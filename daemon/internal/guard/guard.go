// Package guard is dockerd-guard (docs/09 §6, docs/README D49): a small,
// separately running process that owns the Docker socket and offers
// launchroomd a narrow, allow-listed subset of the Engine API on a unix
// socket of its own.
//
// Every request is parsed strictly and denied by default:
//
//   - the method and path must be in the route table (route.go), the API
//     version prefix in range, every path parameter must match its grammar;
//   - query parameters are allow-listed per route (unknown or repeated keys
//     are refused) and re-encoded from the parsed values;
//   - request headers are allow-listed (everything else is dropped);
//   - JSON bodies are decoded with unknown fields refused, every field
//     outside the allow-list of the container's class must be zero, and the
//     body that reaches Docker is re-encoded from the checked structure, so
//     no parser differential (duplicate keys, case folding) can carry
//     anything past the checks;
//   - every operation on an existing object first inspects it: only objects
//     labelled launchroom.managed=true with a valid launchroom.server label
//     are touched, and the request is forwarded with the full object id the
//     inspection returned (no name swap between check and use).
//
// The guard enforces the policy floor itself (internal/policy, container.go):
// launchroomd keeps its own checks (defence in depth), so a launchroomd
// compromise no longer equals root on the host.
package guard

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"log/slog"
	"net"
	"net/http"
	"os"
	"sync"
	"time"

	"github.com/vantezzen/launchroom/daemon/internal/guard/guardapi"
	"github.com/vantezzen/launchroom/daemon/internal/policy"
)

// Shared with launchroomd (internal/guard/guardapi).
const (
	StatusPath       = guardapi.StatusPath
	EdgePortsPath    = guardapi.EdgePortsPath
	RefusalPrefix    = guardapi.RefusalPrefix
	NotManagedMarker = guardapi.NotManagedMarker
)

// Status is the body of StatusPath.
type Status = guardapi.Status

// EdgePort is one exposed edge port.
type EdgePort = guardapi.EdgePort

// Version is reported on the status endpoint (set by the binary).
var Version = "0.0.0-dev"

// Options configures a Server.
type Options struct {
	// Upstream is the Docker socket the guard owns ("/var/run/docker.sock").
	Upstream string
	// Policy is the local policy floor (registries, capabilities, limits,
	// system image digests). Relaxations come only from the guard's own
	// environment, never from launchroomd.
	Policy policy.Config
	// InteractiveSessions mirrors LR_INTERACTIVE_SESSIONS (on, console,
	// data, off): with "off" or "data" the guard refuses every TTY exec.
	InteractiveSessions string
	// Updater allows launchroomd's self-update supervisor container (nil:
	// self-updates through the guard are refused).
	Updater *UpdaterRule
	// Rootless describes a rootless Docker host (nil: rootful).
	Rootless *RootlessOptions
	// Log receives one line per refusal (never request bodies).
	Log *slog.Logger
	// CallTimeout bounds the guard's own inspections (default 30 s).
	CallTimeout time.Duration
}

// UpdaterRule is the one shape of a container with host access the guard
// lets launchroomd create: the self-update supervisor (internal/update),
// run from launchroomd's CURRENT image with the Docker socket and the
// install folder mounted. See container.go (classUpdater).
type UpdaterRule struct {
	// ProjectDir is the install folder on the host (compose working dir).
	ProjectDir string
	// SocketHostPath is the Docker socket's path on the host.
	SocketHostPath string
	// Images returns the image ids ("sha256:…") the supervisor may run:
	// those of the running launchroomd container(s) of this install.
	Images func(ctx context.Context) ([]string, error)
}

// Server is a running guard.
type Server struct {
	o        Options
	upstream *http.Client
	execs    *execTable

	mu  sync.Mutex
	pol policy.Config
}

// New validates the options and returns a server.
func New(o Options) (*Server, error) {
	if o.Upstream == "" {
		return nil, errors.New("guard: upstream socket is required")
	}
	if err := o.Policy.Validate(); err != nil {
		return nil, err
	}
	switch o.InteractiveSessions {
	case "", "on", "console", "data", "off":
	default:
		return nil, fmt.Errorf("guard: interactive sessions %q must be on, console, data or off", o.InteractiveSessions)
	}
	if o.Log == nil {
		o.Log = slog.New(slog.DiscardHandler)
	}
	if o.CallTimeout <= 0 {
		o.CallTimeout = 30 * time.Second
	}
	s := &Server{o: o, pol: o.Policy, execs: newExecTable()}
	s.upstream = &http.Client{Transport: s.transport()}
	return s, nil
}

// Options returns the server's options (to derive a reconfigured server).
func (s *Server) Options() Options { return s.o }

// SetPolicy replaces the policy (tests; the binary sets it once at start).
func (s *Server) SetPolicy(p policy.Config) error {
	if err := p.Validate(); err != nil {
		return err
	}
	s.mu.Lock()
	s.pol = p
	s.mu.Unlock()
	return nil
}

// Policy returns the current policy.
func (s *Server) Policy() policy.Config {
	s.mu.Lock()
	defer s.mu.Unlock()
	return s.pol
}

func (s *Server) transport() *http.Transport {
	return &http.Transport{
		DialContext: func(ctx context.Context, _, _ string) (net.Conn, error) {
			var d net.Dialer
			return d.DialContext(ctx, "unix", s.o.Upstream)
		},
		DisableCompression:  true,
		MaxIdleConns:        16,
		IdleConnTimeout:     90 * time.Second,
		TLSHandshakeTimeout: 0,
	}
}

func (s *Server) dialUpstream(ctx context.Context) (net.Conn, error) {
	var d net.Dialer
	return d.DialContext(ctx, "unix", s.o.Upstream)
}

// Listen creates the guard's unix socket at path (0600, an existing socket
// file is replaced).
func Listen(path string) (net.Listener, error) {
	if fi, err := os.Lstat(path); err == nil {
		if fi.Mode()&os.ModeSocket == 0 {
			return nil, fmt.Errorf("guard: %s exists and is not a socket", path)
		}
		if err := os.Remove(path); err != nil {
			return nil, err
		}
	}
	var lc net.ListenConfig
	l, err := lc.Listen(context.Background(), "unix", path)
	if err != nil {
		return nil, err
	}
	if err := os.Chmod(path, 0o600); err != nil {
		_ = l.Close()
		return nil, err
	}
	return l, nil
}

// Serve answers requests on l until ctx is done.
func (s *Server) Serve(ctx context.Context, l net.Listener) error {
	hs := &http.Server{
		Handler:           s,
		ReadHeaderTimeout: 10 * time.Second,
		MaxHeaderBytes:    64 << 10,
		BaseContext:       func(net.Listener) context.Context { return ctx },
	}
	go func() {
		<-ctx.Done()
		sctx, cancel := context.WithTimeout(context.WithoutCancel(ctx), 5*time.Second)
		defer cancel()
		_ = hs.Shutdown(sctx)
	}()
	err := hs.Serve(l)
	if errors.Is(err, http.ErrServerClosed) {
		return nil
	}
	return err
}

// ServeHTTP implements http.Handler.
func (s *Server) ServeHTTP(w http.ResponseWriter, r *http.Request) {
	if r.URL.Path == StatusPath && r.Method == http.MethodGet {
		s.serveStatus(w)
		return
	}
	if r.URL.Path == EdgePortsPath && r.Method == http.MethodPost {
		s.serveEdgePorts(w, r)
		return
	}
	req, err := parseRequest(r)
	if err != nil {
		s.refuse(w, r, http.StatusForbidden, err)
		return
	}
	if err := s.handle(w, r, req); err != nil {
		var re *refusal
		if errors.As(err, &re) {
			s.refuse(w, r, re.status, re)
			return
		}
		s.refuse(w, r, http.StatusBadGateway, err)
	}
}

func (s *Server) serveStatus(w http.ResponseWriter) {
	st := Status{Guard: true, Version: Version, Rootless: s.o.Rootless != nil}
	if s.o.Rootless != nil && s.o.Rootless.APISocket != "" {
		st.EdgePorts = true
	}
	writeJSON(w, http.StatusOK, st)
}

// refusal is a request the guard does not forward.
type refusal struct {
	status int
	msg    string
}

func (r *refusal) Error() string { return r.msg }

func deny(format string, args ...any) error {
	return &refusal{status: http.StatusForbidden, msg: fmt.Sprintf(format, args...)}
}

func notManaged(kind, ref string) error {
	return &refusal{status: http.StatusForbidden, msg: fmt.Sprintf("%s %s is %s", kind, short(ref), NotManagedMarker)}
}

func notFound(kind, ref string) error {
	return &refusal{status: http.StatusNotFound, msg: fmt.Sprintf("no such %s: %s", kind, short(ref))}
}

func (s *Server) refuse(w http.ResponseWriter, r *http.Request, status int, err error) {
	msg := err.Error()
	if status == http.StatusForbidden {
		s.o.Log.Warn("request refused", "method", r.Method, "path", truncate(r.URL.Path, 200), "reason", msg)
	}
	writeJSON(w, status, map[string]string{"message": RefusalPrefix + msg})
}

func writeJSON(w http.ResponseWriter, status int, v any) {
	w.Header().Set("Content-Type", "application/json")
	w.WriteHeader(status)
	_ = json.NewEncoder(w).Encode(v)
}

func short(id string) string {
	if len(id) == 64 {
		return id[:12]
	}
	return truncate(id, 128)
}

func truncate(s string, n int) string {
	if len(s) <= n {
		return s
	}
	return s[:n] + "…"
}
