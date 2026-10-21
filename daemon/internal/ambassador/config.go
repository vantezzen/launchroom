// Package ambassador is the per-server TCP forwarder that lets workloads
// reach dependencies on other servers by their usual names (docs/05 §3):
// `api` on server B keeps connecting to `postgres:5432`, and the ambassador,
// attached to the environment network with the alias `postgres`, forwards to
// the mesh-published port of the replica on server A (`100.64.0.7:31001`).
//
// launchroomd runs it as the system container lr-system-ambassador and
// pushes its configuration (Config) as a file; the forwarder re-reads it
// every second and applies changes without dropping unaffected
// connections. Per listener it balances over the upstreams (least
// connections by default, or round robin), skips upstreams whose TCP health
// check fails, and drains connections of removed listeners or upstreams for
// a bounded time.
//
// The package only depends on the standard library: it is linked into the
// small launchroom-ambassador binary, not only into launchroomd.
package ambassador

import (
	"errors"
	"fmt"
	"net/netip"
	"slices"
	"time"
)

// Balancing policies.
const (
	// LeastConn sends a new connection to the healthy upstream with the
	// fewest open connections (ties: round robin). Default.
	LeastConn = "least_conn"
	// RoundRobin cycles through the healthy upstreams.
	RoundRobin = "round_robin"
)

// Limits.
const (
	MaxListeners = 1024
	MaxUpstreams = 64
	// DefaultDrain bounds how long connections of a removed listener or
	// upstream keep running before they are closed.
	DefaultDrain = 30 * time.Second
	maxDrain     = time.Hour
)

// Config is the file launchroomd writes (JSON).
type Config struct {
	Listeners []Listener `json:"listeners"`
	// DrainSeconds bounds connection draining (0 = DefaultDrain).
	DrainSeconds int `json:"drainSeconds,omitempty"`
}

// Listener is one (network, alias, port) of the NodeState's ambassadors,
// resolved by launchroomd to the ambassador's address on that network.
type Listener struct {
	// Listen is the ambassador's ip:port on the environment network.
	Listen string `json:"listen"`
	// Clients is the environment network's subnet: connections from any
	// other source are refused (defense in depth; the firewall already
	// keeps other environments off that network).
	Clients string `json:"clients"`
	// Upstreams are mesh-published ports on other servers (ip:port).
	Upstreams []string `json:"upstreams"`
	// Balance is LeastConn (default) or RoundRobin.
	Balance string `json:"balance,omitempty"`
}

// Drain returns the effective drain timeout.
func (c Config) Drain() time.Duration {
	if c.DrainSeconds <= 0 {
		return DefaultDrain
	}
	return min(time.Duration(c.DrainSeconds)*time.Second, maxDrain)
}

// Validate checks every field; the forwarder never applies an invalid file.
func (c Config) Validate() error {
	if len(c.Listeners) > MaxListeners {
		return fmt.Errorf("ambassador: %d listeners (max %d)", len(c.Listeners), MaxListeners)
	}
	if c.DrainSeconds < 0 {
		return errors.New("ambassador: drainSeconds must not be negative")
	}
	seen := map[netip.AddrPort]bool{}
	var errs []error
	for i, l := range c.Listeners {
		ap, err := netip.ParseAddrPort(l.Listen)
		if err != nil || ap.Port() == 0 || !ap.Addr().IsValid() || ap.Addr().IsUnspecified() {
			errs = append(errs, fmt.Errorf("ambassador: listeners[%d].listen %q is not ip:port", i, l.Listen))
			continue
		}
		if seen[ap] {
			errs = append(errs, fmt.Errorf("ambassador: listeners[%d].listen %s is duplicated", i, ap))
		}
		seen[ap] = true
		p, err := netip.ParsePrefix(l.Clients)
		if err != nil || !p.Contains(ap.Addr()) {
			errs = append(errs, fmt.Errorf("ambassador: listeners[%d].clients %q must be the subnet of %s", i, l.Clients, ap.Addr()))
		}
		if len(l.Upstreams) == 0 || len(l.Upstreams) > MaxUpstreams {
			errs = append(errs, fmt.Errorf("ambassador: listeners[%d] needs 1-%d upstreams", i, MaxUpstreams))
		}
		for j, u := range l.Upstreams {
			up, err := netip.ParseAddrPort(u)
			if err != nil || up.Port() == 0 || !up.Addr().IsValid() || up.Addr().IsUnspecified() {
				errs = append(errs, fmt.Errorf("ambassador: listeners[%d].upstreams[%d] %q is not ip:port", i, j, u))
			}
		}
		if len(l.Upstreams) != len(dedupe(l.Upstreams)) {
			errs = append(errs, fmt.Errorf("ambassador: listeners[%d] has duplicate upstreams", i))
		}
		if l.Balance != "" && l.Balance != LeastConn && l.Balance != RoundRobin {
			errs = append(errs, fmt.Errorf("ambassador: listeners[%d].balance %q is unknown", i, l.Balance))
		}
	}
	return errors.Join(errs...)
}

func dedupe(in []string) []string {
	out := slices.Clone(in)
	slices.Sort(out)
	return slices.Compact(out)
}
