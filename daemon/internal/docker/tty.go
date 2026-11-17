package docker

import (
	"context"
	"errors"
	"fmt"
	"sync"
	"time"

	"github.com/moby/moby/client"
)

// TTYOptions describes an interactive process in a managed container
// (the Console, docs/03 §11.4). There is deliberately no User, Privileged
// or WorkingDir: the process runs as the container's own user.
type TTYOptions struct {
	// Argv is the process to run (never a shell string).
	Argv []string
	// Env is added to the container's environment for this process only.
	Env        []string
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
	if !c.Running() {
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

// Hang-up input for a process that is still running when the stream
// closes: Docker keeps an exec running after its attach connection is gone
// (there is no exec kill in the Engine API), so a Console or SSH shell would
// stay in the container forever. ^C ends a foreground program and clears
// the line, ^D on the empty line then ends the shell. The pause lets the
// terminal flush its input queue on the interrupt before the EOF arrives.
var (
	hangUpInterrupt = []byte{0x03}
	hangUpEOF       = []byte{0x04}
)

const (
	hangUpPause = 100 * time.Millisecond
	hangUpWait  = 2 * time.Second
)

// Close ends the stream. A process still running gets a hang-up first
// (^C, then ^D) and up to two seconds to exit.
func (t *TTYExec) Close() error {
	t.once.Do(func() {
		t.hangUp()
		t.hr.Close()
	})
	return nil
}

func (t *TTYExec) hangUp() {
	ctx, cancel := context.WithTimeout(context.Background(), hangUpWait)
	defer cancel()
	if !t.running(ctx) {
		return
	}
	_ = t.hr.Conn.SetWriteDeadline(time.Now().Add(hangUpWait))
	if _, err := t.hr.Conn.Write(hangUpInterrupt); err != nil {
		return
	}
	time.Sleep(hangUpPause)
	if _, err := t.hr.Conn.Write(hangUpEOF); err != nil {
		return
	}
	for t.running(ctx) {
		select {
		case <-ctx.Done():
			return
		case <-time.After(hangUpPause):
		}
	}
}

// running reports whether the exec still runs (false when unknown).
func (t *TTYExec) running(ctx context.Context) bool {
	if t.e == nil {
		return false
	}
	res, err := t.e.api.ExecInspect(ctx, t.id, client.ExecInspectOptions{})
	return err == nil && res.Running
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
