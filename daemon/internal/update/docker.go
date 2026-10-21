package update

import (
	"bytes"
	"context"
	"errors"
	"fmt"
	"io"
	"path/filepath"
	"slices"
	"strings"
	"time"

	"github.com/containerd/errdefs"
	"github.com/moby/moby/api/types/container"
	"github.com/moby/moby/api/types/mount"
	"github.com/moby/moby/client"
)

// Labels of the containers this package creates (supervisor, helpers).
const (
	labelManaged = "launchroom.managed"
	labelRole    = "launchroom.role"
	labelTask    = "launchroom.update-task"
	roleUpdater  = "updater"
	// RoleControlPlane is the role label of the control-plane compose stack.
	RoleControlPlane = "control-plane"

	composeProject    = "com.docker.compose.project"
	composeWorkingDir = "com.docker.compose.project.working_dir"
	composeService    = "com.docker.compose.service"

	dockerSocket = "/var/run/docker.sock"
	maxLogTail   = 16 << 10
)

// Self is where the running launchroomd lives on the host.
type Self struct {
	ContainerID string
	// Image is the image ID (sha256:…) the container runs: the known-good
	// image the supervisor is started from.
	Image      string
	ProjectDir string
	Project    string
	Service    string
	Role       string
	// DataDir is the host path mounted at launchroomd's data directory.
	DataDir string
	// SocketPath is the host path of the Docker socket.
	SocketPath string
}

// Discover finds launchroomd's own container: the compose-managed
// container whose main process is pid (launchroomd runs with pid: host,
// so its PID is the host PID Docker reports).
func Discover(ctx context.Context, api *client.Client, pid int, dataDir string) (Self, error) {
	f := client.Filters{}
	f.Add("label", labelManaged+"=true")
	f.Add("label", composeService)
	list, err := api.ContainerList(ctx, client.ContainerListOptions{Filters: f})
	if err != nil {
		return Self{}, fmt.Errorf("list containers: %w", err)
	}
	for _, c := range list.Items {
		res, err := api.ContainerInspect(ctx, c.ID, client.ContainerInspectOptions{})
		if err != nil {
			continue
		}
		if res.Container.State == nil || res.Container.State.Pid != pid {
			continue
		}
		return withGuardSocket(ctx, api, res.Container, dataDir)
	}
	return Self{}, errors.New("launchroomd is not running in a compose-managed container on this host (self-update needs the installer's compose setup)")
}

// InspectSelf describes the launchroomd container id (tests; Discover finds it by PID).
func InspectSelf(ctx context.Context, api *client.Client, id, dataDir string) (Self, error) {
	res, err := api.ContainerInspect(ctx, id, client.ContainerInspectOptions{})
	if err != nil {
		return Self{}, err
	}
	return withGuardSocket(ctx, api, res.Container, dataDir)
}

// withGuardSocket is selfFromInspect for launchroomd behind dockerd-guard
// (D49): its own container has no Docker socket, the guard of the same
// compose project has it, and the supervisor gets that path.
func withGuardSocket(ctx context.Context, api *client.Client, r container.InspectResponse, dataDir string) (Self, error) {
	s, err := selfFromInspect(r, dataDir)
	if err == nil || !errors.Is(err, errNoSocket) || r.Config == nil {
		return s, err
	}
	f := client.Filters{}
	f.Add("label", labelManaged+"=true", composeProject+"="+r.Config.Labels[composeProject], composeService+"="+guardService)
	list, lerr := api.ContainerList(ctx, client.ContainerListOptions{Filters: f})
	if lerr != nil {
		return Self{}, fmt.Errorf("list containers: %w", lerr)
	}
	for _, c := range list.Items {
		g, ierr := api.ContainerInspect(ctx, c.ID, client.ContainerInspectOptions{})
		if ierr != nil {
			continue
		}
		for _, m := range g.Container.Mounts {
			if m.Destination == dockerSocket && filepath.IsAbs(m.Source) {
				r2 := r
				r2.Mounts = append(slices.Clone(r.Mounts), m)
				return selfFromInspect(r2, dataDir)
			}
		}
	}
	return Self{}, err
}

// errNoSocket: launchroomd's container has no Docker socket mount.
var errNoSocket = errors.New("own container has no Docker socket bind mount")

const guardService = "dockerd-guard"

func selfFromInspect(r container.InspectResponse, dataDir string) (Self, error) {
	if r.Config == nil {
		return Self{}, errors.New("own container has no config")
	}
	l := r.Config.Labels
	s := Self{ContainerID: r.ID, Image: r.Image, ProjectDir: l[composeWorkingDir], Project: l[composeProject], Service: l[composeService], Role: l[labelRole]}
	for _, m := range r.Mounts {
		switch m.Destination {
		case dataDir:
			s.DataDir = m.Source
		case dockerSocket:
			s.SocketPath = m.Source
		}
	}
	switch {
	case s.ProjectDir == "" || s.Project == "" || s.Service == "":
		return Self{}, errors.New("own container lacks the compose labels")
	case !filepath.IsAbs(s.ProjectDir) || filepath.Clean(s.ProjectDir) != s.ProjectDir || s.ProjectDir == "/":
		return Self{}, fmt.Errorf("compose working dir %q is not a clean absolute path", s.ProjectDir)
	case s.DataDir == "" || !strings.HasPrefix(s.DataDir, s.ProjectDir+"/"):
		return Self{}, fmt.Errorf("the data directory (%q) is not a bind mount inside the install folder %q", s.DataDir, s.ProjectDir)
	case s.SocketPath == "" || !filepath.IsAbs(s.SocketPath):
		return Self{}, errNoSocket
	case !strings.HasPrefix(s.Image, "sha256:"):
		return Self{}, fmt.Errorf("unexpected image id %q", s.Image)
	}
	return s, nil
}

// pull pulls a digest-pinned image (anonymously).
func pull(ctx context.Context, api *client.Client, ref string) error {
	if !strings.Contains(ref, "@sha256:") {
		return fmt.Errorf("refusing to pull %q: not digest-pinned", ref)
	}
	ctx, cancel := context.WithTimeout(ctx, 15*time.Minute)
	defer cancel()
	res, err := api.ImagePull(ctx, ref, client.ImagePullOptions{})
	if err != nil {
		return fmt.Errorf("pull %s: %w", ref, err)
	}
	defer func() { _ = res.Close() }()
	if err := res.Wait(ctx); err != nil {
		return fmt.Errorf("pull %s: %w", ref, err)
	}
	return nil
}

// helper describes a short-lived container (supervisor or installer helper).
type helper struct {
	Name       string
	Image      string
	Entrypoint []string
	Cmd        []string
	Env        []string
	WorkingDir string
	User       string
	GroupAdd   []string
	TaskID     string
	// Binds are host paths mounted at the same path inside, plus the Docker socket.
	SameBinds  []string
	SocketPath string
}

func (h helper) create(ctx context.Context, api *client.Client) (string, error) {
	removeByName(ctx, api, h.Name)
	mounts := []mount.Mount{{Type: mount.TypeBind, Source: h.SocketPath, Target: dockerSocket}}
	for _, p := range h.SameBinds {
		mounts = append(mounts, mount.Mount{Type: mount.TypeBind, Source: p, Target: p})
	}
	res, err := api.ContainerCreate(ctx, client.ContainerCreateOptions{
		Name: h.Name,
		Config: &container.Config{
			Image: h.Image, Entrypoint: h.Entrypoint, Cmd: h.Cmd, Env: h.Env, WorkingDir: h.WorkingDir, User: h.User,
			// A TTY makes the log stream raw (no multiplexing).
			Tty:    true,
			Labels: map[string]string{labelManaged: "true", labelRole: roleUpdater, labelTask: h.TaskID},
		},
		HostConfig: &container.HostConfig{
			Mounts: mounts, GroupAdd: h.GroupAdd,
			// Only the Docker socket is needed; no network at all.
			NetworkMode: "none",
			LogConfig:   container.LogConfig{Type: "json-file", Config: map[string]string{"max-size": "10m", "max-file": "3"}},
		},
	})
	if err != nil {
		return "", fmt.Errorf("create %s: %w", h.Name, err)
	}
	return res.ID, nil
}

// start creates and starts the container and returns its id.
func (h helper) start(ctx context.Context, api *client.Client) (string, error) {
	id, err := h.create(ctx, api)
	if err != nil {
		return "", err
	}
	if _, err := api.ContainerStart(ctx, id, client.ContainerStartOptions{}); err != nil {
		_, _ = api.ContainerRemove(context.WithoutCancel(ctx), id, client.ContainerRemoveOptions{Force: true})
		return "", fmt.Errorf("start %s: %w", h.Name, err)
	}
	return id, nil
}

// run starts the container, waits for it to exit (within timeout), and
// returns its exit code and the tail of its output; the container is removed.
func (h helper) run(ctx context.Context, api *client.Client, timeout time.Duration) (int, string, error) {
	id, err := h.start(ctx, api)
	if err != nil {
		return -1, "", err
	}
	defer func() {
		_, _ = api.ContainerRemove(context.WithoutCancel(ctx), id, client.ContainerRemoveOptions{Force: true})
	}()
	wctx, cancel := context.WithTimeout(ctx, timeout)
	defer cancel()
	code, werr := wait(wctx, api, id)
	logs := tail(ctx, api, id)
	if werr != nil {
		return -1, logs, fmt.Errorf("%s: %w", h.Name, werr)
	}
	return code, logs, nil
}

// wait blocks until the container is not running and returns its exit code.
func wait(ctx context.Context, api *client.Client, id string) (int, error) {
	w := api.ContainerWait(ctx, id, client.ContainerWaitOptions{Condition: container.WaitConditionNotRunning})
	select {
	case r := <-w.Result:
		if r.Error != nil && r.Error.Message != "" {
			return int(r.StatusCode), errors.New(r.Error.Message)
		}
		return int(r.StatusCode), nil
	case err := <-w.Error:
		return -1, err
	case <-ctx.Done():
		return -1, ctx.Err()
	}
}

func tail(ctx context.Context, api *client.Client, id string) string {
	lctx, cancel := context.WithTimeout(context.WithoutCancel(ctx), 30*time.Second)
	defer cancel()
	rc, err := api.ContainerLogs(lctx, id, client.ContainerLogsOptions{ShowStdout: true, ShowStderr: true, Tail: "200"})
	if err != nil {
		return ""
	}
	defer func() { _ = rc.Close() }()
	b, _ := io.ReadAll(io.LimitReader(rc, 4*maxLogTail))
	b = bytes.ReplaceAll(b, []byte("\r\n"), []byte("\n"))
	if len(b) > maxLogTail {
		b = b[len(b)-maxLogTail:]
	}
	return string(b)
}

func removeByName(ctx context.Context, api *client.Client, name string) {
	res, err := api.ContainerInspect(ctx, name, client.ContainerInspectOptions{})
	if err != nil {
		return
	}
	// Only our own helpers, never anything else that happens to have the name.
	if res.Container.Config == nil || res.Container.Config.Labels[labelRole] != roleUpdater {
		return
	}
	_, _ = api.ContainerRemove(ctx, res.Container.ID, client.ContainerRemoveOptions{Force: true})
}

// containerRunning reports whether a container by that name runs (false if gone).
func containerRunning(ctx context.Context, api *client.Client, name string) (bool, error) {
	res, err := api.ContainerInspect(ctx, name, client.ContainerInspectOptions{})
	if errdefs.IsNotFound(err) {
		return false, nil
	}
	if err != nil {
		return false, err
	}
	return res.Container.State != nil && res.Container.State.Running, nil
}
