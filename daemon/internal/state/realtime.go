package state

import (
	"fmt"
	"net/url"
	"regexp"
)

// Realtime nudges (docs/03 §4): the dashboard tells the daemon through a
// Reverb (Pusher protocol) WebSocket that something changed; the nudge is
// content-free and the daemon always fetches through the signed HTTP channel.

// Document kinds.
const (
	KindDaemonConfig         = "DaemonConfig"
	KindBroadcastAuthRequest = "BroadcastAuthRequest"
	KindBroadcastAuth        = "BroadcastAuth"
)

// Nudge event names on the daemon's private channel. They carry no data.
const (
	NudgeStateChanged  = "state.changed"
	NudgeTaskAvailable = "task.available"
	NudgeLogsRequested = "logs.requested"
)

// DaemonChannelPrefix + the server id is the daemon's private Pusher channel.
const DaemonChannelPrefix = "private-daemon."

var (
	reWebSocketURL  = regexp.MustCompile(`^wss?://[a-z0-9.-]+(?::[0-9]{1,5})?(?:/[A-Za-z0-9._~-]+)*$`)
	rePusherKey     = regexp.MustCompile(`^[A-Za-z0-9_.-]{1,128}$`)
	reSocketID      = regexp.MustCompile(`^[0-9]{1,20}\.[0-9]{1,20}$`)
	reDaemonChannel = regexp.MustCompile(`^private-(?:daemon\.srv_|session\.ses_)` + ulid + `$`)
	rePusherAuth    = regexp.MustCompile(`^[A-Za-z0-9_.-]{1,128}:[a-f0-9]{64}$`)
)

// WebSocketURL is the base URL of the Pusher-protocol server (ws:// or wss://,
// optional path prefix, no query). The daemon appends /app/<key>.
type WebSocketURL string

// ProtocolSchema implements SchemaDescriber.
func (WebSocketURL) ProtocolSchema() ScalarSchema { return strSchema(reWebSocketURL, 2048) }

// Validate checks the grammar.
func (v WebSocketURL) Validate() error {
	if err := checkPattern("websocket url", string(v), reWebSocketURL, 2048); err != nil {
		return err
	}
	if u, err := url.Parse(string(v)); err != nil || u.Host == "" || u.User != nil {
		return fmt.Errorf("websocket url %q is invalid", truncate(string(v)))
	}
	return nil
}

// PusherKey is the public application key of the Pusher-protocol server.
type PusherKey string

// ProtocolSchema implements SchemaDescriber.
func (PusherKey) ProtocolSchema() ScalarSchema { return strSchema(rePusherKey, 128) }

// Validate checks the grammar.
func (v PusherKey) Validate() error { return checkPattern("pusher key", string(v), rePusherKey, 128) }

// SocketID is the Pusher connection id ("123.456").
type SocketID string

// ProtocolSchema implements SchemaDescriber.
func (SocketID) ProtocolSchema() ScalarSchema { return strSchema(reSocketID, 41) }

// Validate checks the grammar.
func (v SocketID) Validate() error { return checkPattern("socket id", string(v), reSocketID, 41) }

// DaemonChannel is a private channel launchroomd joins: its own
// "private-daemon.<server id>" or a session's "private-session.<session id>".
type DaemonChannel string

// ProtocolSchema implements SchemaDescriber.
func (DaemonChannel) ProtocolSchema() ScalarSchema { return strSchema(reDaemonChannel, 0) }

// Validate checks the grammar.
func (v DaemonChannel) Validate() error {
	return checkPattern("channel", string(v), reDaemonChannel, 0)
}

// ChannelFor returns the private channel of a server.
func ChannelFor(id ServerID) DaemonChannel { return DaemonChannel(DaemonChannelPrefix + string(id)) }

// PusherAuth is a channel authorization "<key>:<hex HMAC-SHA256>".
type PusherAuth string

// ProtocolSchema implements SchemaDescriber.
func (PusherAuth) ProtocolSchema() ScalarSchema { return strSchema(rePusherAuth, 0) }

// Validate checks the grammar.
func (v PusherAuth) Validate() error { return checkPattern("auth", string(v), rePusherAuth, 0) }

// NudgeConfig tells the daemon where to receive nudges.
type NudgeConfig struct {
	// Key is the public Pusher application key.
	Key PusherKey `json:"key"`
	// URL is the WebSocket base URL. Omitted: derived from the daemon's
	// control-plane URL (http→ws, https→wss, same host and path), where the
	// dashboard proxies /app/* to Reverb.
	URL WebSocketURL `json:"url,omitzero"`
}

func (n *NudgeConfig) validate(e *errs, path string) {
	e.check(path+".key", n.Key)
	if n.URL != "" {
		e.check(path+".url", n.URL)
	}
}

// DaemonConfig is the signed response to GET /daemon/v1/config: settings the
// dashboard may change after enrollment.
type DaemonConfig struct {
	TypeMeta
	// Nudge is omitted when realtime nudges are off (the daemon only polls).
	Nudge *NudgeConfig `json:"nudge,omitzero" jsonschema:"nullable"`
}

// Validate checks the document.
func (c *DaemonConfig) Validate() error {
	var e errs
	c.check(&e, KindDaemonConfig)
	if c.Nudge != nil {
		c.Nudge.validate(&e, "nudge")
	}
	return e.err()
}

// BroadcastAuthRequest is POST /daemon/v1/broadcasting/auth: the daemon asks to
// subscribe its connection to its own private channel.
type BroadcastAuthRequest struct {
	TypeMeta
	SocketID SocketID      `json:"socketId"`
	Channel  DaemonChannel `json:"channel"`
}

// Validate checks the request.
func (r *BroadcastAuthRequest) Validate() error {
	var e errs
	r.check(&e, KindBroadcastAuthRequest)
	e.check("socketId", r.SocketID)
	e.check("channel", r.Channel)
	return e.err()
}

// BroadcastAuth is the signed channel authorization for one socket.
type BroadcastAuth struct {
	TypeMeta
	Auth PusherAuth `json:"auth"`
}

// Validate checks the response.
func (a *BroadcastAuth) Validate() error {
	var e errs
	a.check(&e, KindBroadcastAuth)
	e.check("auth", a.Auth)
	return e.err()
}
