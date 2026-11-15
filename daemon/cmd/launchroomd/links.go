package main

import (
	"context"
	"net/netip"

	"github.com/vantezzen/launchroom/daemon/internal/identity"
	"github.com/vantezzen/launchroom/daemon/internal/linkprobe"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// setupLinkProbe prepares the private-network link measurements
// (docs/05 §2.1): a prober for the peers and a server answering their
// probes. Both idle until the NodeState lists peers.
func (rt *daemonRuntime) setupLinkProbe(id *identity.Identity) {
	log := rt.log.With("component", "linkprobe")
	rt.links = linkprobe.NewProber(linkprobe.ProberOptions{
		Self: string(rt.serverID), Sign: id.SignMessage, LocalIP: rt.meshIP, Log: log, Now: rt.now,
	})
	rt.linkSrv = linkprobe.NewServer(linkprobe.ServerOptions{Self: string(rt.serverID), Sign: id.SignMessage, Log: log, Now: rt.now})
	rt.linkListener.name = "link probe listener"
}

// linkPeers are the probe peers; none while the local LR_LINK_PROBE=off.
func (rt *daemonRuntime) linkPeers(ns *state.NodeState) []linkprobe.Peer {
	if rt.cfg.LinkProbeOff {
		return nil
	}
	return linkprobe.Peers(ns, rt.serverID)
}

// syncLinkProbe hands the peers to the prober and the server and serves
// <mesh IP>:7400 while there are any.
func (rt *daemonRuntime) syncLinkProbe(ctx context.Context, ns *state.NodeState) {
	peers := rt.linkPeers(ns)
	rt.links.SetPeers(peers)
	rt.linkSrv.SetPeers(peers)
	want := ""
	if ip := rt.meshIP(); ip.IsValid() && len(peers) > 0 {
		want = netip.AddrPortFrom(ip, state.LinkProbeMeshPort).String()
	}
	rt.linkListener.set(ctx, rt.log, want, rt.linkSrv.Serve)
}
