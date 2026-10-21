//go:build integration

package integration

import (
	"bytes"
	"context"
	"crypto/ed25519"
	"crypto/rand"
	"log/slog"
	"net"
	"net/netip"
	"os"
	"strconv"
	"strings"
	"sync"
	"testing"
	"time"

	"github.com/moby/moby/client"

	"github.com/vantezzen/launchroom/daemon/internal/docker"
	"github.com/vantezzen/launchroom/daemon/internal/firewall"
	"github.com/vantezzen/launchroom/daemon/internal/linkprobe"
	"github.com/vantezzen/launchroom/daemon/internal/mesh"
	"github.com/vantezzen/launchroom/daemon/internal/policy"
	"github.com/vantezzen/launchroom/daemon/internal/secret"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// TestLinkProbeMesh runs the link probe listener (docs/05 §2.1) on this
// server's real mesh IP behind real nftables, with two peers in their own
// network namespaces on a real Headscale mesh:
//
//   - a listed peer gets a signed answer to its signed hello, and a short
//     transfer over WireGuard (whose rate is logged);
//   - a peer that is not listed cannot even connect (nftables mesh-host);
//   - a listed peer presenting another peer's identity (stolen key) is
//     refused: the source address is not that peer's;
//   - a replayed hello is refused.
func TestLinkProbeMesh(t *testing.T) {
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
	log := slog.New(slog.NewTextHandler(os.Stderr, &slog.HandlerOptions{Level: slog.LevelInfo}))
	m := &meshEnv{serverID: state.ServerID(randomID("srv")), api: api}
	m.engine, err = docker.NewEngine(docker.EngineOptions{Host: engineHost, ServerID: string(m.serverID), UserAgent: "launchroom-itest"})
	if err != nil {
		t.Fatal(err)
	}
	ctx, cancel := context.WithTimeout(context.Background(), 15*time.Minute)
	defer cancel()
	for _, img := range []string{headscaleImage, string(tailscaleImage), busyboxImage} {
		pullImage(t, api, img)
	}
	startHeadscale(ctx, t, m)
	hostKey := m.preAuthKey(ctx, t, "tag:lr-runtime")
	mgr := mesh.New(mesh.Options{
		ServerID: m.serverID, Docker: m.engine, Exec: m.engine, Policy: policy.DefaultConfig(), InsecureLoginHosts: []string{"127.0.0.1"},
		OpenAuthKey: func(state.Sealed) (secret.Secret, error) { return secret.Secret(hostKey), nil },
		Log:         log,
	})
	meshSpec := state.TailscaleSpec{Enabled: true, Image: tailscaleImage, LoginServer: "http://127.0.0.1", AuthKey: &state.Sealed{Sealed: "aXRlc3Q="}}
	t.Cleanup(func() { mgr.Sync(context.WithoutCancel(ctx), state.TailscaleSpec{}) })
	eventuallyWithin(t, 2*time.Minute, "host mesh client connected", func() bool {
		return mgr.Sync(ctx, meshSpec).State == state.MeshConnected
	})
	hostIP := mgr.IPv4()

	f := &fwEnv{t: t, api: api, engine: m.engine, server: m.serverID}
	f.network(fwNet{name: "lr-itest-mesh", subnet: netip.MustParsePrefix(meshSubnet)})
	peerA := m.startPeer(ctx, t, "link-a", m.preAuthKey(ctx, t, "tag:lr-runtime"))
	peerB := m.startPeer(ctx, t, "link-b", m.preAuthKey(ctx, t, "tag:lr-runtime"))
	// Sidecars that stay up, so probes can be sent from each peer's namespace.
	m.sidecar(ctx, t, peerA, "link-a-shell", "sleep", "900")
	m.sidecar(ctx, t, peerB, "link-b-shell", "sleep", "900")

	newKey := func() (ed25519.PublicKey, ed25519.PrivateKey) {
		pub, priv, err := ed25519.GenerateKey(rand.Reader)
		if err != nil {
			t.Fatal(err)
		}
		return pub, priv
	}
	hostPub, hostPriv := newKey()
	aPub, aPriv := newKey()
	bPub, _ := newKey()
	idA, idB := randomID("srv"), randomID("srv")

	// The listed peers feed both the server and the firewall, like the runtime.
	var mu sync.Mutex
	from := []netip.Addr{peerA.ip}
	fw := firewall.New(firewall.Options{
		Backend: firewall.NewBackend(), Networks: m.engine.ListNetworks, MeshInterface: mesh.Interface, Log: log,
		Mesh: func() firewall.MeshRules {
			mu.Lock()
			defer mu.Unlock()
			return firewall.MeshRules{MeshIP: hostIP, LinkProbePort: state.LinkProbeMeshPort, LinkProbeFrom: append([]netip.Addr(nil), from...)}
		},
	})
	t.Cleanup(func() { _ = fw.Remove() })
	if err := fw.Sync(ctx); err != nil {
		t.Fatal(err)
	}
	srv := linkprobe.NewServer(linkprobe.ServerOptions{Self: string(m.serverID), Sign: func(msg []byte) []byte { return ed25519.Sign(hostPriv, msg) }, Log: log})
	srv.SetPeers([]linkprobe.Peer{{ID: idA, IP: peerA.ip, Key: aPub}, {ID: idB, IP: peerB.ip, Key: bPub}})
	var lc net.ListenConfig
	l, err := lc.Listen(ctx, "tcp", netip.AddrPortFrom(hostIP, state.LinkProbeMeshPort).String())
	if err != nil {
		t.Fatal(err)
	}
	go func() { _ = srv.Serve(ctx, l) }()

	signA := func(msg []byte) []byte { return ed25519.Sign(aPriv, msg) }
	target := hostIP.String()
	// probe sends one hello line from a peer's namespace (nc, the line on
	// its stdin; argv only) and returns everything the server answered.
	probe := func(sidecar, line string) (string, int) {
		var out bytes.Buffer
		res, err := m.engine.ExecStream(ctx, "lr-itest-"+sidecar, docker.ExecOptions{
			Argv:  []string{"nc", "-w", "4", target, strconv.Itoa(state.LinkProbeMeshPort)},
			Stdin: strings.NewReader(line), Stdout: &out,
		})
		if err != nil {
			t.Fatalf("exec nc: %v", err)
		}
		return out.String(), res.ExitCode
	}

	// 1. A listed peer gets a signed answer.
	var ok bool
	eventuallyWithin(t, time.Minute, "listed peer answered", func() bool {
		line, nonce, err := linkprobe.HelloLine(linkprobe.OpPing, idA, string(m.serverID), 0, time.Now(), signA)
		if err != nil {
			t.Fatal(err)
		}
		out, _ := probe("link-a-shell", string(line))
		first, _, _ := strings.Cut(out, "\n")
		if err := linkprobe.CheckAck([]byte(first), string(m.serverID), idA, nonce, hostPub); err != nil {
			t.Logf("answer %q: %v", out, err)
			return false
		}
		ok = true
		return true
	})
	if !ok {
		t.Fatal("no answer")
	}

	// 2. A real transfer over WireGuard.
	const size = 4 << 20
	line, _, err := linkprobe.HelloLine(linkprobe.OpTransfer, idA, string(m.serverID), size, time.Now(), signA)
	if err != nil {
		t.Fatal(err)
	}
	start := time.Now()
	out, code := probe("link-a-shell", string(line))
	elapsed := time.Since(start)
	_, data, _ := strings.Cut(out, "\n")
	n := len(data)
	if n < size {
		t.Fatalf("transfer: %d bytes (exit %d)", n, code)
	}
	t.Logf("transfer over the mesh: %d bytes in %s (≈ %.0f Mbit/s, including nc start-up and exec overhead)", n, elapsed, float64(n)*8/elapsed.Seconds()/1e6)

	// 3. Peer B is not in the firewall's list: no connection at all.
	lineB, _, _ := linkprobe.HelloLine(linkprobe.OpPing, idB, string(m.serverID), 0, time.Now(), func(msg []byte) []byte { return make([]byte, 64) })
	if out, _ := probe("link-b-shell", string(lineB)); strings.Contains(out, `"v"`) {
		t.Fatalf("an unlisted peer got an answer: %q", out)
	}

	// 4. Peer B allowed by the firewall, but presenting A's identity and a valid A signature: refused (source address).
	mu.Lock()
	from = []netip.Addr{peerA.ip, peerB.ip}
	mu.Unlock()
	if err := fw.Sync(ctx); err != nil {
		t.Fatal(err)
	}
	stolen, _, _ := linkprobe.HelloLine(linkprobe.OpPing, idA, string(m.serverID), 0, time.Now(), signA)
	if out, _ := probe("link-b-shell", string(stolen)); strings.Contains(out, `"ok":true`) {
		t.Fatalf("a peer with a stolen identity got an answer: %q", out)
	}

	// 5. A replayed hello from the right peer is refused.
	replay, nonce, _ := linkprobe.HelloLine(linkprobe.OpPing, idA, string(m.serverID), 0, time.Now(), signA)
	first, _ := probe("link-a-shell", string(replay))
	if err := linkprobe.CheckAck([]byte(strings.SplitN(first, "\n", 2)[0]), string(m.serverID), idA, nonce, hostPub); err != nil {
		t.Fatalf("first use refused: %q %v", first, err)
	}
	if again, _ := probe("link-a-shell", string(replay)); strings.Contains(again, `"ok":true`) {
		t.Fatalf("replayed hello answered: %q", again)
	}
}
