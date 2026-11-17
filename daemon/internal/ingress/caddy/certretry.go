package caddy

import (
	"context"
	"crypto/tls"
	"errors"
	"fmt"
	"net"
	"slices"
	"strconv"
	"time"

	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// "Retry now" for certificates (task cert_retry, docs/03 §7): Caddy retries
// a failed issuance on its own, with a growing backoff. A retry reloads the
// running config, which starts the managed hosts' issuance afresh, and
// opens a TLS connection to the edge itself for each host, which starts an
// on-demand host's issuance. The outcome shows in the certificate statuses
// as usual. Hosts the CA rate-limited are skipped until its retryAfter.

// certRetryGap is the shortest time between two reloads for retries; a
// retry within it only opens the connections.
const certRetryGap = time.Minute

// certRetryHandshake bounds a retry's TLS connection (an on-demand
// issuance happens during the handshake).
const certRetryHandshake = 3 * time.Minute

// ErrCertRetryNothing means every host asked for is rate-limited by its CA.
var ErrCertRetryNothing = errors.New("caddy: every host is waiting for its CA's rate limit")

// RetryCertificates starts obtaining certificates for hosts again. Every
// host must be routed by this edge. It returns the hosts it started; the
// handshakes go on in the background (bounded by ctx's parent lifetime
// through bg).
func (m *Manager) RetryCertificates(ctx, bg context.Context, hosts []state.Hostname) ([]state.Hostname, error) {
	m.mu.Lock()
	routed := Hosts(m.lastRoutes)
	cfg, certs, last := m.appliedCfg, slices.Clone(m.certs), m.certRetryAt
	m.mu.Unlock()
	if cfg == nil {
		return nil, errors.New("caddy: no route config is applied yet; certificates are obtained once it is")
	}
	now := m.o.Now()
	var start []state.Hostname
	var waitUntil time.Time
	for _, h := range hosts {
		if !slices.Contains(routed, string(h)) {
			return nil, fmt.Errorf("caddy: %s is not routed by this server", h)
		}
		i := slices.IndexFunc(certs, func(c state.CertificateStatus) bool { return c.Host == h })
		if i >= 0 && certs[i].RetryAfter != nil && certs[i].RetryAfter.After(now) {
			if waitUntil.IsZero() || certs[i].RetryAfter.Before(waitUntil) {
				waitUntil = *certs[i].RetryAfter
			}
			continue
		}
		start = append(start, h)
	}
	if len(start) == 0 {
		return nil, fmt.Errorf("%w (until %s)", ErrCertRetryNothing, waitUntil.UTC().Format(time.RFC3339))
	}
	if now.Sub(last) >= certRetryGap {
		r, ok := m.o.Admin.(Reloader)
		if !ok {
			return nil, errors.New("caddy: the admin client cannot reload")
		}
		if err := r.Reload(ctx, cfg); err != nil {
			return nil, fmt.Errorf("caddy: reload for the retry: %w", err)
		}
		m.mu.Lock()
		m.certRetryAt = now
		m.mu.Unlock()
	}
	port := m.o.Render.HTTPSPort
	if port == 0 {
		port = 443
	}
	for _, h := range start {
		go m.knock(bg, string(h), port)
	}
	m.o.Log.Info("certificate retry started", "hosts", start)
	select { // fresh statuses soon
	case m.certKick <- struct{}{}:
	default:
	}
	return start, nil
}

// knock opens a TLS connection to the edge for host, so Caddy obtains an
// on-demand certificate now. The certificate itself isn't checked: the
// connection only asks for it.
func (m *Manager) knock(ctx context.Context, host string, port int) {
	ctx, cancel := context.WithTimeout(ctx, certRetryHandshake)
	defer cancel()
	d := tls.Dialer{Config: &tls.Config{ServerName: host, InsecureSkipVerify: true, MinVersion: tls.VersionTLS12}} //nolint:gosec // only triggers issuance
	c, err := d.DialContext(ctx, "tcp", net.JoinHostPort("127.0.0.1", strconv.Itoa(port)))
	if err != nil {
		m.o.Log.Debug("certificate retry connection", "host", host, "err", err)
		return
	}
	_ = c.Close()
}
