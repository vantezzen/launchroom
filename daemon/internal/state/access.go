package state

import (
	"fmt"
	"net/netip"
	"regexp"
	"strings"
	"time"

	"golang.org/x/crypto/ssh"
)

// Temporary remote access (docs/03 §12, D61, D67): "Connect via SSH" into one
// workload container, "Connect with SFTP" into one volume and temporary
// access to a managed database, each a pinned "access" task that runs a
// listener on a random port for one person.

// Limits of access documents.
const (
	MaxAccessAllowFrom = 16
	MaxAccessKeys      = 4
	MaxAccessHosts     = 4
	MaxAccessEvents    = 50
	// MinRSABits is the smallest RSA key accepted for SSH access.
	MinRSABits = 3072
)

var (
	reAccessID       = idRegexp("acc")
	reAccessSSHUser  = regexp.MustCompile(`^lr-[a-z0-9]{10}$`)
	reAccessDBUser   = regexp.MustCompile(`^lr_tmp_[a-z0-9]{12}$`)
	reAccessPrefix   = regexp.MustCompile(`^[0-9a-f.:]{2,45}/[0-9]{1,3}$`)
	reSSHPublicKey   = regexp.MustCompile(`^(ssh-ed25519|ecdsa-sha2-nistp256|ecdsa-sha2-nistp384|ecdsa-sha2-nistp521|ssh-rsa) [A-Za-z0-9+/]+={0,2}$`)
	reSSHFingerprint = regexp.MustCompile(`^SHA256:[A-Za-z0-9+/]{43}$`)
	reHexSHA256      = regexp.MustCompile(`^[0-9a-f]{64}$`)
	reAccessHost     = regexp.MustCompile(`^[a-z0-9.:-]{1,253}$`)
	accessKinds      = []string{string(AccessSSH), string(AccessDatabase), string(AccessVolume)}
	accessEnds       = []string{string(AccessEndEnded), string(AccessEndIdle), string(AccessEndDeadline), string(AccessEndLocked), string(AccessEndRefused), string(AccessEndError)}
	accessEvents     = []string{string(AccessEventConnected), string(AccessEventAuthFailed), string(AccessEventLocked)}
	accessMethods    = []string{string(AccessMethodPassword), string(AccessMethodPublicKey)}
	accessChannels   = []string{string(AccessChannelShell), string(AccessChannelExec), string(AccessChannelSFTP), string(AccessChannelDatabase)}
	// AccessEngines are the database engines with temporary access.
	AccessEngines = []DatabaseEngine{EnginePostgres, EngineRedis, EngineValkey}
)

// AccessID is a temporary access' public id ("acc_<ULID>").
type AccessID string

// ProtocolSchema implements SchemaDescriber.
func (AccessID) ProtocolSchema() ScalarSchema { return strSchema(reAccessID, 0) }

// Validate checks the grammar.
func (v AccessID) Validate() error { return checkPattern("access id", string(v), reAccessID, 0) }

// AccessKind is what an access opens.
type AccessKind string

// Access kinds.
const (
	AccessSSH      AccessKind = "ssh"
	AccessDatabase AccessKind = "database"
	// AccessVolume is SFTP only, into a helper container that mounts one
	// volume of the workload (D67).
	AccessVolume AccessKind = "volume"
)

// ProtocolSchema implements SchemaDescriber.
func (AccessKind) ProtocolSchema() ScalarSchema { return enumSchema(accessKinds) }

// Validate checks the value.
func (v AccessKind) Validate() error { return checkEnum("access kind", string(v), accessKinds) }

// AccessUser is the generated user name: "lr-<10>" for SSH and SFTP, "lr_tmp_<12>"
// for database roles (a fixed alphabet, so quoting is always safe).
type AccessUser string

// ProtocolSchema implements SchemaDescriber.
func (AccessUser) ProtocolSchema() ScalarSchema {
	return ScalarSchema{Type: "string", Pattern: `^(lr-[a-z0-9]{10}|lr_tmp_[a-z0-9]{12})$`}
}

// Validate checks the grammar.
func (v AccessUser) Validate() error {
	if reAccessSSHUser.MatchString(string(v)) || reAccessDBUser.MatchString(string(v)) {
		return nil
	}
	return fmt.Errorf("access user %q is not a generated name", truncate(string(v)))
}

// SSHUser reports whether v is an SSH user name.
func (v AccessUser) SSHUser() bool { return reAccessSSHUser.MatchString(string(v)) }

// DatabaseUser reports whether v is a database role name.
func (v AccessUser) DatabaseUser() bool { return reAccessDBUser.MatchString(string(v)) }

// AccessPrefix is an allowed source range ("203.0.113.7/32", "2001:db8::/48").
type AccessPrefix string

// ProtocolSchema implements SchemaDescriber.
func (AccessPrefix) ProtocolSchema() ScalarSchema { return strSchema(reAccessPrefix, 0) }

// Validate checks the grammar and the local limits: canonical (host bits
// zero), IPv4 no wider than /8, IPv6 no wider than /32, never unspecified,
// link-local (metadata) or multicast.
func (v AccessPrefix) Validate() error {
	if err := checkPattern("allowed range", string(v), reAccessPrefix, 0); err != nil {
		return err
	}
	p, err := netip.ParsePrefix(string(v))
	if err != nil || p.Masked() != p || p.Addr().Zone() != "" || p.Addr().Is4In6() {
		return fmt.Errorf("allowed range %q is not a canonical prefix", truncate(string(v)))
	}
	a := p.Addr()
	if a.Is4() && p.Bits() < 8 || a.Is6() && p.Bits() < 32 {
		return fmt.Errorf("allowed range %q is wider than /8 (IPv4) or /32 (IPv6)", string(v))
	}
	if a.IsUnspecified() || a.IsLinkLocalUnicast() || a.IsMulticast() || a.IsLinkLocalMulticast() ||
		netip.MustParsePrefix("169.254.0.0/16").Overlaps(p) || netip.MustParsePrefix("fe80::/10").Overlaps(p) ||
		netip.MustParsePrefix("0.0.0.0/8").Overlaps(p) || netip.MustParsePrefix("224.0.0.0/3").Overlaps(p) || netip.MustParsePrefix("ff00::/8").Overlaps(p) {
		return fmt.Errorf("allowed range %q includes unspecified, link-local or multicast addresses", string(v))
	}
	return nil
}

// Prefix returns the parsed prefix (call Validate first).
func (v AccessPrefix) Prefix() netip.Prefix {
	p, _ := netip.ParsePrefix(string(v))
	return p
}

// SSHPublicKey is one OpenSSH public key ("ssh-ed25519 AAAA…", no comment).
type SSHPublicKey string

// ProtocolSchema implements SchemaDescriber.
func (SSHPublicKey) ProtocolSchema() ScalarSchema { return strSchema(reSSHPublicKey, 2048) }

// Validate checks the grammar, that the key parses as the type it names and,
// for RSA, the key size.
func (v SSHPublicKey) Validate() error {
	if err := checkPattern("ssh public key", string(v), reSSHPublicKey, 2048); err != nil {
		return err
	}
	k, err := v.Parse()
	if err != nil {
		return fmt.Errorf("ssh public key: %w", err)
	}
	if k.Type() != strings.SplitN(string(v), " ", 2)[0] {
		return fmt.Errorf("ssh public key: type %s does not match its data", k.Type())
	}
	if ck, ok := k.(ssh.CryptoPublicKey); ok && k.Type() == ssh.KeyAlgoRSA {
		if rk, ok := ck.CryptoPublicKey().(interface{ Size() int }); ok && rk.Size()*8 < MinRSABits {
			return fmt.Errorf("ssh public key: RSA keys need at least %d bits", MinRSABits)
		}
	}
	return nil
}

// Parse returns the parsed key.
func (v SSHPublicKey) Parse() (ssh.PublicKey, error) {
	k, _, _, rest, err := ssh.ParseAuthorizedKey([]byte(v))
	if err != nil {
		return nil, err
	}
	if len(rest) > 0 {
		return nil, fmt.Errorf("one key only")
	}
	return k, nil
}

// AccessHost is an address or DNS name the dashboard shows for an access
// (the certificate's subject alternative names).
type AccessHost string

// ProtocolSchema implements SchemaDescriber.
func (AccessHost) ProtocolSchema() ScalarSchema { return strSchema(reAccessHost, 253) }

// Validate checks that it is an IP address or a hostname.
func (v AccessHost) Validate() error {
	if err := checkPattern("access host", string(v), reAccessHost, 253); err != nil {
		return err
	}
	if a, err := netip.ParseAddr(string(v)); err == nil {
		if a.Zone() != "" || a.String() != string(v) {
			return fmt.Errorf("access host %q is not a canonical IP", truncate(string(v)))
		}
		return nil
	}
	if strings.Contains(string(v), ":") || !reHostname.MatchString(string(v)) && string(v) != "localhost" {
		return fmt.Errorf("access host %q is neither an IP nor a hostname", truncate(string(v)))
	}
	return nil
}

// AccessSpec is the spec of an "access" task (docs/03 §12.1).
type AccessSpec struct {
	Access   AccessID   `json:"access"`
	Kind     AccessKind `json:"kind"`
	Workload WorkloadID `json:"workload"`
	// Engine is the database engine (database only: postgres, redis, valkey).
	Engine DatabaseEngine `json:"engine,omitzero"`
	// User is the generated SSH user or database role.
	User AccessUser `json:"user"`
	// AuthorizedKeys are the only keys SSH accepts (no password then).
	AuthorizedKeys []SSHPublicKey `json:"authorizedKeys,omitzero" jsonschema:"maxItems=4"`
	// Password is a sealed TaskToken (purpose "access"): the SSH password
	// (without keys) or the database role's password.
	Password *Sealed `json:"password,omitzero" jsonschema:"nullable"`
	// AllowFrom are the source ranges that may connect.
	AllowFrom []AccessPrefix `json:"allowFrom" jsonschema:"minItems=1,maxItems=16"`
	// Hosts are the addresses and names the dashboard shows (certificate SANs).
	Hosts []AccessHost `json:"hosts,omitzero" jsonschema:"maxItems=4"`
	// AllowWrites lets the database role change rows (never DDL or roles);
	// for a volume it mounts the volume writable (else read-only).
	AllowWrites bool `json:"allowWrites,omitzero"`
	// Volume is the volume a volume access serves (volume only). The
	// workload must mount it in the verified NodeState.
	Volume VolumeID `json:"volume,omitzero"`
	// Image is the SFTP helper image (volume only): digest-pinned, its digest
	// one this launchroomd pins for the "files" system component.
	Image ImageRef `json:"image,omitzero"`
	// IdleTimeout ends the access after this long without a connection or traffic.
	IdleTimeout Duration `json:"idleTimeout"`
}

// TaskType implements TaskSpec.
func (*AccessSpec) TaskType() TaskType { return TaskAccess }

// Validate implements TaskSpec.
func (s *AccessSpec) Validate() error {
	var e errs
	e.check("access", s.Access)
	e.check("kind", s.Kind)
	e.check("workload", s.Workload)
	e.check("user", s.User)
	e.check("idleTimeout", s.IdleTimeout)
	if d := s.IdleTimeout.Std(); d < 5*time.Minute || d > time.Hour {
		e.addf("idleTimeout", "must be between 5m and 1h")
	}
	checkCount(&e, "allowFrom", len(s.AllowFrom), 1, MaxAccessAllowFrom)
	for i, p := range s.AllowFrom {
		e.check(idx("allowFrom", i), p)
	}
	unique(&e, "allowFrom", s.AllowFrom)
	checkCount(&e, "hosts", len(s.Hosts), 0, MaxAccessHosts)
	for i, h := range s.Hosts {
		e.check(idx("hosts", i), h)
	}
	checkCount(&e, "authorizedKeys", len(s.AuthorizedKeys), 0, MaxAccessKeys)
	for i, k := range s.AuthorizedKeys {
		e.check(idx("authorizedKeys", i), k)
	}
	unique(&e, "authorizedKeys", s.AuthorizedKeys)
	if s.Password != nil {
		e.check("password", *s.Password)
	}
	if s.Kind != AccessVolume && (s.Volume != "" || s.Image != "") {
		e.addf("volume", "only valid for volume access")
	}
	switch s.Kind {
	case AccessSSH, AccessVolume:
		if !s.User.SSHUser() {
			e.addf("user", "must be an SSH user (lr-…)")
		}
		if s.Engine != "" || s.Kind == AccessSSH && s.AllowWrites {
			e.addf("engine", "only valid for database access")
		}
		if (len(s.AuthorizedKeys) == 0) == (s.Password == nil) {
			e.addf("password", "ssh access takes either authorizedKeys or a password")
		}
		if s.Kind == AccessVolume {
			e.check("volume", s.Volume)
			e.check("image", s.Image)
			if !strings.Contains(string(s.Image), "@sha256:") {
				e.addf("image", "must be digest-pinned")
			}
		}
	case AccessDatabase:
		if !s.User.DatabaseUser() {
			e.addf("user", "must be a database role (lr_tmp_…)")
		}
		e.check("engine", s.Engine)
		ok := false
		for _, en := range AccessEngines {
			ok = ok || s.Engine == en
		}
		if !ok {
			e.addf("engine", "has no temporary access")
		}
		if len(s.AuthorizedKeys) > 0 {
			e.addf("authorizedKeys", "only valid for ssh access")
		}
		if s.Password == nil {
			e.addf("password", "is required for database access")
		}
	}
	return e.err()
}

// AccessEndReason says why an access ended.
type AccessEndReason string

// End reasons.
const (
	AccessEndEnded    AccessEndReason = "ended"
	AccessEndIdle     AccessEndReason = "idle"
	AccessEndDeadline AccessEndReason = "deadline"
	AccessEndLocked   AccessEndReason = "locked"
	AccessEndRefused  AccessEndReason = "refused"
	AccessEndError    AccessEndReason = "error"
)

// ProtocolSchema implements SchemaDescriber.
func (AccessEndReason) ProtocolSchema() ScalarSchema { return enumSchema(accessEnds) }

// Validate checks the value.
func (v AccessEndReason) Validate() error {
	return checkEnum("access end reason", string(v), accessEnds)
}

// AccessEventType is one thing that happened at an access endpoint.
type AccessEventType string

// Event types.
const (
	AccessEventConnected  AccessEventType = "connected"
	AccessEventAuthFailed AccessEventType = "auth_failed"
	AccessEventLocked     AccessEventType = "locked"
)

// ProtocolSchema implements SchemaDescriber.
func (AccessEventType) ProtocolSchema() ScalarSchema { return enumSchema(accessEvents) }

// Validate checks the value.
func (v AccessEventType) Validate() error { return checkEnum("access event", string(v), accessEvents) }

// AccessMethod is how a connection authenticated.
type AccessMethod string

// Methods.
const (
	AccessMethodPassword  AccessMethod = "password"
	AccessMethodPublicKey AccessMethod = "publickey"
)

// ProtocolSchema implements SchemaDescriber.
func (AccessMethod) ProtocolSchema() ScalarSchema { return enumSchema(accessMethods) }

// Validate checks the value.
func (v AccessMethod) Validate() error { return checkEnum("access method", string(v), accessMethods) }

// AccessChannel is what an authenticated connection used.
type AccessChannel string

// Channels.
const (
	AccessChannelShell    AccessChannel = "shell"
	AccessChannelExec     AccessChannel = "exec"
	AccessChannelSFTP     AccessChannel = "sftp"
	AccessChannelDatabase AccessChannel = "database"
)

// ProtocolSchema implements SchemaDescriber.
func (AccessChannel) ProtocolSchema() ScalarSchema { return enumSchema(accessChannels) }

// Validate checks the value.
func (v AccessChannel) Validate() error {
	return checkEnum("access channel", string(v), accessChannels)
}

// AccessEvent is reported for the audit log (never credentials or contents).
type AccessEvent struct {
	At     time.Time       `json:"at"`
	Type   AccessEventType `json:"type"`
	Remote IPAddr          `json:"remote,omitzero"`
	Method AccessMethod    `json:"method,omitzero"`
	// Channel is set for connected events once the client opened one (ssh) or
	// authenticated (database).
	Channel AccessChannel `json:"channel,omitzero"`
	// Count aggregates auth_failed events of one address.
	Count int `json:"count,omitzero" jsonschema:"minimum=0,maximum=1000000"`
}

func (ev *AccessEvent) validate(e *errs, p string) {
	if ev.At.IsZero() {
		e.addf(p+".at", "is required")
	}
	e.check(p+".type", ev.Type)
	if ev.Remote != "" {
		e.check(p+".remote", ev.Remote)
	}
	if ev.Method != "" {
		e.check(p+".method", ev.Method)
	}
	if ev.Channel != "" {
		e.check(p+".channel", ev.Channel)
	}
	if ev.Count < 0 || ev.Count > 1000000 {
		e.addf(p+".count", "out of range")
	}
}

// AccessHostKey is the SSH host key of an access (generated per access).
type AccessHostKey struct {
	Type      string `json:"type" jsonschema:"enum=ssh-ed25519"`
	SHA256    string `json:"sha256" jsonschema:"pattern=^SHA256:[A-Za-z0-9+/]{43}$"`
	PublicKey string `json:"publicKey" jsonschema:"maxLength=256"`
}

// AccessCertificate is the TLS certificate of a database access.
type AccessCertificate struct {
	// SHA256 is the lowercase hex SHA-256 of the DER certificate.
	SHA256 string `json:"sha256" jsonschema:"pattern=^[0-9a-f]{64}$"`
	PEM    string `json:"pem" jsonschema:"maxLength=4096"`
}

// AccessStatus is reported with every heartbeat of an access task.
type AccessStatus struct {
	Port        int                `json:"port" jsonschema:"minimum=1024,maximum=65535"`
	HostKey     *AccessHostKey     `json:"hostKey,omitzero" jsonschema:"nullable"`
	Certificate *AccessCertificate `json:"certificate,omitzero" jsonschema:"nullable"`
	// PrivateAddresses are private interface addresses for clients on the same network.
	PrivateAddresses []IPAddr `json:"privateAddresses,omitzero" jsonschema:"maxItems=4"`
	// Database is the database a Postgres role connects to (for the connection URL).
	Database TableName `json:"database,omitzero"`
	// BehindNat: the detected public IPv4 is on no interface of this server.
	BehindNat         bool          `json:"behindNat,omitzero"`
	ActiveConnections int           `json:"activeConnections" jsonschema:"minimum=0"`
	LastActivityAt    time.Time     `json:"lastActivityAt,omitzero"`
	Events            []AccessEvent `json:"events,omitzero" jsonschema:"maxItems=50"`
	// Dropped counts events that did not fit.
	Dropped int `json:"dropped,omitzero" jsonschema:"minimum=0"`
	// Owner is who SFTP reads and writes as in a volume access: the owner of
	// the volume's top folder (D67).
	Owner *FileOwner `json:"owner,omitzero" jsonschema:"nullable"`
}

func (s *AccessStatus) validate(e *errs, p string) {
	if s.Port < 1024 || s.Port > 65535 {
		e.addf(p+".port", "must be between 1024 and 65535")
	}
	if s.HostKey != nil {
		if s.HostKey.Type != ssh.KeyAlgoED25519 || !reSSHFingerprint.MatchString(s.HostKey.SHA256) || len(s.HostKey.PublicKey) > 256 {
			e.addf(p+".hostKey", "is not an ed25519 key with its SHA256 fingerprint")
		}
	}
	if s.Certificate != nil && (!reHexSHA256.MatchString(s.Certificate.SHA256) || len(s.Certificate.PEM) > 4096) {
		e.addf(p+".certificate", "needs a hex SHA-256 and a PEM of at most 4096 bytes")
	}
	if s.Database != "" {
		e.check(p+".database", s.Database)
	}
	checkCount(e, p+".privateAddresses", len(s.PrivateAddresses), 0, 4)
	for i, a := range s.PrivateAddresses {
		e.check(idx(p+".privateAddresses", i), a)
	}
	if s.ActiveConnections < 0 || s.Dropped < 0 {
		e.addf(p, "counts must be >= 0")
	}
	checkCount(e, p+".events", len(s.Events), 0, MaxAccessEvents)
	for i := range s.Events {
		s.Events[i].validate(e, idx(p+".events", i))
	}
	if s.Owner != nil {
		s.Owner.validate(e, p+".owner")
	}
}

// AccessResult is reported when an access task finishes.
type AccessResult struct {
	EndReason    AccessEndReason `json:"endReason"`
	StartedAt    time.Time       `json:"startedAt,omitzero"`
	Connections  int             `json:"connections" jsonschema:"minimum=0"`
	AuthFailures int             `json:"authFailures" jsonschema:"minimum=0"`
	BytesIn      int64           `json:"bytesIn" jsonschema:"minimum=0"`
	BytesOut     int64           `json:"bytesOut" jsonschema:"minimum=0"`
	// Events are those after the last heartbeat.
	Events []AccessEvent `json:"events,omitzero" jsonschema:"maxItems=50"`
}

func (r *AccessResult) validate(e *errs) {
	e.check("access.endReason", r.EndReason)
	if r.Connections < 0 || r.AuthFailures < 0 || r.BytesIn < 0 || r.BytesOut < 0 {
		e.addf("access", "counts must be >= 0")
	}
	checkCount(e, "access.events", len(r.Events), 0, MaxAccessEvents)
	for i := range r.Events {
		r.Events[i].validate(e, idx("access.events", i))
	}
}
