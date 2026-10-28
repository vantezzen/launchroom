package caddy

import (
	"context"
	"errors"
	"fmt"
	"io"
	"net"
	"net/http"
	"net/http/httptest"
	"testing"
	"time"
)

// Only a 4xx from Caddy is a refusal of the config; everything else (no
// connection, timeouts, 5xx while starting) is retried (docs/22 §6.6).
func TestIsConfigRefusal(t *testing.T) {
	for _, tc := range []struct {
		name string
		err  error
		want bool
	}{
		{"nil", nil, false},
		{"400 provision error", &AdminStatusError{Method: "POST", Path: "/load", Status: 400, Body: `{"error":"loading config: provision http.handlers.waf: invalid"}`}, true},
		{"422", &AdminStatusError{Status: 422}, true},
		{"wrapped 400", fmt.Errorf("load: %w", &AdminStatusError{Status: 400}), true},
		{"408", &AdminStatusError{Status: http.StatusRequestTimeout}, false},
		{"429", &AdminStatusError{Status: http.StatusTooManyRequests}, false},
		{"500", &AdminStatusError{Status: 500}, false},
		{"503", &AdminStatusError{Status: 503}, false},
		{"connection refused", fmt.Errorf("caddy admin: POST /load: %w", &net.OpError{Op: "dial", Net: "tcp", Err: errors.New("connect: connection refused")}), false},
		{"deadline", context.DeadlineExceeded, false},
		{"other", errors.New("boom"), false},
	} {
		if got := IsConfigRefusal(tc.err); got != tc.want {
			t.Errorf("%s: IsConfigRefusal = %v, want %v", tc.name, got, tc.want)
		}
	}
}

// The real client classifies what it gets from the wire.
func TestAdminClientErrors(t *testing.T) {
	ctx := t.Context()
	status := func(code int) *AdminClient {
		srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
			w.WriteHeader(code)
			_, _ = w.Write([]byte(`{"error":"loading config: provision http.handlers.waf: bad"}`))
		}))
		t.Cleanup(srv.Close)
		return NewAdminClient(srv.Listener.Addr().String())
	}

	err := status(http.StatusBadRequest).Load(ctx, []byte(`{}`))
	if !IsConfigRefusal(err) || err.Error() != `caddy admin: POST /load: HTTP 400: {"error":"loading config: provision http.handlers.waf: bad"}` {
		t.Fatalf("400: %v", err)
	}
	if err := status(http.StatusServiceUnavailable).Load(ctx, []byte(`{}`)); err == nil || IsConfigRefusal(err) {
		t.Fatalf("503: %v", err)
	}
	if err := status(http.StatusOK).Load(ctx, []byte(`{}`)); err != nil {
		t.Fatalf("200: %v", err)
	}

	// A port nobody listens on: connection refused.
	ln, err := (&net.ListenConfig{}).Listen(ctx, "tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	addr := ln.Addr().String()
	_ = ln.Close()
	if err := NewAdminClient(addr).Load(ctx, []byte(`{}`)); err == nil || IsConfigRefusal(err) {
		t.Fatalf("refused connection: %v", err)
	}

	// A server that doesn't answer in time.
	slow := httptest.NewServer(http.HandlerFunc(func(_ http.ResponseWriter, r *http.Request) {
		_, _ = io.Copy(io.Discard, r.Body) // so the server notices the client hanging up
		select {
		case <-r.Context().Done():
		case <-time.After(5 * time.Second):
		}
	}))
	t.Cleanup(slow.Close)
	c := NewAdminClient(slow.Listener.Addr().String())
	c.timeout = 50 * time.Millisecond
	if err := c.Load(ctx, []byte(`{}`)); err == nil || IsConfigRefusal(err) {
		t.Fatalf("timeout: %v", err)
	}
}
