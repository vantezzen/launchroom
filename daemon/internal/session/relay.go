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

	"github.com/vantezzen/launchroom/daemon/internal/pusher"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

const (
	relayHandshake  = 15 * time.Second
	relayWrite      = 10 * time.Second
	relayPingEvery  = 25 * time.Second
	relayPongWithin = 20 * time.Second
)

// Authorizer authorizes a socket on a channel (the signed
// POST /daemon/v1/broadcasting/auth).
type Authorizer = pusher.Authorizer

// Event is one event received on the session channel.
type Event struct {
	Name string
	// Data is the event data: an object for client events, the decoded
	// string for server events.
	Data jsontext.Value
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
	conn    *pusher.Conn
	channel string
	events  chan Event
	done    chan struct{}
	err     error
	errOnce sync.Once
	pong    chan struct{}
}

// Dial connects, authorizes and subscribes; it returns once the subscription is confirmed.
func Dial(ctx context.Context, o RelayOptions) (*Relay, error) {
	if o.Nudge == nil {
		return nil, errors.New("session: realtime (Reverb) is off on the dashboard")
	}
	endpoint, err := pusher.Endpoint(o.Nudge, o.ControlPlaneURL, o.DaemonVersion)
	if err != nil {
		return nil, err
	}
	hc := o.HTTPClient
	if hc == nil {
		hc = http.DefaultClient
	}
	hctx, cancel := context.WithTimeout(ctx, relayHandshake)
	defer cancel()
	conn, err := pusher.Dial(hctx, endpoint, o.ControlPlaneURL, hc)
	if err != nil {
		return nil, fmt.Errorf("session: connect to relay: %w", err)
	}
	r := &Relay{conn: conn, channel: string(o.Channel), events: make(chan Event, 64), done: make(chan struct{}), pong: make(chan struct{}, 1)}
	if err := r.handshake(hctx, o); err != nil {
		_ = conn.CloseNow()
		return nil, err
	}
	go r.readLoop(context.WithoutCancel(ctx))
	go r.pingLoop(context.WithoutCancel(ctx))
	return r, nil
}

func (r *Relay) handshake(ctx context.Context, o RelayOptions) error {
	for {
		m, err := r.conn.Read(ctx)
		if err != nil {
			return fmt.Errorf("session: relay handshake: %w", err)
		}
		switch m.Event {
		case pusher.EvEstablished:
			if _, err := r.conn.Subscribe(ctx, m, o.Auth, o.Channel); err != nil {
				return fmt.Errorf("session: %w", err)
			}
		case pusher.EvSubscribed:
			if m.Channel == r.channel {
				return nil
			}
		case pusher.EvError, pusher.EvSubscriptionError:
			return fmt.Errorf("session: relay refused the subscription: %s", m.ErrorText())
		case pusher.EvPing:
			if err := r.conn.Pong(ctx); err != nil {
				return err
			}
		}
	}
}

func (r *Relay) readLoop(ctx context.Context) {
	defer close(r.events)
	for {
		m, err := r.conn.Read(ctx)
		if err != nil {
			r.fail(err)
			return
		}
		switch m.Event {
		case pusher.EvPing:
			_ = r.conn.Pong(ctx)
			continue
		case pusher.EvPong:
			select {
			case r.pong <- struct{}{}:
			default:
			}
			continue
		case pusher.EvError:
			r.fail(fmt.Errorf("session: relay error: %s", m.ErrorText()))
			return
		}
		if m.Channel != r.channel {
			continue
		}
		data, err := m.Payload()
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
		err := r.conn.Ping(ctx)
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

// SendFrame sends one frame as a client event.
func (r *Relay) SendFrame(ctx context.Context, f Frame) error {
	data, err := json.Marshal(f)
	if err != nil {
		return err
	}
	if err := r.conn.Write(ctx, pusher.Message{Event: FrameEvent, Channel: r.channel, Data: data}); err != nil {
		return fmt.Errorf("session: relay write: %w", err)
	}
	return nil
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
		_ = r.conn.Close("session ended")
	})
}
