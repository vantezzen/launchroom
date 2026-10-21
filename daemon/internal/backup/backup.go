package backup

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"path/filepath"
	"slices"
	"strconv"
	"strings"
	"sync/atomic"
	"time"

	"github.com/vantezzen/launchroom/daemon/internal/docker"
	"github.com/vantezzen/launchroom/daemon/internal/policy"
	"github.com/vantezzen/launchroom/daemon/internal/secret"
	"github.com/vantezzen/launchroom/daemon/internal/state"
	"github.com/vantezzen/launchroom/daemon/internal/transfer"
)

// Docker is what the engine needs from the Docker layer.
type Docker interface {
	ListContainers(ctx context.Context, labels map[string]string) ([]docker.Container, error)
	docker.Execer
}

// Logf receives short, secret-free progress lines for the task log.
type Logf func(format string, args ...any)

// Options configures an Engine.
type Options struct {
	// ResticBinary is the restic executable ("" = restic missing; every operation fails clearly).
	ResticBinary string
	// DataDir is the daemon data dir (restic cache; local destinations live in DataDir/backups).
	DataDir string
	// InsecureHosts may be reached over plain http (LR_BACKUP_INSECURE_HOSTS; dev MinIO).
	InsecureHosts []string
	Docker        Docker
	// WorkloadEnv opens the env of a workload in the verified NodeState.
	WorkloadEnv func(state.WorkloadID) (map[string]secret.Secret, error)
	Now         func() time.Time
	// ServerID labels the helper containers of volume backups.
	ServerID string
	// Volumes enables volume backups and restores (nil: unsupported).
	Volumes VolumeDocker
	// VolumeIdleTimeout bounds how long a volume restore waits for the
	// containers using the volume to go away (default 10 minutes).
	VolumeIdleTimeout time.Duration
	// PollEvery is the idle-wait poll interval (default 2 s).
	PollEvery time.Duration
	// Runtime runs database images in throwaway containers for
	// point-in-time restores and verifications (nil: unsupported).
	Runtime RuntimeDocker
	// Policy is the local policy floor those containers are checked against.
	Policy policy.Config
	// Workload returns the image and environment network of a workload in
	// the verified NodeState (imports run their throwaway there; D55).
	Workload func(state.WorkloadID) (WorkloadRuntime, error)
	// Isolate makes sure the firewall covers the managed networks before an
	// import's throwaway starts (firewall.Manager.Sync; nil = none, tests).
	Isolate func(ctx context.Context) error
	// Transfers fetches uploads and stores exports (nil: imports and exports unsupported).
	Transfers transfer.Client
	// TransferDir holds fetched uploads (default DataDir/transfers).
	TransferDir string
	// Free returns the free bytes of a path's filesystem (default: statfs).
	Free func(path string) (int64, bool)
}

// WorkloadRuntime is what an import needs to know about its target workload.
type WorkloadRuntime struct {
	Image state.ImageRef
	// Network is the host-side name of the environment network it is attached to.
	Network string
}

// Engine runs backup, restore, snapshot listing and maintenance.
type Engine struct {
	o       Options
	testEnv []string
}

// New returns an Engine.
func New(o Options) *Engine {
	if o.Now == nil {
		o.Now = time.Now
	}
	if o.VolumeIdleTimeout <= 0 {
		o.VolumeIdleTimeout = 10 * time.Minute
	}
	if o.PollEvery <= 0 {
		o.PollEvery = 2 * time.Second
	}
	if o.TransferDir == "" && o.DataDir != "" {
		o.TransferDir = filepath.Join(o.DataDir, "transfers")
	}
	return &Engine{o: o}
}

func nopLog(string, ...any) {}

// container returns the running container of a workload on this server.
func (e *Engine) container(ctx context.Context, wl state.WorkloadID) (string, error) {
	cs, err := e.o.Docker.ListContainers(ctx, map[string]string{docker.LabelWorkload: string(wl)})
	if err != nil {
		return "", err
	}
	for _, c := range cs {
		if c.Running && c.Labels[docker.LabelSystem] == "" {
			return c.ID, nil
		}
	}
	return "", fmt.Errorf("backup: workload %s has no running container on this server", wl)
}

func (e *Engine) workloadEnv(wl state.WorkloadID) (map[string]secret.Secret, error) {
	if e.o.WorkloadEnv == nil {
		return nil, errors.New("backup: workload env is not available")
	}
	env, err := e.o.WorkloadEnv(wl)
	if err != nil {
		return nil, fmt.Errorf("backup: env of workload %s: %w", wl, err)
	}
	return env, nil
}

// countingWriter counts bytes written through it.
type countingWriter struct {
	w io.Writer
	n atomic.Int64
}

func (c *countingWriter) Write(p []byte) (int, error) {
	n, err := c.w.Write(p)
	c.n.Add(int64(n))
	return n, err
}

// errDumpFailed marks the stream of a failed dump.
var errDumpFailed = errors.New("dump failed")

// Backup streams the engine's dump of a database workload into a new restic
// snapshot. Both exit codes are checked: a failed dump fails the backup even
// if restic stored a (truncated) snapshot, which is then forgotten.
func (e *Engine) Backup(ctx context.Context, spec *state.BackupSpec, creds Credentials, logf Logf) (*state.BackupResult, error) {
	if logf == nil {
		logf = nopLog
	}
	if spec.Kind == state.BackupVolume {
		return e.backupVolume(ctx, spec, creds, logf)
	}
	if spec.Kind != state.BackupDatabase && spec.Kind != state.BackupBaseBackup {
		return nil, fmt.Errorf("backup: %s backups: %w (control-plane backups run in the dashboard container)", spec.Kind, ErrUnsupported)
	}
	en, err := engineFor(spec.Target.Engine)
	if err != nil {
		return nil, err
	}
	env, err := e.workloadEnv(spec.Target.Workload)
	if err != nil {
		return nil, err
	}
	file, produce, what := en.file, en.dump, string(spec.Target.Engine)+" dump"
	if spec.Kind == state.BackupBaseBackup {
		if en.base == nil {
			return nil, fmt.Errorf("backup: base backups of %s: %w", spec.Target.Engine, ErrUnsupported)
		}
		file, produce, what = BaseBackupFile, en.base, "base backup"
	}
	native := spec.Target.Engine == state.EngineClickHouse && spec.Kind == state.BackupDatabase
	var cmd command
	if !native {
		if cmd, err = produce(env); err != nil {
			return nil, err
		}
	}
	id, err := e.container(ctx, spec.Target.Workload)
	if err != nil {
		return nil, err
	}
	r, err := e.openRepo(spec.Destination, creds)
	if err != nil {
		return nil, err
	}
	start := e.o.Now()
	if created, err := r.ensure(ctx); err != nil {
		return nil, err
	} else if created {
		logf("Initialised a new backup repository")
	}
	logf("Streaming the %s into the repository", what)

	args := []string{"backup", "--stdin", "--stdin-filename", file, "--host", resticHost, "--json"}
	for _, t := range spec.Tags {
		args = append(args, "--tag", string(t))
	}

	ctx, cancel := context.WithCancel(ctx)
	defer cancel()
	pr, pw := io.Pipe()
	counted := &countingWriter{w: pw}
	dumpDone := make(chan error, 1)
	go func() {
		if native {
			err := e.clickhouseDump(ctx, id, env, counted)
			if err != nil {
				_ = pw.CloseWithError(errDumpFailed)
			} else {
				_ = pw.Close()
			}
			dumpDone <- err
			return
		}
		res, err := e.o.Docker.ExecStream(ctx, id, docker.ExecOptions{Argv: cmd.argv, Env: cmd.env, Stdin: cmd.input(nil), Stdout: counted})
		switch {
		case err != nil:
			err = fmt.Errorf("backup: running %s: %w", cmd.argv[0], err)
		case res.ExitCode != 0:
			err = fmt.Errorf("backup: %s exited with code %d: %s", cmd.argv[0], res.ExitCode, cmd.masked(res.Stderr))
		}
		if err != nil {
			_ = pw.CloseWithError(errDumpFailed)
		} else {
			_ = pw.Close()
		}
		dumpDone <- err
	}()

	var out bytes.Buffer
	resticErr := r.run(ctx, "backup", pr, &out, args...)
	if resticErr != nil {
		// Unblock the dump if restic stopped reading.
		_ = pr.CloseWithError(errors.New("restic stopped"))
		cancel()
	}
	dumpErr := <-dumpDone

	summary := parseBackupSummary(out.Bytes())
	switch {
	case dumpErr != nil:
		if summary.SnapshotID != "" {
			// A valid snapshot of a failed dump is worse than none. (restic
			// sees EOF when the stream breaks and may have saved one; exec's
			// Wait then reports the copy error rather than restic's exit 0.)
			fctx, fcancel := context.WithTimeout(context.WithoutCancel(ctx), 2*time.Minute)
			_ = r.run(fctx, "forget", nil, nil, "forget", summary.SnapshotID)
			fcancel()
		}
		return nil, dumpErr
	case resticErr != nil:
		return nil, resticErr
	case summary.SnapshotID == "":
		return nil, errors.New("backup: restic did not report a snapshot id")
	}
	snap := state.SnapshotID(summary.SnapshotID)
	if err := snap.Validate(); err != nil {
		return nil, fmt.Errorf("backup: restic reported an invalid snapshot id: %w", err)
	}
	size := counted.n.Load()
	logf("Snapshot %s stored (%s, %s added)", summary.SnapshotID[:8], humanBytes(size), humanBytes(summary.DataAdded))
	return &state.BackupResult{
		SnapshotID: snap, SizeBytes: size, AddedBytes: max(summary.DataAdded, 0),
		DurationMs: e.o.Now().Sub(start).Milliseconds(),
	}, nil
}

type backupSummary struct {
	MessageType         string `json:"message_type"`
	SnapshotID          string `json:"snapshot_id"`
	DataAdded           int64  `json:"data_added"`
	TotalBytesProcessed int64  `json:"total_bytes_processed"`
}

func parseBackupSummary(out []byte) backupSummary {
	var s backupSummary
	jsonLines(out, func(line []byte) {
		var m backupSummary
		if json.Unmarshal(line, &m) == nil && m.MessageType == "summary" {
			s = m
		}
	})
	return s
}

// resticSnapshot is the subset of `restic snapshots --json` we use.
type resticSnapshot struct {
	ID      string    `json:"id"`
	Time    time.Time `json:"time"`
	Paths   []string  `json:"paths"`
	Tags    []string  `json:"tags"`
	Summary *struct {
		TotalBytesProcessed int64 `json:"total_bytes_processed"`
	} `json:"summary"`
}

func (r *repo) snapshots(ctx context.Context, args ...string) ([]resticSnapshot, error) {
	var out bytes.Buffer
	if err := r.run(ctx, "snapshots", nil, &out, append([]string{"snapshots", "--json"}, args...)...); err != nil {
		return nil, err
	}
	var snaps []resticSnapshot
	if err := json.Unmarshal(bytes.TrimSpace(out.Bytes()), &snaps); err != nil {
		return nil, errors.New("restic snapshots: unexpected output")
	}
	return snaps, nil
}

// Restore feeds a snapshot of the engine's dump into the engine's restore
// tool inside the target workload's container. Both exit codes are checked.
func (e *Engine) Restore(ctx context.Context, spec *state.RestoreSpec, creds Credentials, logf Logf) (*state.RestoreResult, error) {
	if logf == nil {
		logf = nopLog
	}
	if spec.Kind == state.BackupVolume {
		return e.restoreVolume(ctx, spec, creds, logf)
	}
	if spec.Kind != state.BackupDatabase {
		return nil, fmt.Errorf("backup: %s restores: %w", spec.Kind, ErrUnsupported)
	}
	en, err := engineFor(spec.Target.Engine)
	if err != nil {
		return nil, err
	}
	env, err := e.workloadEnv(spec.Target.Workload)
	if err != nil {
		return nil, err
	}
	native := spec.Target.Engine == state.EngineClickHouse
	var cmd command
	if !native {
		if cmd, err = en.restore(env); err != nil {
			return nil, err
		}
	}
	id, err := e.container(ctx, spec.Target.Workload)
	if err != nil {
		return nil, err
	}
	r, err := e.openRepo(spec.Destination, creds)
	if err != nil {
		return nil, err
	}
	start := e.o.Now()
	if ok, err := r.exists(ctx); err != nil {
		return nil, err
	} else if !ok {
		return nil, errors.New("backup: the backup repository does not exist")
	}
	snaps, err := r.snapshots(ctx, string(spec.Snapshot))
	if err != nil {
		return nil, err
	}
	if len(snaps) != 1 {
		return nil, fmt.Errorf("backup: snapshot %s was not found in the repository", spec.Snapshot)
	}
	want := "/" + en.file
	if !slices.Equal(snaps[0].Paths, []string{want}) {
		return nil, fmt.Errorf("backup: snapshot %s does not hold a %s dump (%s)", spec.Snapshot, spec.Target.Engine, want)
	}
	logf("Restoring snapshot %s into %s", short(snaps[0].ID), spec.Target.Engine)
	if native {
		size, err := e.restoreClickhouse(ctx, r, snaps[0].ID, id, env)
		if err != nil {
			return nil, err
		}
		logf("Restored %s", humanBytes(size))
		return &state.RestoreResult{SizeBytes: size, DurationMs: e.o.Now().Sub(start).Milliseconds()}, nil
	}

	ctx, cancel := context.WithCancel(ctx)
	defer cancel()
	pr, pw := io.Pipe()
	counted := &countingWriter{w: pw}
	dumpDone := make(chan error, 1)
	go func() {
		err := r.run(ctx, "dump", nil, counted, "dump", snaps[0].ID, want)
		if err != nil {
			_ = pw.CloseWithError(errDumpFailed)
		} else {
			_ = pw.Close()
		}
		dumpDone <- err
	}()
	res, execErr := e.o.Docker.ExecStream(ctx, id, docker.ExecOptions{Argv: cmd.argv, Env: cmd.env, Stdin: cmd.input(pr)})
	_ = pr.CloseWithError(errors.New("restore stopped reading"))
	if execErr != nil || res.ExitCode != 0 {
		cancel()
	}
	dumpErr := <-dumpDone
	switch {
	case dumpErr != nil && !errors.Is(dumpErr, context.Canceled):
		return nil, dumpErr
	case execErr != nil:
		return nil, fmt.Errorf("backup: running %s: %w", cmd.argv[0], execErr)
	case res.ExitCode != 0:
		return nil, fmt.Errorf("backup: %s exited with code %d: %s", cmd.argv[0], res.ExitCode, cmd.masked(res.Stderr))
	case dumpErr != nil:
		return nil, dumpErr
	}
	size := counted.n.Load()
	logf("Restored %s", humanBytes(size))
	return &state.RestoreResult{SizeBytes: size, DurationMs: e.o.Now().Sub(start).Milliseconds()}, nil
}

// Snapshots lists the snapshots carrying all of spec.Tags, newest first.
// A repository that does not exist yet has no snapshots.
func (e *Engine) Snapshots(ctx context.Context, spec *state.BackupSnapshotsSpec, creds Credentials) (*state.BackupSnapshotsResult, error) {
	r, err := e.openRepo(spec.Destination, creds)
	if err != nil {
		return nil, err
	}
	var args []string
	if len(spec.Tags) > 0 {
		args = append(args, "--tag", joinTags(spec.Tags))
	}
	if ok, err := r.exists(ctx); err != nil {
		return nil, err
	} else if !ok {
		return &state.BackupSnapshotsResult{Snapshots: []state.SnapshotInfo{}}, nil
	}
	snaps, err := r.snapshots(ctx, args...)
	if err != nil {
		return nil, err
	}
	slices.SortStableFunc(snaps, func(a, b resticSnapshot) int { return b.Time.Compare(a.Time) })
	res := &state.BackupSnapshotsResult{Snapshots: make([]state.SnapshotInfo, 0, min(len(snaps), state.MaxSnapshotsListed))}
	for _, s := range snaps {
		if len(res.Snapshots) == state.MaxSnapshotsListed {
			res.Truncated = true
			break
		}
		id := state.SnapshotID(s.ID)
		if id.Validate() != nil || s.Time.IsZero() {
			continue
		}
		info := state.SnapshotInfo{ID: id, Time: s.Time.UTC(), Tags: []state.BackupTag{}}
		for _, t := range s.Tags {
			if bt := state.BackupTag(t); bt.Validate() == nil && len(info.Tags) < 32 {
				info.Tags = append(info.Tags, bt)
			}
		}
		if s.Summary != nil && s.Summary.TotalBytesProcessed > 0 {
			info.SizeBytes = s.Summary.TotalBytesProcessed
		}
		res.Snapshots = append(res.Snapshots, info)
	}
	return res, nil
}

// Maintenance runs forget (+ prune), prune or check on a repository.
//
// forget requires at least one tag and applies the retention to all
// snapshots carrying all of the tags as ONE group (--group-by ”), so
// the dashboard scopes it per service (service:<id>) or per control plane
// (kind:control_plane); snapshots of other services are never considered.
func (e *Engine) Maintenance(ctx context.Context, spec *state.BackupMaintenanceSpec, creds Credentials, logf Logf) (*state.MaintenanceResult, error) {
	if logf == nil {
		logf = nopLog
	}
	r, err := e.openRepo(spec.Destination, creds)
	if err != nil {
		return nil, err
	}
	start := e.o.Now()
	res := &state.MaintenanceResult{}
	if ok, err := r.exists(ctx); err != nil {
		return nil, err
	} else if !ok {
		logf("The backup repository does not exist yet; nothing to do")
		res.DurationMs = e.o.Now().Sub(start).Milliseconds()
		return res, nil
	}
	switch spec.Operation {
	case state.MaintenanceForget:
		if len(spec.Tags) == 0 {
			return nil, errors.New("backup: forget needs at least one tag (it would otherwise apply to every snapshot in the repository)")
		}
		if spec.Retention == nil {
			return nil, errors.New("backup: forget needs a retention policy")
		}
		args := []string{"forget", "--json", "--group-by", "", "--tag", joinTags(spec.Tags)}
		for flag, n := range map[string]int{
			"--keep-last": spec.Retention.KeepLast, "--keep-hourly": spec.Retention.KeepHourly, "--keep-daily": spec.Retention.KeepDaily,
			"--keep-weekly": spec.Retention.KeepWeekly, "--keep-monthly": spec.Retention.KeepMonthly,
		} {
			if n > 0 {
				args = append(args, flag, strconv.Itoa(n))
			}
		}
		if h := spec.Retention.KeepWithinHours; h > 0 {
			args = append(args, "--keep-within", strconv.Itoa(h)+"h")
		}
		var out bytes.Buffer
		if err := r.run(ctx, "forget", nil, &out, args...); err != nil {
			return nil, err
		}
		var groups []struct {
			Remove []json.RawMessage `json:"remove"`
		}
		if err := json.Unmarshal(bytes.TrimSpace(out.Bytes()), &groups); err == nil {
			for _, g := range groups {
				res.RemovedSnapshots += len(g.Remove)
			}
		}
		logf("Forgot %d snapshots", res.RemovedSnapshots)
		if res.RemovedSnapshots > 0 {
			if err := r.run(ctx, "prune", nil, nil, "prune"); err != nil {
				return nil, err
			}
			logf("Pruned unreferenced data")
		}
	case state.MaintenancePrune:
		if err := r.run(ctx, "prune", nil, nil, "prune"); err != nil {
			return nil, err
		}
	case state.MaintenanceCheck:
		args := []string{"check"}
		if spec.ReadDataSubsetPercent > 0 {
			args = append(args, "--read-data-subset="+strconv.Itoa(spec.ReadDataSubsetPercent)+"%")
		}
		if err := r.run(ctx, "check", nil, nil, args...); err != nil {
			return nil, err
		}
		logf("Repository check passed")
	default:
		return nil, fmt.Errorf("backup: maintenance %q: %w", spec.Operation, ErrUnsupported)
	}
	res.DurationMs = e.o.Now().Sub(start).Milliseconds()
	return res, nil
}

func joinTags(tags []state.BackupTag) string {
	s := make([]string, len(tags))
	for i, t := range tags {
		s[i] = string(t)
	}
	return strings.Join(s, ",")
}

func short(id string) string {
	if len(id) > 8 {
		return id[:8]
	}
	return id
}

// firstLines keeps a short, single-line excerpt of a tool's stderr.
func firstLines(s string) string {
	s = strings.TrimSpace(s)
	lines := strings.Split(s, "\n")
	if len(lines) > 3 {
		lines = lines[len(lines)-3:]
	}
	s = strings.Join(lines, " / ")
	if len(s) > 512 {
		s = s[len(s)-512:]
	}
	if s == "" {
		return "no error output"
	}
	return s
}

func humanBytes(n int64) string {
	const unit = 1024
	if n < unit {
		return fmt.Sprintf("%d B", n)
	}
	div, exp := int64(unit), 0
	for m := n / unit; m >= unit; m /= unit {
		div *= unit
		exp++
	}
	return fmt.Sprintf("%.1f %ciB", float64(n)/float64(div), "KMGTPE"[exp])
}
