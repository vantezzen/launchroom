package buildkitd

import (
	"context"
	"errors"
	"testing"

	"github.com/vantezzen/launchroom/daemon/internal/docker/dockerfake"
	"github.com/vantezzen/launchroom/daemon/internal/policy"
)

// noIODocker is the fake Docker of a host without the io cgroup controller:
// like runc there, starting a container that sets an I/O weight fails.
type noIODocker struct {
	*dockerfake.Fake
	refused int
}

func (d *noIODocker) StartContainer(ctx context.Context, id string) error {
	c, err := d.InspectContainer(ctx, id)
	if err != nil {
		return err
	}
	if cc, ok := d.Create(c.Name); ok && cc.HostConfig.BlkioWeight != 0 {
		d.refused++
		return errors.New("Error response from daemon: failed to create task for container: failed to create shim task: OCI runtime create failed: runc create failed: unable to start container process: error during container init: error setting cgroup config for procHooks process: openat2 /sys/fs/cgroup/docker/c647bcd99c27/io.weight: no such file or directory: unknown")
	}
	return d.Fake.StartContainer(ctx, id)
}

func TestHostWithoutIOWeightStillBuilds(t *testing.T) {
	d := &noIODocker{Fake: dockerfake.New(readySrv)}
	d.AddImage(string(image))
	m := New(Options{ServerID: readySrv, Docker: d, Ops: nopOps{}, Policy: policy.DefaultConfig(),
		Resources: Resources{CPUShares: buildCPUShares, BlkioWeight: buildBlkioWeight}})
	syncOn(t, m)
	if d.refused != 1 {
		t.Fatalf("refused %d starts, want 1", d.refused)
	}
	cc, ok := d.Create(ContainerName)
	if !ok || cc.HostConfig.BlkioWeight != 0 || cc.HostConfig.CPUShares != buildCPUShares {
		t.Fatalf("container resources %+v", cc.HostConfig.Resources)
	}
	// The next pass keeps the container instead of trying the weight again.
	syncOn(t, m)
	if d.refused != 1 || m.Resources().BlkioWeight != 0 {
		t.Fatalf("refused %d, weight %d", d.refused, m.Resources().BlkioWeight)
	}
	if ioWeightRefused(errors.New("no space left on device")) || !ioWeightRefused(errors.New("write /sys/fs/cgroup/blkio/x/blkio.weight: invalid argument")) {
		t.Fatal("ioWeightRefused")
	}
}
