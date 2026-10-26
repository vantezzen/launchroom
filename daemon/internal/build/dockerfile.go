package build

import (
	"bytes"
	"os"
	"path/filepath"
	"regexp"
	"slices"
	"strconv"
	"strings"

	"github.com/moby/buildkit/frontend/dockerfile/parser"

	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// dockerfileFacts are what launchroom reads from a Dockerfile.
type dockerfileFacts struct {
	// Args are names declared with ARG (any stage).
	Args map[string]bool
	// Expose is the first EXPOSEd TCP port of the final stage (0 = none).
	Expose int
	// DevOnly explains why the Dockerfile looks like a development setup ("" = looks fine).
	DevOnly string
	// Cmd is the final stage's command (ENTRYPOINT + CMD) as a shell would
	// print it: exec-form words quoted where needed, shell form as written
	// (display only, and what "Override" prefills, which runs without a shell).
	Cmd string
	// CmdPort is the port the command names (--port 8000, -b 0.0.0.0:8000),
	// 0 if none or it reads $PORT; a default when the Dockerfile has no EXPOSE.
	CmdPort int
}

var reDevCmd = regexp.MustCompile(`(?i)\b(npm run dev|yarn dev|pnpm dev|bun dev|next dev|vite(\s|$|"|')|artisan serve|manage\.py runserver|runserver|nodemon|rails s(erver)?\b.*-e development)`)

// defaultDockerfiles are probed in order when the spec names none.
var defaultDockerfiles = []state.RelPath{"Dockerfile", "Containerfile"}

// readDockerfile reads name (relative to the app directory) through root,
// which refuses any path or symlink that resolves outside it.
func readDockerfile(root *os.Root, name string) (dockerfileFacts, error) {
	f := dockerfileFacts{Args: map[string]bool{}}
	path := filepath.ToSlash(name)
	raw, err := root.ReadFile(name)
	if err != nil {
		return f, err
	}
	if len(raw) > 1<<20 {
		raw = raw[:1<<20]
	}
	res, err := parser.Parse(bytes.NewReader(raw))
	if err != nil {
		return f, err
	}
	base := strings.ToLower(filepath.Base(path))
	if strings.HasSuffix(base, ".dev") || strings.Contains(base, ".dev.") || strings.HasSuffix(base, ".development") {
		f.DevOnly = "the file is named " + filepath.Base(path)
	}
	var entry, cmd []string
	for _, n := range res.AST.Children {
		switch strings.ToLower(n.Value) {
		case "from":
			f.Expose, entry, cmd = 0, nil, nil // a new stage resets what the final image exposes and runs
		case "arg":
			for a := n.Next; a != nil; a = a.Next {
				name, _, _ := strings.Cut(a.Value, "=")
				f.Args[name] = true
			}
		case "expose":
			for a := n.Next; a != nil && f.Expose == 0; a = a.Next {
				p, proto, _ := strings.Cut(a.Value, "/")
				if proto != "" && proto != "tcp" {
					continue
				}
				if v, err := strconv.Atoi(p); err == nil && v > 0 && v < 65536 {
					f.Expose = v
				}
			}
		case "cmd":
			cmd = commandWords(n)
		case "entrypoint":
			entry = commandWords(n)
		}
	}
	words := append(slices.Clone(entry), cmd...)
	f.Cmd = shellWords(words)
	f.CmdPort = commandPort(words)
	if f.DevOnly == "" && f.Cmd != "" && reDevCmd.MatchString(f.Cmd) {
		f.DevOnly = "its CMD runs a development server (" + clipText(strings.TrimSpace(f.Cmd), 120) + ")"
	}
	return f, nil
}

// commandWords are the words of a CMD or ENTRYPOINT: the exec form's
// elements, or the shell form's text as one word.
func commandWords(n *parser.Node) []string {
	if n.Attributes["json"] {
		var words []string
		for a := n.Next; a != nil; a = a.Next {
			words = append(words, a.Value)
		}
		return words
	}
	_, rest, _ := strings.Cut(strings.TrimSpace(n.Original), " ")
	if rest = strings.TrimSpace(rest); rest == "" {
		return nil
	}
	return []string{shellForm + rest}
}

// shellForm marks a shell-form command (shown as written, never quoted).
const shellForm = "\x00sh:"

// shellWords prints words as a POSIX shell would read them back: plain
// words as they are, others in single quotes (the dashboard's argv format,
// resources/js/features/projects/argv.ts).
func shellWords(words []string) string {
	out := make([]string, 0, len(words))
	for _, w := range words {
		if text, ok := strings.CutPrefix(w, shellForm); ok {
			out = append(out, text)
			continue
		}
		if w == "" || strings.ContainsAny(w, " \t\n\"'\\$`") {
			w = "'" + strings.ReplaceAll(w, "'", `'"'"'`) + "'"
		}
		out = append(out, w)
	}
	return strings.Join(out, " ")
}

var rePortValue = regexp.MustCompile(`^(?:[\w.\-\[\]:]*:)?(\d{1,5})$`)

// commandPort finds the port a server command names: --port 8000,
// --port=8000, -p 8000 (uvicorn, flask, many CLIs) and --bind/-b/--listen
// host:8000 (gunicorn, hypercorn, daphne). 0 when there is none or the
// command reads $PORT (launchroom sets PORT then).
func commandPort(words []string) int {
	var tokens []string
	for _, w := range words {
		if text, ok := strings.CutPrefix(w, shellForm); ok {
			tokens = append(tokens, strings.Fields(text)...)
		} else {
			tokens = append(tokens, w)
		}
	}
	for i, t := range tokens {
		name, value, inline := strings.Cut(t, "=")
		switch name {
		case "--port", "-p", "--bind", "-b", "--listen", "--http":
		default:
			continue
		}
		if !inline {
			if i+1 >= len(tokens) {
				continue
			}
			value = tokens[i+1]
		}
		m := rePortValue.FindStringSubmatch(strings.Trim(value, `"'`))
		if m == nil {
			continue
		}
		if p, err := strconv.Atoi(m[1]); err == nil && p > 0 && p < 65536 {
			return p
		}
	}
	return 0
}

// dockerfilePort is the port of a Dockerfile build: its EXPOSE, else the
// port its command names (logged, so the plan says where it came from).
func dockerfilePort(sink LogSink, df dockerfileFacts) *state.Port {
	switch {
	case df.Expose > 0:
		p := state.Port(df.Expose)
		return &p
	case df.CmdPort > 0:
		p := state.Port(df.CmdPort)
		sink.Line("plan", state.StreamStdout, "No EXPOSE in the Dockerfile: port "+strconv.Itoa(df.CmdPort)+" from its command")
		return &p
	}
	return nil
}
