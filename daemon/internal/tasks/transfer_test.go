package tasks

import (
	"context"
	"crypto/ecdh"
	"crypto/rand"
	"errors"
	"strings"
	"testing"
	"time"

	"github.com/vantezzen/launchroom/daemon/internal/backup"
	"github.com/vantezzen/launchroom/daemon/internal/sealed"
	"github.com/vantezzen/launchroom/daemon/internal/secret"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

type fakeTransferEngine struct {
	url   secret.Secret
	creds backup.Credentials
	block chan struct{}
}

func (f *fakeTransferEngine) ImportDatabase(_ context.Context, _ state.TaskID, spec *state.DbImportSpec, url secret.Secret, _ backup.Logf, progress backup.Progress) (*state.DataImportResult, error) {
	f.url = url
	progress("Importing")
	if f.block != nil {
		<-f.block
	}
	return &state.DataImportResult{Mode: spec.Mode, Tables: 3}, nil
}

func (f *fakeTransferEngine) ImportVolume(context.Context, state.TaskID, *state.VolumeImportSpec, backup.Logf, backup.Progress) (*state.DataImportResult, error) {
	return &state.DataImportResult{Mode: state.ImportRun, Files: 2}, nil
}

func (f *fakeTransferEngine) ExportDatabase(_ context.Context, _ *state.DbExportSpec, c backup.Credentials, _ backup.Logf, _ backup.Progress) (*state.DataExportResult, error) {
	f.creds = c
	return &state.DataExportResult{Format: state.FormatPostgresCustom, SHA256: state.SHA256Hex(strings.Repeat("a", 64))}, nil
}

func (f *fakeTransferEngine) ExportVolume(context.Context, *state.VolumeExportSpec, backup.Credentials, backup.Logf, backup.Progress) (*state.DataExportResult, error) {
	return nil, errors.New("boom")
}

func importTask(t *testing.T, pub *ecdh.PublicKey, mode state.DataImportMode, env map[string]secret.Secret) state.Task {
	t.Helper()
	box, err := sealed.SealJSON(state.TaskEnv{ServerID: bServer, TaskID: bTask, Env: env}, pub, rand.Reader)
	if err != nil {
		t.Fatal(err)
	}
	return state.Task{ID: bTask, Type: state.TaskDbImport, Attempt: 1, LeaseExpiresAt: time.Now(), Deadline: time.Now().Add(time.Hour),
		Spec: &state.DbImportSpec{Mode: mode, Engine: state.EnginePostgres, Workload: "wl_01J9Z8X7W6V5T4S3R2Q1P0N9MK",
			Source: &state.DbImportSource{Kind: state.SourceURL, URL: &box}, Resources: state.Resources{CPUs: 1, MemoryMB: 512, Pids: 256}}}
}

func TestTransferHandlerOpensTheSourceURL(t *testing.T) {
	key, _ := ecdh.X25519().GenerateKey(rand.Reader)
	fe := &fakeTransferEngine{}
	h := TransferHandler(TransferOptions{ServerID: bServer, SealingKey: key, Engine: fe, Client: &fakeCP{}})
	res, err := h(t.Context(), importTask(t, key.PublicKey(), state.ImportRun, map[string]secret.Secret{"SOURCE_URL": "postgres://u:p@h/db"}))
	if err != nil || res.DataImport == nil || res.DataImport.Tables != 3 || fe.url.Reveal() != "postgres://u:p@h/db" {
		t.Fatalf("%+v %v", res, err)
	}
	// Anything but exactly SOURCE_URL is refused.
	if _, err := h(t.Context(), importTask(t, key.PublicKey(), state.ImportRun, map[string]secret.Secret{"SOURCE_URL": "x", "OTHER": "y"})); err == nil {
		t.Fatal("extra env accepted")
	}
	// Sealed to another key: refused.
	other, _ := ecdh.X25519().GenerateKey(rand.Reader)
	if _, err := h(t.Context(), importTask(t, other.PublicKey(), state.ImportRun, map[string]secret.Secret{"SOURCE_URL": "x"})); err == nil {
		t.Fatal("foreign seal accepted")
	}
}

func TestTransferHandlerSharesTheHeavySlot(t *testing.T) {
	key, _ := ecdh.X25519().GenerateKey(rand.Reader)
	slots := make(chan struct{}, 1)
	slots <- struct{}{} // a backup holds the slot
	fe := &fakeTransferEngine{}
	h := TransferHandler(TransferOptions{ServerID: bServer, SealingKey: key, Engine: fe, Client: &fakeCP{}, Slots: slots})
	// Inspections don't wait for it.
	if _, err := h(t.Context(), importTask(t, key.PublicKey(), state.ImportInspect, map[string]secret.Secret{"SOURCE_URL": "x"})); err != nil {
		t.Fatal(err)
	}
	// Imports do.
	ctx, cancel := context.WithTimeout(t.Context(), 200*time.Millisecond)
	defer cancel()
	if _, err := h(ctx, importTask(t, key.PublicKey(), state.ImportRun, map[string]secret.Secret{"SOURCE_URL": "x"})); !errors.Is(err, context.DeadlineExceeded) {
		t.Fatalf("import ran while the slot was taken: %v", err)
	}
	<-slots
	task := importTask(t, key.PublicKey(), state.ImportRun, map[string]secret.Secret{"SOURCE_URL": "x"})
	task.Type, task.Spec = state.TaskVolumeExport, &state.VolumeExportSpec{Volume: "vol_01J9Z8X7W6V5T4S3R2Q1P0N9N9", Transfer: state.TransferTarget{ID: "dtr_01J9Z8X7W6V5T4S3R2Q1P0N9R0", ChunkBytes: 1 << 23}}
	if _, err := h(t.Context(), task); err == nil || len(slots) != 0 {
		t.Fatalf("failed export: %v, slot held %d", err, len(slots))
	}
}
