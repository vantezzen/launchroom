package state

import (
	"regexp"
	"strings"
)

// Data import and export (D55, docs/14 §12): moving a database dump or a
// volume archive into a managed service, and handing a decrypted dump or
// volume tarball out of one. Files travel through the dashboard in chunks
// over the signed channel: launchroomd fetches an uploaded file chunk by
// chunk (GET /daemon/v1/transfers/{id}/chunks/{n}, signed responses) and
// uploads an export the same way (PUT …/chunks/{n}, signed requests).

// Limits of transfers and their results.
const (
	// MinTransferChunk and MaxTransferChunk bound TransferRef.chunkBytes.
	MinTransferChunk = 1 << 20
	MaxTransferChunk = 32 << 20
	// MaxTransferBytes bounds one transferred file (1 TiB).
	MaxTransferBytes = 1 << 40
	// MaxImportWarnings bounds DataImportResult.warnings.
	MaxImportWarnings  = 8
	maxWarningLen      = 256
	maxVersionLen      = 64
	maxStripComponents = 8
	maxOwnerID         = 1<<31 - 1
)

var (
	reTransferID       = idRegexp("dtr")
	dataImportModes    = []string{string(ImportInspect), string(ImportRun)}
	importSourceKinds  = []string{string(SourceFile), string(SourceURL)}
	dataFormats        = []string{string(FormatPostgresCustom), string(FormatPostgresSQL), string(FormatMySQLSQL), string(FormatMongoArchive), string(FormatClickHouseBackup), string(FormatRedisRDB), string(FormatTar), string(FormatZip)}
	importableEngines  = []DatabaseEngine{EnginePostgres, EngineMySQL, EngineMariaDB, EngineMongoDB}
	reTransferWarnings = regexp.MustCompile(`^[^\x00-\x1f\x7f]*$`)
)

// TransferID is a data transfer's public id ("dtr_<ULID>").
type TransferID string

// ProtocolSchema implements SchemaDescriber.
func (TransferID) ProtocolSchema() ScalarSchema { return strSchema(reTransferID, 0) }

// Validate checks the grammar.
func (v TransferID) Validate() error { return checkPattern("transfer id", string(v), reTransferID, 0) }

// DataImportMode says whether an import task only looks or imports.
type DataImportMode string

// Import modes.
const (
	// ImportInspect reports the target (empty or not, version, size, the
	// volume's root owner) and, for a connection URL, checks the source can
	// be reached. It changes nothing.
	ImportInspect DataImportMode = "inspect"
	// ImportRun imports.
	ImportRun DataImportMode = "import"
)

// ProtocolSchema implements SchemaDescriber.
func (DataImportMode) ProtocolSchema() ScalarSchema { return enumSchema(dataImportModes) }

// Validate checks the value.
func (v DataImportMode) Validate() error { return checkEnum("import mode", string(v), dataImportModes) }

// ImportSourceKind is where imported data comes from.
type ImportSourceKind string

// Import sources.
const (
	// SourceFile is an uploaded file the dashboard holds (TransferRef).
	SourceFile ImportSourceKind = "file"
	// SourceURL is a database connection URL launchroomd dumps from directly.
	SourceURL ImportSourceKind = "url"
)

// ProtocolSchema implements SchemaDescriber.
func (ImportSourceKind) ProtocolSchema() ScalarSchema { return enumSchema(importSourceKinds) }

// Validate checks the value.
func (v ImportSourceKind) Validate() error {
	return checkEnum("import source", string(v), importSourceKinds)
}

// DataFormat names the format of an imported or exported file.
type DataFormat string

// Data formats. Whether a file is gzip-compressed as a whole is separate
// (DataImportResult.compressed, DataExportResult.compressed).
const (
	// FormatPostgresCustom is pg_dump --format=custom (-Fc).
	FormatPostgresCustom DataFormat = "postgres_custom"
	// FormatPostgresSQL is a plain SQL dump made by pg_dump.
	FormatPostgresSQL DataFormat = "postgres_sql"
	// FormatMySQLSQL is a mysqldump / mariadb-dump SQL file.
	FormatMySQLSQL DataFormat = "mysql_sql"
	// FormatMongoArchive is mongodump --archive (collections optionally --gzip).
	FormatMongoArchive DataFormat = "mongodb_archive"
	// FormatClickHouseBackup is ClickHouse's native BACKUP as a zip (export only).
	FormatClickHouseBackup DataFormat = "clickhouse_backup"
	// FormatRedisRDB is a Redis/Valkey RDB file (export only).
	FormatRedisRDB DataFormat = "redis_rdb"
	// FormatTar is a tar archive (volumes).
	FormatTar DataFormat = "tar"
	// FormatZip is a zip archive (volume imports).
	FormatZip DataFormat = "zip"
)

// ProtocolSchema implements SchemaDescriber.
func (DataFormat) ProtocolSchema() ScalarSchema { return enumSchema(dataFormats) }

// Validate checks the value.
func (v DataFormat) Validate() error { return checkEnum("data format", string(v), dataFormats) }

// TransferRef is an uploaded file launchroomd fetches from the dashboard in
// chunks of ChunkBytes (the last one shorter). SHA256 is the file's digest
// as the uploader computed it: SHA-256 over the concatenated raw SHA-256
// digests of the chunks in order (so a browser can compute it chunk by
// chunk). launchroomd verifies it before it touches anything.
type TransferRef struct {
	ID         TransferID `json:"id"`
	SizeBytes  int64      `json:"sizeBytes" jsonschema:"minimum=1,maximum=1099511627776"`
	ChunkBytes int64      `json:"chunkBytes" jsonschema:"minimum=1048576,maximum=33554432"`
	Chunks     int        `json:"chunks" jsonschema:"minimum=1"`
	SHA256     SHA256Hex  `json:"sha256"`
}

func (t *TransferRef) validate(e *errs, p string) {
	e.check(p+".id", t.ID)
	if t.SizeBytes < 1 || t.SizeBytes > MaxTransferBytes {
		e.addf(p+".sizeBytes", "must be between 1 byte and 1 TiB")
	}
	if t.ChunkBytes < MinTransferChunk || t.ChunkBytes > MaxTransferChunk {
		e.addf(p+".chunkBytes", "must be between 1 MiB and 32 MiB")
	} else if t.SizeBytes >= 1 && int64(t.Chunks) != (t.SizeBytes+t.ChunkBytes-1)/t.ChunkBytes {
		e.addf(p+".chunks", "must be sizeBytes / chunkBytes rounded up")
	}
	e.check(p+".sha256", t.SHA256)
}

// TransferTarget is where launchroomd uploads an export: chunks of
// ChunkBytes (the last one shorter) to PUT /daemon/v1/transfers/{id}/chunks/{n}.
type TransferTarget struct {
	ID         TransferID `json:"id"`
	ChunkBytes int64      `json:"chunkBytes" jsonschema:"minimum=1048576,maximum=33554432"`
}

func (t *TransferTarget) validate(e *errs, p string) {
	e.check(p+".id", t.ID)
	if t.ChunkBytes < MinTransferChunk || t.ChunkBytes > MaxTransferChunk {
		e.addf(p+".chunkBytes", "must be between 1 MiB and 32 MiB")
	}
}

// FileOwner is a numeric owner for imported files.
type FileOwner struct {
	UID int `json:"uid" jsonschema:"minimum=0,maximum=2147483647"`
	GID int `json:"gid" jsonschema:"minimum=0,maximum=2147483647"`
}

func (o *FileOwner) validate(e *errs, p string) {
	if o.UID < 0 || o.UID > maxOwnerID || o.GID < 0 || o.GID > maxOwnerID {
		e.addf(p, "uid and gid must be between 0 and 2147483647")
	}
}

// DbImportSource is where a database import reads from: exactly one of File
// (kind file) and URL (kind url).
type DbImportSource struct {
	Kind ImportSourceKind `json:"kind"`
	File *TransferRef     `json:"file,omitzero" jsonschema:"nullable"`
	// URL is a sealed TaskEnv bound to this server and task holding exactly
	// SOURCE_URL (postgres://, postgresql://, mysql://, mariadb://,
	// mongodb://, mongodb+srv://). It is used once and never logged.
	URL *Sealed `json:"url,omitzero" jsonschema:"nullable"`
}

func (s *DbImportSource) validate(e *errs, p string) {
	e.check(p+".kind", s.Kind)
	switch s.Kind {
	case SourceFile:
		if s.File == nil || s.URL != nil {
			e.addf(p, "a file source has exactly file")
		} else {
			s.File.validate(e, p+".file")
		}
	case SourceURL:
		if s.URL == nil || s.File != nil {
			e.addf(p, "a url source has exactly url")
		} else {
			e.check(p+".url", *s.URL)
		}
	}
}

// DbImportSpec imports a dump into a managed database (docs/14 §12). The
// target is a database workload in this server's verified NodeState; the
// restore runs in a throwaway container of that workload's own image on its
// environment network (cap-drop ALL, the given limits, removed afterwards).
// The credentials of the target come from the workload's sealed env.
type DbImportSpec struct {
	Mode DataImportMode `json:"mode"`
	// Engine is the target's engine: postgres, mysql, mariadb or mongodb.
	Engine   DatabaseEngine `json:"engine"`
	Workload WorkloadID     `json:"workload"`
	// Source is required to import; an inspection may carry a url source
	// (checks it can be reached), never a file.
	Source *DbImportSource `json:"source,omitzero" jsonschema:"nullable"`
	// Replace drops and recreates the database before importing. Without
	// it, a database that is not empty refuses the import.
	Replace bool `json:"replace,omitzero"`
	// Resources cap the throwaway container.
	Resources Resources `json:"resources"`
}

// TaskType implements TaskSpec.
func (*DbImportSpec) TaskType() TaskType { return TaskDbImport }

// Validate implements TaskSpec.
func (s *DbImportSpec) Validate() error {
	var e errs
	e.check("mode", s.Mode)
	e.check("engine", s.Engine)
	if s.Engine.Validate() == nil && !importableEngine(s.Engine) {
		e.addf("engine", "imports support postgres, mysql, mariadb and mongodb")
	}
	e.check("workload", s.Workload)
	if s.Source != nil {
		s.Source.validate(&e, "source")
	}
	switch s.Mode {
	case ImportInspect:
		if s.Replace {
			e.addf("replace", "is only valid for imports")
		}
		if s.Source != nil && s.Source.Kind == SourceFile {
			e.addf("source", "inspections check url sources only")
		}
	case ImportRun:
		if s.Source == nil {
			e.addf("source", "is required to import")
		}
	}
	s.Resources.validate(&e, "resources")
	return e.err()
}

func importableEngine(engine DatabaseEngine) bool {
	for _, ok := range importableEngines {
		if engine == ok {
			return true
		}
	}
	return false
}

// VolumeImportSpec extracts an uploaded .tar, .tar.gz or .zip into a
// service volume through a never-started volume helper (docs/14 §12). The
// dashboard holds the service while it runs. Path traversal, absolute names,
// links leaving the archive and device nodes refuse the import.
type VolumeImportSpec struct {
	Mode   DataImportMode `json:"mode"`
	Volume VolumeID       `json:"volume"`
	// File is required to import.
	File *TransferRef `json:"file,omitzero" jsonschema:"nullable"`
	// Replace empties the volume first; without it the files are merged in
	// (existing files with the same name are overwritten).
	Replace bool `json:"replace,omitzero"`
	// StripComponents drops this many leading path components (like tar
	// --strip-components); entries with fewer are skipped.
	StripComponents int `json:"stripComponents,omitzero" jsonschema:"minimum=0,maximum=8"`
	// Owner sets every extracted entry's owner (nil: keep the archive's
	// numeric owners; zip entries, which carry none, get the volume root's).
	Owner *FileOwner `json:"owner,omitzero" jsonschema:"nullable"`
}

// TaskType implements TaskSpec.
func (*VolumeImportSpec) TaskType() TaskType { return TaskVolumeImport }

// Validate implements TaskSpec.
func (s *VolumeImportSpec) Validate() error {
	var e errs
	e.check("mode", s.Mode)
	e.check("volume", s.Volume)
	if s.StripComponents < 0 || s.StripComponents > maxStripComponents {
		e.addf("stripComponents", "must be between 0 and 8")
	}
	if s.Owner != nil {
		s.Owner.validate(&e, "owner")
	}
	switch s.Mode {
	case ImportInspect:
		if s.File != nil || s.Replace || s.StripComponents != 0 || s.Owner != nil {
			e.addf("mode", "inspections take only the volume")
		}
	case ImportRun:
		if s.File == nil {
			e.addf("file", "is required to import")
		} else {
			s.File.validate(&e, "file")
		}
	}
	return e.err()
}

// DbExportSpec writes a database's data in the engine's native format to
// the dashboard (Transfer): either a fresh dump of the running database
// (Workload, the same argv as backups) or a stored backup (Snapshot +
// Destination, decrypted by restic). MySQL/MariaDB SQL is gzip-compressed on
// the way; the other formats are compressed already.
type DbExportSpec struct {
	Engine DatabaseEngine `json:"engine"`
	// Workload: dump the running database (live).
	Workload WorkloadID `json:"workload,omitzero"`
	// Snapshot and Destination: a stored backup of this engine.
	Snapshot    SnapshotID         `json:"snapshot,omitzero"`
	Destination *BackupDestination `json:"destination,omitzero" jsonschema:"nullable"`
	Transfer    TransferTarget     `json:"transfer"`
}

// TaskType implements TaskSpec.
func (*DbExportSpec) TaskType() TaskType { return TaskDbExport }

// Validate implements TaskSpec.
func (s *DbExportSpec) Validate() error {
	var e errs
	e.check("engine", s.Engine)
	validateExportSource(&e, s.Workload != "", s.Snapshot, s.Destination)
	if s.Workload != "" {
		e.check("workload", s.Workload)
	}
	s.Transfer.validate(&e, "transfer")
	return e.err()
}

// VolumeExportSpec writes a volume's files as a .tar.gz (names relative to
// the volume, numeric owners and modes kept) to the dashboard: either the
// live volume (Volume, read through a never-started helper; Consistency
// pause freezes its users while it is read) or a stored volume backup
// (Snapshot + Destination).
type VolumeExportSpec struct {
	Volume      VolumeID           `json:"volume,omitzero"`
	Consistency VolumeConsistency  `json:"consistency,omitzero"`
	Snapshot    SnapshotID         `json:"snapshot,omitzero"`
	Destination *BackupDestination `json:"destination,omitzero" jsonschema:"nullable"`
	Transfer    TransferTarget     `json:"transfer"`
}

// TaskType implements TaskSpec.
func (*VolumeExportSpec) TaskType() TaskType { return TaskVolumeExport }

// Validate implements TaskSpec.
func (s *VolumeExportSpec) Validate() error {
	var e errs
	validateExportSource(&e, s.Volume != "", s.Snapshot, s.Destination)
	if s.Volume != "" {
		e.check("volume", s.Volume)
	}
	if s.Consistency != "" {
		e.check("consistency", s.Consistency)
		if s.Snapshot != "" {
			e.addf("consistency", "is only valid for live volumes")
		}
	}
	s.Transfer.validate(&e, "transfer")
	return e.err()
}

// validateExportSource requires exactly one of a live source and a
// snapshot (with its destination).
func validateExportSource(e *errs, live bool, snapshot SnapshotID, dest *BackupDestination) {
	switch {
	case live && (snapshot != "" || dest != nil):
		e.addf("snapshot", "an export reads either the live data or a snapshot")
	case !live && snapshot == "":
		e.addf("snapshot", "an export needs the live data or a snapshot")
	case snapshot != "":
		e.check("snapshot", snapshot)
		if dest == nil {
			e.addf("destination", "is required with a snapshot")
		} else {
			dest.validate(e, "destination")
		}
	}
}

// DataImportResult is the outcome of a db_import or volume_import task (both
// modes). For an inspection it describes the target as it is; after an
// import, what the target holds now.
type DataImportResult struct {
	Mode DataImportMode `json:"mode"`
	// Empty: no tables/collections (databases) or no files (volumes).
	Empty bool `json:"empty"`
	// Tables are tables (Postgres, MySQL) or collections (MongoDB).
	Tables int64 `json:"tables,omitzero" jsonschema:"minimum=0"`
	// Files are the entries extracted (volume imports).
	Files int64 `json:"files,omitzero" jsonschema:"minimum=0"`
	// SizeBytes is the database's size, or the bytes extracted into a volume.
	SizeBytes int64 `json:"sizeBytes,omitzero" jsonschema:"minimum=0"`
	// ServerVersion is the target engine's version ("17.6").
	ServerVersion string `json:"serverVersion,omitzero" jsonschema:"maxLength=64"`
	// RootOwner is the owner of the volume's root folder (volumes).
	RootOwner *FileOwner `json:"rootOwner,omitzero" jsonschema:"nullable"`
	// Format and Compressed describe the source file as launchroomd read it.
	Format     DataFormat `json:"format,omitzero"`
	Compressed bool       `json:"compressed,omitzero"`
	// SourceVersion is what made the source ("PostgreSQL 16.4", "MySQL
	// 8.0.36", "MariaDB 10.11.6", "MongoDB 7.0.5"), when it says.
	SourceVersion string `json:"sourceVersion,omitzero" jsonschema:"maxLength=64"`
	// ReadBytes is how much was read from the source (file or stream).
	ReadBytes int64 `json:"readBytes,omitzero" jsonschema:"minimum=0"`
	// Warnings are short notes about what launchroomd adjusted ("Removed
	// DEFINER clauses from 3 views and routines").
	Warnings   []string `json:"warnings,omitzero" jsonschema:"nullable,maxItems=8"`
	DurationMs int64    `json:"durationMs" jsonschema:"minimum=0"`
}

func (r *DataImportResult) validate(e *errs) {
	p := "dataImport"
	e.check(p+".mode", r.Mode)
	if r.Tables < 0 || r.Files < 0 || r.SizeBytes < 0 || r.ReadBytes < 0 || r.DurationMs < 0 {
		e.addf(p, "counts, sizes and durations must be >= 0")
	}
	checkText(e, p+".serverVersion", r.ServerVersion, maxVersionLen)
	checkText(e, p+".sourceVersion", r.SourceVersion, maxVersionLen)
	if r.RootOwner != nil {
		r.RootOwner.validate(e, p+".rootOwner")
	}
	if r.Format != "" {
		e.check(p+".format", r.Format)
	}
	checkCount(e, p+".warnings", len(r.Warnings), 0, MaxImportWarnings)
	for i, w := range r.Warnings {
		checkText(e, idx(p+".warnings", i), w, maxWarningLen)
		if !reTransferWarnings.MatchString(w) || strings.TrimSpace(w) == "" {
			e.addf(idx(p+".warnings", i), "must be one non-empty line")
		}
	}
}

// DataExportResult is the outcome of a db_export or volume_export task: the
// uploaded file (Chunks chunks of the target's chunkBytes, the last one
// shorter) and its digest in TransferRef's scheme.
type DataExportResult struct {
	Format DataFormat `json:"format"`
	// Compressed: the file is gzip-compressed as a whole (.sql.gz, .tar.gz).
	Compressed bool      `json:"compressed,omitzero"`
	SizeBytes  int64     `json:"sizeBytes" jsonschema:"minimum=0"`
	Chunks     int       `json:"chunks" jsonschema:"minimum=0"`
	SHA256     SHA256Hex `json:"sha256"`
	DurationMs int64     `json:"durationMs" jsonschema:"minimum=0"`
}

func (r *DataExportResult) validate(e *errs) {
	p := "dataExport"
	e.check(p+".format", r.Format)
	e.check(p+".sha256", r.SHA256)
	if r.SizeBytes < 0 || r.Chunks < 0 || r.DurationMs < 0 {
		e.addf(p, "sizeBytes, chunks and durationMs must be >= 0")
	}
}
