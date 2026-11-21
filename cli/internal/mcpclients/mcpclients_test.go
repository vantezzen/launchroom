package mcpclients

import (
	"encoding/json"
	"errors"
	"strings"
	"testing"
)

func TestMergeKeepsOtherServersAndIsIdempotent(t *testing.T) {
	c, _ := Find("cursor")
	existing := []byte(`{"mcpServers":{"other":{"command":"x"}},"theme":"dark"}`)
	out, changed, err := c.Merge(existing, "launchroom", []string{"mcp"})
	if err != nil || !changed {
		t.Fatalf("merge = %v %v", changed, err)
	}
	var doc map[string]any
	if err := json.Unmarshal(out, &doc); err != nil {
		t.Fatal(err)
	}
	servers := doc["mcpServers"].(map[string]any)
	if doc["theme"] != "dark" || servers["other"] == nil || servers["launchroom"].(map[string]any)["command"] != "launchroom" {
		t.Errorf("merged = %s", out)
	}
	if strings.Contains(string(out), "token") {
		t.Error("an entry never holds a token")
	}
	again, changed, err := c.Merge(out, "launchroom", []string{"mcp"})
	if err != nil || changed || string(again) != string(out) {
		t.Errorf("a second merge changes nothing: %v %v", changed, err)
	}
}

func TestMergeVSCodeAndNewFiles(t *testing.T) {
	c, _ := Find("vscode")
	out, changed, err := c.Merge(nil, "launchroom", []string{"mcp"})
	if err != nil || !changed || !strings.Contains(string(out), `"servers"`) || !strings.Contains(string(out), `"type": "stdio"`) {
		t.Errorf("vscode = %s %v", out, err)
	}
}

func TestMergeRefusesWhatItCannotRead(t *testing.T) {
	c, _ := Find("cursor")
	for _, bad := range []string{"{ // comment\n}", `{"mcpServers": []}`} {
		if _, _, err := c.Merge([]byte(bad), "launchroom", []string{"mcp"}); !errors.Is(err, ErrUnreadable) {
			t.Errorf("%q: %v", bad, err)
		}
	}
}

func TestCodexTOML(t *testing.T) {
	c, _ := Find("codex")
	out, changed, err := c.Merge([]byte("model = \"o3\"\n"), "/home/ada/.local/bin/launchroom", []string{"mcp"})
	want := "model = \"o3\"\n\n[mcp_servers.launchroom]\ncommand = \"/home/ada/.local/bin/launchroom\"\nargs = [\"mcp\"]\n"
	if err != nil || !changed || string(out) != want {
		t.Errorf("codex = %q", out)
	}
	if _, changed, _ := c.Merge(out, "x", nil); changed {
		t.Error("an existing table is left alone")
	}
	if got := c.Command("launchroom", []string{"mcp", "--dir", "/my project"}); got != "codex mcp add launchroom -- launchroom mcp --dir '/my project'" {
		t.Errorf("command = %s", got)
	}
}

func TestPaths(t *testing.T) {
	c, _ := Find("claude-desktop")
	if got := c.GlobalPath("/Users/ada", "darwin"); got != "/Users/ada/Library/Application Support/Claude/claude_desktop_config.json" {
		t.Errorf("path = %s", got)
	}
	if c.Project != "" || !c.NeedsDir {
		t.Error("Claude Desktop has no project file and needs --dir")
	}
	if _, ok := Find("emacs"); ok {
		t.Error("unknown client")
	}
}
