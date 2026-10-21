package docker

import (
	"context"
	"encoding/json"
	"fmt"
	"io"
	"net"
	"net/http"
	"slices"
	"strings"
	"time"

	"github.com/moby/moby/client"

	"github.com/vantezzen/launchroom/daemon/internal/guard/guardapi"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// RuntimeFacts say how Docker runs and how launchroomd reaches it (D49).
type RuntimeFacts struct {
	// Rootless: the Docker daemon runs rootless (docker info "name=rootless").
	Rootless bool
	// Guard: launchroomd's Docker endpoint is dockerd-guard.
	Guard bool
	// EdgePorts: the guard can expose 80/443 on a rootless host.
	EdgePorts bool
	// Engine is the Docker distribution (EngineKindOf).
	Engine state.EngineKind
}

// EngineKindOf names the Docker distribution from `docker info`
// (D53): Docker Desktop and OrbStack say so in OperatingSystem, Colima's
// VM is named "colima" ("colima-<profile>" for other profiles). Everything
// else is Docker Engine. The kernel is deliberately not used: a dind
// container on OrbStack runs OrbStack's kernel but is no OrbStack engine.
func EngineKindOf(operatingSystem, name string) state.EngineKind {
	osName, name := strings.ToLower(strings.TrimSpace(operatingSystem)), strings.ToLower(strings.TrimSpace(name))
	switch {
	case osName == "docker desktop":
		return state.EngineDockerDesktop
	case osName == "orbstack":
		return state.EngineOrbStack
	case name == "colima", strings.HasPrefix(name, "colima-"):
		return state.EngineColima
	}
	return state.EngineDocker
}

// RuntimeOps are the runtime probes (Engine only).
type RuntimeOps interface {
	Runtime(ctx context.Context) (RuntimeFacts, error)
	// EnsureEdgePorts asks dockerd-guard to expose the edge ports of a
	// rootless host (idempotent).
	EnsureEdgePorts(ctx context.Context) ([]guardapi.EdgePort, error)
}

var _ RuntimeOps = (*Engine)(nil)

// Runtime implements RuntimeOps.
func (e *Engine) Runtime(ctx context.Context) (RuntimeFacts, error) {
	ctx, cancel := e.call(ctx)
	defer cancel()
	res, err := e.api.Info(ctx, client.InfoOptions{})
	if err != nil {
		return RuntimeFacts{}, wrap("info", err)
	}
	f := RuntimeFacts{
		Rootless: slices.Contains(res.Info.SecurityOptions, "name=rootless"),
		Engine:   EngineKindOf(res.Info.OperatingSystem, res.Info.Name),
	}
	var st guardapi.Status
	if code, err := e.guardCall(ctx, http.MethodGet, guardapi.StatusPath, &st); err == nil && code == http.StatusOK && st.Guard {
		f.Guard, f.EdgePorts = true, st.EdgePorts
	}
	return f, nil
}

// EnsureEdgePorts implements RuntimeOps.
func (e *Engine) EnsureEdgePorts(ctx context.Context) ([]guardapi.EdgePort, error) {
	ctx, cancel := context.WithTimeout(ctx, e.callTimeout)
	defer cancel()
	var out struct {
		Ports   []guardapi.EdgePort `json:"ports"`
		Message string              `json:"message"`
	}
	code, err := e.guardCall(ctx, http.MethodPost, guardapi.EdgePortsPath, &out)
	if err != nil {
		return nil, fmt.Errorf("docker: edge ports: %w", err)
	}
	if code != http.StatusOK {
		return nil, fmt.Errorf("docker: edge ports: HTTP %d: %s", code, out.Message)
	}
	return out.Ports, nil
}

// guardCall talks to dockerd-guard's own endpoints over the Docker
// connection (a plain Docker daemon answers 404).
func (e *Engine) guardCall(ctx context.Context, method, path string, out any) (int, error) {
	dial := e.api.Dialer()
	hc := &http.Client{Transport: &http.Transport{DialContext: func(ctx context.Context, _, _ string) (net.Conn, error) { return dial(ctx) }}, Timeout: 30 * time.Second}
	defer hc.CloseIdleConnections()
	req, err := http.NewRequestWithContext(ctx, method, "http://docker"+path, http.NoBody)
	if err != nil {
		return 0, err
	}
	res, err := hc.Do(req)
	if err != nil {
		return 0, err
	}
	defer func() { _ = res.Body.Close() }()
	raw, err := io.ReadAll(io.LimitReader(res.Body, 64<<10))
	if err != nil {
		return res.StatusCode, err
	}
	_ = json.Unmarshal(raw, out)
	return res.StatusCode, nil
}
