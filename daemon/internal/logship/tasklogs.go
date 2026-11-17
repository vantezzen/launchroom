package logship

import (
	"context"
	"errors"
	"fmt"
	"sync"
	"time"

	"github.com/vantezzen/launchroom/daemon/internal/docker"
	"github.com/vantezzen/launchroom/daemon/internal/state"
	"github.com/vantezzen/launchroom/daemon/internal/tasks"
)

// TaskHandler returns the handler for "logs" tasks: it reads a workload's
// container logs (tail/since/follow) through the Docker API and ships them
// with source {kind: task, id: <task id>}, seq starting at 1.
func TaskHandler(d docker.Client, c tasks.Client) tasks.Handler {
	return func(ctx context.Context, t state.Task) (state.TaskResults, error) {
		spec, ok := t.Spec.(*state.LogsSpec)
		if !ok {
			return state.TaskResults{}, errors.New("logs task without a logs spec")
		}
		target, err := docker.WorkloadContainer(ctx, d, spec.Workload)
		if err != nil {
			return state.TaskResults{}, err
		}
		if target == nil {
			return state.TaskResults{}, fmt.Errorf("workload %s has no container on this server", spec.Workload)
		}

		s := &taskShipper{client: c, source: state.LogSource{Kind: state.LogSourceTask, ID: string(t.ID)}}
		opts := docker.LogsOptions{Tail: spec.Tail, Follow: spec.Follow}
		if spec.Since != nil {
			opts.Since = *spec.Since
		}
		// A batch the control plane refuses ends the task with that error
		// instead of dropping lines silently: the tail is the whole point of
		// the task, so the failure shows on it (and the explorer renews it).
		sctx, cancel := context.WithCancelCause(ctx)
		defer cancel(nil)
		stop := make(chan struct{})
		var wg sync.WaitGroup
		if spec.Follow {
			wg.Add(1)
			go func() {
				defer wg.Done()
				tick := time.NewTicker(tasks.LogFlushEvery)
				defer tick.Stop()
				for {
					select {
					case <-stop:
						return
					case <-tick.C:
						if ferr := s.flush(sctx, false); ferr != nil {
							cancel(ferr)
							return
						}
					}
				}
			}()
		}
		err = followAcrossRestarts(sctx, d, spec, *target, opts, s)
		close(stop)
		wg.Wait()
		if cause := context.Cause(sctx); cause != nil && ctx.Err() == nil && !errors.Is(cause, context.Canceled) {
			return state.TaskResults{}, cause
		}
		if ferr := s.flush(context.WithoutCancel(ctx), true); ferr != nil && err == nil {
			err = ferr
		}
		if spec.Follow && ctx.Err() != nil {
			// Following ends with the task deadline or a cancel; the stream
			// then fails with whatever the closed connection reports.
			return state.TaskResults{}, nil
		}
		return state.TaskResults{}, err
	}
}

// Re-follow timing (a crash loop's live tail, docs/08 §15).
const (
	refollowPoll = time.Second
)

// followAcrossRestarts streams the container's lines; a following tail
// goes on when the container exits and starts again (Docker's restart
// policy, a liveness restart, a replacement of the workload's container)
// until the task's deadline, with a system line at each exit and start.
// Without follow it reads once (also the history of an exited container).
func followAcrossRestarts(ctx context.Context, d docker.Client, spec *state.LogsSpec, target docker.Container, opts docker.LogsOptions, s *taskShipper) error {
	var last time.Time
	add := func(l docker.LogLine) error {
		if !last.IsZero() && !l.Time.IsZero() && !l.Time.After(last) {
			return nil // already shipped before the restart
		}
		if !l.Time.IsZero() {
			last = l.Time
		}
		return s.add(ctx, l)
	}
	for {
		err := d.StreamLogs(ctx, target.ID, opts, add)
		if !spec.Follow || ctx.Err() != nil || (err != nil && !errors.Is(err, docker.ErrNotFound)) {
			return err
		}
		// The process ended (or the container is gone): say so and wait
		// for the workload's container to run again.
		if msg := exitLine(ctx, d, target); msg != "" {
			if err := s.system(ctx, msg); err != nil {
				return err
			}
		}
		next, ok := waitRunning(ctx, d, spec.Workload, target)
		if !ok {
			return nil
		}
		msg := "The container started again."
		if next.ID != target.ID {
			msg = "A new container started."
		} else if next.RestartCount > 0 {
			msg = fmt.Sprintf("The container started again (restart %d).", next.RestartCount)
		}
		if err := s.system(ctx, msg); err != nil {
			return err
		}
		target = next
		// From the new run's start; lines of the old run in the same second
		// are skipped by time.
		opts = docker.LogsOptions{Tail: -1, Follow: true, Since: next.StartedAt}
	}
}

// exitLine words how the followed run ended ("" = it didn't, or unknown).
func exitLine(ctx context.Context, d docker.Client, target docker.Container) string {
	c, err := d.InspectContainer(ctx, target.ID)
	switch {
	case err != nil:
		return "The container was removed."
	case !c.Running() || c.Restarting():
		if c.OOMKilled {
			return fmt.Sprintf("The container exited with code %d: it ran out of memory.", c.ExitCode)
		}
		return fmt.Sprintf("The container exited with code %d.", c.ExitCode)
	case c.FinishedAt.After(target.StartedAt):
		return "The container exited." // and runs again already: Docker reset the code
	}
	return ""
}

// waitRunning polls for the workload's container to run a newer process
// than prev (false: ctx ended).
func waitRunning(ctx context.Context, d docker.Client, wl state.WorkloadID, prev docker.Container) (docker.Container, bool) {
	t := time.NewTicker(refollowPoll)
	defer t.Stop()
	for {
		select {
		case <-ctx.Done():
			return docker.Container{}, false
		case <-t.C:
		}
		c, err := docker.WorkloadContainer(ctx, d, wl)
		if err != nil || c == nil || !c.Running() || c.Restarting() {
			continue
		}
		if c.ID != prev.ID || c.StartedAt.After(prev.StartedAt) || !prev.Running() && prev.StartedAt.IsZero() {
			return *c, true
		}
	}
}

type taskShipper struct {
	client tasks.Client
	source state.LogSource

	mu        sync.Mutex
	lines     []state.LogLine
	seq       uint64
	lastFlush time.Time
}

// system adds one of launchroom's own lines (stream system).
func (s *taskShipper) system(ctx context.Context, text string) error {
	s.mu.Lock()
	s.lines = append(s.lines, state.LogLine{Time: time.Now().UTC(), Stream: state.StreamSystem, Text: text})
	s.mu.Unlock()
	return s.flush(ctx, false)
}

func (s *taskShipper) add(ctx context.Context, l docker.LogLine) error {
	s.mu.Lock()
	line := state.LogLine{Time: l.Time, Stream: state.StreamStdout, Text: state.Clip(l.Text, state.MaxLogLineLen)}
	if l.Stream == "stderr" {
		line.Stream = state.StreamStderr
	}
	if line.Time.IsZero() {
		line.Time = time.Now().UTC()
	}
	s.lines = append(s.lines, line)
	full := len(s.lines) >= tasks.LogBatchLines
	s.mu.Unlock()
	if full {
		return s.flush(ctx, true)
	}
	return nil
}

// flush ships buffered lines; unless force, it waits for LogFlushEvery since the last flush.
func (s *taskShipper) flush(ctx context.Context, force bool) error {
	s.mu.Lock()
	defer s.mu.Unlock()
	if len(s.lines) == 0 || (!force && time.Since(s.lastFlush) < tasks.LogFlushEvery) {
		return nil
	}
	s.seq++
	batch := &state.LogBatch{
		TypeMeta: state.NewTypeMeta(state.KindLogBatch),
		Chunks:   []state.LogChunk{{Source: s.source, Seq: s.seq, Lines: s.lines}},
	}
	s.lines, s.lastFlush = nil, time.Now()
	sctx, cancel := context.WithTimeout(context.WithoutCancel(ctx), 30*time.Second)
	defer cancel()
	if err := s.client.ShipLogs(sctx, batch); err != nil {
		return fmt.Errorf("ship logs: %w", err)
	}
	return nil
}
