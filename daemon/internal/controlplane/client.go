// Package controlplane is the daemon's HTTP client for the dashboard's
// /daemon/v1 API (docs/03 §4). Every request is signed (RFC 9421, see
// internal/identity) and every response body the daemon acts on must carry a
// valid X-LR-Signature from a trusted control-plane key; anything else is
// rejected before parsing (fail closed).
package controlplane

import (
	"bytes"
	"context"
	"crypto/ed25519"
	"crypto/tls"
	"errors"
	"fmt"
	"io"
	"log/slog"
	"net"
	"net/http"
	"net/url"
	"strconv"
	"strings"
	"sync"
	"sync/atomic"
	"time"
	"unicode"

	"github.com/vantezzen/launchroom/daemon/internal/identity"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

const (
	// ProtocolVersionsHeader advertises the protocol versions this daemon speaks (docs/03 §10).
	ProtocolVersionsHeader = "X-Lr-Protocol-Versions"
	idempotencyHeader      = "Idempotency-Key"

	defaultTimeout   = 30 * time.Second
	maxStateBytes    = state.MaxDocumentBytes
	maxResponseBytes = 4 << 20
	maxErrorSnippet  = 256
)

// Options configures a Client.
type Options struct {
	// BaseURL is the dashboard base URL; endpoints are resolved under <base>/daemon/v1/.
	BaseURL *url.URL
	// Signer signs every request (keyid "enroll" before enrollment, the server id after).
	Signer *identity.Signer
	// Trusted verifies response signatures. Nil is allowed only for an
	// enrollment-only client, whose responses are checked against the pinned key.
	Trusted identity.KeySet
	// DaemonVersion goes into the User-Agent.
	DaemonVersion string
	// Timeout bounds each request (default 30 s).
	Timeout time.Duration
	// Transport is the underlying transport (default: a hardened clone of http.DefaultTransport).
	Transport http.RoundTripper
	// Now is the daemon clock (default time.Now), used for the clock skew
	// estimate and the preferred URL's cooldown.
	Now func() time.Time
	// Log receives failover warnings (default: discarded).
	Log *slog.Logger
	// ClientCertificate, when set, is presented on every TLS connection to
	// the dashboard that asks for one (mTLS, docs/03 §4.3): the configured
	// URL, an adopted origin and its verification. Applied to an
	// *http.Transport (a clone; the default one when Transport is nil).
	ClientCertificate func(*tls.CertificateRequestInfo) (*tls.Certificate, error)
}

// PreferredCooldown is how long a failing preferred URL (the adopted
// canonical origin, docs/03 §4.2) is skipped before it is tried again.
const PreferredCooldown = 2 * time.Minute

// Client talks to the dashboard. It is safe for concurrent use.
type Client struct {
	base      *url.URL
	http      *http.Client
	trusted   identity.KeySet
	timeout   time.Duration
	userAgent string
	now       func() time.Time

	// skewMs is the dashboard clock minus the daemon clock in ms, from the
	// Date header of the last response (skewKnown: at least one sample).
	skewMs    atomic.Int64
	skewKnown atomic.Bool

	log *slog.Logger
	// preferred is the adopted canonical origin (nil: use base only).
	// While downUntil is in the future it is skipped (failover to base).
	pmu       sync.Mutex
	preferred *url.URL
	downUntil time.Time
	downWhy   string
	// active is the base URL of the last successful request.
	active atomic.Pointer[url.URL]
}

// New returns a Client.
func New(opts Options) (*Client, error) {
	if opts.BaseURL == nil || opts.BaseURL.Host == "" {
		return nil, errors.New("controlplane: base URL is required")
	}
	if opts.Signer == nil {
		return nil, errors.New("controlplane: signer is required")
	}
	timeout := opts.Timeout
	if timeout <= 0 {
		timeout = defaultTimeout
	}
	base := opts.Transport
	if base == nil {
		base = defaultTransport()
	}
	if opts.ClientCertificate != nil {
		base = WithClientCertificate(base, opts.ClientCertificate)
	}
	now := opts.Now
	if now == nil {
		now = time.Now
	}
	log := opts.Log
	if log == nil {
		log = slog.New(slog.DiscardHandler)
	}
	return &Client{
		log:  log,
		now:  now,
		base: opts.BaseURL,
		http: &http.Client{
			Transport: &identity.Transport{Base: base, Signer: opts.Signer},
			// Redirects would change the signed @path and could point anywhere; never follow them.
			CheckRedirect: func(*http.Request, []*http.Request) error { return http.ErrUseLastResponse },
		},
		trusted:   opts.Trusted,
		timeout:   timeout,
		userAgent: "launchroomd/" + opts.DaemonVersion,
	}, nil
}

// WithClientCertificate returns rt with get presenting the client
// certificate on TLS connections. rt must be an *http.Transport (it is
// cloned); anything else is returned unchanged.
func WithClientCertificate(rt http.RoundTripper, get func(*tls.CertificateRequestInfo) (*tls.Certificate, error)) http.RoundTripper {
	t, ok := rt.(*http.Transport)
	if !ok || get == nil {
		return rt
	}
	t = t.Clone()
	if t.TLSClientConfig == nil {
		t.TLSClientConfig = &tls.Config{MinVersion: tls.VersionTLS12}
	} else {
		t.TLSClientConfig = t.TLSClientConfig.Clone()
	}
	t.TLSClientConfig.GetClientCertificate = get
	return t
}

// DefaultTransport is the hardened transport used for dashboard connections
// (TLS ≥ 1.2, bounded dial/handshake/header timeouts).
func DefaultTransport() http.RoundTripper { return defaultTransport() }

func defaultTransport() http.RoundTripper {
	t, _ := http.DefaultTransport.(*http.Transport)
	t = t.Clone()
	t.TLSClientConfig = &tls.Config{MinVersion: tls.VersionTLS12}
	t.TLSHandshakeTimeout = 10 * time.Second
	t.ResponseHeaderTimeout = 30 * time.Second
	t.MaxIdleConnsPerHost = 4
	t.DialContext = (&net.Dialer{Timeout: 10 * time.Second, KeepAlive: 30 * time.Second}).DialContext
	return t
}

// APIError is a non-success HTTP status. Body is an untrusted, truncated,
// sanitized snippet of the (unsigned) error body, for logs only.
type APIError struct {
	Status int
	Body   string
}

func (e *APIError) Error() string {
	if e.Body == "" {
		return fmt.Sprintf("controlplane: HTTP %d", e.Status)
	}
	return fmt.Sprintf("controlplane: HTTP %d: %s", e.Status, e.Body)
}

// Retryable reports whether the request may succeed later (5xx, 429, 408).
func (e *APIError) Retryable() bool {
	return e.Status >= 500 || e.Status == http.StatusTooManyRequests || e.Status == http.StatusRequestTimeout
}

// IsRetryable reports whether err is worth retrying: network errors and retryable API errors.
// Signature and decoding failures are not retryable at the request level.
func IsRetryable(err error) bool {
	if err == nil {
		return false
	}
	var api *APIError
	if errors.As(err, &api) {
		return api.Retryable()
	}
	var sig *SignatureError
	var dec *DecodeError
	return !errors.As(err, &sig) && !errors.As(err, &dec)
}

// SignatureError means a response failed signature verification.
type SignatureError struct{ Err error }

func (e *SignatureError) Error() string { return "controlplane: " + e.Err.Error() }
func (e *SignatureError) Unwrap() error { return e.Err }

// DecodeError means a signed response was not a valid document.
type DecodeError struct{ Err error }

func (e *DecodeError) Error() string {
	return "controlplane: invalid response document: " + e.Err.Error()
}
func (e *DecodeError) Unwrap() error { return e.Err }

type response struct {
	status    int
	body      []byte
	signature string
}

// do sends one request. body is encoded as JSON when non-nil. With an
// adopted canonical origin (SetPreferred) the request goes there first and
// fails over to the configured base URL (docs/03 §4.2).
func (c *Client) do(ctx context.Context, method, path string, query url.Values, body any, headers http.Header, limit int64) (*response, error) {
	var data []byte
	if raw, ok := body.(rawBody); ok {
		data = raw.data
		headers = headers.Clone()
		if headers == nil {
			headers = http.Header{}
		}
		headers.Set("Content-Type", raw.contentType)
	} else if body != nil {
		var err error
		if data, err = state.Encode(body); err != nil {
			return nil, fmt.Errorf("controlplane: encode request: %w", err)
		}
	}
	if p := c.usablePreferred(); p != nil {
		r, err := c.send(ctx, p, method, path, query, data, body != nil, headers, limit)
		why := c.preferredFailure(r, err)
		if why == "" {
			c.preferredOK(p)
			c.active.Store(p)
			return r, nil
		}
		if ctx.Err() != nil {
			return nil, err
		}
		c.preferredDown(p, why)
	}
	r, err := c.send(ctx, c.base, method, path, query, data, body != nil, headers, limit)
	if err == nil {
		c.active.Store(c.base)
	}
	return r, err
}

// doBase sends one request to the configured base URL only (enrollment,
// verifying a candidate origin).
func (c *Client) doBase(ctx context.Context, base *url.URL, method, path string, body any, limit int64) (*response, error) {
	var data []byte
	if body != nil {
		var err error
		if data, err = state.Encode(body); err != nil {
			return nil, fmt.Errorf("controlplane: encode request: %w", err)
		}
	}
	return c.send(ctx, base, method, path, nil, data, body != nil, nil, limit)
}

// send sends one request to base. Every call builds (and so signs, with a
// fresh nonce) a new request.
func (c *Client) send(ctx context.Context, base *url.URL, method, path string, query url.Values, data []byte, hasBody bool, headers http.Header, limit int64) (*response, error) {
	timeout := c.timeout
	if d, ok := ctx.Value(timeoutKey{}).(time.Duration); ok && d > 0 {
		timeout = d
	}
	ctx, cancel := context.WithTimeout(ctx, timeout)
	defer cancel()

	u := base.JoinPath("daemon", "v1", path)
	if len(query) > 0 {
		u.RawQuery = query.Encode()
	}
	var reader io.Reader = http.NoBody
	if hasBody {
		reader = bytes.NewReader(data)
	}
	req, err := http.NewRequestWithContext(ctx, method, u.String(), reader)
	if err != nil {
		return nil, fmt.Errorf("controlplane: build request: %w", err)
	}
	for k, vs := range headers {
		for _, v := range vs {
			req.Header.Add(k, v)
		}
	}
	if req.Header.Get("Accept") == "" {
		req.Header.Set("Accept", "application/json")
	}
	req.Header.Set("User-Agent", c.userAgent)
	req.Header.Set(ProtocolVersionsHeader, state.ProtocolVersionV1)
	if hasBody && req.Header.Get("Content-Type") == "" {
		req.Header.Set("Content-Type", "application/json")
	}

	sent := c.now()
	resp, err := c.http.Do(req)
	if err != nil {
		return nil, fmt.Errorf("controlplane: %s %s: %w", method, u.Path, err)
	}
	defer func() { _ = resp.Body.Close() }()
	c.observeDate(resp.Header.Get("Date"), sent, c.now())

	got, err := io.ReadAll(io.LimitReader(resp.Body, limit+1))
	if err != nil {
		return nil, fmt.Errorf("controlplane: read response: %w", err)
	}
	if int64(len(got)) > limit {
		return nil, fmt.Errorf("controlplane: response larger than %d bytes", limit)
	}
	return &response{status: resp.StatusCode, body: got, signature: resp.Header.Get(identity.ResponseSignatureHeader)}, nil
}

// observeDate updates the clock skew estimate from a response Date header,
// taking the middle of the request as the daemon's time of the answer.
func (c *Client) observeDate(header string, sent, received time.Time) {
	if header == "" {
		return
	}
	served, err := http.ParseTime(header)
	if err != nil {
		return
	}
	mid := sent.Add(received.Sub(sent) / 2)
	// The header has whole seconds: compare against the daemon's whole second too.
	c.skewMs.Store(served.Sub(mid.Truncate(time.Second)).Milliseconds())
	c.skewKnown.Store(true)
}

// ClockSkew returns the dashboard clock minus the daemon clock (ok=false
// before the first response with a Date header). Resolution is about 1 s.
func (c *Client) ClockSkew() (time.Duration, bool) {
	if !c.skewKnown.Load() {
		return 0, false
	}
	return time.Duration(c.skewMs.Load()) * time.Millisecond, true
}

func statusError(r *response) error {
	return &APIError{Status: r.status, Body: sanitize(r.body)}
}

// sanitize makes an untrusted error body safe to log.
func sanitize(b []byte) string {
	if len(b) > maxErrorSnippet {
		b = b[:maxErrorSnippet]
	}
	return strings.Map(func(r rune) rune {
		if unicode.IsPrint(r) {
			return r
		}
		return -1
	}, string(b))
}

// verified returns the body if it is signed by a trusted key.
func (c *Client) verified(r *response) ([]byte, string, error) {
	if len(c.trusted) == 0 {
		return nil, "", &SignatureError{Err: errors.New("no trusted control-plane keys configured")}
	}
	kid, err := c.trusted.Verify(r.signature, r.body)
	if err != nil {
		return nil, "", &SignatureError{Err: err}
	}
	return r.body, kid, nil
}

func decodeSigned[T any, PT interface {
	*T
	state.Document
}](c *Client, r *response) (*T, error) {
	body, _, err := c.verified(r)
	if err != nil {
		return nil, err
	}
	doc, err := state.Decode[T, PT](body)
	if err != nil {
		return nil, &DecodeError{Err: err}
	}
	return doc, nil
}

// EnrollResult is a verified enrollment response and the key set to trust from now on.
type EnrollResult struct {
	Response *state.EnrollResponse
	Trusted  identity.KeySet
}

// Enroll sends the join request. The response must be signed by pinned (the
// key from the install command), and the returned key set must contain pinned
// under the signing kid — otherwise a MITM could substitute its own keys.
func (c *Client) Enroll(ctx context.Context, req *state.EnrollRequest, pinned ed25519.PublicKey) (*EnrollResult, error) {
	if err := req.Validate(); err != nil {
		return nil, fmt.Errorf("controlplane: enroll request: %w", err)
	}
	// Enrollment always uses the configured URL (the install command's).
	r, err := c.doBase(ctx, c.base, http.MethodPost, "enroll", req, maxResponseBytes)
	if err != nil {
		return nil, err
	}
	if r.status != http.StatusOK && r.status != http.StatusCreated {
		return nil, statusError(r)
	}
	kid, err := identity.VerifyWithKey(pinned, r.signature, r.body)
	if err != nil {
		return nil, &SignatureError{Err: err}
	}
	resp, err := state.Decode[state.EnrollResponse](r.body)
	if err != nil {
		return nil, &DecodeError{Err: err}
	}
	keys := make(map[string]string, len(resp.ControlPlaneKeys))
	for k, v := range resp.ControlPlaneKeys {
		keys[string(k)] = string(v)
	}
	trusted, err := identity.ParseKeySet(keys)
	if err != nil {
		return nil, &DecodeError{Err: err}
	}
	if got, ok := trusted[kid]; !ok || !got.Equal(pinned) {
		return nil, &SignatureError{Err: fmt.Errorf("enrollment key set does not contain the pinned key under kid %q", kid)}
	}
	return &EnrollResult{Response: resp, Trusted: trusted}, nil
}

// FetchState fetches the desired state. modified is false (and the result nil)
// when the dashboard answers 304 for have. The result is verified before parsing,
// strictly decoded, validated and checked to be for serverID.
func (c *Client) FetchState(ctx context.Context, serverID state.ServerID, have int64) (st *state.SignedState, modified bool, err error) {
	q := url.Values{"have": {strconv.FormatInt(have, 10)}}
	r, err := c.do(ctx, http.MethodGet, "state", q, nil, nil, maxStateBytes)
	if err != nil {
		return nil, false, err
	}
	switch r.status {
	case http.StatusNotModified:
		return nil, false, nil
	case http.StatusOK:
	default:
		return nil, false, statusError(r)
	}
	if _, _, err := c.verified(r); err != nil {
		return nil, false, err
	}
	ss, err := state.VerifyNodeState(r.body, r.signature, c.trusted, serverID)
	if err != nil {
		return nil, false, &DecodeError{Err: err}
	}
	return ss, true, nil
}

// LeaseTasks leases up to req.Max tasks (non-blocking).
func (c *Client) LeaseTasks(ctx context.Context, req *state.LeaseRequest) ([]state.Task, error) {
	if err := req.Validate(); err != nil {
		return nil, fmt.Errorf("controlplane: lease request: %w", err)
	}
	r, err := c.do(ctx, http.MethodPost, "tasks/lease", nil, req, nil, maxStateBytes)
	if err != nil {
		return nil, err
	}
	if r.status != http.StatusOK {
		return nil, statusError(r)
	}
	lease, err := decodeSigned[state.TaskLease](c, r)
	if err != nil {
		return nil, err
	}
	return lease.Tasks, nil
}

// Heartbeat extends a task lease.
func (c *Client) Heartbeat(ctx context.Context, id state.TaskID, hb *state.TaskHeartbeat) (*state.TaskHeartbeatResult, error) {
	if err := id.Validate(); err != nil {
		return nil, fmt.Errorf("controlplane: %w", err)
	}
	if err := hb.Validate(); err != nil {
		return nil, fmt.Errorf("controlplane: heartbeat: %w", err)
	}
	r, err := c.do(ctx, http.MethodPost, "tasks/"+string(id)+"/heartbeat", nil, hb, nil, maxResponseBytes)
	if err != nil {
		return nil, err
	}
	if r.status != http.StatusOK {
		return nil, statusError(r)
	}
	return decodeSigned[state.TaskHeartbeatResult](c, r)
}

// Complete reports a task outcome. It is idempotent by (task id, attempt).
func (c *Client) Complete(ctx context.Context, id state.TaskID, done *state.TaskCompletion) error {
	if err := id.Validate(); err != nil {
		return fmt.Errorf("controlplane: %w", err)
	}
	if err := done.Validate(); err != nil {
		return fmt.Errorf("controlplane: completion: %w", err)
	}
	h := http.Header{idempotencyHeader: {string(id) + ":" + strconv.Itoa(done.Attempt)}}
	return c.post(ctx, "tasks/"+string(id)+"/complete", done, h)
}

// Report sends observed state.
func (c *Client) Report(ctx context.Context, rep *state.Report) error {
	if err := rep.Validate(); err != nil {
		return fmt.Errorf("controlplane: report: %w", err)
	}
	return c.post(ctx, "reports", rep, nil)
}

// ShipLogs sends a batch of log chunks.
func (c *Client) ShipLogs(ctx context.Context, batch *state.LogBatch) error {
	if err := batch.Validate(); err != nil {
		return fmt.Errorf("controlplane: logs: %w", err)
	}
	return c.post(ctx, "logs", batch, nil)
}

// RegistryToken requests a short-lived registry bearer token (docs/05 §6).
// The dashboard grants every requested scope or refuses (403); the daemon
// re-checks that the signed response covers what it asked for.
func (c *Client) RegistryToken(ctx context.Context, req *state.RegistryTokenRequest) (*state.RegistryToken, error) {
	if err := req.Validate(); err != nil {
		return nil, fmt.Errorf("controlplane: registry token request: %w", err)
	}
	r, err := c.do(ctx, http.MethodPost, "registry-token", nil, req, nil, maxResponseBytes)
	if err != nil {
		return nil, err
	}
	if r.status != http.StatusOK && r.status != http.StatusCreated {
		return nil, statusError(r)
	}
	tok, err := decodeSigned[state.RegistryToken](c, r)
	if err != nil {
		return nil, err
	}
	if !tok.Covers(req.Scopes) {
		return nil, &DecodeError{Err: errors.New("registry token does not cover the requested scopes")}
	}
	return tok, nil
}

// FetchConfig fetches the daemon settings that may change after enrollment
// (GET /daemon/v1/config). A dashboard without the endpoint answers 404.
func (c *Client) FetchConfig(ctx context.Context) (*state.DaemonConfig, error) {
	r, err := c.do(ctx, http.MethodGet, "config", nil, nil, nil, maxResponseBytes)
	if err != nil {
		return nil, err
	}
	if r.status != http.StatusOK {
		return nil, statusError(r)
	}
	return decodeSigned[state.DaemonConfig](c, r)
}

// CloseIdleConnections drops idle keep-alive connections, so the next
// request opens a new TLS connection (with the current client certificate).
func (c *Client) CloseIdleConnections() { c.http.CloseIdleConnections() }

// IssueClientCertificate asks for a client certificate for the request's key
// (POST /daemon/v1/client-certificate, mTLS, docs/03 §4.3). The response
// must be signed; the caller checks the certificate itself (clientcert).
func (c *Client) IssueClientCertificate(ctx context.Context, req *state.ClientCertificateRequest) (*state.ClientCertificate, error) {
	if err := req.Validate(); err != nil {
		return nil, fmt.Errorf("controlplane: client certificate request: %w", err)
	}
	r, err := c.do(ctx, http.MethodPost, "client-certificate", nil, req, nil, maxResponseBytes)
	if err != nil {
		return nil, err
	}
	if r.status != http.StatusOK && r.status != http.StatusCreated {
		return nil, statusError(r)
	}
	return decodeSigned[state.ClientCertificate](c, r)
}

// BroadcastAuth asks the dashboard to authorize socketID on the daemon's own
// private channel (POST /daemon/v1/broadcasting/auth). The result is the
// Pusher "auth" value for the subscribe message.
func (c *Client) BroadcastAuth(ctx context.Context, socketID state.SocketID, channel state.DaemonChannel) (state.PusherAuth, error) {
	req := &state.BroadcastAuthRequest{TypeMeta: state.NewTypeMeta(state.KindBroadcastAuthRequest), SocketID: socketID, Channel: channel}
	if err := req.Validate(); err != nil {
		return "", fmt.Errorf("controlplane: broadcast auth request: %w", err)
	}
	r, err := c.do(ctx, http.MethodPost, "broadcasting/auth", nil, req, nil, maxResponseBytes)
	if err != nil {
		return "", err
	}
	if r.status != http.StatusOK {
		return "", statusError(r)
	}
	auth, err := decodeSigned[state.BroadcastAuth](c, r)
	if err != nil {
		return "", err
	}
	return auth.Auth, nil
}

// post sends a body to an endpoint whose response the daemon does not act on.
func (c *Client) post(ctx context.Context, path string, body any, h http.Header) error {
	r, err := c.do(ctx, http.MethodPost, path, nil, body, h, maxResponseBytes)
	if err != nil {
		return err
	}
	switch r.status {
	case http.StatusOK, http.StatusAccepted, http.StatusNoContent:
		return nil
	default:
		return statusError(r)
	}
}

// Trusted returns the control-plane key set this client verifies responses
// with (also used for signed session commands, docs/03 §11.2).
func (c *Client) Trusted() identity.KeySet { return c.trusted }

// ---- Canonical origin and failover (docs/03 §4.2) ----

// SetPreferred makes requests go to u first (the verified canonical origin)
// and fail over to the configured base URL; nil clears it.
func (c *Client) SetPreferred(u *url.URL) {
	c.pmu.Lock()
	defer c.pmu.Unlock()
	if u != nil {
		cp := *u
		u = &cp
	}
	if sameURL(c.preferred, u) {
		return
	}
	c.preferred, c.downUntil, c.downWhy = u, time.Time{}, ""
}

// Configured is the configured base URL (LR_CONTROL_PLANE_URL).
func (c *Client) Configured() *url.URL { cp := *c.base; return &cp }

// Preferred is the adopted origin (nil when none).
func (c *Client) Preferred() *url.URL {
	c.pmu.Lock()
	defer c.pmu.Unlock()
	if c.preferred == nil {
		return nil
	}
	cp := *c.preferred
	return &cp
}

// Failover reports whether the preferred URL is currently skipped and why.
func (c *Client) Failover() (bool, string) {
	c.pmu.Lock()
	defer c.pmu.Unlock()
	if c.preferred == nil || !c.now().Before(c.downUntil) {
		return false, ""
	}
	return true, c.downWhy
}

// ActiveBase is where the next request goes: the preferred URL unless it is
// cooling down, else the configured one. Realtime connections (nudges,
// sessions) derive their endpoint from it.
func (c *Client) ActiveBase() *url.URL {
	if p := c.usablePreferred(); p != nil {
		return p
	}
	return c.Configured()
}

// LastActive is the base URL of the last successful request (nil before one).
func (c *Client) LastActive() *url.URL {
	if u := c.active.Load(); u != nil {
		cp := *u
		return &cp
	}
	return nil
}

// VerifyOrigin checks that candidate is the same control plane: a signed
// GET <candidate>/daemon/v1/config must answer 200 with a DaemonConfig signed
// by a trusted key. Used before adopting a pushed canonical origin.
func (c *Client) VerifyOrigin(ctx context.Context, candidate *url.URL) error {
	r, err := c.doBase(ctx, candidate, http.MethodGet, "config", nil, maxResponseBytes)
	if err != nil {
		return err
	}
	if r.status != http.StatusOK {
		return statusError(r)
	}
	_, err = decodeSigned[state.DaemonConfig](c, r)
	return err
}

func (c *Client) usablePreferred() *url.URL {
	c.pmu.Lock()
	defer c.pmu.Unlock()
	if c.preferred == nil || c.now().Before(c.downUntil) {
		return nil
	}
	cp := *c.preferred
	return &cp
}

// preferredFailure says why a response from the preferred URL is not used
// ("" = use it): a transport error, a status that means "not the dashboard
// (here)" (404, 421, 502, 503, 504), or a 2xx body whose signature does not
// verify (every 2xx daemon response with a body is signed, protocol/SIGNING.md §3).
func (c *Client) preferredFailure(r *response, err error) string {
	if err != nil {
		return err.Error()
	}
	switch r.status {
	case http.StatusNotFound, http.StatusMisdirectedRequest, http.StatusBadGateway, http.StatusServiceUnavailable, http.StatusGatewayTimeout:
		return fmt.Sprintf("HTTP %d", r.status)
	}
	if r.status >= 200 && r.status < 300 && len(r.body) > 0 && len(c.trusted) > 0 {
		if _, err := c.trusted.Verify(r.signature, r.body); err != nil {
			return "response not signed by the control plane"
		}
	}
	return ""
}

func (c *Client) preferredDown(p *url.URL, why string) {
	c.pmu.Lock()
	if !sameURL(c.preferred, p) {
		c.pmu.Unlock()
		return
	}
	first := c.downWhy == ""
	c.downUntil = c.now().Add(PreferredCooldown)
	c.downWhy = sanitize([]byte(why))
	c.pmu.Unlock()
	if first {
		c.log.Warn("dashboard's canonical address failed; using the configured URL for a while",
			"preferred", p.String(), "fallback", c.base.String(), "reason", why, "retryIn", PreferredCooldown.String())
	}
}

func (c *Client) preferredOK(p *url.URL) {
	c.pmu.Lock()
	recovered := sameURL(c.preferred, p) && c.downWhy != ""
	if recovered {
		c.downUntil, c.downWhy = time.Time{}, ""
	}
	c.pmu.Unlock()
	if recovered {
		c.log.Info("dashboard's canonical address works again", "preferred", p.String())
	}
}

func sameURL(a, b *url.URL) bool {
	if a == nil || b == nil {
		return a == nil && b == nil
	}
	return a.String() == b.String()
}
