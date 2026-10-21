package health

import (
	"context"
	"sync"
	"time"

	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// Status is the probe state of one workload.
type Status struct {
	State               state.ProbeState
	ConsecutiveFailures int
	LastCheckedAt       time.Time
	LastError           string
	// everPassed ends the start period early (as in Docker).
	everPassed bool
}

// Next folds one probe result into st. Failures during the start period do
// not count until the first success; afterwards failureThreshold consecutive
// failures make the probe failing, and one success makes it passing again.
func Next(st Status, probeErr error, now, startedAt time.Time, spec state.HealthSpec) Status {
	st.LastCheckedAt = now
	if st.State == "" {
		st.State = state.ProbeStarting
	}
	if probeErr == nil {
		st.State, st.ConsecutiveFailures, st.LastError, st.everPassed = state.ProbePassing, 0, "", true
		return st
	}
	st.LastError = probeErr.Error()
	if !st.everPassed && now.Before(startedAt.Add(spec.StartPeriod.Std())) {
		return st // still starting; failures don't count yet
	}
	st.ConsecutiveFailures++
	if st.ConsecutiveFailures >= max(spec.FailureThreshold, 1) {
		st.State = state.ProbeFailing
	}
	return st
}

// ProbeFunc performs one check (Probe in production).
type ProbeFunc func(ctx context.Context, t Target) error

// Monitor runs one probe goroutine per target. Sync replaces the target set;
// Close stops everything.
type Monitor struct {
	ctx      context.Context
	cancel   context.CancelFunc
	probe    ProbeFunc
	onChange func()
	now      func() time.Time

	mu      sync.Mutex
	running map[state.WorkloadID]*runner
	status  map[state.WorkloadID]Status
	wg      sync.WaitGroup
}

type runner struct {
	key    string
	cancel context.CancelFunc
}

// MonitorOptions configures a Monitor.
type MonitorOptions struct {
	// Probe performs a check (default Probe).
	Probe ProbeFunc
	// OnChange is called (from a probe goroutine) whenever a workload's probe
	// state changes. It must not block.
	OnChange func()
	// Now is the clock (default time.Now).
	Now func() time.Time
}

// NewMonitor returns a monitor whose goroutines live until ctx is done or Close is called.
func NewMonitor(ctx context.Context, opts MonitorOptions) *Monitor {
	ctx, cancel := context.WithCancel(ctx)
	m := &Monitor{
		ctx: ctx, cancel: cancel, probe: opts.Probe, onChange: opts.OnChange, now: opts.Now,
		running: map[state.WorkloadID]*runner{}, status: map[state.WorkloadID]Status{},
	}
	if m.probe == nil {
		m.probe = Probe
	}
	if m.now == nil {
		m.now = time.Now
	}
	if m.onChange == nil {
		m.onChange = func() {}
	}
	return m
}

// Sync makes the monitor probe exactly targets. Unchanged targets keep their
// state; changed ones (new container, address or spec) restart from "starting".
func (m *Monitor) Sync(targets []Target) {
	m.mu.Lock()
	defer m.mu.Unlock()
	want := make(map[state.WorkloadID]Target, len(targets))
	for _, t := range targets {
		want[t.Workload] = t
	}
	for id, r := range m.running {
		if t, ok := want[id]; !ok || t.key() != r.key {
			r.cancel()
			delete(m.running, id)
			delete(m.status, id)
		}
	}
	for id, t := range want {
		if _, ok := m.running[id]; ok || m.ctx.Err() != nil {
			continue
		}
		ctx, cancel := context.WithCancel(m.ctx)
		m.running[id] = &runner{key: t.key(), cancel: cancel}
		m.status[id] = Status{State: state.ProbeStarting}
		m.wg.Add(1)
		go m.loop(ctx, t)
	}
}

func (m *Monitor) loop(ctx context.Context, t Target) {
	defer m.wg.Done()
	interval := t.Spec.Interval.Std()
	if interval <= 0 {
		interval = 5 * time.Second
	}
	key := t.key()
	timer := time.NewTimer(0)
	defer timer.Stop()
	for {
		select {
		case <-ctx.Done():
			return
		case <-timer.C:
		}
		err := m.probe(ctx, t)
		if ctx.Err() != nil {
			return
		}
		m.mu.Lock()
		r, ok := m.running[t.Workload]
		if !ok || r.key != key {
			m.mu.Unlock()
			return
		}
		prev := m.status[t.Workload]
		next := Next(prev, err, m.now(), t.StartedAt, t.Spec)
		m.status[t.Workload] = next
		m.mu.Unlock()
		if next.State != prev.State {
			m.onChange()
		}
		timer.Reset(interval)
	}
}

// Status returns the probe state of a workload (ok=false if not probed).
func (m *Monitor) Status(id state.WorkloadID) (Status, bool) {
	m.mu.Lock()
	defer m.mu.Unlock()
	s, ok := m.status[id]
	return s, ok
}

// Close stops all probes and waits for them.
func (m *Monitor) Close() {
	m.cancel()
	m.wg.Wait()
}
