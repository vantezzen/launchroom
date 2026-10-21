package logship

import (
	"context"
	"errors"
	"strings"
	"sync"
	"testing"
	"time"

	"github.com/moby/moby/api/types/container"

	"github.com/vantezzen/launchroom/daemon/internal/docker"
	"github.com/vantezzen/launchroom/daemon/internal/docker/dockerfake"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

const (
	srvID = "srv_01J9Z8X7W6V5T4S3R2Q1P0N9M8"
	wl    = state.WorkloadID("wl_01J9Z8X7W6V5T4S3R2Q1P0N9N1")
)

type fakeCP struct {
	mu      sync.Mutex
	batches []*state.LogBatch
	fail    int
}

func (f *fakeCP) ShipLogs(_ context.Context, b *state.LogBatch) error {
	if err := b.Validate(); err != nil {
		return err
	}
	f.mu.Lock()
	defer f.mu.Unlock()
	if f.fail > 0 {
		f.fail--
		return errors.New("503 unavailable")
	}
	f.batches = append(f.batches, b)
	return nil
}

func (f *fakeCP) lines() []state.LogLine {
	f.mu.Lock()
	defer f.mu.Unlock()
	var out []state.LogLine
	for _, b := range f.batches {
		for _, c := range b.Chunks {
			if c.Source.Kind != state.LogSourceWorkload || c.Source.ID != string(wl) {
				panic("wrong source")
			}
			out = append(out, c.Lines...)
		}
	}
	return out
}

func (f *fakeCP) seqs() []uint64 {
	f.mu.Lock()
	defer f.mu.Unlock()
	var out []uint64
	for _, b := range f.batches {
		out = append(out, b.Chunks[0].Seq)
	}
	return out
}

func running(t *testing.T, d *dockerfake.Fake) string {
	t.Helper()
	labels := docker.ManagedLabels(srvID)
	labels[docker.LabelWorkload] = string(wl)
	d.AddImage("img")
	id, err := d.CreateContainer(t.Context(), &docker.ContainerCreate{Name: "lr-x", Config: &container.Config{Image: "img", Labels: labels}})
	if err != nil {
		t.Fatal(err)
	}
	if err := d.StartContainer(t.Context(), id); err != nil {
		t.Fatal(err)
	}
	return id
}

func nodeState(ship bool) *state.NodeState {
	return &state.NodeState{Workloads: []state.WorkloadSpec{{ID: wl, ShipLogs: ship}}}
}

func waitFor(t *testing.T, what string, ok func() bool) {
	t.Helper()
	deadline := time.Now().Add(5 * time.Second)
	for !ok() {
		if time.Now().After(deadline) {
			t.Fatalf("timed out waiting for %s", what)
		}
		time.Sleep(10 * time.Millisecond)
	}
}

func TestShipsContinuouslyAndResumesFromCursor(t *testing.T) {
	d := dockerfake.New(srvID)
	id := running(t, d)
	t0 := time.Now().Add(-time.Minute).UTC()
	for i := range 1200 {
		d.Logs[id] = append(d.Logs[id], docker.LogLine{Stream: "stdout", Time: t0.Add(time.Duration(i) * time.Millisecond), Text: "line"})
	}
	d.Logs[id] = append(d.Logs[id], docker.LogLine{Stream: "stderr", Time: t0.Add(2 * time.Second), Text: "bad \xff " + strings.Repeat("x", 20000)})

	dir := t.TempDir()
	cp := &fakeCP{}
	m := New(Options{Docker: d, Client: cp, Dir: dir, FlushEvery: 20 * time.Millisecond})
	m.Sync(t.Context(), nodeState(true))
	waitFor(t, "all lines", func() bool { return len(cp.lines()) == 1201 })
	last := cp.lines()[1200]
	if last.Stream != state.StreamStderr || len(last.Text) > maxLineText {
		t.Fatalf("last line %v %d", last.Stream, len(last.Text))
	}
	seqs := cp.seqs()
	for i := 1; i < len(seqs); i++ {
		if seqs[i] <= seqs[i-1] {
			t.Fatalf("seqs not increasing: %v", seqs)
		}
	}
	m.Stop()

	// A restarted daemon with the same cursor ships only new lines.
	d.Logs[id] = append(d.Logs[id], docker.LogLine{Stream: "stdout", Time: t0.Add(3 * time.Second), Text: "after restart"})
	cp2 := &fakeCP{}
	m2 := New(Options{Docker: d, Client: cp2, Dir: dir, FlushEvery: 20 * time.Millisecond})
	m2.Sync(t.Context(), nodeState(true))
	waitFor(t, "the new line", func() bool { return len(cp2.lines()) == 1 })
	if got := cp2.lines()[0].Text; got != "after restart" {
		t.Fatalf("got %q", got)
	}
	if cp2.seqs()[0] <= seqs[len(seqs)-1] {
		t.Fatal("seq went backwards across a restart")
	}

	// Dropping shipLogs stops the follower and forgets the cursor.
	m2.Sync(t.Context(), nodeState(false))
	if len(m2.Following()) != 0 {
		t.Fatal("still following")
	}
	m2.Stop()
}

func TestRateLimitAndRetry(t *testing.T) {
	d := dockerfake.New(srvID)
	id := running(t, d)
	t0 := time.Now().UTC()
	for i := range 50 {
		d.Logs[id] = append(d.Logs[id], docker.LogLine{Stream: "stdout", Time: t0.Add(time.Duration(i) * time.Microsecond), Text: "x"})
	}
	cp := &fakeCP{fail: 1}
	m := New(Options{Docker: d, Client: cp, Dir: t.TempDir(), FlushEvery: 10 * time.Millisecond, Rate: 1, Burst: 10})
	m.Sync(t.Context(), nodeState(true))
	// The 50 lines arrive at once: 10 pass the bucket, 40 are counted. The
	// first shipment fails; the lines stay buffered and go out on the retry
	// (2 s later), followed by the notice.
	waitFor(t, "lines after the retry", func() bool { return len(cp.lines()) >= 11 })
	lines := cp.lines()
	if n := len(lines); n != 11 || lines[10].Stream != state.StreamSystem || !strings.Contains(lines[10].Text, "40 log lines were dropped") {
		t.Fatalf("%d lines, last %+v", n, lines[len(lines)-1])
	}
	m.Stop()
}
