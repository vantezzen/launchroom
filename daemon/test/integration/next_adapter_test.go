//go:build integration

package integration

import (
	"bytes"
	"context"
	"crypto/rand"
	"io"
	"regexp"
	"slices"
	"strings"
	"testing"
	"time"

	"github.com/moby/moby/client"

	"github.com/vantezzen/launchroom/daemon/internal/build"
	"github.com/vantezzen/launchroom/daemon/internal/build/nextadapter"
	"github.com/vantezzen/launchroom/daemon/internal/build/presets"
	"github.com/vantezzen/launchroom/daemon/internal/health"
	"github.com/vantezzen/launchroom/daemon/internal/policy"
	"github.com/vantezzen/launchroom/daemon/internal/reconcile"
	"github.com/vantezzen/launchroom/daemon/internal/sealed"
	"github.com/vantezzen/launchroom/daemon/internal/secret"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// TestBuildNextAdapterAndRun builds the next-adapter fixture (Next 16.4,
// App Router, an ISR page, proxy/middleware, public/) through the real
// pipeline: Railpack plan + nextjs preset with the vendored deployment
// adapter, BuildKit, push to Zot. It runs the image through the reconciler
// and checks pages, public files and hashed static assets. The same commit
// built without the adapter (decision removed → `next start` with the full
// node_modules) must give a larger image.
func TestBuildNextAdapterAndRun(t *testing.T) {
	be := setupBuild(t)
	ctx, cancel := context.WithTimeout(t.Context(), 30*time.Minute)
	defer cancel()

	// 1. With the adapter.
	task := state.TaskID("tsk_01J9Z8X7W6V5T4S3R2Q1P0N9Q1")
	be.lease(task)
	sink := &captureSink{}
	start := time.Now()
	out, err := be.builder.Run(ctx, build.Job{Task: task, Inputs: be.inputs("next-adapter", state.BuilderAuto), Image: appImage, Sink: sink})
	logs := sink.all()
	if err != nil {
		t.Fatalf("build: %v\n%s", err, tail(logs, 120))
	}
	adapterBuild := time.Since(start)
	s := out.Summary
	t.Logf("adapter build: %s in %s; start=%q framework=%s", out.Image, adapterBuild.Round(time.Second), s.StartCommand, s.Framework)
	if len(s.Presets) != 1 || s.Presets[0].Name != "nextjs" || s.Presets[0].Version != 2 {
		t.Fatalf("presets %+v", s.Presets)
	}
	var ids []string
	for _, d := range s.Presets[0].Decisions {
		ids = append(ids, string(d.ID))
	}
	if !slices.Contains(ids, presets.NextAdapterDecision) || s.StartCommand != "node .next/standalone/server.js" {
		t.Fatalf("adapter not applied: decisions %v start %q", ids, s.StartCommand)
	}
	for _, want := range []string{"Applying modifyConfig from launchroom", "[launchroom] output: 'standalone'", "deploymentId: " + string(itDeployment), "Running onBuildComplete from launchroom", "[launchroom] standalone output:"} {
		if !strings.Contains(logs, want) {
			t.Errorf("build log lacks %q", want)
		}
	}
	if strings.Contains(string(s.Plan), "launchroom-next-finalize [projectDir]") {
		t.Error("the summary plan carries the vendored adapter source")
	}
	n := s.Next
	if n == nil {
		t.Fatalf("no next summary in the build summary\n%s", tail(logs, 60))
	}
	t.Logf("next summary: %+v", *n)
	if n.AdapterVersion != nextadapter.Version || n.NextVersion != "16.4.2" || n.DeploymentID != string(itDeployment) ||
		n.ServerEntry != ".next/standalone/server.js" || !n.Middleware || n.Routes.AppPages < 2 || n.Routes.AppRoutes < 1 ||
		!slices.Equal(n.ISRRoutes, []state.HTTPPath{"/isr"}) || n.CacheHandler {
		t.Fatalf("next summary %+v", *n)
	}
	done := state.TaskCompletion{TypeMeta: state.NewTypeMeta(state.KindTaskCompletion), Attempt: 1, Outcome: state.OutcomeSucceeded,
		Build: &state.BuildResult{Image: out.Image, DurationMs: out.Duration.Milliseconds(), Summary: &out.Summary}}
	if err := done.Validate(); err != nil {
		t.Fatalf("summary does not validate: %v", err)
	}

	// 2. Same commit without the adapter: Railpack's next start with full node_modules.
	plainTask := state.TaskID("tsk_01J9Z8X7W6V5T4S3R2Q1P0N9Q2")
	be.lease(plainTask)
	plainIn := be.inputs("next-adapter", state.BuilderAuto)
	plainIn.Deployment = "dpl_01J9Z8X7W6V5T4S3R2Q1P0N9Q3"
	plainIn.Builder.DisabledDecisions = []state.Slug{presets.NextAdapterDecision}
	plainSink := &captureSink{}
	start = time.Now()
	plain, err := be.builder.Run(ctx, build.Job{Task: plainTask, Inputs: plainIn, Image: appImage, Sink: plainSink})
	if err != nil {
		t.Fatalf("plain build: %v\n%s", err, tail(plainSink.all(), 80))
	}
	plainBuild := time.Since(start)
	if plain.Summary.Next != nil || plain.Summary.StartCommand == "node .next/standalone/server.js" {
		t.Fatalf("adapter still active with the decision removed: %+v", plain.Summary)
	}

	be.issuer.mu.Lock()
	be.issuer.nodeApps[state.AppRepository(itService)] = true
	be.issuer.mu.Unlock()
	be.tokens.Forget("")
	size := func(ref state.ImageRef) int64 {
		if err := pullFresh(ctx, be, string(ref)); err != nil {
			t.Fatal(err)
		}
		insp, err := be.api.ImageInspect(ctx, string(ref))
		if err != nil {
			t.Fatal(err)
		}
		return insp.Size
	}
	adapterSize, plainSize := size(out.Image), size(plain.Image)
	t.Logf("IMAGE SIZES: adapter (standalone) %.1f MB, without adapter (next start) %.1f MB, ratio %.2f; build times %s / %s",
		float64(adapterSize)/1e6, float64(plainSize)/1e6, float64(adapterSize)/float64(plainSize), adapterBuild.Round(time.Second), plainBuild.Round(time.Second))
	if adapterSize >= plainSize {
		t.Errorf("standalone image (%d bytes) is not smaller than the next start image (%d bytes)", adapterSize, plainSize)
	}
	if blob := imageBytes(t, be.api, string(out.Image)); bytes.Contains(blob, []byte("launchroom-next-finalize [projectDir]")) {
		t.Error("the vendored adapter reached the runtime image")
	}

	// 3. Run the adapter image: pages, proxy header, public/ and hashed static assets.
	ip := runBuiltImage(ctx, t, be, out.Image, "/api/health")
	base := "http://" + ip + ":3000"
	code, hdr, body, err := get(t, noRedirect, base+"/", "next")
	if err != nil || code != 200 || !strings.Contains(body, "Hello from launchroom (next-adapter)") {
		t.Fatalf("GET /: %d %v %.300q", code, err, body)
	}
	if hdr.Get("x-launchroom-proxy") != "1" {
		t.Errorf("proxy (middleware) did not run: headers %v", hdr)
	}
	m := regexp.MustCompile(`/_next/static/[^"?]+\.js`).FindString(body)
	if m == "" {
		t.Fatalf("no static chunk referenced in %.500q", body)
	}
	if !strings.Contains(body, "?dpl="+string(itDeployment)) {
		t.Errorf("assets are not tagged with the deploymentId")
	}
	if code, _, _, err := get(t, noRedirect, base+m, "next"); err != nil || code != 200 {
		t.Errorf("GET %s: %d %v", m, code, err)
	}
	if code, _, body, err := get(t, noRedirect, base+"/launchroom.txt", "next"); err != nil || code != 200 || !strings.Contains(body, "served from public/") {
		t.Errorf("GET /launchroom.txt: %d %v %q", code, err, body)
	}
	if code, hdr, body, err := get(t, noRedirect, base+"/isr", "next"); err != nil || code != 200 || !strings.Contains(body, "generated at") {
		t.Errorf("GET /isr: %d %v %.200q", code, err, body)
	} else {
		t.Logf("ISR page cache: %s", hdr.Get("x-nextjs-cache"))
	}
}

// runBuiltImage runs a built image as a serving workload on port 3000 via the
// reconciler and returns its container IP once healthy.
func runBuiltImage(ctx context.Context, t *testing.T, be *buildEnv, image state.ImageRef, healthPath state.HTTPPath) string {
	t.Helper()
	mon := health.NewMonitor(ctx, health.MonitorOptions{})
	t.Cleanup(mon.Close)
	rec := reconcile.New(reconcile.Options{
		ServerID: be.serverID, Docker: be.engine, Policy: policy.DefaultConfig(), Health: mon,
		EnvHashKey: reconcile.EnvHashKey(be.key.Bytes()),
		OpenEnv: func(s *state.WorkloadSpec) (map[string]secret.Secret, error) {
			return sealed.OpenWorkloadEnv(s.Env, be.key, be.serverID, s.ID)
		},
		StopGrace: 2 * time.Second,
	})
	wl := state.WorkloadID("wl_01J9Z8X7W6V5T4S3R2Q1P0N9Q4")
	netID := state.NetworkID("net_01J9Z8X7W6V5T4S3R2Q1P0N9Q5")
	// The build summary's runtime defaults, as the dashboard applies them (docs/04 §13).
	env := map[string]secret.Secret{"PORT": "3000", "HOSTNAME": "0.0.0.0", "NEXT_TELEMETRY_DISABLED": "1"}
	envSealed, err := sealed.SealWorkloadEnv(state.WorkloadEnv{ServerID: be.serverID, WorkloadID: wl, Env: env}, be.key.PublicKey(), rand.Reader)
	if err != nil {
		t.Fatal(err)
	}
	port := state.Port(3000)
	ns := &state.NodeState{
		TypeMeta: state.NewTypeMeta(state.KindNodeState), ServerID: be.serverID, Version: 1, IssuedAt: time.Now().UTC(),
		Networks: []state.NetworkSpec{{ID: netID, Name: "lr-e_itest-next", Subnet: "10.231.252.0/24"}},
		Volumes:  []state.VolumeSpec{}, Ambassadors: []state.AmbassadorSpec{}, Routes: []state.RouteSpec{},
		Firewall: state.FirewallSpec{MeshAllow: []state.MeshAllowRule{}},
		Workloads: []state.WorkloadSpec{{
			ID: wl, Service: itService, Environment: "env_01J9Z8X7W6V5T4S3R2Q1P0N9N4", Deployment: itDeployment,
			Image: image, Env: envSealed, Port: &port,
			Health:    &state.HealthSpec{Type: state.HealthHTTP, Path: healthPath, StartPeriod: "60s", Interval: "2s", Timeout: "2s", FailureThreshold: 3},
			Resources: state.Resources{CPUs: 1, MemoryMB: 512, Pids: 512},
			Networks:  []state.NetworkAttachment{{Network: netID, Aliases: []state.Slug{"next"}}},
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
			logs := ""
			if st.ContainerID != "" {
				if rc, err := be.api.ContainerLogs(ctx, st.ContainerID, client.ContainerLogsOptions{ShowStdout: true, ShowStderr: true}); err == nil {
					b, _ := io.ReadAll(rc)
					_ = rc.Close()
					logs = string(b)
				}
			}
			t.Fatalf("built image never became healthy: %+v\n%s", st, tail(logs, 40))
		}
		time.Sleep(time.Second)
	}
	insp, err := be.api.ContainerInspect(ctx, st.ContainerID, client.ContainerInspectOptions{})
	if err != nil {
		t.Fatal(err)
	}
	return insp.Container.NetworkSettings.Networks["lr-e_itest-next"].IPAddress.String()
}
