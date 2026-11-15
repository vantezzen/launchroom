// Package taskstest is an in-memory control plane for tests of the task
// runner and of task handlers.
package taskstest

import (
	"context"
	"sync"
	"time"

	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// ControlPlane records leases, completions and shipped logs. Set the
// exported fields before the runner uses it.
type ControlPlane struct {
	Mu          sync.Mutex
	Lease       []state.Task
	LeaseReqs   []state.LeaseRequest
	Completions map[state.TaskID]*state.TaskCompletion
	CompleteErr []error
	Batches     []*state.LogBatch
	Cancel      bool
	Heartbeats  int
	ShipErr     error
}

// LeaseTasks hands out Lease once.
func (f *ControlPlane) LeaseTasks(_ context.Context, req *state.LeaseRequest) ([]state.Task, error) {
	f.Mu.Lock()
	defer f.Mu.Unlock()
	f.LeaseReqs = append(f.LeaseReqs, *req)
	out := f.Lease
	f.Lease = nil
	return out, nil
}

// Heartbeat extends the lease and asks to cancel when Cancel is set.
func (f *ControlPlane) Heartbeat(context.Context, state.TaskID, *state.TaskHeartbeat) (*state.TaskHeartbeatResult, error) {
	f.Mu.Lock()
	defer f.Mu.Unlock()
	f.Heartbeats++
	return &state.TaskHeartbeatResult{LeaseExpiresAt: time.Now().Add(time.Minute), Cancel: f.Cancel}, nil
}

// Complete records the completion, failing with the queued CompleteErr first.
func (f *ControlPlane) Complete(_ context.Context, id state.TaskID, done *state.TaskCompletion) error {
	f.Mu.Lock()
	defer f.Mu.Unlock()
	if len(f.CompleteErr) > 0 {
		err := f.CompleteErr[0]
		f.CompleteErr = f.CompleteErr[1:]
		return err
	}
	if f.Completions == nil {
		f.Completions = map[state.TaskID]*state.TaskCompletion{}
	}
	f.Completions[id] = done
	return nil
}

// ShipLogs validates and records the batch, or fails with ShipErr.
func (f *ControlPlane) ShipLogs(_ context.Context, b *state.LogBatch) error {
	if err := b.Validate(); err != nil {
		return err
	}
	f.Mu.Lock()
	defer f.Mu.Unlock()
	if f.ShipErr != nil {
		return f.ShipErr
	}
	f.Batches = append(f.Batches, b)
	return nil
}

// ShippedBatches returns the batches shipped so far.
func (f *ControlPlane) ShippedBatches() []*state.LogBatch {
	f.Mu.Lock()
	defer f.Mu.Unlock()
	return append([]*state.LogBatch(nil), f.Batches...)
}

// ShippedLines returns every shipped line in order.
func (f *ControlPlane) ShippedLines() []state.LogLine {
	var out []state.LogLine
	for _, b := range f.ShippedBatches() {
		for _, c := range b.Chunks {
			out = append(out, c.Lines...)
		}
	}
	return out
}

// Completion returns the recorded completion of id (nil: none).
func (f *ControlPlane) Completion(id state.TaskID) *state.TaskCompletion {
	f.Mu.Lock()
	defer f.Mu.Unlock()
	return f.Completions[id]
}

// Task returns a leased task (attempt 2) with the given deadline from now.
func Task(id state.TaskID, typ state.TaskType, spec state.TaskSpec, deadline time.Duration) state.Task {
	return state.Task{ID: id, Type: typ, Attempt: 2, LeaseExpiresAt: time.Now().Add(time.Minute), Deadline: time.Now().Add(deadline), Spec: spec}
}
