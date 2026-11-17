package session

import (
	"bytes"
	"encoding/json/v2"
	"log/slog"
	"strings"
	"testing"
	"time"
)

// logLines decodes every JSON log line written to buf.
func logLines(t *testing.T, buf *bytes.Buffer) []map[string]any {
	t.Helper()
	var out []map[string]any
	for line := range strings.SplitSeq(strings.TrimSpace(buf.String()), "\n") {
		if line == "" {
			continue
		}
		var m map[string]any
		if err := json.Unmarshal([]byte(line), &m); err != nil {
			t.Fatalf("log line %q: %v", line, err)
		}
		out = append(out, m)
	}
	return out
}

// A slow session start is logged at INFO with the timings of each step;
// a fast one logs nothing.
func TestSlowHandshakeIsLoggedAtInfo(t *testing.T) {
	clock := time.Date(2026, 11, 17, 10, 0, 0, 0, time.UTC)
	now := func() time.Time { return clock }

	var buf bytes.Buffer
	log := slog.New(slog.NewJSONHandler(&buf, &slog.HandlerOptions{Level: slog.LevelInfo}))

	fast := newHandshake(now)
	clock = clock.Add(200 * time.Millisecond)
	fast.settings = now()
	clock = clock.Add(300 * time.Millisecond)
	fast.relayJoined(log)
	clock = clock.Add(time.Second)
	fast.firstFrame(log)
	if buf.Len() != 0 {
		t.Fatalf("a fast handshake logged: %s", buf.String())
	}
	if fast.unfinished() != nil {
		t.Fatal("a session with a browser frame has no unfinished timings")
	}

	slow := newHandshake(now)
	clock = clock.Add(400 * time.Millisecond)
	slow.settings = now()
	clock = clock.Add(6 * time.Second)
	slow.relayJoined(log)
	clock = clock.Add(2 * time.Second)
	slow.firstFrame(log)
	slow.firstFrame(log) // only the first frame counts

	lines := logLines(t, &buf)
	if len(lines) != 2 {
		t.Fatalf("want 2 log lines, got %d: %s", len(lines), buf.String())
	}
	relay, done := lines[0], lines[1]
	if relay["level"] != "INFO" || relay["msg"] != "session relay connected slowly" {
		t.Fatalf("relay line: %v", relay)
	}
	if relay["settingsMs"] != 400.0 || relay["relayMs"] != 6400.0 || relay["missing"] != "browser frame" {
		t.Fatalf("relay timings: %v", relay)
	}
	if done["level"] != "INFO" || done["msg"] != "session handshake was slow" {
		t.Fatalf("handshake line: %v", done)
	}
	if done["firstFrameMs"] != 8400.0 {
		t.Fatalf("handshake timings: %v", done)
	}
	if _, ok := done["missing"]; ok {
		t.Fatalf("a finished handshake has nothing missing: %v", done)
	}
}

// A session whose browser never sent a frame reports how far it got.
func TestUnfinishedHandshakeNamesTheMissingStep(t *testing.T) {
	clock := time.Date(2026, 11, 17, 10, 0, 0, 0, time.UTC)
	now := func() time.Time { return clock }
	h := newHandshake(now)
	clock = clock.Add(25 * time.Second)

	got := map[string]any{}
	attrs := h.unfinished()
	for i := 0; i+1 < len(attrs); i += 2 {
		got[attrs[i].(string)] = attrs[i+1]
	}
	if got["missing"] != "realtime settings" || got["elapsedMs"] != int64(25000) {
		t.Fatalf("unfinished: %v", got)
	}

	h.settings = now()
	h.relay = now()
	attrs = h.unfinished()
	got = map[string]any{}
	for i := 0; i+1 < len(attrs); i += 2 {
		got[attrs[i].(string)] = attrs[i+1]
	}
	if got["missing"] != "browser frame" || got["relayMs"] != int64(25000) {
		t.Fatalf("unfinished after relay: %v", got)
	}
}
