// Package pusher is launchroomd's client side of the Pusher protocol that
// Reverb speaks (https://pusher.com/docs/channels/library_auth_reference/pusher-websockets-protocol/):
// dialing, framing, the signed channel subscription and pings. The nudge
// subscription and the session relay both use it.
package pusher

import (
	"context"
	"encoding/json/jsontext"
	"encoding/json/v2"
	"errors"
	"fmt"
	"net/http"
	"net/url"
	"sync"
	"time"

	"github.com/coder/websocket"

	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// Protocol events.
const (
	EvEstablished       = "pusher:connection_established"
	EvError             = "pusher:error"
	EvPing              = "pusher:ping"
	EvPong              = "pusher:pong"
	EvSubscribe         = "pusher:subscribe"
	EvSubscribed        = "pusher_internal:subscription_succeeded"
	EvSubscriptionError = "pusher:subscription_error"

	protocolVersion = "7"
	readLimit       = 64 << 10
	writeTimeout    = 10 * time.Second
)

// Message is a protocol frame. Data is a JSON string for server events
// (Pusher double-encodes) or an object for client events.
type Message struct {
	Event   string         `json:"event"`
	Channel string         `json:"channel,omitzero"`
	Data    jsontext.Value `json:"data,omitzero"`
}

// Payload is Data, decoded from its string form for server events.
func (m Message) Payload() (jsontext.Value, error) {
	if len(m.Data) > 0 && m.Data[0] == '"' {
		var s string
		if err := json.Unmarshal(m.Data, &s); err != nil {
			return nil, err
		}
		return jsontext.Value(s), nil
	}
	return m.Data, nil
}

// ErrorText is a server error frame's data, cut to one short printable line.
func (m Message) ErrorText() string {
	var pe struct {
		Code    int    `json:"code"`
		Message string `json:"message"`
	}
	if p, err := m.Payload(); err == nil && json.Unmarshal(p, &pe) == nil && pe.Message != "" {
		return state.ClipLine(fmt.Sprintf("%d %s", pe.Code, pe.Message), 200)
	}
	return state.ClipLine(string(m.Data), 200)
}

// Authorizer signs a socket's subscription to a private channel (the
// signed POST /daemon/v1/broadcasting/auth).
type Authorizer interface {
	BroadcastAuth(ctx context.Context, socketID state.SocketID, channel state.DaemonChannel) (state.PusherAuth, error)
}

// Endpoint returns the WebSocket URL for cfg: its own URL, or the
// control-plane URL with a ws(s) scheme.
func Endpoint(cfg *state.NudgeConfig, controlPlane *url.URL, daemonVersion string) (string, error) {
	var base *url.URL
	if cfg.URL != "" {
		u, err := url.Parse(string(cfg.URL))
		if err != nil {
			return "", fmt.Errorf("pusher: %w", err)
		}
		base = u
	} else {
		if controlPlane == nil {
			return "", errors.New("pusher: no WebSocket URL and no control-plane URL")
		}
		u := *controlPlane
		switch u.Scheme {
		case "https":
			u.Scheme = "wss"
		case "http":
			u.Scheme = "ws"
		default:
			return "", fmt.Errorf("pusher: cannot derive a WebSocket URL from %q", controlPlane.Scheme)
		}
		u.RawQuery, u.Fragment, u.User = "", "", nil
		base = &u
	}
	ep := base.JoinPath("app", string(cfg.Key))
	ep.RawQuery = url.Values{
		"protocol": {protocolVersion}, "client": {"launchroomd"}, "version": {daemonVersion}, "flash": {"false"},
	}.Encode()
	return ep.String(), nil
}

// Conn is one connection. Writes are safe from several goroutines.
type Conn struct {
	ws  *websocket.Conn
	wmu sync.Mutex
}

// Dial connects to endpoint (Origin: the control plane's, when known).
func Dial(ctx context.Context, endpoint string, controlPlane *url.URL, hc *http.Client) (*Conn, error) {
	h := http.Header{}
	if controlPlane != nil {
		h.Set("Origin", controlPlane.Scheme+"://"+controlPlane.Host)
	}
	ws, resp, err := websocket.Dial(ctx, endpoint, &websocket.DialOptions{HTTPClient: hc, HTTPHeader: h})
	if resp != nil && resp.Body != nil {
		_ = resp.Body.Close() // the library owns the connection; this only satisfies bodyclose
	}
	if err != nil {
		return nil, err
	}
	ws.SetReadLimit(readLimit)
	return &Conn{ws: ws}, nil
}

// Read returns the next frame.
func (c *Conn) Read(ctx context.Context) (Message, error) {
	_, data, err := c.ws.Read(ctx)
	if err != nil {
		return Message{}, err
	}
	var m Message
	if err := json.Unmarshal(data, &m); err != nil {
		return Message{}, fmt.Errorf("pusher: malformed frame: %w", err)
	}
	return m, nil
}

// Write sends a frame.
func (c *Conn) Write(ctx context.Context, m Message) error {
	data, err := json.Marshal(m)
	if err != nil {
		return err
	}
	c.wmu.Lock()
	defer c.wmu.Unlock()
	wctx, cancel := context.WithTimeout(ctx, writeTimeout)
	defer cancel()
	if err := c.ws.Write(wctx, websocket.MessageText, data); err != nil {
		return fmt.Errorf("pusher: write: %w", err)
	}
	return nil
}

// Ping and Pong send the keep-alive frames.
func (c *Conn) Ping(ctx context.Context) error {
	return c.Write(ctx, Message{Event: EvPing, Data: jsontext.Value(`{}`)})
}

// Pong answers the server's ping.
func (c *Conn) Pong(ctx context.Context) error {
	return c.Write(ctx, Message{Event: EvPong, Data: jsontext.Value(`{}`)})
}

// Subscribe answers connection_established (m): it has auth sign the socket
// for channel and subscribes. It returns the server's activity timeout (0:
// none announced).
func (c *Conn) Subscribe(ctx context.Context, m Message, auth Authorizer, channel state.DaemonChannel) (time.Duration, error) {
	data, err := m.Payload()
	if err != nil {
		return 0, err
	}
	var est struct {
		SocketID        string `json:"socket_id"`
		ActivityTimeout int    `json:"activity_timeout"`
	}
	if err := json.Unmarshal(data, &est); err != nil {
		return 0, fmt.Errorf("pusher: connection_established: %w", err)
	}
	sid := state.SocketID(est.SocketID)
	if err := sid.Validate(); err != nil {
		return 0, fmt.Errorf("pusher: %w", err)
	}
	signed, err := auth.BroadcastAuth(ctx, sid, channel)
	if err != nil {
		return 0, fmt.Errorf("pusher: channel authorization: %w", err)
	}
	sub, err := json.Marshal(map[string]string{"auth": string(signed), "channel": string(channel)})
	if err != nil {
		return 0, err
	}
	if err := c.Write(ctx, Message{Event: EvSubscribe, Data: sub}); err != nil {
		return 0, err
	}
	return time.Duration(est.ActivityTimeout) * time.Second, nil
}

// Close closes the connection with a normal closure.
func (c *Conn) Close(reason string) error { return c.ws.Close(websocket.StatusNormalClosure, reason) }

// CloseNow drops the connection.
func (c *Conn) CloseNow() error { return c.ws.CloseNow() }
