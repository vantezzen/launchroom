//go:build integration

package integration

import (
	"archive/tar"
	"bytes"
	"context"
	"errors"
	"io"
	"strings"
	"testing"
	"time"

	"github.com/moby/moby/api/types/container"
	"github.com/moby/moby/api/types/mount"

	"github.com/vantezzen/launchroom/daemon/internal/backup"
	"github.com/vantezzen/launchroom/daemon/internal/docker"
	"github.com/vantezzen/launchroom/daemon/internal/secret"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

type tarFile struct {
	name    string
	content string
	uid     int
	mode    int64
}

func tarOf(t *testing.T, files ...tarFile) io.Reader {
	t.Helper()
	var buf bytes.Buffer
	tw := tar.NewWriter(&buf)
	dirs := map[string]bool{}
	for _, f := range files {
		parts := strings.Split(f.name, "/")
		for i := 1; i < len(parts); i++ {
			dir := strings.Join(parts[:i], "/") + "/"
			if !dirs[dir] {
				dirs[dir] = true
				if err := tw.WriteHeader(&tar.Header{Typeflag: tar.TypeDir, Name: dir, Mode: 0o755, ModTime: time.Now()}); err != nil {
					t.Fatal(err)
				}
			}
		}
		if err := tw.WriteHeader(&tar.Header{Typeflag: tar.TypeReg, Name: f.name, Mode: f.mode, Size: int64(len(f.content)), Uid: f.uid, Gid: f.uid, ModTime: time.Now()}); err != nil {
			t.Fatal(err)
		}
		if _, err := tw.Write([]byte(f.content)); err != nil {
			t.Fatal(err)
		}
	}
	if err := tw.Close(); err != nil {
		t.Fatal(err)
	}
	return &buf
}

// volumeHelper creates (never starts) a container that mounts the volume at /data.
func volumeHelper(ctx context.Context, t *testing.T, e *env, volume string, readOnly bool) string {
	t.Helper()
	if err := e.engine.EnsureHelperImage(ctx); err != nil {
		t.Fatal(err)
	}
	labels := docker.ManagedLabels(string(e.serverID))
	labels[docker.LabelHelper] = "volume"
	id, err := fixtureEngine(t, e.serverID).CreateContainer(ctx, &docker.ContainerCreate{
		Name:       "lr-vol-itest-" + strings.ToLower(randomID("x")[2:10]),
		Config:     &container.Config{Image: docker.HelperImage, Labels: labels, Cmd: []string{"/x"}},
		HostConfig: &container.HostConfig{NetworkMode: "none", Mounts: []mount.Mount{{Type: mount.TypeVolume, Source: volume, Target: "/data", ReadOnly: readOnly}}},
	})
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { _ = e.engine.RemoveContainer(context.WithoutCancel(ctx), id) })
	return id
}

// volumeFiles reads a volume's regular files: name (under data/) → content and owner.
func volumeFiles(ctx context.Context, t *testing.T, e *env, volume string) map[string]tarFile {
	t.Helper()
	id := volumeHelper(ctx, t, e, volume, true)
	defer func() { _ = e.engine.RemoveContainer(context.WithoutCancel(ctx), id) }()
	rc, err := e.engine.StreamFromContainer(ctx, id, "/data")
	if err != nil {
		t.Fatal(err)
	}
	defer func() { _ = rc.Close() }()
	out := map[string]tarFile{}
	tr := tar.NewReader(rc)
	for {
		h, err := tr.Next()
		if errors.Is(err, io.EOF) {
			return out
		}
		if err != nil {
			t.Fatal(err)
		}
		if h.Typeflag != tar.TypeReg {
			continue
		}
		data, _ := io.ReadAll(tr)
		out[h.Name] = tarFile{name: h.Name, content: string(data), uid: h.Uid, mode: h.Mode & 0o777}
	}
}

func TestVolumeBackupAndRestoreThroughRestic(t *testing.T) {
	e := setup(t)
	b := &backupEnv{env: e, envs: map[state.WorkloadID]map[string]secret.Secret{}}
	ctx, cancel := context.WithTimeout(context.Background(), 10*time.Minute)
	defer cancel()

	s3wl := state.WorkloadID(randomID("wl"))
	s3id := b.run(ctx, t, s3wl, s3Image, map[string]secret.Secret{"ROOT_ACCESS_KEY": s3Access, "ROOT_SECRET_KEY": s3Secret}, []string{"posix", "/tmp"})
	c, err := e.engine.InspectContainer(ctx, s3id)
	if err != nil {
		t.Fatal(err)
	}
	var s3ip string
	for _, ip := range c.Networks {
		s3ip = ip.String()
	}

	// A service volume, labelled like the reconciler creates it.
	volID := state.VolumeID(randomID("vol"))
	svc, envID := randomID("svc"), randomID("env")
	name := "lr-v_" + strings.ToLower(randomID("x")[2:18])
	labels := docker.ManagedLabels(string(e.serverID))
	labels[docker.LabelVolume], labels[docker.LabelService], labels[docker.LabelEnvironment] = string(volID), svc, envID
	if err := e.engine.CreateVolume(ctx, docker.VolumeCreate{Name: name, Labels: labels}); err != nil {
		t.Fatal(err)
	}
	seed := volumeHelper(ctx, t, e, name, false)
	if err := e.engine.StreamToContainer(ctx, seed, "/", tarOf(t,
		tarFile{name: "data/uploads/avatar.png", content: "PNG-original", uid: 1000, mode: 0o640},
		tarFile{name: "data/notes.txt", content: "hello from the volume", uid: 33, mode: 0o600},
	)); err != nil {
		t.Fatal(err)
	}
	_ = e.engine.RemoveContainer(ctx, seed)

	// A running service uses the volume (mounted outside redis' /data, which its entrypoint chowns).
	user := state.WorkloadID(randomID("wl"))
	if err := e.engine.PullImage(ctx, redisImage); err != nil {
		t.Fatal(err)
	}
	ulabels := docker.ManagedLabels(string(e.serverID))
	ulabels[docker.LabelWorkload] = string(user)
	userID, err := fixtureEngine(t, e.serverID).CreateContainer(ctx, &docker.ContainerCreate{
		Name:       docker.ContainerName(user),
		Config:     &container.Config{Image: redisImage, Labels: ulabels, Cmd: []string{"redis-server", "--dir", "/tmp"}},
		HostConfig: &container.HostConfig{Mounts: []mount.Mount{{Type: mount.TypeVolume, Source: name, Target: "/srv/uploads"}}},
	})
	if err != nil {
		t.Fatal(err)
	}
	if err := e.engine.StartContainer(ctx, userID); err != nil {
		t.Fatal(err)
	}

	bin := backup.LookupBinary("")
	if bin == "" {
		t.Fatal("restic not found next to the test binary (make test-daemon-integration fetches it)")
	}
	eng := backup.New(backup.Options{
		ResticBinary: bin, DataDir: t.TempDir(), InsecureHosts: []string{s3ip}, Docker: e.engine,
		ServerID: string(e.serverID), Volumes: e.engine, VolumeIdleTimeout: 30 * time.Second, PollEvery: 500 * time.Millisecond,
	})
	creds := backup.Credentials{AccessKeyID: s3Access, SecretAccessKey: s3Secret, ResticPassword: "repo-password-123"}
	dest := state.BackupDestination{Kind: state.DestinationS3, Endpoint: state.S3Endpoint("http://" + s3ip + ":7070"), Bucket: "launchroom-vol-itest"}
	tags := []state.BackupTag{"kind:volume", state.BackupTag("volume:" + string(volID))}
	logf := func(format string, args ...any) { t.Logf("volume: "+format, args...) }

	// Pause consistency: frozen while read, running again afterwards.
	res, err := eng.Backup(ctx, &state.BackupSpec{Kind: state.BackupVolume, Target: state.BackupTarget{Volume: volID, Consistency: state.ConsistencyPause}, Destination: dest, Tags: tags}, creds, logf)
	if err != nil {
		t.Fatal(err)
	}
	if res.SizeBytes < 1024 || res.SnapshotID.Validate() != nil {
		t.Fatalf("backup result %+v", res)
	}
	if uc, err := e.engine.InspectContainer(ctx, userID); err != nil || !uc.Running || uc.State != "running" {
		t.Fatalf("the service must run again after a paused backup: %+v %v", uc, err)
	}
	// Live: a second snapshot dedupes against the first.
	res2, err := eng.Backup(ctx, &state.BackupSpec{Kind: state.BackupVolume, Target: state.BackupTarget{Volume: volID, Consistency: state.ConsistencyLive}, Destination: dest, Tags: tags}, creds, logf)
	if err != nil {
		t.Fatal(err)
	}
	if res2.AddedBytes >= res.SizeBytes {
		t.Fatalf("second snapshot added %d bytes of %d: no dedupe", res2.AddedBytes, res.SizeBytes)
	}

	// Change the volume, then restore the first snapshot in place.
	change := volumeHelper(ctx, t, e, name, false)
	if err := e.engine.StreamToContainer(ctx, change, "/", tarOf(t,
		tarFile{name: "data/uploads/avatar.png", content: "PNG-overwritten", uid: 1000, mode: 0o640},
		tarFile{name: "data/stray.txt", content: "must disappear", uid: 0, mode: 0o644},
	)); err != nil {
		t.Fatal(err)
	}
	_ = e.engine.RemoveContainer(ctx, change)

	restore := &state.RestoreSpec{Kind: state.BackupVolume, Snapshot: res.SnapshotID, Mode: state.RestoreInPlace, Target: state.BackupTarget{Volume: volID}, Destination: dest}

	// The service still uses the volume: the restore waits, then gives up.
	short := backup.New(backup.Options{ResticBinary: bin, DataDir: t.TempDir(), InsecureHosts: []string{s3ip}, Docker: e.engine,
		ServerID: string(e.serverID), Volumes: e.engine, VolumeIdleTimeout: 2 * time.Second, PollEvery: 500 * time.Millisecond})
	if _, err := short.Restore(ctx, restore, creds, logf); err == nil || !strings.Contains(err.Error(), "still used") {
		t.Fatalf("restore with a running service: %v", err)
	}

	// The dashboard holds the service (its container goes away), then the restore runs.
	if err := e.engine.RemoveContainer(ctx, userID); err != nil {
		t.Fatal(err)
	}
	rres, err := eng.Restore(ctx, restore, creds, logf)
	if err != nil {
		t.Fatal(err)
	}
	if rres.SizeBytes != res.SizeBytes {
		t.Fatalf("restored %d bytes, backed up %d", rres.SizeBytes, res.SizeBytes)
	}
	files := volumeFiles(ctx, t, e, name)
	if got := files["data/uploads/avatar.png"]; got.content != "PNG-original" || got.uid != 1000 || got.mode != 0o640 {
		t.Fatalf("avatar.png after restore = %+v", got)
	}
	if got := files["data/notes.txt"]; got.content != "hello from the volume" || got.uid != 33 {
		t.Fatalf("notes.txt after restore = %+v", got)
	}
	if _, ok := files["data/stray.txt"]; ok {
		t.Fatal("a file added after the snapshot survived the restore")
	}

	// The volume keeps its name and owner labels; no helper is left behind.
	vols, err := e.engine.ListVolumes(ctx)
	if err != nil {
		t.Fatal(err)
	}
	found := false
	for _, v := range vols {
		if v.Name == name {
			found = v.Labels[docker.LabelVolume] == string(volID) && v.Labels[docker.LabelService] == svc && v.Labels[docker.LabelEnvironment] == envID
		}
	}
	if !found {
		t.Fatal("the restored volume lost its labels")
	}
	helpers, err := e.engine.ListContainers(ctx, map[string]string{docker.LabelHelper: "volume"})
	if err != nil || len(helpers) != 0 {
		t.Fatalf("helpers left: %d %v", len(helpers), err)
	}

	// An unknown snapshot is refused before the volume is touched.
	if _, err := eng.Restore(ctx, &state.RestoreSpec{Kind: state.BackupVolume, Snapshot: "0000000000000000000000000000000000000000000000000000000000000000", Mode: state.RestoreInPlace, Target: state.BackupTarget{Volume: volID}, Destination: dest}, creds, logf); err == nil {
		t.Fatal("restoring an unknown snapshot succeeded")
	}
}
