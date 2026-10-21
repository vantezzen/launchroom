package main

import (
	"context"
	"crypto/ecdh"
	"net/netip"
	"slices"

	"github.com/vantezzen/launchroom/daemon/internal/ambassadord"
	"github.com/vantezzen/launchroom/daemon/internal/docker"
	"github.com/vantezzen/launchroom/daemon/internal/firewall"
	"github.com/vantezzen/launchroom/daemon/internal/mesh"
	"github.com/vantezzen/launchroom/daemon/internal/policy"
	"github.com/vantezzen/launchroom/daemon/internal/registry"
	"github.com/vantezzen/launchroom/daemon/internal/sealed"
	"github.com/vantezzen/launchroom/daemon/internal/secret"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// meshRegistryPort is where the control-plane host serves the registry on its mesh IP (docs/05 §6).
const meshRegistryPort = 5000

// setupMesh prepares the mesh client manager (docs/05 §2). The client only
// runs when the NodeState enables it.
func (rt *daemonRuntime) setupMesh(dc docker.Client, pol policy.Config, priv *ecdh.PrivateKey) {
	ex, _ := dc.(docker.Execer)
	rt.mesh = mesh.New(mesh.Options{
		ServerID: rt.serverID, Docker: dc, Exec: ex, Policy: pol, InsecureLoginHosts: rt.cfg.MeshInsecureLoginHosts,
		OpenAuthKey: func(s state.Sealed) (secret.Secret, error) { return sealed.OpenMeshAuthKey(s, priv, rt.serverID) },
		Log:         rt.log.With("component", "mesh"), Now: rt.now,
	})
	ops, _ := dc.(docker.AmbassadorOps)
	rt.ambassador = ambassadord.New(ambassadord.Options{
		ServerID: string(rt.serverID), Docker: dc, Ops: ops, Binary: rt.cfg.AmbassadorBinary, Subnet: rt.cfg.AmbassadorSubnet,
		Isolate: rt.firewall.Sync, Log: rt.log.With("component", "ambassador"),
	})
}

// meshRules are the cross-server firewall rules of the current NodeState
// after the local policy floor (docs/05 §3, §5): the mesh-published ports
// and their peers, the certificate storage peers (control-plane host) and
// the ambassador's upstreams.
func (rt *daemonRuntime) meshRules() firewall.MeshRules {
	r := firewall.MeshRules{MeshIP: rt.meshIP()}
	cur := rt.store.Current()
	if cur == nil {
		return r
	}
	ns := cur.State
	allow, refused := policy.FilterMeshAllow(ns.Firewall.MeshAllow)
	for _, a := range allow {
		r.MeshAllow = append(r.MeshAllow, firewall.MeshAllow{Port: a.Port, From: a.From})
	}
	if cs := ns.System.CertStorage; cs.ServeOnMesh && ns.System.Tailscale.Enabled {
		from, bad := policy.FilterCertStoragePeers(cs.AllowFrom)
		r.CertStoragePort, r.CertStorageFrom = state.CertStorageMeshPort, from
		refused = append(refused, bad...)
	}
	if from := rt.linkProbeFrom(ns); len(from) > 0 {
		r.LinkProbePort, r.LinkProbeFrom = state.LinkProbeMeshPort, from
	}
	ambs, _ := policy.FilterAmbassadors(ns) // refusals are logged by the ambassador manager
	for _, a := range ambs {
		r.AmbassadorUpstreams = append(r.AmbassadorUpstreams, a.Upstreams...)
	}
	rt.meshRefusedMu.Lock()
	if !slices.Equal(refused, rt.meshRefused) {
		if len(refused) > 0 {
			rt.log.Warn("local policy refused mesh allow-list entries", "refused", refused)
		}
		rt.meshRefused = refused
	}
	rt.meshRefusedMu.Unlock()
	return r
}

// syncAmbassador converges the ambassador container (after the reconciler
// created the environment networks it joins).
func (rt *daemonRuntime) syncAmbassador(ctx context.Context, ns *state.NodeState) {
	if _, err := rt.ambassador.Sync(ctx, ns); err != nil && ctx.Err() == nil {
		rt.log.Warn("ambassador not converged", "err", err)
	}
}

// syncCertStorage serves <mesh IP>:6379 → the local Valkey while the
// NodeState asks for it (control-plane host with edges sharing certificate
// storage, docs/05 §5); nftables allows the port only from the listed edges.
func (rt *daemonRuntime) syncCertStorage(ctx context.Context, ns *state.NodeState) {
	want := ""
	if ns.System.CertStorage.ServeOnMesh && ns.System.Tailscale.Enabled && rt.cfg.CertStorageUpstream != "" {
		if ip := rt.meshIP(); ip.IsValid() {
			want = netip.AddrPortFrom(ip, state.CertStorageMeshPort).String()
		}
	}
	cur := ""
	if rt.certFwd != nil {
		cur = rt.certFwd.Addr()
	}
	if want == cur {
		return
	}
	if rt.certFwdStop != nil {
		rt.certFwdStop()
		rt.log.Info("certificate storage forwarder stopped", "addr", cur)
	}
	rt.certFwd, rt.certFwdStop = nil, nil
	if want == "" {
		return
	}
	f := registry.NewForwarder(want, rt.cfg.CertStorageUpstream, rt.log.With("component", "cert-storage-mesh"))
	fctx, cancel := context.WithCancel(ctx)
	if !rt.serveForwarder(fctx, f, "certificate storage forwarder") {
		cancel()
		return
	}
	rt.certFwd, rt.certFwdStop = f, cancel
}

// syncMesh converges the mesh client and, on the control-plane host, the
// registry forwarder on the mesh IP. Called from the reconcile loop only.
func (rt *daemonRuntime) syncMesh(ctx context.Context, ns *state.NodeState) {
	rt.mesh.Sync(ctx, ns.System.Tailscale)
	rt.syncMeshRegistry(ctx, ns.System.Registry.ServeOnMesh && ns.System.Tailscale.Enabled, rt.mesh.IPv4())
	rt.syncCertStorage(ctx, ns)
	rt.syncLinkProbe(ctx, ns)
}

// syncMeshRegistry serves <mesh IP>:5000 → the local registry while serve is
// set and the mesh address is known; a new address moves the listener.
func (rt *daemonRuntime) syncMeshRegistry(ctx context.Context, serve bool, ip netip.Addr) {
	want := ""
	if serve && ip.IsValid() {
		want = netip.AddrPortFrom(ip, meshRegistryPort).String()
	}
	cur := ""
	if rt.meshFwd != nil {
		cur = rt.meshFwd.Addr()
	}
	if want == cur {
		return
	}
	if rt.meshFwdStop != nil {
		rt.meshFwdStop()
		rt.log.Info("mesh registry forwarder stopped", "addr", cur)
	}
	rt.meshFwd, rt.meshFwdStop = nil, nil
	if want == "" {
		return
	}
	// Zot listens on 127.0.0.1:5000 on the control-plane host; in dev the
	// fake server reaches it through LR_REGISTRY_UPSTREAM.
	upstream := rt.cfg.RegistryUpstream
	if upstream == "" {
		upstream = registry.DefaultAddress
	}
	f := registry.NewForwarder(want, upstream, rt.log.With("component", "registry-mesh"))
	fctx, cancel := context.WithCancel(ctx)
	if !rt.serveForwarder(fctx, f, "mesh registry forwarder") {
		cancel()
		return
	}
	rt.meshFwd, rt.meshFwdStop = f, cancel
}
