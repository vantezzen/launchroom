package commands

import (
	"errors"
	"fmt"
	"io"
	"io/fs"
	"os"
	"path/filepath"
	"runtime"
	"strings"

	"github.com/spf13/cobra"

	"github.com/vantezzen/launchroom/cli/internal/api"
	"github.com/vantezzen/launchroom/cli/internal/exitcode"
	"github.com/vantezzen/launchroom/cli/internal/fsutil"
	"github.com/vantezzen/launchroom/cli/internal/link"
	"github.com/vantezzen/launchroom/cli/internal/mcpclients"
	"github.com/vantezzen/launchroom/cli/internal/mcpserver"
)

// mcpCmd is `launchroom mcp` (D112, docs/16 §7): an MCP server on stdio for
// AI coding agents, with the CLI's own credentials and link.
func (a *App) mcpCmd() *cobra.Command {
	var dir string
	cmd := &cobra.Command{
		Use:   "mcp",
		Short: "Run the MCP server for AI coding agents (stdio)",
		Long: "Lets an AI coding agent in your editor deploy, follow deployments, read failures and logs, apply fixes and run commands, " +
			"with your stored sign-in and this directory's link. Editors start it themselves: set it up with `launchroom mcp install`.\n\n" +
			"It speaks the Model Context Protocol on stdin and stdout; nothing else is printed there. The agent can do what the token allows " +
			"and nothing more: sign in for it with `launchroom login --abilities read,deploy,fix,env:read,env:write,run`.",
		Args: cobra.NoArgs,
		RunE: func(cmd *cobra.Command, _ []string) error {
			if dir != "" {
				abs, err := filepath.Abs(dir)
				if err != nil {
					return exitcode.New(exitcode.Usage, err)
				}
				dir = abs
			}
			srv := mcpserver.New(mcpserver.Options{
				Version: a.Version,
				Dir:     dir,
				Getwd:   a.Getwd,
				Session: func(l *link.Link) (*api.Client, string, error) {
					u, err := a.dashboardURLFor(l)
					if err != nil {
						return nil, "", err
					}
					s, err := a.sessionAt(u)
					if err != nil {
						return nil, "", err
					}
					return s.Client, s.URL, nil
				},
				Sleep: a.Sleep,
				Log:   a.Printer.Err,
			})
			err := srv.Serve(cmd.Context(), a.Stdin, a.Printer.Out)
			if errors.Is(err, io.EOF) || cmd.Context().Err() != nil {
				return nil
			}
			return err
		},
	}
	cmd.Flags().StringVar(&dir, "dir", "", "the project directory (default: the editor's workspace, else the working directory)")
	cmd.AddCommand(a.mcpInstallCmd())
	return cmd
}

type installEntry struct {
	Client  string `json:"client"`
	Name    string `json:"name"`
	File    string `json:"file,omitempty"`
	Command string `json:"command,omitempty"`
	Snippet string `json:"snippet"`
	Written bool   `json:"written"`
}

// mcpInstallCmd prints (or writes) each editor's configuration entry.
func (a *App) mcpInstallCmd() *cobra.Command {
	var client string
	var write, yes, global bool
	cmd := &cobra.Command{
		Use:   "install [--client " + strings.Join(mcpclients.IDs(), "|") + "] [--write [--global] [--yes]]",
		Short: "Connect an AI coding agent: print or write its MCP configuration",
		Long: "Prints where each editor keeps its MCP servers and the entry that starts `launchroom mcp`; --write adds it to that file after showing it. " +
			"The entry holds no token: the server uses your `launchroom login`.\n\n" +
			"  launchroom mcp install                          every supported editor\n" +
			"  launchroom mcp install --client cursor --write  add it to .cursor/mcp.json in this project\n" +
			"  launchroom mcp install --client cursor --write --global   to ~/.cursor/mcp.json",
		Args: cobra.NoArgs,
		RunE: func(cmd *cobra.Command, _ []string) error {
			clients := mcpclients.Clients
			if client != "" {
				c, ok := mcpclients.Find(client)
				if !ok {
					return exitcode.Usagef("--client must be one of %s", oneOf(mcpclients.IDs()))
				}
				clients = []mcpclients.Client{c}
			} else if write {
				return exitcode.Usagef("--write needs --client (one of %s)", oneOf(mcpclients.IDs()))
			}
			project, err := a.projectDir()
			if err != nil {
				return err
			}
			home, err := os.UserHomeDir()
			if err != nil {
				return err
			}
			var out []installEntry
			for _, c := range clients {
				e, err := a.installFor(c, project, home, write, global, yes)
				if err != nil {
					return err
				}
				out = append(out, e)
			}
			return a.Printer.Result(out, func(w io.Writer) { a.printInstall(w, out, write) })
		},
	}
	f := cmd.Flags()
	f.StringVar(&client, "client", "", "one editor: "+oneOf(mcpclients.IDs()))
	f.BoolVar(&write, "write", false, "add the entry to the editor's configuration file (asks first)")
	f.BoolVar(&global, "global", false, "with --write: the user-wide file instead of this project's")
	f.BoolVar(&yes, "yes", false, "with --write: don't ask (needed without a terminal)")
	return cmd
}

// projectDir is the linked directory, else the working directory.
func (a *App) projectDir() (string, error) {
	wd, err := a.Getwd()
	if err != nil {
		return "", err
	}
	if l, dir, err := link.Find(wd); err == nil && l != nil {
		return dir, nil
	}
	return filepath.Abs(wd)
}

// installFor works out one client's file and entry and writes it if asked.
func (a *App) installFor(c mcpclients.Client, project, home string, write, global, yes bool) (installEntry, error) {
	file := ""
	shared := false
	switch {
	case global || c.Project == "":
		file = c.GlobalPath(home, runtime.GOOS)
	default:
		file, shared = filepath.Join(project, c.Project), true
	}
	command := "launchroom"
	if !shared {
		// Editors started from the dock don't see ~/.local/bin on PATH.
		if exe, err := os.Executable(); err == nil {
			command = exe
		}
	}
	args := []string{"mcp"}
	if c.NeedsDir {
		args = append(args, "--dir", project)
	}
	e := installEntry{Client: c.ID, Name: c.Name, File: file, Snippet: c.Snippet(command, args)}
	if c.Command != nil {
		e.Command = c.Command(command, args)
	}
	if !write {
		return e, nil
	}
	if file == "" {
		return e, exitcode.Usagef("%s has no configuration file launchroom can write; run: %s", c.Name, e.Command)
	}
	existing, err := os.ReadFile(file) //nolint:gosec // the editor's own configuration file, chosen by --client
	if err != nil && !errors.Is(err, fs.ErrNotExist) {
		return e, err
	}
	merged, changed, err := c.Merge(existing, command, args)
	if errors.Is(err, mcpclients.ErrUnreadable) {
		return e, exitcode.New(exitcode.Refused, fmt.Errorf("%s isn't plain JSON (%w), so it was left alone: add this yourself\n%s", file, err, e.Snippet))
	}
	if err != nil {
		return e, err
	}
	if !changed {
		a.Printer.Info("%s already starts launchroom (%s).", c.Name, file)
		return e, nil
	}
	if !yes {
		if !a.Printer.TTY {
			return e, exitcode.Usagef("pass --yes to write %s without a terminal", file)
		}
		a.Printer.Info("This adds to %s:\n\n%s", file, indent(e.Snippet))
		ok, err := a.confirm("Write it?")
		if err != nil {
			return e, err
		}
		if !ok {
			return e, exitcode.New(exitcode.Refused, errors.New("nothing written"))
		}
	}
	mode := os.FileMode(0o644)
	if st, err := os.Stat(file); err == nil {
		mode = st.Mode().Perm()
	}
	if err := fsutil.WriteFileAtomic(file, merged, mode); err != nil {
		return e, err
	}
	e.Written = true
	return e, nil
}

func indent(s string) string {
	return "  " + strings.ReplaceAll(strings.TrimRight(s, "\n"), "\n", "\n  ")
}

func (a *App) printInstall(w io.Writer, out []installEntry, write bool) {
	p := a.Printer
	for i, e := range out {
		if i > 0 {
			_, _ = fmt.Fprintln(w)
		}
		if e.Written {
			_, _ = fmt.Fprintf(w, "%s Added launchroom to %s (%s). Restart %s or reload its MCP servers.\n", p.Good("✓"), e.Name, e.File, e.Name)
			continue
		}
		if write {
			continue
		}
		_, _ = fmt.Fprintln(w, p.Bold(e.Name))
		if e.Command != "" {
			_, _ = fmt.Fprintf(w, "  Run: %s\n", e.Command)
			if e.File != "" {
				_, _ = fmt.Fprintf(w, "  Or add to %s:\n", e.File)
			}
		} else if e.File != "" {
			_, _ = fmt.Fprintf(w, "  Add to %s:\n", e.File)
		}
		_, _ = fmt.Fprintln(w, indent(e.Snippet))
	}
	if !write {
		_, _ = fmt.Fprintln(w)
		_, _ = fmt.Fprintln(w, p.Dim("No token goes into these files: the server uses your `launchroom login`. Write one with --client NAME --write."))
	}
}
