package docker

import (
	"context"
	"errors"
	"fmt"
	"io"
	"maps"
	"net/netip"
	"slices"
	"strconv"
	"strings"
	"time"

	cerrdefs "github.com/containerd/errdefs"
	"github.com/moby/moby/api/types/container"
	"github.com/moby/moby/api/types/mount"
	"github.com/moby/moby/api/types/network"
	"github.com/moby/moby/client"

	"github.com/vantezzen/launchroom/daemon/internal/guard/guardapi"
)

// RegistryAuth returns the base64 X-Registry-Auth value for pulling ref, or
// "" for anonymous pulls. The daemon will obtain short-lived pull tokens from
// the dashboard here (docs/05 §6); for now the hook is a stub.
type RegistryAuth func(ctx context.Context, ref string) (string, error)

// EngineOptions configures an Engine.
type EngineOptions struct {
	// ServerID scopes every operation to objects labelled for this server.
	ServerID string
	// Host overrides DOCKER_HOST (e.g. "unix:///var/run/docker.sock"). Empty: from the environment.
	Host string
	// CallTimeout bounds every API call except pulls and log streams (default 30 s).
	CallTimeout time.Duration
	// PullTimeout bounds an image pull including the mirror warm-up
	// (default DefaultPullTimeout).
	PullTimeout time.Duration
	// PullIdleTimeout fails a pull when Docker reports no progress for this
	// long (default DefaultPullIdleTimeout).
	PullIdleTimeout time.Duration
	// Warm prepares the launchroom registry's mirror before a pull (nil: none).
	Warm MirrorWarm
	// Auth supplies registry credentials per pull (default: anonymous).
	Auth RegistryAuth
	// UserAgent identifies the daemon to the daemon.
	UserAgent string
}

// Engine implements Client over the Docker Engine API.
type Engine struct {
	api         *client.Client
	serverID    string
	callTimeout time.Duration
	pullTimeout time.Duration
	pullIdle    time.Duration
	warm        MirrorWarm
	auth        RegistryAuth
}

var _ Client = (*Engine)(nil)

// NewEngine returns a client for the local Docker daemon. It does not connect yet.
func NewEngine(opts EngineOptions) (*Engine, error) {
	if opts.ServerID == "" {
		return nil, errors.New("docker: server id is required")
	}
	ops := []client.Opt{client.FromEnv}
	if opts.Host != "" {
		ops = append(ops, client.WithHost(opts.Host))
	}
	if opts.UserAgent != "" {
		ops = append(ops, client.WithUserAgent(opts.UserAgent))
	}
	api, err := client.New(ops...)
	if err != nil {
		return nil, fmt.Errorf("docker: %w", err)
	}
	e := &Engine{
		api: api, serverID: opts.ServerID, auth: opts.Auth,
		callTimeout: opts.CallTimeout, pullTimeout: opts.PullTimeout, pullIdle: opts.PullIdleTimeout, warm: opts.Warm,
	}
	if e.callTimeout <= 0 {
		e.callTimeout = 30 * time.Second
	}
	if e.pullTimeout <= 0 {
		e.pullTimeout = DefaultPullTimeout
	}
	if e.pullIdle <= 0 {
		e.pullIdle = DefaultPullIdleTimeout
	}
	if e.auth == nil {
		e.auth = func(context.Context, string) (string, error) { return "", nil }
	}
	return e, nil
}

// Close releases idle connections.
func (e *Engine) Close() error { return e.api.Close() }

func (e *Engine) call(ctx context.Context) (context.Context, context.CancelFunc) {
	return context.WithTimeout(ctx, e.callTimeout)
}

func (e *Engine) managedFilter(extra map[string]string) client.Filters {
	f := client.Filters{}
	f.Add("label", LabelManaged+"=true", LabelServer+"="+e.serverID)
	for k, v := range extra {
		f.Add("label", k+"="+v)
	}
	return f
}

func wrap(op string, err error) error {
	if err == nil {
		return nil
	}
	if cerrdefs.IsNotFound(err) {
		return fmt.Errorf("docker: %s: %w: %w", op, ErrNotFound, err)
	}
	// dockerd-guard (D49) refuses with 403 and a prefixed message.
	if cerrdefs.IsPermissionDenied(err) && strings.Contains(err.Error(), guardapi.RefusalPrefix) {
		if strings.Contains(err.Error(), guardapi.NotManagedMarker) {
			return fmt.Errorf("docker: %s: %w: %w", op, ErrNotManaged, err)
		}
		return fmt.Errorf("docker: %s: %w: %w", op, ErrGuardRefused, err)
	}
	return fmt.Errorf("docker: %s: %w", op, err)
}

// Info implements Client.
func (e *Engine) Info(ctx context.Context) (EngineInfo, error) {
	ctx, cancel := e.call(ctx)
	defer cancel()
	res, err := e.api.Info(ctx, client.InfoOptions{})
	if err != nil {
		return EngineInfo{}, wrap("info", err)
	}
	return EngineInfo{
		Version: res.Info.ServerVersion, CPUs: res.Info.NCPU, MemoryMB: int(res.Info.MemTotal >> 20),
		OperatingSystem: res.Info.OperatingSystem, KernelVersion: res.Info.KernelVersion,
	}, nil
}

// ListContainers implements Client.
func (e *Engine) ListContainers(ctx context.Context, labels map[string]string) ([]Container, error) {
	lctx, cancel := e.call(ctx)
	res, err := e.api.ContainerList(lctx, client.ContainerListOptions{All: true, Filters: e.managedFilter(labels)})
	cancel()
	if err != nil {
		return nil, wrap("list containers", err)
	}
	out := make([]Container, 0, len(res.Items))
	for _, s := range res.Items {
		c, err := e.InspectContainer(ctx, s.ID)
		if errors.Is(err, ErrNotFound) {
			continue // removed in between
		}
		if err != nil {
			return nil, err
		}
		out = append(out, c)
	}
	return out, nil
}

// InspectContainer implements Client.
func (e *Engine) InspectContainer(ctx context.Context, id string) (Container, error) {
	ctx, cancel := e.call(ctx)
	defer cancel()
	res, err := e.api.ContainerInspect(ctx, id, client.ContainerInspectOptions{})
	if err != nil {
		return Container{}, wrap("inspect container", err)
	}
	c := fromInspect(res.Container)
	if !IsManaged(c.Labels, e.serverID) {
		return Container{}, fmt.Errorf("%w: container %s", ErrNotManaged, shortID(id))
	}
	return c, nil
}

func fromInspect(r container.InspectResponse) Container {
	c := Container{ID: r.ID, Name: strings.TrimPrefix(r.Name, "/"), RestartCount: r.RestartCount, Networks: map[string]netip.Addr{}, Created: parseDockerTime(r.Created)}
	if r.Config != nil {
		c.Image = r.Config.Image
		c.Labels = maps.Clone(r.Config.Labels)
		if r.Config.StopTimeout != nil {
			c.StopTimeout = time.Duration(max(0, *r.Config.StopTimeout)) * time.Second
		}
	}
	if s := r.State; s != nil {
		c.State = string(s.Status)
		c.Running = s.Running
		c.Restarting = s.Restarting
		c.OOMKilled = s.OOMKilled
		c.Pid = s.Pid
		c.ExitCode = s.ExitCode
		c.StartedAt = parseDockerTime(s.StartedAt)
		c.FinishedAt = parseDockerTime(s.FinishedAt)
	}
	for _, m := range r.Mounts {
		if m.Type == mount.TypeVolume && m.Name != "" {
			c.Volumes = append(c.Volumes, m.Name)
		}
	}
	if r.NetworkSettings != nil {
		for _, bs := range r.NetworkSettings.Ports {
			for _, b := range bs {
				if port, err := strconv.ParseUint(b.HostPort, 10, 16); err == nil && b.HostIP.IsValid() {
					c.HostPorts = append(c.HostPorts, netip.AddrPortFrom(b.HostIP.Unmap(), uint16(port)))
				}
			}
		}
		slices.SortFunc(c.HostPorts, netip.AddrPort.Compare)
		for name, ep := range r.NetworkSettings.Networks {
			if ep != nil {
				c.Networks[name] = ep.IPAddress
				if len(ep.Aliases) > 0 {
					if c.Aliases == nil {
						c.Aliases = map[string][]string{}
					}
					c.Aliases[name] = slices.Clone(ep.Aliases)
				}
			}
		}
	}
	return c
}

func parseDockerTime(s string) time.Time {
	t, err := time.Parse(time.RFC3339Nano, s)
	if err != nil || t.Year() <= 1 {
		return time.Time{}
	}
	return t.UTC()
}

func shortID(id string) string {
	if len(id) > 12 {
		return id[:12]
	}
	return id
}

// mustOwnContainer refuses to act on containers this daemon does not manage.
func (e *Engine) mustOwnContainer(ctx context.Context, id string) error {
	_, err := e.InspectContainer(ctx, id)
	return err
}

// CreateContainer implements Client.
func (e *Engine) CreateContainer(ctx context.Context, cc *ContainerCreate) (string, error) {
	if cc == nil || cc.Config == nil || cc.HostConfig == nil {
		return "", errors.New("docker: incomplete container spec")
	}
	if !IsManaged(cc.Config.Labels, e.serverID) {
		return "", fmt.Errorf("%w: refusing to create an unlabelled container", ErrNotManaged)
	}
	if !strings.HasPrefix(cc.Name, "lr-") {
		return "", fmt.Errorf("docker: container name %q must be generated (lr-…)", cc.Name)
	}
	ctx, cancel := e.call(ctx)
	defer cancel()
	res, err := e.api.ContainerCreate(ctx, client.ContainerCreateOptions{
		Name: cc.Name, Config: cc.Config, HostConfig: cc.HostConfig, NetworkingConfig: cc.Networking,
	})
	if err != nil {
		return "", wrap("create container "+cc.Name, err)
	}
	return res.ID, nil
}

// StartContainer implements Client.
func (e *Engine) StartContainer(ctx context.Context, id string) error {
	if err := e.mustOwnContainer(ctx, id); err != nil {
		return err
	}
	ctx, cancel := e.call(ctx)
	defer cancel()
	_, err := e.api.ContainerStart(ctx, id, client.ContainerStartOptions{})
	return wrap("start container", err)
}

// StopContainer implements Client.
func (e *Engine) StopContainer(ctx context.Context, id string, grace time.Duration) error {
	if err := e.mustOwnContainer(ctx, id); err != nil {
		return err
	}
	secs := max(0, int(grace.Round(time.Second)/time.Second))
	ctx, cancel := context.WithTimeout(ctx, e.callTimeout+time.Duration(secs)*time.Second)
	defer cancel()
	_, err := e.api.ContainerStop(ctx, id, client.ContainerStopOptions{Timeout: &secs})
	return wrap("stop container", err)
}

// RemoveContainer implements Client.
func (e *Engine) RemoveContainer(ctx context.Context, id string) error {
	if err := e.mustOwnContainer(ctx, id); err != nil {
		return err
	}
	ctx, cancel := e.call(ctx)
	defer cancel()
	// RemoveVolumes stays false: named volumes are never deleted implicitly.
	_, err := e.api.ContainerRemove(ctx, id, client.ContainerRemoveOptions{Force: true})
	return wrap("remove container", err)
}

// StreamLogs implements Client.
func (e *Engine) StreamLogs(ctx context.Context, id string, opts LogsOptions, fn func(LogLine) error) error {
	if err := e.mustOwnContainer(ctx, id); err != nil {
		return err
	}
	lo := client.ContainerLogsOptions{ShowStdout: true, ShowStderr: true, Timestamps: true, Follow: opts.Follow, Tail: "all"}
	if opts.Tail >= 0 {
		lo.Tail = strconv.Itoa(opts.Tail)
	}
	if !opts.Since.IsZero() {
		lo.Since = strconv.FormatInt(opts.Since.Unix(), 10)
	}
	rc, err := e.api.ContainerLogs(ctx, id, lo)
	if err != nil {
		return wrap("container logs", err)
	}
	defer func() { _ = rc.Close() }()
	return Demux(rc, fn)
}

// ListNetworks implements Client.
func (e *Engine) ListNetworks(ctx context.Context) ([]Network, error) {
	ctx, cancel := e.call(ctx)
	defer cancel()
	res, err := e.api.NetworkList(ctx, client.NetworkListOptions{Filters: e.managedFilter(nil)})
	if err != nil {
		return nil, wrap("list networks", err)
	}
	out := make([]Network, 0, len(res.Items))
	for _, n := range res.Items {
		if !IsManaged(n.Labels, e.serverID) {
			continue
		}
		nw := Network{ID: n.ID, Name: n.Name, Labels: maps.Clone(n.Labels), Bridge: BridgeName(n.Driver, n.ID, n.Options)}
		for _, c := range n.IPAM.Config {
			if c.Subnet.IsValid() {
				nw.Subnets = append(nw.Subnets, c.Subnet)
			}
		}
		out = append(out, nw)
	}
	return out, nil
}

// BridgeName is the host interface of a bridge network: the
// com.docker.network.bridge.name option, else Docker's default "br-" + the
// first 12 characters of the network id ("" for other drivers).
func BridgeName(driver, id string, options map[string]string) string {
	if driver != "bridge" {
		return ""
	}
	if name := options["com.docker.network.bridge.name"]; name != "" {
		return name
	}
	if len(id) < 12 {
		return ""
	}
	return "br-" + id[:12]
}

// CreateNetwork implements Client.
func (e *Engine) CreateNetwork(ctx context.Context, n NetworkCreate) (string, error) {
	if !IsManaged(n.Labels, e.serverID) {
		return "", fmt.Errorf("%w: refusing to create an unlabelled network", ErrNotManaged)
	}
	if !strings.HasPrefix(n.Name, "lr-") || !n.Subnet.IsValid() {
		return "", fmt.Errorf("docker: network %q needs a generated name and a subnet", n.Name)
	}
	ctx, cancel := e.call(ctx)
	defer cancel()
	ipv6 := false
	res, err := e.api.NetworkCreate(ctx, n.Name, client.NetworkCreateOptions{
		Driver:     "bridge",
		EnableIPv6: &ipv6,
		IPAM:       &network.IPAM{Driver: "default", Config: []network.IPAMConfig{{Subnet: n.Subnet}}},
		Labels:     maps.Clone(n.Labels),
	})
	if err != nil {
		return "", wrap("create network "+n.Name, err)
	}
	return res.ID, nil
}

// RemoveNetwork implements Client.
func (e *Engine) RemoveNetwork(ctx context.Context, id string) error {
	ctx, cancel := e.call(ctx)
	defer cancel()
	res, err := e.api.NetworkInspect(ctx, id, client.NetworkInspectOptions{})
	if err != nil {
		return wrap("inspect network", err)
	}
	if !IsManaged(res.Network.Labels, e.serverID) {
		return fmt.Errorf("%w: network %s", ErrNotManaged, res.Network.Name)
	}
	_, err = e.api.NetworkRemove(ctx, id, client.NetworkRemoveOptions{})
	return wrap("remove network", err)
}

// ListVolumes implements Client.
func (e *Engine) ListVolumes(ctx context.Context) ([]Volume, error) {
	ctx, cancel := e.call(ctx)
	defer cancel()
	res, err := e.api.VolumeList(ctx, client.VolumeListOptions{Filters: e.managedFilter(nil)})
	if err != nil {
		return nil, wrap("list volumes", err)
	}
	out := make([]Volume, 0, len(res.Items))
	for _, v := range res.Items {
		if IsManaged(v.Labels, e.serverID) {
			out = append(out, Volume{Name: v.Name, Labels: maps.Clone(v.Labels)})
		}
	}
	return out, nil
}

// CreateVolume implements Client. Docker's volume create is idempotent and
// returns an existing volume of the same name regardless of its labels, so
// the result is checked: an unmanaged volume must never be adopted.
func (e *Engine) CreateVolume(ctx context.Context, v VolumeCreate) error {
	if !IsManaged(v.Labels, e.serverID) {
		return fmt.Errorf("%w: refusing to create an unlabelled volume", ErrNotManaged)
	}
	if !strings.HasPrefix(v.Name, "lr-") {
		return fmt.Errorf("docker: volume name %q must be generated (lr-…)", v.Name)
	}
	ctx, cancel := e.call(ctx)
	defer cancel()
	res, err := e.api.VolumeCreate(ctx, client.VolumeCreateOptions{Name: v.Name, Driver: "local", Labels: maps.Clone(v.Labels)})
	if err != nil {
		return wrap("create volume "+v.Name, err)
	}
	if IsManaged(res.Volume.Labels, e.serverID) {
		return nil
	}
	// For an existing volume Docker answers from its in-memory volume cache,
	// which after a dockerd restart may hold the bare driver volume without
	// its labels (moby's store: "there could be some inconsistent details such
	// as labels here"). The stored labels are authoritative: inspect them
	// before refusing, or our own system volumes (lr-system-ambassador-config)
	// are refused after every restart of the server until something inspects
	// them (dev mesh outage, 2026-10-19).
	in, err := e.api.VolumeInspect(ctx, v.Name, client.VolumeInspectOptions{})
	if err != nil {
		return wrap("inspect volume "+v.Name, err)
	}
	if !IsManaged(in.Volume.Labels, e.serverID) {
		return fmt.Errorf("%w: volume %s already exists", ErrNotManaged, v.Name)
	}
	return nil
}

// RemoveVolume implements Client. It inspects the volume first and refuses
// unmanaged ones; the removal is never forced, so Docker refuses a volume
// that any container (managed or not, running or stopped) still uses.
func (e *Engine) RemoveVolume(ctx context.Context, name string) error {
	if !strings.HasPrefix(name, "lr-") {
		return fmt.Errorf("docker: volume name %q must be generated (lr-…)", name)
	}
	ctx, cancel := e.call(ctx)
	defer cancel()
	res, err := e.api.VolumeInspect(ctx, name, client.VolumeInspectOptions{})
	if err != nil {
		return wrap("inspect volume "+name, err)
	}
	if !IsManaged(res.Volume.Labels, e.serverID) {
		return fmt.Errorf("%w: volume %s", ErrNotManaged, name)
	}
	_, err = e.api.VolumeRemove(ctx, name, client.VolumeRemoveOptions{Force: false})
	return wrap("remove volume "+name, err)
}

// ImagePresent implements Client.
func (e *Engine) ImagePresent(ctx context.Context, ref string) (bool, error) {
	ctx, cancel := e.call(ctx)
	defer cancel()
	_, err := e.api.ImageInspect(ctx, ref)
	switch {
	case err == nil:
		return true, nil
	case cerrdefs.IsNotFound(err):
		return false, nil
	default:
		return false, wrap("inspect image", err)
	}
}

// maxFrame bounds one multiplexed log frame (Docker writes ≤ 32 KiB frames).
const maxFrame = 1 << 20

// Demux reads Docker's multiplexed log stream (8-byte header: stream type,
// 3 zero bytes, big-endian uint32 length) with timestamps enabled and calls
// fn per complete line. Lines longer than 64 KiB are split.
func Demux(r io.Reader, fn func(LogLine) error) error {
	var hdr [8]byte
	buf := map[byte][]byte{}
	flush := func(stream byte, final bool) error {
		b := buf[stream]
		for {
			i := indexNL(b)
			if i < 0 {
				if final && len(b) > 0 || len(b) > 64<<10 {
					if err := emit(stream, b, fn); err != nil {
						return err
					}
					b = nil
				}
				buf[stream] = b
				return nil
			}
			if err := emit(stream, b[:i], fn); err != nil {
				return err
			}
			b = b[i+1:]
		}
	}
	for {
		if _, err := io.ReadFull(r, hdr[:]); err != nil {
			for _, s := range []byte{1, 2} {
				if ferr := flush(s, true); ferr != nil {
					return ferr
				}
			}
			if errors.Is(err, io.EOF) || errors.Is(err, io.ErrUnexpectedEOF) || errors.Is(err, context.Canceled) {
				return nil
			}
			return fmt.Errorf("docker: read logs: %w", err)
		}
		stream := hdr[0]
		size := int(hdr[4])<<24 | int(hdr[5])<<16 | int(hdr[6])<<8 | int(hdr[7])
		if (stream != 1 && stream != 2) || size > maxFrame {
			return fmt.Errorf("docker: malformed log stream (stream %d, frame %d bytes)", stream, size)
		}
		payload := make([]byte, size)
		if _, err := io.ReadFull(r, payload); err != nil {
			return fmt.Errorf("docker: read logs: %w", err)
		}
		buf[stream] = append(buf[stream], payload...)
		if err := flush(stream, false); err != nil {
			return err
		}
	}
}

func indexNL(b []byte) int {
	for i, c := range b {
		if c == '\n' {
			return i
		}
	}
	return -1
}

func emit(stream byte, line []byte, fn func(LogLine) error) error {
	l := LogLine{Stream: "stdout"}
	if stream == 2 {
		l.Stream = "stderr"
	}
	text := string(line)
	if ts, rest, ok := strings.Cut(text, " "); ok {
		if t, err := time.Parse(time.RFC3339Nano, ts); err == nil {
			l.Time, text = t.UTC(), rest
		}
	}
	l.Text = strings.TrimSuffix(text, "\r")
	return fn(l)
}
