package build

import (
	"context"
	"errors"
	"fmt"
	"net"
	"regexp"
	"strings"

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
	f := &state.TaskFailure{Class: e.Class, Step: clipText(e.Step, 128), Retryable: e.Retryable(), Hints: e.Hints, Summary: e.Summary}
	if len(f.Hints) > 16 {
		f.Hints = f.Hints[:16]
	}
	return f
}

func fail(class state.FailureClass, step string, err error) *Error {
	return &Error{Class: class, Step: step, Err: err}
}

// hint is a known-error pattern (docs/04 §6, failure classification).
type hint struct {
	code    string
	re      *regexp.Regexp
	message string
}

var hints = []hint{
	{"pnpm-outdated-lockfile", regexp.MustCompile(`ERR_PNPM_OUTDATED_LOCKFILE`), "pnpm-lock.yaml is out of date with package.json. Run `pnpm install` locally and commit the lockfile."},
	{"npm-ci-lockfile", regexp.MustCompile("npm ci` can only install packages when your package.json and package-lock.json"), "package-lock.json is out of sync with package.json. Run `npm install` locally and commit the lockfile."},
	{"yarn-frozen-lockfile", regexp.MustCompile(`(?i)lockfile needs to be updated|YN0028`), "yarn.lock is out of date. Run `yarn install` locally and commit the lockfile."},
	{"node-gyp-toolchain", regexp.MustCompile(`(?i)gyp ERR!|node-gyp`), "A native module needs a build toolchain. Add build packages via RAILPACK_BUILD_APT_PACKAGES (e.g. \"python3 make g++\") or use a prebuilt alternative."},
	{"missing-module", regexp.MustCompile(`Cannot find module '([^']+)'`), "A module is missing at build or runtime. Check that it is a dependency (not only a devDependency for runtime code); for Next.js standalone, see outputFileTracingIncludes."},
	{"php-extension", regexp.MustCompile(`(?i)requires ext-([a-z0-9_]+)|PHP extension ([a-z0-9_]+) is missing`), "A PHP extension is missing. Add it to composer.json (\"ext-…\") or RAILPACK_PHP_EXTENSIONS."},
	{"django-improperly-configured", regexp.MustCompile(`ImproperlyConfigured`), "Django settings failed during the build (often collectstatic). Make sure settings import without runtime-only secrets, or mark the variable as build-time."},
	{"python-module-not-found", regexp.MustCompile(`ModuleNotFoundError: No module named '([^']+)'`), "A Python module is missing. Add it to requirements.txt/pyproject.toml."},
	{"out-of-memory", regexp.MustCompile(`(?i)JavaScript heap out of memory|exit code: 137|signal: killed`), "The build ran out of memory. Raise the build server's BuildKit memory or reduce build parallelism (e.g. NODE_OPTIONS=--max-old-space-size)."},
	{"no-start-command", regexp.MustCompile(`(?i)no start command`), "No start command was detected. Add a \"start\" script to package.json, a Procfile, or set a start command."},
	{"registry-rate-limit", regexp.MustCompile(`(?i)toomanyrequests|rate limit`), "An upstream registry or GitHub rate limit was hit. launchroom retries once; set GITHUB_TOKEN for builds if this keeps happening."},
	{"vite-env-undefined", regexp.MustCompile(`import\.meta\.env\.[A-Z_]+ is undefined`), "A Vite variable is undefined in the bundle. Mark it as a build-time variable (VITE_*)."},
}

// hintsFor matches log text against the known-error table.
func hintsFor(text string) []state.FailureHint {
	var out []state.FailureHint
	for _, h := range hints {
		if h.re.MatchString(text) {
			out = append(out, state.FailureHint{Code: state.Slug(h.code), Message: h.message})
		}
	}
	return out
}

var (
	reTransient = regexp.MustCompile(`(?i)(connection reset|connection refused|i/o timeout|TLS handshake timeout|no such host|temporary failure in name resolution|unexpected EOF|502 Bad Gateway|503 Service Unavailable|504 Gateway|toomanyrequests|failed to fetch anonymous token|failed to do request|context deadline exceeded while|server misbehaving|EAI_AGAIN|ETIMEDOUT|ECONNRESET)`)
	reExecFail  = regexp.MustCompile(`(?i)(process ".*" did not complete successfully|executor failed running|exit code: [0-9]+)`)
)

// classifySolve classifies a BuildKit solve error.
func classifySolve(err error, step string, logTail string) *Error {
	msg := err.Error()
	e := fail(state.FailureBuild, step, err)
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

func isNetErr(err error) bool {
	var ne net.Error
	var oe *net.OpError
	return errors.As(err, &ne) || errors.As(err, &oe)
}

func clipText(s string, n int) string {
	if len(s) <= n {
		return s
	}
	return s[:n]
}
