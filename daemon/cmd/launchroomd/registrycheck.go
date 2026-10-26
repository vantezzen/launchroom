package main

import (
	"context"
	"errors"
	"net"
	"net/http"
	"strings"
	"sync/atomic"
	"time"

	"github.com/vantezzen/launchroom/daemon/internal/registry"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// Registry reachability (D68): every server checks, every few minutes, that
// it reaches the image registry it pulls from (its local registry address:
// the forwarder to the main server's registry over the private network, or
// the registry itself on the main server). A server that can't download
// images shows on its page before a deploy waits on it.
const (
	registryCheckFirst    = 20 * time.Second
	registryCheckInterval = 5 * time.Minute
	registryCheckTimeout  = 15 * time.Second
)

type registryChecker struct {
	url    string
	client *http.Client
	now    func() time.Time
	last   atomic.Pointer[state.RegistryReach]
	onDone func()
}

func newRegistryChecker(now func() time.Time, onDone func()) *registryChecker {
	return &registryChecker{
		url: "http://" + registry.DefaultAddress + "/v2/",
		client: &http.Client{Timeout: registryCheckTimeout, Transport: &http.Transport{
			Proxy: nil, DisableKeepAlives: true,
			DialContext: (&net.Dialer{Timeout: registryCheckTimeout}).DialContext,
		}},
		now: now, onDone: onDone,
	}
}

func (c *registryChecker) run(ctx context.Context) {
	wait := registryCheckFirst
	for {
		select {
		case <-ctx.Done():
			return
		case <-time.After(wait):
		}
		wait = registryCheckInterval
		c.last.Store(c.check(ctx))
		c.onDone()
	}
}

// check asks GET /v2/; any HTTP answer (401 without a token) is reachable.
func (c *registryChecker) check(ctx context.Context) *state.RegistryReach {
	start := c.now()
	req, err := http.NewRequestWithContext(ctx, http.MethodGet, c.url, nil)
	if err != nil {
		return &state.RegistryReach{CheckedAt: start.UTC(), Error: "invalid registry address"}
	}
	res, err := c.client.Do(req)
	if err != nil {
		return &state.RegistryReach{CheckedAt: start.UTC(), Error: registryError(err)}
	}
	_ = res.Body.Close()
	return &state.RegistryReach{Reachable: true, CheckedAt: start.UTC(), LatencyMs: int(max(c.now().Sub(start).Milliseconds(), 0))}
}

// registryError is a short, secret-free reason.
func registryError(err error) string {
	var ne net.Error
	switch {
	case errors.As(err, &ne) && ne.Timeout():
		return "no answer within 15 seconds"
	case strings.Contains(err.Error(), "connection refused"):
		return "connection refused"
	case strings.Contains(err.Error(), "EOF"), strings.Contains(err.Error(), "connection reset"):
		return "the connection was closed (the main server's registry is not reachable from here)"
	}
	return "unreachable"
}

// Snapshot is the latest check (nil before the first).
func (c *registryChecker) Snapshot() *state.RegistryReach { return c.last.Load() }
