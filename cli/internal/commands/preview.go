package commands

import (
	"context"
	"errors"
	"fmt"
	"io"
	"strings"
	"time"

	"github.com/spf13/cobra"

	"github.com/vantezzen/launchroom/cli/internal/api"
	"github.com/vantezzen/launchroom/cli/internal/exitcode"
)

// previewPoll is how often `preview wait` asks again.
const previewPoll = 5 * time.Second

func (a *App) previewCmd() *cobra.Command {
	cmd := &cobra.Command{
		Use:   "preview",
		Short: "Pull request previews of the linked project",
	}
	cmd.AddCommand(a.previewWaitCmd())
	return cmd
}

func (a *App) previewWaitCmd() *cobra.Command {
	var pr int
	var sha string
	var timeout time.Duration
	cmd := &cobra.Command{
		Use:   "wait --pr N",
		Short: "Wait until a pull request's preview is live or failed, then print its URLs",
		Long: "Waits until every service of the preview of pull request N is live (exit 0, its URLs on stdout) or one failed\n" +
			"(exit 10, with why). --sha waits for that commit (default: the preview's head commit). In GitHub Actions, pass\n" +
			"${{ github.event.pull_request.head.sha }}: GITHUB_SHA is the merge commit in pull_request events.\n\n" +
			"Exits 14 when --timeout passes first, 6 when the preview was skipped or closed. --json prints the preview.",
		Args: cobra.NoArgs,
		RunE: func(cmd *cobra.Command, _ []string) error {
			if pr < 1 {
				return exitcode.Usagef("--pr is the pull request number")
			}
			if sha != "" && !shaRe.MatchString(sha) {
				return exitcode.Usagef("--sha must be a full commit SHA (40 lowercase hex characters)")
			}
			if timeout <= 0 {
				return exitcode.Usagef("--timeout must be positive")
			}
			ctx := cmd.Context()
			s, err := a.session()
			if err != nil {
				return err
			}
			t, err := a.resolve(ctx, s, false)
			if err != nil {
				return err
			}
			return a.waitForPreview(ctx, s, t.Environment.ProjectID, pr, sha, time.Now().Add(timeout))
		},
	}
	f := cmd.Flags()
	f.IntVar(&pr, "pr", 0, "the pull or merge request number")
	f.StringVar(&sha, "sha", "", "wait for this commit (40 hex; default: the preview's head)")
	f.DurationVar(&timeout, "timeout", 20*time.Minute, "give up after this long (exit 14)")
	return cmd
}

// previewSettled reports whether every deployment finished building sha
// (deployments without a commit, such as databases, only need to finish).
func previewSettled(p api.Preview, sha string) bool {
	if p.HeadSha != sha || len(p.Deployments) == 0 {
		return false
	}
	for _, d := range p.Deployments {
		if !d.Finished || (d.Commit != nil && d.Commit.Sha != sha) {
			return false
		}
	}
	return true
}

// previewState is the one line printed whenever it changes.
func previewState(p api.Preview, sha string) string {
	if p.HeadSha != sha {
		return fmt.Sprintf("Waiting for the preview to pick up %s (it is at %s)", shortSha(sha), shortSha(p.HeadSha))
	}
	for _, d := range p.Deployments {
		if d.WaitingForChecks() {
			return checksLine(d.Checks)
		}
	}
	building := 0
	for _, d := range p.Deployments {
		if !d.Finished {
			building++
		}
	}
	switch {
	case p.Status == api.PreviewStatusAwaitingApproval:
		return "Waiting for a maintainer to approve the preview (a pull request from a fork)"
	case building > 0:
		return fmt.Sprintf("Deploying %s: %d of %d %s in progress", shortSha(sha), building, len(p.Deployments), plural(len(p.Deployments), "service", "services"))
	default:
		return "Preview " + strings.ReplaceAll(p.Status, "_", " ")
	}
}

func (a *App) waitForPreview(ctx context.Context, s *session, projectID string, pr int, sha string, deadline time.Time) error {
	p := a.Printer
	said := ""
	say := func(line string) {
		if line != said && !p.JSON {
			p.Info("%s %s", p.Dim(fmt.Sprintf("#%d", pr)), line)
		}
		said = line
	}
	for {
		list, err := s.Client.Previews(ctx, projectID, pr)
		if err != nil {
			return err
		}
		if len(list) == 0 {
			say("No preview yet")
		} else {
			pv := list[0]
			want := sha
			if want == "" {
				want = pv.HeadSha
			}
			switch pv.Status {
			case api.PreviewStatusSkipped, api.PreviewStatusClosed, api.PreviewStatusExpired:
				reason := "the preview is " + pv.Status
				if pv.StatusReason != nil && *pv.StatusReason != "" {
					reason += ": " + *pv.StatusReason
				}
				_ = p.Result(pv, nil)
				return exitcode.New(exitcode.Refused, errors.New(reason))
			case api.PreviewStatusFailed:
				if pv.HeadSha == want && !hasFailedDeployment(pv) {
					_ = p.Result(pv, nil)
					return exitcode.New(exitcode.DeploymentFailed, fmt.Errorf("the preview couldn't start: %s", deref(pv.StatusReason)))
				}
			}
			if previewSettled(pv, want) {
				return a.previewOutcome(ctx, s, pv)
			}
			say(previewState(pv, want))
		}
		if !time.Now().Before(deadline) {
			return exitcode.New(exitcode.WaitTimedOut, fmt.Errorf("the preview of #%d didn't settle in time", pr))
		}
		if err := s.Client.Sleep(ctx, previewPoll); err != nil {
			return err
		}
	}
}

func hasFailedDeployment(p api.Preview) bool {
	for _, d := range p.Deployments {
		if d.Failed() {
			return true
		}
	}
	return false
}

// previewOutcome prints a settled preview: its URLs when every service is
// live, else why the failed ones failed (exit 10).
func (a *App) previewOutcome(ctx context.Context, s *session, pv api.Preview) error {
	p := a.Printer
	var failed []string
	for _, d := range pv.Deployments {
		if !d.Failed() {
			continue
		}
		failed = append(failed, d.ServiceName)
		if r := a.failureReport(ctx, s, d); r != nil && !p.JSON {
			a.printFailure(p.Err, d.ServiceName, *r)
		}
	}
	if err := p.Result(pv, func(w io.Writer) {
		if failed != nil {
			return
		}
		_, _ = fmt.Fprintf(p.Err, "%s Preview of #%d is live\n", p.Good("✓"), pv.Number)
		for _, u := range pv.URLs {
			_, _ = fmt.Fprintf(w, "%s  %s\n", u.URL, p.Dim(u.Service))
		}
	}); err != nil {
		return err
	}
	if failed != nil {
		return exitcode.New(exitcode.DeploymentFailed, fmt.Errorf("the preview of #%d didn't go live: %s failed", pv.Number, strings.Join(failed, ", ")))
	}
	return nil
}
