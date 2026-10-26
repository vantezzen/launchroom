package build

import (
	"context"
	"encoding/base64"
	"encoding/json"
	"errors"
	"fmt"
	"net/url"
	"os"
	"path/filepath"
	"slices"
	"strings"
	"testing"
	"time"

	bkclient "github.com/moby/buildkit/client"
	"github.com/moby/buildkit/session/auth"
	"github.com/opencontainers/go-digest"
	rpplan "github.com/railwayapp/railpack/core/plan"

	"github.com/vantezzen/launchroom/daemon/internal/build/buildkitd"
	"github.com/vantezzen/launchroom/daemon/internal/build/presets"
	"github.com/vantezzen/launchroom/daemon/internal/secret"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

func TestMasker(t *testing.T) {
	const s = "s3cr3t/value+x=="
	m := NewMasker(s, "abc", "")
	for _, form := range []string{
		s,
		base64.StdEncoding.EncodeToString([]byte(s)),
		base64.URLEncoding.EncodeToString([]byte(s)),
		base64.RawStdEncoding.EncodeToString([]byte(s)),
		url.QueryEscape(s),
		url.PathEscape(s),
	} {
		if got := m.Mask("before " + form + " after"); got != "before *** after" {
			t.Errorf("form %q not masked: %q", form, got)
		}
	}
	if got := m.Mask("abc is short"); got != "abc is short" {
		t.Errorf("short values must not be masked: %q", got)
	}
	pem := "-----BEGIN KEY-----\nMIIBVQIBADANBgkqhkiG9w0BAQEFAASCAT8w\n-----END KEY-----"
	if got := NewMasker(pem).Mask("line MIIBVQIBADANBgkqhkiG9w0BAQEFAASCAT8w"); strings.Contains(got, "MIIBVQ") {
		t.Errorf("multi-line secret line leaked: %q", got)
	}
	var nilMasker *Masker
	if nilMasker.Mask("x") != "x" {
		t.Error("nil masker")
	}
}

func TestSecretsHash(t *testing.T) {
	if SecretsHash(nil) != "" {
		t.Fatal("empty env must have no hash")
	}
	a := SecretsHash(map[string]secret.Secret{"A": "1", "B": "2"})
	b := SecretsHash(map[string]secret.Secret{"B": "2", "A": "1"})
	c := SecretsHash(map[string]secret.Secret{"A": "1", "B": "3"})
	if a != b || a == c || a.Validate() != nil {
		t.Fatalf("hash not deterministic or not value-sensitive: %s %s %s", a, b, c)
	}
}

func TestCheckCloneURL(t *testing.T) {
	ok := []string{"https://github.com/acme/shop.git", "http://git-fixtures:8080/node-hello.git", "ssh://git@github.com/a/b", "ssh://git.example.com:2222/srv/app.git"}
	for _, u := range ok {
		if _, err := checkCloneURL(state.GitURL(u), []string{"git-fixtures"}); err != nil {
			t.Errorf("%s: %v", u, err)
		}
	}
	bad := []string{"http://github.com/acme/shop.git", "file:///etc", "ssh://git:pw@github.com/a/b", "ssh://git@github.com/", "ssh://git@github.com/a'b", "https://user:pw@github.com/a/b", "https://github.com/a/b?x=1"}
	for _, u := range bad {
		if _, err := checkCloneURL(state.GitURL(u), []string{"git-fixtures"}); err == nil {
			t.Errorf("%s accepted", u)
		}
	}
}

func TestCheckTreeAndAppDir(t *testing.T) {
	root := t.TempDir()
	must := func(err error) {
		t.Helper()
		if err != nil {
			t.Fatal(err)
		}
	}
	must(os.MkdirAll(filepath.Join(root, "apps", "web"), 0o750))
	must(os.WriteFile(filepath.Join(root, "apps", "web", "package.json"), []byte("{}"), 0o644))
	must(os.Symlink("web", filepath.Join(root, "apps", "alias")))
	must(os.Symlink("../web/package.json", filepath.Join(root, "apps", "web", "link.json")))
	if err := checkTree(root); err != nil {
		t.Fatalf("relative in-repo symlinks are fine: %v", err)
	}
	if d, err := appDir(root, "apps/alias"); err != nil || !strings.HasSuffix(d, filepath.Join("apps", "web")) {
		t.Fatalf("appDir via symlink: %q %v", d, err)
	}
	if _, err := appDir(root, "missing"); err == nil {
		t.Fatal("missing root dir accepted")
	}

	evil := t.TempDir()
	must(os.Symlink("/data/daemon/keys/daemon.key", filepath.Join(evil, "package.json")))
	if err := checkTree(evil); !errors.Is(err, errUnsafeRepo) {
		t.Fatalf("absolute symlink accepted: %v", err)
	}
	evil2 := t.TempDir()
	must(os.MkdirAll(filepath.Join(evil2, "a"), 0o750))
	must(os.Symlink("../../etc", filepath.Join(evil2, "a", "up")))
	if err := checkTree(evil2); !errors.Is(err, errUnsafeRepo) {
		t.Fatalf("escaping symlink accepted: %v", err)
	}
	outside := t.TempDir()
	must(os.Symlink(outside, filepath.Join(root, "out")))
	if _, err := appDir(root, "out"); err == nil {
		t.Fatal("root dir escaping through a symlink accepted")
	}
}

func TestReadDockerfile(t *testing.T) {
	dir := t.TempDir()
	root, err := os.OpenRoot(dir)
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { _ = root.Close() })
	write := func(name, body string) (*os.Root, string) {
		if err := os.WriteFile(filepath.Join(dir, name), []byte(body), 0o644); err != nil {
			t.Fatal(err)
		}
		return root, name
	}
	f, err := readDockerfile(write("Dockerfile", "FROM node:22 AS build\nARG NPM_TOKEN\nEXPOSE 9999\nFROM node:22-slim\nARG GREETING=hi\nEXPOSE 3000/tcp 9000/udp\nCMD [\"node\", \"server.js\"]\n"))
	if err != nil {
		t.Fatal(err)
	}
	if f.Expose != 3000 || !f.Args["NPM_TOKEN"] || !f.Args["GREETING"] || f.DevOnly != "" || !strings.Contains(f.Cmd, "server.js") {
		t.Fatalf("facts %+v", f)
	}
	// Exec form prints as a shell would read it back (never the JSON array),
	// and the port comes from the command when there is no EXPOSE.
	uvi, _ := readDockerfile(write("Dockerfile", "FROM python:3.12-slim\nCMD [\"uvicorn\", \"app.main:app\", \"--host\", \"0.0.0.0\", \"--port\", \"8000\"]\n"))
	if uvi.Cmd != "uvicorn app.main:app --host 0.0.0.0 --port 8000" || uvi.CmdPort != 8000 || uvi.Expose != 0 {
		t.Errorf("uvicorn %+v", uvi)
	}
	quoted, _ := readDockerfile(write("Dockerfile", "FROM python:3.12\nENTRYPOINT [\"gunicorn\"]\nCMD [\"-b\", \"0.0.0.0:9000\", \"--log-level\", \"info warn\", \"it's\"]\n"))
	if quoted.Cmd != `gunicorn -b 0.0.0.0:9000 --log-level 'info warn' 'it'"'"'s'` || quoted.CmdPort != 9000 {
		t.Errorf("quoted %+v", quoted)
	}
	shell, _ := readDockerfile(write("Dockerfile", "FROM node:22\nCMD node server.js --port=$PORT\n"))
	if shell.Cmd != "node server.js --port=$PORT" || shell.CmdPort != 0 {
		t.Errorf("shell form %+v", shell)
	}
	flask, _ := readDockerfile(write("Dockerfile", "FROM python:3.12\nCMD flask run --host 0.0.0.0 -p 5000\n"))
	if flask.CmdPort != 5000 {
		t.Errorf("flask %+v", flask)
	}
	dev, _ := readDockerfile(write("Dockerfile.dev", "FROM node:22\nCMD npm run dev\n"))
	if dev.DevOnly == "" {
		t.Fatal("dev Dockerfile not flagged")
	}
	vite, _ := readDockerfile(write("Dockerfile", "FROM node:22\nCMD [\"npx\", \"vite\"]\n"))
	if vite.DevOnly == "" {
		t.Fatal("vite dev server CMD not flagged")
	}
}

func TestApplyPatch(t *testing.T) {
	p := rpplan.NewBuildPlan()
	p.Steps = []rpplan.Step{
		{Name: "install", Commands: []rpplan.Command{rpplan.NewExecCommand("composer install")}},
		{Name: "build", Commands: []rpplan.Command{rpplan.NewExecCommand("php artisan config:cache"), rpplan.NewExecCommand("npm run build")}},
	}
	err := applyPatch(p, presets.PlanPatch{
		StartCommand:                "node server.js",
		DeployVariables:             map[string]string{"RAILPACK_SKIP_MIGRATIONS": "true"},
		BuildVariables:              map[string]string{"NEXT_TELEMETRY_DISABLED": "1"},
		AppendBuildCommands:         []string{"cp -r a b"},
		DropBuildCommandsContaining: []string{"artisan config:cache"},
	})
	if err != nil {
		t.Fatal(err)
	}
	if p.Deploy.StartCmd != "node server.js" || p.Deploy.Variables["RAILPACK_SKIP_MIGRATIONS"] != "true" || p.Steps[1].Variables["NEXT_TELEMETRY_DISABLED"] != "1" {
		t.Fatalf("plan %+v", p)
	}
	if got := stepCommands(p, "build"); got != "npm run build && cp -r a b" {
		t.Fatalf("build commands %q", got)
	}
	if err := applyPatch(p, presets.PlanPatch{AppendBuildCommands: []string{"echo 'x'"}}); err == nil {
		t.Fatal("single quotes must be refused (railpack wraps commands in a single-quoted shell string)")
	}
	empty := rpplan.NewBuildPlan()
	if err := applyPatch(empty, presets.PlanPatch{AppendBuildCommands: []string{"x"}}); err == nil {
		t.Fatal("patching a plan without a build step must fail")
	}
}

func TestRailpackEnvUsesPlaceholdersForSecrets(t *testing.T) {
	env := railpackEnv(state.BuilderSpec{
		StartCommand: "node x.js",
		RailpackEnv:  map[state.RailpackEnvName]string{"RAILPACK_NODE_VERSION": "22"},
	}, []string{"NPM_TOKEN"})
	if env["NPM_TOKEN"] != planPlaceholder || env["RAILPACK_START_CMD"] != "node x.js" || env["RAILPACK_NODE_VERSION"] != "22" {
		t.Fatalf("env %v", env)
	}
}

type fakeTokens struct {
	got []state.RegistryScope
	err error
}

func (f *fakeTokens) Token(_ context.Context, scopes []state.RegistryScope, _ state.TaskID) (secret.Secret, error) {
	f.got = scopes
	return "jwt.token.sig", f.err
}

func TestRegistryAuthScopes(t *testing.T) {
	b := New(Options{RegistryHost: "registry.launchroom.internal:5000"})
	tokens := &fakeTokens{}
	a := newRegistryAuth("registry.launchroom.internal:5000", "tsk_01J9Z8X7W6V5T4S3R2Q1P0N9P0", tokens, b.allowedScope("svc_01J9Z8X7W6V5T4S3R2Q1P0N9N2"))
	resp, err := a.FetchToken(t.Context(), &auth.FetchTokenRequest{Host: "registry.launchroom.internal:5000", Scopes: []string{
		"repository:apps/svc_01j9z8x7w6v5t4s3r2q1p0n9n2:pull,push",
		"repository:cache/svc_01j9z8x7w6v5t4s3r2q1p0n9n2:pull,push repository:mirror/docker.io/library/node:pull,push",
		"repository:apps/svc_01j9z8x7w6v5t4s3r2q1p0n9n3:pull,push",
		"repository:library/node:pull",
		"registry:catalog:*",
	}})
	if err != nil || resp.Token != "jwt.token.sig" {
		t.Fatalf("fetch: %v", err)
	}
	want := []state.RegistryScope{
		{Repository: "apps/svc_01j9z8x7w6v5t4s3r2q1p0n9n2", Actions: []state.RegistryAction{"pull", "push"}},
		{Repository: "cache/svc_01j9z8x7w6v5t4s3r2q1p0n9n2", Actions: []state.RegistryAction{"pull", "push"}},
		{Repository: "mirror/docker.io/library/node", Actions: []state.RegistryAction{"pull"}},
	}
	if len(tokens.got) != len(want) {
		t.Fatalf("scopes %+v", tokens.got)
	}
	for i := range want {
		if tokens.got[i].Repository != want[i].Repository || !slices.Equal(tokens.got[i].Actions, want[i].Actions) {
			t.Fatalf("scope %d = %+v, want %+v", i, tokens.got[i], want[i])
		}
	}
	if _, err := a.FetchToken(t.Context(), &auth.FetchTokenRequest{Host: "registry.launchroom.internal:5000", Scopes: []string{"repository:apps/other:push"}}); err == nil {
		t.Fatal("token for a foreign repository")
	}
	// Token authority only for the launchroom registry; signatures verify.
	ta, err := a.GetTokenAuthority(t.Context(), &auth.GetTokenAuthorityRequest{Host: "registry.launchroom.internal:5000", Salt: []byte("salt")})
	if err != nil || len(ta.PublicKey) != 32 {
		t.Fatalf("authority: %v", err)
	}
	if _, err := a.GetTokenAuthority(t.Context(), &auth.GetTokenAuthorityRequest{Host: "registry-1.docker.io", Salt: []byte("salt")}); err == nil {
		t.Fatal("authority claimed for docker hub")
	}
	v, err := a.VerifyTokenAuthority(t.Context(), &auth.VerifyTokenAuthorityRequest{Host: "registry.launchroom.internal:5000", Salt: []byte("salt"), Payload: []byte("p")})
	if err != nil || len(v.Signed) != 64+1 {
		t.Fatalf("verify: %v %d", err, len(v.Signed))
	}
}

type sink struct{ lines []string }

func (s *sink) Line(step string, _ state.LogStream, text string) {
	s.lines = append(s.lines, step+"|"+text)
}

func TestLogMapper(t *testing.T) {
	s := &sink{}
	names := map[string]string{"npm ci": "install", "npm run build": "build"}
	m := newLogMapper(railpackStepper(names), s)
	now := time.Now()
	d1, d2, d3 := digest.FromString("1"), digest.FromString("2"), digest.FromString("3")
	m.status(&bkclient.SolveStatus{
		Vertexes: []*bkclient.Vertex{{Digest: d1, Name: "npm ci", Started: &now}, {Digest: d2, Name: "npm run build", Started: &now}, {Digest: d3, Name: "exporting to image", Started: &now}},
		Logs:     []*bkclient.VertexLog{{Vertex: d1, Stream: 1, Data: []byte("added 3 packages\npart")}, {Vertex: d2, Stream: 2, Data: []byte("boom\n")}},
	})
	m.status(&bkclient.SolveStatus{
		Vertexes: []*bkclient.Vertex{{Digest: d1, Name: "npm ci", Started: &now, Completed: &now}, {Digest: d2, Name: "npm run build", Started: &now, Error: "exit code: 1"}},
		Logs:     []*bkclient.VertexLog{{Vertex: d1, Stream: 1, Data: []byte("ial\n")}},
	})
	got := strings.Join(s.lines, "\n")
	for _, want := range []string{"install|#1 npm ci", "install|#1 added 3 packages", "install|#1 partial", "build|#2 boom", "push|#3 exporting to image", "install|#1 DONE", "build|#2 ERROR: exit code: 1"} {
		if !strings.Contains(got, want) {
			t.Errorf("missing %q in\n%s", want, got)
		}
	}
	if m.failedStep() != "build" || !strings.Contains(m.tail(), "boom") {
		t.Fatalf("failed step %q tail %q", m.failedStep(), m.tail())
	}
	for name, want := range map[string]string{"[builder 2/5] RUN npm ci": "builder", "[2/3] COPY . .": "build", "[internal] load build definition from Dockerfile": "setup", "exporting cache to registry": "cache"} {
		if got := dockerfileStep(name); got != want {
			t.Errorf("dockerfileStep(%q) = %q, want %q", name, got, want)
		}
	}
}

func TestClassifyAndHints(t *testing.T) {
	e := classifySolve(errors.New(`process "pnpm i" did not complete successfully: exit code: 1`), "install", "ERR_PNPM_OUTDATED_LOCKFILE Cannot install")
	if e.Class != state.FailureBuild || len(e.Hints) == 0 || e.Hints[0].Code != "pnpm-outdated-lockfile" || e.Retryable() {
		t.Fatalf("build error %+v", e)
	}
	n := classifySolve(errors.New("failed to do request: dial tcp: i/o timeout"), "push", "")
	if n.Class != state.FailureTransient || !n.Retryable() {
		t.Fatalf("transient %+v", n)
	}
	f := (&Error{Class: state.FailurePlan, Step: strings.Repeat("x", 300), Err: errors.New("x")}).Failure()
	if len(f.Step) != 128 || f.Retryable {
		t.Fatalf("failure %+v", f)
	}
	completion := &state.TaskCompletion{TypeMeta: state.NewTypeMeta(state.KindTaskCompletion), Attempt: 1, Outcome: state.OutcomeFailed, Failure: e.Failure()}
	if err := completion.Validate(); err != nil {
		t.Fatalf("failure is not a valid protocol document: %v", err)
	}
}

func TestApplyPatchBuildFilesAndDeployOnly(t *testing.T) {
	p := rpplan.NewBuildPlan()
	p.Steps = []rpplan.Step{
		{Name: "install", Commands: []rpplan.Command{rpplan.NewExecCommand("npm ci")}},
		{Name: "build", Commands: []rpplan.Command{rpplan.NewExecCommand("npm run build")}},
	}
	p.Deploy.Inputs = []rpplan.Layer{
		{Step: "packages:mise", Filter: rpplan.Filter{Include: []string{"/mise/shims"}}},
		rpplan.NewStepLayer("prune", rpplan.Filter{Include: []string{"/app/node_modules"}}),
		rpplan.NewStepLayer("build", rpplan.Filter{Include: []string{"/root/.cache", "."}, Exclude: []string{"node_modules"}}),
	}
	big := strings.Repeat("x", 5000)
	err := applyPatch(p, presets.PlanPatch{
		BuildFiles: map[string]string{"/opt/lr/b.js": big, "/opt/lr/a.js": "a"},
		DeployOnly: []string{".next/standalone"},
	})
	if err != nil {
		t.Fatal(err)
	}
	cmds := p.Steps[1].Commands
	f0, ok0 := cmds[0].(rpplan.FileCommand)
	f1, ok1 := cmds[1].(rpplan.FileCommand)
	if !ok0 || !ok1 || f0.Path != "/opt/lr/a.js" || f1.Path != "/opt/lr/b.js" || p.Steps[1].Assets[f1.Name] != big {
		t.Fatalf("files must be written first, sorted: %#v", cmds)
	}
	if _, ok := cmds[2].(rpplan.ExecCommand); !ok {
		t.Fatalf("npm run build must follow the files: %#v", cmds)
	}
	if len(p.Deploy.Inputs) != 2 || p.Deploy.Inputs[0].Step != "packages:mise" || p.Deploy.Inputs[1].Step != "build" ||
		!slices.Equal(p.Deploy.Inputs[1].Include, []string{".next/standalone"}) || len(p.Deploy.Inputs[1].Exclude) != 0 {
		t.Fatalf("deploy inputs = %+v", p.Deploy.Inputs)
	}
	shown, err := summaryPlan(p)
	if err != nil {
		t.Fatal(err)
	}
	if strings.Contains(string(shown), big) || !strings.Contains(string(shown), "(vendored file: 5000 bytes, sha256:") {
		t.Fatalf("summary plan must not carry the vendored files: %d bytes", len(shown))
	}
	if raw, _ := json.Marshal(p); !strings.Contains(string(raw), big) {
		t.Fatal("the plan for BuildKit lost the file content")
	}

	for _, bad := range []presets.PlanPatch{
		{BuildFiles: map[string]string{"relative.js": "x"}},
		{BuildFiles: map[string]string{"/opt/../etc/x": "x"}},
		{DeployOnly: []string{"/abs"}},
		{DeployOnly: []string{"../up"}},
	} {
		q := rpplan.NewBuildPlan()
		q.Steps = []rpplan.Step{{Name: "build"}}
		q.Deploy.Inputs = []rpplan.Layer{rpplan.NewStepLayer("build", rpplan.Filter{Include: []string{"."}})}
		if err := applyPatch(q, bad); err == nil {
			t.Errorf("patch %+v accepted", bad)
		}
	}
	noBuild := rpplan.NewBuildPlan()
	noBuild.Deploy.Inputs = []rpplan.Layer{{Image: "caddy"}}
	if err := applyPatch(noBuild, presets.PlanPatch{DeployOnly: []string{"dist"}}); err == nil {
		t.Fatal("restricting a deploy without build layers must fail")
	}
}

func TestParseNextSummary(t *testing.T) {
	line := `#14 launchroom-next-summary {"v":1,"adapterVersion":"0.1.0","nextVersion":"16.4.2","deploymentId":"dpl_01J9Z8X7W6V5T4S3R2Q1P0N9N5",` +
		`"serverEntry":".next/standalone/server.js","middleware":true,"middlewareRuntime":"nodejs","imageOptimization":true,"sharp":true,` +
		`"cacheHandler":false,"routes":{"appPages":2,"appRoutes":1,"pages":0,"pagesApi":0,"staticFiles":11,"prerendered":1,"isr":1,"edge":0},` +
		`"isrRoutes":["/isr","not a path","/blog/a b"]}`
	var got []string
	c := &nextCapture{sink: sinkFunc(func(_ string, _ state.LogStream, text string) { got = append(got, text) })}
	c.Line("build", state.StreamStdout, "#14 unrelated")
	c.Line("build", state.StreamStdout, line)
	info := c.result()
	if info == nil || len(got) != 2 {
		t.Fatalf("summary not captured (or line not forwarded): %v", got)
	}
	if info.AdapterVersion != "0.1.0" || info.NextVersion != "16.4.2" || info.ServerEntry != ".next/standalone/server.js" ||
		!info.Middleware || info.MiddlewareRuntime != "nodejs" || info.Routes.AppPages != 2 || info.Routes.StaticFiles != 11 ||
		!slices.Equal(info.ISRRoutes, []state.HTTPPath{"/isr"}) || info.DeploymentID != "dpl_01J9Z8X7W6V5T4S3R2Q1P0N9N5" {
		t.Fatalf("info = %+v", info)
	}
	for _, bad := range []string{
		`{"v":2,"adapterVersion":"0.1.0","nextVersion":"16.4.2"}`,
		`{"v":1,"adapterVersion":"latest","nextVersion":"16.4.2"}`,
		`{"v":1,"adapterVersion":"0.1.0","nextVersion":"16.4.2","routes":{"appPages":-1}}`,
		`not json`,
	} {
		if parseNextSummary(bad) != nil {
			t.Errorf("accepted %s", bad)
		}
	}
	if info := parseNextSummary(`{"v":1,"adapterVersion":"0.1.0","nextVersion":"16.4.2","serverEntry":"../x","middlewareRuntime":"bad runtime!"}`); info == nil ||
		info.ServerEntry != "" || info.MiddlewareRuntime != "" {
		t.Fatalf("invalid optional fields must be dropped: %+v", info)
	}
	stderr := &nextCapture{sink: sinkFunc(func(string, state.LogStream, string) {})}
	stderr.Line("build", state.StreamStderr, line)
	if stderr.result() != nil {
		t.Fatal("only stdout carries the summary")
	}
}

type sinkFunc func(step string, stream state.LogStream, text string)

func (f sinkFunc) Line(step string, stream state.LogStream, text string) { f(step, stream, text) }

type lineSink struct{ lines []string }

func (s *lineSink) Line(step string, _ state.LogStream, text string) {
	s.lines = append(s.lines, "["+step+"] "+text)
}

func TestBuildkitUnavailable(t *testing.T) {
	sink := &lineSink{}
	nr := &buildkitd.NotReadyError{CrashLoop: true, Restarts: 6, ExitCode: 1, State: "restarting", LogTail: []string{"a", "rootlesskit: permission denied"}, UsernsRestricted: true}
	e := buildkitUnavailable(fmt.Errorf("wrapped: %w", nr), sink)
	f := e.Failure()
	if f.Class != state.FailureInternal || f.Step != "setup" || !f.Retryable {
		t.Fatalf("failure %+v", f)
	}
	if len(f.Hints) != 2 || f.Hints[0].Code != buildkitd.HintUserns || f.Hints[1].Code != buildkitd.HintCrashLoop {
		t.Fatalf("hints %+v", f.Hints)
	}
	for _, h := range f.Hints {
		if err := h.Code.Validate(); err != nil {
			t.Fatal(err)
		}
	}
	if strings.Join(sink.lines, "\n") != "[setup] BuildKit's last log lines:\n[setup]   a\n[setup]   rootlesskit: permission denied" {
		t.Fatalf("log lines %q", sink.lines)
	}
	if e := buildkitUnavailable(fmt.Errorf("x: %w", context.DeadlineExceeded), sink); e.Class != state.FailureTimeout {
		t.Fatalf("deadline class %s", e.Class)
	}
	if e := buildkitUnavailable(buildkitd.ErrNotRunning, nil); e.Class != state.FailureInternal || len(e.Hints) != 0 {
		t.Fatalf("not running %+v", e)
	}
}
