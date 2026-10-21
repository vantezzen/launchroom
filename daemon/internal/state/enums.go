package state

// Enumerations of the v1 protocol. Each type lists its values once; Validate
// and the generated schema both use that list.

// WorkloadRole tells the daemon whether a workload should receive traffic.
type WorkloadRole string

// Workload roles.
const (
	RoleServing  WorkloadRole = "serving"
	RoleDraining WorkloadRole = "draining" // stop after drainUntil
)

var workloadRoles = []string{string(RoleServing), string(RoleDraining)}

// ProtocolSchema implements SchemaDescriber.
func (WorkloadRole) ProtocolSchema() ScalarSchema { return enumSchema(workloadRoles) }

// Validate checks the value.
func (v WorkloadRole) Validate() error { return checkEnum("role", string(v), workloadRoles) }

// RestartPolicy mirrors Docker restart policies.
type RestartPolicy string

// Restart policies.
const (
	RestartNo            RestartPolicy = "no"
	RestartAlways        RestartPolicy = "always"
	RestartUnlessStopped RestartPolicy = "unless-stopped"
	RestartOnFailure     RestartPolicy = "on-failure"
)

var restartPolicies = []string{string(RestartNo), string(RestartAlways), string(RestartUnlessStopped), string(RestartOnFailure)}

// ProtocolSchema implements SchemaDescriber.
func (RestartPolicy) ProtocolSchema() ScalarSchema { return enumSchema(restartPolicies) }

// Validate checks the value.
func (v RestartPolicy) Validate() error {
	return checkEnum("restart policy", string(v), restartPolicies)
}

// StopSignal is the signal a workload is stopped with (WorkloadSpec.stop).
// Only the graceful-shutdown signals of the database engines exist: SIGTERM
// (MySQL, Redis/Valkey; Postgres' "smart" shutdown) and SIGINT (Postgres'
// "fast" shutdown).
type StopSignal string

// Stop signals.
const (
	SignalTerm StopSignal = "SIGTERM"
	SignalInt  StopSignal = "SIGINT"
)

var stopSignals = []string{string(SignalTerm), string(SignalInt)}

// ProtocolSchema implements SchemaDescriber.
func (StopSignal) ProtocolSchema() ScalarSchema { return enumSchema(stopSignals) }

// Validate checks the value.
func (v StopSignal) Validate() error { return checkEnum("stop signal", string(v), stopSignals) }

// HealthType selects the external health probe.
type HealthType string

// Health probe types.
const (
	HealthHTTP HealthType = "http"
	HealthTCP  HealthType = "tcp"
)

var healthTypes = []string{string(HealthHTTP), string(HealthTCP)}

// ProtocolSchema implements SchemaDescriber.
func (HealthType) ProtocolSchema() ScalarSchema { return enumSchema(healthTypes) }

// Validate checks the value.
func (v HealthType) Validate() error { return checkEnum("health type", string(v), healthTypes) }

// TLSMode selects how Caddy obtains a certificate for a route (docs/05 §5).
type TLSMode string

// TLS modes.
const (
	TLSACME     TLSMode = "acme"      // managed per-host certificate (HTTP-01 / TLS-ALPN-01)
	TLSOnDemand TLSMode = "on_demand" // custom domains; approved by the local daemon's ask endpoint
	TLSInternal TLSMode = "internal"  // Caddy internal CA (local dev, *.localhost)
	// TLSWildcard: the host is served by the DNS-01 wildcard certificate
	// "*.<host minus its first label>" (NodeState.ingress.dns). Without a DNS
	// provider for that parent the daemon falls back to "acme".
	TLSWildcard TLSMode = "wildcard"
)

var tlsModes = []string{string(TLSACME), string(TLSOnDemand), string(TLSInternal), string(TLSWildcard)}

// ProtocolSchema implements SchemaDescriber.
func (TLSMode) ProtocolSchema() ScalarSchema { return enumSchema(tlsModes) }

// Validate checks the value.
func (v TLSMode) Validate() error { return checkEnum("tls mode", string(v), tlsModes) }

// TaskType discriminates task specs.
type TaskType string

// Task types (docs/03 §7, docs/14 §7).
const (
	TaskBuild             TaskType = "build"
	TaskPlan              TaskType = "plan"
	TaskRunOnce           TaskType = "run_once"
	TaskBackup            TaskType = "backup"
	TaskRestore           TaskType = "restore"
	TaskBackupMaintenance TaskType = "backup_maintenance"
	TaskLogs              TaskType = "logs"
	TaskSelfUpdate        TaskType = "self_update"
	// TaskBackupSnapshots lists a repository's snapshots (restic snapshots --json).
	TaskBackupSnapshots TaskType = "backup_snapshots"
	// TaskSession runs an interactive console or data session (docs/03 §11).
	TaskSession TaskType = "session"
	// TaskVerifyRestore restores a snapshot into a throwaway container and checks it (docs/14 §10.4).
	TaskVerifyRestore TaskType = "verify_restore"
	// Data import and export (D55, docs/14 §12; daemon feature "dataTransfer").
	TaskDbImport     TaskType = "db_import"
	TaskVolumeImport TaskType = "volume_import"
	TaskDbExport     TaskType = "db_export"
	TaskVolumeExport TaskType = "volume_export"
)

var taskTypes = []string{
	string(TaskBuild), string(TaskPlan), string(TaskRunOnce), string(TaskBackup),
	string(TaskRestore), string(TaskBackupMaintenance), string(TaskLogs), string(TaskSelfUpdate),
	string(TaskBackupSnapshots), string(TaskVerifyRestore),
	string(TaskSession),
	string(TaskDbImport), string(TaskVolumeImport), string(TaskDbExport), string(TaskVolumeExport),
}

// TaskTypes returns all task types in protocol order.
func TaskTypes() []TaskType {
	out := make([]TaskType, len(taskTypes))
	for i, t := range taskTypes {
		out[i] = TaskType(t)
	}
	return out
}

// ProtocolSchema implements SchemaDescriber.
func (TaskType) ProtocolSchema() ScalarSchema { return enumSchema(taskTypes) }

// Validate checks the value.
func (v TaskType) Validate() error { return checkEnum("task type", string(v), taskTypes) }

// UpdateScope is what a self_update task updates (docs/11 §4).
type UpdateScope string

// Update scopes.
const (
	// UpdateDaemon updates launchroomd on a server that is not the control-plane host.
	UpdateDaemon UpdateScope = "daemon"
	// UpdateControlPlane updates the control-plane stack (dashboard, workers,
	// databases' pins, the local launchroomd) on the control-plane host.
	UpdateControlPlane UpdateScope = "control_plane"
)

var updateScopes = []string{string(UpdateDaemon), string(UpdateControlPlane)}

// ProtocolSchema implements SchemaDescriber.
func (UpdateScope) ProtocolSchema() ScalarSchema { return enumSchema(updateScopes) }

// Validate checks the value.
func (v UpdateScope) Validate() error { return checkEnum("update scope", string(v), updateScopes) }

// BuilderKind is the builder chosen for a build (docs/04 §2). "auto" lets the
// daemon resolve it after cloning (Dockerfile present → dockerfile, otherwise
// railpack with presets); the outcome is reported, and the dashboard pins it
// for later builds. A builder is never switched silently.
type BuilderKind string

// Builder kinds.
const (
	BuilderAuto       BuilderKind = "auto"
	BuilderDockerfile BuilderKind = "dockerfile"
	BuilderRailpack   BuilderKind = "railpack"
)

var builderKinds = []string{string(BuilderAuto), string(BuilderDockerfile), string(BuilderRailpack)}

// ProtocolSchema implements SchemaDescriber.
func (BuilderKind) ProtocolSchema() ScalarSchema { return enumSchema(builderKinds) }

// Validate checks the value.
func (v BuilderKind) Validate() error { return checkEnum("builder", string(v), builderKinds) }

// GitProvider is the source host of a repository (docs/19): "github" (GitHub
// App installation token as clone credential), "gitlab" and "gitea"
// (Gitea/Forgejo; the connection's OAuth/access token), or "git" (any git
// server over https or ssh; no credential, or a deploy key for ssh). The
// provider picks the Basic-auth user for clone tokens.
type GitProvider string

// Git providers.
const (
	GitHub     GitProvider = "github"
	GitLab     GitProvider = "gitlab"
	Gitea      GitProvider = "gitea"
	GitGeneric GitProvider = "git"
)

var gitProviders = []string{string(GitHub), string(GitLab), string(Gitea), string(GitGeneric)}

// ProtocolSchema implements SchemaDescriber.
func (GitProvider) ProtocolSchema() ScalarSchema { return enumSchema(gitProviders) }

// Validate checks the value.
func (v GitProvider) Validate() error { return checkEnum("git provider", string(v), gitProviders) }

// Platform is a build target platform.
type Platform string

// Platforms.
const (
	PlatformAMD64 Platform = "linux/amd64"
	PlatformARM64 Platform = "linux/arm64"
)

var platforms = []string{string(PlatformAMD64), string(PlatformARM64)}

// ProtocolSchema implements SchemaDescriber.
func (Platform) ProtocolSchema() ScalarSchema { return enumSchema(platforms) }

// Validate checks the value.
func (v Platform) Validate() error { return checkEnum("platform", string(v), platforms) }

// BackupKind is what a backup covers (docs/14 §2).
type BackupKind string

// Backup kinds.
const (
	BackupDatabase     BackupKind = "database"
	BackupVolume       BackupKind = "volume"
	BackupControlPlane BackupKind = "control_plane"
	// BackupBaseBackup is a physical Postgres base backup (pg_basebackup),
	// the starting point of point-in-time recovery (docs/14 §10).
	BackupBaseBackup BackupKind = "base_backup"
)

var backupKinds = []string{string(BackupDatabase), string(BackupVolume), string(BackupControlPlane), string(BackupBaseBackup)}

// ProtocolSchema implements SchemaDescriber.
func (BackupKind) ProtocolSchema() ScalarSchema { return enumSchema(backupKinds) }

// Validate checks the value.
func (v BackupKind) Validate() error { return checkEnum("backup kind", string(v), backupKinds) }

// DatabaseEngine selects the daemon's engine driver; argv is generated by the
// daemon from typed fields, never taken from the dashboard (docs/14 §7).
type DatabaseEngine string

// Database engines.
const (
	EnginePostgres DatabaseEngine = "postgres"
	EngineMySQL    DatabaseEngine = "mysql"
	EngineMariaDB  DatabaseEngine = "mariadb"
	EngineRedis    DatabaseEngine = "redis"
	EngineMongoDB  DatabaseEngine = "mongodb"
	EngineValkey   DatabaseEngine = "valkey"
	// EngineClickHouse: daemon feature "mongoClickhouse" (D47), like EngineMongoDB.
	EngineClickHouse DatabaseEngine = "clickhouse"
)

var databaseEngines = []string{string(EnginePostgres), string(EngineMySQL), string(EngineMariaDB), string(EngineRedis), string(EngineMongoDB), string(EngineValkey), string(EngineClickHouse)}

// ProtocolSchema implements SchemaDescriber.
func (DatabaseEngine) ProtocolSchema() ScalarSchema { return enumSchema(databaseEngines) }

// Validate checks the value.
func (v DatabaseEngine) Validate() error { return checkEnum("engine", string(v), databaseEngines) }

// VolumeConsistency chooses how a volume is backed up (docs/14 §2).
type VolumeConsistency string

// Volume consistency modes.
const (
	ConsistencyLive  VolumeConsistency = "live"
	ConsistencyPause VolumeConsistency = "pause"
)

var volumeConsistencies = []string{string(ConsistencyLive), string(ConsistencyPause)}

// ProtocolSchema implements SchemaDescriber.
func (VolumeConsistency) ProtocolSchema() ScalarSchema { return enumSchema(volumeConsistencies) }

// Validate checks the value.
func (v VolumeConsistency) Validate() error {
	return checkEnum("consistency", string(v), volumeConsistencies)
}

// DestinationKind is a backup destination type.
type DestinationKind string

// Destination kinds.
const (
	DestinationS3    DestinationKind = "s3"
	DestinationLocal DestinationKind = "local" // inside launchroom/data/backups; does not survive the server
)

var destinationKinds = []string{string(DestinationS3), string(DestinationLocal)}

// ProtocolSchema implements SchemaDescriber.
func (DestinationKind) ProtocolSchema() ScalarSchema { return enumSchema(destinationKinds) }

// Validate checks the value.
func (v DestinationKind) Validate() error {
	return checkEnum("destination kind", string(v), destinationKinds)
}

// RestoreMode chooses between a safe restore into a new service and a destructive in-place restore.
type RestoreMode string

// Restore modes (docs/14 §5.1).
const (
	RestoreNewService RestoreMode = "new_service"
	RestoreInPlace    RestoreMode = "in_place"
)

var restoreModes = []string{string(RestoreNewService), string(RestoreInPlace)}

// ProtocolSchema implements SchemaDescriber.
func (RestoreMode) ProtocolSchema() ScalarSchema { return enumSchema(restoreModes) }

// Validate checks the value.
func (v RestoreMode) Validate() error { return checkEnum("restore mode", string(v), restoreModes) }

// MaintenanceOperation is a restic repository maintenance operation.
type MaintenanceOperation string

// Maintenance operations (docs/14 §3).
const (
	MaintenanceForget MaintenanceOperation = "forget"
	MaintenancePrune  MaintenanceOperation = "prune"
	MaintenanceCheck  MaintenanceOperation = "check"
)

var maintenanceOperations = []string{string(MaintenanceForget), string(MaintenancePrune), string(MaintenanceCheck)}

// ProtocolSchema implements SchemaDescriber.
func (MaintenanceOperation) ProtocolSchema() ScalarSchema { return enumSchema(maintenanceOperations) }

// Validate checks the value.
func (v MaintenanceOperation) Validate() error {
	return checkEnum("maintenance operation", string(v), maintenanceOperations)
}

// WorkloadPhase is the observed lifecycle phase of a workload.
type WorkloadPhase string

// Workload phases.
const (
	PhasePending   WorkloadPhase = "pending"
	PhasePulling   WorkloadPhase = "pulling"
	PhaseStarting  WorkloadPhase = "starting"
	PhaseRunning   WorkloadPhase = "running" // running, no probe configured or start period not over
	PhaseHealthy   WorkloadPhase = "healthy"
	PhaseUnhealthy WorkloadPhase = "unhealthy"
	PhaseDraining  WorkloadPhase = "draining"
	PhaseExited    WorkloadPhase = "exited"
	PhaseFailed    WorkloadPhase = "failed" // could not be created/started (e.g. pull or policy failure)
	PhaseRemoved   WorkloadPhase = "removed"
)

var workloadPhases = []string{
	string(PhasePending), string(PhasePulling), string(PhaseStarting), string(PhaseRunning), string(PhaseHealthy),
	string(PhaseUnhealthy), string(PhaseDraining), string(PhaseExited), string(PhaseFailed), string(PhaseRemoved),
}

// ProtocolSchema implements SchemaDescriber.
func (WorkloadPhase) ProtocolSchema() ScalarSchema { return enumSchema(workloadPhases) }

// Validate checks the value.
func (v WorkloadPhase) Validate() error { return checkEnum("phase", string(v), workloadPhases) }

// PullStage is where an in-flight image pull is (docs/04 §11 "Pulls").
type PullStage string

// Pull stages.
const (
	PullMirroring   PullStage = "mirroring"   // the registry mirror fetches the image from upstream
	PullDownloading PullStage = "downloading" // Docker downloads layers from the local registry
	PullExtracting  PullStage = "extracting"  // every layer is downloaded, Docker unpacks them
)

var pullStages = []string{string(PullMirroring), string(PullDownloading), string(PullExtracting)}

// ProtocolSchema implements SchemaDescriber.
func (PullStage) ProtocolSchema() ScalarSchema { return enumSchema(pullStages) }

// Validate checks the value.
func (v PullStage) Validate() error { return checkEnum("pull stage", string(v), pullStages) }

// LogStream identifies where a log line came from.
type LogStream string

// Log streams.
const (
	StreamStdout LogStream = "stdout"
	StreamStderr LogStream = "stderr"
	StreamSystem LogStream = "system" // launchroom's own step messages
)

var logStreams = []string{string(StreamStdout), string(StreamStderr), string(StreamSystem)}

// ProtocolSchema implements SchemaDescriber.
func (LogStream) ProtocolSchema() ScalarSchema { return enumSchema(logStreams) }

// Validate checks the value.
func (v LogStream) Validate() error { return checkEnum("log stream", string(v), logStreams) }

// LogSourceKind is what a log chunk belongs to.
type LogSourceKind string

// Log source kinds.
const (
	LogSourceTask       LogSourceKind = "task"
	LogSourceDeployment LogSourceKind = "deployment"
	LogSourceWorkload   LogSourceKind = "workload"
)

var logSourceKinds = []string{string(LogSourceTask), string(LogSourceDeployment), string(LogSourceWorkload)}

// ProtocolSchema implements SchemaDescriber.
func (LogSourceKind) ProtocolSchema() ScalarSchema { return enumSchema(logSourceKinds) }

// Validate checks the value.
func (v LogSourceKind) Validate() error { return checkEnum("log source", string(v), logSourceKinds) }

// TaskOutcome is the final result of a task attempt.
type TaskOutcome string

// Task outcomes.
const (
	OutcomeSucceeded TaskOutcome = "succeeded"
	OutcomeFailed    TaskOutcome = "failed"
	OutcomeCancelled TaskOutcome = "cancelled"
	// OutcomeReleased hands the lease back without a result: launchroomd is
	// shutting down (restart, upgrade) and the task did not finish within the
	// shutdown grace. The dashboard re-queues it right away (docs/03 §7).
	OutcomeReleased TaskOutcome = "released"
)

var taskOutcomes = []string{string(OutcomeSucceeded), string(OutcomeFailed), string(OutcomeCancelled), string(OutcomeReleased)}

// ProtocolSchema implements SchemaDescriber.
func (TaskOutcome) ProtocolSchema() ScalarSchema { return enumSchema(taskOutcomes) }

// Validate checks the value.
func (v TaskOutcome) Validate() error { return checkEnum("outcome", string(v), taskOutcomes) }

// ServerStatus is the dashboard's view of an enrolled server.
type ServerStatus string

// Server statuses returned at enrollment.
const (
	ServerActive          ServerStatus = "active"
	ServerPendingApproval ServerStatus = "pending_approval"
)

var serverStatuses = []string{string(ServerActive), string(ServerPendingApproval)}

// ProtocolSchema implements SchemaDescriber.
func (ServerStatus) ProtocolSchema() ScalarSchema { return enumSchema(serverStatuses) }

// Validate checks the value.
func (v ServerStatus) Validate() error { return checkEnum("server status", string(v), serverStatuses) }

// ProbeState is the state of a workload's external health probe.
type ProbeState string

// Probe states.
const (
	ProbeStarting ProbeState = "starting" // start period, no success yet
	ProbePassing  ProbeState = "passing"
	ProbeFailing  ProbeState = "failing" // failureThreshold consecutive failures after the start period
)

var probeStates = []string{string(ProbeStarting), string(ProbePassing), string(ProbeFailing)}

// ProtocolSchema implements SchemaDescriber.
func (ProbeState) ProtocolSchema() ScalarSchema { return enumSchema(probeStates) }

// Validate checks the value.
func (v ProbeState) Validate() error { return checkEnum("probe state", string(v), probeStates) }

// IngressState is whether the route table is live in the local Caddy.
type IngressState string

// Ingress states.
const (
	IngressPending IngressState = "pending" // Caddy is starting or the first config is not loaded yet
	IngressApplied IngressState = "applied"
	IngressFailed  IngressState = "failed"
)

var ingressStates = []string{string(IngressPending), string(IngressApplied), string(IngressFailed)}

// ProtocolSchema implements SchemaDescriber.
func (IngressState) ProtocolSchema() ScalarSchema { return enumSchema(ingressStates) }

// Validate checks the value.
func (v IngressState) Validate() error { return checkEnum("ingress state", string(v), ingressStates) }

// FailureClass classifies a failed build or plan (docs/04 §6) so the UI can
// show the right hint and the dashboard knows whether a retry can help.
type FailureClass string

// Failure classes of build and plan tasks.
const (
	// FailurePlan: the builder could not plan the app (Railpack exit 1, nothing detected). Not retried.
	FailurePlan FailureClass = "plan_error"
	// FailureBuild: a build step failed (non-zero exit inside the build). Not retried.
	FailureBuild FailureClass = "build_error"
	// FailureTransient: network, registry or rate-limit trouble (Railpack exit 75). Retry once.
	FailureTransient FailureClass = "transient"
	// FailureClone: the commit could not be fetched (auth, missing SHA, repository gone).
	FailureClone FailureClass = "clone_error"
	// FailureConfig: the spec cannot be built as given (root dir or Dockerfile missing,
	// unavailable Railpack version, a symlink escaping the repository, …).
	FailureConfig FailureClass = "config_error"
	// FailureTimeout: the task deadline passed.
	FailureTimeout FailureClass = "timeout"
	// FailureInternal: a daemon-side problem (BuildKit unavailable, bug). Retry may help.
	FailureInternal FailureClass = "internal_error"
)

var failureClasses = []string{
	string(FailurePlan), string(FailureBuild), string(FailureTransient), string(FailureClone),
	string(FailureConfig), string(FailureTimeout), string(FailureInternal),
}

// ProtocolSchema implements SchemaDescriber.
func (FailureClass) ProtocolSchema() ScalarSchema { return enumSchema(failureClasses) }

// Validate checks the value.
func (v FailureClass) Validate() error { return checkEnum("failure class", string(v), failureClasses) }

// BuilderReason says why a builder was used (shown as "Building with your Dockerfile", …).
type BuilderReason string

// Builder reasons.
const (
	ReasonExplicit          BuilderReason = "explicit"            // the spec named the builder
	ReasonDockerfileFound   BuilderReason = "dockerfile_detected" // auto: a Dockerfile/Containerfile exists
	ReasonPresetAndRailpack BuilderReason = "preset"              // auto: a launchroom preset matched
	ReasonRailpack          BuilderReason = "railpack"            // auto: plain Railpack
)

var builderReasons = []string{string(ReasonExplicit), string(ReasonDockerfileFound), string(ReasonPresetAndRailpack), string(ReasonRailpack)}

// ProtocolSchema implements SchemaDescriber.
func (BuilderReason) ProtocolSchema() ScalarSchema { return enumSchema(builderReasons) }

// Validate checks the value.
func (v BuilderReason) Validate() error {
	return checkEnum("builder reason", string(v), builderReasons)
}

// RegistryAction is an action in a registry token scope (Docker token spec).
type RegistryAction string

// Registry actions.
const (
	RegistryPull RegistryAction = "pull"
	RegistryPush RegistryAction = "push"
)

var registryActions = []string{string(RegistryPull), string(RegistryPush)}

// ProtocolSchema implements SchemaDescriber.
func (RegistryAction) ProtocolSchema() ScalarSchema { return enumSchema(registryActions) }

// Validate checks the value.
func (v RegistryAction) Validate() error {
	return checkEnum("registry action", string(v), registryActions)
}

// HostKeyType is the algorithm of a pinned SSH host key (RepoSpec.knownHosts).
type HostKeyType string

// Host key types.
const (
	HostKeyEd25519  HostKeyType = "ssh-ed25519"
	HostKeyECDSA256 HostKeyType = "ecdsa-sha2-nistp256"
	HostKeyECDSA384 HostKeyType = "ecdsa-sha2-nistp384"
	HostKeyECDSA521 HostKeyType = "ecdsa-sha2-nistp521"
	HostKeyRSA      HostKeyType = "ssh-rsa"
)

var hostKeyTypes = []string{string(HostKeyEd25519), string(HostKeyECDSA256), string(HostKeyECDSA384), string(HostKeyECDSA521), string(HostKeyRSA)}

// ProtocolSchema implements SchemaDescriber.
func (HostKeyType) ProtocolSchema() ScalarSchema { return enumSchema(hostKeyTypes) }

// Validate checks the value.
func (v HostKeyType) Validate() error { return checkEnum("host key type", string(v), hostKeyTypes) }

// TokenPurpose binds a sealed task token to the spec field it belongs to.
type TokenPurpose string

// Token purposes.
const (
	PurposeClone TokenPurpose = "clone" // BuildInputs.cloneToken
	// PurposeSSHKey is a deploy key for ssh clones (BuildInputs.sshKey): the
	// token is the standard base64 of the 32-byte ed25519 seed.
	PurposeSSHKey TokenPurpose = "ssh_key"
	// PurposeSession is the key of an interactive session (SessionSpec.key).
	PurposeSession TokenPurpose = "session"
)

var tokenPurposes = []string{string(PurposeClone), string(PurposeSSHKey), string(PurposeSession)}

// ProtocolSchema implements SchemaDescriber.
func (TokenPurpose) ProtocolSchema() ScalarSchema { return enumSchema(tokenPurposes) }

// Validate checks the value.
func (v TokenPurpose) Validate() error { return checkEnum("token purpose", string(v), tokenPurposes) }

// AttestationKind is an attestation attached to a pushed image index.
type AttestationKind string

// Attestation kinds.
const (
	AttestSBOM       AttestationKind = "sbom"
	AttestProvenance AttestationKind = "provenance"
)

var attestationKinds = []string{string(AttestSBOM), string(AttestProvenance)}

// ProtocolSchema implements SchemaDescriber.
func (AttestationKind) ProtocolSchema() ScalarSchema { return enumSchema(attestationKinds) }

// Validate checks the value.
func (v AttestationKind) Validate() error {
	return checkEnum("attestation", string(v), attestationKinds)
}

// VolumeDeletionOutcome is the result of one requested volume deletion (NodeState.deleteVolumes).
type VolumeDeletionOutcome string

// Volume deletion outcomes.
const (
	// VolumeDeleted: the volume was removed.
	VolumeDeleted VolumeDeletionOutcome = "deleted"
	// VolumeAbsent: no managed volume of that name exists (already gone); final.
	VolumeAbsent VolumeDeletionOutcome = "absent"
	// VolumeRefused: launchroomd will not delete it (labels do not match the
	// request, still declared in the NodeState, or mounted by a container).
	// Retried on later passes while it is requested.
	VolumeRefused VolumeDeletionOutcome = "refused"
	// VolumeDeleteFailed: Docker refused or failed; retried on later passes.
	VolumeDeleteFailed VolumeDeletionOutcome = "failed"
)

var volumeDeletionOutcomes = []string{string(VolumeDeleted), string(VolumeAbsent), string(VolumeRefused), string(VolumeDeleteFailed)}

// ProtocolSchema implements SchemaDescriber.
func (VolumeDeletionOutcome) ProtocolSchema() ScalarSchema { return enumSchema(volumeDeletionOutcomes) }

// Validate checks the value.
func (v VolumeDeletionOutcome) Validate() error {
	return checkEnum("volume deletion outcome", string(v), volumeDeletionOutcomes)
}
