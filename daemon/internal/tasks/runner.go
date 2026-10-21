// Package tasks leases typed tasks from the dashboard and runs them
// (docs/03 §7): a lease loop (every ~10 s, jittered), dispatch by type to
// registered handlers, heartbeats while a task runs, and an idempotent
// completion keyed by (task id, attempt).
package tasks

import (
	"context"
	"errors"
	"fmt"
	"log/slog"
	"math/rand/v2"
	"net/http"
	"slices"
	"sync"
	"sync/atomic"
	"time"

	"github.com/vantezzen/launchroom/daemon/internal/controlplane"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// Client is the subset of the control-plane client the runner uses.
type Client interface {
	LeaseTasks(ctx context.Context, req *state.LeaseRequest) ([]state.Task, error)
	Heartbeat(ctx context.Context, id state.TaskID, hb *state.TaskHeartbeat) (*state.TaskHeartbeatResult, error)
	Complete(ctx context.Context, id state.TaskID, done *state.TaskCompletion) error
	ShipLogs(ctx context.Context, batch *state.LogBatch) error
}

// Result is what a handler reports on success.
type Result struct {
	RunOnce *state.RunOnceResult
	Build   *state.BuildResult
	Plan    *state.PlanResult
	// Backup task results (docs/14).
	Backup      *state.BackupResult
	Restore     *state.RestoreResult
	Snapshots   *state.BackupSnapshotsResult
	Maintenance *state.MaintenanceResult
	// Verify is the result of a verify_restore task (docs/14 §10.4).
	Verify *state.VerifyResult
	// SelfUpdate is reported for succeeded and failed self_update tasks.
	SelfUpdate *state.SelfUpdateResult
	// Session is reported for finished session tasks, also when they failed.
	Session *state.SessionResult
	// Inspection is reported for plan tasks with inspect, also when they failed (docs/20).
	Inspection *state.RepoInspection
	// DataImport and DataExport are the results of import and export tasks (D55).
	DataImport *state.DataImportResult
	DataExport *state.DataExportResult
}

// Handler runs one task. ctx is cancelled when the deadline passes, the
// dashboard cancels the task or the daemon shuts down.
type Handler func(ctx context.Context, t state.Task) (Result, error)

// ErrUnsupported is the failure for task types this daemon cannot run.
var ErrUnsupported = errors.New("task type is not supported by this launchroomd version")

// ErrHandoff is returned by a handler whose task outlives this process
// (self_update replaces launchroomd's container): the runner does not
// complete the attempt; the next launchroomd process reports it.
var ErrHandoff = errors.New("task handed over to the next launchroomd process")

type progressKey struct{}

type lostKey struct{}

// LeaseLost reports whether the runner stopped the task because its
// heartbeats kept failing (MaxHeartbeatFailures).
func LeaseLost(ctx context.Context) bool {
	l, ok := ctx.Value(lostKey{}).(*atomic.Bool)
	return ok && l.Load()
}

// progress holds a task's latest progress line (sent with heartbeats).
type progress struct {
	mu   sync.Mutex
	line string
}

// SetProgress sets the short status line sent with the task's next
// heartbeat (docs/03 §7). It is a no-op outside a runner.
func SetProgress(ctx context.Context, line string) {
	if p, ok := ctx.Value(progressKey{}).(*progress); ok {
		if len(line) > 256 {
			line = line[:256]
		}
		p.mu.Lock()
		p.line = line
		p.mu.Unlock()
	}
}

func (p *progress) get() string {
	p.mu.Lock()
	defer p.mu.Unlock()
	return p.line
}

// Options configures a Runner.
type Options struct {
	Client   Client
	Handlers map[state.TaskType]Handler
	// Interval is the lease poll interval (default 10 s, jittered ±10%).
	Interval time.Duration
	// HeartbeatEvery is the heartbeat interval while a task runs (default 20 s; leases last 60 s).
	HeartbeatEvery time.Duration
	// MaxConcurrent bounds running tasks (default 4).
	MaxConcurrent int
	// ShutdownGrace is how long running tasks may still finish after the
	// daemon starts shutting down (default 0: stop at once). Tasks that do
	// not finish in time are stopped and completed as "released": the
	// dashboard re-queues them instead of failing or cancelling them.
	ShutdownGrace time.Duration
	// MaxHeartbeatFailures stops a running task after this many heartbeats
	// in a row failed (0: never; interactive sessions fail closed after 2,
	// docs/03 §11.3). LeaseLost reports it to the handler.
	MaxHeartbeatFailures int
	Log                  *slog.Logger
	Now                  func() time.Time
}

// Runner leases and runs tasks.
type Runner struct {
	o Options

	mu      sync.Mutex
	running map[state.TaskID]bool
	wg      sync.WaitGroup
	kick    chan struct{}
}

// New returns a Runner.
func New(o Options) *Runner {
	if o.Interval <= 0 {
		o.Interval = 10 * time.Second
	}
	if o.HeartbeatEvery <= 0 {
		o.HeartbeatEvery = 20 * time.Second
	}
	if o.MaxConcurrent <= 0 {
		o.MaxConcurrent = 4
	}
	if o.Log == nil {
		o.Log = slog.New(slog.DiscardHandler)
	}
	if o.Now == nil {
		o.Now = time.Now
	}
	return &Runner{o: o, running: map[state.TaskID]bool{}, kick: make(chan struct{}, 1)}
}

// Kick asks for an immediate lease (e.g. after a "task available" nudge).
func (r *Runner) Kick() {
	select {
	case r.kick <- struct{}{}:
	default:
	}
}

// Run leases until ctx is done, then waits for running tasks: they get
// ShutdownGrace to finish, then their contexts are cancelled and they
// complete as released (handed back for another attempt).
func (r *Runner) Run(ctx context.Context) {
	defer r.wg.Wait()
	for {
		r.LeaseOnce(ctx)
		f := 0.9 + 0.2*rand.Float64() //nolint:gosec // jitter does not need a CSPRNG
		t := time.NewTimer(time.Duration(float64(r.o.Interval) * f))
		select {
		case <-ctx.Done():
			t.Stop()
			return
		case <-r.kick:
			t.Stop()
		case <-t.C:
		}
	}
}

func (r *Runner) types() []state.TaskType {
	out := make([]state.TaskType, 0, len(r.o.Handlers))
	for t := range r.o.Handlers {
		out = append(out, t)
	}
	slices.Sort(out)
	return out
}

// LeaseOnce leases up to the free capacity and starts the tasks.
func (r *Runner) LeaseOnce(ctx context.Context) {
	r.mu.Lock()
	free := r.o.MaxConcurrent - len(r.running)
	r.mu.Unlock()
	types := r.types()
	if free <= 0 || len(types) == 0 || ctx.Err() != nil {
		return
	}
	tasks, err := r.o.Client.LeaseTasks(ctx, &state.LeaseRequest{
		TypeMeta: state.NewTypeMeta(state.KindLeaseRequest), Max: min(free, 16), Types: types,
	})
	if err != nil {
		if ctx.Err() == nil {
			r.o.Log.Warn("leasing tasks failed", "err", err)
		}
		return
	}
	for _, t := range tasks {
		r.mu.Lock()
		dup := r.running[t.ID]
		if !dup {
			r.running[t.ID] = true
		}
		r.mu.Unlock()
		if dup {
			continue // still running from an earlier lease
		}
		r.wg.Add(1)
		go r.run(ctx, t)
	}
}

func (r *Runner) run(parent context.Context, t state.Task) {
	defer r.wg.Done()
	defer func() {
		r.mu.Lock()
		delete(r.running, t.ID)
		r.mu.Unlock()
	}()
	log := r.o.Log.With("task", t.ID, "type", t.Type, "attempt", t.Attempt)
	log.Info("task started")

	// The task does not stop the moment the daemon starts shutting down: it
	// gets ShutdownGrace to finish (stopAfterGrace), then it is released.
	prog := &progress{}
	lost := &atomic.Bool{}
	base := context.WithValue(context.WithValue(context.WithoutCancel(parent), progressKey{}, prog), lostKey{}, lost)
	ctx, cancel := context.WithDeadline(base, t.Deadline)
	defer cancel()
	finished := make(chan struct{})
	defer close(finished)
	go r.stopAfterGrace(parent, ctx, cancel, finished, log)
	var cancelled sync.Once
	var cancelledByCP bool

	hbDone := make(chan struct{})
	go func() {
		defer close(hbDone)
		tick := time.NewTicker(r.o.HeartbeatEvery)
		defer tick.Stop()
		failures := 0
		for {
			select {
			case <-ctx.Done():
				return
			case <-tick.C:
			}
			res, err := r.o.Client.Heartbeat(ctx, t.ID, &state.TaskHeartbeat{TypeMeta: state.NewTypeMeta(state.KindHeartbeat), Attempt: t.Attempt, Progress: prog.get()})
			var api *controlplane.APIError
			switch {
			case err == nil && res.Cancel:
				log.Info("dashboard cancelled the task")
				cancelled.Do(func() { cancelledByCP = true })
				cancel()
				return
			case errors.As(err, &api) && (api.Status == http.StatusNotFound || api.Status == http.StatusConflict || api.Status == http.StatusGone):
				log.Warn("task lease lost; stopping", "err", err)
				cancelled.Do(func() { cancelledByCP = true })
				cancel()
				return
			case err != nil && ctx.Err() == nil:
				log.Warn("task heartbeat failed", "err", err)
				failures++
				if r.o.MaxHeartbeatFailures > 0 && failures >= r.o.MaxHeartbeatFailures {
					log.Warn("too many failed heartbeats; stopping the task", "failures", failures)
					lost.Store(true)
					cancel()
					return
				}
				continue
			}
			failures = 0
		}
	}()

	var res Result
	var err error
	if h, ok := r.o.Handlers[t.Type]; ok {
		res, err = safeRun(ctx, h, t)
	} else {
		err = ErrUnsupported
	}
	cancel()
	<-hbDone

	if errors.Is(err, ErrHandoff) {
		log.Info("task handed over to the next launchroomd process")
		return
	}
	done := &state.TaskCompletion{TypeMeta: state.NewTypeMeta(state.KindTaskCompletion), Attempt: t.Attempt}
	switch {
	case err == nil:
		done.Outcome, done.Build, done.RunOnce, done.Plan = state.OutcomeSucceeded, res.Build, res.RunOnce, res.Plan
		done.Backup, done.Restore, done.Snapshots, done.Maintenance = res.Backup, res.Restore, res.Snapshots, res.Maintenance
		done.Verify = res.Verify
		done.DataImport, done.DataExport = res.DataImport, res.DataExport
	case cancelledByCP:
		done.Outcome, done.Error = state.OutcomeCancelled, clip(err.Error())
	case parent.Err() != nil:
		// Shutting down (restart, upgrade): hand the lease back so the task
		// is retried instead of failed or cancelled.
		done.Outcome, done.Error = state.OutcomeReleased, "launchroomd stopped before the task finished: "+clip(err.Error())
	case errors.Is(err, context.DeadlineExceeded):
		done.Outcome, done.Error = state.OutcomeFailed, "deadline exceeded"
	default:
		done.Outcome, done.Error = state.OutcomeFailed, clip(err.Error())
	}
	if res.SelfUpdate != nil {
		done.SelfUpdate = res.SelfUpdate
	}
	if res.Session != nil {
		done.Session = res.Session
	}
	if res.Inspection != nil && done.Outcome != state.OutcomeReleased {
		done.Inspection = res.Inspection
	}
	var fc failureCarrier
	if err != nil && done.Outcome == state.OutcomeFailed && errors.As(err, &fc) {
		done.Failure = fc.Failure()
		if done.Failure.Class != state.FailureTimeout && errors.Is(err, context.DeadlineExceeded) {
			done.Failure.Class = state.FailureTimeout
		}
	}
	r.complete(parent, t, done, log)
}

// stopAfterGrace cancels a running task ShutdownGrace after parent (the
// daemon) is done, unless the task finishes first.
func (r *Runner) stopAfterGrace(parent, task context.Context, cancel context.CancelFunc, finished <-chan struct{}, log *slog.Logger) {
	select {
	case <-finished:
		return
	case <-task.Done():
		return
	case <-parent.Done():
	}
	if r.o.ShutdownGrace > 0 {
		log.Info("launchroomd is stopping; letting the task finish", "grace", r.o.ShutdownGrace.String())
		t := time.NewTimer(r.o.ShutdownGrace)
		defer t.Stop()
		select {
		case <-finished:
			return
		case <-task.Done():
			return
		case <-t.C:
		}
	}
	log.Info("launchroomd is stopping; releasing the task")
	cancel()
}

func safeRun(ctx context.Context, h Handler, t state.Task) (res Result, err error) {
	defer func() {
		if p := recover(); p != nil {
			err = fmt.Errorf("handler panicked: %v", p)
		}
	}()
	return h(ctx, t)
}

// complete reports the outcome, retrying transient failures a few times. It
// uses a fresh context so completion still happens during shutdown.
func (r *Runner) complete(parent context.Context, t state.Task, done *state.TaskCompletion, log *slog.Logger) {
	ctx, cancel := context.WithTimeout(context.WithoutCancel(parent), 2*time.Minute)
	defer cancel()
	backoff := time.Second
	for attempt := 1; ; attempt++ {
		err := r.o.Client.Complete(ctx, t.ID, done)
		if err == nil {
			log.Info("task completed", "outcome", done.Outcome)
			return
		}
		if !controlplane.IsRetryable(err) || attempt >= 5 {
			log.Error("could not report task completion", "err", err, "outcome", done.Outcome)
			return
		}
		select {
		case <-ctx.Done():
			return
		case <-time.After(backoff):
		}
		backoff *= 2
	}
}

func clip(s string) string {
	if len(s) > 4096 {
		return s[:4096]
	}
	return s
}

// LineShipper ships step-grouped log lines of a task (the build/backup
// shipper, exported for other packages' handlers such as session transcripts).
type LineShipper interface {
	Line(step string, stream state.LogStream, text string)
	Close()
}

// NewTaskLogShipper returns a shipper for the logs of task id, attempt.
func NewTaskLogShipper(ctx context.Context, c Client, id state.TaskID, attempt int) LineShipper {
	return newStepShipper(ctx, c, state.LogSource{Kind: state.LogSourceTask, ID: string(id)}, attempt)
}
