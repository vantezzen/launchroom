package reconcile

import (
	"context"
	"fmt"
	"slices"
	"time"

	"github.com/vantezzen/launchroom/daemon/internal/cronexpr"
	"github.com/vantezzen/launchroom/daemon/internal/docker"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// Scheduled workloads (WorkloadSpec.schedule, cron services): the
// reconciler creates their container without starting it; RunDue starts it
// at every due minute. launchroomd keeps the schedule while the dashboard is
// unreachable (docs/04 §1). Runs missed while launchroomd or the server was
// down are not caught up.

// dueRun is one container RunDue starts.
type dueRun struct {
	workload  state.WorkloadID
	container string
	key       string
}

// due lists the scheduled serving workloads whose container exists, is not
// running and is due at now. It is pure. A minute is due once: when its wall
// clock key isn't later than the container's last start (in the schedule's
// zone), it already ran — this also runs a minute repeated when the clocks
// go back only once. A run still going when the next one is due is not
// started twice (the next run is skipped).
func due(specs []state.WorkloadSpec, observedBy map[state.WorkloadID]docker.Container, now time.Time) (runs []dueRun, skipped []string) {
	for i := range specs {
		s := &specs[i]
		if s.Schedule == nil || s.Role != state.RoleServing {
			continue
		}
		c, ok := observedBy[s.ID]
		if !ok {
			continue
		}
		sched, err := cronexpr.Parse(string(s.Schedule.Cron))
		if err != nil {
			continue // refused by validation; never reached
		}
		loc, err := cronexpr.LoadLocation(string(s.Schedule.TimeZone))
		if err != nil {
			continue
		}
		at := now.In(loc)
		if !sched.Matches(at) {
			continue
		}
		key := cronexpr.WallKey(at)
		if !c.StartedAt.IsZero() && cronexpr.WallKey(c.StartedAt.In(loc)) >= key {
			continue
		}
		if c.Running || c.State == "restarting" || c.State == "removing" || c.State == "dead" || c.State == "paused" {
			skipped = append(skipped, fmt.Sprintf("%s: the previous run is still %s", s.ID, c.State))
			continue
		}
		runs = append(runs, dueRun{workload: s.ID, container: c.ID, key: key})
	}
	return runs, skipped
}

// RunDue starts every scheduled workload due at now (call it once a minute,
// shortly after the minute starts; never concurrently with itself). It
// lists the containers itself, so a run still going is seen as running. It
// reports whether it started anything (the caller then reconciles, which
// refreshes the observed statuses).
func (r *Reconciler) RunDue(ctx context.Context, now time.Time) bool {
	r.mu.Lock()
	var specs []state.WorkloadSpec
	if r.ns != nil {
		specs = r.ns.Workloads
	}
	r.mu.Unlock()
	if !slices.ContainsFunc(specs, func(s state.WorkloadSpec) bool { return s.Schedule != nil }) {
		return false
	}
	cs, err := r.o.Docker.ListContainers(ctx, nil)
	if err != nil {
		r.o.Log.Warn("scheduled runs: list containers", "err", err)
		return false
	}
	byID := map[state.WorkloadID]docker.Container{}
	for _, c := range cs {
		id := state.WorkloadID(c.Labels[docker.LabelWorkload])
		if c.Labels[docker.LabelSystem] != "" || id == "" {
			continue
		}
		if prev, dup := byID[id]; dup && (prev.Running || !c.Running) {
			continue
		}
		byID[id] = c
	}

	runs, skipped := due(specs, byID, now)
	for _, msg := range skipped {
		r.o.Log.Warn("scheduled run skipped", "reason", msg)
	}
	for _, run := range runs {
		r.o.Log.Info("scheduled run", "workload", run.workload, "due", run.key)
		if err := r.o.Docker.StartContainer(ctx, run.container); err != nil {
			r.o.Log.Warn("scheduled run could not start", "workload", run.workload, "err", err)
			r.setNote(run.workload, state.PhaseFailed, "scheduled run could not start: "+err.Error())
			continue
		}
		r.clearNote(run.workload)
	}
	return len(runs) > 0
}
