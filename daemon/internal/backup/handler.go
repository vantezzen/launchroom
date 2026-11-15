package backup

import (
	"context"
	"crypto/ecdh"
	"errors"
	"fmt"
	"slices"

	"github.com/vantezzen/launchroom/daemon/internal/sealed"
	"github.com/vantezzen/launchroom/daemon/internal/secret"
	"github.com/vantezzen/launchroom/daemon/internal/state"
	"github.com/vantezzen/launchroom/daemon/internal/tasks"
)

// TaskEngine runs backup work (*Engine).
type TaskEngine interface {
	Backup(ctx context.Context, spec *state.BackupSpec, c Credentials, logf Logf) (*state.BackupResult, error)
	Restore(ctx context.Context, spec *state.RestoreSpec, c Credentials, logf Logf) (*state.RestoreResult, error)
	Snapshots(ctx context.Context, spec *state.BackupSnapshotsSpec, c Credentials) (*state.BackupSnapshotsResult, error)
	Maintenance(ctx context.Context, spec *state.BackupMaintenanceSpec, c Credentials, logf Logf) (*state.MaintenanceResult, error)
	RestorePointInTime(ctx context.Context, spec *state.RestoreSpec, c Credentials, target PostgresTarget, logf Logf) (*state.RestoreResult, error)
	Verify(ctx context.Context, spec *state.VerifyRestoreSpec, c Credentials, logf Logf) (*state.VerifyResult, error)
}

// HandlerOptions configures Handler.
type HandlerOptions struct {
	ServerID   state.ServerID
	SealingKey *ecdh.PrivateKey
	Engine     TaskEngine
	Client     tasks.Client
	// Slots bounds heavy work (backup, restore, maintenance) per server; one
	// at a time by default (docs/14 §7). Snapshot listings don't take a slot.
	Slots chan struct{}
}

// Handler returns the task handler for "backup", "restore",
// "backup_snapshots", "backup_maintenance" and "verify_restore" tasks.
// Destination credentials are a sealed TaskEnv bound to this server and
// task, holding exactly the names the destination kind needs; a
// point-in-time restore's runtime env is a second one (the target's
// Postgres credentials).
func Handler(o HandlerOptions) tasks.Handler {
	if o.Slots == nil {
		o.Slots = make(chan struct{}, 1)
	}
	return func(ctx context.Context, t state.Task) (state.TaskResults, error) {
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
			return state.TaskResults{}, errors.New("backup handler got a non-backup spec")
		}
		env, err := sealed.OpenTaskEnv(dest.Credentials, o.SealingKey, o.ServerID, t.ID)
		if err != nil {
			return state.TaskResults{}, fmt.Errorf("destination credentials: %w", err)
		}
		creds, err := CredentialsFromEnv(dest.Kind, env)
		if err != nil {
			return state.TaskResults{}, err
		}
		if spec, ok := t.Spec.(*state.BackupSnapshotsSpec); ok {
			res, err := o.Engine.Snapshots(ctx, spec, creds)
			return state.TaskResults{Snapshots: res}, err
		}

		logs := tasks.NewTaskLogShipper(ctx, o.Client, t.ID, t.Attempt)
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
				return state.TaskResults{}, ctx.Err()
			}
		}
		defer func() { <-o.Slots }()

		var res state.TaskResults
		switch spec := t.Spec.(type) {
		case *state.BackupSpec:
			res.Backup, err = o.Engine.Backup(ctx, spec, creds, logf)
		case *state.RestoreSpec:
			if spec.Kind == state.BackupBaseBackup && spec.Runtime != nil {
				var target PostgresTarget
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
			return state.TaskResults{}, err
		}
		return res, nil
	}
}

// restoreTarget opens a point-in-time restore's runtime env (a TaskEnv bound
// to this server and task).
func (o HandlerOptions) restoreTarget(rt *state.RestoreRuntime, task state.TaskID) (PostgresTarget, error) {
	env, err := sealed.OpenTaskEnv(rt.Env, o.SealingKey, o.ServerID, task)
	if err != nil {
		return PostgresTarget{}, fmt.Errorf("restore runtime env: %w", err)
	}
	return PostgresTargetFromEnv(env)
}

// TransferEngine runs imports and exports (*Engine, D55).
type TransferEngine interface {
	ImportDatabase(ctx context.Context, task state.TaskID, spec *state.DbImportSpec, sourceURL secret.Secret, logf Logf, progress Progress) (*state.DataImportResult, error)
	ImportVolume(ctx context.Context, task state.TaskID, spec *state.VolumeImportSpec, logf Logf, progress Progress) (*state.DataImportResult, error)
	ExportDatabase(ctx context.Context, spec *state.DbExportSpec, creds Credentials, logf Logf, progress Progress) (*state.DataExportResult, error)
	ExportVolume(ctx context.Context, spec *state.VolumeExportSpec, creds Credentials, logf Logf, progress Progress) (*state.DataExportResult, error)
}

// TransferOptions configures TransferHandler.
type TransferOptions struct {
	ServerID   state.ServerID
	SealingKey *ecdh.PrivateKey
	Engine     TransferEngine
	Client     tasks.Client
	// Slots is the server's heavy-work slot, shared with the backup handler
	// (imports and exports take it; inspections don't).
	Slots chan struct{}
}

// TransferHandler returns the task handler for "db_import", "volume_import",
// "db_export" and "volume_export" tasks (D55, docs/14 §12). A url source's
// SOURCE_URL and a snapshot export's destination credentials are TaskEnvs
// sealed to this server and task.
func TransferHandler(o TransferOptions) tasks.Handler {
	if o.Slots == nil {
		o.Slots = make(chan struct{}, 1)
	}
	return func(ctx context.Context, t state.Task) (state.TaskResults, error) {
		var inspect bool
		step := "import"
		switch spec := t.Spec.(type) {
		case *state.DbImportSpec:
			inspect = spec.Mode == state.ImportInspect
		case *state.VolumeImportSpec:
			inspect = spec.Mode == state.ImportInspect
		case *state.DbExportSpec, *state.VolumeExportSpec:
			step = "export"
		default:
			return state.TaskResults{}, errors.New("transfer handler got another spec")
		}
		if inspect {
			step = "inspect"
		}
		logs := tasks.NewTaskLogShipper(ctx, o.Client, t.ID, t.Attempt)
		defer logs.Close()
		logf := func(format string, args ...any) { //nolint:contextcheck // logs ship on the shipper's own context
			logs.Line(step, state.StreamStdout, fmt.Sprintf(format, args...))
		}
		progress := func(line string) { tasks.SetProgress(ctx, line) }
		if !inspect {
			select {
			case o.Slots <- struct{}{}:
			default:
				logf("Waiting for another backup, restore, import or export on this server to finish")
				progress("Waiting for another backup or import on this server")
				select {
				case o.Slots <- struct{}{}:
				case <-ctx.Done():
					return state.TaskResults{}, ctx.Err()
				}
			}
			defer func() { <-o.Slots }()
		}
		res, err := o.run(ctx, t, logf, progress)
		if err != nil {
			logs.Line(step, state.StreamStderr, err.Error()) //nolint:contextcheck // see above
			return state.TaskResults{}, err
		}
		return res, nil
	}
}

func (o TransferOptions) run(ctx context.Context, t state.Task, logf Logf, progress Progress) (state.TaskResults, error) {
	switch spec := t.Spec.(type) {
	case *state.DbImportSpec:
		var url secret.Secret
		if spec.Source != nil && spec.Source.Kind == state.SourceURL && spec.Source.URL != nil {
			env, err := sealed.OpenTaskEnv(*spec.Source.URL, o.SealingKey, o.ServerID, t.ID)
			if err != nil {
				return state.TaskResults{}, fmt.Errorf("source url: %w", err)
			}
			names := make([]string, 0, len(env))
			for k := range env {
				names = append(names, k)
			}
			if !slices.Equal(names, []string{EnvSourceURL}) || env[EnvSourceURL].IsZero() {
				return state.TaskResults{}, fmt.Errorf("source url: the sealed env must hold exactly %s", EnvSourceURL)
			}
			url = env[EnvSourceURL]
		}
		r, err := o.Engine.ImportDatabase(ctx, t.ID, spec, url, logf, progress)
		return state.TaskResults{DataImport: r}, err
	case *state.VolumeImportSpec:
		r, err := o.Engine.ImportVolume(ctx, t.ID, spec, logf, progress)
		return state.TaskResults{DataImport: r}, err
	case *state.DbExportSpec:
		creds, err := o.creds(spec.Destination, t.ID)
		if err != nil {
			return state.TaskResults{}, err
		}
		r, err := o.Engine.ExportDatabase(ctx, spec, creds, logf, progress)
		return state.TaskResults{DataExport: r}, err
	case *state.VolumeExportSpec:
		creds, err := o.creds(spec.Destination, t.ID)
		if err != nil {
			return state.TaskResults{}, err
		}
		r, err := o.Engine.ExportVolume(ctx, spec, creds, logf, progress)
		return state.TaskResults{DataExport: r}, err
	}
	return state.TaskResults{}, errors.New("transfer handler got another spec")
}

// creds opens a snapshot export's destination credentials (none for live exports).
func (o TransferOptions) creds(dest *state.BackupDestination, task state.TaskID) (Credentials, error) {
	if dest == nil {
		return Credentials{}, nil
	}
	env, err := sealed.OpenTaskEnv(dest.Credentials, o.SealingKey, o.ServerID, task)
	if err != nil {
		return Credentials{}, fmt.Errorf("destination credentials: %w", err)
	}
	return CredentialsFromEnv(dest.Kind, env)
}
