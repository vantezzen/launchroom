//go:build integration

package integration

import (
	"context"
	"errors"
	"sync"
	"testing"
	"time"

	"github.com/moby/moby/api/types/container"
	"github.com/moby/moby/client"

	"github.com/vantezzen/launchroom/daemon/internal/docker"
	"github.com/vantezzen/launchroom/daemon/internal/hostinfo"
	"github.com/vantezzen/launchroom/daemon/internal/metrics"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// TestWorkloadMetricsFromDocker: Docker's one-shot stats of a real managed
// workload container become per-minute samples; unmanaged containers are
// refused and never sampled. Host metrics come from the real /proc.
func TestWorkloadMetricsFromDocker(t *testing.T) {
	e := setup(t)
	ctx := t.Context()
	pull(ctx, t, e.api, string(whoamiImage))

	run := func(name string, labels map[string]string) string {
		t.Helper()
		res, err := e.api.ContainerCreate(ctx, client.ContainerCreateOptions{
			Name: name, Config: &container.Config{Image: string(whoamiImage), Labels: labels},
		})
		if err != nil {
			t.Fatal(err)
		}
		t.Cleanup(func() {
			_, _ = e.api.ContainerRemove(context.WithoutCancel(ctx), res.ID, client.ContainerRemoveOptions{Force: true})
		})
		if _, err := e.api.ContainerStart(ctx, res.ID, client.ContainerStartOptions{}); err != nil {
			t.Fatal(err)
		}
		return res.ID
	}
	wl := state.WorkloadID(randomID("wl"))
	labels := docker.ManagedLabels(string(e.serverID))
	labels[docker.LabelWorkload] = string(wl)
	run("lr-metrics-managed", labels)
	foreign := run("metrics-foreign", map[string]string{docker.LabelWorkload: string(wl)})

	if _, err := e.engine.ContainerStats(ctx, foreign); !errors.Is(err, docker.ErrNotManaged) {
		t.Fatalf("stats of an unmanaged container: %v", err)
	}

	// A clock that crosses a minute boundary between samples; CPU deltas use
	// Docker's own read times.
	var mu sync.Mutex
	now := time.Now().UTC().Truncate(time.Minute)
	clock := func() time.Time { mu.Lock(); defer mu.Unlock(); return now }
	advance := func(d time.Duration) { mu.Lock(); now = now.Add(d); mu.Unlock() }
	c := metrics.New(metrics.Options{
		Containers: e.engine, Stats: e.engine, Now: clock,
		Disk: func(context.Context) (state.FilesystemUsage, bool) { return hostinfo.Filesystem("/") },
	})
	c.Sample(ctx)
	time.Sleep(2 * time.Second)
	advance(30 * time.Second)
	c.Sample(ctx)
	advance(time.Minute)
	c.Sample(ctx)

	m := c.Pending()
	if m == nil || len(m.Host) != 1 || len(m.Workloads) != 1 {
		t.Fatalf("pending %+v", m)
	}
	h := m.Host[0]
	if h.CPUPct == nil || h.MemUsedMB == nil || *h.MemUsedMB <= 0 || h.DiskTotalMB == nil || h.Load1 == nil {
		t.Errorf("host sample %+v", h)
	}
	s := m.Workloads[0]
	if s.Workload != wl || s.CPUCores == nil || s.MemMB == nil || *s.MemMB <= 0 {
		t.Errorf("workload sample %+v", s)
	}
	t.Logf("host cpu %.2f%% mem %d MB; workload cpu %.3f cores mem %d MB", *h.CPUPct, *h.MemUsedMB, *s.CPUCores, *s.MemMB)
	rep := state.Report{TypeMeta: state.NewTypeMeta(state.KindReport), ServerID: e.serverID, ObservedAt: time.Now().UTC(), Seq: 1, Workloads: []state.WorkloadStatus{}, Metrics: m}
	if err := rep.Validate(); err != nil {
		t.Fatal(err)
	}
}
