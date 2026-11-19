package lrtrace

import (
	"context"
	"crypto/aes"
	"crypto/cipher"
	"encoding/base64"
	"errors"
	"io"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"

	"github.com/caddyserver/caddy/v2"
	"github.com/caddyserver/caddy/v2/modules/caddyhttp"
	"github.com/caddyserver/caddy/v2/modules/caddyhttp/reverseproxy"
	"github.com/corazawaf/coraza/v3"

	"github.com/vantezzen/launchroom/deploy/images/caddy/lrtrace/format"
)

const (
	edgeID    = "srv_01J9Z8X7W6V5T4S3R2Q1P0N9MK"
	appServer = "srv_01J9Z8X7W6V5T4S3R2Q1P0N9CC"
	routeID   = "rt_01J9Z8X7W6V5T4S3R2Q1P0N9AA"
	serviceID = "sin_01J9Z8X7W6V5T4S3R2Q1P0N9BB"
	appDial   = "100.64.0.7:41234"
)

var testKey = []byte("0123456789abcdef0123456789abcdef")

// newHandler provisions a handler with a key file in a temporary secrets
// directory.
func newHandler(t *testing.T) *Handler {
	t.Helper()
	dir := t.TempDir()
	old := secretsDir
	secretsDir = dir
	t.Cleanup(func() { secretsDir = old })
	path := filepath.Join(dir, "trace-key")
	if err := os.WriteFile(path, []byte(base64.StdEncoding.EncodeToString(testKey)+"\n"), 0o600); err != nil {
		t.Fatal(err)
	}
	h := &Handler{
		Edge: edgeID, KeyID: 42, KeyFile: path,
		Routes: map[string]RouteConfig{routeID: {Service: serviceID, Waf: "block", Upstreams: map[string]string{appDial: appServer}}},
	}
	if err := h.Provision(caddy.Context{}); err != nil {
		t.Fatal(err)
	}
	return h
}

func testAEAD(t *testing.T) cipher.AEAD {
	t.Helper()
	b, err := aes.NewCipher(testKey)
	if err != nil {
		t.Fatal(err)
	}
	g, err := cipher.NewGCM(b)
	if err != nil {
		t.Fatal(err)
	}
	return g
}

// decode opens a header with the test key.
func decode(t *testing.T, header string) format.Fields {
	t.Helper()
	if header == "" {
		t.Fatal("no trace header")
	}
	aead := testAEAD(t)
	id, p, err := format.Open(header, func(id uint32) cipher.AEAD {
		if id == 42 {
			return aead
		}
		return nil
	})
	if err != nil || id != 42 {
		t.Fatalf("open: %v", err)
	}
	f, err := format.Decode(p)
	if err != nil {
		t.Fatal(err)
	}
	return f
}

// caddyRequest is a request with the context Caddy's server gives it.
func caddyRequest(method, target string) *http.Request {
	r := httptest.NewRequestWithContext(context.Background(), method, target, nil)
	ctx := context.WithValue(r.Context(), caddy.ReplacerCtxKey, caddy.NewReplacer())
	ctx = context.WithValue(ctx, caddyhttp.VarsCtxKey, map[string]any{})
	return r.WithContext(ctx)
}

// proxy plays the app reverse_proxy: the render's vars, the policy's
// selection and the placeholders a successful round trip sets.
func proxy(t *testing.T, r *http.Request, pool reverseproxy.UpstreamPool) *reverseproxy.Upstream {
	t.Helper()
	caddyhttp.SetVar(r.Context(), varRoute, routeID)
	caddyhttp.SetVar(r.Context(), varStage, "app")
	p := new(RoundRobin)
	return p.Select(pool, r, nil)
}

func pool(dials ...string) reverseproxy.UpstreamPool {
	var out reverseproxy.UpstreamPool
	for _, d := range dials {
		out = append(out, &reverseproxy.Upstream{Host: new(reverseproxy.Host), Dial: d})
	}
	return out
}

func repl(r *http.Request) *caddy.Replacer {
	return r.Context().Value(caddy.ReplacerCtxKey).(*caddy.Replacer)
}

func TestAppResponse(t *testing.T) {
	h := newHandler(t)
	r := caddyRequest(http.MethodGet, "/users/123?token=secret")
	r.Header.Set(RequestIDHeader, "client-chosen")
	var seenID string
	w := httptest.NewRecorder()
	err := h.ServeHTTP(w, r, caddyhttp.HandlerFunc(func(w http.ResponseWriter, r *http.Request) error {
		seenID = r.Header.Get(RequestIDHeader)
		proxy(t, r, pool(appDial))
		repl(r).Set("http.reverse_proxy.upstream.latency", 1500*time.Microsecond)
		repl(r).Set("http.reverse_proxy.status_code", 201)
		w.WriteHeader(http.StatusCreated)
		_, _ = io.WriteString(w, "ok")
		return nil
	}))
	if err != nil {
		t.Fatal(err)
	}
	f := decode(t, w.Header().Get(format.Header))
	if seenID == "client-chosen" || seenID != f.RequestID || len(seenID) != 36 {
		t.Errorf("request id: app saw %q, trace has %q", seenID, f.RequestID)
	}
	if f.Edge != edgeID || f.Route != routeID || f.Service != serviceID || f.UpstreamServer != appServer {
		t.Errorf("ids: %+v", f)
	}
	if f.Method != "GET" || f.Path != "/users/:id" || f.Protocol != format.ProtocolHTTP11 || f.Status != 201 || f.Origin != format.OriginApp {
		t.Errorf("request: %+v", f)
	}
	if f.UpstreamTime == nil || *f.UpstreamTime != 1500 || f.UpstreamStatus != 201 || f.Attempts == nil || *f.Attempts != 1 || f.EdgeTime == nil {
		t.Errorf("upstream: %+v", f)
	}
	if f.Firewall != nil || f.Failure != 0 {
		t.Errorf("unexpected firewall or failure: %+v", f)
	}
	if d := time.Since(time.UnixMilli(f.ReceivedAt)); d < 0 || d > time.Minute {
		t.Errorf("receivedAt %d", f.ReceivedAt)
	}
}

// Placeholders of forward auth (an earlier proxy) never count as the app's.
func TestForwardAuthPlaceholdersCleared(t *testing.T) {
	h := newHandler(t)
	r := caddyRequest(http.MethodGet, "/")
	w := httptest.NewRecorder()
	_ = h.ServeHTTP(w, r, caddyhttp.HandlerFunc(func(w http.ResponseWriter, r *http.Request) error {
		caddyhttp.SetVar(r.Context(), varStage, "auth")
		repl(r).Set("http.reverse_proxy.status_code", 200)
		repl(r).Set("http.reverse_proxy.upstream.latency", time.Millisecond)
		proxy(t, r, pool(appDial))
		w.WriteHeader(499) // the client went away during the app's attempt
		return nil
	}))
	f := decode(t, w.Header().Get(format.Header))
	if f.UpstreamStatus != 0 || f.UpstreamTime != nil {
		t.Errorf("forward auth's placeholders leaked: %+v", f)
	}
	if f.Origin != format.OriginApp || f.Status != 499 {
		t.Errorf("origin %d status %d", f.Origin, f.Status)
	}
}

func TestUpstreamFailures(t *testing.T) {
	for name, tc := range map[string]struct {
		err     error
		failure uint8
		status  uint16
	}{
		"refused":     {caddyhttp.Error(http.StatusBadGateway, errors.New("dial tcp 100.64.0.7:41234: connect: connection refused")), format.FailureRefused, 502},
		"timeout":     {caddyhttp.Error(http.StatusGatewayTimeout, errors.New("dial tcp: i/o timeout")), format.FailureTimeout, 504},
		"no upstream": {caddyhttp.Error(http.StatusServiceUnavailable, errors.New("no upstreams available")), format.FailureNoUpstream, 503},
		"other":       {caddyhttp.Error(http.StatusBadGateway, errors.New("tls: bad certificate")), format.FailureOther, 502},
		"client gone": {caddyhttp.Error(499, context.Canceled), format.FailureClientClose, 499},
	} {
		t.Run(name, func(t *testing.T) {
			h := newHandler(t)
			r := caddyRequest(http.MethodPost, "/api")
			w := httptest.NewRecorder()
			err := h.ServeHTTP(w, r, caddyhttp.HandlerFunc(func(w http.ResponseWriter, r *http.Request) error {
				proxy(t, r, pool("10.0.0.9:1")) // a first attempt elsewhere
				proxy(t, r, pool(appDial))
				return tc.err
			}))
			if !errors.Is(err, tc.err) {
				t.Fatalf("error changed: %v", err)
			}
			if w.Code != http.StatusOK || w.Body.Len() != 0 {
				t.Fatalf("handler wrote a response itself: %d", w.Code)
			}
			f := decode(t, w.Header().Get(format.Header))
			if f.Origin != format.OriginUpstreamFailure || f.Failure != tc.failure || f.Status != tc.status {
				t.Errorf("origin %d failure %d status %d", f.Origin, f.Failure, f.Status)
			}
			if f.Attempts == nil || *f.Attempts != 2 || f.UpstreamServer != appServer {
				t.Errorf("attempts %v upstream %q", f.Attempts, f.UpstreamServer)
			}
		})
	}
}

func TestOrigins(t *testing.T) {
	for name, tc := range map[string]struct {
		vars   map[string]string
		status int
		err    error
		origin uint8
	}{
		"no route":             {nil, 404, nil, format.OriginNoRoute},
		"explicit no route":    {map[string]string{varOrigin: "no_route"}, 404, nil, format.OriginNoRoute},
		"redirect":             {map[string]string{varRoute: routeID, varOrigin: "redirect"}, 308, nil, format.OriginRedirect},
		"stopped":              {map[string]string{varRoute: routeID, varOrigin: "stopped"}, 503, nil, format.OriginStopped},
		"unavailable":          {map[string]string{varRoute: routeID, varOrigin: "unavailable"}, 503, nil, format.OriginUpstreamFailure},
		"refused page":         {map[string]string{varRoute: routeID, varOrigin: "refused"}, 403, nil, format.OriginAccessRefused},
		"forward auth said no": {map[string]string{varRoute: routeID, varStage: "auth"}, 302, nil, format.OriginAccessRefused},
		"forward auth down":    {map[string]string{varRoute: routeID, varStage: "auth"}, 0, caddyhttp.Error(502, errors.New("connection refused")), format.OriginEdge},
		"reachability":         {map[string]string{varOrigin: "edge"}, 200, nil, format.OriginEdge},
		"route, no stage":      {map[string]string{varRoute: routeID}, 500, nil, format.OriginEdge},
		"bad route var":        {map[string]string{varRoute: "../etc"}, 404, nil, format.OriginNoRoute},
		"unknown origin var":   {map[string]string{varOrigin: "bogus"}, 404, nil, format.OriginNoRoute},
	} {
		t.Run(name, func(t *testing.T) {
			h := newHandler(t)
			r := caddyRequest(http.MethodGet, "/")
			w := httptest.NewRecorder()
			_ = h.ServeHTTP(w, r, caddyhttp.HandlerFunc(func(w http.ResponseWriter, r *http.Request) error {
				for k, v := range tc.vars {
					caddyhttp.SetVar(r.Context(), k, v)
				}
				if tc.err != nil {
					return tc.err
				}
				w.WriteHeader(tc.status)
				return nil
			}))
			f := decode(t, w.Header().Get(format.Header))
			if f.Origin != tc.origin {
				t.Errorf("origin %d, want %d", f.Origin, tc.origin)
			}
			if tc.vars[varRoute] == routeID && f.Route != routeID {
				t.Errorf("route %q", f.Route)
			}
			if tc.vars[varRoute] != routeID && f.Route != "" {
				t.Errorf("route %q, want none", f.Route)
			}
			if name == "unavailable" && f.Failure != format.FailureNoUpstream {
				t.Errorf("failure %d", f.Failure)
			}
		})
	}
}

// headerLog records the header map at every WriteHeader.
type headerLog struct {
	http.ResponseWriter
	at map[int]string
}

func (w *headerLog) WriteHeader(s int) {
	w.at[s] = w.Header().Get(format.Header)
	w.ResponseWriter.WriteHeader(s)
}

func TestWriterPaths(t *testing.T) {
	for name, write := range map[string]func(w http.ResponseWriter){
		"write":     func(w http.ResponseWriter) { _, _ = w.Write([]byte("x")) },
		"read from": func(w http.ResponseWriter) { _, _ = io.Copy(w, strings.NewReader("x")) },
		"flush":     func(w http.ResponseWriter) { _ = http.NewResponseController(w).Flush() },
		"early hints then 200": func(w http.ResponseWriter) {
			w.WriteHeader(http.StatusEarlyHints)
			w.WriteHeader(http.StatusOK)
		},
		"twice": func(w http.ResponseWriter) {
			w.WriteHeader(http.StatusOK)
			w.Header().Del(format.Header)
			w.WriteHeader(http.StatusTeapot)
		},
	} {
		t.Run(name, func(t *testing.T) {
			h := newHandler(t)
			r := caddyRequest(http.MethodGet, "/")
			log := &headerLog{ResponseWriter: httptest.NewRecorder(), at: map[int]string{}}
			_ = h.ServeHTTP(log, r, caddyhttp.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) error {
				write(w)
				return nil
			}))
			if log.at[http.StatusEarlyHints] != "" {
				t.Error("trace set on 103")
			}
			if f := decode(t, log.at[http.StatusOK]); f.Status != 200 {
				t.Errorf("status %d", f.Status)
			}
		})
	}
}

// A WebSocket upgrade gets the trace on its 101 and can still be hijacked
// through Unwrap.
func TestSwitchingProtocols(t *testing.T) {
	h := newHandler(t)
	r := caddyRequest(http.MethodGet, "/ws")
	log := &headerLog{ResponseWriter: httptest.NewRecorder(), at: map[int]string{}}
	_ = h.ServeHTTP(log, r, caddyhttp.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) error {
		w.WriteHeader(http.StatusSwitchingProtocols)
		if u, ok := w.(interface{ Unwrap() http.ResponseWriter }); !ok || u.Unwrap() == nil {
			t.Error("no Unwrap")
		}
		return nil
	}))
	if f := decode(t, log.at[http.StatusSwitchingProtocols]); f.Status != 101 {
		t.Errorf("status %d", f.Status)
	}
}

func TestProvisionRefuses(t *testing.T) {
	for name, edit := range map[string]func(h *Handler){
		"key outside secrets": func(h *Handler) { h.KeyFile = "/etc/passwd" },
		"key with dots":       func(h *Handler) { h.KeyFile = secretsDir + "/../trace-key" },
		"relative key":        func(h *Handler) { h.KeyFile = "trace-key" },
		"missing key":         func(h *Handler) { h.KeyFile = secretsDir + "/nope" },
		"bad edge":            func(h *Handler) { h.Edge = "srv_x" },
		"no key id":           func(h *Handler) { h.KeyID = 0 },
		"bad route":           func(h *Handler) { h.Routes = map[string]RouteConfig{"rt_x": {}} },
		"bad service": func(h *Handler) {
			h.Routes = map[string]RouteConfig{routeID: {Service: "svc_01J9Z8X7W6V5T4S3R2Q1P0N9BB"}}
		},
		"bad waf": func(h *Handler) { h.Routes = map[string]RouteConfig{routeID: {Waf: "off"}} },
		"bad upstream server": func(h *Handler) {
			h.Routes = map[string]RouteConfig{routeID: {Upstreams: map[string]string{appDial: "x"}}}
		},
	} {
		t.Run(name, func(t *testing.T) {
			h := newHandler(t)
			edit(h)
			if err := h.Provision(caddy.Context{}); err == nil {
				t.Fatal("provisioned")
			}
		})
	}
	t.Run("short key", func(t *testing.T) {
		h := newHandler(t)
		if err := os.WriteFile(h.KeyFile, []byte("c2hvcnQ="), 0o600); err != nil {
			t.Fatal(err)
		}
		err := h.Provision(caddy.Context{})
		if err == nil || strings.Contains(err.Error(), "c2hvcnQ") {
			t.Fatalf("err = %v", err)
		}
	})
}

// The firewall: the Coraza action copies the decision into the request's
// slot, keyed by the transaction id (= request id).
func TestFirewallAction(t *testing.T) {
	const directives = `
SecRule ARGS:q "@streq attack" "id:942100,phase:2,pass,log,setvar:tx.blocking_inbound_anomaly_score=+5"
SecRule ARGS:q "@streq attack" "id:901200,phase:2,pass,log"
SecRule ARGS:q "@streq attack" "id:911013,phase:2,pass,nolog"
SecRule TX:BLOCKING_INBOUND_ANOMALY_SCORE "@ge %{tx.threshold}" "id:949110,phase:2,deny,status:403,log"
SecAction "id:99997,phase:2,pass,nolog,lrtrace"
SecAction "id:99998,phase:3,pass,nolog,lrtrace"
SecAction "id:99999,phase:5,pass,nolog,lrtrace"
`
	for name, tc := range map[string]struct {
		engine, threshold, mode string
		wantMode, wantOutcome   uint8
	}{
		"blocked":                    {"On", "5", "block", format.FirewallModeBlock, format.FirewallBlocked},
		"below threshold":            {"On", "100", "block", format.FirewallModeBlock, format.FirewallClean},
		"log only":                   {"DetectionOnly", "5", "log", format.FirewallModeLog, format.FirewallDetected},
		"log only, mode from engine": {"DetectionOnly", "5", "", format.FirewallModeLog, format.FirewallDetected},
	} {
		t.Run(name, func(t *testing.T) {
			waf, err := coraza.NewWAF(coraza.NewWAFConfig().WithDirectives(
				"SecRuleEngine " + tc.engine + "\nSecAction \"id:1,phase:1,pass,nolog,setvar:tx.threshold=" + tc.threshold + "\"\n" + directives))
			if err != nil {
				t.Fatal(err)
			}
			h := newHandler(t)
			rc := h.Routes[routeID]
			rc.Waf = tc.mode
			h.Routes[routeID] = rc
			r := caddyRequest(http.MethodGet, "/search?q=attack")
			w := httptest.NewRecorder()
			err = h.ServeHTTP(w, r, caddyhttp.HandlerFunc(func(w http.ResponseWriter, r *http.Request) error {
				caddyhttp.SetVar(r.Context(), varRoute, routeID)
				tx := waf.NewTransactionWithID(r.Header.Get(RequestIDHeader))
				defer func() { _ = tx.Close() }()
				tx.ProcessURI(r.RequestURI, r.Method, r.Proto)
				it := tx.ProcessRequestHeaders()
				if it == nil {
					it, _ = tx.ProcessRequestBody()
				}
				tx.ProcessLogging()
				if it != nil {
					return caddyhttp.Error(it.Status, errors.New("interruption triggered"))
				}
				caddyhttp.SetVar(r.Context(), varStage, "app")
				w.WriteHeader(http.StatusOK)
				return nil
			}))
			f := decode(t, w.Header().Get(format.Header))
			fw := f.Firewall
			if fw == nil {
				t.Fatal("no firewall field")
			}
			if fw.Mode != tc.wantMode || fw.Outcome != tc.wantOutcome || fw.Score != 5 || len(fw.Rules) != 1 || fw.Rules[0] != 942100 {
				t.Errorf("firewall %+v", *fw)
			}
			blocked := tc.wantOutcome == format.FirewallBlocked
			if blocked != (err != nil) || blocked != (f.Origin == format.OriginFirewall) || blocked && f.Status != 403 {
				t.Errorf("blocked=%v: err %v, origin %d, status %d", blocked, err, f.Origin, f.Status)
			}
		})
	}
}

// Without the handler (no slot) the action does nothing.
func TestFirewallActionWithoutSlot(t *testing.T) {
	waf, err := coraza.NewWAF(coraza.NewWAFConfig().WithDirectives("SecRuleEngine On\nSecAction \"id:99997,phase:1,pass,nolog,lrtrace\""))
	if err != nil {
		t.Fatal(err)
	}
	tx := waf.NewTransactionWithID("not-registered")
	defer func() { _ = tx.Close() }()
	tx.ProcessURI("/", "GET", "HTTP/1.1")
	if it := tx.ProcessRequestHeaders(); it != nil {
		t.Fatal(it)
	}
	if _, err := coraza.NewWAF(coraza.NewWAFConfig().WithDirectives("SecAction \"id:5,phase:1,pass,nolog,lrtrace:x\"")); err == nil {
		t.Error("action with an argument accepted")
	}
}

// A dial the route's map doesn't name is a local workload: the edge.
func TestLocalUpstreamIsEdge(t *testing.T) {
	h := newHandler(t)
	r := caddyRequest(http.MethodGet, "/")
	w := httptest.NewRecorder()
	_ = h.ServeHTTP(w, r, caddyhttp.HandlerFunc(func(w http.ResponseWriter, r *http.Request) error {
		proxy(t, r, pool("172.18.0.5:3000"))
		w.WriteHeader(http.StatusOK)
		return nil
	}))
	if f := decode(t, w.Header().Get(format.Header)); f.UpstreamServer != edgeID {
		t.Errorf("upstreamServer %q, want the edge", f.UpstreamServer)
	}
}
