//go:build integration

package integration

import (
	"archive/tar"
	"bytes"
	"compress/gzip"
	"context"
	"crypto/sha256"
	"encoding/base64"
	"encoding/hex"
	"encoding/json"
	"io"
	"log/slog"
	"maps"
	"net"
	"net/http"
	"os"
	"os/signal"
	"path/filepath"
	"strings"
	"syscall"
	"testing"
	"time"

	"github.com/moby/moby/api/types/container"
	"github.com/moby/moby/api/types/mount"
	"github.com/moby/moby/client"

	"github.com/vantezzen/launchroom/daemon/internal/state"
	"github.com/vantezzen/launchroom/daemon/internal/tasks"
	"github.com/vantezzen/launchroom/daemon/internal/update"
	"github.com/vantezzen/launchroom/daemon/internal/update/updatetest"
)

// The self-update swap end to end, inside the dind test daemon:
//
//   - a "server" installed like install/server.sh does it (the real script,
//     run in the digest-pinned docker CLI image), whose launchroomd is a fake
//     built from this test binary (it only records itself running, like the
//     real one after its first signed exchange);
//   - releases signed with a throwaway key (trust.json key mode) and served
//     like GitHub's release downloads;
//   - the real self_update handler and supervisor: verify, pull, back up,
//     run the bundle's server.sh, wait for the new version, roll back.
const (
	updRegistryImage = "docker.io/library/registry@sha256:ddf754342cfc8acc51a56d5d0ab6af06826461864460636d8bd5c546dab2a7b8" // registry:3.1.2
	updComposeImage  = "docker.io/library/docker@sha256:6fae2214c3121a28d5aa639d09032f61310b7c9c75ffbad1025f261a1b621800"   // docker:29.9.1-cli
	updRegistryAddr  = "127.0.0.1:5011"
	updRepo          = updRegistryAddr + "/lr-itest/launchroomd"
	updDeployDir     = "/opt/launchroom/bin/deploy"
	fakeEntrypoint   = "/fake"
	// guardVolumeSocket is the install's dockerd-guard socket (compose volume
	// launchroomd_guard-socket) as seen from the dind test daemon's disk.
	guardVolumeSocket = "/var/lib/docker/volumes/launchroomd_guard-socket/_data/docker.sock"
)

// TestFakeLaunchroomd is the entrypoint of the fake launchroomd image: it
// records itself running (update.MarkRunning, as launchroomd does after its
// first verified state fetch) and waits for SIGTERM. LR_FAKE_BROKEN=1 makes
// it crash at once (a release that never becomes healthy).
func TestFakeLaunchroomd(t *testing.T) {
	if os.Getenv("LR_FAKE") != "1" {
		t.Skip("only runs inside the fake launchroomd image")
	}
	start := time.Now()
	if os.Getenv("LR_FAKE_BROKEN") == "1" {
		os.Exit(3)
	}
	// LR_FAKE_IDLE: a stand-in for the dashboard/worker services.
	if os.Getenv("LR_FAKE_IDLE") != "1" {
		if err := update.MarkRunning("/data", os.Getenv("LR_FAKE_VERSION"), start); err != nil {
			t.Fatal(err)
		}
	}
	ctx, stop := signal.NotifyContext(context.Background(), syscall.SIGTERM, os.Interrupt)
	defer stop()
	<-ctx.Done()
}

// TestFakeSupervisor is `launchroomd update-supervisor <plan>` in the fake image.
func TestFakeSupervisor(t *testing.T) {
	if os.Getenv("LR_FAKE") != "1" {
		t.Skip("only runs inside the fake launchroomd image")
	}
	plan := ""
	for _, a := range os.Args[1:] {
		if strings.HasSuffix(a, "plan.json") {
			plan = a
		}
	}
	api, err := client.New(client.FromEnv)
	if err != nil {
		t.Fatal(err)
	}
	s := &update.Supervisor{API: api, Log: slog.New(slog.NewTextHandler(os.Stderr, nil)), Poll: 500 * time.Millisecond}
	if err := s.RunSupervisor(context.Background(), plan); err != nil {
		t.Fatal(err)
	}
}

type updEnv struct {
	api        *client.Client
	project    string // install folder
	signer     *updatetest.Signer
	assets     map[string][]byte
	releaseURL string
	images     map[string]string // version → digest ref
	imageIDs   map[string]string // version → image id
}

func TestSelfUpdateSwapAndRollback(t *testing.T) {
	ctx, cancel := context.WithTimeout(context.Background(), 20*time.Minute)
	defer cancel()
	e := setupUpdate(t)

	// Released versions: 1.0.0 (installed), 1.1.0 (good), 1.2.0 (never healthy).
	for _, v := range []struct {
		version string
		broken  bool
	}{{"1.0.0", false}, {"1.1.0", false}, {"1.2.0", true}} {
		e.buildFake(ctx, t, v.version, v.broken)
		e.publish(t, v.version, e.signer)
	}

	// Install 1.0.0 with the bundle's own server.sh, as on a real server.
	e.install(ctx, t, "1.0.0")
	e.waitRunning(t, "1.0.0")
	self := e.self(ctx, t)
	if self.Image != e.imageIDs["1.0.0"] {
		t.Fatalf("installed image %s, want %s", self.Image, e.imageIDs["1.0.0"])
	}

	// 1.0.0 → 1.1.0: verified, swapped, healthy.
	res, err := e.handler(self, "1.0.0")(ctx, updateTask("tsk_01J9Z8X7W6V5T4S3R2Q1P0AAA1", "1.1.0", e.images["1.1.0"]))
	if err != nil {
		t.Fatalf("update to 1.1.0 failed: %v", err)
	}
	if res.SelfUpdate == nil || res.SelfUpdate.RolledBack || res.SelfUpdate.ToVersion != "1.1.0" || res.SelfUpdate.FromVersion != "1.0.0" {
		t.Fatalf("result %+v", res.SelfUpdate)
	}
	self = e.self(ctx, t)
	if self.Image != e.imageIDs["1.1.0"] {
		t.Fatalf("after the update launchroomd runs %s, want 1.1.0 (%s)", self.Image, e.imageIDs["1.1.0"])
	}
	if !strings.Contains(e.readFile(t, ".env"), "LR_DAEMON_IMAGE="+e.images["1.1.0"]) {
		t.Fatal(".env does not pin 1.1.0")
	}
	e.assertNoUpdaters(ctx, t)

	// Downgrade and reinstall are refused before anything happens.
	for _, v := range []string{"1.0.0", "1.1.0"} {
		if _, err := e.handler(self, "1.1.0")(ctx, updateTask("tsk_01J9Z8X7W6V5T4S3R2Q1P0AAA2", v, e.images[v])); err == nil || !strings.Contains(err.Error(), "never downgrades") {
			t.Fatalf("update to %s: %v", v, err)
		}
	}
	// A release signed by anyone but the pinned key is refused.
	e.publish(t, "1.3.0", mustSigner(t))
	if _, err := e.handler(self, "1.1.0")(ctx, updateTask("tsk_01J9Z8X7W6V5T4S3R2Q1P0AAA3", "1.3.0", e.images["1.1.0"])); err == nil || !strings.Contains(err.Error(), "signature") {
		t.Fatalf("foreign signature: %v", err)
	}
	if got := e.self(ctx, t).Image; got != e.imageIDs["1.1.0"] {
		t.Fatalf("refused updates changed launchroomd to %s", got)
	}

	// 1.1.0 → 1.2.0: installed, never healthy → rolled back to 1.1.0.
	before := e.readFile(t, ".env")
	res, err = e.handler(self, "1.1.0")(ctx, updateTask("tsk_01J9Z8X7W6V5T4S3R2Q1P0AAA4", "1.2.0", e.images["1.2.0"]))
	if err == nil || !strings.Contains(err.Error(), "rolled back to 1.1.0") {
		t.Fatalf("broken update: %v", err)
	}
	if res.SelfUpdate == nil || !res.SelfUpdate.RolledBack {
		t.Fatalf("result %+v", res.SelfUpdate)
	}
	e.waitRunning(t, "1.1.0")
	if got := e.self(ctx, t).Image; got != e.imageIDs["1.1.0"] {
		t.Fatalf("after the rollback launchroomd runs %s, want 1.1.0", got)
	}
	if after := e.readFile(t, ".env"); after != before {
		t.Fatalf(".env not restored:\n%s\nwant\n%s", after, before)
	}
	e.assertNoUpdaters(ctx, t)

	// A re-leased attempt of the finished task reports the recorded outcome.
	if _, err := e.handler(self, "1.1.0")(ctx, updateTask("tsk_01J9Z8X7W6V5T4S3R2Q1P0AAA4", "1.2.0", e.images["1.2.0"])); err == nil || !strings.Contains(err.Error(), "rolled back") {
		t.Fatalf("re-leased attempt: %v", err)
	}
}

func setupUpdate(t *testing.T) *updEnv {
	t.Helper()
	ctx, cancel := context.WithTimeout(context.Background(), 10*time.Minute)
	defer cancel()
	api, err := client.New(client.FromEnv)
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { _ = api.Close() })
	sweep(t, api)
	t.Cleanup(func() { sweep(t, api) })

	e := &updEnv{api: api, project: filepath.Join(t.TempDir(), "launchroomd"), signer: mustSigner(t),
		assets: map[string][]byte{}, images: map[string]string{}, imageIDs: map[string]string{}}

	// Local registry (pulls by digest need one).
	mustPull(ctx, t, api, updRegistryImage)
	mustPull(ctx, t, api, updComposeImage)
	_, _ = api.ContainerRemove(ctx, "lr-itest-update-registry", client.ContainerRemoveOptions{Force: true})
	cr, err := api.ContainerCreate(ctx, client.ContainerCreateOptions{
		Name: "lr-itest-update-registry",
		Config: &container.Config{Image: updRegistryImage, Labels: map[string]string{"launchroom.managed": "true"},
			Env: []string{"REGISTRY_HTTP_ADDR=" + updRegistryAddr}},
		// Host network of the test daemon: dockerd pulls from 127.0.0.1 (no TLS needed for loopback).
		HostConfig: &container.HostConfig{NetworkMode: "host", Mounts: []mount.Mount{{Type: mount.TypeTmpfs, Target: "/var/lib/registry"}}},
	})
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() {
		_, _ = api.ContainerRemove(context.Background(), cr.ID, client.ContainerRemoveOptions{Force: true})
	})
	if _, err := api.ContainerStart(ctx, cr.ID, client.ContainerStartOptions{}); err != nil {
		t.Fatal(err)
	}
	updWaitHTTP(t, "http://"+updRegistryAddr+"/v2/")

	// Release downloads (like github.com/<repo>/releases/download/v<version>/…).
	var lc net.ListenConfig
	l, err := lc.Listen(ctx, "tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	srv := &http.Server{ReadHeaderTimeout: 5 * time.Second, Handler: http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		b, ok := e.assets[r.URL.Path]
		if !ok {
			http.NotFound(w, r)
			return
		}
		_, _ = w.Write(b)
	})}
	go func() { _ = srv.Serve(l) }()
	t.Cleanup(func() { _ = srv.Close() })
	e.releaseURL = "http://" + l.Addr().String() + "/releases"

	if err := os.MkdirAll(filepath.Join(e.project, "data"), 0o700); err != nil {
		t.Fatal(err)
	}
	// The pinned trust: this test's release key (key mode), never the dashboard's say.
	trust, _ := json.Marshal(update.Trust{ReleaseBase: e.releaseURL, PublicKey: e.signer.PublicKeyPEM, AllowInsecureReleaseBase: true})
	e.writeFile(t, "data/trust.json", trust)
	// An enrolled server (server.sh then only updates and restarts launchroomd).
	e.writeFile(t, "data/enrollment.json", []byte("{}\n"))
	return e
}

// buildFake creates the fake launchroomd image of a version (this test
// binary on scratch) and pushes it, recording its digest reference.
func (e *updEnv) buildFake(ctx context.Context, t *testing.T, version string, broken bool) {
	t.Helper()
	self, err := os.ReadFile("/proc/self/exe")
	if err != nil {
		t.Fatal(err)
	}
	var rootfs bytes.Buffer
	tw := tar.NewWriter(&rootfs)
	_ = tw.WriteHeader(&tar.Header{Name: "fake", Mode: 0o755, Size: int64(len(self)), Typeflag: tar.TypeReg})
	_, _ = tw.Write(self)
	_ = tw.WriteHeader(&tar.Header{Name: "tmp/", Mode: 0o1777, Typeflag: tar.TypeDir})
	// The real launchroomd (its update-supervisor command) and dockerd-guard
	// of this tree: the install runs the guard in front of the socket (D49)
	// and the supervisor is the one shape the guard allows.
	for _, bin := range []string{"launchroomd", "dockerd-guard"} {
		b, err := os.ReadFile(filepath.Join("/opt/launchroom/bin", bin))
		if err != nil {
			t.Fatalf("%s: %v (built by make test-daemon-integration)", bin, err)
		}
		_ = tw.WriteHeader(&tar.Header{Name: "usr/local/bin/" + bin, Mode: 0o755, Size: int64(len(b)), Typeflag: tar.TypeReg})
		_, _ = tw.Write(b)
	}
	_ = tw.Close()
	brokenEnv := "0"
	if broken {
		brokenEnv = "1"
	}
	tag := updRepo + ":" + version
	rc, err := e.api.ImageImport(ctx, client.ImageImportSource{Source: &rootfs, SourceName: "-"}, tag, client.ImageImportOptions{Changes: []string{
		"ENV LR_FAKE=1 LR_FAKE_VERSION=" + version + " LR_FAKE_BROKEN=" + brokenEnv,
		`ENTRYPOINT ["` + fakeEntrypoint + `", "-test.run=^TestFakeLaunchroomd$"]`,
	}})
	if err != nil {
		t.Fatal(err)
	}
	_, _ = io.Copy(io.Discard, rc)
	_ = rc.Close()
	push, err := e.api.ImagePush(ctx, tag, client.ImagePushOptions{RegistryAuth: base64.URLEncoding.EncodeToString([]byte("{}"))})
	if err != nil {
		t.Fatal(err)
	}
	if err := push.Wait(ctx); err != nil {
		t.Fatalf("push %s: %v", tag, err)
	}
	_ = push.Close()
	ins, err := e.api.ImageInspect(ctx, tag)
	if err != nil {
		t.Fatal(err)
	}
	for _, d := range ins.RepoDigests {
		if strings.HasPrefix(d, updRepo+"@sha256:") {
			e.images[version] = d
		}
	}
	if e.images[version] == "" {
		t.Fatalf("no digest for %s: %v", tag, ins.RepoDigests)
	}
	e.imageIDs[version] = ins.ID
}

// publish signs and serves the deploy bundle of version (made of the repo's
// real install scripts and daemon compose file).
func (e *updEnv) publish(t *testing.T, version string, signer *updatetest.Signer) {
	t.Helper()
	e.publishWith(t, version, signer, nil)
}

// publishWith is publish with some bundle files replaced.
func (e *updEnv) publishWith(t *testing.T, version string, signer *updatetest.Signer, override map[string][]byte) {
	t.Helper()
	read := func(p string) []byte {
		b, err := os.ReadFile(filepath.Join(updDeployDir, p))
		if err != nil {
			t.Fatalf("%v (make test-daemon-integration copies deploy/ next to the test binary)", err)
		}
		return b
	}
	image := e.images[version]
	if image == "" {
		image = e.images["1.1.0"]
	}
	files := map[string][]byte{
		"VERSION":                   []byte(version + "\n"),
		"control-plane/images.env":  []byte("LR_DAEMON_IMAGE=" + image + "\nLR_COMPOSE_IMAGE=" + updComposeImage + "\n"),
		"control-plane/compose.yml": read("control-plane/compose.yml"),
		"control-plane/launchroom":  read("control-plane/launchroom"),
		"daemon/compose.yml":        read("daemon/compose.yml"),
		"install/install.sh":        read("install/install.sh"),
		"install/server.sh":         read("install/server.sh"),
	}
	maps.Copy(files, override)
	var buf bytes.Buffer
	zw := gzip.NewWriter(&buf)
	tw := tar.NewWriter(zw)
	for name, b := range files {
		_ = tw.WriteHeader(&tar.Header{Name: name, Mode: 0o644, Size: int64(len(b)), Typeflag: tar.TypeReg})
		_, _ = tw.Write(b)
	}
	_ = tw.Close()
	_ = zw.Close()
	tarball := buf.Bytes()
	sig, err := signer.Sign(tarball)
	if err != nil {
		t.Fatal(err)
	}
	sum := sha256.Sum256(tarball)
	base := "/releases/download/v" + version + "/"
	e.assets[base+update.BundleAsset] = tarball
	e.assets[base+update.BundleAsset+".sha256"] = []byte(hex.EncodeToString(sum[:]) + "  " + update.BundleAsset + "\n")
	e.assets[base+update.BundleAsset+".sigstore.json"] = sig
}

// install runs the release's server.sh in the docker CLI image, like the
// supervisor does (install folder mounted at its own path).
func (e *updEnv) install(ctx context.Context, t *testing.T, version string) {
	t.Helper()
	bundle := filepath.Join(e.project, "data", "bundle-"+version)
	if err := os.MkdirAll(bundle, 0o700); err != nil {
		t.Fatal(err)
	}
	b, err := update.ParseBundle(e.assets["/releases/download/v"+version+"/"+update.BundleAsset])
	if err != nil {
		t.Fatal(err)
	}
	_ = os.RemoveAll(bundle)
	if err := b.Extract(bundle); err != nil {
		t.Fatal(err)
	}
	cr, err := e.api.ContainerCreate(ctx, client.ContainerCreateOptions{
		Name: "lr-itest-update-install",
		Config: &container.Config{Image: updComposeImage, Cmd: []string{"sh", filepath.Join(bundle, "install", "server.sh")}, WorkingDir: e.project,
			Labels: map[string]string{"launchroom.managed": "true"},
			Env: []string{"LR_BUNDLE_DIR=" + bundle, "LR_BUNDLE_VERIFIED=1", "LAUNCHROOM_DAEMON_DIR=" + e.project, "LR_DAEMON_IMAGE=" + e.images[version],
				"LR_CONTROL_PLANE_URL=http://127.0.0.1:9", "LR_CP_KEY=ed25519:" + base64.StdEncoding.EncodeToString(make([]byte, 32))}},
		HostConfig: &container.HostConfig{NetworkMode: "none", Mounts: []mount.Mount{
			{Type: mount.TypeBind, Source: "/var/run/docker.sock", Target: "/var/run/docker.sock"},
			{Type: mount.TypeBind, Source: e.project, Target: e.project},
		}},
	})
	if err != nil {
		t.Fatal(err)
	}
	defer func() {
		_, _ = e.api.ContainerRemove(context.WithoutCancel(ctx), cr.ID, client.ContainerRemoveOptions{Force: true})
	}()
	if _, err := e.api.ContainerStart(ctx, cr.ID, client.ContainerStartOptions{}); err != nil {
		t.Fatal(err)
	}
	w := e.api.ContainerWait(ctx, cr.ID, client.ContainerWaitOptions{Condition: container.WaitConditionNotRunning})
	select {
	case r := <-w.Result:
		if r.StatusCode != 0 {
			t.Fatalf("server.sh exited %d: %s", r.StatusCode, updContainerLogs(ctx, e.api, cr.ID))
		}
	case err := <-w.Error:
		t.Fatal(err)
	}
}

func (e *updEnv) handler(self update.Self, running string) tasks.Handler {
	// launchroomd's Docker is the install's dockerd-guard (D49): its socket
	// lives in the compose volume, which the test reaches on the dind's disk.
	guarded, err := client.New(client.WithHost("unix://" + guardVolumeSocket))
	if err != nil {
		panic(err)
	}
	return update.NewHandler(update.HandlerOptions{
		DataDir: filepath.Join(e.project, "data"), Version: running, Docker: guarded,
		Log:  slog.New(slog.NewTextHandler(os.Stderr, &slog.HandlerOptions{Level: slog.LevelInfo})),
		Self: func(context.Context) (update.Self, error) { return self, nil },
		// The supervisor is the real `launchroomd update-supervisor` of the
		// current (fake) image: the only supervisor shape the guard allows.
		HealthTimeout: 40 * time.Second, InstallTimeout: 5 * time.Minute,
	})
}

// self is the running launchroomd container of the install, as Discover sees it.
func (e *updEnv) self(ctx context.Context, t *testing.T) update.Self {
	t.Helper()
	f := client.Filters{}
	f.Add("label", "com.docker.compose.project=launchroomd")
	f.Add("label", "com.docker.compose.service=launchroomd")
	list, err := e.api.ContainerList(ctx, client.ContainerListOptions{Filters: f})
	if err != nil || len(list.Items) != 1 {
		t.Fatalf("launchroomd container: %v %v", len(list.Items), err)
	}
	s, err := update.InspectSelf(ctx, e.api, list.Items[0].ID, "/data")
	if err != nil {
		t.Fatal(err)
	}
	return s
}

func (e *updEnv) waitRunning(t *testing.T, version string) {
	t.Helper()
	deadline := time.Now().Add(time.Minute)
	for time.Now().Before(deadline) {
		var r update.Running
		if b, err := os.ReadFile(filepath.Join(e.project, "data", "update", "running.json")); err == nil && json.Unmarshal(b, &r) == nil && r.Version == version {
			return
		}
		time.Sleep(500 * time.Millisecond)
	}
	t.Fatalf("launchroomd %s did not start", version)
}

func (e *updEnv) assertNoUpdaters(ctx context.Context, t *testing.T) {
	t.Helper()
	f := client.Filters{}
	f.Add("label", "launchroom.role=updater")
	list, err := e.api.ContainerList(ctx, client.ContainerListOptions{All: true, Filters: f})
	if err != nil {
		t.Fatal(err)
	}
	if len(list.Items) != 0 {
		t.Fatalf("%d updater containers left behind", len(list.Items))
	}
}

func (e *updEnv) writeFile(t *testing.T, rel string, b []byte) {
	t.Helper()
	if err := os.WriteFile(filepath.Join(e.project, rel), b, 0o600); err != nil {
		t.Fatal(err)
	}
}

func (e *updEnv) readFile(t *testing.T, rel string) string {
	t.Helper()
	b, err := os.ReadFile(filepath.Join(e.project, rel))
	if err != nil {
		t.Fatal(err)
	}
	return string(b)
}

func updateTask(id, version, image string) state.Task {
	return state.Task{ID: state.TaskID(id), Type: state.TaskSelfUpdate, Attempt: 1,
		Spec: &state.SelfUpdateSpec{Version: state.Semver(version), Image: state.ImageRef(image)}}
}

func mustSigner(t *testing.T) *updatetest.Signer {
	t.Helper()
	s, err := updatetest.NewSigner()
	if err != nil {
		t.Fatal(err)
	}
	return s
}

func mustPull(ctx context.Context, t *testing.T, api *client.Client, ref string) {
	t.Helper()
	res, err := api.ImagePull(ctx, ref, client.ImagePullOptions{})
	if err != nil {
		t.Fatal(err)
	}
	defer func() { _ = res.Close() }()
	if err := res.Wait(ctx); err != nil {
		t.Fatalf("pull %s: %v", ref, err)
	}
}

func updWaitHTTP(t *testing.T, url string) {
	t.Helper()
	deadline := time.Now().Add(time.Minute)
	for time.Now().Before(deadline) {
		if res, err := http.Get(url); err == nil { //nolint:gosec,noctx // test
			_ = res.Body.Close()
			return
		}
		time.Sleep(300 * time.Millisecond)
	}
	t.Fatalf("%s did not answer", url)
}

func updContainerLogs(ctx context.Context, api *client.Client, id string) string {
	rc, err := api.ContainerLogs(ctx, id, client.ContainerLogsOptions{ShowStdout: true, ShowStderr: true})
	if err != nil {
		return err.Error()
	}
	defer func() { _ = rc.Close() }()
	b, _ := io.ReadAll(rc)
	return string(b)
}
