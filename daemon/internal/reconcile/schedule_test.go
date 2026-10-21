package reconcile

import (
	"testing"
	"time"

	"github.com/vantezzen/launchroom/daemon/internal/docker"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

func scheduled(id state.WorkloadID, cron, zone string) state.WorkloadSpec {
	s := wspec(id)
	s.Restart = state.RestartNo
	s.Schedule = &state.ScheduleSpec{Cron: state.CronExpr(cron), TimeZone: state.Timezone(zone)}
	return s
}

func TestDiffCreatesScheduledWorkloadsWithoutStartingThem(t *testing.T) {
	d := Desired{Networks: []state.NetworkSpec{netSpec()}, Workloads: []DesiredWorkload{{Spec: scheduled(wlA, "*/5 * * * *", "UTC"), Hash: "h1"}}}
	a := Actual{Networks: []docker.Network{managedNet("lr-e_a", "10.231.4.0/24")}, Images: map[state.ImageRef]bool{imgA: true}}
	p := Diff(d, a, t0, time.Second)
	if len(p.Actions) != 1 || p.Actions[0].Kind != CreateWorkload || !p.Actions[0].CreateOnly {
		t.Fatalf("plan = %+v", p.Actions)
	}
	// Created and exited containers stay as they are between runs.
	for _, st := range []string{"created", "exited"} {
		a.Containers = []docker.Container{ctr(wlA, "h1", st)}
		if p := Diff(d, a, t0, time.Second); len(p.Actions) != 0 {
			t.Fatalf("%s: plan = %v", st, kinds(p))
		}
	}
}

func TestDueRunsOncePerMinuteInTheScheduleZone(t *testing.T) {
	specs := []state.WorkloadSpec{scheduled(wlA, "30 3 * * *", "Europe/Berlin"), scheduled(wlB, "*/5 * * * *", "UTC")}
	created := ctr(wlA, "h", "created")
	at := time.Date(2026, 10, 19, 1, 30, 0, 0, time.UTC) // 03:30 in Berlin (CEST)

	runs, _ := due(specs, map[state.WorkloadID]docker.Container{wlA: created, wlB: ctr(wlB, "h", "exited")}, at.Add(time.Second))
	if len(runs) != 2 || runs[0].workload != wlA || runs[0].key != "2026-10-19T03:30" {
		t.Fatalf("runs = %+v", runs)
	}

	// Not due in UTC's 03:30.
	if runs, _ := due(specs[:1], map[state.WorkloadID]docker.Container{wlA: created}, time.Date(2026, 10, 19, 3, 30, 0, 0, time.UTC)); len(runs) != 0 {
		t.Fatalf("runs = %+v", runs)
	}

	// Already started this minute: not again.
	ran := ctr(wlA, "h", "exited")
	ran.StartedAt = at.Add(2 * time.Second)
	if runs, _ := due(specs[:1], map[state.WorkloadID]docker.Container{wlA: ran}, at.Add(30*time.Second)); len(runs) != 0 {
		t.Fatalf("runs = %+v", runs)
	}

	// Still running from the last run: skipped, not started twice.
	busy := ctr(wlB, "h", "running")
	busy.StartedAt = at.Add(-5 * time.Minute)
	runs, skipped := due(specs[1:], map[state.WorkloadID]docker.Container{wlB: busy}, at)
	if len(runs) != 0 || len(skipped) != 1 {
		t.Fatalf("runs = %+v, skipped = %v", runs, skipped)
	}

	// Draining workloads never run.
	drain := scheduled(wlB, "* * * * *", "UTC")
	drain.Role = state.RoleDraining
	if runs, _ := due([]state.WorkloadSpec{drain}, map[state.WorkloadID]docker.Container{wlB: ctr(wlB, "h", "exited")}, at); len(runs) != 0 {
		t.Fatalf("runs = %+v", runs)
	}
}

func TestDueRunsARepeatedMinuteOnce(t *testing.T) {
	// 2026-10-25 Berlin: 02:30 CEST (00:30Z) and 02:30 CET (01:30Z).
	specs := []state.WorkloadSpec{scheduled(wlA, "30 2 * * *", "Europe/Berlin")}
	c := ctr(wlA, "h", "exited")
	c.StartedAt = time.Date(2026, 10, 25, 0, 30, 1, 0, time.UTC)
	if runs, _ := due(specs, map[state.WorkloadID]docker.Container{wlA: c}, time.Date(2026, 10, 25, 1, 30, 1, 0, time.UTC)); len(runs) != 0 {
		t.Fatalf("runs = %+v", runs)
	}
}

func TestScheduledStatus(t *testing.T) {
	s := scheduled(wlA, "* * * * *", "UTC")
	for st, want := range map[string]state.WorkloadPhase{"created": state.PhaseHealthy, "running": state.PhaseHealthy, "exited": state.PhaseHealthy, "paused": state.PhaseUnhealthy} {
		c := ctr(wlA, "h", st)
		c.ExitCode = 3
		ws := statusOf(&s, &observed{c: c}, nil, nil, t0)
		if ws.Phase != want {
			t.Errorf("%s: phase %s, want %s", st, ws.Phase, want)
		}
		if st == "exited" && (ws.ExitCode == nil || *ws.ExitCode != 3 || ws.Message != "scheduled: the last run exited with code 3") {
			t.Errorf("exited: %+v", ws)
		}
	}
}
