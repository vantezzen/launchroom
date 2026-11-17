package state

import (
	"regexp"
	"time"
)

// ---- Runtime visibility: why something failed (docs/03 §9, docs/15 §6) ----
//
// Every type here is an optional report field: launchroomd → dashboard only,
// so no daemon feature is needed (the dashboard updates before its servers
// and reads unknown codes leniently, D76).

// WorkloadFailureCode classifies why a container could not be pulled,
// created or started, at the source (the Docker error), so the dashboard
// explains it without parsing Docker's wording.
type WorkloadFailureCode string

// Workload failure codes.
const (
	// Start failures.
	FailureCommandNotFound WorkloadFailureCode = "command_not_found" // exec: "x": executable file not found / no such file (127)
	FailureNotExecutable   WorkloadFailureCode = "not_executable"    // permission denied on the start command (126)
	FailureWrongPlatform   WorkloadFailureCode = "wrong_platform"    // exec format error, or the image has no variant for this server's platform
	FailureMountFailed     WorkloadFailureCode = "mount_failed"      // a volume could not be mounted
	FailureInvalidUser     WorkloadFailureCode = "invalid_user"      // the image's user does not exist in it
	// Pull failures.
	FailureImageNotFound WorkloadFailureCode = "image_not_found" // manifest unknown / not found
	FailureUnauthorized  WorkloadFailureCode = "unauthorized"    // pull access denied (401/403)
	FailureRateLimited   WorkloadFailureCode = "rate_limited"    // toomanyrequests
	FailureNetwork       WorkloadFailureCode = "network"         // the registry could not be reached
	// Either.
	FailureNoSpace WorkloadFailureCode = "no_space" // no space left on device
	FailureOther   WorkloadFailureCode = "other"
)

var workloadFailureCodes = []string{
	string(FailureCommandNotFound), string(FailureNotExecutable), string(FailureWrongPlatform), string(FailureMountFailed),
	string(FailureInvalidUser), string(FailureImageNotFound), string(FailureUnauthorized), string(FailureRateLimited),
	string(FailureNetwork), string(FailureNoSpace), string(FailureOther),
}

// ProtocolSchema implements SchemaDescriber.
func (WorkloadFailureCode) ProtocolSchema() ScalarSchema { return enumSchema(workloadFailureCodes) }

// Validate checks the value.
func (v WorkloadFailureCode) Validate() error {
	return checkEnum("workload failure code", string(v), workloadFailureCodes)
}

// WorkloadFailure is why a workload's container can't be pulled, created or
// started right now (WorkloadStatus.failure); cleared once it starts.
type WorkloadFailure struct {
	Code WorkloadFailureCode `json:"code"`
	// Message is Docker's error, secret-free and clipped.
	Message string    `json:"message,omitzero" jsonschema:"maxLength=1024"`
	At      time.Time `json:"at"`
}

func (f *WorkloadFailure) validate(e *errs, p string) {
	e.check(p+".code", f.Code)
	checkText(e, p+".message", f.Message, 1024)
	if f.At.IsZero() {
		e.addf(p+".at", "is required")
	}
}

// Limits of image facts.
const (
	maxImageUser         = 64
	maxImageExposedPorts = 32
)

// ImageFacts is what an image's config says about how it runs: launchroomd
// reads it when it pulls (WorkloadStatus.image) or builds (BuildSummary.image)
// an image, so the dashboard can grant the capabilities a root image needs
// (docs/09) and adopt the port it exposes.
type ImageFacts struct {
	// User is Config.User as written in the image ("", "root", "0", "1000:1000", "app").
	User string `json:"user,omitzero" jsonschema:"maxLength=64"`
	// ExposedPorts are the image's EXPOSEd TCP ports (sorted, unique).
	ExposedPorts []Port `json:"exposedPorts,omitzero" jsonschema:"nullable,maxItems=32"`
}

func (f *ImageFacts) validate(e *errs, p string) {
	checkText(e, p+".user", f.User, maxImageUser)
	if hasControl(f.User) {
		e.addf(p+".user", "contains control characters")
	}
	checkCount(e, p+".exposedPorts", len(f.ExposedPorts), 0, maxImageExposedPorts)
	for i, port := range f.ExposedPorts {
		e.check(idx(p+".exposedPorts", i), port)
	}
	unique(e, p+".exposedPorts", f.ExposedPorts)
}

func hasControl(s string) bool {
	for _, r := range s {
		if r < 0x20 || r == 0x7f {
			return true
		}
	}
	return false
}

// ProbeHint names what a failing HTTP probe's answer means (ProbeStatus.hint).
type ProbeHint string

// Probe hints.
const (
	// ProbeHostNotAllowed: the app answered 400/403/421 with a "host not
	// allowed" page (Django DisallowedHost, Rails blocked hosts, ASP.NET
	// AllowedHosts): it rejects its own domain.
	ProbeHostNotAllowed ProbeHint = "host_not_allowed"
)

var probeHints = []string{string(ProbeHostNotAllowed)}

// ProtocolSchema implements SchemaDescriber.
func (ProbeHint) ProtocolSchema() ScalarSchema { return enumSchema(probeHints) }

// Validate checks the value.
func (v ProbeHint) Validate() error { return checkEnum("probe hint", string(v), probeHints) }

// MaxProbeBody bounds ProbeStatus.body (the start of a failing answer).
const MaxProbeBody = 300

// ---- Host: Docker, reconcile, launchroomd itself, refusals ----

// DockerReach is whether launchroomd reaches Docker (through dockerd-guard
// where it runs): from every reconcile pass and a /_ping every 30 s.
type DockerReach struct {
	Reachable bool      `json:"reachable"`
	CheckedAt time.Time `json:"checkedAt"`
	// Error is why it isn't reachable (secret-free).
	Error string `json:"error,omitzero" jsonschema:"maxLength=512"`
}

// ReconcileErrorCode classifies a problem of the last reconcile pass.
type ReconcileErrorCode string

// Reconcile error codes.
const (
	ReconcileDockerUnreachable ReconcileErrorCode = "docker_unreachable"
	ReconcileGuardRefused      ReconcileErrorCode = "guard_refused"
	ReconcileDiskFull          ReconcileErrorCode = "disk_full"
	ReconcileNotIsolated       ReconcileErrorCode = "not_isolated" // the firewall could not isolate networks, workloads are held
	ReconcileNetwork           ReconcileErrorCode = "network"      // a network could not be created or attached
	ReconcileVolume            ReconcileErrorCode = "volume"       // a volume could not be created
	ReconcileOther             ReconcileErrorCode = "other"
)

var reconcileErrorCodes = []string{
	string(ReconcileDockerUnreachable), string(ReconcileGuardRefused), string(ReconcileDiskFull), string(ReconcileNotIsolated),
	string(ReconcileNetwork), string(ReconcileVolume), string(ReconcileOther),
}

// ProtocolSchema implements SchemaDescriber.
func (ReconcileErrorCode) ProtocolSchema() ScalarSchema { return enumSchema(reconcileErrorCodes) }

// Validate checks the value.
func (v ReconcileErrorCode) Validate() error {
	return checkEnum("reconcile error code", string(v), reconcileErrorCodes)
}

// MaxReconcileErrors bounds ReconcileStatus.errors.
const MaxReconcileErrors = 16

var rePublicID = regexp.MustCompile(`^[a-z]{2,4}_` + ulid + `$`)

// PublicID is any launchroom public id ("wl_…", "vol_…", "net_…").
type PublicID string

// ProtocolSchema implements SchemaDescriber.
func (PublicID) ProtocolSchema() ScalarSchema { return strSchema(rePublicID, 0) }

// Validate checks the grammar.
func (v PublicID) Validate() error { return checkPattern("public id", string(v), rePublicID, 0) }

// ReconcileStatus is how launchroomd's last reconcile passes went.
type ReconcileStatus struct {
	// LastPassAt is when the last pass ended, LastCompleteAt when the last
	// pass ended without an error (omitted: none yet since launchroomd started).
	LastPassAt     time.Time  `json:"lastPassAt"`
	LastCompleteAt *time.Time `json:"lastCompleteAt,omitzero" jsonschema:"nullable"`
	// Errors are the last pass's problems (first 16).
	Errors []ReconcileError `json:"errors,omitzero" jsonschema:"nullable,maxItems=16"`
}

// ReconcileError is one problem of a reconcile pass.
type ReconcileError struct {
	Code ReconcileErrorCode `json:"code"`
	// Target is the public id of the object it concerns (wl_, vol_, net_ …), if any.
	Target  PublicID `json:"target,omitzero"`
	Message string   `json:"message,omitzero" jsonschema:"maxLength=512"`
}

func (r *ReconcileStatus) validate(e *errs, p string) {
	if r.LastPassAt.IsZero() {
		e.addf(p+".lastPassAt", "is required")
	}
	checkCount(e, p+".errors", len(r.Errors), 0, MaxReconcileErrors)
	for i, re := range r.Errors {
		ep := idx(p+".errors", i)
		e.check(ep+".code", re.Code)
		if re.Target != "" {
			e.check(ep+".target", re.Target)
		}
		checkText(e, ep+".message", re.Message, 512)
	}
}

// MaxPanicTrace bounds DaemonExit.panic.
const MaxPanicTrace = 8 << 10

// DaemonRun describes the running launchroomd: when it and the host
// started, and how its previous run ended (docs/03 §9).
type DaemonRun struct {
	StartedAt time.Time `json:"startedAt"`
	// HostBootedAt is the host's boot time (/proc/stat btime), if readable.
	HostBootedAt *time.Time `json:"hostBootedAt,omitzero" jsonschema:"nullable"`
	// PreviousExit is set when the previous run ended uncleanly (a crash,
	// a kill or a host reboot), until a report carrying it was accepted.
	PreviousExit *DaemonExit `json:"previousExit,omitzero" jsonschema:"nullable"`
}

// DaemonExit is how launchroomd's previous run ended.
type DaemonExit struct {
	// Unclean is true when the previous run didn't shut down cleanly.
	Unclean bool `json:"unclean"`
	// At is when the previous run was last seen alive (its last heartbeat).
	At *time.Time `json:"at,omitzero" jsonschema:"nullable"`
	// Panic is the previous run's crash output (Go panic trace, clipped).
	// Secrets are never in it (redacting types), it is still masked.
	Panic string `json:"panic,omitzero" jsonschema:"maxLength=8192"`
}

func (d *DaemonRun) validate(e *errs, p string) {
	if d.StartedAt.IsZero() {
		e.addf(p+".startedAt", "is required")
	}
	if d.PreviousExit != nil {
		checkText(e, p+".previousExit.panic", d.PreviousExit.Panic, MaxPanicTrace)
	}
}

// Refusal is the last time the dashboard refused one of launchroomd's
// signed requests (401, 403, 426): sent with the next accepted report, so an
// intermittent refusal (clock skew, an expired client certificate) is
// visible afterwards.
type Refusal struct {
	At     time.Time `json:"at"`
	Status int       `json:"status" jsonschema:"minimum=400,maximum=599"`
	// ClockSkewMs is the dashboard's Date header minus launchroomd's clock
	// at the refusal (±1 s resolution), when the answer had one.
	ClockSkewMs *int64 `json:"clockSkewMs,omitzero" jsonschema:"nullable"`
	// Message is the dashboard's error text (secret-free).
	Message string `json:"message,omitzero" jsonschema:"maxLength=512"`
}

func (r *Refusal) validate(e *errs, p string) {
	if r.At.IsZero() {
		e.addf(p+".at", "is required")
	}
	if r.Status < 400 || r.Status > 599 {
		e.addf(p+".status", "must be 400-599")
	}
	checkText(e, p+".message", r.Message, 512)
}

// ---- Edge ----

// IngressFailureReason classifies why the edge's Caddy isn't serving.
type IngressFailureReason string

// Ingress failure reasons.
const (
	// IngressPortInUse: another process holds :80 or :443 (port, process).
	IngressPortInUse IngressFailureReason = "port_in_use"
	// IngressConfigRefused: Caddy refused the rendered config.
	IngressConfigRefused IngressFailureReason = "config_refused"
	// IngressImage: Caddy's image could not be pulled.
	IngressImage IngressFailureReason = "image"
	// IngressNotRunning: the Caddy container keeps exiting.
	IngressNotRunning   IngressFailureReason = "not_running"
	IngressOtherFailure IngressFailureReason = "other"
)

var ingressFailureReasons = []string{
	string(IngressPortInUse), string(IngressConfigRefused), string(IngressImage), string(IngressNotRunning), string(IngressOtherFailure),
}

// ProtocolSchema implements SchemaDescriber.
func (IngressFailureReason) ProtocolSchema() ScalarSchema { return enumSchema(ingressFailureReasons) }

// Validate checks the value.
func (v IngressFailureReason) Validate() error {
	return checkEnum("ingress failure reason", string(v), ingressFailureReasons)
}

// Limits of IngressStatus failure details.
const (
	maxIngressProcess     = 64
	MaxIngressOutputLines = 30
)

// CertFailureKind classifies a host's last certificate failure.
type CertFailureKind string

// Certificate failure kinds.
const (
	CertRateLimited CertFailureKind = "rate_limited" // the CA's rate limit (retryAfter)
	CertDNSMismatch CertFailureKind = "dns_mismatch" // the CA reached another address than this edge
	CertCAA         CertFailureKind = "caa"          // a CAA record forbids the CA
	CertTimeout     CertFailureKind = "timeout"      // the CA's challenge timed out
	CertDNSProvider CertFailureKind = "dns_provider" // the DNS provider's API refused the DNS-01 record
	CertOtherError  CertFailureKind = "other"
)

var certFailureKinds = []string{
	string(CertRateLimited), string(CertDNSMismatch), string(CertCAA), string(CertTimeout), string(CertDNSProvider), string(CertOtherError),
}

// ProtocolSchema implements SchemaDescriber.
func (CertFailureKind) ProtocolSchema() ScalarSchema { return enumSchema(certFailureKinds) }

// Validate checks the value.
func (v CertFailureKind) Validate() error {
	return checkEnum("certificate failure kind", string(v), certFailureKinds)
}

// ---- Disk ----

// MaxDiskVolumes bounds DiskBreakdown.volumes.
const MaxDiskVolumes = 1024

// DiskBreakdown is what takes the server's disk (HostHealth.disk), measured
// less often than the rest of the health snapshot (every 30 min).
type DiskBreakdown struct {
	MeasuredAt time.Time `json:"measuredAt"`
	// Volumes are the launchroom-managed volumes and their sizes.
	Volumes []VolumeUsage `json:"volumes,omitzero" jsonschema:"nullable,maxItems=1024"`
	// WalBacklogMB is the WAL waiting to be shipped (point-in-time recovery).
	WalBacklogMB *int64 `json:"walBacklogMb,omitzero" jsonschema:"nullable,minimum=0"`
	// RegistryMB is the image registry's storage (control-plane host).
	RegistryMB *int64 `json:"registryMb,omitzero" jsonschema:"nullable,minimum=0"`
}

// VolumeUsage is the size of one managed volume.
type VolumeUsage struct {
	ID     VolumeID `json:"id"`
	SizeMB int64    `json:"sizeMb" jsonschema:"minimum=0"`
	// Retired is true for a volume the NodeState no longer declares (kept
	// until its deletion is requested, docs/05 §1.1).
	Retired bool `json:"retired,omitzero"`
}

func (d *DiskBreakdown) validate(e *errs, p string) {
	if d.MeasuredAt.IsZero() {
		e.addf(p+".measuredAt", "is required")
	}
	checkCount(e, p+".volumes", len(d.Volumes), 0, MaxDiskVolumes)
	ids := make([]VolumeID, 0, len(d.Volumes))
	for i, v := range d.Volumes {
		vp := idx(p+".volumes", i)
		e.check(vp+".id", v.ID)
		if v.SizeMB < 0 {
			e.addf(vp+".sizeMb", "must be >= 0")
		}
		ids = append(ids, v.ID)
	}
	unique(e, p+".volumes.id", ids)
	for name, v := range map[string]*int64{"walBacklogMb": d.WalBacklogMB, "registryMb": d.RegistryMB} {
		if v != nil && *v < 0 {
			e.addf(p+"."+name, "must be >= 0")
		}
	}
}
