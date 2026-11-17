// Package health runs the daemon's external health probes against container
// IP:port (docs/04 §8, docs/03 §1) and discovers which TCP ports a container
// actually listens on, so the UI can say "your app listens on 5173, not 3000".
//
// Probes run from the daemon (host network namespace), so images need no curl.
// HTTP probes never follow redirects and never use a proxy.
package health

import (
	"context"
	"errors"
	"fmt"
	"io"
	"net"
	"net/http"
	"net/netip"
	"regexp"
	"strconv"
	"strings"
	"time"
	"unicode"

	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// DefaultExpectStatus is used when an HTTP probe has no expectStatus.
const DefaultExpectStatus = state.StatusRange("2xx-3xx")

// Target is one workload to probe.
type Target struct {
	Workload    state.WorkloadID
	ContainerID string
	Addr        netip.AddrPort
	Spec        state.HealthSpec
	// StartedAt is when the container (re)started; the start period counts from here.
	StartedAt time.Time
}

// key identifies a probe instance; a change restarts the probe.
func (t Target) key() string {
	return fmt.Sprintf("%s|%s|%s|%+v|%d", t.ContainerID, t.Addr, t.Spec.Type, t.Spec, t.StartedAt.UnixNano())
}

// ParseStatusRange parses "200", "2xx", "2xx-3xx", "200-399" into an inclusive range.
func ParseStatusRange(r state.StatusRange) (lo, hi int, err error) {
	if err := r.Validate(); err != nil {
		return 0, 0, err
	}
	bound := func(s string) (int, int) {
		if strings.HasSuffix(s, "xx") {
			n, _ := strconv.Atoi(s[:1])
			return n * 100, n*100 + 99
		}
		n, _ := strconv.Atoi(s)
		return n, n
	}
	left, right, isRange := strings.Cut(string(r), "-")
	lo, hi = bound(left)
	if isRange {
		_, hi = bound(right)
	}
	if lo > hi {
		return 0, 0, fmt.Errorf("status range %q is empty", r)
	}
	return lo, hi, nil
}

// Probe performs one check. It returns nil when the target is healthy.
func Probe(ctx context.Context, t Target) error {
	timeout := t.Spec.Timeout.Std()
	if timeout <= 0 {
		timeout = 3 * time.Second
	}
	ctx, cancel := context.WithTimeout(ctx, timeout)
	defer cancel()
	if !t.Addr.IsValid() {
		return errors.New("no container address")
	}
	switch t.Spec.Type {
	case state.HealthTCP:
		var d net.Dialer
		c, err := d.DialContext(ctx, "tcp", t.Addr.String())
		if err != nil {
			return simplify(err)
		}
		return c.Close()
	case state.HealthHTTP:
		return probeHTTP(ctx, t)
	default:
		return fmt.Errorf("unknown probe type %q", t.Spec.Type)
	}
}

var probeTransport = &http.Transport{
	Proxy:                  nil, // never through a proxy
	DisableKeepAlives:      true,
	DialContext:            (&net.Dialer{Timeout: 5 * time.Second}).DialContext,
	ResponseHeaderTimeout:  30 * time.Second,
	MaxResponseHeaderBytes: 64 << 10,
}

var probeClient = &http.Client{
	Transport:     probeTransport,
	CheckRedirect: func(*http.Request, []*http.Request) error { return http.ErrUseLastResponse },
}

func probeHTTP(ctx context.Context, t Target) error {
	expect := t.Spec.ExpectStatus
	if expect == "" {
		expect = DefaultExpectStatus
	}
	lo, hi, err := ParseStatusRange(expect)
	if err != nil {
		return err
	}
	path := string(t.Spec.Path)
	if path == "" {
		path = "/"
	}
	req, err := http.NewRequestWithContext(ctx, http.MethodGet, "http://"+t.Addr.String()+path, http.NoBody)
	if err != nil {
		return fmt.Errorf("invalid probe request: %w", err)
	}
	req.Header.Set("User-Agent", "launchroom-health/1")
	if t.Spec.Host != "" {
		// Ask as a visitor does (docs/04 §8): the app's own domain over
		// HTTPS at the edge, so allowed-hosts checks and forced-HTTPS
		// redirects answer the probe as they answer visitors.
		req.Host = string(t.Spec.Host)
		req.Header.Set("X-Forwarded-Proto", "https")
		req.Header.Set("X-Forwarded-Host", string(t.Spec.Host))
	}
	resp, err := probeClient.Do(req)
	if err != nil {
		return simplify(err)
	}
	raw, _ := io.ReadAll(io.LimitReader(resp.Body, maxProbeRead))
	_ = resp.Body.Close()
	hint := classify(resp.StatusCode, raw)
	inRange := resp.StatusCode >= lo && resp.StatusCode <= hi
	// An app that refuses its own domain fails even where 4xx would pass
	// (the implicit "/" accepts 2xx-4xx): every visitor gets that answer.
	// Without a host the probe asked for the container address, which the
	// app may refuse without refusing visitors.
	if inRange && (hint != ProbeHostNotAllowed || t.Spec.Host == "") {
		return nil
	}
	he := &HTTPError{Status: resp.StatusCode, Expect: expect, Hint: hint, Body: Snippet(raw, resp.Header.Get("Content-Type"))}
	return he
}

// maxProbeRead bounds how much of an answer the probe reads.
const maxProbeRead = 4096

// ProbeHostNotAllowed is state.ProbeHostNotAllowed.
const ProbeHostNotAllowed = state.ProbeHostNotAllowed

// HTTPError is a failing HTTP answer: its status, what it means and the
// start of its body (ProbeStatus.body/hint).
type HTTPError struct {
	Status int
	Expect state.StatusRange
	Hint   state.ProbeHint
	// Body is the start of the answer as text (Snippet).
	Body string
}

func (e *HTTPError) Error() string {
	if e.Hint == ProbeHostNotAllowed {
		return fmt.Sprintf("HTTP %d (expected %s): the app doesn't accept this domain as its host", e.Status, e.Expect)
	}
	return fmt.Sprintf("HTTP %d (expected %s)", e.Status, e.Expect)
}

// reHostNotAllowed matches the "host not allowed" pages of common
// frameworks: Django (DisallowedHost, "Invalid HTTP_HOST header"), Rails
// (Blocked hosts), ASP.NET/HTTP.sys ("Invalid Hostname"), Symfony and
// Laravel ("Untrusted Host"), Vite and webpack dev servers.
var reHostNotAllowed = regexp.MustCompile(`(?i)disallowedhost|invalid http_host header|blocked hosts?\b|invalid hostname|untrusted host|invalid host header|host .{0,80}is not allowed|this host .{0,80}not allowed|not an allowed host|allowed_hosts`)

// classify names what a failing answer means ("" = nothing known).
func classify(status int, body []byte) state.ProbeHint {
	switch status {
	case http.StatusMisdirectedRequest:
		return ProbeHostNotAllowed
	case http.StatusBadRequest, http.StatusForbidden:
		if reHostNotAllowed.Match(body) {
			return ProbeHostNotAllowed
		}
	}
	return ""
}

// Snippet is the start of an answer body for the dashboard: HTML reduced
// to its text, control characters and runs of whitespace collapsed, valid
// UTF-8, at most state.MaxProbeBody bytes. The dashboard masks it like a
// log line before it shows it.
func Snippet(body []byte, contentType string) string {
	text := string(body)
	if strings.Contains(strings.ToLower(contentType), "html") || looksHTML(text) {
		text = htmlText(text)
	}
	var b strings.Builder
	space := false
	for _, r := range strings.ToValidUTF8(text, "") {
		if unicode.IsSpace(r) || unicode.IsControl(r) {
			space = b.Len() > 0
			continue
		}
		if space {
			b.WriteByte(' ')
			space = false
		}
		b.WriteRune(r)
	}
	return state.Clip(b.String(), state.MaxProbeBody)
}

func looksHTML(s string) bool {
	head := strings.ToLower(strings.TrimSpace(s[:min(len(s), 256)]))
	return strings.HasPrefix(head, "<!doctype html") || strings.HasPrefix(head, "<html")
}

// htmlText drops tags, comments, scripts and styles and decodes the common
// entities: enough to show "Bad Request (400)" or a framework's error
// title from an error page.
func htmlText(s string) string {
	var b strings.Builder
	lower := strings.ToLower(s)
	for i := 0; i < len(s); {
		switch {
		case strings.HasPrefix(lower[i:], "<!--"):
			end := strings.Index(lower[i:], "-->")
			if end < 0 {
				return b.String()
			}
			i += end + 3
		case strings.HasPrefix(lower[i:], "<script"), strings.HasPrefix(lower[i:], "<style"):
			tag := "</script"
			if strings.HasPrefix(lower[i:], "<style") {
				tag = "</style"
			}
			end := strings.Index(lower[i:], tag)
			if end < 0 {
				return b.String()
			}
			i += end + len(tag)
			if gt := strings.IndexByte(s[i:], '>'); gt >= 0 {
				i += gt + 1
			}
			b.WriteByte(' ')
		case s[i] == '<':
			end := strings.IndexByte(s[i:], '>')
			if end < 0 {
				return b.String()
			}
			b.WriteByte(' ')
			i += end + 1
		default:
			b.WriteByte(s[i])
			i++
		}
	}
	return htmlEntities.Replace(b.String())
}

var htmlEntities = strings.NewReplacer("&amp;", "&", "&lt;", "<", "&gt;", ">", "&quot;", `"`, "&#39;", "'", "&#x27;", "'", "&nbsp;", " ")

// simplify turns network errors into short, stable messages for the UI.
func simplify(err error) error {
	msg := err.Error()
	switch {
	case errors.Is(err, context.DeadlineExceeded) || strings.Contains(msg, "Client.Timeout") || strings.Contains(msg, "i/o timeout"):
		return errors.New("timed out")
	case strings.Contains(msg, "connection refused"):
		return errors.New("connection refused")
	case strings.Contains(msg, "connection reset"):
		return errors.New("connection reset")
	case strings.Contains(msg, "no route to host"):
		return errors.New("no route to host")
	}
	return errors.New(state.Clip(msg, 200))
}
