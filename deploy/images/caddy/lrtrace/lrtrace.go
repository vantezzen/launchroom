// Package lrtrace is launchroom's request trace for the edge Caddy
// (docs/23 §3): the handler http.handlers.lr_trace, which gives every request
// an X-Request-Id and every response a Launchroom-Trace header; the
// selection policy lr_round_robin, which counts upstream attempts; and the
// Coraza action lrtrace, which copies the firewall's decision into the
// request's trace. Nothing per request is kept after the response.
package lrtrace

import (
	"context"
	"crypto/aes"
	"crypto/cipher"
	"crypto/rand"
	"encoding/base64"
	"errors"
	"fmt"
	"math"
	"net/http"
	"os"
	"path/filepath"
	"strings"
	"sync"
	"time"

	"github.com/caddyserver/caddy/v2"
	"github.com/caddyserver/caddy/v2/modules/caddyhttp"
	"github.com/caddyserver/caddy/v2/modules/caddyhttp/reverseproxy"
	"github.com/corazawaf/coraza/v3/experimental/plugins"

	"github.com/vantezzen/launchroom/deploy/images/caddy/lrtrace/format"
	"github.com/vantezzen/launchroom/deploy/images/caddy/lrtrace/pathnorm"
)

func init() {
	caddy.RegisterModule(new(Handler))
	caddy.RegisterModule(new(RoundRobin))
	plugins.RegisterAction(ActionName, newAction)
}

// RequestIDHeader carries the request id to the app, forward auth and the
// firewall (whose transaction id it becomes, tx_id_req_header).
const RequestIDHeader = "X-Request-Id"

// Caddy vars the render sets per route (docs/23 §3).
const (
	varRoute  = "lr_route"
	varStage  = "lr_stage"
	varOrigin = "lr_origin"
)

// secretsDir is launchroomd's secrets directory in the Caddy container;
// the key file must be directly inside it. A variable for the tests.
var secretsDir = "/data/launchroom/secrets"

// Handler is http.handlers.lr_trace. It must be the first handler of every
// server, in a non-terminal route without matchers.
type Handler struct {
	// Edge is this server's id (srv_…).
	Edge string `json:"edge"`
	// KeyID names the key in the header.
	KeyID uint32 `json:"key_id"`
	// KeyFile holds the AES-256 key, standard base64. Read once at
	// provisioning; never logged.
	KeyFile string `json:"key_file"`
	// Routes by route id (rt_…).
	Routes map[string]RouteConfig `json:"routes,omitempty"`

	aead cipher.AEAD
}

// RouteConfig is what the edge knows about a route beyond its id.
type RouteConfig struct {
	// Service is the route's service instance (sin_…), empty for the
	// dashboard's own routes.
	Service string `json:"service,omitempty"`
	// Waf is the firewall mode, "block" or "log"; empty without firewall.
	Waf string `json:"waf,omitempty"`
	// Upstreams maps the app proxy's dial addresses to their servers (srv_…).
	Upstreams map[string]string `json:"upstreams,omitempty"`
}

// CaddyModule returns the Caddy module information.
func (*Handler) CaddyModule() caddy.ModuleInfo {
	return caddy.ModuleInfo{ID: "http.handlers.lr_trace", New: func() caddy.Module { return new(Handler) }}
}

// Provision checks the config and loads the key.
func (h *Handler) Provision(caddy.Context) error {
	if _, err := format.ParseID("srv", h.Edge); err != nil {
		return errors.New("lr_trace: edge is not a server id")
	}
	if h.KeyID == 0 {
		return errors.New("lr_trace: key_id is required")
	}
	for id, rc := range h.Routes {
		if _, err := format.ParseID("rt", id); err != nil {
			return fmt.Errorf("lr_trace: route %q is not a route id", id)
		}
		if _, err := format.ParseID("sin", rc.Service); rc.Service != "" && err != nil {
			return fmt.Errorf("lr_trace: route %s: service is not a service instance id", id)
		}
		switch rc.Waf {
		case "", "block", "log":
		default:
			return fmt.Errorf("lr_trace: route %s: waf is not block or log", id)
		}
		for dial, srv := range rc.Upstreams {
			if _, err := format.ParseID("srv", srv); err != nil {
				return fmt.Errorf("lr_trace: route %s: upstream %q: not a server id", id, dial)
			}
		}
	}
	aead, err := loadKey(h.KeyFile)
	if err != nil {
		return err
	}
	h.aead = aead
	return nil
}

// loadKey reads the key file, which must be directly in secretsDir. Errors
// never quote the file's content.
func loadKey(path string) (cipher.AEAD, error) {
	if path == "" || !filepath.IsAbs(path) || filepath.Clean(path) != path || filepath.Dir(path) != secretsDir {
		return nil, errors.New("lr_trace: key_file must be a file in " + secretsDir)
	}
	data, err := os.ReadFile(path) //nolint:gosec // checked to be in the secrets directory
	if err != nil {
		return nil, errors.New("lr_trace: can't read key_file")
	}
	defer clear(data)
	text := strings.TrimSpace(string(data))
	key, err := base64.StdEncoding.DecodeString(text)
	if err != nil {
		key, err = base64.RawStdEncoding.DecodeString(text)
	}
	defer clear(key)
	if err != nil || len(key) != 32 {
		return nil, errors.New("lr_trace: key_file must hold 32 bytes, standard base64")
	}
	block, err := aes.NewCipher(key)
	if err != nil {
		return nil, errors.New("lr_trace: bad key")
	}
	return cipher.NewGCM(block)
}

// stateKey finds the request's state in its context (the proxy's cloned
// requests share it).
type stateKey struct{}

// reqState is what one request collects for its trace. The firewall action
// and the selection policy write it, the response writer reads it.
type reqState struct {
	start  time.Time
	id     string
	method string
	uri    string
	proto  uint8

	mu sync.Mutex
	// Firewall (Coraza action).
	fwSeen, fwInterrupted, fwDetected, fwDetectionOnly bool
	fwScore                                            int
	fwRules                                            []uint32
	// App proxy (selection policy).
	lbUsed   bool
	attempts int
	lastDial string
}

func stateOf(ctx context.Context) *reqState {
	st, _ := ctx.Value(stateKey{}).(*reqState)
	return st
}

// ServeHTTP sets the request id, runs the rest of the server and puts the
// trace on the response: in the final WriteHeader, or, when a handler
// returned an error, on the header map the error pages then write.
func (h *Handler) ServeHTTP(w http.ResponseWriter, r *http.Request, next caddyhttp.Handler) error {
	st := &reqState{start: time.Now(), id: newRequestID(), method: r.Method, uri: r.RequestURI, proto: protocolOf(r)}
	r.Header.Set(RequestIDHeader, st.id)
	defer slots.add(st.id, st)()
	r = r.WithContext(context.WithValue(r.Context(), stateKey{}, st))
	tw := &traceWriter{ResponseWriterWrapper: &caddyhttp.ResponseWriterWrapper{ResponseWriter: w}, h: h, st: st, r: r}
	err := next.ServeHTTP(tw, r)
	if err != nil && tw.claim() {
		h.setTrace(w.Header(), r, st, errorStatus(err), err)
	}
	return err
}

// setTrace sets the header; a trace that can't be built is left out, the
// response never fails because of it.
func (h *Handler) setTrace(hdr http.Header, r *http.Request, st *reqState, status int, err error) {
	if v, ok := h.trace(r, st, status, err); ok {
		hdr.Set(format.Header, v)
	}
}

func (h *Handler) trace(r *http.Request, st *reqState, status int, handlerErr error) (string, bool) {
	f := h.fields(r, st, status, handlerErr)
	p, err := format.Encode(f)
	if err != nil {
		return "", false
	}
	v, err := format.Seal(h.aead, h.KeyID, nil, p)
	return v, err == nil
}

// fields builds the trace of a response with this status (docs/23 §2).
func (h *Handler) fields(r *http.Request, st *reqState, status int, handlerErr error) format.Fields {
	f := format.Fields{
		ReceivedAt: st.start.UnixMilli(),
		RequestID:  st.id,
		Edge:       h.Edge,
		Method:     st.method,
		Protocol:   st.proto,
		Status:     uint16(min(max(status, 0), math.MaxUint16)), //nolint:gosec // clamped
	}
	if p, ok := pathnorm.Normalize(st.uri); ok {
		f.Path = p
	}
	et := micros(time.Since(st.start))
	f.EdgeTime = &et

	route, stage, originVar := stringVar(r, varRoute), stringVar(r, varStage), stringVar(r, varOrigin)
	var rc RouteConfig
	if _, err := format.ParseID("rt", route); err == nil {
		f.Route = route
		rc = h.Routes[route]
		f.Service = rc.Service
	} else {
		route = ""
	}

	st.mu.Lock()
	fwSeen, fwInterrupted, fwDetected, fwDetectionOnly := st.fwSeen, st.fwInterrupted, st.fwDetected, st.fwDetectionOnly
	fwScore, fwRules := st.fwScore, append([]uint32(nil), st.fwRules...)
	lbUsed, attempts, lastDial := st.lbUsed, st.attempts, st.lastDial
	st.mu.Unlock()

	if stage == "app" && lbUsed {
		a := uint8(min(attempts, math.MaxUint8)) //nolint:gosec // clamped
		f.Attempts = &a
		if lastDial != "" {
			// Dials the map doesn't name are local workloads: the edge itself.
			f.UpstreamServer = h.Edge
			if srv, ok := rc.Upstreams[lastDial]; ok {
				f.UpstreamServer = srv
			}
		}
		// The policy cleared these before each attempt, so they are the
		// app's (never forward auth's).
		if repl, ok := r.Context().Value(caddy.ReplacerCtxKey).(*caddy.Replacer); ok {
			if v, ok := repl.Get("http.reverse_proxy.upstream.latency"); ok {
				if d, ok := v.(time.Duration); ok {
					t := micros(d)
					f.UpstreamTime = &t
				}
			}
			if v, ok := repl.Get("http.reverse_proxy.status_code"); ok {
				if s, ok := v.(int); ok && s > 0 && s <= math.MaxUint16 {
					f.UpstreamStatus = uint16(s)
				}
			}
		}
	}

	clientGone := status == 499 && r.Context().Err() != nil
	switch {
	case fwInterrupted:
		f.Origin = format.OriginFirewall
	case originVar == "redirect":
		f.Origin = format.OriginRedirect
	case originVar == "stopped":
		f.Origin = format.OriginStopped
	case originVar == "unavailable":
		f.Origin, f.Failure = format.OriginUpstreamFailure, format.FailureNoUpstream
	case originVar == "refused":
		f.Origin = format.OriginAccessRefused
	case originVar == "no_route":
		f.Origin = format.OriginNoRoute
	case originVar == "edge":
		f.Origin = format.OriginEdge
	case stage == "app" && (handlerErr != nil || clientGone):
		f.Origin, f.Failure = format.OriginUpstreamFailure, failureOf(handlerErr, status, clientGone)
	case stage == "app":
		f.Origin = format.OriginApp
	case stage == "auth" && (handlerErr != nil || clientGone):
		f.Origin, f.Failure = format.OriginEdge, failureOf(handlerErr, status, clientGone)
	case stage == "auth":
		f.Origin = format.OriginAccessRefused
	case route == "":
		f.Origin = format.OriginNoRoute
	default:
		f.Origin = format.OriginEdge
	}
	if f.Origin != format.OriginApp && f.Origin != format.OriginUpstreamFailure {
		// The upstream fields describe the app proxy only.
		f.UpstreamTime, f.UpstreamStatus = nil, 0
	}

	if fwSeen {
		fw := &format.Firewall{Mode: format.FirewallModeBlock, Outcome: format.FirewallClean, Score: uint8(min(max(fwScore, 0), math.MaxUint8)), Rules: fwRules} //nolint:gosec // clamped
		switch {
		case rc.Waf == "log", rc.Waf == "" && fwDetectionOnly:
			fw.Mode = format.FirewallModeLog
		}
		switch {
		case fwInterrupted:
			fw.Outcome = format.FirewallBlocked
		case fwDetected:
			fw.Outcome = format.FirewallDetected
		}
		f.Firewall = fw
	}
	return f
}

// failureOf classifies why the upstream (or forward auth) failed. The
// proxy's dial errors don't unwrap to their cause (reverseproxy.DialError),
// so the message is the last resort.
func failureOf(err error, status int, clientGone bool) uint8 {
	if clientGone || status == 499 || errors.Is(err, context.Canceled) {
		return format.FailureClientClose
	}
	if status == http.StatusServiceUnavailable {
		return format.FailureNoUpstream // every upstream marked down: "no upstreams available"
	}
	msg := ""
	if err != nil {
		msg = err.Error()
	}
	switch {
	case strings.Contains(msg, "connection refused"):
		return format.FailureRefused
	case status == http.StatusGatewayTimeout, errors.Is(err, context.DeadlineExceeded),
		strings.Contains(msg, "timeout"), strings.Contains(msg, "deadline exceeded"):
		return format.FailureTimeout
	}
	return format.FailureOther
}

// errorStatus is the status Caddy answers a handler error with.
func errorStatus(err error) int {
	var he caddyhttp.HandlerError
	if errors.As(err, &he) && he.StatusCode >= 100 && he.StatusCode <= 999 {
		return he.StatusCode
	}
	return http.StatusInternalServerError
}

func stringVar(r *http.Request, name string) string {
	s, _ := caddyhttp.GetVar(r.Context(), name).(string)
	return s
}

func micros(d time.Duration) uint32 {
	return uint32(min(max(d.Microseconds(), 0), math.MaxUint32)) //nolint:gosec // clamped
}

func protocolOf(r *http.Request) uint8 {
	switch {
	case r.ProtoMajor == 3:
		return format.ProtocolHTTP3
	case r.ProtoMajor == 2:
		return format.ProtocolHTTP2
	case r.ProtoMajor == 1 && r.ProtoMinor == 1:
		return format.ProtocolHTTP11
	case r.ProtoMajor == 1 && r.ProtoMinor == 0:
		return format.ProtocolHTTP10
	}
	return 0
}

// newRequestID is a random (version 4) UUID.
func newRequestID() string {
	var b [16]byte
	_, _ = rand.Read(b[:]) // crypto/rand.Read never fails
	b[6] = b[6]&0x0f | 0x40
	b[8] = b[8]&0x3f | 0x80
	return format.UUIDString(b)
}

// Interface guards.
var (
	_ caddy.Provisioner           = (*Handler)(nil)
	_ caddyhttp.MiddlewareHandler = (*Handler)(nil)
	_ reverseproxy.Selector       = (*RoundRobin)(nil)
)
