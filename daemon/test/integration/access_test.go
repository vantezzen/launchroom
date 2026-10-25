//go:build integration

package integration

import (
	"context"
	"crypto/ecdh"
	"crypto/ed25519"
	"crypto/rand"
	"encoding/hex"
	"encoding/pem"
	"fmt"
	"net"
	"os"
	"strconv"
	"strings"
	"testing"
	"time"

	"github.com/moby/moby/api/types/container"
	"github.com/moby/moby/api/types/mount"
	"github.com/moby/moby/client"
	"golang.org/x/crypto/ssh"

	"github.com/vantezzen/launchroom/daemon/internal/access"
	"github.com/vantezzen/launchroom/daemon/internal/docker"
	"github.com/vantezzen/launchroom/daemon/internal/sealed"
	"github.com/vantezzen/launchroom/daemon/internal/secret"
	"github.com/vantezzen/launchroom/daemon/internal/state"
	"github.com/vantezzen/launchroom/daemon/internal/tasks"
)

// TestTemporaryAccess runs "Connect via SSH" and temporary database access
// (D61) against real containers through dockerd-guard, with real clients:
// OpenSSH ssh and sftp, and psql, from a container on the host network.
func TestTemporaryAccess(t *testing.T) {
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
	ctx, cancel := context.WithTimeout(context.Background(), 15*time.Minute)
	defer cancel()
	// The client image: postgres:18-alpine has psql and apk for openssh.
	if err := s.engine.PullImage(ctx, postgresImage); err != nil {
		t.Fatal(err)
	}
	mgr := access.NewManager(access.ManagerOptions{DataDir: t.TempDir()})
	pgEnv := map[string]secret.Secret{"POSTGRES_USER": "app", "POSTGRES_DB": "app", "POSTGRES_PASSWORD": "pw-session-123456"}

	run := func(t *testing.T, spec *state.AccessSpec, password string, env map[string]secret.Secret) (*state.AccessStatus, context.CancelFunc, chan tasks.Result) {
		t.Helper()
		task := state.TaskID(randomID("tsk"))
		if password != "" {
			pw, err := sealed.SealJSON(state.TaskToken{ServerID: s.serverID, TaskID: task, Purpose: state.PurposeAccess, Token: secret.Secret(password)}, s.key.PublicKey(), rand.Reader)
			if err != nil {
				t.Fatal(err)
			}
			spec.Password = &pw
		}
		if err := spec.Validate(); err != nil {
			t.Fatal(err)
		}
		h := access.Handler(access.Options{
			ServerID: s.serverID, SealingKey: s.key, Docker: s.engine, Manager: mgr,
			Workload: func(id state.WorkloadID) (*state.WorkloadSpec, bool) {
				return &state.WorkloadSpec{ID: id}, id == spec.Workload
			},
			WorkloadEnv: func(state.WorkloadID) (map[string]secret.Secret, error) { return env, nil },
		})
		actx, stop := context.WithCancel(ctx)
		hctx, probe := tasks.HandlerContext(actx)
		done := make(chan tasks.Result, 1)
		go func() {
			r, err := h(hctx, state.Task{ID: task, Type: state.TaskAccess, Attempt: 1, Deadline: time.Now().Add(10 * time.Minute), Spec: spec})
			if err != nil {
				t.Logf("access handler: %v", err)
			}
			done <- r
		}()
		deadline := time.After(2 * time.Minute)
		for {
			select {
			case <-probe.Heartbeats():
				if r := probe.Reporter(); r != nil {
					st, ack := r.HeartbeatStatus()
					ack(false)
					return st, stop, done
				}
			case r := <-done:
				t.Fatalf("access ended before listening: %+v", r.Access)
			case <-deadline:
				t.Fatal("access never listened")
			}
		}
	}

	t.Run("ssh and sftp with OpenSSH", func(t *testing.T) {
		wl := s.start(ctx, t, busyboxImage, nil, []string{"sleep", "3600"})
		_, priv, _ := ed25519.GenerateKey(rand.Reader)
		signer, _ := ssh.NewSignerFromKey(priv)
		user := "lr-" + access.RandomName(10)
		spec := &state.AccessSpec{
			Access: state.AccessID(randomID("acc")), Kind: state.AccessSSH, Workload: wl, User: state.AccessUser(user),
			AuthorizedKeys: []state.SSHPublicKey{state.SSHPublicKey(strings.TrimSpace(string(ssh.MarshalAuthorizedKey(signer.PublicKey()))))},
			AllowFrom:      []state.AccessPrefix{"127.0.0.1/32"}, IdleTimeout: "5m",
		}
		st, stop, done := run(t, spec, "", nil)
		if st.HostKey == nil || st.Port < 40100 || st.Port > 40199 {
			t.Fatalf("status %+v", st)
		}
		block, err := ssh.MarshalPrivateKey(priv, "")
		if err != nil {
			t.Fatal(err)
		}
		knownHosts := fmt.Sprintf("[127.0.0.1]:%d %s", st.Port, st.HostKey.PublicKey)
		script := strings.Join([]string{
			"set -e",
			"apk add --no-cache -q openssh-client >/dev/null",
			`printf '%s\n' "$KEY" > /tmp/id && chmod 600 /tmp/id`,
			`printf '%s\n' "$KNOWN" > /tmp/kh`,
			`O="-i /tmp/id -o UserKnownHostsFile=/tmp/kh -o StrictHostKeyChecking=yes -o BatchMode=yes -o IdentitiesOnly=yes"`,
			`ssh $O -p $PORT $USER@127.0.0.1 'echo hello-$((40+2)); id -u; exit 4' || echo "ssh-exit=$?"`,
			`printf 'payload-%s\n' 12345 > /tmp/up.txt`,
			`printf 'mkdir /srv\nput /tmp/up.txt /srv/up.txt\nget /srv/up.txt /tmp/down.txt\nls /srv\nget /proc/self/environ /tmp/env\n' > /tmp/batch`,
			`sftp $O -P $PORT -b /tmp/batch $USER@127.0.0.1 || echo "sftp-partial"`,
			`cat /tmp/down.txt`,
			`test ! -e /tmp/env && echo proc-refused`,
			// A forwarding attempt is refused.
			`ssh $O -p $PORT -W 127.0.0.1:22 $USER@127.0.0.1 </dev/null 2>&1 | grep -qi 'prohibited' && echo forward-refused`,
		}, "\n")
		out := runClient(ctx, t, api, []string{"KEY=" + string(pem.EncodeToMemory(block)), "KNOWN=" + knownHosts, "PORT=" + strconv.Itoa(st.Port), "USER=" + user}, script)
		for _, want := range []string{"hello-42", "ssh-exit=4", "payload-12345", "up.txt", "proc-refused", "forward-refused"} {
			if !strings.Contains(out, want) {
				t.Errorf("client output lacks %q:\n%s", want, out)
			}
		}
		// The file is in the container, written as the container's user.
		cs, _ := s.engine.ListContainers(ctx, map[string]string{docker.LabelWorkload: string(wl)})
		var buf strings.Builder
		if _, err := s.engine.ExecStream(ctx, cs[0].ID, docker.ExecOptions{Argv: []string{"cat", "/srv/up.txt"}, Stdout: &buf}); err != nil || buf.String() != "payload-12345\n" {
			t.Fatalf("file in the container: %q %v", buf.String(), err)
		}
		stop()
		r := <-done
		if r.Access == nil || r.Access.EndReason != state.AccessEndEnded || r.Access.Connections < 2 {
			t.Fatalf("result %+v", r.Access)
		}
		assertClosed(ctx, t, st.Port)
	})

	t.Run("postgres with psql", func(t *testing.T) {
		var kv []string
		for k, v := range pgEnv {
			kv = append(kv, k+"="+v.Reveal())
		}
		wl := s.start(ctx, t, postgresImage, kv, nil)
		waitPG(ctx, t, s.engine, wl)
		cs, _ := s.engine.ListContainers(ctx, map[string]string{docker.LabelWorkload: string(wl)})
		db := cs[0].ID
		psqlAsApp := func(sql string) string {
			var b strings.Builder
			_, _ = s.engine.ExecStream(ctx, db, docker.ExecOptions{Argv: []string{"psql", "--host=127.0.0.1", "--username=app", "--dbname=app", "-tAc", sql}, Env: []string{"PGPASSWORD=pw-session-123456"}, Stdout: &b})
			return strings.TrimSpace(b.String())
		}
		psqlAsApp("CREATE TABLE notes (id int); INSERT INTO notes VALUES (1), (2)")
		role := "lr_tmp_" + access.RandomName(12)
		password := "pw-" + access.RandomName(29)
		spec := &state.AccessSpec{
			Access: state.AccessID(randomID("acc")), Kind: state.AccessDatabase, Workload: wl, Engine: state.EnginePostgres,
			User: state.AccessUser(role), AllowFrom: []state.AccessPrefix{"127.0.0.1/32"}, Hosts: []state.AccessHost{"127.0.0.1"}, IdleTimeout: "5m",
		}
		st, stop, done := run(t, spec, password, pgEnv)
		if st.Certificate == nil {
			t.Fatalf("status %+v", st)
		}
		if got := psqlAsApp("SELECT rolname FROM pg_roles WHERE rolname = '" + role + "'"); got != role {
			t.Fatalf("role not created: %q", got)
		}
		script := strings.Join([]string{
			`printf '%s' "$CERT" > /tmp/ca.pem`,
			`C="host=127.0.0.1 port=$PORT dbname=app"`,
			`psql "$C user=$ROLE sslmode=verify-full sslrootcert=/tmp/ca.pem" -tAc 'SELECT count(*) FROM notes' | sed 's/^/count=/'`,
			`psql "$C user=$ROLE sslmode=require" -tAc 'INSERT INTO notes VALUES (3)' 2>&1 | grep -q 'read-only' && echo write-refused`,
			`psql "$C user=$ROLE sslmode=disable" -tAc 'SELECT 1' 2>&1 | grep -q 'needs SSL' && echo plain-refused`,
			`PGPASSWORD=pw-session-123456 psql "$C user=app sslmode=require" -tAc 'SELECT 1' 2>&1 | grep -q "connect as $ROLE" && echo app-refused`,
			`psql "$C user=$ROLE sslmode=require dbname=postgres" -tAc 'SELECT 1' 2>&1 | grep -q 'connect to the database' && echo other-db-refused`,
		}, "\n")
		out := runClient(ctx, t, api, []string{"PGPASSWORD=" + password, "CERT=" + st.Certificate.PEM, "PORT=" + strconv.Itoa(st.Port), "ROLE=" + role}, script)
		for _, want := range []string{"count=2", "write-refused", "plain-refused", "app-refused", "other-db-refused"} {
			if !strings.Contains(out, want) {
				t.Errorf("client output lacks %q:\n%s", want, out)
			}
		}
		if got := psqlAsApp("SELECT count(*) FROM notes"); got != "2" {
			t.Fatalf("a read-only role wrote: %s rows", got)
		}
		// Expiry: ending the access closes the port and drops the role.
		stop()
		r := <-done
		if r.Access == nil || r.Access.EndReason != state.AccessEndEnded {
			t.Fatalf("result %+v", r.Access)
		}
		assertClosed(ctx, t, st.Port)
		if got := psqlAsApp("SELECT count(*) FROM pg_roles WHERE rolname = '" + role + "'"); got != "0" {
			t.Fatalf("role left after the end: %s", got)
		}
		if es, _ := mgr.Ledger(); len(es) != 0 {
			t.Fatalf("ledger after the end: %+v", es)
		}
	})
}

// TestVolumeAccessSFTP runs "Connect with SFTP" (D67) through dockerd-guard
// with OpenSSH's sftp, scp and ssh: a file written over SFTP lands in the
// volume as its owner and the running service reads it; "/" is the volume;
// shells and commands are refused; a read-only access can't write; the
// helper is gone afterwards.
func TestVolumeAccessSFTP(t *testing.T) {
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
	ctx, cancel := context.WithTimeout(context.Background(), 15*time.Minute)
	defer cancel()
	if err := s.engine.PullImage(ctx, postgresImage); err != nil {
		t.Fatal(err)
	}
	fx := fixtureEngine(t, s.serverID)

	// The service: busybox running as 1000:1000 with its volume at /app/data,
	// whose top folder belongs to 1000:1000 (like an app's data folder).
	volID := state.VolumeID(randomID("vol"))
	volName := "lr-v_" + hex.EncodeToString([]byte(access.RandomName(8)))
	svc, env := state.ServiceID(randomID("svc")), state.EnvironmentID(randomID("env"))
	vl := docker.ManagedLabels(string(s.serverID))
	vl[docker.LabelVolume], vl[docker.LabelService], vl[docker.LabelEnvironment] = string(volID), string(svc), string(env)
	if err := fx.CreateVolume(ctx, docker.VolumeCreate{Name: volName, Labels: vl}); err != nil {
		t.Fatal(err)
	}
	if err := s.engine.PullImage(ctx, busyboxImage); err != nil {
		t.Fatal(err)
	}
	wl := state.WorkloadID(randomID("wl"))
	labels := docker.ManagedLabels(string(s.serverID))
	labels[docker.LabelWorkload] = string(wl)
	setup, err := fx.CreateContainer(ctx, &docker.ContainerCreate{
		Name: "lr-itest-chown-" + access.RandomName(6), Config: &container.Config{Image: busyboxImage, Labels: docker.ManagedLabels(string(s.serverID)), Cmd: []string{"chown", "1000:1000", "/app/data"}},
		HostConfig: &container.HostConfig{Mounts: []mount.Mount{{Type: mount.TypeVolume, Source: volName, Target: "/app/data"}}},
	})
	if err != nil {
		t.Fatal(err)
	}
	if err := fx.StartContainer(ctx, setup); err != nil {
		t.Fatal(err)
	}
	waitExited(ctx, t, api, setup)
	svcID, err := fx.CreateContainer(ctx, &docker.ContainerCreate{
		Name:       docker.ContainerName(wl),
		Config:     &container.Config{Image: busyboxImage, Labels: labels, User: "1000:1000", Cmd: []string{"sleep", "3600"}},
		HostConfig: &container.HostConfig{Mounts: []mount.Mount{{Type: mount.TypeVolume, Source: volName, Target: "/app/data"}}},
	})
	if err != nil {
		t.Fatal(err)
	}
	if err := fx.StartContainer(ctx, svcID); err != nil {
		t.Fatal(err)
	}
	inService := func(argv ...string) string {
		var b strings.Builder
		_, _ = s.engine.ExecStream(ctx, svcID, docker.ExecOptions{Argv: argv, Stdout: &b})
		return strings.TrimSpace(b.String())
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
	open := func(t *testing.T, writes bool) (*state.AccessStatus, string, context.CancelFunc, chan tasks.Result) {
		t.Helper()
		user := "lr-" + access.RandomName(10)
		spec := &state.AccessSpec{
			Access: state.AccessID(randomID("acc")), Kind: state.AccessVolume, Workload: wl, User: state.AccessUser(user),
			AuthorizedKeys: []state.SSHPublicKey{state.SSHPublicKey(strings.TrimSpace(string(ssh.MarshalAuthorizedKey(signer.PublicKey()))))},
			AllowFrom:      []state.AccessPrefix{"127.0.0.1/32"}, IdleTimeout: "5m", AllowWrites: writes,
			Volume: volID, Image: state.ImageRef(busyboxImage),
		}
		if err := spec.Validate(); err != nil {
			t.Fatal(err)
		}
		actx, stop := context.WithCancel(ctx)
		hctx, probe := tasks.HandlerContext(actx)
		done := make(chan tasks.Result, 1)
		go func() {
			r, err := h(hctx, state.Task{ID: state.TaskID(randomID("tsk")), Type: state.TaskAccess, Attempt: 1, Deadline: time.Now().Add(10 * time.Minute), Spec: spec})
			if err != nil {
				t.Logf("access handler: %v", err)
			}
			done <- r
		}()
		deadline := time.After(3 * time.Minute)
		for {
			select {
			case <-probe.Heartbeats():
				if r := probe.Reporter(); r != nil {
					st, ack := r.HeartbeatStatus()
					ack(false)
					return st, user, stop, done
				}
			case r := <-done:
				t.Fatalf("access ended before listening: %+v", r.Access)
			case <-deadline:
				t.Fatal("access never listened")
			}
		}
	}
	clientEnv := func(st *state.AccessStatus, user string) []string {
		return []string{"KEY=" + string(pem.EncodeToMemory(block)), "KNOWN=" + fmt.Sprintf("[127.0.0.1]:%d %s", st.Port, st.HostKey.PublicKey), "PORT=" + strconv.Itoa(st.Port), "USER=" + user}
	}
	prelude := []string{
		"apk add --no-cache -q openssh-client >/dev/null",
		`printf '%s\n' "$KEY" > /tmp/id && chmod 600 /tmp/id`,
		`printf '%s\n' "$KNOWN" > /tmp/kh`,
		`O="-i /tmp/id -o UserKnownHostsFile=/tmp/kh -o StrictHostKeyChecking=yes -o BatchMode=yes -o IdentitiesOnly=yes"`,
	}

	t.Run("writable", func(t *testing.T) {
		st, user, stop, done := open(t, true)
		if st.Owner == nil || st.Owner.UID != 1000 || st.Owner.GID != 1000 || st.HostKey == nil {
			t.Fatalf("status %+v", st)
		}
		script := strings.Join(append(prelude,
			`printf 'volume-payload-%s\n' 4242 > /tmp/up.txt`,
			`printf 'mkdir /uploads\nput /tmp/up.txt /uploads/up.txt\ncd ..\ncd ..\npwd\nls /\nget /uploads/up.txt /tmp/down.txt\n' > /tmp/batch`,
			`sftp $O -P $PORT -b /tmp/batch $USER@127.0.0.1 2>&1 | sed 's/^/sftp: /'`,
			`cat /tmp/down.txt`,
			`echo scp-file > /tmp/scp.txt && scp $O -P $PORT /tmp/scp.txt $USER@127.0.0.1:/scp.txt >/tmp/scp.out 2>&1 && echo scp-ok || sed 's/^/scp: /' /tmp/scp.out`,
			`ssh $O -p $PORT $USER@127.0.0.1 'id' </dev/null >/tmp/id.out 2>&1; test -s /tmp/id.out && grep -q 'uid=' /tmp/id.out || echo exec-refused`,
			`ssh $O -tt -p $PORT $USER@127.0.0.1 </dev/null >/tmp/sh.out 2>&1; grep -q 'uid=\|# \|\$ ' /tmp/sh.out || echo shell-refused`,
		), "\n")
		out := runClient(ctx, t, api, clientEnv(st, user), script)
		for _, want := range []string{"volume-payload-4242", "sftp: Remote working directory: /", "scp-ok", "exec-refused", "shell-refused"} {
			if !strings.Contains(out, want) {
				t.Errorf("client output lacks %q:\n%s", want, out)
			}
		}
		// The running service reads the file, owned like its own files.
		if got := inService("cat", "/app/data/uploads/up.txt"); got != "volume-payload-4242" {
			t.Fatalf("service reads %q", got)
		}
		if got := inService("stat", "-c", "%u:%g", "/app/data/uploads/up.txt", "/app/data/scp.txt"); got != "1000:1000\n1000:1000" {
			t.Fatalf("owners %q", got)
		}
		helpers := func() int {
			f := client.Filters{}
			f.Add("label", docker.LabelHelper+"="+access.HelperKind)
			cs, err := api.ContainerList(ctx, client.ContainerListOptions{All: true, Filters: f})
			if err != nil {
				t.Fatal(err)
			}
			return len(cs.Items)
		}
		if n := helpers(); n != 1 {
			t.Fatalf("%d helpers while open", n)
		}
		stop()
		if r := <-done; r.Access == nil || r.Access.EndReason != state.AccessEndEnded || r.Access.Connections < 3 {
			t.Fatalf("result %+v", r.Access)
		}
		assertClosed(ctx, t, st.Port)
		if n := helpers(); n != 0 {
			t.Fatalf("%d helpers left", n)
		}
	})

	t.Run("read-only", func(t *testing.T) {
		st, user, stop, done := open(t, false)
		script := strings.Join(append(prelude,
			`echo nope > /tmp/ro.txt`,
			`printf 'get /uploads/up.txt /tmp/ro-down.txt\nput /tmp/ro.txt /ro.txt\n' > /tmp/batch`,
			`sftp $O -P $PORT -b /tmp/batch $USER@127.0.0.1 2>&1 | sed 's/^/sftp: /'`,
			`cat /tmp/ro-down.txt`,
		), "\n")
		out := runClient(ctx, t, api, clientEnv(st, user), script)
		if !strings.Contains(out, "volume-payload-4242") || !strings.Contains(strings.ToLower(out), "permission denied") {
			t.Errorf("read-only client output:\n%s", out)
		}
		if got := inService("ls", "/app/data"); strings.Contains(got, "ro.txt") {
			t.Fatalf("a read-only access wrote: %q", got)
		}
		stop()
		<-done
	})
}

// waitExited waits until a container has stopped.
func waitExited(ctx context.Context, t *testing.T, api *client.Client, id string) {
	t.Helper()
	w := api.ContainerWait(ctx, id, client.ContainerWaitOptions{Condition: container.WaitConditionNotRunning})
	select {
	case <-w.Result:
	case err := <-w.Error:
		t.Fatal(err)
	}
}

func assertClosed(ctx context.Context, t *testing.T, port int) {
	t.Helper()
	d := net.Dialer{Timeout: 2 * time.Second}
	if c, err := d.DialContext(ctx, "tcp", "127.0.0.1:"+strconv.Itoa(port)); err == nil {
		_ = c.Close()
		t.Fatalf("port %d still accepts connections", port)
	}
}

// clientShell is the client container's shell (see runClient).
const clientShell = "sh"

// runClient runs script in a postgres:18-alpine container on the host
// network (where launchroomd listens) and returns its output.
func runClient(ctx context.Context, t *testing.T, api *client.Client, env []string, script string) string {
	t.Helper()
	cr, err := api.ContainerCreate(ctx, client.ContainerCreateOptions{
		// The script runs inside this throwaway client container, not on the
		// host (R1 is about launchroomd's own processes).
		Config:     &container.Config{Image: postgresImage, Entrypoint: []string{clientShell, "-c", script}, Env: env, Labels: map[string]string{"launchroom.dev": "true"}},
		HostConfig: &container.HostConfig{NetworkMode: "host"},
	})
	if err != nil {
		t.Fatal(err)
	}
	defer func() {
		_, _ = api.ContainerRemove(context.WithoutCancel(ctx), cr.ID, client.ContainerRemoveOptions{Force: true})
	}()
	if _, err := api.ContainerStart(ctx, cr.ID, client.ContainerStartOptions{}); err != nil {
		t.Fatal(err)
	}
	w := api.ContainerWait(ctx, cr.ID, client.ContainerWaitOptions{Condition: container.WaitConditionNotRunning})
	select {
	case <-w.Result:
	case err := <-w.Error:
		t.Fatal(err)
	}
	out := updContainerLogs(ctx, api, cr.ID)
	if os.Getenv("LR_ITEST_VERBOSE") != "" {
		t.Log(out)
	}
	return out
}
