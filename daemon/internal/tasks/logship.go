package tasks

import (
	"context"
	"sync"
	"time"

	"github.com/vantezzen/launchroom/daemon/internal/state"
)

const (
	// LogBatchLines is the most lines shipped in one log batch.
	LogBatchLines = 500
	// LogFlushEvery is how often buffered task log lines are shipped.
	LogFlushEvery = time.Second
)

// LineShipper ships step-grouped log lines of a task.
type LineShipper interface {
	Line(step string, stream state.LogStream, text string)
	Close()
}

// NewTaskLogShipper returns a shipper for the logs of task id, attempt.
func NewTaskLogShipper(ctx context.Context, c Client, id state.TaskID, attempt int) LineShipper {
	return newStepShipper(ctx, c, state.LogSource{Kind: state.LogSourceTask, ID: string(id)}, attempt)
}

// stepShipper batches step-grouped log lines and ships them live (every
// LogFlushEvery, or every LogBatchLines lines). Seq starts at a per-attempt
// offset so a retried task's lines do not collide with the first attempt's
// (the dashboard ignores chunks it already has for a (source, seq)).
type stepShipper struct {
	client Client
	source state.LogSource
	ctx    context.Context

	mu    sync.Mutex
	lines []state.LogLine
	seq   uint64
	stop  chan struct{}
	done  chan struct{}
	once  sync.Once
}

// AttemptSeqBase separates the log seq ranges of task attempts.
const AttemptSeqBase = 1_000_000_000

func newStepShipper(ctx context.Context, c Client, src state.LogSource, attempt int) *stepShipper {
	s := &stepShipper{client: c, source: src, ctx: context.WithoutCancel(ctx), stop: make(chan struct{}), done: make(chan struct{})}
	if attempt > 1 {
		s.seq = uint64(attempt-1) * AttemptSeqBase //nolint:gosec // attempt >= 1
	}
	go s.loop() //nolint:contextcheck // the loop owns a detached context so the last lines still ship on cancel
	return s
}

func (s *stepShipper) loop() {
	defer close(s.done)
	t := time.NewTicker(LogFlushEvery)
	defer t.Stop()
	for {
		select {
		case <-s.stop:
			s.flush()
			return
		case <-t.C:
			s.flush()
		}
	}
}

// Line implements LineShipper.
func (s *stepShipper) Line(step string, stream state.LogStream, text string) {
	s.mu.Lock()
	s.lines = append(s.lines, state.LogLine{Time: time.Now().UTC(), Stream: stream, Text: state.Clip(text, state.MaxLogLineLen), Step: state.Clip(step, state.MaxLogStepLen)})
	full := len(s.lines) >= LogBatchLines
	s.mu.Unlock()
	if full {
		s.flush()
	}
}

func (s *stepShipper) flush() {
	s.mu.Lock()
	if len(s.lines) == 0 {
		s.mu.Unlock()
		return
	}
	s.seq++
	batch := &state.LogBatch{
		TypeMeta: state.NewTypeMeta(state.KindLogBatch),
		Chunks:   []state.LogChunk{{Source: s.source, Seq: s.seq, Lines: s.lines}},
	}
	s.lines = nil
	s.mu.Unlock()
	ctx, cancel := context.WithTimeout(s.ctx, 30*time.Second)
	defer cancel()
	_ = s.client.ShipLogs(ctx, batch) // logs are best effort; the task result is authoritative
}

// Close flushes the remaining lines and stops the loop.
func (s *stepShipper) Close() {
	s.once.Do(func() { close(s.stop) })
	<-s.done
}
