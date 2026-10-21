package commands

import (
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"sync"
	"testing"

	"github.com/vantezzen/launchroom/cli/internal/exitcode"
)

const exported = "{\n  \"$schema\": \"https://launchroom.vantezzen.io/schema/launchroom.v1.json\",\n  \"version\": 1,\n  \"services\": {}\n}\n"

type configAPI struct {
	srv      *httptest.Server
	mu       sync.Mutex
	posted   []string
	revision string
}

func newConfigAPI(t *testing.T) *configAPI {
	f := &configAPI{}
	mux := http.NewServeMux()
	env := map[string]any{"id": "env_1", "projectId": "prj_1", "name": "production", "slug": "production", "kind": "production"}
	mux.HandleFunc("GET /api/v1/environments/env_1", func(w http.ResponseWriter, _ *http.Request) {
		writeJSON(w, 200, map[string]any{"data": env})
	})
	mux.HandleFunc("GET /api/v1/environments/env_1/config", func(w http.ResponseWriter, r *http.Request) {
		if r.Header.Get("Authorization") != "Bearer "+testToken {
			writeJSON(w, 401, map[string]any{"error": map[string]any{"code": "unauthenticated", "message": "Sign in again."}})
			return
		}
		f.mu.Lock()
		f.revision = r.URL.Query().Get("revision")
		f.mu.Unlock()
		writeJSON(w, 200, map[string]any{"data": map[string]any{"path": "launchroom.json", "content": exported, "revision": nil}})
	})
	check := func(diff bool) http.HandlerFunc {
		return func(w http.ResponseWriter, r *http.Request) {
			var body map[string]string
			_ = json.NewDecoder(r.Body).Decode(&body)
			f.mu.Lock()
			f.posted = append(f.posted, body["content"])
			f.mu.Unlock()
			if strings.Contains(body["content"], `"port": "3000"`) {
				writeJSON(w, 200, map[string]any{"data": map[string]any{"valid": false, "errors": []any{map[string]any{"message": `"port" should be a whole number.`, "line": 3, "column": 39, "path": "services.web.deploy.port", "hint": "Remove the quotes: 3000.", "excerpt": []any{}}}, "warnings": []any{}, "ignored": []any{}, "changes": []any{}}})
				return
			}
			changes := []any{}
			if diff {
				changes = append(changes,
					map[string]any{"path": "services.svc_1.scaling.replicas", "kind": "changed", "effect": "restart", "label": "web: replicas", "before": "1", "after": "3"},
					map[string]any{"path": "services.svc_9", "kind": "added", "effect": "new", "label": "redis: new database service"},
				)
			}
			writeJSON(w, 200, map[string]any{"data": map[string]any{"valid": true, "errors": []any{}, "warnings": []any{}, "ignored": []any{map[string]any{"message": "web: domains are ignored in previews."}}, "changes": changes}})
		}
	}
	mux.HandleFunc("POST /api/v1/environments/env_1/config/validate", check(false))
	mux.HandleFunc("POST /api/v1/environments/env_1/config/diff", check(true))
	f.srv = httptest.NewServer(mux)
	t.Cleanup(f.srv.Close)
	return f
}

func TestConfigPullWritesTheFileAndAsksBeforeOverwriting(t *testing.T) {
	f := newConfigAPI(t)
	h := newHarness(t, f.srv.URL)

	if code := h.run("config", "pull", "--environment", "env_1", "--revision", "4"); code != 0 {
		t.Fatalf("exit %d: %s", code, h.err)
	}
	b, err := os.ReadFile(filepath.Join(h.dir, "launchroom.json"))
	if err != nil || string(b) != exported {
		t.Fatalf("file = %q, %v", b, err)
	}
	if f.revision != "4" {
		t.Fatalf("revision query = %q", f.revision)
	}
	if code := h.run("config", "pull", "--environment", "env_1"); code != exitcode.Usage {
		t.Fatalf("overwrite without --yes: exit %d", code)
	}
	if code := h.run("config", "pull", "--environment", "env_1", "--yes", "--json"); code != 0 {
		t.Fatalf("exit %d: %s", code, h.err)
	}
	if res := jsonOnly(t, h.out.Bytes()); res["file"] != filepath.Join(h.dir, "launchroom.json") {
		t.Fatalf("json = %v", res)
	}
}

func TestConfigValidateChecksSyntaxLocallyFirst(t *testing.T) {
	f := newConfigAPI(t)
	h := newHarness(t, f.srv.URL)
	file := filepath.Join(h.dir, "launchroom.json")
	if err := os.WriteFile(file, []byte("{\n  \"version\": 1,\n}"), 0o644); err != nil {
		t.Fatal(err)
	}

	code := h.run("config", "validate", "--environment", "env_1")
	if code != exitcode.Refused {
		t.Fatalf("exit %d", code)
	}
	if !strings.Contains(h.out.String(), "launchroom.json:3:1  trailing comma before }") || !strings.Contains(h.out.String(), ">    3 │ }") {
		t.Fatalf("stdout = %s", h.out)
	}
	if len(f.posted) != 0 {
		t.Fatal("a file with a syntax error must not be sent")
	}
}

func TestConfigValidatePrintsServerProblemsWithTheExcerpt(t *testing.T) {
	f := newConfigAPI(t)
	h := newHarness(t, f.srv.URL)
	content := "{\n  \"version\": 1,\n  \"services\": { \"web\": { \"deploy\": { \"port\": \"3000\" } } }\n}\n"
	if err := os.WriteFile(filepath.Join(h.dir, "custom.json"), []byte(content), 0o644); err != nil {
		t.Fatal(err)
	}

	if code := h.run("config", "validate", "custom.json", "--environment", "env_1"); code != exitcode.Refused {
		t.Fatalf("exit %d: %s", code, h.err)
	}
	out := h.out.String()
	if !strings.Contains(out, `custom.json:3:39  "port" should be a whole number.`) || !strings.Contains(out, "Remove the quotes: 3000.") || !strings.Contains(out, "^") {
		t.Fatalf("stdout = %s", out)
	}
	if f.posted[0] != content {
		t.Fatal("the file must be sent as it is")
	}

	if code := h.run("config", "validate", "custom.json", "--environment", "env_1", "--json"); code != exitcode.Refused {
		t.Fatalf("json exit %d", code)
	}
	if res := jsonOnly(t, h.out.Bytes()); res["valid"] != false {
		t.Fatalf("json = %v", res)
	}
}

func TestConfigDiffListsChanges(t *testing.T) {
	f := newConfigAPI(t)
	h := newHarness(t, f.srv.URL)
	if err := os.WriteFile(filepath.Join(h.dir, "launchroom.json"), []byte(exported), 0o644); err != nil {
		t.Fatal(err)
	}

	if code := h.run("config", "diff", "--environment", "env_1"); code != 0 {
		t.Fatalf("exit %d: %s", code, h.err)
	}
	out := h.out.String()
	for _, want := range []string{"~ web: replicas  1 → 3 (restart)", "+ redis: new database service (new)", "– web: domains are ignored in previews.", "2 changes. Push the file to apply them."} {
		if !strings.Contains(out, want) {
			t.Fatalf("missing %q in\n%s", want, out)
		}
	}
}

func TestConfigValidateMissingFileIsUsage(t *testing.T) {
	h := newHarness(t, "http://127.0.0.1:1")
	if code := h.run("config", "validate", "--environment", "env_1"); code != exitcode.Usage {
		t.Fatalf("exit %d", code)
	}
	if !strings.Contains(h.err.String(), "config pull") {
		t.Fatalf("stderr = %s", h.err)
	}
}
