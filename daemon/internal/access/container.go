package access

import (
	"bytes"
	"context"
	"io"

	"github.com/vantezzen/launchroom/daemon/internal/docker"
)

// Docker is what access needs from the Engine (through dockerd-guard).
type Docker interface {
	docker.Client
	docker.Interactive
	docker.Execer
}

// TTY is an interactive process with a terminal.
type TTY interface {
	io.ReadWriteCloser
	Resize(ctx context.Context, cols, rows uint) error
	ExitCode(ctx context.Context) (int, error)
}

// Container runs processes in the access' target container, always as the
// container's own user (no User override, docs/03 §11.4).
type Container interface {
	TTY(ctx context.Context, argv, env []string, cols, rows uint) (TTY, error)
	Exec(ctx context.Context, argv []string, stdin io.Reader, stdout, stderr io.Writer) (int, error)
	Runner
	EnvRunner
}

// dockerContainer is Container over the Engine.
type dockerContainer struct {
	d  Docker
	id string
}

// NewContainer returns the Container for a managed container id.
func NewContainer(d Docker, id string) Container { return &dockerContainer{d: d, id: id} }

func (c *dockerContainer) TTY(ctx context.Context, argv, env []string, cols, rows uint) (TTY, error) {
	t, err := c.d.ExecTTY(ctx, c.id, docker.TTYOptions{Argv: argv, Env: env, Cols: cols, Rows: rows})
	if err != nil {
		return nil, err
	}
	return t, nil
}

func (c *dockerContainer) Exec(ctx context.Context, argv []string, stdin io.Reader, stdout, stderr io.Writer) (int, error) {
	res, err := c.d.ExecStream(ctx, c.id, docker.ExecOptions{Argv: argv, Stdin: stdin, Stdout: stdout, Stderr: stderr})
	return res.ExitCode, err
}

// Run implements Runner (SFTP tools).
func (c *dockerContainer) Run(ctx context.Context, argv []string, stdin io.Reader, stdout io.Writer) (int, string, error) {
	res, err := c.d.ExecStream(ctx, c.id, docker.ExecOptions{Argv: argv, Stdin: stdin, Stdout: stdout})
	return res.ExitCode, res.Stderr, err
}

// RunEnv implements EnvRunner (database clients with credentials in env).
func (c *dockerContainer) RunEnv(ctx context.Context, argv, env []string, stdin []byte, stdout *bytes.Buffer) (int, string, error) {
	o := docker.ExecOptions{Argv: argv, Env: env, Stdout: stdout}
	if stdin != nil {
		o.Stdin = bytes.NewReader(stdin)
	}
	res, err := c.d.ExecStream(ctx, c.id, o)
	return res.ExitCode, res.Stderr, err
}
