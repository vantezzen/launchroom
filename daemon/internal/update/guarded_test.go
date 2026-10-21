package update

import (
	"encoding/json"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// guardedInstall lays out an install folder with guard/trust.json and a
// staged, signed bundle for task, as the handler leaves it.
func guardedInstall(t *testing.T, k testKey, version string, tarball, sig []byte) *Plan {
	t.Helper()
	proj := t.TempDir()
	data := filepath.Join(proj, "data")
	task := state.TaskID("tsk_01J9Z8X7W6V5T4S3R2Q1P0N9M8")
	dir := taskDir(data, task)
	if err := os.MkdirAll(dir, 0o700); err != nil {
		t.Fatal(err)
	}
	if err := os.MkdirAll(filepath.Join(proj, dirGuard), 0o700); err != nil {
		t.Fatal(err)
	}
	raw, _ := json.Marshal(k.trust("http://127.0.0.1:1"))
	if err := os.WriteFile(guardTrustPath(proj), raw, 0o600); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(filepath.Join(dir, fileBundleTar), tarball, 0o600); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(filepath.Join(dir, fileBundleSig), sig, 0o600); err != nil {
		t.Fatal(err)
	}
	return &Plan{TaskID: task, Scope: state.UpdateDaemon, From: "1.0.0", To: version, ProjectDir: proj, DataDir: data,
		BundleDir: filepath.Join(dir, dirBundle), DaemonImage: state.ImageRef("ghcr.io/evil/x@sha256:" + strings.Repeat("9", 64)), ComposeImage: state.ImageRef("ghcr.io/evil/y@sha256:" + strings.Repeat("9", 64))}
}

func TestGuardedSupervisorVerifiesAgain(t *testing.T) {
	k := newTestKey(t)
	tarball := makeTarball(t, bundleFiles("1.2.0"))
	sig := k.sign(t, tarball)
	s := &Supervisor{Version: "1.0.0"}

	p := guardedInstall(t, k, "1.2.0", tarball, sig)
	if !Guarded(p.ProjectDir) {
		t.Fatal("not guarded")
	}
	backup, err := s.prepareGuarded(t.Context(), p)
	if err != nil {
		t.Fatal(err)
	}
	own := filepath.Join(p.ProjectDir, dirGuard, dirUpdate, string(p.TaskID))
	if backup != filepath.Join(own, dirBackup) || p.BundleDir != filepath.Join(own, dirBundle) {
		t.Fatalf("backup %s bundle %s", backup, p.BundleDir)
	}
	// Image pins come from the verified bundle, not from the plan.
	if p.DaemonImage != testDaemonImage || p.ComposeImage != testComposeImage {
		t.Fatalf("images %s %s", p.DaemonImage, p.ComposeImage)
	}
	if _, err := os.Stat(filepath.Join(p.BundleDir, "install", "server.sh")); err != nil {
		t.Fatal(err)
	}

	// A bundle launchroomd swapped after the handler verified it.
	evil := makeTarball(t, map[string][]byte{"VERSION": []byte("1.2.0\n"), "install/server.sh": []byte("#!/bin/sh\nrm -rf /\n")})
	p = guardedInstall(t, k, "1.2.0", evil, sig)
	if _, err := s.prepareGuarded(t.Context(), p); err == nil || !strings.Contains(err.Error(), "does not verify") {
		t.Fatalf("swapped bundle: %v", err)
	}
	// Signed by someone else.
	other := newTestKey(t)
	p = guardedInstall(t, k, "1.2.0", tarball, other.sign(t, tarball))
	if _, err := s.prepareGuarded(t.Context(), p); err == nil {
		t.Fatal("foreign signature accepted")
	}
	// A downgrade (signed, but older than the supervisor).
	old := makeTarball(t, bundleFiles("0.9.0"))
	p = guardedInstall(t, k, "0.9.0", old, k.sign(t, old))
	if _, err := s.prepareGuarded(t.Context(), p); err == nil {
		t.Fatal("downgrade accepted")
	}
	// The plan names another version than the bundle.
	p = guardedInstall(t, k, "1.3.0", tarball, sig)
	if _, err := s.prepareGuarded(t.Context(), p); err == nil {
		t.Fatal("version mismatch accepted")
	}
	// Not guarded: no guard/trust.json.
	if Guarded(t.TempDir()) {
		t.Fatal("guarded without a pin")
	}
}
