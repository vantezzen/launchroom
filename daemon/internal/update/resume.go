package update

import (
	"context"
	"errors"
	"io/fs"
	"log/slog"
	"net/http"
	"time"

	"github.com/moby/moby/client"

	"github.com/vantezzen/launchroom/daemon/internal/controlplane"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// Completer reports a task outcome (controlplane.Client.Complete).
type Completer func(ctx context.Context, id state.TaskID, done *state.TaskCompletion) error

// ResumeOptions configures Resume.
type ResumeOptions struct {
	DataDir  string
	Docker   *client.Client
	Complete Completer
	Log      *slog.Logger
	// Poll and Timeout bound the wait for the supervisor's verdict.
	Poll    time.Duration
	Timeout time.Duration
}

// Resume reports an update that a previous launchroomd process started:
// it waits for the supervisor's verdict (status.json), completes the task
// with it and clears pending.json. It is called once at startup, after the
// first signed exchange with the control plane (MarkRunning).
//
// If the attempt's lease was lost meanwhile (the dashboard re-queued it),
// the completion is refused; the re-leased attempt then finishes
// immediately from the recorded status (handler).
func Resume(ctx context.Context, o ResumeOptions) {
	if o.Log == nil {
		o.Log = slog.New(slog.DiscardHandler)
	}
	if o.Poll <= 0 {
		o.Poll = 2 * time.Second
	}
	if o.Timeout <= 0 {
		o.Timeout = 2 * defaultCPInstallTimeout
	}
	p, err := readPending(o.DataDir)
	if err != nil {
		o.Log.Warn("unreadable pending update; ignoring it", "err", err)
		return
	}
	if p == nil {
		return
	}
	log := o.Log.With("task", p.TaskID, "from", p.From, "to", p.To)
	log.Info("waiting for the outcome of the update started before this restart")
	deadline := time.Now().Add(o.Timeout)
	var st Status
	for {
		st, err = readStatus(o.DataDir, p.TaskID)
		if err == nil {
			break
		}
		if !errors.Is(err, fs.ErrNotExist) {
			log.Warn("reading update status", "err", err)
		}
		gone := false
		if o.Docker != nil {
			running, ierr := containerRunning(ctx, o.Docker, p.Supervisor)
			gone = ierr == nil && !running
		}
		if gone || time.Now().After(deadline) {
			// Re-check: the supervisor may have written its verdict just before exiting.
			if st, err = readStatus(o.DataDir, p.TaskID); err == nil {
				break
			}
			st = Status{TaskID: p.TaskID, Scope: p.Scope, From: p.From, To: p.To, Outcome: OutcomeFailed,
				Error: "the update supervisor exited without a result; check `docker logs " + p.Supervisor + "`", FinishedAt: time.Now().UTC()}
			_ = writeStatus(o.DataDir, st)
			break
		}
		select {
		case <-ctx.Done():
			return
		case <-time.After(o.Poll):
		}
	}
	if o.Docker != nil {
		removeByName(context.WithoutCancel(ctx), o.Docker, p.Supervisor)
	}
	for attempt := 1; ; attempt++ {
		err := o.Complete(ctx, p.TaskID, st.Completion(p.Attempt))
		var api *controlplane.APIError
		switch {
		case err == nil:
			log.Info("reported the update outcome", "outcome", st.Outcome)
		case errors.As(err, &api) && (api.Status == http.StatusConflict || api.Status == http.StatusNotFound || api.Status == http.StatusGone):
			log.Info("the dashboard re-queued the update task; the next lease reports the outcome", "outcome", st.Outcome)
		case controlplane.IsRetryable(err) && attempt < 30 && ctx.Err() == nil:
			select {
			case <-ctx.Done():
				return
			case <-time.After(o.Poll * time.Duration(min(attempt, 15))):
			}
			continue
		default:
			log.Error("could not report the update outcome", "err", err)
			return // keep pending.json: the next start tries again
		}
		removePending(o.DataDir, p.TaskID)
		return
	}
}
