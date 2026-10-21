package mesh

import (
	"encoding/json/v2"
	"fmt"
	"net/netip"
	"slices"
	"strings"
	"time"

	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// recentHandshake is how recent the last handshake with a peer must be for a
// path without a direct address to count as relayed (WireGuard re-handshakes
// every 2 minutes while traffic flows).
const recentHandshake = 3 * time.Minute

// tsStatus is the subset of `tailscale status --json` (ipnstate.Status) and
// `tailscale debug prefs` (ipn.Prefs) launchroomd reads. Unknown fields are
// ignored: the client's output is not a launchroom protocol document.
type tsStatus struct {
	Status struct {
		BackendState string   `json:"BackendState"`
		TailscaleIPs []string `json:"TailscaleIPs"`
		Health       []string `json:"Health"`
		Self         *tsPeer  `json:"Self"`
		Peer         map[string]*tsPeer
	} `json:"status"`
	Prefs struct {
		ControlURL string `json:"ControlURL"`
	} `json:"prefs"`
}

type tsPeer struct {
	TailscaleIPs  []string  `json:"TailscaleIPs"`
	Online        bool      `json:"Online"`
	CurAddr       string    `json:"CurAddr"`
	Relay         string    `json:"Relay"`
	LastHandshake time.Time `json:"LastHandshake"`
}

// parsedStatus is the client's state as launchroomd needs it.
type parsedStatus struct {
	backend    string
	controlURL string
	// controlLost: running, but the client reports no connection to the
	// coordination server (Self.Online is explicitly false).
	controlLost bool
	report      *state.MeshStatus
}

// ParseStatus turns the merged status/prefs output into the reported status.
// It never fails on unexpected values: anything that would not pass the
// protocol grammar is dropped.
func ParseStatus(raw []byte, now time.Time) (parsedStatus, error) {
	var s tsStatus
	if err := json.Unmarshal(raw, &s); err != nil {
		return parsedStatus{}, fmt.Errorf("mesh: cannot read the client status: %w", err)
	}
	out := parsedStatus{backend: s.Status.BackendState, controlURL: s.Prefs.ControlURL}
	if s.Status.BackendState == "Running" {
		var self struct {
			Status struct {
				Self *struct {
					Online *bool `json:"Online"`
				} `json:"Self"`
			} `json:"status"`
		}
		if json.Unmarshal(raw, &self) == nil && self.Status.Self != nil && self.Status.Self.Online != nil {
			out.controlLost = !*self.Status.Self.Online
		}
	}
	rep := &state.MeshStatus{CheckedAt: now.UTC()}
	out.report = rep
	v4, v6 := splitIPs(s.Status.TailscaleIPs)
	switch {
	case s.Status.BackendState == "Running" && v4.IsValid():
		rep.State = state.MeshConnected
		rep.IPv4 = state.IPAddr(v4.String())
		if v6.IsValid() {
			rep.IPv6 = state.IPAddr(v6.String())
		}
	case s.Status.BackendState == "NeedsMachineAuth":
		rep.State = state.MeshNeedsLogin
		rep.Message = "waiting for the coordination server to approve this server"
	case s.Status.BackendState == "NeedsLogin" || s.Status.BackendState == "NoState" || s.Status.BackendState == "Stopped":
		rep.State = state.MeshNeedsLogin
		rep.Message = "not logged in to the mesh yet"
	default:
		rep.State = state.MeshStarting
		rep.Message = "mesh client is " + strings.ToLower(safeWord(s.Status.BackendState))
	}
	if rep.State != state.MeshConnected && len(s.Status.Health) > 0 {
		rep.Message = truncate(rep.Message + ": " + strings.Join(s.Status.Health, "; "))
	}
	if rep.State != state.MeshConnected {
		return out, nil
	}
	for _, p := range s.Status.Peer {
		if p == nil {
			continue
		}
		pv4, _ := splitIPs(p.TailscaleIPs)
		if !pv4.IsValid() {
			continue
		}
		peer := state.MeshPeer{IPv4: state.IPAddr(pv4.String()), Online: p.Online, Connection: connection(p, now)}
		if r := state.Label(p.Relay); p.Relay != "" && r.Validate() == nil {
			peer.Relay = r
		}
		if !p.LastHandshake.IsZero() && p.LastHandshake.Year() > 1 {
			t := p.LastHandshake.UTC()
			peer.LastHandshake = &t
		}
		rep.Peers = append(rep.Peers, peer)
	}
	slices.SortFunc(rep.Peers, func(a, b state.MeshPeer) int {
		x, _ := netip.ParseAddr(string(a.IPv4))
		y, _ := netip.ParseAddr(string(b.IPv4))
		return x.Compare(y)
	})
	rep.Peers = slices.CompactFunc(rep.Peers, func(a, b state.MeshPeer) bool { return a.IPv4 == b.IPv4 })
	if len(rep.Peers) > maxPeers {
		rep.Peers = rep.Peers[:maxPeers]
	}
	return out, nil
}

const maxPeers = 256

func connection(p *tsPeer, now time.Time) state.MeshConnection {
	switch {
	case p.CurAddr != "":
		return state.MeshDirect
	case !p.LastHandshake.IsZero() && p.LastHandshake.Year() > 1 && now.Sub(p.LastHandshake) < recentHandshake:
		return state.MeshRelay
	default:
		return state.MeshIdle
	}
}

func splitIPs(ips []string) (v4, v6 netip.Addr) {
	for _, s := range ips {
		a, err := netip.ParseAddr(s)
		if err != nil || a.Zone() != "" {
			continue
		}
		if a.Is4() && !v4.IsValid() {
			v4 = a
		}
		if a.Is6() && !v6.IsValid() {
			v6 = a
		}
	}
	return v4, v6
}

func safeWord(s string) string {
	if s == "" || len(s) > 32 || strings.ContainsFunc(s, func(r rune) bool { return (r < 'A' || r > 'Z') && (r < 'a' || r > 'z') }) {
		return "starting"
	}
	return s
}
