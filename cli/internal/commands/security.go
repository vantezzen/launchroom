package commands

import (
	"fmt"
	"io"
	"strings"

	"github.com/spf13/cobra"

	"github.com/vantezzen/launchroom/cli/internal/api"
	"github.com/vantezzen/launchroom/cli/internal/exitcode"
)

// securityCmd is `launchroom security` (docs/22 §11): open "Action
// needed" findings of the team, and `security inventory <query>`.
func (a *App) securityCmd() *cobra.Command {
	var failOn string
	cmd := &cobra.Command{
		Use:   "security",
		Short: "Show open findings that need action",
		Long:  "Lists the team's open \"Action needed\" findings. With --fail-on action-needed the command exits with code 12 when there are any (for CI).",
		Args:  cobra.NoArgs,
		RunE: func(cmd *cobra.Command, _ []string) error {
			if failOn != "" && failOn != "action-needed" {
				return exitcode.Usagef("--fail-on accepts action-needed")
			}
			s, err := a.session()
			if err != nil {
				return err
			}
			findings, err := s.Client.SecurityFindings(cmd.Context(), "action_needed")
			if err != nil {
				return err
			}
			if findings == nil {
				findings = []api.SecurityFinding{}
			}
			err = a.Printer.Result(map[string]any{"findings": findings}, func(w io.Writer) {
				p := a.Printer
				if len(findings) == 0 {
					_, _ = fmt.Fprintln(w, p.Good("No finding needs action."))
					return
				}
				rows := make([][]string, 0, len(findings))
				for _, f := range findings {
					fixed := "–"
					if f.FixedVersion != nil {
						fixed = *f.FixedVersion
					}
					severity := f.Severity
					if f.KnownExploited {
						severity += ", known exploited"
					}
					rows = append(rows, []string{
						f.Service.Name + " " + p.Dim(f.Service.ProjectName+"/"+f.Service.EnvironmentName),
						f.Package + " " + f.Version, fixed, p.Bad(severity), f.Advisory,
					})
				}
				p.Table(w, []string{"service", "package", "fixed in", "severity", "advisory"}, rows)
			})
			if err != nil {
				return err
			}
			if failOn == "action-needed" && len(findings) > 0 {
				return exitcode.New(exitcode.SecurityActionNeeded, fmt.Errorf("%d %s need action", len(findings), plural(len(findings), "finding", "findings")))
			}
			return nil
		},
	}
	cmd.Flags().StringVar(&failOn, "fail-on", "", `exit with code 12 when findings need action ("action-needed")`)
	cmd.AddCommand(a.securityInventoryCmd())
	return cmd
}

func (a *App) securityInventoryCmd() *cobra.Command {
	return &cobra.Command{
		Use:   "inventory <query>",
		Short: "Find which services run a package or runtime",
		Long:  "Searches the packages and runtimes of every live service. Words match package names, a version filters by prefix, and ecosystem:, service: and scope: filter, for example `launchroom security inventory lodash 4.17`.",
		Args:  cobra.MinimumNArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			s, err := a.session()
			if err != nil {
				return err
			}
			result, err := s.Client.SecurityInventory(cmd.Context(), strings.Join(args, " "))
			if err != nil {
				return err
			}
			return a.Printer.Result(result, func(w io.Writer) {
				p := a.Printer
				if len(result.Packages) == 0 {
					_, _ = fmt.Fprintf(w, "No service uses %s.\n", strings.Join(args, " "))
					return
				}
				rows := [][]string{}
				for _, pkg := range result.Packages {
					for _, use := range pkg.Versions {
						names := make([]string, 0, len(use.Services))
						for _, svc := range use.Services {
							names = append(names, svc.Name+" "+p.Dim(svc.ProjectName+"/"+svc.EnvironmentName))
						}
						advisories := make([]string, 0, len(use.Findings))
						for _, f := range use.Findings {
							advisories = append(advisories, f.Advisory)
						}
						rows = append(rows, []string{pkg.Name, use.Version, use.Scope, strings.Join(names, ", "), strings.Join(advisories, " ")})
					}
				}
				p.Table(w, []string{"package", "version", "scope", "services", "advisories"}, rows)
				if result.Truncated {
					_, _ = fmt.Fprintln(w, p.Dim("Only the first matches are shown; add a version or a filter."))
				}
			})
		},
	}
}
