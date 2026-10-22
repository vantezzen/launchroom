package buildkitd

import (
	"fmt"
	"math"
	"strings"
)

// Build resources (docs/04 §5.2, D59). A build must never take the
// dashboard or the running apps down, so buildkitd gets what is left after
// a reserve for everything else, a low CPU and I/O weight, and the highest
// OOM score. The values come from launchroomd's own measurements of the
// host (HostFacts), never from the dashboard.

// HostFacts is what launchroomd measured on this host.
type HostFacts struct {
	// CPUs is the host's CPU count (0 = unknown).
	CPUs int
	// MemoryMB is the host's total memory (0 = unknown).
	MemoryMB int
	// SwapMB is the host's total swap.
	SwapMB int
	// InUseMB is the memory in use by everything except builds
	// (MemTotal − MemAvailable − buildkitd's own usage; 0 = unknown).
	InUseMB int
	// ControlPlane: the dashboard, its database and workers run here too.
	ControlPlane bool
	// WorkloadsMB are the declared memory limits of this server's workloads.
	WorkloadsMB []int
}

// Overrides are the daemon-local LR_BUILDKIT_MEMORY_MB / LR_BUILDKIT_CPUS.
type Overrides struct {
	MemoryMB int
	CPUs     float64
}

// Resources are the buildkitd container's limits and what builds derive
// from them.
type Resources struct {
	// MemoryMB is the hard memory limit (0 = none).
	MemoryMB int
	// SwapMB is the swap builds may use on top (MemorySwap = Memory + Swap).
	SwapMB int
	// CPUs is a hard CPU cap (0 = none; only on 1–2 CPU hosts).
	CPUs float64
	// CPUShares is the relative CPU weight (Docker's default is 1024).
	CPUShares int64
	// BlkioWeight is the relative block I/O weight (10–1000; 0 = default).
	BlkioWeight uint16
	// OOMScoreAdj makes the kernel pick build processes first under
	// memory pressure (inherited by every build step).
	OOMScoreAdj int
	// Parallelism is BuildKit's max-parallelism.
	Parallelism int
	// NodeHeapMB is the V8 heap that fits the build memory and swap
	// (NODE_OPTIONS --max-old-space-size; 0 = leave Node's default).
	NodeHeapMB int
	// Workers bounds Rust worker threads (SWC/Turbopack) during Node builds.
	Workers int
	// Small: the host has under smallHostMB of memory.
	Small bool
	// NoSwap: a small host without swap (builds that need more memory fail).
	NoSwap bool
	// Tight: the reserve left less than minBuildMB, builds get minBuildMB anyway.
	Tight bool
}

// Planning constants (docs/04 §5.2). Measured on the production control
// plane (dashboard, horizon, reverb, scheduler, postgres, valkey, zot,
// headscale, launchroomd, dockerd-guard, Caddy): about 0.9–1.1 GB idle.
const (
	// controlPlaneMB is the floor reserved for the control plane on its host.
	controlPlaneMB = 1024
	// perWorkloadMB is the floor reserved per workload (at most its limit).
	perWorkloadMB = 256
	// minBuildMB: below this builds can't do anything useful; they get it
	// anyway (the OOM score makes them the first to go).
	minBuildMB = 512
	// smallHostMB: hosts under 4 GB get the CPU cap and the swap hint.
	smallHostMB = 4096
	// quantumMB rounds limits so small measurement changes don't recreate buildkitd.
	quantumMB = 128
	// buildCPUShares gives builds a low CPU weight: cgroup v1 cpu.shares 64
	// (1/16 of a default container), cgroup v2 cpu.weight 3 (runc ≤ 1.2) or
	// 12 (runc ≥ 1.3) against the default 100.
	buildCPUShares = 64
	// buildBlkioWeight is the lowest block I/O weight Docker accepts.
	buildBlkioWeight = 10
	// buildOOMScoreAdj is the highest OOM score adjustment.
	buildOOMScoreAdj = 1000
	// heapPercent of the build memory and swap goes to Node's old space.
	heapPercent = 75
	// workerMB is the build memory per framework build worker.
	workerMB = 1536
)

// osReserveMB is what the OS, Docker and launchroom's own containers keep:
// 256 MB plus 1/32 of the memory, at most 1 GB.
func osReserveMB(totalMB int) int { return min(256+totalMB/32, 1024) }

// headroomMB is the slack on top of the reserve: max(256 MB, 1/16).
func headroomMB(totalMB int) int { return max(256, totalMB/16) }

// PlanResources sizes buildkitd for a host (pure, tested).
//
//   - Memory: total − reserve − headroom, where the reserve is the larger of
//     what is in use now and a floor (OS, the control plane on its host, up
//     to 256 MB per declared workload); at least 512 MB, at most 3/4 of the
//     host. Swap: 3/4 of the host's swap, at most twice the memory.
//   - CPU: a low weight always (an idle host still builds at full speed); a
//     hard cap of CPUs − 0.25 only on hosts with one or two CPUs.
//   - I/O weight lowest, OOM score highest.
//   - Parallelism, Node heap and framework workers follow the memory.
func PlanResources(h HostFacts, o Overrides) Resources {
	r := Resources{CPUShares: buildCPUShares, BlkioWeight: buildBlkioWeight, OOMScoreAdj: buildOOMScoreAdj}
	total := h.MemoryMB
	r.Small = total > 0 && total < smallHostMB
	r.NoSwap = r.Small && h.SwapMB <= 0
	switch {
	case o.MemoryMB > 0:
		r.MemoryMB = o.MemoryMB
	case total > 0:
		floor := osReserveMB(total)
		if h.ControlPlane {
			floor += controlPlaneMB
		}
		for _, w := range h.WorkloadsMB {
			floor += min(max(w, 0), perWorkloadMB)
		}
		reserve := max(h.InUseMB, floor)
		build := total - reserve - headroomMB(total)
		if build < minBuildMB {
			build, r.Tight = minBuildMB, true
		}
		build = min(build, total*3/4)
		r.MemoryMB = max(build/quantumMB*quantumMB, min(minBuildMB, total))
	}
	if r.MemoryMB > 0 && h.SwapMB > 0 {
		r.SwapMB = min(h.SwapMB*3/4, 2*r.MemoryMB) / quantumMB * quantumMB
	}
	switch {
	case o.CPUs > 0:
		r.CPUs = o.CPUs
	case h.CPUs == 1 || h.CPUs == 2:
		r.CPUs = float64(h.CPUs) - 0.25
	}
	cpus := h.CPUs
	if cpus <= 0 {
		cpus = 2
	}
	if r.CPUs > 0 {
		cpus = max(1, int(math.Ceil(r.CPUs)))
	}
	r.Parallelism = max(2, cpus)
	r.Workers = max(1, cpus-1)
	if r.MemoryMB > 0 {
		// One parallel step per GB (at least one), one framework worker per
		// 1.5 GB: parallel Node processes each bring their own heap.
		r.Parallelism = max(1, min(r.Parallelism, r.MemoryMB/1024))
		r.Workers = max(1, min(r.Workers, r.MemoryMB/workerMB))
		// The kernel stops a build at memory + swap; a heap that fits makes
		// V8 collect garbage harder instead.
		r.NodeHeapMB = max(256, (r.MemoryMB+r.SwapMB)*heapPercent/100/quantumMB*quantumMB)
	}
	return r
}

// Settle keeps the current resources unless the new plan differs enough to
// be worth recreating buildkitd (memory by 384 MB or more, swap by 512 MB or
// more, or another CPU cap); small measurement noise never recreates it.
func Settle(cur, next Resources) Resources {
	if cur.MemoryMB == 0 && cur.CPUShares == 0 {
		return next // nothing applied yet
	}
	if abs(cur.MemoryMB-next.MemoryMB) >= 384 || abs(cur.SwapMB-next.SwapMB) >= 512 || cur.CPUs != next.CPUs {
		return next
	}
	// Facts that don't change the container still follow the host.
	cur.Small, cur.NoSwap, cur.Tight = next.Small, next.NoSwap, next.Tight
	return cur
}

func abs(x int) int {
	if x < 0 {
		return -x
	}
	return x
}

// GB formats megabytes as "0.7 GB", "2 GB", "12 GB".
func GB(mb int) string {
	g := float64(mb) / 1024
	switch {
	case g >= 10:
		return fmt.Sprintf("%.0f GB", g)
	case math.Abs(g-math.Round(g)) < 0.05:
		return fmt.Sprintf("%.0f GB", math.Round(g))
	}
	return fmt.Sprintf("%.1f GB", g)
}

// cpuText formats a CPU cap ("0.75 CPU", "1.75 CPUs").
func cpuText(c float64) string {
	s := strings.TrimRight(strings.TrimRight(fmt.Sprintf("%.2f", c), "0"), ".")
	if c <= 1 {
		return s + " CPU"
	}
	return s + " CPUs"
}

// Summary is the one-line description of the limits for the server page
// and the build log ("Builds are limited to 0.7 GB of memory …").
func (r Resources) Summary() string {
	var parts []string
	if r.MemoryMB > 0 {
		mem := GB(r.MemoryMB) + " of memory"
		if r.SwapMB > 0 {
			mem += " (plus " + GB(r.SwapMB) + " of swap)"
		}
		parts = append(parts, mem)
	}
	if r.CPUs > 0 {
		parts = append(parts, cpuText(r.CPUs))
	}
	s := "Builds run at low priority"
	if len(parts) > 0 {
		s = "Builds are limited to " + strings.Join(parts, " and ") + " and run at low priority"
	}
	return s + ", so your apps and the dashboard stay responsive while they build."
}

// SwapCommand creates a 2 GB swap file by hand (the installers' swap step,
// docs/11 §2.1c).
const SwapCommand = "sudo fallocate -l 2G /swapfile-launchroom && sudo chmod 600 /swapfile-launchroom && sudo mkswap /swapfile-launchroom && sudo swapon /swapfile-launchroom && echo '/swapfile-launchroom none swap sw 0 0' | sudo tee -a /etc/fstab"

// SwapHint is the advice for a small host without swap.
const SwapHint = "This server has no swap, so a build that needs more memory fails instead of slowing down. Add 2 GB of swap by re-running the launchroom installer, or run: " + SwapCommand
