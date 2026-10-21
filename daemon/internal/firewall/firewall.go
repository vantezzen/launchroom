package firewall

import (
	"context"
	"errors"
	"fmt"
	"log/slog"
	"net/netip"
	"strings"
	"sync"
	"time"

	"github.com/vantezzen/launchroom/daemon/internal/docker"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// Mode selects whether the daemon enforces isolation (local config only).
type Mode string

// Modes.
const (
	// ModeEnforce (default) applies the rules and refuses to start workloads
	// and builds while they cannot be applied (fail closed).
	ModeEnforce Mode = "enforce"
	// ModeOff installs nothing (hosts without nftables, e.g. Docker Desktop
	// build machines). Reported as "disabled".
	ModeOff Mode = "off"
)

// ErrNotApplied is returned by Sync while the isolation is not in place.
var ErrNotApplied = errors.New("network isolation (nftables) is not in place")

// Backend reads and writes the kernel ruleset.
type Backend interface {
	// Apply atomically replaces the table with rs.
	Apply(rs Ruleset) error
	// Check returns how the live table differs from rs (nil = identical).
	Check(rs Ruleset) ([]string, error)
	// Remove deletes the table.
	Remove() error
}

// Options configures a Manager.
type Options struct {
	Mode    Mode
	Backend Backend
	// Networks lists the managed Docker networks of this server.
	Networks func(ctx context.Context) ([]docker.Network, error)
	// BuildNetwork is the name of the build network (lr-system-build);
	// BuildGateway and RegistryPort the registry forwarder buildkitd uses.
	BuildNetwork string
	BuildGateway netip.Addr
	RegistryPort uint16
	// AllowPrivate are private destinations workloads may reach (LR_FIREWALL_ALLOW_PRIVATE).
	AllowPrivate []netip.Prefix
	// MeshInterface is the mesh client's interface ("" = no mesh rules).
	MeshInterface string
	// AmbassadorNetwork is the name of the ambassador's own network
	// (lr-system-ambassador); its bridge gets the ambassador chain.
	AmbassadorNetwork string
	// Mesh returns the current cross-server rules (mesh IP, meshAllow,
	// certificate storage peers, ambassador upstreams), already filtered by
	// the local policy floor. nil = none.
	Mesh func() MeshRules
	Log  *slog.Logger
	Now  func() time.Time
}

// MeshRules are the cross-server parts of the ruleset (docs/05 §3, §5).
type MeshRules struct {
	MeshIP          netip.Addr
	MeshAllow       []MeshAllow
	CertStoragePort uint16
	CertStorageFrom []netip.Addr
	// LinkProbePort and LinkProbeFrom: launchroomd answers link probes on
	// its mesh IP for these peers only (system.linkProbe, docs/05 §2.1).
	LinkProbePort       uint16
	LinkProbeFrom       []netip.Addr
	AmbassadorUpstreams []netip.AddrPort
}

// Manager keeps the `inet launchroom` table converged.
type Manager struct {
	o Options

	mu      sync.Mutex
	applied string // String() of the last ruleset this daemon applied
	status  state.FirewallStatus
}

// New returns a Manager.
func New(o Options) *Manager {
	if o.Mode == "" {
		o.Mode = ModeEnforce
	}
	if o.Backend == nil {
		o.Backend = NewBackend()
	}
	if o.Log == nil {
		o.Log = slog.New(slog.DiscardHandler)
	}
	if o.Now == nil {
		o.Now = time.Now
	}
	return &Manager{o: o}
}

// Spec derives the ruleset input from the managed networks.
func (m *Manager) Spec(nets []docker.Network) Spec {
	s := Spec{BuildGateway: m.o.BuildGateway, RegistryPort: m.o.RegistryPort, AllowPrivate: m.o.AllowPrivate, MeshInterface: m.o.MeshInterface}
	for _, n := range nets {
		if n.Bridge == "" {
			continue
		}
		if n.Name == m.o.BuildNetwork {
			s.BuildBridge = n.Bridge
			continue
		}
		if m.o.AmbassadorNetwork != "" && n.Name == m.o.AmbassadorNetwork {
			s.AmbassadorBridge = n.Bridge
			continue
		}
		s.Bridges = append(s.Bridges, n.Bridge)
	}
	if m.o.Mesh != nil {
		r := m.o.Mesh()
		s.MeshIP, s.MeshAllow, s.CertStoragePort, s.CertStorageFrom = r.MeshIP, r.MeshAllow, r.CertStoragePort, r.CertStorageFrom
		s.LinkProbePort, s.LinkProbeFrom = r.LinkProbePort, r.LinkProbeFrom
		if s.AmbassadorBridge != "" {
			s.AmbassadorUpstreams = r.AmbassadorUpstreams
		}
	}
	return s
}

// Sync makes sure the rules for the current networks are in place: it
// compares the live table with the expected ruleset and re-applies it on any
// difference (new networks, or drift: rules flushed or edited by something
// else). In enforce mode it returns ErrNotApplied (wrapped) when the rules
// could not be applied; callers must not start workloads or builds then.
func (m *Manager) Sync(ctx context.Context) error {
	m.mu.Lock()
	defer m.mu.Unlock()
	now := m.o.Now().UTC()
	if m.o.Mode == ModeOff {
		m.status = state.FirewallStatus{Mode: state.FirewallDisabled, CheckedAt: now}
		return nil
	}
	nets, err := m.o.Networks(ctx)
	if err != nil {
		return m.failed(now, fmt.Errorf("list networks: %w", err))
	}
	spec := m.Spec(nets)
	rs := Build(spec)
	text := rs.String()
	drift, err := m.o.Backend.Check(rs)
	if err != nil {
		return m.failed(now, fmt.Errorf("read nftables: %w", err))
	}
	repairs := m.status.Repairs
	if len(drift) > 0 {
		if m.applied == text {
			// We applied exactly this before: something else changed it.
			repairs++
			m.o.Log.Warn("firewall drift detected; re-applying", "drift", drift)
		}
		if err := m.o.Backend.Apply(rs); err != nil {
			return m.failed(now, err)
		}
		m.o.Log.Info("firewall rules applied", "networks", len(spec.Bridges), "buildNetwork", spec.BuildBridge != "")
		m.applied = text
	}
	m.status = state.FirewallStatus{Mode: state.FirewallEnforced, CheckedAt: now, Networks: len(spec.Bridges) + boolInt(spec.BuildBridge != ""), Repairs: repairs}
	return nil
}

func (m *Manager) failed(now time.Time, err error) error {
	msg := err.Error()
	if len(msg) > 1024 {
		msg = msg[:1024]
	}
	m.status = state.FirewallStatus{Mode: state.FirewallError, CheckedAt: now, Repairs: m.status.Repairs, Error: strings.ToValidUTF8(msg, "?")}
	m.applied = ""
	m.o.Log.Error("firewall rules could not be applied; workloads and builds are held back", "err", err)
	return fmt.Errorf("%w: %w", ErrNotApplied, err)
}

// Status returns the last result (nil before the first Sync).
func (m *Manager) Status() *state.FirewallStatus {
	m.mu.Lock()
	defer m.mu.Unlock()
	if m.status.Mode == "" {
		return nil
	}
	s := m.status
	return &s
}

// Remove deletes the daemon's table (uninstall).
func (m *Manager) Remove() error { return m.o.Backend.Remove() }

func boolInt(b bool) int {
	if b {
		return 1
	}
	return 0
}
