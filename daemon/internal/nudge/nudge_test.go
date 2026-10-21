package nudge

import (
	"context"
	"encoding/json/v2"
	"errors"
	"net/http"
	"net/http/httptest"
	"net/url"
	"strconv"
	"strings"
	"sync"
	"testing"
	"time"

	"github.com/coder/websocket"

	"github.com/vantezzen/launchroom/daemon/internal/controlplane"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

const testServer = state.ServerID("srv_01J9Z8X7W6V5T4S3R2Q1P0N9MK")

type fakeCP struct {
	mu     sync.Mutex
	cfg    *state.DaemonConfig
	cfgErr error
	auths  []string // "socket|channel"
}

func (f *fakeCP) FetchConfig(context.Context) (*state.DaemonConfig, error) {
	f.mu.Lock()
	defer f.mu.Unlock()
	return f.cfg, f.cfgErr
}

func (f *fakeCP) BroadcastAuth(_ context.Context, socketID state.SocketID, ch state.DaemonChannel) (state.PusherAuth, error) {
	f.mu.Lock()
	defer f.mu.Unlock()
	f.auths = append(f.auths, string(socketID)+"|"+string(ch))
	return state.PusherAuth("key:" + strings.Repeat("a", 64)), nil
}

// fakePusher is a minimal Pusher-protocol server.
type fakePusher struct {
	t        *testing.T
	mu       sync.Mutex
	conns    int
	subs     []map[string]string
	gotPong  chan struct{}
	gotPing  chan struct{}
	send     chan string // frames to push to the current connection
	closeNow chan struct{}
	paths    []string
	origins  []string
}

func newFakePusher(t *testing.T) (*fakePusher, *httptest.Server) {
	p := &fakePusher{t: t, gotPong: make(chan struct{}, 4), gotPing: make(chan struct{}, 4), send: make(chan string, 16), closeNow: make(chan struct{}, 1)}
	srv := httptest.NewServer(http.HandlerFunc(p.serve))
	t.Cleanup(srv.Close)
	return p, srv
}

func (p *fakePusher) serve(w http.ResponseWriter, r *http.Request) {
	p.mu.Lock()
	p.conns++
	n := p.conns
	p.paths = append(p.paths, r.URL.RequestURI())
	p.origins = append(p.origins, r.Header.Get("Origin"))
	p.mu.Unlock()
	c, err := websocket.Accept(w, r, &websocket.AcceptOptions{InsecureSkipVerify: true}) // the daemon sends the dashboard Origin
	if err != nil {
		return
	}
	defer func() { _ = c.CloseNow() }()
	ctx := r.Context()
	est := `{"event":"pusher:connection_established","data":"{\"socket_id\":\"` + "1234." + strconv.Itoa(n) + `\",\"activity_timeout\":30}"}`
	if err := c.Write(ctx, websocket.MessageText, []byte(est)); err != nil {
		return
	}
	in := make(chan map[string]any)
	go func() {
		for {
			_, data, err := c.Read(ctx)
			if err != nil {
				close(in)
				return
			}
			var m map[string]any
			_ = json.Unmarshal(data, &m)
			in <- m
		}
	}()
	for {
		select {
		case m, ok := <-in:
			if !ok {
				return
			}
			switch m["event"] {
			case "pusher:subscribe":
				d, _ := m["data"].(map[string]any)
				sub := map[string]string{}
				for k, v := range d {
					sub[k], _ = v.(string)
				}
				p.mu.Lock()
				p.subs = append(p.subs, sub)
				p.mu.Unlock()
				_ = c.Write(ctx, websocket.MessageText, []byte(`{"event":"pusher_internal:subscription_succeeded","channel":"`+sub["channel"]+`","data":"{}"}`))
			case "pusher:pong":
				p.gotPong <- struct{}{}
			case "pusher:ping":
				p.gotPing <- struct{}{}
				_ = c.Write(ctx, websocket.MessageText, []byte(`{"event":"pusher:pong","data":"{}"}`))
			}
		case f := <-p.send:
			_ = c.Write(ctx, websocket.MessageText, []byte(f))
		case <-p.closeNow:
			_ = c.Close(websocket.StatusGoingAway, "bye")
			return
		case <-ctx.Done():
			return
		}
	}
}

func wsURL(s *httptest.Server) state.WebSocketURL {
	return state.WebSocketURL("ws" + strings.TrimPrefix(s.URL, "http"))
}

func wait[T any](t *testing.T, ch <-chan T, what string) T {
	t.Helper()
	select {
	case v := <-ch:
		return v
	case <-time.After(5 * time.Second):
		t.Fatalf("timed out waiting for %s", what)
	}
	var zero T
	return zero
}

func TestSubscribesAndDispatchesNudges(t *testing.T) {
	p, srv := newFakePusher(t)
	cp := &fakeCP{cfg: &state.DaemonConfig{TypeMeta: state.NewTypeMeta(state.KindDaemonConfig), Nudge: &state.NudgeConfig{Key: "app-key", URL: wsURL(srv)}}}
	events := make(chan string, 8)
	subscribed := make(chan struct{}, 4)
	cpURL, _ := url.Parse("https://dash.example.com")
	ctx, cancel := context.WithCancel(context.Background())
	done := make(chan struct{})
	go func() {
		defer close(done)
		Run(ctx, Options{
			CP: cp, ServerID: testServer, ControlPlaneURL: cpURL, DaemonVersion: "1.2.3",
			OnEvent: func(e string) { events <- e }, OnSubscribed: func() { subscribed <- struct{}{} },
			BackoffMin: 10 * time.Millisecond, BackoffMax: 20 * time.Millisecond,
		})
	}()
	defer func() { cancel(); <-done }()

	wait(t, subscribed, "subscription")
	p.mu.Lock()
	if len(p.subs) != 1 || p.subs[0]["channel"] != "private-daemon."+string(testServer) || !strings.HasPrefix(p.subs[0]["auth"], "key:") {
		t.Fatalf("subscribe = %+v", p.subs)
	}
	if !strings.HasPrefix(p.paths[0], "/app/app-key?") || !strings.Contains(p.paths[0], "protocol=7") || p.origins[0] != "https://dash.example.com" {
		t.Fatalf("path %q origin %q", p.paths[0], p.origins[0])
	}
	p.mu.Unlock()
	if len(cp.auths) != 1 || cp.auths[0] != "1234.1|private-daemon."+string(testServer) {
		t.Fatalf("auths = %v", cp.auths)
	}

	// Nudges on our channel are dispatched; other channels and unknown events are not.
	p.send <- `{"event":"state.changed","channel":"private-daemon.srv_01J9Z8X7W6V5T4S3R2Q1P0N9MZ","data":"{}"}`
	p.send <- `{"event":"something.else","channel":"private-daemon.` + string(testServer) + `","data":"{}"}`
	p.send <- `{"event":"task.available","channel":"private-daemon.` + string(testServer) + `","data":"[]"}`
	if got := wait(t, events, "event"); got != state.NudgeTaskAvailable {
		t.Fatalf("event = %q", got)
	}

	// Server pings are answered.
	p.send <- `{"event":"pusher:ping","data":"{}"}`
	wait(t, p.gotPong, "pong")

	// A dropped connection reconnects and re-subscribes.
	p.closeNow <- struct{}{}
	wait(t, subscribed, "re-subscription")
	p.send <- `{"event":"state.changed","channel":"private-daemon.` + string(testServer) + `","data":"{}"}`
	if got := wait(t, events, "event after reconnect"); got != state.NudgeStateChanged {
		t.Fatalf("event = %q", got)
	}
}

func TestFallsBackToEnrollmentConfigOn404(t *testing.T) {
	p, srv := newFakePusher(t)
	cp := &fakeCP{cfgErr: &controlplane.APIError{Status: http.StatusNotFound}}
	subscribed := make(chan struct{}, 1)
	ctx, cancel := context.WithCancel(context.Background())
	done := make(chan struct{})
	go func() {
		defer close(done)
		Run(ctx, Options{CP: cp, ServerID: testServer, Fallback: &state.NudgeConfig{Key: "k", URL: wsURL(srv)},
			OnSubscribed: func() { subscribed <- struct{}{} }})
	}()
	defer func() { cancel(); <-done }()
	wait(t, subscribed, "subscription")
	p.mu.Lock()
	defer p.mu.Unlock()
	if p.conns != 1 {
		t.Fatalf("conns = %d", p.conns)
	}
}

func TestNoConfigMeansNoConnection(t *testing.T) {
	cp := &fakeCP{cfg: &state.DaemonConfig{TypeMeta: state.NewTypeMeta(state.KindDaemonConfig)}}
	ctx, cancel := context.WithTimeout(context.Background(), 100*time.Millisecond)
	defer cancel()
	Run(ctx, Options{CP: cp, ServerID: testServer, ConfigRetry: time.Hour}) // returns when ctx ends
}

// TestUnreachableDashboardRetriesWithBackoff: while the dashboard can't be
// asked for the config (control plane restarting) and there is no fallback,
// the daemon retries on the connection backoff, not after ConfigRetry, so
// nudges come back seconds after the dashboard does (failover test:
// they stayed off for 5 minutes and NodeStates waited for polls).
func TestUnreachableDashboardRetriesWithBackoff(t *testing.T) {
	p, srv := newFakePusher(t)
	cp := &fakeCP{cfgErr: errors.New("dial tcp: connection refused")}
	subscribed := make(chan struct{}, 1)
	ctx, cancel := context.WithCancel(context.Background())
	done := make(chan struct{})
	go func() {
		defer close(done)
		Run(ctx, Options{CP: cp, ServerID: testServer, ConfigRetry: time.Hour, BackoffMin: 10 * time.Millisecond, BackoffMax: 20 * time.Millisecond,
			OnSubscribed: func() { subscribed <- struct{}{} }})
	}()
	defer func() { cancel(); <-done }()
	time.Sleep(50 * time.Millisecond)
	cp.mu.Lock()
	cp.cfgErr = nil
	cp.cfg = &state.DaemonConfig{TypeMeta: state.NewTypeMeta(state.KindDaemonConfig), Nudge: &state.NudgeConfig{Key: "k", URL: wsURL(srv)}}
	cp.mu.Unlock()
	wait(t, subscribed, "subscription after the dashboard returned")
	p.mu.Lock()
	defer p.mu.Unlock()
	if p.conns != 1 {
		t.Fatalf("conns = %d", p.conns)
	}
}

func TestServerErrorReconnectsWithBackoff(t *testing.T) {
	var mu sync.Mutex
	conns := 0
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		mu.Lock()
		conns++
		mu.Unlock()
		c, err := websocket.Accept(w, r, &websocket.AcceptOptions{InsecureSkipVerify: true}) // the daemon sends the dashboard Origin
		if err != nil {
			return
		}
		defer func() { _ = c.CloseNow() }()
		_ = c.Write(r.Context(), websocket.MessageText, []byte(`{"event":"pusher:error","data":{"code":4001,"message":"App key does not exist"}}`))
		_, _, _ = c.Read(r.Context())
	}))
	t.Cleanup(srv.Close)
	cp := &fakeCP{cfg: &state.DaemonConfig{TypeMeta: state.NewTypeMeta(state.KindDaemonConfig), Nudge: &state.NudgeConfig{Key: "k", URL: wsURL(srv)}}}
	ctx, cancel := context.WithTimeout(context.Background(), 300*time.Millisecond)
	defer cancel()
	Run(ctx, Options{CP: cp, ServerID: testServer, BackoffMin: 20 * time.Millisecond, BackoffMax: 40 * time.Millisecond})
	mu.Lock()
	defer mu.Unlock()
	if conns < 2 || conns > 20 {
		t.Fatalf("conns = %d, want a few reconnects with backoff", conns)
	}
}

func TestEndpoint(t *testing.T) {
	cp, _ := url.Parse("https://dash.example.com/base")
	for _, tc := range []struct {
		cfg  state.NudgeConfig
		cp   *url.URL
		want string
	}{
		{state.NudgeConfig{Key: "k"}, cp, "wss://dash.example.com/base/app/k?client=launchroomd&flash=false&protocol=7&version=1.0.0"},
		{state.NudgeConfig{Key: "k", URL: "ws://reverb:6001"}, cp, "ws://reverb:6001/app/k?client=launchroomd&flash=false&protocol=7&version=1.0.0"},
		{state.NudgeConfig{Key: "k"}, mustURL("http://127.0.0.1:8000"), "ws://127.0.0.1:8000/app/k?client=launchroomd&flash=false&protocol=7&version=1.0.0"},
	} {
		got, err := Endpoint(&tc.cfg, tc.cp, "1.0.0")
		if err != nil || got != tc.want {
			t.Errorf("Endpoint(%+v) = %q, %v; want %q", tc.cfg, got, err, tc.want)
		}
	}
	if _, err := Endpoint(&state.NudgeConfig{Key: "k"}, nil, "1"); err == nil {
		t.Error("no URL at all accepted")
	}
}

func TestActivityInterval(t *testing.T) {
	for in, want := range map[int]time.Duration{0: 28 * time.Second, 30: 28 * time.Second, 5: 10 * time.Second, 600: 120 * time.Second} {
		if got := activityInterval(in); got != want {
			t.Errorf("activityInterval(%d) = %v, want %v", in, got, want)
		}
	}
}

func mustURL(s string) *url.URL {
	u, err := url.Parse(s)
	if err != nil {
		panic(errors.New(s))
	}
	return u
}

// The endpoint follows the client's active base URL (an adopted canonical
// origin, docs/03 §4.2) when ControlPlaneBase is set.
func TestEndpointFollowsActiveBase(t *testing.T) {
	configured, _ := url.Parse("https://1-2-3-4.sslip.io")
	active := configured
	o := Options{ControlPlaneURL: configured, ControlPlaneBase: func() *url.URL { return active }}
	cfg := &state.NudgeConfig{Key: "k"}
	ep, err := Endpoint(cfg, o.controlPlane(), "1.0.0")
	if err != nil || !strings.HasPrefix(ep, "wss://1-2-3-4.sslip.io/app/k?") {
		t.Fatalf("endpoint = %s (%v)", ep, err)
	}
	active, _ = url.Parse("https://dash.example.com")
	ep, err = Endpoint(cfg, o.controlPlane(), "1.0.0")
	if err != nil || !strings.HasPrefix(ep, "wss://dash.example.com/app/k?") {
		t.Fatalf("endpoint after adoption = %s (%v)", ep, err)
	}
	active = nil
	if got := o.controlPlane(); got != configured {
		t.Fatalf("nil active base: %v", got)
	}
}
