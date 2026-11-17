//go:build integration

package integration

import (
	"context"
	"errors"
	"slices"
	"strings"
	"testing"
	"time"

	"github.com/moby/moby/api/types/container"
	"github.com/moby/moby/client"

	"github.com/vantezzen/launchroom/daemon/internal/build"
	"github.com/vantezzen/launchroom/daemon/internal/build/buildkitd"
	"github.com/vantezzen/launchroom/daemon/internal/registry"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// runImage starts the built image (pulled with a node token) with cmd
// (nil: the image's own command) and returns the container id; it is
// removed when the test ends.
func runImage(ctx context.Context, t *testing.T, be *buildEnv, image state.ImageRef, cmd []string, env []string) string {
	t.Helper()
	be.issuer.mu.Lock()
	be.issuer.nodeApps[state.AppRepository(itService)] = true
	be.issuer.mu.Unlock()
	be.tokens.Forget("")
	if err := pullFresh(ctx, be, string(image)); err != nil {
		t.Fatal(err)
	}
	cfg := &container.Config{Image: string(image), Env: env, Labels: map[string]string{"launchroom.dev": "true"}}
	if cmd != nil {
		cfg.Entrypoint, cfg.Cmd = cmd[:1], cmd[1:]
	}
	cr, err := be.api.ContainerCreate(ctx, client.ContainerCreateOptions{Config: cfg, HostConfig: &container.HostConfig{}})
	if err != nil {
		t.Fatal(err)
	}
	cleanupCtx := context.WithoutCancel(ctx)
	t.Cleanup(func() {
		_, _ = be.api.ContainerRemove(cleanupCtx, cr.ID, client.ContainerRemoveOptions{Force: true})
	})
	if _, err := be.api.ContainerStart(ctx, cr.ID, client.ContainerStartOptions{}); err != nil {
		t.Fatal(err)
	}
	return cr.ID
}

// A Turborepo app (apps/web, Next.js, importing @repo/ui) builds from the
// workspace root, filtered to the app, and serves from its folder.
func TestBuildTurboWorkspaceApp(t *testing.T) {
	be := setupBuild(t)
	ctx, cancel := context.WithTimeout(t.Context(), 40*time.Minute)
	defer cancel()
	inputs := be.inputs("turbo-pnpm", state.BuilderAuto)
	inputs.RootDir = "apps/web"
	task := state.TaskID("tsk_01J9Z8X7W6V5T4S3R2Q1P0WS01")
	be.lease(task)
	sink := &captureSink{}
	out, err := be.builder.Run(ctx, build.Job{Task: task, Inputs: inputs, Image: appImage, Sink: sink})
	logs := sink.all()
	if err != nil {
		t.Fatalf("build: %v\n%s", err, tail(logs, 150))
	}
	w := out.Summary.Workspace
	t.Logf("built %s: workspace %+v, build %q, start %q", out.Image, w, out.Summary.BuildCommand, out.Summary.StartCommand)
	if w == nil || w.PackageName != "web" || w.Manager != "pnpm" || w.Tool != "turbo" || !slices.Contains(w.DependsOn, "packages/ui") {
		t.Fatalf("workspace %+v", w)
	}
	if !strings.Contains(out.Summary.BuildCommand, "turbo run build --filter=web...") || strings.Contains(logs, "docs:build") {
		t.Fatalf("the build was not filtered to the app: %q\n%s", out.Summary.BuildCommand, tail(logs, 80))
	}

	id := runImage(ctx, t, be, out.Image, nil, []string{"PORT=3000", "HOSTNAME=0.0.0.0", "NODE_ENV=production"})
	insp, err := be.api.ContainerInspect(ctx, id, client.ContainerInspectOptions{})
	if err != nil {
		t.Fatal(err)
	}
	var ip string
	for _, n := range insp.Container.NetworkSettings.Networks {
		ip = n.IPAddress.String()
	}
	deadline := time.Now().Add(90 * time.Second)
	for {
		code, _, body, err := get(t, noRedirect, "http://"+ip+":3000/", "web.localhost")
		if err == nil && code == 200 {
			if !strings.Contains(body, "<html") {
				t.Fatalf("/: %q", tail(body, 5))
			}
			break
		}
		if time.Now().After(deadline) {
			t.Fatalf("the workspace app never answered (%d, %v):\n%s", code, err, tail(containerLogs(t, be.api, id), 40))
		}
		time.Sleep(time.Second)
	}
}

// A Laravel 12 app with Filament and Horizon builds without a single
// extension named by the user: composer.lock asks for intl, zip and pcntl.
func TestBuildLaravelFilamentDependencyExtensions(t *testing.T) {
	be := setupBuild(t)
	ctx, cancel := context.WithTimeout(t.Context(), 40*time.Minute)
	defer cancel()
	task := state.TaskID("tsk_01J9Z8X7W6V5T4S3R2Q1P0WS02")
	be.lease(task)
	sink := &captureSink{}
	out, err := be.builder.Run(ctx, build.Job{Task: task, Inputs: be.inputs("laravel12-filament", state.BuilderAuto), Image: appImage, Sink: sink})
	if err != nil {
		t.Fatalf("build: %v\n%s", err, tail(sink.all(), 150))
	}
	var names []string
	for _, x := range out.Summary.PhpDependencyExtensions {
		names = append(names, string(x.Name))
	}
	t.Logf("dependency extensions %q", names)
	for _, want := range []string{"intl", "zip", "pcntl"} {
		if !slices.Contains(names, want) {
			t.Fatalf("summary extensions %q lack %s", names, want)
		}
	}
	id := runImage(ctx, t, be, out.Image, []string{"php", "-m"}, nil)
	w := be.api.ContainerWait(ctx, id, client.ContainerWaitOptions{Condition: container.WaitConditionNotRunning})
	select {
	case <-w.Result:
	case err := <-w.Error:
		t.Fatal(err)
	}
	// Each log line carries the stream header; a module line ends in its name.
	lines := strings.Split(strings.ToLower(containerLogs(t, be.api, id)), "\n")
	for _, want := range []string{"intl", "zip", "pcntl"} {
		if !slices.ContainsFunc(lines, func(l string) bool { return strings.HasSuffix(strings.TrimSpace(l), want) }) {
			t.Fatalf("php -m lacks %s:\n%q", want, lines)
		}
	}
}

// Host-caused build failures say what happened: a full disk runs the
// cleanup at once, a name that doesn't resolve says so, and a build that
// prints nothing is stopped.
func TestBuildHostFailureHints(t *testing.T) {
	be := setupBuild(t)
	ctx, cancel := context.WithTimeout(t.Context(), 15*time.Minute)
	defer cancel()
	freed := 0
	b := build.New(build.Options{
		BuildKit: be.bk.WaitClient, Tokens: be.tokens, RegistryHost: buildkitd.RegistryHost(), PublicRegistry: registry.DefaultAddress,
		WorkDir: t.TempDir(), InsecureGitHosts: []string{"127.0.0.1"}, IdleTimeout: 20 * time.Second,
		FreeDisk: func(context.Context) state.CleanupResult { freed++; return state.CleanupResult{ReclaimedMB: 1234} },
	})
	for _, c := range []struct {
		task             state.TaskID
		name, dockerfile string
		hint             state.HintCode
		class            state.FailureClass
		says             string
	}{
		// BuildKit gives build steps a 64 MB /dev/shm.
		{"tsk_01J9Z8X7W6V5T4S3R2Q1P0WS10", "disk full", "FROM busybox:1.37\nRUN dd if=/dev/zero of=/dev/shm/fill bs=1M count=200\n", state.HintDiskFull, state.FailureTransient, "freed 1.2 GB"},
		{"tsk_01J9Z8X7W6V5T4S3R2Q1P0WS11", "dns", "FROM busybox:1.37\nRUN wget -q -O- http://registry.launchroom-dns-test.invalid/\n", state.HintBuildDNS, "", "can't resolve registry.launchroom-dns-test.invalid"},
		{"tsk_01J9Z8X7W6V5T4S3R2Q1P0WS12", "idle", "FROM busybox:1.37\nRUN echo starting && sleep 120\n", state.HintBuildIdle, state.FailureTimeout, "No build output for 20 seconds"},
	} {
		t.Run(c.name, func(t *testing.T) {
			task := c.task
			be.lease(task)
			in := be.inputs("node-hello", state.BuilderDockerfile)
			in.Builder.Dockerfile = c.dockerfile
			sink := &captureSink{}
			_, err := b.Run(ctx, build.Job{Task: task, Inputs: in, Image: appImage, Sink: sink})
			var berr *build.Error
			if !errors.As(err, &berr) {
				t.Fatalf("err %v\n%s", err, tail(sink.all(), 40))
			}
			f := berr.Failure()
			i := slices.IndexFunc(f.Hints, func(h state.FailureHint) bool { return h.Code == c.hint })
			if i < 0 || !strings.Contains(f.Hints[i].Message, c.says) || c.class != "" && f.Class != c.class {
				t.Fatalf("failure %+v\n%s", f, tail(sink.all(), 40))
			}
			t.Logf("%s: %s", f.Class, f.Hints[i].Message)
		})
	}
	if freed != 1 {
		t.Fatalf("cleanup ran %d times, want once (disk full only)", freed)
	}
}
