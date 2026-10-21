package backup

import (
	"bytes"
	"compress/gzip"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net"
	"net/url"
	"os"
	"regexp"
	"strconv"
	"strings"
	"sync/atomic"
	"time"

	"github.com/vantezzen/launchroom/daemon/internal/build"
	"github.com/vantezzen/launchroom/daemon/internal/docker"
	"github.com/vantezzen/launchroom/daemon/internal/secret"
	"github.com/vantezzen/launchroom/daemon/internal/state"
	"github.com/vantezzen/launchroom/daemon/internal/transfer"
)

// Database imports (D55, docs/14 §12.3): a dump file fetched from the
// dashboard or a source database reached by URL is restored into a managed
// database with the target image's own client tools, run in a throwaway
// container on the database's environment network.

// Helper purposes of import throwaways: imports hold the heavy slot and sweep
// their leftovers; inspections (which don't) use their own label.
const (
	helperImport      = "import"
	helperImportCheck = "import-check"
)

// EnvSourceURL is the one name in a URL source's sealed TaskEnv.
const EnvSourceURL = "SOURCE_URL"

// Progress receives a short status line for the task's heartbeat.
type Progress func(line string)

func nopProgress(string) {}

// importTarget is the database an import writes into.
type importTarget struct {
	engine    state.DatabaseEngine
	name      string // for messages: the database name
	user, db  string
	pw        string
	rootPw    string // MySQL root (drop/create database), "" when the env has none
	container string // the database container
	host      string // its address on the environment network
	rt        WorkloadRuntime
}

// inspection is what a target holds.
type inspection struct {
	objects int64 // anything that makes it "not empty"
	tables  int64 // tables / collections
	size    int64
	version Version
}

func (e *Engine) importTarget(ctx context.Context, spec *state.DbImportSpec, withNetwork bool) (*importTarget, error) {
	env, err := e.workloadEnv(spec.Workload)
	if err != nil {
		return nil, err
	}
	t := &importTarget{engine: spec.Engine}
	switch spec.Engine {
	case state.EnginePostgres:
		t.user, t.db, t.pw, err = pgConn(env)
	case state.EngineMySQL, state.EngineMariaDB:
		t.user, t.db, t.pw, err = mysqlConn(env)
		if err == nil {
			t.rootPw, _ = password(env, "MYSQL_ROOT_PASSWORD", "MARIADB_ROOT_PASSWORD")
		}
	case state.EngineMongoDB:
		t.user, t.db, t.pw, err = mongoConn(env)
	default:
		err = fmt.Errorf("backup: imports into %s: %w", spec.Engine, ErrUnsupported)
	}
	if err != nil {
		return nil, err
	}
	t.name = t.db
	if t.container, err = e.container(ctx, spec.Workload); err != nil {
		return nil, err
	}
	if !withNetwork {
		return t, nil
	}
	if e.o.Workload == nil || e.o.Runtime == nil {
		return nil, fmt.Errorf("backup: imports: %w", ErrUnsupported)
	}
	if t.rt, err = e.o.Workload(spec.Workload); err != nil {
		return nil, err
	}
	c, err := e.o.Runtime.InspectContainer(ctx, t.container)
	if err != nil {
		return nil, err
	}
	ip, ok := c.Networks[t.rt.Network]
	if !ok || !ip.IsValid() {
		return nil, fmt.Errorf("backup: the database has no address on its environment network %s", t.rt.Network)
	}
	t.host = ip.String()
	return t, nil
}

func mysqlConn(env map[string]secret.Secret) (user, db, pw string, err error) {
	if user, err = ident(env, "", "MYSQL_USER", "MARIADB_USER"); err != nil {
		return "", "", "", err
	}
	if db, err = ident(env, "", "MYSQL_DATABASE", "MARIADB_DATABASE"); err != nil {
		return "", "", "", err
	}
	pw, err = password(env, "MYSQL_PASSWORD", "MARIADB_PASSWORD")
	return user, db, pw, err
}

func clientBin(engine state.DatabaseEngine) string {
	if engine == state.EngineMariaDB {
		return "mariadb"
	}
	return "mysql"
}

// ---- fixed queries in the database container ----

const pgInspectQuery = `SELECT (SELECT count(*) FROM pg_catalog.pg_class c JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace ` +
	`WHERE c.relkind IN ('r','p','v','m','S','f') AND n.nspname NOT IN ('pg_catalog','information_schema') AND n.nspname NOT LIKE 'pg\_toast%' AND n.nspname NOT LIKE 'pg\_temp%'), ` +
	`(SELECT count(*) FROM pg_catalog.pg_tables WHERE schemaname NOT IN ('pg_catalog','information_schema')), ` +
	`pg_database_size(current_database()), current_setting('server_version')`

const mysqlInspectQuery = `SELECT (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()) + ` +
	`(SELECT COUNT(*) FROM information_schema.routines WHERE routine_schema = DATABASE()), ` +
	`(SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'), ` +
	`(SELECT COALESCE(SUM(data_length + index_length), 0) FROM information_schema.tables WHERE table_schema = DATABASE()), VERSION()`

// mongoImportScript is the only JavaScript imports run (fixed); everything
// variable arrives through the exec env.
const mongoImportScript = `const env = process.env;
const uri = "mongodb://" + encodeURIComponent(env.LR_MONGO_USER) + ":" + encodeURIComponent(env.LR_MONGO_PASSWORD) +
  "@127.0.0.1:27017/?authSource=admin&directConnection=true&appName=launchroom";
const d = new Mongo(uri).getDB(env.LR_MONGO_DB);
if (env.LR_MONGO_OP === "drop") { d.dropDatabase(); }
const names = d.getCollectionNames().filter((n) => !n.startsWith("system."));
const st = d.stats();
print("` + mongoMarker + `" + JSON.stringify({collections: names.length, size: Number(st.dataSize || 0) + Number(st.indexSize || 0), version: d.version()}));`

// mongoSourceScript checks a URL source (fixed; the URL comes from the env).
const mongoSourceScript = `const m = new Mongo(process.env.LR_SOURCE_URL);
const d = m.getDB(process.env.LR_SOURCE_DB);
print("` + mongoMarker + `" + JSON.stringify({collections: d.getCollectionNames().length, version: d.version()}));`

const mongoMarker = "__lr_result__"

func (e *Engine) inspectTarget(ctx context.Context, t *importTarget) (inspection, error) {
	var argv, env []string
	switch t.engine {
	case state.EnginePostgres:
		argv = []string{"psql", "-X", "-A", "-t", "-F", "|", "--host=127.0.0.1", "--username=" + t.user, "--dbname=" + t.db, "-c", pgInspectQuery}
		env = []string{"PGPASSWORD=" + t.pw, "PGAPPNAME=launchroom"}
	case state.EngineMySQL, state.EngineMariaDB:
		argv = []string{clientBin(t.engine), "--protocol=tcp", "--host=127.0.0.1", "--user=" + t.user, "--database=" + t.db, "-N", "-B", "-e", mysqlInspectQuery}
		env = []string{"MYSQL_PWD=" + t.pw}
	case state.EngineMongoDB:
		return e.mongoTarget(ctx, t, "inspect")
	}
	out, err := e.execOut(ctx, t.container, env, argv)
	if err != nil {
		return inspection{}, err
	}
	sep := "|"
	if t.engine != state.EnginePostgres {
		sep = "\t"
	}
	f := strings.Split(lastLine(out), sep)
	if len(f) < 4 {
		return inspection{}, fmt.Errorf("backup: unexpected answer from the database: %q", clip(out, 128))
	}
	var in inspection
	in.objects, _ = strconv.ParseInt(strings.TrimSpace(f[0]), 10, 64)
	in.tables, _ = strconv.ParseInt(strings.TrimSpace(f[1]), 10, 64)
	in.size, _ = strconv.ParseInt(strings.TrimSpace(f[2]), 10, 64)
	in.version = ParseVersion(f[3])
	return in, nil
}

func (e *Engine) mongoTarget(ctx context.Context, t *importTarget, op string) (inspection, error) {
	out, err := e.execOut(ctx, t.container, []string{"LR_MONGO_USER=" + t.user, "LR_MONGO_PASSWORD=" + t.pw, "LR_MONGO_DB=" + t.db, "LR_MONGO_OP=" + op},
		[]string{"mongosh", "--quiet", "--nodb", "--norc", "--eval", mongoImportScript})
	if err != nil {
		return inspection{}, err
	}
	var r struct {
		Collections int64   `json:"collections"`
		Size        float64 `json:"size"`
		Version     string  `json:"version"`
	}
	if err := markedJSON(out, &r); err != nil {
		return inspection{}, err
	}
	return inspection{objects: r.Collections, tables: r.Collections, size: int64(r.Size), version: ParseVersion(r.Version)}, nil
}

func markedJSON(out string, v any) error {
	i := strings.LastIndex(out, mongoMarker)
	if i < 0 {
		return fmt.Errorf("backup: unexpected answer from mongosh: %q", clip(out, 128))
	}
	return json.Unmarshal([]byte(strings.TrimSpace(lastLine(out[i+len(mongoMarker):]))), v)
}

// execOut runs a fixed command in a container and returns its stdout; a
// non-zero exit is an error with the (masked) stderr tail.
func (e *Engine) execOut(ctx context.Context, id string, env, argv []string, extraMask ...string) (string, error) {
	var out bytes.Buffer
	res, err := e.o.Docker.ExecStream(ctx, id, docker.ExecOptions{Argv: argv, Env: env, Stdout: &limitedWriter{w: &out, n: 64 << 10}})
	if err != nil {
		return "", fmt.Errorf("backup: running %s: %w", argv[0], err)
	}
	if res.ExitCode != 0 {
		return "", fmt.Errorf("%s failed (exit %d): %s", argv[0], res.ExitCode, maskTail(res.Stderr, env, extraMask...))
	}
	return strings.TrimSpace(out.String()), nil
}

// maskTail is a short stderr excerpt with every env value and extra secret masked.
func maskTail(stderr string, env []string, extra ...string) string {
	vals := append([]string(nil), extra...)
	for _, kv := range env {
		if _, v, ok := strings.Cut(kv, "="); ok && len(v) >= 4 {
			vals = append(vals, v)
		}
	}
	return build.NewMasker(vals...).Mask(firstLines(stderr))
}

// replace drops and recreates the target database (docs/14 §12.3).
func (e *Engine) replace(ctx context.Context, t *importTarget) error {
	switch t.engine {
	case state.EnginePostgres:
		_, err := e.execOut(ctx, t.container, []string{"PGPASSWORD=" + t.pw, "PGAPPNAME=launchroom"}, []string{
			"psql", "-X", "-q", "-v", "ON_ERROR_STOP=1", "--host=127.0.0.1", "--username=" + t.user, "--dbname=template1",
			"-c", `DROP DATABASE IF EXISTS "` + t.db + `" WITH (FORCE)`, "-c", `CREATE DATABASE "` + t.db + `" OWNER "` + t.user + `"`,
		})
		return err
	case state.EngineMySQL, state.EngineMariaDB:
		user, pw := t.user, t.pw
		if t.rootPw != "" {
			user, pw = "root", t.rootPw
		}
		_, err := e.execOut(ctx, t.container, []string{"MYSQL_PWD=" + pw}, []string{
			clientBin(t.engine), "--protocol=tcp", "--host=127.0.0.1", "--user=" + user,
			"-e", "DROP DATABASE IF EXISTS `" + t.db + "`; CREATE DATABASE `" + t.db + "`",
		})
		return err
	case state.EngineMongoDB:
		_, err := e.mongoTarget(ctx, t, "drop")
		return err
	}
	return ErrUnsupported
}

// ---- URL sources ----

// sourceConn is a parsed connection URL. It holds a secret: never log it.
type sourceConn struct {
	raw                  string
	host, port, user, db string
	pw                   string
	params               url.Values
	scheme               string
}

// display is the URL without user and password ("postgres://db.example.com:5432/app").
func (s *sourceConn) display() string {
	return s.scheme + "://" + net.JoinHostPort(s.host, s.port) + "/" + s.db
}

func parseSourceURL(engine state.DatabaseEngine, raw string) (*sourceConn, error) {
	bad := errors.New("the connection URL isn't valid")
	u, err := url.Parse(strings.TrimSpace(raw))
	if err != nil || u.Host == "" || u.Opaque != "" {
		return nil, bad
	}
	schemes := map[state.DatabaseEngine][]string{
		state.EnginePostgres: {"postgres", "postgresql"},
		state.EngineMySQL:    {"mysql", "mariadb"},
		state.EngineMariaDB:  {"mysql", "mariadb"},
		state.EngineMongoDB:  {"mongodb", "mongodb+srv"},
	}[engine]
	scheme := strings.ToLower(u.Scheme)
	ok := false
	for _, s := range schemes {
		ok = ok || s == scheme
	}
	if !ok {
		return nil, fmt.Errorf("a %s source needs a %s:// URL", engine, schemes[0])
	}
	c := &sourceConn{raw: raw, scheme: scheme, host: u.Hostname(), port: u.Port(), params: u.Query(), db: strings.TrimPrefix(u.Path, "/")}
	if u.User != nil {
		c.user = u.User.Username()
		c.pw, _ = u.User.Password()
	}
	if c.port == "" {
		c.port = map[state.DatabaseEngine]string{state.EnginePostgres: "5432", state.EngineMySQL: "3306", state.EngineMariaDB: "3306", state.EngineMongoDB: "27017"}[engine]
	}
	if c.host == "" || strings.ContainsAny(c.host, " /\\") || c.db == "" || strings.Contains(c.db, "/") {
		return nil, errors.New("the connection URL needs a host and a database name (…/<database>)")
	}
	if p, err := strconv.Atoi(c.port); err != nil || p < 1 || p > 65535 {
		return nil, bad
	}
	if strings.ContainsAny(c.pw, "\r\n") || strings.ContainsAny(c.user, "\r\n") {
		return nil, bad
	}
	return c, nil
}

// probe checks the source can be reached from the throwaway and returns its version.
func (e *Engine) probeSource(ctx context.Context, id string, engine state.DatabaseEngine, src *sourceConn) (Version, int64, error) {
	var argv, env []string
	switch engine {
	case state.EnginePostgres:
		argv = []string{"psql", "-X", "-A", "-t", "-F", "|", "-c",
			"SELECT current_setting('server_version'), (SELECT count(*) FROM pg_catalog.pg_tables WHERE schemaname NOT IN ('pg_catalog','information_schema'))"}
		env = src.pgEnv()
	case state.EngineMySQL, state.EngineMariaDB:
		argv = append(src.mysqlArgs(clientBin(engine)), "--connect-timeout=15", "-N", "-B", "-e",
			"SELECT VERSION(), (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE())", "--database="+src.db)
		env = []string{"MYSQL_PWD=" + src.pw}
	case state.EngineMongoDB:
		argv = []string{"mongosh", "--quiet", "--nodb", "--norc", "--eval", mongoSourceScript}
		env = []string{"LR_SOURCE_URL=" + src.raw, "LR_SOURCE_DB=" + src.db}
	}
	ctx, cancel := context.WithTimeout(ctx, 45*time.Second)
	defer cancel()
	var out bytes.Buffer
	res, err := e.o.Runtime.ExecStream(ctx, id, docker.ExecOptions{Argv: argv, Env: env, Stdout: &limitedWriter{w: &out, n: 64 << 10}})
	if err == nil && res.ExitCode != 0 {
		err = errors.New(maskTail(res.Stderr, env, src.raw, src.pw))
	}
	if err != nil {
		return Version{}, 0, fmt.Errorf("couldn't reach the source database at %s from this server: %s. Private and mesh addresses are blocked for imports like for apps; use a dump file instead",
			net.JoinHostPort(src.host, src.port), build.NewMasker(src.raw, src.pw).Mask(err.Error()))
	}
	if engine == state.EngineMongoDB {
		var r struct {
			Collections int64  `json:"collections"`
			Version     string `json:"version"`
		}
		if err := markedJSON(out.String(), &r); err != nil {
			return Version{}, 0, err
		}
		return ParseVersion(r.Version), r.Collections, nil
	}
	sep := "|"
	if engine != state.EnginePostgres {
		sep = "\t"
	}
	f := strings.Split(lastLine(out.String()), sep)
	tables := int64(0)
	if len(f) > 1 {
		tables, _ = strconv.ParseInt(strings.TrimSpace(f[1]), 10, 64)
	}
	return ParseVersion(f[0]), tables, nil
}

func (s *sourceConn) pgEnv() []string {
	mode := s.params.Get("sslmode")
	if mode == "" {
		mode = "prefer"
	}
	env := []string{"PGHOST=" + s.host, "PGPORT=" + s.port, "PGDATABASE=" + s.db, "PGSSLMODE=" + mode, "PGCONNECT_TIMEOUT=15", "PGAPPNAME=launchroom-import"}
	if s.user != "" {
		env = append(env, "PGUSER="+s.user)
	}
	if s.pw != "" {
		env = append(env, "PGPASSWORD="+s.pw)
	}
	return env
}

func (s *sourceConn) mysqlArgs(bin string) []string {
	argv := []string{bin, "--protocol=tcp", "--host=" + s.host, "--port=" + s.port}
	if s.user != "" {
		argv = append(argv, "--user="+s.user)
	}
	if m := s.params.Get("ssl-mode"); m != "" && reSSLMode.MatchString(m) {
		argv = append(argv, "--ssl-mode="+strings.ToUpper(m))
	}
	return argv
}

var reSSLMode = regexp.MustCompile(`^(?i)(DISABLED|PREFERRED|REQUIRED|VERIFY_CA|VERIFY_IDENTITY)$`)

// dumpCommand is the source's dump, run in the throwaway.
func (s *sourceConn) dumpCommand(engine state.DatabaseEngine) command {
	switch engine {
	case state.EnginePostgres:
		return command{argv: []string{"pg_dump", "--format=custom", "--no-owner", "--no-privileges"}, env: s.pgEnv()}
	case state.EngineMySQL:
		return command{argv: append(s.mysqlArgs("mysqldump"), "--single-transaction", "--routines", "--triggers", "--events", "--no-tablespaces",
			"--hex-blob", "--set-gtid-purged=OFF", "--column-statistics=0", "--", s.db), env: []string{"MYSQL_PWD=" + s.pw}}
	case state.EngineMariaDB:
		return command{argv: append(s.mysqlArgs("mariadb-dump"), "--single-transaction", "--routines", "--triggers", "--events", "--no-tablespaces",
			"--hex-blob", "--", s.db), env: []string{"MYSQL_PWD=" + s.pw}}
	case state.EngineMongoDB:
		u, _ := url.Parse(s.raw)
		if u.User != nil {
			u.User = url.User(u.User.Username())
		}
		c := command{argv: []string{"mongodump", "--uri=" + u.String(), "--archive", "--quiet"}}
		if s.pw != "" {
			c.stdin = []byte(s.pw + "\n")
		}
		return c
	}
	return command{}
}

// ---- restore ----

// restoreCommand is the restore into the target, run in the throwaway.
func (t *importTarget) restoreCommand(s Sniffed) command {
	switch t.engine {
	case state.EnginePostgres:
		conn := []string{"--host=" + t.host, "--port=5432", "--username=" + t.user, "--dbname=" + t.db}
		env := []string{"PGPASSWORD=" + t.pw, "PGAPPNAME=launchroom-import"}
		if s.Format == state.FormatPostgresCustom {
			return command{argv: append([]string{"pg_restore", "--no-owner", "--no-privileges", "--single-transaction", "--exit-on-error"}, conn...), env: env}
		}
		return command{argv: append([]string{"psql", "-X", "-q", "-v", "ON_ERROR_STOP=1", "--single-transaction", "--file=-"}, conn...), env: env}
	case state.EngineMySQL, state.EngineMariaDB:
		return command{argv: []string{clientBin(t.engine), "--protocol=tcp", "--host=" + t.host, "--port=3306", "--user=" + t.user, "--database=" + t.db,
			"--binary-mode", "--default-character-set=utf8mb4"}, env: []string{"MYSQL_PWD=" + t.pw}}
	case state.EngineMongoDB:
		argv := []string{"mongorestore", "--host=" + t.host, "--port=27017", "--username=" + t.user, "--authenticationDatabase=admin", "--quiet", "--archive"}
		if s.InnerGzip {
			argv = append(argv, "--gzip")
		}
		argv = append(argv, "--nsExclude=admin.*", "--nsExclude=config.*", "--nsExclude=local.*",
			"--nsFrom=$db$.$coll$", "--nsTo="+t.db+".$coll$", "--stopOnError")
		return command{argv: argv, stdin: []byte(t.pw + "\n")}
	}
	return command{}
}

// progressReader counts bytes read and reports progress now and then.
type progressReader struct {
	r    io.Reader
	n    atomic.Int64
	tick func(int64)
	last time.Time
}

func (c *progressReader) Read(p []byte) (int, error) {
	n, err := c.r.Read(p)
	c.n.Add(int64(n))
	if c.tick != nil && time.Since(c.last) > 2*time.Second {
		c.last = time.Now()
		c.tick(c.n.Load())
	}
	return n, err
}

// filtered wraps src in the engine's line filter (MySQL, plain Postgres SQL).
func filtered(ctx context.Context, src io.Reader, run func(io.Writer, io.Reader) error) (io.Reader, <-chan error) {
	pr, pw := io.Pipe()
	done := make(chan error, 1)
	go func() {
		err := run(pw, src)
		_ = pw.CloseWithError(err)
		done <- err
	}()
	go func() {
		<-ctx.Done()
		_ = pr.CloseWithError(ctx.Err())
	}()
	return pr, done
}

// ImportDatabase runs a db_import task (both modes). sourceURL is the opened
// SOURCE_URL of a url source (zero otherwise).
func (e *Engine) ImportDatabase(ctx context.Context, task state.TaskID, spec *state.DbImportSpec, sourceURL secret.Secret, logf Logf, progress Progress) (*state.DataImportResult, error) {
	if logf == nil {
		logf = nopLog
	}
	if progress == nil {
		progress = nopProgress
	}
	start := e.o.Now()
	var src *sourceConn
	if spec.Source != nil && spec.Source.Kind == state.SourceURL {
		var err error
		if src, err = parseSourceURL(spec.Engine, sourceURL.Reveal()); err != nil {
			return nil, err
		}
	}
	needThrowaway := spec.Mode == state.ImportRun || src != nil
	t, err := e.importTarget(ctx, spec, needThrowaway)
	if err != nil {
		return nil, err
	}
	before, err := e.inspectTarget(ctx, t)
	if err != nil {
		return nil, err
	}
	res := &state.DataImportResult{Mode: spec.Mode, Empty: before.objects == 0, Tables: before.tables, SizeBytes: before.size, ServerVersion: before.version.String()}

	if spec.Mode == state.ImportInspect {
		if src != nil {
			id, remove, err := e.importThrowaway(ctx, t, spec, helperImportCheck)
			if err != nil {
				return nil, err
			}
			defer remove()
			v, _, err := e.probeSource(ctx, id, spec.Engine, src)
			if err != nil {
				return nil, err
			}
			res.SourceVersion = strings.TrimSpace(familyOf(spec.Engine) + " " + v.String())
			if _, err := checkCompatible(Sniffed{Format: formatOf(spec.Engine), Family: familyOf(spec.Engine), Server: v}, spec.Engine, before.version, t.name); err != nil {
				return nil, err
			}
		}
		res.DurationMs = e.o.Now().Sub(start).Milliseconds()
		return res, nil
	}

	// Import.
	var (
		sniffed Sniffed
		path    string
		size    int64
	)
	if src == nil {
		ref := *spec.Source.File
		if e.o.Transfers == nil {
			return nil, fmt.Errorf("backup: imports: %w", ErrUnsupported)
		}
		logf("Fetching the file (%s)", transfer.HumanBytes(ref.SizeBytes))
		path, err = transfer.Fetch(ctx, e.o.Transfers, ref, task, transfer.Options{Dir: e.o.TransferDir, Free: e.o.Free}, func(done, total int64) {
			progress(fmt.Sprintf("Fetching %s of %s", transfer.HumanBytes(done), transfer.HumanBytes(total)))
		})
		if err != nil {
			return nil, err
		}
		defer transfer.Remove(e.o.TransferDir, task)
		size = ref.SizeBytes
		if sniffed, err = SniffFile(path); err != nil {
			return nil, err
		}
	}
	if !before.empty() && !spec.Replace {
		return nil, fmt.Errorf("%s isn't empty (%s). Choose Replace everything to import into it", t.name, describeContents(t.engine, before))
	}

	e.sweepImports(ctx)
	id, remove, err := e.importThrowaway(ctx, t, spec, helperImport)
	if err != nil {
		return nil, err
	}
	defer remove()

	var warnings []string
	if src != nil {
		logf("Connecting to %s", src.display())
		v, _, err := e.probeSource(ctx, id, spec.Engine, src)
		if err != nil {
			return nil, err
		}
		sniffed = Sniffed{Format: formatOf(spec.Engine), Family: familyOf(spec.Engine), Server: v, InnerGzip: false}
		res.SourceVersion = sniffed.SourceVersion()
	} else {
		if sniffed.Generic {
			sniffed.Format, sniffed.Family = formatOf(spec.Engine), familyOf(spec.Engine)
			if spec.Engine == state.EngineMongoDB {
				return nil, fmt.Errorf("this is a SQL file, but %s is MongoDB; use mongodump --archive", t.name)
			}
			warnings = append(warnings, "The file doesn't say which tool made it; it was imported as SQL")
		}
		res.Format, res.Compressed, res.SourceVersion = sniffed.Format, sniffed.Compressed, sniffed.SourceVersion()
	}
	w, err := checkCompatible(sniffed, spec.Engine, before.version, t.name)
	if err != nil {
		return nil, err
	}
	warnings = append(warnings, w...)
	if src != nil {
		res.Format = sniffed.Format
	}

	if spec.Replace && !before.empty() {
		logf("Dropping and recreating %s", t.name)
		progress("Replacing the database")
		if err := e.replace(ctx, t); err != nil {
			return nil, fmt.Errorf("replacing %s: %w", t.name, err)
		}
	}

	progress("Importing")
	logf("Importing into %s", t.name)
	var read int64
	var fw []string
	if src != nil {
		read, fw, err = e.importFromURL(ctx, id, t, src, progress)
	} else {
		read, fw, err = e.importFromFile(ctx, id, t, path, size, sniffed, progress)
	}
	if err != nil {
		return nil, err
	}
	warnings = append(warnings, fw...)
	after, err := e.inspectTarget(ctx, t)
	if err != nil {
		return nil, err
	}
	res.Empty, res.Tables, res.SizeBytes, res.ReadBytes = after.objects == 0, after.tables, after.size, read
	res.ServerVersion = after.version.String()
	res.Warnings = limitWarnings(warnings)
	res.DurationMs = e.o.Now().Sub(start).Milliseconds()
	logf("Imported %s (%s)", describeContents(t.engine, after), humanBytes(after.size))
	return res, nil
}

func (in inspection) empty() bool { return in.objects == 0 }

func describeContents(engine state.DatabaseEngine, in inspection) string {
	unit := "tables"
	if engine == state.EngineMongoDB {
		unit = "collections"
	}
	if in.tables == 1 {
		unit = strings.TrimSuffix(unit, "s")
	}
	return fmt.Sprintf("%d %s", in.tables, unit)
}

func limitWarnings(w []string) []string {
	var out []string
	for _, s := range w {
		s = strings.Join(strings.Fields(s), " ")
		if s == "" || len(out) == state.MaxImportWarnings {
			continue
		}
		out = append(out, clip(s, 256))
	}
	return out
}

func familyOf(engine state.DatabaseEngine) string {
	switch engine {
	case state.EnginePostgres:
		return familyPostgres
	case state.EngineMySQL:
		return familyMySQL
	case state.EngineMariaDB:
		return familyMariaDB
	case state.EngineMongoDB:
		return familyMongoDB
	}
	return ""
}

func formatOf(engine state.DatabaseEngine) state.DataFormat {
	switch engine {
	case state.EnginePostgres:
		return state.FormatPostgresSQL
	case state.EngineMongoDB:
		return state.FormatMongoArchive
	}
	return state.FormatMySQLSQL
}

// importThrowaway creates and starts the throwaway the client tools run in:
// the target's own image on its environment network (docs/14 §12.3).
func (e *Engine) importThrowaway(ctx context.Context, t *importTarget, spec *state.DbImportSpec, purpose string) (string, func(), error) {
	if err := e.checkRuntime(ctx, t.rt.Image, spec.Resources); err != nil {
		return "", nil, err
	}
	if e.o.Isolate != nil {
		if err := e.o.Isolate(ctx); err != nil {
			return "", nil, fmt.Errorf("backup: the firewall could not cover the import: %w", err)
		}
	}
	secs := 6 * 3600
	if dl, ok := ctx.Deadline(); ok {
		secs = max(60, min(int(time.Until(dl)/time.Second)+60, 86400))
	}
	id, err := e.createThrowaway(ctx, throwaway{
		purpose: purpose, image: t.rt.Image, resources: spec.Resources, network: t.rt.Network, noCaps: true,
		entrypoint: []string{"sleep"}, cmd: []string{strconv.Itoa(secs)},
	})
	if err != nil {
		return "", nil, err
	}
	remove := func() { e.removeQuietly(ctx, id) }
	if err := e.o.Runtime.StartContainer(ctx, id); err != nil {
		remove()
		return "", nil, fmt.Errorf("backup: starting the import container: %w", err)
	}
	return id, remove, nil
}

// sweepImports removes import throwaways left by an interrupted import (the
// heavy slot makes them unused) and inspection ones older than 10 minutes.
func (e *Engine) sweepImports(ctx context.Context) {
	if e.o.Runtime == nil {
		return
	}
	e.sweepThrowaways(ctx, helperImport)
	cs, err := e.o.Runtime.ListContainers(ctx, map[string]string{docker.LabelHelper: helperImportCheck})
	if err != nil {
		return
	}
	for _, c := range cs {
		if !c.Created.IsZero() && e.o.Now().Sub(c.Created) > 10*time.Minute {
			_ = e.o.Runtime.RemoveContainer(ctx, c.ID)
		}
	}
}

// runRestore feeds in into the restore command in the throwaway.
func (e *Engine) runRestore(ctx context.Context, id string, cmd command, in io.Reader, extraMask ...string) error {
	res, err := e.o.Runtime.ExecStream(ctx, id, docker.ExecOptions{Argv: cmd.argv, Env: cmd.env, Stdin: cmd.input(in)})
	if err != nil {
		return fmt.Errorf("backup: running %s: %w", cmd.argv[0], err)
	}
	if res.ExitCode != 0 {
		vals := extraMask
		if cmd.stdin != nil {
			vals = append(vals, strings.TrimSpace(string(cmd.stdin)))
		}
		return fmt.Errorf("%s failed (exit %d): %s", cmd.argv[0], res.ExitCode, maskTail(res.Stderr, cmd.env, vals...))
	}
	return nil
}

func (e *Engine) importFromFile(ctx context.Context, id string, t *importTarget, path string, size int64, s Sniffed, progress Progress) (int64, []string, error) {
	f, err := os.Open(path) //nolint:gosec // generated path inside the data dir
	if err != nil {
		return 0, nil, err
	}
	defer func() { _ = f.Close() }()
	fileCount := &progressReader{r: f, tick: func(n int64) {
		progress(fmt.Sprintf("Importing %s of %s", transfer.HumanBytes(n), transfer.HumanBytes(size)))
	}}
	var stream io.Reader = fileCount
	if s.Compressed {
		zr, err := gzip.NewReader(fileCount)
		if err != nil {
			return 0, nil, err
		}
		defer func() { _ = zr.Close() }()
		stream = zr
	}
	counted := &progressReader{r: stream}
	return e.restoreStream(ctx, id, t, s, counted)
}

// restoreStream filters (when the format needs it) and restores in.
func (e *Engine) restoreStream(ctx context.Context, id string, t *importTarget, s Sniffed, in *progressReader, extraMask ...string) (int64, []string, error) {
	ctx, cancel := context.WithCancel(ctx)
	defer cancel()
	var warnings []string
	var input io.Reader = in
	var filterDone <-chan error
	var mf *mysqlFilter
	var pf *pgFilter
	switch s.Format {
	case state.FormatMySQLSQL:
		mf = newMySQLFilter()
		input, filterDone = filtered(ctx, in, mf.run)
	case state.FormatPostgresSQL:
		pf = &pgFilter{}
		input, filterDone = filtered(ctx, in, pf.run)
	}
	restoreErr := e.runRestore(ctx, id, t.restoreCommand(s), input, extraMask...)
	if filterDone != nil {
		cancel()
		if ferr := <-filterDone; ferr != nil && !errors.Is(ferr, context.Canceled) && !errors.Is(ferr, io.ErrClosedPipe) {
			return 0, nil, ferr
		}
	}
	if restoreErr != nil {
		return 0, nil, restoreErr
	}
	switch {
	case mf != nil:
		warnings = append(warnings, mf.warnings(t.db)...)
	case pf != nil && pf.dropped > 0, s.Format == state.FormatPostgresCustom:
		warnings = append(warnings, ownershipWarning)
	}
	return in.n.Load(), warnings, nil
}

// importFromURL pipes the source's dump into the restore.
func (e *Engine) importFromURL(ctx context.Context, id string, t *importTarget, src *sourceConn, progress Progress) (int64, []string, error) {
	ctx, cancel := context.WithCancel(ctx)
	defer cancel()
	dump := src.dumpCommand(t.engine)
	pr, pw := io.Pipe()
	dumpDone := make(chan error, 1)
	go func() {
		res, err := e.o.Runtime.ExecStream(ctx, id, docker.ExecOptions{Argv: dump.argv, Env: dump.env, Stdin: dump.input(nil), Stdout: pw})
		switch {
		case err != nil:
			err = fmt.Errorf("backup: running %s: %w", dump.argv[0], err)
		case res.ExitCode != 0:
			err = fmt.Errorf("%s failed (exit %d): %s", dump.argv[0], res.ExitCode, maskTail(res.Stderr, dump.env, src.raw, src.pw))
		}
		_ = pw.CloseWithError(err)
		dumpDone <- err
	}()
	in := &progressReader{r: pr, tick: func(n int64) { progress("Importing " + transfer.HumanBytes(n)) }}
	s := Sniffed{Format: formatOf(t.engine)}
	if t.engine == state.EnginePostgres {
		s.Format = state.FormatPostgresCustom
	}
	read, warnings, restoreErr := e.restoreStream(ctx, id, t, s, in, src.raw, src.pw)
	if restoreErr != nil {
		cancel()
	}
	_ = pr.CloseWithError(errors.New("restore stopped reading"))
	dumpErr := <-dumpDone
	switch {
	case dumpErr != nil && !errors.Is(dumpErr, context.Canceled):
		return 0, nil, dumpErr
	case restoreErr != nil:
		return 0, nil, restoreErr
	}
	return read, warnings, nil
}
