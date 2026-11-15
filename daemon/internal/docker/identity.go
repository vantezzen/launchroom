package docker

import (
	"context"
	"fmt"
	"slices"

	"github.com/vantezzen/launchroom/daemon/internal/names"
	"github.com/vantezzen/launchroom/daemon/internal/policy"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// Kind is what a managed container is for.
type Kind int

// Container kinds.
const (
	// KindOther is a managed object none of the kinds below claims (the
	// self-update supervisor, a container with incomplete labels).
	KindOther Kind = iota
	// KindWorkload runs a workload of the NodeState; the reconciler owns it.
	KindWorkload
	// KindSystem is one of launchroomd's own containers (Caddy, BuildKit, …).
	KindSystem
	// KindTask is the one-off container of a run_once task.
	KindTask
	// KindHelper is a volume or access helper, or a backup throwaway.
	KindHelper
)

// Kind classifies c by its labels and name. Only a container with the
// workload label and none of the system, task or helper markers is a
// workload, so no code path can mistake a system container for one.
func (c Container) Kind() Kind {
	l := c.Labels
	switch {
	case l[names.LabelManaged] != "true":
		return KindOther
	case l[names.LabelSystem] != "" || names.System(c.Name):
		return KindSystem
	case l[names.LabelTask] != "":
		return KindTask
	case l[names.LabelHelper] != "":
		return KindHelper
	case l[names.LabelWorkload] != "":
		return KindWorkload
	}
	return KindOther
}

// Workload is the workload id of a workload container ("" for any other kind).
func (c Container) Workload() state.WorkloadID {
	if c.Kind() != KindWorkload {
		return ""
	}
	return state.WorkloadID(c.Labels[names.LabelWorkload])
}

// ContainerLister lists managed containers (Client.ListContainers).
type ContainerLister interface {
	ListContainers(ctx context.Context, labels map[string]string) ([]Container, error)
}

// WorkloadContainer returns the container of workload wl on this server,
// preferring a running one (nil: none exists).
func WorkloadContainer(ctx context.Context, d ContainerLister, wl state.WorkloadID) (*Container, error) {
	cs, err := d.ListContainers(ctx, map[string]string{names.LabelWorkload: string(wl)})
	if err != nil {
		return nil, err
	}
	return PickWorkload(cs, wl), nil
}

// PickWorkload returns the container of wl among cs, preferring a running
// one (nil: none).
func PickWorkload(cs []Container, wl state.WorkloadID) *Container {
	var out *Container
	for i := range cs {
		if cs[i].Workload() != wl {
			continue
		}
		if out == nil || (cs[i].Running() && !out.Running()) {
			out = &cs[i]
		}
	}
	return out
}

// WorkloadContainers groups the workload containers of cs by workload,
// keeping the running one when a workload has several.
func WorkloadContainers(cs []Container) map[state.WorkloadID]Container {
	out := map[state.WorkloadID]Container{}
	for _, c := range cs {
		id := c.Workload()
		if id == "" {
			continue
		}
		if prev, dup := out[id]; dup && (prev.Running() || !c.Running()) {
			continue
		}
		out[id] = c
	}
	return out
}

// VolumeOwner is the service and environment a service volume must belong to.
type VolumeOwner struct {
	Service     state.ServiceID
	Environment state.EnvironmentID
	// Exact refuses a volume that records no owner. Without it, a prepared
	// volume (created before its service runs here, for a move: docs/14
	// §13.2, D73) is accepted.
	Exact bool
}

// CheckServiceVolume reports why v is not service volume id of owner. A
// system volume, or a name outside the per-service grammar, never is (a
// compromised control plane must not reach Caddy's ACME keys or BuildKit
// state through a mount, a backup or volume access). A zero owner checks the
// id only.
func CheckServiceVolume(v Volume, id state.VolumeID, owner VolumeOwner) error {
	l := v.Labels
	switch {
	case l[names.LabelSystem] != "" || policy.CheckVolumeName(state.HostName(v.Name)) != nil:
		return fmt.Errorf("volume %s is not a service volume", v.Name)
	case l[names.LabelVolume] != string(id):
		return fmt.Errorf("volume %s carries the id %q, not %s", v.Name, l[names.LabelVolume], id)
	case owner.Service == "":
		return nil
	}
	svc, env := l[names.LabelService], l[names.LabelEnvironment]
	if svc == "" && env == "" && !owner.Exact {
		return nil
	}
	if svc != string(owner.Service) || env != string(owner.Environment) {
		return fmt.Errorf("volume %s belongs to another service", v.Name)
	}
	return nil
}

// VolumeUsers returns the containers of cs that mount the named volume.
func VolumeUsers(cs []Container, volume string) []Container {
	var out []Container
	for _, c := range cs {
		if slices.Contains(c.Volumes, volume) {
			out = append(out, c)
		}
	}
	return out
}
