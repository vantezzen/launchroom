// Package presets holds launchroom's framework presets (docs/04 §3): small,
// versioned, pure functions over static repository facts and Railpack's
// detection info that patch the Railpack plan where its defaults are not
// production-grade, and describe the runtime contract (port, health path,
// release command, extra processes). Presets compose with Railpack; they
// never replace it, and every decision is a removable chip in the UI.
package presets

import (
	"bufio"
	"encoding/json"
	"io"
	"io/fs"
	"path"
	"regexp"
	"slices"
	"strings"
)

// Facts are cheap static facts about an app directory. Gathering them never
// executes repository code.
type Facts struct {
	// Files are the paths (relative, slash-separated) that exist among the probed ones.
	Files map[string]bool
	// PackageJSON is the parsed package.json, if any.
	PackageJSON *PackageJSON
	// ComposerRequire are the composer.json require + require-dev package names.
	ComposerRequire map[string]bool
	// PythonDeps are lowercased dependency names from requirements*.txt,
	// pyproject.toml and Pipfile (extras kept separately).
	PythonDeps map[string]bool
	// PythonExtras maps a dependency to its extras ("fastapi" → {"standard"}).
	PythonExtras map[string][]string
	// NextConfig is the content of next.config.{js,mjs,ts} (truncated).
	NextConfig string
	// Django facts (settings modules found by a shallow scan).
	Django DjangoFacts
	// PythonApps are candidate ASGI/WSGI app objects found in well-known files.
	PythonApps []PythonApp
	// Listeners are hard-coded `listen(<port>)` calls in JS/TS files that never read PORT.
	Listeners []Listener
	// EnvRefs are variable names referenced in the source (sorted, unique).
	EnvRefs []string
	// BuildEnv are the names of the build-scoped variables of this build.
	BuildEnv []string
}

// PackageJSON is the subset of package.json the presets use.
type PackageJSON struct {
	Name    string            `json:"name"`
	Main    string            `json:"main"`
	Scripts map[string]string `json:"scripts"`
	// Deps merges dependencies and devDependencies (name → version range).
	Deps map[string]string `json:"-"`
}

// Dep returns the version range of a dependency and whether it exists.
func (p *PackageJSON) Dep(name string) (string, bool) {
	if p == nil {
		return "", false
	}
	v, ok := p.Deps[name]
	return v, ok
}

// DjangoFacts are found in the project's settings module.
type DjangoFacts struct {
	// Project is the package containing settings.py ("mysite").
	Project string
	// WSGI and ASGI are WSGI_APPLICATION / ASGI_APPLICATION ("mysite.wsgi.application").
	WSGI string
	ASGI string
	// StaticRoot reports whether STATIC_ROOT is set.
	StaticRoot bool
}

// PythonApp is an app object like `app = FastAPI()` in a module.
type PythonApp struct {
	File      string // "app/main.py"
	Variable  string // "app"
	Framework string // "fastapi", "flask", "litestar"
}

// Listener is a hard-coded listen port in a source file.
type Listener struct {
	File string
	Port string
}

// Limits keep gathering cheap on large repositories.
const (
	maxFileBytes   = 256 << 10
	maxSourceFiles = 400
	maxEnvRefs     = 256
	maxScanDepth   = 3
)

// probed are the files whose presence presets look at.
var probed = []string{
	"package.json", "package-lock.json", "pnpm-lock.yaml", "yarn.lock", "bun.lockb", "bun.lock",
	"composer.json", "artisan", "manage.py", "requirements.txt", "pyproject.toml", "Pipfile", "uv.lock", "poetry.lock",
	"main.py", "app.py", "server.py", "app/main.py", "api/main.py", "src/main.py",
	"index.js", "index.ts", "server.js", "server.ts", "src/index.js", "src/index.ts", "src/server.js", "src/server.ts",
	"next.config.js", "next.config.mjs", "next.config.ts", "vite.config.js", "vite.config.ts", "vite.config.mjs",
	"Dockerfile", "Containerfile", "Procfile", "railpack.json", "go.mod", "Gemfile",
}

func readSmall(fsys fs.FS, name string) (string, bool) {
	f, err := fsys.Open(name)
	if err != nil {
		return "", false
	}
	defer func() { _ = f.Close() }()
	if st, err := f.Stat(); err != nil || !st.Mode().IsRegular() {
		return "", false
	}
	b, err := io.ReadAll(io.LimitReader(f, maxFileBytes))
	if err != nil {
		return "", false
	}
	return string(b), true
}

// Gather reads facts from fsys (the app root directory). Unreadable or
// malformed files are skipped: facts are best-effort hints, never errors.
func Gather(fsys fs.FS, buildEnv []string) Facts {
	f := Facts{
		Files: map[string]bool{}, ComposerRequire: map[string]bool{},
		PythonDeps: map[string]bool{}, PythonExtras: map[string][]string{},
		BuildEnv: slices.Clone(buildEnv),
	}
	for _, p := range probed {
		if st, err := fs.Stat(fsys, p); err == nil && st.Mode().IsRegular() {
			f.Files[p] = true
		}
	}
	if s, ok := readSmall(fsys, "package.json"); ok {
		f.PackageJSON = parsePackageJSON(s)
	}
	if s, ok := readSmall(fsys, "composer.json"); ok {
		var c struct {
			Require    map[string]any `json:"require"`
			RequireDev map[string]any `json:"require-dev"`
		}
		if json.Unmarshal([]byte(s), &c) == nil {
			for k := range c.Require {
				f.ComposerRequire[strings.ToLower(k)] = true
			}
			for k := range c.RequireDev {
				f.ComposerRequire[strings.ToLower(k)] = true
			}
		}
	}
	gatherPythonDeps(fsys, &f)
	for _, n := range []string{"next.config.js", "next.config.mjs", "next.config.ts"} {
		if s, ok := readSmall(fsys, n); ok {
			f.NextConfig = s
			break
		}
	}
	if f.Files["manage.py"] {
		f.Django = gatherDjango(fsys)
	}
	f.PythonApps = findPythonApps(fsys)
	scanSources(fsys, &f)
	return f
}

func parsePackageJSON(s string) *PackageJSON {
	var raw struct {
		Name            string            `json:"name"`
		Main            string            `json:"main"`
		Scripts         map[string]string `json:"scripts"`
		Dependencies    map[string]string `json:"dependencies"`
		DevDependencies map[string]string `json:"devDependencies"`
	}
	if json.Unmarshal([]byte(s), &raw) != nil {
		return nil
	}
	p := &PackageJSON{Name: raw.Name, Main: raw.Main, Scripts: raw.Scripts, Deps: map[string]string{}}
	for k, v := range raw.DevDependencies {
		p.Deps[k] = v
	}
	for k, v := range raw.Dependencies {
		p.Deps[k] = v
	}
	if p.Scripts == nil {
		p.Scripts = map[string]string{}
	}
	return p
}

var (
	reReqLine    = regexp.MustCompile(`^\s*([A-Za-z0-9][A-Za-z0-9._-]*)\s*(?:\[([A-Za-z0-9_,\s-]+)\])?`)
	rePyprojDeps = regexp.MustCompile(`["']([A-Za-z0-9][A-Za-z0-9._-]*)(?:\[([A-Za-z0-9_,\s-]+)\])?\s*(?:[<>=!~;].*)?["']`)
	rePipfileDep = regexp.MustCompile(`^\s*["']?([A-Za-z0-9][A-Za-z0-9._-]*)["']?\s*=`)
)

func addPyDep(f *Facts, name, extras string) {
	n := strings.ToLower(strings.ReplaceAll(name, "_", "-"))
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
	reSettingsEn = regexp.MustCompile(`DJANGO_SETTINGS_MODULE['"]?\s*,\s*['"]([A-Za-z0-9_]+)\.settings`)
)

func gatherDjango(fsys fs.FS) DjangoFacts {
	var d DjangoFacts
	if s, ok := readSmall(fsys, "manage.py"); ok {
		if m := reSettingsEn.FindStringSubmatch(s); m != nil {
			d.Project = m[1]
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

var (
	reListen   = regexp.MustCompile(`\.listen\(\s*([0-9]{2,5})\s*[,)]`)
	reReadPort = regexp.MustCompile(`process\.env\.PORT|process\.env\[['"]PORT['"]\]|Deno\.env\.get\(['"]PORT|Bun\.env\.PORT`)
	reEnvRefs  = []*regexp.Regexp{
		regexp.MustCompile(`process\.env\.([A-Z_][A-Z0-9_]*)`),
		regexp.MustCompile(`process\.env\[['"]([A-Z_][A-Z0-9_]*)['"]\]`),
		regexp.MustCompile(`import\.meta\.env\.([A-Z_][A-Z0-9_]*)`),
		regexp.MustCompile(`\benv\(\s*['"]([A-Z_][A-Z0-9_]*)['"]`),
		regexp.MustCompile(`os\.environ(?:\.get)?[\[(]\s*['"]([A-Z_][A-Z0-9_]*)['"]`),
		regexp.MustCompile(`os\.getenv\(\s*['"]([A-Z_][A-Z0-9_]*)['"]`),
		regexp.MustCompile(`getenv\(\s*['"]([A-Z_][A-Z0-9_]*)['"]`),
	}
	sourceExts  = []string{".js", ".mjs", ".cjs", ".ts", ".mts", ".tsx", ".jsx", ".vue", ".svelte", ".astro", ".py", ".php"}
	skippedDirs = []string{"node_modules", "vendor", ".git", ".next", "dist", "build", ".venv", "venv", "__pycache__", "storage", ".nuxt", ".svelte-kit", "coverage"}
)

// scanSources walks source files (bounded depth and count) for env references
// and hard-coded listen ports.
func scanSources(fsys fs.FS, f *Facts) {
	refs := map[string]bool{}
	files := 0
	_ = fs.WalkDir(fsys, ".", func(p string, d fs.DirEntry, err error) error {
		if err != nil {
			return nil //nolint:nilerr // best effort: skip unreadable entries
		}
		if d.IsDir() {
			if p != "." && (slices.Contains(skippedDirs, d.Name()) || strings.HasPrefix(d.Name(), ".") || strings.Count(p, "/") >= maxScanDepth) {
				return fs.SkipDir
			}
			return nil
		}
		if !d.Type().IsRegular() || !slices.Contains(sourceExts, path.Ext(p)) {
			return nil
		}
		if files >= maxSourceFiles {
			return fs.SkipAll
		}
		files++
		s, ok := readSmall(fsys, p)
		if !ok {
			return nil
		}
		for _, re := range reEnvRefs {
			for _, m := range re.FindAllStringSubmatch(s, -1) {
				if len(refs) < maxEnvRefs {
					refs[m[1]] = true
				}
			}
		}
		ext := path.Ext(p)
		if ext != ".py" && ext != ".php" && !reReadPort.MatchString(s) {
			for _, m := range reListen.FindAllStringSubmatch(s, -1) {
				f.Listeners = append(f.Listeners, Listener{File: p, Port: m[1]})
			}
		}
		return nil
	})
	for k := range refs {
		f.EnvRefs = append(f.EnvRefs, k)
	}
	slices.Sort(f.EnvRefs)
}
