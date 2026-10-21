//go:build integration

package integration

import (
	"context"
	"crypto/rand"
	"log/slog"
	"os"
	"strings"
	"testing"
	"time"

	"github.com/moby/moby/client"

	"github.com/vantezzen/launchroom/daemon/internal/docker"
	"github.com/vantezzen/launchroom/daemon/internal/health"
	"github.com/vantezzen/launchroom/daemon/internal/policy"
	"github.com/vantezzen/launchroom/daemon/internal/reconcile"
	"github.com/vantezzen/launchroom/daemon/internal/sealed"
	"github.com/vantezzen/launchroom/daemon/internal/secret"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// dbStopEngine is one managed database engine as the dashboard's engine
// driver renders it, with how to talk to it and what its log says about
// shutdowns.
type dbStopEngine struct {
	name     string
	image    state.ImageRef
	mount    state.ContainerPath
	env      map[string]secret.Secret
	command  []string // the driver's ENTRYPOINT override (Valkey: its image's starts tini)
	args     []string
	signal   state.StopSignal
	memoryMB int
	// execEnv authenticates the client commands below.
	execEnv []string
	ready   []string
	write   []string
	read    []string
	want    string
	stopped string // logged once per clean shutdown ("" = the exit code 0 is the signal)
	// logFile is a log the engine writes inside the container instead of
	// stdout (ClickHouse); read by exec while it runs.
	logFile     string
	cleanStart  string   // logged by a start after a clean shutdown ("" = nothing specific)
	uncleanLogs []string // logged by a start after an unclean shutdown
}

func dbStopEngines() []dbStopEngine {
	const memoryMB = 512
	psql := func(args ...string) []string {
		return append([]string{"psql", "--host=127.0.0.1", "--username=app", "--dbname=app", "-v", "ON_ERROR_STOP=1", "-XtA"}, args...)
	}
	mysql := func(args ...string) []string {
		return append([]string{"mysql", "--protocol=TCP", "--host=127.0.0.1", "--user=app", "--batch", "--skip-column-names", "app"}, args...)
	}
	mongosh := func(script string) []string {
		return []string{"mongosh", "--quiet", "--norc", "mongodb://app:pw-dbstop-123456@127.0.0.1:27017/app?authSource=admin", "--eval", script}
	}
	return []dbStopEngine{
		{
			name: "postgres", image: postgresImage, mount: "/var/lib/postgresql", signal: state.SignalInt, memoryMB: memoryMB,
			env:     map[string]secret.Secret{"POSTGRES_USER": "app", "POSTGRES_PASSWORD": "pw-dbstop-123456", "POSTGRES_DB": "app"},
			execEnv: []string{"PGPASSWORD=pw-dbstop-123456"},
			ready:   psql("-c", "SELECT 1"),
			write:   psql("-c", "CREATE TABLE notes (body text); INSERT INTO notes VALUES ('kept')"),
			read:    psql("-c", "SELECT body FROM notes"), want: "kept",
			stopped:     "database system is shut down",
			cleanStart:  "database system was shut down at",
			uncleanLogs: []string{"not properly shut down", "automatic recovery in progress", "database system was interrupted"},
		},
		{
			name: "mysql", image: mysqlImage, mount: "/var/lib/mysql", signal: state.SignalTerm, memoryMB: 1024,
			env:     map[string]secret.Secret{"MYSQL_USER": "app", "MYSQL_PASSWORD": "pw-dbstop-123456", "MYSQL_ROOT_PASSWORD": "root-dbstop-123456", "MYSQL_DATABASE": "app"},
			execEnv: []string{"MYSQL_PWD=pw-dbstop-123456"},
			ready:   mysql("-e", "SELECT 1"),
			write:   mysql("-e", "CREATE TABLE notes (body text); INSERT INTO notes VALUES ('kept')"),
			read:    mysql("-e", "SELECT body FROM notes"), want: "kept",
			stopped:     "Shutdown complete",
			uncleanLogs: []string{"crash recovery", "not shut down normally"},
		},
		{
			// The driver feeds requirepass through stdin from a fixed shell
			// line that execs the same entrypoint (verified by hand); this
			// test starts the server directly, which ends in the same PID 1.
			name: "redis", image: redisImage, mount: "/data", signal: state.SignalTerm, memoryMB: memoryMB,
			env:   map[string]secret.Secret{},
			args:  []string{"redis-server", "--appendonly", "yes"},
			ready: []string{"redis-cli", "ping"}, write: []string{"redis-cli", "SET", "note", "kept"},
			read: []string{"redis-cli", "GET", "note"}, want: "kept",
			stopped: "ready to exit, bye bye",
		},
		{
			// D47: mongod is PID 1 after the entrypoint's gosu.
			name: "mongodb", image: mongoImage, mount: "/data/db", signal: state.SignalTerm, memoryMB: 1024,
			env:   map[string]secret.Secret{"MONGO_INITDB_ROOT_USERNAME": "app", "MONGO_INITDB_ROOT_PASSWORD": "pw-dbstop-123456", "MONGO_INITDB_DATABASE": "app"},
			ready: mongosh("db.runCommand({ping: 1})"),
			write: mongosh("db.notes.insertOne({body: 'kept'})"),
			read:  mongosh("print(db.notes.findOne().body)"), want: "kept",
			stopped:     `"Now exiting"`,
			uncleanLogs: []string{"Detected unclean shutdown"},
		},
		{
			// D47: clickhouse-server is PID 1 after `clickhouse su`.
			name: "clickhouse", image: clickhouseImage, mount: "/var/lib/clickhouse", signal: state.SignalTerm, memoryMB: 1024,
			env:     map[string]secret.Secret{"CLICKHOUSE_USER": "app", "CLICKHOUSE_PASSWORD": "pw-dbstop-123456", "CLICKHOUSE_DATABASE": "default"},
			execEnv: []string{"CLICKHOUSE_USER=app", "CLICKHOUSE_PASSWORD=pw-dbstop-123456"},
			ready:   []string{"clickhouse-client", "--host=127.0.0.1", "--query", "SELECT 1"},
			write:   []string{"clickhouse-client", "--host=127.0.0.1", "--database=default", "--multiquery", "--query", "CREATE TABLE notes (body String) ENGINE = MergeTree ORDER BY body; INSERT INTO notes VALUES ('kept')"},
			read:    []string{"clickhouse-client", "--host=127.0.0.1", "--database=default", "--query", "SELECT body FROM notes"}, want: "kept",
			// ClickHouse logs to files, not stdout; a clean SIGTERM shutdown
			// exits 0 (killed: 137), and a start after an unclean one logs
			// "Status file … already exists - unclean restart".
			logFile:     "/var/log/clickhouse-server/clickhouse-server.log",
			uncleanLogs: []string{"unclean restart"},
		},
		{
			name: "valkey", image: valkeyImage, mount: "/data", signal: state.SignalTerm, memoryMB: memoryMB,
			command: []string{"docker-entrypoint.sh"},
			env:     map[string]secret.Secret{},
			args:    []string{"valkey-server", "--appendonly", "yes"},
			ready:   []string{"valkey-cli", "ping"}, write: []string{"valkey-cli", "SET", "note", "kept"},
			read: []string{"valkey-cli", "GET", "note"}, want: "kept",
			stopped: "ready to exit, bye bye",
		},
	}
}

// TestDatabaseStopsCleanly: managed databases run without an init process
// and with their engine's stop signal and a 60 s stop timeout (D42), so
// stopping (drain), restarting on a new deployment and redeploying a running
// database shut the engine down cleanly — well before the timeout — and the
// next start needs no crash recovery. The reconciler's own grace here is
// 2 s; the container's stop timeout extends it.
func TestDatabaseStopsCleanly(t *testing.T) {
	for _, eng := range dbStopEngines() {
		t.Run(eng.name, func(t *testing.T) { testDatabaseStop(t, eng) })
	}
}

func testDatabaseStop(t *testing.T, eng dbStopEngine) {
	e := setup(t)
	ctx, cancel := context.WithTimeout(context.Background(), 15*time.Minute)
	defer cancel()

	pol := policy.DefaultConfig()
	pol.AllowedRegistries = []string{"docker.io/library/", "docker.io/valkey/", "docker.io/clickhouse/"}
	mon := health.NewMonitor(ctx, health.MonitorOptions{})
	t.Cleanup(mon.Close)
	e.rec = reconcile.New(reconcile.Options{
		ServerID: e.serverID, Docker: e.engine, Policy: pol, Health: mon,
		EnvHashKey: reconcile.EnvHashKey(e.key.Bytes()),
		OpenEnv: func(s *state.WorkloadSpec) (map[string]secret.Secret, error) {
			return sealed.OpenWorkloadEnv(s.Env, e.key, e.serverID, s.ID)
		},
		StopGrace: 2 * time.Second,
		Log:       slog.New(slog.NewTextHandler(os.Stderr, &slog.HandlerOptions{Level: slog.LevelInfo})),
	})

	wl := state.WorkloadID(randomID("wl"))
	netID, volID := state.NetworkID(randomID("net")), state.VolumeID(randomID("vol"))
	version := int64(0)
	// nodeState renders the database with a new deployment id (a redeploy).
	nodeState := func(role state.WorkloadRole) *state.NodeState {
		t.Helper()
		version++
		sealedEnv, err := sealed.SealWorkloadEnv(state.WorkloadEnv{ServerID: e.serverID, WorkloadID: wl, Env: eng.env}, e.key.PublicKey(), rand.Reader)
		if err != nil {
			t.Fatal(err)
		}
		w := state.WorkloadSpec{
			ID: wl, Service: "svc_01J9Z8X7W6V5T4S3R2Q1P0DB02", Environment: "env_01J9Z8X7W6V5T4S3R2Q1P0DB03",
			Deployment: state.DeploymentID(randomID("dpl")), Image: eng.image, Command: eng.command, Args: eng.args, Env: sealedEnv,
			Resources:    state.Resources{CPUs: 1, MemoryMB: eng.memoryMB, Pids: 512},
			Capabilities: []state.Capability{"CHOWN", "SETUID", "SETGID", "DAC_OVERRIDE", "FOWNER"},
			Networks:     []state.NetworkAttachment{{Network: netID, Aliases: []state.Slug{"db"}}},
			Mounts:       []state.Mount{{Volume: volID, Target: eng.mount}},
			Restart:      state.RestartUnlessStopped, Role: role,
			Stop: &state.StopSpec{Signal: eng.signal, Timeout: "60s", NoInit: true},
		}
		if role == state.RoleDraining {
			past := time.Now().Add(-time.Minute).UTC()
			w.DrainUntil = &past
		}
		ns := &state.NodeState{
			TypeMeta: state.NewTypeMeta(state.KindNodeState), ServerID: e.serverID, Version: version, IssuedAt: time.Now().UTC(),
			Networks:    []state.NetworkSpec{{ID: netID, Name: "lr-e_dbstop", Subnet: "10.231.251.0/24"}},
			Volumes:     []state.VolumeSpec{{ID: volID, Name: state.HostName("lr-v_dbstop-" + eng.name)}},
			Workloads:   []state.WorkloadSpec{w},
			Ambassadors: []state.AmbassadorSpec{}, Routes: []state.RouteSpec{},
			Firewall: state.FirewallSpec{MeshAllow: []state.MeshAllowRule{}},
		}
		if err := ns.Validate(); err != nil {
			t.Fatal(err)
		}
		return ns
	}

	exec := func(id string, argv []string) (string, int) {
		t.Helper()
		var out strings.Builder
		res, err := e.engine.ExecStream(ctx, id, docker.ExecOptions{Argv: argv, Env: eng.execEnv, Stdout: &out})
		if err != nil {
			t.Fatal(err)
		}
		return strings.TrimSpace(out.String() + res.Stderr), res.ExitCode
	}
	logs := func(id string) string {
		t.Helper()
		var b strings.Builder
		if err := e.engine.StreamLogs(ctx, id, docker.LogsOptions{Tail: -1}, func(l docker.LogLine) error {
			b.WriteString(l.Text)
			b.WriteByte('\n')
			return nil
		}); err != nil {
			t.Fatal(err)
		}
		if c, err := e.engine.InspectContainer(ctx, id); eng.logFile != "" && err == nil && c.Running {
			var out strings.Builder
			if _, err := e.engine.ExecStream(ctx, id, docker.ExecOptions{Argv: []string{"cat", eng.logFile}, Stdout: &out}); err == nil {
				b.WriteString(out.String())
			}
		}
		return b.String()
	}
	// start converges ns (serving) and waits for the engine.
	start := func(ns *state.NodeState) string {
		t.Helper()
		converge(t, e, ns, func() bool { //nolint:contextcheck // converge bounds each pass itself
			st := status(e.rec, wl)
			if st.ContainerID == "" || (st.Phase != state.PhaseStarting && st.Phase != state.PhaseHealthy) {
				return false
			}
			c, err := e.engine.InspectContainer(ctx, st.ContainerID)
			return err == nil && c.Running && c.Labels[docker.LabelDeployment] == string(ns.Workloads[0].Deployment)
		})
		id := status(e.rec, wl).ContainerID
		deadline := time.Now().Add(4 * time.Minute)
		for {
			out, code := exec(id, eng.ready)
			if code == 0 {
				break
			}
			if time.Now().After(deadline) {
				t.Fatalf("%s did not become ready: %s\n%s", eng.name, out, logs(id))
			}
			time.Sleep(time.Second)
		}
		return id
	}
	// reconcileTimed runs one pass and returns how long it took.
	reconcileTimed := func(ns *state.NodeState) time.Duration {
		t.Helper()
		began := time.Now()
		if err := e.rec.Reconcile(ctx, ns); err != nil {
			t.Fatalf("reconcile: %v", err)
		}
		return time.Since(began)
	}
	assertCleanStart := func(id string) {
		t.Helper()
		l := logs(id)
		for _, bad := range eng.uncleanLogs {
			if strings.Contains(l, bad) {
				t.Fatalf("%s recovered from an unclean shutdown (%q):\n%s", eng.name, bad, l)
			}
		}
		if eng.cleanStart != "" && !strings.Contains(l, eng.cleanStart) {
			t.Fatalf("%s start does not report a clean shutdown (%q):\n%s", eng.name, eng.cleanStart, l)
		}
		if out, code := exec(id, eng.read); code != 0 || !strings.Contains(out, eng.want) {
			t.Fatalf("data lost: %q (exit %d)", out, code)
		}
	}

	// 1. Start, check the container config, write data.
	first := start(nodeState(state.RoleServing))
	insp, err := e.api.ContainerInspect(ctx, first, client.ContainerInspectOptions{})
	if err != nil {
		t.Fatal(err)
	}
	if hc := insp.Container.HostConfig; hc.Init == nil || *hc.Init {
		t.Errorf("init = %v, want false", hc.Init)
	}
	if c := insp.Container.Config; c.StopSignal != string(eng.signal) || c.StopTimeout == nil || *c.StopTimeout != 60 {
		t.Errorf("stop signal %q timeout %v", c.StopSignal, c.StopTimeout)
	}
	if out, code := exec(first, eng.write); code != 0 {
		t.Fatalf("write: %s", out)
	}
	before := strings.Count(logs(first), eng.stopped)

	// 2. Stop (drain period over): a clean shutdown, well before the timeout.
	took := reconcileTimed(nodeState(state.RoleDraining))
	c, err := e.engine.InspectContainer(ctx, first)
	if err != nil {
		t.Fatal(err)
	}
	t.Logf("%s stopped in %s (exit code %d)", eng.name, took.Round(time.Millisecond), c.ExitCode)
	if c.Running || c.ExitCode != 0 {
		t.Fatalf("after the stop: running=%v exit code %d:\n%s", c.Running, c.ExitCode, logs(first))
	}
	if after := strings.Count(logs(first), eng.stopped); eng.stopped != "" && after != before+1 {
		t.Fatalf("no clean shutdown in the log (%q %d → %d):\n%s", eng.stopped, before, after, logs(first))
	}
	if took > 30*time.Second {
		t.Fatalf("the stop took %s (timeout 60 s)", took)
	}

	// 3. A new deployment starts on the same volume without recovery.
	second := start(nodeState(state.RoleServing))
	assertCleanStart(second)

	// 4. Redeploying the running database (stop + remove + create).
	redeploy := nodeState(state.RoleServing)
	took = reconcileTimed(redeploy)
	t.Logf("%s redeployed in %s", eng.name, took.Round(time.Millisecond))
	if took > 45*time.Second {
		t.Fatalf("the redeploy took %s (stop timeout 60 s)", took)
	}
	third := start(redeploy)
	if third == second {
		t.Fatal("the redeploy kept the container")
	}
	assertCleanStart(third)
}
