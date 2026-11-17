package build

import (
	"encoding/json"
	"errors"
	"io"
	"io/fs"
	"maps"
	"os"
	"path"
	"path/filepath"
	"regexp"
	"slices"
	"strings"

	yaml "go.yaml.in/yaml/v3"

	"github.com/vantezzen/launchroom/daemon/internal/build/presets"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// workspace is the JavaScript monorepo workspace an app folder belongs to
// (docs/04 §3, monorepos): the build context and the Railpack app become the
// workspace root, the build is filtered to the app and its workspace
// dependencies, and the app starts from its own folder.
type workspace struct {
	// Root is the workspace root relative to the checkout ("" = the checkout root).
	Root string
	// App is the app folder relative to Root (never empty).
	App string
	// Package is the app's package name.
	Package string
	// Manager is the workspace's package manager (pnpm, npm, yarn, bun).
	Manager string
	// Tool is the monorepo tool ("turbo", "nx" or "").
	Tool string
	// DependsOn are the folders of the workspace packages the app depends on
	// (transitively), relative to the checkout, sorted.
	DependsOn []string
	// Scripts are the app's package.json scripts.
	Scripts map[string]string
	// Main is the app's package.json "main".
	Main string
}

// Limits of the workspace scan: it reads only package manifests.
const (
	maxManifestBytes   = 512 << 10
	maxWorkspaceDirs   = 4000
	maxWorkspaceDepth  = 6
	maxWorkspaceGlobs  = 64
	maxWorkspaceWalkUp = 16
)

// rePackageName is an npm package name launchroom puts into a build command
// unquoted: lowercase, optionally scoped, with no shell metacharacters.
var rePackageName = regexp.MustCompile(`^(?:@[a-z0-9][a-z0-9._-]*/)?[a-z0-9][a-z0-9._-]*$`)

// appLockfiles mark an app folder with its own lockfile: an isolated app
// that happens to live in a monorepo, built on its own as before.
var appLockfiles = []string{"package-lock.json", "pnpm-lock.yaml", "yarn.lock", "bun.lockb", "bun.lock", "npm-shrinkwrap.json"}

type manifest struct {
	Name                 string            `json:"name"`
	Main                 string            `json:"main"`
	PackageManager       string            `json:"packageManager"`
	Scripts              map[string]string `json:"scripts"`
	Dependencies         map[string]string `json:"dependencies"`
	DevDependencies      map[string]string `json:"devDependencies"`
	OptionalDependencies map[string]string `json:"optionalDependencies"`
	// Workspaces is either a list of globs or {"packages": [...]} (yarn).
	Workspaces json.RawMessage `json:"workspaces"`
}

func (m *manifest) workspaceGlobs() []string {
	if len(m.Workspaces) == 0 {
		return nil
	}
	var list []string
	if json.Unmarshal(m.Workspaces, &list) == nil {
		return list
	}
	var obj struct {
		Packages []string `json:"packages"`
	}
	if json.Unmarshal(m.Workspaces, &obj) == nil {
		return obj.Packages
	}
	return nil
}

func readCapped(fsys fs.FS, name string, limit int64) ([]byte, error) {
	f, err := fsys.Open(name)
	if err != nil {
		return nil, err
	}
	defer func() { _ = f.Close() }()
	st, err := f.Stat()
	if err != nil {
		return nil, err
	}
	if !st.Mode().IsRegular() {
		return nil, errors.New(name + " is not a regular file")
	}
	raw, err := io.ReadAll(io.LimitReader(f, limit+1))
	if err != nil {
		return nil, err
	}
	if int64(len(raw)) > limit {
		return nil, errors.New(name + " is too large")
	}
	return raw, nil
}

func readManifest(fsys fs.FS, dir string) (*manifest, error) {
	raw, err := readCapped(fsys, path.Join(dir, "package.json"), maxManifestBytes)
	if err != nil {
		return nil, err
	}
	var m manifest
	if err := json.Unmarshal(raw, &m); err != nil {
		return nil, err
	}
	return &m, nil
}

func exists(fsys fs.FS, name string) bool {
	_, err := fs.Stat(fsys, name)
	return err == nil
}

// workspaceGlobsAt returns the workspace globs declared in dir
// (pnpm-workspace.yaml first, then package.json "workspaces").
func workspaceGlobsAt(fsys fs.FS, dir string) []string {
	if raw, err := readCapped(fsys, path.Join(dir, "pnpm-workspace.yaml"), maxManifestBytes); err == nil {
		var pw struct {
			Packages []string `yaml:"packages"`
		}
		if yaml.Unmarshal(raw, &pw) == nil && len(pw.Packages) > 0 {
			return pw.Packages
		}
	}
	if m, err := readManifest(fsys, dir); err == nil {
		return m.workspaceGlobs()
	}
	return nil
}

// globSet is a workspace's package globs: a folder is a workspace package
// when a positive glob matches it and no "!" glob does.
type globSet struct{ include, exclude [][]string }

func newGlobSet(globs []string) globSet {
	var g globSet
	for i, raw := range globs {
		if i >= maxWorkspaceGlobs {
			break
		}
		neg := strings.HasPrefix(raw, "!")
		p := strings.Trim(strings.TrimPrefix(strings.TrimPrefix(raw, "!"), "./"), "/")
		if p == "" || strings.Contains(p, "..") {
			continue
		}
		segs := strings.Split(p, "/")
		if neg {
			g.exclude = append(g.exclude, segs)
		} else {
			g.include = append(g.include, segs)
		}
	}
	return g
}

func (g globSet) empty() bool { return len(g.include) == 0 }

func (g globSet) match(rel string) bool {
	if rel == "" {
		return false
	}
	segs := strings.Split(rel, "/")
	in := slices.ContainsFunc(g.include, func(p []string) bool { return matchSegments(p, segs) })
	return in && !slices.ContainsFunc(g.exclude, func(p []string) bool { return matchSegments(p, segs) })
}

// matchSegments matches a slash-split glob against a slash-split path; "**"
// matches any number of segments (including none).
func matchSegments(pat, segs []string) bool {
	if len(pat) == 0 {
		return len(segs) == 0
	}
	if pat[0] == "**" {
		for i := 0; i <= len(segs); i++ {
			if matchSegments(pat[1:], segs[i:]) {
				return true
			}
		}
		return false
	}
	if len(segs) == 0 {
		return false
	}
	ok, err := path.Match(pat[0], segs[0])
	return err == nil && ok && matchSegments(pat[1:], segs[1:])
}

// otherAppManifests mark an app folder that isn't a Node app even though it
// has a package.json (Laravel or Django with a Vite frontend): Railpack must
// plan it from its own folder.
var otherAppManifests = []string{"composer.json", "Gemfile", "requirements.txt", "pyproject.toml", "Pipfile", "manage.py", "go.mod", "mix.exs", "Cargo.toml", "pom.xml", "build.gradle", "build.gradle.kts"}

// detectWorkspace finds the workspace of the app folder dir inside the
// checkout and returns it with its absolute root, or a note for the log.
func detectWorkspace(checkout, dir string) (*workspace, string, string) {
	base, err := filepath.EvalSymlinks(checkout)
	if err != nil {
		return nil, "", ""
	}
	rel, err := filepath.Rel(base, dir)
	if err != nil || rel == "." || strings.HasPrefix(rel, "..") {
		return nil, "", ""
	}
	root, err := os.OpenRoot(base)
	if err != nil {
		return nil, "", ""
	}
	defer func() { _ = root.Close() }()
	appRel := filepath.ToSlash(rel)
	for _, m := range otherAppManifests {
		if exists(root.FS(), path.Join(appRel, m)) {
			return nil, "", ""
		}
	}
	ws, note := findWorkspace(root.FS(), appRel)
	if ws == nil {
		return nil, "", note
	}
	if ws.Root != "" && state.RelPath(ws.Root).Validate() != nil || state.RelPath(ws.App).Validate() != nil {
		return nil, "", ""
	}
	return ws, filepath.Join(base, filepath.FromSlash(ws.Root)), ""
}

// findWorkspace returns the workspace the app folder rootDir (relative to
// the checkout) belongs to, or nil: the nearest folder above it whose
// workspace globs include it. An app with its own lockfile, without a
// package.json name, or at the workspace root itself is built on its own.
// fsys is the checkout (an os.Root FS, so no read leaves it).
func findWorkspace(fsys fs.FS, rootDir string) (*workspace, string) {
	if rootDir == "" || rootDir == "." {
		return nil, ""
	}
	app, err := readManifest(fsys, rootDir)
	if err != nil {
		return nil, ""
	}
	for _, l := range appLockfiles {
		if exists(fsys, path.Join(rootDir, l)) {
			return nil, ""
		}
	}
	dir := rootDir
	for range maxWorkspaceWalkUp {
		if dir == "." || dir == "" {
			break
		}
		dir = path.Dir(dir)
		root := dir
		if root == "." {
			root = ""
		}
		globs := newGlobSet(workspaceGlobsAt(fsys, nonEmpty(root)))
		if globs.empty() {
			continue
		}
		rel := strings.TrimPrefix(strings.TrimPrefix(rootDir, root), "/")
		if !globs.match(rel) {
			continue
		}
		if !rePackageName.MatchString(app.Name) {
			return nil, "The app folder belongs to a workspace, but its package.json name " + quoteName(app.Name) + " is not a plain npm package name, so it is built on its own."
		}
		ws := &workspace{Root: root, App: rel, Package: app.Name, Scripts: app.Scripts, Main: app.Main}
		rootManifest, _ := readManifest(fsys, nonEmpty(root))
		ws.Manager = workspaceManager(fsys, nonEmpty(root), rootManifest)
		switch {
		case exists(fsys, path.Join(nonEmpty(root), "turbo.json")) || exists(fsys, path.Join(nonEmpty(root), "turbo.jsonc")):
			ws.Tool = "turbo"
		case exists(fsys, path.Join(nonEmpty(root), "nx.json")):
			ws.Tool = "nx"
		}
		ws.DependsOn = workspaceDeps(fsys, root, globs, rel, app)
		return ws, ""
	}
	return nil, ""
}

func nonEmpty(dir string) string {
	if dir == "" {
		return "."
	}
	return dir
}

func quoteName(s string) string {
	if s == "" {
		return "(none)"
	}
	return `"` + state.Clip(s, 64) + `"`
}

func workspaceManager(fsys fs.FS, root string, m *manifest) string {
	if m != nil {
		if name, _, ok := strings.Cut(m.PackageManager, "@"); ok {
			switch name {
			case "pnpm", "yarn", "npm", "bun":
				return name
			}
		}
	}
	switch {
	case exists(fsys, path.Join(root, "pnpm-workspace.yaml")), exists(fsys, path.Join(root, "pnpm-lock.yaml")):
		return "pnpm"
	case exists(fsys, path.Join(root, "bun.lock")), exists(fsys, path.Join(root, "bun.lockb")):
		return "bun"
	case exists(fsys, path.Join(root, "yarn.lock")):
		return "yarn"
	}
	return "npm"
}

// workspacePackages maps the package names of the workspace to their
// folders relative to the workspace root.
func workspacePackages(fsys fs.FS, root string, globs globSet) map[string]string {
	out := map[string]string{}
	seen := 0
	_ = fs.WalkDir(fsys, nonEmpty(root), func(p string, d fs.DirEntry, err error) error {
		if err != nil || !d.IsDir() {
			return nil //nolint:nilerr // unreadable folders are skipped
		}
		rel := strings.TrimPrefix(strings.TrimPrefix(p, root), "/")
		if p == nonEmpty(root) {
			rel = ""
		}
		name := d.Name()
		if rel != "" && (name == "node_modules" || name == ".git" || strings.HasPrefix(name, ".")) {
			return fs.SkipDir
		}
		if seen++; seen > maxWorkspaceDirs {
			return fs.SkipAll
		}
		if rel != "" && strings.Count(rel, "/") >= maxWorkspaceDepth {
			return fs.SkipDir
		}
		if globs.match(rel) {
			if m, err := readManifest(fsys, p); err == nil && m.Name != "" {
				if _, dup := out[m.Name]; !dup {
					out[m.Name] = rel
				}
			}
		}
		return nil
	})
	return out
}

// workspaceDeps resolves the app's workspace dependencies transitively
// (dependencies, devDependencies and optionalDependencies: building a
// package needs its dev tooling too) and returns their folders relative to
// the checkout.
func workspaceDeps(fsys fs.FS, root string, globs globSet, appRel string, app *manifest) []string {
	pkgs := workspacePackages(fsys, root, globs)
	visited := map[string]bool{appRel: true}
	var out []string
	queue := []*manifest{app}
	for len(queue) > 0 && len(out) < 64 {
		m := queue[0]
		queue = queue[1:]
		for _, deps := range []map[string]string{m.Dependencies, m.DevDependencies, m.OptionalDependencies} {
			for _, name := range slices.Sorted(maps.Keys(deps)) {
				dir, ok := pkgs[name]
				if !ok || visited[dir] {
					continue
				}
				visited[dir] = true
				out = append(out, path.Join(root, dir))
				if dm, err := readManifest(fsys, path.Join(nonEmpty(root), dir)); err == nil {
					queue = append(queue, dm)
				}
			}
		}
	}
	slices.Sort(out)
	if len(out) > 64 {
		out = out[:64]
	}
	return out
}

// exec runs a workspace binary with the manager ("pnpm exec turbo").
func (w *workspace) exec(bin string) string {
	switch w.Manager {
	case "pnpm":
		return "pnpm exec " + bin
	case "yarn":
		return "yarn " + bin
	case "bun":
		return "bunx " + bin
	}
	return "npx " + bin
}

// buildCommand builds the app and the workspace packages it depends on,
// from the workspace root. Turborepo runs in loose env mode so build
// variables (and launchroom's own, like the Next.js adapter's) reach the
// app's build task.
func (w *workspace) buildCommand() string {
	switch w.Tool {
	case "turbo":
		return w.exec("turbo") + " run build --filter=" + w.Package + "... --env-mode=loose"
	case "nx":
		return w.exec("nx") + " run " + w.Package + ":build"
	}
	switch w.Manager {
	case "pnpm":
		return "pnpm --filter " + w.Package + "... run build"
	case "yarn":
		return "yarn workspace " + w.Package + " run build"
	case "bun":
		return "bun run --filter " + w.Package + " build"
	}
	return "npm run build --workspace=" + w.Package + " --if-present"
}

// inApp runs a command from the app folder (the workspace root is /app).
func (w *workspace) inApp(cmd string) string {
	return "cd " + w.App + " && " + cmd
}

// startCommand is the app's own start, run from its folder.
func (w *workspace) startCommand() string {
	if _, ok := w.Scripts["start"]; !ok && w.Main != "" && !strings.ContainsAny(w.Main, " '\"$`;&|<>\\") {
		return w.inApp("node " + w.Main)
	}
	return w.inApp(w.Manager + " run start")
}

// reNextStandaloneStart is the start command of Next.js standalone output.
// In a workspace, Next.js traces from the workspace root, so server.js sits
// under the app's folder inside the standalone output; it changes into its
// own folder itself.
var reNextStandaloneStart = regexp.MustCompile(`^node (\.next/standalone)/server\.js$`)

// startFromApp rewrites a preset's start command (written for the app
// folder) for a workspace build.
func (w *workspace) startFromApp(cmd string) string {
	if m := reNextStandaloneStart.FindStringSubmatch(cmd); m != nil {
		return "node " + path.Join(w.App, m[1], w.App, "server.js")
	}
	return w.inApp(cmd)
}

// summary is the protocol form.
func (w *workspace) summary() *state.WorkspaceInfo {
	info := &state.WorkspaceInfo{Root: state.RelPath(w.Root), PackageName: w.Package, Manager: state.Ident(w.Manager), Tool: state.Ident(w.Tool)}
	for _, d := range w.DependsOn {
		if state.RelPath(d).Validate() == nil {
			info.DependsOn = append(info.DependsOn, state.RelPath(d))
		}
	}
	return info
}

// patch rewrites a preset's plan patch, written for the app folder, for a
// build planned at the workspace root: commands run from the app folder and
// deploy paths are under it.
func (w *workspace) patch(p *presets.PlanPatch) {
	for i, c := range p.AppendBuildCommands {
		p.AppendBuildCommands[i] = w.inApp(c)
	}
	if p.StartCommand != "" {
		p.StartCommand = w.startFromApp(p.StartCommand)
	}
	for i, d := range p.DeployOnly {
		p.DeployOnly[i] = path.Join(w.App, d)
	}
}

// rootName is the workspace root for people.
func (w *workspace) rootName() string {
	if w.Root == "" {
		return "the repository root"
	}
	return w.Root
}

// reTurboPrune is the Turborepo Docker recipe (turbo prune <app> --docker),
// which needs the workspace root as its build context.
var reTurboPrune = regexp.MustCompile(`\bturbo\s+prune\b`)

// dockerfileWantsWorkspace says whether a Dockerfile in a workspace app's
// folder is written for the workspace root as its context: it runs turbo
// prune, or copies paths under the app's folder.
func dockerfileWantsWorkspace(content string, w *workspace) bool {
	return reTurboPrune.MatchString(content) || strings.Contains(content, w.App+"/") || strings.Contains(content, "pnpm-workspace.yaml")
}

// describe is the plan log line.
func (w *workspace) describe() string {
	kind := w.Manager
	switch w.Tool {
	case "turbo":
		kind += " + Turborepo"
	case "nx":
		kind += " + Nx"
	}
	line := "Workspace app " + w.Package + " in a " + kind + " monorepo: building from " + w.rootName()
	if len(w.DependsOn) > 0 {
		line += " with " + strings.Join(w.DependsOn, ", ")
	}
	return line
}

// railpackEnv sets the workspace build and start commands, unless the
// service has its own: those were written for the app folder, so they run
// there.
func (w *workspace) railpackEnv(env map[string]string, bs state.BuilderSpec) {
	if _, set := bs.RailpackEnv["RAILPACK_BUILD_CMD"]; !set {
		if bs.BuildCommand != "" {
			env["RAILPACK_BUILD_CMD"] = w.inApp(bs.BuildCommand)
		} else {
			env["RAILPACK_BUILD_CMD"] = w.buildCommand()
		}
	}
	if _, set := bs.RailpackEnv["RAILPACK_START_CMD"]; !set {
		if bs.StartCommand != "" {
			env["RAILPACK_START_CMD"] = w.inApp(bs.StartCommand)
		} else {
			env["RAILPACK_START_CMD"] = w.startCommand()
		}
	}
}
