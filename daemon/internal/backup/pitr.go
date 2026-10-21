package backup

import (
	"archive/tar"
	"context"
	"errors"
	"fmt"
	"io"
	"maps"
	"path"
	"slices"
	"strings"
	"time"

	"github.com/vantezzen/launchroom/daemon/internal/docker"
	"github.com/vantezzen/launchroom/daemon/internal/secret"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// Point-in-time recovery (docs/14 §10.3). A base backup and the WAL shipped
// after it are laid into the (emptied) data volume with a standby.signal,
// and Postgres replays them in a throwaway container without network:
// standby mode reads WAL from pg_wal only, so no restore_command (a shell
// string Postgres would run) is needed. With a target time, Postgres stops
// there and promotes itself (recovery_target_action=promote); without one,
// or when the archive ends before the target, launchroomd promotes it once
// replay stops advancing. The container is then shut down cleanly and the
// workload starts on the promoted data like after any restart.

// WalDirName is the WAL archive directory the dashboard configures in every
// Postgres volume (<mount>/launchroom-wal). For Postgres ≤ 17 it lies inside
// PGDATA, so base backups contain it; it is never restored from one.
const WalDirName = "launchroom-wal"

// walAfterTarget is how much WAL past a target time is laid out: a record at
// time t is in a segment Postgres completes within archive_timeout (60 s)
// and launchroomd ships within an interval, so a quarter of an hour is
// plenty; replay stops at the target anyway.
const walAfterTarget = 30 * time.Minute

// replayStableFor is how long replay must stand still before launchroomd
// considers the laid-out WAL used up and promotes.
const replayStableFor = 8 * time.Second

// PostgresTarget is the restore target's database configuration (a sealed
// TaskEnv: POSTGRES_USER, POSTGRES_PASSWORD, POSTGRES_DB).
type PostgresTarget struct {
	User     string
	Password secret.Secret
	Database string
}

// PostgresTargetFromEnv checks the sealed runtime env of a point-in-time restore.
func PostgresTargetFromEnv(env map[string]secret.Secret) (PostgresTarget, error) {
	names := slices.Sorted(maps.Keys(env))
	if !slices.Equal(names, []string{"POSTGRES_DB", "POSTGRES_PASSWORD", "POSTGRES_USER"}) {
		return PostgresTarget{}, fmt.Errorf("backup: the restore runtime env must contain exactly POSTGRES_DB, POSTGRES_PASSWORD, POSTGRES_USER, got %s", strings.Join(names, ", "))
	}
	user, db, pw, err := pgConn(env)
	if err != nil {
		return PostgresTarget{}, err
	}
	return PostgresTarget{User: user, Password: secret.Secret(pw), Database: db}, nil
}

// RestorePointInTime replaces the data volume of a Postgres service with a
// base backup replayed to spec.PointInTime (or as far as the archive goes).
// The dashboard holds the database (and its dependents) out of the
// NodeState while this runs; in place, a safety snapshot was taken first.
func (e *Engine) RestorePointInTime(ctx context.Context, spec *state.RestoreSpec, creds Credentials, target PostgresTarget, logf Logf) (*state.RestoreResult, error) {
	if logf == nil {
		logf = nopLog
	}
	if spec.Kind != state.BackupBaseBackup || spec.Runtime == nil {
		return nil, errors.New("backup: not a point-in-time restore")
	}
	rt := spec.Runtime
	if e.o.Volumes == nil {
		return nil, fmt.Errorf("backup: volume restores: %w", ErrUnsupported)
	}
	if err := e.checkRuntime(ctx, rt.Image, rt.Resources); err != nil {
		return nil, err
	}
	vol, err := e.findVolume(ctx, spec.Target.Volume)
	if err != nil {
		return nil, err
	}
	r, err := e.openRepo(spec.Destination, creds)
	if err != nil {
		return nil, err
	}
	start := e.o.Now()
	base, wal, err := e.replaySnapshots(ctx, r, spec.Snapshot, spec.WalTags, spec.PointInTime)
	if err != nil {
		return nil, err
	}
	if err := e.waitIdle(ctx, vol.Name, logf); err != nil {
		return nil, err
	}
	e.sweepThrowaways(ctx, helperReplay)
	logf("Restoring base backup %s with %d WAL snapshot(s) into volume %s", short(base.ID), len(wal), spec.Target.Volume)
	if err := e.o.Volumes.RemoveVolume(ctx, vol.Name); err != nil {
		return nil, fmt.Errorf("backup: emptying the volume: %w", err)
	}
	if err := e.o.Volumes.CreateVolume(ctx, docker.VolumeCreate{Name: vol.Name, Labels: maps.Clone(vol.Labels)}); err != nil {
		return nil, fmt.Errorf("backup: recreating the volume (it is now missing; restore again): %w", err)
	}

	cmd := replayCommand(spec.PointInTime, rt.ArchiveDirectory)
	id, err := e.createThrowaway(ctx, throwaway{
		purpose: helperReplay, image: rt.Image, volume: vol.Name, mountPath: rt.MountPath,
		env: []string{"PGDATA=" + string(rt.DataDirectory)}, cmd: cmd, resources: rt.Resources, stopSignal: state.SignalInt,
	})
	if err != nil {
		return nil, err
	}
	defer e.removeQuietly(ctx, id)

	size, err := e.layBaseBackup(ctx, r, base, wal, id, rt.MountPath, rt.DataDirectory, rt.ArchiveDirectory)
	if err != nil {
		return nil, fmt.Errorf("%w (the volume is incomplete; restore again)", err)
	}
	logf("Replaying WAL%s", targetText(spec.PointInTime))
	restoredTo, err := e.replay(ctx, id, target.User, spec.PointInTime, logf)
	if err != nil {
		return nil, fmt.Errorf("%w (the volume is incomplete; restore again)", err)
	}
	if err := e.adoptCredentials(ctx, id, target); err != nil {
		return nil, err
	}
	if err := e.stopThrowaway(ctx, id); err != nil {
		return nil, err
	}
	if restoredTo != nil {
		logf("Restored to %s", restoredTo.UTC().Format(time.RFC3339))
	}
	return &state.RestoreResult{SizeBytes: size, DurationMs: e.o.Now().Sub(start).Milliseconds(), RestoredTo: restoredTo}, nil
}

func targetText(t *time.Time) string {
	if t == nil {
		return " to the newest archived transaction"
	}
	return " to " + t.UTC().Format(time.RFC3339)
}

// replayCommand is the argv of the replay container (the image's
// entrypoint runs it as the postgres user): no TCP listener, read-only
// connections while replaying (to watch progress), and the target.
func replayCommand(target *time.Time, archiveDir state.ContainerPath) []string {
	cmd := []string{"postgres", "-c", "listen_addresses=", "-c", "hot_standby=on"}
	if archiveDir != "" {
		// Once promoted, the new timeline's history file and the last
		// partial segment are archived into the workload's directory and
		// shipped when it runs again.
		cmd = append(cmd, "-c", "archive_mode=on", "-c", "archive_library=basic_archive", "-c", "basic_archive.archive_directory="+string(archiveDir))
	} else {
		cmd = append(cmd, "-c", "archive_mode=off")
	}
	if target != nil {
		cmd = append(cmd,
			"-c", "recovery_target_time="+target.UTC().Format("2006-01-02 15:04:05.999999")+"+00",
			"-c", "recovery_target_action=promote",
		)
	}
	return cmd
}

// replaySnapshots resolves the base backup snapshot and the WAL snapshots
// to lay out after it: every WAL snapshot of the tags taken since the base
// backup started, oldest first, up to walAfterTarget past the target.
func (e *Engine) replaySnapshots(ctx context.Context, r *repo, snapshot state.SnapshotID, walTags []state.BackupTag, target *time.Time) (resticSnapshot, []resticSnapshot, error) {
	if ok, err := r.exists(ctx); err != nil {
		return resticSnapshot{}, nil, err
	} else if !ok {
		return resticSnapshot{}, nil, errors.New("backup: the backup repository does not exist")
	}
	snaps, err := r.snapshots(ctx, string(snapshot))
	if err != nil {
		return resticSnapshot{}, nil, err
	}
	if len(snaps) != 1 {
		return resticSnapshot{}, nil, fmt.Errorf("backup: snapshot %s was not found in the repository", snapshot)
	}
	base := snaps[0]
	if !slices.Equal(base.Paths, []string{"/" + BaseBackupFile}) {
		return resticSnapshot{}, nil, fmt.Errorf("backup: snapshot %s is not a Postgres base backup", snapshot)
	}
	if target != nil && target.Before(base.Time) {
		return resticSnapshot{}, nil, fmt.Errorf("backup: %s is before base backup %s was taken", target.UTC().Format(time.RFC3339), short(base.ID))
	}
	if len(walTags) == 0 {
		return base, nil, nil
	}
	all, err := r.snapshots(ctx, "--tag", joinTags(walTags))
	if err != nil {
		return resticSnapshot{}, nil, err
	}
	var wal []resticSnapshot
	for _, s := range all {
		if !slices.Equal(s.Paths, []string{"/" + WalFile}) || s.Time.Before(base.Time) {
			continue
		}
		if target != nil && s.Time.After(target.Add(walAfterTarget)) {
			continue
		}
		wal = append(wal, s)
	}
	slices.SortStableFunc(wal, func(a, b resticSnapshot) int { return a.Time.Compare(b.Time) })
	return base, wal, nil
}

// layBaseBackup extracts the base backup into dataDir (relative to the
// volume at mountPath), adds the WAL files into pg_wal and a standby.signal,
// and creates the (empty) WAL archive directory. Entries are filtered like
// volume snapshots: no device nodes, no setuid/setgid bits, no extended
// attributes, nothing outside the data directory.
func (e *Engine) layBaseBackup(ctx context.Context, r *repo, base resticSnapshot, wal []resticSnapshot, id string, mountPath, dataDir, archiveDir state.ContainerPath) (int64, error) {
	rel := strings.Trim(strings.TrimPrefix(string(dataDir), string(mountPath)), "/")
	archRel := ""
	if archiveDir != "" {
		archRel = strings.Trim(strings.TrimPrefix(string(archiveDir), string(mountPath)), "/")
	}
	ctx, cancel := context.WithCancel(ctx)
	defer cancel()
	pr, pw := io.Pipe()
	layDone := make(chan error, 1)
	var size int64
	go func() {
		lay := &baseLayout{tw: tar.NewWriter(pw), rel: rel, files: map[string]bool{}, dirs: map[string]bool{}}
		err := func() error {
			if err := r.streamFile(ctx, base.ID, BaseBackupFile, lay.base); err != nil {
				return err
			}
			for _, s := range wal {
				if err := r.streamFile(ctx, s.ID, WalFile, lay.wal); err != nil {
					return err
				}
			}
			return lay.finish(archRel)
		}()
		size = lay.bytes
		if err != nil {
			_ = pw.CloseWithError(err)
		} else {
			_ = pw.Close()
		}
		layDone <- err
	}()
	putErr := e.o.Runtime.StreamToContainer(ctx, id, string(mountPath), pr)
	_ = pr.CloseWithError(errors.New("restore stopped reading"))
	if putErr != nil {
		cancel()
	}
	layErr := <-layDone
	switch {
	case layErr != nil && !errors.Is(layErr, context.Canceled):
		return 0, layErr
	case putErr != nil:
		return 0, fmt.Errorf("backup: extracting into the volume: %w", putErr)
	case layErr != nil:
		return 0, layErr
	}
	return size, nil
}

// streamFile runs `restic dump <id> /<file>` and hands the stream to fn.
func (r *repo) streamFile(ctx context.Context, id, file string, fn func(io.Reader) error) error {
	ctx, cancel := context.WithCancel(ctx)
	defer cancel()
	pr, pw := io.Pipe()
	dumpDone := make(chan error, 1)
	go func() {
		err := r.run(ctx, "dump", nil, pw, "dump", id, "/"+file)
		if err != nil {
			_ = pw.CloseWithError(errDumpFailed)
		} else {
			_ = pw.Close()
		}
		dumpDone <- err
	}()
	fnErr := fn(pr)
	_ = pr.CloseWithError(errors.New("stopped reading"))
	if fnErr != nil {
		cancel()
	}
	dumpErr := <-dumpDone
	switch {
	case fnErr != nil && !errors.Is(fnErr, errDumpFailed):
		return fnErr
	case dumpErr != nil:
		return dumpErr
	}
	return fnErr
}

// baseLayout writes the restored data directory as one tar.
type baseLayout struct {
	tw       *tar.Writer
	rel      string // data directory relative to the volume root ("" or "18/docker")
	uid, gid int    // owner of the base backup's files (the image's postgres user)
	owned    bool
	files    map[string]bool // regular files written (archive names)
	dirs     map[string]bool
	bytes    int64
}

func (l *baseLayout) name(p string) string {
	if l.rel == "" {
		return p
	}
	return l.rel + "/" + p
}

// ensureParents writes directory entries for the data directory's parents
// (Postgres ≥ 18 keeps PGDATA in a versioned subdirectory of the volume),
// owned like the data, 0700 for PGDATA itself.
func (l *baseLayout) ensureParents() error {
	if l.rel == "" {
		return nil
	}
	parts := strings.Split(l.rel, "/")
	for i := range parts {
		dir := strings.Join(parts[:i+1], "/")
		if l.dirs[dir] {
			continue
		}
		mode := int64(0o755)
		if i == len(parts)-1 {
			mode = 0o700
		}
		if err := l.tw.WriteHeader(&tar.Header{Typeflag: tar.TypeDir, Name: dir + "/", Mode: mode, Uid: l.uid, Gid: l.gid, ModTime: time.Now()}); err != nil {
			return err
		}
		l.dirs[dir] = true
	}
	return nil
}

// cleanBaseName returns a base backup entry's clean relative name, or ""
// for the root entry; ok is false for names that leave the directory.
func cleanBaseName(name string) (string, bool) {
	if strings.HasPrefix(name, "/") || strings.ContainsRune(name, 0) {
		return "", false
	}
	for part := range strings.SplitSeq(strings.TrimSuffix(name, "/"), "/") {
		if part == ".." {
			return "", false
		}
	}
	clean := path.Clean(strings.TrimPrefix(name, "./"))
	if clean == "." {
		return "", true
	}
	return clean, true
}

// skipBaseEntry drops what is never restored from a base backup: the WAL
// archive directory (Postgres ≤ 17 keeps it inside PGDATA) and recovery
// signal files (the replay writes its own).
func skipBaseEntry(name string) bool {
	return name == WalDirName || strings.HasPrefix(name, WalDirName+"/") ||
		name == "standby.signal" || name == "recovery.signal" || name == "postmaster.pid"
}

func (l *baseLayout) base(src io.Reader) error {
	tr := tar.NewReader(src)
	for {
		hdr, err := tr.Next()
		if errors.Is(err, io.EOF) {
			break
		}
		if err != nil {
			return fmt.Errorf("backup: reading the base backup: %w", err)
		}
		name, ok := cleanBaseName(hdr.Name)
		if !ok {
			return fmt.Errorf("%w: %q is outside the data directory", errUnsafeArchive, hdr.Name)
		}
		if name == "" || skipBaseEntry(name) {
			continue
		}
		if !l.owned {
			l.uid, l.gid, l.owned = hdr.Uid, hdr.Gid, true
			if err := l.ensureParents(); err != nil {
				return err
			}
		}
		out := &tar.Header{Name: l.name(name), Mode: hdr.Mode &^ (0o4000 | 0o2000), Uid: hdr.Uid, Gid: hdr.Gid, ModTime: hdr.ModTime, Typeflag: hdr.Typeflag}
		switch hdr.Typeflag {
		case tar.TypeReg, '\x00':
			out.Typeflag, out.Size = tar.TypeReg, hdr.Size
			l.files[name] = true
		case tar.TypeDir:
			out.Name += "/"
			l.dirs[l.name(name)] = true
		case tar.TypeSymlink:
			if hdr.Linkname == "" {
				return fmt.Errorf("%w: empty symlink %q", errUnsafeArchive, hdr.Name)
			}
			out.Linkname = hdr.Linkname
		case tar.TypeFifo, tar.TypeXGlobalHeader:
			continue
		default:
			return fmt.Errorf("%w: %q has type %q", errUnsafeArchive, hdr.Name, string(hdr.Typeflag))
		}
		if err := l.tw.WriteHeader(out); err != nil {
			return fmt.Errorf("backup: writing the data directory: %w", err)
		}
		if out.Typeflag == tar.TypeReg {
			n, err := io.CopyN(l.tw, tr, hdr.Size)
			l.bytes += n
			if err != nil {
				return fmt.Errorf("backup: copying %q: %w", name, err)
			}
		}
	}
	if !l.owned {
		return errors.New("backup: the base backup is empty")
	}
	_, _ = io.Copy(io.Discard, src)
	return nil
}

// wal adds the files of one WAL snapshot to pg_wal (a file already laid out
// by the base backup or an earlier snapshot is kept).
func (l *baseLayout) wal(src io.Reader) error {
	tr := tar.NewReader(src)
	for {
		hdr, err := tr.Next()
		if errors.Is(err, io.EOF) {
			break
		}
		if err != nil {
			return fmt.Errorf("backup: reading archived WAL: %w", err)
		}
		file := strings.TrimPrefix(hdr.Name, "wal/")
		if hdr.Typeflag != tar.TypeReg || file == hdr.Name || !reWalFile.MatchString(file) || strings.HasSuffix(file, ".partial") {
			continue
		}
		name := "pg_wal/" + file
		if l.files[name] {
			continue
		}
		if err := l.tw.WriteHeader(&tar.Header{Typeflag: tar.TypeReg, Name: l.name(name), Mode: 0o600, Size: hdr.Size, Uid: l.uid, Gid: l.gid, ModTime: hdr.ModTime}); err != nil {
			return fmt.Errorf("backup: writing WAL: %w", err)
		}
		n, err := io.CopyN(l.tw, tr, hdr.Size)
		l.bytes += n
		if err != nil {
			return fmt.Errorf("backup: copying WAL %s: %w", file, err)
		}
		l.files[name] = true
	}
	_, _ = io.Copy(io.Discard, src)
	return nil
}

// finish adds standby.signal (replay from pg_wal, no restore_command) and
// the empty WAL archive directory, then closes the archive.
func (l *baseLayout) finish(archRel string) error {
	if err := l.tw.WriteHeader(&tar.Header{Typeflag: tar.TypeReg, Name: l.name("standby.signal"), Mode: 0o600, Uid: l.uid, Gid: l.gid, ModTime: time.Now()}); err != nil {
		return err
	}
	if archRel != "" {
		if err := l.tw.WriteHeader(&tar.Header{Typeflag: tar.TypeDir, Name: archRel + "/", Mode: 0o700, Uid: l.uid, Gid: l.gid, ModTime: time.Now()}); err != nil {
			return err
		}
	}
	return l.tw.Close()
}

// psql runs one statement over the local socket and returns its unaligned
// output. The data's own role connects (local connections are trusted in
// the official images; the password env is passed as well).
func (e *Engine) psql(ctx context.Context, id, user string, stdin io.Reader, sql string, env ...string) (string, error) {
	argv := []string{"psql", "-XAtq", "-v", "ON_ERROR_STOP=1", "--host=/var/run/postgresql", "--username=" + user, "--dbname=postgres"}
	if sql != "" {
		argv = append(argv, "-c", sql)
	}
	out, res, err := e.execIn(ctx, id, stdin, append([]string{"PGCONNECT_TIMEOUT=5"}, env...), argv...)
	if err != nil {
		return "", err
	}
	if res.ExitCode != 0 {
		return "", fmt.Errorf("psql exited with code %d: %s", res.ExitCode, firstLines(res.Stderr))
	}
	return out, nil
}

// replay starts the replay container and waits until Postgres is promoted:
// by itself at the target, or by launchroomd once replay stopped advancing
// (the laid-out WAL is used up). It returns the time of the last replayed
// transaction.
func (e *Engine) replay(ctx context.Context, id, user string, target *time.Time, logf Logf) (*time.Time, error) {
	if err := e.o.Runtime.StartContainer(ctx, id); err != nil {
		return nil, fmt.Errorf("backup: starting the database: %w", err)
	}
	const status = `SELECT pg_is_in_recovery(), coalesce(pg_last_wal_replay_lsn()::text, ''), ` +
		`coalesce(to_char(pg_last_xact_replay_timestamp() AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS.US"Z"'), '')`
	var lastLSN string
	var stableSince time.Time
	promoted := false
	for {
		if err := e.running(ctx, id, "the database"); err != nil {
			return nil, err
		}
		out, err := e.psql(ctx, id, user, nil, status)
		if err == nil {
			f := strings.Split(out, "|")
			if len(f) != 3 {
				return nil, fmt.Errorf("backup: unexpected replay status %q", firstLines(out))
			}
			if f[0] == "f" {
				return parseReplayTime(f[2]), nil
			}
			switch {
			case f[1] != lastLSN:
				lastLSN, stableSince = f[1], e.o.Now()
			case !promoted && e.o.Now().Sub(stableSince) >= replayStableFor:
				if target != nil {
					logf("The archive ends before %s; promoting at the newest archived transaction", target.UTC().Format(time.RFC3339))
				}
				if _, err := e.psql(ctx, id, user, nil, "SELECT pg_promote(true, 120)"); err != nil {
					return nil, fmt.Errorf("backup: promoting the restored database: %w", err)
				}
				promoted = true
			}
		}
		select {
		case <-ctx.Done():
			return nil, ctx.Err()
		case <-time.After(e.o.PollEvery):
		}
	}
}

func parseReplayTime(s string) *time.Time {
	if s == "" {
		return nil
	}
	t, err := time.Parse("2006-01-02T15:04:05.999999Z", s)
	if err != nil {
		return nil
	}
	return &t
}

// adoptCredentials gives the restored data the target's password (a copy
// has its own generated credentials) and checks that its database exists.
func (e *Engine) adoptCredentials(ctx context.Context, id string, t PostgresTarget) error {
	if !reDBIdent.MatchString(t.User) || !reDBIdent.MatchString(t.Database) {
		return errors.New("backup: invalid target role or database name")
	}
	pw := t.Password.Reveal()
	if pw == "" || strings.ContainsRune(pw, 0) {
		return errors.New("backup: the target has no usable POSTGRES_PASSWORD")
	}
	// Through stdin, never argv: the password must not show up in ps.
	sql := `ALTER ROLE "` + t.User + `" WITH PASSWORD '` + strings.ReplaceAll(pw, "'", "''") + "';\n"
	if _, err := e.psql(ctx, id, t.User, strings.NewReader(sql), ""); err != nil {
		return fmt.Errorf("backup: the restored data has no role %s (restore into a database with the same POSTGRES_USER): %w", t.User, err)
	}
	out, err := e.psql(ctx, id, t.User, nil, "SELECT count(*) FROM pg_database WHERE datname = '"+t.Database+"'")
	if err != nil {
		return err
	}
	if out != "1" {
		return fmt.Errorf("backup: the restored data has no database %s", t.Database)
	}
	return nil
}
