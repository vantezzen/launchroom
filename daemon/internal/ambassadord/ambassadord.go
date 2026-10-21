// Package ambassadord runs the ambassador (docs/05 §3) as launchroomd's
// system container lr-system-ambassador and keeps its listeners in step with
// the NodeState's ambassadors.
//
// Design:
//   - Image: built locally from launchroomd's own launchroom-ambassador
//     binary (a static Go binary next to launchroomd) with the Engine's
//     image import, tagged lr-system-ambassador:<16 hex of its sha256>. No
//     registry, no pull and nothing the dashboard chooses: the image is as
//     trusted as launchroomd itself.
//   - Network: its own bridge lr-system-ambassador (LR_AMBASSADOR_SUBNET,
//     default 10.232.1.0/24) provides the default gateway (GwPriority), so
//     every connection to an upstream leaves through that bridge, where the
//     nftables chain `ambassador` allows exactly the listed upstreams
//     (mesh-published ports) and nothing else. Environment networks stay
//     blocked from the mesh range as before.
//   - Listeners: the container joins every environment network with
//     ambassadors, with the remote services' slugs as network aliases, so
//     `postgres` resolves to it there (Docker DNS). Each listener binds the
//     ambassador's address on that network only, and accepts clients only
//     from that network's subnet: one ambassador serves many environments
//     without mixing them.
//   - Config: a JSON file in the volume lr-system-ambassador-config, copied
//     in through the Engine archive API; the forwarder re-reads it every
//     second. Changing listeners or upstreams never restarts the container.
//   - Hardening: non-root (65534), cap-drop ALL, no-new-privileges,
//     read-only root filesystem, memory/pids limits, json-file rotation.
package ambassadord

import (
	"archive/tar"
	"bytes"
	"context"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"log/slog"
	"maps"
	"net/netip"
	"os"
	"slices"
	"strings"
	"sync"
	"time"

	"github.com/moby/moby/api/types/container"
	"github.com/moby/moby/api/types/mount"
	"github.com/moby/moby/api/types/network"

	"github.com/vantezzen/launchroom/daemon/internal/ambassador"
	"github.com/vantezzen/launchroom/daemon/internal/docker"
	"github.com/vantezzen/launchroom/daemon/internal/policy"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// Fixed host-side names.
const (
	ContainerName = "lr-system-ambassador"
	NetworkName   = "lr-system-ambassador"
	ConfigVolume  = "lr-system-ambassador-config"
	ImageRepo     = "lr-system-ambassador"
	// BinaryName is the forwarder binary shipped next to launchroomd.
	BinaryName = "launchroom-ambassador"

	configDir  = "/config"
	configFile = "ambassador.json"
	user       = "65534:65534"
	specVer    = "ambassador-v1"
	// gwPriority makes the ambassador's own network the default gateway.
	gwPriority = 1000
	memoryMB   = 256
	pids       = 1024
)

// DefaultSubnet is the ambassador network (docs/05 §7).
var DefaultSubnet = netip.MustParsePrefix("10.232.1.0/24")

// Options configures a Manager.
type Options struct {
	ServerID string
	Docker   docker.Client
	Ops      docker.AmbassadorOps
	// Binary is the launchroom-ambassador executable to build the image from.
	Binary string
	// Subnet of the ambassador network.
	Subnet netip.Prefix
	// DrainSeconds is passed to the forwarder (0 = its default).
	DrainSeconds int
	// Isolate applies the firewall (which covers the ambassador network and
	// allows its upstreams) before the container is (re)started; nil = none.
	Isolate func(ctx context.Context) error
	Log     *slog.Logger
}

// Manager converges the ambassador container.
type Manager struct {
	o Options

	mu        sync.Mutex
	binHash   string
	binStamp  string
	written   []byte // the config last copied into the current container
	writtenTo string // its container id
	refused   []string
}

// New returns a Manager.
func New(o Options) *Manager {
	if !o.Subnet.IsValid() {
		o.Subnet = DefaultSubnet
	}
	if o.Log == nil {
		o.Log = slog.New(slog.DiscardHandler)
	}
	return &Manager{o: o}
}

// Status is the outcome of one Sync (for logs and tests).
type Status struct {
	Running   bool
	Listeners []ambassador.Listener
}

// Sync converges the container to ns.Ambassadors (after the policy floor).
// Without ambassadors the container and its network are removed (the
// config volume is kept). Environment networks that do not exist yet are
// skipped until the next pass.
func (m *Manager) Sync(ctx context.Context, ns *state.NodeState) (Status, error) {
	m.mu.Lock()
	defer m.mu.Unlock()
	desired, refused := policy.FilterAmbassadors(ns)
	if !slices.Equal(refused, m.refused) {
		if len(refused) > 0 {
			m.o.Log.Warn("local policy refused ambassador listeners or upstreams", "refused", refused)
		}
		m.refused = refused
	}
	if len(desired) == 0 {
		return Status{}, m.remove(ctx)
	}
	if m.o.Ops == nil {
		return Status{}, errors.New("ambassador: the Docker client cannot run system containers")
	}
	image, err := m.ensureImage(ctx)
	if err != nil {
		return Status{}, err
	}
	if err := m.ensureNetwork(ctx); err != nil {
		return Status{}, err
	}
	if m.o.Isolate != nil {
		// Fail closed: the ambassador only runs behind its firewall chain.
		if err := m.o.Isolate(ctx); err != nil {
			return Status{}, fmt.Errorf("ambassador: not started: %w", err)
		}
	}
	c, err := m.ensureContainer(ctx, image)
	if err != nil {
		return Status{}, err
	}
	if c, err = m.attach(ctx, c, desired); err != nil {
		return Status{}, err
	}
	cfg := Config(c, desired, m.o.DrainSeconds)
	if err := m.writeConfig(ctx, c.ID, cfg); err != nil {
		return Status{}, err
	}
	return Status{Running: true, Listeners: cfg.Listeners}, nil
}

// Detach disconnects the ambassador from an environment network that is
// about to be removed (the reconciler removes networks before the next
// Sync would leave them). No-op when it is not attached.
func (m *Manager) Detach(ctx context.Context, network string) error {
	if network == NetworkName || m.o.Ops == nil {
		return nil
	}
	m.mu.Lock()
	defer m.mu.Unlock()
	cs, err := m.list(ctx)
	if err != nil {
		return err
	}
	for _, c := range cs {
		if _, ok := c.Networks[network]; !ok {
			continue
		}
		m.o.Log.Info("ambassador leaves network", "network", network, "reason", "network removed")
		if err := m.o.Ops.DisconnectNetwork(ctx, network, c.ID); err != nil && !errors.Is(err, docker.ErrNotFound) {
			return err
		}
		m.written, m.writtenTo = nil, "" // its listeners there are gone; rewrite the config
	}
	return nil
}

// Config builds the forwarder config: one listener per ambassador on the
// container's address in that network (networks it is not attached to yet
// are left out).
func Config(c docker.Container, desired []policy.Ambassador, drainSeconds int) ambassador.Config {
	cfg := ambassador.Config{Listeners: []ambassador.Listener{}, DrainSeconds: drainSeconds}
	for _, a := range desired {
		ip, ok := c.Networks[string(a.Network.Name)]
		if !ok || !ip.IsValid() {
			continue
		}
		ups := make([]string, len(a.Upstreams))
		for i, u := range a.Upstreams {
			ups[i] = u.String()
		}
		cfg.Listeners = append(cfg.Listeners, ambassador.Listener{
			Listen:    netip.AddrPortFrom(ip, uint16(a.Port)).String(), //nolint:gosec // validated 1-65535
			Clients:   string(a.Network.Subnet),
			Upstreams: ups,
			Balance:   ambassador.LeastConn,
		})
	}
	return cfg
}

func (m *Manager) list(ctx context.Context) ([]docker.Container, error) {
	return m.o.Docker.ListContainers(ctx, map[string]string{docker.LabelSystem: policy.SystemAmbassador})
}

func (m *Manager) remove(ctx context.Context) error {
	cs, err := m.list(ctx)
	if err != nil {
		return err
	}
	var errs []error
	for _, c := range cs {
		m.o.Log.Info("removing the ambassador (no ambassadors left)", "container", c.Name)
		if c.Running {
			errs = append(errs, m.o.Docker.StopContainer(ctx, c.ID, 10*time.Second))
		}
		errs = append(errs, m.o.Docker.RemoveContainer(ctx, c.ID))
	}
	m.written, m.writtenTo = nil, ""
	if err := errors.Join(errs...); err != nil {
		return err
	}
	nets, err := m.o.Docker.ListNetworks(ctx)
	if err != nil {
		return err
	}
	for _, n := range nets {
		if n.Name == NetworkName && n.Labels[docker.LabelSystem] == policy.SystemAmbassador {
			if err := m.o.Docker.RemoveNetwork(ctx, n.ID); err != nil {
				return err
			}
		}
	}
	return nil
}

// binaryHash hashes the forwarder binary (cached by size and mtime).
func (m *Manager) binaryHash() (string, error) {
	fi, err := os.Stat(m.o.Binary)
	if err != nil {
		return "", fmt.Errorf("ambassador: binary %s: %w", m.o.Binary, err)
	}
	stamp := fmt.Sprintf("%d|%d", fi.Size(), fi.ModTime().UnixNano())
	if stamp == m.binStamp && m.binHash != "" {
		return m.binHash, nil
	}
	f, err := os.Open(m.o.Binary)
	if err != nil {
		return "", err
	}
	defer func() { _ = f.Close() }()
	h := sha256.New()
	if _, err := io.Copy(h, f); err != nil {
		return "", err
	}
	m.binStamp, m.binHash = stamp, hex.EncodeToString(h.Sum(nil))
	return m.binHash, nil
}

// ImageRef is the local image tag for a binary hash.
func ImageRef(hash string) string { return ImageRepo + ":" + hash[:16] }

func (m *Manager) ensureImage(ctx context.Context) (string, error) {
	hash, err := m.binaryHash()
	if err != nil {
		return "", err
	}
	ref := ImageRef(hash)
	ok, err := m.o.Docker.ImagePresent(ctx, ref)
	if err != nil || ok {
		return ref, err
	}
	bin, err := os.ReadFile(m.o.Binary)
	if err != nil {
		return "", err
	}
	if sum := sha256.Sum256(bin); hex.EncodeToString(sum[:]) != hash {
		return "", errors.New("ambassador: binary changed while building the image; retrying on the next pass")
	}
	m.o.Log.Info("building the ambassador image from launchroomd's binary", "image", ref)
	err = m.o.Ops.ImportImage(ctx, ref, rootfs(bin), []string{
		`ENTRYPOINT ["/` + BinaryName + `"]`,
		`CMD ["--config", "` + configDir + "/" + configFile + `"]`,
		"USER " + user,
		"LABEL " + docker.LabelManaged + "=true",
	})
	return ref, err
}

// rootfs is a tar with the forwarder binary at /launchroom-ambassador and
// an empty /config mount point owned by the forwarder's user.
func rootfs(bin []byte) io.Reader {
	var buf bytes.Buffer
	tw := tar.NewWriter(&buf)
	t := time.Unix(0, 0)
	_ = tw.WriteHeader(&tar.Header{Typeflag: tar.TypeDir, Name: strings.TrimPrefix(configDir, "/") + "/", Mode: 0o755, Uid: 65534, Gid: 65534, ModTime: t})
	_ = tw.WriteHeader(&tar.Header{Typeflag: tar.TypeReg, Name: BinaryName, Mode: 0o555, Size: int64(len(bin)), ModTime: t})
	_, _ = tw.Write(bin)
	_ = tw.Close()
	return &buf
}

func (m *Manager) ensureNetwork(ctx context.Context) error {
	nets, err := m.o.Docker.ListNetworks(ctx)
	if err != nil {
		return err
	}
	for _, n := range nets {
		if n.Name != NetworkName {
			continue
		}
		if len(n.Subnets) == 1 && n.Subnets[0] == m.o.Subnet {
			return nil
		}
		return fmt.Errorf("ambassador: network %s exists with subnet %v, want %s (remove it or change LR_AMBASSADOR_SUBNET)", NetworkName, n.Subnets, m.o.Subnet)
	}
	labels := docker.ManagedLabels(m.o.ServerID)
	labels[docker.LabelSystem] = policy.SystemAmbassador
	labels[docker.LabelNetwork] = "system-ambassador"
	_, err = m.o.Docker.CreateNetwork(ctx, docker.NetworkCreate{Name: NetworkName, Subnet: m.o.Subnet, Labels: labels})
	return err
}

// SpecHash identifies the container spec for an image.
func (m *Manager) SpecHash(image string) string {
	sum := sha256.Sum256([]byte(fmt.Sprintf("%s|%s|%s", specVer, image, m.o.Subnet)))
	return hex.EncodeToString(sum[:16])
}

// ContainerSpec is the built-in container spec.
func (m *Manager) ContainerSpec(image string) *docker.ContainerCreate {
	labels := docker.ManagedLabels(m.o.ServerID)
	labels[docker.LabelSystem] = policy.SystemAmbassador
	labels[docker.LabelSpecHash] = m.SpecHash(image)
	pidLimit := int64(pids)
	mem := int64(memoryMB) << 20
	init := true
	return &docker.ContainerCreate{
		Name: ContainerName,
		Config: &container.Config{
			Image:  image,
			User:   user,
			Labels: labels,
		},
		HostConfig: &container.HostConfig{
			NetworkMode:    NetworkName,
			RestartPolicy:  container.RestartPolicy{Name: container.RestartPolicyUnlessStopped},
			CapDrop:        []string{"ALL"},
			SecurityOpt:    []string{policy.NoNewPrivileges},
			ReadonlyRootfs: true,
			Init:           &init,
			// Listeners on service ports below 1024 without capabilities
			// (Docker's default for bridge networks, made explicit).
			Sysctls: map[string]string{"net.ipv4.ip_unprivileged_port_start": "0"},
			LogConfig: container.LogConfig{
				Type:   policy.LogDriver,
				Config: map[string]string{"max-size": policy.LogMaxSize, "max-file": policy.LogMaxFile},
			},
			Mounts:    []mount.Mount{{Type: mount.TypeVolume, Source: ConfigVolume, Target: configDir}},
			Resources: container.Resources{Memory: mem, MemorySwap: mem, NanoCPUs: 1e9, PidsLimit: &pidLimit},
		},
		Networking: &network.NetworkingConfig{EndpointsConfig: map[string]*network.EndpointSettings{
			NetworkName: {GwPriority: gwPriority},
		}},
	}
}

func (m *Manager) ensureContainer(ctx context.Context, image string) (docker.Container, error) {
	hash := m.SpecHash(image)
	cs, err := m.list(ctx)
	if err != nil {
		return docker.Container{}, err
	}
	var current *docker.Container
	for i := range cs {
		c := cs[i]
		if current == nil && c.Name == ContainerName && c.Labels[docker.LabelSpecHash] == hash {
			current = &cs[i]
			continue
		}
		m.o.Log.Info("replacing the ambassador container", "container", c.Name)
		if c.Running {
			_ = m.o.Docker.StopContainer(ctx, c.ID, 10*time.Second)
		}
		if err := m.o.Docker.RemoveContainer(ctx, c.ID); err != nil {
			return docker.Container{}, err
		}
	}
	if current == nil {
		labels := docker.ManagedLabels(m.o.ServerID)
		labels[docker.LabelVolume] = "system-" + ConfigVolume
		labels[docker.LabelSystem] = policy.SystemAmbassador
		if err := m.o.Docker.CreateVolume(ctx, docker.VolumeCreate{Name: ConfigVolume, Labels: labels}); err != nil {
			return docker.Container{}, err
		}
		id, err := m.o.Docker.CreateContainer(ctx, m.ContainerSpec(image))
		if err != nil {
			return docker.Container{}, err
		}
		c, err := m.o.Docker.InspectContainer(ctx, id)
		if err != nil {
			return docker.Container{}, err
		}
		current = &c
		m.written, m.writtenTo = nil, ""
	}
	if !current.Running {
		if err := m.o.Docker.StartContainer(ctx, current.ID); err != nil {
			return docker.Container{}, err
		}
	}
	return *current, nil
}

// attach connects the container to the environment networks with
// ambassadors (aliases = the remote services' slugs) and disconnects it from
// the others. An alias change reconnects that network only.
func (m *Manager) attach(ctx context.Context, c docker.Container, desired []policy.Ambassador) (docker.Container, error) {
	want := map[string][]string{}
	for _, a := range desired {
		want[string(a.Network.Name)] = append(want[string(a.Network.Name)], string(a.Alias))
	}
	for name, aliases := range want {
		slices.Sort(aliases)
		want[name] = slices.Compact(aliases)
	}
	have, err := m.o.Docker.ListNetworks(ctx)
	if err != nil {
		return c, err
	}
	exists := map[string]bool{}
	for _, n := range have {
		exists[n.Name] = true
	}
	changed := false
	var errs []error
	for name := range c.Networks {
		if name == NetworkName {
			continue
		}
		aliases, ok := want[name]
		got := slices.Sorted(slices.Values(c.Aliases[name]))
		if ok && slices.Equal(got, aliases) {
			continue
		}
		m.o.Log.Info("ambassador leaves network", "network", name, "reason", map[bool]string{true: "aliases changed", false: "no ambassadors"}[ok])
		if err := m.o.Ops.DisconnectNetwork(ctx, name, c.ID); err != nil && !errors.Is(err, docker.ErrNotFound) {
			errs = append(errs, err)
			continue
		}
		delete(c.Networks, name)
		changed = true
	}
	for _, name := range slices.Sorted(maps.Keys(want)) {
		if _, ok := c.Networks[name]; ok {
			continue
		}
		if !exists[name] {
			continue // created by the reconciler with the first workload; next pass
		}
		m.o.Log.Info("ambassador joins network", "network", name, "aliases", want[name])
		if err := m.o.Ops.ConnectNetwork(ctx, name, c.ID, want[name]); err != nil {
			errs = append(errs, err)
			continue
		}
		changed = true
	}
	if changed {
		fresh, err := m.o.Docker.InspectContainer(ctx, c.ID)
		if err != nil {
			return c, err
		}
		c = fresh
	}
	return c, errors.Join(errs...)
}

func (m *Manager) writeConfig(ctx context.Context, id string, cfg ambassador.Config) error {
	data, err := json.MarshalIndent(cfg, "", "  ")
	if err != nil {
		return err
	}
	data = append(data, '\n')
	if bytes.Equal(data, m.written) && m.writtenTo == id {
		return nil
	}
	var buf bytes.Buffer
	tw := tar.NewWriter(&buf)
	_ = tw.WriteHeader(&tar.Header{Typeflag: tar.TypeReg, Name: configFile, Mode: 0o444, Size: int64(len(data)), Uid: 65534, Gid: 65534, ModTime: time.Now()})
	_, _ = tw.Write(data)
	_ = tw.Close()
	if err := m.o.Ops.CopyToContainer(ctx, id, configDir, &buf); err != nil {
		return fmt.Errorf("ambassador: write config: %w", err)
	}
	m.written, m.writtenTo = data, id
	m.o.Log.Info("ambassador config updated", "listeners", len(cfg.Listeners))
	return nil
}
