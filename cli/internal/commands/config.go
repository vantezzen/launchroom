package commands

import (
	"errors"
	"fmt"
	"io"
	"io/fs"
	"os"
	"path/filepath"
	"strings"

	"github.com/spf13/cobra"

	"github.com/vantezzen/launchroom/cli/internal/api"
	"github.com/vantezzen/launchroom/cli/internal/config"
	"github.com/vantezzen/launchroom/cli/internal/configfile"
	"github.com/vantezzen/launchroom/cli/internal/exitcode"
)

// configCmd is `launchroom config` (docs/18 §7): launchroom.json.
func (a *App) configCmd() *cobra.Command {
	cmd := &cobra.Command{
		Use:   "config",
		Short: "launchroom.json: download, check and compare the environment's configuration",
		Long: "launchroom.json keeps service settings next to your code; the dashboard reads it at every push.\n" +
			"`config pull` writes the linked environment as launchroom.json, `config validate` checks a file and\n" +
			"`config diff` shows the staged changes it would produce in the environment.",
	}
	cmd.AddCommand(a.configPullCmd(), a.configValidateCmd(), a.configDiffCmd())
	return cmd
}

func (a *App) configPath(args []string) (string, error) {
	file := configfile.DefaultPath
	if len(args) == 1 {
		file = args[0]
	}
	if filepath.IsAbs(file) {
		return file, nil
	}
	wd, err := a.Getwd()
	if err != nil {
		return "", err
	}
	return filepath.Join(wd, file), nil
}

func (a *App) configPullCmd() *cobra.Command {
	var yes bool
	var revision int
	cmd := &cobra.Command{
		Use:   "pull [FILE]",
		Short: "Write the linked environment as launchroom.json",
		Long: "Writes the environment's current settings (staged changes included) to FILE (default launchroom.json).\n" +
			"Secrets are written as { \"secret\": true }, never their values. --revision exports a deployed revision.",
		Args: cobra.MaximumNArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			ctx := cmd.Context()
			file, err := a.configPath(args)
			if err != nil {
				return err
			}
			p := a.Printer
			if _, err := os.Stat(file); err == nil && !yes {
				if !p.TTY || p.JSON {
					return exitcode.Usagef("%s exists; pass --yes to overwrite it", file)
				}
				ok, err := a.confirm(fmt.Sprintf("Overwrite %s?", file))
				if err != nil {
					return err
				}
				if !ok {
					return exitcode.New(exitcode.Refused, errors.New("not overwritten"))
				}
			} else if err != nil && !errors.Is(err, fs.ErrNotExist) {
				return err
			}
			s, err := a.session()
			if err != nil {
				return err
			}
			t, err := a.resolve(ctx, s, false)
			if err != nil {
				return err
			}
			export, err := s.Client.ExportConfig(ctx, t.Environment.ID, revision)
			if err != nil {
				return err
			}
			if err := config.WriteFileAtomic(file, []byte(export.Content), 0o644); err != nil {
				return err
			}
			out := map[string]any{"file": file, "environment": t.Environment.ID, "revision": export.Revision, "suggestedPath": export.Path}
			return p.Result(out, func(w io.Writer) {
				from := t.Environment.Name
				if export.Revision != nil {
					from = fmt.Sprintf("%s (revision %d)", from, *export.Revision)
				}
				_, _ = fmt.Fprintf(w, "%s Wrote %s from %s.\n", p.Good("✓"), file, from)
				if filepath.Base(file) != filepath.Base(export.Path) {
					p.Info(p.Dim(fmt.Sprintf("The project reads %s; rename the file or change the path in Settings → General.", export.Path)))
				}
				p.Info(p.Dim("Commit it: the next push applies what it says."))
			})
		},
	}
	cmd.Flags().BoolVarP(&yes, "yes", "y", false, "overwrite FILE without asking")
	cmd.Flags().IntVar(&revision, "revision", 0, "export this deployed revision instead of the current settings")
	return cmd
}

// readConfig reads FILE and checks its JSON syntax locally.
func (a *App) readConfig(args []string) (string, []byte, error) {
	file, err := a.configPath(args)
	if err != nil {
		return "", nil, err
	}
	content, err := os.ReadFile(file) //nolint:gosec // the user's own file, named on the command line
	if err != nil {
		if errors.Is(err, fs.ErrNotExist) {
			return file, nil, exitcode.Usagef("%s doesn't exist (run `launchroom config pull` to start one)", file)
		}
		return file, nil, err
	}
	return file, content, nil
}

// localProblem turns a syntax error into the API's problem shape.
func localProblem(err error) (api.ConfigProblem, bool) {
	var se *configfile.SyntaxError
	if !errors.As(err, &se) {
		return api.ConfigProblem{}, false
	}
	line, column := se.Line, se.Column
	return api.ConfigProblem{Message: se.Message, Line: &line, Column: &column}, true
}

func (a *App) checkConfig(cmd *cobra.Command, args []string, diff bool) (string, []byte, api.ConfigCheck, error) {
	file, content, err := a.readConfig(args)
	if err != nil {
		return file, nil, api.ConfigCheck{}, err
	}
	if err := configfile.Check(content); err != nil {
		if problem, ok := localProblem(err); ok {
			return file, content, api.ConfigCheck{Valid: false, Errors: []api.ConfigProblem{problem}}, nil
		}
		return file, content, api.ConfigCheck{}, err
	}
	ctx := cmd.Context()
	s, err := a.session()
	if err != nil {
		return file, content, api.ConfigCheck{}, err
	}
	t, err := a.resolve(ctx, s, false)
	if err != nil {
		return file, content, api.ConfigCheck{}, err
	}
	res, err := s.Client.CheckConfig(ctx, t.Environment.ID, string(content), diff)
	return file, content, res, err
}

// printProblems writes problems as `file:line:column  message`, the lines
// around them with a caret, and the hint.
func (a *App) printProblems(w io.Writer, file string, content []byte, problems []api.ConfigProblem, label string) {
	p := a.Printer
	name := filepath.Base(file)
	for _, problem := range problems {
		where := name
		if problem.Line != nil && problem.Column != nil {
			where = fmt.Sprintf("%s:%d:%d", name, *problem.Line, *problem.Column)
		}
		_, _ = fmt.Fprintf(w, "%s %s  %s\n", label, p.Bold(where), problem.Message)
		if problem.Line != nil {
			column := 0
			if problem.Column != nil {
				column = *problem.Column
			}
			lines, first := configfile.Around(content, *problem.Line)
			_, _ = fmt.Fprint(w, p.Dim(configfile.Excerpt(lines, first, *problem.Line, column)))
		}
		if problem.Hint != nil && *problem.Hint != "" {
			_, _ = fmt.Fprintf(w, "  %s\n", p.Dim(*problem.Hint))
		}
		_, _ = fmt.Fprintln(w)
	}
}

func invalid(res api.ConfigCheck) error {
	if res.Valid {
		return nil
	}
	n := len(res.Errors)
	word := "problem"
	if n != 1 {
		word = "problems"
	}
	return exitcode.New(exitcode.Refused, fmt.Errorf("launchroom.json has %d %s", n, word))
}

func (a *App) configValidateCmd() *cobra.Command {
	return &cobra.Command{
		Use:   "validate [FILE]",
		Short: "Check launchroom.json against the linked environment",
		Long:  "Checks JSON syntax locally, then the schema and rules in the dashboard (the same checks a push runs). Exits 6 when the file has problems.",
		Args:  cobra.MaximumNArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			file, content, res, err := a.checkConfig(cmd, args, false)
			if err != nil {
				return err
			}
			p := a.Printer
			if err := p.Result(res, func(w io.Writer) {
				a.printProblems(w, file, content, res.Errors, p.Bad("✕"))
				a.printProblems(w, file, content, res.Warnings, p.Warn("!"))
				if res.Valid {
					_, _ = fmt.Fprintf(w, "%s %s is valid.\n", p.Good("✓"), filepath.Base(file))
				}
			}); err != nil {
				return err
			}
			return invalid(res)
		},
	}
}

func (a *App) configDiffCmd() *cobra.Command {
	return &cobra.Command{
		Use:   "diff [FILE]",
		Short: "Show the staged changes launchroom.json would produce",
		Long:  "A dry run in the dashboard: nothing is written. Domains are listed but apply right away when the file is pushed.",
		Args:  cobra.MaximumNArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			file, content, res, err := a.checkConfig(cmd, args, true)
			if err != nil {
				return err
			}
			p := a.Printer
			if err := p.Result(res, func(w io.Writer) {
				if !res.Valid {
					a.printProblems(w, file, content, res.Errors, p.Bad("✕"))
					return
				}
				if len(res.Changes) == 0 {
					_, _ = fmt.Fprintf(w, "%s The environment already matches %s.\n", p.Good("✓"), filepath.Base(file))
				}
				for _, change := range res.Changes {
					_, _ = fmt.Fprintln(w, changeLine(p.Good, p.Bad, p.Warn, p.Dim, change))
				}
				for _, note := range res.Ignored {
					_, _ = fmt.Fprintf(w, "%s %s\n", p.Dim("–"), p.Dim(note.Message))
				}
				if len(res.Changes) > 0 {
					_, _ = fmt.Fprintf(w, "\n%s\n", p.Dim(fmt.Sprintf("%d %s. Push the file to apply them.", len(res.Changes), plural(len(res.Changes), "change", "changes"))))
				}
			}); err != nil {
				return err
			}
			return invalid(res)
		},
	}
}

// changeLine is `+ redis  new database (new)`, `~ web: build command  npm run build → pnpm build (rebuild)`.
func changeLine(good, bad, warn, dim func(string) string, change api.ChangeItem) string {
	sign := map[string]string{"added": good("+"), "removed": bad("−"), "changed": warn("~")}[change.Kind]
	if sign == "" {
		sign = "•"
	}
	line := sign + " " + change.Label
	if change.Kind == "changed" && (change.Before != nil || change.After != nil) {
		line += "  " + dim(orDash(change.Before)+" → "+orDash(change.After))
	}
	if change.Effect != "" && change.Effect != "none" {
		line += " " + dim("("+change.Effect+")")
	}
	return line
}

func orDash(s *string) string {
	if s == nil || strings.TrimSpace(*s) == "" {
		return "—"
	}
	return *s
}
