package docker

import (
	"context"
	"errors"
	"fmt"
	"io"
	"sync"
	"time"

	"github.com/moby/moby/api/pkg/stdcopy"
	"github.com/moby/moby/client"
)

// MaxExecStderr bounds the stderr an ExecStream keeps (the tail is kept).
const MaxExecStderr = 8 << 10

// ExecOptions describes one process run inside a managed container.
type ExecOptions struct {
	// Argv is the process to run (never a shell string).
	Argv []string
	// Env is added to the container's environment for this process only
	// ("NAME=value"); used for credentials so they never appear in argv.
	Env []string
	// Stdin, if set, is copied to the process' stdin, which is closed at EOF.
	Stdin io.Reader
	// Stdout receives the process' stdout (nil discards it).
	Stdout io.Writer
	// User runs the process as this user of the image ("postgres"); empty:
	// the container's user.
	User string
}

// ExecResult is the outcome of a finished exec.
type ExecResult struct {
	ExitCode int
	// Stderr is the tail (≤ MaxExecStderr bytes) of the process' stderr.
	Stderr string
}

// Execer runs processes in managed containers with streamed stdio.
type Execer interface {
	ExecStream(ctx context.Context, id string, o ExecOptions) (ExecResult, error)
}

var _ Execer = (*Engine)(nil)

// ExecStream runs argv inside a managed, running container, streams stdin and
// stdout, keeps the stderr tail and returns the exit code. Cancelling ctx
// closes the stream (the process gets EOF/SIGPIPE on its stdio).
func (e *Engine) ExecStream(ctx context.Context, id string, o ExecOptions) (ExecResult, error) {
	if len(o.Argv) == 0 {
		return ExecResult{}, errors.New("docker: exec needs an argv")
	}
	if err := e.mustOwnContainer(ctx, id); err != nil {
		return ExecResult{}, err
	}
	cctx, cancel := e.call(ctx)
	ex, err := e.api.ExecCreate(cctx, id, client.ExecCreateOptions{
		AttachStdin: o.Stdin != nil, AttachStdout: true, AttachStderr: true, Cmd: o.Argv, Env: o.Env, User: o.User,
	})
	cancel()
	if err != nil {
		return ExecResult{}, wrap("exec create", err)
	}
	hr, err := e.api.ExecAttach(context.WithoutCancel(ctx), ex.ID, client.ExecAttachOptions{})
	if err != nil {
		return ExecResult{}, wrap("exec attach", err)
	}
	var closeOnce sync.Once
	closeConn := func() { closeOnce.Do(hr.Close) }
	defer closeConn()
	stop := context.AfterFunc(ctx, closeConn)
	defer stop()

	if o.Stdin != nil {
		// Not awaited: a process may stop reading early (the exit code
		// decides), and the copy ends when the caller closes its reader or
		// the connection closes.
		go func() {
			_, _ = io.Copy(hr.Conn, o.Stdin)
			_ = hr.CloseWrite()
		}()
	}

	out := o.Stdout
	if out == nil {
		out = io.Discard
	}
	tail := &tailBuffer{max: MaxExecStderr}
	_, copyErr := stdcopy.StdCopy(out, tail, hr.Reader)
	closeConn()
	if err := ctx.Err(); err != nil {
		return ExecResult{Stderr: tail.String()}, err
	}
	if copyErr != nil {
		return ExecResult{Stderr: tail.String()}, fmt.Errorf("docker: exec stream: %w", copyErr)
	}
	code, err := e.execExitCode(ctx, ex.ID)
	return ExecResult{ExitCode: code, Stderr: tail.String()}, err
}

// execExitCode waits (briefly) until the exec is no longer running.
func (e *Engine) execExitCode(ctx context.Context, execID string) (int, error) {
	deadline := time.Now().Add(e.callTimeout)
	for {
		cctx, cancel := e.call(ctx)
		res, err := e.api.ExecInspect(cctx, execID, client.ExecInspectOptions{})
		cancel()
		if err != nil {
			return -1, wrap("exec inspect", err)
		}
		if !res.Running {
			return res.ExitCode, nil
		}
		if time.Now().After(deadline) {
			return -1, errors.New("docker: exec did not finish after its output ended")
		}
		select {
		case <-ctx.Done():
			return -1, ctx.Err()
		case <-time.After(100 * time.Millisecond):
		}
	}
}

// tailBuffer keeps the last max bytes written to it.
type tailBuffer struct {
	mu  sync.Mutex
	max int
	b   []byte
}

func (t *tailBuffer) Write(p []byte) (int, error) {
	t.mu.Lock()
	defer t.mu.Unlock()
	t.b = append(t.b, p...)
	if len(t.b) > t.max {
		t.b = append(t.b[:0], t.b[len(t.b)-t.max:]...)
	}
	return len(p), nil
}

func (t *tailBuffer) String() string {
	t.mu.Lock()
	defer t.mu.Unlock()
	return string(t.b)
}
