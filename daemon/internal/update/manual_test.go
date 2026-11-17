package update

import (
	"errors"
	"os"
	"path/filepath"
	"slices"
	"strings"
	"testing"
	"time"

	"github.com/vantezzen/launchroom/daemon/internal/state"
)

func TestFilterInstallArgsAndEnv(t *testing.T) {
	got, err := FilterInstallArgs([]string{"--domain", "dash.example.com", "--no-public-ip-detect", "--unstable"})
	if err != nil || !slices.Equal(got, []string{"--domain", "dash.example.com", "--no-public-ip-detect", "--unstable"}) {
		t.Fatalf("%v %v", got, err)
	}
	for _, bad := range [][]string{{"--restore"}, {"--domain", "x;rm -rf /"}, {"--domain"}, {"--bundle", "/tmp/evil"}, {"--move", "tok"}} {
		if _, err := FilterInstallArgs(bad); err == nil {
			t.Errorf("%v accepted", bad)
		}
	}
	img := "ghcr.io/vantezzen/launchroom-caddy@sha256:" + strings.Repeat("a", 64)
	if got, err := FilterInstallEnv([]string{"LR_CADDY_IMAGE=" + img, "LAUNCHROOM_RELEASE_BASE=http://releases.test"}); err != nil || len(got) != 2 {
		t.Fatalf("%v %v", got, err)
	}
	// The installer's overrides as an admin (or the install e2e) gives them:
	// local tags, a mirror's tag, a registry with a port.
	for _, ok := range []string{"launchroom-dev-e2e-dashboard:test", "postgres:18-alpine", "registry.example.com:5000/mirror/launchroom-dashboard:1.2.0",
		"docker.io/library/launchroom-dev-e2e-caddy@sha256:" + strings.Repeat("b", 64), "ghcr.io/vantezzen/launchroom-dashboard:1.2.0@sha256:" + strings.Repeat("c", 64)} {
		if _, err := FilterInstallEnv([]string{"LR_DASHBOARD_IMAGE=" + ok}); err != nil {
			t.Errorf("%s refused: %v", ok, err)
		}
	}
	for _, bad := range []string{"APP_KEY=x", "LR_CADDY_IMAGE=not an image", "PATH=/tmp", "LAUNCHROOM_RELEASE_BASE=file:///etc",
		"LR_CADDY_IMAGE=", "LR_CADDY_IMAGE=-v", "LR_CADDY_IMAGE=Upper/case", "LR_CADDY_IMAGE=x:tag;rm", "LR_CADDY_IMAGE=x@sha256:short", "LR_CADDY_IMAGE=x\ny"} {
		if _, err := FilterInstallEnv([]string{bad}); err == nil {
			t.Errorf("%s accepted", bad)
		}
	}
}

func TestNewTaskID(t *testing.T) {
	a, b := newTaskID(time.Now()), newTaskID(time.Now())
	if err := a.Validate(); err != nil {
		t.Fatal(a, err)
	}
	if a == b {
		t.Error("ids repeat")
	}
}

func TestMigrationFailure(t *testing.T) {
	logs := "[launchroom] running migrations\n\n   Illuminate\\Database\\QueryException\n[launchroom] migration-failed 2026_11_16_120000_add_x (QueryException: column \"x\" already exists)\n"
	if got := MigrationFailure(logs); got != `2026_11_16_120000_add_x (QueryException: column "x" already exists)` {
		t.Errorf("got %q", got)
	}
	if got := MigrationFailure("[launchroom] starting: frankenphp\n"); got != "" {
		t.Errorf("got %q", got)
	}
}

func TestManualPlanValidate(t *testing.T) {
	p := Plan{TaskID: testTask, Scope: state.UpdateControlPlane, ProjectDir: "/opt/launchroom", DataDir: "/opt/launchroom/data/daemon",
		SocketPath: "/var/run/docker.sock", ComposeImage: state.ImageRef("docker.io/library/docker@sha256:" + strings.Repeat("6", 64)), Manual: true}
	if err := p.validate(); err != nil {
		t.Fatalf("pull-only manual plan: %v", err)
	}
	p.BundleDir = "/tmp/bundle"
	if err := p.validate(); err == nil {
		t.Error("bundle outside the data folder accepted")
	}
	p.BundleDir, p.Scope = "/opt/launchroom/data/daemon/update/tasks/x/bundle", state.UpdateDaemon
	if err := p.validate(); err == nil {
		t.Error("manual daemon-scope plan accepted")
	}
	p.Manual, p.Scope = false, state.UpdateControlPlane
	p.BundleDir = ""
	if err := p.validate(); err == nil {
		t.Error("a task plan without a bundle accepted")
	}
}

func TestFindFailedRollback(t *testing.T) {
	dir := t.TempDir()
	mustWrite(t, filepath.Join(dir, "daemon-compose.yml"), "services: {}\n")
	if _, err := FindFailedRollback(dir, ""); !errors.Is(err, ErrNothingToFinish) {
		t.Fatalf("empty: %v", err)
	}
	data := filepath.Join(dir, "data", "daemon")
	older := state.TaskID("tsk_01J9Z8X7W6V5T4S3R2Q1P0N9P1")
	for id, st := range map[state.TaskID]Status{
		testTask: {TaskID: testTask, Scope: state.UpdateControlPlane, From: "0.1.5", To: "0.1.6", Outcome: OutcomeFailed, RollbackFailed: true,
			Error: "x; ROLLBACK FAILED starting the previous version: y", FinishedAt: time.Date(2026, 11, 17, 10, 0, 0, 0, time.UTC)},
		// An older status without the flag (written before it existed).
		older: {TaskID: older, Scope: state.UpdateControlPlane, From: "0.1.4", To: "0.1.5", Outcome: OutcomeFailed,
			Error: "x; ROLLBACK FAILED restoring files: y", FinishedAt: time.Date(2026, 11, 1, 10, 0, 0, 0, time.UTC)},
		"tsk_01J9Z8X7W6V5T4S3R2Q1P0N9P2": {TaskID: "tsk_01J9Z8X7W6V5T4S3R2Q1P0N9P2", Outcome: OutcomeRolledBack, FinishedAt: time.Now()},
	} {
		if err := os.MkdirAll(taskDir(data, id), 0o700); err != nil {
			t.Fatal(err)
		}
		if err := writeStatus(data, st); err != nil {
			t.Fatal(err)
		}
	}
	mustWrite(t, filepath.Join(dir, "data", "postgres", "launchroom-pre-upgrade.dump"), "dump")
	f, err := FindFailedRollback(dir, "")
	if err != nil || f.Status.TaskID != testTask || !f.Dump || f.Backup != filepath.Join(taskDir(data, testTask), dirBackup) {
		t.Fatalf("%+v %v", f, err)
	}
	if f, err := FindFailedRollback(dir, older); err != nil || f.Status.TaskID != older {
		t.Fatalf("by id: %+v %v", f, err)
	}
	// On a guarded install only the supervisor's own backup is trusted.
	mustWrite(t, filepath.Join(dir, "guard", "trust.json"), "{}")
	if f, _ := FindFailedRollback(dir, ""); f.Backup != filepath.Join(dir, "guard", "update", string(testTask), "backup") {
		t.Errorf("guarded backup = %s", f.Backup)
	}
}

func TestSavedList(t *testing.T) {
	dir := t.TempDir()
	mustWrite(t, filepath.Join(dir, "daemon-compose.yml"), "services: {}\n")
	backup := filepath.Join(dir, "backup")
	saved, err := backupFiles(dir, backup, cpManagedFiles)
	if err != nil {
		t.Fatal(err)
	}
	if err := writeJSON(filepath.Join(backup, fileSaved), saved); err != nil {
		t.Fatal(err)
	}
	got, err := savedList(dir, backup)
	if err != nil || len(got) != len(cpManagedFiles) {
		t.Fatalf("%+v %v", got, err)
	}
	// A list naming a file outside the managed set is refused.
	mustWrite(t, filepath.Join(backup, fileSaved), `[{"Rel":"../../etc/passwd","Mode":420,"UID":0,"GID":0}]`)
	if _, err := savedList(dir, backup); err == nil {
		t.Error("foreign file accepted")
	}
	// Without a list: the managed files found in the backup.
	_ = os.Remove(filepath.Join(backup, fileSaved))
	mustWrite(t, filepath.Join(backup, ".env"), "A=1\n")
	got, err = savedList(dir, backup)
	if err != nil || len(got) != 2 || !slices.ContainsFunc(got, func(f savedFile) bool { return f.Rel == ".env" && f.Mode == 0o600 }) {
		t.Fatalf("fallback: %+v %v", got, err)
	}
}

func mustWrite(t *testing.T, path, content string) {
	t.Helper()
	if err := os.MkdirAll(filepath.Dir(path), 0o700); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(path, []byte(content), 0o600); err != nil {
		t.Fatal(err)
	}
}
