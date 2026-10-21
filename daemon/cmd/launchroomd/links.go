package main

import (
	"context"
	"net"
	"net/netip"
	"slices"

	"github.com/vantezzen/launchroom/daemon/internal/identity"
	"github.com/vantezzen/launchroom/daemon/internal/linkprobe"
	"github.com/vantezzen/launchroom/daemon/internal/policy"
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
}

// linkPeers are system.linkProbe's peers after the local policy floor: on
// the mesh (100.64.0.0/10), with a valid key, never this server. Nil when
// probing is off (NodeState, mesh or the local LR_LINK_PROBE=off).
func (rt *daemonRuntime) linkPeers(ns *state.NodeState) []linkprobe.Peer {
	lp := ns.System.LinkProbe
	if rt.cfg.LinkProbeOff || !lp.Enabled || !ns.System.Tailscale.Enabled {
		return nil
	}
	var out []linkprobe.Peer
	for _, p := range lp.Peers {
		ip, err := netip.ParseAddr(string(p.IPv4))
		if err != nil || !policy.MeshPeer(ip) || p.ServerID == rt.serverID {
			continue
		}
		key, err := identity.ParsePublicKey(string(p.SigningKey))
		if err != nil {
			continue
		}
		out = append(out, linkprobe.Peer{ID: string(p.ServerID), IP: ip.Unmap(), Key: key})
	}
	slices.SortFunc(out, func(a, b linkprobe.Peer) int { return a.IP.Compare(b.IP) })
	return out
}

// linkProbeFrom are the peers nftables lets reach the probe port.
func (rt *daemonRuntime) linkProbeFrom(ns *state.NodeState) []netip.Addr {
	peers := rt.linkPeers(ns)
	out := make([]netip.Addr, 0, len(peers))
	for _, p := range peers {
		out = append(out, p.IP)
	}
	return out
}

// syncLinkProbe hands the peers to the prober and the server and serves
// <mesh IP>:7400 while there are any; a new mesh address moves the listener.
func (rt *daemonRuntime) syncLinkProbe(ctx context.Context, ns *state.NodeState) {
	peers := rt.linkPeers(ns)
	rt.links.SetPeers(peers)
	rt.linkSrv.SetPeers(peers)
	want := ""
	if ip := rt.meshIP(); ip.IsValid() && len(peers) > 0 {
		want = netip.AddrPortFrom(ip, state.LinkProbeMeshPort).String()
	}
	if want == rt.linkAddr {
		return
	}
	if rt.linkStop != nil {
		rt.linkStop()
		rt.log.Info("link probe listener stopped", "addr", rt.linkAddr)
	}
	rt.linkAddr, rt.linkStop = "", nil
	if want == "" {
		return
	}
	var lc net.ListenConfig
	l, err := lc.Listen(ctx, "tcp", want)
	if err != nil {
		rt.log.Warn("link probe listener cannot listen; retrying on the next pass", "addr", want, "err", err)
		return
	}
	lctx, cancel := context.WithCancel(ctx)
	rt.linkAddr, rt.linkStop = want, cancel
	rt.log.Info("link probe listener listening", "addr", want, "peers", len(peers))
	go func() {
		if err := rt.linkSrv.Serve(lctx, l); err != nil {
			rt.log.Error("link probe listener stopped", "err", err)
		}
	}()
}
