// Package dockerfake is an in-memory docker.Client for unit tests. It
// enforces the same ownership rules as the real Engine (managed labels,
// generated names) and records every mutating call.
package dockerfake

import (
	"context"
	"crypto/rand"
	"encoding/hex"
	"fmt"
	"maps"
	"net/netip"
	"slices"
	"strconv"
	"strings"
	"sync"
	"time"

	"github.com/moby/moby/api/types/mount"

	"github.com/vantezzen/launchroom/daemon/internal/docker"
)

// Fake is an in-memory Docker daemon.
type Fake struct {
	ServerID string

	mu         sync.Mutex
	containers map[string]*entry
	networks   map[string]*docker.Network
	volumes    map[string]*docker.Volume
	images     map[string]bool
	nextIP     map[string]netip.Addr
	calls      []string
	pid        int

	// PullErr makes PullImage fail for a reference.
	PullErr map[string]error
	// CreateErr makes CreateContainer fail for a container name.
	CreateErr map[string]error
	// FilesErr fails CreateContainer for this container name as if writing
	// its config files failed (the real engine removes the container then).
	FilesErr map[string]error
	// ExitOnStart makes a container exit immediately with this code when started.
	ExitOnStart map[string]int
	// Unbound names containers whose published ports Docker did not bind on
	// start (the mesh address was missing).
	Unbound map[string]bool
	// Logs are returned by StreamLogs per container id.
	Logs map[string][]docker.LogLine
	// Now is the clock (default time.Now).
	Now func() time.Time
	// OnPull runs at the start of every PullImage (without the lock), e.g. to
	// report progress through docker.ReportPullProgress.
	OnPull func(ctx context.Context, ref string)
	// OnCreate observes every successful CreateContainer (called with the lock held).
	OnCreate func(docker.ContainerCreate)
	// Stats are returned by ContainerStats per container id (missing: an error).
	Stats map[string]docker.ContainerStats
	// ImageLabels are an image's own labels, merged into the labels of
	// containers created from it (like Docker).
	ImageLabels map[string]map[string]string
}

type entry struct {
	c      docker.Container
	create docker.ContainerCreate
}

var _ docker.Client = (*Fake)(nil)

// New returns an empty fake daemon for serverID.
func New(serverID string) *Fake {
	return &Fake{
		ServerID:    serverID,
		containers:  map[string]*entry{},
		networks:    map[string]*docker.Network{},
		volumes:     map[string]*docker.Volume{},
		images:      map[string]bool{},
		nextIP:      map[string]netip.Addr{},
		PullErr:     map[string]error{},
		CreateErr:   map[string]error{},
		ExitOnStart: map[string]int{},
		Logs:        map[string][]docker.LogLine{},
		Stats:       map[string]docker.ContainerStats{},
		pid:         1000,
	}
}

func (f *Fake) now() time.Time {
	if f.Now != nil {
		return f.Now()
	}
	return time.Now()
}

func (f *Fake) record(format string, args ...any) {
	f.calls = append(f.calls, fmt.Sprintf(format, args...))
}

// Calls returns and clears the recorded mutating calls ("create lr-…", "start lr-…", …).
func (f *Fake) Calls() []string {
	f.mu.Lock()
	defer f.mu.Unlock()
	out := f.calls
	f.calls = nil
	return out
}

func newID() string {
	b := make([]byte, 32)
	_, _ = rand.Read(b)
	return hex.EncodeToString(b)
}

// AddImage marks an image as present locally.
func (f *Fake) AddImage(ref string) {
	f.mu.Lock()
	defer f.mu.Unlock()
	f.images[ref] = true
}

// AddUnmanagedContainer adds a container that the daemon must never touch.
func (f *Fake) AddUnmanagedContainer(name string, labels map[string]string) string {
	f.mu.Lock()
	defer f.mu.Unlock()
	id := newID()
	f.containers[id] = &entry{c: docker.Container{ID: id, Name: name, Labels: labels, State: "running", Running: true}}
	return id
}

// Create returns the create spec of a container by name, if it exists.
func (f *Fake) Create(name string) (docker.ContainerCreate, bool) {
	f.mu.Lock()
	defer f.mu.Unlock()
	for _, e := range f.containers {
		if e.c.Name == name {
			return e.create, true
		}
	}
	return docker.ContainerCreate{}, false
}

// SetState changes a container's observed state (simulating crashes).
func (f *Fake) SetState(id, state string, exitCode int) {
	f.mu.Lock()
	defer f.mu.Unlock()
	if e, ok := f.containers[id]; ok {
		e.c.State, e.c.Running, e.c.ExitCode = state, state == "running", exitCode
	}
}

// Update changes a container's observed state in place (restart counts,
// OOM kills, Docker's restarting state).
func (f *Fake) Update(id string, fn func(c *docker.Container)) {
	f.mu.Lock()
	defer f.mu.Unlock()
	if e, ok := f.containers[id]; ok {
		fn(&e.c)
	}
}

func (f *Fake) owned(labels map[string]string) bool { return docker.IsManaged(labels, f.ServerID) }

func (f *Fake) get(id string) (*entry, error) {
	e, ok := f.containers[id]
	if !ok {
		return nil, fmt.Errorf("container %s: %w", id, docker.ErrNotFound)
	}
	if !f.owned(e.c.Labels) {
		return nil, fmt.Errorf("container %s: %w", e.c.Name, docker.ErrNotManaged)
	}
	return e, nil
}

func clone(c docker.Container) docker.Container {
	c.Labels = maps.Clone(c.Labels)
	c.Networks = maps.Clone(c.Networks)
	c.Volumes = slices.Clone(c.Volumes)
	c.HostPorts = slices.Clone(c.HostPorts)
	return c
}

var _ docker.StatsOps = (*Fake)(nil)

// ContainerStats implements docker.StatsOps.
func (f *Fake) ContainerStats(_ context.Context, id string) (docker.ContainerStats, error) {
	f.mu.Lock()
	defer f.mu.Unlock()
	if _, err := f.get(id); err != nil {
		return docker.ContainerStats{}, err
	}
	s, ok := f.Stats[id]
	if !ok {
		return docker.ContainerStats{}, fmt.Errorf("container %s: no stats", id)
	}
	return s, nil
}

// SetStats sets the stats returned for a container.
func (f *Fake) SetStats(id string, s docker.ContainerStats) {
	f.mu.Lock()
	defer f.mu.Unlock()
	f.Stats[id] = s
}

// Info implements docker.Client.
func (f *Fake) Info(context.Context) (docker.EngineInfo, error) {
	return docker.EngineInfo{Version: "29.0.0-fake", CPUs: 4, MemoryMB: 8192}, nil
}

// ListContainers implements docker.Client.
func (f *Fake) ListContainers(_ context.Context, labels map[string]string) ([]docker.Container, error) {
	f.mu.Lock()
	defer f.mu.Unlock()
	var out []docker.Container
	for _, e := range f.containers {
		if !f.owned(e.c.Labels) {
			continue
		}
		match := true
		for k, v := range labels {
			if e.c.Labels[k] != v {
				match = false
			}
		}
		if match {
			out = append(out, clone(e.c))
		}
	}
	slices.SortFunc(out, func(a, b docker.Container) int { return strings.Compare(a.Name, b.Name) })
	return out, nil
}

// InspectContainer implements docker.Client.
func (f *Fake) InspectContainer(_ context.Context, id string) (docker.Container, error) {
	f.mu.Lock()
	defer f.mu.Unlock()
	e, err := f.get(id)
	if err != nil {
		return docker.Container{}, err
	}
	return clone(e.c), nil
}

// CreateContainer implements docker.Client.
func (f *Fake) CreateContainer(_ context.Context, cc *docker.ContainerCreate) (string, error) {
	f.mu.Lock()
	defer f.mu.Unlock()
	if !f.owned(cc.Config.Labels) {
		return "", docker.ErrNotManaged
	}
	if err := f.CreateErr[cc.Name]; err != nil {
		return "", err
	}
	for _, e := range f.containers {
		if e.c.Name == cc.Name {
			return "", fmt.Errorf("conflict: container name %s is in use", cc.Name)
		}
	}
	if err := f.FilesErr[cc.Name]; err != nil && len(cc.Files) > 0 {
		return "", err
	}
	if !f.images[cc.Config.Image] {
		return "", fmt.Errorf("no such image %s: %w", cc.Config.Image, docker.ErrNotFound)
	}
	// Like Docker: every network to attach must exist at create time.
	if cc.Networking != nil {
		for name := range cc.Networking.EndpointsConfig {
			if !f.hasNetwork(name) {
				return "", fmt.Errorf("network %s not found: %w", name, docker.ErrNotFound)
			}
		}
	}
	id := newID()
	labels := maps.Clone(f.ImageLabels[cc.Config.Image])
	if labels == nil {
		labels = map[string]string{}
	}
	maps.Copy(labels, cc.Config.Labels)
	c := docker.Container{ID: id, Name: cc.Name, Image: cc.Config.Image, Labels: labels, State: "created", Networks: map[string]netip.Addr{}, Created: f.now().UTC()}
	if cc.Config.StopTimeout != nil {
		c.StopTimeout = time.Duration(*cc.Config.StopTimeout) * time.Second
	}
	if cc.Networking != nil {
		for name := range cc.Networking.EndpointsConfig {
			c.Networks[name] = f.allocIP(name)
		}
	}
	if cc.HostConfig != nil {
		for _, m := range cc.HostConfig.Mounts {
			if m.Type == mount.TypeVolume {
				c.Volumes = append(c.Volumes, m.Source)
			}
		}
	}
	f.containers[id] = &entry{c: c, create: *cc}
	f.record("create %s", cc.Name)
	if f.OnCreate != nil {
		f.OnCreate(*cc)
	}
	return id, nil
}

func (f *Fake) hasNetwork(name string) bool {
	for _, n := range f.networks {
		if n.Name == name {
			return true
		}
	}
	return false
}

func (f *Fake) allocIP(network string) netip.Addr {
	for _, n := range f.networks {
		if n.Name != network || len(n.Subnets) == 0 {
			continue
		}
		ip, ok := f.nextIP[network]
		if !ok {
			ip = n.Subnets[0].Addr().Next() // .1 is the gateway
		}
		ip = ip.Next()
		f.nextIP[network] = ip
		return ip
	}
	return netip.Addr{}
}

// StartContainer implements docker.Client.
func (f *Fake) StartContainer(_ context.Context, id string) error {
	f.mu.Lock()
	defer f.mu.Unlock()
	e, err := f.get(id)
	if err != nil {
		return err
	}
	f.pid++
	e.c.State, e.c.Running, e.c.Pid, e.c.StartedAt, e.c.ExitCode = "running", true, f.pid, f.now().UTC(), 0
	e.c.HostPorts = nil
	if hc := e.create.HostConfig; hc != nil && !f.Unbound[e.c.Name] {
		for _, bs := range hc.PortBindings {
			for _, b := range bs {
				if p, err := strconv.ParseUint(b.HostPort, 10, 16); err == nil {
					e.c.HostPorts = append(e.c.HostPorts, netip.AddrPortFrom(b.HostIP, uint16(p)))
				}
			}
		}
	}
	if code, ok := f.ExitOnStart[e.c.Name]; ok {
		e.c.State, e.c.Running, e.c.Pid, e.c.ExitCode, e.c.FinishedAt = "exited", false, 0, code, f.now().UTC()
	}
	f.record("start %s", e.c.Name)
	return nil
}

// StopContainer implements docker.Client.
func (f *Fake) StopContainer(_ context.Context, id string, grace time.Duration) error {
	f.mu.Lock()
	defer f.mu.Unlock()
	e, err := f.get(id)
	if err != nil {
		return err
	}
	e.c.State, e.c.Running, e.c.Pid, e.c.FinishedAt, e.c.HostPorts = "exited", false, 0, f.now().UTC(), nil
	f.record("stop %s %s", e.c.Name, grace)
	return nil
}

// RemoveContainer implements docker.Client.
func (f *Fake) RemoveContainer(_ context.Context, id string) error {
	f.mu.Lock()
	defer f.mu.Unlock()
	e, err := f.get(id)
	if err != nil {
		return err
	}
	delete(f.containers, id)
	f.record("remove %s", e.c.Name)
	return nil
}

// StreamLogs implements docker.Client.
func (f *Fake) StreamLogs(ctx context.Context, id string, opts docker.LogsOptions, fn func(docker.LogLine) error) error {
	f.mu.Lock()
	if _, err := f.get(id); err != nil {
		f.mu.Unlock()
		return err
	}
	lines := slices.Clone(f.Logs[id])
	f.mu.Unlock()
	if opts.Tail >= 0 && len(lines) > opts.Tail {
		lines = lines[len(lines)-opts.Tail:]
	}
	for _, l := range lines {
		if !opts.Since.IsZero() && l.Time.Before(opts.Since) {
			continue
		}
		if err := ctx.Err(); err != nil {
			return nil
		}
		if err := fn(l); err != nil {
			return err
		}
	}
	if opts.Follow {
		<-ctx.Done()
	}
	return nil
}

// ListNetworks implements docker.Client.
func (f *Fake) ListNetworks(context.Context) ([]docker.Network, error) {
	f.mu.Lock()
	defer f.mu.Unlock()
	var out []docker.Network
	for _, n := range f.networks {
		if f.owned(n.Labels) {
			c := *n
			c.Labels = maps.Clone(n.Labels)
			out = append(out, c)
		}
	}
	slices.SortFunc(out, func(a, b docker.Network) int { return strings.Compare(a.Name, b.Name) })
	return out, nil
}

// AddUnmanagedNetwork adds a network that the daemon must never touch.
func (f *Fake) AddUnmanagedNetwork(name string) {
	f.mu.Lock()
	defer f.mu.Unlock()
	id := newID()
	f.networks[id] = &docker.Network{ID: id, Name: name, Labels: map[string]string{}}
}

// CreateNetwork implements docker.Client.
func (f *Fake) CreateNetwork(_ context.Context, n docker.NetworkCreate) (string, error) {
	f.mu.Lock()
	defer f.mu.Unlock()
	if !f.owned(n.Labels) {
		return "", docker.ErrNotManaged
	}
	for _, x := range f.networks {
		if x.Name == n.Name {
			return "", fmt.Errorf("conflict: network %s already exists", n.Name)
		}
	}
	id := newID()
	f.networks[id] = &docker.Network{ID: id, Name: n.Name, Labels: maps.Clone(n.Labels), Subnets: []netip.Prefix{n.Subnet}}
	f.record("create-network %s %s", n.Name, n.Subnet)
	return id, nil
}

// RemoveNetwork implements docker.Client.
func (f *Fake) RemoveNetwork(_ context.Context, id string) error {
	f.mu.Lock()
	defer f.mu.Unlock()
	n, ok := f.networks[id]
	if !ok {
		return docker.ErrNotFound
	}
	if !f.owned(n.Labels) {
		return docker.ErrNotManaged
	}
	for _, e := range f.containers {
		if _, attached := e.c.Networks[n.Name]; attached {
			return fmt.Errorf("network %s has active endpoints", n.Name)
		}
	}
	delete(f.networks, id)
	f.record("remove-network %s", n.Name)
	return nil
}

// ListVolumes implements docker.Client.
func (f *Fake) ListVolumes(context.Context) ([]docker.Volume, error) {
	f.mu.Lock()
	defer f.mu.Unlock()
	var out []docker.Volume
	for _, v := range f.volumes {
		if f.owned(v.Labels) {
			out = append(out, docker.Volume{Name: v.Name, Labels: maps.Clone(v.Labels)})
		}
	}
	slices.SortFunc(out, func(a, b docker.Volume) int { return strings.Compare(a.Name, b.Name) })
	return out, nil
}

// AddUnmanagedVolume adds a volume that the daemon must never adopt.
func (f *Fake) AddUnmanagedVolume(name string) {
	f.mu.Lock()
	defer f.mu.Unlock()
	f.volumes[name] = &docker.Volume{Name: name, Labels: map[string]string{}}
}

// CreateVolume implements docker.Client.
func (f *Fake) CreateVolume(_ context.Context, v docker.VolumeCreate) error {
	f.mu.Lock()
	defer f.mu.Unlock()
	if !f.owned(v.Labels) {
		return docker.ErrNotManaged
	}
	if x, ok := f.volumes[v.Name]; ok {
		if !f.owned(x.Labels) {
			return docker.ErrNotManaged
		}
		return nil
	}
	f.volumes[v.Name] = &docker.Volume{Name: v.Name, Labels: maps.Clone(v.Labels)}
	f.record("create-volume %s", v.Name)
	return nil
}

// RemoveVolume implements docker.Client (never forced: refuses volumes in use).
func (f *Fake) RemoveVolume(_ context.Context, name string) error {
	f.mu.Lock()
	defer f.mu.Unlock()
	v, ok := f.volumes[name]
	if !ok {
		return fmt.Errorf("volume %s: %w", name, docker.ErrNotFound)
	}
	if !f.owned(v.Labels) {
		return docker.ErrNotManaged
	}
	for _, e := range f.containers {
		if slices.Contains(e.c.Volumes, name) {
			return fmt.Errorf("conflict: volume %s is in use by %s", name, e.c.Name)
		}
	}
	delete(f.volumes, name)
	f.record("remove-volume %s", name)
	return nil
}

// AddVolume adds a volume with the given labels (e.g. a managed volume created by an earlier daemon run).
func (f *Fake) AddVolume(name string, labels map[string]string) {
	f.mu.Lock()
	defer f.mu.Unlock()
	f.volumes[name] = &docker.Volume{Name: name, Labels: maps.Clone(labels)}
}

// RemoveNetworkByName deletes a network behind the daemon's back (tests of races).
func (f *Fake) RemoveNetworkByName(name string) {
	f.mu.Lock()
	defer f.mu.Unlock()
	for id, n := range f.networks {
		if n.Name == name {
			delete(f.networks, id)
		}
	}
}

// ImagePresent implements docker.Client.
func (f *Fake) ImagePresent(_ context.Context, ref string) (bool, error) {
	f.mu.Lock()
	defer f.mu.Unlock()
	return f.images[ref], nil
}

// PullImage implements docker.Client.
func (f *Fake) PullImage(ctx context.Context, ref string) error {
	if f.OnPull != nil {
		f.OnPull(ctx, ref)
	}
	f.mu.Lock()
	defer f.mu.Unlock()
	f.record("pull %s", ref)
	if err := f.PullErr[ref]; err != nil {
		return err
	}
	f.images[ref] = true
	return nil
}
