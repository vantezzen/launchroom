package state

import "time"

// ---- Container lifecycle (D68, docs/04 §8.1, docs/15 §6) ----
//
// launchroomd reports what happens to workload containers as typed events
// (Report.events) and what is clearly wrong with a starting one as an issue
// (WorkloadStatus.issue). Both are optional report fields: a dashboard that
// doesn't know them never receives them from an older launchroomd, and the
// dashboard turns them into deployment log lines, failure reasons and
// fail-fast decisions. No NodeState field changes, so no daemon feature is
// needed for them.

// WorkloadEventKind is what happened to a workload's container.
type WorkloadEventKind string

// Workload event kinds.
const (
	EventCreated       WorkloadEventKind = "created"        // launchroomd created the container
	EventCreateFailed  WorkloadEventKind = "create_failed"  // creating or starting it failed (message)
	EventStarted       WorkloadEventKind = "started"        // the container's process started
	EventExited        WorkloadEventKind = "exited"         // the process ended (exitCode, oomKilled, output)
	EventRestarted     WorkloadEventKind = "restarted"      // Docker's restart policy started it again (restarts)
	EventHealthPassing WorkloadEventKind = "health_passing" // the health check passed
	EventHealthFailing WorkloadEventKind = "health_failing" // the health check failed failureThreshold times (message, failures)
	EventStopped       WorkloadEventKind = "stopped"        // launchroomd stopped it (reason)
	EventRemoved       WorkloadEventKind = "removed"        // launchroomd removed it (reason)
)

var workloadEventKinds = []string{
	string(EventCreated), string(EventCreateFailed), string(EventStarted), string(EventExited), string(EventRestarted),
	string(EventHealthPassing), string(EventHealthFailing), string(EventStopped), string(EventRemoved),
}

// ProtocolSchema implements SchemaDescriber.
func (WorkloadEventKind) ProtocolSchema() ScalarSchema { return enumSchema(workloadEventKinds) }

// Validate checks the value.
func (v WorkloadEventKind) Validate() error {
	return checkEnum("workload event kind", string(v), workloadEventKinds)
}

// WorkloadEventReason says why launchroomd stopped or removed a container.
type WorkloadEventReason string

// Reasons of stopped/removed events.
const (
	ReasonDrained      WorkloadEventReason = "drained"        // its drain period ended (a newer deployment took over)
	ReasonNotDesired   WorkloadEventReason = "not_desired"    // the workload left the desired state (stopped, failed, held, scaled down)
	ReasonSpecChanged  WorkloadEventReason = "spec_changed"   // recreated with a changed container spec
	ReasonDuplicate    WorkloadEventReason = "duplicate"      // a second container of the same workload
	ReasonPortNotBound WorkloadEventReason = "port_not_bound" // recreated to bind its mesh-published port
)

var workloadEventReasons = []string{
	string(ReasonDrained), string(ReasonNotDesired), string(ReasonSpecChanged), string(ReasonDuplicate), string(ReasonPortNotBound),
}

// ProtocolSchema implements SchemaDescriber.
func (WorkloadEventReason) ProtocolSchema() ScalarSchema { return enumSchema(workloadEventReasons) }

// Validate checks the value.
func (v WorkloadEventReason) Validate() error {
	return checkEnum("workload event reason", string(v), workloadEventReasons)
}

// WorkloadEvent is one container lifecycle event. Events are buffered by
// launchroomd and repeated in every report until one carrying them was
// accepted (like metrics); Seq is unique and increasing per launchroomd
// (Unix µs), so the dashboard stores each event once.
type WorkloadEvent struct {
	Seq      uint64     `json:"seq" jsonschema:"minimum=1"`
	Workload WorkloadID `json:"workload"`
	// Deployment is the workload's deployment from the NodeState (omitted
	// when launchroomd no longer knows it, e.g. a leftover container).
	Deployment DeploymentID      `json:"deployment,omitzero"`
	At         time.Time         `json:"at"`
	Kind       WorkloadEventKind `json:"kind"`
	// Reason is set on stopped and removed events.
	Reason WorkloadEventReason `json:"reason,omitzero"`
	// ExitCode and OOMKilled describe an exit (exited, restarted).
	ExitCode  *int `json:"exitCode,omitzero" jsonschema:"nullable"`
	OOMKilled bool `json:"oomKilled,omitzero"`
	// Restarts is Docker's restart count of the container (restarted).
	Restarts int `json:"restarts,omitzero" jsonschema:"minimum=0"`
	// Failures is the number of consecutive failed health checks (health_failing).
	Failures int `json:"failures,omitzero" jsonschema:"minimum=0"`
	// Message is a short, secret-free detail: the probe error, the create error.
	Message string `json:"message,omitzero" jsonschema:"maxLength=1024"`
	// Output is the container's last lines before an exit (at most 30;
	// the dashboard masks them like any other deployment log line).
	Output []LogLine `json:"output,omitzero" jsonschema:"nullable,maxItems=30"`
}

// Limits of events.
const (
	// MaxReportEvents bounds Report.events.
	MaxReportEvents = 256
	// MaxEventOutputLines bounds WorkloadEvent.output.
	MaxEventOutputLines = 30
	maxEventOutputLine  = 2048
)

func (ev *WorkloadEvent) validate(e *errs, p string) {
	if ev.Seq < 1 {
		e.addf(p+".seq", "must be >= 1")
	}
	e.check(p+".workload", ev.Workload)
	if ev.Deployment != "" {
		e.check(p+".deployment", ev.Deployment)
	}
	if ev.At.IsZero() {
		e.addf(p+".at", "is required")
	}
	e.check(p+".kind", ev.Kind)
	if ev.Reason != "" {
		e.check(p+".reason", ev.Reason)
	}
	if ev.Restarts < 0 || ev.Failures < 0 {
		e.addf(p, "counts must be >= 0")
	}
	checkText(e, p+".message", ev.Message, 1024)
	checkCount(e, p+".output", len(ev.Output), 0, MaxEventOutputLines)
	for j, l := range ev.Output {
		lp := idx(p+".output", j)
		if l.Time.IsZero() {
			e.addf(lp+".time", "is required")
		}
		e.check(lp+".stream", l.Stream)
		checkText(e, lp+".text", l.Text, maxEventOutputLine)
	}
}

// WorkloadIssueKind is something clearly wrong with a starting workload:
// waiting longer will not fix it, so the dashboard fails its deployment
// right away instead of at the health deadline.
type WorkloadIssueKind string

// Workload issues.
const (
	// IssueCrashLoop: the container keeps exiting (Docker restarted it at least 3 times).
	IssueCrashLoop WorkloadIssueKind = "crash_loop"
	// IssueLoopbackOnly: the app listens on the expected port, but only on
	// 127.0.0.1/::1, so nothing outside the container reaches it.
	IssueLoopbackOnly WorkloadIssueKind = "loopback_only"
	// IssueWrongPort: the app listens on other ports, not the expected one.
	IssueWrongPort WorkloadIssueKind = "wrong_port"
)

var workloadIssueKinds = []string{string(IssueCrashLoop), string(IssueLoopbackOnly), string(IssueWrongPort)}

// ProtocolSchema implements SchemaDescriber.
func (WorkloadIssueKind) ProtocolSchema() ScalarSchema { return enumSchema(workloadIssueKinds) }

// Validate checks the value.
func (v WorkloadIssueKind) Validate() error {
	return checkEnum("workload issue kind", string(v), workloadIssueKinds)
}

// WorkloadIssue is reported on a workload whose container is clearly
// misconfigured. Port issues are only reported once they held for a while
// (IssueSettle) and while the health check doesn't pass.
type WorkloadIssue struct {
	Kind  WorkloadIssueKind `json:"kind"`
	Since time.Time         `json:"since"`
	// Port is the port launchroom expects (port issues).
	Port *Port `json:"port,omitzero" jsonschema:"nullable"`
	// Listening are the TCP ports the app listens on (port issues; loopback
	// ones included).
	Listening []Port `json:"listening,omitzero" jsonschema:"nullable,maxItems=32"`
	// ExitCode, OOMKilled and Restarts describe the last exit (crash loop).
	ExitCode  *int `json:"exitCode,omitzero" jsonschema:"nullable"`
	OOMKilled bool `json:"oomKilled,omitzero"`
	Restarts  int  `json:"restarts,omitzero" jsonschema:"minimum=0"`
}

// IssueSettle is how long a port issue must hold before it is reported:
// some apps open a port before the one they serve on.
const IssueSettle = 15 * time.Second

func (is *WorkloadIssue) validate(e *errs, p string) {
	e.check(p+".kind", is.Kind)
	if is.Since.IsZero() {
		e.addf(p+".since", "is required")
	}
	if is.Port != nil {
		e.check(p+".port", *is.Port)
	}
	checkCount(e, p+".listening", len(is.Listening), 0, maxDetectedPortCount)
	for j, port := range is.Listening {
		e.check(idx(p+".listening", j), port)
	}
	if is.Restarts < 0 {
		e.addf(p+".restarts", "must be >= 0")
	}
}
