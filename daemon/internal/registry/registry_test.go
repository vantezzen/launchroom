package registry

import (
	"bufio"
	"context"
	"encoding/base64"
	"encoding/json"
	"errors"
	"net"
	"strconv"
	"sync"
	"testing"
	"time"

	"github.com/vantezzen/launchroom/daemon/internal/secret"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

type fakeCP struct {
	mu    sync.Mutex
	reqs  []*state.RegistryTokenRequest
	ttl   time.Duration
	now   func() time.Time
	err   error
	count int
}

func (f *fakeCP) RegistryToken(_ context.Context, req *state.RegistryTokenRequest) (*state.RegistryToken, error) {
	f.mu.Lock()
	defer f.mu.Unlock()
	if f.err != nil {
		return nil, f.err
	}
	f.reqs = append(f.reqs, req)
	f.count++
	return &state.RegistryToken{
		Token:     secret.Secret("h.p.s" + strconv.Itoa(f.count)),
		ExpiresAt: f.now().Add(f.ttl),
		Scopes:    req.Scopes,
	}, nil
}

func pull(repo string) []state.RegistryScope {
	return []state.RegistryScope{{Repository: state.RegistryRepository(repo), Actions: []state.RegistryAction{state.RegistryPull}}}
}

func TestTokenCache(t *testing.T) {
	now := time.Date(2026, 10, 18, 12, 0, 0, 0, time.UTC)
	cp := &fakeCP{ttl: 5 * time.Minute, now: func() time.Time { return now }}
	tk := NewTokens(cp)
	tk.now = func() time.Time { return now }

	a, err := tk.Token(t.Context(), pull("apps/svc_x"), "")
	if err != nil {
		t.Fatal(err)
	}
	b, _ := tk.Token(t.Context(), pull("apps/svc_x"), "")
	if a != b || cp.count != 1 {
		t.Fatalf("token not cached: %d requests", cp.count)
	}
	if _, err := tk.Token(t.Context(), pull("apps/svc_y"), ""); err != nil || cp.count != 2 {
		t.Fatalf("different scope must request a new token (%d, %v)", cp.count, err)
	}
	if _, err := tk.Token(t.Context(), pull("apps/svc_x"), "tsk_01J9Z8X7W6V5T4S3R2Q1P0N9P0"); err != nil || cp.count != 3 {
		t.Fatalf("task-bound token must not reuse an unbound one (%d)", cp.count)
	}
	now = now.Add(4*time.Minute + 30*time.Second) // within refreshBefore of expiry
	if _, err := tk.Token(t.Context(), pull("apps/svc_x"), ""); err != nil || cp.count != 4 {
		t.Fatalf("expiring token not refreshed (%d)", cp.count)
	}
	tk.Forget("tsk_01J9Z8X7W6V5T4S3R2Q1P0N9P0")
	for k := range tk.cache {
		if k[:4] == "tsk_" {
			t.Fatalf("forgotten task token still cached: %s", k)
		}
	}
	cp.err = errors.New("403")
	if _, err := tk.Token(t.Context(), pull("apps/other"), ""); err == nil {
		t.Fatal("error swallowed")
	}
}

func TestPullAuth(t *testing.T) {
	cp := &fakeCP{ttl: time.Minute, now: time.Now}
	auth := NewTokens(cp).PullAuth(DefaultAddress)
	got, err := auth(t.Context(), "127.0.0.1:5000/apps/svc_01j9z8x7w6v5t4s3r2q1p0n9n2@sha256:"+string(make64()))
	if err != nil {
		t.Fatal(err)
	}
	raw, err := base64.URLEncoding.DecodeString(got)
	if err != nil {
		t.Fatal(err)
	}
	var cfg map[string]string
	if err := json.Unmarshal(raw, &cfg); err != nil || cfg["registrytoken"] != "h.p.s1" || len(cfg) != 1 {
		t.Fatalf("auth config = %s", raw)
	}
	if r := cp.reqs[0]; len(r.Scopes) != 1 || r.Scopes[0].Repository != "apps/svc_01j9z8x7w6v5t4s3r2q1p0n9n2" ||
		len(r.Scopes[0].Actions) != 1 || r.Scopes[0].Actions[0] != state.RegistryPull || r.Task != "" {
		t.Fatalf("pull must ask for exactly pull on the image repository: %+v", r)
	}
	// Foreign registries are anonymous and never ask the dashboard.
	if got, err := auth(t.Context(), "docker.io/library/caddy@sha256:"+string(make64())); err != nil || got != "" || len(cp.reqs) != 1 {
		t.Fatalf("foreign image got credentials: %q %v", got, err)
	}
	// A registry host prefix trick does not count as local.
	if got, _ := auth(t.Context(), "127.0.0.1:50000/apps/x@sha256:"+string(make64())); got != "" {
		t.Fatal("127.0.0.1:50000 treated as the local registry")
	}
}

func make64() []byte {
	b := make([]byte, 64)
	for i := range b {
		b[i] = 'a'
	}
	return b
}

func TestRepositoryOf(t *testing.T) {
	cases := map[string]string{
		"127.0.0.1:5000/mirror/docker.io/library/postgres@sha256:abc": "mirror/docker.io/library/postgres",
		"127.0.0.1:5000/apps/svc_x:dpl_y":                             "apps/svc_x",
		"127.0.0.1:5000/../etc":                                       "",
		"127.0.0.1:5000/APPS/x":                                       "",
		"ghcr.io/vantezzen/x":                                         "",
	}
	for in, want := range cases {
		got, ok := RepositoryOf(in, DefaultAddress)
		if string(got) != want || ok != (want != "") {
			t.Errorf("%s → %q %v, want %q", in, got, ok, want)
		}
	}
}

func TestForwarder(t *testing.T) {
	var lc net.ListenConfig
	var dialer net.Dialer
	up, err := lc.Listen(t.Context(), "tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	defer func() { _ = up.Close() }()
	go func() {
		for {
			c, err := up.Accept()
			if err != nil {
				return
			}
			go func() {
				defer func() { _ = c.Close() }()
				line, _ := bufio.NewReader(c).ReadString('\n')
				_, _ = c.Write([]byte("echo:" + line))
			}()
		}
	}()

	l, err := lc.Listen(t.Context(), "tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	f := NewForwarder(l.Addr().String(), up.Addr().String(), nil)
	ctx, cancel := context.WithCancel(t.Context())
	done := make(chan error, 1)
	go func() { done <- f.ServeListener(ctx, l) }()

	c, err := dialer.DialContext(t.Context(), "tcp", l.Addr().String())
	if err != nil {
		t.Fatal(err)
	}
	_, _ = c.Write([]byte("hello\n"))
	got, _ := bufio.NewReader(c).ReadString('\n')
	_ = c.Close()
	if got != "echo:hello\n" {
		t.Fatalf("got %q", got)
	}

	// No upstream: connections are closed immediately.
	f.SetUpstream("")
	c2, err := dialer.DialContext(t.Context(), "tcp", l.Addr().String())
	if err != nil {
		t.Fatal(err)
	}
	_ = c2.SetReadDeadline(time.Now().Add(5 * time.Second))
	if n, _ := c2.Read(make([]byte, 1)); n != 0 {
		t.Fatal("connection without upstream was served")
	}
	_ = c2.Close()

	cancel()
	if err := <-done; err != nil {
		t.Fatal(err)
	}
}
