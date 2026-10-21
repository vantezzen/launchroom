// Package nudge receives the dashboard's realtime "poll now" nudges over a
// Pusher-protocol WebSocket (Laravel Reverb, docs/03 §4).
//
// The daemon subscribes to its private channel "private-daemon.<server id>";
// the channel authorization comes from the signed POST
// /daemon/v1/broadcasting/auth. Nudges are content-free: an event only makes
// the daemon fetch state or lease tasks through the signed HTTP channel right
// away. Nothing received on the socket is trusted beyond that, so a forged or
// replayed nudge costs one extra (signed, verified) poll. Polling keeps
// running regardless; this only cuts latency.
package nudge

import (
	"context"
	"encoding/json/jsontext"
	"encoding/json/v2"
	"errors"
	"fmt"
	"log/slog"
	"math/rand/v2"
	"net/http"
	"net/url"
	"strings"
	"time"

	"github.com/coder/websocket"

	"github.com/vantezzen/launchroom/daemon/internal/controlplane"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// Pusher protocol (https://pusher.com/docs/channels/library_auth_reference/pusher-websockets-protocol/).
const (
	protocolVersion         = "7"
	evConnectionEstablished = "pusher:connection_established"
	evError                 = "pusher:error"
	evPing                  = "pusher:ping"
	evPong                  = "pusher:pong"
	evSubscribe             = "pusher:subscribe"
	evSubscribed            = "pusher_internal:subscription_succeeded"
	evSubscriptionError     = "pusher:subscription_error"

	readLimit        = 64 << 10
	handshakeTimeout = 15 * time.Second
	writeTimeout     = 10 * time.Second
	pongTimeout      = 30 * time.Second
	// healthyAfter: a connection that lasted this long resets the backoff.
	healthyAfter = 2 * time.Minute
)

// ControlPlane is the subset of the control-plane client the nudge client uses.
type ControlPlane interface {
	FetchConfig(ctx context.Context) (*state.DaemonConfig, error)
	BroadcastAuth(ctx context.Context, socketID state.SocketID, channel state.DaemonChannel) (state.PusherAuth, error)
}

// Options configures Run.
type Options struct {
	CP ControlPlane
	// ControlPlaneURL derives the WebSocket URL when the config names none.
	ControlPlaneURL *url.URL
	// ControlPlaneBase, when set, is asked before every connection and wins
	// over ControlPlaneURL: the client's active base URL, so nudges follow an
	// adopted canonical origin and its failover (docs/03 §4.2).
	ControlPlaneBase func() *url.URL
	ServerID         state.ServerID
	// Fallback is the nudge config from enrollment, used when the dashboard
	// has no config endpoint (404).
	Fallback *state.NudgeConfig
	// OnEvent is called for every nudge event on the daemon's channel.
	OnEvent func(event string)
	// OnSubscribed is called after every (re)subscription: nudges sent while
	// disconnected are lost, so the daemon catches up by polling once.
	OnSubscribed  func()
	DaemonVersion string
	Log           *slog.Logger
	// HTTPClient dials the WebSocket (default: a client with TLS ≥ 1.2).
	HTTPClient *http.Client
	// BackoffMin/BackoffMax bound the reconnect backoff (default 1 s / 60 s, full jitter).
	BackoffMin, BackoffMax time.Duration
	// ConfigRetry is how often to ask again while nudges are off (default 5 min).
	ConfigRetry time.Duration
	Now         func() time.Time
}

// controlPlane is the base URL realtime endpoints derive from.
func (o *Options) controlPlane() *url.URL {
	if o.ControlPlaneBase != nil {
		if u := o.ControlPlaneBase(); u != nil {
			return u
		}
	}
	return o.ControlPlaneURL
}

func (o *Options) defaults() {
	if o.Log == nil {
		o.Log = slog.New(slog.DiscardHandler)
	}
	if o.BackoffMin <= 0 {
		o.BackoffMin = time.Second
	}
	if o.BackoffMax <= 0 {
		o.BackoffMax = time.Minute
	}
	if o.ConfigRetry <= 0 {
		o.ConfigRetry = 5 * time.Minute
	}
	if o.OnEvent == nil {
		o.OnEvent = func(string) {}
	}
	if o.OnSubscribed == nil {
		o.OnSubscribed = func() {}
	}
	if o.Now == nil {
		o.Now = time.Now
	}
	if o.HTTPClient == nil {
		o.HTTPClient = &http.Client{Transport: controlplane.DefaultTransport()}
	}
}

// Run keeps a nudge subscription alive until ctx is done, reconnecting with
// jittered exponential backoff. It never returns an error: without nudges the
// daemon simply polls.
func Run(ctx context.Context, o Options) {
	o.defaults()
	backoff := o.BackoffMin
	for ctx.Err() == nil {
		cfg, unreachable := resolveConfig(ctx, o)
		if cfg == nil {
			// Nudges off: ask again later. Dashboard unreachable (e.g. the
			// control plane restarting): retry with the connection backoff,
			// not the long "off" interval, or nudges stay off for minutes
			// after it is back and every change waits for a poll.
			wait := o.ConfigRetry
			if unreachable {
				wait = fullJitter(backoff)
				backoff = min(backoff*2, o.BackoffMax)
			}
			if sleep(ctx, wait) != nil {
				return
			}
			continue
		}
		started := o.Now()
		err := session(ctx, o, cfg)
		if ctx.Err() != nil {
			return
		}
		if o.Now().Sub(started) >= healthyAfter {
			backoff = o.BackoffMin
		}
		wait := fullJitter(backoff)
		o.Log.Warn("nudge connection lost; polling continues", "err", err, "retryIn", wait.Round(time.Millisecond).String())
		if sleep(ctx, wait) != nil {
			return
		}
		backoff = min(backoff*2, o.BackoffMax)
	}
}

// resolveConfig asks the dashboard for the nudge settings. A dashboard
// without the endpoint (404) or an unreachable one falls back to the
// enrollment value; nil means nudges are off, or (unreachable = true) that
// the dashboard could not be asked and there is no fallback.
func resolveConfig(ctx context.Context, o Options) (nudge *state.NudgeConfig, unreachable bool) {
	cfg, err := o.CP.FetchConfig(ctx)
	if err == nil {
		if cfg.Nudge == nil {
			o.Log.Debug("realtime nudges are off; polling only")
		}
		return cfg.Nudge, false
	}
	var api *controlplane.APIError
	notFound := errors.As(err, &api) && api.Status == http.StatusNotFound
	if ctx.Err() == nil && !notFound {
		o.Log.Debug("fetching daemon config failed", "err", err)
	}
	return o.Fallback, !notFound
}

// Endpoint returns the Pusher WebSocket URL for cfg.
func Endpoint(cfg *state.NudgeConfig, controlPlane *url.URL, daemonVersion string) (string, error) {
	var base *url.URL
	if cfg.URL != "" {
		u, err := url.Parse(string(cfg.URL))
		if err != nil {
			return "", fmt.Errorf("nudge: %w", err)
		}
		base = u
	} else {
		if controlPlane == nil {
			return "", errors.New("nudge: no WebSocket URL and no control-plane URL")
		}
		u := *controlPlane
		switch u.Scheme {
		case "https":
			u.Scheme = "wss"
		case "http":
			u.Scheme = "ws"
		default:
			return "", fmt.Errorf("nudge: cannot derive a WebSocket URL from %q", controlPlane.Scheme)
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

// message is a Pusher protocol frame. Data is a JSON string for server
// events (Pusher double-encodes) or an object for client events.
type message struct {
	Event   string         `json:"event"`
	Channel string         `json:"channel,omitzero"`
	Data    jsontext.Value `json:"data,omitzero"`
}

// dataObject decodes Data, accepting both the string-encoded and the plain form.
func (m message) dataObject(v any) error {
	raw := m.Data
	if len(raw) > 0 && raw[0] == '"' {
		var s string
		if err := json.Unmarshal(raw, &s); err != nil {
			return err
		}
		raw = jsontext.Value(s)
	}
	return json.Unmarshal(raw, v)
}

type established struct {
	SocketID        string `json:"socket_id"`
	ActivityTimeout int    `json:"activity_timeout"`
}

type pusherError struct {
	Code    int    `json:"code"`
	Message string `json:"message"`
}

// session runs one connection: connect, authorize, subscribe, then dispatch
// events and keep the connection alive with pings until it fails.
func session(ctx context.Context, o Options, cfg *state.NudgeConfig) error {
	cp := o.controlPlane()
	endpoint, err := Endpoint(cfg, cp, o.DaemonVersion)
	if err != nil {
		return err
	}
	hctx, cancel := context.WithTimeout(ctx, handshakeTimeout)
	h := http.Header{}
	if cp != nil {
		h.Set("Origin", cp.Scheme+"://"+cp.Host)
	}
	conn, resp, err := websocket.Dial(hctx, endpoint, &websocket.DialOptions{HTTPClient: o.HTTPClient, HTTPHeader: h})
	cancel()
	if resp != nil && resp.Body != nil {
		_ = resp.Body.Close() // the library owns the connection; this only satisfies bodyclose
	}
	if err != nil {
		return fmt.Errorf("nudge: connect: %w", err)
	}
	defer func() { _ = conn.CloseNow() }()
	conn.SetReadLimit(readLimit)

	msgs := make(chan message, 16)
	readErr := make(chan error, 1)
	rctx, stopRead := context.WithCancel(ctx)
	defer stopRead()
	go func() {
		for {
			_, data, err := conn.Read(rctx)
			if err != nil {
				readErr <- err
				return
			}
			var m message
			if err := json.Unmarshal(data, &m); err != nil {
				readErr <- fmt.Errorf("nudge: malformed frame: %w", err)
				return
			}
			select {
			case msgs <- m:
			case <-rctx.Done():
				return
			}
		}
	}()

	s := &sess{o: o, conn: conn, channel: state.ChannelFor(o.ServerID)}
	idle := 30 * time.Second
	timer := time.NewTimer(handshakeTimeout)
	defer timer.Stop()
	awaitingPong := false
	for {
		select {
		case <-ctx.Done():
			_ = conn.Close(websocket.StatusNormalClosure, "daemon stopping")
			return nil
		case err := <-readErr:
			return fmt.Errorf("nudge: read: %w", err)
		case <-timer.C:
			switch {
			case !s.subscribed:
				return errors.New("nudge: no subscription confirmation in time")
			case awaitingPong:
				return errors.New("nudge: no answer to ping")
			}
			if err := s.write(ctx, message{Event: evPing, Data: jsontext.Value(`{}`)}); err != nil {
				return err
			}
			awaitingPong = true
			timer.Reset(pongTimeout)
		case m := <-msgs:
			awaitingPong = false
			activity, err := s.handle(ctx, m)
			if err != nil {
				return err
			}
			if activity > 0 {
				idle = activity
			}
			// Until the subscription is confirmed the handshake deadline stays.
			if s.subscribed {
				timer.Reset(idle)
			}
		}
	}
}

type sess struct {
	o          Options
	conn       *websocket.Conn
	channel    state.DaemonChannel
	subscribed bool
}

func (s *sess) write(ctx context.Context, m message) error {
	data, err := json.Marshal(m)
	if err != nil {
		return err
	}
	wctx, cancel := context.WithTimeout(ctx, writeTimeout)
	defer cancel()
	if err := s.conn.Write(wctx, websocket.MessageText, data); err != nil {
		return fmt.Errorf("nudge: write: %w", err)
	}
	return nil
}

// handle processes one frame; it returns the server's activity timeout when
// the frame announces one.
func (s *sess) handle(ctx context.Context, m message) (time.Duration, error) {
	switch m.Event {
	case evConnectionEstablished:
		var est established
		if err := m.dataObject(&est); err != nil {
			return 0, fmt.Errorf("nudge: connection_established: %w", err)
		}
		socketID := state.SocketID(est.SocketID)
		if err := socketID.Validate(); err != nil {
			return 0, fmt.Errorf("nudge: %w", err)
		}
		auth, err := s.o.CP.BroadcastAuth(ctx, socketID, s.channel)
		if err != nil {
			return 0, fmt.Errorf("nudge: channel authorization: %w", err)
		}
		sub, err := json.Marshal(map[string]string{"auth": string(auth), "channel": string(s.channel)})
		if err != nil {
			return 0, err
		}
		if err := s.write(ctx, message{Event: evSubscribe, Data: sub}); err != nil {
			return 0, err
		}
		// Ping a little before the server would consider us idle.
		return activityInterval(est.ActivityTimeout), nil
	case evSubscribed:
		if m.Channel == string(s.channel) && !s.subscribed {
			s.subscribed = true
			s.o.Log.Info("realtime nudges connected", "channel", s.channel)
			s.o.OnSubscribed()
		}
	case evPing:
		return 0, s.write(ctx, message{Event: evPong, Data: jsontext.Value(`{}`)})
	case evPong:
	case evError, evSubscriptionError:
		var pe pusherError
		_ = m.dataObject(&pe)
		return 0, fmt.Errorf("nudge: server error %d: %s", pe.Code, clip(pe.Message))
	case state.NudgeStateChanged, state.NudgeTaskAvailable, state.NudgeLogsRequested:
		if m.Channel == string(s.channel) && s.subscribed {
			s.o.Log.Debug("nudge received", "event", m.Event)
			s.o.OnEvent(m.Event)
		}
	default:
		s.o.Log.Debug("ignoring nudge frame", "event", clip(m.Event))
	}
	return 0, nil
}

// activityInterval converts Pusher's activity_timeout (seconds) into the
// client's idle interval before it pings, clamped to 10–120 s.
func activityInterval(seconds int) time.Duration {
	d := time.Duration(seconds) * time.Second
	if d <= 0 {
		d = 30 * time.Second
	}
	return min(max(d-2*time.Second, 10*time.Second), 120*time.Second)
}

func clip(s string) string {
	s = strings.Map(func(r rune) rune {
		if r < 0x20 || r == 0x7f {
			return -1
		}
		return r
	}, s)
	if len(s) > 200 {
		return s[:200]
	}
	return s
}

// fullJitter returns a random duration in [d/2, d].
func fullJitter(d time.Duration) time.Duration {
	return d/2 + time.Duration(rand.Int64N(int64(d/2)+1)) //nolint:gosec // jitter does not need a CSPRNG
}

func sleep(ctx context.Context, d time.Duration) error {
	t := time.NewTimer(d)
	defer t.Stop()
	select {
	case <-ctx.Done():
		return ctx.Err()
	case <-t.C:
		return nil
	}
}
