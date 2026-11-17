package reconcile

import (
	"context"
	"errors"
	"fmt"
	"log/slog"
	"net/netip"
	"slices"
	"sync"
	"time"

	"github.com/vantezzen/launchroom/daemon/internal/docker"
	"github.com/vantezzen/launchroom/daemon/internal/health"
	"github.com/vantezzen/launchroom/daemon/internal/ingress"
	"github.com/vantezzen/launchroom/daemon/internal/names"
	"github.com/vantezzen/launchroom/daemon/internal/policy"
	"github.com/vantezzen/launchroom/daemon/internal/secret"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// Defaults.
const (
	DefaultStopGrace = 10 * time.Second
	// NoProbeHealthyAfter: a workload without a probe counts as healthy after
	// running this long without exiting (docs/04 §8).
	NoProbeHealthyAfter = 10 * time.Second
	removedRetention    = 10 * time.Minute
)

// Ingress converges the system Caddy and its config (caddy.Manager); it
// resolves same-server upstreams through the reconciler's observation.
type Ingress interface {
	Sync(ctx context.Context, sys state.SystemContainer, routes []state.RouteSpec, resolve ingress.Resolver) *state.IngressStatus
}

// EnvOpener decrypts a workload's sealed env (sealed.OpenWorkloadEnv bound to the daemon key).
type EnvOpener func(spec *state.WorkloadSpec) (map[string]secret.Secret, error)

// Options configures a Reconciler.
type Options struct {
	ServerID   state.ServerID
	Docker     docker.Client
	Policy     policy.Config
	OpenEnv    EnvOpener
	EnvHashKey []byte
	// MeshIP returns this server's mesh address (for publishOnMesh; invalid =
	// not known yet). nil = no mesh.
	MeshIP func() netip.Addr
	Health *health.Monitor
	Ports  health.PortScanner
	// Ingress may be nil (no ingress management).
	Ingress   Ingress
	StopGrace time.Duration
	// OnProgress is called (non-blocking) when statuses change mid-pass, e.g. before a long pull.
	OnProgress func()
	// PullWait is how long a pass waits for an image pull it started
	// before it goes on without the workloads that need it (default
	// DefaultPullWait); the pull goes on in the background.
	PullWait time.Duration
	// OnPullDone is called (non-blocking) when a background pull ends: the
	// workloads waiting for it can be created now. nil = none.
	OnPullDone func()
	// Networks creates and removes environment networks; run_once tasks
	// share it and hold the networks they use (nil: one of its own).
	Networks *docker.Networks
	// BeforeNetworkRemove detaches launchroom's system containers (the
	// ambassador) from an environment network about to be removed; Docker
	// refuses to remove a network with endpoints. nil = none.
	BeforeNetworkRemove func(ctx context.Context, network string) error
	// MarksFile persists what launchroomd last saw of each workload's
	// container (marks.go), so exits while it was down are reported after
	// a restart. "" = not persisted.
	MarksFile string
	Log       *slog.Logger
	Now       func() time.Time
}

// Reconciler converges Docker to a NodeState. Reconcile must not be called
// concurrently; Snapshot may be called from any goroutine.
type Reconciler struct {
	o Options

	mu       sync.Mutex
	ns       *state.NodeState
	observed map[state.WorkloadID]observed
	book     map[state.WorkloadID]note
	ingress  *state.IngressStatus
	pass     time.Time
	// refused are the address upstreams the local policy refused last time (logged on change).
	refused []state.AddrPort
	// deletions are the outcomes of NodeState.deleteVolumes, by volume id.
	deletions map[state.VolumeID]state.VolumeDeletionResult
	// marks and events: container lifecycle (lifecycle.go, D68).
	marks  map[state.WorkloadID]mark
	events eventLog
	// boot is when this reconciler started: containers that started or
	// exited before it are not reported as new events.
	boot time.Time
	// pulls are the image pulls in flight, by image (pulls.go): a pull runs
	// in the background, so one stuck download never holds back the rest
	// of the desired state (D68); AbandonPulls cancels the unneeded ones.
	pulls map[state.ImageRef]*bgPull
	// watchers wait for each workload container's next exit (exitwatch.go);
	// stopping are the containers launchroomd stopped itself, by id.
	watchers map[string]*exitWatcher
	stopping map[string]time.Time
	// marksSaved is the last persisted marks file; marksWrite serialises writes.
	marksSaved []byte
	marksWrite sync.Mutex
	// images are the configs of the images in use (digest-pinned, so they
	// never change); host is this server's platform (observe.go).
	images map[state.ImageRef]docker.ImageConfig
	host   *hostPlatform
	// passHealth: how the last passes went and whether Docker answered.
	passHealth passHealth
}

type observed struct {
	c        docker.Container
	ip       netip.Addr // primary network IP
	ports    []health.Listener
	portsErr bool
	// issue is set while the container is clearly misconfigured (D68).
	issue *state.WorkloadIssue
	// at is when the container was inspected.
	at time.Time
}

// note is the daemon's own knowledge about a workload that Docker can't tell:
// in-flight pulls, failures, recent removals.
type note struct {
	phase   state.WorkloadPhase // pulling, failed, removed
	message string
	pull    *state.PullProgress // while pulling, once launchroomd knows more than "started"
	at      time.Time
	// failure classifies a failed note (WorkloadStatus.failure).
	failure state.WorkloadFailureCode
}

// New returns a Reconciler.
func New(o Options) *Reconciler {
	if o.StopGrace <= 0 {
		o.StopGrace = DefaultStopGrace
	}
	if o.Log == nil {
		o.Log = slog.New(slog.DiscardHandler)
	}
	if o.Now == nil {
		o.Now = time.Now
	}
	if o.Networks == nil {
		o.Networks = docker.NewNetworks(o.Docker, o.ServerID)
	}
	if o.Ports == nil {
		o.Ports = health.ProcScanner{}
	}
	if o.OnProgress == nil {
		o.OnProgress = func() {}
	}
	if o.OnPullDone == nil {
		o.OnPullDone = func() {}
	}
	if o.PullWait <= 0 {
		o.PullWait = DefaultPullWait
	}
	r := &Reconciler{
		o: o, observed: map[state.WorkloadID]observed{}, book: map[state.WorkloadID]note{}, deletions: map[state.VolumeID]state.VolumeDeletionResult{},
		marks: map[state.WorkloadID]mark{}, boot: o.Now(), pulls: map[state.ImageRef]*bgPull{},
		watchers: map[string]*exitWatcher{}, stopping: map[string]time.Time{}, images: map[state.ImageRef]docker.ImageConfig{},
	}
	r.loadMarks()
	return r
}

func (r *Reconciler) setNote(id state.WorkloadID, phase state.WorkloadPhase, msg string) {
	r.mu.Lock()
	defer r.mu.Unlock()
	r.book[id] = note{phase: phase, message: state.Clip(msg, state.MaxStatusLen), at: r.o.Now()}
}

// setFailure records why a workload's container can't be pulled, created
// or started (a failed note with its typed code).
func (r *Reconciler) setFailure(id state.WorkloadID, msg string, code state.WorkloadFailureCode) {
	r.mu.Lock()
	defer r.mu.Unlock()
	r.book[id] = note{phase: state.PhaseFailed, message: state.Clip(msg, state.MaxStatusLen), at: r.o.Now(), failure: code}
}

// observedContainer reports whether the workload has a container (last observation).
func (r *Reconciler) observedContainer(id state.WorkloadID) (docker.Container, bool) {
	r.mu.Lock()
	defer r.mu.Unlock()
	o, ok := r.observed[id]
	return o.c, ok
}

// clearFailure drops a failure note (the workload is waiting, not failing).
func (r *Reconciler) clearFailure(id state.WorkloadID) {
	r.mu.Lock()
	defer r.mu.Unlock()
	if n, ok := r.book[id]; ok && n.phase == state.PhaseFailed {
		delete(r.book, id)
	}
}

func (r *Reconciler) clearNote(id state.WorkloadID) {
	r.mu.Lock()
	defer r.mu.Unlock()
	delete(r.book, id)
}

// Reconcile runs one level-triggered pass for ns. Errors are recorded per
// workload and in the returned error (for logging); the next pass retries.
func (r *Reconciler) Reconcile(ctx context.Context, ns *state.NodeState) error {
	now := r.o.Now()
	r.mu.Lock()
	r.ns, r.pass = ns, now
	r.mu.Unlock()

	r.beginPass()
	desired, creates := r.desired(ns)
	actual, err := r.observe(ctx, desired)
	if err != nil {
		return r.endPass(fmt.Errorf("reconcile: observe: %w", err), true)
	}
	plan := Diff(desired, actual, now, r.o.StopGrace)
	for id, reason := range plan.Blocked {
		r.setNote(id, state.PhaseFailed, reason)
	}
	r.unrouteLeaving(ctx, ns, plan)
	applyErr := r.apply(ctx, ns, plan, creates)
	if err := r.deleteVolumes(ctx, ns); err != nil {
		applyErr = errors.Join(applyErr, err)
	}

	if err := r.refresh(ctx, ns); err != nil {
		return r.endPass(errors.Join(applyErr, fmt.Errorf("reconcile: refresh: %w", err)), true)
	}
	r.syncHealth(ns) //nolint:contextcheck // probes run on the monitor's own long-lived context
	r.liveness(ctx, ns)
	if r.o.Ingress != nil {
		st := r.o.Ingress.Sync(ctx, ns.System.Caddy, r.routes(ns), r.resolve)
		r.mu.Lock()
		r.ingress = st
		r.mu.Unlock()
	}
	r.saveMarks()
	return r.endPass(applyErr, false)
}

// unrouteLeaving takes workloads this pass stops or removes out of the routes
// before they stop (zero-downtime rollouts, docs/04 §8): the ingress is synced
// with the new routes minus those workloads first. Otherwise a NodeState that
// both switches traffic and ends a drain (applied late, e.g. after a missed
// nudge) would stop the old container while Caddy still sends requests to it.
func (r *Reconciler) unrouteLeaving(ctx context.Context, ns *state.NodeState, plan Plan) {
	if r.o.Ingress == nil {
		return
	}
	leaving := map[state.WorkloadID]bool{}
	for _, a := range plan.Actions {
		if (a.Kind == StopContainer || a.Kind == RemoveContainer) && a.Workload != "" {
			leaving[a.Workload] = true
		}
	}
	if len(leaving) == 0 {
		return
	}
	st := r.o.Ingress.Sync(ctx, ns.System.Caddy, r.routes(ns), func(id state.WorkloadID) (ingress.Endpoint, bool) {
		if leaving[id] {
			return ingress.Endpoint{}, false
		}
		return r.resolve(id)
	})
	r.mu.Lock()
	r.ingress = st
	r.mu.Unlock()
}

// routes are the NodeState routes minus address upstreams the local policy
// refuses (docs/03 §8): only the mesh range and LR_ALLOWED_LOCAL_UPSTREAMS.
func (r *Reconciler) routes(ns *state.NodeState) []state.RouteSpec {
	routes, refused := policy.FilterRouteUpstreams(ns.Routes, r.o.Policy)
	if !slices.Equal(refused, r.refused) {
		if len(refused) > 0 {
			r.o.Log.Warn("local policy refused route address upstreams (not in the mesh range or LR_ALLOWED_LOCAL_UPSTREAMS)", "addresses", refused)
		}
		r.refused = refused
	}
	return routes
}

// desired builds container specs (policy-checked, env opened) for every workload.
func (r *Reconciler) desired(ns *state.NodeState) (Desired, map[state.WorkloadID]*docker.ContainerCreate) {
	d := Desired{Networks: ns.Networks, Volumes: ns.Volumes, Skipped: map[state.WorkloadID]string{}}
	creates := map[state.WorkloadID]*docker.ContainerCreate{}
	nets := make(map[state.NetworkID]state.HostName, len(ns.Networks))
	for _, n := range ns.Networks {
		nets[n.ID] = n.Name
	}
	vols := make(map[state.VolumeID]state.HostName, len(ns.Volumes))
	for _, v := range ns.Volumes {
		vols[v.ID] = v.Name
	}
	meshIP := netip.Addr{}
	if r.o.MeshIP != nil {
		meshIP = r.o.MeshIP()
	}
	for i := range ns.Workloads {
		spec := ns.Workloads[i]
		if spec.PublishOnMesh != nil && !meshIP.IsValid() {
			// Docker can only bind the mesh IP once tailscale0 has it. The
			// existing container (if any) is left as it is, so a daemon
			// restart never recreates it; launchroomd reconciles again as
			// soon as the mesh address is known (docs/05 §3).
			d.Skipped[spec.ID] = "waiting for this server's mesh address"
			r.clearFailure(spec.ID)
			// Reported, so the rollout says what it waits for (D68).
			if _, running := r.observedContainer(spec.ID); !running {
				r.setNote(spec.ID, state.PhasePending, "waiting for this server's private network address")
			}
			continue
		}
		cc, err := r.build(ns.ServerID, &spec, nets, vols, meshIP)
		if err != nil {
			d.Skipped[spec.ID] = err.Error()
			r.setNote(spec.ID, state.PhaseFailed, err.Error())
			r.o.Log.Warn("workload cannot be created", "workload", spec.ID, "err", err)
			continue
		}
		hash, err := docker.SpecHash(cc, r.o.EnvHashKey)
		if err != nil {
			d.Skipped[spec.ID] = err.Error()
			continue
		}
		cc.Config.Labels[names.LabelSpecHash] = hash
		creates[spec.ID] = cc
		r.mu.Lock()
		if n, ok := r.book[spec.ID]; ok && n.phase == state.PhaseFailed {
			delete(r.book, spec.ID) // re-evaluated by this pass
		}
		r.mu.Unlock()
		dw := DesiredWorkload{Spec: spec, Hash: hash}
		if spec.PublishOnMesh != nil {
			dw.MeshBinding = netip.AddrPortFrom(meshIP, uint16(spec.PublishOnMesh.Port)) //nolint:gosec // validated mesh port range
		}
		d.Workloads = append(d.Workloads, dw)
	}
	return d, creates
}

func (r *Reconciler) build(server state.ServerID, spec *state.WorkloadSpec, nets map[state.NetworkID]state.HostName, vols map[state.VolumeID]state.HostName, meshIP netip.Addr) (*docker.ContainerCreate, error) {
	if err := policy.Check(*spec, r.o.Policy); err != nil {
		return nil, err
	}
	if r.o.OpenEnv == nil {
		return nil, errors.New("no env opener configured")
	}
	env, err := r.o.OpenEnv(spec)
	if err != nil {
		return nil, fmt.Errorf("cannot open env: %w", err)
	}
	return docker.BuildContainer(docker.WorkloadInput{
		ServerID: server, Spec: *spec, Env: env, Networks: nets, Volumes: vols, MeshIP: meshIP,
	}, r.o.Policy)
}

func (r *Reconciler) observe(ctx context.Context, d Desired) (Actual, error) {
	var a Actual
	var err error
	nets, err := r.o.Docker.ListNetworks(ctx)
	if err != nil {
		return a, err
	}
	for _, n := range nets {
		// System networks (lr-system-build) belong to their system container manager.
		if n.Labels[names.LabelSystem] == "" {
			a.Networks = append(a.Networks, n)
		}
	}
	if a.Volumes, err = r.o.Docker.ListVolumes(ctx); err != nil {
		return a, err
	}
	cs, err := r.o.Docker.ListContainers(ctx, nil)
	if err != nil {
		return a, err
	}
	// Networks a run_once container is attached to stay (the task may run in
	// an environment network this server has no workloads in yet).
	taskNets := map[string]bool{}
	for _, c := range cs {
		if c.Kind() == docker.KindWorkload {
			a.Containers = append(a.Containers, c)
		}
		if c.Labels[names.LabelTask] != "" {
			for name := range c.Networks {
				taskNets[name] = true
			}
		}
	}
	for _, n := range d.Networks {
		delete(taskNets, string(n.Name)) // desired anyway
	}
	// Networks a run_once task holds right now (it may have created one
	// and not attached its container yet) are not removal candidates.
	for _, n := range a.Networks {
		if r.o.Networks.Held(n.Name) {
			taskNets[n.Name] = true
		}
	}
	if len(taskNets) > 0 {
		a.Networks = slices.DeleteFunc(a.Networks, func(n docker.Network) bool { return taskNets[n.Name] })
	}
	a.Images = map[state.ImageRef]bool{}
	for _, w := range d.Workloads {
		if _, done := a.Images[w.Spec.Image]; done {
			continue
		}
		ok, err := r.o.Docker.ImagePresent(ctx, string(w.Spec.Image))
		if err != nil {
			return a, err
		}
		a.Images[w.Spec.Image] = ok
	}
	return a, nil
}

func (r *Reconciler) apply(ctx context.Context, ns *state.NodeState, p Plan, creates map[state.WorkloadID]*docker.ContainerCreate) error {
	var errs []error
	failedPull := map[state.ImageRef]error{}
	pending := map[state.ImageRef]bool{}
	// A volume that could not be created (e.g. an unmanaged volume of that
	// name exists) must not be mounted: Docker would mount whatever has the name.
	failedVolume := map[string]error{}
	fail := func(a Action, err error) {
		errs = append(errs, fmt.Errorf("%s: %w", a, err))
		r.passError(a, err)
		r.o.Log.Warn("reconcile action failed", "action", a.String(), "err", err)
	}
	deployments := make(map[state.WorkloadID]state.DeploymentID, len(ns.Workloads))
	for i := range ns.Workloads {
		deployments[ns.Workloads[i].ID] = ns.Workloads[i].Deployment
	}
	event := func(a Action, kind state.WorkloadEventKind, msg string) {
		r.emit(state.WorkloadEvent{Workload: a.Workload, Deployment: deployments[a.Workload], Kind: kind, Reason: a.Why, Message: msg})
	}
	// failed records a typed create/start failure: the workload's note and
	// a create_failed event.
	failed := func(a Action, msg string, code state.WorkloadFailureCode) {
		r.setFailure(a.Workload, msg, code)
		r.emit(state.WorkloadEvent{Workload: a.Workload, Deployment: deployments[a.Workload], Kind: state.EventCreateFailed, Message: msg, Code: code})
	}
	specs := make(map[state.WorkloadID]*state.WorkloadSpec, len(ns.Workloads))
	for i := range ns.Workloads {
		specs[ns.Workloads[i].ID] = &ns.Workloads[i]
	}
	// Fail closed but wait: Docker refuses to create or start a container
	// while the firewall can't be applied (docker.ErrNotIsolated). That is a
	// host condition (reported in the host status), retried every pass; the
	// workload isn't broken, so it must not fail its deployment.
	notIsolated := func(a Action, err error) bool {
		if !errors.Is(err, docker.ErrNotIsolated) {
			return false
		}
		errs = append(errs, err)
		r.passErrorCode(state.ReconcileNotIsolated, a, err)
		r.setNote(a.Workload, state.PhasePending, "waiting: "+err.Error())
		return true
	}
	for _, a := range p.Actions {
		if ctx.Err() != nil {
			return errors.Join(append(errs, ctx.Err())...)
		}
		r.o.Log.Info("reconcile", "action", string(a.Kind), "target", a.String())
		switch a.Kind {
		case CreateNetwork:
			if err := r.o.Networks.Ensure(ctx, a.Network); err != nil {
				fail(a, err)
			}
		case CreateVolume:
			labels := docker.ManagedLabels(string(r.o.ServerID))
			labels[names.LabelVolume] = string(a.Volume.ID)
			// An owner-less (prepared) volume records no service: the first
			// workload that mounts it may (volumeMountError).
			if a.VolumeOwner.Service != "" {
				labels[names.LabelService] = string(a.VolumeOwner.Service)
				labels[names.LabelEnvironment] = string(a.VolumeOwner.Environment)
			}
			if err := r.o.Docker.CreateVolume(ctx, docker.VolumeCreate{Name: string(a.Volume.Name), Labels: labels}); err != nil {
				failedVolume[string(a.Volume.Name)] = err
				fail(a, err)
			}
		case PullImage:
			r.markPulling(p, a.Image)
			switch done, err := r.pull(ctx, a.Image); {
			case !done:
				pending[a.Image] = true
			case err != nil:
				failedPull[a.Image] = err
				fail(a, err)
			}
		case RemoveContainer:
			out, err := r.removeContainer(ctx, a)
			if err != nil {
				fail(a, err)
				continue
			}
			if a.Workload != "" {
				r.emit(state.WorkloadEvent{Workload: a.Workload, Deployment: deployments[a.Workload], Kind: state.EventRemoved, Reason: a.Why, Output: out})
			}
			if a.Undesired {
				r.setNote(a.Workload, state.PhaseRemoved, "")
			}
		case CreateWorkload:
			if pending[a.Image] {
				continue // still pulling in the background; reported as pulling
			}
			if err, ok := failedPull[a.Image]; ok {
				r.setFailure(a.Workload, "pull failed: "+err.Error(), docker.PullFailure(err))
				continue
			}
			if err := mountsFailedVolume(creates[a.Workload], failedVolume); err != nil {
				r.setFailure(a.Workload, err.Error(), state.FailureMountFailed)
				continue
			}
			if msg := r.wrongPlatform(ctx, a.Image); msg != "" {
				failed(a, msg, state.FailureWrongPlatform)
				continue
			}
			if err := r.createAndStart(ctx, ns, creates[a.Workload], specs[a.Workload], !a.CreateOnly); err != nil {
				if notIsolated(a, err) {
					continue
				}
				failed(a, err.Error(), docker.StartFailure(err))
				fail(a, err)
				continue
			}
			event(a, state.EventCreated, "")
			r.clearNote(a.Workload)
		case StartContainer:
			if err := r.o.Docker.StartContainer(ctx, a.ContainerID); err != nil {
				if notIsolated(a, err) {
					continue
				}
				failed(a, "start: "+err.Error(), docker.StartFailure(err))
				fail(a, err)
				continue
			}
			r.clearNote(a.Workload)
		case StopContainer:
			r.markStopping(a.ContainerID)
			if err := r.o.Docker.StopContainer(ctx, a.ContainerID, a.Grace); err != nil {
				fail(a, err)
				continue
			}
			r.emit(state.WorkloadEvent{
				Workload: a.Workload, Deployment: deployments[a.Workload], Kind: state.EventStopped, Reason: a.Why,
				Output: r.lastRun(ctx, a.ContainerID),
			})
		case RemoveNetwork:
			removed, err := r.o.Networks.RemoveUnlessHeld(a.NetworkName, func() error {
				if r.o.BeforeNetworkRemove != nil {
					if err := r.o.BeforeNetworkRemove(ctx, a.NetworkName); err != nil {
						return err
					}
				}
				return r.o.Docker.RemoveNetwork(ctx, a.NetworkID)
			})
			if err != nil {
				fail(a, err)
			} else if !removed {
				r.o.Log.Info("network kept: a one-off task uses it", "network", a.NetworkName)
			}
		}
	}
	return errors.Join(errs...)
}

func mountsFailedVolume(cc *docker.ContainerCreate, failed map[string]error) error {
	if cc == nil || cc.HostConfig == nil {
		return nil
	}
	for _, m := range cc.HostConfig.Mounts {
		if err, ok := failed[m.Source]; ok {
			return fmt.Errorf("volume %s could not be created: %w", m.Source, err)
		}
	}
	return nil
}

func (r *Reconciler) markPulling(p Plan, image state.ImageRef) {
	for _, a := range p.Actions {
		if a.Kind == CreateWorkload && a.Image == image {
			r.mu.Lock()
			n, ok := r.book[a.Workload]
			r.mu.Unlock()
			if ok && n.phase == state.PhasePulling {
				continue // a background pull from an earlier pass: keep its progress
			}
			r.setNote(a.Workload, state.PhasePulling, "pulling "+string(image))
		}
	}
	r.o.OnProgress()
}

// pullProgress records an in-flight pull's progress on the workloads waiting
// for the image and reports it (docs/04 §11 "Pulls").
func (r *Reconciler) pullProgress(image state.ImageRef, pp docker.PullProgress) {
	prog := &state.PullProgress{
		Stage: state.PullStage(pp.Stage), DownloadedBytes: max(pp.Downloaded, 0), TotalBytes: max(pp.Total, 0),
	}
	if prog.Stage.Validate() != nil {
		return
	}
	r.mu.Lock()
	for _, id := range r.waitingFor(image) {
		if n, ok := r.book[id]; ok && n.phase == state.PhasePulling {
			// UpdatedAt moves only with the pull (D68): a stuck pull keeps its time.
			at := r.o.Now().UTC()
			if prev := n.pull; prev != nil && prev.UpdatedAt != nil && prev.Stage == prog.Stage && prev.DownloadedBytes == prog.DownloadedBytes && prev.TotalBytes == prog.TotalBytes {
				at = *prev.UpdatedAt
			}
			p := *prog
			p.UpdatedAt = &at
			n.pull = &p
			r.book[id] = n
		}
	}
	r.mu.Unlock()
	r.o.OnProgress()
}

// removeContainer stops and removes a container; a workload container's
// last lines are read in between (the removed event carries them, D68:
// logs survive a replacement even without runtime log retention).
func (r *Reconciler) removeContainer(ctx context.Context, a Action) ([]state.LogLine, error) {
	r.markStopping(a.ContainerID)
	if a.Grace > 0 {
		if err := r.o.Docker.StopContainer(ctx, a.ContainerID, a.Grace); err != nil && !errors.Is(err, docker.ErrNotFound) {
			return nil, err
		}
	}
	var out []state.LogLine
	if a.Workload != "" {
		out = r.lastRun(ctx, a.ContainerID)
	}
	if err := r.o.Docker.RemoveContainer(ctx, a.ContainerID); err != nil && !errors.Is(err, docker.ErrNotFound) {
		return nil, err
	}
	return out, nil
}

// lastRun returns the last lines of a container's last run (best effort).
func (r *Reconciler) lastRun(ctx context.Context, id string) []state.LogLine {
	c, err := r.o.Docker.InspectContainer(ctx, id)
	if err != nil {
		return nil
	}
	b := runBounds{since: c.StartedAt}
	if !c.Running() && !c.FinishedAt.Before(c.StartedAt) {
		b.until = c.FinishedAt
	}
	return r.tailRun(ctx, id, b)
}

// createAndStart creates and (unless start is false) starts a workload
// container through the network owner, which re-ensures its networks when
// Docker does not find one.
func (r *Reconciler) createAndStart(ctx context.Context, ns *state.NodeState, cc *docker.ContainerCreate, spec *state.WorkloadSpec, start bool) error {
	if cc == nil {
		return errors.New("no container spec")
	}
	var nets []state.NetworkSpec
	if cc.Networking != nil {
		for _, n := range ns.Networks {
			if _, attached := cc.Networking.EndpointsConfig[string(n.Name)]; attached {
				nets = append(nets, n)
			}
		}
	}
	id, err := r.o.Networks.CreateContainer(ctx, cc, nets)
	if err != nil {
		return fmt.Errorf("create: %w", err)
	}
	// After the create (Docker copied the image's folder into an empty
	// volume then), before the first start.
	if err := r.initOwners(ctx, cc, spec); err != nil {
		r.passErrorCode(state.ReconcileVolume, Action{Kind: CreateWorkload, Workload: spec.ID}, err)
		r.o.Log.Warn("could not give a new volume to the image's user", "container", cc.Name, "err", err)
	}
	if !start {
		return nil
	}
	if err := r.o.Networks.StartContainer(ctx, id, nets); err != nil {
		return fmt.Errorf("start: %w", err)
	}
	return nil
}

// refresh re-lists workload containers and records IPs and listening ports.
func (r *Reconciler) refresh(ctx context.Context, ns *state.NodeState) error {
	cs, err := r.o.Docker.ListContainers(ctx, nil)
	if err != nil {
		return err
	}
	nets := make(map[state.NetworkID]string, len(ns.Networks))
	for _, n := range ns.Networks {
		nets[n.ID] = string(n.Name)
	}
	specs := map[state.WorkloadID]*state.WorkloadSpec{}
	for i := range ns.Workloads {
		specs[ns.Workloads[i].ID] = &ns.Workloads[i]
	}
	obs := map[state.WorkloadID]observed{}
	at := r.o.Now()
	for id, c := range docker.WorkloadContainers(cs) {
		o := observed{c: c, at: at}
		if spec := specs[id]; spec != nil && len(spec.Networks) > 0 {
			o.ip = c.Networks[nets[spec.Networks[0].Network]]
		}
		if c.Running() && c.Pid > 0 {
			ls, err := r.o.Ports.Listeners(c.Pid)
			if err != nil {
				o.portsErr = true
			} else {
				o.ports = ls
			}
		}
		obs[id] = o
	}
	now := r.o.Now()
	r.mu.Lock()
	var exits []exitTail
	for id, o := range obs {
		spec := specs[id]
		var dep state.DeploymentID
		if spec != nil {
			dep = spec.Deployment
		}
		exits = append(exits, r.trackLocked(id, dep, o.c)...)
		if spec != nil {
			probe := r.probeLocked(spec)
			r.probeEventsLocked(spec, probe, o.c.Running())
			o.issue = r.issueLocked(spec, o, probe)
			obs[id] = o
		}
	}
	for id := range r.marks {
		if _, seen := obs[id]; !seen && specs[id] == nil {
			delete(r.marks, id) // the container is gone and the workload left
		}
	}
	r.observed = obs
	r.passHealth.lastObserved = at
	r.syncWatchersLocked(ctx, obs)
	r.pruneStoppingLocked(now)
	r.mu.Unlock()
	r.finishExits(ctx, exits)
	r.learnImages(ctx, ns)
	r.mu.Lock()
	defer r.mu.Unlock()
	for id, n := range r.book {
		if _, desired := specs[id]; !desired && n.phase != state.PhaseRemoved {
			delete(r.book, id)
		}
		if n.phase == state.PhaseRemoved && (now.Sub(n.at) > removedRetention || specs[id] != nil) {
			delete(r.book, id)
		}
	}
	return nil
}

func (r *Reconciler) syncHealth(ns *state.NodeState) {
	if r.o.Health == nil {
		return
	}
	r.mu.Lock()
	var targets []health.Target
	for i := range ns.Workloads {
		s := &ns.Workloads[i]
		o, ok := r.observed[s.ID]
		if !ok || s.Health == nil || s.Port == nil || !o.c.Running() || !o.ip.IsValid() {
			continue
		}
		targets = append(targets, health.Target{
			Workload: s.ID, ContainerID: o.c.ID, Spec: *s.Health, StartedAt: o.c.StartedAt,
			Addr: netip.AddrPortFrom(o.ip, uint16(*s.Port)), //nolint:gosec // validated 1-65535
		})
	}
	r.mu.Unlock()
	r.o.Health.Sync(targets)
}

// resolve is the ingress resolver over the last observation.
func (r *Reconciler) resolve(id state.WorkloadID) (ingress.Endpoint, bool) {
	r.mu.Lock()
	defer r.mu.Unlock()
	o, ok := r.observed[id]
	if !ok || r.ns == nil {
		return ingress.Endpoint{}, false
	}
	spec := r.ns.Workload(id)
	if spec == nil {
		return ingress.Endpoint{}, false
	}
	return ingress.Endpoint{
		Addr: o.ip, Serving: spec.Role == state.RoleServing, Ready: r.readyLocked(spec, o),
		// Fail open (HEAL-10): with no ready replica the edge routes to
		// running ones, never to stopped, restarting or paused ones.
		Running: o.c.State == docker.StateRunning,
	}, true
}

func (r *Reconciler) readyLocked(spec *state.WorkloadSpec, o observed) bool {
	if !o.c.Running() {
		return false
	}
	if spec.Health == nil {
		return true
	}
	if r.o.Health == nil {
		return false
	}
	st, ok := r.o.Health.Status(spec.ID)
	return ok && st.State == state.ProbePassing
}

// Snapshot returns the observed status of every desired (and recently
// removed) workload, plus the ingress status and the NodeState version.
func (r *Reconciler) Snapshot() (version int64, workloads []state.WorkloadStatus, ingress *state.IngressStatus) {
	r.mu.Lock()
	defer r.mu.Unlock()
	now := r.o.Now().UTC()
	if r.ns == nil {
		return 0, []state.WorkloadStatus{}, nil
	}
	out := []state.WorkloadStatus{}
	seen := map[state.WorkloadID]bool{}
	for i := range r.ns.Workloads {
		s := &r.ns.Workloads[i]
		seen[s.ID] = true
		var probe *health.Status
		if r.o.Health != nil && s.Health != nil {
			if st, ok := r.o.Health.Status(s.ID); ok {
				probe = &st
			}
		}
		o, has := r.observed[s.ID]
		var op *observed
		if has {
			op = &o
		}
		n, hasNote := r.book[s.ID]
		var np *note
		if hasNote {
			np = &n
		}
		ws := statusOf(s, op, np, probe, now)
		// Only what was actually seen is new: while Docker can't be
		// observed, the status keeps the time of the last observation.
		ws.ObservedAt = r.observedAtLocked(op)
		ws.Image = r.imageFactsLocked(s.Image)
		if ws.Failure == nil {
			ws.Failure = r.startFailureLocked(s.ID, op, now)
		}
		out = append(out, ws)
	}
	for id, n := range r.book {
		if !seen[id] && n.phase == state.PhaseRemoved {
			out = append(out, state.WorkloadStatus{ID: id, Phase: state.PhaseRemoved, ObservedAt: n.at.UTC()})
		}
	}
	slices.SortFunc(out, func(a, b state.WorkloadStatus) int {
		if a.ID < b.ID {
			return -1
		}
		if a.ID > b.ID {
			return 1
		}
		return 0
	})
	return r.ns.Version, out, r.ingress
}

// observedAtLocked is when a workload's state was last seen: its container's
// inspection, else the last successful pass (r.mu held).
func (r *Reconciler) observedAtLocked(o *observed) time.Time {
	switch {
	case o != nil && !o.at.IsZero():
		return o.at.UTC()
	case !r.passHealth.lastObserved.IsZero():
		return r.passHealth.lastObserved.UTC()
	case r.passHealth.status == nil:
		return r.o.Now().UTC() // no pass ended yet: nothing is stale
	}
	return r.boot.UTC()
}

// statusOf derives the reported phase of one desired workload. It is pure.
func statusOf(s *state.WorkloadSpec, o *observed, n *note, probe *health.Status, now time.Time) state.WorkloadStatus {
	ws := state.WorkloadStatus{ID: s.ID, ObservedAt: now}
	if probe != nil {
		ps := &state.ProbeStatus{
			State: probe.State, ConsecutiveFailures: probe.ConsecutiveFailures, LastError: state.Clip(probe.LastError, state.MaxStatusLen), Attempts: probe.Attempts,
			Body: state.Clip(probe.Body, state.MaxProbeBody), Hint: probe.Hint,
		}
		if !probe.LastCheckedAt.IsZero() {
			t := probe.LastCheckedAt.UTC()
			ps.LastCheckedAt = &t
		}
		ws.Health = ps
	}
	if o == nil {
		switch {
		case n != nil && (n.phase == state.PhasePulling || n.phase == state.PhaseFailed || n.phase == state.PhasePending):
			ws.Phase, ws.Message = n.phase, n.message
			ws.Failure = n.failureStatus()
			if n.phase == state.PhasePulling && n.pull != nil {
				pp := *n.pull
				ws.Pull = &pp
			}
		case s.Role == state.RoleDraining:
			ws.Phase = state.PhaseRemoved
		default:
			ws.Phase = state.PhasePending
		}
		return ws
	}
	c := o.c
	if len(c.ID) == 64 {
		ws.ContainerID = c.ID
	}
	if o.issue != nil {
		is := *o.issue
		ws.Issue = &is
	}
	ws.RestartCount = max(c.RestartCount, 0)
	if !c.StartedAt.IsZero() {
		t := c.StartedAt.UTC()
		ws.StartedAt = &t
	}
	if o.ports != nil {
		ws.DetectedPorts = make([]state.Port, 0, len(o.ports))
		for _, l := range o.ports {
			if len(ws.DetectedPorts) < 32 {
				ws.DetectedPorts = append(ws.DetectedPorts, state.Port(l.Port))
			}
		}
	}
	hint := func() string {
		if s.Port == nil || o.ports == nil {
			return ""
		}
		code, msg := health.Hint(int(*s.Port), o.ports)
		ws.PortHint = code
		return msg
	}

	if s.Role == state.RoleServing && (n == nil || n.phase != state.PhaseFailed || c.Running()) {
		if phase, msg, ok := modeOf(s).status(c, &ws); ok {
			ws.Phase, ws.Message = phase, state.Clip(msg, state.MaxStatusLen)
			return ws
		}
	}
	switch {
	case n != nil && n.phase == state.PhaseFailed && !c.Running():
		ws.Phase, ws.Message = state.PhaseFailed, n.message
		ws.Failure = n.failureStatus()
	case s.Role == state.RoleDraining:
		if c.Running() {
			ws.Phase = state.PhaseDraining
		} else {
			ws.Phase, ws.Message = state.PhaseExited, "drained"
		}
	case c.State == docker.StateRunning:
		switch {
		case s.Health != nil && probe != nil && probe.State == state.ProbePassing:
			ws.Phase = state.PhaseHealthy
		case s.Health != nil && probe != nil && probe.State == state.ProbeFailing:
			ws.Phase = state.PhaseUnhealthy
			ws.Message = joinMsg("health check failing: "+probe.LastError, hint())
		case s.Health != nil:
			ws.Phase = state.PhaseStarting
			if probe != nil && probe.LastError != "" {
				ws.Message = joinMsg("waiting for health check: "+probe.LastError, hint())
			}
		case now.Sub(c.StartedAt) >= NoProbeHealthyAfter:
			ws.Phase = state.PhaseHealthy
		default:
			ws.Phase = state.PhaseStarting
		}
	case c.State == docker.StateRestarting:
		ws.Phase, ws.Message = state.PhaseUnhealthy, fmt.Sprintf("restarting after exit code %d", c.ExitCode)
	case c.State == docker.StateCreated:
		ws.Phase = state.PhaseStarting
	case c.State == docker.StatePaused:
		ws.Phase, ws.Message = state.PhaseUnhealthy, "paused"
	case c.State == docker.StateRemoving:
		ws.Phase = state.PhaseRemoved
	default: // exited, dead
		ws.Phase = state.PhaseExited
		code := c.ExitCode
		ws.ExitCode = &code
		if c.OOMKilled {
			ws.Message = "killed: out of memory (raise the memory limit)"
		}
	}
	ws.Message = state.Clip(ws.Message, state.MaxStatusLen)
	return ws
}

// failureStatus is the typed failure of a failed note (nil: none).
func (n *note) failureStatus() *state.WorkloadFailure {
	if n == nil || n.phase != state.PhaseFailed || n.failure == "" {
		return nil
	}
	return &state.WorkloadFailure{Code: n.failure, Message: state.Clip(n.message, 1024), At: n.at.UTC()}
}

func joinMsg(a, b string) string {
	if b == "" {
		return a
	}
	return a + "; " + b
}
