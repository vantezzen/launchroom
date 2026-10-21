package tasks

import (
	"context"
	"errors"
	"strings"
	"sync"
	"testing"
	"time"

	"github.com/vantezzen/launchroom/daemon/internal/controlplane"
	"github.com/vantezzen/launchroom/daemon/internal/docker"
	"github.com/vantezzen/launchroom/daemon/internal/docker/dockerfake"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

const (
	srvID = "srv_01J9Z8X7W6V5T4S3R2Q1P0N9MK"
	wl    = state.WorkloadID("wl_01J9Z8X7W6V5T4S3R2Q1P0N9N7")
)

type fakeCP struct {
	mu          sync.Mutex
	lease       []state.Task
	leaseReqs   []state.LeaseRequest
	completions map[state.TaskID]*state.TaskCompletion
	completeErr []error
	batches     []*state.LogBatch
	cancel      bool
	heartbeats  int
	shipErr     error
}

func (f *fakeCP) LeaseTasks(_ context.Context, req *state.LeaseRequest) ([]state.Task, error) {
	f.mu.Lock()
	defer f.mu.Unlock()
	f.leaseReqs = append(f.leaseReqs, *req)
	out := f.lease
	f.lease = nil
	return out, nil
}

func (f *fakeCP) Heartbeat(context.Context, state.TaskID, *state.TaskHeartbeat) (*state.TaskHeartbeatResult, error) {
	f.mu.Lock()
	defer f.mu.Unlock()
	f.heartbeats++
	return &state.TaskHeartbeatResult{LeaseExpiresAt: time.Now().Add(time.Minute), Cancel: f.cancel}, nil
}

func (f *fakeCP) Complete(_ context.Context, id state.TaskID, done *state.TaskCompletion) error {
	f.mu.Lock()
	defer f.mu.Unlock()
	if len(f.completeErr) > 0 {
		err := f.completeErr[0]
		f.completeErr = f.completeErr[1:]
		return err
	}
	if f.completions == nil {
		f.completions = map[state.TaskID]*state.TaskCompletion{}
	}
	f.completions[id] = done
	return nil
}

func (f *fakeCP) ShipLogs(_ context.Context, b *state.LogBatch) error {
	if err := b.Validate(); err != nil {
		return err
	}
	f.mu.Lock()
	defer f.mu.Unlock()
	if f.shipErr != nil {
		return f.shipErr
	}
	f.batches = append(f.batches, b)
	return nil
}

func (f *fakeCP) shippedLines() []state.LogLine {
	f.mu.Lock()
	defer f.mu.Unlock()
	var out []state.LogLine
	for _, b := range f.batches {
		for _, c := range b.Chunks {
			out = append(out, c.Lines...)
		}
	}
	return out
}

func (f *fakeCP) completion(id state.TaskID) *state.TaskCompletion {
	f.mu.Lock()
	defer f.mu.Unlock()
	return f.completions[id]
}

func task(id state.TaskID, typ state.TaskType, spec state.TaskSpec, deadline time.Duration) state.Task {
	return state.Task{ID: id, Type: typ, Attempt: 2, LeaseExpiresAt: time.Now().Add(time.Minute), Deadline: time.Now().Add(deadline), Spec: spec}
}

func TestUnsupportedAndLeaseTypes(t *testing.T) {
	cp := &fakeCP{}
	r := New(Options{Client: cp, Handlers: map[state.TaskType]Handler{state.TaskLogs: func(context.Context, state.Task) (Result, error) { return Result{}, nil }}})
	const id = state.TaskID("tsk_01J9Z8X7W6V5T4S3R2Q1P0N9N1")
	cp.lease = []state.Task{task(id, state.TaskBuild, &state.BuildSpec{}, time.Minute)}
	r.LeaseOnce(t.Context())
	r.wg.Wait()
	done := cp.completion(id)
	if done == nil || done.Outcome != state.OutcomeFailed || !strings.Contains(done.Error, "not supported") || done.Attempt != 2 {
		t.Fatalf("%+v", done)
	}
	if got := cp.leaseReqs[0].Types; len(got) != 1 || got[0] != state.TaskLogs {
		t.Fatalf("leased types %v", got)
	}
	if err := cp.leaseReqs[0].Validate(); err != nil {
		t.Fatal(err)
	}
}

func TestHandlerOutcomes(t *testing.T) {
	cp := &fakeCP{completeErr: []error{&controlplane.APIError{Status: 503}}} // first completion attempt fails transiently
	r := New(Options{Client: cp, HeartbeatEvery: 10 * time.Millisecond, Handlers: map[state.TaskType]Handler{
		state.TaskLogs: func(ctx context.Context, t state.Task) (Result, error) {
			switch t.ID {
			case "tsk_01J9Z8X7W6V5T4S3R2Q1P0N9N2":
				return Result{}, errors.New("boom")
			case "tsk_01J9Z8X7W6V5T4S3R2Q1P0N9N3":
				panic("bug")
			case "tsk_01J9Z8X7W6V5T4S3R2Q1P0N9N4":
				<-ctx.Done()
				return Result{}, ctx.Err()
			}
			return Result{}, nil
		},
	}})
	ids := []state.TaskID{"tsk_01J9Z8X7W6V5T4S3R2Q1P0N9N1", "tsk_01J9Z8X7W6V5T4S3R2Q1P0N9N2", "tsk_01J9Z8X7W6V5T4S3R2Q1P0N9N3", "tsk_01J9Z8X7W6V5T4S3R2Q1P0N9N4"}
	for i, id := range ids {
		d := time.Minute
		if i == 3 {
			d = 100 * time.Millisecond
		}
		cp.lease = append(cp.lease, task(id, state.TaskLogs, &state.LogsSpec{Workload: wl}, d))
	}
	r.LeaseOnce(t.Context())
	r.wg.Wait()
	want := []state.TaskOutcome{state.OutcomeSucceeded, state.OutcomeFailed, state.OutcomeFailed, state.OutcomeFailed}
	for i, id := range ids {
		if got := cp.completion(id); got == nil || got.Outcome != want[i] {
			t.Errorf("%s: %+v", id, got)
		}
	}
	if !strings.Contains(cp.completion(ids[2]).Error, "panicked") || cp.completion(ids[3]).Error != "deadline exceeded" {
		t.Error("error messages")
	}
}

func TestCancelledByDashboard(t *testing.T) {
	cp := &fakeCP{cancel: true}
	r := New(Options{Client: cp, HeartbeatEvery: 10 * time.Millisecond, Handlers: map[state.TaskType]Handler{
		state.TaskLogs: func(ctx context.Context, _ state.Task) (Result, error) { <-ctx.Done(); return Result{}, ctx.Err() },
	}})
	const id = state.TaskID("tsk_01J9Z8X7W6V5T4S3R2Q1P0N9N5")
	cp.lease = []state.Task{task(id, state.TaskLogs, &state.LogsSpec{Workload: wl}, time.Minute)}
	r.LeaseOnce(t.Context())
	r.wg.Wait()
	if got := cp.completion(id); got == nil || got.Outcome != state.OutcomeCancelled {
		t.Fatalf("%+v", got)
	}
}

func TestLogsHandler(t *testing.T) {
	ctx := t.Context()
	d := dockerfake.New(srvID)
	d.AddImage("img")
	labels := docker.ManagedLabels(srvID)
	labels[docker.LabelWorkload] = string(wl)
	cc := &docker.ContainerCreate{Name: "lr-x"}
	cc.Config = configWith(labels)
	id, err := d.CreateContainer(ctx, cc)
	if err != nil {
		t.Fatal(err)
	}
	t0 := time.Date(2026, 10, 17, 12, 0, 0, 0, time.UTC)
	for i := range 1203 {
		d.Logs[id] = append(d.Logs[id], docker.LogLine{Stream: "stdout", Time: t0.Add(time.Duration(i) * time.Second), Text: "line"})
	}
	d.Logs[id] = append(d.Logs[id], docker.LogLine{Stream: "stderr", Time: t0.Add(time.Hour), Text: "bad \xff utf8 " + strings.Repeat("x", 20000)})

	cp := &fakeCP{}
	h := LogsHandler(d, cp)
	tk := task("tsk_01J9Z8X7W6V5T4S3R2Q1P0N9N6", state.TaskLogs, &state.LogsSpec{Workload: wl, Tail: 1100}, time.Minute)
	if _, err := h(ctx, tk); err != nil {
		t.Fatal(err)
	}
	total := 0
	for i, b := range cp.batches {
		c := b.Chunks[0]
		if c.Seq != uint64(i+1) || c.Source.Kind != state.LogSourceTask || c.Source.ID != string(tk.ID) {
			t.Fatalf("chunk %d: %+v", i, c.Source)
		}
		total += len(c.Lines)
	}
	if total != 1100 || len(cp.batches) != 3 {
		t.Fatalf("shipped %d lines in %d batches", total, len(cp.batches))
	}
	last := cp.batches[2].Chunks[0].Lines
	if l := last[len(last)-1]; l.Stream != state.StreamStderr || len(l.Text) > maxLogLineText {
		t.Fatalf("%v %d", l.Stream, len(l.Text))
	}

	// Unknown workload fails cleanly.
	tk.Spec = &state.LogsSpec{Workload: "wl_01J9Z8X7W6V5T4S3R2Q1P0N9NZ"}
	if _, err := h(ctx, tk); err == nil || !strings.Contains(err.Error(), "no container") {
		t.Fatalf("err = %v", err)
	}
}

// TestLogsFollowShipsLive: a following tail ships its history (blank lines
// included) within a second or two while the task is still running, not
// only when it ends.
func TestLogsFollowShipsLive(t *testing.T) {
	d := dockerfake.New(srvID)
	d.AddImage("img")
	labels := docker.ManagedLabels(srvID)
	labels[docker.LabelWorkload] = string(wl)
	id, _ := d.CreateContainer(t.Context(), &docker.ContainerCreate{Name: "lr-z", Config: configWith(labels)})
	d.Logs[id] = []docker.LogLine{{Stream: "stdout", Text: ""}, {Stream: "stdout", Text: "> node server.js"}, {Stream: "stdout", Text: "listening on :3000"}}
	cp := &fakeCP{}
	ctx, cancel := context.WithTimeout(t.Context(), 30*time.Second)
	defer cancel()
	done := make(chan error, 1)
	go func() {
		_, err := LogsHandler(d, cp)(ctx, task("tsk_01J9Z8X7W6V5T4S3R2Q1P0N9N8", state.TaskLogs, &state.LogsSpec{Workload: wl, Tail: 200, Follow: true}, time.Minute))
		done <- err
	}()
	deadline := time.Now().Add(3 * time.Second)
	for len(cp.shippedLines()) < 3 {
		if time.Now().After(deadline) {
			t.Fatalf("follow shipped %d lines within 3s", len(cp.shippedLines()))
		}
		time.Sleep(50 * time.Millisecond)
	}
	if l := cp.shippedLines(); l[0].Text != "" || l[2].Text != "listening on :3000" {
		t.Fatalf("lines = %+v", l)
	}
	select {
	case err := <-done:
		t.Fatalf("follow ended early: %v", err)
	default:
	}
	cancel()
	if err := <-done; err != nil {
		t.Fatal(err)
	}
}

// TestLogsFollowFailsWhenShippingFails: a refused batch ends the tail with
// the error instead of dropping lines silently until the deadline.
func TestLogsFollowFailsWhenShippingFails(t *testing.T) {
	d := dockerfake.New(srvID)
	d.AddImage("img")
	labels := docker.ManagedLabels(srvID)
	labels[docker.LabelWorkload] = string(wl)
	id, _ := d.CreateContainer(t.Context(), &docker.ContainerCreate{Name: "lr-w", Config: configWith(labels)})
	d.Logs[id] = []docker.LogLine{{Stream: "stdout", Text: "hello"}}
	cp := &fakeCP{shipErr: errors.New("422 unprocessable")}
	ctx, cancel := context.WithTimeout(t.Context(), 30*time.Second)
	defer cancel()
	start := time.Now()
	_, err := LogsHandler(d, cp)(ctx, task("tsk_01J9Z8X7W6V5T4S3R2Q1P0N9N9", state.TaskLogs, &state.LogsSpec{Workload: wl, Tail: 10, Follow: true}, time.Minute))
	if err == nil || !strings.Contains(err.Error(), "422") {
		t.Fatalf("err = %v", err)
	}
	if time.Since(start) > 5*time.Second {
		t.Fatalf("took %s to notice", time.Since(start))
	}
}

func TestLogsFollowEndsAtDeadline(t *testing.T) {
	d := dockerfake.New(srvID)
	d.AddImage("img")
	labels := docker.ManagedLabels(srvID)
	labels[docker.LabelWorkload] = string(wl)
	id, _ := d.CreateContainer(t.Context(), &docker.ContainerCreate{Name: "lr-y", Config: configWith(labels)})
	d.Logs[id] = []docker.LogLine{{Stream: "stdout", Text: "hello"}}
	cp := &fakeCP{}
	ctx, cancel := context.WithTimeout(t.Context(), 1500*time.Millisecond)
	defer cancel()
	if _, err := LogsHandler(d, cp)(ctx, task("tsk_01J9Z8X7W6V5T4S3R2Q1P0N9N7", state.TaskLogs, &state.LogsSpec{Workload: wl, Tail: 10, Follow: true}, time.Minute)); err != nil {
		t.Fatal(err)
	}
	if len(cp.batches) != 1 {
		t.Fatalf("%d batches", len(cp.batches))
	}
}
