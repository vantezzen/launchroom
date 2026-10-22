// Package policy is the daemon's local policy floor (docs/03-daemon-protocol.md
// §8, docs/09-security.md R23). It is enforced regardless of what the
// dashboard sends, so that even a compromised control plane cannot get
// trivial host root through a normal-looking spec.
//
// Check validates a WorkloadSpec; CheckContainer validates the Docker
// container-create configuration derived from it (defence in depth: the
// converter in internal/docker must never produce anything this rejects).
package policy

import (
	"errors"
	"fmt"
	"net/netip"
	"slices"
	"strconv"
	"strings"
	"time"

	"github.com/moby/moby/api/types/container"
	"github.com/moby/moby/api/types/mount"

	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// Hardened container defaults required by the floor.
const (
	LogDriver          = "json-file"
	LogMaxSize         = "10m"
	LogMaxFile         = "3"
	NoNewPrivileges    = "no-new-privileges:true"
	DefaultRegistry    = "127.0.0.1:5000/"
	capabilityDropAll  = "ALL"
	defaultMaxCPUs     = 64
	defaultMaxMemoryMB = 256 * 1024
	defaultMaxPids     = 32768
)

// System components whose images the dashboard may choose (NodeState.system).
const (
	SystemCaddy     = "caddy"
	SystemBuildkit  = "buildkit"
	SystemTailscale = "tailscale"
)

// DefaultSystemImageDigests pins the image digests the daemon accepts for its
// system containers (docs/09 R31). The repository may differ (a mirror in the
// local registry is fine) but the content digest must be one of these, so a
// compromised control plane cannot run an arbitrary image with the system
// containers' extra privileges (host network). The pins are compiled into the
// signed launchroomd binary (and dockerd-guard, same image), so a server
// learns new ones only by running a new signed release (self-update carries
// them); extra digests come only from host config (LR_SYSTEM_IMAGE_DIGESTS).
//
//	caddy:    a release build: ghcr.io/vantezzen/launchroom-caddy of the same
//	          release (deploy/images/caddy, multi-arch index digest), set at
//	          build time (LaunchroomCaddyImageDigest); the stock image is then
//	          not accepted. A dev build (no digest set): docker.io/library/caddy:2
//	          = v2.11.7 (StockCaddyImageDigest), what the dev stack and tests run.
//	buildkit: docker.io/moby/buildkit:v0.34.0-rootless (multi-arch index digest)
//	tailscale: docker.io/tailscale/tailscale:v1.104.1 (multi-arch index digest)
var DefaultSystemImageDigests = systemImageDigests(launchroomCaddyDigest)

// launchroomCaddyDigest is the index digest of this release's launchroom-caddy
// image ("sha256:<64 hex>"), set by the release build (.github/workflows/
// release.yml builds the image first):
//
//	-ldflags "-X github.com/vantezzen/launchroom/daemon/internal/policy.launchroomCaddyDigest=sha256:…"
//
// Empty in dev and test builds.
var launchroomCaddyDigest string

// StockCaddyImageDigest pins docker.io/library/caddy:2 = v2.11.7 (multi-arch
// index digest), the edge Caddy of dev builds (the base of launchroom-caddy).
const StockCaddyImageDigest = "sha256:685ae3d7c34d9b5f8caeddc5d14051db7bbd88ff1f5a2fa0c16bd8ca328fb7af"

// LaunchroomCaddyImageDigest is the launchroom-caddy digest this binary was
// built with, or "" for a dev build.
func LaunchroomCaddyImageDigest() string { return launchroomCaddyDigest }

// systemImageDigests builds the default pins. A release build pins only its
// own launchroom-caddy: the stock image lacks the storage and DNS modules and
// no release dashboard sends it, so accepting it would only widen what a
// compromised control plane can choose. A malformed build-time digest is a
// broken release and stops the binary (fail closed) rather than falling back.
func systemImageDigests(caddyDigest string) map[string][]string {
	caddy := StockCaddyImageDigest
	if caddyDigest != "" {
		if !isDigest(caddyDigest) {
			panic(fmt.Sprintf("policy: build-time launchroom-caddy digest %q is not sha256:<64 hex>", caddyDigest))
		}
		caddy = caddyDigest
	}
	return map[string][]string{
		SystemCaddy:    {caddy},
		SystemBuildkit: {BuildkitImageDigest},
		// The mesh client runs with the host network, NET_ADMIN and /dev/net/tun.
		SystemTailscale: {TailscaleImageDigest},
	}
}

// isDigest reports whether s is "sha256:" followed by 64 lowercase hex digits.
func isDigest(s string) bool {
	hexPart, ok := strings.CutPrefix(s, "sha256:")
	if !ok || len(hexPart) != 64 {
		return false
	}
	for _, c := range hexPart {
		if (c < '0' || c > '9') && (c < 'a' || c > 'f') {
			return false
		}
	}
	return true
}

// TailscaleImageDigest pins tailscale/tailscale:v1.104.1 (docs/05 §2).
const TailscaleImageDigest = "sha256:87c55d6be56ad85b3d8bceca5f87f73de288c609131460dd1be1874c250cc41d"

// BuildkitImageDigest pins moby/buildkit:v0.34.0-rootless.
const BuildkitImageDigest = "sha256:65eca3c01cd1180c8f64565c4e3c5983af5145818b0e6fa863385d099eb73855"

// MeshRange is the mesh (Tailscale/Headscale CGNAT) range. Address upstreams
// in it are mesh-published replicas on other servers (docs/05 §4).
var MeshRange = netip.MustParsePrefix("100.64.0.0/10")

// DefaultCapabilities is the allow-list from docs/03 §8.
var DefaultCapabilities = []state.Capability{"NET_BIND_SERVICE", "CHOWN", "SETUID", "SETGID", "DAC_OVERRIDE", "FOWNER"}

// Config is the local policy. Relaxations (extra registries, capabilities,
// higher limits) come only from data/daemon/policy.yaml on the host, never
// from the dashboard.
type Config struct {
	// AllowedRegistries are image reference prefixes; each must end in "/".
	AllowedRegistries []string
	// AllowedCapabilities may be added on top of cap-drop ALL.
	AllowedCapabilities []state.Capability
	// Upper bounds for workload resources.
	MaxCPUs     float64
	MaxMemoryMB int
	MaxPids     int
	// SystemImageDigests maps a system component ("caddy") to the accepted
	// "sha256:…" image digests (see DefaultSystemImageDigests).
	SystemImageDigests map[string][]string
	// UpstreamRanges are where route address upstreams ({"address": …}) may
	// point (default: the mesh range only).
	UpstreamRanges []netip.Prefix
	// LocalUpstreams are exact ip:port address upstreams allowed besides
	// UpstreamRanges, from the daemon's own config (LR_ALLOWED_LOCAL_UPSTREAMS;
	// the control-plane host allows the dashboard's 127.0.0.1:8000). Without
	// this a compromised control plane could make Caddy proxy the internet to
	// any loopback or host port (the daemon's ask endpoint, Caddy's admin API,
	// databases bound to localhost…).
	LocalUpstreams []netip.AddrPort
	// CertStorageAddrs are exact ip:port addresses of shared certificate
	// storage allowed besides the mesh range (LR_CERT_STORAGE_ALLOW; e.g. the
	// control plane's Valkey on 127.0.0.1:6379 for its own edge). Kept apart
	// from LocalUpstreams so the storage port can never become a route upstream.
	CertStorageAddrs []netip.AddrPort
}

// DefaultConfig returns the built-in floor: the local registry only, the
// default capability allow-list and generous resource ceilings.
func DefaultConfig() Config {
	return Config{
		AllowedRegistries:   []string{DefaultRegistry},
		AllowedCapabilities: slices.Clone(DefaultCapabilities),
		MaxCPUs:             defaultMaxCPUs,
		MaxMemoryMB:         defaultMaxMemoryMB,
		MaxPids:             defaultMaxPids,
		SystemImageDigests:  cloneDigests(DefaultSystemImageDigests),
		UpstreamRanges:      []netip.Prefix{MeshRange},
	}
}

// Host-side name prefixes (docs/09 R3). The dashboard generates lr-e_<hash>
// environment networks and lr-v_<hash> per-service volumes; lr-system-* are
// the daemon's own objects (Caddy data, BuildKit state and network) and are
// never part of a NodeState.
const (
	NetworkPrefix = "lr-e_"
	VolumePrefix  = "lr-v_"
	SystemPrefix  = "lr-system-"
)

// CheckNetworkName allows only environment network names in a NodeState.
func CheckNetworkName(name state.HostName) error {
	if !strings.HasPrefix(string(name), NetworkPrefix) {
		return violation("networks", "network %s is not an environment network (%s…)", name, NetworkPrefix)
	}
	return nil
}

// CheckVolumeName allows only per-service workload volume names in a NodeState.
func CheckVolumeName(name state.HostName) error {
	if !strings.HasPrefix(string(name), VolumePrefix) {
		return violation("mounts", "volume %s is not a workload volume (%s…); system volumes are never mountable", name, VolumePrefix)
	}
	return nil
}

// CheckUpstreamAddress allows an address upstream only inside UpstreamRanges
// or when it is listed exactly in LocalUpstreams.
func CheckUpstreamAddress(addr state.AddrPort, cfg Config) error {
	ap, err := netip.ParseAddrPort(string(addr))
	if err != nil || ap.Port() == 0 {
		return violation("upstreams", "address upstream %q is not an ip:port", addr)
	}
	ap = netip.AddrPortFrom(ap.Addr().Unmap(), ap.Port())
	if slices.Contains(cfg.LocalUpstreams, ap) {
		return nil
	}
	for _, p := range cfg.UpstreamRanges {
		if p.Contains(ap.Addr()) {
			return nil
		}
	}
	return violation("upstreams", "address upstream %s is outside the mesh range and not in the local allow-list (LR_ALLOWED_LOCAL_UPSTREAMS)", ap)
}

// CheckCertStorageAddress allows shared certificate storage (NodeState
// ingress.storage) only in the mesh range or at an address listed exactly in
// CertStorageAddrs: Caddy runs with the host network, so a compromised
// control plane must not point it at arbitrary host or LAN services.
func CheckCertStorageAddress(addr state.AddrPort, cfg Config) error {
	ap, err := netip.ParseAddrPort(string(addr))
	if err != nil || ap.Port() == 0 {
		return violation("cert-storage", "storage address %q is not an ip:port", addr)
	}
	ap = netip.AddrPortFrom(ap.Addr().Unmap(), ap.Port())
	if slices.Contains(cfg.CertStorageAddrs, ap) || MeshRange.Contains(ap.Addr()) {
		return nil
	}
	return violation("cert-storage", "storage address %s is outside the mesh range and not in the local allow-list (LR_CERT_STORAGE_ALLOW)", ap)
}

// FilterRouteUpstreams returns the routes with every address upstream the
// policy refuses removed, plus the refused addresses (sorted, unique). Workload
// upstreams are kept: they resolve to container IPs on launchroom networks.
// The input is not modified.
func FilterRouteUpstreams(routes []state.RouteSpec, cfg Config) ([]state.RouteSpec, []state.AddrPort) {
	out := make([]state.RouteSpec, 0, len(routes))
	var refused []state.AddrPort
	for _, r := range routes {
		kept := make([]state.RouteUpstream, 0, len(r.Upstreams))
		for _, u := range r.Upstreams {
			if u.Address != "" {
				if err := CheckUpstreamAddress(u.Address, cfg); err != nil {
					refused = append(refused, u.Address)
					continue
				}
			}
			kept = append(kept, u)
		}
		r.Upstreams = kept
		out = append(out, r)
	}
	slices.Sort(refused)
	return out, slices.Compact(refused)
}

func cloneDigests(m map[string][]string) map[string][]string {
	out := make(map[string][]string, len(m))
	for k, v := range m {
		out[k] = slices.Clone(v)
	}
	return out
}

// CheckSystemImage requires a digest-pinned reference whose digest is pinned
// for the component. Unlike workload images, the registry does not matter:
// the digest identifies the content.
func CheckSystemImage(component string, ref state.ImageRef, cfg Config) error {
	if err := ref.Validate(); err != nil {
		return violation("system-image", "%s image must be digest-pinned: %v", component, err)
	}
	_, digest, _ := strings.Cut(string(ref), "@")
	if slices.Contains(cfg.SystemImageDigests[component], digest) {
		return nil
	}
	return violation("system-image", "%s image %s is not a pinned %s release for this launchroomd version", component, digest, component)
}

// Validate checks the policy config itself.
func (c Config) Validate() error {
	var errs []error
	if len(c.AllowedRegistries) == 0 {
		errs = append(errs, errors.New("policy: at least one allowed registry is required"))
	}
	for _, r := range c.AllowedRegistries {
		if !strings.HasSuffix(r, "/") || strings.Count(r, "/") < 1 || strings.ContainsAny(r, "@ ") {
			errs = append(errs, fmt.Errorf("policy: allowed registry %q must be a prefix ending in '/'", r))
		}
	}
	for _, capName := range c.AllowedCapabilities {
		if err := capName.Validate(); err != nil {
			errs = append(errs, fmt.Errorf("policy: %w", err))
		}
	}
	if c.MaxCPUs <= 0 || c.MaxMemoryMB <= 0 || c.MaxPids <= 0 {
		errs = append(errs, errors.New("policy: resource ceilings must be positive"))
	}
	return errors.Join(errs...)
}

// Violation is a policy floor violation.
type Violation struct {
	Rule   string
	Detail string
}

func (v *Violation) Error() string { return "policy: " + v.Rule + ": " + v.Detail }

func violation(rule, format string, args ...any) error {
	return &Violation{Rule: rule, Detail: fmt.Sprintf(format, args...)}
}

// Check validates a workload spec against the policy floor. The spec must
// already be grammatically valid (NodeState.Validate); Check re-checks the
// security-relevant parts anyway so it is safe to call on its own.
func Check(spec state.WorkloadSpec, cfg Config) error {
	if err := cfg.Validate(); err != nil {
		return err
	}
	var errs []error
	add := func(err error) {
		if err != nil {
			errs = append(errs, err)
		}
	}

	add(CheckImage(spec.Image, cfg))
	for _, c := range spec.Capabilities {
		if !slices.Contains(cfg.AllowedCapabilities, c) {
			add(violation("capabilities", "%s is not in the local allow-list", c))
		}
	}
	for _, m := range spec.Mounts {
		if err := m.Volume.Validate(); err != nil {
			add(violation("mounts", "only named launchroom volumes may be mounted: %v", err))
		}
		if err := m.Target.Validate(); err != nil {
			add(violation("mounts", "%v", err))
		}
	}
	if st := spec.Stop; st != nil {
		if err := st.Signal.Validate(); err != nil {
			add(violation("stop", "%v", err))
		}
		if d := st.Timeout.Std(); d < time.Second || d > state.MaxStopTimeout {
			add(violation("stop", "stop timeout must be between 1s and %s", state.MaxStopTimeout))
		}
	}
	r := spec.Resources
	switch {
	case !(r.CPUs > 0):
		add(violation("resources", "a CPU limit is required"))
	case r.CPUs > cfg.MaxCPUs:
		add(violation("resources", "cpus %.2f exceeds local maximum %.2f", r.CPUs, cfg.MaxCPUs))
	}
	switch {
	case r.MemoryMB <= 0:
		add(violation("resources", "a memory limit is required"))
	case r.MemoryMB > cfg.MaxMemoryMB:
		add(violation("resources", "memory %d MB exceeds local maximum %d MB", r.MemoryMB, cfg.MaxMemoryMB))
	}
	switch {
	case r.Pids <= 0:
		add(violation("resources", "a pids limit is required"))
	case r.Pids > cfg.MaxPids:
		add(violation("resources", "pids %d exceeds local maximum %d", r.Pids, cfg.MaxPids))
	}
	return errors.Join(errs...)
}

// CheckImage requires a digest-pinned reference from an allowed registry.
func CheckImage(ref state.ImageRef, cfg Config) error {
	if err := ref.Validate(); err != nil {
		return violation("image", "images must be digest-pinned: %v", err)
	}
	for _, prefix := range cfg.AllowedRegistries {
		if strings.HasPrefix(string(ref), prefix) {
			return nil
		}
	}
	return violation("image", "%s is not from an allowed registry (%s)", ref.Repository(), strings.Join(cfg.AllowedRegistries, ", "))
}

// unconfinedSecurityOpts disable seccomp/AppArmor/SELinux confinement.
var unconfinedSecurityOpts = []string{
	"seccomp=unconfined", "seccomp:unconfined",
	"apparmor=unconfined", "apparmor:unconfined",
	"label=disable", "label:disable",
	"systempaths=unconfined",
}

// CheckContainer validates the final container-create configuration.
func CheckContainer(cfg *container.Config, hc *container.HostConfig, pc Config) error {
	if cfg == nil || hc == nil {
		return violation("container", "config and host config are required")
	}
	var errs []error
	add := func(rule, format string, args ...any) { errs = append(errs, violation(rule, format, args...)) }

	if err := CheckImage(state.ImageRef(cfg.Image), pc); err != nil {
		errs = append(errs, err)
	}
	if hc.Privileged {
		add("privileged", "privileged containers are not allowed")
	}

	// Namespaces: only private ones.
	if nm := string(hc.NetworkMode); nm == "host" || strings.HasPrefix(nm, "container:") {
		add("namespaces", "network mode %q is not allowed", nm)
	}
	if hc.PidMode != "" {
		add("namespaces", "pid mode %q is not allowed", hc.PidMode)
	}
	if ipc := string(hc.IpcMode); ipc != "" && ipc != "private" && ipc != "none" {
		add("namespaces", "ipc mode %q is not allowed", ipc)
	}
	if hc.UTSMode != "" {
		add("namespaces", "uts mode %q is not allowed", hc.UTSMode)
	}
	if hc.UsernsMode != "" {
		add("namespaces", "userns mode %q is not allowed", hc.UsernsMode)
	}
	if cg := string(hc.CgroupnsMode); cg != "" && cg != "private" {
		add("namespaces", "cgroupns mode %q is not allowed", cg)
	}
	if hc.CgroupParent != "" || hc.Cgroup != "" {
		add("namespaces", "custom cgroups are not allowed")
	}

	// Devices and host filesystem.
	if len(hc.Devices) > 0 || len(hc.DeviceRequests) > 0 || len(hc.DeviceCgroupRules) > 0 {
		add("devices", "devices are not allowed")
	}
	if len(hc.Binds) > 0 || len(hc.VolumesFrom) > 0 || hc.VolumeDriver != "" || len(cfg.Volumes) > 0 {
		add("mounts", "bind mounts, volumes-from, volume drivers and anonymous volumes are not allowed")
	}
	for _, m := range hc.Mounts {
		if m.Type != mount.TypeVolume || m.BindOptions != nil || m.TmpfsOptions != nil || m.ImageOptions != nil {
			add("mounts", "only named volume mounts are allowed (got %s)", m.Type)
			continue
		}
		if m.VolumeOptions != nil && (m.VolumeOptions.DriverConfig != nil || m.VolumeOptions.Subpath != "") {
			add("mounts", "volume driver options are not allowed (they can bind host paths)")
		}
		if err := state.ContainerPath(m.Target).Validate(); err != nil {
			add("mounts", "%v", err)
		}
	}
	if len(hc.Tmpfs) > 0 || len(hc.StorageOpt) > 0 || len(hc.Sysctls) > 0 {
		add("host", "tmpfs, storage options and sysctls are not allowed")
	}
	if hc.MaskedPaths != nil || hc.ReadonlyPaths != nil {
		add("host", "overriding masked/read-only paths is not allowed")
	}
	if hc.Runtime != "" && hc.Runtime != "runc" {
		add("host", "runtime %q is not allowed", hc.Runtime)
	}
	if hc.OomKillDisable != nil && *hc.OomKillDisable {
		add("host", "disabling the OOM killer is not allowed")
	}

	// Capabilities and security options.
	if !slices.Contains(hc.CapDrop, capabilityDropAll) {
		add("capabilities", "cap-drop ALL is required")
	}
	for _, c := range hc.CapAdd {
		if !slices.Contains(pc.AllowedCapabilities, state.Capability(strings.TrimPrefix(strings.ToUpper(c), "CAP_"))) {
			add("capabilities", "%s is not in the local allow-list", c)
		}
	}
	if !slices.Contains(hc.SecurityOpt, NoNewPrivileges) {
		add("security", "no-new-privileges is required")
	}
	for _, o := range hc.SecurityOpt {
		lo := strings.ToLower(o)
		switch {
		case slices.Contains(unconfinedSecurityOpts, lo):
			add("security", "security option %q is not allowed", o)
		case strings.HasPrefix(lo, "apparmor"):
			// Docker's default profile only: a named host profile can be
			// wider (launchroom-buildkit grants user namespaces, D58).
			add("security", "security option %q is not allowed: workloads keep Docker's default AppArmor profile", o)
		}
	}

	// Required defaults. The init process may only be left out with a typed
	// stop (signal from the allow-list, bounded timeout): the image's
	// entrypoint then runs as PID 1 and gets the signal itself (D42).
	stopTyped := cfg.StopSignal != "" && cfg.StopTimeout != nil
	if cfg.StopSignal != "" && state.StopSignal(cfg.StopSignal).Validate() != nil {
		add("defaults", "stop signal %q is not allowed", cfg.StopSignal)
	}
	if t := cfg.StopTimeout; t != nil && (*t < 1 || time.Duration(*t)*time.Second > state.MaxStopTimeout) {
		add("defaults", "stop timeout must be between 1s and %s", state.MaxStopTimeout)
	}
	if (hc.Init == nil || !*hc.Init) && !stopTyped {
		add("defaults", "init is required unless the container has a stop signal and timeout")
	}
	lc := hc.LogConfig
	if lc.Type != LogDriver || lc.Config["max-size"] != LogMaxSize || lc.Config["max-file"] != LogMaxFile {
		add("defaults", "log driver must be %s with max-size=%s,max-file=%s", LogDriver, LogMaxSize, LogMaxFile)
	}
	if hc.Memory <= 0 || hc.NanoCPUs <= 0 || hc.PidsLimit == nil || *hc.PidsLimit <= 0 {
		add("defaults", "memory, cpu and pids limits are required")
	}

	// Ports: never on all interfaces.
	if hc.PublishAllPorts {
		add("ports", "publish-all is not allowed")
	}
	for port, bindings := range hc.PortBindings {
		for _, b := range bindings {
			if !b.HostIP.IsValid() || b.HostIP.IsUnspecified() {
				add("ports", "port %s must be bound to a specific host IP", port)
				continue
			}
			// Published ports exist only for cross-server consumers: on the
			// mesh IP, in the mesh-published range (docs/05 §3).
			hp, err := strconv.Atoi(b.HostPort)
			if !MeshRange.Contains(b.HostIP.Unmap()) || err != nil || state.MeshPort(hp).Validate() != nil {
				add("ports", "port %s may only be published on the mesh (%s, %d-%d), got %s:%s", port, MeshRange, state.MeshPortMin, state.MeshPortMax, b.HostIP, b.HostPort)
			}
		}
	}
	return errors.Join(errs...)
}
