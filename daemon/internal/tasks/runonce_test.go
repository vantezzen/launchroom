package tasks

import (
	"context"
	"crypto/ecdh"
	"crypto/rand"
	"encoding/base64"
	"errors"
	"net/netip"
	"net/url"
	"slices"
	"strings"
	"sync/atomic"
	"testing"
	"time"

	"github.com/vantezzen/launchroom/daemon/internal/build"
	"github.com/vantezzen/launchroom/daemon/internal/docker"
	"github.com/vantezzen/launchroom/daemon/internal/docker/dockerfake"
	"github.com/vantezzen/launchroom/daemon/internal/policy"
	"github.com/vantezzen/launchroom/daemon/internal/sealed"
	"github.com/vantezzen/launchroom/daemon/internal/secret"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

const roImage = state.ImageRef("127.0.0.1:5000/apps/svc_01j9z8x7w6v5t4s3r2q1p0n9n2@sha256:863294e97f3d613949dbcb8bac8bc2f34d40fb4773c50c12679d99fdc95c2272")

func runOnceTask(t *testing.T, pub *ecdh.PublicKey, timeout state.Duration) state.Task {
	t.Helper()
	env, err := sealed.SealJSON(state.TaskEnv{ServerID: bServer, TaskID: bTask, Env: map[string]secret.Secret{"DATABASE_URL": "postgres://u:p@db/x"}}, pub, rand.Reader)
	if err != nil {
		t.Fatal(err)
	}
	return state.Task{
		ID: bTask, Type: state.TaskRunOnce, Attempt: 1, LeaseExpiresAt: time.Now(), Deadline: time.Now().Add(time.Hour),
		Spec: &state.RunOnceSpec{
			Service: "svc_01J9Z8X7W6V5T4S3R2Q1P0N9N2", Environment: "env_01J9Z8X7W6V5T4S3R2Q1P0N9N4", Deployment: "dpl_01J9Z8X7W6V5T4S3R2Q1P0N9N5",
			Image: roImage, Args: []string{"php", "artisan", "migrate", "--force"}, Env: env,
			Network:   state.NetworkSpec{ID: "net_01J9Z8X7W6V5T4S3R2Q1P0N9N4", Name: "lr-e_0123456789abcdef", Subnet: "10.231.4.0/24"},
			Timeout:   timeout,
			Resources: state.Resources{CPUs: 1, MemoryMB: 256, Pids: 256},
		},
	}
}

func newRunOnce(t *testing.T) (*dockerfake.Fake, *fakeCP, *ecdh.PrivateKey, Handler) {
	t.Helper()
	priv, err := ecdh.X25519().GenerateKey(rand.Reader)
	if err != nil {
		t.Fatal(err)
	}
	d := dockerfake.New(string(bServer))
	cp := &fakeCP{}
	h := RunOnceHandler(RunOnceOptions{ServerID: bServer, SealingKey: priv, Docker: d, Client: cp, Policy: policy.DefaultConfig(), PollEvery: 10 * time.Millisecond})
	return d, cp, priv, h
}

func TestRunOnceExitCodeAndCleanup(t *testing.T) {
	d, cp, priv, h := newRunOnce(t)
	d.ExitOnStart[RunOnceContainerName(bTask)] = 3
	d.Logs = map[string][]docker.LogLine{}

	res, err := h(context.Background(), runOnceTask(t, priv.PublicKey(), "1m"))
	if err != nil {
		t.Fatal(err)
	}
	if res.RunOnce == nil || res.RunOnce.ExitCode != 3 {
		t.Fatalf("result = %+v", res.RunOnce)
	}
	calls := strings.Join(d.Calls(), "\n")
	for _, want := range []string{"pull " + string(roImage), "create lr-run-tsk_01j9z8x7w6v5t4s3r2q1p0n9p0", "start lr-run-", "remove lr-run-"} {
		if !strings.Contains(calls, want) {
			t.Errorf("calls %q miss %q", calls, want)
		}
	}
	nets, _ := d.ListNetworks(context.Background())
	if len(nets) != 1 || nets[0].Name != "lr-e_0123456789abcdef" || nets[0].Labels[docker.LabelNetwork] != "net_01J9Z8X7W6V5T4S3R2Q1P0N9N4" {
		t.Fatalf("networks = %+v", nets)
	}
	if cs, _ := d.ListContainers(context.Background(), nil); len(cs) != 0 {
		t.Fatalf("container left behind: %+v", cs)
	}
	var steps []string
	for _, b := range cp.batches {
		for _, l := range b.Chunks[0].Lines {
			steps = append(steps, l.Step)
			if strings.Contains(l.Text, "postgres://") {
				t.Fatal("env value in logs")
			}
		}
	}
	if !slices.Contains(steps, "run") {
		t.Fatalf("no run step lines: %v", steps)
	}
}

func TestRunOnceContainerIsNotAWorkload(t *testing.T) {
	d, _, priv, h := newRunOnce(t)
	d.AddImage(string(roImage))
	d.ExitOnStart[RunOnceContainerName(bTask)] = 0
	var created docker.ContainerCreate
	d.OnCreate = func(cc docker.ContainerCreate) { created = cc }

	if _, err := h(context.Background(), runOnceTask(t, priv.PublicKey(), "1m")); err != nil {
		t.Fatal(err)
	}
	labels := created.Config.Labels
	if labels[docker.LabelWorkload] != "" || labels[docker.LabelTask] != string(bTask) || labels[docker.LabelManaged] != "true" {
		t.Fatalf("labels = %v", labels)
	}
	if created.HostConfig.RestartPolicy.Name != "no" || created.HostConfig.Privileged || !slices.Contains(created.HostConfig.CapDrop, "ALL") {
		t.Fatalf("host config not hardened: %+v", created.HostConfig)
	}
	if !slices.Contains(created.Config.Env, "DATABASE_URL=postgres://u:p@db/x") {
		t.Fatal("sealed env not passed to the container")
	}
	if got := strings.Join(created.Config.Cmd, " "); got != "php artisan migrate --force" {
		t.Fatalf("argv = %q", got)
	}
}

func TestRunOnceTimeoutStopsAndRemoves(t *testing.T) {
	d, _, priv, h := newRunOnce(t)
	d.AddImage(string(roImage))

	_, err := h(context.Background(), runOnceTask(t, priv.PublicKey(), "200ms"))
	if !errors.Is(err, context.DeadlineExceeded) {
		t.Fatalf("err = %v", err)
	}
	calls := strings.Join(d.Calls(), "\n")
	if !strings.Contains(calls, "stop lr-run-") || !strings.Contains(calls, "remove lr-run-") {
		t.Fatalf("calls = %s", calls)
	}
}

func TestRunOnceRejectsForeignNetworkAndUnpinnedImages(t *testing.T) {
	d, _, priv, h := newRunOnce(t)
	d.AddUnmanagedNetwork("lr-e_0123456789abcdef")
	if _, err := h(context.Background(), runOnceTask(t, priv.PublicKey(), "1m")); err == nil {
		t.Fatal("ran in a network of another owner")
	}

	d2, _, priv2, h2 := newRunOnce(t)
	tk := runOnceTask(t, priv2.PublicKey(), "1m")
	tk.Spec.(*state.RunOnceSpec).Image = "docker.io/library/alpine@sha256:863294e97f3d613949dbcb8bac8bc2f34d40fb4773c50c12679d99fdc95c2272"
	if _, err := h2(context.Background(), tk); err == nil {
		t.Fatal("ran an image from outside the allowed registry")
	}
	if calls := d2.Calls(); slices.ContainsFunc(calls, func(c string) bool { return strings.HasPrefix(c, "create lr-") }) {
		t.Fatalf("created a container: %v", calls)
	}
}

func TestRunOnceEnvBoundToTask(t *testing.T) {
	_, _, priv, h := newRunOnce(t)
	tk := runOnceTask(t, priv.PublicKey(), "1m")
	tk.ID = "tsk_01J9Z8X7W6V5T4S3R2Q1P0N9P1" // sealed for bTask
	if _, err := h(context.Background(), tk); err == nil {
		t.Fatal("opened an env sealed for another task")
	}
}

func TestRunOnceCommandOnlyReplacesAShellEntrypoint(t *testing.T) {
	d, _, priv, h := newRunOnce(t)
	d.AddImage(string(roImage))
	d.ExitOnStart[RunOnceContainerName(bTask)] = 0
	var created docker.ContainerCreate
	d.OnCreate = func(cc docker.ContainerCreate) { created = cc }
	tk := runOnceTask(t, priv.PublicKey(), "1m")
	spec := tk.Spec.(*state.RunOnceSpec)
	spec.Command, spec.Args = []string{"php", "artisan", "migrate", "--force"}, nil
	if err := spec.Validate(); err != nil {
		t.Fatal(err)
	}

	if _, err := h(context.Background(), tk); err != nil {
		t.Fatal(err)
	}
	if got := strings.Join(created.Config.Entrypoint, " "); got != "php artisan migrate --force" || len(created.Config.Cmd) != 0 {
		t.Fatalf("entrypoint %q cmd %q", created.Config.Entrypoint, created.Config.Cmd)
	}
}

// M2 (2026-10 security review): release/seed output is masked for the
// values the dashboard lists in maskEnv, and for the password of DSN values.
func TestRunOnceMasksListedEnvValues(t *testing.T) {
	env := map[string]secret.Secret{
		"DATABASE_URL": "postgres://app:s3cr3t-db-pass@db:5432/app",
		"API_KEY":      "key-abcdef123456",
		"APP_NAME":     "production-shop",
	}
	m := build.NewMasker(maskValues(env, []state.EnvName{"DATABASE_URL", "API_KEY", "NOT_IN_ENV"})...)

	for in, want := range map[string]string{
		"connecting to postgres://app:s3cr3t-db-pass@db:5432/app":                      "connecting to ***",
		`FATAL: password "s3cr3t-db-pass" rejected`:                                    `FATAL: password "***" rejected`,
		"auth header " + base64.StdEncoding.EncodeToString([]byte("key-abcdef123456")): "auth header ***",
		"key=" + url.QueryEscape("key-abcdef123456"):                                   "key=***",
		"booting production-shop":                                                      "booting production-shop",
	} {
		if got := m.Mask(in); got != want {
			t.Errorf("Mask(%q) = %q, want %q", in, got, want)
		}
	}
}

func TestRunOnceSpecValidatesMaskEnv(t *testing.T) {
	priv, err := ecdh.X25519().GenerateKey(rand.Reader)
	if err != nil {
		t.Fatal(err)
	}
	task := runOnceTask(t, priv.PublicKey(), "1m")
	spec := task.Spec.(*state.RunOnceSpec)
	spec.MaskEnv = []state.EnvName{"DATABASE_URL"}
	if err := spec.Validate(); err != nil {
		t.Fatalf("valid maskEnv rejected: %v", err)
	}
	for _, bad := range [][]state.EnvName{{"1BAD"}, {"A=B"}, {"DUP", "DUP"}} {
		spec.MaskEnv = bad
		if err := spec.Validate(); err == nil {
			t.Errorf("maskEnv %v accepted", bad)
		}
	}
}

func probeTask(t *testing.T, pub *ecdh.PublicKey, within state.Duration, path state.HTTPPath) state.Task {
	t.Helper()
	task := runOnceTask(t, pub, "5m")
	spec := task.Spec.(*state.RunOnceSpec)
	spec.Args = nil
	spec.Probe = &state.RunOnceProbe{Port: 8080, Path: path, Within: within}
	if err := spec.Validate(); err != nil {
		t.Fatalf("probe spec invalid: %v", err)
	}
	return task
}

func newProbeRunOnce(t *testing.T, probe func(context.Context, netip.AddrPort, string) (int, error), ports func(int) []int) (*dockerfake.Fake, Handler, *ecdh.PrivateKey) {
	t.Helper()
	priv, err := ecdh.X25519().GenerateKey(rand.Reader)
	if err != nil {
		t.Fatal(err)
	}
	d := dockerfake.New(string(bServer))
	d.Logs = map[string][]docker.LogLine{}
	h := RunOnceHandler(RunOnceOptions{ServerID: bServer, SealingKey: priv, Docker: d, Client: &fakeCP{}, Policy: policy.DefaultConfig(), PollEvery: 5 * time.Millisecond, Probe: probe, Ports: ports})
	return d, h, priv
}

func TestRunOnceProbeListening(t *testing.T) {
	var gotPath string
	var calls atomic.Int32
	d, h, priv := newProbeRunOnce(t, func(_ context.Context, addr netip.AddrPort, path string) (int, error) {
		gotPath = path
		if !addr.IsValid() || addr.Port() != 8080 {
			return 0, errors.New("bad addr")
		}
		if calls.Add(1) < 3 {
			return 0, errors.New("connection refused")
		}
		return 200, nil
	}, nil)
	res, err := h(context.Background(), probeTask(t, priv.PublicKey(), "1m", "/health"))
	if err != nil {
		t.Fatal(err)
	}
	p := res.RunOnce.Probe
	if p == nil || !p.Listening || p.Exited || p.HTTPStatus != 200 || res.RunOnce.ExitCode != 0 || gotPath != "/health" {
		t.Fatalf("result = %+v probe %+v", res.RunOnce, p)
	}
	calls2 := strings.Join(d.Calls(), "\n")
	if !strings.Contains(calls2, "stop lr-run-") || !strings.Contains(calls2, "remove lr-run-") {
		t.Fatalf("container not stopped/removed: %s", calls2)
	}
}

func TestRunOnceProbeExited(t *testing.T) {
	d, h, priv := newProbeRunOnce(t, func(context.Context, netip.AddrPort, string) (int, error) { return 0, errors.New("refused") }, nil)
	d.ExitOnStart[RunOnceContainerName(bTask)] = 2
	res, err := h(context.Background(), probeTask(t, priv.PublicKey(), "1m", ""))
	if err != nil {
		t.Fatal(err)
	}
	if p := res.RunOnce.Probe; p == nil || p.Listening || !p.Exited || res.RunOnce.ExitCode != 2 {
		t.Fatalf("result = %+v probe %+v", res.RunOnce, res.RunOnce.Probe)
	}
}

func TestRunOnceProbeTimeoutReportsPorts(t *testing.T) {
	_, h, priv := newProbeRunOnce(t,
		func(context.Context, netip.AddrPort, string) (int, error) { return 0, errors.New("refused") },
		func(int) []int { return []int{5173, 5173, 9229} })
	res, err := h(context.Background(), probeTask(t, priv.PublicKey(), "1s", ""))
	if err != nil {
		t.Fatal(err)
	}
	p := res.RunOnce.Probe
	if p == nil || p.Listening || p.Exited || !slices.Equal(p.ListeningPorts, []state.Port{5173, 9229}) {
		t.Fatalf("probe = %+v", p)
	}
	done := &state.TaskCompletion{TypeMeta: state.NewTypeMeta(state.KindTaskCompletion), Attempt: 1, Outcome: state.OutcomeSucceeded, RunOnce: res.RunOnce}
	if err := done.Validate(); err != nil {
		t.Fatalf("completion invalid: %v", err)
	}
}
