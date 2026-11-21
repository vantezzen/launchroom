package commands

import (
	"context"
	"fmt"
	"io"
	"strings"
	"unicode"
	"unicode/utf8"

	"github.com/vantezzen/launchroom/cli/internal/api"
)

// deploymentResult is a finished deployment as `--json` prints it: the
// API's deployment, plus the failure report when it failed (D111).
type deploymentResult struct {
	api.Deployment
	FailureReport *api.FailureReport `json:"failureReport,omitempty"`
}

// failureReport fetches why d failed; nil when it didn't fail or the
// dashboard can't say (an older version, a missing permission): the
// caller falls back to the failure message.
func (a *App) failureReport(ctx context.Context, s *session, d api.Deployment) *api.FailureReport {
	if d.Status != api.DeploymentStatusFailed {
		return nil
	}
	r, err := s.Client.DeploymentFailure(ctx, d.ID)
	if err != nil {
		return nil
	}
	return &r
}

// failureVerb is the outcome before the explanation (failure-card.tsx).
func failureVerb(class string) string {
	switch class {
	case api.DeploymentFailureClassPlanError, api.DeploymentFailureClassBuildError, api.DeploymentFailureClassBuildTimeout:
		return "Build failed"
	case api.DeploymentFailureClassReleaseError:
		return "Release failed"
	case api.DeploymentFailureClassHealthTimeout, api.DeploymentFailureClassPortMismatch:
		return "Health check failed"
	case api.DeploymentFailureClassRuntimeError:
		return "Start failed"
	default:
		return "Deploy failed"
	}
}

// lowerFirst lowercases a leading ordinary word ("Your app" → "your app");
// names such as BuildKit or GitHub keep their case.
func lowerFirst(s string) string {
	word, _, _ := strings.Cut(s, " ")
	r, size := utf8.DecodeRuneInString(word)
	if size == 0 || !unicode.IsUpper(r) {
		return s
	}
	for _, rest := range word[size:] {
		if unicode.IsUpper(rest) || unicode.IsDigit(rest) {
			return s
		}
	}
	return string(unicode.ToLower(r)) + s[size:]
}

// failureHeadline is the block's first line.
func failureHeadline(r api.FailureReport) string {
	if r.Explanation != nil {
		return failureVerb(r.Class) + ": " + lowerFirst(r.Explanation.Title)
	}
	return r.Title
}

// printFailure writes a failed deployment's explanation: what failed, why,
// the failing step's last lines, the fixes with the command for each and
// where to read more. Colour only adds to the marks and words.
func (a *App) printFailure(w io.Writer, serviceName string, r api.FailureReport) {
	p := a.Printer
	_, _ = fmt.Fprintf(w, "\n%s %s %s\n", p.Bad("✕"), p.Bold(failureHeadline(r)), p.Dim("("+serviceName+")"))
	indent := func(s string) { _, _ = fmt.Fprintf(w, "  %s\n", s) }
	if r.ServingCommit != nil && *r.ServingCommit != "" {
		indent(fmt.Sprintf("The site is still serving %s.", shortSha(*r.ServingCommit)))
	}
	if e := r.Explanation; e != nil {
		indent(e.Cause)
		if e.Answer != nil && *e.Answer != "" {
			indent("The app answered:")
			for _, line := range strings.Split(strings.TrimRight(*e.Answer, "\n"), "\n") {
				indent("  " + p.Dim(line))
			}
		}
		indent(e.Fix)
	} else {
		if r.Message != nil && *r.Message != "" {
			first, _, _ := strings.Cut(*r.Message, "\n")
			indent(first)
		}
		indent(r.Hint)
		for _, h := range r.Hints {
			indent(p.Dim("Known problem: ") + h.Message)
		}
	}
	if len(r.Excerpt) > 0 {
		step := "failing step"
		if r.FailingStep != nil {
			step = *r.FailingStep + " step"
		}
		_, _ = fmt.Fprintln(w)
		indent(p.Dim("Last lines of the " + step))
		width := len(fmt.Sprint(r.Excerpt[len(r.Excerpt)-1].Number))
		prev := 0
		for _, l := range r.Excerpt {
			if prev != 0 && l.Number > prev+1 {
				indent(p.Dim(fmt.Sprintf("%*s", width, "…")))
			}
			prev = l.Number
			mark, text := " ", l.Text
			if l.Error {
				mark, text = p.Bad("›"), p.Bold(text)
			}
			indent(fmt.Sprintf("%s %s %s", p.Dim(fmt.Sprintf("%*d", width, l.Number)), mark, text))
		}
	}
	_, _ = fmt.Fprintln(w)
	for _, f := range r.Fixes {
		_, _ = fmt.Fprintf(w, "  %s %s\n", p.Dim("Fix  "), f.Label)
		switch {
		case f.Command != nil && *f.Command != "":
			_, _ = fmt.Fprintf(w, "        %s\n", *f.Command)
		default:
			_, _ = fmt.Fprintf(w, "        %s\n", p.Dim("in the dashboard: "+f.DashboardURL))
		}
	}
	_, _ = fmt.Fprintf(w, "  %s %s\n", p.Dim("More "), r.DashboardURL)
}

func shortSha(sha string) string {
	if len(sha) > 7 {
		return sha[:7]
	}
	return sha
}
