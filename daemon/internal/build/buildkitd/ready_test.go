package buildkitd

import (
	"context"
	"errors"
	"os"
	"path/filepath"
	"strings"
	"sync"
	"testing"
	"time"

	bkclient "github.com/moby/buildkit/client"
	"github.com/moby/moby/api/types/container"

	"github.com/vantezzen/launchroom/daemon/internal/docker"
	"github.com/vantezzen/launchroom/daemon/internal/docker/dockerfake"
)

const readySrv = "srv_01J9Z8X7W6V5T4S3R2Q1P0N9MK"

// readyRig is a Manager on a fake Docker whose dial succeeds only while
// dialOK says so.
type readyRig struct {
	m      *Manager
	d      *dockerfake.Fake
	mu     sync.Mutex
	dialOK bool
	dials  int
	lines  []string
}

func newReadyRig(t *testing.T, timeout time.Duration) *readyRig {
	t.Helper()
	r := &readyRig{d: dockerfake.New(readySrv)}
	sysctl := filepath.Join(t.TempDir(), "userns")
	if err := os.WriteFile(sysctl, []byte("0\n"), 0o600); err != nil {
		t.Fatal(err)
	}
	r.m = New(Options{ServerID: readySrv, Docker: r.d, ReadyTimeout: timeout, UsernsSysctl: sysctl})
	r.m.connect = func(context.Context, string) (*bkclient.Client, error) {
		r.mu.Lock()
		defer r.mu.Unlock()
		r.dials++
		if r.dialOK {
			return &bkclient.Client{}, nil
		}
		return nil, errors.New("buildkitd: not ready: connection refused")
	}
	return r
}

// container creates and starts a managed buildkitd container and makes it the running one.
func (r *readyRig) container(t *testing.T) string {
	t.Helper()
	r.d.AddImage("buildkit")
	id, err := r.d.CreateContainer(t.Context(), &docker.ContainerCreate{
		Name: ContainerName, Config: &container.Config{Image: "buildkit", Labels: docker.ManagedLabels(readySrv)},
	})
	if err != nil {
		t.Fatal(err)
	}
	if err := r.d.StartContainer(t.Context(), id); err != nil {
		t.Fatal(err)
	}
	r.synced(id)
	return id
}

func (r *readyRig) synced(id string) {
	r.m.mu.Lock()
	r.m.running, r.m.synced, r.m.enabled = id, true, true
	r.m.mu.Unlock()
}

func (r *readyRig) setDial(ok bool) {
	r.mu.Lock()
	r.dialOK = ok
	r.mu.Unlock()
}

func (r *readyRig) wait(ctx context.Context) (*bkclient.Client, error) {
	return r.m.WaitClient(ctx, func(l string) {
		r.mu.Lock()
		r.lines = append(r.lines, l)
		r.mu.Unlock()
	})
}

func restarting(c *docker.Container) {
	c.State, c.Running, c.Restarting = "restarting", true, true
	c.StartedAt = time.Now().Add(-2 * time.Second)
	c.FinishedAt = time.Now().Add(-time.Second)
}

func TestWaitClientReadyRightAway(t *testing.T) {
	r := newReadyRig(t, 5*time.Second)
	r.container(t)
	r.setDial(true)
	if _, err := r.wait(t.Context()); err != nil {
		t.Fatal(err)
	}
	if len(r.lines) != 0 {
		t.Fatalf("waited without need: %v", r.lines)
	}
}

func TestWaitClientWaitsForARestart(t *testing.T) {
	r := newReadyRig(t, 20*time.Second)
	id := r.container(t)
	r.d.Update(id, func(c *docker.Container) { restarting(c); c.RestartCount = 1; c.OOMKilled = true })
	go func() {
		time.Sleep(600 * time.Millisecond)
		r.d.Update(id, func(c *docker.Container) { c.State, c.Restarting, c.RestartCount = "running", false, 2 })
		r.setDial(true)
	}()
	start := time.Now()
	if _, err := r.wait(t.Context()); err != nil {
		t.Fatalf("wait: %v", err)
	}
	if time.Since(start) < 500*time.Millisecond {
		t.Fatal("did not wait for the restart")
	}
	if len(r.lines) == 0 || r.lines[0] != WaitingMessage {
		t.Fatalf("progress lines %q", r.lines)
	}
	if r.dials != 1 {
		t.Fatalf("dialled a restarting container: %d dials", r.dials)
	}
}

func TestWaitClientWaitsForTheFirstSync(t *testing.T) {
	r := newReadyRig(t, 20*time.Second)
	r.setDial(true)
	r.d.AddImage("buildkit")
	go func() {
		time.Sleep(400 * time.Millisecond)
		id, _ := r.d.CreateContainer(context.Background(), &docker.ContainerCreate{
			Name: ContainerName, Config: &container.Config{Image: "buildkit", Labels: docker.ManagedLabels(readySrv)},
		})
		_ = r.d.StartContainer(context.Background(), id)
		r.synced(id)
	}()
	if _, err := r.wait(t.Context()); err != nil {
		t.Fatalf("wait: %v", err)
	}
}

func TestWaitClientFollowsAReplacement(t *testing.T) {
	r := newReadyRig(t, 20*time.Second)
	old := r.container(t)
	_ = r.d.RemoveContainer(t.Context(), old) // the reconcile replaces it (new spec)
	r.setDial(true)
	go func() {
		time.Sleep(400 * time.Millisecond)
		id, _ := r.d.CreateContainer(context.Background(), &docker.ContainerCreate{
			Name: ContainerName, Config: &container.Config{Image: "buildkit", Labels: docker.ManagedLabels(readySrv)},
		})
		_ = r.d.StartContainer(context.Background(), id)
		r.synced(id)
	}()
	if _, err := r.wait(t.Context()); err != nil {
		t.Fatalf("wait: %v", err)
	}
}

func TestWaitClientDisabled(t *testing.T) {
	r := newReadyRig(t, 20*time.Second)
	r.m.mu.Lock()
	r.m.synced = true
	r.m.mu.Unlock()
	start := time.Now()
	if _, err := r.wait(t.Context()); !errors.Is(err, ErrNotRunning) {
		t.Fatalf("err = %v", err)
	}
	if time.Since(start) > time.Second {
		t.Fatal("waited although the build role is off")
	}
}

func TestWaitClientCrashLoop(t *testing.T) {
	r := newReadyRig(t, 30*time.Second)
	id := r.container(t)
	r.d.Update(id, func(c *docker.Container) { restarting(c); c.ExitCode = 1 })
	r.d.Logs[id] = []docker.LogLine{
		{Text: `{"level":"info","msg":"starting"}`},
		{Text: "[rootlesskit:parent] error: failed to start the child: fork/exec /proc/self/exe: permission denied"},
	}
	stop := make(chan struct{})
	defer close(stop)
	go func() {
		for {
			select {
			case <-stop:
				return
			case <-time.After(50 * time.Millisecond):
				r.d.Update(id, func(c *docker.Container) { c.RestartCount++ })
			}
		}
	}()
	start := time.Now()
	_, err := r.wait(t.Context())
	var nr *NotReadyError
	if !errors.As(err, &nr) || !nr.CrashLoop {
		t.Fatalf("err = %v", err)
	}
	if time.Since(start) > 10*time.Second {
		t.Fatalf("crash loop detected only after %s", time.Since(start))
	}
	if len(nr.LogTail) != 2 || !strings.Contains(err.Error(), "fork/exec /proc/self/exe: permission denied") || !strings.Contains(err.Error(), "keeps restarting") {
		t.Fatalf("diagnosis misses the logs: %v", err)
	}
	if nr.UsernsRestricted {
		t.Fatal("blamed AppArmor although the sysctl is 0")
	}
	if h := nr.Hints(); len(h) != 1 || h[0].Code != HintCrashLoop {
		t.Fatalf("hints %+v", h)
	}
}

func TestNotReadyDiagnosis(t *testing.T) {
	r := newReadyRig(t, time.Second)
	id := r.container(t)
	r.d.Logs[id] = []docker.LogLine{{Text: "[rootlesskit:child ] error: failed to share mount point: /: permission denied"}}
	c, _ := r.d.InspectContainer(t.Context(), id)
	restarting(&c)
	c.RestartCount, c.ExitCode = 7, 1

	if nr := r.m.notReady(t.Context(), id, &c, true, time.Second, nil); nr.UsernsRestricted {
		t.Fatal("userns blamed with the sysctl off")
	}
	if err := os.WriteFile(r.m.o.UsernsSysctl, []byte("1\n"), 0o600); err != nil {
		t.Fatal(err)
	}
	nr := r.m.notReady(t.Context(), id, &c, true, time.Second, nil)
	if !nr.UsernsRestricted || nr.Hints()[0].Code != HintUserns || !strings.Contains(nr.Hints()[0].Message, "apparmor_parser -r -W /etc/apparmor.d/launchroom-buildkit") {
		t.Fatalf("userns restriction not explained: %+v %+v", nr, nr.Hints())
	}

	// Go stack traces are dropped; the messages around them stay.
	r.d.Logs[id] = []docker.LogLine{
		{Text: "failed to parse config: toml: line 1: expected '.' or '=', but got 'i' instead"},
		{Text: "github.com/moby/buildkit/cmd/buildkitd/config.Load"},
		{Text: "\t/src/cmd/buildkitd/config/load.go:15"},
		{Text: "main.main"},
		{Text: "[rootlesskit:parent] error: child exited: exit status 1"},
		{Text: "failed to parse config: toml: line 1: expected '.' or '=', but got 'i' instead"},
		{Text: "[rootlesskit:parent] error: child exited: exit status 1"},
	}
	nr = r.m.notReady(t.Context(), id, &c, true, time.Second, nil)
	if len(nr.LogTail) != 2 || !strings.HasPrefix(nr.LogTail[0], "failed to parse config") {
		t.Fatalf("log tail %q", nr.LogTail)
	}

	c.OOMKilled, c.ExitCode = true, 137
	r.d.Logs[id] = []docker.LogLine{{Text: "killed"}}
	nr = r.m.notReady(t.Context(), id, &c, true, time.Second, nil)
	if !strings.Contains(nr.Error(), "out of memory") || nr.Hints()[0].Code != HintOOM {
		t.Fatalf("oom: %v %+v", nr, nr.Hints())
	}
}

func TestWaitClientTimesOut(t *testing.T) {
	r := newReadyRig(t, 700*time.Millisecond)
	r.container(t) // running, but never answers
	_, err := r.wait(t.Context())
	var nr *NotReadyError
	if !errors.As(err, &nr) || nr.CrashLoop || nr.State != "running" {
		t.Fatalf("err = %#v", err)
	}
	if h := nr.Hints(); len(h) != 1 || h[0].Code != HintNotReady {
		t.Fatalf("hints %+v", h)
	}
	if !strings.Contains(err.Error(), "did not become ready") || !strings.Contains(err.Error(), "connection refused") {
		t.Fatalf("message %q", err)
	}
}

func TestWaitClientCallerCancel(t *testing.T) {
	r := newReadyRig(t, time.Minute)
	r.container(t)
	ctx, cancel := context.WithTimeout(t.Context(), 300*time.Millisecond)
	defer cancel()
	if _, err := r.wait(ctx); !errors.Is(err, context.DeadlineExceeded) {
		t.Fatalf("err = %v", err)
	}
}
