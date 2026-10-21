// Package registry gives the local Docker daemon and BuildKit access to the
// launchroom registry (docs/05 §6):
//
//   - a TCP forwarder on 127.0.0.1:5000 to Zot (the mesh address, or
//     LR_REGISTRY_UPSTREAM in dev). Docker treats 127.0.0.0/8 registries as
//     insecure-allowed, so no daemon.json change is needed;
//   - short-lived, scoped bearer tokens from the dashboard
//     (POST /daemon/v1/registry-token), cached until shortly before expiry and
//     passed per pull as X-Registry-Auth ({"registrytoken": …}), never stored
//     in ~/.docker/config.json.
package registry

import (
	"context"
	"encoding/base64"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"log/slog"
	"net"
	"slices"
	"strings"
	"sync"
	"sync/atomic"
	"time"

	"github.com/vantezzen/launchroom/daemon/internal/secret"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// DefaultAddress is where the Docker daemon pulls launchroom images from.
const DefaultAddress = "127.0.0.1:5000"

// TokenRequester obtains registry tokens (controlplane.Client).
type TokenRequester interface {
	RegistryToken(ctx context.Context, req *state.RegistryTokenRequest) (*state.RegistryToken, error)
}

// refreshBefore renews cached tokens this long before they expire.
const refreshBefore = 45 * time.Second

// Tokens caches dashboard-issued registry tokens per (task, scopes).
type Tokens struct {
	client TokenRequester
	now    func() time.Time

	mu    sync.Mutex
	cache map[string]cachedToken
}

type cachedToken struct {
	token   secret.Secret
	expires time.Time
}

// NewTokens returns a token cache over client.
func NewTokens(client TokenRequester) *Tokens {
	return &Tokens{client: client, now: time.Now, cache: map[string]cachedToken{}}
}

func cacheKey(scopes []state.RegistryScope, task state.TaskID) string {
	parts := make([]string, 0, len(scopes)+1)
	for _, s := range scopes {
		parts = append(parts, state.ScopeString(s))
	}
	slices.Sort(parts)
	return string(task) + "|" + strings.Join(parts, " ")
}

// Token returns a token covering scopes, requesting a new one when the cached
// one is missing or about to expire. task is required for push scopes.
func (t *Tokens) Token(ctx context.Context, scopes []state.RegistryScope, task state.TaskID) (secret.Secret, error) {
	if t == nil || t.client == nil {
		return "", errors.New("registry: no token source configured")
	}
	key := cacheKey(scopes, task)
	now := t.now()
	t.mu.Lock()
	for k, c := range t.cache {
		if !now.Before(c.expires) {
			delete(t.cache, k)
		}
	}
	if c, ok := t.cache[key]; ok && now.Add(refreshBefore).Before(c.expires) {
		t.mu.Unlock()
		return c.token, nil
	}
	t.mu.Unlock()

	tok, err := t.client.RegistryToken(ctx, &state.RegistryTokenRequest{
		TypeMeta: state.NewTypeMeta(state.KindRegistryTokenRequest), Scopes: scopes, Task: task,
	})
	if err != nil {
		return "", fmt.Errorf("registry: token for %s: %w", key, err)
	}
	t.mu.Lock()
	t.cache[key] = cachedToken{token: tok.Token, expires: tok.ExpiresAt}
	t.mu.Unlock()
	return tok.Token, nil
}

// Forget drops cached tokens of a task (after the task finished).
func (t *Tokens) Forget(task state.TaskID) {
	if t == nil {
		return
	}
	t.mu.Lock()
	defer t.mu.Unlock()
	for k := range t.cache {
		if strings.HasPrefix(k, string(task)+"|") {
			delete(t.cache, k)
		}
	}
}

// RepositoryOf returns the repository path of ref if it is in the registry at
// host ("127.0.0.1:5000/apps/x@sha256:…" → "apps/x").
func RepositoryOf(ref, host string) (state.RegistryRepository, bool) {
	rest, ok := strings.CutPrefix(ref, host+"/")
	if !ok {
		return "", false
	}
	if i := strings.IndexAny(rest, "@:"); i >= 0 {
		rest = rest[:i]
	}
	repo := state.RegistryRepository(rest)
	if repo.Validate() != nil {
		return "", false
	}
	return repo, true
}

// EncodeAuth renders the X-Registry-Auth header value for a bearer token.
func EncodeAuth(tok secret.Secret) (string, error) {
	raw, err := json.Marshal(map[string]string{"registrytoken": tok.Reveal()})
	if err != nil {
		return "", err
	}
	return base64.URLEncoding.EncodeToString(raw), nil
}

// taskKey carries the task a pull happens for (see WithTask).
type taskKey struct{}

// WithTask marks ctx as working for task: pulls made with it request their
// registry token with that task id, so the dashboard can authorize images that
// are not (yet) in the server's NodeState, e.g. the new image a run_once
// release command runs before rollout.
func WithTask(ctx context.Context, task state.TaskID) context.Context {
	return context.WithValue(ctx, taskKey{}, task)
}

// TaskFrom returns the task set by WithTask, or "".
func TaskFrom(ctx context.Context) state.TaskID {
	id, _ := ctx.Value(taskKey{}).(state.TaskID)
	return id
}

// PullAuth returns the docker.RegistryAuth hook: images in the launchroom
// registry at host get a pull-only token for exactly their repository; all
// other images are pulled anonymously. A task set with WithTask is sent along.
func (t *Tokens) PullAuth(host string) func(ctx context.Context, ref string) (string, error) {
	return func(ctx context.Context, ref string) (string, error) {
		repo, ok := RepositoryOf(ref, host)
		if !ok {
			return "", nil
		}
		tok, err := t.Token(ctx, []state.RegistryScope{{Repository: repo, Actions: []state.RegistryAction{state.RegistryPull}}}, TaskFrom(ctx))
		if err != nil {
			return "", err
		}
		return EncodeAuth(tok)
	}
}

// ---- forwarder ----

// maxConns bounds concurrent forwarded connections.
const maxConns = 512

// Forwarder accepts TCP connections on one address and pipes them to the
// current upstream. The upstream can change at runtime (NodeState).
type Forwarder struct {
	listen   string
	upstream atomic.Pointer[string]
	log      *slog.Logger
	dialer   net.Dialer
}

// NewForwarder returns a forwarder for listen ("127.0.0.1:5000").
func NewForwarder(listen, upstream string, log *slog.Logger) *Forwarder {
	if log == nil {
		log = slog.New(slog.DiscardHandler)
	}
	f := &Forwarder{listen: listen, log: log, dialer: net.Dialer{Timeout: 10 * time.Second, KeepAlive: 30 * time.Second}}
	f.SetUpstream(upstream)
	return f
}

// SetUpstream changes where new connections go ("" closes new connections).
func (f *Forwarder) SetUpstream(addr string) {
	f.upstream.Store(&addr)
}

// Addr is the listen address.
func (f *Forwarder) Addr() string { return f.listen }

// Upstream returns the current upstream.
func (f *Forwarder) Upstream() string {
	if p := f.upstream.Load(); p != nil {
		return *p
	}
	return ""
}

// Serve listens until ctx is done.
func (f *Forwarder) Serve(ctx context.Context) error {
	var lc net.ListenConfig
	l, err := lc.Listen(ctx, "tcp", f.listen)
	if err != nil {
		return fmt.Errorf("registry forwarder: %w", err)
	}
	return f.ServeListener(ctx, l)
}

// ServeListener serves on an existing listener (tests) until ctx is done.
func (f *Forwarder) ServeListener(ctx context.Context, l net.Listener) error {
	go func() {
		<-ctx.Done()
		_ = l.Close()
	}()
	sem := make(chan struct{}, maxConns)
	var wg sync.WaitGroup
	defer wg.Wait()
	for {
		c, err := l.Accept()
		if err != nil {
			if ctx.Err() != nil {
				return nil
			}
			var ne net.Error
			if errors.As(err, &ne) && ne.Timeout() {
				continue
			}
			return fmt.Errorf("registry forwarder: accept: %w", err)
		}
		select {
		case sem <- struct{}{}:
		default:
			f.log.Warn("registry forwarder: too many connections; dropping one")
			_ = c.Close()
			continue
		}
		wg.Add(1)
		go func() {
			defer wg.Done()
			defer func() { <-sem }()
			f.pipe(ctx, c)
		}()
	}
}

func (f *Forwarder) pipe(ctx context.Context, c net.Conn) {
	defer func() { _ = c.Close() }()
	up := f.Upstream()
	if up == "" {
		return
	}
	u, err := f.dialer.DialContext(ctx, "tcp", up)
	if err != nil {
		f.log.Warn("registry forwarder: upstream unreachable", "upstream", up, "err", err)
		return
	}
	defer func() { _ = u.Close() }()
	stop := context.AfterFunc(ctx, func() { _ = c.Close(); _ = u.Close() })
	defer stop()
	done := make(chan struct{}, 2)
	cp := func(dst, src net.Conn) {
		_, _ = io.Copy(dst, src)
		if tc, ok := dst.(interface{ CloseWrite() error }); ok {
			_ = tc.CloseWrite()
		}
		done <- struct{}{}
	}
	go cp(u, c)
	go cp(c, u)
	<-done
	<-done
}
