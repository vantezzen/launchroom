package main

import (
	"bufio"
	"context"
	"encoding/json"
	"errors"
	"flag"
	"fmt"
	"io"
	"log/slog"
	"os"
	"os/signal"
	"path/filepath"
	"strings"
	"syscall"
	"time"

	"github.com/moby/moby/client"

	"github.com/vantezzen/launchroom/daemon/internal/doctor"
	"github.com/vantezzen/launchroom/daemon/internal/names"
	"github.com/vantezzen/launchroom/daemon/internal/state"
	"github.com/vantezzen/launchroom/daemon/internal/update"
)

// runDoctor is `launchroomd doctor` (internal/doctor): the host helper runs
// it on the control-plane server with --dir and the host's Docker socket;
// `docker compose run --rm launchroomd doctor` runs it on any other server
// as launchroomd itself (guard socket, /data).
func runDoctor(args []string, stdin io.Reader, stdout, stderr io.Writer) int {
	fs := flag.NewFlagSet("doctor", flag.ContinueOnError)
	fs.SetOutput(stderr)
	dir := fs.String("dir", "", "control-plane install folder (mounted at the same path); empty: this server's launchroomd")
	asJSON := fs.Bool("json", false, "print the report as JSON")
	bundle := fs.Bool("bundle", false, "also write a redacted support bundle")
	fix := fs.String("fix", "", "apply a fix (ID or ID:target) after typing yes")
	hostRoot := fs.String("host-root", "/host", "where the host's root is mounted read-only (control plane)")
	dockerFS := fs.String("docker-fs", "/run/docker-fs", "a path on Docker's filesystem (server mode)")
	command := fs.String("command", "", "how to run the doctor again, for fix hints")
	if err := fs.Parse(args); err != nil {
		return 2
	}
	var fixID doctor.FixID
	var fixTarget string
	if *fix != "" {
		var err error
		if fixID, fixTarget, err = doctor.ParseFix(*fix); err != nil {
			_, _ = fmt.Fprintln(stderr, "launchroom doctor:", err)
			return 2
		}
	}

	ctx, stop := signal.NotifyContext(context.Background(), os.Interrupt, syscall.SIGTERM)
	defer stop()
	api, err := newUpdateAPI()
	if err != nil {
		_, _ = fmt.Fprintln(stderr, "launchroom doctor: no Docker client:", err)
		return 1
	}
	o := doctor.Options{Engine: &doctor.Moby{API: api}, Version: version, Command: *command}
	if *dir != "" {
		d := filepath.Clean(*dir)
		if !filepath.IsAbs(d) || d == "/" {
			_, _ = fmt.Fprintln(stderr, "launchroom doctor: --dir must be an absolute path")
			return 2
		}
		o.Mode, o.Dir, o.DataDir = doctor.ModeControlPlane, d, filepath.Join(d, "data", "daemon")
		if st, err := os.Stat(*hostRoot); err == nil && st.IsDir() {
			o.HostRoot = *hostRoot
		}
	} else {
		o.Mode, o.DataDir = doctor.ModeServer, envOr("LR_DATA_DIR", "/data")
		o.Dir = filepath.Dir(o.DataDir)
		o.ControlPlaneURL = os.Getenv("LR_CONTROL_PLANE_URL")
		if _, err := os.Stat(*dockerFS); err == nil {
			o.DockerFS = *dockerFS
		}
	}
	hooks := doctorHooks(api, o.Dir)

	rep := doctor.Run(ctx, o)
	exit := 0
	if fixID != "" {
		confirm := typedYes(stdin, stdout)
		if err := doctor.Apply(ctx, o, hooks, rep, fixID, fixTarget, confirm, stdout); err != nil {
			exit = 1
		}
		rep = doctor.Run(ctx, o)
		_, _ = fmt.Fprintln(stdout)
	}
	bundlePath := ""
	if *bundle {
		if bundlePath, err = doctor.WriteBundle(ctx, o, rep); err != nil {
			_, _ = fmt.Fprintln(stderr, "launchroom doctor: could not write the support bundle:", err)
			exit = 1
		}
	}
	if *asJSON {
		out := struct {
			doctor.Report
			Bundle string `json:"bundle,omitempty"`
		}{rep, bundlePath}
		b, _ := json.MarshalIndent(out, "", "  ")
		_, _ = fmt.Fprintln(stdout, string(b))
	} else {
		cmd := o.Command
		if cmd == "" {
			cmd = "./launchroom doctor"
			if o.Mode == doctor.ModeServer {
				cmd = "docker compose run --rm launchroomd doctor"
			}
		}
		doctor.WriteText(stdout, rep, cmd)
		if bundlePath != "" {
			_, _ = fmt.Fprintf(stdout, "\nWrote the support bundle %s. Look through it before you share it.\n", bundlePath)
		}
	}
	if rep.Failed() {
		exit = 1
	}
	return exit
}

// doctorHooks are the fixes that run compose or the update rollback; only
// on the control-plane server (the host socket).
func doctorHooks(api *client.Client, dir string) doctor.Hooks {
	if dir == "" || !strings.HasPrefix(dir, "/") {
		return doctor.Hooks{}
	}
	return doctor.Hooks{
		ComposeUp: func(ctx context.Context, service string) error {
			env, err := update.ReadDotEnv(filepath.Join(dir, ".env"))
			if err != nil {
				return err
			}
			img := state.ImageRef(env["LR_COMPOSE_IMAGE"])
			if err := update.EnsureImage(ctx, api, img); err != nil {
				return err
			}
			code, out, err := update.RunHelper(ctx, api, update.HelperRun{
				Name: names.SystemPrefix + "doctor-compose", Image: img, ProjectDir: dir, Timeout: 10 * time.Minute,
				Cmd: []string{"docker", "compose", "--project-directory", dir, "-p", "launchroom", "up", "-d", "--wait", "--wait-timeout", "300", service},
			})
			if err != nil {
				return err
			}
			if code != 0 {
				return fmt.Errorf("docker compose up exited with %d: %s", code, lastLine(out))
			}
			return nil
		},
		FinishRollback: func(ctx context.Context, task string) (string, error) {
			st, err := update.FinishRollback(ctx, update.FinishOptions{API: api, ProjectDir: dir, TaskID: state.TaskID(task), Log: lineLogger(os.Stdout)})
			if err != nil {
				return "", err
			}
			if st.Outcome != update.OutcomeRolledBack {
				return "", errors.New(st.Error)
			}
			return "Back on " + st.From + ". Everything from before the update is in place again.", nil
		},
	}
}

// typedYes asks on stdout and reads the answer from stdin.
func typedYes(stdin io.Reader, stdout io.Writer) doctor.Confirm {
	in := bufio.NewReader(stdin)
	return func(doctor.FixRef) bool {
		_, _ = fmt.Fprint(stdout, `Type "yes" to do this: `)
		line, _ := in.ReadString('\n')
		ok := strings.TrimSpace(line) == "yes"
		if !ok {
			_, _ = fmt.Fprintln(stdout, "Skipped.")
		}
		return ok
	}
}

// runUpdateLocal is `launchroomd update-local --dir DIR [--bundle DIR]
// [--env KEY=VALUE]… [-- installer options]`: a manual control-plane update
// through the update supervisor (backup, database copy, rollback on
// failure). `--probe` only says that this version has it.
func runUpdateLocal(args []string, stdout, stderr io.Writer) int {
	fs := flag.NewFlagSet("update-local", flag.ContinueOnError)
	fs.SetOutput(stderr)
	dir := fs.String("dir", "", "control-plane install folder (mounted at the same path)")
	bundleDir := fs.String("bundle", "", "unpacked release bundle to install (empty: pull the pins in .env again)")
	probe := fs.Bool("probe", false, "exit 0 if this version supports manual updates")
	var env multiFlag
	fs.Var(&env, "env", "KEY=VALUE for the installer (image pins), repeatable")
	if err := fs.Parse(args); err != nil {
		return 2
	}
	if *probe {
		_, _ = fmt.Fprintln(stdout, "update-local supported")
		return 0
	}
	ctx, stop := signal.NotifyContext(context.Background(), os.Interrupt, syscall.SIGTERM)
	defer stop()
	api, err := newUpdateAPI()
	if err != nil {
		_, _ = fmt.Fprintln(stderr, "launchroom: no Docker client:", err)
		return 1
	}
	st, err := update.RunLocal(ctx, update.LocalOptions{
		API: api, Log: lineLogger(stdout), ProjectDir: *dir, BundleDir: *bundleDir,
		InstallArgs: fs.Args(), InstallEnv: env,
	})
	if err != nil {
		_, _ = fmt.Fprintln(stderr, "launchroom: error:", err)
		return 1
	}
	switch st.Outcome {
	case update.OutcomeSucceeded:
		_, _ = fmt.Fprintln(stdout, "launchroom: updated. The copy of the files from before is in data/daemon/update/tasks/"+string(st.TaskID)+"/backup.")
		return 0
	case update.OutcomeRolledBack:
		_, _ = fmt.Fprintf(stderr, "launchroom: error: the update failed and everything went back to how it was: %s\n", st.Reason)
		if st.MigrationFailed != "" {
			_, _ = fmt.Fprintf(stderr, "launchroom: the new version's database migration failed: %s\n", st.MigrationFailed)
		}
		_, _ = fmt.Fprintf(stderr, "launchroom: logs of the containers that failed: data/daemon/update/tasks/%s/diagnostics\n", st.TaskID)
	default:
		_, _ = fmt.Fprintln(stderr, "launchroom: error:", st.Error)
	}
	return 1
}

// runUpdateRollback is `launchroomd update-rollback --dir DIR [--task ID]`:
// finishes the rollback of an update whose rollback stopped half-way.
func runUpdateRollback(args []string, stdout, stderr io.Writer) int {
	fs := flag.NewFlagSet("update-rollback", flag.ContinueOnError)
	fs.SetOutput(stderr)
	dir := fs.String("dir", "", "install folder (mounted at the same path)")
	task := fs.String("task", "", "update task id (default: the newest one whose rollback failed)")
	if err := fs.Parse(args); err != nil {
		return 2
	}
	api, err := newUpdateAPI()
	if err != nil {
		_, _ = fmt.Fprintln(stderr, "launchroom: no Docker client:", err)
		return 1
	}
	st, err := update.FinishRollback(context.Background(), update.FinishOptions{API: api, ProjectDir: *dir, TaskID: state.TaskID(*task), Log: lineLogger(stdout)})
	if err != nil {
		_, _ = fmt.Fprintln(stderr, "launchroom: error:", err)
		return 1
	}
	if st.Outcome != update.OutcomeRolledBack {
		_, _ = fmt.Fprintln(stderr, "launchroom: error:", st.Error)
		return 1
	}
	_, _ = fmt.Fprintf(stdout, "launchroom: back on %s.\n", st.From)
	return 0
}

type multiFlag []string

func (m *multiFlag) String() string     { return strings.Join(*m, ",") }
func (m *multiFlag) Set(v string) error { *m = append(*m, v); return nil }

func envOr(key, def string) string {
	if v := os.Getenv(key); v != "" {
		return v
	}
	return def
}

func lastLine(s string) string {
	lines := strings.Split(strings.TrimSpace(s), "\n")
	return strings.TrimSpace(lines[len(lines)-1])
}

// lineLogger prints the supervisor's progress for people: "launchroom:
// <message>", with a helper's output indented below it.
func lineLogger(w io.Writer) *slog.Logger { return slog.New(&lineHandler{w: w}) }

type lineHandler struct {
	w     io.Writer
	attrs []slog.Attr
}

func (h *lineHandler) Enabled(_ context.Context, l slog.Level) bool { return l >= slog.LevelInfo }

func (h *lineHandler) Handle(_ context.Context, r slog.Record) error {
	var extra []string
	output := ""
	r.Attrs(func(a slog.Attr) bool {
		switch a.Key {
		case "output":
			output = a.Value.String()
		case "err", "error", "version", "to", "step":
			if s := a.Value.String(); s != "" {
				extra = append(extra, s)
			}
		}
		return true
	})
	line := "launchroom: " + r.Message
	if len(extra) > 0 {
		line += " (" + strings.Join(extra, ", ") + ")"
	}
	_, err := fmt.Fprintln(h.w, line)
	if output != "" {
		for l := range strings.SplitSeq(strings.TrimRight(output, "\n"), "\n") {
			_, _ = fmt.Fprintln(h.w, "    "+strings.TrimRight(l, "\r"))
		}
	}
	return err
}

func (h *lineHandler) WithAttrs(a []slog.Attr) slog.Handler {
	return &lineHandler{w: h.w, attrs: append(h.attrs, a...)}
}
func (h *lineHandler) WithGroup(string) slog.Handler { return h }
