package docker

import (
	"context"
	"fmt"
	"net/http"
	"net/http/httptest"
	"strings"
	"sync"
	"testing"
	"time"
)

const (
	layerA = "sha256:aaaaaaaaaaaa0000000000000000000000000000000000000000000000000000"
	layerB = "sha256:bbbbbbbbbbbb0000000000000000000000000000000000000000000000000000"
)

func TestPullTracker(t *testing.T) {
	tr := newPullTracker(map[string]int64{layerA: 400, layerB: 600, "bogus": 5})
	if p := tr.progress(); p != (PullProgress{Stage: PullDownloading, Downloaded: 0, Total: 1000}) {
		t.Fatalf("from the manifest: %+v", p)
	}
	steps := []struct {
		id, status   string
		current, tot int64
		changed      bool
		want         PullProgress
	}{
		{"mirror/x", "Pulling from mirror/x", 0, 0, false, PullProgress{PullDownloading, 0, 1000}},
		{"aaaaaaaaaaaa", "Pulling fs layer", 0, 0, false, PullProgress{PullDownloading, 0, 1000}},
		{"aaaaaaaaaaaa", "Downloading", 100, 400, true, PullProgress{PullDownloading, 100, 1000}},
		{"bbbbbbbbbbbb", "Downloading", 300, 600, true, PullProgress{PullDownloading, 400, 1000}},
		// Progress never goes backwards within a layer.
		{"bbbbbbbbbbbb", "Downloading", 200, 600, false, PullProgress{PullDownloading, 400, 1000}},
		{"aaaaaaaaaaaa", "Download complete", 0, 0, true, PullProgress{PullDownloading, 700, 1000}},
		{"bbbbbbbbbbbb", "Extracting", 10, 600, true, PullProgress{PullExtracting, 1000, 1000}},
		{"aaaaaaaaaaaa", "Pull complete", 0, 0, true, PullProgress{PullExtracting, 1000, 1000}},
		{"bbbbbbbbbbbb", "Pull complete", 0, 0, true, PullProgress{PullDownloading, 1000, 1000}},
		{"", "Digest: sha256:…", 0, 0, false, PullProgress{PullDownloading, 1000, 1000}},
	}
	for i, s := range steps {
		if got := tr.observe(s.id, s.status, s.current, s.tot); got != s.changed {
			t.Errorf("%d %s: changed %v", i, s.status, got)
		}
		if p := tr.progress(); p != s.want {
			t.Errorf("%d %s: %+v, want %+v", i, s.status, p, s.want)
		}
	}

	// Without a manifest, sizes come from Docker's own totals.
	tr = newPullTracker(nil)
	tr.observe("cccccccccccc", "Downloading", 5, 50)
	tr.observe("dddddddddddd", "Already exists", 0, 0)
	if p := tr.progress(); p.Downloaded != 5 || p.Total != 50 {
		t.Fatalf("no manifest: %+v", p)
	}
}

// pullDaemon streams a pull: one message per entry of msgs, pausing pause
// before the entry at index pauseAt.
type pullDaemon struct {
	msgs    []string
	pauseAt int
	pause   time.Duration
}

func (d *pullDaemon) ServeHTTP(w http.ResponseWriter, r *http.Request) {
	switch {
	case strings.HasSuffix(r.URL.Path, "/_ping"):
		w.Header().Set("Api-Version", "1.51")
		_, _ = w.Write([]byte("OK"))
	case strings.HasSuffix(r.URL.Path, "/images/create"):
		w.Header().Set("Content-Type", "application/json")
		fl, _ := w.(http.Flusher)
		for i, m := range d.msgs {
			if i == d.pauseAt && d.pause > 0 {
				select {
				case <-time.After(d.pause):
				case <-r.Context().Done():
					return
				}
			}
			_, _ = fmt.Fprintln(w, m)
			if fl != nil {
				fl.Flush()
			}
		}
	default:
		w.WriteHeader(http.StatusNotFound)
	}
}

func pullEngine(t *testing.T, d http.Handler, opts EngineOptions) *Engine {
	t.Helper()
	srv := httptest.NewServer(d)
	t.Cleanup(srv.Close)
	opts.ServerID = "srv_X"
	opts.Host = "tcp://" + strings.TrimPrefix(srv.URL, "http://")
	e, err := NewEngine(opts)
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { _ = e.Close() })
	return e
}

const pullRef = "127.0.0.1:5000/mirror/ghcr.io/x/y@sha256:1111111111111111111111111111111111111111111111111111111111111111"

func TestEnginePullWarmsAndReports(t *testing.T) {
	d := &pullDaemon{msgs: []string{
		`{"status":"Pulling from mirror/ghcr.io/x/y","id":"sha256:1111"}`,
		`{"status":"Pulling fs layer","id":"aaaaaaaaaaaa"}`,
		`{"status":"Downloading","id":"aaaaaaaaaaaa","progressDetail":{"current":200,"total":400}}`,
		`{"status":"Download complete","id":"aaaaaaaaaaaa"}`,
		`{"status":"Already exists","id":"bbbbbbbbbbbb"}`,
		`{"status":"Pull complete","id":"aaaaaaaaaaaa"}`,
		`{"status":"Status: Downloaded newer image"}`,
	}}
	var warmed []string
	e := pullEngine(t, d, EngineOptions{Warm: func(_ context.Context, ref string) (map[string]int64, error) {
		warmed = append(warmed, ref)
		return map[string]int64{layerA: 400, layerB: 600}, nil
	}})
	var mu sync.Mutex
	var got []PullProgress
	ctx := WithPullProgress(t.Context(), func(p PullProgress) { mu.Lock(); got = append(got, p); mu.Unlock() })
	if err := e.PullImage(ctx, pullRef); err != nil {
		t.Fatal(err)
	}
	if len(warmed) != 1 || warmed[0] != pullRef {
		t.Fatalf("warmed %q", warmed)
	}
	mu.Lock()
	defer mu.Unlock()
	if len(got) < 2 || got[0].Stage != PullMirroring || got[1] != (PullProgress{PullDownloading, 0, 1000}) {
		t.Fatalf("progress %+v", got)
	}
}

func TestEnginePullFailsWithoutProgress(t *testing.T) {
	d := &pullDaemon{msgs: []string{
		`{"status":"Downloading","id":"aaaaaaaaaaaa","progressDetail":{"current":1,"total":400}}`,
		`{"status":"Download complete","id":"aaaaaaaaaaaa"}`,
	}, pauseAt: 1, pause: 5 * time.Second}
	e := pullEngine(t, d, EngineOptions{PullIdleTimeout: 200 * time.Millisecond})
	start := time.Now()
	err := e.PullImage(t.Context(), pullRef)
	if err == nil || !strings.Contains(err.Error(), "no download progress for 200ms") {
		t.Fatalf("err %v", err)
	}
	if time.Since(start) > 3*time.Second {
		t.Fatalf("took %s", time.Since(start))
	}
}

// A warm-up of an image launchroom built that hangs means this server
// doesn't reach the registry: the pull fails at the warm-up's bound with a
// clear message instead of waiting for the overall pull timeout (D68).
func TestEnginePullFailsWhenTheRegistryDoesNotAnswer(t *testing.T) {
	e := pullEngine(t, &pullDaemon{}, EngineOptions{BuiltWarmTimeout: 100 * time.Millisecond, Warm: func(ctx context.Context, _ string) (map[string]int64, error) {
		<-ctx.Done()
		return nil, ctx.Err()
	}})
	start := time.Now()
	err := e.PullImage(t.Context(), "127.0.0.1:5000/apps/svc_x@sha256:1111111111111111111111111111111111111111111111111111111111111111")
	if err == nil || !strings.Contains(err.Error(), "the launchroom registry didn't answer within 100ms") {
		t.Fatalf("err %v", err)
	}
	if time.Since(start) > 3*time.Second {
		t.Fatalf("took %s", time.Since(start))
	}
}

func TestEnginePullKeepsGoingWhileProgressing(t *testing.T) {
	// Each message comes within the idle timeout; the whole pull takes longer.
	msgs := make([]string, 0, 8)
	for i := range 8 {
		msgs = append(msgs, fmt.Sprintf(`{"status":"Downloading","id":"aaaaaaaaaaaa","progressDetail":{"current":%d,"total":400}}`, (i+1)*10))
	}
	slow := &slowDaemon{pullDaemon: pullDaemon{msgs: msgs}, every: 100 * time.Millisecond}
	e := pullEngine(t, slow, EngineOptions{PullIdleTimeout: 300 * time.Millisecond})
	if err := e.PullImage(t.Context(), pullRef); err != nil {
		t.Fatal(err)
	}
}

func TestEnginePullReportsDaemonError(t *testing.T) {
	d := &pullDaemon{msgs: []string{`{"errorDetail":{"message":"manifest unknown"},"error":"manifest unknown"}`}}
	e := pullEngine(t, d, EngineOptions{})
	if err := e.PullImage(t.Context(), pullRef); err == nil || !strings.Contains(err.Error(), "manifest unknown") {
		t.Fatalf("err %v", err)
	}
}

// slowDaemon sends every message after a delay.
type slowDaemon struct {
	pullDaemon
	every time.Duration
}

func (d *slowDaemon) ServeHTTP(w http.ResponseWriter, r *http.Request) {
	if !strings.HasSuffix(r.URL.Path, "/images/create") {
		d.pullDaemon.ServeHTTP(w, r)
		return
	}
	w.Header().Set("Content-Type", "application/json")
	fl, _ := w.(http.Flusher)
	for _, m := range d.msgs {
		time.Sleep(d.every)
		_, _ = fmt.Fprintln(w, m)
		if fl != nil {
			fl.Flush()
		}
	}
}
