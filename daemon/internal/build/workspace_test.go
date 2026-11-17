package build

import (
	"encoding/json"
	"path/filepath"
	"slices"
	"strings"
	"testing"
	"testing/fstest"

	rpplan "github.com/railwayapp/railpack/core/plan"

	"github.com/vantezzen/launchroom/daemon/internal/build/presets"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

func pkg(name string, deps map[string]string, extra string) *fstest.MapFile {
	raw, _ := json.Marshal(deps)
	body := `{"name":"` + name + `","scripts":{"build":"x","start":"y"},"dependencies":` + string(raw) + extra + `}`
	return &fstest.MapFile{Data: []byte(body)}
}

func file(s string) *fstest.MapFile { return &fstest.MapFile{Data: []byte(s)} }

// The workspace-root table: which app folders build from a workspace root,
// and which stay on their own.
func TestFindWorkspace(t *testing.T) {
	turboPnpm := fstest.MapFS{
		"package.json":                   file(`{"name":"root","private":true,"packageManager":"pnpm@9.0.0"}`),
		"pnpm-workspace.yaml":            file("packages:\n  - \"apps/*\"\n  - \"packages/*\"\n  - \"!packages/legacy\"\n"),
		"pnpm-lock.yaml":                 file("lockfileVersion: '9.0'\n"),
		"turbo.json":                     file(`{"tasks":{}}`),
		"apps/web/package.json":          pkg("web", map[string]string{"@repo/ui": "workspace:*", "next": "16.0.0"}, `,"devDependencies":{"@repo/tsconfig":"workspace:*"}`),
		"apps/docs/package.json":         pkg("docs", nil, ""),
		"packages/ui/package.json":       pkg("@repo/ui", map[string]string{"@repo/tokens": "workspace:*"}, ""),
		"packages/tokens/package.json":   pkg("@repo/tokens", nil, ""),
		"packages/tsconfig/package.json": pkg("@repo/tsconfig", nil, ""),
		"packages/legacy/package.json":   pkg("legacy", nil, ""),
		"tools/script/package.json":      pkg("script", nil, ""),
	}
	npmNested := fstest.MapFS{
		"README.md":                         file("hi"),
		"js/package.json":                   file(`{"name":"js-root","workspaces":["services/**"]}`),
		"js/package-lock.json":              file("{}"),
		"js/services/api/v2/package.json":   pkg("@acme/api", map[string]string{"@acme/lib": "^1.0.0"}, ""),
		"js/services/lib/package.json":      pkg("@acme/lib", nil, ""),
		"js/services/isolated/package.json": pkg("isolated", nil, ""),
		"js/services/isolated/yarn.lock":    file(""),
		"js/services/Bad/package.json":      pkg("Bad Name", nil, ""),
	}
	yarnObj := fstest.MapFS{
		"package.json":           file(`{"name":"r","workspaces":{"packages":["apps/*"]}}`),
		"yarn.lock":              file(""),
		"apps/site/package.json": pkg("site", nil, ""),
	}
	nx := fstest.MapFS{
		"package.json":           file(`{"name":"r","workspaces":["apps/*","libs/*"]}`),
		"bun.lock":               file(""),
		"nx.json":                file(`{}`),
		"apps/shop/package.json": pkg("shop", map[string]string{"cart": "*"}, ""),
		"libs/cart/package.json": pkg("cart", nil, ""),
	}
	for _, c := range []struct {
		name    string
		fs      fstest.MapFS
		app     string
		root    string
		rel     string
		manager string
		tool    string
		deps    []string
		build   string
		note    bool
	}{
		{name: "pnpm turbo", fs: turboPnpm, app: "apps/web", rel: "apps/web", manager: "pnpm", tool: "turbo",
			deps: []string{"packages/tokens", "packages/tsconfig", "packages/ui"}, build: "pnpm exec turbo run build --filter=web... --env-mode=loose"},
		{name: "pnpm excluded by a negated glob", fs: turboPnpm, app: "packages/legacy"},
		{name: "outside the globs", fs: turboPnpm, app: "tools/script"},
		{name: "the workspace root itself", fs: turboPnpm, app: ""},
		{name: "npm nested root, recursive glob", fs: npmNested, app: "js/services/api/v2", root: "js", rel: "services/api/v2", manager: "npm",
			deps: []string{"js/services/lib"}, build: "npm run build --workspace=@acme/api --if-present"},
		{name: "isolated app with its own lockfile", fs: npmNested, app: "js/services/isolated"},
		{name: "name not usable in a command", fs: npmNested, app: "js/services/Bad", note: true},
		{name: "yarn object form", fs: yarnObj, app: "apps/site", rel: "apps/site", manager: "yarn", build: "yarn workspace site run build"},
		{name: "nx with bun", fs: nx, app: "apps/shop", rel: "apps/shop", manager: "bun", tool: "nx", deps: []string{"libs/cart"}, build: "bunx nx run shop:build"},
	} {
		t.Run(c.name, func(t *testing.T) {
			ws, note := findWorkspace(c.fs, c.app)
			if (note != "") != c.note {
				t.Fatalf("note %q", note)
			}
			if c.rel == "" {
				if ws != nil {
					t.Fatalf("workspace %+v for an app built on its own", ws)
				}
				return
			}
			if ws == nil {
				t.Fatal("no workspace")
			}
			if ws.Root != c.root || ws.App != c.rel || ws.Manager != c.manager || ws.Tool != c.tool || !slices.Equal(ws.DependsOn, c.deps) {
				t.Fatalf("workspace %+v", ws)
			}
			if got := ws.buildCommand(); got != c.build {
				t.Fatalf("build %q, want %q", got, c.build)
			}
			if err := ws.summary().Root.Validate(); c.root != "" && err != nil {
				t.Fatal(err)
			}
		})
	}
}

func TestWorkspaceCommands(t *testing.T) {
	ws := &workspace{App: "apps/web", Package: "web", Manager: "pnpm", Scripts: map[string]string{"start": "next start"}}
	if got := ws.startCommand(); got != "cd apps/web && pnpm run start" {
		t.Fatalf("start %q", got)
	}
	if got := ws.startFromApp("node .next/standalone/server.js"); got != "node apps/web/.next/standalone/apps/web/server.js" {
		t.Fatalf("next start %q", got)
	}
	if got := ws.startFromApp("node build"); got != "cd apps/web && node build" {
		t.Fatalf("start %q", got)
	}
	p := presets.PlanPatch{AppendBuildCommands: []string{"node /opt/x/cli.js"}, StartCommand: "node dist/server.js", DeployOnly: []string{".next/standalone"}}
	ws.patch(&p)
	if p.AppendBuildCommands[0] != "cd apps/web && node /opt/x/cli.js" || p.StartCommand != "cd apps/web && node dist/server.js" || p.DeployOnly[0] != "apps/web/.next/standalone" {
		t.Fatalf("patch %+v", p)
	}

	// The service's own commands were written for the app folder.
	env := map[string]string{}
	ws.railpackEnv(env, state.BuilderSpec{BuildCommand: "npm run build:prod"})
	if env["RAILPACK_BUILD_CMD"] != "cd apps/web && npm run build:prod" || env["RAILPACK_START_CMD"] != "cd apps/web && pnpm run start" {
		t.Fatalf("env %v", env)
	}
	env = map[string]string{}
	ws.railpackEnv(env, state.BuilderSpec{RailpackEnv: map[state.RailpackEnvName]string{"RAILPACK_BUILD_CMD": "make"}})
	if _, set := env["RAILPACK_BUILD_CMD"]; set {
		t.Fatalf("overrode the service's RAILPACK_BUILD_CMD: %v", env)
	}

	turbo := `FROM node:24 AS builder
RUN npm i -g turbo
COPY . .
RUN turbo prune web --docker`
	if !dockerfileWantsWorkspace(turbo, ws) || dockerfileWantsWorkspace("FROM node:24\nCOPY . .\nRUN npm ci", ws) {
		t.Fatal("Dockerfile context heuristic")
	}
}

// newTree writes files under a fresh directory.
func newTree(t *testing.T, files map[string]string) string {
	t.Helper()
	dir := t.TempDir()
	writeTree(t, dir, files)
	return dir
}

// A Turborepo app plans at the workspace root: Railpack installs the whole
// workspace, the build is filtered to the app, it starts from its folder,
// and the build context is the workspace root.
func TestResolveWorkspaceApp(t *testing.T) {
	repo := newTree(t, map[string]string{
		"package.json":              `{"name":"root","private":true,"packageManager":"pnpm@9.15.0","scripts":{"build":"turbo run build"},"devDependencies":{"turbo":"2.5.0"}}`,
		"pnpm-workspace.yaml":       "packages:\n  - apps/*\n  - packages/*\n",
		"pnpm-lock.yaml":            "lockfileVersion: '9.0'\n",
		"turbo.json":                `{"tasks":{"build":{"dependsOn":["^build"]}}}`,
		"apps/api/package.json":     `{"name":"api","scripts":{"build":"tsc","start":"node dist/index.js"},"dependencies":{"express":"^5.1.0","@repo/lib":"workspace:*"}}`,
		"apps/api/src/index.ts":     "import express from 'express'\n",
		"packages/lib/package.json": `{"name":"@repo/lib","scripts":{"build":"tsc"}}`,
	})
	dir := filepath.Join(repo, "apps", "api")
	ws, wsDir, note := detectWorkspace(repo, dir)
	if ws == nil || note != "" {
		t.Fatalf("no workspace (%q)", note)
	}
	if resolved, _ := filepath.EvalSymlinks(repo); wsDir != resolved {
		t.Fatalf("workspace dir %s", wsDir)
	}
	sink := &lineSink{}
	job := &Job{Inputs: state.BuildInputs{Builder: state.BuilderSpec{Kind: state.BuilderAuto, RailpackVersion: RailpackVersion}}, workspace: ws, contextDir: wsDir}
	r, err := (&Builder{}).resolve(dir, t.TempDir(), job, nil, sink)
	if err != nil {
		t.Fatalf("resolve: %v\n%s", err, strings.Join(sink.lines, "\n"))
	}
	if r.contextDir != wsDir {
		t.Fatalf("context %s", r.contextDir)
	}
	w := r.summary.Workspace
	if w == nil || w.PackageName != "api" || w.Manager != "pnpm" || w.Tool != "turbo" || w.Root != "" || !slices.Equal(w.DependsOn, []state.RelPath{"packages/lib"}) {
		t.Fatalf("workspace summary %+v", w)
	}
	validSummary(t, r.summary)
	if !strings.Contains(r.summary.BuildCommand, "turbo run build --filter=api... --env-mode=loose") {
		t.Fatalf("build command %q", r.summary.BuildCommand)
	}
	var plan rpplan.BuildPlan
	if err := json.Unmarshal(r.plan, &plan); err != nil {
		t.Fatal(err)
	}
	if !strings.HasPrefix(plan.Deploy.StartCmd, "cd apps/api && ") {
		t.Fatalf("start %q", plan.Deploy.StartCmd)
	}
	if !slices.Contains(sink.lines, "[plan] Workspace app api in a pnpm + Turborepo monorepo: building from the repository root with packages/lib") {
		t.Fatalf("log %q", sink.lines)
	}
}

// A PHP app with a Vite frontend inside a JS workspace still plans from its
// own folder.
func TestDetectWorkspaceSkipsOtherLanguages(t *testing.T) {
	repo := newTree(t, map[string]string{
		"package.json":           `{"name":"root","workspaces":["apps/*"]}`,
		"apps/web/package.json":  `{"name":"web"}`,
		"apps/web/composer.json": `{}`,
	})
	if ws, _, _ := detectWorkspace(repo, filepath.Join(repo, "apps", "web")); ws != nil {
		t.Fatalf("workspace %+v", ws)
	}
}

// validSummary fails unless the summary is a valid protocol document.
func validSummary(t *testing.T, s state.BuildSummary) {
	t.Helper()
	c := &state.TaskCompletion{TypeMeta: state.NewTypeMeta(state.KindTaskCompletion), Attempt: 1, Outcome: state.OutcomeSucceeded,
		TaskResults: state.TaskResults{Plan: &state.PlanResult{DurationMs: 1, Summary: s}}}
	if err := c.Validate(); err != nil {
		t.Fatalf("summary is not a valid protocol document: %v", err)
	}
}
