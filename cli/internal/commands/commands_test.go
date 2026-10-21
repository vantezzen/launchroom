package commands

import (
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"runtime"
	"strings"
	"sync"
	"testing"
	"time"

	"github.com/vantezzen/launchroom/cli/internal/config"
	"github.com/vantezzen/launchroom/cli/internal/exitcode"
	"github.com/vantezzen/launchroom/cli/internal/link"
	"github.com/vantezzen/launchroom/cli/internal/output"
)

const testToken = "7|lr_pat_testtoken"

// fakeAPI implements the slice of /api/v1 the commands use.
type fakeAPI struct {
	t   *testing.T
	srv *httptest.Server

	mu          sync.Mutex
	polls       int
	vars        []map[string]any
	created     []map[string]any
	patched     []map[string]any
	deleted     []string
	deployments []map[string]any
	finalStatus string
	secretsOK   bool
}

func newFakeAPI(t *testing.T) *fakeAPI {
	f := &fakeAPI{t: t, finalStatus: "active", secretsOK: true}
	f.vars = []map[string]any{
		{"id": "var_1", "key": "APP_NAME", "type": "config", "value": "shop", "targets": []any{}, "connection": false, "editable": true},
		{"id": "var_2", "key": "API_KEY", "type": "secret", "value": nil, "targets": []any{}, "connection": false, "editable": true},
		{"id": "var_3", "key": "DATABASE_URL", "type": "config", "value": "${{postgres.DATABASE_URL}}", "targets": []any{}, "connection": true, "editable": false},
	}
	mux := http.NewServeMux()
	auth := func(h http.HandlerFunc) http.HandlerFunc {
		return func(w http.ResponseWriter, r *http.Request) {
			if r.Header.Get("Authorization") != "Bearer "+testToken {
				writeJSON(w, 401, map[string]any{"error": map[string]any{"code": "unauthenticated", "message": "Sign in again."}})
				return
			}
			h(w, r)
		}
	}
	env := map[string]any{"id": "env_1", "projectId": "prj_1", "name": "production", "slug": "production", "kind": "production", "revision": 3, "autoDeployPaused": false, "dashboardUrl": "http://x/p/prj_1/env_1"}
	svc := map[string]any{"id": "svc_1", "environmentId": "env_1", "name": "web", "slug": "web", "kind": "app", "status": "live", "urls": []any{"https://web.example"}, "dashboardUrl": "http://x/p/prj_1/env_1/s/svc_1", "activeDeployment": nil, "latestDeployment": nil}
	mux.HandleFunc("POST /api/v1/cli/sessions", func(w http.ResponseWriter, r *http.Request) {
		var body map[string]string
		_ = json.NewDecoder(r.Body).Decode(&body)
		if body["clientName"] != "launchroom-cli" || body["hostname"] != "test-host" {
			t.Errorf("login body = %v", body)
		}
		writeJSON(w, 201, map[string]any{"data": map[string]any{"deviceCode": "dc", "userCode": "WDJB-MJHT", "verificationUrl": f.srv.URL + "/cli/login", "verificationUrlComplete": f.srv.URL + "/cli/login?code=WDJB-MJHT", "expiresIn": 600, "interval": 1}})
	})
	mux.HandleFunc("POST /api/v1/cli/sessions/token", func(w http.ResponseWriter, _ *http.Request) {
		f.mu.Lock()
		f.polls++
		n := f.polls
		f.mu.Unlock()
		if n < 3 {
			writeJSON(w, 428, map[string]any{"error": map[string]any{"code": "authorization_pending", "message": "waiting"}})
			return
		}
		writeJSON(w, 200, map[string]any{"data": map[string]any{"token": testToken, "expiresAt": "2027-01-17T12:00:00Z", "team": map[string]any{"id": "team_1", "name": "Acme"}, "abilities": []string{"read", "deploy"}, "user": map[string]any{"name": "Ada", "email": "ada@example.com"}}})
	})
	mux.HandleFunc("GET /api/v1/me", auth(func(w http.ResponseWriter, _ *http.Request) {
		writeJSON(w, 200, map[string]any{"data": map[string]any{"token": map[string]any{"id": "tok_1", "name": "cli", "abilities": []string{"read"}, "expiresAt": "2027-01-17T12:00:00Z", "client": "cli"}, "user": map[string]any{"name": "Ada", "email": "ada@example.com"}, "team": map[string]any{"id": "team_1", "name": "Acme", "role": "owner"}}})
	}))
	mux.HandleFunc("GET /api/v1/projects", auth(func(w http.ResponseWriter, _ *http.Request) {
		writeJSON(w, 200, map[string]any{"data": []any{map[string]any{"id": "prj_1", "name": "Shop", "slug": "shop", "environments": []any{}}}, "meta": map[string]any{"nextCursor": nil}})
	}))
	mux.HandleFunc("GET /api/v1/projects/prj_1/environments", auth(func(w http.ResponseWriter, _ *http.Request) {
		writeJSON(w, 200, map[string]any{"data": []any{env}})
	}))
	mux.HandleFunc("GET /api/v1/environments/env_1", auth(func(w http.ResponseWriter, _ *http.Request) {
		writeJSON(w, 200, map[string]any{"data": env})
	}))
	mux.HandleFunc("GET /api/v1/environments/env_1/services", auth(func(w http.ResponseWriter, _ *http.Request) {
		writeJSON(w, 200, map[string]any{"data": []any{svc}})
	}))
	mux.HandleFunc("GET /api/v1/environments/env_1/services/svc_1", auth(func(w http.ResponseWriter, _ *http.Request) {
		writeJSON(w, 200, map[string]any{"data": svc})
	}))
	mux.HandleFunc("GET /api/v1/environments/env_1/services/svc_1/variables", auth(func(w http.ResponseWriter, _ *http.Request) {
		writeJSON(w, 200, map[string]any{"data": f.vars})
	}))
	mux.HandleFunc("POST /api/v1/environments/env_1/services/svc_1/variables", auth(func(w http.ResponseWriter, r *http.Request) {
		if r.Header.Get("Idempotency-Key") == "" {
			t.Error("creates must send an Idempotency-Key")
		}
		var body map[string]any
		_ = json.NewDecoder(r.Body).Decode(&body)
		f.mu.Lock()
		f.created = append(f.created, body)
		f.mu.Unlock()
		writeJSON(w, 201, map[string]any{"data": map[string]any{"id": "var_9", "key": body["key"], "type": body["type"], "value": nil}})
	}))
	mux.HandleFunc("PATCH /api/v1/environments/env_1/variables/{id}", auth(func(w http.ResponseWriter, r *http.Request) {
		var body map[string]any
		_ = json.NewDecoder(r.Body).Decode(&body)
		body["id"] = r.PathValue("id")
		f.mu.Lock()
		f.patched = append(f.patched, body)
		f.mu.Unlock()
		writeJSON(w, 200, map[string]any{"data": map[string]any{"id": r.PathValue("id"), "key": "APP_NAME", "type": "config"}})
	}))
	mux.HandleFunc("DELETE /api/v1/environments/env_1/variables/{id}", auth(func(w http.ResponseWriter, r *http.Request) {
		f.mu.Lock()
		f.deleted = append(f.deleted, r.PathValue("id"))
		f.mu.Unlock()
		w.WriteHeader(204)
	}))
	mux.HandleFunc("GET /api/v1/environments/env_1/services/svc_1/env", auth(func(w http.ResponseWriter, r *http.Request) {
		vars := map[string]string{"APP_NAME": "shop", "DATABASE_URL": "postgres://u:p@postgres:5432/db", "MULTI": "a b\nc"}
		withheld := []string{}
		if r.URL.Query().Get("secrets") == "1" && f.secretsOK {
			vars["API_KEY"] = "s3cr3t"
		} else {
			withheld = append(withheld, "API_KEY")
		}
		writeJSON(w, 200, map[string]any{"data": map[string]any{"variables": vars, "withheld": withheld, "revision": 3}})
	}))
	mux.HandleFunc("POST /api/v1/environments/env_1/deployments", auth(func(w http.ResponseWriter, r *http.Request) {
		var body map[string]any
		_ = json.NewDecoder(r.Body).Decode(&body)
		f.mu.Lock()
		f.deployments = append(f.deployments, body)
		f.mu.Unlock()
		writeJSON(w, 202, map[string]any{"data": deployment("queued", false)})
	}))
	mux.HandleFunc("GET /api/v1/deployments/dpl_1", auth(func(w http.ResponseWriter, _ *http.Request) {
		writeJSON(w, 200, map[string]any{"data": deployment(f.finalStatus, true)})
	}))
	mux.HandleFunc("GET /api/v1/deployments/dpl_1/logs/stream", auth(func(w http.ResponseWriter, _ *http.Request) {
		w.Header().Set("Content-Type", "text/event-stream")
		_, _ = fmt.Fprint(w, "event: status\ndata: {\"status\":\"building\",\"finished\":false}\n\n")
		_, _ = fmt.Fprint(w, "event: lines\nid: 4\ndata: {\"lines\":[{\"number\":1,\"time\":\"\",\"stream\":\"stdout\",\"text\":\"npm run build\",\"section\":\"build\"}],\"cursor\":4}\n\n")
		_, _ = fmt.Fprintf(w, "event: status\ndata: {\"status\":%q,\"finished\":true}\n\nevent: end\ndata: {}\n\n", f.finalStatus)
	}))
	f.srv = httptest.NewServer(mux)
	t.Cleanup(f.srv.Close)
	return f
}

func deployment(status string, finished bool) map[string]any {
	d := map[string]any{"id": "dpl_1", "environmentId": "env_1", "serviceId": "svc_1", "serviceName": "web", "status": status, "finished": finished, "trigger": "api", "dashboardUrl": "http://x/d/dpl_1",
		"commit": map[string]any{"sha": strings.Repeat("a", 40), "shortSha": "aaaaaaa", "message": "Fix checkout", "ref": "refs/heads/main"}}
	if status == "failed" {
		d["failure"] = map[string]any{"class": "build_failed", "message": "npm run build exited with 1"}
	}
	return d
}

func writeJSON(w http.ResponseWriter, status int, v any) {
	w.Header().Set("Content-Type", "application/json")
	w.WriteHeader(status)
	_ = json.NewEncoder(w).Encode(v)
}

type harness struct {
	app      *App
	out, err *bytes.Buffer
	dir      string // working directory
	env      map[string]string
	ran      [][]string
	ranEnv   []string
}

func newHarness(t *testing.T, apiURL string) *harness {
	h := &harness{out: &bytes.Buffer{}, err: &bytes.Buffer{}, dir: t.TempDir(), env: map[string]string{}}
	h.app = &App{
		Version:     "1.2.3",
		Printer:     &output.Printer{Out: h.out, Err: h.err},
		Stdin:       strings.NewReader(""),
		Getenv:      func(k string) string { return h.env[k] },
		Getwd:       func() (string, error) { return h.dir, nil },
		ConfigDir:   filepath.Join(t.TempDir(), "config"),
		OpenBrowser: func(string) error { return nil },
		Hostname:    func() (string, error) { return "test-host", nil },
		RunCommand: func(_ context.Context, argv, env []string, _ Stdio) (int, error) {
			h.ran = append(h.ran, argv)
			h.ranEnv = env
			if argv[0] == "fail" {
				return 3, nil
			}
			return 0, nil
		},
	}
	if apiURL != "" {
		h.env["LAUNCHROOM_URL"] = apiURL
		h.env["LAUNCHROOM_TOKEN"] = testToken
	}
	return h
}

func (h *harness) run(args ...string) int {
	h.out.Reset()
	h.err.Reset()
	h.app.flags = globalFlags{}
	return h.app.Execute(context.Background(), args)
}

func (h *harness) linkTo(t *testing.T, url string) {
	if _, err := link.Write(h.dir, link.Link{URL: url, ProjectID: "prj_1", EnvironmentID: "env_1", ServiceID: "svc_1"}); err != nil {
		t.Fatal(err)
	}
}

// jsonOnly asserts stdout is exactly one JSON document.
func jsonOnly(t *testing.T, out []byte) map[string]any {
	t.Helper()
	dec := json.NewDecoder(bytes.NewReader(out))
	var v any
	if err := dec.Decode(&v); err != nil {
		t.Fatalf("stdout is not JSON: %v\n%s", err, out)
	}
	if dec.More() {
		t.Fatalf("stdout has more than one JSON document:\n%s", out)
	}
	if m, ok := v.(map[string]any); ok {
		return m
	}
	return map[string]any{"_": v}
}

func TestLoginDeviceFlowStoresToken(t *testing.T) {
	f := newFakeAPI(t)
	h := newHarness(t, "")
	var opened string
	h.app.OpenBrowser = func(u string) error { opened = u; return nil }
	speedUpPolling(t)

	if code := h.run("login", "--url", f.srv.URL+"/", "--json"); code != 0 {
		t.Fatalf("exit %d: %s", code, h.err)
	}
	res := jsonOnly(t, h.out.Bytes())
	if res["store"] != config.StoreFile || res["url"] != f.srv.URL {
		t.Fatalf("result = %v", res)
	}
	first, _, _ := strings.Cut(h.err.String(), "\n")
	var pending map[string]any
	if err := json.Unmarshal([]byte(first), &pending); err != nil || pending["event"] != "pending" || pending["userCode"] != "WDJB-MJHT" {
		t.Fatalf("first stderr line must be the pending JSON, got %q", first)
	}
	if !strings.HasSuffix(opened, "code=WDJB-MJHT") {
		t.Fatalf("opened %q", opened)
	}
	if strings.Contains(h.out.String()+h.err.String(), testToken) {
		t.Fatal("the token must never be printed")
	}
	tok, err := (config.Credentials{Dir: h.app.ConfigDir}).Load(f.srv.URL, config.StoreFile)
	if err != nil || tok != testToken {
		t.Fatalf("stored token %q %v", tok, err)
	}
	// The stored token is used afterwards (no LAUNCHROOM_TOKEN).
	if code := h.run("whoami", "--json"); code != 0 {
		t.Fatalf("whoami exit %d: %s", code, h.err)
	}
	if code := h.run("logout"); code != 0 {
		t.Fatalf("logout exit %d", code)
	}
	if code := h.run("whoami"); code != exitcode.NotSignedIn {
		t.Fatalf("after logout: exit %d", code)
	}
}

func TestUnauthenticatedIsExit3WithJSONError(t *testing.T) {
	f := newFakeAPI(t)
	h := newHarness(t, f.srv.URL)
	h.env["LAUNCHROOM_TOKEN"] = "wrong"
	if code := h.run("whoami", "--json"); code != exitcode.NotSignedIn {
		t.Fatalf("exit %d", code)
	}
	if h.out.Len() != 0 {
		t.Fatalf("stdout must be empty on error: %s", h.out)
	}
	var e struct {
		Error struct {
			Code     string `json:"code"`
			ExitCode int    `json:"exitCode"`
		} `json:"error"`
	}
	if err := json.Unmarshal(h.err.Bytes(), &e); err != nil || e.Error.Code != "unauthenticated" || e.Error.ExitCode != 3 {
		t.Fatalf("stderr = %s", h.err)
	}
}

func TestNotLinkedIsUsage(t *testing.T) {
	f := newFakeAPI(t)
	h := newHarness(t, f.srv.URL)
	if code := h.run("status"); code != exitcode.Usage {
		t.Fatalf("exit %d: %s", code, h.err)
	}
	if code := h.run("deploy", "--bogus"); code != exitcode.Usage {
		t.Fatalf("unknown flag: exit %d", code)
	}
	if code := h.run("deploy", "--sha", "abc"); code != exitcode.Usage {
		t.Fatalf("short sha: exit %d", code)
	}
}

func TestLinkAndStatus(t *testing.T) {
	f := newFakeAPI(t)
	h := newHarness(t, f.srv.URL)
	if code := h.run("link", "--project", "shop", "--json"); code != 0 {
		t.Fatalf("link exit %d: %s", code, h.err)
	}
	l, _, err := link.Find(h.dir)
	if err != nil || l == nil || l.ProjectID != "prj_1" || l.EnvironmentID != "env_1" || l.ServiceID != "svc_1" {
		t.Fatalf("link = %+v %v", l, err)
	}
	if code := h.run("status", "--json"); code != 0 {
		t.Fatalf("status exit %d: %s", code, h.err)
	}
	st := jsonOnly(t, h.out.Bytes())
	if svcs, _ := st["services"].([]any); len(svcs) != 1 {
		t.Fatalf("status = %v", st)
	}
	if code := h.run("status"); code != 0 || !strings.Contains(h.out.String(), "web") {
		t.Fatalf("human status: %d %s", code, h.out)
	}
}

func TestDeployFollowsUntilLive(t *testing.T) {
	f := newFakeAPI(t)
	h := newHarness(t, f.srv.URL)
	h.linkTo(t, f.srv.URL)
	if code := h.run("deploy", "--ref", "main"); code != 0 {
		t.Fatalf("exit %d: %s", code, h.err)
	}
	if !strings.Contains(h.out.String(), "npm run build") || !strings.Contains(h.out.String(), "web is live") {
		t.Fatalf("output: %s", h.out)
	}
	if f.deployments[0]["service"] != "svc_1" || f.deployments[0]["ref"] != "main" {
		t.Fatalf("request = %v", f.deployments[0])
	}
}

func TestDeployFailureExit10(t *testing.T) {
	f := newFakeAPI(t)
	f.finalStatus = "failed"
	h := newHarness(t, f.srv.URL)
	h.linkTo(t, f.srv.URL)
	if code := h.run("deploy", "--json"); code != exitcode.DeploymentFailed {
		t.Fatalf("exit %d: %s", code, h.err)
	}
	// --json: stdout is the final deployments, no log lines.
	var ds []map[string]any
	if err := json.Unmarshal(h.out.Bytes(), &ds); err != nil || len(ds) != 1 || ds[0]["status"] != "failed" {
		t.Fatalf("stdout = %s", h.out)
	}
	if !strings.Contains(h.err.String(), "npm run build exited with 1") {
		t.Fatalf("stderr = %s", h.err)
	}
}

func TestDeployDetach(t *testing.T) {
	f := newFakeAPI(t)
	h := newHarness(t, f.srv.URL)
	h.linkTo(t, f.srv.URL)
	if code := h.run("deploy", "--redeploy", "--detach", "--json"); code != 0 {
		t.Fatalf("exit %d: %s", code, h.err)
	}
	if f.deployments[0]["redeploy"] != true {
		t.Fatalf("request = %v", f.deployments[0])
	}
	var ds []map[string]any
	if err := json.Unmarshal(h.out.Bytes(), &ds); err != nil || ds[0]["status"] != "queued" {
		t.Fatalf("stdout = %s", h.out)
	}
}

func TestEnvSetCreatesOrUpdates(t *testing.T) {
	f := newFakeAPI(t)
	h := newHarness(t, f.srv.URL)
	h.linkTo(t, f.srv.URL)
	if code := h.run("env", "set", "APP_NAME=store", "NEW_ONE=x=y", "--secret", "--json"); code != 0 {
		t.Fatalf("exit %d: %s", code, h.err)
	}
	if len(f.patched) != 1 || f.patched[0]["id"] != "var_1" || f.patched[0]["value"] != "store" || f.patched[0]["type"] != "secret" {
		t.Fatalf("patched = %v", f.patched)
	}
	if len(f.created) != 1 || f.created[0]["key"] != "NEW_ONE" || f.created[0]["value"] != "x=y" || f.created[0]["type"] != "secret" {
		t.Fatalf("created = %v", f.created)
	}
	if code := h.run("env", "set", "1BAD=x"); code != exitcode.Usage {
		t.Fatalf("bad key: exit %d", code)
	}
	if code := h.run("env", "rm", "API_KEY"); code != 0 || len(f.deleted) != 1 || f.deleted[0] != "var_2" {
		t.Fatalf("rm: %v %s", f.deleted, h.err)
	}
	if code := h.run("env", "rm", "NOPE"); code != exitcode.NotFound {
		t.Fatalf("rm missing: exit %d", code)
	}
}

func TestEnvLsNeverShowsSecrets(t *testing.T) {
	f := newFakeAPI(t)
	h := newHarness(t, f.srv.URL)
	h.linkTo(t, f.srv.URL)
	if code := h.run("env", "ls"); code != 0 {
		t.Fatalf("exit %d", code)
	}
	if !strings.Contains(h.out.String(), "APP_NAME") || !strings.Contains(h.out.String(), "secret") {
		t.Fatalf("output %s", h.out)
	}
}

func TestEnvPull(t *testing.T) {
	f := newFakeAPI(t)
	h := newHarness(t, f.srv.URL)
	h.linkTo(t, f.srv.URL)
	if err := os.Mkdir(filepath.Join(h.dir, ".git"), 0o755); err != nil {
		t.Fatal(err)
	}
	if code := h.run("env", "pull", "--json"); code != 0 {
		t.Fatalf("exit %d: %s", code, h.err)
	}
	res := jsonOnly(t, h.out.Bytes())
	if warnings, _ := res["warnings"].([]any); len(warnings) != 1 {
		t.Fatalf("expected a gitignore warning: %v", res)
	}
	file := filepath.Join(h.dir, ".env")
	b, err := os.ReadFile(file)
	if err != nil {
		t.Fatal(err)
	}
	if !strings.Contains(string(b), "API_KEY=s3cr3t") || !strings.Contains(string(b), `MULTI="a b\nc"`) {
		t.Fatalf(".env = %s", b)
	}
	if info, _ := os.Stat(file); runtime.GOOS != "windows" && info.Mode().Perm() != 0o600 {
		t.Fatalf("mode %04o", info.Mode().Perm())
	}
	// Exists, no TTY, no --yes: refuse.
	if code := h.run("env", "pull"); code != exitcode.Usage {
		t.Fatalf("overwrite without --yes: exit %d", code)
	}
	// Ignored now, secrets withheld by the server.
	if err := os.WriteFile(filepath.Join(h.dir, ".gitignore"), []byte(".env\n"), 0o644); err != nil {
		t.Fatal(err)
	}
	f.secretsOK = false
	if code := h.run("env", "pull", "--yes"); code != 0 {
		t.Fatalf("exit %d: %s", code, h.err)
	}
	if strings.Contains(h.err.String(), "not git-ignored") || !strings.Contains(h.err.String(), "API_KEY") {
		t.Fatalf("stderr = %s", h.err)
	}
	b, _ = os.ReadFile(file)
	if strings.Contains(string(b), "s3cr3t") {
		t.Fatal("withheld secret written")
	}
}

func TestRunInjectsEnvAndPassesExitCode(t *testing.T) {
	f := newFakeAPI(t)
	h := newHarness(t, f.srv.URL)
	h.linkTo(t, f.srv.URL)
	if code := h.run("run", "--", "node", "server.js", "--port", "3000"); code != 0 {
		t.Fatalf("exit %d: %s", code, h.err)
	}
	if strings.Join(h.ran[0], " ") != "node server.js --port 3000" {
		t.Fatalf("argv = %v", h.ran[0])
	}
	found := false
	for _, kv := range h.ranEnv {
		if kv == "API_KEY=s3cr3t" {
			found = true
		}
	}
	if !found {
		t.Fatal("env not injected")
	}
	if code := h.run("run", "fail"); code != 3 {
		t.Fatalf("child exit code must pass through, got %d (%s)", code, h.err)
	}
	if h.err.Len() != 0 {
		t.Fatalf("a failing child prints no launchroom error: %s", h.err)
	}
}

func TestMergeEnvLaunchroomWins(t *testing.T) {
	got := mergeEnv([]string{"A=local", "PATH=/bin"}, map[string]string{"A": "remote"})
	if strings.Join(got, ",") != "A=remote,PATH=/bin" {
		t.Fatalf("got %v", got)
	}
}

func TestOpenPrintsURLWhenNoBrowser(t *testing.T) {
	f := newFakeAPI(t)
	h := newHarness(t, f.srv.URL)
	h.linkTo(t, f.srv.URL)
	h.app.OpenBrowser = func(string) error { return io.EOF }
	if code := h.run("open"); code != 0 || strings.TrimSpace(h.out.String()) != "http://x/p/prj_1/env_1" {
		t.Fatalf("open: %d %q", code, h.out)
	}
}

func speedUpPolling(t *testing.T) {
	t.Helper()
	old := newLoginClient
	newLoginClient = func(url, ua string) *loginClient {
		c := old(url, ua)
		c.Sleep = func(context.Context, time.Duration) error { return nil }
		return c
	}
	t.Cleanup(func() { newLoginClient = old })
}
