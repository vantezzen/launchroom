package docker

import (
	"archive/tar"
	"bytes"
	"context"
	"errors"
	"fmt"
	"io"
	"time"

	cerrdefs "github.com/containerd/errdefs"
	"github.com/moby/moby/client"
)

// Volume backups (docs/14 §2) read and write named volumes through a helper
// container that is created but never started: it only mounts the volume at
// /data, and the Engine API's archive endpoints stream its content (GET) or
// extract a tar into it (PUT). No process runs in it, so no image content is
// needed: the helper image is imported locally from an archive holding just
// the /data mount point.
const (
	// HelperImage is the local image of volume helper containers.
	HelperImage = "launchroom-local/volume-helper:1"
	// LabelHelper marks a volume helper container ("volume").
	LabelHelper = "launchroom.helper"
	// HelperMount is where a helper container mounts its volume.
	HelperMount = "/data"
)

// VolumeOps are the Engine operations volume backups need on top of Client.
type VolumeOps interface {
	// PauseContainer freezes a managed container's processes (cgroup freezer).
	PauseContainer(ctx context.Context, id string) error
	// UnpauseContainer resumes a paused managed container.
	UnpauseContainer(ctx context.Context, id string) error
	// StreamFromContainer returns a tar stream of src inside a managed
	// container (also a created, never started one). Bounded by ctx only.
	StreamFromContainer(ctx context.Context, id, src string) (io.ReadCloser, error)
	// StreamToContainer extracts a tar stream at dst inside a managed
	// container, keeping the archive's owners. Bounded by ctx only.
	StreamToContainer(ctx context.Context, id, dst string, tar io.Reader) error
	// EnsureHelperImage imports HelperImage when it is missing.
	EnsureHelperImage(ctx context.Context) error
}

var _ VolumeOps = (*Engine)(nil)

// PauseContainer implements VolumeOps.
func (e *Engine) PauseContainer(ctx context.Context, id string) error {
	if err := e.mustOwnContainer(ctx, id); err != nil {
		return err
	}
	ctx, cancel := e.call(ctx)
	defer cancel()
	_, err := e.api.ContainerPause(ctx, id, client.ContainerPauseOptions{})
	return wrap("pause container", err)
}

// UnpauseContainer implements VolumeOps.
func (e *Engine) UnpauseContainer(ctx context.Context, id string) error {
	if err := e.mustOwnContainer(ctx, id); err != nil {
		return err
	}
	ctx, cancel := e.call(ctx)
	defer cancel()
	_, err := e.api.ContainerUnpause(ctx, id, client.ContainerUnpauseOptions{})
	return wrap("unpause container", err)
}

// StreamFromContainer implements VolumeOps.
func (e *Engine) StreamFromContainer(ctx context.Context, id, src string) (io.ReadCloser, error) {
	if err := e.mustOwnContainer(ctx, id); err != nil {
		return nil, err
	}
	res, err := e.api.CopyFromContainer(ctx, id, client.CopyFromContainerOptions{SourcePath: src})
	if err != nil {
		return nil, wrap("copy from container", err)
	}
	return res.Content, nil
}

// StreamToContainer implements VolumeOps.
func (e *Engine) StreamToContainer(ctx context.Context, id, dst string, tar io.Reader) error {
	if err := e.mustOwnContainer(ctx, id); err != nil {
		return err
	}
	_, err := e.api.CopyToContainer(ctx, id, client.CopyToContainerOptions{DestinationPath: dst, Content: tar, CopyUIDGID: true})
	return wrap("copy to container", err)
}

// EnsureHelperImage implements VolumeOps.
func (e *Engine) EnsureHelperImage(ctx context.Context) error {
	if ok, err := e.ImagePresent(ctx, HelperImage); err != nil || ok {
		return err
	}
	var buf bytes.Buffer
	tw := tar.NewWriter(&buf)
	if err := tw.WriteHeader(&tar.Header{Typeflag: tar.TypeDir, Name: "data/", Mode: 0o755, ModTime: time.Unix(0, 0)}); err != nil {
		return fmt.Errorf("docker: helper image: %w", err)
	}
	if err := tw.Close(); err != nil {
		return fmt.Errorf("docker: helper image: %w", err)
	}
	ctx, cancel := context.WithTimeout(ctx, e.pullTimeout)
	defer cancel()
	res, err := e.api.ImageImport(ctx, client.ImageImportSource{Source: &buf, SourceName: "-"}, HelperImage, client.ImageImportOptions{
		Message: "launchroom volume helper (never started)",
		// A command is required to create a container; it is never run.
		Changes: []string{`CMD ["/launchroom-volume-helper"]`, "LABEL " + LabelManaged + "=true " + LabelHelper + "=volume"},
	})
	if err != nil {
		return wrap("import helper image", err)
	}
	defer func() { _ = res.Close() }()
	if _, err := io.Copy(io.Discard, res); err != nil {
		return wrap("import helper image", err)
	}
	if ok, err := e.ImagePresent(ctx, HelperImage); err != nil || !ok {
		return errors.Join(errors.New("docker: helper image missing after import"), err)
	}
	return nil
}

// IsConflict reports a Docker conflict error (e.g. a volume still in use).
func IsConflict(err error) bool { return cerrdefs.IsConflict(err) }
