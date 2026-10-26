package reconcile

import (
	"context"
	"slices"
	"strings"
	"time"
	"unicode/utf8"

	"github.com/vantezzen/launchroom/daemon/internal/docker"
	"github.com/vantezzen/launchroom/daemon/internal/health"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// Container lifecycle (D68, docs/04 §8.1): what happens to workload
// containers is reported as typed events, and a starting container that is
// clearly misconfigured carries an issue, so the dashboard can show the
// rollout as it happens and fail it right away.
//
// Events come from two places: the reconciler's own actions (created,
// create failed, stopped, removed, with a typed reason) and the difference
// between two observations of the same container (started, exited,
// restarted), with Docker's own StartedAt/FinishedAt as the times, so a
// container that crashed between two looks is still reported once, at the
// moment it happened. Docker's event stream is not used: it would need a
// streaming route in dockerd-guard filtered per event, and events lost while
// launchroomd restarts would have to be reconstructed from the state anyway.

const (
	// maxBufferedEvents bounds undelivered events (oldest dropped first).
	maxBufferedEvents = 1024
	// crashLoopRestarts restarts by Docker's restart policy make a crash loop.
	crashLoopRestarts = 3
	// stableRun: a container that has been running this long after its
	// restarts is not crash-looping (anymore).
	stableRun = time.Minute
	// settledAfter: a workload counts as started once its health check has
	// passed (or, without one, it has been running) this long. Until then it
	// is watched closely (Watch) and its output is shipped as startup output.
	settledAfter = 30 * time.Second
	// startupWindow bounds how long a workload counts as starting.
	startupWindow = 15 * time.Minute
	// exitTailLines are the output lines attached to an exit.
	exitTailLines = state.MaxEventOutputLines
)

// mark is what launchroomd remembers about a workload's container between
// observations.
type mark struct {
	container  string
	deployment state.DeploymentID
	startedAt  time.Time
	finishedAt time.Time
	restarts   int
	// passedAt is when the health check last started passing (zero: not passing).
	passedAt time.Time
	// portIssue and portSince track a port issue until it settled.
	portIssue state.WorkloadIssueKind
	portSince time.Time
	// loopSince is when the container was first seen crash-looping.
	loopSince time.Time
	// probeFailing remembers the last reported probe state (health events).
	probeFailing, probePassing bool
	// lastExit is the last exit seen: Docker resets ExitCode while the
	// container runs again after a restart.
	lastExit  *int
	lastOOMed bool
}

// eventLog buffers events until a report carrying them was accepted.
type eventLog struct {
	buf  []state.WorkloadEvent
	last uint64
}

// emitLocked appends an event (r.mu held).
func (r *Reconciler) emitLocked(ev state.WorkloadEvent) {
	now := r.o.Now()
	if ev.At.IsZero() {
		ev.At = now
	}
	ev.At = ev.At.UTC()
	ev.Seq = max(r.events.last+1, uint64(max(now.UnixMicro(), 1))) //nolint:gosec // positive
	r.events.last = ev.Seq
	ev.Message = clip(ev.Message, 1024)
	r.events.buf = append(r.events.buf, ev)
	if over := len(r.events.buf) - maxBufferedEvents; over > 0 {
		r.events.buf = slices.Delete(r.events.buf, 0, over)
	}
}

func (r *Reconciler) emit(ev state.WorkloadEvent) {
	r.mu.Lock()
	defer r.mu.Unlock()
	if ev.Deployment == "" {
		ev.Deployment = r.marks[ev.Workload].deployment
	}
	r.emitLocked(ev)
}

// PendingEvents returns the oldest undelivered events (at most
// state.MaxReportEvents) for the next report.
func (r *Reconciler) PendingEvents() []state.WorkloadEvent {
	r.mu.Lock()
	defer r.mu.Unlock()
	n := min(len(r.events.buf), state.MaxReportEvents)
	if n == 0 {
		return nil
	}
	return slices.Clone(r.events.buf[:n])
}

// AckEvents drops the events a report delivered.
func (r *Reconciler) AckEvents(sent []state.WorkloadEvent) {
	if len(sent) == 0 {
		return
	}
	upTo := sent[len(sent)-1].Seq
	r.mu.Lock()
	defer r.mu.Unlock()
	r.events.buf = slices.DeleteFunc(r.events.buf, func(ev state.WorkloadEvent) bool { return ev.Seq <= upTo })
}

// exitTail is an exit whose output still has to be read (outside the lock).
type exitTail struct {
	container string
	ev        state.WorkloadEvent
}

// trackLocked compares a fresh observation of a workload's container with
// the last one and emits what happened in between; exits are returned so the
// caller can attach the container's last lines (r.mu held).
func (r *Reconciler) trackLocked(id state.WorkloadID, deployment state.DeploymentID, c docker.Container) []exitTail {
	prev, had := r.marks[id]
	m := prev
	if deployment != "" {
		m.deployment = deployment
	}
	var exits []exitTail
	recent := func(t time.Time) bool { return !t.IsZero() && !t.Before(r.boot) }
	if !had || prev.container != c.ID {
		// A container launchroomd hasn't seen before: report its start, but
		// not containers that were running before launchroomd started.
		m = mark{container: c.ID, deployment: m.deployment, startedAt: c.StartedAt, finishedAt: c.FinishedAt, restarts: c.RestartCount}
		if recent(c.StartedAt) {
			r.emitLocked(state.WorkloadEvent{Workload: id, Deployment: m.deployment, At: c.StartedAt, Kind: state.EventStarted})
		}
		if recent(c.FinishedAt) && !c.FinishedAt.Before(c.StartedAt) && !c.Running {
			exits = append(exits, r.exitLocked(id, m.deployment, c))
			m.noteExit(c)
		}
		r.marks[id] = m
		return exits
	}
	if c.FinishedAt.After(prev.finishedAt) && recent(c.FinishedAt) {
		exits = append(exits, r.exitLocked(id, m.deployment, c))
		m.noteExit(c)
	}
	if c.StartedAt.After(prev.startedAt) && recent(c.StartedAt) {
		if c.RestartCount > prev.restarts {
			r.emitLocked(state.WorkloadEvent{Workload: id, Deployment: m.deployment, At: c.StartedAt, Kind: state.EventRestarted, Restarts: c.RestartCount})
		} else {
			r.emitLocked(state.WorkloadEvent{Workload: id, Deployment: m.deployment, At: c.StartedAt, Kind: state.EventStarted})
		}
		m.passedAt = time.Time{}
	}
	m.startedAt, m.finishedAt, m.restarts = c.StartedAt, c.FinishedAt, c.RestartCount
	r.marks[id] = m
	return exits
}

// exitLocked builds an exit event; its output is read afterwards. The exit
// code is known only while the container is down: Docker resets it when its
// restart policy starts the container again.
func (r *Reconciler) exitLocked(id state.WorkloadID, deployment state.DeploymentID, c docker.Container) exitTail {
	ev := state.WorkloadEvent{Workload: id, Deployment: deployment, At: c.FinishedAt, Kind: state.EventExited, OOMKilled: c.OOMKilled}
	if code, ok := exitCode(c); ok {
		ev.ExitCode = &code
	}
	return exitTail{container: c.ID, ev: ev}
}

func exitCode(c docker.Container) (int, bool) {
	if c.Running && !c.Restarting {
		return 0, false
	}
	return c.ExitCode, true
}

// noteExit remembers an exit's code for the crash-loop issue.
func (m *mark) noteExit(c docker.Container) {
	if code, ok := exitCode(c); ok {
		m.lastExit = &code
	}
	m.lastOOMed = m.lastOOMed || c.OOMKilled
}

// finishExits reads the last lines of each exited container and emits the
// exit events (I/O without the lock; bounded by a short timeout each).
func (r *Reconciler) finishExits(ctx context.Context, exits []exitTail) {
	for _, x := range exits {
		x.ev.Output = r.tail(ctx, x.container)
		r.emit(x.ev)
	}
}

// tail returns a container's last output lines (best effort).
func (r *Reconciler) tail(ctx context.Context, container string) []state.LogLine {
	ctx, cancel := context.WithTimeout(ctx, 3*time.Second)
	defer cancel()
	var lines []state.LogLine
	_ = r.o.Docker.StreamLogs(ctx, container, docker.LogsOptions{Tail: exitTailLines}, func(l docker.LogLine) error {
		line := state.LogLine{Time: l.Time.UTC(), Stream: state.StreamStdout, Text: clipLine(l.Text)}
		if l.Stream == "stderr" {
			line.Stream = state.StreamStderr
		}
		if line.Time.IsZero() {
			line.Time = r.o.Now().UTC()
		}
		lines = append(lines, line)
		return nil
	})
	if over := len(lines) - exitTailLines; over > 0 {
		lines = lines[over:]
	}
	return lines
}

// clipLine makes an output line valid UTF-8 and at most 2 KiB.
func clipLine(s string) string {
	s = strings.ToValidUTF8(strings.TrimRight(s, "\r\n"), "�")
	if len(s) <= 2048 {
		return s
	}
	cut := 2048
	for cut > 0 && !utf8.RuneStart(s[cut]) {
		cut--
	}
	return s[:cut]
}

// probeEventsLocked reports health check transitions of a workload (r.mu held).
func (r *Reconciler) probeEventsLocked(spec *state.WorkloadSpec, probe *health.Status, running bool) {
	m, ok := r.marks[spec.ID]
	if !ok || probe == nil || !running {
		return
	}
	switch probe.State {
	case state.ProbePassing:
		if !m.probePassing {
			r.emitLocked(state.WorkloadEvent{Workload: spec.ID, Deployment: m.deployment, At: probe.LastCheckedAt, Kind: state.EventHealthPassing})
			m.passedAt = r.o.Now()
		}
		m.probePassing, m.probeFailing = true, false
	case state.ProbeFailing:
		if !m.probeFailing {
			r.emitLocked(state.WorkloadEvent{
				Workload: spec.ID, Deployment: m.deployment, At: probe.LastCheckedAt, Kind: state.EventHealthFailing,
				Failures: probe.ConsecutiveFailures, Message: probe.LastError,
			})
		}
		m.probePassing, m.probeFailing = false, true
		m.passedAt = time.Time{}
	default:
		m.probePassing, m.probeFailing = false, false
	}
	r.marks[spec.ID] = m
}

// issueLocked derives a running container's issue (r.mu held): a crash loop
// from Docker's restart count, a port issue from its listening sockets once
// it held for state.IssueSettle while the health check doesn't pass.
func (r *Reconciler) issueLocked(spec *state.WorkloadSpec, o observed, probe *health.Status) *state.WorkloadIssue {
	m, ok := r.marks[spec.ID]
	if !ok {
		return nil
	}
	now := r.o.Now()
	c := o.c
	defer func() { r.marks[spec.ID] = m }()

	looping := spec.Schedule == nil && c.RestartCount >= crashLoopRestarts && (!c.Running || c.Restarting || now.Sub(c.StartedAt) < stableRun)
	if looping {
		if m.loopSince.IsZero() {
			m.loopSince = now
		}
		issue := &state.WorkloadIssue{Kind: state.IssueCrashLoop, Since: m.loopSince.UTC(), OOMKilled: c.OOMKilled || m.lastOOMed, Restarts: c.RestartCount}
		if code, ok := exitCode(c); ok {
			issue.ExitCode = &code
		} else if m.lastExit != nil {
			issue.ExitCode = m.lastExit
		}
		return issue
	}
	m.loopSince = time.Time{}

	kind := portIssue(spec, o, probe)
	if kind == "" || kind != m.portIssue {
		m.portIssue, m.portSince = kind, now
		return nil
	}
	if now.Sub(m.portSince) < state.IssueSettle {
		return nil
	}
	port := *spec.Port
	listening := make([]state.Port, 0, len(o.ports))
	for _, l := range o.ports {
		if len(listening) < 32 {
			listening = append(listening, state.Port(l.Port))
		}
	}
	return &state.WorkloadIssue{Kind: kind, Since: m.portSince.UTC(), Port: &port, Listening: listening}
}

// portIssue classifies a running container's listeners against the port
// launchroom probes ("" = nothing clearly wrong, e.g. it doesn't listen
// anywhere yet, which is just a slow start).
func portIssue(spec *state.WorkloadSpec, o observed, probe *health.Status) state.WorkloadIssueKind {
	if spec.Port == nil || spec.Health == nil || o.ports == nil || !o.c.Running || o.c.Restarting {
		return ""
	}
	if probe != nil && probe.State == state.ProbePassing {
		return ""
	}
	want := int(*spec.Port)
	other := false
	for _, l := range o.ports {
		if l.Port == want {
			if l.LoopbackOnly {
				return state.IssueLoopbackOnly
			}
			return ""
		}
		if !l.LoopbackOnly {
			other = true
		}
	}
	if other {
		return state.IssueWrongPort
	}
	return ""
}

// settledLocked reports whether a workload finished starting (r.mu held):
// its health check passed (or, without one, it has been running) for
// settledAfter, or it started longer than startupWindow ago.
func (r *Reconciler) settledLocked(spec *state.WorkloadSpec, o observed, now time.Time) bool {
	if spec.Schedule != nil || spec.Role != state.RoleServing {
		return true
	}
	c := o.c
	if c.StartedAt.IsZero() {
		return c.State != "created" // never started and not about to
	}
	if now.Sub(c.StartedAt) > startupWindow {
		return true
	}
	if !c.Running || c.Restarting {
		return false
	}
	if spec.Health == nil {
		return now.Sub(c.StartedAt) >= settledAfter+NoProbeHealthyAfter
	}
	m := r.marks[spec.ID]
	return !m.passedAt.IsZero() && now.Sub(m.passedAt) >= settledAfter
}

// Starting lists the workloads that are still starting (not settled): their
// output is shipped as startup output (logship) and Watch looks at them
// closely.
func (r *Reconciler) Starting() []state.WorkloadID {
	r.mu.Lock()
	defer r.mu.Unlock()
	if r.ns == nil {
		return nil
	}
	now := r.o.Now()
	var out []state.WorkloadID
	for i := range r.ns.Workloads {
		s := &r.ns.Workloads[i]
		o, ok := r.observed[s.ID]
		if ok && !r.settledLocked(s, o, now) {
			out = append(out, s.ID)
		}
	}
	return out
}

// Watch re-inspects the containers of starting workloads (between reconcile
// passes, every couple of seconds) so crashes, restarts and port issues are
// reported within seconds instead of at the next pass. It returns whether
// anything changed (the caller sends a report). Safe to call while a
// reconcile pass runs: both only record observations under the lock.
func (r *Reconciler) Watch(ctx context.Context) bool {
	r.mu.Lock()
	if r.ns == nil {
		r.mu.Unlock()
		return false
	}
	now := r.o.Now()
	type target struct {
		spec *state.WorkloadSpec
		id   string
	}
	var targets []target
	for i := range r.ns.Workloads {
		s := &r.ns.Workloads[i]
		if o, ok := r.observed[s.ID]; ok && !r.settledLocked(s, o, now) {
			targets = append(targets, target{spec: s, id: o.c.ID})
		}
	}
	eventsBefore := r.events.last
	r.mu.Unlock()

	changed := false
	var exits []exitTail
	for _, t := range targets {
		c, err := r.o.Docker.InspectContainer(ctx, t.id)
		if err != nil {
			continue // removed in between: the next pass sees it
		}
		o := observed{c: c}
		if c.Running && c.Pid > 0 {
			if ls, err := r.o.Ports.Listeners(c.Pid); err == nil {
				o.ports = ls
			} else {
				o.portsErr = true
			}
		}
		r.mu.Lock()
		prev, had := r.observed[t.spec.ID]
		if had {
			o.ip = prev.ip
		}
		exits = append(exits, r.trackLocked(t.spec.ID, t.spec.Deployment, c)...)
		probe := r.probeLocked(t.spec)
		r.probeEventsLocked(t.spec, probe, c.Running)
		o.issue = r.issueLocked(t.spec, o, probe)
		if !had || prev.c.State != c.State || prev.c.RestartCount != c.RestartCount || !prev.c.StartedAt.Equal(c.StartedAt) ||
			(prev.issue == nil) != (o.issue == nil) || !slices.Equal(health.Ports(prev.ports), health.Ports(o.ports)) {
			changed = true
		}
		r.observed[t.spec.ID] = o
		r.mu.Unlock()
	}
	r.finishExits(ctx, exits)
	r.mu.Lock()
	changed = changed || r.events.last != eventsBefore
	r.mu.Unlock()
	return changed
}

// probeLocked returns the probe status of a workload (r.mu held; the
// monitor has its own lock).
func (r *Reconciler) probeLocked(spec *state.WorkloadSpec) *health.Status {
	if r.o.Health == nil || spec.Health == nil {
		return nil
	}
	if st, ok := r.o.Health.Status(spec.ID); ok {
		return &st
	}
	return nil
}
