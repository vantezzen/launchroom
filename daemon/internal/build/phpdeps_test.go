package build

import (
	"encoding/json"
	"fmt"
	"maps"
	"os"
	"path/filepath"
	"slices"
	"strings"
	"testing"
	"testing/fstest"

	rpplan "github.com/railwayapp/railpack/core/plan"

	"github.com/vantezzen/launchroom/daemon/internal/state"
)

func lockFile(packages, dev map[string][]string) *fstest.MapFile {
	type p struct {
		Name    string            `json:"name"`
		Require map[string]string `json:"require"`
	}
	conv := func(m map[string][]string) []p {
		var out []p
		for _, name := range slices.Sorted(maps.Keys(m)) {
			req := map[string]string{"php": "^8.2"}
			for _, r := range m[name] {
				req[r] = "*"
			}
			out = append(out, p{Name: name, Require: req})
		}
		return out
	}
	raw, _ := json.Marshal(map[string]any{"packages": conv(packages), "packages-dev": conv(dev)})
	return &fstest.MapFile{Data: raw}
}

func TestPhpDependencyExtensions(t *testing.T) {
	fsys := fstest.MapFS{
		"composer.json": file(`{"name":"acme/shop","require":{"php":"^8.2","ext-gd":"*","laravel/framework":"^12.0"},"require-dev":{"ext-xdebug":"*"},"config":{"platform":{"ext-imagick":"3.7"}}}`),
		"composer.lock": lockFile(map[string][]string{
			"filament/support":    {"ext-intl"},
			"laravel/horizon":     {"ext-pcntl", "ext-posix"},
			"openspout/openspout": {"ext-zip", "ext-dom", "ext-XMLReader"},
			"spatie/image":        {"ext-gd", "ext-imagick"},
			"vendor/odd":          {"ext-blitz"},
			"vendor/zip2":         {"ext-zip"},
		}, map[string][]string{"phpunit/phpunit": {"ext-intl", "ext-bcmath"}}),
	}
	add, unknown := phpDependencyExtensions(fsys)
	want := []state.PhpDependencyExtension{
		{Name: "intl", For: "filament/support"},
		{Name: "pcntl", For: "laravel/horizon"},
		{Name: "zip", For: "openspout/openspout"},
		{Name: "bcmath", For: "phpunit/phpunit"},
		{Name: "xdebug", For: "acme/shop"},
	}
	if !slices.Equal(add, want) {
		t.Fatalf("extensions %+v\nwant %+v", add, want)
	}
	// gd: the app's own require (Railpack installs it); imagick: faked by
	// config.platform; posix, dom, xmlreader: built into the image.
	if !slices.Equal(unknown, []string{"blitz (vendor/odd)"}) {
		t.Fatalf("unknown %q", unknown)
	}
	for _, x := range add {
		if x.Name.Validate() != nil || x.For.Validate() != nil {
			t.Fatalf("invalid %+v", x)
		}
	}

	env := map[string]string{railpackPhpExtensions: "redis,pdo_pgsql"}
	withDependencyExtensions(env, add)
	if env[railpackPhpExtensions] != "redis,pdo_pgsql,intl,pcntl,zip,bcmath,xdebug" {
		t.Fatalf("env %q", env[railpackPhpExtensions])
	}
	if got := describeDependencyExtensions(add[:2]); got != "intl (filament/support), pcntl (laravel/horizon)" {
		t.Fatalf("describe %q", got)
	}
	merged := mergeDependencyExtensions([]state.PhpDependencyExtension{{Name: "pcntl", For: "laravel/framework"}}, add)
	if merged[0].For != "laravel/framework" || len(merged) != len(add) {
		t.Fatalf("merged %+v", merged)
	}

	if add, unknown := phpDependencyExtensions(fstest.MapFS{"composer.json": file(`{}`)}); add != nil || unknown != nil {
		t.Fatalf("no lock: %+v %q", add, unknown)
	}
}

// Real lock files are megabytes; the reader streams up to 16 MB.
func TestPhpDependencyExtensionsLargeLock(t *testing.T) {
	var b strings.Builder
	b.WriteString(`{"packages":[`)
	for i := range 20000 {
		fmt.Fprintf(&b, `{"name":"vendor/pkg%d","description":"%s","require":{"php":"^8.2"}},`, i, strings.Repeat("x", 200))
	}
	b.WriteString(`{"name":"filament/support","require":{"ext-intl":"*"}}]}`)
	if b.Len() < 4<<20 {
		t.Fatalf("lock only %d bytes", b.Len())
	}
	add, _ := phpDependencyExtensions(fstest.MapFS{"composer.json": file(`{}`), "composer.lock": file(b.String())})
	if !slices.Equal(add, []state.PhpDependencyExtension{{Name: "intl", For: "filament/support"}}) {
		t.Fatalf("extensions %+v", add)
	}
}

// A Laravel app whose dependencies need intl and zip gets them in the
// Railpack plan's install-php-extensions step, listed in the summary.
func TestRailpackPlanAddsDependencyExtensions(t *testing.T) {
	dir := copyRepo(t, "laravel")
	if err := os.WriteFile(filepath.Join(dir, "composer.lock"), lockFile(map[string][]string{
		"filament/support": {"ext-intl"}, "openspout/openspout": {"ext-zip"},
	}, nil).Data, 0o600); err != nil {
		t.Fatal(err)
	}
	r, sink := resolveFor(t, dir, state.BuilderSpec{Kind: state.BuilderAuto, RailpackVersion: RailpackVersion})
	names := make([]string, 0, len(r.summary.PhpDependencyExtensions))
	for _, x := range r.summary.PhpDependencyExtensions {
		names = append(names, string(x.Name))
	}
	for _, want := range []string{"intl", "zip", "pcntl"} {
		if !slices.Contains(names, want) {
			t.Fatalf("summary extensions %q lack %s", names, want)
		}
	}
	if !slices.ContainsFunc(sink.lines, func(l string) bool {
		return strings.HasPrefix(l, "[plan] Adds PHP extensions your dependencies need: ") && strings.Contains(l, "intl (filament/support)")
	}) {
		t.Fatalf("log lines %q", sink.lines)
	}
	var plan rpplan.BuildPlan
	if err := json.Unmarshal(r.plan, &plan); err != nil {
		t.Fatal(err)
	}
	install := ""
	for _, s := range plan.Steps {
		for _, c := range s.Commands {
			if ex, ok := c.(rpplan.ExecCommand); ok && strings.HasPrefix(ex.Cmd, "install-php-extensions ") {
				install = ex.Cmd
			}
		}
	}
	for _, want := range []string{" intl", " zip", " pcntl"} {
		if !strings.Contains(install, want) {
			t.Fatalf("install command %q lacks%s", install, want)
		}
	}
	validSummary(t, r.summary)
}

func TestPhpVersionFloor(t *testing.T) {
	for c, want := range map[string]string{
		"^8.2": "8.2", "~8.3.0 || ~8.4.0 || ~8.5.0": "8.3", ">=8.4.1": "8.4", ">=7.2.5|^8.0": "7.2", "*": "", "<9": "", "8.1 - 8.4": "8.1",
	} {
		ma, mi, ok := constraintFloor(c)
		got := ""
		if ok {
			got = phpFloor{Major: ma, Minor: mi}.String()
		}
		if got != want {
			t.Errorf("constraintFloor(%q) = %q, want %q", c, got, want)
		}
	}
	lock := fstest.MapFS{
		"composer.json": file(`{"require":{"php":"^8.2"}}`),
		"composer.lock": file(`{"packages":[{"name":"a/a","require":{"php":"^8.0"}},{"name":"symfony/clock","require":{"php":">=8.4.1"}},{"name":"o/o","require":{"php":"~8.3.0 || ~8.4.0"}}]}`),
	}
	if _, _, f := readComposerLock(lock); f != (phpFloor{Major: 8, Minor: 4, For: "symfony/clock"}) {
		t.Fatalf("floor %+v", f)
	}
	lock["composer.json"] = file(`{"config":{"platform":{"php":"8.2.0"}}}`)
	if _, _, f := readComposerLock(lock); f.Major != 0 {
		t.Fatalf("a pinned platform php must win: %+v", f)
	}
}

// Railpack takes PHP 8.2 from "^8.2"; a lock that needs 8.4 moves the plan.
func TestRailpackPhpVersionFollowsLock(t *testing.T) {
	dir := copyRepo(t, "laravel")
	if err := os.WriteFile(filepath.Join(dir, "composer.json"), []byte(`{"name":"acme/shop","require":{"php":"^8.2","laravel/framework":"^12.0"}}`), 0o600); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(filepath.Join(dir, "composer.lock"), []byte(`{"packages":[{"name":"symfony/clock","require":{"php":">=8.4.1"}}]}`), 0o600); err != nil {
		t.Fatal(err)
	}
	r, sink := resolveFor(t, dir, state.BuilderSpec{Kind: state.BuilderAuto, RailpackVersion: RailpackVersion})
	if !strings.Contains(string(r.plan), `"dunglas/frankenphp:php8.4-trixie"`) || strings.Contains(string(r.plan), "frankenphp:php8.2") {
		t.Fatalf("plan images: %s", r.plan)
	}
	if !slices.ContainsFunc(sink.lines, func(l string) bool {
		return strings.HasPrefix(l, "[plan] Uses PHP 8.4: composer.lock needs it (symfony/clock)")
	}) {
		t.Fatalf("log %q", sink.lines)
	}
	validSummary(t, r.summary)
}
