//go:build integration

package integration

import (
	"testing"
	"time"

	"github.com/vantezzen/launchroom/daemon/internal/ingress/caddy"
	"github.com/vantezzen/launchroom/daemon/internal/policy"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// G15: another program holds port 80 on the edge (an nginx installed after
// launchroom). The ingress names the reason, the port and the program, and
// applies the routes by itself once the port is free.
func TestIngressPortInUse(t *testing.T) {
	e := setupEdge(t, caddy.Options{}, nil, nil, func(o *caddy.ManagerOptions, _ *policy.Config) {
		o.ProcRoot = "/proc" // the test shares the fake server's PID and network namespaces
	})
	ctx := t.Context()
	holder := runHelper(ctx, t, e.engine, e.serverID, "lr-itest-port80", busyboxImage, nil, []string{"httpd", "-f", "-p", "80"}, nil, nil)
	ns, _ := e.nodeState(t, 1, true, true)

	converge(t, e, ns, func() bool {
		_, _, ing := e.rec.Snapshot()
		return ing != nil && ing.Reason == state.IngressPortInUse
	})
	_, _, ing := e.rec.Snapshot()
	t.Logf("ingress: state=%s reason=%s port=%v process=%q message=%q", ing.State, ing.Reason, ing.Port, ing.Process, ing.Message)
	if ing.State == state.IngressApplied || ing.Port == nil || *ing.Port != 80 || ing.Process != "httpd" {
		t.Fatalf("ingress %+v", ing)
	}
	rep := state.Report{TypeMeta: state.NewTypeMeta(state.KindReport), ServerID: e.serverID, ObservedAt: time.Now().UTC(), Seq: 1, Full: true,
		AppliedVersion: 1, Workloads: []state.WorkloadStatus{}, Ingress: ing}
	if err := rep.Validate(); err != nil {
		t.Fatalf("report: %v", err)
	}

	if err := e.engine.StopContainer(ctx, holder, time.Second); err != nil {
		t.Fatal(err)
	}
	if err := e.engine.RemoveContainer(ctx, holder); err != nil {
		t.Fatal(err)
	}
	converge(t, e, ns, func() bool {
		_, _, ing := e.rec.Snapshot()
		return ing != nil && ing.State == state.IngressApplied
	})
	if _, _, ing := e.rec.Snapshot(); ing.Reason != "" || ing.Port != nil || ing.Process != "" {
		t.Fatalf("after the port was freed: %+v", ing)
	}
}
