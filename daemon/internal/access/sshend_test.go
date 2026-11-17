package access

import (
	"context"
	"log/slog"
	"net"
	"net/netip"
	"testing"
	"time"

	"golang.org/x/crypto/ssh"
)

// stubbornContainer's shells never report an exit code: like a Docker exec
// whose attach connection closed, the process may keep running.
type stubbornContainer struct{ *fakeContainer }

type stubbornTTY struct{ *fakeTTY }

func (stubbornTTY) ExitCode(ctx context.Context) (int, error) {
	<-ctx.Done()
	return -1, ctx.Err()
}

func (c stubbornContainer) TTY(ctx context.Context, argv, env []string, cols, rows uint) (TTY, error) {
	t, err := c.fakeContainer.TTY(ctx, argv, env, cols, rows)
	if err != nil {
		return nil, err
	}
	return stubbornTTY{t.(*fakeTTY)}, nil
}

// Ending an access with an open SSH shell closes it within seconds: the
// teardown doesn't wait for an exit code the engine may never report.
func TestSSHAccessEndsPromptlyWithOpenShell(t *testing.T) {
	signer, _, err := NewHostKey()
	if err != nil {
		t.Fatal(err)
	}
	c := stubbornContainer{&fakeContainer{toolBox: newToolBox(t), resize: make(chan [2]uint, 4)}}
	o := SSHOptions{Password: "correct-horse-battery-staple-0123", HostKey: signer, Container: c, User: testUser, Shell: "/bin/sh"}
	ep := newEndpoint([]netip.Prefix{netip.MustParsePrefix("127.0.0.1/32")}, time.Now, slog.New(slog.DiscardHandler))
	var lc net.ListenConfig
	l, err := lc.Listen(context.Background(), "tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	ctx, cancel := context.WithCancel(context.Background())
	served := make(chan struct{})
	go func() { defer close(served); ep.serve(ctx, l, newSSHFront(o, ep).handle) }()
	defer func() { cancel(); <-served }()

	cl, err := dialSSH(l.Addr().String(), signer.PublicKey(), ssh.Password("correct-horse-battery-staple-0123"))
	if err != nil {
		t.Fatal(err)
	}
	defer func() { _ = cl.Close() }()
	s, _ := cl.NewSession()
	if err := s.RequestPty("xterm", 30, 100, ssh.TerminalModes{}); err != nil {
		t.Fatal(err)
	}
	if err := s.Shell(); err != nil {
		t.Fatal(err)
	}
	deadline := time.Now().Add(5 * time.Second)
	for {
		c.mu.Lock()
		n := len(c.ttys)
		c.mu.Unlock()
		if n > 0 {
			break
		}
		if time.Now().After(deadline) {
			t.Fatal("the shell did not start")
		}
		time.Sleep(10 * time.Millisecond)
	}

	start := time.Now()
	cancel() // the access ends
	select {
	case <-served:
	case <-time.After(exitCodeWait + 5*time.Second):
		t.Fatal("the access kept waiting for the shell's exit code")
	}
	if d := time.Since(start); d > exitCodeWait+2*time.Second {
		t.Fatalf("teardown took %s", d)
	}
}
