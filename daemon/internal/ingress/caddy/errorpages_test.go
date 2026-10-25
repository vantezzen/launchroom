package caddy

import (
	"encoding/json"
	"regexp"
	"slices"
	"strings"
	"testing"

	"github.com/vantezzen/launchroom/daemon/internal/state"
)

var allPages = map[string]errorPage{
	"not-found": pageNotFound, "unavailable": pageUnavailable, "refused-auth": pageRefusedAuth,
	"refused-client-auth": pageRefusedClientAuth, "client-cert-required": pageClientCertRequired,
	"app-error": pageAppError, "dashboard-error": pageDashboardError, "upstream-error": pageUpstreamError,
}

var rePlaceholder = regexp.MustCompile(`\{[a-z_]+\.[a-z_.]+\}`)

func TestErrorPagesAreSmallAndSelfContained(t *testing.T) {
	for name, p := range allPages {
		page := p.html(errorStatusPlaceholder)
		if len(page) > 4096 {
			t.Errorf("%s: %d bytes", name, len(page))
		}
		lower := strings.ToLower(page)
		for _, bad := range []string{"http://", "https://", "<script", "javascript:", "@import", "url(", "<link", "<img", "—"} {
			if strings.Contains(lower, bad) {
				t.Errorf("%s contains %q", name, bad)
			}
		}
		if !strings.Contains(page, "<title>") || !strings.Contains(page, "prefers-color-scheme:dark") || !strings.Contains(page, `name="viewport"`) {
			t.Errorf("%s lacks title, dark mode or viewport", name)
		}
		// Only the status and, inside host-matched routes, the host are placeholders.
		want := []string{errorStatusPlaceholder}
		if p.host {
			want = append(want, "{http.request.host}")
		}
		if got := rePlaceholder.FindAllString(page, -1); !slices.Equal(got, want) {
			t.Errorf("%s placeholders = %v, want %v", name, got, want)
		}
		if p.plain == "" {
			t.Errorf("%s has no plain body", name)
		}
	}
	if pageNotFound.host || pageUpstreamError.host || pageDashboardError.host {
		t.Error("pages answered for unmatched hosts must not show the Host header")
	}
}

// negotiated collects every static_response of a config and checks that each
// one is half of an Accept negotiation: HTML for text/html, plain otherwise,
// same status, no-store.
func negotiated(t *testing.T, cfg []byte) map[string]string {
	t.Helper()
	var root any
	if err := json.Unmarshal(cfg, &root); err != nil {
		t.Fatal(err)
	}
	statuses := map[string]string{} // plain body prefix → status
	paired := 0
	var walk func(v any)
	walk = func(v any) {
		switch v := v.(type) {
		case map[string]any:
			if v["handler"] == "subroute" {
				routes, _ := v["routes"].([]any)
				if len(routes) == 2 {
					first, _ := routes[0].(map[string]any)
					if m, _ := json.Marshal(first["match"]); strings.Contains(string(m), `"Accept":["*text/html*"]`) {
						htmlH := first["handle"].([]any)[0].(map[string]any)
						plainH := routes[1].(map[string]any)["handle"].([]any)[0].(map[string]any)
						checkPair(t, htmlH, plainH)
						statuses[plainH["body"].(string)] = jsonString(plainH["status_code"])
						paired += 2
						return
					}
				}
			}
			if v["handler"] == "static_response" {
				if s, ok := v["status_code"].(float64); !ok || s >= 400 {
					t.Errorf("error response outside a negotiation: %v", v)
				}
			}
			for _, c := range v {
				walk(c)
			}
		case []any:
			for _, c := range v {
				walk(c)
			}
		}
	}
	walk(root)
	if paired == 0 {
		t.Error("no negotiated error pages")
	}
	return statuses
}

func jsonString(v any) string { b, _ := json.Marshal(v); return strings.Trim(string(b), `"`) }

func checkPair(t *testing.T, htmlH, plainH map[string]any) {
	t.Helper()
	if htmlH["status_code"] != plainH["status_code"] {
		t.Errorf("status differs: %v / %v", htmlH["status_code"], plainH["status_code"])
	}
	hh, ph := htmlH["headers"].(map[string]any), plainH["headers"].(map[string]any)
	first := func(h map[string]any, k string) string { l, _ := h[k].([]any); s, _ := l[0].(string); return s }
	if first(hh, "Content-Type") != "text/html; charset=utf-8" || first(ph, "Content-Type") == "" || strings.Contains(first(ph, "Content-Type"), "html") {
		t.Errorf("content types %v / %v", hh["Content-Type"], ph["Content-Type"])
	}
	if first(hh, "Cache-Control") != "no-store" || first(ph, "Cache-Control") != "no-store" {
		t.Error("error pages must not be cached")
	}
	if b, _ := htmlH["body"].(string); !strings.HasPrefix(b, "<!doctype html>") {
		t.Errorf("html body = %.40q", b)
	}
}

func TestErrorPagesNegotiatedWithStatus(t *testing.T) {
	o := renderOpts
	o.LocalUpstreams = []string{"127.0.0.1:8000"}
	dash := dashRoute(t, state.ClientAuthRequire, "127.0.0.1:8000")
	refusedCA := dashRoute(t, state.ClientAuthRequire, "127.0.0.1:8000")
	refusedCA.Spec.ID, refusedCA.Spec.Hosts, refusedCA.EdgeSecretFile = "rt_01J9Z8X7W6V5T4S3R2Q1P0N9NC", []state.Hostname{"refused.example.com"}, ""
	protected := routeSpecs()[2]
	protected.Protection = &state.RouteProtection{ForwardAuth: state.ForwardAuth{URL: "https://evil.example.net/auth/preview"}}
	routes := []Route{
		dash, refusedCA,
		{Spec: routeSpecs()[0], Dial: []string{"10.0.0.1:3000"}},
		{Spec: routeSpecs()[1]}, // no healthy instance
		{Spec: protected, Dial: []string{"10.0.0.2:3000"}},
	}
	cfg, err := Render(routes, o)
	if err != nil {
		t.Fatal(err)
	}
	got := negotiated(t, cfg)
	want := map[string]string{
		pageNotFound.plain: "404", pageUnavailable.plain: "503", pageRefusedAuth.plain: "403",
		pageRefusedClientAuth.plain: "403", pageClientCertRequired.plain: "401",
		pageDashboardError.plain: errorStatusPlaceholder, pageAppError.plain: errorStatusPlaceholder,
		pageUpstreamError.plain: errorStatusPlaceholder,
	}
	for body, status := range want {
		if got[body] != status {
			t.Errorf("%q: status %q, want %q", body, got[body], status)
		}
	}
	if !strings.Contains(string(cfg), `"application/json"`) {
		t.Error("the client certificate answer must stay JSON for API clients")
	}
}

func TestErrorRoutesPerRouteKind(t *testing.T) {
	o := renderOpts
	o.LocalUpstreams = []string{"127.0.0.1:8000"}
	dash := Route{Spec: state.RouteSpec{ID: "rt_0000000000000000000000DASH", Hosts: []state.Hostname{"dash.example.com"}, TLS: state.TLSACME,
		Upstreams: []state.RouteUpstream{{Address: "127.0.0.1:8000"}}}, Dial: []string{"127.0.0.1:8000"}}
	app := Route{Spec: routeSpecs()[1], Dial: []string{"10.0.0.1:8080"}}
	er := o.errorRoutes([]Route{dash, app})
	if len(er) != 3 {
		t.Fatalf("errors routes = %d", len(er))
	}
	b, _ := json.Marshal(er)
	var parsed []struct {
		Match []struct {
			Host []string            `json:"host"`
			Vars map[string][]string `json:"vars"`
		} `json:"match"`
		Terminal bool `json:"terminal"`
	}
	if err := json.Unmarshal(b, &parsed); err != nil {
		t.Fatal(err)
	}
	hosts := [][]string{{"dash.example.com"}, {"custom.example.org"}, nil}
	for i, r := range parsed {
		if !r.Terminal || len(r.Match) != 1 || !slices.Equal(r.Match[0].Host, hosts[i]) ||
			!slices.Equal(r.Match[0].Vars[errorStatusPlaceholder], []string{"502", "503", "504"}) {
			t.Errorf("errors route %d = %+v", i, r)
		}
	}
	for i, p := range []errorPage{pageDashboardError, pageAppError, pageUpstreamError} {
		if !strings.Contains(string(mustJSON(t, er[i])), jsonEscape(p.title)) {
			t.Errorf("errors route %d is not %q", i, p.title)
		}
	}
	// Without LR_ALLOWED_LOCAL_UPSTREAMS no route counts as the dashboard.
	if er := renderOpts.errorRoutes([]Route{dash, app}); len(er) != 2 {
		t.Errorf("errors routes without local upstreams = %d", len(er))
	}
}

func mustJSON(t *testing.T, v any) []byte {
	t.Helper()
	b, err := json.Marshal(v)
	if err != nil {
		t.Fatal(err)
	}
	return b
}

func jsonEscape(s string) string { b, _ := json.Marshal(s); return strings.Trim(string(b), `"`) }
