package controlplane

import (
	"context"
	"crypto/tls"
	"crypto/x509"
	"net"
	"net/http"
	"net/http/httptest"
	"net/url"
	"os"
	"path/filepath"
	"strings"
	"sync"
	"sync/atomic"
	"testing"
	"time"

	"github.com/vantezzen/launchroom/daemon/internal/identity"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// network is a fake internet: host:port → listener address, with the
// httptest TLS certificate (valid for example.com) trusted.
type network struct {
	mu    sync.Mutex
	hosts map[string]string
	roots *x509.CertPool
}

func (n *network) add(hostport, addr string) {
	n.mu.Lock()
	defer n.mu.Unlock()
	n.hosts[hostport] = addr
}

func (n *network) transport() http.RoundTripper {
	return &http.Transport{
		TLSClientConfig: &tls.Config{RootCAs: n.roots, MinVersion: tls.VersionTLS12},
		DialContext: func(ctx context.Context, network, addr string) (net.Conn, error) {
			n.mu.Lock()
			target, ok := n.hosts[addr]
			n.mu.Unlock()
			if !ok {
				return nil, &net.OpError{Op: "dial", Net: network, Err: &net.DNSError{Err: "no such host", Name: addr, IsNotFound: true}}
			}
			var d net.Dialer
			return d.DialContext(ctx, network, target)
		},
	}
}

// followSetup is a daemon whose configured URL is http://fallback.test
// (fallback) and a canonical origin https://example.com (preferred), both
// signed with the same control-plane key.
type followSetup struct {
	id        *identity.Identity
	fallback  *fakeCP
	preferred *fakeCP
	tlsSrv    *httptest.Server
	net       *network
	clock     *fakeClock
	client    *Client
}

type fakeClock struct {
	mu  sync.Mutex
	now time.Time
}

func (c *fakeClock) Now() time.Time { c.mu.Lock(); defer c.mu.Unlock(); return c.now }
func (c *fakeClock) Add(d time.Duration) {
	c.mu.Lock()
	defer c.mu.Unlock()
	c.now = c.now.Add(d)
}

func configBody(t *testing.T) string { t.Helper(); return fixture(t, "daemon-config.json") }

func newFollowSetup(t *testing.T) *followSetup {
	t.Helper()
	id, _ := identity.Generate()
	fb, fbSrv := newFakeCP(t, id.SigningPublicKey())
	pf := &fakeCP{t: t, daemonPub: id.SigningPublicKey(), cpKey: fb.cpKey, other: fb.other}
	tlsSrv := httptest.NewTLSServer(http.HandlerFunc(pf.serve))
	t.Cleanup(tlsSrv.Close)
	roots := x509.NewCertPool()
	roots.AddCert(tlsSrv.Certificate())
	n := &network{hosts: map[string]string{}, roots: roots}
	n.add("fallback.test:80", strings.TrimPrefix(fbSrv.URL, "http://"))
	n.add("example.com:443", strings.TrimPrefix(tlsSrv.URL, "https://"))

	ok := func(*http.Request, []byte) reply { return reply{status: 200, body: configBody(t)} }
	fb.set(ok)
	pf.set(ok)

	clock := &fakeClock{now: time.Date(2026, 10, 19, 12, 0, 0, 0, time.UTC)}
	signer, _ := id.NewSigner(string(serverID))
	base, _ := url.Parse("http://fallback.test")
	c, err := New(Options{
		BaseURL: base, Signer: signer, Trusted: identity.KeySet{kid: fb.pub()}, DaemonVersion: "0.1.0",
		Timeout: 5 * time.Second, Transport: n.transport(), Now: clock.Now,
	})
	if err != nil {
		t.Fatal(err)
	}
	return &followSetup{id: id, fallback: fb, preferred: pf, tlsSrv: tlsSrv, net: n, clock: clock, client: c}
}

func (s *followSetup) count(f *fakeCP) int {
	f.mu.Lock()
	defer f.mu.Unlock()
	return len(f.requests)
}

func mustParse(t *testing.T, raw string) *url.URL {
	t.Helper()
	u, err := url.Parse(raw)
	if err != nil {
		t.Fatal(err)
	}
	return u
}

func TestPreferredFailover(t *testing.T) {
	cases := map[string]func(s *followSetup){
		"bad gateway": func(s *followSetup) {
			s.preferred.set(func(*http.Request, []byte) reply { return reply{status: 502, body: "no upstream", sign: "none"} })
		},
		"not found": func(s *followSetup) {
			s.preferred.set(func(*http.Request, []byte) reply { return reply{status: 404, body: "nope", sign: "none"} })
		},
		"unsigned 2xx": func(s *followSetup) {
			s.preferred.set(func(*http.Request, []byte) reply { return reply{status: 200, body: `{"foreign":true}`, sign: "none"} })
		},
		"signed by another key": func(s *followSetup) {
			s.preferred.set(func(*http.Request, []byte) reply { return reply{status: 200, body: `{"x":1}`, sign: "other"} })
		},
		"unreachable": func(s *followSetup) {
			s.net.add("example.com:443", "127.0.0.1:1")
		},
	}
	for name, breakIt := range cases {
		t.Run(name, func(t *testing.T) {
			s := newFollowSetup(t)
			s.client.SetPreferred(mustParse(t, "https://example.com"))
			breakIt(s)
			if _, err := s.client.FetchConfig(t.Context()); err != nil {
				t.Fatalf("failover should answer from the configured URL: %v", err)
			}
			if s.count(s.fallback) != 1 {
				t.Fatalf("fallback got %d requests", s.count(s.fallback))
			}
			if s.fallback.last().keyID != string(serverID) {
				t.Fatal("fallback request not signed with the server key")
			}
			if down, _ := s.client.Failover(); !down {
				t.Fatal("preferred should be cooling down")
			}
			if got := s.client.LastActive().String(); got != "http://fallback.test" {
				t.Fatalf("active = %s", got)
			}
			if got := s.client.ActiveBase().String(); got != "http://fallback.test" {
				t.Fatalf("active base during cooldown = %s", got)
			}
			before := s.count(s.preferred)
			if _, err := s.client.FetchConfig(t.Context()); err != nil {
				t.Fatal(err)
			}
			if s.count(s.preferred) != before {
				t.Fatal("preferred contacted during the cooldown")
			}
		})
	}
}

func TestPreferredRecovers(t *testing.T) {
	s := newFollowSetup(t)
	s.client.SetPreferred(mustParse(t, "https://example.com"))
	s.preferred.set(func(*http.Request, []byte) reply { return reply{status: 503, body: "", sign: "none"} })
	if _, err := s.client.FetchConfig(t.Context()); err != nil {
		t.Fatal(err)
	}
	s.preferred.set(func(*http.Request, []byte) reply { return reply{status: 200, body: configBody(t)} })
	s.clock.Add(PreferredCooldown + time.Second)
	if _, err := s.client.FetchConfig(t.Context()); err != nil {
		t.Fatal(err)
	}
	if down, _ := s.client.Failover(); down {
		t.Fatal("preferred should be back")
	}
	if got := s.client.LastActive().String(); got != "https://example.com" {
		t.Fatalf("active = %s", got)
	}
	if s.preferred.last().path != "/daemon/v1/config" || s.preferred.last().keyID != string(serverID) {
		t.Fatalf("preferred request = %+v", s.preferred.last())
	}
}

// A dashboard answer such as 403 is the dashboard's: no failover, no cooldown.
func TestPreferredClientErrorIsFinal(t *testing.T) {
	s := newFollowSetup(t)
	s.client.SetPreferred(mustParse(t, "https://example.com"))
	s.preferred.set(func(*http.Request, []byte) reply {
		return reply{status: 403, body: `{"error":"forbidden"}`, sign: "none"}
	})
	_, err := s.client.FetchConfig(t.Context())
	if err == nil || !strings.Contains(err.Error(), "403") {
		t.Fatalf("err = %v", err)
	}
	if s.count(s.fallback) != 0 {
		t.Fatal("403 must not fail over")
	}
	if down, _ := s.client.Failover(); down {
		t.Fatal("403 must not start a cooldown")
	}
}

// Enrollment always goes to the configured URL.
func TestEnrollIgnoresPreferred(t *testing.T) {
	s := newFollowSetup(t)
	s.client.SetPreferred(mustParse(t, "https://example.com"))
	s.fallback.set(func(*http.Request, []byte) reply { return reply{status: 400, body: "x", sign: "none"} })
	_, _ = s.client.Enroll(t.Context(), enrollRequest(s.id), s.fallback.pub())
	if s.count(s.preferred) != 0 || s.count(s.fallback) != 1 {
		t.Fatal("enroll must use the configured URL")
	}
}

func newFollower(t *testing.T, s *followSetup, off bool, changes *atomic.Int32) *Follower {
	t.Helper()
	return NewFollower(FollowOptions{
		Client: s.client, DataDir: t.TempDir(), Off: off, Now: s.clock.Now,
		OnChange: func() {
			if changes != nil {
				changes.Add(1)
			}
		},
	})
}

func TestFollowerAdoptsVerifiedOrigin(t *testing.T) {
	s := newFollowSetup(t)
	var changes atomic.Int32
	f := newFollower(t, s, false, &changes)
	f.Want("https://example.com")
	if st := f.Status(); st.State != state.ControlPlanePending {
		t.Fatalf("before verification: %+v", st)
	}
	if f.Adopted() != nil {
		t.Fatal("must not be adopted before it verified")
	}
	f.step(t.Context())

	if got := f.Adopted(); got == nil || got.String() != "https://example.com" {
		t.Fatalf("adopted = %v", got)
	}
	if p := s.client.Preferred(); p == nil || p.String() != "https://example.com" {
		t.Fatalf("client preferred = %v", p)
	}
	if changes.Load() != 1 {
		t.Fatalf("OnChange called %d times", changes.Load())
	}
	if s.preferred.last().path != "/daemon/v1/config" {
		t.Fatalf("verification request = %+v", s.preferred.last())
	}
	info, err := os.Stat(f.path())
	if err != nil || info.Mode().Perm() != 0o600 {
		t.Fatalf("persisted file: %v %v", info, err)
	}
	if _, err := s.client.FetchConfig(t.Context()); err != nil {
		t.Fatal(err)
	}
	st := f.Status()
	if st.State != state.ControlPlaneAdopted || st.Adopted != "https://example.com" || st.Configured != "http://fallback.test" || st.Active != "https://example.com" {
		t.Fatalf("status = %+v", st)
	}
	rep := &state.Report{TypeMeta: state.NewTypeMeta(state.KindReport), ServerID: serverID, ObservedAt: s.clock.Now(), Seq: 1,
		Host: &state.HostStatus{CPUs: 1, DaemonVersion: "0.1.0", ControlPlane: st}}
	if err := rep.Validate(); err != nil {
		t.Fatalf("status does not validate: %v", err)
	}

	// The preferred URL breaks: requests fail over, the status says so.
	s.preferred.set(func(*http.Request, []byte) reply { return reply{status: 502, sign: "none"} })
	if _, err := s.client.FetchConfig(t.Context()); err != nil {
		t.Fatal(err)
	}
	if st := f.Status(); st.State != state.ControlPlaneFallback || st.Message == "" {
		t.Fatalf("fallback status = %+v", st)
	}

	// A restart loads the adopted origin right away.
	g := NewFollower(FollowOptions{Client: s.client, DataDir: filepath.Dir(f.path()), Now: s.clock.Now})
	s.client.SetPreferred(nil)
	g.Load()
	if p := s.client.Preferred(); p == nil || p.String() != "https://example.com" {
		t.Fatalf("after Load: preferred = %v", p)
	}

	// The dashboard stops sending it (or sends the configured origin): back to the configured URL.
	g.Want("http://fallback.test")
	g.Want("")
	g.step(t.Context())
	if s.client.Preferred() != nil || g.Adopted() != nil {
		t.Fatal("cleared origin still preferred")
	}
	if _, err := os.Stat(g.path()); !os.IsNotExist(err) {
		t.Fatal("persisted origin not removed")
	}
	if st := g.Status(); st.State != state.ControlPlaneConfigured {
		t.Fatalf("status = %+v", st)
	}
}

func TestFollowerRefusesUnverified(t *testing.T) {
	s := newFollowSetup(t)
	s.preferred.set(func(*http.Request, []byte) reply { return reply{status: 200, body: configBody(t), sign: "other"} })
	f := newFollower(t, s, false, nil)
	f.Want("https://example.com")
	f.step(t.Context())
	if f.Adopted() != nil || s.client.Preferred() != nil {
		t.Fatal("an origin signed by another key must not be adopted")
	}
	st := f.Status()
	if st.State != state.ControlPlanePending || !strings.Contains(st.Message, "cannot verify") {
		t.Fatalf("status = %+v", st)
	}
	// Not retried before the backoff.
	n := s.count(s.preferred)
	f.step(t.Context())
	if s.count(s.preferred) != n {
		t.Fatal("retried before the backoff")
	}
	s.clock.Add(followRetryMin + time.Second)
	s.preferred.set(func(*http.Request, []byte) reply { return reply{status: 200, body: configBody(t)} })
	f.step(t.Context())
	if f.Adopted() == nil {
		t.Fatal("not adopted after it verified")
	}
	if _, err := os.Stat(f.path()); err != nil {
		t.Fatal(err)
	}
}

func TestFollowerLocalPolicy(t *testing.T) {
	s := newFollowSetup(t)
	f := newFollower(t, s, true, nil)
	f.Want("https://example.com")
	f.step(t.Context())
	if s.count(s.preferred) != 0 || f.Adopted() != nil {
		t.Fatal("LR_CONTROL_PLANE_FOLLOW=off must never contact or adopt")
	}
	if st := f.Status(); st.State != state.ControlPlaneRefused || st.Message == "" {
		t.Fatalf("status = %+v", st)
	}

	// The control-plane host's own daemon (loopback URL) never moves either.
	id, _ := identity.Generate()
	signer, _ := id.NewSigner(string(serverID))
	c, _ := New(Options{BaseURL: mustParse(t, "http://127.0.0.1:8000"), Signer: signer, DaemonVersion: "0.1.0"})
	g := NewFollower(FollowOptions{Client: c, DataDir: t.TempDir()})
	g.Want("https://example.com")
	g.step(t.Context())
	if c.Preferred() != nil || g.Status().State != state.ControlPlaneRefused {
		t.Fatalf("loopback daemon: %+v", g.Status())
	}
	// A persisted file is ignored (and removed) under such a policy.
	if err := os.WriteFile(g.path(), []byte(`{"url":"https://example.com","verifiedAt":"2026-10-19T00:00:00Z"}`), 0o600); err != nil {
		t.Fatal(err)
	}
	g.Load()
	if c.Preferred() != nil {
		t.Fatal("loaded an origin the policy refuses")
	}
}

func TestFollowerIgnoresInvalidPersistedFile(t *testing.T) {
	s := newFollowSetup(t)
	f := newFollower(t, s, false, nil)
	for _, raw := range []string{`nope`, `{"url":"http://example.com"}`, `{"url":"https://10.0.0.1"}`, `{"url":"http://fallback.test"}`} {
		if err := os.WriteFile(f.path(), []byte(raw), 0o600); err != nil {
			t.Fatal(err)
		}
		f.Load()
		if s.client.Preferred() != nil {
			t.Fatalf("loaded %s", raw)
		}
		if _, err := os.Stat(f.path()); !os.IsNotExist(err) {
			t.Fatalf("%s not removed", raw)
		}
	}
}
