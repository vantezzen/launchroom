package reconcile

import (
	"context"
	"crypto/ecdh"
	"crypto/rand"
	"errors"
	"net/netip"
	"slices"
	"strings"
	"sync"
	"testing"
	"time"

	"github.com/vantezzen/launchroom/daemon/internal/docker"
	"github.com/vantezzen/launchroom/daemon/internal/docker/dockerfake"
	"github.com/vantezzen/launchroom/daemon/internal/health"
	"github.com/vantezzen/launchroom/daemon/internal/ingress/caddy"
	"github.com/vantezzen/launchroom/daemon/internal/policy"
	"github.com/vantezzen/launchroom/daemon/internal/sealed"
	"github.com/vantezzen/launchroom/daemon/internal/secret"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

type fakeIngress struct {
	mu     sync.Mutex
	routes [][]caddy.Route
	// onSync observes every sync (with the lock held).
	onSync func([]caddy.Route)
}

func (f *fakeIngress) Sync(_ context.Context, sys state.SystemContainer, routes []caddy.Route) *state.IngressStatus {
	f.mu.Lock()
	defer f.mu.Unlock()
	f.routes = append(f.routes, routes)
	if f.onSync != nil {
		f.onSync(routes)
	}
	if !sys.Enabled {
		return nil
	}
	return &state.IngressStatus{State: state.IngressApplied, Routes: len(routes)}
}

func (f *fakeIngress) last() []caddy.Route {
	f.mu.Lock()
	defer f.mu.Unlock()
	return f.routes[len(f.routes)-1]
}

type noPorts struct{}

func (noPorts) Listeners(int) ([]health.Listener, error) {
	return []health.Listener{{Port: 3000}}, nil
}

type harness struct {
	r       *Reconciler
	d       *dockerfake.Fake
	ingress *fakeIngress
	key     *ecdh.PrivateKey
	probeOK *bool
	now     time.Time
}

func newHarness(t *testing.T) *harness {
	t.Helper()
	key, err := ecdh.X25519().GenerateKey(rand.Reader)
	if err != nil {
		t.Fatal(err)
	}
	h := &harness{d: dockerfake.New(srvID), ingress: &fakeIngress{}, key: key, now: t0}
	ok := true
	h.probeOK = &ok
	h.d.Now = func() time.Time { return h.now }
	mon := health.NewMonitor(t.Context(), health.MonitorOptions{Probe: func(context.Context, health.Target) error {
		if *h.probeOK {
			return nil
		}
		return errors.New("connection refused")
	}, Now: func() time.Time { return h.now }})
	t.Cleanup(mon.Close)
	h.r = New(Options{
		ServerID: srvID, Docker: h.d, Policy: policy.DefaultConfig(), Health: mon, Ports: noPorts{}, Ingress: h.ingress,
		EnvHashKey: EnvHashKey(key.Bytes()),
		OpenEnv: func(s *state.WorkloadSpec) (map[string]secret.Secret, error) {
			return sealed.OpenWorkloadEnv(s.Env, key, srvID, s.ID)
		},
		Now: func() time.Time { return h.now },
	})
	return h
}

func (h *harness) seal(t *testing.T, id state.WorkloadID, env map[string]secret.Secret) state.Sealed {
	t.Helper()
	s, err := sealed.SealWorkloadEnv(state.WorkloadEnv{ServerID: srvID, WorkloadID: id, Env: env}, h.key.PublicKey(), rand.Reader)
	if err != nil {
		t.Fatal(err)
	}
	return s
}

func (h *harness) nodeState(t *testing.T, version int64, workloads ...state.WorkloadSpec) *state.NodeState {
	t.Helper()
	ns := &state.NodeState{
		TypeMeta: state.NewTypeMeta(state.KindNodeState), ServerID: srvID, Version: version, IssuedAt: t0,
		Networks: []state.NetworkSpec{netSpec()}, Volumes: []state.VolumeSpec{}, Workloads: workloads,
		Ambassadors: []state.AmbassadorSpec{}, Routes: []state.RouteSpec{}, Firewall: state.FirewallSpec{MeshAllow: []state.MeshAllowRule{}},
	}
	if len(workloads) > 0 {
		ns.Routes = []state.RouteSpec{{ID: "rt_01J9Z8X7W6V5T4S3R2Q1P0N9N9", Hosts: []state.Hostname{"app.localhost"}, TLS: state.TLSInternal,
			Upstreams: []state.RouteUpstream{{Workload: workloads[0].ID, Port: 3000}}}}
		ns.System.Caddy = state.SystemContainer{Enabled: true, Image: state.ImageRef("docker.io/library/caddy@" + policy.DefaultSystemImageDigests[policy.SystemCaddy][0])}
	}
	if err := ns.Validate(); err != nil {
		t.Fatal(err)
	}
	return ns
}

func (h *harness) workload(t *testing.T, id state.WorkloadID, env map[string]secret.Secret) state.WorkloadSpec {
	if env == nil {
		env = map[string]secret.Secret{}
	}
	port := state.Port(3000)
	return state.WorkloadSpec{
		ID: id, Service: "svc_01J9Z8X7W6V5T4S3R2Q1P0N9N2", Environment: "env_01J9Z8X7W6V5T4S3R2Q1P0N9N4", Deployment: "dpl_01J9Z8X7W6V5T4S3R2Q1P0N9N5",
		Image: imgA, Env: h.seal(t, id, env), Port: &port,
		Health:    &state.HealthSpec{Type: state.HealthHTTP, Path: "/", StartPeriod: "30s", Interval: "1s", Timeout: "1s", FailureThreshold: 1},
		Resources: state.Resources{CPUs: 1, MemoryMB: 256, Pids: 256},
		Networks:  []state.NetworkAttachment{{Network: netA, Aliases: []state.Slug{"web"}}},
		Restart:   state.RestartUnlessStopped, Role: state.RoleServing,
	}
}

func phaseOf(r *Reconciler, id state.WorkloadID) state.WorkloadStatus {
	_, ws, _ := r.Snapshot()
	for _, w := range ws {
		if w.ID == id {
			return w
		}
	}
	return state.WorkloadStatus{}
}

func eventually(t *testing.T, cond func() bool) {
	t.Helper()
	deadline := time.Now().Add(5 * time.Second)
	for !cond() {
		if time.Now().After(deadline) {
			t.Fatal("timed out")
		}
		time.Sleep(10 * time.Millisecond)
	}
}

func TestReconcileLifecycle(t *testing.T) {
	ctx := t.Context()
	h := newHarness(t)
	w := h.workload(t, wlA, map[string]secret.Secret{"GREETING": "hi"})
	ns := h.nodeState(t, 1, w)

	if err := h.r.Reconcile(ctx, ns); err != nil {
		t.Fatal(err)
	}
	calls := h.d.Calls()
	want := []string{"create-network lr-e_a 10.231.4.0/24", "pull " + string(imgA), "create lr-" + strings.ToLower(string(wlA)), "start lr-" + strings.ToLower(string(wlA))}
	if !slices.Equal(calls, want) {
		t.Fatalf("calls %q", calls)
	}
	cc, _ := h.d.Create("lr-" + strings.ToLower(string(wlA)))
	if !slices.Contains(cc.Config.Env, "GREETING=hi") || !slices.Contains(cc.HostConfig.CapDrop, "ALL") ||
		!slices.Contains(cc.HostConfig.SecurityOpt, policy.NoNewPrivileges) || cc.Config.Labels[docker.LabelSpecHash] == "" {
		t.Fatalf("container spec %+v %+v", cc.Config, cc.HostConfig)
	}
	if strings.Contains(cc.Config.Labels[docker.LabelSpecHash], "hi") {
		t.Fatal("hash leaks env")
	}

	// Probe passes → healthy; second pass routes traffic to the container IP.
	eventually(t, func() bool { return phaseOf(h.r, wlA).Phase == state.PhaseHealthy })
	if err := h.r.Reconcile(ctx, ns); err != nil {
		t.Fatal(err)
	}
	if calls := h.d.Calls(); len(calls) != 0 {
		t.Fatalf("converged state changed something: %q", calls)
	}
	routes := h.ingress.last()
	if len(routes) != 1 || len(routes[0].Dial) != 1 || !strings.HasSuffix(routes[0].Dial[0], ":3000") || !strings.HasPrefix(routes[0].Dial[0], "10.231.4.") {
		t.Fatalf("routes %+v", routes)
	}
	st := phaseOf(h.r, wlA)
	if len(st.ContainerID) != 64 || st.Health == nil || st.Health.State != state.ProbePassing || !slices.Equal(st.DetectedPorts, []state.Port{3000}) {
		t.Fatalf("status %+v", st)
	}
	v, _, ing := h.r.Snapshot()
	if v != 1 || ing == nil || ing.State != state.IngressApplied {
		t.Fatalf("v=%d ingress=%+v", v, ing)
	}

	// Same env re-sealed (new ciphertext) → no recreate.
	w2 := w
	w2.Env = h.seal(t, wlA, map[string]secret.Secret{"GREETING": "hi"})
	if err := h.r.Reconcile(ctx, h.nodeState(t, 2, w2)); err != nil {
		t.Fatal(err)
	}
	if calls := h.d.Calls(); len(calls) != 0 {
		t.Fatalf("re-sealed env recreated: %q", calls)
	}
	// Changed env → recreate.
	w3 := w
	w3.Env = h.seal(t, wlA, map[string]secret.Secret{"GREETING": "hello"})
	if err := h.r.Reconcile(ctx, h.nodeState(t, 3, w3)); err != nil {
		t.Fatal(err)
	}
	if calls := h.d.Calls(); !slices.Contains(calls, "remove lr-"+strings.ToLower(string(wlA))) || !slices.Contains(calls, "create lr-"+strings.ToLower(string(wlA))) {
		t.Fatalf("calls %q", calls)
	}

	// Removed from desired state → stopped, removed, reported as removed; network removed.
	empty := h.nodeState(t, 4)
	empty.Networks = []state.NetworkSpec{}
	if err := h.r.Reconcile(ctx, empty); err != nil {
		t.Fatal(err)
	}
	calls = h.d.Calls()
	if !slices.Contains(calls, "remove lr-"+strings.ToLower(string(wlA))) || !slices.Contains(calls, "remove-network lr-e_a") {
		t.Fatalf("calls %q", calls)
	}
	if st := phaseOf(h.r, wlA); st.Phase != state.PhaseRemoved {
		t.Fatalf("%+v", st)
	}
}

// A NodeState that switches traffic and ends a drain in one pass (applied
// late) must take the drained workload out of the routes before stopping it.
func TestReconcileUnroutesBeforeStopping(t *testing.T) {
	ctx := t.Context()
	h := newHarness(t)
	a := h.workload(t, wlA, nil)
	b := h.workload(t, wlB, nil)
	both := func(version int64, workloads ...state.WorkloadSpec) *state.NodeState {
		ns := h.nodeState(t, version, workloads...)
		ns.Routes[0].Upstreams = []state.RouteUpstream{{Workload: wlB, Port: 3000}, {Workload: wlA, Port: 3000}}
		return ns
	}
	for _, ns := range []*state.NodeState{h.nodeState(t, 1, a), both(2, b, a)} {
		if err := h.r.Reconcile(ctx, ns); err != nil {
			t.Fatal(err)
		}
	}
	eventually(t, func() bool {
		return phaseOf(h.r, wlA).Phase == state.PhaseHealthy && phaseOf(h.r, wlB).Phase == state.PhaseHealthy
	})
	if err := h.r.Reconcile(ctx, both(2, b, a)); err != nil {
		t.Fatal(err)
	}
	if routes := h.ingress.last(); len(routes[0].Dial) != 2 {
		t.Fatalf("both serving workloads should be routed: %+v", routes)
	}

	h.d.Calls()
	draining := a
	past := t0.Add(-time.Minute)
	draining.Role, draining.DrainUntil = state.RoleDraining, &past
	aRunning := func() bool {
		cs, _ := h.d.ListContainers(ctx, map[string]string{docker.LabelWorkload: string(wlA)})
		return len(cs) == 1 && cs[0].Running
	}
	var routedWhileStopping bool
	var syncsWhileRunning int
	h.ingress.onSync = func(routes []caddy.Route) {
		// Called with the ingress lock only; the fake docker has its own lock.
		if !aRunning() {
			return
		}
		syncsWhileRunning++
		routedWhileStopping = routedWhileStopping || len(routes[0].Dial) != 1
	}
	if err := h.r.Reconcile(ctx, both(3, b, draining)); err != nil {
		t.Fatal(err)
	}
	if syncsWhileRunning == 0 || routedWhileStopping {
		t.Fatalf("the draining workload was not unrouted before it stopped (syncs=%d)", syncsWhileRunning)
	}
	if calls := h.d.Calls(); !slices.ContainsFunc(calls, func(c string) bool { return strings.HasPrefix(c, "stop lr-"+strings.ToLower(string(wlA))) }) {
		t.Fatalf("calls %q", calls)
	}
}

func TestReconcileUnhealthyNotRouted(t *testing.T) {
	h := newHarness(t)
	*h.probeOK = false
	h.now = t0.Add(time.Hour) // start period long over
	w := h.workload(t, wlA, nil)
	ns := h.nodeState(t, 1, w)
	h.d.Now = func() time.Time { return t0 } // container "started" at t0
	if err := h.r.Reconcile(t.Context(), ns); err != nil {
		t.Fatal(err)
	}
	eventually(t, func() bool { return phaseOf(h.r, wlA).Phase == state.PhaseUnhealthy })
	if err := h.r.Reconcile(t.Context(), ns); err != nil {
		t.Fatal(err)
	}
	if routes := h.ingress.last(); len(routes[0].Dial) != 0 {
		t.Fatalf("unhealthy workload routed: %v", routes[0].Dial)
	}
	if msg := phaseOf(h.r, wlA).Message; !strings.Contains(msg, "connection refused") {
		t.Fatalf("message %q", msg)
	}
}

func TestReconcileRetargetedEnvIsRefused(t *testing.T) {
	h := newHarness(t)
	w := h.workload(t, wlA, nil)
	w.Env = h.seal(t, wlB, map[string]secret.Secret{"X": "1"}) // sealed for another workload
	if err := h.r.Reconcile(t.Context(), h.nodeState(t, 1, w)); err != nil {
		t.Fatal(err)
	}
	for _, c := range h.d.Calls() {
		if strings.HasPrefix(c, "create lr-") {
			t.Fatal("container created with a re-targeted env")
		}
	}
	st := phaseOf(h.r, wlA)
	if st.Phase != state.PhaseFailed || !strings.Contains(st.Message, "sealed for") {
		t.Fatalf("%+v", st)
	}
}

func TestReconcilePullFailure(t *testing.T) {
	h := newHarness(t)
	h.d.PullErr[string(imgA)] = errors.New("manifest unknown")
	w := h.workload(t, wlA, nil)
	if err := h.r.Reconcile(t.Context(), h.nodeState(t, 1, w)); err == nil {
		t.Fatal("expected an error")
	}
	st := phaseOf(h.r, wlA)
	if st.Phase != state.PhaseFailed || !strings.Contains(st.Message, "manifest unknown") {
		t.Fatalf("%+v", st)
	}
}

func TestReconcileNeverTouchesUnmanaged(t *testing.T) {
	h := newHarness(t)
	h.d.AddUnmanagedContainer("someone-elses", map[string]string{"launchroom.workload": string(wlGone)})
	h.d.AddUnmanagedNetwork("lr-e_other")
	if err := h.r.Reconcile(t.Context(), h.nodeState(t, 1)); err != nil {
		t.Fatal(err)
	}
	for _, c := range h.d.Calls() {
		if strings.Contains(c, "someone-elses") || strings.Contains(c, "lr-e_other") {
			t.Fatalf("touched unmanaged object: %s", c)
		}
	}
}

type phaseCase struct {
	name  string
	s     *state.WorkloadSpec
	o     *observed
	n     *note
	probe *health.Status
	now   time.Time
	want  state.WorkloadPhase
}

func TestStatusOfPhases(t *testing.T) {
	port := state.Port(3000)
	s := &state.WorkloadSpec{ID: wlA, Port: &port, Role: state.RoleServing}
	running := &observed{c: docker.Container{State: "running", Running: true, StartedAt: t0}}
	cases := []phaseCase{
		{"pending", s, nil, nil, nil, t0, state.PhasePending},
		{"pulling", s, nil, &note{phase: state.PhasePulling}, nil, t0, state.PhasePulling},
		{"failed", s, nil, &note{phase: state.PhaseFailed}, nil, t0, state.PhaseFailed},
		{"no probe, just started", s, running, nil, nil, t0.Add(time.Second), state.PhaseStarting},
		{"no probe, running long enough", s, running, nil, nil, t0.Add(time.Minute), state.PhaseHealthy},
		{"exited", s, &observed{c: docker.Container{State: "exited", ExitCode: 1}}, nil, nil, t0, state.PhaseExited},
		{"restarting", s, &observed{c: docker.Container{State: "restarting"}}, nil, nil, t0, state.PhaseUnhealthy},
		{"created", s, &observed{c: docker.Container{State: "created"}}, nil, nil, t0, state.PhaseStarting},
	}
	hs := &state.WorkloadSpec{ID: wlA, Port: &port, Role: state.RoleServing, Health: &state.HealthSpec{Type: state.HealthTCP}}
	cases = append(cases,
		phaseCase{"probe starting", hs, running, nil, &health.Status{State: state.ProbeStarting}, t0, state.PhaseStarting},
		phaseCase{"probe failing", hs, running, nil, &health.Status{State: state.ProbeFailing}, t0, state.PhaseUnhealthy},
	)
	dr := *s
	dr.Role = state.RoleDraining
	cases = append(cases,
		phaseCase{"draining", &dr, running, nil, nil, t0, state.PhaseDraining},
		phaseCase{"drained", &dr, &observed{c: docker.Container{State: "exited"}}, nil, nil, t0, state.PhaseExited},
	)
	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			if got := statusOf(tc.s, tc.o, tc.n, tc.probe, tc.now); got.Phase != tc.want {
				t.Fatalf("got %s", got.Phase)
			}
		})
	}
	// Port hint when probe fails and the app listens elsewhere.
	o := &observed{c: running.c, ports: []health.Listener{{Port: 5173}}}
	got := statusOf(hs, o, nil, &health.Status{State: state.ProbeFailing, LastError: "connection refused"}, t0)
	if !strings.Contains(got.Message, "listens on 5173, not 3000") {
		t.Fatalf("message %q", got.Message)
	}
}

func TestReconcileKeepsSystemNetworks(t *testing.T) {
	h := newHarness(t)
	labels := docker.ManagedLabels(string(srvID))
	labels[docker.LabelSystem] = policy.SystemBuildkit
	if _, err := h.d.CreateNetwork(t.Context(), docker.NetworkCreate{Name: "lr-system-build", Subnet: netip.MustParsePrefix("10.232.0.0/24"), Labels: labels}); err != nil {
		t.Fatal(err)
	}
	if err := h.r.Reconcile(t.Context(), h.nodeState(t, 1)); err != nil {
		t.Fatal(err)
	}
	for _, c := range h.d.Calls() {
		if strings.Contains(c, "remove-network") || strings.Contains(c, "RemoveNetwork") {
			t.Fatalf("reconciler removed the system build network: %s", c)
		}
	}
}

// A compromised control plane must not make Caddy proxy to arbitrary host
// ports: address upstreams outside the mesh range and the local allow-list
// never reach the ingress (docs/03 §8).
func TestReconcileRefusesForbiddenAddressUpstreams(t *testing.T) {
	h := newHarness(t)
	h.r.o.Policy.LocalUpstreams = []netip.AddrPort{netip.MustParseAddrPort("127.0.0.1:8000")}
	ns := h.nodeState(t, 1)
	ns.System.Caddy = state.SystemContainer{Enabled: true, Image: state.ImageRef("docker.io/library/caddy@" + policy.DefaultSystemImageDigests[policy.SystemCaddy][0])}
	ns.Routes = []state.RouteSpec{
		{ID: "rt_0000000000000000000000DASH", Hosts: []state.Hostname{"dash.example.com"}, TLS: state.TLSInternal,
			Upstreams: []state.RouteUpstream{{Address: "127.0.0.1:8000"}}},
		{ID: "rt_01J9Z8X7W6V5T4S3R2Q1P0N9N9", Hosts: []state.Hostname{"evil.example.com"}, TLS: state.TLSInternal,
			Upstreams: []state.RouteUpstream{{Address: "127.0.0.1:2019"}, {Address: "100.64.0.9:31002"}}},
	}
	if err := ns.Validate(); err != nil {
		t.Fatal(err)
	}
	if err := h.r.Reconcile(t.Context(), ns); err != nil {
		t.Fatal(err)
	}
	routes := h.ingress.last()
	if len(routes) != 2 || !slices.Equal(routes[0].Dial, []string{"127.0.0.1:8000"}) || !slices.Equal(routes[1].Dial, []string{"100.64.0.9:31002"}) {
		t.Fatalf("routes = %+v", routes)
	}
}

// TestReconcileHoldsWorkloadsWithoutIsolation: while the firewall cannot be
// applied, networks are created but no workload container is created or
// started (fail closed, docs/05 §1); isolation runs after networks exist.
func TestReconcileHoldsWorkloadsWithoutIsolation(t *testing.T) {
	ctx := t.Context()
	h := newHarness(t)
	var calls []string
	isoErr := errors.New("nftables unavailable")
	h.r.o.Isolate = func(context.Context) error {
		calls = append(calls, "isolate after "+strings.Join(h.d.Calls(), ","))
		return isoErr
	}
	ns := h.nodeState(t, 1, h.workload(t, wlA, nil))
	if err := h.r.Reconcile(ctx, ns); !errors.Is(err, isoErr) {
		t.Fatalf("err = %v", err)
	}
	if len(calls) != 1 || !strings.Contains(calls[0], "create-network lr-e_a") {
		t.Fatalf("isolate calls %q", calls)
	}
	for _, c := range h.d.Calls() {
		if strings.HasPrefix(c, "create lr-") || strings.HasPrefix(c, "start ") {
			t.Fatalf("workload started without isolation: %q", h.d.Calls())
		}
	}
	if st := phaseOf(h.r, wlA); st.Phase != state.PhasePending || !strings.Contains(st.Message, "nftables") {
		t.Fatalf("status %+v", st)
	}
	isoErr = nil
	if err := h.r.Reconcile(ctx, ns); err != nil {
		t.Fatal(err)
	}
	if !slices.Contains(h.d.Calls(), "start lr-"+strings.ToLower(string(wlA))) {
		t.Fatalf("workload not started once isolated: %q", h.d.Calls())
	}
}

// A pull's progress reaches the workload's reported status while it runs
// (docs/04 §11 "Pulls") and is gone once the container exists.
func TestReconcileReportsPullProgress(t *testing.T) {
	ctx := t.Context()
	h := newHarness(t)
	w := h.workload(t, wlA, nil)
	ns := h.nodeState(t, 1, w)
	var during state.WorkloadStatus
	h.d.OnPull = func(ctx context.Context, _ string) {
		docker.ReportPullProgress(ctx, docker.PullProgress{Stage: docker.PullMirroring})
		docker.ReportPullProgress(ctx, docker.PullProgress{Stage: docker.PullDownloading, Downloaded: 412_000_000, Total: 690_000_000})
		docker.ReportPullProgress(ctx, docker.PullProgress{Stage: "bogus", Downloaded: 1, Total: 2}) // dropped
		during = phaseOf(h.r, wlA)
	}
	if err := h.r.Reconcile(ctx, ns); err != nil {
		t.Fatal(err)
	}
	want := state.PullProgress{Stage: state.PullDownloading, DownloadedBytes: 412_000_000, TotalBytes: 690_000_000}
	if during.Phase != state.PhasePulling || during.Pull == nil || during.Pull.UpdatedAt == nil || !during.Pull.UpdatedAt.Equal(t0) {
		t.Fatalf("during the pull: %+v (pull %+v)", during, during.Pull)
	}
	if got := *during.Pull; got.Stage != want.Stage || got.DownloadedBytes != want.DownloadedBytes || got.TotalBytes != want.TotalBytes {
		t.Fatalf("during the pull: %+v", got)
	}
	if st := phaseOf(h.r, wlA); st.Pull != nil {
		t.Fatalf("after the pull: %+v", st.Pull)
	}
}

// A pull the running pass waits for is abandoned once a newer desired state
// no longer has a workload with its image (a deployment cancelled or a
// service held for an import, docs/14 §12.5): the server applies the new
// state instead of waiting for the pull to end. A pull the new state still
// needs goes on.
func TestAbandonPullsWhenNoDesiredWorkloadNeedsTheImage(t *testing.T) {
	h := newHarness(t)
	w := h.workload(t, wlA, nil)
	first, still, without := h.nodeState(t, 1, w), h.nodeState(t, 2, w), h.nodeState(t, 3)
	started := make(chan struct{})
	var cause error
	h.d.PullErr[string(imgA)] = context.Canceled
	h.d.OnPull = func(ctx context.Context, _ string) {
		close(started)
		<-ctx.Done()
		cause = context.Cause(ctx)
	}
	done := make(chan error, 1)
	go func() { done <- h.r.Reconcile(t.Context(), first) }()
	<-started

	if h.r.AbandonPulls(still) {
		t.Fatal("abandoned a pull the new state still needs")
	}
	if !h.r.AbandonPulls(without) {
		t.Fatal("the pull was not abandoned")
	}
	select {
	case err := <-done:
		if err == nil {
			t.Fatal("the pass hid the abandoned pull")
		}
	case <-time.After(5 * time.Second):
		t.Fatal("the pass kept waiting for the pull")
	}
	if !errors.Is(cause, errPullAbandoned) {
		t.Fatalf("cause %v", cause)
	}
	if h.r.AbandonPulls(without) {
		t.Fatal("abandoned a pull after the pass ended")
	}
}
