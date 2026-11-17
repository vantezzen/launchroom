package watchdog

import (
	"bufio"
	"context"
	"crypto/tls"
	"encoding/base64"
	"errors"
	"net"
	"net/http"
	"net/http/httptest"
	"net/netip"
	"strings"
	"sync"
	"testing"
	"time"

	"github.com/vantezzen/launchroom/daemon/internal/secret"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// fakeSMTP is a minimal ESMTP server: STARTTLS (when offered), AUTH PLAIN,
// one message.
type fakeSMTP struct {
	starttls bool
	cert     tls.Certificate
	mu       sync.Mutex
	auth     string
	data     string
	tlsUsed  bool
}

func (f *fakeSMTP) serve(t *testing.T) net.Listener {
	var lc net.ListenConfig
	l, err := lc.Listen(t.Context(), "tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { _ = l.Close() })
	go func() {
		for {
			c, err := l.Accept()
			if err != nil {
				return
			}
			go f.session(c)
		}
	}()
	return l
}

func (f *fakeSMTP) session(c net.Conn) {
	defer func() { _ = c.Close() }()
	r, w := bufio.NewReader(c), c
	say := func(s string) { _, _ = w.Write([]byte(s + "\r\n")) }
	say("220 example.com ESMTP")
	secure := false
	for {
		line, err := r.ReadString('\n')
		if err != nil {
			return
		}
		cmd := strings.TrimSpace(line)
		switch up := strings.ToUpper(cmd); {
		case strings.HasPrefix(up, "EHLO"):
			if f.starttls && !secure {
				say("250-example.com\r\n250-STARTTLS\r\n250 AUTH PLAIN")
			} else {
				say("250-example.com\r\n250 AUTH PLAIN")
			}
		case up == "STARTTLS" && f.starttls:
			say("220 go ahead")
			tc := tls.Server(c, &tls.Config{Certificates: []tls.Certificate{f.cert}, MinVersion: tls.VersionTLS12})
			if tc.HandshakeContext(context.Background()) != nil {
				return
			}
			c, r, w, secure = tc, bufio.NewReader(tc), tc, true
			f.mu.Lock()
			f.tlsUsed = true
			f.mu.Unlock()
		case strings.HasPrefix(up, "AUTH PLAIN "):
			raw, _ := base64.StdEncoding.DecodeString(strings.TrimPrefix(cmd, "AUTH PLAIN "))
			f.mu.Lock()
			f.auth = string(raw)
			f.mu.Unlock()
			say("235 ok")
		case strings.HasPrefix(up, "MAIL"), strings.HasPrefix(up, "RCPT"):
			say("250 ok")
		case up == "DATA":
			say("354 go")
			var b strings.Builder
			for {
				l, err := r.ReadString('\n')
				if err != nil || l == ".\r\n" {
					break
				}
				b.WriteString(l)
			}
			f.mu.Lock()
			f.data = b.String()
			f.mu.Unlock()
			say("250 queued")
		case up == "QUIT":
			say("221 bye")
			return
		default:
			say("502 no")
		}
	}
}

func TestSMTPRequiresSTARTTLS(t *testing.T) {
	ts := httptest.NewTLSServer(http.NotFoundHandler()) // its certificate covers example.com
	defer ts.Close()
	pool := ts.Client().Transport.(*http.Transport).TLSClientConfig.RootCAs
	ch := &state.FallbackChannel{Kind: state.FallbackEmail, ServerLabel: "server-1", SMTP: &state.FallbackSMTP{
		Host: "example.com", Port: 587, From: "launchroom@example.com", To: []state.EmailAddress{"ops@example.com"},
	}}
	n := notification{Role: state.WatchdogControlPlane, Kind: state.NoticeDown, DashboardHost: "lr.example.com", At: time.Now()}
	egress := func(l net.Listener) Egress {
		return Egress{
			Resolve: func(context.Context, string) ([]netip.Addr, error) {
				return []netip.Addr{netip.MustParseAddr(publicIP)}, nil
			},
			Dial: func(ctx context.Context, network, _ string) (net.Conn, error) {
				var d net.Dialer
				return d.DialContext(ctx, network, l.Addr().String())
			},
			RootCAs: pool,
		}
	}
	values := map[string]secret.Secret{"password": "hunter22-secret"}

	plainOnly := &fakeSMTP{}
	if err := egress(plainOnly.serve(t)).send(t.Context(), ch, values, n); !errors.Is(err, errNoSTARTTLS) {
		t.Fatalf("without STARTTLS: %v", err)
	}
	plainOnly.mu.Lock()
	if plainOnly.auth != "" || plainOnly.data != "" {
		t.Fatal("credentials or message sent in the clear")
	}
	plainOnly.mu.Unlock()

	good := &fakeSMTP{starttls: true, cert: ts.TLS.Certificates[0]}
	if err := egress(good.serve(t)).send(t.Context(), ch, values, n); err != nil {
		t.Fatalf("with STARTTLS: %v", err)
	}
	good.mu.Lock()
	defer good.mu.Unlock()
	if !good.tlsUsed || good.auth != "\x00launchroom@example.com\x00hunter22-secret" {
		t.Fatalf("tls %v auth %q", good.tlsUsed, good.auth)
	}
	if !strings.Contains(good.data, "Subject: launchroom: the dashboard can't be reached") || !strings.Contains(good.data, "To: ops@example.com") || !strings.Contains(good.data, "lr.example.com") {
		t.Fatalf("message %q", good.data)
	}
	// A private mail server is refused like a private webhook.
	refused := egress(good.serve(t))
	refused.Resolve = func(context.Context, string) ([]netip.Addr, error) {
		return []netip.Addr{netip.MustParseAddr("10.0.0.1")}, nil
	}
	if err := refused.send(t.Context(), ch, values, n); !errors.Is(err, errPrivateHost) {
		t.Fatalf("private smtp: %v", err)
	}
}
