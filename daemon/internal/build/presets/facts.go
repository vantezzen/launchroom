// Package presets holds launchroom's framework presets (docs/04 §3): small,
// versioned, pure functions over static repository facts and Railpack's
// detection info that patch the Railpack plan where its defaults are not
// production-grade, and describe the runtime contract (port, health path,
// release command, extra processes). Presets compose with Railpack; they
// never replace it, and every decision is a removable chip in the UI.
package presets

import (
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
	// JS are facts from JavaScript framework config files and lockfiles.
	JS JSFacts
	// PHP, Ruby, Elixir, JVM, DotNet and Rust are the server ecosystems' facts.
	PHP    PHPFacts
	Ruby   RubyFacts
	Elixir ElixirFacts
	JVM    JVMFacts
	DotNet DotNetFacts
	Rust   RustFacts
	// DockerfileWorkdir is the last WORKDIR of the app's Dockerfile, if any.
	DockerfileWorkdir string
}

// Limits keep gathering cheap on large repositories.
const (
	maxFileBytes   = 256 << 10
	maxSourceFiles = 400
	maxEnvRefs     = 256
	maxScanDepth   = 3
)

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
	gatherComposer(fsys, &f)
	gatherJavaScript(fsys, &f)
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
	gatherServerFacts(fsys, &f)
	scanSources(fsys, &f)
	return f
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
