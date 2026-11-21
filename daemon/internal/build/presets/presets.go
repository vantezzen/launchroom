package presets

import (
	"maps"
	"slices"
	"strconv"
	"strings"

	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// Info is what Railpack detected (from its BuildResult).
type Info struct {
	// Provider is the detected Railpack provider ("node", "php", "python", …).
	Provider string
	// Metadata is Railpack's metadata (nodeRuntime, nodeIsSPA, phpFramework, …).
	Metadata map[string]string
	// Packages maps resolved package names to versions ("node" → "22.11.0").
	Packages map[string]string
	// Dockerfile is set when the app builds from its own Dockerfile and only
	// the runtime decisions apply (ResolveRuntime). Provider is empty then.
	Dockerfile bool
	// PruneDeps says the Railpack plan prunes devDependencies from the
	// runtime image (RAILPACK_PRUNE_DEPS).
	PruneDeps bool
}

// PlanPatch is how a preset changes the Railpack plan. The build package
// applies it to the typed plan; presets never touch the plan directly.
type PlanPatch struct {
	// StartCommand replaces deploy.startCommand (a shell string run in the app container).
	StartCommand string
	// DeployVariables are added to the image environment.
	DeployVariables map[string]string
	// BuildVariables are added to the variables of the "build" step.
	BuildVariables map[string]string
	// AppendBuildCommands are shell commands appended to the "build" step.
	AppendBuildCommands []string
	// DropBuildCommandsContaining removes exec commands of any step whose
	// command line contains one of these substrings.
	DropBuildCommandsContaining []string
	// BuildFiles are written (absolute path → content) at the start of the
	// "build" step, as Railpack plan assets. Paths outside the app directory
	// never reach the runtime image.
	BuildFiles map[string]string
	// DeployFiles are written (absolute path → content) into the runtime
	// image as one extra deploy layer, outside the app directory (launchroom's
	// runtime assets such as /opt/launchroom/node/forwarded.mjs). They never
	// replace a file of the app.
	DeployFiles map[string]string
	// DeployOnly restricts what the runtime image takes from the build: the
	// deploy layers from the build/prune steps are replaced by one layer of
	// just these paths (relative to the app directory). Runtime layers such
	// as the mise toolchain stay.
	DeployOnly []string
}

// Effect is the contribution of one decision.
type Effect struct {
	Plan           PlanPatch
	RuntimeEnv     map[string]string
	Processes      []state.ProcessSuggestion
	ReleaseCommand []string
	HealthPath     string
	Port           int
	// GeneratedSecrets are runtime secrets the dashboard creates when the service has none.
	GeneratedSecrets []state.GeneratedSecret
	// EnvAliases set framework variables to system values per environment.
	EnvAliases []state.EnvAlias
	// RequiredSecrets are secrets the app needs that launchroom can't generate.
	RequiredSecrets []state.RequiredSecret
	// VolumeSuggestions are folders whose files should outlive a deploy.
	VolumeSuggestions []state.VolumeSuggestion
}

// Decision is one removable preset decision (a UI chip) and its effect.
type Decision struct {
	ID     string
	Title  string
	Detail string
	Effect Effect
}

// Warning is a non-fatal finding. Its code can be disabled like a decision.
type Warning struct {
	Code    string
	Message string
	// Runtime keeps the warning for Dockerfile builds (it is about the
	// running app, not the image launchroom builds).
	Runtime bool
}

// Proposal is what a preset proposes before user removals are applied.
type Proposal struct {
	Framework string
	// Port and HealthPath are defaults that hold even if every decision is removed.
	Port       int
	HealthPath string
	Decisions  []Decision
	Warnings   []Warning
	// Commands are the framework's standard one-off commands (D105). They
	// are not decisions: removing a decision doesn't change them.
	Commands []state.CommandSuggestion
}

// Preset is one framework preset. Match and Propose must be pure.
type Preset interface {
	Name() string
	Version() int
	Match(f Facts, i Info) bool
	Propose(f Facts, i Info) Proposal
}

// All returns the presets in matching order (most specific first).
func All() []Preset {
	ps := []Preset{laravel{}, symfony{}, rails{}, phoenix{}, django{}, pythonASGI{}, jvm{}, dotnet{}, rocket{}, phpPlain{}}
	ps = append(ps, javascriptPresets()...)
	return append(ps, staticSPA{}, nodeServer{})
}

// recipeVersion counts changes to what launchroomd adds to Railpack images
// outside the presets (the PHP server adjustments, D85; 3: the PHP version
// from the lock, the Laravel start script, the static server, deploy files
// and workspace builds). Bump it with any such change so existing images
// are rebuilt instead of reused.
const recipeVersion = 3

// Recipe names how launchroomd turns a Railpack plan into an image: the
// recipe version and every preset's version. The dashboard puts it in the
// build key (docs/04 §7), so a release that changes the recipe rebuilds
// images on their next deploy instead of reusing ones built the old way.
func Recipe() string {
	parts := []string{"r" + strconv.Itoa(recipeVersion)}
	for _, p := range All() {
		parts = append(parts, p.Name()+strconv.Itoa(p.Version()))
	}
	return strings.Join(parts, " ")
}

// ByName returns a preset by name.
func ByName(name string) (Preset, bool) {
	for _, p := range All() {
		if p.Name() == name {
			return p, true
		}
	}
	return nil, false
}

// Result is a resolved preset: the merged effect of the enabled decisions.
type Result struct {
	Applied   state.AppliedPreset
	Framework string
	Effect
	Warnings []state.BuildWarning
	// Commands are the framework's standard one-off commands (D105).
	Commands []state.CommandSuggestion
}

// Resolve picks the preset (forced by name, "none" to disable, "" to detect),
// proposes, drops disabled decisions/warnings and merges the rest. It returns
// nil when no preset applies. An unknown forced name is reported as ok=false.
func Resolve(f Facts, i Info, forced string, disabled []string) (res *Result, ok bool) {
	return resolve(f, i, forced, disabled, false)
}

// ResolveRuntime resolves the preset of an app that builds from its own
// Dockerfile (scope runtime; D6: launchroom never changes the image). Only
// what acts on the running container applies: runtime env, aliases, health
// path, generated and required secrets, volume suggestions and processes.
// Plan patches and the release command belong to the Railpack image and are
// dropped, together with every decision left without a runtime effect and
// every warning not marked Runtime. The preset's port is a fallback for a
// Dockerfile without EXPOSE.
func ResolveRuntime(f Facts, forced string, disabled []string) (res *Result, ok bool) {
	return resolve(f, Info{Dockerfile: true}, forced, disabled, true)
}

func resolve(f Facts, i Info, forced string, disabled []string, runtimeOnly bool) (res *Result, ok bool) {
	var p Preset
	switch forced {
	case string(state.PresetNone):
		return nil, true
	case "":
		for _, c := range All() {
			if c.Match(f, i) {
				p = c
				break
			}
		}
		if p == nil {
			return nil, true
		}
	default:
		var found bool
		if p, found = ByName(forced); !found {
			return nil, false
		}
	}
	prop := p.Propose(f, i)
	r := &Result{
		Applied:   state.AppliedPreset{Name: state.Slug(p.Name()), Version: p.Version(), Decisions: []state.PresetDecision{}},
		Framework: prop.Framework,
		Effect:    Effect{Port: prop.Port, HealthPath: prop.HealthPath},
		Commands:  prop.Commands,
	}
	if runtimeOnly {
		r.Applied.Scope = state.PresetScopeRuntime
	}
	for _, d := range prop.Decisions {
		if slices.Contains(disabled, d.ID) {
			continue
		}
		if runtimeOnly {
			d.Effect.Plan, d.Effect.ReleaseCommand = PlanPatch{}, nil
			if !d.Effect.hasRuntime() {
				continue
			}
		}
		r.Applied.Decisions = append(r.Applied.Decisions, state.PresetDecision{ID: state.Slug(d.ID), Title: d.Title, Detail: d.Detail})
		merge(&r.Effect, d.Effect)
	}
	// A plan that keeps only some paths (Next.js standalone) drops node_modules.
	if isJavaScript(p) && r.Plan.DeployOnly == nil {
		r.Commands = append(r.Commands, jsCommands(f, i)...)
	}
	for _, w := range prop.Warnings {
		if !slices.Contains(disabled, w.Code) && (!runtimeOnly || w.Runtime) {
			r.Warnings = append(r.Warnings, state.BuildWarning{Code: state.Slug(w.Code), Message: w.Message})
		}
	}
	return r, true
}

// hasRuntime reports whether the effect acts on the running container.
func (e Effect) hasRuntime() bool {
	return len(e.RuntimeEnv) > 0 || len(e.Processes) > 0 || e.HealthPath != "" || e.Port != 0 ||
		len(e.GeneratedSecrets) > 0 || len(e.EnvAliases) > 0 || len(e.RequiredSecrets) > 0 || len(e.VolumeSuggestions) > 0
}

// PrePlan is what a preset needs before Railpack plans, from facts alone:
// variables of Railpack's environment (RAILPACK_PHP_ROOT_DIR) and PHP
// extensions to install. Its decisions are listed with the preset's other
// decisions by Propose, so a user removes them the same way.
type PrePlan struct {
	RailpackEnv   map[string]string
	PhpExtensions []state.PhpDependencyExtension
}

// prePlanner is a preset with decisions that act before Railpack plans.
type prePlanner interface {
	// PrePlan returns the pre-plan effect of each decision by ID, or nil
	// when the preset doesn't apply to the facts.
	PrePlan(f Facts) map[string]PrePlan
}

// ResolvePrePlan returns the pre-plan effect of the preset that applies
// (forced by name, "none" to disable, "" to detect from facts), without the
// decisions the user removed. The build merges it into Railpack's
// environment before planning (docs/04 §3).
func ResolvePrePlan(f Facts, forced string, disabled []string) PrePlan {
	var out PrePlan
	if forced == string(state.PresetNone) {
		return out
	}
	for _, p := range All() {
		if forced != "" && p.Name() != forced {
			continue
		}
		pp, ok := p.(prePlanner)
		if !ok {
			continue
		}
		byID := pp.PrePlan(f)
		if byID == nil {
			continue
		}
		for _, id := range slices.Sorted(maps.Keys(byID)) {
			if slices.Contains(disabled, id) {
				continue
			}
			out.RailpackEnv = mergeMap(out.RailpackEnv, byID[id].RailpackEnv)
			out.PhpExtensions = appendNew(out.PhpExtensions, byID[id].PhpExtensions, func(x state.PhpDependencyExtension) state.PhpExtensionIdent { return x.Name })
		}
		break
	}
	return out
}

func merge(dst *Effect, src Effect) {
	if src.Plan.StartCommand != "" {
		dst.Plan.StartCommand = src.Plan.StartCommand
	}
	dst.Plan.DeployVariables = mergeMap(dst.Plan.DeployVariables, src.Plan.DeployVariables)
	dst.Plan.BuildVariables = mergeMap(dst.Plan.BuildVariables, src.Plan.BuildVariables)
	dst.Plan.AppendBuildCommands = append(dst.Plan.AppendBuildCommands, src.Plan.AppendBuildCommands...)
	dst.Plan.DropBuildCommandsContaining = append(dst.Plan.DropBuildCommandsContaining, src.Plan.DropBuildCommandsContaining...)
	dst.Plan.BuildFiles = mergeMap(dst.Plan.BuildFiles, src.Plan.BuildFiles)
	dst.Plan.DeployFiles = mergeMap(dst.Plan.DeployFiles, src.Plan.DeployFiles)
	if src.Plan.DeployOnly != nil {
		dst.Plan.DeployOnly = slices.Clone(src.Plan.DeployOnly)
	}
	dst.RuntimeEnv = mergeMap(dst.RuntimeEnv, src.RuntimeEnv)
	dst.Processes = append(dst.Processes, src.Processes...)
	if src.ReleaseCommand != nil {
		dst.ReleaseCommand = slices.Clone(src.ReleaseCommand)
	}
	if src.HealthPath != "" {
		dst.HealthPath = src.HealthPath
	}
	for _, g := range src.GeneratedSecrets {
		if !slices.ContainsFunc(dst.GeneratedSecrets, func(have state.GeneratedSecret) bool { return have.Name == g.Name }) {
			dst.GeneratedSecrets = append(dst.GeneratedSecrets, g)
		}
	}
	if src.Port != 0 {
		dst.Port = src.Port
	}
	dst.EnvAliases = appendNew(dst.EnvAliases, src.EnvAliases, func(a state.EnvAlias) state.EnvName { return a.Name })
	dst.RequiredSecrets = appendNew(dst.RequiredSecrets, src.RequiredSecrets, func(r state.RequiredSecret) state.EnvName { return r.Name })
	dst.VolumeSuggestions = appendNew(dst.VolumeSuggestions, src.VolumeSuggestions, func(v state.VolumeSuggestion) state.ContainerPath { return v.Path })
}

// appendNew appends the items of src whose key dst doesn't have yet (the
// first decision that names a key wins, as for generated secrets).
func appendNew[T any, K comparable](dst, src []T, key func(T) K) []T {
	for _, item := range src {
		if !slices.ContainsFunc(dst, func(have T) bool { return key(have) == key(item) }) {
			dst = append(dst, item)
		}
	}
	return dst
}

func mergeMap(dst, src map[string]string) map[string]string {
	if len(src) == 0 {
		return dst
	}
	if dst == nil {
		dst = map[string]string{}
	}
	maps.Copy(dst, src)
	return dst
}

// DefaultPort is the port launchroom injects as $PORT when no preset says
// otherwise (docs/04 §3).
func DefaultPort(provider string) int {
	switch provider {
	case "node":
		return 3000
	case "python", "deno":
		// Deno.serve listens on 8000 unless told otherwise.
		return 8000
	default:
		// Go, JVM, PHP (FrankenPHP honours {$PORT}), static Caddy and others.
		return 8080
	}
}

// majorMinor parses the leading "major.minor" of a version range like "^16.2.1".
func majorMinor(v string) (major, minor int, ok bool) {
	v = strings.TrimLeft(strings.TrimSpace(v), "^~>=<v ")
	parts := strings.SplitN(v, ".", 3)
	if len(parts) == 0 {
		return 0, 0, false
	}
	maj, err := strconv.Atoi(parts[0])
	if err != nil {
		return 0, 0, false
	}
	if len(parts) == 1 {
		return maj, 0, true
	}
	min, err := strconv.Atoi(strings.TrimRightFunc(parts[1], func(r rune) bool { return r < '0' || r > '9' }))
	if err != nil {
		return maj, 0, true
	}
	return maj, min, true
}

func atLeast(v string, major, minor int) bool {
	ma, mi, ok := majorMinor(v)
	return ok && (ma > major || (ma == major && mi >= minor))
}
