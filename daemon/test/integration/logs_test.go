//go:build integration

package integration

import (
	"context"
	"strings"
	"sync"
	"testing"
	"time"

	"github.com/vantezzen/launchroom/daemon/internal/state"
	"github.com/vantezzen/launchroom/daemon/internal/tasks"
)

// tailCP collects the batches a logs task ships, validated like the
// control-plane client does before sending.
type tailCP struct {
	logCP

	mu      sync.Mutex
	batches []*state.LogBatch
}

func (c *tailCP) ShipLogs(ctx context.Context, b *state.LogBatch) error {
	if err := b.Validate(); err != nil {
		return err
	}
	c.mu.Lock()
	c.batches = append(c.batches, b)
	c.mu.Unlock()
	return c.logCP.ShipLogs(ctx, b)
}

func (c *tailCP) count() (batches, lines int, newest time.Time) {
	c.mu.Lock()
	defer c.mu.Unlock()
	for _, b := range c.batches {
		for _, ch := range b.Chunks {
			lines += len(ch.Lines)
			for _, l := range ch.Lines {
				if l.Time.After(newest) {
					newest = l.Time
				}
			}
		}
	}
	return len(c.batches), lines, newest
}

// TestLogsTaskFollow runs a following `logs` task (the log explorer's live
// tail) against a real workload container: its history ships within seconds
// and lines the container prints later keep arriving while the task runs,
// with seq counting up from 1.
func TestLogsTaskFollow(t *testing.T) {
	e := setup(t)
	ns, wl := e.nodeState(t, 1, true, false)
	// whoami logs every request with -verbose; the 1 s health probe makes a
	// steady stream of new lines.
	ns.Workloads[0].Args = append(ns.Workloads[0].Args, "-verbose")
	converge(t, e, ns, func() bool { return status(e.rec, wl).Phase == state.PhaseHealthy })

	cp := &tailCP{}
	h := tasks.LogsHandler(e.engine, cp)
	id := state.TaskID(randomID("tsk"))
	tk := state.Task{ID: id, Type: state.TaskLogs, Attempt: 1, LeaseExpiresAt: time.Now().Add(time.Minute), Deadline: time.Now().Add(5 * time.Minute),
		Spec: &state.LogsSpec{Workload: wl, Tail: 200, Follow: true}}
	if err := tk.Validate(); err != nil {
		t.Fatal(err)
	}
	ctx, cancel := context.WithTimeout(context.Background(), 2*time.Minute)
	defer cancel()
	done := make(chan error, 1)
	start := time.Now().UTC()
	go func() {
		_, err := h(ctx, tk)
		done <- err
	}()

	wait := func(what string, within time.Duration, ok func() bool) {
		t.Helper()
		deadline := time.Now().Add(within)
		for !ok() {
			select {
			case err := <-done:
				t.Fatalf("logs task ended while waiting for %s: %v", what, err)
			default:
			}
			if time.Now().After(deadline) {
				b, n, _ := cp.count()
				t.Fatalf("no %s within %s (%d batches, %d lines)\n%s", what, within, b, n, cp.text())
			}
			time.Sleep(100 * time.Millisecond)
		}
	}
	wait("history", 5*time.Second, func() bool { _, n, _ := cp.count(); return n > 0 })
	wait("new lines while following", 15*time.Second, func() bool { _, _, newest := cp.count(); return newest.After(start.Add(time.Second)) })

	cancel()
	if err := <-done; err != nil {
		t.Fatalf("following ended with %v", err)
	}
	cp.mu.Lock()
	defer cp.mu.Unlock()
	for i, b := range cp.batches {
		c := b.Chunks[0]
		if c.Seq != uint64(i+1) || c.Source.Kind != state.LogSourceTask || c.Source.ID != string(id) {
			t.Fatalf("batch %d: seq %d source %+v", i, c.Seq, c.Source)
		}
	}
	if !strings.Contains(cp.text(), "8080") {
		t.Fatalf("whoami output missing:\n%s", cp.text())
	}
}
