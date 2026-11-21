package commands

import (
	"context"
	"errors"
	"fmt"
	"io"
	"strings"
	"time"
	"unicode/utf8"

	"github.com/spf13/cobra"

	"github.com/vantezzen/launchroom/cli/internal/api"
	"github.com/vantezzen/launchroom/cli/internal/exitcode"
	"github.com/vantezzen/launchroom/cli/internal/output"
)

// deploymentsCmd is `launchroom deployments [ls]` (D111).
func (a *App) deploymentsCmd() *cobra.Command {
	var all bool
	var limit int
	run := func(cmd *cobra.Command, _ []string) error {
		if limit < 1 || limit > 100 {
			return exitcode.Usagef("--limit must be between 1 and 100")
		}
		ctx := cmd.Context()
		s, err := a.session()
		if err != nil {
			return err
		}
		t, err := a.resolve(ctx, s, !all)
		if err != nil {
			return err
		}
		serviceID := ""
		if !all {
			serviceID = t.Service.ID
		}
		list, err := s.Client.Deployments(ctx, t.Environment.ID, serviceID, limit)
		if err != nil {
			return err
		}
		return a.Printer.Result(list, func(w io.Writer) {
			if len(list) == 0 {
				_, _ = fmt.Fprintln(w, "No deployments yet.")
				return
			}
			a.printDeploymentRows(w, list, all)
		})
	}
	cmd := &cobra.Command{
		Use:     "deployments",
		Aliases: []string{"deploys"},
		Short:   "List the linked service's deployments, newest first",
		Long:    "The linked service's deployments (or --service), newest first; --all lists every service of the environment.",
		Args:    cobra.NoArgs,
		RunE:    run,
	}
	ls := &cobra.Command{Use: "ls", Short: cmd.Short, Long: cmd.Long, Args: cobra.NoArgs, RunE: run}
	for _, c := range []*cobra.Command{cmd, ls} {
		c.Flags().BoolVar(&all, "all", false, "every service of the environment")
		c.Flags().IntVar(&limit, "limit", 20, "how many, at most 100")
	}
	cmd.AddCommand(ls)
	return cmd
}

// printDeploymentRows aligns deployments in columns. Widths come from the
// plain text, so colour never shifts a column.
func (a *App) printDeploymentRows(w io.Writer, list []api.Deployment, withService bool) {
	p := a.Printer
	now := time.Now()
	type row struct{ id, status, service, age, commit, trigger string }
	rows := make([]row, len(list))
	widths := make([]int, 4)
	for i, d := range list {
		commit := ""
		if d.Commit != nil {
			commit = d.Commit.ShortSha
			if d.Commit.Message != nil && *d.Commit.Message != "" {
				first, _, _ := strings.Cut(*d.Commit.Message, "\n")
				commit += " " + truncate(first, 48)
			}
		}
		rows[i] = row{d.ID, d.Status, d.ServiceName, ago(d.QueuedAt, now), commit, d.Trigger}
		for j, cell := range []string{rows[i].id, statusPlain(d.Status), rows[i].service, rows[i].age} {
			widths[j] = max(widths[j], utf8.RuneCountInString(cell))
		}
	}
	pad := func(s string, plain string, width int) string {
		return s + strings.Repeat(" ", width-utf8.RuneCountInString(plain)+2)
	}
	for _, r := range rows {
		line := pad(p.Dim(r.id), r.id, widths[0]) + pad(p.Status(r.status), statusPlain(r.status), widths[1])
		if withService {
			line += pad(r.service, r.service, widths[2])
		}
		line += pad(p.Dim(r.age), r.age, widths[3]) + r.commit
		if r.trigger != "" {
			line += "  " + p.Dim(strings.ReplaceAll(r.trigger, "_", " "))
		}
		_, _ = fmt.Fprintln(w, strings.TrimRight(line, " "))
	}
}

func truncate(s string, n int) string {
	if utf8.RuneCountInString(s) <= n {
		return s
	}
	r := []rune(s)
	return string(r[:n-1]) + "…"
}

// ago is a short relative time ("4m ago", "3d ago").
func ago(iso string, now time.Time) string {
	t, err := time.Parse(time.RFC3339, iso)
	if err != nil {
		return ""
	}
	d := now.Sub(t)
	switch {
	case d < time.Minute:
		return "just now"
	case d < time.Hour:
		return fmt.Sprintf("%dm ago", int(d.Minutes()))
	case d < 48*time.Hour:
		return fmt.Sprintf("%dh ago", int(d.Hours()))
	default:
		return fmt.Sprintf("%dd ago", int(d.Hours()/24))
	}
}

// deploymentArg returns the deployment named on the command line, or the
// one pick chooses among the linked service's newest deployments.
func (a *App) deploymentArg(ctx context.Context, s *session, args []string, pick func([]api.Deployment, *api.Service) (api.Deployment, error)) (api.Deployment, error) {
	if len(args) == 1 {
		if !strings.HasPrefix(args[0], "dpl_") {
			return api.Deployment{}, exitcode.Usagef("%q is not a deployment id (dpl_…)", args[0])
		}
		return s.Client.Deployment(ctx, args[0])
	}
	t, err := a.resolve(ctx, s, true)
	if err != nil {
		return api.Deployment{}, err
	}
	list, err := s.Client.Deployments(ctx, t.Environment.ID, t.Service.ID, 50)
	if err != nil {
		return api.Deployment{}, err
	}
	return pick(list, t.Service)
}

// rollbackTarget is the newest superseded deployment older than the live
// one: the version that ran before it.
func rollbackTarget(list []api.Deployment, svc *api.Service) (api.Deployment, error) {
	seenLive := svc.ActiveDeployment == nil
	for _, d := range list {
		if svc.ActiveDeployment != nil && d.ID == svc.ActiveDeployment.ID {
			seenLive = true
			continue
		}
		if seenLive && d.Status == api.DeploymentStatusSuperseded {
			return d, nil
		}
	}
	return api.Deployment{}, exitcode.New(exitcode.NotFound, fmt.Errorf("%s has no earlier version to roll back to (pass a deployment id from `launchroom deployments`)", svc.Name))
}

func (a *App) rollbackCmd() *cobra.Command {
	var yes, detach, resume bool
	cmd := &cobra.Command{
		Use:   "rollback [DEPLOYMENT]",
		Short: "Go back to an earlier version of the linked service",
		Long: "Starts the image of an earlier deployment again, without a rebuild (default: the version that ran before the live one).\n" +
			"Database migrations are not reverted. In production, new pushes don't go live until auto-deploy is resumed:\n" +
			"`launchroom rollback --resume` or Resume in the dashboard.",
		Args: cobra.MaximumNArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			ctx := cmd.Context()
			s, err := a.session()
			if err != nil {
				return err
			}
			if resume {
				if len(args) > 0 {
					return exitcode.Usagef("--resume takes no deployment")
				}
				return a.resumeAutoDeploy(ctx, s)
			}
			target, err := a.deploymentArg(ctx, s, args, rollbackTarget)
			if err != nil {
				return err
			}
			p := a.Printer
			question := fmt.Sprintf("Roll %s back to %s %s? Database migrations are not reverted.", target.ServiceName, target.ID, describeCommit(target))
			if !yes {
				if !p.TTY || p.JSON {
					return exitcode.Usagef("rolling back needs --yes without a terminal")
				}
				ok, err := a.confirm(question)
				if err != nil {
					return err
				}
				if !ok {
					return exitcode.Usagef("nothing was rolled back")
				}
			}
			d, err := s.Client.DeploymentAction(ctx, target.ID, "rollback")
			if err != nil {
				return err
			}
			if !p.JSON {
				p.Info("Rolling %s back to %s %s", p.Bold(d.ServiceName), target.ID, p.Dim(describeCommit(target)))
				p.Info(p.Dim("Database migrations are not reverted."))
			}
			if env, err := s.Client.Environment(ctx, d.EnvironmentID); err == nil && env.AutoDeployPaused && !p.JSON {
				p.Info(p.Dim(fmt.Sprintf("Auto-deploy is paused in %s: new pushes don't go live until you resume it with `launchroom rollback --resume`.", env.Name)))
			}
			return a.finishDeployments(ctx, s, []api.Deployment{d}, detach)
		},
	}
	f := cmd.Flags()
	f.BoolVarP(&yes, "yes", "y", false, "don't ask first")
	f.BoolVar(&detach, "detach", false, "don't wait for the rollback to finish")
	f.BoolVar(&resume, "resume", false, "resume auto-deploy after a rollback (new pushes go live again)")
	return cmd
}

func (a *App) resumeAutoDeploy(ctx context.Context, s *session) error {
	t, err := a.resolve(ctx, s, false)
	if err != nil {
		return err
	}
	env, err := s.Client.ResumeAutoDeploy(ctx, t.Environment.ID)
	if err != nil {
		return err
	}
	return a.Printer.Result(env, func(w io.Writer) {
		_, _ = fmt.Fprintf(w, "%s Auto-deploy resumed: new pushes go live in %s again.\n", a.Printer.Good("✓"), env.Name)
	})
}

func (a *App) cancelCmd() *cobra.Command {
	return &cobra.Command{
		Use:   "cancel [DEPLOYMENT]",
		Short: "Cancel a deployment that hasn't gone live (default: the linked service's latest)",
		Args:  cobra.MaximumNArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			ctx := cmd.Context()
			s, err := a.session()
			if err != nil {
				return err
			}
			d, err := a.deploymentArg(ctx, s, args, func(list []api.Deployment, svc *api.Service) (api.Deployment, error) {
				for _, d := range list {
					if !d.Finished || d.Status == api.DeploymentStatusReady {
						return d, nil
					}
				}
				return api.Deployment{}, exitcode.New(exitcode.NotFound, fmt.Errorf("%s has no deployment in progress", svc.Name))
			})
			if err != nil {
				return err
			}
			d, err = s.Client.DeploymentAction(ctx, d.ID, "cancel")
			if err != nil {
				return err
			}
			return a.Printer.Result(d, func(w io.Writer) {
				_, _ = fmt.Fprintf(w, "%s %s %s\n", d.ID, a.Printer.Status(d.Status), d.ServiceName)
			})
		},
	}
}

func (a *App) redeployCmd() *cobra.Command {
	var detach bool
	cmd := &cobra.Command{
		Use:   "redeploy [DEPLOYMENT]",
		Short: "Deploy a deployment's commit again (default: the live one)",
		Long:  "Builds and rolls out the commit of DEPLOYMENT again with the environment's current configuration (default: the linked service's live deployment).",
		Args:  cobra.MaximumNArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			ctx := cmd.Context()
			s, err := a.session()
			if err != nil {
				return err
			}
			from, err := a.deploymentArg(ctx, s, args, func(list []api.Deployment, svc *api.Service) (api.Deployment, error) {
				if svc.ActiveDeployment != nil {
					return *svc.ActiveDeployment, nil
				}
				if len(list) > 0 {
					return list[0], nil
				}
				return api.Deployment{}, exitcode.New(exitcode.NotFound, fmt.Errorf("%s has no deployment yet: run `launchroom deploy`", svc.Name))
			})
			if err != nil {
				return err
			}
			d, err := s.Client.DeploymentAction(ctx, from.ID, "redeploy")
			if err != nil {
				return err
			}
			if !a.Printer.JSON {
				a.Printer.Info("Redeploying %s %s", a.Printer.Bold(d.ServiceName), a.Printer.Dim(describeCommit(d)))
				a.Printer.Info(a.Printer.Dim(d.DashboardURL))
			}
			return a.finishDeployments(ctx, s, []api.Deployment{d}, detach)
		},
	}
	cmd.Flags().BoolVar(&detach, "detach", false, "don't wait for the deployment to finish")
	return cmd
}

func (a *App) fixCmd() *cobra.Command {
	var deployment string
	var port, seconds int
	var detach bool
	cmd := &cobra.Command{
		Use:   "fix [CODE]",
		Short: "Explain a failed deployment and apply the fix launchroom suggests",
		Long: "Without CODE: why the deployment failed and how to fix it (default: the linked service's latest deployment).\n" +
			"With CODE: applies that fix (it must be the one suggested), redeploys and follows the rollout.\n\n" +
			"  launchroom fix                                       what failed and the fixes\n" +
			"  launchroom fix use_tcp_healthcheck --deployment dpl_…  apply one\n\n" +
			"Applying a fix needs the \"Apply suggested fixes\" permission on the token, next to Deploy.",
		Args: cobra.MaximumNArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			ctx := cmd.Context()
			if port < 0 || port > 65535 || seconds < 0 || seconds > 3600 {
				return exitcode.Usagef("--port must be 1 to 65535 and --seconds 1 to 3600")
			}
			s, err := a.session()
			if err != nil {
				return err
			}
			var ids []string
			if deployment != "" {
				ids = []string{deployment}
			}
			d, err := a.deploymentArg(ctx, s, ids, func(list []api.Deployment, svc *api.Service) (api.Deployment, error) {
				if len(list) == 0 {
					return api.Deployment{}, exitcode.New(exitcode.NotFound, fmt.Errorf("%s has no deployment yet", svc.Name))
				}
				return list[0], nil
			})
			if err != nil {
				return err
			}
			p := a.Printer
			if d.Status != api.DeploymentStatusFailed {
				return p.Result(map[string]any{"deployment": d, "failureReport": nil}, func(w io.Writer) {
					_, _ = fmt.Fprintf(w, "%s %s is %s: nothing to fix.\n", d.ID, d.ServiceName, p.Status(d.Status))
				})
			}
			if len(args) == 0 {
				r, err := s.Client.DeploymentFailure(ctx, d.ID)
				if err != nil {
					return err
				}
				return p.Result(r, func(w io.Writer) { a.printFailure(w, d.ServiceName, r) })
			}
			body := api.FixRequest{}
			if port > 0 {
				body.Port = &port
			}
			if seconds > 0 {
				body.Seconds = &seconds
			}
			res, err := s.Client.ApplyFix(ctx, d.ID, args[0], body)
			if err != nil {
				return a.withFixHint(err, d)
			}
			if res.Deployment == nil {
				return p.Result(res, func(w io.Writer) {
					_, _ = fmt.Fprintf(w, "%s %s\n", p.Good("✓"), res.Message)
				})
			}
			if !p.JSON {
				p.Info("%s %s", p.Good("✓"), res.Message)
				p.Info(p.Dim(res.Deployment.DashboardURL))
			}
			return a.finishDeployments(ctx, s, []api.Deployment{*res.Deployment}, detach)
		},
	}
	f := cmd.Flags()
	f.StringVar(&deployment, "deployment", "", "the failed deployment (dpl_…; default: the linked service's latest)")
	f.IntVar(&port, "port", 0, "set_port: the port to use instead of the suggested one")
	f.IntVar(&seconds, "seconds", 0, "raise_health_timeout: the start period instead of the suggested one")
	f.BoolVar(&detach, "detach", false, "don't wait for the new deployment to finish")
	return cmd
}

// withFixHint says how to get a token that may apply fixes.
func (a *App) withFixHint(err error, d api.Deployment) error {
	var apiErr *api.Error
	if a.Printer.JSON || !errors.As(err, &apiErr) || apiErr.Ability != api.APIAbilityFix {
		return err
	}
	return fmt.Errorf("%w\n  Sign in again with `launchroom login` and allow \"Apply suggested fixes\", or use a token with it.\n  Or apply it in the dashboard: %s", err, d.DashboardURL)
}

// statusPlain is Printer.Status without colour, for column widths.
func statusPlain(status string) string { return (&output.Printer{}).Status(status) }
