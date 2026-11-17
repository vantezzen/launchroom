package watchdog

import (
	"bytes"
	"context"
	"errors"
	"fmt"
	"log/slog"
	"os"
	"path/filepath"
	"runtime/pprof"
	"sync"
	"time"
)

// launchroomd's own watchdog (docs/03 §9, PAIN-1): its loops mark the work
// they do (Begin/end), and a goroutine checks every 30 s that none of it
// has run past its deadline. While everything moves it touches the
// heartbeat file, which `launchroomd healthcheck` (the container's
// healthcheck) reads. When a loop is stuck it writes a note with every
// goroutine's stack to the crash output and exits, so the container's
// restart policy brings launchroomd back; the next run reports the note as
// the previous run's panic (Report.host.daemon.previousExit).

// Self-watchdog defaults.
const (
	// HeartbeatMaxAge is how old the heartbeat file may be before
	// `launchroomd healthcheck` says unhealthy.
	HeartbeatMaxAge = 5 * time.Minute
	// SelfWatchInterval is how often the self-watchdog checks.
	SelfWatchInterval = 30 * time.Second
	// StallExitCode is launchroomd's exit status after a stall.
	StallExitCode = 3
)

// SelfWatchOptions configures a SelfWatch.
type SelfWatchOptions struct {
	// File is the heartbeat file (touched while nothing is stuck).
	File string
	// Interval between checks (default SelfWatchInterval).
	Interval time.Duration
	// OnStall receives the stall note before Exit (the crash output).
	OnStall func(note []byte)
	// Exit ends the process (default os.Exit).
	Exit func(code int)
	Now  func() time.Time
	Log  *slog.Logger
}

// SelfWatch is launchroomd's in-process watchdog.
type SelfWatch struct {
	o SelfWatchOptions

	mu   sync.Mutex
	next uint64
	ops  map[uint64]selfOp
}

type selfOp struct {
	name     string
	started  time.Time
	deadline time.Duration
}

// NewSelfWatch returns a SelfWatch.
func NewSelfWatch(o SelfWatchOptions) *SelfWatch {
	if o.Interval <= 0 {
		o.Interval = SelfWatchInterval
	}
	if o.Exit == nil {
		o.Exit = os.Exit
	}
	if o.Now == nil {
		o.Now = time.Now
	}
	if o.Log == nil {
		o.Log = slog.New(slog.DiscardHandler)
	}
	return &SelfWatch{o: o, ops: map[uint64]selfOp{}}
}

// Begin marks the start of a unit of work that must end within deadline
// (a reconcile pass, a report); call the returned function when it ends.
// A nil SelfWatch watches nothing.
func (w *SelfWatch) Begin(name string, deadline time.Duration) (end func()) {
	if w == nil {
		return func() {}
	}
	w.mu.Lock()
	w.next++
	id := w.next
	w.ops[id] = selfOp{name: name, started: w.o.Now(), deadline: deadline}
	w.mu.Unlock()
	return func() {
		w.mu.Lock()
		delete(w.ops, id)
		w.mu.Unlock()
	}
}

// Run checks every interval until ctx ends.
func (w *SelfWatch) Run(ctx context.Context) {
	for {
		w.Check()
		select {
		case <-ctx.Done():
			return
		case <-time.After(w.o.Interval):
		}
	}
}

// Check touches the heartbeat when nothing is stuck, and otherwise writes
// the stall note and exits. It returns the stuck work's name ("" when none;
// only reached with a non-exiting Exit).
func (w *SelfWatch) Check() string {
	now := w.o.Now()
	w.mu.Lock()
	var stuck *selfOp
	for _, op := range w.ops {
		if now.Sub(op.started) > op.deadline && (stuck == nil || op.started.Before(stuck.started)) {
			o := op
			stuck = &o
		}
	}
	w.mu.Unlock()
	if stuck == nil {
		if err := Touch(w.o.File, now); err != nil {
			w.o.Log.Warn("could not write the heartbeat file", "err", err)
		}
		return ""
	}
	running := now.Sub(stuck.started).Round(time.Second)
	w.o.Log.Error("launchroomd is stuck; exiting so it restarts", "work", stuck.name, "running", running.String())
	if w.o.OnStall != nil {
		var b bytes.Buffer
		fmt.Fprintf(&b, "launchroomd stalled: %s made no progress for %s (deadline %s); exiting so it restarts.\n\n",
			stuck.name, running, stuck.deadline)
		_ = pprof.Lookup("goroutine").WriteTo(&b, 2)
		w.o.OnStall(b.Bytes())
	}
	w.o.Exit(StallExitCode)
	return stuck.name
}

// Touch sets the heartbeat file's modification time to now, creating it.
func Touch(file string, now time.Time) error {
	if file == "" {
		return nil
	}
	if err := os.Chtimes(file, now, now); err == nil {
		return nil
	}
	if err := os.MkdirAll(filepath.Dir(file), 0o700); err != nil {
		return err
	}
	if err := os.WriteFile(file, nil, 0o600); err != nil {
		return err
	}
	return os.Chtimes(file, now, now)
}

// ErrStaleHeartbeat means launchroomd's loops stopped moving.
var ErrStaleHeartbeat = errors.New("launchroomd's heartbeat is stale")

// Healthy reports whether the heartbeat file was touched within maxAge.
func Healthy(file string, maxAge time.Duration, now time.Time) error {
	fi, err := os.Stat(file)
	if err != nil {
		return fmt.Errorf("no heartbeat yet: %w", err)
	}
	if age := now.Sub(fi.ModTime()); age > maxAge {
		return fmt.Errorf("%w: last beat %s ago", ErrStaleHeartbeat, age.Round(time.Second))
	}
	return nil
}
