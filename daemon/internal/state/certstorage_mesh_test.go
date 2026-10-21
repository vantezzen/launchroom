package state

import (
	"os"
	"strings"
	"testing"
)

func TestCertStorageMeshValidation(t *testing.T) {
	raw, err := os.ReadFile(fixtureDir + "/node-state.cross-server.json")
	if err != nil {
		t.Fatal(err)
	}
	ns, err := Decode[NodeState](raw)
	if err != nil {
		t.Fatal(err)
	}
	if !ns.System.CertStorage.ServeOnMesh || len(ns.System.CertStorage.AllowFrom) != 2 {
		t.Fatalf("certStorage %+v", ns.System.CertStorage)
	}
	for name, mutate := range map[string]func(*NodeState){
		"allowFrom without serveOnMesh": func(n *NodeState) { n.System.CertStorage.ServeOnMesh = false },
		"duplicate peer":                func(n *NodeState) { n.System.CertStorage.AllowFrom = []IPAddr{"100.64.0.2", "100.64.0.2"} },
		"not an ip":                     func(n *NodeState) { n.System.CertStorage.AllowFrom = []IPAddr{"edge-1"} },
	} {
		c := *ns
		c.System.CertStorage.AllowFrom = append([]IPAddr(nil), ns.System.CertStorage.AllowFrom...)
		mutate(&c)
		if err := c.Validate(); err == nil || !strings.Contains(err.Error(), "certStorage") {
			t.Errorf("%s: %v", name, err)
		}
	}
}
