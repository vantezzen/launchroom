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
	"errors"
	"fmt"
	"log/slog"
	"math/rand/v2"
	"net/http"
	"net/url"
	"time"

	"github.com/vantezzen/launchroom/daemon/internal/controlplane"
	"github.com/vantezzen/launchroom/daemon/internal/pusher"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

const (
	handshakeTimeout = 15 * time.Second
	pongTimeout      = 30 * time.Second
	// healthyAfter: a connection that lasted this long resets the backoff.
	healthyAfter = 2 * time.Minute
)

// ControlPlane is the subset of the control-plane client the nudge client uses.
type ControlPlane interface {
	FetchConfig(ctx context.Context) (*state.DaemonConfig, error)
	pusher.Authorizer
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

// session runs one connection: connect, authorize, subscribe, then dispatch
// events and keep the connection alive with pings until it fails.
func session(ctx context.Context, o Options, cfg *state.NudgeConfig) error {
	cp := o.controlPlane()
	endpoint, err := pusher.Endpoint(cfg, cp, o.DaemonVersion)
	if err != nil {
		return err
	}
	hctx, cancel := context.WithTimeout(ctx, handshakeTimeout)
	conn, err := pusher.Dial(hctx, endpoint, cp, o.HTTPClient)
	cancel()
	if err != nil {
		return fmt.Errorf("nudge: connect: %w", err)
	}
	defer func() { _ = conn.CloseNow() }()

	msgs := make(chan pusher.Message, 16)
	readErr := make(chan error, 1)
	rctx, stopRead := context.WithCancel(ctx)
	defer stopRead()
	go func() {
		for {
			m, err := conn.Read(rctx)
			if err != nil {
				readErr <- err
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
			_ = conn.Close("daemon stopping")
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
			if err := conn.Ping(ctx); err != nil {
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
	conn       *pusher.Conn
	channel    state.DaemonChannel
	subscribed bool
}

// handle processes one frame; it returns the server's activity timeout when
// the frame announces one.
func (s *sess) handle(ctx context.Context, m pusher.Message) (time.Duration, error) {
	switch m.Event {
	case pusher.EvEstablished:
		activity, err := s.conn.Subscribe(ctx, m, s.o.CP, s.channel)
		if err != nil {
			return 0, fmt.Errorf("nudge: %w", err)
		}
		// Ping a little before the server would consider us idle.
		return activityInterval(activity), nil
	case pusher.EvSubscribed:
		if m.Channel == string(s.channel) && !s.subscribed {
			s.subscribed = true
			s.o.Log.Info("realtime nudges connected", "channel", s.channel)
			s.o.OnSubscribed()
		}
	case pusher.EvPing:
		return 0, s.conn.Pong(ctx)
	case pusher.EvPong:
	case pusher.EvError, pusher.EvSubscriptionError:
		return 0, fmt.Errorf("nudge: server error %s", m.ErrorText())
	case state.NudgeStateChanged, state.NudgeTaskAvailable, state.NudgeLogsRequested:
		if m.Channel == string(s.channel) && s.subscribed {
			s.o.Log.Debug("nudge received", "event", m.Event)
			s.o.OnEvent(m.Event)
		}
	default:
		s.o.Log.Debug("ignoring nudge frame", "event", state.ClipLine(m.Event, 200))
	}
	return 0, nil
}

// activityInterval converts Pusher's activity_timeout into the client's
// idle interval before it pings, clamped to 10–120 s.
func activityInterval(d time.Duration) time.Duration {
	if d <= 0 {
		d = 30 * time.Second
	}
	return min(max(d-2*time.Second, 10*time.Second), 120*time.Second)
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
