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
	h := BackupHandler(BackupOptions{ServerID: bServer, SealingKey: key, Engine: fe, Client: &fakeCP{}})

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
	fc := &fakeCP{}
	h := BackupHandler(BackupOptions{ServerID: bServer, SealingKey: key, Engine: fe, Client: fc})

	res, err := h(t.Context(), backupTask(t, key.PublicKey(), state.TaskBackup, s3Env, bTask))
	if err != nil || res.Backup == nil || res.Backup.SizeBytes != 3 {
		t.Fatalf("backup: %+v %v", res, err)
	}
	if fe.creds.SecretAccessKey.Reveal() != "s3-secret-value" || fe.creds.ResticPassword.Reveal() != "repo-password-value" {
		t.Fatal("credentials not passed through")
	}
	for typ, check := range map[state.TaskType]func(Result) bool{
		state.TaskRestore:           func(r Result) bool { return r.Restore != nil },
		state.TaskBackupSnapshots:   func(r Result) bool { return r.Snapshots != nil },
		state.TaskBackupMaintenance: func(r Result) bool { return r.Maintenance != nil },
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
	fc := &fakeCP{}
	fc.lease = []state.Task{backupTask(t, key.PublicKey(), state.TaskBackup, s3Env, bTask)}
	h := BackupHandler(BackupOptions{ServerID: bServer, SealingKey: key, Engine: &fakeBackupEngine{}, Client: fc})
	r := New(Options{Client: fc, Handlers: map[state.TaskType]Handler{state.TaskBackup: h}})
	r.LeaseOnce(t.Context())
	r.wg.Wait()
	done := fc.completion(bTask)
	if done == nil || done.Outcome != state.OutcomeSucceeded || done.Backup == nil {
		t.Fatalf("completion %+v", done)
	}
	if err := done.Validate(); err != nil {
		t.Fatal(err)
	}

	fc = &fakeCP{}
	fc.lease = []state.Task{backupTask(t, key.PublicKey(), state.TaskBackup, s3Env, bTask)}
	h = BackupHandler(BackupOptions{ServerID: bServer, SealingKey: key, Engine: &fakeBackupEngine{err: errors.New("backup: pg_dump exited with code 1: boom")}, Client: fc})
	r = New(Options{Client: fc, Handlers: map[state.TaskType]Handler{state.TaskBackup: h}})
	r.LeaseOnce(t.Context())
	r.wg.Wait()
	done = fc.completion(bTask)
	if done == nil || done.Outcome != state.OutcomeFailed || done.Backup != nil || !strings.Contains(done.Error, "pg_dump exited") {
		t.Fatalf("completion %+v", done)
	}
}
