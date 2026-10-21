//go:build integration

package integration

import (
	"archive/tar"
	"bytes"
	"compress/gzip"
	"context"
	"crypto/ecdsa"
	"crypto/elliptic"
	"crypto/rand"
	"crypto/sha256"
	"crypto/x509"
	"embed"
	"encoding/base64"
	"encoding/json"
	"encoding/pem"
	"errors"
	"fmt"
	"io"
	"io/fs"
	"log/slog"
	"net"
	"net/http"
	"net/netip"
	"os"
	"strings"
	"sync"
	"testing"
	"time"

	"github.com/moby/moby/api/types/container"
	"github.com/moby/moby/api/types/mount"
	"github.com/moby/moby/client"

	"github.com/vantezzen/launchroom/daemon/internal/build"
	"github.com/vantezzen/launchroom/daemon/internal/build/buildkitd"
	"github.com/vantezzen/launchroom/daemon/internal/docker"
	"github.com/vantezzen/launchroom/daemon/internal/health"
	"github.com/vantezzen/launchroom/daemon/internal/policy"
	"github.com/vantezzen/launchroom/daemon/internal/reconcile"
	"github.com/vantezzen/launchroom/daemon/internal/registry"
	"github.com/vantezzen/launchroom/daemon/internal/sealed"
	"github.com/vantezzen/launchroom/daemon/internal/secret"
	"github.com/vantezzen/launchroom/daemon/internal/state"
	"github.com/vantezzen/launchroom/daemon/test/gitserve"
)

//go:embed testdata/build
var buildFixtures embed.FS

// zotImage is the registry pinned for the dev stack (dev/compose.yml).
const zotImage = "ghcr.io/project-zot/zot@sha256:96cda11459ce6f8c60da3b03f03e9f67fd11b3ae2d82c4c04c92b3c608996080"

// buildkitImage is the rootless BuildKit the daemon pins, pulled THROUGH the
// Zot mirror (127.0.0.1:5000/mirror/docker.io/…) with a dashboard-style token.
const buildkitImage = state.ImageRef("127.0.0.1:5000/mirror/docker.io/moby/buildkit@" + policy.BuildkitImageDigest)

// ---- fake control plane: registry token issuer ----

// tokenIssuer mints Docker token-spec JWTs (ES256) like the dashboard's
// RegistryTokenIssuer and applies the same scope rules for one server.
type tokenIssuer struct {
	key      *ecdsa.PrivateKey
	mu       sync.Mutex
	leased   map[state.TaskID]state.ServiceID // build tasks leased by this server
	nodeApps map[state.RegistryRepository]bool
	issued   []state.RegistryTokenRequest
}

func newTokenIssuer(t *testing.T) *tokenIssuer {
	t.Helper()
	k, err := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	if err != nil {
		t.Fatal(err)
	}
	return &tokenIssuer{key: k, leased: map[state.TaskID]state.ServiceID{}, nodeApps: map[state.RegistryRepository]bool{}}
}

func (ti *tokenIssuer) publicPEM(t *testing.T) []byte {
	der, err := x509.MarshalPKIXPublicKey(&ti.key.PublicKey)
	if err != nil {
		t.Fatal(err)
	}
	return pem.EncodeToMemory(&pem.Block{Type: "PUBLIC KEY", Bytes: der})
}

func (ti *tokenIssuer) allowed(req *state.RegistryTokenRequest) error {
	for _, s := range req.Scopes {
		repo := string(s.Repository)
		for _, a := range s.Actions {
			switch {
			case strings.HasPrefix(repo, "mirror/") && a == state.RegistryPull:
			case a == state.RegistryPull && ti.nodeApps[s.Repository]:
			case req.Task != "" && ti.leased[req.Task] != "" &&
				(s.Repository == state.AppRepository(ti.leased[req.Task]) || s.Repository == state.CacheRepository(ti.leased[req.Task])):
			default:
				return fmt.Errorf("scope %s:%s denied", repo, a)
			}
		}
	}
	return nil
}

func (ti *tokenIssuer) RegistryToken(_ context.Context, req *state.RegistryTokenRequest) (*state.RegistryToken, error) {
	ti.mu.Lock()
	defer ti.mu.Unlock()
	if err := req.Validate(); err != nil {
		return nil, err
	}
	if err := ti.allowed(req); err != nil {
		return nil, err
	}
	ti.issued = append(ti.issued, *req)
	now := time.Now()
	access := make([]map[string]any, 0, len(req.Scopes))
	for _, s := range req.Scopes {
		access = append(access, map[string]any{"type": "repository", "name": string(s.Repository), "actions": s.Actions})
	}
	b64 := base64.RawURLEncoding.EncodeToString
	h, _ := json.Marshal(map[string]any{"alg": "ES256", "typ": "JWT"})
	c, _ := json.Marshal(map[string]any{"iss": "launchroom-itest", "sub": "srv", "aud": "launchroom-registry", "iat": now.Unix(),
		"nbf": now.Unix() - 5, "exp": now.Add(5 * time.Minute).Unix(), "access": access})
	in := b64(h) + "." + b64(c)
	sum := sha256.Sum256([]byte(in))
	r, s, err := ecdsa.Sign(rand.Reader, ti.key, sum[:])
	if err != nil {
		return nil, err
	}
	sig := make([]byte, 64)
	r.FillBytes(sig[:32])
	s.FillBytes(sig[32:])
	return &state.RegistryToken{
		TypeMeta: state.NewTypeMeta(state.KindRegistryToken), Token: secret.Secret(in + "." + b64(sig)),
		ExpiresAt: now.Add(5 * time.Minute), Scopes: req.Scopes,
	}, nil
}

// ---- environment ----

type buildEnv struct {
	*env
	issuer  *tokenIssuer
	tokens  *registry.Tokens
	engine  *docker.Engine // with registry auth
	zotAddr string
	bk      *buildkitd.Manager
	builder *build.Builder
	gitURL  string
	repos   map[string]gitserve.Repo
	zotID   string
}

func tarFiles(files map[string][]byte) io.Reader {
	var buf bytes.Buffer
	tw := tar.NewWriter(&buf)
	_ = tw.WriteHeader(&tar.Header{Typeflag: tar.TypeDir, Name: "keys/", Mode: 0o755, ModTime: time.Unix(0, 0)})
	for name, data := range files {
		_ = tw.WriteHeader(&tar.Header{Name: name, Mode: 0o644, Size: int64(len(data)), ModTime: time.Unix(0, 0)})
		_, _ = tw.Write(data)
	}
	_ = tw.Close()
	return &buf
}

// startZot runs a Zot with the dev stack's auth, sync and retention config
// (dev/zot/config.json) inside the test daemon and returns its address.
func startZot(t *testing.T, api *client.Client, issuer *tokenIssuer) (addr string, id string) {
	t.Helper()
	return startZotAs(t, api, issuer, "lr-itest-zot", "lr-itest-zot-cache")
}

// startZotAs is startZot with its own container name and storage volume.
func startZotAs(t *testing.T, api *client.Client, issuer *tokenIssuer, name, volume string) (addr string, id string) {
	t.Helper()
	ctx := t.Context()
	pull, err := api.ImagePull(ctx, zotImage, client.ImagePullOptions{})
	if err != nil {
		t.Fatal(err)
	}
	if err := pull.Wait(ctx); err != nil {
		t.Fatal(err)
	}
	_ = pull.Close()
	cfg, err := os.ReadFile("/opt/launchroom/bin/zot-config.json")
	if err != nil {
		t.Fatalf("zot config (copied next to the test binary by make test-daemon-integration): %v", err)
	}
	labels := docker.ManagedLabels("srv_01J9Z8X7W6V5T4S3R2Q1P0ZZZZ")
	labels["launchroom.dev"] = "true"
	cr, err := api.ContainerCreate(ctx, client.ContainerCreateOptions{
		Name:   name,
		Config: &container.Config{Image: zotImage, Labels: labels},
		HostConfig: &container.HostConfig{
			// Not managed-labelled: survives the per-test sweep so mirrored images stay cached.
			Mounts: []mount.Mount{{Type: mount.TypeVolume, Source: volume, Target: "/var/lib/registry"}},
		},
	})
	if err != nil {
		t.Fatal(err)
	}
	if _, err := api.CopyToContainer(ctx, cr.ID, client.CopyToContainerOptions{DestinationPath: "/etc/zot", Content: tarFiles(map[string][]byte{
		"config.json": cfg, "keys/registry-token.pub.pem": issuer.publicPEM(t),
	})}); err != nil {
		t.Fatal(err)
	}
	if _, err := api.ContainerStart(ctx, cr.ID, client.ContainerStartOptions{}); err != nil {
		t.Fatal(err)
	}
	insp, err := api.ContainerInspect(ctx, cr.ID, client.ContainerInspectOptions{})
	if err != nil {
		t.Fatal(err)
	}
	var ip netip.Addr
	for _, n := range insp.Container.NetworkSettings.Networks {
		ip = n.IPAddress
	}
	addr = netip.AddrPortFrom(ip, 5000).String()
	deadline := time.Now().Add(60 * time.Second)
	for {
		resp, err := http.Get("http://" + addr + "/v2/") //nolint:noctx // test readiness probe
		if err == nil {
			_ = resp.Body.Close()
			if resp.StatusCode == http.StatusUnauthorized {
				return addr, cr.ID
			}
		}
		if time.Now().After(deadline) {
			t.Fatalf("zot not ready: %v", err)
		}
		time.Sleep(500 * time.Millisecond)
	}
}

func setupBuild(t *testing.T) *buildEnv {
	t.Helper()
	e := setup(t)
	ctx := t.Context()
	be := &buildEnv{env: e, issuer: newTokenIssuer(t), repos: map[string]gitserve.Repo{}}
	_, _ = e.api.ContainerRemove(ctx, "lr-itest-zot", client.ContainerRemoveOptions{Force: true})
	be.zotAddr, be.zotID = startZot(t, e.api, be.issuer)
	t.Cleanup(func() {
		_, _ = e.api.ContainerRemove(context.Background(), be.zotID, client.ContainerRemoveOptions{Force: true})
	})

	be.tokens = registry.NewTokens(be.issuer)
	var err error
	be.engine, err = docker.NewEngine(docker.EngineOptions{Host: engineHost, ServerID: string(e.serverID), UserAgent: "launchroom-itest", Auth: be.tokens.PullAuth(registry.DefaultAddress)})
	if err != nil {
		t.Fatal(err)
	}
	fctx, cancel := context.WithCancel(context.Background())
	t.Cleanup(cancel)
	serve := func(addr string) {
		f := registry.NewForwarder(addr, be.zotAddr, slog.Default())
		var lc net.ListenConfig
		l, err := lc.Listen(ctx, "tcp", addr)
		if err != nil {
			t.Fatalf("forwarder %s: %v", addr, err)
		}
		go func() { _ = f.ServeListener(fctx, l) }()
	}
	serve(registry.DefaultAddress)

	log := slog.New(slog.NewTextHandler(os.Stderr, &slog.HandlerOptions{Level: slog.LevelInfo}))
	be.bk = buildkitd.New(buildkitd.Options{
		ServerID: string(e.serverID), Docker: be.engine, Ops: be.engine, Policy: policy.DefaultConfig(),
		MaxParallelism: 4, MemoryMB: 4096, Log: log, DialTimeout: 2 * time.Minute,
	})
	sctx, scancel := context.WithTimeout(ctx, 5*time.Minute)
	defer scancel()
	if err := be.bk.Sync(sctx, state.SystemContainer{Enabled: true, Image: buildkitImage}); err != nil {
		t.Fatalf("buildkitd: %v", err)
	}
	// The build network now exists; buildkitd reaches the registry at its gateway.
	serve(netip.AddrPortFrom(be.bk.Gateway(), buildkitd.RegistryPort).String())

	// Fixture repositories over git smart HTTP (plain http allowed for 127.0.0.1 only).
	root := t.TempDir()
	sub, _ := fs.Sub(buildFixtures, "testdata/build")
	dirs, _ := fs.ReadDir(sub, ".")
	for _, d := range dirs {
		fsys, _ := fs.Sub(sub, d.Name())
		r, err := gitserve.Publish(ctx, root, d.Name(), fsys)
		if err != nil {
			t.Fatal(err)
		}
		be.repos[d.Name()] = r
	}
	h, err := gitserve.Handler(root)
	if err != nil {
		t.Fatal(err)
	}
	var glc net.ListenConfig
	gl, err := glc.Listen(ctx, "tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	srv := &http.Server{Handler: h, ReadHeaderTimeout: 10 * time.Second}
	go func() { _ = srv.Serve(gl) }()
	t.Cleanup(func() { _ = srv.Close() })
	be.gitURL = "http://" + gl.Addr().String()

	be.builder = build.New(build.Options{
		BuildKit: be.bk.Client, Tokens: be.tokens, RegistryHost: buildkitd.RegistryHost(), PublicRegistry: registry.DefaultAddress,
		WorkDir: t.TempDir(), InsecureGitHosts: []string{"127.0.0.1"}, Log: log,
	})
	return be
}

// captureSink records build log lines.
type captureSink struct {
	mu    sync.Mutex
	lines []string
	steps map[string]int
}

func (c *captureSink) Line(step string, stream state.LogStream, text string) {
	c.mu.Lock()
	defer c.mu.Unlock()
	if c.steps == nil {
		c.steps = map[string]int{}
	}
	c.steps[step]++
	c.lines = append(c.lines, "["+step+"] "+text)
}

func (c *captureSink) all() string {
	c.mu.Lock()
	defer c.mu.Unlock()
	return strings.Join(c.lines, "\n")
}

const (
	itService    = state.ServiceID("svc_01J9Z8X7W6V5T4S3R2Q1P0N9N2")
	itDeployment = state.DeploymentID("dpl_01J9Z8X7W6V5T4S3R2Q1P0N9N5")
)

func (be *buildEnv) inputs(fixture string, kind state.BuilderKind) state.BuildInputs {
	return state.BuildInputs{
		Service: itService, Environment: "env_01J9Z8X7W6V5T4S3R2Q1P0N9N4", Deployment: itDeployment,
		Repo:     state.RepoSpec{Provider: state.GitGeneric, CloneURL: state.GitURL(be.gitURL + "/" + fixture + ".git"), SHA: state.GitSHA(be.repos[fixture].SHA)},
		Builder:  state.BuilderSpec{Kind: kind, RailpackVersion: state.Semver(build.RailpackVersion)},
		Platform: state.Platform("linux/" + hostArch()), CacheKey: "svc_01j9z8x7w6v5t4s3r2q1p0n9n2",
	}
}

func hostArch() string {
	if b, err := os.ReadFile("/proc/sys/kernel/arch"); err == nil && strings.Contains(string(b), "aarch64") {
		return "arm64"
	}
	return "amd64"
}

func (be *buildEnv) lease(task state.TaskID) {
	be.issuer.mu.Lock()
	be.issuer.leased[task] = itService
	be.issuer.mu.Unlock()
}

var appImage = state.ImageRepository(registry.DefaultAddress + "/" + string(state.AppRepository(itService)))

func TestBuildRailpackNodeHelloAndRun(t *testing.T) {
	be := setupBuild(t)
	ctx, cancel := context.WithTimeout(t.Context(), 20*time.Minute)
	defer cancel()

	// Plan first ("we detected…"): no push, no BuildKit.
	plan, err := be.builder.Run(ctx, build.Job{Task: "tsk_01J9Z8X7W6V5T4S3R2Q1P0N9P1", Inputs: be.inputs("node-hello", state.BuilderAuto), Sink: &captureSink{}})
	if err != nil {
		t.Fatalf("plan: %v", err)
	}
	ps := plan.Summary
	t.Logf("plan summary: builder=%s reason=%s provider=%s framework=%s start=%q port=%v presets=%+v packages=%+v",
		ps.Builder, ps.BuilderReason, ps.Provider, ps.Framework, ps.StartCommand, *ps.Port, ps.Presets, ps.Packages)
	if ps.Builder != state.BuilderRailpack || ps.Provider != "node" || ps.Port == nil || *ps.Port != 3000 ||
		len(ps.Presets) != 1 || ps.Presets[0].Name != "node-server" || ps.StartCommand == "" || len(ps.Plan) == 0 {
		t.Fatalf("unexpected plan summary: %+v", ps)
	}

	// Build + push.
	task := state.TaskID("tsk_01J9Z8X7W6V5T4S3R2Q1P0N9P2")
	be.lease(task)
	sink := &captureSink{}
	out, err := be.builder.Run(ctx, build.Job{Task: task, Inputs: be.inputs("node-hello", state.BuilderAuto), Image: appImage, Sink: sink})
	t.Logf("build log steps: %v", sink.steps)
	if err != nil {
		t.Fatalf("build: %v\n%s", err, tail(sink.all(), 80))
	}
	t.Logf("built %s in %s (attestations %v)", out.Image, out.Duration, out.Attestations)
	if !strings.HasPrefix(string(out.Image), string(appImage)+"@sha256:") {
		t.Fatalf("image ref %s", out.Image)
	}
	for _, step := range []string{"clone", "plan", "push"} {
		if sink.steps[step] == 0 {
			t.Errorf("no log lines for step %q (steps %v)", step, sink.steps)
		}
	}

	// The Railpack frontend came through the Zot mirror (buildkitd registry mirror config).
	if logs := containerLogs(t, be.api, be.zotID); !strings.Contains(logs, "railpack-frontend") {
		t.Errorf("zot never synced the railpack frontend (mirror not used?)")
	}

	// Run the built image through the reconciler: pull with a node-scoped token, health probe.
	be.issuer.mu.Lock()
	be.issuer.nodeApps[state.AppRepository(itService)] = true
	be.issuer.mu.Unlock()
	mon := health.NewMonitor(ctx, health.MonitorOptions{})
	defer mon.Close()
	rec := reconcile.New(reconcile.Options{
		ServerID: be.serverID, Docker: be.engine, Policy: policy.DefaultConfig(), Health: mon,
		EnvHashKey: reconcile.EnvHashKey(be.key.Bytes()),
		OpenEnv: func(s *state.WorkloadSpec) (map[string]secret.Secret, error) {
			return sealed.OpenWorkloadEnv(s.Env, be.key, be.serverID, s.ID)
		},
		StopGrace: 2 * time.Second,
	})
	wl := state.WorkloadID("wl_01J9Z8X7W6V5T4S3R2Q1P0N9N7")
	netID := state.NetworkID("net_01J9Z8X7W6V5T4S3R2Q1P0N9N0")
	envSealed, err := sealed.SealWorkloadEnv(state.WorkloadEnv{ServerID: be.serverID, WorkloadID: wl, Env: map[string]secret.Secret{"PORT": "3000"}}, be.key.PublicKey(), rand.Reader)
	if err != nil {
		t.Fatal(err)
	}
	port := state.Port(3000)
	ns := &state.NodeState{
		TypeMeta: state.NewTypeMeta(state.KindNodeState), ServerID: be.serverID, Version: 1, IssuedAt: time.Now().UTC(),
		Networks: []state.NetworkSpec{{ID: netID, Name: "lr-e_itest-build", Subnet: "10.231.251.0/24"}},
		Volumes:  []state.VolumeSpec{}, Ambassadors: []state.AmbassadorSpec{}, Routes: []state.RouteSpec{},
		Firewall: state.FirewallSpec{MeshAllow: []state.MeshAllowRule{}},
		Workloads: []state.WorkloadSpec{{
			ID: wl, Service: itService, Environment: "env_01J9Z8X7W6V5T4S3R2Q1P0N9N4", Deployment: itDeployment,
			Image: out.Image, Env: envSealed, Port: &port,
			Health:    &state.HealthSpec{Type: state.HealthHTTP, Path: "/health", StartPeriod: "60s", Interval: "1s", Timeout: "1s", FailureThreshold: 3},
			Resources: state.Resources{CPUs: 1, MemoryMB: 256, Pids: 256},
			Networks:  []state.NetworkAttachment{{Network: netID, Aliases: []state.Slug{"hello"}}},
			Restart:   state.RestartUnlessStopped, Role: state.RoleServing,
		}},
	}
	if err := ns.Validate(); err != nil {
		t.Fatal(err)
	}
	deadline := time.Now().Add(5 * time.Minute)
	var st state.WorkloadStatus
	for {
		if err := rec.Reconcile(ctx, ns); err != nil {
			t.Logf("reconcile: %v", err)
		}
		st = status(rec, wl)
		if st.Phase == state.PhaseHealthy {
			break
		}
		if time.Now().After(deadline) {
			t.Fatalf("built image never became healthy: %+v", st)
		}
		time.Sleep(time.Second)
	}
	insp, err := be.api.ContainerInspect(ctx, st.ContainerID, client.ContainerInspectOptions{})
	if err != nil {
		t.Fatal(err)
	}
	ip := insp.Container.NetworkSettings.Networks["lr-e_itest-build"].IPAddress
	code, _, body, err := get(t, noRedirect, "http://"+ip.String()+":3000/", "hello")
	if err != nil || code != 200 || !strings.Contains(body, "from launchroom") {
		t.Fatalf("app: %d %q %v", code, body, err)
	}
	// Pull tokens were scoped to exactly the app repository, without a task.
	foundPull := false
	for _, r := range be.issuer.issued {
		if r.Task == "" && len(r.Scopes) == 1 && r.Scopes[0].Repository == state.AppRepository(itService) {
			foundPull = len(r.Scopes[0].Actions) == 1 && r.Scopes[0].Actions[0] == state.RegistryPull
		}
	}
	if !foundPull {
		t.Errorf("no app-scoped pull token issued: %+v", be.issuer.issued)
	}
}

func TestBuildDockerfileSecretsAndFailures(t *testing.T) {
	be := setupBuild(t)
	ctx, cancel := context.WithTimeout(t.Context(), 15*time.Minute)
	defer cancel()
	const secretValue = "s3cr3t-Value_with/special+chars"
	task := state.TaskID("tsk_01J9Z8X7W6V5T4S3R2Q1P0N9P3")
	be.lease(task)

	in := be.inputs("dockerfile-secret", state.BuilderAuto)
	in.BuildArgs = []state.EnvName{"GREETING"}
	sink := &captureSink{}
	out, err := be.builder.Run(ctx, build.Job{
		Task: task, Inputs: in, Image: appImage, Sink: sink,
		Env: map[string]secret.Secret{"BUILD_SECRET": secretValue, "GREETING": "hello-from-build-arg"},
	})
	logs := sink.all()
	if err != nil {
		t.Fatalf("build: %v\n%s", err, tail(logs, 80))
	}
	if out.Summary.Builder != state.BuilderDockerfile || out.Summary.BuilderReason != state.ReasonDockerfileFound || out.Summary.Port == nil || *out.Summary.Port != 8080 {
		t.Fatalf("summary %+v", out.Summary)
	}
	if out.SecretsHash == "" {
		t.Error("no secrets hash")
	}

	// Masking: raw, base64 forms never reach the log; the masked line does.
	for _, form := range []string{secretValue, base64.StdEncoding.EncodeToString([]byte(secretValue))} {
		if strings.Contains(logs, form) {
			t.Fatalf("secret form %q leaked into the build log", form)
		}
	}
	if !strings.Contains(logs, "token is ***") || !strings.Contains(logs, "b64 ***") {
		t.Errorf("masked lines missing:\n%s", tail(logs, 60))
	}

	// The secret is in no layer, config or history; the build arg is.
	if err := be.engine.PullImage(ctx, string(out.Image)); err == nil {
		t.Fatal("pull without a node token must be refused (scope)")
	}
	be.issuer.mu.Lock()
	be.issuer.nodeApps[state.AppRepository(itService)] = true
	be.issuer.mu.Unlock()
	be.tokens.Forget("")
	if err := pullFresh(ctx, be, string(out.Image)); err != nil {
		t.Fatal(err)
	}
	blob := imageBytes(t, be.api, string(out.Image))
	if bytes.Contains(blob, []byte(secretValue)) {
		t.Fatal("build secret found in the image (layers, config or history)")
	}
	if !bytes.Contains(blob, []byte("hello-from-build-arg")) {
		t.Fatal("sanity: declared build arg not found in the image; the layer scan is not working")
	}
	hist, err := be.api.ImageHistory(ctx, string(out.Image))
	if err != nil {
		t.Fatal(err)
	}
	for _, h := range hist.Items {
		if strings.Contains(h.CreatedBy, secretValue) {
			t.Fatalf("secret in history: %s", h.CreatedBy)
		}
	}

	// A failing RUN is a build_error with a known-error hint.
	task2 := state.TaskID("tsk_01J9Z8X7W6V5T4S3R2Q1P0N9P4")
	be.lease(task2)
	sink2 := &captureSink{}
	_, err = be.builder.Run(ctx, build.Job{Task: task2, Inputs: be.inputs("dockerfile-broken", state.BuilderDockerfile), Image: appImage, Sink: sink2})
	var bErr *build.Error
	if !errors.As(err, &bErr) || bErr.Class != state.FailureBuild {
		t.Fatalf("broken build: %v", err)
	}
	if f := bErr.Failure(); len(f.Hints) == 0 || f.Hints[0].Code != "pnpm-outdated-lockfile" || f.Summary == nil {
		t.Errorf("failure %+v", f)
	}

	// A push for a task that is not leased gets no token: the build fails at push.
	task3 := state.TaskID("tsk_01J9Z8X7W6V5T4S3R2Q1P0N9P5")
	_, err = be.builder.Run(ctx, build.Job{Task: task3, Inputs: be.inputs("dockerfile-secret", state.BuilderDockerfile), Image: appImage, Sink: &captureSink{}})
	if err == nil {
		t.Fatal("unleased task could push")
	}

	// Plan-only for a Next.js app: preset decisions without building.
	plan, err := be.builder.Run(ctx, build.Job{Task: "tsk_01J9Z8X7W6V5T4S3R2Q1P0N9P6", Inputs: be.inputs("next-standalone", state.BuilderAuto), Sink: &captureSink{}})
	if err != nil {
		t.Fatalf("next plan: %v", err)
	}
	if plan.Summary.Framework != "next" || len(plan.Summary.Presets) != 1 || plan.Summary.Presets[0].Name != "nextjs" ||
		plan.Summary.StartCommand != "node .next/standalone/server.js" || plan.Summary.RuntimeEnv["HOSTNAME"] != "0.0.0.0" {
		t.Fatalf("next plan summary %+v", plan.Summary)
	}
	if !strings.Contains(string(plan.Summary.Plan), ".next/standalone") {
		t.Error("standalone overlay not in the plan")
	}
}

func pullFresh(ctx context.Context, be *buildEnv, ref string) error {
	_, _ = be.api.ImageRemove(ctx, ref, client.ImageRemoveOptions{Force: true})
	return be.engine.PullImage(ctx, ref)
}

// imageBytes returns every byte of the image as `docker save` exports it,
// with gzip-compressed blobs decompressed.
func imageBytes(t *testing.T, api *client.Client, ref string) []byte {
	t.Helper()
	rc, err := api.ImageSave(t.Context(), []string{ref})
	if err != nil {
		t.Fatal(err)
	}
	defer func() { _ = rc.Close() }()
	var all bytes.Buffer
	tr := tar.NewReader(rc)
	for {
		h, err := tr.Next()
		if errors.Is(err, io.EOF) {
			break
		}
		if err != nil {
			t.Fatal(err)
		}
		data, err := io.ReadAll(tr)
		if err != nil {
			t.Fatal(err)
		}
		all.Write([]byte(h.Name))
		all.Write(data)
		if len(data) > 2 && data[0] == 0x1f && data[1] == 0x8b {
			if zr, err := gzip.NewReader(bytes.NewReader(data)); err == nil {
				if plain, err := io.ReadAll(zr); err == nil {
					all.Write(plain)
				}
			}
		}
	}
	return all.Bytes()
}

func containerLogs(t *testing.T, api *client.Client, id string) string {
	t.Helper()
	rc, err := api.ContainerLogs(t.Context(), id, client.ContainerLogsOptions{ShowStdout: true, ShowStderr: true})
	if err != nil {
		t.Fatal(err)
	}
	defer func() { _ = rc.Close() }()
	b, _ := io.ReadAll(rc)
	return string(b)
}

func tail(s string, n int) string {
	lines := strings.Split(s, "\n")
	if len(lines) > n {
		lines = lines[len(lines)-n:]
	}
	return strings.Join(lines, "\n")
}
