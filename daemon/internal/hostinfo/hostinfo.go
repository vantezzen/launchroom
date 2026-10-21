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
	// DataDir is the daemon data dir.
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

// memory parses MemTotal and MemAvailable (kB) from /proc/meminfo.
func (c *Collector) memory() *state.MemoryUsage {
	data, ok := c.read("meminfo")
	if !ok {
		return nil
	}
	var total, avail int64 = -1, -1
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
			total = v >> 10
		case "MemAvailable:":
			avail = v >> 10
		}
	}
	if total < 0 || avail < 0 {
		return nil
	}
	return &state.MemoryUsage{TotalMB: total, AvailableMB: min(avail, total)}
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
