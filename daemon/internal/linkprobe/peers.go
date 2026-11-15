package linkprobe

import (
	"net/netip"
	"slices"

	"github.com/vantezzen/launchroom/daemon/internal/identity"
	"github.com/vantezzen/launchroom/daemon/internal/policy"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// Peers are system.linkProbe's peers after the local policy floor
// (docs/05 §2.1): on the mesh (100.64.0.0/10), with a valid key, never
// self. Nil when the NodeState turns probing or the mesh off.
func Peers(ns *state.NodeState, self state.ServerID) []Peer {
	lp := ns.System.LinkProbe
	if !lp.Enabled || !ns.System.Tailscale.Enabled {
		return nil
	}
	var out []Peer
	for _, p := range lp.Peers {
		ip, err := netip.ParseAddr(string(p.IPv4))
		if err != nil || !policy.MeshPeer(ip) || p.ServerID == self {
			continue
		}
		key, err := identity.ParsePublicKey(string(p.SigningKey))
		if err != nil {
			continue
		}
		out = append(out, Peer{ID: string(p.ServerID), IP: ip.Unmap(), Key: key})
	}
	slices.SortFunc(out, func(a, b Peer) int { return a.IP.Compare(b.IP) })
	return out
}

// Addrs are the peers' addresses (the ones nftables lets reach the probe port).
func Addrs(peers []Peer) []netip.Addr {
	out := make([]netip.Addr, 0, len(peers))
	for _, p := range peers {
		out = append(out, p.IP)
	}
	return out
}
