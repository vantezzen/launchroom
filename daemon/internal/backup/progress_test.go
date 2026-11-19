package backup

import (
	"context"
	"encoding/json"
	"fmt"
	"slices"
	"strings"
	"sync"
	"testing"
	"time"

	"github.com/vantezzen/launchroom/daemon/internal/state"
)

type logRecorder struct {
	mu    sync.Mutex
	lines []string
}

func (l *logRecorder) logf(format string, args ...any) {
	l.mu.Lock()
	defer l.mu.Unlock()
	l.lines = append(l.lines, fmt.Sprintf(format, args...))
}

func (l *logRecorder) progress(t *testing.T) []ProgressLine {
	t.Helper()
	l.mu.Lock()
	defer l.mu.Unlock()
	var out []ProgressLine
	for _, s := range l.lines {
		if !IsProgress(s) {
			continue
		}
		var p ProgressLine
		if err := json.Unmarshal([]byte(strings.TrimPrefix(s, ProgressPrefix)), &p); err != nil {
			t.Fatalf("progress line %q: %v", s, err)
		}
		if p.V != ProgressVersion {
			t.Fatalf("progress line %q: version %d", s, p.V)
		}
		out = append(out, p)
	}
	return out
}

func phases(ps []ProgressLine) []string {
	var out []string
	for _, p := range ps {
		if len(out) == 0 || out[len(out)-1] != p.Phase {
			out = append(out, p.Phase)
		}
	}
	return out
}

func TestBackupReportsProgress(t *testing.T) {
	t.Parallel()
	h := newHarness(t, map[string]fakeStep{"backup": {Stdout: summaryLine}})
	var rec logRecorder
	if _, err := h.e.Backup(context.Background(), backupSpec(), creds, rec.logf); err != nil {
		t.Fatal(err)
	}
	ps := rec.progress(t)
	if got := phases(ps); !slices.Equal(got, []string{PhasePreparing, PhaseStreaming, PhaseFinishing, PhaseStored}) {
		t.Fatalf("phases = %v", got)
	}
	last := ps[len(ps)-1]
	if last.Op != "backup" || last.Bytes != int64(len("DUMPDATA")) || last.Snapshot != snapshotID[:8] || last.AddedBytes != 2048 {
		t.Fatalf("stored line = %+v", last)
	}
	// The human-readable lines stay.
	if !slices.ContainsFunc(rec.lines, func(s string) bool { return strings.HasPrefix(s, "Snapshot ") }) {
		t.Fatalf("lines = %v", rec.lines)
	}
	assertNoSecrets(t, strings.Join(rec.lines, "\n"))
}

func TestVolumeBackupReportsProgressWithResticTotals(t *testing.T) {
	t.Parallel()
	status := `{"message_type":"status","percent_done":0.25,"total_bytes":36,"bytes_done":9}` + "\n"
	h, _ := volumeHarness(t, map[string]fakeStep{"backup": {Stdout: status + summaryLine}})
	var rec logRecorder
	res, err := h.e.Backup(context.Background(), volumeSpec(state.ConsistencyLive), creds, rec.logf)
	if err != nil || string(res.SnapshotID) != snapshotID {
		t.Fatalf("res = %+v, err %v", res, err)
	}
	if got := phases(rec.progress(t)); !slices.Equal(got, []string{PhasePreparing, PhaseStreaming, PhaseFinishing, PhaseStored}) {
		t.Fatalf("phases = %v", got)
	}
}

func TestRestoreReportsProgressAgainstTheSnapshotSize(t *testing.T) {
	t.Parallel()
	snaps := `[{"id":"` + snapshotID + `","time":"2026-10-17T03:00:00Z","paths":["/postgres.dump"],"tags":["kind:database"],"summary":{"total_bytes_processed":11}}]`
	h := newHarness(t, map[string]fakeStep{"snapshots": {Stdout: snaps}, "dump": {Stdout: "RESTOREDATA"}})
	var rec logRecorder
	if _, err := h.e.Restore(context.Background(), restoreSpec(), creds, rec.logf); err != nil {
		t.Fatal(err)
	}
	ps := rec.progress(t)
	if got := phases(ps); !slices.Equal(got, []string{PhasePreparing, PhaseRestoring, PhaseDone}) {
		t.Fatalf("phases = %v", got)
	}
	for _, p := range ps {
		if p.Phase == PhaseRestoring && p.TotalBytes != 11 {
			t.Fatalf("restoring line = %+v", p)
		}
	}
	if last := ps[len(ps)-1]; last.Op != "restore" || last.Bytes != 11 {
		t.Fatalf("done line = %+v", last)
	}
}

func TestMaintenanceReportsPhases(t *testing.T) {
	t.Parallel()
	h := newHarness(t, map[string]fakeStep{"forget": {Stdout: `[{"remove":[{"id":"x"}]}]`}})
	var rec logRecorder
	spec := &state.BackupMaintenanceSpec{Operation: state.MaintenanceForget, Destination: dest, Tags: []state.BackupTag{"service:svc_01J9Z8X7W6V5T4S3R2Q1P0N9MK"}, Retention: &state.Retention{KeepLast: 1}}
	if _, err := h.e.Maintenance(context.Background(), spec, creds, rec.logf); err != nil {
		t.Fatal(err)
	}
	if got := phases(rec.progress(t)); !slices.Equal(got, []string{PhaseForgetting, PhasePruning, PhaseDone}) {
		t.Fatalf("phases = %v", got)
	}
}

func TestProgressThrottle(t *testing.T) {
	t.Parallel()
	start := time.Date(2026, 11, 18, 3, 0, 0, 0, time.UTC)
	now := start
	var rec logRecorder
	var n int64
	tr := &tracker{ctx: context.Background(), op: "backup", every: 2 * time.Second, start: start, now: func() time.Time { return now }, raw: rec.logf}
	tr.phase(PhaseStreaming, func() int64 { return n }, 0) // forced: 1 line
	tick := func(d time.Duration) {
		now = now.Add(d)
		tr.mu.Lock()
		tr.emitLocked(false)
		tr.mu.Unlock()
	}
	n = 10
	tick(time.Second) // too soon
	tick(time.Second) // 2 s: a line
	tick(2 * time.Second)
	if got := len(rec.progress(t)); got != 2 {
		t.Fatalf("lines after 2 s with no change = %d, want 2 (unchanged lines are skipped)", got)
	}
	tick(30 * time.Second) // unchanged, but quiet for 30 s: a line
	if got := len(rec.progress(t)); got != 3 {
		t.Fatalf("lines = %d, want a keep-alive line after 30 s", got)
	}
	// After 5 minutes the lines slow down to every 10 s.
	now = start.Add(6 * time.Minute)
	n++
	tick(0)
	before := len(rec.progress(t))
	for range 4 {
		n++
		tick(2 * time.Second)
	}
	if got := len(rec.progress(t)) - before; got != 0 {
		t.Fatalf("slow phase emitted %d lines within 8 s", got)
	}
	n++
	tick(2 * time.Second)
	if got := len(rec.progress(t)) - before; got != 1 {
		t.Fatalf("slow phase emitted %d lines after 10 s, want 1", got)
	}
	last := rec.progress(t)[len(rec.progress(t))-1]
	if last.Bytes != n || last.ElapsedMs != now.Sub(start).Milliseconds() {
		t.Fatalf("last = %+v", last)
	}
}

func TestProgressTickerRuns(t *testing.T) {
	t.Parallel()
	var rec logRecorder
	var mu sync.Mutex
	var n int64
	tr := &tracker{ctx: context.Background(), op: "backup", every: 5 * time.Millisecond, start: time.Now(), now: time.Now, raw: rec.logf}
	tr.phase(PhaseStreaming, func() int64 { mu.Lock(); defer mu.Unlock(); return n }, 0)
	stop := tr.ticking()
	deadline := time.Now().Add(5 * time.Second)
	for len(rec.progress(t)) < 4 && time.Now().Before(deadline) {
		mu.Lock()
		n += 100
		mu.Unlock()
		time.Sleep(2 * time.Millisecond)
	}
	stop()
	stop()
	if len(rec.progress(t)) < 4 {
		t.Fatal("the ticker emitted no periodic lines")
	}
	tr.finish(n)
	if last := rec.progress(t)[len(rec.progress(t))-1]; last.Phase != PhaseDone {
		t.Fatalf("last = %+v", last)
	}
}

func TestResticOutputDropsStatusLines(t *testing.T) {
	t.Parallel()
	o := &resticOutput{}
	in := summaryLine + `{"message_type":"status","percent_done":0.5,"total_bytes":100,"bytes_done":50}` + "\n"
	for i := 0; i < len(in); i += 7 { // split writes
		_, _ = o.Write([]byte(in[i:min(i+7, len(in))]))
	}
	if st := o.latest(); st.TotalBytes != 100 || st.BytesDone != 50 {
		t.Fatalf("status = %+v", st)
	}
	if strings.Contains(string(o.Bytes()), `"status"`) {
		t.Fatalf("kept status lines: %s", o.Bytes())
	}
	if s := parseBackupSummary(o.Bytes()); s.SnapshotID != snapshotID || s.DataAdded != 2048 {
		t.Fatalf("summary = %+v", s)
	}
}

func TestProgressTextFormat(t *testing.T) {
	t.Parallel()
	got := ProgressText(ProgressLine{Op: "backup", Phase: PhaseStreaming, Bytes: 1024, ElapsedMs: 2000})
	if want := `::progress:: {"v":1,"op":"backup","phase":"streaming","bytes":1024,"elapsedMs":2000}`; got != want {
		t.Fatalf("got  %s\nwant %s", got, want)
	}
	if !IsProgress(got) || IsProgress("Streaming the postgres dump into the repository") {
		t.Fatal("IsProgress")
	}
}
