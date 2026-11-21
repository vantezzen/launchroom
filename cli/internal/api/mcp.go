package api

import (
	"context"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"strconv"
)

// Reads `launchroom mcp` needs beyond the commands' (docs/16 §7): single
// pages with their cursor, and request analytics.

// TokenScope is how far a scoped token reaches (GET /me, docs/16 §1.4).
type TokenScope struct {
	ProjectID   string  `json:"projectId"`
	ProjectName string  `json:"projectName"`
	ServiceID   *string `json:"serviceId"`
	ServiceName *string `json:"serviceName"`
}

// AnalyticsTotals are request counts over the range and the period before.
type AnalyticsTotals struct {
	Requests             int      `json:"requests"`
	Status2xx            int      `json:"status2xx"`
	Status3xx            int      `json:"status3xx"`
	Status4xx            int      `json:"status4xx"`
	Status5xx            int      `json:"status5xx"`
	ErrorRatePct         *float64 `json:"errorRatePct"`
	PreviousRequests     *int     `json:"previousRequests"`
	PreviousErrorRatePct *float64 `json:"previousErrorRatePct"`
	Blocked              int      `json:"blocked"`
}

// AnalyticsPath is one normalised path of the top routes.
type AnalyticsPath struct {
	Path      string `json:"path"`
	Requests  int    `json:"requests"`
	Status4xx int    `json:"status4xx"`
	Status5xx int    `json:"status5xx"`
	Blocked   int    `json:"blocked"`
}

// AnalyticsSplit is one server or service of the split.
type AnalyticsSplit struct {
	ID           string   `json:"id"`
	Name         string   `json:"name"`
	Requests     int      `json:"requests"`
	ErrorRatePct *float64 `json:"errorRatePct"`
}

// DeployMarker is a deploy inside the analytics range.
type DeployMarker struct {
	DeploymentID string  `json:"deploymentId"`
	ServiceID    string  `json:"serviceId"`
	At           string  `json:"at"`
	Status       string  `json:"status"`
	ServiceName  *string `json:"serviceName"`
	Sha          *string `json:"sha"`
}

// Analytics is GET …/analytics of an environment or a service (the parts
// the CLI reads).
type Analytics struct {
	Range         string           `json:"range"`
	BucketSeconds int              `json:"bucketSeconds"`
	Totals        AnalyticsTotals  `json:"totals"`
	Services      []AnalyticsSplit `json:"services"`
	Paths         []AnalyticsPath  `json:"paths"`
	PathRequests  int              `json:"pathRequests"`
	Deploys       []DeployMarker   `json:"deploys"`
}

// ProjectsPage is one page of GET /projects.
func (c *Client) ProjectsPage(ctx context.Context, cursor string, limit int) ([]Project, ListMeta, error) {
	q := url.Values{"limit": {strconv.Itoa(limit)}}
	if cursor != "" {
		q.Set("cursor", cursor)
	}
	var page []Project
	var meta ListMeta
	return page, meta, get(ctx, c, "/projects", q, &page, &meta)
}

// DeploymentsPage is one page of an environment's deployments, newest first.
func (c *Client) DeploymentsPage(ctx context.Context, envID, serviceID, cursor string, limit int) ([]Deployment, ListMeta, error) {
	q := url.Values{"limit": {strconv.Itoa(limit)}}
	if serviceID != "" {
		q.Set("service", serviceID)
	}
	if cursor != "" {
		q.Set("cursor", cursor)
	}
	var page []Deployment
	var meta ListMeta
	return page, meta, get(ctx, c, "/environments/"+seg(envID)+"/deployments", q, &page, &meta)
}

// Analytics is the request analytics of an environment, or of one of its
// services when serviceID is set.
func (c *Client) Analytics(ctx context.Context, envID, serviceID, rng string) (Analytics, error) {
	path := "/environments/" + seg(envID)
	if serviceID != "" {
		path += "/services/" + seg(serviceID)
	}
	var a Analytics
	return a, get(ctx, c, path+"/analytics", url.Values{"range": {rng}}, &a, nil)
}

// OpenPreviews is GET /projects/{p}/previews, open ones only when open.
func (c *Client) OpenPreviews(ctx context.Context, projectID string, pr int, open bool) ([]Preview, error) {
	q := url.Values{}
	if pr > 0 {
		q.Set("pr", strconv.Itoa(pr))
	}
	if open {
		q.Set("open", "1")
	}
	var p []Preview
	return p, get(ctx, c, "/projects/"+seg(projectID)+"/previews", q, &p, nil)
}

// ConfigSchema is the dashboard's launchroom.json JSON Schema
// (/schema/launchroom.v1.json, public, outside /api/v1).
func (c *Client) ConfigSchema(ctx context.Context) ([]byte, error) {
	return c.getRaw(ctx, "/schema/launchroom.v1.json")
}

// getRaw GETs a public dashboard path (no token, no API envelope), at most
// 4 MiB.
func (c *Client) getRaw(ctx context.Context, path string) ([]byte, error) {
	req, err := http.NewRequestWithContext(ctx, http.MethodGet, c.BaseURL+path, nil)
	if err != nil {
		return nil, err
	}
	if c.UserAgent != "" {
		req.Header.Set("User-Agent", c.UserAgent)
	}
	resp, err := c.HTTP.Do(req)
	if err != nil {
		return nil, &Error{Code: "network_error", Message: fmt.Sprintf("could not reach %s: %v", c.BaseURL, unwrapURLError(err))}
	}
	defer func() { _ = resp.Body.Close() }()
	body, err := io.ReadAll(io.LimitReader(resp.Body, 4<<20))
	if err != nil {
		return nil, err
	}
	if resp.StatusCode != http.StatusOK {
		return nil, &Error{Status: resp.StatusCode, Code: "http_" + strconv.Itoa(resp.StatusCode), Message: fmt.Sprintf("the dashboard answered %d for %s", resp.StatusCode, path)}
	}
	return body, nil
}
