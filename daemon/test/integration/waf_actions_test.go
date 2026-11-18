//go:build integration

package integration

import (
	"bytes"
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

// TestWafAllowsRestApis (docs/22 §6.3): a route in Block lets through the
// methods REST APIs use (PUT, PATCH, DELETE) and raw uploads sent as
// application/octet-stream, binary bytes included, while it still refuses
// an injection in a PUT's JSON body, a Java deserialisation payload in a raw
// upload and methods outside the list.
func TestWafAllowsRestApis(t *testing.T) {
	w := setupWafEdge(t)
	ns, wl := w.wafState(t, 1, &state.RouteWaf{Mode: state.WafModeBlock}, nil)
	converge(t, w.env, ns, func() bool {
		_, _, ing := w.rec.Snapshot()
		return status(w.rec, wl).Phase == state.PhaseHealthy && ing != nil && ing.State == state.IngressApplied
	})
	c := edgeClient(false)
	w.waitServing(t, c, testHost)
	url := "https://" + testHost + "/v1/orders/0b6e2c1a-4f0d-4a8e-9c71-2d5b9f3e6a10/upload"
	jsonHdr := map[string]string{"Content-Type": "application/json"}
	raw := map[string]string{"Content-Type": "application/octet-stream"}
	// A PNG header and NUL bytes: form parsing would flag them (rule 920270).
	binary := append([]byte("\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDR\x00\x00\x01\x00"), bytes.Repeat([]byte{0, 0xff, '&', '=', 0x7f, '\''}, 512)...)
	for _, tc := range []struct {
		name   string
		method string
		body   []byte
		hdr    map[string]string
		want   int
	}{
		{"put json", http.MethodPut, []byte(`{"status":"paid","items":[{"sku":"A-1","qty":2}]}`), jsonHdr, 200},
		{"patch json", http.MethodPatch, []byte(`{"status":"shipped"}`), jsonHdr, 200},
		{"delete", http.MethodDelete, nil, nil, 200},
		{"put octet-stream", http.MethodPut, binary, raw, 200},
		{"put octet-stream, mixed case", http.MethodPut, []byte("plain bytes"), map[string]string{"Content-Type": "Application/Octet-Stream"}, 200},
		{"put json with sql injection", http.MethodPut, []byte(`{"note":"1' OR 1=1--"}`), jsonHdr, 403},
		{"put octet-stream with java payload", http.MethodPut, []byte("\xac\xed\x00\x05sr\x00java.lang.Runtime"), raw, 403},
		{"trace", "TRACE", nil, nil, 403},
	} {
		code, body, err := wafDo(t, c, tc.method, url, tc.body, tc.hdr)
		if err != nil || code != tc.want {
			t.Errorf("%s: %d (want %d) %v %.120q", tc.name, code, tc.want, err, body)
			continue
		}
		t.Logf("%s: %d", tc.name, code)
	}
	if t.Failed() {
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
