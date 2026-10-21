package docker

import (
	"context"
	"encoding/json"
	"fmt"
	"io"
	"time"

	"github.com/moby/moby/api/types/container"
	"github.com/moby/moby/client"
)

// ContainerStats is one usage reading of a container (Docker's stats API,
// one-shot, no streaming).
type ContainerStats struct {
	// Read is when Docker took the reading.
	Read time.Time
	// CPUTotalNs is the cumulative CPU time of the container (nanoseconds).
	CPUTotalNs uint64
	// MemUsedBytes is memory usage minus the inactive file cache (what `docker stats` shows).
	MemUsedBytes uint64
	// HasMemory is false when Docker reported no memory usage (e.g. stopped container).
	HasMemory bool
}

// StatsOps reads container usage for metrics (docs/design/direction.md §8.2).
// Only containers managed by this server are read.
type StatsOps interface {
	ContainerStats(ctx context.Context, id string) (ContainerStats, error)
}

var _ StatsOps = (*Engine)(nil)

const maxStatsBody = 1 << 20

// ContainerStats implements StatsOps.
func (e *Engine) ContainerStats(ctx context.Context, id string) (ContainerStats, error) {
	if err := e.mustOwnContainer(ctx, id); err != nil {
		return ContainerStats{}, err
	}
	ctx, cancel := e.call(ctx)
	defer cancel()
	res, err := e.api.ContainerStats(ctx, id, client.ContainerStatsOptions{})
	if err != nil {
		return ContainerStats{}, wrap("container stats", err)
	}
	defer func() { _ = res.Body.Close() }()
	var s container.StatsResponse
	if err := json.NewDecoder(io.LimitReader(res.Body, maxStatsBody)).Decode(&s); err != nil {
		return ContainerStats{}, fmt.Errorf("docker: decode container stats: %w", err)
	}
	return statsFrom(&s), nil
}

func statsFrom(s *container.StatsResponse) ContainerStats {
	out := ContainerStats{Read: s.Read.UTC(), CPUTotalNs: s.CPUStats.CPUUsage.TotalUsage}
	if u := s.MemoryStats.Usage; u > 0 {
		inactive := s.MemoryStats.Stats["inactive_file"] // cgroup v2
		if v, ok := s.MemoryStats.Stats["total_inactive_file"]; ok {
			inactive = v // cgroup v1
		}
		if inactive < u {
			u -= inactive
		}
		out.MemUsedBytes, out.HasMemory = u, true
	}
	return out
}
