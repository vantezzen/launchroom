// Package hostinfo collects the host health facts the daemon reports
// periodically (docs/03 §1 Telemetry): disk of the data dir and of Docker's
// root dir, memory, load, inotify limits, Docker network count, Docker disk
// accounting, BuildKit cache size and clock skew against the dashboard.
// The dashboard turns them into warnings: full disks, exhausted address
// pools and low inotify limits are the most common causes of a self-hosted
// install that "suddenly stopped working".
//
// Every fact is best effort: what cannot be read is omitted.
package hostinfo

import (
	"bufio"
	"bytes"
	"context"
	"os"
	"path/filepath"
	"strconv"
	"strings"
	"time"

	"github.com/vantezzen/launchroom/daemon/internal/docker"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// Options configures a Collector.
type Options struct {
	DataDir string
	// Docker reads host-wide Docker facts (nil: skipped).
	Docker docker.HostOps
	// BuildCacheMB returns the BuildKit cache size (ok=false: no BuildKit running).
	BuildCacheMB func(ctx context.Context) (int64, bool)
	// ClockSkew returns the dashboard clock minus the daemon clock.
	ClockSkew func() (time.Duration, bool)
	// ProcRoot is /proc (tests use a fake tree).
	ProcRoot string
	Now      func() time.Time
}

// Collector gathers host health snapshots.
type Collector struct{ o Options }

// New returns a Collector.
func New(o Options) *Collector {
	if o.ProcRoot == "" {
		o.ProcRoot = "/proc"
	}
	if o.Now == nil {
		o.Now = time.Now
	}
	return &Collector{o: o}
}

// Collect takes one snapshot.
func (c *Collector) Collect(ctx context.Context) *state.HostHealth {
	h := &state.HostHealth{CollectedAt: c.o.Now().UTC()}
	if c.o.DataDir != "" {
		if fs, ok := Filesystem(c.o.DataDir); ok {
			h.DataDisk = &fs
		}
	}
	h.Memory = c.memory()
	h.Load = c.load()
	h.Inotify = c.inotify()
	if d := c.o.Docker; d != nil {
		if root, err := d.RootDir(ctx); err == nil && root != "" && filepath.IsAbs(root) {
			// Visible when the daemon shares Docker's mount namespace (dind in dev,
			// or a deliberate mount); otherwise the data dir disk stands in.
			if fs, ok := Filesystem(root); ok {
				h.DockerDisk = &fs
			}
		}
		if total, managed, err := d.CountNetworks(ctx); err == nil {
			h.Networks = &state.NetworkUsage{Total: total, Managed: managed}
		}
		if _, u, err := d.Images(ctx); err == nil {
			h.Docker = &state.DockerUsage{ImagesMB: u.ImagesMB, ImagesReclaimableMB: u.ImagesReclaimableMB, ContainersMB: u.ContainersMB}
		}
	}
	if c.o.BuildCacheMB != nil {
		if mb, ok := c.o.BuildCacheMB(ctx); ok {
			h.BuildCacheMB = &mb
		}
	}
	if c.o.ClockSkew != nil {
		if d, ok := c.o.ClockSkew(); ok {
			ms := d.Milliseconds()
			h.ClockSkewMs = &ms
		}
	}
	return h
}

func (c *Collector) read(rel string) ([]byte, bool) {
	data, err := os.ReadFile(filepath.Join(c.o.ProcRoot, rel)) //nolint:gosec // fixed /proc paths
	if err != nil || len(data) > 1<<20 {
		return nil, false
	}
	return data, true
}

func (c *Collector) memory() *state.MemoryUsage {
	m, ok := c.memInfo()
	if !ok {
		return nil
	}
	return &state.MemoryUsage{TotalMB: m.TotalMB, AvailableMB: m.AvailableMB}
}

// MemInfo is the host's memory and swap from /proc/meminfo (MB).
type MemInfo struct {
	TotalMB, AvailableMB, SwapTotalMB, SwapFreeMB int64
}

// ReadMemInfo reads <procRoot>/meminfo (ok = MemTotal and MemAvailable
// were there). /proc/meminfo is the host's inside launchroomd's container.
func ReadMemInfo(procRoot string) (MemInfo, bool) {
	return (&Collector{o: Options{ProcRoot: procRoot}}).memInfo()
}

func (c *Collector) memInfo() (MemInfo, bool) {
	data, ok := c.read("meminfo")
	if !ok {
		return MemInfo{}, false
	}
	return ParseMemInfo(data)
}

// ParseMemInfo reads the content of /proc/meminfo (ok = MemTotal and
// MemAvailable were there).
func ParseMemInfo(data []byte) (MemInfo, bool) {
	m := MemInfo{TotalMB: -1, AvailableMB: -1}
	sc := bufio.NewScanner(bytes.NewReader(data))
	for sc.Scan() {
		f := strings.Fields(sc.Text())
		if len(f) < 2 {
			continue
		}
		v, err := strconv.ParseInt(f[1], 10, 64)
		if err != nil || v < 0 {
			continue
		}
		switch f[0] {
		case "MemTotal:":
			m.TotalMB = v >> 10
		case "MemAvailable:":
			m.AvailableMB = v >> 10
		case "SwapTotal:":
			m.SwapTotalMB = v >> 10
		case "SwapFree:":
			m.SwapFreeMB = v >> 10
		}
	}
	if m.TotalMB < 0 || m.AvailableMB < 0 {
		return MemInfo{}, false
	}
	m.AvailableMB = min(m.AvailableMB, m.TotalMB)
	m.SwapFreeMB = min(m.SwapFreeMB, m.SwapTotalMB)
	return m, true
}

// virtualInterfaces are prefixes of interfaces that are not the host's own
// network: loopback, Docker bridges and veths, the mesh (tailscale0),
// tunnels and other container/VM plumbing.
var virtualInterfaces = []string{
	"lo", "docker", "br-", "veth", "tailscale", "cni", "flannel", "virbr", "lr-", "tunl", "sit", "ip6tnl", "ip6gre",
	"gre", "erspan", "ip_vti", "ip6_vti", "nlmon", "dummy", "kube", "cali", "vxlan", "genev", "ifb",
}

// VirtualInterface reports whether name is container, VM or tunnel plumbing
// rather than a physical (or the VM's own) interface.
func VirtualInterface(name string) bool {
	for _, p := range virtualInterfaces {
		if strings.HasPrefix(name, p) {
			return true
		}
	}
	return false
}

// DockerDisk returns the filesystem Docker stores images and containers on:
// Docker's root dir when launchroomd can see it (it shares Docker's mount
// namespace in dev, or by a deliberate mount), else the data dir standing in.
func DockerDisk(host docker.HostOps, dataDir string) func(ctx context.Context) (state.FilesystemUsage, bool) {
	var root string
	return func(ctx context.Context) (state.FilesystemUsage, bool) {
		if root == "" && host != nil {
			if r, err := host.RootDir(ctx); err == nil && filepath.IsAbs(r) {
				root = r
			}
		}
		if root != "" {
			if fs, ok := Filesystem(root); ok {
				return fs, true
			}
		}
		return Filesystem(dataDir)
	}
}

func (c *Collector) load() *state.LoadAverage {
	data, ok := c.read("loadavg")
	if !ok {
		return nil
	}
	f := strings.Fields(string(data))
	if len(f) < 3 {
		return nil
	}
	var v [3]float64
	for i := range v {
		x, err := strconv.ParseFloat(f[i], 64)
		if err != nil || x < 0 || x > 1e6 {
			return nil
		}
		v[i] = x
	}
	return &state.LoadAverage{Load1: v[0], Load5: v[1], Load15: v[2]}
}

func (c *Collector) inotify() *state.InotifyLimits {
	var v [3]int64
	for i, name := range []string{"max_user_watches", "max_user_instances", "max_queued_events"} {
		data, ok := c.read(filepath.Join("sys", "fs", "inotify", name))
		if !ok {
			return nil
		}
		x, err := strconv.ParseInt(strings.TrimSpace(string(data)), 10, 64)
		if err != nil || x < 0 {
			return nil
		}
		v[i] = x
	}
	return &state.InotifyLimits{MaxUserWatches: v[0], MaxUserInstances: v[1], MaxQueuedEvents: v[2]}
}
