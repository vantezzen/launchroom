package build

import (
	"bytes"
	"os"
	"path/filepath"
	"regexp"
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
	// Cmd is the final stage's CMD/ENTRYPOINT as written (display only).
	Cmd string
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
	for _, n := range res.AST.Children {
		switch strings.ToLower(n.Value) {
		case "from":
			f.Expose, f.Cmd = 0, "" // a new stage resets what the final image exposes
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
		case "cmd", "entrypoint":
			_, rest, _ := strings.Cut(strings.TrimSpace(n.Original), " ")
			f.Cmd = strings.TrimSpace(rest)
		}
	}
	if f.DevOnly == "" && f.Cmd != "" && reDevCmd.MatchString(f.Cmd) {
		f.DevOnly = "its CMD runs a development server (" + clipText(strings.TrimSpace(f.Cmd), 120) + ")"
	}
	return f, nil
}
