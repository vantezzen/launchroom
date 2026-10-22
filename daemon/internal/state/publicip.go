package state

import "net/netip"

// Non-public ranges (IANA special-purpose registries). The same list as the
// dashboard's App\Support\Network\IpClassifier, so both sides agree on what
// a public address is. Ranges embedding IPv4 (mapped, NAT64, 6to4, Teredo)
// are refused outright.
var nonPublicPrefixes = func() []netip.Prefix {
	cidrs := []string{
		"0.0.0.0/8", "10.0.0.0/8", "100.64.0.0/10", "127.0.0.0/8", "169.254.0.0/16",
		"172.16.0.0/12", "192.0.0.0/24", "192.0.2.0/24", "192.88.99.0/24", "192.168.0.0/16",
		"198.18.0.0/15", "198.51.100.0/24", "203.0.113.0/24", "224.0.0.0/4", "240.0.0.0/4",
		"::/128", "::1/128", "::/96", "::ffff:0:0/96", "64:ff9b::/96", "64:ff9b:1::/48",
		"100::/64", "2001::/23", "2001:db8::/32", "2002::/16", "3fff::/20", "5f00::/16",
		"fc00::/7", "fe80::/10", "fec0::/10", "ff00::/8",
	}
	out := make([]netip.Prefix, 0, len(cidrs))
	for _, c := range cidrs {
		out = append(out, netip.MustParsePrefix(c))
	}
	return out
}()

// PublicIP reports whether a is a globally routable unicast address: not
// private, CGNAT, Docker (172.16.0.0/12 and friends), loopback, link-local,
// ULA, documentation, multicast or another special range.
func PublicIP(a netip.Addr) bool {
	if !a.IsValid() || a.Zone() != "" {
		return false
	}
	for _, p := range nonPublicPrefixes {
		if p.Contains(a) {
			return false
		}
	}
	return true
}
