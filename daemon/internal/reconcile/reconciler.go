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
	"github.com/vantezzen/launchroom/daemon/internal/ingress/caddy"
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
	maxMessage          = 1024
)

// Ingress converges the system Caddy and its config (caddy.Manager).
type Ingress interface {
	Sync(ctx context.Context, sys state.SystemContainer, routes []caddy.Route) *state.IngressStatus
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
	// Isolate makes sure the network isolation covers every network
	// (firewall.Manager.Sync). It runs once per pass, after new networks are
	// created and before any container is created or started; while it fails,
	// no workload is created or started (fail closed). nil = no isolation.
	Isolate func(ctx context.Context) error
	// Holds are the environment networks run_once tasks currently use; the
	// reconciler never removes a held network (nil = none).
	Holds *docker.NetworkHolds
	// BeforeNetworkRemove detaches launchroom's system containers (the
	// ambassador) from an environment network about to be removed; Docker
	// refuses to remove a network with endpoints. nil = none.
	BeforeNetworkRemove func(ctx context.Context, network string) error
	Log                 *slog.Logger
	Now                 func() time.Time
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
}

type observed struct {
	c        docker.Container
	ip       netip.Addr // primary network IP
	ports    []health.Listener
	portsErr bool
	// issue is set while the container is clearly misconfigured (D68).
	issue *state.WorkloadIssue
}

// note is the daemon's own knowledge about a workload that Docker can't tell:
// in-flight pulls, failures, recent removals.
type note struct {
	phase   state.WorkloadPhase // pulling, failed, removed
	message string
	pull    *state.PullProgress // while pulling, once launchroomd knows more than "started"
	at      time.Time
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
	return &Reconciler{
		o: o, observed: map[state.WorkloadID]observed{}, book: map[state.WorkloadID]note{}, deletions: map[state.VolumeID]state.VolumeDeletionResult{},
		marks: map[state.WorkloadID]mark{}, boot: o.Now(), pulls: map[state.ImageRef]*bgPull{},
	}
}

func (r *Reconciler) setNote(id state.WorkloadID, phase state.WorkloadPhase, msg string) {
	r.mu.Lock()
	defer r.mu.Unlock()
	if len(msg) > maxMessage {
		msg = msg[:maxMessage]
	}
	r.book[id] = note{phase: phase, message: msg, at: r.o.Now()}
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

	desired, creates := r.desired(ns)
	actual, err := r.observe(ctx, desired)
	if err != nil {
		return fmt.Errorf("reconcile: observe: %w", err)
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

	// Re-observe what we converged to, then probes, ingress and statuses.
	if err := r.refresh(ctx, ns); err != nil {
		return errors.Join(applyErr, fmt.Errorf("reconcile: refresh: %w", err))
	}
	r.syncHealth(ns) //nolint:contextcheck // probes run on the monitor's own long-lived context
	if r.o.Ingress != nil {
		st := r.o.Ingress.Sync(ctx, ns.System.Caddy, caddy.ResolveRoutes(r.routes(ns), r.resolve))
		r.mu.Lock()
		r.ingress = st
		r.mu.Unlock()
	}
	return applyErr
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
	st := r.o.Ingress.Sync(ctx, ns.System.Caddy, caddy.ResolveRoutes(r.routes(ns), func(id state.WorkloadID) (caddy.Endpoint, bool) {
		if leaving[id] {
			return caddy.Endpoint{}, false
		}
		return r.resolve(id)
	}))
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
		hash, err := SpecHash(cc, r.o.EnvHashKey)
		if err != nil {
			d.Skipped[spec.ID] = err.Error()
			continue
		}
		cc.Config.Labels[docker.LabelSpecHash] = hash
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
		if n.Labels[docker.LabelSystem] == "" {
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
		if c.Labels[docker.LabelSystem] == "" && c.Labels[docker.LabelWorkload] != "" {
			a.Containers = append(a.Containers, c)
		}
		if c.Labels[docker.LabelTask] != "" {
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
		if r.o.Holds.Held(n.Name) {
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
		r.o.Log.Warn("reconcile action failed", "action", a.String(), "err", err)
	}
	deployments := make(map[state.WorkloadID]state.DeploymentID, len(ns.Workloads))
	for i := range ns.Workloads {
		deployments[ns.Workloads[i].ID] = ns.Workloads[i].Deployment
	}
	event := func(a Action, kind state.WorkloadEventKind, msg string) {
		r.emit(state.WorkloadEvent{Workload: a.Workload, Deployment: deployments[a.Workload], Kind: kind, Reason: a.Why, Message: msg})
	}
	isolated := false
	var isoErr error
	isolate := func() {
		if isolated || r.o.Isolate == nil {
			return
		}
		isolated = true
		if isoErr = r.o.Isolate(ctx); isoErr != nil {
			errs = append(errs, isoErr)
		}
	}
	for _, a := range p.Actions {
		if ctx.Err() != nil {
			return errors.Join(append(errs, ctx.Err())...)
		}
		if a.Kind != CreateNetwork {
			isolate()
		}
		if isoErr != nil && (a.Kind == CreateWorkload || a.Kind == StartContainer) {
			// Fail closed but wait: a firewall that can't be applied is a host
			// condition (reported in the host status), retried every pass; the
			// workload isn't broken, so it must not fail its deployment.
			r.setNote(a.Workload, state.PhasePending, "waiting: "+isoErr.Error())
			continue
		}
		r.o.Log.Info("reconcile", "action", string(a.Kind), "target", a.String())
		switch a.Kind {
		case CreateNetwork:
			if err := r.createNetwork(ctx, a.Network); err != nil {
				fail(a, err)
			}
		case CreateVolume:
			labels := docker.ManagedLabels(string(r.o.ServerID))
			labels[docker.LabelVolume] = string(a.Volume.ID)
			// An owner-less (prepared) volume records no service: the first
			// workload that mounts it may (volumeMountError).
			if a.VolumeOwner.Service != "" {
				labels[docker.LabelService] = string(a.VolumeOwner.Service)
				labels[docker.LabelEnvironment] = string(a.VolumeOwner.Environment)
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
			if err := r.removeContainer(ctx, a); err != nil {
				fail(a, err)
				continue
			}
			if a.Workload != "" {
				event(a, state.EventRemoved, "")
			}
			if a.Undesired {
				r.setNote(a.Workload, state.PhaseRemoved, "")
			}
		case CreateWorkload:
			if pending[a.Image] {
				continue // still pulling in the background; reported as pulling
			}
			if err, ok := failedPull[a.Image]; ok {
				r.setNote(a.Workload, state.PhaseFailed, "pull failed: "+err.Error())
				continue
			}
			if err := mountsFailedVolume(creates[a.Workload], failedVolume); err != nil {
				r.setNote(a.Workload, state.PhaseFailed, err.Error())
				continue
			}
			if err := r.createAndStart(ctx, ns, creates[a.Workload], !a.CreateOnly); err != nil {
				r.setNote(a.Workload, state.PhaseFailed, err.Error())
				event(a, state.EventCreateFailed, err.Error())
				fail(a, err)
				continue
			}
			event(a, state.EventCreated, "")
			r.clearNote(a.Workload)
		case StartContainer:
			if err := r.o.Docker.StartContainer(ctx, a.ContainerID); err != nil {
				r.setNote(a.Workload, state.PhaseFailed, "start: "+err.Error())
				event(a, state.EventCreateFailed, "start: "+err.Error())
				fail(a, err)
				continue
			}
			r.clearNote(a.Workload)
		case StopContainer:
			if err := r.o.Docker.StopContainer(ctx, a.ContainerID, a.Grace); err != nil {
				fail(a, err)
				continue
			}
			event(a, state.EventStopped, "")
		case RemoveNetwork:
			removed, err := r.o.Holds.RemoveUnlessHeld(a.NetworkName, func() error {
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
	isolate() // drift check on passes without workload changes
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

func (r *Reconciler) removeContainer(ctx context.Context, a Action) error {
	if a.Grace > 0 {
		if err := r.o.Docker.StopContainer(ctx, a.ContainerID, a.Grace); err != nil && !errors.Is(err, docker.ErrNotFound) {
			return err
		}
	}
	if err := r.o.Docker.RemoveContainer(ctx, a.ContainerID); err != nil && !errors.Is(err, docker.ErrNotFound) {
		return err
	}
	return nil
}

// createNetwork creates a desired network. A creation error is not final if
// the network exists anyway (created concurrently, e.g. by a run_once task)
// with this network's id label: that is verified by listing.
func (r *Reconciler) createNetwork(ctx context.Context, n state.NetworkSpec) error {
	labels := docker.ManagedLabels(string(r.o.ServerID))
	labels[docker.LabelNetwork] = string(n.ID)
	prefix, _ := netip.ParsePrefix(string(n.Subnet))
	_, err := r.o.Docker.CreateNetwork(ctx, docker.NetworkCreate{Name: string(n.Name), Subnet: prefix, Labels: labels})
	if err == nil {
		return nil
	}
	if ok, lerr := r.networkExists(ctx, n); lerr == nil && ok {
		return nil
	}
	return err
}

// networkExists reports whether the managed network n exists with n's id label.
func (r *Reconciler) networkExists(ctx context.Context, n state.NetworkSpec) (bool, error) {
	nets, err := r.o.Docker.ListNetworks(ctx)
	if err != nil {
		return false, err
	}
	for _, have := range nets {
		if have.Name == string(n.Name) {
			return have.Labels[docker.LabelNetwork] == string(n.ID), nil
		}
	}
	return false, nil
}

// ensureNetworks (re)creates the desired networks cc attaches to that are missing.
func (r *Reconciler) ensureNetworks(ctx context.Context, ns *state.NodeState, cc *docker.ContainerCreate) error {
	if cc.Networking == nil {
		return nil
	}
	for _, n := range ns.Networks {
		if _, attached := cc.Networking.EndpointsConfig[string(n.Name)]; !attached {
			continue
		}
		ok, err := r.networkExists(ctx, n)
		if err != nil {
			return err
		}
		if !ok {
			r.o.Log.Warn("network vanished before its container was created; creating it again", "network", n.Name)
			if err := r.createNetwork(ctx, n); err != nil {
				return err
			}
		}
	}
	return nil
}

// createAndStart creates and (unless start is false) starts a workload
// container. "Network not
// found" (the network was removed or is not visible yet) re-ensures the
// networks and retries with bounded backoff (docker.CreateWithNetworkRetry).
func (r *Reconciler) createAndStart(ctx context.Context, ns *state.NodeState, cc *docker.ContainerCreate, start bool) error {
	if cc == nil {
		return errors.New("no container spec")
	}
	ensure := func(ctx context.Context) error { return r.ensureNetworks(ctx, ns, cc) }
	id, err := docker.CreateWithNetworkRetry(ctx, func() (string, error) { return r.o.Docker.CreateContainer(ctx, cc) }, ensure)
	if err != nil {
		return fmt.Errorf("create: %w", err)
	}
	if !start {
		return nil
	}
	if _, err := docker.CreateWithNetworkRetry(ctx, func() (string, error) { return id, r.o.Docker.StartContainer(ctx, id) }, ensure); err != nil {
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
	for _, c := range cs {
		id := state.WorkloadID(c.Labels[docker.LabelWorkload])
		if c.Labels[docker.LabelSystem] != "" || id == "" {
			continue
		}
		if prev, dup := obs[id]; dup && (prev.c.Running || !c.Running) {
			continue // prefer the running one
		}
		o := observed{c: c}
		if spec := specs[id]; spec != nil && len(spec.Networks) > 0 {
			o.ip = c.Networks[nets[spec.Networks[0].Network]]
		}
		if c.Running && c.Pid > 0 {
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
			r.probeEventsLocked(spec, probe, o.c.Running)
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
	r.mu.Unlock()
	r.finishExits(ctx, exits)
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
		if !ok || s.Health == nil || s.Port == nil || !o.c.Running || !o.ip.IsValid() {
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
func (r *Reconciler) resolve(id state.WorkloadID) (caddy.Endpoint, bool) {
	r.mu.Lock()
	defer r.mu.Unlock()
	o, ok := r.observed[id]
	if !ok || r.ns == nil {
		return caddy.Endpoint{}, false
	}
	var spec *state.WorkloadSpec
	for i := range r.ns.Workloads {
		if r.ns.Workloads[i].ID == id {
			spec = &r.ns.Workloads[i]
		}
	}
	if spec == nil {
		return caddy.Endpoint{}, false
	}
	return caddy.Endpoint{Addr: o.ip, Serving: spec.Role == state.RoleServing, Ready: r.readyLocked(spec, o)}, true
}

func (r *Reconciler) readyLocked(spec *state.WorkloadSpec, o observed) bool {
	if !o.c.Running {
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
		out = append(out, statusOf(s, op, np, probe, now))
	}
	for id, n := range r.book {
		if !seen[id] && n.phase == state.PhaseRemoved {
			out = append(out, state.WorkloadStatus{ID: id, Phase: state.PhaseRemoved, ObservedAt: now})
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

// statusOf derives the reported phase of one desired workload. It is pure.
func statusOf(s *state.WorkloadSpec, o *observed, n *note, probe *health.Status, now time.Time) state.WorkloadStatus {
	ws := state.WorkloadStatus{ID: s.ID, ObservedAt: now}
	if probe != nil {
		ps := &state.ProbeStatus{State: probe.State, ConsecutiveFailures: probe.ConsecutiveFailures, LastError: clip(probe.LastError, 1024), Attempts: probe.Attempts}
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
		return health.Hint(int(*s.Port), o.ports)
	}

	switch {
	case n != nil && n.phase == state.PhaseFailed && !c.Running:
		ws.Phase, ws.Message = state.PhaseFailed, n.message
	case s.Schedule != nil && s.Role == state.RoleServing:
		// A scheduled job is ready once its container exists: between runs
		// it is "created" or "exited", which is how it should be.
		ws.Phase, ws.Message = scheduledStatus(c, &ws)
	case s.Role == state.RoleDraining:
		if c.Running {
			ws.Phase = state.PhaseDraining
		} else {
			ws.Phase, ws.Message = state.PhaseExited, "drained"
		}
	case c.State == "running":
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
	case c.State == "restarting":
		ws.Phase, ws.Message = state.PhaseUnhealthy, fmt.Sprintf("restarting after exit code %d", c.ExitCode)
	case c.State == "created":
		ws.Phase = state.PhaseStarting
	case c.State == "paused":
		ws.Phase, ws.Message = state.PhaseUnhealthy, "paused"
	case c.State == "removing":
		ws.Phase = state.PhaseRemoved
	default: // exited, dead
		ws.Phase = state.PhaseExited
		code := c.ExitCode
		ws.ExitCode = &code
		if c.OOMKilled {
			ws.Message = "killed: out of memory (raise the memory limit)"
		}
	}
	ws.Message = clip(ws.Message, maxMessage)
	return ws
}

// scheduledStatus: healthy unless Docker can't run it; the last run's
// outcome is in the message (and its exit code in ExitCode).
func scheduledStatus(c docker.Container, ws *state.WorkloadStatus) (state.WorkloadPhase, string) {
	switch c.State {
	case "running":
		return state.PhaseHealthy, "scheduled: running now"
	case "created":
		return state.PhaseHealthy, "scheduled: waiting for the first run"
	case "exited":
		code := c.ExitCode
		ws.ExitCode = &code
		switch {
		case c.OOMKilled:
			return state.PhaseHealthy, "scheduled: the last run was killed: out of memory (raise the memory limit)"
		case code != 0:
			return state.PhaseHealthy, fmt.Sprintf("scheduled: the last run exited with code %d", code)
		}
		return state.PhaseHealthy, "scheduled: the last run succeeded"
	case "removing":
		return state.PhaseRemoved, ""
	}
	return state.PhaseUnhealthy, "scheduled: container is " + c.State
}

func joinMsg(a, b string) string {
	if b == "" {
		return a
	}
	return a + "; " + b
}

func clip(s string, n int) string {
	if len(s) > n {
		return s[:n]
	}
	return s
}
