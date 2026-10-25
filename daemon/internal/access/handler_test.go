package access

import (
	"bytes"
	"context"
	"crypto/ecdh"
	"crypto/rand"
	"crypto/tls"
	"crypto/x509"
	"encoding/binary"
	"encoding/pem"
	"errors"
	"io"
	"net"
	"net/netip"
	"os"
	"path/filepath"
	"strconv"
	"strings"
	"sync"
	"sync/atomic"
	"testing"
	"time"

	"github.com/moby/moby/api/types/container"

	"github.com/vantezzen/launchroom/daemon/internal/docker"
	"github.com/vantezzen/launchroom/daemon/internal/docker/dockerfake"
	"github.com/vantezzen/launchroom/daemon/internal/firewall"
	"github.com/vantezzen/launchroom/daemon/internal/sealed"
	"github.com/vantezzen/launchroom/daemon/internal/secret"
	"github.com/vantezzen/launchroom/daemon/internal/state"
	"github.com/vantezzen/launchroom/daemon/internal/tasks"
)

const (
	testServer   = state.ServerID("srv_01J9Z8X7W6V5T4S3R2Q1P0N9MK")
	testWorkload = state.WorkloadID("wl_01J9Z8X7W6V5T4S3R2Q1P0N9N8")
	testRole     = "lr_tmp_abcdef012345"
	testPassword = "temp-password-0123456789abcdef"
)

// execDocker adds exec to the fake Engine: database clients succeed and
// are recorded; "drop" can be made to fail.
type execDocker struct {
	*dockerfake.Fake
	mu       sync.Mutex
	stdin    []string
	failDrop atomic.Bool
}

func (d *execDocker) ExecTTY(context.Context, string, docker.TTYOptions) (*docker.TTYExec, error) {
	return nil, errors.New("no tty in this fake")
}
func (d *execDocker) PathExists(context.Context, string, string) (bool, error) { return true, nil }
func (d *execDocker) ExecStream(_ context.Context, _ string, o docker.ExecOptions) (docker.ExecResult, error) {
	var in []byte
	if o.Stdin != nil {
		in, _ = io.ReadAll(o.Stdin)
	}
	d.mu.Lock()
	d.stdin = append(d.stdin, string(in))
	d.mu.Unlock()
	if d.failDrop.Load() && strings.Contains(string(in), "DROP ROLE") {
		return docker.ExecResult{ExitCode: 2, Stderr: "psql: connection refused"}, nil
	}
	return docker.ExecResult{}, nil
}

func (d *execDocker) scripts() []string {
	d.mu.Lock()
	defer d.mu.Unlock()
	return append([]string(nil), d.stdin...)
}

type clock struct{ ns atomic.Int64 }

func (c *clock) now() time.Time      { return time.Unix(0, c.ns.Load()) }
func (c *clock) add(d time.Duration) { c.ns.Add(int64(d)) }

type harness struct {
	d       *execDocker
	key     *ecdh.PrivateKey
	mgr     *Manager
	clk     *clock
	syncs   atomic.Int32
	rules   chan []firewall.AccessRule
	upAddr  netip.AddrPort
	upMsgs  chan []byte
	dataDir string
}

func newHarness(t *testing.T) *harness {
	t.Helper()
	h := &harness{d: &execDocker{Fake: dockerfake.New(string(testServer))}, clk: &clock{}, rules: make(chan []firewall.AccessRule, 16), upMsgs: make(chan []byte, 4), dataDir: t.TempDir()}
	h.clk.ns.Store(time.Date(2026, 10, 25, 10, 0, 0, 0, time.UTC).UnixNano())
	var err error
	if h.key, err = ecdh.X25519().GenerateKey(rand.Reader); err != nil {
		t.Fatal(err)
	}
	labels := docker.ManagedLabels(string(testServer))
	labels[docker.LabelWorkload] = string(testWorkload)
	h.d.AddImage("postgres@sha256:x")
	id, err := h.d.CreateContainer(context.Background(), &docker.ContainerCreate{Name: docker.ContainerName(testWorkload), Config: &container.Config{Image: "postgres@sha256:x", Labels: labels}})
	if err != nil {
		t.Fatal(err)
	}
	_ = h.d.StartContainer(context.Background(), id)
	// A fake database upstream: records the first message it gets.
	var lc net.ListenConfig
	up, err := lc.Listen(context.Background(), "tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { _ = up.Close() })
	h.upAddr = netip.MustParseAddrPort(up.Addr().String())
	go func() {
		for {
			c, err := up.Accept()
			if err != nil {
				return
			}
			go func() {
				defer func() { _ = c.Close() }()
				b := make([]byte, 4096)
				n, _ := c.Read(b)
				h.upMsgs <- b[:n]
				_, _ = c.Write([]byte("R\x00\x00\x00\x08\x00\x00\x00\x00")) // AuthenticationOk
				_, _ = io.Copy(io.Discard, c)
			}()
		}
	}()
	h.d.Update(id, func(c *docker.Container) {
		c.Networks = map[string]netip.Addr{"lr-e_0123456789abcdef": h.upAddr.Addr()}
	})
	h.mgr = NewManager(ManagerOptions{
		Ports: PortRange{Lo: 41000, Hi: 41099}, DataDir: h.dataDir,
		SyncFirewall:     func(context.Context) error { h.syncs.Add(1); h.rules <- h.mgr.Rules(); return nil },
		FirewallEnforced: func() bool { return true },
		Listen: func(ctx context.Context, port uint16) (net.Listener, error) {
			// Tests bind loopback with an ephemeral port; the range is checked below.
			if port < 41000 || port > 41099 {
				t.Errorf("port %d outside the range", port)
			}
			var lc net.ListenConfig
			return lc.Listen(ctx, "tcp", "127.0.0.1:0")
		},
	})
	return h
}

func (h *harness) handler() tasks.Handler {
	return Handler(Options{
		ServerID: testServer, SealingKey: h.key, Docker: h.d, Manager: h.mgr,
		Workload: func(id state.WorkloadID) (*state.WorkloadSpec, bool) {
			return &state.WorkloadSpec{ID: id, Service: "svc_01J9Z8X7W6V5T4S3R2Q1P0N9P2", Environment: "env_01J9Z8X7W6V5T4S3R2Q1P0N9P3"}, id == testWorkload
		},
		WorkloadEnv: func(state.WorkloadID) (map[string]secret.Secret, error) {
			return map[string]secret.Secret{"POSTGRES_USER": "app", "POSTGRES_DB": "shop", "POSTGRES_PASSWORD": "service-pw"}, nil
		},
		Dial: func(ctx context.Context, _ netip.AddrPort) (net.Conn, error) {
			var d net.Dialer
			return d.DialContext(ctx, "tcp", h.upAddr.String())
		},
		StatusEvery: 10 * time.Millisecond,
		Now:         h.clk.now,
	})
}

func (h *harness) task(t *testing.T, kind state.AccessKind) state.Task {
	t.Helper()
	id := state.TaskID("tsk_01J9Z8X7W6V5T4S3R2Q1P0N9S3")
	pw, err := sealed.SealJSON(state.TaskToken{ServerID: testServer, TaskID: id, Purpose: state.PurposeAccess, Token: testPassword}, h.key.PublicKey(), rand.Reader)
	if err != nil {
		t.Fatal(err)
	}
	spec := &state.AccessSpec{
		Access: "acc_01J9Z8X7W6V5T4S3R2Q1P0N9A1", Kind: kind, Workload: testWorkload, Engine: state.EnginePostgres,
		User: testRole, Password: &pw, AllowFrom: []state.AccessPrefix{"127.0.0.1/32"}, Hosts: []state.AccessHost{"127.0.0.1"}, IdleTimeout: "15m",
	}
	if err := spec.Validate(); err != nil {
		t.Fatal(err)
	}
	return state.Task{ID: id, Type: state.TaskAccess, Attempt: 1, Deadline: h.clk.now().Add(2 * time.Hour), Spec: spec}
}

func runHandler(ctx context.Context, h tasks.Handler, t state.Task) (chan tasks.Result, chan error) {
	res, errc := make(chan tasks.Result, 1), make(chan error, 1)
	go func() {
		r, err := h(ctx, t)
		res <- r
		errc <- err
	}()
	return res, errc
}

// waitRules waits for a firewall sync with n rules.
func waitRules(t *testing.T, h *harness, n int) []firewall.AccessRule {
	t.Helper()
	timeout := time.After(10 * time.Second)
	for {
		select {
		case r := <-h.rules:
			if len(r) == n {
				return r
			}
		case <-timeout:
			t.Fatalf("no firewall sync with %d rules", n)
		}
	}
}

func dialPG(t *testing.T, port int, user string) (net.Conn, error) {
	t.Helper()
	var d net.Dialer
	c, err := d.DialContext(context.Background(), "tcp", "127.0.0.1:"+itoa(port))
	if err != nil {
		return nil, err
	}
	_, _ = c.Write([]byte{0, 0, 0, 8, 0x04, 0xd2, 0x16, 0x2f}) // SSLRequest
	b := make([]byte, 1)
	if _, err := io.ReadFull(c, b); err != nil || b[0] != 'S' {
		return nil, errors.New("no SSL")
	}
	tc := tls.Client(c, &tls.Config{InsecureSkipVerify: true}) //nolint:gosec // pinned below by the test
	if err := tc.HandshakeContext(context.Background()); err != nil {
		return nil, err
	}
	_, _ = tc.Write(startup(map[string]string{"user": user}))
	return tc, nil
}

func startup(params map[string]string) []byte {
	var b bytes.Buffer
	b.Write([]byte{0, 0, 0, 0, 0, 3, 0, 0})
	for _, k := range []string{"user", "database", "replication", "application_name"} {
		if v, ok := params[k]; ok {
			b.WriteString(k + "\x00" + v + "\x00")
		}
	}
	b.WriteByte(0)
	out := b.Bytes()
	binary.BigEndian.PutUint32(out, uint32(len(out))) //nolint:gosec // small test message
	return out
}

func itoa(n int) string { return strconv.Itoa(n) }

// probe reads the access status the handler reports with heartbeats.
type probe struct{ *tasks.HandlerProbe }

func (p probe) wait(t *testing.T) *state.AccessStatus {
	t.Helper()
	timeout := time.After(10 * time.Second)
	for {
		select {
		case <-p.Heartbeats():
			if r := p.Reporter(); r != nil {
				st, done := r.HeartbeatStatus()
				done(false)
				if st.Port != 0 {
					return st
				}
			}
		case <-timeout:
			t.Fatal("no heartbeat with a status")
		}
	}
}

func TestDatabaseAccessLifecycle(t *testing.T) {
	h := newHarness(t)
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	task := h.task(t, state.AccessDatabase)
	ctx, hp := tasks.HandlerContext(ctx)
	rep := probe{hp}
	res, errc := runHandler(ctx, h.handler(), task)

	rules := waitRules(t, h, 1)
	if rules[0].Port < 41000 || rules[0].Port > 41099 || len(rules[0].From) != 1 || rules[0].From[0].String() != "127.0.0.1/32" {
		t.Fatalf("rule %+v", rules[0])
	}
	if es, _ := h.mgr.Ledger(); len(es) != 1 || es[0].Role != testRole {
		t.Fatalf("ledger while running: %+v", es)
	}
	scripts := h.d.scripts()
	if len(scripts) == 0 || !strings.Contains(scripts[0], `CREATE ROLE "`+testRole+`"`) || strings.Contains(scripts[0], testPassword) {
		t.Fatalf("create script: %q", scripts)
	}
	st := rep.wait(t)
	if st.Certificate == nil || st.HostKey != nil {
		t.Fatalf("status %+v", st)
	}
	block, _ := pem.Decode([]byte(st.Certificate.PEM))
	cert, err := x509.ParseCertificate(block.Bytes)
	if err != nil || len(cert.IPAddresses) != 1 || !cert.IPAddresses[0].Equal(net.ParseIP("127.0.0.1")) {
		t.Fatalf("certificate SANs: %v %v", err, cert)
	}

	// A wrong user never reaches the database.
	port := st.Port
	if c, err := dialPG(t, port, "app"); err == nil {
		reply := make([]byte, 256)
		n, _ := c.Read(reply)
		if n == 0 || reply[0] != 'E' || !bytes.Contains(reply[:n], []byte("connect as "+testRole)) {
			t.Fatalf("wrong user answer %q", reply[:n])
		}
		_ = c.Close()
	}
	select {
	case m := <-h.upMsgs:
		t.Fatalf("upstream got a message for a wrong user: %q", m)
	case <-time.After(100 * time.Millisecond):
	}
	// The temporary role does, with the database filled in.
	c, err := dialPG(t, port, testRole)
	if err != nil {
		t.Fatal(err)
	}
	select {
	case m := <-h.upMsgs:
		if !bytes.Contains(m, []byte("user\x00"+testRole+"\x00")) || !bytes.Contains(m, []byte("database\x00shop\x00")) {
			t.Fatalf("upstream startup %q", m)
		}
	case <-time.After(5 * time.Second):
		t.Fatal("upstream got nothing")
	}
	_ = c.Close()

	// Idle: no traffic for longer than the idle timeout ends it.
	var r tasks.Result
	for done := false; !done; {
		h.clk.add(16 * time.Minute)
		select {
		case r = <-res:
			done = true
		case <-time.After(200 * time.Millisecond):
		}
	}
	if err := <-errc; err != nil {
		t.Fatal(err)
	}
	if r.Access == nil || r.Access.EndReason != state.AccessEndIdle || r.Access.Connections != 1 {
		t.Fatalf("result %+v", r.Access)
	}
	waitRules(t, h, 0)
	if len(h.mgr.Rules()) != 0 {
		t.Fatal("firewall rule left after the end")
	}
	if es, _ := h.mgr.Ledger(); len(es) != 0 {
		t.Fatalf("ledger after the end: %+v", es)
	}
	scripts = h.d.scripts()
	if !strings.Contains(scripts[len(scripts)-1], "DROP ROLE IF EXISTS") {
		t.Fatal("role not dropped")
	}
	// The port is closed.
	if c, err := (&net.Dialer{Timeout: time.Second}).DialContext(context.Background(), "tcp", "127.0.0.1:"+itoa(port)); err == nil {
		_ = c.Close()
		t.Fatal("port still open")
	}
}

func TestAccessDeadlineCancelAndRestartCleanup(t *testing.T) {
	h := newHarness(t)
	h.d.failDrop.Store(true)
	task := h.task(t, state.AccessDatabase)
	ctx, cancel := context.WithTimeout(context.Background(), 300*time.Millisecond)
	defer cancel()
	res, errc := runHandler(ctx, h.handler(), task)
	r := <-res
	if err := <-errc; err != nil {
		t.Logf("handler: %v", err)
	}
	if r.Access.EndReason != state.AccessEndDeadline {
		t.Fatalf("reason %s", r.Access.EndReason)
	}
	// The drop failed: the ledger keeps the role for the sweep (a crash
	// between create and drop looks the same).
	es, _ := h.mgr.Ledger()
	if len(es) != 1 {
		t.Fatalf("ledger %+v", es)
	}
	b, _ := os.ReadFile(filepath.Join(h.dataDir, "access", "roles.json"))
	if bytes.Contains(b, []byte(testPassword)) || bytes.Contains(b, []byte("service-pw")) {
		t.Fatal("a password in the ledger")
	}
	// A new process: nothing is running, the sweep drops it once the database answers.
	fresh := NewManager(ManagerOptions{DataDir: h.dataDir})
	fresh.Sweep(context.Background(), func(ctx context.Context, e LedgerEntry) error { return errors.New("database down") })
	if es, _ := fresh.Ledger(); len(es) != 1 {
		t.Fatal("a failed drop was forgotten")
	}
	var dropped []string
	fresh.Sweep(context.Background(), func(ctx context.Context, e LedgerEntry) error { dropped = append(dropped, e.Role); return nil })
	if len(dropped) != 1 || dropped[0] != testRole {
		t.Fatalf("dropped %v", dropped)
	}
	if es, _ := fresh.Ledger(); len(es) != 0 {
		t.Fatal("ledger not emptied")
	}
	if len(fresh.Rules()) != 0 {
		t.Fatal("a new process starts with firewall rules")
	}

	// Cancel (End now in the dashboard): reason ended.
	h.d.failDrop.Store(false)
	for len(h.rules) > 0 {
		<-h.rules
	}
	ctx2, cancel2 := context.WithCancel(context.Background())
	res, errc = runHandler(ctx2, h.handler(), task)
	waitRules(t, h, 1)
	cancel2()
	r = <-res
	if err := <-errc; err != nil {
		t.Logf("handler: %v", err)
	}
	if r.Access.EndReason != state.AccessEndEnded {
		t.Fatalf("reason %s", r.Access.EndReason)
	}
}

func TestAccessRefusedByPolicyOrTarget(t *testing.T) {
	h := newHarness(t)
	task := h.task(t, state.AccessDatabase)
	off := Handler(Options{ServerID: testServer, SealingKey: h.key, Docker: h.d, Manager: h.mgr, Allowed: func(state.AccessKind) bool { return false }})
	r, err := off(context.Background(), task)
	if err == nil || r.Access.EndReason != state.AccessEndRefused {
		t.Fatalf("policy: %v %+v", err, r.Access)
	}
	spec := *task.Spec.(*state.AccessSpec) //nolint:forcetypeassert // test
	spec.Workload = "wl_01J9Z8X7W6V5T4S3R2Q1P0N9ZZ"
	task.Spec = &spec
	r, err = h.handler()(context.Background(), task)
	if err == nil || r.Access.EndReason != state.AccessEndRefused {
		t.Fatalf("foreign workload: %v %+v", err, r.Access)
	}
	// A password sealed for another task doesn't open.
	task = h.task(t, state.AccessDatabase)
	task.ID = "tsk_01J9Z8X7W6V5T4S3R2Q1P0N9S9"
	if _, err := h.handler()(context.Background(), task); err == nil || !strings.Contains(err.Error(), "access password") {
		t.Fatalf("re-targeted password: %v", err)
	}
}
