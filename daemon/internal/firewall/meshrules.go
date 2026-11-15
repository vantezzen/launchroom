package firewall

import (
	"net/netip"

	"github.com/vantezzen/launchroom/daemon/internal/policy"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// MeshRulesFor are the cross-server rules of a NodeState after the local
// policy floor (docs/05 §3, §5): the mesh-published ports and their peers,
// the certificate storage peers (control-plane host), the link-probe peers
// and the ambassador's upstreams. refused lists the allow-list entries the
// floor dropped (ambassador refusals are the ambassador manager's to log).
func MeshRulesFor(ns *state.NodeState, meshIP netip.Addr, linkProbeFrom []netip.Addr) (r MeshRules, refused []string) {
	r.MeshIP = meshIP
	if ns == nil {
		return r, nil
	}
	allow, refused := policy.FilterMeshAllow(ns.Firewall.MeshAllow)
	for _, a := range allow {
		r.MeshAllow = append(r.MeshAllow, MeshAllow{Port: a.Port, From: a.From})
	}
	if cs := ns.System.CertStorage; cs.ServeOnMesh && ns.System.Tailscale.Enabled {
		from, bad := policy.FilterCertStoragePeers(cs.AllowFrom)
		r.CertStoragePort, r.CertStorageFrom = state.CertStorageMeshPort, from
		refused = append(refused, bad...)
	}
	if len(linkProbeFrom) > 0 {
		r.LinkProbePort, r.LinkProbeFrom = state.LinkProbeMeshPort, linkProbeFrom
	}
	ambs, _ := policy.FilterAmbassadors(ns)
	for _, a := range ambs {
		r.AmbassadorUpstreams = append(r.AmbassadorUpstreams, a.Upstreams...)
	}
	return r, refused
}
