package access

import (
	"bytes"
	"context"
	"crypto/subtle"
	"encoding/binary"
	"errors"
	"fmt"
	"io"
	"net"
	"net/netip"
	"regexp"
	"sync"
	"time"

	"github.com/pkg/sftp"
	"golang.org/x/crypto/ssh"

	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// SSH front end (docs/03 §12.2): an SSH protocol server whose sessions are
// execs in one container. Only "session" channels; no forwarding of any
// kind (launchroomd runs in the host's network namespace).

var reTerm = regexp.MustCompile(`^[A-Za-z0-9._+-]{1,64}$`)

// SSHOptions configures the front end of one access.
type SSHOptions struct {
	User     string
	Password string // empty: keys only
	Keys     []ssh.PublicKey
	HostKey  ssh.Signer
	// Shell is the container's shell ("" = none: shell and exec are refused).
	Shell string
	// Root confines SFTP to this container folder, served as "/" (volume
	// access); "" serves the whole container.
	Root      string
	Container Container
}

type sshFront struct {
	o   SSHOptions
	ep  *endpoint
	cfg *ssh.ServerConfig
}

const methodExt = "launchroom-method"

func newSSHFront(o SSHOptions, ep *endpoint) *sshFront {
	f := &sshFront{o: o, ep: ep}
	cfg := &ssh.ServerConfig{MaxAuthTries: MaxAuthTries, ServerVersion: "SSH-2.0-launchroom"}
	if o.Password != "" {
		cfg.PasswordCallback = f.password
	}
	if len(o.Keys) > 0 {
		cfg.PublicKeyCallback = f.publicKey
	}
	cfg.AddHostKey(o.HostKey)
	f.cfg = cfg
	return f
}

func remoteOf(meta ssh.ConnMetadata) netip.Addr {
	ap, err := netip.ParseAddrPort(meta.RemoteAddr().String())
	if err != nil {
		return netip.Addr{}
	}
	return ap.Addr().Unmap()
}

func (f *sshFront) password(meta ssh.ConnMetadata, pw []byte) (*ssh.Permissions, error) {
	userOK := subtle.ConstantTimeCompare([]byte(meta.User()), []byte(f.o.User)) == 1
	pwOK := subtle.ConstantTimeCompare(pw, []byte(f.o.Password)) == 1
	if userOK && pwOK {
		return &ssh.Permissions{Extensions: map[string]string{methodExt: string(state.AccessMethodPassword)}}, nil
	}
	f.ep.failed(remoteOf(meta), true)
	time.Sleep(failureDelay)
	return nil, errors.New("access denied")
}

func (f *sshFront) publicKey(meta ssh.ConnMetadata, key ssh.PublicKey) (*ssh.Permissions, error) {
	if subtle.ConstantTimeCompare([]byte(meta.User()), []byte(f.o.User)) == 1 {
		got := key.Marshal()
		for _, k := range f.o.Keys {
			if bytes.Equal(got, k.Marshal()) {
				return &ssh.Permissions{Extensions: map[string]string{methodExt: string(state.AccessMethodPublicKey)}}, nil
			}
		}
	}
	f.ep.failed(remoteOf(meta), false)
	return nil, errors.New("access denied")
}

// handle serves one admitted TCP connection.
func (f *sshFront) handle(ctx context.Context, c net.Conn, a netip.Addr) {
	_ = c.SetDeadline(time.Now().Add(handshakeTimeout))
	sc, chans, reqs, err := ssh.NewServerConn(c, f.cfg)
	if err != nil {
		return
	}
	_ = c.SetDeadline(time.Time{})
	defer func() { _ = sc.Close() }()
	stop := context.AfterFunc(ctx, func() { _ = sc.Close() })
	defer stop()
	method := state.AccessMethod(sc.Permissions.Extensions[methodExt])
	var once sync.Once
	report := func(ch state.AccessChannel) { once.Do(func() { f.ep.connected(a, method, ch) }) }
	defer report("")
	// Global requests (tcpip-forward, keepalives): always "no".
	go ssh.DiscardRequests(reqs)
	var wg sync.WaitGroup
	for nc := range chans {
		if nc.ChannelType() != "session" {
			_ = nc.Reject(ssh.Prohibited, "only sessions are allowed here")
			continue
		}
		ch, creqs, err := nc.Accept()
		if err != nil {
			continue
		}
		wg.Add(1)
		go func() {
			defer wg.Done()
			f.session(ctx, ch, creqs, report)
		}()
	}
	wg.Wait()
}

// countingChannel counts application bytes for idle tracking.
type countingChannel struct {
	ssh.Channel
	ep *endpoint
}

func (c countingChannel) Read(p []byte) (int, error) {
	n, err := c.Channel.Read(p)
	if n > 0 {
		c.ep.activity(n, 0)
	}
	return n, err
}

func (c countingChannel) Write(p []byte) (int, error) {
	n, err := c.Channel.Write(p)
	if n > 0 {
		c.ep.activity(0, n)
	}
	return n, err
}

type ptyRequest struct {
	term       string
	cols, rows uint
}

// session runs one "session" channel: one shell, exec or sftp.
func (f *sshFront) session(ctx context.Context, raw ssh.Channel, reqs <-chan *ssh.Request, report func(state.AccessChannel)) {
	ch := countingChannel{Channel: raw, ep: f.ep}
	defer func() { _ = ch.Close() }()
	var pty *ptyRequest
	var tty TTY
	var pending *[2]uint // a window change before the TTY existed
	var ttyMu sync.Mutex
	started := false
	done := make(chan struct{})
	for {
		var req *ssh.Request
		var ok bool
		select {
		case req, ok = <-reqs:
		case <-done:
			return
		}
		if !ok {
			if !started {
				return
			}
			<-done
			return
		}
		switch req.Type {
		case "pty-req":
			p, err := parsePty(req.Payload)
			reply(req, err == nil && !started)
			if err == nil {
				pty = p
			}
		case "env":
			reply(req, true) // accepted and ignored
		case "window-change":
			cols, rows, err := parseWindow(req.Payload)
			ttyMu.Lock()
			t := tty
			if t == nil && err == nil {
				pending = &[2]uint{cols, rows}
			}
			ttyMu.Unlock()
			if err == nil && t != nil {
				_ = t.Resize(ctx, cols, rows)
			}
			reply(req, err == nil)
		case "shell", "exec":
			if started || f.o.Shell == "" {
				reply(req, false)
				continue
			}
			argv := []string{f.o.Shell}
			kind := state.AccessChannelShell
			if req.Type == "exec" {
				cmd, err := parseString(req.Payload)
				if err != nil || len(cmd) > 64<<10 || bytes.IndexByte([]byte(cmd), 0) >= 0 {
					reply(req, false)
					continue
				}
				argv = append(argv, "-c", cmd)
				kind = state.AccessChannelExec
			}
			started = true
			reply(req, true)
			report(kind)
			go func() {
				defer close(done)
				code := f.run(ctx, ch, argv, pty, func(t TTY) {
					ttyMu.Lock()
					tty = t
					p := pending
					ttyMu.Unlock()
					if p != nil {
						_ = t.Resize(ctx, p[0], p[1])
					}
				})
				sendExit(ch, code)
			}()
		case "subsystem":
			name, err := parseString(req.Payload)
			if started || err != nil || name != "sftp" {
				reply(req, false)
				continue
			}
			started = true
			reply(req, true)
			report(state.AccessChannelSFTP)
			go func() {
				defer close(done)
				fsys := NewFSAt(ctx, f.o.Container, f.o.Root, func(n int) { f.ep.activity(n, 0) })
				rs := sftp.NewRequestServer(ch, fsys.Handlers(), sftp.WithStartDirectory("/"))
				_ = rs.Serve()
				// The exit status first: closing the request server closes the
				// channel, and scp (SFTP mode) fails without an exit status.
				sendExit(ch, 0)
				_ = rs.Close()
			}()
		default:
			// signal, x11-req, auth-agent-req@openssh.com, keepalives, …
			reply(req, false)
		}
	}
}

// run starts argv in the container (with a TTY when one was requested)
// and copies the streams; it returns the exit code.
func (f *sshFront) run(ctx context.Context, ch countingChannel, argv []string, pty *ptyRequest, setTTY func(TTY)) int {
	if pty != nil {
		term := "xterm-256color"
		if reTerm.MatchString(pty.term) {
			term = pty.term
		}
		t, err := f.o.Container.TTY(ctx, argv, []string{"TERM=" + term}, max(pty.cols, 2), max(pty.rows, 2))
		if err != nil {
			_, _ = fmt.Fprintf(ch.Stderr(), "launchroom: %v\r\n", err)
			return 1
		}
		setTTY(t)
		// The shell gets a hangup when the client goes away or the access ends.
		stop := context.AfterFunc(ctx, func() { _ = t.Close() })
		defer stop()
		go func() {
			_, _ = io.Copy(t, ch)
			_ = t.Close()
		}()
		_, _ = io.Copy(ch, t)
		_ = t.Close()
		code, err := t.ExitCode(context.WithoutCancel(ctx))
		if err != nil {
			return 255
		}
		return code
	}
	code, err := f.o.Container.Exec(ctx, argv, ch, ch, ch.Stderr())
	if err != nil {
		_, _ = fmt.Fprintf(ch.Stderr(), "launchroom: %v\n", err)
		return 255
	}
	return code
}

func reply(req *ssh.Request, ok bool) {
	if req.WantReply {
		_ = req.Reply(ok, nil)
	}
}

func sendExit(ch ssh.Channel, code int) {
	b := make([]byte, 4)
	binary.BigEndian.PutUint32(b, uint32(code)) //nolint:gosec // exit codes are 0–255 or -1
	_, _ = ch.SendRequest("exit-status", false, b)
	_ = ch.CloseWrite()
}

// ---- payload parsing (RFC 4254) ----

func parseString(b []byte) (string, error) {
	if len(b) < 4 {
		return "", errors.New("short payload")
	}
	n := binary.BigEndian.Uint32(b)
	if int64(n) > int64(len(b)-4) {
		return "", errors.New("short payload")
	}
	return string(b[4 : 4+n]), nil
}

func parsePty(b []byte) (*ptyRequest, error) {
	term, err := parseString(b)
	if err != nil {
		return nil, err
	}
	rest := b[4+len(term):]
	if len(rest) < 16 {
		return nil, errors.New("short pty request")
	}
	cols, rows := binary.BigEndian.Uint32(rest), binary.BigEndian.Uint32(rest[4:])
	if cols > 500 || rows > 200 {
		return nil, errors.New("terminal too large")
	}
	return &ptyRequest{term: term, cols: uint(cols), rows: uint(rows)}, nil
}

func parseWindow(b []byte) (uint, uint, error) {
	if len(b) < 8 {
		return 0, 0, errors.New("short window change")
	}
	cols, rows := binary.BigEndian.Uint32(b), binary.BigEndian.Uint32(b[4:])
	if cols < 2 || cols > 500 || rows < 2 || rows > 200 {
		return 0, 0, errors.New("window size out of range")
	}
	return uint(cols), uint(rows), nil
}
