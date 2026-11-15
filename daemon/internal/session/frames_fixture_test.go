package session

import (
	"encoding/base64"
	"encoding/json/v2"
	"os"
	"testing"

	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// framesFixture is protocol/fixtures/session/frames.json, which the
// dashboard's frames.test.ts verifies too.
type framesFixture struct {
	MacPrefix     string `json:"macPrefix"`
	MaxPayload    int    `json:"maxPayload"`
	MaxData       int    `json:"maxData"`
	Key           string `json:"key"`
	Session       string `json:"session"`
	BrowserFrames []struct {
		P string `json:"p"`
		M string `json:"m"`
	} `json:"browserFrames"`
	DaemonFrames []Frame `json:"daemonFrames"`
	Refused      []Frame `json:"refusedAfterDaemonFrames"`
}

func TestSharedFrameVectors(t *testing.T) {
	data, err := os.ReadFile("../../../protocol/fixtures/session/frames.json")
	if err != nil {
		t.Fatal(err)
	}
	var fx framesFixture
	if err := json.Unmarshal(data, &fx); err != nil {
		t.Fatal(err)
	}
	if fx.MacPrefix != macPrefix || fx.MaxPayload != MaxPayload || fx.MaxData != MaxData {
		t.Fatalf("constants: fixture %q/%d/%d, Go %q/%d/%d", fx.MacPrefix, fx.MaxPayload, fx.MaxData, macPrefix, MaxPayload, MaxData)
	}
	key, err := base64.RawURLEncoding.DecodeString(fx.Key)
	if err != nil {
		t.Fatal(err)
	}
	session := state.SessionID(fx.Session)

	daemon := NewCodec(key, session, true)
	for i, f := range fx.BrowserFrames {
		if got := MAC(key, DirBrowserToDaemon, session, f.P); got != f.M {
			t.Errorf("browser frame %d: MAC %s, want %s", i, got, f.M)
		}
		if _, err := daemon.Open(frameJSON(t, Frame{P: f.P, M: f.M})); err != nil {
			t.Errorf("browser frame %d: %v", i, err)
		}
	}

	browser := NewCodec(key, session, false)
	for i, f := range fx.DaemonFrames {
		var p Payload
		if err := json.Unmarshal([]byte(f.P), &p); err != nil {
			t.Fatal(err)
		}
		sealed, err := daemon.Seal(p)
		if err != nil {
			t.Fatal(err)
		}
		if sealed != f {
			t.Errorf("daemon frame %d: sealed %+v, want %+v", i, sealed, f)
		}
		if _, err := browser.Open(frameJSON(t, f)); err != nil {
			t.Errorf("daemon frame %d: %v", i, err)
		}
	}
	for i, f := range fx.Refused {
		if _, err := browser.Open(frameJSON(t, f)); err == nil {
			t.Errorf("refused frame %d was accepted", i)
		}
	}
}
