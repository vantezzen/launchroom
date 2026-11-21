package commands

import (
	"context"
	"errors"
	"fmt"
	"io"
	"io/fs"
	"os"
	"path/filepath"
	"sort"
	"strings"

	"github.com/spf13/cobra"

	"github.com/vantezzen/launchroom/cli/internal/api"
	"github.com/vantezzen/launchroom/cli/internal/dotenv"
	"github.com/vantezzen/launchroom/cli/internal/exitcode"
	"github.com/vantezzen/launchroom/cli/internal/fsutil"
	"github.com/vantezzen/launchroom/cli/internal/gitignore"
	"github.com/vantezzen/launchroom/cli/internal/text"
)

func (a *App) envCmd() *cobra.Command {
	cmd := &cobra.Command{
		Use:   "env",
		Short: "Variables of the linked service",
		Long:  "Changes are staged like in the dashboard: `launchroom deploy --staged` applies them.",
	}
	cmd.AddCommand(a.envLsCmd(), a.envSetCmd(), a.envRmCmd(), a.envPullCmd())
	return cmd
}

func (a *App) envTarget(ctx context.Context) (*session, *target, error) {
	s, err := a.session()
	if err != nil {
		return nil, nil, err
	}
	t, err := a.resolve(ctx, s, true)
	return s, t, err
}

func (a *App) envLsCmd() *cobra.Command {
	return &cobra.Command{
		Use:   "ls",
		Short: "List variables (secret values are never listed)",
		Args:  cobra.NoArgs,
		RunE: func(cmd *cobra.Command, _ []string) error {
			s, t, err := a.envTarget(cmd.Context())
			if err != nil {
				return err
			}
			vars, err := s.Client.Variables(cmd.Context(), t.Environment.ID, t.Service.ID)
			if err != nil {
				return err
			}
			return a.Printer.Result(vars, func(w io.Writer) {
				p := a.Printer
				if len(vars) == 0 {
					_, _ = fmt.Fprintf(w, "%s has no variables in %s.\n", t.Service.Name, t.Environment.Name)
					return
				}
				rows := make([][]string, 0, len(vars))
				for _, v := range vars {
					value := p.Dim("•••••• secret")
					if v.Value != nil {
						value = *v.Value
						if len(value) > 60 {
							value = text.Clip(value, 57) + "…"
						}
					}
					note := ""
					if v.Connection {
						note = p.Dim("from a connection")
					}
					rows = append(rows, []string{v.Key, value, note})
				}
				p.Table(w, []string{"key", "value", ""}, rows)
			})
		},
	}
}

type setResult struct {
	Key    string `json:"key"`
	ID     string `json:"id"`
	Type   string `json:"type"`
	Action string `json:"action"` // created | updated
}

func (a *App) envSetCmd() *cobra.Command {
	var secret, apply, detach bool
	cmd := &cobra.Command{
		Use:   "set KEY=VALUE...",
		Short: "Add or change variables in this environment (staged; --apply deploys them)",
		Long:  "Adds or changes variables of the linked service in this environment. They are staged until deployed: --apply deploys the environment's staged changes right after and follows the rollout (--json then prints the deployments).",
		Args:  cobra.MinimumNArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			ctx := cmd.Context()
			pairs := make([][2]string, 0, len(args))
			for _, arg := range args {
				k, v, ok := strings.Cut(arg, "=")
				if !ok || !dotenv.ValidKey(k) {
					return exitcode.Usagef("%q is not KEY=VALUE (keys: letters, digits and _, not starting with a digit)", arg)
				}
				pairs = append(pairs, [2]string{k, v})
			}
			s, t, err := a.envTarget(ctx)
			if err != nil {
				return err
			}
			vars, err := s.Client.Variables(ctx, t.Environment.ID, t.Service.ID)
			if err != nil {
				return err
			}
			byKey := map[string]api.Variable{}
			for _, v := range vars {
				if v.Editable && !v.Connection {
					byKey[v.Key] = v
				}
			}
			typ := "config"
			if secret {
				typ = "secret"
			}
			results := make([]setResult, 0, len(pairs))
			for _, kv := range pairs {
				if existing, ok := byKey[kv[0]]; ok {
					fields := map[string]any{"value": kv[1]}
					if secret && existing.Type != "secret" {
						fields["type"] = "secret"
					}
					v, err := s.Client.UpdateVariable(ctx, t.Environment.ID, existing.ID, fields)
					if err != nil {
						return fmt.Errorf("%s: %w", kv[0], err)
					}
					results = append(results, setResult{v.Key, v.ID, v.Type, "updated"})
					continue
				}
				v, err := s.Client.CreateVariable(ctx, t.Environment.ID, t.Service.ID, kv[0], kv[1], typ)
				if err != nil {
					return fmt.Errorf("%s: %w", kv[0], err)
				}
				results = append(results, setResult{v.Key, v.ID, v.Type, "created"})
			}
			if apply {
				return a.applyStaged(ctx, s, t, results, detach)
			}
			return a.Printer.Result(results, func(w io.Writer) {
				for _, r := range results {
					_, _ = fmt.Fprintf(w, "%s %s %s\n", a.Printer.Good("✓"), r.Key, a.Printer.Dim(r.Action+" ("+r.Type+")"))
				}
				_, _ = fmt.Fprintln(w, a.Printer.Dim("Staged. Run `launchroom deploy --staged` (or `env set --apply`) to apply."))
			})
		},
	}
	cmd.Flags().BoolVar(&secret, "secret", false, "store the values as secrets")
	cmd.Flags().BoolVar(&apply, "apply", false, "deploy the environment's staged changes right after")
	cmd.Flags().BoolVar(&detach, "detach", false, "with --apply: don't wait for the deployments to finish")
	return cmd
}

func (a *App) envRmCmd() *cobra.Command {
	return &cobra.Command{
		Use:   "rm KEY...",
		Short: "Remove variables (staged)",
		Args:  cobra.MinimumNArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			ctx := cmd.Context()
			s, t, err := a.envTarget(ctx)
			if err != nil {
				return err
			}
			vars, err := s.Client.Variables(ctx, t.Environment.ID, t.Service.ID)
			if err != nil {
				return err
			}
			removed := []string{}
			for _, key := range args {
				found := false
				for _, v := range vars {
					if v.Key != key || v.Connection {
						continue
					}
					found = true
					if err := s.Client.DeleteVariable(ctx, t.Environment.ID, v.ID); err != nil {
						return fmt.Errorf("%s: %w", key, err)
					}
				}
				if !found {
					return exitcode.New(exitcode.NotFound, fmt.Errorf("%s has no variable %s in %s", t.Service.Name, key, t.Environment.Name))
				}
				removed = append(removed, key)
			}
			return a.Printer.Result(map[string]any{"removed": removed}, func(w io.Writer) {
				_, _ = fmt.Fprintf(w, "%s Removed %s. %s\n", a.Printer.Good("✓"), strings.Join(removed, ", "), a.Printer.Dim("Staged: `launchroom deploy --staged` applies it."))
			})
		},
	}
}

// fetchEnv resolves the service's env (secrets when wanted and allowed).
func (a *App) fetchEnv(ctx context.Context, withSecrets bool) (*target, api.ResolvedEnv, error) {
	s, t, err := a.envTarget(ctx)
	if err != nil {
		return nil, api.ResolvedEnv{}, err
	}
	env, err := s.Client.ResolvedEnv(ctx, t.Environment.ID, t.Service.ID, withSecrets)
	if env.Variables == nil {
		env.Variables = map[string]string{}
	}
	return t, env, err
}

func (a *App) envPullCmd() *cobra.Command {
	var yes, noSecrets bool
	cmd := &cobra.Command{
		Use:   "pull [FILE]",
		Short: "Write the service's variables to a .env file",
		Long: "Writes the resolved variables of the last deployed revision to FILE (default .env, mode 0600). " +
			"References to other services resolve to their private names (postgres:5432), which work inside the environment only.",
		Args: cobra.MaximumNArgs(1),
		RunE: func(cmd *cobra.Command, args []string) error {
			ctx := cmd.Context()
			file := ".env"
			if len(args) == 1 {
				file = args[0]
			}
			wd, err := a.Getwd()
			if err != nil {
				return err
			}
			if !filepath.IsAbs(file) {
				file = filepath.Join(wd, file)
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
			t, env, err := a.fetchEnv(ctx, !noSecrets)
			if err != nil {
				return err
			}
			header := fmt.Sprintf("Pulled by launchroom from %s / %s.\nContains secrets: never commit this file.", t.Service.Name, t.Environment.Name)
			if err := fsutil.WriteFileAtomic(file, []byte(dotenv.Render(env.Variables, header)), 0o600); err != nil {
				return err
			}
			warnings := []string{}
			if res, err := gitignore.Check(file); err == nil && res.InRepo && !res.Ignored {
				warnings = append(warnings, fmt.Sprintf("%s is not git-ignored: add it to .gitignore so it is never committed", file))
			}
			sort.Strings(env.Withheld)
			out := map[string]any{"file": file, "count": len(env.Variables), "withheld": env.Withheld, "revision": env.Revision, "warnings": warnings}
			return p.Result(out, func(w io.Writer) {
				_, _ = fmt.Fprintf(w, "%s Wrote %d %s to %s.\n", p.Good("✓"), len(env.Variables), plural(len(env.Variables), "variable", "variables"), file)
				if len(env.Withheld) > 0 {
					p.Warning("Left out %d secret %s (%s): the token needs the secrets:read ability.", len(env.Withheld), plural(len(env.Withheld), "value", "values"), strings.Join(env.Withheld, ", "))
				}
				for _, warn := range warnings {
					p.Warning("%s", warn)
				}
				p.Info(p.Dim("References to other services use their private names, reachable only inside the environment."))
			})
		},
	}
	cmd.Flags().BoolVarP(&yes, "yes", "y", false, "overwrite FILE without asking")
	cmd.Flags().BoolVar(&noSecrets, "no-secrets", false, "leave secret values out")
	return cmd
}

// applyStaged deploys the staged changes after `env set --apply` and
// follows them like `deploy --staged`.
func (a *App) applyStaged(ctx context.Context, s *session, t *target, results []setResult, detach bool) error {
	p := a.Printer
	if !p.JSON {
		for _, r := range results {
			p.Info("%s %s %s", p.Good("✓"), r.Key, p.Dim(r.Action+" ("+r.Type+")"))
		}
	}
	res, err := s.Client.DeployStaged(ctx, t.Environment.ID, "")
	if err != nil {
		return err
	}
	if !p.JSON {
		p.Info("Revision %d of %s: deploying %d %s.", res.Revision, t.Environment.Name, len(res.Deployments), plural(len(res.Deployments), "service", "services"))
	}
	return a.finishDeployments(ctx, s, res.Deployments, detach)
}
