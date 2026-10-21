//go:build integration

package integration

import (
	"context"
	"maps"
	"strings"
	"testing"
	"time"

	"github.com/moby/moby/api/types/container"
	"github.com/moby/moby/client"

	"github.com/vantezzen/launchroom/daemon/internal/cleanup"
	"github.com/vantezzen/launchroom/daemon/internal/docker"
	"github.com/vantezzen/launchroom/daemon/internal/hostinfo"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// TestCleanupAgainstDocker runs the disk cleanup against a real daemon:
// unused images of the launchroom registry beyond the newest two per
// repository are removed; images in use, images with a reference outside the
// registry and the base image stay; leftover one-off containers are removed
// and nothing else is touched (docs/04 §8).
func TestCleanupAgainstDocker(t *testing.T) {
	e := setup(t)
	ctx, cancel := context.WithTimeout(context.Background(), 5*time.Minute)
	defer cancel()
	pull(ctx, t, e.api, string(whoamiImage))

	// A stopped scratch container to commit distinct images from.
	base, err := e.api.ContainerCreate(ctx, client.ContainerCreateOptions{Config: &container.Config{Image: string(whoamiImage)}})
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() {
		_, _ = e.api.ContainerRemove(context.WithoutCancel(ctx), base.ID, client.ContainerRemoveOptions{Force: true})
	})
	// Its own registry prefix, so images other tests left in the shared
	// daemon are not part of this run.
	const prefix = "127.0.0.1:5000/itestcleanup/"
	const repo = prefix + "apps/svc_itest"
	commit := func(tag string) string {
		t.Helper()
		res, err := e.api.ContainerCommit(ctx, base.ID, client.ContainerCommitOptions{Reference: repo + ":" + tag, Changes: []string{"LABEL itest.version=" + tag}})
		if err != nil {
			t.Fatal(err)
		}
		t.Cleanup(func() {
			_, _ = e.api.ImageRemove(context.WithoutCancel(ctx), res.ID, client.ImageRemoveOptions{Force: true})
		})
		time.Sleep(1100 * time.Millisecond) // Created has second resolution
		return res.ID
	}
	v1, v2, v3, v4, v5 := commit("v1"), commit("v2"), commit("v3"), commit("v4"), commit("v5")
	// v1 also has a reference outside the registry: never ours to delete.
	if _, err := e.api.ImageTag(ctx, client.ImageTagOptions{Source: v1, Target: "example.com/someone-else:keep"}); err != nil {
		t.Fatal(err)
	}
	// v3 is used by a (not launchroom-managed) container: Docker refuses, and we never force.
	user, err := e.api.ContainerCreate(ctx, client.ContainerCreateOptions{Config: &container.Config{Image: repo + ":v3"}})
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() {
		_, _ = e.api.ContainerRemove(context.WithoutCancel(ctx), user.ID, client.ContainerRemoveOptions{Force: true})
	})

	// A leftover one-off container (daemon crashed during a run_once task).
	labels := maps.Clone(docker.ManagedLabels(string(e.serverID)))
	labels[docker.LabelTask] = randomID("tsk")
	leftover, err := e.api.ContainerCreate(ctx, client.ContainerCreateOptions{Name: "lr-run-itest-leftover", Config: &container.Config{Image: string(whoamiImage), Labels: labels}})
	if err != nil {
		t.Fatal(err)
	}

	c := cleanup.New(cleanup.Options{
		Docker: e.engine, Host: e.engine, RegistryPrefix: prefix,
		MinImageAge: time.Nanosecond, LeftoverAge: time.Nanosecond,
	})
	res := c.Run(ctx, state.CleanupScheduled)
	if res.Error != "" {
		t.Fatalf("cleanup error: %s", res.Error)
	}

	present := map[string]bool{}
	list, err := e.api.ImageList(ctx, client.ImageListOptions{})
	if err != nil {
		t.Fatal(err)
	}
	for _, img := range list.Items {
		present[img.ID] = true
	}
	// Unused, all refs ours: v2, v4, v5 → keep the newest two (v5, v4), remove v2.
	// v3 is in use, v1 has a foreign reference, the base image is docker.io.
	for id, want := range map[string]bool{v1: true, v2: false, v3: true, v4: true, v5: true} {
		if present[id] != want {
			t.Errorf("image %s present=%v, want %v", id[:19], present[id], want)
		}
	}
	if ok, _ := e.engine.ImagePresent(ctx, string(whoamiImage)); !ok {
		t.Error("the base image was removed")
	}
	if res.RemovedImages != 1 || res.RemovedContainers != 1 {
		t.Errorf("result = %+v", res)
	}
	if _, err := e.api.ContainerInspect(ctx, leftover.ID, client.ContainerInspectOptions{}); err == nil {
		t.Error("leftover lr-run container still exists")
	}
	if _, err := e.api.ContainerInspect(ctx, user.ID, client.ContainerInspectOptions{}); err != nil {
		t.Errorf("an unmanaged container was touched: %v", err)
	}

	// RemoveImage itself refuses images with foreign references.
	if err := e.engine.RemoveImage(ctx, v1, prefix); err == nil || !strings.Contains(err.Error(), "example.com/someone-else") {
		t.Errorf("foreign-referenced image: err = %v", err)
	}

	// Host health facts come from the same daemon.
	h := hostinfo.New(hostinfo.Options{DataDir: t.TempDir(), Docker: e.engine}).Collect(ctx)
	if h.Networks == nil || h.Networks.Total < 3 || h.Docker == nil || h.Docker.ImagesMB <= 0 || h.Memory == nil || h.Load == nil || h.Inotify == nil {
		t.Errorf("host health = %+v", h)
	}
	if h.DockerDisk == nil {
		t.Error("the Docker root filesystem is visible in the test daemon but was not reported")
	}
}

func pull(ctx context.Context, t *testing.T, api *client.Client, ref string) {
	t.Helper()
	res, err := api.ImagePull(ctx, ref, client.ImagePullOptions{})
	if err != nil {
		t.Fatal(err)
	}
	defer func() { _ = res.Close() }()
	if err := res.Wait(ctx); err != nil {
		t.Fatal(err)
	}
}
