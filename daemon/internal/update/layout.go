package update

import (
	"encoding/json"
	"errors"
	"fmt"
	"io/fs"
	"os"
	"path/filepath"
	"time"

	"github.com/vantezzen/launchroom/daemon/internal/atomicfile"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// Layout below launchroomd's data directory:
//
//	update/running.json              the running launchroomd (version, start), after its first signed exchange
//	update/pending.json              the update this process (or its successor) must report
//	update/tuf/                      Sigstore TUF metadata cache
//	update/tasks/<task>/plan.json    what the supervisor does (host paths)
//	update/tasks/<task>/status.json  the supervisor's final outcome
//	update/tasks/<task>/bundle/      the verified, extracted release bundle
//	update/tasks/<task>/backup/      the managed files before the update
//	update/tasks/<task>/bundle.tar.gz (+ .sigstore.json) the signed bundle as downloaded
//
// On a guarded install (D49) the supervisor keeps its own state outside
// launchroomd's reach, below the install folder:
//
//	guard/trust.json                 the release identity (written by the installer)
//	guard/update/<task>/bundle/      the bundle the supervisor verified itself
//	guard/update/<task>/backup/      the managed files before the update
const (
	dirGuard      = "guard"
	fileBundleTar = "bundle.tar.gz"
	fileBundleSig = "bundle.tar.gz.sigstore.json"

	dirUpdate    = "update"
	fileRunning  = "running.json"
	filePending  = "pending.json"
	filePlan     = "plan.json"
	fileStatus   = "status.json"
	dirTUF       = "tuf"
	dirTasks     = "tasks"
	dirBundle    = "bundle"
	dirBackup    = "backup"
	keepTaskDirs = 3
)

func updateDir(dataDir string) string { return filepath.Join(dataDir, dirUpdate) }

// taskDir is the per-task working directory.
func taskDir(dataDir string, id state.TaskID) string {
	return filepath.Join(dataDir, dirUpdate, dirTasks, string(id))
}

// Running is written by every launchroomd after its first successful signed
// exchange with the control plane. The supervisor treats it as the health
// signal of a freshly started version.
type Running struct {
	Version   string    `json:"version"`
	StartedAt time.Time `json:"startedAt"`
	Pid       int       `json:"pid"`
}

// MarkRunning records that this process (version, started at) is up and
// talking to the control plane.
func MarkRunning(dataDir, version string, startedAt time.Time) error {
	if err := os.MkdirAll(updateDir(dataDir), 0o700); err != nil {
		return err
	}
	return writeJSON(filepath.Join(updateDir(dataDir), fileRunning), Running{Version: version, StartedAt: startedAt.UTC(), Pid: os.Getpid()})
}

func readRunning(dataDir string) (Running, error) {
	var r Running
	err := readJSON(filepath.Join(updateDir(dataDir), fileRunning), &r)
	return r, err
}

// Pending is the update whose outcome still has to be reported.
type Pending struct {
	TaskID  state.TaskID      `json:"taskId"`
	Attempt int               `json:"attempt"`
	Scope   state.UpdateScope `json:"scope"`
	From    string            `json:"fromVersion"`
	To      string            `json:"toVersion"`
	// Supervisor is the helper container's name.
	Supervisor string    `json:"supervisor"`
	CreatedAt  time.Time `json:"createdAt"`
}

// Plan tells the supervisor what to do. Paths are HOST paths: the
// supervisor and its helpers mount the install folder at the same path.
type Plan struct {
	TaskID  state.TaskID      `json:"taskId"`
	Attempt int               `json:"attempt"`
	Scope   state.UpdateScope `json:"scope"`
	From    string            `json:"fromVersion"`
	To      string            `json:"toVersion"`
	// ProjectDir is the install folder (compose working dir), e.g. /opt/launchroom.
	ProjectDir string `json:"projectDir"`
	// Project is the compose project name.
	Project string `json:"project"`
	// Service is launchroomd's compose service.
	Service string `json:"service"`
	// DaemonContainer is the container of the launchroomd that started the
	// update. If the update fails before the installer replaced it, that
	// process is still the one running after the rollback.
	DaemonContainer string `json:"daemonContainer,omitempty"`
	// DataDir is launchroomd's /data on the host.
	DataDir string `json:"dataDir"`
	// SocketPath is the Docker socket on the host.
	SocketPath string `json:"socketPath"`
	// BundleDir is the verified, extracted bundle (host path).
	BundleDir    string         `json:"bundleDir"`
	DaemonImage  state.ImageRef `json:"daemonImage"`
	ComposeImage state.ImageRef `json:"composeImage"`
	// InstallTimeout bounds the installer run; HealthTimeout the wait for
	// the new launchroomd (and, on rollback, the old one).
	InstallTimeout Duration `json:"installTimeout"`
	HealthTimeout  Duration `json:"healthTimeout"`
}

// Outcome is the supervisor's verdict.
type Outcome string

// Outcomes.
const (
	OutcomeSucceeded  Outcome = "succeeded"
	OutcomeRolledBack Outcome = "rolled_back"
	// OutcomeFailed: nothing was changed, or the rollback failed too.
	OutcomeFailed Outcome = "failed"
)

// Status is the final result of one update task.
type Status struct {
	TaskID     state.TaskID      `json:"taskId"`
	Scope      state.UpdateScope `json:"scope"`
	From       string            `json:"fromVersion"`
	To         string            `json:"toVersion"`
	Outcome    Outcome           `json:"outcome"`
	Error      string            `json:"error,omitempty"`
	FinishedAt time.Time         `json:"finishedAt"`
}

// Completion converts the status into the task completion fields.
func (s Status) Completion(attempt int) *state.TaskCompletion {
	done := &state.TaskCompletion{TypeMeta: state.NewTypeMeta(state.KindTaskCompletion), Attempt: attempt,
		SelfUpdate: &state.SelfUpdateResult{Scope: s.Scope, FromVersion: state.Semver(s.From), ToVersion: state.Semver(s.To), RolledBack: s.Outcome == OutcomeRolledBack}}
	if s.Outcome == OutcomeSucceeded {
		done.Outcome = state.OutcomeSucceeded
	} else {
		done.Outcome = state.OutcomeFailed
		done.Error = clip(s.Error)
	}
	return done
}

// Err is the status as a handler error (nil on success).
func (s Status) Err() error {
	if s.Outcome == OutcomeSucceeded {
		return nil
	}
	return errors.New(clip(s.Error))
}

// Duration is a JSON duration string ("3m0s").
type Duration time.Duration

// MarshalJSON implements json.Marshaler.
func (d Duration) MarshalJSON() ([]byte, error) { return json.Marshal(time.Duration(d).String()) }

// UnmarshalJSON implements json.Unmarshaler.
func (d *Duration) UnmarshalJSON(b []byte) error {
	var s string
	if err := json.Unmarshal(b, &s); err != nil {
		return err
	}
	v, err := time.ParseDuration(s)
	if err != nil {
		return err
	}
	*d = Duration(v)
	return nil
}

func writeJSON(path string, v any) error {
	b, err := json.MarshalIndent(v, "", "  ")
	if err != nil {
		return err
	}
	return atomicfile.Write(path, append(b, '\n'), 0o600)
}

func readJSON(path string, v any) error {
	b, err := readLimited(path, 1<<20)
	if err != nil {
		return err
	}
	if err := json.Unmarshal(b, v); err != nil {
		return fmt.Errorf("%s: %w", filepath.Base(path), err)
	}
	return nil
}

// readStatus returns the recorded outcome of a task (fs.ErrNotExist if none).
func readStatus(dataDir string, id state.TaskID) (Status, error) {
	var s Status
	err := readJSON(filepath.Join(taskDir(dataDir, id), fileStatus), &s)
	return s, err
}

func writeStatus(dataDir string, s Status) error {
	return writeJSON(filepath.Join(taskDir(dataDir, s.TaskID), fileStatus), s)
}

func readPending(dataDir string) (*Pending, error) {
	var p Pending
	err := readJSON(filepath.Join(updateDir(dataDir), filePending), &p)
	if errors.Is(err, fs.ErrNotExist) {
		return nil, nil
	}
	if err != nil {
		return nil, err
	}
	return &p, nil
}

func removePending(dataDir string, id state.TaskID) {
	if p, err := readPending(dataDir); err == nil && p != nil && p.TaskID == id {
		_ = os.Remove(filepath.Join(updateDir(dataDir), filePending))
	}
}

// pruneTaskDirs keeps the newest keepTaskDirs task directories (and the
// current one).
func pruneTaskDirs(dataDir string, current state.TaskID) {
	root := filepath.Join(updateDir(dataDir), dirTasks)
	entries, err := os.ReadDir(root)
	if err != nil {
		return
	}
	type dir struct {
		name string
		mod  time.Time
	}
	var dirs []dir
	for _, e := range entries {
		if !e.IsDir() || e.Name() == string(current) {
			continue
		}
		if info, err := e.Info(); err == nil {
			dirs = append(dirs, dir{e.Name(), info.ModTime()})
		}
	}
	for len(dirs) > keepTaskDirs-1 {
		oldest := 0
		for i := range dirs {
			if dirs[i].mod.Before(dirs[oldest].mod) {
				oldest = i
			}
		}
		_ = os.RemoveAll(filepath.Join(root, dirs[oldest].name))
		dirs = append(dirs[:oldest], dirs[oldest+1:]...)
	}
}

func clip(s string) string {
	if len(s) > 4000 {
		return s[:4000]
	}
	return s
}
