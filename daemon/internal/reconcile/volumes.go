package reconcile

import (
	"context"
	"errors"
	"fmt"
	"slices"
	"strings"

	"github.com/vantezzen/launchroom/daemon/internal/docker"
	"github.com/vantezzen/launchroom/daemon/internal/policy"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// deleteVolumes carries out NodeState.deleteVolumes (docs/05 §1.1). Volumes
// are only ever deleted when the dashboard asks for them explicitly, never
// because they left the desired state. A volume is deleted only if it is
// managed by this server, its id/service/environment labels match the
// request exactly, it is not declared in the NodeState and no container uses
// it; Docker's own in-use check (removal is never forced) is the last line.
// Final outcomes (deleted, absent) are kept while the request stays; refused
// and failed ones are retried on later passes.
func (r *Reconciler) deleteVolumes(ctx context.Context, ns *state.NodeState) error {
	requested := make(map[state.VolumeID]bool, len(ns.DeleteVolumes))
	for _, d := range ns.DeleteVolumes {
		requested[d.ID] = true
	}
	r.mu.Lock()
	for id := range r.deletions {
		if !requested[id] {
			delete(r.deletions, id)
		}
	}
	pending := slices.DeleteFunc(slices.Clone(ns.DeleteVolumes), func(d state.VolumeDeletion) bool {
		res, ok := r.deletions[d.ID]
		return ok && res.Name == d.Name && (res.Outcome == state.VolumeDeleted || res.Outcome == state.VolumeAbsent)
	})
	r.mu.Unlock()
	if len(pending) == 0 {
		return nil
	}

	vols, err := r.o.Docker.ListVolumes(ctx)
	if err != nil {
		return fmt.Errorf("delete volumes: list volumes: %w", err)
	}
	cs, err := r.o.Docker.ListContainers(ctx, nil)
	if err != nil {
		return fmt.Errorf("delete volumes: list containers: %w", err)
	}
	have := make(map[string]docker.Volume, len(vols))
	for _, v := range vols {
		have[v.Name] = v
	}
	declared := map[string]bool{}
	for _, v := range ns.Volumes {
		declared[string(v.ID)] = true
		declared[string(v.Name)] = true
	}

	var errs []error
	for _, d := range pending {
		res := state.VolumeDeletionResult{ID: d.ID, Name: d.Name, At: r.o.Now().UTC()}
		res.Outcome, res.Message = r.deleteVolume(ctx, d, have, cs, declared)
		res.Message = clip(res.Message, maxMessage)
		switch res.Outcome {
		case state.VolumeDeleted:
			r.o.Log.Info("volume deleted on request", "volume", d.Name, "id", d.ID, "service", d.Service, "environment", d.Environment)
		case state.VolumeAbsent:
			r.o.Log.Info("volume requested for deletion does not exist", "volume", d.Name, "id", d.ID)
		default:
			r.o.Log.Warn("volume not deleted", "volume", d.Name, "id", d.ID, "outcome", res.Outcome, "reason", res.Message)
			if res.Outcome == state.VolumeDeleteFailed {
				errs = append(errs, fmt.Errorf("delete volume %s: %s", d.Name, res.Message))
			}
		}
		r.mu.Lock()
		r.deletions[d.ID] = res
		r.mu.Unlock()
	}
	return errors.Join(errs...)
}

func (r *Reconciler) deleteVolume(ctx context.Context, d state.VolumeDeletion, have map[string]docker.Volume, cs []docker.Container, declared map[string]bool) (state.VolumeDeletionOutcome, string) {
	if err := policy.CheckVolumeName(d.Name); err != nil {
		return state.VolumeRefused, err.Error()
	}
	if declared[string(d.ID)] || declared[string(d.Name)] {
		return state.VolumeRefused, "the volume is still declared in the desired state"
	}
	v, ok := have[string(d.Name)]
	if !ok {
		return state.VolumeAbsent, ""
	}
	l := v.Labels
	switch {
	case l[docker.LabelSystem] != "":
		return state.VolumeRefused, "the volume belongs to a launchroom system container"
	case l[docker.LabelVolume] != string(d.ID):
		return state.VolumeRefused, fmt.Sprintf("the volume's id label is %q, not %s", l[docker.LabelVolume], d.ID)
	case l[docker.LabelService] != string(d.Service) || l[docker.LabelEnvironment] != string(d.Environment):
		return state.VolumeRefused, "the volume's service or environment labels do not match the request"
	}
	var users []string
	for _, c := range cs {
		if slices.Contains(c.Volumes, string(d.Name)) {
			users = append(users, c.Name)
		}
	}
	if len(users) > 0 {
		slices.Sort(users)
		return state.VolumeRefused, "the volume is in use by " + strings.Join(users, ", ")
	}
	err := r.o.Docker.RemoveVolume(ctx, string(d.Name))
	switch {
	case err == nil:
		return state.VolumeDeleted, ""
	case errors.Is(err, docker.ErrNotFound):
		return state.VolumeAbsent, ""
	default:
		return state.VolumeDeleteFailed, err.Error()
	}
}

// VolumeDeletions returns the outcomes of the current NodeState's deleteVolumes, sorted by id.
func (r *Reconciler) VolumeDeletions() []state.VolumeDeletionResult {
	r.mu.Lock()
	defer r.mu.Unlock()
	if len(r.deletions) == 0 {
		return nil
	}
	out := make([]state.VolumeDeletionResult, 0, len(r.deletions))
	for _, res := range r.deletions {
		out = append(out, res)
	}
	slices.SortFunc(out, func(a, b state.VolumeDeletionResult) int { return strings.Compare(string(a.ID), string(b.ID)) })
	return out
}
