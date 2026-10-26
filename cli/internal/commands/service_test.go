package commands

import (
	"encoding/json"
	"net/http"
	"strings"
	"testing"

	"github.com/vantezzen/launchroom/cli/internal/exitcode"
)

// serviceActions adds the service lifecycle endpoints (D68) to the fake API
// and records what they were called with.
func serviceActions(t *testing.T, f *fakeAPI) *[]map[string]any {
	t.Helper()
	var calls []map[string]any
	mux, ok := f.srv.Config.Handler.(*http.ServeMux)
	if !ok {
		t.Fatal("fake API has no mux")
	}
	for _, action := range []string{"stop", "start", "restart", "deploy"} {
		mux.HandleFunc("POST /api/v1/environments/env_1/services/svc_1/"+action, func(w http.ResponseWriter, r *http.Request) {
			if r.Header.Get("Authorization") != "Bearer "+testToken || r.Header.Get("Idempotency-Key") == "" {
				t.Errorf("%s: missing token or Idempotency-Key", action)
			}
			body := map[string]any{}
			_ = json.NewDecoder(r.Body).Decode(&body)
			body["action"] = action
			f.mu.Lock()
			calls = append(calls, body)
			f.mu.Unlock()
			svc := map[string]any{"id": "svc_1", "environmentId": "env_1", "name": "web", "slug": "web", "kind": "app", "status": "live", "urls": []any{}, "dashboardUrl": "http://x", "activeDeployment": nil, "latestDeployment": nil}
			switch action {
			case "deploy":
				writeJSON(w, 202, map[string]any{"data": deployment("queued", false)})
			case "stop":
				svc["status"] = "stopped"
				svc["stoppedAt"] = "2026-10-25T12:00:00Z"
				writeJSON(w, 200, map[string]any{"data": map[string]any{"service": svc, "deployments": []any{}}})
			default:
				writeJSON(w, 202, map[string]any{"data": map[string]any{"service": svc, "deployments": []any{deployment("queued", false)}}})
			}
		})
	}
	return &calls
}

func TestServiceStopNeedsYesWithoutATerminal(t *testing.T) {
	f := newFakeAPI(t)
	calls := serviceActions(t, f)
	h := newHarness(t, f.srv.URL)
	h.linkTo(t, f.srv.URL)
	if code := h.run("service", "stop"); code != exitcode.Usage {
		t.Fatalf("exit %d: %s", code, h.err)
	}
	if len(*calls) != 0 {
		t.Fatalf("stopped without confirmation: %v", *calls)
	}
	if code := h.run("service", "stop", "web", "--yes", "--json"); code != 0 {
		t.Fatalf("exit %d: %s", code, h.err)
	}
	out := jsonOnly(t, h.out.Bytes())
	if out["status"] != "stopped" || (*calls)[0]["processes"] != true {
		t.Fatalf("stdout = %v, calls = %v", out, *calls)
	}
}

func TestServiceStopAsksOnATerminal(t *testing.T) {
	f := newFakeAPI(t)
	calls := serviceActions(t, f)
	h := newHarness(t, f.srv.URL)
	h.linkTo(t, f.srv.URL)
	h.app.Printer.TTY = true
	h.app.Stdin = strings.NewReader("y\n")
	if code := h.run("service", "stop", "--without-processes"); code != 0 {
		t.Fatalf("exit %d: %s", code, h.err)
	}
	if !strings.Contains(h.err.String(), "Stop web in production?") || !strings.Contains(h.out.String(), "web is stopped in production") {
		t.Fatalf("stderr = %s, stdout = %s", h.err, h.out)
	}
	if (*calls)[0]["processes"] != false {
		t.Fatalf("calls = %v", *calls)
	}
}

func TestServiceStartAndRestartFollowTheRollout(t *testing.T) {
	for _, action := range []string{"start", "restart"} {
		f := newFakeAPI(t)
		calls := serviceActions(t, f)
		h := newHarness(t, f.srv.URL)
		if code := h.run("service", action, "web", "--project", "shop", "--environment", "production"); code != 0 {
			t.Fatalf("%s exit %d: %s", action, code, h.err)
		}
		if !strings.Contains(h.out.String(), "web is live") || (*calls)[0]["action"] != action {
			t.Fatalf("%s: stdout = %s, calls = %v", action, h.out, *calls)
		}
	}
}

func TestServiceRestartFailureExit10(t *testing.T) {
	f := newFakeAPI(t)
	f.finalStatus = "failed"
	serviceActions(t, f)
	h := newHarness(t, f.srv.URL)
	h.linkTo(t, f.srv.URL)
	if code := h.run("service", "restart", "--json"); code != exitcode.DeploymentFailed {
		t.Fatalf("exit %d: %s", code, h.err)
	}
}

func TestServiceDeployDetach(t *testing.T) {
	f := newFakeAPI(t)
	calls := serviceActions(t, f)
	h := newHarness(t, f.srv.URL)
	h.linkTo(t, f.srv.URL)
	if code := h.run("service", "deploy", "--detach", "--json"); code != 0 {
		t.Fatalf("exit %d: %s", code, h.err)
	}
	var ds []map[string]any
	if err := json.Unmarshal(h.out.Bytes(), &ds); err != nil || ds[0]["status"] != "queued" || (*calls)[0]["action"] != "deploy" {
		t.Fatalf("stdout = %s, calls = %v", h.out, *calls)
	}
}

func TestServiceUnknownIsNotFound(t *testing.T) {
	f := newFakeAPI(t)
	serviceActions(t, f)
	h := newHarness(t, f.srv.URL)
	h.linkTo(t, f.srv.URL)
	if code := h.run("service", "restart", "worker"); code != exitcode.NotFound {
		t.Fatalf("exit %d: %s", code, h.err)
	}
}
