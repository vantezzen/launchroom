//go:build integration

package integration

import (
	"context"
	"crypto/rand"
	"crypto/tls"
	"log/slog"
	"net"
	"net/http"
	"os"
	"slices"
	"strings"
	"testing"
	"time"

	"github.com/vantezzen/launchroom/daemon/internal/docker"
	"github.com/vantezzen/launchroom/daemon/internal/health"
	"github.com/vantezzen/launchroom/daemon/internal/policy"
	"github.com/vantezzen/launchroom/daemon/internal/reconcile"
	"github.com/vantezzen/launchroom/daemon/internal/sealed"
	"github.com/vantezzen/launchroom/daemon/internal/secret"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// crashShell runs scripted test apps inside the test container (a crash,
// a slow start; a constant, so TestNoShellInvocations sees no host shell call).
const crashShell = "sh"

// Container lifecycle and rollout failure cases against a real Docker
// (D68, docs/04 §8.1): each ends with a clear, typed signal within seconds
// instead of a rollout that waits for its deadline.

const lifecycleHost = "lifecycle.localhost"

// lifecycleEnv is the integration env with a reconciler that runs busybox
// workloads, probes them and reads their sockets, and an engine whose
// pulls of images under the local registry warm up with warm.
func lifecycleEnv(t *testing.T, warm docker.MirrorWarm) *env {
	t.Helper()
	e := setup(t)
	if warm != nil {
		var err error
		e.engine, err = docker.NewEngine(docker.EngineOptions{Host: engineHost, ServerID: string(e.serverID), UserAgent: "launchroom-itest", Warm: warm, BuiltWarmTimeout: 2 * time.Second})
		if err != nil {
			t.Fatal(err)
		}
	}
	ctx, cancel := context.WithCancel(context.Background())
	t.Cleanup(cancel)
	pol := policy.DefaultConfig()
	pol.AllowedRegistries = []string{policy.DefaultRegistry, "docker.io/library/"}
	mon := health.NewMonitor(ctx, health.MonitorOptions{})
	t.Cleanup(mon.Close)
	e.rec = reconcile.New(reconcile.Options{
		ServerID: e.serverID, Docker: e.engine, Policy: pol, Health: mon, Ingress: e.ingress,
		EnvHashKey: reconcile.EnvHashKey(e.key.Bytes()),
		OpenEnv: func(s *state.WorkloadSpec) (map[string]secret.Secret, error) {
			return sealed.OpenWorkloadEnv(s.Env, e.key, e.serverID, s.ID)
		},
		StopGrace: 2 * time.Second, PullWait: 2 * time.Second,
		Log: slog.New(slog.NewTextHandler(os.Stderr, &slog.HandlerOptions{Level: slog.LevelInfo})),
	})
	return e
}

// busyboxWorkload is a workload of the pinned busybox image running argv,
// probed over HTTP on port 8080 when probed is set.
func (e *env) busyboxWorkload(t *testing.T, id state.WorkloadID, image state.ImageRef, argv []string, probed bool) state.WorkloadSpec {
	t.Helper()
	sealedEnv, err := sealed.SealWorkloadEnv(state.WorkloadEnv{ServerID: e.serverID, WorkloadID: id, Env: map[string]secret.Secret{}}, e.key.PublicKey(), rand.Reader)
	if err != nil {
		t.Fatal(err)
	}
	w := state.WorkloadSpec{
		ID: id, Service: "svc_01J9Z8X7W6V5T4S3R2Q1P0KC02", Environment: "env_01J9Z8X7W6V5T4S3R2Q1P0KC03", Deployment: state.DeploymentID(randomID("dpl")),
		Image: image, Command: argv, Env: sealedEnv,
		Resources: state.Resources{CPUs: 0.5, MemoryMB: 64, Pids: 64},
		Networks:  []state.NetworkAttachment{{Network: "net_01J9Z8X7W6V5T4S3R2Q1P0KC04", Aliases: []state.Slug{"app"}}},
		Restart:   state.RestartUnlessStopped, Role: state.RoleServing,
	}
	if probed {
		port := state.Port(8080)
		w.Port = &port
		w.Health = &state.HealthSpec{Type: state.HealthHTTP, Path: "/", ExpectStatus: "2xx-4xx", StartPeriod: "60s", Interval: "1s", Timeout: "1s", FailureThreshold: 3}
	}
	return w
}

// lifecycleState is a NodeState with workloads and, when routed is set, a
// route to the first one (stopped: a stopped route without upstreams).
func (e *env) lifecycleState(t *testing.T, version int64, route string, workloads ...state.WorkloadSpec) *state.NodeState {
	t.Helper()
	ns := &state.NodeState{
		TypeMeta: state.NewTypeMeta(state.KindNodeState), ServerID: e.serverID, Version: version, IssuedAt: time.Now().UTC(),
		System:      state.SystemSpec{Caddy: state.SystemContainer{Enabled: route != "", Image: caddyImage}},
		Networks:    []state.NetworkSpec{{ID: "net_01J9Z8X7W6V5T4S3R2Q1P0KC04", Name: "lr-e_lifecycle", Subnet: "10.231.249.0/24"}},
		Volumes:     []state.VolumeSpec{},
		Workloads:   workloads,
		Ambassadors: []state.AmbassadorSpec{},
		Routes:      []state.RouteSpec{},
		Firewall:    state.FirewallSpec{MeshAllow: []state.MeshAllowRule{}},
	}
	switch route {
	case "routed":
		ns.Routes = []state.RouteSpec{{ID: "rt_01J9Z8X7W6V5T4S3R2Q1P0KC05", Hosts: []state.Hostname{lifecycleHost}, TLS: state.TLSInternal,
			Upstreams: []state.RouteUpstream{{Workload: workloads[0].ID, Port: 8080}}}}
	case "stopped":
		ns.Routes = []state.RouteSpec{{ID: "rt_01J9Z8X7W6V5T4S3R2Q1P0KC05", Hosts: []state.Hostname{lifecycleHost}, TLS: state.TLSInternal, Stopped: true}}
	}
	if err := ns.Validate(); err != nil {
		t.Fatal(err)
	}
	return ns
}

// watchUntil reconciles once, then watches (as launchroomd does every two
// seconds) until done or the deadline.
func watchUntil(t *testing.T, e *env, ns *state.NodeState, within time.Duration, done func() bool) {
	t.Helper()
	ctx, cancel := context.WithTimeout(context.Background(), within+time.Minute)
	defer cancel()
	deadline := time.Now().Add(within)
	last := time.Time{}
	for !done() {
		if time.Now().After(deadline) {
			_, ws, _ := e.rec.Snapshot()
			t.Fatalf("not within %s: workloads=%+v events=%+v", within, ws, e.rec.PendingEvents())
		}
		if time.Since(last) > 10*time.Second {
			if err := e.rec.Reconcile(ctx, ns); err != nil {
				t.Logf("reconcile: %v", err)
			}
			last = time.Now()
		}
		e.rec.Watch(ctx)
		time.Sleep(500 * time.Millisecond)
	}
	// One more pass, as launchroomd does on a probe change, so the ingress
	// routes what satisfied done().
	if err := e.rec.Reconcile(ctx, ns); err != nil {
		t.Logf("reconcile: %v", err)
	}
}

func events(e *env, id state.WorkloadID, kind state.WorkloadEventKind) []state.WorkloadEvent {
	var out []state.WorkloadEvent
	for _, ev := range e.rec.PendingEvents() {
		if ev.Workload == id && ev.Kind == kind {
			out = append(out, ev)
		}
	}
	return out
}

// TestRolloutFailsFastOnLoopbackOnly: an app listening on 127.0.0.1 only
// (uvicorn's default --host) carries a loopback_only issue once it held for
// IssueSettle, while its health check never passes.
func TestRolloutFailsFastOnLoopbackOnly(t *testing.T) {
	e := lifecycleEnv(t, nil)
	wl := state.WorkloadID(randomID("wl"))
	ns := e.lifecycleState(t, 1, "", e.busyboxWorkload(t, wl, busyboxImage, []string{"httpd", "-f", "-p", "127.0.0.1:8080", "-h", "/"}, true))
	start := time.Now()
	watchUntil(t, e, ns, 90*time.Second, func() bool { return status(e.rec, wl).Issue != nil })
	st := status(e.rec, wl)
	if st.Issue.Kind != state.IssueLoopbackOnly || st.Issue.Port == nil || *st.Issue.Port != 8080 || !slices.Contains(st.Issue.Listening, 8080) {
		t.Fatalf("issue %+v", st.Issue)
	}
	if st.Health == nil || st.Health.State == state.ProbePassing || st.Health.Attempts == 0 {
		t.Fatalf("health %+v", st.Health)
	}
	t.Logf("loopback_only after %s: %+v", time.Since(start).Round(time.Second), st.Issue)
	if len(events(e, wl, state.EventCreated)) != 1 || len(events(e, wl, state.EventStarted)) != 1 {
		t.Fatalf("events %+v", e.rec.PendingEvents())
	}
}

// TestRolloutFailsFastOnCrashLoop: an app that crashes on import exits with
// its traceback, Docker restarts it, and after three restarts it carries a
// crash_loop issue; the exit carries the last lines.
func TestRolloutFailsFastOnCrashLoop(t *testing.T) {
	e := lifecycleEnv(t, nil)
	wl := state.WorkloadID(randomID("wl"))
	argv := []string{crashShell, "-c", `echo "Traceback (most recent call last):"; echo "RuntimeError: the app fails while importing" >&2; exit 3`}
	ns := e.lifecycleState(t, 1, "", e.busyboxWorkload(t, wl, busyboxImage, argv, true))
	start := time.Now()
	watchUntil(t, e, ns, 90*time.Second, func() bool {
		is := status(e.rec, wl).Issue
		return is != nil && is.Kind == state.IssueCrashLoop
	})
	st := status(e.rec, wl)
	if st.Issue.Restarts < 3 || (st.Issue.ExitCode != nil && *st.Issue.ExitCode != 3) {
		t.Fatalf("issue %+v", st.Issue)
	}
	// Docker resets the code once it restarted the container; when an exit
	// was seen while it was down, the code is the app's.
	exits := events(e, wl, state.EventExited)
	if len(exits) == 0 {
		t.Fatalf("no exits: %+v", e.rec.PendingEvents())
	}
	for _, x := range exits {
		if x.ExitCode != nil && *x.ExitCode != 3 {
			t.Fatalf("exit %+v", x)
		}
	}
	found := false
	for _, l := range exits[len(exits)-1].Output {
		found = found || (strings.Contains(l.Text, "RuntimeError") && l.Stream == state.StreamStderr)
	}
	if !found {
		t.Fatalf("exit output %+v", exits[len(exits)-1].Output)
	}
	if len(events(e, wl, state.EventRestarted)) == 0 {
		t.Fatalf("no restart events: %+v", e.rec.PendingEvents())
	}
	t.Logf("crash_loop after %s", time.Since(start).Round(time.Second))
}

// TestSlowStartIsNotAnIssue: an app that takes a while before it listens
// is just starting: no issue (Docker's DNS listener on 127.0.0.11 is not the
// app), then healthy, with the health check's attempts counted.
func TestSlowStartIsNotAnIssue(t *testing.T) {
	e := lifecycleEnv(t, nil)
	wl := state.WorkloadID(randomID("wl"))
	argv := []string{crashShell, "-c", "echo warming up; sleep 20; exec httpd -f -p 8080 -h /"}
	ns := e.lifecycleState(t, 1, "", e.busyboxWorkload(t, wl, busyboxImage, argv, true))
	watchUntil(t, e, ns, 2*time.Minute, func() bool {
		if is := status(e.rec, wl).Issue; is != nil {
			t.Fatalf("a slow start reported as %+v", is)
		}
		return status(e.rec, wl).Phase == state.PhaseHealthy
	})
	e.rec.Watch(t.Context()) // the next look reports the passing check
	if len(events(e, wl, state.EventHealthPassing)) != 1 {
		t.Fatalf("events %+v", e.rec.PendingEvents())
	}
	if st := status(e.rec, wl); st.Health == nil || st.Health.Attempts < 10 {
		t.Fatalf("health %+v", st.Health)
	}
}

// TestStopStartAndRestart: Stop leaves the workload out of the desired state
// and the route answers with the stopped page; Start brings it back on a new
// workload; Restart replaces it with another one next to the old, which
// drains with its reason.
func TestStopStartAndRestart(t *testing.T) {
	e := lifecycleEnv(t, nil)
	httpd := []string{"httpd", "-f", "-p", "8080", "-h", "/"}
	a := e.busyboxWorkload(t, state.WorkloadID(randomID("wl")), busyboxImage, httpd, true)
	watchUntil(t, e, e.lifecycleState(t, 1, "routed", a), 3*time.Minute, func() bool {
		_, _, ing := e.rec.Snapshot()
		return status(e.rec, a.ID).Phase == state.PhaseHealthy && ing != nil && ing.State == state.IngressApplied
	})
	waitRoute(t, http.StatusNotFound, "The requested URL was not found")

	// Stop.
	stopped := e.lifecycleState(t, 2, "stopped")
	watchUntil(t, e, stopped, time.Minute, func() bool { return status(e.rec, a.ID).Phase == state.PhaseRemoved })
	if rm := events(e, a.ID, state.EventRemoved); len(rm) != 1 || rm[0].Reason != state.ReasonNotDesired {
		t.Fatalf("removal %+v", rm)
	}
	cs, _ := e.engine.ListContainers(t.Context(), map[string]string{docker.LabelWorkload: string(a.ID)})
	if len(cs) != 0 {
		t.Fatalf("containers left: %v", cs)
	}
	waitRoute(t, http.StatusServiceUnavailable, "this app is stopped")

	// Start: a new workload of the same image.
	b := e.busyboxWorkload(t, state.WorkloadID(randomID("wl")), busyboxImage, httpd, true)
	watchUntil(t, e, e.lifecycleState(t, 3, "routed", b), 2*time.Minute, func() bool { return status(e.rec, b.ID).Phase == state.PhaseHealthy })
	watchUntil(t, e, e.lifecycleState(t, 3, "routed", b), time.Minute, func() bool { return true })
	waitRoute(t, http.StatusNotFound, "The requested URL was not found")

	// Restart: c starts next to b, which drains once c is routed.
	c := e.busyboxWorkload(t, state.WorkloadID(randomID("wl")), busyboxImage, httpd, true)
	watchUntil(t, e, e.lifecycleState(t, 4, "routed", c, b), 2*time.Minute, func() bool { return status(e.rec, c.ID).Phase == state.PhaseHealthy })
	past := time.Now().Add(-time.Second).UTC()
	b.Role, b.DrainUntil = state.RoleDraining, &past
	watchUntil(t, e, e.lifecycleState(t, 5, "routed", c, b), time.Minute, func() bool { return len(events(e, b.ID, state.EventStopped)) > 0 })
	if st := events(e, b.ID, state.EventStopped); st[0].Reason != state.ReasonDrained {
		t.Fatalf("drain %+v", st)
	}
	waitRoute(t, http.StatusNotFound, "The requested URL was not found")
}

// lifecycleTLS reaches the edge's HTTPS port (Caddy's internal CA).
var lifecycleTLS = &http.Client{Timeout: 10 * time.Second, Transport: &http.Transport{
	Proxy: nil, DisableKeepAlives: true,
	TLSClientConfig: &tls.Config{ServerName: lifecycleHost, InsecureSkipVerify: true, MinVersion: tls.VersionTLS12}, //nolint:gosec // internal CA in tests
	DialContext: func(ctx context.Context, network, _ string) (net.Conn, error) {
		var d net.Dialer
		return d.DialContext(ctx, network, "127.0.0.1:443")
	},
}, CheckRedirect: func(*http.Request, []*http.Request) error { return http.ErrUseLastResponse }}

// waitRoute waits until the edge answers lifecycleHost with code (and
// contains in the body). busybox httpd answers / with its own 404 page, so
// that page means the request reached the app.
func waitRoute(t *testing.T, code int, contains string) {
	t.Helper()
	deadline := time.Now().Add(90 * time.Second)
	for {
		got, _, body, err := get(t, noRedirect, "http://127.0.0.1:80/", lifecycleHost)
		if got == http.StatusPermanentRedirect {
			got, _, body, err = get(t, lifecycleTLS, "https://"+lifecycleHost+"/", lifecycleHost)
		}
		if err == nil && got == code && strings.Contains(body, contains) {
			return
		}
		if time.Now().After(deadline) {
			t.Fatalf("route: %d %q %v, want %d with %q", got, body, err, code, contains)
		}
		time.Sleep(time.Second)
	}
}

// TestPullFromUnreachableRegistryFails: an image the registry never answers
// for (the private network to the main server is down or stalled) fails
// with a clear reason at the warm-up's bound, without holding back the
// rest of the desired state; a refused workload (policy) fails at once.
func TestPullFromUnreachableRegistryFails(t *testing.T) {
	e := lifecycleEnv(t, func(ctx context.Context, _ string) (map[string]int64, error) {
		<-ctx.Done() // the registry never answers
		return nil, ctx.Err()
	})
	stuck := e.busyboxWorkload(t, state.WorkloadID(randomID("wl")), "127.0.0.1:5000/apps/svc_01j9z8x7w6v5t4s3r2q1p0lc02@sha256:1111111111111111111111111111111111111111111111111111111111111111", []string{"sleep", "3600"}, false)
	other := e.busyboxWorkload(t, state.WorkloadID(randomID("wl")), busyboxImage, []string{"sleep", "3600"}, false)
	refused := e.busyboxWorkload(t, state.WorkloadID(randomID("wl")), "ghcr.io/example/app@sha256:2222222222222222222222222222222222222222222222222222222222222222", []string{"sleep", "3600"}, false)
	ns := e.lifecycleState(t, 1, "", stuck, other, refused)

	start := time.Now()
	ctx, cancel := context.WithTimeout(context.Background(), 5*time.Minute)
	defer cancel()
	if err := e.rec.Reconcile(ctx, ns); err != nil {
		t.Logf("reconcile: %v", err)
	}
	if time.Since(start) > time.Minute {
		t.Fatalf("the pass waited %s", time.Since(start))
	}
	if st := status(e.rec, refused.ID); st.Phase != state.PhaseFailed || !strings.Contains(st.Message, "policy") {
		t.Fatalf("refused workload: %+v", st)
	}
	if st := status(e.rec, stuck.ID); st.Phase != state.PhasePulling {
		t.Fatalf("stuck workload: %+v", st)
	}
	var failed state.WorkloadStatus
	watchUntil(t, e, ns, 2*time.Minute, func() bool {
		if st := status(e.rec, stuck.ID); st.Phase == state.PhaseFailed {
			failed = st
		}
		return status(e.rec, other.ID).Phase == state.PhaseHealthy && failed.Phase == state.PhaseFailed
	})
	// (The next pass tries the pull again; the rollout has failed by then.)
	if !strings.Contains(failed.Message, "the launchroom registry didn't answer within 2s") {
		t.Fatalf("stuck workload: %+v", failed)
	}
}
