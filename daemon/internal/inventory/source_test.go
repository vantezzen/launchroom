package inventory

import (
	"os"
	"path/filepath"
	"slices"
	"strings"
	"testing"

	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// checkout writes files (slash paths → content) into a temp dir and analyses app.
func checkout(t *testing.T, app string, files map[string]string) *sourceFacts {
	t.Helper()
	dir := t.TempDir()
	for p, c := range files {
		full := filepath.Join(dir, filepath.FromSlash(p))
		if err := os.MkdirAll(filepath.Dir(full), 0o750); err != nil {
			t.Fatal(err)
		}
		if err := os.WriteFile(full, []byte(c), 0o644); err != nil {
			t.Fatal(err)
		}
	}
	root, err := os.OpenRoot(dir)
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { _ = root.Close() })
	return analyze(root, app)
}

func wantScopes(t *testing.T, f *npmFacts, prod, dev []string) {
	t.Helper()
	if f == nil {
		t.Fatal("no npm facts")
	}
	for _, k := range prod {
		if !f.prod[k] {
			t.Errorf("%s is not production (prod=%v)", k, f.prod)
		}
	}
	for _, k := range dev {
		if !f.dev[k] || f.prod[k] {
			t.Errorf("%s is not dev-only (dev=%v prod=%v)", k, f.dev, f.prod)
		}
	}
}

const appManifest = `{"name":"web","dependencies":{"next":"^16.0.0","lodash":"^4.17.0"},"devDependencies":{"vitest":"^3.0.0"}}`

func TestPackageLockWorkspace(t *testing.T) {
	s := checkout(t, "apps/web", map[string]string{
		"apps/web/package.json": appManifest,
		"package-lock.json": `{"lockfileVersion":3,"packages":{
			"":{"name":"mono","workspaces":["apps/*"],"devDependencies":{"turbo":"^2"}},
			"apps/web":{"name":"web","dependencies":{"next":"^16.0.0","lodash":"^4.17.0"},"devDependencies":{"vitest":"^3.0.0"}},
			"node_modules/web":{"resolved":"apps/web","link":true},
			"node_modules/next":{"version":"16.4.2","dependencies":{"styled-jsx":"5.1.6"}},
			"node_modules/styled-jsx":{"version":"5.1.6"},
			"node_modules/lodash":{"version":"4.17.20"},
			"node_modules/vitest":{"version":"3.2.4","dev":true,"dependencies":{"tinyspy":"^4"}},
			"node_modules/vitest/node_modules/tinyspy":{"version":"4.0.3","dev":true},
			"node_modules/turbo":{"version":"2.5.0","dev":true}
		}}`,
	})
	wantScopes(t, s.npm, []string{"next@16.4.2", "styled-jsx@5.1.6", "lodash@4.17.20"}, []string{"vitest@3.2.4", "tinyspy@4.0.3"})
	if s.npm.prod["turbo@2.5.0"] || s.npm.dev["turbo@2.5.0"] {
		t.Error("the workspace root's own dev dependency counted for the app")
	}
	if !s.npm.direct["next"] || !s.npm.direct["vitest"] || s.npm.direct["styled-jsx"] {
		t.Errorf("direct = %v", s.npm.direct)
	}
	want := []state.InventoryLockfile{{Path: "apps/web/package.json", Kind: state.LockfileNpm, Packages: 3}, {Path: "package-lock.json", Kind: state.LockfileNpm, Packages: 6}}
	if !slices.Equal(s.lockfiles, want) {
		t.Errorf("lockfiles = %+v", s.lockfiles)
	}
}

func TestPnpm(t *testing.T) {
	v9 := `lockfileVersion: '9.0'
importers:
  .:
    devDependencies:
      turbo:
        specifier: ^2
        version: 2.5.0
  apps/web:
    dependencies:
      next:
        specifier: ^16.0.0
        version: 16.4.2(react@19.1.0)
      lodash:
        specifier: ^4.17.0
        version: 4.17.20
      shared:
        specifier: workspace:*
        version: link:../../packages/shared
    devDependencies:
      vitest:
        specifier: ^3.0.0
        version: 3.2.4
packages:
  next@16.4.2:
    resolution: {integrity: sha512-x}
snapshots:
  next@16.4.2(react@19.1.0):
    dependencies:
      react: 19.1.0
      styled-jsx: 5.1.6(react@19.1.0)
  react@19.1.0: {}
  styled-jsx@5.1.6(react@19.1.0):
    dependencies:
      react: 19.1.0
  lodash@4.17.20: {}
  vitest@3.2.4:
    dependencies:
      tinyspy: 4.0.3
  tinyspy@4.0.3: {}
  turbo@2.5.0: {}
`
	s := checkout(t, "apps/web", map[string]string{"apps/web/package.json": appManifest, "pnpm-lock.yaml": v9})
	wantScopes(t, s.npm, []string{"next@16.4.2", "react@19.1.0", "styled-jsx@5.1.6", "lodash@4.17.20"}, []string{"vitest@3.2.4", "tinyspy@4.0.3"})

	v6 := `lockfileVersion: '6.0'
dependencies:
  lodash:
    specifier: ^4.17.0
    version: 4.17.20
  '@scope/a':
    specifier: ^1.0.0
    version: 1.0.0
devDependencies:
  vitest:
    specifier: ^3.0.0
    version: 3.2.4
packages:
  /lodash@4.17.20:
    dev: false
  /@scope/a@1.0.0:
    dependencies:
      b: 2.0.0
    dev: false
  /b@2.0.0:
    dev: false
  /vitest@3.2.4:
    dependencies:
      tinyspy: 4.0.3
    dev: true
  /tinyspy@4.0.3:
    dev: true
`
	s = checkout(t, "", map[string]string{"package.json": `{"dependencies":{"lodash":"^4"}}`, "pnpm-lock.yaml": v6})
	wantScopes(t, s.npm, []string{"lodash@4.17.20", "@scope/a@1.0.0", "b@2.0.0"}, []string{"vitest@3.2.4", "tinyspy@4.0.3"})
}

func TestYarn(t *testing.T) {
	v1 := `# yarn lockfile v1


"@scope/a@^1.0.0":
  version "1.0.1"
  dependencies:
    b "^2.0.0"

b@^2.0.0, b@^2.1.0:
  version "2.1.0"

lodash@^4.17.0:
  version "4.17.20"

vitest@^3.0.0:
  version "3.2.4"
  dependencies:
    tinyspy "^4"
    b "^2.1.0"

tinyspy@^4:
  version "4.0.3"
`
	manifest := `{"dependencies":{"@scope/a":"^1.0.0","lodash":"^4.17.0"},"devDependencies":{"vitest":"^3.0.0"}}`
	s := checkout(t, "", map[string]string{"package.json": manifest, "yarn.lock": v1})
	wantScopes(t, s.npm, []string{"@scope/a@1.0.1", "b@2.1.0", "lodash@4.17.20"}, []string{"vitest@3.2.4", "tinyspy@4.0.3"})

	berry := `__metadata:
  version: 8
  cacheKey: 10c0

"@scope/a@npm:^1.0.0":
  version: 1.0.1
  resolution: "@scope/a@npm:1.0.1"
  dependencies:
    b: "npm:^2.0.0"
  languageName: node
  linkType: hard

"b@npm:^2.0.0":
  version: 2.1.0
  resolution: "b@npm:2.1.0"

"lodash@npm:^4.17.0":
  version: 4.17.20
  resolution: "lodash@npm:4.17.20"

"vitest@npm:^3.0.0":
  version: 3.2.4
  resolution: "vitest@npm:3.2.4"
  dependencies:
    tinyspy: "npm:^4"

"tinyspy@npm:^4":
  version: 4.0.3
  resolution: "tinyspy@npm:4.0.3"

"web@workspace:.":
  version: 0.0.0-use.local
  resolution: "web@workspace:."
  dependencies:
    "@scope/a": "npm:^1.0.0"
    lodash: "npm:^4.17.0"
    vitest: "npm:^3.0.0"
  languageName: unknown
  linkType: soft
`
	s = checkout(t, "", map[string]string{"package.json": manifest, "yarn.lock": berry})
	wantScopes(t, s.npm, []string{"@scope/a@1.0.1", "b@2.1.0", "lodash@4.17.20"}, []string{"vitest@3.2.4", "tinyspy@4.0.3"})
	if s.npm.prod["web@0.0.0-use.local"] {
		t.Error("the workspace itself counted as a package")
	}
}

func TestBun(t *testing.T) {
	lock := `{
  "lockfileVersion": 1,
  "workspaces": {
    "": {
      "name": "web",
      "dependencies": { "lodash": "^4.17.0", "@scope/a": "^1.0.0", },
      "devDependencies": { "vitest": "^3.0.0", },
    },
  },
  "packages": {
    "@scope/a": ["@scope/a@1.0.1", "", { "dependencies": { "b": "^2.0.0" } }, "sha512-a"],
    "@scope/a/b": ["b@1.9.0", "", {}, "sha512-b1"],
    "b": ["b@2.1.0", "", {}, "sha512-b2"],
    "lodash": ["lodash@4.17.20", "", {}, "sha512-l"],
    "vitest": ["vitest@3.2.4", "", { "dependencies": { "tinyspy": "^4", "b": "^2" } }, "sha512-v"],
    "tinyspy": ["tinyspy@4.0.3", "", {}, "sha512-t"],
  }
}`
	s := checkout(t, "", map[string]string{"package.json": `{"dependencies":{"lodash":"^4"}}`, "bun.lock": lock})
	wantScopes(t, s.npm, []string{"@scope/a@1.0.1", "b@1.9.0", "lodash@4.17.20"}, []string{"vitest@3.2.4", "tinyspy@4.0.3", "b@2.1.0"})
}

func TestNoLockfile(t *testing.T) {
	s := checkout(t, "", map[string]string{"package.json": appManifest})
	if s.npm == nil || s.npm.lockfile || len(s.npm.prod) != 0 || !s.npm.direct["next"] {
		t.Errorf("npm = %+v", s.npm)
	}
	if len(s.notes) != 1 || !strings.Contains(s.notes[0], "No lockfile found") {
		t.Errorf("notes = %v", s.notes)
	}
	// Garbage never fails the analysis.
	s = checkout(t, "", map[string]string{"package.json": appManifest, "package-lock.json": "{not json", "composer.lock": "[", "poetry.lock": "= =", "go.mod": "\x00"})
	if s.npm == nil || len(s.notes) < 2 {
		t.Errorf("garbage: %+v %v", s.npm, s.notes)
	}
}

func TestPython(t *testing.T) {
	s := checkout(t, "", map[string]string{
		"pyproject.toml": `[project]
name = "api"
dependencies = ["Django>=5", "psycopg[binary]~=3.2; python_version > '3.10'"]

[dependency-groups]
dev = ["pytest>=8", {include-group = "lint"}]
lint = ["ruff"]
`,
		"uv.lock": `version = 1
[[package]]
name = "api"
version = "0.1.0"
source = { editable = "." }
dependencies = [{ name = "django" }, { name = "psycopg" }]
[package.dev-dependencies]
dev = [{ name = "pytest" }]

[[package]]
name = "django"
version = "5.2.1"
dependencies = [{ name = "asgiref" }, { name = "sqlparse" }]

[[package]]
name = "asgiref"
version = "3.8.1"

[[package]]
name = "sqlparse"
version = "0.5.3"

[[package]]
name = "psycopg"
version = "3.2.9"

[[package]]
name = "pytest"
version = "8.4.0"
dependencies = [{ name = "pluggy" }, { name = "sqlparse" }]

[[package]]
name = "pluggy"
version = "1.6.0"
`,
		"requirements-dev.txt": "black==25.1.0\n# comment\n-r requirements.txt\n",
	})
	f := s.pypi
	if f == nil {
		t.Fatal("no pypi facts")
	}
	for _, n := range []string{"django", "asgiref", "sqlparse", "psycopg"} {
		if f.devOnly(n) {
			t.Errorf("%s dev-only", n)
		}
	}
	for _, n := range []string{"pytest", "pluggy", "black", "ruff"} {
		if !f.devOnly(n) {
			t.Errorf("%s not dev-only", n)
		}
	}
	if !f.direct["django"] || f.direct["asgiref"] {
		t.Errorf("direct = %v", f.direct)
	}

	s = checkout(t, "", map[string]string{
		"poetry.lock": `[[package]]
name = "Flask"
version = "3.1.0"
groups = ["main"]

[[package]]
name = "pytest-cov"
version = "6.0.0"
groups = ["dev"]
`,
		"Pipfile.lock": `{"default":{"requests":{"version":"==2.32.3"}},"develop":{"mypy":{"version":"==1.15.0"}}}`,
		"pyproject.toml": `[tool.poetry.dependencies]
python = "^3.12"
flask = "^3.1"
[tool.poetry.group.test.dependencies]
pytest-cov = "^6"
`,
	})
	if f := s.pypi; f.devOnly("flask") || !f.devOnly("pytest-cov") || !f.devOnly("mypy") || f.devOnly("requests") || f.main["python"] {
		t.Errorf("poetry/pipfile: %+v", f)
	}
}

func TestComposerGemsGo(t *testing.T) {
	s := checkout(t, "", map[string]string{
		"composer.json": `{"require":{"laravel/framework":"^12"},"require-dev":{"phpunit/phpunit":"^11"}}`,
		"composer.lock": `{"packages":[{"name":"laravel/framework"},{"name":"symfony/console"}],"packages-dev":[{"name":"phpunit/phpunit"},{"name":"sebastian/diff"}]}`,
		"Gemfile": `source "https://rubygems.org"
gem "rails", "~> 8.0"
gem "pg"
gem "debug", group: [:development, :test]
group :development, :test do
  gem "rspec-rails"
  if ENV["X"]
    gem "pry"
  end
end
group :production do
  gem "lograge"
end
gem "bootsnap", require: false
`,
		"Gemfile.lock": "GEM\n  specs:\n    rails (8.0.2)\n      actionpack (= 8.0.2)\n    pg (1.5.9)\n",
		"go.mod":       "module x\n\ngo 1.24\n\nrequire (\n\tgithub.com/a/b v1.2.3\n\tgolang.org/x/net v0.40.0 // indirect\n)\n\nrequire github.com/c/d v0.1.0\n",
	})
	if c := s.composer; c.devOnly("laravel/framework") || c.devOnly("symfony/console") || !c.devOnly("phpunit/phpunit") || !c.devOnly("sebastian/diff") || !c.direct["phpunit/phpunit"] {
		t.Errorf("composer = %+v", c)
	}
	g := s.gems
	for _, n := range []string{"rails", "pg", "lograge", "bootsnap"} {
		if g.devOnly(n) || !g.direct[n] {
			t.Errorf("gem %s", n)
		}
	}
	for _, n := range []string{"debug", "rspec-rails", "pry"} {
		if !g.devOnly(n) {
			t.Errorf("gem %s not dev", n)
		}
	}
	if !s.goDirect["github.com/a/b"] || !s.goDirect["github.com/c/d"] || s.goDirect["golang.org/x/net"] {
		t.Errorf("go = %v", s.goDirect)
	}
	kinds := map[state.LockfileKind]int{}
	for _, l := range s.lockfiles {
		kinds[l.Kind] = l.Packages
	}
	if kinds[state.LockfileComposer] != 4 || kinds[state.LockfileGoMod] != 3 {
		t.Errorf("lockfiles = %+v", s.lockfiles)
	}
}

// Symlinks out of the checkout are never followed (os.Root).
func TestSymlinkContained(t *testing.T) {
	dir := t.TempDir()
	outside := filepath.Join(t.TempDir(), "secret.json")
	if err := os.WriteFile(outside, []byte(appManifest), 0o644); err != nil {
		t.Fatal(err)
	}
	if err := os.Symlink(outside, filepath.Join(dir, "package.json")); err != nil {
		t.Fatal(err)
	}
	root, err := os.OpenRoot(dir)
	if err != nil {
		t.Fatal(err)
	}
	defer func() { _ = root.Close() }()
	if s := analyze(root, ""); s.npm != nil {
		t.Error("a manifest outside the checkout was read")
	}
}
