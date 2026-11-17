package docker

import (
	"archive/tar"
	"bytes"
	"context"
	"errors"
	"io"
	"path"
	"strings"
	"time"

	"github.com/moby/moby/api/types/container"
	"github.com/moby/moby/api/types/mount"

	"github.com/vantezzen/launchroom/daemon/internal/names"
	"github.com/vantezzen/launchroom/daemon/internal/policy"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// InitVolumeOwner gives a volume's top folder to owner (G76, docs/03 §5):
// an image that runs as a non-root user can't write to a fresh volume, whose
// top folder belongs to root. It changes only an empty volume, never one
// that has files, and reports whether it changed anything. Callers run it
// after the container that mounts the volume was created (Docker copies the
// image's own folder into an empty volume then, with its owner) and before
// that container starts. It works through a never-started helper container
// and the archive routes dockerd-guard allows; nothing runs in a container.
// A Client without VolumeOps changes nothing.
func InitVolumeOwner(ctx context.Context, c Client, serverID state.ServerID, volume string, owner state.FileOwner) (bool, error) {
	ops, ok := c.(VolumeOps)
	if !ok {
		return false, nil
	}
	ctx, cancel := context.WithTimeout(ctx, 30*time.Second)
	defer cancel()
	if err := ops.EnsureHelperImage(ctx); err != nil {
		return false, err
	}
	labels := ManagedLabels(string(serverID))
	labels[names.LabelHelper] = names.HelperVolume
	hc := policy.BaseHostConfig()
	hc.NetworkMode = "none"
	hc.Mounts = []mount.Mount{{Type: mount.TypeVolume, Source: volume, Target: names.HelperMount}}
	id, err := c.CreateContainer(ctx, &ContainerCreate{
		Name: names.VolumeHelper(),
		Config: &container.Config{
			Image: names.VolumeHelperImage, Labels: labels,
			Cmd: []string{"/launchroom-volume-helper"}, NetworkDisabled: true,
		},
		HostConfig: hc,
	})
	if err != nil {
		return false, err
	}
	defer func() {
		rctx, rcancel := context.WithTimeout(context.WithoutCancel(ctx), 30*time.Second)
		defer rcancel()
		_ = c.RemoveContainer(rctx, id)
	}()
	root, empty, err := volumeRoot(ctx, ops, id)
	if err != nil {
		return false, err
	}
	if !empty || (root.Uid == owner.UID && root.Gid == owner.GID) {
		return false, nil
	}
	// A directory entry for the mount point: Docker merges it into the
	// existing folder and applies its owner (and keeps its mode).
	var buf bytes.Buffer
	tw := tar.NewWriter(&buf)
	hdr := &tar.Header{
		Typeflag: tar.TypeDir, Name: strings.TrimPrefix(names.HelperMount, "/") + "/",
		Mode: root.Mode & 0o7777, Uid: owner.UID, Gid: owner.GID, ModTime: root.ModTime, Format: tar.FormatPAX,
	}
	if hdr.Mode == 0 {
		hdr.Mode = 0o755
	}
	if err := tw.WriteHeader(hdr); err != nil {
		return false, err
	}
	if err := tw.Close(); err != nil {
		return false, err
	}
	if err := ops.StreamToContainer(ctx, id, "/", &buf); err != nil {
		return false, err
	}
	return true, nil
}

// volumeRoot reads the helper's mount point: its own header and whether
// anything is inside (only the first entries are read).
func volumeRoot(ctx context.Context, ops VolumeOps, helper string) (*tar.Header, bool, error) {
	rc, err := ops.StreamFromContainer(ctx, helper, names.HelperMount)
	if err != nil {
		return nil, false, err
	}
	defer func() { _ = rc.Close() }()
	tr := tar.NewReader(rc)
	var root *tar.Header
	for {
		h, err := tr.Next()
		if errors.Is(err, io.EOF) {
			break
		}
		if err != nil {
			return nil, false, err
		}
		name := strings.TrimSuffix(path.Clean(h.Name), "/")
		if root == nil && name == path.Base(names.HelperMount) {
			root = h
			continue
		}
		return orEmpty(root), false, nil // anything else: the volume has files
	}
	if root == nil {
		return nil, false, errors.New("the volume's top folder is missing from the archive")
	}
	return root, true, nil
}

func orEmpty(h *tar.Header) *tar.Header {
	if h == nil {
		return &tar.Header{}
	}
	return h
}
