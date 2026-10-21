package state

import (
	"os"
	"slices"
	"strings"
	"testing"
	"time"
)

func TestLinkProbeValidation(t *testing.T) {
	raw, err := os.ReadFile(fixtureDir + "/node-state.link-probe.json")
	if err != nil {
		t.Fatal(err)
	}
	ns, err := Decode[NodeState](raw)
	if err != nil {
		t.Fatal(err)
	}
	if !ns.System.LinkProbe.Enabled || len(ns.System.LinkProbe.Peers) != 2 {
		t.Fatalf("linkProbe %+v", ns.System.LinkProbe)
	}
	for name, mutate := range map[string]func(*NodeState){
		"peers while disabled": func(n *NodeState) { n.System.LinkProbe.Enabled = false },
		"itself as a peer":     func(n *NodeState) { n.System.LinkProbe.Peers[0].ServerID = n.ServerID },
		"duplicate address":    func(n *NodeState) { n.System.LinkProbe.Peers[1].IPv4 = n.System.LinkProbe.Peers[0].IPv4 },
		"duplicate server":     func(n *NodeState) { n.System.LinkProbe.Peers[1].ServerID = n.System.LinkProbe.Peers[0].ServerID },
		"not a key":            func(n *NodeState) { n.System.LinkProbe.Peers[0].SigningKey = "x25519:abc" },
		"not an ip":            func(n *NodeState) { n.System.LinkProbe.Peers[0].IPv4 = "server-2" },
	} {
		c := *ns
		c.System.LinkProbe.Peers = slices.Clone(ns.System.LinkProbe.Peers)
		mutate(&c)
		if err := c.Validate(); err == nil || !strings.Contains(err.Error(), "linkProbe") {
			t.Errorf("%s: %v", name, err)
		}
	}
}

func TestReportLinksValidation(t *testing.T) {
	raw, err := os.ReadFile(fixtureDir + "/report.links.json")
	if err != nil {
		t.Fatal(err)
	}
	rep, err := Decode[Report](raw)
	if err != nil {
		t.Fatal(err)
	}
	if len(rep.Host.Links) != 3 || rep.Host.Links[0].RTTMs == nil || *rep.Host.Links[0].ThroughputMbps != 482.6 {
		t.Fatalf("links %+v", rep.Host.Links)
	}
	neg, huge := -1.0, 1e9
	at := time.Now()
	for name, mutate := range map[string]func(*LinkMeasurement){
		"negative rtt":            func(l *LinkMeasurement) { l.RTTMs = &neg },
		"absurd throughput":       func(l *LinkMeasurement) { l.ThroughputMbps = &huge },
		"throughput without time": func(l *LinkMeasurement) { l.ThroughputAt = nil },
		"time without throughput": func(l *LinkMeasurement) { l.ThroughputMbps, l.ThroughputAt = nil, &at },
		"no time":                 func(l *LinkMeasurement) { l.MeasuredAt = time.Time{} },
		"long error":              func(l *LinkMeasurement) { l.Error = strings.Repeat("x", 300) },
		"bad peer":                func(l *LinkMeasurement) { l.Peer = "server-2" },
	} {
		c := *rep
		h := *rep.Host
		h.Links = slices.Clone(rep.Host.Links)
		c.Host = &h
		mutate(&h.Links[0])
		if err := c.Validate(); err == nil || !strings.Contains(err.Error(), "host.links") {
			t.Errorf("%s: %v", name, err)
		}
	}
}
