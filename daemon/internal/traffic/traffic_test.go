package traffic

import (
	"context"
	"fmt"
	"net"
	"strings"
	"sync"
	"sync/atomic"
	"testing"
	"time"

	"github.com/vantezzen/launchroom/daemon/internal/state"
)

const (
	routeWeb = state.RouteID("rt_01J9Z8X7W6V5T4S3R2Q1P0N9AA")
	routeAPI = state.RouteID("rt_01J9Z8X7W6V5T4S3R2Q1P0N9BB")
)

type clock struct {
	mu sync.Mutex
	t  time.Time
}

func (c *clock) now() time.Time { c.mu.Lock(); defer c.mu.Unlock(); return c.t }
func (c *clock) set(t time.Time) {
	c.mu.Lock()
	c.t = t
	c.mu.Unlock()
}

func newCollector(t *testing.T) (*Collector, *clock, *atomic.Int32) {
	t.Helper()
	clk := &clock{t: time.Date(2026, 10, 19, 12, 0, 10, 0, time.UTC)}
	var kicks atomic.Int32
	c := New(Options{Now: clk.now, OnMinute: func() { kicks.Add(1) }})
	c.SetRoutes([]state.RouteSpec{
		{ID: routeWeb, Hosts: []state.Hostname{"shop.com", "www.shop.com"}},
		{ID: routeAPI, Hosts: []state.Hostname{"api.shop.com"}},
	})
	return c, clk, &kicks
}

func line(host string, status int, seconds float64, size, read int64) []byte {
	return fmt.Appendf(nil, `{"level":"info","ts":1.7e9,"logger":"http.log.access.lr-access","msg":"handled request","request":{"host":%q,"method":"GET"},"bytes_read":%d,"duration":%g,"size":%d,"status":%d}`+"\n",
		host, read, seconds, size, status)
}

func TestRouteLookup(t *testing.T) {
	c, _, _ := newCollector(t)
	for host, want := range map[string]state.RouteID{
		"shop.com": routeWeb, "SHOP.com:8443": routeWeb, "www.shop.com.": routeWeb, "api.shop.com": routeAPI, "other.com": "",
	} {
		got, ok := c.Route(host)
		if got != want || ok != (want != "") {
			t.Errorf("Route(%q) = %q, %v; want %q", host, got, ok, want)
		}
	}
}

func TestAggregationAndRollover(t *testing.T) {
	c, clk, kicks := newCollector(t)
	c.Observe(line("shop.com", 200, 0.004, 1000, 10))   // 5 ms bucket (index 2)
	c.Observe(line("www.shop.com", 301, 0.001, 0, 0))   // 1 ms bucket (0)
	c.Observe(line("shop.com:443", 404, 0.0011, 50, 0)) // 2 ms bucket (1)
	c.Observe(line("shop.com", 502, 31, 20, 0))         // overflow
	c.Observe(line("shop.com", 101, 600, 0, 0))         // upgrade: requests only
	c.Observe(line("api.shop.com", 200, 0.25, 7, 3))    // 250 ms bucket (7)
	c.Observe(line("unknown.com", 200, 0.1, 1, 1))      // dropped
	c.Observe([]byte("not json\n"))                     // dropped
	if c.Pending() != nil {
		t.Fatal("minute not complete yet")
	}
	clk.set(clk.now().Add(time.Minute))
	c.Roll()
	if kicks.Load() != 1 {
		t.Fatalf("kicks = %d", kicks.Load())
	}
	got := c.Pending()
	if len(got) != 2 {
		t.Fatalf("rows = %+v", got)
	}
	web := got[1]
	if got[0].Route == routeWeb {
		web = got[0]
	}
	at := time.Date(2026, 10, 19, 12, 0, 0, 0, time.UTC)
	if web.Route != routeWeb || !web.At.Equal(at) || web.Requests != 5 || web.Status2xx != 1 || web.Status3xx != 1 ||
		web.Status4xx != 1 || web.Status5xx != 1 || web.BytesOut != 1070 || web.BytesIn != 10 {
		t.Fatalf("web = %+v", web)
	}
	want := []int64{1, 1, 1, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 1}
	if fmt.Sprint(web.LatencyMs) != fmt.Sprint(want) {
		t.Fatalf("latency = %v, want %v", web.LatencyMs, want)
	}
	m := state.ReportMetrics{Host: []state.HostMetricSample{}, Workloads: []state.WorkloadMetricSample{}, Requests: got}
	r := state.Report{TypeMeta: state.NewTypeMeta(state.KindReport), ServerID: "srv_01J9Z8X7W6V5T4S3R2Q1P0N9MK", ObservedAt: at, Seq: 1, Full: true, Metrics: &m}
	if err := r.Validate(); err != nil {
		t.Fatalf("collected rows don't validate: %v", err)
	}

	// Ack drops what was sent; a later minute stays.
	c.Observe(line("api.shop.com", 200, 0.01, 1, 1))
	clk.set(clk.now().Add(time.Minute))
	c.Roll()
	c.Ack(got)
	if p := c.Pending(); len(p) != 1 || p[0].Route != routeAPI || p[0].Requests != 1 {
		t.Fatalf("after ack = %+v", p)
	}
}

func TestOnlyUpgradesHaveNoHistogram(t *testing.T) {
	c, clk, _ := newCollector(t)
	c.Observe(line("shop.com", 101, 30, 0, 0))
	clk.set(clk.now().Add(time.Minute))
	c.Roll()
	p := c.Pending()
	if len(p) != 1 || p[0].LatencyMs != nil || p[0].Requests != 1 {
		t.Fatalf("pending = %+v", p)
	}
}

func TestBucketEdges(t *testing.T) {
	for ms, want := range map[float64]int{0: 0, 1: 0, 1.0001: 1, 2: 1, 30000: 13, 30000.1: 14, 1e9: 14} {
		if got := state.LatencyBucket(ms); got != want {
			t.Errorf("LatencyBucket(%g) = %d, want %d", ms, got, want)
		}
	}
}

func TestBufferBound(t *testing.T) {
	c, clk, _ := newCollector(t)
	for i := range MaxRows + 5 {
		c.Observe(line("shop.com", 200, 0.01, 1, 1))
		clk.set(clk.now().Add(time.Minute))
		c.Roll()
		_ = i
	}
	if n := len(c.Pending()); n != MaxRows {
		t.Fatalf("pending = %d", n)
	}
}

func TestServeReadsLinesAndSkipsLongOnes(t *testing.T) {
	c, clk, _ := newCollector(t)
	var lc net.ListenConfig
	ln, err := lc.Listen(t.Context(), "tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	ctx, cancel := context.WithCancel(t.Context())
	done := make(chan error, 1)
	go func() { done <- c.ServeListener(ctx, ln) }()

	var d net.Dialer
	conn, err := d.DialContext(t.Context(), "tcp", ln.Addr().String())
	if err != nil {
		t.Fatal(err)
	}
	var b strings.Builder
	b.Write(line("shop.com", 200, 0.01, 1, 1))
	b.WriteString(`{"request":{"host":"shop.com"},"status":500,"x":"` + strings.Repeat("a", MaxLineBytes+10) + `"}` + "\n")
	b.Write(line("shop.com", 200, 0.01, 1, 1))
	if _, err := conn.Write([]byte(b.String())); err != nil {
		t.Fatal(err)
	}
	_ = conn.Close()

	deadline := time.Now().Add(5 * time.Second)
	for {
		c.mu.Lock()
		var n int64
		if a := c.acc[routeWeb]; a != nil {
			n = a.Requests
		}
		c.mu.Unlock()
		if n == 2 {
			break
		}
		if time.Now().After(deadline) {
			t.Fatalf("requests = %d, want 2 (the long line skipped)", n)
		}
		time.Sleep(10 * time.Millisecond)
	}
	clk.set(clk.now().Add(time.Minute))
	c.Roll()
	if p := c.Pending(); len(p) != 1 || p[0].Status5xx != 0 {
		t.Fatalf("pending = %+v", p)
	}
	cancel()
	if err := <-done; err != nil {
		t.Fatal(err)
	}
}

func TestServeRefusesNonLoopbackAddress(t *testing.T) {
	c, _, _ := newCollector(t)
	if err := c.Serve(t.Context(), "0.0.0.0:2022"); err == nil {
		t.Fatal("non-loopback address accepted")
	}
}
