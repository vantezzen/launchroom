package commands

import (
	"fmt"
	"io"

	"github.com/spf13/cobra"

	"github.com/vantezzen/launchroom/cli/internal/api"
	"github.com/vantezzen/launchroom/cli/internal/exitcode"
)

// serviceCmd is `launchroom service deploy|restart|stop|start` (D68): the
// service panel header's actions for one service in one environment.
func (a *App) serviceCmd() *cobra.Command {
	cmd := &cobra.Command{
		Use:   "service",
		Short: "Deploy, restart, stop or start a service",
		Long: "Acts on one service in one environment: the linked one, or the one named as the argument (id, slug or name) with --environment.\n\n" +
			"  launchroom service deploy web    the latest version (latest commit of the tracked branch, or the image tag again)\n" +
			"  launchroom service restart web   new containers of the running version\n" +
			"  launchroom service stop web      stop its containers; settings, domains, volumes and deployments stay\n" +
			"  launchroom service start web     run the stopped version again\n\n" +
			"Each needs the deploy permission in that environment.",
		Args: cobra.NoArgs,
	}
	cmd.AddCommand(a.serviceDeployCmd(), a.serviceRestartCmd(), a.serviceStopCmd(), a.serviceStartCmd())
	return cmd
}

// serviceArg makes the optional positional service win over --service and the link.
func (a *App) serviceArg(args []string) {
	if len(args) == 1 {
		a.flags.service = args[0]
	}
}

func (a *App) serviceDeployCmd() *cobra.Command {
	var detach bool
	cmd := &cobra.Command{
		Use:   "deploy [service]",
		Short: "Deploy the latest version of a service",
		Long:  "Builds and deploys the latest commit of the branch the service tracks, or runs what an image service's tag points to now, then follows it until it is live or failed. With staged changes, use `launchroom deploy --staged` instead.",
		Args:  cobra.MaximumNArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			ctx := cmd.Context()
			a.serviceArg(args)
			s, err := a.session()
			if err != nil {
				return err
			}
			t, err := a.resolve(ctx, s, true)
			if err != nil {
				return err
			}
			d, err := s.Client.DeployService(ctx, t.Environment.ID, t.Service.ID)
			if err != nil {
				return err
			}
			if p := a.Printer; !p.JSON {
				p.Info("Deploying %s to %s %s", p.Bold(t.Service.Name), t.Environment.Name, p.Dim(describeCommit(d)))
				p.Info(p.Dim(d.DashboardURL))
			}
			return a.finishDeployments(ctx, s, []api.Deployment{d}, detach)
		},
	}
	cmd.Flags().BoolVar(&detach, "detach", false, "don't wait for the deployment to finish")
	return cmd
}

func (a *App) serviceRestartCmd() *cobra.Command {
	var detach, without bool
	cmd := &cobra.Command{
		Use:   "restart [service]",
		Short: "Restart a running service",
		Long:  "Starts new containers of the running version (same image, configuration and variables, no build), one replica at a time when there are several, then follows the rollout. An app's workers and crons restart with it unless you pass --without-processes.",
		Args:  cobra.MaximumNArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			return a.runServiceAction(cmd, args, "restart", !without, detach)
		},
	}
	cmd.Flags().BoolVar(&detach, "detach", false, "don't wait for the restart to finish")
	cmd.Flags().BoolVar(&without, "without-processes", false, "leave the app's workers and crons as they are")
	return cmd
}

func (a *App) serviceStartCmd() *cobra.Command {
	var detach, without bool
	cmd := &cobra.Command{
		Use:   "start [service]",
		Short: "Start a stopped service",
		Long:  "Runs the version that was live when the service stopped, with the configuration deployed last, then follows the rollout. An app's stopped workers and crons start with it unless you pass --without-processes.",
		Args:  cobra.MaximumNArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			return a.runServiceAction(cmd, args, "start", !without, detach)
		},
	}
	cmd.Flags().BoolVar(&detach, "detach", false, "don't wait for the service to run")
	cmd.Flags().BoolVar(&without, "without-processes", false, "leave the app's workers and crons stopped")
	return cmd
}

func (a *App) serviceStopCmd() *cobra.Command {
	var yes, without bool
	cmd := &cobra.Command{
		Use:   "stop [service]",
		Short: "Stop a service",
		Long: "Stops the service's containers. Its domains show a stopped page; settings, domains, volumes and deployments stay, and unfinished deployments are cancelled. " +
			"Asks first on a terminal; pass --yes in scripts. An app's workers and crons stop with it unless you pass --without-processes.",
		Args: cobra.MaximumNArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			ctx := cmd.Context()
			a.serviceArg(args)
			s, err := a.session()
			if err != nil {
				return err
			}
			t, err := a.resolve(ctx, s, true)
			if err != nil {
				return err
			}
			p := a.Printer
			if !yes {
				if !p.TTY || p.JSON {
					return exitcode.Usagef("stopping %s needs a confirmation: pass --yes (there is no terminal to ask on)", t.Service.Name)
				}
				ok, err := a.confirm(fmt.Sprintf("Stop %s in %s? Its domains show a stopped page until it starts again.", p.Bold(t.Service.Name), t.Environment.Name))
				if err != nil {
					return err
				}
				if !ok {
					return exitcode.Usagef("nothing was stopped")
				}
			}
			res, err := s.Client.ServiceAction(ctx, t.Environment.ID, t.Service.ID, "stop", !without)
			if err != nil {
				return err
			}
			return p.Result(res.Service, func(w io.Writer) {
				_, _ = fmt.Fprintf(w, "%s %s is stopped in %s. Start it with `launchroom service start %s`.\n", p.Good("✓"), res.Service.Name, t.Environment.Name, res.Service.Slug)
			})
		},
	}
	cmd.Flags().BoolVar(&yes, "yes", false, "don't ask before stopping")
	cmd.Flags().BoolVar(&without, "without-processes", false, "leave the app's workers and crons running")
	return cmd
}

// runServiceAction is restart and start: one deployment per service,
// followed like `launchroom deploy`.
func (a *App) runServiceAction(cmd *cobra.Command, args []string, action string, processes, detach bool) error {
	ctx := cmd.Context()
	a.serviceArg(args)
	s, err := a.session()
	if err != nil {
		return err
	}
	t, err := a.resolve(ctx, s, true)
	if err != nil {
		return err
	}
	res, err := s.Client.ServiceAction(ctx, t.Environment.ID, t.Service.ID, action, processes)
	if err != nil {
		return err
	}
	if p := a.Printer; !p.JSON {
		verb := "Restarting"
		if action == "start" {
			verb = "Starting"
		}
		p.Info("%s %s in %s", verb, p.Bold(t.Service.Name), t.Environment.Name)
	}
	if res.Deployments == nil {
		res.Deployments = []api.Deployment{}
	}
	if len(res.Deployments) == 0 && !a.Printer.JSON {
		a.Printer.Info("%s has never been deployed here: it runs with its first deploy.", t.Service.Name)
	}
	return a.finishDeployments(ctx, s, res.Deployments, detach)
}
