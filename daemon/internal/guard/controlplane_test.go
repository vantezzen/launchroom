package guard

import (
	"bytes"
	"log/slog"
	"net/http"
	"strings"
	"testing"

	"github.com/vantezzen/launchroom/daemon/internal/names"
)

func controlPlaneLabels(service string) map[string]string {
	return map[string]string{
		names.LabelManaged: "true", names.LabelRole: "control-plane",
		"com.docker.compose.project": "launchroom", "com.docker.compose.service": service,
	}
}

// The watchdog's guard kind (D49 addendum): inspect every control-plane
// container, restart only the dashboard's own services, nothing else.
func TestControlPlaneKind(t *testing.T) {
	var logs bytes.Buffer
	h := newHarness(t, func(o *Options) { o.Log = slog.New(slog.NewTextHandler(&logs, nil)) })
	services := []string{"dashboard", "horizon", "reverb", "scheduler", "zot", "postgres", "valkey"}
	for i, svc := range services {
		h.fake.addContainer(strings.Repeat(string("abcdef9"[i]), 64), "launchroom-"+svc+"-1", controlPlaneLabels(svc))
	}
	// Same labels, other compose project or another service: foreign.
	other := controlPlaneLabels("dashboard")
	other["com.docker.compose.project"] = "someone-else"
	h.fake.addContainer(strings.Repeat("1", 64), "someone-else-dashboard-1", other)
	h.fake.addContainer(strings.Repeat("2", 64), "launchroom-headscale-1", controlPlaneLabels("headscale"))
	noRole := controlPlaneLabels("horizon")
	delete(noRole, names.LabelRole)
	h.fake.addContainer(strings.Repeat("3", 64), "launchroom-horizon-2", noRole)
	h.fake.addContainer(strings.Repeat("4", 64), "lr-wl_x", managed())

	for _, svc := range services {
		name := "launchroom-" + svc + "-1"
		ok(t, h.do(http.MethodGet, "/v1.52/containers/"+name+"/json", nil))
		denied := []struct{ method, path string }{
			{http.MethodPost, "/containers/" + name + "/stop?t=10"},
			{http.MethodPost, "/containers/" + name + "/start"},
			{http.MethodPost, "/containers/" + name + "/pause"},
			{http.MethodPost, "/containers/" + name + "/wait"},
			{http.MethodGet, "/containers/" + name + "/logs?stdout=1"},
			{http.MethodGet, "/containers/" + name + "/stats"},
			{http.MethodDelete, "/containers/" + name},
			{http.MethodGet, "/containers/" + name + "/archive?path=/etc"},
			{http.MethodPut, "/containers/" + name + "/archive?path=/tmp"},
		}
		for _, d := range denied {
			w := h.do(d.method, "/v1.52"+d.path, nil)
			if w.Code != http.StatusForbidden {
				t.Fatalf("%s %s: HTTP %d %s", d.method, d.path, w.Code, w.Body.String())
			}
		}
		refused(t, h.do(http.MethodPost, "/v1.52/containers/"+name+"/exec", map[string]any{"Cmd": []string{"id"}}), "exec")
		w := h.do(http.MethodPost, "/v1.52/containers/"+name+"/restart?t=60", nil)
		if svc == "postgres" || svc == "valkey" {
			refused(t, w, "may not restart")
			continue
		}
		ok(t, w)
		r, _ := h.fake.last()
		if !strings.HasSuffix(r.URL.Path, "/restart") || strings.Contains(r.URL.Path, name) || r.URL.Query().Get("t") != "60" {
			t.Fatalf("forwarded %s (by id, with t)", r.URL)
		}
	}
	if !strings.Contains(logs.String(), "restarting a control-plane container") || !strings.Contains(logs.String(), "service=horizon") {
		t.Fatalf("no audit line: %s", logs.String())
	}

	// Foreign look-alikes stay foreign; restart of anything else is refused.
	for _, name := range []string{"someone-else-dashboard-1", "launchroom-headscale-1", "launchroom-horizon-2"} {
		refused(t, h.do(http.MethodGet, "/v1.52/containers/"+name+"/json", nil), NotManagedMarker)
		refused(t, h.do(http.MethodPost, "/v1.52/containers/"+name+"/restart", nil), NotManagedMarker)
	}
	refused(t, h.do(http.MethodPost, "/v1.52/containers/lr-wl_x/restart", nil), "may not restart")
	// Restart takes no signal and a bounded timeout.
	refused(t, h.do(http.MethodPost, "/v1.52/containers/launchroom-dashboard-1/restart?signal=KILL", nil), "signal")
	refused(t, h.do(http.MethodPost, "/v1.52/containers/launchroom-dashboard-1/restart?t=86400", nil), "integer")
}
