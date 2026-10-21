package policy

import (
	"fmt"
	"net/netip"
	"net/url"
	"slices"
	"strings"

	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// CheckMeshLoginServer allows a mesh login server (system.tailscale.loginServer)
// only over https; plain http only for hosts listed in the daemon's local
// LR_MESH_INSECURE_LOGIN_HOSTS (dev), since the mesh client would otherwise
// send its node key exchange to an unauthenticated server (docs/05 §2).
func CheckMeshLoginServer(u state.MeshLoginURL, insecureHosts []string) error {
	if err := u.Validate(); err != nil {
		return violation("mesh", "%v", err)
	}
	parsed, err := url.Parse(string(u))
	if err != nil {
		return violation("mesh", "login server %q is invalid", u)
	}
	if parsed.Scheme == "https" {
		return nil
	}
	if slices.Contains(insecureHosts, strings.ToLower(parsed.Hostname())) {
		return nil
	}
	return violation("mesh", "login server %s uses plain http; only https is allowed (or list the host in LR_MESH_INSECURE_LOGIN_HOSTS for local development)", u)
}

// SystemAmbassador is the ambassador system container (docs/05 §3). Its
// image is not chosen by the dashboard: launchroomd builds it locally from
// its own launchroom-ambassador binary (internal/ambassadord), so there is
// no digest to pin.
const SystemAmbassador = "ambassador"

// MeshPeer reports whether ip may appear in a mesh allow-list: a mesh
// address (WireGuard binds it to a peer key, so it is authenticated).
func MeshPeer(ip netip.Addr) bool {
	return ip.IsValid() && MeshRange.Contains(ip.Unmap())
}

// MeshUpstream allows an ambassador upstream only at a mesh-published port
// on the mesh: a compromised control plane must not make the ambassador (on
// every environment network it serves) a relay to the LAN, the internet or
// other host ports.
func MeshUpstream(addr state.AddrPort) (netip.AddrPort, error) {
	ap, err := netip.ParseAddrPort(string(addr))
	if err != nil || ap.Port() == 0 {
		return netip.AddrPort{}, violation("ambassadors", "upstream %q is not an ip:port", addr)
	}
	ap = netip.AddrPortFrom(ap.Addr().Unmap(), ap.Port())
	if !MeshPeer(ap.Addr()) || state.MeshPort(ap.Port()).Validate() != nil {
		return netip.AddrPort{}, violation("ambassadors", "upstream %s is not a mesh-published port (%s, %d-%d)", ap, MeshRange, state.MeshPortMin, state.MeshPortMax)
	}
	return ap, nil
}

// Ambassador is one ambassador listener that passed the policy floor.
type Ambassador struct {
	Network   state.NetworkSpec
	Alias     state.Slug
	Port      state.Port
	Upstreams []netip.AddrPort
}

// FilterAmbassadors keeps the ambassador listeners on declared environment
// networks with their mesh-published upstreams; anything else is refused
// (returned as messages for the log). A listener without an acceptable
// upstream is dropped. Output order: network name, alias, port.
func FilterAmbassadors(ns *state.NodeState) ([]Ambassador, []string) {
	nets := map[state.NetworkID]state.NetworkSpec{}
	for _, n := range ns.Networks {
		if CheckNetworkName(n.Name) == nil {
			nets[n.ID] = n
		}
	}
	var out []Ambassador
	var refused []string
	for _, a := range ns.Ambassadors {
		n, ok := nets[a.Network]
		if !ok {
			refused = append(refused, fmt.Sprintf("%s/%s:%d: network is not a declared environment network", a.Network, a.Alias, a.Port))
			continue
		}
		var ups []netip.AddrPort
		for _, u := range a.Upstreams {
			ap, err := MeshUpstream(u)
			if err != nil {
				refused = append(refused, err.Error())
				continue
			}
			ups = append(ups, ap)
		}
		slices.SortFunc(ups, netip.AddrPort.Compare)
		ups = slices.Compact(ups)
		if len(ups) == 0 {
			refused = append(refused, fmt.Sprintf("%s/%s:%d: no upstream left", a.Network, a.Alias, a.Port))
			continue
		}
		out = append(out, Ambassador{Network: n, Alias: a.Alias, Port: a.Port, Upstreams: ups})
	}
	slices.SortFunc(out, func(x, y Ambassador) int {
		if x.Network.Name != y.Network.Name {
			if x.Network.Name < y.Network.Name {
				return -1
			}
			return 1
		}
		if x.Alias != y.Alias {
			if x.Alias < y.Alias {
				return -1
			}
			return 1
		}
		return int(x.Port) - int(y.Port)
	})
	return out, refused
}

// MeshAllowRule is one firewall.meshAllow rule after the policy floor.
type MeshAllowRule struct {
	Port uint16
	From []netip.Addr
}

// FilterMeshAllow keeps only mesh peers in firewall.meshAllow (an allow-list
// can't open a mesh-published port to the LAN or the internet: they never
// arrive on the mesh interface anyway, and the rule set stays minimal).
func FilterMeshAllow(rules []state.MeshAllowRule) ([]MeshAllowRule, []string) {
	var out []MeshAllowRule
	var refused []string
	for _, r := range rules {
		if r.Port.Validate() != nil {
			refused = append(refused, fmt.Sprintf("meshAllow port %d is outside the mesh-published range", r.Port))
			continue
		}
		from, bad := meshPeers(r.From)
		refused = append(refused, bad...)
		if len(from) > 0 {
			out = append(out, MeshAllowRule{Port: uint16(r.Port), From: from}) //nolint:gosec // validated range
		}
	}
	return out, refused
}

// FilterCertStoragePeers keeps the mesh peers of system.certStorage.allowFrom.
func FilterCertStoragePeers(ips []state.IPAddr) ([]netip.Addr, []string) {
	return meshPeers(ips)
}

func meshPeers(ips []state.IPAddr) ([]netip.Addr, []string) {
	var out []netip.Addr
	var refused []string
	for _, s := range ips {
		ip, err := netip.ParseAddr(string(s))
		if err != nil || !MeshPeer(ip) {
			refused = append(refused, fmt.Sprintf("peer %s is not a mesh address", s))
			continue
		}
		out = append(out, ip.Unmap())
	}
	slices.SortFunc(out, netip.Addr.Compare)
	return slices.Compact(out), refused
}
