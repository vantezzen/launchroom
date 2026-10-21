// Package mesh runs the Tailscale client as one of launchroomd's system
// containers (docs/05 §2) and reports its state.
//
// Design:
//   - the stock tailscale/tailscale image (digest-pinned, checked against the
//     local policy floor), running tailscaled directly (not containerboot) in
//     kernel mode: host network, /dev/net/tun, NET_ADMIN + NET_RAW only,
//     no-new-privileges; its state lives in the named volume
//     lr-system-tailscale-state, so a restart keeps the node's identity;
//   - launchroomd logs the client in itself with `tailscale up --reset
//     --auth-key=file:/dev/stdin`: the pre-auth key (sealed to this daemon in
//     the NodeState) only ever travels over the exec's stdin, never argv, env
//     or a file. `--accept-dns=false` (mandatory: launchroom never touches
//     /etc/resolv.conf) and `--accept-routes=false` are always set;
//   - tags come from the pre-auth key with the embedded Headscale; with "bring
//     your own Tailscale" the NodeState names them and they are advertised;
//   - status (`tailscale status --json`) is read over an exec every pass and
//     turned into Report.host.mesh.
//
// The userspace fallback (tsnet embedded in launchroomd, for hosts without
// /dev/net/tun) is not implemented: without TUN the container fails to start
// and the mesh state is "error" with that reason.
package mesh

import (
	"bytes"
	"context"
	"crypto/sha256"
	"encoding/hex"
	"errors"
	"fmt"
	"io"
	"log/slog"
	"net"
	"net/netip"
	"net/url"
	"strings"
	"sync"
	"time"

	"github.com/moby/moby/api/types/container"
	"github.com/moby/moby/api/types/mount"

	"github.com/vantezzen/launchroom/daemon/internal/docker"
	"github.com/vantezzen/launchroom/daemon/internal/policy"
	"github.com/vantezzen/launchroom/daemon/internal/secret"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// Fixed host-side names (docs/05 §2).
const (
	ContainerName = "lr-system-tailscale"
	StateVolume   = "lr-system-tailscale-state"
	// Interface is the WireGuard interface tailscaled creates on the host.
	Interface = "tailscale0"

	socketPath  = "/var/run/tailscale/tailscaled.sock"
	stateDir    = "/var/lib/tailscale"
	specVersion = "tailscale-v1"

	statusTimeout  = 15 * time.Second
	loginTimeout   = 75 * time.Second
	retryAfter     = 30 * time.Second
	maxStatusBytes = 4 << 20
	// controlStuckAfter: a running client that has had no connection to its
	// coordination server for this long WHILE the server answers on TCP is
	// restarted (see Manager.recoverControl).
	controlStuckAfter = time.Minute
)

// Options configures a Manager.
type Options struct {
	ServerID state.ServerID
	Docker   docker.Client
	// Exec runs the tailscale CLI inside the container.
	Exec   docker.Execer
	Policy policy.Config
	// InsecureLoginHosts may be plain-http login servers (local config, dev only).
	InsecureLoginHosts []string
	// OpenAuthKey opens the sealed pre-auth key of the NodeState.
	OpenAuthKey func(state.Sealed) (secret.Secret, error)
	// Reachable reports whether the login server accepts TCP connections
	// (default: a 3 s dial from launchroomd, which shares the client's host
	// network).
	Reachable func(ctx context.Context, loginServer state.MeshLoginURL) bool
	Log       *slog.Logger
	Now       func() time.Time
}

// Manager converges the mesh client container and keeps its status.
type Manager struct {
	o Options

	mu        sync.Mutex
	status    *state.MeshStatus
	ipv4      netip.Addr
	lastLogin time.Time // last failed login attempt
	loginErr  string
	// stuckSince: since when the client has had no control connection while
	// the login server was reachable (zero = not stuck).
	stuckSince time.Time
}

// New returns a Manager.
func New(o Options) *Manager {
	if o.Log == nil {
		o.Log = slog.New(slog.DiscardHandler)
	}
	if o.Now == nil {
		o.Now = time.Now
	}
	if o.Reachable == nil {
		o.Reachable = dialLoginServer
	}
	return &Manager{o: o}
}

// Status is the latest mesh status (nil while the mesh is off).
func (m *Manager) Status() *state.MeshStatus {
	m.mu.Lock()
	defer m.mu.Unlock()
	if m.status == nil {
		return nil
	}
	c := *m.status
	return &c
}

// IPv4 is this server's mesh address (invalid until connected).
func (m *Manager) IPv4() netip.Addr {
	m.mu.Lock()
	defer m.mu.Unlock()
	return m.ipv4
}

func (m *Manager) set(st *state.MeshStatus) *state.MeshStatus {
	m.mu.Lock()
	defer m.mu.Unlock()
	m.status = st
	m.ipv4 = netip.Addr{}
	if st != nil && st.State == state.MeshConnected {
		if a, err := netip.ParseAddr(string(st.IPv4)); err == nil {
			m.ipv4 = a
		}
	}
	return st
}

func (m *Manager) fail(format string, args ...any) *state.MeshStatus {
	msg := truncate(fmt.Sprintf(format, args...))
	m.o.Log.Warn("mesh not converged", "reason", msg)
	return m.set(&state.MeshStatus{State: state.MeshError, Message: msg, CheckedAt: m.o.Now().UTC()})
}

// Sync converges the client to spec and returns the resulting status (nil
// when the mesh is disabled; the client container is then removed, its state
// volume kept).
func (m *Manager) Sync(ctx context.Context, spec state.TailscaleSpec) *state.MeshStatus {
	if !spec.Enabled {
		if err := m.remove(ctx); err != nil {
			m.o.Log.Warn("could not remove the disabled mesh client", "err", err)
		}
		return m.set(nil)
	}
	if m.o.Exec == nil {
		return m.fail("this Docker client cannot run the mesh client")
	}
	if err := policy.CheckSystemImage(policy.SystemTailscale, spec.Image, m.o.Policy); err != nil {
		return m.fail("%v", err)
	}
	if err := policy.CheckMeshLoginServer(spec.LoginServer, m.o.InsecureLoginHosts); err != nil {
		return m.fail("%v", err)
	}
	id, err := m.ensureContainer(ctx, spec.Image)
	if err != nil {
		return m.fail("mesh client container: %v", err)
	}
	raw, err := m.statusJSON(ctx, id)
	if err != nil {
		return m.set(&state.MeshStatus{State: state.MeshStarting, Message: "waiting for the mesh client: " + truncate(err.Error()), CheckedAt: m.o.Now().UTC()})
	}
	st, err := ParseStatus(raw, m.o.Now())
	if err != nil {
		return m.fail("mesh client status: %v", err)
	}
	login, reauth := needsLogin(st.backend, st.controlURL, spec.LoginServer)
	if !login {
		m.recoverControl(ctx, id, spec.LoginServer, st.controlLost)
		return m.set(st.report)
	}
	if res := m.login(ctx, id, spec, reauth); res != nil {
		return m.set(res)
	}
	if raw, err = m.statusJSON(ctx, id); err == nil {
		if st, err = ParseStatus(raw, m.o.Now()); err == nil {
			return m.set(st.report)
		}
	}
	return m.set(&state.MeshStatus{State: state.MeshStarting, Message: "logged in; waiting for the mesh address", CheckedAt: m.o.Now().UTC()})
}

// recoverControl restarts a client that stays disconnected from a reachable
// coordination server. After the server was down for a while, tailscaled
// may keep dialing it in a way that never succeeds again (it falls back to
// forcing port 443, which a plain-http dev Headscale doesn't serve), and
// then never learns peers' new endpoints: a peer that restarted stays
// unreachable over the mesh (found by the failover test). While the coordination
// server itself is down nothing is restarted: established peer paths keep
// working without it, and a restart would only cut them.
func (m *Manager) recoverControl(ctx context.Context, id string, loginServer state.MeshLoginURL, lost bool) {
	now := m.o.Now()
	if !lost || !m.o.Reachable(ctx, loginServer) {
		m.mu.Lock()
		m.stuckSince = time.Time{}
		m.mu.Unlock()
		return
	}
	m.mu.Lock()
	if m.stuckSince.IsZero() {
		m.stuckSince = now
	}
	stuck := now.Sub(m.stuckSince)
	if stuck < controlStuckAfter {
		m.mu.Unlock()
		return
	}
	m.stuckSince = time.Time{}
	m.mu.Unlock()
	m.o.Log.Warn("mesh client cannot reach the reachable coordination server; restarting it", "for", stuck.Round(time.Second).String())
	if err := m.o.Docker.StopContainer(ctx, id, 10*time.Second); err != nil {
		m.o.Log.Warn("could not restart the mesh client", "err", err)
		return
	}
	if err := m.o.Docker.StartContainer(ctx, id); err != nil {
		m.o.Log.Warn("could not restart the mesh client", "err", err)
	}
}

// dialLoginServer reports whether the login server's host accepts a TCP
// connection on its URL's port.
func dialLoginServer(ctx context.Context, loginServer state.MeshLoginURL) bool {
	u, err := url.Parse(string(loginServer))
	if err != nil || u.Hostname() == "" {
		return false
	}
	port := u.Port()
	if port == "" {
		port = "443"
		if u.Scheme == "http" {
			port = "80"
		}
	}
	d := net.Dialer{Timeout: 3 * time.Second}
	c, err := d.DialContext(ctx, "tcp", net.JoinHostPort(u.Hostname(), port))
	if err != nil {
		return false
	}
	_ = c.Close()
	return true
}

// needsLogin decides whether the client must (re)log in: it is not logged in,
// or it is logged in to another coordination server (switch between the
// embedded Headscale and "bring your own Tailscale"), which needs a forced
// re-authentication.
func needsLogin(backend, controlURL string, want state.MeshLoginURL) (login, reauth bool) {
	switch backend {
	case "NeedsLogin", "NoState", "Stopped":
		return true, controlURL != "" && !sameLoginServer(controlURL, want)
	case "Running", "Starting":
		if controlURL != "" && !sameLoginServer(controlURL, want) {
			return true, true
		}
	}
	return false, false
}

func sameLoginServer(a string, b state.MeshLoginURL) bool {
	return strings.TrimSuffix(strings.ToLower(a), "/") == strings.TrimSuffix(strings.ToLower(string(b)), "/")
}

// login runs `tailscale up` with the sealed pre-auth key on stdin. It returns
// a status to report when it could not log in (nil = logged in).
func (m *Manager) login(ctx context.Context, id string, spec state.TailscaleSpec, reauth bool) *state.MeshStatus {
	now := m.o.Now().UTC()
	if spec.AuthKey == nil {
		return &state.MeshStatus{State: state.MeshNeedsLogin, Message: "waiting for a mesh key from the dashboard", CheckedAt: now}
	}
	m.mu.Lock()
	last, lastErr := m.lastLogin, m.loginErr
	m.mu.Unlock()
	if !last.IsZero() && now.Sub(last) < retryAfter {
		return &state.MeshStatus{State: state.MeshError, Message: truncate("login failed, retrying shortly: " + lastErr), CheckedAt: now}
	}
	key, err := m.o.OpenAuthKey(*spec.AuthKey)
	if err != nil {
		return &state.MeshStatus{State: state.MeshError, Message: truncate("mesh key cannot be opened: " + err.Error()), CheckedAt: now}
	}
	argv := UpArgs(spec, Hostname(m.o.ServerID), reauth)
	ctx, cancel := context.WithTimeout(ctx, loginTimeout)
	defer cancel()
	m.o.Log.Info("logging in to the mesh", "loginServer", spec.LoginServer, "reauth", reauth)
	res, err := m.o.Exec.ExecStream(ctx, id, docker.ExecOptions{Argv: argv, Stdin: strings.NewReader(key.Reveal())})
	if err == nil && res.ExitCode == 0 {
		m.mu.Lock()
		m.lastLogin, m.loginErr = time.Time{}, ""
		m.mu.Unlock()
		m.o.Log.Info("mesh login succeeded")
		return nil
	}
	msg := ""
	if err != nil {
		msg = err.Error()
	} else {
		msg = fmt.Sprintf("tailscale up exited with %d: %s", res.ExitCode, strings.TrimSpace(res.Stderr))
	}
	// The CLI never echoes the key, but never risk reporting it.
	msg = truncate(strings.ReplaceAll(msg, key.Reveal(), "[redacted]"))
	m.mu.Lock()
	m.lastLogin, m.loginErr = now, msg
	m.mu.Unlock()
	m.o.Log.Warn("mesh login failed", "err", msg)
	// tailscaled remembers failed control dials for a while (it then forces
	// port 443); a fresh process starts the next attempt clean.
	if err := m.o.Docker.StopContainer(context.WithoutCancel(ctx), id, 10*time.Second); err == nil {
		_ = m.o.Docker.StartContainer(context.WithoutCancel(ctx), id)
	}
	return &state.MeshStatus{State: state.MeshError, Message: truncate("login failed: " + msg), CheckedAt: now}
}

// UpArgs is the `tailscale up` argv. Every non-default setting is named
// (--reset), so the result does not depend on earlier runs.
func UpArgs(spec state.TailscaleSpec, hostname string, reauth bool) []string {
	argv := []string{
		"tailscale", "--socket=" + socketPath, "up", "--reset",
		"--login-server=" + string(spec.LoginServer),
		"--hostname=" + hostname,
		// Mandatory: never let the client rewrite /etc/resolv.conf (docs/05 §2).
		"--accept-dns=false",
		"--accept-routes=false",
		"--auth-key=file:/dev/stdin",
		"--timeout=60s",
	}
	if len(spec.Tags) > 0 {
		tags := make([]string, len(spec.Tags))
		for i, t := range spec.Tags {
			tags[i] = string(t)
		}
		argv = append(argv, "--advertise-tags="+strings.Join(tags, ","))
	}
	if reauth {
		argv = append(argv, "--force-reauth")
	}
	return argv
}

// Hostname is the mesh hostname of a server: "lr-" + its id in lowercase,
// with "_" as "-" (e.g. lr-srv-01j9z8x7w6v5t4s3r2q1p0n9mk).
func Hostname(id state.ServerID) string {
	return "lr-" + strings.ReplaceAll(strings.ToLower(string(id)), "_", "-")
}

type limitedBuffer struct {
	bytes.Buffer
	max int
}

func (b *limitedBuffer) Write(p []byte) (int, error) {
	if b.Len()+len(p) > b.max {
		return 0, errors.New("mesh: status output too large")
	}
	return b.Buffer.Write(p)
}

// statusJSON returns `tailscale status --json` plus the control URL from
// `tailscale debug prefs`, merged as {"status": …, "prefs": …}.
func (m *Manager) statusJSON(ctx context.Context, id string) ([]byte, error) {
	ctx, cancel := context.WithTimeout(ctx, statusTimeout)
	defer cancel()
	run := func(args ...string) ([]byte, error) {
		out := &limitedBuffer{max: maxStatusBytes}
		res, err := m.o.Exec.ExecStream(ctx, id, docker.ExecOptions{Argv: append([]string{"tailscale", "--socket=" + socketPath}, args...), Stdout: out})
		if err != nil {
			return nil, err
		}
		if res.ExitCode != 0 {
			return nil, fmt.Errorf("tailscale %s exited with %d: %s", args[0], res.ExitCode, strings.TrimSpace(res.Stderr))
		}
		return out.Bytes(), nil
	}
	status, err := run("status", "--json")
	if err != nil {
		return nil, err
	}
	prefs, err := run("debug", "prefs")
	if err != nil {
		return nil, err
	}
	var b bytes.Buffer
	b.WriteString(`{"status":`)
	b.Write(bytes.TrimSpace(status))
	b.WriteString(`,"prefs":`)
	b.Write(bytes.TrimSpace(prefs))
	b.WriteString("}")
	return b.Bytes(), nil
}

// SpecHash identifies the built-in container spec for an image.
func SpecHash(image state.ImageRef) string {
	sum := sha256.Sum256([]byte(specVersion + "|" + string(image)))
	return hex.EncodeToString(sum[:16])
}

// ContainerSpec is the built-in spec of the mesh client (docs/05 §2).
func ContainerSpec(serverID state.ServerID, image state.ImageRef) *docker.ContainerCreate {
	labels := docker.ManagedLabels(string(serverID))
	labels[docker.LabelSystem] = policy.SystemTailscale
	labels[docker.LabelSpecHash] = SpecHash(image)
	memory := int64(512 << 20)
	pids := int64(1024)
	return &docker.ContainerCreate{
		Name: ContainerName,
		Config: &container.Config{
			Image:      string(image),
			Entrypoint: []string{"tailscaled"},
			Cmd: []string{
				"--state=" + stateDir + "/tailscaled.state",
				"--statedir=" + stateDir,
				"--socket=" + socketPath,
				"--tun=" + Interface,
			},
			Labels: labels,
		},
		HostConfig: &container.HostConfig{
			// Kernel mode: the WireGuard interface lives in the host's network
			// namespace, where launchroomd binds the mesh IP and Caddy reaches
			// mesh-published replicas.
			NetworkMode:   "host",
			RestartPolicy: container.RestartPolicy{Name: container.RestartPolicyUnlessStopped},
			CapDrop:       []string{"ALL"},
			CapAdd:        []string{"NET_ADMIN", "NET_RAW"},
			SecurityOpt:   []string{policy.NoNewPrivileges},
			Resources: container.Resources{
				Memory: memory, MemorySwap: memory, PidsLimit: &pids,
				Devices: []container.DeviceMapping{{PathOnHost: "/dev/net/tun", PathInContainer: "/dev/net/tun", CgroupPermissions: "rwm"}},
			},
			LogConfig: container.LogConfig{
				Type:   policy.LogDriver,
				Config: map[string]string{"max-size": policy.LogMaxSize, "max-file": policy.LogMaxFile},
			},
			Mounts: []mount.Mount{{Type: mount.TypeVolume, Source: StateVolume, Target: stateDir}},
		},
	}
}

func (m *Manager) list(ctx context.Context) ([]docker.Container, error) {
	return m.o.Docker.ListContainers(ctx, map[string]string{docker.LabelSystem: policy.SystemTailscale})
}

func (m *Manager) remove(ctx context.Context) error {
	cs, err := m.list(ctx)
	if err != nil {
		return err
	}
	var errs []error
	for _, c := range cs {
		if c.Running {
			errs = append(errs, m.o.Docker.StopContainer(ctx, c.ID, 10*time.Second))
		}
		errs = append(errs, m.o.Docker.RemoveContainer(ctx, c.ID))
	}
	return errors.Join(errs...)
}

func (m *Manager) ensureContainer(ctx context.Context, image state.ImageRef) (string, error) {
	hash := SpecHash(image)
	cs, err := m.list(ctx)
	if err != nil {
		return "", err
	}
	var current *docker.Container
	for i := range cs {
		c := cs[i]
		if current == nil && c.Name == ContainerName && c.Labels[docker.LabelSpecHash] == hash {
			current = &cs[i]
			continue
		}
		m.o.Log.Info("replacing system mesh client container", "container", c.Name)
		if c.Running {
			_ = m.o.Docker.StopContainer(ctx, c.ID, 10*time.Second)
		}
		if err := m.o.Docker.RemoveContainer(ctx, c.ID); err != nil {
			return "", err
		}
	}
	if current == nil {
		labels := docker.ManagedLabels(string(m.o.ServerID))
		labels[docker.LabelVolume] = "system-" + StateVolume
		labels[docker.LabelSystem] = policy.SystemTailscale
		if err := m.o.Docker.CreateVolume(ctx, docker.VolumeCreate{Name: StateVolume, Labels: labels}); err != nil {
			return "", err
		}
		present, err := m.o.Docker.ImagePresent(ctx, string(image))
		if err != nil {
			return "", err
		}
		if !present {
			m.o.Log.Info("pulling system mesh client image", "image", image)
			if err := m.o.Docker.PullImage(ctx, string(image)); err != nil {
				return "", err
			}
		}
		id, err := m.o.Docker.CreateContainer(ctx, ContainerSpec(m.o.ServerID, image))
		if err != nil {
			return "", err
		}
		c, err := m.o.Docker.InspectContainer(ctx, id)
		if err != nil {
			return "", err
		}
		current = &c
	}
	if !current.Running {
		if err := m.o.Docker.StartContainer(ctx, current.ID); err != nil {
			return "", err
		}
	}
	return current.ID, nil
}

func truncate(s string) string {
	if len(s) > 1024 {
		return s[:1024]
	}
	return s
}

var _ io.Writer = (*limitedBuffer)(nil)
