package caddy

import (
	"encoding/json"
	"regexp"
	"slices"
	"strings"
	"time"
	"unicode"

	"github.com/vantezzen/launchroom/daemon/internal/ingress/certstore"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// certEvent is what Caddy's log said about obtaining a certificate for one
// identifier (a host or "*.parent").
type certEvent struct {
	lastErr   string
	lastErrAt time.Time
	lastOKAt  time.Time
	// kind classifies lastErr; retryAfter is the CA's rate-limit end.
	kind       state.CertFailureKind
	retryAfter *time.Time
}

// caddyLogLine is the subset of Caddy's JSON log lines (certmagic, loggers
// "tls.obtain" / "tls.renew") that carry certificate outcomes.
type caddyLogLine struct {
	Logger     string `json:"logger"`
	Msg        string `json:"msg"`
	Identifier string `json:"identifier"`
	Error      string `json:"error"`
}

// parseCertLog reads one Caddy log line: ok=true for a success, ok=false
// with errMsg for a failure; matched=false for everything else.
func parseCertLog(line string) (identifier string, ok bool, errMsg string, matched bool) {
	id, ok, raw, matched := parseCertLogRaw(line)
	if matched && !ok {
		return id, false, cleanError(raw), true
	}
	return id, ok, "", matched
}

func parseCertLogRaw(line string) (identifier string, ok bool, rawErr string, matched bool) {
	line = strings.TrimSpace(line)
	if !strings.HasPrefix(line, "{") || !strings.Contains(line, `"identifier"`) {
		return "", false, "", false
	}
	var l caddyLogLine
	if json.Unmarshal([]byte(line), &l) != nil || !strings.HasPrefix(l.Logger, "tls") || l.Identifier == "" {
		return "", false, "", false
	}
	id := strings.ToLower(l.Identifier)
	switch l.Msg {
	case "certificate obtained successfully", "certificate renewed successfully":
		return id, true, "", true
	case "could not get certificate from issuer":
		return id, false, l.Error, true
	}
	return "", false, "", false
}

// reRetryAfter is the end of an ACME rate limit as Let's Encrypt words it
// ("…, retry after 2026-11-17 14:05:00 UTC: see …").
var reRetryAfter = regexp.MustCompile(`(?i)retry after ([0-9]{4}-[0-9]{2}-[0-9]{2}[ T][0-9]{2}:[0-9]{2}:[0-9]{2}) ?(?:UTC|Z)`)

// ClassifyCertError says why a certificate could not be obtained, from the
// ACME problem type in Caddy's error (RFC 8555 §6.7) and its wording;
// retryAfter is set for a rate limit that names its end.
func ClassifyCertError(msg string) (kind state.CertFailureKind, retryAfter *time.Time) {
	m := strings.ToLower(msg)
	has := func(subs ...string) bool {
		for _, s := range subs {
			if strings.Contains(m, s) {
				return true
			}
		}
		return false
	}
	switch {
	case has("acme:error:ratelimited", "too many certificates", "too many failed authorizations", "rate limit"):
		if g := reRetryAfter.FindStringSubmatch(msg); g != nil {
			if t, err := time.Parse("2006-01-02 15:04:05", strings.Replace(g[1], "T", " ", 1)); err == nil {
				return state.CertRateLimited, &t
			}
		}
		return state.CertRateLimited, nil
	case has("acme:error:caa", "caa record"):
		return state.CertCAA, nil
	case has("presenting for challenge", "adding temporary record", "solving challenges: dns", "dns provider", "cleaning up solver"):
		return state.CertDNSProvider, nil
	case has("timeout during connect", "timeout after connect", "i/o timeout", "context deadline exceeded", "timed out"):
		return state.CertTimeout, nil
	case has("acme:error:unauthorized", "acme:error:connection", "acme:error:dns", "acme:error:incorrectresponse",
		"invalid response from", "no valid ip addresses found", "nxdomain", "during secondary validation"):
		return state.CertDNSMismatch, nil
	}
	return state.CertOtherError, nil
}

// cleanError keeps an error printable and short (it is reported to the dashboard).
func cleanError(s string) string {
	s = strings.Map(func(r rune) rune {
		if unicode.IsPrint(r) {
			return r
		}
		return ' '
	}, strings.TrimSpace(s))
	if s == "" {
		s = "certificate could not be obtained"
	}
	return state.Shorten(s, 1021)
}

// CertificateStatuses derives the certificate state of every routed host
// from the stored certificates and Caddy's issuance log. Pure; sorted by host.
func CertificateStatuses(routes []Route, t TLS, ix *certstore.Index, events map[string]certEvent, now time.Time) []state.CertificateStatus {
	var hosts []state.Hostname
	for _, r := range routes {
		hosts = append(hosts, r.Spec.Hosts...)
	}
	slices.Sort(hosts)
	hosts = slices.Compact(hosts)
	if len(hosts) > 4096 {
		hosts = hosts[:4096]
	}
	wildcardRoute := map[state.Hostname]bool{}
	for _, r := range routes {
		if r.Spec.TLS == state.TLSWildcard {
			for _, h := range r.Spec.Hosts {
				wildcardRoute[h] = true
			}
		}
	}
	out := make([]state.CertificateStatus, 0, len(hosts))
	for _, h := range hosts {
		st := state.CertificateStatus{Host: h, State: state.CertPending}
		ev := events[string(h)]
		if wildcardRoute[h] {
			if w := t.DNS.WildcardFor(h); w != "" {
				if wev, ok := events[w]; ok {
					ev = wev
				}
			}
		}
		var c *certstore.Cert
		if ix != nil {
			c, st.Wildcard = ix.Lookup(string(h), now)
		}
		if c != nil {
			nb, na := c.Leaf.NotBefore.UTC(), c.Leaf.NotAfter.UTC()
			st.Issuer, st.NotBefore, st.NotAfter = certstore.Issuer(c.Leaf), &nb, &na
			if now.Before(na) {
				st.State = state.CertIssued
			} else {
				st.State = state.CertFailed
				if ev.lastErr == "" {
					st.LastError = "certificate expired on " + na.Format(time.RFC3339)
				}
			}
		} else {
			st.Wildcard = false
		}
		stale := c != nil && ev.lastErrAt.Before(c.Leaf.NotBefore) // the error predates the certificate
		if ev.lastErr != "" && ev.lastErrAt.After(ev.lastOKAt) && !stale {
			at := ev.lastErrAt.UTC()
			st.LastError, st.LastErrorAt = ev.lastErr, &at
			st.FailureKind = ev.kind
			if ev.retryAfter != nil && ev.retryAfter.After(now) {
				ra := ev.retryAfter.UTC()
				st.RetryAfter = &ra
			}
			if c == nil {
				st.State = state.CertFailed
			}
		}
		out = append(out, st)
	}
	return out
}
