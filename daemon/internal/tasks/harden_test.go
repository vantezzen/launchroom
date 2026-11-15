package tasks

import (
	"context"
	"crypto/ecdh"
	"crypto/rand"
	"strings"
	"testing"
	"time"

	"github.com/vantezzen/launchroom/daemon/internal/docker"
	"github.com/vantezzen/launchroom/daemon/internal/docker/dockerfake"
	"github.com/vantezzen/launchroom/daemon/internal/names"
	"github.com/vantezzen/launchroom/daemon/internal/policy"
	"github.com/vantezzen/launchroom/daemon/internal/state"
	"github.com/vantezzen/launchroom/daemon/internal/tasks/taskstest"
)

// A daemon restart (SIGTERM, `make dev-daemon`) lets running tasks finish
// within the shutdown grace and hands unfinished ones back ("released") so
// the dashboard retries them instead of failing or cancelling them.
func TestShutdownGraceAndRelease(t *testing.T) {
	const (
		quick = state.TaskID("tsk_01J9Z8X7W6V5T4S3R2Q1P0N9Q1")
		slow  = state.TaskID("tsk_01J9Z8X7W6V5T4S3R2Q1P0N9Q2")
	)
	cp := &taskstest.ControlPlane{}
	started := make(chan struct{}, 2)
	r := New(Options{Client: cp, HeartbeatEvery: 10 * time.Millisecond, ShutdownGrace: 500 * time.Millisecond, Handlers: map[state.TaskType]Handler{
		state.TaskLogs: func(ctx context.Context, t state.Task) (state.TaskResults, error) {
			started <- struct{}{}
			if t.ID == quick {
				// Still running when shutdown starts, done well within the grace.
				time.Sleep(80 * time.Millisecond)
				return state.TaskResults{}, ctx.Err()
			}
			<-ctx.Done()
			return state.TaskResults{}, ctx.Err()
		},
	}})
	cp.Lease = []state.Task{taskstest.Task(quick, state.TaskLogs, &state.LogsSpec{Workload: wl}, time.Minute), taskstest.Task(slow, state.TaskLogs, &state.LogsSpec{Workload: wl}, time.Minute)}
	ctx, stop := context.WithCancel(t.Context())
	r.LeaseOnce(ctx)
	<-started
	<-started
	stop() // SIGTERM
	begin := time.Now()
	r.wg.Wait()

	if got := cp.Completion(quick); got == nil || got.Outcome != state.OutcomeSucceeded {
		t.Fatalf("quick task: %+v", got)
	}
	got := cp.Completion(slow)
	if got == nil || got.Outcome != state.OutcomeReleased || !strings.Contains(got.Error, "launchroomd stopped") {
		t.Fatalf("slow task: %+v", got)
	}
	if err := got.Validate(); err != nil {
		t.Fatal(err)
	}
	if waited := time.Since(begin); waited < 400*time.Millisecond || waited > 5*time.Second {
		t.Fatalf("shutdown took %s", waited)
	}
}

// Without a grace the task stops at once but is still released, not cancelled.
func TestShutdownWithoutGraceReleases(t *testing.T) {
	cp := &taskstest.ControlPlane{}
	started := make(chan struct{})
	r := New(Options{Client: cp, Handlers: map[state.TaskType]Handler{
		state.TaskLogs: func(ctx context.Context, _ state.Task) (state.TaskResults, error) {
			close(started)
			<-ctx.Done()
			return state.TaskResults{}, ctx.Err()
		},
	}})
	const id = state.TaskID("tsk_01J9Z8X7W6V5T4S3R2Q1P0N9Q3")
	cp.Lease = []state.Task{taskstest.Task(id, state.TaskLogs, &state.LogsSpec{Workload: wl}, time.Minute)}
	ctx, stop := context.WithCancel(t.Context())
	r.LeaseOnce(ctx)
	<-started
	stop()
	r.wg.Wait()
	if got := cp.Completion(id); got == nil || got.Outcome != state.OutcomeReleased {
		t.Fatalf("%+v", got)
	}
}

// The run_once task holds its environment network from before it ensures
// it until its container is gone, so the reconciler cannot remove it in between.
func TestRunOnceHoldsItsNetwork(t *testing.T) {
	priv, err := ecdh.X25519().GenerateKey(rand.Reader)
	if err != nil {
		t.Fatal(err)
	}
	d := dockerfake.New(string(bServer))
	nets := docker.NewNetworks(d, bServer)
	h := RunOnceHandler(RunOnceOptions{ServerID: bServer, SealingKey: priv, Docker: d, Client: &taskstest.ControlPlane{}, Policy: policy.DefaultConfig(),
		PollEvery: 10 * time.Millisecond, Networks: nets})
	d.AddImage(string(roImage))
	d.ExitOnStart[names.RunOnceContainer(bTask)] = 0
	const name = "lr-e_0123456789abcdef"
	heldAtCreate := false
	d.OnCreate = func(docker.ContainerCreate) { heldAtCreate = nets.Held(name) }
	if _, err := h(context.Background(), runOnceTask(t, priv.PublicKey(), "1m")); err != nil {
		t.Fatal(err)
	}
	if !heldAtCreate {
		t.Fatal("network not held while the container was created")
	}
	if nets.Held(name) {
		t.Fatal("hold not released after the task")
	}
}

// A network removed between ensuring it and creating the container is
// re-created and the create retried (bounded), instead of failing the task.
func TestRunOnceRecreatesVanishedNetwork(t *testing.T) {
	priv, err := ecdh.X25519().GenerateKey(rand.Reader)
	if err != nil {
		t.Fatal(err)
	}
	d := dockerfake.New(string(bServer))
	v := &vanishOnce{Fake: d}
	h := RunOnceHandler(RunOnceOptions{ServerID: bServer, SealingKey: priv, Docker: v, Client: &taskstest.ControlPlane{}, Policy: policy.DefaultConfig(), PollEvery: 10 * time.Millisecond})
	d.AddImage(string(roImage))
	d.ExitOnStart[names.RunOnceContainer(bTask)] = 0
	res, err := h(context.Background(), runOnceTask(t, priv.PublicKey(), "1m"))
	if err != nil || res.RunOnce == nil {
		t.Fatalf("%v %+v", err, res)
	}
	n := 0
	for _, c := range d.Calls() {
		if strings.HasPrefix(c, "create-network ") {
			n++
		}
	}
	if n != 2 {
		t.Fatalf("network created %d times: %q", n, d.Calls())
	}
}

type vanishOnce struct {
	*dockerfake.Fake
	done bool
}

func (v *vanishOnce) CreateContainer(ctx context.Context, cc *docker.ContainerCreate) (string, error) {
	if !v.done {
		v.done = true
		v.RemoveNetworkByName("lr-e_0123456789abcdef")
	}
	return v.Fake.CreateContainer(ctx, cc)
}
