package watchdog

import (
	"context"
	"fmt"
	"slices"
	"time"

	"github.com/moby/moby/api/types/container"
	"github.com/moby/moby/client"

	"github.com/vantezzen/launchroom/daemon/internal/names"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// Container is one control-plane container as the watchdog sees it.
type Container struct {
	ID        string
	Service   state.ControlPlaneService
	State     state.ServiceState
	StartedAt *time.Time
	// Restarts is Docker's restart count.
	Restarts  int
	OOMKilled bool
	// ExitCode is the last exit code (stopped containers).
	ExitCode int
}

// Engine reaches the control-plane containers. Through dockerd-guard it
// may only list, inspect and restart them (guard kind control-plane, D49
// addendum); restarting Postgres or Valkey is refused there too.
type Engine interface {
	// Containers are the control-plane containers of this host's install.
	Containers(ctx context.Context) ([]Container, error)
	// Restart restarts one, waiting up to stop for it to exit.
	Restart(ctx context.Context, id string, stop time.Duration) error
}

// The control plane's compose project (deploy/control-plane/compose.yml).
const (
	controlPlaneProject = "launchroom"
	labelComposeProject = "com.docker.compose.project"
	labelComposeService = "com.docker.compose.service"
)

// NewEngine adapts a Docker client (launchroomd's DOCKER_HOST: the guard).
func NewEngine(api *client.Client) Engine { return &mobyEngine{api: api} }

type mobyEngine struct{ api *client.Client }

func (e *mobyEngine) Containers(ctx context.Context) ([]Container, error) {
	f := client.Filters{}
	f.Add("label", names.LabelManaged+"=true", names.LabelRole+"=control-plane", labelComposeProject+"="+controlPlaneProject)
	list, err := e.api.ContainerList(ctx, client.ContainerListOptions{All: true, Filters: f})
	if err != nil {
		return nil, fmt.Errorf("list control-plane containers: %w", err)
	}
	var out []Container
	for _, s := range list.Items {
		svc := state.ControlPlaneService(s.Labels[labelComposeService])
		if svc.Validate() != nil {
			continue // launchroomd, dockerd-guard, headscale: not watched
		}
		res, err := e.api.ContainerInspect(ctx, s.ID, client.ContainerInspectOptions{})
		if err != nil {
			continue
		}
		out = append(out, fromInspect(svc, res.Container))
	}
	return out, nil
}

func fromInspect(svc state.ControlPlaneService, r container.InspectResponse) Container {
	c := Container{ID: r.ID, Service: svc, State: state.ServiceStopped, Restarts: r.RestartCount}
	st := r.State
	if st == nil {
		return c
	}
	c.OOMKilled, c.ExitCode = st.OOMKilled, st.ExitCode
	switch {
	case st.Restarting:
		c.State = state.ServiceRestarting
	case st.Running && st.Health != nil && st.Health.Status == container.Unhealthy:
		c.State = state.ServiceUnhealthy
	case st.Running:
		c.State = state.ServiceRunning
	}
	if t, err := time.Parse(time.RFC3339Nano, st.StartedAt); err == nil && !t.IsZero() && t.Year() > 1 {
		t = t.UTC()
		c.StartedAt = &t
	}
	return c
}

func (e *mobyEngine) Restart(ctx context.Context, id string, stop time.Duration) error {
	secs := int(stop / time.Second)
	_, err := e.api.ContainerRestart(ctx, id, client.ContainerRestartOptions{Timeout: &secs})
	return err
}

// stopGrace is how long a restart waits for a service to exit (compose's
// stop_grace_period: Horizon finishes its jobs).
func stopGrace(s state.ControlPlaneService) time.Duration {
	if s == state.CPHorizon {
		return 60 * time.Second
	}
	return 10 * time.Second
}

func restartable(s state.ControlPlaneService) bool {
	return slices.Contains(state.RestartableControlPlaneServices, s)
}
