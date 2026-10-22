// Package metrics samples host and workload usage every 15 s, aggregates it
// per minute and buffers the completed minutes until a report carrying them
// was accepted by the dashboard (docs/design/direction.md §8.2, docs/03 §1).
//
// Host: CPU % from /proc/stat deltas, memory from /proc/meminfo, the disk of
// Docker's root dir (else the data dir), rx/tx bytes/s of the physical
// interfaces from /proc/net/dev deltas, load1. Workloads: Docker's one-shot
// stats of running managed workload containers (CPU time deltas, memory
// without the inactive file cache). Everything is best effort: what cannot be
// read is omitted, the first reading only primes the counters.
package metrics

import (
	"context"
	"log/slog"
	"slices"
	"sync"
	"time"

	"github.com/vantezzen/launchroom/daemon/internal/docker"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// Defaults and bounds.
const (
	DefaultInterval = 15 * time.Second
	// MaxHostMinutes and MaxWorkloadRows bound the undelivered buffer (oldest dropped).
	MaxHostMinutes  = 60
	MaxWorkloadRows = 4096
	statsTimeout    = 5 * time.Second
	statsWorkers    = 4
)

// Containers lists the managed containers (docker.Client.ListContainers).
type Containers interface {
	ListContainers(ctx context.Context, labels map[string]string) ([]docker.Container, error)
}

// Options configures a Collector.
type Options struct {
	// ProcRoot is /proc (tests use a fake tree).
	ProcRoot string
	// Disk returns the filesystem to report (nil: no disk metrics).
	Disk func(ctx context.Context) (state.FilesystemUsage, bool)
	// SkipInterface reports interfaces left out of the network totals (default SkipInterface).
	SkipInterface func(name string) bool
	// Containers and Stats read workload usage (nil: no workload metrics).
	Containers Containers
	Stats      docker.StatsOps
	// OnMinute is called after a minute was completed (e.g. kick a report).
	OnMinute func()
	Interval time.Duration
	Now      func() time.Time
	Log      *slog.Logger
}

// Collector samples, aggregates and buffers metrics. Safe for concurrent use.
type Collector struct {
	o Options

	// Sampling state (only touched by Sample).
	prevCPU  *cpuTimes
	prevNet  map[string]netCounters
	prevNetT time.Time
	prevCtr  map[string]ctrReading

	mu        sync.Mutex
	hostAcc   *hostAcc
	wlAcc     map[state.WorkloadID]*wlAcc
	wlMinute  time.Time
	host      []state.HostMetricSample
	workloads []state.WorkloadMetricSample
}

// New returns a Collector.
func New(o Options) *Collector {
	if o.ProcRoot == "" {
		o.ProcRoot = "/proc"
	}
	if o.SkipInterface == nil {
		o.SkipInterface = SkipInterface
	}
	if o.Interval <= 0 {
		o.Interval = DefaultInterval
	}
	if o.Now == nil {
		o.Now = time.Now
	}
	if o.Log == nil {
		o.Log = slog.New(slog.DiscardHandler)
	}
	return &Collector{o: o, prevCtr: map[string]ctrReading{}, wlAcc: map[state.WorkloadID]*wlAcc{}}
}

// Run samples every interval until ctx is done.
func (c *Collector) Run(ctx context.Context) {
	t := time.NewTicker(c.o.Interval)
	defer t.Stop()
	c.Sample(ctx)
	for {
		select {
		case <-ctx.Done():
			return
		case <-t.C:
			c.Sample(ctx)
		}
	}
}

// Sample takes one reading of the host and the workloads.
func (c *Collector) Sample(ctx context.Context) {
	now := c.o.Now().UTC()
	h := c.sampleHost(ctx, now)
	w := c.sampleWorkloads(ctx, now)
	h.buildCPU = w.buildCPU
	c.mu.Lock()
	completed := c.rollLocked(now)
	c.addHostLocked(now, h)
	c.addWorkloadsLocked(w)
	c.mu.Unlock()
	if completed && c.o.OnMinute != nil {
		c.o.OnMinute()
	}
}

// Pending returns the completed minutes not acknowledged yet (nil when none).
func (c *Collector) Pending() *state.ReportMetrics {
	c.mu.Lock()
	defer c.mu.Unlock()
	if len(c.host) == 0 && len(c.workloads) == 0 {
		return nil
	}
	return &state.ReportMetrics{
		Host:      slices.Clone(c.host),
		Workloads: slices.Clone(c.workloads),
	}
}

// Ack drops the rows of m (a report carrying them was accepted). Rows
// completed after m was taken stay.
func (c *Collector) Ack(m *state.ReportMetrics) {
	if m == nil {
		return
	}
	hostSent := make(map[int64]bool, len(m.Host))
	for _, s := range m.Host {
		hostSent[s.At.Unix()] = true
	}
	type key struct {
		w  state.WorkloadID
		at int64
	}
	wlSent := make(map[key]bool, len(m.Workloads))
	for _, s := range m.Workloads {
		wlSent[key{s.Workload, s.At.Unix()}] = true
	}
	c.mu.Lock()
	defer c.mu.Unlock()
	c.host = slices.DeleteFunc(c.host, func(s state.HostMetricSample) bool { return hostSent[s.At.Unix()] })
	c.workloads = slices.DeleteFunc(c.workloads, func(s state.WorkloadMetricSample) bool { return wlSent[key{s.Workload, s.At.Unix()}] })
}

// ---- aggregation ----

type hostAcc struct {
	minute                      time.Time
	samples                     int
	cpu, load                   avg
	buildCPU                    avg
	memUsed, memTotal           avg
	diskUsed, diskTotal, rx, tx avg
}

type wlAcc struct {
	cpu, mem avg
}

// avg is a running mean of the values seen.
type avg struct {
	sum float64
	n   int
}

func (a *avg) add(v *float64) {
	if v != nil {
		a.sum += *v
		a.n++
	}
}

func (a avg) float(digits float64) *float64 {
	if a.n == 0 {
		return nil
	}
	v := round(a.sum/float64(a.n), digits)
	return &v
}

func (a avg) int() *int64 {
	if a.n == 0 {
		return nil
	}
	v := int64(a.sum/float64(a.n) + 0.5)
	return &v
}

func round(v, digits float64) float64 {
	return float64(int64(v*digits+0.5)) / digits
}

// rollLocked completes the accumulators of minutes before now's minute.
func (c *Collector) rollLocked(now time.Time) bool {
	minute := now.Truncate(time.Minute)
	completed := false
	if a := c.hostAcc; a != nil && a.minute.Before(minute) {
		if s, ok := a.sample(); ok {
			c.host = append(c.host, s)
			if over := len(c.host) - MaxHostMinutes; over > 0 {
				c.host = slices.Delete(c.host, 0, over)
			}
			completed = true
		}
		c.hostAcc = nil
	}
	if !c.wlMinute.IsZero() && c.wlMinute.Before(minute) {
		ids := make([]state.WorkloadID, 0, len(c.wlAcc))
		for id := range c.wlAcc {
			ids = append(ids, id)
		}
		slices.Sort(ids)
		for _, id := range ids {
			a := c.wlAcc[id]
			s := state.WorkloadMetricSample{Workload: id, At: c.wlMinute, CPUCores: a.cpu.float(1000), MemMB: a.mem.int()}
			if s.CPUCores != nil || s.MemMB != nil {
				c.workloads = append(c.workloads, s)
				completed = true
			}
		}
		if over := len(c.workloads) - MaxWorkloadRows; over > 0 {
			c.workloads = slices.Delete(c.workloads, 0, over)
		}
		c.wlAcc = map[state.WorkloadID]*wlAcc{}
		c.wlMinute = time.Time{}
	}
	return completed
}

func (a *hostAcc) sample() (state.HostMetricSample, bool) {
	s := state.HostMetricSample{
		At: a.minute, Samples: min(max(a.samples, 1), 60),
		CPUPct: a.cpu.float(100), Load1: a.load.float(100),
		BuildCPUCores: a.buildCPU.float(1000),
		MemUsedMB:     a.memUsed.int(), MemTotalMB: a.memTotal.int(),
		DiskUsedMB: a.diskUsed.int(), DiskTotalMB: a.diskTotal.int(),
		NetRxBps: a.rx.int(), NetTxBps: a.tx.int(),
	}
	if s.CPUPct != nil && *s.CPUPct > 100 {
		v := 100.0
		s.CPUPct = &v
	}
	ok := s.CPUPct != nil || s.BuildCPUCores != nil || s.Load1 != nil || s.MemUsedMB != nil || s.DiskUsedMB != nil || s.NetRxBps != nil
	return s, ok
}

func (c *Collector) addHostLocked(now time.Time, h hostReading) {
	minute := now.Truncate(time.Minute)
	if c.hostAcc == nil {
		c.hostAcc = &hostAcc{minute: minute}
	}
	a := c.hostAcc
	a.samples++
	a.cpu.add(h.cpuPct)
	a.buildCPU.add(h.buildCPU)
	a.load.add(h.load1)
	a.memUsed.add(h.memUsed)
	a.memTotal.add(h.memTotal)
	a.diskUsed.add(h.diskUsed)
	a.diskTotal.add(h.diskTotal)
	a.rx.add(h.rxBps)
	a.tx.add(h.txBps)
}

func (c *Collector) addWorkloadsLocked(w workloadReadings) {
	if len(w.items) == 0 {
		return
	}
	minute := w.at.Truncate(time.Minute)
	if c.wlMinute.IsZero() {
		c.wlMinute = minute
	}
	for _, r := range w.items {
		a := c.wlAcc[r.id]
		if a == nil {
			a = &wlAcc{}
			c.wlAcc[r.id] = a
		}
		a.cpu.add(r.cores)
		a.mem.add(r.memMB)
	}
}
