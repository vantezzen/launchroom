package state

import (
	"strings"
	"testing"
	"time"
)

func TestStoppedRouteValidation(t *testing.T) {
	ns := validNodeState(t)
	ns.Routes[0].Stopped = true
	ns.Routes[0].Upstreams = nil
	if err := ns.Validate(); err != nil {
		t.Fatalf("stopped route: %v", err)
	}
	ns.Routes[0].Upstreams = []RouteUpstream{{Address: "100.64.0.9:31002"}}
	if err := ns.Validate(); err == nil {
		t.Error("stopped route with upstreams accepted")
	}
	ns = validNodeState(t)
	ns.Routes[0].Upstreams = nil
	if err := ns.Validate(); err == nil {
		t.Error("running route without upstreams accepted")
	}
}

func lifecycleReport() *Report {
	now := time.Date(2026, 10, 25, 12, 0, 0, 0, time.UTC)
	code := 1
	port := Port(8000)
	return &Report{
		TypeMeta: NewTypeMeta(KindReport), ServerID: "srv_01J9Z8X7W6V5T4S3R2Q1P0N9MZ", ObservedAt: now, Seq: 7, Full: true,
		Workloads: []WorkloadStatus{{
			ID: "wl_01J9Z8X7W6V5T4S3R2Q1P0N9MA", Phase: PhaseUnhealthy, ObservedAt: now, RestartCount: 3,
			Health: &ProbeStatus{State: ProbeStarting, Attempts: 4, LastError: "connection refused"},
			Issue:  &WorkloadIssue{Kind: IssueLoopbackOnly, Since: now, Port: &port, Listening: []Port{8000}},
		}},
		Events: []WorkloadEvent{
			{Seq: 1, Workload: "wl_01J9Z8X7W6V5T4S3R2Q1P0N9MA", Deployment: "dpl_01J9Z8X7W6V5T4S3R2Q1P0N9MB", At: now, Kind: EventCreated},
			{Seq: 2, Workload: "wl_01J9Z8X7W6V5T4S3R2Q1P0N9MA", At: now, Kind: EventExited, ExitCode: &code,
				Output: []LogLine{{Time: now, Stream: StreamStderr, Text: "ModuleNotFoundError: No module named 'app'"}}},
			{Seq: 3, Workload: "wl_01J9Z8X7W6V5T4S3R2Q1P0N9MA", At: now, Kind: EventStopped, Reason: ReasonDrained},
		},
	}
}

func TestLifecycleReportValidation(t *testing.T) {
	if err := lifecycleReport().Validate(); err != nil {
		t.Fatal(err)
	}
	bad := map[string]func(r *Report){
		"event kind":     func(r *Report) { r.Events[0].Kind = "exploded" },
		"event reason":   func(r *Report) { r.Events[2].Reason = "because" },
		"event seq":      func(r *Report) { r.Events[0].Seq = 0 },
		"event time":     func(r *Report) { r.Events[0].At = time.Time{} },
		"event workload": func(r *Report) { r.Events[0].Workload = "nope" },
		"event message":  func(r *Report) { r.Events[0].Message = strings.Repeat("x", 1025) },
		"output lines": func(r *Report) {
			r.Events[1].Output = make([]LogLine, MaxEventOutputLines+1)
			for i := range r.Events[1].Output {
				r.Events[1].Output[i] = LogLine{Time: r.ObservedAt, Stream: StreamStdout, Text: "x"}
			}
		},
		"output stream": func(r *Report) { r.Events[1].Output[0].Stream = "tty" },
		"issue kind":    func(r *Report) { r.Workloads[0].Issue.Kind = "sad" },
		"issue since":   func(r *Report) { r.Workloads[0].Issue.Since = time.Time{} },
		"issue port":    func(r *Report) { r.Workloads[0].Issue.Listening = []Port{0} },
		"attempts":      func(r *Report) { r.Workloads[0].Health.Attempts = -1 },
		"too many events": func(r *Report) {
			for len(r.Events) <= MaxReportEvents {
				r.Events = append(r.Events, r.Events[0])
			}
		},
	}
	for name, mutate := range bad {
		r := lifecycleReport()
		mutate(r)
		if err := r.Validate(); err == nil {
			t.Errorf("%s: accepted", name)
		}
	}
}
