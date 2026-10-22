// Package buildkitd runs launchroom's BuildKit daemon as a system container
// on servers with the build role (docs/04 §5) and connects the daemon to it.
//
// Design (docs/04 §5, docs/09 R1/R23):
//   - moby/buildkit rootless, with seccomp/AppArmor/systempaths unconfined
//     (what rootless BuildKit needs to create its own user namespace) but
//     never --privileged and never --oci-worker-no-process-sandbox; on hosts
//     that restrict unprivileged user namespaces (Ubuntu 23.10+) AppArmor is
//     the installer's launchroom-buildkit profile instead (D58, apparmor.go);
//   - its own bridge network (lr-system-build); build steps share the
//     daemon's network namespace, which therefore has nothing listening but
//     a unix socket: there is no TCP API that a build step could reach;
//   - the daemon talks gRPC to buildkitd through `buildctl dial-stdio` over a
//     Docker exec stream, so the API is reachable only through the Docker
//     socket the daemon already owns;
//   - registry access goes to registry.launchroom.internal:5000, which
//     resolves (ExtraHosts) to the network gateway where the daemon runs a
//     second registry forwarder; plain HTTP is enabled for that host only;
//   - the config file is built into the daemon and copied into the container
//     before it starts; the dashboard only chooses image (digest-pinned and
//     checked against policy) and enablement.
package buildkitd

import (
	"archive/tar"
	"bytes"
	"context"
	"crypto/sha256"
	"encoding/hex"
	"errors"
	"fmt"
	"log/slog"
	"net"
	"net/netip"
	"slices"
	"strings"
	"sync"
	"time"

	bkclient "github.com/moby/buildkit/client"
	"github.com/moby/moby/api/types/container"
	"github.com/moby/moby/api/types/mount"

	"github.com/vantezzen/launchroom/daemon/internal/docker"
	"github.com/vantezzen/launchroom/daemon/internal/policy"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// Fixed host-side names of the build system objects.
const (
	ContainerName = "lr-system-buildkit"
	NetworkName   = "lr-system-build"
	StateVolume   = "lr-system-buildkit-state"
	// RegistryHostname is how buildkitd reaches the registry (gateway forwarder).
	RegistryHostname = "registry.launchroom.internal"
	// RegistryPort is the forwarder port on the gateway.
	RegistryPort = 5000

	// AppArmorProfile is the host profile the installers load on hosts with
	// kernel.apparmor_restrict_unprivileged_userns=1 (D58): unconfined plus
	// userns, attached to nothing by path.
	AppArmorProfile = "launchroom-buildkit"
	// appArmorUnconfined is the AppArmor setting everywhere else.
	appArmorUnconfined = "unconfined"

	configDir  = "/home/user/.config/buildkit"
	configPath = configDir + "/buildkitd.toml"
	stateDir   = "/home/user/.local/share/buildkit"
	specVer    = "buildkitd-v2"
)

// DefaultSubnet is the build network (docs/05 §7); configurable per daemon.
var DefaultSubnet = netip.MustParsePrefix("10.232.0.0/24")

// AllowedFrontends are the only gateway frontends buildkitd runs: Railpack's
// and Docker's dockerfile syntax images (`# syntax=` lines).
var AllowedFrontends = []string{"ghcr.io/railwayapp/railpack-frontend", "docker.io/docker/dockerfile"}

// MirroredRegistries are pulled through the launchroom registry's on-demand
// mirror (mirror/<registry>/…, docs/05 §6); buildkitd falls back to the
// upstream registry when the mirror fails.
var MirroredRegistries = []string{"docker.io", "ghcr.io"}

// GC configures BuildKit garbage collection (docs/04 §5).
type GC struct {
	ReservedSpace string // e.g. "10GB"
	MaxUsedSpace  string // e.g. "60GB"
	MinFreeSpace  string // e.g. "15%"
	// CacheKeep is how long mount caches and local sources survive.
	CacheKeep time.Duration
}

// Options configures a Manager.
type Options struct {
	ServerID string
	Docker   docker.Client
	Ops      docker.SystemOps
	Policy   policy.Config
	// Subnet of the build network; its first host is the gateway.
	Subnet netip.Prefix
	GC     GC
	// Resources are the container's limits when Measure is nil (tests);
	// otherwise they are planned from Measure (PlanResources with
	// Overrides) whenever the container is converged.
	Resources Resources
	// Measure reads the host (launchroomd's own measurements, never the
	// dashboard's) for PlanResources.
	Measure   func(ctx context.Context) HostFacts
	Overrides Overrides
	// Busy reports a running build: the limits are not changed then (a new
	// spec would recreate the container under it).
	Busy func() bool
	Log  *slog.Logger
	// DialTimeout bounds connecting to buildkitd (default 30 s).
	DialTimeout time.Duration
	// ReadyTimeout bounds how long a build waits for buildkitd to start
	// (WaitClient, default 2 min).
	ReadyTimeout time.Duration
	// UsernsSysctl is the AppArmor user-namespace restriction sysctl read to
	// explain a crash loop (default /proc/sys/kernel/apparmor_restrict_unprivileged_userns).
	UsernsSysctl string
	// AppArmorEnabled is the kernel's AppArmor switch (default
	// /sys/module/apparmor/parameters/enabled, "Y" when on).
	AppArmorEnabled string
}

// Manager converges the system BuildKit container.
type Manager struct {
	o Options

	mu      sync.Mutex
	running string // container id when the current spec is running
	// res are the resources of the current spec.
	res Resources
	// platforms is what the container platformsOf names can build (D53).
	platforms   []state.Platform
	platformsOf string
	// synced is set after the first Sync (launchroomd just started: builds
	// wait for it); enabled is what that Sync was told, syncErr its error.
	synced  bool
	enabled bool
	syncErr error
	// profile is the AppArmor profile of the running container ("" = none
	// yet); profileMissing is when starting with AppArmorProfile last
	// failed because the host has not loaded it (zero = not known missing).
	profile        string
	profileMissing time.Time

	// connect dials buildkitd in container id (tests replace it).
	connect func(ctx context.Context, id string) (*bkclient.Client, error)
}

// New returns a Manager.
func New(o Options) *Manager {
	if !o.Subnet.IsValid() {
		o.Subnet = DefaultSubnet
	}
	if o.Resources.Parallelism <= 0 {
		o.Resources.Parallelism = 4
	}
	if o.GC.MinFreeSpace == "" {
		o.GC.MinFreeSpace = "15%"
	}
	if o.GC.CacheKeep <= 0 {
		o.GC.CacheKeep = 7 * 24 * time.Hour
	}
	if o.Log == nil {
		o.Log = slog.New(slog.DiscardHandler)
	}
	if o.DialTimeout <= 0 {
		o.DialTimeout = 30 * time.Second
	}
	if o.ReadyTimeout <= 0 {
		o.ReadyTimeout = 2 * time.Minute
	}
	if o.UsernsSysctl == "" {
		o.UsernsSysctl = "/proc/sys/kernel/apparmor_restrict_unprivileged_userns"
	}
	if o.AppArmorEnabled == "" {
		o.AppArmorEnabled = "/sys/module/apparmor/parameters/enabled"
	}
	m := &Manager{o: o, res: o.Resources}
	m.connect = m.dial
	return m
}

// Resources are the limits of the current (or next) buildkitd container.
func (m *Manager) Resources() Resources {
	m.mu.Lock()
	defer m.mu.Unlock()
	return m.res
}

// planResources re-plans the limits from a fresh measurement; a change that
// would recreate the container waits while a build runs (Settle, Busy).
func (m *Manager) planResources(ctx context.Context) {
	if m.o.Measure == nil {
		return
	}
	next := PlanResources(m.o.Measure(ctx), m.o.Overrides)
	m.mu.Lock()
	defer m.mu.Unlock()
	settled := Settle(m.res, next)
	changes := !sameContainer(settled, m.res)
	if changes && m.res.CPUShares != 0 && m.o.Busy != nil && m.o.Busy() {
		return
	}
	if changes {
		m.o.Log.Info("build resources", "memoryMB", settled.MemoryMB, "swapMB", settled.SwapMB, "cpus", settled.CPUs,
			"parallelism", settled.Parallelism, "nodeHeapMB", settled.NodeHeapMB, "tight", settled.Tight, "noSwap", settled.NoSwap)
	}
	m.res = settled
}

// Gateway is the build network's gateway, where the daemon's build registry
// forwarder listens.
func (m *Manager) Gateway() netip.Addr { return m.o.Subnet.Addr().Next() }

// RegistryHost is the registry host:port buildkitd pushes to and pulls from.
func RegistryHost() string { return fmt.Sprintf("%s:%d", RegistryHostname, RegistryPort) }

// sameContainer: a and b give the same container spec.
func sameContainer(a, b Resources) bool {
	return a.MemoryMB == b.MemoryMB && a.SwapMB == b.SwapMB && a.CPUs == b.CPUs && a.CPUShares == b.CPUShares &&
		a.BlkioWeight == b.BlkioWeight && a.OOMScoreAdj == b.OOMScoreAdj && a.Parallelism == b.Parallelism
}

// Config renders buildkitd.toml.
func (m *Manager) Config() []byte { return m.config(m.Resources()) }

func (m *Manager) config(res Resources) []byte {
	var b strings.Builder
	q := func(s string) string { return fmt.Sprintf("%q", s) }
	b.WriteString("# Generated by launchroomd; do not edit.\n")
	b.WriteString("[log]\n  format = \"json\"\n\n")
	b.WriteString("[worker.oci]\n  enabled = true\n")
	fmt.Fprintf(&b, "  max-parallelism = %d\n  gc = true\n", max(1, res.Parallelism))
	if m.o.GC.ReservedSpace != "" {
		fmt.Fprintf(&b, "  reservedSpace = %s\n", q(m.o.GC.ReservedSpace))
	}
	if m.o.GC.MaxUsedSpace != "" {
		fmt.Fprintf(&b, "  maxUsedSpace = %s\n", q(m.o.GC.MaxUsedSpace))
	}
	fmt.Fprintf(&b, "  minFreeSpace = %s\n\n", q(m.o.GC.MinFreeSpace))
	b.WriteString("  # Mount caches, local sources and git checkouts go first.\n")
	b.WriteString("  [[worker.oci.gcpolicy]]\n")
	fmt.Fprintf(&b, "    keepDuration = %s\n", q(m.o.GC.CacheKeep.String()))
	b.WriteString("    filters = [\"type==source.local\", \"type==exec.cachemount\", \"type==source.git.checkout\"]\n\n")
	b.WriteString("  [[worker.oci.gcpolicy]]\n    all = true\n")
	if m.o.GC.MaxUsedSpace != "" {
		fmt.Fprintf(&b, "    maxUsedSpace = %s\n", q(m.o.GC.MaxUsedSpace))
	}
	fmt.Fprintf(&b, "    minFreeSpace = %s\n\n", q(m.o.GC.MinFreeSpace))
	b.WriteString("[worker.containerd]\n  enabled = false\n\n")
	fmt.Fprintf(&b, "[registry.%s]\n  http = true\n\n", q(RegistryHost()))
	for _, r := range MirroredRegistries {
		fmt.Fprintf(&b, "[registry.%s]\n  mirrors = [%s]\n\n", q(r), q(RegistryHost()+"/mirror/"+r))
	}
	b.WriteString("[frontend.\"gateway.v0\"]\n")
	allowed := make([]string, len(AllowedFrontends))
	for i, f := range AllowedFrontends {
		allowed[i] = q(f)
	}
	fmt.Fprintf(&b, "  allowedRepositories = [%s]\n\n", strings.Join(allowed, ", "))
	b.WriteString("[history]\n  maxAge = \"168h\"\n  maxEntries = 200\n")
	return []byte(b.String())
}

// SpecHash identifies the built-in container spec and config for an image
// and AppArmor profile ("unconfined" or AppArmorProfile).
func (m *Manager) SpecHash(image state.ImageRef, appArmor string) string {
	return m.specHash(image, appArmor, m.Resources())
}

func (m *Manager) specHash(image state.ImageRef, appArmor string, res Resources) string {
	sum := sha256.New()
	_, _ = fmt.Fprintf(sum, "%s|%s|%s|%v|%d|%d|%d|%d|%d|", specVer, image, m.o.Subnet, res.CPUs, res.MemoryMB, res.SwapMB,
		res.CPUShares, res.BlkioWeight, res.OOMScoreAdj)
	if appArmor != appArmorUnconfined {
		_, _ = fmt.Fprintf(sum, "apparmor=%s|", appArmor)
	}
	sum.Write(m.config(res))
	return hex.EncodeToString(sum.Sum(nil)[:16])
}

// ContainerSpec is the built-in container spec of the system buildkitd
// with an AppArmor profile ("unconfined" or AppArmorProfile).
func (m *Manager) ContainerSpec(image state.ImageRef, appArmor string) *docker.ContainerCreate {
	return m.containerSpec(image, appArmor, m.Resources())
}

func (m *Manager) containerSpec(image state.ImageRef, appArmor string, r Resources) *docker.ContainerCreate {
	labels := docker.ManagedLabels(m.o.ServerID)
	labels[docker.LabelSystem] = policy.SystemBuildkit
	labels[docker.LabelSpecHash] = m.specHash(image, appArmor, r)
	pids := int64(32768)
	// Builds lose every contest for the host (docs/04 §5.2): a hard memory
	// limit (plus some swap), a low CPU and block I/O weight, a CPU cap on
	// one- and two-CPU hosts. No MemoryReservation: on cgroup v2 it becomes
	// memory.low, a protection, the opposite of what builds should get.
	res := container.Resources{PidsLimit: &pids, CPUShares: r.CPUShares, BlkioWeight: r.BlkioWeight}
	if r.MemoryMB > 0 {
		res.Memory = int64(r.MemoryMB) << 20
		res.MemorySwap = res.Memory + int64(r.SwapMB)<<20
	}
	if r.CPUs > 0 {
		res.NanoCPUs = int64(r.CPUs * 1e9)
	}
	return &docker.ContainerCreate{
		Name: ContainerName,
		Config: &container.Config{
			Image: string(image),
			// The image entrypoint is `rootlesskit buildkitd`; these are buildkitd flags.
			Cmd:    []string{"--config", configPath, "--oci-worker-net=host"},
			Labels: labels,
		},
		HostConfig: &container.HostConfig{
			NetworkMode:   NetworkName,
			RestartPolicy: container.RestartPolicy{Name: container.RestartPolicyUnlessStopped},
			// Rootless BuildKit needs to set up its own user namespace; these
			// options relax only the filters that prevent that (docs/04 §5):
			// AppArmor is unconfined, or the launchroom-buildkit profile
			// (unconfined plus userns) where the host restricts user
			// namespaces (D58). No --privileged, no added capabilities, no
			// host namespaces.
			SecurityOpt: []string{"seccomp=unconfined", "apparmor=" + appArmor},
			// systempaths=unconfined: no masked or read-only /proc and /sys paths
			// (the Engine API form of the CLI option), so it can mount its own /proc.
			MaskedPaths:   []string{},
			ReadonlyPaths: []string{},
			ExtraHosts:    []string{RegistryHostname + ":" + m.Gateway().String()},
			LogConfig: container.LogConfig{
				Type:   policy.LogDriver,
				Config: map[string]string{"max-size": policy.LogMaxSize, "max-file": policy.LogMaxFile},
			},
			Mounts:    []mount.Mount{{Type: mount.TypeVolume, Source: StateVolume, Target: stateDir}},
			Resources: res,
			// The kernel's OOM killer picks build processes first; build
			// steps inherit the score and can't lower it.
			OomScoreAdj: r.OOMScoreAdj,
		},
	}
}

// Sync converges the container to sys. It returns nil when BuildKit is
// disabled (and removes a previous container; the state volume is kept).
func (m *Manager) Sync(ctx context.Context, sys state.SystemContainer) error {
	if !sys.Enabled {
		m.mu.Lock()
		m.running, m.synced, m.enabled, m.syncErr, m.profile = "", true, false, nil, ""
		m.mu.Unlock()
		return m.remove(ctx)
	}
	id, err := m.sync(ctx, sys.Image)
	m.mu.Lock()
	m.running = id
	m.synced, m.enabled, m.syncErr = true, true, err
	m.mu.Unlock()
	return err
}

func (m *Manager) sync(ctx context.Context, image state.ImageRef) (string, error) {
	if err := policy.CheckSystemImage(policy.SystemBuildkit, image, m.o.Policy); err != nil {
		return "", err
	}
	if err := m.ensureNetwork(ctx); err != nil {
		return "", err
	}
	m.planResources(ctx)
	return m.ensureProfiled(ctx, image)
}

// Platforms returns the platforms the running buildkitd can build for: its
// own and those the engine's binfmt handlers emulate (Docker Desktop's QEMU,
// OrbStack's Rosetta; launchroom never installs handlers, that needs a
// privileged container). BuildKit finds them by running a test binary per
// architecture at start, so the answer is asked once per container. nil
// when buildkitd is not running or can't be asked yet.
func (m *Manager) Platforms(ctx context.Context) []state.Platform {
	m.mu.Lock()
	id := m.running
	if id != "" && id == m.platformsOf {
		out := m.platforms
		m.mu.Unlock()
		return out
	}
	m.mu.Unlock()
	if id == "" {
		return nil
	}
	c, err := m.Client(ctx)
	if err != nil {
		return nil
	}
	defer func() { _ = c.Close() }()
	workers, err := c.ListWorkers(ctx)
	if err != nil {
		return nil
	}
	var names []string
	for _, w := range workers {
		for _, p := range w.Platforms {
			names = append(names, p.OS+"/"+p.Architecture)
		}
	}
	out := BuildPlatforms(names)
	m.mu.Lock()
	if m.running == id {
		m.platforms, m.platformsOf = out, id
	}
	m.mu.Unlock()
	return out
}

// BuildPlatforms keeps the launchroom platforms among BuildKit's worker
// platforms ("linux/amd64", "linux/arm64"; variants and other
// architectures are dropped), sorted and without duplicates.
func BuildPlatforms(names []string) []state.Platform {
	var out []state.Platform
	for _, n := range names {
		p := state.Platform(strings.ToLower(n))
		if p.Validate() == nil && !slices.Contains(out, p) {
			out = append(out, p)
		}
	}
	slices.Sort(out)
	return out
}

// Enabled reports whether a buildkitd container is running for the current spec.
func (m *Manager) Enabled() bool {
	m.mu.Lock()
	defer m.mu.Unlock()
	return m.running != ""
}

func (m *Manager) list(ctx context.Context) ([]docker.Container, error) {
	return m.o.Docker.ListContainers(ctx, map[string]string{docker.LabelSystem: policy.SystemBuildkit})
}

func (m *Manager) remove(ctx context.Context) error {
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

func (m *Manager) ensureNetwork(ctx context.Context) error {
	ns, err := m.o.Docker.ListNetworks(ctx)
	if err != nil {
		return err
	}
	for _, n := range ns {
		if n.Name != NetworkName {
			continue
		}
		if len(n.Subnets) == 1 && n.Subnets[0] == m.o.Subnet {
			return nil
		}
		return fmt.Errorf("buildkitd: network %s exists with subnet %v, want %s (remove it or change LR_BUILD_SUBNET)", NetworkName, n.Subnets, m.o.Subnet)
	}
	labels := docker.ManagedLabels(m.o.ServerID)
	labels[docker.LabelSystem] = policy.SystemBuildkit
	labels[docker.LabelNetwork] = "system-build"
	_, err = m.o.Docker.CreateNetwork(ctx, docker.NetworkCreate{Name: NetworkName, Subnet: m.o.Subnet, Labels: labels})
	return err
}

func (m *Manager) ensureContainer(ctx context.Context, image state.ImageRef, appArmor string) (string, error) {
	res := m.Resources()
	hash := m.specHash(image, appArmor, res)
	cs, err := m.list(ctx)
	if err != nil {
		return "", err
	}
	var current *docker.Container
	for i := range cs {
		c := cs[i]
		if current == nil && c.Name == ContainerName && c.Labels[docker.LabelSpecHash] == hash {
			current = &cs[i]
			continue
		}
		m.o.Log.Info("replacing system buildkitd container", "container", c.Name)
		if c.Running {
			_ = m.o.Docker.StopContainer(ctx, c.ID, 30*time.Second)
		}
		if err := m.o.Docker.RemoveContainer(ctx, c.ID); err != nil {
			return "", err
		}
	}
	if current == nil {
		labels := docker.ManagedLabels(m.o.ServerID)
		labels[docker.LabelVolume] = "system-" + StateVolume
		labels[docker.LabelSystem] = policy.SystemBuildkit
		if err := m.o.Docker.CreateVolume(ctx, docker.VolumeCreate{Name: StateVolume, Labels: labels}); err != nil {
			return "", err
		}
		present, err := m.o.Docker.ImagePresent(ctx, string(image))
		if err != nil {
			return "", err
		}
		if !present {
			m.o.Log.Info("pulling system buildkitd image", "image", image)
			if err := m.o.Docker.PullImage(ctx, string(image)); err != nil {
				return "", err
			}
		}
		id, err := m.o.Docker.CreateContainer(ctx, m.containerSpec(image, appArmor, res))
		if err != nil {
			return "", err
		}
		if err := m.o.Ops.CopyToContainer(ctx, id, "/", configArchive(m.config(res))); err != nil {
			_ = m.o.Docker.RemoveContainer(ctx, id)
			return "", fmt.Errorf("buildkitd: install config: %w", err)
		}
		c, err := m.o.Docker.InspectContainer(ctx, id)
		if err != nil {
			return "", err
		}
		current = &c
	}
	if current.Restarting && current.RestartCount >= crashRestarts {
		// Docker's restart policy keeps it going; builds wait for it and
		// fail with its diagnosis (WaitClient) when it never comes up.
		m.o.Log.Warn("system buildkitd keeps restarting", "restarts", current.RestartCount,
			"exitCode", current.ExitCode, "oomKilled", current.OOMKilled)
	}
	if !current.Running {
		if err := m.o.Docker.StartContainer(ctx, current.ID); err != nil {
			return "", err
		}
	}
	return current.ID, nil
}

// configArchive is a tar with the config file (owned by the rootless user).
func configArchive(cfg []byte) *bytes.Reader {
	var buf bytes.Buffer
	tw := tar.NewWriter(&buf)
	now := time.Unix(0, 0)
	for _, d := range []string{"home/user/.config/", "home/user/.config/buildkit/"} {
		_ = tw.WriteHeader(&tar.Header{Typeflag: tar.TypeDir, Name: d, Mode: 0o755, Uid: 1000, Gid: 1000, ModTime: now})
	}
	_ = tw.WriteHeader(&tar.Header{Typeflag: tar.TypeReg, Name: strings.TrimPrefix(configPath, "/"), Mode: 0o644, Size: int64(len(cfg)), Uid: 1000, Gid: 1000, ModTime: now})
	_, _ = tw.Write(cfg)
	_ = tw.Close()
	return bytes.NewReader(buf.Bytes())
}

// ErrNotRunning means the server has no running buildkitd (no build role).
var ErrNotRunning = errors.New("buildkitd is not running on this server (build role disabled or not started yet)")

// Client connects to the running buildkitd and waits until it answers
// (DialTimeout). Builds use WaitClient, which also waits for a starting or
// restarting container. The caller closes the client.
func (m *Manager) Client(ctx context.Context) (*bkclient.Client, error) {
	m.mu.Lock()
	id := m.running
	m.mu.Unlock()
	if id == "" {
		return nil, ErrNotRunning
	}
	return m.connect(ctx, id)
}

// dial connects through `buildctl dial-stdio` and retries until buildkitd
// lists its workers or DialTimeout passes.
func (m *Manager) dial(ctx context.Context, id string) (*bkclient.Client, error) {
	dial := func(ctx context.Context, _ string) (net.Conn, error) {
		return m.o.Ops.DialExec(ctx, id, []string{"buildctl", "dial-stdio"})
	}
	ctx, cancel := context.WithTimeout(ctx, m.o.DialTimeout)
	defer cancel()
	var lastErr error
	for {
		c, err := bkclient.New(ctx, "", bkclient.WithContextDialer(dial))
		if err == nil {
			if _, err = c.ListWorkers(ctx); err == nil {
				return c, nil
			}
			_ = c.Close()
		}
		lastErr = err
		select {
		case <-ctx.Done():
			return nil, fmt.Errorf("buildkitd: not ready: %w", lastErr)
		case <-time.After(500 * time.Millisecond):
		}
	}
}
