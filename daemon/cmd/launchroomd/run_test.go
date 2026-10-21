package main

import (
	"bytes"
	"context"
	"crypto/ed25519"
	"crypto/rand"
	"encoding/json"
	"errors"
	"io"
	"log/slog"
	"net/http"
	"net/http/httptest"
	"net/netip"
	"net/url"
	"os"
	"path/filepath"
	"slices"
	"strconv"
	"strings"
	"sync"
	"testing"
	"time"

	"github.com/vantezzen/launchroom/daemon/internal/config"
	"github.com/vantezzen/launchroom/daemon/internal/docker"
	"github.com/vantezzen/launchroom/daemon/internal/docker/dockerfake"
	"github.com/vantezzen/launchroom/daemon/internal/enrollment"
	"github.com/vantezzen/launchroom/daemon/internal/firewall"
	"github.com/vantezzen/launchroom/daemon/internal/health"
	"github.com/vantezzen/launchroom/daemon/internal/identity"
	"github.com/vantezzen/launchroom/daemon/internal/secret"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

const (
	testServerID = "srv_01J9Z8X7W6V5T4S3R2Q1P0N9MK"
	testToken    = "lr_jt_Zm9vYmFyYmF6cXV4cXV1eA"
)

// fakeDashboard implements just enough of /daemon/v1 to drive the daemon.
type fakeDashboard struct {
	t      *testing.T
	key    ed25519.PrivateKey
	signer ed25519.PrivateKey // key used to sign responses (normally key)

	mu        sync.Mutex
	daemonKey ed25519.PublicKey
	version   int64
	enrolls   int
	fetches   []string // "have" values
	stateDoc  string
	reports   []state.Report
	enrollReq *state.EnrollRequest
}

func newFakeDashboard(t *testing.T) (*fakeDashboard, *httptest.Server) {
	t.Helper()
	_, key, _ := ed25519.GenerateKey(rand.Reader)
	doc, err := os.ReadFile("../../../protocol/fixtures/v1/node-state.web-postgres.json")
	if err != nil {
		t.Fatal(err)
	}
	f := &fakeDashboard{t: t, key: key, signer: key, version: 1842, stateDoc: string(doc)}
	srv := httptest.NewServer(http.HandlerFunc(f.serve))
	t.Cleanup(srv.Close)
	return f, srv
}

func (f *fakeDashboard) pub() ed25519.PublicKey {
	p, _ := f.key.Public().(ed25519.PublicKey)
	return p
}

func (f *fakeDashboard) respond(w http.ResponseWriter, status int, body string) {
	f.mu.Lock()
	signer := f.signer
	f.mu.Unlock()
	h, err := identity.SignResponse("cp-1", signer, []byte(body))
	if err != nil {
		f.t.Error(err)
	}
	w.Header().Set(identity.ResponseSignatureHeader, h)
	w.WriteHeader(status)
	_, _ = io.WriteString(w, body)
}

func (f *fakeDashboard) serve(w http.ResponseWriter, r *http.Request) {
	body, _ := io.ReadAll(r.Body)
	f.mu.Lock() // released explicitly in each branch before responding

	switch r.URL.Path {
	case "/daemon/v1/enroll":
		var req struct {
			Token      string `json:"token"`
			SigningKey string `json:"signingKey"`
		}
		_ = json.Unmarshal(body, &req)
		pub, err := identity.ParsePublicKey(req.SigningKey)
		if err != nil || req.Token != testToken {
			f.mu.Unlock()
			w.WriteHeader(http.StatusForbidden)
			return
		}
		if _, err := identity.VerifyRequest(r, body, identity.RequestVerifyOptions{
			LookupKey: func(kid string) (ed25519.PublicKey, error) {
				if kid != identity.EnrollKeyID {
					return nil, errors.New("unexpected kid")
				}
				return pub, nil
			},
		}); err != nil {
			f.t.Errorf("enroll request signature: %v", err)
		}
		if er, err := state.Decode[state.EnrollRequest](body); err != nil {
			f.t.Errorf("invalid enroll request: %v\n%s", err, body)
		} else {
			f.enrollReq = er
		}
		f.daemonKey = pub
		f.enrolls++
		resp := `{"apiVersion":"launchroom.vantezzen.io/v1","kind":"EnrollResponse","serverId":"` + testServerID +
			`","status":"active","controlPlaneKeys":{"cp-1":"` + identity.FormatPublicKey(f.pub()) +
			`"},"registry":{"address":"127.0.0.1:5000"},"pollIntervals":{"state":"1s","tasks":"10s"}}`
		f.mu.Unlock()
		f.respond(w, http.StatusCreated, resp)

	case "/daemon/v1/state":
		daemonKey := f.daemonKey
		if _, err := identity.VerifyRequest(r, body, identity.RequestVerifyOptions{
			LookupKey: func(kid string) (ed25519.PublicKey, error) {
				if kid != testServerID || daemonKey == nil {
					return nil, errors.New("unknown daemon")
				}
				return daemonKey, nil
			},
		}); err != nil {
			f.mu.Unlock()
			f.t.Errorf("state request signature: %v", err)
			w.WriteHeader(http.StatusUnauthorized)
			return
		}
		have := r.URL.Query().Get("have")
		f.fetches = append(f.fetches, have)
		version := f.version
		doc := strings.Replace(f.stateDoc, `"version": 1842`, `"version": `+strconv.FormatInt(version, 10), 1)
		f.mu.Unlock()
		if h, _ := strconv.ParseInt(have, 10, 64); h >= version {
			w.WriteHeader(http.StatusNotModified)
			return
		}
		f.respond(w, http.StatusOK, doc)

	case "/daemon/v1/reports":
		rep, err := state.Decode[state.Report](body)
		if err != nil {
			f.mu.Unlock()
			f.t.Errorf("invalid report: %v\n%s", err, body)
			w.WriteHeader(http.StatusUnprocessableEntity)
			return
		}
		f.reports = append(f.reports, *rep)
		f.mu.Unlock()
		w.WriteHeader(http.StatusNoContent)

	case "/daemon/v1/tasks/lease":
		f.mu.Unlock()
		f.respond(w, http.StatusOK, `{"apiVersion":"launchroom.vantezzen.io/v1","kind":"TaskLease","tasks":[]}`)

	default:
		f.mu.Unlock()
		w.WriteHeader(http.StatusNotFound)
	}
}

func (f *fakeDashboard) lastReport() *state.Report {
	f.mu.Lock()
	defer f.mu.Unlock()
	if len(f.reports) == 0 {
		return nil
	}
	r := f.reports[len(f.reports)-1]
	return &r
}

func (f *fakeDashboard) snapshot() (enrolls int, fetches []string) {
	f.mu.Lock()
	defer f.mu.Unlock()
	return f.enrolls, append([]string(nil), f.fetches...)
}

// syncBuffer is a goroutine-safe log sink.
type syncBuffer struct {
	mu  sync.Mutex
	buf bytes.Buffer
}

func (b *syncBuffer) Write(p []byte) (int, error) {
	b.mu.Lock()
	defer b.mu.Unlock()
	return b.buf.Write(p)
}

func (b *syncBuffer) String() string {
	b.mu.Lock()
	defer b.mu.Unlock()
	return b.buf.String()
}

func testConfig(t *testing.T, srvURL string, pinned ed25519.PublicKey, token string) config.Config {
	t.Helper()
	u, _ := url.Parse(srvURL)
	return config.Config{
		ControlPlaneURL: u, DataDir: t.TempDir(), PinnedCPKey: pinned, JoinToken: secret.Secret(token), LogLevel: slog.LevelDebug,
		AskAddr: netip.MustParseAddrPort("127.0.0.1:0"), HTTPSRedirectPort: 443,
	}
}

type okAdmin struct{}

func (okAdmin) Ready(context.Context) error        { return nil }
func (okAdmin) Load(context.Context, []byte) error { return nil }

func testDeps() deps {
	return deps{
		hostname:   func() (string, error) { return "test host!", nil },
		now:        time.Now,
		docker:     func(s state.ServerID) (docker.Client, error) { return dockerfake.New(string(s)), nil },
		caddyAdmin: okAdmin{},
		ports:      health.ProcScanner{Root: "/nonexistent"},
		firewall:   &firewall.FakeBackend{},
	}
}

func eventually(t *testing.T, what string, cond func() bool) {
	t.Helper()
	deadline := time.Now().Add(10 * time.Second)
	for !cond() {
		if time.Now().After(deadline) {
			t.Fatalf("timed out waiting for %s", what)
		}
		time.Sleep(20 * time.Millisecond)
	}
}

func persistedVersion(t *testing.T, cfg config.Config, pub ed25519.PublicKey) int64 {
	t.Helper()
	s := state.NewStore(filepath.Join(cfg.DataDir, stateFile), identity.KeySet{"cp-1": pub}, testServerID)
	if err := s.Load(); err != nil {
		return -1
	}
	return s.Version()
}

func startDaemon(t *testing.T, cfg config.Config, logs io.Writer) (cancel func() error, refresh chan os.Signal) {
	t.Helper()
	ctx, stop := context.WithCancel(t.Context())
	refresh = make(chan os.Signal, 1)
	logger := slog.New(slog.NewJSONHandler(logs, &slog.HandlerOptions{Level: slog.LevelDebug}))
	done := make(chan error, 1)
	go func() { done <- run(ctx, cfg, logger, refresh, testDeps()) }()
	return func() error {
		stop()
		select {
		case err := <-done:
			return err
		case <-time.After(10 * time.Second):
			t.Fatal("daemon did not shut down")
			return nil
		}
	}, refresh
}

func TestEnrollFetchPersistAndRestart(t *testing.T) {
	cp, srv := newFakeDashboard(t)
	cfg := testConfig(t, srv.URL, cp.pub(), testToken)
	logs := &syncBuffer{}

	stop, refresh := startDaemon(t, cfg, logs)
	eventually(t, "first state persisted", func() bool { return persistedVersion(t, cfg, cp.pub()) == 1842 })

	cp.mu.Lock()
	cp.version = 1843
	cp.mu.Unlock()
	refresh <- os.Interrupt // any signal value; the daemon only cares that one arrived
	eventually(t, "SIGHUP refresh", func() bool { return persistedVersion(t, cfg, cp.pub()) == 1843 })
	eventually(t, "report for the new version", func() bool { r := cp.lastReport(); return r != nil && r.AppliedVersion == 1843 })

	if err := stop(); err != nil {
		t.Fatalf("graceful shutdown returned %v", err)
	}

	for _, name := range []string{"keys/signing.key", "keys/sealing.key", enrollment.FileName, stateFile} {
		fi, err := os.Stat(filepath.Join(cfg.DataDir, name))
		if err != nil {
			t.Fatal(err)
		}
		if fi.Mode().Perm() != 0o600 {
			t.Errorf("%s perm %v", name, fi.Mode().Perm())
		}
	}

	out := logs.String()
	if strings.Contains(out, testToken) {
		t.Fatal("join token leaked into logs")
	}
	rep := cp.lastReport()
	if rep == nil || rep.AppliedVersion < 1842 || rep.Host == nil || rep.Ingress == nil || len(rep.Workloads) != 2 {
		t.Fatalf("report %+v", rep)
	}
	// Hardware facts went out at enrollment and with a report (then only
	// when they change or hourly); memory is filled at enrollment.
	cp.mu.Lock()
	enrollReq, reports := cp.enrollReq, slices.Clone(cp.reports)
	cp.mu.Unlock()
	if enrollReq == nil || enrollReq.Host.MemoryMB <= 0 || enrollReq.Host.Hardware == nil {
		t.Errorf("enroll host facts %+v", enrollReq)
	}
	withHardware := 0
	for _, r := range reports {
		if r.Host != nil && r.Host.Hardware != nil {
			withHardware++
		}
	}
	if withHardware == 0 {
		t.Error("no report carried the hardware facts")
	}
	for _, w := range rep.Workloads {
		// The fixture's sealed env is not addressed to this daemon's key.
		if w.Phase != state.PhaseFailed || !strings.Contains(w.Message, "cannot open env") {
			t.Errorf("workload %s: %s %q", w.ID, w.Phase, w.Message)
		}
	}

	// Restart without a token: reuse enrollment and state; no second enrollment.
	cfg.JoinToken = ""
	stop, _ = startDaemon(t, cfg, logs)
	eventually(t, "fetch after restart", func() bool {
		_, f := cp.snapshot()
		return len(f) > 0 && f[len(f)-1] == "1843"
	})
	if err := stop(); err != nil {
		t.Fatal(err)
	}
	if enrolls, _ := cp.snapshot(); enrolls != 1 {
		t.Fatalf("enrolled %d times", enrolls)
	}
}

func TestNotEnrolledWithoutToken(t *testing.T) {
	_, srv := newFakeDashboard(t)
	cfg := testConfig(t, srv.URL, nil, "")
	err := run(t.Context(), cfg, slog.New(slog.DiscardHandler), nil, testDeps())
	if err == nil || !strings.Contains(err.Error(), "not enrolled") {
		t.Fatalf("err = %v", err)
	}
}

func TestEnrollmentRejectsUnpinnedSigner(t *testing.T) {
	cp, srv := newFakeDashboard(t)
	_, mitm, _ := ed25519.GenerateKey(rand.Reader)
	cp.mu.Lock()
	cp.signer = mitm
	cp.mu.Unlock()

	cfg := testConfig(t, srv.URL, cp.pub(), testToken)
	err := run(t.Context(), cfg, slog.New(slog.DiscardHandler), nil, testDeps())
	if err == nil || !strings.Contains(err.Error(), "enrollment failed") {
		t.Fatalf("err = %v", err)
	}
	if _, statErr := os.Stat(filepath.Join(cfg.DataDir, enrollment.FileName)); !errors.Is(statErr, os.ErrNotExist) {
		t.Fatal("enrollment persisted despite a bad signature")
	}
}

func TestStateSignedByUnknownKeyIsNotPersisted(t *testing.T) {
	cp, srv := newFakeDashboard(t)
	cfg := testConfig(t, srv.URL, cp.pub(), testToken)
	logs := &syncBuffer{}
	stop, refresh := startDaemon(t, cfg, logs)
	eventually(t, "first state persisted", func() bool { return persistedVersion(t, cfg, cp.pub()) == 1842 })

	_, rogue, _ := ed25519.GenerateKey(rand.Reader)
	cp.mu.Lock()
	cp.signer = rogue
	cp.version = 9999
	cp.mu.Unlock()
	refresh <- os.Interrupt
	eventually(t, "rejection logged", func() bool { return strings.Contains(logs.String(), "fetching desired state failed") })
	if err := stop(); err != nil {
		t.Fatal(err)
	}
	if v := persistedVersion(t, cfg, cp.pub()); v != 1842 {
		t.Fatalf("persisted version %d after a forged state", v)
	}
}

func TestHostFactsSanitized(t *testing.T) {
	hf := hostFacts(func() (string, error) { return "my host/ä", nil }, "")
	if err := hf.Hostname.Validate(); err != nil {
		t.Fatal(err)
	}
	if hf.OS != "linux" {
		t.Fatalf("os = %q", hf.OS)
	}
	hf = hostFacts(func() (string, error) { return "", errors.New("x") }, "")
	if hf.Hostname != "unknown" || hf.CPUs < 1 {
		t.Fatalf("%+v", hf)
	}
	// On a Mac the installer's LR_HOST_OS names the machine's OS (D53).
	if hf = hostFacts(func() (string, error) { return "mac", nil }, state.HostDarwin); hf.OS != "darwin" {
		t.Fatalf("os = %q", hf.OS)
	}
}

func TestJitterBounds(t *testing.T) {
	for range 1000 {
		d := jitter(10 * time.Second)
		if d < 9*time.Second || d > 11*time.Second {
			t.Fatalf("jitter %v out of bounds", d)
		}
	}
}

func TestForwardAuthOrigins(t *testing.T) {
	cases := map[string][]string{
		"http://dashboard:8000":        {"http://dashboard:8000", "http://127.0.0.1:8000"},
		"https://Dash.Example.com/":    {"https://dash.example.com:443", "http://127.0.0.1:8000"},
		"http://dash.example.com/base": {"http://dash.example.com:80", "http://127.0.0.1:8000"},
	}
	local := []netip.AddrPort{netip.MustParseAddrPort("127.0.0.1:8000")}
	for raw, want := range cases {
		if got := forwardAuthOrigins(mustURL(t, raw), nil, local); !slices.Equal(got, want) {
			t.Errorf("%s: %v, want %v", raw, got, want)
		}
	}
	// The origins match what state.ForwardAuthURL reports for the dashboard's URL.
	if got := forwardAuthOrigins(mustURL(t, "http://dashboard:8000"), nil, nil); got[0] != state.ForwardAuthURL("http://dashboard:8000/auth/preview").Origin() {
		t.Errorf("origin mismatch: %v", got)
	}
}

// The adopted canonical origin (docs/03 §4.2) joins the allow-list; the same
// origin twice is listed once.
func TestForwardAuthOriginsAdopted(t *testing.T) {
	local := []netip.AddrPort{netip.MustParseAddrPort("127.0.0.1:8000")}
	got := forwardAuthOrigins(mustURL(t, "https://1-2-3-4.sslip.io"), mustURL(t, "https://dash.example.com"), local)
	want := []string{"https://1-2-3-4.sslip.io:443", "https://dash.example.com:443", "http://127.0.0.1:8000"}
	if !slices.Equal(got, want) {
		t.Fatalf("%v, want %v", got, want)
	}
	if got := forwardAuthOrigins(mustURL(t, "https://dash.example.com"), mustURL(t, "https://DASH.example.com:443"), nil); len(got) != 1 {
		t.Fatalf("duplicate origin: %v", got)
	}
}

func mustURL(t *testing.T, raw string) *url.URL {
	t.Helper()
	u, err := url.Parse(raw)
	if err != nil {
		t.Fatal(err)
	}
	return u
}
