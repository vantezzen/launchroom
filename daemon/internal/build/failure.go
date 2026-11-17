package build

import (
	"context"
	"errors"
	"fmt"
	"net"
	"regexp"
	"slices"
	"strconv"
	"strings"
	"time"

	"github.com/vantezzen/launchroom/daemon/internal/build/buildkitd"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// Error is a classified build/plan failure (docs/04 §6).
type Error struct {
	Class state.FailureClass
	// Step is the step that failed ("clone", "plan", "install", "build", "push", …).
	Step string
	Err  error
	// Summary is what was detected before the failure (if planning got that far).
	Summary *state.BuildSummary
	Hints   []state.FailureHint
	// Inspection is the repository inspection of a plan job with Inspect,
	// collected before the failure (nil when the checkout never existed).
	Inspection *state.RepoInspection
}

func (e *Error) Error() string {
	if e.Step != "" {
		return fmt.Sprintf("%s (%s): %v", e.Class, e.Step, e.Err)
	}
	return fmt.Sprintf("%s: %v", e.Class, e.Err)
}

func (e *Error) Unwrap() error { return e.Err }

// Retryable reports whether the same inputs may succeed on another attempt.
func (e *Error) Retryable() bool {
	switch e.Class {
	case state.FailureTransient, state.FailureInternal:
		return true
	default:
		return false
	}
}

// Failure renders the protocol form.
func (e *Error) Failure() *state.TaskFailure {
	f := &state.TaskFailure{Class: e.Class, Step: state.Clip(e.Step, state.MaxFailureStepLen), Retryable: e.Retryable(), Hints: e.Hints, Summary: e.Summary}
	if len(f.Hints) > state.MaxFailureHints {
		f.Hints = f.Hints[:state.MaxFailureHints]
	}
	return f
}

func fail(class state.FailureClass, step string, err error) *Error {
	return &Error{Class: class, Step: step, Err: err}
}

// hint is a known-error pattern (docs/04 §6, failure classification).
type hint struct {
	code    state.HintCode
	re      *regexp.Regexp
	message string
	// also must match too (a second, independent pattern), when set.
	also *regexp.Regexp
	// render builds the message from the text and re's match, when set.
	render func(text string, m []string) string
}

var hints = []hint{
	{code: state.HintPnpmOutdatedLockfile, re: regexp.MustCompile(`ERR_PNPM_OUTDATED_LOCKFILE`), message: "pnpm-lock.yaml is out of date with package.json. Run `pnpm install` locally and commit the lockfile."},
	{code: state.HintNpmCiLockfile, re: regexp.MustCompile("npm ci` can only install packages when your package.json and package-lock.json"), message: "package-lock.json is out of sync with package.json. Run `npm install` locally and commit the lockfile."},
	{code: state.HintYarnFrozenLockfile, re: regexp.MustCompile(`(?i)lockfile needs to be updated|YN0028`), message: "yarn.lock is out of date. Run `yarn install` locally and commit the lockfile."},
	{code: state.HintNodeGypToolchain, re: regexp.MustCompile(`(?i)gyp ERR!|node-gyp`), message: "A native module needs a build toolchain. Add build packages via RAILPACK_BUILD_APT_PACKAGES (e.g. \"python3 make g++\") or use a prebuilt alternative."},
	{code: state.HintMissingModule, re: regexp.MustCompile(`Cannot find module '([^']+)'`), message: "A module is missing at build or runtime. Check that it is a dependency (not only a devDependency for runtime code); for Next.js standalone, see outputFileTracingIncludes."},
	{code: state.HintPhpExtension, re: regexp.MustCompile(`(?i)requires ext-([a-z0-9_]+)|PHP extension ([a-z0-9_]+) is missing`), render: phpExtensionMessage},
	{code: state.HintDjangoImproperlyConfig, re: regexp.MustCompile(`ImproperlyConfigured`), message: "Django settings failed during the build (often collectstatic). Make sure settings import without runtime-only secrets, or mark the variable as build-time."},
	{code: state.HintPythonModuleNotFound, re: regexp.MustCompile(`ModuleNotFoundError: No module named '([^']+)'`), message: "A Python module is missing. Add it to requirements.txt/pyproject.toml."},
	{code: state.HintOutOfMemory, re: regexp.MustCompile(`(?i)JavaScript heap out of memory|exit code: 137|signal: killed`), message: "The build ran out of memory. Raise the build server's BuildKit memory or reduce build parallelism (e.g. NODE_OPTIONS=--max-old-space-size)."},
	{code: state.HintNoStartCommand, re: regexp.MustCompile(`(?i)no start command`), message: "No start command was detected. Add a \"start\" script to package.json, a Procfile, or set a start command."},
	{code: state.HintRegistryRateLimit, re: regexp.MustCompile(`(?i)toomanyrequests|rate limit`), message: "An upstream registry or GitHub rate limit was hit. launchroom retries once; set GITHUB_TOKEN for builds if this keeps happening."},
	{code: state.HintViteEnvUndefined, re: regexp.MustCompile(`import\.meta\.env\.[A-Z_]+ is undefined`), message: "A Vite variable is undefined in the bundle. Mark it as a build-time variable (VITE_*)."},
	{code: state.HintDiskFull, re: reDiskFull, message: diskFullMessage},
	{code: state.HintBuildDNS, re: reBuildDNS, render: buildDNSMessage},
	{code: state.HintBuildDatabase, re: rePrerender, also: rePrivateUnreachable, render: buildDatabaseMessage},
}

var (
	reDiskFull = regexp.MustCompile(`(?i)no space left on device|\bENOSPC\b|disk quota exceeded`)
	// reBuildDNS is a public name (it has a dot) that didn't resolve inside
	// the build: the server's resolver doesn't answer from containers.
	reBuildDNS = regexp.MustCompile(`(?i)getaddrinfo (?:EAI_AGAIN|ENOTFOUND) ([a-z0-9-]+(?:\.[a-z0-9-]+)*\.[a-z]{2,})|Could not resolve host:? '?([a-z0-9-]+(?:\.[a-z0-9-]+)*\.[a-z]{2,})|lookup ([a-z0-9-]+(?:\.[a-z0-9-]+)*\.[a-z]{2,})(?: on [0-9a-f.:\[\]]+)?: (?:no such host|server misbehaving|i/o timeout)|bad address '([a-z0-9-]+(?:\.[a-z0-9-]+)*\.[a-z]{2,})'|Temporary failure in name resolution|Name or service not known|DNS lookup error`)
	// rePrerender is a framework rendering pages at build time.
	rePrerender = regexp.MustCompile(`Collecting page data|Generating static pages|Error occurred prerendering page|prerender|Prerendering|getStaticProps|generateStaticParams`)
	// rePrivateUnreachable is a database or private service the build can't reach.
	rePrivateUnreachable = regexp.MustCompile(`ECONNREFUSED|getaddrinfo (?:ENOTFOUND|EAI_AGAIN) [a-z0-9_-]+\b(?:[^.]|$)|Can't reach database server|connect ECONNREFUSED|P1001|Connection refused|could not translate host name`)
	rePrerenderPage      = regexp.MustCompile(`Error occurred prerendering page "([^"]{1,200})"`)
)

func phpExtensionMessage(_ string, m []string) string {
	ext := strings.ToLower(m[1] + m[2])
	return "The PHP extension " + ext + " is missing. Add \"ext-" + ext + "\": \"*\" to composer.json require, or set RAILPACK_PHP_EXTENSIONS=" + ext + "."
}

const diskFullMessage = "This server ran out of disk space during the build. Free space on the server, then retry the deploy."

func buildDNSMessage(_ string, m []string) string {
	host := ""
	for _, g := range m[1:] {
		if g != "" {
			host = g
			break
		}
	}
	if host == "" {
		return "A name didn't resolve inside the build. Builds use this server's DNS resolver; check that it answers from containers (a resolver on 127.0.0.53 doesn't), then retry."
	}
	return "Builds on this server can't resolve " + state.Clip(host, 253) + ". Builds use the server's DNS resolver; check that it answers from containers (a resolver on 127.0.0.53 doesn't), then retry."
}

func buildDatabaseMessage(text string, _ []string) string {
	page := "a page"
	if m := rePrerenderPage.FindStringSubmatch(text); m != nil {
		page = "the page " + m[1]
	}
	return "The build tried to reach your database while rendering " + page + ". Builds run apart from your services, so private hosts like your database aren't reachable then. Render the page at request time (in Next.js: export const dynamic = 'force-dynamic', or await connection()), or skip the query when the database isn't there."
}

func hintsFor(text string) []state.FailureHint {
	var out []state.FailureHint
	for _, h := range hints {
		m := h.re.FindStringSubmatch(text)
		if m == nil || h.also != nil && !h.also.MatchString(text) {
			continue
		}
		msg := h.message
		if h.render != nil {
			msg = h.render(text, m)
		}
		out = append(out, state.FailureHint{Code: h.code, Message: state.Clip(msg, 1024)})
	}
	return out
}

var (
	reTransient = regexp.MustCompile(`(?i)(connection reset|connection refused|i/o timeout|TLS handshake timeout|no such host|temporary failure in name resolution|unexpected EOF|502 Bad Gateway|503 Service Unavailable|504 Gateway|toomanyrequests|failed to fetch anonymous token|failed to do request|context deadline exceeded while|server misbehaving|EAI_AGAIN|ETIMEDOUT|ECONNRESET)`)
	reExecFail  = regexp.MustCompile(`(?i)(process ".*" did not complete successfully|executor failed running|exit code: [0-9]+)`)
)

func classifySolve(err error, step string, logTail string) *Error {
	msg := err.Error()
	e := fail(state.FailureBuild, step, err)
	var idle errBuildIdle
	if errors.As(err, &idle) {
		e.Class = state.FailureTimeout
		e.Hints = append(hintsFor(logTail), state.FailureHint{Code: state.HintBuildIdle, Message: "No build output for " + humanDuration(idle.idle) + " during " + idle.step + ", so launchroom stopped the build. A step may be waiting for input or for a network call that never answers; check the last lines above, then retry."})
		return e
	}
	switch {
	case errors.Is(err, context.DeadlineExceeded):
		e.Class = state.FailureTimeout
	case reExecFail.MatchString(msg):
		e.Class = state.FailureBuild
	case isNetErr(err) || reTransient.MatchString(msg):
		e.Class = state.FailureTransient
	case strings.Contains(msg, "failed to solve") || strings.Contains(msg, "dockerfile parse error") || strings.Contains(msg, "failed to parse"):
		e.Class = state.FailureBuild
	default:
		e.Class = state.FailureBuild
	}
	e.Hints = hintsFor(msg + "\n" + logTail)
	return e
}

// hintCarrier is an error with its own known-error hints (buildkitd.NotReadyError).
type hintCarrier interface {
	Hints() []state.FailureHint
}

// logTailCarrier is an error carrying log lines worth showing (buildkitd's last lines).
type logTailCarrier interface {
	Logs() []string
}

// buildkitUnavailable classifies a failure to reach buildkitd: internal
// (retried once by the dashboard), with buildkitd's own diagnosis as hints
// and its last log lines in the build log.
func buildkitUnavailable(err error, sink LogSink) *Error {
	e := fail(state.FailureInternal, "setup", err)
	var hc hintCarrier
	if errors.As(err, &hc) {
		e.Hints = hc.Hints()
	} else {
		e.Hints = []state.FailureHint{buildkitd.NotReadyHint()}
		if errors.Is(err, context.DeadlineExceeded) {
			e.Class = state.FailureTimeout // the task deadline passed while waiting
		}
	}
	var lc logTailCarrier
	if errors.As(err, &lc) && sink != nil {
		if lines := lc.Logs(); len(lines) > 0 {
			sink.Line("setup", state.StreamStderr, "BuildKit's last log lines:")
			for _, l := range lines {
				sink.Line("setup", state.StreamStderr, "  "+l)
			}
		}
	}
	return e
}

func isNetErr(err error) bool {
	var ne net.Error
	var oe *net.OpError
	return errors.As(err, &ne) || errors.As(err, &oe)
}

// freeDiskTimeout bounds the cleanup a disk-full build runs.
const freeDiskTimeout = 3 * time.Minute

// freeDiskAfter handles a build that ran out of disk: it makes sure the
// failure carries the disk-full hint, runs the server's cleanup at once
// (old images and build cache, never volumes) and says what it freed. A
// build that freed space is retryable, so the dashboard's one automatic
// retry runs on the freed disk.
func (b *Builder) freeDiskAfter(ctx context.Context, be *Error, sink LogSink) {
	i := slices.IndexFunc(be.Hints, func(h state.FailureHint) bool { return h.Code == state.HintDiskFull })
	if i < 0 {
		if be.Err == nil || !reDiskFull.MatchString(be.Err.Error()) {
			return
		}
		be.Hints = append(be.Hints, state.FailureHint{Code: state.HintDiskFull, Message: diskFullMessage})
		i = len(be.Hints) - 1
	}
	if b.o.FreeDisk == nil {
		return
	}
	sink.Line(be.Step, state.StreamStderr, "This server is out of disk space: removing old images and build cache")
	fctx, cancel := context.WithTimeout(context.WithoutCancel(ctx), freeDiskTimeout)
	defer cancel()
	res := b.o.FreeDisk(fctx)
	freed := res.ReclaimedMB
	switch {
	case freed > 0:
		be.Hints[i].Message = "This server ran out of disk space during the build. launchroom removed old images and build cache and freed " + formatMB(freed) + "; retry the deploy."
		if be.Class == state.FailureBuild {
			be.Class = state.FailureTransient
		}
	case res.Error != "":
		be.Hints[i].Message = state.Clip("This server ran out of disk space during the build, and cleaning up failed ("+res.Error+"). Free space on the server, then retry the deploy.", 1024)
	default:
		be.Hints[i].Message = "This server ran out of disk space during the build and has no old images or build cache left to remove. Free space on the server (unused volumes, other services), then retry the deploy."
	}
	sink.Line(be.Step, state.StreamStderr, "Cleanup freed "+formatMB(freed))
}

// formatMB renders a size for people ("3.1 GB", "420 MB").
func formatMB(mb int64) string {
	if mb >= 1024 {
		return strconv.FormatFloat(float64(mb)/1024, 'f', 1, 64) + " GB"
	}
	return strconv.FormatInt(max(mb, 0), 10) + " MB"
}

// humanDuration renders "20 minutes", "1 hour", "90 seconds".
func humanDuration(d time.Duration) string {
	switch {
	case d >= time.Hour && d%time.Hour == 0:
		return plural(int64(d/time.Hour), "hour")
	case d >= time.Minute:
		return plural(int64(d.Round(time.Minute)/time.Minute), "minute")
	}
	return plural(int64(d.Round(time.Second)/time.Second), "second")
}

func plural(n int64, unit string) string {
	if n == 1 {
		return "1 " + unit
	}
	return strconv.FormatInt(n, 10) + " " + unit + "s"
}
