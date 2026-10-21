package state

import (
	"math"
	"time"
)

// Host health facts (docs/03 §1 Telemetry, docs/04 §8 retention). The daemon
// collects them periodically and attaches the latest snapshot to every
// report; the dashboard derives warnings from them. Every part is optional:
// a fact the daemon cannot read is omitted, never guessed.

// HostHealth is the latest host health snapshot.
type HostHealth struct {
	// CollectedAt is when the snapshot was taken (daemon clock).
	CollectedAt time.Time `json:"collectedAt"`
	// DataDisk is the filesystem holding the daemon data dir.
	DataDisk *FilesystemUsage `json:"dataDisk,omitzero" jsonschema:"nullable"`
	// DockerDisk is the filesystem holding Docker's root dir (omitted when
	// the daemon cannot see it, e.g. when it runs in a container without it).
	DockerDisk *FilesystemUsage `json:"dockerDisk,omitzero" jsonschema:"nullable"`
	Memory     *MemoryUsage     `json:"memory,omitzero" jsonschema:"nullable"`
	Load       *LoadAverage     `json:"load,omitzero" jsonschema:"nullable"`
	// Inotify are the kernel's inotify limits (/proc/sys/fs/inotify).
	Inotify *InotifyLimits `json:"inotify,omitzero" jsonschema:"nullable"`
	// Networks counts Docker networks on the host.
	Networks *NetworkUsage `json:"networks,omitzero" jsonschema:"nullable"`
	// Docker is Docker's own disk accounting (system df).
	Docker *DockerUsage `json:"docker,omitzero" jsonschema:"nullable"`
	// BuildCacheMB is the size of the daemon's BuildKit cache (build role only).
	BuildCacheMB *int64 `json:"buildCacheMb,omitzero" jsonschema:"nullable,minimum=0"`
	// ClockSkewMs is the dashboard's clock minus the daemon's, from the Date
	// header of the last dashboard response (±1 s resolution).
	ClockSkewMs *int64 `json:"clockSkewMs,omitzero" jsonschema:"nullable"`
	// Cleanup is the result of the last disk cleanup run.
	Cleanup *CleanupResult `json:"cleanup,omitzero" jsonschema:"nullable"`
	// Firewall is the state of the daemon's network isolation (docs/05 §1).
	Firewall *FirewallStatus `json:"firewall,omitzero" jsonschema:"nullable"`
}

// FirewallMode is the state of the nftables isolation.
type FirewallMode string

// Firewall modes.
const (
	// FirewallEnforced: the rules are in place and match the expected ruleset.
	FirewallEnforced FirewallMode = "enforced"
	// FirewallDisabled: turned off in the daemon's local config (LR_FIREWALL=off).
	FirewallDisabled FirewallMode = "disabled"
	// FirewallError: the rules could not be applied; workloads are not started.
	FirewallError FirewallMode = "error"
)

var firewallModes = []string{string(FirewallEnforced), string(FirewallDisabled), string(FirewallError)}

// ProtocolSchema implements SchemaDescriber.
func (FirewallMode) ProtocolSchema() ScalarSchema { return enumSchema(firewallModes) }

// Validate checks the value.
func (v FirewallMode) Validate() error { return checkEnum("firewall mode", string(v), firewallModes) }

// FirewallStatus reports the daemon's network isolation.
type FirewallStatus struct {
	Mode FirewallMode `json:"mode"`
	// CheckedAt is the last time the live rules were compared with the expected ones.
	CheckedAt time.Time `json:"checkedAt"`
	// Networks is the number of launchroom networks (bridges) covered.
	Networks int `json:"networks" jsonschema:"minimum=0"`
	// Repairs counts drift repairs since the daemon started (rules changed or
	// removed by something else and re-applied).
	Repairs int `json:"repairs" jsonschema:"minimum=0"`
	// Error is a short description of the last failure.
	Error string `json:"error,omitzero" jsonschema:"maxLength=1024"`
}

// FilesystemUsage is the size and free space of one filesystem.
type FilesystemUsage struct {
	TotalMB int64 `json:"totalMb" jsonschema:"minimum=0"`
	FreeMB  int64 `json:"freeMb" jsonschema:"minimum=0"`
}

// MemoryUsage is host memory from /proc/meminfo.
type MemoryUsage struct {
	TotalMB     int64 `json:"totalMb" jsonschema:"minimum=0"`
	AvailableMB int64 `json:"availableMb" jsonschema:"minimum=0"`
}

// LoadAverage is /proc/loadavg.
type LoadAverage struct {
	Load1  float64 `json:"load1" jsonschema:"minimum=0,maximum=1000000"`
	Load5  float64 `json:"load5" jsonschema:"minimum=0,maximum=1000000"`
	Load15 float64 `json:"load15" jsonschema:"minimum=0,maximum=1000000"`
}

// InotifyLimits are the kernel's per-user inotify limits.
type InotifyLimits struct {
	MaxUserWatches   int64 `json:"maxUserWatches" jsonschema:"minimum=0"`
	MaxUserInstances int64 `json:"maxUserInstances" jsonschema:"minimum=0"`
	MaxQueuedEvents  int64 `json:"maxQueuedEvents" jsonschema:"minimum=0"`
}

// NetworkUsage counts Docker networks: all of them (Docker's default address
// pools run out at about 30 networks) and those launchroom manages (with
// subnets from launchroom's own range, docs/05 §1).
type NetworkUsage struct {
	Total   int `json:"total" jsonschema:"minimum=0"`
	Managed int `json:"managed" jsonschema:"minimum=0"`
}

// DockerUsage is Docker's disk accounting.
type DockerUsage struct {
	ImagesMB int64 `json:"imagesMb" jsonschema:"minimum=0"`
	// ImagesReclaimableMB is what images not used by any container take.
	ImagesReclaimableMB int64 `json:"imagesReclaimableMb" jsonschema:"minimum=0"`
	ContainersMB        int64 `json:"containersMb" jsonschema:"minimum=0"`
}

// CleanupTrigger is why a cleanup ran.
type CleanupTrigger string

// Cleanup triggers.
const (
	CleanupScheduled    CleanupTrigger = "schedule"
	CleanupDiskPressure CleanupTrigger = "disk_pressure"
)

var cleanupTriggers = []string{string(CleanupScheduled), string(CleanupDiskPressure)}

// ProtocolSchema implements SchemaDescriber.
func (CleanupTrigger) ProtocolSchema() ScalarSchema { return enumSchema(cleanupTriggers) }

// Validate checks the value.
func (v CleanupTrigger) Validate() error {
	return checkEnum("cleanup trigger", string(v), cleanupTriggers)
}

// CleanupResult summarises one cleanup run (docs/04 §8): unused launchroom
// images beyond retention, BuildKit cache by age, leftover one-off containers.
// Volumes are never pruned.
type CleanupResult struct {
	At      time.Time      `json:"at"`
	Trigger CleanupTrigger `json:"trigger"`
	// RemovedImages and RemovedContainers count what was deleted.
	RemovedImages     int `json:"removedImages" jsonschema:"minimum=0"`
	RemovedContainers int `json:"removedContainers" jsonschema:"minimum=0"`
	// ReclaimedMB is the space freed by image removals plus the BuildKit prune.
	ReclaimedMB int64 `json:"reclaimedMb" jsonschema:"minimum=0"`
	// Error is a short, secret-free description of a partial failure.
	Error string `json:"error,omitzero" jsonschema:"maxLength=1024"`
}

func (h *HostHealth) validate(e *errs, p string) {
	if h.CollectedAt.IsZero() {
		e.addf(p+".collectedAt", "is required")
	}
	for name, fs := range map[string]*FilesystemUsage{p + ".dataDisk": h.DataDisk, p + ".dockerDisk": h.DockerDisk} {
		if fs != nil && (fs.TotalMB < 0 || fs.FreeMB < 0) {
			e.addf(name, "sizes must be >= 0")
		}
	}
	if m := h.Memory; m != nil && (m.TotalMB < 0 || m.AvailableMB < 0) {
		e.addf(p+".memory", "sizes must be >= 0")
	}
	if l := h.Load; l != nil {
		for _, v := range []float64{l.Load1, l.Load5, l.Load15} {
			if math.IsNaN(v) || v < 0 || v > 1e6 {
				e.addf(p+".load", "must be between 0 and 1000000")
				break
			}
		}
	}
	if i := h.Inotify; i != nil && (i.MaxUserWatches < 0 || i.MaxUserInstances < 0 || i.MaxQueuedEvents < 0) {
		e.addf(p+".inotify", "limits must be >= 0")
	}
	if n := h.Networks; n != nil && (n.Total < 0 || n.Managed < 0) {
		e.addf(p+".networks", "counts must be >= 0")
	}
	if d := h.Docker; d != nil && (d.ImagesMB < 0 || d.ImagesReclaimableMB < 0 || d.ContainersMB < 0) {
		e.addf(p+".docker", "sizes must be >= 0")
	}
	if h.BuildCacheMB != nil && *h.BuildCacheMB < 0 {
		e.addf(p+".buildCacheMb", "must be >= 0")
	}
	if c := h.Cleanup; c != nil {
		if c.At.IsZero() {
			e.addf(p+".cleanup.at", "is required")
		}
		e.check(p+".cleanup.trigger", c.Trigger)
		if c.RemovedImages < 0 || c.RemovedContainers < 0 || c.ReclaimedMB < 0 {
			e.addf(p+".cleanup", "counts must be >= 0")
		}
		checkText(e, p+".cleanup.error", c.Error, 1024)
	}
	if f := h.Firewall; f != nil {
		e.check(p+".firewall.mode", f.Mode)
		if f.CheckedAt.IsZero() {
			e.addf(p+".firewall.checkedAt", "is required")
		}
		if f.Networks < 0 || f.Repairs < 0 {
			e.addf(p+".firewall", "counts must be >= 0")
		}
		checkText(e, p+".firewall.error", f.Error, 1024)
	}
}
