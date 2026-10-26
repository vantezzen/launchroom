// Package reconcile converges Docker networks, volumes and workload
// containers to the signed NodeState (docs/03 §5, docs/10 §4). It is
// level-triggered: every pass observes the actual state, computes a plan with
// the pure Diff function and applies it. Only objects labelled
// launchroom.managed=true for this server are ever touched.
//
// Rolling updates are expressed by the dashboard in the desired state (new and
// old workloads coexist; the old one becomes "draining"); the daemon converges
// each workload id independently and gates traffic on health in the ingress.
package reconcile

import (
	"cmp"
	"fmt"
	"net/netip"
	"slices"
	"time"

	"github.com/vantezzen/launchroom/daemon/internal/docker"
	"github.com/vantezzen/launchroom/daemon/internal/policy"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// ActionKind is one kind of change.
type ActionKind string

// Action kinds, in the order Diff emits them.
const (
	CreateNetwork   ActionKind = "create-network"
	CreateVolume    ActionKind = "create-volume"
	PullImage       ActionKind = "pull"
	RemoveContainer ActionKind = "remove" // stop (grace) + remove
	CreateWorkload  ActionKind = "create" // create + start
	StartContainer  ActionKind = "start"
	StopContainer   ActionKind = "stop"
	RemoveNetwork   ActionKind = "remove-network"
)

// Action is one step of a plan.
type Action struct {
	Kind        ActionKind
	Workload    state.WorkloadID // create/start/stop/remove; also the pulls' first user
	ContainerID string
	Image       state.ImageRef
	Network     state.NetworkSpec // create-network
	NetworkID   string            // remove-network (Docker id)
	NetworkName string
	Volume      state.VolumeSpec
	// VolumeOwner is the service and environment a created volume belongs to
	// (labelled on it; checked before every later mount, docs/03 §8).
	VolumeOwner VolumeOwner
	Grace       time.Duration
	// Undesired is set on removals of containers whose workload is gone from the desired state.
	Undesired bool
	// CreateOnly: the container is created but not started (scheduled
	// workloads; RunDue starts it when it is due).
	CreateOnly bool
	Reason     string
	// Why is the typed reason of a stop or removal, reported as a lifecycle
	// event (D68).
	Why state.WorkloadEventReason
}

// String renders the action for logs.
func (a Action) String() string {
	switch a.Kind {
	case CreateNetwork:
		return fmt.Sprintf("%s %s %s", a.Kind, a.Network.Name, a.Network.Subnet)
	case CreateVolume:
		return fmt.Sprintf("%s %s", a.Kind, a.Volume.Name)
	case PullImage:
		return fmt.Sprintf("%s %s", a.Kind, a.Image)
	case RemoveNetwork:
		return fmt.Sprintf("%s %s", a.Kind, a.NetworkName)
	default:
		return fmt.Sprintf("%s %s (%s)", a.Kind, a.Workload, a.Reason)
	}
}

// VolumeOwner binds a workload volume to one service in one environment.
type VolumeOwner struct {
	Service     state.ServiceID
	Environment state.EnvironmentID
}

// Plan is the ordered list of actions plus workloads that cannot be converged.
type Plan struct {
	Actions []Action
	// Blocked workloads are not touched this pass (reason per workload).
	Blocked map[state.WorkloadID]string
}

// DesiredWorkload is a workload whose container spec could be built.
type DesiredWorkload struct {
	Spec state.WorkloadSpec
	// Hash identifies the container spec (incl. a keyed digest of the env).
	Hash string
	// MeshBinding is the mesh ip:port the workload must be published on
	// (zero = none).
	MeshBinding netip.AddrPort
}

// Desired is the converge target derived from a NodeState.
type Desired struct {
	Networks  []state.NetworkSpec
	Volumes   []state.VolumeSpec
	Workloads []DesiredWorkload
	// Skipped workloads could not be built (env or policy failure): their
	// existing containers are left exactly as they are.
	Skipped map[state.WorkloadID]string
}

// Actual is the observed state (managed objects only, system containers excluded).
type Actual struct {
	Networks   []docker.Network
	Volumes    []docker.Volume
	Containers []docker.Container
	// Images reports which desired images are present locally.
	Images map[state.ImageRef]bool
}

// Diff computes the plan that converges actual to desired at time now. It is
// pure: no I/O, deterministic output order.
func Diff(d Desired, a Actual, now time.Time, grace time.Duration) Plan {
	p := Plan{Blocked: map[state.WorkloadID]string{}}
	var creates, pulls, workloadSteps, drains, removals, netRemovals []Action

	// Networks. Only per-environment names (lr-e_…) are converged: system
	// networks such as lr-system-build belong to their managers and must
	// never be declared, created or joined by a workload (docs/03 §8).
	actualNets := map[string]docker.Network{}
	for _, n := range a.Networks {
		actualNets[n.Name] = n
	}
	desiredNetNames := map[string]bool{}
	badNets := map[state.NetworkID]string{}
	for _, n := range d.Networks {
		if err := policy.CheckNetworkName(n.Name); err != nil {
			badNets[n.ID] = err.Error()
			continue
		}
		desiredNetNames[string(n.Name)] = true
		got, ok := actualNets[string(n.Name)]
		if !ok {
			creates = append(creates, Action{Kind: CreateNetwork, Network: n, Reason: "missing"})
			continue
		}
		want, _ := netip.ParsePrefix(string(n.Subnet))
		if !slices.Contains(got.Subnets, want) {
			badNets[n.ID] = fmt.Sprintf("network %s exists with subnet %v, want %s (recreate it or allocate a new network)", n.Name, got.Subnets, n.Subnet)
		}
	}
	for _, n := range a.Networks {
		if !desiredNetNames[n.Name] {
			netRemovals = append(netRemovals, Action{Kind: RemoveNetwork, NetworkID: n.ID, NetworkName: n.Name, Reason: "not desired"})
		}
	}

	// Volumes are created here but never removed for leaving the desired
	// state (data safety); deletion happens only on an explicit
	// NodeState.deleteVolumes request (deleteVolumes, D31). A
	// volume is created only once a workload mounts it, labelled with that
	// workload's service and environment; every mount is checked against the
	// labels of the existing volume (volumeMountError).
	vols := volumeIndex(d)
	haveVol := map[string]docker.Volume{}
	for _, v := range a.Volumes {
		haveVol[v.Name] = v
	}
	for _, v := range d.Volumes {
		if vols.bad[v.ID] != "" {
			continue
		}
		// A volume no workload mounts yet is created without an owner: a
		// service moving here from another server is held while its volume
		// is copied in, and runs (and claims it) afterwards (docs/14 §13.2,
		// feature preparedVolumes). Its mounts are checked like any other.
		owner := vols.owner[v.ID]
		if _, exists := haveVol[string(v.Name)]; !exists {
			creates = append(creates, Action{Kind: CreateVolume, Volume: v, VolumeOwner: owner, Reason: "missing"})
		}
	}

	// Containers by workload id.
	byWorkload := map[state.WorkloadID][]docker.Container{}
	for _, c := range a.Containers {
		id := state.WorkloadID(c.Labels[docker.LabelWorkload])
		byWorkload[id] = append(byWorkload[id], c)
	}
	for id := range byWorkload {
		slices.SortFunc(byWorkload[id], func(x, y docker.Container) int {
			// Running first, then by name/id for determinism.
			if x.Running != y.Running {
				if x.Running {
					return -1
				}
				return 1
			}
			return cmp.Or(cmp.Compare(x.Name, y.Name), cmp.Compare(x.ID, y.ID))
		})
	}

	desiredIDs := map[state.WorkloadID]bool{}
	pulled := map[state.ImageRef]bool{}
	workloads := slices.Clone(d.Workloads)
	slices.SortFunc(workloads, func(x, y DesiredWorkload) int { return cmp.Compare(x.Spec.ID, y.Spec.ID) })

	for _, w := range workloads {
		s := w.Spec
		desiredIDs[s.ID] = true
		reason := blockedBy(s, badNets)
		if reason == "" && s.Role != state.RoleDraining {
			reason = volumeMountError(s, vols, haveVol)
		}
		if reason != "" {
			p.Blocked[s.ID] = reason
			continue
		}
		cs := byWorkload[s.ID]

		if s.Role == state.RoleDraining {
			// Never create or recreate a draining workload; keep one container until drainUntil.
			if len(cs) == 0 {
				continue
			}
			keep := cs[0]
			for _, extra := range cs[1:] {
				workloadSteps = append(workloadSteps, removal(extra, s.ID, grace, "duplicate container", state.ReasonDuplicate))
			}
			if keep.Running && s.DrainUntil != nil && !now.Before(*s.DrainUntil) {
				drains = append(drains, Action{Kind: StopContainer, Workload: s.ID, ContainerID: keep.ID, Grace: stopGrace(keep, grace), Reason: "drain period over", Why: state.ReasonDrained})
			}
			continue
		}

		var keep *docker.Container
		var stale []docker.Container
		for i := range cs {
			if keep == nil && cs[i].Labels[docker.LabelSpecHash] == w.Hash && cs[i].State != "dead" && cs[i].State != "removing" {
				keep = &cs[i]
				continue
			}
			stale = append(stale, cs[i])
		}
		if keep == nil {
			if !a.Images[s.Image] && !pulled[s.Image] {
				pulls = append(pulls, Action{Kind: PullImage, Image: s.Image, Workload: s.ID, Reason: "image not present"})
				pulled[s.Image] = true
			}
			for _, c := range stale {
				workloadSteps = append(workloadSteps, removal(c, s.ID, grace, "spec changed", state.ReasonSpecChanged))
			}
			workloadSteps = append(workloadSteps, Action{Kind: CreateWorkload, Workload: s.ID, Image: s.Image, Reason: createReason(len(stale)), CreateOnly: s.Schedule != nil})
			continue
		}
		for _, c := range stale {
			workloadSteps = append(workloadSteps, removal(c, s.ID, grace, "duplicate container", state.ReasonDuplicate))
		}
		if s.Schedule == nil && needsStart(*keep, s.Restart) {
			workloadSteps = append(workloadSteps, Action{Kind: StartContainer, Workload: s.ID, ContainerID: keep.ID, Reason: "not running (" + keep.State + ")"})
		}
		if keep.Running && w.MeshBinding.IsValid() && !slices.Contains(keep.HostPorts, w.MeshBinding) {
			// Docker started it while the mesh address was missing (e.g. its
			// restart policy at boot) and runs it without the published
			// port; a restart does not bind it either (Docker 29), so the
			// container is recreated.
			workloadSteps = append(workloadSteps, removal(*keep, s.ID, grace, "published port "+w.MeshBinding.String()+" not bound", state.ReasonPortNotBound),
				Action{Kind: CreateWorkload, Workload: s.ID, Image: s.Image, Reason: "recreate: published port not bound"})
		}
	}

	ids := make([]state.WorkloadID, 0, len(byWorkload))
	for id := range byWorkload {
		ids = append(ids, id)
	}
	slices.Sort(ids)
	for _, id := range ids {
		if desiredIDs[id] {
			continue
		}
		if _, skip := d.Skipped[id]; skip {
			continue
		}
		for _, c := range byWorkload[id] {
			rm := removal(c, id, grace, "no longer desired", state.ReasonNotDesired)
			rm.Undesired = true
			removals = append(removals, rm)
		}
	}

	// Containers no longer desired go right after networks and volumes are
	// created, before pulls and creates: a service held for an import or a
	// restore (docs/14 §12.5) stops as soon as this server applies the
	// state, not after a slow pull for another workload.
	p.Actions = slices.Concat(creates, removals, pulls, workloadSteps, drains, netRemovals)
	return p
}

func removal(c docker.Container, id state.WorkloadID, grace time.Duration, reason string, why state.WorkloadEventReason) Action {
	g := stopGrace(c, grace)
	if !c.Running {
		g = 0
	}
	return Action{Kind: RemoveContainer, Workload: id, ContainerID: c.ID, Grace: g, Reason: reason, Why: why}
}

// stopGrace is how long a stop waits for the container: the reconciler's
// grace, or the container's own stop timeout (WorkloadSpec.stop) if longer.
// The stop returns as soon as the container exits.
func stopGrace(c docker.Container, grace time.Duration) time.Duration {
	return max(grace, c.StopTimeout)
}

func createReason(stale int) string {
	if stale > 0 {
		return "recreate: spec changed"
	}
	return "missing"
}

// volumes indexes the desired volumes and who mounts them.
type volumes struct {
	spec  map[state.VolumeID]state.VolumeSpec
	owner map[state.VolumeID]VolumeOwner
	// bad are volumes no workload may mount (policy name check, owner conflict).
	bad map[state.VolumeID]string
}

func volumeIndex(d Desired) volumes {
	v := volumes{spec: map[state.VolumeID]state.VolumeSpec{}, owner: map[state.VolumeID]VolumeOwner{}, bad: map[state.VolumeID]string{}}
	for _, s := range d.Volumes {
		v.spec[s.ID] = s
		if err := policy.CheckVolumeName(s.Name); err != nil {
			v.bad[s.ID] = err.Error()
		}
	}
	for _, w := range d.Workloads {
		o := VolumeOwner{Service: w.Spec.Service, Environment: w.Spec.Environment}
		for _, m := range w.Spec.Mounts {
			have, ok := v.owner[m.Volume]
			switch {
			case !ok:
				v.owner[m.Volume] = o
			case have != o && v.bad[m.Volume] == "":
				v.bad[m.Volume] = fmt.Sprintf("policy: mounts: volume %s is mounted by workloads of different services or environments", m.Volume)
			}
		}
	}
	return v
}

// volumeMountError returns why s must not mount one of its volumes ("" = ok).
// A compromised control plane must not be able to mount launchroom's system
// volumes (Caddy's ACME keys, BuildKit state) or another service's data into
// a workload: names must be per-service volume names, and an existing
// volume must carry the declared volume id and, when it records one, the
// same service and environment.
func volumeMountError(s state.WorkloadSpec, vols volumes, have map[string]docker.Volume) string {
	for _, m := range s.Mounts {
		if reason := vols.bad[m.Volume]; reason != "" {
			return reason
		}
		spec, ok := vols.spec[m.Volume]
		if !ok {
			return fmt.Sprintf("policy: mounts: unknown volume %s", m.Volume)
		}
		got, exists := have[string(spec.Name)]
		if !exists {
			continue // created this pass with this workload's owner labels
		}
		l := got.Labels
		switch {
		case l[docker.LabelSystem] != "" || l[docker.LabelVolume] != string(m.Volume):
			return fmt.Sprintf("policy: mounts: volume %s exists but does not belong to %s", spec.Name, m.Volume)
		case l[docker.LabelService] != "" && (l[docker.LabelService] != string(s.Service) || l[docker.LabelEnvironment] != string(s.Environment)):
			return fmt.Sprintf("policy: mounts: volume %s belongs to another service", spec.Name)
		}
	}
	return ""
}

func blockedBy(s state.WorkloadSpec, badNets map[state.NetworkID]string) string {
	for _, n := range s.Networks {
		if r, ok := badNets[n.Network]; ok {
			return r
		}
	}
	return ""
}

// needsStart (unscheduled workloads; scheduled ones are started by RunDue
// only): a created container is always started; a stopped one only when
// its restart policy says Docker should keep it running (always,
// unless-stopped). "no"/"on-failure" containers that exited stay exited and
// are reported as such.
func needsStart(c docker.Container, restart state.RestartPolicy) bool {
	switch c.State {
	case "created":
		return true
	case "exited":
		return restart == state.RestartAlways || restart == state.RestartUnlessStopped
	}
	return false
}
