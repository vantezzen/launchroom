package state

import (
	"time"
)

// ---- Private-network link quality (docs/05 §2.1) ----

// LinkProbeMeshPort is where launchroomd answers link probes from its peers,
// on its mesh IPv4 only. nftables (`mesh-host`) allows it only from the
// peers listed in system.linkProbe.peers.
const LinkProbeMeshPort = 7400

// Limits of the link probe documents.
const (
	MaxLinkProbePeers = 256
	// MaxLinkRTTMs bounds a reported round-trip time (one minute).
	MaxLinkRTTMs = 60000
	// MaxLinkThroughputMbps bounds a reported throughput (400 Gbit/s).
	MaxLinkThroughputMbps = 400000
)

// LinkProbeSpec is NodeState.system.linkProbe: measure latency and
// throughput to these peers over the mesh and answer their probes. Omitted
// (off) when the mesh is off or the server opted out. Every peer is another
// launchroom server; its signing key authenticates its probes (and its
// answers to ours), on top of WireGuard's peer authentication.
type LinkProbeSpec struct {
	Enabled bool            `json:"enabled,omitzero"`
	Peers   []LinkProbePeer `json:"peers,omitzero" jsonschema:"nullable,maxItems=256"`
}

// LinkProbePeer is one server to measure and to accept probes from.
type LinkProbePeer struct {
	ServerID ServerID `json:"serverId"`
	// IPv4 is the peer's mesh address (local policy: inside 100.64.0.0/10).
	IPv4 IPAddr `json:"ipv4"`
	// SigningKey is the peer launchroomd's Ed25519 request-signing key.
	SigningKey Ed25519Key `json:"signingKey"`
}

func (s *LinkProbeSpec) validate(e *errs, self ServerID) {
	const p = "system.linkProbe"
	checkCount(e, p+".peers", len(s.Peers), 0, MaxLinkProbePeers)
	if len(s.Peers) > 0 && !s.Enabled {
		e.addf(p+".peers", "are only allowed when enabled")
	}
	ids := make([]ServerID, 0, len(s.Peers))
	ips := make([]IPAddr, 0, len(s.Peers))
	for i, peer := range s.Peers {
		pp := idx(p+".peers", i)
		e.check(pp+".serverId", peer.ServerID)
		e.check(pp+".ipv4", peer.IPv4)
		e.check(pp+".signingKey", peer.SigningKey)
		if peer.ServerID == self {
			e.addf(pp+".serverId", "must not be this server")
		}
		ids = append(ids, peer.ServerID)
		ips = append(ips, peer.IPv4)
	}
	unique(e, p+".peers.serverId", ids)
	unique(e, p+".peers.ipv4", ips)
}

// LinkMeasurement is one entry of Report.host.links: the latest
// measurement from this server to a peer. Reports repeat it until the next
// one; the dashboard keeps one sample per measuredAt.
type LinkMeasurement struct {
	Peer ServerID `json:"peer"`
	// MeasuredAt is when the latency probe ran (daemon clock).
	MeasuredAt time.Time `json:"measuredAt"`
	// RTTMs is the median round trip of a few small messages over one
	// connection, in milliseconds; omitted when the probe failed.
	RTTMs *float64 `json:"rttMs,omitzero" jsonschema:"nullable,minimum=0,maximum=60000"`
	// Error says why the latest probe failed (secret-free); omitted on success.
	Error string `json:"error,omitzero" jsonschema:"maxLength=256"`
	// ThroughputMbps is the latest download rate from the peer (a short,
	// size-capped transfer, measured rarely); omitted until one succeeded.
	ThroughputMbps *float64 `json:"throughputMbps,omitzero" jsonschema:"nullable,minimum=0,maximum=400000"`
	// ThroughputAt is when that transfer ran.
	ThroughputAt *time.Time `json:"throughputAt,omitzero" jsonschema:"nullable"`
}

func validateLinks(e *errs, p string, links []LinkMeasurement) {
	checkCount(e, p, len(links), 0, MaxLinkProbePeers)
	peers := make([]ServerID, 0, len(links))
	for i, l := range links {
		lp := idx(p, i)
		e.check(lp+".peer", l.Peer)
		if l.MeasuredAt.IsZero() {
			e.addf(lp+".measuredAt", "is required")
		}
		checkFloat(e, lp+".rttMs", l.RTTMs, MaxLinkRTTMs)
		checkFloat(e, lp+".throughputMbps", l.ThroughputMbps, MaxLinkThroughputMbps)
		checkText(e, lp+".error", l.Error, 256)
		if (l.ThroughputMbps == nil) != (l.ThroughputAt == nil) {
			e.addf(lp+".throughputAt", "is required with throughputMbps (and only then)")
		}
		peers = append(peers, l.Peer)
	}
	unique(e, p+".peer", peers)
}
