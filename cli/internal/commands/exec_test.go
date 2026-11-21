package commands

import (
	"bufio"
	"context"
	"encoding/json"
	"fmt"
	"net/http"
	"slices"
	"strconv"
	"strings"
	"testing"
	"time"

	"github.com/vantezzen/launchroom/cli/internal/exitcode"
)

// fakeCommands adds the one-off command endpoints (D105) to the fake API.
type fakeCommands struct {
	started   []map[string]any
	cancelled int
	status    string
	exitCode  any
	errorText any
	// stream: "ok", "missing" (404, the CLI polls) or "block" (until the
	// request ends; onBlock runs once the lines are out).
	stream  string
	onBlock func()
	ability bool // false: every endpoint answers 403 with ability "run"
}

var commandLines = []any{
	map[string]any{"number": 1, "time": "2026-11-20T12:00:00Z", "stream": "stdout", "text": "Migrated: 2026_11_01_create_orders"},
	map[string]any{"number": 2, "time": "2026-11-20T12:00:01Z", "stream": "stderr", "text": "a warning"},
	map[string]any{"number": 3, "time": "2026-11-20T12:00:02Z", "stream": "system", "text": "Exited with 0"},
}

func commandRun(status string, exit, errText any) map[string]any {
	finished := status != "queued" && status != "running"
	return map[string]any{
		"id": "run_1", "status": status, "finished": finished, "argv": []string{"php", "artisan", "migrate", "--force"}, "name": nil,
		"exitCode": exit, "error": errText, "timeoutSeconds": 600, "outputTruncated": false, "service": "svc_1", "environment": "env_1",
		"deployment": "dpl_1", "server": "fra-1", "ranBy": "Ada", "viaApi": true, "cancelledBy": nil,
		"createdAt": "2026-11-20T12:00:00Z", "startedAt": nil, "finishedAt": nil, "durationMs": nil,
	}
}

func commandsAPI(t *testing.T, f *fakeAPI) *fakeCommands {
	t.Helper()
	fc := &fakeCommands{status: "succeeded", exitCode: 0, stream: "ok", ability: true}
	mux, ok := f.srv.Config.Handler.(*http.ServeMux)
	if !ok {
		t.Fatal("fake API has no mux")
	}
	handle := func(pattern string, h http.HandlerFunc) {
		mux.HandleFunc(pattern, func(w http.ResponseWriter, r *http.Request) {
			if r.Header.Get("Authorization") != "Bearer "+testToken {
				t.Errorf("%s: missing token", pattern)
			}
			if !fc.ability {
				writeJSON(w, 403, map[string]any{"error": map[string]any{"code": "forbidden", "message": "This token can't do that.", "ability": "run"}})
				return
			}
			h(w, r)
		})
	}
	handle("GET /api/v1/environments/env_1/services/svc_1/commands", func(w http.ResponseWriter, _ *http.Request) {
		writeJSON(w, 200, map[string]any{"data": map[string]any{"framework": "Laravel", "awaitingBuild": false, "commands": []any{
			map[string]any{"name": "migrate", "label": "Run migrations", "description": nil, "argv": []string{"php", "artisan", "migrate", "--force"}, "danger": true, "source": "framework"},
			map[string]any{"name": "cache", "label": "Cache config", "description": "Caches the config.", "argv": []string{"php", "artisan", "config:cache"}, "danger": false, "source": "framework"},
		}}})
	})
	handle("POST /api/v1/environments/env_1/services/svc_1/command-runs", func(w http.ResponseWriter, r *http.Request) {
		if r.Header.Get("Idempotency-Key") == "" {
			t.Error("creating a run must send an Idempotency-Key")
		}
		body := map[string]any{}
		_ = json.NewDecoder(r.Body).Decode(&body)
		f.mu.Lock()
		fc.started = append(fc.started, body)
		f.mu.Unlock()
		writeJSON(w, 201, map[string]any{"data": commandRun("queued", nil, nil)})
	})
	handle("GET /api/v1/command-runs/run_1", func(w http.ResponseWriter, _ *http.Request) {
		writeJSON(w, 200, map[string]any{"data": commandRun(fc.status, fc.exitCode, fc.errorText)})
	})
	handle("POST /api/v1/command-runs/run_1/cancel", func(w http.ResponseWriter, _ *http.Request) {
		f.mu.Lock()
		fc.cancelled++
		f.mu.Unlock()
		writeJSON(w, 200, map[string]any{"data": commandRun("running", nil, nil)})
	})
	handle("GET /api/v1/command-runs/run_1/output", func(w http.ResponseWriter, r *http.Request) {
		after, _ := strconv.Atoi(r.URL.Query().Get("after"))
		lines := []any{}
		if after < 3 {
			lines = commandLines[after:]
		}
		writeJSON(w, 200, map[string]any{"data": lines, "meta": map[string]any{"nextCursor": nil, "cursor": 3, "hasMore": false, "finished": true}})
	})
	handle("GET /api/v1/command-runs/run_1/output/stream", func(w http.ResponseWriter, r *http.Request) {
		if fc.stream == "missing" {
			writeJSON(w, 404, map[string]any{"error": map[string]any{"code": "not_found", "message": "Not found."}})
			return
		}
		w.Header().Set("Content-Type", "text/event-stream")
		lines, _ := json.Marshal(map[string]any{"lines": commandLines, "cursor": 3})
		_, _ = fmt.Fprintf(w, "event: status\ndata: {\"status\":\"running\",\"finished\":false}\n\nevent: lines\nid: 3\ndata: %s\n\n", lines)
		if fc.stream == "block" {
			w.(http.Flusher).Flush()
			fc.onBlock()
			<-r.Context().Done()
			return
		}
		end, _ := json.Marshal(map[string]any{"status": fc.status, "exitCode": fc.exitCode, "error": fc.errorText})
		_, _ = fmt.Fprintf(w, "event: end\ndata: %s\n\n", end)
	})
	return fc
}

func execHarness(t *testing.T) (*harness, *fakeCommands) {
	t.Helper()
	f := newFakeAPI(t)
	fc := commandsAPI(t, f)
	h := newHarness(t, f.srv.URL)
	h.linkTo(t, f.srv.URL)
	h.app.Sleep = func(context.Context, time.Duration) error { return nil }
	return h, fc
}

func TestExecPassesArgvAsGiven(t *testing.T) {
	h, fc := execHarness(t)
	argv := []string{"php", "artisan", "tinker", "--execute", "echo 1;", "-v", "--json", "--"}
	if code := h.run(append([]string{"exec", "--timeout", "30s", "--"}, argv...)...); code != 0 {
		t.Fatalf("exit %d: %s", code, h.err)
	}
	got := fc.started[0]
	gotArgv, _ := got["argv"].([]any)
	if len(gotArgv) != len(argv) || got["name"] != nil || got["timeoutSeconds"] != float64(30) {
		t.Fatalf("body = %v", got)
	}
	for i, a := range argv {
		if gotArgv[i] != a {
			t.Fatalf("argv[%d] = %v, want %q", i, gotArgv[i], a)
		}
	}
	if h.out.String() != "Migrated: 2026_11_01_create_orders\n" {
		t.Fatalf("stdout = %q", h.out)
	}
	for _, want := range []string{"Running php artisan migrate --force in web (production)", "a warning", "Exited with 0"} {
		if !strings.Contains(h.err.String(), want) {
			t.Fatalf("stderr misses %q:\n%s", want, h.err)
		}
	}
}

func TestExecByNameAndUsage(t *testing.T) {
	h, fc := execHarness(t)
	if code := h.run("exec", "cache"); code != 0 {
		t.Fatalf("exit %d: %s", code, h.err)
	}
	if fc.started[0]["name"] != "cache" || fc.started[0]["argv"] != nil {
		t.Fatalf("body = %v", fc.started[0])
	}
	if code := h.run("exec", "seed"); code != exitcode.NotFound || !strings.Contains(h.err.String(), "launchroom exec -- seed") {
		t.Fatalf("unknown name: exit %d: %s", code, h.err)
	}
	for _, args := range [][]string{{"exec"}, {"exec", "ls", "-la"}, {"exec", "ls", "--", "-la"}, {"exec", "--"}, {"exec", "Bad_Name"}, {"exec", "--list", "migrate"}, {"exec", "--timeout", "2h", "--", "ls"}} {
		if code := h.run(args...); code != exitcode.Usage {
			t.Errorf("%v: exit %d, want 2 (%s)", args, code, h.err)
		}
	}
	if len(fc.started) != 1 {
		t.Fatalf("started %d runs", len(fc.started))
	}
}

func TestExecChangesDataNeedsConfirmation(t *testing.T) {
	h, fc := execHarness(t)
	if code := h.run("exec", "migrate"); code != exitcode.Usage || !strings.Contains(h.err.String(), "--yes") {
		t.Fatalf("no TTY: exit %d: %s", code, h.err)
	}
	h.app.Printer.TTY = true
	h.app.Stdin = strings.NewReader("n\n")
	h.app.stdin = nil
	if code := h.run("exec", "migrate"); code != exitcode.Usage {
		t.Fatalf("declined: exit %d: %s", code, h.err)
	}
	if !strings.Contains(h.err.String(), "Run `php artisan migrate --force` in production? [y/N]") {
		t.Fatalf("prompt: %s", h.err)
	}
	if len(fc.started) != 0 {
		t.Fatalf("ran without confirmation: %v", fc.started)
	}
	h.app.Stdin = strings.NewReader("y\n")
	h.app.stdin = nil
	if code := h.run("exec", "migrate"); code != 0 {
		t.Fatalf("confirmed: exit %d: %s", code, h.err)
	}
	h.app.Printer.TTY = false
	if code := h.run("exec", "migrate", "--yes"); code != 0 || len(fc.started) != 2 || fc.started[1]["name"] != "migrate" {
		t.Fatalf("--yes: exit %d: %s %v", code, h.err, fc.started)
	}
}

func TestExecExitCodes(t *testing.T) {
	cases := []struct {
		status   string
		exit     any
		errText  any
		want     int
		stderr   string
		stream   string
		jsonMode bool
	}{
		{status: "succeeded", exit: 0, want: 0},
		{status: "failed", exit: 3, want: 3},
		{status: "failed", exit: 3, want: 3, stream: "missing"},
		{status: "timed_out", want: exitcode.CommandTimedOut, stderr: "longer than 10m0s"},
		{status: "cancelled", want: exitcode.Interrupted, stderr: "cancelled"},
		{status: "error", errText: "No server could run it.", want: exitcode.CommandFailed, stderr: "couldn't run: No server could run it."},
		{status: "error", errText: "No server could run it.", want: exitcode.CommandFailed, jsonMode: true},
	}
	for _, c := range cases {
		h, fc := execHarness(t)
		fc.status, fc.exitCode, fc.errorText = c.status, c.exit, c.errText
		if c.stream != "" {
			fc.stream = c.stream
		}
		args := []string{"exec", "--", "php", "artisan", "migrate"}
		if c.jsonMode {
			args = append([]string{"--json"}, args...)
		}
		if code := h.run(args...); code != c.want {
			t.Errorf("%s (%s): exit %d, want %d: %s", c.status, c.stream, code, c.want, h.err)
		}
		if !strings.Contains(h.err.String(), c.stderr) {
			t.Errorf("%s: stderr misses %q: %s", c.status, c.stderr, h.err)
		}
		if c.stream == "missing" && !strings.Contains(h.out.String(), "Migrated") {
			t.Errorf("polling printed no output: %s", h.out)
		}
	}
}

func TestExecJSONLines(t *testing.T) {
	h, _ := execHarness(t)
	if code := h.run("exec", "--json", "--", "php", "artisan", "migrate"); code != 0 {
		t.Fatalf("exit %d: %s", code, h.err)
	}
	var objs []map[string]any
	sc := bufio.NewScanner(strings.NewReader(h.out.String()))
	for sc.Scan() {
		var m map[string]any
		if err := json.Unmarshal(sc.Bytes(), &m); err != nil {
			t.Fatalf("not a JSON line: %q", sc.Text())
		}
		objs = append(objs, m)
	}
	if len(objs) != 4 || objs[0]["stream"] != "stdout" || objs[2]["stream"] != "system" {
		t.Fatalf("stdout = %s", h.out)
	}
	run, _ := objs[3]["run"].(map[string]any)
	if run["id"] != "run_1" || run["status"] != "succeeded" {
		t.Fatalf("last line = %v", objs[3])
	}
	if h.err.Len() != 0 {
		t.Fatalf("stderr must stay empty: %s", h.err)
	}
}

func TestExecDetach(t *testing.T) {
	h, fc := execHarness(t)
	fc.stream = "missing"
	if code := h.run("exec", "--detach", "--", "ls"); code != 0 || strings.TrimSpace(h.out.String()) != "run_1" {
		t.Fatalf("exit %d: %q %s", code, h.out, h.err)
	}
	if code := h.run("exec", "--detach", "--json", "--", "ls"); code != 0 || jsonOnly(t, h.out.Bytes())["status"] != "queued" {
		t.Fatalf("json: exit %d: %s", code, h.out)
	}
}

func TestExecInterruptCancelsTheRun(t *testing.T) {
	h, fc := execHarness(t)
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	fc.stream = "block"
	fc.onBlock = cancel
	h.app.flags = globalFlags{}
	if code := h.app.Execute(ctx, []string{"exec", "--", "sleep", "60"}); code != exitcode.Interrupted {
		t.Fatalf("exit %d: %s", code, h.err)
	}
	if fc.cancelled != 1 {
		t.Fatalf("cancelled %d times", fc.cancelled)
	}
}

func TestExecList(t *testing.T) {
	h, fc := execHarness(t)
	if code := h.run("exec", "--list"); code != 0 {
		t.Fatalf("exit %d: %s", code, h.err)
	}
	out := h.out.String()
	for _, want := range []string{"migrate", "Run migrations", "php artisan migrate --force", "changes data", "php artisan config:cache"} {
		if !strings.Contains(out, want) {
			t.Fatalf("list misses %q:\n%s", want, out)
		}
	}
	if code := h.run("exec", "--list", "--json"); code != 0 {
		t.Fatalf("json: exit %d", code)
	}
	cmds, _ := jsonOnly(t, h.out.Bytes())["commands"].([]any)
	if len(cmds) != 2 || len(fc.started) != 0 {
		t.Fatalf("commands = %v", cmds)
	}
}

func TestExecWithoutRunAbilityHints(t *testing.T) {
	h, fc := execHarness(t)
	fc.ability = false
	if code := h.run("exec", "--", "ls"); code != exitcode.NotAllowed {
		t.Fatalf("exit %d: %s", code, h.err)
	}
	if !strings.Contains(h.err.String(), `"run" ability`) || !strings.Contains(h.err.String(), "Run commands") {
		t.Fatalf("stderr = %s", h.err)
	}
}

func TestQuoteArgv(t *testing.T) {
	got := quoteArgv([]string{"php", "-r", "echo 'x';", ""})
	if got != `php -r "echo 'x';" ""` {
		t.Fatalf("got %s", got)
	}
	if !slices.Equal(strings.Fields(quoteArgv([]string{"a", "b"})), []string{"a", "b"}) {
		t.Fatal("plain args stay plain")
	}
}
