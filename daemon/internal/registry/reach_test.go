package registry

import (
	"net"
	"net/http"
	"net/http/httptest"
	"testing"
	"time"
)

func TestReachCheck(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		w.WriteHeader(http.StatusUnauthorized) // Zot without a token: reachable
	}))
	defer srv.Close()
	c := NewReachChecker(time.Now, func() {})
	c.url = srv.URL + "/v2/"
	if r := c.check(t.Context()); !r.Reachable || r.Error != "" || r.CheckedAt.IsZero() {
		t.Fatalf("reachable registry: %+v", r)
	}

	// A listener that accepts and never answers (a stalled private network
	// path): unreachable after the timeout, with a reason.
	l, err := (&net.ListenConfig{}).Listen(t.Context(), "tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	defer func() { _ = l.Close() }()
	go func() {
		for {
			conn, err := l.Accept()
			if err != nil {
				return
			}
			defer func() { _ = conn.Close() }()
		}
	}()
	c.url = "http://" + l.Addr().String() + "/v2/"
	c.client.Timeout = 200 * time.Millisecond
	if r := c.check(t.Context()); r.Reachable || r.Error != "no answer within 15 seconds" {
		t.Fatalf("stalled registry: %+v", r)
	}
}

// Errors are classified by type, not by their text.
func TestReachErrors(t *testing.T) {
	closed, err := (&net.ListenConfig{}).Listen(t.Context(), "tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	addr := closed.Addr().String()
	_ = closed.Close()
	c := NewReachChecker(time.Now, func() {})
	c.url = "http://" + addr + "/v2/"
	if r := c.check(t.Context()); r.Error != "connection refused" {
		t.Fatalf("refused: %+v", r)
	}

	hangup, err := (&net.ListenConfig{}).Listen(t.Context(), "tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	defer func() { _ = hangup.Close() }()
	go func() {
		for {
			conn, err := hangup.Accept()
			if err != nil {
				return
			}
			_ = conn.Close()
		}
	}()
	c.url = "http://" + hangup.Addr().String() + "/v2/"
	for range 20 {
		if r := c.check(t.Context()); r.Error != "the connection was closed (the main server's registry is not reachable from here)" {
			t.Fatalf("closed: %+v", r)
		}
	}
}
