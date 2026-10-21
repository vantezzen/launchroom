//go:build integration

package integration

import (
	"io"
	"net"
	"net/http"
	"net/netip"
	"strings"
	"testing"
	"time"

	"github.com/vantezzen/launchroom/daemon/internal/ingress/caddy"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// TestDashboardDomainRoute is the dashboard's system route on the
// control-plane host with a custom domain (docs/05 §4.1, D43): Caddy serves
// the new and the old host from the same loopback upstream (allowed by the
// local policy, LR_ALLOWED_LOCAL_UPSTREAMS), with its internal CA for
// *.localhost, and redirects plain http of both hosts to https. Another
// loopback port is refused by the local policy.
func TestDashboardDomainRoute(t *testing.T) {
	ln, err := (&net.ListenConfig{}).Listen(t.Context(), "tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	srv := &http.Server{ReadHeaderTimeout: 5 * time.Second, Handler: http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "text/plain; charset=utf-8")
		_, _ = io.WriteString(w, "dashboard host="+r.Host+" proto="+r.Header.Get("X-Forwarded-Proto")) //nolint:gosec // test echo, plain text
	})}
	go func() { _ = srv.Serve(ln) }()
	t.Cleanup(func() { _ = srv.Close() })
	upstream := netip.MustParseAddrPort(ln.Addr().String())

	e := setupLocal(t, caddy.Options{}, []netip.AddrPort{upstream})
	ns, _ := e.nodeState(t, 1, false, true)
	const newHost, oldHost, refusedHost = "dash.localhost", "old.localhost", "refused.localhost"
	ns.Routes = []state.RouteSpec{{
		ID: "rt_0000000000000000000001DASH", Hosts: []state.Hostname{newHost, oldHost}, TLS: state.TLSInternal,
		Upstreams: []state.RouteUpstream{{Address: state.AddrPort(upstream.String())}},
	}, {
		ID: "rt_0000000000000000000000DASH", Hosts: []state.Hostname{refusedHost}, TLS: state.TLSInternal,
		Upstreams: []state.RouteUpstream{{Address: "127.0.0.1:2019"}},
	}}
	if err := ns.Validate(); err != nil {
		t.Fatal(err)
	}
	converge(t, e, ns, func() bool {
		_, _, ing := e.rec.Snapshot()
		return ing != nil && ing.State == state.IngressApplied
	})

	plain := &http.Client{
		Timeout:       10 * time.Second,
		CheckRedirect: func(*http.Request, []*http.Request) error { return http.ErrUseLastResponse },
		Transport:     &http.Transport{Proxy: nil, DisableKeepAlives: true},
	}
	for _, host := range []string{newHost, oldHost} {
		c := tlsClientFor(host)
		deadline := time.Now().Add(90 * time.Second)
		var code int
		var body string
		for {
			code, _, body, err = do(t, c, http.MethodGet, "https://"+host+"/up", "", nil)
			if err == nil && code == http.StatusOK {
				break
			}
			if time.Now().After(deadline) {
				t.Fatalf("%s over https: %d %q %v", host, code, body, err)
			}
			time.Sleep(time.Second)
		}
		if !strings.Contains(body, "host="+host) || !strings.Contains(body, "proto=https") {
			t.Errorf("%s: upstream saw %q", host, body)
		}

		req, err := http.NewRequestWithContext(t.Context(), http.MethodGet, "http://127.0.0.1/login?x=1", nil)
		if err != nil {
			t.Fatal(err)
		}
		req.Host = host
		resp, err := plain.Do(req)
		if err != nil {
			t.Fatal(err)
		}
		_ = resp.Body.Close()
		if resp.StatusCode != http.StatusPermanentRedirect || resp.Header.Get("Location") != "https://"+host+"/login?x=1" {
			t.Errorf("%s http: %d %q", host, resp.StatusCode, resp.Header.Get("Location"))
		}
	}

	// The refused address upstream (Caddy's own admin API) is never proxied.
	code, _, body, err := do(t, tlsClientFor(refusedHost), http.MethodGet, "https://"+refusedHost+"/config/", "", nil)
	if err == nil && code == http.StatusOK && strings.Contains(body, "apps") {
		t.Fatalf("refused address upstream was proxied: %d %q", code, body)
	}
}
