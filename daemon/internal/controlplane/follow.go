package controlplane

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"log/slog"
	"net/url"
	"os"
	"path/filepath"
	"sync"
	"time"

	"github.com/vantezzen/launchroom/daemon/internal/atomicfile"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// FollowFile is where the adopted canonical origin is kept across restarts
// (inside the data dir).
const FollowFile = "control-plane.json"

// Verification backoff for a pushed origin that does not verify yet.
const (
	followRetryMin = time.Minute
	followRetryMax = 10 * time.Minute
	followTimeout  = 30 * time.Second
)

// FollowOptions configures a Follower.
type FollowOptions struct {
	Client *Client
	// DataDir holds FollowFile.
	DataDir string
	// Off is the local policy LR_CONTROL_PLANE_FOLLOW=off.
	Off bool
	// OnChange is called after the adopted origin changed (forward-auth
	// origins and realtime endpoints follow it).
	OnChange func()
	Log      *slog.Logger
	Now      func() time.Time
}

// Follower moves launchroomd to the dashboard's canonical origin from the
// signed NodeState (system.controlPlane, docs/03 §4.2) once that origin
// proves it is the same control plane (a response signed by the pinned
// keys). The configured LR_CONTROL_PLANE_URL stays the fallback forever.
type Follower struct {
	o    FollowOptions
	kick chan struct{}

	mu      sync.Mutex
	want    state.ControlPlaneOrigin // latest system.controlPlane.url
	adopted state.ControlPlaneOrigin // verified, preferred by the client
	pending string                   // why want is not adopted yet ("" when it is)
	nextTry time.Time
	backoff time.Duration
}

// NewFollower returns a Follower; call Load once at start and Run in the background.
func NewFollower(o FollowOptions) *Follower {
	if o.Log == nil {
		o.Log = slog.New(slog.DiscardHandler)
	}
	if o.Now == nil {
		o.Now = time.Now
	}
	if o.OnChange == nil {
		o.OnChange = func() {}
	}
	return &Follower{o: o, kick: make(chan struct{}, 1)}
}

type followRecord struct {
	URL        state.ControlPlaneOrigin `json:"url"`
	VerifiedAt time.Time                `json:"verifiedAt"`
}

func (f *Follower) path() string { return filepath.Join(f.o.DataDir, FollowFile) }

// refusal is why the local policy does not follow ("" = it may).
func (f *Follower) refusal() string {
	switch {
	case f.o.Off:
		return "LR_CONTROL_PLANE_FOLLOW=off: staying on the configured URL"
	case state.IsLoopbackHost(f.o.Client.base):
		return "the configured URL is this host itself: staying on it"
	}
	return ""
}

// sameAsConfigured reports whether origin is the configured URL's origin.
func (f *Follower) sameAsConfigured(origin state.ControlPlaneOrigin) bool {
	u, err := origin.URL()
	if err != nil {
		return false
	}
	return state.OriginOf(u) == state.OriginOf(f.o.Client.base) && (f.o.Client.base.Path == "" || f.o.Client.base.Path == "/")
}

// Load prefers the origin adopted before the restart (it was verified then;
// failover covers it breaking meanwhile). Unusable files are removed.
func (f *Follower) Load() {
	raw, err := os.ReadFile(f.path())
	if err != nil {
		if !errors.Is(err, os.ErrNotExist) {
			f.o.Log.Warn("cannot read the adopted control-plane origin; ignoring it", "err", err)
		}
		return
	}
	var rec followRecord
	if err := json.Unmarshal(raw, &rec); err != nil || rec.URL.Validate() != nil || f.refusal() != "" || f.sameAsConfigured(rec.URL) {
		_ = os.Remove(f.path())
		return
	}
	u, _ := rec.URL.URL()
	f.mu.Lock()
	f.adopted, f.want = rec.URL, rec.URL
	f.mu.Unlock()
	f.o.Client.SetPreferred(u)
	f.o.Log.Info("using the dashboard's canonical address adopted earlier", "url", string(rec.URL), "fallback", f.o.Client.base.String())
}

// Want records the NodeState's system.controlPlane.url ("" = none) and
// triggers a check when it changed. Cheap to call on every pass.
func (f *Follower) Want(origin state.ControlPlaneOrigin) {
	f.mu.Lock()
	changed := origin != f.want
	if changed {
		f.want, f.nextTry, f.backoff = origin, time.Time{}, 0
	}
	f.mu.Unlock()
	if changed {
		select {
		case f.kick <- struct{}{}:
		default:
		}
	}
}

// Run checks the wanted origin whenever it changes and retries verification
// with backoff until ctx is done.
func (f *Follower) Run(ctx context.Context) {
	for {
		f.step(ctx)
		f.mu.Lock()
		wait := time.Duration(0)
		if !f.nextTry.IsZero() {
			wait = max(f.nextTry.Sub(f.o.Now()), time.Second)
		}
		f.mu.Unlock()
		var timer *time.Timer
		var fire <-chan time.Time
		if wait > 0 {
			timer = time.NewTimer(wait)
			fire = timer.C
		}
		select {
		case <-ctx.Done():
			if timer != nil {
				timer.Stop()
			}
			return
		case <-f.kick:
		case <-fire:
		}
		if timer != nil {
			timer.Stop()
		}
	}
}

// step converges once: clear, refuse, keep, or verify and adopt.
func (f *Follower) step(ctx context.Context) {
	f.mu.Lock()
	want, adopted := f.want, f.adopted
	due := f.nextTry.IsZero() || !f.o.Now().Before(f.nextTry)
	f.mu.Unlock()

	switch {
	case want == "" || f.sameAsConfigured(want) || want.Validate() != nil:
		f.clear()
		return
	case f.refusal() != "":
		f.clear()
		return
	case want == adopted:
		f.mu.Lock()
		f.pending, f.nextTry, f.backoff = "", time.Time{}, 0
		f.mu.Unlock()
		return
	case !due:
		return
	}

	u, _ := want.URL()
	vctx, cancel := context.WithTimeout(ctx, followTimeout)
	err := f.o.Client.VerifyOrigin(vctx, u)
	cancel()
	if ctx.Err() != nil {
		return
	}
	if err != nil {
		f.mu.Lock()
		f.backoff = min(max(f.backoff*2, followRetryMin), followRetryMax)
		f.nextTry = f.o.Now().Add(f.backoff)
		f.pending = "cannot verify " + string(want) + ": " + sanitize([]byte(err.Error()))
		retry := f.backoff
		f.mu.Unlock()
		f.o.Log.Warn("the dashboard's canonical address does not verify yet; staying on the current URL",
			"url", string(want), "err", err, "retryIn", retry.String())
		return
	}
	if err := f.persist(want); err != nil {
		f.o.Log.Warn("cannot persist the adopted control-plane origin (used until restart)", "err", err)
	}
	f.mu.Lock()
	if f.want != want { // changed while verifying: check the new one next
		f.mu.Unlock()
		return
	}
	f.adopted, f.pending, f.nextTry, f.backoff = want, "", time.Time{}, 0
	f.mu.Unlock()
	f.o.Client.SetPreferred(u)
	f.o.Log.Info("moved to the dashboard's canonical address", "url", string(want), "fallback", f.o.Client.base.String())
	f.o.OnChange()
}

func (f *Follower) clear() {
	f.mu.Lock()
	had := f.adopted != ""
	f.adopted, f.pending, f.nextTry, f.backoff = "", "", time.Time{}, 0
	f.mu.Unlock()
	if err := os.Remove(f.path()); err != nil && !errors.Is(err, os.ErrNotExist) {
		f.o.Log.Warn("cannot remove the adopted control-plane origin", "err", err)
	}
	if had {
		f.o.Client.SetPreferred(nil)
		f.o.Log.Info("back on the configured control-plane URL", "url", f.o.Client.base.String())
		f.o.OnChange()
	}
}

func (f *Follower) persist(origin state.ControlPlaneOrigin) error {
	raw, err := json.Marshal(followRecord{URL: origin, VerifiedAt: f.o.Now().UTC()})
	if err != nil {
		return err
	}
	return atomicfile.Write(f.path(), append(raw, '\n'), 0o600)
}

// Adopted is the verified origin requests prefer (nil when none).
func (f *Follower) Adopted() *url.URL {
	f.mu.Lock()
	defer f.mu.Unlock()
	if f.adopted == "" {
		return nil
	}
	u, err := f.adopted.URL()
	if err != nil {
		return nil
	}
	return u
}

// Status is Report.host.controlPlane.
func (f *Follower) Status() *state.ControlPlaneStatus {
	f.mu.Lock()
	want, adopted, pending := f.want, f.adopted, f.pending
	f.mu.Unlock()
	st := &state.ControlPlaneStatus{
		Configured: state.NewControlPlaneBaseURL(f.o.Client.base),
		Adopted:    adopted,
		Active:     state.NewControlPlaneBaseURL(f.o.Client.LastActive()),
	}
	down, why := f.o.Client.Failover()
	switch {
	case want == "" || f.sameAsConfigured(want):
		st.State = state.ControlPlaneConfigured
	case f.refusal() != "":
		st.State, st.Message = state.ControlPlaneRefused, f.refusal()
	case want != adopted:
		st.State, st.Message = state.ControlPlanePending, pending
		if st.Message == "" {
			st.Message = "verifying " + string(want)
		}
	case down:
		st.State, st.Message = state.ControlPlaneFallback, fmt.Sprintf("%s failed (%s); using the configured URL", adopted, why)
	default:
		st.State = state.ControlPlaneAdopted
	}
	if len(st.Message) > 512 {
		st.Message = st.Message[:512]
	}
	return st
}
