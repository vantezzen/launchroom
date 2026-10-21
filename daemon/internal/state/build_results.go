package state

import (
	"strings"
	"unicode/utf8"
)

// Results of build and plan tasks (docs/04 §4–§6).

// Limits of build summaries.
const (
	maxPackages     = 64
	maxPresets      = 8
	maxDecisions    = 32
	maxWarnings     = 64
	maxProcesses    = 16
	maxEnvRefs      = 256
	maxRuntimeEnv   = 64
	maxHints        = 16
	maxSummaryText  = 1024
	maxBuildEnvRefs = 1024
)

// ResolvedPackage is a toolchain/package version Railpack resolved and where it came from.
type ResolvedPackage struct {
	Name    Ident  `json:"name"`
	Version string `json:"version" jsonschema:"maxLength=128"`
	// Source says where the version came from (".nvmrc", "package.json > engines", "railpack default", …).
	Source string `json:"source,omitzero" jsonschema:"maxLength=256"`
}

// PresetDecision is one removable decision a preset made (a UI chip).
type PresetDecision struct {
	// ID is stable per preset version; BuilderSpec.disabledDecisions references it.
	ID     Slug   `json:"id"`
	Title  string `json:"title" jsonschema:"maxLength=256"`
	Detail string `json:"detail,omitzero" jsonschema:"maxLength=1024"`
}

// AppliedPreset is a launchroom preset that matched and the decisions it applied.
type AppliedPreset struct {
	Name Slug `json:"name"`
	// Version of the preset code (bumped when its behaviour changes).
	Version   int              `json:"version" jsonschema:"minimum=1"`
	Decisions []PresetDecision `json:"decisions"`
}

// ProcessSuggestion is an extra process from the same image (queue worker, scheduler, …).
type ProcessSuggestion struct {
	Name Slug `json:"name"`
	// Args is the argv to run in the image (never a shell string).
	Args []string `json:"args" jsonschema:"minItems=1,maxItems=64"`
}

// BuildWarning is a non-fatal finding shown with the plan.
type BuildWarning struct {
	Code    Slug   `json:"code"`
	Message string `json:"message" jsonschema:"maxLength=1024"`
}

// BuildSummary is the "we detected…" data (docs/04 §4): the resolved builder,
// what Railpack detected, the preset decisions and the runtime contract.
type BuildSummary struct {
	Builder       BuilderKind   `json:"builder"`
	BuilderReason BuilderReason `json:"builderReason"`
	// DockerfilePath is the Dockerfile used (dockerfile builds), relative to rootDir.
	DockerfilePath RelPath `json:"dockerfilePath,omitzero"`
	// RailpackVersion is the Railpack version that planned the build (railpack builds).
	RailpackVersion Semver `json:"railpackVersion,omitzero"`
	// Provider is the Railpack provider ("node", "php", "python", …).
	Provider Ident `json:"provider,omitzero"`
	// Framework is the detected framework ("next", "vite", "laravel", "django", "fastapi", "express", …).
	Framework Ident `json:"framework,omitzero"`
	// PackageManager is the detected package manager ("npm", "pnpm", "composer", "uv", …).
	PackageManager Ident             `json:"packageManager,omitzero"`
	Packages       []ResolvedPackage `json:"packages,omitzero" jsonschema:"nullable,maxItems=64"`
	// Commands as they run inside the build/app container (shell strings, display only).
	InstallCommand string `json:"installCommand,omitzero" jsonschema:"maxLength=4096"`
	BuildCommand   string `json:"buildCommand,omitzero" jsonschema:"maxLength=4096"`
	StartCommand   string `json:"startCommand,omitzero" jsonschema:"maxLength=4096"`
	// Port is the port the app is expected to listen on ($PORT is injected with this value).
	Port    *Port           `json:"port,omitzero" jsonschema:"nullable"`
	Presets []AppliedPreset `json:"presets,omitzero" jsonschema:"nullable,maxItems=8"`
	// RuntimeEnv are non-secret runtime defaults suggested by presets (HOSTNAME=0.0.0.0, …).
	RuntimeEnv map[string]string `json:"runtimeEnv,omitzero" jsonschema:"nullable"`
	// Processes are suggested extra processes from the same image.
	Processes []ProcessSuggestion `json:"processes,omitzero" jsonschema:"nullable,maxItems=16"`
	// ReleaseCommand runs once per deploy before rollout (argv), e.g. migrations.
	ReleaseCommand []string `json:"releaseCommand,omitzero" jsonschema:"nullable,minItems=1,maxItems=64"`
	// HealthPath is the suggested HTTP health check path.
	HealthPath HTTPPath `json:"healthPath,omitzero"`
	// BuildEnv names the build-scoped variables passed as BuildKit secrets (names only).
	BuildEnv []EnvName `json:"buildEnv,omitzero" jsonschema:"nullable,maxItems=1024"`
	// EnvReferences are variable names referenced in the source (cheap static scan).
	EnvReferences []EnvName      `json:"envReferences,omitzero" jsonschema:"nullable,maxItems=256"`
	Warnings      []BuildWarning `json:"warnings,omitzero" jsonschema:"nullable,maxItems=64"`
	// Plan is the resolved Railpack build plan (railpack builds); it lists secret names only.
	Plan RawObject `json:"plan,omitzero"`
	// Next is what the launchroom Next.js deployment adapter reported (build tasks of Next ≥ 16.2 apps).
	Next *NextBuildInfo `json:"next,omitzero" jsonschema:"nullable"`
}

// Limits of NextBuildInfo.
const (
	maxNextISRRoutes = 32
	maxNextCount     = 1_000_000
)

// NextBuildInfo is the build manifest summary of @vantezzen/launchroom-next-adapter
// (docs/04 §3): what the dashboard shows about a Next.js build.
type NextBuildInfo struct {
	AdapterVersion Semver `json:"adapterVersion"`
	NextVersion    Semver `json:"nextVersion"`
	// DeploymentID is the Next.js deploymentId baked into the build (skew protection).
	DeploymentID string `json:"deploymentId,omitzero" jsonschema:"maxLength=128"`
	// ServerEntry is the standalone server, relative to the app directory (".next/standalone/server.js").
	ServerEntry RelPath `json:"serverEntry,omitzero"`
	// Middleware says whether the app has middleware/proxy, and its runtime ("nodejs", "edge").
	Middleware        bool  `json:"middleware"`
	MiddlewareRuntime Ident `json:"middlewareRuntime,omitzero"`
	// ImageOptimization is on unless images.unoptimized or a custom loader is configured; Sharp says whether sharp is installed.
	ImageOptimization bool `json:"imageOptimization"`
	Sharp             bool `json:"sharp"`
	// CacheHandler says whether a cacheHandler/cacheHandlers is configured (shared ISR cache).
	CacheHandler bool            `json:"cacheHandler"`
	Routes       NextRouteCounts `json:"routes"`
	// ISRRoutes are prerendered paths with time-based revalidation (first 32).
	ISRRoutes []HTTPPath `json:"isrRoutes,omitzero" jsonschema:"nullable,maxItems=32"`
}

// NextRouteCounts counts the user-facing outputs of a Next.js build.
type NextRouteCounts struct {
	AppPages    int `json:"appPages" jsonschema:"minimum=0,maximum=1000000"`
	AppRoutes   int `json:"appRoutes" jsonschema:"minimum=0,maximum=1000000"`
	Pages       int `json:"pages" jsonschema:"minimum=0,maximum=1000000"`
	PagesAPI    int `json:"pagesApi" jsonschema:"minimum=0,maximum=1000000"`
	StaticFiles int `json:"staticFiles" jsonschema:"minimum=0,maximum=1000000"`
	// Prerendered counts fully static prerenders, ISR those with time-based revalidation.
	Prerendered int `json:"prerendered" jsonschema:"minimum=0,maximum=1000000"`
	ISR         int `json:"isr" jsonschema:"minimum=0,maximum=1000000"`
	// Edge counts routes built for the edge runtime (they run in the Node.js server here).
	Edge int `json:"edge" jsonschema:"minimum=0,maximum=1000000"`
}

// Validate checks the adapter report.
func (n *NextBuildInfo) Validate() error {
	e := &errs{}
	n.validate(e, "next")
	return e.err()
}

func (n *NextBuildInfo) validate(e *errs, p string) {
	e.check(p+".adapterVersion", n.AdapterVersion)
	e.check(p+".nextVersion", n.NextVersion)
	checkText(e, p+".deploymentId", n.DeploymentID, 128)
	if n.ServerEntry != "" {
		e.check(p+".serverEntry", n.ServerEntry)
	}
	if n.MiddlewareRuntime != "" {
		e.check(p+".middlewareRuntime", n.MiddlewareRuntime)
	}
	r := n.Routes
	counts := []struct {
		name string
		v    int
	}{
		{"appPages", r.AppPages}, {"appRoutes", r.AppRoutes}, {"pages", r.Pages}, {"pagesApi", r.PagesAPI},
		{"staticFiles", r.StaticFiles}, {"prerendered", r.Prerendered}, {"isr", r.ISR}, {"edge", r.Edge},
	}
	for _, c := range counts {
		if c.v < 0 || c.v > maxNextCount {
			e.addf(p+".routes."+c.name, "must be between 0 and %d", maxNextCount)
		}
	}
	checkCount(e, p+".isrRoutes", len(n.ISRRoutes), 0, maxNextISRRoutes)
	for i, r := range n.ISRRoutes {
		e.check(idx(p+".isrRoutes", i), r)
	}
}

func (s *BuildSummary) validate(e *errs, p string) {
	switch s.Builder {
	case BuilderDockerfile, BuilderRailpack:
	default:
		e.addf(p+".builder", "must be dockerfile or railpack")
	}
	e.check(p+".builderReason", s.BuilderReason)
	if s.DockerfilePath != "" {
		e.check(p+".dockerfilePath", s.DockerfilePath)
	}
	if s.RailpackVersion != "" {
		e.check(p+".railpackVersion", s.RailpackVersion)
	}
	for name, v := range map[string]Ident{"provider": s.Provider, "framework": s.Framework, "packageManager": s.PackageManager} {
		if v != "" {
			e.check(p+"."+name, v)
		}
	}
	checkCount(e, p+".packages", len(s.Packages), 0, maxPackages)
	for i, pk := range s.Packages {
		pp := idx(p+".packages", i)
		e.check(pp+".name", pk.Name)
		checkText(e, pp+".version", pk.Version, 128)
		checkText(e, pp+".source", pk.Source, 256)
	}
	checkText(e, p+".installCommand", s.InstallCommand, maxCommandLen)
	checkText(e, p+".buildCommand", s.BuildCommand, maxCommandLen)
	checkText(e, p+".startCommand", s.StartCommand, maxCommandLen)
	if s.Port != nil {
		e.check(p+".port", *s.Port)
	}
	checkCount(e, p+".presets", len(s.Presets), 0, maxPresets)
	for i, ap := range s.Presets {
		pp := idx(p+".presets", i)
		e.check(pp+".name", ap.Name)
		if ap.Version < 1 {
			e.addf(pp+".version", "must be >= 1")
		}
		if ap.Decisions == nil {
			e.addf(pp+".decisions", "is required ([] when empty)")
		}
		checkCount(e, pp+".decisions", len(ap.Decisions), 0, maxDecisions)
		for j, d := range ap.Decisions {
			dp := idx(pp+".decisions", j)
			e.check(dp+".id", d.ID)
			checkText(e, dp+".title", d.Title, maxLabelText)
			checkText(e, dp+".detail", d.Detail, maxSummaryText)
		}
	}
	checkEnvMap(e, p+".runtimeEnv", s.RuntimeEnv, maxRuntimeEnv)
	checkCount(e, p+".processes", len(s.Processes), 0, maxProcesses)
	for i, pr := range s.Processes {
		pp := idx(p+".processes", i)
		e.check(pp+".name", pr.Name)
		if pr.Args == nil {
			e.addf(pp+".args", "is required")
		}
		checkArgv(e, pp+".args", pr.Args)
	}
	checkArgv(e, p+".releaseCommand", s.ReleaseCommand)
	if s.HealthPath != "" {
		e.check(p+".healthPath", s.HealthPath)
	}
	checkCount(e, p+".buildEnv", len(s.BuildEnv), 0, maxBuildEnvRefs)
	for i, n := range s.BuildEnv {
		e.check(idx(p+".buildEnv", i), n)
	}
	checkCount(e, p+".envReferences", len(s.EnvReferences), 0, maxEnvRefs)
	for i, n := range s.EnvReferences {
		e.check(idx(p+".envReferences", i), n)
	}
	checkCount(e, p+".warnings", len(s.Warnings), 0, maxWarnings)
	for i, w := range s.Warnings {
		wp := idx(p+".warnings", i)
		e.check(wp+".code", w.Code)
		checkText(e, wp+".message", w.Message, maxSummaryText)
	}
	if len(s.Plan) > 0 {
		e.check(p+".plan", s.Plan)
	}
	if s.Next != nil {
		s.Next.validate(e, p+".next")
	}
}

func (b *BuildResult) validate(e *errs) {
	e.check("build.image", b.Image)
	if b.DurationMs < 0 {
		e.addf("build.durationMs", "must be >= 0")
	}
	if b.Summary != nil {
		b.Summary.validate(e, "build.summary")
	}
	if b.SecretsHash != "" {
		e.check("build.secretsHash", b.SecretsHash)
	}
	checkCount(e, "build.attestations", len(b.Attestations), 0, 2)
	for i, a := range b.Attestations {
		e.check(idx("build.attestations", i), a)
	}
	unique(e, "build.attestations", b.Attestations)
}

// PlanResult is the outcome of a plan task.
type PlanResult struct {
	DurationMs int64        `json:"durationMs" jsonschema:"minimum=0"`
	Summary    BuildSummary `json:"summary"`
}

func (r *PlanResult) validate(e *errs) {
	if r.DurationMs < 0 {
		e.addf("plan.durationMs", "must be >= 0")
	}
	r.Summary.validate(e, "plan.summary")
}

// FailureHint is a known-error hint ("ERR_PNPM_OUTDATED_LOCKFILE → run pnpm install and commit the lockfile").
type FailureHint struct {
	Code    Slug   `json:"code"`
	Message string `json:"message" jsonschema:"maxLength=1024"`
}

// TaskFailure classifies a failed build or plan task.
type TaskFailure struct {
	Class FailureClass `json:"class"`
	// Step is the build step that failed ("clone", "plan", "install", "build", "push", …).
	Step string `json:"step,omitzero" jsonschema:"maxLength=128"`
	// Retryable says whether retrying the same inputs may succeed.
	Retryable bool          `json:"retryable"`
	Hints     []FailureHint `json:"hints,omitzero" jsonschema:"nullable,maxItems=16"`
	// Summary is set when planning got far enough to detect something (plan_error, build_error).
	Summary *BuildSummary `json:"summary,omitzero" jsonschema:"nullable"`
}

func (f *TaskFailure) validate(e *errs) {
	e.check("failure.class", f.Class)
	checkText(e, "failure.step", f.Step, 128)
	checkCount(e, "failure.hints", len(f.Hints), 0, maxHints)
	for i, h := range f.Hints {
		hp := idx("failure.hints", i)
		e.check(hp+".code", h.Code)
		checkText(e, hp+".message", h.Message, maxSummaryText)
	}
	if f.Summary != nil {
		f.Summary.validate(e, "failure.summary")
	}
}

// LowerID lowercases a public id for use in Docker repository paths and tags.
func LowerID[T ~string](id T) string { return strings.ToLower(string(id)) }

// Limits of a repository inspection (docs/20 §2).
const (
	MaxInspectionTree       = 2000
	MaxInspectionFiles      = 48
	MaxInspectedFileBytes   = 32768
	MaxInspectionTotalBytes = 262144
	maxProbePorts           = 16
)

// RepoInspection is what launchroomd read from a checkout for a generated
// Dockerfile (plan tasks with inspect): a file listing of the app directory
// and selected manifest and entry files, never secrets (docs/20 §2).
type RepoInspection struct {
	Tree          []RelPath       `json:"tree" jsonschema:"nullable,maxItems=2000"`
	TreeTruncated bool            `json:"treeTruncated"`
	Files         []InspectedFile `json:"files" jsonschema:"nullable,maxItems=48"`
}

// InspectedFile is one file of a RepoInspection (UTF-8 text, cut at its limit).
type InspectedFile struct {
	Path      RelPath `json:"path"`
	Content   string  `json:"content" jsonschema:"maxLength=32768"`
	Truncated bool    `json:"truncated"`
}

func (r *RepoInspection) validate(e *errs) {
	checkCount(e, "inspection.tree", len(r.Tree), 0, MaxInspectionTree)
	for i, p := range r.Tree {
		e.check(idx("inspection.tree", i), p)
	}
	checkCount(e, "inspection.files", len(r.Files), 0, MaxInspectionFiles)
	total := 0
	for i, f := range r.Files {
		fp := idx("inspection.files", i)
		e.check(fp+".path", f.Path)
		checkText(e, fp+".content", f.Content, MaxInspectedFileBytes)
		if !utf8.ValidString(f.Content) {
			e.addf(fp+".content", "must be valid UTF-8")
		}
		total += len(f.Content)
	}
	if total > MaxInspectionTotalBytes {
		e.addf("inspection.files", "content is %d bytes, at most %d allowed", total, MaxInspectionTotalBytes)
	}
}
