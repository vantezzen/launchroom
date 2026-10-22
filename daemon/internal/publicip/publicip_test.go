package publicip

import (
	"context"
	"net"
	"net/http"
	"net/http/httptest"
	"net/netip"
	"strings"
	"sync"
	"sync/atomic"
	"testing"
	"time"

	"github.com/vantezzen/launchroom/daemon/internal/state"
)

func TestPublic(t *testing.T) {
	refused := []string{
		"10.1.2.3", "172.16.0.1", "172.17.0.1", "172.18.0.1", "192.168.1.10", // private, Docker bridges
		"100.64.0.1", "100.127.255.254", // CGNAT / mesh
		"127.0.0.1", "169.254.169.254", "0.0.0.0", "192.0.2.1", "198.51.100.7", "203.0.113.7", // loopback, link-local, docs
		"224.0.0.1", "255.255.255.255", "198.18.0.1",
		"::1", "::", "fe80::1", "fd00::1", "fc00::5", "2001:db8::1", "ff02::1", "2002:c000:204::1",
		"::ffff:93.184.216.34", "64:ff9b::808:808",
	}
	for _, s := range refused {
		if Public(netip.MustParseAddr(s)) {
			t.Errorf("%s accepted as public", s)
		}
	}
	for _, s := range []string{"93.184.216.34", "8.8.8.8", "1.1.1.1", "2606:2800:220:1:248:1893:25c8:1946", "2a01:4f8::1"} {
		if !Public(netip.MustParseAddr(s)) {
			t.Errorf("%s refused", s)
		}
	}
	if Public(netip.Addr{}) {
		t.Error("zero address accepted")
	}
}

func TestParseConfigured(t *testing.T) {
	p, err := ParseConfigured(" 93.184.216.34 , 2606:2800:220:1:248:1893:25c8:1946 ")
	if err != nil || p.IPv4 != "93.184.216.34" || p.IPv6 != "2606:2800:220:1:248:1893:25c8:1946" {
		t.Fatalf("got %+v, %v", p, err)
	}
	if p, err := ParseConfigured(""); p != nil || err != nil {
		t.Fatalf("empty: %+v %v", p, err)
	}
	for _, bad := range []string{"172.18.0.1", "1.1.1.1,8.8.8.8", "nope", "1.1.1.1,2a01:4f8::1,8.8.8.8", "::ffff:1.1.1.1", "fe80::1%eth0"} {
		if _, err := ParseConfigured(bad); err == nil {
			t.Errorf("%q accepted", bad)
		}
	}
}

// echo serves fixed answers per path and records the dial family.
func echo(t *testing.T, answers map[string]string) (*httptest.Server, *Options, *[]string) {
	t.Helper()
	srv := httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		a, ok := answers[r.URL.Path]
		if !ok {
			http.Error(w, "no", http.StatusInternalServerError)
			return
		}
		_, _ = w.Write([]byte(a))
	}))
	t.Cleanup(srv.Close)
	var families []string
	var mu sync.Mutex
	base := srv.Client().Transport.(*http.Transport) //nolint:forcetypeassert // httptest
	o := &Options{
		Transport: base,
		Dial: func(ctx context.Context, network, _ string) (net.Conn, error) {
			mu.Lock()
			families = append(families, network)
			mu.Unlock()
			var d net.Dialer
			return d.DialContext(ctx, "tcp", srv.Listener.Addr().String())
		},
	}
	return srv, o, &families
}

func TestDetectFirstValidPublicAnswerPerFamily(t *testing.T) {
	srv, o, families := echo(t, map[string]string{
		"/private": "172.18.0.1\n", "/garbage": "<html>", "/v4": " 93.184.216.34\n",
		"/v6wrong": "93.184.216.34", "/v6": "2606:2800:220:1:248:1893:25c8:1946\n",
		"/long": strings.Repeat("1", 100),
	})
	o.IPv4URLs = []string{srv.URL + "/down", srv.URL + "/private", srv.URL + "/garbage", srv.URL + "/long", srv.URL + "/v4", srv.URL + "/v6"}
	o.IPv6URLs = []string{srv.URL + "/v6wrong", srv.URL + "/v6"}
	var changes atomic.Int32
	o.OnChange = func() { changes.Add(1) }
	d := New(*o)
	if d.Snapshot() != nil {
		t.Fatal("snapshot before detection")
	}
	if !d.Detect(context.Background()) {
		t.Fatal("nothing detected")
	}
	got := d.Snapshot()
	if got.IPv4 != "93.184.216.34" || got.IPv6 != "2606:2800:220:1:248:1893:25c8:1946" || got.DetectedAt.IsZero() {
		t.Fatalf("got %+v", got)
	}
	sawV4, sawV6 := false, false
	for _, f := range *families {
		sawV4 = sawV4 || f == "tcp4"
		sawV6 = sawV6 || f == "tcp6"
		if f != "tcp4" && f != "tcp6" {
			t.Fatalf("family %q", f)
		}
	}
	if !sawV4 || !sawV6 {
		t.Fatalf("families %v", *families)
	}
	d.Detect(context.Background())
	if changes.Load() != 1 {
		t.Fatalf("OnChange called %d times, want 1", changes.Load())
	}
}

func TestDetectNothing(t *testing.T) {
	srv, o, _ := echo(t, map[string]string{"/private": "10.0.0.1"})
	o.IPv4URLs = []string{srv.URL + "/private"}
	o.IPv6URLs = []string{srv.URL + "/missing"}
	d := New(*o)
	if d.Detect(context.Background()) || d.Snapshot() != nil {
		t.Fatal("detected something")
	}
}

func TestOffAndConfigured(t *testing.T) {
	var dialed atomic.Bool
	dial := func(context.Context, string, string) (net.Conn, error) { dialed.Store(true); return nil, net.ErrClosed }
	off := New(Options{Off: true, Dial: dial})
	if off.Detect(context.Background()) || off.Snapshot() != nil {
		t.Fatal("off detector found something")
	}
	ctx, cancel := context.WithTimeout(context.Background(), 50*time.Millisecond)
	defer cancel()
	off.Run(ctx)

	cfg := &state.PublicAddresses{IPv4: "93.184.216.34"}
	c := New(Options{Configured: cfg, Dial: dial})
	c.Run(ctx)
	if !c.Detect(context.Background()) || c.Snapshot().IPv4 != "93.184.216.34" || c.Snapshot().DetectedAt.IsZero() {
		t.Fatalf("configured: %+v", c.Snapshot())
	}
	if dialed.Load() {
		t.Fatal("echo services contacted")
	}
}

func TestRunRetries(t *testing.T) {
	var calls atomic.Int32
	srv := httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		if calls.Add(1) < 3 {
			http.Error(w, "busy", http.StatusServiceUnavailable)
			return
		}
		_, _ = w.Write([]byte("93.184.216.34"))
	}))
	defer srv.Close()
	d := New(Options{
		IPv4URLs: []string{srv.URL}, IPv6URLs: []string{}, Transport: srv.Client().Transport.(*http.Transport), //nolint:forcetypeassert // httptest
		Dial: func(ctx context.Context, _, _ string) (net.Conn, error) {
			var dl net.Dialer
			return dl.DialContext(ctx, "tcp", srv.Listener.Addr().String())
		},
		Retry: 10 * time.Millisecond, Interval: time.Hour,
	})
	ctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
	defer cancel()
	go d.Run(ctx)
	for d.Snapshot() == nil {
		if ctx.Err() != nil {
			t.Fatal("never detected")
		}
		time.Sleep(5 * time.Millisecond)
	}
}
