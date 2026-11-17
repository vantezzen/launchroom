package presets

import (
	"strings"
)

type staticSPA struct{}

func (staticSPA) Name() string { return "static-spa" }
func (staticSPA) Version() int { return 1 }

func (staticSPA) Match(_ Facts, i Info) bool { return i.Metadata["nodeIsSPA"] == "true" }

func (staticSPA) Propose(f Facts, i Info) Proposal {
	framework := i.Metadata["nodeRuntime"]
	if framework == "" || framework == "node" {
		framework = "static"
		for _, d := range []string{"vite", "react-scripts", "@angular/core", "astro", "next"} {
			if _, ok := f.PackageJSON.Dep(d); ok {
				framework = strings.TrimPrefix(strings.Split(d, "/")[0], "@")
				break
			}
		}
	}
	if framework == "react-scripts" {
		framework = "cra"
	}
	return Proposal{
		Framework: framework, Port: 8080, HealthPath: "/",
		Decisions: []Decision{{
			ID: "spa-fallback", Title: "Serve as a static site with SPA fallback",
			Detail: "Caddy serves the build output and falls back to index.html (set RAILPACK_NO_SPA=1 to disable, RAILPACK_SPA_OUTPUT_DIR to change the directory).",
		}},
	}
}
