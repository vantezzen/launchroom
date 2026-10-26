package reconcile

import (
	"context"
	"errors"
	"time"

	"github.com/vantezzen/launchroom/daemon/internal/docker"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// Image pulls run in the background (D68, docs/04 §11). A pass that needs
// an image starts its pull and waits for it at most PullWait: a quick pull
// (most of them) creates the workloads in the same pass as before; a slow
// or stuck one goes on in the background while the pass converges
// everything else, and the pass after it ends creates the workloads that
// waited (OnPullDone kicks it). Until then they are reported as pulling,
// with the pull's progress and the time it last moved, so the dashboard
// can tell a slow download from a stuck one. Before, the pass blocked on
// the pull for up to 45 minutes and applied nothing newer meanwhile.

// DefaultPullWait is how long a pass waits for a pull it started.
const DefaultPullWait = 15 * time.Second

// bgPull is one image pull in flight.
type bgPull struct {
	cancel context.CancelCauseFunc
	done   chan struct{}
	err    error // set before done is closed
}

// pull starts (or joins) the background pull of image and waits for it at
// most PullWait. done=false means it is still running; once it ended, the
// next call returns its outcome and forgets it, so a failed pull is tried
// again by a later pass.
func (r *Reconciler) pull(ctx context.Context, image state.ImageRef) (done bool, err error) {
	r.mu.Lock()
	bg, ok := r.pulls[image]
	if !ok {
		pctx, cancel := context.WithCancelCause(context.WithoutCancel(ctx))
		bg = &bgPull{cancel: cancel, done: make(chan struct{})}
		r.pulls[image] = bg
		go r.runPull(pctx, image, bg)
	}
	r.mu.Unlock()

	wait := time.NewTimer(r.o.PullWait)
	defer wait.Stop()
	select {
	case <-bg.done:
	case <-wait.C:
		return false, nil
	case <-ctx.Done():
		return false, nil
	}
	r.mu.Lock()
	if r.pulls[image] == bg {
		delete(r.pulls, image)
	}
	r.mu.Unlock()
	return true, bg.err
}

func (r *Reconciler) runPull(ctx context.Context, image state.ImageRef, bg *bgPull) {
	pctx := docker.WithPullProgress(ctx, func(pp docker.PullProgress) { r.pullProgress(image, pp) })
	err := r.o.Docker.PullImage(pctx, string(image))
	if cause := context.Cause(ctx); err != nil && cause != nil && !errors.Is(cause, context.Canceled) {
		err = cause
	}
	bg.err = err
	close(bg.done)
	bg.cancel(nil)
	if err != nil {
		r.o.Log.Warn("image pull failed", "image", image, "err", err)
	}
	// The workloads waiting for it are created by the next pass.
	r.o.OnPullDone()
}

// waitingFor lists the desired workloads that need image (r.mu held).
func (r *Reconciler) waitingFor(image state.ImageRef) []state.WorkloadID {
	if r.ns == nil {
		return nil
	}
	var ids []state.WorkloadID
	for i := range r.ns.Workloads {
		if r.ns.Workloads[i].Image == image {
			ids = append(ids, r.ns.Workloads[i].ID)
		}
	}
	return ids
}

// errPullAbandoned is the cause of a pull AbandonPulls cancelled.
var errPullAbandoned = errors.New("pull abandoned: no desired workload uses the image any more")

// AbandonPulls cancels the image pulls in flight that ns, a newer desired
// state, no longer needs: a workload taken out of the desired state (a
// cancelled deployment, a stopped service, a service held for an import or
// a restore, docs/14 §12.5) must not keep downloading. Safe from any
// goroutine; returns whether a pull was abandoned.
func (r *Reconciler) AbandonPulls(ns *state.NodeState) bool {
	if ns == nil {
		return false
	}
	needed := map[state.ImageRef]bool{}
	for i := range ns.Workloads {
		needed[ns.Workloads[i].Image] = true
	}
	r.mu.Lock()
	defer r.mu.Unlock()
	abandoned := false
	for image, bg := range r.pulls {
		select {
		case <-bg.done:
			continue
		default:
		}
		if needed[image] {
			continue
		}
		r.o.Log.Info("abandoning pull: no desired workload uses the image any more", "image", image, "version", ns.Version)
		bg.cancel(errPullAbandoned)
		delete(r.pulls, image)
		abandoned = true
	}
	return abandoned
}
