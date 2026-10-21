package ambassador

import (
	"context"
	"errors"
	"fmt"
	"io"
	"log/slog"
	"maps"
	"net"
	"net/netip"
	"slices"
	"sync"
	"sync/atomic"
	"time"
)

// Options tunes a Server (zero values: defaults).
type Options struct {
	// DialTimeout bounds connecting to an upstream (default 5 s).
	DialTimeout time.Duration
	// CheckInterval and CheckTimeout drive the TCP health checks (default 5 s / 2 s).
	CheckInterval time.Duration
	CheckTimeout  time.Duration
	// FailThreshold consecutive failed checks mark an upstream unhealthy (default 2).
	FailThreshold int
	Log           *slog.Logger
	// Listen and Dial are replaceable for tests.
	Listen func(ctx context.Context, addr string) (net.Listener, error)
	Dial   func(ctx context.Context, addr string) (net.Conn, error)
}

// Server runs the listeners of the current Config.
type Server struct {
	o   Options
	ctx context.Context

	mu        sync.Mutex
	drain     time.Duration
	listeners map[string]*listener
	upstreams map[string]*upstream
}

type listener struct {
	addr  string
	ln    net.Listener
	pool  atomic.Pointer[pool]
	conns sync.Map // *proxied → struct{}
}

type pool struct {
	ups     []*upstream
	balance string
	clients netip.Prefix
	rr      atomic.Uint64
}

type upstream struct {
	addr    string
	healthy atomic.Bool
	active  atomic.Int64
	refs    int
	stop    context.CancelFunc
}

type proxied struct {
	client, server net.Conn
	up             *upstream
	once           sync.Once
}

func (p *proxied) close() {
	p.once.Do(func() {
		_ = p.client.Close()
		_ = p.server.Close()
	})
}

// NewServer returns a Server bound to ctx: cancelling it closes every
// listener and connection.
func NewServer(ctx context.Context, o Options) *Server {
	if o.DialTimeout <= 0 {
		o.DialTimeout = 5 * time.Second
	}
	if o.CheckInterval <= 0 {
		o.CheckInterval = 5 * time.Second
	}
	if o.CheckTimeout <= 0 {
		o.CheckTimeout = 2 * time.Second
	}
	if o.FailThreshold <= 0 {
		o.FailThreshold = 2
	}
	if o.Log == nil {
		o.Log = slog.New(slog.DiscardHandler)
	}
	if o.Listen == nil {
		o.Listen = func(ctx context.Context, addr string) (net.Listener, error) {
			var lc net.ListenConfig
			return lc.Listen(ctx, "tcp", addr)
		}
	}
	if o.Dial == nil {
		o.Dial = func(ctx context.Context, addr string) (net.Conn, error) {
			var d net.Dialer
			return d.DialContext(ctx, "tcp", addr)
		}
	}
	s := &Server{o: o, ctx: ctx, drain: DefaultDrain, listeners: map[string]*listener{}, upstreams: map[string]*upstream{}}
	go func() {
		<-ctx.Done()
		s.mu.Lock()
		defer s.mu.Unlock()
		for addr, l := range s.listeners {
			_ = l.ln.Close()
			closeAll(l, nil)
			delete(s.listeners, addr)
		}
	}()
	return s
}

// Apply converges the listeners to cfg (which must be valid). Unchanged
// listeners and their connections are untouched; listeners that cannot be
// opened yet (the address is not configured in the container yet) are
// reported in the error and retried by the next Apply.
func (s *Server) Apply(cfg Config) error {
	if err := cfg.Validate(); err != nil {
		return err
	}
	s.mu.Lock()
	defer s.mu.Unlock()
	if s.ctx.Err() != nil {
		return s.ctx.Err()
	}
	s.drain = cfg.Drain()
	want := map[string]Listener{}
	for _, l := range cfg.Listeners {
		ap := netip.MustParseAddrPort(l.Listen)
		l.Listen = ap.String()
		want[l.Listen] = l
	}
	for addr, l := range s.listeners {
		if _, ok := want[addr]; ok {
			continue
		}
		s.o.Log.Info("listener removed; draining its connections", "listen", addr, "drain", s.drain.String())
		_ = l.ln.Close()
		s.setPool(l, nil)
		delete(s.listeners, addr)
		s.drainLater(l, nil)
	}
	var errs []error
	for _, addr := range slices.Sorted(maps.Keys(want)) {
		cfgL := want[addr]
		if l, ok := s.listeners[addr]; ok {
			s.setPool(l, &cfgL)
			continue
		}
		ln, err := s.o.Listen(s.ctx, addr)
		if err != nil {
			errs = append(errs, fmt.Errorf("ambassador: listen %s: %w", addr, err))
			continue
		}
		l := &listener{addr: addr, ln: ln}
		s.setPool(l, &cfgL)
		s.listeners[addr] = l
		s.o.Log.Info("listening", "listen", addr, "upstreams", cfgL.Upstreams)
		go s.serve(l)
	}
	return errors.Join(errs...)
}

// Listening returns the addresses currently served (sorted).
func (s *Server) Listening() []string {
	s.mu.Lock()
	defer s.mu.Unlock()
	return slices.Sorted(maps.Keys(s.listeners))
}

// Healthy reports an upstream's health state (false when unknown).
func (s *Server) Healthy(addr string) bool {
	s.mu.Lock()
	defer s.mu.Unlock()
	u, ok := s.upstreams[addr]
	return ok && u.healthy.Load()
}

// setPool replaces l's upstreams (nil = none) and keeps the shared
// upstream reference counts and health checkers in step. Connections to
// upstreams l no longer uses are drained. s.mu must be held.
func (s *Server) setPool(l *listener, cfg *Listener) {
	old := l.pool.Load()
	var next *pool
	if cfg != nil {
		next = &pool{balance: cfg.Balance, clients: netip.MustParsePrefix(cfg.Clients).Masked()}
		if next.balance == "" {
			next.balance = LeastConn
		}
		for _, addr := range dedupe(cfg.Upstreams) {
			u := s.upstreams[addr]
			if u == nil {
				ctx, cancel := context.WithCancel(s.ctx)
				u = &upstream{addr: addr, stop: cancel}
				u.healthy.Store(true) // optimistic until the first check fails
				s.upstreams[addr] = u
				go s.check(ctx, u)
			}
			u.refs++
			next.ups = append(next.ups, u)
		}
	}
	l.pool.Store(next)
	if old == nil {
		return
	}
	gone := map[*upstream]bool{}
	for _, u := range old.ups {
		gone[u] = true
	}
	if next != nil {
		for _, u := range next.ups {
			delete(gone, u)
		}
	}
	for _, u := range old.ups {
		u.refs--
		if u.refs <= 0 {
			u.stop()
			delete(s.upstreams, u.addr)
		}
	}
	if len(gone) > 0 && next != nil {
		s.drainLater(l, gone)
	}
}

// drainLater closes l's connections (only those to the given upstreams,
// nil = all) once the drain period is over.
func (s *Server) drainLater(l *listener, only map[*upstream]bool) {
	t := time.NewTimer(s.drain)
	go func() {
		defer t.Stop()
		select {
		case <-t.C:
		case <-s.ctx.Done():
		}
		closeAll(l, only)
	}()
}

func closeAll(l *listener, only map[*upstream]bool) {
	l.conns.Range(func(k, _ any) bool {
		p, _ := k.(*proxied)
		if p != nil && (only == nil || only[p.up]) {
			p.close()
		}
		return true
	})
}

func (s *Server) serve(l *listener) {
	for {
		c, err := l.ln.Accept()
		if err != nil {
			if errors.Is(err, net.ErrClosed) || s.ctx.Err() != nil {
				return
			}
			s.o.Log.Warn("accept failed", "listen", l.addr, "err", err)
			time.Sleep(100 * time.Millisecond)
			continue
		}
		go s.handle(l, c)
	}
}

func (s *Server) handle(l *listener, c net.Conn) {
	p := l.pool.Load()
	if p == nil {
		_ = c.Close()
		return
	}
	src, err := netip.ParseAddrPort(c.RemoteAddr().String())
	if err != nil || !p.clients.Contains(src.Addr().Unmap()) {
		s.o.Log.Warn("connection from outside the environment network refused", "listen", l.addr, "from", c.RemoteAddr().String())
		_ = c.Close()
		return
	}
	for _, u := range p.order() {
		ctx, cancel := context.WithTimeout(s.ctx, s.o.DialTimeout)
		up, err := s.o.Dial(ctx, u.addr)
		cancel()
		if err != nil {
			if u.healthy.Swap(false) {
				s.o.Log.Warn("upstream unreachable; marked unhealthy", "upstream", u.addr, "err", err)
			}
			continue
		}
		px := &proxied{client: c, server: up, up: u}
		l.conns.Store(px, struct{}{})
		u.active.Add(1)
		pipe(px)
		u.active.Add(-1)
		l.conns.Delete(px)
		return
	}
	s.o.Log.Warn("no upstream reachable; connection closed", "listen", l.addr)
	_ = c.Close()
}

// order is the dial order for one new connection: healthy upstreams first
// (by policy), then the unhealthy ones as a last resort (a check may be stale).
func (p *pool) order() []*upstream {
	n := len(p.ups)
	if n == 0 {
		return nil
	}
	start := int(p.rr.Add(1) % uint64(n)) //nolint:gosec // n > 0, small
	rotated := append(slices.Clone(p.ups[start:]), p.ups[:start]...)
	var healthy, unhealthy []*upstream
	for _, u := range rotated {
		if u.healthy.Load() {
			healthy = append(healthy, u)
		} else {
			unhealthy = append(unhealthy, u)
		}
	}
	if p.balance == LeastConn {
		// Stable: ties keep the round-robin rotation.
		slices.SortStableFunc(healthy, func(a, b *upstream) int { return int(a.active.Load() - b.active.Load()) })
	}
	return append(healthy, unhealthy...)
}

// pipe copies both directions until both are done; a half-close is passed
// on (CloseWrite), so protocols that shut down one side keep working.
func pipe(p *proxied) {
	var wg sync.WaitGroup
	wg.Add(2)
	cp := func(dst, src net.Conn) {
		defer wg.Done()
		_, err := io.Copy(dst, src)
		if cw, ok := dst.(interface{ CloseWrite() error }); ok && err == nil {
			_ = cw.CloseWrite()
		} else {
			p.close()
		}
	}
	go cp(p.server, p.client)
	go cp(p.client, p.server)
	wg.Wait()
	p.close()
}

func (s *Server) check(ctx context.Context, u *upstream) {
	fails := 0
	t := time.NewTicker(s.o.CheckInterval)
	defer t.Stop()
	for {
		cctx, cancel := context.WithTimeout(ctx, s.o.CheckTimeout)
		c, err := s.o.Dial(cctx, u.addr)
		cancel()
		if ctx.Err() != nil {
			return
		}
		if err == nil {
			_ = c.Close()
			fails = 0
			if !u.healthy.Swap(true) {
				s.o.Log.Info("upstream healthy", "upstream", u.addr)
			}
		} else {
			fails++
			if fails >= s.o.FailThreshold && u.healthy.Swap(false) {
				s.o.Log.Warn("upstream failed its health check", "upstream", u.addr, "err", err)
			}
		}
		select {
		case <-ctx.Done():
			return
		case <-t.C:
		}
	}
}
