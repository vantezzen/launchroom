package caddy

import (
	"archive/tar"
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net"
	"net/netip"
	"os"
	"path"
	"path/filepath"
	"regexp"
	"slices"
	"strconv"
	"strings"
	"sync"
	"testing"
	"time"

	"github.com/vantezzen/launchroom/daemon/internal/docker/dockerfake"
	"github.com/vantezzen/launchroom/daemon/internal/policy"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

var wafOpts = func() Options {
	o := renderOpts
	o.Waf = &WafOptions{LogAddress: "127.0.0.1:2023"}
	return o
}()

// wafFixtureRoutes are the routes of protocol/fixtures/v1/node-state.waf.json.
func wafFixtureRoutes(t *testing.T) []Route {
	t.Helper()
	data, err := os.ReadFile(filepath.Join("..", "..", "..", "..", "protocol", "fixtures", "v1", "node-state.waf.json"))
	if err != nil {
		t.Fatal(err)
	}
	ns, err := state.DecodeNodeState(data)
	if err != nil {
		t.Fatal(err)
	}
	return ResolveRoutes(ns.Routes, resolver(map[state.WorkloadID]Endpoint{
		"wl_01J9Z8X7W6V5T4S3R2Q1P0N9N7": {Addr: netip.MustParseAddr("10.231.4.5"), Serving: true, Ready: true},
	}))
}

// wafRendered collects the firewall handlers of a rendered config: per
// HTTPS route id, its directive string.
func wafRendered(t *testing.T, cfg []byte) map[string]string {
	t.Helper()
	var parsed struct {
		Apps struct {
			HTTP struct {
				Servers map[string]struct {
					Routes []struct {
						ID     string `json:"@id"`
						Handle []struct {
							Routes []struct {
								Handle []map[string]any `json:"handle"`
							} `json:"routes"`
						} `json:"handle"`
					} `json:"routes"`
				} `json:"servers"`
			} `json:"http"`
		} `json:"apps"`
	}
	if err := json.Unmarshal(cfg, &parsed); err != nil {
		t.Fatal(err)
	}
	out := map[string]string{}
	for _, r := range parsed.Apps.HTTP.Servers[serverHTTPS].Routes {
		for _, h := range r.Handle {
			for i, sr := range h.Routes {
				for _, hh := range sr.Handle {
					if hh["handler"] != "waf" {
						continue
					}
					if hh["load_owasp_crs"] != true {
						t.Errorf("%s: the firewall must load the embedded CRS", r.ID)
					}
					// The route header is set right before and removed right after.
					before, after := h.Routes[i-1].Handle[0], h.Routes[i+1].Handle[0]
					set, _ := before["request"].(map[string]any)["set"].(map[string]any)
					del, _ := after["request"].(map[string]any)["delete"].([]any)
					if v, _ := set[WafRouteHeader].([]any); len(v) != 1 || v[0] != strings.TrimPrefix(r.ID, "lr-route-") || len(del) != 1 || del[0] != WafRouteHeader {
						t.Errorf("%s: route header around the firewall: %v / %v", r.ID, before, after)
					}
					out[strings.TrimPrefix(r.ID, "lr-route-")], _ = hh["directives"].(string)
				}
			}
		}
	}
	return out
}

func TestRenderWafGolden(t *testing.T) {
	routes := wafFixtureRoutes(t)
	cfg, err := Render(routes, wafOpts)
	if err != nil {
		t.Fatal(err)
	}
	golden(t, "waf", cfg)

	got := wafRendered(t, cfg)
	if len(got) != 2 {
		t.Fatalf("firewalled routes = %v", got)
	}
	dirs := got["rt_01J9Z8X7W6V5T4S3R2Q1P0N9N9"]
	if dirs == "" || got["rt_01J9Z8X7W6V5T4S3R2Q1P0N9NA"] != dirs {
		t.Error("the directive string must be identical for every route of the edge (one shared rule set)")
	}
	for _, want := range []string{
		"SecRuleEngine On\n",
		"SecAuditLog http://127.0.0.1:2023/waf\n",
		"SecAuditLogParts AKZ\n",
		`SecRule REQUEST_HEADERS:X-Lr-Waf-Route "@streq rt_01J9Z8X7W6V5T4S3R2Q1P0N9N9" "id:10003,phase:1,pass,nolog,chain"` + "\n" +
			`  SecRule REQUEST_FILENAME "@beginsWith /api/search" "ctl:ruleRemoveTargetById=942100;ARGS:q"` + "\n",
		`SecRule REQUEST_HEADERS:X-Lr-Waf-Route "@streq rt_01J9Z8X7W6V5T4S3R2Q1P0N9N9" "id:10004,phase:1,pass,nolog,ctl:ruleRemoveById=920350"` + "\n",
		`SecRule REQUEST_HEADERS:X-Lr-Waf-Route "@streq rt_01J9Z8X7W6V5T4S3R2Q1P0N9NA" "id:10005,phase:1,pass,nolog,ctl:ruleEngine=DetectionOnly"` + "\n",
		`SecRule REQUEST_HEADERS:X-Lr-Waf-Route "@streq rt_01J9Z8X7W6V5T4S3R2Q1P0N9NA" "id:10006,phase:1,pass,nolog,ctl:requestBodyLimit=131072"` + "\n",
		"SecRequestBodyLimit 1048576\n",
		"SecArgumentsLimit 256\n",
		`SecAction "id:10002,phase:1,pass,nolog,ctl:requestBodyLimit=32768"` + "\n",
		`SecRule REQUEST_HEADERS:X-Lr-Waf-Route "@streq rt_01J9Z8X7W6V5T4S3R2Q1P0N9NA" "id:10007,phase:1,pass,nolog,setvar:tx.blocking_paranoia_level=2"` + "\n",
	} {
		if !strings.Contains(dirs, want) {
			t.Errorf("directives lack %q:\n%s", want, dirs)
		}
	}
	// CRS's setup comes first, its rules last, the per-route rules between.
	if i, j, k := strings.Index(dirs, "Include @crs-setup.conf.example"), strings.Index(dirs, "@streq"), strings.Index(dirs, "Include @owasp_crs/*.conf"); i < 0 || i > j || j > k || !strings.HasSuffix(dirs, "Include @owasp_crs/*.conf\n") {
		t.Errorf("directive order:\n%s", dirs)
	}
	// Every rule id is unique and in the local range.
	ids := regexp.MustCompile(`"id:(\d+),`).FindAllStringSubmatch(dirs, -1)
	seen := map[int]bool{}
	for _, m := range ids {
		n, _ := strconv.Atoi(m[1])
		if n < wafLocalRuleMin || n > wafLocalRuleMax || seen[n] {
			t.Errorf("rule id %d duplicate or outside the local range", n)
		}
		seen[n] = true
	}
	// The firewall's own log lines carry client addresses and matched data.
	if !bytes.Contains(cfg, []byte(`"http.handlers.waf"`)) {
		t.Error("the firewall logger is not excluded from Caddy's log")
	}
	// Blocked requests get the firewall page with the reference id, only on firewalled hosts.
	if !bytes.Contains(cfg, []byte("This request was blocked by the web firewall")) || !bytes.Contains(cfg, []byte("Reference {http.error.id}")) {
		t.Error("no blocked page")
	}
}

func TestRenderWafMixed(t *testing.T) {
	o := wafOpts
	o.ForwardAuthOrigins = []string{"http://dashboard:8000"}
	o.LocalUpstreams = []string{"127.0.0.1:8000"}
	block := &state.RouteWaf{Mode: state.WafModeBlock}
	routes := []Route{
		{Spec: state.RouteSpec{
			ID: "rt_01J9Z8X7W6V5T4S3R2Q1P0N9NC", Hosts: []state.Hostname{"pr-1.preview.localhost"}, TLS: state.TLSACME,
			Upstreams:  []state.RouteUpstream{{Workload: wlA, Port: 3000}},
			Protection: &state.RouteProtection{ForwardAuth: state.ForwardAuth{URL: "http://dashboard:8000/auth/preview"}},
			Waf:        block,
		}, Dial: []string{"10.231.4.5:3000"}},
		// The dashboard's system route never gets the firewall, nor a route with clientAuth.
		{Spec: state.RouteSpec{
			ID: "rt_0000000000000000000000DASH", Hosts: []state.Hostname{"dash.example.com"}, TLS: state.TLSACME,
			Upstreams:  []state.RouteUpstream{{Address: "127.0.0.1:8000"}},
			ClientAuth: &state.RouteClientAuth{Mode: state.ClientAuthVerify},
			Waf:        block,
		}, EdgeSecretFile: EdgeSecretFile("rt_0000000000000000000000DASH")},
		{Spec: state.RouteSpec{
			ID: "rt_0000000000000000000001DASH", Hosts: []state.Hostname{"dash.localhost"}, TLS: state.TLSInternal,
			Upstreams: []state.RouteUpstream{{Address: "127.0.0.1:8000"}},
			Waf:       block,
		}},
		// Off: no firewall.
		{Spec: state.RouteSpec{
			ID: "rt_01J9Z8X7W6V5T4S3R2Q1P0N9ND", Hosts: []state.Hostname{"plain.example.com"}, TLS: state.TLSACME,
			Upstreams: []state.RouteUpstream{{Workload: wlA, Port: 3000}},
		}, Dial: []string{"10.231.4.5:3000"}},
	}
	cfg, err := Render(routes, o)
	if err != nil {
		t.Fatal(err)
	}
	golden(t, "waf-mixed", cfg)
	got := wafRendered(t, cfg)
	if len(got) != 1 || got["rt_01J9Z8X7W6V5T4S3R2Q1P0N9NC"] == "" {
		t.Fatalf("firewalled routes = %v", got)
	}
	// Before forward auth: the preview's auth check never sees a request the firewall refused.
	sub := string(cfg[bytes.Index(cfg, []byte(`"lr-route-rt_01J9Z8X7W6V5T4S3R2Q1P0N9NC"`)):])
	if w, fa := strings.Index(sub, `"handler": "waf"`), strings.Index(sub, state.ForwardAuthPath); w < 0 || fa < 0 || w > fa {
		t.Error("the firewall must run before forward auth")
	}
	if ids := WafRoutes(routes, o); len(ids) != 1 {
		t.Errorf("WafRoutes = %v", ids)
	}
}

// docs/22 §6.6: without the module, or when Caddy refused the firewall, the
// same routes are served without it.
func TestRenderWafFallback(t *testing.T) {
	routes := wafFixtureRoutes(t)
	without, err := Render(routes, renderOpts)
	if err != nil {
		t.Fatal(err)
	}
	golden(t, "waf-fallback", without)
	stripped := slices.Clone(routes)
	for i := range stripped {
		stripped[i].Spec.Waf = nil
	}
	plain, err := Render(stripped, renderOpts)
	if err != nil {
		t.Fatal(err)
	}
	if !bytes.Equal(without, plain) {
		t.Error("the fallback differs from the routes without firewall settings")
	}
	if bytes.Contains(without, []byte(`"waf"`)) || bytes.Contains(without, []byte(WafRouteHeader)) {
		t.Error("firewall rendered without WafOptions")
	}
	// The module on, no route asking for it: nothing but the log exclusion.
	none, err := Render(stripped, wafOpts)
	if err != nil {
		t.Fatal(err)
	}
	if bytes.Contains(none, []byte(`"handler": "waf"`)) || bytes.Contains(none, []byte("blocked by the web firewall")) {
		t.Error("firewall rendered for routes that are off")
	}
}

func TestWafDirectivesRefuseInvalidValues(t *testing.T) {
	base := func(w state.RouteWaf) []Route {
		return []Route{{Spec: state.RouteSpec{ID: "rt_01J9Z8X7W6V5T4S3R2Q1P0N9NC", Waf: &w}}}
	}
	cases := map[string][]Route{
		"evaluation rule":  base(state.RouteWaf{Mode: state.WafModeBlock, Exclusions: []state.WafExclusion{{Rule: 949110}}}),
		"init rule":        base(state.RouteWaf{Mode: state.WafModeBlock, Exclusions: []state.WafExclusion{{Rule: 901100}}}),
		"directive path":   base(state.RouteWaf{Mode: state.WafModeBlock, Exclusions: []state.WafExclusion{{Rule: 942100, Path: "/x\" \"id:1,phase:1,deny"}}}),
		"directive target": base(state.RouteWaf{Mode: state.WafModeBlock, Exclusions: []state.WafExclusion{{Rule: 942100, Target: "ARGS:q\nInclude /etc/passwd"}}}),
		"mode":             base(state.RouteWaf{Mode: "deny"}),
		"paranoia":         base(state.RouteWaf{Mode: state.WafModeBlock, Paranoia: 4}),
		"body limit":       base(state.RouteWaf{Mode: state.WafModeBlock, InspectBodyKiB: 64}),
		"route id":         {{Spec: state.RouteSpec{ID: "rt_x\" \"id:1", Waf: &state.RouteWaf{Mode: state.WafModeBlock}}}},
	}
	for name, routes := range cases {
		if _, err := wafDirectives(routes, "127.0.0.1:2023"); err == nil {
			t.Errorf("%s: accepted", name)
		}
	}
	for _, addr := range []string{"0.0.0.0:2023", "10.0.0.1:2023", "127.0.0.1:0", "localhost:2023", ""} {
		if _, err := wafDirectives(nil, addr); err == nil {
			t.Errorf("log address %q accepted", addr)
		}
	}
	// The local id range is finite.
	many := make([]Route, 0, 2000)
	for i := range 2000 {
		ex := make([]state.WafExclusion, state.MaxWafExclusions)
		for j := range ex {
			ex[j] = state.WafExclusion{Rule: state.WafRuleID(911000 + j)}
		}
		many = append(many, Route{Spec: state.RouteSpec{ID: state.RouteID(fmt.Sprintf("rt_01J9Z8X7W6V5T4S3R2Q%05d", i)), Waf: &state.RouteWaf{Mode: state.WafModeLog, Paranoia: 2, Exclusions: ex}}})
	}
	if _, err := wafDirectives(many, "127.0.0.1:2023"); err == nil {
		t.Error("rule ids past the local range accepted")
	}
}

func TestWafSupportOf(t *testing.T) {
	if s := WafSupportOf(map[string]string{LabelWafEngine: "coraza 3.8.1", LabelWafRuleSet: "OWASP CRS 4.25.0"}); !s.Available() || s.RuleSet != "OWASP CRS 4.25.0" {
		t.Errorf("support = %+v", s)
	}
	for _, labels := range []map[string]string{
		nil,
		{LabelWafEngine: "coraza 3.8.1"},
		{LabelWafEngine: "coraza\n3.8.1", LabelWafRuleSet: "OWASP CRS 4.25.0"},
	} {
		if WafSupportOf(labels).Available() {
			t.Errorf("%v: available", labels)
		}
	}
}

// refusingAdmin refuses configs with the firewall.
type refusingAdmin struct {
	fakeAdmin
	refuse bool
}

func (a *refusingAdmin) Load(ctx context.Context, cfg []byte) error {
	if a.refuse && bytes.Contains(cfg, []byte(`"handler": "waf"`)) {
		a.mu.Lock()
		a.loads = append(a.loads, nil) // count the attempt
		a.mu.Unlock()
		return fmt.Errorf("load: %w", &AdminStatusError{Method: "POST", Path: "/load", Status: 400, Body: "loading module 'waf':\nprovision http.handlers.waf: invalid directive"})
	}
	return a.fakeAdmin.Load(ctx, cfg)
}

func TestManagerWaf(t *testing.T) {
	ctx := t.Context()
	image := state.ImageRef("docker.io/library/caddy@" + policy.DefaultSystemImageDigests[policy.SystemCaddy][0])
	routes := wafFixtureRoutes(t)
	newManager := func(labels map[string]string) (*Manager, *refusingAdmin) {
		d := dockerfake.New(srvID)
		d.ImageLabels = map[string]map[string]string{string(image): labels}
		admin := &refusingAdmin{}
		var dropped int64 = 3
		return NewManager(ManagerOptions{
			ServerID: srvID, Docker: d, Admin: admin, Policy: policy.DefaultConfig(), Render: renderOpts, CacheDir: t.TempDir(), Ask: &Ask{},
			WafLogAddress: "127.0.0.1:2023", WafDropped: func() int64 { return dropped },
		}), admin
	}
	sys := state.SystemContainer{Enabled: true, Image: image}

	// Stock Caddy (no labels): routes are served without the firewall, the state says why.
	m, admin := newManager(nil)
	st := m.Sync(ctx, sys, routes)
	if st.State != state.IngressApplied || st.Waf == nil || st.Waf.State != state.WafStateUnsupported || m.WafSupport().Available() {
		t.Fatalf("stock caddy: %+v / %+v", st, st.Waf)
	}
	if bytes.Contains(admin.loads[0], []byte(`"handler": "waf"`)) {
		t.Error("firewall rendered for a Caddy without the module")
	}

	// launchroom-caddy: on, with the versions from the image.
	labels := map[string]string{LabelWafEngine: "coraza 3.8.1", LabelWafRuleSet: "OWASP CRS 4.25.0"}
	m, admin = newManager(labels)
	st = m.Sync(ctx, sys, routes)
	want := state.WafStatus{State: state.WafStateOn, Engine: "coraza 3.8.1", RuleSet: "OWASP CRS 4.25.0", Routes: 2, DroppedRecords: 3}
	if st.State != state.IngressApplied || st.Waf == nil || *st.Waf != want || !m.WafSupport().Available() {
		t.Fatalf("launchroom-caddy: %+v / %+v", st, st.Waf)
	}
	if len(admin.loads) != 1 || !bytes.Contains(admin.loads[0], []byte(`"handler": "waf"`)) {
		t.Fatal("firewall not loaded")
	}
	// Routes without firewall settings: off.
	plain := slices.Clone(routes)
	for i := range plain {
		plain[i].Spec.Waf = nil
	}
	if st = m.Sync(ctx, sys, plain); st.Waf == nil || st.Waf.State != state.WafStateOff || st.Waf.Routes != 0 {
		t.Fatalf("off: %+v", st.Waf)
	}

	// Caddy refuses the firewall: the same routes without it, state error, no retry of the same config.
	m, admin = newManager(labels)
	admin.refuse = true
	st = m.Sync(ctx, sys, routes)
	if st.State != state.IngressApplied || st.Waf == nil || st.Waf.State != state.WafStateError || st.Waf.Routes != 0 || !strings.Contains(st.Waf.Message, "invalid directive") || strings.ContainsAny(st.Waf.Message, "\n") {
		t.Fatalf("refused: %+v / %+v", st, st.Waf)
	}
	if len(admin.loads) != 2 || admin.loads[0] != nil || bytes.Contains(admin.loads[1], []byte(`"handler": "waf"`)) || !bytes.Contains(admin.loads[1], []byte("lr-route-rt_01J9Z8X7W6V5T4S3R2Q1P0N9N9")) {
		t.Fatalf("loads after refusal: %d", len(admin.loads))
	}
	rep := state.Report{TypeMeta: state.NewTypeMeta(state.KindReport), ServerID: srvID, ObservedAt: time.Now(), Seq: 1, Full: true, AppliedVersion: 1, Workloads: []state.WorkloadStatus{}, Ingress: st}
	if err := rep.Validate(); err != nil {
		t.Fatalf("report with the error state: %v", err)
	}
	st = m.Sync(ctx, sys, routes)
	if len(admin.loads) != 2 || st.Waf.State != state.WafStateError {
		t.Fatalf("the refused config was tried again (%d loads, %+v)", len(admin.loads), st.Waf)
	}
	// A settings change tries again; once accepted the state is on.
	admin.refuse = false
	changed := slices.Clone(routes)
	changed[1].Spec.Waf = &state.RouteWaf{Mode: state.WafModeBlock}
	if st = m.Sync(ctx, sys, changed); st.Waf.State != state.WafStateOn || st.Waf.Routes != 2 || st.Waf.Message != "" {
		t.Fatalf("after change: %+v", st.Waf)
	}
}

// unreachableAdmin answers "connection refused" to the next `down` loads,
// like a Caddy that is still starting after a reboot.
type unreachableAdmin struct {
	fakeAdmin
	down     int
	attempts int
}

func (a *unreachableAdmin) Load(ctx context.Context, cfg []byte) error {
	a.mu.Lock()
	a.attempts++
	if a.down > 0 {
		a.down--
		a.mu.Unlock()
		return fmt.Errorf("caddy admin: POST /load: %w", &net.OpError{Op: "dial", Net: "tcp", Err: errors.New("connect: connection refused")})
	}
	a.mu.Unlock()
	return a.fakeAdmin.Load(ctx, cfg)
}

// archiveFake records the files copied into containers.
type archiveFake struct {
	*dockerfake.Fake
	mu    sync.Mutex
	files map[string][]byte
}

func (f *archiveFake) CopyToContainer(_ context.Context, _, dst string, r io.Reader) error {
	tr := tar.NewReader(r)
	for {
		h, err := tr.Next()
		if errors.Is(err, io.EOF) {
			return nil
		}
		if err != nil {
			return err
		}
		if h.Typeflag != tar.TypeReg {
			continue
		}
		b, err := io.ReadAll(tr)
		if err != nil {
			return err
		}
		f.mu.Lock()
		f.files[path.Join(dst, h.Name)] = b //nolint:gosec // test double: names only key a map
		f.mu.Unlock()
	}
}

func (f *archiveFake) DialExec(context.Context, string, []string) (net.Conn, error) {
	return nil, errors.New("not supported")
}

// Caddy not reachable yet is no refusal: the same config, firewall
// included, is loaded again, and the firewall ends up on (docs/22 §6.6).
func TestManagerWafCaddyUnreachable(t *testing.T) {
	ctx := t.Context()
	image := state.ImageRef("docker.io/library/caddy@" + policy.DefaultSystemImageDigests[policy.SystemCaddy][0])
	routes := wafFixtureRoutes(t)
	labels := map[string]string{LabelWafEngine: "coraza 3.8.1", LabelWafRuleSet: "OWASP CRS 4.25.0"}
	sys := state.SystemContainer{Enabled: true, Image: image}
	now := time.Date(2026, 10, 28, 12, 0, 0, 0, time.UTC)
	var d *archiveFake
	newManager := func(down int) (*Manager, *unreachableAdmin) {
		d = &archiveFake{Fake: dockerfake.New(srvID), files: map[string][]byte{}}
		d.ImageLabels = map[string]map[string]string{string(image): labels}
		admin := &unreachableAdmin{down: down}
		return NewManager(ManagerOptions{
			ServerID: srvID, Docker: d, Admin: admin, Policy: policy.DefaultConfig(), Render: renderOpts, CacheDir: t.TempDir(), Ask: &Ask{},
			WafLogAddress: "127.0.0.1:2023", LoadRetry: time.Millisecond, Now: func() time.Time { return now },
		}), admin
	}
	hasWaf := func(cfg []byte) bool { return bytes.Contains(cfg, []byte(`"handler": "waf"`)) }

	// First load refused at the connection, the retry in the same pass succeeds.
	m, admin := newManager(1)
	st := m.Sync(ctx, sys, routes)
	if st.State != state.IngressApplied || st.Waf == nil || st.Waf.State != state.WafStateOn || st.Waf.Routes != 2 || st.Waf.Message != "" {
		t.Fatalf("after one refused connection: %+v / %+v", st, st.Waf)
	}
	if admin.attempts != 2 || len(admin.loads) != 1 || !hasWaf(admin.loads[0]) {
		t.Fatalf("attempts %d, loads %d", admin.attempts, len(admin.loads))
	}

	// Caddy stays away for the whole pass: pending, no firewall error, no
	// fallback without the firewall; the next pass loads it.
	m, admin = newManager(loadAttempts)
	st = m.Sync(ctx, sys, routes)
	if st.State != state.IngressPending || !strings.Contains(st.Message, "Caddy wasn't reachable yet; retrying") || !strings.Contains(st.Message, "connection refused") {
		t.Fatalf("unreachable: %+v", st)
	}
	if st.Waf == nil || st.Waf.State == state.WafStateError || st.Waf.Message != "Caddy wasn't reachable yet; retrying." {
		t.Fatalf("unreachable firewall state: %+v", st.Waf)
	}
	if admin.attempts != loadAttempts || len(admin.loads) != 0 {
		t.Fatalf("attempts %d, loads %d (no fallback config expected)", admin.attempts, len(admin.loads))
	}
	// The next Caddy start resumes the routes (without the firewall, which
	// is loaded once Caddy answers), not whatever kept it from starting.
	if seeded := d.files[AutosaveFile]; !bytes.Contains(seeded, []byte("lr-route-rt_01J9Z8X7W6V5T4S3R2Q1P0N9N9")) || hasWaf(seeded) || !json.Valid(seeded) {
		t.Fatalf("resume config: %.200s", seeded)
	}
	rep := state.Report{TypeMeta: state.NewTypeMeta(state.KindReport), ServerID: srvID, ObservedAt: now, Seq: 1, Full: true, AppliedVersion: 1, Workloads: []state.WorkloadStatus{}, Ingress: st}
	if err := rep.Validate(); err != nil {
		t.Fatalf("report while unreachable: %v", err)
	}
	st = m.Sync(ctx, sys, routes)
	if st.State != state.IngressApplied || st.Waf.State != state.WafStateOn || st.Waf.Routes != 2 || st.Waf.Message != "" {
		t.Fatalf("next pass: %+v / %+v", st, st.Waf)
	}
	if len(admin.loads) != 1 || !hasWaf(admin.loads[0]) {
		t.Fatalf("loads %d", len(admin.loads))
	}

	// Unreachable for longer than wafUnreachableAfter: the firewall reports
	// error (the dashboard's callout), yet every pass keeps trying.
	m, admin = newManager(2 * loadAttempts)
	m.Sync(ctx, sys, routes)
	now = now.Add(wafUnreachableAfter)
	st = m.Sync(ctx, sys, routes)
	if st.State != state.IngressPending || st.Waf.State != state.WafStateError || !strings.Contains(st.Waf.Message, "hasn't been reachable since 2026-10-28T12:00:00Z") {
		t.Fatalf("persistently unreachable: %+v / %+v", st, st.Waf)
	}
	st = m.Sync(ctx, sys, routes)
	if st.State != state.IngressApplied || st.Waf.State != state.WafStateOn || st.Waf.Message != "" || admin.attempts != 2*loadAttempts+1 {
		t.Fatalf("recovered: %+v / %+v (%d attempts)", st, st.Waf, admin.attempts)
	}
}

// A real refusal (4xx) falls back at once, without retries in the pass.
func TestManagerWafRefusalNotRetried(t *testing.T) {
	ctx := t.Context()
	image := state.ImageRef("docker.io/library/caddy@" + policy.DefaultSystemImageDigests[policy.SystemCaddy][0])
	d := dockerfake.New(srvID)
	d.ImageLabels = map[string]map[string]string{string(image): {LabelWafEngine: "coraza 3.8.1", LabelWafRuleSet: "OWASP CRS 4.25.0"}}
	admin := &refusingAdmin{refuse: true}
	m := NewManager(ManagerOptions{
		ServerID: srvID, Docker: d, Admin: admin, Policy: policy.DefaultConfig(), Render: renderOpts, CacheDir: t.TempDir(), Ask: &Ask{},
		WafLogAddress: "127.0.0.1:2023", LoadRetry: time.Hour, // a retry would hang the test
	})
	sys := state.SystemContainer{Enabled: true, Image: image}
	routes := wafFixtureRoutes(t)
	for range 3 {
		st := m.Sync(ctx, sys, routes)
		if st.State != state.IngressApplied || st.Waf.State != state.WafStateError || !strings.Contains(st.Waf.Message, "Caddy refused the firewall config") {
			t.Fatalf("%+v / %+v", st, st.Waf)
		}
	}
	if len(admin.loads) != 2 || admin.loads[0] != nil {
		t.Fatalf("loads %d: the refused config was tried again", len(admin.loads))
	}
}
