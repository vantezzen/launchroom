package access

import (
	"archive/tar"
	"bytes"
	"context"
	"crypto/ed25519"
	"crypto/rand"
	"io"
	"os"
	"path/filepath"
	"strings"
	"sync/atomic"
	"testing"
	"time"

	"github.com/pkg/sftp"
	"golang.org/x/crypto/ssh"

	"github.com/vantezzen/launchroom/daemon/internal/docker"
	"github.com/vantezzen/launchroom/daemon/internal/docker/dockerfake"
	"github.com/vantezzen/launchroom/daemon/internal/policy"
	"github.com/vantezzen/launchroom/daemon/internal/state"
	"github.com/vantezzen/launchroom/daemon/internal/tasks"
)

const (
	testVolume     = state.VolumeID("vol_01J9Z8X7W6V5T4S3R2Q1P0N9V1")
	testVolumeName = "lr-v_0123456789abcdef"
	testService    = state.ServiceID("svc_01J9Z8X7W6V5T4S3R2Q1P0N9P2")
	testEnv        = state.EnvironmentID("env_01J9Z8X7W6V5T4S3R2Q1P0N9P3")
)

var testFilesImage = state.ImageRef("docker.io/library/busybox@" + policy.FilesImageDigest)

// volDocker is the fake Engine of volume access: execs into a helper run
// the toolbox (its /data is the volume), the archive of /data starts with
// the volume's top folder owned by 999:999.
type volDocker struct {
	*dockerfake.Fake
	box   *toolBox
	execs atomic.Int32
}

func (d *volDocker) ExecTTY(context.Context, string, docker.TTYOptions) (*docker.TTYExec, error) {
	return nil, io.ErrUnexpectedEOF
}
func (d *volDocker) PathExists(context.Context, string, string) (bool, error) { return true, nil }
func (d *volDocker) ExecStream(ctx context.Context, id string, o docker.ExecOptions) (docker.ExecResult, error) {
	c, err := d.InspectContainer(ctx, id)
	if err != nil || c.Labels[docker.LabelHelper] != HelperKind || c.State != "running" {
		return docker.ExecResult{}, io.ErrClosedPipe
	}
	d.execs.Add(1)
	code, stderr, err := d.box.Run(ctx, o.Argv, o.Stdin, o.Stdout)
	return docker.ExecResult{ExitCode: code, Stderr: stderr}, err
}
func (d *volDocker) StreamFromContainer(_ context.Context, _ string, src string) (io.ReadCloser, error) {
	var b bytes.Buffer
	tw := tar.NewWriter(&b)
	_ = tw.WriteHeader(&tar.Header{Typeflag: tar.TypeDir, Name: strings.TrimPrefix(src, "/") + "/", Mode: 0o755, Uid: 999, Gid: 999})
	_ = tw.Close()
	return io.NopCloser(&b), nil
}

func helpers(t *testing.T, d *volDocker) []docker.Container {
	t.Helper()
	cs, err := d.ListContainers(context.Background(), map[string]string{docker.LabelHelper: HelperKind})
	if err != nil {
		t.Fatal(err)
	}
	return cs
}

// TestVolumeAccessLifecycle: "Connect with SFTP" (D67) starts a helper of the
// pinned image with only the volume (read-only here), as the volume's
// owner, serves the volume as "/" over SFTP, refuses shells and exec, and
// removes the helper when the workload leaves the desired state.
func TestVolumeAccessLifecycle(t *testing.T) {
	h := newHarness(t)
	d := &volDocker{Fake: dockerfake.New(string(testServer)), box: newToolBox(t)}
	if err := os.MkdirAll(filepath.Join(d.box.root, "data"), 0o750); err != nil {
		t.Fatal(err)
	}
	_ = os.WriteFile(filepath.Join(d.box.root, "data", "hello.txt"), []byte("from the volume"), 0o644)
	labels := docker.ManagedLabels(string(testServer))
	labels[docker.LabelVolume], labels[docker.LabelService], labels[docker.LabelEnvironment] = string(testVolume), string(testService), string(testEnv)
	d.AddVolume(testVolumeName, labels)
	var gone atomic.Bool
	handler := Handler(Options{
		ServerID: testServer, SealingKey: h.key, Docker: d, Manager: h.mgr,
		Workload: func(id state.WorkloadID) (*state.WorkloadSpec, bool) {
			return &state.WorkloadSpec{ID: id, Service: testService, Environment: testEnv, Mounts: []state.Mount{{Volume: testVolume, Target: "/app/data"}}}, id == testWorkload && !gone.Load()
		},
		StatusEvery: 10 * time.Millisecond,
		Now:         h.clk.now,
	})
	pub, priv, _ := ed25519.GenerateKey(rand.Reader)
	signer, _ := ssh.NewSignerFromKey(priv)
	spec := &state.AccessSpec{
		Access: "acc_01J9Z8X7W6V5T4S3R2Q1P0N9A3", Kind: state.AccessVolume, Workload: testWorkload, User: testUser,
		AuthorizedKeys: []state.SSHPublicKey{pubKey(t, pub)}, AllowFrom: []state.AccessPrefix{"127.0.0.1/32"}, IdleTimeout: "15m",
		Volume: testVolume, Image: testFilesImage,
	}
	if err := spec.Validate(); err != nil {
		t.Fatal(err)
	}
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	ctx, hp := tasks.HandlerContext(ctx)
	task := state.Task{ID: "tsk_01J9Z8X7W6V5T4S3R2Q1P0N9S7", Type: state.TaskAccess, Attempt: 1, Deadline: h.clk.now().Add(time.Hour), Spec: spec}
	res, errc := runHandler(ctx, handler, task)
	st := probe{hp}.wait(t)

	if st.Owner == nil || *st.Owner != (state.FileOwner{UID: 999, GID: 999}) || st.HostKey == nil {
		t.Fatalf("status %+v", st)
	}
	if calls := strings.Join(d.Calls(), "\n"); !strings.Contains(calls, "pull "+string(testFilesImage)) {
		t.Errorf("the helper image was not pulled:\n%s", calls)
	}
	// Exactly one helper (the probe is gone), with the built-in shape.
	hs := helpers(t, d)
	if len(hs) != 1 || hs[0].State != "running" || hs[0].Labels[LabelAccess] != string(spec.Access) {
		t.Fatalf("helpers %+v", hs)
	}
	cc, _ := d.Create(hs[0].Name)
	hc := cc.HostConfig
	if cc.Config.User != "999:999" || cc.Config.Image != string(testFilesImage) || !cc.Config.NetworkDisabled || cc.Config.Entrypoint[0] != "sleep" ||
		hc.NetworkMode != "none" || !hc.ReadonlyRootfs || hc.CapDrop[0] != "ALL" || len(hc.Mounts) != 1 ||
		hc.Mounts[0].Source != testVolumeName || hc.Mounts[0].Target != HelperMount || !hc.Mounts[0].ReadOnly {
		t.Fatalf("helper spec %+v %+v", cc.Config, hc)
	}

	cl, err := ssh.Dial("tcp", "127.0.0.1:"+itoa(st.Port), &ssh.ClientConfig{User: testUser, Auth: []ssh.AuthMethod{ssh.PublicKeys(signer)}, HostKeyCallback: ssh.InsecureIgnoreHostKey(), Timeout: 5 * time.Second}) //nolint:gosec // test
	if err != nil {
		t.Fatal(err)
	}
	defer func() { _ = cl.Close() }()
	for _, try := range []func(*ssh.Session) error{
		func(s *ssh.Session) error { return s.Shell() },
		func(s *ssh.Session) error { return s.Start("cat /etc/passwd") },
	} {
		s, err := cl.NewSession()
		if err != nil {
			t.Fatal(err)
		}
		if err := try(s); err == nil {
			t.Error("a shell or command ran in a volume access")
		}
		_ = s.Close()
	}
	before := d.execs.Load()
	sc, err := sftp.NewClient(cl)
	if err != nil {
		t.Fatal(err)
	}
	r, err := sc.Open("/hello.txt")
	if err != nil {
		t.Fatal(err)
	}
	b, _ := io.ReadAll(r)
	_ = r.Close()
	if string(b) != "from the volume" || d.execs.Load() == before {
		t.Fatalf("read %q through %d execs", b, d.execs.Load()-before)
	}
	_ = sc.Close()

	// The service is held (a restore): the access ends, the helper goes.
	gone.Store(true)
	select {
	case r := <-res:
		if err := <-errc; err != nil || r.Access.EndReason != state.AccessEndEnded || r.Access.Connections < 1 {
			t.Fatalf("result %+v %v", r.Access, err)
		}
	case <-time.After(10 * time.Second):
		t.Fatal("the access did not end with its workload")
	}
	if hs := helpers(t, d); len(hs) != 0 {
		t.Fatalf("helpers left: %+v", hs)
	}
	if h.mgr.Running(spec.Access) {
		t.Fatal("the access still counts as running")
	}
}

// A volume the workload doesn't mount, or one of another service, is
// refused; an unpinned helper image too. Nothing is created.
func TestVolumeAccessRefusals(t *testing.T) {
	h := newHarness(t)
	pub, _, _ := ed25519.GenerateKey(rand.Reader)
	for name, c := range map[string]struct {
		mounts  []state.Mount
		labels  map[string]string
		image   state.ImageRef
		refused bool
	}{
		"not mounted":     {labels: map[string]string{docker.LabelService: string(testService)}, image: testFilesImage, refused: true},
		"another service": {mounts: []state.Mount{{Volume: testVolume, Target: "/d"}}, labels: map[string]string{docker.LabelService: "svc_01J9Z8X7W6V5T4S3R2Q1P0N9ZZ"}, image: testFilesImage, refused: true},
		"system volume":   {mounts: []state.Mount{{Volume: testVolume, Target: "/d"}}, labels: map[string]string{docker.LabelSystem: "caddy"}, image: testFilesImage, refused: true},
		"unpinned image":  {mounts: []state.Mount{{Volume: testVolume, Target: "/d"}}, image: state.ImageRef("docker.io/library/busybox@sha256:" + strings.Repeat("0", 64)), refused: true},
	} {
		t.Run(name, func(t *testing.T) {
			d := &volDocker{Fake: dockerfake.New(string(testServer)), box: newToolBox(t)}
			labels := docker.ManagedLabels(string(testServer))
			labels[docker.LabelVolume] = string(testVolume)
			for k, v := range c.labels {
				labels[k] = v
			}
			d.AddVolume(testVolumeName, labels)
			handler := Handler(Options{
				ServerID: testServer, SealingKey: h.key, Docker: d, Manager: h.mgr, Now: h.clk.now,
				Workload: func(id state.WorkloadID) (*state.WorkloadSpec, bool) {
					return &state.WorkloadSpec{ID: id, Service: testService, Environment: testEnv, Mounts: c.mounts}, true
				},
			})
			spec := &state.AccessSpec{
				Access: "acc_01J9Z8X7W6V5T4S3R2Q1P0N9A3", Kind: state.AccessVolume, Workload: testWorkload, User: testUser,
				AuthorizedKeys: []state.SSHPublicKey{pubKey(t, pub)}, AllowFrom: []state.AccessPrefix{"127.0.0.1/32"}, IdleTimeout: "15m",
				Volume: testVolume, Image: c.image,
			}
			r, err := handler(context.Background(), state.Task{ID: "tsk_01J9Z8X7W6V5T4S3R2Q1P0N9S7", Type: state.TaskAccess, Attempt: 1, Deadline: h.clk.now().Add(time.Hour), Spec: spec})
			if err == nil || (r.Access.EndReason == state.AccessEndRefused) != c.refused {
				t.Fatalf("%v %+v", err, r.Access)
			}
			if hs := helpers(t, d); len(hs) != 0 {
				t.Fatalf("helpers %+v", hs)
			}
		})
	}
}

// Sweeping removes helpers no running access owns, never claimed ones.
func TestSweepHelpers(t *testing.T) {
	d := dockerfake.New(string(testServer))
	d.AddImage(string(testFilesImage))
	mk := func(access state.AccessID) string {
		id, err := d.CreateContainer(context.Background(), HelperSpec(testServer, access, testFilesImage, testVolumeName, "0:0", true, time.Hour))
		if err != nil {
			t.Fatal(err)
		}
		return id
	}
	live, stale := mk("acc_01J9Z8X7W6V5T4S3R2Q1P0N9A1"), mk("acc_01J9Z8X7W6V5T4S3R2Q1P0N9A2")
	m := NewManager(ManagerOptions{})
	release := m.Claim("acc_01J9Z8X7W6V5T4S3R2Q1P0N9A1")
	SweepHelpers(context.Background(), d, m.Running)
	if _, err := d.InspectContainer(context.Background(), live); err != nil {
		t.Fatalf("claimed helper removed: %v", err)
	}
	if _, err := d.InspectContainer(context.Background(), stale); err == nil {
		t.Fatal("stale helper kept")
	}
	release()
	release()
	if m.Running("acc_01J9Z8X7W6V5T4S3R2Q1P0N9A1") {
		t.Fatal("released access still running")
	}
}

func pubKey(t *testing.T, k ed25519.PublicKey) state.SSHPublicKey {
	t.Helper()
	pk, err := ssh.NewPublicKey(k)
	if err != nil {
		t.Fatal(err)
	}
	return state.SSHPublicKey(strings.TrimSpace(string(ssh.MarshalAuthorizedKey(pk))))
}
