package docker

import (
	"context"
	"errors"
	"fmt"
	"io"
	"net"
	"sync"
	"time"

	"github.com/moby/moby/api/pkg/stdcopy"
	"github.com/moby/moby/client"
)

// SystemOps are Engine operations used only for launchroom's own system
// containers (BuildKit): copying built-in config files into a container
// before it starts, and talking to a daemon inside a container over an exec
// stdio pipe instead of a network port.
type SystemOps interface {
	// CopyToContainer extracts a tar archive at dst inside a managed container.
	CopyToContainer(ctx context.Context, id, dst string, tar io.Reader) error
	// DialExec runs argv inside a managed, running container and returns its
	// stdin/stdout as a stream (stderr is discarded).
	DialExec(ctx context.Context, id string, argv []string) (net.Conn, error)
}

var _ SystemOps = (*Engine)(nil)

// CopyToContainer implements SystemOps.
func (e *Engine) CopyToContainer(ctx context.Context, id, dst string, tar io.Reader) error {
	if err := e.mustOwnContainer(ctx, id); err != nil {
		return err
	}
	ctx, cancel := e.call(ctx)
	defer cancel()
	_, err := e.api.CopyToContainer(ctx, id, client.CopyToContainerOptions{DestinationPath: dst, Content: tar, CopyUIDGID: true})
	return wrap("copy to container", err)
}

// DialExec implements SystemOps. The exec runs as the container's default
// user; its lifetime is bound to the returned connection (closing it ends the
// process' stdin).
func (e *Engine) DialExec(ctx context.Context, id string, argv []string) (net.Conn, error) {
	if len(argv) == 0 {
		return nil, errors.New("docker: exec needs an argv")
	}
	if err := e.mustOwnContainer(ctx, id); err != nil {
		return nil, err
	}
	cctx, cancel := e.call(ctx)
	defer cancel()
	ex, err := e.api.ExecCreate(cctx, id, client.ExecCreateOptions{AttachStdin: true, AttachStdout: true, AttachStderr: true, Cmd: argv})
	if err != nil {
		return nil, wrap("exec create", err)
	}
	// The hijacked connection outlives this call: no call timeout here.
	hr, err := e.api.ExecAttach(context.WithoutCancel(ctx), ex.ID, client.ExecAttachOptions{})
	if err != nil {
		return nil, wrap("exec attach", err)
	}
	pr, pw := io.Pipe()
	c := &execConn{hr: hr.HijackedResponse, pr: pr}
	go func() {
		// Non-TTY exec output is multiplexed (stdout/stderr frames).
		_, err := stdcopy.StdCopy(pw, io.Discard, hr.Reader)
		if err == nil {
			err = io.EOF
		}
		_ = pw.CloseWithError(err)
	}()
	return c, nil
}

// execConn adapts a hijacked exec stream to net.Conn.
type execConn struct {
	hr   client.HijackedResponse
	pr   *io.PipeReader
	once sync.Once
}

func (c *execConn) Read(p []byte) (int, error)  { return c.pr.Read(p) }
func (c *execConn) Write(p []byte) (int, error) { return c.hr.Conn.Write(p) }

func (c *execConn) Close() error {
	c.once.Do(func() {
		_ = c.hr.CloseWrite()
		c.hr.Close()
		_ = c.pr.Close()
	})
	return nil
}

// CloseWrite closes the process' stdin.
func (c *execConn) CloseWrite() error { return c.hr.CloseWrite() }

func (c *execConn) LocalAddr() net.Addr  { return c.hr.Conn.LocalAddr() }
func (c *execConn) RemoteAddr() net.Addr { return c.hr.Conn.RemoteAddr() }

func (c *execConn) SetDeadline(t time.Time) error      { return c.hr.Conn.SetDeadline(t) }
func (c *execConn) SetReadDeadline(t time.Time) error  { return c.hr.Conn.SetReadDeadline(t) }
func (c *execConn) SetWriteDeadline(t time.Time) error { return c.hr.Conn.SetWriteDeadline(t) }

// String identifies the stream in logs.
func (c *execConn) String() string { return fmt.Sprintf("exec-stream(%s)", c.hr.Conn.RemoteAddr()) }

// ArchiveReader reads files out of launchroom's own system containers (the
// edge Caddy's certificate storage, docs/05 §5).
type ArchiveReader interface {
	// CopyFromContainer returns a tar archive of src inside a managed
	// container, at most maxBytes long (longer archives are an error).
	CopyFromContainer(ctx context.Context, id, src string, maxBytes int64) ([]byte, error)
}

var _ ArchiveReader = (*Engine)(nil)

// CopyFromContainer implements ArchiveReader.
func (e *Engine) CopyFromContainer(ctx context.Context, id, src string, maxBytes int64) ([]byte, error) {
	if err := e.mustOwnContainer(ctx, id); err != nil {
		return nil, err
	}
	ctx, cancel := e.call(ctx)
	defer cancel()
	res, err := e.api.CopyFromContainer(ctx, id, client.CopyFromContainerOptions{SourcePath: src})
	if err != nil {
		return nil, wrap("copy from container", err)
	}
	defer func() { _ = res.Content.Close() }()
	data, err := io.ReadAll(io.LimitReader(res.Content, maxBytes+1))
	if err != nil {
		return nil, wrap("copy from container", err)
	}
	if int64(len(data)) > maxBytes {
		return nil, fmt.Errorf("docker: copy from container: archive of %s is larger than %d bytes", src, maxBytes)
	}
	return data, nil
}
