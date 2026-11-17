package session

import (
	"context"
	"net/http"
	"net/http/httptest"
	"strings"
	"sync/atomic"
	"testing"
	"time"

	"github.com/coder/websocket"

	"github.com/vantezzen/launchroom/daemon/internal/pusher"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// blockingEngine answers every command only when its context ends, and
// counts how many it was asked to run.
type blockingEngine struct {
	sqlBase
	ran atomic.Int32
}

func (e *blockingEngine) wait(ctx context.Context) *Result {
	e.ran.Add(1)
	<-ctx.Done()
	return failed("cancelled")
}

func (e *blockingEngine) schema(ctx context.Context) *Result { return e.wait(ctx) }
func (e *blockingEngine) rows(ctx context.Context, _ state.TableRef, _, _ int) *Result {
	return e.wait(ctx)
}
func (e *blockingEngine) query(ctx context.Context, _ string, _ bool) *Result { return e.wait(ctx) }
func (e *blockingEngine) key(ctx context.Context, _ string) *Result           { return e.wait(ctx) }

// sinkRelay is a relay connected to a WebSocket server that reads and
// drops everything.
func sinkRelay(t *testing.T) *Relay {
	t.Helper()
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		c, err := websocket.Accept(w, r, nil)
		if err != nil {
			return
		}
		defer func() { _ = c.CloseNow() }()
		for {
			if _, _, err := c.Read(r.Context()); err != nil {
				return
			}
		}
	}))
	t.Cleanup(srv.Close)
	conn, err := pusher.Dial(t.Context(), "ws"+strings.TrimPrefix(srv.URL, "http"), nil, nil)
	if err != nil {
		t.Fatal(err)
	}
	r := &Relay{conn: conn, channel: "private-session." + string(sid), events: make(chan Event), done: make(chan struct{}), pong: make(chan struct{}, 1)}
	t.Cleanup(r.Close)
	return r
}

// Ending a data session stops its running command and skips the queued
// ones: the task completes at once instead of after every command (each up
// to commandTimeout) has run against the database.
func TestDataSessionEndCancelsRunningAndQueuedCommands(t *testing.T) {
	eng := &blockingEngine{}
	o := Options{}
	o.defaults()
	s := &run{
		o: o, spec: &state.SessionSpec{Session: sid, Kind: state.SessionData}, res: &state.SessionResult{},
		codec: NewCodec(make([]byte, 32), sid, true), relay: sinkRelay(t), log: o.Log,
		engine: &engineRunner{engine: state.EnginePostgres, impl: eng},
	}
	s.activity = make(chan struct{}, 1)
	if err := s.start(t.Context(), Payload{T: THello}); err != nil {
		t.Fatal(err)
	}
	for i := range 4 {
		s.commands <- &state.SessionCommand{Op: state.OpID("op_01J9Z8X7W6V5T4S3R2Q1P0N9Q" + string(rune('0'+i))), Command: state.CommandSchema}
	}
	deadline := time.Now().Add(5 * time.Second)
	for eng.ran.Load() == 0 && time.Now().Before(deadline) {
		time.Sleep(5 * time.Millisecond)
	}

	done := make(chan struct{})
	go func() { s.cleanup(); close(done) }()
	select {
	case <-done:
	case <-time.After(5 * time.Second):
		t.Fatal("cleanup waited for the running and queued commands")
	}
	if got := eng.ran.Load(); got != 1 {
		t.Fatalf("ran %d commands, want only the one already running", got)
	}
}
