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
}

// Proposal is what a preset proposes before user removals are applied.
type Proposal struct {
	Framework string
	// Port and HealthPath are defaults that hold even if every decision is removed.
	Port       int
	HealthPath string
	Decisions  []Decision
	Warnings   []Warning
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
	return []Preset{laravel{}, django{}, pythonASGI{}, nextjs{}, staticSPA{}, nodeServer{}}
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
}

// Resolve picks the preset (forced by name, "none" to disable, "" to detect),
// proposes, drops disabled decisions/warnings and merges the rest. It returns
// nil when no preset applies. An unknown forced name is reported as ok=false.
func Resolve(f Facts, i Info, forced string, disabled []string) (res *Result, ok bool) {
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
	}
	for _, d := range prop.Decisions {
		if slices.Contains(disabled, d.ID) {
			continue
		}
		r.Applied.Decisions = append(r.Applied.Decisions, state.PresetDecision{ID: state.Slug(d.ID), Title: d.Title, Detail: d.Detail})
		merge(&r.Effect, d.Effect)
	}
	for _, w := range prop.Warnings {
		if !slices.Contains(disabled, w.Code) {
			r.Warnings = append(r.Warnings, state.BuildWarning{Code: state.Slug(w.Code), Message: w.Message})
		}
	}
	return r, true
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
	if src.Port != 0 {
		dst.Port = src.Port
	}
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
	case "node", "deno":
		return 3000
	case "python":
		return 8000
	default:
		// Go, JVM, PHP (FrankenPHP honours {$PORT}), static Caddy and others.
		return 8080
	}
}

// ---- version helpers ----

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
