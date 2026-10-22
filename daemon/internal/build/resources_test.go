package build

import (
	"testing"

	rpplan "github.com/railwayapp/railpack/core/plan"

	"github.com/vantezzen/launchroom/daemon/internal/build/buildkitd"
	"github.com/vantezzen/launchroom/daemon/internal/secret"
)

func TestSmallNodeBuildLimits(t *testing.T) {
	resources := buildkitd.PlanResources(buildkitd.HostFacts{CPUs: 1, MemoryMB: 2048, SwapMB: 2048, ControlPlane: true}, buildkitd.Overrides{})
	p := &rpplan.BuildPlan{Steps: []rpplan.Step{{Name: "install"}, {Name: "build"}}}
	limitNodeBuild(p, resources, nil)
	if p.Steps[1].Variables["NODE_OPTIONS"] != "--max-old-space-size=1152" || p.Steps[1].Variables["RAYON_NUM_THREADS"] != "1" {
		t.Fatalf("build variables: %v", p.Steps[1].Variables)
	}
	if len(p.Steps[0].Variables) != 0 || len(p.Deploy.Variables) != 0 {
		t.Fatal("build limits leaked into install or runtime")
	}
	p.Steps[1].Variables = map[string]string{"RAYON_NUM_THREADS": "2"}
	limitNodeBuild(p, resources, map[string]secret.Secret{"NODE_OPTIONS": secret.Secret("--max-old-space-size=768")})
	if _, exists := p.Steps[1].Variables["NODE_OPTIONS"]; exists || p.Steps[1].Variables["RAYON_NUM_THREADS"] != "2" {
		t.Fatal("explicit build settings were overridden")
	}
}
