package caddy

import (
	"crypto/ed25519"
	"encoding/base64"
	"encoding/json"
	"io"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
	"time"
)

const testNonce = "AbCdEfGhIjKlMnOpQrStUvWxYz012345_-"

func reachServer(t *testing.T, now func() time.Time) (*httptest.Server, ed25519.PublicKey, *Ask) {
	t.Helper()
	pub, priv, err := ed25519.GenerateKey(nil)
	if err != nil {
		t.Fatal(err)
	}
	a := &Ask{Reach: NewReachability("srv_01J9Z8X7W6V5T4S3R2Q1P0N9MK", func(m []byte) []byte { return ed25519.Sign(priv, m) }, now)}
	srv := httptest.NewServer(a)
	t.Cleanup(srv.Close)
	return srv, pub, a
}

type reachResult struct {
	StatusCode int
	Header     http.Header
}

func reachGet(t *testing.T, srv *httptest.Server, method, host, path string) (reachResult, []byte) {
	t.Helper()
	req, err := http.NewRequestWithContext(t.Context(), method, srv.URL+path, nil)
	if err != nil {
		t.Fatal(err)
	}
	req.Host = host
	resp, err := http.DefaultClient.Do(req)
	if err != nil {
		t.Fatal(err)
	}
	defer func() { _ = resp.Body.Close() }()
	body, _ := io.ReadAll(resp.Body)
	return reachResult{resp.StatusCode, resp.Header}, body
}

func TestReachabilitySignsHostAndNonce(t *testing.T) {
	srv, pub, _ := reachServer(t, nil)
	resp, body := reachGet(t, srv, http.MethodGet, "LR-Check-1a2b.Apps.Example.com.:80", ReachabilityPath+testNonce)
	if resp.StatusCode != 200 || resp.Header.Get("Content-Type") != "application/json" || resp.Header.Get("Cache-Control") != "no-store" {
		t.Fatalf("status %d headers %v", resp.StatusCode, resp.Header)
	}
	var ans struct{ ServerID, Signature string }
	if err := json.Unmarshal(body, &ans); err != nil {
		t.Fatal(err)
	}
	if ans.ServerID != "srv_01J9Z8X7W6V5T4S3R2Q1P0N9MK" {
		t.Fatalf("server %q", ans.ServerID)
	}
	sig, err := base64.StdEncoding.DecodeString(ans.Signature)
	if err != nil || len(sig) != ed25519.SignatureSize {
		t.Fatalf("signature %q", ans.Signature)
	}
	msg := "launchroom-reachability/v1\nlr-check-1a2b.apps.example.com\n" + testNonce
	if !ed25519.Verify(pub, []byte(msg), sig) {
		t.Fatal("signature does not verify over the exact message")
	}
	if ed25519.Verify(pub, []byte("launchroom-reachability/v1\nother.example.com\n"+testNonce), sig) {
		t.Fatal("signature verifies for another host")
	}
}

func TestReachabilityRefusals(t *testing.T) {
	srv, _, _ := reachServer(t, nil)
	cases := []struct {
		method, host, path string
		want               int
	}{
		{http.MethodGet, "apps.example.com", ReachabilityPath + "short", 404},
		{http.MethodGet, "apps.example.com", ReachabilityPath + testNonce + "/x", 404},
		{http.MethodGet, "apps.example.com", ReachabilityPath + strings.Repeat("a", 129), 404},
		{http.MethodGet, "localhost", ReachabilityPath + testNonce, 400},
		{http.MethodGet, "1_2.example.com", ReachabilityPath + testNonce, 400},
		{http.MethodGet, "-a.example.com", ReachabilityPath + testNonce, 400},
		{http.MethodGet, "[::1]:80", ReachabilityPath + testNonce, 400},
		{http.MethodPost, "apps.example.com", ReachabilityPath + testNonce, 405},
		{http.MethodHead, "apps.example.com", ReachabilityPath + testNonce, 405},
	}
	for _, c := range cases {
		if resp, _ := reachGet(t, srv, c.method, c.host, c.path); resp.StatusCode != c.want {
			t.Errorf("%s %s %s: %d, want %d", c.method, c.host, c.path, resp.StatusCode, c.want)
		}
	}
}

func TestReachabilityRateLimit(t *testing.T) {
	now := time.Unix(1_800_000_000, 0)
	srv, _, _ := reachServer(t, func() time.Time { return now })
	ok := 0
	for range reachabilityBurst + 5 {
		if resp, _ := reachGet(t, srv, http.MethodGet, "apps.example.com", ReachabilityPath+testNonce); resp.StatusCode == 200 {
			ok++
		} else if resp.StatusCode != http.StatusTooManyRequests {
			t.Fatalf("status %d", resp.StatusCode)
		}
	}
	if ok != reachabilityBurst {
		t.Fatalf("%d answered, want %d", ok, reachabilityBurst)
	}
	now = now.Add(time.Second)
	if resp, _ := reachGet(t, srv, http.MethodGet, "apps.example.com", ReachabilityPath+testNonce); resp.StatusCode != 200 {
		t.Fatalf("not refilled: %d", resp.StatusCode)
	}
}

func TestAskPathUnchangedWithReachability(t *testing.T) {
	srv, _, a := reachServer(t, nil)
	a.SetHosts([]string{"custom.example.org"})
	if resp, _ := reachGet(t, srv, http.MethodGet, "x", AskPath+"?domain=custom.example.org"); resp.StatusCode != 200 {
		t.Fatalf("ask: %d", resp.StatusCode)
	}
	if resp, _ := reachGet(t, srv, http.MethodGet, "x", AskPath+"?domain=evil.example.org"); resp.StatusCode != 404 {
		t.Fatalf("ask unknown: %d", resp.StatusCode)
	}
	plain := httptest.NewServer(&Ask{})
	defer plain.Close()
	if resp, _ := reachGet(t, plain, http.MethodGet, "apps.example.com", ReachabilityPath+testNonce); resp.StatusCode != 404 {
		t.Fatalf("without Reach: %d", resp.StatusCode)
	}
}

func TestRenderReachabilityRoute(t *testing.T) {
	o := renderOpts
	o.ReachabilityDial = "127.0.0.1:2021"
	raw, err := Render([]Route{{Spec: routeSpecs()[1]}}, o)
	if err != nil {
		t.Fatal(err)
	}
	var cfg struct {
		Apps struct {
			HTTP struct {
				Servers map[string]struct {
					Routes []json.RawMessage `json:"routes"`
				} `json:"servers"`
			} `json:"http"`
		} `json:"apps"`
	}
	if err := json.Unmarshal(raw, &cfg); err != nil {
		t.Fatal(err)
	}
	want := `{"handle":[{"handler":"reverse_proxy","upstreams":[{"dial":"127.0.0.1:2021"}]}],"match":[{"path":["/.well-known/launchroom/check/*"]}],"terminal":true}`
	for _, name := range []string{serverHTTP, serverHTTPS} {
		routes := cfg.Apps.HTTP.Servers[name].Routes
		if len(routes) < 3 {
			t.Fatalf("%s: %d routes", name, len(routes))
		}
		var compact strings.Builder
		var v any
		_ = json.Unmarshal(routes[0], &v)
		b, _ := json.Marshal(v)
		compact.Write(b)
		if compact.String() != want {
			t.Errorf("%s first route = %s", name, compact.String())
		}
	}
	for _, bad := range []string{"0.0.0.0:2021", "10.0.0.1:2021", "127.0.0.1:0", "nope"} {
		o.ReachabilityDial = bad
		if _, err := Render(nil, o); err == nil {
			t.Errorf("dial %q accepted", bad)
		}
	}
}
