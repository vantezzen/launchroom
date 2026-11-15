package controlplane

import (
	"context"
	"errors"
	"log/slog"
	"math/rand/v2"
	"net/http"
	"os"
	"sync/atomic"
	"time"

	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// StateSyncer keeps the persisted desired state up to date (docs/03 §5):
// it polls, follows nudges, accepts newer verified states and records the
// signed ones that don't decode or validate.
type StateSyncer struct {
	Client   *Client
	Store    *state.Store
	ServerID state.ServerID
	Log      *slog.Logger
	// OnNewState is called after a newer state was accepted.
	OnNewState func()
	// Nudge triggers an immediate fetch (realtime "state.changed" nudge).
	Nudge <-chan struct{}
	// Rejected is the latest refused NodeState (reported as stateRejected
	// until a newer state is accepted); OnRejected is called when it changes.
	Rejected   *atomic.Pointer[state.Rejection]
	OnRejected func()
	Now        func() time.Time
	// OnContact is called after every successful (verified) state fetch.
	OnContact func()
}

// Loop syncs every interval (±10%), on a nudge and on refresh (SIGHUP),
// until ctx ends.
func (s *StateSyncer) Loop(ctx context.Context, interval time.Duration, refresh <-chan os.Signal) error {
	for {
		s.SyncOnce(ctx)

		timer := time.NewTimer(Jitter(interval))
		select {
		case <-ctx.Done():
			timer.Stop()
			return nil
		case <-refresh:
			timer.Stop()
			s.Log.Info("SIGHUP: fetching desired state now")
		case <-s.Nudge:
			timer.Stop()
		case <-timer.C:
		}
	}
}

// SyncOnce fetches the state once. Errors are logged, never fatal: the
// daemon keeps running on its last verified state while the dashboard is
// unreachable.
func (s *StateSyncer) SyncOnce(ctx context.Context) {
	have := s.Store.Version()
	ss, modified, err := s.Client.FetchState(ctx, s.ServerID, have)
	if err == nil && s.OnContact != nil {
		s.OnContact()
	}
	switch {
	case ctx.Err() != nil:
		return
	case err != nil:
		var api *APIError
		var rej *state.RejectedError
		if errors.As(err, &rej) {
			s.reject(rej, have)
			return
		}
		if errors.As(err, &api) && api.Status == http.StatusForbidden {
			s.Log.Warn("dashboard refused the state request (server pending approval or revoked)", "err", err)
			return
		}
		s.Log.Warn("fetching desired state failed", "err", err, "have", have)
		return
	case !modified:
		s.Log.Debug("desired state unchanged", "version", have)
		return
	}
	if err := s.Store.Accept(ss); err != nil {
		if errors.Is(err, state.ErrStaleVersion) {
			s.Log.Warn("dashboard sent a desired state that is not newer; ignoring", "err", err)
			return
		}
		s.Log.Error("could not accept desired state", "err", err)
		return
	}
	s.Log.Info("desired state updated", "version", ss.State.Version, "kid", ss.KeyID, "workloads", len(ss.State.Workloads))
	if s.Rejected != nil {
		if r := s.Rejected.Load(); r != nil && r.Version <= ss.State.Version {
			s.Rejected.Store(nil)
			s.Log.Info("a newer desired state replaced the rejected one", "rejected", r.Version, "version", ss.State.Version)
			if s.OnRejected != nil {
				s.OnRejected()
			}
		}
	}
	if s.OnNewState != nil {
		s.OnNewState()
	}
}

// reject records a signed NodeState that does not decode or validate. The
// daemon keeps converging to its last good state; the rejection is
// reported (Report.stateRejected) and logged once per version.
func (s *StateSyncer) reject(rej *state.RejectedError, have int64) {
	if s.Rejected == nil {
		s.Log.Error("desired state rejected; keeping the last good state", "version", rej.Version, "have", have, "err", rej.Err)
		return
	}
	msg := state.Clip(rej.Err.Error(), state.MaxMessageLen)
	if prev := s.Rejected.Load(); prev != nil && prev.Version == rej.Version && prev.Error == msg {
		return
	}
	now := time.Now
	if s.Now != nil {
		now = s.Now
	}
	s.Rejected.Store(&state.Rejection{Version: rej.Version, Error: msg, RejectedAt: now().UTC()})
	s.Log.Error("desired state rejected; keeping the last good state", "version", rej.Version, "have", have, "err", rej.Err)
	if s.OnRejected != nil {
		s.OnRejected()
	}
}

// Jitter spreads d by ±10% so a fleet doesn't poll in lockstep.
func Jitter(d time.Duration) time.Duration {
	f := 0.9 + 0.2*rand.Float64() //nolint:gosec // jitter does not need a CSPRNG
	return time.Duration(float64(d) * f)
}
