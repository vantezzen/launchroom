package update

import (
	"context"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"github.com/vantezzen/launchroom/daemon/internal/state"
	"github.com/vantezzen/launchroom/daemon/internal/tasks"
)

func selfUpdateTask(version string, scope state.UpdateScope) state.Task {
	return state.Task{ID: testTask, Type: state.TaskSelfUpdate, Attempt: 1,
		Spec: &state.SelfUpdateSpec{Version: state.Semver(version), Image: testDaemonImage, Scope: scope}}
}

// The handler refuses before touching Docker or the network.
func TestHandlerRefusals(t *testing.T) {
	k := newTestKey(t)
	rs := newReleaseServer(t)
	good := makeTarball(t, bundleFiles("0.3.0"))
	rs.publish("0.3.0", good, k.sign(t, good))

	withTrust := func(t *testing.T) string {
		dir := t.TempDir()
		if err := writeJSON(filepath.Join(dir, TrustFile), k.trust(rs.URL+"/releases")); err != nil {
			t.Fatal(err)
		}
		return dir
	}
	daemonSelf := func(context.Context) (Self, error) { return Self{Role: "launchroomd"}, nil }
	cpSelf := func(context.Context) (Self, error) { return Self{Role: RoleControlPlane}, nil }

	cases := []struct {
		name    string
		dataDir func(*testing.T) string
		self    func(context.Context) (Self, error)
		task    state.Task
		want    string
	}{
		{"downgrade", withTrust, daemonSelf, selfUpdateTask("0.1.0", ""), "never downgrades"},
		{"same version", withTrust, daemonSelf, selfUpdateTask("0.2.0", ""), "never downgrades"},
		{"no trust pinned", func(t *testing.T) string { return t.TempDir() }, daemonSelf, selfUpdateTask("0.3.0", ""), "no release identity"},
		{"daemon scope on the control plane", withTrust, cpSelf, selfUpdateTask("0.3.0", state.UpdateDaemon), "updated together with the control plane"},
		{"control-plane scope elsewhere", withTrust, daemonSelf, selfUpdateTask("0.3.0", state.UpdateControlPlane), "only run on the control-plane server"},
		{"unsigned version", withTrust, daemonSelf, selfUpdateTask("0.4.0", ""), "HTTP 404"},
	}
	for _, c := range cases {
		t.Run(c.name, func(t *testing.T) {
			h := NewHandler(HandlerOptions{DataDir: c.dataDir(t), Version: "0.2.0", Self: c.self})
			res, err := h(context.Background(), c.task)
			if err == nil || !strings.Contains(err.Error(), c.want) {
				t.Fatalf("err = %v, want %q", err, c.want)
			}
			if res.SelfUpdate == nil || res.SelfUpdate.FromVersion != "0.2.0" || res.SelfUpdate.RolledBack {
				t.Fatalf("result %+v", res.SelfUpdate)
			}
		})
	}

	// The image the dashboard names must be the one the signed bundle pins.
	t.Run("image mismatch", func(t *testing.T) {
		task := selfUpdateTask("0.3.0", "")
		task.Spec.(*state.SelfUpdateSpec).Image = state.ImageRef("ghcr.io/evil/launchroomd@sha256:" + strings.Repeat("9", 64))
		h := NewHandler(HandlerOptions{DataDir: withTrust(t), Version: "0.2.0", Self: daemonSelf})
		if _, err := h(context.Background(), task); err == nil || !strings.Contains(err.Error(), "pins") {
			t.Fatalf("err = %v", err)
		}
	})
}

// A re-leased attempt of a finished update completes from the recorded status.
func TestHandlerReportsRecordedStatus(t *testing.T) {
	dir := t.TempDir()
	if err := os.MkdirAll(taskDir(dir, testTask), 0o700); err != nil {
		t.Fatal(err)
	}
	if err := writeStatus(dir, Status{TaskID: testTask, Scope: state.UpdateDaemon, From: "0.1.0", To: "0.3.0", Outcome: OutcomeRolledBack, Error: "rolled back to 0.1.0: x"}); err != nil {
		t.Fatal(err)
	}
	h := NewHandler(HandlerOptions{DataDir: dir, Version: "0.1.0"})
	res, err := h(context.Background(), selfUpdateTask("0.3.0", ""))
	if err == nil || res.SelfUpdate == nil || !res.SelfUpdate.RolledBack {
		t.Fatalf("res=%+v err=%v", res.SelfUpdate, err)
	}
	_ = tasks.ErrHandoff
}
