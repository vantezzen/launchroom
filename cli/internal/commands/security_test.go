package commands

import (
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"

	"github.com/vantezzen/launchroom/cli/internal/exitcode"
)

func securityAPI(t *testing.T, findings []map[string]any) *httptest.Server {
	t.Helper()
	mux := http.NewServeMux()
	mux.HandleFunc("GET /api/v1/security/findings", func(w http.ResponseWriter, r *http.Request) {
		if r.Header.Get("Authorization") != "Bearer "+testToken || r.URL.Query().Get("level") != "action_needed" {
			t.Errorf("request %s %v", r.URL, r.Header)
		}
		writeJSON(w, 200, map[string]any{"data": findings, "meta": map[string]any{"nextCursor": nil}})
	})
	mux.HandleFunc("GET /api/v1/security/inventory", func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Query().Get("q") != "lodash 4.17" {
			t.Errorf("q = %q", r.URL.Query().Get("q"))
		}
		writeJSON(w, 200, map[string]any{"data": map[string]any{"query": "lodash 4.17", "truncated": false, "packages": []any{
			map[string]any{"name": "lodash", "ecosystem": "npm", "versions": []any{map[string]any{"version": "4.17.20", "scope": "runtime",
				"services": []any{map[string]any{"id": "svc_1", "name": "api", "projectName": "Shop", "environmentName": "production"}},
				"findings": []any{map[string]any{"advisory": "GHSA-35jh-r3h4-6jhm"}}}}},
		}}})
	})
	srv := httptest.NewServer(mux)
	t.Cleanup(srv.Close)
	return srv
}

func TestSecurityFailsOnActionNeeded(t *testing.T) {
	srv := securityAPI(t, []map[string]any{{
		"id": "fnd_1", "advisory": "GHSA-35jh-r3h4-6jhm", "severity": "high", "knownExploited": false, "package": "lodash", "version": "4.17.20",
		"fixedVersion": "4.17.21", "level": "action_needed", "reasons": []string{}, "status": "open",
		"service": map[string]any{"id": "svc_1", "name": "api", "projectName": "Shop", "environmentName": "production"},
	}})
	h := newHarness(t, srv.URL)

	if code := h.run("security"); code != 0 || !strings.Contains(h.out.String(), "lodash 4.17.20") {
		t.Fatalf("exit %d: %s %s", code, h.out, h.err)
	}
	if code := h.run("security", "--fail-on", "action-needed", "--json"); code != exitcode.SecurityActionNeeded {
		t.Fatalf("exit %d: %s", code, h.err)
	}
	var out struct {
		Findings []map[string]any `json:"findings"`
	}
	if err := json.Unmarshal(h.out.Bytes(), &out); err != nil || len(out.Findings) != 1 {
		t.Fatalf("stdout = %s", h.out)
	}
	if code := h.run("security", "--fail-on", "everything"); code != exitcode.Usage {
		t.Fatalf("bad --fail-on: exit %d", code)
	}
}

func TestSecurityPassesWithoutFindings(t *testing.T) {
	h := newHarness(t, securityAPI(t, []map[string]any{}).URL)
	if code := h.run("security", "--fail-on", "action-needed"); code != 0 || !strings.Contains(h.out.String(), "No finding needs action.") {
		t.Fatalf("exit %d: %s", code, h.out)
	}
}

func TestSecurityInventory(t *testing.T) {
	h := newHarness(t, securityAPI(t, nil).URL)
	if code := h.run("security", "inventory", "lodash", "4.17"); code != 0 {
		t.Fatalf("exit %d: %s", code, h.err)
	}
	if !strings.Contains(h.out.String(), "4.17.20") || !strings.Contains(h.out.String(), "GHSA-35jh-r3h4-6jhm") {
		t.Fatalf("stdout = %s", h.out)
	}
}
