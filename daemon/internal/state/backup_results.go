package state

import "time"

// Limits of backup results.
const (
	// MaxSnapshotsListed bounds BackupSnapshotsResult.Snapshots (newest first).
	MaxSnapshotsListed = 1000
	maxSnapshotTags    = 32
)

// BackupResult is the outcome of a successful backup task.
type BackupResult struct {
	// SnapshotID is the full restic snapshot id.
	SnapshotID SnapshotID `json:"snapshotId"`
	// SizeBytes is the size of the streamed dump (bytes read by restic).
	SizeBytes int64 `json:"sizeBytes" jsonschema:"minimum=0"`
	// AddedBytes is what the snapshot added to the repository after deduplication (if restic reported it).
	AddedBytes int64 `json:"addedBytes,omitzero" jsonschema:"minimum=0"`
	DurationMs int64 `json:"durationMs" jsonschema:"minimum=0"`
}

func (r *BackupResult) validate(e *errs) {
	e.check("backup.snapshotId", r.SnapshotID)
	if r.SizeBytes < 0 || r.AddedBytes < 0 || r.DurationMs < 0 {
		e.addf("backup", "sizeBytes, addedBytes and durationMs must be >= 0")
	}
}

// RestoreResult is the outcome of a successful restore task.
type RestoreResult struct {
	// SizeBytes is the size of the dump fed into the engine's restore tool.
	SizeBytes  int64 `json:"sizeBytes" jsonschema:"minimum=0"`
	DurationMs int64 `json:"durationMs" jsonschema:"minimum=0"`
	// RestoredTo is the time of the last transaction replayed (base_backup restores).
	RestoredTo *time.Time `json:"restoredTo,omitzero" jsonschema:"nullable"`
}

func (r *RestoreResult) validate(e *errs) {
	if r.SizeBytes < 0 || r.DurationMs < 0 {
		e.addf("restore", "sizeBytes and durationMs must be >= 0")
	}
}

// MaintenanceResult is the outcome of a successful backup_maintenance task.
type MaintenanceResult struct {
	// RemovedSnapshots is the number of snapshots forget removed (forget only).
	RemovedSnapshots int   `json:"removedSnapshots,omitzero" jsonschema:"minimum=0"`
	DurationMs       int64 `json:"durationMs" jsonschema:"minimum=0"`
}

func (r *MaintenanceResult) validate(e *errs) {
	if r.RemovedSnapshots < 0 || r.DurationMs < 0 {
		e.addf("maintenance", "removedSnapshots and durationMs must be >= 0")
	}
}

// SnapshotInfo is one restic snapshot as listed by backup_snapshots.
type SnapshotInfo struct {
	ID   SnapshotID `json:"id"`
	Time time.Time  `json:"time"`
	// Tags are the snapshot's tags that match the BackupTag grammar (others are dropped).
	Tags []BackupTag `json:"tags" jsonschema:"maxItems=32"`
	// SizeBytes is the size of the backed-up data, if restic recorded it.
	SizeBytes int64 `json:"sizeBytes,omitzero" jsonschema:"minimum=0"`
}

// BackupSnapshotsResult lists snapshots, newest first.
type BackupSnapshotsResult struct {
	Snapshots []SnapshotInfo `json:"snapshots" jsonschema:"maxItems=1000"`
	// Truncated is true when more than MaxSnapshotsListed snapshots matched.
	Truncated bool `json:"truncated,omitzero"`
}

func (r *BackupSnapshotsResult) validate(e *errs) {
	if r.Snapshots == nil {
		e.addf("snapshots.snapshots", "is required")
	}
	checkCount(e, "snapshots.snapshots", len(r.Snapshots), 0, MaxSnapshotsListed)
	for i := range r.Snapshots {
		s := &r.Snapshots[i]
		p := idx("snapshots.snapshots", i)
		e.check(p+".id", s.ID)
		if s.Time.IsZero() {
			e.addf(p+".time", "is required")
		}
		if s.SizeBytes < 0 {
			e.addf(p+".sizeBytes", "must be >= 0")
		}
		checkCount(e, p+".tags", len(s.Tags), 0, maxSnapshotTags)
		for j, t := range s.Tags {
			e.check(idx(p+".tags", j), t)
		}
	}
}
