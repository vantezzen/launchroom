package caddy

import (
	"bytes"
	"context"
	"encoding/base64"
	"encoding/json"
	"fmt"
	"net/netip"
	"os"
	"path/filepath"
	"slices"
	"strings"
	"testing"

	"github.com/vantezzen/launchroom/daemon/internal/docker/dockerfake"
	"github.com/vantezzen/launchroom/daemon/internal/ingress"
	"github.com/vantezzen/launchroom/daemon/internal/names"
	"github.com/vantezzen/launchroom/daemon/internal/policy"
	"github.com/vantezzen/launchroom/daemon/internal/secret"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// traceFixture is protocol/fixtures/v1/node-state.trace.json with its
// workload resolved.
func traceFixture(t *testing.T) (*state.NodeState, []Route) {
	t.Helper()
	data, err := os.ReadFile(filepath.Join("..", "..", "..", "..", "protocol", "fixtures", "v1", "node-state.trace.json"))
	if err != nil {
		t.Fatal(err)
	}
	ns, err := state.DecodeNodeState(data)
	if err != nil {
		t.Fatal(err)
	}
	return ns, ResolveRoutes(ns.Routes, resolver(map[state.WorkloadID]ingress.Endpoint{
		"wl_01J9Z8X7W6V5T4S3R2Q1P0N9N7": {Addr: netip.MustParseAddr("10.231.4.5"), Serving: true, Ready: true},
	}))
}

var traceOpts = func() Options {
	o := wafOpts
	o.LocalUpstreams = []string{"127.0.0.1:8000"}
	o.ForwardAuthOrigins = []string{"https://launchroom.example.com:443"}
	o.Trace = &TraceOptions{Edge: srvID, KeyID: 42, KeyFile: TraceKeyFile}
	return o
}()

// handlersOf flattens the handlers of a route list (subroutes included) in order.
func handlersOf(routes []any) []map[string]any {
	var out []map[string]any
	for _, r := range routes {
		for _, h := range r.(map[string]any)["handle"].([]any) {
			hm := h.(map[string]any)
			out = append(out, hm)
			if sub, ok := hm["routes"].([]any); ok && hm["handler"] == "subroute" {
				out = append(out, handlersOf(sub)...)
			}
		}
	}
	return out
}

func serverRoutes(t *testing.T, cfg []byte, server string) []any {
	t.Helper()
	var parsed map[string]any
	if err := json.Unmarshal(cfg, &parsed); err != nil {
		t.Fatal(err)
	}
	return parsed["apps"].(map[string]any)["http"].(map[string]any)["servers"].(map[string]any)[server].(map[string]any)["routes"].([]any)
}

func TestRenderTraceGolden(t *testing.T) {
	_, routes := traceFixture(t)
	cfg, err := Render(routes, traceOpts)
	if err != nil {
		t.Fatal(err)
	}
	golden(t, "trace", cfg)

	for _, server := range []string{serverHTTPS, serverHTTP} {
		rs := serverRoutes(t, cfg, server)
		first := rs[0].(map[string]any)
		if first["terminal"] == true || first["match"] != nil {
			t.Errorf("%s: the trace route must match everything and never terminate", server)
		}
		h := first["handle"].([]any)[0].(map[string]any)
		if h["handler"] != "lr_trace" || h["edge"] != srvID || h["key_id"] != float64(42) || h["key_file"] != "/data/launchroom/secrets/trace-key" {
			t.Fatalf("%s: first handler %v", server, h)
		}
		want := map[string]any{
			"rt_01J9Z8X7W6V5T4S3R2Q1P0N9N9": map[string]any{
				"service": "sin_01J9Z8X7W6V5T4S3R2Q1P0N9NB", "waf": "block",
				"upstreams": map[string]any{"10.231.4.5:3000": srvID, "100.64.0.9:31002": "srv_01J9Z8X7W6V5T4S3R2Q1P0N9MM"},
			},
			"rt_01J9Z8X7W6V5T4S3R2Q1P0N9NA": map[string]any{
				"service": "sin_01J9Z8X7W6V5T4S3R2Q1P0N9NB", "waf": "log",
				"upstreams": map[string]any{"10.231.4.5:3000": srvID},
			},
			// The dashboard: no service, its loopback upstream is this edge.
			"rt_0000000000000000000000DASH": map[string]any{"upstreams": map[string]any{"127.0.0.1:8000": srvID}},
		}
		got, _ := json.Marshal(h["routes"])
		wantJSON, _ := json.Marshal(want)
		if !bytes.Equal(got, wantJSON) {
			t.Errorf("%s: routes\n got %s\nwant %s", server, got, wantJSON)
		}
	}

	var vars []string
	for _, h := range handlersOf(serverRoutes(t, cfg, serverHTTPS)) {
		switch h["handler"] {
		case "vars":
			for k, v := range h {
				if k != "handler" {
					vars = append(vars, k+"="+fmt.Sprint(v))
				}
			}
		case "waf":
			if h["tx_id_req_header"] != "X-Request-Id" {
				t.Error("the firewall's transaction id is not the request id")
			}
			dirs := h["directives"].(string)
			if !strings.HasSuffix(dirs, "Include @owasp_crs/*.conf\n"+
				`SecAction "id:99997,phase:2,pass,nolog,lrtrace"`+"\n"+
				`SecAction "id:99998,phase:3,pass,nolog,lrtrace"`+"\n"+
				`SecAction "id:99999,phase:5,pass,nolog,lrtrace"`+"\n") {
				t.Errorf("trace rules missing after CRS:\n%s", dirs)
			}
		case "reverse_proxy":
			if lb, ok := h["load_balancing"].(map[string]any); ok {
				if p := lb["selection_policy"].(map[string]any)["policy"]; p != "lr_round_robin" {
					t.Errorf("app proxy selection policy %v", p)
				}
			}
		}
	}
	for _, want := range []string{"lr_route=rt_01J9Z8X7W6V5T4S3R2Q1P0N9N9", "lr_route=rt_0000000000000000000000DASH", "lr_stage=app", "lr_origin=redirect", "lr_origin=no_route"} {
		if !slices.Contains(vars, want) {
			t.Errorf("no %s in %v", want, vars)
		}
	}
	// The access log stays where it was, after the trace.
	if rs := serverRoutes(t, cfg, serverHTTPS); fmt.Sprint(rs[1].(map[string]any)["handle"].([]any)[0].(map[string]any)["handler"]) == "lr_trace" {
		t.Error("trace rendered twice")
	}
}

func TestRenderTraceEdgePages(t *testing.T) {
	o := traceOpts
	o.ReachabilityDial = "127.0.0.1:2021"
	spec := func(id string) state.RouteSpec {
		return state.RouteSpec{ID: state.RouteID(id), Hosts: []state.Hostname{state.Hostname(strings.ToLower(id[len(id)-4:]) + ".example.com")}, TLS: state.TLSACME}
	}
	stopped := spec("rt_01J9Z8X7W6V5T4S3R2Q1P0STOP")
	stopped.Stopped = true
	protected := spec("rt_01J9Z8X7W6V5T4S3R2Q1P0PROT")
	protected.Upstreams = []state.RouteUpstream{{Address: "100.64.0.9:31002"}}
	protected.Protection = &state.RouteProtection{ForwardAuth: state.ForwardAuth{URL: "https://launchroom.example.com/auth/preview"}}
	refused := protected
	refused.ID, refused.Hosts = "rt_01J9Z8X7W6V5T4S3R2Q1P0REFU", []state.Hostname{"refu.example.com"}
	refused.Protection = &state.RouteProtection{ForwardAuth: state.ForwardAuth{URL: "https://evil.example.com/auth/preview"}}
	empty := spec("rt_01J9Z8X7W6V5T4S3R2Q1P0NONE")
	empty.Upstreams = []state.RouteUpstream{{Workload: wlA, Port: 3000}}
	routes := ResolveRoutes([]state.RouteSpec{stopped, protected, refused, empty}, resolver(nil))
	cfg, err := Render(routes, o)
	if err != nil {
		t.Fatal(err)
	}
	// Each edge page has its origin in the same handle list, right before it
	// (pages are subroutes around a static_response).
	pages := map[string]string{}
	var stages []string
	var origin, stage string
	for _, h := range handlersOf(serverRoutes(t, cfg, serverHTTPS)) {
		switch h["handler"] {
		case "vars":
			if v, ok := h[traceOriginVar].(string); ok {
				origin = v
			}
			if v, ok := h[traceStageVar].(string); ok {
				stage = v
			}
		case "subroute":
		default:
			if origin != "" {
				pages[origin] = fmt.Sprint(h["handler"], h["status_code"])
			}
			if stage != "" {
				stages = append(stages, stage+">"+fmt.Sprint(h["handler"]))
			}
			origin, stage = "", ""
		}
	}
	for origin, want := range map[string]string{
		"stopped": "static_response503", "refused": "static_response403", "unavailable": "static_response503",
		"edge": "reverse_proxy<nil>", "no_route": "static_response404",
	} {
		if pages[origin] != want {
			t.Errorf("lr_origin=%s before %q, want %q (%v)", origin, pages[origin], want, pages)
		}
	}
	// Forward auth runs with stage auth; the protected route's app proxy with stage app.
	if !slices.Equal(stages, []string{"auth>reverse_proxy", "app>reverse_proxy"}) {
		t.Errorf("stages %v", stages)
	}
	for _, h := range handlersOf(serverRoutes(t, cfg, serverHTTP)) {
		if h["handler"] == "vars" && h[traceOriginVar] == "redirect" && h[traceRouteVar] == nil {
			t.Error("HTTP redirect without its route")
		}
	}
}

func TestRenderWithoutTraceUnchanged(t *testing.T) {
	_, routes := traceFixture(t)
	o := traceOpts
	o.Trace = nil
	cfg, err := Render(routes, o)
	if err != nil {
		t.Fatal(err)
	}
	for _, s := range []string{"lr_trace", "lr_route", "lr_origin", "lr_stage", "lr_round_robin", "lrtrace", "tx_id_req_header"} {
		if bytes.Contains(cfg, []byte(s)) {
			t.Errorf("%s rendered without the trace", s)
		}
	}
}

func TestRenderTraceRefusesBadOptions(t *testing.T) {
	_, routes := traceFixture(t)
	for name, tr := range map[string]TraceOptions{
		"edge":     {Edge: "srv_bad", KeyID: 1, KeyFile: TraceKeyFile},
		"key id":   {Edge: srvID, KeyFile: TraceKeyFile},
		"key file": {Edge: srvID, KeyID: 1, KeyFile: "/etc/passwd"},
	} {
		o := traceOpts
		o.Trace = &tr
		if _, err := Render(routes, o); err == nil {
			t.Errorf("%s: accepted", name)
		}
	}
	// A route id or service failing its grammar is left out, never the whole handler.
	bad := slices.Clone(routes)
	bad[0].Spec.Service = "sin_bad"
	cfg, err := Render(bad, traceOpts)
	if err != nil || bytes.Contains(cfg, []byte("sin_bad")) {
		t.Fatalf("bad service: %v", err)
	}
}

// traceAdmin refuses configs naming the trace module (a Caddy without it).
type traceAdmin struct {
	fakeAdmin
	refuse bool
}

func (a *traceAdmin) Load(ctx context.Context, cfg []byte) error {
	if a.refuse && bytes.Contains(cfg, []byte(`"lr_trace"`)) {
		return fmt.Errorf("load: %w", &AdminStatusError{Method: "POST", Path: "/load", Status: 400, Body: "unknown module: http.handlers.lr_trace"})
	}
	return a.fakeAdmin.Load(ctx, cfg)
}

func TestManagerTrace(t *testing.T) {
	ctx := t.Context()
	image := state.ImageRef("docker.io/library/caddy@" + policy.DefaultSystemImageDigests[names.SystemCaddy][0])
	ns, routes := traceFixture(t)
	key := base64.StdEncoding.EncodeToString([]byte(strings.Repeat("k", 32)))
	var d *archiveFake
	newManager := func(labels map[string]string, keyVals map[string]secret.Secret) (*Manager, *traceAdmin) {
		d = &archiveFake{Fake: dockerfake.New(srvID), files: map[string][]byte{}}
		d.ImageLabels = map[string]map[string]string{string(image): labels}
		admin := &traceAdmin{}
		return NewManager(ManagerOptions{
			ServerID: srvID, Docker: d, Admin: admin, Policy: policy.DefaultConfig(), Render: renderOpts, CacheDir: t.TempDir(), Ask: &Ask{},
			Spec: func() *state.IngressSpec { return ns.Ingress },
			OpenSecret: func(_ state.Sealed, purpose state.IngressSecretPurpose) (map[string]secret.Secret, error) {
				if purpose != state.IngressSecretTrace {
					return nil, fmt.Errorf("unexpected purpose %s", purpose)
				}
				return keyVals, nil
			},
		}), admin
	}
	sys := state.SystemContainer{Enabled: true, Image: image}
	hasTrace := func(cfg []byte) bool { return bytes.Contains(cfg, []byte(`"handler": "lr_trace"`)) }
	good := map[string]secret.Secret{"key": secret.Secret(key), "keyId": "42"}
	label := map[string]string{LabelTrace: "1"}

	// Stock Caddy: no trace, no feature; the key file is still kept current.
	m, admin := newManager(nil, good)
	if st := m.sync(ctx, sys, routes); st.State != state.IngressApplied || hasTrace(admin.loads[0]) || slices.Contains(m.Features(), state.FeatureRequestTrace) {
		t.Fatalf("stock caddy: %+v", st)
	}

	// launchroom-caddy with the module: the handler, the key in a file, the feature.
	m, admin = newManager(label, good)
	if st := m.sync(ctx, sys, routes); st.State != state.IngressApplied || len(admin.loads) != 1 || !hasTrace(admin.loads[0]) {
		t.Fatalf("trace caddy: %+v", st)
	}
	if !slices.Contains(m.Features(), state.FeatureRequestTrace) {
		t.Error("feature not announced")
	}
	if got := string(d.files[TraceKeyFile]); got != key {
		t.Errorf("key file = %q", got)
	}
	if bytes.Contains(admin.loads[0], []byte(key)) {
		t.Fatal("the key is in the config")
	}
	if !bytes.Contains(admin.loads[0], []byte(`"key_id": 42`)) {
		t.Error("key id missing")
	}

	// An unusable key: routes served without the trace, the file emptied.
	m, admin = newManager(label, map[string]secret.Secret{"key": "short", "keyId": "42"})
	if st := m.sync(ctx, sys, routes); st.State != state.IngressApplied || hasTrace(admin.loads[0]) || len(d.files[TraceKeyFile]) != 0 {
		t.Fatalf("bad key: %+v", st)
	}

	// Caddy refuses the trace: the routes without it, and the same config isn't tried again.
	m, admin = newManager(label, good)
	admin.refuse = true
	for range 2 {
		if st := m.sync(ctx, sys, routes); st.State != state.IngressApplied {
			t.Fatalf("refused trace: %+v", st)
		}
	}
	if len(admin.loads) != 1 || hasTrace(admin.loads[0]) || !bytes.Contains(admin.loads[0], []byte("lr-route-rt_01J9Z8X7W6V5T4S3R2Q1P0N9N9")) {
		t.Fatalf("loads after refusal: %d", len(admin.loads))
	}
}

// With the firewall too: Caddy refusing both serves the plain routes.
func TestManagerTraceAndWafRefused(t *testing.T) {
	ctx := t.Context()
	image := state.ImageRef("docker.io/library/caddy@" + policy.DefaultSystemImageDigests[names.SystemCaddy][0])
	ns, routes := traceFixture(t)
	d := dockerfake.New(srvID)
	d.ImageLabels = map[string]map[string]string{string(image): {LabelTrace: "1", LabelWafEngine: "coraza 3.8.1", LabelWafRuleSet: "OWASP CRS 4.25.0"}}
	admin := &traceAdmin{refuse: true}
	key := base64.StdEncoding.EncodeToString(make([]byte, 32))
	m := NewManager(ManagerOptions{
		ServerID: srvID, Docker: d, Admin: admin, Policy: policy.DefaultConfig(), Render: renderOpts,
		CacheDir: t.TempDir(), Ask: &Ask{}, WafLogAddress: "127.0.0.1:2023",
		Spec: func() *state.IngressSpec { return ns.Ingress },
		OpenSecret: func(state.Sealed, state.IngressSecretPurpose) (map[string]secret.Secret, error) {
			return map[string]secret.Secret{"key": secret.Secret(key), "keyId": "1"}, nil
		},
	})
	st := m.sync(ctx, state.SystemContainer{Enabled: true, Image: image}, routes)
	if st.State != state.IngressApplied || st.Waf == nil || st.Waf.State != state.WafStateError {
		t.Fatalf("%+v / %+v", st, st.Waf)
	}
	loads := admin.loads
	if len(loads) != 1 || bytes.Contains(loads[0], []byte(`"lr_trace"`)) || bytes.Contains(loads[0], []byte(`"handler": "waf"`)) {
		t.Fatalf("loads %d", len(loads))
	}
}

func TestTraceSupported(t *testing.T) {
	if !TraceSupported(map[string]string{LabelTrace: "1"}) {
		t.Error("label 1 not supported")
	}
	for _, v := range []string{"", "0", "true", " 1"} {
		if TraceSupported(map[string]string{LabelTrace: v}) {
			t.Errorf("%q supported", v)
		}
	}
}
