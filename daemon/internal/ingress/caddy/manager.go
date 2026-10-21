package caddy

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"errors"
	"fmt"
	"log/slog"
	"os"
	"path/filepath"
	"slices"
	"sync"
	"time"

	"github.com/moby/moby/api/types/container"
	"github.com/moby/moby/api/types/mount"

	"github.com/vantezzen/launchroom/daemon/internal/atomicfile"
	"github.com/vantezzen/launchroom/daemon/internal/docker"
	"github.com/vantezzen/launchroom/daemon/internal/ingress/certstore"
	"github.com/vantezzen/launchroom/daemon/internal/policy"
	"github.com/vantezzen/launchroom/daemon/internal/secret"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// System container and volume names (generated, fixed per server).
const (
	ContainerName = "lr-system-caddy"
	DataVolume    = "lr-system-caddy-data"
	ConfigVolume  = "lr-system-caddy-config"
	// CacheFile is the last applied config inside the cache dir (<data>/caddy).
	CacheFile = "config.json"
	// specVersion changes whenever the built-in container spec changes (forces a recreate).
	specVersion = "caddy-v1"
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
	Now          func() time.Time
	// Spec returns the current NodeState's ingress section (nil: Caddy's
	// defaults: local file storage, per-host certificates).
	Spec func() *state.IngressSpec
	// OpenSecret opens sealed ingress credentials addressed to this server.
	OpenSecret func(state.Sealed, state.IngressSecretPurpose) (map[string]secret.Secret, error)
	// ForwardAuthOrigins, when set, replaces Render.ForwardAuthOrigins on
	// every Sync (the allow-list follows an adopted control-plane origin,
	// docs/03 §4.2, §8).
	ForwardAuthOrigins func() []string
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
}

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
	return &state.IngressStatus{State: st, Routes: routes, AppliedAt: appliedAt, Message: truncate(msg),
		Certificates: slices.Clone(m.certs), Storage: cloneStorage(m.storage)}
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

// Sync converges the system container to sys and loads the rendered route
// table. It returns nil when Caddy is disabled on this server.
func (m *Manager) Sync(ctx context.Context, sys state.SystemContainer, routes []Route) *state.IngressStatus {
	if !sys.Enabled {
		m.o.Ask.SetHosts(nil)
		if err := m.removeContainer(ctx); err != nil {
			m.o.Log.Warn("could not remove the disabled system caddy", "err", err)
		}
		m.mu.Lock()
		m.appliedKey, m.containerID, m.certs, m.storage = "", "", nil, nil
		m.mu.Unlock()
		return nil
	}
	fail := func(format string, args ...any) *state.IngressStatus {
		msg := fmt.Sprintf(format, args...)
		m.o.Log.Warn("ingress not applied", "reason", msg)
		return m.status(state.IngressFailed, len(routes), nil, msg)
	}
	image, err := m.image(ctx, sys.Image)
	if err != nil {
		return fail("%v", err)
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
		return fail("system caddy: %v", err)
	}
	sum := sha256.Sum256(cfg)
	files := plan.fingerprint()
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
		m.mu.Unlock()
		return m.status(state.IngressApplied, n, ptr(applied), "")
	}
	// Caddy caches {file.*} secrets until its config is reloaded: force a
	// reload when only the files changed.
	force := m.appliedFiles != files && m.appliedKey != ""
	m.mu.Unlock()

	if err := m.waitReady(ctx); err != nil {
		return m.status(state.IngressPending, len(routes), nil, "waiting for caddy: "+err.Error())
	}
	if err := m.copyFiles(ctx, c.ID, plan.files); err != nil {
		return fail("copy certificate files into caddy: %v", err)
	}
	load := m.o.Admin.Load
	if r, ok := m.o.Admin.(Reloader); ok && force {
		load = r.Reload
	}
	if err := load(ctx, cfg); err != nil {
		return fail("load config: %v", err)
	}
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
	m.mu.Unlock()
	m.o.Log.Info("ingress config applied", "routes", len(routes))
	select { // certificate statuses for the new route table
	case m.certKick <- struct{}{}:
	default:
	}
	return m.status(state.IngressApplied, len(routes), ptr(now), "")
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
	checkErr := policy.CheckSystemImage(policy.SystemCaddy, requested, m.o.Policy)
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
		if c.Name != ContainerName || c.Labels[docker.LabelSpecHash] != SpecHash(current) {
			continue
		}
		if policy.CheckSystemImage(policy.SystemCaddy, current, m.o.Policy) == nil {
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
	return m.o.Docker.ListContainers(ctx, map[string]string{docker.LabelSystem: policy.SystemCaddy})
}

func (m *Manager) removeContainer(ctx context.Context) error {
	cs, err := m.list(ctx)
	if err != nil {
		return err
	}
	var errs []error
	for _, c := range cs {
		if c.Running {
			errs = append(errs, m.o.Docker.StopContainer(ctx, c.ID, 10*time.Second))
		}
		errs = append(errs, m.o.Docker.RemoveContainer(ctx, c.ID))
	}
	return errors.Join(errs...)
}

// SpecHash identifies the built-in container spec for an image.
func SpecHash(image state.ImageRef) string {
	sum := sha256.Sum256([]byte(specVersion + "|" + string(image)))
	return hex.EncodeToString(sum[:16])
}

func (m *Manager) ensureContainer(ctx context.Context, image state.ImageRef) (docker.Container, error) {
	hash := SpecHash(image)
	cs, err := m.list(ctx)
	if err != nil {
		return docker.Container{}, err
	}
	var current *docker.Container
	for i := range cs {
		c := cs[i]
		if current == nil && c.Name == ContainerName && c.Labels[docker.LabelSpecHash] == hash {
			current = &cs[i]
			continue
		}
		m.o.Log.Info("replacing system caddy container", "container", c.Name)
		if c.Running {
			_ = m.o.Docker.StopContainer(ctx, c.ID, 10*time.Second)
		}
		if err := m.o.Docker.RemoveContainer(ctx, c.ID); err != nil {
			return docker.Container{}, err
		}
	}
	if current == nil {
		for _, v := range []string{DataVolume, ConfigVolume} {
			labels := docker.ManagedLabels(m.o.ServerID)
			labels[docker.LabelVolume] = "system-" + v
			labels[docker.LabelSystem] = policy.SystemCaddy
			if err := m.o.Docker.CreateVolume(ctx, docker.VolumeCreate{Name: v, Labels: labels}); err != nil {
				return docker.Container{}, err
			}
		}
		present, err := m.o.Docker.ImagePresent(ctx, string(image))
		if err != nil {
			return docker.Container{}, err
		}
		if !present {
			m.o.Log.Info("pulling system caddy image", "image", image)
			if err := m.o.Docker.PullImage(ctx, string(image)); err != nil {
				return docker.Container{}, err
			}
		}
		id, err := m.o.Docker.CreateContainer(ctx, ContainerSpec(m.o.ServerID, image, m.o.Render.AdminListen))
		if err != nil {
			return docker.Container{}, err
		}
		c, err := m.o.Docker.InspectContainer(ctx, id)
		if err != nil {
			return docker.Container{}, err
		}
		current = &c
	}
	if !current.Running {
		if err := m.o.Docker.StartContainer(ctx, current.ID); err != nil {
			return docker.Container{}, err
		}
		c, err := m.o.Docker.InspectContainer(ctx, current.ID)
		if err != nil {
			return docker.Container{}, err
		}
		current = &c
	}
	return *current, nil
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
	labels[docker.LabelSystem] = policy.SystemCaddy
	labels[docker.LabelSpecHash] = SpecHash(image)
	memory := int64(1 << 30)
	pids := int64(4096)
	return &docker.ContainerCreate{
		Name: ContainerName,
		Config: &container.Config{
			Image:  string(image),
			Cmd:    []string{"caddy", "run", "--resume"},
			Env:    []string{"CADDY_ADMIN=" + adminListen},
			Labels: labels,
		},
		HostConfig: &container.HostConfig{
			NetworkMode:   "host",
			RestartPolicy: container.RestartPolicy{Name: container.RestartPolicyUnlessStopped},
			CapDrop:       []string{"ALL"},
			CapAdd:        []string{"NET_BIND_SERVICE"},
			SecurityOpt:   []string{policy.NoNewPrivileges},
			LogConfig: container.LogConfig{
				Type:   policy.LogDriver,
				Config: map[string]string{"max-size": policy.LogMaxSize, "max-file": policy.LogMaxFile},
			},
			Mounts: []mount.Mount{
				{Type: mount.TypeVolume, Source: DataVolume, Target: "/data"},
				{Type: mount.TypeVolume, Source: ConfigVolume, Target: "/config"},
			},
			Resources: container.Resources{Memory: memory, MemorySwap: memory, PidsLimit: &pids},
		},
	}
}

func ptr[T any](v T) *T { return &v }

func truncate(s string) string {
	if len(s) > 1024 {
		return s[:1024]
	}
	return s
}
