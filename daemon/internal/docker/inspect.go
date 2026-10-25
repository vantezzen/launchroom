package docker

import (
	"context"
	"io/fs"
	"time"

	"github.com/moby/moby/api/types/container"
	"github.com/moby/moby/client"
)

// Container inspection (docs/22 §7.2): read-only views of a managed
// container's writable layer and processes, through dockerd-guard's
// changes, top and archive HEAD routes. Nothing runs in the container and
// no file content is read.

// ChangeKind is Docker's change kind of a path.
type ChangeKind int

// Change kinds.
const (
	ChangeModified ChangeKind = iota
	ChangeAdded
	ChangeDeleted
)

// Change is one changed path of a container's writable layer.
type Change struct {
	Path string
	Kind ChangeKind
}

// Processes is a container's process list as Docker's top returns it (ps
// -ef columns with Docker's default arguments).
type Processes struct {
	Titles    []string
	Processes [][]string
}

// PathStat is a stat of a path inside a container.
type PathStat struct {
	Size  int64
	Mode  fs.FileMode
	Mtime time.Time
}

// InspectionOps reads a managed container for an inspection.
type InspectionOps interface {
	ContainerChanges(ctx context.Context, id string) ([]Change, error)
	ContainerTop(ctx context.Context, id string) (Processes, error)
	StatPath(ctx context.Context, id, path string) (PathStat, error)
	// ImageID resolves a local image reference to its id ("sha256:…").
	ImageID(ctx context.Context, ref string) (string, error)
}

var _ InspectionOps = (*Engine)(nil)

// ContainerChanges implements InspectionOps (GET /containers/{id}/changes).
func (e *Engine) ContainerChanges(ctx context.Context, id string) ([]Change, error) {
	if err := e.mustOwnContainer(ctx, id); err != nil {
		return nil, err
	}
	ctx, cancel := e.call(ctx)
	defer cancel()
	res, err := e.api.ContainerDiff(ctx, id, client.ContainerDiffOptions{})
	if err != nil {
		return nil, wrap("container changes", err)
	}
	out := make([]Change, 0, len(res.Changes))
	for _, c := range res.Changes {
		k := ChangeModified
		switch c.Kind {
		case container.ChangeAdd:
			k = ChangeAdded
		case container.ChangeDelete:
			k = ChangeDeleted
		case container.ChangeModify:
		}
		out = append(out, Change{Path: c.Path, Kind: k})
	}
	return out, nil
}

// ContainerTop implements InspectionOps (GET /containers/{id}/top, never
// with ps_args: dockerd-guard refuses them).
func (e *Engine) ContainerTop(ctx context.Context, id string) (Processes, error) {
	if err := e.mustOwnContainer(ctx, id); err != nil {
		return Processes{}, err
	}
	ctx, cancel := e.call(ctx)
	defer cancel()
	res, err := e.api.ContainerTop(ctx, id, client.ContainerTopOptions{})
	if err != nil {
		return Processes{}, wrap("container top", err)
	}
	return Processes{Titles: res.Titles, Processes: res.Processes}, nil
}

// StatPath implements InspectionOps (HEAD /containers/{id}/archive).
func (e *Engine) StatPath(ctx context.Context, id, path string) (PathStat, error) {
	if err := e.mustOwnContainer(ctx, id); err != nil {
		return PathStat{}, err
	}
	ctx, cancel := e.call(ctx)
	defer cancel()
	res, err := e.api.ContainerStatPath(ctx, id, client.ContainerStatPathOptions{Path: path})
	if err != nil {
		return PathStat{}, wrap("stat", err)
	}
	return PathStat{Size: res.Stat.Size, Mode: res.Stat.Mode, Mtime: res.Stat.Mtime}, nil
}

// ImageID implements InspectionOps.
func (e *Engine) ImageID(ctx context.Context, ref string) (string, error) {
	ctx, cancel := e.call(ctx)
	defer cancel()
	res, err := e.api.ImageInspect(ctx, ref)
	if err != nil {
		return "", wrap("inspect image", err)
	}
	return res.ID, nil
}
