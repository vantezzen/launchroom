package tasks

import (
	"context"
	"errors"
	"sync"
	"testing"
	"time"

	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// progressCP records heartbeat progress lines.
type progressCP struct {
	fakeCP
	pmu      sync.Mutex
	progress []string
}

func (p *progressCP) Heartbeat(ctx context.Context, id state.TaskID, hb *state.TaskHeartbeat) (*state.TaskHeartbeatResult, error) {
	p.pmu.Lock()
	p.progress = append(p.progress, hb.Progress)
	p.pmu.Unlock()
	return p.fakeCP.Heartbeat(ctx, id, hb)
}

func TestProgressAndHandoff(t *testing.T) {
	cp := &progressCP{}
	const handoff, failed = state.TaskID("tsk_01J9Z8X7W6V5T4S3R2Q1P0N9Q1"), state.TaskID("tsk_01J9Z8X7W6V5T4S3R2Q1P0N9Q2")
	r := New(Options{Client: cp, HeartbeatEvery: 5 * time.Millisecond, Handlers: map[state.TaskType]Handler{
		state.TaskSelfUpdate: func(ctx context.Context, tk state.Task) (Result, error) {
			SetProgress(ctx, "Installing v0.2.0")
			time.Sleep(50 * time.Millisecond)
			res := Result{SelfUpdate: &state.SelfUpdateResult{Scope: state.UpdateDaemon, FromVersion: "0.1.0", ToVersion: "0.2.0", RolledBack: true}}
			if tk.ID == handoff {
				return res, ErrHandoff
			}
			return res, errors.New("rolled back to 0.1.0: unhealthy")
		},
	}})
	spec := &state.SelfUpdateSpec{Version: "0.2.0", Image: "ghcr.io/vantezzen/launchroomd@sha256:1111111111111111111111111111111111111111111111111111111111111111"}
	cp.lease = []state.Task{task(handoff, state.TaskSelfUpdate, spec, time.Minute), task(failed, state.TaskSelfUpdate, spec, time.Minute)}
	r.LeaseOnce(t.Context())
	r.wg.Wait()

	if got := cp.completion(handoff); got != nil {
		t.Fatalf("handed-over task was completed: %+v", got)
	}
	got := cp.completion(failed)
	if got == nil || got.Outcome != state.OutcomeFailed || got.SelfUpdate == nil || !got.SelfUpdate.RolledBack {
		t.Fatalf("failed self_update completion: %+v", got)
	}
	if err := got.Validate(); err != nil {
		t.Fatal(err)
	}
	cp.pmu.Lock()
	defer cp.pmu.Unlock()
	seen := false
	for _, p := range cp.progress {
		seen = seen || p == "Installing v0.2.0"
	}
	if !seen {
		t.Fatalf("progress not sent with heartbeats: %q", cp.progress)
	}
}

func TestSetProgressOutsideRunner(_ *testing.T) {
	SetProgress(context.Background(), "ignored") // must not panic
}
