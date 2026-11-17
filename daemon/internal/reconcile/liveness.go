package reconcile

import (
	"context"
	"fmt"
	"slices"
	"time"

	"github.com/vantezzen/launchroom/daemon/internal/docker"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// Liveness restarts (D89, docs/04 §8): a serving workload whose health
// check passed and then kept failing for HealthSpec.restartAfter is
// restarted by launchroomd (a hung app with a single replica recovers on
// its own). Never while it starts (the check must have passed since the
// container started), at most livenessMaxRestarts times an hour; after that
// it is left alone with the issue "unresponsive" until the check passes.

const (
	livenessMaxRestarts = 3
	livenessWindow      = time.Hour
)

type livenessJob struct {
	spec    *state.WorkloadSpec
	c       docker.Container
	failing time.Duration
	lastErr string
}

// liveness restarts the workloads whose probe failed too long (after a
// refresh; the I/O runs without the lock).
func (r *Reconciler) liveness(ctx context.Context, ns *state.NodeState) {
	if r.o.Health == nil {
		return
	}
	r.mu.Lock()
	now := r.o.Now()
	var jobs []livenessJob
	for i := range ns.Workloads {
		s := &ns.Workloads[i]
		if s.Health == nil || s.Health.RestartAfter == "" || s.Role != state.RoleServing || !modeOf(s).watchesStartup() {
			continue
		}
		after := s.Health.RestartAfter.Std()
		o, ok := r.observed[s.ID]
		m, marked := r.marks[s.ID]
		if !ok || !marked || m.container != o.c.ID || !o.c.Running() || o.c.Restarting() || after <= 0 {
			continue
		}
		probe := r.probeLocked(s)
		if probe == nil || probe.State != state.ProbeFailing || !probe.EverPassed || probe.FailingSince.IsZero() {
			if probe != nil && probe.State == state.ProbePassing {
				m.unresponsiveSince = time.Time{}
				r.marks[s.ID] = m
			}
			continue
		}
		failing := now.Sub(probe.FailingSince)
		if failing < after {
			continue
		}
		m.healthRestarts = slices.DeleteFunc(m.healthRestarts, func(t time.Time) bool { return now.Sub(t) >= livenessWindow })
		if len(m.healthRestarts) >= livenessMaxRestarts {
			if m.unresponsiveSince.IsZero() {
				m.unresponsiveSince = now
				r.o.Log.Warn("health check keeps failing after restarts; leaving the container alone", "workload", s.ID, "restarts", len(m.healthRestarts))
			}
			r.marks[s.ID] = m
			o.issue = &state.WorkloadIssue{Kind: state.IssueUnresponsive, Since: m.unresponsiveSince.UTC(), Restarts: len(m.healthRestarts)}
			r.observed[s.ID] = o
			continue
		}
		m.healthRestarts = append(m.healthRestarts, now)
		m.unresponsiveSince = time.Time{}
		m.selfRestart = true
		r.stopping[o.c.ID] = now
		r.marks[s.ID] = m
		jobs = append(jobs, livenessJob{spec: s, c: o.c, failing: failing, lastErr: probe.LastError})
	}
	r.mu.Unlock()

	for _, j := range jobs {
		r.livenessRestart(ctx, j)
	}
}

func (r *Reconciler) livenessRestart(ctx context.Context, j livenessJob) {
	c := j.c
	r.o.Log.Warn("restarting a container whose health check keeps failing", "workload", j.spec.ID, "failing", j.failing.Round(time.Second), "err", j.lastErr)
	ev := state.WorkloadEvent{
		Workload: j.spec.ID, Deployment: j.spec.Deployment, Kind: state.EventRestarted, Reason: state.ReasonHealth,
		Restarts: max(c.RestartCount, 0),
		Message:  fmt.Sprintf("health check failing for %s: %s", j.failing.Round(time.Second), j.lastErr),
		// The hung run's last lines, read before it is stopped.
		Output: r.tailRun(ctx, c.ID, runBounds{since: c.StartedAt}),
	}
	r.emit(ev)
	grace := max(c.StopTimeout, r.o.StopGrace)
	if err := r.o.Docker.StopContainer(ctx, c.ID, grace); err != nil {
		r.o.Log.Warn("liveness restart: stop failed", "workload", j.spec.ID, "err", err)
		return
	}
	// A stopped container of a desired workload is started by the next
	// pass anyway; starting it here saves that wait.
	if err := r.o.Docker.StartContainer(ctx, c.ID); err != nil {
		r.o.Log.Warn("liveness restart: start failed", "workload", j.spec.ID, "err", err)
	}
	r.o.OnProgress()
}
