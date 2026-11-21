package commands

import (
	"bufio"
	"context"
	"encoding/json"
	"io"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"

	"github.com/vantezzen/launchroom/cli/internal/api"
	"github.com/vantezzen/launchroom/cli/internal/output"
)

// TestMCPOverStdio drives `launchroom mcp` like an editor: JSON-RPC lines on
// stdin, answers on stdout, nothing else on stdout.
func TestMCPOverStdio(t *testing.T) {
	f := newFakeAPI(t)
	h := newHarness(t, f.srv.URL)
	h.linkTo(t, f.srv.URL)
	inR, inW := io.Pipe()
	outR, outW := io.Pipe()
	h.app.Stdin = inR
	h.app.Printer = &output.Printer{Out: outW, Err: h.err}

	code := make(chan int, 1)
	go func() {
		code <- h.app.Execute(context.Background(), []string{"mcp"})
		_ = outW.Close()
	}()
	lines := bufio.NewScanner(outR)
	lines.Buffer(make([]byte, 1<<20), 8<<20)
	send := func(m map[string]any) {
		b, _ := json.Marshal(m)
		if _, err := inW.Write(append(b, '\n')); err != nil {
			t.Fatal(err)
		}
	}
	recv := func(id float64) map[string]any {
		for lines.Scan() {
			var m map[string]any
			if err := json.Unmarshal(lines.Bytes(), &m); err != nil {
				t.Fatalf("stdout carries only JSON-RPC: %s", lines.Bytes())
			}
			if m["id"] == id {
				return m
			}
		}
		t.Fatalf("stdout closed before answer %v (stderr: %s)", id, h.err)
		return nil
	}

	send(map[string]any{"jsonrpc": "2.0", "id": 1, "method": "initialize", "params": map[string]any{"protocolVersion": "2025-06-18", "capabilities": map[string]any{}, "clientInfo": map[string]any{"name": "e2e", "version": "1"}}})
	init := recv(1)["result"].(map[string]any)
	if init["serverInfo"].(map[string]any)["name"] != "launchroom" || init["protocolVersion"] != "2025-06-18" {
		t.Errorf("initialize = %v", init)
	}
	send(map[string]any{"jsonrpc": "2.0", "method": "notifications/initialized"})

	send(map[string]any{"jsonrpc": "2.0", "id": 2, "method": "tools/list"})
	var names []string
	for _, tool := range recv(2)["result"].(map[string]any)["tools"].([]any) {
		names = append(names, tool.(map[string]any)["name"].(string))
	}
	// The fake token has read only.
	if !strings.Contains(strings.Join(names, ","), "get_project") || strings.Contains(strings.Join(names, ","), "deploy,") {
		t.Errorf("tools = %v", names)
	}

	send(map[string]any{"jsonrpc": "2.0", "id": 3, "method": "tools/call", "params": map[string]any{"name": "whoami", "arguments": map[string]any{}}})
	res := recv(3)["result"].(map[string]any)
	text := res["content"].([]any)[0].(map[string]any)["text"].(string)
	if res["isError"] == true || !strings.Contains(text, "as Ada in team Acme") || !strings.Contains(text, "linked to project") {
		t.Errorf("whoami = %s", text)
	}

	_ = inW.Close()
	select {
	case c := <-code:
		if c != 0 {
			t.Errorf("exit = %d, stderr: %s", c, h.err)
		}
	case <-time.After(5 * time.Second):
		t.Fatal("launchroom mcp must exit when stdin closes")
	}
}

func TestMCPInstallPrintsEveryClientWithoutAToken(t *testing.T) {
	h := newHarness(t, "")
	h.env["LAUNCHROOM_TOKEN"] = testToken
	if code := h.run("mcp", "install"); code != 0 {
		t.Fatalf("exit %d: %s", code, h.err)
	}
	out := h.out.String()
	for _, want := range []string{"Claude Code", "claude mcp add launchroom -- launchroom mcp", "Cursor", ".cursor/mcp.json", "VS Code", `"type": "stdio"`, "Codex", "[mcp_servers.launchroom]", "Claude Desktop", "--dir"} {
		if !strings.Contains(out, want) {
			t.Errorf("install output lacks %q:\n%s", want, out)
		}
	}
	if strings.Contains(out, testToken) {
		t.Error("a token never goes into the snippets")
	}
}

func TestMCPInstallWrite(t *testing.T) {
	h := newHarness(t, "")
	if code := h.run("mcp", "install", "--client", "cursor", "--write"); code != 2 {
		t.Errorf("--write without a terminal needs --yes: exit %d", code)
	}
	if code := h.run("mcp", "install", "--write"); code != 2 {
		t.Errorf("--write needs --client: exit %d", code)
	}
	if code := h.run("mcp", "install", "--client", "emacs"); code != 2 {
		t.Errorf("an unknown client is a usage error: exit %d", code)
	}
	path := filepath.Join(h.dir, ".cursor", "mcp.json")
	if err := os.MkdirAll(filepath.Dir(path), 0o755); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(path, []byte(`{"mcpServers":{"other":{"command":"x"}}}`), 0o600); err != nil {
		t.Fatal(err)
	}
	if code := h.run("mcp", "install", "--client", "cursor", "--write", "--yes"); code != 0 {
		t.Fatalf("exit %d: %s", code, h.err)
	}
	b, _ := os.ReadFile(path)
	if !strings.Contains(string(b), `"other"`) || !strings.Contains(string(b), `"launchroom"`) || !strings.Contains(string(b), `"mcp"`) {
		t.Errorf("file = %s", b)
	}
	if st, _ := os.Stat(path); st.Mode().Perm() != 0o600 {
		t.Errorf("the file keeps its mode: %v", st.Mode())
	}
	if code := h.run("mcp", "install", "--client", "cursor", "--write", "--yes"); code != 0 || !strings.Contains(h.err.String(), "already starts launchroom") {
		t.Errorf("a second write: exit %d, %s", code, h.err)
	}
	if err := os.WriteFile(path, []byte("{ // comments\n}"), 0o600); err != nil {
		t.Fatal(err)
	}
	if code := h.run("mcp", "install", "--client", "cursor", "--write", "--yes"); code != 6 {
		t.Errorf("an unreadable file is left alone: exit %d", code)
	}
}

func TestLoginAbilitiesPreselect(t *testing.T) {
	got := withQuery("https://dash.example/cli/login?code=WDJB-MJHT", "abilities", "read,deploy,env:read")
	if got != "https://dash.example/cli/login?abilities=read%2Cdeploy%2Cenv%3Aread&code=WDJB-MJHT" {
		t.Errorf("url = %s", got)
	}
	h := newHarness(t, "")
	if code := h.run("login", "--url", "https://dash.example", "--abilities", "read,root"); code != 2 {
		t.Errorf("an unknown ability is a usage error: exit %d", code)
	}
	if code := h.run("login", "--url", "https://dash.example", "--abilities", "read", "--full-access"); code != 2 {
		t.Errorf("--abilities and --full-access together are a usage error: exit %d", code)
	}
}

func TestLoginFullAccessPreselect(t *testing.T) {
	f := newFakeAPI(t)
	h := newHarness(t, "")
	var opened string
	h.app.OpenBrowser = func(u string) error { opened = u; return nil }
	speedUpPolling(t)

	if code := h.run("login", "--url", f.srv.URL, "--full-access", "--json"); code != 0 {
		t.Fatalf("exit %d: %s", code, h.err)
	}
	if !strings.Contains(opened, "access=full") || strings.Contains(opened, "abilities=") {
		t.Errorf("opened %q", opened)
	}
}

func TestAccessLine(t *testing.T) {
	if got := accessLine(api.Token{Abilities: []string{"read", "deploy"}}); got != "read, deploy" {
		t.Errorf("custom = %q", got)
	}
	if got := accessLine(api.Token{Abilities: []string{"read", "deploy"}, FullAccess: true}); got != "Full access (read, deploy)" {
		t.Errorf("full = %q", got)
	}
}
