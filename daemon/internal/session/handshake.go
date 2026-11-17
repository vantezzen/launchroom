package session

import (
	"log/slog"
	"time"
)

// handshakeSlow is when a session's start counts as slow: it is then logged
// at INFO with its timings, so a slow path shows up without debug logs (the
// browser gives up after 25 s).
const handshakeSlow = 5 * time.Second

// handshake times a session's start: the lease (the handler starts), the
// realtime settings from the dashboard, the relay subscription and the
// browser's first frame.
type handshake struct {
	now    func() time.Time
	leased time.Time
	// The steps, zero until reached.
	settings, relay, first time.Time
}

func newHandshake(now func() time.Time) *handshake {
	return &handshake{now: now, leased: now()}
}

// attrs are the steps reached so far, in milliseconds since the lease.
func (h *handshake) attrs() []any {
	out := make([]any, 0, 8)
	for _, step := range []struct {
		key string
		at  time.Time
	}{{"settingsMs", h.settings}, {"relayMs", h.relay}, {"firstFrameMs", h.first}} {
		if !step.at.IsZero() {
			out = append(out, step.key, step.at.Sub(h.leased).Milliseconds())
		}
	}
	if m := h.missing(); m != "" {
		out = append(out, "missing", m)
	}
	return out
}

// missing is the first step not reached ("" once the browser's first frame arrived).
func (h *handshake) missing() string {
	switch {
	case h.settings.IsZero():
		return "realtime settings"
	case h.relay.IsZero():
		return "relay"
	case h.first.IsZero():
		return "browser frame"
	}
	return ""
}

// relayJoined marks the relay subscription; a slow one is logged at once,
// before the browser's frame (which may never come).
func (h *handshake) relayJoined(log *slog.Logger) {
	h.relay = h.now()
	if h.relay.Sub(h.leased) > handshakeSlow {
		log.Info("session relay connected slowly", h.attrs()...)
	}
}

// firstFrame marks the browser's first frame and logs a slow handshake.
func (h *handshake) firstFrame(log *slog.Logger) {
	if !h.first.IsZero() {
		return
	}
	h.first = h.now()
	if h.first.Sub(h.leased) > handshakeSlow {
		log.Info("session handshake was slow", h.attrs()...)
	}
}

// unfinished is the timings for the end log of a session whose browser never
// sent a frame (nil otherwise).
func (h *handshake) unfinished() []any {
	if !h.first.IsZero() {
		return nil
	}
	return append(h.attrs(), "elapsedMs", h.now().Sub(h.leased).Milliseconds())
}
