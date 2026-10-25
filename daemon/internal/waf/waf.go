// Package waf receives the web firewall's audit records from the edge
// Caddy and aggregates them per route and 10-minute window into
// Report.metrics.waf (docs/22 §6.5, docs/03 §13.4).
//
// Coraza posts one JSON record per request that reached CRS's anomaly
// threshold to a loopback listener served here. Each record is decoded into
// a few fields (time, host, method, normalised path, matched rule ids and
// variable names, anomaly score, transaction id) and the client address is
// cut to its /24 or /48 network right after decoding; the rest of the
// record (query string, argument values, matched data) never leaves memory.
// Completed windows are buffered until a report carrying them was
// accepted, like the request analytics.
package waf

import (
	"cmp"
	"context"
	"errors"
	"io"
	"log/slog"
	"net"
	"net/http"
	"net/netip"
	"slices"
	"strconv"
	"strings"
	"sync"
	"sync/atomic"
	"time"

	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// Defaults and bounds.
const (
	// DefaultAddr is LR_INGRESS_WAF_LOG_ADDR's default.
	DefaultAddr = "127.0.0.1:2023"
	// Path is where Coraza posts records (caddy.WafLogPath).
	Path = "/waf"
	// MaxBody bounds one record.
	MaxBody = 256 << 10
	// QueueSize bounds records waiting to be decoded (overflow is dropped and counted).
	QueueSize = 1024
	// DefaultTick completes windows.
	DefaultTick = 15 * time.Second
	// grace keeps a window open after its end for records still in flight.
	grace = time.Minute
	// maxPathsPerRoute / maxKeys bound distinct paths while a window is
	// open (later new paths count as other paths).
	maxPathsPerRoute = 1000
	maxKeys          = 20000
	// maxOpen bounds open (route, window) accumulators.
	maxOpen = 4096
)

// Options configures a Collector.
type Options struct {
	// OnWindow is called after a window with results completed (kick a report).
	OnWindow func()
	Tick     time.Duration
	Now      func() time.Time
	Log      *slog.Logger
}

// Collector receives and aggregates firewall records. Safe for concurrent use.
type Collector struct {
	o       Options
	routes  atomic.Pointer[map[string]state.RouteID]
	queue   chan []byte
	dropped atomic.Int64

	mu      sync.Mutex
	open    map[windowKey]*window
	keys    int
	pending []state.WafSample
}

type windowKey struct {
	route state.RouteID
	at    int64
}

type window struct {
	sample   state.WafSample
	cats     map[state.WafCategory]*state.WafCategoryCount
	rules    map[state.WafRuleID]*state.WafRuleCount
	paths    map[state.RequestPath]*state.WafCounts
	other    state.WafCounts
	distinct []state.WafEvent // the first event of each distinct (network, rules, path)
	others   []state.WafEvent // the first other events
	seen     map[string]bool
}

// New returns a Collector without routes (every record is dropped until SetRoutes).
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
	return &Collector{o: o, queue: make(chan []byte, QueueSize), open: map[windowKey]*window{}}
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

func (c *Collector) route(host string) (state.RouteID, bool) {
	m := c.routes.Load()
	if m == nil {
		return "", false
	}
	host = strings.ToLower(strings.TrimSpace(host))
	if h, _, err := net.SplitHostPort(host); err == nil {
		host = h
	}
	id, ok := (*m)[strings.TrimSuffix(strings.Trim(host, "[]"), ".")]
	return id, ok
}

// Dropped is the number of records lost since start (queue full or too large).
func (c *Collector) Dropped() int64 { return c.dropped.Load() }

// Observe decodes one record and folds it into its window. Records that
// don't count (below the threshold, unknown host, undecodable) are dropped.
func (c *Collector) Observe(body []byte) {
	now := c.o.Now().UTC()
	ev, ok := decode(body, now)
	if !ok {
		return
	}
	route, ok := c.route(ev.host)
	if !ok {
		return
	}
	c.mu.Lock()
	completed := c.rollLocked(now)
	c.addLocked(route, ev, now)
	c.mu.Unlock()
	if completed && c.o.OnWindow != nil {
		c.o.OnWindow()
	}
}

func (c *Collector) addLocked(route state.RouteID, ev event, now time.Time) {
	at := ev.at.Truncate(state.WafWindow)
	k := windowKey{route, at.Unix()}
	w := c.open[k]
	if w == nil && !at.Add(state.WafWindow).Add(grace).After(now) {
		// Its window is already complete: count it in the current one.
		ev.at = now.Truncate(time.Second)
		at = ev.at.Truncate(state.WafWindow)
		k = windowKey{route, at.Unix()}
		w = c.open[k]
	}
	if w == nil {
		if len(c.open) >= maxOpen {
			c.dropped.Add(1)
			return
		}
		w = &window{
			sample: state.WafSample{Route: route, At: at},
			cats:   map[state.WafCategory]*state.WafCategoryCount{},
			rules:  map[state.WafRuleID]*state.WafRuleCount{},
			paths:  map[state.RequestPath]*state.WafCounts{},
			seen:   map[string]bool{},
		}
		c.open[k] = w
	}
	add := func(blocked, detected *int64) {
		if ev.action == state.WafBlocked {
			*blocked++
		} else {
			*detected++
		}
	}
	add(&w.sample.Blocked, &w.sample.Detected)
	cats := categories(ev.rules)
	for _, cat := range cats {
		cc := w.cats[cat]
		if cc == nil {
			cc = &state.WafCategoryCount{Category: cat}
			w.cats[cat] = cc
		}
		add(&cc.Blocked, &cc.Detected)
	}
	for _, r := range ev.rules {
		rc := w.rules[r]
		if rc == nil {
			rc = &state.WafRuleCount{Rule: r}
			w.rules[r] = rc
		}
		add(&rc.Blocked, &rc.Detected)
	}
	pc := &w.other
	if ev.pathOK {
		if p := w.paths[ev.path]; p != nil {
			pc = p
		} else if len(w.paths) < maxPathsPerRoute && c.keys < maxKeys {
			pc = &state.WafCounts{}
			w.paths[ev.path] = pc
			c.keys++
		}
	}
	add(&pc.Blocked, &pc.Detected)

	// Event samples: the first event of each distinct (client network, rule
	// set, path), then the first others (docs/22 §6.5).
	if ev.method == "" || !ev.pathOK || len(ev.rules) == 0 || ev.network == "" || ev.reference == "" {
		return
	}
	rules := ev.rules
	if len(rules) > maxEventRules {
		rules = rules[:maxEventRules]
	}
	sample := state.WafEvent{
		At: ev.at, Action: ev.action, Method: ev.method, Path: ev.path,
		Rules: slices.Clone(rules), Categories: cats, Targets: slices.Clone(ev.targets),
		ClientNetwork: ev.network, Score: ev.score, Reference: ev.reference,
	}
	key := string(ev.network) + "|" + ruleKey(ev.rules) + "|" + string(ev.path)
	switch {
	case !w.seen[key] && len(w.distinct) < state.MaxWafEvents:
		w.seen[key] = true
		w.distinct = append(w.distinct, sample)
	case len(w.others) < state.MaxWafEvents:
		w.others = append(w.others, sample)
	}
}

func ruleKey(rules []state.WafRuleID) string {
	sorted := slices.Clone(rules)
	slices.Sort(sorted)
	var b strings.Builder
	for _, r := range sorted {
		b.WriteString(strconv.Itoa(int(r)))
		b.WriteByte(',')
	}
	return b.String()
}

// Roll completes the windows that ended (called on a tick).
func (c *Collector) Roll() {
	now := c.o.Now().UTC()
	c.mu.Lock()
	completed := c.rollLocked(now)
	c.mu.Unlock()
	if completed && c.o.OnWindow != nil {
		c.o.OnWindow()
	}
}

func (c *Collector) rollLocked(now time.Time) bool {
	var done []windowKey
	for k, w := range c.open {
		if !w.sample.At.Add(state.WafWindow).Add(grace).After(now) {
			done = append(done, k)
		}
	}
	if len(done) == 0 {
		return false
	}
	slices.SortFunc(done, func(a, b windowKey) int {
		return cmp.Or(cmp.Compare(a.at, b.at), strings.Compare(string(a.route), string(b.route)))
	})
	for _, k := range done {
		w := c.open[k]
		c.keys -= len(w.paths)
		delete(c.open, k)
		c.pending = append(c.pending, w.complete())
	}
	if over := len(c.pending) - state.MaxWafSamples; over > 0 {
		c.pending = slices.Delete(c.pending, 0, over)
	}
	return true
}

// complete turns an accumulator into its sample: every category, the top
// rules and paths (the rest summed), the event samples in time order.
func (w *window) complete() state.WafSample {
	s := w.sample
	total := func(b, d int64) int64 { return b + d }
	for _, cc := range w.cats {
		s.Categories = append(s.Categories, *cc)
	}
	slices.SortFunc(s.Categories, func(a, b state.WafCategoryCount) int {
		return cmp.Or(cmp.Compare(total(b.Blocked, b.Detected), total(a.Blocked, a.Detected)), strings.Compare(string(a.Category), string(b.Category)))
	})
	for _, rc := range w.rules {
		s.Rules = append(s.Rules, *rc)
	}
	slices.SortFunc(s.Rules, func(a, b state.WafRuleCount) int {
		return cmp.Or(cmp.Compare(total(b.Blocked, b.Detected), total(a.Blocked, a.Detected)), cmp.Compare(a.Rule, b.Rule))
	})
	if len(s.Rules) > state.MaxWafRuleCounts {
		s.Rules = s.Rules[:state.MaxWafRuleCounts]
	}
	for p, pc := range w.paths {
		s.Paths = append(s.Paths, state.WafPathCount{Path: p, Blocked: pc.Blocked, Detected: pc.Detected})
	}
	slices.SortFunc(s.Paths, func(a, b state.WafPathCount) int {
		return cmp.Or(cmp.Compare(total(b.Blocked, b.Detected), total(a.Blocked, a.Detected)), strings.Compare(string(a.Path), string(b.Path)))
	})
	s.OtherPaths = w.other
	if len(s.Paths) > state.MaxWafPathCounts {
		for _, p := range s.Paths[state.MaxWafPathCounts:] {
			s.OtherPaths.Blocked += p.Blocked
			s.OtherPaths.Detected += p.Detected
		}
		s.Paths = s.Paths[:state.MaxWafPathCounts]
	}
	events := append(slices.Clone(w.distinct), w.others...)
	if len(events) > state.MaxWafEvents {
		events = events[:state.MaxWafEvents]
	}
	slices.SortStableFunc(events, func(a, b state.WafEvent) int { return a.At.Compare(b.At) })
	s.Events = events
	return s
}

// Pending returns the completed windows not acknowledged yet (nil when none).
func (c *Collector) Pending() []state.WafSample {
	c.mu.Lock()
	defer c.mu.Unlock()
	if len(c.pending) == 0 {
		return nil
	}
	return slices.Clone(c.pending)
}

// Ack drops the windows of a report that was accepted.
func (c *Collector) Ack(samples []state.WafSample) {
	if len(samples) == 0 {
		return
	}
	sent := make(map[windowKey]bool, len(samples))
	for _, s := range samples {
		sent[windowKey{s.Route, s.At.Unix()}] = true
	}
	c.mu.Lock()
	defer c.mu.Unlock()
	c.pending = slices.DeleteFunc(c.pending, func(s state.WafSample) bool { return sent[windowKey{s.Route, s.At.Unix()}] })
}

// Run decodes queued records and completes windows every tick until ctx is done.
func (c *Collector) Run(ctx context.Context) {
	t := time.NewTicker(c.o.Tick)
	defer t.Stop()
	for {
		select {
		case <-ctx.Done():
			return
		case body := <-c.queue:
			c.Observe(body)
		case <-t.C:
			c.Roll()
		}
	}
}

// ServeHTTP receives one record: loopback peers only, POST to Path, a body
// of at most MaxBody. It answers 204 before the record is parsed (Coraza
// writes synchronously while the request is being answered) and queues it.
func (c *Collector) ServeHTTP(w http.ResponseWriter, r *http.Request) {
	if !loopbackPeer(r.RemoteAddr) {
		http.Error(w, "forbidden", http.StatusForbidden)
		return
	}
	if r.Method != http.MethodPost || r.URL.Path != Path {
		http.NotFound(w, r)
		return
	}
	body, err := io.ReadAll(http.MaxBytesReader(w, r.Body, MaxBody))
	if err != nil {
		c.dropped.Add(1)
		http.Error(w, "record too large", http.StatusRequestEntityTooLarge)
		return
	}
	w.WriteHeader(http.StatusNoContent)
	select {
	case c.queue <- body:
	default:
		c.dropped.Add(1)
	}
}

func loopbackPeer(remote string) bool {
	ap, err := netip.ParseAddrPort(remote)
	return err == nil && ap.Addr().Unmap().IsLoopback()
}

// Serve listens on addr (must be loopback) until ctx is done.
func (c *Collector) Serve(ctx context.Context, addr string) error {
	ap, err := netip.ParseAddrPort(addr)
	if err != nil || !ap.Addr().IsLoopback() || ap.Port() == 0 {
		return errors.New("waf: the record address must be a loopback ip:port")
	}
	var lc net.ListenConfig
	ln, err := lc.Listen(ctx, "tcp", ap.String())
	if err != nil {
		return err
	}
	return c.ServeListener(ctx, ln)
}

// ServeListener serves records on ln until ctx is done.
func (c *Collector) ServeListener(ctx context.Context, ln net.Listener) error {
	srv := &http.Server{
		Handler:           c,
		ReadHeaderTimeout: 5 * time.Second,
		ReadTimeout:       10 * time.Second,
		WriteTimeout:      10 * time.Second,
		IdleTimeout:       time.Minute,
		MaxHeaderBytes:    16 << 10,
		ErrorLog:          slog.NewLogLogger(c.o.Log.Handler(), slog.LevelDebug),
	}
	go func() {
		<-ctx.Done()
		sctx, cancel := context.WithTimeout(context.WithoutCancel(ctx), 2*time.Second)
		defer cancel()
		_ = srv.Shutdown(sctx)
	}()
	if err := srv.Serve(ln); err != nil && !errors.Is(err, http.ErrServerClosed) {
		return err
	}
	return nil
}
