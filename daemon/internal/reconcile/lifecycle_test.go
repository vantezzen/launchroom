package reconcile

import (
	"context"
	"errors"
	"slices"
	"strings"
	"sync"
	"testing"
	"time"

	"github.com/vantezzen/launchroom/daemon/internal/docker"
	"github.com/vantezzen/launchroom/daemon/internal/health"
	"github.com/vantezzen/launchroom/daemon/internal/sealed"
	"github.com/vantezzen/launchroom/daemon/internal/secret"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// clock is a goroutine-safe test clock (the probe goroutines read it).
type clock struct {
	mu sync.Mutex
	t  time.Time
}

func (c *clock) now() time.Time {
	c.mu.Lock()
	defer c.mu.Unlock()
	return c.t
}

func (c *clock) advance(d time.Duration) {
	c.mu.Lock()
	defer c.mu.Unlock()
	c.t = c.t.Add(d)
}

// scanner returns fixed listeners (port issue tests).
type scanner struct {
	mu sync.Mutex
	ls []health.Listener
}

func (s *scanner) Listeners(int) ([]health.Listener, error) {
	s.mu.Lock()
	defer s.mu.Unlock()
	return slices.Clone(s.ls), nil
}

func (s *scanner) set(ls ...health.Listener) {
	s.mu.Lock()
	defer s.mu.Unlock()
	s.ls = ls
}

// lifecycleHarness is the reconciler harness with a moving clock, a failing
// probe and controllable listeners.
func lifecycleHarness(t *testing.T) (*harness, *clock, *scanner) {
	t.Helper()
	h := newHarness(t)
	clk := &clock{t: t0}
	ports := &scanner{ls: []health.Listener{{Port: 3000}}}
	h.d.Now = clk.now
	mon := health.NewMonitor(t.Context(), health.MonitorOptions{Probe: func(context.Context, health.Target) error {
		return errors.New("connection refused")
	}, Now: clk.now})
	t.Cleanup(mon.Close)
	h.r = New(Options{
		ServerID: srvID, Docker: h.d, Policy: h.r.o.Policy, Health: mon, Ports: ports, Ingress: h.ingress,
		EnvHashKey: h.r.o.EnvHashKey,
		OpenEnv: func(s *state.WorkloadSpec) (map[string]secret.Secret, error) {
			return sealed.OpenWorkloadEnv(s.Env, h.key, srvID, s.ID)
		},
		Now: clk.now,
	})
	return h, clk, ports
}

func eventKinds(evs []state.WorkloadEvent) []state.WorkloadEventKind {
	out := make([]state.WorkloadEventKind, len(evs))
	for i, ev := range evs {
		out[i] = ev.Kind
	}
	return out
}

func containerOf(t *testing.T, h *harness, id state.WorkloadID) docker.Container {
	t.Helper()
	cs, err := h.d.ListContainers(t.Context(), map[string]string{docker.LabelWorkload: string(id)})
	if err != nil || len(cs) != 1 {
		t.Fatalf("containers of %s: %v %v", id, cs, err)
	}
	return cs[0]
}

// A container that crashes at start: created and started, then exits with
// its output attached, Docker restarts it, and after three restarts the
// workload carries a crash_loop issue (reported by Watch within seconds).
func TestLifecycleCrashLoop(t *testing.T) {
	ctx := t.Context()
	h, clk, _ := lifecycleHarness(t)
	w := h.workload(t, wlA, nil)
	if err := h.r.Reconcile(ctx, h.nodeState(t, 1, w)); err != nil {
		t.Fatal(err)
	}
	if got := eventKinds(h.r.PendingEvents()); !slices.Equal(got, []state.WorkloadEventKind{state.EventCreated, state.EventStarted}) {
		t.Fatalf("events %v", got)
	}
	evs := h.r.PendingEvents()
	if evs[0].Deployment != w.Deployment || evs[0].Workload != wlA || evs[0].Seq >= evs[1].Seq {
		t.Fatalf("%+v", evs)
	}
	h.r.AckEvents(evs)
	if len(h.r.PendingEvents()) != 0 {
		t.Fatal("acked events still pending")
	}
	if got := h.r.Starting(); !slices.Equal(got, []state.WorkloadID{wlA}) {
		t.Fatalf("starting %v", got)
	}

	c := containerOf(t, h, wlA)
	h.d.Logs = map[string][]docker.LogLine{c.ID: {
		{Stream: "stdout", Time: clk.now(), Text: "INFO: Started server process [7]"},
		{Stream: "stderr", Time: clk.now(), Text: "RuntimeError: FIXTURE_MODE=crash: the app fails while importing"},
	}}
	for restart := 1; restart <= 3; restart++ {
		clk.advance(time.Second)
		finished := clk.now()
		clk.advance(time.Second)
		h.d.Update(c.ID, func(c *docker.Container) {
			c.State, c.Running, c.Restarting, c.ExitCode = "running", true, false, 1
			c.FinishedAt, c.StartedAt, c.RestartCount = finished, clk.now(), restart
		})
		if !h.r.Watch(ctx) {
			t.Fatalf("restart %d not noticed", restart)
		}
	}
	evs = h.r.PendingEvents()
	want := []state.WorkloadEventKind{state.EventRestarted, state.EventExited, state.EventRestarted, state.EventExited, state.EventRestarted, state.EventExited}
	if !slices.Equal(eventKinds(evs), want) {
		t.Fatalf("events %v", eventKinds(evs))
	}
	exit := evs[1]
	if exit.ExitCode != nil || len(exit.Output) != 2 || !strings.Contains(exit.Output[1].Text, "RuntimeError") || exit.Output[1].Stream != state.StreamStderr {
		t.Fatalf("exit %+v", exit)
	}
	if evs[4].Restarts != 3 || exit.At.After(evs[0].At) {
		t.Fatalf("restart counts or times: %+v", evs)
	}
	st := phaseOf(h.r, wlA)
	if st.Issue == nil || st.Issue.Kind != state.IssueCrashLoop || st.Issue.Restarts != 3 {
		t.Fatalf("issue %+v", st.Issue)
	}
	// Seen while Docker waits to restart it: the exit code is known.
	h.d.Update(c.ID, func(c *docker.Container) { c.State, c.Restarting, c.ExitCode = "restarting", true, 1 })
	h.r.Watch(ctx)
	if st := phaseOf(h.r, wlA); st.Issue == nil || st.Issue.ExitCode == nil || *st.Issue.ExitCode != 1 {
		t.Fatalf("issue while restarting %+v", st.Issue)
	}
	h.d.Update(c.ID, func(c *docker.Container) { c.State, c.Restarting, c.ExitCode = "running", false, 0 })

	// Running steadily for a minute after the restarts: no longer a loop.
	clk.advance(2 * time.Minute)
	h.r.Watch(ctx)
	if st := phaseOf(h.r, wlA); st.Issue != nil {
		t.Fatalf("issue after a stable run: %+v", st.Issue)
	}
}

// Port issues: the app listening on 127.0.0.1 only, or on another port, is
// reported once it held for IssueSettle while the health check doesn't pass;
// listening nowhere yet is a slow start, not an issue.
func TestLifecyclePortIssues(t *testing.T) {
	cases := map[string]struct {
		listeners []health.Listener
		want      state.WorkloadIssueKind
	}{
		"loopback only":  {[]health.Listener{{Port: 3000, LoopbackOnly: true}}, state.IssueLoopbackOnly},
		"wrong port":     {[]health.Listener{{Port: 8000}}, state.IssueWrongPort},
		"slow start":     {[]health.Listener{}, ""},
		"loopback other": {[]health.Listener{{Port: 9000, LoopbackOnly: true}}, ""},
	}
	for name, tc := range cases {
		t.Run(name, func(t *testing.T) {
			ctx := t.Context()
			h, clk, ports := lifecycleHarness(t)
			ports.set(tc.listeners...)
			w := h.workload(t, wlA, nil)
			ns := h.nodeState(t, 1, w)
			if err := h.r.Reconcile(ctx, ns); err != nil {
				t.Fatal(err)
			}
			if st := phaseOf(h.r, wlA); st.Issue != nil {
				t.Fatalf("issue before it settled: %+v", st.Issue)
			}
			clk.advance(state.IssueSettle + time.Second)
			h.r.Watch(ctx)
			st := phaseOf(h.r, wlA)
			if tc.want == "" {
				if st.Issue != nil {
					t.Fatalf("unexpected issue %+v", st.Issue)
				}
				return
			}
			if st.Issue == nil || st.Issue.Kind != tc.want || st.Issue.Port == nil || *st.Issue.Port != 3000 || len(st.Issue.Listening) != 1 {
				t.Fatalf("issue %+v", st.Issue)
			}
			// Fixed (listens on 0.0.0.0:3000): the issue goes away.
			ports.set(health.Listener{Port: 3000})
			h.r.Watch(ctx)
			if st := phaseOf(h.r, wlA); st.Issue != nil {
				t.Fatalf("issue after the fix: %+v", st.Issue)
			}
		})
	}
}

// Stops and removals by launchroomd carry their reason.
func TestLifecycleStopReasons(t *testing.T) {
	ctx := t.Context()
	h, clk, _ := lifecycleHarness(t)
	w := h.workload(t, wlA, nil)
	if err := h.r.Reconcile(ctx, h.nodeState(t, 1, w)); err != nil {
		t.Fatal(err)
	}
	h.r.AckEvents(h.r.PendingEvents())

	until := clk.now()
	drain := w
	drain.Role, drain.DrainUntil = state.RoleDraining, &until
	clk.advance(time.Second)
	if err := h.r.Reconcile(ctx, h.nodeState(t, 2, drain)); err != nil {
		t.Fatal(err)
	}
	evs := h.r.PendingEvents()
	if len(evs) < 2 || evs[0].Kind != state.EventStopped || evs[0].Reason != state.ReasonDrained || evs[1].Kind != state.EventExited {
		t.Fatalf("drain events %+v", evs)
	}
	h.r.AckEvents(evs)

	empty := h.nodeState(t, 3)
	if err := h.r.Reconcile(ctx, empty); err != nil {
		t.Fatal(err)
	}
	evs = h.r.PendingEvents()
	if len(evs) != 1 || evs[0].Kind != state.EventRemoved || evs[0].Reason != state.ReasonNotDesired || evs[0].Deployment != w.Deployment {
		t.Fatalf("removal events %+v", evs)
	}
}

// Containers that were running before launchroomd started are not reported
// as new starts.
func TestLifecycleIgnoresOlderContainers(t *testing.T) {
	ctx := t.Context()
	h, clk, _ := lifecycleHarness(t)
	w := h.workload(t, wlA, nil)
	if err := h.r.Reconcile(ctx, h.nodeState(t, 1, w)); err != nil {
		t.Fatal(err)
	}
	clk.advance(time.Hour)
	// A new reconciler (launchroomd restarted) sees the running container.
	h2 := New(h.r.o)
	if err := h2.Reconcile(ctx, h.nodeState(t, 1, w)); err != nil {
		t.Fatal(err)
	}
	for _, ev := range h2.PendingEvents() {
		if ev.Kind == state.EventStarted || ev.Kind == state.EventCreated || ev.Kind == state.EventExited {
			t.Fatalf("events after a restart: %v", eventKinds(h2.PendingEvents()))
		}
	}
}

// A stuck image pull doesn't hold back the rest of the desired state (D68):
// the pass waits PullWait for it, creates the workloads whose image is
// there and reports the waiting one as pulling; once the pull ends, the
// next pass creates it.
func TestStuckPullDoesNotBlockThePass(t *testing.T) {
	ctx := t.Context()
	h := newHarness(t)
	h.r.o.PullWait = 50 * time.Millisecond
	pulled := make(chan struct{})
	h.r.o.OnPullDone = func() { close(pulled) }
	release := make(chan struct{})
	h.d.OnPull = func(ctx context.Context, _ string) {
		select {
		case <-release:
		case <-ctx.Done():
		}
	}
	imgB := state.ImageRef("127.0.0.1:5000/apps/b@sha256:bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb")
	h.d.AddImage(string(imgB))
	a := h.workload(t, wlA, nil)
	b := h.workload(t, wlB, nil)
	b.Image = imgB
	ns := h.nodeState(t, 1, a, b)

	start := time.Now()
	if err := h.r.Reconcile(ctx, ns); err != nil {
		t.Fatal(err)
	}
	if time.Since(start) > 3*time.Second {
		t.Fatalf("the pass waited %s for the stuck pull", time.Since(start))
	}
	if !slices.Contains(h.d.Calls(), "create lr-"+strings.ToLower(string(wlB))) {
		t.Fatalf("the other workload wasn't created: %q", h.d.Calls())
	}
	if st := phaseOf(h.r, wlA); st.Phase != state.PhasePulling {
		t.Fatalf("waiting workload: %+v", st)
	}

	close(release)
	select {
	case <-pulled:
	case <-time.After(5 * time.Second):
		t.Fatal("the background pull never ended")
	}
	if err := h.r.Reconcile(ctx, ns); err != nil {
		t.Fatal(err)
	}
	if !slices.Contains(h.d.Calls(), "create lr-"+strings.ToLower(string(wlA))) {
		t.Fatalf("not created after the pull: %q", h.d.Calls())
	}
}
