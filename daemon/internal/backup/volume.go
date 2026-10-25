package backup

import (
	"bytes"
	"context"
	"crypto/rand"
	"encoding/hex"
	"errors"
	"fmt"
	"io"
	"maps"
	"slices"
	"sync"
	"time"

	"github.com/moby/moby/api/types/container"
	"github.com/moby/moby/api/types/mount"

	"github.com/vantezzen/launchroom/daemon/internal/docker"
	"github.com/vantezzen/launchroom/daemon/internal/policy"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// VolumeFile is the restic --stdin-filename of volume snapshots: a tar
// stream of the volume's content (entries "data/…"), so restic's
// content-defined chunking deduplicates unchanged files across runs and a
// restore extracts it with the Engine API (docs/14 §2).
const VolumeFile = "volume.tar"

// VolumeDocker is what volume backups need from the Docker layer.
type VolumeDocker interface {
	ListContainers(ctx context.Context, labels map[string]string) ([]docker.Container, error)
	ListVolumes(ctx context.Context) ([]docker.Volume, error)
	CreateVolume(ctx context.Context, v docker.VolumeCreate) error
	RemoveVolume(ctx context.Context, name string) error
	CreateContainer(ctx context.Context, cc *docker.ContainerCreate) (string, error)
	RemoveContainer(ctx context.Context, id string) error
	docker.VolumeOps
}

// findVolume returns the managed volume carrying the protocol id. Workload
// volumes carry their service and environment labels (reconcile), except a
// prepared one (created before its service runs here, for a move: docs/14
// §13.2), which records none; system volumes (Caddy, BuildKit) are never a
// backup or restore target.
func (e *Engine) findVolume(ctx context.Context, id state.VolumeID) (docker.Volume, error) {
	vols, err := e.o.Volumes.ListVolumes(ctx)
	if err != nil {
		return docker.Volume{}, err
	}
	var found []docker.Volume
	for _, v := range vols {
		if v.Labels[docker.LabelVolume] == string(id) {
			found = append(found, v)
		}
	}
	switch {
	case len(found) == 0:
		return docker.Volume{}, fmt.Errorf("backup: volume %s does not exist on this server", id)
	case len(found) > 1:
		return docker.Volume{}, fmt.Errorf("backup: more than one volume carries the id %s", id)
	case found[0].Labels[docker.LabelSystem] != "" || policy.CheckVolumeName(state.HostName(found[0].Name)) != nil:
		return docker.Volume{}, fmt.Errorf("backup: volume %s is not a service volume", id)
	}
	return found[0], nil
}

// users returns the containers that mount the volume, except volume
// helpers. A volume access' SFTP helper counts (D67): a restore or import
// waits until it is gone (the access ends once the service is held).
func (e *Engine) users(ctx context.Context, name string) ([]docker.Container, error) {
	cs, err := e.o.Volumes.ListContainers(ctx, nil)
	if err != nil {
		return nil, err
	}
	var out []docker.Container
	for _, c := range cs {
		if c.Labels[docker.LabelHelper] != "volume" && slices.Contains(c.Volumes, name) {
			out = append(out, c)
		}
	}
	return out, nil
}

// sweepHelpers removes helper containers left behind by an interrupted
// volume backup or restore. Heavy backup work runs one at a time per server
// (tasks.BackupOptions.Slots), so no other helper is in use.
func (e *Engine) sweepHelpers(ctx context.Context) {
	cs, err := e.o.Volumes.ListContainers(ctx, map[string]string{docker.LabelHelper: "volume"})
	if err != nil {
		return
	}
	for _, c := range cs {
		_ = e.o.Volumes.RemoveContainer(ctx, c.ID)
	}
}

// helper creates (never starts) a container that mounts the volume at /data.
func (e *Engine) helper(ctx context.Context, name string, readOnly bool) (id string, remove func(), err error) {
	if err := e.o.Volumes.EnsureHelperImage(ctx); err != nil {
		return "", nil, err
	}
	suffix := make([]byte, 6)
	_, _ = rand.Read(suffix)
	labels := docker.ManagedLabels(e.o.ServerID)
	labels[docker.LabelHelper] = "volume"
	id, err = e.o.Volumes.CreateContainer(ctx, &docker.ContainerCreate{
		Name: "lr-vol-" + hex.EncodeToString(suffix),
		Config: &container.Config{
			Image: docker.HelperImage, Labels: labels,
			Cmd: []string{"/launchroom-volume-helper"}, NetworkDisabled: true,
		},
		HostConfig: &container.HostConfig{
			NetworkMode: "none",
			CapDrop:     []string{"ALL"},
			Mounts:      []mount.Mount{{Type: mount.TypeVolume, Source: name, Target: docker.HelperMount, ReadOnly: readOnly}},
		},
	})
	if err != nil {
		return "", nil, err
	}
	return id, func() {
		rctx, cancel := context.WithTimeout(context.WithoutCancel(ctx), 30*time.Second)
		defer cancel()
		_ = e.o.Volumes.RemoveContainer(rctx, id)
	}, nil
}

// backupVolume streams a volume's content as a tar into a new restic
// snapshot (docs/14 §2). With consistency "pause" the containers using the
// volume are frozen while it is read; "live" reads it while they run.
// Database volumes are never backed up this way (the dashboard sends dumps).
func (e *Engine) backupVolume(ctx context.Context, spec *state.BackupSpec, creds Credentials, logf Logf) (*state.BackupResult, error) {
	if e.o.Volumes == nil {
		return nil, fmt.Errorf("backup: volume backups: %w", ErrUnsupported)
	}
	vol, err := e.findVolume(ctx, spec.Target.Volume)
	if err != nil {
		return nil, err
	}
	r, err := e.openRepo(spec.Destination, creds)
	if err != nil {
		return nil, err
	}
	start := e.o.Now()
	if created, err := r.ensure(ctx); err != nil {
		return nil, err
	} else if created {
		logf("Initialised a new backup repository")
	}
	e.sweepHelpers(ctx)
	hid, remove, err := e.helper(ctx, vol.Name, true)
	if err != nil {
		return nil, err
	}
	defer remove()

	unpause := func() {}
	if spec.Target.Consistency == state.ConsistencyPause {
		paused, err := e.pauseUsers(ctx, vol.Name)
		if err != nil {
			return nil, err
		}
		unpause = sync.OnceFunc(func() { e.unpauseAll(ctx, paused) })
		if len(paused) > 0 {
			logf("Paused the service while the volume is read")
		}
	}
	defer unpause()

	logf("Streaming volume %s into the repository", spec.Target.Volume)
	ctx, cancel := context.WithCancel(ctx)
	defer cancel()
	src, err := e.o.Volumes.StreamFromContainer(ctx, hid, docker.HelperMount)
	if err != nil {
		return nil, err
	}
	defer func() { _ = src.Close() }()

	args := []string{"backup", "--stdin", "--stdin-filename", VolumeFile, "--host", resticHost, "--json"}
	for _, t := range spec.Tags {
		args = append(args, "--tag", string(t))
	}
	pr, pw := io.Pipe()
	counted := &countingWriter{w: pw}
	readDone := make(chan error, 1)
	go func() {
		_, err := io.Copy(counted, src)
		// The volume has been read: resume the service before the upload finishes.
		unpause()
		if err != nil {
			err = fmt.Errorf("backup: reading the volume: %w", err)
			_ = pw.CloseWithError(errDumpFailed)
		} else {
			_ = pw.Close()
		}
		readDone <- err
	}()

	var out bytes.Buffer
	resticErr := r.run(ctx, "backup", pr, &out, args...)
	if resticErr != nil {
		_ = pr.CloseWithError(errors.New("restic stopped"))
		cancel()
	}
	readErr := <-readDone
	summary := parseBackupSummary(out.Bytes())
	switch {
	case readErr != nil:
		if summary.SnapshotID != "" {
			fctx, fcancel := context.WithTimeout(context.WithoutCancel(ctx), 2*time.Minute)
			_ = r.run(fctx, "forget", nil, nil, "forget", summary.SnapshotID)
			fcancel()
		}
		return nil, readErr
	case resticErr != nil:
		return nil, resticErr
	case summary.SnapshotID == "":
		return nil, errors.New("backup: restic did not report a snapshot id")
	}
	snap := state.SnapshotID(summary.SnapshotID)
	if err := snap.Validate(); err != nil {
		return nil, fmt.Errorf("backup: restic reported an invalid snapshot id: %w", err)
	}
	size := counted.n.Load()
	logf("Snapshot %s stored (%s, %s added)", short(summary.SnapshotID), humanBytes(size), humanBytes(summary.DataAdded))
	return &state.BackupResult{
		SnapshotID: snap, SizeBytes: size, AddedBytes: max(summary.DataAdded, 0),
		DurationMs: e.o.Now().Sub(start).Milliseconds(),
	}, nil
}

// pauseUsers freezes the running containers that use the volume.
func (e *Engine) pauseUsers(ctx context.Context, name string) ([]string, error) {
	users, err := e.users(ctx, name)
	if err != nil {
		return nil, err
	}
	var paused []string
	for _, c := range users {
		if !c.Running || c.State == "paused" {
			continue
		}
		if err := e.o.Volumes.PauseContainer(ctx, c.ID); err != nil {
			e.unpauseAll(ctx, paused)
			return nil, fmt.Errorf("backup: pausing the service for a consistent backup: %w", err)
		}
		paused = append(paused, c.ID)
	}
	return paused, nil
}

func (e *Engine) unpauseAll(ctx context.Context, ids []string) {
	uctx, cancel := context.WithTimeout(context.WithoutCancel(ctx), time.Minute)
	defer cancel()
	for _, id := range ids {
		_ = e.o.Volumes.UnpauseContainer(uctx, id)
	}
}

// restoreVolume replaces a volume's content with a snapshot (docs/14 §5.1).
// The dashboard stops the service first (its workloads leave the desired
// state while the restore runs; in place, a safety snapshot was taken). The
// restore waits until no container uses the volume, recreates it empty with
// the same name and labels, and extracts the snapshot's tar into it.
func (e *Engine) restoreVolume(ctx context.Context, spec *state.RestoreSpec, creds Credentials, logf Logf) (*state.RestoreResult, error) {
	if e.o.Volumes == nil {
		return nil, fmt.Errorf("backup: volume restores: %w", ErrUnsupported)
	}
	vol, err := e.findVolume(ctx, spec.Target.Volume)
	if err != nil {
		return nil, err
	}
	r, err := e.openRepo(spec.Destination, creds)
	if err != nil {
		return nil, err
	}
	start := e.o.Now()
	if ok, err := r.exists(ctx); err != nil {
		return nil, err
	} else if !ok {
		return nil, errors.New("backup: the backup repository does not exist")
	}
	snaps, err := r.snapshots(ctx, string(spec.Snapshot))
	if err != nil {
		return nil, err
	}
	if len(snaps) != 1 {
		return nil, fmt.Errorf("backup: snapshot %s was not found in the repository", spec.Snapshot)
	}
	want := "/" + VolumeFile
	if !slices.Equal(snaps[0].Paths, []string{want}) {
		return nil, fmt.Errorf("backup: snapshot %s is not a volume snapshot", spec.Snapshot)
	}

	if err := e.waitIdle(ctx, vol.Name, logf); err != nil {
		return nil, err
	}
	e.sweepHelpers(ctx)
	logf("Restoring snapshot %s into volume %s", short(snaps[0].ID), spec.Target.Volume)
	if err := e.o.Volumes.RemoveVolume(ctx, vol.Name); err != nil {
		return nil, fmt.Errorf("backup: emptying the volume: %w", err)
	}
	if err := e.o.Volumes.CreateVolume(ctx, docker.VolumeCreate{Name: vol.Name, Labels: maps.Clone(vol.Labels)}); err != nil {
		return nil, fmt.Errorf("backup: recreating the volume (it is now missing; restore again): %w", err)
	}
	hid, remove, err := e.helper(ctx, vol.Name, false)
	if err != nil {
		return nil, err
	}
	defer remove()

	ctx, cancel := context.WithCancel(ctx)
	defer cancel()
	pr, pw := io.Pipe()
	counted := &countingWriter{w: pw}
	dumpDone := make(chan error, 1)
	go func() {
		err := r.run(ctx, "dump", nil, counted, "dump", snaps[0].ID, want)
		if err != nil {
			_ = pw.CloseWithError(errDumpFailed)
		} else {
			_ = pw.Close()
		}
		dumpDone <- err
	}()
	// The snapshot is filtered before Docker extracts it: no device nodes,
	// setuid/setgid bits, file capabilities or names outside data/ (docs/14 §2).
	fr, fw := io.Pipe()
	filterDone := make(chan error, 1)
	go func() {
		err := sanitizeVolumeTar(fw, pr)
		_ = pr.CloseWithError(errors.New("restore stopped reading"))
		_ = fw.CloseWithError(err)
		filterDone <- err
	}()
	putErr := e.o.Volumes.StreamToContainer(ctx, hid, "/", fr)
	_ = fr.CloseWithError(errors.New("restore stopped reading"))
	if putErr != nil {
		cancel()
	}
	filterErr := <-filterDone
	if filterErr != nil {
		cancel()
	}
	dumpErr := <-dumpDone
	switch {
	case filterErr != nil && errors.Is(filterErr, errUnsafeArchive):
		return nil, fmt.Errorf("%w (the volume is incomplete; restore another snapshot)", filterErr)
	case dumpErr != nil && !errors.Is(dumpErr, context.Canceled):
		return nil, fmt.Errorf("%w (the volume is incomplete; restore again)", dumpErr)
	case putErr != nil:
		return nil, fmt.Errorf("backup: extracting into the volume (it is incomplete; restore again): %w", putErr)
	case dumpErr != nil:
		return nil, dumpErr
	case filterErr != nil:
		return nil, fmt.Errorf("%w (the volume is incomplete; restore again)", filterErr)
	}
	size := counted.n.Load()
	logf("Restored %s", humanBytes(size))
	return &state.RestoreResult{SizeBytes: size, DurationMs: e.o.Now().Sub(start).Milliseconds()}, nil
}

// waitIdle waits until no container uses the volume (the dashboard has
// taken the service's workloads out of the desired state).
func (e *Engine) waitIdle(ctx context.Context, name string, logf Logf) error {
	deadline := e.o.Now().Add(e.o.VolumeIdleTimeout)
	logged := false
	for {
		users, err := e.users(ctx, name)
		if err != nil {
			return err
		}
		if len(users) == 0 {
			return nil
		}
		if !logged {
			logf("Waiting for the service to stop before the volume is replaced")
			logged = true
		}
		if e.o.Now().After(deadline) {
			return fmt.Errorf("backup: the volume is still used by %d container(s); stop the service and restore again", len(users))
		}
		select {
		case <-ctx.Done():
			return ctx.Err()
		case <-time.After(e.o.PollEvery):
		}
	}
}
