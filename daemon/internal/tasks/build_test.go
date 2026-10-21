package tasks

import (
	"context"
	"crypto/ecdh"
	"crypto/rand"
	"errors"
	"testing"
	"time"

	"github.com/vantezzen/launchroom/daemon/internal/build"
	"github.com/vantezzen/launchroom/daemon/internal/sealed"
	"github.com/vantezzen/launchroom/daemon/internal/secret"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

type fakeBuilder struct {
	job build.Job
	out *build.Outcome
	err error
}

func (f *fakeBuilder) Run(_ context.Context, job build.Job) (*build.Outcome, error) {
	f.job = job
	if job.Sink != nil {
		job.Sink.Line("clone", state.StreamStdout, "fetching")
	}
	return f.out, f.err
}

const (
	bServer = state.ServerID("srv_01J9Z8X7W6V5T4S3R2Q1P0N9MK")
	bTask   = state.TaskID("tsk_01J9Z8X7W6V5T4S3R2Q1P0N9P0")
)

func buildTaskFor(t *testing.T, pub *ecdh.PublicKey, plan bool) state.Task {
	t.Helper()
	tok, err := sealed.SealJSON(state.TaskToken{ServerID: bServer, TaskID: bTask, Purpose: state.PurposeClone, Token: "ghs_clone"}, pub, rand.Reader)
	if err != nil {
		t.Fatal(err)
	}
	env, err := sealed.SealJSON(state.TaskEnv{ServerID: bServer, TaskID: bTask, Env: map[string]secret.Secret{"NPM_TOKEN": "npm_x"}}, pub, rand.Reader)
	if err != nil {
		t.Fatal(err)
	}
	in := state.BuildInputs{
		Service: "svc_01J9Z8X7W6V5T4S3R2Q1P0N9N2", Environment: "env_01J9Z8X7W6V5T4S3R2Q1P0N9N4", Deployment: "dpl_01J9Z8X7W6V5T4S3R2Q1P0N9N5",
		Repo:       state.RepoSpec{Provider: state.GitHub, CloneURL: "https://github.com/a/b.git", SHA: "4015b57a143aec5156fd1444a017a32137a3fd0f"},
		CloneToken: &tok, Env: &env,
		Builder:  state.BuilderSpec{Kind: state.BuilderAuto, RailpackVersion: "0.42.0"},
		Platform: "linux/amd64", CacheKey: "svc",
	}
	task := state.Task{ID: bTask, Attempt: 2, LeaseExpiresAt: time.Now(), Deadline: time.Now().Add(time.Hour)}
	if plan {
		task.Type, task.Spec = state.TaskPlan, &state.PlanSpec{BuildInputs: in}
	} else {
		task.Type, task.Spec = state.TaskBuild, &state.BuildSpec{BuildInputs: in, Image: "127.0.0.1:5000/apps/svc_01j9z8x7w6v5t4s3r2q1p0n9n2"}
	}
	return task
}

func TestBuildHandler(t *testing.T) {
	key, _ := ecdh.X25519().GenerateKey(rand.Reader)
	fc := &fakeCP{}
	fb := &fakeBuilder{out: &build.Outcome{
		Summary:  state.BuildSummary{Builder: state.BuilderRailpack, BuilderReason: state.ReasonRailpack},
		Image:    "127.0.0.1:5000/apps/svc_01j9z8x7w6v5t4s3r2q1p0n9n2@sha256:0000000000000000000000000000000000000000000000000000000000000000",
		Duration: 1500 * time.Millisecond, SecretsHash: "aa",
	}}
	forgot := state.TaskID("")
	h := BuildHandler(BuildOptions{ServerID: bServer, SealingKey: key, Builder: fb, Client: fc, Slots: make(chan struct{}, 1), Forget: func(id state.TaskID) { forgot = id }})

	res, err := h(t.Context(), buildTaskFor(t, key.PublicKey(), false))
	if err != nil {
		t.Fatal(err)
	}
	if res.Build == nil || res.Build.DurationMs != 1500 || res.Build.Summary.Builder != state.BuilderRailpack || res.Plan != nil {
		t.Fatalf("result %+v", res)
	}
	if fb.job.CloneToken.Reveal() != "ghs_clone" || fb.job.Env["NPM_TOKEN"].Reveal() != "npm_x" || fb.job.Image == "" || forgot != bTask {
		t.Fatalf("job %+v", fb.job)
	}
	// Logs were shipped with the step and a per-attempt seq offset.
	fc.mu.Lock()
	batches := fc.batches
	fc.mu.Unlock()
	if len(batches) == 0 || batches[0].Chunks[0].Lines[0].Step != "clone" || batches[0].Chunks[0].Seq != attemptSeqBase+1 {
		t.Fatalf("log batches %+v", batches)
	}

	res, err = h(t.Context(), buildTaskFor(t, key.PublicKey(), true))
	if err != nil || res.Plan == nil || res.Build != nil || fb.job.Image != "" {
		t.Fatalf("plan result %+v %v", res, err)
	}

	// A token sealed for another server is a config error, not a crash.
	other, _ := ecdh.X25519().GenerateKey(rand.Reader)
	_, err = h(t.Context(), buildTaskFor(t, other.PublicKey(), false))
	var be *build.Error
	if !errors.As(err, &be) || be.Class != state.FailureConfig {
		t.Fatalf("err %v", err)
	}
}

func TestRunnerReportsBuildFailures(t *testing.T) {
	key, _ := ecdh.X25519().GenerateKey(rand.Reader)
	fc := &fakeCP{}
	fb := &fakeBuilder{err: &build.Error{Class: state.FailureBuild, Step: "install", Err: errors.New("exit code: 1"),
		Hints: []state.FailureHint{{Code: "pnpm-outdated-lockfile", Message: "run pnpm install"}}}}
	fc.lease = []state.Task{buildTaskFor(t, key.PublicKey(), false)}
	r := New(Options{Client: fc, Handlers: map[state.TaskType]Handler{state.TaskBuild: BuildHandler(BuildOptions{ServerID: bServer, SealingKey: key, Builder: fb, Client: fc})}})
	r.LeaseOnce(t.Context())
	r.wg.Wait()
	done := fc.completion(bTask)
	if done == nil || done.Outcome != state.OutcomeFailed || done.Failure == nil || done.Failure.Class != state.FailureBuild || done.Failure.Step != "install" || len(done.Failure.Hints) != 1 {
		t.Fatalf("completion %+v", done)
	}
	if err := done.Validate(); err != nil {
		t.Fatal(err)
	}
}
