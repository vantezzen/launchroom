package caddy

import (
	"context"
	"errors"
	"net"
	"net/http"
	"strings"
	"sync/atomic"
	"time"
)

// AskPath is the path of the on-demand TLS permission endpoint.
const AskPath = "/caddy/ask"

// Ask answers Caddy's on-demand TLS permission requests from the cached route
// table, so certificates are only requested for hosts the daemon actually
// routes — also while the dashboard is down — and random SNI cannot burn
// ACME rate limits (docs/05 §5).
//
// The same loopback server answers the reachability check (Reach, when set).
type Ask struct {
	hosts atomic.Pointer[map[string]bool]
	// Reach answers ReachabilityPath; nil = 404.
	Reach http.Handler
}

// SetHosts replaces the allowed host set.
func (a *Ask) SetHosts(hosts []string) {
	m := make(map[string]bool, len(hosts))
	for _, h := range hosts {
		m[h] = true
	}
	a.hosts.Store(&m)
}

// Allowed reports whether a certificate may be obtained for domain.
func (a *Ask) Allowed(domain string) bool {
	m := a.hosts.Load()
	return m != nil && (*m)[strings.ToLower(strings.TrimSuffix(domain, "."))]
}

// ServeHTTP implements http.Handler: 200 for routed hosts, 404 otherwise.
func (a *Ask) ServeHTTP(w http.ResponseWriter, r *http.Request) {
	if a.Reach != nil && strings.HasPrefix(r.URL.Path, ReachabilityPath) {
		a.Reach.ServeHTTP(w, r)
		return
	}
	if r.URL.Path != AskPath || r.Method != http.MethodGet {
		http.NotFound(w, r)
		return
	}
	if a.Allowed(r.URL.Query().Get("domain")) {
		w.WriteHeader(http.StatusOK)
		return
	}
	http.NotFound(w, r)
}

// Serve listens on addr (loopback) until ctx is done.
func (a *Ask) Serve(ctx context.Context, addr string) error {
	var lc net.ListenConfig
	ln, err := lc.Listen(ctx, "tcp", addr)
	if err != nil {
		return err
	}
	return a.ServeListener(ctx, ln)
}

// ServeListener serves on ln until ctx is done.
func (a *Ask) ServeListener(ctx context.Context, ln net.Listener) error {
	srv := &http.Server{Handler: a, ReadHeaderTimeout: 5 * time.Second, ReadTimeout: 10 * time.Second, WriteTimeout: 10 * time.Second}
	ctx, cancel := context.WithCancel(ctx)
	defer cancel()
	done := make(chan struct{})
	go func() {
		defer close(done)
		<-ctx.Done()
		sctx, cancel := context.WithTimeout(context.WithoutCancel(ctx), 5*time.Second)
		defer cancel()
		_ = srv.Shutdown(sctx)
	}()
	err := srv.Serve(ln)
	cancel()
	<-done
	if errors.Is(err, http.ErrServerClosed) {
		return nil
	}
	return err
}
