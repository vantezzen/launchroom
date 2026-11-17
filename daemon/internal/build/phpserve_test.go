package build

import (
	"encoding/json"
	"os"
	"path/filepath"
	"slices"
	"strings"
	"testing"

	rpplan "github.com/railwayapp/railpack/core/plan"

	"github.com/vantezzen/launchroom/daemon/internal/build/presets"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

func planCaddyfile(t *testing.T, raw []byte) string {
	t.Helper()
	var plan rpplan.BuildPlan
	if err := json.Unmarshal(raw, &plan); err != nil {
		t.Fatal(err)
	}
	for _, s := range plan.Steps {
		if cf, ok := s.Assets[phpCaddyfileAsset]; ok {
			return cf
		}
	}
	t.Fatal("the plan has no Caddyfile asset")
	return ""
}

// Railpack's own Caddyfile is adjusted for Laravel and for plain PHP. If a
// Railpack upgrade changes its shape, this test fails instead of builds
// silently keeping debug logs and http:// links.
func TestRailpackPhpCaddyfileIsAdjusted(t *testing.T) {
	plain := t.TempDir()
	if err := os.WriteFile(filepath.Join(plain, "index.php"), []byte("<?php echo 'hi';\n"), 0o600); err != nil {
		t.Fatal(err)
	}
	for name, dir := range map[string]string{"laravel": copyRepo(t, "laravel"), "plain": plain} {
		t.Run(name, func(t *testing.T) {
			r, sink := resolveFor(t, dir, state.BuilderSpec{Kind: state.BuilderAuto, RailpackVersion: RailpackVersion})
			if r.summary.Provider != "php" {
				t.Fatalf("provider %q", r.summary.Provider)
			}
			if i := slices.IndexFunc(r.summary.Warnings, func(w state.BuildWarning) bool { return w.Code == "php-server-not-adjusted" }); i >= 0 {
				t.Fatalf("Railpack's Caddyfile changed shape: %s", r.summary.Warnings[i].Message)
			}
			if !slices.Contains(sink.lines, "[plan] "+phpServeAdjusted) {
				t.Fatalf("log lines %q", sink.lines)
			}
			cf := planCaddyfile(t, r.plan)
			for _, want := range []string{"level INFO", "map {header.X-Forwarded-Proto} {launchroom_https} {", "https on", "php_server {", "env HTTPS {launchroom_https}",
				"trusted_proxies static private_ranges 100.64.0.0/10", "client_ip_headers X-Forwarded-For", "trusted_proxies_strict", "env REMOTE_ADDR {client_ip}"} {
				if !strings.Contains(cf, want) {
					t.Errorf("Caddyfile lacks %q:\n%s", want, cf)
				}
			}
			if strings.Contains(cf, "DEBUG") {
				t.Errorf("Caddyfile still logs at DEBUG:\n%s", cf)
			}
			if i, j := strings.Index(cf, "servers {"), strings.Index(cf, "frankenphp {"); i < 0 || j < i {
				t.Errorf("the servers block is not in the global options:\n%s", cf)
			}
		})
	}
}

func TestRepositoryCaddyfileIsLeftAlone(t *testing.T) {
	dir := copyRepo(t, "laravel")
	own := ":{$PORT:80} {\n\tlog {\n\t\tlevel DEBUG\n\t}\n\troot * /app/public\n\tphp_server\n}\n"
	if err := os.WriteFile(filepath.Join(dir, "Caddyfile"), []byte(own), 0o600); err != nil {
		t.Fatal(err)
	}
	r, sink := resolveFor(t, dir, state.BuilderSpec{Kind: state.BuilderAuto, RailpackVersion: RailpackVersion})
	if cf := planCaddyfile(t, r.plan); cf != own {
		t.Fatalf("the repository's Caddyfile was changed:\n%s", cf)
	}
	adjusted := slices.Contains(sink.lines, "[plan] "+phpServeAdjusted)
	if adjusted || slices.ContainsFunc(r.summary.Warnings, func(w state.BuildWarning) bool { return w.Code == "php-server-not-adjusted" }) {
		t.Fatalf("log %q warnings %+v", sink.lines, r.summary.Warnings)
	}
}

func TestAdjustPhpServerRefusesUnknownShapes(t *testing.T) {
	plan := func(cf string) *rpplan.BuildPlan {
		return &rpplan.BuildPlan{Steps: []rpplan.Step{{Name: "prepare", Assets: map[string]string{phpCaddyfileAsset: cf}}}}
	}
	for name, cf := range map[string]string{
		"no debug level":       "{\n\tlog {\n\t\tlevel WARN\n\t}\n}\n:80 {\n\tphp_server\n}\n",
		"php_server block":     "{\n\tlog {\n\t\tlevel DEBUG\n\t}\n}\n:80 {\n\tphp_server {\n\t\troot /app\n\t}\n}\n",
		"two php_server":       "{\n\tlog {\n\t\tlevel DEBUG\n\t}\n}\n:80 {\n\tphp_server\n}\n:81 {\n\tphp_server\n}\n",
		"no php_server at all": "{\n\tlog {\n\t\tlevel DEBUG\n\t}\n}\n:80 {\n\tfile_server\n}\n",
	} {
		p := plan(cf)
		if err := adjustPhpServer(p, phpServerShims{HTTPS: true, VisitorIP: true}); err == nil {
			t.Errorf("%s: adjusted an unexpected Caddyfile", name)
		}
		if p.Steps[0].Assets[phpCaddyfileAsset] != cf {
			t.Errorf("%s: a refused Caddyfile was changed", name)
		}
	}
	if err := adjustPhpServer(&rpplan.BuildPlan{}, phpServerShims{HTTPS: true}); err == nil {
		t.Error("a plan without a Caddyfile was accepted")
	}

	s := &state.BuildSummary{}
	sink := &lineSink{}
	adjustPhpPlan(plan("nothing"), phpPlanInput{}, s, sink)
	if len(s.Warnings) != 1 || s.Warnings[0].Code != "php-server-not-adjusted" || len(sink.lines) != 0 {
		t.Fatalf("warnings %+v log %q", s.Warnings, sink.lines)
	}

	p := plan("{\n  log {\n    level DEBUG\n  }\n\tfrankenphp {\n\t}\n}\n:80 {\n\tphp_server\n}\n")
	if err := adjustPhpServer(p, phpServerShims{HTTPS: true, VisitorIP: true}); err != nil {
		t.Fatal(err)
	}
	want := "{\n  log {\n    level INFO\n  }\n" +
		"\tservers {\n\t\ttrusted_proxies static private_ranges 100.64.0.0/10\n\t\tclient_ip_headers X-Forwarded-For\n\t\ttrusted_proxies_strict\n\t}\n" +
		"\tfrankenphp {\n\t}\n}\n:80 {\n" +
		"\tmap {header.X-Forwarded-Proto} {launchroom_https} {\n\t\thttps on\n\t}\n" +
		"\tphp_server {\n\t\tenv HTTPS {launchroom_https}\n\t\tenv REMOTE_ADDR {client_ip}\n\t}\n}\n"
	if got := p.Steps[0].Assets[phpCaddyfileAsset]; got != want {
		t.Fatalf("adjusted Caddyfile:\n%s\nwant:\n%s", got, want)
	}

	// Only the visitor address: no map, no log level change, so a Caddyfile
	// without DEBUG is fine.
	p = plan("{\n\tfrankenphp {\n\t}\n}\n:80 {\n\tphp_server\n}\n")
	if err := adjustPhpServer(p, phpServerShims{VisitorIP: true}); err != nil {
		t.Fatal(err)
	}
	if got := p.Steps[0].Assets[phpCaddyfileAsset]; strings.Contains(got, "launchroom_https") || !strings.Contains(got, "\tphp_server {\n\t\tenv REMOTE_ADDR {client_ip}\n\t}") {
		t.Fatalf("adjusted Caddyfile:\n%s", got)
	}
}

func startScript(t *testing.T, raw []byte) string {
	t.Helper()
	var plan rpplan.BuildPlan
	if err := json.Unmarshal(raw, &plan); err != nil {
		t.Fatal(err)
	}
	for _, s := range plan.Steps {
		if sh, ok := s.Assets[phpStartScriptAsset]; ok {
			return sh
		}
	}
	t.Fatal("the plan has no start-container.sh asset")
	return ""
}

// Railpack's Laravel start script is adjusted exactly as intended. If a
// Railpack upgrade changes its shape, this test fails instead of builds
// silently flushing the shared cache on every start again.
func TestRailpackLaravelStartScriptIsAdjusted(t *testing.T) {
	r, sink := resolveFor(t, copyRepo(t, "laravel"), state.BuilderSpec{Kind: state.BuilderAuto, RailpackVersion: RailpackVersion})
	if slices.ContainsFunc(r.summary.Warnings, func(w state.BuildWarning) bool { return w.Code == "laravel-start-not-adjusted" }) {
		t.Fatalf("Railpack's Laravel start script changed shape: %+v", r.summary.Warnings)
	}
	if !slices.Contains(sink.lines, "[plan] "+laravelStartAdjusted) {
		t.Fatalf("log lines %q", sink.lines)
	}
	sh := startScript(t, r.plan)
	if strings.Contains(sh, "optimize:clear") || strings.Count(sh, laravelStartFallback) != 1 {
		t.Fatalf("start script:\n%s", sh)
	}
	if !strings.Contains(sh, "php artisan storage:link") || !strings.Contains(sh, "docker-php-entrypoint") {
		t.Fatalf("start script lost its other steps:\n%s", sh)
	}

	// Removing the chips leaves the start script and the visitor address
	// alone; HTTPS (D85) stays.
	off := state.BuilderSpec{Kind: state.BuilderAuto, RailpackVersion: RailpackVersion, DisabledDecisions: []state.Slug{presets.PhpVisitorIPDecision, presets.LaravelStartScriptDecision}}
	r, sink = resolveFor(t, copyRepo(t, "laravel"), off)
	if sh := startScript(t, r.plan); !strings.Contains(sh, "php artisan optimize:clear") {
		t.Fatalf("disabled start script was changed:\n%s", sh)
	}
	if cf := planCaddyfile(t, r.plan); strings.Contains(cf, "REMOTE_ADDR") || strings.Contains(cf, "trusted_proxies") || !strings.Contains(cf, "env HTTPS {launchroom_https}") {
		t.Fatalf("Caddyfile with the visitor address removed:\n%s", cf)
	}
	if !slices.Contains(sink.lines, "[plan] "+phpServeAdjustedHTTPS) {
		t.Fatalf("log lines %q", sink.lines)
	}
}

func TestAdjustLaravelStartRefusesUnknownShapes(t *testing.T) {
	plan := func(sh string) *rpplan.BuildPlan {
		return &rpplan.BuildPlan{Steps: []rpplan.Step{{Name: "prepare", Assets: map[string]string{phpStartScriptAsset: sh}}}}
	}
	for name, sh := range map[string]string{
		"no clear":    "php artisan optimize\n",
		"no optimize": "php artisan optimize:clear\n",
		"twice":       "php artisan optimize:clear\nphp artisan optimize\nphp artisan optimize\n",
		"indented":    "  php artisan optimize:clear\n  php artisan optimize\n",
	} {
		p := plan(sh)
		if err := adjustLaravelStart(p); err == nil {
			t.Errorf("%s: adjusted an unexpected start script", name)
		}
		if p.Steps[0].Assets[phpStartScriptAsset] != sh {
			t.Errorf("%s: a refused start script was changed", name)
		}
	}
	p := plan("set -e\nphp artisan storage:link\nphp artisan optimize:clear\nphp artisan optimize\nexec frankenphp\n")
	if err := adjustLaravelStart(p); err != nil {
		t.Fatal(err)
	}
	want := "set -e\nphp artisan storage:link\n" + laravelStartFallback + "\nexec frankenphp\n"
	if got := p.Steps[0].Assets[phpStartScriptAsset]; got != want {
		t.Fatalf("adjusted script:\n%s\nwant:\n%s", got, want)
	}
}

// The repository's own start script is the app's to configure.
func TestRepositoryStartScriptIsLeftAlone(t *testing.T) {
	dir := copyRepo(t, "laravel")
	own := "#!/bin/bash\nphp artisan optimize:clear\nphp artisan optimize\nexec docker-php-entrypoint --config /Caddyfile --adapter caddyfile\n"
	if err := os.WriteFile(filepath.Join(dir, "start-container.sh"), []byte(own), 0o600); err != nil {
		t.Fatal(err)
	}
	r, sink := resolveFor(t, dir, state.BuilderSpec{Kind: state.BuilderAuto, RailpackVersion: RailpackVersion})
	if sh := startScript(t, r.plan); sh != own {
		t.Fatalf("the repository's start script was changed:\n%s", sh)
	}
	if !slices.ContainsFunc(sink.lines, func(l string) bool { return strings.Contains(l, "start-container.sh as is") }) {
		t.Fatalf("log lines %q", sink.lines)
	}
}
