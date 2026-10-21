package tasks

import (
	"context"
	"crypto/ecdh"
	"errors"
	"fmt"

	"github.com/vantezzen/launchroom/daemon/internal/backup"
	"github.com/vantezzen/launchroom/daemon/internal/sealed"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// BackupEngine runs backup work (backup.Engine).
type BackupEngine interface {
	Backup(ctx context.Context, spec *state.BackupSpec, c backup.Credentials, logf backup.Logf) (*state.BackupResult, error)
	Restore(ctx context.Context, spec *state.RestoreSpec, c backup.Credentials, logf backup.Logf) (*state.RestoreResult, error)
	Snapshots(ctx context.Context, spec *state.BackupSnapshotsSpec, c backup.Credentials) (*state.BackupSnapshotsResult, error)
	Maintenance(ctx context.Context, spec *state.BackupMaintenanceSpec, c backup.Credentials, logf backup.Logf) (*state.MaintenanceResult, error)
	RestorePointInTime(ctx context.Context, spec *state.RestoreSpec, c backup.Credentials, target backup.PostgresTarget, logf backup.Logf) (*state.RestoreResult, error)
	Verify(ctx context.Context, spec *state.VerifyRestoreSpec, c backup.Credentials, logf backup.Logf) (*state.VerifyResult, error)
}

// BackupOptions configures BackupHandler.
type BackupOptions struct {
	ServerID   state.ServerID
	SealingKey *ecdh.PrivateKey
	Engine     BackupEngine
	Client     Client
	// Slots bounds heavy work (backup, restore, maintenance) per server; one
	// at a time by default (docs/14 §7). Snapshot listings don't take a slot.
	Slots chan struct{}
}

// BackupHandler returns the handler for "backup", "restore",
// "backup_snapshots", "backup_maintenance" and "verify_restore" tasks.
// Destination credentials are a sealed TaskEnv bound to this server and
// task, holding exactly the names the destination kind needs; a
// point-in-time restore's runtime env is a second one (the target's
// Postgres credentials).
func BackupHandler(o BackupOptions) Handler {
	if o.Slots == nil {
		o.Slots = make(chan struct{}, 1)
	}
	return func(ctx context.Context, t state.Task) (Result, error) {
		var dest *state.BackupDestination
		step := "backup"
		switch spec := t.Spec.(type) {
		case *state.BackupSpec:
			dest = &spec.Destination
		case *state.RestoreSpec:
			dest, step = &spec.Destination, "restore"
		case *state.BackupSnapshotsSpec:
			dest, step = &spec.Destination, "snapshots"
		case *state.BackupMaintenanceSpec:
			dest, step = &spec.Destination, "maintenance"
		case *state.VerifyRestoreSpec:
			dest, step = &spec.Destination, "verify"
		default:
			return Result{}, errors.New("backup handler got a non-backup spec")
		}
		env, err := sealed.OpenTaskEnv(dest.Credentials, o.SealingKey, o.ServerID, t.ID)
		if err != nil {
			return Result{}, fmt.Errorf("destination credentials: %w", err)
		}
		creds, err := backup.CredentialsFromEnv(dest.Kind, env)
		if err != nil {
			return Result{}, err
		}
		if spec, ok := t.Spec.(*state.BackupSnapshotsSpec); ok {
			res, err := o.Engine.Snapshots(ctx, spec, creds)
			return Result{Snapshots: res}, err
		}

		logs := newStepShipper(ctx, o.Client, state.LogSource{Kind: state.LogSourceTask, ID: string(t.ID)}, t.Attempt)
		defer logs.Close()
		logf := func(format string, args ...any) { //nolint:contextcheck // logs ship on the shipper's own context
			logs.Line(step, state.StreamStdout, fmt.Sprintf(format, args...))
		}
		select {
		case o.Slots <- struct{}{}:
		default:
			logf("Waiting for another backup or restore on this server to finish")
			select {
			case o.Slots <- struct{}{}:
			case <-ctx.Done():
				return Result{}, ctx.Err()
			}
		}
		defer func() { <-o.Slots }()

		var res Result
		switch spec := t.Spec.(type) {
		case *state.BackupSpec:
			res.Backup, err = o.Engine.Backup(ctx, spec, creds, logf)
		case *state.RestoreSpec:
			if spec.Kind == state.BackupBaseBackup && spec.Runtime != nil {
				var target backup.PostgresTarget
				if target, err = o.restoreTarget(spec.Runtime, t.ID); err == nil {
					res.Restore, err = o.Engine.RestorePointInTime(ctx, spec, creds, target, logf)
				}
				break
			}
			res.Restore, err = o.Engine.Restore(ctx, spec, creds, logf)
		case *state.BackupMaintenanceSpec:
			res.Maintenance, err = o.Engine.Maintenance(ctx, spec, creds, logf)
		case *state.VerifyRestoreSpec:
			res.Verify, err = o.Engine.Verify(ctx, spec, creds, logf)
		}
		if err != nil {
			logs.Line(step, state.StreamStderr, err.Error()) //nolint:contextcheck // see above
			return Result{}, err
		}
		return res, nil
	}
}

// restoreTarget opens a point-in-time restore's runtime env (a TaskEnv bound
// to this server and task).
func (o BackupOptions) restoreTarget(rt *state.RestoreRuntime, task state.TaskID) (backup.PostgresTarget, error) {
	env, err := sealed.OpenTaskEnv(rt.Env, o.SealingKey, o.ServerID, task)
	if err != nil {
		return backup.PostgresTarget{}, fmt.Errorf("restore runtime env: %w", err)
	}
	return backup.PostgresTargetFromEnv(env)
}
