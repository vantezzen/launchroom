package watchdog

import (
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"
)

// G14: the self-watchdog beats while work ends in time, and writes a stall
// note and exits when a unit of work runs past its deadline.
func TestSelfWatch(t *testing.T) {
	file := filepath.Join(t.TempDir(), "heartbeat")
	clock := time.Date(2026, 11, 17, 10, 0, 0, 0, time.UTC)
	var note []byte
	exited := -1
	w := NewSelfWatch(SelfWatchOptions{
		File: file, Now: func() time.Time { return clock },
		OnStall: func(n []byte) { note = n }, Exit: func(code int) { exited = code },
	})
	end := w.Begin("reconcile pass", 10*time.Minute)
	clock = clock.Add(5 * time.Minute)
	if stuck := w.Check(); stuck != "" || exited != -1 {
		t.Fatalf("stuck %q exited %d", stuck, exited)
	}
	if err := Healthy(file, HeartbeatMaxAge, clock); err != nil {
		t.Fatal(err)
	}
	end()
	w.Begin("report", time.Minute)
	clock = clock.Add(2 * time.Minute)
	if stuck := w.Check(); stuck != "report" || exited != StallExitCode {
		t.Fatalf("stuck %q exited %d", stuck, exited)
	}
	if !strings.HasPrefix(string(note), "launchroomd stalled: report made no progress for 2m0s") || !strings.Contains(string(note), "goroutine") {
		t.Fatalf("note %.200q", note)
	}
	if err := Healthy(file, time.Minute, clock); err == nil || !strings.Contains(err.Error(), "stale") {
		t.Fatalf("heartbeat after a stall: %v", err)
	}
	var none *SelfWatch
	none.Begin("x", time.Second)()
	if err := Healthy(filepath.Join(t.TempDir(), "missing"), time.Minute, clock); err == nil {
		t.Fatal("missing heartbeat is healthy")
	}
	_ = os.Remove(file)
}
