// Package mcpclients knows where AI coding tools keep their MCP server
// configuration and how to add launchroom's entry to it
// (`launchroom mcp install`, docs/16-api-and-cli.md §7.6). The entry is the
// command only, never a token: the server reads the CLI's credentials.
package mcpclients

import (
	"bytes"
	"encoding/json"
	"errors"
	"fmt"
	"path/filepath"
	"reflect"
	"strconv"
	"strings"
)

// Formats of configuration files.
const (
	// FormatMCPServers is JSON with {"mcpServers": {name: {command, args}}}.
	FormatMCPServers = "mcpServers"
	// FormatVSCode is VS Code's {"servers": {name: {type: "stdio", command, args}}}.
	FormatVSCode = "vscode"
	// FormatCodexTOML is Codex's TOML table [mcp_servers.name].
	FormatCodexTOML = "codex-toml"
)

// ServerName is the entry's key in every client.
const ServerName = "launchroom"

// Client is one AI coding tool.
type Client struct {
	ID   string
	Name string
	// Project is the configuration file relative to the project directory
	// ("" when the tool has none per project).
	Project string
	// Global is the user-wide file relative to the home directory, per OS
	// ("" when none); see GlobalPath.
	Global map[string]string
	Format string
	// Command is a CLI that adds the entry itself, preferred when the tool
	// has one ("" when not).
	Command func(command string, args []string) string
	// NeedsDir: the tool doesn't start servers in a project, so the entry
	// names the project with --dir.
	NeedsDir bool
}

// Clients are the supported tools, in the order they are listed.
var Clients = []Client{
	{
		ID: "claude-code", Name: "Claude Code", Project: ".mcp.json", Format: FormatMCPServers,
		Command: func(command string, args []string) string {
			return "claude mcp add " + ServerName + " -- " + shellJoin(append([]string{command}, args...))
		},
	},
	{ID: "cursor", Name: "Cursor", Project: filepath.Join(".cursor", "mcp.json"), Global: everywhere(filepath.Join(".cursor", "mcp.json")), Format: FormatMCPServers},
	{ID: "vscode", Name: "VS Code", Project: filepath.Join(".vscode", "mcp.json"), Format: FormatVSCode},
	{
		ID: "codex", Name: "Codex", Global: everywhere(filepath.Join(".codex", "config.toml")), Format: FormatCodexTOML,
		Command: func(command string, args []string) string {
			return "codex mcp add " + ServerName + " -- " + shellJoin(append([]string{command}, args...))
		},
	},
	{ID: "windsurf", Name: "Windsurf", Global: everywhere(filepath.Join(".codeium", "windsurf", "mcp_config.json")), Format: FormatMCPServers},
	{ID: "gemini", Name: "Gemini CLI", Project: filepath.Join(".gemini", "settings.json"), Global: everywhere(filepath.Join(".gemini", "settings.json")), Format: FormatMCPServers},
	{
		ID: "claude-desktop", Name: "Claude Desktop", Format: FormatMCPServers, NeedsDir: true,
		Global: map[string]string{
			"darwin":  filepath.Join("Library", "Application Support", "Claude", "claude_desktop_config.json"),
			"windows": filepath.Join("AppData", "Roaming", "Claude", "claude_desktop_config.json"),
			"linux":   filepath.Join(".config", "Claude", "claude_desktop_config.json"),
		},
	},
}

func everywhere(path string) map[string]string {
	return map[string]string{"darwin": path, "windows": path, "linux": path}
}

// Find returns the client with id.
func Find(id string) (Client, bool) {
	for _, c := range Clients {
		if c.ID == id {
			return c, true
		}
	}
	return Client{}, false
}

// IDs lists the client ids.
func IDs() []string {
	ids := make([]string, len(Clients))
	for i, c := range Clients {
		ids[i] = c.ID
	}
	return ids
}

// GlobalPath is the user-wide file under home on goos ("" when none).
func (c Client) GlobalPath(home, goos string) string {
	rel, ok := c.Global[goos]
	if !ok {
		return ""
	}
	return filepath.Join(home, rel)
}

// entry is the server's configuration object in a JSON file.
func (c Client) entry(command string, args []string) map[string]any {
	e := map[string]any{"command": command, "args": anySlice(args)}
	if c.Format == FormatVSCode {
		e["type"] = "stdio"
	}
	return e
}

func anySlice(args []string) []any {
	out := make([]any, len(args))
	for i, a := range args {
		out[i] = a
	}
	return out
}

func (c Client) key() string {
	if c.Format == FormatVSCode {
		return "servers"
	}
	return "mcpServers"
}

// Snippet is the configuration to add, as it appears in the file.
func (c Client) Snippet(command string, args []string) string {
	if c.Format == FormatCodexTOML {
		return codexTable(command, args)
	}
	b, _ := json.MarshalIndent(map[string]any{c.key(): map[string]any{ServerName: c.entry(command, args)}}, "", "  ")
	return string(b) + "\n"
}

// ErrUnreadable means the file exists but isn't plain JSON (comments, another
// syntax); it is left alone and the snippet printed instead.
var ErrUnreadable = errors.New("not plain JSON")

// Merge adds launchroom's entry to a file's content (nil: a new file). It
// keeps every other entry; changed is false when the entry is already there.
func (c Client) Merge(existing []byte, command string, args []string) (out []byte, changed bool, err error) {
	if c.Format == FormatCodexTOML {
		return mergeTOML(existing, command, args)
	}
	doc := map[string]any{}
	if len(bytes.TrimSpace(existing)) > 0 {
		if err := json.Unmarshal(existing, &doc); err != nil {
			return nil, false, fmt.Errorf("%w: %w", ErrUnreadable, err)
		}
	}
	servers, _ := doc[c.key()].(map[string]any)
	if doc[c.key()] != nil && servers == nil {
		return nil, false, fmt.Errorf("%w: %q is not an object", ErrUnreadable, c.key())
	}
	if servers == nil {
		servers = map[string]any{}
	}
	want := c.entry(command, args)
	if have, ok := servers[ServerName]; ok && reflect.DeepEqual(have, want) {
		return existing, false, nil
	}
	servers[ServerName] = want
	doc[c.key()] = servers
	b, err := json.MarshalIndent(doc, "", "  ")
	if err != nil {
		return nil, false, err
	}
	return append(b, '\n'), true, nil
}

func codexTable(command string, args []string) string {
	quoted := make([]string, len(args))
	for i, a := range args {
		quoted[i] = strconv.Quote(a)
	}
	return fmt.Sprintf("[mcp_servers.%s]\ncommand = %s\nargs = [%s]\n", ServerName, strconv.Quote(command), strings.Join(quoted, ", "))
}

// mergeTOML appends the table unless the file already has one for
// launchroom (then it is left as it is: a TOML file is edited by hand).
func mergeTOML(existing []byte, command string, args []string) ([]byte, bool, error) {
	header := "[mcp_servers." + ServerName + "]"
	for _, line := range strings.Split(string(existing), "\n") {
		if strings.TrimSpace(line) == header {
			return existing, false, nil
		}
	}
	out := bytes.TrimRight(existing, "\n")
	if len(out) > 0 {
		out = append(out, '\n', '\n')
	}
	return append(out, codexTable(command, args)...), true, nil
}

// shellJoin quotes argv for a POSIX shell line a person pastes.
func shellJoin(argv []string) string {
	parts := make([]string, len(argv))
	for i, a := range argv {
		if a != "" && strings.IndexFunc(a, func(r rune) bool {
			return !strings.ContainsRune("/.-_:=", r) && (r < '0' || r > '9') && (r < 'a' || r > 'z') && (r < 'A' || r > 'Z')
		}) < 0 {
			parts[i] = a
			continue
		}
		parts[i] = "'" + strings.ReplaceAll(a, "'", `'\''`) + "'"
	}
	return strings.Join(parts, " ")
}
