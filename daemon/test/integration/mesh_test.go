//go:build integration

package integration

import (
	"archive/tar"
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"io"
	"log/slog"
	"maps"
	"net"
	"net/http"
	"net/netip"
	"os"
	"strings"
	"testing"
	"time"

	"github.com/moby/moby/api/types/container"
	"github.com/moby/moby/client"

	"github.com/vantezzen/launchroom/daemon/internal/docker"
	"github.com/vantezzen/launchroom/daemon/internal/mesh"
	"github.com/vantezzen/launchroom/daemon/internal/policy"
	"github.com/vantezzen/launchroom/daemon/internal/secret"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// Pinned like deploy/control-plane/images.env and policy.TailscaleImageDigest.
const (
	headscaleImage = "docker.io/headscale/headscale@sha256:780ed2f9e85719a2211277433f2c8afb45105d0d25031949a6848a485f9e216d"
	tailscaleImage = state.ImageRef("docker.io/tailscale/tailscale@" + policy.TailscaleImageDigest)
	meshSubnet     = "10.250.7.0/24"
	meshGateway    = "10.250.7.1"
)

// headscaleConfig is the dev config (dev/headscale/config.yaml) for a
// Headscale on the test daemon's host network.
const headscaleConfig = `server_url: http://127.0.0.1
listen_addr: 0.0.0.0:80
metrics_listen_addr: 127.0.0.1:9090
noise:
  private_key_path: /var/lib/headscale/noise_private.key
prefixes:
  v4: 100.64.0.0/10
  v6: fd7a:115c:a1e0::/48
  allocation: sequential
derp:
  server:
    enabled: true
    region_id: 999
    region_code: lr
    region_name: launchroom
    verify_clients: true
    stun_listen_addr: 0.0.0.0:3478
    private_key_path: /var/lib/headscale/derp_server_private.key
    automatically_add_embedded_derp_region: true
  urls: []
  paths: []
  auto_update_enabled: false
disable_check_updates: true
database:
  type: sqlite
  sqlite:
    path: /var/lib/headscale/db.sqlite
policy:
  mode: database
dns:
  magic_dns: false
  override_local_dns: false
  nameservers:
    global: []
unix_socket: /var/run/headscale/headscale.sock
unix_socket_permission: "0770"
`

// meshPolicy is what the dashboard renders (App\Services\Mesh\MeshPolicy).
const meshPolicy = `{"tagOwners":{"tag:lr-build":["launchroom@"],"tag:lr-cp":["launchroom@"],"tag:lr-edge":["launchroom@"],"tag:lr-registry":["launchroom@"],"tag:lr-runtime":["launchroom@"]},
"acls":[{"action":"accept","src":["tag:lr-build","tag:lr-cp","tag:lr-edge","tag:lr-registry","tag:lr-runtime"],"dst":["tag:lr-cp:443,5000"]},
{"action":"accept","src":["tag:lr-edge"],"dst":["tag:lr-runtime:31000-32999"]},
{"action":"accept","src":["tag:lr-runtime"],"dst":["tag:lr-runtime:31000-32999"]},
{"action":"accept","src":["tag:lr-build","tag:lr-cp","tag:lr-edge","tag:lr-registry","tag:lr-runtime"],"dst":["tag:lr-build:7400","tag:lr-cp:7400","tag:lr-edge:7400","tag:lr-registry:7400","tag:lr-runtime:7400"]}]}`

func tarWithDirs(files map[string][]byte) io.Reader {
	var buf bytes.Buffer
	tw := tar.NewWriter(&buf)
	seen := map[string]bool{}
	for name, data := range files {
		parts := strings.Split(name, "/")
		for i := 1; i < len(parts); i++ {
			dir := strings.Join(parts[:i], "/") + "/"
			if !seen[dir] {
				seen[dir] = true
				_ = tw.WriteHeader(&tar.Header{Typeflag: tar.TypeDir, Name: dir, Mode: 0o755, ModTime: time.Unix(0, 0)})
			}
		}
		_ = tw.WriteHeader(&tar.Header{Name: name, Mode: 0o644, Size: int64(len(data)), ModTime: time.Unix(0, 0)})
		_, _ = tw.Write(data)
	}
	_ = tw.Close()
	return &buf
}

func eventuallyWithin(t *testing.T, timeout time.Duration, what string, f func() bool) {
	t.Helper()
	deadline := time.Now().Add(timeout)
	for time.Now().Before(deadline) {
		if f() {
			return
		}
		time.Sleep(time.Second)
	}
	t.Fatalf("timed out waiting for %s", what)
}

type meshEnv struct {
	serverID  state.ServerID
	api       *client.Client
	engine    *docker.Engine
	headscale string
}

func (m *meshEnv) exec(ctx context.Context, t *testing.T, id string, argv ...string) (string, int) {
	t.Helper()
	var out bytes.Buffer
	res, err := m.engine.ExecStream(ctx, id, docker.ExecOptions{Argv: argv, Stdout: &out})
	if err != nil {
		t.Fatalf("exec %v: %v", argv, err)
	}
	return out.String() + res.Stderr, res.ExitCode
}

func (m *meshEnv) preAuthKey(ctx context.Context, t *testing.T, tags string) string {
	t.Helper()
	out, code := m.exec(ctx, t, m.headscale, "headscale", "preauthkeys", "create", "-u", "1", "--tags", tags, "-e", "1h", "-o", "json")
	if code != 0 {
		t.Fatalf("preauthkeys create: %s", out)
	}
	var k struct {
		Key string `json:"key"`
	}
	if err := json.Unmarshal([]byte(out[strings.Index(out, "{"):]), &k); err != nil || k.Key == "" {
		t.Fatalf("preauthkeys output: %q (%v)", out, err)
	}
	return k.Key
}

func startHeadscale(ctx context.Context, t *testing.T, m *meshEnv) {
	t.Helper()
	labels := maps.Clone(docker.ManagedLabels(string(m.serverID)))
	id, err := fixtureEngine(t, m.serverID).CreateContainer(ctx, &docker.ContainerCreate{
		Name:       "lr-itest-headscale",
		Config:     &container.Config{Image: headscaleImage, Cmd: []string{"serve"}, Labels: labels},
		HostConfig: &container.HostConfig{NetworkMode: "host"},
	})
	if err != nil {
		t.Fatal(err)
	}
	if err := m.engine.CopyToContainer(ctx, id, "/", tarWithDirs(map[string][]byte{
		"etc/headscale/config.yaml": []byte(headscaleConfig),
		"etc/headscale/policy.json": []byte(meshPolicy),
	})); err != nil {
		t.Fatal(err)
	}
	if err := m.engine.StartContainer(ctx, id); err != nil {
		t.Fatal(err)
	}
	m.headscale = id
	eventuallyWithin(t, 2*time.Minute, "headscale healthy", func() bool {
		_, code := m.exec(ctx, t, id, "headscale", "health")
		return code == 0
	})
	for _, argv := range [][]string{
		{"headscale", "users", "create", "launchroom"},
		{"headscale", "policy", "set", "-f", "/etc/headscale/policy.json"},
	} {
		if out, code := m.exec(ctx, t, id, argv...); code != 0 {
			t.Fatalf("%v: %s", argv, out)
		}
	}
}

// TestMeshJoinAndACL runs the mesh client the way launchroomd does
// (internal/mesh, kernel mode, key on stdin) against a real Headscale with
// the dashboard's ACL policy, plus a second, runtime-tagged peer in its own
// network namespace: the client joins and reports its address and peer, the
// peer reaches the control plane's registry port over the mesh, and the
// ACL drops any other port (docs/05 §2).
func TestMeshJoinAndACL(t *testing.T) {
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

	m := &meshEnv{serverID: state.ServerID(randomID("srv")), api: api}
	m.engine, err = docker.NewEngine(docker.EngineOptions{Host: engineHost, ServerID: string(m.serverID), UserAgent: "launchroom-itest"})
	if err != nil {
		t.Fatal(err)
	}
	ctx, cancel := context.WithTimeout(context.Background(), 10*time.Minute)
	defer cancel()
	pullImage(t, api, headscaleImage)
	startHeadscale(ctx, t, m)
	cpKey := m.preAuthKey(ctx, t, "tag:lr-cp,tag:lr-edge")
	peerKey := m.preAuthKey(ctx, t, "tag:lr-runtime")

	pullImage(t, api, string(tailscaleImage))
	mgr := mesh.New(mesh.Options{
		ServerID: m.serverID, Docker: m.engine, Exec: m.engine, Policy: policy.DefaultConfig(), InsecureLoginHosts: []string{"127.0.0.1"},
		OpenAuthKey: func(s state.Sealed) (secret.Secret, error) {
			if s.Sealed != "aXRlc3Q=" {
				return "", fmt.Errorf("unexpected box")
			}
			return secret.Secret(cpKey), nil
		},
		Log: slog.New(slog.NewTextHandler(os.Stderr, &slog.HandlerOptions{Level: slog.LevelInfo})),
	})
	spec := state.TailscaleSpec{Enabled: true, Image: tailscaleImage, LoginServer: "http://127.0.0.1", AuthKey: &state.Sealed{Sealed: "aXRlc3Q="}}
	t.Cleanup(func() {
		if st := mgr.Sync(context.WithoutCancel(ctx), state.TailscaleSpec{}); st != nil {
			t.Errorf("disabled mesh still reports %+v", st)
		}
	})

	var st *state.MeshStatus
	eventuallyWithin(t, 2*time.Minute, "mesh client connected", func() bool {
		st = mgr.Sync(ctx, spec)
		return st.State == state.MeshConnected
	})
	ip := mgr.IPv4()
	if !netip.MustParsePrefix("100.64.0.0/10").Contains(ip) {
		t.Fatalf("mesh IP %s not in the CGNAT range (status %+v)", ip, st)
	}
	t.Logf("control-plane node joined as %s", ip)

	// The registry port (allowed) and another port (denied) on the mesh IP.
	for _, port := range []uint16{5000, 2222} {
		var lc net.ListenConfig
		l, err := lc.Listen(ctx, "tcp", netip.AddrPortFrom(ip, port).String())
		if err != nil {
			t.Fatal(err)
		}
		srv := &http.Server{Handler: http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) { _, _ = io.WriteString(w, "mesh-ok") }), ReadHeaderTimeout: 5 * time.Second}
		go func() { _ = srv.Serve(l) }()
		t.Cleanup(func() { _ = srv.Close() })
	}

	// A runtime peer in its own network namespace with its own tailscale0.
	labels := maps.Clone(docker.ManagedLabels(string(m.serverID)))
	labels[docker.LabelNetwork] = "itest-mesh"
	// Other tests (links, xnet) use the same name; a leftover is removed first.
	if old, err := m.api.NetworkInspect(ctx, "lr-itest-mesh", client.NetworkInspectOptions{}); err == nil {
		for id := range old.Network.Containers {
			_, _ = m.api.ContainerRemove(ctx, id, client.ContainerRemoveOptions{Force: true})
		}
		_, _ = m.api.NetworkRemove(ctx, "lr-itest-mesh", client.NetworkRemoveOptions{})
	}
	if _, err := m.engine.CreateNetwork(ctx, docker.NetworkCreate{Name: "lr-itest-mesh", Subnet: netip.MustParsePrefix(meshSubnet), Labels: labels}); err != nil {
		t.Fatal(err)
	}
	peer, err := fixtureEngine(t, m.serverID).CreateContainer(ctx, &docker.ContainerCreate{
		Name: "lr-itest-mesh-peer",
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
	if err := m.engine.StartContainer(ctx, peer); err != nil {
		t.Fatal(err)
	}
	eventuallyWithin(t, 2*time.Minute, "peer logged in", func() bool {
		_, code := m.exec(ctx, t, peer, "tailscale", "--socket=/var/run/tailscale/tailscaled.sock", "up", "--reset",
			"--login-server=http://"+meshGateway, "--auth-key="+peerKey, "--hostname=lr-itest-peer", "--accept-dns=false", "--timeout=20s")
		return code == 0
	})

	// Allowed: tag:lr-runtime → tag:lr-cp:5000.
	var body string
	eventuallyWithin(t, 2*time.Minute, "peer reaches the registry port over the mesh", func() bool {
		var code int
		body, code = m.exec(ctx, t, peer, "wget", "-qO-", "-T", "5", fmt.Sprintf("http://%s:5000/", ip))
		return code == 0 && strings.Contains(body, "mesh-ok")
	})
	// Denied by the ACL: any other port, although something listens there.
	if out, code := m.exec(ctx, t, peer, "wget", "-qO-", "-T", "5", fmt.Sprintf("http://%s:2222/", ip)); code == 0 {
		t.Fatalf("ACL let the peer reach port 2222: %s", out)
	}

	// The control-plane node reports the peer.
	eventuallyWithin(t, 2*time.Minute, "peer in the mesh status", func() bool {
		st = mgr.Sync(ctx, spec)
		for _, p := range st.Peers {
			if p.Online && p.Connection == state.MeshDirect {
				return true
			}
		}
		return false
	})
	t.Logf("mesh status: %+v", st.Peers)

	// A restarted client keeps its identity (state volume) and needs no key.
	cs, err := m.engine.ListContainers(ctx, map[string]string{docker.LabelSystem: policy.SystemTailscale})
	if err != nil || len(cs) != 1 || cs[0].Name != mesh.ContainerName {
		t.Fatalf("mesh client container: %v %v", cs, err)
	}
	if err := m.engine.StopContainer(ctx, cs[0].ID, 10*time.Second); err != nil {
		t.Fatal(err)
	}
	if err := m.engine.StartContainer(ctx, cs[0].ID); err != nil {
		t.Fatal(err)
	}
	noKey := spec
	noKey.AuthKey = nil
	eventuallyWithin(t, 2*time.Minute, "reconnected after restart without a key", func() bool {
		st = mgr.Sync(ctx, noKey)
		return st.State == state.MeshConnected && mgr.IPv4() == ip
	})
}
