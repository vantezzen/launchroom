package hostinfo

import (
	"context"
	"testing"
	"time"

	"github.com/vantezzen/launchroom/daemon/internal/docker"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

type fakeHost struct{ root string }

func (h fakeHost) RootDir(context.Context) (string, error) { return h.root, nil }
func (fakeHost) CountNetworks(context.Context) (int, int, error) {
	return 7, 3, nil
}

func (fakeHost) Images(context.Context) ([]docker.Image, docker.Usage, error) {
	return nil, docker.Usage{ImagesMB: 2048, ImagesReclaimableMB: 512, ContainersMB: 40}, nil
}
func (fakeHost) RemoveImage(context.Context, string, string) error { return nil }

func TestCollect(t *testing.T) {
	at := time.Date(2026, 10, 18, 12, 0, 0, 0, time.UTC)
	dir := t.TempDir()
	c := New(Options{
		DataDir: dir, Docker: fakeHost{root: dir}, ProcRoot: "testdata/proc", Now: func() time.Time { return at },
		BuildCacheMB: func(context.Context) (int64, bool) { return 3072, true },
		ClockSkew:    func() (time.Duration, bool) { return -1500 * time.Millisecond, true },
	})
	h := c.Collect(context.Background())
	if !h.CollectedAt.Equal(at) {
		t.Errorf("collectedAt %v", h.CollectedAt)
	}
	if h.Memory == nil || h.Memory.TotalMB != 7939 || h.Memory.AvailableMB != 5120 {
		t.Errorf("memory %+v", h.Memory)
	}
	if h.Load == nil || h.Load.Load1 != 0.42 || h.Load.Load15 != 0.25 {
		t.Errorf("load %+v", h.Load)
	}
	if h.Inotify == nil || *h.Inotify != (state.InotifyLimits{MaxUserWatches: 524288, MaxUserInstances: 128, MaxQueuedEvents: 16384}) {
		t.Errorf("inotify %+v", h.Inotify)
	}
	if h.Networks == nil || h.Networks.Total != 7 || h.Networks.Managed != 3 {
		t.Errorf("networks %+v", h.Networks)
	}
	if h.Docker == nil || h.Docker.ImagesMB != 2048 {
		t.Errorf("docker %+v", h.Docker)
	}
	if h.BuildCacheMB == nil || *h.BuildCacheMB != 3072 || h.ClockSkewMs == nil || *h.ClockSkewMs != -1500 {
		t.Errorf("build cache %v skew %v", h.BuildCacheMB, h.ClockSkewMs)
	}
	if h.DataDisk == nil || h.DataDisk.TotalMB <= 0 || h.DockerDisk == nil {
		t.Errorf("disks %+v %+v", h.DataDisk, h.DockerDisk)
	}
	rep := state.Report{TypeMeta: state.NewTypeMeta(state.KindReport), ServerID: "srv_01J9Z8X7W6V5T4S3R2Q1P0N9MK", ObservedAt: at, Seq: 1,
		Host: &state.HostStatus{CPUs: 1, DaemonVersion: "1.0.0", Health: h}}
	if err := rep.Validate(); err != nil {
		t.Fatal(err)
	}
}

func TestCollectWithoutSources(t *testing.T) {
	h := New(Options{ProcRoot: t.TempDir(), Docker: fakeHost{root: "/does/not/exist"}}).Collect(context.Background())
	if h.Memory != nil || h.Load != nil || h.Inotify != nil || h.DataDisk != nil || h.DockerDisk != nil || h.BuildCacheMB != nil || h.ClockSkewMs != nil {
		t.Fatalf("unreadable facts were not omitted: %+v", h)
	}
}
