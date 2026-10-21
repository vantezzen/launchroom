package docker

import (
	"context"
	"errors"
	"fmt"
	"slices"
	"strings"
	"time"

	cerrdefs "github.com/containerd/errdefs"
	"github.com/moby/moby/client"
)

// HostOps are host-wide Docker reads for health reports and the image part of
// the disk cleanup (docs/04 §8). Reads may see objects launchroom does not
// manage (they are only counted); RemoveImage refuses any image that has a
// reference outside the given registry prefix. Nothing here touches volumes.
type HostOps interface {
	// RootDir is Docker's data root (DockerRootDir).
	RootDir(ctx context.Context) (string, error)
	// CountNetworks counts all networks and those managed by this server.
	CountNetworks(ctx context.Context) (total, managed int, err error)
	// Images lists images with their sizes and how many containers use them.
	Images(ctx context.Context) ([]Image, Usage, error)
	// RemoveImage removes an unused image (never forced). Every reference
	// of the image must start with onlyUnder (e.g. "127.0.0.1:5000/").
	// It returns ErrImageInUse when a container still uses it.
	RemoveImage(ctx context.Context, id, onlyUnder string) error
}

// ErrImageInUse means Docker refused to remove an image a container uses.
var ErrImageInUse = errors.New("docker: image is in use")

// Image is a local image.
type Image struct {
	ID string
	// Refs are its repo digests and tags ("127.0.0.1:5000/apps/svc_…@sha256:…").
	Refs    []string
	Created time.Time
	SizeMB  int64
	// Containers is the number of containers (running or not) using it; -1 = unknown.
	Containers int64
}

// Usage is Docker's disk accounting in MB.
type Usage struct {
	ImagesMB            int64
	ImagesReclaimableMB int64
	ContainersMB        int64
}

var _ HostOps = (*Engine)(nil)

// RootDir implements HostOps.
func (e *Engine) RootDir(ctx context.Context) (string, error) {
	ctx, cancel := e.call(ctx)
	defer cancel()
	res, err := e.api.Info(ctx, client.InfoOptions{})
	if err != nil {
		return "", wrap("info", err)
	}
	return res.Info.DockerRootDir, nil
}

// CountNetworks implements HostOps.
func (e *Engine) CountNetworks(ctx context.Context) (int, int, error) {
	ctx, cancel := e.call(ctx)
	defer cancel()
	res, err := e.api.NetworkList(ctx, client.NetworkListOptions{})
	if err != nil {
		return 0, 0, wrap("list networks", err)
	}
	managed := 0
	for _, n := range res.Items {
		if IsManaged(n.Labels, e.serverID) {
			managed++
		}
	}
	return len(res.Items), managed, nil
}

// Images implements HostOps. It uses Docker's disk usage endpoint, which also
// counts the containers per image.
func (e *Engine) Images(ctx context.Context) ([]Image, Usage, error) {
	ctx, cancel := context.WithTimeout(ctx, 2*e.callTimeout) // system df can be slow on big hosts
	defer cancel()
	res, err := e.api.DiskUsage(ctx, client.DiskUsageOptions{Images: true, Containers: true, Verbose: true})
	if err != nil {
		return nil, Usage{}, wrap("disk usage", err)
	}
	u := Usage{
		ImagesMB:            mb(res.Images.TotalSize),
		ImagesReclaimableMB: mb(res.Images.Reclaimable),
		ContainersMB:        mb(res.Containers.TotalSize),
	}
	out := make([]Image, 0, len(res.Images.Items))
	for _, s := range res.Images.Items {
		refs := make([]string, 0, len(s.RepoDigests)+len(s.RepoTags))
		for _, r := range slices.Concat(s.RepoDigests, s.RepoTags) {
			if r != "" && r != "<none>@<none>" && r != "<none>:<none>" {
				refs = append(refs, r)
			}
		}
		out = append(out, Image{
			ID: s.ID, Refs: refs, Created: time.Unix(s.Created, 0).UTC(), SizeMB: mb(s.Size), Containers: s.Containers,
		})
	}
	return out, u, nil
}

func mb(b int64) int64 { return max(b, 0) >> 20 }

// RemoveImage implements HostOps.
func (e *Engine) RemoveImage(ctx context.Context, id, onlyUnder string) error {
	if onlyUnder == "" || !strings.HasSuffix(onlyUnder, "/") {
		return errors.New("docker: remove image needs a registry prefix")
	}
	ctx, cancel := e.call(ctx)
	defer cancel()
	res, err := e.api.ImageInspect(ctx, id)
	if err != nil {
		return wrap("inspect image", err)
	}
	refs := slices.Concat(res.RepoDigests, res.RepoTags)
	if len(refs) == 0 {
		return fmt.Errorf("%w: image %s has no launchroom reference", ErrNotManaged, shortID(id))
	}
	for _, r := range refs {
		if !strings.HasPrefix(r, onlyUnder) {
			return fmt.Errorf("%w: image %s is also referenced as %s", ErrNotManaged, shortID(id), r)
		}
	}
	// Remove by reference, not by ID: each reference is ours, and removing the
	// last one deletes the image. Never forced: images used by a container stay.
	for _, r := range refs {
		_, err := e.api.ImageRemove(ctx, r, client.ImageRemoveOptions{PruneChildren: true})
		switch {
		case err == nil, cerrdefs.IsNotFound(err):
		case cerrdefs.IsConflict(err):
			return fmt.Errorf("%w: %s", ErrImageInUse, shortID(id))
		default:
			return wrap("remove image", err)
		}
	}
	return nil
}
