package main

import (
	"context"
	"crypto/ecdh"
	"net/netip"
	"slices"

	"github.com/vantezzen/launchroom/daemon/internal/ambassadord"
	"github.com/vantezzen/launchroom/daemon/internal/docker"
	"github.com/vantezzen/launchroom/daemon/internal/firewall"
	"github.com/vantezzen/launchroom/daemon/internal/linkprobe"
	"github.com/vantezzen/launchroom/daemon/internal/mesh"
	"github.com/vantezzen/launchroom/daemon/internal/policy"
	"github.com/vantezzen/launchroom/daemon/internal/registry"
	"github.com/vantezzen/launchroom/daemon/internal/sealed"
	"github.com/vantezzen/launchroom/daemon/internal/secret"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

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
	rt.meshRegListener.name, rt.certListener.name = "mesh registry forwarder", "certificate storage forwarder"
	rt.ambassador = ambassadord.New(ambassadord.Options{
		ServerID: string(rt.serverID), Docker: dc, Ops: ops, Binary: rt.cfg.AmbassadorBinary, Subnet: rt.cfg.AmbassadorSubnet,
		Log: rt.log.With("component", "ambassador"),
	})
}

// meshRules are the cross-server firewall rules of the current NodeState
// after the local policy floor; refusals are logged when they change.
func (rt *daemonRuntime) meshRules() firewall.MeshRules {
	var ns *state.NodeState
	if cur := rt.store.Current(); cur != nil {
		ns = cur.State
	}
	var linkFrom []netip.Addr
	if ns != nil {
		linkFrom = linkprobe.Addrs(rt.linkPeers(ns))
	}
	r, refused := firewall.MeshRulesFor(ns, rt.meshIP(), linkFrom)
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
	f := registry.NewForwarder(want, rt.cfg.CertStorageUpstream, rt.log.With("component", "cert-storage-mesh"))
	rt.certListener.set(ctx, rt.log, want, f.ServeListener)
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
// set and the mesh address is known.
func (rt *daemonRuntime) syncMeshRegistry(ctx context.Context, serve bool, ip netip.Addr) {
	want := ""
	if serve && ip.IsValid() {
		want = netip.AddrPortFrom(ip, state.RegistryMeshPort).String()
	}
	// Zot listens on 127.0.0.1:5000 on the control-plane host; in dev the
	// fake server reaches it through LR_REGISTRY_UPSTREAM.
	upstream := rt.cfg.RegistryUpstream
	if upstream == "" {
		upstream = registry.DefaultAddress
	}
	f := registry.NewForwarder(want, upstream, rt.log.With("component", "registry-mesh"))
	rt.meshRegListener.set(ctx, rt.log, want, f.ServeListener)
}
