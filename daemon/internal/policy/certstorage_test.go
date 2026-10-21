package policy_test

import (
	"net/netip"
	"testing"

	"github.com/vantezzen/launchroom/daemon/internal/policy"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

func TestCheckCertStorageAddress(t *testing.T) {
	cfg := policy.DefaultConfig()
	cfg.CertStorageAddrs = []netip.AddrPort{netip.MustParseAddrPort("127.0.0.1:6379")}
	cfg.LocalUpstreams = []netip.AddrPort{netip.MustParseAddrPort("127.0.0.1:8000")}
	for _, ok := range []state.AddrPort{"100.64.0.1:6379", "127.0.0.1:6379", "[::ffff:127.0.0.1]:6379"} {
		if err := policy.CheckCertStorageAddress(ok, cfg); err != nil {
			t.Errorf("%s refused: %v", ok, err)
		}
	}
	for _, bad := range []state.AddrPort{"127.0.0.1:8000", "127.0.0.1:6380", "10.0.0.5:6379", "169.254.169.254:80", "x"} {
		err := policy.CheckCertStorageAddress(bad, cfg)
		if err == nil {
			t.Errorf("%s allowed", bad)
			continue
		}
		assertRule(t, err, "cert-storage")
	}
	// The storage allow-list never opens route upstreams.
	if err := policy.CheckUpstreamAddress("127.0.0.1:6379", cfg); err == nil {
		t.Error("cert storage address became a route upstream")
	}
}
