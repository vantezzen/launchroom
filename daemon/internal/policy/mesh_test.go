package policy

import (
	"testing"

	"github.com/vantezzen/launchroom/daemon/internal/state"
)

func stateURL(s string) state.MeshLoginURL { return state.MeshLoginURL(s) }

func TestCheckMeshLoginServer(t *testing.T) {
	for url, ok := range map[string]bool{
		"https://dash.example.com":           true,
		"https://dash.example.com:8443":      true,
		"http://dash.example.com":            false,
		"http://headscale":                   true,
		"http://HEADSCALE":                   false, // grammar: lowercase only
		"https://dash.example.com/path":      false,
		"ftp://dash.example.com":             false,
		"https://user@dash.example.com":      false,
		"https://controlplane.tailscale.com": true,
	} {
		err := CheckMeshLoginServer(stateURL(url), []string{"headscale"})
		if (err == nil) != ok {
			t.Errorf("%s: err = %v, want ok=%v", url, err, ok)
		}
	}
}

func TestFilterAmbassadors(t *testing.T) {
	ns := &state.NodeState{
		Networks: []state.NetworkSpec{{ID: "net_01J9Z8X7W6V5T4S3R2Q1P0N9N1", Name: "lr-e_aaaaaaaaaaaaaaaa", Subnet: "10.231.4.0/24"}},
		Ambassadors: []state.AmbassadorSpec{
			{Network: "net_01J9Z8X7W6V5T4S3R2Q1P0N9N1", Alias: "postgres", Port: 5432, Upstreams: []state.AddrPort{"100.64.0.7:31001", "192.168.1.5:31001", "100.64.0.8:5432", "100.64.0.7:31001"}},
			{Network: "net_01J9Z8X7W6V5T4S3R2Q1P0N9N1", Alias: "redis", Port: 6379, Upstreams: []state.AddrPort{"10.0.0.1:31002"}},
			{Network: "net_01J9Z8X7W6V5T4S3R2Q1P0N9N9", Alias: "api", Port: 3000, Upstreams: []state.AddrPort{"100.64.0.7:31003"}},
		},
	}
	got, refused := FilterAmbassadors(ns)
	if len(got) != 1 || got[0].Alias != "postgres" || len(got[0].Upstreams) != 1 || got[0].Upstreams[0].String() != "100.64.0.7:31001" {
		t.Fatalf("kept %+v", got)
	}
	if len(refused) != 5 {
		t.Fatalf("refused %q", refused)
	}
}

func TestFilterMeshAllowAndPeers(t *testing.T) {
	got, refused := FilterMeshAllow([]state.MeshAllowRule{
		{Port: 31001, From: []state.IPAddr{"100.64.0.3", "192.168.1.5", "100.64.0.2"}},
		{Port: 31002, From: []state.IPAddr{"10.0.0.1"}},
		{Port: 22, From: []state.IPAddr{"100.64.0.3"}},
	})
	if len(got) != 1 || got[0].Port != 31001 || len(got[0].From) != 2 || got[0].From[0].String() != "100.64.0.2" {
		t.Fatalf("kept %+v", got)
	}
	if len(refused) != 3 {
		t.Fatalf("refused %q", refused)
	}
	peers, bad := FilterCertStoragePeers([]state.IPAddr{"100.64.0.4", "8.8.8.8"})
	if len(peers) != 1 || len(bad) != 1 {
		t.Fatalf("%v %v", peers, bad)
	}
}
