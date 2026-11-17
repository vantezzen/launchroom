package hostinfo

import (
	"context"
	"errors"
	"regexp"
	"strings"
	"sync"
	"time"

	mobyclient "github.com/moby/moby/client"

	"github.com/vantezzen/launchroom/daemon/internal/docker"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// DockerPingInterval is how often DockerCheck pings Docker (docs/03 §9).
const DockerPingInterval = 30 * time.Second

// DockerCheck tracks whether launchroomd reaches Docker (through
// dockerd-guard where it runs): a /_ping every 30 s, plus what every
// reconcile pass saw (Report.host.docker).
type DockerCheck struct {
	ping     func(context.Context) error
	now      func() time.Time
	onChange func()

	mu   sync.Mutex
	last *state.DockerReach
}

// NewDockerCheck returns a DockerCheck. ping is a /_ping through the
// launchroomd's Docker endpoint; onChange runs when reachability flips.
func NewDockerCheck(ping func(context.Context) error, now func() time.Time, onChange func()) *DockerCheck {
	if now == nil {
		now = time.Now
	}
	if onChange == nil {
		onChange = func() {}
	}
	return &DockerCheck{ping: ping, now: now, onChange: onChange}
}

// Run pings Docker every DockerPingInterval until ctx ends.
func (d *DockerCheck) Run(ctx context.Context) {
	if d.ping == nil {
		return
	}
	for {
		pctx, cancel := context.WithTimeout(ctx, 10*time.Second)
		err := d.ping(pctx)
		cancel()
		if ctx.Err() != nil {
			return
		}
		d.set(err)
		select {
		case <-ctx.Done():
			return
		case <-time.After(DockerPingInterval):
		}
	}
}

// ObservePass records what a reconcile pass saw: an error that says Docker
// can't be reached makes it unreachable now; a pass without such an error
// proves it reachable.
func (d *DockerCheck) ObservePass(err error) {
	if err != nil && !dockerUnreachable(err) {
		return
	}
	d.set(err)
}

func (d *DockerCheck) set(err error) {
	r := &state.DockerReach{Reachable: err == nil, CheckedAt: d.now().UTC()}
	if err != nil {
		r.Error = state.ClipLine(err.Error(), 512)
	}
	d.mu.Lock()
	changed := d.last == nil || d.last.Reachable != r.Reachable
	d.last = r
	d.mu.Unlock()
	if changed {
		d.onChange()
	}
}

// Snapshot is Report.host.docker (nil before the first check).
func (d *DockerCheck) Snapshot() *state.DockerReach {
	d.mu.Lock()
	defer d.mu.Unlock()
	if d.last == nil {
		return nil
	}
	r := *d.last
	return &r
}

// ReconcileTracker keeps how launchroomd's reconcile passes went
// (Report.host.reconcile).
type ReconcileTracker struct {
	mu   sync.Mutex
	last *state.ReconcileStatus
}

// Pass records the end of a reconcile pass at with its error.
func (t *ReconcileTracker) Pass(at time.Time, err error) {
	t.mu.Lock()
	defer t.mu.Unlock()
	s := &state.ReconcileStatus{LastPassAt: at.UTC()}
	if t.last != nil {
		s.LastCompleteAt = t.last.LastCompleteAt
	}
	if err == nil {
		complete := at.UTC()
		s.LastCompleteAt = &complete
	} else {
		s.Errors = ReconcileErrors(err)
	}
	t.last = s
}

// Snapshot is Report.host.reconcile (nil before the first pass).
func (t *ReconcileTracker) Snapshot() *state.ReconcileStatus {
	t.mu.Lock()
	defer t.mu.Unlock()
	if t.last == nil {
		return nil
	}
	s := *t.last
	return &s
}

// ReconcileErrorer is implemented by reconcile errors that classify
// themselves; others are classified from Docker's sentinels and wording.
type ReconcileErrorer interface {
	ReconcileErrors() []state.ReconcileError
}

var rePublicIDInText = regexp.MustCompile(`\b[a-z]{2,4}_[0-7][0-9A-HJKMNP-TV-Z]{25}\b`)

// ReconcileErrors classifies a reconcile pass's error (joined errors one by
// one) into at most state.MaxReconcileErrors typed problems.
func ReconcileErrors(err error) []state.ReconcileError {
	var typed ReconcileErrorer
	if errors.As(err, &typed) {
		out := typed.ReconcileErrors()
		return out[:min(len(out), state.MaxReconcileErrors)]
	}
	var parts []error
	if j, ok := err.(interface{ Unwrap() []error }); ok {
		parts = j.Unwrap()
	} else {
		parts = []error{err}
	}
	var out []state.ReconcileError
	for _, p := range parts {
		if p == nil {
			continue
		}
		if len(out) == state.MaxReconcileErrors {
			break
		}
		e := state.ReconcileError{Code: ReconcileCode(p), Message: state.ClipLine(p.Error(), 512)}
		if id := state.PublicID(rePublicIDInText.FindString(p.Error())); id != "" && id.Validate() == nil {
			e.Target = id
		}
		out = append(out, e)
	}
	return out
}

// ReconcileCode classifies one reconcile error.
func ReconcileCode(err error) state.ReconcileErrorCode {
	msg := strings.ToLower(err.Error())
	switch {
	case dockerUnreachable(err):
		return state.ReconcileDockerUnreachable
	case errors.Is(err, docker.ErrGuardRefused), errors.Is(err, docker.ErrNotManaged):
		return state.ReconcileGuardRefused
	case strings.Contains(msg, "no space left on device"), strings.Contains(msg, "disk quota exceeded"):
		return state.ReconcileDiskFull
	case errors.Is(err, docker.ErrNotIsolated):
		return state.ReconcileNotIsolated
	case strings.Contains(msg, "network"):
		return state.ReconcileNetwork
	case strings.Contains(msg, "volume"):
		return state.ReconcileVolume
	}
	return state.ReconcileOther
}

// dockerUnreachable reports whether err says Docker (or dockerd-guard in
// front of it) can't be reached at all.
func dockerUnreachable(err error) bool {
	if mobyclient.IsErrConnectionFailed(err) {
		return true
	}
	msg := strings.ToLower(err.Error())
	for _, s := range []string{
		"cannot connect to the docker daemon", "is the docker daemon running", "connect: connection refused",
		"connect: no such file or directory", "dial unix",
	} {
		if strings.Contains(msg, s) {
			return true
		}
	}
	return false
}
