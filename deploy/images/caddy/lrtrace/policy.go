package lrtrace

import (
	"net/http"

	"github.com/caddyserver/caddy/v2"
	"github.com/caddyserver/caddy/v2/modules/caddyhttp/reverseproxy"
)

// RoundRobin is http.reverse_proxy.selection_policies.lr_round_robin: round
// robin that also counts the attempts and records the last upstream for the
// trace (Caddy only knows the retry count after the response). Used on the
// app proxy only.
type RoundRobin struct {
	reverseproxy.RoundRobinSelection
}

// CaddyModule returns the Caddy module information.
func (*RoundRobin) CaddyModule() caddy.ModuleInfo {
	return caddy.ModuleInfo{
		ID:  "http.reverse_proxy.selection_policies.lr_round_robin",
		New: func() caddy.Module { return new(RoundRobin) },
	}
}

// Select picks like round_robin. Before each attempt it clears the proxy
// placeholders the trace reads, so values of an earlier proxy (forward auth)
// or a failed attempt never count as the app's.
func (p *RoundRobin) Select(pool reverseproxy.UpstreamPool, r *http.Request, w http.ResponseWriter) *reverseproxy.Upstream {
	up := p.RoundRobinSelection.Select(pool, r, w)
	if repl, ok := r.Context().Value(caddy.ReplacerCtxKey).(*caddy.Replacer); ok {
		repl.Delete("http.reverse_proxy.upstream.latency")
		repl.Delete("http.reverse_proxy.status_code")
	}
	if st := stateOf(r.Context()); st != nil {
		st.mu.Lock()
		st.lbUsed = true
		if up != nil {
			st.attempts++
			st.lastDial = up.Dial
		}
		st.mu.Unlock()
	}
	return up
}
