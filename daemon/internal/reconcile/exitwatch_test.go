package reconcile

import (
	"context"
	"errors"
	"path/filepath"
	"slices"
	"strings"
	"sync/atomic"
	"testing"
	"time"

	"github.com/vantezzen/launchroom/daemon/internal/docker"
	"github.com/vantezzen/launchroom/daemon/internal/docker/dockerfake"
	"github.com/vantezzen/launchroom/daemon/internal/health"
	"github.com/vantezzen/launchroom/daemon/internal/sealed"
	"github.com/vantezzen/launchroom/daemon/internal/secret"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// plainDocker hides the fake's optional interfaces (no exit watcher, no
// image configs, no volume helpers).
type plainDocker struct{ docker.Client }

// opsHarness is the reconciler harness with a goroutine-safe clock (the exit
// watchers read it), a probe the test controls and options to adjust.
func opsHarness(t *testing.T, adjust func(*Options)) (*harness, *clock, *atomic.Bool) {
	t.Helper()
	h := newHarness(t)
	clk := &clock{t: t0}
	h.d.Now = clk.now
	probeOK := &atomic.Bool{}
	probeOK.Store(true)
	mon := health.NewMonitor(t.Context(), health.MonitorOptions{Probe: func(context.Context, health.Target) error {
		if probeOK.Load() {
			return nil
		}
		return errors.New("timed out")
	}, Now: clk.now})
	t.Cleanup(mon.Close)
	o := Options{
		ServerID: srvID, Docker: h.d, Policy: h.r.o.Policy, Health: mon, Ports: noPorts{}, Ingress: h.ingress,
		EnvHashKey: h.r.o.EnvHashKey,
		OpenEnv: func(s *state.WorkloadSpec) (map[string]secret.Secret, error) {
			return sealed.OpenWorkloadEnv(s.Env, h.key, srvID, s.ID)
		},
		Now: clk.now,
	}
	if adjust != nil {
		adjust(&o)
	}
	h.r = New(o)
	t.Cleanup(h.r.StopWatchers)
	return h, clk, probeOK
}

func findEvents(r *Reconciler, kind state.WorkloadEventKind) []state.WorkloadEvent {
	var out []state.WorkloadEvent
	for _, ev := range r.PendingEvents() {
		if ev.Kind == kind {
			out = append(out, ev)
		}
	}
	return out
}

func texts(lines []state.LogLine) []string {
	out := make([]string, len(lines))
	for i, l := range lines {
		out[i] = l.Text
	}
	return out
}

// G1: a steady-state OOM is reported with its exit code, the OOM flag, the
// signal and the dead run's last lines, by the watcher during Docker's
// restart backoff; the pass after the restart adds the restart, not a second exit.
func TestExitWatcherReportsSteadyStateOOM(t *testing.T) {
	ctx := t.Context()
	h, clk, _ := opsHarness(t, nil)
	h.d.AddImage(string(imgA))
	if err := h.r.Reconcile(ctx, h.nodeState(t, 1, h.workload(t, wlA, nil))); err != nil {
		t.Fatal(err)
	}
	c := containerOf(t, h, wlA)
	eventually(t, func() bool { return h.d.Waiting(c.ID) > 0 })
	h.r.AckEvents(h.r.PendingEvents())

	clk.advance(20 * time.Minute)
	start := c.StartedAt
	h.d.Logs[c.ID] = []docker.LogLine{
		{Stream: "stdout", Time: start.Add(time.Second), Text: "listening on :3000"},
		{Stream: "stderr", Time: clk.now().Add(-time.Second), Text: "allocating 600 MB"},
		// The next run's banner (after the restart) must not be attached.
		{Stream: "stdout", Time: clk.now().Add(10 * time.Second), Text: "listening on :3000 (run 2)"},
	}
	h.d.Exit(c.ID, 137, true, true)
	eventually(t, func() bool { return len(findEvents(h.r, state.EventExited)) == 1 })
	ev := findEvents(h.r, state.EventExited)[0]
	if ev.ExitCode == nil || *ev.ExitCode != 137 || !ev.OOMKilled || ev.Signal != 9 || ev.ByLaunchroom || !ev.At.Equal(clk.now()) {
		t.Fatalf("exit %+v", ev)
	}
	if got := texts(ev.Output); !slices.Equal(got, []string{"listening on :3000", "allocating 600 MB"}) {
		t.Fatalf("output %q", got)
	}

	clk.advance(time.Second)
	h.d.Restarted(c.ID)
	if err := h.r.Reconcile(ctx, h.nodeState(t, 1, h.workload(t, wlA, nil))); err != nil {
		t.Fatal(err)
	}
	if n := len(findEvents(h.r, state.EventExited)); n != 1 {
		t.Fatalf("%d exit events", n)
	}
	if rs := findEvents(h.r, state.EventRestarted); len(rs) != 1 || rs[0].Restarts != 1 {
		t.Fatalf("restarted %+v", rs)
	}
	if ws := phaseOf(h.r, wlA); ws.ObservedAt.IsZero() || ws.Phase == state.PhaseFailed {
		t.Fatalf("status %+v", ws)
	}
}

// When Docker started the container again before the inspection, the code
// from the wait is kept, OOM stays unknown (never guessed from 137) and the
// new run's lines are cut at its start.
func TestExitSeenAfterRestart(t *testing.T) {
	ctx := t.Context()
	h, clk, _ := opsHarness(t, func(o *Options) { o.Docker = plainDocker{o.Docker} })
	h.d.AddImage(string(imgA))
	if err := h.r.Reconcile(ctx, h.nodeState(t, 1, h.workload(t, wlA, nil))); err != nil {
		t.Fatal(err)
	}
	c := containerOf(t, h, wlA)
	clk.advance(time.Hour)
	h.d.Exit(c.ID, 137, false, true)
	exitAt := clk.now()
	clk.advance(500 * time.Millisecond)
	h.d.Restarted(c.ID)
	h.d.Logs[c.ID] = []docker.LogLine{
		{Stream: "stderr", Time: exitAt.Add(-2 * time.Second), Text: "panic: deadlock"},
		{Stream: "stdout", Time: exitAt.Add(time.Second), Text: "starting again"},
	}
	h.r.exitSeen(ctx, wlA, c.ID, docker.ExitResult{Code: 137})
	evs := findEvents(h.r, state.EventExited)
	if len(evs) != 1 || *evs[0].ExitCode != 137 || evs[0].OOMKilled || evs[0].Signal != 9 || !evs[0].At.Equal(exitAt) {
		t.Fatalf("exits %+v", evs)
	}
	if got := texts(evs[0].Output); !slices.Equal(got, []string{"panic: deadlock"}) {
		t.Fatalf("output %q", got)
	}
	// The pass sees the same exit: no second event.
	if err := h.r.Reconcile(ctx, h.nodeState(t, 1, h.workload(t, wlA, nil))); err != nil {
		t.Fatal(err)
	}
	if n := len(findEvents(h.r, state.EventExited)); n != 1 {
		t.Fatalf("%d exits", n)
	}
}

// G8: a removed container's last lines travel with the removed event; the
// exit launchroomd caused is marked byLaunchroom and carries none.
func TestRemovalCarriesTailAndIsByLaunchroom(t *testing.T) {
	ctx := t.Context()
	h, clk, _ := opsHarness(t, nil)
	h.d.AddImage(string(imgA))
	if err := h.r.Reconcile(ctx, h.nodeState(t, 1, h.workload(t, wlA, nil))); err != nil {
		t.Fatal(err)
	}
	c := containerOf(t, h, wlA)
	eventually(t, func() bool { return h.d.Waiting(c.ID) > 0 })
	clk.advance(time.Minute)
	h.d.Logs[c.ID] = []docker.LogLine{{Stream: "stdout", Time: clk.now().Add(-time.Second), Text: "GET / 200"}}
	if err := h.r.Reconcile(ctx, h.nodeState(t, 2)); err != nil {
		t.Fatal(err)
	}
	rm := findEvents(h.r, state.EventRemoved)
	if len(rm) != 1 || !slices.Equal(texts(rm[0].Output), []string{"GET / 200"}) || rm[0].Reason != state.ReasonNotDesired {
		t.Fatalf("removed %+v", rm)
	}
	for _, ev := range findEvents(h.r, state.EventExited) {
		if !ev.ByLaunchroom || len(ev.Output) > 0 {
			t.Fatalf("exit %+v", ev)
		}
	}
}

// G5: pull, create and start failures carry typed codes.
func TestTypedFailures(t *testing.T) {
	ctx := t.Context()
	h, _, _ := opsHarness(t, func(o *Options) { o.PullWait = time.Second })
	h.d.PullErr[string(imgA)] = errors.New("docker: pull x: toomanyrequests: You have reached your pull rate limit")
	w := h.workload(t, wlA, nil)
	_ = h.r.Reconcile(ctx, h.nodeState(t, 1, w))
	if ws := phaseOf(h.r, wlA); ws.Phase != state.PhaseFailed || ws.Failure == nil || ws.Failure.Code != state.FailureRateLimited || ws.Failure.At.IsZero() {
		t.Fatalf("pull: %+v", ws)
	}

	delete(h.d.PullErr, string(imgA))
	h.d.StartErr["lr-"+strings.ToLower(string(wlA))] = errors.New(`OCI runtime create failed: runc create failed: unable to start container process: exec: "nope": executable file not found in $PATH: unknown`)
	_ = h.r.Reconcile(ctx, h.nodeState(t, 1, w))
	ws := phaseOf(h.r, wlA)
	if ws.Failure == nil || ws.Failure.Code != state.FailureCommandNotFound {
		t.Fatalf("start: %+v", ws)
	}
	cf := findEvents(h.r, state.EventCreateFailed)
	if len(cf) == 0 || cf[len(cf)-1].Code != state.FailureCommandNotFound {
		t.Fatalf("events %+v", cf)
	}
}

// G5/G74: an image for another architecture fails before the create, with
// wrong_platform; image facts (user, exposed ports) are reported.
func TestWrongPlatformAndImageFacts(t *testing.T) {
	ctx := t.Context()
	h, _, _ := opsHarness(t, nil)
	h.d.AddImage(string(imgA))
	h.d.Arch = "arm64"
	h.d.Images[string(imgA)] = docker.ImageConfig{User: "1000:1000", ExposedPorts: []int{8000}, OS: "linux", Architecture: "amd64"}
	_ = h.r.Reconcile(ctx, h.nodeState(t, 1, h.workload(t, wlA, nil)))
	ws := phaseOf(h.r, wlA)
	if ws.Failure == nil || ws.Failure.Code != state.FailureWrongPlatform || !strings.Contains(ws.Failure.Message, "linux/amd64; this server runs linux/arm64") {
		t.Fatalf("status %+v", ws)
	}
	if ws.Image == nil || ws.Image.User != "1000:1000" || !slices.Equal(ws.Image.ExposedPorts, []state.Port{8000}) {
		t.Fatalf("image %+v", ws.Image)
	}
	if _, ok := h.d.Create("lr-" + strings.ToLower(string(wlA))); ok {
		t.Fatal("created a container for the wrong platform")
	}

	// A native image is created.
	h2, _, _ := opsHarness(t, nil)
	h2.d.AddImage(string(imgA))
	h2.d.Images[string(imgA)] = docker.ImageConfig{Architecture: "arm64"}
	h2.d.Arch = "arm64"
	if err := h2.r.Reconcile(ctx, h2.nodeState(t, 1, h2.workload(t, wlA, nil))); err != nil {
		t.Fatal(err)
	}
	if ws := phaseOf(h2.r, wlA); ws.Failure != nil || ws.Image == nil || ws.Image.User != "" {
		t.Fatalf("native: %+v", ws)
	}
}

// failingDocker fails listing networks while fail is set (Docker down).
type failingDocker struct {
	*dockerfake.Fake
	fail atomic.Bool
}

func (f *failingDocker) ListNetworks(ctx context.Context) ([]docker.Network, error) {
	if f.fail.Load() {
		return nil, errors.New("Cannot connect to the Docker daemon at unix:///var/run/docker.sock. Is the docker daemon running?")
	}
	return f.Fake.ListNetworks(ctx)
}

// G3: while Docker can't be observed, statuses keep their last observation
// time and the pass health says why.
func TestObserveFailureKeepsObservedAt(t *testing.T) {
	ctx := t.Context()
	var fd *failingDocker
	h, clk, _ := opsHarness(t, func(o *Options) {
		fd = &failingDocker{Fake: o.Docker.(*dockerfake.Fake)}
		o.Docker = fd
	})
	h.d.AddImage(string(imgA))
	ns := h.nodeState(t, 1, h.workload(t, wlA, nil))
	if err := h.r.Reconcile(ctx, ns); err != nil {
		t.Fatal(err)
	}
	seen := phaseOf(h.r, wlA).ObservedAt
	if d := h.r.DockerReach(); d == nil || !d.Reachable {
		t.Fatalf("reach %+v", d)
	}
	if st := h.r.ReconcileStatus(); st == nil || st.LastCompleteAt == nil || len(st.Errors) != 0 {
		t.Fatalf("status %+v", st)
	}

	clk.advance(time.Minute)
	fd.fail.Store(true)
	if err := h.r.Reconcile(ctx, ns); err == nil {
		t.Fatal("no error")
	}
	if got := phaseOf(h.r, wlA).ObservedAt; !got.Equal(seen) {
		t.Fatalf("observedAt %s, want %s", got, seen)
	}
	d := h.r.DockerReach()
	if d == nil || d.Reachable || !strings.Contains(d.Error, "Cannot connect") || !d.CheckedAt.Equal(clk.now()) {
		t.Fatalf("reach %+v", d)
	}
	st := h.r.ReconcileStatus()
	if st == nil || len(st.Errors) != 1 || st.Errors[0].Code != state.ReconcileDockerUnreachable || !st.LastPassAt.Equal(clk.now()) || !st.LastCompleteAt.Equal(seen) {
		t.Fatalf("status %+v", st)
	}
}

// G4: marks persist, so an exit and a restart while launchroomd was down
// are reported once after it starts again.
func TestPersistedMarksReportExitsWhileDown(t *testing.T) {
	ctx := t.Context()
	file := filepath.Join(t.TempDir(), "lifecycle.json")
	h, clk, _ := opsHarness(t, func(o *Options) { o.MarksFile = file; o.Docker = plainDocker{o.Docker} })
	h.d.AddImage(string(imgA))
	ns := h.nodeState(t, 1, h.workload(t, wlA, nil))
	if err := h.r.Reconcile(ctx, ns); err != nil {
		t.Fatal(err)
	}
	c := containerOf(t, h, wlA)

	// launchroomd is down: the app crashes and Docker restarts it.
	clk.advance(10 * time.Minute)
	h.d.Logs[c.ID] = []docker.LogLine{{Stream: "stderr", Time: clk.now().Add(-time.Second), Text: "fatal: lost the database"}}
	h.d.Exit(c.ID, 1, false, true)
	clk.advance(time.Second)
	h.d.Restarted(c.ID)
	clk.advance(time.Minute)

	again := New(Options{ServerID: srvID, Docker: plainDocker{h.d}, Policy: h.r.o.Policy, Ports: noPorts{}, EnvHashKey: h.r.o.EnvHashKey,
		OpenEnv: h.r.o.OpenEnv, Now: clk.now, MarksFile: file})
	if err := again.Reconcile(ctx, ns); err != nil {
		t.Fatal(err)
	}
	exits := findEvents(again, state.EventExited)
	// Docker reset the code once it restarted it: the pass can't know it.
	if len(exits) != 1 || exits[0].ExitCode != nil || !slices.Equal(texts(exits[0].Output), []string{"fatal: lost the database"}) {
		t.Fatalf("exits %+v", exits)
	}
	if rs := findEvents(again, state.EventRestarted); len(rs) != 1 {
		t.Fatalf("restarts %+v", rs)
	}
	// Without the file the same situation is skipped (before launchroomd started).
	third := New(Options{ServerID: srvID, Docker: plainDocker{h.d}, Policy: h.r.o.Policy, Ports: noPorts{}, EnvHashKey: h.r.o.EnvHashKey,
		OpenEnv: h.r.o.OpenEnv, Now: clk.now})
	if err := third.Reconcile(ctx, ns); err != nil {
		t.Fatal(err)
	}
	if n := len(findEvents(third, state.EventExited)); n != 0 {
		t.Fatalf("%d exits without marks", n)
	}
}

// G24 (OD2): a serving workload whose check passed and then failed for
// restartAfter is restarted (event restarted, reason health, with the hung
// run's lines), at most 3 times an hour, then reported unresponsive.
func TestLivenessRestart(t *testing.T) {
	ctx := t.Context()
	h, clk, probeOK := opsHarness(t, func(o *Options) { o.Docker = plainDocker{o.Docker} })
	h.d.AddImage(string(imgA))
	w := h.workload(t, wlA, nil)
	w.Health.RestartAfter = "1m"
	w.Health.FailureThreshold = 1
	ns := h.nodeState(t, 1, w)
	if err := h.r.Reconcile(ctx, ns); err != nil {
		t.Fatal(err)
	}
	eventually(t, func() bool { return phaseOf(h.r, wlA).Phase == state.PhaseHealthy })

	restarts := 0
	for round := range 4 {
		probeOK.Store(false)
		eventually(t, func() bool { return phaseOf(h.r, wlA).Phase == state.PhaseUnhealthy })
		// Not yet: failing for less than restartAfter.
		clk.advance(30 * time.Second)
		_ = h.r.Reconcile(ctx, ns)
		if n := len(findEvents(h.r, state.EventRestarted)); n != restarts {
			t.Fatalf("round %d: restarted early (%d)", round, n)
		}
		clk.advance(time.Minute)
		c := containerOf(t, h, wlA)
		h.d.Logs[c.ID] = []docker.LogLine{{Stream: "stdout", Time: clk.now().Add(-time.Second), Text: "waiting for lock"}}
		_ = h.r.Reconcile(ctx, ns)
		got := findEvents(h.r, state.EventRestarted)
		if round < 3 {
			restarts++
			if len(got) != restarts {
				t.Fatalf("round %d: %d restarts", round, len(got))
			}
			ev := got[len(got)-1]
			if ev.Reason != state.ReasonHealth || !strings.HasPrefix(ev.Message, "health check failing for 1m") || !slices.Equal(texts(ev.Output), []string{"waiting for lock"}) {
				t.Fatalf("event %+v", ev)
			}
			if starts := findEvents(h.r, state.EventStarted); len(starts) != 1 {
				t.Fatalf("the restart was also reported as a start: %+v", starts)
			}
			// It comes back healthy, then hangs again.
			clk.advance(time.Second)
			_ = h.r.Reconcile(ctx, ns)
			probeOK.Store(true)
			eventually(t, func() bool { return phaseOf(h.r, wlA).Phase == state.PhaseHealthy })
			continue
		}
		if len(got) != 3 {
			t.Fatalf("a fourth restart within the hour: %d", len(got))
		}
		if is := phaseOf(h.r, wlA).Issue; is == nil || is.Kind != state.IssueUnresponsive || is.Restarts != 3 {
			t.Fatalf("issue %+v", is)
		}
	}
	// Passing again clears it.
	probeOK.Store(true)
	eventually(t, func() bool { return phaseOf(h.r, wlA).Phase == state.PhaseHealthy })
	_ = h.r.Reconcile(ctx, ns)
	if is := phaseOf(h.r, wlA).Issue; is != nil {
		t.Fatalf("issue %+v", is)
	}
}

// G76: a new, empty volume is given to the image's user once; a volume that
// has files is never changed.
func TestInitOwner(t *testing.T) {
	ctx := t.Context()
	h, _, _ := opsHarness(t, nil)
	h.d.AddImage(string(imgA))
	ns := h.nodeState(t, 1, h.workload(t, wlA, nil))
	ns.Workloads[0].Mounts = []state.Mount{
		{Volume: "vol_01J9Z8X7W6V5T4S3R2Q1P0N9V1", Target: "/data", InitOwner: &state.FileOwner{UID: 1000, GID: 1000}},
		{Volume: "vol_01J9Z8X7W6V5T4S3R2Q1P0N9V2", Target: "/uploads", InitOwner: &state.FileOwner{UID: 1000, GID: 1000}},
	}
	ns.Volumes = []state.VolumeSpec{{ID: "vol_01J9Z8X7W6V5T4S3R2Q1P0N9V1", Name: "lr-v_fresh"}, {ID: "vol_01J9Z8X7W6V5T4S3R2Q1P0N9V2", Name: "lr-v_used"}}
	if err := ns.Validate(); err != nil {
		t.Fatal(err)
	}
	h.d.SetVolumeRoot("lr-v_used", dockerfake.VolumeRoot{Mode: 0o755, Files: []string{"a.jpg"}})
	if err := h.r.Reconcile(ctx, ns); err != nil {
		t.Fatal(err)
	}
	if root := h.d.VolumeRootOf("lr-v_fresh"); root.UID != 1000 || root.GID != 1000 || root.Mode != 0o755 {
		t.Fatalf("fresh %+v", root)
	}
	if root := h.d.VolumeRootOf("lr-v_used"); root.UID != 0 {
		t.Fatalf("used %+v", root)
	}
	if ws := phaseOf(h.r, wlA); ws.Phase == state.PhaseFailed {
		t.Fatalf("status %+v", ws)
	}
	// The helpers are gone.
	cs, _ := h.d.ListContainers(ctx, nil)
	for _, c := range cs {
		if strings.HasPrefix(c.Name, "lr-vol-") {
			t.Fatalf("helper left: %s", c.Name)
		}
	}
}

// A report carries at most 1 MiB of event output; the rest waits.
func TestPendingEventsByteBudget(t *testing.T) {
	h, _, _ := opsHarness(t, nil)
	line := strings.Repeat("x", 2000)
	out := make([]state.LogLine, 8)
	for i := range out {
		out[i] = state.LogLine{Time: t0, Stream: state.StreamStdout, Text: line}
	}
	for range 100 {
		h.r.emit(state.WorkloadEvent{Workload: wlA, Kind: state.EventExited, Output: out})
	}
	first := h.r.PendingEvents()
	if len(first) != 65 { // 65 × 16 000 bytes ≤ 1 MiB < 66 × 16 000
		t.Fatalf("%d events", len(first))
	}
	h.r.AckEvents(first)
	if n := len(h.r.PendingEvents()); n != 35 {
		t.Fatalf("%d left", n)
	}
}

// The pass error carries its typed problems (hostinfo.ReconcileErrorer), and
// the edge learns which replicas run (fail open).
func TestPassErrorIsTypedAndEndpointsRun(t *testing.T) {
	ctx := t.Context()
	var fd *failingDocker
	h, _, _ := opsHarness(t, func(o *Options) {
		fd = &failingDocker{Fake: o.Docker.(*dockerfake.Fake)}
		o.Docker = fd
	})
	h.d.AddImage(string(imgA))
	ns := h.nodeState(t, 1, h.workload(t, wlA, nil))
	if err := h.r.Reconcile(ctx, ns); err != nil {
		t.Fatal(err)
	}
	if ep, ok := h.r.resolve(wlA); !ok || !ep.Running {
		t.Fatalf("endpoint %+v", ep)
	}
	fd.fail.Store(true)
	err := h.r.Reconcile(ctx, ns)
	var typed interface{ ReconcileErrors() []state.ReconcileError }
	if !errors.As(err, &typed) || len(typed.ReconcileErrors()) != 1 || typed.ReconcileErrors()[0].Code != state.ReconcileDockerUnreachable {
		t.Fatalf("err %#v", err)
	}
	// A restarting replica (crashed, Docker about to start it) doesn't run.
	c := containerOf(t, h, wlA)
	h.d.Exit(c.ID, 1, false, true)
	fd.fail.Store(false)
	_ = h.r.Reconcile(ctx, ns)
	if ep, ok := h.r.resolve(wlA); ok && ep.Running {
		t.Fatalf("a restarting replica runs: %+v", ep)
	}
}
