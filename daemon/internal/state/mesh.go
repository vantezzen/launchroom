package state

import (
	"encoding/json/v2"
	"strings"
	"time"

	"github.com/vantezzen/launchroom/daemon/internal/secret"
)

// ---- Mesh (docs/05 §2) ----

// MeshAuthKey is the plaintext of NodeState.system.tailscale.authKey:
// libsodium crypto_box_seal of this JSON object to the daemon's X25519 key.
// ServerID binds it to one server (no re-targeting).
type MeshAuthKey struct {
	ServerID ServerID `json:"serverId"`
	// AuthKey is a Headscale pre-auth key (single-use, tagged, short-lived) or,
	// with "bring your own Tailscale", the tailnet's auth key.
	AuthKey secret.Secret `json:"authKey" jsonschema:"type=string,minLength=1,maxLength=1024"`
}

type meshAuthKeyWire struct {
	ServerID ServerID `json:"serverId"`
	AuthKey  string   `json:"authKey"`
}

// MarshalJSON reveals the key deliberately: only used to produce plaintext that is sealed immediately.
func (k MeshAuthKey) MarshalJSON() ([]byte, error) {
	return json.Marshal(meshAuthKeyWire{ServerID: k.ServerID, AuthKey: k.AuthKey.Reveal()})
}

// UnmarshalJSON strictly decodes the wire form.
func (k *MeshAuthKey) UnmarshalJSON(data []byte) error {
	var w meshAuthKeyWire
	if err := json.Unmarshal(data, &w, strictOptions()); err != nil {
		return err
	}
	*k = MeshAuthKey{ServerID: w.ServerID, AuthKey: secret.Secret(w.AuthKey)}
	return nil
}

// Validate checks the id and the key's size and characters. Errors never contain the key.
func (k *MeshAuthKey) Validate() error {
	var e errs
	e.check("serverId", k.ServerID)
	v := k.AuthKey.Reveal()
	if v == "" || len(v) > 1024 || strings.ContainsFunc(v, func(r rune) bool { return r <= ' ' || r == 0x7f }) {
		e.addf("authKey", "must be 1-1024 printable bytes without spaces")
	}
	return e.err()
}

// MeshState is the state of the mesh client on a server.
type MeshState string

// Mesh states.
const (
	// MeshStarting: the client container is being created or has not answered yet.
	MeshStarting MeshState = "starting"
	// MeshNeedsLogin: the client is not registered and has no usable auth key yet.
	MeshNeedsLogin MeshState = "needs_login"
	// MeshConnected: registered, with a mesh address.
	MeshConnected MeshState = "connected"
	// MeshError: the client cannot run or log in (see message).
	MeshError MeshState = "error"
)

var meshStates = []string{string(MeshStarting), string(MeshNeedsLogin), string(MeshConnected), string(MeshError)}

// ProtocolSchema implements SchemaDescriber.
func (MeshState) ProtocolSchema() ScalarSchema { return enumSchema(meshStates) }

// Validate checks the value.
func (v MeshState) Validate() error { return checkEnum("mesh state", string(v), meshStates) }

// MeshConnection is how this server reaches a peer.
type MeshConnection string

// Mesh connection kinds.
const (
	// MeshDirect: WireGuard packets flow directly (hole punched or public).
	MeshDirect MeshConnection = "direct"
	// MeshRelay: traffic is relayed through a DERP server.
	MeshRelay MeshConnection = "relay"
	// MeshIdle: no traffic recently; the path is not known.
	MeshIdle MeshConnection = "idle"
)

var meshConnections = []string{string(MeshDirect), string(MeshRelay), string(MeshIdle)}

// ProtocolSchema implements SchemaDescriber.
func (MeshConnection) ProtocolSchema() ScalarSchema { return enumSchema(meshConnections) }

// Validate checks the value.
func (v MeshConnection) Validate() error {
	return checkEnum("mesh connection", string(v), meshConnections)
}

// Limits of a mesh status.
const maxMeshPeers = 256

// MeshStatus is Report.host.mesh: the mesh client's view (omitted when the mesh is off).
type MeshStatus struct {
	State MeshState `json:"state"`
	// IPv4 and IPv6 are this server's mesh addresses once connected.
	IPv4 IPAddr `json:"ipv4,omitzero"`
	IPv6 IPAddr `json:"ipv6,omitzero"`
	// Message explains starting, needs_login and error states (secret-free).
	Message string `json:"message,omitzero" jsonschema:"maxLength=1024"`
	// Peers are the other servers this client knows (at most 256).
	Peers []MeshPeer `json:"peers,omitzero" jsonschema:"nullable"`
	// CheckedAt is when the client was last asked (daemon clock).
	CheckedAt time.Time `json:"checkedAt"`
}

// MeshPeer is one peer in the mesh status.
type MeshPeer struct {
	IPv4   IPAddr `json:"ipv4"`
	Online bool   `json:"online"`
	// Connection is direct, relay (via DERP) or idle.
	Connection MeshConnection `json:"connection"`
	// Relay is the DERP region code in use (relay) or the peer's home region.
	Relay Label `json:"relay,omitzero"`
	// LastHandshake is the last WireGuard handshake with the peer.
	LastHandshake *time.Time `json:"lastHandshake,omitzero" jsonschema:"nullable"`
}

func (m *MeshStatus) validate(e *errs, p string) {
	e.check(p+".state", m.State)
	if m.IPv4 != "" {
		e.check(p+".ipv4", m.IPv4)
	}
	if m.IPv6 != "" {
		e.check(p+".ipv6", m.IPv6)
	}
	checkText(e, p+".message", m.Message, 1024)
	if m.CheckedAt.IsZero() {
		e.addf(p+".checkedAt", "is required")
	}
	checkCount(e, p+".peers", len(m.Peers), 0, maxMeshPeers)
	ips := make([]IPAddr, 0, len(m.Peers))
	for i, peer := range m.Peers {
		pp := idx(p+".peers", i)
		e.check(pp+".ipv4", peer.IPv4)
		e.check(pp+".connection", peer.Connection)
		if peer.Relay != "" {
			e.check(pp+".relay", peer.Relay)
		}
		ips = append(ips, peer.IPv4)
	}
	unique(e, p+".peers.ipv4", ips)
}
