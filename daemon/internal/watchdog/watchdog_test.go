package watchdog

import (
	"context"
	"encoding/json"
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

type fakeEngine struct {
	mu         sync.Mutex
	containers []Container
	restarted  []string
	err        error
}

func (f *fakeEngine) Containers(context.Context) ([]Container, error) {
	f.mu.Lock()
	defer f.mu.Unlock()
	return append([]Container(nil), f.containers...), f.err
}

func (f *fakeEngine) Restart(_ context.Context, id string, _ time.Duration) error {
	f.mu.Lock()
	defer f.mu.Unlock()
	f.restarted = append(f.restarted, id)
	return nil
}

func (f *fakeEngine) set(svc state.ControlPlaneService, st state.ServiceState, exit int) {
	f.mu.Lock()
	defer f.mu.Unlock()
	for i := range f.containers {
		if f.containers[i].Service == svc {
			f.containers[i].State, f.containers[i].ExitCode = st, exit
		}
	}
}

func (f *fakeEngine) restarts() []string {
	f.mu.Lock()
	defer f.mu.Unlock()
	return append([]string(nil), f.restarted...)
}

func healthyEngine() *fakeEngine {
	f := &fakeEngine{}
	for _, svc := range allServices {
		f.containers = append(f.containers, Container{ID: "id-" + string(svc), Service: svc, State: state.ServiceRunning})
	}
	return f
}

type clock struct {
	mu sync.Mutex
	t  time.Time
}

func (c *clock) now() time.Time { c.mu.Lock(); defer c.mu.Unlock(); return c.t }
func (c *clock) add(d time.Duration) {
	c.mu.Lock()
	c.t = c.t.Add(d)
	c.mu.Unlock()
}

// readyServer answers the readiness endpoint with a settable status (0 = hang up).
type readyServer struct {
	mu     sync.Mutex
	status int
	paths  []string
}

func (r *readyServer) set(s int) { r.mu.Lock(); r.status = s; r.mu.Unlock() }

func (r *readyServer) ServeHTTP(w http.ResponseWriter, req *http.Request) {
	r.mu.Lock()
	s := r.status
	r.paths = append(r.paths, req.URL.Path)
	r.mu.Unlock()
	if s == 0 {
		hj, _ := w.(http.Hijacker)
		c, _, _ := hj.Hijack()
		_ = c.Close()
		return
	}
	w.WriteHeader(s)
}

// receiver is a fallback webhook endpoint reached through a public-looking address.
type receiver struct {
	mu     sync.Mutex
	bodies []map[string]any
	status int
	srv    *httptest.Server
}

func newReceiver(t *testing.T) *receiver {
	r := &receiver{status: 200}
	r.srv = httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, req *http.Request) {
		var m map[string]any
		_ = json.NewDecoder(req.Body).Decode(&m)
		r.mu.Lock()
		r.bodies = append(r.bodies, m)
		s := r.status
		r.mu.Unlock()
		w.WriteHeader(s)
	}))
	t.Cleanup(r.srv.Close)
	return r
}

func (r *receiver) got() []map[string]any {
	r.mu.Lock()
	defer r.mu.Unlock()
	return append([]map[string]any(nil), r.bodies...)
}

// egressTo resolves every host to resolveTo and dials the receiver.
func egressTo(r *receiver, resolveTo string) Egress {
	pool := r.srv.Client().Transport.(*http.Transport).TLSClientConfig.RootCAs
	return Egress{
		Resolve: func(context.Context, string) ([]netip.Addr, error) {
			return []netip.Addr{netip.MustParseAddr(resolveTo)}, nil
		},
		Dial: func(ctx context.Context, network, _ string) (net.Conn, error) {
			var d net.Dialer
			return d.DialContext(ctx, network, r.srv.Listener.Addr().String())
		},
		RootCAs: pool,
	}
}

const publicIP = "93.184.216.34"

type harness struct {
	w       *Watchdog
	engine  *fakeEngine
	ready   *readyServer
	clock   *clock
	contact time.Time
	cmu     sync.Mutex
	spec    *state.WatchdogSpec
	changes int
}

func newHarness(t *testing.T, role state.WatchdogRole, mode Mode, rcv *receiver, mut ...func(*Options)) *harness {
	t.Helper()
	h := &harness{engine: healthyEngine(), ready: &readyServer{status: 200}, clock: &clock{t: time.Date(2026, 11, 17, 10, 0, 0, 0, time.UTC)}}
	rs := httptest.NewServer(h.ready)
	t.Cleanup(rs.Close)
	h.contact = h.clock.now()
	h.spec = &state.WatchdogSpec{Role: role}
	var eg Egress
	url := ""
	if rcv != nil {
		eg = egressTo(rcv, publicIP)
		url = "https://example.com/T0/secret-token"
		h.spec.Fallback = &state.FallbackChannel{Kind: state.FallbackWebhook, ServerLabel: "server-1"}
	}
	o := Options{
		Engine: h.engine, ReadyURL: rs.URL + "/up/ready", Mode: mode, DataDir: t.TempDir(), Egress: eg,
		Spec: func() *state.WatchdogSpec { return h.spec },
		Open: func(state.Sealed) (map[string]secret.Secret, error) {
			return map[string]secret.Secret{"url": secret.Secret(url)}, nil
		},
		LastContact:   func() time.Time { h.cmu.Lock(); defer h.cmu.Unlock(); return h.contact },
		DashboardHost: "launchroom.example.com", Now: h.clock.now,
		OnChange: func() { h.changes++ },
	}
	if role == state.WatchdogPeer {
		o.Engine, o.ReadyURL = nil, ""
	}
	for _, m := range mut {
		m(&o)
	}
	h.w = New(o)
	return h
}

func (h *harness) touch() { h.cmu.Lock(); h.contact = h.clock.now(); h.cmu.Unlock() }

// step advances the clock by one interval and checks.
func (h *harness) step(t *testing.T, n int) {
	t.Helper()
	for range n {
		h.clock.add(Interval)
		h.w.Check(t.Context())
	}
}

func TestParseMode(t *testing.T) {
	for in, want := range map[string]Mode{"": ModeOn, "on": ModeOn, "notify": ModeNotify, "off": ModeOff} {
		if got, err := ParseMode(in); err != nil || got != want {
			t.Fatalf("%q: %v %v", in, got, err)
		}
	}
	if _, err := ParseMode("yes"); err == nil {
		t.Fatal("accepted yes")
	}
}

func TestRestartsAfterThreeFailingChecks(t *testing.T) {
	h := newHarness(t, state.WatchdogControlPlane, ModeOn, nil)
	h.w.Check(t.Context())
	p := h.w.Pending()
	if p == nil || p.Ready == nil || !*p.Ready || len(p.Services) != 7 {
		t.Fatalf("pending %+v", p)
	}
	h.engine.set(state.CPHorizon, state.ServiceUnhealthy, 0)
	h.step(t, 2)
	if r := h.engine.restarts(); len(r) != 0 {
		t.Fatalf("restarted after 2 checks: %v", r)
	}
	h.step(t, 1)
	if r := h.engine.restarts(); len(r) != 1 || r[0] != "id-horizon" {
		t.Fatalf("restarts %v", r)
	}
	p = h.w.Pending()
	if len(p.Restarts) != 1 || p.Restarts[0].Service != state.CPHorizon || p.Restarts[0].Reason != state.RestartUnhealthy {
		t.Fatalf("pending restarts %+v", p.Restarts)
	}
	rep := state.Report{TypeMeta: state.NewTypeMeta(state.KindReport), ServerID: "srv_01J9Z8X7W6V5T4S3R2Q1P0N9M8", ObservedAt: h.clock.now(), Seq: 1,
		Host: &state.HostStatus{CPUs: 1, DaemonVersion: "1.0.0"}, ControlPlane: p}
	if err := rep.Validate(); err != nil {
		t.Fatalf("report invalid: %v", err)
	}
	h.w.Ack(p)
	if p := h.w.Pending(); len(p.Restarts) != 0 {
		t.Fatalf("not acked: %+v", p.Restarts)
	}
}

func TestHourlyCapAndPersistence(t *testing.T) {
	h := newHarness(t, state.WatchdogControlPlane, ModeOn, nil)
	h.engine.set(state.CPReverb, state.ServiceUnhealthy, 0)
	h.step(t, 30) // 15 minutes of an unhealthy reverb
	if r := h.engine.restarts(); len(r) != maxRestartsPerHour {
		t.Fatalf("%d restarts within the hour, want %d", len(r), maxRestartsPerHour)
	}
	// A new process remembers the history.
	w2 := New(h.w.o)
	if n := w2.persisted.restartsWithin(state.CPReverb, h.clock.now(), time.Hour); n != maxRestartsPerHour {
		t.Fatalf("history after reload: %d", n)
	}
	if p := w2.persisted; len(p.Restarts) != maxRestartsPerHour {
		t.Fatalf("undelivered restarts after reload: %+v", p.Restarts)
	}
	h.clock.add(time.Hour)
	h.step(t, 3)
	if r := h.engine.restarts(); len(r) != maxRestartsPerHour+1 {
		t.Fatalf("no restart after the hour: %d", len(r))
	}
}

func TestNeverRestartsDatabasesOrInNotifyMode(t *testing.T) {
	h := newHarness(t, state.WatchdogControlPlane, ModeOn, nil)
	h.engine.set(state.CPPostgres, state.ServiceUnhealthy, 0)
	h.engine.set(state.CPValkey, state.ServiceStopped, 1)
	h.step(t, 10)
	if r := h.engine.restarts(); len(r) != 0 {
		t.Fatalf("restarted %v", r)
	}
	// Restart refuses databases even when asked directly.
	if h.w.restart(t.Context(), Container{ID: "id-postgres", Service: state.CPPostgres}, state.RestartUnhealthy, h.clock.now()) {
		t.Fatal("restarted postgres")
	}

	n := newHarness(t, state.WatchdogControlPlane, ModeNotify, nil)
	n.engine.set(state.CPDashboard, state.ServiceUnhealthy, 0)
	n.step(t, 10)
	if r := n.engine.restarts(); len(r) != 0 {
		t.Fatalf("notify mode restarted %v", r)
	}
	if p := n.w.Pending(); p == nil || p.Services[0].State != state.ServiceUnhealthy {
		t.Fatalf("notify mode still reports: %+v", p)
	}
}

func TestStoppedOnPurposeStaysStopped(t *testing.T) {
	h := newHarness(t, state.WatchdogControlPlane, ModeOn, nil)
	h.engine.set(state.CPScheduler, state.ServiceStopped, 143)
	h.step(t, 5)
	if r := h.engine.restarts(); len(r) != 0 {
		t.Fatalf("restarted a stopped scheduler: %v", r)
	}
	h.engine.set(state.CPScheduler, state.ServiceStopped, 1)
	h.step(t, 3)
	if r := h.engine.restarts(); len(r) != 1 {
		t.Fatalf("crashed scheduler not restarted: %v", r)
	}
	if p := h.w.Pending(); p.Restarts[0].Reason != state.RestartExited {
		t.Fatalf("reason %v", p.Restarts[0].Reason)
	}
}

func TestHungDashboardRestartedAsNotReady(t *testing.T) {
	h := newHarness(t, state.WatchdogControlPlane, ModeOn, nil)
	h.ready.set(0) // connection dropped: no answer at all
	h.step(t, 3)
	r := h.engine.restarts()
	if len(r) != 1 || r[0] != "id-dashboard" {
		t.Fatalf("restarts %v", r)
	}
	if p := h.w.Pending(); p.Restarts[0].Reason != state.RestartNotReady || *p.Ready {
		t.Fatalf("pending %+v", p)
	}
	// An answering dashboard whose database is down is not restarted.
	g := newHarness(t, state.WatchdogControlPlane, ModeOn, nil)
	g.ready.set(503)
	g.step(t, 10)
	if r := g.engine.restarts(); len(r) != 0 {
		t.Fatalf("restarted an answering dashboard: %v", r)
	}
}

func TestReadyFallsBackToUp(t *testing.T) {
	h := newHarness(t, state.WatchdogControlPlane, ModeOn, nil)
	h.ready.set(404)
	h.w.Check(t.Context())
	h.ready.mu.Lock()
	paths := append([]string(nil), h.ready.paths...)
	h.ready.mu.Unlock()
	if len(paths) != 2 || paths[0] != "/up/ready" || paths[1] != "/up" {
		t.Fatalf("paths %v", paths)
	}
}

func TestFallbackNoticesControlPlane(t *testing.T) {
	rcv := newReceiver(t)
	h := newHarness(t, state.WatchdogControlPlane, ModeNotify, rcv)
	h.ready.set(503)
	h.step(t, 9) // 4.5 minutes
	if n := len(rcv.got()); n != 0 {
		t.Fatalf("notified early: %d", n)
	}
	h.step(t, 2)
	got := rcv.got()
	if len(got) != 1 || got[0]["notice"] != "down" || got[0]["server"] != "server-1" || got[0]["dashboard"] != "launchroom.example.com" {
		t.Fatalf("got %+v", got)
	}
	h.step(t, 20)
	if n := len(rcv.got()); n != 1 {
		t.Fatalf("repeated within 6 h: %d", n)
	}
	h.clock.add(6 * time.Hour)
	h.step(t, 1)
	if got := rcv.got(); len(got) != 2 || got[1]["notice"] != "still_down" {
		t.Fatalf("no still_down: %+v", got)
	}
	// A restarted launchroomd doesn't send "down" again.
	h.w = New(h.w.o)
	h.step(t, 3)
	if n := len(rcv.got()); n != 2 {
		t.Fatalf("down sent again after restart: %d", n)
	}
	h.ready.set(200)
	h.touch()
	h.step(t, 1)
	got = rcv.got()
	if len(got) != 3 || got[2]["notice"] != "back" {
		t.Fatalf("no back: %+v", got)
	}
	p := h.w.Pending()
	if len(p.Notices) == 0 || p.Notices[len(p.Notices)-1].Kind != state.NoticeBack || !p.Notices[len(p.Notices)-1].Sent {
		t.Fatalf("notices %+v", p.Notices)
	}
}

func TestFallbackPeerAfterTenMinutes(t *testing.T) {
	rcv := newReceiver(t)
	h := newHarness(t, state.WatchdogPeer, ModeOn, rcv)
	h.step(t, 19)
	if n := len(rcv.got()); n != 0 {
		t.Fatalf("peer notified early: %d", n)
	}
	h.step(t, 1)
	got := rcv.got()
	if len(got) != 1 || got[0]["notice"] != "down" || got[0]["role"] != "peer" {
		t.Fatalf("got %+v", got)
	}
	if !strings.Contains(got[0]["text"].(string), "server-1 can't reach the launchroom dashboard at launchroom.example.com") {
		t.Fatalf("text %q", got[0]["text"])
	}
	if p := h.w.Pending(); p.Ready != nil || len(p.Services) != 0 {
		t.Fatalf("peer reports services: %+v", p)
	}
}

func TestFailedSendRecordedWithoutSecretsAndRetried(t *testing.T) {
	rcv := newReceiver(t)
	rcv.status = 500
	h := newHarness(t, state.WatchdogPeer, ModeOn, rcv)
	h.step(t, 20)
	p := h.w.Pending()
	if len(p.Notices) != 1 || p.Notices[0].Sent || p.Notices[0].Error != "the channel answered HTTP 500" {
		t.Fatalf("notices %+v", p.Notices)
	}
	h.step(t, 5) // 2.5 min: no retry yet
	if n := len(rcv.got()); n != 1 {
		t.Fatalf("retried too soon: %d", n)
	}
	rcv.mu.Lock()
	rcv.status = 200
	rcv.mu.Unlock()
	h.step(t, 6)
	p = h.w.Pending()
	if len(rcv.got()) != 2 || len(p.Notices) != 1 || !p.Notices[0].Sent {
		t.Fatalf("retry: %d sends, notices %+v", len(rcv.got()), p.Notices)
	}
	for _, n := range p.Notices {
		if strings.Contains(n.Error, "secret-token") || strings.Contains(n.Error, "example.com") {
			t.Fatalf("error leaks the url: %q", n.Error)
		}
	}
}

func TestOffAndNoSpec(t *testing.T) {
	h := newHarness(t, state.WatchdogControlPlane, ModeOff, nil)
	h.engine.set(state.CPDashboard, state.ServiceUnhealthy, 0)
	h.step(t, 10)
	if h.w.Pending() != nil || len(h.engine.restarts()) != 0 {
		t.Fatal("off mode acted")
	}
	g := newHarness(t, state.WatchdogControlPlane, ModeOn, nil)
	g.spec = nil
	g.step(t, 3)
	if g.w.Pending() != nil {
		t.Fatal("ran without system.watchdog")
	}
}

func TestEgressRefusesPrivateAndPlainHTTP(t *testing.T) {
	rcv := newReceiver(t)
	ch := &state.FallbackChannel{Kind: state.FallbackSlack, ServerLabel: "server-1"}
	n := notification{Role: state.WatchdogControlPlane, Kind: state.NoticeDown, DashboardHost: "lr.example.com", At: time.Now()}
	for _, ip := range []string{"10.0.0.5", "127.0.0.1", "169.254.169.254", "100.64.1.2", "192.168.1.1", "fd00::1", "::1", "0.0.0.0", "172.16.0.1"} {
		err := egressTo(rcv, ip).send(t.Context(), ch, map[string]secret.Secret{"url": "https://example.com/services/T/B/x"}, n)
		if !errors.Is(err, errPrivateHost) {
			t.Fatalf("%s: %v", ip, err)
		}
	}
	if len(rcv.got()) != 0 {
		t.Fatal("reached the receiver")
	}
	// Allowed explicitly.
	priv := *ch
	priv.AllowPrivateHosts = true
	if err := egressTo(rcv, "10.0.0.5").send(t.Context(), &priv, map[string]secret.Secret{"url": "https://example.com/services/T/B/x"}, n); err != nil {
		t.Fatalf("allowed private: %v", err)
	}
	// Slack escapes its control sequences.
	ch.ServerLabel = "<!channel> & co"
	if err := egressTo(rcv, publicIP).send(t.Context(), ch, map[string]secret.Secret{"url": "https://example.com/services/T/B/x"}, n); err != nil {
		t.Fatal(err)
	}
	if txt := rcv.got()[1]["text"].(string); strings.Contains(txt, "<!channel>") || !strings.Contains(txt, "&lt;!channel&gt; &amp; co") {
		t.Fatalf("slack text %q", txt)
	}
	for _, u := range []string{"http://hooks.example.com/x", "https://user:pw@hooks.example.com/x", "ftp://x"} {
		if err := egressTo(rcv, publicIP).send(t.Context(), ch, map[string]secret.Secret{"url": secret.Secret(u)}, n); err == nil || strings.Contains(err.Error(), "hooks.example.com") {
			t.Fatalf("%s: %v", u, err)
		}
	}
}

func TestEgressNoRedirects(t *testing.T) {
	rcv := newReceiver(t)
	rcv.status = http.StatusFound
	ch := &state.FallbackChannel{Kind: state.FallbackDiscord, ServerLabel: "server-1"}
	err := egressTo(rcv, publicIP).send(t.Context(), ch, map[string]secret.Secret{"url": "https://example.com/api/webhooks/1/x"},
		notification{Role: state.WatchdogPeer, Kind: state.NoticeBack, At: time.Now()})
	if err == nil || err.Error() != "the channel answered HTTP 302" {
		t.Fatalf("err %v", err)
	}
	if m := rcv.got()[0]; m["allowed_mentions"] == nil || m["content"] == nil {
		t.Fatalf("discord body %+v", m)
	}
}

func TestTemplatesAreConstant(t *testing.T) {
	for _, role := range []state.WatchdogRole{state.WatchdogControlPlane, state.WatchdogPeer} {
		for _, kind := range []state.FallbackNoticeKind{state.NoticeDown, state.NoticeStillDown, state.NoticeBack} {
			subject, text := message(role, kind, "lr.example.com", "web-1\r\nBcc: x")
			if subject == "" || !strings.Contains(text, "lr.example.com") || !strings.Contains(text, "web-1Bcc: x") || strings.ContainsAny(text, "\r\n") {
				t.Fatalf("%s/%s: %q %q", role, kind, subject, text)
			}
			for _, word := range []string{"agent", "daemon", "—", " just ", "simple", "easy"} {
				if strings.Contains(strings.ToLower(text+subject), word) {
					t.Fatalf("%s/%s uses %q: %q", role, kind, word, text)
				}
			}
		}
	}
}

func TestPublicAddr(t *testing.T) {
	for in, want := range map[string]bool{
		"93.184.216.34": true, "2606:2800:220:1::1": true,
		"10.1.2.3": false, "100.100.0.1": false, "fe80::1": false, "fc00::1": false, "224.0.0.1": false, "::ffff:10.0.0.1": false,
	} {
		if got := publicAddr(netip.MustParseAddr(in).Unmap()); got != want {
			t.Fatalf("%s: %v", in, got)
		}
	}
}
