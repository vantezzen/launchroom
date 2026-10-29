//go:build integration

package integration

import (
	"context"
	"crypto/rand"
	"encoding/json"
	"slices"
	"strings"
	"testing"
	"time"

	"github.com/moby/moby/client"

	"github.com/vantezzen/launchroom/daemon/internal/build"
	"github.com/vantezzen/launchroom/daemon/internal/health"
	"github.com/vantezzen/launchroom/daemon/internal/policy"
	"github.com/vantezzen/launchroom/daemon/internal/reconcile"
	"github.com/vantezzen/launchroom/daemon/internal/sealed"
	"github.com/vantezzen/launchroom/daemon/internal/secret"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// A Laravel app written for SQLite (no ext-pdo_pgsql in composer.json),
// connected to Postgres: the build adds pdo_pgsql through Railpack, and the
// running image has the driver and uses the pgsql connection (docs/04 §3.1).
func TestBuildLaravelWithPostgresDriver(t *testing.T) {
	be := setupBuild(t)
	ctx, cancel := context.WithTimeout(t.Context(), 30*time.Minute)
	defer cancel()

	inputs := be.inputs("laravel-sqlite", state.BuilderAuto)
	inputs.Builder.PhpExtensions = []state.PhpExtension{{Name: state.PhpExtPdoPgsql, For: state.EnginePostgres}}
	task := state.TaskID("tsk_01J9Z8X7W6V5T4S3R2Q1P0N9P7")
	be.lease(task)
	sink := &captureSink{}
	out, err := be.builder.Run(ctx, build.Job{Task: task, Inputs: inputs, Image: appImage, Sink: sink})
	if err != nil {
		t.Fatalf("build: %v\n%s", err, tail(sink.all(), 120))
	}
	s := out.Summary
	t.Logf("built %s in %s (provider %s, framework %s, presets %+v)", out.Image, out.Duration, s.Provider, s.Framework, s.Presets)
	if s.Provider != "php" || !slices.Equal(s.PhpExtensions, inputs.Builder.PhpExtensions) {
		t.Fatalf("summary provider %q, phpExtensions %+v", s.Provider, s.PhpExtensions)
	}
	if !strings.Contains(sink.all(), "Adds PHP extensions: pdo_pgsql (for postgres)") {
		t.Errorf("build log does not say which extensions it adds")
	}

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
	wl := state.WorkloadID("wl_01J9Z8X7W6V5T4S3R2Q1P0N9P8")
	netID := state.NetworkID("net_01J9Z8X7W6V5T4S3R2Q1P0N9P9")
	// What the Laravel connection style injects (docs/06 §3.2), plus file-free
	// sessions and cache: there is no database server in this test.
	env := map[string]secret.Secret{
		"PORT": "8080", "DB_CONNECTION": "pgsql", "DB_HOST": "postgres", "DB_PORT": "5432",
		"DB_DATABASE": "app", "DB_USERNAME": "app", "DB_PASSWORD": "secret-password",
		"APP_KEY": "base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=", "SESSION_DRIVER": "array", "CACHE_STORE": "array", "LOG_CHANNEL": "stderr",
	}
	envSealed, err := sealed.SealWorkloadEnv(state.WorkloadEnv{ServerID: be.serverID, WorkloadID: wl, Env: env}, be.key.PublicKey(), rand.Reader)
	if err != nil {
		t.Fatal(err)
	}
	port := state.Port(8080)
	ns := &state.NodeState{
		TypeMeta: state.NewTypeMeta(state.KindNodeState), ServerID: be.serverID, Version: 1, IssuedAt: time.Now().UTC(),
		Networks: []state.NetworkSpec{{ID: netID, Name: "lr-e_itest-php", Subnet: "10.231.252.0/24"}},
		Volumes:  []state.VolumeSpec{}, Ambassadors: []state.AmbassadorSpec{}, Routes: []state.RouteSpec{},
		Firewall: state.FirewallSpec{MeshAllow: []state.MeshAllowRule{}},
		Workloads: []state.WorkloadSpec{{
			ID: wl, Service: itService, Environment: "env_01J9Z8X7W6V5T4S3R2Q1P0N9N4", Deployment: itDeployment,
			Image: out.Image, Env: envSealed, Port: &port,
			Health:    &state.HealthSpec{Type: state.HealthHTTP, Path: "/up", StartPeriod: "120s", Interval: "2s", Timeout: "2s", FailureThreshold: 5},
			Resources: state.Resources{CPUs: 1, MemoryMB: 512, Pids: 512},
			Networks:  []state.NetworkAttachment{{Network: netID, Aliases: []state.Slug{"api"}}},
			Restart:   state.RestartUnlessStopped, Role: state.RoleServing,
			// What the dashboard grants Railpack PHP images: FrankenPHP's
			// binary carries cap_net_bind_service (docs/04 §3.1).
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
			t.Fatalf("the Laravel image never became healthy: %+v\n%s", st, tail(logs, 60))
		}
		time.Sleep(time.Second)
	}
	insp, err := be.api.ContainerInspect(ctx, st.ContainerID, client.ContainerInspectOptions{})
	if err != nil {
		t.Fatal(err)
	}
	ip := insp.Container.NetworkSettings.Networks["lr-e_itest-php"].IPAddress
	code, _, body, err := get(t, noRedirect, "http://"+ip.String()+":8080/driver", "api")
	if err != nil || code != 200 {
		t.Fatalf("/driver: %d %q %v", code, body, err)
	}
	var got struct {
		Connection string   `json:"connection"`
		Drivers    []string `json:"drivers"`
	}
	if err := json.Unmarshal([]byte(body), &got); err != nil {
		t.Fatalf("/driver: %q: %v", body, err)
	}
	t.Logf("/driver: %+v", got)
	if got.Connection != "pgsql" || !slices.Contains(got.Drivers, "pgsql") {
		t.Fatalf("the app uses %q with PDO drivers %v; want pgsql with the pgsql driver", got.Connection, got.Drivers)
	}
}
