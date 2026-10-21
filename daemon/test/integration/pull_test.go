//go:build integration

package integration

import (
	"context"
	"log/slog"
	"net"
	"slices"
	"sync"
	"testing"
	"time"

	"github.com/moby/moby/client"

	"github.com/vantezzen/launchroom/daemon/internal/docker"
	"github.com/vantezzen/launchroom/daemon/internal/registry"
)

// largeImage is the Umami image whose first deploy timed out (docs/04 §11
// "Pulls"): Zot syncs it on demand for longer than dockerd's 30 s header
// timeout.
const largeImage = registry.DefaultAddress + "/mirror/ghcr.io/umami-software/umami@sha256:85909afc45bdcda1917394594a087421fdbb05610fded0fa9f6fb861abb2f367"

// TestLargeMirrorPull pulls a large image through a Zot that has never seen
// it: the warm-up waits for the sync, then the pull reports byte progress up
// to the full size.
func TestLargeMirrorPull(t *testing.T) {
	e := setup(t)
	ctx := t.Context()
	issuer := newTokenIssuer(t)
	// A fresh registry volume: the image must come from upstream.
	const name, volume = "lr-itest-zot-pull", "lr-itest-zot-pull"
	_, _ = e.api.ContainerRemove(ctx, name, client.ContainerRemoveOptions{Force: true})
	_, _ = e.api.VolumeRemove(ctx, volume, client.VolumeRemoveOptions{Force: true})
	zotAddr, zotID := startZotAs(t, e.api, issuer, name, volume)
	t.Cleanup(func() {
		bg := context.Background()
		_, _ = e.api.ImageRemove(bg, largeImage, client.ImageRemoveOptions{Force: true})
		_, _ = e.api.ContainerRemove(bg, zotID, client.ContainerRemoveOptions{Force: true})
		_, _ = e.api.VolumeRemove(bg, volume, client.VolumeRemoveOptions{Force: true})
	})
	_, _ = e.api.ImageRemove(ctx, largeImage, client.ImageRemoveOptions{Force: true})

	fctx, cancel := context.WithCancel(context.Background())
	t.Cleanup(cancel)
	var lc net.ListenConfig
	l, err := lc.Listen(ctx, "tcp", registry.DefaultAddress)
	if err != nil {
		t.Fatalf("forwarder: %v", err)
	}
	go func() {
		_ = registry.NewForwarder(registry.DefaultAddress, zotAddr, slog.Default()).ServeListener(fctx, l)
	}()

	tokens := registry.NewTokens(issuer)
	engine, err := docker.NewEngine(docker.EngineOptions{
		ServerID: string(e.serverID), UserAgent: "launchroom-itest",
		Auth: tokens.PullAuth(registry.DefaultAddress),
		Warm: tokens.Warm(registry.DefaultAddress, nil, nil),
	})
	if err != nil {
		t.Fatal(err)
	}
	var mu sync.Mutex
	var got []docker.PullProgress
	pctx := docker.WithPullProgress(ctx, func(p docker.PullProgress) {
		mu.Lock()
		got = append(got, p)
		mu.Unlock()
	})
	start := time.Now()
	if err := engine.PullImage(pctx, largeImage); err != nil {
		t.Fatalf("pull: %v", err)
	}
	t.Logf("pulled in %s with %d progress reports", time.Since(start).Round(time.Second), len(got))
	if ok, err := engine.ImagePresent(ctx, largeImage); err != nil || !ok {
		t.Fatalf("image not present: %v %v", ok, err)
	}

	mu.Lock()
	defer mu.Unlock()
	if len(got) < 2 || got[0].Stage != docker.PullMirroring {
		t.Fatalf("progress %+v", got)
	}
	total := got[1].Total
	if total < 50_000_000 {
		t.Fatalf("total from the manifest %d, want a large image", total)
	}
	// Docker's stream may add small blobs the manifest's layers don't list
	// (the config), so the total can grow a little; it never shrinks.
	stages := []string{}
	var prev docker.PullProgress
	for _, p := range got[1:] {
		if len(stages) == 0 || stages[len(stages)-1] != p.Stage {
			stages = append(stages, p.Stage)
		}
		if p.Total < prev.Total || p.Downloaded > p.Total || p.Downloaded < prev.Downloaded || p.Total > total+total/100 {
			t.Fatalf("inconsistent progress %+v after %+v (manifest total %d)", p, prev, total)
		}
		prev = p
	}
	if !slices.Contains(stages, docker.PullDownloading) {
		t.Fatalf("stages %v", stages)
	}
	t.Logf("stages %v, last %+v", stages, prev)
	if prev.Downloaded != prev.Total {
		t.Fatalf("last report %+v, want every byte", prev)
	}
}
