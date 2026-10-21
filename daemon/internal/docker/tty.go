package docker

import (
	"context"
	"errors"
	"fmt"
	"sync"

	"github.com/moby/moby/client"
)

// TTYOptions describes an interactive process in a managed container
// (the Console, docs/03 §11.4). There is deliberately no User, Privileged
// or WorkingDir: the process runs as the container's own user.
type TTYOptions struct {
	// Argv is the process to run (never a shell string).
	Argv []string
	// Env is added to the container's environment for this process only.
	Env []string
	// Cols and Rows are the initial terminal size.
	Cols, Rows uint
}

// TTYExec is a running interactive process. Read returns raw terminal
// output (a TTY exec is not multiplexed), Write sends input.
type TTYExec struct {
	e    *Engine
	id   string
	hr   client.HijackedResponse
	once sync.Once
}

// Interactive runs interactive processes in managed containers.
type Interactive interface {
	// ExecTTY starts argv with a TTY inside a managed, running container.
	ExecTTY(ctx context.Context, id string, o TTYOptions) (*TTYExec, error)
	// PathExists reports whether path exists inside a managed container
	// (Engine API stat; nothing runs in the container).
	PathExists(ctx context.Context, id, path string) (bool, error)
}

var _ Interactive = (*Engine)(nil)

// ExecTTY implements Interactive.
func (e *Engine) ExecTTY(ctx context.Context, id string, o TTYOptions) (*TTYExec, error) {
	if len(o.Argv) == 0 {
		return nil, errors.New("docker: exec needs an argv")
	}
	c, err := e.InspectContainer(ctx, id)
	if err != nil {
		return nil, err
	}
	if !c.Running {
		return nil, fmt.Errorf("docker: container %s is not running", shortID(id))
	}
	size := client.ConsoleSize{Height: max(o.Rows, 2), Width: max(o.Cols, 2)}
	cctx, cancel := e.call(ctx)
	ex, err := e.api.ExecCreate(cctx, id, client.ExecCreateOptions{
		TTY: true, ConsoleSize: size, AttachStdin: true, AttachStdout: true, AttachStderr: true,
		Cmd: o.Argv, Env: o.Env, Privileged: false,
	})
	cancel()
	if err != nil {
		return nil, wrap("exec create", err)
	}
	// The hijacked connection outlives this call.
	hr, err := e.api.ExecAttach(context.WithoutCancel(ctx), ex.ID, client.ExecAttachOptions{TTY: true, ConsoleSize: size})
	if err != nil {
		return nil, wrap("exec attach", err)
	}
	return &TTYExec{e: e, id: ex.ID, hr: hr.HijackedResponse}, nil
}

// Read reads terminal output.
func (t *TTYExec) Read(p []byte) (int, error) { return t.hr.Reader.Read(p) }

// Write sends terminal input.
func (t *TTYExec) Write(p []byte) (int, error) { return t.hr.Conn.Write(p) }

// Resize changes the terminal size.
func (t *TTYExec) Resize(ctx context.Context, cols, rows uint) error {
	ctx, cancel := t.e.call(ctx)
	defer cancel()
	_, err := t.e.api.ExecResize(ctx, t.id, client.ExecResizeOptions{Height: rows, Width: cols})
	return wrap("exec resize", err)
}

// Close closes the stream (the shell gets a hangup).
func (t *TTYExec) Close() error {
	t.once.Do(t.hr.Close)
	return nil
}

// ExitCode returns the exit code once the process ended (-1 if still running
// after a short wait).
func (t *TTYExec) ExitCode(ctx context.Context) (int, error) {
	return t.e.execExitCode(ctx, t.id)
}

// PathExists implements Interactive.
func (e *Engine) PathExists(ctx context.Context, id, path string) (bool, error) {
	if err := e.mustOwnContainer(ctx, id); err != nil {
		return false, err
	}
	ctx, cancel := e.call(ctx)
	defer cancel()
	_, err := e.api.ContainerStatPath(ctx, id, client.ContainerStatPathOptions{Path: path})
	if err != nil {
		if w := wrap("stat", err); errors.Is(w, ErrNotFound) {
			return false, nil
		}
		return false, wrap("stat", err)
	}
	return true, nil
}
