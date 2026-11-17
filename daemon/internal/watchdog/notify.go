package watchdog

import (
	"bytes"
	"context"
	"crypto/tls"
	"crypto/x509"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net"
	"net/http"
	"net/netip"
	"net/smtp"
	"net/url"
	"strconv"
	"strings"
	"time"

	"github.com/vantezzen/launchroom/daemon/internal/secret"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// sendTimeout bounds one fallback message (connect, TLS, answer).
const sendTimeout = 10 * time.Second

// Egress is how fallback messages leave the server. The zero value uses
// the system resolver and dials directly; tests replace them.
type Egress struct {
	// Resolve returns the addresses of host.
	Resolve func(ctx context.Context, host string) ([]netip.Addr, error)
	// Dial connects to an already-checked address.
	Dial func(ctx context.Context, network, addr string) (net.Conn, error)
	// RootCAs verify the channel's TLS certificate (nil: the system roots).
	RootCAs *x509.CertPool
}

func (e Egress) resolve(ctx context.Context, host string) ([]netip.Addr, error) {
	if e.Resolve != nil {
		return e.Resolve(ctx, host)
	}
	return net.DefaultResolver.LookupNetIP(ctx, "ip", host)
}

func (e Egress) dial(ctx context.Context, network, addr string) (net.Conn, error) {
	if e.Dial != nil {
		return e.Dial(ctx, network, addr)
	}
	var d net.Dialer
	return d.DialContext(ctx, network, addr)
}

// sendError is a secret-free reason a message wasn't sent: it never holds
// the URL, the token or the server's answer.
type sendError string

func (e sendError) Error() string { return string(e) }

const (
	errPrivateHost = sendError("the channel's host resolves to a private address; save the channel with private hosts allowed to use it")
	errResolve     = sendError("the channel's host could not be resolved")
	errConnect     = sendError("the channel could not be reached")
	errTLS         = sendError("the channel's TLS certificate was not accepted")
	errTimeout     = sendError("the channel did not answer in time")
	errNotHTTPS    = sendError("the channel's url must be https without credentials")
	errNoSTARTTLS  = sendError("the mail server does not offer STARTTLS")
	errSMTPAuth    = sendError("the mail server refused the login")
	errSMTP        = sendError("the mail server refused the message")
	errChannel     = sendError("the channel could not be opened")
)

// dialChecked resolves host, refuses private addresses (unless allowed)
// and dials the first allowed address itself: the address checked is the
// address connected to, so a second DNS answer can't point elsewhere.
func (e Egress) dialChecked(ctx context.Context, host, port string, allowPrivate bool) (net.Conn, error) {
	var addrs []netip.Addr
	if a, err := netip.ParseAddr(host); err == nil {
		addrs = []netip.Addr{a}
	} else {
		rs, err := e.resolve(ctx, host)
		if err != nil || len(rs) == 0 {
			return nil, errResolve
		}
		addrs = rs
	}
	var last error = errPrivateHost
	for _, a := range addrs {
		a = a.Unmap()
		if !allowPrivate && !publicAddr(a) {
			continue
		}
		c, err := e.dial(ctx, "tcp", net.JoinHostPort(a.String(), port))
		if err == nil {
			return c, nil
		}
		last = errConnect
		if errors.Is(err, context.DeadlineExceeded) {
			last = errTimeout
		}
	}
	return nil, last
}

var nonPublic = []netip.Prefix{
	netip.MustParsePrefix("0.0.0.0/8"),
	netip.MustParsePrefix("100.64.0.0/10"), // CGNAT, the mesh
	netip.MustParsePrefix("192.0.0.0/24"),
	netip.MustParsePrefix("198.18.0.0/15"),
	netip.MustParsePrefix("240.0.0.0/4"),
	netip.MustParsePrefix("64:ff9b:1::/48"),
}

// publicAddr reports whether a is a global unicast address outside the
// private, loopback, link-local, CGNAT, ULA and reserved ranges.
func publicAddr(a netip.Addr) bool {
	if !a.IsValid() || !a.IsGlobalUnicast() || a.IsPrivate() || a.IsLoopback() || a.IsLinkLocalUnicast() || a.IsUnspecified() || a.IsMulticast() {
		return false
	}
	for _, p := range nonPublic {
		if p.Contains(a) {
			return false
		}
	}
	return true
}

// Message templates: constant text naming only the server and the
// dashboard's address. Never errors, log text or anything else.
var (
	subjects = map[state.FallbackNoticeKind]string{
		state.NoticeDown:      "launchroom: the dashboard can't be reached",
		state.NoticeStillDown: "launchroom: the dashboard still can't be reached",
		state.NoticeBack:      "launchroom: the dashboard is back",
	}
	bodiesControlPlane = map[state.FallbackNoticeKind]string{
		state.NoticeDown:      "The launchroom dashboard at %[1]s isn't responding. %[2]s, the server it runs on, has been checking for 5 minutes and restarts what it can. Your apps keep running.",
		state.NoticeStillDown: "The launchroom dashboard at %[1]s still isn't responding, as seen from %[2]s. Your apps keep running.",
		state.NoticeBack:      "The launchroom dashboard at %[1]s is responding again, as seen from %[2]s.",
	}
	bodiesPeer = map[state.FallbackNoticeKind]string{
		state.NoticeDown:      "%[2]s can't reach the launchroom dashboard at %[1]s and has been trying for 10 minutes. Apps on %[2]s keep running.",
		state.NoticeStillDown: "%[2]s still can't reach the launchroom dashboard at %[1]s. Apps on %[2]s keep running.",
		state.NoticeBack:      "%[2]s reaches the launchroom dashboard at %[1]s again.",
	}
)

// message renders a notice's subject and text.
func message(role state.WatchdogRole, kind state.FallbackNoticeKind, dashboardHost, serverLabel string) (string, string) {
	bodies := bodiesControlPlane
	if role == state.WatchdogPeer {
		bodies = bodiesPeer
	}
	if dashboardHost == "" {
		dashboardHost = "its usual address"
	}
	return subjects[kind], fmt.Sprintf(bodies[kind], plain(dashboardHost), plain(serverLabel))
}

// plain drops control characters (the label is validated text already).
func plain(s string) string {
	return strings.Map(func(r rune) rune {
		if r < 0x20 || r == 0x7f {
			return -1
		}
		return r
	}, s)
}

// slackEscape escapes Slack's control sequences (<!channel>, links).
var slackEscape = strings.NewReplacer("&", "&amp;", "<", "&lt;", ">", "&gt;")

// notification is one message to send.
type notification struct {
	Role          state.WatchdogRole
	Kind          state.FallbackNoticeKind
	DashboardHost string
	At            time.Time
}

// send delivers n through ch with the opened secret values.
func (e Egress) send(ctx context.Context, ch *state.FallbackChannel, values map[string]secret.Secret, n notification) error {
	plainValues := make(map[string]string, len(values))
	for k, v := range values {
		plainValues[k] = v.Reveal()
	}
	if err := state.CheckFallbackSecret(ch.Kind, plainValues); err != nil {
		return errChannel
	}
	ctx, cancel := context.WithTimeout(ctx, sendTimeout)
	defer cancel()
	subject, text := message(n.Role, n.Kind, n.DashboardHost, ch.ServerLabel)
	if ch.Kind == state.FallbackEmail {
		if ch.SMTP == nil {
			return errChannel
		}
		user := plainValues["username"]
		if user == "" {
			user = string(ch.SMTP.From)
		}
		return e.sendMail(ctx, ch.SMTP, ch.AllowPrivateHosts, user, values["password"], subject, text, n.At)
	}
	var body any
	switch ch.Kind {
	case state.FallbackSlack:
		body = map[string]any{"text": slackEscape.Replace(text)}
	case state.FallbackDiscord:
		body = map[string]any{"content": text, "allowed_mentions": map[string]any{"parse": []string{}}}
	default:
		body = map[string]any{
			"event": "launchroom.dashboard_unreachable", "notice": string(n.Kind), "role": string(n.Role),
			"server": ch.ServerLabel, "dashboard": n.DashboardHost, "subject": subject, "text": text,
			"at": n.At.UTC().Format(time.RFC3339),
		}
	}
	raw, err := json.Marshal(body)
	if err != nil {
		return errChannel
	}
	return e.post(ctx, values["url"], ch.AllowPrivateHosts, raw)
}

// post sends a JSON body to an https URL: no proxy, no redirects, the
// address checked at dial time.
func (e Egress) post(ctx context.Context, rawURL secret.Secret, allowPrivate bool, body []byte) error {
	u, err := url.Parse(rawURL.Reveal())
	if err != nil || u.Scheme != "https" || u.User != nil || u.Hostname() == "" {
		return errNotHTTPS
	}
	tr := &http.Transport{
		Proxy: nil,
		DialContext: func(ctx context.Context, _, addr string) (net.Conn, error) {
			host, port, err := net.SplitHostPort(addr)
			if err != nil {
				return nil, errConnect
			}
			return e.dialChecked(ctx, host, port, allowPrivate)
		},
		TLSClientConfig:       &tls.Config{MinVersion: tls.VersionTLS12, RootCAs: e.RootCAs},
		TLSHandshakeTimeout:   sendTimeout,
		ResponseHeaderTimeout: sendTimeout,
		DisableKeepAlives:     true,
		ForceAttemptHTTP2:     true,
	}
	defer tr.CloseIdleConnections()
	c := &http.Client{
		Transport:     tr,
		Timeout:       sendTimeout,
		CheckRedirect: func(*http.Request, []*http.Request) error { return http.ErrUseLastResponse },
	}
	req, err := http.NewRequestWithContext(ctx, http.MethodPost, u.String(), bytes.NewReader(body))
	if err != nil {
		return errNotHTTPS
	}
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("User-Agent", "launchroomd-watchdog")
	res, err := c.Do(req)
	if err != nil {
		return classify(err)
	}
	defer func() { _ = res.Body.Close() }()
	_, _ = io.Copy(io.Discard, io.LimitReader(res.Body, 64<<10))
	if res.StatusCode/100 != 2 {
		return sendError("the channel answered HTTP " + strconv.Itoa(res.StatusCode))
	}
	return nil
}

// classify maps a client error to a secret-free reason (url.Error carries the URL).
func classify(err error) error {
	var se sendError
	switch {
	case errors.As(err, &se):
		return se
	case errors.Is(err, context.DeadlineExceeded):
		return errTimeout
	}
	var cv *tls.CertificateVerificationError
	var ua x509.UnknownAuthorityError
	var hn x509.HostnameError
	if errors.As(err, &cv) || errors.As(err, &ua) || errors.As(err, &hn) {
		return errTLS
	}
	var ne net.Error
	if errors.As(err, &ne) && ne.Timeout() {
		return errTimeout
	}
	return errConnect
}

// sendMail sends a plain-text message over SMTP: implicit TLS on 465,
// STARTTLS required on every other port, then AUTH PLAIN.
func (e Egress) sendMail(ctx context.Context, s *state.FallbackSMTP, allowPrivate bool, user string, password secret.Secret, subject, text string, at time.Time) error {
	host, port := string(s.Host), strconv.Itoa(int(s.Port))
	conn, err := e.dialChecked(ctx, host, port, allowPrivate)
	if err != nil {
		return err
	}
	if dl, ok := ctx.Deadline(); ok {
		_ = conn.SetDeadline(dl)
	}
	tcfg := &tls.Config{ServerName: host, MinVersion: tls.VersionTLS12, RootCAs: e.RootCAs}
	if s.Port == 465 {
		tc := tls.Client(conn, tcfg)
		if err := tc.HandshakeContext(ctx); err != nil {
			_ = conn.Close()
			return classify(err)
		}
		conn = tc
	}
	c, err := smtp.NewClient(conn, host)
	if err != nil {
		_ = conn.Close()
		return classify(err)
	}
	defer func() { _ = c.Close() }()
	if s.Port != 465 {
		if ok, _ := c.Extension("STARTTLS"); !ok {
			return errNoSTARTTLS
		}
		if err := c.StartTLS(tcfg); err != nil {
			return classify(err)
		}
	}
	if err := c.Auth(smtp.PlainAuth("", user, password.Reveal(), host)); err != nil {
		return errSMTPAuth
	}
	if err := c.Mail(string(s.From)); err != nil {
		return errSMTP
	}
	for _, to := range s.To {
		if err := c.Rcpt(string(to)); err != nil {
			return errSMTP
		}
	}
	w, err := c.Data()
	if err != nil {
		return errSMTP
	}
	if _, err := w.Write(mailMessage(s, subject, text, at)); err != nil {
		return errSMTP
	}
	if err := w.Close(); err != nil {
		return errSMTP
	}
	_ = c.Quit()
	return nil
}

func mailMessage(s *state.FallbackSMTP, subject, text string, at time.Time) []byte {
	to := make([]string, 0, len(s.To))
	for _, a := range s.To {
		to = append(to, string(a))
	}
	var b strings.Builder
	fmt.Fprintf(&b, "From: %s\r\n", s.From)
	fmt.Fprintf(&b, "To: %s\r\n", strings.Join(to, ", "))
	fmt.Fprintf(&b, "Subject: %s\r\n", plain(subject))
	fmt.Fprintf(&b, "Date: %s\r\n", at.UTC().Format(time.RFC1123Z))
	b.WriteString("MIME-Version: 1.0\r\nContent-Type: text/plain; charset=utf-8\r\nContent-Transfer-Encoding: 8bit\r\nAuto-Submitted: auto-generated\r\n\r\n")
	b.WriteString(text)
	b.WriteString("\r\n")
	return []byte(b.String())
}
