package mcpserver

import (
	"context"
	"fmt"
	"strings"

	"github.com/vantezzen/launchroom/cli/internal/api"
	"github.com/vantezzen/launchroom/cli/internal/mcp"
)

func (s *server) envTools() []entry {
	return []entry{
		on(mcp.NewTool("list_env", "List variables",
			"The variables a service gets in an environment: name, type (config or secret), whether a connection provides it, and where it applies. Never values: read what the app expects from its code, and ask the person for a value.",
			readOnly, s.listEnv), "service", api.APIAbilityEnvRead),
		on(mcp.NewTool("set_variable", "Set a variable",
			"Creates or changes one variable of a service in this environment. It is staged: apply_staged_changes (or the next deploy) makes it live. secret: true stores it as a secret. Overwrites an existing value.",
			destructiveIdempotent, s.setVariable), "service", api.APIAbilityEnvWrite),
		on(mcp.NewTool("get_config", "Export launchroom.json",
			"The environment's settings as a launchroom.json (build, start, health checks, scaling, domains, variable names; never secret values), to compare with or create the repository's file.",
			readOnly, s.getConfig), "project", api.APIAbilityEnvRead),
		on(mcp.NewTool("check_config", "Check launchroom.json",
			"Validates a launchroom.json's content against the environment, with each problem's line and hint; with diff also the staged changes it would make. Changes nothing: commit the file to apply it.",
			readOnly, s.checkConfig), "project", api.APIAbilityRead),
	}
}

// ---- list_env

// EnvVar is a variable without its value.
type EnvVar struct {
	Key        string               `json:"key"`
	Type       string               `json:"type" enum:"config,secret"`
	Connection bool                 `json:"connection" desc:"Provided by a connection to another service (read-only here)."`
	Editable   bool                 `json:"editable"`
	Targets    []api.VariableTarget `json:"targets"`
}

// EnvOut is the variable names of a service.
type EnvOut struct {
	Service   string   `json:"service"`
	Variables []EnvVar `json:"variables"`
}

func (s *server) listEnv(ctx context.Context, _ *mcp.Request, in Where) (EnvOut, string, error) {
	c, t, err := s.targetOf(ctx, in, true)
	if err != nil {
		return EnvOut{}, "", err
	}
	vars, err := c.client.Variables(ctx, t.Environment.ID, t.Service.ID)
	if err != nil {
		return EnvOut{}, "", explain(err)
	}
	out := EnvOut{Service: t.Service.ID, Variables: make([]EnvVar, 0, len(vars))}
	var b strings.Builder
	fmt.Fprintf(&b, "Variables of %s in %s (names only; values are never shown):\n", t.Service.Name, t.Environment.Name)
	for _, v := range vars {
		// Value is dropped here, config values included (docs/16 §7.4).
		out.Variables = append(out.Variables, EnvVar{Key: v.Key, Type: v.Type, Connection: v.Connection, Editable: v.Editable, Targets: nonNil(v.Targets)})
		note := v.Type
		if v.Connection {
			note += ", from a connection"
		}
		fmt.Fprintf(&b, "- %s (%s)\n", v.Key, note)
	}
	if len(vars) == 0 {
		b.WriteString("None.\n")
	}
	return out, b.String(), nil
}

// ---- set_variable

// SetVariableIn is one variable.
type SetVariableIn struct {
	Where
	Key    string `json:"key" pattern:"^[A-Za-z_][A-Za-z0-9_]{0,255}$" desc:"Variable name."`
	Value  string `json:"value" maxLength:"131072" desc:"Its value."`
	Secret bool   `json:"secret,omitempty" desc:"Store it as a secret (masked in logs, never shown again)."`
}

// SetVariableOut says what happened, never the value.
type SetVariableOut struct {
	Key    string `json:"key"`
	Type   string `json:"type" enum:"config,secret"`
	Action string `json:"action" enum:"created,updated"`
	Staged bool   `json:"staged" desc:"Live only after apply_staged_changes or the next deploy."`
}

func (s *server) setVariable(ctx context.Context, _ *mcp.Request, in SetVariableIn) (SetVariableOut, string, error) {
	c, t, err := s.targetOf(ctx, in.Where, true)
	if err != nil {
		return SetVariableOut{}, "", err
	}
	vars, err := c.client.Variables(ctx, t.Environment.ID, t.Service.ID)
	if err != nil {
		return SetVariableOut{}, "", explain(err)
	}
	typ := api.VariableTypeConfig
	if in.Secret {
		typ = api.VariableTypeSecret
	}
	out := SetVariableOut{Key: in.Key, Staged: true}
	for _, v := range vars {
		if v.Key != in.Key {
			continue
		}
		if v.Connection || !v.Editable {
			return SetVariableOut{}, "", mcp.Errorf("%s comes from a connection or another scope and can't be changed here; change it in the dashboard.", in.Key)
		}
		fields := map[string]any{"value": in.Value}
		if in.Secret && v.Type != api.VariableTypeSecret {
			fields["type"] = api.VariableTypeSecret
		}
		updated, err := c.client.UpdateVariable(ctx, t.Environment.ID, v.ID, fields)
		if err != nil {
			return SetVariableOut{}, "", explain(err)
		}
		out.Type, out.Action = updated.Type, "updated"
		return out, setText(out, t), nil
	}
	created, err := c.client.CreateVariable(ctx, t.Environment.ID, t.Service.ID, in.Key, in.Value, typ)
	if err != nil {
		return SetVariableOut{}, "", explain(err)
	}
	out.Type, out.Action = created.Type, "created"
	return out, setText(out, t), nil
}

func setText(out SetVariableOut, t *Target) string {
	return fmt.Sprintf("%s %s (%s) for %s in %s. It is staged: apply_staged_changes makes it live.\n", out.Action, out.Key, out.Type, t.Service.Name, t.Environment.Name)
}

// ---- get_config

// ConfigIn picks the environment.
type ConfigIn struct {
	Project     string `json:"project,omitempty" maxLength:"100" desc:"Project id (prj_…), slug or name. Default: the linked project."`
	Environment string `json:"environment,omitempty" maxLength:"100" desc:"Environment id (env_…), slug or name. Default: the linked environment, else production."`
}

// ConfigOut is the export.
type ConfigOut struct {
	Config api.ConfigExport `json:"config"`
}

func (s *server) getConfig(ctx context.Context, _ *mcp.Request, in ConfigIn) (ConfigOut, string, error) {
	c, t, err := s.targetOf(ctx, Where{Project: in.Project, Environment: in.Environment}, false)
	if err != nil {
		return ConfigOut{}, "", err
	}
	e, err := c.client.ExportConfig(ctx, t.Environment.ID, 0)
	if err != nil {
		return ConfigOut{}, "", explain(err)
	}
	return ConfigOut{Config: e}, capText(fmt.Sprintf("%s as %s (staged changes included):\n%s\n", t.Environment.Name, e.Path, e.Content)), nil
}

// ---- check_config

// CheckConfigIn is the file to check.
type CheckConfigIn struct {
	Project     string `json:"project,omitempty" maxLength:"100" desc:"Project id (prj_…), slug or name. Default: the linked project."`
	Environment string `json:"environment,omitempty" maxLength:"100" desc:"Environment id (env_…), slug or name. Default: the linked environment, else production."`
	Content     string `json:"content" maxLength:"262144" desc:"The launchroom.json text, as in the repository."`
	Diff        bool   `json:"diff,omitempty" desc:"Also list the staged changes it would make."`
}

// CheckConfigOut is the check's result.
type CheckConfigOut struct {
	Check api.ConfigCheck `json:"check"`
}

func (s *server) checkConfig(ctx context.Context, _ *mcp.Request, in CheckConfigIn) (CheckConfigOut, string, error) {
	c, t, err := s.targetOf(ctx, Where{Project: in.Project, Environment: in.Environment}, false)
	if err != nil {
		return CheckConfigOut{}, "", err
	}
	r, err := c.client.CheckConfig(ctx, t.Environment.ID, in.Content, in.Diff)
	if err != nil {
		return CheckConfigOut{}, "", explain(err)
	}
	r.Errors, r.Warnings, r.Ignored, r.Changes = nonNil(r.Errors), nonNil(r.Warnings), nonNil(r.Ignored), nonNil(r.Changes)
	var b strings.Builder
	if r.Valid {
		fmt.Fprintf(&b, "Valid for %s.\n", t.Environment.Name)
	} else {
		fmt.Fprintf(&b, "Not valid for %s:\n", t.Environment.Name)
	}
	problems := func(label string, list []api.ConfigProblem) {
		for _, p := range list {
			fmt.Fprintf(&b, "- %s", label)
			if p.Line != nil {
				fmt.Fprintf(&b, " line %d", *p.Line)
				if p.Column != nil {
					fmt.Fprintf(&b, ":%d", *p.Column)
				}
			}
			if p.Path != nil {
				fmt.Fprintf(&b, " (%s)", *p.Path)
			}
			fmt.Fprintf(&b, ": %s", p.Message)
			if p.Hint != nil {
				fmt.Fprintf(&b, " Hint: %s", *p.Hint)
			}
			b.WriteString("\n")
		}
	}
	problems("error", r.Errors)
	problems("warning", r.Warnings)
	problems("ignored", r.Ignored)
	if in.Diff {
		if len(r.Changes) == 0 {
			b.WriteString("It would change nothing.\n")
		}
		for _, ch := range r.Changes {
			fmt.Fprintf(&b, "- would %s %s (%s): %s\n", ch.Kind, ch.Path, ch.Effect, ch.Label)
		}
	}
	return CheckConfigOut{Check: r}, capText(b.String()), nil
}
