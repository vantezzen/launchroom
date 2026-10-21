//go:build integration

package integration

import (
	"context"
	"net"
	"net/http"
	"net/netip"
	"os/exec"
	"strconv"
	"testing"
	"time"

	"github.com/moby/moby/api/types/container"
	"github.com/moby/moby/api/types/network"
	"github.com/moby/moby/client"

	"github.com/vantezzen/launchroom/daemon/internal/docker"
	"github.com/vantezzen/launchroom/daemon/internal/firewall"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// busyboxImage provides wget and httpd for the reachability probes.
const busyboxImage = "docker.io/library/busybox@sha256:bdf57e528e45e4433820e045b29b4597825a1c9e38353532d90a01445013f82e"

// fwNet is one test network.
type fwNet struct {
	name    string
	subnet  netip.Prefix
	system  bool // labelled like lr-system-build
	managed bool
}

func (n fwNet) gateway() netip.Addr { return n.subnet.Addr().Next() }

func (n fwNet) host(i int) netip.Addr {
	a := n.subnet.Addr()
	for range i {
		a = a.Next()
	}
	return a
}

type fwEnv struct {
	t      *testing.T
	api    *client.Client
	engine *docker.Engine
	server state.ServerID
}

func (f *fwEnv) network(n fwNet) {
	t, ctx := f.t, f.t.Context()
	if n.managed {
		labels := docker.ManagedLabels(string(f.server))
		if n.system {
			labels[docker.LabelSystem] = "buildkit"
			labels[docker.LabelNetwork] = "system-build"
		} else {
			labels[docker.LabelNetwork] = randomID("net")
		}
		if _, err := f.engine.CreateNetwork(ctx, docker.NetworkCreate{Name: n.name, Subnet: n.subnet, Labels: labels}); err != nil {
			t.Fatal(err)
		}
		return
	}
	// A network launchroom does not manage (metadata stand-in, other Docker
	// users); a leftover of an aborted run is removed first.
	if old, err := f.api.NetworkInspect(ctx, n.name, client.NetworkInspectOptions{}); err == nil {
		for id := range old.Network.Containers {
			_, _ = f.api.ContainerRemove(ctx, id, client.ContainerRemoveOptions{Force: true})
		}
		_, _ = f.api.NetworkRemove(ctx, n.name, client.NetworkRemoveOptions{})
	}
	res, err := f.api.NetworkCreate(ctx, n.name, client.NetworkCreateOptions{
		Driver: "bridge", IPAM: &network.IPAM{Driver: "default", Config: []network.IPAMConfig{{Subnet: n.subnet}}},
		Labels: map[string]string{"launchroom.dev": "true"},
	})
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { _, _ = f.api.NetworkRemove(context.WithoutCancel(ctx), res.ID, client.NetworkRemoveOptions{}) })
}

// run starts a busybox container on network n (optionally at a fixed IP).
func (f *fwEnv) run(ctx context.Context, n fwNet, ip netip.Addr, cmd ...string) string {
	t := f.t
	ep := &network.EndpointSettings{}
	if ip.IsValid() {
		ep.IPAMConfig = &network.EndpointIPAMConfig{IPv4Address: ip}
	}
	labels := map[string]string{"launchroom.dev": "true"}
	if n.managed {
		labels = docker.ManagedLabels(string(f.server)) // swept with the managed objects
	}
	cr, err := f.api.ContainerCreate(ctx, client.ContainerCreateOptions{
		Config:           &container.Config{Image: busyboxImage, Cmd: cmd, Labels: labels},
		HostConfig:       &container.HostConfig{NetworkMode: container.NetworkMode(n.name)},
		NetworkingConfig: &network.NetworkingConfig{EndpointsConfig: map[string]*network.EndpointSettings{n.name: ep}},
	})
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() {
		_, _ = f.api.ContainerRemove(context.WithoutCancel(ctx), cr.ID, client.ContainerRemoveOptions{Force: true})
	})
	if _, err := f.api.ContainerStart(ctx, cr.ID, client.ContainerStartOptions{}); err != nil {
		t.Fatal(err)
	}
	return cr.ID
}

// reach reports whether a container on network n can fetch url (HTTP).
func (f *fwEnv) reach(ctx context.Context, n fwNet, url string) bool {
	t := f.t
	t.Helper()
	id := f.run(ctx, n, netip.Addr{}, "wget", "-q", "-T", "4", "-O", "/dev/null", url)
	deadline := time.Now().Add(30 * time.Second)
	for time.Now().Before(deadline) {
		insp, err := f.api.ContainerInspect(ctx, id, client.ContainerInspectOptions{})
		if err != nil {
			t.Fatal(err)
		}
		if st := insp.Container.State; st != nil && !st.Running && st.Status != container.StateCreated {
			return st.ExitCode == 0
		}
		time.Sleep(200 * time.Millisecond)
	}
	t.Fatalf("probe for %s did not finish", url)
	return false
}

// serve answers HTTP on addr in this (the "host") network namespace.
func serve(t *testing.T, addr string) bool {
	t.Helper()
	l, err := (&net.ListenConfig{}).Listen(t.Context(), "tcp", addr)
	if err != nil {
		t.Logf("cannot listen on %s: %v", addr, err)
		return false
	}
	srv := &http.Server{Handler: http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) { _, _ = w.Write([]byte("ok")) }), ReadHeaderTimeout: 5 * time.Second}
	go func() { _ = srv.Serve(l) }()
	t.Cleanup(func() { _ = srv.Close() })
	return true
}

func eventuallyTrue(t *testing.T, what string, f func() bool) {
	t.Helper()
	deadline := time.Now().Add(20 * time.Second)
	for time.Now().Before(deadline) {
		if f() {
			return
		}
		time.Sleep(500 * time.Millisecond)
	}
	t.Fatalf("timed out waiting for %s", what)
}

// TestFirewallIsolation covers H2 of the 2026-10 security review in a real
// kernel (the dind-test daemon's network namespace, like the daemon with
// network_mode: host): workloads and builds cannot reach cloud metadata,
// other environments or the host's admin ports, but reach their own
// network, DNS, the internet and the public ingress; the host (Caddy, health
// probes) still reaches containers; drift is repaired.
func TestFirewallIsolation(t *testing.T) {
	api, err := client.New(client.FromEnv)
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { _ = api.Close() })
	sweep(t, api)
	t.Cleanup(func() { sweep(t, api) })
	f := &fwEnv{t: t, api: api, server: state.ServerID(randomID("srv"))}
	f.engine, err = docker.NewEngine(docker.EngineOptions{Host: engineHost, ServerID: string(f.server), UserAgent: "launchroom-itest"})
	if err != nil {
		t.Fatal(err)
	}
	ctx := t.Context()
	pullImage(t, api, busyboxImage)

	backend := firewall.NewBackend()
	_ = backend.Remove()
	t.Cleanup(func() { _ = backend.Remove() })

	envA := fwNet{name: "lr-e_itest_fwa", subnet: netip.MustParsePrefix("10.231.250.0/24"), managed: true}
	envB := fwNet{name: "lr-e_itest_fwb", subnet: netip.MustParsePrefix("10.231.251.0/24"), managed: true}
	build := fwNet{name: "lr-system-build", subnet: netip.MustParsePrefix("10.232.0.0/24"), managed: true, system: true}
	meta := fwNet{name: "lr-itest-metadata", subnet: netip.MustParsePrefix("169.254.169.0/24")}
	other := fwNet{name: "lr-itest-other", subnet: netip.MustParsePrefix("172.29.250.0/24")}
	for _, n := range []fwNet{envA, envB, build, meta, other} {
		f.network(n)
	}

	// Stand-ins: cloud metadata (off-host, like the real one), a service on
	// another Docker network (e.g. the control plane's lr-system), a service
	// in another environment and one in the same environment.
	metadataIP := netip.MustParseAddr("169.254.169.254")
	f.run(ctx, meta, metadataIP, "httpd", "-f", "-h", "/etc", "-p", "80")
	otherIP := other.host(10)
	f.run(ctx, other, otherIP, "httpd", "-f", "-h", "/etc", "-p", "8080")
	peerB := envB.host(10)
	f.run(ctx, envB, peerB, "httpd", "-f", "-h", "/etc", "-p", "8080")
	peerA := envA.host(10)
	f.run(ctx, envA, peerA, "httpd", "-f", "-h", "/etc", "-p", "8080")

	// Host listeners: an admin/daemon port, the registry forwarder port and
	// the public ingress.
	adminPort := uint16(12019)
	if !serve(t, "0.0.0.0:"+strconv.Itoa(int(adminPort))) {
		t.Fatal("cannot listen on the admin stand-in port")
	}
	if !serve(t, "0.0.0.0:5000") {
		t.Fatal("cannot listen on the registry stand-in port")
	}
	// Port 80 on specific addresses, so the local metadata stand-in below can
	// use port 80 too.
	ingress := serve(t, netip.AddrPortFrom(envA.gateway(), 80).String())

	url := func(a netip.Addr, port uint16) string {
		return "http://" + netip.AddrPortFrom(a, port).String() + "/hostname"
	}
	metadataURL := url(metadataIP, 80)

	// A metadata endpoint that is a local address of the host (a metadata
	// proxy, or how some clouds answer 169.254.169.254): a dummy interface.
	localMeta := netip.MustParseAddr("169.254.170.2")
	dummyInterface(t, "lritestmd0", localMeta)
	if !serve(t, netip.AddrPortFrom(localMeta, 80).String()) {
		t.Fatal("cannot listen on the local metadata stand-in")
	}
	localMetaURL := url(localMeta, 80)

	// Without the firewall these are reachable (the test can see a block).
	// The bridge-hosted metadata stand-in and other environments are already
	// cut off by Docker's own inter-bridge isolation in dind, so they have no
	// sanity check; the egress chain blocks them by destination as well.
	eventuallyTrue(t, "httpd stand-ins", func() bool { return f.reach(ctx, envA, url(peerA, 8080)) })
	if !f.reach(ctx, envA, localMetaURL) || !f.reach(ctx, envA, url(envA.gateway(), adminPort)) {
		t.Fatal("sanity: local metadata and host admin port must be reachable before the firewall is applied")
	}

	m := firewall.New(firewall.Options{
		Backend: backend, Networks: f.engine.ListNetworks,
		BuildNetwork: build.name, BuildGateway: build.gateway(), RegistryPort: 5000,
	})
	if err := m.Sync(ctx); err != nil {
		t.Fatal(err)
	}
	if st := m.Status(); st.Mode != state.FirewallEnforced || st.Networks != 3 {
		t.Fatalf("status %+v", st)
	}

	check := func(phase string) {
		t.Helper()
		cases := []struct {
			name string
			from fwNet
			url  string
			want bool
		}{
			{"workload → cloud metadata", envA, metadataURL, false},
			{"workload → local metadata address", envA, localMetaURL, false},
			{"build → local metadata address", build, localMetaURL, false},
			{"build → cloud metadata", build, metadataURL, false},
			{"workload → other environment", envA, url(peerB, 8080), false},
			{"workload → other Docker network", envA, url(otherIP, 8080), false},
			{"workload → host admin port (gateway)", envA, url(envA.gateway(), adminPort), false},
			{"workload → host admin port (other bridge address)", envA, url(envB.gateway(), adminPort), false},
			{"workload → registry port", envA, url(envA.gateway(), 5000), false},
			{"build → host admin port", build, url(build.gateway(), adminPort), false},
			{"build → registry forwarder", build, url(build.gateway(), 5000), true},
			{"workload → same environment", envA, url(peerA, 8080), true},
			{"workload → internet (DNS + HTTP)", envA, "http://detectportal.firefox.com/success.txt", true},
			{"build → internet (DNS + HTTP)", build, "http://detectportal.firefox.com/success.txt", true},
		}
		if ingress {
			cases = append(cases, struct {
				name string
				from fwNet
				url  string
				want bool
			}{"workload → public ingress on the host (hairpin)", envA, url(envA.gateway(), 80), true})
		}
		for _, c := range cases {
			if got := f.reach(ctx, c.from, c.url); got != c.want {
				t.Errorf("%s: %s reachable=%v, want %v", phase, c.name, got, c.want)
			}
		}
		// Host → container (Caddy upstreams, health probes).
		hc := &http.Client{Timeout: 5 * time.Second}
		req, _ := http.NewRequestWithContext(ctx, http.MethodGet, url(peerA, 8080), nil)
		res, err := hc.Do(req)
		if err != nil {
			t.Errorf("%s: host → container: %v", phase, err)
		} else {
			_ = res.Body.Close()
		}
	}
	check("applied")

	// Drift: something deletes our table; the next sync puts it back.
	if err := backend.Remove(); err != nil {
		t.Fatal(err)
	}
	if !f.reach(ctx, envA, localMetaURL) {
		t.Fatal("sanity: removing the table must reopen metadata")
	}
	if err := m.Sync(ctx); err != nil {
		t.Fatal(err)
	}
	if st := m.Status(); st.Repairs != 1 {
		t.Fatalf("drift not counted: %+v", st)
	}
	check("after drift repair")

	// A new environment network is covered on the next sync.
	envC := fwNet{name: "lr-e_itest_fwc", subnet: netip.MustParsePrefix("10.231.252.0/24"), managed: true}
	f.network(envC)
	if err := m.Sync(ctx); err != nil {
		t.Fatal(err)
	}
	if f.reach(ctx, envC, localMetaURL) || f.reach(ctx, envC, url(peerA, 8080)) {
		t.Fatal("new network not isolated")
	}
}

// dummyInterface adds a dummy interface with addr in the test's (host)
// network namespace, removed afterwards. Test-only, argv without a shell.
func dummyInterface(t *testing.T, name string, addr netip.Addr) {
	t.Helper()
	ip := func(args ...string) {
		t.Helper()
		out, err := exec.CommandContext(context.WithoutCancel(t.Context()), "ip", args...).CombinedOutput() //nolint:forbidigo,gosec // test fixture: fixed argv, no shell
		if err != nil {
			t.Fatalf("ip %v: %v: %s", args, err, out)
		}
	}
	ip("link", "add", name, "type", "dummy")
	t.Cleanup(func() { ip("link", "del", name) })
	ip("addr", "add", netip.PrefixFrom(addr, addr.BitLen()).String(), "dev", name)
	ip("link", "set", name, "up")
}

func pullImage(t *testing.T, api *client.Client, ref string) {
	t.Helper()
	res, err := api.ImagePull(t.Context(), ref, client.ImagePullOptions{})
	if err != nil {
		t.Fatal(err)
	}
	defer func() { _ = res.Close() }()
	if err := res.Wait(t.Context()); err != nil {
		t.Fatal(err)
	}
}

// TestFirewallLargeRuleset: a table of several hundred rules (many
// environment networks and mesh allowances) applies in one batch and checks
// clean. Before NETLINK_CAP_ACK the kernel's acks echoed every request and
// overflowed the default netlink receive buffer (ENOBUFS), which held back
// every workload on the server (found by the failover test).
func TestFirewallLargeRuleset(t *testing.T) {
	backend := firewall.NewBackend()
	_ = backend.Remove()
	t.Cleanup(func() { _ = backend.Remove() })

	spec := firewall.Spec{
		MeshInterface: "tailscale0",
		MeshIP:        netip.MustParseAddr("100.64.0.1"),
		RegistryPort:  5000,
	}
	for i := range 250 {
		spec.Bridges = append(spec.Bridges, "lrbig"+strconv.Itoa(i))
	}
	for i := range 200 {
		spec.MeshAllow = append(spec.MeshAllow, firewall.MeshAllow{
			Port: uint16(31000 + i),
			From: []netip.Addr{netip.MustParseAddr("100.64.0.2"), netip.MustParseAddr("100.64.0.3")},
		})
	}
	rs := firewall.Build(spec)
	rules := 0
	for _, ch := range rs.Chains {
		rules += len(ch.Rules)
	}
	if rules < 500 {
		t.Fatalf("ruleset has only %d rules; the test needs a large one", rules)
	}
	if err := backend.Apply(rs); err != nil {
		t.Fatalf("apply %d rules: %v", rules, err)
	}
	drift, err := backend.Check(rs)
	if err != nil || len(drift) != 0 {
		t.Fatalf("check: drift %v, err %v", drift, err)
	}
}
