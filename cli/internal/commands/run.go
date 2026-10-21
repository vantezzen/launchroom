package commands

import (
	"context"
	"errors"
	"os"
	"os/exec"
	"os/signal"
	"sort"
	"strings"
	"syscall"

	"github.com/spf13/cobra"

	"github.com/vantezzen/launchroom/cli/internal/exitcode"
)

func (a *App) runCmd() *cobra.Command {
	var noSecrets bool
	cmd := &cobra.Command{
		Use:   "run [--no-secrets] -- COMMAND [ARGS...]",
		Short: "Run a local command with the service's variables injected",
		Long:  "Starts COMMAND directly (no shell) with the resolved variables of the last deployed revision on top of your environment. Exits with COMMAND's exit code.",
		Args:  cobra.MinimumNArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			ctx := cmd.Context()
			_, env, err := a.fetchEnv(ctx, !noSecrets)
			if err != nil {
				return err
			}
			if len(env.Withheld) > 0 && !a.Printer.JSON {
				a.Printer.Warning("Running without %d secret %s (the token needs secrets:read): %s", len(env.Withheld), plural(len(env.Withheld), "value", "values"), strings.Join(env.Withheld, ", "))
			}
			code, err := a.RunCommand(ctx, args, mergeEnv(os.Environ(), env.Variables), Stdio{In: a.Stdin, Out: a.Printer.Out, Err: a.Printer.Err})
			if err != nil {
				return err
			}
			if code != 0 {
				return &childExit{code}
			}
			return nil
		},
	}
	cmd.Flags().BoolVar(&noSecrets, "no-secrets", false, "leave secret values out")
	cmd.Flags().SetInterspersed(false)
	return cmd
}

// childExit passes the child's exit code through without printing an error.
type childExit struct{ code int }

func (c *childExit) Error() string { return "" }

// ExitCode returns the child's code.
func (c *childExit) ExitCode() int { return c.code }

// mergeEnv puts vars over base (launchroom's values win), sorted.
func mergeEnv(base []string, vars map[string]string) []string {
	m := map[string]string{}
	for _, kv := range base {
		if k, v, ok := strings.Cut(kv, "="); ok {
			m[k] = v
		}
	}
	for k, v := range vars {
		m[k] = v
	}
	out := make([]string, 0, len(m))
	for k, v := range m {
		out = append(out, k+"="+v)
	}
	sort.Strings(out)
	return out
}

// runChild starts argv (no shell), forwards SIGINT/SIGTERM and returns
// its exit code.
func runChild(ctx context.Context, argv []string, env []string, stdio Stdio) (int, error) {
	path, err := exec.LookPath(argv[0])
	if err != nil {
		return 0, exitcode.New(exitcode.Usage, err)
	}
	// No CommandContext: the child gets the terminal's signals (forwarded
	// below) and decides itself when to exit; we never SIGKILL it.
	c := exec.Command(path, argv[1:]...) //nolint:forbidigo,gosec,noctx // `launchroom run` exists to start the user's own command, as argv (docs/16 §3.3)
	c.Env = env
	c.Stdin, c.Stdout, c.Stderr = stdio.In, stdio.Out, stdio.Err
	if err := c.Start(); err != nil {
		return 0, err
	}
	sigs := make(chan os.Signal, 2)
	signal.Notify(sigs, os.Interrupt, syscall.SIGTERM)
	defer signal.Stop(sigs)
	done := make(chan error, 1)
	go func() { done <- c.Wait() }()
	for {
		select {
		case sig := <-sigs:
			_ = c.Process.Signal(sig)
		case <-ctx.Done():
			// The parent context ends on our own signal handler too; the
			// child got the signal from the terminal's process group or
			// from the case above, so wait for it to exit.
			err := <-done
			return exitCodeOf(err)
		case err := <-done:
			return exitCodeOf(err)
		}
	}
}

func exitCodeOf(err error) (int, error) {
	if err == nil {
		return 0, nil
	}
	var ee *exec.ExitError
	if errors.As(err, &ee) {
		if ws, ok := ee.Sys().(syscall.WaitStatus); ok && ws.Signaled() {
			return 128 + int(ws.Signal()), nil
		}
		return ee.ExitCode(), nil
	}
	return 0, err
}
