package caddy

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"errors"
	"fmt"
	"log/slog"
	"maps"
	"os"
	"path/filepath"
	"slices"
	"strings"
	"sync"
	"time"
	"unicode/utf8"

	"github.com/moby/moby/api/types/container"
	"github.com/moby/moby/api/types/mount"

	"github.com/vantezzen/launchroom/daemon/internal/atomicfile"
	"github.com/vantezzen/launchroom/daemon/internal/docker"
	"github.com/vantezzen/launchroom/daemon/internal/ingress"
	"github.com/vantezzen/launchroom/daemon/internal/ingress/certstore"
	"github.com/vantezzen/launchroom/daemon/internal/names"
	"github.com/vantezzen/launchroom/daemon/internal/policy"
	"github.com/vantezzen/launchroom/daemon/internal/secret"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// Where Caddy's config is kept.
const (
	// CacheFile is the last applied config inside the cache dir (<data>/caddy).
	CacheFile = "config.json"
	// AutosaveFile is Caddy's autosaved config inside its container
	// (XDG_CONFIG_HOME=/config), which "caddy run --resume" loads at start.
	AutosaveFile = "/config/caddy/autosave.json"
)

// ManagerOptions configures a Manager.
type ManagerOptions struct {
	ServerID string
	Docker   docker.Client
	Admin    Admin
	Policy   policy.Config
	Render   Options
	// CacheDir holds the last applied config (e.g. /data/caddy).
	CacheDir string
	Ask      *Ask
	Log      *slog.Logger
	// ReadyTimeout bounds waiting for a (re)started Caddy's admin API (default 20 s).
	ReadyTimeout time.Duration
	// LoadRetry is the first wait before loading a config again when Caddy
	// wasn't reachable (doubling, loadAttempts in all; default 500 ms).
	LoadRetry time.Duration
	Now       func() time.Time
	// Spec returns the current NodeState's ingress section (nil: Caddy's
	// defaults: local file storage, per-host certificates).
	Spec func() *state.IngressSpec
	// OpenSecret opens sealed ingress credentials addressed to this server.
	OpenSecret func(state.Sealed, state.IngressSecretPurpose) (map[string]secret.Secret, error)
	// ForwardAuthOrigins, when set, replaces Render.ForwardAuthOrigins on
	// every Sync (the allow-list follows an adopted control-plane origin,
	// docs/03 §4.2, §8).
	ForwardAuthOrigins func() []string
	// WafLogAddress is the loopback ip:port of launchroomd's firewall record
	// receiver (internal/waf); "" never renders the firewall.
	WafLogAddress string
	// WafDropped returns the firewall records lost since start (IngressStatus.waf.droppedRecords).
	WafDropped func() int64
	// ProcRoot is the host's /proc (default "/proc"; launchroomd shares the
	// host's PID and network namespaces), read to name the program holding
	// a port Caddy can't bind.
	ProcRoot string
}

// Manager runs the system Caddy container and keeps its config in sync.
type Manager struct {
	o ManagerOptions

	mu           sync.Mutex
	appliedKey   string // config hash + files fingerprint + container id/start time
	appliedFiles string
	appliedAt    time.Time
	routes       int
	// refusedAuth are the protected routes refused last time (logged on change).
	refusedAuth []state.RouteID
	// refusedClientAuth are the clientAuth routes refused last time (logged on change).
	refusedClientAuth []state.RouteID
	// edgeFiles are the clientAuth edge secret files written last time
	// (overwritten with nothing when their route goes away).
	edgeFiles []string

	// Certificates (docs/05 §5): the last applied routes and TLS setup, the
	// shared storage, issuance events from Caddy's log, the derived statuses.
	lastRoutes    []Route
	lastTLS       TLS
	redis         *certstore.Redis
	degraded      bool
	containerID   string
	storage       *state.CertStorageStatus
	certs         []state.CertificateStatus
	events        map[string]certEvent
	logsSince     time.Time
	logsContainer string
	notes         []string
	certKick      chan struct{}
	// keptImage is the running image kept instead of an unpinned requested
	// one (logged on change; "" when the requested image runs).
	keptImage state.ImageRef

	// Web firewall (docs/22 §6): what the running Caddy carries, the routes
	// with the firewall in the applied config, and Caddy's refusal of a
	// config with the firewall (its key, so the same config isn't tried on
	// every pass, and the message).
	wafSupport WafSupport
	wafAsked   bool
	wafRoutes  int
	wafFailed  string
	wafErr     string
	// wafWanted counts the routes the firewall would cover in this pass's
	// config (0: none, or the running Caddy has no module).
	wafWanted int

	// Request trace (docs/23 §3): whether the running Caddy carries the
	// module, and the key of a config with the trace Caddy refused (not
	// tried again until something changes).
	traceSupport bool
	traceFailed  string

	// unreachableSince is when loading a config first failed in a row
	// because Caddy's admin API wasn't reachable (zero: it was); unreachableErr
	// is the last such error (docs/22 §6.6).
	unreachableSince time.Time
	unreachableErr   string

	// failure classifies the last state other than applied (reason, port,
	// process, Caddy's output); nil once a config is applied.
	failure *failure
	// appliedCfg is the config Caddy runs (the last good one; a refused
	// config leaves it serving). cert_retry reloads it.
	appliedCfg []byte
	// certRetryAt is when a cert_retry last reloaded Caddy.
	certRetryAt time.Time
}

// Loading a config is tried loadAttempts times within one pass while Caddy
// isn't reachable; after that the reconcile loop's own interval is the
// backoff. The firewall's state turns to error only after
// wafUnreachableAfter of it (docs/22 §6.6).
const (
	loadAttempts        = 5
	wafUnreachableAfter = 5 * time.Minute
)

// logRefusedAuth logs protected routes whose forward-auth URL the local policy
// refuses, once per change (they answer 403, docs/05 §4).
func (m *Manager) logRefusedAuth(refused []state.RouteID, origins []string) {
	m.mu.Lock()
	defer m.mu.Unlock()
	if slices.Equal(refused, m.refusedAuth) {
		return
	}
	m.refusedAuth = refused
	if len(refused) > 0 {
		m.o.Log.Warn("local policy refused the forward-auth URL of protected routes (not the control-plane URL or LR_ALLOWED_LOCAL_UPSTREAMS); they answer 403",
			"routes", refused, "allowedOrigins", origins)
	}
}

// planClientAuth opens the edge secret of every route with clientAuth
// (mTLS, docs/03 §4.3) into a secret file in the Caddy container and
// returns the routes with their file set; a secret that can't be opened
// leaves the file empty, so the route fails closed. Files of routes that
// went away are overwritten with nothing.
func (m *Manager) planClientAuth(p *tlsPlan, routes []Route) []Route {
	out := slices.Clone(routes)
	var written []string
	for i, r := range out {
		if r.Spec.ClientAuth == nil {
			continue
		}
		vals, err := m.openSecret(r.Spec.ClientAuth.EdgeSecret, state.IngressSecretClientAuth)
		if err != nil {
			p.notes = append(p.notes, fmt.Sprintf("route %s: client certificate edge secret unusable, the route answers 403: %v", r.Spec.ID, err))
			continue
		}
		f := EdgeSecretFile(r.Spec.ID)
		p.files[f] = vals["secret"]
		out[i].EdgeSecretFile = f
		written = append(written, f)
	}
	m.mu.Lock()
	for _, f := range m.edgeFiles {
		if !slices.Contains(written, f) {
			p.files[f] = ""
		}
	}
	m.edgeFiles = written
	m.mu.Unlock()
	return out
}

// logRefusedClientAuth logs routes whose clientAuth the local policy refuses
// (upstreams other than LR_ALLOWED_LOCAL_UPSTREAMS, or an unusable edge
// secret), once per change; they answer 403.
func (m *Manager) logRefusedClientAuth(refused []state.RouteID) {
	m.mu.Lock()
	defer m.mu.Unlock()
	if slices.Equal(refused, m.refusedClientAuth) {
		return
	}
	m.refusedClientAuth = refused
	if len(refused) > 0 {
		m.o.Log.Warn("local policy refused the client certificate check of routes (only routes to LR_ALLOWED_LOCAL_UPSTREAMS may carry one, with a usable edge secret); they answer 403",
			"routes", refused)
	}
}

// renderOptions are the render options of this pass.
func (m *Manager) renderOptions() Options {
	o := m.o.Render
	if m.o.ForwardAuthOrigins != nil {
		o.ForwardAuthOrigins = m.o.ForwardAuthOrigins()
	}
	o.LocalUpstreams = make([]string, 0, len(m.o.Policy.LocalUpstreams))
	for _, u := range m.o.Policy.LocalUpstreams {
		o.LocalUpstreams = append(o.LocalUpstreams, u.String())
	}
	return o
}

// NewManager returns a Manager.
func NewManager(o ManagerOptions) *Manager {
	if o.Log == nil {
		o.Log = slog.New(slog.DiscardHandler)
	}
	if o.ReadyTimeout <= 0 {
		o.ReadyTimeout = 20 * time.Second
	}
	if o.LoadRetry <= 0 {
		o.LoadRetry = 500 * time.Millisecond
	}
	if o.Now == nil {
		o.Now = time.Now
	}
	if o.Ask == nil {
		o.Ask = &Ask{}
	}
	return &Manager{o: o, events: map[string]certEvent{}, certKick: make(chan struct{}, 1)}
}

// status builds a report with the latest certificate information.
func (m *Manager) status(st state.IngressState, routes int, appliedAt *time.Time, msg string) *state.IngressStatus {
	m.mu.Lock()
	defer m.mu.Unlock()
	s := &state.IngressStatus{State: st, Routes: routes, AppliedAt: appliedAt, Message: state.Clip(msg, state.MaxStatusLen),
		Certificates: slices.Clone(m.certs), Storage: cloneStorage(m.storage), Waf: m.wafStatusLocked()}
	if f := m.failure; f != nil && st != state.IngressApplied {
		s.Reason, s.Process, s.Output = f.reason, f.process, slices.Clone(f.output)
		if f.port > 0 {
			p := state.Port(f.port)
			s.Port = &p
		}
	}
	return s
}

// setFailure records why the ingress isn't applied (nil: it is).
func (m *Manager) setFailure(f *failure) {
	m.mu.Lock()
	m.failure = f
	m.mu.Unlock()
}

// Features are the web firewall and the request trace while the running,
// pinned Caddy carries their modules (docs/22 §6.1, docs/23 §3).
func (m *Manager) Features() []state.DaemonFeature {
	var out []state.DaemonFeature
	if m.WafSupport().Available() {
		out = append(out, state.FeatureWaf)
	}
	m.mu.Lock()
	trace := m.traceSupport
	m.mu.Unlock()
	if trace {
		out = append(out, state.FeatureRequestTrace)
	}
	return out
}

// WafSupport is the firewall module of the running Caddy (zero: none, or
// Caddy isn't running yet). launchroomd announces the daemon feature "waf"
// only while it is available.
func (m *Manager) WafSupport() WafSupport {
	m.mu.Lock()
	defer m.mu.Unlock()
	return m.wafSupport
}

// wafStatusLocked is IngressStatus.waf: nil without the module unless a
// route asks for the firewall (then unsupported).
func (m *Manager) wafStatusLocked() *state.WafStatus {
	if !m.wafSupport.Available() {
		if !m.wafAsked {
			return nil
		}
		return &state.WafStatus{State: state.WafStateUnsupported, Message: "The edge's Caddy has no web firewall module."}
	}
	st := &state.WafStatus{State: state.WafStateOff, Engine: m.wafSupport.Engine, RuleSet: m.wafSupport.RuleSet}
	unreachable := !m.unreachableSince.IsZero() && m.wafWanted > 0
	switch {
	case m.wafErr != "":
		st.State, st.Message = state.WafStateError, plainText(m.wafErr, 1024)
	case unreachable && m.o.Now().Sub(m.unreachableSince) >= wafUnreachableAfter:
		st.State = state.WafStateError
		st.Message = plainText(fmt.Sprintf("Caddy hasn't been reachable since %s, so the firewall can't be applied; retrying: %s",
			m.unreachableSince.UTC().Format(time.RFC3339), m.unreachableErr), 1024)
	case m.wafRoutes > 0:
		st.State, st.Routes = state.WafStateOn, m.wafRoutes
	}
	if unreachable && st.State != state.WafStateError {
		// Caddy keeps serving its last config (--resume) meanwhile.
		st.Message = "Caddy wasn't reachable yet; retrying."
	}
	if m.o.WafDropped != nil {
		st.DroppedRecords = max(m.o.WafDropped(), 0)
	}
	return st
}

// plainText makes s a single line of printable text of at most n bytes.
func plainText(s string, n int) string {
	return state.Clip(strings.Map(func(r rune) rune {
		if r < 0x20 || r == 0x7f || r == utf8.RuneError {
			return ' '
		}
		return r
	}, s), n)
}

func cloneStorage(s *state.CertStorageStatus) *state.CertStorageStatus {
	if s == nil {
		return nil
	}
	c := *s
	return &c
}

// logNotes logs secret-free certificate setup warnings once per change.
func (m *Manager) logNotes(notes []string) {
	m.mu.Lock()
	defer m.mu.Unlock()
	if slices.Equal(notes, m.notes) {
		return
	}
	m.notes = notes
	for _, n := range notes {
		m.o.Log.Warn("ingress certificates", "note", n)
	}
}

// Sync converges the system container to sys and loads the route table,
// resolving same-server upstreams through resolve. It returns nil when Caddy
// is disabled on this server.
func (m *Manager) Sync(ctx context.Context, sys state.SystemContainer, specs []state.RouteSpec, resolve ingress.Resolver) *state.IngressStatus {
	return m.sync(ctx, sys, ResolveRoutes(specs, resolve))
}

func (m *Manager) sync(ctx context.Context, sys state.SystemContainer, routes []Route) *state.IngressStatus {
	if !sys.Enabled {
		m.o.Ask.SetHosts(nil)
		if err := m.removeContainer(ctx); err != nil {
			m.o.Log.Warn("could not remove the disabled system caddy", "err", err)
		}
		m.mu.Lock()
		m.appliedKey, m.containerID, m.certs, m.storage = "", "", nil, nil
		m.wafSupport, m.wafRoutes, m.wafErr, m.wafFailed, m.wafWanted = WafSupport{}, 0, "", "", 0
		m.traceSupport, m.traceFailed = false, ""
		m.unreachableSince, m.unreachableErr, m.failure, m.appliedCfg = time.Time{}, "", nil, nil
		m.mu.Unlock()
		return nil
	}
	failWith := func(f *failure, format string, args ...any) *state.IngressStatus {
		msg := fmt.Sprintf(format, args...)
		m.o.Log.Warn("ingress not applied", "reason", msg)
		m.setFailure(f)
		return m.status(state.IngressFailed, len(routes), nil, msg)
	}
	fail := func(format string, args ...any) *state.IngressStatus {
		return failWith(&failure{reason: state.IngressOtherFailure}, format, args...)
	}
	image, err := m.image(ctx, sys.Image)
	if err != nil {
		return failWith(&failure{reason: state.IngressImage}, "%v", err)
	}
	var spec *state.IngressSpec
	if m.o.Spec != nil {
		spec = m.o.Spec()
	}
	plan := m.plan(ctx, spec)
	routes = m.planClientAuth(plan, routes)
	m.logNotes(plan.notes)
	render := m.renderOptions()
	cfg, err := RenderTLS(routes, plan.tls, render)
	if err != nil {
		return fail("%v", err)
	}
	m.logRefusedAuth(RefusedForwardAuth(routes, render), render.ForwardAuthOrigins)
	m.logRefusedClientAuth(RefusedClientAuth(routes, render))
	m.o.Ask.SetHosts(Hosts(routes))

	c, err := m.ensureContainer(ctx, image)
	if err != nil {
		reason := state.IngressOtherFailure
		if strings.Contains(strings.ToLower(err.Error()), "pull") {
			reason = state.IngressImage
		}
		return failWith(&failure{reason: reason}, "system caddy: %v", err)
	}
	files := plan.fingerprint()
	// fallback has neither the request trace nor the firewall; base is
	// what is served when only the firewall is refused.
	fallback := cfg
	base, render, traceKey := m.traceConfig(routes, plan, render, c, fallback)
	cfg, wafKey, wafRoutes, wafErr := m.wafConfig(routes, plan.tls, render, c)
	if cfg == nil {
		cfg = base
	}
	sum := sha256.Sum256(cfg)
	key := hex.EncodeToString(sum[:]) + "|" + files + "|" + c.ID + "|" + c.StartedAt.String()

	m.mu.Lock()
	m.lastRoutes, m.lastTLS, m.redis, m.containerID = routes, plan.tls, plan.redis, c.ID
	m.degraded = plan.storage != nil && plan.storage.State == state.StorageDegraded
	mirrored := 0
	if m.storage != nil {
		mirrored = m.storage.Mirrored
	}
	m.storage = plan.storage
	if m.storage != nil && m.storage.State == state.StorageShared {
		m.storage.Mirrored = mirrored // counted by RefreshCertificates
	}
	if m.appliedKey == key {
		applied, n := m.appliedAt, m.routes
		m.unreachableSince, m.unreachableErr, m.failure = time.Time{}, "", nil
		m.mu.Unlock()
		return m.status(state.IngressApplied, n, ptr(applied), "")
	}
	// Caddy caches {file.*} secrets until its config is reloaded: force a
	// reload when only the files changed.
	force := m.appliedFiles != files && m.appliedKey != ""
	m.mu.Unlock()

	unreachable := func(err error) *state.IngressStatus {
		m.seedResume(ctx, c.ID, plan.files, fallback)
		return m.unreachable(ctx, len(routes), err)
	}
	if err := m.waitReady(ctx); err != nil {
		return unreachable(err)
	}
	if err := m.copyFiles(ctx, c.ID, plan.files); err != nil {
		return fail("copy certificate files into caddy: %v", err)
	}
	load := m.o.Admin.Load
	if r, ok := m.o.Admin.(Reloader); ok && force {
		load = r.Reload
	}
	err = m.loadRetrying(ctx, load, cfg)
	if err != nil && IsConfigRefusal(err) && wafKey != "" {
		// Caddy refused the config with the firewall: serve the same routes
		// without it (availability wins, docs/22 §6.6) and don't try this
		// config again until something changes.
		m.o.Log.Warn("caddy refused the config with the web firewall; serving the routes without it", "err", err)
		m.mu.Lock()
		m.wafFailed, m.wafErr = wafKey, "Caddy refused the firewall config: "+err.Error()
		m.mu.Unlock()
		cfg, wafRoutes, wafErr = base, 0, m.wafErr
		err = m.loadRetrying(ctx, load, cfg)
	}
	if err != nil && IsConfigRefusal(err) && traceKey != "" {
		// The same for the request trace: the routes matter more.
		m.o.Log.Warn("caddy refused the config with the request trace; serving the routes without it", "err", err)
		m.mu.Lock()
		m.traceFailed = traceKey
		m.mu.Unlock()
		cfg = fallback
		err = m.loadRetrying(ctx, load, cfg)
	}
	if err != nil {
		if !IsConfigRefusal(err) {
			// Caddy wasn't reachable (starting, restarting, slow after a
			// reboot): that says nothing about the config. The next pass
			// tries the same config, firewall included (docs/22 §6.6).
			return unreachable(err)
		}
		return failWith(m.configRefused(ctx, c.ID, err), "load config: %v%s", err, m.keepsServing())
	}
	sum = sha256.Sum256(cfg)
	key = hex.EncodeToString(sum[:]) + "|" + files + "|" + c.ID + "|" + c.StartedAt.String()
	if m.o.CacheDir != "" {
		if err := os.MkdirAll(m.o.CacheDir, 0o700); err == nil {
			if err := atomicfile.Write(filepath.Join(m.o.CacheDir, CacheFile), cfg, 0o600); err != nil {
				m.o.Log.Warn("could not cache caddy config", "err", err)
			}
		}
	}
	now := m.o.Now().UTC()
	m.mu.Lock()
	m.appliedKey, m.appliedFiles, m.appliedAt, m.routes = key, files, now, len(routes)
	m.wafRoutes, m.wafErr = wafRoutes, wafErr
	m.unreachableSince, m.unreachableErr, m.failure, m.appliedCfg = time.Time{}, "", nil, cfg
	m.mu.Unlock()
	m.o.Log.Info("ingress config applied", "routes", len(routes))
	select { // certificate statuses for the new route table
	case m.certKick <- struct{}{}:
	default:
	}
	return m.status(state.IngressApplied, len(routes), ptr(now), "")
}

// loadRetrying loads cfg, trying again with a doubling wait while Caddy
// isn't reachable. A refusal of the config returns at once; an availability
// error returns once the attempts are spent.
func (m *Manager) loadRetrying(ctx context.Context, load func(context.Context, []byte) error, cfg []byte) error {
	wait := m.o.LoadRetry
	for attempt := 1; ; attempt++ {
		err := load(ctx, cfg)
		if err == nil || IsConfigRefusal(err) || attempt == loadAttempts {
			return err
		}
		m.o.Log.Info("caddy wasn't reachable; loading the config again", "err", err, "attempt", attempt, "wait", wait)
		select {
		case <-ctx.Done():
			return err
		case <-time.After(wait):
		}
		wait *= 2
	}
}

// unreachable records that Caddy's admin API couldn't be reached and
// reports the ingress as pending: nothing is marked as refused, so the next
// pass loads the same config, firewall included (docs/22 §6.6).
func (m *Manager) unreachable(ctx context.Context, routes int, err error) *state.IngressStatus {
	m.mu.Lock()
	if m.unreachableSince.IsZero() {
		m.unreachableSince = m.o.Now()
	}
	m.unreachableErr = err.Error()
	since := m.unreachableSince
	m.mu.Unlock()
	m.o.Log.Warn("caddy wasn't reachable; the config is loaded again on the next pass", "err", err)
	// After a minute, say why: a port another program holds, a Caddy that
	// keeps exiting (its last lines). Before that it is a normal restart.
	var f *failure
	if m.o.Now().Sub(since) >= diagnoseAfter {
		f = m.diagnose(ctx)
	}
	m.setFailure(f)
	return m.status(state.IngressPending, routes, nil, "Caddy wasn't reachable yet; retrying: "+err.Error())
}

// keepsServing is the note on a refused config: Caddy keeps the previous
// one (an admin API load is all or nothing).
func (m *Manager) keepsServing() string {
	m.mu.Lock()
	defer m.mu.Unlock()
	if m.appliedCfg == nil {
		return ""
	}
	return "; Caddy keeps serving the previous routes"
}

// seedResume writes cfg, with the secret files it reads, where "caddy run
// --resume" finds it. While Caddy can't be reached, the config it resumes
// may be what keeps it from starting: with a shared certificate storage
// that is unreachable at boot (the mesh isn't up yet), the internal CA
// fails to provision and Caddy exits, over and over, before launchroomd
// can load anything. Its next start then resumes the config launchroomd
// wants now (local storage while the shared one is unreachable, docs/05 §4).
// cfg is the config without the firewall, which launchroomd loads as soon
// as Caddy answers.
func (m *Manager) seedResume(ctx context.Context, containerID string, files map[string]secret.Secret, cfg []byte) {
	all := make(map[string]secret.Secret, len(files)+1)
	maps.Copy(all, files)
	all[AutosaveFile] = secret.Secret(cfg)
	if err := m.copyFiles(ctx, containerID, all); err != nil {
		m.o.Log.Warn("could not write caddy's resume config", "err", err)
	}
}

// wafConfig renders the config with the web firewall when the running
// Caddy carries the module and a route asks for it. It returns nil (use
// the config without it) otherwise, or when Caddy already refused this
// exact config; key identifies the config with the firewall ("" when not
// tried), routes counts the firewalled routes, errMsg is the state's
// message for the config that will be loaded.
func (m *Manager) wafConfig(routes []Route, t TLS, render Options, c docker.Container) (cfg []byte, key string, n int, errMsg string) {
	support := WafSupportOf(c.Labels)
	asked := false
	for _, r := range routes {
		asked = asked || r.Spec.Waf != nil
	}
	m.mu.Lock()
	if support != m.wafSupport {
		m.wafFailed = "" // another Caddy: try again
	}
	m.wafSupport, m.wafAsked = support, asked
	failed, failedErr := m.wafFailed, m.wafErr
	m.mu.Unlock()
	if !support.Available() || m.o.WafLogAddress == "" {
		m.setWafWanted(0)
		return nil, "", 0, ""
	}
	render.Waf = &WafOptions{LogAddress: m.o.WafLogAddress}
	n = len(WafRoutes(routes, render))
	m.setWafWanted(n)
	if n == 0 {
		return nil, "", 0, ""
	}
	cfg, err := RenderTLS(routes, t, render)
	if err != nil {
		m.o.Log.Warn("web firewall config not rendered; serving the routes without it", "err", err)
		return nil, "", 0, "The firewall config could not be rendered: " + err.Error()
	}
	sum := sha256.Sum256(cfg)
	key = hex.EncodeToString(sum[:]) + "|" + c.ID + "|" + c.StartedAt.String()
	if key == failed {
		return nil, "", 0, failedErr
	}
	return cfg, key, n, ""
}

// traceConfig renders the config with the request trace when the running
// Caddy carries the module and the edge's key is usable. Otherwise, or when
// Caddy already refused this exact config, it returns fallback, render
// unchanged and key "".
func (m *Manager) traceConfig(routes []Route, p *tlsPlan, render Options, c docker.Container, fallback []byte) ([]byte, Options, string) {
	supported := TraceSupported(c.Labels)
	m.mu.Lock()
	if supported != m.traceSupport {
		m.traceFailed = "" // another Caddy: try again
	}
	m.traceSupport = supported
	failed := m.traceFailed
	m.mu.Unlock()
	if !supported || p.trace == nil {
		return fallback, render, ""
	}
	traced := render
	traced.Trace = p.trace
	cfg, err := RenderTLS(routes, p.tls, traced)
	if err != nil {
		m.o.Log.Warn("request trace config not rendered; serving the routes without it", "err", err)
		return fallback, render, ""
	}
	sum := sha256.Sum256(cfg)
	key := hex.EncodeToString(sum[:]) + "|" + c.ID + "|" + c.StartedAt.String()
	if key == failed {
		return fallback, render, ""
	}
	return cfg, traced, key
}

func (m *Manager) setWafWanted(n int) {
	m.mu.Lock()
	m.wafWanted = n
	m.mu.Unlock()
}

// image returns the Caddy image to run for the requested one. Each release
// pins only its own launchroom-caddy digest (internal/policy), and the
// dashboard is updated before the servers (docs/11 §4), so an edge still on
// the previous launchroomd is sent the next release's image, which it can't
// verify. It then keeps the Caddy it already runs, if this binary pins that
// image, and still applies the routes; the new image follows with the
// self-update. Nothing outside the pins ever runs: an unpinned request with
// no pinned container running fails as before.
func (m *Manager) image(ctx context.Context, requested state.ImageRef) (state.ImageRef, error) {
	checkErr := policy.CheckSystemImage(names.SystemCaddy, requested, m.o.Policy)
	if checkErr == nil {
		m.setKeptImage(requested, "", nil)
		return requested, nil
	}
	cs, err := m.list(ctx)
	if err != nil {
		return "", checkErr
	}
	for _, c := range cs {
		current := state.ImageRef(c.Image)
		if c.Name != names.CaddyContainer || c.Labels[names.LabelSpecHash] != m.system(current).Hash() {
			continue
		}
		if policy.CheckSystemImage(names.SystemCaddy, current, m.o.Policy) == nil {
			m.setKeptImage(requested, current, checkErr)
			return current, nil
		}
	}
	return "", checkErr
}

func (m *Manager) setKeptImage(requested, kept state.ImageRef, reason error) {
	m.mu.Lock()
	changed := m.keptImage != kept
	m.keptImage = kept
	m.mu.Unlock()
	if changed && kept != "" {
		m.o.Log.Warn("keeping the running system caddy image until this server runs the matching launchroomd release",
			"requested", requested, "running", kept, "reason", reason)
	}
}

func (m *Manager) waitReady(ctx context.Context) error {
	ctx, cancel := context.WithTimeout(ctx, m.o.ReadyTimeout)
	defer cancel()
	var err error
	for {
		if err = m.o.Admin.Ready(ctx); err == nil {
			return nil
		}
		select {
		case <-ctx.Done():
			return err
		case <-time.After(250 * time.Millisecond):
		}
	}
}

func (m *Manager) list(ctx context.Context) ([]docker.Container, error) {
	return m.o.Docker.ListContainers(ctx, map[string]string{names.LabelSystem: names.SystemCaddy})
}

func (m *Manager) removeContainer(ctx context.Context) error {
	cs, err := m.list(ctx)
	if err != nil {
		return err
	}
	var errs []error
	for _, c := range cs {
		if c.Running() {
			errs = append(errs, m.o.Docker.StopContainer(ctx, c.ID, 10*time.Second))
		}
		errs = append(errs, m.o.Docker.RemoveContainer(ctx, c.ID))
	}
	return errors.Join(errs...)
}

func (m *Manager) ensureContainer(ctx context.Context, image state.ImageRef) (docker.Container, error) {
	c, _, err := m.system(image).Ensure(ctx, m.o.Docker, m.o.Log)
	return c, err
}

// ContainerSpec is the built-in spec of the system Caddy (docs/05 §4). It
// runs with the host network so it can reach container IPs on every
// environment bridge network and bind 80/443 directly, with the admin API on
// loopback only. "--resume" restores the last loaded config (autosave in the
// config volume), so Caddy keeps serving across restarts while the daemon or
// dashboard is down.
func ContainerSpec(serverID string, image state.ImageRef, adminListen string) *docker.ContainerCreate {
	if adminListen == "" {
		adminListen = DefaultAdminListen
	}
	labels := docker.ManagedLabels(serverID)
	labels[names.LabelSystem] = names.SystemCaddy
	memory := int64(1 << 30)
	pids := int64(4096)
	hc := policy.BaseHostConfig()
	hc.NetworkMode = "host"
	hc.RestartPolicy = container.RestartPolicy{Name: container.RestartPolicyUnlessStopped}
	hc.CapAdd = []string{"NET_BIND_SERVICE"}
	hc.Mounts = []mount.Mount{
		{Type: mount.TypeVolume, Source: names.CaddyDataVolume, Target: "/data"},
		{Type: mount.TypeVolume, Source: names.CaddyConfigVolume, Target: "/config"},
	}
	hc.Resources = container.Resources{Memory: memory, MemorySwap: memory, PidsLimit: &pids}
	return &docker.ContainerCreate{
		Name: names.CaddyContainer,
		Config: &container.Config{
			Image:  string(image),
			Cmd:    []string{"caddy", "run", "--resume"},
			Env:    []string{"CADDY_ADMIN=" + adminListen},
			Labels: labels,
		},
		HostConfig: hc,
	}
}

// system is the Caddy system container for image.
func (m *Manager) system(image state.ImageRef) docker.SystemContainer {
	return docker.SystemContainer{
		Component: names.SystemCaddy, Spec: ContainerSpec(m.o.ServerID, image, m.o.Render.AdminListen),
		Volumes: []string{names.CaddyDataVolume, names.CaddyConfigVolume}, Pull: true,
	}
}

func ptr[T any](v T) *T { return &v }
