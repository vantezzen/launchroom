package caddy

import (
	"errors"
	"fmt"
	"net/netip"
	"slices"

	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// Request trace (docs/23 §3): launchroom-caddy carries the module
// deploy/images/caddy/lrtrace, which writes an encrypted Launchroom-Trace
// header on every response. launchroomd renders its handler first on both
// servers, so it wraps every other route, and tags the routes with variables
// the handler reads when the response headers are written.

// TraceOptions turns the request trace on. Set only when the running Caddy
// carries the module (TraceSupported) and the edge's key could be opened.
type TraceOptions struct {
	// Edge is this server: the trace's edge and the server of local upstreams.
	Edge state.ServerID
	// KeyID is the id of the key in KeyFile (written into every header).
	KeyID uint32
	// KeyFile is the container path of the key (TraceKeyFile); the module
	// reads it once and only accepts files under SecretsDir.
	KeyFile string
}

// TraceKeyFile is the container path of the edge's trace key: the
// IngressSecret's "key" value (standard base64 of 32 bytes), never in the
// config.
const TraceKeyFile = SecretsDir + "/trace-key"

// Caddy variables the trace handler reads (docs/23 §3).
const (
	// traceRouteVar is the matched route's id ("" when none matched).
	traceRouteVar = "lr_route"
	// traceStageVar is the proxy that runs next: traceStageAuth (forward
	// auth) or traceStageApp (the app), so the upstream placeholders are
	// attributed to the right one.
	traceStageVar  = "lr_stage"
	traceStageAuth = "auth"
	traceStageApp  = "app"
	// traceOriginVar names who produced a response the edge answers itself
	// (docs/23 §2.2); responses from the app, the firewall and upstream
	// failures are classified by the handler.
	traceOriginVar = "lr_origin"
)

// Values of traceOriginVar (docs/23 §2.2).
const (
	traceOriginStopped  = "stopped"
	traceOriginRedirect = "redirect"
	traceOriginNoRoute  = "no_route"
	// traceOriginRefused: a policy refusal (forward auth or client
	// certificates), the edge's own 401/403 page.
	traceOriginRefused = "refused"
	traceOriginEdge    = "edge"
	// traceOriginUnavailable: the 503 page of a route without a ready
	// upstream (upstream failure "no healthy upstream").
	traceOriginUnavailable = "unavailable"
)

// traceRequestIDHeader is the request id the handler sets on the request
// (overwriting a client's value); the firewall uses it as its transaction
// id, so the trace finds the firewall's verdict under it (docs/23 §3).
const traceRequestIDHeader = "X-Request-Id"

// Firewall rules that copy the verdict into the trace (Coraza action
// "lrtrace" of the module) after CRS's rules: phase 2 for requests that
// pass, 3 for response-header matches, 5 for blocked requests. Their ids
// are reserved at the top of the local range (docs/22 §6.3).
const (
	traceRuleFirst = 99997
	traceRules     = `SecAction "id:99997,phase:2,pass,nolog,lrtrace"
SecAction "id:99998,phase:3,pass,nolog,lrtrace"
SecAction "id:99999,phase:5,pass,nolog,lrtrace"
`
)

// traceSelectionPolicy wraps round robin and counts the attempts, so the
// trace knows them on success too.
const traceSelectionPolicy = "lr_round_robin"

// traceHandler is the lr_trace handler: this edge, the key, and per route
// the service instance, the firewall mode and the server behind each dial
// address. The module refuses the whole config on an invalid id, so values
// failing their grammar are left out.
func traceHandler(t *TraceOptions, routes []Route, o Options) (obj, error) {
	if err := t.Edge.Validate(); err != nil {
		return nil, fmt.Errorf("caddy: trace: %w", err)
	}
	if t.KeyID == 0 || t.KeyFile != TraceKeyFile {
		return nil, errors.New("caddy: trace: key id and key file are required")
	}
	byRoute := obj{}
	for _, r := range routes {
		if r.Spec.ID.Validate() != nil {
			continue
		}
		entry := obj{}
		if r.Spec.Service != "" && r.Spec.Service.Validate() == nil {
			entry["service"] = string(r.Spec.Service)
		}
		if o.wafOn(r) && r.Spec.Waf.Mode.Validate() == nil {
			entry["waf"] = string(r.Spec.Waf.Mode)
		}
		if ups := traceUpstreams(r, t.Edge, o); len(ups) > 0 {
			entry["upstreams"] = ups
		}
		if len(entry) > 0 {
			byRoute[string(r.Spec.ID)] = entry
		}
	}
	h := obj{"handler": "lr_trace", "edge": string(t.Edge), "key_id": t.KeyID, "key_file": t.KeyFile}
	if len(byRoute) > 0 {
		h["routes"] = byRoute
	}
	return h, nil
}

// traceUpstreams maps a route's dial addresses to servers: replicas on this
// edge (resolved workloads and LR_ALLOWED_LOCAL_UPSTREAMS) to the edge,
// mesh addresses to the server the dashboard named (none: left out).
func traceUpstreams(r Route, edge state.ServerID, o Options) obj {
	remote := map[string]state.ServerID{}
	for _, u := range r.Spec.Upstreams {
		if u.Address == "" {
			continue
		}
		switch {
		case u.Server != "" && u.Server.Validate() == nil:
			if _, dup := remote[string(u.Address)]; !dup {
				remote[string(u.Address)] = u.Server
			}
		case slices.Contains(o.LocalUpstreams, string(u.Address)):
			remote[string(u.Address)] = edge
		}
	}
	out := obj{}
	for _, d := range r.Dial {
		if _, err := netip.ParseAddrPort(d); err != nil {
			continue
		}
		if srv, ok := remote[d]; ok {
			out[d] = string(srv)
			continue
		}
		if !isAddressUpstream(r.Spec, d) {
			out[d] = string(edge) // a workload on this server
		}
	}
	return out
}

func isAddressUpstream(r state.RouteSpec, dial string) bool {
	for _, u := range r.Upstreams {
		if string(u.Address) == dial {
			return true
		}
	}
	return false
}

// traceVars is a vars handler with the trace variables (nil without trace).
func (o Options) traceVars(kv ...string) obj {
	if o.Trace == nil {
		return nil
	}
	h := obj{"handler": "vars"}
	for i := 0; i+1 < len(kv); i += 2 {
		h[kv[i]] = kv[i+1]
	}
	return h
}

// traceRoute is a subroute entry with traceVars (nil without trace).
func (o Options) traceRoute(kv ...string) []any {
	if h := o.traceVars(kv...); h != nil {
		return []any{obj{"handle": []any{h}}}
	}
	return nil
}

// withTraceVars prepends traceVars to a handler list.
func (o Options) withTraceVars(handle []any, kv ...string) []any {
	if h := o.traceVars(kv...); h != nil {
		return append([]any{h}, handle...)
	}
	return handle
}

// TraceSupported reads the trace module label of a Caddy container (image
// labels are merged into container labels, and the image is digest-pinned,
// docs/09 R31).
func TraceSupported(labels map[string]string) bool {
	return labels[LabelTrace] == "1"
}

// LabelTrace is the image label of launchroom-caddy builds with the trace
// module (deploy/images/caddy).
const LabelTrace = "io.vantezzen.launchroom.trace"
