package reconcile

import (
	"bytes"
	"encoding/json"
	"errors"
	"io/fs"
	"os"
	"slices"
	"time"

	"github.com/vantezzen/launchroom/daemon/internal/atomicfile"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// Persisted lifecycle marks (docs/03 §9): what launchroomd last saw of each
// workload's container is written to Options.MarksFile, so after a restart
// of launchroomd (a crash, an update, a host reboot) exits and restarts that
// happened while it was down are still reported once, instead of being
// skipped as "before launchroomd started".

// marksVersion is the file format version.
const marksVersion = 1

type marksFile struct {
	Version int          `json:"version"`
	Marks   []markRecord `json:"marks"`
}

type markRecord struct {
	Workload   state.WorkloadID   `json:"workload"`
	Container  string             `json:"container"`
	Deployment state.DeploymentID `json:"deployment,omitempty"`
	StartedAt  time.Time          `json:"startedAt"`
	FinishedAt time.Time          `json:"finishedAt"`
	Restarts   int                `json:"restarts"`
}

// loadMarks reads the persisted marks (missing or unreadable: none; the
// file is only a hint, the containers are the truth).
func (r *Reconciler) loadMarks() {
	if r.o.MarksFile == "" {
		return
	}
	raw, err := os.ReadFile(r.o.MarksFile)
	if err != nil {
		if !errors.Is(err, fs.ErrNotExist) {
			r.o.Log.Warn("cannot read the saved container marks", "err", err)
		}
		return
	}
	var f marksFile
	if err := json.Unmarshal(raw, &f); err != nil || f.Version != marksVersion {
		r.o.Log.Warn("ignoring the saved container marks", "err", err, "version", f.Version)
		return
	}
	for _, m := range f.Marks {
		if m.Workload.Validate() != nil || len(m.Container) != 64 {
			continue
		}
		if m.Deployment != "" && m.Deployment.Validate() != nil {
			m.Deployment = ""
		}
		r.marks[m.Workload] = mark{container: m.Container, deployment: m.Deployment, startedAt: m.StartedAt, finishedAt: m.FinishedAt, restarts: m.Restarts}
	}
	r.marksSaved = raw
}

// saveMarks writes the marks when they changed (best effort).
func (r *Reconciler) saveMarks() {
	if r.o.MarksFile == "" {
		return
	}
	// One writer at a time, so an older snapshot never overwrites a newer one.
	r.marksWrite.Lock()
	defer r.marksWrite.Unlock()
	r.mu.Lock()
	f := marksFile{Version: marksVersion, Marks: make([]markRecord, 0, len(r.marks))}
	for id, m := range r.marks {
		if m.container == "" {
			continue
		}
		f.Marks = append(f.Marks, markRecord{
			Workload: id, Container: m.container, Deployment: m.deployment,
			StartedAt: m.startedAt.UTC(), FinishedAt: m.finishedAt.UTC(), Restarts: m.restarts,
		})
	}
	slices.SortFunc(f.Marks, func(a, b markRecord) int {
		switch {
		case a.Workload < b.Workload:
			return -1
		case a.Workload > b.Workload:
			return 1
		}
		return 0
	})
	raw, err := json.Marshal(f)
	if err != nil || bytes.Equal(raw, r.marksSaved) {
		r.mu.Unlock()
		return
	}
	r.marksSaved = raw
	r.mu.Unlock()
	if err := atomicfile.Write(r.o.MarksFile, raw, 0o600); err != nil {
		r.o.Log.Warn("cannot save the container marks", "err", err)
	}
}
