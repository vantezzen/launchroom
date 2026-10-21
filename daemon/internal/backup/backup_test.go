package backup

import (
	"bufio"
	"context"
	"encoding/json"
	"errors"
	"io"
	"os"
	"path/filepath"
	"slices"
	"strings"
	"sync"
	"testing"
	"time"

	"github.com/vantezzen/launchroom/daemon/internal/docker"
	"github.com/vantezzen/launchroom/daemon/internal/secret"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// The test binary doubles as a fake restic: with LR_FAKE_RESTIC_DIR set it
// reads <dir>/script.json (subcommand → exit/stdout/stderr), appends the
// invocation to <dir>/calls.jsonl and exits.
func TestMain(m *testing.M) {
	if dir := os.Getenv("LR_FAKE_RESTIC_DIR"); dir != "" {
		os.Exit(fakeRestic(dir))
	}
	os.Exit(m.Run())
}

type fakeStep struct {
	SleepMs int    `json:"sleepMs"`
	Exit    int    `json:"exit"`
	Stdout  string `json:"stdout"`
	Stderr  string `json:"stderr"`
}

type fakeCall struct {
	Args  []string `json:"args"`
	Env   []string `json:"env"`
	Stdin string   `json:"stdin"`
}

func fakeRestic(dir string) int {
	args := os.Args[1:]
	sub := ""
	for i := 0; i < len(args); i++ {
		if args[i] == "--repo" || args[i] == "--cache-dir" {
			i++
			continue
		}
		sub = args[i]
		break
	}
	if sub == "cat" {
		sub = "cat config"
	}
	var script map[string]fakeStep
	data, _ := os.ReadFile(filepath.Join(dir, "script.json"))
	_ = json.Unmarshal(data, &script)
	call := fakeCall{Args: args, Env: os.Environ()}
	if sub == "backup" {
		in, _ := io.ReadAll(os.Stdin)
		call.Stdin = string(in)
	}
	line, _ := json.Marshal(call)
	f, _ := os.OpenFile(filepath.Join(dir, "calls.jsonl"), os.O_APPEND|os.O_CREATE|os.O_WRONLY, 0o600)
	_, _ = f.Write(append(line, '\n'))
	_ = f.Close()
	step := script[sub]
	time.Sleep(time.Duration(step.SleepMs) * time.Millisecond)
	_, _ = os.Stdout.WriteString(step.Stdout)
	_, _ = os.Stderr.WriteString(step.Stderr)
	return step.Exit
}

// fakeDocker runs "dumps" by writing Output and "restores" by recording stdin.
type fakeDocker struct {
	mu       sync.Mutex
	Output   string
	Exit     int
	Stderr   string
	Stdin    string
	Argv     []string
	Env      []string
	NoRunner bool
}

func (f *fakeDocker) ListContainers(_ context.Context, labels map[string]string) ([]docker.Container, error) {
	if f.NoRunner {
		return nil, nil
	}
	return []docker.Container{{ID: "c1", Running: true, Labels: map[string]string{docker.LabelWorkload: labels[docker.LabelWorkload]}}}, nil
}

func (f *fakeDocker) ExecStream(_ context.Context, _ string, o docker.ExecOptions) (docker.ExecResult, error) {
	f.mu.Lock()
	f.Argv, f.Env = o.Argv, o.Env
	f.mu.Unlock()
	if o.Stdin != nil {
		in, _ := io.ReadAll(o.Stdin)
		f.mu.Lock()
		f.Stdin = string(in)
		f.mu.Unlock()
	}
	if o.Stdout != nil {
		_, _ = io.WriteString(o.Stdout, f.Output)
	}
	return docker.ExecResult{ExitCode: f.Exit, Stderr: f.Stderr}, nil
}

const (
	pgPassword  = "pg-secret-password-123"
	s3Secret    = "s3-secret-access-key-456"
	repoPass    = "restic-repo-password-789"
	snapshotID  = "4a1f0c2e9b8d7f6a5e4d3c2b1a0f9e8d7c6b5a4f3e2d1c0b9a8f7e6d5c4b3a2f"
	workloadID  = state.WorkloadID("wl_01J9Z8X7W6V5T4S3R2Q1P0N9MK")
	summaryLine = `{"message_type":"status","percent_done":0.5}
{"message_type":"summary","snapshot_id":"` + snapshotID + `","data_added":2048,"total_bytes_processed":8}
`
)

var creds = Credentials{AccessKeyID: "AKIAEXAMPLE1", SecretAccessKey: s3Secret, ResticPassword: repoPass}

var dest = state.BackupDestination{Kind: state.DestinationS3, Endpoint: "https://s3.example.com", Bucket: "backups", Prefix: "lr/team1"}

type harness struct {
	t   *testing.T
	dir string
	e   *Engine
	d   *fakeDocker
}

func newHarness(t *testing.T, script map[string]fakeStep) *harness {
	t.Helper()
	dir := t.TempDir()
	data, _ := json.Marshal(script)
	if err := os.WriteFile(filepath.Join(dir, "script.json"), data, 0o600); err != nil {
		t.Fatal(err)
	}
	d := &fakeDocker{Output: "DUMPDATA"}
	e := New(Options{
		ResticBinary: os.Args[0], DataDir: t.TempDir(), InsecureHosts: []string{"minio"}, Docker: d,
		WorkloadEnv: func(state.WorkloadID) (map[string]secret.Secret, error) {
			return map[string]secret.Secret{
				"POSTGRES_USER": "app", "POSTGRES_DB": "app", "POSTGRES_PASSWORD": pgPassword,
				"MYSQL_USER": "app", "MYSQL_DATABASE": "app", "MYSQL_PASSWORD": pgPassword, "REDIS_PASSWORD": pgPassword,
			}, nil
		},
	})
	e.testEnv = []string{"LR_FAKE_RESTIC_DIR=" + dir}
	return &harness{t: t, dir: dir, e: e, d: d}
}

func (h *harness) calls() []fakeCall {
	h.t.Helper()
	f, err := os.Open(filepath.Join(h.dir, "calls.jsonl"))
	if err != nil {
		return nil
	}
	defer func() { _ = f.Close() }()
	var out []fakeCall
	sc := bufio.NewScanner(f)
	sc.Buffer(make([]byte, 1<<20), 1<<20)
	for sc.Scan() {
		var c fakeCall
		_ = json.Unmarshal(sc.Bytes(), &c)
		out = append(out, c)
	}
	return out
}

func (h *harness) subcommands() []string {
	var out []string
	for _, c := range h.calls() {
		out = append(out, c.Args[4])
	}
	return out
}

func backupSpec() *state.BackupSpec {
	return &state.BackupSpec{
		Kind: state.BackupDatabase, Target: state.BackupTarget{Engine: state.EnginePostgres, Workload: workloadID},
		Destination: dest, Tags: []state.BackupTag{"kind:database", "service:svc_01J9Z8X7W6V5T4S3R2Q1P0N9MK"},
	}
}

func assertNoSecrets(t *testing.T, s string) {
	t.Helper()
	for _, v := range []string{pgPassword, s3Secret, repoPass} {
		if strings.Contains(s, v) {
			t.Fatalf("secret leaked into %q", s)
		}
	}
}

func TestBackupStreamsDumpIntoRestic(t *testing.T) {
	t.Parallel()
	h := newHarness(t, map[string]fakeStep{"backup": {Stdout: summaryLine}})
	var logs []string
	res, err := h.e.Backup(context.Background(), backupSpec(), creds, func(f string, a ...any) { logs = append(logs, f) })
	if err != nil {
		t.Fatal(err)
	}
	if string(res.SnapshotID) != snapshotID || res.SizeBytes != 8 || res.AddedBytes != 2048 {
		t.Fatalf("result = %+v", res)
	}
	calls := h.calls()
	if got := h.subcommands(); !slices.Equal(got, []string{"cat", "backup"}) {
		t.Fatalf("restic calls = %v", got)
	}
	b := calls[1]
	if b.Stdin != "DUMPDATA" {
		t.Fatalf("restic stdin = %q", b.Stdin)
	}
	if b.Args[1] != "s3:https://s3.example.com/backups/lr/team1" {
		t.Fatalf("repo = %q", b.Args[1])
	}
	joined := strings.Join(b.Args, " ")
	for _, want := range []string{"--stdin --stdin-filename postgres.dump", "--host launchroom", "--tag kind:database", "--tag service:svc_01J9Z8X7W6V5T4S3R2Q1P0N9MK", "--json"} {
		if !strings.Contains(joined, want) {
			t.Errorf("restic argv %q lacks %q", joined, want)
		}
	}
	assertNoSecrets(t, joined)
	for _, want := range []string{"AWS_SECRET_ACCESS_KEY=" + s3Secret, "RESTIC_PASSWORD=" + repoPass, "AWS_ACCESS_KEY_ID=AKIAEXAMPLE1", "AWS_DEFAULT_REGION=us-east-1"} {
		if !slices.Contains(b.Env, want) {
			t.Errorf("restic env lacks %s", strings.SplitN(want, "=", 2)[0])
		}
	}
	for _, kv := range b.Env {
		if strings.HasPrefix(kv, "PATH=") || strings.HasPrefix(kv, "PGPASSWORD=") {
			t.Errorf("restic env has %s", strings.SplitN(kv, "=", 2)[0])
		}
	}
	if !slices.Equal(h.d.Argv, []string{"pg_dump", "--format=custom", "--no-owner", "--no-privileges", "--host=127.0.0.1", "--username=app", "--dbname=app"}) {
		t.Fatalf("dump argv = %v", h.d.Argv)
	}
	if !slices.Equal(h.d.Env, []string{"PGPASSWORD=" + pgPassword}) {
		t.Fatal("dump env does not carry the password")
	}
	assertNoSecrets(t, strings.Join(logs, "\n"))
}

func TestBackupFailsWhenDumpFailsEvenIfResticSucceeded(t *testing.T) {
	t.Parallel()
	h := newHarness(t, map[string]fakeStep{"backup": {Stdout: summaryLine}})
	h.d.Exit = 1
	h.d.Output = "PARTIAL"
	h.d.Stderr = "pg_dump: error: connection failed for password " + pgPassword
	_, err := h.e.Backup(context.Background(), backupSpec(), creds, nil)
	if err == nil || !strings.Contains(err.Error(), "pg_dump exited with code 1") {
		t.Fatalf("err = %v", err)
	}
	assertNoSecrets(t, err.Error())
	calls := h.calls()
	if got := h.subcommands(); !slices.Equal(got, []string{"cat", "backup", "forget"}) {
		t.Fatalf("restic calls = %v (the bogus snapshot must be forgotten)", got)
	}
	if last := calls[2].Args; last[len(last)-1] != snapshotID {
		t.Fatalf("forget args = %v", last)
	}
}

func TestBackupResticFailure(t *testing.T) {
	t.Parallel()
	for name, tc := range map[string]struct {
		step fakeStep
		want string
	}{
		"generic":        {fakeStep{Exit: 1, Stderr: "Fatal: unable to save snapshot: " + s3Secret}, "restic backup failed (exit 1)"},
		"wrong password": {fakeStep{Exit: 12, Stderr: "wrong password"}, "wrong repository password"},
		"locked":         {fakeStep{Exit: 11}, "locked by another operation"},
	} {
		t.Run(name, func(t *testing.T) {
			h := newHarness(t, map[string]fakeStep{"backup": tc.step})
			_, err := h.e.Backup(context.Background(), backupSpec(), creds, nil)
			if err == nil || !strings.Contains(err.Error(), tc.want) {
				t.Fatalf("err = %v, want %q", err, tc.want)
			}
			assertNoSecrets(t, err.Error())
		})
	}
}

func TestBackupInitialisesMissingRepository(t *testing.T) {
	t.Parallel()
	h := newHarness(t, map[string]fakeStep{"cat config": {Exit: 10}, "backup": {Stdout: summaryLine}})
	if _, err := h.e.Backup(context.Background(), backupSpec(), creds, nil); err != nil {
		t.Fatal(err)
	}
	if got := h.subcommands(); !slices.Equal(got, []string{"cat", "init", "backup"}) {
		t.Fatalf("restic calls = %v", got)
	}
}

func TestBackupRefusesUnsupported(t *testing.T) {
	t.Parallel()
	h := newHarness(t, nil)
	cases := map[string]*state.BackupSpec{}
	vol := backupSpec()
	vol.Kind, vol.Target = state.BackupVolume, state.BackupTarget{Volume: "vol_01J9Z8X7W6V5T4S3R2Q1P0N9MK"}
	cases["volume"] = vol
	cp := backupSpec()
	cp.Kind, cp.Target = state.BackupControlPlane, state.BackupTarget{}
	cases["control plane"] = cp
	// MongoDB and ClickHouse are supported since D47 (engines_test.go);
	// a base backup exists only for Postgres.
	base := backupSpec()
	base.Kind, base.Target.Engine = state.BackupBaseBackup, state.EngineMySQL
	cases["mysql base backup"] = base
	for name, spec := range cases {
		if _, err := h.e.Backup(context.Background(), spec, creds, nil); !errors.Is(err, ErrUnsupported) {
			t.Errorf("%s: err = %v", name, err)
		}
	}
	if len(h.calls()) != 0 {
		t.Fatal("restic ran for an unsupported backup")
	}
}

func TestInsecureEndpoints(t *testing.T) {
	t.Parallel()
	h := newHarness(t, map[string]fakeStep{"backup": {Stdout: summaryLine}})
	spec := backupSpec()
	spec.Destination.Endpoint = "http://s3.example.com"
	if _, err := h.e.Backup(context.Background(), spec, creds, nil); err == nil || !strings.Contains(err.Error(), "LR_BACKUP_INSECURE_HOSTS") {
		t.Fatalf("err = %v", err)
	}
	spec.Destination.Endpoint = "http://minio:9000"
	spec.Destination.Prefix = ""
	if _, err := h.e.Backup(context.Background(), spec, creds, nil); err != nil {
		t.Fatal(err)
	}
	if repo := h.calls()[0].Args[1]; repo != "s3:http://minio:9000/backups" {
		t.Fatalf("repo = %q", repo)
	}
}

func TestEngineArgv(t *testing.T) {
	t.Parallel()
	env := map[string]secret.Secret{"MYSQL_USER": "app", "MYSQL_DATABASE": "shop", "MYSQL_PASSWORD": "pw-123456", "REDIS_PASSWORD": "rpw-123456"}
	my, _ := engineFor(state.EngineMySQL)
	c, err := my.dump(env)
	if err != nil || c.argv[0] != "mysqldump" || c.argv[len(c.argv)-1] != "shop" || !slices.Equal(c.env, []string{"MYSQL_PWD=pw-123456"}) {
		t.Fatalf("mysql dump = %+v, %v", c, err)
	}
	vk, _ := engineFor(state.EngineValkey)
	c, err = vk.dump(env)
	if err != nil || !slices.Equal(c.argv, []string{"valkey-cli", "--rdb", "-"}) || !slices.Equal(c.env, []string{"REDISCLI_AUTH=rpw-123456"}) {
		t.Fatalf("valkey dump = %+v, %v", c, err)
	}
	if _, err := vk.restore(env); !errors.Is(err, ErrUnsupported) {
		t.Fatalf("valkey restore err = %v", err)
	}
	bad := map[string]secret.Secret{"POSTGRES_USER": "app; DROP", "POSTGRES_PASSWORD": "x"}
	if _, err := postgres.dump(bad); err == nil || strings.Contains(err.Error(), "DROP") {
		t.Fatalf("bad identifier err = %v", err)
	}
	if _, err := postgres.dump(map[string]secret.Secret{"POSTGRES_USER": "-o=x"}); err == nil {
		t.Fatal("option-looking identifier accepted")
	}
	for _, en := range []state.DatabaseEngine{state.EnginePostgres, state.EngineMySQL, state.EngineRedis, state.EngineValkey, state.EngineMariaDB} {
		f, err := SnapshotFile(en)
		if err != nil || f == "" {
			t.Errorf("%s: %q %v", en, f, err)
		}
	}
}

func restoreSpec() *state.RestoreSpec {
	return &state.RestoreSpec{
		Kind: state.BackupDatabase, Snapshot: state.SnapshotID(snapshotID[:8]), Mode: state.RestoreNewService,
		Target: state.BackupTarget{Engine: state.EnginePostgres, Workload: workloadID}, Destination: dest,
	}
}

func TestRestoreFeedsResticDumpIntoRestoreTool(t *testing.T) {
	t.Parallel()
	snaps := `[{"id":"` + snapshotID + `","time":"2026-10-17T03:00:00Z","paths":["/postgres.dump"],"tags":["kind:database"]}]`
	h := newHarness(t, map[string]fakeStep{"snapshots": {Stdout: snaps}, "dump": {Stdout: "RESTOREDATA"}})
	res, err := h.e.Restore(context.Background(), restoreSpec(), creds, nil)
	if err != nil {
		t.Fatal(err)
	}
	if res.SizeBytes != int64(len("RESTOREDATA")) || h.d.Stdin != "RESTOREDATA" {
		t.Fatalf("res = %+v, stdin %q", res, h.d.Stdin)
	}
	if h.d.Argv[0] != "pg_restore" || !slices.Contains(h.d.Argv, "--single-transaction") || !slices.Contains(h.d.Argv, "--dbname=app") {
		t.Fatalf("restore argv = %v", h.d.Argv)
	}
	if d := h.calls()[2].Args; d[4] != "dump" || d[5] != snapshotID || d[6] != "/postgres.dump" {
		t.Fatalf("dump args = %v", d)
	}
}

func TestRestoreRefusesForeignSnapshotsAndFailures(t *testing.T) {
	t.Parallel()
	mysqlSnap := `[{"id":"` + snapshotID + `","time":"2026-10-17T03:00:00Z","paths":["/mysql.sql"]}]`
	h := newHarness(t, map[string]fakeStep{"snapshots": {Stdout: mysqlSnap}})
	if _, err := h.e.Restore(context.Background(), restoreSpec(), creds, nil); err == nil || !strings.Contains(err.Error(), "does not hold a postgres dump") {
		t.Fatalf("err = %v", err)
	}
	h = newHarness(t, map[string]fakeStep{"snapshots": {Stdout: "[]"}})
	if _, err := h.e.Restore(context.Background(), restoreSpec(), creds, nil); err == nil || !strings.Contains(err.Error(), "not found") {
		t.Fatalf("err = %v", err)
	}
	ok := `[{"id":"` + snapshotID + `","time":"2026-10-17T03:00:00Z","paths":["/postgres.dump"]}]`
	h = newHarness(t, map[string]fakeStep{"snapshots": {Stdout: ok}, "dump": {Stdout: "X"}})
	h.d.Exit, h.d.Stderr = 1, "pg_restore: error: password "+pgPassword
	_, err := h.e.Restore(context.Background(), restoreSpec(), creds, nil)
	if err == nil || !strings.Contains(err.Error(), "pg_restore exited with code 1") {
		t.Fatalf("err = %v", err)
	}
	assertNoSecrets(t, err.Error())
	h = newHarness(t, map[string]fakeStep{"snapshots": {Stdout: ok}, "dump": {Exit: 1, Stdout: "PART"}})
	if _, err := h.e.Restore(context.Background(), restoreSpec(), creds, nil); err == nil || !strings.Contains(err.Error(), "restic dump failed") {
		t.Fatalf("err = %v", err)
	}
}

func TestSnapshotsNewestFirstAndFiltered(t *testing.T) {
	t.Parallel()
	out := `[{"id":"` + strings.Repeat("a", 64) + `","time":"2026-10-16T03:00:00Z","paths":["/postgres.dump"],"tags":["kind:database","not a tag"],"summary":{"total_bytes_processed":10}},` +
		`{"id":"` + strings.Repeat("b", 64) + `","time":"2026-10-17T03:00:00Z","paths":["/postgres.dump"],"tags":["kind:database"]}]`
	h := newHarness(t, map[string]fakeStep{"snapshots": {Stdout: out}})
	res, err := h.e.Snapshots(context.Background(), &state.BackupSnapshotsSpec{Destination: dest, Tags: []state.BackupTag{"kind:database", "service:svc_1"}}, creds)
	if err != nil {
		t.Fatal(err)
	}
	if len(res.Snapshots) != 2 || res.Snapshots[0].ID[0] != 'b' || res.Snapshots[1].SizeBytes != 10 || !slices.Equal(res.Snapshots[1].Tags, []state.BackupTag{"kind:database"}) {
		t.Fatalf("res = %+v", res)
	}
	if args := strings.Join(h.calls()[1].Args, " "); !strings.Contains(args, "--tag kind:database,service:svc_1") {
		t.Fatalf("args = %s", args)
	}
	var c state.TaskCompletion
	c.TypeMeta, c.Attempt, c.Outcome, c.Snapshots = state.NewTypeMeta(state.KindTaskCompletion), 1, state.OutcomeSucceeded, res
	if err := c.Validate(); err != nil {
		t.Fatal(err)
	}
	h = newHarness(t, map[string]fakeStep{"cat config": {Exit: 10}})
	res, err = h.e.Snapshots(context.Background(), &state.BackupSnapshotsSpec{Destination: dest}, creds)
	if err != nil || res.Snapshots == nil || len(res.Snapshots) != 0 {
		t.Fatalf("missing repo: %+v %v", res, err)
	}
}

func TestMaintenance(t *testing.T) {
	forget := `[{"tags":null,"host":"","paths":null,"keep":[{}],"remove":[{},{}]}]`
	h := newHarness(t, map[string]fakeStep{"forget": {Stdout: forget}})
	spec := &state.BackupMaintenanceSpec{Operation: state.MaintenanceForget, Destination: dest, Retention: &state.Retention{KeepDaily: 7, KeepWeekly: 4}, Tags: []state.BackupTag{"service:svc_1"}}
	res, err := h.e.Maintenance(context.Background(), spec, creds, nil)
	if err != nil || res.RemovedSnapshots != 2 {
		t.Fatalf("res = %+v, %v", res, err)
	}
	if got := h.subcommands(); !slices.Equal(got, []string{"cat", "forget", "prune"}) {
		t.Fatalf("calls = %v", got)
	}
	args := h.calls()[1].Args
	joined := strings.Join(args, " ")
	if !slices.Contains(args, "--group-by") || !strings.Contains(joined, "--tag service:svc_1") || !strings.Contains(joined, "--keep-daily 7") || !strings.Contains(joined, "--keep-weekly 4") {
		t.Fatalf("forget args = %v", args)
	}
	if i := slices.Index(args, "--group-by"); args[i+1] != "" {
		t.Fatalf("group-by = %q", args[i+1])
	}
	spec.Tags = nil
	if _, err := h.e.Maintenance(context.Background(), spec, creds, nil); err == nil {
		t.Fatal("forget without tags accepted")
	}
	h = newHarness(t, nil)
	if _, err := h.e.Maintenance(context.Background(), &state.BackupMaintenanceSpec{Operation: state.MaintenanceCheck, Destination: dest, ReadDataSubsetPercent: 5}, creds, nil); err != nil {
		t.Fatal(err)
	}
	if args := h.calls()[1].Args; args[len(args)-1] != "--read-data-subset=5%" {
		t.Fatalf("check args = %v", args)
	}
}

func TestCredentialsFromEnv(t *testing.T) {
	t.Parallel()
	full := map[string]secret.Secret{EnvAccessKeyID: "a", EnvSecretAccessKey: "b", EnvResticPassword: "c"}
	if _, err := CredentialsFromEnv(state.DestinationS3, full); err != nil {
		t.Fatal(err)
	}
	extra := map[string]secret.Secret{EnvAccessKeyID: "a", EnvSecretAccessKey: "b", EnvResticPassword: "c", "RESTIC_REPOSITORY": "x"}
	if _, err := CredentialsFromEnv(state.DestinationS3, extra); err == nil {
		t.Fatal("extra name accepted")
	}
	if _, err := CredentialsFromEnv(state.DestinationLocal, full); err == nil {
		t.Fatal("s3 creds accepted for local")
	}
	if _, err := CredentialsFromEnv(state.DestinationLocal, map[string]secret.Secret{EnvResticPassword: "c"}); err != nil {
		t.Fatal(err)
	}
	_, err := CredentialsFromEnv(state.DestinationS3, map[string]secret.Secret{EnvAccessKeyID: "secretvalue1", EnvSecretAccessKey: "", EnvResticPassword: "c"})
	if err == nil || strings.Contains(err.Error(), "secretvalue1") {
		t.Fatalf("err = %v", err)
	}
}

func TestMissingResticBinary(t *testing.T) {
	t.Parallel()
	e := New(Options{DataDir: t.TempDir(), Docker: &fakeDocker{}, WorkloadEnv: func(state.WorkloadID) (map[string]secret.Secret, error) {
		return map[string]secret.Secret{"POSTGRES_PASSWORD": "x"}, nil
	}})
	if _, err := e.Backup(context.Background(), backupSpec(), creds, nil); err == nil || !strings.Contains(err.Error(), "restic is not installed") {
		t.Fatalf("err = %v", err)
	}
}

func TestMissingBucketProbeTimesOutThenInits(t *testing.T) {
	old := probeTimeout
	probeTimeout = 300 * time.Millisecond
	t.Cleanup(func() { probeTimeout = old })
	h := newHarness(t, map[string]fakeStep{"cat config": {SleepMs: 5000}, "backup": {Stdout: summaryLine}})
	if _, err := h.e.Backup(context.Background(), backupSpec(), creds, nil); err != nil {
		t.Fatal(err)
	}
	if got := h.subcommands(); !slices.Equal(got, []string{"cat", "init", "backup"}) {
		t.Fatalf("restic calls = %v", got)
	}
}
