package backup_test

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
	"github.com/vantezzen/launchroom/daemon/internal/tasks"
	"github.com/vantezzen/launchroom/daemon/internal/tasks/taskstest"
)

type fakeBackupEngine struct {
	creds  backup.Credentials
	target backup.PostgresTarget
	err    error
}

func (f *fakeBackupEngine) Backup(_ context.Context, _ *state.BackupSpec, c backup.Credentials, logf backup.Logf) (*state.BackupResult, error) {
	f.creds = c
	logf("streaming")
	if f.err != nil {
		return nil, f.err
	}
	return &state.BackupResult{SnapshotID: state.SnapshotID(strings.Repeat("a", 64)), SizeBytes: 3, DurationMs: 1}, nil
}

func (f *fakeBackupEngine) Restore(_ context.Context, _ *state.RestoreSpec, c backup.Credentials, _ backup.Logf) (*state.RestoreResult, error) {
	f.creds = c
	return &state.RestoreResult{SizeBytes: 3}, f.err
}

func (f *fakeBackupEngine) Snapshots(_ context.Context, _ *state.BackupSnapshotsSpec, c backup.Credentials) (*state.BackupSnapshotsResult, error) {
	f.creds = c
	return &state.BackupSnapshotsResult{Snapshots: []state.SnapshotInfo{}}, f.err
}

func (f *fakeBackupEngine) Maintenance(_ context.Context, _ *state.BackupMaintenanceSpec, c backup.Credentials, _ backup.Logf) (*state.MaintenanceResult, error) {
	f.creds = c
	return &state.MaintenanceResult{RemovedSnapshots: 1}, f.err
}

func (f *fakeBackupEngine) RestorePointInTime(_ context.Context, _ *state.RestoreSpec, c backup.Credentials, target backup.PostgresTarget, _ backup.Logf) (*state.RestoreResult, error) {
	f.creds, f.target = c, target
	at := time.Date(2026, 10, 19, 12, 0, 0, 0, time.UTC)
	return &state.RestoreResult{SizeBytes: 5, RestoredTo: &at}, f.err
}

func (f *fakeBackupEngine) Verify(_ context.Context, _ *state.VerifyRestoreSpec, c backup.Credentials, _ backup.Logf) (*state.VerifyResult, error) {
	f.creds = c
	return &state.VerifyResult{Passed: true, Checks: []state.VerifyCheck{{Name: state.VerifyCheckRestore, Passed: true}}}, f.err
}

const (
	bServer = state.ServerID("srv_01J9Z8X7W6V5T4S3R2Q1P0N9MK")
	bTask   = state.TaskID("tsk_01J9Z8X7W6V5T4S3R2Q1P0N9P0")
)

func backupTask(t *testing.T, pub *ecdh.PublicKey, typ state.TaskType, env map[string]secret.Secret, boundTo state.TaskID) state.Task {
	t.Helper()
	creds, err := sealed.SealJSON(state.TaskEnv{ServerID: bServer, TaskID: boundTo, Env: env}, pub, rand.Reader)
	if err != nil {
		t.Fatal(err)
	}
	dest := state.BackupDestination{Kind: state.DestinationS3, Endpoint: "https://s3.example.com", Bucket: "backups", Credentials: creds}
	target := state.BackupTarget{Engine: state.EnginePostgres, Workload: "wl_01J9Z8X7W6V5T4S3R2Q1P0N9MK"}
	task := state.Task{ID: bTask, Type: typ, Attempt: 1, LeaseExpiresAt: time.Now(), Deadline: time.Now().Add(time.Hour)}
	switch typ {
	case state.TaskBackup:
		task.Spec = &state.BackupSpec{Kind: state.BackupDatabase, Target: target, Destination: dest, Tags: []state.BackupTag{}}
	case state.TaskRestore:
		task.Spec = &state.RestoreSpec{Kind: state.BackupDatabase, Snapshot: "1a2b3c4d", Target: target, Destination: dest, Mode: state.RestoreInPlace}
	case state.TaskBackupSnapshots:
		task.Spec = &state.BackupSnapshotsSpec{Destination: dest, Tags: []state.BackupTag{}}
	case state.TaskBackupMaintenance:
		task.Spec = &state.BackupMaintenanceSpec{Operation: state.MaintenancePrune, Destination: dest}
	case state.TaskVerifyRestore:
		task.Spec = &state.VerifyRestoreSpec{
			Kind: state.BackupDatabase, Snapshot: "1a2b3c4d", Destination: dest, Engine: state.EnginePostgres,
			Image: state.ImageRef("127.0.0.1:5000/mirror/docker.io/library/postgres@sha256:" + strings.Repeat("a", 64)), DataPath: "/var/lib/postgresql",
			Resources: state.Resources{CPUs: 1, MemoryMB: 512, Pids: 256},
		}
	}
	return task
}

// pitrTask is a base_backup restore whose runtime env is sealed like the
// destination credentials (a TaskEnv bound to the server and task).
func pitrTask(t *testing.T, pub *ecdh.PublicKey, runtimeEnv map[string]secret.Secret) state.Task {
	t.Helper()
	task := backupTask(t, pub, state.TaskRestore, s3Env, bTask)
	env, err := sealed.SealJSON(state.TaskEnv{ServerID: bServer, TaskID: bTask, Env: runtimeEnv}, pub, rand.Reader)
	if err != nil {
		t.Fatal(err)
	}
	spec := task.Spec.(*state.RestoreSpec)
	spec.Kind = state.BackupBaseBackup
	spec.Target = state.BackupTarget{Engine: state.EnginePostgres, Volume: "vol_01J9Z8X7W6V5T4S3R2Q1P0N9MK"}
	spec.Runtime = &state.RestoreRuntime{
		Image:     state.ImageRef("127.0.0.1:5000/mirror/docker.io/library/postgres@sha256:" + strings.Repeat("a", 64)),
		MountPath: "/var/lib/postgresql", DataDirectory: "/var/lib/postgresql/18/docker", Env: env,
		Resources: state.Resources{CPUs: 1, MemoryMB: 512, Pids: 256},
	}
	if err := spec.Validate(); err != nil {
		t.Fatal(err)
	}
	return task
}

func TestBackupHandlerPointInTimeAndVerify(t *testing.T) {
	key, _ := ecdh.X25519().GenerateKey(rand.Reader)
	fe := &fakeBackupEngine{}
	h := backup.Handler(backup.HandlerOptions{ServerID: bServer, SealingKey: key, Engine: fe, Client: &taskstest.ControlPlane{}})

	pgEnv := map[string]secret.Secret{"POSTGRES_USER": "app", "POSTGRES_PASSWORD": "target-pw-123", "POSTGRES_DB": "app"}
	res, err := h(t.Context(), pitrTask(t, key.PublicKey(), pgEnv))
	if err != nil || res.Restore == nil || res.Restore.RestoredTo == nil {
		t.Fatalf("point-in-time restore: %+v %v", res, err)
	}
	if fe.target.User != "app" || fe.target.Password.Reveal() != "target-pw-123" || fe.creds.ResticPassword.Reveal() != "repo-password-value" {
		t.Fatalf("target %+v not passed through", fe.target)
	}
	// The runtime env holds exactly the target's Postgres configuration.
	bad := map[string]secret.Secret{"POSTGRES_USER": "app", "POSTGRES_PASSWORD": "pw", "POSTGRES_DB": "app", "PGDATA": "/etc"}
	if _, err := h(t.Context(), pitrTask(t, key.PublicKey(), bad)); err == nil || strings.Contains(err.Error(), "target-pw") {
		t.Fatalf("extra runtime env accepted: %v", err)
	}

	res, err = h(t.Context(), backupTask(t, key.PublicKey(), state.TaskVerifyRestore, s3Env, bTask))
	if err != nil || res.Verify == nil || !res.Verify.Passed {
		t.Fatalf("verify: %+v %v", res, err)
	}
}

var s3Env = map[string]secret.Secret{"AWS_ACCESS_KEY_ID": "AKIA1", "AWS_SECRET_ACCESS_KEY": "s3-secret-value", "RESTIC_PASSWORD": "repo-password-value"}

func TestBackupHandler(t *testing.T) {
	key, _ := ecdh.X25519().GenerateKey(rand.Reader)
	fe := &fakeBackupEngine{}
	fc := &taskstest.ControlPlane{}
	h := backup.Handler(backup.HandlerOptions{ServerID: bServer, SealingKey: key, Engine: fe, Client: fc})

	res, err := h(t.Context(), backupTask(t, key.PublicKey(), state.TaskBackup, s3Env, bTask))
	if err != nil || res.Backup == nil || res.Backup.SizeBytes != 3 {
		t.Fatalf("backup: %+v %v", res, err)
	}
	if fe.creds.SecretAccessKey.Reveal() != "s3-secret-value" || fe.creds.ResticPassword.Reveal() != "repo-password-value" {
		t.Fatal("credentials not passed through")
	}
	for typ, check := range map[state.TaskType]func(state.TaskResults) bool{
		state.TaskRestore:           func(r state.TaskResults) bool { return r.Restore != nil },
		state.TaskBackupSnapshots:   func(r state.TaskResults) bool { return r.Snapshots != nil },
		state.TaskBackupMaintenance: func(r state.TaskResults) bool { return r.Maintenance != nil },
	} {
		res, err := h(t.Context(), backupTask(t, key.PublicKey(), typ, s3Env, bTask))
		if err != nil || !check(res) {
			t.Fatalf("%s: %+v %v", typ, res, err)
		}
	}

	// Credentials bound to another task, or with extra/missing names, are refused.
	if _, err := h(t.Context(), backupTask(t, key.PublicKey(), state.TaskBackup, s3Env, "tsk_01J9Z8X7W6V5T4S3R2Q1P0N9P1")); err == nil {
		t.Fatal("credentials sealed for another task accepted")
	}
	extra := map[string]secret.Secret{"AWS_ACCESS_KEY_ID": "AKIA1", "AWS_SECRET_ACCESS_KEY": "s3-secret-value", "RESTIC_PASSWORD": "repo-password-value", "RESTIC_REPOSITORY_FILE": "/etc/passwd"}
	_, err = h(t.Context(), backupTask(t, key.PublicKey(), state.TaskBackup, extra, bTask))
	if err == nil || strings.Contains(err.Error(), "s3-secret-value") {
		t.Fatalf("extra credential name: %v", err)
	}
}

func TestRunnerReportsBackupResults(t *testing.T) {
	key, _ := ecdh.X25519().GenerateKey(rand.Reader)
	fc := &taskstest.ControlPlane{}
	fc.Lease = []state.Task{backupTask(t, key.PublicKey(), state.TaskBackup, s3Env, bTask)}
	h := backup.Handler(backup.HandlerOptions{ServerID: bServer, SealingKey: key, Engine: &fakeBackupEngine{}, Client: fc})
	r := tasks.New(tasks.Options{Client: fc, Handlers: map[state.TaskType]tasks.Handler{state.TaskBackup: h}})
	r.LeaseOnce(t.Context())
	r.Wait()
	done := fc.Completion(bTask)
	if done == nil || done.Outcome != state.OutcomeSucceeded || done.Backup == nil {
		t.Fatalf("completion %+v", done)
	}
	if err := done.Validate(); err != nil {
		t.Fatal(err)
	}

	fc = &taskstest.ControlPlane{}
	fc.Lease = []state.Task{backupTask(t, key.PublicKey(), state.TaskBackup, s3Env, bTask)}
	h = backup.Handler(backup.HandlerOptions{ServerID: bServer, SealingKey: key, Engine: &fakeBackupEngine{err: errors.New("backup: pg_dump exited with code 1: boom")}, Client: fc})
	r = tasks.New(tasks.Options{Client: fc, Handlers: map[state.TaskType]tasks.Handler{state.TaskBackup: h}})
	r.LeaseOnce(t.Context())
	r.Wait()
	done = fc.Completion(bTask)
	if done == nil || done.Outcome != state.OutcomeFailed || done.Backup != nil || !strings.Contains(done.Error, "pg_dump exited") {
		t.Fatalf("completion %+v", done)
	}
}

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
	h := backup.TransferHandler(backup.TransferOptions{ServerID: bServer, SealingKey: key, Engine: fe, Client: &taskstest.ControlPlane{}})
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
	h := backup.TransferHandler(backup.TransferOptions{ServerID: bServer, SealingKey: key, Engine: fe, Client: &taskstest.ControlPlane{}, Slots: slots})
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
