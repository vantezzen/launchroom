package commands

import (
	"encoding/json"
	"net/http"
	"strings"
	"testing"
	"time"

	"github.com/vantezzen/launchroom/cli/internal/exitcode"
)

// d111API adds the failure report, fix, deployment list, rollback, ray and
// preview endpoints (D111) to the fake API and records the calls.
type d111API struct {
	calls    []string
	bodies   []map[string]any
	previews []map[string]any
	ability  bool
}

func failureReportJSON() map[string]any {
	return map[string]any{
		"deploymentId": "dpl_1", "class": "health_timeout", "title": "Health check timed out", "message": "malformed HTTP response",
		"hint": "Check the health check path.",
		"explanation": map[string]any{
			"key": "health_not_http", "title": "Your app doesn’t answer HTTP on its port",
			"cause": "Something accepts connections on the port, but it doesn’t answer like a web server.",
			"fix":   "Switch the health check to TCP.", "answer": nil,
		},
		"hints": []any{}, "failingStep": "health", "errorLine": 4,
		"excerpt": []any{
			map[string]any{"number": 3, "stream": "stdout", "text": "probe attempt 3", "error": false},
			map[string]any{"number": 4, "stream": "stderr", "text": "error: connection reset by peer", "error": true},
			map[string]any{"number": 20, "stream": "stdout", "text": "probe attempt 20", "error": false},
		},
		"fixes": []any{map[string]any{
			"code": "use_tcp_healthcheck", "label": "Check over TCP and redeploy", "oneClick": true,
			"command": "launchroom fix use_tcp_healthcheck --deployment dpl_1", "dashboardUrl": "http://x/d/dpl_1",
		}},
		"servingCommit": strings.Repeat("b", 40), "dashboardUrl": "http://x/d/dpl_1",
	}
}

func addD111(t *testing.T, f *fakeAPI) *d111API {
	t.Helper()
	d := &d111API{ability: true}
	mux, ok := f.srv.Config.Handler.(*http.ServeMux)
	if !ok {
		t.Fatal("fake API has no mux")
	}
	record := func(r *http.Request) {
		body := map[string]any{}
		_ = json.NewDecoder(r.Body).Decode(&body)
		f.mu.Lock()
		d.calls = append(d.calls, r.Method+" "+r.URL.Path)
		d.bodies = append(d.bodies, body)
		f.mu.Unlock()
	}
	mux.HandleFunc("GET /api/v1/deployments/dpl_1/failure", func(w http.ResponseWriter, _ *http.Request) {
		writeJSON(w, 200, map[string]any{"data": failureReportJSON()})
	})
	mux.HandleFunc("POST /api/v1/deployments/{id}/fixes/{code}", func(w http.ResponseWriter, r *http.Request) {
		record(r)
		if !d.ability {
			writeJSON(w, 403, map[string]any{"error": map[string]any{"code": "missing_ability", "message": "This token can't do that.", "ability": "fix"}})
			return
		}
		writeJSON(w, 202, map[string]any{"data": map[string]any{"outcome": "deploying", "message": "Fix staged and deploying.", "deployment": deployment("queued", false)}})
	})
	list := func(id, status string) map[string]any {
		dep := deployment(status, status != "queued")
		dep["id"] = id
		dep["queuedAt"] = time.Now().Add(-90 * time.Minute).UTC().Format(time.RFC3339)
		return dep
	}
	mux.HandleFunc("GET /api/v1/environments/env_1/deployments", func(w http.ResponseWriter, r *http.Request) {
		record(r)
		writeJSON(w, 200, map[string]any{"data": []any{list("dpl_9", "queued"), list("dpl_live", "active"), list("dpl_old", "superseded"), list("dpl_older", "superseded")}, "meta": map[string]any{"nextCursor": nil}})
	})
	for _, action := range []string{"rollback", "cancel", "redeploy"} {
		mux.HandleFunc("POST /api/v1/deployments/{id}/"+action, func(w http.ResponseWriter, r *http.Request) {
			record(r)
			writeJSON(w, 202, map[string]any{"data": deployment("queued", false)})
		})
	}
	mux.HandleFunc("POST /api/v1/environments/env_1/auto-deploy/resume", func(w http.ResponseWriter, r *http.Request) {
		record(r)
		writeJSON(w, 200, map[string]any{"data": map[string]any{"id": "env_1", "projectId": "prj_1", "name": "production", "slug": "production", "kind": "production", "autoDeployPaused": false, "dashboardUrl": "http://x"}})
	})
	mux.HandleFunc("POST /api/v1/environments/env_1/deploy", func(w http.ResponseWriter, r *http.Request) {
		record(r)
		writeJSON(w, 202, map[string]any{"data": map[string]any{"revision": 4, "deployments": []any{deployment("queued", false)}}})
	})
	mux.HandleFunc("POST /api/v1/traces/decode", func(w http.ResponseWriter, r *http.Request) {
		record(r)
		if d.bodies[len(d.bodies)-1]["value"] != "lr1.AbCdEf" {
			writeJSON(w, 422, map[string]any{"error": map[string]any{"code": "rule_violation", "message": "This ray isn't from one of your services."}})
			return
		}
		writeJSON(w, 200, map[string]any{"data": map[string]any{
			"receivedAt": "2026-11-20T12:00:00.000Z", "requestId": "0b6c2c1e-1111-4222-8333-444455556666", "method": "GET", "path": "/checkout",
			"protocol": "HTTP/2", "status": 502, "upstreamStatus": nil, "origin": "upstream_failure", "failure": "refused",
			"edgeTimeUs": 12500, "upstreamTimeUs": 800, "attempts": 2, "firewall": map[string]any{"mode": "block", "outcome": "clean", "score": 0, "rules": []any{}},
			"edge": map[string]any{"id": "srv_1", "name": "fra-1", "status": "online"}, "upstreamServer": map[string]any{"id": "srv_2", "name": "fra-2", "status": "online"},
			"sameServer": false, "host": "shop.example.com", "dashboard": false, "endsAt": "app_server", "summary": "The app server couldn't reach web.",
			"service":  map[string]any{"id": "svc_1", "name": "web", "projectId": "prj_1", "projectName": "Shop", "environmentId": "env_1", "environmentName": "production"},
			"logsFrom": 1763639940, "logsTo": 1763640060, "dashboardUrl": "http://x/trace?t=lr1",
		}})
	})
	mux.HandleFunc("GET /api/v1/projects/prj_1/previews", func(w http.ResponseWriter, r *http.Request) {
		record(r)
		writeJSON(w, 200, map[string]any{"data": d.previews})
	})
	return d
}

func preview(status string, deployments ...map[string]any) map[string]any {
	return map[string]any{
		"id": "prv_1", "number": 7, "title": "Add login", "author": "ada", "repository": "o/web", "pullRequestUrl": nil,
		"headRef": "feature", "baseRef": "main", "headSha": strings.Repeat("a", 40), "isFork": false, "status": status,
		"statusReason": nil, "health": "live", "environmentId": "env_7", "deployments": deployments, "expiresAt": nil, "dashboardUrl": "http://x/previews",
		"urls": []any{map[string]any{"service": "web", "url": "https://pr-7.example.com"}},
	}
}

func d111Harness(t *testing.T) (*harness, *fakeAPI, *d111API) {
	t.Helper()
	f := newFakeAPI(t)
	d := addD111(t, f)
	h := newHarness(t, f.srv.URL)
	h.linkTo(t, f.srv.URL)
	return h, f, d
}

func TestDeployFailurePrintsTheExplanation(t *testing.T) {
	h, f, _ := d111Harness(t)
	f.finalStatus = "failed"
	if code := h.run("deploy"); code != exitcode.DeploymentFailed {
		t.Fatalf("exit %d: %s", code, h.err)
	}
	out := h.err.String()
	for _, want := range []string{
		"✕ Health check failed: your app doesn’t answer HTTP on its port (web)",
		"The site is still serving bbbbbbb.",
		"Last lines of the health step",
		" 4 › error: connection reset by peer",
		"  …",
		"Fix   Check over TCP and redeploy",
		"        launchroom fix use_tcp_healthcheck --deployment dpl_1",
		"More  http://x/d/dpl_1",
		"web didn't go live",
	} {
		if !strings.Contains(out, want) {
			t.Errorf("stderr lacks %q:\n%s", want, out)
		}
	}
	if strings.Contains(out, "\x1b[") {
		t.Errorf("colour without a terminal:\n%s", out)
	}
}

func TestDeployFailureJSONCarriesTheReport(t *testing.T) {
	h, f, _ := d111Harness(t)
	f.finalStatus = "failed"
	if code := h.run("deploy", "--json"); code != exitcode.DeploymentFailed {
		t.Fatalf("exit %d", code)
	}
	var ds []map[string]any
	if err := json.Unmarshal(h.out.Bytes(), &ds); err != nil || len(ds) != 1 {
		t.Fatalf("stdout = %s", h.out)
	}
	report, _ := ds[0]["failureReport"].(map[string]any)
	if report["failingStep"] != "health" || ds[0]["status"] != "failed" {
		t.Fatalf("deployment = %v", ds[0])
	}
}

func TestFixShowsAndApplies(t *testing.T) {
	h, f, d := d111Harness(t)
	f.finalStatus = "failed"
	if code := h.run("fix", "--deployment", "dpl_1"); code != 0 || !strings.Contains(h.out.String(), "launchroom fix use_tcp_healthcheck") {
		t.Fatalf("show: %d %s %s", code, h.out, h.err)
	}
	f.finalStatus = "active"
	if code := h.run("fix", "--deployment", "dpl_1"); code != 0 || !strings.Contains(h.out.String(), "nothing to fix") {
		t.Fatalf("live: %d %s", code, h.out)
	}
	f.finalStatus = "failed"
	d.ability = false
	if code := h.run("fix", "use_tcp_healthcheck", "--deployment", "dpl_1"); code != exitcode.NotAllowed || !strings.Contains(h.err.String(), "Apply suggested fixes") {
		t.Fatalf("no ability: %d %s", code, h.err)
	}
}

func TestFixAppliesAndFollows(t *testing.T) {
	h, f, d := d111Harness(t)
	mux, _ := f.srv.Config.Handler.(*http.ServeMux)
	mux.HandleFunc("GET /api/v1/deployments/dpl_failed", func(w http.ResponseWriter, _ *http.Request) {
		dep := deployment("failed", true)
		dep["id"] = "dpl_failed"
		writeJSON(w, 200, map[string]any{"data": dep})
	})
	if code := h.run("fix", "use_tcp_healthcheck", "--deployment", "dpl_failed", "--port", "8080"); code != 0 {
		t.Fatalf("exit %d: %s", code, h.err)
	}
	if d.calls[0] != "POST /api/v1/deployments/dpl_failed/fixes/use_tcp_healthcheck" || d.bodies[0]["port"] != float64(8080) {
		t.Fatalf("calls = %v %v", d.calls, d.bodies)
	}
	if !strings.Contains(h.out.String(), "web is live") || !strings.Contains(h.err.String(), "Fix staged and deploying.") {
		t.Fatalf("out = %s\nerr = %s", h.out, h.err)
	}
}

func TestDeploymentsListIsAligned(t *testing.T) {
	h, _, d := d111Harness(t)
	if code := h.run("deployments"); code != 0 {
		t.Fatalf("exit %d: %s", code, h.err)
	}
	lines := strings.Split(strings.TrimSpace(h.out.String()), "\n")
	if len(lines) != 4 || !strings.HasPrefix(lines[1], "dpl_live   ● live") || !strings.Contains(lines[1], "1h ago") || !strings.Contains(lines[1], "aaaaaaa Fix checkout") {
		t.Fatalf("output:\n%s", h.out)
	}
	if !strings.Contains(d.calls[0], "/environments/env_1/deployments") {
		t.Fatalf("calls = %v", d.calls)
	}
	if code := h.run("deployments", "ls", "--all", "--json"); code != 0 {
		t.Fatalf("ls --all: %d %s", code, h.err)
	}
}

func TestRollbackPicksThePreviousVersion(t *testing.T) {
	h, _, d := d111Harness(t)
	if code := h.run("rollback"); code != exitcode.Usage {
		t.Fatalf("without --yes: %d %s", code, h.err)
	}
	// The fake service lists no live deployment: the newest superseded one is the target.
	if code := h.run("rollback", "--yes"); code != 0 {
		t.Fatalf("exit %d: %s", code, h.err)
	}
	if d.calls[len(d.calls)-1] != "POST /api/v1/deployments/dpl_old/rollback" {
		t.Fatalf("calls = %v", d.calls)
	}
	if !strings.Contains(h.err.String(), "Database migrations are not reverted.") {
		t.Fatalf("stderr = %s", h.err)
	}
	if code := h.run("rollback", "--resume"); code != 0 || d.calls[len(d.calls)-1] != "POST /api/v1/environments/env_1/auto-deploy/resume" {
		t.Fatalf("resume: %d %v", code, d.calls)
	}
}

func TestCancelAndRedeployDefaults(t *testing.T) {
	h, _, d := d111Harness(t)
	if code := h.run("cancel"); code != 0 || d.calls[len(d.calls)-1] != "POST /api/v1/deployments/dpl_9/cancel" {
		t.Fatalf("cancel: %d %v %s", code, d.calls, h.err)
	}
	if code := h.run("redeploy", "dpl_1"); code != 0 || d.calls[len(d.calls)-1] != "POST /api/v1/deployments/dpl_1/redeploy" {
		t.Fatalf("redeploy: %d %v %s", code, d.calls, h.err)
	}
	if code := h.run("redeploy", "web"); code != exitcode.Usage {
		t.Fatalf("not an id: %d", code)
	}
}

func TestLogsSearchLevelAndWindow(t *testing.T) {
	h, f, _ := d111Harness(t)
	if code := h.run("logs", "-q", "timeout", "--level", "error", "--from", "2h", "--to", "1h"); code != 0 {
		t.Fatalf("exit %d: %s", code, h.err)
	}
	q := f.logQueries[0]
	if q.Get("q") != "timeout level:error" || q.Get("from") == "" || q.Get("to") == "" || q.Get("service") != "svc_1" {
		t.Fatalf("query = %v", q)
	}
	for _, args := range [][]string{{"logs", "--to", "1h"}, {"logs", "--from", "soon"}, {"logs", "--level", "loud"}, {"logs", "--from", "1h", "-f"}} {
		if code := h.run(args...); code != exitcode.Usage {
			t.Errorf("%v: exit %d", args, code)
		}
	}
}

func TestParseWhen(t *testing.T) {
	now := time.Date(2026, 11, 20, 15, 30, 0, 0, time.UTC)
	for in, want := range map[string]time.Time{
		"30m":                  now.Add(-30 * time.Minute),
		"2h":                   now.Add(-2 * time.Hour),
		"3d":                   now.Add(-72 * time.Hour),
		"09:15":                time.Date(2026, 11, 20, 9, 15, 0, 0, time.UTC),
		"2026-11-19T08:00:00Z": time.Date(2026, 11, 19, 8, 0, 0, 0, time.UTC),
	} {
		got, err := parseWhen(in, now)
		if err != nil || !got.Equal(want) {
			t.Errorf("%q = %v, %v; want %v", in, got, err, want)
		}
	}
	if _, err := parseWhen("yesterday", now); err == nil {
		t.Error("yesterday parsed")
	}
}

func TestFindRay(t *testing.T) {
	curl := "HTTP/2 502 \r\ncontent-type: text/html\r\nX-Launchroom-Ray: lr1.AbCdEf\r\nserver: launchroom\r\n\r\n<html>"
	for in, want := range map[string]string{
		curl:                            "lr1.AbCdEf",
		"launchroom-trace: \"lr1.Old\"": "lr1.Old",
		"see lr2.Newer_one here":        "lr2.Newer_one",
		"  lr1.AbCdEf\n":                "lr1.AbCdEf",
		"nothing":                       "nothing",
	} {
		if got := findRay(in); got != want {
			t.Errorf("findRay(%q) = %q, want %q", in, got, want)
		}
	}
}

func TestRayReadsStdinAndPrintsTheTrace(t *testing.T) {
	h, f, _ := d111Harness(t)
	h.app.Stdin = strings.NewReader("HTTP/2 502\r\nx-launchroom-ray: lr1.AbCdEf\r\n\r\n")
	if code := h.run("ray"); code != 0 {
		t.Fatalf("exit %d: %s", code, h.err)
	}
	out := h.out.String()
	for _, want := range []string{"✕ 502  GET /checkout HTTP/2", "Host      shop.example.com", "Shop / production / web", "edge 12.5 ms", "of which the app 800 µs", "fra-1 → fra-2", "Ended at the app server", "No log lines mention this request."} {
		if !strings.Contains(out, want) {
			t.Errorf("output lacks %q:\n%s", want, out)
		}
	}
	if q := f.logQueries[0]; !strings.Contains(q.Get("q"), "0b6c2c1e") || q.Get("from") != "1763639940" {
		t.Fatalf("log query = %v", q)
	}
	if code := h.run("ray", "lr1.Other"); code != exitcode.Refused || !strings.Contains(h.err.String(), "isn't from one of your services") {
		t.Fatalf("refusal: %d %s", code, h.err)
	}
}

func TestPreviewWait(t *testing.T) {
	h, _, d := d111Harness(t)
	live := deployment("active", true)
	d.previews = []map[string]any{preview("running", live)}
	if code := h.run("preview", "wait", "--pr", "7"); code != 0 {
		t.Fatalf("exit %d: %s", code, h.err)
	}
	if strings.TrimSpace(h.out.String()) != "https://pr-7.example.com  web" || !strings.Contains(d.calls[0], "/projects/prj_1/previews") {
		t.Fatalf("out = %q calls = %v", h.out, d.calls)
	}

	// Another commit than the one asked for: still waiting when the timeout passes.
	if code := h.run("preview", "wait", "--pr", "7", "--sha", strings.Repeat("c", 40), "--timeout", "1ms"); code != exitcode.WaitTimedOut {
		t.Fatalf("timeout: %d %s", code, h.err)
	}

	d.previews = []map[string]any{preview("running", deployment("failed", true))}
	if code := h.run("preview", "wait", "--pr", "7", "--json"); code != exitcode.DeploymentFailed {
		t.Fatalf("failed: %d %s", code, h.err)
	}
	if jsonOnly(t, h.out.Bytes())["number"] != float64(7) {
		t.Fatalf("json = %s", h.out)
	}

	d.previews = []map[string]any{preview("closed")}
	if code := h.run("preview", "wait", "--pr", "7"); code != exitcode.Refused {
		t.Fatalf("closed: %d", code)
	}
	if code := h.run("preview", "wait"); code != exitcode.Usage {
		t.Fatalf("no --pr: %d", code)
	}
}

func TestEnvSetApplyDeploys(t *testing.T) {
	h, _, d := d111Harness(t)
	if code := h.run("env", "set", "NEW=1", "--apply"); code != 0 {
		t.Fatalf("exit %d: %s", code, h.err)
	}
	if d.calls[len(d.calls)-1] != "POST /api/v1/environments/env_1/deploy" || !strings.Contains(h.out.String(), "web is live") {
		t.Fatalf("calls = %v out = %s", d.calls, h.out)
	}
}
