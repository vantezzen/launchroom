package tasks

import (
	"context"
	"errors"
	"strings"
	"testing"
	"time"

	"github.com/vantezzen/launchroom/daemon/internal/controlplane"
	"github.com/vantezzen/launchroom/daemon/internal/state"
	"github.com/vantezzen/launchroom/daemon/internal/tasks/taskstest"
)

const (
	wl      = state.WorkloadID("wl_01J9Z8X7W6V5T4S3R2Q1P0N9N7")
	bServer = state.ServerID("srv_01J9Z8X7W6V5T4S3R2Q1P0N9MK")
	bTask   = state.TaskID("tsk_01J9Z8X7W6V5T4S3R2Q1P0N9P0")
)

func TestUnsupportedAndLeaseTypes(t *testing.T) {
	cp := &taskstest.ControlPlane{}
	r := New(Options{Client: cp, Handlers: map[state.TaskType]Handler{state.TaskLogs: func(context.Context, state.Task) (state.TaskResults, error) { return state.TaskResults{}, nil }}})
	const id = state.TaskID("tsk_01J9Z8X7W6V5T4S3R2Q1P0N9N1")
	cp.Lease = []state.Task{taskstest.Task(id, state.TaskBuild, &state.BuildSpec{}, time.Minute)}
	r.LeaseOnce(t.Context())
	r.wg.Wait()
	done := cp.Completion(id)
	if done == nil || done.Outcome != state.OutcomeFailed || !strings.Contains(done.Error, "not supported") || done.Attempt != 2 {
		t.Fatalf("%+v", done)
	}
	if got := cp.LeaseReqs[0].Types; len(got) != 1 || got[0] != state.TaskLogs {
		t.Fatalf("leased types %v", got)
	}
	if err := cp.LeaseReqs[0].Validate(); err != nil {
		t.Fatal(err)
	}
}

func TestHandlerOutcomes(t *testing.T) {
	cp := &taskstest.ControlPlane{CompleteErr: []error{&controlplane.APIError{Status: 503}}} // first completion attempt fails transiently
	r := New(Options{Client: cp, HeartbeatEvery: 10 * time.Millisecond, Handlers: map[state.TaskType]Handler{
		state.TaskLogs: func(ctx context.Context, t state.Task) (state.TaskResults, error) {
			switch t.ID {
			case "tsk_01J9Z8X7W6V5T4S3R2Q1P0N9N2":
				return state.TaskResults{}, errors.New("boom")
			case "tsk_01J9Z8X7W6V5T4S3R2Q1P0N9N3":
				panic("bug")
			case "tsk_01J9Z8X7W6V5T4S3R2Q1P0N9N4":
				<-ctx.Done()
				return state.TaskResults{}, ctx.Err()
			}
			return state.TaskResults{}, nil
		},
	}})
	ids := []state.TaskID{"tsk_01J9Z8X7W6V5T4S3R2Q1P0N9N1", "tsk_01J9Z8X7W6V5T4S3R2Q1P0N9N2", "tsk_01J9Z8X7W6V5T4S3R2Q1P0N9N3", "tsk_01J9Z8X7W6V5T4S3R2Q1P0N9N4"}
	for i, id := range ids {
		d := time.Minute
		if i == 3 {
			d = 100 * time.Millisecond
		}
		cp.Lease = append(cp.Lease, taskstest.Task(id, state.TaskLogs, &state.LogsSpec{Workload: wl}, d))
	}
	r.LeaseOnce(t.Context())
	r.wg.Wait()
	want := []state.TaskOutcome{state.OutcomeSucceeded, state.OutcomeFailed, state.OutcomeFailed, state.OutcomeFailed}
	for i, id := range ids {
		if got := cp.Completion(id); got == nil || got.Outcome != want[i] {
			t.Errorf("%s: %+v", id, got)
		}
	}
	if !strings.Contains(cp.Completion(ids[2]).Error, "panicked") || cp.Completion(ids[3]).Error != "deadline exceeded" {
		t.Error("error messages")
	}
}

func TestCancelledByDashboard(t *testing.T) {
	cp := &taskstest.ControlPlane{Cancel: true}
	r := New(Options{Client: cp, HeartbeatEvery: 10 * time.Millisecond, Handlers: map[state.TaskType]Handler{
		state.TaskLogs: func(ctx context.Context, _ state.Task) (state.TaskResults, error) {
			<-ctx.Done()
			return state.TaskResults{}, ctx.Err()
		},
	}})
	const id = state.TaskID("tsk_01J9Z8X7W6V5T4S3R2Q1P0N9N5")
	cp.Lease = []state.Task{taskstest.Task(id, state.TaskLogs, &state.LogsSpec{Workload: wl}, time.Minute)}
	r.LeaseOnce(t.Context())
	r.wg.Wait()
	if got := cp.Completion(id); got == nil || got.Outcome != state.OutcomeCancelled {
		t.Fatalf("%+v", got)
	}
}
