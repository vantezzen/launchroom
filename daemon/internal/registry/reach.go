package registry

import (
	"context"
	"errors"
	"fmt"
	"io"
	"net"
	"net/http"
	"net/http/httptrace"
	"sync/atomic"
	"syscall"
	"time"

	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// Registry reachability (D68): every server checks, every few minutes, that
// it reaches the image registry it pulls from (DefaultAddress: the
// forwarder to the main server's registry over the private network, or the
// registry itself on the main server). A server that can't download images
// shows on its page before a deploy waits on it.
const (
	reachFirst    = 20 * time.Second
	reachInterval = 5 * time.Minute
	reachTimeout  = 15 * time.Second
)

// ReachChecker runs the reachability check (Report.host.registry).
type ReachChecker struct {
	url    string
	client *http.Client
	now    func() time.Time
	last   atomic.Pointer[state.RegistryReach]
	onDone func()
}

// NewReachChecker checks DefaultAddress; onDone runs after every check.
func NewReachChecker(now func() time.Time, onDone func()) *ReachChecker {
	return &ReachChecker{
		url: "http://" + DefaultAddress + "/v2/",
		client: &http.Client{Timeout: reachTimeout, Transport: &http.Transport{
			Proxy: nil, DisableKeepAlives: true,
			DialContext: (&net.Dialer{Timeout: reachTimeout}).DialContext,
		}},
		now: now, onDone: onDone,
	}
}

// Run checks shortly after the start, then every reachInterval.
func (c *ReachChecker) Run(ctx context.Context) {
	wait := reachFirst
	for {
		select {
		case <-ctx.Done():
			return
		case <-time.After(wait):
		}
		wait = reachInterval
		c.last.Store(c.check(ctx))
		c.onDone()
	}
}

// check asks GET /v2/; any HTTP answer (401 without a token) is reachable.
func (c *ReachChecker) check(ctx context.Context) *state.RegistryReach {
	start := c.now()
	req, err := http.NewRequestWithContext(ctx, http.MethodGet, c.url, nil)
	if err != nil {
		return &state.RegistryReach{CheckedAt: start.UTC(), Error: "invalid registry address"}
	}
	var connected atomic.Bool
	req = req.WithContext(httptrace.WithClientTrace(ctx, &httptrace.ClientTrace{
		GotConn: func(httptrace.GotConnInfo) { connected.Store(true) },
	}))
	res, err := c.client.Do(req)
	if err != nil {
		return &state.RegistryReach{CheckedAt: start.UTC(), Error: reachError(err, connected.Load())}
	}
	_ = res.Body.Close()
	return &state.RegistryReach{Reachable: true, CheckedAt: start.UTC(), LatencyMs: int(max(c.now().Sub(start).Milliseconds(), 0))}
}

// reachError is a short, secret-free reason. connected says a TCP connection
// was made: a failure after that is the far side closing it, whichever error
// net/http reports for that (EOF or its unexported "server closed idle
// connection", depending on timing).
func reachError(err error, connected bool) string {
	var ne net.Error
	switch {
	case errors.As(err, &ne) && ne.Timeout():
		return fmt.Sprintf("no answer within %d seconds", int(reachTimeout/time.Second))
	case errors.Is(err, syscall.ECONNREFUSED):
		return "connection refused"
	case connected, errors.Is(err, io.EOF), errors.Is(err, io.ErrUnexpectedEOF), errors.Is(err, syscall.ECONNRESET), errors.Is(err, syscall.EPIPE):
		return "the connection was closed (the main server's registry is not reachable from here)"
	}
	return "unreachable"
}

// Snapshot is the latest check (nil before the first).
func (c *ReachChecker) Snapshot() *state.RegistryReach { return c.last.Load() }
