//go:build integration

package integration

import (
	"context"
	"crypto/ecdh"
	"crypto/rand"
	"crypto/tls"
	"errors"
	"io"
	"log/slog"
	"math/big"
	"net"
	"net/http"
	"net/netip"
	"os"
	"slices"
	"strings"
	"testing"
	"time"

	"github.com/moby/moby/client"

	"github.com/vantezzen/launchroom/daemon/internal/docker"
	"github.com/vantezzen/launchroom/daemon/internal/health"
	"github.com/vantezzen/launchroom/daemon/internal/ingress/caddy"
	"github.com/vantezzen/launchroom/daemon/internal/policy"
	"github.com/vantezzen/launchroom/daemon/internal/reconcile"
	"github.com/vantezzen/launchroom/daemon/internal/sealed"
	"github.com/vantezzen/launchroom/daemon/internal/secret"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// Small, digest-pinned public test images.
const (
	whoamiImage = state.ImageRef("docker.io/traefik/whoami@sha256:c4717a8d1f0134a7444e24f881160e033991f23027c6c5a9a3f8fd22e70d1d44")
	caddyImage  = state.ImageRef("docker.io/library/caddy@sha256:685ae3d7c34d9b5f8caeddc5d14051db7bbd88ff1f5a2fa0c16bd8ca328fb7af")
	testHost    = "test.localhost"
	askAddr     = "127.0.0.1:2021"
)

const crockford = "0123456789ABCDEFGHJKMNPQRSTVWXYZ"

func randomID(prefix string) string {
	b := make([]byte, 26)
	for i := range b {
		n, _ := rand.Int(rand.Reader, big.NewInt(int64(len(crockford))))
		b[i] = crockford[n.Int64()]
	}
	b[0] = '0'
	return prefix + "_" + string(b)
}

// sweep removes every launchroom-managed object in the (dedicated) test daemon.
func sweep(t *testing.T, api *client.Client) {
	t.Helper()
	ctx, cancel := context.WithTimeout(context.Background(), 2*time.Minute)
	defer cancel()
	f := client.Filters{}
	f.Add("label", docker.LabelManaged+"=true")
	cs, err := api.ContainerList(ctx, client.ContainerListOptions{All: true, Filters: f})
	if err != nil {
		t.Fatalf("docker not reachable (run via `make test-daemon-integration`): %v", err)
	}
	for _, c := range cs.Items {
		_, _ = api.ContainerRemove(ctx, c.ID, client.ContainerRemoveOptions{Force: true})
	}
	ns, _ := api.NetworkList(ctx, client.NetworkListOptions{Filters: f})
	for _, n := range ns.Items {
		_, _ = api.NetworkRemove(ctx, n.ID, client.NetworkRemoveOptions{})
	}
	vs, _ := api.VolumeList(ctx, client.VolumeListOptions{Filters: f})
	for _, v := range vs.Items {
		_, _ = api.VolumeRemove(ctx, v.Name, client.VolumeRemoveOptions{Force: true})
	}
}

type env struct {
	serverID state.ServerID
	key      *ecdh.PrivateKey
	rec      *reconcile.Reconciler
	api      *client.Client
	engine   *docker.Engine
	ingress  *caddy.Manager
}

func setup(t *testing.T) *env {
	t.Helper()
	return setupWith(t, caddy.Options{})
}

// setupWith is setup with extra render options (e.g. ForwardAuthOrigins).
func setupWith(t *testing.T, extra caddy.Options) *env {
	t.Helper()
	return setupLocal(t, extra, nil)
}

// setupLocal is setupWith with LR_ALLOWED_LOCAL_UPSTREAMS (address upstreams
// on this host the local policy allows, e.g. the dashboard's 127.0.0.1:8000).
func setupLocal(t *testing.T, extra caddy.Options, local []netip.AddrPort) *env {
	t.Helper()
	return setupLocalOpen(t, extra, local, nil)
}

// setupLocalOpen is setupLocal with sealed ingress credentials opened by
// open (with the env's key; nil: none).
func setupLocalOpen(t *testing.T, extra caddy.Options, local []netip.AddrPort, open func(e *env) func(state.Sealed, state.IngressSecretPurpose) (map[string]secret.Secret, error)) *env {
	t.Helper()
	return setupEdge(t, extra, local, open, nil)
}

// setupEdge is setupLocalOpen with a hook that adjusts the ingress
// manager's options and the local policy (e.g. a locally built Caddy).
func setupEdge(t *testing.T, extra caddy.Options, local []netip.AddrPort, open func(e *env) func(state.Sealed, state.IngressSecretPurpose) (map[string]secret.Secret, error),
	mut func(*caddy.ManagerOptions, *policy.Config),
) *env {
	t.Helper()
	api, err := client.New(client.FromEnv)
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { _ = api.Close() })
	sweep(t, api)
	t.Cleanup(func() { sweep(t, api) })

	e := &env{serverID: state.ServerID(randomID("srv")), api: api}
	e.key, err = ecdh.X25519().GenerateKey(rand.Reader)
	if err != nil {
		t.Fatal(err)
	}
	e.engine, err = docker.NewEngine(docker.EngineOptions{Host: engineHost, ServerID: string(e.serverID), UserAgent: "launchroom-itest"})
	if err != nil {
		t.Fatal(err)
	}
	log := slog.New(slog.NewTextHandler(os.Stderr, &slog.HandlerOptions{Level: slog.LevelInfo}))

	pol := policy.DefaultConfig()
	pol.AllowedRegistries = []string{"docker.io/traefik/"}
	pol.LocalUpstreams = local

	ask := &caddy.Ask{}
	ctx, cancel := context.WithCancel(context.Background())
	t.Cleanup(cancel)
	go func() { _ = ask.Serve(ctx, askAddr) }()

	mo := caddy.ManagerOptions{
		ServerID: string(e.serverID), Docker: e.engine, Admin: caddy.NewAdminClient(caddy.DefaultAdminListen),
		Ask: ask, CacheDir: t.TempDir(), Log: log, ReadyTimeout: time.Minute, OpenSecret: openWith(open, e),
		Render: caddy.Options{AdminListen: caddy.DefaultAdminListen, AskURL: "http://" + askAddr + caddy.AskPath, HTTPPort: 80, HTTPSPort: 443, RedirectHTTPSPort: 443,
			ForwardAuthOrigins: extra.ForwardAuthOrigins, AccessLogAddress: extra.AccessLogAddress},
	}
	if mut != nil {
		mut(&mo, &pol)
	}
	mo.Policy = pol
	ingress := caddy.NewManager(mo)
	e.ingress = ingress
	mon := health.NewMonitor(ctx, health.MonitorOptions{})
	t.Cleanup(mon.Close)
	e.rec = reconcile.New(reconcile.Options{
		ServerID: e.serverID, Docker: e.engine, Policy: pol, Health: mon, Ingress: ingress,
		EnvHashKey: reconcile.EnvHashKey(e.key.Bytes()),
		OpenEnv: func(s *state.WorkloadSpec) (map[string]secret.Secret, error) {
			return sealed.OpenWorkloadEnv(s.Env, e.key, e.serverID, s.ID)
		},
		StopGrace: 2 * time.Second, Log: log,
	})
	return e
}

func openWith(open func(e *env) func(state.Sealed, state.IngressSecretPurpose) (map[string]secret.Secret, error), e *env) func(state.Sealed, state.IngressSecretPurpose) (map[string]secret.Secret, error) {
	if open == nil {
		return nil
	}
	return open(e)
}

func (e *env) nodeState(t *testing.T, version int64, withApp, withCaddy bool) (*state.NodeState, state.WorkloadID) {
	t.Helper()
	netID := state.NetworkID("net_01J9Z8X7W6V5T4S3R2Q1P0N9N0")
	wl := state.WorkloadID("wl_01J9Z8X7W6V5T4S3R2Q1P0N9N7")
	ns := &state.NodeState{
		TypeMeta: state.NewTypeMeta(state.KindNodeState), ServerID: e.serverID, Version: version, IssuedAt: time.Now().UTC(),
		System:      state.SystemSpec{Caddy: state.SystemContainer{Enabled: withCaddy, Image: caddyImage}},
		Networks:    []state.NetworkSpec{},
		Volumes:     []state.VolumeSpec{},
		Workloads:   []state.WorkloadSpec{},
		Ambassadors: []state.AmbassadorSpec{},
		Routes:      []state.RouteSpec{},
		Firewall:    state.FirewallSpec{MeshAllow: []state.MeshAllowRule{}},
	}
	if withApp {
		sealedEnv, err := sealed.SealWorkloadEnv(state.WorkloadEnv{ServerID: e.serverID, WorkloadID: wl,
			Env: map[string]secret.Secret{"WHOAMI_NAME": "itest"}}, e.key.PublicKey(), rand.Reader)
		if err != nil {
			t.Fatal(err)
		}
		port := state.Port(8080)
		ns.Networks = []state.NetworkSpec{{ID: netID, Name: "lr-e_itest", Subnet: "10.231.250.0/24"}}
		ns.Workloads = []state.WorkloadSpec{{
			ID: wl, Service: "svc_01J9Z8X7W6V5T4S3R2Q1P0N9N2", Environment: "env_01J9Z8X7W6V5T4S3R2Q1P0N9N4", Deployment: "dpl_01J9Z8X7W6V5T4S3R2Q1P0N9N5",
			Image: whoamiImage, Args: []string{"-port", "8080"}, Env: sealedEnv, Port: &port,
			Health:    &state.HealthSpec{Type: state.HealthHTTP, Path: "/", StartPeriod: "30s", Interval: "1s", Timeout: "1s", FailureThreshold: 3},
			Resources: state.Resources{CPUs: 0.5, MemoryMB: 64, Pids: 64},
			Networks:  []state.NetworkAttachment{{Network: netID, Aliases: []state.Slug{"whoami"}}},
			Restart:   state.RestartUnlessStopped, Role: state.RoleServing,
		}}
		ns.Routes = []state.RouteSpec{{ID: "rt_01J9Z8X7W6V5T4S3R2Q1P0N9N9", Hosts: []state.Hostname{testHost}, TLS: state.TLSInternal,
			Upstreams: []state.RouteUpstream{{Workload: wl, Port: 8080}}, Headers: map[state.HeaderName]string{"X-Robots-Tag": "noindex"}}}
	}
	if err := ns.Validate(); err != nil {
		t.Fatal(err)
	}
	return ns, wl
}

func status(rec *reconcile.Reconciler, id state.WorkloadID) state.WorkloadStatus {
	_, ws, _ := rec.Snapshot()
	for _, w := range ws {
		if w.ID == id {
			return w
		}
	}
	return state.WorkloadStatus{}
}

func converge(t *testing.T, e *env, ns *state.NodeState, done func() bool) {
	t.Helper()
	deadline := time.Now().Add(5 * time.Minute)
	for {
		ctx, cancel := context.WithTimeout(context.Background(), 4*time.Minute)
		err := e.rec.Reconcile(ctx, ns)
		cancel()
		if err != nil {
			t.Logf("reconcile: %v", err)
		}
		if done() {
			// One more pass, as the daemon does on every probe state change, so the
			// ingress reflects the state that satisfied done().
			ctx, cancel := context.WithTimeout(context.Background(), time.Minute)
			defer cancel()
			if err := e.rec.Reconcile(ctx, ns); err != nil {
				t.Logf("reconcile: %v", err)
			}
			return
		}
		if time.Now().After(deadline) {
			_, ws, ing := e.rec.Snapshot()
			t.Fatalf("did not converge: workloads=%+v ingress=%+v", ws, ing)
		}
		time.Sleep(time.Second)
	}
}

var noRedirect = &http.Client{
	Timeout:       5 * time.Second,
	Transport:     &http.Transport{Proxy: nil, DisableKeepAlives: true},
	CheckRedirect: func(*http.Request, []*http.Request) error { return http.ErrUseLastResponse },
}

func get(t *testing.T, c *http.Client, url, host string) (int, http.Header, string, error) {
	t.Helper()
	req, err := http.NewRequestWithContext(t.Context(), http.MethodGet, url, nil) //nolint:gosec // G704: tests call their own local endpoints
	if err != nil {
		t.Fatal(err)
	}
	req.Host = host
	resp, err := c.Do(req) //nolint:gosec // G704: tests call their own local endpoints
	if err != nil {
		return 0, nil, "", err
	}
	defer func() { _ = resp.Body.Close() }()
	b, _ := io.ReadAll(io.LimitReader(resp.Body, 1<<16))
	return resp.StatusCode, resp.Header, string(b), nil
}

func TestReconcileWorkloadAndIngress(t *testing.T) {
	e := setup(t)
	ns, wl := e.nodeState(t, 1, true, true)

	converge(t, e, ns, func() bool {
		_, _, ing := e.rec.Snapshot()
		return status(e.rec, wl).Phase == state.PhaseHealthy && ing != nil && ing.State == state.IngressApplied
	})
	st := status(e.rec, wl)
	t.Logf("workload status: %+v", st)

	// Port discovery through /proc (we share the daemon's PID namespace).
	if !slices.Contains(st.DetectedPorts, 8080) {
		t.Errorf("detected ports %v, want 8080", st.DetectedPorts)
	}

	// The container got the policy-floor defaults.
	ctx := t.Context()
	insp, err := e.api.ContainerInspect(ctx, st.ContainerID, client.ContainerInspectOptions{})
	if err != nil {
		t.Fatal(err)
	}
	hc := insp.Container.HostConfig
	if !slices.Contains(hc.CapDrop, "ALL") && !slices.Contains(hc.CapDrop, "CAP_ALL") {
		t.Errorf("CapDrop %v", hc.CapDrop)
	}
	if !slices.Contains(hc.SecurityOpt, policy.NoNewPrivileges) || hc.Init == nil || !*hc.Init || hc.Privileged {
		t.Errorf("security %v init %v privileged %v", hc.SecurityOpt, hc.Init, hc.Privileged)
	}
	if hc.LogConfig.Type != "json-file" || hc.LogConfig.Config["max-size"] != "10m" || hc.LogConfig.Config["max-file"] != "3" {
		t.Errorf("log config %+v", hc.LogConfig)
	}
	if hc.PidsLimit == nil || *hc.PidsLimit != 64 || hc.Memory != 64<<20 {
		t.Errorf("limits pids=%v mem=%d", hc.PidsLimit, hc.Memory)
	}
	if insp.Container.Config.Labels[docker.LabelManaged] != "true" || insp.Container.Config.Labels[docker.LabelServer] != string(e.serverID) {
		t.Errorf("labels %v", insp.Container.Config.Labels)
	}

	// Caddy: HTTP → HTTPS redirect, unknown hosts 404, HTTPS routed to the workload.
	code, hdr, _, err := get(t, noRedirect, "http://127.0.0.1:80/hello?x=1", testHost)
	if err != nil || code != http.StatusPermanentRedirect || hdr.Get("Location") != "https://"+testHost+"/hello?x=1" {
		t.Fatalf("http: %d %v %v", code, hdr, err)
	}
	if code, _, body, err := get(t, noRedirect, "http://127.0.0.1:80/", "unknown.localhost"); err != nil || code != 404 || !strings.Contains(body, "launchroom") {
		t.Fatalf("unknown host: %d %q %v", code, body, err)
	}

	tlsClient := &http.Client{Timeout: 10 * time.Second, Transport: &http.Transport{
		Proxy: nil, DisableKeepAlives: true,
		// Caddy's internal CA (dev/local mode); the root lives in its data volume.
		TLSClientConfig: &tls.Config{ServerName: testHost, InsecureSkipVerify: true, MinVersion: tls.VersionTLS12}, //nolint:gosec // internal CA in tests
		DialContext: func(ctx context.Context, network, _ string) (net.Conn, error) {
			var d net.Dialer
			return d.DialContext(ctx, network, "127.0.0.1:443")
		},
	}}
	var body string
	var hdrs http.Header
	deadline := time.Now().Add(90 * time.Second)
	for {
		code, h, b, err := get(t, tlsClient, "https://"+testHost+"/", testHost)
		if err == nil && code == 200 {
			body, hdrs = b, h
			break
		}
		if time.Now().After(deadline) {
			t.Fatalf("https: %d %q %v", code, b, err)
		}
		time.Sleep(time.Second)
	}
	if !strings.Contains(body, "Name: itest") || !strings.Contains(body, "Hostname:") {
		t.Errorf("whoami body (env not delivered?):\n%s", body)
	}
	if hdrs.Get("X-Robots-Tag") != "noindex" {
		t.Errorf("X-Robots-Tag = %q", hdrs.Get("X-Robots-Tag"))
	}

	// Reconcile is idempotent once converged.
	if err := e.rec.Reconcile(ctx, ns); err != nil {
		t.Fatal(err)
	}
	if again := status(e.rec, wl); again.ContainerID != st.ContainerID {
		t.Fatal("converged workload was recreated")
	}

	// Remove the workload: container and network go away, the route 404s.
	ns2, _ := e.nodeState(t, 2, false, true)
	converge(t, e, ns2, func() bool { return status(e.rec, wl).Phase == state.PhaseRemoved })
	cs, err := e.engine.ListContainers(ctx, map[string]string{docker.LabelWorkload: string(wl)})
	if err != nil || len(cs) != 0 {
		t.Fatalf("workload containers left: %v %v", cs, err)
	}
	nets, _ := e.engine.ListNetworks(ctx)
	if len(nets) != 0 {
		t.Fatalf("networks left: %v", nets)
	}
	if code, _, _, err := get(t, noRedirect, "http://127.0.0.1:80/", testHost); err != nil || code != 404 {
		t.Fatalf("removed route: %d %v", code, err)
	}

	// Disable Caddy: the system container is removed (its volumes stay).
	ns3, _ := e.nodeState(t, 3, false, false)
	if err := e.rec.Reconcile(ctx, ns3); err != nil {
		t.Fatal(err)
	}
	sys, _ := e.engine.ListContainers(ctx, map[string]string{docker.LabelSystem: policy.SystemCaddy})
	if len(sys) != 0 {
		t.Fatalf("caddy still running: %v", sys)
	}
	vols, _ := e.engine.ListVolumes(ctx)
	if len(vols) != 2 {
		t.Errorf("caddy volumes: %v", vols)
	}
}

func TestEngineRefusesUnmanagedObjects(t *testing.T) {
	e := setup(t)
	ctx := t.Context()
	// An object created without our labels (by "someone else").
	if _, err := e.api.VolumeCreate(ctx, client.VolumeCreateOptions{Name: "lr-v_foreign", Labels: map[string]string{"launchroom.dev": "true"}}); err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { _, _ = e.api.VolumeRemove(context.Background(), "lr-v_foreign", client.VolumeRemoveOptions{}) })
	labels := docker.ManagedLabels(string(e.serverID))
	err := e.engine.CreateVolume(ctx, docker.VolumeCreate{Name: "lr-v_foreign", Labels: labels})
	if !errors.Is(err, docker.ErrNotManaged) {
		t.Fatalf("adopted a foreign volume: %v", err)
	}
	vols, _ := e.engine.ListVolumes(ctx)
	if len(vols) != 0 {
		t.Fatalf("foreign volume listed: %v", vols)
	}
}
