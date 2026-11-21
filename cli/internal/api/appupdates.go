package api

import (
	"context"
	"net/http"
	"net/url"
	"slices"
)

// App updates of image services (D106, docs/04 §14).

// UpdateCandidate is a newer release of a running image.
type UpdateCandidate struct {
	Kind        string  `json:"kind"`
	Tag         string  `json:"tag"`
	Version     string  `json:"version"`
	Digest      *string `json:"digest"`
	PublishedAt *string `json:"publishedAt"`
	FirstSeenAt string  `json:"firstSeenAt"`
	CuratedBy   *string `json:"curatedBy,omitempty"`
}

// AppUpdateRun is an update running now, or one that ended within a day.
type AppUpdateRun struct {
	ID             string   `json:"id"`
	Status         string   `json:"status"`
	Automatic      bool     `json:"automatic"`
	Kind           string   `json:"kind"`
	FromVersion    string   `json:"fromVersion"`
	ToVersion      string   `json:"toVersion"`
	Actor          *string  `json:"actor"`
	DeploymentID   *string  `json:"deploymentId"`
	DeploymentHref *string  `json:"deploymentHref"`
	Message        *string  `json:"message"`
	BackupNote     *string  `json:"backupNote"`
	BackingUp      []string `json:"backingUp"`
	StartedAt      string   `json:"startedAt"`
	FinishedAt     *string  `json:"finishedAt"`
	WatchUntil     *string  `json:"watchUntil"`
}

// Open reports whether the update is still running (backing up, deploying
// or watching).
func (r AppUpdateRun) Open() bool {
	return slices.Contains([]string{AppUpdateStatusBackingUp, AppUpdateStatusDeploying, AppUpdateStatusWatching}, r.Status)
}

// AppUpdate is one image service's updates in one environment
// (GET /app-updates).
type AppUpdate struct {
	ServiceID          string            `json:"serviceId"`
	ServiceName        string            `json:"serviceName"`
	ProjectID          string            `json:"projectId"`
	ProjectName        string            `json:"projectName"`
	EnvironmentID      string            `json:"environmentId"`
	EnvironmentName    string            `json:"environmentName"`
	Production         bool              `json:"production"`
	Status             string            `json:"status"`
	Reason             *string           `json:"reason"`
	Running            *string           `json:"running"`
	Moving             bool              `json:"moving"`
	RunningPublishedAt *string           `json:"runningPublishedAt"`
	Image              *string           `json:"image"`
	Candidates         []UpdateCandidate `json:"candidates"`
	Recommended        *UpdateCandidate  `json:"recommended"`
	Major              *UpdateCandidate  `json:"major"`
	ReleaseNotesURL    *string           `json:"releaseNotesUrl"`
	CheckedAt          *string           `json:"checkedAt"`
	Run                *AppUpdateRun     `json:"run"`
	CanUpdate          bool              `json:"canUpdate"`
	Blocker            *string           `json:"blocker"`
	Backups            []string          `json:"backups"`
	KeepsData          bool              `json:"keepsData"`
	BackupStorage      bool              `json:"backupStorage"`
	AutoUpdate         string            `json:"autoUpdate"`
	DashboardURL       string            `json:"dashboardUrl"`
}

// Candidate is the candidate with tag, if the last check found it.
func (u AppUpdate) Candidate(tag string) *UpdateCandidate {
	for i := range u.Candidates {
		if u.Candidates[i].Tag == tag {
			return &u.Candidates[i]
		}
	}
	return nil
}

// StartAppUpdateRequest is POST …/update's body.
type StartAppUpdateRequest struct {
	Tag              string `json:"tag"`
	ReleaseNotesRead bool   `json:"releaseNotesRead,omitempty"`
}

// AppUpdates is GET /app-updates (projectID "": the whole team, or what
// the token's scope covers).
func (c *Client) AppUpdates(ctx context.Context, projectID string) ([]AppUpdate, error) {
	q := url.Values{}
	if projectID != "" {
		q.Set("project", projectID)
	}
	var u []AppUpdate
	return u, get(ctx, c, "/app-updates", q, &u, nil)
}

// ApplyAppUpdate is POST /environments/{env}/services/{svc}/update.
func (c *Client) ApplyAppUpdate(ctx context.Context, envID, serviceID string, body StartAppUpdateRequest) (AppUpdate, error) {
	var u AppUpdate
	return u, c.do(ctx, request{method: http.MethodPost, path: "/environments/" + seg(envID) + "/services/" + seg(serviceID) + "/update", body: body, idempotent: true}, &u, nil)
}
