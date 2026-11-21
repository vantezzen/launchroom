package api

import (
	"context"
	"net/http"
	"net/url"
	"strconv"
)

// Failure reports, fixes, rays and previews (D111).

// FailureExplanation is a known failure in plain words (FailureCatalogue).
type FailureExplanation struct {
	Key    string  `json:"key"`
	Title  string  `json:"title"`
	Cause  string  `json:"cause"`
	Fix    string  `json:"fix"`
	Answer *string `json:"answer"`
}

// FailureHint is a known build problem the server recognised.
type FailureHint struct {
	Code    string `json:"code"`
	Message string `json:"message"`
}

// ExcerptLine is one line of the failing step's excerpt.
type ExcerptLine struct {
	Number int    `json:"number"`
	Stream string `json:"stream"`
	Text   string `json:"text"`
	Error  bool   `json:"error"`
}

// FailureFix is a fix for an explained failure.
type FailureFix struct {
	Code         string  `json:"code"`
	Label        string  `json:"label"`
	OneClick     bool    `json:"oneClick"`
	Command      *string `json:"command"`
	DashboardURL string  `json:"dashboardUrl"`
	Port         *int    `json:"port"`
	Seconds      *int    `json:"seconds"`
	Server       *string `json:"server"`
	Variable     *string `json:"variable"`
}

// FailureReport is GET /deployments/{id}/failure.
type FailureReport struct {
	DeploymentID  string              `json:"deploymentId"`
	Class         string              `json:"class"`
	Title         string              `json:"title"`
	Message       *string             `json:"message"`
	Hint          string              `json:"hint"`
	Explanation   *FailureExplanation `json:"explanation"`
	Hints         []FailureHint       `json:"hints"`
	FailingStep   *string             `json:"failingStep"`
	Excerpt       []ExcerptLine       `json:"excerpt"`
	ErrorLine     *int                `json:"errorLine"`
	Fixes         []FailureFix        `json:"fixes"`
	ServingCommit *string             `json:"servingCommit"`
	DashboardURL  string              `json:"dashboardUrl"`
}

// FixRequest overrides a fix's suggestion (POST …/fixes/{code}).
type FixRequest struct {
	Port    *int `json:"port,omitempty"`
	Seconds *int `json:"seconds,omitempty"`
}

// FixResult is what applying a fix did.
type FixResult struct {
	Outcome    string      `json:"outcome"`
	Message    string      `json:"message"`
	Deployment *Deployment `json:"deployment"`
}

// TraceServer is a server on a traced request's path.
type TraceServer struct {
	ID     string `json:"id"`
	Name   string `json:"name"`
	Status string `json:"status"`
}

// WafRule is a firewall rule that matched.
type WafRule struct {
	ID       int    `json:"id"`
	Message  string `json:"message"`
	Category string `json:"category"`
}

// TraceFirewall is the firewall's decision about a traced request.
type TraceFirewall struct {
	Mode    string    `json:"mode"`
	Outcome string    `json:"outcome"`
	Score   int       `json:"score"`
	Rules   []WafRule `json:"rules"`
}

// TraceService is the service a traced request reached.
type TraceService struct {
	ID              string `json:"id"`
	Name            string `json:"name"`
	ProjectID       string `json:"projectId"`
	ProjectName     string `json:"projectName"`
	EnvironmentID   string `json:"environmentId"`
	EnvironmentName string `json:"environmentName"`
}

// Trace is POST /traces/decode: a decoded ray.
type Trace struct {
	ReceivedAt     string         `json:"receivedAt"`
	RequestID      string         `json:"requestId"`
	Method         *string        `json:"method"`
	Path           *string        `json:"path"`
	Protocol       *string        `json:"protocol"`
	Status         int            `json:"status"`
	UpstreamStatus *int           `json:"upstreamStatus"`
	Origin         string         `json:"origin"`
	Failure        *string        `json:"failure"`
	EdgeTimeUs     *int           `json:"edgeTimeUs"`
	UpstreamTimeUs *int           `json:"upstreamTimeUs"`
	Attempts       *int           `json:"attempts"`
	Firewall       *TraceFirewall `json:"firewall"`
	Edge           TraceServer    `json:"edge"`
	UpstreamServer *TraceServer   `json:"upstreamServer"`
	SameServer     bool           `json:"sameServer"`
	Host           *string        `json:"host"`
	Service        *TraceService  `json:"service"`
	Dashboard      bool           `json:"dashboard"`
	EndsAt         string         `json:"endsAt"`
	Summary        string         `json:"summary"`
	LogsFrom       *int           `json:"logsFrom"`
	LogsTo         *int           `json:"logsTo"`
	DashboardURL   string         `json:"dashboardUrl"`
}

// PreviewURL is a preview's address for one service.
type PreviewURL struct {
	Service string `json:"service"`
	URL     string `json:"url"`
}

// Preview is one pull request's preview (GET /projects/{p}/previews).
type Preview struct {
	ID             string       `json:"id"`
	Number         int          `json:"number"`
	Title          string       `json:"title"`
	Author         string       `json:"author"`
	Repository     string       `json:"repository"`
	PullRequestURL *string      `json:"pullRequestUrl"`
	HeadRef        string       `json:"headRef"`
	BaseRef        string       `json:"baseRef"`
	HeadSha        string       `json:"headSha"`
	IsFork         bool         `json:"isFork"`
	Status         string       `json:"status"`
	StatusReason   *string      `json:"statusReason"`
	Health         string       `json:"health"`
	EnvironmentID  *string      `json:"environmentId"`
	URLs           []PreviewURL `json:"urls"`
	Deployments    []Deployment `json:"deployments"`
	ExpiresAt      *string      `json:"expiresAt"`
	DashboardURL   string       `json:"dashboardUrl"`
}

// ConfigStatus is GET /environments/{env}/config/status: the last read of
// the environment's launchroom.json.
type ConfigStatus struct {
	ID        string  `json:"id"`
	Name      string  `json:"name"`
	Status    *string `json:"status"`
	CommitSha *string `json:"commitSha"`
	CheckedAt *string `json:"checkedAt"`
}

// DeploymentFailure is GET /deployments/{id}/failure.
func (c *Client) DeploymentFailure(ctx context.Context, id string) (FailureReport, error) {
	var r FailureReport
	return r, get(ctx, c, "/deployments/"+seg(id)+"/failure", nil, &r, nil)
}

// ApplyFix is POST /deployments/{id}/fixes/{code}.
func (c *Client) ApplyFix(ctx context.Context, id, code string, body FixRequest) (FixResult, error) {
	var r FixResult
	return r, c.do(ctx, request{method: http.MethodPost, path: "/deployments/" + seg(id) + "/fixes/" + seg(code), body: body, idempotent: true}, &r, nil)
}

// ResumeAutoDeploy is POST /environments/{env}/auto-deploy/resume.
func (c *Client) ResumeAutoDeploy(ctx context.Context, envID string) (Environment, error) {
	var e Environment
	return e, c.do(ctx, request{method: http.MethodPost, path: "/environments/" + seg(envID) + "/auto-deploy/resume", idempotent: true}, &e, nil)
}

// DecodeTrace is POST /traces/decode.
func (c *Client) DecodeTrace(ctx context.Context, value string) (Trace, error) {
	var t Trace
	return t, c.do(ctx, request{method: http.MethodPost, path: "/traces/decode", body: map[string]string{"value": value}}, &t, nil)
}

// Previews is GET /projects/{p}/previews (pr > 0: that pull request only).
func (c *Client) Previews(ctx context.Context, projectID string, pr int) ([]Preview, error) {
	q := url.Values{}
	if pr > 0 {
		q.Set("pr", strconv.Itoa(pr))
	}
	var p []Preview
	return p, get(ctx, c, "/projects/"+seg(projectID)+"/previews", q, &p, nil)
}

// ConfigStatus is GET /environments/{env}/config/status.
func (c *Client) ConfigStatus(ctx context.Context, envID string) (ConfigStatus, error) {
	var s ConfigStatus
	return s, get(ctx, c, "/environments/"+seg(envID)+"/config/status", nil, &s, nil)
}
