// Package logship ships the runtime logs of workloads whose NodeState asks
// for it (`workloads[].shipLogs`, runtime log retention, docs/08 §12):
// launchroomd follows each such container's logs through the Docker API and
// sends them as workload chunks ({kind: workload, id: <workload id>}) to
// POST /daemon/v1/logs, continuously.
//
// It is cheap and bounded on purpose:
//   - one follower goroutine per workload, batches of at most 500 lines,
//     flushed every 2 s;
//   - a per-workload rate limit (200 lines/s sustained, 2,000 burst): lines
//     above it are dropped and the next batch says how many;
//   - at most 5,000 unshipped lines per workload while the dashboard is
//     unreachable (oldest dropped first);
//   - a cursor per workload (the last shipped line's time and seq) in
//     data/logship, so a restart neither loses nor repeats lines (the
//     dashboard also ignores re-sent seqs).
//
// Chunk seqs are time-based (Unix µs, strictly increasing) so they keep
// increasing across restarts and container replacements.
package logship

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"log/slog"
	"os"
	"path/filepath"
	"strings"
	"sync"
	"time"
	"unicode/utf8"

	"github.com/vantezzen/launchroom/daemon/internal/atomicfile"
	"github.com/vantezzen/launchroom/daemon/internal/docker"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// Defaults (Options fields override them in tests).
const (
	DefaultFlushEvery = 2 * time.Second
	DefaultRate       = 200
	DefaultBurst      = 2000
	batchLines        = 500
	maxBuffered       = 5000
	maxLineText       = 16384
	retryMin          = 2 * time.Second
	retryMax          = time.Minute
)

// Shipper sends log batches to the control plane.
type Shipper interface {
	ShipLogs(ctx context.Context, batch *state.LogBatch) error
}

// Options configure a Manager.
type Options struct {
	Docker docker.Client
	Client Shipper
	// Dir keeps one cursor file per workload (created on demand).
	Dir        string
	Log        *slog.Logger
	Now        func() time.Time
	FlushEvery time.Duration
	// Rate and Burst limit lines per second per workload.
	Rate, Burst int
}

// Manager runs one follower per workload that ships logs.
type Manager struct {
	opts Options
	mu   sync.Mutex
	run  map[state.WorkloadID]context.CancelFunc
	wg   sync.WaitGroup
}

// New returns a manager; nothing runs until Sync.
func New(o Options) *Manager {
	if o.Now == nil {
		o.Now = time.Now
	}
	if o.FlushEvery <= 0 {
		o.FlushEvery = DefaultFlushEvery
	}
	if o.Rate <= 0 {
		o.Rate = DefaultRate
	}
	if o.Burst <= 0 {
		o.Burst = DefaultBurst
	}
	if o.Log == nil {
		o.Log = slog.New(slog.DiscardHandler)
	}
	return &Manager{opts: o, run: map[state.WorkloadID]context.CancelFunc{}}
}

// Sync starts followers for workloads with shipLogs and stops the others.
// ctx is the daemon's run context: followers end with it.
func (m *Manager) Sync(ctx context.Context, ns *state.NodeState) {
	want := map[state.WorkloadID]bool{}
	if ns != nil {
		for i := range ns.Workloads {
			if ns.Workloads[i].ShipLogs {
				want[ns.Workloads[i].ID] = true
			}
		}
	}
	m.mu.Lock()
	defer m.mu.Unlock()
	for id, stop := range m.run {
		if !want[id] {
			stop()
			delete(m.run, id)
			_ = os.Remove(m.cursorPath(id))
			m.opts.Log.Info("stopped shipping runtime logs", "workload", id)
		}
	}
	for id := range want {
		if _, ok := m.run[id]; ok {
			continue
		}
		fctx, cancel := context.WithCancel(ctx)
		m.run[id] = cancel
		m.wg.Add(1)
		go func() {
			defer m.wg.Done()
			newFollower(m, id).loop(fctx)
		}()
		m.opts.Log.Info("shipping runtime logs", "workload", id)
	}
}

// Following lists the workloads being followed (tests, debugging).
func (m *Manager) Following() []state.WorkloadID {
	m.mu.Lock()
	defer m.mu.Unlock()
	out := make([]state.WorkloadID, 0, len(m.run))
	for id := range m.run {
		out = append(out, id)
	}
	return out
}

// Stop ends every follower and waits for them (their last batch is flushed).
func (m *Manager) Stop() {
	m.mu.Lock()
	for id, stop := range m.run {
		stop()
		delete(m.run, id)
	}
	m.mu.Unlock()
	m.wg.Wait()
}

func (m *Manager) cursorPath(id state.WorkloadID) string {
	return filepath.Join(m.opts.Dir, strings.ToLower(string(id))+".json")
}

// cursor is the persisted position of a follower.
type cursor struct {
	Time time.Time `json:"time"`
	Seq  uint64    `json:"seq"`
}

type follower struct {
	m  *Manager
	id state.WorkloadID

	mu      sync.Mutex
	pending []state.LogLine
	dropped int
	cur     cursor
	// token bucket
	tokens float64
	last   time.Time
}

func newFollower(m *Manager, id state.WorkloadID) *follower {
	f := &follower{m: m, id: id, tokens: float64(m.opts.Burst), last: m.opts.Now()}
	if data, err := os.ReadFile(m.cursorPath(id)); err == nil {
		_ = json.Unmarshal(data, &f.cur)
	}
	return f
}

func (f *follower) loop(ctx context.Context) {
	tick := time.NewTicker(f.m.opts.FlushEvery)
	defer tick.Stop()
	done := make(chan struct{})
	go func() {
		defer close(done)
		retry := retryMin
		for ctx.Err() == nil {
			err := f.stream(ctx)
			if ctx.Err() != nil {
				return
			}
			if err != nil {
				f.m.opts.Log.Debug("runtime log stream ended", "workload", f.id, "err", err)
			}
			select {
			case <-ctx.Done():
				return
			case <-time.After(retry):
			}
			if err != nil {
				retry = min(retry*2, retryMax)
			} else {
				retry = retryMin
			}
		}
	}()
	backoff := time.Duration(0)
	var nextTry time.Time
	for {
		select {
		case <-ctx.Done():
			<-done
			// Last flush with a short budget of its own.
			fctx, cancel := context.WithTimeout(context.WithoutCancel(ctx), 5*time.Second)
			_ = f.flush(fctx)
			cancel()
			return
		case <-tick.C:
		}
		if f.m.opts.Now().Before(nextTry) {
			continue
		}
		if err := f.flush(ctx); err != nil && ctx.Err() == nil {
			backoff = min(max(backoff*2, retryMin), retryMax)
			nextTry = f.m.opts.Now().Add(backoff)
			f.m.opts.Log.Warn("runtime logs not shipped; retrying", "workload", f.id, "retry_in", backoff, "err", err)
			continue
		}
		backoff = 0
	}
}

// stream follows the workload's running container from the cursor until the
// stream ends (container stopped or replaced) or ctx is done.
func (f *follower) stream(ctx context.Context) error {
	cs, err := f.m.opts.Docker.ListContainers(ctx, map[string]string{docker.LabelWorkload: string(f.id)})
	if err != nil {
		return err
	}
	var target *docker.Container
	for i := range cs {
		if cs[i].Labels[docker.LabelSystem] == "" && cs[i].Running {
			target = &cs[i]
			break
		}
	}
	if target == nil {
		return errors.New("no running container")
	}
	f.mu.Lock()
	since := f.cur.Time
	f.mu.Unlock()
	opts := docker.LogsOptions{Tail: -1, Follow: true, Since: since}
	return f.m.opts.Docker.StreamLogs(ctx, target.ID, opts, func(l docker.LogLine) error {
		f.add(l)
		return nil
	})
}

func (f *follower) add(l docker.LogLine) {
	f.mu.Lock()
	defer f.mu.Unlock()
	if l.Time.IsZero() {
		l.Time = f.m.opts.Now().UTC()
	}
	// Docker's `since` has second precision: skip what was shipped already.
	if !f.cur.Time.IsZero() && !l.Time.After(f.cur.Time) {
		return
	}
	if !f.take() {
		f.dropped++
		return
	}
	line := state.LogLine{Time: l.Time.UTC(), Stream: state.StreamStdout, Text: sanitize(l.Text)}
	if l.Stream == "stderr" {
		line.Stream = state.StreamStderr
	}
	f.pending = append(f.pending, line)
	if over := len(f.pending) - maxBuffered; over > 0 {
		f.pending = f.pending[over:]
		f.dropped += over
	}
	// Advance the read position (not the shipped one) so a reconnect of
	// the stream doesn't read buffered lines twice.
	f.cur.Time = line.Time
}

// take consumes one token of the rate limit.
func (f *follower) take() bool {
	now := f.m.opts.Now()
	f.tokens = min(float64(f.m.opts.Burst), f.tokens+now.Sub(f.last).Seconds()*float64(f.m.opts.Rate))
	f.last = now
	if f.tokens < 1 {
		return false
	}
	f.tokens--
	return true
}

// flush ships the buffered lines in batches; on failure they stay buffered.
func (f *follower) flush(ctx context.Context) error {
	for {
		f.mu.Lock()
		if f.dropped > 0 && len(f.pending) < maxBuffered {
			f.pending = append(f.pending, state.LogLine{
				Time: f.m.opts.Now().UTC(), Stream: state.StreamSystem,
				Text: fmt.Sprintf("launchroom: %d log lines were dropped (more than %d lines per second, or the dashboard was unreachable)", f.dropped, f.m.opts.Rate),
			})
			f.dropped = 0
		}
		if len(f.pending) == 0 {
			f.mu.Unlock()
			return nil
		}
		n := min(len(f.pending), batchLines)
		lines := append([]state.LogLine(nil), f.pending[:n]...)
		seq := max(f.cur.Seq+1, uint64(max(f.m.opts.Now().UnixMicro(), 1))) //nolint:gosec // positive
		f.mu.Unlock()

		batch := &state.LogBatch{
			TypeMeta: state.NewTypeMeta(state.KindLogBatch),
			Chunks:   []state.LogChunk{{Source: state.LogSource{Kind: state.LogSourceWorkload, ID: string(f.id)}, Seq: seq, Lines: lines}},
		}
		sctx, cancel := context.WithTimeout(ctx, 30*time.Second)
		err := f.m.opts.Client.ShipLogs(sctx, batch)
		cancel()
		if err != nil {
			return err
		}

		f.mu.Lock()
		f.pending = f.pending[n:]
		f.cur.Seq = seq
		saved := cursor{Time: lines[len(lines)-1].Time, Seq: seq}
		f.mu.Unlock()
		f.save(saved)
	}
}

func (f *follower) save(c cursor) {
	data, err := json.Marshal(c)
	if err != nil {
		return
	}
	if err := os.MkdirAll(f.m.opts.Dir, 0o700); err != nil {
		return
	}
	if err := atomicfile.Write(f.m.cursorPath(f.id), data, 0o600); err != nil {
		f.m.opts.Log.Debug("runtime log cursor not saved", "workload", f.id, "err", err)
	}
}

// sanitize makes a line valid UTF-8 and caps it at the protocol limit.
func sanitize(s string) string {
	s = strings.ToValidUTF8(s, "�")
	if len(s) <= maxLineText {
		return s
	}
	cut := maxLineText
	for cut > 0 && !utf8.RuneStart(s[cut]) {
		cut--
	}
	return s[:cut]
}
