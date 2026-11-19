package doctor

import (
	"archive/tar"
	"bytes"
	"compress/gzip"
	"context"
	"errors"
	"io"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"slices"
	"strings"
	"testing"
	"time"
)

// fakeEngine is an Engine over a fixed list of containers.
type fakeEngine struct {
	down       bool
	containers []Container
	logs       map[string]string
	exec       func(c Container, argv []string) (int, string)
	calls      []string
	runOnce    []OneOff
}

func (f *fakeEngine) Info(context.Context) (EngineInfo, error) {
	if f.down {
		return EngineInfo{}, errors.New("Cannot connect to the Docker daemon")
	}
	return EngineInfo{ServerVersion: "28.5.1", CgroupVersion: "2", DockerRootDir: "/var/lib/docker"}, nil
}

func (f *fakeEngine) Containers(_ context.Context, labels ...string) ([]Container, error) {
	var out []Container
	for _, c := range f.containers {
		match := true
		for _, l := range labels {
			switch {
			case l == labelProject+"="+cpProject && c.Project != cpProject:
				match = false
			case l == "launchroom.managed=true" && c.Project == cpProject:
				// compose containers are labelled managed too
			}
		}
		if match {
			out = append(out, c)
		}
	}
	return out, nil
}

func (f *fakeEngine) Logs(_ context.Context, c Container, _ int) (string, error) {
	return f.logs[c.Service+c.Name], nil
}

func (f *fakeEngine) Exec(_ context.Context, c Container, argv []string) (int, string, error) {
	f.calls = append(f.calls, "exec "+c.Service+" "+strings.Join(argv, " "))
	if f.exec != nil {
		code, out := f.exec(c, argv)
		return code, out, nil
	}
	return 0, "", nil
}

func (f *fakeEngine) Start(_ context.Context, c Container) error {
	f.calls = append(f.calls, "start "+c.Service)
	return nil
}

func (f *fakeEngine) Restart(_ context.Context, c Container) error {
	f.calls = append(f.calls, "restart "+c.Service)
	return nil
}

func (f *fakeEngine) Stop(_ context.Context, c Container) error {
	f.calls = append(f.calls, "stop "+c.Service)
	return nil
}

func (f *fakeEngine) Remove(_ context.Context, c Container) error {
	f.calls = append(f.calls, "remove "+c.Name)
	return nil
}

func (f *fakeEngine) InspectJSON(_ context.Context, c Container) ([]byte, error) {
	return []byte(`{"Name":"` + c.Name + `","Config":{"Image":"` + c.Image + `"}}`), nil
}

func (f *fakeEngine) RunOnce(_ context.Context, r OneOff) (int, string, error) {
	f.runOnce = append(f.runOnce, r)
	return 0, "AOF analyzed: ok", nil
}

const (
	pgImage     = "docker.io/library/postgres@sha256:77f585114c32fbca283dc835b0596f4e52b51b4c6662d7810b2f4084f60a1873"
	valkeyImage = "docker.io/valkey/valkey@sha256:081c2f5cb575efc901aa80ff9cdbd1ec6a301682fd35e1ebb4b0990a4a4a8507"
	dbPassword  = "s3cretDbPasswordValue1234567890abcdef1234"
)

func healthyStack(now time.Time) []Container {
	var out []Container
	for _, s := range cpServices {
		c := Container{ID: s.name, Name: "launchroom-" + s.name + "-1", Project: cpProject, Service: s.name, Running: true,
			Status: "running", StartedAt: now.Add(-3 * time.Hour), OomScoreAdj: -500}
		switch s.name {
		case "postgres":
			c.Image, c.Health, c.OomScoreAdj = pgImage, "healthy", -900
		case "valkey":
			c.Image, c.Health = valkeyImage, "healthy"
		}
		out = append(out, c)
	}
	return append(out, Container{ID: "caddy", Name: "lr-system-caddy", Running: true, Status: "running", Role: "edge"})
}

type fixture struct {
	dir string
	eng *fakeEngine
	o   Options
	now time.Time
}

func newFixture(t *testing.T) *fixture {
	t.Helper()
	dir := t.TempDir()
	now := time.Date(2026, 11, 17, 12, 0, 0, 0, time.UTC)
	for _, d := range []string{"data/daemon/update", "data/postgres", "data/valkey/appendonlydir", "proc/net"} {
		if err := os.MkdirAll(filepath.Join(dir, d), 0o700); err != nil {
			t.Fatal(err)
		}
	}
	write(t, filepath.Join(dir, ".env"), "APP_URL=https://dash.localhost\nDB_PASSWORD="+dbPassword+"\nLR_POSTGRES_IMAGE="+pgImage+"\nLR_VALKEY_IMAGE="+valkeyImage+"\nLR_COMPOSE_IMAGE=docker.io/library/docker@sha256:6fae2214c3121a28d5aa639d09032f61310b7c9c75ffbad1025f261a1b621800\n")
	write(t, filepath.Join(dir, "daemon-compose.yml"), "services: {}\n")
	write(t, filepath.Join(dir, "data/daemon/enrollment.json"), `{"serverId":"srv_x"}`)
	write(t, filepath.Join(dir, "proc/meminfo"), "MemTotal: 8000000 kB\nMemAvailable: 4000000 kB\nSwapTotal: 1000 kB\n")
	// LISTEN on 80 (0050) and 443 (01BB).
	write(t, filepath.Join(dir, "proc/net/tcp"), "  sl  local_address rem_address   st\n   0: 00000000:0050 00000000:0000 0A\n   1: 00000000:01BB 00000000:0000 0A\n")
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		switch r.URL.Path {
		case "/v2/":
			w.WriteHeader(http.StatusUnauthorized)
		case "/up":
			w.WriteHeader(http.StatusOK)
		default:
			w.WriteHeader(http.StatusNotFound)
		}
	}))
	t.Cleanup(srv.Close)
	addr := strings.TrimPrefix(srv.URL, "http://")
	eng := &fakeEngine{containers: healthyStack(now), logs: map[string]string{}}
	eng.exec = func(c Container, argv []string) (int, string) {
		switch {
		case c.Service == "postgres":
			return 0, "52428800|7|100\n"
		case c.Service == "valkey":
			return 0, "# Server\r\nvalkey_version:8.1.0\r\nused_memory:1048576\r\nmaxmemory:536870912\r\naof_last_write_status:ok\r\n"
		case strings.Join(argv, " ") == "php artisan list --raw":
			return 0, "about   Display basic information\nlaunchroom:setup-token   Print\n"
		case strings.Join(argv, " ") == "php artisan launchroom:dashboard-url":
			return 0, "https://dash.localhost\n"
		}
		return 0, ""
	}
	o := Options{Mode: ModeControlPlane, Engine: eng, Dir: dir, DataDir: filepath.Join(dir, "data/daemon"), ProcDir: filepath.Join(dir, "proc"),
		Version: "0.1.6", DashboardAddr: addr, RegistryAddr: addr, TLSAddr: "127.0.0.1:1", Now: func() time.Time { return now }}
	return &fixture{dir: dir, eng: eng, o: o, now: now}
}

func write(t *testing.T, path, content string) {
	t.Helper()
	if err := os.WriteFile(path, []byte(content), 0o600); err != nil {
		t.Fatal(err)
	}
}

func result(t *testing.T, rep Report, id string) Result {
	t.Helper()
	for _, r := range rep.Results {
		if r.ID == id {
			return r
		}
	}
	t.Fatalf("no result %q in %+v", id, rep.Results)
	return Result{}
}

func (f *fixture) set(service string, mut func(*Container)) {
	for i := range f.eng.containers {
		if f.eng.containers[i].Service == service {
			mut(&f.eng.containers[i])
		}
	}
}

func TestHealthyControlPlane(t *testing.T) {
	f := newFixture(t)
	rep := Run(t.Context(), f.o)
	for _, r := range rep.Results {
		if r.Status == StatusFail && r.ID != "address.certificate" {
			t.Errorf("%s failed: %s %v", r.ID, r.Summary, r.Detail)
		}
	}
	if got := result(t, rep, "db.ready"); got.Status != StatusOK || !strings.Contains(got.Summary, "50 MB, 7 of 100 connections") {
		t.Errorf("db.ready = %+v", got)
	}
	if got := result(t, rep, "cache.ready"); !strings.Contains(got.Summary, "1 MB of 512 MB") {
		t.Errorf("cache.ready = %+v", got)
	}
	if got := result(t, rep, "address.ports"); got.Status != StatusOK {
		t.Errorf("ports = %+v", got)
	}
	if got := result(t, rep, "dashboard.checks"); got.Status != StatusSkip {
		t.Errorf("artisan group without launchroom:doctor = %+v", got)
	}
	if got := result(t, rep, "update.state"); got.Status != StatusOK {
		t.Errorf("update = %+v", got)
	}
}

func TestServiceStates(t *testing.T) {
	f := newFixture(t)
	f.set("valkey", func(c *Container) {
		c.Running, c.Status, c.ExitCode, c.FinishedAt, c.Health = false, "exited", 1, f.now.Add(-3*time.Minute), ""
	})
	f.set("dashboard", func(c *Container) { c.Health, c.HealthLog = "unhealthy", "curl: (7) Failed to connect" })
	f.set("postgres", func(c *Container) { c.OOMKilled = true })
	f.set("horizon", func(c *Container) {
		c.Image = "ghcr.io/vantezzen/launchroom-dashboard@sha256:" + strings.Repeat("1", 64)
	})
	f.write(".env", "LR_DASHBOARD_IMAGE=ghcr.io/vantezzen/launchroom-dashboard@sha256:"+strings.Repeat("2", 64)+"\n")
	f.eng.containers = slices.DeleteFunc(f.eng.containers, func(c Container) bool { return c.Service == "reverb" })

	rep := Run(t.Context(), f.o)
	v := result(t, rep, "service.valkey")
	if v.Status != StatusFail || v.Fix == nil || v.Fix.ID != FixStart || v.Fix.Target != "valkey" || !strings.Contains(v.Summary, "exit code 1") || !strings.Contains(v.Summary, "3 minutes ago") {
		t.Errorf("valkey = %+v", v)
	}
	d := result(t, rep, "service.dashboard")
	if d.Status != StatusFail || d.Fix == nil || d.Fix.ID != FixRestart || !slices.Contains(d.Detail, "Last check: curl: (7) Failed to connect") {
		t.Errorf("dashboard = %+v", d)
	}
	if r := result(t, rep, "service.reverb"); r.Status != StatusFail || r.Summary != "Not created." || r.Fix.ID != FixStart {
		t.Errorf("reverb = %+v", r)
	}
	if h := result(t, rep, "service.horizon"); h.Status != StatusWarn || h.Fix == nil || h.Fix.ID != FixStart {
		t.Errorf("horizon digest mismatch = %+v", h)
	}
	if o := result(t, rep, "host.oom"); o.Status != StatusWarn || !strings.Contains(o.Summary, "postgres") {
		t.Errorf("oom = %+v", o)
	}
	var text bytes.Buffer
	WriteText(&text, rep, "./launchroom doctor")
	for _, want := range []string{"What needs attention", "Fix: ./launchroom doctor --fix start:valkey", "Fix: ./launchroom doctor --fix restart:dashboard", "problem  Cache and queues (Valkey)"} {
		if !strings.Contains(text.String(), want) {
			t.Errorf("text lacks %q:\n%s", want, text.String())
		}
	}
	for _, banned := range []string{"agent", "daemon", "·", "—", "just ", "simply"} {
		if strings.Contains(strings.ToLower(text.String()), banned) {
			t.Errorf("text has %q:\n%s", banned, text.String())
		}
	}
}

func (f *fixture) write(name, extra string) {
	p := filepath.Join(f.dir, name)
	b, _ := os.ReadFile(p)
	_ = os.WriteFile(p, append(b, extra...), 0o600)
}

func TestDockerDown(t *testing.T) {
	f := newFixture(t)
	f.eng.down = true
	rep := Run(t.Context(), f.o)
	if d := result(t, rep, "host.docker"); d.Status != StatusFail {
		t.Errorf("docker = %+v", d)
	}
	if s := result(t, rep, "services"); s.Status != StatusSkip {
		t.Errorf("services not skipped: %+v", s)
	}
	if !rep.Failed() {
		t.Error("report not failed")
	}
}

func TestLogMatchers(t *testing.T) {
	f := newFixture(t)
	f.set("valkey", func(c *Container) { c.Running, c.Restarting, c.Health = true, true, "" })
	f.eng.logs["valkey"+"launchroom-valkey-1"] = "1:M 17 Nov 2026 * Reading the remaining AOF tail...\n1:M # Bad file format reading the append only file appendonly.aof.1.incr.aof: make a backup of your AOF file, then use ./valkey-check-aof --fix <filename.manifest>\n"
	f.eng.logs["dashboard"+"launchroom-dashboard-1"] = "[launchroom] running migrations\n[launchroom] migration-failed 2026_11_16_120000_add_x (Illuminate\\Database\\QueryException)\n"
	f.set("dashboard", func(c *Container) { c.Running, c.Status = false, "exited" })
	f.eng.logs["launchroomd"+"launchroom-launchroomd-1"] = `{"time":"x","level":"INFO","msg":"ok"}` + "\n" +
		`{"time":"x","level":"ERROR","msg":"state fetch failed","err":"401 Unauthorized: Bearer abcdefghijklmnop1234"}` + "\n"
	rep := Run(t.Context(), f.o)
	a := result(t, rep, "cache.aof")
	if a.Status != StatusFail || a.Fix == nil || a.Fix.ID != FixRepairValkeyAOF {
		t.Errorf("aof = %+v", a)
	}
	m := result(t, rep, "dashboard.migrations")
	if !strings.Contains(m.Summary, "2026_11_16_120000_add_x") {
		t.Errorf("migration = %+v", m)
	}
	l := result(t, rep, "daemon.log")
	if l.Status != StatusWarn || len(l.Detail) != 1 || strings.Contains(l.Detail[0], "abcdefghijklmnop1234") {
		t.Errorf("daemon log = %+v", l)
	}
	if got := matchLog("LOG:  database system is ready\nPANIC:  could not write to file \"pg_wal/xlogtemp.1\": No space left on device\n", postgresPatterns); !strings.Contains(got, "PANIC") && !strings.Contains(got, "disk is full") {
		t.Errorf("postgres matcher = %q", got)
	}
}

func TestArtisanChecks(t *testing.T) {
	f := newFixture(t)
	base := f.eng.exec
	f.eng.exec = func(c Container, argv []string) (int, string) {
		switch strings.Join(argv, " ") {
		case "php artisan list --raw":
			return 0, "launchroom:doctor   Checks\n"
		case "php artisan launchroom:doctor --json":
			return 1, `Deprecated: something` + "\n" + `{"checks":[{"id":"schedule.locks","title":"Schedule","status":"fail","summary":"3 commands are locked by a scheduler that stopped.","fix":"clear-schedule-locks"},{"id":"bad id!","status":"ok"},{"id":"queue.wait","title":"Queues","status":"ok","summary":"Jobs wait 2 s.","fix":"rm -rf /"}]}`
		}
		return base(c, argv)
	}
	rep := Run(t.Context(), f.o)
	s := result(t, rep, "dashboard.schedule.locks")
	if s.Status != StatusFail || s.Fix == nil || s.Fix.ID != FixClearScheduleLocks {
		t.Errorf("schedule = %+v", s)
	}
	if q := result(t, rep, "dashboard.queue.wait"); q.Fix != nil {
		t.Errorf("unknown fix taken over: %+v", q)
	}
	for _, r := range rep.Results {
		if strings.Contains(r.ID, "bad id") {
			t.Errorf("invalid id kept: %+v", r)
		}
	}
}

func TestStalledUpdateOffersCancel(t *testing.T) {
	f := newFixture(t)
	base := f.eng.exec
	var ran []string
	f.eng.exec = func(c Container, argv []string) (int, string) {
		switch strings.Join(argv, " ") {
		case "php artisan list --raw":
			return 0, "launchroom:doctor   Checks\n"
		case "php artisan launchroom:doctor --json":
			return 1, `{"checks":[{"id":"update","title":"Update","status":"fail","summary":"The update to 0.2.0 has made no progress for 25 minutes.","detail":["Backing up"],"fix":"cancel-update"}]}`
		case "php artisan launchroom:updates:cancel --stalled":
			ran = append(ran, c.Service)
			return 0, "The update to 0.2.0 was cancelled.\n"
		}
		return base(c, argv)
	}
	rep := Run(t.Context(), f.o)
	u := result(t, rep, "dashboard.update")
	if u.Status != StatusFail || u.Fix == nil || u.Fix.ID != FixCancelUpdate {
		t.Fatalf("update = %+v", u)
	}
	var out bytes.Buffer
	if err := Apply(t.Context(), f.o, Hooks{}, rep, FixCancelUpdate, "", func(FixRef) bool { return true }, &out); err != nil {
		t.Fatal(err, out.String())
	}
	if !slices.Equal(ran, []string{"scheduler"}) || !strings.Contains(out.String(), "Cancelled the update") {
		t.Errorf("ran in %v, output %q", ran, out.String())
	}
	if id, _, err := ParseFix("cancel-update"); err != nil || id != FixCancelUpdate {
		t.Errorf("ParseFix = %v, %v", id, err)
	}
}

func TestFailedRollbackOffersFix(t *testing.T) {
	f := newFixture(t)
	task := "tsk_01J9Z8X7W6V5T4S3R2Q1P0N9P7"
	tdir := filepath.Join(f.dir, "data/daemon/update/tasks", task)
	if err := os.MkdirAll(filepath.Join(tdir, "backup"), 0o700); err != nil {
		t.Fatal(err)
	}
	write(t, filepath.Join(tdir, "status.json"), `{"taskId":"`+task+`","scope":"control_plane","fromVersion":"0.1.5","toVersion":"0.1.6","outcome":"failed","rollbackFailed":true,"error":"boom; ROLLBACK FAILED starting the previous version: x","finishedAt":"2026-11-17T11:00:00Z"}`)
	write(t, filepath.Join(f.dir, "data/postgres/launchroom-pre-upgrade.dump"), "dump")
	rep := Run(t.Context(), f.o)
	u := result(t, rep, "update.rollback")
	if u.Status != StatusFail || u.Fix == nil || u.Fix.ID != FixUpdateRollback || u.Fix.Target != task || !strings.Contains(u.Fix.Does, "from its copy") {
		t.Errorf("update = %+v", u)
	}
	// The dump is needed for the rollback: free-disk keeps it.
	r := &run{o: f.o, env: map[string]string{}}
	r.o.defaults()
	r.load(t.Context())
	if _, err := r.freeDisk(t.Context()); err != nil {
		t.Fatal(err)
	}
	if _, err := os.Stat(filepath.Join(f.dir, "data/postgres/launchroom-pre-upgrade.dump")); err != nil {
		t.Error("free-disk removed the dump a rollback still needs")
	}
}

func TestApplyFixes(t *testing.T) {
	f := newFixture(t)
	f.set("valkey", func(c *Container) { c.Running, c.Status = false, "exited" })
	f.set("dashboard", func(c *Container) { c.Health = "unhealthy" })
	var composed []string
	hooks := Hooks{ComposeUp: func(_ context.Context, svc string) error { composed = append(composed, svc); return nil }}
	rep := Run(t.Context(), f.o)
	yes := func(FixRef) bool { return true }
	no := func(FixRef) bool { return false }
	var out bytes.Buffer

	if err := Apply(t.Context(), f.o, hooks, rep, FixStart, "", no, &out); !errors.Is(err, ErrDeclined) || len(composed) != 0 {
		t.Fatalf("declined: err=%v composed=%v", err, composed)
	}
	if err := Apply(t.Context(), f.o, hooks, rep, FixStart, "valkey", yes, &out); err != nil || !slices.Equal(composed, []string{"valkey"}) {
		t.Fatalf("start: err=%v composed=%v", err, composed)
	}
	if err := Apply(t.Context(), f.o, hooks, rep, FixRestart, "", yes, &out); err != nil || !slices.Contains(f.eng.calls, "restart dashboard") {
		t.Fatalf("restart: err=%v calls=%v", err, f.eng.calls)
	}
	// Postgres is never restarted, whatever the report says.
	forged := Report{Results: []Result{{Status: StatusFail, Fix: &FixRef{ID: FixRestart, Target: "postgres"}}}}
	if err := Apply(t.Context(), f.o, hooks, forged, FixRestart, "", yes, &out); err == nil || slices.Contains(f.eng.calls, "restart postgres") {
		t.Fatalf("postgres restarted: err=%v calls=%v", err, f.eng.calls)
	}
	if !strings.Contains(out.String(), "Started valkey.") {
		t.Errorf("output: %s", out.String())
	}
}

func TestRepairValkeyAOF(t *testing.T) {
	f := newFixture(t)
	f.set("valkey", func(c *Container) { c.Running, c.Restarting = true, true })
	write(t, filepath.Join(f.dir, "data/valkey/appendonlydir/appendonly.aof.manifest"), "file appendonly.aof.1.base.rdb seq 1 type b\n")
	write(t, filepath.Join(f.dir, "data/valkey/appendonlydir/appendonly.aof.1.incr.aof"), "*2\r\n$6\r\nSELECT")
	rep := Report{Results: []Result{{Status: StatusFail, Fix: &FixRef{ID: FixRepairValkeyAOF, Target: "valkey"}}}}
	var out bytes.Buffer
	if err := Apply(t.Context(), f.o, Hooks{}, rep, FixRepairValkeyAOF, "", func(FixRef) bool { return true }, &out); err != nil {
		t.Fatal(err, out.String())
	}
	if len(f.eng.runOnce) != 1 {
		t.Fatalf("runOnce = %+v", f.eng.runOnce)
	}
	r := f.eng.runOnce[0]
	if !slices.Equal(r.Cmd, []string{"valkey-check-aof", "--fix", "/data/appendonlydir/appendonly.aof.manifest"}) || r.Image != valkeyImage || r.Stdin != "y\n" ||
		!slices.Equal(r.Binds, []string{filepath.Join(f.dir, "data/valkey") + ":/data"}) || !strings.HasPrefix(r.Name, "lr-system-") {
		t.Errorf("one-off = %+v", r)
	}
	if !slices.Equal(f.eng.calls, []string{"stop valkey", "start valkey"}) {
		t.Errorf("calls = %v", f.eng.calls)
	}
	backups, _ := filepath.Glob(filepath.Join(f.dir, "data/valkey/aof-backup-*/appendonlydir/appendonly.aof.1.incr.aof"))
	if len(backups) != 1 {
		t.Errorf("no copy of the AOF before the repair")
	}
}

func TestFreeDisk(t *testing.T) {
	f := newFixture(t)
	write(t, filepath.Join(f.dir, "data/postgres/launchroom-pre-upgrade.dump"), strings.Repeat("x", 4096))
	f.eng.containers = append(f.eng.containers,
		Container{ID: "u1", Name: "lr-system-update-abc-install", Role: "updater", Status: "exited"},
		Container{ID: "a1", Name: "lr-app", Role: "", Status: "exited"})
	rep := Run(t.Context(), f.o)
	if d := result(t, rep, "db.dump"); d.Status != StatusWarn || d.Fix == nil || d.Fix.ID != FixFreeDisk {
		t.Fatalf("dump = %+v", d)
	}
	var out bytes.Buffer
	if err := Apply(t.Context(), f.o, Hooks{}, rep, FixFreeDisk, "", func(FixRef) bool { return true }, &out); err != nil {
		t.Fatal(err)
	}
	if _, err := os.Stat(filepath.Join(f.dir, "data/postgres/launchroom-pre-upgrade.dump")); err == nil {
		t.Error("dump kept")
	}
	if !slices.Contains(f.eng.calls, "remove lr-system-update-abc-install") || slices.Contains(f.eng.calls, "remove lr-app") {
		t.Errorf("calls = %v", f.eng.calls)
	}
}

func TestRedaction(t *testing.T) {
	env := map[string]string{"DB_PASSWORD": dbPassword, "APP_KEY": "base64:Zm9vYmFyYmF6cXV4cXV1eGNvcmdlZ3JhdWx0", "SHORT": "abc", "VALKEY_PASSWORD": "v4lkeyPassw0rdValue"}
	red := NewRedactor(env)
	in := strings.Join([]string{
		"connecting with " + dbPassword,
		"APP_KEY=base64:Zm9vYmFyYmF6cXV4cXV1eGNvcmdlZ3JhdWx0",
		"short value abc stays",
		"-----BEGIN PRIVATE KEY-----\nMIIEvQIBADANBg\n-----END PRIVATE KEY-----",
		"Authorization: Bearer eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.dozjgNryP4J3jVmNHl0w5N_XgL0n3I9PlFUP0THsR8U",
		"curl https://admin:hunter2pass@registry.example.com/v2/",
		`{"password":"p@ssw0rd!!","token":"tok_1234567890"}`,
		"MAIL_PASSWORD=mailsecret99 api_key=AKIAIOSFODNN7EXAMPLE",
		"join token lr_jt_0123456789abcdefghij",
		"standalone eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.dozjgNryP4J3jVmNHl0w5N_XgL0n3I9PlFUP0THsR8U",
	}, "\n")
	out := red.Redact(in)
	for _, secret := range []string{dbPassword, "Zm9vYmFyYmF6cXV4cXV1eGNvcmdlZ3JhdWx0", "MIIEvQIBADANBg", "hunter2pass", "p@ssw0rd!!", "tok_1234567890",
		"mailsecret99", "AKIAIOSFODNN7EXAMPLE", "lr_jt_0123456789abcdefghij", "dozjgNryP4J3jVmNHl0w5N", "eyJzdWIiOiIxMjM0NTY3ODkwIn0"} {
		if strings.Contains(out, secret) {
			t.Errorf("%q survived:\n%s", secret, out)
		}
	}
	for _, kept := range []string{"[redacted:DB_PASSWORD]", "short value abc stays", "registry.example.com/v2/", "[redacted:pem]"} {
		if !strings.Contains(out, kept) {
			t.Errorf("lacks %q:\n%s", kept, out)
		}
	}
}

func TestBundle(t *testing.T) {
	f := newFixture(t)
	f.eng.logs["postgres"+"launchroom-postgres-1"] = "FATAL: password authentication failed; password was " + dbPassword + "\n"
	f.eng.containers = append(f.eng.containers, Container{ID: "app", Name: "lr-wl-app", Role: "", Running: true, Status: "running"})
	f.eng.logs["lr-wl-app"] = "customer data that never leaves the server\n"
	tdir := filepath.Join(f.dir, "data/daemon/update/tasks/tsk_01J9Z8X7W6V5T4S3R2Q1P0N9P7")
	_ = os.MkdirAll(filepath.Join(tdir, "diagnostics"), 0o700)
	_ = os.MkdirAll(filepath.Join(tdir, "backup"), 0o700)
	write(t, filepath.Join(tdir, "status.json"), `{"outcome":"rolled_back"}`)
	write(t, filepath.Join(tdir, "diagnostics/dashboard.log"), "[launchroom] migration-failed x\n")
	write(t, filepath.Join(tdir, "backup/.env"), "DB_PASSWORD="+dbPassword+"\n")
	write(t, filepath.Join(f.dir, "data/daemon/signing.key"), "PRIVATE")

	rep := Run(t.Context(), f.o)
	path, err := WriteBundle(t.Context(), f.o, rep)
	if err != nil {
		t.Fatal(err)
	}
	if st, err := os.Stat(path); err != nil || st.Mode().Perm() != 0o600 || !strings.HasPrefix(path, filepath.Join(f.dir, "data", "doctor")) {
		t.Fatalf("bundle %s: %v %v", path, st.Mode(), err)
	}
	files := readTarGz(t, path)
	for _, want := range []string{"doctor.json", "containers.txt", "versions.txt", "logs/launchroom-postgres-1.log", "inspect/lr-system-caddy.json",
		"update/tsk_01J9Z8X7W6V5T4S3R2Q1P0N9P7/status.json", "update/tsk_01J9Z8X7W6V5T4S3R2Q1P0N9P7/diagnostics/dashboard.log"} {
		if _, ok := files["launchroom-support/"+want]; !ok {
			t.Errorf("bundle lacks %s (has %v)", want, keys(files))
		}
	}
	for name, body := range files {
		if strings.Contains(name, ".env") || strings.HasSuffix(name, ".key") || strings.Contains(name, "keys/") || strings.Contains(name, "logs/lr-wl-app") {
			t.Errorf("bundle has %s", name)
		}
		if strings.Contains(body, dbPassword) || strings.Contains(body, "PRIVATE") || strings.Contains(body, "customer data") {
			t.Errorf("%s leaks a secret: %s", name, body)
		}
	}
	if !strings.Contains(files["launchroom-support/logs/launchroom-postgres-1.log"], "[redacted:DB_PASSWORD]") {
		t.Errorf("postgres log not redacted: %s", files["launchroom-support/logs/launchroom-postgres-1.log"])
	}
}

func readTarGz(t *testing.T, path string) map[string]string {
	t.Helper()
	b, err := os.ReadFile(path)
	if err != nil {
		t.Fatal(err)
	}
	gz, err := gzip.NewReader(bytes.NewReader(b))
	if err != nil {
		t.Fatal(err)
	}
	tr := tar.NewReader(gz)
	out := map[string]string{}
	for {
		h, err := tr.Next()
		if errors.Is(err, io.EOF) {
			return out
		}
		if err != nil {
			t.Fatal(err)
		}
		body, _ := io.ReadAll(tr)
		out[h.Name] = string(body)
	}
}

func keys(m map[string]string) []string {
	var out []string
	for k := range m {
		out = append(out, k)
	}
	slices.Sort(out)
	return out
}

func TestParseFix(t *testing.T) {
	if id, target, err := ParseFix("start:valkey"); err != nil || id != FixStart || target != "valkey" {
		t.Errorf("start:valkey = %v %v %v", id, target, err)
	}
	for _, bad := range []string{"rm", "start:../../etc", "restart:a b"} {
		if _, _, err := ParseFix(bad); err == nil {
			t.Errorf("%q accepted", bad)
		}
	}
}

func TestDiskThresholds(t *testing.T) {
	for _, tc := range []struct {
		free, total uint64
		want        Status
	}{
		{40 * gib, 80 * gib, StatusOK},
		{4 * gib, 80 * gib, StatusWarn},
		{gib / 2, 80 * gib, StatusFail},
		{3 * gib, 200 * gib, StatusFail},
	} {
		u := fsUsage{Total: tc.total, Free: tc.free, FreePct: float64(tc.free) * 100 / float64(tc.total), Inodes: 1000000, InodesFree: 500000, InodesFPct: 50}
		if got := diskResult("d", "x", u); got.Status != tc.want {
			t.Errorf("%d/%d: %s, want %s", tc.free, tc.total, got.Status, tc.want)
		}
	}
	u := fsUsage{Total: 100 * gib, Free: 50 * gib, FreePct: 50, Inodes: 1000000, InodesFree: 5000, InodesFPct: 0.5}
	if got := diskResult("d", "x", u); got.Status != StatusFail || got.Fix == nil {
		t.Errorf("inodes: %+v", got)
	}
}
