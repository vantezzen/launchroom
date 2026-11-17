package presets

import (
	"bufio"
	"io/fs"
	"path"
	"regexp"
	"slices"
	"strings"
)

// DjangoFacts are found in the project's settings module.
type DjangoFacts struct {
	// Project is the package containing settings.py ("mysite").
	Project string
	// WSGI and ASGI are WSGI_APPLICATION / ASGI_APPLICATION ("mysite.wsgi.application").
	WSGI       string
	ASGI       string
	StaticRoot bool
	// SecretKeyEnv is the variable SECRET_KEY is read from ("DJANGO_SECRET_KEY"), if the settings read it from the environment.
	SecretKeyEnv string
	// SettingsModule is the default DJANGO_SETTINGS_MODULE of the WSGI/ASGI
	// module (the production entry point), else of manage.py.
	SettingsModule string
	// AllowedHostsEnv and CSRFOriginsEnv are the variables ALLOWED_HOSTS and
	// CSRF_TRUSTED_ORIGINS are read from, if the settings read them from the environment.
	AllowedHostsEnv string
	CSRFOriginsEnv  string
	// AllowedHostsAny is set when ALLOWED_HOSTS is ["*"].
	AllowedHostsAny bool
	// WhiteNoise is set when the settings already mention whitenoise.
	WhiteNoise bool
	// RemoteStatic is set when static files go to a storage backend (django-storages).
	RemoteStatic bool
	// SQLite is set when a database uses the sqlite3 engine; SQLiteDir is
	// the folder of its file relative to BASE_DIR ("" for BASE_DIR itself).
	SQLite    bool
	SQLiteDir string
}

// PythonApp is an app object like `app = FastAPI()` in a module.
type PythonApp struct {
	File      string // "app/main.py"
	Variable  string // "app"
	Framework string // "fastapi", "flask", "litestar"
}

var (
	reReqLine    = regexp.MustCompile(`^\s*([A-Za-z0-9][A-Za-z0-9._-]*)\s*(?:\[([A-Za-z0-9_,\s-]+)\])?`)
	rePyprojDeps = regexp.MustCompile(`["']([A-Za-z0-9][A-Za-z0-9._-]*)(?:\[([A-Za-z0-9_,\s-]+)\])?\s*(?:[<>=!~;].*)?["']`)
	rePipfileDep = regexp.MustCompile(`^\s*["']?([A-Za-z0-9][A-Za-z0-9._-]*)["']?\s*=`)
)

var rePEP503 = regexp.MustCompile(`[-_.]+`)

// PythonName normalises a Python package name (PEP 503: runs of "-", "_"
// and "." become one "-", lower case), so "Django_Rest.Framework" and
// "django-rest-framework" are the same package.
func PythonName(n string) string {
	return strings.ToLower(rePEP503.ReplaceAllString(strings.TrimSpace(n), "-"))
}

func addPyDep(f *Facts, name, extras string) {
	n := PythonName(name)
	f.PythonDeps[n] = true
	for _, e := range strings.Split(extras, ",") {
		if e = strings.TrimSpace(strings.ToLower(e)); e != "" && !slices.Contains(f.PythonExtras[n], e) {
			f.PythonExtras[n] = append(f.PythonExtras[n], e)
		}
	}
}

func gatherPythonDeps(fsys fs.FS, f *Facts) {
	for _, n := range []string{"requirements.txt", "requirements/base.txt", "requirements/prod.txt", "requirements/production.txt"} {
		s, ok := readSmall(fsys, n)
		if !ok {
			continue
		}
		sc := bufio.NewScanner(strings.NewReader(s))
		for sc.Scan() {
			line := strings.TrimSpace(sc.Text())
			if line == "" || strings.HasPrefix(line, "#") || strings.HasPrefix(line, "-") {
				continue
			}
			if m := reReqLine.FindStringSubmatch(line); m != nil {
				addPyDep(f, m[1], m[2])
			}
		}
	}
	if s, ok := readSmall(fsys, "pyproject.toml"); ok {
		// Dependencies appear as quoted PEP 508 strings (PEP 621, uv) or as
		// `name = "…"` keys (poetry); both are matched loosely.
		inDeps := false
		sc := bufio.NewScanner(strings.NewReader(s))
		for sc.Scan() {
			line := strings.TrimSpace(sc.Text())
			if strings.HasPrefix(line, "[") {
				inDeps = strings.Contains(line, "dependencies")
				continue
			}
			if strings.HasPrefix(line, "dependencies") {
				inDeps = true
			}
			for _, m := range rePyprojDeps.FindAllStringSubmatch(line, -1) {
				if inDeps {
					addPyDep(f, m[1], m[2])
				}
			}
			if inDeps {
				if m := rePipfileDep.FindStringSubmatch(line); m != nil && m[1] != "python" && m[1] != "dependencies" {
					addPyDep(f, m[1], "")
				}
			}
		}
	}
	if s, ok := readSmall(fsys, "Pipfile"); ok {
		inPkgs := false
		sc := bufio.NewScanner(strings.NewReader(s))
		for sc.Scan() {
			line := strings.TrimSpace(sc.Text())
			if strings.HasPrefix(line, "[") {
				inPkgs = line == "[packages]" || line == "[dev-packages]"
				continue
			}
			if m := rePipfileDep.FindStringSubmatch(line); inPkgs && m != nil {
				addPyDep(f, m[1], "")
			}
		}
	}
}

var (
	reWSGI       = regexp.MustCompile(`(?m)^\s*WSGI_APPLICATION\s*=\s*['"]([A-Za-z0-9_.]+)['"]`)
	reASGI       = regexp.MustCompile(`(?m)^\s*ASGI_APPLICATION\s*=\s*['"]([A-Za-z0-9_.]+)['"]`)
	reStaticRoot = regexp.MustCompile(`(?m)^\s*STATIC_ROOT\s*=`)
	// SECRET_KEY = os.environ["X"], os.environ.get("X", …), os.getenv("X"), env("X") (django-environ), config("X") (python-decouple).
	reSecretKeyEnv = regexp.MustCompile(`(?m)^\s*SECRET_KEY\s*=\s*(?:os\.environ(?:\.get)?|os\.getenv|env(?:\.str)?|config)\s*[\[(]\s*['"]([A-Z_][A-Z0-9_]*)['"]`)
	reSettingsEn   = regexp.MustCompile(`DJANGO_SETTINGS_MODULE['"]?\s*,\s*['"]([A-Za-z0-9_]+)\.settings`)
	reSettingsMod  = regexp.MustCompile(`DJANGO_SETTINGS_MODULE['"]?\s*,\s*['"]([A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)*)['"]`)
	// ALLOWED_HOSTS = os.environ.get("X", "").split(","), env.list("X"), config("X", cast=Csv()), …
	reHostsEnv   = regexp.MustCompile(`(?m)^\s*ALLOWED_HOSTS\s*=\s*(?:os\.environ(?:\.get)?|os\.getenv|env(?:\.list)?|config)\s*[\[(]\s*['"]([A-Z_][A-Z0-9_]*)['"]`)
	reCSRFEnv    = regexp.MustCompile(`(?m)^\s*CSRF_TRUSTED_ORIGINS\s*=\s*(?:os\.environ(?:\.get)?|os\.getenv|env(?:\.list)?|config)\s*[\[(]\s*['"]([A-Z_][A-Z0-9_]*)['"]`)
	reHostsAny   = regexp.MustCompile(`(?m)^\s*ALLOWED_HOSTS\s*=\s*\[\s*['"]\*['"]\s*,?\s*\]`)
	reRemoteStat = regexp.MustCompile(`storages\.backends|STATICFILES_STORAGE\s*=`)
	reSQLite     = regexp.MustCompile(`['"]ENGINE['"]\s*:\s*['"]django\.db\.backends\.sqlite3['"]`)
	reSQLiteName = regexp.MustCompile(`['"]NAME['"]\s*:\s*(?:BASE_DIR((?:\s*/\s*['"][^'"]+['"])+)|os\.path\.join\(\s*BASE_DIR((?:\s*,\s*['"][^'"]+['"])+)\s*\))`)
	reQuoted     = regexp.MustCompile(`['"]([^'"]+)['"]`)
)

func gatherDjango(fsys fs.FS) DjangoFacts {
	var d DjangoFacts
	if s, ok := readSmall(fsys, "manage.py"); ok {
		if m := reSettingsEn.FindStringSubmatch(s); m != nil {
			d.Project = m[1]
		}
		if m := reSettingsMod.FindStringSubmatch(s); m != nil {
			d.SettingsModule = m[1]
		}
	}
	var candidates []string
	if d.Project != "" {
		candidates = append(candidates, d.Project+"/settings.py", d.Project+"/settings/__init__.py", d.Project+"/settings/base.py", d.Project+"/settings/production.py")
	}
	entries, _ := fs.ReadDir(fsys, ".")
	for _, e := range entries {
		if e.IsDir() && !strings.HasPrefix(e.Name(), ".") {
			candidates = append(candidates, e.Name()+"/settings.py")
		}
	}
	for _, c := range candidates {
		s, ok := readSmall(fsys, c)
		if !ok {
			continue
		}
		if d.Project == "" {
			d.Project = strings.Split(c, "/")[0]
		}
		if m := reWSGI.FindStringSubmatch(s); m != nil && d.WSGI == "" {
			d.WSGI = m[1]
		}
		if m := reASGI.FindStringSubmatch(s); m != nil && d.ASGI == "" {
			d.ASGI = m[1]
		}
		if reStaticRoot.MatchString(s) {
			d.StaticRoot = true
		}
		if m := reSecretKeyEnv.FindStringSubmatch(s); m != nil && d.SecretKeyEnv == "" {
			d.SecretKeyEnv = m[1]
		}
		if m := reHostsEnv.FindStringSubmatch(s); m != nil && d.AllowedHostsEnv == "" {
			d.AllowedHostsEnv = m[1]
		}
		if m := reCSRFEnv.FindStringSubmatch(s); m != nil && d.CSRFOriginsEnv == "" {
			d.CSRFOriginsEnv = m[1]
		}
		d.AllowedHostsAny = d.AllowedHostsAny || reHostsAny.MatchString(s)
		d.WhiteNoise = d.WhiteNoise || strings.Contains(s, "whitenoise")
		d.RemoteStatic = d.RemoteStatic || reRemoteStat.MatchString(s)
		if reSQLite.MatchString(s) && !d.SQLite {
			d.SQLite = true
			if m := reSQLiteName.FindStringSubmatch(s); m != nil {
				var segs []string
				for _, q := range reQuoted.FindAllStringSubmatch(m[1]+m[2], -1) {
					segs = append(segs, q[1])
				}
				if len(segs) > 1 {
					d.SQLiteDir = path.Clean(strings.Join(segs[:len(segs)-1], "/"))
				}
			}
		}
	}
	// The WSGI/ASGI module's default is what production runs with.
	for _, entry := range []string{d.WSGI, d.ASGI} {
		if mod, _ := moduleOf(entry); entry != "" {
			if s, ok := readSmall(fsys, strings.ReplaceAll(mod, ".", "/")+".py"); ok {
				if m := reSettingsMod.FindStringSubmatch(s); m != nil {
					d.SettingsModule = m[1]
					break
				}
			}
		}
	}
	return d
}

var rePyApp = regexp.MustCompile(`(?m)^([A-Za-z_][A-Za-z0-9_]*)\s*(?::\s*[A-Za-z_.]+\s*)?=\s*(FastAPI|Flask|Litestar)\(`)

func findPythonApps(fsys fs.FS) []PythonApp {
	candidates := []string{"main.py", "app.py", "server.py", "app/main.py", "api/main.py", "src/main.py", "app/__init__.py"}
	if entries, err := fs.ReadDir(fsys, "src"); err == nil {
		for _, e := range entries {
			if e.IsDir() {
				candidates = append(candidates, "src/"+e.Name()+"/main.py", "src/"+e.Name()+"/app.py")
			}
		}
	}
	var out []PythonApp
	for _, c := range candidates {
		s, ok := readSmall(fsys, c)
		if !ok {
			continue
		}
		if m := rePyApp.FindStringSubmatch(s); m != nil {
			out = append(out, PythonApp{File: c, Variable: m[1], Framework: strings.ToLower(m[2])})
		}
	}
	return out
}
