package commands

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/url"
	"regexp"
	"strings"
	"time"

	"github.com/spf13/cobra"

	"github.com/vantezzen/launchroom/cli/internal/api"
	"github.com/vantezzen/launchroom/cli/internal/exitcode"
	"github.com/vantezzen/launchroom/cli/internal/sse"
)

var shaRe = regexp.MustCompile(`^[0-9a-f]{40}$`)

func (a *App) deployCmd() *cobra.Command {
	var ref, sha, message string
	var redeploy, staged, detach bool
	cmd := &cobra.Command{
		Use:   "deploy",
		Short: "Deploy the linked service (the latest commit of its branch by default)",
		Long: "Deploys a git ref of the linked service with the environment's last deployed configuration, then follows the build until it is live or failed.\n\n" +
			"  launchroom deploy                 latest commit of the tracked branch\n" +
			"  launchroom deploy --ref v1.4.0    a branch or tag\n" +
			"  launchroom deploy --sha <40 hex>  one commit\n" +
			"  launchroom deploy --redeploy      the current commit again\n" +
			"  launchroom deploy --staged        apply the environment's staged changes\n\n" +
			"Local files are not uploaded: launchroom builds from your repository.",
		Args: cobra.NoArgs,
		RunE: func(cmd *cobra.Command, _ []string) error {
			ctx := cmd.Context()
			modes := 0
			for _, on := range []bool{ref != "", sha != "", redeploy, staged} {
				if on {
					modes++
				}
			}
			if modes > 1 && (ref == "" || sha == "" || redeploy || staged) {
				return exitcode.Usagef("--ref, --sha, --redeploy and --staged don't combine (only --ref with --sha)")
			}
			if sha != "" && !shaRe.MatchString(sha) {
				return exitcode.Usagef("--sha must be a full commit SHA (40 lowercase hex characters)")
			}
			if message != "" && !staged {
				return exitcode.Usagef("--message only applies to --staged")
			}
			s, err := a.session()
			if err != nil {
				return err
			}
			t, err := a.resolve(ctx, s, !staged)
			if err != nil {
				return err
			}
			p := a.Printer
			var deployments []api.Deployment
			if staged {
				res, err := s.Client.DeployStaged(ctx, t.Environment.ID, message)
				if err != nil {
					return err
				}
				deployments = res.Deployments
				if !p.JSON {
					p.Info("Revision %d of %s: deploying %d %s.", res.Revision, t.Environment.Name, len(deployments), plural(len(deployments), "service", "services"))
				}
			} else {
				d, err := s.Client.CreateDeployment(ctx, t.Environment.ID, api.DeployRequest{Service: t.Service.ID, Ref: ref, Sha: sha, Redeploy: redeploy})
				if err != nil {
					return err
				}
				deployments = []api.Deployment{d}
				if !p.JSON {
					p.Info("Deploying %s to %s %s", p.Bold(t.Service.Name), t.Environment.Name, p.Dim(describeCommit(d)))
					p.Info(p.Dim(d.DashboardURL))
				}
			}
			if detach || len(deployments) == 0 {
				return p.Result(deployments, func(w io.Writer) {
					for _, d := range deployments {
						_, _ = fmt.Fprintf(w, "%s %s %s\n", d.ID, p.Status(d.Status), d.ServiceName)
					}
				})
			}
			var final []api.Deployment
			if len(deployments) == 1 {
				d, err := a.followDeployment(ctx, s, deployments[0])
				if err != nil {
					return err
				}
				final = []api.Deployment{d}
			} else {
				if final, err = a.waitAll(ctx, s, deployments); err != nil {
					return err
				}
			}
			var failed []string
			for _, d := range final {
				if d.Failed() {
					msg := d.ServiceName + " " + d.Status
					if d.Failure != nil && d.Failure.Message != "" {
						msg += ": " + d.Failure.Message
					}
					failed = append(failed, msg)
				}
			}
			if err := p.Result(final, func(w io.Writer) {
				for _, d := range final {
					if !d.Failed() {
						_, _ = fmt.Fprintf(w, "%s %s is %s\n", p.Good("✓"), d.ServiceName, statusWord(d.Status))
					}
				}
			}); err != nil {
				return err
			}
			if failed != nil {
				return exitcode.New(exitcode.DeploymentFailed, errors.New(strings.Join(failed, "\n")))
			}
			return nil
		},
	}
	f := cmd.Flags()
	f.StringVar(&ref, "ref", "", "branch or tag to deploy")
	f.StringVar(&sha, "sha", "", "commit to deploy (40 hex)")
	f.BoolVar(&redeploy, "redeploy", false, "deploy the current commit again")
	f.BoolVar(&staged, "staged", false, "apply the environment's staged changes")
	f.StringVar(&message, "message", "", "revision message (with --staged)")
	f.BoolVar(&detach, "detach", false, "don't wait for the deployment to finish")
	return cmd
}

func plural(n int, one, many string) string {
	if n == 1 {
		return one
	}
	return many
}

func statusWord(s string) string {
	if s == "active" {
		return "live"
	}
	return strings.ReplaceAll(s, "_", " ")
}

func describeCommit(d api.Deployment) string {
	if d.Commit == nil {
		return ""
	}
	out := d.Commit.ShortSha
	if d.Commit.Ref != nil && *d.Commit.Ref != "" {
		out = strings.TrimPrefix(strings.TrimPrefix(*d.Commit.Ref, "refs/heads/"), "refs/tags/") + " @ " + out
	}
	if d.Commit.Message != nil && *d.Commit.Message != "" {
		first, _, _ := strings.Cut(*d.Commit.Message, "\n")
		out += " " + first
	}
	return "(" + out + ")"
}

type linesEvent struct {
	Lines   []api.LogLine  `json:"lines"`
	Entries []api.LogEntry `json:"entries"`
	Cursor  int            `json:"cursor"`
}

type statusEvent struct {
	Status   string `json:"status"`
	Finished bool   `json:"finished"`
}

// followDeployment streams a deployment's log lines (not in --json mode)
// until it finishes and returns its final state.
func (a *App) followDeployment(ctx context.Context, s *session, d api.Deployment) (api.Deployment, error) {
	if d.Finished {
		return d, nil
	}
	p := a.Printer
	last := d.Status
	err := s.Client.Stream(ctx, "/deployments/"+url.PathEscape(d.ID)+"/logs/stream", nil, "", func(ev sse.Event) error {
		switch ev.Event {
		case "lines":
			var le linesEvent
			if json.Unmarshal([]byte(ev.Data), &le) != nil || p.JSON {
				return nil
			}
			for _, l := range le.Lines {
				a.printLogLine(l)
			}
		case "status":
			var st statusEvent
			if json.Unmarshal([]byte(ev.Data), &st) == nil {
				if st.Status != last && !p.JSON {
					p.Info("%s %s", p.Status(st.Status), p.Dim(d.ServiceName))
				}
				last = st.Status
				if st.Finished {
					return api.ErrStopStream
				}
			}
		}
		return nil
	})
	if err != nil {
		var apiErr *api.Error
		// Older dashboards without the stream: poll instead.
		if !errors.As(err, &apiErr) || (apiErr.Status != 404 && apiErr.Status != 405 && apiErr.Code != "bad_response") {
			return d, err
		}
		final, err := a.waitAll(ctx, s, []api.Deployment{d})
		if err != nil {
			return d, err
		}
		return final[0], nil
	}
	final, err := s.Client.Deployment(ctx, d.ID)
	if err != nil {
		return d, err
	}
	if !final.Finished {
		res, err := a.waitAll(ctx, s, []api.Deployment{final})
		if err != nil {
			return final, err
		}
		return res[0], nil
	}
	return final, nil
}

// waitAll polls deployments until every one finished, printing changes.
func (a *App) waitAll(ctx context.Context, s *session, deployments []api.Deployment) ([]api.Deployment, error) {
	current := append([]api.Deployment(nil), deployments...)
	for {
		done := true
		for i, d := range current {
			if d.Finished {
				continue
			}
			next, err := s.Client.Deployment(ctx, d.ID)
			if err != nil {
				return current, err
			}
			if next.Status != d.Status && !a.Printer.JSON {
				a.Printer.Info("%s %s", a.Printer.Status(next.Status), a.Printer.Dim(next.ServiceName))
			}
			current[i] = next
			if !next.Finished {
				done = false
			}
		}
		if done {
			return current, nil
		}
		if err := s.Client.Sleep(ctx, 2*time.Second); err != nil {
			return current, err
		}
	}
}

func (a *App) printLogLine(l api.LogLine) {
	text := l.Text
	if l.Stream == "stderr" {
		text = a.Printer.Warn(text)
	}
	_, _ = fmt.Fprintf(a.Printer.Out, "%s %s\n", a.Printer.Dim(fmt.Sprintf("%-8s", l.Section)), text)
}
