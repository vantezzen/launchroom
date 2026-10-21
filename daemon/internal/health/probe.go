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
	"strconv"
	"strings"
	"time"

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
	resp, err := probeClient.Do(req)
	if err != nil {
		return simplify(err)
	}
	_, _ = io.Copy(io.Discard, io.LimitReader(resp.Body, 4096))
	_ = resp.Body.Close()
	if resp.StatusCode < lo || resp.StatusCode > hi {
		return fmt.Errorf("HTTP %d (expected %s)", resp.StatusCode, expect)
	}
	return nil
}

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
	if len(msg) > 200 {
		msg = msg[:200]
	}
	return errors.New(msg)
}
