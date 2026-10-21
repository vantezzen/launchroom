//go:build integration

package integration

import (
	"archive/tar"
	"bytes"
	"context"
	"crypto/tls"
	"crypto/x509"
	"errors"
	"fmt"
	"io"
	"log/slog"
	"net/http"
	"net/netip"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"

	"github.com/moby/moby/api/types/container"
	"github.com/moby/moby/client"

	"github.com/vantezzen/launchroom/daemon/internal/docker"
	"github.com/vantezzen/launchroom/daemon/internal/ingress/caddy"
	"github.com/vantezzen/launchroom/daemon/internal/ingress/certstore"
	"github.com/vantezzen/launchroom/daemon/internal/policy"
	"github.com/vantezzen/launchroom/daemon/internal/secret"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// Edge test images: launchroom's Caddy build (`make caddy-image`, loaded into
// dind-test by `make test-daemon-integration`), Valkey as shared storage and
// Pebble + pebble-challtestsrv as ACME CA and DNS (all digest-pinned).
const (
	localCaddyImage   = "launchroom-dev-caddy:dev"
	valkeyImage       = "docker.io/valkey/valkey@sha256:081c2f5cb575efc901aa80ff9cdbd1ec6a301682fd35e1ebb4b0990a4a4a8507"                 // 8-alpine
	pebbleImage       = "ghcr.io/letsencrypt/pebble@sha256:ddf230642b1a584f519f32e347de1b05a6e4c1f6c35c1863b33effeab5f78199"              // 2.10.1
	challtestsrvImage = "ghcr.io/letsencrypt/pebble-challtestsrv@sha256:12ce21884def456bcf9786542113949e1f19dc7738d2c70e156c2d0c38a1405b" // 2.10.1

	storageAddr = "127.0.0.1:6379"
	storagePass = "itest-valkey-password"
	storageKey  = "itest-encryption-key-0123456789abcdef"
	// certStorageACL is the dashboard's ACL SETUSER rule set for lr-caddy
	// (App\Services\Ingress\CertStorage), after "on >password".
	certStorageACL = "~lr-caddy ~lr-caddy/* resetchannels -@all +hello +auth +client|setinfo +ping +get +set +del +exists +zadd +zrem +zrange +scan +type +evalsha +eval +getrange +pexpire +pttl"
	pebbleDir      = "https://localhost:14000/dir"
	pebbleRootPEM  = "/data/launchroom/pebble-minica.pem"
	edgeBAdmin     = "127.0.0.1:2029"
	sharedHost     = "shop.edge.test"
	failingHost    = "broken.edge.test"
)

const pebbleConfig = `{"pebble": {
  "listenAddress": "127.0.0.1:14000", "managementListenAddress": "127.0.0.1:15000",
  "certificate": "test/certs/localhost/cert.pem", "privateKey": "test/certs/localhost/key.pem",
  "httpPort": 80, "tlsPort": 443, "ocspResponderURL": "", "externalAccountBindingRequired": false
}}`

// TestMultiEdgeSharedStorage runs two edge Caddys (launchroom's image) that
// share Valkey certificate storage against a Pebble ACME CA (docs/05 §5):
//   - one ACME client for both: either edge may order, the other answers
//     the HTTP-01 / TLS-ALPN-01 challenge from storage, both serve one cert;
//   - launchroomd reads the shared storage, mirrors the cert and reports it
//     (issuer, expiry); an issuance failure is reported with Caddy's error;
//   - with the storage down, a restarted edge Caddy serves the mirror.
func TestMultiEdgeSharedStorage(t *testing.T) {
	api, err := client.New(client.FromEnv)
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { _ = api.Close() })
	ctx, cancel := context.WithTimeout(context.Background(), 15*time.Minute)
	defer cancel()

	insp, err := api.ImageInspect(ctx, localCaddyImage)
	if err != nil || len(insp.RepoDigests) == 0 {
		t.Skipf("%s is not loaded into this Docker daemon (run `make caddy-image` before `make test-daemon-integration`): %v", localCaddyImage, err)
	}
	_, digest, _ := strings.Cut(insp.RepoDigests[0], "@")
	image := state.ImageRef("docker.io/library/launchroom-dev-caddy@" + digest)

	sweep(t, api)
	t.Cleanup(func() { sweep(t, api) })
	serverID := state.ServerID(randomID("srv"))
	engine, err := docker.NewEngine(docker.EngineOptions{Host: engineHost, ServerID: string(serverID), UserAgent: "launchroom-itest"})
	if err != nil {
		t.Fatal(err)
	}
	log := slog.New(slog.NewTextHandler(os.Stderr, &slog.HandlerOptions{Level: slog.LevelInfo}))

	// Infrastructure: Valkey (shared storage), challtestsrv (DNS: every name →
	// 127.0.0.1), Pebble (ACME; validates on :80/:443 of this host).
	valkey := runHelper(ctx, t, engine, serverID, "lr-itest-valkey", valkeyImage, nil,
		// Edges log in as the restricted ACL user the dashboard creates
		// (App\Services\Ingress\CertStorage): only the lr-caddy keys and the
		// commands caddy-storage-redis, redislock and launchroomd's mirror use.
		[]string{"valkey-server", "/etc/lr-valkey.conf"}, nil,
		map[string][]byte{"/etc/lr-valkey.conf": []byte("bind 127.0.0.1\nport 6379\nrequirepass itest-admin-password\nsave \"\"\nappendonly no\n" +
			"user lr-caddy on >" + storagePass + " " + certStorageACL + "\n")})
	waitFor(ctx, t, "valkey with the lr-caddy ACL user", func() error {
		var out bytes.Buffer
		res, err := engine.ExecStream(ctx, valkey, docker.ExecOptions{Argv: []string{"valkey-cli", "--no-auth-warning", "--user", "lr-caddy", "--pass", storagePass, "ping"}, Stdout: &out})
		if err != nil {
			return err
		}
		if !strings.Contains(out.String(), "PONG") {
			return fmt.Errorf("ping as lr-caddy: %q %q (exit %d)", out.String(), res.Stderr, res.ExitCode)
		}
		return nil
	})
	// 2026-10 review M3: the user shares the dashboard's main Valkey, so it
	// must not pause, kill or list other clients, nor touch other keys.
	for _, argv := range [][]string{
		{"client", "pause", "1000", "all"}, {"client", "kill", "id", "1"}, {"client", "list"}, {"client", "no-evict", "on"},
		{"config", "get", "*"}, {"keys", "*"}, {"get", "laravel_session:x"}, {"flushall"}, {"select", "1"},
	} {
		var out bytes.Buffer
		res, err := engine.ExecStream(ctx, valkey, docker.ExecOptions{Argv: append([]string{"valkey-cli", "--no-auth-warning", "--user", "lr-caddy", "--pass", storagePass}, argv...), Stdout: &out})
		if err != nil {
			t.Fatal(err)
		}
		if !strings.Contains(out.String(), "NOPERM") {
			t.Fatalf("lr-caddy may run %v: %q %q (exit %d)", argv, out.String(), res.Stderr, res.ExitCode)
		}
	}
	runHelper(ctx, t, engine, serverID, "lr-itest-challtestsrv", challtestsrvImage, nil, []string{
		"-defaultIPv4", "127.0.0.1", "-defaultIPv6", "", "-http01", "", "-https01", "", "-tlsalpn01", "", "-doh", "",
		"-dnsserver", "127.0.0.1:8053", "-management", "127.0.0.1:8055",
	}, nil, nil)
	pebble := runHelper(ctx, t, engine, serverID, "lr-itest-pebble", pebbleImage, nil,
		[]string{"-config", "/test/config/lr.json", "-dnsserver", "127.0.0.1:8053"},
		[]string{"PEBBLE_VA_NOSLEEP=1", "PEBBLE_WFE_NONCEREJECT=0", "PEBBLE_AUTHZREUSE=0"},
		map[string][]byte{"/test/config/lr.json": []byte(pebbleConfig)})
	minica := readFile(ctx, t, engine, pebble, "/test/certs/pebble.minica.pem")
	waitHTTP(ctx, t, "https://localhost:14000/dir", minica)

	// Edge A: launchroomd's Manager with shared storage (docs/05 §5).
	pol := policy.DefaultConfig()
	pol.CertStorageAddrs = mustAddrPorts(storageAddr)
	pol.SystemImageDigests[policy.SystemCaddy] = append(pol.SystemImageDigests[policy.SystemCaddy], digest)
	guardAllowSystemDigest(t, policy.SystemCaddy, digest)
	spec := &state.IngressSpec{Storage: &state.CertStorageSpec{Address: storageAddr, KeyPrefix: "lr-caddy"}}
	creds := map[string]secret.Secret{"username": "lr-caddy", "password": storagePass, "encryptionKey": storageKey}
	cacheDir := t.TempDir()
	ask := &caddy.Ask{}
	go func() { _ = ask.Serve(ctx, askAddr) }()
	opts := caddy.Options{
		AdminListen: caddy.DefaultAdminListen, AskURL: "http://" + askAddr + caddy.AskPath, HTTPPort: 80, HTTPSPort: 443, RedirectHTTPSPort: 443,
		ACMEDirectory: pebbleDir, ACMERootFiles: []string{pebbleRootPEM},
	}
	m := caddy.NewManager(caddy.ManagerOptions{
		ServerID: string(serverID), Docker: engine, Admin: caddy.NewAdminClient(caddy.DefaultAdminListen), Policy: pol,
		Ask: ask, CacheDir: cacheDir, Log: log, ReadyTimeout: time.Minute, Render: opts,
		Spec: func() *state.IngressSpec { return spec },
		OpenSecret: func(_ state.Sealed, p state.IngressSecretPurpose) (map[string]secret.Secret, error) {
			if p != state.IngressSecretCertStorage {
				return nil, errors.New("unexpected purpose")
			}
			return creds, nil
		},
	})
	specs := []state.RouteSpec{{
		ID: state.RouteID(randomID("rt")), Hosts: []state.Hostname{sharedHost}, TLS: state.TLSACME,
		Upstreams: []state.RouteUpstream{{Workload: state.WorkloadID(randomID("wl")), Port: 3000}},
	}}
	noWorkloads := func(state.WorkloadID) (caddy.Endpoint, bool) { return caddy.Endpoint{}, false }
	sys := state.SystemContainer{Enabled: true, Image: image}

	// The first load creates the container; Pebble's root goes into its data
	// volume (the issuer reads it at provision), then the config loads.
	_ = m.Sync(ctx, sys, caddy.ResolveRoutes(specs, noWorkloads))
	edgeA := systemCaddyID(ctx, t, engine)
	copyFiles(ctx, t, engine, edgeA, map[string][]byte{pebbleRootPEM: minica})
	st := m.Sync(ctx, sys, caddy.ResolveRoutes(specs, noWorkloads))
	if st == nil || st.State != state.IngressApplied || st.Storage == nil || st.Storage.State != state.StorageShared {
		t.Fatalf("edge A not applied with shared storage: %+v", st)
	}

	// Edge B: the same image and storage, other ports, its own admin API.
	cc := caddy.ContainerSpec(string(serverID), image, edgeBAdmin)
	cc.Name = "lr-itest-caddy-b"
	delete(cc.Config.Labels, docker.LabelSystem) // not the Manager's container
	cc.Config.Cmd = []string{"caddy", "run"}
	cc.HostConfig.Mounts = nil
	edgeB, err := fixtureEngine(t, serverID).CreateContainer(ctx, cc) // a modified Caddy: a fixture, not launchroomd's spec
	if err != nil {
		t.Fatal(err)
	}
	copyFiles(ctx, t, engine, edgeB, map[string][]byte{
		pebbleRootPEM: minica,
		caddy.SecretsDir + "/" + caddy.SecretStorageUser:     []byte("lr-caddy"),
		caddy.SecretsDir + "/" + caddy.SecretStoragePassword: []byte(storagePass),
		caddy.SecretsDir + "/" + caddy.SecretStorageKey:      []byte(storageKey),
	})
	if err := engine.StartContainer(ctx, edgeB); err != nil {
		t.Fatal(err)
	}
	optsB := opts
	optsB.AdminListen, optsB.HTTPPort, optsB.HTTPSPort = edgeBAdmin, 8080, 8443
	cfgB, err := caddy.RenderTLS(caddy.ResolveRoutes(specs, noWorkloads), caddy.TLS{Storage: &caddy.Storage{Address: storageAddr, KeyPrefix: "lr-caddy", HasUsername: true}}, optsB)
	if err != nil {
		t.Fatal(err)
	}
	adminB := caddy.NewAdminClient(edgeBAdmin)
	waitFor(ctx, t, "edge B admin API", func() error { return adminB.Ready(ctx) })
	if err := adminB.Load(ctx, cfgB); err != nil {
		t.Fatal(err)
	}

	// Both edges serve the same certificate (one ACME client via storage).
	var serialA, serialB string
	waitFor(ctx, t, "a certificate on both edges", func() error {
		var errA, errB error
		serialA, errA = servedSerial("127.0.0.1:443", sharedHost)
		serialB, errB = servedSerial("127.0.0.1:8443", sharedHost)
		return errors.Join(errA, errB)
	})
	if serialA != serialB {
		t.Fatalf("edges serve different certificates: %s vs %s", serialA, serialB)
	}

	// launchroomd reads the shared storage, mirrors and reports the cert.
	m.RefreshCertificates(ctx)
	st = m.Sync(ctx, sys, caddy.ResolveRoutes(specs, noWorkloads))
	cert := findCert(st, sharedHost)
	if cert == nil || cert.State != state.CertIssued || !strings.Contains(cert.Issuer, "Pebble") || cert.NotAfter == nil {
		t.Fatalf("certificate status: %+v", st.Certificates)
	}
	if st.Storage.Mirrored != 1 {
		t.Fatalf("mirror: %+v", st.Storage)
	}
	if _, err := os.Stat(filepath.Join(cacheDir, "certs", sharedHost+".key")); err != nil {
		t.Fatalf("mirror file: %v", err)
	}
	stored, err := (&certstore.Redis{Addr: storageAddr, Username: "lr-caddy", Password: storagePass, KeyPrefix: "lr-caddy", EncryptionKey: storageKey}).Certificates(ctx, false)
	if err != nil || len(stored) == 0 {
		t.Fatalf("shared storage: %v %d", err, len(stored))
	}

	// An issuance failure is reported with Caddy's error (DNS SERVFAIL).
	challtest(ctx, t, "set-servfail", `{"host":"`+failingHost+`."}`)
	specs = append(specs, state.RouteSpec{
		ID: state.RouteID(randomID("rt")), Hosts: []state.Hostname{failingHost}, TLS: state.TLSACME,
		Upstreams: []state.RouteUpstream{{Workload: state.WorkloadID(randomID("wl")), Port: 3000}},
	})
	_ = m.Sync(ctx, sys, caddy.ResolveRoutes(specs, noWorkloads))
	waitFor(ctx, t, "a reported issuance failure", func() error {
		m.RefreshCertificates(ctx)
		st = m.Sync(ctx, sys, caddy.ResolveRoutes(specs, noWorkloads))
		if c := findCert(st, failingHost); c == nil || c.State != state.CertFailed || c.LastError == "" {
			return fmt.Errorf("status %+v", c)
		}
		return nil
	})
	t.Logf("reported error: %s", findCert(st, failingHost).LastError)

	// Storage down + Caddy restarted (in-memory certificates gone): the edge
	// serves the mirror (degraded) and still presents the same certificate.
	if err := engine.StopContainer(ctx, valkey, 5*time.Second); err != nil {
		t.Fatal(err)
	}
	if err := engine.StopContainer(ctx, edgeA, 5*time.Second); err != nil {
		t.Fatal(err)
	}
	if err := engine.RemoveContainer(ctx, edgeA); err != nil {
		t.Fatal(err)
	}
	st = m.Sync(ctx, sys, caddy.ResolveRoutes(specs[:1], noWorkloads))
	if st.State != state.IngressApplied || st.Storage == nil || st.Storage.State != state.StorageDegraded || st.Storage.Mirrored != 1 {
		t.Fatalf("degraded status: %+v / %+v", st, st.Storage)
	}
	waitFor(ctx, t, "the mirrored certificate after a restart", func() error {
		s, err := servedSerial("127.0.0.1:443", sharedHost)
		if err == nil && s != serialA {
			return fmt.Errorf("serial %s, want %s", s, serialA)
		}
		return err
	})

	// Storage back: shared again.
	if err := engine.StartContainer(ctx, valkey); err != nil {
		t.Fatal(err)
	}
	waitFor(ctx, t, "shared storage again", func() error {
		st = m.Sync(ctx, sys, caddy.ResolveRoutes(specs[:1], noWorkloads))
		if st.Storage == nil || st.Storage.State != state.StorageShared {
			return fmt.Errorf("storage %+v", st.Storage)
		}
		return nil
	})
}

func findCert(st *state.IngressStatus, host state.Hostname) *state.CertificateStatus {
	if st == nil {
		return nil
	}
	for i := range st.Certificates {
		if st.Certificates[i].Host == host {
			return &st.Certificates[i]
		}
	}
	return nil
}

func mustAddrPorts(s ...string) []netip.AddrPort {
	out := make([]netip.AddrPort, 0, len(s))
	for _, a := range s {
		out = append(out, netip.MustParseAddrPort(a))
	}
	return out
}

// runHelper runs a launchroom-labelled helper container with the host network.
func runHelper(ctx context.Context, t *testing.T, e *docker.Engine, srv state.ServerID, name, image string, entrypoint, cmd, env []string, files map[string][]byte) string {
	t.Helper()
	if present, err := e.ImagePresent(ctx, image); err != nil || !present {
		if err := e.PullImage(ctx, image); err != nil {
			t.Fatal(err)
		}
	}
	labels := docker.ManagedLabels(string(srv))
	memory, pids := int64(512<<20), int64(512)
	id, err := fixtureEngine(t, srv).CreateContainer(ctx, &docker.ContainerCreate{
		Name:   name,
		Config: &container.Config{Image: image, Entrypoint: entrypoint, Cmd: cmd, Env: env, Labels: labels},
		HostConfig: &container.HostConfig{
			NetworkMode: "host", Resources: container.Resources{Memory: memory, PidsLimit: &pids},
		},
	})
	if err != nil {
		t.Fatal(err)
	}
	if len(files) > 0 {
		copyFiles(ctx, t, e, id, files)
	}
	if err := e.StartContainer(ctx, id); err != nil {
		t.Fatal(err)
	}
	return id
}

func systemCaddyID(ctx context.Context, t *testing.T, e *docker.Engine) string {
	t.Helper()
	cs, err := e.ListContainers(ctx, map[string]string{docker.LabelSystem: policy.SystemCaddy})
	if err != nil || len(cs) != 1 {
		t.Fatalf("system caddy: %v %v", cs, err)
	}
	return cs[0].ID
}

// copyFiles writes files (absolute paths) into a container.
func copyFiles(ctx context.Context, t *testing.T, e *docker.Engine, id string, files map[string][]byte) {
	t.Helper()
	var buf bytes.Buffer
	tw := tar.NewWriter(&buf)
	dirs := map[string]bool{}
	for name, data := range files {
		var parents []string
		for d := filepath.Dir(name); d != "/" && !dirs[d]; d = filepath.Dir(d) {
			dirs[d] = true
			parents = append([]string{d}, parents...)
		}
		for _, d := range parents {
			_ = tw.WriteHeader(&tar.Header{Typeflag: tar.TypeDir, Name: d[1:] + "/", Mode: 0o755})
		}
		_ = tw.WriteHeader(&tar.Header{Typeflag: tar.TypeReg, Name: name[1:], Mode: 0o644, Size: int64(len(data))})
		_, _ = tw.Write(data)
	}
	_ = tw.Close()
	if err := e.CopyToContainer(ctx, id, "/", &buf); err != nil {
		t.Fatal(err)
	}
}

// readFile reads one file out of a container.
func readFile(ctx context.Context, t *testing.T, e *docker.Engine, id, path string) []byte {
	t.Helper()
	archive, err := e.CopyFromContainer(ctx, id, path, 1<<20)
	if err != nil {
		t.Fatal(err)
	}
	tr := tar.NewReader(bytes.NewReader(archive))
	if _, err := tr.Next(); err != nil {
		t.Fatal(err)
	}
	data, err := io.ReadAll(tr)
	if err != nil {
		t.Fatal(err)
	}
	return data
}

func waitHTTP(ctx context.Context, t *testing.T, url string, rootPEM []byte) {
	t.Helper()
	pool := x509.NewCertPool()
	pool.AppendCertsFromPEM(rootPEM)
	c := &http.Client{Timeout: 5 * time.Second, Transport: &http.Transport{TLSClientConfig: &tls.Config{RootCAs: pool, MinVersion: tls.VersionTLS12}}}
	waitFor(ctx, t, url, func() error {
		resp, err := c.Get(url) //nolint:noctx // test helper
		if err != nil {
			return err
		}
		_ = resp.Body.Close()
		return nil
	})
}

// challtest calls pebble-challtestsrv's management API.
func challtest(ctx context.Context, t *testing.T, op, body string) {
	t.Helper()
	req, _ := http.NewRequestWithContext(ctx, http.MethodPost, "http://127.0.0.1:8055/"+op, strings.NewReader(body))
	resp, err := http.DefaultClient.Do(req)
	if err != nil {
		t.Fatal(err)
	}
	_ = resp.Body.Close()
	if resp.StatusCode != http.StatusOK {
		t.Fatalf("challtestsrv %s: HTTP %d", op, resp.StatusCode)
	}
}

// servedSerial completes a TLS handshake with SNI and returns the leaf serial.
func servedSerial(addr, sni string) (string, error) {
	d := tls.Dialer{Config: &tls.Config{ServerName: sni, InsecureSkipVerify: true, MinVersion: tls.VersionTLS12}} //nolint:gosec // comparing certificates, not trusting them
	ctx, cancel := context.WithTimeout(context.Background(), 10*time.Second)
	defer cancel()
	raw, err := d.DialContext(ctx, "tcp", addr)
	if err != nil {
		return "", err
	}
	conn, _ := raw.(*tls.Conn)
	defer func() { _ = conn.Close() }()
	certs := conn.ConnectionState().PeerCertificates
	if len(certs) == 0 {
		return "", errors.New("no certificate")
	}
	if !strings.Contains(certs[0].Issuer.CommonName, "Pebble") {
		return "", fmt.Errorf("issuer %q is not Pebble", certs[0].Issuer.CommonName)
	}
	return certs[0].SerialNumber.String(), nil
}

func waitFor(ctx context.Context, t *testing.T, what string, f func() error) {
	t.Helper()
	deadline := time.Now().Add(3 * time.Minute)
	var err error
	for time.Now().Before(deadline) && ctx.Err() == nil {
		if err = f(); err == nil {
			return
		}
		time.Sleep(time.Second)
	}
	t.Fatalf("timed out waiting for %s: %v", what, err)
}
