//go:build integration

package integration

import (
	"bytes"
	"context"
	"fmt"
	"math/rand/v2"
	"net/netip"
	"strings"
	"testing"
	"time"

	"github.com/moby/moby/api/types/container"

	"github.com/vantezzen/launchroom/daemon/internal/backup"
	"github.com/vantezzen/launchroom/daemon/internal/docker"
	"github.com/vantezzen/launchroom/daemon/internal/policy"
	"github.com/vantezzen/launchroom/daemon/internal/secret"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// Pinned images for the backup test. MinIO's public images are no longer
// published, so the S3 target is versitygw (Apache-2.0, posix backend).
const (
	s3Image       = "docker.io/versity/versitygw@sha256:eb7f786fcd91e72194e48fcf1ac16302d686bf8e3a71434bdbc067a6efd3fdc7" // v1.9.0
	postgresImage = "docker.io/library/postgres@sha256:77f585114c32fbca283dc835b0596f4e52b51b4c6662d7810b2f4084f60a1873"  // 18-alpine (dashboard config)
	redisImage    = "docker.io/library/redis@sha256:858f009f9709ce576febc734aa78b8f6d624b82571f9ddb6bda4377c833b3499"     // 7-alpine (dashboard config)
	s3Access      = "itestaccess"
	s3Secret      = "itest-secret-key-123"
)

type backupEnv struct {
	*env
	envs map[state.WorkloadID]map[string]secret.Secret
	// network is the managed environment network the helpers run on
	// (dockerd-guard refuses workloads on Docker's default bridge).
	network string
}

// ensureNetwork creates the env's managed network once.
func (b *backupEnv) ensureNetwork(ctx context.Context, t *testing.T) string {
	t.Helper()
	if b.network != "" {
		return b.network
	}
	labels := docker.ManagedLabels(string(b.serverID))
	labels[docker.LabelNetwork] = randomID("net")
	name := "lr-e_" + strings.ToLower(randomID("x")[2:18])
	sub := netip.MustParsePrefix(fmt.Sprintf("10.231.%d.0/24", 100+rand.IntN(100)))
	if _, err := b.engine.CreateNetwork(ctx, docker.NetworkCreate{Name: name, Subnet: sub, Labels: labels}); err != nil {
		t.Fatal(err)
	}
	b.network = name
	return name
}

// run starts a managed container (labelled like a workload) and returns its id.
func (b *backupEnv) run(ctx context.Context, t *testing.T, wl state.WorkloadID, image string, env map[string]secret.Secret, cmd []string) string {
	t.Helper()
	if err := b.engine.PullImage(ctx, image); err != nil {
		t.Fatalf("pull %s: %v", image, err)
	}
	labels := docker.ManagedLabels(string(b.serverID))
	labels[docker.LabelWorkload] = string(wl)
	stopTimeout, noInit, pids := 60, false, int64(1024)
	var kv []string
	for k, v := range env {
		kv = append(kv, k+"="+v.Reveal())
	}
	id, err := b.engine.CreateContainer(ctx, &docker.ContainerCreate{
		Name:   docker.ContainerName(wl),
		Config: &container.Config{Image: image, Env: kv, Labels: labels, Cmd: cmd, StopSignal: "SIGTERM", StopTimeout: &stopTimeout},
		// Hardened like a database workload (the dind's dockerd-guard and
		// launchroomd's policy floor refuse anything less): no init, typed stop.
		HostConfig: &container.HostConfig{
			CapDrop: []string{"ALL"}, CapAdd: []string{"CHOWN", "SETUID", "SETGID", "DAC_OVERRIDE", "FOWNER"},
			SecurityOpt: []string{policy.NoNewPrivileges}, Init: &noInit, NetworkMode: container.NetworkMode(b.ensureNetwork(ctx, t)),
			LogConfig: container.LogConfig{Type: policy.LogDriver, Config: map[string]string{"max-size": policy.LogMaxSize, "max-file": policy.LogMaxFile}},
			Resources: container.Resources{Memory: 1 << 30, NanoCPUs: 2e9, PidsLimit: &pids},
		},
	})
	if err != nil {
		t.Fatal(err)
	}
	if err := b.engine.StartContainer(ctx, id); err != nil {
		t.Fatal(err)
	}
	b.envs[wl] = env
	return id
}

func (b *backupEnv) exec(ctx context.Context, t *testing.T, id string, argv []string, env ...string) (string, int) {
	t.Helper()
	var out bytes.Buffer
	res, err := b.engine.ExecStream(ctx, id, docker.ExecOptions{Argv: argv, Env: env, Stdout: &out})
	if err != nil {
		t.Fatalf("exec %v: %v", argv, err)
	}
	return strings.TrimSpace(out.String()), res.ExitCode
}

func (b *backupEnv) waitPostgres(ctx context.Context, t *testing.T, id string) {
	t.Helper()
	deadline := time.Now().Add(2 * time.Minute)
	for {
		// pg_isready over TCP only succeeds once the final server (not the init one) runs.
		if _, code := b.exec(ctx, t, id, []string{"pg_isready", "--host=127.0.0.1", "--username=app", "--dbname=app"}); code == 0 {
			if _, code := b.exec(ctx, t, id, []string{"psql", "--host=127.0.0.1", "--username=app", "--dbname=app", "-tAc", "SELECT 1"}, "PGPASSWORD=pw-one-123456"); code == 0 {
				return
			}
		}
		if time.Now().After(deadline) {
			t.Fatal("postgres did not become ready")
		}
		time.Sleep(time.Second)
	}
}

func TestBackupAndRestorePostgresThroughRestic(t *testing.T) {
	e := setup(t)
	b := &backupEnv{env: e, envs: map[state.WorkloadID]map[string]secret.Secret{}}
	ctx, cancel := context.WithTimeout(context.Background(), 15*time.Minute)
	defer cancel()

	// S3 target (versitygw on the default bridge, reached by container IP over plain http).
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
	if s3ip == "" {
		t.Fatal("s3 container has no IP")
	}

	pgEnv := map[string]secret.Secret{"POSTGRES_USER": "app", "POSTGRES_DB": "app", "POSTGRES_PASSWORD": "pw-one-123456"}
	src, dst := state.WorkloadID(randomID("wl")), state.WorkloadID(randomID("wl"))
	srcID := b.run(ctx, t, src, postgresImage, pgEnv, nil)
	dstID := b.run(ctx, t, dst, postgresImage, pgEnv, nil)
	b.waitPostgres(ctx, t, srcID)
	b.waitPostgres(ctx, t, dstID)
	if out, code := b.exec(ctx, t, srcID, []string{"psql", "--host=127.0.0.1", "--username=app", "--dbname=app", "-v", "ON_ERROR_STOP=1",
		"-c", "CREATE TABLE notes (id int primary key, body text); INSERT INTO notes VALUES (1, 'restored from restic');"}, "PGPASSWORD=pw-one-123456"); code != 0 {
		t.Fatalf("seed failed: %s", out)
	}

	bin := backup.LookupBinary("")
	if bin == "" {
		t.Fatal("restic not found next to the test binary (make test-daemon-integration fetches it)")
	}
	eng := backup.New(backup.Options{
		ResticBinary: bin, DataDir: t.TempDir(), InsecureHosts: []string{s3ip}, Docker: e.engine,
		WorkloadEnv: func(id state.WorkloadID) (map[string]secret.Secret, error) {
			if env, ok := b.envs[id]; ok {
				return env, nil
			}
			return nil, fmt.Errorf("unknown workload %s", id)
		},
	})
	creds := backup.Credentials{AccessKeyID: s3Access, SecretAccessKey: s3Secret, ResticPassword: "repo-password-123"}
	dest := state.BackupDestination{Kind: state.DestinationS3, Endpoint: state.S3Endpoint("http://" + s3ip + ":7070"), Bucket: "launchroom-itest", Prefix: "team-a"}
	tags := []state.BackupTag{"kind:database", state.BackupTag("service:" + strings.ToLower(string(src)))}
	logf := func(format string, args ...any) { t.Logf("backup: "+format, args...) }

	// The bucket does not exist yet: the bounded probe falls through to init.
	res, err := eng.Backup(ctx, &state.BackupSpec{Kind: state.BackupDatabase, Target: state.BackupTarget{Engine: state.EnginePostgres, Workload: src}, Destination: dest, Tags: tags}, creds, logf)
	if err != nil {
		t.Fatal(err)
	}
	if res.SizeBytes < 100 || res.SnapshotID.Validate() != nil {
		t.Fatalf("backup result %+v", res)
	}

	list, err := eng.Snapshots(ctx, &state.BackupSnapshotsSpec{Destination: dest, Tags: tags}, creds)
	if err != nil {
		t.Fatal(err)
	}
	if len(list.Snapshots) != 1 || list.Snapshots[0].ID != res.SnapshotID || len(list.Snapshots[0].Tags) != 2 {
		t.Fatalf("snapshots %+v", list)
	}

	// A failing dump (missing database; loopback connections are trusted in
	// the official image, so a wrong password would not fail) fails the
	// backup even though restic stored the empty stream, and leaves no snapshot.
	b.envs[src] = map[string]secret.Secret{"POSTGRES_USER": "app", "POSTGRES_DB": "nosuchdb", "POSTGRES_PASSWORD": "wrong-password-999"}
	_, err = eng.Backup(ctx, &state.BackupSpec{Kind: state.BackupDatabase, Target: state.BackupTarget{Engine: state.EnginePostgres, Workload: src}, Destination: dest, Tags: tags}, creds, logf)
	if err == nil || !strings.Contains(err.Error(), "pg_dump exited") || strings.Contains(err.Error(), "wrong-password-999") {
		t.Fatalf("failed dump: %v", err)
	}
	b.envs[src] = pgEnv
	if list, err = eng.Snapshots(ctx, &state.BackupSnapshotsSpec{Destination: dest, Tags: tags}, creds); err != nil || len(list.Snapshots) != 1 {
		t.Fatalf("after failed dump: %+v %v", list, err)
	}

	// Restore into the second (empty) database and read the row back.
	rres, err := eng.Restore(ctx, &state.RestoreSpec{Kind: state.BackupDatabase, Snapshot: res.SnapshotID, Mode: state.RestoreNewService,
		Target: state.BackupTarget{Engine: state.EnginePostgres, Workload: dst}, Destination: dest}, creds, logf)
	if err != nil {
		t.Fatal(err)
	}
	if rres.SizeBytes != res.SizeBytes {
		t.Fatalf("restored %d bytes, backed up %d", rres.SizeBytes, res.SizeBytes)
	}
	out, code := b.exec(ctx, t, dstID, []string{"psql", "--host=127.0.0.1", "--username=app", "--dbname=app", "-tAc", "SELECT body FROM notes WHERE id = 1"}, "PGPASSWORD=pw-one-123456")
	if code != 0 || out != "restored from restic" {
		t.Fatalf("restored row = %q (exit %d)", out, code)
	}
	// Restoring again (in place: --clean --if-exists) is idempotent.
	if _, err := eng.Restore(ctx, &state.RestoreSpec{Kind: state.BackupDatabase, Snapshot: res.SnapshotID, Mode: state.RestoreInPlace,
		Target: state.BackupTarget{Engine: state.EnginePostgres, Workload: dst}, Destination: dest}, creds, logf); err != nil {
		t.Fatalf("second restore: %v", err)
	}

	// Redis: `redis-cli --rdb -` streams an RDB to stdout.
	rwl := state.WorkloadID(randomID("wl"))
	b.run(ctx, t, rwl, redisImage, map[string]secret.Secret{"REDIS_PASSWORD": "redis-pw-123456"}, []string{"redis-server", "--requirepass", "redis-pw-123456"})
	time.Sleep(2 * time.Second)
	rtags := []state.BackupTag{"kind:database", state.BackupTag("service:" + strings.ToLower(string(rwl)))}
	rb, err := eng.Backup(ctx, &state.BackupSpec{Kind: state.BackupDatabase, Target: state.BackupTarget{Engine: state.EngineRedis, Workload: rwl}, Destination: dest, Tags: rtags}, creds, logf)
	if err != nil {
		t.Fatal(err)
	}
	if rb.SizeBytes < 9 {
		t.Fatalf("redis backup %+v", rb)
	}

	// Retention is scoped by tags: forgetting redis snapshots never touches postgres ones.
	if _, err := eng.Maintenance(ctx, &state.BackupMaintenanceSpec{Operation: state.MaintenanceForget, Destination: dest, Retention: &state.Retention{KeepLast: 1}, Tags: rtags}, creds, logf); err != nil {
		t.Fatal(err)
	}
	if _, err := eng.Maintenance(ctx, &state.BackupMaintenanceSpec{Operation: state.MaintenanceCheck, Destination: dest}, creds, logf); err != nil {
		t.Fatal(err)
	}
	all, err := eng.Snapshots(ctx, &state.BackupSnapshotsSpec{Destination: dest, Tags: []state.BackupTag{"kind:database"}}, creds)
	if err != nil || len(all.Snapshots) != 2 {
		t.Fatalf("all snapshots: %+v %v", all, err)
	}

	// Wrong repository password is reported clearly and without secrets.
	_, err = eng.Snapshots(ctx, &state.BackupSnapshotsSpec{Destination: dest, Tags: tags}, backup.Credentials{AccessKeyID: s3Access, SecretAccessKey: s3Secret, ResticPassword: "not-the-password"})
	if err == nil || !strings.Contains(err.Error(), "wrong repository password") || strings.Contains(err.Error(), s3Secret) {
		t.Fatalf("wrong password: %v", err)
	}
}
