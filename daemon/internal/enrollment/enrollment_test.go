package enrollment

import (
	"os"
	"path/filepath"
	"testing"
	"time"

	"github.com/vantezzen/launchroom/daemon/internal/state"
)

func TestSaveLoad(t *testing.T) {
	dir := t.TempDir()
	if r, err := Load(dir); err != nil || r != nil {
		t.Fatalf("empty dir: %v %v", r, err)
	}
	rec := &Record{
		ServerID:         "srv_01J9Z8X7W6V5T4S3R2Q1P0N9MK",
		ControlPlaneURL:  "https://dash.example.com",
		ControlPlaneKeys: map[state.KeyID]state.Ed25519Key{"cp-1": "ed25519:Kay64UG8yvCyLhqU000LxzYeUm0L/hLIl5S8kyKWbdc="},
		Status:           state.ServerActive,
		PollIntervals:    state.PollIntervals{State: "30s", Tasks: "10s"},
		EnrolledAt:       time.Date(2026, 10, 17, 12, 0, 0, 0, time.UTC),
	}
	if err := Save(dir, rec); err != nil {
		t.Fatal(err)
	}
	fi, err := os.Stat(filepath.Join(dir, FileName))
	if err != nil {
		t.Fatal(err)
	}
	if fi.Mode().Perm() != 0o600 {
		t.Fatalf("perm %v", fi.Mode().Perm())
	}
	got, err := Load(dir)
	if err != nil {
		t.Fatal(err)
	}
	if got.ServerID != rec.ServerID || len(got.ControlPlaneKeys) != 1 {
		t.Fatalf("got %+v", got)
	}
	ks, err := got.KeySet()
	if err != nil || len(ks["cp-1"]) != 32 {
		t.Fatalf("keyset %v %v", ks, err)
	}

	bad := *rec
	bad.ControlPlaneKeys = nil
	if err := Save(dir, &bad); err == nil {
		t.Fatal("record without keys saved")
	}
	if err := os.WriteFile(filepath.Join(dir, FileName), []byte(`{"serverId":"x"}`), 0o600); err != nil {
		t.Fatal(err)
	}
	if _, err := Load(dir); err == nil {
		t.Fatal("invalid record loaded")
	}
}
