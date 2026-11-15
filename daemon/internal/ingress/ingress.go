// Package ingress is what the workload converger tells the edge about
// same-server upstreams. The edge (ingress/caddy) decides how routes use it.
package ingress

import (
	"net/netip"

	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// Endpoint is what launchroomd observed about a same-server workload upstream.
type Endpoint struct {
	Addr    netip.Addr // container IP on its primary network
	Serving bool       // role serving (not draining)
	Ready   bool       // running and healthy (or running long enough without a probe)
}

// Resolver looks up a workload's endpoint (ok=false: no container).
type Resolver func(id state.WorkloadID) (Endpoint, bool)
