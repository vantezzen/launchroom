package build

import (
	"context"
	"errors"
	"slices"
	"strings"
	"testing"
	"time"

	bkclient "github.com/moby/buildkit/client"
	"github.com/opencontainers/go-digest"

	"github.com/vantezzen/launchroom/daemon/internal/state"
)

func hintCodes(hs []state.FailureHint) []string {
	out := make([]string, 0, len(hs))
	for _, h := range hs {
		out = append(out, string(h.Code))
	}
	return out
}

func hintMessage(hs []state.FailureHint, code state.HintCode) string {
	for _, h := range hs {
		if h.Code == code {
			return h.Message
		}
	}
	return ""
}

// Host-caused and build-time-network failures get their own hints.
func TestHostAndNetworkHints(t *testing.T) {
	for _, c := range []struct {
		name, text string
		want       []string
		says       string
	}{
		{"disk full", "#12 ERROR: failed to copy: write /var/lib/buildkit/x: no space left on device", []string{"disk-full"}, "ran out of disk space"},
		{"npm ENOSPC", "npm error code ENOSPC\nnpm error syscall write", []string{"disk-full"}, ""},
		{"npm registry", "npm error code EAI_AGAIN\nnpm error request to https://registry.npmjs.org/next failed, reason: getaddrinfo EAI_AGAIN registry.npmjs.org", []string{"build-dns"}, "can't resolve registry.npmjs.org"},
		{"go proxy", `dial tcp: lookup proxy.golang.org on 127.0.0.11:53: server misbehaving`, []string{"build-dns"}, "proxy.golang.org"},
		{"curl", "curl: (6) Could not resolve host: github.com", []string{"build-dns"}, "github.com"},
		{"apt", "Temporary failure in name resolution", []string{"build-dns"}, "A name didn't resolve"},
		{"busybox wget", "wget: bad address 'dl-cdn.alpinelinux.org'", []string{"build-dns"}, "dl-cdn.alpinelinux.org"},
		{"apk", "WARNING: fetching https://dl-cdn.alpinelinux.org/alpine/v3.22/main: DNS lookup error", []string{"build-dns"}, ""},
		{"next prerender", "Collecting page data ...\nError occurred prerendering page \"/products\". Read more: https://nextjs.org/docs/messages/prerender-error\nError: getaddrinfo ENOTFOUND postgres\n", []string{"build-database"}, "the page /products"},
		{"prisma", "Generating static pages (0/12)\nPrismaClientInitializationError: Can't reach database server at `postgres:5432`", []string{"build-database"}, "a page"},
		{"refused", "Collecting page data\nError: connect ECONNREFUSED 10.0.0.3:5432", []string{"build-database"}, ""},
		{"private name is not DNS", "getaddrinfo ENOTFOUND postgres", nil, ""},
		{"php extension", "filament/support v4.0 requires ext-intl * -> it is missing from your system.", []string{"php-extension"}, `"ext-intl": "*"`},
	} {
		t.Run(c.name, func(t *testing.T) {
			hs := hintsFor(c.text)
			if got := hintCodes(hs); !slices.Equal(got, c.want) {
				t.Fatalf("hints %q, want %q", got, c.want)
			}
			if c.says != "" && !strings.Contains(hs[0].Message, c.says) {
				t.Fatalf("message %q lacks %q", hs[0].Message, c.says)
			}
			for _, h := range hs {
				if len(h.Message) > 1024 || h.Code.Validate() != nil {
					t.Fatalf("hint %+v", h)
				}
			}
		})
	}
}

// A build that ran out of disk runs the server's cleanup at once, says what
// it freed and becomes retryable when it freed something.
func TestDiskFullFreesDisk(t *testing.T) {
	calls := 0
	b := New(Options{FreeDisk: func(ctx context.Context) state.CleanupResult {
		calls++
		if ctx.Err() != nil {
			t.Error("cleanup got a canceled context")
		}
		return state.CleanupResult{ReclaimedMB: 3174}
	}})
	sink := &lineSink{}
	canceled, cancel := context.WithCancel(context.Background())
	cancel() // the build's own context may be over; the cleanup still runs
	be := classifySolve(errors.New(`process "npm ci" did not complete successfully: exit code: 1`), "install", "npm error code ENOSPC\nnpm error nospc ENOSPC: no space left on device")
	b.freeDiskAfter(canceled, be, sink)
	if calls != 1 || be.Class != state.FailureTransient || !be.Retryable() {
		t.Fatalf("calls %d error %+v", calls, be)
	}
	if msg := hintMessage(be.Hints, state.HintDiskFull); !strings.Contains(msg, "freed 3.1 GB") {
		t.Fatalf("hint %q", msg)
	}
	if !slices.Contains(sink.lines, "[install] Cleanup freed 3.1 GB") {
		t.Fatalf("log %q", sink.lines)
	}

	// A clone that failed on disk gets the hint from its error text.
	be = fail(state.FailureClone, "clone", errors.New("write .git/objects/pack: no space left on device"))
	b = New(Options{FreeDisk: func(context.Context) state.CleanupResult { return state.CleanupResult{} }})
	b.freeDiskAfter(context.Background(), be, &lineSink{})
	if be.Class != state.FailureClone || !strings.Contains(hintMessage(be.Hints, state.HintDiskFull), "no old images or build cache left") {
		t.Fatalf("clone error %+v", be)
	}

	// Other failures never trigger a cleanup.
	b = New(Options{FreeDisk: func(context.Context) state.CleanupResult { t.Error("cleanup ran"); return state.CleanupResult{} }})
	b.freeDiskAfter(context.Background(), classifySolve(errors.New("exit code: 1"), "build", "Type error"), &lineSink{})

	if formatMB(420) != "420 MB" || formatMB(1024) != "1.0 GB" {
		t.Fatal("formatMB")
	}
}

// A build that prints nothing for the idle limit is stopped, and the last
// unterminated line it printed still reaches the log.
func TestIdleBuildIsStopped(t *testing.T) {
	sink := &lineSink{}
	m := newLogMapper(func(string) string { return "install" }, sink)
	now := time.Now()
	d := digest.FromString("v")
	m.status(&bkclient.SolveStatus{Vertexes: []*bkclient.Vertex{{Digest: d, Name: "npm ci", Started: &now}}})
	m.status(&bkclient.SolveStatus{Logs: []*bkclient.VertexLog{{Vertex: d, Data: []byte("added 3 packages\nwaiting for input: ")}}})
	m.flush()
	if !slices.Contains(sink.lines, "[install] #1 waiting for input: ") || m.currentStep() != "install" {
		t.Fatalf("log %q step %q", sink.lines, m.currentStep())
	}
	m.flush() // flushed once only
	if n := len(sink.lines); n != 3 {
		t.Fatalf("log %q", sink.lines)
	}

	activity := make(chan struct{}, 1)
	fired := make(chan struct{})
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	go watchIdle(ctx, 50*time.Millisecond, activity, func() { close(fired) })
	for range 4 {
		time.Sleep(20 * time.Millisecond)
		activity <- struct{}{}
	}
	select {
	case <-fired:
		t.Fatal("idle fired while the build printed")
	default:
	}
	select {
	case <-fired:
	case <-time.After(2 * time.Second):
		t.Fatal("idle never fired")
	}

	e := classifySolve(errBuildIdle{idle: 20 * time.Minute, step: "install"}, "install", "#3 npm ci")
	if e.Class != state.FailureTimeout || e.Retryable() || !slices.Equal(hintCodes(e.Hints), []string{"build-idle"}) || !strings.Contains(e.Hints[0].Message, "No build output for 20 minutes during install") {
		t.Fatalf("idle error %+v", e)
	}
	if (&Builder{}).idleTimeout() != DefaultIdleTimeout || (&Builder{o: Options{IdleTimeout: -1}}).idleTimeout() != 0 {
		t.Fatal("idle timeout default")
	}
}
