package backup

import (
	"archive/tar"
	"context"
	"errors"
	"fmt"
	"io"
	"path"
	"regexp"
	"strings"
	"time"

	"github.com/vantezzen/launchroom/daemon/internal/docker"
	"github.com/vantezzen/launchroom/daemon/internal/secret"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// ClickHouse backups (D47, docs/14 §11). ClickHouse has no logical dump
// that streams a whole database (with its schema, every table engine and
// consistent parts) to stdout, so launchroomd uses the server's native
// BACKUP: it writes a zip into the data volume's backups/ directory (the
// image's default backups.allowed_path, so no config change), launchroomd
// streams that file out through the Engine API archive endpoint into
// restic, and removes it. A restore lays the file back and runs RESTORE.
//
// The database is stored under the fixed name "launchroom" inside the
// backup (BACKUP DATABASE x AS launchroom), so it restores into a database
// of any name (a restored copy, a verification).
//
// Credentials: clickhouse-client reads CLICKHOUSE_USER/CLICKHOUSE_PASSWORD
// from its environment; the exec env sets both, never argv.

// ClickHouseFile is the restic --stdin-filename of ClickHouse backups.
const ClickHouseFile = "clickhouse.zip"

const (
	// clickhouseData is the data directory (the volume's mount path in the
	// official image; the dashboard driver mounts the volume there).
	clickhouseData = "/var/lib/clickhouse"
	// clickhouseBackupDB is the database's name inside every backup.
	clickhouseBackupDB = "launchroom"
)

var reBackupName = regexp.MustCompile(`^launchroom-[0-9a-f]{12}\.zip$`)

type clickhouseConn struct{ user, db string }

func clickhouseFrom(env map[string]secret.Secret) (clickhouseConn, []string, error) {
	user, err := ident(env, "default", "CLICKHOUSE_USER")
	if err != nil {
		return clickhouseConn{}, nil, err
	}
	db, err := ident(env, "default", "CLICKHOUSE_DATABASE", "CLICKHOUSE_DB")
	if err != nil {
		return clickhouseConn{}, nil, err
	}
	execEnv := []string{"CLICKHOUSE_USER=" + user}
	if pw := env["CLICKHOUSE_PASSWORD"]; !pw.IsZero() {
		execEnv = append(execEnv, "CLICKHOUSE_PASSWORD="+pw.Reveal())
	}
	return clickhouseConn{user: user, db: db}, execEnv, nil
}

// clickhouseQuery runs one statement with clickhouse-client inside the
// container (statements are built here from fixed text and grammar-checked
// identifiers).
func clickhouseQuery(ctx context.Context, ex docker.Execer, id string, env []string, query string) (string, error) {
	var out strings.Builder
	cmd := command{argv: []string{"clickhouse-client", "--host=127.0.0.1", "--format=TabSeparated", "--query", query}, env: env}
	res, err := ex.ExecStream(ctx, id, docker.ExecOptions{Argv: cmd.argv, Env: env, Stdout: &limitedWriter{w: &out, n: 64 << 10}})
	switch {
	case err != nil:
		return "", fmt.Errorf("backup: running clickhouse-client: %w", err)
	case res.ExitCode != 0:
		return "", fmt.Errorf("backup: clickhouse-client exited with code %d: %s", res.ExitCode, cmd.masked(res.Stderr))
	}
	return strings.TrimSpace(out.String()), nil
}

func backupFile(name string) string { return path.Join(clickhouseData, "backups", name) }

// removeBackupFile deletes the staged zip (fixed argv, a generated name).
func removeBackupFile(ctx context.Context, ex docker.Execer, id, name string) {
	if !reBackupName.MatchString(name) {
		return
	}
	rctx, cancel := context.WithTimeout(context.WithoutCancel(ctx), time.Minute)
	defer cancel()
	_, _ = ex.ExecStream(rctx, id, docker.ExecOptions{Argv: []string{"rm", "-f", backupFile(name)}})
}

// clickhouseDump writes a native backup of the workload's database to w.
func (e *Engine) clickhouseDump(ctx context.Context, id string, env map[string]secret.Secret, w io.Writer) error {
	if e.o.Runtime == nil {
		return fmt.Errorf("backup: clickhouse backups need the archive API: %w", ErrUnsupported)
	}
	conn, execEnv, err := clickhouseFrom(env)
	if err != nil {
		return err
	}
	name := "launchroom-" + randomSuffix() + ".zip"
	defer removeBackupFile(ctx, e.o.Docker, id, name)
	q := "BACKUP DATABASE `" + conn.db + "` AS `" + clickhouseBackupDB + "` TO File('" + name + "')"
	if _, err := clickhouseQuery(ctx, e.o.Docker, id, execEnv, q); err != nil {
		return err
	}
	rc, err := e.o.Runtime.StreamFromContainer(ctx, id, backupFile(name))
	if err != nil {
		return fmt.Errorf("backup: reading the clickhouse backup: %w", err)
	}
	defer func() { _ = rc.Close() }()
	return untarOne(rc, name, w)
}

// untarOne copies the single regular file name of a tar stream to w.
func untarOne(src io.Reader, name string, w io.Writer) error {
	tr := tar.NewReader(src)
	found := false
	for {
		hdr, err := tr.Next()
		if errors.Is(err, io.EOF) {
			break
		}
		if err != nil {
			return err
		}
		if hdr.Typeflag != tar.TypeReg || path.Clean(hdr.Name) != name || found {
			return fmt.Errorf("backup: unexpected entry %q in the clickhouse backup", clip(hdr.Name, 64))
		}
		found = true
		if _, err := io.CopyN(w, tr, hdr.Size); err != nil {
			return err
		}
	}
	if !found {
		return errors.New("backup: the clickhouse backup file is missing")
	}
	return nil
}

// layClickhouseBackup copies a backup zip (size bytes from src) into the
// container's backups/ directory under a generated name.
func layClickhouseBackup(ctx context.Context, rt docker.VolumeOps, id string, src io.Reader, size int64) (string, error) {
	name := "launchroom-" + randomSuffix() + ".zip"
	pr, pw := io.Pipe()
	go func() {
		tw := tar.NewWriter(pw)
		now := time.Now()
		// 101 is the clickhouse user of the official images; the server
		// only reads the file, so the mode alone would do.
		err := tw.WriteHeader(&tar.Header{Typeflag: tar.TypeDir, Name: "backups/", Mode: 0o750, Uid: 101, Gid: 101, ModTime: now})
		if err == nil {
			err = tw.WriteHeader(&tar.Header{Typeflag: tar.TypeReg, Name: "backups/" + name, Mode: 0o640, Uid: 101, Gid: 101, Size: size, ModTime: now})
		}
		if err == nil {
			_, err = io.CopyN(tw, src, size)
		}
		if err == nil {
			err = tw.Close()
		}
		_ = pw.CloseWithError(err)
	}()
	if err := rt.StreamToContainer(ctx, id, clickhouseData, pr); err != nil {
		_ = pr.CloseWithError(err)
		return "", fmt.Errorf("backup: copying the clickhouse backup: %w", err)
	}
	return name, nil
}

// restoreClickhouse replaces the workload's database with a snapshot.
func (e *Engine) restoreClickhouse(ctx context.Context, r *repo, snapID, id string, env map[string]secret.Secret) (int64, error) {
	if e.o.Runtime == nil {
		return 0, fmt.Errorf("backup: clickhouse restores need the archive API: %w", ErrUnsupported)
	}
	conn, execEnv, err := clickhouseFrom(env)
	if err != nil {
		return 0, err
	}
	size, err := r.fileSize(ctx, snapID, ClickHouseFile)
	if err != nil {
		return 0, err
	}
	var name string
	err = r.streamFile(ctx, snapID, ClickHouseFile, func(src io.Reader) error {
		var lerr error
		name, lerr = layClickhouseBackup(ctx, e.o.Runtime, id, src, size)
		return lerr
	})
	if name != "" {
		defer removeBackupFile(ctx, e.o.Docker, id, name)
	}
	if err != nil {
		return 0, err
	}
	if _, err := clickhouseQuery(ctx, e.o.Docker, id, execEnv, "DROP DATABASE IF EXISTS `"+conn.db+"` SYNC"); err != nil {
		return 0, err
	}
	q := "RESTORE DATABASE `" + clickhouseBackupDB + "` AS `" + conn.db + "` FROM File('" + name + "')"
	if _, err := clickhouseQuery(ctx, e.o.Docker, id, execEnv, q); err != nil {
		return 0, err
	}
	return size, nil
}

// verifyClickhouse restores the snapshot into a throwaway server (no
// network, the default user on its loopback only) and runs CHECK TABLE.
func (e *Engine) verifyClickhouse(ctx context.Context, r *repo, spec *state.VerifyRestoreSpec, snap resticSnapshot, vol string, v *verifier, logf Logf) error {
	size, err := r.fileSize(ctx, snap.ID, ClickHouseFile)
	if err != nil {
		return err
	}
	id, err := e.createThrowaway(ctx, throwaway{purpose: helperVerify, image: spec.Image, volume: vol, mountPath: spec.DataPath,
		env: []string{"CLICKHOUSE_SKIP_USER_SETUP=1"}, resources: spec.Resources})
	if err != nil {
		return err
	}
	defer e.removeQuietly(ctx, id)
	logf("Starting a throwaway clickhouse to restore snapshot %s into", short(snap.ID))
	var name string
	err = r.streamFile(ctx, snap.ID, ClickHouseFile, func(src io.Reader) error {
		var lerr error
		name, lerr = layClickhouseBackup(ctx, e.o.Runtime, id, src, size)
		return lerr
	})
	v.res.Bytes = size
	if err != nil {
		v.check(state.VerifyCheckRestore, err, "")
		return nil
	}
	if err := e.o.Runtime.StartContainer(ctx, id); err != nil {
		return fmt.Errorf("backup: starting the throwaway database: %w", err)
	}
	env := []string{"CLICKHOUSE_USER=default"}
	if !v.check(state.VerifyCheckStart, e.waitFor(ctx, id, nil, env, "clickhouse-client", "--host=127.0.0.1", "--query", "SELECT 1"), "a fresh clickhouse started") {
		return nil
	}
	_, err = clickhouseQuery(ctx, e.o.Runtime, id, env, "RESTORE DATABASE `"+clickhouseBackupDB+"` AS `verify` FROM File('"+name+"')")
	if !v.check(state.VerifyCheckRestore, err, humanBytes(size)+" restored") {
		return nil
	}
	logf("Restored %s; checking it", humanBytes(size))
	out, err := clickhouseQuery(ctx, e.o.Runtime, id, env, "SELECT count(), ifNull(sum(total_rows), 0) FROM system.tables WHERE database = 'verify'")
	if err == nil {
		var tables, rows int64
		tables, rows, err = parseCounts(strings.ReplaceAll(out, "\t", "|"))
		v.res.Tables, v.res.Rows = tables, rows
	}
	v.check(state.VerifyCheckQuery, err, fmt.Sprintf("%d tables, %d rows", v.res.Tables, v.res.Rows))

	out, err = clickhouseQuery(ctx, e.o.Runtime, id, env, "SELECT name FROM system.tables WHERE database = 'verify' AND engine LIKE '%MergeTree' ORDER BY name")
	checked := 0
	for line := range strings.SplitSeq(out, "\n") {
		t := strings.TrimSpace(line)
		if err != nil || t == "" {
			continue
		}
		if !reMySQLTable.MatchString(t) {
			continue // names launchroom can't quote safely are only counted
		}
		var res string
		res, err = clickhouseQuery(ctx, e.o.Runtime, id, env, "CHECK TABLE `verify`.`"+t+"` SETTINGS check_query_single_value_result = 1")
		if err == nil && strings.TrimSpace(res) != "1" {
			err = fmt.Errorf("CHECK TABLE %s reported a problem", t)
		}
		checked++
	}
	v.check(state.VerifyCheckTables, err, fmt.Sprintf("CHECK TABLE: %d tables OK", checked))
	return nil
}

// waitFor runs probe until it exits 0 (the throwaway engine accepts
// connections) or verifyStartTimeout passes.
func (e *Engine) waitFor(ctx context.Context, id string, stdin []byte, env []string, probe ...string) error {
	deadline := e.o.Now().Add(verifyStartTimeout)
	for {
		if err := e.running(ctx, id, "the throwaway database"); err != nil {
			return err
		}
		var in io.Reader
		if stdin != nil {
			in = strings.NewReader(string(stdin))
		}
		if _, res, err := e.execIn(ctx, id, in, env, probe...); err == nil && res.ExitCode == 0 {
			return nil
		}
		if e.o.Now().After(deadline) {
			return fmt.Errorf("the database did not start within %s: %s", verifyStartTimeout, e.lastLogLines(ctx, id, 10))
		}
		select {
		case <-ctx.Done():
			return ctx.Err()
		case <-time.After(e.o.PollEvery):
		}
	}
}
