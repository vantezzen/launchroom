package docker

import (
	"context"
	"errors"
	"net/netip"
	"time"
)

// More labels on launchroom-managed objects (see container.go for the rest).
const (
	// LabelSystem marks launchroom's own system containers ("caddy", …).
	LabelSystem = "launchroom.system"
	// LabelSpecHash is a digest of everything the container was created from;
	// a different desired hash means the container must be recreated.
	LabelSpecHash = "launchroom.spec-hash"
	// LabelNetwork carries the protocol id of a managed network.
	LabelNetwork = "launchroom.network"
	// LabelVolume carries the protocol id (or system purpose) of a managed volume.
	LabelVolume = "launchroom.volume"
	// LabelTask marks a one-off container of a run_once task (no workload
	// label: the reconciler never lists, changes or removes it).
	LabelTask = "launchroom.task"
)

// ErrNotManaged is returned when an operation targets an object that is not
// labelled launchroom.managed=true for this server. The daemon never touches
// such objects (AGENTS.md, docs/03 §1).
var ErrNotManaged = errors.New("docker: object is not managed by this launchroomd")

// ErrGuardRefused is returned when dockerd-guard refused a call (the
// request violated its allow-list or the policy floor, docs/09 §6).
var ErrGuardRefused = errors.New("docker: refused by dockerd-guard")

// ErrNotFound is returned when an object does not exist.
var ErrNotFound = errors.New("docker: not found")

// Client is the daemon's narrow view of the Docker Engine. Every
// implementation only lists, changes or removes objects labelled
// launchroom.managed=true and launchroom.server=<this server>.
type Client interface {
	// Info returns engine facts for reports.
	Info(ctx context.Context) (EngineInfo, error)

	// ListContainers returns all managed containers (running or not), optionally
	// narrowed by extra label equality filters.
	ListContainers(ctx context.Context, labels map[string]string) ([]Container, error)
	// InspectContainer returns one managed container.
	InspectContainer(ctx context.Context, id string) (Container, error)
	// CreateContainer creates a container. Config.Labels must mark it as managed by this server.
	CreateContainer(ctx context.Context, cc *ContainerCreate) (id string, err error)
	// StartContainer starts a created or stopped container.
	StartContainer(ctx context.Context, id string) error
	// StopContainer sends SIGTERM and SIGKILL after grace.
	StopContainer(ctx context.Context, id string, grace time.Duration) error
	// RemoveContainer force-removes a container (never its volumes).
	RemoveContainer(ctx context.Context, id string) error
	// StreamLogs calls fn for every log line of a container until the stream
	// ends, ctx is done or fn returns an error.
	StreamLogs(ctx context.Context, id string, opts LogsOptions, fn func(LogLine) error) error

	// ListNetworks returns all managed networks.
	ListNetworks(ctx context.Context) ([]Network, error)
	// CreateNetwork creates a bridge network with an explicit IPAM subnet.
	CreateNetwork(ctx context.Context, n NetworkCreate) (id string, err error)
	// RemoveNetwork removes a managed network.
	RemoveNetwork(ctx context.Context, id string) error

	// ListVolumes returns all managed volumes.
	ListVolumes(ctx context.Context) ([]Volume, error)
	// CreateVolume creates a named local volume. It fails if a volume with
	// that name exists but is not managed by this server.
	CreateVolume(ctx context.Context, v VolumeCreate) error
	// RemoveVolume deletes a managed volume (never forced: Docker refuses a
	// volume any container still uses). ErrNotFound if it does not exist.
	RemoveVolume(ctx context.Context, name string) error

	// ImagePresent reports whether a digest-pinned image is in the local store.
	ImagePresent(ctx context.Context, ref string) (bool, error)
	// PullImage pulls a digest-pinned image (with X-Registry-Auth from the configured hook).
	PullImage(ctx context.Context, ref string) error
}

// EngineInfo are facts about the Docker daemon and host.
type EngineInfo struct {
	Version  string
	CPUs     int
	MemoryMB int
	// OperatingSystem is the host OS as Docker sees it ("Ubuntu 24.04.1 LTS", "Docker Desktop").
	OperatingSystem string
	// KernelVersion is the host kernel release.
	KernelVersion string
}

// Container is the subset of a container's inspect data the daemon uses.
type Container struct {
	ID     string
	Name   string // without the leading "/"
	Image  string // the reference it was created from
	Labels map[string]string
	// State is Docker's state: created, running, paused, restarting, removing, exited, dead.
	State        string
	Running      bool
	Restarting   bool
	OOMKilled    bool
	Pid          int
	ExitCode     int
	StartedAt    time.Time
	FinishedAt   time.Time
	Created      time.Time
	RestartCount int
	// Networks maps attached network names to the container's IPv4 address there.
	Networks map[string]netip.Addr
	// Aliases maps attached network names to the endpoint's user aliases.
	Aliases map[string][]string
	// HostPorts are the host addresses Docker actually bound for published
	// ports (running containers only).
	HostPorts []netip.AddrPort
	// Volumes are the names of the named volumes mounted into the container.
	Volumes []string
	// StopTimeout is the container's own stop timeout (WorkloadSpec.stop), 0 if unset.
	StopTimeout time.Duration
}

// Network is a managed Docker network.
type Network struct {
	ID      string
	Name    string
	Labels  map[string]string
	Subnets []netip.Prefix
	// Bridge is the host interface of a bridge network (the firewall matches on it).
	Bridge string
}

// NetworkCreate describes a bridge network to create.
type NetworkCreate struct {
	Name   string
	Subnet netip.Prefix
	Labels map[string]string
}

// Volume is a managed Docker volume.
type Volume struct {
	Name   string
	Labels map[string]string
}

// VolumeCreate describes a named local volume to create.
type VolumeCreate struct {
	Name   string
	Labels map[string]string
}

// LogsOptions selects container log lines.
type LogsOptions struct {
	Since  time.Time // zero = from the beginning
	Tail   int       // < 0 = all lines
	Follow bool
}

// LogLine is one demultiplexed log line.
type LogLine struct {
	Stream string // "stdout" or "stderr"
	Time   time.Time
	Text   string
}

// ManagedLabels returns the labels every launchroom object carries.
func ManagedLabels(serverID string) map[string]string {
	return map[string]string{LabelManaged: "true", LabelServer: serverID}
}

// IsManaged reports whether labels mark an object as managed by serverID.
func IsManaged(labels map[string]string, serverID string) bool {
	return labels[LabelManaged] == "true" && serverID != "" && labels[LabelServer] == serverID
}
