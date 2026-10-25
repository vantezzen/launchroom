package access

import (
	"archive/tar"
	"context"
	"crypto/rand"
	"encoding/hex"
	"errors"
	"fmt"
	"io"
	"strconv"
	"time"

	"github.com/moby/moby/api/types/container"
	"github.com/moby/moby/api/types/mount"

	"github.com/vantezzen/launchroom/daemon/internal/docker"
	"github.com/vantezzen/launchroom/daemon/internal/policy"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// Volume access (D67, docs/03 §12.4): "Connect with SFTP" into one volume.
// The SSH front end is the same as for a console; its SFTP backend runs the
// file tools in a short-lived helper container instead of the workload:
//
//   - the pinned busybox image of the "files" system component (digest
//     checked here and again by dockerd-guard), read-only root filesystem;
//   - only the volume, at /data, read-only unless the access allows writes;
//   - network "none", cap-drop ALL, no-new-privileges, an init, memory, pids
//     and CPU limits, and PID 1 is `sleep` for the access' lifetime plus a
//     minute, so a helper a crash left behind stops by itself;
//   - it runs as the owner of the volume's top folder, read from the first
//     entry of the volume's archive through a never-started probe of the same
//     shape, so files it writes belong to the same user as the service's;
//   - SFTP sees the volume as "/" (FS root) and nothing else; shell and exec
//     requests are refused (dockerd-guard allows only the file tools' argv).
//
// Helpers carry launchroom.helper=access and the access id; launchroomd
// removes them when the access ends and sweeps every one at start (no access
// outlives the process).
const (
	// LabelAccess carries the access id on a volume access helper.
	LabelAccess = "launchroom.access"
	// HelperKind is the launchroom.helper value of volume access helpers.
	HelperKind = "access"
	// HelperMount is where the helper mounts the volume.
	HelperMount = "/data"
	// HelperMaxLifetime bounds the helper's sleep (the access cap is 4 h).
	HelperMaxLifetime = 5 * time.Hour

	helperMemory = 128 << 20
	helperPids   = 128
	helperCPUs   = 1_000_000_000 // one CPU
	probeUser    = "65534:65534"
)

// VolumeDocker is what volume access needs on top of Docker: reading the
// first entry of a volume's archive through the never-started probe.
type VolumeDocker interface {
	StreamFromContainer(ctx context.Context, id, src string) (io.ReadCloser, error)
}

// HelperName returns a generated helper container name.
func HelperName() string {
	b := make([]byte, 6)
	_, _ = rand.Read(b)
	return "lr-acc-" + hex.EncodeToString(b)
}

// HelperSpec is the built-in helper container (the guard checks this shape).
func HelperSpec(server state.ServerID, access state.AccessID, image state.ImageRef, volume string, user string, readOnly bool, lifetime time.Duration) *docker.ContainerCreate {
	labels := docker.ManagedLabels(string(server))
	labels[docker.LabelHelper] = HelperKind
	labels[LabelAccess] = string(access)
	secs := int64(min(max(lifetime, time.Minute), HelperMaxLifetime) / time.Second)
	memory, pids, init := int64(helperMemory), int64(helperPids), true
	return &docker.ContainerCreate{
		Name: HelperName(),
		Config: &container.Config{
			Image: string(image), Labels: labels, User: user,
			Entrypoint:      []string{"sleep"},
			Cmd:             []string{strconv.FormatInt(secs, 10)},
			NetworkDisabled: true,
		},
		HostConfig: &container.HostConfig{
			NetworkMode:    "none",
			CapDrop:        []string{"ALL"},
			SecurityOpt:    []string{policy.NoNewPrivileges},
			ReadonlyRootfs: true,
			Init:           &init,
			Resources:      container.Resources{Memory: memory, MemorySwap: memory, PidsLimit: &pids, NanoCPUs: helperCPUs},
			LogConfig: container.LogConfig{
				Type:   policy.LogDriver,
				Config: map[string]string{"max-size": policy.LogMaxSize, "max-file": policy.LogMaxFile},
			},
			Mounts: []mount.Mount{{Type: mount.TypeVolume, Source: volume, Target: HelperMount, ReadOnly: readOnly}},
		},
	}
}

// volumeTarget finds the Docker volume of the spec: declared as a mount of
// the workload in the verified NodeState, exactly one managed workload
// volume carrying the id, never a system volume, and (when it records one)
// owned by the workload's service and environment.
func (a *run) volumeTarget(ctx context.Context, wl *state.WorkloadSpec) (docker.Volume, error) {
	declared := false
	for _, m := range wl.Mounts {
		declared = declared || m.Volume == a.spec.Volume
	}
	if !declared {
		return docker.Volume{}, fmt.Errorf("%w: %s does not mount volume %s", ErrRefused, a.spec.Workload, a.spec.Volume)
	}
	vols, err := a.o.Docker.ListVolumes(ctx)
	if err != nil {
		return docker.Volume{}, err
	}
	var found []docker.Volume
	for _, v := range vols {
		if v.Labels[docker.LabelVolume] == string(a.spec.Volume) {
			found = append(found, v)
		}
	}
	if len(found) != 1 {
		return docker.Volume{}, fmt.Errorf("volume %s is not on this server yet (deploy the service once)", a.spec.Volume)
	}
	v := found[0]
	l := v.Labels
	switch {
	case l[docker.LabelSystem] != "" || policy.CheckVolumeName(state.HostName(v.Name)) != nil:
		return docker.Volume{}, fmt.Errorf("%w: %s is not a service volume", ErrRefused, a.spec.Volume)
	case l[docker.LabelService] != "" && l[docker.LabelService] != string(wl.Service),
		l[docker.LabelEnvironment] != "" && l[docker.LabelEnvironment] != string(wl.Environment):
		return docker.Volume{}, fmt.Errorf("%w: volume %s belongs to another service", ErrRefused, a.spec.Volume)
	}
	return v, nil
}

// startHelper probes the owner, then creates and starts the helper. The
// returned remove is safe to call more than once.
func (a *run) startHelper(ctx context.Context, vol docker.Volume, until time.Time) (string, state.FileOwner, func(), error) {
	vd, ok := a.o.Docker.(VolumeDocker)
	if !ok {
		return "", state.FileOwner{}, nil, errors.New("this server can't read volumes")
	}
	if err := a.o.CheckImage(a.spec.Image); err != nil {
		return "", state.FileOwner{}, nil, fmt.Errorf("%w: %w", ErrRefused, err)
	}
	if ok, err := a.o.Docker.ImagePresent(ctx, string(a.spec.Image)); err != nil || !ok {
		if err := a.o.Docker.PullImage(ctx, string(a.spec.Image)); err != nil {
			return "", state.FileOwner{}, nil, fmt.Errorf("pulling the SFTP helper image: %w", err)
		}
	}
	owner, err := a.probeOwner(ctx, vd, vol.Name)
	if err != nil {
		return "", state.FileOwner{}, nil, err
	}
	user := strconv.Itoa(owner.UID) + ":" + strconv.Itoa(owner.GID)
	spec := HelperSpec(a.o.ServerID, a.spec.Access, a.spec.Image, vol.Name, user, !a.spec.AllowWrites, until.Sub(a.o.Now())+time.Minute)
	id, err := a.o.Docker.CreateContainer(ctx, spec)
	if err != nil {
		return "", state.FileOwner{}, nil, fmt.Errorf("creating the SFTP helper: %w", err)
	}
	remove := a.remover(ctx, id)
	if err := a.o.Docker.StartContainer(ctx, id); err != nil {
		remove()
		return "", state.FileOwner{}, nil, fmt.Errorf("starting the SFTP helper: %w", err)
	}
	return id, owner, remove, nil
}

// probeOwner reads the owner of the volume's top folder: the first entry of
// its archive, through a never-started container of the helper's shape.
func (a *run) probeOwner(ctx context.Context, vd VolumeDocker, volume string) (state.FileOwner, error) {
	spec := HelperSpec(a.o.ServerID, a.spec.Access, a.spec.Image, volume, probeUser, true, time.Minute)
	id, err := a.o.Docker.CreateContainer(ctx, spec)
	if err != nil {
		return state.FileOwner{}, fmt.Errorf("reading the volume's owner: %w", err)
	}
	defer a.remover(ctx, id)()
	pctx, cancel := context.WithTimeout(ctx, 30*time.Second)
	defer cancel()
	src, err := vd.StreamFromContainer(pctx, id, HelperMount)
	if err != nil {
		return state.FileOwner{}, fmt.Errorf("reading the volume's owner: %w", err)
	}
	defer func() { _ = src.Close() }()
	h, err := tar.NewReader(src).Next()
	if err != nil {
		return state.FileOwner{}, fmt.Errorf("reading the volume's owner: %w", err)
	}
	o := state.FileOwner{UID: h.Uid, GID: h.Gid}
	if o.UID < 0 || o.GID < 0 || o.UID > 2147483647 || o.GID > 2147483647 {
		return state.FileOwner{}, errors.New("the volume's top folder has an invalid owner")
	}
	return o, nil
}

func (a *run) remover(ctx context.Context, id string) func() {
	return func() {
		rctx, cancel := context.WithTimeout(context.WithoutCancel(ctx), 30*time.Second)
		defer cancel()
		if err := a.o.Docker.RemoveContainer(rctx, id); err != nil && !errors.Is(err, docker.ErrNotFound) {
			a.log.Warn("removing the SFTP helper failed; it is swept later", "err", err)
		}
	}
}

// SweepHelpers removes volume access helpers that no running access owns
// (a crash or restart left them; live is the set of running accesses).
func SweepHelpers(ctx context.Context, d docker.Client, live func(state.AccessID) bool) {
	cs, err := d.ListContainers(ctx, map[string]string{docker.LabelHelper: HelperKind})
	if err != nil {
		return
	}
	for _, c := range cs {
		if c.Labels[docker.LabelHelper] != HelperKind || live != nil && live(state.AccessID(c.Labels[LabelAccess])) {
			continue
		}
		rctx, cancel := context.WithTimeout(ctx, 30*time.Second)
		_ = d.RemoveContainer(rctx, c.ID)
		cancel()
	}
}
