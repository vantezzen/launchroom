package cleanup

import (
	"context"
	"errors"
	"maps"
	"slices"
	"strconv"
	"strings"
	"sync"
	"testing"
	"time"

	"github.com/moby/moby/api/types/container"

	"github.com/vantezzen/launchroom/daemon/internal/docker"
	"github.com/vantezzen/launchroom/daemon/internal/docker/dockerfake"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

const (
	server = "srv_01J9Z8X7W6V5T4S3R2Q1P0N9MK"
	reg    = "127.0.0.1:5000/"
)

var now = time.Date(2026, 10, 18, 12, 0, 0, 0, time.UTC)

func img(id, ref string, ageHours int, containers int64) docker.Image {
	return docker.Image{ID: id, Refs: []string{ref}, Created: now.Add(-time.Duration(ageHours) * time.Hour), SizeMB: 100, Containers: containers}
}

func ids(images []docker.Image) []string {
	out := make([]string, 0, len(images))
	for _, i := range images {
		out = append(out, i.ID)
	}
	slices.Sort(out)
	return out
}

func TestSelectKeepsRunningDesiredAndPreviousTwo(t *testing.T) {
	a := reg + "apps/svc_a@sha256:"
	images := []docker.Image{
		img("a1", a+"1", 100, 0), // oldest
		img("a2", a+"2", 90, 0),
		img("a3", a+"3", 80, 0),
		img("a4", a+"4", 70, 0),
		img("a5", a+"5", 60, 1), // running
		img("b1", reg+"apps/svc_b@sha256:1", 50, 0),
		img("b2", reg+"apps/svc_b@sha256:2", 40, 0),
		img("b3", reg+"apps/svc_b@sha256:3", 30, 0), // desired, no container yet
		img("m1", reg+"mirror/docker.io/library/postgres@sha256:1", 5000, 0),
		img("m2", reg+"mirror/docker.io/library/postgres@sha256:2", 4000, 0),
		img("m3", reg+"mirror/docker.io/library/postgres@sha256:3", 3000, 0),
		img("fresh", a+"6", 0, 0), // pulled for a run_once, younger than MinImageAge
		img("x", "docker.io/library/caddy@sha256:1", 999, 0),
		{ID: "mixed", Refs: []string{reg + "apps/svc_a@sha256:7", "myimage:latest"}, Created: now.Add(-500 * time.Hour)},
		{ID: "dangling", Created: now.Add(-500 * time.Hour)},
	}
	got := Select(images, []string{reg + "apps/svc_b@sha256:3"}, reg, 2, now.Add(-time.Hour))
	// svc_a: a5 runs; of the rest (fresh, a4, a3, a2, a1) the newest two (fresh, a4) stay; fresh is young anyway.
	// svc_b: b3 desired; b2, b1 are the previous two. mirror: m3, m2 kept.
	if want := []string{"a1", "a2", "a3", "m1"}; !slices.Equal(ids(got), want) {
		t.Fatalf("selected %v, want %v", ids(got), want)
	}
}

// fakeHost implements docker.HostOps over a list of images.
type fakeHost struct {
	mu      sync.Mutex
	images  []docker.Image
	inUse   map[string]bool
	removed []string
}

func (h *fakeHost) RootDir(context.Context) (string, error) { return "/var/lib/docker", nil }
func (h *fakeHost) CountNetworks(context.Context) (int, int, error) {
	return 3, 1, nil
}

func (h *fakeHost) Images(context.Context) ([]docker.Image, docker.Usage, error) {
	h.mu.Lock()
	defer h.mu.Unlock()
	return slices.Clone(h.images), docker.Usage{ImagesMB: 1000}, nil
}

func (h *fakeHost) RemoveImage(_ context.Context, id, onlyUnder string) error {
	h.mu.Lock()
	defer h.mu.Unlock()
	if h.inUse[id] {
		return docker.ErrImageInUse
	}
	for i, im := range h.images {
		if im.ID != id {
			continue
		}
		for _, r := range im.Refs {
			if !strings.HasPrefix(r, onlyUnder) {
				return docker.ErrNotManaged
			}
		}
		h.images = slices.Delete(h.images, i, i+1)
		h.removed = append(h.removed, id)
		return nil
	}
	return docker.ErrNotFound
}

func runOnce(t *testing.T, f *dockerfake.Fake, name string, created time.Time, state string) string {
	t.Helper()
	ref := reg + "apps/svc_a@sha256:" + strings.Repeat("a", 64)
	f.AddImage(ref)
	f.Now = func() time.Time { return created }
	labels := maps.Clone(docker.ManagedLabels(server))
	labels[docker.LabelTask] = "tsk_01J9Z8X7W6V5T4S3R2Q1P0N9P0"
	id, err := f.CreateContainer(context.Background(), &docker.ContainerCreate{
		Name: name, Config: &container.Config{Image: ref, Labels: labels}, HostConfig: &container.HostConfig{},
	})
	if err != nil {
		t.Fatal(err)
	}
	if state != "created" {
		f.SetState(id, state, 0)
	}
	return id
}

func TestRunRemovesLeftoversImagesAndPrunesBuildCache(t *testing.T) {
	f := dockerfake.New(server)
	runOnce(t, f, "lr-run-old", now.Add(-time.Hour), "exited")
	runOnce(t, f, "lr-run-recent", now.Add(-time.Minute), "exited")
	runOnce(t, f, "lr-run-running", now.Add(-2*time.Hour), "running")
	runOnce(t, f, "lr-run-never-started", now.Add(-2*time.Hour), "created")
	f.Now = func() time.Time { return now }

	a := reg + "apps/svc_a@sha256:"
	host := &fakeHost{
		images: []docker.Image{img("a1", a+"1", 100, 0), img("a2", a+"2", 90, 0), img("a3", a+"3", 80, 0), img("a4", a+"4", 70, 0), img("busy", a+"0", 200, -1)},
		inUse:  map[string]bool{"busy": true},
	}
	var pruned []string
	c := New(Options{
		Docker: f, Host: host, RegistryPrefix: reg, Now: func() time.Time { return now },
		PruneBuildCache: func(context.Context) BuildCachePrune {
			return func(_ context.Context, age time.Duration, keep int64) (int64, error) {
				pruned = append(pruned, age.String()+"/"+itoa(keep>>30))
				return 300 << 20, nil
			}
		},
	})
	if c.Last() != nil {
		t.Fatal("result before the first run")
	}
	res := c.Run(context.Background(), state.CleanupScheduled)
	if res.RemovedContainers != 2 || res.RemovedImages != 2 || res.ReclaimedMB != 200+300 || res.Error != "" || res.Trigger != state.CleanupScheduled {
		t.Fatalf("result = %+v", res)
	}
	if slices.Sort(host.removed); !slices.Equal(host.removed, []string{"a1", "a2"}) {
		t.Fatalf("removed images %v", host.removed) // "busy" was refused as in use, a3/a4 kept
	}
	left, _ := f.ListContainers(context.Background(), nil)
	var names []string
	for _, c := range left {
		names = append(names, c.Name)
	}
	slices.Sort(names)
	if !slices.Equal(names, []string{"lr-run-recent", "lr-run-running"}) {
		t.Fatalf("containers left %v", names)
	}
	if !slices.Equal(pruned, []string{"168h0m0s/10"}) {
		t.Fatalf("build cache prune %v (never --all)", pruned)
	}

	// Disk pressure prunes the build cache harder; errors are reported, not fatal.
	c.o.PruneBuildCache = func(context.Context) BuildCachePrune {
		return func(_ context.Context, age time.Duration, keep int64) (int64, error) {
			pruned = append(pruned, age.String()+"/"+itoa(keep>>30))
			return 0, errors.New("buildkitd went away")
		}
	}
	res = c.Run(context.Background(), state.CleanupDiskPressure)
	if pruned[1] != "24h0m0s/2" || !strings.Contains(res.Error, "buildkitd went away") || c.Last().Trigger != state.CleanupDiskPressure {
		t.Fatalf("pressure run: %v %+v", pruned, res)
	}
}

func TestRepository(t *testing.T) {
	for in, want := range map[string]string{
		"127.0.0.1:5000/apps/svc@sha256:abc": "127.0.0.1:5000/apps/svc",
		"127.0.0.1:5000/apps/svc:tag":        "127.0.0.1:5000/apps/svc",
		"127.0.0.1:5000/apps/svc":            "127.0.0.1:5000/apps/svc",
	} {
		if got := repository(in); got != want {
			t.Errorf("repository(%q) = %q", in, got)
		}
	}
}

func itoa(n int64) string { return strconv.FormatInt(n, 10) }
