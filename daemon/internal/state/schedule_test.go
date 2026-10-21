package state

import (
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func cronFixture(t *testing.T) *NodeState {
	t.Helper()
	data, err := os.ReadFile(filepath.Join(fixtureDir, "node-state.cron.json"))
	if err != nil {
		t.Fatal(err)
	}
	ns, err := Decode[NodeState](data)
	if err != nil {
		t.Fatal(err)
	}
	return ns
}

func TestScheduleValidation(t *testing.T) {
	if err := cronFixture(t).Validate(); err != nil {
		t.Fatal(err)
	}
	port := Port(80)
	for name, tc := range map[string]struct {
		mutate func(w *WorkloadSpec)
		want   string
	}{
		"bad cron":       {func(w *WorkloadSpec) { w.Schedule.Cron = "61 * * * *" }, "outside 0-59"},
		"six fields":     {func(w *WorkloadSpec) { w.Schedule.Cron = "0 0 * * * *" }, "does not match"},
		"unknown zone":   {func(w *WorkloadSpec) { w.Schedule.TimeZone = "Mars/Olympus" }, "unknown time zone"},
		"restart policy": {func(w *WorkloadSpec) { w.Restart = RestartUnlessStopped }, `requires restart "no"`},
		"port":           {func(w *WorkloadSpec) { w.Port = &port }, "without port"},
	} {
		t.Run(name, func(t *testing.T) {
			ns := cronFixture(t)
			w := &ns.Workloads[len(ns.Workloads)-1]
			tc.mutate(w)
			err := ns.Validate()
			if err == nil || !strings.Contains(err.Error(), tc.want) {
				t.Fatalf("err = %v, want %q", err, tc.want)
			}
		})
	}
}
