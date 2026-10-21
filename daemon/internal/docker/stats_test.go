package docker

import (
	"testing"
	"time"

	"github.com/moby/moby/api/types/container"
)

func TestStatsFrom(t *testing.T) {
	read := time.Date(2026, 10, 19, 12, 0, 0, 0, time.UTC)
	v2 := statsFrom(&container.StatsResponse{
		Read:        read,
		CPUStats:    container.CPUStats{CPUUsage: container.CPUUsage{TotalUsage: 42}},
		MemoryStats: container.MemoryStats{Usage: 300 << 20, Stats: map[string]uint64{"inactive_file": 100 << 20}},
	})
	if v2.CPUTotalNs != 42 || !v2.HasMemory || v2.MemUsedBytes != 200<<20 || !v2.Read.Equal(read) {
		t.Errorf("cgroup v2: %+v", v2)
	}
	v1 := statsFrom(&container.StatsResponse{MemoryStats: container.MemoryStats{
		Usage: 300 << 20, Stats: map[string]uint64{"inactive_file": 1, "total_inactive_file": 50 << 20},
	}})
	if v1.MemUsedBytes != 250<<20 {
		t.Errorf("cgroup v1: %+v", v1)
	}
	if none := statsFrom(&container.StatsResponse{}); none.HasMemory {
		t.Errorf("stopped: %+v", none)
	}
}
