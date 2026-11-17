package logship_test

import (
	"context"
	"errors"
	"strings"
	"testing"
	"time"

	"github.com/moby/moby/api/types/container"

	"github.com/vantezzen/launchroom/daemon/internal/docker"
	"github.com/vantezzen/launchroom/daemon/internal/docker/dockerfake"
	"github.com/vantezzen/launchroom/daemon/internal/logship"
	"github.com/vantezzen/launchroom/daemon/internal/names"
	"github.com/vantezzen/launchroom/daemon/internal/state"
	"github.com/vantezzen/launchroom/daemon/internal/tasks/taskstest"
)

const (
	srvID = "srv_01J9Z8X7W6V5T4S3R2Q1P0N9MK"
	wl    = state.WorkloadID("wl_01J9Z8X7W6V5T4S3R2Q1P0N9N7")
)

func configWith(labels map[string]string) *container.Config {
	return &container.Config{Image: "img", Labels: labels}
}

func TestTaskHandler(t *testing.T) {
	ctx := t.Context()
	d := dockerfake.New(srvID)
	d.AddImage("img")
	labels := docker.ManagedLabels(srvID)
	labels[names.LabelWorkload] = string(wl)
	cc := &docker.ContainerCreate{Name: "lr-x"}
	cc.Config = configWith(labels)
	id, err := d.CreateContainer(ctx, cc)
	if err != nil {
		t.Fatal(err)
	}
	t0 := time.Date(2026, 10, 17, 12, 0, 0, 0, time.UTC)
	for i := range 1203 {
		d.Logs[id] = append(d.Logs[id], docker.LogLine{Stream: "stdout", Time: t0.Add(time.Duration(i) * time.Second), Text: "line"})
	}
	d.Logs[id] = append(d.Logs[id], docker.LogLine{Stream: "stderr", Time: t0.Add(time.Hour), Text: "bad \xff utf8 " + strings.Repeat("x", 20000)})

	cp := &taskstest.ControlPlane{}
	h := logship.TaskHandler(d, cp)
	tk := taskstest.Task("tsk_01J9Z8X7W6V5T4S3R2Q1P0N9N6", state.TaskLogs, &state.LogsSpec{Workload: wl, Tail: 1100}, time.Minute)
	if _, err := h(ctx, tk); err != nil {
		t.Fatal(err)
	}
	total := 0
	for i, b := range cp.Batches {
		c := b.Chunks[0]
		if c.Seq != uint64(i+1) || c.Source.Kind != state.LogSourceTask || c.Source.ID != string(tk.ID) {
			t.Fatalf("chunk %d: %+v", i, c.Source)
		}
		total += len(c.Lines)
	}
	if total != 1100 || len(cp.Batches) != 3 {
		t.Fatalf("shipped %d lines in %d batches", total, len(cp.Batches))
	}
	last := cp.Batches[2].Chunks[0].Lines
	if l := last[len(last)-1]; l.Stream != state.StreamStderr || len(l.Text) > state.MaxLogLineLen {
		t.Fatalf("%v %d", l.Stream, len(l.Text))
	}

	// Unknown workload fails cleanly.
	tk.Spec = &state.LogsSpec{Workload: "wl_01J9Z8X7W6V5T4S3R2Q1P0N9NZ"}
	if _, err := h(ctx, tk); err == nil || !strings.Contains(err.Error(), "no container") {
		t.Fatalf("err = %v", err)
	}
}

// TestLogsFollowShipsLive: a following tail ships its history (blank lines
// included) within a second or two while the task is still running, not
// only when it ends.
func TestLogsFollowShipsLive(t *testing.T) {
	d := dockerfake.New(srvID)
	d.AddImage("img")
	labels := docker.ManagedLabels(srvID)
	labels[names.LabelWorkload] = string(wl)
	id, _ := d.CreateContainer(t.Context(), &docker.ContainerCreate{Name: "lr-z", Config: configWith(labels)})
	d.Logs[id] = []docker.LogLine{{Stream: "stdout", Text: ""}, {Stream: "stdout", Text: "> node server.js"}, {Stream: "stdout", Text: "listening on :3000"}}
	cp := &taskstest.ControlPlane{}
	ctx, cancel := context.WithTimeout(t.Context(), 30*time.Second)
	defer cancel()
	done := make(chan error, 1)
	go func() {
		_, err := logship.TaskHandler(d, cp)(ctx, taskstest.Task("tsk_01J9Z8X7W6V5T4S3R2Q1P0N9N8", state.TaskLogs, &state.LogsSpec{Workload: wl, Tail: 200, Follow: true}, time.Minute))
		done <- err
	}()
	deadline := time.Now().Add(3 * time.Second)
	for len(cp.ShippedLines()) < 3 {
		if time.Now().After(deadline) {
			t.Fatalf("follow shipped %d lines within 3s", len(cp.ShippedLines()))
		}
		time.Sleep(50 * time.Millisecond)
	}
	if l := cp.ShippedLines(); l[0].Text != "" || l[2].Text != "listening on :3000" {
		t.Fatalf("lines = %+v", l)
	}
	select {
	case err := <-done:
		t.Fatalf("follow ended early: %v", err)
	default:
	}
	cancel()
	if err := <-done; err != nil {
		t.Fatal(err)
	}
}

// TestLogsFollowFailsWhenShippingFails: a refused batch ends the tail with
// the error instead of dropping lines silently until the deadline.
func TestLogsFollowFailsWhenShippingFails(t *testing.T) {
	d := dockerfake.New(srvID)
	d.AddImage("img")
	labels := docker.ManagedLabels(srvID)
	labels[names.LabelWorkload] = string(wl)
	id, _ := d.CreateContainer(t.Context(), &docker.ContainerCreate{Name: "lr-w", Config: configWith(labels)})
	d.Logs[id] = []docker.LogLine{{Stream: "stdout", Text: "hello"}}
	cp := &taskstest.ControlPlane{ShipErr: errors.New("422 unprocessable")}
	ctx, cancel := context.WithTimeout(t.Context(), 30*time.Second)
	defer cancel()
	start := time.Now()
	_, err := logship.TaskHandler(d, cp)(ctx, taskstest.Task("tsk_01J9Z8X7W6V5T4S3R2Q1P0N9N9", state.TaskLogs, &state.LogsSpec{Workload: wl, Tail: 10, Follow: true}, time.Minute))
	if err == nil || !strings.Contains(err.Error(), "422") {
		t.Fatalf("err = %v", err)
	}
	if time.Since(start) > 5*time.Second {
		t.Fatalf("took %s to notice", time.Since(start))
	}
}

func TestLogsFollowEndsAtDeadline(t *testing.T) {
	d := dockerfake.New(srvID)
	d.AddImage("img")
	labels := docker.ManagedLabels(srvID)
	labels[names.LabelWorkload] = string(wl)
	id, _ := d.CreateContainer(t.Context(), &docker.ContainerCreate{Name: "lr-y", Config: configWith(labels)})
	d.Logs[id] = []docker.LogLine{{Stream: "stdout", Text: "hello"}}
	cp := &taskstest.ControlPlane{}
	ctx, cancel := context.WithTimeout(t.Context(), 1500*time.Millisecond)
	defer cancel()
	if _, err := logship.TaskHandler(d, cp)(ctx, taskstest.Task("tsk_01J9Z8X7W6V5T4S3R2Q1P0N9N7", state.TaskLogs, &state.LogsSpec{Workload: wl, Tail: 10, Follow: true}, time.Minute)); err != nil {
		t.Fatal(err)
	}
	if len(cp.Batches) != 1 {
		t.Fatalf("%d batches", len(cp.Batches))
	}
}

// G9: a following tail goes on across a restart of the container (a crash
// loop), with a system line at the exit and at the new start, and the new
// run's lines.
func TestLogsFollowAcrossRestarts(t *testing.T) {
	d := dockerfake.New(srvID)
	d.AddImage("img")
	labels := docker.ManagedLabels(srvID)
	labels[names.LabelWorkload] = string(wl)
	id, _ := d.CreateContainer(t.Context(), &docker.ContainerCreate{Name: "lr-r", Config: configWith(labels)})
	if err := d.StartContainer(t.Context(), id); err != nil {
		t.Fatal(err)
	}
	now := time.Now().UTC()
	d.Logs[id] = []docker.LogLine{{Stream: "stdout", Time: now.Add(-time.Minute), Text: "run 1"}}
	cp := &taskstest.ControlPlane{}
	ctx, cancel := context.WithTimeout(t.Context(), 30*time.Second)
	defer cancel()
	done := make(chan error, 1)
	go func() {
		_, err := logship.TaskHandler(d, cp)(ctx, taskstest.Task("tsk_01J9Z8X7W6V5T4S3R2Q1P0N9NA", state.TaskLogs, &state.LogsSpec{Workload: wl, Tail: 200, Follow: true}, time.Minute))
		done <- err
	}()
	waitLines := func(n int) []state.LogLine {
		t.Helper()
		deadline := time.Now().Add(5 * time.Second)
		for len(cp.ShippedLines()) < n {
			if time.Now().After(deadline) {
				t.Fatalf("shipped %+v, want %d lines", cp.ShippedLines(), n)
			}
			time.Sleep(50 * time.Millisecond)
		}
		return cp.ShippedLines()
	}
	waitLines(1)
	for d.Waiting(id) == 0 {
		time.Sleep(10 * time.Millisecond)
	}
	d.Exit(id, 1, false, true)
	time.Sleep(100 * time.Millisecond)
	d.Logs[id] = append(d.Logs[id], docker.LogLine{Stream: "stdout", Time: time.Now().UTC().Add(time.Hour), Text: "run 2"})
	d.Restarted(id)
	got := waitLines(4)
	want := []struct {
		stream state.LogStream
		text   string
	}{{state.StreamStdout, "run 1"}, {state.StreamSystem, "The container exited with code 1."}, {state.StreamSystem, "The container started again (restart 1)."}, {state.StreamStdout, "run 2"}}
	for i, w := range want {
		if got[i].Stream != w.stream || got[i].Text != w.text {
			t.Fatalf("line %d: %+v (all %+v)", i, got[i], got)
		}
	}
	cancel()
	if err := <-done; err != nil {
		t.Fatal(err)
	}
}
