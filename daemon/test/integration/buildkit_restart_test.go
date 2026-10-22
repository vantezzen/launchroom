//go:build integration

package integration

import (
	"archive/tar"
	"bytes"
	"context"
	"errors"
	"io"
	"strings"
	"testing"
	"time"

	"github.com/moby/moby/client"

	"github.com/vantezzen/launchroom/daemon/internal/build"
	"github.com/vantezzen/launchroom/daemon/internal/build/buildkitd"
	"github.com/vantezzen/launchroom/daemon/internal/docker"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// TestBuildkitRestartingBeforeABuild reproduces a buildkitd that Docker's
// restart policy keeps restarting (as on a server where it crashes on
// start): a broken config plus a killed buildkitd makes it crash-loop, a
// build then fails with the crash diagnosis instead of a bare dial error;
// once the config is good again the container recovers on its next
// restart and a build that starts while it is still restarting waits and
// succeeds.
func TestBuildkitRestartingBeforeABuild(t *testing.T) {
	be := setupBuild(t)
	ctx, cancel := context.WithTimeout(t.Context(), 20*time.Minute)
	defer cancel()
	insp, err := be.api.ContainerInspect(ctx, buildkitd.ContainerName, client.ContainerInspectOptions{})
	if err != nil {
		t.Fatal(err)
	}
	id := insp.Container.ID

	// Crash loop: buildkitd cannot parse its config on every start.
	installConfig(ctx, t, be, id, []byte("this is [[ not toml\n"))
	execIn(ctx, t, be.api, id, "pkill", "-9", "buildkitd")

	sink := &captureSink{}
	task := state.TaskID("tsk_01J9Z8X7W6V5T4S3R2Q1P0N9R1")
	be.lease(task)
	start := time.Now()
	_, err = be.builder.Run(ctx, build.Job{Task: task, Inputs: be.inputs("node-hello", state.BuilderAuto), Image: appImage, Sink: sink})
	t.Logf("crash-looping build failed after %s: %v\n%s", time.Since(start), err, tail(sink.all(), 30))
	// The pipeline masks the error text (the chain ends there); the
	// classification, hints and build log carry the diagnosis.
	var be2 *build.Error
	if !errors.As(err, &be2) || be2.Class != state.FailureInternal || !strings.Contains(err.Error(), "keeps restarting") {
		t.Fatalf("want a classified crash loop, got %v", err)
	}
	if hints := be2.Failure().Hints; len(hints) == 0 || hints[len(hints)-1].Code != buildkitd.HintCrashLoop {
		t.Errorf("hints %+v", hints)
	}
	all := sink.all()
	if !strings.Contains(all, buildkitd.WaitingMessage) || !strings.Contains(all, "BuildKit's last log lines:") || !strings.Contains(all, "failed to parse config") {
		var n int
		lerr := be.engine.StreamLogs(ctx, id, docker.LogsOptions{Tail: 60}, func(docker.LogLine) error { n++; return nil })
		t.Errorf("build log lacks the wait or the diagnosis (engine returns %d log lines, err %v):\n%s", n, lerr, all)
	}

	// Fixed config: Docker's next restart brings it back; the build waits.
	installConfig(ctx, t, be, id, be.bk.Config())
	if c, err := be.api.ContainerInspect(ctx, id, client.ContainerInspectOptions{}); err == nil {
		t.Logf("buildkitd before the second build: status=%s restarting=%v restarts=%d", c.Container.State.Status, c.Container.State.Restarting, c.Container.RestartCount)
	}
	sink = &captureSink{}
	task = "tsk_01J9Z8X7W6V5T4S3R2Q1P0N9R2"
	be.lease(task)
	out, err := be.builder.Run(ctx, build.Job{Task: task, Inputs: be.inputs("node-hello", state.BuilderAuto), Image: appImage, Sink: sink})
	if err != nil {
		t.Fatalf("build after recovery: %v\n%s", err, tail(sink.all(), 60))
	}
	t.Logf("built %s; waited: %v", out.Image, strings.Contains(sink.all(), buildkitd.WaitingMessage))
}

// installConfig copies a buildkitd.toml into the container (running or not).
func installConfig(ctx context.Context, t *testing.T, be *buildEnv, id string, cfg []byte) {
	t.Helper()
	var buf bytes.Buffer
	tw := tar.NewWriter(&buf)
	_ = tw.WriteHeader(&tar.Header{Typeflag: tar.TypeReg, Name: "home/user/.config/buildkit/buildkitd.toml", Mode: 0o644, Size: int64(len(cfg)), Uid: 1000, Gid: 1000, ModTime: time.Unix(0, 0)})
	_, _ = tw.Write(cfg)
	_ = tw.Close()
	if err := be.engine.CopyToContainer(ctx, id, "/", &buf); err != nil {
		t.Fatalf("install config: %v", err)
	}
}

// execIn runs argv in a container and waits for it to finish.
func execIn(ctx context.Context, t *testing.T, api *client.Client, id string, argv ...string) {
	t.Helper()
	res, err := api.ExecCreate(ctx, id, client.ExecCreateOptions{Cmd: argv, AttachStdout: true, AttachStderr: true})
	if err != nil {
		t.Fatal(err)
	}
	att, err := api.ExecAttach(ctx, res.ID, client.ExecAttachOptions{})
	if err != nil {
		t.Fatal(err)
	}
	defer att.Close()
	_, _ = io.Copy(io.Discard, att.Reader)
}
