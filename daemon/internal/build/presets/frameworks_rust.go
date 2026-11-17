package presets

import (
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// rocket covers Rust apps on Rocket, which reads its address and port from
// ROCKET_ADDRESS and ROCKET_PORT instead of PORT.
type rocket struct{}

func (rocket) Name() string { return "rocket" }
func (rocket) Version() int { return 1 }

func (rocket) Match(f Facts, i Info) bool {
	return f.Rust.Deps["rocket"] && (i.Provider == "rust" || i.Provider == "")
}

func (rocket) Propose(_ Facts, i Info) Proposal {
	p := Proposal{Framework: "rocket", HealthPath: "/"}
	if i.Dockerfile {
		p.Port = 8000
	}
	p.Decisions = append(p.Decisions, Decision{
		ID: "rocket-port", Title: "Listen on the service's port",
		Detail: "ROCKET_PORT follows the service's port and ROCKET_ADDRESS is 0.0.0.0, so Rocket answers where launchroom sends traffic.",
		Effect: Effect{
			RuntimeEnv: map[string]string{"ROCKET_ADDRESS": "0.0.0.0"},
			EnvAliases: []state.EnvAlias{{Name: "ROCKET_PORT", From: state.AliasPort}},
		},
	})
	return p
}
