package state

import (
	"errors"
	"regexp"
	"time"
	"unicode/utf8"
)

// Container inspection (docs/22 §7.2, docs/03 §13.2): an "inspect_container"
// task reads what changed in a workload container's writable layer and which
// processes run in it, for an investigation. Read-only: launchroomd reads
// through dockerd-guard's container inspect, changes, top and archive HEAD
// routes and never reads file contents.

// Limits of inspection documents.
const (
	MaxInspectionChanges   = 2000
	MaxInspectionCollapsed = 64
	MaxInspectionProcesses = 256
	MaxInspectionStats     = 256
	maxInspectedPath       = 1024
	maxProcessCommand      = 512
	maxChangeFlags         = 8
)

var (
	// reInspectedPath: absolute, no control characters (launchroomd escapes
	// them as \xNN and a backslash as \\ before sending).
	reInspectedPath = regexp.MustCompile(`^/[^\x00-\x1f\x7f]*$`)
	reInspectedUser = regexp.MustCompile(`^[A-Za-z0-9._+-]{1,32}$`)
	changeKinds     = []string{string(ChangeAdded), string(ChangeModified), string(ChangeDeleted)}
	fileTypes       = []string{string(FileRegular), string(FileDirectory), string(FileSymlink), string(FileOther)}
	changeFlags     = []string{
		string(ChangeFlagExecutable), string(ChangeFlagSystemPath), string(ChangeFlagSensitiveFile),
		string(ChangeFlagAppCode), string(ChangeFlagTempDir), string(ChangeFlagHidden),
	}
	processFlags = []string{
		string(ProcessFlagEntry), string(ProcessFlagShell), string(ProcessFlagNetworkTool), string(ProcessFlagMiner),
		string(ProcessFlagTempExecutable), string(ProcessFlagInlineCode), string(ProcessFlagAddedBinary),
	}
)

// InspectContainerSpec asks for the changes and processes of one workload's
// running container (task "inspect_container", daemon feature
// "containerInspection"). Pinned to the replica's server, one attempt.
type InspectContainerSpec struct {
	// Workload is the replica's workload id; it must be in the server's
	// verified NodeState.
	Workload WorkloadID `json:"workload"`
	// MaskEnv names variables of the workload's env whose values (and their
	// encodings, and the password of URL values) are replaced by "***" in
	// process commands (docs/09 R16). Names missing from the env are ignored.
	MaskEnv []EnvName `json:"maskEnv,omitzero" jsonschema:"nullable,maxItems=1024"`
}

// TaskType implements TaskSpec.
func (*InspectContainerSpec) TaskType() TaskType { return TaskInspectContainer }

// Validate implements TaskSpec.
func (s *InspectContainerSpec) Validate() error {
	var e errs
	e.check("workload", s.Workload)
	checkCount(&e, "maskEnv", len(s.MaskEnv), 0, maxMaskEnvNames)
	for i, n := range s.MaskEnv {
		e.check(idx("maskEnv", i), n)
	}
	unique(&e, "maskEnv", s.MaskEnv)
	return e.err()
}

// ContainerInspectionResult is TaskCompletion.containerInspection of a
// succeeded inspect_container task.
type ContainerInspectionResult struct {
	Workload    WorkloadID `json:"workload"`
	ContainerID string     `json:"containerId" jsonschema:"pattern=^[a-f0-9]{64}$"`
	// ImageID is the image the container runs ("sha256:<64 hex>").
	ImageID string `json:"imageId" jsonschema:"pattern=^sha256:[a-f0-9]{64}$"`
	// ImageMatches is true when that image is the one of the workload's
	// image reference in the verified NodeState.
	ImageMatches bool      `json:"imageMatches"`
	StartedAt    time.Time `json:"startedAt"`
	RestartCount int       `json:"restartCount" jsonschema:"minimum=0"`
	// User is the container's configured user (omitted: the image default, root).
	User InspectedUser `json:"user,omitzero"`
	// WorkingDir is the container's working directory (the app's files).
	WorkingDir InspectedPath      `json:"workingDir,omitzero"`
	Changes    ContainerChanges   `json:"changes"`
	Processes  ContainerProcesses `json:"processes"`
	// InspectedAt is when launchroomd read the container.
	InspectedAt time.Time `json:"inspectedAt"`
}

// ContainerChanges are the writable layer's changes since the container
// was created (Docker's container changes).
type ContainerChanges struct {
	// Total is the number of changes Docker reported.
	Total int `json:"total" jsonschema:"minimum=0"`
	// Entries are changes outside collapsed locations, most interesting
	// first (flagged, then the working dir, then the rest), ≤ 2000.
	Entries []ContainerChange `json:"entries" jsonschema:"maxItems=2000"`
	// Collapsed counts changes under known cache locations (package manager
	// caches, .next/cache, __pycache__, /var/cache, /var/log, …).
	Collapsed []CollapsedChanges `json:"collapsed,omitzero" jsonschema:"nullable,maxItems=64"`
	// Truncated is true when changes were left out because of the entry limit.
	Truncated bool `json:"truncated,omitzero"`
}

// ContainerChange is one changed path.
type ContainerChange struct {
	Path InspectedPath `json:"path"`
	Kind ChangeKind    `json:"kind"`
	// Type, Size, Executable and ModifiedAt come from a stat of the path
	// (archive HEAD), done for at most 256 added or modified paths; omitted
	// otherwise and for deleted paths.
	Type       FileType   `json:"type,omitzero"`
	Size       int64      `json:"size,omitzero" jsonschema:"minimum=0"`
	Executable bool       `json:"executable,omitzero"`
	ModifiedAt *time.Time `json:"modifiedAt,omitzero" jsonschema:"nullable"`
	// Flags are launchroomd's fixed classification rules (docs/03 §13.2).
	Flags []ChangeFlag `json:"flags,omitzero" jsonschema:"nullable,maxItems=8"`
}

// CollapsedChanges counts the changes under one cache location.
type CollapsedChanges struct {
	Prefix InspectedPath `json:"prefix"`
	Count  int           `json:"count" jsonschema:"minimum=1"`
}

// ContainerProcesses is the container's process list (Docker's top with
// its default ps arguments).
type ContainerProcesses struct {
	Total int `json:"total" jsonschema:"minimum=0"`
	// Entries in PID order, ≤ 256.
	Entries   []ContainerProcess `json:"entries" jsonschema:"maxItems=256"`
	Truncated bool               `json:"truncated,omitzero"`
	// ParentsUnknown is true when the host's ps printed no parent PIDs
	// (busybox): every ppid is 0, only the first process (the lowest PID)
	// is flagged entry, and no other process can be judged by its parent.
	ParentsUnknown bool `json:"parentsUnknown,omitzero"`
}

// ContainerProcess is one process (host PIDs, as Docker reports them).
type ContainerProcess struct {
	PID  int           `json:"pid" jsonschema:"minimum=1"`
	PPID int           `json:"ppid" jsonschema:"minimum=0"`
	User InspectedUser `json:"user"`
	// Command is the command line, masked against the spec's maskEnv
	// values, control characters escaped, cut to 512 bytes.
	Command string        `json:"command" jsonschema:"maxLength=512"`
	Flags   []ProcessFlag `json:"flags,omitzero" jsonschema:"nullable,maxItems=8"`
}

func (r *ContainerInspectionResult) validate(e *errs) {
	const p = "containerInspection"
	e.check(p+".workload", r.Workload)
	if !reContainerID.MatchString(r.ContainerID) {
		e.addf(p+".containerId", "must be 64 lowercase hex characters")
	}
	if !reImageID.MatchString(r.ImageID) {
		e.addf(p+".imageId", "must be sha256:<64 hex>")
	}
	if r.StartedAt.IsZero() {
		e.addf(p+".startedAt", "is required")
	}
	if r.RestartCount < 0 {
		e.addf(p+".restartCount", "must be >= 0")
	}
	if r.User != "" {
		e.check(p+".user", r.User)
	}
	if r.WorkingDir != "" {
		e.check(p+".workingDir", r.WorkingDir)
	}
	if r.InspectedAt.IsZero() {
		e.addf(p+".inspectedAt", "is required")
	}
	c := r.Changes
	if c.Total < 0 {
		e.addf(p+".changes.total", "must be >= 0")
	}
	checkCount(e, p+".changes.entries", len(c.Entries), 0, MaxInspectionChanges)
	stats := 0
	paths := make([]InspectedPath, 0, len(c.Entries))
	for i, ch := range c.Entries {
		q := idx(p+".changes.entries", i)
		e.check(q+".path", ch.Path)
		e.check(q+".kind", ch.Kind)
		if ch.Type != "" {
			e.check(q+".type", ch.Type)
			stats++
			if ch.Kind == ChangeDeleted {
				e.addf(q+".type", "is not valid for deleted paths")
			}
		} else if ch.Size != 0 || ch.Executable || ch.ModifiedAt != nil {
			e.addf(q, "size, executable and modifiedAt need a type")
		}
		if ch.Size < 0 {
			e.addf(q+".size", "must be >= 0")
		}
		checkCount(e, q+".flags", len(ch.Flags), 0, maxChangeFlags)
		for j, f := range ch.Flags {
			e.check(idx(q+".flags", j), f)
		}
		unique(e, q+".flags", ch.Flags)
		paths = append(paths, ch.Path)
	}
	unique(e, p+".changes.entries[].path", paths)
	if stats > MaxInspectionStats {
		e.addf(p+".changes.entries", "at most %d entries may carry stat fields", MaxInspectionStats)
	}
	checkCount(e, p+".changes.collapsed", len(c.Collapsed), 0, MaxInspectionCollapsed)
	collapsed := len(c.Entries)
	for i, cc := range c.Collapsed {
		q := idx(p+".changes.collapsed", i)
		e.check(q+".prefix", cc.Prefix)
		if cc.Count < 1 {
			e.addf(q+".count", "must be >= 1")
		}
		collapsed += cc.Count
	}
	if collapsed > c.Total {
		e.addf(p+".changes", "entries and collapsed counts exceed total")
	}
	pr := r.Processes
	if pr.Total < 0 {
		e.addf(p+".processes.total", "must be >= 0")
	}
	checkCount(e, p+".processes.entries", len(pr.Entries), 0, MaxInspectionProcesses)
	if len(pr.Entries) > pr.Total {
		e.addf(p+".processes", "more entries than total")
	}
	pids := make([]int, 0, len(pr.Entries))
	for i, proc := range pr.Entries {
		q := idx(p+".processes.entries", i)
		if proc.PID < 1 {
			e.addf(q+".pid", "must be >= 1")
		}
		if proc.PPID < 0 {
			e.addf(q+".ppid", "must be >= 0")
		}
		e.check(q+".user", proc.User)
		checkPlainText(e, q+".command", proc.Command, maxProcessCommand)
		checkCount(e, q+".flags", len(proc.Flags), 0, maxChangeFlags)
		for j, f := range proc.Flags {
			e.check(idx(q+".flags", j), f)
		}
		unique(e, q+".flags", proc.Flags)
		pids = append(pids, proc.PID)
	}
	unique(e, p+".processes.entries[].pid", pids)
}

var (
	reImageID      = regexp.MustCompile(`^sha256:[a-f0-9]{64}$`)
	errInvalidUTF8 = errors.New("must be valid UTF-8")
)

// InspectedPath is a path inside a container as reported by an inspection:
// absolute, valid UTF-8, no control characters (escaped as \xNN by
// launchroomd), ≤ 1024 bytes. Unlike ContainerPath it may contain any
// printable character, since files are named by whoever wrote them.
type InspectedPath string

// ProtocolSchema implements SchemaDescriber.
func (InspectedPath) ProtocolSchema() ScalarSchema {
	return strSchema(reInspectedPath, maxInspectedPath)
}

// Validate checks the grammar.
func (v InspectedPath) Validate() error {
	if err := checkPattern("inspected path", string(v), reInspectedPath, maxInspectedPath); err != nil {
		return err
	}
	if !utf8.ValidString(string(v)) {
		return errInvalidUTF8
	}
	return nil
}

// InspectedUser is a user name or numeric id as ps prints it.
type InspectedUser string

// ProtocolSchema implements SchemaDescriber.
func (InspectedUser) ProtocolSchema() ScalarSchema { return strSchema(reInspectedUser, 32) }

// Validate checks the grammar.
func (v InspectedUser) Validate() error {
	return checkPattern("user", string(v), reInspectedUser, 32)
}

// ChangeKind is Docker's change kind.
type ChangeKind string

// Change kinds.
const (
	ChangeAdded    ChangeKind = "added"
	ChangeModified ChangeKind = "modified"
	ChangeDeleted  ChangeKind = "deleted"
)

// ProtocolSchema implements SchemaDescriber.
func (ChangeKind) ProtocolSchema() ScalarSchema { return enumSchema(changeKinds) }

// Validate checks the value.
func (v ChangeKind) Validate() error { return checkEnum("change kind", string(v), changeKinds) }

// FileType is the type of a stat'ed path.
type FileType string

// File types.
const (
	FileRegular   FileType = "file"
	FileDirectory FileType = "dir"
	FileSymlink   FileType = "symlink"
	FileOther     FileType = "other"
)

// ProtocolSchema implements SchemaDescriber.
func (FileType) ProtocolSchema() ScalarSchema { return enumSchema(fileTypes) }

// Validate checks the value.
func (v FileType) Validate() error { return checkEnum("file type", string(v), fileTypes) }

// ChangeFlag is one of launchroomd's fixed classification rules for a change.
type ChangeFlag string

// Change flags (rules: docs/03 §13.2).
const (
	// ChangeFlagExecutable: a regular file with an execute bit.
	ChangeFlagExecutable ChangeFlag = "executable"
	// ChangeFlagSystemPath: under /bin, /sbin, /usr/bin, /usr/sbin,
	// /usr/local/bin, /usr/local/sbin, /lib, /lib64, /usr/lib, /usr/lib64.
	ChangeFlagSystemPath ChangeFlag = "system_path"
	// ChangeFlagSensitiveFile: accounts, cron, preload, shell profiles, SSH keys.
	ChangeFlagSensitiveFile ChangeFlag = "sensitive_file"
	// ChangeFlagAppCode: under the container's working directory.
	ChangeFlagAppCode ChangeFlag = "app_code"
	// ChangeFlagTempDir: under /tmp, /var/tmp or /dev/shm.
	ChangeFlagTempDir ChangeFlag = "temp_dir"
	// ChangeFlagHidden: a dot-file or dot-directory outside home directories.
	ChangeFlagHidden ChangeFlag = "hidden"
)

// ProtocolSchema implements SchemaDescriber.
func (ChangeFlag) ProtocolSchema() ScalarSchema { return enumSchema(changeFlags) }

// Validate checks the value.
func (v ChangeFlag) Validate() error { return checkEnum("change flag", string(v), changeFlags) }

// ProcessFlag is one of launchroomd's fixed classification rules for a process.
type ProcessFlag string

// Process flags (rules: docs/03 §13.2).
const (
	// ProcessFlagEntry: the container's first process (its parent is not in
	// the list; without parent PIDs, the lowest PID: ContainerProcesses.parentsUnknown).
	ProcessFlagEntry ProcessFlag = "entry"
	// ProcessFlagShell: a shell that is not the entry process.
	ProcessFlagShell ProcessFlag = "shell"
	// ProcessFlagNetworkTool: nc, ncat, netcat, socat, curl, wget, telnet, ssh, scp, nmap, masscan.
	ProcessFlagNetworkTool ProcessFlag = "network_tool"
	// ProcessFlagMiner: a known crypto-miner name (xmrig, minerd, cpuminer, kinsing, kdevtmpfsi).
	ProcessFlagMiner ProcessFlag = "miner"
	// ProcessFlagTempExecutable: the program runs from /tmp, /var/tmp or /dev/shm.
	ProcessFlagTempExecutable ProcessFlag = "temp_executable"
	// ProcessFlagInlineCode: an interpreter given code on the command line
	// (python -c, perl -e, node -e, php -r, ruby -e, a shell with -c) by a process
	// other than the entry process.
	ProcessFlagInlineCode ProcessFlag = "inline_code"
	// ProcessFlagAddedBinary: the program is a path the container added.
	ProcessFlagAddedBinary ProcessFlag = "added_binary"
)

// ProtocolSchema implements SchemaDescriber.
func (ProcessFlag) ProtocolSchema() ScalarSchema { return enumSchema(processFlags) }

// Validate checks the value.
func (v ProcessFlag) Validate() error { return checkEnum("process flag", string(v), processFlags) }
