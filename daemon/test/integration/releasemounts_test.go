//go:build integration

package integration

import (
	"context"
	"crypto/rand"
	"strings"
	"testing"
	"time"

	"github.com/vantezzen/launchroom/daemon/internal/docker"
	"github.com/vantezzen/launchroom/daemon/internal/names"
	"github.com/vantezzen/launchroom/daemon/internal/policy"
	"github.com/vantezzen/launchroom/daemon/internal/sealed"
	"github.com/vantezzen/launchroom/daemon/internal/secret"
	"github.com/vantezzen/launchroom/daemon/internal/state"
	"github.com/vantezzen/launchroom/daemon/internal/tasks"
)

// TestRunOnceReleaseMounts runs release commands with the service's volume
// mounted (G62, RunOnceSpec.mounts) on real Docker: what one run writes the
// next one reads, and a volume of another service is refused.
func TestRunOnceReleaseMounts(t *testing.T) {
	e := setup(t)
	pol := policy.DefaultConfig()
	pol.AllowedRegistries = []string{"docker.io/library/"}
	const (
		svc  = state.ServiceID("svc_01J9Z8X7W6V5T4S3R2Q1P0N9N2")
		env  = state.EnvironmentID("env_01J9Z8X7W6V5T4S3R2Q1P0N9N4")
		vol  = state.VolumeID("vol_01J9Z8X7W6V5T4S3R2Q1P0N9V1")
		name = state.HostName("lr-v_itest_release")
	)
	labels := docker.ManagedLabels(string(e.serverID))
	labels[names.LabelVolume] = string(vol)
	labels[names.LabelService] = string(svc)
	labels[names.LabelEnvironment] = string(env)
	if err := e.engine.CreateVolume(context.Background(), docker.VolumeCreate{Name: string(name), Labels: labels}); err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { _ = e.engine.RemoveVolume(context.Background(), string(name)) })

	ns := &state.NodeState{Volumes: []state.VolumeSpec{{ID: vol, Name: name}}}
	cp := &logCP{}
	h := tasks.RunOnceHandler(tasks.RunOnceOptions{
		ServerID: e.serverID, SealingKey: e.key, Docker: e.engine, Client: cp, Policy: pol,
		NodeState: func() *state.NodeState { return ns }, VolumeWait: 2 * time.Second,
	})
	netSpec := state.NetworkSpec{ID: "net_01J9Z8X7W6V5T4S3R2Q1P0N9N1", Name: "lr-e_itest_relmnt", Subnet: "10.231.252.0/24"}

	run := func(t *testing.T, service state.ServiceID, args ...string) (state.TaskResults, error) {
		t.Helper()
		id := state.TaskID(randomID("tsk"))
		sealedEnv, err := sealed.SealJSON(state.TaskEnv{ServerID: e.serverID, TaskID: id, Env: map[string]secret.Secret{}}, e.key.PublicKey(), rand.Reader)
		if err != nil {
			t.Fatal(err)
		}
		task := state.Task{ID: id, Type: state.TaskRunOnce, Attempt: 1, LeaseExpiresAt: time.Now().Add(time.Minute), Deadline: time.Now().Add(10 * time.Minute),
			Spec: &state.RunOnceSpec{
				Service: service, Environment: env, Deployment: "dpl_01J9Z8X7W6V5T4S3R2Q1P0N9N5",
				Image: caddyImage, Args: args, Env: sealedEnv, Network: netSpec, Timeout: "2m",
				Resources: state.Resources{CPUs: 0.5, MemoryMB: 64, Pids: 64},
				Mounts:    []state.RunOnceMount{{Volume: vol, Target: "/data"}},
			}}
		if err := task.Validate(); err != nil {
			t.Fatal(err)
		}
		ctx, cancel := context.WithTimeout(context.Background(), 5*time.Minute)
		defer cancel()
		return h(ctx, task)
	}

	res, err := run(t, svc, "mkdir", "/data/migrated-42")
	if err != nil || res.RunOnce == nil || res.RunOnce.ExitCode != 0 {
		t.Fatalf("write: %+v, %v; logs:\n%s", res.RunOnce, err, cp.text())
	}
	before := len(cp.text())
	res, err = run(t, svc, "ls", "/data")
	if err != nil || res.RunOnce == nil || res.RunOnce.ExitCode != 0 {
		t.Fatalf("read: %+v, %v", res.RunOnce, err)
	}
	if !strings.Contains(cp.text()[before:], "migrated-42") {
		t.Fatalf("the second run didn't see the first run's file; logs:\n%s", cp.text())
	}

	if _, err := run(t, "svc_01J9Z8X7W6V5T4S3R2Q1P0N9N7", "true"); err == nil || !strings.Contains(err.Error(), "belongs to another service") {
		t.Fatalf("a volume of another service was mounted: %v", err)
	}
}
