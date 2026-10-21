package presets

import (
	"fmt"
	"strings"

	"github.com/vantezzen/launchroom/daemon/internal/build/nextadapter"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// NextAdapterPackage is the adapter injected through NEXT_ADAPTER_PATH
// (vendored into the daemon, see internal/build/nextadapter).
const NextAdapterPackage = "@vantezzen/launchroom-next-adapter"

// NextAdapterDecision is the id of the Next ≥ 16.2 adapter decision. The
// build pipeline adds LAUNCHROOM_DEPLOYMENT_ID and parses the adapter's
// summary line when it is applied.
const NextAdapterDecision = "deployment-adapter"

// ---- Laravel ----

type laravel struct{}

func (laravel) Name() string { return "laravel" }
func (laravel) Version() int { return 1 }

func (laravel) Match(f Facts, i Info) bool {
	return f.Files["artisan"] && (f.ComposerRequire["laravel/framework"] || i.Metadata["phpFramework"] == "laravel")
}

func (laravel) Propose(f Facts, _ Info) Proposal {
	p := Proposal{Framework: "laravel", Port: 8080, HealthPath: "/up"}
	p.Decisions = append(p.Decisions,
		Decision{
			ID: "release-migrations", Title: "Run migrations once per deploy",
			Detail: "php artisan migrate --force runs as the release command instead of at every container start (no races between replicas and workers).",
			Effect: Effect{
				Plan:           PlanPatch{DeployVariables: map[string]string{"RAILPACK_SKIP_MIGRATIONS": "true"}},
				ReleaseCommand: []string{"php", "artisan", "migrate", "--force"},
			},
		},
		Decision{
			ID: "optimize-at-start", Title: "Cache config at container start",
			Detail: "Build-time config/route/view/event caching is removed so build secrets never land in bootstrap/cache; the start script runs artisan optimize with the runtime environment.",
			Effect: Effect{Plan: PlanPatch{DropBuildCommandsContaining: []string{
				"artisan config:cache", "artisan route:cache", "artisan view:cache", "artisan event:cache", "artisan optimize",
			}}},
		},
	)
	if f.ComposerRequire["laravel/horizon"] {
		p.Decisions = append(p.Decisions, Decision{
			ID: "horizon", Title: "Horizon worker process", Detail: "php artisan horizon",
			Effect: Effect{Processes: []state.ProcessSuggestion{{Name: "horizon", Args: []string{"php", "artisan", "horizon"}}}},
		})
	} else {
		p.Decisions = append(p.Decisions, Decision{
			ID: "queue-worker", Title: "Queue worker process", Detail: "php artisan queue:work",
			Effect: Effect{Processes: []state.ProcessSuggestion{{Name: "queue", Args: []string{"php", "artisan", "queue:work", "--tries=3"}}}},
		})
	}
	p.Decisions = append(p.Decisions, Decision{
		ID: "scheduler", Title: "Scheduler process", Detail: "php artisan schedule:work",
		Effect: Effect{Processes: []state.ProcessSuggestion{{Name: "scheduler", Args: []string{"php", "artisan", "schedule:work"}}}},
	})
	if f.ComposerRequire["laravel/reverb"] {
		p.Decisions = append(p.Decisions, Decision{
			ID: "reverb", Title: "Reverb WebSocket process", Detail: "php artisan reverb:start",
			Effect: Effect{Processes: []state.ProcessSuggestion{{Name: "reverb", Args: []string{"php", "artisan", "reverb:start", "--host=0.0.0.0", "--port=8080"}}}},
		})
	}
	p.Decisions = append(p.Decisions, Decision{ID: "health-up", Title: "Health check on /up", Effect: Effect{HealthPath: "/up"}})
	p.Warnings = append(p.Warnings, Warning{Code: "app-key", Message: "Laravel needs APP_KEY at runtime. Generate one in the service variables if it is not set."})
	return p
}

// ---- Django ----

type django struct{}

func (django) Name() string { return "django" }
func (django) Version() int { return 1 }

func (django) Match(f Facts, _ Info) bool {
	return f.Files["manage.py"] && f.PythonDeps["django"]
}

func moduleOf(dotted string) (module, attr string) {
	i := strings.LastIndex(dotted, ".")
	if i <= 0 {
		return dotted, "application"
	}
	return dotted[:i], dotted[i+1:]
}

func (django) Propose(f Facts, _ Info) Proposal {
	p := Proposal{Framework: "django", Port: 8000, HealthPath: "/"}
	d := f.Django
	project := d.Project
	var start string
	switch {
	case d.ASGI != "" && (f.PythonDeps["channels"] || f.PythonDeps["daphne"] || f.PythonDeps["uvicorn"]):
		mod, attr := moduleOf(d.ASGI)
		if f.PythonDeps["uvicorn"] {
			start = fmt.Sprintf("uvicorn %s:%s --host 0.0.0.0 --port ${PORT:-8000}", mod, attr)
		} else {
			start = fmt.Sprintf("daphne -b 0.0.0.0 -p ${PORT:-8000} %s:%s", mod, attr)
		}
	case d.WSGI != "":
		mod, attr := moduleOf(d.WSGI)
		start = fmt.Sprintf("gunicorn --bind 0.0.0.0:${PORT:-8000} %s:%s", mod, attr)
	case project != "":
		start = fmt.Sprintf("gunicorn --bind 0.0.0.0:${PORT:-8000} %s.wsgi:application", project)
	}
	if start != "" {
		p.Decisions = append(p.Decisions, Decision{
			ID: "release-migrations", Title: "Run migrations once per deploy",
			Detail: "python manage.py migrate runs as the release command; the container only starts the app server.",
			Effect: Effect{Plan: PlanPatch{StartCommand: start}, ReleaseCommand: []string{"python", "manage.py", "migrate", "--noinput"}},
		})
		if !f.PythonDeps["gunicorn"] && strings.HasPrefix(start, "gunicorn") {
			p.Warnings = append(p.Warnings, Warning{Code: "gunicorn-missing", Message: "gunicorn is not in your dependencies; add it so the production server can start."})
		}
	} else {
		p.Warnings = append(p.Warnings, Warning{Code: "wsgi-not-found", Message: "Could not find WSGI_APPLICATION; keeping Railpack's start command."})
	}
	if d.StaticRoot {
		p.Decisions = append(p.Decisions, Decision{
			ID: "collectstatic", Title: "Collect static files at build", Detail: "python manage.py collectstatic --noinput",
			Effect: Effect{Plan: PlanPatch{AppendBuildCommands: []string{"python manage.py collectstatic --noinput"}}},
		})
	} else {
		p.Warnings = append(p.Warnings, Warning{Code: "static-root-missing", Message: "STATIC_ROOT is not set, so static files are not collected. Set STATIC_ROOT and serve them with whitenoise."})
	}
	if f.PythonDeps["celery"] && project != "" {
		p.Decisions = append(p.Decisions,
			Decision{ID: "celery-worker", Title: "Celery worker process", Detail: "celery -A " + project + " worker",
				Effect: Effect{Processes: []state.ProcessSuggestion{{Name: "worker", Args: []string{"celery", "-A", project, "worker", "--loglevel=info"}}}}},
			Decision{ID: "celery-beat", Title: "Celery beat process", Detail: "celery -A " + project + " beat",
				Effect: Effect{Processes: []state.ProcessSuggestion{{Name: "beat", Args: []string{"celery", "-A", project, "beat", "--loglevel=info"}}}}},
		)
	}
	return p
}

// ---- FastAPI / Flask / Litestar ----

type pythonASGI struct{}

func (pythonASGI) Name() string { return "python-web" }
func (pythonASGI) Version() int { return 1 }

func (pythonASGI) Match(f Facts, _ Info) bool {
	if f.Files["manage.py"] {
		return false
	}
	return f.PythonDeps["fastapi"] || f.PythonDeps["flask"] || f.PythonDeps["litestar"]
}

func pyModule(file string) (module, appDir string) {
	m := strings.TrimSuffix(file, ".py")
	if strings.HasPrefix(m, "src/") {
		appDir, m = "src", strings.TrimPrefix(m, "src/")
	}
	m = strings.TrimSuffix(m, "/__init__")
	return strings.ReplaceAll(m, "/", "."), appDir
}

func (pythonASGI) Propose(f Facts, _ Info) Proposal {
	framework := "fastapi"
	switch {
	case f.PythonDeps["fastapi"]:
	case f.PythonDeps["litestar"]:
		framework = "litestar"
	default:
		framework = "flask"
	}
	p := Proposal{Framework: framework, Port: 8000, HealthPath: "/"}
	var app *PythonApp
	for i := range f.PythonApps {
		if f.PythonApps[i].Framework == framework {
			app = &f.PythonApps[i]
			break
		}
	}
	if app == nil {
		p.Warnings = append(p.Warnings, Warning{Code: "app-not-found", Message: "Could not find the " + framework + " app object (main.py, app.py, app/main.py, src/*/main.py). Set the start command explicitly."})
		return p
	}
	mod, appDir := pyModule(app.File)
	dirFlag := ""
	if appDir != "" {
		dirFlag = " --app-dir " + appDir
	}
	var start string
	switch {
	case framework == "fastapi" && containsStr(f.PythonExtras["fastapi"], "standard"):
		start = fmt.Sprintf("fastapi run %s --host 0.0.0.0 --port ${PORT:-8000}", app.File)
	case framework == "flask":
		if appDir != "" {
			dirFlag = " --chdir " + appDir
		}
		start = fmt.Sprintf("gunicorn%s --bind 0.0.0.0:${PORT:-8000} %s:%s", dirFlag, mod, app.Variable)
		if !f.PythonDeps["gunicorn"] {
			p.Warnings = append(p.Warnings, Warning{Code: "gunicorn-missing", Message: "gunicorn is not in your dependencies; add it so the production server can start."})
		}
	default:
		start = fmt.Sprintf("uvicorn%s %s:%s --host 0.0.0.0 --port ${PORT:-8000}", dirFlag, mod, app.Variable)
		if !f.PythonDeps["uvicorn"] {
			p.Warnings = append(p.Warnings, Warning{Code: "uvicorn-missing", Message: "uvicorn is not in your dependencies; add it (or fastapi[standard]) so the server can start."})
		}
	}
	p.Decisions = append(p.Decisions, Decision{
		ID: "start-command", Title: "Start " + app.File + ":" + app.Variable, Detail: start,
		Effect: Effect{Plan: PlanPatch{StartCommand: start}},
	})
	return p
}

func containsStr(list []string, s string) bool {
	for _, v := range list {
		if v == s {
			return true
		}
	}
	return false
}

// ---- Next.js ----

type nextjs struct{}

func (nextjs) Name() string { return "nextjs" }

// Version 2: the Next ≥ 16.2 deployment adapter is live.
func (nextjs) Version() int { return 2 }

func (nextjs) Match(f Facts, i Info) bool {
	_, ok := f.PackageJSON.Dep("next")
	return ok && i.Metadata["nodeIsSPA"] != "true"
}

func compactConfig(cfg string) string {
	return strings.NewReplacer(" ", "", "\t", "", "\n", "", "\r", "").Replace(cfg)
}

func hasStandalone(cfg string) bool {
	c := compactConfig(cfg)
	return strings.Contains(c, "output:'standalone'") || strings.Contains(c, `output:"standalone"`)
}

func hasExport(cfg string) bool {
	c := compactConfig(cfg)
	return strings.Contains(c, "output:'export'") || strings.Contains(c, `output:"export"`)
}

// nextStandaloneDir is the standalone output relative to the app directory.
// The adapter pins outputFileTracingRoot to the build context root (the app
// directory), so server.js sits directly in it.
const nextStandaloneDir = ".next/standalone"

// nextAdapterDecision builds the adapter decision, or a reason why it can't apply.
func nextAdapterDecision(f Facts) (*Decision, *Warning) {
	if strings.Contains(f.NextConfig, "adapterPath") {
		return nil, &Warning{Code: "own-next-adapter", Message: "next.config sets adapterPath, which takes precedence over the launchroom adapter; the image keeps the full node_modules unless your adapter produces standalone output."}
	}
	if hasExport(f.NextConfig) {
		return nil, nil
	}
	files, err := nextadapter.Files()
	if err != nil {
		return nil, &Warning{Code: "next-adapter-unavailable", Message: "The vendored launchroom Next.js adapter is damaged (" + err.Error() + "); building without it."}
	}
	return &Decision{
		ID: NextAdapterDecision, Title: "launchroom deployment adapter",
		Detail: "NEXT_ADAPTER_PATH=" + NextAdapterPackage + " " + nextadapter.Version + ": standalone output, deploymentId, public/ and .next/static copied, build manifest; the image contains only " + nextStandaloneDir + ".",
		Effect: Effect{Plan: PlanPatch{
			BuildVariables: map[string]string{
				"NEXT_ADAPTER_PATH":    nextadapter.Entry,
				"LAUNCHROOM_REPO_ROOT": "/app",
				// The CLI below finalizes explicitly (and prints the summary).
				"LAUNCHROOM_NEXT_FINALIZE": "manual",
			},
			BuildFiles:          files,
			AppendBuildCommands: []string{nextadapter.FinalizeCommand},
			StartCommand:        "node " + nextStandaloneDir + "/server.js",
			DeployOnly:          []string{nextStandaloneDir},
		}},
	}, nil
}

func (nextjs) Propose(f Facts, _ Info) Proposal {
	version, _ := f.PackageJSON.Dep("next")
	p := Proposal{Framework: "next", Port: 3000, HealthPath: "/"}
	p.Decisions = append(p.Decisions, Decision{
		ID: "bind-all-interfaces", Title: "Listen on all interfaces", Detail: "HOSTNAME=0.0.0.0 (Docker sets HOSTNAME to the container id).",
		Effect: Effect{RuntimeEnv: map[string]string{"HOSTNAME": "0.0.0.0"}},
	}, Decision{
		ID: "telemetry-off", Title: "Disable Next.js telemetry", Detail: "NEXT_TELEMETRY_DISABLED=1 at build and runtime.",
		Effect: Effect{
			RuntimeEnv: map[string]string{"NEXT_TELEMETRY_DISABLED": "1"},
			Plan:       PlanPatch{BuildVariables: map[string]string{"NEXT_TELEMETRY_DISABLED": "1"}},
		},
	})
	var adapter *Decision
	if atLeast(version, 16, 2) {
		var w *Warning
		adapter, w = nextAdapterDecision(f)
		if w != nil {
			p.Warnings = append(p.Warnings, *w)
		}
	}
	switch {
	case adapter != nil:
		p.Decisions = append(p.Decisions, *adapter)
	case hasStandalone(f.NextConfig):
		p.Decisions = append(p.Decisions, Decision{
			ID: "standalone", Title: "Standalone output", Detail: "Copies public/ and .next/static into .next/standalone and starts node .next/standalone/server.js.",
			Effect: Effect{Plan: PlanPatch{
				AppendBuildCommands: []string{
					"mkdir -p .next/standalone/.next && cp -r .next/static .next/standalone/.next/static && if [ -d public ]; then cp -r public .next/standalone/public; fi",
				},
				StartCommand: "node .next/standalone/server.js",
			}},
		})
	default:
		p.Warnings = append(p.Warnings, Warning{Code: "standalone-off", Message: "Set output: 'standalone' in next.config to ship a much smaller image; launchroom then copies the static assets for you."})
	}
	if !containsStr(f.BuildEnv, "NEXT_SERVER_ACTIONS_ENCRYPTION_KEY") {
		p.Warnings = append(p.Warnings, Warning{Code: "server-actions-key", Message: "Add a generated NEXT_SERVER_ACTIONS_ENCRYPTION_KEY build variable so server actions keep working across replicas and redeploys."})
	}
	p.Warnings = append(p.Warnings, Warning{Code: "isr-cache-warning", Message: "With more than one replica, ISR and revalidateTag need a shared cache handler (e.g. Redis)."})
	return p
}

// ---- Static SPA (Vite, CRA, Angular, Astro static, Next export) ----

type staticSPA struct{}

func (staticSPA) Name() string { return "static-spa" }
func (staticSPA) Version() int { return 1 }

func (staticSPA) Match(_ Facts, i Info) bool { return i.Metadata["nodeIsSPA"] == "true" }

func (staticSPA) Propose(f Facts, i Info) Proposal {
	framework := i.Metadata["nodeRuntime"]
	if framework == "" || framework == "node" {
		framework = "static"
		for _, d := range []string{"vite", "react-scripts", "@angular/core", "astro", "next"} {
			if _, ok := f.PackageJSON.Dep(d); ok {
				framework = strings.TrimPrefix(strings.Split(d, "/")[0], "@")
				break
			}
		}
	}
	if framework == "react-scripts" {
		framework = "cra"
	}
	return Proposal{
		Framework: framework, Port: 8080, HealthPath: "/",
		Decisions: []Decision{{
			ID: "spa-fallback", Title: "Serve as a static site with SPA fallback",
			Detail: "Caddy serves the build output and falls back to index.html (set RAILPACK_NO_SPA=1 to disable, RAILPACK_SPA_OUTPUT_DIR to change the directory).",
		}},
	}
}

// ---- Node servers (Express, Fastify, Hono, Koa, Nest, plain Node) ----

type nodeServer struct{}

func (nodeServer) Name() string { return "node-server" }
func (nodeServer) Version() int { return 1 }

func (nodeServer) Match(f Facts, i Info) bool {
	return f.PackageJSON != nil && (i.Provider == "" || i.Provider == "node")
}

func (nodeServer) Propose(f Facts, _ Info) Proposal {
	framework := "node"
	for _, d := range []string{"express", "fastify", "hono", "koa", "@nestjs/core"} {
		if _, ok := f.PackageJSON.Dep(d); ok {
			framework = strings.TrimPrefix(strings.Split(d, "/")[0], "@")
			break
		}
	}
	if framework == "nestjs" {
		framework = "nest"
	}
	p := Proposal{Framework: framework, Port: 3000, HealthPath: "/"}
	p.Decisions = append(p.Decisions, Decision{
		ID: "port-contract", Title: "Listen on $PORT", Detail: "launchroom sets PORT=3000; your server must listen on process.env.PORT.",
		Effect: Effect{Port: 3000},
	})
	for _, l := range f.Listeners {
		p.Warnings = append(p.Warnings, Warning{
			Code:    "hardcoded-port",
			Message: fmt.Sprintf("%s calls listen(%s) without reading process.env.PORT; use process.env.PORT so the platform can route to it.", l.File, l.Port),
		})
		break
	}
	_, hasStart := f.PackageJSON.Scripts["start"]
	if !hasStart && f.PackageJSON.Main == "" && !f.Files["index.js"] && !f.Files["index.ts"] && !f.Files["server.js"] {
		p.Warnings = append(p.Warnings, Warning{Code: "no-start-script", Message: "package.json has no \"start\" script or \"main\"; add one or set a start command."})
	}
	return p
}
