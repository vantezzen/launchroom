package tasks

import (
	"context"
	"errors"
	"fmt"
	"time"

	"github.com/vantezzen/launchroom/daemon/internal/docker"
	"github.com/vantezzen/launchroom/daemon/internal/policy"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// DefaultVolumeWait is how long a run_once task with mounts waits for its
// volumes to be declared in the verified NodeState and created by the
// reconciler (the dashboard pins the task and sends the new NodeState at
// the same time, so the task can arrive first).
const DefaultVolumeWait = 2 * time.Minute

// volumeNotReady is a mount whose volume isn't declared or created yet.
type volumeNotReady struct{ msg string }

func (e *volumeNotReady) Error() string { return e.msg }

// releaseMounts resolves spec.mounts (G62, daemon feature releaseMounts) to
// container mounts and host-side volume names. Each volume must be declared
// in the verified NodeState, belong to the task's service (a shared volume
// declared for another service is refused), be mounted by no workload of
// another service or environment, and exist as a launchroom volume of this
// server whose labels (when it records an owner) name the same service and
// environment. A volume that isn't declared or created yet is waited for up
// to VolumeWait; anything else fails at once. Docker never creates a volume
// for the task: an unknown name would come back unlabelled.
func (r *oneOff) releaseMounts(ctx context.Context, spec *state.RunOnceSpec) ([]state.Mount, map[state.VolumeID]state.HostName, error) {
	if len(spec.Mounts) == 0 {
		return nil, nil, nil
	}
	if r.o.NodeState == nil {
		return nil, nil, errors.New("run_once: this server can't mount volumes into one-off containers")
	}
	wait := r.o.VolumeWait
	if wait <= 0 {
		wait = DefaultVolumeWait
	}
	deadline := time.Now().Add(wait)
	logged := false
	tick := time.NewTicker(max(r.o.PollEvery, 100*time.Millisecond))
	defer tick.Stop()
	for {
		mounts, vols, err := r.resolveMounts(ctx, spec)
		var notReady *volumeNotReady
		if !errors.As(err, &notReady) {
			return mounts, vols, err
		}
		if time.Now().After(deadline) {
			return nil, nil, fmt.Errorf("run_once: %w", err)
		}
		if !logged {
			r.logs.Line("run", state.StreamSystem, "Waiting for the service's volumes on this server") //nolint:contextcheck // logs ship on the shipper's own context
			logged = true
		}
		select {
		case <-ctx.Done():
			return nil, nil, ctx.Err()
		case <-tick.C:
		}
	}
}

// initOwners gives the task's fresh, empty volumes to the owner their
// workloads declare (G76), after the container was created and before it
// starts, as the reconciler does for workloads. A release command of a
// non-root image (SQLite migrations on the first deploy) otherwise can't
// write to a volume no workload has started on yet. A failure is logged and
// the command still runs.
func (r *oneOff) initOwners(ctx context.Context, mounts []state.Mount, vols map[state.VolumeID]state.HostName) {
	for _, m := range mounts {
		if m.InitOwner == nil || m.ReadOnly {
			continue
		}
		if _, err := docker.InitVolumeOwner(ctx, r.o.Docker, r.o.ServerID, string(vols[m.Volume]), *m.InitOwner); err != nil {
			r.logs.Line("run", state.StreamSystem, "Could not give the new volume to the image's user: "+err.Error()) //nolint:contextcheck // logs ship on the shipper's own context
		}
	}
}

func (r *oneOff) resolveMounts(ctx context.Context, spec *state.RunOnceSpec) ([]state.Mount, map[state.VolumeID]state.HostName, error) {
	ns := r.o.NodeState()
	if ns == nil {
		return nil, nil, &volumeNotReady{fmt.Sprintf("volume %s isn't declared on this server", spec.Mounts[0].Volume)}
	}
	owner := docker.VolumeOwner{Service: spec.Service, Environment: spec.Environment}
	declared := make(map[state.VolumeID]state.VolumeSpec, len(ns.Volumes))
	for _, v := range ns.Volumes {
		declared[v.ID] = v
	}
	var have map[string]docker.Volume
	mounts := make([]state.Mount, 0, len(spec.Mounts))
	vols := make(map[state.VolumeID]state.HostName, len(spec.Mounts))
	for _, m := range spec.Mounts {
		v, ok := declared[m.Volume]
		if !ok {
			return nil, nil, &volumeNotReady{fmt.Sprintf("volume %s isn't declared on this server", m.Volume)}
		}
		if err := policy.CheckVolumeName(v.Name); err != nil {
			return nil, nil, fmt.Errorf("run_once: mounts: %w", err)
		}
		if v.Service != "" && v.Service != spec.Service {
			return nil, nil, fmt.Errorf("run_once: mounts: volume %s belongs to another service", m.Volume)
		}
		var initOwner *state.FileOwner
		for i := range ns.Workloads {
			w := &ns.Workloads[i]
			for _, wm := range w.Mounts {
				if wm.Volume != m.Volume {
					continue
				}
				if w.Service != spec.Service && v.Service == "" || w.Environment != spec.Environment {
					return nil, nil, fmt.Errorf("run_once: mounts: volume %s is mounted by another service or environment", m.Volume)
				}
				// The release on a fresh volume runs before any workload
				// started on it: give it the owner the workloads declare.
				if wm.InitOwner != nil && !wm.ReadOnly && !m.ReadOnly && w.Service == spec.Service && initOwner == nil {
					initOwner = wm.InitOwner
				}
			}
		}
		if have == nil {
			list, err := r.o.Docker.ListVolumes(ctx)
			if err != nil {
				return nil, nil, fmt.Errorf("run_once: list volumes: %w", err)
			}
			have = make(map[string]docker.Volume, len(list))
			for _, x := range list {
				have[x.Name] = x
			}
		}
		got, ok := have[string(v.Name)]
		if !ok {
			return nil, nil, &volumeNotReady{fmt.Sprintf("volume %s isn't created on this server yet", m.Volume)}
		}
		if err := docker.CheckServiceVolume(got, m.Volume, owner); err != nil {
			return nil, nil, fmt.Errorf("run_once: mounts: %w", err)
		}
		mounts = append(mounts, state.Mount{Volume: m.Volume, Target: m.Target, ReadOnly: m.ReadOnly, InitOwner: initOwner})
		vols[m.Volume] = v.Name
	}
	return mounts, vols, nil
}
