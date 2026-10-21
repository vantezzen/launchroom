//go:build integration

package integration

import (
	"context"
	"crypto/tls"
	"io"
	"net"
	"net/http"
	"strings"
	"sync"
	"testing"
	"time"

	"github.com/vantezzen/launchroom/daemon/internal/ingress/caddy"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// authServer is a stand-in for the dashboard's /auth/preview endpoint.
type authServer struct {
	mu   sync.Mutex
	last *authRequest
}

// authRequest is what the auth server saw.
type authRequest struct {
	Method, Path, Host string
	Header             http.Header
}

func (a *authServer) ServeHTTP(w http.ResponseWriter, r *http.Request) {
	a.mu.Lock()
	a.last = &authRequest{Method: r.Method, Path: r.URL.Path, Host: r.Host, Header: r.Header.Clone()}
	a.mu.Unlock()
	if r.Header.Get("X-Launchroom-Bypass") == "ci-secret" {
		w.WriteHeader(http.StatusOK)
		return
	}
	if c, err := r.Cookie("lr_preview"); err == nil && c.Value == "ok" {
		w.WriteHeader(http.StatusNoContent)
		return
	}
	w.Header().Set("Location", "https://dash.example.com/auth/preview/login?return=x")
	w.Header().Add("Set-Cookie", "lr_preview_state=abc; Path=/; HttpOnly; Secure")
	w.WriteHeader(http.StatusFound)
	_, _ = io.WriteString(w, "login required")
}

func (a *authServer) lastRequest() *authRequest {
	a.mu.Lock()
	defer a.mu.Unlock()
	return a.last
}

func tlsClientFor(host string) *http.Client {
	return &http.Client{
		Timeout:       10 * time.Second,
		CheckRedirect: func(*http.Request, []*http.Request) error { return http.ErrUseLastResponse },
		Transport: &http.Transport{
			Proxy: nil, DisableKeepAlives: true,
			TLSClientConfig: &tls.Config{ServerName: host, InsecureSkipVerify: true, MinVersion: tls.VersionTLS12}, //nolint:gosec // internal CA in tests
			DialContext: func(ctx context.Context, network, _ string) (net.Conn, error) {
				var d net.Dialer
				return d.DialContext(ctx, network, "127.0.0.1:443")
			},
		},
	}
}

func do(t *testing.T, c *http.Client, method, url string, body string, hdr map[string]string) (int, http.Header, string, error) {
	t.Helper()
	req, err := http.NewRequestWithContext(t.Context(), method, url, strings.NewReader(body))
	if err != nil {
		t.Fatal(err)
	}
	for k, v := range hdr {
		req.Header.Set(k, v)
	}
	resp, err := c.Do(req)
	if err != nil {
		return 0, nil, "", err
	}
	defer func() { _ = resp.Body.Close() }()
	b, _ := io.ReadAll(io.LimitReader(resp.Body, 1<<16))
	return resp.StatusCode, resp.Header, string(b), nil
}

// TestProtectedRoute runs Caddy's forward auth (docs/05 §4) against a local
// auth server: 2xx continues to the app, anything else is returned as is,
// strip headers never reach the app, and a URL outside the allowed origins
// fails closed.
func TestProtectedRoute(t *testing.T) {
	auth := &authServer{}
	ln, err := (&net.ListenConfig{}).Listen(t.Context(), "tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	srv := &http.Server{Handler: auth, ReadHeaderTimeout: 5 * time.Second}
	go func() { _ = srv.Serve(ln) }()
	t.Cleanup(func() { _ = srv.Close() })
	origin := "http://" + ln.Addr().String()

	e := setupWith(t, caddy.Options{ForwardAuthOrigins: []string{origin}})
	ns, wl := e.nodeState(t, 1, true, true)
	ns.Routes[0].Protection = &state.RouteProtection{ForwardAuth: state.ForwardAuth{
		URL: state.ForwardAuthURL(origin + "/auth/preview"), StripHeaders: []state.HeaderName{"X-Launchroom-Bypass"},
	}}
	const refusedHost = "refused.localhost"
	ns.Routes = append(ns.Routes, state.RouteSpec{
		ID: "rt_01J9Z8X7W6V5T4S3R2Q1P0N9NA", Hosts: []state.Hostname{refusedHost}, TLS: state.TLSInternal,
		Upstreams: ns.Routes[0].Upstreams,
		// Valid by grammar, but not an origin the local policy allows.
		Protection: &state.RouteProtection{ForwardAuth: state.ForwardAuth{URL: "http://127.0.0.1:1/auth/preview"}},
	})
	if err := ns.Validate(); err != nil {
		t.Fatal(err)
	}
	converge(t, e, ns, func() bool {
		_, _, ing := e.rec.Snapshot()
		return status(e.rec, wl).Phase == state.PhaseHealthy && ing != nil && ing.State == state.IngressApplied
	})

	c := tlsClientFor(testHost)
	url := "https://" + testHost + "/hello?x=1"

	// Wait for the internal certificate; any answer from the route will do.
	deadline := time.Now().Add(90 * time.Second)
	for {
		_, _, _, err := do(t, c, http.MethodGet, url, "", nil)
		if err == nil {
			break
		}
		if time.Now().After(deadline) {
			t.Fatalf("https: %v", err)
		}
		time.Sleep(time.Second)
	}

	// (b) Not authenticated: the auth server's 302 reaches the client unchanged.
	code, hdr, body, err := do(t, c, http.MethodGet, url, "", map[string]string{"X-Forwarded-Host": "evil.example.com", "X-Forwarded-Method": "DELETE"})
	if err != nil || code != http.StatusFound {
		t.Fatalf("unauthenticated: %d %q %v", code, body, err)
	}
	if hdr.Get("Location") != "https://dash.example.com/auth/preview/login?return=x" || !strings.Contains(hdr.Get("Set-Cookie"), "lr_preview_state=abc") || body != "login required" {
		t.Errorf("unauthenticated response: %v %q", hdr, body)
	}
	if strings.Contains(body, "Hostname:") {
		t.Error("app reached without authentication")
	}
	ar := auth.lastRequest()
	if ar == nil {
		t.Fatal("auth server not called")
	}
	if ar.Method != http.MethodGet || ar.Path != "/auth/preview" || ar.Host != ln.Addr().String() ||
		ar.Header.Get("X-Forwarded-Method") != http.MethodGet || ar.Header.Get("X-Forwarded-Uri") != "/hello?x=1" ||
		ar.Header.Get("X-Forwarded-Host") != testHost || ar.Header.Get("X-Forwarded-Proto") != "https" {
		t.Errorf("auth request: %+v", ar)
	}

	// (a) Authenticated (cookie, 204): the request continues to the app, body and method intact.
	code, _, body, err = do(t, c, http.MethodPost, url, "payload-123", map[string]string{"Cookie": "lr_preview=ok"})
	if err != nil || code != http.StatusOK || !strings.Contains(body, "Hostname:") || !strings.Contains(body, "POST /hello?x=1") {
		t.Fatalf("authenticated: %d %q %v", code, body, err)
	}
	if ar := auth.lastRequest(); ar.Method != http.MethodGet || ar.Header.Get("X-Forwarded-Method") != http.MethodPost {
		t.Errorf("auth request for POST: %s %v", ar.Method, ar.Header)
	}

	// (c) CI bypass header: allowed by the auth server, stripped before the app.
	code, hdr, body, err = do(t, c, http.MethodGet, url, "", map[string]string{"X-Launchroom-Bypass": "ci-secret"})
	if err != nil || code != http.StatusOK || !strings.Contains(body, "Hostname:") {
		t.Fatalf("bypass: %d %q %v", code, body, err)
	}
	if strings.Contains(strings.ToLower(body), "x-launchroom-bypass") || strings.Contains(body, "ci-secret") {
		t.Errorf("strip header reached the app:\n%s", body)
	}
	if hdr.Get("X-Robots-Tag") != "noindex" {
		t.Errorf("X-Robots-Tag = %q", hdr.Get("X-Robots-Tag"))
	}

	// (d) Refused auth URL: fails closed, never reaches the app or the auth server.
	before := auth.lastRequest()
	code, _, body, err = do(t, tlsClientFor(refusedHost), http.MethodGet, "https://"+refusedHost+"/", "", map[string]string{"Cookie": "lr_preview=ok"})
	if err != nil || code != http.StatusForbidden || !strings.Contains(body, "misconfigured") {
		t.Fatalf("refused route: %d %q %v", code, body, err)
	}
	if auth.lastRequest() != before {
		t.Error("refused route called the auth server")
	}
}
