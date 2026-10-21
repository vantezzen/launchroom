package caddy

import (
	"bytes"
	"context"
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
		return fmt.Errorf("caddy admin: %s %s: HTTP %d: %s", method, path, resp.StatusCode, sanitize(snippet))
	}
	return nil
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
