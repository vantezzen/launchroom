package session

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

	"github.com/vantezzen/launchroom/daemon/internal/nudge"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// Pusher protocol events used by the relay connection.
const (
	evEstablished     = "pusher:connection_established"
	evError           = "pusher:error"
	evPing            = "pusher:ping"
	evPong            = "pusher:pong"
	evSubscribe       = "pusher:subscribe"
	evSubscribed      = "pusher_internal:subscription_succeeded"
	evSubscriptionErr = "pusher:subscription_error"

	relayReadLimit  = 64 << 10
	relayHandshake  = 15 * time.Second
	relayWrite      = 10 * time.Second
	relayPingEvery  = 25 * time.Second
	relayPongWithin = 20 * time.Second
)

// Authorizer authorizes a socket on a channel (the signed
// POST /daemon/v1/broadcasting/auth).
type Authorizer interface {
	BroadcastAuth(ctx context.Context, socketID state.SocketID, channel state.DaemonChannel) (state.PusherAuth, error)
}

// Event is one event received on the session channel.
type Event struct {
	Name string
	// Data is the event data: an object for client events, the decoded
	// string for server events.
	Data jsontext.Value
}

// message is a Pusher protocol frame.
type message struct {
	Event   string         `json:"event"`
	Channel string         `json:"channel,omitzero"`
	Data    jsontext.Value `json:"data,omitzero"`
}

// unwrap returns Data, decoding the string form of server events.
func (m message) unwrap() (jsontext.Value, bool, error) {
	if len(m.Data) > 0 && m.Data[0] == '"' {
		var s string
		if err := json.Unmarshal(m.Data, &s); err != nil {
			return nil, false, err
		}
		return jsontext.Value(s), true, nil
	}
	return m.Data, false, nil
}

// RelayOptions configures Dial.
type RelayOptions struct {
	Nudge           *state.NudgeConfig
	ControlPlaneURL *url.URL
	Channel         state.DaemonChannel
	Auth            Authorizer
	DaemonVersion   string
	HTTPClient      *http.Client
}

// Relay is a subscribed connection to one session channel.
type Relay struct {
	conn    *websocket.Conn
	channel string
	events  chan Event
	done    chan struct{}
	err     error
	errOnce sync.Once
	wmu     sync.Mutex
	pong    chan struct{}
}

// Dial connects, authorizes and subscribes; it returns once the subscription is confirmed.
func Dial(ctx context.Context, o RelayOptions) (*Relay, error) {
	if o.Nudge == nil {
		return nil, errors.New("session: realtime (Reverb) is off on the dashboard")
	}
	endpoint, err := nudge.Endpoint(o.Nudge, o.ControlPlaneURL, o.DaemonVersion)
	if err != nil {
		return nil, err
	}
	hc := o.HTTPClient
	if hc == nil {
		hc = http.DefaultClient
	}
	hctx, cancel := context.WithTimeout(ctx, relayHandshake)
	defer cancel()
	h := http.Header{}
	if o.ControlPlaneURL != nil {
		h.Set("Origin", o.ControlPlaneURL.Scheme+"://"+o.ControlPlaneURL.Host)
	}
	conn, resp, err := websocket.Dial(hctx, endpoint, &websocket.DialOptions{HTTPClient: hc, HTTPHeader: h})
	if resp != nil && resp.Body != nil {
		_ = resp.Body.Close()
	}
	if err != nil {
		return nil, fmt.Errorf("session: connect to relay: %w", err)
	}
	conn.SetReadLimit(relayReadLimit)
	r := &Relay{conn: conn, channel: string(o.Channel), events: make(chan Event, 64), done: make(chan struct{}), pong: make(chan struct{}, 1)}
	if err := r.handshake(hctx, o); err != nil {
		_ = conn.CloseNow()
		return nil, err
	}
	go r.readLoop(context.WithoutCancel(ctx))
	go r.pingLoop(context.WithoutCancel(ctx))
	return r, nil
}

func (r *Relay) read(ctx context.Context) (message, error) {
	_, data, err := r.conn.Read(ctx)
	if err != nil {
		return message{}, err
	}
	var m message
	if err := json.Unmarshal(data, &m); err != nil {
		return message{}, fmt.Errorf("session: malformed relay frame: %w", err)
	}
	return m, nil
}

func (r *Relay) handshake(ctx context.Context, o RelayOptions) error {
	for {
		m, err := r.read(ctx)
		if err != nil {
			return fmt.Errorf("session: relay handshake: %w", err)
		}
		switch m.Event {
		case evEstablished:
			data, _, err := m.unwrap()
			if err != nil {
				return err
			}
			var est struct {
				SocketID string `json:"socket_id"`
			}
			if err := json.Unmarshal(data, &est); err != nil {
				return fmt.Errorf("session: connection_established: %w", err)
			}
			sid := state.SocketID(est.SocketID)
			if err := sid.Validate(); err != nil {
				return fmt.Errorf("session: %w", err)
			}
			auth, err := o.Auth.BroadcastAuth(ctx, sid, o.Channel)
			if err != nil {
				return fmt.Errorf("session: channel authorization: %w", err)
			}
			sub, err := json.Marshal(map[string]string{"auth": string(auth), "channel": r.channel})
			if err != nil {
				return err
			}
			if err := r.write(ctx, message{Event: evSubscribe, Data: sub}); err != nil {
				return err
			}
		case evSubscribed:
			if m.Channel == r.channel {
				return nil
			}
		case evError, evSubscriptionErr:
			return fmt.Errorf("session: relay refused the subscription: %s", clip(string(m.Data)))
		case evPing:
			if err := r.write(ctx, message{Event: evPong, Data: jsontext.Value(`{}`)}); err != nil {
				return err
			}
		}
	}
}

func (r *Relay) readLoop(ctx context.Context) {
	defer close(r.events)
	for {
		m, err := r.read(ctx)
		if err != nil {
			r.fail(err)
			return
		}
		switch m.Event {
		case evPing:
			_ = r.write(ctx, message{Event: evPong, Data: jsontext.Value(`{}`)})
			continue
		case evPong:
			select {
			case r.pong <- struct{}{}:
			default:
			}
			continue
		case evError:
			r.fail(fmt.Errorf("session: relay error: %s", clip(string(m.Data))))
			return
		}
		if m.Channel != r.channel {
			continue
		}
		data, _, err := m.unwrap()
		if err != nil {
			continue
		}
		select {
		case r.events <- Event{Name: m.Event, Data: data}:
		case <-r.done:
			return
		}
	}
}

func (r *Relay) pingLoop(base context.Context) {
	t := time.NewTicker(relayPingEvery)
	defer t.Stop()
	for {
		select {
		case <-r.done:
			return
		case <-t.C:
		}
		ctx, cancel := context.WithTimeout(base, relayWrite)
		err := r.write(ctx, message{Event: evPing, Data: jsontext.Value(`{}`)})
		cancel()
		if err != nil {
			r.fail(err)
			return
		}
		select {
		case <-r.pong:
		case <-r.done:
			return
		case <-time.After(relayPongWithin):
			r.fail(errors.New("session: relay did not answer a ping"))
			return
		}
	}
}

func (r *Relay) write(ctx context.Context, m message) error {
	data, err := json.Marshal(m)
	if err != nil {
		return err
	}
	r.wmu.Lock()
	defer r.wmu.Unlock()
	wctx, cancel := context.WithTimeout(ctx, relayWrite)
	defer cancel()
	if err := r.conn.Write(wctx, websocket.MessageText, data); err != nil {
		return fmt.Errorf("session: relay write: %w", err)
	}
	return nil
}

// SendFrame sends one frame as a client event.
func (r *Relay) SendFrame(ctx context.Context, f Frame) error {
	data, err := json.Marshal(f)
	if err != nil {
		return err
	}
	return r.write(ctx, message{Event: FrameEvent, Channel: r.channel, Data: data})
}

// Events returns received channel events; it is closed when the connection ends.
func (r *Relay) Events() <-chan Event { return r.events }

// Err is why the connection ended (after Events is closed).
func (r *Relay) Err() error { return r.err }

func (r *Relay) fail(err error) {
	r.errOnce.Do(func() {
		r.err = err
		close(r.done)
		_ = r.conn.CloseNow()
	})
}

// Close ends the connection.
func (r *Relay) Close() {
	r.errOnce.Do(func() {
		r.err = errors.New("session: closed")
		close(r.done)
		_ = r.conn.Close(websocket.StatusNormalClosure, "session ended")
	})
}

func clip(s string) string {
	b := []rune(s)
	if len(b) > 200 {
		b = b[:200]
	}
	out := make([]rune, 0, len(b))
	for _, c := range b {
		if c >= 0x20 && c != 0x7f {
			out = append(out, c)
		}
	}
	return string(out)
}
