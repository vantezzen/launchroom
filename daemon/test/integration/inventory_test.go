//go:build integration

package integration

import (
	"context"
	"encoding/json"
	"strings"
	"testing"
	"time"

	"github.com/vantezzen/launchroom/daemon/internal/build"
	"github.com/vantezzen/launchroom/daemon/internal/build/buildkitd"
	"github.com/vantezzen/launchroom/daemon/internal/inventory"
	"github.com/vantezzen/launchroom/daemon/internal/registry"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// TestInventory (docs/22 §3): inventories of images through BuildKit with
// the pinned SBOM scanner (image mounted read-only, no network):
//   - a built app with its lockfile: an installed production package is
//     runtime, an installed dev dependency is dev, a production package
//     referenced from .next/server is bundled + runtime, one referenced only
//     from browser chunks is bundled + client;
//   - a Railpack-built Node image (Debian packages, the node runtime);
//   - mirrored images launchroom didn't build (a Go binary, Alpine packages):
//     language packages unknown, runtimes and OS packages runtime.
func TestInventory(t *testing.T) {
	be := setupBuild(t)
	ctx, cancel := context.WithTimeout(t.Context(), 30*time.Minute)
	defer cancel()
	opts := inventory.Options{
		BuildKit: be.bk.WaitClient, Tokens: be.tokens, RegistryHost: buildkitd.RegistryHost(), PublicRegistry: registry.DefaultAddress,
		WorkDir: t.TempDir(), InsecureGitHosts: []string{"127.0.0.1"},
	}
	platform := state.Platform("linux/" + hostArch())
	run := func(t *testing.T, spec *state.InventorySpec) *state.InventoryResult {
		t.Helper()
		if err := spec.Validate(); err != nil {
			t.Fatal(err)
		}
		task := state.TaskID(randomID("tsk"))
		be.lease(task)
		start := time.Now()
		res, err := inventory.Run(ctx, opts, task, spec, "", "")
		if err != nil {
			t.Fatalf("inventory: %v", err)
		}
		done := state.TaskCompletion{TypeMeta: state.NewTypeMeta(state.KindTaskCompletion), Attempt: 1, Outcome: state.OutcomeSucceeded, Inventory: res}
		if err := done.Validate(); err != nil {
			t.Fatalf("completion invalid: %v", err)
		}
		enc, _ := json.Marshal(done)
		t.Logf("%s: %d components (%d dropped) in %s, %d bytes encoded, distro %+v, notes %q", spec.Image, len(res.Components), res.Dropped, time.Since(start).Round(time.Second), len(enc), res.Distro, res.Notes)
		return res
	}
	find := func(res *state.InventoryResult, eco state.PackageEcosystem, name string) *state.InventoryComponent {
		for i, c := range res.Components {
			if c.Ecosystem == eco && string(c.Name) == name {
				return &res.Components[i]
			}
		}
		return nil
	}
	buildApp := func(t *testing.T, fixture string, kind state.BuilderKind) state.ImageRef {
		t.Helper()
		task := state.TaskID(randomID("tsk"))
		be.lease(task)
		sink := &captureSink{}
		out, err := be.builder.Run(ctx, build.Job{Task: task, Inputs: be.inputs(fixture, kind), Image: appImage, Sink: sink})
		if err != nil {
			t.Fatalf("build %s: %v\n%s", fixture, err, tail(sink.all(), 60))
		}
		return out.Image
	}

	t.Run("built app with lockfile", func(t *testing.T) {
		img := buildApp(t, "inventory-node", state.BuilderDockerfile)
		in := be.inputs("inventory-node", state.BuilderDockerfile)
		res := run(t, &state.InventorySpec{Image: img, Platform: platform, Service: itService, Source: &state.InventorySource{Repo: in.Repo}})
		if !res.SourceAnalyzed || len(res.Lockfiles) != 2 || res.Generator != build.SBOMGenerator {
			t.Errorf("result: analysed %v lockfiles %+v generator %s", res.SourceAnalyzed, res.Lockfiles, res.Generator)
		}
		for _, want := range []struct {
			name     string
			scope    state.DependencyScope
			presence state.ComponentPresence
			direct   bool
		}{
			{"ms", state.ScopeRuntime, state.PresenceInstalled, true},
			{"is-number", state.ScopeDev, state.PresenceInstalled, true},
			{"lodash", state.ScopeRuntime, state.PresenceBundled, true},
			{"zod", state.ScopeClient, state.PresenceBundled, true},
		} {
			c := find(res, state.EcosystemNpm, want.name)
			if c == nil || c.Scope != want.scope || c.Presence != want.presence || c.Direct != want.direct {
				t.Errorf("%s: %+v", want.name, c)
			}
		}
		if c := find(res, state.EcosystemBinary, "busybox"); c == nil || c.Kind != state.ComponentRuntime {
			t.Logf("busybox binary: %+v", c)
		}
	})

	t.Run("railpack node image", func(t *testing.T) {
		img := buildApp(t, "node-hello", state.BuilderAuto)
		in := be.inputs("node-hello", state.BuilderAuto)
		res := run(t, &state.InventorySpec{Image: img, Platform: platform, Service: itService, Source: &state.InventorySource{Repo: in.Repo}})
		node := find(res, state.EcosystemBinary, "node")
		if node == nil || node.Kind != state.ComponentRuntime || node.Scope != state.ScopeRuntime {
			t.Errorf("node runtime: %+v", node)
		}
		if res.Distro == nil || res.Distro.ID != "debian" {
			t.Errorf("distro = %+v", res.Distro)
		}
		debs := 0
		for _, c := range res.Components {
			if c.Ecosystem == state.EcosystemDeb {
				debs++
				if c.PURL == "" || !strings.Contains(string(c.PURL), "distro=debian-") {
					t.Errorf("deb purl %q", c.PURL)
				}
			}
		}
		if debs < 20 {
			t.Errorf("only %d Debian packages", debs)
		}
	})

	t.Run("mirrored images", func(t *testing.T) {
		_, wdigest, _ := strings.Cut(string(whoamiImage), "@")
		res := run(t, &state.InventorySpec{Image: state.ImageRef(registry.DefaultAddress + "/mirror/docker.io/traefik/whoami@" + wdigest), Platform: platform})
		if c := find(res, state.EcosystemGo, "stdlib"); c == nil || c.Kind != state.ComponentRuntime {
			t.Errorf("go stdlib: %+v", c)
		}
		goMods := 0
		for _, c := range res.Components {
			if c.Ecosystem == state.EcosystemGo && c.Name != "stdlib" {
				goMods++
				if c.Scope != state.ScopeRuntime || c.Direct {
					t.Errorf("go module %s: %+v", c.Name, c)
				}
			}
		}
		if goMods == 0 || res.SourceAnalyzed {
			t.Errorf("go modules %d, analysed %v", goMods, res.SourceAnalyzed)
		}

		_, pdigest, _ := strings.Cut(postgresImage, "@")
		res = run(t, &state.InventorySpec{Image: state.ImageRef(registry.DefaultAddress + "/mirror/docker.io/library/postgres@" + pdigest), Platform: platform})
		if res.Distro == nil || res.Distro.ID != "alpine" {
			t.Errorf("distro = %+v", res.Distro)
		}
		apk := 0
		for _, c := range res.Components {
			switch c.Ecosystem {
			case state.EcosystemApk:
				apk++
			case state.EcosystemNpm, state.EcosystemPyPI:
				if c.Scope != state.ScopeUnknown {
					t.Errorf("language package without source: %+v", c)
				}
			}
		}
		if apk < 10 {
			t.Errorf("only %d Alpine packages", apk)
		}
	})
}
