package reconcile

import (
	"context"
	"errors"
	"fmt"

	"github.com/moby/moby/api/types/mount"

	"github.com/vantezzen/launchroom/daemon/internal/docker"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// Volume owners (G76, docs/03 §5): an image that runs as a non-root user
// can't write to a fresh volume, whose top folder belongs to root. For a
// mount with initOwner, launchroomd gives the volume's top folder to that
// owner once: only while the volume is still empty, never a volume that has
// files, never a read-only mount (docker.InitVolumeOwner). It runs after the
// container was created and before it starts. Release commands do the same
// for their mounts (tasks, run_once).

// initOwners applies the mounts' initOwner of a created container.
func (r *Reconciler) initOwners(ctx context.Context, cc *docker.ContainerCreate, spec *state.WorkloadSpec) error {
	if spec == nil || cc == nil || cc.HostConfig == nil {
		return nil
	}
	var errs []error
	for _, m := range spec.Mounts {
		if m.InitOwner == nil || m.ReadOnly {
			continue
		}
		source := ""
		for _, hm := range cc.HostConfig.Mounts {
			if hm.Target == string(m.Target) && hm.Type == mount.TypeVolume {
				source = hm.Source
			}
		}
		if source == "" {
			continue
		}
		changed, err := docker.InitVolumeOwner(ctx, r.o.Docker, r.o.ServerID, source, *m.InitOwner)
		if err != nil {
			errs = append(errs, fmt.Errorf("volume %s: %w", source, err))
			continue
		}
		if changed {
			r.o.Log.Info("gave a new volume to the image's user", "volume", source, "uid", m.InitOwner.UID, "gid", m.InitOwner.GID)
		}
	}
	return errors.Join(errs...)
}
