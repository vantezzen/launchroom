// Package commands implements the `launchroom` command tree
// (docs/16-api-and-cli.md §3.2).
package commands

import (
	"bufio"
	"context"
	"errors"
	"fmt"
	"io"
	"os"
	"runtime"
	"strconv"
	"strings"
	"time"

	"github.com/spf13/cobra"

	"github.com/vantezzen/launchroom/cli/internal/config"
	"github.com/vantezzen/launchroom/cli/internal/exitcode"
	"github.com/vantezzen/launchroom/cli/internal/output"
)

// App holds the process' environment; tests replace every field.
type App struct {
	Version   string
	Printer   *output.Printer
	Stdin     io.Reader
	Getenv    func(string) string
	Getwd     func() (string, error)
	ConfigDir string
	Keyring   config.Keyring // nil: credentials file only
	// OpenBrowser opens a URL (browser.Open in production).
	OpenBrowser func(string) error
	// Hostname names this machine in the sign-in request.
	Hostname func() (string, error)
	// RunCommand runs `launchroom run`'s child; tests replace it.
	RunCommand func(ctx context.Context, argv []string, env []string, stdio Stdio) (int, error)
	// Sleep waits between polls and retries (nil: real time).
	Sleep func(context.Context, time.Duration) error

	flags globalFlags
	stdin *bufio.Reader
}

// Stdio are the streams a child process inherits.
type Stdio struct {
	In       io.Reader
	Out, Err io.Writer
}

type globalFlags struct {
	json        bool
	noColor     bool
	project     string
	environment string
	service     string
}

// UserAgent identifies the CLI to the dashboard.
func (a *App) UserAgent() string {
	return fmt.Sprintf("launchroom-cli/%s (%s/%s)", a.Version, runtime.GOOS, runtime.GOARCH)
}

// Root builds the command tree.
func (a *App) Root() *cobra.Command {
	root := &cobra.Command{
		Use:           "launchroom",
		Short:         "Deploy, follow logs and manage variables of your launchroom projects",
		Long:          "launchroom: one place to deploy.\n\nSign in with `launchroom login`, tie a directory to a project with `launchroom link`, then `launchroom deploy`.",
		Version:       a.Version,
		SilenceUsage:  true,
		SilenceErrors: true,
		PersistentPreRun: func(*cobra.Command, []string) {
			a.Printer.JSON = a.flags.json
			if a.flags.json || a.flags.noColor {
				a.Printer.Color = false
			}
		},
	}
	root.SetVersionTemplate("launchroom {{.Version}}\n")
	pf := root.PersistentFlags()
	pf.BoolVar(&a.flags.json, "json", false, "print machine-readable JSON on stdout (errors as JSON on stderr)")
	pf.BoolVar(&a.flags.noColor, "no-color", false, "no colours (also NO_COLOR=1)")
	pf.StringVar(&a.flags.project, "project", "", "project id or slug (overrides the link)")
	pf.StringVar(&a.flags.environment, "environment", "", "environment id or slug (overrides the link)")
	pf.StringVar(&a.flags.service, "service", "", "service id or slug (overrides the link)")

	root.AddCommand(
		a.loginCmd(), a.logoutCmd(), a.whoamiCmd(), a.linkCmd(), a.statusCmd(),
		a.deployCmd(), a.logsCmd(), a.envCmd(), a.configCmd(), a.runCmd(), a.openCmd(),
		a.dbCmd(), a.volumeCmd(), a.securityCmd(), a.serviceCmd(),
	)
	root.SetFlagErrorFunc(func(_ *cobra.Command, err error) error { return exitcode.New(exitcode.Usage, err) })
	markRunErrors(root)
	return root
}

// runError marks an error returned by a command's RunE. Every other error
// Execute sees comes from cobra parsing the command line (unknown command,
// wrong number of arguments, missing required flags): a usage error.
type runError struct{ err error }

func (e *runError) Error() string { return e.err.Error() }
func (e *runError) Unwrap() error { return e.err }

func markRunErrors(cmd *cobra.Command) {
	if run := cmd.RunE; run != nil {
		cmd.RunE = func(c *cobra.Command, args []string) error {
			if err := run(c, args); err != nil {
				return &runError{err}
			}
			return nil
		}
	}
	for _, sub := range cmd.Commands() {
		markRunErrors(sub)
	}
}

// Execute runs the CLI with args and returns the process exit code.
func (a *App) Execute(ctx context.Context, args []string) int {
	root := a.Root() //nolint:contextcheck // only builds the tree; every RunE gets ctx from ExecuteContext
	root.SetArgs(args)
	root.SetIn(a.Stdin)
	root.SetOut(a.Printer.Out)
	root.SetErr(a.Printer.Err)
	// --json must apply to errors that happen before PersistentPreRun.
	for _, arg := range args {
		if arg == "--" {
			break
		}
		if arg == "--json" {
			a.Printer.JSON = true
			a.Printer.Color = false
		}
	}
	err := root.ExecuteContext(ctx)
	if err == nil {
		return exitcode.OK
	}
	var child *childExit
	if errors.As(err, &child) {
		return child.code
	}
	if errors.Is(err, context.Canceled) && ctx.Err() != nil {
		if !a.Printer.JSON {
			a.Printer.Info("")
		}
		return exitcode.Interrupted
	}
	var fromRun *runError
	if !errors.As(err, &fromRun) && exitcode.Of(err) == exitcode.Error {
		err = exitcode.New(exitcode.Usage, err)
	}
	return a.Printer.Fail(err)
}

func (a *App) readLine() (string, error) {
	if a.stdin == nil {
		a.stdin = bufio.NewReader(a.Stdin)
	}
	line, err := a.stdin.ReadString('\n')
	if err != nil && (line == "" || !errors.Is(err, io.EOF)) {
		return "", err
	}
	return strings.TrimSpace(line), nil
}

// confirm asks a yes/no question on the terminal (default no).
func (a *App) confirm(question string) (bool, error) {
	_, _ = fmt.Fprintf(a.Printer.Err, "%s [y/N] ", question)
	ans, err := a.readLine()
	if err != nil {
		return false, err
	}
	ans = strings.ToLower(ans)
	return ans == "y" || ans == "yes", nil
}

// pick lets the user choose one of labels on the terminal; returns the index.
func (a *App) pick(what string, labels []string) (int, error) {
	if len(labels) == 1 {
		return 0, nil
	}
	if !a.Printer.TTY || a.Printer.JSON {
		return 0, exitcode.Usagef("several %ss match; pass --%s", what, what)
	}
	_, _ = fmt.Fprintf(a.Printer.Err, "Which %s?\n", what)
	for i, l := range labels {
		_, _ = fmt.Fprintf(a.Printer.Err, "  %d) %s\n", i+1, l)
	}
	for {
		_, _ = fmt.Fprintf(a.Printer.Err, "Choose 1–%d: ", len(labels))
		ans, err := a.readLine()
		if err != nil {
			return 0, err
		}
		if n, err := strconv.Atoi(ans); err == nil && n >= 1 && n <= len(labels) {
			return n - 1, nil
		}
	}
}

// Main wires the real process environment and runs the CLI.
func Main(ctx context.Context, version string, args []string, keyring config.Keyring, openBrowser func(string) error) int {
	dir, err := config.Dir()
	p := output.New(false, false)
	if err != nil {
		return p.Fail(err)
	}
	app := &App{
		Version:     version,
		Printer:     p,
		Stdin:       os.Stdin,
		Getenv:      os.Getenv,
		Getwd:       os.Getwd,
		ConfigDir:   dir,
		Keyring:     keyring,
		OpenBrowser: openBrowser,
		Hostname:    os.Hostname,
		RunCommand:  runChild,
	}
	return app.Execute(ctx, args)
}
