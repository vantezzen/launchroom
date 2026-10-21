// Package cleanup frees disk on a server (docs/04 §8 retention), on a
// schedule and under disk pressure:
//
//   - images from the launchroom registry (127.0.0.1:5000/…) that no
//     container uses and no workload of the current NodeState needs, except
//     the newest Keep (2) per repository (= per service), so a rollback needs
//     no pull. Images with any reference outside the registry are never
//     touched, and images younger than MinImageAge are kept (a run_once task
//     may have pulled one that is not in a NodeState yet);
//   - the BuildKit cache by age and size (never everything);
//   - one-off task containers (lr-run-*) left behind by a daemon crash.
//
// Volumes are never pruned (Dokploy lost data that way). Removal is never
// forced: Docker itself refuses to delete an image a container uses.
package cleanup

import (
	"cmp"
	"context"
	"errors"
	"fmt"
	"log/slog"
	"maps"
	"slices"
	"strings"
	"sync"
	"time"

	"github.com/vantezzen/launchroom/daemon/internal/docker"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// Defaults (docs/04 §8).
const (
	DefaultKeep           = 2
	DefaultMinImageAge    = time.Hour
	DefaultLeftoverAge    = 15 * time.Minute
	DefaultBuildCacheAge  = 7 * 24 * time.Hour
	DefaultBuildCacheKeep = 10 << 30 // bytes of BuildKit cache always kept
	// Under disk pressure the BuildKit prune is more aggressive.
	pressureBuildCacheAge  = 24 * time.Hour
	pressureBuildCacheKeep = 2 << 30
)

// BuildCachePrune prunes the BuildKit cache: records unused for longer than
// keepDuration, while keeping keepBytes. It returns the bytes freed.
type BuildCachePrune func(ctx context.Context, keepDuration time.Duration, keepBytes int64) (int64, error)

// Options configures a Cleaner.
type Options struct {
	Docker docker.Client
	Host   docker.HostOps
	// RegistryPrefix is the only image reference prefix that may be removed ("127.0.0.1:5000/").
	RegistryPrefix string
	// DesiredImages returns the images of the current NodeState (kept even
	// when no container runs them yet).
	DesiredImages func() []string
	// PruneBuildCache is nil without a running BuildKit (no build role).
	PruneBuildCache func(ctx context.Context) BuildCachePrune
	Keep            int
	MinImageAge     time.Duration
	LeftoverAge     time.Duration
	Log             *slog.Logger
	Now             func() time.Time
}

// Cleaner runs cleanups; Run may be called concurrently (runs are serialized).
type Cleaner struct {
	o    Options
	mu   sync.Mutex
	last *state.CleanupResult
}

// New returns a Cleaner.
func New(o Options) *Cleaner {
	if o.Keep <= 0 {
		o.Keep = DefaultKeep
	}
	if o.MinImageAge <= 0 {
		o.MinImageAge = DefaultMinImageAge
	}
	if o.LeftoverAge <= 0 {
		o.LeftoverAge = DefaultLeftoverAge
	}
	if o.Log == nil {
		o.Log = slog.New(slog.DiscardHandler)
	}
	if o.Now == nil {
		o.Now = time.Now
	}
	if o.DesiredImages == nil {
		o.DesiredImages = func() []string { return nil }
	}
	return &Cleaner{o: o}
}

// Last returns the result of the last run (nil before the first).
func (c *Cleaner) Last() *state.CleanupResult {
	c.mu.Lock()
	defer c.mu.Unlock()
	if c.last == nil {
		return nil
	}
	r := *c.last
	return &r
}

// Run performs one cleanup.
func (c *Cleaner) Run(ctx context.Context, trigger state.CleanupTrigger) state.CleanupResult {
	c.mu.Lock()
	defer c.mu.Unlock()
	res := state.CleanupResult{At: c.o.Now().UTC(), Trigger: trigger}
	var errs []error

	n, err := c.removeLeftovers(ctx)
	res.RemovedContainers = n
	errs = append(errs, err)

	removed, freed, err := c.removeImages(ctx)
	res.RemovedImages, res.ReclaimedMB = removed, freed
	errs = append(errs, err)

	if c.o.PruneBuildCache != nil {
		if prune := c.o.PruneBuildCache(ctx); prune != nil {
			age, keep := DefaultBuildCacheAge, int64(DefaultBuildCacheKeep)
			if trigger == state.CleanupDiskPressure {
				age, keep = pressureBuildCacheAge, pressureBuildCacheKeep
			}
			b, err := prune(ctx, age, keep)
			res.ReclaimedMB += max(b, 0) >> 20
			if err != nil {
				errs = append(errs, fmt.Errorf("build cache: %w", err))
			}
		}
	}
	if err := errors.Join(errs...); err != nil && ctx.Err() == nil {
		msg := err.Error()
		if len(msg) > 1024 {
			msg = msg[:1024]
		}
		res.Error = msg
	}
	c.o.Log.Info("cleanup finished", "trigger", trigger, "removedImages", res.RemovedImages,
		"removedContainers", res.RemovedContainers, "reclaimedMb", res.ReclaimedMB, "err", res.Error)
	c.last = &res
	return res
}

// removeLeftovers removes stopped one-off task containers older than LeftoverAge.
func (c *Cleaner) removeLeftovers(ctx context.Context) (int, error) {
	list, err := c.o.Docker.ListContainers(ctx, nil)
	if err != nil {
		return 0, fmt.Errorf("list containers: %w", err)
	}
	now := c.o.Now()
	removed := 0
	var errs []error
	for _, ct := range list {
		if ct.Labels[docker.LabelTask] == "" || !strings.HasPrefix(ct.Name, "lr-run-") {
			continue
		}
		switch ct.State {
		case "exited", "dead", "created":
		default:
			continue // running, restarting, paused, removing: not a leftover (yet)
		}
		since := ct.FinishedAt
		if since.IsZero() {
			since = ct.Created
		}
		if since.IsZero() || now.Sub(since) < c.o.LeftoverAge {
			continue
		}
		if err := c.o.Docker.RemoveContainer(ctx, ct.ID); err != nil && !errors.Is(err, docker.ErrNotFound) {
			errs = append(errs, err)
			continue
		}
		removed++
	}
	return removed, errors.Join(errs...)
}

func (c *Cleaner) removeImages(ctx context.Context) (int, int64, error) {
	if c.o.Host == nil || c.o.RegistryPrefix == "" {
		return 0, 0, nil
	}
	images, _, err := c.o.Host.Images(ctx)
	if err != nil {
		return 0, 0, fmt.Errorf("list images: %w", err)
	}
	victims := Select(images, c.o.DesiredImages(), c.o.RegistryPrefix, c.o.Keep, c.o.Now().Add(-c.o.MinImageAge))
	removed, freed := 0, int64(0)
	var errs []error
	for _, img := range victims {
		if ctx.Err() != nil {
			break
		}
		err := c.o.Host.RemoveImage(ctx, img.ID, c.o.RegistryPrefix)
		switch {
		case err == nil:
			removed++
			freed += img.SizeMB
		case errors.Is(err, docker.ErrImageInUse), errors.Is(err, docker.ErrNotFound):
		default:
			errs = append(errs, err)
		}
	}
	return removed, freed, errors.Join(errs...)
}

// Select returns the images to remove: only images whose every reference is
// under prefix; per repository, everything except images in use (by a
// container or by the desired state), images created after notBefore, and the
// newest keep of the rest.
func Select(images []docker.Image, desired []string, prefix string, keep int, notBefore time.Time) []docker.Image {
	want := map[string]bool{}
	for _, d := range desired {
		want[d] = true
	}
	groups := map[string][]docker.Image{}
	for _, img := range images {
		if len(img.Refs) == 0 || !allUnder(img.Refs, prefix) {
			continue
		}
		if img.Containers > 0 || slices.ContainsFunc(img.Refs, func(r string) bool { return want[r] }) {
			continue
		}
		repo := repository(img.Refs[0])
		groups[repo] = append(groups[repo], img)
	}
	var out []docker.Image
	for _, repo := range slices.Sorted(maps.Keys(groups)) {
		g := groups[repo]
		slices.SortStableFunc(g, func(a, b docker.Image) int { return cmp.Compare(b.Created.UnixNano(), a.Created.UnixNano()) })
		for i, img := range g {
			if i < keep || img.Created.After(notBefore) {
				continue
			}
			out = append(out, img)
		}
	}
	return out
}

func allUnder(refs []string, prefix string) bool {
	for _, r := range refs {
		if !strings.HasPrefix(r, prefix) {
			return false
		}
	}
	return true
}

// repository strips the digest or tag from a reference.
func repository(ref string) string {
	if i := strings.Index(ref, "@"); i >= 0 {
		return ref[:i]
	}
	if i := strings.LastIndex(ref, ":"); i > strings.LastIndex(ref, "/") {
		return ref[:i]
	}
	return ref
}
