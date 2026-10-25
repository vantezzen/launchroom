package access

import (
	"context"
	"log/slog"
	"net"
	"net/netip"
	"sync"
	"time"

	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// Limits of one endpoint (docs/09 R39).
const (
	// MaxPasswordFailures ends the access (reason "locked").
	MaxPasswordFailures = 5
	// MaxAuthTries per SSH connection.
	MaxAuthTries = 6
	// ConnectionsPerMinute per remote address.
	ConnectionsPerMinute = 20
	// failureDelay slows down every failed authentication.
	failureDelay = time.Second
	// handshakeTimeout bounds the SSH handshake or the database startup.
	handshakeTimeout = 20 * time.Second
	// maxConnections open at once per access.
	maxConnections = 32
)

// endpoint is the shared state of one running access: allow-list,
// counters, events for the audit log and the rate limits.
type endpoint struct {
	allow []netip.Prefix
	now   func() time.Time
	log   *slog.Logger

	mu           sync.Mutex
	events       []state.AccessEvent
	dropped      int
	failsByAddr  map[netip.Addr]int
	recent       map[netip.Addr][]time.Time
	active       int
	connections  int
	authFailures int
	pwFailures   int
	bytesIn      int64
	bytesOut     int64
	lastActivity time.Time
	startedAt    time.Time
	locked       chan struct{}
	lockOnce     sync.Once
	changed      chan struct{}
}

func newEndpoint(allow []netip.Prefix, now func() time.Time, log *slog.Logger) *endpoint {
	return &endpoint{
		allow: allow, now: now, log: log,
		failsByAddr: map[netip.Addr]int{}, recent: map[netip.Addr][]time.Time{},
		lastActivity: now(), locked: make(chan struct{}), changed: make(chan struct{}, 1),
	}
}

// remoteAddr returns the connection's source address (unmapped).
func remoteAddr(c net.Conn) netip.Addr {
	ap, err := netip.ParseAddrPort(c.RemoteAddr().String())
	if err != nil {
		return netip.Addr{}
	}
	return ap.Addr().Unmap()
}

// allowed reports whether a may connect.
func (e *endpoint) allowed(a netip.Addr) bool {
	if !a.IsValid() {
		return false
	}
	for _, p := range e.allow {
		if p.Contains(a) {
			return true
		}
	}
	return false
}

// admit applies the allow-list, the per-address rate limit and the
// connection cap. It is called before a byte is read.
func (e *endpoint) admit(a netip.Addr) bool {
	if !e.allowed(a) {
		return false
	}
	e.mu.Lock()
	defer e.mu.Unlock()
	now := e.now()
	ts := e.recent[a][:0]
	for _, t := range e.recent[a] {
		if now.Sub(t) < time.Minute {
			ts = append(ts, t)
		}
	}
	if len(ts) >= ConnectionsPerMinute || e.active >= maxConnections {
		e.recent[a] = ts
		return false
	}
	e.recent[a] = append(ts, now)
	e.active++
	return true
}

// release is called when an admitted connection closes.
func (e *endpoint) release() {
	e.mu.Lock()
	e.active--
	e.mu.Unlock()
	e.notify()
}

func (e *endpoint) notify() {
	select {
	case e.changed <- struct{}{}:
	default:
	}
}

func (e *endpoint) event(ev state.AccessEvent) {
	if len(e.events) >= state.MaxAccessEvents {
		e.dropped++
		return
	}
	e.events = append(e.events, ev)
}

// connected records an authenticated connection.
func (e *endpoint) connected(a netip.Addr, m state.AccessMethod, ch state.AccessChannel) {
	e.mu.Lock()
	e.connections++
	e.lastActivity = e.now()
	if e.startedAt.IsZero() {
		e.startedAt = e.now().UTC()
	}
	e.event(state.AccessEvent{At: e.now().UTC(), Type: state.AccessEventConnected, Remote: state.IPAddr(a.String()), Method: m, Channel: ch})
	e.mu.Unlock()
	e.notify()
}

// failed records a failed authentication. password failures count toward
// the lockout; a key a client offers that doesn't match does not.
func (e *endpoint) failed(a netip.Addr, password bool) {
	e.mu.Lock()
	e.authFailures++
	e.failsByAddr[a]++
	lock := false
	if password {
		e.pwFailures++
		lock = e.pwFailures >= MaxPasswordFailures
	}
	e.mu.Unlock()
	if lock {
		e.lockOnce.Do(func() {
			e.mu.Lock()
			e.event(state.AccessEvent{At: e.now().UTC(), Type: state.AccessEventLocked, Remote: state.IPAddr(a.String())})
			e.mu.Unlock()
			close(e.locked)
		})
	}
	e.notify()
}

// activity records application bytes (idle tracking and totals).
func (e *endpoint) activity(in, out int) {
	e.mu.Lock()
	e.bytesIn += int64(in)
	e.bytesOut += int64(out)
	e.lastActivity = e.now()
	e.mu.Unlock()
}

// idleSince is the last activity.
func (e *endpoint) idleSince() time.Time {
	e.mu.Lock()
	defer e.mu.Unlock()
	return e.lastActivity
}

// drainEvents returns the pending events (auth failures aggregated per
// address) and whether more were dropped.
func (e *endpoint) drainEvents() ([]state.AccessEvent, int, func(bool)) {
	e.mu.Lock()
	defer e.mu.Unlock()
	evs := append([]state.AccessEvent(nil), e.events...)
	for a, n := range e.failsByAddr {
		if len(evs) >= state.MaxAccessEvents {
			break
		}
		evs = append(evs, state.AccessEvent{At: e.now().UTC(), Type: state.AccessEventAuthFailed, Remote: state.IPAddr(a.String()), Count: n})
	}
	dropped := e.dropped
	fails := make(map[netip.Addr]int, len(e.failsByAddr))
	for a, n := range e.failsByAddr {
		fails[a] = n
	}
	nEvents := len(e.events)
	return evs, dropped, func(delivered bool) {
		if !delivered {
			return
		}
		e.mu.Lock()
		defer e.mu.Unlock()
		e.events = append([]state.AccessEvent(nil), e.events[min(nEvents, len(e.events)):]...)
		e.dropped -= dropped
		for a, n := range fails {
			if e.failsByAddr[a] -= n; e.failsByAddr[a] <= 0 {
				delete(e.failsByAddr, a)
			}
		}
	}
}

// stats returns the counters for the result.
func (e *endpoint) stats() (active, connections, failures int, in, out int64, started time.Time, last time.Time) {
	e.mu.Lock()
	defer e.mu.Unlock()
	return e.active, e.connections, e.authFailures, e.bytesIn, e.bytesOut, e.startedAt, e.lastActivity
}

// serve accepts connections until ctx is done; handle runs each admitted one.
func (e *endpoint) serve(ctx context.Context, l net.Listener, handle func(ctx context.Context, c net.Conn, a netip.Addr)) {
	var wg sync.WaitGroup
	stop := context.AfterFunc(ctx, func() { _ = l.Close() })
	defer stop()
	conns := map[net.Conn]struct{}{}
	var cmu sync.Mutex
	for {
		c, err := l.Accept()
		if err != nil {
			break
		}
		a := remoteAddr(c)
		if !e.admit(a) {
			_ = c.Close()
			continue
		}
		cmu.Lock()
		conns[c] = struct{}{}
		cmu.Unlock()
		wg.Add(1)
		go func() {
			defer wg.Done()
			defer e.release()
			defer func() {
				cmu.Lock()
				delete(conns, c)
				cmu.Unlock()
				_ = c.Close()
			}()
			handle(ctx, c, a)
		}()
	}
	cmu.Lock()
	for c := range conns {
		_ = c.Close()
	}
	cmu.Unlock()
	wg.Wait()
}
