package docker

import (
	"testing"

	"github.com/vantezzen/launchroom/daemon/internal/state"
)

func TestEngineKindOf(t *testing.T) {
	cases := []struct {
		os, name string
		want     state.EngineKind
	}{
		{"Docker Desktop", "docker-desktop", state.EngineDockerDesktop},
		{"OrbStack", "orbstack", state.EngineOrbStack},
		{"Ubuntu 24.04.1 LTS", "colima", state.EngineColima},
		{"Ubuntu 24.04.1 LTS", "colima-work", state.EngineColima},
		{"Ubuntu 24.04.1 LTS", "vps-1", state.EngineDocker},
		{"Ubuntu 24.04.1 LTS", "colimax", state.EngineDocker},
		// A dind container on OrbStack: OrbStack's kernel, but its own OS.
		{"Alpine Linux v3.24 (containerized)", "8f0c2d1e", state.EngineDocker},
		{"", "", state.EngineDocker},
	}
	for _, c := range cases {
		if got := EngineKindOf(c.os, c.name); got != c.want {
			t.Errorf("EngineKindOf(%q, %q) = %q, want %q", c.os, c.name, got, c.want)
		}
	}
}
