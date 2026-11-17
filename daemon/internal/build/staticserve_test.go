package build

import (
	"encoding/json"
	"os"
	"path/filepath"
	"slices"
	"strings"
	"testing"
	"testing/fstest"

	rpplan "github.com/railwayapp/railpack/core/plan"

	"github.com/vantezzen/launchroom/daemon/internal/build/presets"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

func staticCaddyfile(t *testing.T, raw []byte) string {
	t.Helper()
	var plan rpplan.BuildPlan
	if err := json.Unmarshal(raw, &plan); err != nil {
		t.Fatal(err)
	}
	for _, s := range plan.Steps {
		if s.Name == staticCaddyStep {
			return s.Assets[phpCaddyfileAsset]
		}
	}
	t.Fatal("the plan has no caddy step")
	return ""
}

// Railpack's static-site Caddyfile gets cache headers (and, for a site built
// under a base path, the prefix stripped). If a Railpack upgrade changes its
// shape, this test fails instead of sites silently losing them.
func TestRailpackStaticCaddyfileIsAdjusted(t *testing.T) {
	dir := copyRepo(t, "vite-spa")
	if err := os.WriteFile(filepath.Join(dir, "vite.config.js"), []byte("export default { base: '/app/' }\n"), 0o600); err != nil {
		t.Fatal(err)
	}
	r, sink := resolveFor(t, dir, state.BuilderSpec{Kind: state.BuilderAuto, RailpackVersion: RailpackVersion})
	if i := slices.IndexFunc(r.summary.Warnings, func(w state.BuildWarning) bool { return w.Code == "static-server-not-adjusted" }); i >= 0 {
		t.Fatalf("Railpack's static Caddyfile changed shape: %s", r.summary.Warnings[i].Message)
	}
	cf := staticCaddyfile(t, r.plan)
	for _, want := range []string{
		"uri strip_prefix /app",
		`header @launchroom_hashed Cache-Control "public, max-age=31536000, immutable"`,
		"@launchroom_hashed path /assets/* /app/assets/*",
		`header @launchroom_revalidate Cache-Control "no-cache"`,
		"try_files {path} {path}.html {path}/index.html /index.html",
	} {
		if !strings.Contains(cf, want) {
			t.Errorf("Caddyfile lacks %q:\n%s", want, cf)
		}
	}
	if strings.Contains(cf, "handle_errors") {
		t.Errorf("a single-page app got 404 pages:\n%s", cf)
	}
	if !slices.Contains(sink.lines, "[plan] Adjusted the static site server: hashed assets cached for a year, HTML revalidated, served under /app") {
		t.Fatalf("log %q", sink.lines)
	}

	off := state.BuilderSpec{Kind: state.BuilderAuto, RailpackVersion: RailpackVersion, DisabledDecisions: []state.Slug{staticCacheDecision, staticBaseDecision}}
	r, _ = resolveFor(t, dir, off)
	if cf := staticCaddyfile(t, r.plan); strings.Contains(cf, "launchroom") || strings.Contains(cf, "strip_prefix") {
		t.Fatalf("disabled adjustments applied:\n%s", cf)
	}
}

func TestAdjustStaticServer(t *testing.T) {
	const railpack = "{\n}\n:{$PORT:80} {\n\trespond /health 200\n\troot * /app/dist\n\tfile_server\n\ttry_files {path} {path}.html {path}/index.html /index.html\n}\n"
	plan := func(cf string) *rpplan.BuildPlan {
		return &rpplan.BuildPlan{Steps: []rpplan.Step{{Name: staticCaddyStep, Assets: map[string]string{phpCaddyfileAsset: cf}}}}
	}
	p := plan(railpack)
	if err := adjustStaticServer(p, staticShims{MultiPage: true}); err != nil {
		t.Fatal(err)
	}
	want := "{\n}\n:{$PORT:80} {\n\trespond /health 200\n\n\thandle_errors 404 {\n\t\troot * /app/dist\n\t\trewrite * /404.html\n\t\tfile_server\n\t}\n" +
		"\troot * /app/dist\n\tfile_server\n\ttry_files {path} {path}.html {path}/index.html\n}\n"
	if got := p.Steps[0].Assets[phpCaddyfileAsset]; got != want {
		t.Fatalf("adjusted:\n%s\nwant:\n%s", got, want)
	}
	for name, cf := range map[string]string{
		"no health":    strings.Replace(railpack, "\trespond /health 200\n", "", 1),
		"own fallback": strings.Replace(railpack, " /index.html\n", " /200.html\n", 1),
		"two roots":    strings.Replace(railpack, "\tfile_server", "\troot * /x\n\tfile_server", 1),
	} {
		p := plan(cf)
		if err := adjustStaticServer(p, staticShims{Cache: true}); err == nil {
			t.Errorf("%s: adjusted an unexpected Caddyfile", name)
		}
		if p.Steps[0].Assets[phpCaddyfileAsset] != cf {
			t.Errorf("%s: a refused Caddyfile was changed", name)
		}
	}

	if multiPageSite(presetsFacts(`{"dependencies":{"astro":"5"}}`)) != true || multiPageSite(presetsFacts(`{"devDependencies":{"vite":"7"}}`)) {
		t.Fatal("multi-page detection")
	}
	for cfg, want := range map[string]string{
		"export default defineConfig({ base: '/docs/', plugins: [] })": "/docs",
		"export default { base: './' }":                                "",
		"export default { base: '/' }":                                 "",
		"export default { base: '/a b/' }":                             "",
	} {
		if got := viteBase(fstest.MapFS{"vite.config.ts": file(cfg)}); got != want {
			t.Errorf("viteBase(%q) = %q, want %q", cfg, got, want)
		}
	}
}

func presetsFacts(packageJSON string) presets.Facts {
	return presets.Gather(fstest.MapFS{"package.json": file(packageJSON)}, nil)
}
