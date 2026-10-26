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
	// NoAttestations disables SBOM/provenance (tests only).
	NoAttestations bool
	Log            *slog.Logger
	Now            func() time.Time
}

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
		o.PublicRegistry = "127.0.0.1:5000"
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
	m    *Masker
	sink LogSink
}

func (s maskedSink) Line(step string, stream state.LogStream, text string) {
	if s.sink != nil {
		s.sink.Line(step, stream, s.m.Mask(text))
	}
}

// secretValues lists every value that must never appear in logs.
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
	masker := NewMasker(secretValues(&job)...)
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
		if isHostKeyMismatch(err) {
			class = state.FailureClone
		} else if ctx.Err() != nil {
			class = state.FailureTimeout
		} else if isNetErr(err) || reTransient.MatchString(err.Error()) {
			class = state.FailureTransient
		}
		return nil, fail(class, "clone", err)
	}
	dir, err := appDir(src, job.Inputs.RootDir)
	if err != nil {
		return nil, fail(state.FailureConfig, "clone", err)
	}
	// Unsafe links outside the app folder (test fixtures elsewhere in a
	// monorepo) are removed, then the whole checkout is checked strictly.
	removed, err := pruneOutsideApp(src, dir)
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
		// Attach the inspection to any later failure.
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
		res.summary.Warnings = append(res.summary.Warnings, state.BuildWarning{Code: "unsafe-links-removed", Message: clipText(prunedMessage(removed), 1024)})
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
	img, err := b.build(ctx, &job, dir, work, res, buildSink)
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

// resolved is the outcome of builder resolution and planning.
type resolved struct {
	summary state.BuildSummary
	// railpack builds
	plan  []byte
	steps map[string]string
	// nextAdapter: the Next.js deployment adapter runs in this build.
	nextAdapter bool
	// dockerfile builds
	dockerfileDir  string
	dockerfileName string
	dockerArgs     map[string]bool
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

	if bs.Dockerfile != "" {
		return b.resolveInline(work, bs.Dockerfile, r, sink)
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
		s.StartCommand = clipText(df.Cmd, 4096)
		s.Port = dockerfilePort(sink, df)
		s.HealthPath = "/"
		if df.DevOnly != "" {
			s.Warnings = append(s.Warnings, state.BuildWarning{Code: "dockerfile-dev-only", Message: clipText("This Dockerfile looks like a development setup: "+df.DevOnly+". Switch to auto-build (Railpack) for a production image.", 1024)})
		}
		r.dockerfileDir, r.dockerfileName, r.dockerArgs = filepath.Dir(dfPath), filepath.Base(dfPath), df.Args
		return r, nil
	}

	if string(bs.RailpackVersion) != RailpackVersion && strings.TrimPrefix(string(bs.RailpackVersion), "v") != RailpackVersion {
		return nil, fail(state.FailureConfig, "plan", fmt.Errorf("railpack %s is not available on this server (its launchroomd ships railpack %s); update the service's builder version", bs.RailpackVersion, RailpackVersion))
	}
	sink.Line("plan", state.StreamStdout, "Planning with Railpack "+RailpackVersion)
	var planLogs []string
	rp, perr := planWithRailpack(dir, railpackEnv(bs, envNames), func(level, msg string) {
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
		e.Hints = hintsFor(strings.Join(planLogs, "\n"))
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

	disabled := make([]string, 0, len(bs.DisabledDecisions))
	for _, d := range bs.DisabledDecisions {
		disabled = append(disabled, string(d))
	}
	preset, ok := presets.Resolve(facts, rp.info, string(bs.Preset), disabled)
	if !ok {
		return nil, fail(state.FailureConfig, "plan", fmt.Errorf("unknown preset %q", bs.Preset))
	}
	port := presets.DefaultPort(rp.info.Provider)
	if preset != nil {
		r.nextAdapter = presetApplied(preset, presets.NextAdapterDecision)
		if r.nextAdapter && job.Inputs.Deployment != "" {
			// deploymentId for skew protection; one image keeps one id however often it is promoted.
			preset.Plan.BuildVariables["LAUNCHROOM_DEPLOYMENT_ID"] = string(job.Inputs.Deployment)
		}
		if err := applyPatch(rp.result.Plan, preset.Plan); err != nil {
			s.Warnings = append(s.Warnings, state.BuildWarning{Code: "preset-not-applied", Message: clipText("Preset "+string(preset.Applied.Name)+" could not patch the plan: "+err.Error(), 1024)})
		}
		s.Presets = []state.AppliedPreset{preset.Applied}
		s.RuntimeEnv = preset.RuntimeEnv
		s.Processes = preset.Processes
		s.ReleaseCommand = preset.ReleaseCommand
		s.HealthPath = state.HTTPPath(preset.HealthPath)
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
	if preset != nil && preset.Framework != "" {
		s.Framework = ident(preset.Framework)
	}
	p := state.Port(port)
	s.Port = &p
	if rp.info.Provider == "node" && b.o.Resources != nil {
		limitNodeBuild(rp.result.Plan, b.o.Resources(), job.Env)
	}
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

// pushTarget returns the buildkitd-side push name and the public repository.
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

func (b *Builder) build(ctx context.Context, job *Job, dir, work string, r *resolved, sink LogSink) (state.ImageRef, error) {
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
		Secrets:   secrets,
		Auth:      newRegistryAuth(b.o.RegistryHost, job.Task, b.o.Tokens, b.allowedScope(job.Inputs.Service)),
		ImageName: pushName,
		CacheRef:  b.o.RegistryHost + "/" + string(state.CacheRepository(job.Inputs.Service)) + ":buildcache",
		Sink:      sink,
	}
	if r.summary.Builder == state.BuilderDockerfile {
		req.Frontend = "dockerfile.v0"
		req.Locals = map[string]string{"context": dir, "dockerfile": r.dockerfileDir}
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
		req.Locals = map[string]string{"context": dir, "dockerfile": planDir}
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
	s.StartCommand = clipText(df.Cmd, 4096)
	s.Port = dockerfilePort(sink, df)
	s.HealthPath = "/"
	if df.DevOnly != "" {
		s.Warnings = append(s.Warnings, state.BuildWarning{Code: "dockerfile-dev-only", Message: clipText("This Dockerfile looks like a development setup: "+df.DevOnly+".", 1024)})
	}
	r.dockerfileDir, r.dockerfileName, r.dockerArgs = dfDir, "Dockerfile", df.Args
	return r, nil
}
