// Package config loads the daemon's configuration from environment variables
// and command-line flags (flags win). Secrets — the join token — are read
// only from the environment or a file, never from argv, because argv is
// visible to every process on the host (docs/03 §3).
package config

import (
	"crypto/ed25519"
	"errors"
	"flag"
	"fmt"
	"io"
	"io/fs"
	"log/slog"
	"net"
	"net/netip"
	"net/url"
	"os"
	"path/filepath"
	"regexp"
	"runtime"
	"strconv"
	"strings"

	"github.com/vantezzen/launchroom/daemon/internal/identity"
	"github.com/vantezzen/launchroom/daemon/internal/publicip"
	"github.com/vantezzen/launchroom/daemon/internal/secret"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// Environment variable names.
const (
	EnvControlPlaneURL = "LR_CONTROL_PLANE_URL"
	EnvDataDir         = "LR_DATA_DIR"
	EnvCPKey           = "LR_CP_KEY"
	EnvCPKeyFile       = "LR_CP_KEY_FILE"
	EnvJoinToken       = "LR_JOIN_TOKEN"      //nolint:gosec // env var name, not a credential
	EnvJoinTokenFile   = "LR_JOIN_TOKEN_FILE" //nolint:gosec // env var name, not a credential
	EnvLogLevel        = "LR_LOG_LEVEL"
	// EnvAskAddr is where the daemon answers Caddy's on-demand TLS "ask" (loopback only).
	EnvAskAddr = "LR_INGRESS_ASK_ADDR"
	// EnvAccessLogAddr is where the edge Caddy writes its access log for
	// request metrics (loopback only, docs/15 §1.1).
	EnvAccessLogAddr = "LR_INGRESS_ACCESS_LOG_ADDR"
	// EnvHTTPSRedirectPort is the public HTTPS port used in HTTP→HTTPS redirects
	// (443 in production; the host-mapped port in local dev, e.g. 8443).
	EnvHTTPSRedirectPort = "LR_INGRESS_HTTPS_PORT"
	// EnvRegistryUpstream is where the 127.0.0.1:5000 registry forwarder
	// connects when the NodeState names no mesh address (host:port; dev: zot:5000).
	EnvRegistryUpstream = "LR_REGISTRY_UPSTREAM"
	// EnvBuildSlots bounds concurrent builds/plans (default max(1, cores/4)).
	EnvBuildSlots = "LR_BUILD_SLOTS"
	// EnvInteractiveSessions is the local policy for interactive sessions
	// (docs/03 §11.3): on (default), console, data or off.
	EnvInteractiveSessions = "LR_INTERACTIVE_SESSIONS"
	// EnvBuildSubnet is the build network (default 10.232.0.0/24, docs/05 §7).
	EnvBuildSubnet = "LR_BUILD_SUBNET"
	// EnvInsecureGitHosts lists hosts that may be cloned over plain http (dev fixtures only).
	EnvInsecureGitHosts = "LR_BUILD_INSECURE_GIT_HOSTS"
	// EnvBuildkitMemoryMB and EnvBuildkitCPUs limit the buildkitd container (0 = derived from the host).
	EnvBuildkitMemoryMB = "LR_BUILDKIT_MEMORY_MB"
	EnvBuildkitCPUs     = "LR_BUILDKIT_CPUS"
	// EnvResticBinary overrides where the restic binary is (default
	// /usr/local/bin/restic, else "restic" next to the daemon executable).
	EnvResticBinary = "LR_RESTIC_BINARY"
	// EnvDataTransfers is the local switch for data imports and exports
	// (D55): "on" (default) or "off".
	EnvDataTransfers = "LR_DATA_TRANSFERS"
	// EnvBackupInsecureHosts lists S3 endpoint hosts that may be reached over plain http (dev MinIO only).
	EnvBackupInsecureHosts = "LR_BACKUP_INSECURE_HOSTS"
	// EnvAllowedLocalUpstreams lists ip:port route address upstreams allowed
	// besides the mesh range (docs/03 §8), e.g. the dashboard's
	// 127.0.0.1:8000 on the control-plane host.
	EnvAllowedLocalUpstreams = "LR_ALLOWED_LOCAL_UPSTREAMS"
	// EnvCertStorageAllow lists ip:port addresses of shared certificate
	// storage allowed besides the mesh range (docs/05 §5), e.g. the
	// control plane's Valkey on 127.0.0.1:6379 for its own edge.
	EnvCertStorageAllow = "LR_CERT_STORAGE_ALLOW"
	// EnvSystemImageDigests adds accepted system image digests on top of the
	// ones built into this launchroomd release, as "component=sha256:…"
	// pairs, comma-separated (e.g. a locally built launchroom-caddy in dev).
	// Host config only: the dashboard can never widen the allow-list.
	EnvSystemImageDigests = "LR_SYSTEM_IMAGE_DIGESTS"
	// EnvFirewall is "enforce" (default) or "off" (docs/05 §1): network
	// isolation of workloads and builds with nftables.
	EnvFirewall = "LR_FIREWALL"
	// EnvFirewallAllowPrivate lists private CIDRs (LAN) that workloads and
	// builds may reach, e.g. "192.168.1.0/24". Metadata, the mesh, other
	// environments and the host stay blocked regardless.
	EnvFirewallAllowPrivate = "LR_FIREWALL_ALLOW_PRIVATE"
	// EnvMeshInsecureLoginHosts lists mesh login server hosts that may be
	// reached over plain http (dev only: the dev Headscale on the compose
	// network). Production login servers are https (docs/05 §2).
	EnvMeshInsecureLoginHosts = "LR_MESH_INSECURE_LOGIN_HOSTS"
	// EnvAmbassadorBinary is the launchroom-ambassador executable (default:
	// next to launchroomd's own executable, docs/05 §3).
	EnvAmbassadorBinary = "LR_AMBASSADOR_BIN"
	// EnvAmbassadorSubnet is the ambassador's own network (default 10.232.1.0/24).
	EnvAmbassadorSubnet = "LR_AMBASSADOR_SUBNET"
	// EnvCertStorageUpstream is the local Valkey the control-plane host serves
	// on its mesh IP for the edges' certificate storage (system.certStorage;
	// default 127.0.0.1:6380, where the control-plane compose publishes it).
	EnvCertStorageUpstream = "LR_CERT_STORAGE_UPSTREAM"
	// EnvHostTimezone is the host's IANA time zone, recorded by the installer
	// (launchroomd's container cannot see the host's /etc). Informational:
	// it only suggests the server's location in the dashboard.
	EnvHostTimezone = "LR_HOST_TIMEZONE"
	// EnvHostOS is the host's operating system as the installer saw it
	// ("darwin" on a Mac, where Docker runs in a Linux VM and launchroomd
	// cannot tell by itself; D53). Informational: it shapes the capability
	// matrix and the roles the dashboard offers, never a security check.
	EnvHostOS = "LR_HOST_OS"
	// EnvHostModel is the machine model the installer read on the host
	// (macOS: system_profiler's "Model Name"). Display only (device art).
	EnvHostModel = "LR_HOST_MODEL"
	// EnvLinkProbe is "on" (default) or "off": a local kill switch for the
	// private-network link measurements (docs/05 §2.1). Off, launchroomd
	// neither listens on the probe port nor measures, whatever the NodeState says.
	EnvLinkProbe = "LR_LINK_PROBE"
	// EnvControlPlaneFollow is "on" (default) or "off": whether launchroomd
	// may move to the dashboard's canonical origin from the signed NodeState
	// (system.controlPlane, docs/03 §4.2). Off, it only ever talks to
	// LR_CONTROL_PLANE_URL.
	EnvControlPlaneFollow = "LR_CONTROL_PLANE_FOLLOW"
	// EnvPublicIPDetect is "on" (default) or "off": whether launchroomd asks
	// IP echo services for this server's public address (what DNS records
	// must point at, docs/05 §5). Off mirrors install.sh --no-public-ip-detect.
	EnvPublicIPDetect = "LR_PUBLIC_IP_DETECT"
	// EnvPublicIPs sets the public addresses instead of detecting them:
	// comma-separated, at most one IPv4 and one IPv6, each public.
	EnvPublicIPs = "LR_PUBLIC_IPS"

	// DefaultAskAddr is the default loopback address of the on-demand TLS ask endpoint.
	DefaultAskAddr = "127.0.0.1:2021"
	// DefaultAccessLogAddr is the default loopback address of the access log listener.
	DefaultAccessLogAddr = "127.0.0.1:2022"

	// DefaultDataDir is where ./data/daemon is mounted inside the daemon container.
	DefaultDataDir = "/data"

	maxTokenFileBytes = 4096
	maxKeyFileBytes   = 1024
)

// Config is the validated daemon configuration.
type Config struct {
	// ControlPlaneURL is the dashboard base URL (daemon API lives under /daemon/v1).
	ControlPlaneURL *url.URL
	// DataDir holds keys/, enrollment.json and state.json.
	DataDir string
	// PinnedCPKey is the control-plane signing key from the install command
	// (LR_CP_KEY=ed25519:<base64>). Required to enroll.
	PinnedCPKey ed25519.PublicKey
	// JoinToken is the single-use enrollment token (may be empty once enrolled).
	JoinToken secret.Secret
	LogLevel  slog.Level
	// AskAddr is the loopback ip:port of the on-demand TLS ask endpoint.
	AskAddr netip.AddrPort
	// AccessLogAddr is the loopback ip:port the edge Caddy's access log is read from.
	AccessLogAddr netip.AddrPort
	// HTTPSRedirectPort is the public HTTPS port used in HTTP→HTTPS redirects.
	HTTPSRedirectPort int
	// RegistryUpstream is the registry forwarder target when the NodeState sets none ("" = none).
	RegistryUpstream string
	// BuildSlots bounds concurrent builds and plans.
	BuildSlots int
	// InteractiveSessions is LR_INTERACTIVE_SESSIONS: "on", "console", "data" or "off".
	InteractiveSessions string
	// BuildSubnet is the system build network.
	BuildSubnet netip.Prefix
	// InsecureGitHosts may be cloned over plain http.
	InsecureGitHosts []string
	// BuildkitMemoryMB and BuildkitCPUs limit buildkitd (0 = derived from the host).
	BuildkitMemoryMB int
	BuildkitCPUs     float64
	// ResticBinary is an explicit restic path ("" = default lookup).
	ResticBinary string
	// DataTransfers is LR_DATA_TRANSFERS: data imports and exports are
	// leased (and announced) only when it is true (default).
	DataTransfers bool
	// BackupInsecureHosts may be used as plain-http S3 endpoints.
	BackupInsecureHosts []string
	// AllowedLocalUpstreams are route address upstreams allowed outside the mesh range.
	AllowedLocalUpstreams []netip.AddrPort
	// CertStorageAllow are certificate storage addresses allowed outside the mesh range.
	CertStorageAllow []netip.AddrPort
	// SystemImageDigests are extra accepted system image digests per component.
	SystemImageDigests map[string][]string
	// Firewall is "enforce" or "off".
	Firewall string
	// FirewallAllowPrivate are private destinations containers may reach.
	FirewallAllowPrivate []netip.Prefix
	// MeshInsecureLoginHosts may serve as plain-http mesh login servers (dev only).
	MeshInsecureLoginHosts []string
	// AmbassadorBinary is the forwarder binary the ambassador image is built from.
	AmbassadorBinary string
	// AmbassadorSubnet is the ambassador's own network.
	AmbassadorSubnet netip.Prefix
	// CertStorageUpstream is the local Valkey (host:port) served on the mesh
	// IP when the NodeState asks for it (control-plane host).
	CertStorageUpstream string
	// HostTimezone is $LR_HOST_TIMEZONE as given (validated where it is used).
	HostTimezone string
	// HostOS is $LR_HOST_OS when it names a known OS ("" otherwise: Linux).
	HostOS state.HostOS
	// HostModel is $LR_HOST_MODEL as given (sanitized where it is used).
	HostModel string
	// LinkProbeOff is the local kill switch for link measurements (LR_LINK_PROBE=off).
	LinkProbeOff bool
	// ControlPlaneFollowOff refuses system.controlPlane (LR_CONTROL_PLANE_FOLLOW=off).
	ControlPlaneFollowOff bool
	// PublicIPDetectOff never contacts IP echo services (LR_PUBLIC_IP_DETECT=off).
	PublicIPDetectOff bool
	// PublicIPs are the configured public addresses (LR_PUBLIC_IPS), nil = detect.
	PublicIPs *state.PublicAddresses
}

// DefaultCertStorageUpstream is where the control-plane compose publishes Valkey for launchroomd.
const DefaultCertStorageUpstream = "127.0.0.1:6380"

// KeysDir is where the daemon's key files live.
func (c Config) KeysDir() string { return filepath.Join(c.DataDir, "keys") }

// Lookup reads an environment variable (os.LookupEnv in production).
type Lookup func(key string) (string, bool)

// Load parses args (without the program name) and the environment.
// readFile reads LR_JOIN_TOKEN_FILE (os.ReadFile in production).
func Load(args []string, env Lookup, readFile func(string) ([]byte, error)) (Config, error) {
	get := func(k string) string {
		v, _ := env(k)
		return strings.TrimSpace(v)
	}

	fs := flag.NewFlagSet("launchroomd", flag.ContinueOnError)
	fs.SetOutput(io.Discard)
	cpURL := fs.String("control-plane-url", get(EnvControlPlaneURL), "dashboard base URL ($"+EnvControlPlaneURL+")")
	dataDir := fs.String("data-dir", orDefault(get(EnvDataDir), DefaultDataDir), "data directory ($"+EnvDataDir+")")
	cpKey := fs.String("cp-key", get(EnvCPKey), "pinned control-plane signing key ed25519:<base64> ($"+EnvCPKey+")")
	cpKeyFile := fs.String("cp-key-file", get(EnvCPKeyFile), "file containing the pinned control-plane key ($"+EnvCPKeyFile+")")
	accessLogAddr := fs.String("ingress-access-log-addr", orDefault(get(EnvAccessLogAddr), DefaultAccessLogAddr), "loopback ip:port for Caddy's access log, read for request metrics ($"+EnvAccessLogAddr+")")
	askAddr := fs.String("ingress-ask-addr", orDefault(get(EnvAskAddr), DefaultAskAddr), "loopback ip:port for Caddy's on-demand TLS ask ($"+EnvAskAddr+")")
	httpsPort := fs.String("ingress-https-port", orDefault(get(EnvHTTPSRedirectPort), "443"), "public HTTPS port for redirects ($"+EnvHTTPSRedirectPort+")")
	logLevel := fs.String("log-level", orDefault(get(EnvLogLevel), "info"), "debug|info|warn|error ($"+EnvLogLevel+")")
	if err := fs.Parse(args); err != nil {
		return Config{}, fmt.Errorf("config: %w (the join token is only accepted via $%s or $%s)", err, EnvJoinToken, EnvJoinTokenFile)
	}
	if fs.NArg() > 0 {
		return Config{}, fmt.Errorf("config: unexpected arguments %q", fs.Args())
	}

	var cfg Config
	var errs []error

	u, err := parseControlPlaneURL(*cpURL)
	if err != nil {
		errs = append(errs, err)
	}
	cfg.ControlPlaneURL = u

	if *dataDir == "" || !filepath.IsAbs(*dataDir) {
		errs = append(errs, fmt.Errorf("config: data dir must be an absolute path, got %q", *dataDir))
	}
	cfg.DataDir = filepath.Clean(*dataDir)

	keyText, keySource, err := loadCPKey(*cpKey, *cpKeyFile, readFile)
	if err != nil {
		errs = append(errs, err)
	}
	if keyText != "" {
		k, err := identity.ParsePublicKey(keyText)
		if err != nil {
			errs = append(errs, fmt.Errorf("config: %s: %w", keySource, err))
		}
		cfg.PinnedCPKey = k
	}

	ap, err := netip.ParseAddrPort(*askAddr)
	if err != nil || !ap.Addr().IsLoopback() || ap.Port() == 0 {
		errs = append(errs, fmt.Errorf("config: %s must be a loopback ip:port, got %q", EnvAskAddr, *askAddr))
	}
	cfg.AskAddr = ap
	lap, err := netip.ParseAddrPort(*accessLogAddr)
	if err != nil || !lap.Addr().IsLoopback() || lap.Port() == 0 {
		errs = append(errs, fmt.Errorf("config: %s must be a loopback ip:port, got %q", EnvAccessLogAddr, *accessLogAddr))
	}
	cfg.AccessLogAddr = lap
	port, err := strconv.Atoi(*httpsPort)
	if err != nil || port < 1 || port > 65535 {
		errs = append(errs, fmt.Errorf("config: %s must be a port number, got %q", EnvHTTPSRedirectPort, *httpsPort))
	}
	cfg.HTTPSRedirectPort = port

	buildErrs := loadBuildConfig(&cfg, get)
	errs = append(errs, buildErrs...)
	ups, err := parseAddrPorts(EnvAllowedLocalUpstreams, get(EnvAllowedLocalUpstreams))
	if err != nil {
		errs = append(errs, err)
	}
	cfg.AllowedLocalUpstreams = ups
	storageAllow, err := parseAddrPorts(EnvCertStorageAllow, get(EnvCertStorageAllow))
	if err != nil {
		errs = append(errs, err)
	}
	cfg.CertStorageAllow = storageAllow
	digests, err := parseSystemImageDigests(get(EnvSystemImageDigests))
	if err != nil {
		errs = append(errs, err)
	}
	cfg.SystemImageDigests = digests

	errs = append(errs, loadFirewallConfig(&cfg, get)...)
	cfg.HostTimezone = strings.TrimSpace(get(EnvHostTimezone))
	if hostOS := state.HostOS(strings.ToLower(strings.TrimSpace(get(EnvHostOS)))); hostOS.Validate() == nil {
		cfg.HostOS = hostOS
	}
	cfg.HostModel = strings.TrimSpace(get(EnvHostModel))

	tok, err := loadJoinToken(env, readFile)
	if err != nil {
		errs = append(errs, err)
	}
	cfg.JoinToken = tok
	if !tok.IsZero() && cfg.PinnedCPKey == nil {
		errs = append(errs, fmt.Errorf("config: a join token requires %s or %s (the pinned control-plane key from the install command)", EnvCPKey, EnvCPKeyFile))
	}

	if err := cfg.LogLevel.UnmarshalText([]byte(*logLevel)); err != nil {
		errs = append(errs, fmt.Errorf("config: invalid log level %q", *logLevel))
	}
	if err := errors.Join(errs...); err != nil {
		return Config{}, err
	}
	return cfg, nil
}

func loadFirewallConfig(cfg *Config, get func(string) string) []error {
	var errs []error
	switch v := strings.ToLower(get(EnvLinkProbe)); v {
	case "", "on":
	case "off":
		cfg.LinkProbeOff = true
	default:
		errs = append(errs, fmt.Errorf("config: %s must be on or off, got %q", EnvLinkProbe, v))
	}
	switch v := strings.ToLower(get(EnvControlPlaneFollow)); v {
	case "", "on":
	case "off":
		cfg.ControlPlaneFollowOff = true
	default:
		errs = append(errs, fmt.Errorf("config: %s must be on or off, got %q", EnvControlPlaneFollow, v))
	}
	switch v := strings.ToLower(get(EnvPublicIPDetect)); v {
	case "", "on":
	case "off":
		cfg.PublicIPDetectOff = true
	default:
		errs = append(errs, fmt.Errorf("config: %s must be on or off, got %q", EnvPublicIPDetect, v))
	}
	if ips, err := publicip.ParseConfigured(get(EnvPublicIPs)); err != nil {
		errs = append(errs, fmt.Errorf("config: %s: %w", EnvPublicIPs, err))
	} else {
		cfg.PublicIPs = ips
	}
	cfg.Firewall = orDefault(strings.ToLower(get(EnvFirewall)), "enforce")
	if cfg.Firewall != "enforce" && cfg.Firewall != "off" {
		errs = append(errs, fmt.Errorf("config: %s must be enforce or off, got %q", EnvFirewall, cfg.Firewall))
	}
	for _, item := range strings.Split(get(EnvFirewallAllowPrivate), ",") {
		if item = strings.TrimSpace(item); item == "" {
			continue
		}
		p, err := netip.ParsePrefix(item)
		if err != nil || p.Masked() != p || p.Bits() == 0 {
			errs = append(errs, fmt.Errorf("config: %s must be a comma-separated list of canonical CIDRs (not 0.0.0.0/0), got %q", EnvFirewallAllowPrivate, item))
			continue
		}
		cfg.FirewallAllowPrivate = append(cfg.FirewallAllowPrivate, p)
	}
	return errs
}

// parseAddrPorts parses a comma-separated list of ip:port (no host names:
// the allow-list must not depend on DNS).
func parseAddrPorts(name, v string) ([]netip.AddrPort, error) {
	var out []netip.AddrPort
	for _, item := range strings.Split(v, ",") {
		if item = strings.TrimSpace(item); item == "" {
			continue
		}
		ap, err := netip.ParseAddrPort(item)
		if err != nil || ap.Port() == 0 || ap.Addr().Zone() != "" || ap.Addr().IsUnspecified() {
			return nil, fmt.Errorf("config: %s must be a comma-separated list of ip:port, got %q", name, item)
		}
		out = append(out, netip.AddrPortFrom(ap.Addr().Unmap(), ap.Port()))
	}
	return out, nil
}

var reDigest = regexp.MustCompile(`^sha256:[a-f0-9]{64}$`)

// ParseSystemImageDigests parses LR_SYSTEM_IMAGE_DIGESTS (also read by dockerd-guard).
func ParseSystemImageDigests(v string) (map[string][]string, error) {
	return parseSystemImageDigests(v)
}

// parseSystemImageDigests parses "component=sha256:…,…" (components: caddy, buildkit).
func parseSystemImageDigests(v string) (map[string][]string, error) {
	var out map[string][]string
	for _, item := range strings.Split(v, ",") {
		if item = strings.TrimSpace(item); item == "" {
			continue
		}
		comp, digest, ok := strings.Cut(item, "=")
		if !ok || (comp != "caddy" && comp != "buildkit") || !reDigest.MatchString(digest) {
			return nil, fmt.Errorf("config: %s must be a comma-separated list of caddy=sha256:<64 hex> or buildkit=sha256:<64 hex>, got %q", EnvSystemImageDigests, item)
		}
		if out == nil {
			out = map[string][]string{}
		}
		out[comp] = append(out[comp], digest)
	}
	return out, nil
}

func orDefault(v, def string) string {
	if v == "" {
		return def
	}
	return v
}

func parseControlPlaneURL(s string) (*url.URL, error) {
	if s == "" {
		return nil, fmt.Errorf("config: control-plane URL is required (--control-plane-url or $%s)", EnvControlPlaneURL)
	}
	u, err := url.Parse(s)
	if err != nil {
		return nil, fmt.Errorf("config: control-plane URL: %w", err)
	}
	switch {
	case u.Scheme != "https" && u.Scheme != "http":
		// http is allowed for local dev and the mesh; every payload is signed regardless (docs/03 §4.1).
		return nil, fmt.Errorf("config: control-plane URL must be http(s), got %q", u.Scheme)
	case u.Host == "":
		return nil, errors.New("config: control-plane URL has no host")
	case u.User != nil:
		return nil, errors.New("config: control-plane URL must not contain credentials")
	case u.RawQuery != "" || u.Fragment != "":
		return nil, errors.New("config: control-plane URL must not contain a query or fragment")
	}
	u.Path = strings.TrimSuffix(u.Path, "/")
	return u, nil
}

// loadCPKey returns the pinned key text from the flag/env value and/or the
// key file. A key file that does not exist (yet) counts as unset: the key is
// only needed to enroll, and enrolling without it fails with a clear error.
// If both are set they must agree.
func loadCPKey(direct, file string, readFile func(string) ([]byte, error)) (key, source string, err error) {
	if file == "" {
		if direct == "" {
			return "", "", nil
		}
		return direct, EnvCPKey, nil
	}
	if !filepath.IsAbs(file) {
		return "", "", fmt.Errorf("config: %s must be an absolute path", EnvCPKeyFile)
	}
	data, err := readFile(file)
	switch {
	case errors.Is(err, fs.ErrNotExist):
		if direct != "" {
			return direct, EnvCPKey, nil
		}
		return "", "", nil
	case err != nil:
		return "", "", fmt.Errorf("config: read %s: %w", EnvCPKeyFile, err)
	case len(data) > maxKeyFileBytes:
		return "", "", fmt.Errorf("config: %s is larger than %d bytes", EnvCPKeyFile, maxKeyFileBytes)
	}
	fromFile := strings.TrimSpace(string(data))
	if direct != "" && direct != fromFile {
		// Two different pinned keys: refuse rather than guess (fail closed).
		return "", "", fmt.Errorf("config: %s and %s contain different keys", EnvCPKey, EnvCPKeyFile)
	}
	return fromFile, EnvCPKeyFile, nil
}

func loadJoinToken(env Lookup, readFile func(string) ([]byte, error)) (secret.Secret, error) {
	direct, hasDirect := env(EnvJoinToken)
	file, hasFile := env(EnvJoinTokenFile)
	direct, file = strings.TrimSpace(direct), strings.TrimSpace(file)
	if direct != "" && file != "" {
		return "", fmt.Errorf("config: set only one of %s and %s", EnvJoinToken, EnvJoinTokenFile)
	}
	var tok string
	switch {
	case hasDirect && direct != "":
		tok = direct
	case hasFile && file != "":
		if !filepath.IsAbs(file) {
			return "", fmt.Errorf("config: %s must be an absolute path", EnvJoinTokenFile)
		}
		data, err := readFile(file)
		if err != nil {
			return "", fmt.Errorf("config: read %s: %w", EnvJoinTokenFile, err)
		}
		if len(data) > maxTokenFileBytes {
			return "", fmt.Errorf("config: %s is larger than %d bytes", EnvJoinTokenFile, maxTokenFileBytes)
		}
		tok = strings.TrimSpace(string(data))
	default:
		return "", nil
	}
	if !state.ValidJoinToken(tok) {
		// Never echo the token itself.
		return "", errors.New("config: join token has an invalid format")
	}
	return secret.Secret(tok), nil
}

// FromProcess loads the configuration of the running process.
func FromProcess() (Config, error) {
	return Load(os.Args[1:], os.LookupEnv, os.ReadFile)
}

// loadBuildConfig reads the registry forwarder and build settings.
func loadBuildConfig(cfg *Config, get func(string) string) []error {
	var errs []error
	if up := get(EnvRegistryUpstream); up != "" {
		host, port, err := net.SplitHostPort(up)
		if p, perr := strconv.Atoi(port); err != nil || host == "" || perr != nil || p < 1 || p > 65535 {
			errs = append(errs, fmt.Errorf("config: %s must be host:port, got %q", EnvRegistryUpstream, up))
		}
		cfg.RegistryUpstream = up
	}
	cfg.InteractiveSessions = "on"
	if v := strings.ToLower(strings.TrimSpace(get(EnvInteractiveSessions))); v != "" {
		switch v {
		case "on", "console", "data", "off":
			cfg.InteractiveSessions = v
		default:
			errs = append(errs, fmt.Errorf("config: %s must be on, console, data or off, got %q", EnvInteractiveSessions, v))
		}
	}
	cfg.DataTransfers = true
	if v := strings.ToLower(strings.TrimSpace(get(EnvDataTransfers))); v != "" {
		switch v {
		case "on":
		case "off":
			cfg.DataTransfers = false
		default:
			errs = append(errs, fmt.Errorf("config: %s must be on or off, got %q", EnvDataTransfers, v))
		}
	}
	cfg.BuildSlots = max(1, runtime.NumCPU()/4)
	if v := get(EnvBuildSlots); v != "" {
		n, err := strconv.Atoi(v)
		if err != nil || n < 1 || n > 64 {
			errs = append(errs, fmt.Errorf("config: %s must be between 1 and 64, got %q", EnvBuildSlots, v))
		}
		cfg.BuildSlots = n
	}
	cfg.BuildSubnet = netip.MustParsePrefix("10.232.0.0/24")
	if v := get(EnvBuildSubnet); v != "" {
		p, err := netip.ParsePrefix(v)
		if err != nil || !p.Addr().Is4() || p.Bits() > 29 || p.Masked() != p {
			errs = append(errs, fmt.Errorf("config: %s must be a canonical IPv4 CIDR of at least /29, got %q", EnvBuildSubnet, v))
		}
		cfg.BuildSubnet = p
	}
	cfg.AmbassadorSubnet = netip.MustParsePrefix("10.232.1.0/24")
	if v := get(EnvAmbassadorSubnet); v != "" {
		p, err := netip.ParsePrefix(v)
		if err != nil || !p.Addr().Is4() || p.Bits() > 29 || p.Masked() != p {
			errs = append(errs, fmt.Errorf("config: %s must be a canonical IPv4 CIDR of at least /29, got %q", EnvAmbassadorSubnet, v))
		}
		cfg.AmbassadorSubnet = p
	}
	if cfg.AmbassadorSubnet.Overlaps(cfg.BuildSubnet) {
		errs = append(errs, fmt.Errorf("config: %s overlaps %s", EnvAmbassadorSubnet, EnvBuildSubnet))
	}
	if v := get(EnvAmbassadorBinary); v != "" {
		if !filepath.IsAbs(v) {
			errs = append(errs, fmt.Errorf("config: %s must be an absolute path", EnvAmbassadorBinary))
		}
		cfg.AmbassadorBinary = v
	} else if exe, err := os.Executable(); err == nil {
		cfg.AmbassadorBinary = filepath.Join(filepath.Dir(exe), "launchroom-ambassador")
	}
	cfg.CertStorageUpstream = DefaultCertStorageUpstream
	if up := get(EnvCertStorageUpstream); up != "" {
		host, port, err := net.SplitHostPort(up)
		if p, perr := strconv.Atoi(port); err != nil || host == "" || perr != nil || p < 1 || p > 65535 {
			errs = append(errs, fmt.Errorf("config: %s must be host:port, got %q", EnvCertStorageUpstream, up))
		}
		cfg.CertStorageUpstream = up
	}
	if v := get(EnvResticBinary); v != "" {
		if !filepath.IsAbs(v) {
			errs = append(errs, fmt.Errorf("config: %s must be an absolute path", EnvResticBinary))
		}
		cfg.ResticBinary = v
	}
	for _, h := range strings.Split(get(EnvBackupInsecureHosts), ",") {
		if h = strings.TrimSpace(strings.ToLower(h)); h != "" {
			cfg.BackupInsecureHosts = append(cfg.BackupInsecureHosts, h)
		}
	}
	for _, h := range strings.Split(get(EnvMeshInsecureLoginHosts), ",") {
		if h = strings.TrimSpace(strings.ToLower(h)); h != "" {
			cfg.MeshInsecureLoginHosts = append(cfg.MeshInsecureLoginHosts, h)
		}
	}
	for _, h := range strings.Split(get(EnvInsecureGitHosts), ",") {
		if h = strings.TrimSpace(strings.ToLower(h)); h != "" {
			cfg.InsecureGitHosts = append(cfg.InsecureGitHosts, h)
		}
	}
	if v := get(EnvBuildkitMemoryMB); v != "" {
		n, err := strconv.Atoi(v)
		if err != nil || n < 0 {
			errs = append(errs, fmt.Errorf("config: %s must be a non-negative integer", EnvBuildkitMemoryMB))
		}
		cfg.BuildkitMemoryMB = n
	}
	if v := get(EnvBuildkitCPUs); v != "" {
		f, err := strconv.ParseFloat(v, 64)
		if err != nil || f < 0 || f > 1024 {
			errs = append(errs, fmt.Errorf("config: %s must be between 0 and 1024", EnvBuildkitCPUs))
		}
		cfg.BuildkitCPUs = f
	}
	return errs
}

// SessionAllowed reports whether the local policy (LR_INTERACTIVE_SESSIONS)
// allows interactive sessions of kind ("console" or "data").
func (c Config) SessionAllowed(kind string) bool {
	switch c.InteractiveSessions {
	case "", "on":
		return true
	case "off":
		return false
	default:
		return c.InteractiveSessions == kind
	}
}
