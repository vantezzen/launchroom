package state

import (
	"regexp"
	"strings"
	"time"
)

// Point-in-time recovery and restore verification (docs/14 §10).
//
// Postgres archives completed WAL segments with its bundled basic_archive
// module into a directory inside the database's volume (no archive_command,
// so no shell); launchroomd ships them from there into restic snapshots
// (WalArchiveSpec on the workload, status in Report.walArchives). Physical
// base backups are `backup` tasks of kind base_backup; a `restore` of kind
// base_backup lays a base backup plus the archived WAL into the volume and
// replays it to a point in time in a throwaway, network-less container
// (RestoreRuntime). `verify_restore` restores the newest snapshot into a
// throwaway container and checks it (VerifyRestoreSpec, VerifyResult).

// WalSegment is a WAL segment file name: timeline, log and segment number
// as 24 upper-case hex digits ("000000010000000000000003").
type WalSegment string

var reWalSegment = regexp.MustCompile(`^[0-9A-F]{24}$`)

// ProtocolSchema implements SchemaDescriber.
func (WalSegment) ProtocolSchema() ScalarSchema { return strSchema(reWalSegment, 0) }

// Validate checks the grammar.
func (v WalSegment) Validate() error { return checkPattern("wal segment", string(v), reWalSegment, 0) }

// DatabaseIdent is a database or role name as the engines are configured
// with it (POSTGRES_USER, POSTGRES_DB): letters, digits and underscores.
type DatabaseIdent string

var reDatabaseIdent = regexp.MustCompile(`^[A-Za-z0-9_]{1,63}$`)

// ProtocolSchema implements SchemaDescriber.
func (DatabaseIdent) ProtocolSchema() ScalarSchema { return strSchema(reDatabaseIdent, 0) }

// Validate checks the grammar.
func (v DatabaseIdent) Validate() error {
	return checkPattern("database identifier", string(v), reDatabaseIdent, 0)
}

// WalArchiveState is how WAL shipping of one workload is doing.
type WalArchiveState string

// WAL archive states.
const (
	// WalArchiveActive: the last cycle shipped (or had nothing to ship).
	WalArchiveActive WalArchiveState = "active"
	// WalArchiveWaiting: the workload has no running container (yet).
	WalArchiveWaiting WalArchiveState = "waiting"
	// WalArchiveFailing: the last cycle failed (message says why); segments stay until the next one ships them.
	WalArchiveFailing WalArchiveState = "failing"
)

var walArchiveStates = []string{string(WalArchiveActive), string(WalArchiveWaiting), string(WalArchiveFailing)}

// ProtocolSchema implements SchemaDescriber.
func (WalArchiveState) ProtocolSchema() ScalarSchema { return enumSchema(walArchiveStates) }

// Validate checks the value.
func (v WalArchiveState) Validate() error {
	return checkEnum("wal archive state", string(v), walArchiveStates)
}

// VerifyCheckName names one check of a restore verification.
type VerifyCheckName string

// Verification checks (docs/14 §10.4).
const (
	VerifyCheckRestore    VerifyCheckName = "restore"    // the snapshot restored without errors
	VerifyCheckReplay     VerifyCheckName = "replay"     // base backup + WAL replayed and promoted
	VerifyCheckStart      VerifyCheckName = "start"      // the engine started on the restored data
	VerifyCheckQuery      VerifyCheckName = "query"      // tables and rows could be counted
	VerifyCheckAmcheck    VerifyCheckName = "amcheck"    // pg_amcheck found no corruption
	VerifyCheckMysqlcheck VerifyCheckName = "mysqlcheck" // CHECK TABLE reported every table OK
	VerifyCheckRDB        VerifyCheckName = "rdb"        // redis-check-rdb / valkey-check-rdb accepted the file
	VerifyCheckArchive    VerifyCheckName = "archive"    // a volume tar read completely, every entry allowed
	VerifyCheckValidate   VerifyCheckName = "validate"   // MongoDB validate passed on every collection (D47)
	VerifyCheckTables     VerifyCheckName = "checktable" // ClickHouse CHECK TABLE passed on every MergeTree table (D47)
)

var verifyCheckNames = []string{
	string(VerifyCheckRestore), string(VerifyCheckReplay), string(VerifyCheckStart), string(VerifyCheckQuery),
	string(VerifyCheckAmcheck), string(VerifyCheckMysqlcheck), string(VerifyCheckRDB), string(VerifyCheckArchive),
	string(VerifyCheckValidate), string(VerifyCheckTables),
}

// ProtocolSchema implements SchemaDescriber.
func (VerifyCheckName) ProtocolSchema() ScalarSchema { return enumSchema(verifyCheckNames) }

// Validate checks the value.
func (v VerifyCheckName) Validate() error {
	return checkEnum("verify check", string(v), verifyCheckNames)
}

// Limits of point-in-time recovery documents.
const (
	maxWalArchives     = 256
	maxVerifyChecks    = 16
	maxVerifyDetailLen = 512
	minWalInterval     = 10 * time.Second
	maxWalInterval     = time.Hour
)

// WalArchiveSpec turns on continuous WAL shipping for a Postgres workload
// (NodeState workloads[].walArchive). The workload's args make Postgres
// archive completed segments into Directory (archive_library=basic_archive);
// every Interval launchroomd stores the segments found there as one restic
// snapshot (`wal.tar`, entries `wal/<segment>`) with Tags, then deletes them
// from the directory. Shipping never changes the container.
type WalArchiveSpec struct {
	// Directory is basic_archive.archive_directory inside the container. It
	// must lie inside one of the workload's mounts, so segments not shipped
	// yet survive a container replacement.
	Directory ContainerPath `json:"directory"`
	// Destination is the restic repository. Unlike task destinations, its
	// credentials are a sealed WorkloadEnv bound to this server and workload
	// (the same document as workloads[].env) holding exactly
	// AWS_ACCESS_KEY_ID, AWS_SECRET_ACCESS_KEY and RESTIC_PASSWORD.
	Destination BackupDestination `json:"destination"`
	// Tags are put on every WAL snapshot ("kind:wal", "service:…", …).
	Tags []BackupTag `json:"tags" jsonschema:"minItems=1,maxItems=32"`
	// Interval between shipping cycles (10s–1h).
	Interval Duration `json:"interval"`
}

func (w *WalArchiveSpec) validate(e *errs, p string, mounts []Mount) {
	e.check(p+".directory", w.Directory)
	inMount := false
	for _, m := range mounts {
		t := string(m.Target)
		if !m.ReadOnly && (string(w.Directory) == t || strings.HasPrefix(string(w.Directory), t+"/")) {
			inMount = true
		}
	}
	if !inMount {
		e.addf(p+".directory", "must be inside one of the workload's writable mounts")
	}
	w.Destination.validate(e, p+".destination")
	checkCount(e, p+".tags", len(w.Tags), 1, 32)
	for i, t := range w.Tags {
		e.check(idx(p+".tags", i), t)
	}
	e.check(p+".interval", w.Interval)
	if d := w.Interval.Std(); d < minWalInterval || d > maxWalInterval {
		e.addf(p+".interval", "must be between 10s and 1h")
	}
}

// WalArchiveStatus is the WAL shipping state of one workload (Report.walArchives).
type WalArchiveStatus struct {
	Workload WorkloadID      `json:"workload"`
	State    WalArchiveState `json:"state"`
	// CheckedAt is when the archive directory was last looked at.
	CheckedAt time.Time `json:"checkedAt"`
	// LastArchivedAt is when segments were last stored in the repository.
	LastArchivedAt *time.Time `json:"lastArchivedAt,omitzero" jsonschema:"nullable"`
	// LastSegment is the newest segment stored so far (by name).
	LastSegment WalSegment `json:"lastSegment,omitzero"`
	// PendingSegments are completed segments in the directory not stored yet.
	PendingSegments int `json:"pendingSegments" jsonschema:"minimum=0"`
	// Message is a short, secret-free explanation of a failing state.
	Message string `json:"message,omitzero" jsonschema:"maxLength=1024"`
}

func (s *WalArchiveStatus) validate(e *errs, p string) {
	e.check(p+".workload", s.Workload)
	e.check(p+".state", s.State)
	if s.CheckedAt.IsZero() {
		e.addf(p+".checkedAt", "is required")
	}
	if s.LastSegment != "" {
		e.check(p+".lastSegment", s.LastSegment)
	}
	if s.PendingSegments < 0 {
		e.addf(p+".pendingSegments", "must be >= 0")
	}
	checkText(e, p+".message", s.Message, 1024)
}

// RestoreRuntime is how launchroomd runs Postgres to replay a base backup:
// a throwaway container from Image (digest-pinned, policy floor) with the
// target volume at MountPath, no network, cap-drop ALL plus the engine's
// capabilities and these resource limits.
type RestoreRuntime struct {
	Image ImageRef `json:"image"`
	// MountPath is where the database's volume is mounted.
	MountPath ContainerPath `json:"mountPath"`
	// DataDirectory is PGDATA; equal to or inside MountPath.
	DataDirectory ContainerPath `json:"dataDirectory"`
	// ArchiveDirectory, when set, keeps archiving during the replay (into the
	// workload's WAL archive directory, inside MountPath), so the new
	// timeline's history file is shipped once the workload runs again.
	ArchiveDirectory ContainerPath `json:"archiveDirectory,omitzero"`
	// Env is a sealed TaskEnv bound to this server and task holding exactly
	// POSTGRES_USER, POSTGRES_PASSWORD and POSTGRES_DB of the target: after
	// the replay the role's password is set to the target's (a copy has its
	// own credentials).
	Env       Sealed    `json:"env"`
	Resources Resources `json:"resources"`
}

func (r *RestoreRuntime) validate(e *errs, p string) {
	e.check(p+".image", r.Image)
	e.check(p+".mountPath", r.MountPath)
	e.check(p+".dataDirectory", r.DataDirectory)
	within := func(path ContainerPath) bool {
		return path == r.MountPath || strings.HasPrefix(string(path), string(r.MountPath)+"/")
	}
	if !within(r.DataDirectory) {
		e.addf(p+".dataDirectory", "must be inside mountPath")
	}
	if r.ArchiveDirectory != "" {
		e.check(p+".archiveDirectory", r.ArchiveDirectory)
		if !within(r.ArchiveDirectory) {
			e.addf(p+".archiveDirectory", "must be inside mountPath")
		}
	}
	e.check(p+".env", r.Env)
	r.Resources.validate(e, p+".resources")
}

// VerifyRestoreSpec restores a snapshot into a throwaway container (or, for
// volumes, reads it completely) and checks the result (docs/14 §10.4).
// Nothing of the service is touched; the container has no network.
type VerifyRestoreSpec struct {
	// Kind is database (a dump), base_backup (a physical Postgres backup,
	// replayed with the archived WAL) or volume (a volume tar).
	Kind        BackupKind        `json:"kind"`
	Snapshot    SnapshotID        `json:"snapshot"`
	Destination BackupDestination `json:"destination"`
	// Engine, Image and DataPath (where the engine image keeps its data, the
	// mount path of the database's volume) are required for database and base_backup.
	Engine   DatabaseEngine `json:"engine,omitzero"`
	Image    ImageRef       `json:"image,omitzero"`
	DataPath ContainerPath  `json:"dataPath,omitzero"`
	// DataDirectory is PGDATA (base_backup only; inside DataPath).
	DataDirectory ContainerPath `json:"dataDirectory,omitzero"`
	// WalTags select the WAL snapshots replayed after the base backup (base_backup only).
	WalTags []BackupTag `json:"walTags,omitzero" jsonschema:"nullable"`
	// DatabaseUser and DatabaseName are the source's POSTGRES_USER and
	// POSTGRES_DB (base_backup only): the restored data's own role and
	// database, checked over the local socket.
	DatabaseUser DatabaseIdent `json:"databaseUser,omitzero"`
	DatabaseName DatabaseIdent `json:"databaseName,omitzero"`
	// Resources cap the throwaway container.
	Resources Resources `json:"resources"`
}

// TaskType implements TaskSpec.
func (*VerifyRestoreSpec) TaskType() TaskType { return TaskVerifyRestore }

// Validate implements TaskSpec.
func (s *VerifyRestoreSpec) Validate() error {
	var e errs
	e.check("kind", s.Kind)
	e.check("snapshot", s.Snapshot)
	s.Destination.validate(&e, "destination")
	s.Resources.validate(&e, "resources")
	switch s.Kind {
	case BackupDatabase, BackupBaseBackup:
		e.check("engine", s.Engine)
		e.check("image", s.Image)
		e.check("dataPath", s.DataPath)
		if s.Kind == BackupBaseBackup {
			if s.Engine != EnginePostgres {
				e.addf("engine", "base backups are Postgres only")
			}
			e.check("dataDirectory", s.DataDirectory)
			if s.DataDirectory != s.DataPath && !strings.HasPrefix(string(s.DataDirectory), string(s.DataPath)+"/") {
				e.addf("dataDirectory", "must be inside dataPath")
			}
			e.check("databaseUser", s.DatabaseUser)
			e.check("databaseName", s.DatabaseName)
		} else if s.DataDirectory != "" || len(s.WalTags) > 0 || s.DatabaseUser != "" || s.DatabaseName != "" {
			e.addf("kind", "dataDirectory, walTags, databaseUser and databaseName are only valid for base_backup")
		}
	case BackupVolume:
		if s.Engine != "" || s.Image != "" || s.DataPath != "" || s.DataDirectory != "" || len(s.WalTags) > 0 || s.DatabaseUser != "" || s.DatabaseName != "" {
			e.addf("kind", "engine, image, dataPath, dataDirectory and walTags are not valid for volume verification")
		}
	default:
		e.addf("kind", "control-plane backups are verified by the dashboard")
	}
	checkCount(&e, "walTags", len(s.WalTags), 0, 32)
	for i, t := range s.WalTags {
		e.check(idx("walTags", i), t)
	}
	return e.err()
}

// VerifyCheck is one check of a verification.
type VerifyCheck struct {
	Name   VerifyCheckName `json:"name"`
	Passed bool            `json:"passed"`
	// Detail is a short, secret-free result ("12 tables, 3,401 rows", an error excerpt).
	Detail string `json:"detail,omitzero" jsonschema:"maxLength=512"`
}

// VerifyResult is the outcome of a verify_restore task that got as far as
// restoring: Passed is false when any check failed (the task still succeeds;
// the dashboard records the failed verification).
type VerifyResult struct {
	Passed bool          `json:"passed"`
	Checks []VerifyCheck `json:"checks" jsonschema:"maxItems=16"`
	// Tables and Rows were counted in the restored database (databases).
	Tables int64 `json:"tables,omitzero" jsonschema:"minimum=0"`
	Rows   int64 `json:"rows,omitzero" jsonschema:"minimum=0"`
	// Files and Bytes were read from the volume snapshot (volumes), or the
	// size of the restored dump/RDB (databases).
	Files int64 `json:"files,omitzero" jsonschema:"minimum=0"`
	Bytes int64 `json:"bytes,omitzero" jsonschema:"minimum=0"`
	// RestoredTo is the time of the last transaction replayed (base_backup).
	RestoredTo *time.Time `json:"restoredTo,omitzero" jsonschema:"nullable"`
	DurationMs int64      `json:"durationMs" jsonschema:"minimum=0"`
}

func (r *VerifyResult) validate(e *errs) {
	if r.Checks == nil {
		e.addf("verify.checks", "is required")
	}
	checkCount(e, "verify.checks", len(r.Checks), 0, maxVerifyChecks)
	for i := range r.Checks {
		p := idx("verify.checks", i)
		e.check(p+".name", r.Checks[i].Name)
		checkText(e, p+".detail", r.Checks[i].Detail, maxVerifyDetailLen)
	}
	if r.Tables < 0 || r.Rows < 0 || r.Files < 0 || r.Bytes < 0 || r.DurationMs < 0 {
		e.addf("verify", "counts and durationMs must be >= 0")
	}
}
