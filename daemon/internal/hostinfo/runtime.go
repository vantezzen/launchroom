package hostinfo

import (
	"fmt"
	"strings"

	"github.com/vantezzen/launchroom/daemon/internal/docker"
	"github.com/vantezzen/launchroom/daemon/internal/guard/guardapi"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// HostOS is the machine's OS: the installer's LR_HOST_OS, else darwin for
// OrbStack (it only exists on macOS), else Linux. Docker Desktop and Colima
// also run on Linux, so only the installer can tell those apart.
func HostOS(configured state.HostOS, engine state.EngineKind) state.HostOS {
	switch {
	case configured != "":
		return configured
	case engine == state.EngineOrbStack:
		return state.HostDarwin
	}
	return state.HostLinux
}

// Runtime evaluates the capability matrix of Report.host.runtime (D49,
// D53): what the server can do given how Docker runs. It reports codes and
// facts; the dashboard words them.
func Runtime(f docker.RuntimeFacts, hostOS state.HostOS, ports []guardapi.EdgePort, portsErr string, fw *state.FirewallStatus, mesh *state.MeshStatus) *state.HostRuntime {
	engine := f.Engine
	if engine == "" {
		engine = state.EngineDocker
	}
	r := &state.HostRuntime{Docker: state.DockerRootful, Guard: f.Guard, HostOS: hostOS, Engine: engine}
	if f.Rootless {
		r.Docker = state.DockerRootless
	}
	// Docker in a Linux VM: launchroomd's "host" is the VM; the machine's
	// own network only sees the engine's port forwarding (D53).
	vm := hostOS == state.HostDarwin || engine == state.EngineDockerDesktop
	add := func(n state.CapabilityName, s state.CapabilityState, reason state.CapabilityReason, facts string) {
		r.Capabilities = append(r.Capabilities, state.HostCapability{Name: n, State: s, Reason: reason, Message: state.Clip(facts, 512)})
	}
	if f.Guard {
		add(state.CapDockerAccess, state.CapabilityFull, "", "")
	} else {
		add(state.CapDockerAccess, state.CapabilityDegraded, state.CapReasonFullSocket, "")
	}

	switch {
	case vm:
		add(state.CapEdgePorts, state.CapabilityUnavailable, state.CapReasonVM, "")
		add(state.CapClientAddresses, state.CapabilityDegraded, state.CapReasonVM, "")
	case !f.Rootless:
		add(state.CapEdgePorts, state.CapabilityFull, "", "")
		add(state.CapClientAddresses, state.CapabilityFull, "", "")
	case !f.Guard || !f.EdgePorts:
		add(state.CapEdgePorts, state.CapabilityUnavailable, state.CapReasonNoRootlessKitAPI, "")
		add(state.CapClientAddresses, state.CapabilityDegraded, state.CapReasonRootless, "")
	default:
		var refused []string
		for _, p := range ports {
			if !p.Exposed {
				refused = append(refused, fmt.Sprintf("%d/%s (%s)", p.Port, p.Proto, p.Message))
			}
		}
		switch {
		case portsErr != "":
			add(state.CapEdgePorts, state.CapabilityUnavailable, state.CapReasonError, portsErr)
		case len(ports) == 0:
			add(state.CapEdgePorts, state.CapabilityUnavailable, state.CapReasonPortsPending, "")
		case len(refused) > 0:
			add(state.CapEdgePorts, state.CapabilityDegraded, state.CapReasonPortsRefused, strings.Join(refused, ", "))
		default:
			add(state.CapEdgePorts, state.CapabilityFull, "", "")
		}
		add(state.CapClientAddresses, state.CapabilityDegraded, state.CapReasonRootless, "")
	}

	if fw != nil {
		switch fw.Mode {
		case state.FirewallEnforced:
			switch {
			case vm:
				add(state.CapFirewall, state.CapabilityFull, state.CapReasonVM, "")
			case f.Rootless:
				add(state.CapFirewall, state.CapabilityDegraded, state.CapReasonRootless, "")
			default:
				add(state.CapFirewall, state.CapabilityFull, "", "")
			}
		case state.FirewallDisabled:
			add(state.CapFirewall, state.CapabilityUnavailable, state.CapReasonTurnedOff, "")
		default:
			add(state.CapFirewall, state.CapabilityUnavailable, state.CapReasonError, fw.Error)
		}
	}
	if mesh != nil {
		switch {
		case mesh.State == state.MeshError:
			add(state.CapMesh, state.CapabilityUnavailable, state.CapReasonError, mesh.Message)
		case vm:
			add(state.CapMesh, state.CapabilityDegraded, state.CapReasonVM, "")
		case f.Rootless:
			add(state.CapMesh, state.CapabilityDegraded, state.CapReasonRootless, "")
		default:
			add(state.CapMesh, state.CapabilityFull, "", "")
		}
	}
	add(state.CapPortDiscovery, state.CapabilityFull, "", "")
	if engine == state.EngineDockerDesktop {
		add(state.CapWorkloads, state.CapabilityUnavailable, state.CapReasonDockerDesktop, "")
	} else {
		add(state.CapWorkloads, state.CapabilityFull, "", "")
	}
	return r
}
