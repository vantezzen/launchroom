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

// runMode is how a workload's container runs, chosen once from its spec
// (modeOf). A long-running workload is kept up; a scheduled one
// (WorkloadSpec.schedule, cron services) is created without starting and
// RunDue starts it at every due minute. launchroomd keeps the schedule while
// the dashboard is unreachable (docs/04 §1); runs missed while launchroomd
// or the server was down are not caught up.
type runMode interface {
	// createOnly: a new container is created, not started.
	createOnly() bool
	// wantsStart: the reconciler starts this existing container.
	wantsStart(c docker.Container, restart state.RestartPolicy) bool
	// crashLoopApplies: Docker's restart count can mean a crash loop.
	crashLoopApplies() bool
	// watchesStartup: a fresh container has a startup phase that settles.
	watchesStartup() bool
	// status is the phase of a serving workload's container (ok=false:
	// the generic rules of statusOf apply).
	status(c docker.Container, ws *state.WorkloadStatus) (phase state.WorkloadPhase, message string, ok bool)
}

func modeOf(s *state.WorkloadSpec) runMode {
	if s.Schedule != nil {
		return scheduledRun{s.Schedule}
	}
	return longRunning{}
}

type longRunning struct{}

func (longRunning) createOnly() bool       { return false }
func (longRunning) crashLoopApplies() bool { return true }
func (longRunning) watchesStartup() bool   { return true }

func (longRunning) wantsStart(c docker.Container, restart state.RestartPolicy) bool {
	return needsStart(c, restart)
}

func (longRunning) status(docker.Container, *state.WorkloadStatus) (state.WorkloadPhase, string, bool) {
	return "", "", false
}

type scheduledRun struct{ spec *state.ScheduleSpec }

func (scheduledRun) createOnly() bool                                      { return true }
func (scheduledRun) wantsStart(docker.Container, state.RestartPolicy) bool { return false }
func (scheduledRun) crashLoopApplies() bool                                { return false }
func (scheduledRun) watchesStartup() bool                                  { return false }

// status: a scheduled job is healthy once its container exists (between
// runs it is "created" or "exited", which is how it should be) unless
// Docker can't run it; the last run's outcome is in the message and its
// exit code in ws.ExitCode.
func (scheduledRun) status(c docker.Container, ws *state.WorkloadStatus) (state.WorkloadPhase, string, bool) {
	switch c.State {
	case docker.StateRunning:
		return state.PhaseHealthy, "scheduled: running now", true
	case docker.StateCreated:
		return state.PhaseHealthy, "scheduled: waiting for the first run", true
	case docker.StateExited:
		code := c.ExitCode
		ws.ExitCode = &code
		switch {
		case c.OOMKilled:
			return state.PhaseHealthy, "scheduled: the last run was killed: out of memory (raise the memory limit)", true
		case code != 0:
			return state.PhaseHealthy, fmt.Sprintf("scheduled: the last run exited with code %d", code), true
		}
		return state.PhaseHealthy, "scheduled: the last run succeeded", true
	case docker.StateRemoving:
		return state.PhaseRemoved, "", true
	}
	return state.PhaseUnhealthy, "scheduled: container is " + string(c.State), true
}

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
		mode, isScheduled := modeOf(s).(scheduledRun)
		if !isScheduled || s.Role != state.RoleServing {
			continue
		}
		c, ok := observedBy[s.ID]
		if !ok {
			continue
		}
		sched, err := cronexpr.Parse(string(mode.spec.Cron))
		if err != nil {
			continue // refused by validation; never reached
		}
		loc, err := cronexpr.LoadLocation(string(mode.spec.TimeZone))
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
		if c.Running() || c.State == docker.StateRemoving || c.State == docker.StateDead {
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
	if !slices.ContainsFunc(specs, func(s state.WorkloadSpec) bool { _, ok := modeOf(&s).(scheduledRun); return ok }) {
		return false
	}
	cs, err := r.o.Docker.ListContainers(ctx, nil)
	if err != nil {
		r.o.Log.Warn("scheduled runs: list containers", "err", err)
		return false
	}
	runs, skipped := due(specs, docker.WorkloadContainers(cs), now)
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
