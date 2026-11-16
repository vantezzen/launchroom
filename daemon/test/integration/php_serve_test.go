//go:build integration

package integration

import (
	"context"
	"crypto/rand"
	"encoding/json"
	"net/http"
	"strings"
	"testing"
	"time"

	"github.com/moby/moby/client"

	"github.com/vantezzen/launchroom/daemon/internal/build"
	"github.com/vantezzen/launchroom/daemon/internal/docker"
	"github.com/vantezzen/launchroom/daemon/internal/health"
	"github.com/vantezzen/launchroom/daemon/internal/policy"
	"github.com/vantezzen/launchroom/daemon/internal/reconcile"
	"github.com/vantezzen/launchroom/daemon/internal/sealed"
	"github.com/vantezzen/launchroom/daemon/internal/secret"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// A plain PHP app built by Railpack: PHP sees HTTPS when the ingress says the
// request came in over HTTPS, and FrankenPHP doesn't log every request at
// debug level (docs/04 §3.2).
func TestBuildPhpServesBehindIngress(t *testing.T) {
	be := setupBuild(t)
	ctx, cancel := context.WithTimeout(t.Context(), 20*time.Minute)
	defer cancel()

	task := state.TaskID("tsk_01J9Z8X7W6V5T4S3R2Q1P0N9Q1")
	be.lease(task)
	sink := &captureSink{}
	out, err := be.builder.Run(ctx, build.Job{Task: task, Inputs: be.inputs("php-plain", state.BuilderAuto), Image: appImage, Sink: sink})
	if err != nil {
		t.Fatalf("build: %v\n%s", err, tail(sink.all(), 120))
	}
	if out.Summary.Provider != "php" || !strings.Contains(sink.all(), "Adjusted the PHP server for launchroom") {
		t.Fatalf("provider %q, warnings %+v\n%s", out.Summary.Provider, out.Summary.Warnings, tail(sink.all(), 40))
	}

	be.issuer.mu.Lock()
	be.issuer.nodeApps[state.AppRepository(itService)] = true
	be.issuer.mu.Unlock()
	mon := health.NewMonitor(ctx, health.MonitorOptions{})
	defer mon.Close()
	rec := reconcile.New(reconcile.Options{
		ServerID: be.serverID, Docker: be.engine, Policy: policy.DefaultConfig(), Health: mon,
		EnvHashKey: docker.EnvHashKey(be.key.Bytes()),
		OpenEnv: func(s *state.WorkloadSpec) (map[string]secret.Secret, error) {
			return sealed.OpenWorkloadEnv(s.Env, be.key, be.serverID, s.ID)
		},
		StopGrace: 2 * time.Second,
	})
	wl := state.WorkloadID("wl_01J9Z8X7W6V5T4S3R2Q1P0N9Q2")
	netID := state.NetworkID("net_01J9Z8X7W6V5T4S3R2Q1P0N9Q3")
	envSealed, err := sealed.SealWorkloadEnv(state.WorkloadEnv{ServerID: be.serverID, WorkloadID: wl, Env: map[string]secret.Secret{"PORT": "8080"}}, be.key.PublicKey(), rand.Reader)
	if err != nil {
		t.Fatal(err)
	}
	port := state.Port(8080)
	ns := &state.NodeState{
		TypeMeta: state.NewTypeMeta(state.KindNodeState), ServerID: be.serverID, Version: 1, IssuedAt: time.Now().UTC(),
		Networks: []state.NetworkSpec{{ID: netID, Name: "lr-e_itest-phps", Subnet: "10.231.253.0/24"}},
		Volumes:  []state.VolumeSpec{}, Ambassadors: []state.AmbassadorSpec{}, Routes: []state.RouteSpec{},
		Firewall: state.FirewallSpec{MeshAllow: []state.MeshAllowRule{}},
		Workloads: []state.WorkloadSpec{{
			ID: wl, Service: itService, Environment: "env_01J9Z8X7W6V5T4S3R2Q1P0N9N4", Deployment: itDeployment,
			Image: out.Image, Env: envSealed, Port: &port,
			Health:    &state.HealthSpec{Type: state.HealthHTTP, Path: "/", StartPeriod: "60s", Interval: "2s", Timeout: "2s", FailureThreshold: 5},
			Resources: state.Resources{CPUs: 1, MemoryMB: 256, Pids: 256},
			Networks:  []state.NetworkAttachment{{Network: netID, Aliases: []state.Slug{"api"}}},
			Restart:   state.RestartUnlessStopped, Role: state.RoleServing,
			Capabilities: []state.Capability{"NET_BIND_SERVICE"},
		}},
	}
	if err := ns.Validate(); err != nil {
		t.Fatal(err)
	}
	deadline := time.Now().Add(3 * time.Minute)
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
				logs = containerLogs(t, be.api, st.ContainerID)
			}
			t.Fatalf("the PHP image never became healthy: %+v\n%s", st, tail(logs, 60))
		}
		time.Sleep(time.Second)
	}
	insp, err := be.api.ContainerInspect(ctx, st.ContainerID, client.ContainerInspectOptions{})
	if err != nil {
		t.Fatal(err)
	}
	url := "http://" + insp.Container.NetworkSettings.Networks["lr-e_itest-phps"].IPAddress.String() + ":8080/"

	type scheme struct {
		Set   bool    `json:"set"`
		HTTPS *string `json:"https"`
	}
	ask := func(proto string) scheme {
		t.Helper()
		req, err := http.NewRequestWithContext(ctx, http.MethodGet, url, nil)
		if err != nil {
			t.Fatal(err)
		}
		if proto != "" {
			req.Header.Set("X-Forwarded-Proto", proto)
		}
		resp, err := noRedirect.Do(req) //nolint:gosec // G704: the test's own container
		if err != nil {
			t.Fatal(err)
		}
		defer func() { _ = resp.Body.Close() }()
		var got scheme
		if err := json.NewDecoder(resp.Body).Decode(&got); err != nil || resp.StatusCode != http.StatusOK {
			t.Fatalf("%s: %d %v", proto, resp.StatusCode, err)
		}
		return got
	}
	if got := ask("https"); got.HTTPS == nil || *got.HTTPS != "on" {
		t.Errorf("X-Forwarded-Proto https: $_SERVER['HTTPS'] = %+v, want on", got)
	}
	for _, proto := range []string{"", "http"} {
		if got := ask(proto); got.HTTPS != nil && *got.HTTPS != "" {
			t.Errorf("X-Forwarded-Proto %q: $_SERVER['HTTPS'] = %q, want empty", proto, *got.HTTPS)
		}
	}

	logs := containerLogs(t, be.api, st.ContainerID)
	if strings.Contains(logs, `"level":"debug"`) {
		t.Fatalf("FrankenPHP logs at debug level:\n%s", tail(logs, 30))
	}
}
