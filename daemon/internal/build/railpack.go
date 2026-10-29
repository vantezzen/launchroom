package build

import (
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"maps"
	"slices"
	"strings"
	"sync"

	rpcore "github.com/railwayapp/railpack/core"
	rpapp "github.com/railwayapp/railpack/core/app"
	rpplan "github.com/railwayapp/railpack/core/plan"

	"github.com/vantezzen/launchroom/daemon/internal/build/buildkitd"
	"github.com/vantezzen/launchroom/daemon/internal/build/presets"
	"github.com/vantezzen/launchroom/daemon/internal/secret"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// RailpackVersion is the Railpack core linked into this daemon (go.mod). A
// build spec pinning another version fails with config_error: the version is
// pinned per service and upgraded deliberately (docs/04 §2).
const RailpackVersion = "0.42.0"

// RailpackFrontend is the Railpack BuildKit gateway frontend matching
// RailpackVersion, digest-pinned. buildkitd pulls it through the Zot mirror
// (its registry mirror config); allowedRepositories admits only this repo.
const RailpackFrontend = "ghcr.io/railwayapp/railpack-frontend@sha256:a42a12399fa9e83a09e475f69dbf8a45a8a74ea38a4485fcb401356d84317a14"

// planPlaceholder stands in for build variable values while planning:
// Railpack only needs the names (they become plan secrets).
const planPlaceholder = "launchroom-build-variable"

// railpackMu serialises planning: Railpack core uses process-global logging
// and a shared mise install dir.
var railpackMu sync.Mutex

type railpackPlan struct {
	result *rpcore.BuildResult
	info   presets.Info
}

// limitNodeBuild leaves explicit settings alone and changes only the build
// step: these limits must never reach the app's runtime environment.
func limitNodeBuild(p *rpplan.BuildPlan, resources buildkitd.Resources, env map[string]secret.Secret) {
	if !resources.Small && !resources.Tight || resources.NodeHeapMB <= 0 {
		return
	}
	for i := range p.Steps {
		step := &p.Steps[i]
		if step.Name != "build" {
			continue
		}
		if step.Variables == nil {
			step.Variables = map[string]string{}
		}
		for name, value := range map[string]string{
			"NODE_OPTIONS":      fmt.Sprintf("--max-old-space-size=%d", resources.NodeHeapMB),
			"RAYON_NUM_THREADS": fmt.Sprint(resources.Workers),
		} {
			if _, supplied := env[name]; supplied {
				continue
			}
			if _, supplied := step.Variables[name]; !supplied {
				step.Variables[name] = value
			}
		}
	}
}

// railpackEnv builds the Railpack environment: non-secret RAILPACK_* config
// with real values, build-scoped variables with placeholder values.
func railpackEnv(b state.BuilderSpec, buildEnv []string) map[string]string {
	env := map[string]string{}
	for _, n := range buildEnv {
		env[n] = planPlaceholder
	}
	for k, v := range b.RailpackEnv {
		env[string(k)] = v
	}
	if b.InstallCommand != "" {
		env["RAILPACK_INSTALL_CMD"] = b.InstallCommand
	}
	if b.BuildCommand != "" {
		env["RAILPACK_BUILD_CMD"] = b.BuildCommand
	}
	if b.StartCommand != "" {
		env["RAILPACK_START_CMD"] = b.StartCommand
	}
	return env
}

// dropConfigSecrets removes Railpack's own settings (RAILPACK_*: railpackEnv,
// command overrides, PHP extensions) from the plan's secrets. Railpack makes
// every planning variable a plan secret, but only build variables get a value
// in the solve; BuildKit fails a step on a secret it can't find. A build
// variable that happens to be named RAILPACK_* keeps its secret.
func dropConfigSecrets(p *rpplan.BuildPlan, buildEnv []string) {
	config := func(name string) bool {
		return strings.HasPrefix(name, "RAILPACK_") && !slices.Contains(buildEnv, name)
	}
	p.Secrets = slices.DeleteFunc(p.Secrets, config)
	for i := range p.Steps {
		if p.Steps[i].Secrets != nil {
			p.Steps[i].Secrets = slices.DeleteFunc(p.Steps[i].Secrets, config)
		}
	}
}

// planWithRailpack runs Railpack's planner in-process. A nil plan with a
// nil error means Railpack could not plan the app (plan_error, see logs).
func planWithRailpack(dir string, env map[string]string, logf func(level, msg string)) (*railpackPlan, error) {
	railpackMu.Lock()
	defer railpackMu.Unlock()
	app, err := rpapp.NewApp(dir)
	if err != nil {
		return nil, fail(state.FailureConfig, "plan", err)
	}
	vars := maps.Clone(env)
	res, err := rpcore.GenerateBuildPlan(app, rpapp.NewEnvironment(&vars), &rpcore.GenerateBuildPlanOptions{RailpackVersion: RailpackVersion})
	if res != nil {
		for _, m := range res.Logs {
			logf(string(m.Level), m.Msg)
		}
	}
	if err != nil {
		// Railpack returns errors only for transient failures (mise/GitHub, exit 75).
		return nil, fail(state.FailureTransient, "plan", err)
	}
	if res == nil || !res.Success || res.Plan == nil {
		return nil, nil
	}
	p := &railpackPlan{result: res, info: presets.Info{Metadata: res.Metadata, Packages: map[string]string{}}}
	if len(res.DetectedProviders) > 0 {
		p.info.Provider = res.DetectedProviders[0]
	}
	for name, pkg := range res.ResolvedPackages {
		if pkg != nil && pkg.ResolvedVersion != nil {
			p.info.Packages[name] = *pkg.ResolvedVersion
		}
	}
	return p, nil
}

// applyPatch applies a preset's plan patch to the typed Railpack plan.
func applyPatch(p *rpplan.BuildPlan, patch presets.PlanPatch) error {
	if patch.StartCommand != "" {
		p.Deploy.StartCmd = patch.StartCommand
	}
	if len(patch.DeployVariables) > 0 {
		if p.Deploy.Variables == nil {
			p.Deploy.Variables = map[string]string{}
		}
		maps.Copy(p.Deploy.Variables, patch.DeployVariables)
	}
	if len(patch.DropBuildCommandsContaining) > 0 {
		for i := range p.Steps {
			p.Steps[i].Commands = slices.DeleteFunc(p.Steps[i].Commands, func(c rpplan.Command) bool {
				ex, ok := c.(rpplan.ExecCommand)
				if !ok {
					return false
				}
				for _, s := range patch.DropBuildCommandsContaining {
					if strings.Contains(ex.Cmd, s) {
						return true
					}
				}
				return false
			})
		}
	}
	if len(patch.DeployOnly) > 0 {
		if err := restrictDeploy(p, patch.DeployOnly); err != nil {
			return err
		}
	}
	if len(patch.AppendBuildCommands) == 0 && len(patch.BuildVariables) == 0 && len(patch.BuildFiles) == 0 {
		return nil
	}
	idx := slices.IndexFunc(p.Steps, func(s rpplan.Step) bool { return s.Name == "build" })
	if idx < 0 {
		return fmt.Errorf("the plan has no build step to patch")
	}
	st := &p.Steps[idx]
	if len(patch.BuildFiles) > 0 {
		if st.Assets == nil {
			st.Assets = map[string]string{}
		}
		files := make([]rpplan.Command, 0, len(patch.BuildFiles))
		for _, path := range slices.Sorted(maps.Keys(patch.BuildFiles)) {
			if !strings.HasPrefix(path, "/") || strings.Contains(path, "..") {
				return fmt.Errorf("preset file path %q is not absolute", path)
			}
			name := buildFileAsset + path
			st.Assets[name] = patch.BuildFiles[path]
			files = append(files, rpplan.NewFileCommand(path, name, rpplan.FileOptions{CustomName: "write " + path}))
		}
		st.Commands = append(files, st.Commands...)
	}
	for _, c := range patch.AppendBuildCommands {
		if strings.ContainsRune(c, '\'') {
			return fmt.Errorf("preset command contains a single quote")
		}
		st.Commands = append(st.Commands, rpplan.NewExecShellCommand(c, rpplan.ExecOptions{CustomName: c}))
	}
	if len(patch.BuildVariables) > 0 {
		if st.Variables == nil {
			st.Variables = map[string]string{}
		}
		maps.Copy(st.Variables, patch.BuildVariables)
	}
	return nil
}

// buildFileAsset prefixes the plan asset names of preset build files.
const buildFileAsset = "launchroom:"

// restrictDeploy replaces the deploy layers taken from the app's build
// (install/prune/build steps) by one layer of only the given paths from the
// build step. Other layers (the mise toolchain, images) stay.
func restrictDeploy(p *rpplan.BuildPlan, include []string) error {
	appSteps := map[string]bool{"install": true, "prune": true, "build": true}
	kept := make([]rpplan.Layer, 0, len(p.Deploy.Inputs))
	found := false
	for _, l := range p.Deploy.Inputs {
		if appSteps[l.Step] {
			found = true
			continue
		}
		kept = append(kept, l)
	}
	if !found {
		return fmt.Errorf("the plan's deploy has no build layer to restrict")
	}
	for _, inc := range include {
		if inc == "" || strings.HasPrefix(inc, "/") || strings.Contains(inc, "..") {
			return fmt.Errorf("deploy path %q must be relative to the app directory", inc)
		}
	}
	p.Deploy.Inputs = append(kept, rpplan.NewStepLayer("build", rpplan.Filter{Include: slices.Clone(include)}))
	return nil
}

// summaryPlan is the plan as shown in the build summary: embedded preset
// files are replaced by their size and digest (they are vendored code, not
// something to review in the plan view).
func summaryPlan(p *rpplan.BuildPlan) ([]byte, error) {
	raw, err := json.Marshal(p)
	if err != nil {
		return nil, err
	}
	var cp rpplan.BuildPlan
	if err := json.Unmarshal(raw, &cp); err != nil {
		return nil, err
	}
	changed := false
	for i := range cp.Steps {
		for name, content := range cp.Steps[i].Assets {
			if strings.HasPrefix(name, buildFileAsset) {
				sum := sha256.Sum256([]byte(content))
				cp.Steps[i].Assets[name] = fmt.Sprintf("(vendored file: %d bytes, sha256:%s)", len(content), hex.EncodeToString(sum[:]))
				changed = true
			}
		}
	}
	if !changed {
		return raw, nil
	}
	return json.Marshal(&cp)
}

// stepCommands joins a step's exec commands for display.
func stepCommands(p *rpplan.BuildPlan, name string) string {
	for _, s := range p.Steps {
		if s.Name != name {
			continue
		}
		var cmds []string
		for _, c := range s.Commands {
			if ex, ok := c.(rpplan.ExecCommand); ok {
				n := ex.CustomName
				if n == "" {
					n = ex.Cmd
				}
				cmds = append(cmds, n)
			}
		}
		return clipText(strings.Join(cmds, " && "), 4096)
	}
	return ""
}

// stepNames maps exec command display names to the step they belong to,
// so BuildKit vertices can be grouped by Railpack step.
func stepNames(p *rpplan.BuildPlan) map[string]string {
	out := map[string]string{}
	for _, s := range p.Steps {
		for _, c := range s.Commands {
			switch v := c.(type) {
			case rpplan.ExecCommand:
				if v.CustomName != "" {
					out[v.CustomName] = s.Name
				}
				out[v.Cmd] = s.Name
			case rpplan.CopyCommand:
				out["copy "+v.Src] = s.Name
			}
		}
	}
	return out
}

func ident(s string) state.Ident {
	v := state.Ident(s)
	if v.Validate() != nil {
		return ""
	}
	return v
}

// railpackSummary fills the summary from Railpack's result.
func railpackSummary(s *state.BuildSummary, rp *railpackPlan) {
	res := rp.result
	s.Builder = state.BuilderRailpack
	s.RailpackVersion = state.Semver(RailpackVersion)
	s.Provider = ident(rp.info.Provider)
	md := res.Metadata
	for _, k := range []string{"nodePackageManager", "pythonPackageManager", "javaPackageManager"} {
		if v := md[k]; v != "" {
			s.PackageManager = ident(v)
			break
		}
	}
	if s.PackageManager == "" && rp.info.Provider == "php" {
		s.PackageManager = "composer"
	}
	for _, k := range []string{"nodeRuntime", "phpFramework", "pythonRuntime", "javaFramework"} {
		if v := md[k]; v != "" && v != "node" && v != "python" {
			s.Framework = ident(v)
			break
		}
	}
	names := slices.Sorted(maps.Keys(res.ResolvedPackages))
	for _, n := range names {
		pkg := res.ResolvedPackages[n]
		if pkg == nil || pkg.ResolvedVersion == nil || len(s.Packages) >= 64 || ident(n) == "" {
			continue
		}
		s.Packages = append(s.Packages, state.ResolvedPackage{Name: ident(n), Version: clipText(*pkg.ResolvedVersion, 128), Source: clipText(pkg.Source, 256)})
	}
	s.InstallCommand = stepCommands(res.Plan, "install")
	s.BuildCommand = stepCommands(res.Plan, "build")
	s.StartCommand = clipText(res.Plan.Deploy.StartCmd, 4096)
}
