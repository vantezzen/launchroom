package build

import (
	"encoding/json"
	"os"
	"path/filepath"
	"slices"
	"strings"
	"testing"

	rpplan "github.com/railwayapp/railpack/core/plan"

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
			for _, want := range []string{"level INFO", "map {header.X-Forwarded-Proto} {launchroom_https} {", "https on", "php_server {", "env HTTPS {launchroom_https}"} {
				if !strings.Contains(cf, want) {
					t.Errorf("Caddyfile lacks %q:\n%s", want, cf)
				}
			}
			if strings.Contains(cf, "DEBUG") {
				t.Errorf("Caddyfile still logs at DEBUG:\n%s", cf)
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
		if err := adjustPhpServer(p); err == nil {
			t.Errorf("%s: adjusted an unexpected Caddyfile", name)
		}
		if p.Steps[0].Assets[phpCaddyfileAsset] != cf {
			t.Errorf("%s: a refused Caddyfile was changed", name)
		}
	}
	if err := adjustPhpServer(&rpplan.BuildPlan{}); err == nil {
		t.Error("a plan without a Caddyfile was accepted")
	}

	s := &state.BuildSummary{}
	sink := &lineSink{}
	adjustPhpPlan(plan("nothing"), false, s, sink)
	if len(s.Warnings) != 1 || s.Warnings[0].Code != "php-server-not-adjusted" || len(sink.lines) != 0 {
		t.Fatalf("warnings %+v log %q", s.Warnings, sink.lines)
	}

	p := plan("{\n  log {\n    level DEBUG\n  }\n}\n:80 {\n\tphp_server\n}\n")
	if err := adjustPhpServer(p); err != nil {
		t.Fatal(err)
	}
	want := "{\n  log {\n    level INFO\n  }\n}\n:80 {\n" +
		"\tmap {header.X-Forwarded-Proto} {launchroom_https} {\n\t\thttps on\n\t}\n" +
		"\tphp_server {\n\t\tenv HTTPS {launchroom_https}\n\t}\n}\n"
	if got := p.Steps[0].Assets[phpCaddyfileAsset]; got != want {
		t.Fatalf("adjusted Caddyfile:\n%s\nwant:\n%s", got, want)
	}
}
