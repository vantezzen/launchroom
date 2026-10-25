package access

import (
	"bytes"
	"context"
	"crypto/ed25519"
	"crypto/rand"
	"errors"
	"io"
	"log/slog"
	"net"
	"net/netip"
	"strings"
	"sync"
	"testing"
	"time"

	"github.com/pkg/sftp"
	"golang.org/x/crypto/ssh"

	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// fakeContainer runs "shell -c <cmd>" as echo-to-stdout and records argvs.
type fakeContainer struct {
	*toolBox
	mu     sync.Mutex
	execs  [][]string
	ttys   []*fakeTTY
	resize chan [2]uint
}

func (c *fakeContainer) TTY(_ context.Context, argv, env []string, cols, rows uint) (TTY, error) {
	c.mu.Lock()
	defer c.mu.Unlock()
	c.execs = append(c.execs, append([]string{"tty"}, argv...))
	t := newFakeTTY(c.resize)
	c.ttys = append(c.ttys, t)
	return t, nil
}

func (c *fakeContainer) Exec(_ context.Context, argv []string, stdin io.Reader, stdout, stderr io.Writer) (int, error) {
	c.mu.Lock()
	c.execs = append(c.execs, argv)
	c.mu.Unlock()
	if len(argv) == 3 && argv[1] == "-c" {
		_, _ = io.WriteString(stdout, "ran: "+argv[2]+"\n")
		_, _ = io.WriteString(stderr, "warn\n")
		return 7, nil
	}
	_, _ = io.Copy(stdout, stdin)
	return 0, nil
}

func (c *fakeContainer) RunEnv(context.Context, []string, []string, []byte, *bytes.Buffer) (int, string, error) {
	return 0, "", nil
}

// fakeTTY echoes input back; "exit\r" ends it with code 3.
type fakeTTY struct {
	pr     *io.PipeReader
	pw     *io.PipeWriter
	resize chan [2]uint
	once   sync.Once
}

func newFakeTTY(resize chan [2]uint) *fakeTTY {
	pr, pw := io.Pipe()
	return &fakeTTY{pr: pr, pw: pw, resize: resize}
}

func (t *fakeTTY) Read(p []byte) (int, error) { return t.pr.Read(p) }
func (t *fakeTTY) Write(p []byte) (int, error) {
	if bytes.Contains(p, []byte("exit")) {
		_ = t.Close()
		return len(p), nil
	}
	go func() { _, _ = t.pw.Write(p) }()
	return len(p), nil
}
func (t *fakeTTY) Close() error { t.once.Do(func() { _ = t.pw.Close() }); return nil }
func (t *fakeTTY) Resize(_ context.Context, cols, rows uint) error {
	if t.resize != nil {
		t.resize <- [2]uint{cols, rows}
	}
	return nil
}
func (t *fakeTTY) ExitCode(context.Context) (int, error) { return 3, nil }

const testUser = "lr-abcdef0123"

// sshServer starts a front end on 127.0.0.1 and returns its address.
func sshServer(t *testing.T, o SSHOptions, allow string) (string, *endpoint, *fakeContainer, ssh.PublicKey) {
	t.Helper()
	signer, hk, err := NewHostKey()
	if err != nil {
		t.Fatal(err)
	}
	if hk.Type != "ssh-ed25519" || !strings.HasPrefix(hk.SHA256, "SHA256:") {
		t.Fatalf("host key %+v", hk)
	}
	c := &fakeContainer{toolBox: newToolBox(t), resize: make(chan [2]uint, 4)}
	o.HostKey, o.Container, o.User = signer, c, testUser
	if o.Shell == "" {
		o.Shell = "/bin/sh"
	}
	ep := newEndpoint([]netip.Prefix{netip.MustParsePrefix(allow)}, time.Now, slog.New(slog.DiscardHandler))
	var lc net.ListenConfig
	l, err := lc.Listen(context.Background(), "tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	ctx, cancel := context.WithCancel(context.Background())
	done := make(chan struct{})
	go func() { defer close(done); ep.serve(ctx, l, newSSHFront(o, ep).handle) }()
	t.Cleanup(func() { cancel(); <-done })
	return l.Addr().String(), ep, c, signer.PublicKey()
}

func dialSSH(addr string, host ssh.PublicKey, auth ...ssh.AuthMethod) (*ssh.Client, error) {
	return ssh.Dial("tcp", addr, &ssh.ClientConfig{User: testUser, Auth: auth, HostKeyCallback: ssh.FixedHostKey(host), Timeout: 5 * time.Second})
}

func TestSSHPasswordAuthAndExec(t *testing.T) {
	addr, ep, c, host := sshServer(t, SSHOptions{Password: "correct-horse-battery-staple-0123"}, "127.0.0.1/32")
	if _, err := dialSSH(addr, host, ssh.Password("wrong")); err == nil {
		t.Fatal("wrong password accepted")
	}
	cl, err := dialSSH(addr, host, ssh.Password("correct-horse-battery-staple-0123"))
	if err != nil {
		t.Fatal(err)
	}
	defer func() { _ = cl.Close() }()
	s, _ := cl.NewSession()
	var out, errOut bytes.Buffer
	s.Stdout, s.Stderr = &out, &errOut
	err = s.Run("echo hi; id")
	var exit *ssh.ExitError
	if !errors.As(err, &exit) || exit.ExitStatus() != 7 {
		t.Fatalf("exit: %v", err)
	}
	if out.String() != "ran: echo hi; id\n" || errOut.String() != "warn\n" {
		t.Fatalf("out %q err %q", out.String(), errOut.String())
	}
	c.mu.Lock()
	last := c.execs[len(c.execs)-1]
	c.mu.Unlock()
	if strings.Join(last, "|") != "/bin/sh|-c|echo hi; id" {
		t.Fatalf("argv %q", last)
	}
	evs, _, done := ep.drainEvents()
	done(true)
	var connected, failed int
	for _, e := range evs {
		switch e.Type {
		case state.AccessEventConnected:
			connected++
			if e.Method != state.AccessMethodPassword || e.Channel != state.AccessChannelExec || e.Remote != "127.0.0.1" {
				t.Errorf("event %+v", e)
			}
		case state.AccessEventAuthFailed:
			failed += e.Count
		}
	}
	if connected != 1 || failed < 1 {
		t.Fatalf("events %+v", evs)
	}
}

func TestSSHPasswordLockout(t *testing.T) {
	addr, ep, _, host := sshServer(t, SSHOptions{Password: "correct-horse-battery-staple-0123"}, "127.0.0.1/32")
	for i := 0; i < MaxPasswordFailures; i++ {
		_, _ = dialSSH(addr, host, ssh.Password("guess"))
	}
	select {
	case <-ep.locked:
	case <-time.After(10 * time.Second):
		t.Fatal("not locked after the failures")
	}
}

func TestSSHPublicKeyShellPTYAndNoForwarding(t *testing.T) {
	_, priv, _ := ed25519.GenerateKey(rand.Reader)
	signer, _ := ssh.NewSignerFromKey(priv)
	_, other, _ := ed25519.GenerateKey(rand.Reader)
	otherSigner, _ := ssh.NewSignerFromKey(other)
	addr, ep, c, host := sshServer(t, SSHOptions{Keys: []ssh.PublicKey{signer.PublicKey()}}, "127.0.0.0/8")
	if _, err := dialSSH(addr, host, ssh.PublicKeys(otherSigner)); err == nil {
		t.Fatal("unknown key accepted")
	}
	if _, err := dialSSH(addr, host, ssh.Password("anything")); err == nil {
		t.Fatal("password accepted on a key-only access")
	}
	cl, err := dialSSH(addr, host, ssh.PublicKeys(signer))
	if err != nil {
		t.Fatal(err)
	}
	defer func() { _ = cl.Close() }()

	// Forwarding of any kind is refused.
	if _, err := cl.Dial("tcp", "127.0.0.1:22"); err == nil {
		t.Fatal("direct-tcpip was allowed")
	}
	if _, err := cl.Listen("tcp", "127.0.0.1:0"); err == nil {
		t.Fatal("tcpip-forward was allowed")
	}
	if _, _, err := cl.OpenChannel("x11", nil); err == nil {
		t.Fatal("x11 channel was allowed")
	}

	s, _ := cl.NewSession()
	if err := s.RequestPty("xterm", 30, 100, ssh.TerminalModes{}); err != nil {
		t.Fatal(err)
	}
	stdin, _ := s.StdinPipe()
	var out bytes.Buffer
	s.Stdout = &out
	if ok, _ := s.SendRequest("auth-agent-req@openssh.com", true, nil); ok {
		t.Fatal("agent forwarding was allowed")
	}
	if err := s.Shell(); err != nil {
		t.Fatal(err)
	}
	if err := s.WindowChange(40, 120); err != nil {
		t.Fatal(err)
	}
	select {
	case sz := <-c.resize:
		if sz != [2]uint{120, 40} {
			t.Fatalf("resize %v", sz)
		}
	case <-time.After(5 * time.Second):
		t.Fatal("no resize")
	}
	_, _ = stdin.Write([]byte("hello\r"))
	time.Sleep(100 * time.Millisecond)
	_, _ = stdin.Write([]byte("exit\r"))
	err = s.Wait()
	var exit *ssh.ExitError
	if !errors.As(err, &exit) || exit.ExitStatus() != 3 {
		t.Fatalf("exit %v", err)
	}
	if !strings.Contains(out.String(), "hello") {
		t.Fatalf("output %q", out.String())
	}
	c.mu.Lock()
	first := c.execs[0]
	c.mu.Unlock()
	if strings.Join(first, "|") != "tty|/bin/sh" {
		t.Fatalf("shell argv %q", first)
	}
	evs, _, _ := ep.drainEvents()
	found := false
	for _, e := range evs {
		found = found || e.Type == state.AccessEventConnected && e.Method == state.AccessMethodPublicKey && e.Channel == state.AccessChannelShell
	}
	if !found {
		t.Fatalf("no publickey shell event: %+v", evs)
	}
}

func TestSSHSFTPSubsystem(t *testing.T) {
	addr, _, _, host := sshServer(t, SSHOptions{Password: "correct-horse-battery-staple-0123"}, "127.0.0.1/32")
	cl, err := dialSSH(addr, host, ssh.Password("correct-horse-battery-staple-0123"))
	if err != nil {
		t.Fatal(err)
	}
	defer func() { _ = cl.Close() }()
	sc, err := sftp.NewClient(cl)
	if err != nil {
		t.Fatal(err)
	}
	defer func() { _ = sc.Close() }()
	f, err := sc.Create("/note.txt")
	if err != nil {
		t.Fatal(err)
	}
	_, _ = f.Write([]byte("from sftp"))
	if err := f.Close(); err != nil {
		t.Fatal(err)
	}
	r, err := sc.Open("/note.txt")
	if err != nil {
		t.Fatal(err)
	}
	b, _ := io.ReadAll(r)
	if string(b) != "from sftp" {
		t.Fatalf("read %q", b)
	}
}

func TestEndpointAdmission(t *testing.T) {
	now := time.Unix(1000, 0)
	ep := newEndpoint([]netip.Prefix{netip.MustParsePrefix("203.0.113.0/24"), netip.MustParsePrefix("2001:db8::/48")}, func() time.Time { return now }, slog.New(slog.DiscardHandler))
	for _, a := range []string{"198.51.100.1", "203.0.114.1", "2001:db9::1", "127.0.0.1"} {
		if ep.admit(netip.MustParseAddr(a)) {
			t.Errorf("%s admitted", a)
		}
	}
	if !ep.admit(netip.MustParseAddr("2001:db8::7")) {
		t.Fatal("allowed IPv6 refused")
	}
	ep.release()
	a := netip.MustParseAddr("203.0.113.9")
	for i := range ConnectionsPerMinute {
		if !ep.admit(a) {
			t.Fatalf("connection %d refused", i)
		}
		ep.release()
	}
	if ep.admit(a) {
		t.Fatal("rate limit not applied")
	}
	now = now.Add(61 * time.Second)
	if !ep.admit(a) {
		t.Fatal("rate limit didn't reset")
	}
	if ep.admit(netip.Addr{}) {
		t.Fatal("invalid address admitted")
	}
}

func TestPayloadParsing(t *testing.T) {
	if _, err := parseString([]byte{0, 0, 0, 9, 'a'}); err == nil {
		t.Fatal("short string accepted")
	}
	if _, _, err := parseWindow([]byte{0, 0, 9, 0, 0, 0, 0, 10}); err == nil {
		t.Fatal("2304 columns accepted")
	}
	pty := append([]byte{0, 0, 0, 5}, []byte("xterm")...)
	pty = append(pty, 0, 0, 0, 80, 0, 0, 0, 24, 0, 0, 0, 0, 0, 0, 0, 0)
	if p, err := parsePty(pty); err != nil || p.cols != 80 || p.rows != 24 || p.term != "xterm" {
		t.Fatalf("pty %+v %v", p, err)
	}
}
