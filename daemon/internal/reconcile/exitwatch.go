package reconcile

import (
	"context"
	"errors"
	"time"

	"github.com/vantezzen/launchroom/daemon/internal/docker"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// Exit watcher (docs/03 §9, docs/15 §6.1): every workload container has one
// goroutine waiting for its next exit (Docker's wait?condition=next-exit,
// which dockerd-guard allows). Docker answers before its restart policy
// starts the container again (at least 100 ms later), so an inspection right
// after the wait still sees the run's exit code, the OOM flag and its end,
// and the tail can be cut at that end. Without it, a steady-state crash
// (an OOM at 3 am) is noticed by the next pass only after Docker restarted
// the container and reset its exit code and OOM flag, and the attached lines
// would be the new run's start-up output.
//
// The pass-based diff (trackLocked) stays the source of truth for starts and
// restarts and the fallback for exits a watcher missed (launchroomd
// restarting, a guard hiccup); both dedupe on the run's FinishedAt.

const (
	// watchBackoffMax bounds the retry delay of a failing wait.
	watchBackoffMax = 30 * time.Second
	// stoppingKeep is how long a stop launchroomd issued is remembered
	// (byLaunchroom on the exit it causes).
	stoppingKeep = 10 * time.Minute
)

// exitWatcher is the goroutine waiting for one container's exits.
type exitWatcher struct {
	workload state.WorkloadID
	cancel   context.CancelFunc
	done     chan struct{}
}

// syncWatchersLocked runs exactly one watcher per observed workload
// container (r.mu held; starting goroutines is not I/O). Watchers live with
// ctx (the daemon's run context): a watcher that ended is started again.
func (r *Reconciler) syncWatchersLocked(ctx context.Context, obs map[state.WorkloadID]observed) {
	ops, ok := r.o.Docker.(docker.ExitOps)
	if !ok || ctx.Err() != nil {
		return
	}
	want := make(map[string]state.WorkloadID, len(obs))
	for id, o := range obs {
		want[o.c.ID] = id
	}
	for cid, w := range r.watchers {
		ended := false
		select {
		case <-w.done:
			ended = true
		default:
		}
		if wl, ok := want[cid]; !ok || wl != w.workload || ended {
			w.cancel()
			delete(r.watchers, cid)
		}
	}
	for cid, wl := range want {
		if _, ok := r.watchers[cid]; ok {
			continue
		}
		wctx, cancel := context.WithCancel(ctx)
		w := &exitWatcher{workload: wl, cancel: cancel, done: make(chan struct{})}
		r.watchers[cid] = w
		go r.watchExits(wctx, ops, wl, cid, w.done)
	}
}

// StopWatchers ends every exit watcher (tests, shutdown).
func (r *Reconciler) StopWatchers() {
	r.mu.Lock()
	ws := make([]*exitWatcher, 0, len(r.watchers))
	for cid, w := range r.watchers {
		w.cancel()
		ws = append(ws, w)
		delete(r.watchers, cid)
	}
	r.mu.Unlock()
	for _, w := range ws {
		<-w.done
	}
}

func (r *Reconciler) watchExits(ctx context.Context, ops docker.ExitOps, wl state.WorkloadID, id string, done chan struct{}) {
	defer close(done)
	backoff := time.Second
	for {
		started := time.Now()
		res, err := ops.WaitNextExit(ctx, id)
		if ctx.Err() != nil {
			return
		}
		if err != nil {
			if errors.Is(err, docker.ErrNotFound) || errors.Is(err, docker.ErrNotManaged) {
				return // removed: the pass stops tracking it
			}
			r.o.Log.Debug("waiting for a container exit failed", "workload", wl, "err", err)
			if !sleepCtx(ctx, backoff) {
				return
			}
			backoff = min(2*backoff, watchBackoffMax)
			continue
		}
		backoff = time.Second
		r.exitSeen(ctx, wl, id, res)
		// Docker restarts a crashing container at least 100 ms after its
		// exit; a wait that keeps answering at once is not waiting.
		if time.Since(started) < 50*time.Millisecond && !sleepCtx(ctx, 100*time.Millisecond) {
			return
		}
	}
}

func sleepCtx(ctx context.Context, d time.Duration) bool {
	t := time.NewTimer(d)
	defer t.Stop()
	select {
	case <-ctx.Done():
		return false
	case <-t.C:
		return true
	}
}

// exitSeen reports one exit the watcher saw: the run's code, OOM flag and
// end from an inspection during the restart backoff, and its last lines.
func (r *Reconciler) exitSeen(ctx context.Context, wl state.WorkloadID, id string, res docker.ExitResult) {
	c, err := r.o.Docker.InspectContainer(ctx, id)
	now := r.o.Now().UTC()
	r.mu.Lock()
	m, ok := r.marks[wl]
	if !ok || m.container != id {
		r.mu.Unlock()
		return // a container the pass doesn't track (a duplicate being removed)
	}
	code, oom, at := res.Code, false, now
	run := runBounds{}
	if err == nil {
		if !c.FinishedAt.IsZero() {
			at = c.FinishedAt
		}
		if !c.Running() || c.Restarting() {
			// Still down: Docker's exit facts are this run's.
			code, oom = c.ExitCode, c.OOMKilled
		} else if c.StartedAt.After(at) {
			// Already running again: keep the code from the wait; whether
			// the kernel killed it for memory is unknown now (never guess
			// it from 137: that is any SIGKILL).
			run.before = c.StartedAt
		}
	}
	if !at.After(m.finishedAt) {
		r.mu.Unlock()
		return // the pass reported it already
	}
	run.until = at
	if !m.startedAt.After(at) {
		run.since = m.startedAt
	}
	ev := state.WorkloadEvent{
		Workload: wl, Deployment: m.deployment, At: at, Kind: state.EventExited, ExitCode: &code, OOMKilled: oom,
		Signal: signalOf(code), ByLaunchroom: r.stoppedByUsLocked(id, at),
	}
	m.finishedAt = at
	m.lastExit = &code
	m.lastOOMed = m.lastOOMed || oom
	r.marks[wl] = m
	r.mu.Unlock()

	if !ev.ByLaunchroom {
		ev.Output = r.tailRun(ctx, id, run)
		r.classifyExit(&ev)
	}
	r.emit(ev)
	r.saveMarks()
	r.o.OnProgress()
}

// markStopping records that launchroomd is about to stop a container (its
// exit is byLaunchroom).
func (r *Reconciler) markStopping(id string) {
	if id == "" {
		return
	}
	r.mu.Lock()
	defer r.mu.Unlock()
	r.stopping[id] = r.o.Now()
}

// stoppedByUsLocked reports whether an exit at at follows a stop launchroomd
// issued for the container (r.mu held).
func (r *Reconciler) stoppedByUsLocked(id string, at time.Time) bool {
	t, ok := r.stopping[id]
	return ok && !at.Before(t.Add(-time.Second))
}

// pruneStoppingLocked forgets old stops (r.mu held).
func (r *Reconciler) pruneStoppingLocked(now time.Time) {
	for id, t := range r.stopping {
		if now.Sub(t) > stoppingKeep {
			delete(r.stopping, id)
		}
	}
}
