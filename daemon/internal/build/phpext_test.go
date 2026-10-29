package build

import (
	"encoding/json"
	"os"
	"path/filepath"
	"slices"
	"strings"
	"testing"

	rpplan "github.com/railwayapp/railpack/core/plan"

	"github.com/vantezzen/launchroom/daemon/internal/state"
)

var pgsqlAndRedis = []state.PhpExtension{
	{Name: state.PhpExtPdoPgsql, For: state.EnginePostgres},
	{Name: state.PhpExtRedis, For: state.EngineRedis},
}

func TestWithPhpExtensions(t *testing.T) {
	env := map[string]string{}
	withPhpExtensions(env, nil)
	if _, set := env[railpackPhpExtensions]; set {
		t.Fatal("no extensions must leave the Railpack env alone")
	}
	withPhpExtensions(env, pgsqlAndRedis)
	if got := env[railpackPhpExtensions]; got != "pdo_pgsql,redis" {
		t.Fatalf("RAILPACK_PHP_EXTENSIONS = %q", got)
	}
	// The user's own railpackEnv value is kept, without duplicates.
	env = map[string]string{railpackPhpExtensions: "gd, redis"}
	withPhpExtensions(env, pgsqlAndRedis)
	if got := env[railpackPhpExtensions]; got != "gd,redis,pdo_pgsql" {
		t.Fatalf("RAILPACK_PHP_EXTENSIONS = %q", got)
	}
	if got := describePhpExtensions(pgsqlAndRedis); got != "pdo_pgsql (for postgres), redis (for redis)" {
		t.Fatalf("describe = %q", got)
	}
}

func TestDockerfilePhpWarning(t *testing.T) {
	if _, ok := dockerfilePhpWarning(pgsqlAndRedis, false); ok {
		t.Fatal("no warning for a Dockerfile app without composer.json")
	}
	if _, ok := dockerfilePhpWarning(nil, true); ok {
		t.Fatal("no warning without extensions")
	}
	w, ok := dockerfilePhpWarning(pgsqlAndRedis, true)
	if !ok || w.Code != "dockerfile-php-extensions" || !strings.Contains(w.Message, "pdo_pgsql (for postgres)") {
		t.Fatalf("warning %+v", w)
	}
}

func copyRepo(t *testing.T, name string) string {
	t.Helper()
	src := filepath.Join("testdata", "repos", name)
	dst := t.TempDir()
	if err := os.CopyFS(dst, os.DirFS(src)); err != nil {
		t.Fatal(err)
	}
	return dst
}

func resolveFor(t *testing.T, dir string, bs state.BuilderSpec) (*resolved, *lineSink) {
	t.Helper()
	sink := &lineSink{}
	job := &Job{Inputs: state.BuildInputs{Builder: bs}}
	r, err := (&Builder{}).resolve(dir, t.TempDir(), job, nil, sink)
	if err != nil {
		t.Fatalf("resolve: %v\n%s", err, strings.Join(sink.lines, "\n"))
	}
	return r, sink
}

// The Laravel app is SQLite-only in composer.json; connected to Postgres and
// Redis, the Railpack plan installs their drivers (docs/04 §3.1).
func TestRailpackPlanAddsPhpExtensions(t *testing.T) {
	dir := copyRepo(t, "laravel")
	r, sink := resolveFor(t, dir, state.BuilderSpec{Kind: state.BuilderAuto, RailpackVersion: RailpackVersion, PhpExtensions: pgsqlAndRedis})

	if r.summary.Provider != "php" || !slices.Equal(r.summary.PhpExtensions, pgsqlAndRedis) {
		t.Fatalf("summary provider %q, phpExtensions %+v", r.summary.Provider, r.summary.PhpExtensions)
	}
	if !slices.Contains(sink.lines, "[plan] Adds PHP extensions: pdo_pgsql (for postgres), redis (for redis)") {
		t.Fatalf("log lines %q", sink.lines)
	}
	var plan rpplan.BuildPlan
	if err := json.Unmarshal(r.plan, &plan); err != nil {
		t.Fatal(err)
	}
	// Railpack's settings are no plan secrets: the solve has no value for them.
	if slices.Contains(plan.Secrets, railpackPhpExtensions) {
		t.Fatalf("plan secrets %v", plan.Secrets)
	}
	install := extensionCommand(&plan)
	for _, want := range []string{"pdo_pgsql", "redis"} {
		if !slices.Contains(strings.Fields(install), want) {
			t.Fatalf("extensions step %q lacks %s", install, want)
		}
	}

	// Without connected databases the plan has no Postgres driver.
	r, _ = resolveFor(t, dir, state.BuilderSpec{Kind: state.BuilderAuto, RailpackVersion: RailpackVersion})
	if err := json.Unmarshal(r.plan, &plan); err != nil {
		t.Fatal(err)
	}
	if strings.Contains(extensionCommand(&plan), "pdo_pgsql") || len(r.summary.PhpExtensions) != 0 {
		t.Fatalf("pdo_pgsql without a Postgres connection: %q", extensionCommand(&plan))
	}
}

func TestDockerfilePhpAppGetsAHint(t *testing.T) {
	dir := copyRepo(t, "laravel")
	if err := os.WriteFile(filepath.Join(dir, "Dockerfile"), []byte("FROM php:8.4-cli\nCOPY . /app\nCMD [\"php\", \"/app/artisan\", \"serve\"]\n"), 0o600); err != nil {
		t.Fatal(err)
	}
	r, _ := resolveFor(t, dir, state.BuilderSpec{Kind: state.BuilderAuto, RailpackVersion: RailpackVersion, PhpExtensions: pgsqlAndRedis})
	if r.summary.Builder != state.BuilderDockerfile || len(r.summary.PhpExtensions) != 0 {
		t.Fatalf("summary %+v", r.summary)
	}
	if !slices.ContainsFunc(r.summary.Warnings, func(w state.BuildWarning) bool { return w.Code == "dockerfile-php-extensions" }) {
		t.Fatalf("warnings %+v", r.summary.Warnings)
	}
}

// extensionCommand is the install-php-extensions command of the plan's
// "extensions" step (empty when the step installs nothing).
func extensionCommand(p *rpplan.BuildPlan) string {
	for _, s := range p.Steps {
		if s.Name != "extensions" {
			continue
		}
		for _, c := range s.Commands {
			if ex, ok := c.(rpplan.ExecCommand); ok && strings.HasPrefix(ex.Cmd, "install-php-extensions") {
				return ex.Cmd
			}
		}
	}
	return ""
}

func TestDropConfigSecrets(t *testing.T) {
	p := rpplan.NewBuildPlan()
	p.Secrets = []string{"NPM_TOKEN", "RAILPACK_PHP_EXTENSIONS", "RAILPACK_INSTALL_CMD", "RAILPACK_OWN_SECRET"}
	p.Steps = []rpplan.Step{{Name: "build", Secrets: []string{"*"}}, {Name: "install", Secrets: []string{"RAILPACK_NODE_VERSION", "NPM_TOKEN"}}}
	dropConfigSecrets(p, []string{"NPM_TOKEN", "RAILPACK_OWN_SECRET"})
	if !slices.Equal(p.Secrets, []string{"NPM_TOKEN", "RAILPACK_OWN_SECRET"}) {
		t.Fatalf("plan secrets %v", p.Secrets)
	}
	if !slices.Equal(p.Steps[0].Secrets, []string{"*"}) || !slices.Equal(p.Steps[1].Secrets, []string{"NPM_TOKEN"}) {
		t.Fatalf("step secrets %v %v", p.Steps[0].Secrets, p.Steps[1].Secrets)
	}
}
