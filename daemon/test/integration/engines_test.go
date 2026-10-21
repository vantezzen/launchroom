//go:build integration

package integration

import (
	"context"
	"strings"
	"testing"
	"time"

	"github.com/vantezzen/launchroom/daemon/internal/secret"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// Engines added in D47 (dashboard config launchroom.database_engines).
// MongoDB 8.0 refuses Linux 6.19+ (SERVER-121912), which the dev VM runs,
// so the tests use the offered 7.0.
const (
	mongoImage      = "docker.io/library/mongo@sha256:c53852c00c23ca9f23fc39690350ffa9a80e3642fe8f96879726d8da0abe80a7"                // 7.0.45
	clickhouseImage = "docker.io/clickhouse/clickhouse-server@sha256:81d3a40da04638ce3db09772fa9f98dca34289863c0d0b8a668b33d5b8bf45a6" // 26.8.31.2-alpine
)

func waitExec(ctx context.Context, t *testing.T, p *pitrEnv, id string, argv []string, env ...string) {
	t.Helper()
	deadline := time.Now().Add(4 * time.Minute)
	for {
		if _, code := p.exec(ctx, t, id, argv, env...); code == 0 {
			return
		}
		if time.Now().After(deadline) {
			t.Fatalf("%v never succeeded", argv)
		}
		time.Sleep(2 * time.Second)
	}
}

// TestMongoBackupRestoreVerify: a MongoDB with the driver's env is dumped
// (password on stdin), restored in place after a change and into a copy
// with another database name and password, and verified (counts, validate).
func TestMongoBackupRestoreVerify(t *testing.T) {
	p := setupPITR(t)
	ctx, cancel := context.WithTimeout(context.Background(), 20*time.Minute)
	defer cancel()

	env := map[string]secret.Secret{"MONGO_INITDB_ROOT_USERNAME": "app", "MONGO_INITDB_ROOT_PASSWORD": "mongo-pw-123456", "MONGO_INITDB_DATABASE": "app"}
	wl := state.WorkloadID(randomID("wl"))
	id := p.run(ctx, t, wl, mongoImage, env, nil)
	sh := func(id, user, pw, db, script string) (string, int) {
		return p.exec(ctx, t, id, []string{"mongosh", "--quiet", "--norc", "mongodb://127.0.0.1:27017/" + db + "?authSource=admin", "--username", user, "--password", pw, "--eval", script})
	}
	deadline := time.Now().Add(4 * time.Minute)
	for {
		if _, code := sh(id, "app", "mongo-pw-123456", "app", `db.notes.insertMany([{n: 1}, {n: 2}, {n: 3}]); db.notes.createIndex({n: 1})`); code == 0 {
			break
		}
		if time.Now().After(deadline) {
			t.Fatal("mongo did not become ready")
		}
		time.Sleep(2 * time.Second)
	}
	b, err := p.eng.Backup(ctx, &state.BackupSpec{Kind: state.BackupDatabase, Target: state.BackupTarget{Engine: state.EngineMongoDB, Workload: wl},
		Destination: p.dest, Tags: []state.BackupTag{"kind:database"}}, p.creds, p.logf)
	if err != nil {
		t.Fatal(err)
	}
	if b.SizeBytes < 100 {
		t.Fatalf("archive of %d bytes", b.SizeBytes)
	}

	// In place: a change after the backup is undone.
	if out, code := sh(id, "app", "mongo-pw-123456", "app", `db.notes.deleteMany({}); db.later.insertOne({x: 1})`); code != 0 {
		t.Fatal(out)
	}
	if _, err := p.eng.Restore(ctx, &state.RestoreSpec{Kind: state.BackupDatabase, Snapshot: b.SnapshotID, Destination: p.dest,
		Target: state.BackupTarget{Engine: state.EngineMongoDB, Workload: wl}, Mode: state.RestoreInPlace}, p.creds, p.logf); err != nil {
		t.Fatal(err)
	}
	if out, _ := sh(id, "app", "mongo-pw-123456", "app", `print(db.notes.countDocuments({}))`); lastLineOf(out) != "3" {
		t.Fatalf("after the in-place restore: %q", out)
	}

	// As new: another database name and password.
	copyEnv := map[string]secret.Secret{"MONGO_INITDB_ROOT_USERNAME": "app", "MONGO_INITDB_ROOT_PASSWORD": "copy-pw-654321", "MONGO_INITDB_DATABASE": "other"}
	cwl := state.WorkloadID(randomID("wl"))
	cid := p.run(ctx, t, cwl, mongoImage, copyEnv, nil)
	deadline = time.Now().Add(4 * time.Minute)
	for {
		if _, code := sh(cid, "app", "copy-pw-654321", "other", `db.runCommand({ping: 1})`); code == 0 {
			break
		}
		if time.Now().After(deadline) {
			t.Fatal("the copy did not become ready")
		}
		time.Sleep(2 * time.Second)
	}
	if _, err := p.eng.Restore(ctx, &state.RestoreSpec{Kind: state.BackupDatabase, Snapshot: b.SnapshotID, Destination: p.dest,
		Target: state.BackupTarget{Engine: state.EngineMongoDB, Workload: cwl}, Mode: state.RestoreNewService}, p.creds, p.logf); err != nil {
		t.Fatal(err)
	}
	if out, _ := sh(cid, "app", "copy-pw-654321", "other", `print(db.notes.countDocuments({}))`); lastLineOf(out) != "3" {
		t.Fatalf("the copy holds %q", out)
	}

	res, err := p.eng.Verify(ctx, &state.VerifyRestoreSpec{Kind: state.BackupDatabase, Snapshot: b.SnapshotID, Destination: p.dest, Engine: state.EngineMongoDB,
		Image: mongoImage, DataPath: "/data/db", Resources: state.Resources{CPUs: 1, MemoryMB: 1024, Pids: 512}}, p.creds, p.logf)
	if err != nil {
		t.Fatal(err)
	}
	if !res.Passed || res.Tables != 1 || res.Rows != 3 {
		t.Fatalf("mongo verification %+v", res)
	}
}

// TestClickHouseBackupRestoreVerify: native BACKUP streamed out of the data
// volume, restored in place and into a copy with another database name,
// verified with CHECK TABLE; no staged zip is left behind.
func TestClickHouseBackupRestoreVerify(t *testing.T) {
	p := setupPITR(t)
	ctx, cancel := context.WithTimeout(context.Background(), 20*time.Minute)
	defer cancel()

	env := map[string]secret.Secret{"CLICKHOUSE_USER": "app", "CLICKHOUSE_PASSWORD": "ch-pw-123456", "CLICKHOUSE_DATABASE": "default", "CLICKHOUSE_DEFAULT_ACCESS_MANAGEMENT": "1"}
	wl := state.WorkloadID(randomID("wl"))
	id := p.run(ctx, t, wl, clickhouseImage, env, nil)
	q := func(id, pw, db, query string) (string, int) {
		return p.exec(ctx, t, id, []string{"clickhouse-client", "--host=127.0.0.1", "--database=" + db, "--query", query}, "CLICKHOUSE_USER=app", "CLICKHOUSE_PASSWORD="+pw)
	}
	waitExec(ctx, t, p, id, []string{"clickhouse-client", "--host=127.0.0.1", "--query", "SELECT 1"}, "CLICKHOUSE_USER=app", "CLICKHOUSE_PASSWORD=ch-pw-123456")
	if out, code := q(id, "ch-pw-123456", "default", "CREATE TABLE events (id UInt64, name String) ENGINE = MergeTree ORDER BY id"); code != 0 {
		t.Fatal(out)
	}
	if out, code := q(id, "ch-pw-123456", "default", "INSERT INTO events SELECT number, toString(number) FROM numbers(1000)"); code != 0 {
		t.Fatal(out)
	}
	b, err := p.eng.Backup(ctx, &state.BackupSpec{Kind: state.BackupDatabase, Target: state.BackupTarget{Engine: state.EngineClickHouse, Workload: wl},
		Destination: p.dest, Tags: []state.BackupTag{"kind:database"}}, p.creds, p.logf)
	if err != nil {
		t.Fatal(err)
	}
	if out, _ := p.exec(ctx, t, id, []string{"ls", "/var/lib/clickhouse/backups"}); strings.TrimSpace(out) != "" {
		t.Fatalf("staged backup left: %q", out)
	}

	if out, code := q(id, "ch-pw-123456", "default", "TRUNCATE TABLE events"); code != 0 {
		t.Fatal(out)
	}
	if _, err := p.eng.Restore(ctx, &state.RestoreSpec{Kind: state.BackupDatabase, Snapshot: b.SnapshotID, Destination: p.dest,
		Target: state.BackupTarget{Engine: state.EngineClickHouse, Workload: wl}, Mode: state.RestoreInPlace}, p.creds, p.logf); err != nil {
		t.Fatal(err)
	}
	if out, _ := q(id, "ch-pw-123456", "default", "SELECT count() FROM events"); out != "1000" {
		t.Fatalf("after the in-place restore: %q", out)
	}

	copyEnv := map[string]secret.Secret{"CLICKHOUSE_USER": "app", "CLICKHOUSE_PASSWORD": "copy-pw-654321", "CLICKHOUSE_DATABASE": "other", "CLICKHOUSE_DEFAULT_ACCESS_MANAGEMENT": "1"}
	cwl := state.WorkloadID(randomID("wl"))
	cid := p.run(ctx, t, cwl, clickhouseImage, copyEnv, nil)
	waitExec(ctx, t, p, cid, []string{"clickhouse-client", "--host=127.0.0.1", "--query", "SELECT 1"}, "CLICKHOUSE_USER=app", "CLICKHOUSE_PASSWORD=copy-pw-654321")
	if _, err := p.eng.Restore(ctx, &state.RestoreSpec{Kind: state.BackupDatabase, Snapshot: b.SnapshotID, Destination: p.dest,
		Target: state.BackupTarget{Engine: state.EngineClickHouse, Workload: cwl}, Mode: state.RestoreNewService}, p.creds, p.logf); err != nil {
		t.Fatal(err)
	}
	if out, _ := q(cid, "copy-pw-654321", "other", "SELECT count() FROM events"); out != "1000" {
		t.Fatalf("the copy holds %q", out)
	}

	res, err := p.eng.Verify(ctx, &state.VerifyRestoreSpec{Kind: state.BackupDatabase, Snapshot: b.SnapshotID, Destination: p.dest, Engine: state.EngineClickHouse,
		Image: clickhouseImage, DataPath: "/var/lib/clickhouse", Resources: state.Resources{CPUs: 1, MemoryMB: 1024, Pids: 512}}, p.creds, p.logf)
	if err != nil {
		t.Fatal(err)
	}
	if !res.Passed || res.Tables != 1 || res.Rows != 1000 {
		t.Fatalf("clickhouse verification %+v", res)
	}
}

func lastLineOf(s string) string {
	lines := strings.Split(strings.TrimSpace(s), "\n")
	return strings.TrimSpace(lines[len(lines)-1])
}
