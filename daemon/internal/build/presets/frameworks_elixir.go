package presets

import (
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

type phoenix struct{}

func (phoenix) Name() string { return "phoenix" }
func (phoenix) Version() int { return 1 }

func (phoenix) Match(f Facts, _ Info) bool { return f.Elixir.Phoenix }

func (phoenix) Propose(f Facts, i Info) Proposal {
	e := f.Elixir
	p := Proposal{Framework: "phoenix", HealthPath: "/"}
	if i.Dockerfile {
		p.Port = 4000
	}
	p.Decisions = append(p.Decisions,
		Decision{
			ID: "phx-server", Title: "Start the web server",
			Detail: "PHX_SERVER=true, so the release serves HTTP when it starts.",
			Effect: Effect{RuntimeEnv: map[string]string{"PHX_SERVER": "true"}},
		},
		Decision{
			ID: "phx-host", Title: "Set PHX_HOST to the service's domain",
			Detail: "URLs and LiveView's origin check use this environment's domain. Your own PHX_HOST replaces it.",
			Effect: Effect{EnvAliases: []state.EnvAlias{{Name: "PHX_HOST", From: state.AliasDomain}}},
		},
		Decision{
			ID: "secret-key-base", Title: "Generate SECRET_KEY_BASE",
			Detail: "Phoenix signs and encrypts cookies with SECRET_KEY_BASE. When the service has none, launchroom creates one as a secret variable per environment, kept across deploys.",
			Effect: Effect{GeneratedSecrets: []state.GeneratedSecret{{Name: "SECRET_KEY_BASE", Length: 64, Encoding: state.SecretAlnum}}},
		},
	)
	switch {
	case !e.Ecto || i.Dockerfile:
	case e.ReleaseModule != "" && e.App != "":
		bin := "/app/_build/prod/rel/" + e.App + "/bin/" + e.App
		p.Decisions = append(p.Decisions, Decision{
			ID: "release-migrations", Title: "Run migrations once per deploy",
			Detail: e.ReleaseModule + ".migrate runs as the release command before the new version starts.",
			Effect: Effect{ReleaseCommand: []string{bin, "eval", e.ReleaseModule + ".migrate"}},
		})
	default:
		p.Warnings = append(p.Warnings, Warning{Code: "phoenix-no-release-module",
			Message: "Migrations don't run on deploy, because the app has no release module. Run mix phx.gen.release and commit lib/<app>/release.ex, and launchroom runs its migrate function on every deploy."})
	}
	return p
}
