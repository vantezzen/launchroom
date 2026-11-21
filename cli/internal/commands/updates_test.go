package commands

import (
	"encoding/json"
	"net/http"
	"strings"
	"testing"

	"github.com/vantezzen/launchroom/cli/internal/exitcode"
)

// updatesAPI fakes GET /app-updates and POST …/update (D106).
type updatesAPI struct {
	calls   []string
	bodies  []map[string]any
	ability bool
	run     map[string]any // the row's run after the update started
}

func candidateJSON(kind, tag string) map[string]any {
	return map[string]any{"kind": kind, "tag": tag, "version": tag, "digest": "sha256:" + strings.Repeat("c", 64), "publishedAt": nil, "firstSeenAt": "2026-11-20T10:00:00Z", "curatedBy": nil}
}

func appUpdateJSON(run map[string]any) map[string]any {
	var r any
	if run != nil {
		r = run
	}
	return map[string]any{
		"serviceId": "svc_1", "serviceName": "web", "projectId": "prj_1", "projectName": "Shop", "environmentId": "env_1", "environmentName": "production",
		"production": true, "status": "available", "reason": nil, "running": "v6.2.0", "moving": false, "runningPublishedAt": nil,
		"image":       "docker.io/listmonk/listmonk:v6.2.0",
		"candidates":  []any{candidateJSON("major", "v7.0.1"), candidateJSON("minor", "v6.3.0")},
		"recommended": candidateJSON("minor", "v6.3.0"), "major": candidateJSON("major", "v7.0.1"),
		"releaseNotesUrl": "https://github.com/knadh/listmonk/releases", "checkedAt": "2026-11-21T10:00:00Z", "run": r,
		"canUpdate": true, "blocker": nil, "backups": []any{"Postgres"}, "keepsData": true, "backupStorage": true, "autoUpdate": "off",
		"dashboardUrl": "http://x/p/prj_1/env_1/s/svc_1",
	}
}

func updateRunJSON(status string) map[string]any {
	return map[string]any{
		"id": "upd_1", "status": status, "automatic": false, "kind": "minor", "fromVersion": "v6.2.0", "toVersion": "v6.3.0", "actor": "ada",
		"deploymentId": nil, "deploymentHref": nil, "message": nil, "backupNote": nil, "backingUp": []any{}, "startedAt": "2026-11-21T10:00:00Z",
		"finishedAt": nil, "watchUntil": nil,
	}
}

func addUpdates(t *testing.T, f *fakeAPI) *updatesAPI {
	t.Helper()
	u := &updatesAPI{ability: true}
	mux, ok := f.srv.Config.Handler.(*http.ServeMux)
	if !ok {
		t.Fatal("fake API has no mux")
	}
	mux.HandleFunc("GET /api/v1/app-updates", func(w http.ResponseWriter, r *http.Request) {
		f.mu.Lock()
		u.calls = append(u.calls, "GET "+r.URL.RequestURI())
		f.mu.Unlock()
		writeJSON(w, 200, map[string]any{"data": []any{appUpdateJSON(u.run)}, "meta": map[string]any{"nextCursor": nil}})
	})
	mux.HandleFunc("POST /api/v1/environments/{env}/services/{svc}/update", func(w http.ResponseWriter, r *http.Request) {
		body := map[string]any{}
		_ = json.NewDecoder(r.Body).Decode(&body)
		f.mu.Lock()
		u.calls = append(u.calls, "POST "+r.URL.Path)
		u.bodies = append(u.bodies, body)
		f.mu.Unlock()
		if !u.ability {
			writeJSON(w, 403, map[string]any{"error": map[string]any{"code": "missing_ability", "message": "This token can't do that.", "ability": "update"}})
			return
		}
		u.run = updateRunJSON("succeeded")
		writeJSON(w, 202, map[string]any{"data": appUpdateJSON(updateRunJSON("backing_up"))})
	})
	return u
}

func updatesHarness(t *testing.T) (*harness, *updatesAPI) {
	t.Helper()
	f := newFakeAPI(t)
	u := addUpdates(t, f)
	h := newHarness(t, f.srv.URL)
	h.linkTo(t, f.srv.URL)
	return h, u
}

func TestUpdatesListsServices(t *testing.T) {
	h, u := updatesHarness(t)
	if code := h.run("updates"); code != 0 {
		t.Fatalf("exit %d: %s", code, h.err)
	}
	out := h.out.String()
	for _, want := range []string{"web (Shop, production)", "v6.2.0", "v6.3.0", "⚠ v7.0.1 (major)"} {
		if !strings.Contains(out, want) {
			t.Errorf("output lacks %q:\n%s", want, out)
		}
	}
	if code := h.run("updates", "ls", "--project", "prj_1", "--json"); code != 0 {
		t.Fatalf("ls --json: %d %s", code, h.err)
	}
	var rows []map[string]any
	if err := json.Unmarshal(h.out.Bytes(), &rows); err != nil || len(rows) != 1 || rows[0]["serviceId"] != "svc_1" {
		t.Fatalf("stdout = %s", h.out)
	}
	if last := u.calls[len(u.calls)-1]; last != "GET /api/v1/app-updates?project=prj_1" {
		t.Fatalf("calls = %v", u.calls)
	}
}

func TestUpdatesApplyRecommended(t *testing.T) {
	h, u := updatesHarness(t)
	if code := h.run("updates", "apply"); code != exitcode.Usage || !strings.Contains(h.err.String(), "--yes") {
		t.Fatalf("no TTY, no --yes: %d %s", code, h.err)
	}
	if code := h.run("updates", "apply", "--yes"); code != 0 {
		t.Fatalf("exit %d: %s", code, h.err)
	}
	if u.calls[len(u.calls)-1] != "POST /api/v1/environments/env_1/services/svc_1/update" || u.bodies[0]["tag"] != "v6.3.0" || u.bodies[0]["releaseNotesRead"] != nil {
		t.Fatalf("calls = %v %v", u.calls, u.bodies)
	}
	if !strings.Contains(h.out.String(), "Backing up, then updating web to v6.3.0.") {
		t.Fatalf("stdout = %s", h.out)
	}
}

func TestUpdatesApplyMajorNeedsTheReleaseNotes(t *testing.T) {
	h, u := updatesHarness(t)
	code := h.run("updates", "apply", "--major", "--yes")
	if code != exitcode.Usage || !strings.Contains(h.err.String(), "https://github.com/knadh/listmonk/releases") || !strings.Contains(h.err.String(), "--release-notes-read") {
		t.Fatalf("without notes: %d %s", code, h.err)
	}
	if len(u.bodies) != 0 {
		t.Fatalf("posted without the notes read: %v", u.bodies)
	}
	if code := h.run("updates", "apply", "--to", "v7.0.1", "--release-notes-read", "--yes"); code != 0 {
		t.Fatalf("exit %d: %s", code, h.err)
	}
	if u.bodies[0]["tag"] != "v7.0.1" || u.bodies[0]["releaseNotesRead"] != true {
		t.Fatalf("bodies = %v", u.bodies)
	}
	if code := h.run("updates", "apply", "--to", "v9.9.9", "--yes"); code != exitcode.NotFound || !strings.Contains(h.err.String(), "v7.0.1, v6.3.0") {
		t.Fatalf("unknown tag: %d %s", code, h.err)
	}
}

func TestUpdatesApplyNamesTheMissingPermission(t *testing.T) {
	h, u := updatesHarness(t)
	u.ability = false
	if code := h.run("updates", "apply", "--yes"); code != exitcode.NotAllowed || !strings.Contains(h.err.String(), "Update apps") {
		t.Fatalf("no ability: %d %s", code, h.err)
	}
}

func TestUpdatesApplyWaits(t *testing.T) {
	h, _ := updatesHarness(t)
	if code := h.run("updates", "apply", "web", "--yes", "--wait"); code != 0 || !strings.Contains(h.err.String(), "web runs v6.3.0") {
		t.Fatalf("wait: %d %s", code, h.err)
	}
}
