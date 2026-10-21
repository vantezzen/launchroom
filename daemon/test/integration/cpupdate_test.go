//go:build integration

package integration

import (
	"context"
	"encoding/json"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"

	"github.com/moby/moby/api/types/container"
	"github.com/moby/moby/api/types/mount"
	"github.com/moby/moby/client"

	"github.com/vantezzen/launchroom/daemon/internal/state"
	"github.com/vantezzen/launchroom/daemon/internal/update"
)

// postgres:18-alpine, the control plane's pin (deploy/control-plane/images.env).
const cpPostgresImage = "docker.io/library/postgres@sha256:77f585114c32fbca283dc835b0596f4e52b51b4c6662d7810b2f4084f60a1873"

// A control-plane-shaped compose project: postgres with its data in
// ./data/postgres, stand-ins for the dashboard services and launchroomd
// (role control-plane, ./data/daemon:/data).
const cpCompose = `name: launchroom
x-labels: &labels
  launchroom.managed: "true"
  launchroom.role: control-plane
x-idle: &idle
  image: ${LR_DAEMON_IMAGE:?}
  labels: *labels
  environment: { LR_FAKE_IDLE: "1" }
services:
  postgres:
    image: ${LR_POSTGRES_IMAGE:?}
    labels: *labels
    environment: { POSTGRES_USER: launchroom, POSTGRES_PASSWORD: pw, POSTGRES_DB: launchroom }
    volumes: [ "./data/postgres:/var/lib/postgresql" ]
    healthcheck: { test: ["CMD", "pg_isready", "-U", "launchroom", "-d", "launchroom"], interval: 1s, retries: 60 }
  dashboard: *idle
  horizon: *idle
  reverb: *idle
  scheduler: *idle
  launchroomd:
    image: ${LR_DAEMON_IMAGE:?}
    labels: *labels
    restart: always
    volumes:
      - /var/run/docker.sock:/var/run/docker.sock
      - ./data/daemon:/data
`

// A stand-in for install.sh with the same contract (--bundle B --dir D):
// it takes the bundle's pins, "migrates" the database and brings the stack
// up, like the real installer re-run (docs/11 §2.1). It is test data, run
// by the supervisor in the docker CLI image.
const cpStubInstaller = `#!/bin/sh
set -eu
while [ $# -gt 0 ]; do case "$1" in --bundle) B="$2"; shift ;; --dir) D="$2"; shift ;; esac; shift; done
cd "$D"
cp "$B/control-plane/compose.yml" compose.yml
img="$(sed -n 's/^LR_DAEMON_IMAGE=//p' "$B/control-plane/images.env")"
{ grep -v '^LR_DAEMON_IMAGE=' .env; echo "LR_DAEMON_IMAGE=$img"; } > .env.tmp && mv .env.tmp .env
docker compose exec -T postgres psql -v ON_ERROR_STOP=1 -U launchroom -d launchroom -c "UPDATE marker SET v = 'migrated $(cat "$B/VERSION")'"
docker compose up -d --remove-orphans
`

func TestControlPlaneUpdateRollsBackDatabase(t *testing.T) {
	ctx, cancel := context.WithTimeout(context.Background(), 20*time.Minute)
	defer cancel()
	e := setupUpdate(t)
	e.project = filepath.Join(filepath.Dir(e.project), "launchroom")
	for _, d := range []string{"data/daemon", "data/postgres"} {
		if err := os.MkdirAll(filepath.Join(e.project, d), 0o700); err != nil {
			t.Fatal(err)
		}
	}
	// Like install.sh: the postgres image's user (70 in the alpine image) owns its data dir.
	if err := os.Chown(filepath.Join(e.project, "data", "postgres"), 70, 70); err != nil {
		t.Fatal(err)
	}
	trust, _ := json.Marshal(update.Trust{ReleaseBase: e.releaseURL, PublicKey: e.signer.PublicKeyPEM, AllowInsecureReleaseBase: true})
	e.writeFile(t, "data/daemon/trust.json", trust)
	mustPull(ctx, t, e.api, cpPostgresImage)

	for _, v := range []struct {
		version string
		broken  bool
	}{{"2.0.0", false}, {"2.1.0", true}, {"2.2.0", false}} {
		e.buildFake(ctx, t, v.version, v.broken)
		e.publishWith(t, v.version, e.signer, map[string][]byte{
			"control-plane/compose.yml": []byte(cpCompose),
			"control-plane/images.env":  []byte("LR_DAEMON_IMAGE=" + e.images[v.version] + "\nLR_COMPOSE_IMAGE=" + updComposeImage + "\nLR_POSTGRES_IMAGE=" + cpPostgresImage + "\n"),
			"install/install.sh":        []byte(cpStubInstaller),
		})
	}

	// "Installed" control plane at 2.0.0 with some data.
	e.writeFile(t, "compose.yml", []byte(cpCompose))
	e.writeFile(t, ".env", []byte("LR_DAEMON_IMAGE="+e.images["2.0.0"]+"\nLR_POSTGRES_IMAGE="+cpPostgresImage+"\n"))
	e.compose(ctx, t, "up", "-d", "--wait", "--wait-timeout", "120")
	e.compose(ctx, t, "exec", "-T", "postgres", "psql", "-v", "ON_ERROR_STOP=1", "-U", "launchroom", "-d", "launchroom",
		"-c", "CREATE TABLE marker (v text); INSERT INTO marker VALUES ('original')")
	e.waitRunningIn(t, "data/daemon", "2.0.0")
	self := e.cpSelf(ctx, t)
	if self.Role != update.RoleControlPlane {
		t.Fatalf("role %q", self.Role)
	}
	handler := func(running string) func(context.Context, state.Task) error {
		h := update.NewHandler(update.HandlerOptions{
			DataDir: filepath.Join(e.project, "data", "daemon"), Version: running, Docker: e.api,
			Self:                 func(context.Context) (update.Self, error) { return self, nil },
			SupervisorEntrypoint: []string{fakeEntrypoint, "-test.run=^TestFakeSupervisor$"},
			HealthTimeout:        30 * time.Second, InstallTimeout: 5 * time.Minute,
		})
		return func(ctx context.Context, tk state.Task) error { _, err := h(ctx, tk); return err }
	}
	cpTask := func(id, version string) state.Task {
		tk := updateTask(id, version, e.images[version])
		tk.Spec.(*state.SelfUpdateSpec).Scope = state.UpdateControlPlane
		return tk
	}

	// 2.0.0 → 2.1.0: the installer migrates the database, the new launchroomd
	// never becomes healthy → files AND database are restored.
	err := handler("2.0.0")(ctx, cpTask("tsk_01J9Z8X7W6V5T4S3R2Q1P0BBB1", "2.1.0"))
	if err == nil || !strings.Contains(err.Error(), "rolled back to 2.0.0") {
		t.Fatalf("broken control-plane update: %v", err)
	}
	if got := e.marker(ctx, t); got != "original" {
		t.Fatalf("database not restored: marker = %q", got)
	}
	if !strings.Contains(e.readFile(t, ".env"), "LR_DAEMON_IMAGE="+e.images["2.0.0"]) {
		t.Fatal(".env not restored")
	}
	if got := e.cpSelf(ctx, t).Image; got != e.imageIDs["2.0.0"] {
		t.Fatalf("launchroomd runs %s after the rollback, want 2.0.0", got)
	}

	// 2.0.0 → 2.2.0: migrated and healthy; the dump is cleaned up.
	if err := handler("2.0.0")(ctx, cpTask("tsk_01J9Z8X7W6V5T4S3R2Q1P0BBB2", "2.2.0")); err != nil {
		t.Fatalf("control-plane update: %v", err)
	}
	if got := e.marker(ctx, t); got != "migrated 2.2.0" {
		t.Fatalf("marker = %q", got)
	}
	if _, err := os.Stat(filepath.Join(e.project, "data", "postgres", "launchroom-pre-upgrade.dump")); !os.IsNotExist(err) {
		t.Fatalf("pre-upgrade dump left behind: %v", err)
	}
	e.assertNoUpdaters(ctx, t)
	e.compose(context.WithoutCancel(ctx), t, "down", "--remove-orphans")
}

// compose runs docker compose for the project in the docker CLI image.
func (e *updEnv) compose(ctx context.Context, t *testing.T, args ...string) {
	t.Helper()
	cr, err := e.api.ContainerCreate(ctx, client.ContainerCreateOptions{
		Config: &container.Config{Image: updComposeImage, Cmd: append([]string{"docker", "compose"}, args...), WorkingDir: e.project,
			Labels: map[string]string{"launchroom.managed": "true"}},
		HostConfig: &container.HostConfig{NetworkMode: "none", Mounts: []mount.Mount{
			{Type: mount.TypeBind, Source: "/var/run/docker.sock", Target: "/var/run/docker.sock"},
			{Type: mount.TypeBind, Source: e.project, Target: e.project},
		}},
	})
	if err != nil {
		t.Fatal(err)
	}
	defer func() {
		_, _ = e.api.ContainerRemove(context.WithoutCancel(ctx), cr.ID, client.ContainerRemoveOptions{Force: true})
	}()
	if _, err := e.api.ContainerStart(ctx, cr.ID, client.ContainerStartOptions{}); err != nil {
		t.Fatal(err)
	}
	w := e.api.ContainerWait(ctx, cr.ID, client.ContainerWaitOptions{Condition: container.WaitConditionNotRunning})
	select {
	case r := <-w.Result:
		if r.StatusCode != 0 {
			t.Fatalf("docker compose %v exited %d: %s", args, r.StatusCode, updContainerLogs(ctx, e.api, cr.ID))
		}
	case err := <-w.Error:
		t.Fatal(err)
	}
}

func (e *updEnv) marker(ctx context.Context, t *testing.T) string {
	t.Helper()
	f := client.Filters{}
	f.Add("label", "com.docker.compose.project=launchroom")
	f.Add("label", "com.docker.compose.service=postgres")
	list, err := e.api.ContainerList(ctx, client.ContainerListOptions{Filters: f})
	if err != nil || len(list.Items) != 1 {
		t.Fatalf("postgres container: %d %v", len(list.Items), err)
	}
	res, err := e.api.ExecCreate(ctx, list.Items[0].ID, client.ExecCreateOptions{Cmd: []string{"psql", "-tA", "-U", "launchroom", "-d", "launchroom", "-c", "SELECT v FROM marker"}, AttachStdout: true, AttachStderr: true})
	if err != nil {
		t.Fatal(err)
	}
	att, err := e.api.ExecAttach(ctx, res.ID, client.ExecAttachOptions{})
	if err != nil {
		t.Fatal(err)
	}
	defer att.Close()
	var out strings.Builder
	buf := make([]byte, 4096)
	for {
		n, rerr := att.Reader.Read(buf)
		out.Write(buf[:n])
		if rerr != nil {
			break
		}
	}
	// Strip the 8-byte stream headers of the multiplexed output.
	s := out.String()
	var b strings.Builder
	for len(s) >= 8 {
		size := int(s[4])<<24 | int(s[5])<<16 | int(s[6])<<8 | int(s[7])
		if 8+size > len(s) {
			break
		}
		b.WriteString(s[8 : 8+size])
		s = s[8+size:]
	}
	return strings.TrimSpace(b.String())
}

func (e *updEnv) cpSelf(ctx context.Context, t *testing.T) update.Self {
	t.Helper()
	f := client.Filters{}
	f.Add("label", "com.docker.compose.project=launchroom")
	f.Add("label", "com.docker.compose.service=launchroomd")
	list, err := e.api.ContainerList(ctx, client.ContainerListOptions{Filters: f})
	if err != nil || len(list.Items) != 1 {
		t.Fatalf("launchroomd container: %d %v", len(list.Items), err)
	}
	s, err := update.InspectSelf(ctx, e.api, list.Items[0].ID, "/data")
	if err != nil {
		t.Fatal(err)
	}
	return s
}

func (e *updEnv) waitRunningIn(t *testing.T, dataRel, version string) {
	t.Helper()
	deadline := time.Now().Add(time.Minute)
	for time.Now().Before(deadline) {
		var r update.Running
		if b, err := os.ReadFile(filepath.Join(e.project, dataRel, "update", "running.json")); err == nil && json.Unmarshal(b, &r) == nil && r.Version == version {
			return
		}
		time.Sleep(500 * time.Millisecond)
	}
	t.Fatalf("launchroomd %s did not start", version)
}
