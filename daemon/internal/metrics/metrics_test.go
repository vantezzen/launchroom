package metrics

import (
	"context"
	"fmt"
	"os"
	"path/filepath"
	"testing"
	"time"

	"github.com/moby/moby/api/types/container"

	"github.com/vantezzen/launchroom/daemon/internal/docker"
	"github.com/vantezzen/launchroom/daemon/internal/docker/dockerfake"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

const testServer = "srv_01J9Z8X7W6V5T4S3R2Q1P0N9MK"

// fakeProc is a writable /proc tree.
type fakeProc struct {
	t   *testing.T
	dir string
}

func newFakeProc(t *testing.T) *fakeProc {
	t.Helper()
	p := &fakeProc{t: t, dir: t.TempDir()}
	if err := os.MkdirAll(filepath.Join(p.dir, "net"), 0o750); err != nil {
		t.Fatal(err)
	}
	return p
}

func (p *fakeProc) write(rel, content string) {
	p.t.Helper()
	if err := os.WriteFile(filepath.Join(p.dir, rel), []byte(content), 0o600); err != nil {
		p.t.Fatal(err)
	}
}

// set writes /proc/stat with the given busy and idle jiffies, meminfo,
// loadavg and net/dev with eth0 (counted) and docker0/veth (skipped) bytes.
func (p *fakeProc) set(busy, idle uint64, rx, tx uint64, load float64) {
	p.write("stat", fmt.Sprintf("cpu  %d 0 0 %d 0 0 0 0 0 0\ncpu0 1 2 3 4\n", busy, idle))
	p.write("meminfo", "MemTotal:        8192000 kB\nMemFree:  100 kB\nMemAvailable:    6144000 kB\n")
	p.write("loadavg", fmt.Sprintf("%.2f 0.30 0.25 1/100 4242\n", load))
	p.write("net/dev", fmt.Sprintf(`Inter-|   Receive                                                |  Transmit
 face |bytes    packets errs drop fifo frame compressed multicast|bytes    packets errs drop fifo colls carrier compressed
    lo: 999999 1 0 0 0 0 0 0 999999 1 0 0 0 0 0 0
  eth0: %d 10 0 0 0 0 0 0 %d 10 0 0 0 0 0 0
docker0: %d 1 0 0 0 0 0 0 %d 1 0 0 0 0 0 0
vethab12: %d 1 0 0 0 0 0 0 %d 1 0 0 0 0 0 0
tailscale0: %d 1 0 0 0 0 0 0 %d 1 0 0 0 0 0 0
`, rx, tx, rx*7, tx*7, rx*3, tx*3, rx*5, tx*5))
}

type clock struct{ t time.Time }

func (c *clock) now() time.Time { return c.t }

func TestSkipInterface(t *testing.T) {
	for name, skip := range map[string]bool{
		"lo": true, "docker0": true, "br-45687e908daf": true, "veth1": true, "tailscale0": true, "tunl0": true,
		"eth0": false, "enp1s0": false, "wlan0": false, "ens3": false, "bond0": false,
	} {
		if SkipInterface(name) != skip {
			t.Errorf("%s: want skip=%v", name, skip)
		}
	}
}

func TestHostMinuteAggregation(t *testing.T) {
	p := newFakeProc(t)
	clk := &clock{time.Date(2026, 10, 19, 12, 0, 0, 0, time.UTC)}
	minutes := 0
	c := New(Options{
		ProcRoot: p.dir, Now: clk.now, OnMinute: func() { minutes++ },
		Disk: func(context.Context) (state.FilesystemUsage, bool) {
			return state.FilesystemUsage{TotalMB: 1000, FreeMB: 250}, true
		},
	})
	ctx := context.Background()
	// 12:00:00 primes the counters (no CPU %, no network rates yet).
	p.set(1000, 9000, 1_000_000, 500_000, 0.5)
	c.Sample(ctx)
	// 12:00:15: 25 % busy, 15 000 B/s rx, 3 000 B/s tx.
	clk.t = clk.t.Add(15 * time.Second)
	p.set(1250, 9750, 1_225_000, 545_000, 0.7)
	c.Sample(ctx)
	// 12:00:30: 75 % busy, no traffic.
	clk.t = clk.t.Add(15 * time.Second)
	p.set(1550, 9850, 1_225_000, 545_000, 0.9)
	c.Sample(ctx)
	if c.Pending() != nil || minutes != 0 {
		t.Fatal("the current minute is not complete")
	}
	// 12:01:00 completes 12:00.
	clk.t = clk.t.Add(30 * time.Second)
	p.set(1600, 9900, 1_225_000, 545_000, 1.1)
	c.Sample(ctx)
	m := c.Pending()
	if m == nil || len(m.Host) != 1 || minutes != 1 {
		t.Fatalf("pending %+v (minutes %d)", m, minutes)
	}
	h := m.Host[0]
	if !h.At.Equal(time.Date(2026, 10, 19, 12, 0, 0, 0, time.UTC)) || h.Samples != 3 {
		t.Errorf("minute %v samples %d", h.At, h.Samples)
	}
	if h.CPUPct == nil || *h.CPUPct != 50 {
		t.Errorf("cpu %v", h.CPUPct)
	}
	if *h.MemTotalMB != 8000 || *h.MemUsedMB != 2000 || *h.DiskUsedMB != 750 || *h.DiskTotalMB != 1000 {
		t.Errorf("mem %d/%d disk %d/%d", *h.MemUsedMB, *h.MemTotalMB, *h.DiskUsedMB, *h.DiskTotalMB)
	}
	// Bridges, veths, lo and tailscale0 are excluded: only eth0 counts.
	if *h.NetRxBps != 7500 || *h.NetTxBps != 1500 {
		t.Errorf("net %d/%d", *h.NetRxBps, *h.NetTxBps)
	}
	if *h.Load1 != 0.7 {
		t.Errorf("load %v", *h.Load1)
	}
	rep := state.Report{TypeMeta: state.NewTypeMeta(state.KindReport), ServerID: testServer, ObservedAt: clk.t, Seq: 1, Workloads: []state.WorkloadStatus{}, Metrics: m}
	if err := rep.Validate(); err != nil {
		t.Fatal(err)
	}
}

func TestCounterResetsAreSkipped(t *testing.T) {
	p := newFakeProc(t)
	clk := &clock{time.Date(2026, 10, 19, 12, 0, 0, 0, time.UTC)}
	c := New(Options{ProcRoot: p.dir, Now: clk.now})
	p.set(5000, 5000, 9_000_000, 9_000_000, 0)
	c.Sample(context.Background())
	clk.t = clk.t.Add(15 * time.Second)
	p.set(100, 100, 10, 10, 0) // reboot-like reset
	c.Sample(context.Background())
	clk.t = clk.t.Add(time.Minute)
	c.Sample(context.Background())
	m := c.Pending()
	if m == nil || len(m.Host) != 1 {
		t.Fatalf("%+v", m)
	}
	if h := m.Host[0]; h.CPUPct != nil || *h.NetRxBps != 0 {
		t.Errorf("reset counters must not produce values: cpu %v rx %v", h.CPUPct, *h.NetRxBps)
	}
}

func TestBufferAckAndBounds(t *testing.T) {
	p := newFakeProc(t)
	p.set(1, 1, 1, 1, 0.1)
	clk := &clock{time.Date(2026, 10, 19, 12, 0, 0, 0, time.UTC)}
	c := New(Options{ProcRoot: p.dir, Now: clk.now})
	for range 3 {
		c.Sample(context.Background())
		clk.t = clk.t.Add(time.Minute)
	}
	sent := c.Pending()
	if len(sent.Host) != 2 {
		t.Fatalf("%d minutes", len(sent.Host))
	}
	// A minute completes while the report is in flight; the ack keeps it.
	c.Sample(context.Background())
	c.Ack(sent)
	m := c.Pending()
	if m == nil || len(m.Host) != 1 || !m.Host[0].At.Equal(time.Date(2026, 10, 19, 12, 2, 0, 0, time.UTC)) {
		t.Fatalf("after ack %+v", m)
	}
	c.Ack(m)
	if c.Pending() != nil {
		t.Fatal("empty after acking everything")
	}
	// Undelivered minutes are bounded (oldest dropped).
	for range MaxHostMinutes + 10 {
		clk.t = clk.t.Add(time.Minute)
		c.Sample(context.Background())
	}
	m = c.Pending()
	if len(m.Host) != MaxHostMinutes || !m.Host[len(m.Host)-1].At.Equal(clk.t.Truncate(time.Minute).Add(-time.Minute)) {
		t.Fatalf("%d minutes, last %v", len(m.Host), m.Host[len(m.Host)-1].At)
	}
}

func TestWorkloadStats(t *testing.T) {
	fake := dockerfake.New(testServer)
	fake.AddImage("img")
	ctx := context.Background()
	start := func(name, wl string) string {
		labels := docker.ManagedLabels(testServer)
		if wl != "" {
			labels[docker.LabelWorkload] = wl
		}
		cc := &docker.ContainerCreate{Name: name, Config: &container.Config{Image: "img", Labels: labels}}
		id, err := fake.CreateContainer(ctx, cc)
		if err != nil {
			t.Fatal(err)
		}
		if err := fake.StartContainer(ctx, id); err != nil {
			t.Fatal(err)
		}
		return id
	}
	const wlA, wlB = "wl_01J9Z8X7W6V5T4S3R2Q1P0N9MA", "wl_01J9Z8X7W6V5T4S3R2Q1P0N9MB"
	a := start("lr-a", wlA)
	b := start("lr-b", wlB)
	sys := start("lr-system-caddy", "") // no workload label: not a workload
	other := fake.AddUnmanagedContainer("someone-else", map[string]string{docker.LabelWorkload: wlA})

	clk := &clock{time.Date(2026, 10, 19, 12, 0, 0, 0, time.UTC)}
	c := New(Options{ProcRoot: t.TempDir(), Now: clk.now, Containers: fake, Stats: fake})
	read := clk.t
	set := func(cpuA, cpuB uint64) {
		fake.SetStats(a, docker.ContainerStats{Read: read, CPUTotalNs: cpuA, MemUsedBytes: 200 << 20, HasMemory: true})
		fake.SetStats(b, docker.ContainerStats{Read: read, CPUTotalNs: cpuB})
		fake.SetStats(sys, docker.ContainerStats{Read: read, CPUTotalNs: 1, MemUsedBytes: 1 << 30, HasMemory: true})
	}
	set(1e9, 5e9)
	c.Sample(ctx)
	clk.t, read = clk.t.Add(15*time.Second), read.Add(15*time.Second)
	set(1e9+15e9/2, 5e9) // a: half a core
	c.Sample(ctx)
	clk.t, read = clk.t.Add(45*time.Second), read.Add(45*time.Second)
	set(1e9+15e9/2+45e9/4, 5e9+45e9) // a: a quarter core, b: one core
	c.Sample(ctx)
	m := c.Pending()
	if m == nil || len(m.Workloads) != 2 {
		t.Fatalf("%+v", m)
	}
	got := map[state.WorkloadID]state.WorkloadMetricSample{}
	for _, s := range m.Workloads {
		got[s.Workload] = s
	}
	if s := got[wlA]; s.CPUCores == nil || *s.CPUCores != 0.5 || s.MemMB == nil || *s.MemMB != 200 {
		t.Errorf("a: %+v", s)
	}
	if s := got[wlB]; s.CPUCores == nil || *s.CPUCores != 0 || s.MemMB != nil {
		// b was idle in 12:00 and reports no memory.
		t.Errorf("b: %+v", s)
	}
	clk.t = clk.t.Add(time.Minute)
	c.Sample(ctx)
	m = c.Pending()
	var last []state.WorkloadMetricSample
	for _, s := range m.Workloads {
		if s.At.Equal(time.Date(2026, 10, 19, 12, 1, 0, 0, time.UTC)) {
			last = append(last, s)
		}
	}
	if len(last) != 2 || *last[0].CPUCores != 0.25 || *last[1].CPUCores != 1 {
		t.Fatalf("12:01 %+v", last)
	}
	if _, err := fake.ContainerStats(ctx, other); err == nil {
		t.Fatal("unmanaged containers must be refused")
	}
	rep := state.Report{TypeMeta: state.NewTypeMeta(state.KindReport), ServerID: testServer, ObservedAt: clk.t, Seq: 1, Workloads: []state.WorkloadStatus{}, Metrics: m}
	if err := rep.Validate(); err != nil {
		t.Fatal(err)
	}
}
