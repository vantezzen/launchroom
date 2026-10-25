package access

import (
	"context"
	"crypto/rand"
	"encoding/json"
	"errors"
	"fmt"
	"log/slog"
	"math/big"
	"net"
	"net/netip"
	"os"
	"path/filepath"
	"slices"
	"strconv"
	"strings"
	"sync"
	"time"

	"github.com/vantezzen/launchroom/daemon/internal/atomicfile"
	"github.com/vantezzen/launchroom/daemon/internal/firewall"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// DefaultPorts is LR_REMOTE_ACCESS_PORTS' default.
const DefaultPorts = "40100-40199"

// PortRange is the local range access ports are picked from.
type PortRange struct{ Lo, Hi uint16 }

// ParsePorts parses "lo-hi" (1024 ≤ lo ≤ hi, at most 1000 ports).
func ParsePorts(s string) (PortRange, error) {
	lo, hi, ok := strings.Cut(strings.TrimSpace(s), "-")
	a, err1 := strconv.ParseUint(strings.TrimSpace(lo), 10, 16)
	b, err2 := strconv.ParseUint(strings.TrimSpace(hi), 10, 16)
	if !ok || err1 != nil || err2 != nil || a < 1024 || b < a || b-a >= 1000 {
		return PortRange{}, fmt.Errorf("remote access ports must be \"lo-hi\" between 1024 and 65535 with at most 1000 ports, got %q", s)
	}
	return PortRange{Lo: uint16(a), Hi: uint16(b)}, nil
}

// ManagerOptions configures the Manager.
type ManagerOptions struct {
	Ports PortRange
	// DataDir holds the role ledger (data/access/roles.json).
	DataDir string
	// SyncFirewall re-renders the firewall with the current Rules (nil: none).
	SyncFirewall func(ctx context.Context) error
	// FirewallEnforced: when true a failed firewall sync refuses the access.
	FirewallEnforced func() bool
	// PublicIPv4 is the detected public IPv4 (invalid = unknown).
	PublicIPv4 func() netip.Addr
	// Listen binds a port (tests).
	Listen func(ctx context.Context, port uint16) (net.Listener, error)
	Log    *slog.Logger
}

// Manager is launchroomd's set of running accesses: their firewall rules,
// their ports and the ledger of temporary database roles.
type Manager struct {
	o ManagerOptions

	mu     sync.Mutex
	active map[state.AccessID]firewall.AccessRule
	// claimed are accesses that own a volume helper (from before its
	// listener opens until the helper is removed).
	claimed map[state.AccessID]int

	ledgerMu sync.Mutex
}

// NewManager returns a Manager.
func NewManager(o ManagerOptions) *Manager {
	if o.Ports.Lo == 0 {
		o.Ports, _ = ParsePorts(DefaultPorts)
	}
	if o.Log == nil {
		o.Log = slog.New(slog.DiscardHandler)
	}
	if o.Listen == nil {
		o.Listen = func(ctx context.Context, port uint16) (net.Listener, error) {
			var lc net.ListenConfig
			return lc.Listen(ctx, "tcp", ":"+strconv.Itoa(int(port)))
		}
	}
	return &Manager{o: o, active: map[state.AccessID]firewall.AccessRule{}, claimed: map[state.AccessID]int{}}
}

// Claim marks an access as owning a volume helper until release is called,
// so the helper sweep leaves its helper alone (release is idempotent).
func (m *Manager) Claim(id state.AccessID) (release func()) {
	m.mu.Lock()
	m.claimed[id]++
	m.mu.Unlock()
	var once sync.Once
	return func() {
		once.Do(func() {
			m.mu.Lock()
			defer m.mu.Unlock()
			if m.claimed[id]--; m.claimed[id] <= 0 {
				delete(m.claimed, id)
			}
		})
	}
}

// Running reports whether an access runs here (listening or owning a helper).
func (m *Manager) Running(id state.AccessID) bool {
	m.mu.Lock()
	defer m.mu.Unlock()
	_, open := m.active[id]
	return open || m.claimed[id] > 0
}

// Rules returns the firewall rules of the running accesses.
func (m *Manager) Rules() []firewall.AccessRule {
	m.mu.Lock()
	defer m.mu.Unlock()
	out := make([]firewall.AccessRule, 0, len(m.active))
	for _, r := range m.active {
		out = append(out, firewall.AccessRule{Port: r.Port, From: slices.Clone(r.From)})
	}
	slices.SortFunc(out, func(a, b firewall.AccessRule) int { return int(a.Port) - int(b.Port) })
	return out
}

func (m *Manager) inUse(port uint16) bool {
	for _, r := range m.active {
		if r.Port == port {
			return true
		}
	}
	return false
}

// Open binds a random free port of the range, registers its firewall rule
// and syncs the firewall. close removes the rule, syncs and closes the
// listener; it is safe to call more than once.
func (m *Manager) Open(ctx context.Context, id state.AccessID, allow []netip.Prefix) (net.Listener, func(), error) {
	span := int64(m.o.Ports.Hi-m.o.Ports.Lo) + 1
	var l net.Listener
	var port uint16
	for range 32 {
		n, err := rand.Int(rand.Reader, big.NewInt(span))
		if err != nil {
			return nil, nil, err
		}
		p := m.o.Ports.Lo + uint16(n.Int64()) //nolint:gosec // < span ≤ 1000
		m.mu.Lock()
		busy := m.inUse(p)
		m.mu.Unlock()
		if busy {
			continue
		}
		if l, err = m.o.Listen(ctx, p); err == nil {
			port = p
			break
		}
	}
	if l == nil {
		return nil, nil, fmt.Errorf("no free port in %d-%d", m.o.Ports.Lo, m.o.Ports.Hi)
	}
	m.mu.Lock()
	m.active[id] = firewall.AccessRule{Port: port, From: slices.Clone(allow)}
	m.mu.Unlock()
	var once sync.Once
	closeFn := func() {
		once.Do(func() {
			_ = l.Close()
			m.mu.Lock()
			delete(m.active, id)
			m.mu.Unlock()
			if m.o.SyncFirewall != nil {
				sctx, cancel := context.WithTimeout(context.WithoutCancel(ctx), 30*time.Second)
				defer cancel()
				if err := m.o.SyncFirewall(sctx); err != nil {
					m.o.Log.Warn("firewall sync after an access ended failed; the next pass retries", "err", err)
				}
			}
		})
	}
	if m.o.SyncFirewall != nil {
		if err := m.o.SyncFirewall(ctx); err != nil && m.o.FirewallEnforced != nil && m.o.FirewallEnforced() {
			closeFn()
			return nil, nil, fmt.Errorf("the firewall rules for the port could not be applied: %w", err)
		}
	}
	return l, closeFn, nil
}

// Network describes how the server is reached (privateAddresses, behindNat).
func (m *Manager) Network() ([]state.IPAddr, bool) {
	var priv []state.IPAddr
	local := map[netip.Addr]bool{}
	ifaces, _ := net.Interfaces()
	for _, ifc := range ifaces {
		if ifc.Flags&net.FlagUp == 0 || ifc.Flags&net.FlagLoopback != 0 || skipInterface(ifc.Name) {
			continue
		}
		addrs, _ := ifc.Addrs()
		for _, a := range addrs {
			p, err := netip.ParsePrefix(a.String())
			if err != nil {
				continue
			}
			ip := p.Addr().Unmap()
			local[ip] = true
			if ip.IsPrivate() && len(priv) < 4 && !firewallMesh(ip) {
				priv = append(priv, state.IPAddr(ip.String()))
			}
		}
	}
	nat := false
	if m.o.PublicIPv4 != nil {
		if pub := m.o.PublicIPv4(); pub.IsValid() && !local[pub] {
			nat = true
		}
	}
	return priv, nat
}

// skipInterface: Docker bridges, veths and the mesh are not how users reach a server.
func skipInterface(name string) bool {
	for _, p := range []string{"docker", "br-", "veth", "tailscale", "lr-", "cni", "flannel"} {
		if strings.HasPrefix(name, p) {
			return true
		}
	}
	return false
}

func firewallMesh(ip netip.Addr) bool {
	for _, p := range firewall.Mesh {
		if p.Contains(ip) {
			return true
		}
	}
	return false
}

// ---- role ledger ----

// LedgerEntry is a temporary role that may exist in a database.
type LedgerEntry struct {
	Access      state.AccessID       `json:"access"`
	Engine      state.DatabaseEngine `json:"engine"`
	Workload    state.WorkloadID     `json:"workload"`
	Service     state.ServiceID      `json:"service,omitzero"`
	Environment state.EnvironmentID  `json:"environment,omitzero"`
	Role        string               `json:"role"`
	CreatedAt   time.Time            `json:"createdAt"`
}

func (m *Manager) ledgerPath() string { return filepath.Join(m.o.DataDir, "access", "roles.json") }

// Ledger returns the entries.
func (m *Manager) Ledger() ([]LedgerEntry, error) {
	m.ledgerMu.Lock()
	defer m.ledgerMu.Unlock()
	return m.readLedger()
}

func (m *Manager) readLedger() ([]LedgerEntry, error) {
	if m.o.DataDir == "" {
		return nil, nil
	}
	b, err := os.ReadFile(m.ledgerPath())
	if errors.Is(err, os.ErrNotExist) {
		return nil, nil
	}
	if err != nil {
		return nil, err
	}
	var out []LedgerEntry
	if err := json.Unmarshal(b, &out); err != nil {
		return nil, fmt.Errorf("access ledger: %w", err)
	}
	return out, nil
}

func (m *Manager) writeLedger(es []LedgerEntry) error {
	if m.o.DataDir == "" {
		return nil
	}
	if err := os.MkdirAll(filepath.Dir(m.ledgerPath()), 0o700); err != nil {
		return err
	}
	b, err := json.Marshal(es)
	if err != nil {
		return err
	}
	return atomicfile.Write(m.ledgerPath(), b, 0o600)
}

// Remember adds an entry before the role is created (write-ahead).
func (m *Manager) Remember(e LedgerEntry) error {
	if !reRoleName.MatchString(e.Role) {
		return errors.New("access ledger: role name is not generated")
	}
	m.ledgerMu.Lock()
	defer m.ledgerMu.Unlock()
	es, err := m.readLedger()
	if err != nil {
		return err
	}
	es = slices.DeleteFunc(es, func(x LedgerEntry) bool { return x.Role == e.Role })
	return m.writeLedger(append(es, e))
}

// Forget removes an entry after the role was dropped.
func (m *Manager) Forget(role string) error {
	m.ledgerMu.Lock()
	defer m.ledgerMu.Unlock()
	es, err := m.readLedger()
	if err != nil {
		return err
	}
	n := len(es)
	es = slices.DeleteFunc(es, func(x LedgerEntry) bool { return x.Role == role })
	if len(es) == n {
		return nil
	}
	return m.writeLedger(es)
}

// Sweep drops the roles of entries whose access isn't running in this
// process (left by a crash or restart). drop is called per entry; entries
// whose drop succeeded or whose database is gone are forgotten.
func (m *Manager) Sweep(ctx context.Context, drop func(ctx context.Context, e LedgerEntry) error) {
	es, err := m.Ledger()
	if err != nil {
		m.o.Log.Warn("reading the access ledger failed", "err", err)
		return
	}
	for _, e := range es {
		if m.Running(e.Access) || ctx.Err() != nil {
			continue
		}
		if err := drop(ctx, e); err != nil {
			m.o.Log.Warn("dropping a leftover temporary role failed; retrying on the next pass", "access", e.Access, "err", err)
			continue
		}
		if err := m.Forget(e.Role); err != nil {
			m.o.Log.Warn("updating the access ledger failed", "err", err)
		}
		m.o.Log.Info("dropped a leftover temporary role", "access", e.Access, "engine", e.Engine)
	}
}
