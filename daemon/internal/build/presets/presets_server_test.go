package presets

import (
	"slices"
	"strings"
	"testing"
	"testing/fstest"

	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// validSummary checks that a result fits the protocol's build summary.
func validSummary(t *testing.T, r *Result) {
	t.Helper()
	s := state.BuildSummary{
		Builder: state.BuilderRailpack, BuilderReason: state.ReasonPresetAndRailpack,
		Presets: []state.AppliedPreset{r.Applied}, RuntimeEnv: r.RuntimeEnv, Processes: r.Processes,
		ReleaseCommand: r.ReleaseCommand, Commands: r.Commands, HealthPath: state.HTTPPath(r.HealthPath), GeneratedSecrets: r.GeneratedSecrets,
		EnvAliases: r.EnvAliases, RequiredSecrets: r.RequiredSecrets, VolumeSuggestions: r.VolumeSuggestions, Warnings: r.Warnings,
	}
	c := &state.TaskCompletion{TypeMeta: state.NewTypeMeta(state.KindTaskCompletion), Attempt: 1, Outcome: state.OutcomeSucceeded,
		TaskResults: state.TaskResults{Plan: &state.PlanResult{Summary: s}}}
	if err := c.Validate(); err != nil {
		t.Fatalf("summary is not valid: %v", err)
	}
}

func mustResolve(t *testing.T, f Facts, i Info, want string) *Result {
	t.Helper()
	r, ok := Resolve(f, i, "", nil)
	if !ok || r == nil {
		t.Fatalf("no preset matched, want %s", want)
	}
	if string(r.Applied.Name) != want {
		t.Fatalf("preset = %s, want %s", r.Applied.Name, want)
	}
	validSummary(t, r)
	return r
}

func process(r *Result, name string) *state.ProcessSuggestion {
	for i := range r.Processes {
		if string(r.Processes[i].Name) == name {
			return &r.Processes[i]
		}
	}
	return nil
}

func alias(r *Result, name string) state.EnvAliasSource {
	for _, a := range r.EnvAliases {
		if string(a.Name) == name {
			return a.From
		}
	}
	return ""
}

func TestLaravelSkeletons(t *testing.T) {
	php := Info{Provider: "php", Metadata: map[string]string{"phpFramework": "laravel"}}
	t.Run("12", func(t *testing.T) {
		r := mustResolve(t, repo(t, "laravel"), php, "laravel")
		if r.HealthPath != "/up" || !slices.Contains(decisionIDs(r), "health-up") || r.Applied.Version != 3 {
			t.Fatalf("health %q decisions %v version %d", r.HealthPath, decisionIDs(r), r.Applied.Version)
		}
		if p := process(r, "horizon"); p == nil || p.StopTimeout != "60s" {
			t.Fatalf("horizon = %+v", p)
		}
		if alias(r, "APP_URL") != state.AliasPublicURL {
			t.Fatalf("aliases = %+v", r.EnvAliases)
		}
		if r.RuntimeEnv["LOG_LEVEL"] != "info" || r.RuntimeEnv["SESSION_SECURE_COOKIE"] != "true" {
			t.Fatalf("runtime env = %v", r.RuntimeEnv)
		}
		for _, id := range []string{PhpVisitorIPDecision, LaravelStartScriptDecision, PhpPcntlDecision} {
			if !slices.Contains(decisionIDs(r), id) {
				t.Errorf("decision %s missing: %v", id, decisionIDs(r))
			}
		}
		// Laravel 12 without config/database.php defaults to SQLite and database sessions.
		if codes := warningCodes(r); !slices.Contains(codes, "sqlite-database") || slices.Contains(codes, "file-sessions") {
			t.Fatalf("warnings = %v", codes)
		}
		if len(r.VolumeSuggestions) != 0 {
			t.Fatalf("no upload code, no volume: %+v", r.VolumeSuggestions)
		}
	})
	t.Run("10", func(t *testing.T) {
		r := mustResolve(t, repo(t, "laravel10"), php, "laravel")
		if r.HealthPath != "/" || slices.Contains(decisionIDs(r), "health-up") {
			t.Fatalf("Laravel 10 has no /up: health %q decisions %v", r.HealthPath, decisionIDs(r))
		}
		if p := process(r, "queue"); p == nil || p.StopTimeout != "60s" {
			t.Fatalf("queue = %+v", p)
		}
		if codes := warningCodes(r); slices.Contains(codes, "sqlite-database") || !slices.Contains(codes, "file-sessions") {
			t.Fatalf("warnings = %v", codes)
		}
		if len(r.VolumeSuggestions) != 1 || r.VolumeSuggestions[0].Path != "/app/storage/app" {
			t.Fatalf("volume suggestions = %+v", r.VolumeSuggestions)
		}
	})
	t.Run("11", func(t *testing.T) {
		r := mustResolve(t, repo(t, "laravel11"), php, "laravel")
		if r.HealthPath != "/healthz" {
			t.Fatalf("a commented-out health: '/up' must not win: %q", r.HealthPath)
		}
		if process(r, "reverb") == nil || !slices.Contains(warningCodes(r), "sqlite-database") {
			t.Fatalf("processes %+v warnings %v", r.Processes, warningCodes(r))
		}
	})
	t.Run("removing health keeps /", func(t *testing.T) {
		r, _ := Resolve(repo(t, "laravel10"), php, "", []string{"health-up"})
		if r.HealthPath != "/" {
			t.Fatalf("health = %q", r.HealthPath)
		}
		r, _ = Resolve(repo(t, "laravel"), php, "", []string{"health-up"})
		if r.HealthPath != "/up" {
			t.Fatalf("the detected path is the preset's default: %q", r.HealthPath)
		}
	})
}

func TestPrePlan(t *testing.T) {
	pcntl := []state.PhpDependencyExtension{{Name: "pcntl", For: "laravel/framework"}}
	if got := ResolvePrePlan(repo(t, "laravel10"), "", nil); !slices.Equal(got.PhpExtensions, pcntl) || got.RailpackEnv != nil {
		t.Fatalf("laravel pre-plan = %+v", got)
	}
	if got := ResolvePrePlan(repo(t, "laravel"), "", []string{PhpPcntlDecision}); got.PhpExtensions != nil {
		t.Fatalf("removed pcntl still installed: %+v", got)
	}
	if got := ResolvePrePlan(repo(t, "laravel"), "none", nil); got.PhpExtensions != nil {
		t.Fatalf("preset none: %+v", got)
	}
	if got := ResolvePrePlan(repo(t, "php-public"), "", nil); got.RailpackEnv["RAILPACK_PHP_ROOT_DIR"] != "/app/public" {
		t.Fatalf("plain php pre-plan = %+v", got)
	}
	if got := ResolvePrePlan(repo(t, "php-public"), "", []string{PhpPublicDocrootDecision}); got.RailpackEnv != nil {
		t.Fatalf("removed docroot still applied: %+v", got)
	}
	for _, name := range []string{"symfony", "django", "node-hello", "rails80"} {
		if got := ResolvePrePlan(repo(t, name), "", nil); got.RailpackEnv != nil || got.PhpExtensions != nil {
			t.Errorf("%s: pre-plan = %+v", name, got)
		}
	}
}

func TestSymfonyAndPlainPHP(t *testing.T) {
	r := mustResolve(t, repo(t, "symfony"), Info{Provider: "php", Metadata: map[string]string{"phpFramework": "symfony"}}, "symfony")
	if !slices.Equal(r.ReleaseCommand, []string{"php", "bin/console", "doctrine:migrations:migrate", "--no-interaction", "--allow-no-migration"}) ||
		r.Plan.DeployVariables["RAILPACK_SKIP_MIGRATIONS"] != "true" {
		t.Fatalf("release = %v plan = %+v", r.ReleaseCommand, r.Plan)
	}
	if !slices.Equal(r.GeneratedSecrets, []state.GeneratedSecret{{Name: "APP_SECRET", Length: 16, Encoding: state.SecretHex}}) {
		t.Fatalf("generated = %+v", r.GeneratedSecrets)
	}
	if alias(r, "DEFAULT_URI") != state.AliasPublicURL || alias(r, "SYMFONY_TRUSTED_PROXIES") != state.AliasTrustedProxyCIDRs {
		t.Fatalf("aliases = %+v", r.EnvAliases)
	}
	// Without doctrine migrations, Railpack's start script has nothing to skip.
	f := repo(t, "symfony")
	delete(f.ComposerRequire, "doctrine/doctrine-migrations-bundle")
	if r, _ := Resolve(f, Info{Provider: "php"}, "", nil); r.ReleaseCommand != nil || slices.Contains(decisionIDs(r), "release-migrations") {
		t.Fatalf("release without migrations bundle: %v", r.ReleaseCommand)
	}

	r = mustResolve(t, repo(t, "php-public"), Info{Provider: "php"}, "php")
	if !slices.Equal(decisionIDs(r), []string{PhpPublicDocrootDecision, PhpVisitorIPDecision}) {
		t.Fatalf("decisions = %v", decisionIDs(r))
	}
	if r, _ := Resolve(repo(t, "php-public"), Info{Provider: "node"}, "", nil); r != nil && r.Applied.Name == "php" {
		t.Fatal("plain php needs Railpack's php provider")
	}
}

func djangoFS(settings string, extra ...string) fstest.MapFS {
	fsys := fstest.MapFS{
		"manage.py":        {Data: []byte(`os.environ.setdefault("DJANGO_SETTINGS_MODULE", "site.settings")`)},
		"site/settings.py": {Data: []byte("WSGI_APPLICATION = \"site.wsgi.application\"\nSTATIC_ROOT = BASE_DIR / \"staticfiles\"\n" + settings)},
		"requirements.txt": {Data: []byte("django\ngunicorn\n" + strings.Join(extra, "\n") + "\n")},
	}
	return fsys
}

func TestDjangoHosts(t *testing.T) {
	py := Info{Provider: "python"}
	t.Run("hosts from a variable", func(t *testing.T) {
		r := mustResolve(t, Gather(djangoFS(`ALLOWED_HOSTS = os.environ.get("DJANGO_ALLOWED_HOSTS", "").split(",")
CSRF_TRUSTED_ORIGINS = env.list("CSRF_ORIGINS", default=[])`), nil), py, "django")
		if alias(r, "DJANGO_ALLOWED_HOSTS") != state.AliasAllowedHostsList || alias(r, "CSRF_ORIGINS") != state.AliasPublicURLs {
			t.Fatalf("aliases = %+v", r.EnvAliases)
		}
		if alias(r, "FORWARDED_ALLOW_IPS") != state.AliasTrustedProxyCIDRs {
			t.Fatalf("proxy trust missing: %+v", r.EnvAliases)
		}
		if r.Plan.DeployFiles != nil || r.RuntimeEnv["DJANGO_SETTINGS_MODULE"] != "" {
			t.Fatalf("no shim when the settings read the hosts: %+v %v", r.Plan.DeployFiles, r.RuntimeEnv)
		}
		if r.Applied.Version != 3 {
			t.Fatalf("django version %d", r.Applied.Version)
		}
	})
	t.Run("decouple", func(t *testing.T) {
		f := Gather(djangoFS(`ALLOWED_HOSTS = config("ALLOWED_HOSTS", cast=Csv())`), nil)
		if f.Django.AllowedHostsEnv != "ALLOWED_HOSTS" {
			t.Fatalf("hosts env = %q", f.Django.AllowedHostsEnv)
		}
	})
	t.Run("hard-coded hosts use the shim", func(t *testing.T) {
		fsys := djangoFS(`ALLOWED_HOSTS = ["example.com"]`)
		fsys["site/wsgi.py"] = &fstest.MapFile{Data: []byte(`os.environ.setdefault('DJANGO_SETTINGS_MODULE', 'site.settings.production')`)}
		r := mustResolve(t, Gather(fsys, nil), py, "django")
		if r.Plan.DeployFiles[DjangoShimPath] != djangoShim {
			t.Fatalf("deploy files = %v", r.Plan.DeployFiles)
		}
		if r.RuntimeEnv["DJANGO_SETTINGS_MODULE"] != "launchroom_django" || r.RuntimeEnv["LAUNCHROOM_DJANGO_SETTINGS"] != "site.settings.production" ||
			r.RuntimeEnv["PYTHONPATH"] != "/opt/launchroom/python" {
			t.Fatalf("the WSGI module's settings are production's: %v", r.RuntimeEnv)
		}
		if alias(r, "LAUNCHROOM_ALLOWED_HOSTS") != state.AliasAllowedHostsList || alias(r, "LAUNCHROOM_CSRF_TRUSTED_ORIGINS") != state.AliasPublicURLs {
			t.Fatalf("aliases = %+v", r.EnvAliases)
		}
		if !strings.Contains(djangoShim, `_os.environ["LAUNCHROOM_DJANGO_SETTINGS"]`) || strings.Contains(djangoShim, "\t") {
			t.Fatal("the shim reads the original module from the environment and is indented with spaces")
		}
		r, _ = Resolve(Gather(fsys, nil), py, "", []string{"allowed-hosts"})
		if r.Plan.DeployFiles != nil || r.RuntimeEnv["DJANGO_SETTINGS_MODULE"] != "" {
			t.Fatalf("removing the decision removes the shim: %+v", r.RuntimeEnv)
		}
		rt, _ := ResolveRuntime(Gather(fsys, nil), "", nil)
		if rt.RuntimeEnv["DJANGO_SETTINGS_MODULE"] != "" || slices.Contains(decisionIDs(rt), "allowed-hosts") {
			t.Fatalf("Dockerfile builds can't get the shim file: %v %v", rt.RuntimeEnv, decisionIDs(rt))
		}
	})
	t.Run("wildcard", func(t *testing.T) {
		r := mustResolve(t, Gather(djangoFS(`ALLOWED_HOSTS = ['*']`), nil), py, "django")
		if slices.Contains(decisionIDs(r), "allowed-hosts") {
			t.Fatalf("decisions = %v", decisionIDs(r))
		}
	})
	t.Run("whitenoise", func(t *testing.T) {
		r := mustResolve(t, Gather(djangoFS(`ALLOWED_HOSTS = ['*']`, "whitenoise"), nil), py, "django")
		if r.RuntimeEnv["LAUNCHROOM_WHITENOISE"] != "1" || r.Plan.DeployFiles[DjangoShimPath] == "" {
			t.Fatalf("whitenoise installed but not configured: %v", r.RuntimeEnv)
		}
		r = mustResolve(t, Gather(djangoFS(`ALLOWED_HOSTS = ['*']
MIDDLEWARE = ["whitenoise.middleware.WhiteNoiseMiddleware"]`, "whitenoise"), nil), py, "django")
		if slices.Contains(decisionIDs(r), "whitenoise") || slices.Contains(warningCodes(r), "static-files-unserved") {
			t.Fatalf("already configured: %v %v", decisionIDs(r), warningCodes(r))
		}
		r = mustResolve(t, Gather(djangoFS(`ALLOWED_HOSTS = ['*']`), nil), py, "django")
		if !slices.Contains(warningCodes(r), "static-files-unserved") {
			t.Fatalf("warnings = %v", warningCodes(r))
		}
	})
	t.Run("sqlite", func(t *testing.T) {
		r := mustResolve(t, repo(t, "django"), py, "django")
		if p := process(r, "worker"); p == nil || p.StopTimeout != "60s" || process(r, "beat").StopTimeout != "" {
			t.Fatalf("celery processes = %+v", r.Processes)
		}
		r = mustResolve(t, Gather(djangoFS(`DATABASES = {"default": {"ENGINE": "django.db.backends.sqlite3", "NAME": BASE_DIR / "db.sqlite3"}}`), nil), py, "django")
		if !slices.Contains(warningCodes(r), "sqlite-database") || len(r.VolumeSuggestions) != 0 {
			t.Fatalf("a database file in the app root gets a warning only: %v %+v", warningCodes(r), r.VolumeSuggestions)
		}
		r = mustResolve(t, Gather(djangoFS(`DATABASES = {
    "default": {
        "ENGINE": "django.db.backends.sqlite3",
        "NAME": os.path.join(BASE_DIR, "data", "db.sqlite3"),
    }
}`), nil), py, "django")
		if len(r.VolumeSuggestions) != 1 || r.VolumeSuggestions[0].Path != "/app/data" {
			t.Fatalf("volume suggestions = %+v", r.VolumeSuggestions)
		}
	})
	t.Run("python-web trusts the proxy", func(t *testing.T) {
		r := mustResolve(t, repo(t, "fastapi"), py, "python-web")
		if alias(r, "FORWARDED_ALLOW_IPS") != state.AliasTrustedProxyCIDRs || r.Applied.Version != 2 {
			t.Fatalf("aliases = %+v", r.EnvAliases)
		}
	})
}

func TestRails(t *testing.T) {
	ruby := Info{Provider: "ruby", Metadata: map[string]string{"rubyRails": "true"}}
	t.Run("7.0", func(t *testing.T) {
		r := mustResolve(t, repo(t, "rails70"), ruby, "rails")
		if r.RuntimeEnv["RAILS_ENV"] != "production" || r.RuntimeEnv["RAILS_LOG_TO_STDOUT"] != "1" {
			t.Fatalf("runtime env = %v", r.RuntimeEnv)
		}
		if !slices.Equal(r.ReleaseCommand, []string{"bin/rails", "db:prepare"}) {
			t.Fatalf("release = %v", r.ReleaseCommand)
		}
		if r.HealthPath != "/" || slices.Contains(decisionIDs(r), "health-up") {
			t.Fatalf("Rails 7.0 has no health route: %q", r.HealthPath)
		}
		if p := process(r, "sidekiq"); p == nil || p.StopTimeout != "30s" {
			t.Fatalf("processes = %+v", r.Processes)
		}
		if !slices.Equal(r.GeneratedSecrets, []state.GeneratedSecret{{Name: "SECRET_KEY_BASE", Length: 64, Encoding: state.SecretHex}}) {
			t.Fatalf("generated = %+v", r.GeneratedSecrets)
		}
		if len(r.RequiredSecrets) != 1 || r.RequiredSecrets[0].Name != "RAILS_MASTER_KEY" || !strings.Contains(r.RequiredSecrets[0].Reason, "config/master.key") {
			t.Fatalf("required = %+v", r.RequiredSecrets)
		}
		if !strings.HasPrefix(r.Plan.StartCommand, "rm -f tmp/pids/server.pid && exec ") {
			t.Fatalf("start = %q", r.Plan.StartCommand)
		}
		if len(r.VolumeSuggestions) != 0 || len(r.Warnings) != 0 {
			t.Fatalf("postgres and S3: %+v %v", r.VolumeSuggestions, warningCodes(r))
		}
	})
	t.Run("7.2", func(t *testing.T) {
		r := mustResolve(t, repo(t, "rails72"), ruby, "rails")
		if r.HealthPath != "/up" || process(r, "jobs") == nil || r.Processes[0].Args[2] != "good_job" {
			t.Fatalf("health %q processes %+v", r.HealthPath, r.Processes)
		}
		if r.RequiredSecrets != nil {
			t.Fatalf("the production key is committed: %+v", r.RequiredSecrets)
		}
		if len(r.VolumeSuggestions) != 1 || r.VolumeSuggestions[0].Path != "/app/storage" || slices.Contains(warningCodes(r), "sqlite-database") {
			t.Fatalf("local Active Storage: %+v %v", r.VolumeSuggestions, warningCodes(r))
		}
	})
	t.Run("8.0", func(t *testing.T) {
		r := mustResolve(t, repo(t, "rails80"), ruby, "rails")
		if !slices.Contains(warningCodes(r), "sqlite-database") || len(r.VolumeSuggestions) != 1 || r.VolumeSuggestions[0].Path != "/app/storage" {
			t.Fatalf("sqlite: %v %+v", warningCodes(r), r.VolumeSuggestions)
		}
		if r.RuntimeEnv["SOLID_QUEUE_IN_PUMA"] != "true" || process(r, "jobs") != nil {
			t.Fatalf("Solid Queue on SQLite runs in Puma: %v %+v", r.RuntimeEnv, r.Processes)
		}
		if !strings.Contains(r.Plan.StartCommand, "bin/rails db:prepare && exec") {
			t.Fatalf("start = %q", r.Plan.StartCommand)
		}
		if len(r.RequiredSecrets) != 1 {
			t.Fatalf("required = %+v", r.RequiredSecrets)
		}
	})
	t.Run("8.0 Dockerfile", func(t *testing.T) {
		r, ok := ResolveRuntime(repo(t, "rails80"), "", nil)
		if !ok || r == nil || r.Applied.Name != "rails" || r.Applied.Scope != state.PresetScopeRuntime {
			t.Fatalf("runtime preset = %+v", r)
		}
		validSummary(t, r)
		if r.ReleaseCommand != nil || r.Plan.StartCommand != "" || slices.Contains(decisionIDs(r), "release-migrations") || slices.Contains(decisionIDs(r), "start-command") {
			t.Fatalf("the Dockerfile's entrypoint prepares the database: %v %v", r.ReleaseCommand, decisionIDs(r))
		}
		if r.Port != 80 || r.HealthPath != "/up" || r.VolumeSuggestions[0].Path != "/rails/storage" {
			t.Fatalf("port %d health %q volumes %+v", r.Port, r.HealthPath, r.VolumeSuggestions)
		}
		if !slices.Contains(decisionIDs(r), "secret-key-base") || !slices.Contains(decisionIDs(r), "master-key") || !slices.Contains(warningCodes(r), "sqlite-database") {
			t.Fatalf("decisions %v warnings %v", decisionIDs(r), warningCodes(r))
		}
	})
	t.Run("Solid Queue with Postgres", func(t *testing.T) {
		f := repo(t, "rails80")
		f.Ruby.ProductionSQLite = false
		r := mustResolve(t, f, ruby, "rails")
		if p := process(r, "jobs"); p == nil || !slices.Equal(p.Args, []string{"bin/jobs"}) {
			t.Fatalf("processes = %+v", r.Processes)
		}
	})
}

func TestPhoenix(t *testing.T) {
	ex := Info{Provider: "elixir"}
	r := mustResolve(t, repo(t, "phoenix"), ex, "phoenix")
	if r.RuntimeEnv["PHX_SERVER"] != "true" || alias(r, "PHX_HOST") != state.AliasDomain {
		t.Fatalf("env %v aliases %+v", r.RuntimeEnv, r.EnvAliases)
	}
	if !slices.Equal(r.ReleaseCommand, []string{"/app/_build/prod/rel/hello/bin/hello", "eval", "Hello.Release.migrate"}) {
		t.Fatalf("release = %v", r.ReleaseCommand)
	}
	if !slices.Equal(r.GeneratedSecrets, []state.GeneratedSecret{{Name: "SECRET_KEY_BASE", Length: 64, Encoding: state.SecretAlnum}}) {
		t.Fatalf("generated = %+v", r.GeneratedSecrets)
	}
	r = mustResolve(t, repo(t, "phoenix-norelease"), ex, "phoenix")
	if r.ReleaseCommand != nil || !slices.Contains(warningCodes(r), "phoenix-no-release-module") {
		t.Fatalf("release %v warnings %v", r.ReleaseCommand, warningCodes(r))
	}
}

func TestJVMAndDotNet(t *testing.T) {
	r := mustResolve(t, repo(t, "spring-actuator"), Info{Provider: "java", Metadata: map[string]string{"javaFramework": "spring-boot"}}, "jvm")
	if r.HealthPath != "/api/actuator/health" || r.Framework != "spring-boot" {
		t.Fatalf("health %q framework %s", r.HealthPath, r.Framework)
	}
	if r.RuntimeEnv["JAVA_TOOL_OPTIONS"] != "-XX:MaxRAMPercentage=75 -XX:+ExitOnOutOfMemoryError" || r.RuntimeEnv["SERVER_FORWARD_HEADERS_STRATEGY"] != "native" {
		t.Fatalf("runtime env = %v", r.RuntimeEnv)
	}
	r = mustResolve(t, repo(t, "spring-plain"), Info{Provider: "java"}, "jvm")
	if r.HealthPath != "/" || r.Framework != "spring-boot" || slices.Contains(decisionIDs(r), "health-actuator") {
		t.Fatalf("without actuator: health %q framework %s", r.HealthPath, r.Framework)
	}
	f := repo(t, "spring-actuator")
	f.JVM.ManagementPort = true
	if r, _ := Resolve(f, Info{Provider: "java"}, "", nil); r.HealthPath != "/" {
		t.Fatalf("actuator on its own port can't be probed: %q", r.HealthPath)
	}
	if got := actuatorHealthPath(JVMFacts{ActuatorBasePath: "/"}); got != "/health" {
		t.Fatalf("base path / = %q", got)
	}

	fsys := fstest.MapFS{"Api.csproj": {Data: []byte(`<Project Sdk="Microsoft.NET.Sdk.Web"></Project>`)}}
	r = mustResolve(t, Gather(fsys, nil), Info{Provider: "dotnet"}, "dotnet")
	if r.RuntimeEnv["ASPNETCORE_FORWARDEDHEADERS_ENABLED"] != "true" || r.Framework != "aspnetcore" {
		t.Fatalf("env %v framework %s", r.RuntimeEnv, r.Framework)
	}
}

func TestPortsForDenoAndRocket(t *testing.T) {
	if DefaultPort("deno") != 8000 {
		t.Fatalf("Deno.serve listens on 8000, got %d", DefaultPort("deno"))
	}
	fsys := fstest.MapFS{"Cargo.toml": {Data: []byte("[package]\nname = \"hello\"\n\n[dependencies]\nrocket = \"0.5.1\"\nserde = { version = \"1\" }\n")}}
	r := mustResolve(t, Gather(fsys, nil), Info{Provider: "rust"}, "rocket")
	if alias(r, "ROCKET_PORT") != state.AliasPort || r.RuntimeEnv["ROCKET_ADDRESS"] != "0.0.0.0" {
		t.Fatalf("aliases %+v env %v", r.EnvAliases, r.RuntimeEnv)
	}
	axum := fstest.MapFS{"Cargo.toml": {Data: []byte("[dependencies]\naxum = \"0.7\"\n")}}
	if r, _ := Resolve(Gather(axum, nil), Info{Provider: "rust"}, "", nil); r != nil {
		t.Fatalf("no preset for axum, got %s", r.Applied.Name)
	}
}

func TestResolveRuntimeKeepsOnlyRuntimeDecisions(t *testing.T) {
	f := repo(t, "laravel10")
	f.DockerfileWorkdir = "/var/www/html"
	r, ok := ResolveRuntime(f, "", nil)
	if !ok || r == nil || r.Applied.Scope != state.PresetScopeRuntime {
		t.Fatalf("runtime preset = %+v", r)
	}
	validSummary(t, r)
	if r.ReleaseCommand != nil || r.Plan.DeployVariables != nil || r.Plan.DropBuildCommandsContaining != nil {
		t.Fatalf("image changes leaked into a Dockerfile build: %+v", r.Effect)
	}
	ids := decisionIDs(r)
	for _, id := range []string{"release-migrations", "optimize-at-start", LaravelStartScriptDecision, PhpVisitorIPDecision, PhpPcntlDecision} {
		if slices.Contains(ids, id) {
			t.Errorf("decision %s applies to the Railpack image only: %v", id, ids)
		}
	}
	for _, id := range []string{"app-key", "app-url", "queue-worker", "production-defaults", "persist-uploads"} {
		if !slices.Contains(ids, id) {
			t.Errorf("runtime decision %s missing: %v", id, ids)
		}
	}
	if r.VolumeSuggestions[0].Path != "/var/www/html/storage/app" || !slices.Contains(warningCodes(r), "file-sessions") {
		t.Fatalf("volumes %+v warnings %v", r.VolumeSuggestions, warningCodes(r))
	}
	if r, _ := ResolveRuntime(repo(t, "django"), "", nil); slices.Contains(warningCodes(r), "gunicorn-missing") || r.Plan.StartCommand != "" {
		t.Fatalf("image warnings and start commands stay out: %v %q", warningCodes(r), r.Plan.StartCommand)
	}
	if r, _ := ResolveRuntime(repo(t, "dockerfile-app"), "", nil); r != nil {
		t.Fatalf("a bare Dockerfile app gets no preset, got %s", r.Applied.Name)
	}
}
