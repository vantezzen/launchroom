package update

import (
	"context"
	"errors"
	"os"
	"path/filepath"
	"strings"
	"sync/atomic"
	"testing"
	"time"

	"github.com/moby/moby/api/types/container"

	"github.com/vantezzen/launchroom/daemon/internal/controlplane"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

const testTask = state.TaskID("tsk_01J9Z8X7W6V5T4S3R2Q1P0N9P7")

func TestSelfFromInspect(t *testing.T) {
	good := container.InspectResponse{
		ID: "abc", Image: "sha256:" + strings.Repeat("a", 64),
		Config: &container.Config{Labels: map[string]string{
			composeWorkingDir: "/srv/launchroomd", composeProject: "launchroomd", composeService: "launchroomd", labelRole: "launchroomd",
		}},
		Mounts: []container.MountPoint{
			{Source: "/srv/launchroomd/data", Destination: "/data"},
			{Source: "/var/run/docker.sock", Destination: "/var/run/docker.sock"},
		},
	}
	s, err := selfFromInspect(good, "/data")
	if err != nil {
		t.Fatal(err)
	}
	if s.ProjectDir != "/srv/launchroomd" || s.DataDir != "/srv/launchroomd/data" || s.SocketPath != "/var/run/docker.sock" || s.Role != "launchroomd" {
		t.Fatalf("%+v", s)
	}

	mut := func(f func(*container.InspectResponse)) container.InspectResponse {
		c := good
		c.Config = &container.Config{Labels: map[string]string{}}
		for k, v := range good.Config.Labels {
			c.Config.Labels[k] = v
		}
		c.Mounts = append([]container.MountPoint(nil), good.Mounts...)
		f(&c)
		return c
	}
	for name, c := range map[string]container.InspectResponse{
		"no compose labels": mut(func(c *container.InspectResponse) { delete(c.Config.Labels, composeWorkingDir) }),
		"relative dir":      mut(func(c *container.InspectResponse) { c.Config.Labels[composeWorkingDir] = "srv" }),
		"root dir":          mut(func(c *container.InspectResponse) { c.Config.Labels[composeWorkingDir] = "/" }),
		"data outside":      mut(func(c *container.InspectResponse) { c.Mounts[0].Source = "/var/lib/data" }),
		"no socket":         mut(func(c *container.InspectResponse) { c.Mounts = c.Mounts[:1] }),
		"image by name":     mut(func(c *container.InspectResponse) { c.Image = "launchroomd:latest" }),
	} {
		if _, err := selfFromInspect(c, "/data"); err == nil {
			t.Errorf("%s: accepted", name)
		}
	}
}

func TestBackupAndRestoreFiles(t *testing.T) {
	project := t.TempDir()
	backup := filepath.Join(t.TempDir(), "backup")
	must := func(err error) {
		t.Helper()
		if err != nil {
			t.Fatal(err)
		}
	}
	must(os.WriteFile(filepath.Join(project, "compose.yml"), []byte("old compose"), 0o644))
	must(os.WriteFile(filepath.Join(project, ".env"), []byte("A=1\n"), 0o600))
	saved, err := backupFiles(project, backup, []string{"compose.yml", ".env", "launchroom"})
	must(err)

	// The installer replaces files and adds one that did not exist.
	must(os.WriteFile(filepath.Join(project, "compose.yml"), []byte("new compose"), 0o644))
	must(os.WriteFile(filepath.Join(project, ".env"), []byte("A=2\n"), 0o644))
	must(os.WriteFile(filepath.Join(project, "launchroom"), []byte("#!/bin/sh"), 0o755))

	must(restoreFiles(project, backup, saved))
	if b, _ := os.ReadFile(filepath.Join(project, "compose.yml")); string(b) != "old compose" {
		t.Errorf("compose.yml = %q", b)
	}
	st, err := os.Stat(filepath.Join(project, ".env"))
	must(err)
	if st.Mode().Perm() != 0o600 {
		t.Errorf(".env mode %v", st.Mode().Perm())
	}
	if _, err := os.Stat(filepath.Join(project, "launchroom")); !errors.Is(err, os.ErrNotExist) {
		t.Error("file added by the update was not removed")
	}
}

func TestReadDotEnv(t *testing.T) {
	p := filepath.Join(t.TempDir(), ".env")
	if err := os.WriteFile(p, []byte("# x\nLR_CONTROL_PLANE_URL=https://dash.example.com\nLR_CP_KEY=ed25519:abc=\nlower=1\n"), 0o600); err != nil {
		t.Fatal(err)
	}
	env, err := readDotEnv(p)
	if err != nil {
		t.Fatal(err)
	}
	if env["LR_CONTROL_PLANE_URL"] != "https://dash.example.com" || env["LR_CP_KEY"] != "ed25519:abc=" || env["lower"] != "" {
		t.Fatal(env)
	}
}

func TestStatusCompletion(t *testing.T) {
	ok := Status{TaskID: testTask, Scope: state.UpdateDaemon, From: "0.1.0", To: "0.2.0", Outcome: OutcomeSucceeded}
	done := ok.Completion(2)
	if err := done.Validate(); err != nil {
		t.Fatal(err)
	}
	if done.Outcome != state.OutcomeSucceeded || done.Attempt != 2 || done.SelfUpdate.RolledBack || ok.Err() != nil {
		t.Fatalf("%+v", done)
	}
	rb := ok
	rb.Outcome, rb.Error = OutcomeRolledBack, "rolled back to 0.1.0: boom"
	done = rb.Completion(1)
	if err := done.Validate(); err != nil {
		t.Fatal(err)
	}
	if done.Outcome != state.OutcomeFailed || !done.SelfUpdate.RolledBack || done.Error == "" || rb.Err() == nil {
		t.Fatalf("%+v", done)
	}
}

func TestPlanValidate(t *testing.T) {
	good := Plan{TaskID: testTask, Scope: state.UpdateDaemon, ProjectDir: "/srv/lr", DataDir: "/srv/lr/data", SocketPath: "/var/run/docker.sock",
		BundleDir: "/srv/lr/data/update/tasks/x/bundle", DaemonImage: testDaemonImage, ComposeImage: testComposeImage}
	if err := good.validate(); err != nil {
		t.Fatal(err)
	}
	for name, mut := range map[string]func(*Plan){
		"data outside project": func(p *Plan) { p.DataDir = "/elsewhere" },
		"bundle outside data":  func(p *Plan) { p.BundleDir = "/srv/lr/bundle" },
		"unclean":              func(p *Plan) { p.ProjectDir = "/srv/lr/../etc" },
		"tag image":            func(p *Plan) { p.DaemonImage = "ghcr.io/vantezzen/launchroomd:latest" },
		"bad scope":            func(p *Plan) { p.Scope = "host" },
	} {
		p := good
		mut(&p)
		if err := p.validate(); err == nil {
			t.Errorf("%s: accepted", name)
		}
	}
}

func TestResumeReportsRecordedOutcome(t *testing.T) {
	dir := t.TempDir()
	if err := os.MkdirAll(taskDir(dir, testTask), 0o700); err != nil {
		t.Fatal(err)
	}
	if err := writeJSON(filepath.Join(updateDir(dir), filePending), Pending{TaskID: testTask, Attempt: 3, Scope: state.UpdateDaemon, From: "0.1.0", To: "0.2.0"}); err != nil {
		t.Fatal(err)
	}
	var calls atomic.Int32
	var got *state.TaskCompletion
	var gotID state.TaskID
	complete := func(_ context.Context, id state.TaskID, done *state.TaskCompletion) error {
		calls.Add(1)
		gotID, got = id, done
		return nil
	}
	// The supervisor's verdict arrives a little later.
	go func() {
		time.Sleep(50 * time.Millisecond)
		_ = writeStatus(dir, Status{TaskID: testTask, Scope: state.UpdateDaemon, From: "0.1.0", To: "0.2.0", Outcome: OutcomeSucceeded})
	}()
	Resume(context.Background(), ResumeOptions{DataDir: dir, Complete: complete, Poll: 10 * time.Millisecond, Timeout: 5 * time.Second})
	if calls.Load() != 1 || gotID != testTask || got.Outcome != state.OutcomeSucceeded || got.Attempt != 3 {
		t.Fatalf("calls=%d got=%+v", calls.Load(), got)
	}
	if p, _ := readPending(dir); p != nil {
		t.Error("pending.json not cleared")
	}

	// Lease lost: the completion is refused; pending is still cleared (the
	// re-leased attempt reports from status.json).
	if err := writeJSON(filepath.Join(updateDir(dir), filePending), Pending{TaskID: testTask, Attempt: 3}); err != nil {
		t.Fatal(err)
	}
	Resume(context.Background(), ResumeOptions{DataDir: dir, Poll: 10 * time.Millisecond,
		Complete: func(context.Context, state.TaskID, *state.TaskCompletion) error {
			return &controlplane.APIError{Status: 409}
		}})
	if p, _ := readPending(dir); p != nil {
		t.Error("pending.json not cleared after a lost lease")
	}

	// No verdict at all within the timeout: reported as failed.
	other := state.TaskID("tsk_01J9Z8X7W6V5T4S3R2Q1P0N9P8")
	if err := writeJSON(filepath.Join(updateDir(dir), filePending), Pending{TaskID: other, Attempt: 1, Scope: state.UpdateDaemon, From: "0.1.0", To: "0.2.0", Supervisor: "lr-system-update-x"}); err != nil {
		t.Fatal(err)
	}
	if err := os.MkdirAll(taskDir(dir, other), 0o700); err != nil {
		t.Fatal(err)
	}
	got = nil
	Resume(context.Background(), ResumeOptions{DataDir: dir, Complete: complete, Poll: 10 * time.Millisecond, Timeout: 50 * time.Millisecond})
	if got == nil || gotID != other || got.Outcome != state.OutcomeFailed {
		t.Fatalf("got %+v", got)
	}
}

func TestMarkRunning(t *testing.T) {
	dir := t.TempDir()
	at := time.Date(2026, 10, 18, 12, 0, 0, 0, time.UTC)
	if err := MarkRunning(dir, "0.2.0", at); err != nil {
		t.Fatal(err)
	}
	r, err := readRunning(dir)
	if err != nil || r.Version != "0.2.0" || !r.StartedAt.Equal(at) {
		t.Fatalf("%+v %v", r, err)
	}
}

func TestLastLinesStripsTerminalCodes(t *testing.T) {
	out := "\x1b[?25l\x1b[0G\x1b[2K[+] up 5/8\n\n\x1b[2K \x1b[33mContainer launchroom-dashboard-1 Waiting \x1b[34m13.9s\x1b[0m\n\x1b[?25hcontainer launchroom-dashboard-1 is unhealthy\n"
	got := lastLines(out, 2)
	want := "Container launchroom-dashboard-1 Waiting 13.9s | container launchroom-dashboard-1 is unhealthy"
	if got != want {
		t.Fatalf("lastLines = %q, want %q", got, want)
	}
}
