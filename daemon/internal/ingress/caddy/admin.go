package caddy

import (
	"bytes"
	"context"
	"errors"
	"fmt"
	"io"
	"net/http"
	"strings"
	"time"
	"unicode"
)

// Admin is the subset of Caddy's admin API the daemon uses.
type Admin interface {
	// Ready returns nil once the admin API answers.
	Ready(ctx context.Context) error
	// Load atomically replaces the whole config (POST /load).
	Load(ctx context.Context, cfg []byte) error
}

// AdminClient talks to Caddy's admin API on a loopback address.
type AdminClient struct {
	base    string
	http    *http.Client
	timeout time.Duration
}

// Reloader is implemented by admins that can force a reload of an
// unchanged config (Caddy re-provisions modules, re-reading {file.*} secrets).
type Reloader interface {
	Reload(ctx context.Context, cfg []byte) error
}

var (
	_ Admin    = (*AdminClient)(nil)
	_ Reloader = (*AdminClient)(nil)
)

// NewAdminClient returns a client for http://<listen> (e.g. 127.0.0.1:2019).
func NewAdminClient(listen string) *AdminClient {
	return &AdminClient{
		base: "http://" + listen,
		http: &http.Client{
			Transport:     &http.Transport{Proxy: nil, DisableKeepAlives: true, ResponseHeaderTimeout: 30 * time.Second},
			CheckRedirect: func(*http.Request, []*http.Request) error { return http.ErrUseLastResponse },
		},
		timeout: 30 * time.Second,
	}
}

func (a *AdminClient) do(ctx context.Context, method, path string, body []byte, force bool) error {
	ctx, cancel := context.WithTimeout(ctx, a.timeout)
	defer cancel()
	var r io.Reader = http.NoBody
	if body != nil {
		r = bytes.NewReader(body)
	}
	req, err := http.NewRequestWithContext(ctx, method, a.base+path, r)
	if err != nil {
		return fmt.Errorf("caddy admin: %w", err)
	}
	if body != nil {
		req.Header.Set("Content-Type", "application/json")
	}
	if force {
		req.Header.Set("Cache-Control", "must-revalidate")
	}
	resp, err := a.http.Do(req)
	if err != nil {
		return fmt.Errorf("caddy admin: %s %s: %w", method, path, err)
	}
	defer func() { _ = resp.Body.Close() }()
	snippet, _ := io.ReadAll(io.LimitReader(resp.Body, 512))
	if resp.StatusCode/100 != 2 {
		return &AdminStatusError{Method: method, Path: path, Status: resp.StatusCode, Body: sanitize(snippet)}
	}
	return nil
}

// AdminStatusError is an answer of Caddy's admin API other than 2xx.
type AdminStatusError struct {
	Method, Path string
	Status       int
	// Body is the start of Caddy's answer (printable text only).
	Body string
}

func (e *AdminStatusError) Error() string {
	return fmt.Sprintf("caddy admin: %s %s: HTTP %d: %s", e.Method, e.Path, e.Status, e.Body)
}

// IsConfigRefusal reports whether err is Caddy rejecting a config itself:
// a 4xx answer, which POST /load gives when the config doesn't parse or a
// module fails to provision. Everything else says nothing about the config
// (docs/22 §6.6): no connection, a reset, a timeout, a 5xx while Caddy
// starts or stops, 408 and 429. Those are retried with the same config.
func IsConfigRefusal(err error) bool {
	var se *AdminStatusError
	if !errors.As(err, &se) {
		return false
	}
	return se.Status >= 400 && se.Status < 500 &&
		se.Status != http.StatusRequestTimeout && se.Status != http.StatusTooManyRequests
}

// Ready implements Admin.
func (a *AdminClient) Ready(ctx context.Context) error {
	return a.do(ctx, http.MethodGet, "/config/", nil, false)
}

// Load implements Admin.
func (a *AdminClient) Load(ctx context.Context, cfg []byte) error {
	return a.do(ctx, http.MethodPost, "/load", cfg, false)
}

// Reload implements Reloader: POST /load with "Cache-Control: must-revalidate".
func (a *AdminClient) Reload(ctx context.Context, cfg []byte) error {
	return a.do(ctx, http.MethodPost, "/load", cfg, true)
}

func sanitize(b []byte) string {
	return strings.Map(func(r rune) rune {
		if unicode.IsPrint(r) {
			return r
		}
		return ' '
	}, strings.TrimSpace(string(b)))
}
