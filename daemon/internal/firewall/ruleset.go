// Package firewall installs launchroom's network isolation (docs/05 §1,
// docs/09 R23): containers on launchroom networks (environment networks and
// the build network) may reach their own network, DNS and the internet, and
// nothing else: no cloud metadata, no mesh, no other environment, no LAN or
// other private ranges (unless allow-listed in the daemon's local config) and
// no host ports except the public ingress (80/443).
//
// Design: the daemon owns one nftables table, `inet launchroom`, with base
// chains on the forward and input hooks. It does not use Docker's
// DOCKER-USER chain: an nftables drop verdict is final for the packet in
// every table and on every hook, so a separate table works the same whether
// Docker programs iptables-legacy, iptables-nft or its native nftables
// backend (Docker 29 `firewall-backend`), and Docker never flushes it. Rules
// match the bridge interface a packet arrives on, not its source address, so
// a container that spoofs its source IP is still filtered. The whole table is
// replaced in one netlink transaction (add, delete, add), so there is never a
// window without rules.
package firewall

import (
	"fmt"
	"net/netip"
	"slices"
	"strings"
)

// TableName is the nftables table the daemon owns (family inet).
const TableName = "launchroom"

// Chain names.
const (
	ChainForward = "forward"
	ChainInput   = "input"
	ChainEgress  = "egress"
	ChainHost    = "host"
	// ChainMeshHost filters packets from mesh peers to the host itself;
	// ChainMeshForward packets from mesh peers that would be forwarded (to
	// containers): only connections Docker DNATed from a mesh-published
	// port, after ChainMeshPublished let them in (docs/05 §3).
	ChainMeshHost    = "mesh-host"
	ChainMeshForward = "mesh-forward"
	// ChainMeshPublished (prerouting, before Docker's DNAT) is the gate for
	// new connections from mesh peers: the host ports of mesh-host and the
	// firewall.meshAllow (peer, mesh IP, port) pairs; everything else is
	// dropped. It runs before DNAT so it sees the published port itself.
	ChainMeshPublished = "mesh-published"
	ChainPrerouting    = "prerouting"
	// ChainAmbassador filters everything the ambassador container sends
	// through its own network (lr-system-ambassador): only its listed
	// upstreams (mesh-published ports on other servers) are reachable.
	ChainAmbassador = "ambassador"
	// ChainAccess gates the ports of temporary remote access (docs/05 §8):
	// per access, its allowed sources, then drop. Present only while an
	// access runs.
	ChainAccess = "access"
)

// Priority of the base chains: just before the default filter priority so
// our drops apply before Docker's own accepts are even evaluated.
const Priority = -10

// PreroutingPriority runs the mesh gate before Docker's DNAT (dstnat, -100)
// and after conntrack (-200).
const PreroutingPriority = -150

// Blocked destination ranges for traffic from launchroom networks.
var (
	// Metadata is cloud instance metadata (AWS/GCP/Azure/…, link-local).
	Metadata = []netip.Prefix{
		netip.MustParsePrefix("169.254.0.0/16"),
		netip.MustParsePrefix("fd00:ec2::254/128"),
		netip.MustParsePrefix("fe80::/10"),
	}
	// Mesh is the Tailscale/Headscale range (docs/05 §2).
	Mesh = []netip.Prefix{
		netip.MustParsePrefix("100.64.0.0/10"),
		netip.MustParsePrefix("fd7a:115c:a1e0::/48"),
	}
	// Private are LAN, loopback, reserved and multicast ranges; the local
	// allow-list (LR_FIREWALL_ALLOW_PRIVATE) can open parts of them.
	Private = []netip.Prefix{
		netip.MustParsePrefix("0.0.0.0/8"),
		netip.MustParsePrefix("10.0.0.0/8"),
		netip.MustParsePrefix("127.0.0.0/8"),
		netip.MustParsePrefix("172.16.0.0/12"),
		netip.MustParsePrefix("192.0.0.0/24"),
		netip.MustParsePrefix("192.168.0.0/16"),
		netip.MustParsePrefix("198.18.0.0/15"),
		netip.MustParsePrefix("224.0.0.0/4"),
		netip.MustParsePrefix("240.0.0.0/4"),
		netip.MustParsePrefix("::1/128"),
		netip.MustParsePrefix("fc00::/7"),
		netip.MustParsePrefix("ff00::/8"),
	}
)

// HostIngressPorts are the host ports containers may reach on the host's own
// addresses: Caddy's public ingress, so an app can call another app's public
// URL on the same server (hairpin).
var HostIngressPorts = []PortRule{{Proto: "tcp", Port: 80}, {Proto: "tcp", Port: 443}, {Proto: "udp", Port: 443}}

// MeshHostPorts are the host ports mesh peers may reach (docs/05 §2): HTTPS
// (the daemon API on the control-plane host, the edge ingress for
// mesh-routed requests) and the registry the control-plane host serves on
// its mesh IP. The ACL of the coordination server is the first filter; this
// is the host's own, which also holds for "bring your own Tailscale" tailnets
// whose ACL allows everything.
var MeshHostPorts = []PortRule{{Proto: "tcp", Port: 443}, {Proto: "tcp", Port: 5000}}

// PortRule is a protocol and destination port.
type PortRule struct {
	Proto string // "tcp" or "udp"
	Port  uint16
}

// Spec is what the ruleset is rendered from.
type Spec struct {
	// Bridges are the host interfaces of launchroom's networks (environment
	// networks and the build network).
	Bridges []string
	// BuildBridge is the build network's interface ("" = none); BuildGateway
	// and RegistryPort are where buildkitd reaches the daemon's registry
	// forwarder on the host.
	BuildBridge  string
	BuildGateway netip.Addr
	RegistryPort uint16
	// AllowPrivate are private destinations containers may reach (local
	// config only). Metadata, mesh, other launchroom networks and other
	// Docker bridges stay blocked regardless.
	AllowPrivate []netip.Prefix
	// MeshInterface is the mesh client's interface (tailscale0); "" = no
	// mesh rules. Traffic of the host itself over the mesh (launchroomd, the
	// registry forwarder, Caddy's upstreams) is outbound and not filtered.
	MeshInterface string
	// MeshIP is this server's mesh IPv4 (invalid = not known yet: no
	// mesh-published port is reachable).
	MeshIP netip.Addr
	// MeshAllow are the mesh-published ports and the peers that may reach
	// them (firewall.meshAllow after the local policy floor).
	MeshAllow []MeshAllow
	// CertStoragePort and CertStorageFrom: the control-plane host serves its
	// Valkey on the mesh IP for these peers only (system.certStorage).
	CertStoragePort uint16
	CertStorageFrom []netip.Addr
	// LinkProbePort and LinkProbeFrom: launchroomd's link probe listener on
	// the mesh IP, for these peers only (system.linkProbe).
	LinkProbePort uint16
	LinkProbeFrom []netip.Addr
	// AmbassadorBridge is the interface of the ambassador's own network
	// (lr-system-ambassador, "" = none); AmbassadorUpstreams the only
	// destinations it may reach (docs/05 §3).
	AmbassadorBridge    string
	AmbassadorUpstreams []netip.AddrPort
	// Access are the running temporary accesses' ports and allowed sources.
	Access []AccessRule
}

// AccessRule is one temporary access port and the sources that may reach it.
type AccessRule struct {
	Port uint16
	From []netip.Prefix
}

// MeshAllow is one mesh-published port and its allowed peers.
type MeshAllow struct {
	Port uint16
	From []netip.Addr
}

// Rule is one nftables rule: all set matches must hold, then Verdict.
type Rule struct {
	IIF string // iifname equals
	// IIFNot matches when iifname is none of these.
	IIFNot []string
	OIF    string // oifname equals
	// OIFPrefix matches oifname by prefix (nft `oifname "br-*"`).
	OIFPrefix string
	// Established matches ct state established,related.
	Established bool
	// DNAT matches connections whose destination was rewritten (ct status dnat).
	DNAT  bool
	Src   netip.Prefix
	Dst   netip.Prefix
	Proto string // "tcp" or "udp"; required with DPort
	DPort uint16
	// Verdict is "accept", "drop" or "jump <chain>".
	Verdict string
}

// String renders the rule in nft syntax. It is also stored as the rule's
// comment, which drift detection compares.
func (r Rule) String() string {
	var b []string
	if r.IIF != "" {
		b = append(b, fmt.Sprintf("iifname %q", r.IIF))
	}
	for _, n := range r.IIFNot {
		b = append(b, fmt.Sprintf("iifname != %q", n))
	}
	if r.OIF != "" {
		b = append(b, fmt.Sprintf("oifname %q", r.OIF))
	}
	if r.OIFPrefix != "" {
		b = append(b, fmt.Sprintf("oifname %q", r.OIFPrefix+"*"))
	}
	if r.Established {
		b = append(b, "ct state established,related")
	}
	if r.DNAT {
		b = append(b, "ct status dnat")
	}
	if r.Src.IsValid() {
		b = append(b, family(r.Src.Addr())+" saddr "+r.Src.String())
	}
	if r.Dst.IsValid() {
		b = append(b, family(r.Dst.Addr())+" daddr "+r.Dst.String())
	}
	if r.Proto != "" {
		if r.DPort != 0 {
			b = append(b, fmt.Sprintf("%s dport %d", r.Proto, r.DPort))
		} else {
			b = append(b, "meta l4proto "+r.Proto)
		}
	}
	b = append(b, r.Verdict)
	return strings.Join(b, " ")
}

func family(a netip.Addr) string {
	if a.Is6() {
		return "ip6"
	}
	return "ip"
}

func single(a netip.Addr) netip.Prefix { return netip.PrefixFrom(a, a.BitLen()) }

// Chain is a base chain (Hook set) or a regular chain.
type Chain struct {
	Name string
	Hook string // "forward", "input", "prerouting" or "" (regular chain)
	// Priority of a base chain (0 = Priority).
	Priority int32
	Rules    []Rule
}

// Prio is the chain's effective priority.
func (c Chain) Prio() int32 {
	if c.Priority == 0 {
		return Priority
	}
	return c.Priority
}

// Ruleset is the complete `inet launchroom` table.
type Ruleset struct {
	Chains []Chain
}

// String renders the table in `nft -f` syntax (logs, docs, tests).
func (rs Ruleset) String() string {
	var b strings.Builder
	fmt.Fprintf(&b, "table inet %s {\n", TableName)
	for _, c := range rs.Chains {
		fmt.Fprintf(&b, "  chain %s {\n", c.Name)
		if c.Hook != "" {
			fmt.Fprintf(&b, "    type filter hook %s priority %d; policy accept;\n", c.Hook, c.Prio())
		}
		for _, r := range c.Rules {
			b.WriteString("    " + r.String() + "\n")
		}
		b.WriteString("  }\n")
	}
	b.WriteString("}\n")
	return b.String()
}

// Build renders the ruleset for spec. The output is deterministic.
func Build(spec Spec) Ruleset {
	bridges := slices.Clone(spec.Bridges)
	if spec.BuildBridge != "" {
		bridges = append(bridges, spec.BuildBridge)
	}
	slices.Sort(bridges)
	bridges = slices.Compact(bridges)

	var fwd, in, egress, host []Rule
	for _, br := range bridges {
		// Containers on the same network reach each other (Docker's
		// br_netfilter passes bridged traffic through the forward hook).
		fwd = append(fwd, Rule{IIF: br, OIF: br, Verdict: "accept"}, Rule{IIF: br, Verdict: "jump " + ChainEgress})
	}
	if spec.BuildBridge != "" && spec.BuildGateway.IsValid() && spec.RegistryPort != 0 {
		in = append(in, Rule{IIF: spec.BuildBridge, Dst: netip.PrefixFrom(spec.BuildGateway, spec.BuildGateway.BitLen()), Proto: "tcp", DPort: spec.RegistryPort, Verdict: "accept"})
	}
	for _, br := range bridges {
		in = append(in, Rule{IIF: br, Verdict: "jump " + ChainHost})
	}
	var meshHost, meshFwd, meshPub, pre []Rule
	if spec.MeshInterface != "" {
		fwd = append(fwd, Rule{IIF: spec.MeshInterface, Verdict: "jump " + ChainMeshForward})
		in = append(in, Rule{IIF: spec.MeshInterface, Verdict: "jump " + ChainMeshHost})
		pre = append(pre, Rule{IIF: spec.MeshInterface, Verdict: "jump " + ChainMeshPublished})
		if spec.MeshIP.IsValid() {
			// 2026-10 review M5: Linux accepts packets for any local address
			// on any interface (weak host model), so a LAN neighbour that
			// routes the mesh IP via this host's LAN address would skip the
			// gate above and reach the mesh-published ports (and the
			// registry/Valkey forwarders) without WireGuard. Everything
			// addressed to the mesh IP must arrive on the mesh interface,
			// or be the host's own traffic (loopback).
			// Replies stay allowed: the host's own connection to a
			// mesh-published port is DNATed to the container, whose answer
			// arrives on the bridge addressed to the mesh IP. Conntrack runs
			// before this chain, so a LAN neighbour's new connection is never
			// established.
			pre = append(pre,
				Rule{Established: true, Verdict: "accept"},
				Rule{IIFNot: []string{"lo", spec.MeshInterface}, Dst: single(spec.MeshIP), Verdict: "drop"})
		}
		var hostPorts []Rule
		for _, p := range MeshHostPorts {
			hostPorts = append(hostPorts, Rule{Proto: p.Proto, DPort: p.Port, Verdict: "accept"})
		}
		if spec.CertStoragePort != 0 {
			for _, ip := range sortedAddrs(spec.CertStorageFrom) {
				hostPorts = append(hostPorts, Rule{Src: single(ip), Proto: "tcp", DPort: spec.CertStoragePort, Verdict: "accept"})
			}
		}
		if spec.LinkProbePort != 0 {
			for _, ip := range sortedAddrs(spec.LinkProbeFrom) {
				hostPorts = append(hostPorts, Rule{Src: single(ip), Proto: "tcp", DPort: spec.LinkProbePort, Verdict: "accept"})
			}
		}
		meshHost = append(meshHost, Rule{Established: true, Verdict: "accept"})
		meshHost = append(meshHost, hostPorts...)
		meshHost = append(meshHost, Rule{Verdict: "drop"})
		// The gate before Docker's DNAT: mesh-published ports only from
		// their peers (WireGuard binds the source IP to the peer's key, so
		// the source is authenticated, docs/05 §3), plus the host ports.
		// An accept here only ends this chain; input and forward follow.
		meshPub = append(meshPub, Rule{Established: true, Verdict: "accept"})
		meshPub = append(meshPub, hostPorts...)
		if spec.MeshIP.IsValid() {
			allow := slices.Clone(spec.MeshAllow)
			slices.SortFunc(allow, func(a, b MeshAllow) int { return int(a.Port) - int(b.Port) })
			for _, a := range allow {
				if a.Port == 0 {
					continue
				}
				for _, ip := range sortedAddrs(a.From) {
					if ip.Is4() != spec.MeshIP.Is4() {
						continue
					}
					meshPub = append(meshPub, Rule{Src: single(ip), Dst: single(spec.MeshIP), Proto: "tcp", DPort: a.Port, Verdict: "accept"})
				}
			}
		}
		meshPub = append(meshPub, Rule{Verdict: "drop"})
		// Forwarded from the mesh: only connections Docker DNATed from a
		// published port that passed the gate.
		meshFwd = append(meshFwd, Rule{Established: true, Verdict: "accept"}, Rule{DNAT: true, Verdict: "accept"}, Rule{Verdict: "drop"})
	}
	var amb []Rule
	if spec.AmbassadorBridge != "" {
		// The ambassador's own network carries only its connections to the
		// listed upstreams; it reaches nothing else (no DNS, no internet,
		// no host port). Its listeners live on the environment networks.
		fwd = append(fwd, Rule{IIF: spec.AmbassadorBridge, Verdict: "jump " + ChainAmbassador})
		in = append(in, Rule{IIF: spec.AmbassadorBridge, Verdict: "jump " + ChainAmbassador})
		amb = append(amb, Rule{Established: true, Verdict: "accept"})
		ups := slices.Clone(spec.AmbassadorUpstreams)
		slices.SortFunc(ups, func(a, b netip.AddrPort) int { return a.Compare(b) })
		for _, u := range slices.Compact(ups) {
			if !u.IsValid() || u.Port() == 0 {
				continue
			}
			amb = append(amb, Rule{Dst: single(u.Addr()), Proto: "tcp", DPort: u.Port(), Verdict: "accept"})
		}
		amb = append(amb, Rule{Verdict: "drop"})
	}

	dns := []Rule{{Proto: "udp", DPort: 53, Verdict: "accept"}, {Proto: "tcp", DPort: 53, Verdict: "accept"}}
	egress = append(egress, Rule{Established: true, Verdict: "accept"})
	// DNS to any resolver: Docker's embedded resolver forwards queries from
	// the container's namespace to the host's upstreams, which are often on
	// the LAN or the metadata address (GCP 169.254.169.254:53).
	egress = append(egress, dns...)
	for _, p := range slices.Concat(Metadata, Mesh) {
		egress = append(egress, Rule{Dst: p, Verdict: "drop"})
	}
	for _, br := range bridges {
		egress = append(egress, Rule{OIF: br, Verdict: "drop"}) // other launchroom networks
	}
	// Any other Docker bridge (the control plane's lr-system network, user
	// networks, the default bridge).
	egress = append(egress, Rule{OIFPrefix: "br-", Verdict: "drop"}, Rule{OIF: "docker0", Verdict: "drop"})
	allow := slices.Clone(spec.AllowPrivate)
	slices.SortFunc(allow, comparePrefix)
	for _, p := range slices.Compact(allow) {
		egress = append(egress, Rule{Dst: p, Verdict: "accept"})
	}
	for _, p := range Private {
		egress = append(egress, Rule{Dst: p, Verdict: "drop"})
	}

	host = append(host, Rule{Established: true, Verdict: "accept"})
	// Metadata or mesh addresses that happen to be local (a metadata proxy
	// on the host, the host's own tailscale0 address) are never reachable,
	// not even on the ingress ports.
	for _, p := range slices.Concat(Metadata, Mesh) {
		host = append(host, Rule{Dst: p, Verdict: "drop"})
	}
	host = append(host, dns...)
	for _, p := range HostIngressPorts {
		host = append(host, Rule{Proto: p.Proto, DPort: p.Port, Verdict: "accept"})
	}
	host = append(host, Rule{Verdict: "drop"})

	var access []Rule
	rules := slices.Clone(spec.Access)
	slices.SortFunc(rules, func(a, b AccessRule) int { return int(a.Port) - int(b.Port) })
	for _, a := range rules {
		if a.Port == 0 {
			continue
		}
		from := slices.Clone(a.From)
		slices.SortFunc(from, comparePrefix)
		for _, p := range slices.Compact(from) {
			if p.IsValid() {
				access = append(access, Rule{Src: p.Masked(), Proto: "tcp", DPort: a.Port, Verdict: "accept"})
			}
		}
		access = append(access, Rule{Proto: "tcp", DPort: a.Port, Verdict: "drop"})
	}
	if len(access) > 0 {
		// Last: launchroom bridges and the mesh are decided by their own
		// chains above; this sees the host's other interfaces.
		in = append(in, Rule{Verdict: "jump " + ChainAccess})
	}

	chains := []Chain{
		{Name: ChainEgress, Rules: egress},
		{Name: ChainHost, Rules: host},
	}
	if len(access) > 0 {
		chains = append(chains, Chain{Name: ChainAccess, Rules: access})
	}
	if spec.MeshInterface != "" {
		chains = append(chains, Chain{Name: ChainMeshHost, Rules: meshHost}, Chain{Name: ChainMeshForward, Rules: meshFwd}, Chain{Name: ChainMeshPublished, Rules: meshPub})
	}
	if spec.AmbassadorBridge != "" {
		chains = append(chains, Chain{Name: ChainAmbassador, Rules: amb})
	}
	chains = append(chains,
		Chain{Name: ChainForward, Hook: "forward", Rules: fwd},
		Chain{Name: ChainInput, Hook: "input", Rules: in},
	)
	if spec.MeshInterface != "" {
		chains = append(chains, Chain{Name: ChainPrerouting, Hook: "prerouting", Priority: PreroutingPriority, Rules: pre})
	}
	return Ruleset{Chains: chains}
}

func sortedAddrs(in []netip.Addr) []netip.Addr {
	out := slices.DeleteFunc(slices.Clone(in), func(a netip.Addr) bool { return !a.IsValid() })
	slices.SortFunc(out, netip.Addr.Compare)
	return slices.Compact(out)
}

func comparePrefix(a, b netip.Prefix) int {
	if c := a.Addr().Compare(b.Addr()); c != 0 {
		return c
	}
	return a.Bits() - b.Bits()
}
