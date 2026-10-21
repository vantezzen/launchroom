package tasks

import (
	"context"
	"errors"
	"fmt"
	"strings"
	"sync"
	"time"
	"unicode/utf8"

	"github.com/vantezzen/launchroom/daemon/internal/docker"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

const (
	logBatchLines  = 500
	logFlushEvery  = time.Second
	maxLogLineText = 16384
)

// LogsHandler returns the handler for "logs" tasks: it reads a workload's
// container logs (tail/since/follow) through the Docker API and ships them
// with source {kind: task, id: <task id>}, seq starting at 1.
func LogsHandler(d docker.Client, c Client) Handler {
	return func(ctx context.Context, t state.Task) (Result, error) {
		spec, ok := t.Spec.(*state.LogsSpec)
		if !ok {
			return Result{}, errors.New("logs task without a logs spec")
		}
		cs, err := d.ListContainers(ctx, map[string]string{docker.LabelWorkload: string(spec.Workload)})
		if err != nil {
			return Result{}, err
		}
		var target *docker.Container
		for i := range cs {
			if cs[i].Labels[docker.LabelSystem] != "" {
				continue
			}
			if target == nil || (cs[i].Running && !target.Running) {
				target = &cs[i]
			}
		}
		if target == nil {
			return Result{}, fmt.Errorf("workload %s has no container on this server", spec.Workload)
		}

		s := &shipper{client: c, source: state.LogSource{Kind: state.LogSourceTask, ID: string(t.ID)}}
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
				tick := time.NewTicker(logFlushEvery)
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
		err = d.StreamLogs(sctx, target.ID, opts, func(l docker.LogLine) error { return s.add(sctx, l) })
		close(stop)
		wg.Wait()
		if cause := context.Cause(sctx); cause != nil && ctx.Err() == nil && !errors.Is(cause, context.Canceled) {
			return Result{}, cause // a refused or failed shipment
		}
		if ferr := s.flush(context.WithoutCancel(ctx), true); ferr != nil && err == nil {
			err = ferr
		}
		if spec.Follow && ctx.Err() != nil {
			// Following ends with the task deadline or a cancel; the stream
			// then fails with whatever the closed connection reports.
			return Result{}, nil
		}
		return Result{}, err
	}
}

type shipper struct {
	client Client
	source state.LogSource

	mu        sync.Mutex
	lines     []state.LogLine
	seq       uint64
	lastFlush time.Time
}

func (s *shipper) add(ctx context.Context, l docker.LogLine) error {
	s.mu.Lock()
	line := state.LogLine{Time: l.Time, Stream: state.StreamStdout, Text: sanitizeLine(l.Text)}
	if l.Stream == "stderr" {
		line.Stream = state.StreamStderr
	}
	if line.Time.IsZero() {
		line.Time = time.Now().UTC()
	}
	s.lines = append(s.lines, line)
	full := len(s.lines) >= logBatchLines
	s.mu.Unlock()
	if full {
		return s.flush(ctx, true)
	}
	return nil
}

// flush ships buffered lines; unless force, it waits for logFlushEvery since the last flush.
func (s *shipper) flush(ctx context.Context, force bool) error {
	s.mu.Lock()
	defer s.mu.Unlock()
	if len(s.lines) == 0 || (!force && time.Since(s.lastFlush) < logFlushEvery) {
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

// sanitizeLine makes a line valid UTF-8 and caps it at the protocol limit.
func sanitizeLine(s string) string {
	s = strings.ToValidUTF8(s, "�")
	if len(s) <= maxLogLineText {
		return s
	}
	cut := maxLogLineText
	for cut > 0 && !utf8.RuneStart(s[cut]) {
		cut--
	}
	return s[:cut]
}
