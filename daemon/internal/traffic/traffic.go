// Package traffic turns the edge Caddy's access log into per-route,
// per-minute request metrics (docs/15 §1). Caddy writes its access log with
// the standard "net" writer to a loopback TCP listener served here; every
// line is parsed, attributed to a route by its host and folded into the
// current minute's counters and latency histogram. Nothing per request is
// kept: completed minutes are buffered until a report carrying them was
// accepted, like the usage metrics. The same lines feed the request
// analytics (analytics.go, docs/15 §5): normalised paths, referrer hosts and
// client classes per route and 10-minute window.
package traffic

import (
	"bufio"
	"context"
	"encoding/json"
	"errors"
	"io"
	"log/slog"
	"net"
	"net/netip"
	"slices"
	"strings"
	"sync"
	"sync/atomic"
	"time"

	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// Bounds and defaults.
const (
	// DefaultAddr is the loopback address Caddy's access log is written to.
	DefaultAddr = "127.0.0.1:2022"
	// MaxRows bounds the undelivered route-minutes (oldest dropped).
	MaxRows = 4096
	// MaxLineBytes bounds one access log line; longer lines are skipped.
	MaxLineBytes = 64 << 10
	// MaxConns bounds concurrent connections (Caddy keeps one per log).
	MaxConns = 8
	// DefaultTick completes idle minutes.
	DefaultTick = 15 * time.Second
)

// Options configures a Collector.
type Options struct {
	// OnMinute is called after a minute with traffic was completed (kick a report).
	OnMinute func()
	// Tick is how often idle minutes are completed (default 15 s).
	Tick time.Duration
	Now  func() time.Time
	Log  *slog.Logger
}

// Collector aggregates access log lines. Safe for concurrent use.
type Collector struct {
	o      Options
	routes atomic.Pointer[map[string]state.RouteID]

	mu      sync.Mutex
	minute  time.Time
	acc     map[state.RouteID]*state.RequestMetricSample
	pending []state.RequestMetricSample
	an      analytics
}

// New returns a Collector without routes (every line is dropped until SetRoutes).
func New(o Options) *Collector {
	if o.Tick <= 0 {
		o.Tick = DefaultTick
	}
	if o.Now == nil {
		o.Now = time.Now
	}
	if o.Log == nil {
		o.Log = slog.New(slog.DiscardHandler)
	}
	return &Collector{o: o, acc: map[state.RouteID]*state.RequestMetricSample{}, an: newAnalytics()}
}

// SetRoutes replaces the host → route table (the edge's current NodeState routes).
func (c *Collector) SetRoutes(routes []state.RouteSpec) {
	m := make(map[string]state.RouteID, len(routes))
	for _, r := range routes {
		for _, h := range r.Hosts {
			m[strings.ToLower(string(h))] = r.ID
		}
	}
	c.routes.Store(&m)
}

// Route looks up the route of a request host ("Host" header: lowercased,
// port and a trailing dot removed).
func (c *Collector) Route(host string) (state.RouteID, bool) {
	m := c.routes.Load()
	if m == nil {
		return "", false
	}
	id, ok := (*m)[normalizeHost(host)]
	return id, ok
}

func normalizeHost(host string) string {
	host = strings.ToLower(strings.TrimSpace(host))
	if h, _, err := net.SplitHostPort(host); err == nil {
		host = h
	}
	return strings.TrimSuffix(strings.Trim(host, "[]"), ".")
}

// accessLine is the subset of a Caddy access log line we read (the rest is
// deleted by the filter encoder before it leaves Caddy, docs/15 §1.1). The
// URI arrives without its query string; lr_referer is only the referrer's
// host and lr_agent one user agent token (docs/15 §5.1).
type accessLine struct {
	Request struct {
		Host string `json:"host"`
		URI  string `json:"uri"`
	} `json:"request"`
	Referer  string  `json:"lr_referer"`
	Agent    string  `json:"lr_agent"`
	Status   int     `json:"status"`
	Duration float64 `json:"duration"` // seconds
	Size     int64   `json:"size"`
	Read     int64   `json:"bytes_read"`
}

// Observe folds one access log line into the current minute. Lines that
// don't parse, or whose host has no route, are dropped.
func (c *Collector) Observe(line []byte) {
	var l accessLine
	if json.Unmarshal(line, &l) != nil || l.Request.Host == "" {
		return
	}
	route, ok := c.Route(l.Request.Host)
	if !ok {
		return
	}
	now := c.o.Now().UTC()
	c.mu.Lock()
	completed := c.rollLocked(now)
	c.an.roll(now)
	c.an.observe(now, route, &l, c.Route)
	if c.minute.IsZero() {
		c.minute = now.Truncate(time.Minute)
	}
	a := c.acc[route]
	if a == nil {
		a = &state.RequestMetricSample{Route: route, At: c.minute}
		c.acc[route] = a
	}
	a.Requests++
	a.BytesIn += max(l.Read, 0)
	a.BytesOut += max(l.Size, 0)
	if l.Status >= 200 {
		switch {
		case l.Status < 300:
			a.Status2xx++
		case l.Status < 400:
			a.Status3xx++
		case l.Status < 500:
			a.Status4xx++
		case l.Status < 600:
			a.Status5xx++
		}
		// 1xx (WebSocket upgrades: duration = connection lifetime) and
		// status 0 stay out of the latency histogram.
		if a.LatencyMs == nil {
			a.LatencyMs = make([]int64, state.LatencyBuckets)
		}
		a.LatencyMs[state.LatencyBucket(max(l.Duration, 0)*1000)]++
	}
	c.mu.Unlock()
	if completed && c.o.OnMinute != nil {
		c.o.OnMinute()
	}
}

// Roll completes the minute before now (called on a tick).
func (c *Collector) Roll() {
	now := c.o.Now().UTC()
	c.mu.Lock()
	completed := c.rollLocked(now)
	completed = c.an.roll(now) || completed
	c.mu.Unlock()
	if completed && c.o.OnMinute != nil {
		c.o.OnMinute()
	}
}

func (c *Collector) rollLocked(now time.Time) bool {
	if c.minute.IsZero() || !c.minute.Before(now.Truncate(time.Minute)) {
		return false
	}
	ids := make([]state.RouteID, 0, len(c.acc))
	for id := range c.acc {
		ids = append(ids, id)
	}
	slices.Sort(ids)
	for _, id := range ids {
		c.pending = append(c.pending, *c.acc[id])
	}
	if over := len(c.pending) - MaxRows; over > 0 {
		c.pending = slices.Delete(c.pending, 0, over)
	}
	c.acc = map[state.RouteID]*state.RequestMetricSample{}
	c.minute = time.Time{}
	return len(ids) > 0
}

// Pending returns the completed route-minutes not acknowledged yet (nil when none).
func (c *Collector) Pending() []state.RequestMetricSample {
	c.mu.Lock()
	defer c.mu.Unlock()
	if len(c.pending) == 0 {
		return nil
	}
	out := make([]state.RequestMetricSample, len(c.pending))
	for i, s := range c.pending {
		s.LatencyMs = slices.Clone(s.LatencyMs)
		out[i] = s
	}
	return out
}

// Ack drops the rows of a report that was accepted.
func (c *Collector) Ack(rows []state.RequestMetricSample) {
	if len(rows) == 0 {
		return
	}
	type key struct {
		r  state.RouteID
		at int64
	}
	sent := make(map[key]bool, len(rows))
	for _, s := range rows {
		sent[key{s.Route, s.At.Unix()}] = true
	}
	c.mu.Lock()
	defer c.mu.Unlock()
	c.pending = slices.DeleteFunc(c.pending, func(s state.RequestMetricSample) bool { return sent[key{s.Route, s.At.Unix()}] })
}

// Run completes idle minutes every tick until ctx is done.
func (c *Collector) Run(ctx context.Context) {
	t := time.NewTicker(c.o.Tick)
	defer t.Stop()
	for {
		select {
		case <-ctx.Done():
			return
		case <-t.C:
			c.Roll()
		}
	}
}

// Serve listens on addr (must be loopback) and reads access log lines until
// ctx is done.
func (c *Collector) Serve(ctx context.Context, addr string) error {
	ap, err := netip.ParseAddrPort(addr)
	if err != nil || !ap.Addr().IsLoopback() {
		return errors.New("traffic: access log address must be a loopback ip:port")
	}
	var lc net.ListenConfig
	ln, err := lc.Listen(ctx, "tcp", addr)
	if err != nil {
		return err
	}
	return c.ServeListener(ctx, ln)
}

// ServeListener reads access log lines from connections on ln until ctx is done.
func (c *Collector) ServeListener(ctx context.Context, ln net.Listener) error {
	ctx, cancel := context.WithCancel(ctx)
	defer cancel()
	var wg sync.WaitGroup
	var connsMu sync.Mutex
	conns := map[net.Conn]struct{}{}
	go func() {
		<-ctx.Done()
		_ = ln.Close()
		connsMu.Lock()
		for cn := range conns {
			_ = cn.Close()
		}
		connsMu.Unlock()
	}()
	sem := make(chan struct{}, MaxConns)
	for {
		cn, err := ln.Accept()
		if err != nil {
			wg.Wait()
			if ctx.Err() != nil {
				return nil
			}
			return err
		}
		if !loopbackPeer(cn.RemoteAddr()) {
			_ = cn.Close()
			continue
		}
		select {
		case sem <- struct{}{}:
		default:
			c.o.Log.Warn("too many access log connections; closing one", "max", MaxConns)
			_ = cn.Close()
			continue
		}
		connsMu.Lock()
		conns[cn] = struct{}{}
		connsMu.Unlock()
		wg.Add(1)
		go func() {
			defer wg.Done()
			defer func() { <-sem }()
			c.readLines(cn)
			connsMu.Lock()
			delete(conns, cn)
			connsMu.Unlock()
			_ = cn.Close()
		}()
	}
}

func loopbackPeer(a net.Addr) bool {
	tcp, ok := a.(*net.TCPAddr)
	if !ok {
		return false
	}
	ip, ok := netip.AddrFromSlice(tcp.IP)
	return ok && ip.Unmap().IsLoopback()
}

// readLines reads newline-separated lines; a line longer than MaxLineBytes
// is skipped up to its newline. Parsing is in-memory only, so Caddy's
// writes are never held up for long.
func (c *Collector) readLines(r io.Reader) {
	br := bufio.NewReaderSize(r, MaxLineBytes)
	skipping := false
	for {
		line, err := br.ReadSlice('\n')
		switch {
		case errors.Is(err, bufio.ErrBufferFull):
			skipping = true
			continue
		case err != nil:
			return
		}
		if skipping {
			skipping = false
			continue
		}
		c.Observe(line)
	}
}
