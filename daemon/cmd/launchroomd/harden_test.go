package main

import (
	"os"
	"strings"
	"testing"
)

// One invalid document (here: a network declared twice, the previews bug)
// must not stall the server silently: launchroomd keeps converging to its
// last good state, reports the rejection (Report.stateRejected) and clears
// it once a newer valid state arrives.
func TestRejectedStateIsReportedAndLastGoodStateKept(t *testing.T) {
	cp, srv := newFakeDashboard(t)
	cfg := testConfig(t, srv.URL, cp.pub(), testToken)
	logs := &syncBuffer{}
	stop, refresh := startDaemon(t, cfg, logs)
	eventually(t, "first state persisted", func() bool { return persistedVersion(t, cfg, cp.pub()) == 1842 })

	cp.mu.Lock()
	good := cp.stateDoc
	cp.version = 1843
	cp.stateDoc = strings.Replace(good, `"networks": [`, `"networks": [{"id": "net_01J9Z8X7W6V5T4S3R2Q1P0N9N4", "name": "lr-e_7f3c2a91b0d4e5f6", "subnet": "10.231.4.0/24"}, `, 1)
	cp.mu.Unlock()
	refresh <- os.Interrupt
	eventually(t, "rejection reported", func() bool {
		r := cp.lastReport()
		return r != nil && r.StateRejected != nil && r.StateRejected.Version == 1843
	})
	rep := cp.lastReport()
	if rep.AppliedVersion != 1842 || !strings.Contains(rep.StateRejected.Error, "duplicate") {
		t.Fatalf("report %+v / %+v", rep, rep.StateRejected)
	}
	if v := persistedVersion(t, cfg, cp.pub()); v != 1842 {
		t.Fatalf("persisted version %d", v)
	}

	cp.mu.Lock()
	cp.version = 1844
	cp.stateDoc = good
	cp.mu.Unlock()
	refresh <- os.Interrupt
	eventually(t, "rejection cleared", func() bool {
		r := cp.lastReport()
		return r != nil && r.AppliedVersion == 1844 && r.StateRejected == nil
	})
	if err := stop(); err != nil {
		t.Fatal(err)
	}
}
