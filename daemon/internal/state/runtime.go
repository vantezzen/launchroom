package state

// HostRuntime describes how launchroomd runs on the server: rootful or
// rootless Docker, whether it reaches Docker through dockerd-guard, and
// what the server can do as a result (docs/09 §6, docs/11 §3.2, D49).
// The dashboard shows it as the capability matrix on the server page.
type HostRuntime struct {
	Docker DockerMode `json:"docker"`
	// Guard is true when launchroomd talks to Docker through dockerd-guard.
	Guard bool `json:"guard"`
	// HostOS is the operating system of the machine (darwin: a Mac, whose
	// Docker runs in a Linux VM; D53). Omitted by older daemons.
	HostOS HostOS `json:"hostOs,omitzero"`
	// Engine is the Docker distribution launchroomd runs on (D53).
	Engine EngineKind `json:"engine,omitzero"`
	// BuildPlatforms are the platforms this server's BuildKit can build
	// for: its own and the ones the engine emulates (binfmt handlers such
	// as Docker Desktop's QEMU or OrbStack's Rosetta; launchroom never
	// installs any). Omitted while buildkitd is not running (D53).
	BuildPlatforms []Platform `json:"buildPlatforms,omitzero" jsonschema:"maxItems=8"`
	// Capabilities are the server's capabilities, each with its state.
	Capabilities []HostCapability `json:"capabilities" jsonschema:"maxItems=16"`
}

// DockerMode is how the Docker daemon runs.
type DockerMode string

// Docker modes.
const (
	DockerRootful  DockerMode = "rootful"
	DockerRootless DockerMode = "rootless"
)

var dockerModes = []string{string(DockerRootful), string(DockerRootless)}

// ProtocolSchema implements SchemaDescriber.
func (DockerMode) ProtocolSchema() ScalarSchema { return enumSchema(dockerModes) }

// Validate checks the value.
func (v DockerMode) Validate() error { return checkEnum("docker mode", string(v), dockerModes) }

// HostOS is the machine's operating system as the installer saw it.
type HostOS string

// Host operating systems.
const (
	HostLinux  HostOS = "linux"
	HostDarwin HostOS = "darwin"
)

var hostOSes = []string{string(HostLinux), string(HostDarwin)}

// ProtocolSchema implements SchemaDescriber.
func (HostOS) ProtocolSchema() ScalarSchema { return enumSchema(hostOSes) }

// Validate checks the value.
func (v HostOS) Validate() error { return checkEnum("host os", string(v), hostOSes) }

// EngineKind is the Docker distribution: Docker Engine on Linux, or one
// of the macOS engines that run Docker in a Linux VM (D53).
type EngineKind string

// Engines.
const (
	EngineDocker        EngineKind = "docker-engine"
	EngineDockerDesktop EngineKind = "docker-desktop"
	EngineOrbStack      EngineKind = "orbstack"
	EngineColima        EngineKind = "colima"
)

var engineKinds = []string{string(EngineDocker), string(EngineDockerDesktop), string(EngineOrbStack), string(EngineColima)}

// ProtocolSchema implements SchemaDescriber.
func (EngineKind) ProtocolSchema() ScalarSchema { return enumSchema(engineKinds) }

// Validate checks the value.
func (v EngineKind) Validate() error { return checkEnum("engine", string(v), engineKinds) }

// HostCapability is one row of the capability matrix.
type HostCapability struct {
	Name  CapabilityName  `json:"name"`
	State CapabilityState `json:"state"`
	// Message explains a degraded or unavailable capability (secret-free).
	Message string `json:"message,omitzero" jsonschema:"maxLength=512"`
}

// CapabilityName names a server capability.
type CapabilityName string

// Capabilities.
const (
	// CapDockerAccess: how launchroomd reaches Docker (full = through
	// dockerd-guard, degraded = it holds the whole Docker socket).
	CapDockerAccess CapabilityName = "dockerAccess"
	// CapEdgePorts: public HTTP/HTTPS on 80/443 for the edge Caddy.
	CapEdgePorts CapabilityName = "edgePorts"
	// CapClientAddresses: apps and logs see the visitors' addresses.
	CapClientAddresses CapabilityName = "clientAddresses"
	// CapFirewall: nftables isolation between environments and from the host.
	CapFirewall CapabilityName = "firewall"
	// CapMesh: the private network to other servers (kernel TUN mode).
	CapMesh CapabilityName = "mesh"
	// CapPortDiscovery: detecting the ports apps listen on.
	CapPortDiscovery CapabilityName = "portDiscovery"
	// CapWorkloads: apps here can be reached from the other servers (their
	// ports published on the mesh address). Unavailable on Docker Desktop,
	// which binds published ports on the Mac instead of in its VM (D53).
	CapWorkloads CapabilityName = "workloads"
)

var capabilityNames = []string{string(CapDockerAccess), string(CapEdgePorts), string(CapClientAddresses), string(CapFirewall), string(CapMesh), string(CapPortDiscovery), string(CapWorkloads)}

// ProtocolSchema implements SchemaDescriber.
func (CapabilityName) ProtocolSchema() ScalarSchema { return enumSchema(capabilityNames) }

// Validate checks the value.
func (v CapabilityName) Validate() error {
	return checkEnum("capability", string(v), capabilityNames)
}

// CapabilityState is the state of a capability.
type CapabilityState string

// Capability states.
const (
	CapabilityFull        CapabilityState = "full"
	CapabilityDegraded    CapabilityState = "degraded"
	CapabilityUnavailable CapabilityState = "unavailable"
)

var capabilityStates = []string{string(CapabilityFull), string(CapabilityDegraded), string(CapabilityUnavailable)}

// ProtocolSchema implements SchemaDescriber.
func (CapabilityState) ProtocolSchema() ScalarSchema { return enumSchema(capabilityStates) }

// Validate checks the value.
func (v CapabilityState) Validate() error {
	return checkEnum("capability state", string(v), capabilityStates)
}

func (r *HostRuntime) validate(e *errs, p string) {
	e.check(p+".docker", r.Docker)
	if r.HostOS != "" {
		e.check(p+".hostOs", r.HostOS)
	}
	if r.Engine != "" {
		e.check(p+".engine", r.Engine)
	}
	checkCount(e, p+".buildPlatforms", len(r.BuildPlatforms), 0, 8)
	for i, pl := range r.BuildPlatforms {
		e.check(idx(p+".buildPlatforms", i), pl)
	}
	unique(e, p+".buildPlatforms", r.BuildPlatforms)
	checkCount(e, p+".capabilities", len(r.Capabilities), 0, 16)
	names := make([]CapabilityName, 0, len(r.Capabilities))
	for i, c := range r.Capabilities {
		q := idx(p+".capabilities", i)
		e.check(q+".name", c.Name)
		e.check(q+".state", c.State)
		checkText(e, q+".message", c.Message, 512)
		names = append(names, c.Name)
	}
	unique(e, p+".capabilities.name", names)
}
