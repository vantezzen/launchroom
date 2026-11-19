//go:build integration

package integration

import (
	"archive/tar"
	"bytes"
	"context"
	"crypto/ecdh"
	"crypto/ed25519"
	"crypto/rand"
	"encoding/hex"
	"encoding/pem"
	"flag"
	"fmt"
	"io"
	"net"
	"os"
	"regexp"
	"runtime/pprof"
	"strconv"
	"strings"
	"testing"
	"time"

	"github.com/moby/moby/api/pkg/stdcopy"
	"github.com/moby/moby/api/types/container"
	"github.com/moby/moby/api/types/mount"
	"github.com/moby/moby/client"
	"github.com/pkg/sftp"
	"golang.org/x/crypto/ssh"

	"github.com/vantezzen/launchroom/daemon/internal/access"
	"github.com/vantezzen/launchroom/daemon/internal/docker"
	"github.com/vantezzen/launchroom/daemon/internal/names"
	"github.com/vantezzen/launchroom/daemon/internal/state"
	"github.com/vantezzen/launchroom/daemon/internal/tasks"
)

// The SFTP throughput benchmark is off by default (it moves gigabytes):
//
//	make test-daemon-integration ITEST=sftp ARGS='-test.run TestVolumeAccessSFTPThroughput -lr.sftp-bench-mb=1024'
var (
	sftpBenchMB    = flag.Int("lr.sftp-bench-mb", 0, "run TestVolumeAccessSFTPThroughput with this many MiB per upload")
	sftpBenchPprof = flag.String("lr.sftp-bench-pprof", "", "write a CPU profile of the OpenSSH upload (no added latency) here")
	sftpBenchRTT   = flag.Duration("lr.sftp-bench-rtt", 40*time.Millisecond, "round-trip time the latency proxy adds")
	sftpBenchOnly  = flag.String("lr.sftp-bench-only", "", "run only the measurements whose name matches this regexp")
)

// TestVolumeAccessSFTPThroughput measures uploads into a volume through the
// real path (SSH front end, SFTP request server, file tools in the access
// helper through dockerd-guard) with OpenSSH's sftp and pkg/sftp's client,
// directly and through a proxy that adds a round-trip time, next to the
// baselines below it: the helper's disk, the service container's disk and
// one exec stream through the guard.
func TestVolumeAccessSFTPThroughput(t *testing.T) {
	if *sftpBenchMB <= 0 {
		t.Skip("a benchmark: pass -lr.sftp-bench-mb=<MiB>")
	}
	size := int64(*sftpBenchMB) << 20
	api, err := client.New(client.FromEnv)
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { _ = api.Close() })
	sweep(t, api)
	t.Cleanup(func() { sweep(t, api) })
	s := &sessionEnv{serverID: state.ServerID(randomID("srv"))}
	if s.key, err = ecdh.X25519().GenerateKey(rand.Reader); err != nil {
		t.Fatal(err)
	}
	if s.engine, err = docker.NewEngine(docker.EngineOptions{Host: engineHost, ServerID: string(s.serverID), UserAgent: "launchroom-itest"}); err != nil {
		t.Fatal(err)
	}
	ctx, cancel := context.WithTimeout(context.Background(), 60*time.Minute)
	defer cancel()
	for _, img := range []string{postgresImage, busyboxImage} {
		if err := s.engine.PullImage(ctx, img); err != nil {
			t.Fatal(err)
		}
	}
	fx := fixtureEngine(t, s.serverID)

	volID := state.VolumeID(randomID("vol"))
	volName := "lr-v_" + hex.EncodeToString([]byte(access.RandomName(8)))
	svc, env := state.ServiceID(randomID("svc")), state.EnvironmentID(randomID("env"))
	vl := docker.ManagedLabels(string(s.serverID))
	vl[names.LabelVolume], vl[names.LabelService], vl[names.LabelEnvironment] = string(volID), string(svc), string(env)
	if err := fx.CreateVolume(ctx, docker.VolumeCreate{Name: volName, Labels: vl}); err != nil {
		t.Fatal(err)
	}
	wl := state.WorkloadID(randomID("wl"))
	labels := docker.ManagedLabels(string(s.serverID))
	labels[names.LabelWorkload] = string(wl)
	svcID, err := fx.CreateContainer(ctx, &docker.ContainerCreate{
		Name:       names.WorkloadContainer(wl),
		Config:     &container.Config{Image: busyboxImage, Labels: labels, Cmd: []string{"sleep", "3600"}},
		HostConfig: &container.HostConfig{Mounts: []mount.Mount{{Type: mount.TypeVolume, Source: volName, Target: "/app/data"}}},
	})
	if err != nil {
		t.Fatal(err)
	}
	if err := fx.StartContainer(ctx, svcID); err != nil {
		t.Fatal(err)
	}

	mgr := access.NewManager(access.ManagerOptions{DataDir: t.TempDir()})
	h := access.Handler(access.Options{
		ServerID: s.serverID, SealingKey: s.key, Docker: s.engine, Manager: mgr,
		Workload: func(id state.WorkloadID) (*state.WorkloadSpec, bool) {
			return &state.WorkloadSpec{ID: id, Service: svc, Environment: env, Mounts: []state.Mount{{Volume: volID, Target: "/app/data"}}}, id == wl
		},
	})
	_, priv, _ := ed25519.GenerateKey(rand.Reader)
	signer, _ := ssh.NewSignerFromKey(priv)
	block, err := ssh.MarshalPrivateKey(priv, "")
	if err != nil {
		t.Fatal(err)
	}
	user := "lr-" + access.RandomName(10)
	spec := &state.AccessSpec{
		Access: state.AccessID(randomID("acc")), Kind: state.AccessVolume, Workload: wl, User: state.AccessUser(user),
		AuthorizedKeys: []state.SSHPublicKey{state.SSHPublicKey(strings.TrimSpace(string(ssh.MarshalAuthorizedKey(signer.PublicKey()))))},
		AllowFrom:      []state.AccessPrefix{"127.0.0.1/32"}, IdleTimeout: "30m", AllowWrites: true,
		Volume: volID, Image: state.ImageRef(busyboxImage),
	}
	if err := spec.Validate(); err != nil {
		t.Fatal(err)
	}
	actx, stop := context.WithCancel(ctx)
	defer stop()
	hctx, probe := tasks.HandlerContext(actx)
	done := make(chan struct{})
	go func() {
		defer close(done)
		_, _ = h(hctx, state.Task{ID: state.TaskID(randomID("tsk")), Type: state.TaskAccess, Attempt: 1, Deadline: time.Now().Add(50 * time.Minute), Spec: spec})
	}()
	defer func() { stop(); <-done }()
	var st *state.AccessStatus
	for st == nil {
		select {
		case <-probe.Heartbeats():
			if r := probe.Reporter(); r != nil {
				var ack func(bool)
				st, ack = r.HeartbeatStatus()
				ack(false)
			}
		case <-done:
			t.Fatal("access ended before listening")
		case <-time.After(3 * time.Minute):
			t.Fatal("access never listened")
		}
	}
	f := client.Filters{}
	f.Add("label", names.LabelHelper+"="+names.HelperAccess)
	hs, err := api.ContainerList(ctx, client.ContainerListOptions{Filters: f})
	if err != nil || len(hs.Items) != 1 {
		t.Fatalf("helpers %v %v", hs, err)
	}
	helper := hs.Items[0].ID

	slow := latencyProxy(t, st.Port, *sftpBenchRTT)
	report := func(name string, d time.Duration) {
		t.Logf("%-44s %7.1f MB/s  (%.1fs)", name, float64(size)/1e6/d.Seconds(), d.Seconds())
	}
	clean := func() {
		execOut(ctx, t, api, svcID, "find", "/app/data", "-maxdepth", "1", "-name", "bench-*", "-delete")
	}
	only := regexp.MustCompile(*sftpBenchOnly)

	// Baselines.
	mb := strconv.Itoa(*sftpBenchMB)
	if only.MatchString("baseline") {
		start := time.Now()
		execOut(ctx, t, api, helper, "dd", "if=/dev/zero", "of=/data/bench-disk", "bs=1048576", "count="+mb)
		report("baseline: dd in the helper", time.Since(start))
		clean()
		start = time.Now()
		execOut(ctx, t, api, svcID, "dd", "if=/dev/zero", "of=/app/data/bench-disk", "bs=1048576", "count="+mb)
		report("baseline: dd in the service container", time.Since(start))
		clean()
		start = time.Now()
		res, err := s.engine.ExecStream(ctx, helper, docker.ExecOptions{Argv: []string{"dd", "of=/data/bench-exec", "bs=65536"}, Stdin: io.LimitReader(newPattern(), size)})
		if err != nil || res.ExitCode != 0 {
			t.Fatalf("exec stream: %v %+v", err, res)
		}
		report("baseline: dd exec stream through the guard", time.Since(start))
		clean()
	}

	// The OpenSSH client: a long-running container on the host network.
	cl, err := api.ContainerCreate(ctx, client.ContainerCreateOptions{
		Config: &container.Config{
			Image: postgresImage, Entrypoint: []string{"sleep", "3600"}, Labels: map[string]string{"launchroom.dev": "true"},
		},
		HostConfig: &container.HostConfig{NetworkMode: "host"},
	})
	if err != nil {
		t.Fatal(err)
	}
	defer func() {
		_, _ = api.ContainerRemove(context.WithoutCancel(ctx), cl.ID, client.ContainerRemoveOptions{Force: true})
	}()
	if _, err := api.ContainerStart(ctx, cl.ID, client.ContainerStartOptions{}); err != nil {
		t.Fatal(err)
	}
	// The client's key and known hosts go in through the archive API, the
	// rest as argv: no shell strings (docs/09 R1).
	known := fmt.Sprintf("[127.0.0.1]:%d %s\n[127.0.0.1]:%d %s\n", st.Port, st.HostKey.PublicKey, slow, st.HostKey.PublicKey)
	var files bytes.Buffer
	tw := tar.NewWriter(&files)
	for _, f := range []struct {
		name string
		mode int64
		data []byte
	}{{"id", 0o600, pem.EncodeToMemory(block)}, {"kh", 0o644, []byte(known)}} {
		_ = tw.WriteHeader(&tar.Header{Name: f.name, Mode: f.mode, Size: int64(len(f.data)), ModTime: time.Unix(0, 0)})
		_, _ = tw.Write(f.data)
	}
	_ = tw.Close()
	if _, err := api.CopyToContainer(ctx, cl.ID, client.CopyToContainerOptions{DestinationPath: "/tmp", Content: &files}); err != nil {
		t.Fatal(err)
	}
	execOut(ctx, t, api, cl.ID, "apk", "add", "--no-cache", "-q", "openssh-client")
	execOut(ctx, t, api, cl.ID, "dd", "if=/dev/urandom", "of=/tmp/big", "bs=1048576", "count="+mb)
	// A stock OpenSSH sshd (internal-sftp) in a container on the host
	// network: what the same client gets from a plain server.
	stockPort := 22000 + int(time.Now().UnixNano()%1000)
	pub := strings.TrimSpace(string(ssh.MarshalAuthorizedKey(signer.PublicKey())))
	sd, err := api.ContainerCreate(ctx, client.ContainerCreateOptions{
		Config: &container.Config{
			Image: postgresImage, Labels: map[string]string{"launchroom.dev": "true"}, Entrypoint: []string{"sleep", "3600"},
		},
		HostConfig: &container.HostConfig{NetworkMode: "host"},
	})
	if err != nil {
		t.Fatal(err)
	}
	defer func() {
		_, _ = api.ContainerRemove(context.WithoutCancel(ctx), sd.ID, client.ContainerRemoveOptions{Force: true})
	}()
	if _, err := api.ContainerStart(ctx, sd.ID, client.ContainerStartOptions{}); err != nil {
		t.Fatal(err)
	}
	// Set up and start sshd step by step with argv and the archive API: no
	// shell strings (docs/09 R1). sshd without -D detaches; sleep keeps the
	// container alive.
	execOut(ctx, t, api, sd.ID, "apk", "add", "--no-cache", "-q", "openssh-server")
	execOut(ctx, t, api, sd.ID, "ssh-keygen", "-A")
	execOut(ctx, t, api, sd.ID, "sed", "-i", "s/^root:[^:]*:/root:*:/", "/etc/shadow")
	var keys bytes.Buffer
	ktw := tar.NewWriter(&keys)
	_ = ktw.WriteHeader(&tar.Header{Typeflag: tar.TypeDir, Name: ".ssh/", Mode: 0o700, ModTime: time.Unix(0, 0)})
	_ = ktw.WriteHeader(&tar.Header{Name: ".ssh/authorized_keys", Mode: 0o600, Size: int64(len(pub) + 1), ModTime: time.Unix(0, 0)})
	_, _ = ktw.Write([]byte(pub + "\n"))
	_ = ktw.Close()
	if _, err := api.CopyToContainer(ctx, sd.ID, client.CopyToContainerOptions{DestinationPath: "/root", Content: &keys}); err != nil {
		t.Fatal(err)
	}
	execOut(ctx, t, api, sd.ID, "/usr/sbin/sshd", "-e", "-p", strconv.Itoa(stockPort), "-o", "ListenAddress=127.0.0.1",
		"-o", "PermitRootLogin=prohibit-password", "-o", "Subsystem sftp internal-sftp")
	stockSlow := latencyProxy(t, stockPort, *sftpBenchRTT)
	for i := 0; ; i++ {
		c, err := (&net.Dialer{Timeout: time.Second}).DialContext(ctx, "tcp", "127.0.0.1:"+strconv.Itoa(stockPort))
		if err == nil {
			_ = c.Close()
			break
		}
		if i > 120 {
			t.Fatal("the stock sshd never listened")
		}
		time.Sleep(500 * time.Millisecond)
	}

	type target struct {
		user, dir string
		port      int
		check     string
	}
	ours := func(port int) target { return target{user: user, dir: "/", port: port, check: "yes"} }
	stock := func(port int) target { return target{user: "root", dir: "/tmp/", port: port, check: "no"} }
	openssh := func(name string, tg target, args ...string) {
		if !only.MatchString(name) {
			return
		}
		argv := append([]string{"sftp", "-i", "/tmp/id", "-o", "UserKnownHostsFile=/tmp/kh", "-o", "StrictHostKeyChecking=" + tg.check, "-o", "BatchMode=yes", "-o", "IdentitiesOnly=yes", "-P", strconv.Itoa(tg.port)}, args...)
		remote := tg.dir + "bench-" + access.RandomName(6)
		// The sftp batch file goes in through the archive API (no shell
		// strings, docs/09 R1).
		batch := "put /tmp/big " + remote + "\n"
		var bf bytes.Buffer
		btw := tar.NewWriter(&bf)
		_ = btw.WriteHeader(&tar.Header{Name: "batch", Mode: 0o644, Size: int64(len(batch)), ModTime: time.Unix(0, 0)})
		_, _ = btw.Write([]byte(batch))
		_ = btw.Close()
		if _, err := api.CopyToContainer(ctx, cl.ID, client.CopyToContainerOptions{DestinationPath: "/tmp", Content: &bf}); err != nil {
			t.Fatal(err)
		}
		argv = append(argv, "-b", "/tmp/batch", tg.user+"@127.0.0.1")
		start := time.Now()
		out := execOut(ctx, t, api, cl.ID, argv...)
		d := time.Since(start)
		if strings.Contains(out, "rror") || strings.Contains(out, "denied") || strings.Contains(out, "nvalid") || d < 300*time.Millisecond {
			t.Errorf("%s: %s", name, out)
		}
		report(name, d)
		clean()
		if tg.user == "root" {
			execOut(ctx, t, api, sd.ID, "rm", "-f", remote)
		}
	}
	if *sftpBenchPprof != "" {
		pf, err := os.Create(*sftpBenchPprof)
		if err != nil {
			t.Fatal(err)
		}
		if err := pprof.StartCPUProfile(pf); err != nil {
			t.Fatal(err)
		}
		openssh("OpenSSH sftp (profiled)", ours(st.Port))
		pprof.StopCPUProfile()
		_ = pf.Close()
	}
	rtt := sftpBenchRTT.String()
	big := []string{"-R", "256", "-B", "261120"}
	openssh("stock sshd: OpenSSH sftp", stock(stockPort))
	openssh("stock sshd: OpenSSH sftp +"+rtt, stock(stockSlow))
	openssh("stock sshd: OpenSSH sftp -R 256 -B 255K +"+rtt, stock(stockSlow), big...)
	openssh("OpenSSH sftp", ours(st.Port))
	openssh("OpenSSH sftp aes128-gcm", ours(st.Port), "-c", "aes128-gcm@openssh.com")
	openssh("OpenSSH sftp -R 256 -B 255K", ours(st.Port), big...)
	openssh("OpenSSH sftp +"+rtt, ours(slow))
	openssh("OpenSSH sftp -R 256 -B 255K +"+rtt, ours(slow), big...)
	openssh("OpenSSH sftp -R 1 (no pipelining) +"+rtt, ours(slow), "-R", "1")
	openssh("stock sshd: OpenSSH sftp -R 1 (no pipelining) +"+rtt, stock(stockSlow), "-R", "1")

	pkgsftp := func(name string, port, packet, conc int) {
		if !only.MatchString(name) {
			return
		}
		hk, _, _, _, err := ssh.ParseAuthorizedKey([]byte(st.HostKey.PublicKey))
		if err != nil {
			t.Fatal(err)
		}
		start := time.Now()
		if err := pkgSFTPUpload(ctx, "127.0.0.1:"+strconv.Itoa(port), user, signer, hk, size, packet, conc); err != nil {
			t.Errorf("%s: %v", name, err)
		}
		d := time.Since(start)
		report(name, d)
		clean()
	}
	pkgsftp("pkg/sftp 64 x 32 KiB", st.Port, 32<<10, 64)
	pkgsftp("pkg/sftp 64 x 256 KiB", st.Port, 256<<10-1024, 64)
	pkgsftp("pkg/sftp 64 x 32 KiB +"+rtt, slow, 32<<10, 64)
	pkgsftp("pkg/sftp 64 x 256 KiB +"+rtt, slow, 256<<10-1024, 64)
}

// pkgSFTPUpload uploads size bytes with pkg/sftp's client, conc requests
// of packet bytes in flight.
func pkgSFTPUpload(ctx context.Context, addr, user string, key ssh.Signer, hk ssh.PublicKey, size int64, packet, conc int) error {
	var d net.Dialer
	nc, err := d.DialContext(ctx, "tcp", addr)
	if err != nil {
		return err
	}
	cc, chans, reqs, err := ssh.NewClientConn(nc, addr, &ssh.ClientConfig{User: user, Auth: []ssh.AuthMethod{ssh.PublicKeys(key)}, HostKeyCallback: ssh.FixedHostKey(hk)})
	if err != nil {
		_ = nc.Close()
		return err
	}
	c := ssh.NewClient(cc, chans, reqs)
	defer func() { _ = c.Close() }()
	sc, err := sftp.NewClient(c, sftp.UseConcurrentWrites(true), sftp.MaxConcurrentRequestsPerFile(conc), sftp.MaxPacketUnchecked(packet))
	if err != nil {
		return err
	}
	defer func() { _ = sc.Close() }()
	f, err := sc.Create("/bench-pkg-" + access.RandomName(6))
	if err != nil {
		return err
	}
	if n, err := f.ReadFrom(&io.LimitedReader{R: newPattern(), N: size}); err != nil || n != size {
		return fmt.Errorf("upload: %d %w", n, err)
	}
	return f.Close()
}

// pattern repeats 1 MiB of random bytes (SSH encrypts it either way).
type pattern struct {
	b   []byte
	off int
}

func newPattern() *pattern {
	b := make([]byte, 1<<20)
	_, _ = rand.Read(b)
	return &pattern{b: b}
}

func (p *pattern) Read(b []byte) (int, error) {
	n := copy(b, p.b[p.off:])
	p.off = (p.off + n) % len(p.b)
	return n, nil
}

// latencyProxy forwards 127.0.0.1:<returned port> to port, delaying every
// chunk by rtt/2 in each direction without limiting the bandwidth.
func latencyProxy(t *testing.T, port int, rtt time.Duration) int {
	t.Helper()
	var lc net.ListenConfig
	l, err := lc.Listen(context.Background(), "tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { _ = l.Close() })
	go func() {
		for {
			c, err := l.Accept()
			if err != nil {
				return
			}
			go func() {
				var d net.Dialer
				up, err := d.DialContext(context.Background(), "tcp", "127.0.0.1:"+strconv.Itoa(port))
				if err != nil {
					_ = c.Close()
					return
				}
				doneCh := make(chan struct{}, 2)
				go func() { delayCopy(up, c, rtt/2); doneCh <- struct{}{} }()
				go func() { delayCopy(c, up, rtt/2); doneCh <- struct{}{} }()
				<-doneCh
				<-doneCh
				_ = c.Close()
				_ = up.Close()
			}()
		}
	}()
	return l.Addr().(*net.TCPAddr).Port //nolint:forcetypeassert // a TCP listener
}

func delayCopy(dst, src net.Conn, d time.Duration) {
	type chunk struct {
		b   []byte
		due time.Time
	}
	ch := make(chan chunk, 16384)
	go func() {
		defer close(ch)
		for {
			b := make([]byte, 64<<10)
			n, err := src.Read(b)
			if n > 0 {
				ch <- chunk{b[:n], time.Now().Add(d)}
			}
			if err != nil {
				return
			}
		}
	}()
	for c := range ch {
		if w := time.Until(c.due); w > 0 {
			time.Sleep(w)
		}
		if _, err := dst.Write(c.b); err != nil {
			break
		}
	}
	if tc, ok := dst.(*net.TCPConn); ok {
		_ = tc.CloseWrite()
	}
	for range ch { //nolint:revive // drain
	}
}

// execOut runs argv in a container (raw API) and returns its output.
func execOut(ctx context.Context, t *testing.T, api *client.Client, id string, argv ...string) string {
	t.Helper()
	res, err := api.ExecCreate(ctx, id, client.ExecCreateOptions{Cmd: argv, AttachStdout: true, AttachStderr: true})
	if err != nil {
		t.Fatal(err)
	}
	att, err := api.ExecAttach(ctx, res.ID, client.ExecAttachOptions{})
	if err != nil {
		t.Fatal(err)
	}
	defer att.Close()
	var out bytes.Buffer
	_, _ = stdcopy.StdCopy(&out, &out, att.Reader)
	return out.String()
}
