package hostinfo

import (
	"context"
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"

	"github.com/moby/moby/api/types/volume"
	mobyclient "github.com/moby/moby/client"

	"github.com/vantezzen/launchroom/daemon/internal/docker"
	"github.com/vantezzen/launchroom/daemon/internal/names"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

const testServer = "srv_01J9Z8X7W6V5T4S3R2Q1P0N9MK"

func validHost(t *testing.T, h *state.HostStatus) {
	t.Helper()
	rep := state.Report{TypeMeta: state.NewTypeMeta(state.KindReport), ServerID: testServer, ObservedAt: time.Now().UTC(), Seq: 1, Host: h}
	if err := rep.Validate(); err != nil {
		t.Fatal(err)
	}
}

// G4: a clean shutdown leaves nothing behind; a run that ended without one
// (crash, kill, reboot) is reported with its last heartbeat and crash
// output until a report carrying it was accepted, across another restart.
func TestDaemonRun(t *testing.T) {
	dir, proc := t.TempDir(), t.TempDir()
	if err := os.WriteFile(filepath.Join(proc, "stat"), []byte("cpu  1 2 3\nbtime 1763380000\nprocesses 5\n"), 0o600); err != nil {
		t.Fatal(err)
	}
	start := time.Date(2026, 11, 17, 10, 0, 0, 0, time.UTC)

	r, err := StartRun(dir, proc, start)
	if err != nil {
		t.Fatal(err)
	}
	st := r.Status()
	if !st.StartedAt.Equal(start) || st.HostBootedAt == nil || st.HostBootedAt.Unix() != 1763380000 || st.PreviousExit != nil {
		t.Fatalf("first run %+v", st)
	}
	r.Stop()
	if _, err := os.Stat(filepath.Join(dir, RunMarkerFile)); !errors.Is(err, os.ErrNotExist) {
		t.Fatalf("marker after a clean stop: %v", err)
	}

	// Clean restart: no previous exit.
	r, err = StartRun(dir, proc, start.Add(time.Hour))
	if err != nil {
		t.Fatal(err)
	}
	if st := r.Status(); st.PreviousExit != nil {
		t.Fatalf("after a clean stop %+v", st.PreviousExit)
	}
	// The run crashes: heartbeat, crash output, no Stop.
	beat := start.Add(2 * time.Hour)
	if err := os.WriteFile(filepath.Join(dir, HeartbeatFile), nil, 0o600); err != nil {
		t.Fatal(err)
	}
	_ = os.Chtimes(filepath.Join(dir, HeartbeatFile), beat, beat)
	r.WriteCrash([]byte("panic: runtime error: index out of range\x00\x1b[1m\n\ngoroutine 1 [running]:\n" + strings.Repeat("main.f()\n", 3000)))

	r2, err := StartRun(dir, proc, start.Add(3*time.Hour))
	if err != nil {
		t.Fatal(err)
	}
	prev := r2.Status().PreviousExit
	if prev == nil || !prev.Unclean || prev.At == nil || !prev.At.Equal(beat) {
		t.Fatalf("previous exit %+v", prev)
	}
	if !strings.HasPrefix(prev.Panic, "panic: runtime error: index out of range  [1m\n\ngoroutine 1") || len(prev.Panic) > state.MaxPanicTrace {
		t.Fatalf("panic %q (%d bytes)", prev.Panic[:80], len(prev.Panic))
	}
	validHost(t, &state.HostStatus{CPUs: 1, DaemonVersion: "1.0.0", Daemon: r2.Status()})

	// Killed again before delivering it: still reported (the marker of r2 is there too).
	r3, err := StartRun(dir, proc, start.Add(4*time.Hour))
	if err != nil {
		t.Fatal(err)
	}
	prev = r3.Status().PreviousExit
	if prev == nil || !prev.Unclean {
		t.Fatalf("after a second unclean exit %+v", prev)
	}
	r3.Ack(r3.Status())
	if r3.Status().PreviousExit != nil {
		t.Fatal("ack kept the previous exit")
	}
	r3.Stop()
	r4, err := StartRun(dir, proc, start.Add(5*time.Hour))
	if err != nil {
		t.Fatal(err)
	}
	defer r4.Stop()
	if p := r4.Status().PreviousExit; p != nil {
		t.Fatalf("acked exit came back: %+v", p)
	}
}

func TestBootTimeUnreadable(t *testing.T) {
	if BootTime(t.TempDir()) != nil {
		t.Fatal("boot time without /proc/stat")
	}
}

// G3: Docker reachability from pings and reconcile passes; reconcile
// errors classified with their target.
func TestDockerCheckAndReconcile(t *testing.T) {
	at := time.Date(2026, 11, 17, 10, 0, 0, 0, time.UTC)
	flips := 0
	d := NewDockerCheck(nil, func() time.Time { return at }, func() { flips++ })
	if d.Snapshot() != nil {
		t.Fatal("snapshot before a check")
	}
	d.ObservePass(nil)
	d.ObservePass(errors.New("create container wl_01J9Z8X7W6V5T4S3R2Q1P0N9MK: invalid mount"))
	if s := d.Snapshot(); s == nil || !s.Reachable || flips != 1 {
		t.Fatalf("snapshot %+v flips %d", s, flips)
	}
	d.ObservePass(fmt.Errorf("list containers: %w", errors.New("Cannot connect to the Docker daemon at unix:///run/guard/docker.sock. Is the docker daemon running?")))
	s := d.Snapshot()
	if s.Reachable || !strings.Contains(s.Error, "Cannot connect") || flips != 2 {
		t.Fatalf("snapshot %+v flips %d", s, flips)
	}

	var tr ReconcileTracker
	tr.Pass(at, nil)
	tr.Pass(at.Add(time.Minute), errors.Join(
		fmt.Errorf("docker: create volume: %w", errors.New("no space left on device")),
		fmt.Errorf("start wl_01J9Z8X7W6V5T4S3R2Q1P0N9MK: %w", docker.ErrGuardRefused),
		fmt.Errorf("attach network net_01J9Z8X7W6V5T4S3R2Q1P0N9MK: boom"),
		docker.ErrNotIsolated,
		errors.New("something else"),
	))
	rs := tr.Snapshot()
	if !rs.LastPassAt.Equal(at.Add(time.Minute)) || rs.LastCompleteAt == nil || !rs.LastCompleteAt.Equal(at) || len(rs.Errors) != 5 {
		t.Fatalf("reconcile %+v", rs)
	}
	want := []state.ReconcileErrorCode{state.ReconcileDiskFull, state.ReconcileGuardRefused, state.ReconcileNetwork, state.ReconcileNotIsolated, state.ReconcileOther}
	for i, e := range rs.Errors {
		if e.Code != want[i] {
			t.Errorf("error %d: %s, want %s", i, e.Code, want[i])
		}
	}
	if rs.Errors[1].Target != "wl_01J9Z8X7W6V5T4S3R2Q1P0N9MK" || rs.Errors[2].Target != "net_01J9Z8X7W6V5T4S3R2Q1P0N9MK" {
		t.Fatalf("targets %+v", rs.Errors)
	}
	validHost(t, &state.HostStatus{CPUs: 1, DaemonVersion: "1.0.0", Docker: d.Snapshot(), Reconcile: rs})
}

type fakeDF struct {
	res mobyclient.DiskUsageResult
	err error
}

func (f fakeDF) DiskUsage(context.Context, mobyclient.DiskUsageOptions) (mobyclient.DiskUsageResult, error) {
	return f.res, f.err
}

// G18: per-volume sizes of this server's managed volumes, retired ones
// marked, WAL backlog.
func TestDiskBreakdown(t *testing.T) {
	vol := func(id, server string, size int64) volume.Volume {
		return volume.Volume{
			Name:      names.VolumePrefix + id,
			Labels:    map[string]string{names.LabelManaged: "true", names.LabelServer: server, names.LabelVolume: id},
			UsageData: &volume.UsageData{Size: size, RefCount: 1},
		}
	}
	df := fakeDF{res: mobyclient.DiskUsageResult{Volumes: mobyclient.VolumesDiskUsage{Items: []volume.Volume{
		vol("vol_01J9Z8X7W6V5T4S3R2Q1P0N9AA", testServer, 3<<30),
		vol("vol_01J9Z8X7W6V5T4S3R2Q1P0N9BB", testServer, 5<<20),
		vol("vol_01J9Z8X7W6V5T4S3R2Q1P0N9CC", "srv_01J9Z8X7W6V5T4S3R2Q1P0N9ZZ", 1<<30),
		vol("caddy-data", testServer, 1<<20),
		{Name: "foreign", Labels: map[string]string{}},
	}}}}
	at := time.Date(2026, 11, 17, 10, 0, 0, 0, time.UTC)
	d := DiskBreakdown(context.Background(), df, testServer, DiskBreakdownInput{
		Declared: []state.VolumeID{"vol_01J9Z8X7W6V5T4S3R2Q1P0N9AA"}, WalPendingSegments: 3, Now: at,
	})
	if d == nil || len(d.Volumes) != 2 || d.WalBacklogMB == nil || *d.WalBacklogMB != 48 {
		t.Fatalf("breakdown %+v", d)
	}
	if d.Volumes[0] != (state.VolumeUsage{ID: "vol_01J9Z8X7W6V5T4S3R2Q1P0N9AA", SizeMB: 3072}) ||
		d.Volumes[1] != (state.VolumeUsage{ID: "vol_01J9Z8X7W6V5T4S3R2Q1P0N9BB", SizeMB: 5, Retired: true}) {
		t.Fatalf("volumes %+v", d.Volumes)
	}
	validHost(t, &state.HostStatus{CPUs: 1, DaemonVersion: "1.0.0", Health: &state.HostHealth{CollectedAt: at, Disk: d}})

	if DiskBreakdown(context.Background(), fakeDF{err: errors.New("down")}, testServer, DiskBreakdownInput{Now: at}) != nil {
		t.Fatal("breakdown without Docker")
	}
	if d := DiskBreakdown(context.Background(), df, testServer, DiskBreakdownInput{WalPendingSegments: -1, Now: at}); d.WalBacklogMB != nil {
		t.Fatal("WAL backlog without archives")
	}
}

func TestFilesystemInodes(t *testing.T) {
	fs, ok := Filesystem(t.TempDir())
	if !ok {
		t.Skip("statfs not available here")
	}
	if fs.InodesTotal > 0 && (fs.InodesFree <= 0 || fs.InodesFree > fs.InodesTotal) {
		t.Fatalf("inodes %+v", fs)
	}
}
