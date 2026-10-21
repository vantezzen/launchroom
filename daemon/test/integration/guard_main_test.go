//go:build integration

package integration

import (
	"context"
	"fmt"
	"log/slog"
	"os"
	"path/filepath"
	"slices"
	"sync"
	"testing"

	"github.com/vantezzen/launchroom/daemon/internal/docker"
	"github.com/vantezzen/launchroom/daemon/internal/guard"
	"github.com/vantezzen/launchroom/daemon/internal/policy"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// The whole suite runs launchroomd's Engine through dockerd-guard (D49):
// TestMain starts the guard in this process in front of the dind daemon,
// and every docker.NewEngine gets engineHost. The tests' own raw clients
// (fixtures, "someone else's" objects, assertions) keep talking to Docker
// directly. LR_ITEST_GUARD=off runs the suite without the guard.
var (
	engineHost string
	itestGuard *guard.Server
	guardMu    sync.Mutex
)

// guardRegistries are the workload registries the integration fixtures use
// (production allows only the local registry).
var guardRegistries = []string{policy.DefaultRegistry, "docker.io/", "ghcr.io/"}

func TestMain(m *testing.M) {
	if os.Getenv("LR_ITEST_GUARD") == "off" {
		os.Exit(m.Run())
	}
	dir, err := os.MkdirTemp("", "lr-itest-guard")
	if err != nil {
		fmt.Fprintln(os.Stderr, err)
		os.Exit(1)
	}
	sock := filepath.Join(dir, "docker.sock")
	pol := policy.DefaultConfig()
	pol.AllowedRegistries = slices.Clone(guardRegistries)
	var rootless *guard.RootlessOptions
	if os.Getenv("LR_ITEST_ROOTLESS") == "1" {
		rootless = &guard.RootlessOptions{APISocket: os.Getenv("LR_GUARD_ROOTLESSKIT_API")}
	}
	itestGuard, err = guard.New(guard.Options{
		Upstream: "/var/run/docker.sock", Policy: pol, Rootless: rootless,
		Log: slog.New(slog.NewTextHandler(os.Stderr, &slog.HandlerOptions{Level: slog.LevelWarn})).With("component", "dockerd-guard"),
	})
	if err != nil {
		fmt.Fprintln(os.Stderr, err)
		os.Exit(1)
	}
	l, err := guard.Listen(sock)
	if err != nil {
		fmt.Fprintln(os.Stderr, err)
		os.Exit(1)
	}
	ctx, cancel := context.WithCancel(context.Background())
	go func() { _ = itestGuard.Serve(ctx, l) }()
	engineHost = "unix://" + sock
	fmt.Fprintln(os.Stderr, "integration: launchroomd's Engine runs through dockerd-guard at", sock)
	code := m.Run()
	cancel()
	_ = os.RemoveAll(dir)
	os.Exit(code)
}

// fixtureEngine is an Engine on the raw Docker socket for test fixtures:
// helper containers (registries, ACME servers, peers, databases in the
// shape a test needs) that are not launchroomd's own container specs and
// that the guard rightly refuses. Code under test always gets engineHost.
func fixtureEngine(t testing.TB, server state.ServerID) *docker.Engine {
	t.Helper()
	e, err := docker.NewEngine(docker.EngineOptions{ServerID: string(server), UserAgent: "launchroom-itest-fixture"})
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { _ = e.Close() })
	return e
}

// guardAllowSystemDigest lets the guard accept a locally built system image
// (like a policy relaxation in the guard's .env).
func guardAllowSystemDigest(t *testing.T, component, digest string) {
	t.Helper()
	if itestGuard == nil {
		return
	}
	guardMu.Lock()
	defer guardMu.Unlock()
	pol := itestGuard.Policy()
	cp := policy.DefaultConfig()
	cp.AllowedRegistries = pol.AllowedRegistries
	for k, v := range pol.SystemImageDigests {
		cp.SystemImageDigests[k] = slices.Clone(v)
	}
	cp.SystemImageDigests[component] = append(cp.SystemImageDigests[component], digest)
	if err := itestGuard.SetPolicy(cp); err != nil {
		t.Fatal(err)
	}
}
