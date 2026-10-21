package commands

import (
	"fmt"
	"io"
	"strings"

	"github.com/spf13/cobra"

	"github.com/vantezzen/launchroom/cli/internal/api"
	"github.com/vantezzen/launchroom/cli/internal/exitcode"
	"github.com/vantezzen/launchroom/cli/internal/link"
)

func (a *App) linkCmd() *cobra.Command {
	return &cobra.Command{
		Use:   "link",
		Short: "Link this directory to a project and environment",
		Long:  "Writes .launchroom/project.json (ids only, safe to commit). Pickers appear on a terminal; otherwise pass --project and --environment (and optionally --service).",
		Args:  cobra.NoArgs,
		RunE: func(cmd *cobra.Command, _ []string) error {
			ctx := cmd.Context()
			s, err := a.session()
			if err != nil {
				return err
			}
			var project api.Project
			if a.flags.project != "" {
				if project, err = a.findProject(ctx, s, a.flags.project); err != nil {
					return err
				}
			} else {
				projects, err := s.Client.Projects(ctx)
				if err != nil {
					return err
				}
				if len(projects) == 0 {
					return exitcode.New(exitcode.NotFound, fmt.Errorf("this team has no projects yet: create one in the dashboard (%s)", s.URL))
				}
				labels := make([]string, len(projects))
				for i, p := range projects {
					labels[i] = p.Name
				}
				i, err := a.pick("project", labels)
				if err != nil {
					return err
				}
				project = projects[i]
			}
			envs, err := s.Client.Environments(ctx, project.ID)
			if err != nil {
				return err
			}
			var env api.Environment
			if a.flags.environment != "" {
				if env, err = a.findEnvironment(ctx, s, project, a.flags.environment); err != nil {
					return err
				}
			} else {
				persistent := make([]api.Environment, 0, len(envs))
				for _, e := range envs {
					if e.Kind != "preview" {
						persistent = append(persistent, e)
					}
				}
				if len(persistent) == 0 {
					return exitcode.New(exitcode.NotFound, fmt.Errorf("%s has no environments", project.Name))
				}
				labels := make([]string, len(persistent))
				for i, e := range persistent {
					labels[i] = e.Name + " (" + e.Kind + ")"
				}
				i, err := a.pick("environment", labels)
				if err != nil {
					return err
				}
				env = persistent[i]
			}
			l := link.Link{URL: s.URL, ProjectID: project.ID, EnvironmentID: env.ID}
			var svcName string
			if a.flags.service != "" {
				svc, err := a.findService(ctx, s, env, a.flags.service)
				if err != nil {
					return err
				}
				l.ServiceID, svcName = svc.ID, svc.Name
			} else if services, err := s.Client.Services(ctx, env.ID); err == nil && len(services) == 1 {
				l.ServiceID, svcName = services[0].ID, services[0].Name
			}
			dir, err := a.Getwd()
			if err != nil {
				return err
			}
			path, err := link.Write(dir, l)
			if err != nil {
				return err
			}
			return a.Printer.Result(struct {
				Path string `json:"path"`
				link.Link
			}{path, l}, func(w io.Writer) {
				what := project.Name + " / " + env.Name
				if svcName != "" {
					what += " / " + svcName
				}
				_, _ = fmt.Fprintf(w, "%s Linked to %s (%s).\n", a.Printer.Good("✓"), what, path)
			})
		},
	}
}

func (a *App) statusCmd() *cobra.Command {
	return &cobra.Command{
		Use:   "status",
		Short: "Show the services of the linked environment",
		Args:  cobra.NoArgs,
		RunE: func(cmd *cobra.Command, _ []string) error {
			ctx := cmd.Context()
			s, err := a.session()
			if err != nil {
				return err
			}
			t, err := a.resolve(ctx, s, false)
			if err != nil {
				return err
			}
			services, err := s.Client.Services(ctx, t.Environment.ID)
			if err != nil {
				return err
			}
			if t.Service != nil && a.flags.service != "" {
				services = []api.Service{*t.Service}
			}
			out := struct {
				Environment api.Environment `json:"environment"`
				Services    []api.Service   `json:"services"`
			}{t.Environment, services}
			return a.Printer.Result(out, func(w io.Writer) {
				p := a.Printer
				_, _ = fmt.Fprintf(w, "%s %s\n\n", p.Bold(t.Environment.Name), p.Dim(t.Environment.DashboardURL))
				rows := make([][]string, 0, len(services))
				for _, svc := range services {
					dep := "–"
					if d := svc.LatestDeployment; d != nil {
						dep = d.ID
						if d.Commit != nil {
							dep = d.Commit.ShortSha
						}
					}
					rows = append(rows, []string{svc.Name, p.Status(svc.Status), dep, strings.Join(svc.URLs, " ")})
				}
				p.Table(w, []string{"service", "status", "deployment", "url"}, rows)
			})
		},
	}
}

func (a *App) openCmd() *cobra.Command {
	return &cobra.Command{
		Use:   "open",
		Short: "Open the linked environment (or --service) in the dashboard",
		Args:  cobra.NoArgs,
		RunE: func(cmd *cobra.Command, _ []string) error {
			ctx := cmd.Context()
			s, err := a.session()
			if err != nil {
				return err
			}
			t, err := a.resolve(ctx, s, false)
			if err != nil {
				return err
			}
			u := t.Environment.DashboardURL
			if t.Service != nil && a.flags.service != "" && t.Service.DashboardURL != "" {
				u = t.Service.DashboardURL
			}
			opened := false
			if a.OpenBrowser != nil && !a.Printer.JSON {
				opened = a.OpenBrowser(u) == nil
			}
			return a.Printer.Result(map[string]any{"url": u}, func(w io.Writer) {
				if !opened {
					_, _ = fmt.Fprintln(w, u)
				}
			})
		},
	}
}
