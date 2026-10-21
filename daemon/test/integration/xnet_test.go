//go:build integration

package integration

import (
	"context"
	"crypto/ecdh"
	"crypto/rand"
	"fmt"
	"io"
	"log/slog"
	"maps"
	"net/http"
	"net/netip"
	"os"
	"slices"
	"strings"
	"sync"
	"testing"
	"time"

	"github.com/moby/moby/api/types/container"
	"github.com/moby/moby/client"

	"github.com/vantezzen/launchroom/daemon/internal/ambassadord"
	"github.com/vantezzen/launchroom/daemon/internal/docker"
	"github.com/vantezzen/launchroom/daemon/internal/firewall"
	"github.com/vantezzen/launchroom/daemon/internal/health"
	"github.com/vantezzen/launchroom/daemon/internal/mesh"
	"github.com/vantezzen/launchroom/daemon/internal/policy"
	"github.com/vantezzen/launchroom/daemon/internal/reconcile"
	"github.com/vantezzen/launchroom/daemon/internal/sealed"
	"github.com/vantezzen/launchroom/daemon/internal/secret"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// xnetPeer is a mesh peer (another "server") in its own network namespace:
// a tailscaled container with its own tailscale0, plus an optional busybox
// sidecar sharing that namespace.
type xnetPeer struct {
	id string
	ip netip.Addr
}

func (m *meshEnv) startPeer(ctx context.Context, t *testing.T, name, key string) xnetPeer {
	t.Helper()
	id, err := fixtureEngine(t, m.serverID).CreateContainer(ctx, &docker.ContainerCreate{
		Name: "lr-itest-" + name,
		Config: &container.Config{Image: string(tailscaleImage), Entrypoint: []string{"tailscaled"},
			Cmd: []string{"--state=mem:", "--socket=/var/run/tailscale/tailscaled.sock", "--tun=tailscale0"}, Labels: maps.Clone(docker.ManagedLabels(string(m.serverID)))},
		HostConfig: &container.HostConfig{
			NetworkMode: "lr-itest-mesh", CapAdd: []string{"NET_ADMIN", "NET_RAW"},
			Resources: container.Resources{Devices: []container.DeviceMapping{{PathOnHost: "/dev/net/tun", PathInContainer: "/dev/net/tun", CgroupPermissions: "rwm"}}},
		},
	})
	if err != nil {
		t.Fatal(err)
	}
	if err := m.engine.StartContainer(ctx, id); err != nil {
		t.Fatal(err)
	}
	sock := "--socket=/var/run/tailscale/tailscaled.sock"
	eventuallyWithin(t, 2*time.Minute, name+" logged in", func() bool {
		_, code := m.exec(ctx, t, id, "tailscale", sock, "up", "--reset",
			"--login-server=http://"+meshGateway, "--auth-key="+key, "--hostname=lr-itest-"+name, "--accept-dns=false", "--timeout=20s")
		return code == 0
	})
	out, code := m.exec(ctx, t, id, "tailscale", sock, "ip", "-4")
	ip, err := netip.ParseAddr(strings.TrimSpace(out))
	if code != 0 || err != nil {
		t.Fatalf("%s mesh ip: %q %v", name, out, err)
	}
	t.Logf("peer %s joined as %s", name, ip)
	return xnetPeer{id: id, ip: ip}
}

// sidecar runs busybox in the peer's network namespace.
func (m *meshEnv) sidecar(ctx context.Context, t *testing.T, p xnetPeer, name string, cmd ...string) {
	t.Helper()
	id, err := fixtureEngine(t, m.serverID).CreateContainer(ctx, &docker.ContainerCreate{
		Name:       "lr-itest-" + name,
		Config:     &container.Config{Image: busyboxImage, Cmd: cmd, Labels: maps.Clone(docker.ManagedLabels(string(m.serverID)))},
		HostConfig: &container.HostConfig{NetworkMode: container.NetworkMode("container:" + p.id)},
	})
	if err != nil {
		t.Fatal(err)
	}
	if err := m.engine.StartContainer(ctx, id); err != nil {
		t.Fatal(err)
	}
}

// fetch runs wget in a peer and reports whether it got a body containing want.
func (m *meshEnv) fetch(ctx context.Context, t *testing.T, p xnetPeer, url, want string) bool {
	t.Helper()
	out, code := m.exec(ctx, t, p.id, "wget", "-qO-", "-T", "4", url)
	return code == 0 && strings.Contains(out, want)
}

// TestCrossServerMesh runs the cross-server pieces of docs/05 §3 against a
// real Headscale, real kernel-mode Tailscale clients and nftables: the test
// daemon's namespace is the provider/consumer server, two runtime peers in
// their own network namespaces are "other servers".
//
//   - A workload published on the mesh waits for the mesh address, is bound
//     to the mesh IP only, and is reachable only from the peers in
//     firewall.meshAllow (the ACL lets both peers through; nftables narrows).
//   - The ambassador makes a remote service reachable by its alias from an
//     environment network, while workloads still can't reach the mesh.
//   - A container restarted while the mesh is down (reboot order) comes
//     back once the mesh address is there again.
func TestCrossServerMesh(t *testing.T) {
	api, err := client.New(client.FromEnv)
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { _ = api.Close() })
	sweep(t, api)
	t.Cleanup(func() { sweep(t, api) })
	if _, err := os.Stat("/dev/net/tun"); err != nil {
		t.Skipf("no /dev/net/tun in the test daemon: %v", err)
	}
	ambBin := "/opt/launchroom/bin/launchroom-ambassador"
	if _, err := os.Stat(ambBin); err != nil {
		t.Fatalf("ambassador binary missing (built by make test-daemon-integration): %v", err)
	}
	log := slog.New(slog.NewTextHandler(os.Stderr, &slog.HandlerOptions{Level: slog.LevelInfo}))
	m := &meshEnv{serverID: state.ServerID(randomID("srv")), api: api}
	m.engine, err = docker.NewEngine(docker.EngineOptions{Host: engineHost, ServerID: string(m.serverID), UserAgent: "launchroom-itest"})
	if err != nil {
		t.Fatal(err)
	}
	ctx, cancel := context.WithTimeout(context.Background(), 15*time.Minute)
	defer cancel()
	for _, img := range []string{headscaleImage, string(tailscaleImage), busyboxImage, string(whoamiImage)} {
		pullImage(t, api, img)
	}
	startHeadscale(ctx, t, m)
	hostKey := m.preAuthKey(ctx, t, "tag:lr-cp,tag:lr-edge,tag:lr-runtime")

	mgr := mesh.New(mesh.Options{
		ServerID: m.serverID, Docker: m.engine, Exec: m.engine, Policy: policy.DefaultConfig(), InsecureLoginHosts: []string{"127.0.0.1"},
		OpenAuthKey: func(state.Sealed) (secret.Secret, error) { return secret.Secret(hostKey), nil },
		Log:         log,
	})
	meshSpec := state.TailscaleSpec{Enabled: true, Image: tailscaleImage, LoginServer: "http://127.0.0.1", AuthKey: &state.Sealed{Sealed: "aXRlc3Q="}}
	t.Cleanup(func() { mgr.Sync(context.WithoutCancel(ctx), state.TailscaleSpec{}) })

	// The current NodeState, shared with the firewall's rule source.
	var mu sync.Mutex
	var cur *state.NodeState
	current := func() *state.NodeState { mu.Lock(); defer mu.Unlock(); return cur }
	fw := firewall.New(firewall.Options{
		Backend: firewall.NewBackend(), Networks: m.engine.ListNetworks, MeshInterface: mesh.Interface,
		AmbassadorNetwork: ambassadord.NetworkName, Log: log,
		Mesh: func() firewall.MeshRules {
			r := firewall.MeshRules{MeshIP: mgr.IPv4()}
			ns := current()
			if ns == nil {
				return r
			}
			allow, _ := policy.FilterMeshAllow(ns.Firewall.MeshAllow)
			for _, a := range allow {
				r.MeshAllow = append(r.MeshAllow, firewall.MeshAllow{Port: a.Port, From: a.From})
			}
			ambs, _ := policy.FilterAmbassadors(ns)
			for _, a := range ambs {
				r.AmbassadorUpstreams = append(r.AmbassadorUpstreams, a.Upstreams...)
			}
			return r
		},
	})
	t.Cleanup(func() { _ = fw.Remove() })

	key, err := ecdh.X25519().GenerateKey(rand.Reader)
	if err != nil {
		t.Fatal(err)
	}
	pol := policy.DefaultConfig()
	pol.AllowedRegistries = []string{"docker.io/traefik/"}
	mon := health.NewMonitor(ctx, health.MonitorOptions{})
	t.Cleanup(mon.Close)
	amb := ambassadord.New(ambassadord.Options{
		ServerID: string(m.serverID), Docker: m.engine, Ops: m.engine, Binary: ambBin, Isolate: fw.Sync, DrainSeconds: 2, Log: log,
	})
	rec := reconcile.New(reconcile.Options{
		ServerID: m.serverID, Docker: m.engine, Policy: pol, Health: mon, EnvHashKey: reconcile.EnvHashKey(key.Bytes()),
		OpenEnv: func(s *state.WorkloadSpec) (map[string]secret.Secret, error) {
			return sealed.OpenWorkloadEnv(s.Env, key, m.serverID, s.ID)
		},
		MeshIP: mgr.IPv4, Isolate: fw.Sync, StopGrace: 2 * time.Second, Log: log, BeforeNetworkRemove: amb.Detach,
	})

	netID := state.NetworkID("net_01J9Z8X7W6V5T4S3R2Q1P0N9X1")
	envNet := state.NetworkSpec{ID: netID, Name: "lr-e_xnet", Subnet: "10.231.251.0/24"}
	wl := state.WorkloadID("wl_01J9Z8X7W6V5T4S3R2Q1P0N9X7")
	sealedEnv, err := sealed.SealWorkloadEnv(state.WorkloadEnv{ServerID: m.serverID, WorkloadID: wl,
		Env: map[string]secret.Secret{"WHOAMI_NAME": "xnet-provider"}}, key.PublicKey(), rand.Reader)
	if err != nil {
		t.Fatal(err)
	}
	port := state.Port(8080)
	build := func(version int64, allow []state.IPAddr, ambs []state.AmbassadorSpec) *state.NodeState {
		ns := &state.NodeState{
			TypeMeta: state.NewTypeMeta(state.KindNodeState), ServerID: m.serverID, Version: version, IssuedAt: time.Now().UTC(),
			System: state.SystemSpec{Tailscale: state.TailscaleSpec{Enabled: true, Image: tailscaleImage, LoginServer: meshSpec.LoginServer}}, Networks: []state.NetworkSpec{envNet}, Volumes: []state.VolumeSpec{},
			Workloads: []state.WorkloadSpec{{
				ID: wl, Service: "svc_01J9Z8X7W6V5T4S3R2Q1P0N9X2", Environment: "env_01J9Z8X7W6V5T4S3R2Q1P0N9X4", Deployment: "dpl_01J9Z8X7W6V5T4S3R2Q1P0N9X5",
				Image: whoamiImage, Args: []string{"-port", "8080"}, Env: sealedEnv, Port: &port,
				Resources: state.Resources{CPUs: 0.5, MemoryMB: 64, Pids: 64},
				Networks:  []state.NetworkAttachment{{Network: netID, Aliases: []state.Slug{"api"}}},
				Restart:   state.RestartUnlessStopped, Role: state.RoleServing,
				PublishOnMesh: &state.MeshPublish{Port: 31001},
			}},
			Ambassadors: ambs, Routes: []state.RouteSpec{},
			Firewall: state.FirewallSpec{MeshAllow: []state.MeshAllowRule{}},
		}
		if len(allow) > 0 {
			ns.Firewall.MeshAllow = []state.MeshAllowRule{{Port: 31001, From: allow}}
		}
		if ns.Ambassadors == nil {
			ns.Ambassadors = []state.AmbassadorSpec{}
		}
		if err := ns.Validate(); err != nil {
			t.Fatal(err)
		}
		mu.Lock()
		cur = ns
		mu.Unlock()
		return ns
	}
	pass := func(ns *state.NodeState) {
		t.Helper()
		if err := rec.Reconcile(ctx, ns); err != nil {
			t.Logf("reconcile: %v", err)
		}
		if _, err := amb.Sync(ctx, ns); err != nil {
			t.Logf("ambassador: %v", err)
		}
		if err := fw.Sync(ctx); err != nil {
			t.Fatal(err)
		}
	}
	containerName := "lr-" + strings.ToLower(string(wl))

	// 1. Before the mesh is up the published workload waits.
	pass(build(1, []state.IPAddr{"100.64.0.250"}, nil))
	if cs, _ := m.engine.ListContainers(ctx, map[string]string{docker.LabelWorkload: string(wl)}); len(cs) != 0 {
		t.Fatal("workload created before the mesh address was known")
	}
	if st := status(rec, wl); st.Phase != state.PhasePending {
		t.Fatalf("waiting workload status %+v", st)
	}

	// 2. Mesh up (host + two runtime peers).
	eventuallyWithin(t, 2*time.Minute, "host mesh client connected", func() bool {
		return mgr.Sync(ctx, meshSpec).State == state.MeshConnected
	})
	hostIP := mgr.IPv4()
	// The peers' underlay: not a launchroom network (the firewall would
	// treat a managed one like an environment network).
	f := &fwEnv{t: t, api: api, engine: m.engine, server: m.serverID}
	f.network(fwNet{name: "lr-itest-mesh", subnet: netip.MustParsePrefix(meshSubnet)})
	peerA := m.startPeer(ctx, t, "peer-a", m.preAuthKey(ctx, t, "tag:lr-runtime"))
	peerB := m.startPeer(ctx, t, "peer-b", m.preAuthKey(ctx, t, "tag:lr-runtime"))

	// 3. Published on the mesh IP, allowed only from peer A.
	ns := build(2, []state.IPAddr{state.IPAddr(peerA.ip.String())}, nil)
	pass(ns)
	insp, err := api.ContainerInspect(ctx, containerName, client.ContainerInspectOptions{})
	if err != nil {
		t.Fatal(err)
	}
	for p, bs := range insp.Container.HostConfig.PortBindings {
		for _, b := range bs {
			if b.HostIP != hostIP || b.HostPort != "31001" {
				t.Fatalf("binding %s → %+v, want %s:31001 only", p, b, hostIP)
			}
		}
	}
	url := fmt.Sprintf("http://%s:31001/", hostIP)
	eventuallyWithin(t, 2*time.Minute, "allowed peer reaches the published port", func() bool { return m.fetch(ctx, t, peerA, url, "xnet-provider") })
	if m.fetch(ctx, t, peerB, url, "xnet-provider") {
		t.Fatal("a peer outside meshAllow reached the published port")
	}
	// Not published anywhere else: the host's address on the peers' bridge.
	if m.fetch(ctx, t, peerB, fmt.Sprintf("http://%s:31001/", meshGateway), "xnet-provider") {
		t.Fatal("published port reachable outside the mesh IP")
	}
	// 2026-10 review M5: a LAN neighbour (no WireGuard) that routes the
	// mesh IP via this server's underlay address must not reach the
	// published port: the gate filters by destination, not by interface.
	lanID, err := fixtureEngine(t, m.serverID).CreateContainer(ctx, &docker.ContainerCreate{
		Name:       "lr-itest-lan-neighbour",
		Config:     &container.Config{Image: busyboxImage, Cmd: []string{"sleep", "600"}, Labels: maps.Clone(docker.ManagedLabels(string(m.serverID)))},
		HostConfig: &container.HostConfig{NetworkMode: "lr-itest-mesh", CapAdd: []string{"NET_ADMIN"}},
	})
	if err != nil {
		t.Fatal(err)
	}
	if err := m.engine.StartContainer(ctx, lanID); err != nil {
		t.Fatal(err)
	}
	if out, code := m.exec(ctx, t, lanID, "ip", "route", "add", hostIP.String()+"/32", "via", meshGateway); code != 0 {
		t.Fatalf("route via the underlay: %s", out)
	}
	if out, code := m.exec(ctx, t, lanID, "wget", "-qO-", "-T", "4", url); code == 0 || strings.Contains(out, "xnet-provider") {
		t.Fatalf("a LAN neighbour reached the mesh-published port via the underlay: %q", out)
	}
	// Allow-list changes apply without touching the container.
	pass(build(3, []state.IPAddr{state.IPAddr(peerA.ip.String()), state.IPAddr(peerB.ip.String())}, nil))
	eventuallyWithin(t, time.Minute, "peer B allowed", func() bool { return m.fetch(ctx, t, peerB, url, "xnet-provider") })
	pass(build(4, []state.IPAddr{state.IPAddr(peerA.ip.String())}, nil))
	time.Sleep(time.Second)
	if m.fetch(ctx, t, peerB, url, "xnet-provider") {
		t.Fatal("peer B still allowed after it left meshAllow")
	}

	// 4. Ambassador: peer A serves "remote" on 31005 (busybox httpd over /etc, on its mesh IP); on
	// this server `remote:80` reaches it from the environment network.
	m.sidecar(ctx, t, peerA, "peer-a-httpd", "httpd", "-f", "-p", "31005", "-h", "/etc")
	remote := state.AddrPort(netip.AddrPortFrom(peerA.ip, 31005).String())
	ambs := []state.AmbassadorSpec{{Network: netID, Alias: "remote", Port: 80, Upstreams: []state.AddrPort{remote}}}
	ns = build(5, []state.IPAddr{state.IPAddr(peerA.ip.String())}, ambs)
	pass(ns)
	envFw := fwNet{name: string(envNet.Name), subnet: netip.MustParsePrefix(string(envNet.Subnet)), managed: true}
	eventuallyTrue(t, "workload reaches the remote service through the ambassador", func() bool {
		pass(ns)
		return f.reach(ctx, envFw, "http://remote/passwd")
	})
	if f.reach(ctx, envFw, "http://"+string(remote)+"/passwd") {
		t.Fatal("a workload reached the mesh directly")
	}
	cs, err := m.engine.ListContainers(ctx, map[string]string{docker.LabelSystem: policy.SystemAmbassador})
	if err != nil || len(cs) != 1 {
		t.Fatalf("ambassador container: %v %v", cs, err)
	}
	ambID := cs[0].ID
	// Alias changes reconnect the network; the container stays.
	ambs = append(ambs, state.AmbassadorSpec{Network: netID, Alias: "remote2", Port: 8081, Upstreams: []state.AddrPort{remote}})
	ns = build(6, []state.IPAddr{state.IPAddr(peerA.ip.String())}, ambs)
	eventuallyTrue(t, "second alias", func() bool {
		pass(ns)
		return f.reach(ctx, envFw, "http://remote2:8081/passwd")
	})
	if cs, _ := m.engine.ListContainers(ctx, map[string]string{docker.LabelSystem: policy.SystemAmbassador}); len(cs) != 1 || cs[0].ID != ambID {
		t.Fatal("ambassador container was replaced by a config change")
	}
	// No ambassadors left: container and network go.
	ns = build(7, []state.IPAddr{state.IPAddr(peerA.ip.String())}, nil)
	pass(ns)
	if cs, _ := m.engine.ListContainers(ctx, map[string]string{docker.LabelSystem: policy.SystemAmbassador}); len(cs) != 0 {
		t.Fatal("ambassador still running without ambassadors")
	}

	// 5. Reboot order: the workload restarts while the mesh is down.
	mcs, err := m.engine.ListContainers(ctx, map[string]string{docker.LabelSystem: policy.SystemTailscale})
	if err != nil || len(mcs) != 1 {
		t.Fatalf("mesh client: %v %v", mcs, err)
	}
	if err := m.engine.StopContainer(ctx, mcs[0].ID, 10*time.Second); err != nil {
		t.Fatal(err)
	}
	wcs, _ := m.engine.ListContainers(ctx, map[string]string{docker.LabelWorkload: string(wl)})
	if len(wcs) != 1 {
		t.Fatalf("workload containers %v", wcs)
	}
	_ = m.engine.StopContainer(ctx, wcs[0].ID, 2*time.Second)
	if err := m.engine.StartContainer(ctx, wcs[0].ID); err != nil {
		t.Logf("as expected, the published port cannot be bound without the mesh address: %v", err)
	} else {
		t.Log("Docker started the container without the mesh address (bind deferred)")
	}
	eventuallyWithin(t, 2*time.Minute, "mesh back", func() bool { return mgr.Sync(ctx, meshSpec).State == state.MeshConnected })
	// The container comes back published on the mesh IP (recreated: Docker
	// runs a container started without the address unbound, and a restart
	// does not bind it). Peers' tunnels to the restarted test client take a
	// while to recover in this harness, so the port is checked from the
	// host's own namespace (DNAT on the output path).
	want := netip.AddrPortFrom(mgr.IPv4(), 31001)
	eventuallyWithin(t, 2*time.Minute, "workload published on the mesh again", func() bool {
		pass(ns)
		cs, _ := m.engine.ListContainers(ctx, map[string]string{docker.LabelWorkload: string(wl)})
		if len(cs) != 1 || !cs[0].Running || !slices.Contains(cs[0].HostPorts, want) {
			return false
		}
		res, err := http.Get("http://" + want.String() + "/") //nolint:noctx // test
		if err != nil {
			return false
		}
		defer func() { _ = res.Body.Close() }()
		body, _ := io.ReadAll(res.Body)
		return strings.Contains(string(body), "xnet-provider")
	})

	// 6. The environment leaves this server while the ambassador is
	// attached to its network: one pass detaches it and removes the network.
	ns = build(9, []state.IPAddr{state.IPAddr(peerA.ip.String())}, []state.AmbassadorSpec{{Network: netID, Alias: "remote", Port: 80, Upstreams: []state.AddrPort{remote}}})
	pass(ns)
	gone := &state.NodeState{TypeMeta: ns.TypeMeta, ServerID: ns.ServerID, Version: 10, IssuedAt: time.Now().UTC(), System: ns.System,
		Networks: []state.NetworkSpec{}, Volumes: []state.VolumeSpec{}, Workloads: []state.WorkloadSpec{}, Ambassadors: []state.AmbassadorSpec{},
		Routes: []state.RouteSpec{}, Firewall: state.FirewallSpec{MeshAllow: []state.MeshAllowRule{}}}
	mu.Lock()
	cur = gone
	mu.Unlock()
	if err := rec.Reconcile(ctx, gone); err != nil {
		t.Fatalf("removing the environment: %v", err)
	}
	nets, err := m.engine.ListNetworks(ctx)
	if err != nil {
		t.Fatal(err)
	}
	for _, n := range nets {
		if n.Name == string(envNet.Name) {
			t.Fatal("environment network not removed (ambassador still attached?)")
		}
	}
}
