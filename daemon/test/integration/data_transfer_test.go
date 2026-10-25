//go:build integration

package integration

import (
	"archive/tar"
	"bytes"
	"compress/gzip"
	"context"
	"errors"
	"fmt"
	"io"
	"strings"
	"sync"
	"testing"
	"time"

	"github.com/moby/moby/api/types/mount"

	"github.com/vantezzen/launchroom/daemon/internal/backup"
	"github.com/vantezzen/launchroom/daemon/internal/docker"
	"github.com/vantezzen/launchroom/daemon/internal/policy"
	"github.com/vantezzen/launchroom/daemon/internal/secret"
	"github.com/vantezzen/launchroom/daemon/internal/state"
	"github.com/vantezzen/launchroom/daemon/internal/transfer"
)

// Data import and export (D55, docs/14 §12), against real engines in the
// dind daemon through dockerd-guard. The dashboard side of transfers is an
// in-process fake: uploads are served chunk by chunk, exports collected.

const (
	postgres17Image = "docker.io/library/postgres@sha256:b0f9560a2de083e2cc7382e75f808c7381a32852a7ec49117deedb300e552b24" // 17-alpine (dashboard config)
	postgres16Image = "docker.io/library/postgres@sha256:721873c34ceb9f8d8fc265984940dc982404c105f19ad51be9fdc5970a6080ea" // 16-alpine (dashboard config)
	transferChunk   = 1 << 20
)

var importResources = state.Resources{CPUs: 1, MemoryMB: 1024, Pids: 512}

// fakeTransfers is the dashboard side of transfers.
type fakeTransfers struct {
	mu      sync.Mutex
	uploads map[state.TransferID][]byte
	exports map[state.TransferID]map[int][]byte
	n       int
}

func (f *fakeTransfers) FetchTransferChunk(_ context.Context, id state.TransferID, n int, limit int64) ([]byte, error) {
	f.mu.Lock()
	defer f.mu.Unlock()
	data, ok := f.uploads[id]
	if !ok {
		return nil, errors.New("no such transfer")
	}
	lo := n * transferChunk
	hi := min(lo+transferChunk, len(data))
	if lo >= len(data) || int64(hi-lo) > limit {
		return nil, errors.New("bad chunk")
	}
	return bytes.Clone(data[lo:hi]), nil
}

func (f *fakeTransfers) PutTransferChunk(_ context.Context, id state.TransferID, n int, data []byte) error {
	f.mu.Lock()
	defer f.mu.Unlock()
	if f.exports[id] == nil {
		f.exports[id] = map[int][]byte{}
	}
	f.exports[id][n] = bytes.Clone(data)
	return nil
}

func (f *fakeTransfers) id() state.TransferID {
	f.mu.Lock()
	defer f.mu.Unlock()
	f.n++
	return state.TransferID(fmt.Sprintf("dtr_01J9Z8X7W6V5T4S3R2Q1P0%04d", f.n))
}

// upload stores data as an upload and returns its reference.
func (f *fakeTransfers) upload(data []byte) state.TransferRef {
	id := f.id()
	f.mu.Lock()
	f.uploads[id] = data
	f.mu.Unlock()
	return state.TransferRef{ID: id, SizeBytes: int64(len(data)), ChunkBytes: transferChunk, Chunks: (len(data) + transferChunk - 1) / transferChunk,
		SHA256: state.SHA256Hex(transfer.FileDigest(data, transferChunk))}
}

// exported joins an export's chunks and checks them against the result.
func (f *fakeTransfers) exported(t *testing.T, id state.TransferID, res *state.DataExportResult) []byte {
	t.Helper()
	f.mu.Lock()
	defer f.mu.Unlock()
	var out []byte
	for i := range res.Chunks {
		c, ok := f.exports[id][i]
		if !ok {
			t.Fatalf("chunk %d missing", i)
		}
		out = append(out, c...)
	}
	if int64(len(out)) != res.SizeBytes || transfer.FileDigest(out, transferChunk) != string(res.SHA256) {
		t.Fatalf("export of %d bytes does not match its result %+v", len(out), res)
	}
	return out
}

type transferEnv struct {
	*pitrEnv
	eng    *backup.Engine
	fake   *fakeTransfers
	images map[state.WorkloadID]string
}

func setupTransfers(t *testing.T) *transferEnv {
	t.Helper()
	p := setupPITR(t)
	te := &transferEnv{pitrEnv: p, fake: &fakeTransfers{uploads: map[state.TransferID][]byte{}, exports: map[state.TransferID]map[int][]byte{}}, images: map[state.WorkloadID]string{}}
	bin := backup.LookupBinary("")
	pol := policy.DefaultConfig()
	pol.AllowedRegistries = []string{"docker.io/library/", "docker.io/clickhouse/"}
	s3ip := strings.TrimSuffix(strings.TrimPrefix(string(p.dest.Endpoint), "http://"), ":7070")
	te.eng = backup.New(backup.Options{
		ResticBinary: bin, DataDir: t.TempDir(), InsecureHosts: []string{s3ip}, Docker: p.engine,
		WorkloadEnv: func(id state.WorkloadID) (map[string]secret.Secret, error) {
			if env, ok := p.envs[id]; ok {
				return env, nil
			}
			return nil, fmt.Errorf("unknown workload %s", id)
		},
		Workload: func(id state.WorkloadID) (backup.WorkloadRuntime, error) {
			img, ok := te.images[id]
			if !ok {
				return backup.WorkloadRuntime{}, fmt.Errorf("unknown workload %s", id)
			}
			return backup.WorkloadRuntime{Image: state.ImageRef(img), Network: p.network}, nil
		},
		ServerID: string(p.serverID), Volumes: p.engine, Runtime: p.engine, Policy: pol,
		PollEvery: time.Second, VolumeIdleTimeout: time.Minute, Transfers: te.fake,
		Free: func(string) (int64, bool) { return 1 << 40, true },
	})
	return te
}

// db starts a database workload and remembers its image.
func (te *transferEnv) db(ctx context.Context, t *testing.T, image string, env map[string]secret.Secret) (state.WorkloadID, string) {
	t.Helper()
	wl := state.WorkloadID(randomID("wl"))
	id := te.run(ctx, t, wl, image, env, nil)
	te.images[wl] = image
	return wl, id
}

func (te *transferEnv) ip(ctx context.Context, t *testing.T, id string) string {
	t.Helper()
	c, err := te.engine.InspectContainer(ctx, id)
	if err != nil {
		t.Fatal(err)
	}
	return c.Networks[te.network].String()
}

// output runs argv in a container and returns its stdout bytes.
func (te *transferEnv) output(ctx context.Context, t *testing.T, id string, argv []string, env ...string) []byte {
	t.Helper()
	var out bytes.Buffer
	res, err := te.engine.ExecStream(ctx, id, docker.ExecOptions{Argv: argv, Env: env, Stdout: &out})
	if err != nil || res.ExitCode != 0 {
		t.Fatalf("%v: %v %d %s", argv, err, res.ExitCode, res.Stderr)
	}
	return out.Bytes()
}

func gzipped(b []byte) []byte {
	var out bytes.Buffer
	zw := gzip.NewWriter(&out)
	_, _ = zw.Write(b)
	_ = zw.Close()
	return out.Bytes()
}

func (te *transferEnv) importDB(ctx context.Context, task string, spec *state.DbImportSpec, url string) (*state.DataImportResult, error) {
	spec.Resources = importResources
	return te.eng.ImportDatabase(ctx, state.TaskID(task), spec, secret.Secret(url), te.logf, nil)
}

func taskID() string { return randomID("tsk") }

func pgEnv(pw string) map[string]secret.Secret {
	return map[string]secret.Secret{"POSTGRES_USER": "app", "POSTGRES_DB": "app", "POSTGRES_PASSWORD": secret.Secret(pw)}
}

func (te *transferEnv) waitPG(ctx context.Context, t *testing.T, id, pw string) {
	t.Helper()
	waitExec(ctx, t, te.pitrEnv, id, []string{"psql", "--host=127.0.0.1", "--username=app", "--dbname=app", "-tAc", "SELECT 1"}, "PGPASSWORD="+pw)
}

func (te *transferEnv) psql(ctx context.Context, t *testing.T, id, pw, sql string) string {
	t.Helper()
	out, code := te.exec(ctx, t, id, []string{"psql", "--host=127.0.0.1", "--username=app", "--dbname=app", "-tAc", sql}, "PGPASSWORD="+pw)
	if code != 0 {
		t.Fatalf("psql %q: %s", sql, out)
	}
	return strings.TrimSpace(out)
}

// TestDataTransferPostgres: a 16 source into a 17 target, by URL and by an
// uploaded -Fc dump and a plain .sql.gz; a newer dump is refused naming both
// versions; a target that isn't empty needs replace.
func TestDataTransferPostgres(t *testing.T) {
	te := setupTransfers(t)
	ctx, cancel := context.WithTimeout(context.Background(), 25*time.Minute)
	defer cancel()

	_, src := te.db(ctx, t, postgres16Image, pgEnv("src-pw-123456"))
	te.waitPG(ctx, t, src, "src-pw-123456")
	te.psql(ctx, t, src, "src-pw-123456", "CREATE TABLE notes (id serial PRIMARY KEY, body text); INSERT INTO notes (body) VALUES ('one'), ('two'), ('three'); CREATE VIEW recent AS SELECT * FROM notes")

	twl, tgt := te.db(ctx, t, postgres17Image, pgEnv("tgt-pw-654321"))
	te.waitPG(ctx, t, tgt, "tgt-pw-654321")
	te.psql(ctx, t, tgt, "tgt-pw-654321", "CREATE TABLE leftover (x int)")

	// Inspect: not empty, version 17.
	in, err := te.importDB(ctx, taskID(), &state.DbImportSpec{Mode: state.ImportInspect, Engine: state.EnginePostgres, Workload: twl}, "")
	if err != nil {
		t.Fatal(err)
	}
	if in.Empty || in.Tables != 1 || !strings.HasPrefix(in.ServerVersion, "17.") {
		t.Fatalf("inspection %+v", in)
	}
	url := "postgres://app:src-pw-123456@" + te.ip(ctx, t, src) + ":5432/app?sslmode=disable"
	urlSource := &state.DbImportSource{Kind: state.SourceURL, URL: &state.Sealed{}}

	// An inspection with the URL reaches the source.
	in, err = te.importDB(ctx, taskID(), &state.DbImportSpec{Mode: state.ImportInspect, Engine: state.EnginePostgres, Workload: twl, Source: urlSource}, url)
	if err != nil || !strings.HasPrefix(in.SourceVersion, "PostgreSQL 16.") {
		t.Fatalf("source check %+v %v", in, err)
	}
	// An unreachable source is a clear error, and its password never shows.
	_, err = te.importDB(ctx, taskID(), &state.DbImportSpec{Mode: state.ImportInspect, Engine: state.EnginePostgres, Workload: twl, Source: urlSource},
		"postgres://app:hunter2-secret@"+te.ip(ctx, t, src)+":5999/app")
	if err == nil || !strings.Contains(err.Error(), "couldn't reach") || strings.Contains(err.Error(), "hunter2-secret") {
		t.Fatalf("unreachable source: %v", err)
	}

	// Not empty without replace: refused.
	_, err = te.importDB(ctx, taskID(), &state.DbImportSpec{Mode: state.ImportRun, Engine: state.EnginePostgres, Workload: twl, Source: urlSource}, url)
	if err == nil || !strings.Contains(err.Error(), "isn't empty") {
		t.Fatalf("non-empty target: %v", err)
	}
	// By URL with replace.
	res, err := te.importDB(ctx, taskID(), &state.DbImportSpec{Mode: state.ImportRun, Engine: state.EnginePostgres, Workload: twl, Source: urlSource, Replace: true}, url)
	if err != nil {
		t.Fatal(err)
	}
	if res.Tables != 1 || res.ReadBytes == 0 || te.psql(ctx, t, tgt, "tgt-pw-654321", "SELECT count(*) FROM notes") != "3" {
		t.Fatalf("url import %+v", res)
	}
	if te.psql(ctx, t, tgt, "tgt-pw-654321", "SELECT count(*) FROM pg_tables WHERE tablename = 'leftover'") != "0" {
		t.Fatal("replace kept the old table")
	}

	// From an uploaded -Fc dump into a fresh 17.
	dump := te.output(ctx, t, src, []string{"pg_dump", "-Fc", "--host=127.0.0.1", "--username=app", "--dbname=app"}, "PGPASSWORD=src-pw-123456")
	fwl, fresh := te.db(ctx, t, postgres17Image, pgEnv("fresh-pw-11111"))
	te.waitPG(ctx, t, fresh, "fresh-pw-11111")
	res, err = te.importDB(ctx, taskID(), &state.DbImportSpec{Mode: state.ImportRun, Engine: state.EnginePostgres, Workload: fwl,
		Source: &state.DbImportSource{Kind: state.SourceFile, File: ptrRef(te.fake.upload(dump))}}, "")
	if err != nil {
		t.Fatal(err)
	}
	if res.Format != state.FormatPostgresCustom || !strings.HasPrefix(res.SourceVersion, "PostgreSQL 16.") || te.psql(ctx, t, fresh, "fresh-pw-11111", "SELECT string_agg(body, ',' ORDER BY id) FROM recent") != "one,two,three" {
		t.Fatalf("file import %+v", res)
	}

	// A plain .sql.gz (with OWNER TO and GRANT lines) replacing it.
	te.psql(ctx, t, src, "src-pw-123456", "INSERT INTO notes (body) VALUES ('four'); GRANT SELECT ON notes TO PUBLIC")
	plain := te.output(ctx, t, src, []string{"pg_dump", "--host=127.0.0.1", "--username=app", "--dbname=app"}, "PGPASSWORD=src-pw-123456")
	if !bytes.Contains(plain, []byte("OWNER TO app")) {
		t.Fatal("expected ownership lines in the plain dump")
	}
	res, err = te.importDB(ctx, taskID(), &state.DbImportSpec{Mode: state.ImportRun, Engine: state.EnginePostgres, Workload: fwl, Replace: true,
		Source: &state.DbImportSource{Kind: state.SourceFile, File: ptrRef(te.fake.upload(gzipped(plain)))}}, "")
	if err != nil {
		t.Fatal(err)
	}
	if !res.Compressed || res.Format != state.FormatPostgresSQL || te.psql(ctx, t, fresh, "fresh-pw-11111", "SELECT count(*) FROM notes") != "4" {
		t.Fatalf("plain import %+v", res)
	}

	// A dump from 18 into 17: refused before anything changes.
	_, newer := te.db(ctx, t, postgresImage, pgEnv("new-pw-222222"))
	te.waitPG(ctx, t, newer, "new-pw-222222")
	te.psql(ctx, t, newer, "new-pw-222222", "CREATE TABLE x (i int)")
	newDump := te.output(ctx, t, newer, []string{"pg_dump", "-Fc", "--host=127.0.0.1", "--username=app", "--dbname=app"}, "PGPASSWORD=new-pw-222222")
	_, err = te.importDB(ctx, taskID(), &state.DbImportSpec{Mode: state.ImportRun, Engine: state.EnginePostgres, Workload: fwl, Replace: true,
		Source: &state.DbImportSource{Kind: state.SourceFile, File: ptrRef(te.fake.upload(newDump))}}, "")
	if err == nil || !strings.Contains(err.Error(), "PostgreSQL 18") || !strings.Contains(err.Error(), "PostgreSQL 17") {
		t.Fatalf("newer dump: %v", err)
	}
	if te.psql(ctx, t, fresh, "fresh-pw-11111", "SELECT count(*) FROM notes") != "4" {
		t.Fatal("the refused import changed the database")
	}
	assertNoImportLeftovers(ctx, t, te)
}

// TestDataImportBeforeFirstDeploy: the dashboard prepares a database that
// was never deployed (docs/14 §12.9): its workload runs alone on its volume
// for the import and is removed again; the first deploy's workload (a new
// id, the same volume and credentials) finds the imported data.
func TestDataImportBeforeFirstDeploy(t *testing.T) {
	te := setupTransfers(t)
	ctx, cancel := context.WithTimeout(context.Background(), 15*time.Minute)
	defer cancel()

	_, src := te.db(ctx, t, postgres17Image, pgEnv("src-pw-123456"))
	te.waitPG(ctx, t, src, "src-pw-123456")
	te.psql(ctx, t, src, "src-pw-123456", "CREATE TABLE website (id serial PRIMARY KEY, name text); INSERT INTO website (name) VALUES ('blog'), ('shop')")
	dump := te.output(ctx, t, src, []string{"pg_dump", "-Fc", "--host=127.0.0.1", "--username=app", "--dbname=app"}, "PGPASSWORD=src-pw-123456")

	_, volume := te.serviceVolume(ctx, t)
	mounts := []mount.Mount{{Type: mount.TypeVolume, Source: volume, Target: "/var/lib/postgresql/data"}}
	env := pgEnv("db-pw-777777")

	// The preparation: the database alone, on its volume, empty.
	prepared := state.WorkloadID(randomID("wl"))
	id := te.runMounted(ctx, t, prepared, postgres17Image, env, nil, mounts)
	te.images[prepared] = postgres17Image
	te.waitPG(ctx, t, id, "db-pw-777777")
	in, err := te.importDB(ctx, taskID(), &state.DbImportSpec{Mode: state.ImportInspect, Engine: state.EnginePostgres, Workload: prepared}, "")
	if err != nil || !in.Empty {
		t.Fatalf("prepared database: %+v %v", in, err)
	}
	if _, err := te.importDB(ctx, taskID(), &state.DbImportSpec{Mode: state.ImportRun, Engine: state.EnginePostgres, Workload: prepared,
		Source: &state.DbImportSource{Kind: state.SourceFile, File: ptrRef(te.fake.upload(dump))}}, ""); err != nil {
		t.Fatal(err)
	}

	// Stopped and removed again; the volume stays.
	if err := te.engine.StopContainer(ctx, id, time.Minute); err != nil {
		t.Fatal(err)
	}
	if err := te.engine.RemoveContainer(ctx, id); err != nil {
		t.Fatal(err)
	}

	// The first deploy: a new workload on the same volume with the same credentials.
	first := state.WorkloadID(randomID("wl"))
	id = te.runMounted(ctx, t, first, postgres17Image, env, nil, mounts)
	te.waitPG(ctx, t, id, "db-pw-777777")
	if got := te.psql(ctx, t, id, "db-pw-777777", "SELECT string_agg(name, ',' ORDER BY id) FROM website"); got != "blog,shop" {
		t.Fatalf("the first deploy sees %q", got)
	}
	assertNoImportLeftovers(ctx, t, te)
}

func ptrRef(r state.TransferRef) *state.TransferRef { return &r }

func assertNoImportLeftovers(ctx context.Context, t *testing.T, te *transferEnv) {
	t.Helper()
	for _, h := range []string{"import", "import-check"} {
		cs, err := te.engine.ListContainers(ctx, map[string]string{docker.LabelHelper: h})
		if err != nil || len(cs) != 0 {
			t.Fatalf("%d %s throwaways left (%v)", len(cs), h, err)
		}
	}
}

func myEnv(pw string) map[string]secret.Secret {
	return map[string]secret.Secret{"MYSQL_USER": "app", "MYSQL_PASSWORD": secret.Secret(pw), "MYSQL_DATABASE": "app", "MYSQL_ROOT_PASSWORD": secret.Secret("root-" + pw)}
}

func (te *transferEnv) mysql(ctx context.Context, t *testing.T, id, user, pw, sql string) string {
	t.Helper()
	out, code := te.exec(ctx, t, id, []string{"mysql", "--protocol=tcp", "--host=127.0.0.1", "--user=" + user, "--database=app", "-N", "-B", "-e", sql}, "MYSQL_PWD="+pw)
	if code != 0 {
		t.Fatalf("mysql %q: %s", sql, out)
	}
	return strings.TrimSpace(out)
}

func (te *transferEnv) waitMySQL(ctx context.Context, t *testing.T, id, pw string) {
	t.Helper()
	waitExec(ctx, t, te.pitrEnv, id, []string{"mysql", "--protocol=tcp", "--host=127.0.0.1", "--user=app", "--database=app", "-e", "SELECT 1"}, "MYSQL_PWD="+pw)
}

// TestDataTransferMySQL: an uploaded mysqldump with a DEFINER view, GTID
// statements and CREATE DATABASE/USE, and a URL import replacing the data.
func TestDataTransferMySQL(t *testing.T) {
	te := setupTransfers(t)
	ctx, cancel := context.WithTimeout(context.Background(), 25*time.Minute)
	defer cancel()

	_, src := te.db(ctx, t, mysqlImage, myEnv("src-pw-123456"))
	twl, tgt := te.db(ctx, t, mysqlImage, myEnv("tgt-pw-654321"))
	te.waitMySQL(ctx, t, src, "src-pw-123456")
	te.waitMySQL(ctx, t, tgt, "tgt-pw-654321")
	te.mysql(ctx, t, src, "root", "root-src-pw-123456", "CREATE TABLE app.orders (id INT PRIMARY KEY, total INT); INSERT INTO app.orders VALUES (1, 10), (2, 20); "+
		"CREATE DEFINER=`root`@`%` SQL SECURITY DEFINER VIEW app.big AS SELECT * FROM app.orders WHERE total > 15")

	dump := te.output(ctx, t, src, []string{"mysqldump", "--protocol=tcp", "--host=127.0.0.1", "--user=root", "--single-transaction", "--databases", "app"}, "MYSQL_PWD=root-src-pw-123456")
	noise := "SET @MYSQLDUMP_TEMP_LOG_BIN = @@SESSION.SQL_LOG_BIN;\nSET @@SESSION.SQL_LOG_BIN= 0;\n" +
		"SET @@GLOBAL.GTID_PURGED=/*!80000 '+'*/ '3E11FA47-71CA-11E1-9E33-C80AA9429562:1-5,\n5A1B2C3D-71CA-11E1-9E33-C80AA9429562:1-3';\n"
	file := append([]byte(noise), dump...)
	res, err := te.importDB(ctx, taskID(), &state.DbImportSpec{Mode: state.ImportRun, Engine: state.EngineMySQL, Workload: twl,
		Source: &state.DbImportSource{Kind: state.SourceFile, File: ptrRef(te.fake.upload(gzipped(file)))}}, "")
	if err != nil {
		t.Fatal(err)
	}
	if res.Format != state.FormatMySQLSQL || len(res.Warnings) < 2 || te.mysql(ctx, t, tgt, "app", "tgt-pw-654321", "SELECT id FROM big") != "2" {
		t.Fatalf("file import %+v", res)
	}

	// URL import replaces it.
	te.mysql(ctx, t, src, "root", "root-src-pw-123456", "INSERT INTO app.orders VALUES (3, 30)")
	url := "mysql://app:src-pw-123456@" + te.ip(ctx, t, src) + ":3306/app"
	res, err = te.importDB(ctx, taskID(), &state.DbImportSpec{Mode: state.ImportRun, Engine: state.EngineMySQL, Workload: twl, Replace: true,
		Source: &state.DbImportSource{Kind: state.SourceURL, URL: &state.Sealed{}}}, url)
	if err != nil {
		t.Fatal(err)
	}
	if !strings.HasPrefix(res.SourceVersion, "MySQL 8.4") || te.mysql(ctx, t, tgt, "app", "tgt-pw-654321", "SELECT count(*) FROM orders") != "3" {
		t.Fatalf("url import %+v", res)
	}
	assertNoImportLeftovers(ctx, t, te)
}

func mongoEnv(pw string) map[string]secret.Secret {
	return map[string]secret.Secret{"MONGO_INITDB_ROOT_USERNAME": "app", "MONGO_INITDB_ROOT_PASSWORD": secret.Secret(pw), "MONGO_INITDB_DATABASE": "app"}
}

// TestDataTransferMongo: a mongodump --archive --gzip of another database
// name into the target's, and a URL import (MongoDB 7.0 only: 8.0 refuses
// the dev VM's kernel).
func TestDataTransferMongo(t *testing.T) {
	te := setupTransfers(t)
	ctx, cancel := context.WithTimeout(context.Background(), 20*time.Minute)
	defer cancel()

	srcEnv := mongoEnv("src-pw-123456")
	srcEnv["MONGO_INITDB_DATABASE"] = "shop"
	_, src := te.db(ctx, t, mongoImage, srcEnv)
	twl, tgt := te.db(ctx, t, mongoImage, mongoEnv("tgt-pw-654321"))
	sh := func(id, pw, db, script string) string {
		out, code := te.exec(ctx, t, id, []string{"mongosh", "--quiet", "--norc", "mongodb://127.0.0.1:27017/" + db + "?authSource=admin", "--username", "app", "--password", pw, "--eval", script})
		if code != 0 {
			t.Fatalf("mongosh %q: %s", script, out)
		}
		return lastLineOf(out)
	}
	waitExec(ctx, t, te.pitrEnv, src, []string{"mongosh", "--quiet", "--norc", "mongodb://127.0.0.1:27017/shop?authSource=admin", "--username", "app", "--password", "src-pw-123456", "--eval", "db.runCommand({ping: 1})"})
	waitExec(ctx, t, te.pitrEnv, tgt, []string{"mongosh", "--quiet", "--norc", "mongodb://127.0.0.1:27017/app?authSource=admin", "--username", "app", "--password", "tgt-pw-654321", "--eval", "db.runCommand({ping: 1})"})
	sh(src, "src-pw-123456", "shop", `db.items.insertMany([{n: 1}, {n: 2}, {n: 3}]); db.carts.insertOne({x: 1})`)

	archive := te.output(ctx, t, src, []string{"mongodump", "--host=127.0.0.1", "--username=app", "--password=src-pw-123456", "--authenticationDatabase=admin", "--archive", "--gzip", "--db=shop", "--quiet"})
	res, err := te.importDB(ctx, taskID(), &state.DbImportSpec{Mode: state.ImportRun, Engine: state.EngineMongoDB, Workload: twl,
		Source: &state.DbImportSource{Kind: state.SourceFile, File: ptrRef(te.fake.upload(archive))}}, "")
	if err != nil {
		t.Fatal(err)
	}
	if res.Tables != 2 || res.Format != state.FormatMongoArchive || sh(tgt, "tgt-pw-654321", "app", "print(db.items.countDocuments({}))") != "3" {
		t.Fatalf("archive import %+v", res)
	}

	sh(src, "src-pw-123456", "shop", `db.items.insertOne({n: 4})`)
	url := "mongodb://app:src-pw-123456@" + te.ip(ctx, t, src) + ":27017/shop?authSource=admin"
	res, err = te.importDB(ctx, taskID(), &state.DbImportSpec{Mode: state.ImportRun, Engine: state.EngineMongoDB, Workload: twl, Replace: true,
		Source: &state.DbImportSource{Kind: state.SourceURL, URL: &state.Sealed{}}}, url)
	if err != nil {
		t.Fatal(err)
	}
	if !strings.HasPrefix(res.SourceVersion, "MongoDB 7.0") || sh(tgt, "tgt-pw-654321", "app", "print(db.items.countDocuments({}))") != "4" {
		t.Fatalf("url import %+v", res)
	}
	assertNoImportLeftovers(ctx, t, te)
}

type volEntry struct {
	typ      byte
	content  string
	uid, gid int
	mode     int64
	link     string
}

// volumeTree reads every entry of a volume below its root.
func volumeTree(ctx context.Context, t *testing.T, e *env, volume string) map[string]volEntry {
	t.Helper()
	id := volumeHelper(ctx, t, e, volume, true)
	defer func() { _ = e.engine.RemoveContainer(context.WithoutCancel(ctx), id) }()
	rc, err := e.engine.StreamFromContainer(ctx, id, "/data")
	if err != nil {
		t.Fatal(err)
	}
	defer func() { _ = rc.Close() }()
	out := map[string]volEntry{}
	tr := tar.NewReader(rc)
	for {
		h, err := tr.Next()
		if errors.Is(err, io.EOF) {
			return out
		}
		if err != nil {
			t.Fatal(err)
		}
		if h.Name == "data/" {
			continue
		}
		data, _ := io.ReadAll(tr)
		out[h.Name] = volEntry{typ: h.Typeflag, content: string(data), uid: h.Uid, gid: h.Gid, mode: h.Mode & 0o7777, link: h.Linkname}
	}
}

// TestDataTransferVolumeRoundTrip: a volume exported live and from its
// backup and imported into fresh volumes has identical files, modes,
// owners and symlinks; an unsafe archive changes nothing.
func TestDataTransferVolumeRoundTrip(t *testing.T) {
	te := setupTransfers(t)
	ctx, cancel := context.WithTimeout(context.Background(), 15*time.Minute)
	defer cancel()

	volID, name := te.serviceVolume(ctx, t)
	var seed bytes.Buffer
	tw := tar.NewWriter(&seed)
	for _, h := range []*tar.Header{
		{Name: "data/", Typeflag: tar.TypeDir, Mode: 0o755, Uid: 1000, Gid: 1000},
		{Name: "data/uploads/", Typeflag: tar.TypeDir, Mode: 0o750, Uid: 1000, Gid: 1000},
		{Name: "data/uploads/2026/", Typeflag: tar.TypeDir, Mode: 0o755, Uid: 1000, Gid: 1000},
		{Name: "data/uploads/2026/a.png", Typeflag: tar.TypeReg, Mode: 0o640, Uid: 1000, Gid: 1000, Size: 3},
		{Name: "data/uploads/latest", Typeflag: tar.TypeSymlink, Linkname: "2026/a.png", Uid: 1000, Gid: 1000},
		{Name: "data/config.yml", Typeflag: tar.TypeReg, Mode: 0o600, Uid: 0, Gid: 0, Size: 5},
		{Name: "data/run.sh", Typeflag: tar.TypeReg, Mode: 0o755, Uid: 33, Gid: 33, Size: 2},
	} {
		h.ModTime = time.Now()
		if err := tw.WriteHeader(h); err != nil {
			t.Fatal(err)
		}
		_, _ = tw.Write([]byte(map[string]string{"data/uploads/2026/a.png": "PNG", "data/config.yml": "a: 1\n", "data/run.sh": "#!"}[h.Name]))
	}
	_ = tw.Close()
	h := volumeHelper(ctx, t, te.env, name, false)
	if err := te.engine.StreamToContainer(ctx, h, "/", &seed); err != nil {
		t.Fatal(err)
	}
	_ = te.engine.RemoveContainer(ctx, h)
	want := volumeTree(ctx, t, te.env, name)
	if len(want) != 6 {
		t.Fatalf("seeded %v", want)
	}

	exportLive := func() []byte {
		target := state.TransferTarget{ID: te.fake.id(), ChunkBytes: transferChunk}
		res, err := te.eng.ExportVolume(ctx, &state.VolumeExportSpec{Volume: volID, Consistency: state.ConsistencyLive, Transfer: target}, backup.Credentials{}, te.logf, nil)
		if err != nil {
			t.Fatal(err)
		}
		if res.Format != state.FormatTar || !res.Compressed {
			t.Fatalf("export %+v", res)
		}
		return te.fake.exported(t, target.ID, res)
	}
	importInto := func(data []byte) map[string]volEntry {
		freshID, freshName := te.serviceVolume(ctx, t)
		in, err := te.eng.ImportVolume(ctx, state.TaskID(taskID()), &state.VolumeImportSpec{Mode: state.ImportInspect, Volume: freshID}, te.logf, nil)
		if err != nil || !in.Empty {
			t.Fatalf("fresh volume inspection %+v %v", in, err)
		}
		res, err := te.eng.ImportVolume(ctx, state.TaskID(taskID()), &state.VolumeImportSpec{Mode: state.ImportRun, Volume: freshID, File: ptrRef(te.fake.upload(data))}, te.logf, nil)
		if err != nil {
			t.Fatal(err)
		}
		if res.Files != 6 || res.Format != state.FormatTar {
			t.Fatalf("import %+v", res)
		}
		return volumeTree(ctx, t, te.env, freshName)
	}
	compare := func(got map[string]volEntry, how string) {
		t.Helper()
		for k, w := range want {
			g, ok := got[k]
			if !ok {
				t.Errorf("%s: %s missing", how, k)
				continue
			}
			if g.typ != w.typ || g.content != w.content || g.uid != w.uid || g.gid != w.gid || g.link != w.link || (w.typ != tar.TypeSymlink && g.mode != w.mode) {
				t.Errorf("%s: %s is %+v, want %+v", how, k, g, w)
			}
		}
		if len(got) != len(want) {
			t.Errorf("%s: %d entries, want %d", how, len(got), len(want))
		}
	}
	compare(importInto(exportLive()), "live export")

	// From a backup of the volume.
	b, err := te.eng.Backup(ctx, &state.BackupSpec{Kind: state.BackupVolume, Target: state.BackupTarget{Volume: volID, Consistency: state.ConsistencyLive},
		Destination: te.dest, Tags: []state.BackupTag{"kind:volume"}}, te.creds, te.logf)
	if err != nil {
		t.Fatal(err)
	}
	target := state.TransferTarget{ID: te.fake.id(), ChunkBytes: transferChunk}
	dest := te.dest
	res, err := te.eng.ExportVolume(ctx, &state.VolumeExportSpec{Snapshot: b.SnapshotID, Destination: &dest, Transfer: target}, te.creds, te.logf, nil)
	if err != nil {
		t.Fatal(err)
	}
	compare(importInto(te.fake.exported(t, target.ID, res)), "backup download")

	// Merge an unsafe archive: refused before anything changes.
	var bad bytes.Buffer
	btw := tar.NewWriter(&bad)
	_ = btw.WriteHeader(&tar.Header{Name: "ok.txt", Typeflag: tar.TypeReg, Mode: 0o644, Size: 1})
	_, _ = btw.Write([]byte("x"))
	_ = btw.WriteHeader(&tar.Header{Name: "escape", Typeflag: tar.TypeSymlink, Linkname: "../../etc/passwd"})
	_ = btw.Close()
	_, err = te.eng.ImportVolume(ctx, state.TaskID(taskID()), &state.VolumeImportSpec{Mode: state.ImportRun, Volume: volID, File: ptrRef(te.fake.upload(bad.Bytes()))}, te.logf, nil)
	if err == nil || !strings.Contains(err.Error(), "outside the archive") {
		t.Fatalf("unsafe archive: %v", err)
	}
	if _, ok := volumeTree(ctx, t, te.env, name)["data/ok.txt"]; ok {
		t.Fatal("a refused archive was partly extracted")
	}
}

// TestDataTransferBackupDownload: a database backup downloaded through
// db_export restores to the same data; so does a live export.
func TestDataTransferBackupDownload(t *testing.T) {
	te := setupTransfers(t)
	ctx, cancel := context.WithTimeout(context.Background(), 15*time.Minute)
	defer cancel()

	swl, src := te.db(ctx, t, postgres17Image, pgEnv("src-pw-123456"))
	te.waitPG(ctx, t, src, "src-pw-123456")
	te.psql(ctx, t, src, "src-pw-123456", "CREATE TABLE events (id int, name text); INSERT INTO events SELECT g, md5(g::text) || md5((g * 7)::text) FROM generate_series(1, 200000) g")
	b, err := te.eng.Backup(ctx, &state.BackupSpec{Kind: state.BackupDatabase, Target: state.BackupTarget{Engine: state.EnginePostgres, Workload: swl},
		Destination: te.dest, Tags: []state.BackupTag{"kind:database"}}, te.creds, te.logf)
	if err != nil {
		t.Fatal(err)
	}
	te.psql(ctx, t, src, "src-pw-123456", "DELETE FROM events WHERE id > 100")

	dest := te.dest
	target := state.TransferTarget{ID: te.fake.id(), ChunkBytes: transferChunk}
	res, err := te.eng.ExportDatabase(ctx, &state.DbExportSpec{Engine: state.EnginePostgres, Snapshot: b.SnapshotID, Destination: &dest, Transfer: target}, te.creds, te.logf, nil)
	if err != nil {
		t.Fatal(err)
	}
	download := te.fake.exported(t, target.ID, res)
	if res.Format != state.FormatPostgresCustom || !bytes.HasPrefix(download, []byte("PGDMP")) {
		t.Fatalf("backup download %+v", res)
	}
	live := state.TransferTarget{ID: te.fake.id(), ChunkBytes: transferChunk}
	lres, err := te.eng.ExportDatabase(ctx, &state.DbExportSpec{Engine: state.EnginePostgres, Workload: swl, Transfer: live}, backup.Credentials{}, te.logf, nil)
	if err != nil {
		t.Fatal(err)
	}
	liveDump := te.fake.exported(t, live.ID, lres)

	if res.Chunks < 2 {
		t.Fatalf("expected a download of several chunks, got %+v", res)
	}
	for want, data := range map[string][]byte{"200000": download, "100": liveDump} {
		wl, id := te.db(ctx, t, postgres17Image, pgEnv("dl-pw-333333"))
		te.waitPG(ctx, t, id, "dl-pw-333333")
		if _, err := te.importDB(ctx, taskID(), &state.DbImportSpec{Mode: state.ImportRun, Engine: state.EnginePostgres, Workload: wl,
			Source: &state.DbImportSource{Kind: state.SourceFile, File: ptrRef(te.fake.upload(data))}}, ""); err != nil {
			t.Fatal(err)
		}
		if got := te.psql(ctx, t, id, "dl-pw-333333", "SELECT count(*) FROM events"); got != want {
			t.Fatalf("restored %s rows, want %s", got, want)
		}
	}
}
