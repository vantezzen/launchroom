package build

import (
	"errors"
	"os"
	"path/filepath"
	"slices"
	"strconv"
	"strings"
	"testing"

	"github.com/vantezzen/launchroom/daemon/internal/state"
)

func writeTree(t *testing.T, dir string, files map[string]string) {
	t.Helper()
	for p, c := range files {
		full := filepath.Join(dir, filepath.FromSlash(p))
		if err := os.MkdirAll(filepath.Dir(full), 0o750); err != nil {
			t.Fatal(err)
		}
		if err := os.WriteFile(full, []byte(c), 0o644); err != nil {
			t.Fatal(err)
		}
	}
}

func inspected(ins *state.RepoInspection) (tree, files []string) {
	for _, p := range ins.Tree {
		tree = append(tree, string(p))
	}
	for _, f := range ins.Files {
		files = append(files, string(f.Path))
	}
	return tree, files
}

func TestInspectDenyListAndSelection(t *testing.T) {
	dir := t.TempDir()
	writeTree(t, dir, map[string]string{
		"package.json":            `{"name":"x"}`,
		"composer.json":           `{}`,
		"package-lock.json":       `{}`,
		".env":                    "SECRET=1",
		".env.production":         "SECRET=2",
		"config/.npmrc":           "//registry/:_authToken=x",
		"certs/server.key":        "key",
		"compose.yaml":            "services: {}",
		"docker-compose.yml":      "services: {}",
		"secrets/token.txt":       "t",
		"node_modules/a/index.js": "x",
		"README.md":               "hello",
		"src/index.ts":            "console.log(1)",
		"lib/util.ts":             "x",
		"bin.dat":                 "a\x00b",
	})
	if err := os.Symlink("/etc/passwd", filepath.Join(dir, "main.py")); err != nil {
		t.Fatal(err)
	}
	ins, err := Inspect(dir)
	if err != nil {
		t.Fatal(err)
	}
	tree, files := inspected(ins)
	for _, denied := range []string{".env", ".env.production", "config/.npmrc", "certs/server.key", "compose.yaml", "docker-compose.yml", "secrets/token.txt", "node_modules/a/index.js", "main.py"} {
		if slices.Contains(tree, denied) || slices.Contains(files, denied) {
			t.Errorf("%s was listed or read", denied)
		}
	}
	for _, want := range []string{"package.json", "composer.json", "README.md", "src/index.ts"} {
		if !slices.Contains(files, want) {
			t.Errorf("%s not read (files %v)", want, files)
		}
	}
	if !slices.Contains(tree, "package-lock.json") || slices.Contains(files, "package-lock.json") {
		t.Error("lockfiles are listed, never read")
	}
	if slices.Contains(files, "bin.dat") || slices.Contains(files, "lib/util.ts") {
		t.Errorf("unexpected files %v", files)
	}
	if err := (&state.TaskCompletion{TypeMeta: state.NewTypeMeta(state.KindTaskCompletion), Attempt: 1, Outcome: state.OutcomeFailed, Inspection: ins}).Validate(); err != nil {
		t.Fatalf("inspection does not validate: %v", err)
	}
}

func TestInspectLimitsAndTruncation(t *testing.T) {
	dir := t.TempDir()
	files := map[string]string{
		"package.json": strings.Repeat("a", state.MaxInspectedFileBytes+100),
		"README.md":    strings.Repeat("é", inspectExcerptBytes),
	}
	for i := range state.MaxInspectionTree + 10 {
		files[filepath.ToSlash(filepath.Join("pages", "p"+strings.Repeat("x", i%7), "f"+strconv.Itoa(i)+".txt"))] = "x"
	}
	writeTree(t, dir, files)
	ins, err := Inspect(dir)
	if err != nil {
		t.Fatal(err)
	}
	if len(ins.Tree) != state.MaxInspectionTree || !ins.TreeTruncated {
		t.Fatalf("tree = %d, truncated %v", len(ins.Tree), ins.TreeTruncated)
	}
	for _, f := range ins.Files {
		switch f.Path {
		case "package.json":
			if !f.Truncated || len(f.Content) != state.MaxInspectedFileBytes {
				t.Errorf("package.json: %d bytes, truncated %v", len(f.Content), f.Truncated)
			}
		case "README.md":
			if !f.Truncated || len(f.Content) > inspectExcerptBytes || !strings.HasSuffix(f.Content, "é") {
				t.Errorf("README cut badly: %d bytes", len(f.Content))
			}
		}
	}
}

func TestResolveInlineDockerfile(t *testing.T) {
	work := t.TempDir()
	app := filepath.Join(work, "src")
	writeTree(t, app, map[string]string{"www/index.html": "<p>hi</p>"})
	b := New(Options{WorkDir: work})
	job := &Job{Inputs: state.BuildInputs{Builder: state.BuilderSpec{Kind: state.BuilderDockerfile, Dockerfile: "FROM busybox:1.37\nARG GREETING\nCOPY www/ /www/\nEXPOSE 8080\nCMD [\"httpd\", \"-f\", \"-p\", \"8080\"]\n"}}}
	var lines []string
	r, err := b.resolve(app, work, job, nil, sinkFunc(func(_ string, _ state.LogStream, text string) { lines = append(lines, text) }))
	if err != nil {
		t.Fatal(err)
	}
	s := r.summary
	if s.Builder != state.BuilderDockerfile || s.BuilderReason != state.ReasonExplicit || s.DockerfilePath != "" || s.Port == nil || *s.Port != 8080 {
		t.Fatalf("summary = %+v", s)
	}
	if !r.dockerArgs["GREETING"] || r.dockerfileName != "Dockerfile" || strings.HasPrefix(r.dockerfileDir, app) {
		t.Fatalf("resolved = %+v", r)
	}
	if !slices.Contains(lines, "Building with the Dockerfile stored in launchroom") {
		t.Errorf("logs = %v", lines)
	}
	info, err := os.Stat(r.dockerfileDir)
	if err != nil || info.Mode().Perm() != 0o700 {
		t.Fatalf("dockerfile dir: %v %v", info, err)
	}
}

func TestUnplannableSiteFixture(t *testing.T) {
	src := filepath.Join("..", "..", "..", "dev", "fixtures", "unplannable-site")
	if _, err := os.Stat(src); err != nil {
		t.Skip("fixture not available")
	}
	dir := t.TempDir()
	if err := os.CopyFS(dir, os.DirFS(src)); err != nil {
		t.Fatal(err)
	}
	rp, err := planWithRailpack(dir, map[string]string{}, func(string, string) {})
	if err == nil && rp != nil {
		t.Fatal("Railpack planned the unplannable-site fixture; change its layout")
	}

	// The plan_error of an auto build must still be a valid completion
	// (regression: an empty builderReason made the dashboard refuse it, so
	// the task only ever ended with "lease expired").
	b := New(Options{})
	job := &Job{Inputs: state.BuildInputs{Builder: state.BuilderSpec{Kind: state.BuilderAuto, RailpackVersion: state.Semver(RailpackVersion)}}}
	_, rerr := b.resolve(dir, t.TempDir(), job, nil, sinkFunc(func(string, state.LogStream, string) {}))
	var be *Error
	if !errors.As(rerr, &be) || be.Class != state.FailurePlan || be.Summary == nil {
		t.Fatalf("resolve = %v", rerr)
	}
	done := &state.TaskCompletion{TypeMeta: state.NewTypeMeta(state.KindTaskCompletion), Attempt: 1, Outcome: state.OutcomeFailed, Error: "plan failed", Failure: be.Failure()}
	if err := done.Validate(); err != nil {
		t.Fatalf("plan_error completion is invalid: %v", err)
	}
}
