package docker

import (
	"bytes"
	"encoding/binary"
	"encoding/json"
	"errors"
	"fmt"
	"net/http"
	"net/http/httptest"
	"strings"
	"sync"
	"testing"
	"time"
)

func frame(stream byte, s string) []byte {
	h := make([]byte, 8)
	h[0] = stream
	binary.BigEndian.PutUint32(h[4:], uint32(len(s))) //nolint:gosec // test sizes are small
	return append(h, s...)
}

func TestDemux(t *testing.T) {
	var buf bytes.Buffer
	buf.Write(frame(1, "2026-10-17T12:00:00.5Z hello\n2026-10-17T12:00:01Z wor"))
	buf.Write(frame(2, "2026-10-17T12:00:02Z oops\r\n"))
	buf.Write(frame(1, "ld\nno-timestamp tail"))
	var got []LogLine
	if err := Demux(&buf, func(l LogLine) error { got = append(got, l); return nil }); err != nil {
		t.Fatal(err)
	}
	want := []string{"stdout|hello", "stderr|oops", "stdout|world", "stdout|no-timestamp tail"}
	if len(got) != len(want) {
		t.Fatalf("%+v", got)
	}
	for i, l := range got {
		if l.Stream+"|"+l.Text != want[i] {
			t.Errorf("%d: %+v", i, l)
		}
	}
	if !got[0].Time.Equal(time.Date(2026, 10, 17, 12, 0, 0, 5e8, time.UTC)) {
		t.Errorf("time %v", got[0].Time)
	}
	if err := Demux(bytes.NewReader(frame(7, "x")), func(LogLine) error { return nil }); err == nil {
		t.Error("bad stream type accepted")
	}
}

// fakeDaemon answers the few Engine API calls the ownership checks need.
type fakeDaemon struct {
	mu    sync.Mutex
	paths []string
}

func (f *fakeDaemon) ServeHTTP(w http.ResponseWriter, r *http.Request) {
	f.mu.Lock()
	f.paths = append(f.paths, r.Method+" "+r.URL.Path)
	f.mu.Unlock()
	w.Header().Set("Content-Type", "application/json")
	switch {
	case strings.HasSuffix(r.URL.Path, "/_ping"):
		w.Header().Set("Api-Version", "1.51")
		_, _ = w.Write([]byte("OK"))
	case strings.HasSuffix(r.URL.Path, "/containers/foreign/json"):
		_, _ = w.Write([]byte(`{"Id":"foreign","Name":"/someone","Config":{"Labels":{"app":"x"}},"State":{"Status":"running","Running":true}}`))
	case strings.HasSuffix(r.URL.Path, "/containers/ours/json"):
		_, _ = w.Write([]byte(`{"Id":"ours","Name":"/lr-x","Config":{"Labels":{"launchroom.managed":"true","launchroom.server":"srv_X"}},"State":{"Status":"running","Running":true}}`))
	case strings.HasSuffix(r.URL.Path, "/stop"):
		w.WriteHeader(http.StatusNoContent)
	case strings.HasSuffix(r.URL.Path, "/volumes/create"):
		var body struct{ Name string }
		_ = json.NewDecoder(r.Body).Decode(&body)
		if body.Name == "lr-v_ours" {
			// An existing volume answered from Docker's cache without labels.
			_, _ = fmt.Fprintf(w, `{"Name":%q}`, body.Name)
			return
		}
		_, _ = fmt.Fprintf(w, `{"Name":%q,"Labels":{"someone":"else"}}`, body.Name)
	case strings.HasSuffix(r.URL.Path, "/volumes/lr-v_ours"):
		_, _ = w.Write([]byte(`{"Name":"lr-v_ours","Labels":{"launchroom.managed":"true","launchroom.server":"srv_X"}}`))
	case strings.HasSuffix(r.URL.Path, "/volumes/lr-v_a"):
		_, _ = w.Write([]byte(`{"Name":"lr-v_a","Labels":{"someone":"else"}}`))
	default:
		w.WriteHeader(http.StatusNotFound)
		_, _ = w.Write([]byte(`{"message":"not found"}`))
	}
}

func (f *fakeDaemon) calls() []string {
	f.mu.Lock()
	defer f.mu.Unlock()
	return append([]string(nil), f.paths...)
}

func TestEngineOwnership(t *testing.T) {
	d := &fakeDaemon{}
	srv := httptest.NewServer(d)
	defer srv.Close()
	e, err := NewEngine(EngineOptions{ServerID: "srv_X", Host: "tcp://" + strings.TrimPrefix(srv.URL, "http://")})
	if err != nil {
		t.Fatal(err)
	}
	defer func() { _ = e.Close() }()
	ctx := t.Context()

	if err := e.StopContainer(ctx, "foreign", time.Second); !errors.Is(err, ErrNotManaged) {
		t.Fatalf("stop foreign: %v", err)
	}
	if err := e.RemoveContainer(ctx, "foreign"); !errors.Is(err, ErrNotManaged) {
		t.Fatalf("remove foreign: %v", err)
	}
	for _, c := range d.calls() {
		if strings.Contains(c, "foreign/stop") || strings.HasPrefix(c, "DELETE") {
			t.Fatalf("mutating call on a foreign container: %s", c)
		}
	}
	if err := e.StopContainer(ctx, "ours", time.Second); err != nil {
		t.Fatalf("stop ours: %v", err)
	}
	if _, err := e.InspectContainer(ctx, "missing"); !errors.Is(err, ErrNotFound) {
		t.Fatalf("missing: %v", err)
	}
	// Docker returns an existing, foreign volume on create: never adopt it.
	if err := e.CreateVolume(ctx, VolumeCreate{Name: "lr-v_a", Labels: ManagedLabels("srv_X")}); !errors.Is(err, ErrNotManaged) {
		t.Fatalf("volume: %v", err)
	}
	// Our own existing volume answered without labels: the stored labels decide.
	if err := e.CreateVolume(ctx, VolumeCreate{Name: "lr-v_ours", Labels: ManagedLabels("srv_X")}); err != nil {
		t.Fatalf("own existing volume: %v", err)
	}
	// Unlabelled creates are refused client-side.
	if err := e.CreateVolume(ctx, VolumeCreate{Name: "lr-v_b"}); !errors.Is(err, ErrNotManaged) {
		t.Fatalf("unlabelled volume: %v", err)
	}
	if err := e.PullImage(ctx, "docker.io/library/caddy:2"); err == nil {
		t.Fatal("tag pull accepted")
	}
}
