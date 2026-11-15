package main

import (
	"context"
	"slices"
	"sync"
	"time"

	"github.com/vantezzen/launchroom/daemon/internal/docker"
	"github.com/vantezzen/launchroom/daemon/internal/guard/guardapi"
	"github.com/vantezzen/launchroom/daemon/internal/hostinfo"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// hostRuntime tracks rootless Docker, dockerd-guard and the edge ports of
// a rootless host (D49, docs/11 §3.2). The probe runs at start and every
// edgePortsInterval: RootlessKit forgets exposed ports when the rootless
// daemon restarts, so they are asked for again.
type hostRuntime struct {
	mu    sync.Mutex
	facts *docker.RuntimeFacts
	ports []guardapi.EdgePort
	err   string
	// buildPlatforms are what the running buildkitd can build (nil: not running).
	buildPlatforms []state.Platform
	// builds is the "builds" capability (nil: not a build server).
	builds *state.HostCapability
}

const edgePortsInterval = 30 * time.Second

func (rt *daemonRuntime) runtimeLoop(ctx context.Context) {
	ops, ok := rt.docker.(docker.RuntimeOps)
	if !ok {
		return
	}
	logged := false
	for {
		f, err := ops.Runtime(ctx)
		if err == nil {
			var ports []guardapi.EdgePort
			var perr string
			if f.Rootless && f.Guard && f.EdgePorts {
				p, err := ops.EnsureEdgePorts(ctx)
				if err != nil {
					perr = err.Error()
				}
				ports = p
			}
			rt.hostRT.mu.Lock()
			changed := rt.hostRT.facts == nil || *rt.hostRT.facts != f
			rt.hostRT.facts, rt.hostRT.ports, rt.hostRT.err = &f, ports, perr
			rt.hostRT.mu.Unlock()
			if !logged || changed {
				rt.log.Info("docker runtime", "rootless", f.Rootless, "dockerdGuard", f.Guard, "edgePorts", f.EdgePorts, "engine", f.Engine, "hostOs", hostinfo.HostOS(rt.cfg.HostOS, f.Engine))
				logged = true
				rt.kickReport()
			}
			if !f.Guard {
				// A rootful server without the guard: no repeated probing.
				if !f.Rootless {
					return
				}
			}
		}
		select {
		case <-ctx.Done():
			return
		case <-time.After(edgePortsInterval):
		}
	}
}

// runtimeStatus is Report.host.runtime (nil until the first probe).
func (rt *daemonRuntime) runtimeStatus(h *state.HostStatus) *state.HostRuntime {
	rt.hostRT.mu.Lock()
	f, ports, perr, platforms := rt.hostRT.facts, rt.hostRT.ports, rt.hostRT.err, slices.Clone(rt.hostRT.buildPlatforms)
	rt.hostRT.mu.Unlock()
	if f == nil {
		return nil
	}
	var fw *state.FirewallStatus
	if h.Health != nil {
		fw = h.Health.Firewall
	}
	r := hostinfo.Runtime(*f, hostinfo.HostOS(rt.cfg.HostOS, f.Engine), ports, perr, fw, h.Mesh)
	r.BuildPlatforms = platforms
	rt.hostRT.mu.Lock()
	if b := rt.hostRT.builds; b != nil {
		r.Capabilities = append(r.Capabilities, *b)
	}
	rt.hostRT.mu.Unlock()
	return r
}

func (rt *daemonRuntime) setBuilds(c *state.HostCapability) {
	rt.hostRT.mu.Lock()
	old := rt.hostRT.builds
	changed := (old == nil) != (c == nil) || old != nil && *old != *c
	rt.hostRT.builds = c
	rt.hostRT.mu.Unlock()
	if changed {
		rt.kickReport()
	}
}

// setBuildPlatforms records what the running buildkitd can build (nil when
// it is not running) and reports a change.
func (rt *daemonRuntime) setBuildPlatforms(platforms []state.Platform) {
	rt.hostRT.mu.Lock()
	changed := !slices.Equal(rt.hostRT.buildPlatforms, platforms)
	rt.hostRT.buildPlatforms = platforms
	rt.hostRT.mu.Unlock()
	if changed {
		rt.log.Info("build platforms", "platforms", platforms)
		rt.kickReport()
	}
}
