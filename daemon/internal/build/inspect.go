package build

import (
	"bytes"
	"errors"
	"io"
	"io/fs"
	"os"
	"path"
	"slices"
	"strings"
	"unicode/utf8"

	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// Repository inspection for generated Dockerfiles (docs/20 §2): a file
// listing of the app directory and selected manifest, config and entry
// files. What is never collected: .env files, keys and certificates,
// credential files, compose files, lockfile contents, binary files and
// symlinks. Every read goes through os.Root, so nothing outside the app
// directory is touched. The dashboard filters and scrubs again.

const (
	inspectMaxDepth     = 8
	inspectExcerptBytes = 8 << 10
	inspectMaxExcerpts  = 8
	// manifestMaxDepth: manifests deeper than this (in path components) are listed only.
	manifestMaxDepth = 3
)

// inspectSkipDirs are never descended into.
var inspectSkipDirs = map[string]bool{
	".git": true, "node_modules": true, "vendor": true, ".venv": true, "venv": true,
	"__pycache__": true, "target": true, "dist": true, "build": true, ".next": true,
	".nuxt": true, ".svelte-kit": true, ".turbo": true, ".cache": true, "coverage": true,
}

var deniedExact = map[string]bool{
	".npmrc": true, ".pypirc": true, ".netrc": true, ".git-credentials": true,
	"auth.json": true, "kubeconfig": true, ".dockercfg": true, ".htpasswd": true,
}

var deniedPrefixes = []string{
	".env", ".yarnrc", "id_rsa", "id_ed25519", "id_ecdsa", "id_dsa",
	"credentials", "secrets", "docker-compose",
}

var deniedSuffixes = []string{".pem", ".key", ".p12", ".pfx", ".crt", ".jks", ".keystore", ".tfvars", ".tfstate"}

// deniedName reports whether a path component must never be listed or read.
func deniedName(name string) bool {
	n := strings.ToLower(name)
	if deniedExact[n] {
		return true
	}
	for _, p := range deniedPrefixes {
		if strings.HasPrefix(n, p) {
			return true
		}
	}
	for _, s := range deniedSuffixes {
		if strings.HasSuffix(n, s) {
			return true
		}
	}
	// compose.yaml / compose.override.yml (often inline credentials), but not composer.json.
	if strings.HasPrefix(n, "compose") && !strings.HasPrefix(n, "composer") && (strings.HasSuffix(n, ".yml") || strings.HasSuffix(n, ".yaml")) {
		return true
	}
	return false
}

// deniedPath applies deniedName to every component.
func deniedPath(p string) bool {
	for c := range strings.SplitSeq(p, "/") {
		if deniedName(c) {
			return true
		}
	}
	return false
}

var manifestNames = map[string]bool{
	"package.json": true, "pnpm-workspace.yaml": true, "lerna.json": true, "nx.json": true, "turbo.json": true,
	"tsconfig.json": true, "angular.json": true, "composer.json": true, "requirements.txt": true,
	"pyproject.toml": true, "pipfile": true, "setup.py": true, "setup.cfg": true, "manage.py": true,
	"go.mod": true, "go.work": true, "cargo.toml": true, "gemfile": true, "config.ru": true, "mix.exs": true,
	"pom.xml": true, "build.gradle": true, "build.gradle.kts": true, "settings.gradle": true,
	"settings.gradle.kts": true, "deno.json": true, "deno.jsonc": true, "procfile": true, ".nvmrc": true,
	".node-version": true, ".python-version": true, ".ruby-version": true, ".tool-versions": true,
	"runtime.txt": true, "containerfile": true,
}

var configPrefixes = []string{
	"next.config.", "vite.config.", "nuxt.config.", "astro.config.", "svelte.config.",
	"remix.config.", "react-router.config.",
}

var lockfiles = map[string]bool{
	"package-lock.json": true, "pnpm-lock.yaml": true, "yarn.lock": true, "bun.lockb": true, "bun.lock": true,
	"composer.lock": true, "gemfile.lock": true, "poetry.lock": true, "pipfile.lock": true, "cargo.lock": true,
	"go.sum": true, "uv.lock": true, "mix.lock": true,
}

// fileKind ranks what to read: 0 = not read, 1 manifest, 2 config, 3 readme, 4 entry excerpt.
func fileKind(rel string) (kind int, limit int) {
	base := path.Base(rel)
	b := strings.ToLower(base)
	depth := strings.Count(rel, "/") + 1
	if lockfiles[b] {
		return 0, 0
	}
	if depth <= manifestMaxDepth {
		switch {
		case manifestNames[b], strings.HasPrefix(b, "requirements") && strings.HasSuffix(b, ".txt"),
			strings.HasSuffix(b, ".csproj"), b == "dockerfile", strings.HasPrefix(b, "dockerfile."),
			strings.HasSuffix(b, ".dockerfile"):
			return 1, state.MaxInspectedFileBytes
		}
		for _, p := range configPrefixes {
			if strings.HasPrefix(b, p) {
				return 2, state.MaxInspectedFileBytes
			}
		}
	}
	if depth == 1 && strings.HasPrefix(b, "readme") {
		return 3, inspectExcerptBytes
	}
	if isEntry(rel) {
		return 4, inspectExcerptBytes
	}
	return 0, 0
}

func isEntry(rel string) bool {
	switch rel {
	case "main.go", "main.py", "app.py", "app/main.py", "wsgi.py", "asgi.py", "src/main.rs", "Program.cs":
		return true
	}
	parts := strings.Split(rel, "/")
	base := parts[len(parts)-1]
	stem, _, hasExt := strings.Cut(base, ".")
	switch {
	case len(parts) == 3 && parts[0] == "cmd" && base == "main.go":
		return true
	case len(parts) == 2 && (base == "wsgi.py" || base == "asgi.py"):
		return true
	case len(parts) == 1 && hasExt && (stem == "server" || stem == "index" || stem == "app"):
		return true
	case len(parts) == 2 && parts[0] == "src" && hasExt && (stem == "index" || stem == "main" || stem == "server"):
		return true
	}
	return false
}

// Inspect lists dir and reads its selected files (docs/20 §2).
func Inspect(dir string) (*state.RepoInspection, error) {
	root, err := os.OpenRoot(dir)
	if err != nil {
		return nil, err
	}
	defer func() { _ = root.Close() }()

	out := &state.RepoInspection{Tree: []state.RelPath{}, Files: []state.InspectedFile{}}
	var candidates []string
	errStop := errors.New("tree full")
	walkErr := fs.WalkDir(root.FS(), ".", func(p string, d fs.DirEntry, err error) error {
		if err != nil {
			if p == "." {
				return err
			}
			return nil // unreadable entries are left out
		}
		if p == "." {
			return nil
		}
		if deniedName(d.Name()) {
			if d.IsDir() {
				return fs.SkipDir
			}
			return nil
		}
		depth := strings.Count(p, "/") + 1
		if d.IsDir() {
			if inspectSkipDirs[d.Name()] || depth >= inspectMaxDepth {
				return fs.SkipDir
			}
			return nil
		}
		if !d.Type().IsRegular() { // symlinks, devices, sockets
			return nil
		}
		if state.RelPath(p).Validate() != nil {
			return nil
		}
		if len(out.Tree) >= state.MaxInspectionTree {
			out.TreeTruncated = true
			return errStop
		}
		out.Tree = append(out.Tree, state.RelPath(p))
		if k, _ := fileKind(p); k > 0 {
			candidates = append(candidates, p)
		}
		return nil
	})
	if walkErr != nil && !errors.Is(walkErr, errStop) {
		return nil, walkErr
	}

	// Shallow files first, then manifests > configs > readme > entries.
	slices.SortStableFunc(candidates, func(a, b string) int {
		da, db := strings.Count(a, "/"), strings.Count(b, "/")
		if da != db {
			return da - db
		}
		ka, _ := fileKind(a)
		kb, _ := fileKind(b)
		return ka - kb
	})
	total, excerpts := 0, 0
	for _, p := range candidates {
		if len(out.Files) >= state.MaxInspectionFiles {
			break
		}
		kind, limit := fileKind(p)
		if kind == 4 {
			if excerpts >= inspectMaxExcerpts {
				continue
			}
		}
		if rest := state.MaxInspectionTotalBytes - total; rest < limit {
			limit = rest
		}
		if limit <= 0 {
			break
		}
		content, truncated, ok := readText(root, p, limit)
		if !ok {
			continue
		}
		if kind == 4 {
			excerpts++
		}
		total += len(content)
		out.Files = append(out.Files, state.InspectedFile{Path: state.RelPath(p), Content: content, Truncated: truncated})
	}
	return out, nil
}

// readText reads at most limit bytes of a regular UTF-8 text file without
// NUL bytes, cut at a rune boundary.
func readText(root *os.Root, p string, limit int) (string, bool, bool) {
	if deniedPath(p) {
		return "", false, false
	}
	info, err := root.Lstat(p)
	if err != nil || !info.Mode().IsRegular() {
		return "", false, false
	}
	f, err := root.Open(p)
	if err != nil {
		return "", false, false
	}
	defer func() { _ = f.Close() }()
	buf, err := io.ReadAll(io.LimitReader(f, int64(limit)+1))
	if err != nil {
		return "", false, false
	}
	if bytes.IndexByte(buf, 0) >= 0 {
		return "", false, false
	}
	truncated := len(buf) > limit
	if truncated {
		buf = buf[:limit]
		// Drop a rune the cut split (at most 3 bytes); invalid text stays invalid.
		for i := 0; i < utf8.UTFMax-1 && len(buf) > 0 && !utf8.Valid(buf); i++ {
			buf = buf[:len(buf)-1]
		}
	}
	if !utf8.Valid(buf) {
		return "", false, false
	}
	return string(buf), truncated, true
}
