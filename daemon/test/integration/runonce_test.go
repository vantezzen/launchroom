//go:build integration

package integration

import (
	"context"
	"crypto/rand"
	"strings"
	"sync"
	"testing"
	"time"

	"github.com/vantezzen/launchroom/daemon/internal/docker"
	"github.com/vantezzen/launchroom/daemon/internal/policy"
	"github.com/vantezzen/launchroom/daemon/internal/sealed"
	"github.com/vantezzen/launchroom/daemon/internal/secret"
	"github.com/vantezzen/launchroom/daemon/internal/state"
	"github.com/vantezzen/launchroom/daemon/internal/tasks"
)

// logCP is the control-plane side the run_once handler talks to: it only
// collects shipped log lines.
type logCP struct {
	mu    sync.Mutex
	lines []state.LogLine
}

func (c *logCP) LeaseTasks(context.Context, *state.LeaseRequest) ([]state.Task, error) {
	return nil, nil
}

func (c *logCP) Heartbeat(context.Context, state.TaskID, *state.TaskHeartbeat) (*state.TaskHeartbeatResult, error) {
	return &state.TaskHeartbeatResult{LeaseExpiresAt: time.Now().Add(time.Minute)}, nil
}

func (c *logCP) Complete(context.Context, state.TaskID, *state.TaskCompletion) error { return nil }

func (c *logCP) ShipLogs(_ context.Context, b *state.LogBatch) error {
	c.mu.Lock()
	defer c.mu.Unlock()
	for _, ch := range b.Chunks {
		c.lines = append(c.lines, ch.Lines...)
	}
	return nil
}

func (c *logCP) text() string {
	c.mu.Lock()
	defer c.mu.Unlock()
	var b strings.Builder
	for _, l := range c.lines {
		b.WriteString(l.Text + "\n")
	}
	return b.String()
}

// TestRunOnce runs one-off containers (release commands) against the real
// daemon: exit codes, sealed env, the environment network (created when
// missing), log shipping, cleanup, and the reconciler leaving them alone.
func TestRunOnce(t *testing.T) {
	e := setup(t)
	pol := policy.DefaultConfig()
	pol.AllowedRegistries = []string{"docker.io/library/"}
	cp := &logCP{}
	h := tasks.RunOnceHandler(tasks.RunOnceOptions{ServerID: e.serverID, SealingKey: e.key, Docker: e.engine, Client: cp, Policy: pol})
	netSpec := state.NetworkSpec{ID: "net_01J9Z8X7W6V5T4S3R2Q1P0N9N1", Name: "lr-e_itest_release", Subnet: "10.231.251.0/24"}

	run := func(t *testing.T, args []string, timeout state.Duration) (tasks.Result, error) {
		t.Helper()
		id := state.TaskID(randomID("tsk"))
		env, err := sealed.SealJSON(state.TaskEnv{ServerID: e.serverID, TaskID: id, Env: map[string]secret.Secret{"RELEASE_SECRET": "s3cr3t-value"}}, e.key.PublicKey(), rand.Reader)
		if err != nil {
			t.Fatal(err)
		}
		task := state.Task{ID: id, Type: state.TaskRunOnce, Attempt: 1, LeaseExpiresAt: time.Now().Add(time.Minute), Deadline: time.Now().Add(10 * time.Minute),
			Spec: &state.RunOnceSpec{
				Service: "svc_01J9Z8X7W6V5T4S3R2Q1P0N9N2", Environment: "env_01J9Z8X7W6V5T4S3R2Q1P0N9N4", Deployment: "dpl_01J9Z8X7W6V5T4S3R2Q1P0N9N5",
				Image: caddyImage, Args: args, Env: env, Network: netSpec, Timeout: timeout,
				Resources: state.Resources{CPUs: 0.5, MemoryMB: 64, Pids: 64},
			}}
		if err := task.Validate(); err != nil {
			t.Fatal(err)
		}
		ctx, cancel := context.WithTimeout(context.Background(), 5*time.Minute)
		defer cancel()
		return h(ctx, task)
	}

	t.Run("exit code, env and logs", func(t *testing.T) {
		res, err := run(t, []string{"env"}, "2m")
		if err != nil {
			t.Fatal(err)
		}
		if res.RunOnce == nil || res.RunOnce.ExitCode != 0 {
			t.Fatalf("result = %+v", res.RunOnce)
		}
		if !strings.Contains(cp.text(), "RELEASE_SECRET=s3cr3t-value") {
			t.Fatalf("env not visible inside the container; logs:\n%s", cp.text())
		}
		res, err = run(t, []string{"caddy", "no-such-subcommand"}, "2m")
		if err != nil {
			t.Fatal(err)
		}
		if res.RunOnce.ExitCode == 0 {
			t.Fatal("a failing command reported exit code 0")
		}
	})

	t.Run("network created, container removed", func(t *testing.T) {
		nets, err := e.engine.ListNetworks(context.Background())
		if err != nil {
			t.Fatal(err)
		}
		found := false
		for _, n := range nets {
			found = found || (n.Name == string(netSpec.Name) && n.Labels[docker.LabelNetwork] == string(netSpec.ID))
		}
		if !found {
			t.Fatalf("environment network not created: %+v", nets)
		}
		cs, err := e.engine.ListContainers(context.Background(), nil)
		if err != nil {
			t.Fatal(err)
		}
		for _, c := range cs {
			if c.Labels[docker.LabelTask] != "" {
				t.Fatalf("one-off container left behind: %s", c.Name)
			}
		}
	})

	t.Run("reconciler leaves a running one-off alone and timeouts stop it", func(t *testing.T) {
		ns, _ := e.nodeState(t, 1, false, false)
		done := make(chan error, 1)
		go func() {
			_, err := run(t, []string{"sleep", "30"}, "8s")
			done <- err
		}()
		deadline := time.Now().Add(2 * time.Minute)
		for {
			cs, _ := e.engine.ListContainers(context.Background(), nil)
			running := false
			for _, c := range cs {
				running = running || (c.Labels[docker.LabelTask] != "" && c.Running)
			}
			if running {
				break
			}
			if time.Now().After(deadline) {
				t.Fatal("one-off container never started")
			}
			time.Sleep(200 * time.Millisecond)
		}
		ctx, cancel := context.WithTimeout(context.Background(), time.Minute)
		if err := e.rec.Reconcile(ctx, ns); err != nil {
			t.Logf("reconcile: %v", err)
		}
		cancel()
		cs, _ := e.engine.ListContainers(context.Background(), nil)
		alive := false
		for _, c := range cs {
			alive = alive || (c.Labels[docker.LabelTask] != "" && c.Running)
		}
		if !alive {
			t.Fatal("the reconciler stopped or removed a run_once container")
		}
		if err := <-done; err == nil || !strings.Contains(err.Error(), "deadline") {
			t.Fatalf("timeout not reported: %v", err)
		}
		cs, _ = e.engine.ListContainers(context.Background(), nil)
		for _, c := range cs {
			if c.Labels[docker.LabelTask] != "" {
				t.Fatalf("timed-out container not removed: %s (%s)", c.Name, c.State)
			}
		}
	})
}
