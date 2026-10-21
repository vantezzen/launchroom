package state

import (
	"encoding/json/v2"
	"errors"
	"time"

	"github.com/vantezzen/launchroom/daemon/internal/secret"
)

// Document kinds of the v1 protocol besides NodeState.
const (
	KindEnrollRequest    = "EnrollRequest"
	KindEnrollResponse   = "EnrollResponse"
	KindLeaseRequest     = "LeaseRequest"
	KindTaskLease        = "TaskLease"
	KindHeartbeat        = "TaskHeartbeat"
	KindHeartbeatResult  = "TaskHeartbeatResult"
	KindTaskCompletion   = "TaskCompletion"
	KindReport           = "Report"
	KindLogBatch         = "LogBatch"
	ProtocolVersionV1    = "v1"
	maxProtocolVersions  = 8
	maxDetectedPortCount = 32
	maxProbeErrorLen     = 1024
)

// ---- Enrollment (docs/03 §3) ----

// EnrollRequest is sent once with the join token. It is signed (keyid
// "enroll") with the key in SigningKey, proving possession.
type EnrollRequest struct {
	TypeMeta
	// Token is the single-use join token. It is serialized only by MarshalJSON
	// via Reveal; it is never logged.
	Token            secret.Secret `json:"token" jsonschema:"type=string,pattern=^[A-Za-z0-9_.-]{16\\,256}$"`
	SigningKey       Ed25519Key    `json:"signingKey"`
	SealingKey       X25519Key     `json:"sealingKey"`
	DaemonVersion    Semver        `json:"daemonVersion"`
	ProtocolVersions []string      `json:"protocolVersions" jsonschema:"minItems=1,maxItems=8"`
	Host             HostFacts     `json:"host"`
}

// HostFacts describe the enrolling host (informational, shown in the UI).
type HostFacts struct {
	Hostname Label `json:"hostname"`
	OS       Label `json:"os"`
	Arch     Label `json:"arch"`
	CPUs     int   `json:"cpus" jsonschema:"minimum=1,maximum=4096"`
	// MemoryMB is total host memory if known.
	MemoryMB int `json:"memoryMb,omitzero" jsonschema:"minimum=0"`
	// Hardware describes the machine (device and provider detection); omitted by older daemons.
	Hardware *HardwareFacts `json:"hardware,omitzero" jsonschema:"nullable"`
}

// enrollRequestWire is EnrollRequest with the token as a plain string.
type enrollRequestWire struct {
	TypeMeta
	Token            string     `json:"token"`
	SigningKey       Ed25519Key `json:"signingKey"`
	SealingKey       X25519Key  `json:"sealingKey"`
	DaemonVersion    Semver     `json:"daemonVersion"`
	ProtocolVersions []string   `json:"protocolVersions"`
	Host             HostFacts  `json:"host"`
}

// MarshalJSON reveals the token deliberately; this is the only place it is serialized.
func (r EnrollRequest) MarshalJSON() ([]byte, error) {
	return json.Marshal(enrollRequestWire{
		TypeMeta: r.TypeMeta, Token: r.Token.Reveal(), SigningKey: r.SigningKey, SealingKey: r.SealingKey,
		DaemonVersion: r.DaemonVersion, ProtocolVersions: r.ProtocolVersions, Host: r.Host,
	})
}

// UnmarshalJSON strictly decodes the wire form.
func (r *EnrollRequest) UnmarshalJSON(data []byte) error {
	var w enrollRequestWire
	if err := json.Unmarshal(data, &w, strictOptions()); err != nil {
		return err
	}
	*r = EnrollRequest{
		TypeMeta: w.TypeMeta, Token: secret.Secret(w.Token), SigningKey: w.SigningKey, SealingKey: w.SealingKey,
		DaemonVersion: w.DaemonVersion, ProtocolVersions: w.ProtocolVersions, Host: w.Host,
	}
	return nil
}

// Validate checks the request.
func (r *EnrollRequest) Validate() error {
	var e errs
	r.check(&e, KindEnrollRequest)
	if !reJoinToken.MatchString(r.Token.Reveal()) {
		e.addf("token", "does not match %s", reJoinToken.String())
	}
	e.check("signingKey", r.SigningKey)
	e.check("sealingKey", r.SealingKey)
	e.check("daemonVersion", r.DaemonVersion)
	checkProtocolVersions(&e, r.ProtocolVersions)
	e.check("host.hostname", r.Host.Hostname)
	e.check("host.os", r.Host.OS)
	e.check("host.arch", r.Host.Arch)
	if r.Host.CPUs < 1 || r.Host.CPUs > 4096 {
		e.addf("host.cpus", "must be between 1 and 4096")
	}
	if r.Host.MemoryMB < 0 {
		e.addf("host.memoryMb", "must be >= 0")
	}
	if r.Host.Hardware != nil {
		r.Host.Hardware.validate(&e, "host.hardware")
	}
	return e.err()
}

func checkProtocolVersions(e *errs, vs []string) {
	checkCount(e, "protocolVersions", len(vs), 1, maxProtocolVersions)
	for i, v := range vs {
		if !reProtocolVer.MatchString(v) {
			e.addf(idx("protocolVersions", i), "must look like v1")
		}
	}
}

// ValidJoinToken reports whether s has the join token grammar.
func ValidJoinToken(s string) bool { return reJoinToken.MatchString(s) }

// EnrollResponse is the signed answer to an enrollment. The daemon verifies
// it against the CP key pinned in the install command before trusting anything in it.
type EnrollResponse struct {
	TypeMeta
	ServerID ServerID     `json:"serverId"`
	Status   ServerStatus `json:"status"`
	// ControlPlaneKeys is the trusted key set (kid → "ed25519:<base64>"). It must
	// contain the pinned key under the kid that signed this response.
	ControlPlaneKeys map[KeyID]Ed25519Key `json:"controlPlaneKeys"`
	Registry         RegistryInfo         `json:"registry"`
	// Mesh is set when the mesh is enabled (docs/05 §2).
	Mesh          *MeshEnrollment `json:"mesh,omitzero" jsonschema:"nullable"`
	PollIntervals PollIntervals   `json:"pollIntervals"`
	// Nudge is where realtime nudges come from (docs/03 §4); omitted when off.
	// GET /daemon/v1/config returns the current value later.
	Nudge *NudgeConfig `json:"nudge,omitzero" jsonschema:"nullable"`
}

// RegistryInfo tells the daemon where to pull images from.
type RegistryInfo struct {
	// Address the Docker daemon pulls from; always the local forwarder or Zot ("127.0.0.1:5000").
	Address AddrPort `json:"address"`
	// ForwardTo is Zot's mesh address on servers other than the control-plane host.
	ForwardTo AddrPort `json:"forwardTo,omitzero"`
}

// MeshEnrollment carries the Headscale pre-auth key. Mesh membership grants no authority.
type MeshEnrollment struct {
	LoginServer HTTPSURL `json:"loginServer"`
	// PreAuthKey is sealed to the daemon's X25519 key.
	PreAuthKey Sealed         `json:"preAuthKey"`
	Tags       []TailscaleTag `json:"tags"`
}

// PollIntervals are the fallback polling intervals (jittered by the daemon).
type PollIntervals struct {
	State Duration `json:"state"`
	Tasks Duration `json:"tasks"`
}

// Validate checks the response.
func (r *EnrollResponse) Validate() error {
	var e errs
	r.check(&e, KindEnrollResponse)
	e.check("serverId", r.ServerID)
	e.check("status", r.Status)
	if len(r.ControlPlaneKeys) == 0 || len(r.ControlPlaneKeys) > 16 {
		e.addf("controlPlaneKeys", "must contain 1-16 keys")
	}
	for kid, k := range r.ControlPlaneKeys {
		e.check("controlPlaneKeys.kid", kid)
		e.check("controlPlaneKeys["+string(kid)+"]", k)
	}
	e.check("registry.address", r.Registry.Address)
	if r.Registry.ForwardTo != "" {
		e.check("registry.forwardTo", r.Registry.ForwardTo)
	}
	if r.Mesh != nil {
		e.check("mesh.loginServer", r.Mesh.LoginServer)
		e.check("mesh.preAuthKey", r.Mesh.PreAuthKey)
		for i, t := range r.Mesh.Tags {
			e.check(idx("mesh.tags", i), t)
		}
	}
	if r.Nudge != nil {
		r.Nudge.validate(&e, "nudge")
	}
	for name, d := range map[string]Duration{"pollIntervals.state": r.PollIntervals.State, "pollIntervals.tasks": r.PollIntervals.Tasks} {
		e.check(name, d)
		if dd := d.Std(); dd != 0 && (dd < time.Second || dd > 10*time.Minute) {
			e.addf(name, "must be between 1s and 10m")
		}
	}
	return e.err()
}

// ---- Tasks (docs/03 §4, §7) ----

// LeaseRequest asks for up to Max tasks of the given types.
type LeaseRequest struct {
	TypeMeta
	Max   int        `json:"max" jsonschema:"minimum=1,maximum=16"`
	Types []TaskType `json:"types" jsonschema:"minItems=1"`
}

// Validate checks the request.
func (r *LeaseRequest) Validate() error {
	var e errs
	r.check(&e, KindLeaseRequest)
	if r.Max < 1 || r.Max > 16 {
		e.addf("max", "must be between 1 and 16")
	}
	checkCount(&e, "types", len(r.Types), 1, len(taskTypes))
	for i, t := range r.Types {
		e.check(idx("types", i), t)
	}
	unique(&e, "types", r.Types)
	return e.err()
}

// TaskLease is the signed response to a lease request (0..n tasks).
type TaskLease struct {
	TypeMeta
	Tasks []Task `json:"tasks"`
}

// Validate checks every task.
func (l *TaskLease) Validate() error {
	var e errs
	l.check(&e, KindTaskLease)
	checkCount(&e, "tasks", len(l.Tasks), 0, 16)
	ids := make([]TaskID, 0, len(l.Tasks))
	for i := range l.Tasks {
		l.Tasks[i].validate(&e, idx("tasks", i)+".")
		ids = append(ids, l.Tasks[i].ID)
	}
	unique(&e, "tasks.id", ids)
	return e.err()
}

// TaskHeartbeat extends a lease.
type TaskHeartbeat struct {
	TypeMeta
	Attempt int `json:"attempt" jsonschema:"minimum=1"`
	// Progress is an optional short status line for the UI.
	Progress string `json:"progress,omitzero" jsonschema:"maxLength=256"`
}

// Validate checks the heartbeat.
func (h *TaskHeartbeat) Validate() error {
	var e errs
	h.check(&e, KindHeartbeat)
	if h.Attempt < 1 {
		e.addf("attempt", "must be >= 1")
	}
	checkText(&e, "progress", h.Progress, 256)
	return e.err()
}

// TaskHeartbeatResult is the signed response to a heartbeat.
type TaskHeartbeatResult struct {
	TypeMeta
	LeaseExpiresAt time.Time `json:"leaseExpiresAt"`
	// Cancel asks the daemon to stop the task and complete it as cancelled.
	Cancel bool `json:"cancel"`
}

// Validate checks the result.
func (h *TaskHeartbeatResult) Validate() error {
	var e errs
	h.check(&e, KindHeartbeatResult)
	if h.LeaseExpiresAt.IsZero() {
		e.addf("leaseExpiresAt", "is required")
	}
	return e.err()
}

// TaskCompletion reports the outcome of one attempt. Completion is idempotent
// by (task id, attempt); the client also sends Idempotency-Key "<taskId>:<attempt>".
type TaskCompletion struct {
	TypeMeta
	Attempt int         `json:"attempt" jsonschema:"minimum=1"`
	Outcome TaskOutcome `json:"outcome"`
	// Error is a short, secret-free failure description.
	Error string `json:"error,omitzero" jsonschema:"maxLength=4096"`
	// Build is set for successful build tasks.
	Build *BuildResult `json:"build,omitzero" jsonschema:"nullable"`
	// Plan is set for successful plan tasks.
	Plan *PlanResult `json:"plan,omitzero" jsonschema:"nullable"`
	// Failure classifies a failed build or plan task (docs/04 §6).
	Failure *TaskFailure `json:"failure,omitzero" jsonschema:"nullable"`
	// RunOnce is set for run_once tasks that ran.
	RunOnce *RunOnceResult `json:"runOnce,omitzero" jsonschema:"nullable"`
	// Backup is set for successful backup tasks.
	Backup *BackupResult `json:"backup,omitzero" jsonschema:"nullable"`
	// Restore is set for successful restore tasks.
	Restore *RestoreResult `json:"restore,omitzero" jsonschema:"nullable"`
	// Snapshots is set for successful backup_snapshots tasks.
	Snapshots *BackupSnapshotsResult `json:"snapshots,omitzero" jsonschema:"nullable"`
	// Maintenance is set for successful backup_maintenance tasks.
	Maintenance *MaintenanceResult `json:"maintenance,omitzero" jsonschema:"nullable"`
	// SelfUpdate is set for self_update tasks, succeeded or failed.
	SelfUpdate *SelfUpdateResult `json:"selfUpdate,omitzero" jsonschema:"nullable"`
	// Verify is the result of a verify_restore task.
	Verify *VerifyResult `json:"verify,omitzero" jsonschema:"nullable"`
	// Session is set for finished session tasks (docs/03 §11).
	Session *SessionResult `json:"session,omitzero" jsonschema:"nullable"`
	// Inspection is set for plan tasks with inspect, succeeded or failed,
	// once the checkout exists (docs/20 §2).
	Inspection *RepoInspection `json:"inspection,omitzero" jsonschema:"nullable"`
	// DataImport is set for db_import and volume_import tasks that succeeded (D55).
	DataImport *DataImportResult `json:"dataImport,omitzero" jsonschema:"nullable"`
	// DataExport is set for db_export and volume_export tasks that succeeded (D55).
	DataExport *DataExportResult `json:"dataExport,omitzero" jsonschema:"nullable"`
}

// SelfUpdateResult describes a finished self_update (docs/11 §4).
type SelfUpdateResult struct {
	Scope       UpdateScope `json:"scope"`
	FromVersion Semver      `json:"fromVersion"`
	ToVersion   Semver      `json:"toVersion"`
	// RolledBack is true when the new version was installed but did not
	// become healthy and the previous version was restored.
	RolledBack bool `json:"rolledBack"`
}

func (r *SelfUpdateResult) validate(e *errs) {
	e.check("selfUpdate.scope", r.Scope)
	e.check("selfUpdate.fromVersion", r.FromVersion)
	e.check("selfUpdate.toVersion", r.ToVersion)
}

// BuildResult is the outcome of a build.
type BuildResult struct {
	// Image is the pushed image, digest-pinned in the spec's repository
	// ("127.0.0.1:5000/apps/<service>@sha256:…"). The digest is that of the
	// image index, which also carries the SBOM and provenance attestations.
	Image      ImageRef `json:"image"`
	DurationMs int64    `json:"durationMs" jsonschema:"minimum=0"`
	// Summary is what was detected and applied ("we detected…", docs/04 §4).
	Summary *BuildSummary `json:"summary,omitzero" jsonschema:"nullable"`
	// SecretsHash is SHA-256 over the sorted NAME=value pairs of the build-scoped
	// variables (part of the build key, docs/04 §7). Omitted without build variables.
	SecretsHash SHA256Hex `json:"secretsHash,omitzero"`
	// Attestations attached to the image index.
	Attestations []AttestationKind `json:"attestations,omitzero" jsonschema:"nullable"`
}

// RunOnceResult is the outcome of a one-off container.
type RunOnceResult struct {
	// ExitCode is the container's exit code, or 0 when launchroomd stopped
	// it after a passing probe.
	ExitCode   int   `json:"exitCode"`
	DurationMs int64 `json:"durationMs" jsonschema:"minimum=0"`
	// Probe is the outcome of the spec's start check (docs/20 §5).
	Probe *ProbeOutcome `json:"probe,omitzero" jsonschema:"nullable"`
}

// ProbeOutcome is what a run_once start check found.
type ProbeOutcome struct {
	// Listening: the container accepted TCP connections on the port (and the
	// HTTP path answered below 500, when one was given).
	Listening bool  `json:"listening"`
	AfterMs   int64 `json:"afterMs" jsonschema:"minimum=0"`
	// HTTPStatus is the status of the HTTP check, if one ran.
	HTTPStatus int `json:"httpStatus,omitzero" jsonschema:"minimum=100,maximum=599"`
	// Exited: the container stopped before the check passed.
	Exited bool `json:"exited"`
	// ListeningPorts are the TCP ports the process did open (when not listening).
	ListeningPorts []Port `json:"listeningPorts,omitzero" jsonschema:"nullable,maxItems=16"`
}

func (p *ProbeOutcome) validate(e *errs) {
	if p.AfterMs < 0 {
		e.addf("runOnce.probe.afterMs", "must be >= 0")
	}
	if p.HTTPStatus != 0 && (p.HTTPStatus < 100 || p.HTTPStatus > 599) {
		e.addf("runOnce.probe.httpStatus", "must be 100-599")
	}
	checkCount(e, "runOnce.probe.listeningPorts", len(p.ListeningPorts), 0, maxProbePorts)
	for i, port := range p.ListeningPorts {
		e.check(idx("runOnce.probe.listeningPorts", i), port)
	}
}

// Validate checks the completion.
func (c *TaskCompletion) Validate() error {
	var e errs
	c.check(&e, KindTaskCompletion)
	if c.Attempt < 1 {
		e.addf("attempt", "must be >= 1")
	}
	e.check("outcome", c.Outcome)
	checkText(&e, "error", c.Error, maxMessageLen)
	if c.Build != nil {
		c.Build.validate(&e)
	}
	if c.Plan != nil {
		c.Plan.validate(&e)
	}
	if c.RunOnce != nil && c.RunOnce.Probe != nil {
		c.RunOnce.Probe.validate(&e)
	}
	if c.Inspection != nil {
		c.Inspection.validate(&e)
	}
	if c.Failure != nil {
		c.Failure.validate(&e)
		if c.Outcome == OutcomeSucceeded {
			e.addf("failure", "is only valid for failed tasks")
		}
	}
	if c.RunOnce != nil && c.RunOnce.DurationMs < 0 {
		e.addf("runOnce.durationMs", "must be >= 0")
	}
	if c.Backup != nil {
		c.Backup.validate(&e)
	}
	if c.Restore != nil {
		c.Restore.validate(&e)
	}
	if c.Snapshots != nil {
		c.Snapshots.validate(&e)
	}
	if c.Maintenance != nil {
		c.Maintenance.validate(&e)
	}
	if c.SelfUpdate != nil {
		c.SelfUpdate.validate(&e)
	}
	if c.Verify != nil {
		c.Verify.validate(&e)
	}
	if c.Session != nil {
		c.Session.validate(&e)
	}
	if c.DataImport != nil {
		c.DataImport.validate(&e)
	}
	if c.DataExport != nil {
		c.DataExport.validate(&e)
	}
	return e.err()
}

// ---- Reports (docs/03 §9) ----

// Report is the daemon's observed state. Seq is monotonic per daemon; the
// dashboard ignores reports older than the last applied one per workload.
type Report struct {
	TypeMeta
	ServerID   ServerID  `json:"serverId"`
	ObservedAt time.Time `json:"observedAt"`
	Seq        uint64    `json:"seq" jsonschema:"minimum=1"`
	// Full is true for a complete snapshot (always after reconnecting), false for deltas.
	Full bool `json:"full"`
	// AppliedVersion is the NodeState version the daemon is converging to (0 = none yet).
	AppliedVersion int64            `json:"appliedVersion" jsonschema:"minimum=0"`
	Workloads      []WorkloadStatus `json:"workloads"`
	// Host carries current host facts (resources, Docker version).
	Host *HostStatus `json:"host,omitzero" jsonschema:"nullable"`
	// Ingress is the state of the local Caddy (edge servers; omitted when Caddy is disabled).
	Ingress *IngressStatus `json:"ingress,omitzero" jsonschema:"nullable"`
	// StateRejected is set while the newest NodeState the dashboard served was
	// rejected (it did not decode or validate); launchroomd keeps converging to
	// its last good state (appliedVersion). Omitted otherwise.
	StateRejected *Rejection `json:"stateRejected,omitzero" jsonschema:"nullable"`
	// VolumeDeletions are the outcomes of the current NodeState's deleteVolumes. Omitted when none were requested.
	VolumeDeletions []VolumeDeletionResult `json:"volumeDeletions,omitzero" jsonschema:"nullable"`
	// Metrics are per-minute usage aggregates not delivered yet; omitted when there are none.
	Metrics *ReportMetrics `json:"metrics,omitzero" jsonschema:"nullable"`
	// WalArchives is the WAL shipping state of every workload with a
	// walArchive (point-in-time recovery); omitted when there is none.
	WalArchives []WalArchiveStatus `json:"walArchives,omitzero" jsonschema:"nullable,maxItems=256"`
}

// Rejection describes a NodeState launchroomd refused (Report.stateRejected).
type Rejection struct {
	// Version of the rejected document (0 when it could not be read).
	Version int64 `json:"version" jsonschema:"minimum=0"`
	// Error is the validation error (secret-free; documents carry sealed values only).
	Error      string    `json:"error" jsonschema:"maxLength=4096"`
	RejectedAt time.Time `json:"rejectedAt"`
}

// VolumeDeletionResult is the outcome of one NodeState.deleteVolumes entry.
type VolumeDeletionResult struct {
	ID      VolumeID              `json:"id"`
	Name    HostName              `json:"name"`
	Outcome VolumeDeletionOutcome `json:"outcome"`
	// Message explains refused and failed outcomes.
	Message string    `json:"message,omitzero" jsonschema:"maxLength=1024"`
	At      time.Time `json:"at"`
}

// HostStatus describes the server at report time.
type HostStatus struct {
	CPUs int `json:"cpus" jsonschema:"minimum=1,maximum=4096"`
	// MemoryMB is total host memory as seen by Docker (0 if unknown).
	MemoryMB int `json:"memoryMb" jsonschema:"minimum=0"`
	// DiskTotalMB and DiskFreeMB describe the filesystem of the daemon data dir (0 if unknown).
	DiskTotalMB int64 `json:"diskTotalMb" jsonschema:"minimum=0"`
	DiskFreeMB  int64 `json:"diskFreeMb" jsonschema:"minimum=0"`
	// DockerVersion is the Docker Engine version; empty if Docker is unreachable.
	DockerVersion Label  `json:"dockerVersion,omitzero"`
	DaemonVersion Semver `json:"daemonVersion"`
	// Health is the latest periodic host health snapshot (collected every few
	// minutes, repeated in every report until the next one).
	Health *HostHealth `json:"health,omitzero" jsonschema:"nullable"`
	// Mesh is the mesh client's state (docs/05 §2); omitted when the mesh is off.
	Mesh *MeshStatus `json:"mesh,omitzero" jsonschema:"nullable"`
	// Hardware describes the machine; sent when it changed or was not
	// acknowledged yet, and at least hourly (omitted otherwise).
	Hardware *HardwareFacts `json:"hardware,omitzero" jsonschema:"nullable"`
	// Links are the latest private-network measurements to the peers of
	// system.linkProbe (docs/05 §2.1); omitted when there are none.
	Links []LinkMeasurement `json:"links,omitzero" jsonschema:"nullable,maxItems=256"`
	// Features are the optional protocol features this launchroomd
	// understands (SupportedFeatures); the dashboard sends optional
	// NodeState fields only to servers that listed them.
	Features []DaemonFeature `json:"features,omitzero" jsonschema:"nullable,maxItems=64,uniqueItems=true"`
	// ControlPlane says which dashboard URL launchroomd uses: its configured
	// fallback or the adopted canonical origin (docs/03 §4.2).
	ControlPlane *ControlPlaneStatus `json:"controlPlane,omitzero" jsonschema:"nullable"`
	// Runtime is how launchroomd runs here (rootless Docker, dockerd-guard)
	// and the resulting capability matrix (D49); omitted until known.
	Runtime *HostRuntime `json:"runtime,omitzero" jsonschema:"nullable"`
}

// IngressStatus reports whether the rendered route table is live in Caddy.
type IngressStatus struct {
	State IngressState `json:"state"`
	// Routes is the number of routes in the applied config.
	Routes int `json:"routes" jsonschema:"minimum=0"`
	// AppliedAt is when the current config was loaded into Caddy.
	AppliedAt *time.Time `json:"appliedAt,omitzero" jsonschema:"nullable"`
	// Message is a short, secret-free explanation when state is not applied.
	Message string `json:"message,omitzero" jsonschema:"maxLength=4096"`
	// Certificates is the certificate state of every routed host (docs/05 §5).
	Certificates []CertificateStatus `json:"certificates,omitzero" jsonschema:"nullable,maxItems=4096"`
	// Storage reports where certificates are kept (shared storage, local, degraded).
	Storage *CertStorageStatus `json:"storage,omitzero" jsonschema:"nullable"`
}

// ProbeStatus is the result of the daemon's external health probe.
type ProbeStatus struct {
	State ProbeState `json:"state"`
	// ConsecutiveFailures counts failures after the start period.
	ConsecutiveFailures int        `json:"consecutiveFailures" jsonschema:"minimum=0"`
	LastCheckedAt       *time.Time `json:"lastCheckedAt,omitzero" jsonschema:"nullable"`
	// LastError is the last probe failure (e.g. "connection refused", "HTTP 500").
	LastError string `json:"lastError,omitzero" jsonschema:"maxLength=1024"`
}

// WorkloadStatus is the observed state of one workload.
type WorkloadStatus struct {
	ID         WorkloadID    `json:"id"`
	Phase      WorkloadPhase `json:"phase"`
	ObservedAt time.Time     `json:"observedAt"`
	// ContainerID is the Docker container id (64 hex) if one exists.
	ContainerID  string     `json:"containerId,omitzero" jsonschema:"pattern=^[a-f0-9]{64}$"`
	StartedAt    *time.Time `json:"startedAt,omitzero" jsonschema:"nullable"`
	ExitCode     *int       `json:"exitCode,omitzero" jsonschema:"nullable"`
	RestartCount int        `json:"restartCount" jsonschema:"minimum=0"`
	// Message is a short, secret-free explanation (e.g. probe failure).
	Message string `json:"message,omitzero" jsonschema:"maxLength=4096"`
	// DetectedPorts are TCP ports the container listens on (port discovery).
	// Omitted when discovery is not possible.
	DetectedPorts []Port `json:"detectedPorts,omitzero" jsonschema:"nullable"`
	// Health is the external probe result; omitted for workloads without a probe.
	Health *ProbeStatus `json:"health,omitzero" jsonschema:"nullable"`
	// Pull is the progress of the image pull while the phase is pulling.
	Pull *PullProgress `json:"pull,omitzero" jsonschema:"nullable"`
}

// PullProgress is an in-flight image pull: compressed layer bytes downloaded
// of the total (0 while the total is not known, e.g. while mirroring).
type PullProgress struct {
	Stage           PullStage `json:"stage"`
	DownloadedBytes int64     `json:"downloadedBytes" jsonschema:"minimum=0"`
	TotalBytes      int64     `json:"totalBytes" jsonschema:"minimum=0"`
}

// Validate checks the report.
func (r *Report) Validate() error {
	var e errs
	r.check(&e, KindReport)
	e.check("serverId", r.ServerID)
	if r.ObservedAt.IsZero() {
		e.addf("observedAt", "is required")
	}
	if r.Seq < 1 {
		e.addf("seq", "must be >= 1")
	}
	if r.AppliedVersion < 0 {
		e.addf("appliedVersion", "must be >= 0")
	}
	checkCount(&e, "workloads", len(r.Workloads), 0, maxListItems)
	ids := make([]WorkloadID, 0, len(r.Workloads))
	for i, w := range r.Workloads {
		p := idx("workloads", i)
		e.check(p+".id", w.ID)
		e.check(p+".phase", w.Phase)
		if w.ObservedAt.IsZero() {
			e.addf(p+".observedAt", "is required")
		}
		if w.ContainerID != "" && !reContainerID.MatchString(w.ContainerID) {
			e.addf(p+".containerId", "must be 64 lowercase hex characters")
		}
		if w.RestartCount < 0 {
			e.addf(p+".restartCount", "must be >= 0")
		}
		checkText(&e, p+".message", w.Message, maxMessageLen)
		checkCount(&e, p+".detectedPorts", len(w.DetectedPorts), 0, maxDetectedPortCount)
		for j, port := range w.DetectedPorts {
			e.check(idx(p+".detectedPorts", j), port)
		}
		if h := w.Health; h != nil {
			e.check(p+".health.state", h.State)
			if h.ConsecutiveFailures < 0 {
				e.addf(p+".health.consecutiveFailures", "must be >= 0")
			}
			checkText(&e, p+".health.lastError", h.LastError, maxProbeErrorLen)
		}
		if pp := w.Pull; pp != nil {
			e.check(p+".pull.stage", pp.Stage)
			if pp.DownloadedBytes < 0 || pp.TotalBytes < 0 {
				e.addf(p+".pull", "sizes must be >= 0")
			}
		}
		ids = append(ids, w.ID)
	}
	unique(&e, "workloads.id", ids)
	if h := r.Host; h != nil {
		if h.CPUs < 1 || h.CPUs > 4096 {
			e.addf("host.cpus", "must be between 1 and 4096")
		}
		if h.MemoryMB < 0 || h.DiskTotalMB < 0 || h.DiskFreeMB < 0 {
			e.addf("host", "sizes must be >= 0")
		}
		if h.DockerVersion != "" {
			e.check("host.dockerVersion", h.DockerVersion)
		}
		e.check("host.daemonVersion", h.DaemonVersion)
		if h.Health != nil {
			h.Health.validate(&e, "host.health")
		}
		if h.Mesh != nil {
			h.Mesh.validate(&e, "host.mesh")
		}
		if h.Hardware != nil {
			h.Hardware.validate(&e, "host.hardware")
		}
		validateLinks(&e, "host.links", h.Links)
		validateFeatures(&e, "host.features", h.Features)
		if h.ControlPlane != nil {
			h.ControlPlane.validate(&e, "host.controlPlane")
		}
		if h.Runtime != nil {
			h.Runtime.validate(&e, "host.runtime")
		}
	}
	if m := r.Metrics; m != nil {
		m.validate(&e, "metrics")
	}
	if in := r.Ingress; in != nil {
		e.check("ingress.state", in.State)
		if in.Routes < 0 {
			e.addf("ingress.routes", "must be >= 0")
		}
		checkText(&e, "ingress.message", in.Message, maxMessageLen)
		in.validateCerts(&e)
	}
	if sr := r.StateRejected; sr != nil {
		if sr.Version < 0 {
			e.addf("stateRejected.version", "must be >= 0")
		}
		checkText(&e, "stateRejected.error", sr.Error, maxMessageLen)
		if sr.RejectedAt.IsZero() {
			e.addf("stateRejected.rejectedAt", "is required")
		}
	}
	checkCount(&e, "volumeDeletions", len(r.VolumeDeletions), 0, maxListItems)
	delIDs := make([]VolumeID, 0, len(r.VolumeDeletions))
	for i, d := range r.VolumeDeletions {
		p := idx("volumeDeletions", i)
		e.check(p+".id", d.ID)
		e.check(p+".name", d.Name)
		e.check(p+".outcome", d.Outcome)
		checkText(&e, p+".message", d.Message, 1024)
		if d.At.IsZero() {
			e.addf(p+".at", "is required")
		}
		delIDs = append(delIDs, d.ID)
	}
	unique(&e, "volumeDeletions.id", delIDs)
	checkCount(&e, "walArchives", len(r.WalArchives), 0, maxWalArchives)
	walIDs := make([]WorkloadID, 0, len(r.WalArchives))
	for i := range r.WalArchives {
		r.WalArchives[i].validate(&e, idx("walArchives", i))
		walIDs = append(walIDs, r.WalArchives[i].Workload)
	}
	unique(&e, "walArchives.workload", walIDs)
	return e.err()
}

// ---- Logs ----

// LogBatch ships log chunks (docs/03 §4).
type LogBatch struct {
	TypeMeta
	Chunks []LogChunk `json:"chunks" jsonschema:"minItems=1"`
}

// LogChunk is a run of lines from one source; Seq is monotonic per source.
type LogChunk struct {
	Source LogSource `json:"source"`
	Seq    uint64    `json:"seq" jsonschema:"minimum=1"`
	Lines  []LogLine `json:"lines" jsonschema:"minItems=1"`
}

// LogSource names what the lines belong to. ID is a task, deployment or workload id per Kind.
type LogSource struct {
	Kind LogSourceKind `json:"kind"`
	ID   string        `json:"id" jsonschema:"pattern=^(?:tsk|dpl|wl)_[0-9A-HJKMNP-TV-Z]{26}$"`
}

// LogLine is one line. Text is already masked for known secret values.
type LogLine struct {
	Time   time.Time `json:"time"`
	Stream LogStream `json:"stream"`
	Text   string    `json:"text"`
	// Step groups build log lines into collapsible steps ("clone", "plan",
	// "install", "build", "push", …). Omitted for runtime logs.
	Step string `json:"step,omitzero" jsonschema:"maxLength=128"`
}

// Validate checks the batch.
func (b *LogBatch) Validate() error {
	var e errs
	b.check(&e, KindLogBatch)
	checkCount(&e, "chunks", len(b.Chunks), 1, 256)
	for i, c := range b.Chunks {
		p := idx("chunks", i)
		e.check(p+".source.kind", c.Source.Kind)
		switch c.Source.Kind {
		case LogSourceTask:
			e.check(p+".source.id", TaskID(c.Source.ID))
		case LogSourceDeployment:
			e.check(p+".source.id", DeploymentID(c.Source.ID))
		case LogSourceWorkload:
			e.check(p+".source.id", WorkloadID(c.Source.ID))
		}
		if c.Seq < 1 {
			e.addf(p+".seq", "must be >= 1")
		}
		checkCount(&e, p+".lines", len(c.Lines), 1, maxLogLines)
		for j, l := range c.Lines {
			lp := idx(p+".lines", j)
			if l.Time.IsZero() {
				e.addf(lp+".time", "is required")
			}
			e.check(lp+".stream", l.Stream)
			checkText(&e, lp+".text", l.Text, maxLogLineLen)
			checkText(&e, lp+".step", l.Step, 128)
		}
	}
	return e.err()
}

// errNotSigned is returned when a document that must be signed has no signature.
var errNotSigned = errors.New("state: document is not signed")
