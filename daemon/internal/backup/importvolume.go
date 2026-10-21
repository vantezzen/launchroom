package backup

import (
	"archive/tar"
	"context"
	"errors"
	"fmt"
	"io"
	"maps"

	"github.com/vantezzen/launchroom/daemon/internal/docker"
	"github.com/vantezzen/launchroom/daemon/internal/state"
	"github.com/vantezzen/launchroom/daemon/internal/transfer"
)

// volumeRoot is what an inspection reads about a volume.
type volumeRoot struct {
	owner state.FileOwner
	mode  int64
	empty bool
}

// inspectVolume reads only the first entries of the volume's tar through a
// read-only helper: the root folder (owner, mode) and whether anything
// follows it.
func (e *Engine) inspectVolume(ctx context.Context, name string) (volumeRoot, error) {
	hid, remove, err := e.helper(ctx, name, true)
	if err != nil {
		return volumeRoot{}, err
	}
	defer remove()
	ctx, cancel := context.WithCancel(ctx)
	defer cancel()
	src, err := e.o.Volumes.StreamFromContainer(ctx, hid, docker.HelperMount)
	if err != nil {
		return volumeRoot{}, err
	}
	defer func() { _ = src.Close() }()
	tr := tar.NewReader(src)
	root := volumeRoot{empty: true, mode: 0o755}
	for i := 0; i < 2; i++ {
		h, err := tr.Next()
		if errors.Is(err, io.EOF) {
			break
		}
		if err != nil {
			return volumeRoot{}, fmt.Errorf("backup: reading the volume: %w", err)
		}
		if i == 0 {
			root.owner, root.mode = state.FileOwner{UID: h.Uid, GID: h.Gid}, h.Mode&0o1777
			continue
		}
		root.empty = false
	}
	return root, nil
}

// errStoppedReading ends a producer whose consumer gave up.
var errStoppedReading = errors.New("the reader stopped")

// maxExpansion bounds how much an archive may expand to (archive bombs).
func maxExpansion(size int64) int64 { return max(100*size, 1<<30) }

// ImportVolume runs a volume_import task (both modes). The dashboard holds
// the service while it runs; launchroomd still waits until nothing uses the
// volume before it changes it.
func (e *Engine) ImportVolume(ctx context.Context, task state.TaskID, spec *state.VolumeImportSpec, logf Logf, progress Progress) (*state.DataImportResult, error) {
	if logf == nil {
		logf = nopLog
	}
	if progress == nil {
		progress = nopProgress
	}
	if e.o.Volumes == nil {
		return nil, fmt.Errorf("backup: volume imports: %w", ErrUnsupported)
	}
	start := e.o.Now()
	vol, err := e.findVolume(ctx, spec.Volume)
	if err != nil {
		return nil, err
	}
	if spec.Mode == state.ImportRun {
		// Imports hold the heavy slot, so leftover helpers are unused;
		// inspections run beside backups and must not touch theirs.
		e.sweepHelpers(ctx)
	}
	root, err := e.inspectVolume(ctx, vol.Name)
	if err != nil {
		return nil, err
	}
	res := &state.DataImportResult{Mode: spec.Mode, Empty: root.empty, RootOwner: &state.FileOwner{UID: root.owner.UID, GID: root.owner.GID}}
	if spec.Mode == state.ImportInspect {
		res.DurationMs = e.o.Now().Sub(start).Milliseconds()
		return res, nil
	}
	if e.o.Transfers == nil {
		return nil, fmt.Errorf("backup: volume imports: %w", ErrUnsupported)
	}
	ref := *spec.File
	logf("Fetching the archive (%s)", transfer.HumanBytes(ref.SizeBytes))
	path, err := transfer.Fetch(ctx, e.o.Transfers, ref, task, transfer.Options{Dir: e.o.TransferDir, Free: e.o.Free}, func(done, total int64) {
		progress(fmt.Sprintf("Fetching %s of %s", transfer.HumanBytes(done), transfer.HumanBytes(total)))
	})
	if err != nil {
		return nil, err
	}
	defer transfer.Remove(e.o.TransferDir, task)

	opts := archiveOptions{Strip: spec.StripComponents, Owner: spec.Owner, DefaultOwner: root.owner, RootMode: root.mode, MaxBytes: maxExpansion(ref.SizeBytes)}
	// A dry run first: an unsafe archive is refused before the volume changes.
	progress("Checking the archive")
	stats, err := convertArchive(io.Discard, path, opts)
	if err != nil {
		return nil, err
	}
	res.Format, res.Compressed, res.ReadBytes = stats.Format, stats.Compressed, ref.SizeBytes
	if spec.Owner != nil {
		res.RootOwner = &state.FileOwner{UID: spec.Owner.UID, GID: spec.Owner.GID}
	}

	if err := e.waitIdle(ctx, vol.Name, logf); err != nil {
		return nil, err
	}
	e.sweepHelpers(ctx)
	if spec.Replace {
		logf("Emptying volume %s", spec.Volume)
		if err := e.o.Volumes.RemoveVolume(ctx, vol.Name); err != nil {
			return nil, fmt.Errorf("backup: emptying the volume: %w", err)
		}
		if err := e.o.Volumes.CreateVolume(ctx, docker.VolumeCreate{Name: vol.Name, Labels: maps.Clone(vol.Labels)}); err != nil {
			return nil, fmt.Errorf("backup: recreating the volume (it is now missing; import again): %w", err)
		}
	}
	hid, remove, err := e.helper(ctx, vol.Name, false)
	if err != nil {
		return nil, err
	}
	defer remove()

	progress("Importing")
	logf("Extracting %d entries (%s) into volume %s", stats.Files, humanBytes(stats.Bytes), spec.Volume)
	ctx, cancel := context.WithCancel(ctx)
	defer cancel()
	pr, pw := io.Pipe()
	convDone := make(chan error, 1)
	go func() {
		_, err := convertArchive(pw, path, opts)
		_ = pw.CloseWithError(err)
		convDone <- err
	}()
	putErr := e.o.Volumes.StreamToContainer(ctx, hid, "/", pr)
	_ = pr.CloseWithError(errStoppedReading)
	if putErr != nil {
		cancel()
	}
	convErr := <-convDone
	switch {
	case convErr != nil && !errors.Is(convErr, errStoppedReading):
		return nil, fmt.Errorf("%w (the volume is incomplete; import again)", convErr)
	case putErr != nil:
		return nil, fmt.Errorf("backup: extracting into the volume (it is incomplete; import again): %w", putErr)
	}
	res.Empty = stats.Files == 0 && (spec.Replace || root.empty)
	res.Files, res.SizeBytes = stats.Files, stats.Bytes
	if stats.Skipped > 0 {
		res.Warnings = []string{fmt.Sprintf("Skipped %d entries (named pipes, or above the stripped folders)", stats.Skipped)}
	}
	res.DurationMs = e.o.Now().Sub(start).Milliseconds()
	logf("Imported %d entries (%s)", stats.Files, humanBytes(stats.Bytes))
	return res, nil
}
