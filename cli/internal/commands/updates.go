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

// updatePoll is how often `updates apply --wait` asks again.
const updatePoll = 5 * time.Second

// updatesCmd is `launchroom updates` (D106): newer releases of image
// services, and updating one the way the dashboard does.
func (a *App) updatesCmd() *cobra.Command {
	cmd := &cobra.Command{
		Use:   "updates",
		Short: "Newer releases of your image services, and updating to one",
		Long: "launchroom checks image services (apps from a template or an image) for newer releases.\n\n" +
			"  launchroom updates                    every service with an update, in the team\n" +
			"  launchroom updates --project shop     one project's\n" +
			"  launchroom updates apply listmonk     update to the recommended version\n\n" +
			"Services built from a repository update with a deploy, so they never show here.",
		Args: cobra.NoArgs,
		RunE: func(cmd *cobra.Command, _ []string) error { return a.listUpdates(cmd.Context()) },
	}
	cmd.AddCommand(&cobra.Command{
		Use:     "ls",
		Aliases: []string{"list"},
		Short:   "List services with a newer release or an update in flight",
		Args:    cobra.NoArgs,
		RunE:    func(cmd *cobra.Command, _ []string) error { return a.listUpdates(cmd.Context()) },
	}, a.updatesApplyCmd())
	return cmd
}

func (a *App) listUpdates(ctx context.Context) error {
	s, err := a.session()
	if err != nil {
		return err
	}
	projectID := a.flags.project
	if projectID != "" && !strings.HasPrefix(projectID, "prj_") {
		p, err := a.findProject(ctx, s, a.flags.project)
		if err != nil {
			return err
		}
		projectID = p.ID
	}
	list, err := s.Client.AppUpdates(ctx, projectID)
	if err != nil {
		return err
	}
	return a.Printer.Result(list, func(w io.Writer) {
		p := a.Printer
		if len(list) == 0 {
			_, _ = fmt.Fprintln(w, "Every image service runs its newest release.")
			return
		}
		rows := make([][]string, 0, len(list))
		for _, u := range list {
			rows = append(rows, []string{
				u.ServiceName + p.Dim(" ("+u.ProjectName+", "+u.EnvironmentName+")"),
				deref(u.Running),
				a.updateOffer(u),
				a.updateNote(u),
			})
		}
		p.Table(w, []string{"service", "running", "available", ""}, rows)
	})
}

// updateOffer is the "available" column: the recommended version and a
// major, if there is one.
func (a *App) updateOffer(u api.AppUpdate) string {
	p := a.Printer
	var parts []string
	if u.Recommended != nil {
		parts = append(parts, u.Recommended.Version)
	}
	if u.Major != nil && (u.Recommended == nil || u.Major.Tag != u.Recommended.Tag) {
		parts = append(parts, p.Warn("⚠ "+u.Major.Version+" (major)"))
	}
	if len(parts) == 0 {
		return p.Dim("none")
	}
	return strings.Join(parts, ", ")
}

// updateNote says what is happening or what stands in the way.
func (a *App) updateNote(u api.AppUpdate) string {
	p := a.Printer
	switch {
	case u.Run != nil && (u.Run.Open() || u.Run.Status != api.AppUpdateStatusSucceeded):
		return a.updateStatus(u.Run.Status) + p.Dim(" to "+u.Run.ToVersion)
	case u.Blocker != nil && *u.Blocker != "":
		return p.Dim(*u.Blocker)
	default:
		return ""
	}
}

// updateStatus styles an update's status with its tone: an icon and the
// word, never colour alone.
func (a *App) updateStatus(status string) string {
	p := a.Printer
	label := strings.ReplaceAll(status, "_", " ")
	switch api.AppUpdateStatusTones[status] {
	case "active":
		return p.Good("● " + label)
	case "failed":
		return p.Bad("✕ " + label)
	case "progress":
		return p.Warn("◌ " + label)
	default:
		return p.Dim("○ " + label)
	}
}

type updateApplyOptions struct {
	to                    string
	major, notesRead, yes bool
	wait                  bool
	timeout               time.Duration
}

func (a *App) updatesApplyCmd() *cobra.Command {
	var o updateApplyOptions
	cmd := &cobra.Command{
		Use:   "apply [SERVICE]",
		Short: "Update an image service to a newer release",
		Long: "Backs up the service's data first when backup storage is set up, changes the image to the new release and deploys it.\n" +
			"If the new version fails, launchroom rolls back to the old one. Default: the recommended version (the newest patch or minor).\n\n" +
			"  launchroom updates apply                         the linked service\n" +
			"  launchroom updates apply listmonk --to v6.3.0    a version the last check found\n" +
			"  launchroom updates apply --major --release-notes-read   a major release\n\n" +
			"A major release can change settings and data: read its release notes, then pass --release-notes-read. " +
			"Refused while other changes are staged or a deployment runs. Asks first on a terminal; pass --yes in scripts. " +
			"Needs the \"Update apps\" permission on the token, next to Deploy.",
		Args: cobra.MaximumNArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			if len(args) == 1 {
				a.flags.service = args[0]
			}
			return a.applyUpdate(cmd.Context(), o)
		},
	}
	f := cmd.Flags()
	f.StringVar(&o.to, "to", "", "the tag to update to (one the last check found; default: the recommended version)")
	f.BoolVar(&o.major, "major", false, "update to the major release")
	f.BoolVar(&o.notesRead, "release-notes-read", false, "you read the major release's notes (needed for a major)")
	f.BoolVar(&o.yes, "yes", false, "don't ask before updating")
	f.BoolVar(&o.wait, "wait", false, "wait until the update went live, failed or rolled back")
	f.DurationVar(&o.timeout, "timeout", 30*time.Minute, "with --wait: give up after this long (exit 14)")
	return cmd
}

func (a *App) applyUpdate(ctx context.Context, o updateApplyOptions) error {
	if o.to != "" && o.major {
		return exitcode.Usagef("pass --to or --major, not both")
	}
	if o.wait && o.timeout <= 0 {
		return exitcode.Usagef("--timeout must be positive")
	}
	s, err := a.session()
	if err != nil {
		return err
	}
	t, err := a.resolve(ctx, s, true)
	if err != nil {
		return err
	}
	u, err := a.findUpdate(ctx, s, t)
	if err != nil {
		return err
	}
	c, err := pickCandidate(u, o)
	if err != nil {
		return err
	}
	p := a.Printer
	if c.Kind == api.UpdateKindMajor && !o.notesRead {
		msg := fmt.Sprintf("%s %s is a major release: it can change settings and data. Read its release notes", u.ServiceName, c.Version)
		if u.ReleaseNotesURL != nil && *u.ReleaseNotesURL != "" {
			msg += " (" + *u.ReleaseNotesURL + ")"
		}
		return exitcode.Usagef("%s, then pass --release-notes-read", msg)
	}
	if !o.yes {
		if !p.TTY || p.JSON {
			return exitcode.Usagef("pass --yes to update %s to %s (there is no terminal to ask on)", u.ServiceName, c.Version)
		}
		question := fmt.Sprintf("Update %s in %s from %s to %s?", u.ServiceName, u.EnvironmentName, deref(u.Running), c.Version)
		if len(u.Backups) > 0 {
			question = fmt.Sprintf("Back up %s, then update %s in %s from %s to %s?", strings.Join(u.Backups, " and "), u.ServiceName, u.EnvironmentName, deref(u.Running), c.Version)
		}
		ok, err := a.confirm(question)
		if err != nil {
			return err
		}
		if !ok {
			return exitcode.Usagef("nothing was updated")
		}
	}
	res, err := s.Client.ApplyAppUpdate(ctx, t.Environment.ID, t.Service.ID, api.StartAppUpdateRequest{Tag: c.Tag, ReleaseNotesRead: o.notesRead})
	if err != nil {
		return a.withUpdateHint(err, u)
	}
	if !o.wait {
		return p.Result(res, func(w io.Writer) {
			verb := "Updating"
			if res.Run != nil && res.Run.Status == api.AppUpdateStatusBackingUp {
				verb = "Backing up, then updating"
			}
			_, _ = fmt.Fprintf(w, "%s %s %s to %s.\n", p.Good("✓"), verb, res.ServiceName, c.Version)
			_, _ = fmt.Fprintln(w, p.Dim(res.DashboardURL))
		})
	}
	if !p.JSON {
		p.Info("Updating %s to %s…", p.Bold(res.ServiceName), c.Version)
		p.Info(p.Dim(res.DashboardURL))
	}
	return a.waitForUpdate(ctx, s, res, time.Now().Add(o.timeout))
}

// findUpdate is the target service's row in the update list.
func (a *App) findUpdate(ctx context.Context, s *session, t *target) (api.AppUpdate, error) {
	list, err := s.Client.AppUpdates(ctx, t.Environment.ProjectID)
	if err != nil {
		return api.AppUpdate{}, err
	}
	for _, u := range list {
		if u.ServiceID == t.Service.ID && u.EnvironmentID == t.Environment.ID {
			return u, nil
		}
	}
	return api.AppUpdate{}, exitcode.New(exitcode.NotFound, fmt.Errorf("%s has no update available in %s (see `launchroom updates`)", t.Service.Name, t.Environment.Name))
}

// pickCandidate is --to's candidate, the major with --major, else the
// recommended one.
func pickCandidate(u api.AppUpdate, o updateApplyOptions) (api.UpdateCandidate, error) {
	switch {
	case o.to != "":
		if c := u.Candidate(o.to); c != nil {
			return *c, nil
		}
		tags := make([]string, 0, len(u.Candidates))
		for _, c := range u.Candidates {
			tags = append(tags, c.Tag)
		}
		return api.UpdateCandidate{}, exitcode.New(exitcode.NotFound, fmt.Errorf("%s isn't an available update of %s (available: %s)", o.to, u.ServiceName, strings.Join(tags, ", ")))
	case o.major:
		if u.Major == nil {
			return api.UpdateCandidate{}, exitcode.New(exitcode.NotFound, fmt.Errorf("%s has no major release available", u.ServiceName))
		}
		return *u.Major, nil
	case u.Recommended != nil:
		return *u.Recommended, nil
	case u.Major != nil:
		return api.UpdateCandidate{}, exitcode.Usagef("%s only has a major release available (%s): pass --major --release-notes-read", u.ServiceName, u.Major.Version)
	default:
		return api.UpdateCandidate{}, exitcode.New(exitcode.NotFound, fmt.Errorf("%s has no update available", u.ServiceName))
	}
}

// waitForUpdate polls until the update ended: exit 0 when it went live,
// 10 when it failed or rolled back, 14 when the deadline passes first.
func (a *App) waitForUpdate(ctx context.Context, s *session, started api.AppUpdate, deadline time.Time) error {
	p := a.Printer
	said := ""
	for {
		list, err := s.Client.AppUpdates(ctx, started.ProjectID)
		if err != nil {
			return err
		}
		var run *api.AppUpdateRun
		var row api.AppUpdate
		for _, u := range list {
			if u.ServiceID == started.ServiceID && u.EnvironmentID == started.EnvironmentID {
				row, run = u, u.Run
			}
		}
		if run == nil && started.Run != nil {
			// A succeeded update leaves the list once nothing newer is left.
			_ = p.Result(started, nil)
			if !p.JSON {
				p.Info("%s %s runs %s.", p.Good("✓"), started.ServiceName, started.Run.ToVersion)
			}
			return nil
		}
		if run != nil {
			if !run.Open() {
				_ = p.Result(row, nil)
				if run.Status == api.AppUpdateStatusSucceeded {
					if !p.JSON {
						p.Info("%s %s runs %s.", p.Good("✓"), row.ServiceName, run.ToVersion)
					}
					return nil
				}
				msg := fmt.Sprintf("the update of %s to %s ended %s", row.ServiceName, run.ToVersion, strings.ReplaceAll(run.Status, "_", " "))
				if run.Message != nil && *run.Message != "" {
					msg += ": " + *run.Message
				}
				return exitcode.New(exitcode.DeploymentFailed, errors.New(msg))
			}
			line := a.updateStatus(run.Status)
			if len(run.BackingUp) > 0 {
				line += p.Dim(" " + strings.Join(run.BackingUp, ", "))
			}
			if line != said && !p.JSON {
				p.Info("%s", line)
			}
			said = line
		}
		if !time.Now().Before(deadline) {
			return exitcode.New(exitcode.WaitTimedOut, fmt.Errorf("the update of %s didn't finish in time; it keeps going (see `launchroom updates`)", started.ServiceName))
		}
		if err := s.Client.Sleep(ctx, updatePoll); err != nil {
			return err
		}
	}
}

// withUpdateHint says how to get a token that may update apps.
func (a *App) withUpdateHint(err error, u api.AppUpdate) error {
	var apiErr *api.Error
	if a.Printer.JSON || !errors.As(err, &apiErr) || apiErr.Ability != api.APIAbilityUpdate {
		return err
	}
	return fmt.Errorf("%w\n  Sign in again with `launchroom login` and allow \"Update apps\", or use a token with it.\n  Or update it in the dashboard: %s", err, u.DashboardURL)
}
