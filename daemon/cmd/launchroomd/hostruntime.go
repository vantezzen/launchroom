package main

import (
	"context"
	"fmt"
	"slices"
	"strings"
	"sync"
	"time"

	"github.com/vantezzen/launchroom/daemon/internal/build/buildkitd"
	"github.com/vantezzen/launchroom/daemon/internal/docker"
	"github.com/vantezzen/launchroom/daemon/internal/guard/guardapi"
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
				rt.log.Info("docker runtime", "rootless", f.Rootless, "dockerdGuard", f.Guard, "edgePorts", f.EdgePorts, "engine", f.Engine, "hostOs", hostOSOf(rt.cfg.HostOS, f.Engine))
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
	r := runtimeCapabilities(*f, hostOSOf(rt.cfg.HostOS, f.Engine), ports, perr, fw, h.Mesh)
	r.BuildPlatforms = platforms
	rt.hostRT.mu.Lock()
	if b := rt.hostRT.builds; b != nil {
		r.Capabilities = append(r.Capabilities, *b)
	}
	rt.hostRT.mu.Unlock()
	return r
}

// setBuilds records the "builds" capability after a buildkitd sync and
// reports a change.
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

// buildsCapability is the "builds" row (pure, tested): nil when BuildKit is
// off here; unavailable when the host restricts user namespaces, lacks
// launchroom's AppArmor profile and buildkitd doesn't answer (D58).
func buildsCapability(enabled bool, userns buildkitd.Userns, answering bool, resources buildkitd.Resources) *state.HostCapability {
	if !enabled {
		return nil
	}
	c := &state.HostCapability{Name: state.CapBuilds, State: state.CapabilityFull}
	switch {
	case userns == buildkitd.UsernsProfile:
		c.Message = "Ubuntu restricts user namespaces here; launchroom's AppArmor profile " + buildkitd.AppArmorProfile + " allows them for the build container only"
	case userns == buildkitd.UsernsBlocked && !answering:
		c.State = state.CapabilityUnavailable
		c.Message = "Ubuntu's user-namespace restriction blocks BuildKit here. Re-run the launchroom installer on this server, or run: " + buildkitd.ProfileCommand
	case resources.NoSwap:
		c.State = state.CapabilityDegraded
		c.Message = buildkitd.SwapHint
	case resources.MemoryMB > 0:
		c.Message = resources.Summary()
	}
	return c
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

// hostOSOf is the machine's OS: the installer's LR_HOST_OS, else darwin
// for OrbStack (macOS only), else Linux. Docker Desktop and Colima also
// exist on Linux, so they need the installer's word.
func hostOSOf(configured state.HostOS, engine state.EngineKind) state.HostOS {
	switch {
	case configured != "":
		return configured
	case engine == state.EngineOrbStack:
		return state.HostDarwin
	}
	return state.HostLinux
}

// engineNames are the engines as the server page names them in messages.
var engineNames = map[state.EngineKind]string{
	state.EngineDockerDesktop: "Docker Desktop", state.EngineOrbStack: "OrbStack", state.EngineColima: "Colima",
}

// runtimeCapabilities evaluates the capability matrix (pure, tested).
func runtimeCapabilities(f docker.RuntimeFacts, hostOS state.HostOS, ports []guardapi.EdgePort, portsErr string, fw *state.FirewallStatus, mesh *state.MeshStatus) *state.HostRuntime {
	engine := f.Engine
	if engine == "" {
		engine = state.EngineDocker
	}
	r := &state.HostRuntime{Docker: state.DockerRootful, Guard: f.Guard, HostOS: hostOS, Engine: engine}
	if f.Rootless {
		r.Docker = state.DockerRootless
	}
	// Docker in a Linux VM (a Mac, or Docker Desktop anywhere): launchroomd's
	// "host" is the VM; the machine's own network only sees the engine's
	// port forwarding (D53).
	vm := hostOS == state.HostDarwin || engine == state.EngineDockerDesktop
	vmName := engineNames[engine]
	if vmName == "" {
		vmName = "the engine"
	}
	add := func(n state.CapabilityName, s state.CapabilityState, msg string) {
		if len(msg) > 512 {
			msg = msg[:512]
		}
		r.Capabilities = append(r.Capabilities, state.HostCapability{Name: n, State: s, Message: msg})
	}
	if f.Guard {
		add(state.CapDockerAccess, state.CapabilityFull, "")
	} else {
		add(state.CapDockerAccess, state.CapabilityDegraded, "launchroomd holds the full Docker socket; re-run the installer to add dockerd-guard")
	}

	switch {
	case vm:
		add(state.CapEdgePorts, state.CapabilityUnavailable, "Docker runs in "+vmName+"'s Linux VM here: ports 80 and 443 open inside the VM, not on your network. Let a public server serve traffic")
		add(state.CapClientAddresses, state.CapabilityDegraded, "connections reach the VM through "+vmName+"'s port forwarding, so apps see an internal address")
	case !f.Rootless:
		add(state.CapEdgePorts, state.CapabilityFull, "")
		add(state.CapClientAddresses, state.CapabilityFull, "")
	case !f.Guard || !f.EdgePorts:
		add(state.CapEdgePorts, state.CapabilityUnavailable, "rootless Docker: ports 80 and 443 are not exposed (dockerd-guard has no RootlessKit API; re-run server.sh --rootless)")
		add(state.CapClientAddresses, state.CapabilityDegraded, "rootless Docker forwards connections, so apps see an internal address")
	default:
		var missing []string
		for _, p := range ports {
			if !p.Exposed {
				missing = append(missing, fmt.Sprintf("%d/%s (%s)", p.Port, p.Proto, p.Message))
			}
		}
		switch {
		case portsErr != "":
			add(state.CapEdgePorts, state.CapabilityUnavailable, portsErr)
		case len(ports) == 0:
			add(state.CapEdgePorts, state.CapabilityUnavailable, "edge ports not exposed yet")
		case len(missing) > 0:
			add(state.CapEdgePorts, state.CapabilityDegraded, "not exposed: "+strings.Join(missing, ", ")+"; ports below 1024 need net.ipv4.ip_unprivileged_port_start=80 on the host")
		default:
			add(state.CapEdgePorts, state.CapabilityFull, "")
		}
		add(state.CapClientAddresses, state.CapabilityDegraded, "rootless Docker forwards connections, so apps see an internal address")
	}

	if fw != nil {
		switch fw.Mode {
		case state.FirewallEnforced:
			switch {
			case vm:
				add(state.CapFirewall, state.CapabilityFull, "rules apply inside "+vmName+"'s Linux VM, where the containers run; they also keep apps away from the machine itself (its gateway addresses are private and dropped)")
			case f.Rootless:
				add(state.CapFirewall, state.CapabilityDegraded, "rules apply inside the rootless network namespace, where container traffic flows; the server's own interfaces are separated by RootlessKit")
			default:
				add(state.CapFirewall, state.CapabilityFull, "")
			}
		case state.FirewallDisabled:
			add(state.CapFirewall, state.CapabilityUnavailable, "turned off on this server (LR_FIREWALL=off)")
		default:
			add(state.CapFirewall, state.CapabilityUnavailable, fw.Error)
		}
	}
	if mesh != nil {
		switch {
		case mesh.State == state.MeshError:
			add(state.CapMesh, state.CapabilityUnavailable, mesh.Message)
		case vm:
			add(state.CapMesh, state.CapabilityDegraded, "the private network runs inside "+vmName+"'s VM, behind its NAT, so peers usually connect through the relay")
		case f.Rootless:
			add(state.CapMesh, state.CapabilityDegraded, "the private network runs inside the rootless network namespace; its UDP is NATed, so peers may connect through relays")
		default:
			add(state.CapMesh, state.CapabilityFull, "")
		}
	}
	add(state.CapPortDiscovery, state.CapabilityFull, "")
	if engine == state.EngineDockerDesktop {
		add(state.CapWorkloads, state.CapabilityUnavailable, "Docker Desktop opens published ports on the machine, not on the private network address inside its VM, so apps here can't be reached from your other servers. Builds work; to run apps on a Mac, use OrbStack or Colima")
	} else {
		add(state.CapWorkloads, state.CapabilityFull, "")
	}
	return r
}
