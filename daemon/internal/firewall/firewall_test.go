package firewall

import (
	"context"
	"errors"
	"net/netip"
	"slices"
	"strings"
	"testing"
	"time"

	"github.com/vantezzen/launchroom/daemon/internal/docker"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

func chain(rs Ruleset, name string) []string {
	for _, c := range rs.Chains {
		if c.Name == name {
			out := make([]string, len(c.Rules))
			for i, r := range c.Rules {
				out[i] = r.String()
			}
			return out
		}
	}
	return nil
}

func index(rules []string, rule string) int { return slices.Index(rules, rule) }

func TestBuildRuleset(t *testing.T) {
	rs := Build(Spec{
		Bridges:     []string{"br-bbbbbbbbbbbb", "br-aaaaaaaaaaaa"},
		BuildBridge: "br-cccccccccccc", BuildGateway: netip.MustParseAddr("10.232.0.1"), RegistryPort: 5000,
		AllowPrivate: []netip.Prefix{netip.MustParsePrefix("192.168.1.0/24")},
	})
	fwd := chain(rs, ChainForward)
	for _, br := range []string{"br-aaaaaaaaaaaa", "br-bbbbbbbbbbbb", "br-cccccccccccc"} {
		if index(fwd, `iifname "`+br+`" oifname "`+br+`" accept`) < 0 || index(fwd, `iifname "`+br+`" jump egress`) < 0 {
			t.Fatalf("forward chain misses %s:\n%s", br, rs)
		}
	}
	in := chain(rs, ChainInput)
	if in[0] != `iifname "br-cccccccccccc" ip daddr 10.232.0.1/32 tcp dport 5000 accept` {
		t.Fatalf("registry forwarder rule: %q", in[0])
	}
	eg := chain(rs, ChainEgress)
	order := []string{
		"ct state established,related accept",
		"udp dport 53 accept",
		"ip daddr 169.254.0.0/16 drop",
		"ip6 daddr fd00:ec2::254/128 drop",
		"ip daddr 100.64.0.0/10 drop",
		"ip6 daddr fd7a:115c:a1e0::/48 drop",
		`oifname "br-aaaaaaaaaaaa" drop`,
		`oifname "br-*" drop`,
		"ip daddr 192.168.1.0/24 accept",
		"ip daddr 10.0.0.0/8 drop",
		"ip daddr 192.168.0.0/16 drop",
	}
	last := -1
	for _, r := range order {
		i := index(eg, r)
		if i <= last {
			t.Fatalf("egress rule %q missing or out of order (at %d after %d):\n%s", r, i, last, rs)
		}
		last = i
	}
	host := chain(rs, ChainHost)
	if host[len(host)-1] != "drop" || index(host, "tcp dport 443 accept") < 0 || index(host, "tcp dport 5000 accept") >= 0 || index(host, "tcp dport 2019 accept") >= 0 {
		t.Fatalf("host chain: %q", host)
	}
	// Deterministic regardless of input order.
	again := Build(Spec{
		Bridges:     []string{"br-aaaaaaaaaaaa", "br-bbbbbbbbbbbb", "br-aaaaaaaaaaaa"},
		BuildBridge: "br-cccccccccccc", BuildGateway: netip.MustParseAddr("10.232.0.1"), RegistryPort: 5000,
		AllowPrivate: []netip.Prefix{netip.MustParsePrefix("192.168.1.0/24")},
	})
	if again.String() != rs.String() {
		t.Fatal("ruleset not deterministic")
	}
	if !strings.Contains(rs.String(), "type filter hook forward priority -10; policy accept;") {
		t.Fatalf("base chain header:\n%s", rs)
	}
}

func TestBuildWithoutNetworks(t *testing.T) {
	rs := Build(Spec{})
	if len(chain(rs, ChainForward)) != 0 || len(chain(rs, ChainInput)) != 0 {
		t.Fatalf("rules without bridges:\n%s", rs)
	}
}

func nets() []docker.Network {
	return []docker.Network{
		{Name: "lr-e_a", Bridge: "br-aaaaaaaaaaaa"},
		{Name: "lr-system-build", Bridge: "br-cccccccccccc"},
		{Name: "lr-e_overlay"}, // not a bridge
	}
}

func TestManagerAppliesRepairsAndReports(t *testing.T) {
	fake := &FakeBackend{}
	list := nets()
	m := New(Options{Backend: fake, Networks: func(context.Context) ([]docker.Network, error) { return list, nil },
		BuildNetwork: "lr-system-build", BuildGateway: netip.MustParseAddr("10.232.0.1"), RegistryPort: 5000,
		Now: func() time.Time { return time.Unix(1700000000, 0) }})
	if m.Status() != nil {
		t.Fatal("status before the first sync")
	}
	ctx := t.Context()
	if err := m.Sync(ctx); err != nil || fake.Applies != 1 {
		t.Fatalf("first sync: %v applies=%d", err, fake.Applies)
	}
	if err := m.Sync(ctx); err != nil || fake.Applies != 1 {
		t.Fatalf("unchanged sync re-applied: %v applies=%d", err, fake.Applies)
	}
	st := m.Status()
	if st.Mode != state.FirewallEnforced || st.Networks != 2 || st.Repairs != 0 {
		t.Fatalf("status %+v", st)
	}
	// A new network is not drift.
	list = append(list, docker.Network{Name: "lr-e_b", Bridge: "br-bbbbbbbbbbbb"})
	if err := m.Sync(ctx); err != nil || fake.Applies != 2 || m.Status().Repairs != 0 || !strings.Contains(fake.Live, "br-bbbbbbbbbbbb") {
		t.Fatalf("new network: %v applies=%d %+v", err, fake.Applies, m.Status())
	}
	fake.Tamper()
	if err := m.Sync(ctx); err != nil || fake.Applies != 3 || m.Status().Repairs != 1 {
		t.Fatalf("drift not repaired: %v applies=%d %+v", err, fake.Applies, m.Status())
	}
	fake.Fail = true
	fake.Tamper()
	err := m.Sync(ctx)
	if !errors.Is(err, ErrNotApplied) || m.Status().Mode != state.FirewallError || m.Status().Error == "" {
		t.Fatalf("failure: %v %+v", err, m.Status())
	}
	h := state.HostHealth{CollectedAt: time.Unix(1700000000, 0), Firewall: m.Status()}
	rep := state.Report{TypeMeta: state.NewTypeMeta(state.KindReport), ServerID: "srv_01J9Z8X7W6V5T4S3R2Q1P0N9MK", ObservedAt: h.CollectedAt, Seq: 1,
		Host: &state.HostStatus{CPUs: 1, DaemonVersion: "1.0.0", Health: &h}}
	if err := rep.Validate(); err != nil {
		t.Fatalf("firewall status is not a valid report: %v", err)
	}
}

func TestManagerOff(t *testing.T) {
	fake := &FakeBackend{}
	m := New(Options{Mode: ModeOff, Backend: fake, Networks: func(context.Context) ([]docker.Network, error) { return nets(), nil }})
	if err := m.Sync(t.Context()); err != nil || fake.Applies != 0 || m.Status().Mode != state.FirewallDisabled {
		t.Fatalf("off: %v %d %+v", err, fake.Applies, m.Status())
	}
}

func TestManagerListError(t *testing.T) {
	m := New(Options{Backend: &FakeBackend{}, Networks: func(context.Context) ([]docker.Network, error) { return nil, errors.New("docker down") }})
	if err := m.Sync(t.Context()); !errors.Is(err, ErrNotApplied) {
		t.Fatalf("err = %v", err)
	}
}

func TestBuildRulesetMesh(t *testing.T) {
	without := Build(Spec{Bridges: []string{"br-aaaaaaaaaaaa"}})
	if chain(without, ChainMeshHost) != nil || strings.Contains(without.String(), "tailscale0") {
		t.Fatalf("mesh rules without a mesh interface:\n%s", without)
	}
	rs := Build(Spec{Bridges: []string{"br-aaaaaaaaaaaa"}, MeshInterface: "tailscale0"})
	if index(chain(rs, ChainInput), `iifname "tailscale0" jump mesh-host`) < 0 {
		t.Fatalf("input chain:\n%s", rs)
	}
	if index(chain(rs, ChainForward), `iifname "tailscale0" jump mesh-forward`) < 0 {
		t.Fatalf("forward chain:\n%s", rs)
	}
	host := chain(rs, ChainMeshHost)
	want := []string{"ct state established,related accept", "tcp dport 443 accept", "tcp dport 5000 accept", "drop"}
	if !slices.Equal(host, want) {
		t.Fatalf("mesh-host = %q, want %q", host, want)
	}
	if fwd := chain(rs, ChainMeshForward); fwd[len(fwd)-1] != "drop" || chain(without, ChainPrerouting) != nil {
		t.Fatalf("mesh-forward = %q", fwd)
	}
	// Containers still never reach the mesh range.
	eg := chain(rs, ChainEgress)
	if index(eg, "ip daddr 100.64.0.0/10 drop") < 0 || index(eg, "ip6 daddr fd7a:115c:a1e0::/48 drop") < 0 {
		t.Fatalf("egress: %q", eg)
	}
	// Regular chains come before the base chains that jump to them.
	pos := map[string]int{}
	for i, c := range rs.Chains {
		pos[c.Name] = i
	}
	if pos[ChainMeshHost] > pos[ChainInput] || pos[ChainMeshForward] > pos[ChainForward] {
		t.Fatalf("chain order: %v", pos)
	}
}

func TestBuildRulesetMeshAllowAndAmbassador(t *testing.T) {
	mesh := netip.MustParseAddr("100.64.0.7")
	rs := Build(Spec{
		Bridges: []string{"br-aaaaaaaaaaaa"}, MeshInterface: "tailscale0", MeshIP: mesh,
		MeshAllow: []MeshAllow{
			{Port: 31002, From: []netip.Addr{netip.MustParseAddr("100.64.0.9")}},
			{Port: 31001, From: []netip.Addr{netip.MustParseAddr("100.64.0.3"), netip.MustParseAddr("100.64.0.2"), netip.MustParseAddr("100.64.0.3")}},
		},
		CertStoragePort: 6379, CertStorageFrom: []netip.Addr{netip.MustParseAddr("100.64.0.4")},
		AmbassadorBridge:    "br-dddddddddddd",
		AmbassadorUpstreams: []netip.AddrPort{netip.MustParseAddrPort("100.64.0.8:31005"), netip.MustParseAddrPort("100.64.0.8:31005")},
	})
	pub := chain(rs, ChainMeshPublished)
	want := []string{
		"ct state established,related accept",
		"tcp dport 443 accept",
		"tcp dport 5000 accept",
		"ip saddr 100.64.0.4/32 tcp dport 6379 accept",
		"ip saddr 100.64.0.2/32 ip daddr 100.64.0.7/32 tcp dport 31001 accept",
		"ip saddr 100.64.0.3/32 ip daddr 100.64.0.7/32 tcp dport 31001 accept",
		"ip saddr 100.64.0.9/32 ip daddr 100.64.0.7/32 tcp dport 31002 accept",
		"drop",
	}
	if !slices.Equal(pub, want) {
		t.Fatalf("mesh-published = %q\nwant %q", pub, want)
	}
	if !slices.Equal(chain(rs, ChainMeshForward), []string{"ct state established,related accept", "ct status dnat accept", "drop"}) {
		t.Fatalf("mesh-forward = %q", chain(rs, ChainMeshForward))
	}
	// 2026-10 review M5: packets for the mesh IP that arrive on any other
	// interface (a LAN neighbour routing 100.64.0.7 via this host) are
	// dropped before Docker's DNAT; only the mesh (through the gate) and the
	// host's own loopback traffic reach it.
	if !slices.Equal(chain(rs, ChainPrerouting), []string{`iifname "tailscale0" jump mesh-published`, "ct state established,related accept", `iifname != "lo" iifname != "tailscale0" ip daddr 100.64.0.7/32 drop`}) || !strings.Contains(rs.String(), "type filter hook prerouting priority -150;") {
		t.Fatalf("prerouting:\n%s", rs)
	}
	host := chain(rs, ChainMeshHost)
	if !slices.Equal(host, []string{"ct state established,related accept", "tcp dport 443 accept", "tcp dport 5000 accept", "ip saddr 100.64.0.4/32 tcp dport 6379 accept", "drop"}) {
		t.Fatalf("mesh-host = %q", host)
	}
	amb := chain(rs, ChainAmbassador)
	if !slices.Equal(amb, []string{"ct state established,related accept", "ip daddr 100.64.0.8/32 tcp dport 31005 accept", "drop"}) {
		t.Fatalf("ambassador = %q", amb)
	}
	if index(chain(rs, ChainForward), `iifname "br-dddddddddddd" jump ambassador`) < 0 || index(chain(rs, ChainInput), `iifname "br-dddddddddddd" jump ambassador`) < 0 {
		t.Fatalf("ambassador bridge not filtered:\n%s", rs)
	}
	// The ambassador network is not an environment network: environment
	// containers can't reach it (oifname "br-*" drop) and it gets no egress chain.
	if index(chain(rs, ChainForward), `iifname "br-dddddddddddd" jump egress`) >= 0 {
		t.Fatalf("ambassador bridge in egress:\n%s", rs)
	}

	// Without a known mesh IP no published port is reachable.
	none := Build(Spec{MeshInterface: "tailscale0", MeshAllow: []MeshAllow{{Port: 31001, From: []netip.Addr{netip.MustParseAddr("100.64.0.3")}}}})
	if pub := chain(none, ChainMeshPublished); strings.Contains(strings.Join(pub, "\n"), "31001") {
		t.Fatalf("mesh-published without mesh IP = %q", pub)
	}
	if !slices.Equal(chain(none, ChainPrerouting), []string{`iifname "tailscale0" jump mesh-published`}) {
		t.Fatalf("prerouting without mesh IP = %q", chain(none, ChainPrerouting))
	}
}

func TestManagerMeshRules(t *testing.T) {
	fake := &FakeBackend{}
	list := append(nets(), docker.Network{Name: "lr-system-ambassador", Bridge: "br-dddddddddddd"})
	rules := MeshRules{MeshIP: netip.MustParseAddr("100.64.0.7"), MeshAllow: []MeshAllow{{Port: 31001, From: []netip.Addr{netip.MustParseAddr("100.64.0.3")}}},
		AmbassadorUpstreams: []netip.AddrPort{netip.MustParseAddrPort("100.64.0.8:31005")}}
	m := New(Options{Backend: fake, Networks: func(context.Context) ([]docker.Network, error) { return list, nil },
		BuildNetwork: "lr-system-build", AmbassadorNetwork: "lr-system-ambassador", MeshInterface: "tailscale0",
		Mesh: func() MeshRules { return rules }})
	if err := m.Sync(t.Context()); err != nil {
		t.Fatal(err)
	}
	for _, s := range []string{"tcp dport 31001 accept", `iifname "br-dddddddddddd" jump ambassador`, "ip daddr 100.64.0.8/32 tcp dport 31005 accept"} {
		if !strings.Contains(fake.Live, s) {
			t.Fatalf("missing %q:\n%s", s, fake.Live)
		}
	}
	if m.Status().Networks != 2 {
		t.Fatalf("status %+v (the ambassador network is not an environment network)", m.Status())
	}
	// A new meshAllow peer re-applies.
	rules.MeshAllow[0].From = append(rules.MeshAllow[0].From, netip.MustParseAddr("100.64.0.4"))
	if err := m.Sync(t.Context()); err != nil || fake.Applies != 2 || !strings.Contains(fake.Live, "100.64.0.4/32") {
		t.Fatalf("peer change: %v applies=%d", err, fake.Applies)
	}
}

// The link probe port (docs/05 §2.1) is open on the mesh only for the
// listed peers, in mesh-host and in the gate before Docker's DNAT.
func TestBuildRulesetLinkProbe(t *testing.T) {
	rs := Build(Spec{
		MeshInterface: "tailscale0", MeshIP: netip.MustParseAddr("100.64.0.7"),
		LinkProbePort: 7400, LinkProbeFrom: []netip.Addr{netip.MustParseAddr("100.64.0.9"), netip.MustParseAddr("100.64.0.3")},
	})
	want := []string{
		"ct state established,related accept",
		"tcp dport 443 accept",
		"tcp dport 5000 accept",
		"ip saddr 100.64.0.3/32 tcp dport 7400 accept",
		"ip saddr 100.64.0.9/32 tcp dport 7400 accept",
		"drop",
	}
	if host := chain(rs, ChainMeshHost); !slices.Equal(host, want) {
		t.Fatalf("mesh-host = %q\nwant %q", host, want)
	}
	if pub := chain(rs, ChainMeshPublished); index(pub, "ip saddr 100.64.0.9/32 tcp dport 7400 accept") < 0 || index(pub, "tcp dport 7400 accept") >= 0 {
		t.Fatalf("mesh-published = %q", pub)
	}
	// Without peers the port stays closed.
	if host := chain(Build(Spec{MeshInterface: "tailscale0", LinkProbePort: 7400}), ChainMeshHost); slices.ContainsFunc(host, func(r string) bool { return strings.Contains(r, "7400") }) {
		t.Fatalf("mesh-host without peers = %q", host)
	}
}
