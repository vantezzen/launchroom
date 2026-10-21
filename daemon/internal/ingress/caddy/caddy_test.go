package caddy

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"flag"
	"net"
	"net/http"
	"net/http/httptest"
	"net/netip"
	"os"
	"path/filepath"
	"regexp"
	"slices"
	"strings"
	"sync"
	"testing"

	"github.com/vantezzen/launchroom/daemon/internal/docker"
	"github.com/vantezzen/launchroom/daemon/internal/docker/dockerfake"
	"github.com/vantezzen/launchroom/daemon/internal/policy"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

var update = flag.Bool("update", false, "rewrite golden files")

const (
	srvID = "srv_01J9Z8X7W6V5T4S3R2Q1P0N9MK"
	wlA   = state.WorkloadID("wl_01J9Z8X7W6V5T4S3R2Q1P0N9N7")
	wlB   = state.WorkloadID("wl_01J9Z8X7W6V5T4S3R2Q1P0N9N8")
	wlC   = state.WorkloadID("wl_01J9Z8X7W6V5T4S3R2Q1P0N9N9")
)

var renderOpts = Options{AdminListen: "127.0.0.1:2019", AskURL: "http://127.0.0.1:2021/caddy/ask", HTTPPort: 80, HTTPSPort: 443, RedirectHTTPSPort: 443}

func golden(t *testing.T, name string, got []byte) {
	t.Helper()
	path := filepath.Join("testdata", name+".golden.json")
	if *update {
		if err := os.MkdirAll("testdata", 0o750); err != nil {
			t.Fatal(err)
		}
		if err := os.WriteFile(path, got, 0o644); err != nil {
			t.Fatal(err)
		}
	}
	want, err := os.ReadFile(path)
	if err != nil {
		t.Fatalf("%v (run with -update)", err)
	}
	if !bytes.Equal(got, want) {
		t.Fatalf("%s differs from golden file (run `go test ./internal/ingress/caddy -update` after checking):\n%s", name, got)
	}
}

func routeSpecs() []state.RouteSpec {
	return []state.RouteSpec{
		{
			ID: "rt_01J9Z8X7W6V5T4S3R2Q1P0N9N9", Hosts: []state.Hostname{"shop.example.com", "www.shop.example.com"}, TLS: state.TLSACME,
			RedirectWWWTo: "shop.example.com",
			Upstreams: []state.RouteUpstream{
				{Workload: wlA, Port: 3000}, {Workload: wlB, Port: 3000}, {Address: "100.64.0.9:31002"},
			},
			Headers: map[state.HeaderName]string{"X-Robots-Tag": "noindex", "Server": ""},
		},
		{
			ID: "rt_01J9Z8X7W6V5T4S3R2Q1P0N9NA", Hosts: []state.Hostname{"custom.example.org"}, TLS: state.TLSOnDemand,
			Upstreams: []state.RouteUpstream{{Workload: wlC, Port: 8080}},
		},
		{
			ID: "rt_01J9Z8X7W6V5T4S3R2Q1P0N9NB", Hosts: []state.Hostname{"web.localhost"}, TLS: state.TLSInternal,
			Upstreams: []state.RouteUpstream{{Workload: wlA, Port: 3000}},
		},
	}
}

func resolver(eps map[state.WorkloadID]Endpoint) Resolver {
	return func(id state.WorkloadID) (Endpoint, bool) { ep, ok := eps[id]; return ep, ok }
}

func TestResolveRoutes(t *testing.T) {
	ip := netip.MustParseAddr
	routes := ResolveRoutes(routeSpecs(), resolver(map[state.WorkloadID]Endpoint{
		wlA: {Addr: ip("10.231.4.5"), Serving: true, Ready: true},
		wlB: {Addr: ip("10.231.4.6"), Serving: false, Ready: true}, // draining: not used while A is ready
		wlC: {Addr: ip("10.231.5.2"), Serving: true, Ready: false}, // not healthy: no upstream → 503
	}))
	if got := routes[0].Dial; !slices.Equal(got, []string{"10.231.4.5:3000", "100.64.0.9:31002"}) {
		t.Errorf("route 0: %v", got)
	}
	if len(routes[1].Dial) != 0 {
		t.Errorf("route 1: %v", routes[1].Dial)
	}
	// Draining fallback when no serving workload is ready.
	routes = ResolveRoutes(routeSpecs()[:1], resolver(map[state.WorkloadID]Endpoint{
		wlA: {Addr: ip("10.231.4.5"), Serving: true, Ready: false},
		wlB: {Addr: ip("10.231.4.6"), Serving: false, Ready: true},
	}))
	if got := routes[0].Dial; !slices.Equal(got, []string{"10.231.4.6:3000", "100.64.0.9:31002"}) {
		t.Errorf("fallback: %v", got)
	}
}

func TestRenderGolden(t *testing.T) {
	ip := netip.MustParseAddr
	routes := ResolveRoutes(routeSpecs(), resolver(map[state.WorkloadID]Endpoint{
		wlA: {Addr: ip("10.231.4.5"), Serving: true, Ready: true},
		wlB: {Addr: ip("10.231.4.6"), Serving: true, Ready: true},
	}))
	cfg, err := Render(routes, renderOpts)
	if err != nil {
		t.Fatal(err)
	}
	golden(t, "routes", cfg)

	empty, err := Render(nil, renderOpts)
	if err != nil {
		t.Fatal(err)
	}
	golden(t, "empty", empty)

	dev := renderOpts
	dev.RedirectHTTPSPort = 8443
	devCfg, err := Render(routes[2:], dev)
	if err != nil {
		t.Fatal(err)
	}
	golden(t, "dev-port", devCfg)
	if !bytes.Contains(devCfg, []byte(`https://{http.request.host}:8443{http.request.uri}`)) {
		t.Error("dev redirect port missing")
	}
	var anyCfg map[string]any
	if err := json.Unmarshal(cfg, &anyCfg); err != nil {
		t.Fatal(err)
	}
}

func TestRenderProtected(t *testing.T) {
	opts := renderOpts
	opts.ForwardAuthOrigins = []string{"http://dashboard:8000", "https://dash.example.com:443", "http://127.0.0.1:8000"}
	protected := func(id state.RouteID, host state.Hostname, fa state.ForwardAuth) Route {
		return Route{Spec: state.RouteSpec{
			ID: id, Hosts: []state.Hostname{host}, TLS: state.TLSACME,
			Upstreams:  []state.RouteUpstream{{Workload: wlA, Port: 3000}},
			Headers:    map[state.HeaderName]string{"X-Robots-Tag": "noindex"},
			Protection: &state.RouteProtection{ForwardAuth: fa},
		}, Dial: []string{"10.231.4.5:3000"}}
	}
	cases := map[string]Route{
		"protected-http": protected("rt_01J9Z8X7W6V5T4S3R2Q1P0N9NC", "pr-1.preview.localhost",
			state.ForwardAuth{URL: "http://dashboard:8000/auth/preview"}),
		"protected-https": protected("rt_01J9Z8X7W6V5T4S3R2Q1P0N9ND", "pr-2.preview.example.com",
			state.ForwardAuth{URL: "https://dash.example.com/auth/preview", StripHeaders: []state.HeaderName{"X-Launchroom-Bypass"}}),
		"protected-refused": protected("rt_01J9Z8X7W6V5T4S3R2Q1P0N9NE", "pr-3.preview.example.com",
			state.ForwardAuth{URL: "http://169.254.169.254/auth/preview"}),
	}
	for name, r := range cases {
		cfg, err := Render([]Route{r}, opts)
		if err != nil {
			t.Fatal(err)
		}
		golden(t, name, cfg)
		refused := RefusedForwardAuth([]Route{r}, opts)
		if name == "protected-refused" {
			if !slices.Equal(refused, []state.RouteID{r.Spec.ID}) {
				t.Errorf("refused = %v", refused)
			}
			if bytes.Contains(cfg, []byte("10.231.4.5")) || !bytes.Contains(cfg, []byte("403")) {
				t.Error("refused route still proxies to the app")
			}
		} else if len(refused) != 0 {
			t.Errorf("%s: refused %v", name, refused)
		}
	}

	// Without allowed origins every protected route fails closed, also with an
	// https IP literal (no server_name) that would otherwise be fine.
	ip := protected("rt_01J9Z8X7W6V5T4S3R2Q1P0N9NF", "pr-4.preview.example.com", state.ForwardAuth{URL: "https://10.0.0.1:8443/auth/preview"})
	if got := RefusedForwardAuth([]Route{ip}, renderOpts); len(got) != 1 {
		t.Errorf("refused without origins: %v", got)
	}
	opts.ForwardAuthOrigins = []string{"https://10.0.0.1:8443"}
	cfg, err := Render([]Route{ip}, opts)
	if err != nil {
		t.Fatal(err)
	}
	if !bytes.Contains(cfg, []byte(`"dial": "10.0.0.1:8443"`)) || bytes.Contains(cfg, []byte("server_name")) {
		t.Errorf("ip literal transport:\n%s", cfg)
	}
	// An invalid URL (validated NodeStates never carry one) also fails closed.
	bad := protected("rt_01J9Z8X7W6V5T4S3R2Q1P0N9NF", "pr-4.preview.example.com", state.ForwardAuth{URL: "https://10.0.0.1:8443/other"})
	if got := RefusedForwardAuth([]Route{bad}, opts); len(got) != 1 {
		t.Errorf("invalid url not refused: %v", got)
	}
}

func TestRenderRejects(t *testing.T) {
	if _, err := Render(nil, Options{AdminListen: "0.0.0.0:2019", AskURL: "x"}); err == nil {
		t.Error("public admin accepted")
	}
	if _, err := Render(nil, Options{}); err == nil {
		t.Error("missing ask URL accepted")
	}
	dup := []Route{{Spec: routeSpecs()[1]}, {Spec: routeSpecs()[1]}}
	if _, err := Render(dup, renderOpts); err == nil {
		t.Error("duplicate host accepted")
	}
}

func TestAsk(t *testing.T) {
	a := &Ask{}
	srv := httptest.NewServer(a)
	defer srv.Close()
	get := func(domain string) int {
		resp, err := http.Get(srv.URL + AskPath + "?domain=" + domain) //nolint:noctx // test
		if err != nil {
			t.Fatal(err)
		}
		_ = resp.Body.Close()
		return resp.StatusCode
	}
	if get("custom.example.org") != 404 {
		t.Error("allowed before hosts are known")
	}
	a.SetHosts([]string{"custom.example.org"})
	if get("custom.example.org") != 200 || get("CUSTOM.example.org.") != 200 {
		t.Error("routed host refused")
	}
	if get("evil.example.org") != 404 {
		t.Error("unknown host allowed")
	}

	ln, err := (&net.ListenConfig{}).Listen(t.Context(), "tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	ctx, cancel := context.WithCancel(t.Context())
	done := make(chan error, 1)
	go func() { done <- a.ServeListener(ctx, ln) }()
	cancel()
	if err := <-done; err != nil {
		t.Fatal(err)
	}
}

type fakeAdmin struct {
	mu    sync.Mutex
	loads [][]byte
	err   error
}

func (f *fakeAdmin) Ready(context.Context) error { return nil }
func (f *fakeAdmin) Load(_ context.Context, cfg []byte) error {
	f.mu.Lock()
	defer f.mu.Unlock()
	if f.err != nil {
		return f.err
	}
	f.loads = append(f.loads, cfg)
	return nil
}

func TestManagerLifecycle(t *testing.T) {
	ctx := t.Context()
	d := dockerfake.New(srvID)
	admin := &fakeAdmin{}
	ask := &Ask{}
	image := state.ImageRef("docker.io/library/caddy@" + policy.DefaultSystemImageDigests[policy.SystemCaddy][0])
	dir := t.TempDir()
	m := NewManager(ManagerOptions{ServerID: srvID, Docker: d, Admin: admin, Policy: policy.DefaultConfig(), Render: renderOpts, CacheDir: dir, Ask: ask})
	routes := ResolveRoutes(routeSpecs()[1:2], resolver(nil))

	st := m.Sync(ctx, state.SystemContainer{Enabled: true, Image: image}, routes)
	if st == nil || st.State != state.IngressApplied || st.Routes != 1 {
		t.Fatalf("status %+v", st)
	}
	calls := d.Calls()
	for _, want := range []string{"create-volume " + DataVolume, "pull " + string(image), "create " + ContainerName, "start " + ContainerName} {
		if !slices.Contains(calls, want) {
			t.Errorf("missing %q in %v", want, calls)
		}
	}
	cc, _ := d.Create(ContainerName)
	if cc.HostConfig.NetworkMode != "host" || !slices.Contains(cc.HostConfig.CapDrop, "ALL") || cc.HostConfig.Privileged {
		t.Errorf("container spec: %+v", cc.HostConfig)
	}
	if !ask.Allowed("custom.example.org") {
		t.Error("ask not updated")
	}
	if b, err := os.ReadFile(filepath.Join(dir, CacheFile)); err != nil || !bytes.Equal(b, admin.loads[0]) {
		t.Errorf("cache: %v", err)
	}

	// Unchanged → no reload, no docker calls.
	m.Sync(ctx, state.SystemContainer{Enabled: true, Image: image}, routes)
	if len(admin.loads) != 1 || len(d.Calls()) != 0 {
		t.Fatalf("reloaded unchanged config (%d loads)", len(admin.loads))
	}
	// Changed routes → reload.
	m.Sync(ctx, state.SystemContainer{Enabled: true, Image: image}, nil)
	if len(admin.loads) != 2 {
		t.Fatal("changed config not loaded")
	}
	// Load failure is reported.
	admin.err = errors.New("boom")
	st = m.Sync(ctx, state.SystemContainer{Enabled: true, Image: image}, routes)
	if st.State != state.IngressFailed || !strings.Contains(st.Message, "boom") {
		t.Fatalf("%+v", st)
	}
	admin.err = nil

	// Disabled → container removed (volumes kept).
	if st := m.Sync(ctx, state.SystemContainer{}, nil); st != nil {
		t.Fatalf("%+v", st)
	}
	cs, _ := d.ListContainers(ctx, nil)
	if len(cs) != 0 {
		t.Fatalf("containers left: %v", cs)
	}
	vs, _ := d.ListVolumes(ctx)
	if len(vs) != 2 {
		t.Fatalf("volumes: %v", vs)
	}
	if ask.Allowed("custom.example.org") {
		t.Error("ask still allows hosts")
	}
}

// An unpinned image is refused before anything runs.
func TestManagerRefusesUnpinnedImage(t *testing.T) {
	d := dockerfake.New(srvID)
	m := NewManager(ManagerOptions{ServerID: srvID, Docker: d, Admin: &fakeAdmin{}, Policy: policy.DefaultConfig(), Render: renderOpts, CacheDir: t.TempDir(), Ask: &Ask{}})
	bad := state.ImageRef("docker.io/library/caddy@sha256:" + strings.Repeat("b", 64))
	d.AddImage(string(bad))
	st := m.Sync(t.Context(), state.SystemContainer{Enabled: true, Image: bad}, ResolveRoutes(routeSpecs()[1:2], resolver(nil)))
	if st == nil || st.State != state.IngressFailed || !strings.Contains(st.Message, "pinned") {
		t.Fatalf("%+v", st)
	}
	if cs, _ := d.ListContainers(t.Context(), nil); len(cs) != 0 {
		t.Fatalf("containers created: %v", cs)
	}
}

// An edge still on the previous launchroomd is sent the next release's Caddy
// (the dashboard updates first, docs/11 §4): it keeps its pinned Caddy and
// still applies the routes, then switches once the binary pins the new one.
func TestManagerKeepsPinnedImageForUnpinnedRequest(t *testing.T) {
	ctx := t.Context()
	d := dockerfake.New(srvID)
	admin := &fakeAdmin{}
	ask := &Ask{}
	current := state.ImageRef("ghcr.io/vantezzen/launchroom-caddy@sha256:" + strings.Repeat("1", 64))
	next := state.ImageRef("ghcr.io/vantezzen/launchroom-caddy@sha256:" + strings.Repeat("2", 64))
	d.AddImage(string(current))
	d.AddImage(string(next))
	pol := policy.DefaultConfig()
	pol.SystemImageDigests[policy.SystemCaddy] = []string{strings.TrimPrefix(string(current), "ghcr.io/vantezzen/launchroom-caddy@")}
	m := NewManager(ManagerOptions{ServerID: srvID, Docker: d, Admin: admin, Policy: pol, Render: renderOpts, CacheDir: t.TempDir(), Ask: ask})
	routes := ResolveRoutes(routeSpecs()[1:2], resolver(nil))

	if st := m.Sync(ctx, state.SystemContainer{Enabled: true, Image: current}, routes); st.State != state.IngressApplied {
		t.Fatalf("%+v", st)
	}
	before, _ := d.ListContainers(ctx, nil)

	more := ResolveRoutes(routeSpecs()[0:2], resolver(nil))
	st := m.Sync(ctx, state.SystemContainer{Enabled: true, Image: next}, more)
	if st.State != state.IngressApplied || st.Routes != len(more) {
		t.Fatalf("routes not applied with the kept image: %+v", st)
	}
	cs, _ := d.ListContainers(ctx, nil)
	if len(cs) != 1 || len(before) != 1 || cs[0].Image != string(current) || cs[0].ID != before[0].ID {
		t.Fatalf("caddy was replaced: %+v", cs)
	}
	if len(admin.loads) != 2 {
		t.Fatalf("%d loads, want 2", len(admin.loads))
	}

	// The self-updated binary pins the new image: it replaces the old one.
	m.o.Policy.SystemImageDigests[policy.SystemCaddy] = []string{strings.TrimPrefix(string(next), "ghcr.io/vantezzen/launchroom-caddy@")}
	if st := m.Sync(ctx, state.SystemContainer{Enabled: true, Image: next}, more); st.State != state.IngressApplied {
		t.Fatalf("%+v", st)
	}
	cs, _ = d.ListContainers(ctx, nil)
	if len(cs) != 1 || cs[0].Image != string(next) {
		t.Fatalf("not switched to the new image: %+v", cs)
	}

	// Neither image pinned: refused, nothing changes.
	m.o.Policy.SystemImageDigests[policy.SystemCaddy] = nil
	if st := m.Sync(ctx, state.SystemContainer{Enabled: true, Image: current}, more); st.State != state.IngressFailed {
		t.Fatalf("%+v", st)
	}
	cs, _ = d.ListContainers(ctx, nil)
	if len(cs) != 1 || cs[0].Image != string(next) {
		t.Fatalf("containers changed: %+v", cs)
	}
}

func TestManagerNeverAdoptsUnmanagedVolume(t *testing.T) {
	d := dockerfake.New(srvID)
	d.AddUnmanagedVolume(DataVolume)
	image := state.ImageRef("docker.io/library/caddy@" + policy.DefaultSystemImageDigests[policy.SystemCaddy][0])
	m := NewManager(ManagerOptions{ServerID: srvID, Docker: d, Admin: &fakeAdmin{}, Policy: policy.DefaultConfig(), Render: renderOpts})
	st := m.Sync(t.Context(), state.SystemContainer{Enabled: true, Image: image}, nil)
	if st.State != state.IngressFailed || !strings.Contains(st.Message, docker.ErrNotManaged.Error()) {
		t.Fatalf("%+v", st)
	}
}

// 2026-10 review L8: after a good forward-auth answer the preview cookie is
// removed from the request to the app; the app's own cookies stay.
func TestForwardAuthStripsPreviewCookie(t *testing.T) {
	h := forwardAuthHandler(state.ForwardAuth{URL: "http://dashboard:8000/auth/preview", StripHeaders: []state.HeaderName{"X-Launchroom-Bypass"}})
	raw, err := json.Marshal(h)
	if err != nil {
		t.Fatal(err)
	}
	var parsed struct {
		HandleResponse []struct {
			Routes []struct {
				Handle []struct {
					Handler string `json:"handler"`
					Request struct {
						Delete  []string `json:"delete"`
						Replace map[string][]struct {
							Search  string `json:"search_regexp"`
							Replace string `json:"replace"`
						} `json:"replace"`
					} `json:"request"`
				} `json:"handle"`
			} `json:"routes"`
		} `json:"handle_response"`
	}
	if err := json.Unmarshal(raw, &parsed); err != nil {
		t.Fatal(err)
	}
	var reps []struct {
		Search  string `json:"search_regexp"`
		Replace string `json:"replace"`
	}
	var deleted []string
	for _, r := range parsed.HandleResponse[0].Routes {
		for _, hd := range r.Handle {
			if hd.Handler == "headers" {
				reps = hd.Request.Replace["Cookie"]
				deleted = hd.Request.Delete
			}
		}
	}
	if len(reps) == 0 || !slices.Equal(deleted, []string{"X-Launchroom-Bypass"}) {
		t.Fatalf("no cookie strip / header delete in %s", raw)
	}
	apply := func(v string) string {
		for _, r := range reps {
			v = regexp.MustCompile(r.Search).ReplaceAllString(v, r.Replace)
		}
		return v
	}
	for in, want := range map[string]string{
		"__Host-lr-preview=abc":                         "",
		"__Host-lr-preview=abc; session=1":              "session=1",
		"session=1; __Host-lr-preview=abc":              "session=1",
		"a=1; __Host-lr-preview=abc; b=2":               "a=1; b=2",
		"a=1;__Host-lr-preview=abc;b=2":                 "a=1;b=2",
		"x__Host-lr-preview=keep; a=1":                  "x__Host-lr-preview=keep; a=1",
		"a=1; b=2":                                      "a=1; b=2",
		"__Host-lr-preview=a; __Host-lr-preview=b; c=3": "c=3",
	} {
		if got := apply(in); got != want {
			t.Errorf("Cookie %q → %q, want %q", in, got, want)
		}
	}
}

func TestRenderAccessLog(t *testing.T) {
	routes := ResolveRoutes(routeSpecs(), resolver(map[state.WorkloadID]Endpoint{}))
	o := renderOpts
	o.AccessLogAddress = "127.0.0.1:2022"
	cfg, err := Render(routes, o)
	if err != nil {
		t.Fatal(err)
	}
	golden(t, "access-log", cfg)
	var parsed struct {
		Logging struct {
			Logs map[string]struct {
				Writer  map[string]any `json:"writer"`
				Encoder struct {
					Format string                    `json:"format"`
					Fields map[string]map[string]any `json:"fields"`
				} `json:"encoder"`
				Include []string `json:"include"`
				Exclude []string `json:"exclude"`
			} `json:"logs"`
		} `json:"logging"`
		Apps struct {
			HTTP struct {
				Servers map[string]map[string]any `json:"servers"`
			} `json:"http"`
		} `json:"apps"`
	}
	if err := json.Unmarshal(cfg, &parsed); err != nil {
		t.Fatal(err)
	}
	acc, def := parsed.Logging.Logs[AccessLogger], parsed.Logging.Logs["default"]
	if acc.Writer["output"] != "net" || acc.Writer["address"] != "tcp/127.0.0.1:2022" || acc.Writer["soft_start"] != true {
		t.Errorf("access writer = %v", acc.Writer)
	}
	if acc.Encoder.Format != "filter" || acc.Encoder.Fields["request>headers"]["filter"] != "delete" || acc.Encoder.Fields["request>remote_ip"]["filter"] != "delete" || acc.Encoder.Fields["request>uri"]["filter"] != "delete" {
		t.Errorf("access encoder = %+v", acc.Encoder)
	}
	if !slices.Equal(acc.Include, []string{"http.log.access.lr-access"}) || !slices.Equal(def.Exclude, acc.Include) || def.Writer["output"] != "stderr" {
		t.Errorf("include/exclude = %v / %v", acc.Include, def.Exclude)
	}
	if logs, _ := parsed.Apps.HTTP.Servers[serverHTTPS]["logs"].(map[string]any); logs["default_logger_name"] != AccessLogger {
		t.Errorf("https logs = %v", parsed.Apps.HTTP.Servers[serverHTTPS]["logs"])
	}
	if _, ok := parsed.Apps.HTTP.Servers[serverHTTP]["logs"]; ok {
		t.Error("the plain HTTP redirect server must not be logged")
	}

	// Without the address the config is unchanged (no logging section).
	plain, _ := Render(routes, renderOpts)
	if bytes.Contains(plain, []byte(`"logging"`)) {
		t.Error("logging rendered without an access log address")
	}
	o.AccessLogAddress = "0.0.0.0:2022"
	if _, err := Render(routes, o); err == nil {
		t.Error("non-loopback access log address accepted")
	}
}

// The dashboard's system routes on the control-plane server (docs/05 §4.1):
// one acme route with the canonical, legacy and pending hosts and one
// internal-CA route for *.localhost hosts, both to the loopback dashboard.
func TestRenderDashboardRoutes(t *testing.T) {
	specs := []state.RouteSpec{
		{
			ID: "rt_0000000000000000000000DASH", Hosts: []state.Hostname{"dash.example.com", "1-2-3-4.sslip.io"}, TLS: state.TLSACME,
			Upstreams: []state.RouteUpstream{{Address: "127.0.0.1:8000"}},
		},
		{
			ID: "rt_0000000000000000000001DASH", Hosts: []state.Hostname{"dash.localhost"}, TLS: state.TLSInternal,
			Upstreams: []state.RouteUpstream{{Address: "127.0.0.1:8000"}},
		},
	}
	routes := ResolveRoutes(specs, resolver(nil))
	cfg, err := Render(routes, renderOpts)
	if err != nil {
		t.Fatal(err)
	}
	golden(t, "dashboard", cfg)
	for _, want := range []string{`"dash.example.com"`, `"1-2-3-4.sslip.io"`, `"dash.localhost"`, `"127.0.0.1:8000"`} {
		if !bytes.Contains(cfg, []byte(want)) {
			t.Errorf("config lacks %s", want)
		}
	}
	if got := Hosts(routes); len(got) != 3 {
		t.Errorf("ask hosts = %v", got)
	}
}
