package backup

import (
	"archive/tar"
	"context"
	"errors"
	"io"
	"slices"
	"strings"
	"sync"
	"testing"

	"github.com/vantezzen/launchroom/daemon/internal/docker"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

const volumeID = state.VolumeID("vol_01J9Z8X7W6V5T4S3R2Q1P0N9MK")

// fakeVolumes is a Docker stand-in for volume backups: one service volume,
// containers that use it, and helper containers whose archive is Archive.
type fakeVolumes struct {
	mu        sync.Mutex
	Archive   string
	ReadErr   error
	Users     []docker.Container
	Events    []string
	Extracted string
	Helpers   map[string]bool
	ReadOnly  []bool
	// UsersFor counts down: the volume's users disappear after N lists.
	UsersFor int
}

func (f *fakeVolumes) log(e string) {
	f.mu.Lock()
	defer f.mu.Unlock()
	f.Events = append(f.Events, e)
}

func (f *fakeVolumes) ListContainers(_ context.Context, labels map[string]string) ([]docker.Container, error) {
	f.mu.Lock()
	defer f.mu.Unlock()
	if labels[docker.LabelHelper] == "volume" {
		var out []docker.Container
		for id := range f.Helpers {
			out = append(out, docker.Container{ID: id, Labels: map[string]string{docker.LabelHelper: "volume"}})
		}
		return out, nil
	}
	if f.UsersFor > 0 {
		f.UsersFor--
		if f.UsersFor == 0 {
			f.Users = nil
		}
	}
	return slices.Clone(f.Users), nil
}

func (f *fakeVolumes) ListVolumes(context.Context) ([]docker.Volume, error) {
	return []docker.Volume{
		{Name: "lr-system-caddy-data", Labels: map[string]string{docker.LabelSystem: "caddy", docker.LabelVolume: "caddy-data"}},
		{Name: "lr-v_0123456789abcdef", Labels: map[string]string{docker.LabelVolume: string(volumeID), docker.LabelService: "svc_1", docker.LabelEnvironment: "env_1"}},
		// Prepared for a service moving here (docs/14 §13.2): no owner yet.
		{Name: "lr-v_fedcba9876543210", Labels: map[string]string{docker.LabelVolume: string(preparedVolumeID)}},
		// A system volume dressed up with a workload volume id.
		{Name: "lr-system-buildkit-state", Labels: map[string]string{docker.LabelVolume: string(systemVolumeID)}},
	}, nil
}

const (
	preparedVolumeID state.VolumeID = "vol_01J9Z8X7W6V5T4S3R2Q1P0N9PR"
	systemVolumeID   state.VolumeID = "vol_01J9Z8X7W6V5T4S3R2Q1P0N9SY"
)

func TestFindVolumeAcceptsPreparedVolumesOnly(t *testing.T) {
	t.Parallel()
	h, _ := volumeHarness(t, nil)
	v, err := h.e.findVolume(context.Background(), preparedVolumeID)
	if err != nil || v.Name != "lr-v_fedcba9876543210" {
		t.Fatalf("prepared volume: %+v %v", v, err)
	}
	if _, err := h.e.findVolume(context.Background(), systemVolumeID); err == nil || !strings.Contains(err.Error(), "not a service volume") {
		t.Fatalf("system volume: %v", err)
	}
}

func (f *fakeVolumes) CreateVolume(_ context.Context, v docker.VolumeCreate) error {
	f.log("create-volume " + v.Name + " service=" + v.Labels[docker.LabelService])
	return nil
}

func (f *fakeVolumes) RemoveVolume(_ context.Context, name string) error {
	f.log("remove-volume " + name)
	return nil
}

func (f *fakeVolumes) CreateContainer(_ context.Context, cc *docker.ContainerCreate) (string, error) {
	f.mu.Lock()
	defer f.mu.Unlock()
	if f.Helpers == nil {
		f.Helpers = map[string]bool{}
	}
	if !strings.HasPrefix(cc.Name, "lr-vol-") || cc.Config.Labels[docker.LabelHelper] != "volume" || cc.HostConfig.Mounts[0].Target != "/data" {
		return "", errors.New("unexpected helper spec")
	}
	f.Helpers[cc.Name] = true
	f.ReadOnly = append(f.ReadOnly, cc.HostConfig.Mounts[0].ReadOnly)
	f.Events = append(f.Events, "create-helper "+cc.HostConfig.Mounts[0].Source)
	return cc.Name, nil
}

func (f *fakeVolumes) RemoveContainer(_ context.Context, id string) error {
	f.mu.Lock()
	defer f.mu.Unlock()
	delete(f.Helpers, id)
	f.Events = append(f.Events, "remove-helper")
	return nil
}

func (f *fakeVolumes) PauseContainer(_ context.Context, id string) error {
	f.log("pause " + id)
	return nil
}

func (f *fakeVolumes) UnpauseContainer(_ context.Context, id string) error {
	f.log("unpause " + id)
	return nil
}

func (f *fakeVolumes) StreamFromContainer(_ context.Context, id, src string) (io.ReadCloser, error) {
	f.log("read " + src)
	r := io.Reader(strings.NewReader(f.Archive))
	if f.ReadErr != nil {
		r = io.MultiReader(r, errReader{f.ReadErr})
	}
	return io.NopCloser(r), nil
}

func (f *fakeVolumes) StreamToContainer(_ context.Context, id, dst string, r io.Reader) error {
	data, err := io.ReadAll(r)
	f.mu.Lock()
	f.Extracted = string(data)
	f.Events = append(f.Events, "extract "+dst)
	f.mu.Unlock()
	return err
}

func (f *fakeVolumes) EnsureHelperImage(context.Context) error { return nil }

type errReader struct{ err error }

func (e errReader) Read([]byte) (int, error) { return 0, e.err }

func volumeHarness(t *testing.T, script map[string]fakeStep) (*harness, *fakeVolumes) {
	t.Helper()
	h := newHarness(t, script)
	v := &fakeVolumes{Archive: "TARSTREAM"}
	h.e.o.Volumes = v
	h.e.o.ServerID = "srv_1"
	h.e.o.PollEvery = 1
	return h, v
}

func volumeSpec(c state.VolumeConsistency) *state.BackupSpec {
	return &state.BackupSpec{
		Kind: state.BackupVolume, Target: state.BackupTarget{Volume: volumeID, Consistency: c},
		Destination: dest, Tags: []state.BackupTag{"kind:volume", "volume:" + state.BackupTag(volumeID)},
	}
}

func TestVolumeBackupStreamsTarIntoRestic(t *testing.T) {
	t.Parallel()
	h, v := volumeHarness(t, map[string]fakeStep{"backup": {Stdout: summaryLine}})
	v.Users = []docker.Container{{ID: "app", Running: true, Volumes: []string{"lr-v_0123456789abcdef"}}}
	res, err := h.e.Backup(context.Background(), volumeSpec(state.ConsistencyLive), creds, nil)
	if err != nil {
		t.Fatal(err)
	}
	if string(res.SnapshotID) != snapshotID || res.SizeBytes != int64(len("TARSTREAM")) {
		t.Fatalf("result = %+v", res)
	}
	b := h.calls()[1]
	joined := strings.Join(b.Args, " ")
	if b.Stdin != "TARSTREAM" || !strings.Contains(joined, "--stdin-filename volume.tar") || !strings.Contains(joined, "--tag volume:"+string(volumeID)) {
		t.Fatalf("restic call = %q stdin %q", joined, b.Stdin)
	}
	if !slices.Equal(v.ReadOnly, []bool{true}) {
		t.Fatalf("helper mounts = %v, want one read-only", v.ReadOnly)
	}
	for _, e := range v.Events {
		if strings.HasPrefix(e, "pause") {
			t.Fatalf("live backup paused the service: %v", v.Events)
		}
	}
	if len(v.Helpers) != 0 {
		t.Fatalf("helper left behind: %v", v.Helpers)
	}
}

func TestVolumeBackupPausesUsersOnlyWhileReading(t *testing.T) {
	t.Parallel()
	h, v := volumeHarness(t, map[string]fakeStep{"backup": {Stdout: summaryLine}})
	v.Users = []docker.Container{
		{ID: "app", Running: true, Volumes: []string{"lr-v_0123456789abcdef"}},
		{ID: "other", Running: true, Volumes: []string{"lr-v_other"}},
	}
	if _, err := h.e.Backup(context.Background(), volumeSpec(state.ConsistencyPause), creds, nil); err != nil {
		t.Fatal(err)
	}
	pause, read, unpause := slices.Index(v.Events, "pause app"), slices.Index(v.Events, "read /data"), slices.Index(v.Events, "unpause app")
	if pause < 0 || read < pause || unpause < read || slices.Contains(v.Events, "pause other") {
		t.Fatalf("events = %v", v.Events)
	}
	if strings.Count(strings.Join(v.Events, ","), "unpause app") != 1 {
		t.Fatalf("unpaused more than once: %v", v.Events)
	}
}

func TestVolumeBackupFailsAndForgetsWhenReadingBreaks(t *testing.T) {
	t.Parallel()
	h, v := volumeHarness(t, map[string]fakeStep{"backup": {Stdout: summaryLine}})
	v.ReadErr = errors.New("archive/tar: write too long")
	_, err := h.e.Backup(context.Background(), volumeSpec(state.ConsistencyLive), creds, nil)
	if err == nil || !strings.Contains(err.Error(), "reading the volume") {
		t.Fatalf("err = %v", err)
	}
	if got := h.subcommands(); !slices.Equal(got, []string{"cat", "backup", "forget"}) {
		t.Fatalf("restic calls = %v (the truncated snapshot must be forgotten)", got)
	}
}

func TestVolumeBackupRefusesUnknownVolume(t *testing.T) {
	t.Parallel()
	h, _ := volumeHarness(t, nil)
	spec := volumeSpec(state.ConsistencyLive)
	spec.Target.Volume = "vol_01J9Z8X7W6V5T4S3R2Q1P0N9ZZ"
	if _, err := h.e.Backup(context.Background(), spec, creds, nil); err == nil || !strings.Contains(err.Error(), "does not exist") {
		t.Fatalf("err = %v", err)
	}
	h.e.o.Volumes = nil
	if _, err := h.e.Backup(context.Background(), volumeSpec(state.ConsistencyLive), creds, nil); !errors.Is(err, ErrUnsupported) {
		t.Fatalf("err = %v", err)
	}
}

const volumeSnapshots = `[{"id":"` + snapshotID + `","time":"2026-10-18T10:00:00Z","paths":["/volume.tar"],"tags":["kind:volume"]}]`

func TestVolumeRestoreWaitsRecreatesAndExtracts(t *testing.T) {
	t.Parallel()
	archive := tarOf(t, tarEntry{Typeflag: tar.TypeDir, Name: "data/", Mode: 0o755}, tarEntry{Typeflag: tar.TypeReg, Name: "data/a.txt", Mode: 0o644, Body: "hi"})
	h, v := volumeHarness(t, map[string]fakeStep{"snapshots": {Stdout: volumeSnapshots}, "dump": {Stdout: archive}})
	v.Users = []docker.Container{{ID: "app", Volumes: []string{"lr-v_0123456789abcdef"}}}
	v.UsersFor = 3
	res, err := h.e.Restore(context.Background(), &state.RestoreSpec{
		Kind: state.BackupVolume, Snapshot: snapshotID, Mode: state.RestoreInPlace,
		Target: state.BackupTarget{Volume: volumeID}, Destination: dest,
	}, creds, nil)
	if err != nil {
		t.Fatal(err)
	}
	if res.SizeBytes != int64(len(archive)) || !slices.Equal(tarNames(t, v.Extracted), []string{"data/", "data/a.txt"}) {
		t.Fatalf("res %+v extracted %q", res, v.Extracted)
	}
	want := []string{"remove-volume lr-v_0123456789abcdef", "create-volume lr-v_0123456789abcdef service=svc_1", "create-helper lr-v_0123456789abcdef", "extract /", "remove-helper"}
	if !slices.Equal(v.Events, want) {
		t.Fatalf("events = %v", v.Events)
	}
	if !slices.Equal(v.ReadOnly, []bool{false}) {
		t.Fatalf("restore helper must mount read-write: %v", v.ReadOnly)
	}
}

func TestVolumeRestoreRefusesDatabaseSnapshotsAndBusyVolumes(t *testing.T) {
	t.Parallel()
	h, _ := volumeHarness(t, map[string]fakeStep{"snapshots": {Stdout: strings.ReplaceAll(volumeSnapshots, "/volume.tar", "/postgres.dump")}})
	spec := &state.RestoreSpec{Kind: state.BackupVolume, Snapshot: snapshotID, Mode: state.RestoreInPlace, Target: state.BackupTarget{Volume: volumeID}, Destination: dest}
	if _, err := h.e.Restore(context.Background(), spec, creds, nil); err == nil || !strings.Contains(err.Error(), "not a volume snapshot") {
		t.Fatalf("err = %v", err)
	}

	h, v := volumeHarness(t, map[string]fakeStep{"snapshots": {Stdout: volumeSnapshots}})
	v.Users = []docker.Container{{ID: "app", Running: true, Volumes: []string{"lr-v_0123456789abcdef"}}}
	h.e.o.VolumeIdleTimeout = 1
	if _, err := h.e.Restore(context.Background(), spec, creds, nil); err == nil || !strings.Contains(err.Error(), "still used") {
		t.Fatalf("err = %v", err)
	}
	if slices.Contains(v.Events, "remove-volume lr-v_0123456789abcdef") {
		t.Fatal("a busy volume must never be replaced")
	}
}
