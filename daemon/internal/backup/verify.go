package backup

import (
	"archive/tar"
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"regexp"
	"slices"
	"strconv"
	"strings"
	"time"

	"github.com/vantezzen/launchroom/daemon/internal/docker"
	"github.com/vantezzen/launchroom/daemon/internal/secret"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// Restore verification (docs/14 §10.4): "an unverified backup is a hope, not
// a backup". The newest snapshot of a service is restored into a throwaway
// container on the server that runs it — the engine's own image, a fresh
// volume, no network, capped CPU/memory/pids, lower CPU weight than
// workloads — and checked; everything is removed afterwards. Nothing of the
// service itself is touched.
//
//   - Postgres dumps: pg_restore into a fresh database, count tables and
//     rows, pg_amcheck (heap and B-tree indexes).
//   - Postgres base backups: the point-in-time replay of RestorePointInTime
//     to the newest archived transaction, then the same checks.
//   - MySQL/MariaDB dumps: load into a fresh server, count, CHECK TABLE on every table.
//   - Redis/Valkey RDB files: redis-check-rdb / valkey-check-rdb.
//   - MongoDB archives: mongorestore into a fresh mongod, count, validate
//     every collection (D47).
//   - ClickHouse backups: RESTORE into a fresh server, count, CHECK TABLE on
//     every MergeTree table (D47).
//   - Volume tars: read completely through the restore filter (every entry
//     allowed, every blob's hash checked by restic on the way).

// verifyStartTimeout bounds how long a fresh engine may take to come up.
const verifyStartTimeout = 5 * time.Minute

// countQuery counts the user tables and their exact rows in one database.
const countQuery = `SELECT count(*), coalesce(sum((xpath('/row/c/text()', query_to_xml(format('SELECT count(*) AS c FROM %I.%I', schemaname, tablename), false, true, '')))[1]::text::bigint), 0) ` +
	`FROM pg_catalog.pg_tables WHERE schemaname NOT IN ('pg_catalog', 'information_schema')`

// verifier collects the checks of one verification.
type verifier struct {
	res state.VerifyResult
}

func (v *verifier) check(name state.VerifyCheckName, err error, detail string) bool {
	c := state.VerifyCheck{Name: name, Passed: err == nil, Detail: detail}
	if err != nil {
		c.Detail = clip(err.Error(), 512)
	}
	v.res.Checks = append(v.res.Checks, c)
	return err == nil
}

func clip(s string, n int) string {
	s = strings.ToValidUTF8(s, "")
	if len(s) <= n {
		return s
	}
	return strings.ToValidUTF8(s[:n], "")
}

// Verify restores spec.Snapshot into a throwaway container and checks it.
// An error means the verification could not run (repository, image,
// Docker); a snapshot that does not restore or check cleanly is a result
// with Passed false.
func (e *Engine) Verify(ctx context.Context, spec *state.VerifyRestoreSpec, creds Credentials, logf Logf) (*state.VerifyResult, error) {
	if logf == nil {
		logf = nopLog
	}
	start := e.o.Now()
	r, err := e.openRepo(spec.Destination, creds)
	if err != nil {
		return nil, err
	}
	if ok, err := r.exists(ctx); err != nil {
		return nil, err
	} else if !ok {
		return nil, errors.New("backup: the backup repository does not exist")
	}
	v := &verifier{res: state.VerifyResult{Checks: []state.VerifyCheck{}}}
	switch spec.Kind {
	case state.BackupVolume:
		err = e.verifyVolume(ctx, r, spec, v, logf)
	case state.BackupBaseBackup:
		err = e.verifyBaseBackup(ctx, r, spec, v, logf)
	case state.BackupDatabase:
		err = e.verifyDump(ctx, r, spec, v, logf)
	default:
		err = fmt.Errorf("backup: verifying %s snapshots: %w", spec.Kind, ErrUnsupported)
	}
	if err != nil {
		return nil, err
	}
	v.res.Passed = len(v.res.Checks) > 0 && !slices.ContainsFunc(v.res.Checks, func(c state.VerifyCheck) bool { return !c.Passed })
	v.res.DurationMs = e.o.Now().Sub(start).Milliseconds()
	if v.res.Passed {
		logf("Verified: the snapshot restores and every check passed")
	} else {
		logf("Verification failed")
	}
	return &v.res, nil
}

// snapshotFile resolves a snapshot and checks it holds exactly /file.
func (r *repo) snapshotFile(ctx context.Context, id state.SnapshotID, files ...string) (resticSnapshot, string, error) {
	snaps, err := r.snapshots(ctx, string(id))
	if err != nil {
		return resticSnapshot{}, "", err
	}
	if len(snaps) != 1 {
		return resticSnapshot{}, "", fmt.Errorf("backup: snapshot %s was not found in the repository", id)
	}
	for _, f := range files {
		if slices.Equal(snaps[0].Paths, []string{"/" + f}) {
			return snaps[0], f, nil
		}
	}
	return resticSnapshot{}, "", fmt.Errorf("backup: snapshot %s does not hold %s", id, strings.Join(files, " or "))
}

func (e *Engine) verifyVolume(ctx context.Context, r *repo, spec *state.VerifyRestoreSpec, v *verifier, logf Logf) error {
	snap, _, err := r.snapshotFile(ctx, spec.Snapshot, VolumeFile)
	if err != nil {
		return err
	}
	logf("Reading volume snapshot %s completely", short(snap.ID))
	counter := &tarCounter{}
	err = r.streamFile(ctx, snap.ID, VolumeFile, func(src io.Reader) error {
		pr, pw := io.Pipe()
		done := make(chan error, 1)
		go func() { done <- counter.count(pr) }()
		err := sanitizeVolumeTar(pw, src)
		_ = pw.CloseWithError(err)
		if cerr := <-done; err == nil {
			err = cerr
		}
		return err
	})
	v.res.Files, v.res.Bytes = counter.files, counter.bytes
	v.check(state.VerifyCheckArchive, err, fmt.Sprintf("%d files, %s", counter.files, humanBytes(counter.bytes)))
	return nil
}

// tarCounter counts the regular files and their bytes in a tar stream.
type tarCounter struct{ files, bytes int64 }

func (c *tarCounter) count(src io.Reader) error {
	tr := tar.NewReader(src)
	for {
		hdr, err := tr.Next()
		if errors.Is(err, io.EOF) {
			return nil
		}
		if err != nil {
			return err
		}
		if hdr.Typeflag == tar.TypeReg {
			n, err := io.CopyN(io.Discard, tr, hdr.Size)
			c.files++
			c.bytes += n
			if err != nil {
				return err
			}
		}
	}
}

// verifyVolumeFor creates the throwaway volume of a verification.
func (e *Engine) verifyVolumeFor(ctx context.Context) (string, func(), error) {
	name := "lr-verify-" + randomSuffix()
	labels := docker.ManagedLabels(e.o.ServerID)
	labels[docker.LabelHelper] = helperVerify
	if err := e.o.Runtime.CreateVolume(ctx, docker.VolumeCreate{Name: name, Labels: labels}); err != nil {
		return "", nil, err
	}
	return name, func() {
		rctx, cancel := context.WithTimeout(context.WithoutCancel(ctx), time.Minute)
		defer cancel()
		_ = e.o.Runtime.RemoveVolume(rctx, name)
	}, nil
}

// verifyEnv is the throwaway engine's configuration: fresh credentials that
// exist only for this verification.
func verifyEnv(engine state.DatabaseEngine) (map[string]secret.Secret, []string) {
	pw := randomSuffix() + randomSuffix()
	switch engine {
	case state.EngineMySQL, state.EngineMariaDB:
		prefix := "MYSQL"
		if engine == state.EngineMariaDB {
			prefix = "MARIADB"
		}
		env := map[string]secret.Secret{
			prefix + "_USER": "verify", prefix + "_PASSWORD": secret.Secret(pw), prefix + "_DATABASE": "verify",
		}
		return env, []string{prefix + "_ROOT_PASSWORD=" + pw, prefix + "_USER=verify", prefix + "_PASSWORD=" + pw, prefix + "_DATABASE=verify"}
	default:
		env := map[string]secret.Secret{"POSTGRES_USER": "verify", "POSTGRES_PASSWORD": secret.Secret(pw), "POSTGRES_DB": "verify"}
		return env, []string{"POSTGRES_USER=verify", "POSTGRES_PASSWORD=" + pw, "POSTGRES_DB=verify"}
	}
}

func (e *Engine) verifyDump(ctx context.Context, r *repo, spec *state.VerifyRestoreSpec, v *verifier, logf Logf) error {
	en, err := engineFor(spec.Engine)
	if err != nil {
		return err
	}
	snap, _, err := r.snapshotFile(ctx, spec.Snapshot, en.file)
	if err != nil {
		return err
	}
	if err := e.checkRuntime(ctx, spec.Image, spec.Resources); err != nil {
		return err
	}
	e.sweepThrowaways(ctx, helperVerify)
	vol, removeVol, err := e.verifyVolumeFor(ctx)
	if err != nil {
		return err
	}
	defer removeVol()
	switch spec.Engine {
	case state.EngineRedis, state.EngineValkey:
		return e.verifyRDB(ctx, r, spec, snap, en.file, vol, v, logf)
	case state.EngineMongoDB:
		return e.verifyMongo(ctx, r, spec, snap, vol, v, logf)
	case state.EngineClickHouse:
		return e.verifyClickhouse(ctx, r, spec, snap, vol, v, logf)
	}
	env, containerEnv := verifyEnv(spec.Engine)
	id, err := e.createThrowaway(ctx, throwaway{purpose: helperVerify, image: spec.Image, volume: vol, mountPath: spec.DataPath, env: containerEnv, resources: spec.Resources})
	if err != nil {
		return err
	}
	defer e.removeQuietly(ctx, id)
	logf("Starting a throwaway %s to restore snapshot %s into", spec.Engine, short(snap.ID))
	if err := e.o.Runtime.StartContainer(ctx, id); err != nil {
		return fmt.Errorf("backup: starting the throwaway database: %w", err)
	}
	if !v.check(state.VerifyCheckStart, e.waitReady(ctx, id, spec.Engine, env), "a fresh "+string(spec.Engine)+" started") {
		return nil
	}

	cmd, err := en.restore(env)
	if err != nil {
		return err
	}
	var restored int64
	err = r.streamFile(ctx, snap.ID, en.file, func(src io.Reader) error {
		counted := &countingReader{r: src}
		res, err := e.o.Runtime.ExecStream(ctx, id, docker.ExecOptions{Argv: cmd.argv, Env: cmd.env, Stdin: cmd.input(counted)})
		restored = counted.n
		switch {
		case err != nil:
			return err
		case res.ExitCode != 0:
			return fmt.Errorf("%s exited with code %d: %s", cmd.argv[0], res.ExitCode, cmd.masked(res.Stderr))
		}
		return nil
	})
	v.res.Bytes = restored
	if !v.check(state.VerifyCheckRestore, err, humanBytes(restored)+" restored") {
		return nil
	}
	logf("Restored %s; checking it", humanBytes(restored))
	if spec.Engine == state.EnginePostgres {
		pwEnv := []string{"PGPASSWORD=" + env["POSTGRES_PASSWORD"].Reveal()}
		e.postgresChecks(ctx, id, "verify", "verify", pwEnv, v)
		return nil
	}
	e.mysqlChecks(ctx, id, spec.Engine, env, v)
	return nil
}

// countingReader counts bytes read through it.
type countingReader struct {
	r io.Reader
	n int64
}

func (c *countingReader) Read(p []byte) (int, error) {
	n, err := c.r.Read(p)
	c.n += int64(n)
	return n, err
}

// waitReady waits until the fresh engine accepts TCP connections on its
// loopback (the images' init phase only listens on a socket).
func (e *Engine) waitReady(ctx context.Context, id string, engine state.DatabaseEngine, env map[string]secret.Secret) error {
	deadline := e.o.Now().Add(verifyStartTimeout)
	var probe []string
	var probeEnv []string
	switch engine {
	case state.EnginePostgres:
		probe = []string{"psql", "-XAtq", "--host=127.0.0.1", "--username=verify", "--dbname=verify", "-c", "SELECT 1"}
		probeEnv = []string{"PGPASSWORD=" + env["POSTGRES_PASSWORD"].Reveal(), "PGCONNECT_TIMEOUT=3"}
	default:
		cli := "mysql"
		if engine == state.EngineMariaDB {
			cli = "mariadb"
		}
		probe = []string{cli, "--protocol=tcp", "--host=127.0.0.1", "--user=verify", "--database=verify", "-e", "SELECT 1"}
		probeEnv = []string{"MYSQL_PWD=" + mysqlPassword(env)}
	}
	for {
		if err := e.running(ctx, id, "the throwaway database"); err != nil {
			return err
		}
		if _, res, err := e.execIn(ctx, id, nil, probeEnv, probe...); err == nil && res.ExitCode == 0 {
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

func mysqlPassword(env map[string]secret.Secret) string {
	if s := env["MYSQL_PASSWORD"]; !s.IsZero() {
		return s.Reveal()
	}
	return env["MARIADB_PASSWORD"].Reveal()
}

// postgresChecks counts tables and rows and runs pg_amcheck in one database.
func (e *Engine) postgresChecks(ctx context.Context, id, user, db string, env []string, v *verifier) {
	argv := []string{"psql", "-XAtq", "-v", "ON_ERROR_STOP=1", "--host=/var/run/postgresql", "--username=" + user, "--dbname=" + db, "-c", countQuery}
	out, res, err := e.execIn(ctx, id, nil, env, argv...)
	if err == nil && res.ExitCode != 0 {
		err = fmt.Errorf("psql exited with code %d: %s", res.ExitCode, firstLines(res.Stderr))
	}
	if err == nil {
		var tables, rows int64
		tables, rows, err = parseCounts(out)
		v.res.Tables, v.res.Rows = tables, rows
	}
	v.check(state.VerifyCheckQuery, err, fmt.Sprintf("%d tables, %d rows", v.res.Tables, v.res.Rows))

	// Heap and B-tree checks; the amcheck extension is installed into the
	// throwaway copy only.
	argv = []string{"pg_amcheck", "--host=/var/run/postgresql", "--username=" + user, "--database=" + db, "--install-missing"}
	_, res, err = e.execIn(ctx, id, nil, env, argv...)
	if err == nil && res.ExitCode != 0 {
		err = fmt.Errorf("pg_amcheck exited with code %d: %s", res.ExitCode, firstLines(res.Stderr))
	}
	v.check(state.VerifyCheckAmcheck, err, "no corruption found")
}

func parseCounts(out string) (int64, int64, error) {
	f := strings.Split(strings.TrimSpace(out), "|")
	if len(f) != 2 {
		return 0, 0, fmt.Errorf("unexpected count output %q", clip(out, 64))
	}
	tables, err1 := strconv.ParseInt(strings.TrimSpace(f[0]), 10, 64)
	rows, err2 := strconv.ParseInt(strings.TrimSpace(f[1]), 10, 64)
	if err := errors.Join(err1, err2); err != nil {
		return 0, 0, fmt.Errorf("unexpected count output %q", clip(out, 64))
	}
	return tables, rows, nil
}

func (e *Engine) mysqlChecks(ctx context.Context, id string, engine state.DatabaseEngine, env map[string]secret.Secret, v *verifier) {
	cli := "mysql"
	if engine == state.EngineMariaDB {
		cli = "mariadb"
	}
	pwEnv := []string{"MYSQL_PWD=" + mysqlPassword(env)}
	out, res, err := e.execIn(ctx, id, nil, pwEnv, cli, "--protocol=tcp", "--host=127.0.0.1", "--user=verify", "--batch", "--skip-column-names", "-e",
		"SELECT COUNT(*), COALESCE(SUM(TABLE_ROWS), 0) FROM information_schema.tables WHERE table_schema = 'verify' AND table_type = 'BASE TABLE'")
	if err == nil && res.ExitCode != 0 {
		err = fmt.Errorf("%s exited with code %d: %s", cli, res.ExitCode, firstLines(res.Stderr))
	}
	if err == nil {
		var tables, rows int64
		tables, rows, err = parseCounts(strings.ReplaceAll(out, "\t", "|"))
		v.res.Tables, v.res.Rows = tables, rows
	}
	v.check(state.VerifyCheckQuery, err, fmt.Sprintf("%d tables, about %d rows", v.res.Tables, v.res.Rows))

	// CHECK TABLE on every table (the slim images ship no mysqlcheck).
	out, res, err = e.execIn(ctx, id, nil, pwEnv, cli, "--protocol=tcp", "--host=127.0.0.1", "--user=verify", "--batch", "--skip-column-names", "-e",
		"SELECT TABLE_NAME FROM information_schema.tables WHERE table_schema = 'verify' AND table_type = 'BASE TABLE'")
	if err == nil && res.ExitCode != 0 {
		err = fmt.Errorf("%s exited with code %d: %s", cli, res.ExitCode, firstLines(res.Stderr))
	}
	var tables []string
	for line := range strings.SplitSeq(out, "\n") {
		if name := strings.TrimSpace(line); reMySQLTable.MatchString(name) {
			tables = append(tables, "`verify`.`"+name+"`")
		}
	}
	if err == nil && len(tables) > 0 {
		out, res, err = e.execIn(ctx, id, nil, pwEnv, cli, "--protocol=tcp", "--host=127.0.0.1", "--user=verify", "--batch", "--skip-column-names", "-e",
			"CHECK TABLE "+strings.Join(tables, ", "))
		if err == nil && res.ExitCode != 0 {
			err = fmt.Errorf("CHECK TABLE exited with code %d: %s", res.ExitCode, firstLines(res.Stderr))
		}
		if err == nil {
			for line := range strings.SplitSeq(out, "\n") {
				// Table, Op, Msg_type, Msg_text; only "status OK" (and notes) is healthy.
				f := strings.Split(strings.TrimSpace(line), "\t")
				if len(f) == 4 && (f[2] == "error" || (f[2] == "status" && f[3] != "OK")) {
					err = fmt.Errorf("CHECK TABLE %s: %s", f[0], clip(f[3], 200))
					break
				}
			}
		}
	}
	v.check(state.VerifyCheckMysqlcheck, err, "CHECK TABLE: every table OK")
}

var (
	reKeysRead   = regexp.MustCompile(`(\d+) keys read`)
	reMySQLTable = regexp.MustCompile(`^[A-Za-z0-9_$]{1,64}$`)
)

func lastLine(s string) string {
	lines := strings.Split(strings.TrimSpace(s), "\n")
	return lines[len(lines)-1]
}

// verifyRDB runs the engine's RDB checker on the snapshot's file: a
// container from the image whose command is the checker, the file copied
// in before it starts.
func (e *Engine) verifyRDB(ctx context.Context, r *repo, spec *state.VerifyRestoreSpec, snap resticSnapshot, file, vol string, v *verifier, logf Logf) error {
	size, err := r.fileSize(ctx, snap.ID, file)
	if err != nil {
		return err
	}
	checker := "redis-check-rdb"
	if spec.Engine == state.EngineValkey {
		checker = "valkey-check-rdb"
	}
	target := string(spec.DataPath) + "/launchroom-verify.rdb"
	id, err := e.createThrowaway(ctx, throwaway{purpose: helperVerify, image: spec.Image, volume: vol, mountPath: spec.DataPath, cmd: []string{checker, target}, resources: spec.Resources})
	if err != nil {
		return err
	}
	defer e.removeQuietly(ctx, id)
	logf("Checking RDB snapshot %s with %s", short(snap.ID), checker)
	err = r.streamFile(ctx, snap.ID, file, func(src io.Reader) error {
		pr, pw := io.Pipe()
		go func() {
			tw := tar.NewWriter(pw)
			err := tw.WriteHeader(&tar.Header{Typeflag: tar.TypeReg, Name: "launchroom-verify.rdb", Mode: 0o644, Size: size, ModTime: time.Now()})
			if err == nil {
				_, err = io.CopyN(tw, src, size)
			}
			if err == nil {
				err = tw.Close()
			}
			_ = pw.CloseWithError(err)
		}()
		return e.o.Runtime.StreamToContainer(ctx, id, string(spec.DataPath), pr)
	})
	v.res.Bytes = size
	if !v.check(state.VerifyCheckRestore, err, humanBytes(size)+" copied") {
		return nil
	}
	if err := e.o.Runtime.StartContainer(ctx, id); err != nil {
		return fmt.Errorf("backup: starting %s: %w", checker, err)
	}
	var exit int
	for {
		c, err := e.o.Runtime.InspectContainer(ctx, id)
		if err != nil {
			return err
		}
		if !c.Running && c.State != "created" {
			exit = c.ExitCode
			break
		}
		select {
		case <-ctx.Done():
			return ctx.Err()
		case <-time.After(e.o.PollEvery):
		}
	}
	logs := e.logTail(ctx, id, 40)
	var checkErr error
	if exit != 0 {
		checkErr = fmt.Errorf("%s exited with code %d: %s", checker, exit, firstLines(logs))
	}
	detail := "the RDB file is consistent: " + clip(lastLine(logs), 200)
	if m := reKeysRead.FindStringSubmatch(logs); m != nil {
		keys, _ := strconv.ParseInt(m[1], 10, 64)
		v.res.Rows = keys
		detail = fmt.Sprintf("%d keys, consistent", keys)
	}
	v.check(state.VerifyCheckRDB, checkErr, detail)
	return nil
}

// fileSize returns the size of /file in a snapshot (restic ls --json).
func (r *repo) fileSize(ctx context.Context, id, file string) (int64, error) {
	var out bytes.Buffer
	if err := r.run(ctx, "ls", nil, &out, "ls", "--json", id, "/"+file); err != nil {
		return 0, err
	}
	var size int64 = -1
	jsonLines(out.Bytes(), func(line []byte) {
		var n struct {
			Path string `json:"path"`
			Type string `json:"type"`
			Size int64  `json:"size"`
		}
		if json.Unmarshal(line, &n) == nil && n.Type == "file" && n.Path == "/"+file {
			size = n.Size
		}
	})
	if size < 0 {
		return 0, fmt.Errorf("backup: snapshot %s has no /%s", short(id), file)
	}
	return size, nil
}

// verifyBaseBackup replays the base backup with the archived WAL into a
// throwaway volume (to the newest archived transaction) and checks the
// source's own database.
func (e *Engine) verifyBaseBackup(ctx context.Context, r *repo, spec *state.VerifyRestoreSpec, v *verifier, logf Logf) error {
	if err := e.checkRuntime(ctx, spec.Image, spec.Resources); err != nil {
		return err
	}
	base, wal, err := e.replaySnapshots(ctx, r, spec.Snapshot, spec.WalTags, nil)
	if err != nil {
		return err
	}
	e.sweepThrowaways(ctx, helperVerify)
	vol, removeVol, err := e.verifyVolumeFor(ctx)
	if err != nil {
		return err
	}
	defer removeVol()
	id, err := e.createThrowaway(ctx, throwaway{
		purpose: helperVerify, image: spec.Image, volume: vol, mountPath: spec.DataPath,
		env: []string{"PGDATA=" + string(spec.DataDirectory)}, cmd: replayCommand(nil, ""), resources: spec.Resources, stopSignal: state.SignalInt,
	})
	if err != nil {
		return err
	}
	defer e.removeQuietly(ctx, id)
	logf("Replaying base backup %s with %d WAL snapshot(s) in a throwaway database", short(base.ID), len(wal))
	size, err := e.layBaseBackup(ctx, r, base, wal, id, spec.DataPath, spec.DataDirectory, "")
	v.res.Bytes = size
	if !v.check(state.VerifyCheckRestore, err, humanBytes(size)+" laid out") {
		return nil
	}
	restoredTo, err := e.replay(ctx, id, string(spec.DatabaseUser), nil, logf)
	v.res.RestoredTo = restoredTo
	detail := "replayed and promoted"
	if restoredTo != nil {
		detail = "replayed to " + restoredTo.UTC().Format(time.RFC3339)
	}
	if !v.check(state.VerifyCheckReplay, err, detail) {
		return nil
	}
	e.postgresChecks(ctx, id, string(spec.DatabaseUser), string(spec.DatabaseName), nil, v)
	return nil
}
