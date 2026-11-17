package presets

import (
	"maps"
	"os"
	"path/filepath"
	"slices"
	"strings"
	"testing"
	"testing/fstest"

	"github.com/vantezzen/launchroom/daemon/internal/build/nextadapter"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

func repo(t *testing.T, name string, buildEnv ...string) Facts {
	t.Helper()
	dir := filepath.Join("..", "testdata", "repos", name)
	if _, err := os.Stat(dir); err != nil {
		t.Fatal(err)
	}
	return Gather(os.DirFS(dir), buildEnv)
}

func decisionIDs(r *Result) []string {
	var out []string
	for _, d := range r.Applied.Decisions {
		out = append(out, string(d.ID))
	}
	return out
}

func warningCodes(r *Result) []string {
	var out []string
	for _, w := range r.Warnings {
		out = append(out, string(w.Code))
	}
	return out
}

func TestPresetTable(t *testing.T) {
	cases := []struct {
		repo     string
		info     Info
		buildEnv []string
		preset   string
		check    func(t *testing.T, r *Result)
	}{
		{
			repo: "nextjs-standalone", info: Info{Provider: "node", Metadata: map[string]string{"nodeRuntime": "next"}}, preset: "nextjs",
			check: func(t *testing.T, r *Result) {
				if r.Plan.StartCommand != "node .next/standalone/server.js" || len(r.Plan.AppendBuildCommands) != 1 ||
					!strings.Contains(r.Plan.AppendBuildCommands[0], "cp -r .next/static .next/standalone/.next/static") {
					t.Fatalf("standalone overlay missing: %+v", r.Plan)
				}
				if r.RuntimeEnv["HOSTNAME"] != "0.0.0.0" || r.RuntimeEnv["NEXT_TELEMETRY_DISABLED"] != "1" || r.Plan.BuildVariables["NEXT_TELEMETRY_DISABLED"] != "1" {
					t.Fatalf("runtime env = %v build = %v", r.RuntimeEnv, r.Plan.BuildVariables)
				}
				if r.Port != 3000 || r.Framework != "next" {
					t.Fatalf("port %d framework %s", r.Port, r.Framework)
				}
				// ^15.3.0 without a lockfile is below 15.4: no adapter, the deployment id instead.
				if ids := decisionIDs(r); !slices.Contains(ids, "server-actions-key") || !slices.Contains(ids, NextDeploymentIDDecision) || slices.Contains(ids, NextAdapterDecision) {
					t.Fatalf("decisions = %v", ids)
				}
			},
		},
		{
			repo: "nextjs-plain", info: Info{Provider: "node"}, buildEnv: []string{"NEXT_SERVER_ACTIONS_ENCRYPTION_KEY"}, preset: "nextjs",
			check: func(t *testing.T, r *Result) {
				// Next 15.4 to 16.1 read NEXT_ADAPTER_PATH too.
				if ids := decisionIDs(r); !slices.Contains(ids, NextAdapterDecision) || slices.Contains(ids, NextDeploymentIDDecision) {
					t.Fatalf("next 16.1 gets the adapter: %v", ids)
				}
				if slices.Contains(decisionIDs(r), "server-actions-key") || r.GeneratedSecrets != nil {
					t.Fatalf("the app sets its own key: %v %+v", decisionIDs(r), r.GeneratedSecrets)
				}
				if codes := warningCodes(r); slices.Contains(codes, "standalone-off") {
					t.Fatalf("warnings = %v", codes)
				}
			},
		},
		{
			repo: "nextjs-adapter", info: Info{Provider: "node", Metadata: map[string]string{"nodeRuntime": "next"}}, preset: "nextjs",
			check: func(t *testing.T, r *Result) {
				if !slices.Contains(decisionIDs(r), NextAdapterDecision) || slices.Contains(decisionIDs(r), "standalone") {
					t.Fatalf("decisions = %v", decisionIDs(r))
				}
				if r.Applied.Version != 3 {
					t.Fatalf("nextjs preset version %d", r.Applied.Version)
				}
				bv := r.Plan.BuildVariables
				if bv["NEXT_ADAPTER_PATH"] != nextadapter.Entry || bv["LAUNCHROOM_REPO_ROOT"] != "/app" || bv["LAUNCHROOM_NEXT_FINALIZE"] != "manual" || bv["NEXT_TELEMETRY_DISABLED"] != "1" {
					t.Fatalf("build variables = %v", bv)
				}
				if _, ok := bv["LAUNCHROOM_DEPLOYMENT_ID"]; ok {
					t.Fatal("the deployment id is a build input, not a preset decision")
				}
				if r.Plan.BuildFiles[nextadapter.Entry] == "" || len(r.Plan.BuildFiles) != len(nextadapter.Paths()) {
					t.Fatalf("adapter files not written into the build: %v", slices.Collect(maps.Keys(r.Plan.BuildFiles)))
				}
				if !slices.Equal(r.Plan.AppendBuildCommands, []string{nextadapter.FinalizeCommand}) || r.Plan.StartCommand != "node .next/standalone/server.js" {
					t.Fatalf("plan = %v / %q", r.Plan.AppendBuildCommands, r.Plan.StartCommand)
				}
				if !slices.Equal(r.Plan.DeployOnly, []string{".next/standalone"}) {
					t.Fatalf("deploy only = %v", r.Plan.DeployOnly)
				}
				if r.RuntimeEnv["HOSTNAME"] != "0.0.0.0" {
					t.Fatalf("runtime env = %v", r.RuntimeEnv)
				}
				if codes := warningCodes(r); slices.Contains(codes, "standalone-off") || slices.Contains(codes, "server-actions-key") || !slices.Contains(codes, "isr-cache-warning") {
					t.Fatalf("warnings = %v", codes)
				}
			},
		},
		{
			// The repo's own site/ (D52): a plain Next.js 16 app (fumadocs MDX
			// wrapper, no output, no custom server) gets the adapter.
			repo: "launchroom-site", info: Info{Provider: "node", Metadata: map[string]string{"nodeRuntime": "next"}}, preset: "nextjs",
			check: func(t *testing.T, r *Result) {
				if !slices.Contains(decisionIDs(r), NextAdapterDecision) || slices.Contains(decisionIDs(r), "standalone") {
					t.Fatalf("decisions = %v", decisionIDs(r))
				}
				if r.Plan.StartCommand != "node .next/standalone/server.js" || !slices.Equal(r.Plan.DeployOnly, []string{".next/standalone"}) {
					t.Fatalf("plan = %q %v", r.Plan.StartCommand, r.Plan.DeployOnly)
				}
				if slices.Contains(warningCodes(r), "own-next-adapter") || slices.Contains(warningCodes(r), "standalone-off") {
					t.Fatalf("warnings = %v", warningCodes(r))
				}
			},
		},
		{
			repo: "nextjs-own-adapter", info: Info{Provider: "node"}, preset: "nextjs",
			check: func(t *testing.T, r *Result) {
				if slices.Contains(decisionIDs(r), NextAdapterDecision) || !slices.Contains(decisionIDs(r), "standalone") {
					t.Fatalf("a user adapterPath wins over NEXT_ADAPTER_PATH; decisions = %v", decisionIDs(r))
				}
				if !slices.Contains(warningCodes(r), "own-next-adapter") {
					t.Fatalf("warnings = %v", warningCodes(r))
				}
			},
		},
		{
			repo: "laravel", info: Info{Provider: "php", Metadata: map[string]string{"phpFramework": "laravel"}}, preset: "laravel",
			check: func(t *testing.T, r *Result) {
				if !slices.Equal(r.ReleaseCommand, []string{"php", "artisan", "migrate", "--force"}) {
					t.Fatalf("release = %v", r.ReleaseCommand)
				}
				if r.Plan.DeployVariables["RAILPACK_SKIP_MIGRATIONS"] != "true" || !slices.Contains(r.Plan.DropBuildCommandsContaining, "artisan config:cache") {
					t.Fatalf("plan = %+v", r.Plan)
				}
				var names []string
				for _, p := range r.Processes {
					names = append(names, string(p.Name))
				}
				if !slices.Equal(names, []string{"horizon", "scheduler"}) {
					t.Fatalf("processes = %v (horizon is installed, so no plain queue worker)", names)
				}
				if r.HealthPath != "/up" || r.Port != 8080 {
					t.Fatalf("health %s port %d", r.HealthPath, r.Port)
				}
				if !slices.Equal(r.GeneratedSecrets, []state.GeneratedSecret{{Name: "APP_KEY", Length: 32, Encoding: state.SecretAlnum}}) {
					t.Fatalf("generated secrets = %+v", r.GeneratedSecrets)
				}
				if !slices.Contains(decisionIDs(r), "app-key") || slices.Contains(warningCodes(r), "app-key") {
					t.Fatalf("APP_KEY is a removable decision, not a warning: decisions %v warnings %v", decisionIDs(r), warningCodes(r))
				}
			},
		},
		{
			repo: "vite-spa", info: Info{Provider: "node", Metadata: map[string]string{"nodeIsSPA": "true", "nodeRuntime": "vite"}}, preset: "static-spa",
			check: func(t *testing.T, r *Result) {
				if r.Framework != "vite" || r.Port != 8080 || r.Plan.StartCommand != "" {
					t.Fatalf("framework %s port %d start %q", r.Framework, r.Port, r.Plan.StartCommand)
				}
			},
		},
		{
			repo: "express", info: Info{Provider: "node"}, preset: "node-server",
			check: func(t *testing.T, r *Result) {
				if r.Framework != "express" || r.Port != 3000 {
					t.Fatalf("framework %s port %d", r.Framework, r.Port)
				}
				if !slices.Contains(warningCodes(r), "hardcoded-port") {
					t.Fatalf("listen(3000) not flagged: %v", warningCodes(r))
				}
			},
		},
		{
			repo: "node-hello", info: Info{Provider: "node"}, preset: "node-server",
			check: func(t *testing.T, r *Result) {
				if len(r.Warnings) != 0 {
					t.Fatalf("node-hello reads PORT and has a start script; warnings = %v", warningCodes(r))
				}
			},
		},
		{
			repo: "django", info: Info{Provider: "python"}, preset: "django",
			check: func(t *testing.T, r *Result) {
				if r.Plan.StartCommand != "gunicorn --bind 0.0.0.0:${PORT:-8000} mysite.wsgi:application" {
					t.Fatalf("start = %q", r.Plan.StartCommand)
				}
				if !slices.Equal(r.ReleaseCommand, []string{"python", "manage.py", "migrate", "--noinput"}) {
					t.Fatalf("release = %v", r.ReleaseCommand)
				}
				if !slices.Equal(r.Plan.AppendBuildCommands, []string{"python manage.py collectstatic --noinput"}) {
					t.Fatalf("collectstatic missing: %v", r.Plan.AppendBuildCommands)
				}
				if len(r.Processes) != 2 || r.Processes[0].Args[2] != "mysite" {
					t.Fatalf("celery processes = %+v", r.Processes)
				}
				if !slices.Equal(r.GeneratedSecrets, []state.GeneratedSecret{{Name: "SECRET_KEY", Length: 50, Encoding: state.SecretAlnum}}) {
					t.Fatalf("generated secrets = %+v (settings read SECRET_KEY from the environment)", r.GeneratedSecrets)
				}
			},
		},
		{
			repo: "fastapi", info: Info{Provider: "python"}, preset: "python-web",
			check: func(t *testing.T, r *Result) {
				if r.Plan.StartCommand != "fastapi run app/main.py --host 0.0.0.0 --port ${PORT:-8000}" || r.Framework != "fastapi" {
					t.Fatalf("start = %q", r.Plan.StartCommand)
				}
			},
		},
		{
			repo: "flask-src", info: Info{Provider: "python"}, preset: "python-web",
			check: func(t *testing.T, r *Result) {
				if r.Plan.StartCommand != "gunicorn --chdir src --bind 0.0.0.0:${PORT:-8000} shop.main:app" {
					t.Fatalf("start = %q", r.Plan.StartCommand)
				}
				if !slices.Contains(warningCodes(r), "gunicorn-missing") {
					t.Fatal("missing gunicorn not flagged")
				}
			},
		},
	}
	for _, c := range cases {
		t.Run(c.repo, func(t *testing.T) {
			f := repo(t, c.repo, c.buildEnv...)
			r, ok := Resolve(f, c.info, "", nil)
			if !ok || r == nil {
				t.Fatalf("no preset matched")
			}
			if string(r.Applied.Name) != c.preset {
				t.Fatalf("preset = %s, want %s", r.Applied.Name, c.preset)
			}
			c.check(t, r)
		})
	}
}

func TestDisabledDecisionsAndForcing(t *testing.T) {
	f := repo(t, "laravel")
	info := Info{Provider: "php"}
	r, _ := Resolve(f, info, "", []string{"release-migrations", "app-key"})
	if r.ReleaseCommand != nil || r.Plan.DeployVariables["RAILPACK_SKIP_MIGRATIONS"] != "" {
		t.Fatalf("disabled decision still applied: %+v", r.Effect)
	}
	if slices.Contains(decisionIDs(r), "release-migrations") || slices.Contains(decisionIDs(r), "app-key") {
		t.Fatal("disabled decision still listed")
	}
	if r.GeneratedSecrets != nil {
		t.Fatalf("removed app-key decision still generates %+v", r.GeneratedSecrets)
	}
	if r.HealthPath != "/up" {
		t.Fatal("defaults must survive removed decisions")
	}
	if r, ok := Resolve(f, info, "none", nil); !ok || r != nil {
		t.Fatal("preset none must disable presets")
	}
	if _, ok := Resolve(f, info, "rails-magic", nil); ok {
		t.Fatal("unknown forced preset accepted")
	}
	if r, ok := Resolve(repo(t, "node-hello"), Info{Provider: "node"}, "nextjs", nil); !ok || r.Applied.Name != "nextjs" {
		t.Fatal("forced preset not applied")
	}
	if r, _ := Resolve(repo(t, "dockerfile-app"), Info{}, "", nil); r != nil {
		t.Fatalf("no preset should match a bare Dockerfile repo, got %s", r.Applied.Name)
	}
}

func TestNextAdapterDecisionCanBeRemoved(t *testing.T) {
	f := repo(t, "nextjs-adapter")
	r, _ := Resolve(f, Info{Provider: "node"}, "", []string{NextAdapterDecision})
	if r.Plan.StartCommand != "" || len(r.Plan.BuildFiles) != 0 || r.Plan.DeployOnly != nil || r.Plan.BuildVariables["NEXT_ADAPTER_PATH"] != "" {
		t.Fatalf("removed adapter decision still applied: %+v", r.Plan)
	}
	// Next 16.2 apps with output: 'export' are static sites (static-spa preset); the adapter stays out.
	if d, w := nextAdapterDecision(Facts{NextConfig: "export default {\n  output: \"export\",\n}"}); d != nil || w != nil {
		t.Fatalf("export: %+v %+v", d, w)
	}
}

func TestGatherEnvRefs(t *testing.T) {
	cases := map[string][]string{
		"nextjs-standalone": {"NEXT_PUBLIC_API_URL"},
		"vite-spa":          {"VITE_API_URL"},
		"fastapi":           {"DATABASE_URL"},
		"laravel":           {"APP_NAME"},
		"node-hello":        {"HOSTNAME", "PORT"},
	}
	for name, want := range cases {
		if got := repo(t, name).EnvRefs; !slices.Equal(got, want) {
			t.Errorf("%s: env refs = %v, want %v", name, got, want)
		}
	}
}

func TestDjangoSecretKeyEnv(t *testing.T) {
	for settings, want := range map[string]string{
		`SECRET_KEY = os.environ["DJANGO_SECRET_KEY"]`:                  "DJANGO_SECRET_KEY",
		`SECRET_KEY = os.getenv('SECRET_KEY')`:                          "SECRET_KEY",
		`SECRET_KEY = env("DJANGO_SECRET_KEY")`:                         "DJANGO_SECRET_KEY",
		`SECRET_KEY = env.str("SECRET_KEY", default="x")`:               "SECRET_KEY",
		`SECRET_KEY = config("SECRET_KEY")`:                             "SECRET_KEY",
		`SECRET_KEY = "django-insecure-abc"`:                            "",
		`# SECRET_KEY = os.environ["OLD"]` + "\nSECRET_KEY = 'literal'": "",
	} {
		fsys := fstest.MapFS{
			"manage.py":        {Data: []byte(`os.environ.setdefault("DJANGO_SETTINGS_MODULE", "site.settings")`)},
			"site/settings.py": {Data: []byte(settings + "\nWSGI_APPLICATION = \"site.wsgi.application\"\n")},
			"requirements.txt": {Data: []byte("django\ngunicorn\n")},
		}
		f := Gather(fsys, nil)
		if f.Django.SecretKeyEnv != want {
			t.Errorf("%s: SecretKeyEnv = %q, want %q", settings, f.Django.SecretKeyEnv, want)
		}
		r, _ := Resolve(f, Info{Provider: "python"}, "", nil)
		if got := slices.Contains(decisionIDs(r), "secret-key"); got != (want != "") {
			t.Errorf("%s: secret-key decision = %v", settings, got)
		}
	}
}

func TestDefaultPortAndVersions(t *testing.T) {
	if DefaultPort("node") != 3000 || DefaultPort("python") != 8000 || DefaultPort("golang") != 8080 {
		t.Fatal("default ports")
	}
	for v, want := range map[string]bool{"^16.2.1": true, "16.10.0": true, "~16.1.0": false, "15.9": false, "latest": false, "17": true} {
		if atLeast(v, 16, 2) != want {
			t.Errorf("atLeast(%q) != %v", v, want)
		}
	}
}
