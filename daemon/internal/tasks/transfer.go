package tasks

import (
	"context"
	"crypto/ecdh"
	"errors"
	"fmt"
	"slices"

	"github.com/vantezzen/launchroom/daemon/internal/backup"
	"github.com/vantezzen/launchroom/daemon/internal/sealed"
	"github.com/vantezzen/launchroom/daemon/internal/secret"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// TransferEngine runs imports and exports (backup.Engine, D55).
type TransferEngine interface {
	ImportDatabase(ctx context.Context, task state.TaskID, spec *state.DbImportSpec, sourceURL secret.Secret, logf backup.Logf, progress backup.Progress) (*state.DataImportResult, error)
	ImportVolume(ctx context.Context, task state.TaskID, spec *state.VolumeImportSpec, logf backup.Logf, progress backup.Progress) (*state.DataImportResult, error)
	ExportDatabase(ctx context.Context, spec *state.DbExportSpec, creds backup.Credentials, logf backup.Logf, progress backup.Progress) (*state.DataExportResult, error)
	ExportVolume(ctx context.Context, spec *state.VolumeExportSpec, creds backup.Credentials, logf backup.Logf, progress backup.Progress) (*state.DataExportResult, error)
}

// TransferOptions configures TransferHandler.
type TransferOptions struct {
	ServerID   state.ServerID
	SealingKey *ecdh.PrivateKey
	Engine     TransferEngine
	Client     Client
	// Slots is the server's heavy-work slot, shared with the backup handler
	// (imports and exports take it; inspections don't).
	Slots chan struct{}
}

// TransferHandler returns the handler for "db_import", "volume_import",
// "db_export" and "volume_export" tasks (D55, docs/14 §12). A url source's
// SOURCE_URL and a snapshot export's destination credentials are TaskEnvs
// sealed to this server and task.
func TransferHandler(o TransferOptions) Handler {
	if o.Slots == nil {
		o.Slots = make(chan struct{}, 1)
	}
	return func(ctx context.Context, t state.Task) (Result, error) {
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
			return Result{}, errors.New("transfer handler got another spec")
		}
		if inspect {
			step = "inspect"
		}
		logs := newStepShipper(ctx, o.Client, state.LogSource{Kind: state.LogSourceTask, ID: string(t.ID)}, t.Attempt)
		defer logs.Close()
		logf := func(format string, args ...any) { //nolint:contextcheck // logs ship on the shipper's own context
			logs.Line(step, state.StreamStdout, fmt.Sprintf(format, args...))
		}
		progress := func(line string) { SetProgress(ctx, line) }
		if !inspect {
			select {
			case o.Slots <- struct{}{}:
			default:
				logf("Waiting for another backup, restore, import or export on this server to finish")
				progress("Waiting for another backup or import on this server")
				select {
				case o.Slots <- struct{}{}:
				case <-ctx.Done():
					return Result{}, ctx.Err()
				}
			}
			defer func() { <-o.Slots }()
		}
		res, err := o.run(ctx, t, logf, progress)
		if err != nil {
			logs.Line(step, state.StreamStderr, err.Error()) //nolint:contextcheck // see above
			return Result{}, err
		}
		return res, nil
	}
}

func (o TransferOptions) run(ctx context.Context, t state.Task, logf backup.Logf, progress backup.Progress) (Result, error) {
	switch spec := t.Spec.(type) {
	case *state.DbImportSpec:
		var url secret.Secret
		if spec.Source != nil && spec.Source.Kind == state.SourceURL && spec.Source.URL != nil {
			env, err := sealed.OpenTaskEnv(*spec.Source.URL, o.SealingKey, o.ServerID, t.ID)
			if err != nil {
				return Result{}, fmt.Errorf("source url: %w", err)
			}
			names := make([]string, 0, len(env))
			for k := range env {
				names = append(names, k)
			}
			if !slices.Equal(names, []string{backup.EnvSourceURL}) || env[backup.EnvSourceURL].IsZero() {
				return Result{}, fmt.Errorf("source url: the sealed env must hold exactly %s", backup.EnvSourceURL)
			}
			url = env[backup.EnvSourceURL]
		}
		r, err := o.Engine.ImportDatabase(ctx, t.ID, spec, url, logf, progress)
		return Result{DataImport: r}, err
	case *state.VolumeImportSpec:
		r, err := o.Engine.ImportVolume(ctx, t.ID, spec, logf, progress)
		return Result{DataImport: r}, err
	case *state.DbExportSpec:
		creds, err := o.creds(spec.Destination, t.ID)
		if err != nil {
			return Result{}, err
		}
		r, err := o.Engine.ExportDatabase(ctx, spec, creds, logf, progress)
		return Result{DataExport: r}, err
	case *state.VolumeExportSpec:
		creds, err := o.creds(spec.Destination, t.ID)
		if err != nil {
			return Result{}, err
		}
		r, err := o.Engine.ExportVolume(ctx, spec, creds, logf, progress)
		return Result{DataExport: r}, err
	}
	return Result{}, errors.New("transfer handler got another spec")
}

// creds opens a snapshot export's destination credentials (none for live exports).
func (o TransferOptions) creds(dest *state.BackupDestination, task state.TaskID) (backup.Credentials, error) {
	if dest == nil {
		return backup.Credentials{}, nil
	}
	env, err := sealed.OpenTaskEnv(dest.Credentials, o.SealingKey, o.ServerID, task)
	if err != nil {
		return backup.Credentials{}, fmt.Errorf("destination credentials: %w", err)
	}
	return backup.CredentialsFromEnv(dest.Kind, env)
}
