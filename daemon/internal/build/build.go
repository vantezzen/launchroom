// Package build turns a commit into a pushed, digest-pinned image (docs/04):
// clone the exact SHA, resolve the builder (explicit → Dockerfile → preset +
// Railpack → Railpack), plan with Railpack in-process, apply launchroom
// presets, solve on the local buildkitd (Railpack gateway frontend or the
// Dockerfile frontend) with build variables as BuildKit secrets, push to the
// launchroom registry with SBOM and provenance attestations, and stream
// step-grouped, secret-masked logs.
package build

import (
	"context"
	"crypto/sha256"
	"encoding/base64"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"log/slog"
	"maps"
	"os"
	"path/filepath"
	"slices"
	"strconv"
	"strings"
	"time"

	bkclient "github.com/moby/buildkit/client"

	"github.com/vantezzen/launchroom/daemon/internal/build/buildkitd"
	"github.com/vantezzen/launchroom/daemon/internal/build/presets"
	"github.com/vantezzen/launchroom/daemon/internal/secret"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// Options configures a Builder.
type Options struct {
	// BuildKit connects to the local buildkitd (buildkitd.Manager.WaitClient):
	// it waits a bounded time for a starting or restarting buildkitd and
	// reports progress lines through waiting.
	BuildKit func(ctx context.Context, waiting func(line string)) (*bkclient.Client, error)
	// Tokens issues registry tokens for pushes and mirror pulls.
	Tokens TokenSource
	// RegistryHost is how buildkitd reaches the launchroom registry
	// ("registry.launchroom.internal:5000").
	RegistryHost string
	// PublicRegistry is the registry prefix workloads pull from ("127.0.0.1:5000").
	PublicRegistry string
	// WorkDir holds per-task checkouts (removed after each task).
	WorkDir string
	// InsecureGitHosts may be cloned over plain http (dev fixtures).
	InsecureGitHosts []string
	// Isolate makes sure the build network's egress restrictions are in
	// place (firewall.Manager.Sync) before build steps run; a failure fails
	// the build (fail closed). nil = no isolation (tests).
	Isolate func(ctx context.Context) error
	// Resources returns the limits applied to the running build container.
	Resources func() buildkitd.Resources
	// FreeDisk runs the server's cleanup at once (old images and build
	// cache, never volumes; cleanup.Cleaner.FreeSpace) when a build runs out
	// of disk. nil = no cleanup (tests).
	FreeDisk func(ctx context.Context) state.CleanupResult
	// IdleTimeout stops a build that prints nothing for this long (0 =
	// DefaultIdleTimeout, negative = never).
	IdleTimeout time.Duration
	// NoAttestations disables SBOM/provenance (tests only).
	NoAttestations bool
	Log            *slog.Logger
	Now            func() time.Time
}

// DefaultIdleTimeout stops a build that printed nothing for 20 minutes
// (a step waiting for input or for a network call that never answers),
// long before the task deadline.
const DefaultIdleTimeout = 20 * time.Minute

// Builder runs build and plan jobs.
type Builder struct{ o Options }

// New returns a Builder.
func New(o Options) *Builder {
	if o.Log == nil {
		o.Log = slog.New(slog.DiscardHandler)
	}
	if o.Now == nil {
		o.Now = time.Now
	}
	if o.PublicRegistry == "" {
		o.PublicRegistry = state.RegistryAddress
	}
	return &Builder{o: o}
}

// Job is one build or plan.
type Job struct {
	Task   state.TaskID
	Inputs state.BuildInputs
	// Image is the push repository (build tasks); empty for a plan.
	Image      state.ImageRepository
	CloneToken secret.Secret
	// SSHKey is the deploy key (base64 ed25519 seed) for ssh:// clone URLs.
	SSHKey secret.Secret
	Env    map[string]secret.Secret
	Sink   LogSink
	// Inspect collects a RepoInspection after the clone (plan tasks with
	// inspect, docs/20 §2); it is reported on success and on failure.
	Inspect bool

	// workspace is the monorepo workspace of the app folder (set by Run).
	workspace *workspace
	// contextDir is the workspace root of a workspace app (set by Run).
	contextDir string
}

// Outcome is a successful build or plan.
type Outcome struct {
	Summary      state.BuildSummary
	Image        state.ImageRef
	SecretsHash  state.SHA256Hex
	Attestations []state.AttestationKind
	Duration     time.Duration
	// Inspection is set for jobs with Inspect.
	Inspection *state.RepoInspection
}

type maskedSink struct {
	m    *secret.Masker
	sink LogSink
}

func (s maskedSink) Line(step string, stream state.LogStream, text string) {
	if s.sink != nil {
		s.sink.Line(step, stream, s.m.Mask(text))
	}
}

func secretValues(job *Job) []string {
	var out []string
	for _, v := range job.Env {
		out = append(out, v.Reveal())
	}
	if tok := job.CloneToken.Reveal(); tok != "" {
		out = append(out, tok)
		for _, user := range []string{"x-access-token", "oauth2", "launchroom"} {
			out = append(out, base64.StdEncoding.EncodeToString([]byte(user+":"+tok)))
		}
	}
	if key := job.SSHKey.Reveal(); key != "" {
		out = append(out, key)
	}
	return out
}

// SecretsHash is SHA-256 over the sorted NAME=value lines of the build
// variables (part of the build key, docs/04 §7); "" without variables.
func SecretsHash(env map[string]secret.Secret) state.SHA256Hex {
	if len(env) == 0 {
		return ""
	}
	h := sha256.New()
	for _, k := range slices.Sorted(maps.Keys(env)) {
		h.Write([]byte(k))
		h.Write([]byte{'='})
		h.Write([]byte(env[k].Reveal()))
		h.Write([]byte{'\n'})
	}
	return state.SHA256Hex(hex.EncodeToString(h.Sum(nil)))
}

// Run executes the job. Failures are *Error (classified); their messages are masked.
func (b *Builder) Run(ctx context.Context, job Job) (out *Outcome, err error) {
	start := b.o.Now()
	masker := secret.NewMasker(secretValues(&job)...)
	sink := maskedSink{m: masker, sink: job.Sink}
	defer func() {
		var be *Error
		if errors.As(err, &be) {
			if be.Err != nil {
				be.Err = errors.New(masker.Mask(be.Err.Error()))
			}
			if errors.Is(ctx.Err(), context.DeadlineExceeded) && be.Class != state.FailureTimeout {
				be.Class = state.FailureTimeout
			}
			b.freeDiskAfter(ctx, be, sink)
			sink.Line(be.Step, state.StreamStderr, "error: "+be.Error())
			for _, h := range be.Hints {
				sink.Line(be.Step, state.StreamStderr, "hint: "+h.Message)
			}
		}
	}()

	if err := os.MkdirAll(b.o.WorkDir, 0o700); err != nil {
		return nil, fail(state.FailureInternal, "setup", err)
	}
	work, err := os.MkdirTemp(b.o.WorkDir, "task-"+state.LowerID(job.Task)+"-")
	if err != nil {
		return nil, fail(state.FailureInternal, "setup", err)
	}
	defer func() { _ = os.RemoveAll(work) }()

	src := filepath.Join(work, "src")
	sink.Line("clone", state.StreamStdout, fmt.Sprintf("Fetching %s at %s", job.Inputs.Repo.CloneURL, job.Inputs.Repo.SHA))
	if err := clone(ctx, src, cloneOptions{
		Repo: job.Inputs.Repo, Token: job.CloneToken, SSHKey: job.SSHKey, InsecureHosts: b.o.InsecureGitHosts,
		Progress: func(s string) { sink.Line("clone", state.StreamStdout, s) },
	}); err != nil {
		class := state.FailureClone
		switch {
		case isHostKeyMismatch(err):
			// A changed host key looks like a network error but must not be retried.
		case ctx.Err() != nil:
			class = state.FailureTimeout
		case isNetErr(err) || reTransient.MatchString(err.Error()):
			class = state.FailureTransient
		}
		return nil, fail(class, "clone", err)
	}
	dir, err := appDir(src, job.Inputs.RootDir)
	if err != nil {
		return nil, fail(state.FailureConfig, "clone", err)
	}
	contextDir := dir
	ws, wsDir, note := detectWorkspace(src, dir)
	if note != "" {
		sink.Line("plan", state.StreamStdout, note)
	}
	if ws != nil {
		contextDir = wsDir
		job.workspace, job.contextDir = ws, wsDir
	}
	// Unsafe links outside the app folder (or its workspace: test fixtures
	// elsewhere in a monorepo) are removed, then the whole checkout is
	// checked strictly.
	removed, err := pruneOutsideApp(src, contextDir)
	if err != nil {
		return nil, fail(state.FailureConfig, "clone", err)
	}
	if len(removed) > 0 {
		sink.Line("clone", state.StreamStdout, prunedMessage(removed))
	}
	if err := checkTree(src); err != nil {
		return nil, fail(state.FailureConfig, "clone", err)
	}

	var inspection *state.RepoInspection
	if job.Inspect {
		sink.Line("plan", state.StreamStdout, "Reading the repository's manifest files")
		ins, ierr := Inspect(dir)
		if ierr != nil {
			return nil, fail(state.FailureConfig, "plan", fmt.Errorf("inspect repository: %w", ierr))
		}
		inspection = ins
		defer func() {
			var be *Error
			if errors.As(err, &be) && be.Inspection == nil {
				be.Inspection = inspection
			}
		}()
	}

	envNames := slices.Sorted(maps.Keys(job.Env))
	res, err := b.resolve(dir, work, &job, envNames, sink)
	if err != nil {
		return nil, err
	}
	if len(removed) > 0 {
		res.summary.Warnings = append(res.summary.Warnings, state.BuildWarning{Code: "unsafe-links-removed", Message: state.Clip(prunedMessage(removed), 1024)})
	}
	out = &Outcome{Summary: res.summary, Inspection: inspection}
	if job.Image == "" {
		out.Duration = b.o.Now().Sub(start)
		return out, nil
	}

	var next *nextCapture
	buildSink := LogSink(sink)
	if res.nextAdapter {
		next = &nextCapture{sink: sink}
		buildSink = next
	}
	img, err := b.build(ctx, &job, work, res, buildSink)
	if err != nil {
		var be *Error
		if errors.As(err, &be) && be.Summary == nil {
			s := res.summary
			be.Summary = &s
		}
		return nil, err
	}
	out.Image = img
	if next != nil {
		if info := next.result(); info != nil {
			out.Summary.Next = info
		} else {
			sink.Line("build", state.StreamStderr, "warning: the launchroom Next.js adapter reported no build summary (cached build step?)")
		}
	}
	out.SecretsHash = SecretsHash(job.Env)
	if !b.o.NoAttestations {
		out.Attestations = []state.AttestationKind{state.AttestSBOM, state.AttestProvenance}
	}
	out.Duration = b.o.Now().Sub(start)
	sink.Line("push", state.StreamStdout, "Pushed "+string(img))
	return out, nil
}

type resolved struct {
	summary state.BuildSummary
	// railpack builds
	plan        []byte
	steps       map[string]string
	nextAdapter bool
	// dockerfile builds
	dockerfileDir  string
	dockerfileName string
	dockerArgs     map[string]bool
	// contextDir is the BuildKit build context: the app folder, or the
	// workspace root of a workspace app.
	contextDir string
}

func presetApplied(r *presets.Result, decision string) bool {
	for _, d := range r.Applied.Decisions {
		if string(d.ID) == decision {
			return true
		}
	}
	return false
}

func (b *Builder) findDockerfile(dir string, spec state.RelPath) (path string, rel state.RelPath, err error) {
	candidates := defaultDockerfiles
	if spec != "" {
		candidates = []state.RelPath{spec}
	}
	for _, c := range candidates {
		p, err := fileIn(dir, c)
		if err != nil {
			return "", "", err
		}
		if p != "" {
			return p, c, nil
		}
	}
	return "", "", nil
}

func (b *Builder) resolve(dir, work string, job *Job, envNames []string, sink LogSink) (*resolved, error) {
	bs := job.Inputs.Builder
	r := &resolved{}
	s := &r.summary
	s.BuildEnv = make([]state.EnvName, 0, len(envNames))
	for _, n := range envNames {
		s.BuildEnv = append(s.BuildEnv, state.EnvName(n))
	}
	// Every read of the checkout by the daemon itself goes through os.Root:
	// even a symlink checkTree missed cannot make it read outside dir.
	root, err := os.OpenRoot(dir)
	if err != nil {
		return nil, fail(state.FailureInternal, "plan", err)
	}
	defer func() { _ = root.Close() }()
	facts := presets.Gather(root.FS(), envNames)
	for _, n := range facts.EnvRefs {
		if len(s.EnvReferences) < 256 {
			s.EnvReferences = append(s.EnvReferences, state.EnvName(n))
		}
	}

	r.contextDir = dir
	disabled := make([]string, 0, len(bs.DisabledDecisions))
	for _, d := range bs.DisabledDecisions {
		disabled = append(disabled, string(d))
	}
	var depExts []state.PhpDependencyExtension
	var unknownExts []string
	var lockFloor phpFloor
	if facts.Files["composer.json"] {
		depExts, unknownExts, lockFloor = readComposerLock(root.FS())
	}
	phpWarning, phpApp := dockerfilePhpWarning(bs.PhpExtensions, depExts, facts.Files["composer.json"])
	if bs.Dockerfile != "" {
		inline, err := b.resolveInline(work, bs.Dockerfile, r, sink)
		if err == nil {
			if phpApp {
				s.Warnings = append(s.Warnings, phpWarning)
			}
			dockerfileRuntime(facts, bs, disabled, s, sink)
		}
		return inline, err
	}

	dfPath, dfRel, err := b.findDockerfile(dir, bs.DockerfilePath)
	if err != nil {
		return nil, fail(state.FailureConfig, "plan", err)
	}
	useDockerfile := false
	switch bs.Kind {
	case state.BuilderDockerfile:
		if dfPath == "" {
			want := bs.DockerfilePath
			if want == "" {
				want = "Dockerfile"
			}
			return nil, fail(state.FailureConfig, "plan", fmt.Errorf("no %s found in the app directory", want))
		}
		useDockerfile = true
		s.BuilderReason = state.ReasonExplicit
	case state.BuilderAuto:
		if dfPath != "" {
			useDockerfile = true
			s.BuilderReason = state.ReasonDockerfileFound
		}
	default:
		s.BuilderReason = state.ReasonExplicit
	}

	if useDockerfile {
		sink.Line("plan", state.StreamStdout, "Building with your Dockerfile ("+string(dfRel)+")")
		dfName, err := filepath.Rel(dir, dfPath)
		if err != nil {
			return nil, fail(state.FailureConfig, "plan", err)
		}
		df, err := readDockerfile(root, dfName)
		if err != nil {
			return nil, fail(state.FailurePlan, "plan", fmt.Errorf("parse %s: %w", dfRel, err))
		}
		s.Builder = state.BuilderDockerfile
		s.DockerfilePath = dfRel
		s.StartCommand = state.Clip(df.Cmd, 4096)
		s.Port = dockerfilePort(sink, df)
		s.HealthPath = "/"
		if df.DevOnly != "" {
			s.Warnings = append(s.Warnings, state.BuildWarning{Code: "dockerfile-dev-only", Message: state.Clip("This Dockerfile looks like a development setup: "+df.DevOnly+". Switch to auto-build (Railpack) for a production image.", 1024)})
		}
		if phpApp {
			s.Warnings = append(s.Warnings, phpWarning)
		}
		dockerfileRuntime(facts, bs, disabled, s, sink)
		r.dockerfileDir, r.dockerfileName, r.dockerArgs = filepath.Dir(dfPath), filepath.Base(dfPath), df.Args
		if ws := job.workspace; ws != nil {
			if raw, err := root.ReadFile(dfName); err == nil && dockerfileWantsWorkspace(string(raw), ws) {
				r.contextDir = job.contextDir
				s.Workspace = ws.summary()
				sink.Line("plan", state.StreamStdout, "The Dockerfile builds from the workspace root ("+ws.rootName()+"), so that is its build context")
			}
		}
		return r, nil
	}

	if string(bs.RailpackVersion) != RailpackVersion && strings.TrimPrefix(string(bs.RailpackVersion), "v") != RailpackVersion {
		return nil, fail(state.FailureConfig, "plan", fmt.Errorf("railpack %s is not available on this server (its launchroomd ships railpack %s); update the service's builder version", bs.RailpackVersion, RailpackVersion))
	}
	sink.Line("plan", state.StreamStdout, "Planning with Railpack "+RailpackVersion)
	var planLogs []string
	rpEnv := railpackEnv(bs, envNames)
	prePlan := presets.ResolvePrePlan(facts, string(bs.Preset), disabled)
	for _, env := range []map[string]string{presets.PlanEnv(facts), prePlan.RailpackEnv} {
		for k, v := range env {
			if _, set := rpEnv[k]; !set {
				rpEnv[k] = v
			}
		}
	}
	// The preset's extensions (Laravel's pcntl) come first: they carry a
	// decision the user can remove.
	depExts = mergeDependencyExtensions(prePlan.PhpExtensions, depExts)
	withPhpExtensions(rpEnv, bs.PhpExtensions)
	withDependencyExtensions(rpEnv, depExts)
	planDir := dir
	ws := job.workspace
	if ws != nil {
		planDir = job.contextDir
		ws.railpackEnv(rpEnv, bs)
		s.Workspace = ws.summary()
		r.contextDir = planDir
		sink.Line("plan", state.StreamStdout, ws.describe())
	}
	rp, perr := planWithRailpack(planDir, rpEnv, func(level, msg string) {
		stream := state.StreamStdout
		if level == "error" || level == "warn" {
			stream = state.StreamStderr
		}
		for _, line := range strings.Split(strings.TrimRight(msg, "\n"), "\n") {
			planLogs = append(planLogs, line)
			sink.Line("plan", stream, line)
		}
	})
	if perr != nil {
		var be *Error
		if errors.As(perr, &be) {
			be.Hints = hintsFor(strings.Join(planLogs, "\n"))
		}
		return nil, perr
	}
	if rp == nil {
		e := fail(state.FailurePlan, "plan", errors.New("railpack could not plan this app (see the plan log); set build/start commands, add a Dockerfile, or pick another root directory"))
		e.Hints = append([]state.FailureHint{{Code: state.HintRailpackCannotPlan, Message: "Railpack could not detect how to build this app. Set build and start commands, add a Dockerfile, or pick another root directory."}},
			hintsFor(strings.Join(planLogs, "\n"))...)
		s.Builder = state.BuilderRailpack
		s.RailpackVersion = RailpackVersion
		if s.BuilderReason == "" {
			// auto builds only learn their reason after planning; a valid
			// summary must still name one (strict decoding on both sides).
			s.BuilderReason = state.ReasonRailpack
		}
		e.Summary = s
		return nil, e
	}

	if rp.info.Provider == "php" && lockFloor.Major > 0 {
		b.raisePhp(rp, lockFloor, s, sink)
	}
	preset, ok := presets.Resolve(facts, rp.info, string(bs.Preset), disabled)
	if !ok {
		return nil, fail(state.FailureConfig, "plan", fmt.Errorf("unknown preset %q", bs.Preset))
	}
	port := presets.DefaultPort(rp.info.Provider)
	if preset != nil {
		r.nextAdapter = presetApplied(preset, presets.NextAdapterDecision)
		if job.Inputs.Deployment != "" {
			// deploymentId for skew protection; one image keeps one id however often it is promoted.
			for decision, name := range map[string]string{presets.NextAdapterDecision: "LAUNCHROOM_DEPLOYMENT_ID", presets.NextDeploymentIDDecision: "NEXT_DEPLOYMENT_ID"} {
				if presetApplied(preset, decision) {
					if preset.Plan.BuildVariables == nil {
						preset.Plan.BuildVariables = map[string]string{}
					}
					preset.Plan.BuildVariables[name] = string(job.Inputs.Deployment)
				}
			}
		}
		if ws != nil {
			ws.patch(&preset.Plan)
		}
		if err := applyPatch(rp.result.Plan, preset.Plan); err != nil {
			s.Warnings = append(s.Warnings, state.BuildWarning{Code: "preset-not-applied", Message: state.Clip("Preset "+string(preset.Applied.Name)+" could not patch the plan: "+err.Error(), 1024)})
		}
		s.Presets = []state.AppliedPreset{preset.Applied}
		s.RuntimeEnv = preset.RuntimeEnv
		s.Processes = preset.Processes
		s.ReleaseCommand = preset.ReleaseCommand
		s.Commands = preset.Commands
		s.HealthPath = state.HTTPPath(preset.HealthPath)
		s.GeneratedSecrets = preset.GeneratedSecrets
		s.EnvAliases = preset.EnvAliases
		s.RequiredSecrets = preset.RequiredSecrets
		s.VolumeSuggestions = preset.VolumeSuggestions
		s.Warnings = append(s.Warnings, preset.Warnings...)
		if preset.Port != 0 {
			port = preset.Port
		}
		if bs.Kind == state.BuilderAuto {
			s.BuilderReason = state.ReasonPresetAndRailpack
		}
		sink.Line("plan", state.StreamStdout, "Applied preset "+string(preset.Applied.Name))
	} else if bs.Kind == state.BuilderAuto {
		s.BuilderReason = state.ReasonRailpack
	}
	if s.HealthPath == "" {
		s.HealthPath = "/"
	}
	railpackSummary(s, rp)
	if rp.info.Provider == "php" && len(bs.PhpExtensions) > 0 {
		s.PhpExtensions = slices.Clone(bs.PhpExtensions)
		sink.Line("plan", state.StreamStdout, "Adds PHP extensions: "+describePhpExtensions(bs.PhpExtensions))
	}
	if rp.info.Provider == "php" {
		if len(depExts) > 0 {
			s.PhpDependencyExtensions = depExts
			sink.Line("plan", state.StreamStdout, "Adds PHP extensions your dependencies need: "+describeDependencyExtensions(depExts))
		}
		if len(unknownExts) > 0 {
			s.Warnings = append(s.Warnings, state.BuildWarning{Code: "php-extension-unknown", Message: state.Clip("composer.lock asks for PHP extensions launchroom can't add on its own: "+strings.Join(unknownExts, ", ")+". If the build fails at composer install, install them through RAILPACK_PHP_EXTENSIONS or a Dockerfile.", 1024)})
		}
		_, ownCaddy := root.Lstat(phpCaddyfileAsset)
		_, ownStart := root.Lstat(phpStartScriptAsset)
		adjustPhpPlan(rp.result.Plan, phpPlanInput{
			OwnCaddyfile: ownCaddy == nil, OwnStartScript: ownStart == nil,
			VisitorIP:    preset != nil && presetApplied(preset, presets.PhpVisitorIPDecision),
			LaravelStart: preset != nil && presetApplied(preset, presets.LaravelStartScriptDecision),
		}, s, sink)
	}
	if rp.info.Provider == "node" && rp.info.Metadata["nodeIsSPA"] == "true" && ws == nil {
		_, ownCaddy := root.Lstat(phpCaddyfileAsset)
		_, ownTemplate := root.Lstat("Caddyfile.template")
		adjustStaticPlan(rp.result.Plan, ownCaddy == nil || ownTemplate == nil, multiPageSite(facts), viteBase(root.FS()), bs, s, sink)
	}
	if preset != nil && preset.Framework != "" {
		s.Framework = ident(preset.Framework)
	}
	p := state.Port(port)
	s.Port = &p
	if rp.info.Provider == "node" && b.o.Resources != nil {
		limitNodeBuild(rp.result.Plan, b.o.Resources(), job.Env)
	}
	dropConfigSecrets(rp.result.Plan, envNames)
	raw, err := json.Marshal(rp.result.Plan)
	if err != nil {
		return nil, fail(state.FailureInternal, "plan", err)
	}
	shown, err := summaryPlan(rp.result.Plan)
	if err != nil {
		return nil, fail(state.FailureInternal, "plan", err)
	}
	if len(shown) <= state.MaxRawObjectBytes {
		s.Plan = state.RawObject(shown)
	}
	if len(s.Warnings) > 64 {
		s.Warnings = s.Warnings[:64]
	}
	r.plan = raw
	r.steps = stepNames(rp.result.Plan)
	return r, nil
}

func (b *Builder) pushTarget(job *Job) (pushName string, public string, err error) {
	repo := state.AppRepository(job.Inputs.Service)
	want := b.o.PublicRegistry + "/" + string(repo)
	if string(job.Image) != want {
		return "", "", fmt.Errorf("image repository %s is not this registry's %s", job.Image, want)
	}
	return b.o.RegistryHost + "/" + string(repo) + ":" + state.LowerID(job.Inputs.Deployment), want, nil
}

func (b *Builder) allowedScope(svc state.ServiceID) func(state.RegistryRepository, state.RegistryAction) bool {
	app, cache := state.AppRepository(svc), state.CacheRepository(svc)
	return func(repo state.RegistryRepository, act state.RegistryAction) bool {
		switch {
		case repo == app || repo == cache:
			return true
		case strings.HasPrefix(string(repo), "mirror/"):
			return act == state.RegistryPull
		}
		return false
	}
}

func (b *Builder) build(ctx context.Context, job *Job, work string, r *resolved, sink LogSink) (state.ImageRef, error) {
	pushName, public, err := b.pushTarget(job)
	if err != nil {
		return "", fail(state.FailureConfig, "push", err)
	}
	if b.o.Isolate != nil {
		if err := b.o.Isolate(ctx); err != nil {
			return "", fail(state.FailureInternal, "setup", err)
		}
	}
	sink.Line("setup", state.StreamStdout, "Connecting to BuildKit")
	c, err := b.o.BuildKit(ctx, func(line string) { sink.Line("setup", state.StreamStdout, line) })
	if err != nil {
		return "", buildkitUnavailable(err, sink)
	}
	defer func() { _ = c.Close() }()
	if b.o.Resources != nil {
		resources := b.o.Resources()
		sink.Line("setup", state.StreamStdout, resources.Summary())
		if resources.NoSwap {
			sink.Line("setup", state.StreamStdout, buildkitd.SwapHint)
		}
	}

	secrets := make(map[string][]byte, len(job.Env))
	for k, v := range job.Env {
		secrets[k] = []byte(v.Reveal())
	}
	attrs := map[string]string{"platform": string(job.Inputs.Platform)}
	if !b.o.NoAttestations {
		attrs["attest:sbom"] = "generator=" + SBOMGenerator
		attrs["attest:provenance"] = "mode=min"
	}
	req := solveRequest{
		Idle:      b.idleTimeout(),
		Secrets:   secrets,
		Auth:      newRegistryAuth(b.o.RegistryHost, job.Task, b.o.Tokens, b.allowedScope(job.Inputs.Service)),
		ImageName: pushName,
		CacheRef:  b.o.RegistryHost + "/" + string(state.CacheRepository(job.Inputs.Service)) + ":buildcache",
		Sink:      sink,
	}
	if r.summary.Builder == state.BuilderDockerfile {
		req.Frontend = "dockerfile.v0"
		req.Locals = map[string]string{"context": r.contextDir, "dockerfile": r.dockerfileDir}
		attrs["filename"] = r.dockerfileName
		for _, n := range job.Inputs.BuildArgs {
			if v, ok := job.Env[string(n)]; ok && r.dockerArgs[string(n)] {
				attrs["build-arg:"+string(n)] = v.Reveal()
			}
		}
		req.StepOf = dockerfileStep
	} else {
		planDir := filepath.Join(work, "plan")
		if err := os.MkdirAll(planDir, 0o700); err != nil {
			return "", fail(state.FailureInternal, "setup", err)
		}
		if err := os.WriteFile(filepath.Join(planDir, "railpack-plan.json"), r.plan, 0o600); err != nil {
			return "", fail(state.FailureInternal, "setup", err)
		}
		req.Frontend = "gateway.v0"
		req.Locals = map[string]string{"context": r.contextDir, "dockerfile": planDir}
		attrs["source"] = RailpackFrontend
		attrs["filename"] = "railpack-plan.json"
		attrs["build-arg:cache-key"] = string(job.Inputs.CacheKey)
		attrs["build-arg:secrets-hash"] = string(SecretsHash(job.Env))
		req.StepOf = railpackStepper(r.steps)
	}
	req.FrontendAttrs = attrs
	sink.Line("setup", state.StreamStdout, "Building "+pushName)
	res, err := solve(ctx, c, req)
	if err != nil {
		step := "build"
		if res != nil && res.FailedStep != "" {
			step = res.FailedStep
		}
		tail := ""
		if res != nil {
			tail = res.Tail
		}
		return "", classifySolve(err, step, tail)
	}
	if !strings.HasPrefix(res.Digest, "sha256:") {
		return "", fail(state.FailureInternal, "push", fmt.Errorf("buildkit returned no image digest"))
	}
	ref := state.ImageRef(public + "@" + res.Digest)
	if err := ref.Validate(); err != nil {
		return "", fail(state.FailureInternal, "push", err)
	}
	return ref, nil
}

// inlineDockerfileDir is where an inline Dockerfile is written, inside the
// task's work dir and outside the checkout ("src").
const inlineDockerfileDir = "launchroom-dockerfile"

// resolveInline uses the Dockerfile stored in launchroom (BuilderSpec.dockerfile,
// docs/20 §5): written to a private directory next to the checkout, read
// through os.Root like a repository Dockerfile, and built against the app dir.
func (b *Builder) resolveInline(work, content string, r *resolved, sink LogSink) (*resolved, error) {
	s := &r.summary
	dfDir := filepath.Join(work, inlineDockerfileDir)
	if err := os.Mkdir(dfDir, 0o700); err != nil {
		return nil, fail(state.FailureInternal, "plan", err)
	}
	if err := os.WriteFile(filepath.Join(dfDir, "Dockerfile"), []byte(content), 0o600); err != nil {
		return nil, fail(state.FailureInternal, "plan", err)
	}
	root, err := os.OpenRoot(dfDir)
	if err != nil {
		return nil, fail(state.FailureInternal, "plan", err)
	}
	defer func() { _ = root.Close() }()
	sink.Line("plan", state.StreamStdout, "Building with the Dockerfile stored in launchroom")
	df, err := readDockerfile(root, "Dockerfile")
	if err != nil {
		return nil, fail(state.FailurePlan, "plan", fmt.Errorf("parse the stored Dockerfile: %w", err))
	}
	s.Builder = state.BuilderDockerfile
	s.BuilderReason = state.ReasonExplicit
	s.StartCommand = state.Clip(df.Cmd, 4096)
	s.Port = dockerfilePort(sink, df)
	s.HealthPath = "/"
	if df.DevOnly != "" {
		s.Warnings = append(s.Warnings, state.BuildWarning{Code: "dockerfile-dev-only", Message: state.Clip("This Dockerfile looks like a development setup: "+df.DevOnly+".", 1024)})
	}
	r.dockerfileDir, r.dockerfileName, r.dockerArgs = dfDir, "Dockerfile", df.Args
	return r, nil
}

func (b *Builder) idleTimeout() time.Duration {
	switch {
	case b.o.IdleTimeout < 0:
		return 0
	case b.o.IdleTimeout == 0:
		return DefaultIdleTimeout
	}
	return b.o.IdleTimeout
}

// multiPageSite says whether a static site has a page per route, so an
// unknown path must be a 404 (Astro, Next.js export, Gatsby).
func multiPageSite(f presets.Facts) bool {
	for _, dep := range []string{"astro", "next", "gatsby"} {
		if _, ok := f.PackageJSON.Dep(dep); ok {
			return true
		}
	}
	return false
}

// raisePhp moves the plan to the PHP version composer.lock needs when
// Railpack picked an older one from composer.json.
func (b *Builder) raisePhp(rp *railpackPlan, floor phpFloor, s *state.BuildSummary, sink LogSink) {
	resolved := rp.info.Packages["php"]
	parts := strings.SplitN(resolved, ".", 3)
	if len(parts) < 2 {
		return
	}
	major, err1 := strconv.Atoi(parts[0])
	minor, err2 := strconv.Atoi(parts[1])
	if err1 != nil || err2 != nil || !floor.above(major, minor) {
		return
	}
	if err := raisePhpVersion(rp.result.Plan, floor); err != nil {
		s.Warnings = append(s.Warnings, state.BuildWarning{Code: "php-version-not-raised", Message: state.Clip("composer.lock needs PHP "+floor.String()+" ("+floor.For+"), but launchroom could not move the build off PHP "+resolved+": "+err.Error()+". Raise require.php in composer.json.", 1024)})
		return
	}
	v := floor.String()
	rp.info.Packages["php"] = v
	if pkg := rp.result.ResolvedPackages["php"]; pkg != nil {
		pkg.ResolvedVersion = &v
		pkg.Source = "composer.lock"
	}
	sink.Line("plan", state.StreamStdout, "Uses PHP "+v+": composer.lock needs it ("+floor.For+"), while composer.json allows PHP "+resolved)
}
