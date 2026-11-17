package caddy

import (
	"context"
	"errors"
	"os"
	"path/filepath"
	"strings"
	"sync"
	"testing"
	"time"

	"github.com/vantezzen/launchroom/daemon/internal/docker"
	"github.com/vantezzen/launchroom/daemon/internal/docker/dockerfake"
	"github.com/vantezzen/launchroom/daemon/internal/names"
	"github.com/vantezzen/launchroom/daemon/internal/policy"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// fakeProc is a /proc with nginx (pid 812) listening on :80.
func fakeProc(t *testing.T) string {
	t.Helper()
	root := t.TempDir()
	write := func(rel, content string) {
		p := filepath.Join(root, rel)
		if err := os.MkdirAll(filepath.Dir(p), 0o750); err != nil {
			t.Fatal(err)
		}
		if err := os.WriteFile(p, []byte(content), 0o600); err != nil {
			t.Fatal(err)
		}
	}
	write("net/tcp", "  sl  local_address rem_address   st tx_queue rx_queue tr tm->when retrnsmt   uid  timeout inode\n"+
		"   0: 0100007F:07E3 00000000:0000 0A 00000000:00000000 00:00000000 00000000     0        0 1111 1 0\n"+
		"   1: 00000000:0050 00000000:0000 0A 00000000:00000000 00:00000000 00000000     0        0 4242 1 0\n"+
		"   2: 0100007F:0050 0100007F:9C40 01 00000000:00000000 00:00000000 00000000     0        0 5555 1 0\n")
	write("net/tcp6", "  sl  local_address rem_address st\n")
	write("812/comm", "nginx\n")
	write("900/comm", "sshd\n")
	if err := os.MkdirAll(filepath.Join(root, "812/fd"), 0o750); err != nil {
		t.Fatal(err)
	}
	if err := os.MkdirAll(filepath.Join(root, "900/fd"), 0o750); err != nil {
		t.Fatal(err)
	}
	if err := os.Symlink("socket:[1111]", filepath.Join(root, "900/fd/3")); err != nil {
		t.Fatal(err)
	}
	if err := os.Symlink("socket:[4242]", filepath.Join(root, "812/fd/6")); err != nil {
		t.Fatal(err)
	}
	return root
}

func TestPortHolder(t *testing.T) {
	proc := fakeProc(t)
	if got := PortHolder(proc, 80); got != "nginx" {
		t.Fatalf("holder of :80 = %q", got)
	}
	if got := PortHolder(proc, 443); got != "" {
		t.Fatalf("holder of :443 = %q", got)
	}
	if got := PortHolder(t.TempDir(), 80); got != "" {
		t.Fatalf("holder without /proc = %q", got)
	}
}

func TestClassifyCaddyOutput(t *testing.T) {
	lines := []string{
		`{"level":"info","msg":"using config from file"}`,
		`{"level":"error","ts":1,"msg":"loading initial config","error":"loading new config: http app module: start: listening on :80: listen tcp :80: bind: address already in use"}`,
	}
	if r, p := classifyCaddyOutput(lines); r != state.IngressPortInUse || p != 80 {
		t.Fatalf("got %s %d", r, p)
	}
	if r, p := classifyCaddyOutput([]string{"listen tcp6 [::]:443: bind: address already in use"}); r != state.IngressPortInUse || p != 443 {
		t.Fatalf("ipv6: got %s %d", r, p)
	}
	if r, _ := classifyCaddyOutput([]string{"panic: something"}); r != "" {
		t.Fatalf("got %s", r)
	}
}

// G15: Caddy unreachable for over a minute because nginx holds :80 →
// pending with reason port_in_use, the port, the program and Caddy's last
// lines; once the port is free the next pass applies and clears it.
func TestManagerNamesPortInUse(t *testing.T) {
	ctx := t.Context()
	image := state.ImageRef("docker.io/library/caddy@" + policy.DefaultSystemImageDigests[names.SystemCaddy][0])
	sys := state.SystemContainer{Enabled: true, Image: image}
	clock := time.Date(2026, 11, 17, 12, 0, 0, 0, time.UTC)
	d := dockerfake.New(srvID)
	admin := &unreachableAdmin{down: 1000}
	m := NewManager(ManagerOptions{
		ServerID: srvID, Docker: d, Admin: admin, Policy: policy.DefaultConfig(), Render: renderOpts, CacheDir: t.TempDir(), Ask: &Ask{},
		LoadRetry: time.Millisecond, Now: func() time.Time { return clock }, ProcRoot: fakeProc(t),
	})
	routes := ResolveRoutes(routeSpecs()[1:2], resolver(nil))

	st := m.sync(ctx, sys, routes)
	if st.State != state.IngressPending || st.Reason != "" {
		t.Fatalf("first unreachable pass is a normal restart: %+v", st)
	}
	cs, _ := d.ListContainers(ctx, map[string]string{names.LabelSystem: names.SystemCaddy})
	if len(cs) != 1 {
		t.Fatalf("caddy containers %v", cs)
	}
	d.Logs[cs[0].ID] = []docker.LogLine{
		{Stream: "stderr", Time: clock, Text: `{"level":"info","msg":"serving initial configuration"}`},
		{Stream: "stderr", Time: clock, Text: `{"level":"error","msg":"loading initial config","error":"listen tcp :80: bind: address already in use"}` + "\x1b"},
	}
	d.SetState(cs[0].ID, "restarting", 1)
	clock = clock.Add(90 * time.Second)
	st = m.sync(ctx, sys, routes)
	if st.State != state.IngressPending || st.Reason != state.IngressPortInUse || st.Port == nil || *st.Port != 80 || st.Process != "nginx" || len(st.Output) != 2 {
		t.Fatalf("diagnosed: %+v", st)
	}
	if strings.ContainsRune(st.Output[1].Text, 0x1b) || st.Output[1].Stream != state.StreamStderr {
		t.Fatalf("output %+v", st.Output[1])
	}
	rep := state.Report{TypeMeta: state.NewTypeMeta(state.KindReport), ServerID: srvID, ObservedAt: clock, Seq: 1, Full: true, AppliedVersion: 1, Workloads: []state.WorkloadStatus{}, Ingress: st}
	if err := rep.Validate(); err != nil {
		t.Fatalf("report: %v", err)
	}

	admin.mu.Lock()
	admin.down = 0
	admin.mu.Unlock()
	st = m.sync(ctx, sys, routes)
	if st.State != state.IngressApplied || st.Reason != "" || st.Port != nil || st.Output != nil {
		t.Fatalf("after the port was freed: %+v", st)
	}
}

// G15: a config Caddy refuses is config_refused with its last lines; the
// previous routes keep serving.
func TestManagerConfigRefused(t *testing.T) {
	ctx := t.Context()
	image := state.ImageRef("docker.io/library/caddy@" + policy.DefaultSystemImageDigests[names.SystemCaddy][0])
	sys := state.SystemContainer{Enabled: true, Image: image}
	d := dockerfake.New(srvID)
	admin := &fakeAdmin{}
	m := NewManager(ManagerOptions{ServerID: srvID, Docker: d, Admin: admin, Policy: policy.DefaultConfig(), Render: renderOpts, CacheDir: t.TempDir(), Ask: &Ask{}})
	if st := m.sync(ctx, sys, ResolveRoutes(routeSpecs()[1:2], resolver(nil))); st.State != state.IngressApplied {
		t.Fatalf("%+v", st)
	}
	cs, _ := d.ListContainers(ctx, map[string]string{names.LabelSystem: names.SystemCaddy})
	d.Logs[cs[0].ID] = []docker.LogLine{{Stream: "stderr", Time: time.Now(), Text: `{"level":"error","msg":"adapting config","error":"json: unknown field"}`}}
	admin.err = &AdminStatusError{Method: "POST", Path: "/load", Status: 400, Body: "unknown field"}
	st := m.sync(ctx, sys, ResolveRoutes(routeSpecs()[0:2], resolver(nil)))
	if st.State != state.IngressFailed || st.Reason != state.IngressConfigRefused || len(st.Output) != 1 ||
		!strings.Contains(st.Message, "keeps serving the previous routes") {
		t.Fatalf("%+v", st)
	}
	// A running Caddy refuses a config whose port it can't bind.
	admin.err = &AdminStatusError{Method: "POST", Path: "/load", Status: 400,
		Body: `{"error":"loading new config: http app module: start: listening on :443: listen tcp :443: bind: address already in use"}`}
	st = m.sync(ctx, sys, ResolveRoutes(routeSpecs()[0:1], resolver(nil)))
	if st.State != state.IngressFailed || st.Reason != state.IngressPortInUse || st.Port == nil || *st.Port != 443 {
		t.Fatalf("%+v", st)
	}
}

// G16: ACME failures are classified; a rate limit carries its end.
func TestClassifyCertError(t *testing.T) {
	for msg, want := range map[string]state.CertFailureKind{
		`HTTP 429 urn:ietf:params:acme:error:rateLimited - too many certificates (5) already issued for this exact set of identifiers in the last 168h0m0s, retry after 2026-11-17 14:05:00 UTC: see https://letsencrypt.org/docs/rate-limits/`: state.CertRateLimited,
		`HTTP 403 urn:ietf:params:acme:error:caa - CAA record for example.com prevents issuance`:                                                          state.CertCAA,
		`[example.com] solving challenges: presenting for challenge: adding temporary record for zone "example.com.": cloudflare: 403 Forbidden`:          state.CertDNSProvider,
		`HTTP 400 urn:ietf:params:acme:error:connection - 203.0.113.9: Timeout during connect (likely firewall problem)`:                                  state.CertTimeout,
		`HTTP 403 urn:ietf:params:acme:error:unauthorized - 2a01:4f8::1: Invalid response from http://shop.example.com/.well-known/acme-challenge/x: 404`: state.CertDNSMismatch,
		`HTTP 400 urn:ietf:params:acme:error:dns - no valid A records found for shop.example.com`:                                                         state.CertDNSMismatch,
		`something unexpected`: state.CertOtherError,
	} {
		kind, retry := ClassifyCertError(msg)
		if kind != want {
			t.Errorf("%.60s: %s, want %s", msg, kind, want)
		}
		if (kind == state.CertRateLimited) != (retry != nil) {
			t.Errorf("%.60s: retryAfter %v", msg, retry)
		}
		if retry != nil && !retry.Equal(time.Date(2026, 11, 17, 14, 5, 0, 0, time.UTC)) {
			t.Errorf("retryAfter %v", retry)
		}
	}
}

// reloadAdmin records forced reloads (cert_retry).
type reloadAdmin struct {
	fakeAdmin
	rmu     sync.Mutex
	reloads int
}

func (a *reloadAdmin) Reload(context.Context, []byte) error {
	a.rmu.Lock()
	defer a.rmu.Unlock()
	a.reloads++
	return nil
}

// G16: "Retry now" reloads the running config for routed hosts, skips
// rate-limited ones and refuses hosts this edge doesn't route.
func TestRetryCertificates(t *testing.T) {
	ctx := t.Context()
	image := state.ImageRef("docker.io/library/caddy@" + policy.DefaultSystemImageDigests[names.SystemCaddy][0])
	clock := time.Date(2026, 11, 17, 12, 0, 0, 0, time.UTC)
	d := dockerfake.New(srvID)
	admin := &reloadAdmin{}
	opts := renderOpts
	opts.HTTPSPort = 1 // nothing listens: the knock fails fast
	m := NewManager(ManagerOptions{ServerID: srvID, Docker: d, Admin: admin, Policy: policy.DefaultConfig(), Render: opts, CacheDir: t.TempDir(),
		Ask: &Ask{}, Now: func() time.Time { return clock }})
	if _, err := m.RetryCertificates(ctx, ctx, []state.Hostname{"shop.example.com"}); err == nil {
		t.Fatal("retry before any config")
	}
	if st := m.sync(ctx, state.SystemContainer{Enabled: true, Image: image}, ResolveRoutes(routeSpecs()[0:2], resolver(nil))); st.State != state.IngressApplied {
		t.Fatalf("%+v", st)
	}
	later := clock.Add(time.Hour)
	m.mu.Lock()
	m.certs = []state.CertificateStatus{
		{Host: "shop.example.com", State: state.CertFailed, FailureKind: state.CertRateLimited, RetryAfter: &later},
		{Host: "custom.example.org", State: state.CertFailed, FailureKind: state.CertDNSMismatch},
	}
	m.mu.Unlock()

	if _, err := m.RetryCertificates(ctx, ctx, []state.Hostname{"other.example.net"}); err == nil || !strings.Contains(err.Error(), "not routed") {
		t.Fatalf("unrouted host: %v", err)
	}
	if _, err := m.RetryCertificates(ctx, ctx, []state.Hostname{"shop.example.com"}); !errors.Is(err, ErrCertRetryNothing) {
		t.Fatalf("rate-limited only: %v", err)
	}
	started, err := m.RetryCertificates(ctx, ctx, []state.Hostname{"shop.example.com", "custom.example.org", "www.shop.example.com"})
	if err != nil || len(started) != 2 || started[0] != "custom.example.org" || admin.reloads != 1 {
		t.Fatalf("started %v err %v reloads %d", started, err, admin.reloads)
	}
	// Within a minute: no second reload, the attempt still counts as started.
	if _, err := m.RetryCertificates(ctx, ctx, []state.Hostname{"custom.example.org"}); err != nil || admin.reloads != 1 {
		t.Fatalf("second retry: %v reloads %d", err, admin.reloads)
	}
}

// G16: statuses carry the classification of the last error.
func TestCertificateStatusFailureKind(t *testing.T) {
	now := time.Date(2026, 11, 17, 12, 0, 0, 0, time.UTC)
	until := now.Add(time.Hour)
	routes := ResolveRoutes(routeSpecs()[1:2], resolver(nil))
	events := map[string]certEvent{"custom.example.org": {lastErr: "rate limited", lastErrAt: now.Add(-time.Minute), kind: state.CertRateLimited, retryAfter: &until}}
	st := CertificateStatuses(routes, TLS{}, nil, events, now)
	if len(st) != 1 || st[0].FailureKind != state.CertRateLimited || st[0].RetryAfter == nil || !st[0].RetryAfter.Equal(until) {
		t.Fatalf("%+v", st)
	}
	st = CertificateStatuses(routes, TLS{}, nil, events, until.Add(time.Second))
	if st[0].RetryAfter != nil {
		t.Fatalf("past retryAfter kept: %+v", st[0])
	}
}
