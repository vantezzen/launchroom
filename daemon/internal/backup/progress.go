package backup

import (
	"bytes"
	"context"
	"encoding/json"
	"slices"
	"sync"
	"time"

	"github.com/vantezzen/launchroom/daemon/internal/tasks"
)

// ProgressPrefix starts every machine-readable progress line in a backup,
// restore, maintenance or verify task log; the rest of the line is one JSON
// object (ProgressLine). Human-readable lines never start with it. The
// handler ships these lines on the "system" stream.
const ProgressPrefix = "::progress:: "

// ProgressVersion is ProgressLine.V. Readers ignore lines of other versions.
const ProgressVersion = 1

// Progress phases (ProgressLine.Phase).
const (
	PhaseWaiting    = "waiting"   // another backup, restore, import or export holds the server's slot
	PhasePreparing  = "preparing" // opening (or initialising) the repository, finding the snapshot
	PhaseStreaming  = "streaming" // backup: dump/volume read and uploaded; bytes grows
	PhaseFinishing  = "finishing" // backup: source fully read, restic writing the last data and the snapshot
	PhaseStored     = "stored"    // backup: snapshot stored (snapshot, addedBytes set)
	PhaseRestoring  = "restoring" // restore: snapshot read into the target; bytes grows, totalBytes when known
	PhaseForgetting = "forgetting"
	PhasePruning    = "pruning"
	PhaseChecking   = "checking"
	PhaseVerifying  = "verifying"
	PhaseDone       = "done" // restore, maintenance or verify finished
)

// ProgressLine is the JSON after ProgressPrefix. It carries counters, phase
// names and a short snapshot id only: never credentials, paths or names.
type ProgressLine struct {
	V     int    `json:"v"`
	Op    string `json:"op"` // backup | restore | maintenance | verify (the task log step)
	Phase string `json:"phase"`
	// Bytes is the size read so far (backup: the dump or volume tar fed to
	// restic; restore: the snapshot read back). Absent outside streaming phases.
	Bytes int64 `json:"bytes,omitempty"`
	// TotalBytes is the expected size when launchroomd knows it (restore:
	// the snapshot's recorded size; backup: only if restic reports one).
	TotalBytes int64 `json:"totalBytes,omitempty"`
	// Percent is restic's own estimate (0–100) when it reports a total.
	Percent   float64 `json:"percent,omitempty"`
	ElapsedMs int64   `json:"elapsedMs"`
	// Snapshot and AddedBytes are set on "stored".
	Snapshot   string `json:"snapshot,omitempty"`
	AddedBytes int64  `json:"addedBytes,omitempty"`
}

// ProgressText renders a progress line for the task log.
func ProgressText(p ProgressLine) string {
	return ProgressPrefix + progressJSON(p)
}

func progressJSON(p ProgressLine) string {
	p.V = ProgressVersion
	b, _ := json.Marshal(p) //nolint:errchkjson // plain struct of strings and numbers
	return string(b)
}

// IsProgress reports whether a task log line is a machine-readable progress line.
func IsProgress(text string) bool {
	return len(text) >= len(ProgressPrefix) && text[:len(ProgressPrefix)] == ProgressPrefix
}

// Throttling of the periodic lines: every ProgressEvery for the first
// progressSlowAfter, then every progressSlowFactor × ProgressEvery; a line
// whose counters did not change is skipped unless progressQuietMax passed.
const (
	progressSlowAfter  = 5 * time.Minute
	progressSlowFactor = 5
	progressQuietMax   = 30 * time.Second
)

// tracker emits progress lines for one engine operation. Its logf is
// serialised so the periodic goroutine and the operation never interleave.
type tracker struct {
	ctx   context.Context
	op    string
	every time.Duration
	start time.Time
	now   func() time.Time
	raw   Logf

	mu       sync.Mutex
	cur      ProgressLine
	bytes    func() int64
	restic   *resticOutput
	lastAt   time.Time
	lastSeen ProgressLine
	stop     chan struct{}
	done     chan struct{}
}

func (e *Engine) track(ctx context.Context, op string, logf Logf) *tracker {
	if logf == nil {
		logf = nopLog
	}
	return &tracker{ctx: ctx, op: op, every: e.o.ProgressEvery, start: e.o.Now(), now: e.o.Now, raw: logf}
}

// logf is the operation's human-readable log, serialised with progress lines.
func (t *tracker) logf(format string, args ...any) {
	t.mu.Lock()
	defer t.mu.Unlock()
	t.raw(format, args...)
}

// phase switches to a new phase and logs it at once. bytes (nil: none) is
// sampled by the periodic lines until the next phase.
func (t *tracker) phase(p string, bytes func() int64, total int64) {
	t.mu.Lock()
	defer t.mu.Unlock()
	t.cur = ProgressLine{Op: t.op, Phase: p, TotalBytes: total}
	t.bytes = bytes
	t.emitLocked(true)
}

// stored logs the final line of a backup.
func (t *tracker) stored(size int64, snapshot string, added int64) {
	t.stopTicker()
	t.mu.Lock()
	defer t.mu.Unlock()
	t.cur = ProgressLine{Op: t.op, Phase: PhaseStored, Bytes: size, Snapshot: short(snapshot), AddedBytes: max(added, 0)}
	t.bytes = nil
	t.emitLocked(true)
}

// finish logs a final phase (PhaseDone) with an optional size.
func (t *tracker) finish(size int64) {
	t.stopTicker()
	t.mu.Lock()
	defer t.mu.Unlock()
	t.cur = ProgressLine{Op: t.op, Phase: PhaseDone, Bytes: size}
	t.bytes = nil
	t.emitLocked(true)
}

// ticking starts the periodic lines; close stops them (idempotent, also
// called by stored and finish).
func (t *tracker) ticking() (closeFn func()) {
	t.mu.Lock()
	if t.stop != nil || t.every <= 0 {
		t.mu.Unlock()
		return t.stopTicker
	}
	t.stop, t.done = make(chan struct{}), make(chan struct{})
	stop, done := t.stop, t.done
	t.mu.Unlock()
	go func() {
		defer close(done)
		tick := time.NewTicker(t.every)
		defer tick.Stop()
		for {
			select {
			case <-stop:
				return
			case <-t.ctx.Done():
				return
			case <-tick.C:
				t.mu.Lock()
				t.emitLocked(false)
				t.mu.Unlock()
			}
		}
	}()
	return t.stopTicker
}

func (t *tracker) stopTicker() {
	t.mu.Lock()
	stop, done := t.stop, t.done
	t.stop, t.done = nil, nil
	t.mu.Unlock()
	if stop != nil {
		close(stop)
		<-done
	}
}

func (t *tracker) emitLocked(force bool) {
	now := t.now()
	line := t.cur
	if t.bytes != nil {
		line.Bytes = t.bytes()
	}
	if t.restic != nil && line.Phase == PhaseStreaming {
		if st := t.restic.latest(); st.TotalBytes > 0 {
			line.TotalBytes, line.Percent = st.TotalBytes, min(max(st.PercentDone*100, 0), 100)
		}
	}
	if !force {
		every := t.every
		if now.Sub(t.start) > progressSlowAfter {
			every *= progressSlowFactor
		}
		since := now.Sub(t.lastAt)
		if since < every-every/10 || (line == t.lastSeen && since < progressQuietMax) {
			return
		}
	}
	t.lastAt, t.lastSeen = now, line
	line.ElapsedMs = now.Sub(t.start).Milliseconds()
	t.raw(ProgressPrefix+"%s", progressJSON(line))
	tasks.SetProgress(t.ctx, progressSummary(line))
}

// progressSummary is the short heartbeat status line (docs/03 §7).
func progressSummary(p ProgressLine) string {
	switch p.Phase {
	case PhaseWaiting:
		return "Waiting for another backup or restore on this server"
	case PhaseStreaming:
		return "Backing up: " + humanBytes(p.Bytes) + " read"
	case PhaseFinishing:
		return "Backing up: storing the snapshot"
	case PhaseStored:
		return "Snapshot stored"
	case PhaseRestoring:
		if p.Bytes > 0 {
			return "Restoring: " + humanBytes(p.Bytes) + " read"
		}
		return "Restoring"
	case PhaseForgetting, PhasePruning, PhaseChecking:
		return "Tidying up old backups"
	case PhaseVerifying:
		return "Verifying a backup"
	case PhaseDone:
		return "Finished"
	}
	return "Preparing"
}

// resticStatus is the subset of restic's --json status messages we use.
type resticStatus struct {
	MessageType string  `json:"message_type"`
	PercentDone float64 `json:"percent_done"`
	TotalBytes  int64   `json:"total_bytes"`
	BytesDone   int64   `json:"bytes_done"`
}

// resticOutput collects `restic backup --json` stdout: it keeps the latest
// status message for progress and every other line (the summary, errors)
// for parsing afterwards, so status lines don't pile up in memory.
type resticOutput struct {
	mu      sync.Mutex
	partial []byte
	kept    bytes.Buffer
	status  resticStatus
}

func (o *resticOutput) Write(p []byte) (int, error) {
	o.mu.Lock()
	defer o.mu.Unlock()
	o.partial = append(o.partial, p...)
	for {
		i := bytes.IndexByte(o.partial, '\n')
		if i < 0 {
			break
		}
		o.line(o.partial[:i+1])
		o.partial = o.partial[i+1:]
	}
	if len(o.partial) > 4<<20 { // a runaway line: keep it for the parser, stop buffering it
		o.kept.Write(o.partial)
		o.partial = nil
	}
	return len(p), nil
}

func (o *resticOutput) line(l []byte) {
	if t := bytes.TrimSpace(l); bytes.Contains(t, []byte(`"message_type":"status"`)) {
		var s resticStatus
		if json.Unmarshal(t, &s) == nil && s.MessageType == "status" {
			o.status = s
			return
		}
	}
	o.kept.Write(l)
}

func (o *resticOutput) latest() resticStatus {
	o.mu.Lock()
	defer o.mu.Unlock()
	return o.status
}

// Bytes returns the kept (non-status) output.
func (o *resticOutput) Bytes() []byte {
	o.mu.Lock()
	defer o.mu.Unlock()
	return append(slices.Clone(o.kept.Bytes()), o.partial...)
}
