package cleanup

import (
	"context"
	"slices"
	"testing"
	"time"

	"github.com/vantezzen/launchroom/daemon/internal/docker/dockerfake"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// G18: "Free space now" (trigger manual) and a build out of space
// (FreeSpace) clean with the disk-pressure settings; FreeSpace runs at most
// every 5 minutes; recovery shells left behind go like lr-run-*.
func TestManualAndFreeSpace(t *testing.T) {
	f := dockerfake.New(server)
	runOnce(t, f, "lr-rec-old", now.Add(-time.Hour), "exited")
	runOnce(t, f, "lr-other-old", now.Add(-time.Hour), "exited")
	f.Now = func() time.Time { return now }
	clock := now
	var pruned []string
	c := New(Options{
		Docker: f, Host: &fakeHost{}, RegistryPrefix: reg, Now: func() time.Time { return clock },
		LeftoverPrefixes: []string{"lr-rec-"},
		PruneBuildCache: func(context.Context) BuildCachePrune {
			return func(_ context.Context, age time.Duration, keep int64) (int64, error) {
				pruned = append(pruned, age.String()+"/"+itoa(keep>>30))
				return 0, nil
			}
		},
	})
	res := c.Run(context.Background(), state.CleanupManual)
	if res.Trigger != state.CleanupManual || res.RemovedContainers != 1 || !slices.Equal(pruned, []string{"24h0m0s/2"}) {
		t.Fatalf("manual run %+v, prune %v", res, pruned)
	}
	left, _ := f.ListContainers(context.Background(), nil)
	if len(left) != 1 || left[0].Name != "lr-other-old" {
		t.Fatalf("left %+v", left)
	}

	clock = clock.Add(time.Minute)
	if got := c.FreeSpace(context.Background()); got.Trigger != state.CleanupManual || len(pruned) != 1 {
		t.Fatalf("FreeSpace within the gap ran again: %+v %v", got, pruned)
	}
	clock = clock.Add(freeSpaceGap)
	if got := c.FreeSpace(context.Background()); got.Trigger != state.CleanupDiskPressure || len(pruned) != 2 {
		t.Fatalf("FreeSpace after the gap: %+v %v", got, pruned)
	}
}
