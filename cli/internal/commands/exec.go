package commands

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/url"
	"regexp"
	"strconv"
	"strings"
	"time"

	"github.com/spf13/cobra"

	"github.com/vantezzen/launchroom/cli/internal/api"
	"github.com/vantezzen/launchroom/cli/internal/exitcode"
	"github.com/vantezzen/launchroom/cli/internal/sse"
)

// commandNameRe is the grammar of a listed command's name (StartCommandRunInput.name).
var commandNameRe = regexp.MustCompile(`^[a-z0-9][a-z0-9-]{0,62}$`)

const (
	minCommandTimeout = 10 * time.Second
	maxCommandTimeout = time.Hour
)

type execOptions struct {
	list, detach, yes bool
	timeout           time.Duration
}

// execCmd is `launchroom exec` (D105): a one-off command in a new container
// of the service's live deployment, its output followed here.
func (a *App) execCmd() *cobra.Command {
	var o execOptions
	cmd := &cobra.Command{
		Use:   "exec [--service S] [--timeout 10m] [--detach] [--yes] -- COMMAND [ARGS...] | exec NAME | exec --list",
		Short: "Run a one-off command in a service's live deployment",
		Long: "Starts a new container from the live deployment's image, with the service's variables, network and volumes, runs the command there and follows its output. " +
			"The command runs directly, without a shell.\n\n" +
			"  launchroom exec -- php artisan migrate:status   any program and its arguments\n" +
			"  launchroom exec migrate                         a listed command by name\n" +
			"  launchroom exec --list                          the listed commands\n\n" +
			"Listed commands that change data ask first on a terminal; pass --yes in scripts. " +
			"Exits with the command's exit code (124 when it timed out, 130 when cancelled, 13 when it couldn't run). " +
			"Ctrl-C stops the command on the server. Needs the run permission (\"Run commands\" on API tokens).",
		RunE: func(cmd *cobra.Command, args []string) error {
			return a.withRunHint(a.exec(cmd.Context(), o, args, cmd.ArgsLenAtDash()))
		},
	}
	f := cmd.Flags()
	f.BoolVar(&o.list, "list", false, "list the service's commands")
	f.BoolVar(&o.detach, "detach", false, "print the run's id and don't follow its output")
	f.BoolVar(&o.yes, "yes", false, "don't ask before running a command that changes data")
	f.DurationVar(&o.timeout, "timeout", 0, "stop the command after this long, 10s to 1h (default 10m)")
	return cmd
}

func (a *App) exec(ctx context.Context, o execOptions, args []string, dash int) error {
	var argv []string
	name := ""
	switch {
	case o.list:
		if len(args) > 0 || dash >= 0 {
			return exitcode.Usagef("--list takes no command")
		}
	case dash > 0:
		return exitcode.Usagef("put the command after --, e.g. `launchroom exec -- %s`", strings.Join(args, " "))
	case dash == 0:
		if len(args) == 0 {
			return exitcode.Usagef("nothing to run after --")
		}
		argv = args
	case len(args) == 1:
		name = args[0]
		if !commandNameRe.MatchString(name) {
			return exitcode.Usagef("%q is not a listed command's name: run a program with `launchroom exec -- %s`", name, name)
		}
	case len(args) == 0:
		return exitcode.Usagef("name a listed command or put the program after --, e.g. `launchroom exec -- ls -la` (see `launchroom exec --list`)")
	default:
		return exitcode.Usagef("put the program after --, e.g. `launchroom exec -- %s`", strings.Join(args, " "))
	}
	if o.timeout != 0 && (o.timeout < minCommandTimeout || o.timeout > maxCommandTimeout) {
		return exitcode.Usagef("--timeout must be between 10s and 1h")
	}

	s, err := a.session()
	if err != nil {
		return err
	}
	t, err := a.resolve(ctx, s, true)
	if err != nil {
		return err
	}
	if o.list {
		return a.listCommands(ctx, s, t)
	}
	req := api.StartCommandRunRequest{Argv: argv, Name: name, TimeoutSeconds: int(o.timeout / time.Second)}
	if name != "" {
		if err := a.confirmCommand(ctx, s, t, name, o.yes); err != nil {
			return err
		}
	}
	run, err := s.Client.StartCommandRun(ctx, t.Environment.ID, t.Service.ID, req)
	if err != nil {
		return err
	}
	p := a.Printer
	if o.detach {
		return p.Result(run, func(w io.Writer) { _, _ = fmt.Fprintln(w, run.ID) })
	}
	if !p.JSON {
		where := fmt.Sprintf("%s (%s)", t.Service.Name, t.Environment.Name)
		if run.Server != nil && *run.Server != "" {
			where += " on " + *run.Server
		}
		p.Info(p.Dim(fmt.Sprintf("Running %s in %s…", quoteArgv(run.Argv), where)))
	}
	final, err := a.followRun(ctx, s, run)
	if err != nil {
		if ctx.Err() != nil {
			return a.cancelRun(ctx, s, run.ID)
		}
		return err
	}
	return a.finishRun(final)
}

// confirmCommand finds a listed command and asks before one that changes data.
func (a *App) confirmCommand(ctx context.Context, s *session, t *target, name string, yes bool) error {
	cat, err := s.Client.Commands(ctx, t.Environment.ID, t.Service.ID)
	if err != nil {
		return err
	}
	var cmd *api.Command
	for i := range cat.Commands {
		if cat.Commands[i].Name == name {
			cmd = &cat.Commands[i]
		}
	}
	if cmd == nil {
		msg := fmt.Sprintf("%s has no command %q: see `launchroom exec --list`, or run a program with `launchroom exec -- %s`", t.Service.Name, name, name)
		if cat.AwaitingBuild {
			msg += " (the framework's commands show up after the next build)"
		}
		return exitcode.New(exitcode.NotFound, errors.New(msg))
	}
	if !cmd.Danger || yes {
		return nil
	}
	p := a.Printer
	if !p.TTY || p.JSON {
		return exitcode.Usagef("%s changes data: pass --yes to run it (there is no terminal to ask on)", name)
	}
	ok, err := a.confirm(fmt.Sprintf("Run `%s` in %s?", quoteArgv(cmd.Argv), t.Environment.Name))
	if err != nil {
		return err
	}
	if !ok {
		return exitcode.Usagef("nothing was run")
	}
	return nil
}

func (a *App) listCommands(ctx context.Context, s *session, t *target) error {
	cat, err := s.Client.Commands(ctx, t.Environment.ID, t.Service.ID)
	if err != nil {
		return err
	}
	if cat.Commands == nil {
		cat.Commands = []api.Command{}
	}
	p := a.Printer
	if cat.AwaitingBuild && !p.JSON {
		p.Info(p.Dim("The framework's commands show up after the next build."))
	}
	return p.Result(cat, func(w io.Writer) {
		if len(cat.Commands) == 0 {
			_, _ = fmt.Fprintf(w, "%s has no listed commands. Run any program with `launchroom exec -- <command>`.\n", t.Service.Name)
			return
		}
		rows := make([][]string, 0, len(cat.Commands))
		for _, c := range cat.Commands {
			note := ""
			if c.Danger {
				note = p.Warn("⚠ changes data")
			}
			rows = append(rows, []string{c.Name, c.Label, quoteArgv(c.Argv), note})
		}
		p.Table(w, []string{"name", "label", "command", ""}, rows)
	})
}

// followRun prints a run's output from the start until it ended and
// returns its final state. It follows the stream and polls when that fails.
func (a *App) followRun(ctx context.Context, s *session, run api.CommandRun) (api.CommandRun, error) {
	cursor := 0
	ended := false
	err := s.Client.Stream(ctx, "/command-runs/"+url.PathEscape(run.ID)+"/output/stream", nil, "", func(ev sse.Event) error {
		switch ev.Event {
		case "lines":
			var le api.CommandLinesEvent
			if json.Unmarshal([]byte(ev.Data), &le) == nil {
				for _, l := range le.Lines {
					a.printCommandLine(l)
				}
				cursor = max(cursor, le.Cursor)
			}
		case "end":
			ended = true
		}
		return nil
	})
	if err != nil && ctx.Err() != nil {
		return run, err
	}
	if err == nil && ended {
		final, err := s.Client.CommandRun(ctx, run.ID)
		if err != nil || final.Finished {
			return final, err
		}
	}
	// The stream failed (or ended early): poll the output instead.
	for {
		lines, meta, err := s.Client.CommandRunOutput(ctx, run.ID, cursor)
		if err != nil {
			return run, err
		}
		for _, l := range lines {
			a.printCommandLine(l)
		}
		cursor = max(cursor, meta.Cursor)
		if meta.HasMore {
			continue
		}
		if meta.Finished {
			return s.Client.CommandRun(ctx, run.ID)
		}
		if err := s.Client.Sleep(ctx, time.Second); err != nil {
			return run, err
		}
	}
}

// cancelRun stops the run on the server after Ctrl-C; the interrupt
// (exit 130) still ends the CLI.
func (a *App) cancelRun(ctx context.Context, s *session, id string) error {
	cctx, done := context.WithTimeout(context.WithoutCancel(ctx), 10*time.Second)
	defer done()
	p := a.Printer
	run, err := s.Client.CancelCommandRun(cctx, id)
	switch {
	case err != nil:
		p.Warning("Couldn't cancel the command: %v", err)
	case p.JSON:
		a.printJSONLine(map[string]any{"run": run})
	default:
		p.Info(p.Dim("Cancelling the command…"))
	}
	return ctx.Err()
}

// finishRun maps a finished run to the CLI's exit code.
func (a *App) finishRun(run api.CommandRun) error {
	p := a.Printer
	if p.JSON {
		a.printJSONLine(map[string]any{"run": run})
	} else if run.OutputTruncated {
		p.Info(p.Dim("The output passed its limit and the rest wasn't kept."))
	}
	reason := ""
	if run.Error != nil {
		reason = *run.Error
	}
	switch run.Status {
	case api.CommandRunStatusSucceeded:
		return nil
	case api.CommandRunStatusFailed:
		code := exitcode.Error
		if run.ExitCode != nil && *run.ExitCode > 0 && *run.ExitCode < 256 {
			code = *run.ExitCode
		}
		return &childExit{code}
	case api.CommandRunStatusTimedOut:
		return exitcode.New(exitcode.CommandTimedOut, fmt.Errorf("the command ran longer than %s and was stopped", time.Duration(run.TimeoutSeconds)*time.Second))
	case api.CommandRunStatusCancelled:
		msg := "the command was cancelled"
		if run.CancelledBy != nil && *run.CancelledBy != "" {
			msg += " by " + *run.CancelledBy
		}
		return exitcode.New(exitcode.Interrupted, errors.New(msg))
	default:
		msg := "the command couldn't run"
		if reason != "" {
			msg += ": " + reason
		}
		return exitcode.New(exitcode.CommandFailed, errors.New(msg))
	}
}

// printCommandLine writes one output line where it came from: stdout to
// stdout, stderr and launchroom's own lines (dimmed) to stderr; with --json
// every line is one JSON object on stdout.
func (a *App) printCommandLine(l api.CommandOutputLine) {
	p := a.Printer
	switch {
	case p.JSON:
		a.printJSONLine(l)
	case l.Stream == "stdout":
		_, _ = fmt.Fprintln(p.Out, l.Text)
	case l.Stream == "stderr":
		_, _ = fmt.Fprintln(p.Err, l.Text)
	default:
		_, _ = fmt.Fprintln(p.Err, p.Dim(l.Text))
	}
}

func (a *App) printJSONLine(v any) {
	b, _ := json.Marshal(v)
	_, _ = fmt.Fprintln(a.Printer.Out, string(b))
}

// withRunHint says how to get the run ability when a token lacks it.
func (a *App) withRunHint(err error) error {
	var apiErr *api.Error
	if a.Printer.JSON || !errors.As(err, &apiErr) || apiErr.Ability != "run" {
		return err
	}
	return fmt.Errorf("%w\n  Sign in again with `launchroom login` and allow running commands, or use a token with \"Run commands\"", err)
}

// quoteArgv shows an argv for reading (never for running): arguments with
// spaces or quotes are quoted.
func quoteArgv(argv []string) string {
	out := make([]string, len(argv))
	for i, arg := range argv {
		if arg == "" || strings.ContainsAny(arg, " \t\n\"'\\`$") {
			arg = strconv.Quote(arg)
		}
		out[i] = arg
	}
	return strings.Join(out, " ")
}
