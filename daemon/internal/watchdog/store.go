package watchdog

import (
	"encoding/json"
	"errors"
	"io/fs"
	"os"
	"path/filepath"
	"slices"
	"time"

	"github.com/vantezzen/launchroom/daemon/internal/atomicfile"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// stateFile keeps the watchdog's memory across launchroomd restarts: the
// restart history behind the hourly cap, the current outage episode (so
// "down" isn't sent twice and "back" still is) and what wasn't delivered.
const stateFile = "watchdog.json"

type persisted struct {
	// History are the restarts of the last hour (the cap).
	History []restartMark `json:"history,omitempty"`
	// Restarts and Notices wait for an accepted report.
	Restarts []state.WatchdogRestart `json:"restarts,omitempty"`
	Notices  []state.FallbackNotice  `json:"notices,omitempty"`
	Notice   noticeState             `json:"notice"`
}

type restartMark struct {
	Service state.ControlPlaneService `json:"service"`
	At      time.Time                 `json:"at"`
}

// noticeState is the current outage episode.
type noticeState struct {
	// Down: a "down" notice went out and "back" hasn't yet.
	Down bool `json:"down,omitempty"`
	// SentAt is the last notice sent (the 6-hour repeat counts from it).
	SentAt time.Time `json:"sentAt,omitzero"`
	// LastTry is the last failed attempt (retried after 5 minutes).
	LastTry attempt `json:"lastTry,omitzero"`
}

type attempt struct {
	Kind state.FallbackNoticeKind `json:"kind,omitempty"`
	At   time.Time                `json:"at,omitzero"`
}

func (p *persisted) restartsWithin(svc state.ControlPlaneService, now time.Time, d time.Duration) int {
	n := 0
	for _, m := range p.History {
		if m.Service == svc && now.Sub(m.At) < d {
			n++
		}
	}
	return n
}

func (p *persisted) prune(now time.Time) {
	p.History = slices.DeleteFunc(p.History, func(m restartMark) bool { return now.Sub(m.At) >= time.Hour })
}

func load(dir string) (persisted, error) {
	var p persisted
	if dir == "" {
		return p, nil
	}
	raw, err := os.ReadFile(filepath.Join(dir, stateFile)) //nolint:gosec // G304: launchroomd's own data dir
	if errors.Is(err, fs.ErrNotExist) {
		return p, nil
	}
	if err != nil {
		return p, err
	}
	if err := json.Unmarshal(raw, &p); err != nil {
		return persisted{}, err
	}
	// Drop anything the report would refuse.
	p.Restarts = slices.DeleteFunc(p.Restarts, func(r state.WatchdogRestart) bool {
		return r.Service.Validate() != nil || !restartable(r.Service) || r.Reason.Validate() != nil || r.At.IsZero()
	})
	p.Notices = slices.DeleteFunc(p.Notices, func(n state.FallbackNotice) bool {
		return n.Kind.Validate() != nil || n.At.IsZero() || len(n.Error) > maxErrorLen
	})
	if over := len(p.Restarts) - maxRestarts; over > 0 {
		p.Restarts = p.Restarts[over:]
	}
	if over := len(p.Notices) - maxNotices; over > 0 {
		p.Notices = p.Notices[over:]
	}
	return p, nil
}

func (p *persisted) save(dir string) error {
	if dir == "" {
		return nil
	}
	raw, err := json.Marshal(p)
	if err != nil {
		return err
	}
	return atomicfile.Write(filepath.Join(dir, stateFile), raw, 0o600)
}
