package state

import (
	stdjson "encoding/json"
	"net/netip"
	"testing"
)

func TestPublicIP(t *testing.T) {
	for _, s := range []string{"172.18.0.1", "172.17.0.2", "10.0.0.1", "100.64.0.1", "127.0.0.1", "169.254.1.1", "203.0.113.7", "fd00::1", "fe80::1", "::ffff:8.8.8.8", "2001:db8::1"} {
		if PublicIP(netip.MustParseAddr(s)) {
			t.Errorf("%s public", s)
		}
	}
	for _, s := range []string{"93.184.216.34", "2606:2800:220:1:248:1893:25c8:1946"} {
		if !PublicIP(netip.MustParseAddr(s)) {
			t.Errorf("%s not public", s)
		}
	}
}

func TestReportPublicAddressesValidation(t *testing.T) {
	base := readFixture(t, "report.public-addresses.json")
	pa := func(d map[string]any) map[string]any {
		return d["host"].(map[string]any)["publicAddresses"].(map[string]any)
	}
	if _, err := Decode[Report]([]byte(base)); err != nil {
		t.Fatalf("fixture refused: %v", err)
	}
	cases := map[string]func(d map[string]any){
		"no address":         func(d map[string]any) { delete(pa(d), "ipv4"); delete(pa(d), "ipv6") },
		"docker bridge":      func(d map[string]any) { pa(d)["ipv4"] = "172.18.0.1" },
		"cgnat":              func(d map[string]any) { pa(d)["ipv4"] = "100.64.1.2" },
		"v6 in ipv4":         func(d map[string]any) { pa(d)["ipv4"] = "2606:2800:220:1:248:1893:25c8:1946" },
		"v4 in ipv6":         func(d map[string]any) { pa(d)["ipv6"] = "93.184.216.34" },
		"4in6":               func(d map[string]any) { pa(d)["ipv6"] = "::ffff:93.184.216.34" },
		"ula":                func(d map[string]any) { pa(d)["ipv6"] = "fd00::1" },
		"non-canonical":      func(d map[string]any) { pa(d)["ipv6"] = "2606:2800:220:1:248:1893:25C8:1946" },
		"missing detectedAt": func(d map[string]any) { delete(pa(d), "detectedAt") },
	}
	for name, mutate := range cases {
		t.Run(name, func(t *testing.T) {
			var d map[string]any
			if err := stdjson.Unmarshal([]byte(base), &d); err != nil {
				t.Fatal(err)
			}
			mutate(d)
			raw, _ := stdjson.Marshal(d)
			if _, err := Decode[Report](raw); err == nil {
				t.Fatal("accepted")
			}
		})
	}
}
