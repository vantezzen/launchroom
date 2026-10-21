package state

import (
	"crypto/rsa"
	"encoding/base64"
	"encoding/json/jsontext"
	"encoding/json/v2"
	"errors"
	"fmt"
	"strings"
	"time"
	"unicode/utf8"

	"golang.org/x/crypto/ssh"
)

// Task is a leased unit of imperative work (docs/03 §7). Spec is a
// discriminated union selected by Type; decoding is strict for both levels.
type Task struct {
	ID             TaskID    `json:"id"`
	Type           TaskType  `json:"type"`
	LeaseExpiresAt time.Time `json:"leaseExpiresAt"`
	Attempt        int       `json:"attempt" jsonschema:"minimum=1"`
	// Deadline is the hard deadline after which the daemon abandons the task.
	Deadline time.Time `json:"deadline"`
	// Spec is the typed spec for Type (see the TaskSpec implementations).
	Spec TaskSpec `json:"spec"`
}

// TaskSpec is implemented by every typed task spec.
type TaskSpec interface {
	TaskType() TaskType
	Validate() error
}

// newSpec returns an empty spec value for a task type.
func newSpec(t TaskType) (TaskSpec, error) {
	switch t {
	case TaskBuild:
		return &BuildSpec{}, nil
	case TaskPlan:
		return &PlanSpec{}, nil
	case TaskRunOnce:
		return &RunOnceSpec{}, nil
	case TaskBackup:
		return &BackupSpec{}, nil
	case TaskRestore:
		return &RestoreSpec{}, nil
	case TaskBackupMaintenance:
		return &BackupMaintenanceSpec{}, nil
	case TaskLogs:
		return &LogsSpec{}, nil
	case TaskSelfUpdate:
		return &SelfUpdateSpec{}, nil
	case TaskBackupSnapshots:
		return &BackupSnapshotsSpec{}, nil
	case TaskSession:
		return &SessionSpec{}, nil
	case TaskVerifyRestore:
		return &VerifyRestoreSpec{}, nil
	case TaskDbImport:
		return &DbImportSpec{}, nil
	case TaskVolumeImport:
		return &VolumeImportSpec{}, nil
	case TaskDbExport:
		return &DbExportSpec{}, nil
	case TaskVolumeExport:
		return &VolumeExportSpec{}, nil
	default:
		return nil, fmt.Errorf("unknown task type %q", truncate(string(t)))
	}
}

// SpecTypes returns an empty value of every spec type keyed by task type (for schema generation).
func SpecTypes() map[TaskType]TaskSpec {
	out := make(map[TaskType]TaskSpec, len(taskTypes))
	for _, t := range TaskTypes() {
		s, _ := newSpec(t)
		out[t] = s
	}
	return out
}

// taskWire is Task with an undecoded spec.
type taskWire struct {
	ID             TaskID         `json:"id"`
	Type           TaskType       `json:"type"`
	LeaseExpiresAt time.Time      `json:"leaseExpiresAt"`
	Attempt        int            `json:"attempt"`
	Deadline       time.Time      `json:"deadline"`
	Spec           jsontext.Value `json:"spec"`
}

// UnmarshalJSON decodes the envelope, then strictly decodes spec into the type named by "type".
func (t *Task) UnmarshalJSON(data []byte) error {
	var w taskWire
	if err := json.Unmarshal(data, &w, strictOptions()); err != nil {
		return err
	}
	if len(w.Spec) == 0 {
		return errors.New("task: spec is required")
	}
	spec, err := newSpec(w.Type)
	if err != nil {
		return fmt.Errorf("task: %w", err)
	}
	if err := json.Unmarshal(w.Spec, spec, strictOptions()); err != nil {
		return fmt.Errorf("task: spec: %w", err)
	}
	*t = Task{ID: w.ID, Type: w.Type, LeaseExpiresAt: w.LeaseExpiresAt, Attempt: w.Attempt, Deadline: w.Deadline, Spec: spec}
	return nil
}

// Validate checks the envelope and the spec.
func (t *Task) Validate() error {
	var e errs
	t.validate(&e, "")
	return e.err()
}

func (t *Task) validate(e *errs, p string) {
	e.check(p+"id", t.ID)
	e.check(p+"type", t.Type)
	if t.Attempt < 1 {
		e.addf(p+"attempt", "must be >= 1")
	}
	if t.LeaseExpiresAt.IsZero() {
		e.addf(p+"leaseExpiresAt", "is required")
	}
	if t.Deadline.IsZero() {
		e.addf(p+"deadline", "is required")
	}
	if t.Spec == nil {
		e.addf(p+"spec", "is required")
		return
	}
	if t.Spec.TaskType() != t.Type {
		e.addf(p+"spec", "is a %s spec but type is %s", t.Spec.TaskType(), t.Type)
	}
	if err := t.Spec.Validate(); err != nil {
		e.add(p+"spec", err)
	}
}

// ---- build / plan ----

// RepoSpec identifies the commit to build.
type RepoSpec struct {
	Provider GitProvider `json:"provider"`
	CloneURL GitURL      `json:"cloneUrl"`
	SHA      GitSHA      `json:"sha"`
	// Ref is the branch or tag the commit was resolved from. Servers that
	// refuse to send an unadvertised commit (plain git servers) are fetched
	// at this ref instead and the commit is checked out from its history.
	Ref GitRef `json:"ref,omitzero"`
	// KnownHosts pins the host keys of an ssh:// remote (required for ssh,
	// refused for http(s)). launchroomd accepts only a host key equal to one
	// of them and offers only their key types (docs/19 §6).
	KnownHosts []HostKey `json:"knownHosts,omitzero" jsonschema:"nullable,maxItems=8"`
}

// Limits of pinned host keys.
const (
	maxKnownHosts  = 8
	minRSAHostBits = 2048
)

// HostKey is one pinned SSH host key.
type HostKey struct {
	Type HostKeyType `json:"type"`
	Key  HostKeyBlob `json:"key"`
}

// Validate checks that the key parses, has the declared type and, for RSA,
// at least 2048 bits.
func (k HostKey) Validate() error {
	var e errs
	e.check("type", k.Type)
	e.check("key", k.Key)
	if len(e.list) > 0 {
		return e.err()
	}
	pub, err := k.PublicKey()
	if err != nil {
		return err
	}
	if pub.Type() != string(k.Type) {
		return fmt.Errorf("host key is a %s key, not %s", pub.Type(), k.Type)
	}
	if ck, ok := pub.(ssh.CryptoPublicKey); ok {
		if rk, ok := ck.CryptoPublicKey().(*rsa.PublicKey); ok && rk.N.BitLen() < minRSAHostBits {
			return fmt.Errorf("rsa host key has %d bits, at least %d required", rk.N.BitLen(), minRSAHostBits)
		}
	}
	return nil
}

// PublicKey parses the pinned key.
func (k HostKey) PublicKey() (ssh.PublicKey, error) {
	raw, err := base64.StdEncoding.DecodeString(string(k.Key))
	if err != nil {
		return nil, errors.New("host key is not valid base64")
	}
	pub, err := ssh.ParsePublicKey(raw)
	if err != nil {
		return nil, fmt.Errorf("host key does not parse: %w", err)
	}
	return pub, nil
}

// Limits of builder overrides.
const (
	maxCommandLen        = 4096
	maxRailpackEnv       = 64
	maxDisabledDecisions = 64
	maxBuildArgNames     = 256
	maxMaskEnvNames      = 1024
	maxLabelText         = 256
	maxInlineDockerfile  = 65536
)

// PresetNone disables launchroom presets for a build (BuilderSpec.Preset).
const PresetNone Slug = "none"

// BuilderSpec is the builder decision made by the dashboard (docs/04 §2, §3).
type BuilderSpec struct {
	// Kind is auto (the daemon resolves Dockerfile → preset + Railpack → Railpack
	// after cloning), dockerfile or railpack.
	Kind BuilderKind `json:"kind"`
	// DockerfilePath is repo-relative to rootDir (default "Dockerfile", then "Containerfile").
	DockerfilePath RelPath `json:"dockerfilePath,omitzero"`
	// Dockerfile is an inline Dockerfile stored in launchroom (a verified,
	// generated Dockerfile, docs/20): only with kind dockerfile, never with
	// dockerfilePath. launchroomd writes it outside the checkout and builds
	// rootDir with it.
	Dockerfile string `json:"dockerfile,omitzero" jsonschema:"maxLength=65536"`
	// Preset forces a launchroom preset by name, or disables presets with "none".
	// Omitted: the daemon detects the preset (kinds auto and railpack).
	Preset Slug `json:"preset,omitzero"`
	// RailpackVersion pins Railpack (required for kinds auto and railpack). An
	// daemon only builds with the Railpack version it links; others fail with config_error.
	RailpackVersion Semver `json:"railpackVersion,omitzero"`
	// DisabledDecisions are preset decision ids the user removed (UI chips).
	DisabledDecisions []Slug `json:"disabledDecisions,omitzero" jsonschema:"nullable,maxItems=64"`
	// InstallCommand, BuildCommand and StartCommand override Railpack's commands
	// (RAILPACK_INSTALL_CMD/…). They run inside the build/app container, never on the host.
	InstallCommand string `json:"installCommand,omitzero" jsonschema:"maxLength=4096"`
	BuildCommand   string `json:"buildCommand,omitzero" jsonschema:"maxLength=4096"`
	StartCommand   string `json:"startCommand,omitzero" jsonschema:"maxLength=4096"`
	// RailpackEnv are non-secret Railpack settings (RAILPACK_SPA_OUTPUT_DIR, RAILPACK_NODE_VERSION, …).
	RailpackEnv map[RailpackEnvName]string `json:"railpackEnv,omitzero" jsonschema:"nullable"`
}

// BuildInputs are the inputs shared by build and plan tasks.
type BuildInputs struct {
	Service     ServiceID     `json:"service"`
	Environment EnvironmentID `json:"environment"`
	// Deployment is required for build tasks (it tags the image); plan tasks
	// run outside a deployment (service created, build settings changed) and may omit it.
	Deployment DeploymentID `json:"deployment,omitzero"`
	Repo       RepoSpec     `json:"repo"`
	// CloneToken is a short-lived, repo-scoped credential sealed to the daemon
	// (plaintext: TaskToken with purpose "clone"). Omitted for public
	// repositories; only valid with http(s) clone URLs.
	CloneToken *Sealed `json:"cloneToken,omitzero" jsonschema:"nullable"`
	// SSHKey is the repository's ed25519 deploy key for ssh:// clone URLs,
	// sealed to the daemon (plaintext: TaskToken with purpose "ssh_key",
	// token = standard base64 of the 32-byte seed). Only valid with ssh URLs.
	SSHKey *Sealed `json:"sshKey,omitzero" jsonschema:"nullable"`
	// RootDir is the repo-relative app directory. Omitted: repo root.
	RootDir RelPath     `json:"rootDir,omitzero"`
	Builder BuilderSpec `json:"builder"`
	// Env holds build-scoped variables (passed as BuildKit secrets), sealed
	// (plaintext: TaskEnv bound to this task).
	Env *Sealed `json:"env,omitzero" jsonschema:"nullable"`
	// BuildArgs names non-secret build-scoped variables that may also be passed
	// as Dockerfile build args when the Dockerfile declares a matching ARG.
	// Secret-flagged variables must never be listed (docs/09 R15).
	BuildArgs []EnvName `json:"buildArgs,omitzero" jsonschema:"nullable,maxItems=256"`
	Platform  Platform  `json:"platform"`
	// CacheKey namespaces BuildKit mount caches (usually the service id, lowercased).
	CacheKey CacheKey `json:"cacheKey"`
}

func (b *BuildInputs) validate(e *errs) {
	e.check("service", b.Service)
	e.check("environment", b.Environment)
	if b.Deployment != "" {
		e.check("deployment", b.Deployment)
	}
	e.check("repo.provider", b.Repo.Provider)
	e.check("repo.cloneUrl", b.Repo.CloneURL)
	e.check("repo.sha", b.Repo.SHA)
	if b.Repo.Ref != "" {
		e.check("repo.ref", b.Repo.Ref)
	}
	isSSH := b.Repo.CloneURL.IsSSH()
	checkCount(e, "repo.knownHosts", len(b.Repo.KnownHosts), 0, maxKnownHosts)
	switch {
	case isSSH && len(b.Repo.KnownHosts) == 0:
		e.addf("repo.knownHosts", "is required for ssh clone URLs")
	case !isSSH && len(b.Repo.KnownHosts) > 0:
		e.addf("repo.knownHosts", "is only valid for ssh clone URLs")
	}
	for i, k := range b.Repo.KnownHosts {
		e.check(idx("repo.knownHosts", i), k)
	}
	unique(e, "repo.knownHosts", b.Repo.KnownHosts)
	if b.CloneToken != nil {
		e.check("cloneToken", *b.CloneToken)
		if isSSH {
			e.addf("cloneToken", "is only valid for http(s) clone URLs (use sshKey)")
		}
	}
	if b.SSHKey != nil {
		e.check("sshKey", *b.SSHKey)
		if !isSSH {
			e.addf("sshKey", "is only valid for ssh clone URLs")
		}
	}
	if b.RootDir != "" {
		e.check("rootDir", b.RootDir)
	}
	bs := &b.Builder
	e.check("builder.kind", bs.Kind)
	if bs.DockerfilePath != "" {
		e.check("builder.dockerfilePath", bs.DockerfilePath)
	}
	if bs.Dockerfile != "" {
		switch {
		case bs.Kind != BuilderDockerfile:
			e.addf("builder.dockerfile", "is only valid for dockerfile builds")
		case bs.DockerfilePath != "":
			e.addf("builder.dockerfile", "and dockerfilePath are mutually exclusive")
		}
		checkText(e, "builder.dockerfile", bs.Dockerfile, maxInlineDockerfile)
		if !utf8.ValidString(bs.Dockerfile) {
			e.addf("builder.dockerfile", "must be valid UTF-8")
		}
		if strings.ContainsRune(bs.Dockerfile, 0) {
			e.addf("builder.dockerfile", "contains a NUL byte")
		}
	}
	switch bs.Kind {
	case BuilderDockerfile:
		if bs.Preset != "" || bs.RailpackVersion != "" || bs.InstallCommand != "" || bs.BuildCommand != "" ||
			bs.StartCommand != "" || len(bs.RailpackEnv) > 0 || len(bs.DisabledDecisions) > 0 {
			e.addf("builder", "preset, railpackVersion, commands, railpackEnv and disabledDecisions are only valid for railpack and auto builds")
		}
	case BuilderRailpack, BuilderAuto:
		e.check("builder.railpackVersion", bs.RailpackVersion)
		if bs.Preset != "" {
			e.check("builder.preset", bs.Preset)
		}
		if bs.Kind == BuilderRailpack && bs.DockerfilePath != "" {
			e.addf("builder.dockerfilePath", "is only valid for dockerfile and auto builds")
		}
	}
	checkCount(e, "builder.disabledDecisions", len(bs.DisabledDecisions), 0, maxDisabledDecisions)
	for i, d := range bs.DisabledDecisions {
		e.check(idx("builder.disabledDecisions", i), d)
	}
	for name, cmd := range map[string]string{"installCommand": bs.InstallCommand, "buildCommand": bs.BuildCommand, "startCommand": bs.StartCommand} {
		checkText(e, "builder."+name, cmd, maxCommandLen)
		if strings.ContainsRune(cmd, 0) {
			e.addf("builder."+name, "contains a NUL byte")
		}
	}
	if len(bs.RailpackEnv) > maxRailpackEnv {
		e.addf("builder.railpackEnv", "has more than %d entries", maxRailpackEnv)
	}
	for k, v := range bs.RailpackEnv {
		e.check("builder.railpackEnv", k)
		if len(v) > maxCommandLen || strings.ContainsRune(v, 0) {
			e.addf("builder.railpackEnv."+string(k), "value must be at most %d bytes without NUL", maxCommandLen)
		}
	}
	if b.Env != nil {
		e.check("env", *b.Env)
	}
	checkCount(e, "buildArgs", len(b.BuildArgs), 0, maxBuildArgNames)
	for i, n := range b.BuildArgs {
		e.check(idx("buildArgs", i), n)
	}
	unique(e, "buildArgs", b.BuildArgs)
	e.check("platform", b.Platform)
	e.check("cacheKey", b.CacheKey)
}

// AppRepository is the registry repository builds of a service push to:
// "apps/<service id lowercased>" (docs/04 §5).
func AppRepository(s ServiceID) RegistryRepository {
	return RegistryRepository("apps/" + strings.ToLower(string(s)))
}

// CacheRepository is the registry repository of a service's BuildKit cache.
func CacheRepository(s ServiceID) RegistryRepository {
	return RegistryRepository("cache/" + strings.ToLower(string(s)))
}

// BuildSpec builds and pushes one immutable image. Push credentials are not
// part of the spec: the daemon requests a short-lived push token for this task
// from POST /daemon/v1/registry-token when it pushes (docs/05 §6).
type BuildSpec struct {
	BuildInputs
	// Image is the repository to push to: "<registry>/apps/<service id
	// lowercased>". The daemon tags the image with the deployment id
	// (lowercased) and reports the digest.
	Image ImageRepository `json:"image"`
}

// TaskType implements TaskSpec.
func (*BuildSpec) TaskType() TaskType { return TaskBuild }

// Validate implements TaskSpec.
func (s *BuildSpec) Validate() error {
	var e errs
	s.validate(&e)
	if s.Deployment == "" {
		e.addf("deployment", "is required for build tasks")
	}
	e.check("image", s.Image)
	if s.Image.Validate() == nil && s.Service.Validate() == nil {
		if _, repo, _ := strings.Cut(string(s.Image), "/"); RegistryRepository(repo) != AppRepository(s.Service) {
			e.addf("image", "must be the service's repository %s", AppRepository(s.Service))
		}
	}
	return e.err()
}

// PlanSpec is a dry-run build plan ("we detected…"); nothing is built or pushed.
type PlanSpec struct {
	BuildInputs
	// Inspect asks launchroomd to report a RepoInspection (file listing and
	// selected manifest/entry files, docs/20 §2) in TaskCompletion.inspection,
	// whether planning succeeds or fails.
	Inspect bool `json:"inspect,omitzero"`
}

// TaskType implements TaskSpec.
func (*PlanSpec) TaskType() TaskType { return TaskPlan }

// Validate implements TaskSpec.
func (s *PlanSpec) Validate() error {
	var e errs
	s.validate(&e)
	return e.err()
}

// ---- run_once ----

// RunOnceSpec runs a one-off container (release/seed commands) in an environment
// network. The container is labelled launchroom.task=<task id> (no workload
// label, so the reconciler never touches it) and removed when it exits; the
// exit code is the result (RunOnceResult). The daemon creates the network
// (same name and subnet the NodeState would use) when it does not exist yet.
type RunOnceSpec struct {
	Service     ServiceID     `json:"service"`
	Environment EnvironmentID `json:"environment"`
	Deployment  DeploymentID  `json:"deployment"`
	Image       ImageRef      `json:"image"`
	// Command overrides the image ENTRYPOINT as argv. Omitted: image default.
	// Images whose ENTRYPOINT is a shell wrapper (Railpack runs bash with -c)
	// get the whole argv here and no args, so it runs exactly as given.
	Command []string `json:"command,omitzero" jsonschema:"nullable,minItems=1,maxItems=256"`
	// Args is the argv passed to the ENTRYPOINT (never a shell string). At least
	// one of command and args is required.
	Args []string `json:"args,omitzero" jsonschema:"nullable,minItems=1,maxItems=256"`
	// Env is the runtime environment, sealed (plaintext: TaskEnv bound to this task).
	Env Sealed `json:"env"`
	// MaskEnv names the variables of Env whose values (and their encodings,
	// and the password of a URL value) are replaced by "***" in the shipped
	// logs: secrets and config values that embed one (docs/09 R16).
	// Names missing from Env are ignored.
	MaskEnv   []EnvName   `json:"maskEnv,omitzero" jsonschema:"nullable,maxItems=1024"`
	Network   NetworkSpec `json:"network"`
	Timeout   Duration    `json:"timeout"`
	Resources Resources   `json:"resources"`
	// Probe makes this a start check (docs/20 §5): launchroomd checks from
	// outside that the container accepts connections on probe.port within
	// probe.within, then stops it. With a probe, command and args may both be
	// omitted (the image's own CMD runs).
	Probe *RunOnceProbe `json:"probe,omitzero" jsonschema:"nullable"`
}

// RunOnceProbe is the start check of a run_once task.
type RunOnceProbe struct {
	Port Port `json:"port"`
	// Path, when set, is also requested over HTTP; any status below 500 passes.
	Path   HTTPPath `json:"path,omitzero"`
	Within Duration `json:"within"`
}

// TaskType implements TaskSpec.
func (*RunOnceSpec) TaskType() TaskType { return TaskRunOnce }

// Validate implements TaskSpec.
func (s *RunOnceSpec) Validate() error {
	var e errs
	e.check("service", s.Service)
	e.check("environment", s.Environment)
	e.check("deployment", s.Deployment)
	e.check("image", s.Image)
	checkArgv(&e, "command", s.Command)
	if s.Args == nil && s.Command == nil && s.Probe == nil {
		e.addf("args", "is required unless command or probe is set")
	}
	if s.Probe != nil {
		e.check("probe.port", s.Probe.Port)
		if s.Probe.Path != "" {
			e.check("probe.path", s.Probe.Path)
		}
		e.check("probe.within", s.Probe.Within)
		if w := s.Probe.Within.Std(); s.Probe.Within.Validate() == nil && (w < time.Second || w > 10*time.Minute) {
			e.addf("probe.within", "must be between 1s and 10m")
		}
	}
	checkArgv(&e, "args", s.Args)
	e.check("env", s.Env)
	checkCount(&e, "maskEnv", len(s.MaskEnv), 0, maxMaskEnvNames)
	for i, n := range s.MaskEnv {
		e.check(idx("maskEnv", i), n)
	}
	unique(&e, "maskEnv", s.MaskEnv)
	e.check("network.id", s.Network.ID)
	e.check("network.name", s.Network.Name)
	e.check("network.subnet", s.Network.Subnet)
	e.check("timeout", s.Timeout)
	if s.Timeout.Std() > 24*time.Hour {
		e.addf("timeout", "must be at most 24h")
	}
	s.Resources.validate(&e, "resources")
	return e.err()
}

// ---- backup / restore / maintenance ----

// BackupTarget names what to back up or restore into. Exactly the fields for
// the kind are set: database → engine + workload; volume → volume (+ consistency);
// control_plane → nothing; base_backup → engine (postgres) + workload for a
// backup, engine + volume (the database's data volume) for a restore.
type BackupTarget struct {
	Engine      DatabaseEngine    `json:"engine,omitzero"`
	Workload    WorkloadID        `json:"workload,omitzero"`
	Volume      VolumeID          `json:"volume,omitzero"`
	Consistency VolumeConsistency `json:"consistency,omitzero"`
}

func (t BackupTarget) validate(e *errs, p string, kind BackupKind) {
	switch kind {
	case BackupBaseBackup:
		if t.Engine != EnginePostgres {
			e.addf(p+".engine", "base backups are Postgres only")
		}
		e.check(p+".workload", t.Workload)
		if t.Volume != "" || t.Consistency != "" {
			e.addf(p, "volume and consistency are not valid for base_backup targets")
		}
	case BackupDatabase:
		e.check(p+".engine", t.Engine)
		e.check(p+".workload", t.Workload)
		if t.Volume != "" || t.Consistency != "" {
			e.addf(p, "volume and consistency are not valid for database targets")
		}
	case BackupVolume:
		e.check(p+".volume", t.Volume)
		if t.Consistency != "" {
			e.check(p+".consistency", t.Consistency)
		}
		if t.Engine != "" || t.Workload != "" {
			e.addf(p, "engine and workload are not valid for volume targets")
		}
	case BackupControlPlane:
		if t != (BackupTarget{}) {
			e.addf(p, "must be empty for control_plane targets")
		}
	}
}

// BackupDestination is a restic repository location.
type BackupDestination struct {
	Kind DestinationKind `json:"kind"`
	// Endpoint and Bucket are required for s3; Region is optional (default us-east-1).
	Endpoint S3Endpoint `json:"endpoint,omitzero"`
	Bucket   BucketName `json:"bucket,omitzero"`
	Region   Region     `json:"region,omitzero"`
	// Prefix is the key prefix (s3) or the subdirectory of data/backups (local).
	Prefix ObjectPrefix `json:"prefix,omitzero"`
	// Credentials is a sealed TaskEnv bound to this server and task whose env
	// holds exactly AWS_ACCESS_KEY_ID, AWS_SECRET_ACCESS_KEY and RESTIC_PASSWORD
	// (only RESTIC_PASSWORD for local destinations). The daemon passes them to
	// restic through its environment, never argv.
	Credentials Sealed `json:"credentials"`
}

func (d BackupDestination) validate(e *errs, p string) {
	e.check(p+".kind", d.Kind)
	switch d.Kind {
	case DestinationS3:
		e.check(p+".endpoint", d.Endpoint)
		e.check(p+".bucket", d.Bucket)
		if d.Region != "" {
			e.check(p+".region", d.Region)
		}
	case DestinationLocal:
		if d.Endpoint != "" || d.Bucket != "" || d.Region != "" {
			e.addf(p, "endpoint, bucket and region are not valid for local destinations")
		}
	}
	if d.Prefix != "" {
		e.check(p+".prefix", d.Prefix)
	}
	e.check(p+".credentials", d.Credentials)
}

// BackupSpec takes one restic snapshot (docs/14 §2).
type BackupSpec struct {
	Kind        BackupKind        `json:"kind"`
	Target      BackupTarget      `json:"target"`
	Destination BackupDestination `json:"destination"`
	// Tags are restic snapshot tags ("key:value").
	Tags []BackupTag `json:"tags"`
}

// TaskType implements TaskSpec.
func (*BackupSpec) TaskType() TaskType { return TaskBackup }

// Validate implements TaskSpec.
func (s *BackupSpec) Validate() error {
	var e errs
	e.check("kind", s.Kind)
	s.Target.validate(&e, "target", s.Kind)
	s.Destination.validate(&e, "destination")
	checkCount(&e, "tags", len(s.Tags), 0, 32)
	for i, t := range s.Tags {
		e.check(idx("tags", i), t)
	}
	return e.err()
}

// RestoreSpec restores a snapshot into a target (docs/14 §5).
type RestoreSpec struct {
	Kind        BackupKind        `json:"kind"`
	Snapshot    SnapshotID        `json:"snapshot"`
	Target      BackupTarget      `json:"target"`
	Destination BackupDestination `json:"destination"`
	Mode        RestoreMode       `json:"mode"`
	// PointInTime is where a base_backup restore stops replaying WAL
	// (omitted: as far as the archive goes). Only for base_backup.
	PointInTime *time.Time `json:"pointInTime,omitzero" jsonschema:"nullable"`
	// WalTags select the WAL snapshots to replay after the base backup (base_backup only).
	WalTags []BackupTag `json:"walTags,omitzero" jsonschema:"nullable"`
	// Runtime is how Postgres runs for the replay (required for base_backup).
	Runtime *RestoreRuntime `json:"runtime,omitzero" jsonschema:"nullable"`
}

// TaskType implements TaskSpec.
func (*RestoreSpec) TaskType() TaskType { return TaskRestore }

// Validate implements TaskSpec.
func (s *RestoreSpec) Validate() error {
	var e errs
	e.check("kind", s.Kind)
	if s.Kind == BackupControlPlane {
		e.addf("kind", "control-plane restores run through the installer, not daemon tasks")
	}
	e.check("snapshot", s.Snapshot)
	if s.Kind == BackupBaseBackup {
		// The replay runs against the data volume, with the workload stopped.
		if s.Target.Engine != EnginePostgres {
			e.addf("target.engine", "base backups are Postgres only")
		}
		e.check("target.volume", s.Target.Volume)
		if s.Target.Workload != "" || s.Target.Consistency != "" {
			e.addf("target", "workload and consistency are not valid for base_backup restores")
		}
		if s.Runtime == nil {
			e.addf("runtime", "is required for base_backup restores")
		} else {
			s.Runtime.validate(&e, "runtime")
		}
	} else {
		s.Target.validate(&e, "target", s.Kind)
		if s.PointInTime != nil || len(s.WalTags) > 0 || s.Runtime != nil {
			e.addf("kind", "pointInTime, walTags and runtime are only valid for base_backup restores")
		}
	}
	checkCount(&e, "walTags", len(s.WalTags), 0, 32)
	for i, t := range s.WalTags {
		e.check(idx("walTags", i), t)
	}
	s.Destination.validate(&e, "destination")
	e.check("mode", s.Mode)
	return e.err()
}

// Retention is a restic forget policy.
type Retention struct {
	KeepLast    int `json:"keepLast,omitzero" jsonschema:"minimum=0,maximum=1000"`
	KeepHourly  int `json:"keepHourly,omitzero" jsonschema:"minimum=0,maximum=1000"`
	KeepDaily   int `json:"keepDaily,omitzero" jsonschema:"minimum=0,maximum=1000"`
	KeepWeekly  int `json:"keepWeekly,omitzero" jsonschema:"minimum=0,maximum=1000"`
	KeepMonthly int `json:"keepMonthly,omitzero" jsonschema:"minimum=0,maximum=1000"`
	// KeepWithinHours keeps every snapshot taken within this many hours of
	// the group's newest one (restic --keep-within; WAL and base backups of
	// point-in-time recovery, docs/14 §10.3).
	KeepWithinHours int `json:"keepWithinHours,omitzero" jsonschema:"minimum=0,maximum=87600"`
}

func (r Retention) validate(e *errs, p string) {
	if r.KeepWithinHours < 0 || r.KeepWithinHours > 87600 {
		e.addf(p, "keepWithinHours must be between 0 and 87600")
		return
	}
	vals := []int{r.KeepLast, r.KeepHourly, r.KeepDaily, r.KeepWeekly, r.KeepMonthly, min(r.KeepWithinHours, 1000)}
	total := 0
	for _, v := range vals {
		if v < 0 || v > 1000 {
			e.addf(p, "keep values must be between 0 and 1000")
			return
		}
		total += v
	}
	if total == 0 {
		e.addf(p, "must keep at least one snapshot")
	}
}

// BackupMaintenanceSpec runs forget/prune/check on a repository.
type BackupMaintenanceSpec struct {
	Operation   MaintenanceOperation `json:"operation"`
	Destination BackupDestination    `json:"destination"`
	// Retention is required for forget.
	Retention *Retention `json:"retention,omitzero" jsonschema:"nullable"`
	// Tags restrict forget to snapshots with all of these tags.
	Tags []BackupTag `json:"tags,omitzero" jsonschema:"nullable"`
	// ReadDataSubsetPercent makes check read this percentage of pack data (0 = metadata only).
	ReadDataSubsetPercent int `json:"readDataSubsetPercent,omitzero" jsonschema:"minimum=0,maximum=100"`
}

// TaskType implements TaskSpec.
func (*BackupMaintenanceSpec) TaskType() TaskType { return TaskBackupMaintenance }

// Validate implements TaskSpec.
func (s *BackupMaintenanceSpec) Validate() error {
	var e errs
	e.check("operation", s.Operation)
	s.Destination.validate(&e, "destination")
	switch s.Operation {
	case MaintenanceForget:
		if s.Retention == nil {
			e.addf("retention", "is required for forget")
		} else {
			s.Retention.validate(&e, "retention")
		}
	default:
		if s.Retention != nil {
			e.addf("retention", "is only valid for forget")
		}
	}
	if s.ReadDataSubsetPercent != 0 && s.Operation != MaintenanceCheck {
		e.addf("readDataSubsetPercent", "is only valid for check")
	}
	if s.ReadDataSubsetPercent < 0 || s.ReadDataSubsetPercent > 100 {
		e.addf("readDataSubsetPercent", "must be between 0 and 100")
	}
	checkCount(&e, "tags", len(s.Tags), 0, 32)
	for i, t := range s.Tags {
		e.check(idx("tags", i), t)
	}
	return e.err()
}

// BackupSnapshotsSpec lists the snapshots of a repository that carry all of
// Tags (restic snapshots --json --tag …). Restore UIs are built from this.
type BackupSnapshotsSpec struct {
	Destination BackupDestination `json:"destination"`
	// Tags restrict the listing to snapshots with all of these tags.
	Tags []BackupTag `json:"tags"`
}

// TaskType implements TaskSpec.
func (*BackupSnapshotsSpec) TaskType() TaskType { return TaskBackupSnapshots }

// Validate implements TaskSpec.
func (s *BackupSnapshotsSpec) Validate() error {
	var e errs
	s.Destination.validate(&e, "destination")
	checkCount(&e, "tags", len(s.Tags), 0, 32)
	for i, t := range s.Tags {
		e.check(idx("tags", i), t)
	}
	return e.err()
}

// ---- logs / self_update ----

// LogsSpec fetches (or follows) a workload's runtime logs.
type LogsSpec struct {
	Workload WorkloadID `json:"workload"`
	Since    *time.Time `json:"since,omitzero" jsonschema:"nullable"`
	// Tail limits the number of past lines (0 = none, only with follow).
	Tail   int  `json:"tail" jsonschema:"minimum=0,maximum=10000"`
	Follow bool `json:"follow"`
}

// TaskType implements TaskSpec.
func (*LogsSpec) TaskType() TaskType { return TaskLogs }

// Validate implements TaskSpec.
func (s *LogsSpec) Validate() error {
	var e errs
	e.check("workload", s.Workload)
	if s.Tail < 0 || s.Tail > 10000 {
		e.addf("tail", "must be between 0 and 10000")
	}
	return e.err()
}

// SelfUpdateSpec asks the daemon to update itself (scope "daemon", the
// default) or, on the control-plane host only, the whole control plane
// (scope "control_plane", docs/11 §4). The daemon downloads the release
// bundle of Version itself, verifies its signature against the release
// identity pinned at install (data/trust.json, never taken from the
// dashboard) and never downgrades. Image must equal the bundle's
// LR_DAEMON_IMAGE (a consistency check; the bundle is authoritative).
type SelfUpdateSpec struct {
	Version Semver      `json:"version"`
	Image   ImageRef    `json:"image"`
	Scope   UpdateScope `json:"scope,omitzero"`
}

// TaskType implements TaskSpec.
func (*SelfUpdateSpec) TaskType() TaskType { return TaskSelfUpdate }

// Validate implements TaskSpec.
func (s *SelfUpdateSpec) Validate() error {
	var e errs
	e.check("version", s.Version)
	e.check("image", s.Image)
	if s.Scope != "" {
		e.check("scope", s.Scope)
	}
	return e.err()
}

// EffectiveScope is Scope with the default applied.
func (s *SelfUpdateSpec) EffectiveScope() UpdateScope {
	if s.Scope == "" {
		return UpdateDaemon
	}
	return s.Scope
}
