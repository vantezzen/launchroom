package presets

import (
	"fmt"
	"path"
	"strings"

	"github.com/vantezzen/launchroom/daemon/internal/state"
)

type django struct{}

func (django) Name() string { return "django" }

// Version 3: proxy trust, allowed hosts (alias or settings shim, OD11),
// WhiteNoise, SQLite hints and the Celery stop timeout. Version 2: SECRET_KEY
// is generated when the settings read it from the environment.
func (django) Version() int { return 3 }

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

func (django) Propose(f Facts, i Info) Proposal {
	// manage.py is at the app's root (Match), the image's working directory.
	p := Proposal{Framework: "django", Port: 8000, HealthPath: "/", Commands: djangoCommands()}
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
	if d.SecretKeyEnv != "" {
		p.Decisions = append(p.Decisions, Decision{
			ID: "secret-key", Title: "Generate " + d.SecretKeyEnv,
			Detail: "Your settings read SECRET_KEY from " + d.SecretKeyEnv + ". When the service has none, launchroom creates one as a secret variable per environment, kept across deploys.",
			Effect: Effect{GeneratedSecrets: []state.GeneratedSecret{{Name: state.EnvName(d.SecretKeyEnv), Length: 50, Encoding: state.SecretAlnum}}},
		})
	}
	if f.PythonDeps["celery"] && project != "" {
		p.Decisions = append(p.Decisions,
			Decision{ID: "celery-worker", Title: "Celery worker process", Detail: "celery -A " + project + " worker",
				Effect: Effect{Processes: []state.ProcessSuggestion{{Name: "worker", Args: []string{"celery", "-A", project, "worker", "--loglevel=info"}, StopTimeout: "60s"}}}},
			Decision{ID: "celery-beat", Title: "Celery beat process", Detail: "celery -A " + project + " beat",
				Effect: Effect{Processes: []state.ProcessSuggestion{{Name: "beat", Args: []string{"celery", "-A", project, "beat", "--loglevel=info"}}}}},
		)
	}
	p.Decisions = append(p.Decisions, trustProxyDecision)
	p.Decisions = append(p.Decisions, djangoHosts(f, i)...)
	if d.StaticRoot && !d.WhiteNoise && !d.RemoteStatic && !f.PythonDeps["whitenoise"] {
		p.Warnings = append(p.Warnings, Warning{Code: "static-files-unserved", Runtime: true,
			Message: "With DEBUG off, Django doesn't serve static files itself. Add whitenoise to your dependencies and launchroom adds its middleware."})
	}
	if d.SQLite {
		p.Warnings = append(p.Warnings, Warning{Code: "sqlite-database", Runtime: true,
			Message: "Your settings use SQLite. Its file is inside the container and is reset on every deploy. Connect a database to keep your data."})
		if dir := d.SQLiteDir; dir != "" && !strings.HasPrefix(dir, "..") && !strings.HasPrefix(dir, "/") && !i.Dockerfile {
			p.Decisions = append(p.Decisions, Decision{
				ID: "sqlite-volume", Title: "Suggest a volume for the SQLite database",
				Effect: Effect{VolumeSuggestions: []state.VolumeSuggestion{{
					Path:   state.ContainerPath("/app/" + dir),
					Reason: "The SQLite database in " + dir + "/ is kept across deploys. The app then runs on one server.",
				}}},
			})
		}
	}
	return p
}

// trustProxyDecision lets gunicorn and uvicorn take the scheme and client
// address from launchroom's proxy headers (both read FORWARDED_ALLOW_IPS;
// gunicorn 22+ and uvicorn 0.30+ accept CIDRs).
var trustProxyDecision = Decision{
	ID: "trust-proxy", Title: "Trust launchroom's proxy",
	Detail: "FORWARDED_ALLOW_IPS names the addresses requests arrive from, so the app knows the request was HTTPS and who sent it.",
	Effect: Effect{EnvAliases: []state.EnvAlias{{Name: "FORWARDED_ALLOW_IPS", From: state.AliasTrustedProxyCIDRs}}},
}

// DjangoShimPath is where the Django settings shim is added to the image (OD11).
const DjangoShimPath = "/opt/launchroom/python/launchroom_django.py"

// djangoShim imports the app's own settings module and adds this
// environment's domains. It's a constant: everything variable comes from the
// environment at runtime.
const djangoShim = `# Added by launchroom: loads your settings, then adds this environment's
# domains to ALLOWED_HOSTS and CSRF_TRUSTED_ORIGINS. Remove the decision in
# the service's build settings to stop using it.
import os as _os
from importlib import import_module as _import_module

_settings = _import_module(_os.environ["LAUNCHROOM_DJANGO_SETTINGS"])
for _name in dir(_settings):
    if _name.isupper():
        globals()[_name] = getattr(_settings, _name)


def _launchroom_list(name):
    return [v.strip() for v in _os.environ.get(name, "").split(",") if v.strip()]


_hosts = _launchroom_list("LAUNCHROOM_ALLOWED_HOSTS")
if _hosts:
    _have = list(globals().get("ALLOWED_HOSTS") or [])
    if "*" not in _have:
        ALLOWED_HOSTS = _have + [h for h in _hosts if h not in _have]
_origins = _launchroom_list("LAUNCHROOM_CSRF_TRUSTED_ORIGINS")
if _origins:
    _have = list(globals().get("CSRF_TRUSTED_ORIGINS") or [])
    CSRF_TRUSTED_ORIGINS = _have + [o for o in _origins if o not in _have]
if _hosts and "SECURE_PROXY_SSL_HEADER" not in globals():
    SECURE_PROXY_SSL_HEADER = ("HTTP_X_FORWARDED_PROTO", "https")
if _os.environ.get("LAUNCHROOM_WHITENOISE") == "1":
    _middleware = list(globals().get("MIDDLEWARE") or [])
    if "whitenoise.middleware.WhiteNoiseMiddleware" not in _middleware:
        _security = "django.middleware.security.SecurityMiddleware"
        _at = _middleware.index(_security) + 1 if _security in _middleware else 0
        _middleware.insert(_at, "whitenoise.middleware.WhiteNoiseMiddleware")
        MIDDLEWARE = _middleware
`

// djangoShimEffect switches the app to the settings shim. A
// DJANGO_SETTINGS_MODULE the user set wins, and the shim is then unused.
func djangoShimEffect(settings string) Effect {
	return Effect{
		Plan: PlanPatch{DeployFiles: map[string]string{DjangoShimPath: djangoShim}},
		RuntimeEnv: map[string]string{
			"DJANGO_SETTINGS_MODULE":     "launchroom_django",
			"LAUNCHROOM_DJANGO_SETTINGS": settings,
			"PYTHONPATH":                 path.Dir(DjangoShimPath),
		},
	}
}

// djangoHosts makes Django accept the environment's domains: through the
// variables the settings already read, else through the settings shim. The
// shim also adds WhiteNoise's middleware when whitenoise is installed but not
// configured. The shim needs a file in the image, so Dockerfile builds only
// get the aliases.
func djangoHosts(f Facts, i Info) []Decision {
	d := f.Django
	var out []Decision
	shim := d.SettingsModule != "" && !i.Dockerfile
	switch {
	case d.AllowedHostsEnv != "":
		aliases := []state.EnvAlias{{Name: state.EnvName(d.AllowedHostsEnv), From: state.AliasAllowedHostsList}}
		detail := "Your settings read ALLOWED_HOSTS from " + d.AllowedHostsEnv + ". launchroom sets it to this environment's domains. Your own value replaces it."
		if d.CSRFOriginsEnv != "" {
			aliases = append(aliases, state.EnvAlias{Name: state.EnvName(d.CSRFOriginsEnv), From: state.AliasPublicURLs})
			detail = "Your settings read ALLOWED_HOSTS from " + d.AllowedHostsEnv + " and CSRF_TRUSTED_ORIGINS from " + d.CSRFOriginsEnv + ". launchroom sets them to this environment's domains. Your own values replace them."
		}
		out = append(out, Decision{ID: "allowed-hosts", Title: "Allow this environment's domains", Detail: detail, Effect: Effect{EnvAliases: aliases}})
	case d.AllowedHostsAny:
	case shim:
		e := djangoShimEffect(d.SettingsModule)
		e.EnvAliases = []state.EnvAlias{
			{Name: "LAUNCHROOM_ALLOWED_HOSTS", From: state.AliasAllowedHostsList},
			{Name: "LAUNCHROOM_CSRF_TRUSTED_ORIGINS", From: state.AliasPublicURLs},
		}
		out = append(out, Decision{
			ID: "allowed-hosts", Title: "Allow this environment's domains",
			Detail: "ALLOWED_HOSTS is fixed in your settings, so launchroom loads " + d.SettingsModule + " through a small settings module that adds this environment's domains to ALLOWED_HOSTS and CSRF_TRUSTED_ORIGINS. Your code is unchanged.",
			Effect: e,
		})
	}
	if d.StaticRoot && !d.WhiteNoise && !d.RemoteStatic {
		if f.PythonDeps["whitenoise"] && shim {
			e := djangoShimEffect(d.SettingsModule)
			e.RuntimeEnv["LAUNCHROOM_WHITENOISE"] = "1"
			out = append(out, Decision{
				ID: "whitenoise", Title: "Serve static files with WhiteNoise",
				Detail: "whitenoise is installed but not in MIDDLEWARE, so launchroom adds its middleware after SecurityMiddleware through the settings module.",
				Effect: e,
			})
		}
	}
	return out
}

// pythonASGI covers FastAPI, Flask and Litestar.
type pythonASGI struct{}

func (pythonASGI) Name() string { return "python-web" }

// Version 2: FORWARDED_ALLOW_IPS.
func (pythonASGI) Version() int { return 2 }

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
	}, trustProxyDecision)
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
