package presets

import (
	"encoding/json"
	"io"
	"io/fs"
	"regexp"
)

// PackageJSON is the subset of package.json the presets use.
type PackageJSON struct {
	Name    string            `json:"name"`
	Main    string            `json:"main"`
	Scripts map[string]string `json:"scripts"`
	// Deps merges dependencies and devDependencies (name → version range).
	Deps map[string]string `json:"-"`
	// Dependencies are only the dependencies (kept when Railpack prunes
	// devDependencies).
	Dependencies map[string]string `json:"-"`
}

// Dep returns the version range of a dependency and whether it exists.
func (p *PackageJSON) Dep(name string) (string, bool) {
	if p == nil {
		return "", false
	}
	v, ok := p.Deps[name]
	return v, ok
}

// Listener is a hard-coded listen port in a source file.
type Listener struct {
	File string
	Port string
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
	p := &PackageJSON{Name: raw.Name, Main: raw.Main, Scripts: raw.Scripts, Deps: map[string]string{}, Dependencies: raw.Dependencies}
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

// JSFacts are facts from JavaScript framework config files and lockfiles.
type JSFacts struct {
	// Configs maps a framework to its config file's content (truncated):
	// "astro", "svelte", "nuxt", "react-router", "vite" and "angular" (angular.json).
	Configs map[string]string
	// Locked maps packages (lockedPackages) to the version the lockfile resolved.
	Locked map[string]string
}

// jsConfigs are the config files read per framework, first found wins.
var jsConfigs = []struct {
	key   string
	files []string
}{
	{"astro", []string{"astro.config.mjs", "astro.config.ts", "astro.config.js", "astro.config.mts"}},
	{"svelte", []string{"svelte.config.js", "svelte.config.mjs", "svelte.config.ts"}},
	{"nuxt", []string{"nuxt.config.ts", "nuxt.config.js", "nuxt.config.mjs"}},
	{"react-router", []string{"react-router.config.ts", "react-router.config.js", "react-router.config.mjs"}},
	{"vite", []string{"vite.config.ts", "vite.config.js", "vite.config.mjs", "vite.config.mts"}},
	{"angular", []string{"angular.json"}},
}

// lockedPackages are the packages whose resolved version a preset gates on.
var lockedPackages = []string{"next", "next-auth"}

// maxLockfileBytes bounds how much of a lockfile is searched for versions.
const maxLockfileBytes = 16 << 20

func gatherJavaScript(fsys fs.FS, f *Facts) {
	if f.PackageJSON == nil {
		return
	}
	f.JS = JSFacts{Configs: map[string]string{}, Locked: map[string]string{}}
	for _, c := range jsConfigs {
		for _, n := range c.files {
			if s, ok := readSmall(fsys, n); ok {
				f.JS.Configs[c.key] = s
				break
			}
		}
	}
	for _, lock := range []string{"package-lock.json", "pnpm-lock.yaml", "yarn.lock", "bun.lock"} {
		s, ok := readLimited(fsys, lock, maxLockfileBytes)
		if !ok {
			continue
		}
		for _, pkg := range lockedPackages {
			if _, dep := f.PackageJSON.Dep(pkg); !dep {
				continue
			}
			if v := lockedVersion(lock, s, pkg); v != "" {
				f.JS.Locked[pkg] = v
			}
		}
		break
	}
}

func readLimited(fsys fs.FS, name string, limit int64) (string, bool) {
	fh, err := fsys.Open(name)
	if err != nil {
		return "", false
	}
	defer func() { _ = fh.Close() }()
	if st, err := fh.Stat(); err != nil || !st.Mode().IsRegular() {
		return "", false
	}
	b, err := io.ReadAll(io.LimitReader(fh, limit))
	if err != nil {
		return "", false
	}
	return string(b), true
}

const reVersion = `([0-9]+\.[0-9]+\.[0-9]+[0-9A-Za-z.+-]*)`

// lockedVersion finds the version of pkg (a top-level dependency) that a
// lockfile resolved. It reads the lockfile as text: an unknown shape gives "".
func lockedVersion(lockfile, content, pkg string) string {
	q := regexp.QuoteMeta(pkg)
	var patterns []string
	switch lockfile {
	case "package-lock.json":
		patterns = []string{`"node_modules/` + q + `":\s*\{\s*"version":\s*"` + reVersion + `"`}
	case "pnpm-lock.yaml":
		patterns = []string{
			// v6+ importers: "next:\n  specifier: ^15\n  version: 15.5.2(react@…)".
			`(?m)^\s+'?` + q + `'?:\s*\n\s+specifier:[^\n]*\n\s+version:\s*` + reVersion,
			// v5 importers / dependencies: "next: 15.5.2".
			`(?m)^\s+'?` + q + `'?:\s*'?` + reVersion,
		}
	case "yarn.lock":
		patterns = []string{`(?m)^"?` + q + `@[^\n]*:\s*\n\s+version:?\s+"?` + reVersion}
	case "bun.lock":
		patterns = []string{`"` + q + `":\s*\[\s*"` + q + `@` + reVersion + `"`}
	}
	for _, p := range patterns {
		if m := regexp.MustCompile(p).FindStringSubmatch(content); m != nil {
			return m[1]
		}
	}
	return ""
}
