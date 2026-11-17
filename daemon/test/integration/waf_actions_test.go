//go:build integration

package integration

import (
	"net/http"
	"testing"
	"time"

	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// nextActionID is a server action id as Next.js sends it (Next-Action header).
const nextActionID = "7f3a1c0b9e8d4c2a1b0f9e8d7c6b5a4f3e2d1c0b9a"

// TestWafAllowsBrowserBodies (docs/22 §6.3, OD15): a route in Block lets
// through what stock browsers and frameworks send as text/plain or browser
// reports (Next.js server actions called from client code, sendBeacon, CSP
// and Reporting API reports), and still inspects a server action's
// arguments as JSON.
func TestWafAllowsBrowserBodies(t *testing.T) {
	w := setupWafEdge(t)
	ns, wl := w.wafState(t, 1, &state.RouteWaf{Mode: state.WafModeBlock}, nil)
	converge(t, w.env, ns, func() bool {
		_, _, ing := w.rec.Snapshot()
		return status(w.rec, wl).Phase == state.PhaseHealthy && ing != nil && ing.State == state.IngressApplied
	})
	c := edgeClient(false)
	w.waitServing(t, c, testHost)
	url := "https://" + testHost + "/"
	action := map[string]string{
		"Content-Type": "text/plain;charset=UTF-8", "Next-Action": nextActionID, "Accept": "text/x-component",
		"Origin": "https://" + testHost,
	}
	for _, tc := range []struct {
		name string
		body string
		hdr  map[string]string
		want int
	}{
		{"server action", `["hello",{"count":1}]`, action, 200},
		{"sendBeacon text/plain", "hello", map[string]string{"Content-Type": "text/plain;charset=UTF-8"}, 200},
		{"csp report", `{"csp-report":{"document-uri":"https://www.example.com/","violated-directive":"script-src"}}`, map[string]string{"Content-Type": "application/csp-report"}, 200},
		{"reporting api", `[{"type":"csp-violation","url":"https://www.example.com/","body":{"blockedURL":"inline"}}]`, map[string]string{"Content-Type": "application/reports+json"}, 200},
		{"server action with sql injection", `["1' OR 1=1--"]`, action, 403},
		{"json with sql injection", `["1' OR 1=1--"]`, map[string]string{"Content-Type": "application/json"}, 403},
		{"unlisted type", "hello", map[string]string{"Content-Type": "application/x-launchroom-test"}, 403},
	} {
		code, body, err := wafDo(t, c, http.MethodPost, url, []byte(tc.body), tc.hdr)
		if err != nil || code != tc.want {
			t.Errorf("%s: %d (want %d) %v %.120q", tc.name, code, tc.want, err, body)
			continue
		}
		t.Logf("%s: %d", tc.name, code)
	}
	if t.Failed() {
		// Which rules blocked (the firewall's records).
		time.Sleep(2 * time.Second)
		w.offset.Add(int64(state.WafWindow + 2*time.Minute))
		w.col.Roll()
		for _, s := range w.col.Pending() {
			for _, ev := range s.Events {
				t.Logf("blocked %s %s: rules %v targets %v", ev.Method, ev.Path, ev.Rules, ev.Targets)
			}
		}
	}
}
