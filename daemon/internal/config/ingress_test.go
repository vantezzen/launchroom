package config

import (
	"net/netip"
	"slices"
	"testing"
)

func TestLoadCertStorageAndDigests(t *testing.T) {
	d := "sha256:" + "ab12" + "0123456789abcdef0123456789abcdef0123456789abcdef0123456789ab"
	cfg, err := Load(nil, envOf(map[string]string{
		EnvControlPlaneURL:    "https://dash.example.com",
		EnvCertStorageAllow:   "127.0.0.1:6379",
		EnvSystemImageDigests: "caddy=" + d,
	}), noFile)
	if err != nil {
		t.Fatal(err)
	}
	if !slices.Equal(cfg.CertStorageAllow, []netip.AddrPort{netip.MustParseAddrPort("127.0.0.1:6379")}) {
		t.Errorf("storage allow = %v", cfg.CertStorageAllow)
	}
	if !slices.Equal(cfg.SystemImageDigests["caddy"], []string{d}) {
		t.Errorf("digests = %v", cfg.SystemImageDigests)
	}
	for _, bad := range []map[string]string{
		{EnvCertStorageAllow: "valkey:6379"},
		{EnvSystemImageDigests: "caddy=latest"},
		{EnvSystemImageDigests: "tailscale=" + d},
	} {
		bad[EnvControlPlaneURL] = "https://dash.example.com"
		if _, err := Load(nil, envOf(bad), noFile); err == nil {
			t.Errorf("%v accepted", bad)
		}
	}
}
