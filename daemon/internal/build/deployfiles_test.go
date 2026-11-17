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

// Whenever the image environment points at a launchroom runtime file
// (NODE_OPTIONS=--import=/opt/launchroom/…), the plan writes that file and
// the runtime image takes it as a layer of its own.
func TestDeployFilesReachTheImage(t *testing.T) {
	r, sink := resolveFor(t, copyRepo(t, "express"), state.BuilderSpec{Kind: state.BuilderAuto, RailpackVersion: RailpackVersion})
	if i := slices.IndexFunc(r.summary.Warnings, func(w state.BuildWarning) bool { return w.Code == "preset-not-applied" }); i >= 0 {
		t.Fatalf("preset not applied: %s", r.summary.Warnings[i].Message)
	}
	var plan rpplan.BuildPlan
	if err := json.Unmarshal(r.plan, &plan); err != nil {
		t.Fatal(err)
	}
	opts := plan.Deploy.Variables["NODE_OPTIONS"]
	if !strings.Contains(opts, presets.ForwardedPreloadPath) {
		t.Fatalf("NODE_OPTIONS %q (log %q)", opts, sink.lines)
	}
	idx := slices.IndexFunc(plan.Steps, func(s rpplan.Step) bool { return s.Name == deployFilesStep })
	if idx < 0 {
		t.Fatal("no runtime files step")
	}
	step := plan.Steps[idx]
	if !strings.Contains(step.Assets[buildFileAsset+presets.ForwardedPreloadPath], "x-forwarded-proto") || len(step.Secrets) != 0 {
		t.Fatalf("step %+v", step)
	}
	wrote := slices.ContainsFunc(step.Commands, func(c rpplan.Command) bool {
		f, ok := c.(rpplan.FileCommand)
		return ok && f.Path == presets.ForwardedPreloadPath && f.Name == buildFileAsset+presets.ForwardedPreloadPath
	})
	layer := slices.ContainsFunc(plan.Deploy.Inputs, func(l rpplan.Layer) bool {
		return l.Step == deployFilesStep && slices.Equal(l.Include, []string{presets.ForwardedPreloadPath})
	})
	if !wrote || !layer {
		t.Fatalf("file written %v, deploy layer %v: %+v", wrote, layer, plan.Deploy.Inputs)
	}
	// The plan view shows the vendored file by digest.
	if strings.Contains(string(r.summary.Plan), "x-forwarded-proto") {
		t.Fatal("the summary plan embeds the runtime file")
	}

	// Without the decision, neither the variable nor the file.
	off := state.BuilderSpec{Kind: state.BuilderAuto, RailpackVersion: RailpackVersion, DisabledDecisions: []state.Slug{presets.ForwardedHTTPSDecision}}
	r, _ = resolveFor(t, copyRepo(t, "express"), off)
	if strings.Contains(string(r.plan), presets.ForwardedPreloadPath) {
		t.Fatal("disabled preload still in the plan")
	}
}

func TestAddDeployFilesRefusesAppPaths(t *testing.T) {
	plan := func() *rpplan.BuildPlan {
		return &rpplan.BuildPlan{Steps: []rpplan.Step{{Name: "build"}}}
	}
	for _, path := range []string{"/app/server.js", "relative.js", "/opt/launchroom/../../app/x", "/etc/passwd"} {
		p := plan()
		if err := addDeployFiles(p, map[string]string{path: "x"}); err == nil {
			t.Errorf("%s accepted", path)
		}
	}
	if err := addDeployFiles(&rpplan.BuildPlan{}, map[string]string{"/opt/launchroom/x": "x"}); err == nil {
		t.Error("a plan without a build step accepted")
	}
	// A failed runtime file leaves the image variables untouched.
	p := plan()
	err := applyPatch(p, presets.PlanPatch{DeployVariables: map[string]string{"NODE_OPTIONS": "--import=/app/x.mjs"}, DeployFiles: map[string]string{"/app/x.mjs": "x"}})
	if err == nil || p.Deploy.Variables["NODE_OPTIONS"] != "" {
		t.Fatalf("err %v variables %v", err, p.Deploy.Variables)
	}
}

// Next.js 14 to 15.3 builds get NEXT_DEPLOYMENT_ID, the adapter builds
// LAUNCHROOM_DEPLOYMENT_ID: the deployment id, for skew protection.
func TestNextDeploymentIDs(t *testing.T) {
	for _, c := range []struct {
		version, variable string
	}{{"15.2.0", "NEXT_DEPLOYMENT_ID"}, {"16.2.0", "LAUNCHROOM_DEPLOYMENT_ID"}} {
		dir := copyRepo(t, "nextjs-plain")
		pkgJSON := `{"name":"next-plain","private":true,"scripts":{"build":"next build","start":"next start"},"dependencies":{"next":"` + c.version + `","react":"^19.0.0"}}`
		if err := os.WriteFile(filepath.Join(dir, "package.json"), []byte(pkgJSON), 0o600); err != nil {
			t.Fatal(err)
		}
		const deployment = "01JZ0000000000000000000000"
		job := &Job{Inputs: state.BuildInputs{Builder: state.BuilderSpec{Kind: state.BuilderAuto, RailpackVersion: RailpackVersion}, Deployment: deployment}}
		sink := &lineSink{}
		r, err := (&Builder{}).resolve(dir, t.TempDir(), job, nil, sink)
		if err != nil {
			t.Fatalf("resolve: %v\n%s", err, strings.Join(sink.lines, "\n"))
		}
		var plan rpplan.BuildPlan
		if err := json.Unmarshal(r.plan, &plan); err != nil {
			t.Fatal(err)
		}
		i := slices.IndexFunc(plan.Steps, func(s rpplan.Step) bool { return s.Name == "build" })
		if got := plan.Steps[i].Variables[c.variable]; got != deployment {
			t.Errorf("next %s: %s = %q", c.version, c.variable, got)
		}
	}
}

// A Dockerfile build gets the runtime part of its framework preset (scope
// runtime) and, for PHP, the lines that make the app trust the edge.
func TestDockerfileBuildGetsRuntimePreset(t *testing.T) {
	dir := copyRepo(t, "laravel")
	if err := os.WriteFile(filepath.Join(dir, "Dockerfile"), []byte("FROM php:8.4-apache\nCOPY . /var/www/html\nEXPOSE 80\n"), 0o600); err != nil {
		t.Fatal(err)
	}
	r, sink := resolveFor(t, dir, state.BuilderSpec{Kind: state.BuilderAuto})
	s := r.summary
	if s.Builder != state.BuilderDockerfile || len(s.Presets) != 1 || s.Presets[0].Scope != state.PresetScopeRuntime || s.Presets[0].Name != "laravel" {
		t.Fatalf("presets %+v", s.Presets)
	}
	if !slices.ContainsFunc(s.GeneratedSecrets, func(g state.GeneratedSecret) bool { return g.Name == "APP_KEY" }) {
		t.Fatalf("generated secrets %+v", s.GeneratedSecrets)
	}
	if s.ReleaseCommand != nil || s.Port == nil || *s.Port != 80 {
		t.Fatalf("release %v port %v", s.ReleaseCommand, s.Port)
	}
	i := slices.IndexFunc(s.Warnings, func(w state.BuildWarning) bool { return w.Code == "dockerfile-php-proxy" })
	if i < 0 || !strings.Contains(s.Warnings[i].Message, "trustProxies(at:") {
		t.Fatalf("warnings %+v", s.Warnings)
	}
	if !slices.Contains(sink.lines, "[plan] Applied the runtime settings of preset laravel to your Dockerfile's container") {
		t.Fatalf("log %q", sink.lines)
	}
	validSummary(t, s)

	none := state.BuilderSpec{Kind: state.BuilderAuto, Preset: state.PresetNone}
	if r, _ := resolveFor(t, dir, none); len(r.summary.Presets) != 0 {
		t.Fatalf("preset none: %+v", r.summary.Presets)
	}
}
