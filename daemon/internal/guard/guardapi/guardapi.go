// Package guardapi holds what launchroomd and dockerd-guard share about the
// guard's own endpoints and refusals (D49). It imports nothing, so both
// internal/docker and internal/guard can use it.
package guardapi

const (
	// StatusPath answers launchroomd's "am I guarded?" question (GET).
	StatusPath = "/launchroom/guard/v1/status"
	// EdgePortsPath exposes the edge ports on rootless hosts (POST).
	EdgePortsPath = "/launchroom/guard/v1/edge-ports"
	// RefusalPrefix starts the message of every refusal (HTTP 403).
	RefusalPrefix = "dockerd-guard: "
	// NotManagedMarker is part of the message when the target object is not
	// launchroom's; launchroomd maps it to docker.ErrNotManaged.
	NotManagedMarker = "not managed by launchroom"
)

// Status is the body of StatusPath.
type Status struct {
	Guard    bool   `json:"guard"`
	Version  string `json:"version"`
	Rootless bool   `json:"rootless"`
	// EdgePorts is true when the guard can expose the edge ports of a
	// rootless host (RootlessKit API available).
	EdgePorts bool `json:"edgePorts"`
}

// EdgePort is one exposed port (the answer of EdgePortsPath).
type EdgePort struct {
	Proto   string `json:"proto"`
	Port    int    `json:"port"`
	Exposed bool   `json:"exposed"`
	Message string `json:"message,omitempty"`
}
