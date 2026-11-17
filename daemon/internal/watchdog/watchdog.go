// Package watchdog is launchroomd's watch over the dashboard (docs/11,
// D49 addendum, D90): on the control-plane host it checks the dashboard's
// readiness and containers every 30 s and restarts a hung dashboard,
// Horizon, Reverb, scheduler or Zot (never Postgres or Valkey, at most 3
// per service and hour); on that host and on one peer server it sends a
// fallback notification when the dashboard stays unreachable, since the
// dashboard's own alerts can't. What it saw and did goes into
// Report.controlPlane once the dashboard is back.
package watchdog

import (
	"context"
	"errors"
	"fmt"
	"log/slog"
	"net/http"
	"net/url"
	"slices"
	"sync"
	"time"

	"github.com/vantezzen/launchroom/daemon/internal/secret"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// Mode is the host owner's local setting (LR_WATCHDOG).
type Mode string

// Modes.
const (
	// ModeOn: check, restart and notify (the default).
	ModeOn Mode = "on"
	// ModeNotify: check and notify, never restart anything.
	ModeNotify Mode = "notify"
	// ModeOff: nothing, whatever the dashboard asks.
	ModeOff Mode = "off"
)

// EnvMode is the local setting's environment variable.
const EnvMode = "LR_WATCHDOG"

// ParseMode reads LR_WATCHDOG ("" is on).
func ParseMode(v string) (Mode, error) {
	switch Mode(v) {
	case "", ModeOn:
		return ModeOn, nil
	case ModeNotify, ModeOff:
		return Mode(v), nil
	}
	return "", fmt.Errorf("%s must be on, notify or off, not %q", EnvMode, state.Shorten(v, 32))
}

// Thresholds (r-cp §4.2, OD6/OD7).
const (
	Interval = 30 * time.Second
	// badChecks consecutive failing checks restart a service.
	badChecks = 3
	// maxRestartsPerHour per service.
	maxRestartsPerHour = 3
	// startGrace: a container that started this recently is left alone.
	startGrace = 2 * time.Minute
	// downAfter: the control-plane host notifies after the dashboard was
	// unreachable this long; downAfterPeer on the peer.
	downAfter     = 5 * time.Minute
	downAfterPeer = 10 * time.Minute
	// stillDownEvery repeats the notice while it stays down.
	stillDownEvery = 6 * time.Hour
	// retrySendAfter: a failed send is tried again after this.
	retrySendAfter = 5 * time.Minute
	readyTimeout   = 5 * time.Second
	maxRestarts    = 64 // state.ControlPlaneHealth limits
	maxNotices     = 32
	maxErrorLen    = 512
)

// Options configures a Watchdog.
type Options struct {
	// Engine reaches the control-plane containers (nil: none on this host).
	Engine Engine
	// ReadyURL is the dashboard's loopback readiness endpoint
	// ("http://127.0.0.1:8000/up/ready"); answering 404 it falls back to
	// "/up" on the same origin. "": no readiness check.
	ReadyURL string
	// Spec is the verified NodeState's system.watchdog (nil: off).
	Spec func() *state.WatchdogSpec
	// Open opens the fallback channel's sealed secret.
	Open func(state.Sealed) (map[string]secret.Secret, error)
	// LastContact is the last verified exchange with the dashboard (zero: none yet).
	LastContact func() time.Time
	// DashboardHost names the dashboard in messages (display only).
	DashboardHost string
	// Mode is the local setting.
	Mode Mode
	// DataDir holds watchdog.json (restart history, notice state).
	DataDir string
	// Egress sends fallback messages (zero value: the system's network).
	Egress Egress
	// ReadyClient checks readiness (nil: a plain loopback client).
	ReadyClient *http.Client
	Now         func() time.Time
	Log         *slog.Logger
	// OnChange is called when Pending changed (a report should go out).
	OnChange func()
}

// Watchdog watches the dashboard. Safe for concurrent use.
type Watchdog struct {
	o       Options
	started time.Time

	mu        sync.Mutex
	persisted persisted
	checkedAt time.Time
	ready     *bool
	services  []state.ControlPlaneServiceStatus
	bad       map[state.ControlPlaneService]int
	// readyFailingSince is when the readiness check started failing.
	readyFailingSince time.Time
	// useUp: the readiness endpoint answered 404; /up is used instead.
	useUp bool
}

// New returns a Watchdog with its persisted state loaded.
func New(o Options) *Watchdog {
	if o.Now == nil {
		o.Now = time.Now
	}
	if o.Log == nil {
		o.Log = slog.New(slog.DiscardHandler)
	}
	if o.Mode == "" {
		o.Mode = ModeOn
	}
	if o.Spec == nil {
		o.Spec = func() *state.WatchdogSpec { return nil }
	}
	if o.LastContact == nil {
		o.LastContact = func() time.Time { return time.Time{} }
	}
	if o.OnChange == nil {
		o.OnChange = func() {}
	}
	if o.ReadyClient == nil {
		o.ReadyClient = &http.Client{
			Timeout:       readyTimeout,
			Transport:     &http.Transport{Proxy: nil, DisableKeepAlives: true},
			CheckRedirect: func(*http.Request, []*http.Request) error { return http.ErrUseLastResponse },
		}
	}
	w := &Watchdog{o: o, started: o.Now(), bad: map[state.ControlPlaneService]int{}}
	p, err := load(o.DataDir)
	if err != nil {
		o.Log.Warn("watchdog state unreadable; starting fresh", "err", err)
	}
	w.persisted = p
	return w
}

// Run checks every 30 s until ctx ends.
func (w *Watchdog) Run(ctx context.Context) {
	t := time.NewTicker(Interval)
	defer t.Stop()
	for {
		w.Check(ctx)
		select {
		case <-ctx.Done():
			return
		case <-t.C:
		}
	}
}

// active returns the spec when the watchdog should run.
func (w *Watchdog) active() *state.WatchdogSpec {
	if w.o.Mode == ModeOff {
		return nil
	}
	spec := w.o.Spec()
	if spec == nil || spec.Role.Validate() != nil {
		return nil
	}
	return spec
}

// Check runs one round (exported for tests and a manual kick).
func (w *Watchdog) Check(ctx context.Context) {
	spec := w.active()
	if spec == nil {
		w.mu.Lock()
		changed := !w.checkedAt.IsZero()
		w.checkedAt, w.ready, w.services = time.Time{}, nil, nil
		w.mu.Unlock()
		if changed {
			w.o.OnChange()
		}
		return
	}
	changed := false
	readyKnown, readyOK, unresponsive := false, false, false
	if spec.Role == state.WatchdogControlPlane && w.o.ReadyURL != "" {
		readyKnown = true
		readyOK, unresponsive = w.checkReady(ctx)
	}
	var containers []Container
	var listErr error
	if spec.Role == state.WatchdogControlPlane && w.o.Engine != nil {
		cctx, cancel := context.WithTimeout(ctx, 20*time.Second)
		containers, listErr = w.o.Engine.Containers(cctx)
		cancel()
		if listErr != nil && ctx.Err() == nil {
			w.o.Log.Warn("watchdog: control-plane containers not readable", "err", listErr)
		}
	}
	now := w.o.Now().UTC()

	w.mu.Lock()
	w.checkedAt = now
	if readyKnown {
		r := readyOK
		if w.ready == nil || *w.ready != r {
			changed = true
		}
		w.ready = &r
		if readyOK {
			w.readyFailingSince = time.Time{}
		} else if w.readyFailingSince.IsZero() {
			w.readyFailingSince = now
		}
	} else {
		w.ready = nil
	}
	var restarts []Container
	var reasons []state.WatchdogRestartReason
	if spec.Role == state.WatchdogControlPlane && w.o.Engine != nil && listErr == nil {
		services := statuses(containers)
		if !slices.Equal(services, w.services) {
			changed = true
		}
		w.services = services
		restarts, reasons = w.decideRestarts(containers, unresponsive, now)
	} else if spec.Role != state.WatchdogControlPlane {
		w.services = nil
	}
	w.mu.Unlock()

	for i, c := range restarts {
		if w.restart(ctx, c, reasons[i], now) {
			changed = true
		}
	}
	if w.notify(ctx, spec, readyKnown && !readyOK, now) {
		changed = true
	}
	if changed {
		w.o.OnChange()
	}
}

// checkReady asks the dashboard's readiness endpoint: ok on 200;
// unresponsive when it didn't answer at all (a hung web server).
func (w *Watchdog) checkReady(ctx context.Context) (ok, unresponsive bool) {
	w.mu.Lock()
	target := w.o.ReadyURL
	if w.useUp {
		target = upURL(target)
	}
	w.mu.Unlock()
	status, err := w.get(ctx, target)
	if err != nil {
		return false, true
	}
	if status == http.StatusNotFound && target == w.o.ReadyURL {
		w.mu.Lock()
		w.useUp = true
		w.mu.Unlock()
		if status, err = w.get(ctx, upURL(target)); err != nil {
			return false, true
		}
	}
	return status == http.StatusOK, false
}

func (w *Watchdog) get(ctx context.Context, target string) (int, error) {
	ctx, cancel := context.WithTimeout(ctx, readyTimeout)
	defer cancel()
	req, err := http.NewRequestWithContext(ctx, http.MethodGet, target, nil)
	if err != nil {
		return 0, err
	}
	req.Header.Set("User-Agent", "launchroomd-watchdog")
	res, err := w.o.ReadyClient.Do(req)
	if err != nil {
		return 0, err
	}
	_ = res.Body.Close()
	return res.StatusCode, nil
}

func upURL(ready string) string {
	u, err := url.Parse(ready)
	if err != nil {
		return ready
	}
	u.Path, u.RawQuery = "/up", ""
	return u.String()
}

// statuses are the services in canonical order; a service without a
// container is "missing".
func statuses(cs []Container) []state.ControlPlaneServiceStatus {
	var out []state.ControlPlaneServiceStatus
	for _, svc := range allServices {
		i := slices.IndexFunc(cs, func(c Container) bool { return c.Service == svc })
		if i < 0 {
			out = append(out, state.ControlPlaneServiceStatus{Name: svc, State: state.ServiceMissing})
			continue
		}
		c := cs[i]
		out = append(out, state.ControlPlaneServiceStatus{Name: svc, State: c.State, StartedAt: c.StartedAt, Restarts: max(c.Restarts, 0), OOMKilled: c.OOMKilled})
	}
	return out
}

var allServices = []state.ControlPlaneService{
	state.CPDashboard, state.CPHorizon, state.CPReverb, state.CPScheduler, state.CPZot, state.CPPostgres, state.CPValkey,
}

// stopExits are exit codes of a deliberate stop (SIGTERM/SIGINT/SIGKILL
// after the grace): a service stopped like that was stopped by someone.
var stopExits = []int{0, 130, 137, 143}

// badReason says why a container counts as failing this round ("" = fine).
func badReason(c Container, dashboardUnresponsive bool) state.WatchdogRestartReason {
	switch c.State {
	case state.ServiceUnhealthy:
		return state.RestartUnhealthy
	case state.ServiceStopped:
		// Docker's restart policy restarts crashes; a container still
		// stopped after a crash (or killed by the kernel) is started again.
		// One someone stopped on purpose stays stopped.
		if c.OOMKilled || !slices.Contains(stopExits, c.ExitCode) {
			return state.RestartExited
		}
	case state.ServiceRunning:
		if c.Service == state.CPDashboard && dashboardUnresponsive {
			return state.RestartNotReady
		}
	}
	return ""
}

// decideRestarts counts failing checks and returns the containers to
// restart now (w.mu held).
func (w *Watchdog) decideRestarts(cs []Container, unresponsive bool, now time.Time) ([]Container, []state.WatchdogRestartReason) {
	var out []Container
	var reasons []state.WatchdogRestartReason
	seen := map[state.ControlPlaneService]bool{}
	for _, c := range cs {
		if seen[c.Service] {
			continue
		}
		seen[c.Service] = true
		reason := badReason(c, unresponsive)
		if reason == "" || !restartable(c.Service) {
			w.bad[c.Service] = 0
			continue
		}
		w.bad[c.Service]++
		if w.bad[c.Service] < badChecks || w.o.Mode != ModeOn {
			continue
		}
		if c.StartedAt != nil && now.Sub(*c.StartedAt) < startGrace {
			continue
		}
		if w.persisted.restartsWithin(c.Service, now, time.Hour) >= maxRestartsPerHour {
			continue
		}
		out = append(out, c)
		reasons = append(reasons, reason)
	}
	for svc := range w.bad {
		if !seen[svc] {
			w.bad[svc] = 0
		}
	}
	return out, reasons
}

// restart restarts one container and records it (counted against the cap
// even when Docker refused, so a refusal can't loop).
func (w *Watchdog) restart(ctx context.Context, c Container, reason state.WatchdogRestartReason, now time.Time) bool {
	if !restartable(c.Service) {
		return false // never the databases, whatever the caller says
	}
	w.o.Log.Warn("watchdog: restarting a control-plane service", "service", c.Service, "reason", reason)
	rctx, cancel := context.WithTimeout(ctx, stopGrace(c.Service)+30*time.Second)
	err := w.o.Engine.Restart(rctx, c.ID, stopGrace(c.Service))
	cancel()
	w.mu.Lock()
	defer w.mu.Unlock()
	w.bad[c.Service] = 0
	w.persisted.History = append(w.persisted.History, restartMark{Service: c.Service, At: now})
	w.persisted.prune(now)
	if err != nil {
		w.o.Log.Warn("watchdog: restart failed", "service", c.Service, "err", err)
		w.save()
		return false
	}
	w.persisted.Restarts = appendBounded(w.persisted.Restarts, state.WatchdogRestart{Service: c.Service, At: now, Reason: reason}, maxRestarts)
	w.save()
	return true
}

// down reports whether the dashboard counts as unreachable for the role.
func (w *Watchdog) down(role state.WatchdogRole, readyFailing bool, now time.Time) bool {
	contact := w.o.LastContact()
	if contact.IsZero() {
		contact = w.started
	}
	if role == state.WatchdogPeer {
		return now.Sub(contact) >= downAfterPeer
	}
	w.mu.Lock()
	since := w.readyFailingSince
	w.mu.Unlock()
	if readyFailing && !since.IsZero() && now.Sub(since) >= downAfter {
		return true
	}
	return now.Sub(contact) >= downAfter
}

// notify sends the fallback notices the state calls for.
func (w *Watchdog) notify(ctx context.Context, spec *state.WatchdogSpec, readyFailing bool, now time.Time) bool {
	down := w.down(spec.Role, readyFailing, now)
	w.mu.Lock()
	n := w.persisted.Notice
	w.mu.Unlock()
	var kind state.FallbackNoticeKind
	switch {
	case down && !n.Down:
		kind = state.NoticeDown
	case down && now.Sub(n.SentAt) >= stillDownEvery:
		kind = state.NoticeStillDown
	case !down && n.Down:
		kind = state.NoticeBack
	default:
		return false
	}
	if spec.Fallback == nil {
		// Nothing to send to; keep the episode state so "back" isn't sent
		// to a channel configured mid-outage that never got "down".
		w.mu.Lock()
		w.persisted.Notice = noticeState{Down: down, SentAt: now}
		w.save()
		w.mu.Unlock()
		return false
	}
	if !n.LastTry.At.IsZero() && n.LastTry.Kind == kind && now.Sub(n.LastTry.At) < retrySendAfter {
		return false
	}
	err := w.send(ctx, spec, kind, now)
	notice := state.FallbackNotice{Kind: kind, At: now, Sent: err == nil}
	if err != nil {
		var se sendError
		msg := "the message could not be sent"
		if errors.As(err, &se) {
			msg = string(se)
		}
		notice.Error = state.Shorten(msg, maxErrorLen)
		w.o.Log.Warn("watchdog: fallback notification not sent", "notice", kind, "reason", notice.Error)
	} else {
		w.o.Log.Info("watchdog: fallback notification sent", "notice", kind)
	}
	w.mu.Lock()
	defer w.mu.Unlock()
	ps := &w.persisted
	if l := len(ps.Notices); l > 0 && !ps.Notices[l-1].Sent && ps.Notices[l-1].Kind == kind {
		ps.Notices[l-1] = notice // one entry per failing attempt series
	} else {
		ps.Notices = appendBounded(ps.Notices, notice, maxNotices)
	}
	if err == nil {
		ps.Notice = noticeState{Down: down, SentAt: now}
	} else {
		ps.Notice.LastTry = attempt{Kind: kind, At: now}
	}
	w.save()
	return true
}

func (w *Watchdog) send(ctx context.Context, spec *state.WatchdogSpec, kind state.FallbackNoticeKind, now time.Time) error {
	if w.o.Open == nil {
		return errChannel
	}
	values, err := w.o.Open(spec.Fallback.Secret)
	if err != nil {
		return errChannel
	}
	return w.o.Egress.send(ctx, spec.Fallback, values, notification{Role: spec.Role, Kind: kind, DashboardHost: w.o.DashboardHost, At: now})
}

// Pending is Report.controlPlane: the last check plus the restarts and
// notices not delivered yet (nil when the watchdog is off or hasn't run).
func (w *Watchdog) Pending() *state.ControlPlaneHealth {
	w.mu.Lock()
	defer w.mu.Unlock()
	if w.checkedAt.IsZero() {
		return nil
	}
	h := &state.ControlPlaneHealth{
		CheckedAt: w.checkedAt,
		Services:  slices.Clone(w.services),
		Restarts:  slices.Clone(w.persisted.Restarts),
		Notices:   slices.Clone(w.persisted.Notices),
	}
	if w.ready != nil {
		r := *w.ready
		h.Ready = &r
	}
	return h
}

// Ack drops the restarts and notices a delivered report carried.
func (w *Watchdog) Ack(h *state.ControlPlaneHealth) {
	if h == nil || len(h.Restarts) == 0 && len(h.Notices) == 0 {
		return
	}
	w.mu.Lock()
	defer w.mu.Unlock()
	ps := &w.persisted
	ps.Restarts = slices.DeleteFunc(ps.Restarts, func(r state.WatchdogRestart) bool {
		return slices.ContainsFunc(h.Restarts, func(d state.WatchdogRestart) bool { return d.Service == r.Service && d.At.Equal(r.At) })
	})
	ps.Notices = slices.DeleteFunc(ps.Notices, func(n state.FallbackNotice) bool {
		return slices.ContainsFunc(h.Notices, func(d state.FallbackNotice) bool { return d.Kind == n.Kind && d.At.Equal(n.At) && d.Sent == n.Sent })
	})
	w.save()
}

func (w *Watchdog) save() {
	if err := w.persisted.save(w.o.DataDir); err != nil {
		w.o.Log.Warn("watchdog state not saved", "err", err)
	}
}

func appendBounded[T any](s []T, v T, limit int) []T {
	s = append(s, v)
	if over := len(s) - limit; over > 0 {
		s = slices.Delete(s, 0, over)
	}
	return s
}
