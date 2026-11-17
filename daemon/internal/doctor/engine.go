package doctor

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"strconv"
	"strings"
	"time"

	"github.com/moby/moby/api/pkg/stdcopy"
	"github.com/moby/moby/api/types/container"
	"github.com/moby/moby/client"

	"github.com/vantezzen/launchroom/daemon/internal/names"
	"github.com/vantezzen/launchroom/daemon/internal/update"
)

// Compose labels of the containers the installers' compose projects create.
const (
	labelProject = "com.docker.compose.project"
	labelService = "com.docker.compose.service"
)

// Container is what the checks need to know about one container.
type Container struct {
	ID      string
	Name    string
	Project string
	Service string
	Role    string
	// Image is the reference the container was created from (Config.Image).
	Image        string
	Status       string // created, running, restarting, exited, …
	Running      bool
	Restarting   bool
	ExitCode     int
	OOMKilled    bool
	Error        string
	RestartCount int
	StartedAt    time.Time
	FinishedAt   time.Time
	// Health is "", "starting", "healthy" or "unhealthy"; HealthLog the last
	// probe output.
	Health      string
	HealthLog   string
	OomScoreAdj int
	TTY         bool
}

// Healthy reports a running container that is healthy when it has a healthcheck.
func (c Container) Healthy() bool {
	return c.Running && !c.Restarting && (c.Health == "" || c.Health == "healthy")
}

// EngineInfo are the Docker facts of the host.
type EngineInfo struct {
	ServerVersion string
	CgroupVersion string
	DockerRootDir string
	Rootless      bool
}

// Engine is the part of the Docker Engine API the doctor uses. The checks
// only read; the fixes start, restart and stop control-plane containers
// and run one-off helpers.
type Engine interface {
	Info(ctx context.Context) (EngineInfo, error)
	// Containers lists containers (also stopped ones) with all of the labels
	// (key=value).
	Containers(ctx context.Context, labels ...string) ([]Container, error)
	Logs(ctx context.Context, c Container, lines int) (string, error)
	// Exec runs argv in a running container and returns its exit code and
	// combined output.
	Exec(ctx context.Context, c Container, argv []string) (int, string, error)
	Start(ctx context.Context, c Container) error
	Restart(ctx context.Context, c Container) error
	Stop(ctx context.Context, c Container) error
	Remove(ctx context.Context, c Container) error
	// InspectJSON is the container's inspect document without its
	// environment (support bundle).
	InspectJSON(ctx context.Context, c Container) ([]byte, error)
	// RunOnce runs a one-off container (image, argv, binds host:container)
	// with input on stdin and returns its exit code and output.
	RunOnce(ctx context.Context, r OneOff) (int, string, error)
}

// OneOff is a short-lived helper container a fix runs.
type OneOff struct {
	Name  string
	Image string
	Cmd   []string
	// Binds are host:container[:ro] bind mounts.
	Binds []string
	User  string
	Stdin string
}

// Moby is the Engine on a real Docker client.
type Moby struct {
	API *client.Client
}

var _ Engine = (*Moby)(nil)

// Info implements Engine.
func (m *Moby) Info(ctx context.Context) (EngineInfo, error) {
	res, err := m.API.Info(ctx, client.InfoOptions{})
	if err != nil {
		return EngineInfo{}, err
	}
	i := res.Info
	out := EngineInfo{ServerVersion: i.ServerVersion, CgroupVersion: i.CgroupVersion, DockerRootDir: i.DockerRootDir}
	for _, o := range i.SecurityOptions {
		if strings.Contains(o, "name=rootless") {
			out.Rootless = true
		}
	}
	return out, nil
}

// Containers implements Engine.
func (m *Moby) Containers(ctx context.Context, labels ...string) ([]Container, error) {
	f := client.Filters{}
	for _, l := range labels {
		f.Add("label", l)
	}
	list, err := m.API.ContainerList(ctx, client.ContainerListOptions{All: true, Filters: f})
	if err != nil {
		return nil, err
	}
	out := make([]Container, 0, len(list.Items))
	for _, s := range list.Items {
		res, err := m.API.ContainerInspect(ctx, s.ID, client.ContainerInspectOptions{})
		if err != nil {
			continue
		}
		out = append(out, fromInspect(res.Container))
	}
	return out, nil
}

func fromInspect(r container.InspectResponse) Container {
	c := Container{ID: r.ID, Name: strings.TrimPrefix(r.Name, "/"), RestartCount: r.RestartCount}
	if r.Config != nil {
		c.Image, c.TTY = r.Config.Image, r.Config.Tty
		c.Project, c.Service, c.Role = r.Config.Labels[labelProject], r.Config.Labels[labelService], r.Config.Labels[names.LabelRole]
	}
	if r.HostConfig != nil {
		c.OomScoreAdj = r.HostConfig.OomScoreAdj
	}
	if s := r.State; s != nil {
		c.Status, c.Running, c.Restarting, c.ExitCode, c.OOMKilled, c.Error = string(s.Status), s.Running, s.Restarting, s.ExitCode, s.OOMKilled, s.Error
		c.StartedAt, _ = time.Parse(time.RFC3339Nano, s.StartedAt)
		c.FinishedAt, _ = time.Parse(time.RFC3339Nano, s.FinishedAt)
		if h := s.Health; h != nil {
			c.Health = string(h.Status)
			if n := len(h.Log); n > 0 && h.Log[n-1] != nil {
				c.HealthLog = strings.TrimSpace(h.Log[n-1].Output)
			}
		}
	}
	return c
}

// Logs implements Engine.
func (m *Moby) Logs(ctx context.Context, c Container, lines int) (string, error) {
	return update.ContainerLogTail(ctx, m.API, c.ID, c.TTY, lines), nil
}

// Exec implements Engine.
func (m *Moby) Exec(ctx context.Context, c Container, argv []string) (int, string, error) {
	ex, err := m.API.ExecCreate(ctx, c.ID, client.ExecCreateOptions{AttachStdout: true, AttachStderr: true, Cmd: argv})
	if err != nil {
		return -1, "", err
	}
	hr, err := m.API.ExecAttach(ctx, ex.ID, client.ExecAttachOptions{})
	if err != nil {
		return -1, "", err
	}
	defer hr.Close()
	var buf bytes.Buffer
	done := make(chan error, 1)
	go func() {
		_, err := stdcopy.StdCopy(&buf, &buf, io.LimitReader(hr.Reader, 4<<20))
		done <- err
	}()
	select {
	case <-ctx.Done():
		return -1, buf.String(), ctx.Err()
	case copyErr := <-done:
		_ = copyErr // a cut-off copy still leaves the exit code below
	}
	res, err := m.API.ExecInspect(ctx, ex.ID, client.ExecInspectOptions{})
	if err != nil {
		return -1, buf.String(), err
	}
	return res.ExitCode, buf.String(), nil
}

// Start implements Engine.
func (m *Moby) Start(ctx context.Context, c Container) error {
	_, err := m.API.ContainerStart(ctx, c.ID, client.ContainerStartOptions{})
	return err
}

// Restart implements Engine.
func (m *Moby) Restart(ctx context.Context, c Container) error {
	_, err := m.API.ContainerRestart(ctx, c.ID, client.ContainerRestartOptions{})
	return err
}

// Stop implements Engine.
func (m *Moby) Stop(ctx context.Context, c Container) error {
	_, err := m.API.ContainerStop(ctx, c.ID, client.ContainerStopOptions{})
	return err
}

// Remove implements Engine.
func (m *Moby) Remove(ctx context.Context, c Container) error {
	_, err := m.API.ContainerRemove(ctx, c.ID, client.ContainerRemoveOptions{})
	return err
}

// InspectJSON implements Engine.
func (m *Moby) InspectJSON(ctx context.Context, c Container) ([]byte, error) {
	res, err := m.API.ContainerInspect(ctx, c.ID, client.ContainerInspectOptions{})
	if err != nil {
		return nil, err
	}
	r := res.Container
	if r.Config != nil {
		cfg := *r.Config
		cfg.Env = nil
		r.Config = &cfg
	}
	return json.MarshalIndent(r, "", "  ")
}

// RunOnce implements Engine.
func (m *Moby) RunOnce(ctx context.Context, r OneOff) (int, string, error) {
	if !strings.HasPrefix(r.Name, names.SystemPrefix) {
		return -1, "", fmt.Errorf("helper name %q is not a launchroom system name", r.Name)
	}
	if old, err := m.API.ContainerInspect(ctx, r.Name, client.ContainerInspectOptions{}); err == nil &&
		old.Container.Config != nil && old.Container.Config.Labels[names.LabelRole] == roleDoctor {
		_, _ = m.API.ContainerRemove(ctx, old.Container.ID, client.ContainerRemoveOptions{Force: true})
	}
	res, err := m.API.ContainerCreate(ctx, client.ContainerCreateOptions{
		Name: r.Name,
		Config: &container.Config{
			Image: r.Image, Entrypoint: []string{}, Cmd: r.Cmd, User: r.User,
			OpenStdin: true, StdinOnce: true, AttachStdin: true, AttachStdout: true, AttachStderr: true,
			Labels: map[string]string{names.LabelManaged: "true", names.LabelRole: roleDoctor},
		},
		HostConfig: &container.HostConfig{Binds: r.Binds, NetworkMode: "none"},
	})
	if err != nil {
		return -1, "", err
	}
	defer func() {
		_, _ = m.API.ContainerRemove(context.WithoutCancel(ctx), res.ID, client.ContainerRemoveOptions{Force: true})
	}()
	at, err := m.API.ContainerAttach(ctx, res.ID, client.ContainerAttachOptions{Stream: true, Stdin: true, Stdout: true, Stderr: true})
	if err != nil {
		return -1, "", err
	}
	defer at.Close()
	var buf bytes.Buffer
	copied := make(chan struct{})
	go func() {
		_, _ = stdcopy.StdCopy(&buf, &buf, io.LimitReader(at.Reader, 4<<20))
		close(copied)
	}()
	if _, err := m.API.ContainerStart(ctx, res.ID, client.ContainerStartOptions{}); err != nil {
		return -1, "", err
	}
	if r.Stdin != "" {
		_, _ = io.WriteString(at.Conn, r.Stdin)
	}
	_ = at.CloseWrite()
	w := m.API.ContainerWait(ctx, res.ID, client.ContainerWaitOptions{Condition: container.WaitConditionNotRunning})
	select {
	case wr := <-w.Result:
		<-copied
		if wr.Error != nil && wr.Error.Message != "" {
			return int(wr.StatusCode), buf.String(), errors.New(wr.Error.Message)
		}
		return int(wr.StatusCode), buf.String(), nil
	case err := <-w.Error:
		return -1, buf.String(), err
	case <-ctx.Done():
		return -1, buf.String(), ctx.Err()
	}
}

// roleDoctor labels the doctor's own containers (launchroom.role).
const roleDoctor = "doctor"

func itoa(i int) string { return strconv.Itoa(i) }
