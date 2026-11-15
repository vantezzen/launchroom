package guard

import (
	"context"
	"net"
	"net/http"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"

	"github.com/vantezzen/launchroom/daemon/internal/policy"
)

func upstream(t *testing.T, h http.HandlerFunc) string {
	t.Helper()
	dir, err := os.MkdirTemp("", "guard")
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { _ = os.RemoveAll(dir) })
	sock := filepath.Join(dir, "d.sock")
	var lc net.ListenConfig
	l, err := lc.Listen(t.Context(), "unix", sock)
	if err != nil {
		t.Fatal(err)
	}
	hs := &http.Server{Handler: h} //nolint:gosec // test server
	go func() { _ = hs.Serve(l) }()
	t.Cleanup(func() { _ = hs.Close() })
	return sock
}

// Discover learns the host from Docker before serving: rootless with the
// RootlessKit API socket, and no self-updates outside compose.
func TestDiscover(t *testing.T) {
	sock := upstream(t, func(w http.ResponseWriter, r *http.Request) {
		if strings.HasSuffix(r.URL.Path, "/info") {
			_, _ = w.Write([]byte(`{"SecurityOptions":["name=seccomp,profile=builtin","name=rootless"]}`))
			return
		}
		http.NotFound(w, r)
	})
	srv, err := New(Options{Upstream: sock, Policy: policy.DefaultConfig()})
	if err != nil {
		t.Fatal(err)
	}
	if err := srv.Discover(t.Context(), "/run/rk.sock"); err != nil {
		t.Fatal(err)
	}
	if srv.o.Rootless == nil || srv.o.Rootless.APISocket != "/run/rk.sock" || srv.o.Updater != nil {
		t.Fatalf("rootless %+v updater %+v", srv.o.Rootless, srv.o.Updater)
	}
}

// Without Docker it waits until the context ends.
func TestDiscoverWaitsForDocker(t *testing.T) {
	srv, err := New(Options{Upstream: filepath.Join(t.TempDir(), "missing.sock"), Policy: policy.DefaultConfig()})
	if err != nil {
		t.Fatal(err)
	}
	ctx, cancel := context.WithTimeout(t.Context(), 300*time.Millisecond)
	defer cancel()
	if err := srv.Discover(ctx, ""); err == nil {
		t.Fatal("Discover returned without Docker")
	}
}
