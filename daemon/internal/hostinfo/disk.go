package hostinfo

import (
	"cmp"
	"context"
	"slices"
	"time"

	mobyclient "github.com/moby/moby/client"

	"github.com/vantezzen/launchroom/daemon/internal/names"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// DiskBreakdownInterval is how often the volume sizes are measured: Docker
// walks every volume for it, which is slow on big hosts (docs/03 §9).
const DiskBreakdownInterval = 30 * time.Minute

// walSegmentMB is PostgreSQL's default WAL segment size.
const walSegmentMB = 16

// VolumeUsager is Docker's disk usage endpoint (GET /system/df?type=volume,
// which dockerd-guard forwards).
type VolumeUsager interface {
	DiskUsage(ctx context.Context, options mobyclient.DiskUsageOptions) (mobyclient.DiskUsageResult, error)
}

// DiskBreakdownInput is what a measurement needs besides Docker.
type DiskBreakdownInput struct {
	// Declared are the volumes of the current NodeState (others are retired).
	Declared []state.VolumeID
	// WalPendingSegments are the WAL segments waiting to be shipped, summed
	// over every archive (-1: no point-in-time recovery here).
	WalPendingSegments int
	Now                time.Time
}

// DiskBreakdown measures what takes the server's disk (HostHealth.disk):
// the launchroom-managed volumes of this server by size, retired ones
// marked, and the WAL backlog. Nil when Docker doesn't answer.
func DiskBreakdown(ctx context.Context, api VolumeUsager, serverID string, in DiskBreakdownInput) *state.DiskBreakdown {
	if api == nil {
		return nil
	}
	ctx, cancel := context.WithTimeout(ctx, 2*time.Minute)
	defer cancel()
	res, err := api.DiskUsage(ctx, mobyclient.DiskUsageOptions{Volumes: true, Verbose: true})
	if err != nil {
		return nil
	}
	d := &state.DiskBreakdown{MeasuredAt: in.Now.UTC()}
	for _, v := range res.Volumes.Items {
		if v.Labels[names.LabelManaged] != "true" || v.Labels[names.LabelServer] != serverID {
			continue
		}
		id := state.VolumeID(v.Labels[names.LabelVolume])
		if id.Validate() != nil {
			continue // system volumes (Caddy, BuildKit, …) carry a purpose, not an id
		}
		if slices.ContainsFunc(d.Volumes, func(u state.VolumeUsage) bool { return u.ID == id }) {
			continue
		}
		u := state.VolumeUsage{ID: id, Retired: !slices.Contains(in.Declared, id)}
		if v.UsageData != nil && v.UsageData.Size > 0 {
			u.SizeMB = v.UsageData.Size >> 20
		}
		d.Volumes = append(d.Volumes, u)
	}
	slices.SortFunc(d.Volumes, func(a, b state.VolumeUsage) int {
		return cmp.Or(cmp.Compare(b.SizeMB, a.SizeMB), cmp.Compare(a.ID, b.ID))
	})
	if len(d.Volumes) > state.MaxDiskVolumes {
		d.Volumes = d.Volumes[:state.MaxDiskVolumes]
	}
	if in.WalPendingSegments >= 0 {
		mb := int64(in.WalPendingSegments) * walSegmentMB
		d.WalBacklogMB = &mb
	}
	return d
}
