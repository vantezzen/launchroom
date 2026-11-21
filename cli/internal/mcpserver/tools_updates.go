package mcpserver

import (
	"context"
	"fmt"
	"strings"

	"github.com/vantezzen/launchroom/cli/internal/api"
	"github.com/vantezzen/launchroom/cli/internal/mcp"
)

func (s *server) updateTools() []entry {
	return []entry{
		on(mcp.NewTool("list_app_updates", "List app updates",
			"Image services (apps from a template or an image) with a newer release or an update in flight, in the team or one project: the running version, the releases found (candidates, biggest change first), the recommended one, a major if there is one, what an update backs up first and why it can't start now (blocker). Services built from a repository never appear: they update with deploy.",
			readOnly, s.listAppUpdates), "service", api.APIAbilityRead),
		on(mcp.NewTool("apply_app_update", "Update an app to a newer release",
			"Updates an image service to a release list_app_updates found (tag; default: the recommended one), the same as the dashboard's Update: backs up its data first when backup storage is set up, changes the image, deploys, and rolls back if the new version fails. "+
				"A major release can change settings and data: tell the person what the release notes say (releaseNotesUrl) and set releaseNotesRead: true only after they agreed. "+
				"Refused while other changes are staged or a deployment runs. Ask the person first unless they asked for this update. Waits until the new version is live by default.",
			destructive, s.applyAppUpdate), "service", api.APIAbilityDeploy, api.APIAbilityUpdate),
	}
}

// ---- list_app_updates

// ListAppUpdatesIn narrows the list.
type ListAppUpdatesIn struct {
	Project string `json:"project,omitempty" maxLength:"100" desc:"Project id (prj_…), slug or name. Default: every project the token reaches."`
}

// AppUpdatesOut is the list.
type AppUpdatesOut struct {
	Updates []api.AppUpdate `json:"updates"`
}

func (s *server) listAppUpdates(ctx context.Context, _ *mcp.Request, in ListAppUpdatesIn) (AppUpdatesOut, string, error) {
	c, err := s.connect(ctx)
	if err != nil {
		return AppUpdatesOut{}, "", err
	}
	projectID := ""
	if in.Project != "" {
		p, err := s.findProject(ctx, c, in.Project)
		if err != nil {
			return AppUpdatesOut{}, "", err
		}
		projectID = p.ID
	}
	list, err := c.client.AppUpdates(ctx, projectID)
	if err != nil {
		return AppUpdatesOut{}, "", explain(err)
	}
	list = nonNil(list)
	if len(list) == 0 {
		return AppUpdatesOut{Updates: list}, "Every image service runs its newest release.", nil
	}
	var b strings.Builder
	var notes strings.Builder
	for _, u := range list {
		b.WriteString(updateLine(u))
		b.WriteString("\n")
		if u.ReleaseNotesURL != nil && *u.ReleaseNotesURL != "" {
			fmt.Fprintf(&notes, "%s: %s\n", u.ServiceName, clip(*u.ReleaseNotesURL))
		}
	}
	if notes.Len() > 0 {
		b.WriteString("Release notes (links from the image or template):\n")
		b.WriteString(untrusted("release notes links", notes.String()))
	}
	return AppUpdatesOut{Updates: list}, capText(b.String()), nil
}

// updateLine is one service's updates in a sentence.
func updateLine(u api.AppUpdate) string {
	var b strings.Builder
	fmt.Fprintf(&b, "- %s (%s, %s, %s) runs %s", u.ServiceName, u.ServiceID, u.ProjectName, u.EnvironmentName, orUnknown(u.Running))
	if u.Recommended != nil {
		fmt.Fprintf(&b, "; recommended %s (%s, tag %s)", u.Recommended.Version, u.Recommended.Kind, u.Recommended.Tag)
	}
	if u.Major != nil && (u.Recommended == nil || u.Major.Tag != u.Recommended.Tag) {
		fmt.Fprintf(&b, "; major %s (tag %s, needs the release notes read)", u.Major.Version, u.Major.Tag)
	}
	if u.Run != nil {
		fmt.Fprintf(&b, "; update to %s %s", u.Run.ToVersion, words(u.Run.Status))
	}
	if u.Blocker != nil && *u.Blocker != "" {
		fmt.Fprintf(&b, "; can't update now: %s", *u.Blocker)
	} else if !u.CanUpdate {
		b.WriteString("; this token can't update it")
	}
	if len(u.Backups) > 0 {
		fmt.Fprintf(&b, "; backs up %s first", strings.Join(u.Backups, " and "))
	}
	return b.String()
}

func orUnknown(s *string) string {
	if s == nil || *s == "" {
		return "an unknown version"
	}
	return *s
}

// ---- apply_app_update

// ApplyAppUpdateIn says what to update to.
type ApplyAppUpdateIn struct {
	Where
	Tag              string `json:"tag,omitempty" maxLength:"128" pattern:"^[A-Za-z0-9_][A-Za-z0-9_.-]{0,127}$" desc:"A candidate's tag from list_app_updates. Default: the recommended release."`
	ReleaseNotesRead bool   `json:"releaseNotesRead,omitempty" desc:"Needed for a major release: the person read its release notes and agreed."`
	WaitIn
}

// AppUpdateOut is the service's updates after starting (and following) one.
type AppUpdateOut struct {
	Update   api.AppUpdate `json:"update"`
	Live     bool          `json:"live" desc:"The new version serves (launchroom still watches it and rolls back if it fails)."`
	Finished bool          `json:"finished" desc:"The update ended: succeeded, failed, rolled back or cancelled."`
	TimedOut bool          `json:"timedOut" desc:"The wait ended first; the update carries on (list_app_updates shows it)."`
}

func (s *server) applyAppUpdate(ctx context.Context, req *mcp.Request, in ApplyAppUpdateIn) (AppUpdateOut, string, error) {
	c, t, err := s.targetOf(ctx, in.Where, true)
	if err != nil {
		return AppUpdateOut{}, "", err
	}
	list, err := c.client.AppUpdates(ctx, t.Environment.ProjectID)
	if err != nil {
		return AppUpdateOut{}, "", explain(err)
	}
	var u *api.AppUpdate
	for i := range list {
		if list[i].ServiceID == t.Service.ID && list[i].EnvironmentID == t.Environment.ID {
			u = &list[i]
		}
	}
	if u == nil {
		return AppUpdateOut{}, "", mcp.Errorf("%s has no update available in %s (list_app_updates lists what can be updated).", t.Service.Name, t.Environment.Name)
	}
	tag := in.Tag
	if tag == "" {
		if u.Recommended == nil {
			return AppUpdateOut{}, "", mcp.Errorf("%s has no recommended release: pass tag (candidates: %s).", u.ServiceName, candidateTags(*u))
		}
		tag = u.Recommended.Tag
	}
	cand := u.Candidate(tag)
	if cand == nil {
		return AppUpdateOut{}, "", mcp.Errorf("%s isn't a release found for %s. Candidates: %s.", tag, u.ServiceName, candidateTags(*u))
	}
	if cand.Kind == api.UpdateKindMajor && !in.ReleaseNotesRead {
		return AppUpdateOut{}, "", mcp.Errorf("%s is a major release of %s: it can change settings and data. Tell the person what its release notes say (%s), and only after they agree call again with releaseNotesRead: true.", cand.Version, u.ServiceName, deref(u.ReleaseNotesURL))
	}
	res, err := c.client.ApplyAppUpdate(ctx, t.Environment.ID, t.Service.ID, api.StartAppUpdateRequest{Tag: tag, ReleaseNotesRead: in.ReleaseNotesRead})
	if err != nil {
		return AppUpdateOut{}, "", explain(err)
	}
	out := AppUpdateOut{Update: res}
	if in.wanted() {
		out = s.followUpdate(ctx, req, c, res, in.WaitIn)
	}
	out.Update.Candidates = nonNil(out.Update.Candidates)
	return out, updateOutcomeText(out, cand.Version), nil
}

func candidateTags(u api.AppUpdate) string {
	tags := make([]string, 0, len(u.Candidates))
	for _, c := range u.Candidates {
		tags = append(tags, fmt.Sprintf("%s (%s)", c.Tag, c.Kind))
	}
	if len(tags) == 0 {
		return "none"
	}
	return strings.Join(tags, ", ")
}

// followUpdate polls the update until the new version serves (watching)
// or it ended. Cancelling ctx stops the wait, never the update.
func (s *server) followUpdate(ctx context.Context, req *mcp.Request, c *conn, started api.AppUpdate, w WaitIn) AppUpdateOut {
	deadline := s.opts.Now().Add(w.timeout())
	cur := started
	last := ""
	for {
		run := cur.Run
		switch {
		case run == nil:
			// A succeeded update leaves the list once nothing newer is left.
			return AppUpdateOut{Update: cur, Live: true, Finished: true}
		case !run.Open():
			return AppUpdateOut{Update: cur, Live: run.Status == api.AppUpdateStatusSucceeded, Finished: true}
		case run.Status == api.AppUpdateStatusWatching:
			return AppUpdateOut{Update: cur, Live: true}
		}
		if line := words(run.Status); line != last {
			req.Progress(fmt.Sprintf("%s: %s", cur.ServiceName, line))
			last = line
		}
		if !s.opts.Now().Before(deadline) {
			return AppUpdateOut{Update: cur, TimedOut: true}
		}
		if err := s.opts.Sleep(ctx, pollInterval); err != nil {
			return AppUpdateOut{Update: cur, TimedOut: true}
		}
		list, err := c.client.AppUpdates(ctx, cur.ProjectID)
		if err != nil {
			continue
		}
		found := false
		for _, u := range list {
			if u.ServiceID == cur.ServiceID && u.EnvironmentID == cur.EnvironmentID {
				cur, found = u, true
			}
		}
		if !found {
			cur.Run = nil
		}
	}
}

func updateOutcomeText(o AppUpdateOut, version string) string {
	u, run := o.Update, o.Update.Run
	var b strings.Builder
	switch {
	case run == nil || run.Status == api.AppUpdateStatusSucceeded:
		fmt.Fprintf(&b, "%s runs %s.", u.ServiceName, version)
	case run.Status == api.AppUpdateStatusWatching:
		fmt.Fprintf(&b, "%s runs %s. launchroom watches it", u.ServiceName, run.ToVersion)
		if run.WatchUntil != nil {
			fmt.Fprintf(&b, " until %s", *run.WatchUntil)
		}
		b.WriteString(" and rolls back to the old version if it fails.")
	case o.Finished:
		fmt.Fprintf(&b, "The update of %s to %s ended %s; %s keeps running.", u.ServiceName, run.ToVersion, words(run.Status), run.FromVersion)
	case o.TimedOut:
		fmt.Fprintf(&b, "The update of %s to %s is %s; the wait ended, the update carries on (list_app_updates shows it).", u.ServiceName, run.ToVersion, words(run.Status))
	default:
		fmt.Fprintf(&b, "Updating %s to %s: %s.", u.ServiceName, run.ToVersion, words(run.Status))
	}
	if run != nil && run.Message != nil && *run.Message != "" {
		b.WriteString("\n")
		b.WriteString(untrusted("update message", clip(*run.Message)))
	}
	b.WriteString("\n" + u.DashboardURL)
	return b.String()
}
