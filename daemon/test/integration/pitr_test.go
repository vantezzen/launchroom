//go:build integration

package integration

import (
	"context"
	"fmt"
	"slices"
	"strings"
	"testing"
	"time"

	"github.com/moby/moby/api/types/container"
	"github.com/moby/moby/api/types/mount"

	"github.com/vantezzen/launchroom/daemon/internal/backup"
	"github.com/vantezzen/launchroom/daemon/internal/docker"
	"github.com/vantezzen/launchroom/daemon/internal/policy"
	"github.com/vantezzen/launchroom/daemon/internal/secret"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// mysqlImage is the dashboard's pinned MySQL (config launchroom.database_engines).
const mysqlImage = "docker.io/library/mysql@sha256:24989179f58856db763f418010c1169fecc944e3ec1ea7e921572d83eb070f70" // 8.4

// pitrEnv is a backup engine wired like launchroomd's (volumes, runtime,
// policy floor allowing the official images) against versitygw.
type pitrEnv struct {
	*backupEnv
	eng   *backup.Engine
	creds backup.Credentials
	dest  state.BackupDestination
	logf  backup.Logf
}

func setupPITR(t *testing.T) *pitrEnv {
	t.Helper()
	e := setup(t)
	ctx := t.Context()
	b := &backupEnv{env: e, envs: map[state.WorkloadID]map[string]secret.Secret{}}
	s3wl := state.WorkloadID(randomID("wl"))
	s3id := b.run(ctx, t, s3wl, s3Image, map[string]secret.Secret{"ROOT_ACCESS_KEY": s3Access, "ROOT_SECRET_KEY": s3Secret}, []string{"posix", "/tmp"})
	c, err := e.engine.InspectContainer(ctx, s3id)
	if err != nil {
		t.Fatal(err)
	}
	var s3ip string
	for _, ip := range c.Networks {
		s3ip = ip.String()
	}
	bin := backup.LookupBinary("")
	if bin == "" || s3ip == "" {
		t.Fatal("restic or the s3 target is missing")
	}
	pol := policy.DefaultConfig()
	pol.AllowedRegistries = []string{"docker.io/library/", "docker.io/clickhouse/"}
	eng := backup.New(backup.Options{
		ResticBinary: bin, DataDir: t.TempDir(), InsecureHosts: []string{s3ip}, Docker: e.engine,
		WorkloadEnv: func(id state.WorkloadID) (map[string]secret.Secret, error) {
			if env, ok := b.envs[id]; ok {
				return env, nil
			}
			return nil, fmt.Errorf("unknown workload %s", id)
		},
		ServerID: string(e.serverID), Volumes: e.engine, Runtime: e.engine, Policy: pol,
		PollEvery: time.Second, VolumeIdleTimeout: time.Minute,
	})
	return &pitrEnv{
		backupEnv: b, eng: eng,
		creds: backup.Credentials{AccessKeyID: s3Access, SecretAccessKey: s3Secret, ResticPassword: "repo-password-pitr"},
		dest:  state.BackupDestination{Kind: state.DestinationS3, Endpoint: state.S3Endpoint("http://" + s3ip + ":7070"), Bucket: "launchroom-pitr", Prefix: "team-a"},
		logf:  func(format string, args ...any) { t.Logf("backup: "+format, args...) },
	}
}

// serviceVolume creates a managed service volume like the reconciler does.
func (p *pitrEnv) serviceVolume(ctx context.Context, t *testing.T) (state.VolumeID, string) {
	t.Helper()
	id := state.VolumeID(randomID("vol"))
	name := "lr-v_" + strings.ToLower(randomID("x")[2:14])
	labels := docker.ManagedLabels(string(p.serverID))
	labels[docker.LabelVolume] = string(id)
	labels[docker.LabelService] = randomID("svc")
	labels[docker.LabelEnvironment] = randomID("env")
	if err := p.engine.CreateVolume(ctx, docker.VolumeCreate{Name: name, Labels: labels}); err != nil {
		t.Fatal(err)
	}
	return id, name
}

const (
	pgMount   = "/var/lib/postgresql"
	pgData    = "/var/lib/postgresql/18/docker"
	pgArchive = "/var/lib/postgresql/launchroom-wal"
)

// archivingArgs are what the dashboard renders for a Postgres workload with
// point-in-time recovery (archive_timeout shortened for the test).
var archivingArgs = []string{"postgres", "-c", "archive_mode=on", "-c", "archive_library=basic_archive",
	"-c", "basic_archive.archive_directory=" + pgArchive, "-c", "archive_timeout=10"}

// runPostgres starts a Postgres workload container on a volume.
func (p *pitrEnv) runPostgres(ctx context.Context, t *testing.T, wl state.WorkloadID, volume, password string) string {
	t.Helper()
	if err := p.engine.PullImage(ctx, postgresImage); err != nil {
		t.Fatal(err)
	}
	env := map[string]secret.Secret{"POSTGRES_USER": "app", "POSTGRES_DB": "app", "POSTGRES_PASSWORD": secret.Secret(password)}
	labels := docker.ManagedLabels(string(p.serverID))
	labels[docker.LabelWorkload] = string(wl)
	id, err := fixtureEngine(t, p.serverID).CreateContainer(ctx, &docker.ContainerCreate{
		Name: docker.ContainerName(wl),
		Config: &container.Config{Image: postgresImage, Labels: labels, Cmd: archivingArgs,
			Env: []string{"POSTGRES_USER=app", "POSTGRES_DB=app", "POSTGRES_PASSWORD=" + password}},
		HostConfig: &container.HostConfig{Mounts: []mount.Mount{{Type: mount.TypeVolume, Source: volume, Target: pgMount}}},
	})
	if err != nil {
		t.Fatal(err)
	}
	if err := p.engine.StartContainer(ctx, id); err != nil {
		t.Fatal(err)
	}
	p.envs[wl] = env
	p.waitPG(ctx, t, id, password)
	return id
}

func (p *pitrEnv) waitPG(ctx context.Context, t *testing.T, id, password string) {
	t.Helper()
	deadline := time.Now().Add(2 * time.Minute)
	for {
		if _, code := p.exec(ctx, t, id, []string{"psql", "--host=127.0.0.1", "--username=app", "--dbname=app", "-tAc", "SELECT 1"}, "PGPASSWORD="+password); code == 0 {
			return
		}
		if time.Now().After(deadline) {
			t.Fatal("postgres did not become ready")
		}
		time.Sleep(time.Second)
	}
}

func (p *pitrEnv) sql(ctx context.Context, t *testing.T, id, password, q string) string {
	t.Helper()
	out, code := p.exec(ctx, t, id, []string{"psql", "--host=127.0.0.1", "--username=app", "--dbname=app", "-v", "ON_ERROR_STOP=1", "-XtAc", q}, "PGPASSWORD="+password)
	if code != 0 {
		t.Fatalf("%s: %s", q, out)
	}
	return out
}

func (p *pitrEnv) remove(ctx context.Context, t *testing.T, id string) {
	t.Helper()
	if err := p.engine.StopContainer(ctx, id, 30*time.Second); err != nil {
		t.Fatal(err)
	}
	if err := p.engine.RemoveContainer(ctx, id); err != nil {
		t.Fatal(err)
	}
}

func pgResources() state.Resources { return state.Resources{CPUs: 1, MemoryMB: 512, Pids: 512} }

// TestPointInTimeRecoveryRoundTrip: basic_archive archives WAL into the
// volume, the shipper stores it in restic (versitygw), a base backup is
// taken, and restores replay it — to the newest transaction into a new
// volume with other credentials, and to a point in time in place (with the
// new timeline's history archived). Verification then restores the base
// backup with the WAL, and a dump, into throwaway containers and checks them.
func TestPointInTimeRecoveryRoundTrip(t *testing.T) {
	p := setupPITR(t)
	ctx, cancel := context.WithTimeout(context.Background(), 20*time.Minute)
	defer cancel()

	volID, volName := p.serviceVolume(ctx, t)
	wl := state.WorkloadID(randomID("wl"))
	pgID := p.runPostgres(ctx, t, wl, volName, "pw-one-123456")
	p.sql(ctx, t, pgID, "pw-one-123456", "CREATE TABLE notes (id int primary key, body text); INSERT INTO notes VALUES (1, 'before the base backup');")

	svc := "service:" + strings.ToLower(string(wl))
	walTags := []state.BackupTag{"kind:wal", state.BackupTag(svc)}
	archiver := backup.NewWalArchiver(backup.WalArchiverOptions{
		Engine: p.eng, Docker: p.engine,
		Credentials: func(state.WorkloadID, *state.WalArchiveSpec) (backup.Credentials, error) { return p.creds, nil },
	})
	walSpec := &state.WalArchiveSpec{Directory: pgArchive, Destination: p.dest, Tags: walTags, Interval: "10s"}

	base, err := p.eng.Backup(ctx, &state.BackupSpec{Kind: state.BackupBaseBackup, Target: state.BackupTarget{Engine: state.EnginePostgres, Workload: wl},
		Destination: p.dest, Tags: []state.BackupTag{"kind:base_backup", state.BackupTag(svc)}}, p.creds, p.logf)
	if err != nil {
		t.Fatal(err)
	}
	if base.SizeBytes < 1<<20 {
		t.Fatalf("base backup %+v", base)
	}

	p.sql(ctx, t, pgID, "pw-one-123456", "INSERT INTO notes VALUES (2, 'after the base backup')")
	time.Sleep(1500 * time.Millisecond)
	pointText := p.sql(ctx, t, pgID, "pw-one-123456", "SELECT to_char(now() AT TIME ZONE 'UTC', 'YYYY-MM-DD\"T\"HH24:MI:SS.US\"Z\"')")
	point, err := time.Parse("2006-01-02T15:04:05.999999Z", pointText)
	if err != nil {
		t.Fatal(err)
	}
	time.Sleep(1500 * time.Millisecond)
	p.sql(ctx, t, pgID, "pw-one-123456", "INSERT INTO notes VALUES (3, 'after the point in time')")
	p.sql(ctx, t, pgID, "pw-one-123456", "SELECT pg_switch_wal()")

	// Ship until the segment with row 3 is stored and the directory is empty.
	var shipped int
	deadline := time.Now().Add(2 * time.Minute)
	for {
		res, err := archiver.Ship(ctx, wl, walSpec)
		if err != nil {
			t.Fatal(err)
		}
		shipped += res.Shipped
		if res.Shipped > 0 {
			t.Logf("shipped %d WAL file(s), %d bytes, newest %s", res.Shipped, res.Bytes, res.LastSegment)
		}
		if shipped > 0 && res.Pending == 0 && res.Shipped == 0 {
			break
		}
		if time.Now().After(deadline) {
			t.Fatalf("WAL not shipped (shipped %d, pending %d)", shipped, res.Pending)
		}
		time.Sleep(3 * time.Second)
	}
	if out, _ := p.exec(ctx, t, pgID, []string{"ls", "-1", pgArchive}); strings.TrimSpace(out) != "" {
		t.Fatalf("shipped files are still in the archive directory: %q", out)
	}
	walSnaps, err := p.eng.Snapshots(ctx, &state.BackupSnapshotsSpec{Destination: p.dest, Tags: walTags}, p.creds)
	if err != nil || len(walSnaps.Snapshots) == 0 {
		t.Fatalf("wal snapshots %+v %v", walSnaps, err)
	}

	runtimeFor := func(archive state.ContainerPath) *state.RestoreRuntime {
		return &state.RestoreRuntime{Image: postgresImage, MountPath: pgMount, DataDirectory: pgData, ArchiveDirectory: archive, Resources: pgResources()}
	}

	// 1. Newest transaction, into another volume, with that service's own password.
	copyVol, copyName := p.serviceVolume(ctx, t)
	latest, err := p.eng.RestorePointInTime(ctx, &state.RestoreSpec{
		Kind: state.BackupBaseBackup, Snapshot: base.SnapshotID, Target: state.BackupTarget{Engine: state.EnginePostgres, Volume: copyVol},
		Destination: p.dest, Mode: state.RestoreNewService, WalTags: walTags, Runtime: runtimeFor(""),
	}, p.creds, backup.PostgresTarget{User: "app", Password: "pw-two-123456", Database: "app"}, p.logf)
	if err != nil {
		t.Fatal(err)
	}
	if latest.RestoredTo == nil || latest.RestoredTo.Before(point) {
		t.Fatalf("restored to %v, want after %v", latest.RestoredTo, point)
	}
	copyWl := state.WorkloadID(randomID("wl"))
	copyID := p.runPostgres(ctx, t, copyWl, copyName, "pw-two-123456")
	if got := p.sql(ctx, t, copyID, "pw-two-123456", "SELECT string_agg(id::text, ',' ORDER BY id) FROM notes"); got != "1,2,3" {
		t.Fatalf("latest restore has rows %q, want 1,2,3", got)
	}
	p.remove(ctx, t, copyID)

	// 2. In place, to the point in time between rows 2 and 3. The database
	//    is held (its container is gone) while the volume is replaced.
	p.remove(ctx, t, pgID)
	pit, err := p.eng.RestorePointInTime(ctx, &state.RestoreSpec{
		Kind: state.BackupBaseBackup, Snapshot: base.SnapshotID, Target: state.BackupTarget{Engine: state.EnginePostgres, Volume: volID},
		Destination: p.dest, Mode: state.RestoreInPlace, PointInTime: &point, WalTags: walTags, Runtime: runtimeFor(pgArchive),
	}, p.creds, backup.PostgresTarget{User: "app", Password: "pw-one-123456", Database: "app"}, p.logf)
	if err != nil {
		t.Fatal(err)
	}
	if pit.RestoredTo == nil || pit.RestoredTo.After(point) {
		t.Fatalf("restored to %v, want at or before %v", pit.RestoredTo, point)
	}
	pgID = p.runPostgres(ctx, t, wl, volName, "pw-one-123456")
	if got := p.sql(ctx, t, pgID, "pw-one-123456", "SELECT string_agg(id::text, ',' ORDER BY id) FROM notes"); got != "1,2" {
		t.Fatalf("point-in-time restore has rows %q, want 1,2", got)
	}
	// The promotion's timeline history was archived and ships like any segment.
	out, _ := p.exec(ctx, t, pgID, []string{"ls", "-1", pgArchive})
	if !strings.Contains(out, "00000002.history") {
		t.Fatalf("archive directory after the restore: %q", out)
	}
	if res, err := archiver.Ship(ctx, wl, walSpec); err != nil || res.Shipped == 0 {
		t.Fatalf("shipping after the restore: %+v %v", res, err)
	}
	// No replay container or helper is left behind.
	if cs, _ := p.engine.ListContainers(ctx, map[string]string{docker.LabelHelper: "replay"}); len(cs) != 0 {
		t.Fatalf("%d replay containers left", len(cs))
	}

	// 3. Verification of the base backup: replayed with the WAL into a
	//    throwaway volume, then counted and amchecked.
	vres, err := p.eng.Verify(ctx, &state.VerifyRestoreSpec{
		Kind: state.BackupBaseBackup, Snapshot: base.SnapshotID, Destination: p.dest, Engine: state.EnginePostgres,
		Image: postgresImage, DataPath: pgMount, DataDirectory: pgData, WalTags: walTags, DatabaseUser: "app", DatabaseName: "app",
		Resources: pgResources(),
	}, p.creds, p.logf)
	if err != nil {
		t.Fatal(err)
	}
	if !vres.Passed || vres.Tables != 1 || vres.RestoredTo == nil {
		t.Fatalf("base backup verification %+v", vres)
	}

	// 4. Verification of a dump: pg_restore into a fresh throwaway Postgres.
	dump, err := p.eng.Backup(ctx, &state.BackupSpec{Kind: state.BackupDatabase, Target: state.BackupTarget{Engine: state.EnginePostgres, Workload: wl},
		Destination: p.dest, Tags: []state.BackupTag{"kind:database", state.BackupTag(svc)}}, p.creds, p.logf)
	if err != nil {
		t.Fatal(err)
	}
	dres, err := p.eng.Verify(ctx, &state.VerifyRestoreSpec{
		Kind: state.BackupDatabase, Snapshot: dump.SnapshotID, Destination: p.dest, Engine: state.EnginePostgres,
		Image: postgresImage, DataPath: pgMount, Resources: pgResources(),
	}, p.creds, p.logf)
	if err != nil {
		t.Fatal(err)
	}
	if !dres.Passed || dres.Tables != 1 || dres.Rows != 2 {
		t.Fatalf("dump verification %+v", dres)
	}
	for _, c := range dres.Checks {
		t.Logf("check %s: %v (%s)", c.Name, c.Passed, c.Detail)
	}
	if !slices.ContainsFunc(dres.Checks, func(c state.VerifyCheck) bool { return c.Name == state.VerifyCheckAmcheck && c.Passed }) {
		t.Fatal("pg_amcheck did not run")
	}
	// Throwaway containers and volumes are gone.
	if cs, _ := p.engine.ListContainers(ctx, map[string]string{docker.LabelHelper: "verify"}); len(cs) != 0 {
		t.Fatalf("%d verification containers left", len(cs))
	}
	vols, _ := p.engine.ListVolumes(ctx)
	for _, v := range vols {
		if v.Labels[docker.LabelHelper] == "verify" {
			t.Fatalf("verification volume %s left", v.Name)
		}
	}
}

// TestVerifyRedisMySQLAndVolume checks the RDB checker, a MySQL restore
// with mysqlcheck and a volume snapshot read.
func TestVerifyRedisMySQLAndVolume(t *testing.T) {
	p := setupPITR(t)
	ctx, cancel := context.WithTimeout(context.Background(), 20*time.Minute)
	defer cancel()

	// Redis.
	rwl := state.WorkloadID(randomID("wl"))
	rid := p.run(ctx, t, rwl, redisImage, map[string]secret.Secret{"REDIS_PASSWORD": "redis-pw-123456"}, []string{"redis-server", "--requirepass", "redis-pw-123456"})
	time.Sleep(2 * time.Second)
	for _, k := range []string{"a", "b", "c"} {
		if _, code := p.exec(ctx, t, rid, []string{"redis-cli", "SET", k, "1"}, "REDISCLI_AUTH=redis-pw-123456"); code != 0 {
			t.Fatal("redis SET failed")
		}
	}
	rb, err := p.eng.Backup(ctx, &state.BackupSpec{Kind: state.BackupDatabase, Target: state.BackupTarget{Engine: state.EngineRedis, Workload: rwl},
		Destination: p.dest, Tags: []state.BackupTag{"kind:database"}}, p.creds, p.logf)
	if err != nil {
		t.Fatal(err)
	}
	rres, err := p.eng.Verify(ctx, &state.VerifyRestoreSpec{Kind: state.BackupDatabase, Snapshot: rb.SnapshotID, Destination: p.dest, Engine: state.EngineRedis,
		Image: redisImage, DataPath: "/data", Resources: pgResources()}, p.creds, p.logf)
	if err != nil {
		t.Fatal(err)
	}
	if !rres.Passed || rres.Rows != 3 {
		t.Fatalf("redis verification %+v", rres)
	}

	// MySQL.
	if err := p.engine.PullImage(ctx, mysqlImage); err != nil {
		t.Fatal(err)
	}
	mwl := state.WorkloadID(randomID("wl"))
	menv := map[string]secret.Secret{"MYSQL_USER": "app", "MYSQL_PASSWORD": "mysql-pw-123456", "MYSQL_DATABASE": "app", "MYSQL_ROOT_PASSWORD": "root-pw-123456"}
	mid := p.run(ctx, t, mwl, mysqlImage, menv, nil)
	deadline := time.Now().Add(4 * time.Minute)
	for {
		if _, code := p.exec(ctx, t, mid, []string{"mysql", "--protocol=tcp", "--host=127.0.0.1", "--user=app", "app", "-e",
			"CREATE TABLE IF NOT EXISTS notes (id int primary key); INSERT IGNORE INTO notes VALUES (1), (2);"}, "MYSQL_PWD=mysql-pw-123456"); code == 0 {
			break
		}
		if time.Now().After(deadline) {
			t.Fatal("mysql did not become ready")
		}
		time.Sleep(2 * time.Second)
	}
	mb, err := p.eng.Backup(ctx, &state.BackupSpec{Kind: state.BackupDatabase, Target: state.BackupTarget{Engine: state.EngineMySQL, Workload: mwl},
		Destination: p.dest, Tags: []state.BackupTag{"kind:database"}}, p.creds, p.logf)
	if err != nil {
		t.Fatal(err)
	}
	mres, err := p.eng.Verify(ctx, &state.VerifyRestoreSpec{Kind: state.BackupDatabase, Snapshot: mb.SnapshotID, Destination: p.dest, Engine: state.EngineMySQL,
		Image: mysqlImage, DataPath: "/var/lib/mysql", Resources: state.Resources{CPUs: 1, MemoryMB: 1024, Pids: 512}}, p.creds, p.logf)
	if err != nil {
		t.Fatal(err)
	}
	if !mres.Passed || mres.Tables != 1 {
		t.Fatalf("mysql verification %+v", mres)
	}

	// Volume.
	volID, volName := p.serviceVolume(ctx, t)
	hid := volumeHelper(ctx, t, p.env, volName, false)
	if err := p.engine.StreamToContainer(ctx, hid, "/", tarOf(t, tarFile{name: "data/uploads/a.txt", content: "hello", mode: 0o644}, tarFile{name: "data/b.txt", content: "world!", mode: 0o644})); err != nil {
		t.Fatal(err)
	}
	vb, err := p.eng.Backup(ctx, &state.BackupSpec{Kind: state.BackupVolume, Target: state.BackupTarget{Volume: volID, Consistency: state.ConsistencyLive},
		Destination: p.dest, Tags: []state.BackupTag{"kind:volume"}}, p.creds, p.logf)
	if err != nil {
		t.Fatal(err)
	}
	vres, err := p.eng.Verify(ctx, &state.VerifyRestoreSpec{Kind: state.BackupVolume, Snapshot: vb.SnapshotID, Destination: p.dest, Resources: pgResources()}, p.creds, p.logf)
	if err != nil {
		t.Fatal(err)
	}
	if !vres.Passed || vres.Files != 2 || vres.Bytes != 11 {
		t.Fatalf("volume verification %+v", vres)
	}
}
