package api

import (
	"context"
	"errors"
	"fmt"
	"net/http"
	"net/http/httptest"
	"strings"
	"sync"
	"sync/atomic"
	"testing"
	"time"

	"github.com/vantezzen/launchroom/cli/internal/exitcode"
	"github.com/vantezzen/launchroom/cli/internal/sse"
)

func newTestClient(srv *httptest.Server) *Client {
	c := New(srv.URL, "1|lr_pat_x", "launchroom-cli/test")
	c.Sleep = func(context.Context, time.Duration) error { return nil }
	return c
}

func TestErrorDecodingAndExitCodes(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.Header.Get("Authorization") != "Bearer 1|lr_pat_x" || !strings.HasPrefix(r.Header.Get("User-Agent"), "launchroom-cli/") {
			t.Errorf("headers: %v", r.Header)
		}
		w.Header().Set("Content-Type", "application/json")
		w.WriteHeader(http.StatusForbidden)
		_, _ = w.Write([]byte(`{"error":{"code":"missing_ability","message":"This token can't deploy.","ability":"deploy"}}`))
	}))
	defer srv.Close()
	_, err := newTestClient(srv).Me(context.Background())
	var apiErr *Error
	if !errors.As(err, &apiErr) || apiErr.Code != "missing_ability" || apiErr.Ability != "deploy" || apiErr.Status != 403 {
		t.Fatalf("err = %#v", err)
	}
	if exitcode.Of(err) != exitcode.NotAllowed {
		t.Fatalf("exit code = %d", exitcode.Of(err))
	}
	if !strings.Contains(err.Error(), `"deploy" ability`) {
		t.Fatalf("message = %s", err)
	}
}

func TestNonJSONError(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		w.WriteHeader(http.StatusBadGateway)
		_, _ = w.Write([]byte("<html>bad gateway</html>"))
	}))
	defer srv.Close()
	_, err := newTestClient(srv).Me(context.Background())
	if exitcode.Of(err) != exitcode.Error || !strings.Contains(err.Error(), "502") {
		t.Fatalf("err = %v (%d)", err, exitcode.Of(err))
	}
}

func TestGetRetriesOnceAfter429(t *testing.T) {
	var calls atomic.Int32
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		if calls.Add(1) == 1 {
			w.Header().Set("Retry-After", "1")
			w.WriteHeader(http.StatusTooManyRequests)
			_, _ = w.Write([]byte(`{"error":{"code":"rate_limited","message":"slow down"}}`))
			return
		}
		_, _ = w.Write([]byte(`{"data":{"user":{"name":"A","email":"a@x"},"team":{"id":"team_1","name":"T"},"token":{"id":"tok_1","name":"n","abilities":["read"],"expiresAt":"2030-01-01T00:00:00Z"}}}`))
	}))
	defer srv.Close()
	me, err := newTestClient(srv).Me(context.Background())
	if err != nil || me.User.Email != "a@x" || calls.Load() != 2 {
		t.Fatalf("me=%+v err=%v calls=%d", me, err, calls.Load())
	}
}

func TestPostReusesIdempotencyKeyAfterNetworkError(t *testing.T) {
	var keys []string
	var mu sync.Mutex
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		mu.Lock()
		keys = append(keys, r.Header.Get("Idempotency-Key"))
		n := len(keys)
		mu.Unlock()
		if n == 1 {
			// Drop the connection without an answer.
			hj, _ := w.(http.Hijacker)
			conn, _, _ := hj.Hijack()
			_ = conn.Close()
			return
		}
		w.WriteHeader(http.StatusAccepted)
		_, _ = w.Write([]byte(`{"data":{"id":"dpl_1","status":"queued","finished":false}}`))
	}))
	defer srv.Close()
	d, err := newTestClient(srv).CreateDeployment(context.Background(), "env_1", DeployRequest{Service: "svc_1"})
	if err != nil || d.ID != "dpl_1" {
		t.Fatalf("d=%+v err=%v", d, err)
	}
	if len(keys) != 2 || keys[0] == "" || keys[0] != keys[1] {
		t.Fatalf("idempotency keys = %v", keys)
	}
}

func TestPostWithoutKeyIsNotRetried(t *testing.T) {
	var calls atomic.Int32
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		calls.Add(1)
		hj, _ := w.(http.Hijacker)
		conn, _, _ := hj.Hijack()
		_ = conn.Close()
	}))
	defer srv.Close()
	_, err := newTestClient(srv).PollLogin(context.Background(), "dc")
	if err == nil || calls.Load() != 1 || exitcode.Of(err) != exitcode.Error {
		t.Fatalf("err=%v calls=%d", err, calls.Load())
	}
}

func TestRedirectsAreNotFollowed(t *testing.T) {
	other := httptest.NewServer(http.HandlerFunc(func(_ http.ResponseWriter, r *http.Request) {
		t.Errorf("the token followed a redirect: %v", r.Header.Get("Authorization"))
	}))
	defer other.Close()
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		http.Redirect(w, r, other.URL+"/steal", http.StatusFound)
	}))
	defer srv.Close()
	if _, err := newTestClient(srv).Me(context.Background()); err == nil {
		t.Fatal("a redirect must be an error")
	}
}

func TestStreamReconnectsWithLastEventID(t *testing.T) {
	var mu sync.Mutex
	var seen []string
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		mu.Lock()
		seen = append(seen, r.Header.Get("Last-Event-ID")+"|"+r.URL.Query().Get("after"))
		n := len(seen)
		mu.Unlock()
		w.Header().Set("Content-Type", "text/event-stream")
		switch n {
		case 1:
			_, _ = fmt.Fprint(w, ": ping\n\nevent: lines\nid: 5\ndata: {\"cursor\":5}\n\n")
			// connection closes: EOF → reconnect
		case 2:
			_, _ = fmt.Fprint(w, "event: lines\nid: 9\ndata: {\"cursor\":9}\n\nevent: reconnect\ndata: {}\n\n")
		default:
			_, _ = fmt.Fprint(w, "event: status\ndata: {\"status\":\"active\",\"finished\":true}\n\nevent: end\ndata: {}\n\n")
		}
	}))
	defer srv.Close()
	var events []string
	err := newTestClient(srv).Stream(context.Background(), "/deployments/dpl_1/logs/stream", nil, "", func(ev sse.Event) error {
		events = append(events, ev.Event+":"+ev.ID)
		return nil
	})
	if err != nil {
		t.Fatal(err)
	}
	if strings.Join(seen, ",") != "|,5|5,9|9" {
		t.Fatalf("reconnects = %v", seen)
	}
	if strings.Join(events, ",") != "lines:5,lines:9,status:,end:" {
		t.Fatalf("events = %v", events)
	}
}

func TestStreamHTTPErrorIsFinal(t *testing.T) {
	var calls atomic.Int32
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		calls.Add(1)
		w.WriteHeader(http.StatusNotFound)
		_, _ = w.Write([]byte(`{"error":{"code":"not_found","message":"no"}}`))
	}))
	defer srv.Close()
	err := newTestClient(srv).Stream(context.Background(), "/x", nil, "", func(sse.Event) error { return nil })
	if !IsStatus(err, 404) || calls.Load() != 1 {
		t.Fatalf("err=%v calls=%d", err, calls.Load())
	}
}

func TestAttachmentNameIsSanitised(t *testing.T) {
	for header, want := range map[string]string{
		`attachment; filename="postgres-20261021.dump"`: "postgres-20261021.dump",
		`attachment; filename="../../etc/passwd"`:       "passwd",
		`attachment; filename=".bashrc"`:                "bashrc",
		`attachment; filename="a\b|c?.tar.gz"`:          "b|c?.tar.gz",
		`attachment`:                                    "",
	} {
		if got := AttachmentName(header); got != strings.Map(func(r rune) rune {
			if strings.ContainsRune(`<>:"|?*`, r) {
				return -1
			}
			return r
		}, want) {
			t.Errorf("%s → %q, want %q", header, got, want)
		}
	}
}

func TestOpenDownloadRefusesForeignLinks(t *testing.T) {
	c := New("https://launchroom.example.com", "1|lr_pat_x", "ua")
	for _, link := range []string{"https://evil.example/api/v1/downloads/x", "https://launchroom.example.com/api/v1/downloads/x/../../me", "https://launchroom.example.com/other"} {
		if _, err := c.OpenDownload(context.Background(), link); err == nil {
			t.Errorf("%s was accepted", link)
		}
	}
}
