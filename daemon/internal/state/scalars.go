package state

import (
	"errors"
	"fmt"
	"net/netip"
	"net/url"
	"path"
	"regexp"
	"slices"
	"strconv"
	"strings"
	"time"
)

// ScalarSchema describes the JSON Schema of a protocol scalar type. cmd/schemagen
// turns it into the generated schema, so Go validation and the schema share
// one source of truth (docs/09-security.md R3: every identifier has a grammar).
type ScalarSchema struct {
	Type      string // "string" or "integer"
	Pattern   string
	Enum      []string
	Minimum   *int64
	Maximum   *int64
	MinLength int
	MaxLength int
	Format    string
}

// SchemaDescriber is implemented by every protocol scalar type.
type SchemaDescriber interface {
	ProtocolSchema() ScalarSchema
}

func ptr[T any](v T) *T { return &v }

func strSchema(re *regexp.Regexp, maxLen int) ScalarSchema {
	return ScalarSchema{Type: "string", Pattern: re.String(), MaxLength: maxLen}
}

func enumSchema(values []string) ScalarSchema {
	return ScalarSchema{Type: "string", Enum: slices.Clone(values)}
}

func checkPattern(kind, v string, re *regexp.Regexp, maxLen int) error {
	if maxLen > 0 && len(v) > maxLen {
		return fmt.Errorf("%s is longer than %d bytes", kind, maxLen)
	}
	if !re.MatchString(v) {
		return fmt.Errorf("%s %q does not match %s", kind, truncate(v), re.String())
	}
	return nil
}

func checkEnum(kind, v string, values []string) error {
	if !slices.Contains(values, v) {
		return fmt.Errorf("%s %q is not one of %s", kind, truncate(v), strings.Join(values, ", "))
	}
	return nil
}

func truncate(s string) string {
	const n = 80
	if len(s) <= n {
		return s
	}
	return s[:n] + "…"
}

// ---- Public IDs: "<prefix>_<ULID>" (docs/07-data-model.md) ----

const ulid = `[0-9A-HJKMNP-TV-Z]{26}`

func idRegexp(prefix string) *regexp.Regexp {
	return regexp.MustCompile(`^` + prefix + `_` + ulid + `$`)
}

var (
	reServerID      = idRegexp("srv")
	reNetworkID     = idRegexp("net")
	reVolumeID      = idRegexp("vol")
	reWorkloadID    = idRegexp("wl")
	reServiceID     = idRegexp("svc")
	reEnvironmentID = idRegexp("env")
	reDeploymentID  = idRegexp("dpl")
	reRouteID       = idRegexp("rt")
	reTaskID        = idRegexp("tsk")
)

// ServerID is a server's public id, e.g. "srv_01J9Z8X7W6V5T4S3R2Q1P0N9MK".
type ServerID string

// ProtocolSchema implements SchemaDescriber.
func (ServerID) ProtocolSchema() ScalarSchema { return strSchema(reServerID, 0) }

// Validate checks the grammar.
func (v ServerID) Validate() error { return checkPattern("server id", string(v), reServerID, 0) }

// NetworkID is a launchroom network id ("net_…").
type NetworkID string

// ProtocolSchema implements SchemaDescriber.
func (NetworkID) ProtocolSchema() ScalarSchema { return strSchema(reNetworkID, 0) }

// Validate checks the grammar.
func (v NetworkID) Validate() error { return checkPattern("network id", string(v), reNetworkID, 0) }

// VolumeID is a launchroom volume id ("vol_…").
type VolumeID string

// ProtocolSchema implements SchemaDescriber.
func (VolumeID) ProtocolSchema() ScalarSchema { return strSchema(reVolumeID, 0) }

// Validate checks the grammar.
func (v VolumeID) Validate() error { return checkPattern("volume id", string(v), reVolumeID, 0) }

// WorkloadID is a workload id ("wl_…"), stable per (service instance, replica slot, deployment).
type WorkloadID string

// ProtocolSchema implements SchemaDescriber.
func (WorkloadID) ProtocolSchema() ScalarSchema { return strSchema(reWorkloadID, 0) }

// Validate checks the grammar.
func (v WorkloadID) Validate() error { return checkPattern("workload id", string(v), reWorkloadID, 0) }

// ServiceID is a service id ("svc_…").
type ServiceID string

// ProtocolSchema implements SchemaDescriber.
func (ServiceID) ProtocolSchema() ScalarSchema { return strSchema(reServiceID, 0) }

// Validate checks the grammar.
func (v ServiceID) Validate() error { return checkPattern("service id", string(v), reServiceID, 0) }

// EnvironmentID is an environment id ("env_…").
type EnvironmentID string

// ProtocolSchema implements SchemaDescriber.
func (EnvironmentID) ProtocolSchema() ScalarSchema { return strSchema(reEnvironmentID, 0) }

// Validate checks the grammar.
func (v EnvironmentID) Validate() error {
	return checkPattern("environment id", string(v), reEnvironmentID, 0)
}

// DeploymentID is a deployment id ("dpl_…").
type DeploymentID string

// ProtocolSchema implements SchemaDescriber.
func (DeploymentID) ProtocolSchema() ScalarSchema { return strSchema(reDeploymentID, 0) }

// Validate checks the grammar.
func (v DeploymentID) Validate() error {
	return checkPattern("deployment id", string(v), reDeploymentID, 0)
}

// RouteID is an ingress route id ("rt_…").
type RouteID string

// ProtocolSchema implements SchemaDescriber.
func (RouteID) ProtocolSchema() ScalarSchema { return strSchema(reRouteID, 0) }

// Validate checks the grammar.
func (v RouteID) Validate() error { return checkPattern("route id", string(v), reRouteID, 0) }

// TaskID is a task id ("tsk_…").
type TaskID string

// ProtocolSchema implements SchemaDescriber.
func (TaskID) ProtocolSchema() ScalarSchema { return strSchema(reTaskID, 0) }

// Validate checks the grammar.
func (v TaskID) Validate() error { return checkPattern("task id", string(v), reTaskID, 0) }

// ---- Names ----

var (
	reSlug     = regexp.MustCompile(`^[a-z0-9][a-z0-9-]{0,62}$`)
	reHostName = regexp.MustCompile(`^lr-[a-z0-9][a-z0-9_-]{0,59}$`)
)

// Slug is a DNS-label-like name used for network aliases (service slugs such as "postgres").
type Slug string

// ProtocolSchema implements SchemaDescriber.
func (Slug) ProtocolSchema() ScalarSchema { return strSchema(reSlug, 0) }

// Validate checks the grammar.
func (v Slug) Validate() error { return checkPattern("slug", string(v), reSlug, 0) }

// HostName is a generated host-side Docker object name ("lr-e_7f3c…"). It is
// derived from ids by the dashboard, never from user input (docs/09 R3).
type HostName string

// ProtocolSchema implements SchemaDescriber.
func (HostName) ProtocolSchema() ScalarSchema { return strSchema(reHostName, 0) }

// Validate checks the grammar.
func (v HostName) Validate() error { return checkPattern("host-side name", string(v), reHostName, 0) }

// ---- Images ----

const (
	registryHost = `(?:localhost|[a-z0-9-]+(?:\.[a-z0-9-]+)+|[a-z0-9-]+(?:\.[a-z0-9-]+)*:[0-9]{1,5})`
	repoPath     = `(?:/[a-z0-9]+(?:(?:\.|_|__|-+)[a-z0-9]+)*)+`
)

var (
	reImageRef  = regexp.MustCompile(`^` + registryHost + repoPath + `@sha256:[a-f0-9]{64}$`)
	reImageRepo = regexp.MustCompile(`^` + registryHost + repoPath + `$`)
)

// ImageRef is a digest-pinned image reference with an explicit registry,
// e.g. "127.0.0.1:5000/apps/svc_01j…@sha256:<64 hex>". Tags are not allowed.
type ImageRef string

// ProtocolSchema implements SchemaDescriber.
func (ImageRef) ProtocolSchema() ScalarSchema { return strSchema(reImageRef, 512) }

// Validate checks the grammar.
func (v ImageRef) Validate() error {
	return checkPattern("image reference", string(v), reImageRef, 512)
}

// Repository returns the reference without the digest.
func (v ImageRef) Repository() string {
	repo, _, _ := strings.Cut(string(v), "@")
	return repo
}

// ImageRepository is an image repository without tag or digest (a push target).
type ImageRepository string

// ProtocolSchema implements SchemaDescriber.
func (ImageRepository) ProtocolSchema() ScalarSchema { return strSchema(reImageRepo, 256) }

// Validate checks the grammar.
func (v ImageRepository) Validate() error {
	return checkPattern("image repository", string(v), reImageRepo, 256)
}

// ---- Paths ----

var (
	reContainerPath = regexp.MustCompile(`^/[A-Za-z0-9._@+=,-]+(?:/[A-Za-z0-9._@+=,-]+)*$`)
	reRelPath       = regexp.MustCompile(`^[A-Za-z0-9._@+=,-]+(?:/[A-Za-z0-9._@+=,-]+)*$`)
	reHTTPPath      = regexp.MustCompile(`^/[A-Za-z0-9._~!$&'()*+,;=:@%/?-]*$`)
)

// forbiddenContainerPaths may not be mount targets (docs/09 R4).
var forbiddenContainerPaths = []string{"/proc", "/sys", "/dev", "/var/run/docker.sock", "/run/docker.sock"}

// ContainerPath is an absolute, clean path inside a container (mount target).
// Besides the pattern it must not contain "." or ".." segments and must not be
// (inside) /proc, /sys, /dev or the Docker socket.
type ContainerPath string

// ProtocolSchema implements SchemaDescriber.
func (ContainerPath) ProtocolSchema() ScalarSchema { return strSchema(reContainerPath, 1024) }

// Validate checks the grammar and the forbidden locations.
func (v ContainerPath) Validate() error {
	s := string(v)
	if err := checkPattern("container path", s, reContainerPath, 1024); err != nil {
		return err
	}
	if path.Clean(s) != s || hasDotSegment(s) {
		return fmt.Errorf("container path %q must be clean (no . or .. segments)", s)
	}
	for _, f := range forbiddenContainerPaths {
		if s == f || strings.HasPrefix(s, f+"/") {
			return fmt.Errorf("container path %q is not allowed (inside %s)", s, f)
		}
	}
	return nil
}

func hasDotSegment(p string) bool {
	return slices.ContainsFunc(strings.Split(p, "/"), func(seg string) bool { return seg == "." || seg == ".." })
}

// RelPath is a clean repository-relative path (root dir, Dockerfile path).
// Containment after symlink resolution is checked where the path is used (docs/09 R4).
type RelPath string

// ProtocolSchema implements SchemaDescriber.
func (RelPath) ProtocolSchema() ScalarSchema { return strSchema(reRelPath, 1024) }

// Validate checks the grammar and rejects "." / ".." segments.
func (v RelPath) Validate() error {
	s := string(v)
	if err := checkPattern("relative path", s, reRelPath, 1024); err != nil {
		return err
	}
	if hasDotSegment(s) {
		return fmt.Errorf("relative path %q must not contain . or .. segments", s)
	}
	return nil
}

// HTTPPath is an absolute HTTP request path (with optional query) for health probes.
type HTTPPath string

// ProtocolSchema implements SchemaDescriber.
func (HTTPPath) ProtocolSchema() ScalarSchema { return strSchema(reHTTPPath, 1024) }

// Validate checks the grammar.
func (v HTTPPath) Validate() error { return checkPattern("http path", string(v), reHTTPPath, 1024) }

// ---- Network values ----

var (
	reHostname = regexp.MustCompile(`^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$`)
	reAddrPort = regexp.MustCompile(`^(?:[0-9]{1,3}(?:\.[0-9]{1,3}){3}|\[[0-9a-f:.]{2,45}\]):[0-9]{1,5}$`)
	reIPAddr   = regexp.MustCompile(`^(?:[0-9]{1,3}(?:\.[0-9]{1,3}){3}|[0-9a-f:.]{2,45})$`)
	reCIDR     = regexp.MustCompile(`^[0-9]{1,3}(?:\.[0-9]{1,3}){3}/[0-9]{1,2}$`)
	reHTTPSURL = regexp.MustCompile(`^https://[a-z0-9.-]+(?::[0-9]{1,5})?(?:/[A-Za-z0-9._~!$&'()*+,;=:@%/-]*)?$`)
)

// Hostname is a lowercase RFC 1123 DNS name with at least two labels (IDNs as punycode).
type Hostname string

// ProtocolSchema implements SchemaDescriber.
func (Hostname) ProtocolSchema() ScalarSchema { return strSchema(reHostname, 253) }

// Validate checks the grammar.
func (v Hostname) Validate() error { return checkPattern("hostname", string(v), reHostname, 253) }

// AddrPort is "ip:port" (IPv6 in brackets), e.g. a container IP or a mesh-published port.
type AddrPort string

// ProtocolSchema implements SchemaDescriber.
func (AddrPort) ProtocolSchema() ScalarSchema { return strSchema(reAddrPort, 0) }

// Validate checks the grammar and that it parses with a non-zero port.
func (v AddrPort) Validate() error {
	if err := checkPattern("address", string(v), reAddrPort, 0); err != nil {
		return err
	}
	ap, err := netip.ParseAddrPort(string(v))
	if err != nil || ap.Port() == 0 || ap.Addr().Zone() != "" {
		return fmt.Errorf("address %q is not a valid ip:port", truncate(string(v)))
	}
	return nil
}

// Parse returns the parsed address; call Validate first.
func (v AddrPort) Parse() (netip.AddrPort, error) { return netip.ParseAddrPort(string(v)) }

// IPAddr is a single IPv4 or IPv6 address (canonical form).
type IPAddr string

// ProtocolSchema implements SchemaDescriber.
func (IPAddr) ProtocolSchema() ScalarSchema { return strSchema(reIPAddr, 0) }

// Validate checks the grammar and that it parses in canonical form.
func (v IPAddr) Validate() error {
	if err := checkPattern("ip address", string(v), reIPAddr, 0); err != nil {
		return err
	}
	a, err := netip.ParseAddr(string(v))
	if err != nil || a.Zone() != "" || a.String() != string(v) {
		return fmt.Errorf("ip address %q is not a canonical IP", truncate(string(v)))
	}
	return nil
}

// CIDR is an IPv4 network prefix in canonical form (e.g. "10.231.4.0/24").
type CIDR string

// ProtocolSchema implements SchemaDescriber.
func (CIDR) ProtocolSchema() ScalarSchema { return strSchema(reCIDR, 0) }

// Validate checks the grammar and that the prefix is canonical (host bits zero).
func (v CIDR) Validate() error {
	if err := checkPattern("subnet", string(v), reCIDR, 0); err != nil {
		return err
	}
	p, err := netip.ParsePrefix(string(v))
	if err != nil || p.Masked() != p || p.Bits() < 8 || p.Bits() > 30 {
		return fmt.Errorf("subnet %q is not a canonical IPv4 prefix between /8 and /30", string(v))
	}
	return nil
}

// HTTPSURL is an https URL without credentials, query or fragment.
type HTTPSURL string

// ProtocolSchema implements SchemaDescriber.
func (HTTPSURL) ProtocolSchema() ScalarSchema { return strSchema(reHTTPSURL, 2048) }

// Validate checks the grammar and parses the URL.
func (v HTTPSURL) Validate() error {
	if err := checkPattern("https url", string(v), reHTTPSURL, 2048); err != nil {
		return err
	}
	u, err := url.Parse(string(v))
	if err != nil || u.Scheme != "https" || u.Host == "" || u.User != nil {
		return fmt.Errorf("https url %q is invalid", truncate(string(v)))
	}
	return nil
}

var reMeshLoginURL = regexp.MustCompile(`^https?://[a-z0-9.-]+(?::[0-9]{1,5})?$`)

// MeshLoginURL is the mesh coordination server (Headscale or Tailscale):
// http(s)://host[:port], no path. Plain http passes the grammar but is refused
// by launchroomd's local policy unless the host is listed in its local config
// (dev only, docs/05 §2).
type MeshLoginURL string

// ProtocolSchema implements SchemaDescriber.
func (MeshLoginURL) ProtocolSchema() ScalarSchema { return strSchema(reMeshLoginURL, 512) }

// Validate checks the grammar and parses the URL.
func (v MeshLoginURL) Validate() error {
	if err := checkPattern("mesh login url", string(v), reMeshLoginURL, 512); err != nil {
		return err
	}
	u, err := url.Parse(string(v))
	if err != nil || u.Host == "" || u.User != nil || (u.Scheme != "https" && u.Scheme != "http") {
		return fmt.Errorf("mesh login url %q is invalid", truncate(string(v)))
	}
	if p := u.Port(); p != "" {
		if n, err := strconv.Atoi(p); err != nil || n < 1 || n > 65535 {
			return fmt.Errorf("mesh login url %q has an invalid port", truncate(string(v)))
		}
	}
	return nil
}

// Port is a TCP port number.
type Port int

// ProtocolSchema implements SchemaDescriber.
func (Port) ProtocolSchema() ScalarSchema {
	return ScalarSchema{Type: "integer", Minimum: ptr[int64](1), Maximum: ptr[int64](65535)}
}

// Validate checks the range.
func (v Port) Validate() error {
	if v < 1 || v > 65535 {
		return fmt.Errorf("port %d is outside 1-65535", v)
	}
	return nil
}

// Mesh-published port range (docs/05-networking-ingress.md §7).
const (
	MeshPortMin = 31000
	MeshPortMax = 32999
)

// MeshPort is a port published on the server's mesh IP.
type MeshPort int

// ProtocolSchema implements SchemaDescriber.
func (MeshPort) ProtocolSchema() ScalarSchema {
	return ScalarSchema{Type: "integer", Minimum: ptr[int64](MeshPortMin), Maximum: ptr[int64](MeshPortMax)}
}

// Validate checks the range.
func (v MeshPort) Validate() error {
	if v < MeshPortMin || v > MeshPortMax {
		return fmt.Errorf("mesh port %d is outside %d-%d", v, MeshPortMin, MeshPortMax)
	}
	return nil
}

// ---- Misc ----

var (
	reDuration     = regexp.MustCompile(`^[1-9][0-9]{0,5}(?:ms|s|m|h)$`)
	reGitSHA       = regexp.MustCompile(`^[0-9a-f]{40}(?:[0-9a-f]{24})?$`)
	reCacheKey     = regexp.MustCompile(`^[a-z0-9][a-z0-9_.-]{0,127}$`)
	reSemver       = regexp.MustCompile(`^v?[0-9]+\.[0-9]+\.[0-9]+(?:-[0-9A-Za-z.-]+)?(?:\+[0-9A-Za-z.-]+)?$`)
	reSealed       = regexp.MustCompile(`^(?:[A-Za-z0-9+/]{4})*(?:[A-Za-z0-9+/]{2}==|[A-Za-z0-9+/]{3}=)?$`)
	reEd25519Key   = regexp.MustCompile(`^ed25519:[A-Za-z0-9+/]{43}=$`)
	reX25519Key    = regexp.MustCompile(`^x25519:[A-Za-z0-9+/]{43}=$`)
	reKID          = regexp.MustCompile(`^[A-Za-z0-9._-]{1,64}$`)
	reJoinToken    = regexp.MustCompile(`^[A-Za-z0-9_.-]{16,256}$`)
	reTailscaleTag = regexp.MustCompile(`^tag:[a-z0-9-]{1,63}$`)
	reExpectStatus = regexp.MustCompile(`^[1-5](?:xx|[0-9]{2})(?:-[1-5](?:xx|[0-9]{2}))?$`)
	reHeaderName   = regexp.MustCompile(`^[A-Za-z0-9-]{1,64}$`)
	reBackupTag    = regexp.MustCompile(`^[a-z][a-z0-9_-]{0,31}:[A-Za-z0-9_.-]{1,128}$`)
	reSnapshotID   = regexp.MustCompile(`^[a-f0-9]{8,64}$`)
	reBucket       = regexp.MustCompile(`^[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]$`)
	reObjectPrefix = regexp.MustCompile(`^(?:[A-Za-z0-9._-]+/)*[A-Za-z0-9._-]*$`)
	reRegion       = regexp.MustCompile(`^[a-z0-9-]{1,32}$`)
	reHostLabel    = regexp.MustCompile(`^[A-Za-z0-9._-]{1,128}$`)
	reProtocolVer  = regexp.MustCompile(`^v[1-9][0-9]{0,2}$`)
	reContainerID  = regexp.MustCompile(`^[a-f0-9]{64}$`)
)

// Duration is a positive duration with a single unit: "500ms", "30s", "5m", "1h".
type Duration string

// ProtocolSchema implements SchemaDescriber.
func (Duration) ProtocolSchema() ScalarSchema { return strSchema(reDuration, 0) }

// Validate checks the grammar.
func (v Duration) Validate() error { return checkPattern("duration", string(v), reDuration, 0) }

// Std returns the parsed duration (0 if invalid; call Validate first).
func (v Duration) Std() time.Duration {
	d, err := time.ParseDuration(string(v))
	if err != nil {
		return 0
	}
	return d
}

// Capability is a Linux capability name without the CAP_ prefix, e.g.
// "NET_BIND_SERVICE". Whether it may be granted is decided by the local
// policy allow-list, not by this grammar.
type Capability string

// linuxCapabilities lists every capability name known to the protocol.
var linuxCapabilities = []string{
	"AUDIT_CONTROL",
	"AUDIT_READ",
	"AUDIT_WRITE",
	"BLOCK_SUSPEND",
	"BPF",
	"CHECKPOINT_RESTORE",
	"CHOWN",
	"DAC_OVERRIDE",
	"DAC_READ_SEARCH",
	"FOWNER",
	"FSETID",
	"IPC_LOCK",
	"IPC_OWNER",
	"KILL",
	"LEASE",
	"LINUX_IMMUTABLE",
	"MAC_ADMIN",
	"MAC_OVERRIDE",
	"MKNOD",
	"NET_ADMIN",
	"NET_BIND_SERVICE",
	"NET_BROADCAST",
	"NET_RAW",
	"PERFMON",
	"SETFCAP",
	"SETGID",
	"SETPCAP",
	"SETUID",
	"SYSLOG",
	"SYS_ADMIN",
	"SYS_BOOT",
	"SYS_CHROOT",
	"SYS_MODULE",
	"SYS_NICE",
	"SYS_PACCT",
	"SYS_PTRACE",
	"SYS_RAWIO",
	"SYS_RESOURCE",
	"SYS_TIME",
	"SYS_TTY_CONFIG",
	"WAKE_ALARM",
}

// ProtocolSchema implements SchemaDescriber.
func (Capability) ProtocolSchema() ScalarSchema { return enumSchema(linuxCapabilities) }

// Validate checks the value.
func (v Capability) Validate() error { return checkEnum("capability", string(v), linuxCapabilities) }

// GitSHA is a full commit hash (SHA-1 or SHA-256).
type GitSHA string

// ProtocolSchema implements SchemaDescriber.
func (GitSHA) ProtocolSchema() ScalarSchema { return strSchema(reGitSHA, 0) }

// Validate checks the grammar.
func (v GitSHA) Validate() error { return checkPattern("git sha", string(v), reGitSHA, 0) }

// CacheKey names a build cache scope.
type CacheKey string

// ProtocolSchema implements SchemaDescriber.
func (CacheKey) ProtocolSchema() ScalarSchema { return strSchema(reCacheKey, 0) }

// Validate checks the grammar.
func (v CacheKey) Validate() error { return checkPattern("cache key", string(v), reCacheKey, 0) }

// Semver is a semantic version, optionally prefixed with "v".
type Semver string

// ProtocolSchema implements SchemaDescriber.
func (Semver) ProtocolSchema() ScalarSchema { return strSchema(reSemver, 64) }

// Validate checks the grammar.
func (v Semver) Validate() error { return checkPattern("version", string(v), reSemver, 64) }

// MaxSealedBytes bounds a sealed payload (base64 characters).
const MaxSealedBytes = 1 << 20

// SealedBox is base64 (standard, padded) of libsodium crypto_box_seal output
// addressed to the daemon's X25519 key (docs/03 §6).
type SealedBox string

// ProtocolSchema implements SchemaDescriber.
func (SealedBox) ProtocolSchema() ScalarSchema {
	s := strSchema(reSealed, MaxSealedBytes)
	s.MinLength = 64 // 48 bytes of crypto_box_seal overhead + at least 1 byte
	return s
}

// Validate checks encoding and size bounds.
func (v SealedBox) Validate() error {
	if len(v) < 64 {
		return errors.New("sealed value is too short to be a crypto_box_seal output")
	}
	return checkPattern("sealed value", string(v), reSealed, MaxSealedBytes)
}

// Sealed wraps a sealed payload: {"sealed": "<base64>"}.
type Sealed struct {
	Sealed SealedBox `json:"sealed"`
}

// Validate checks the payload encoding.
func (s Sealed) Validate() error { return s.Sealed.Validate() }

// Ed25519Key is a textual Ed25519 public key "ed25519:<base64 std>".
type Ed25519Key string

// ProtocolSchema implements SchemaDescriber.
func (Ed25519Key) ProtocolSchema() ScalarSchema { return strSchema(reEd25519Key, 0) }

// Validate checks the grammar.
func (v Ed25519Key) Validate() error { return checkPattern("ed25519 key", string(v), reEd25519Key, 0) }

// X25519Key is a textual X25519 public key "x25519:<base64 std>".
type X25519Key string

// ProtocolSchema implements SchemaDescriber.
func (X25519Key) ProtocolSchema() ScalarSchema { return strSchema(reX25519Key, 0) }

// Validate checks the grammar.
func (v X25519Key) Validate() error { return checkPattern("x25519 key", string(v), reX25519Key, 0) }

// KeyID names a control-plane signing key in a key set.
type KeyID string

// ProtocolSchema implements SchemaDescriber.
func (KeyID) ProtocolSchema() ScalarSchema { return strSchema(reKID, 0) }

// Validate checks the grammar.
func (v KeyID) Validate() error { return checkPattern("kid", string(v), reKID, 0) }

// TailscaleTag is an ACL tag such as "tag:lr-edge".
type TailscaleTag string

// ProtocolSchema implements SchemaDescriber.
func (TailscaleTag) ProtocolSchema() ScalarSchema { return strSchema(reTailscaleTag, 0) }

// Validate checks the grammar.
func (v TailscaleTag) Validate() error {
	return checkPattern("tailscale tag", string(v), reTailscaleTag, 0)
}

// StatusRange is an expected HTTP status or range: "200", "2xx", "2xx-3xx", "200-399".
type StatusRange string

// ProtocolSchema implements SchemaDescriber.
func (StatusRange) ProtocolSchema() ScalarSchema { return strSchema(reExpectStatus, 0) }

// Validate checks the grammar.
func (v StatusRange) Validate() error {
	return checkPattern("status range", string(v), reExpectStatus, 0)
}

// HeaderName is an HTTP header field name.
type HeaderName string

// ProtocolSchema implements SchemaDescriber.
func (HeaderName) ProtocolSchema() ScalarSchema { return strSchema(reHeaderName, 0) }

// Validate checks the grammar.
func (v HeaderName) Validate() error { return checkPattern("header name", string(v), reHeaderName, 0) }

// BackupTag is a restic snapshot tag "key:value", e.g. "service:svc_01J…".
type BackupTag string

// ProtocolSchema implements SchemaDescriber.
func (BackupTag) ProtocolSchema() ScalarSchema { return strSchema(reBackupTag, 0) }

// Validate checks the grammar.
func (v BackupTag) Validate() error { return checkPattern("backup tag", string(v), reBackupTag, 0) }

// SnapshotID is a restic snapshot id (short or full hex).
type SnapshotID string

// ProtocolSchema implements SchemaDescriber.
func (SnapshotID) ProtocolSchema() ScalarSchema { return strSchema(reSnapshotID, 0) }

// Validate checks the grammar.
func (v SnapshotID) Validate() error { return checkPattern("snapshot id", string(v), reSnapshotID, 0) }

// BucketName is an S3 bucket name.
type BucketName string

// ProtocolSchema implements SchemaDescriber.
func (BucketName) ProtocolSchema() ScalarSchema { return strSchema(reBucket, 0) }

// Validate checks the grammar.
func (v BucketName) Validate() error { return checkPattern("bucket", string(v), reBucket, 0) }

// S3Endpoint is the http(s) base URL of an S3-compatible service, without
// credentials, path, query or fragment (e.g. "https://<account>.r2.cloudflarestorage.com").
// Plain http is only accepted by daemons whose local config lists the host
// (LR_BACKUP_INSECURE_HOSTS, dev MinIO); the grammar allows it.
type S3Endpoint string

var reS3Endpoint = regexp.MustCompile(`^https?://[a-z0-9.-]+(?::[0-9]{1,5})?$`)

// ProtocolSchema implements SchemaDescriber.
func (S3Endpoint) ProtocolSchema() ScalarSchema { return strSchema(reS3Endpoint, 512) }

// Validate checks the grammar and parses the URL.
func (v S3Endpoint) Validate() error {
	if err := checkPattern("s3 endpoint", string(v), reS3Endpoint, 512); err != nil {
		return err
	}
	u, err := url.Parse(string(v))
	if err != nil || (u.Scheme != "https" && u.Scheme != "http") || u.Hostname() == "" || u.User != nil {
		return fmt.Errorf("s3 endpoint %q is invalid", truncate(string(v)))
	}
	return nil
}

// ForwardAuthURL is the dashboard's preview auth endpoint for Caddy forward
// auth: http(s)://host[:port]/auth/preview, where host is a lowercase DNS name
// (a single label such as "dashboard" is allowed) or an IPv4 literal. No
// credentials, query or fragment. Which origins a daemon actually calls is
// decided by its local policy (the control-plane URL and LR_ALLOWED_LOCAL_UPSTREAMS).
type ForwardAuthURL string

// ForwardAuthPath is the only path a ForwardAuthURL may have.
const ForwardAuthPath = "/auth/preview"

var reForwardAuthURL = regexp.MustCompile(`^https?://[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*(?::[1-9][0-9]{0,4})?/auth/preview$`)

// ProtocolSchema implements SchemaDescriber.
func (ForwardAuthURL) ProtocolSchema() ScalarSchema { return strSchema(reForwardAuthURL, 512) }

// Validate checks the grammar, the port range and IPv4 literals.
func (v ForwardAuthURL) Validate() error {
	if err := checkPattern("forward auth url", string(v), reForwardAuthURL, 512); err != nil {
		return err
	}
	if _, _, _, err := v.Parts(); err != nil {
		return err
	}
	return nil
}

// Parts returns the scheme, host and port (explicit, or 80/443 by scheme).
// It does not check the grammar; call Validate first.
func (v ForwardAuthURL) Parts() (scheme, host string, port uint16, err error) {
	u, err := url.Parse(string(v))
	if err != nil || (u.Scheme != "http" && u.Scheme != "https") || u.Hostname() == "" || u.User != nil ||
		u.Path != ForwardAuthPath || u.RawQuery != "" || u.Fragment != "" {
		return "", "", 0, fmt.Errorf("forward auth url %q is invalid", truncate(string(v)))
	}
	host = u.Hostname()
	// A host whose last label is numeric is an IPv4 literal (no TLD is all digits).
	if last := host[strings.LastIndexByte(host, '.')+1:]; strings.Trim(last, "0123456789") == "" {
		a, perr := netip.ParseAddr(host)
		if perr != nil || !a.Is4() {
			return "", "", 0, fmt.Errorf("forward auth url %q has an invalid IPv4 address", truncate(string(v)))
		}
	}
	port = 80
	if u.Scheme == "https" {
		port = 443
	}
	if p := u.Port(); p != "" {
		n, perr := strconv.Atoi(p)
		if perr != nil || n < 1 || n > 65535 {
			return "", "", 0, fmt.Errorf("forward auth url %q has an invalid port", truncate(string(v)))
		}
		port = uint16(n) //nolint:gosec // checked 1-65535
	}
	return u.Scheme, host, port, nil
}

// Origin is "scheme://host:port" with the port always explicit ("" if invalid).
func (v ForwardAuthURL) Origin() string {
	scheme, host, port, err := v.Parts()
	if err != nil {
		return ""
	}
	return scheme + "://" + host + ":" + strconv.Itoa(int(port))
}

// ObjectPrefix is an S3 key prefix such as "launchroom/prod/".
type ObjectPrefix string

// ProtocolSchema implements SchemaDescriber.
func (ObjectPrefix) ProtocolSchema() ScalarSchema { return strSchema(reObjectPrefix, 256) }

// Validate checks the grammar.
func (v ObjectPrefix) Validate() error {
	if hasDotSegment(string(v)) {
		return fmt.Errorf("object prefix %q must not contain . or .. segments", string(v))
	}
	return checkPattern("object prefix", string(v), reObjectPrefix, 256)
}

// Region is an S3 region name.
type Region string

// ProtocolSchema implements SchemaDescriber.
func (Region) ProtocolSchema() ScalarSchema { return strSchema(reRegion, 0) }

// Validate checks the grammar.
func (v Region) Validate() error { return checkPattern("region", string(v), reRegion, 0) }

// Label is a short free-form machine label (hostnames, OS names, versions in reports).
type Label string

// ProtocolSchema implements SchemaDescriber.
func (Label) ProtocolSchema() ScalarSchema { return strSchema(reHostLabel, 0) }

// Validate checks the grammar.
func (v Label) Validate() error { return checkPattern("label", string(v), reHostLabel, 0) }
