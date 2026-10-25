// Package api is the launchroom API v1 client (docs/16-api-and-cli.md §2).
package api

import (
	"bytes"
	"context"
	"crypto/rand"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"strconv"
	"strings"
	"time"

	"github.com/vantezzen/launchroom/cli/internal/exitcode"
)

// Error is an error answer of the API ({"error": {...}}) or a transport
// failure (Status 0).
type Error struct {
	Status     int                 `json:"-"`
	Code       string              `json:"code"`
	Message    string              `json:"message"`
	Fields     map[string][]string `json:"fields,omitempty"`
	Ability    string              `json:"ability,omitempty"`
	RetryAfter time.Duration       `json:"-"`
}

func (e *Error) Error() string {
	msg := e.Message
	if msg == "" {
		msg = http.StatusText(e.Status)
	}
	if e.Ability != "" {
		msg += fmt.Sprintf(" (the token needs the %q ability)", e.Ability)
	}
	for field, errs := range e.Fields {
		msg += fmt.Sprintf("\n  %s: %s", field, strings.Join(errs, " "))
	}
	return msg
}

// ExitCode maps the error to the CLI's exit codes.
func (e *Error) ExitCode() int {
	if e.Status == 0 {
		return exitcode.Error
	}
	return exitcode.ForStatus(e.Status)
}

// IsStatus reports whether err is an API error with that HTTP status.
func IsStatus(err error, status int) bool {
	var e *Error
	return errors.As(err, &e) && e.Status == status
}

// Client talks to one dashboard.
type Client struct {
	BaseURL    string // e.g. https://launchroom.example.com (no trailing slash)
	Token      string
	UserAgent  string
	HTTP       *http.Client // for normal requests (with a timeout)
	StreamHTTP *http.Client // for SSE and downloads (no overall timeout)
	// TransferHTTP uploads transfer chunks (a longer timeout for slow lines).
	TransferHTTP *http.Client
	// Sleep waits between retries; replaced in tests.
	Sleep func(context.Context, time.Duration) error
}

// New returns a client for baseURL.
func New(baseURL, token, userAgent string) *Client {
	return &Client{
		BaseURL:      strings.TrimRight(baseURL, "/"),
		Token:        token,
		UserAgent:    userAgent,
		HTTP:         &http.Client{Timeout: 60 * time.Second, CheckRedirect: noRedirects},
		StreamHTTP:   &http.Client{CheckRedirect: noRedirects},
		TransferHTTP: &http.Client{Timeout: 10 * time.Minute, CheckRedirect: noRedirects},
		Sleep:        sleep,
	}
}

// The token must never follow a redirect to another host.
func noRedirects(*http.Request, []*http.Request) error { return http.ErrUseLastResponse }

func sleep(ctx context.Context, d time.Duration) error {
	t := time.NewTimer(d)
	defer t.Stop()
	select {
	case <-ctx.Done():
		return ctx.Err()
	case <-t.C:
		return nil
	}
}

// NewIdempotencyKey returns a random Idempotency-Key.
func NewIdempotencyKey() string {
	b := make([]byte, 16)
	_, _ = rand.Read(b)
	return "cli-" + hex.EncodeToString(b)
}

// request describes one API call.
type request struct {
	method     string
	path       string
	query      url.Values
	body       any
	idempotent bool // send an Idempotency-Key (POST creates)
}

// do runs a request and decodes {"data"} into out (and {"meta"} into meta).
func (c *Client) do(ctx context.Context, r request, out, meta any) error {
	var payload []byte
	if r.body != nil {
		var err error
		if payload, err = json.Marshal(r.body); err != nil {
			return err
		}
	}
	key := ""
	if r.idempotent {
		key = NewIdempotencyKey()
	}
	// GETs and idempotency-keyed POSTs are safe to send again after a
	// network error; GETs also wait out one 429.
	retryable := r.method == http.MethodGet || key != ""
	var lastErr error
	for attempt := 0; attempt < 2; attempt++ {
		resp, err := c.send(ctx, c.HTTP, r, payload, key, nil) //nolint:bodyclose // decode closes it
		if err != nil {
			lastErr = err
			if retryable && ctx.Err() == nil && attempt == 0 {
				if err := c.Sleep(ctx, time.Second); err != nil {
					return err
				}
				continue
			}
			return err
		}
		err = decode(resp, out, meta)
		var apiErr *Error
		if errors.As(err, &apiErr) && apiErr.Status == http.StatusTooManyRequests && r.method == http.MethodGet && attempt == 0 {
			wait := apiErr.RetryAfter
			if wait <= 0 || wait > 30*time.Second {
				wait = 5 * time.Second
			}
			if err := c.Sleep(ctx, wait); err != nil {
				return err
			}
			lastErr = err
			continue
		}
		return err
	}
	return lastErr
}

func (c *Client) send(ctx context.Context, hc *http.Client, r request, payload []byte, key string, headers map[string]string) (*http.Response, error) {
	u := c.BaseURL + "/api/v1" + r.path
	if len(r.query) > 0 {
		u += "?" + r.query.Encode()
	}
	var body io.Reader
	if payload != nil {
		body = bytes.NewReader(payload)
	}
	req, err := http.NewRequestWithContext(ctx, r.method, u, body)
	if err != nil {
		return nil, err
	}
	req.Header.Set("Accept", "application/json")
	if payload != nil {
		req.Header.Set("Content-Type", "application/json")
	}
	if c.Token != "" {
		req.Header.Set("Authorization", "Bearer "+c.Token)
	}
	if c.UserAgent != "" {
		req.Header.Set("User-Agent", c.UserAgent)
	}
	if key != "" {
		req.Header.Set("Idempotency-Key", key)
	}
	for k, v := range headers {
		req.Header.Set(k, v)
	}
	resp, err := hc.Do(req)
	if err != nil {
		if ctx.Err() != nil {
			return nil, ctx.Err()
		}
		return nil, &Error{Code: "network_error", Message: fmt.Sprintf("could not reach %s: %v", c.BaseURL, unwrapURLError(err))}
	}
	return resp, nil
}

func unwrapURLError(err error) error {
	var ue *url.Error
	if errors.As(err, &ue) {
		return ue.Err
	}
	return err
}

// decode reads a response: 2xx into out/meta, everything else into *Error.
func decode(resp *http.Response, out, meta any) error {
	defer func() { _ = resp.Body.Close() }()
	raw, err := io.ReadAll(io.LimitReader(resp.Body, 64<<20))
	if err != nil {
		return &Error{Code: "network_error", Message: "reading the response failed: " + err.Error()}
	}
	if resp.StatusCode < 200 || resp.StatusCode > 299 {
		return errorFrom(resp, raw)
	}
	if out == nil && meta == nil || resp.StatusCode == http.StatusNoContent || len(bytes.TrimSpace(raw)) == 0 {
		return nil
	}
	var env envelope
	if err := json.Unmarshal(raw, &env); err != nil {
		return &Error{Status: resp.StatusCode, Code: "bad_response", Message: "the dashboard answered with something that is not launchroom API JSON (is the URL right?)"}
	}
	if out != nil && len(env.Data) > 0 {
		if err := json.Unmarshal(env.Data, out); err != nil {
			return &Error{Status: resp.StatusCode, Code: "bad_response", Message: "unexpected response shape: " + err.Error()}
		}
	}
	if meta != nil && len(env.Meta) > 0 {
		_ = json.Unmarshal(env.Meta, meta)
	}
	return nil
}

func errorFrom(resp *http.Response, raw []byte) *Error {
	var body struct {
		Error *Error `json:"error"`
	}
	e := &Error{}
	if json.Unmarshal(raw, &body) == nil && body.Error != nil {
		e = body.Error
	}
	e.Status = resp.StatusCode
	if e.Code == "" {
		e.Code = "http_" + strconv.Itoa(resp.StatusCode)
	}
	if e.Message == "" {
		e.Message = fmt.Sprintf("the dashboard answered %d %s", resp.StatusCode, http.StatusText(resp.StatusCode))
	}
	if s := resp.Header.Get("Retry-After"); s != "" {
		if n, err := strconv.Atoi(s); err == nil {
			e.RetryAfter = time.Duration(n) * time.Second
		}
	}
	return e
}

func get(ctx context.Context, c *Client, path string, q url.Values, out, meta any) error {
	return c.do(ctx, request{method: http.MethodGet, path: path, query: q}, out, meta)
}

func seg(s string) string { return url.PathEscape(s) }

// Me is GET /me.
func (c *Client) Me(ctx context.Context) (Me, error) {
	var m Me
	return m, get(ctx, c, "/me", nil, &m, nil)
}

// Projects lists every project of the token's team (all pages).
func (c *Client) Projects(ctx context.Context) ([]Project, error) {
	var all []Project
	q := url.Values{"limit": {"100"}}
	for {
		var page []Project
		var meta ListMeta
		if err := get(ctx, c, "/projects", q, &page, &meta); err != nil {
			return nil, err
		}
		all = append(all, page...)
		if meta.NextCursor == nil || *meta.NextCursor == "" {
			return all, nil
		}
		q.Set("cursor", *meta.NextCursor)
	}
}

// Project is GET /projects/{id}.
func (c *Client) Project(ctx context.Context, id string) (Project, error) {
	var p Project
	return p, get(ctx, c, "/projects/"+seg(id), nil, &p, nil)
}

// Environments is GET /projects/{id}/environments.
func (c *Client) Environments(ctx context.Context, projectID string) ([]Environment, error) {
	var e []Environment
	return e, get(ctx, c, "/projects/"+seg(projectID)+"/environments", nil, &e, nil)
}

// Environment is GET /environments/{id}.
func (c *Client) Environment(ctx context.Context, id string) (Environment, error) {
	var e Environment
	return e, get(ctx, c, "/environments/"+seg(id), nil, &e, nil)
}

// Services is GET /environments/{env}/services.
func (c *Client) Services(ctx context.Context, envID string) ([]Service, error) {
	var s []Service
	return s, get(ctx, c, "/environments/"+seg(envID)+"/services", nil, &s, nil)
}

// Service is GET /environments/{env}/services/{svc}.
func (c *Client) Service(ctx context.Context, envID, serviceID string) (Service, error) {
	var s Service
	return s, get(ctx, c, "/environments/"+seg(envID)+"/services/"+seg(serviceID), nil, &s, nil)
}

// Deployments lists the newest deployments of an environment.
func (c *Client) Deployments(ctx context.Context, envID, serviceID string, limit int) ([]Deployment, error) {
	q := url.Values{"limit": {strconv.Itoa(limit)}}
	if serviceID != "" {
		q.Set("service", serviceID)
	}
	var d []Deployment
	return d, get(ctx, c, "/environments/"+seg(envID)+"/deployments", q, &d, nil)
}

// DeployRequest is the body of POST /environments/{env}/deployments.
type DeployRequest struct {
	Service  string `json:"service"`
	Ref      string `json:"ref,omitempty"`
	Sha      string `json:"sha,omitempty"`
	Redeploy bool   `json:"redeploy,omitempty"`
}

// CreateDeployment deploys a ref of a service (or redeploys it).
func (c *Client) CreateDeployment(ctx context.Context, envID string, body DeployRequest) (Deployment, error) {
	var d Deployment
	return d, c.do(ctx, request{method: http.MethodPost, path: "/environments/" + seg(envID) + "/deployments", body: body, idempotent: true}, &d, nil)
}

// DeployStaged applies the environment's staged changes.
func (c *Client) DeployStaged(ctx context.Context, envID, message string) (DeployResult, error) {
	body := map[string]any{"stage": false}
	if message != "" {
		body["message"] = message
	}
	var r DeployResult
	return r, c.do(ctx, request{method: http.MethodPost, path: "/environments/" + seg(envID) + "/deploy", body: body, idempotent: true}, &r, nil)
}

// Deployment is GET /deployments/{id}.
func (c *Client) Deployment(ctx context.Context, id string) (Deployment, error) {
	var d Deployment
	return d, get(ctx, c, "/deployments/"+seg(id), nil, &d, nil)
}

// DeploymentAction is POST /deployments/{id}/{redeploy|rollback|cancel}.
func (c *Client) DeploymentAction(ctx context.Context, id, action string) (Deployment, error) {
	var d Deployment
	return d, c.do(ctx, request{method: http.MethodPost, path: "/deployments/" + seg(id) + "/" + action, idempotent: action != "cancel"}, &d, nil)
}

// DeploymentLogs is one page of a deployment's log after a cursor.
func (c *Client) DeploymentLogs(ctx context.Context, id string, after int) ([]LogLine, LogMeta, error) {
	var lines []LogLine
	var meta LogMeta
	err := get(ctx, c, "/deployments/"+seg(id)+"/logs", url.Values{"after": {strconv.Itoa(after)}}, &lines, &meta)
	return lines, meta, err
}

// LogQuery filters environment logs.
type LogQuery struct {
	Service string
	Source  string // all, build, runtime
	Range   string // 15m, 1h, 24h, 7d
	After   int
}

func (q LogQuery) values() url.Values {
	v := url.Values{}
	if q.Service != "" {
		v.Set("service", q.Service)
	}
	if q.Source != "" {
		v.Set("source", q.Source)
	}
	if q.Range != "" {
		v.Set("range", q.Range)
	}
	if q.After > 0 {
		v.Set("after", strconv.Itoa(q.After))
	}
	return v
}

// EnvironmentLogs is GET /environments/{env}/logs.
func (c *Client) EnvironmentLogs(ctx context.Context, envID string, q LogQuery) ([]LogEntry, LogMeta, error) {
	var entries []LogEntry
	var meta LogMeta
	err := get(ctx, c, "/environments/"+seg(envID)+"/logs", q.values(), &entries, &meta)
	return entries, meta, err
}

// Variables lists the variable rows of a service in an environment.
func (c *Client) Variables(ctx context.Context, envID, serviceID string) ([]Variable, error) {
	var v []Variable
	return v, get(ctx, c, "/environments/"+seg(envID)+"/services/"+seg(serviceID)+"/variables", nil, &v, nil)
}

// CreateVariable adds a variable (staged until deploy).
func (c *Client) CreateVariable(ctx context.Context, envID, serviceID, key, value, typ string) (Variable, error) {
	var v Variable
	body := map[string]any{"key": key, "value": value, "type": typ}
	return v, c.do(ctx, request{method: http.MethodPost, path: "/environments/" + seg(envID) + "/services/" + seg(serviceID) + "/variables", body: body, idempotent: true}, &v, nil)
}

// UpdateVariable changes a variable in this environment only.
func (c *Client) UpdateVariable(ctx context.Context, envID, variableID string, fields map[string]any) (Variable, error) {
	var v Variable
	return v, c.do(ctx, request{method: http.MethodPatch, path: "/environments/" + seg(envID) + "/variables/" + seg(variableID), body: fields}, &v, nil)
}

// DeleteVariable removes a variable (staged until deploy).
func (c *Client) DeleteVariable(ctx context.Context, envID, variableID string) error {
	return c.do(ctx, request{method: http.MethodDelete, path: "/environments/" + seg(envID) + "/variables/" + seg(variableID)}, nil, nil)
}

// ResolvedEnv is the resolved env of a service; secrets only when asked
// for and allowed (else listed in Withheld).
func (c *Client) ResolvedEnv(ctx context.Context, envID, serviceID string, secrets bool) (ResolvedEnv, error) {
	q := url.Values{}
	if secrets {
		q.Set("secrets", "1")
	}
	var r ResolvedEnv
	return r, get(ctx, c, "/environments/"+seg(envID)+"/services/"+seg(serviceID)+"/env", q, &r, nil)
}

// StartLogin is POST /cli/sessions (no token).
func (c *Client) StartLogin(ctx context.Context, hostname, version string) (LoginSession, error) {
	var s LoginSession
	body := map[string]string{"clientName": "launchroom-cli", "hostname": hostname, "version": version}
	return s, c.do(ctx, request{method: http.MethodPost, path: "/cli/sessions", body: body}, &s, nil)
}

// PollLogin is POST /cli/sessions/token: 428 while pending.
func (c *Client) PollLogin(ctx context.Context, deviceCode string) (LoginToken, error) {
	var t LoginToken
	return t, c.do(ctx, request{method: http.MethodPost, path: "/cli/sessions/token", body: map[string]string{"deviceCode": deviceCode}}, &t, nil)
}

// ExportConfig downloads the environment as launchroom.json (revision 0 =
// the current settings, staged changes included).
func (c *Client) ExportConfig(ctx context.Context, envID string, revision int) (ConfigExport, error) {
	q := url.Values{}
	if revision > 0 {
		q.Set("revision", strconv.Itoa(revision))
	}
	var e ConfigExport
	return e, get(ctx, c, "/environments/"+seg(envID)+"/config", q, &e, nil)
}

// CheckConfig validates a launchroom.json against the environment; with
// diff it also lists the staged changes it would produce (a dry run).
func (c *Client) CheckConfig(ctx context.Context, envID, content string, diff bool) (ConfigCheck, error) {
	action := "validate"
	if diff {
		action = "diff"
	}
	var r ConfigCheck
	return r, c.do(ctx, request{method: http.MethodPost, path: "/environments/" + seg(envID) + "/config/" + action, body: map[string]string{"content": content}}, &r, nil)
}

// SecurityFindings lists findings of the token's team (all pages);
// level is "action_needed", "worth_knowing" or "" for both.
func (c *Client) SecurityFindings(ctx context.Context, level string) ([]SecurityFinding, error) {
	var all []SecurityFinding
	q := url.Values{"limit": {"100"}}
	if level != "" {
		q.Set("level", level)
	}
	for {
		var page []SecurityFinding
		var meta ListMeta
		if err := get(ctx, c, "/security/findings", q, &page, &meta); err != nil {
			return nil, err
		}
		all = append(all, page...)
		if meta.NextCursor == nil || *meta.NextCursor == "" {
			return all, nil
		}
		q.Set("cursor", *meta.NextCursor)
	}
}

// SecurityInventory is GET /security/inventory?q=.
func (c *Client) SecurityInventory(ctx context.Context, query string) (InventorySearch, error) {
	var s InventorySearch
	return s, get(ctx, c, "/security/inventory", url.Values{"q": {query}}, &s, nil)
}
