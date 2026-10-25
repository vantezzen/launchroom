// Package inspect runs "inspect_container" tasks (docs/22 §7.2, docs/03
// §13.2): it reads what changed in a workload container's writable layer
// and which processes run in it, through dockerd-guard's read-only routes
// (inspect, changes, archive HEAD, top), and classifies both with fixed
// rules. Nothing is executed in the container and no file content is read.
package inspect

import (
	"context"
	"errors"
	"fmt"
	"io/fs"
	"net/url"
	"path"
	"slices"
	"strings"
	"time"

	"github.com/vantezzen/launchroom/daemon/internal/build"
	"github.com/vantezzen/launchroom/daemon/internal/docker"
	"github.com/vantezzen/launchroom/daemon/internal/secret"
	"github.com/vantezzen/launchroom/daemon/internal/state"
	"github.com/vantezzen/launchroom/daemon/internal/tasks"
)

const (
	maxPath    = 1024
	maxCommand = 512
)

// Docker is what an inspection reads.
type Docker interface {
	ListContainers(ctx context.Context, labels map[string]string) ([]docker.Container, error)
	InspectContainer(ctx context.Context, id string) (docker.Container, error)
	docker.InspectionOps
}

// Options configures the handler.
type Options struct {
	Docker Docker
	// Workload returns the workload's spec in the verified NodeState (nil when absent).
	Workload func(state.WorkloadID) *state.WorkloadSpec
	// Env opens a workload's sealed env (for masking process commands).
	Env func(*state.WorkloadSpec) (map[string]secret.Secret, error)
	Now func() time.Time
}

// ErrNotRunning fails a task whose container isn't running.
var ErrNotRunning = errors.New("the container isn't running")

// Handler returns the handler for "inspect_container" tasks.
func Handler(o Options) tasks.Handler {
	return func(ctx context.Context, t state.Task) (tasks.Result, error) {
		spec, ok := t.Spec.(*state.InspectContainerSpec)
		if !ok {
			return tasks.Result{}, errors.New("inspect_container handler got another spec")
		}
		res, err := Inspect(ctx, o, spec)
		if err != nil {
			return tasks.Result{}, err
		}
		return tasks.Result{ContainerInspection: res}, nil
	}
}

// Inspect reads and classifies the running container of spec.Workload.
func Inspect(ctx context.Context, o Options, spec *state.InspectContainerSpec) (*state.ContainerInspectionResult, error) {
	if o.Now == nil {
		o.Now = time.Now
	}
	wl := o.Workload(spec.Workload)
	if wl == nil {
		return nil, fmt.Errorf("workload %s is not in this server's desired state", spec.Workload)
	}
	c, err := target(ctx, o.Docker, spec.Workload)
	if err != nil {
		return nil, err
	}
	masker := build.NewMasker()
	if len(spec.MaskEnv) > 0 {
		env, err := o.Env(wl)
		if err != nil {
			return nil, fmt.Errorf("workload env: %w", err)
		}
		masker = build.NewMasker(maskValues(env, spec.MaskEnv)...)
	}

	res := &state.ContainerInspectionResult{
		Workload: spec.Workload, ContainerID: c.ID, ImageID: c.ImageID,
		StartedAt: c.StartedAt, RestartCount: max(c.RestartCount, 0),
	}
	if id, err := o.Docker.ImageID(ctx, string(wl.Image)); err == nil {
		res.ImageMatches = id == c.ImageID
	}
	if u := strings.TrimSpace(c.User); u != "" {
		res.User = user(u)
	}
	workDir := ""
	if w := path.Clean(c.WorkingDir); strings.HasPrefix(c.WorkingDir, "/") {
		workDir = w
		res.WorkingDir = state.InspectedPath(Escape(w, maxPath))
	}

	changes, err := o.Docker.ContainerChanges(ctx, c.ID)
	if err != nil {
		return nil, fmt.Errorf("container changes: %w", err)
	}
	res.Changes = classifyChanges(ctx, o.Docker, c.ID, changes, workDir, c.Init)

	top, err := o.Docker.ContainerTop(ctx, c.ID)
	if err != nil {
		if again, ierr := o.Docker.InspectContainer(ctx, c.ID); ierr == nil && !again.Running {
			return nil, ErrNotRunning
		}
		return nil, fmt.Errorf("container processes: %w", err)
	}
	added := map[string]bool{}
	for _, ch := range changes {
		if ch.Kind == docker.ChangeAdded {
			added[path.Clean(ch.Path)] = true
		}
	}
	procs, parentsKnown := parseTop(top)
	res.Processes = classifyProcesses(procs, parentsKnown, added, masker)
	res.InspectedAt = o.Now().UTC()
	return res, nil
}

// target finds the running container of a workload (docs/03 §11.4): managed,
// this server, labelled with the workload, never a system, task or helper
// container.
func target(ctx context.Context, d Docker, wl state.WorkloadID) (docker.Container, error) {
	cs, err := d.ListContainers(ctx, map[string]string{docker.LabelWorkload: string(wl)})
	if err != nil {
		return docker.Container{}, err
	}
	stopped := false
	for _, c := range cs {
		l := c.Labels
		if l[docker.LabelManaged] != "true" || l[docker.LabelWorkload] != string(wl) || l[docker.LabelSystem] != "" ||
			l[docker.LabelTask] != "" || l[docker.LabelHelper] != "" || strings.HasPrefix(c.Name, "lr-system-") {
			continue
		}
		if !c.Running {
			stopped = true
			continue
		}
		full, err := d.InspectContainer(ctx, c.ID)
		if err != nil {
			return docker.Container{}, err
		}
		if !full.Running {
			stopped = true
			continue
		}
		return full, nil
	}
	if stopped {
		return docker.Container{}, ErrNotRunning
	}
	return docker.Container{}, fmt.Errorf("no container of %s on this server", wl)
}

// classifyChanges collapses cache locations, flags and orders the rest and
// stats up to MaxInspectionStats added or modified files. Docker's own init
// binary (dockerInit: HostConfig.Init) and directories listed only because
// something below them changed are left out of the entries; Total still
// counts them.
func classifyChanges(ctx context.Context, d Docker, id string, changes []docker.Change, workDir string, dockerInit bool) state.ContainerChanges {
	out := state.ContainerChanges{Total: len(changes), Entries: []state.ContainerChange{}}
	// Directories show up as changes of their own: a path with changes
	// below it is not worth a stat.
	parents := map[string]bool{}
	for _, ch := range changes {
		for p := path.Dir(ch.Path); p != "/" && p != "."; p = path.Dir(p) {
			if parents[p] {
				break
			}
			parents[p] = true
		}
	}
	collapsed := map[string]int{}
	var order []string
	seen := map[state.InspectedPath]bool{}
	type raw struct {
		path string
		kind docker.ChangeKind
	}
	var listed []raw
	for _, ch := range changes {
		if !strings.HasPrefix(ch.Path, "/") {
			continue
		}
		p := path.Clean(ch.Path)
		if dockerInit && slices.Contains(dockerInitPaths, p) {
			continue
		}
		if ch.Kind == docker.ChangeModified && parents[p] {
			continue
		}
		if pre := collapsePrefix(p); pre != "" {
			if _, ok := collapsed[pre]; ok || len(collapsed) < state.MaxInspectionCollapsed {
				if !ok {
					order = append(order, pre)
				}
				collapsed[pre]++
				continue
			}
		}
		listed = append(listed, raw{p, ch.Kind})
	}
	for _, pre := range order {
		out.Collapsed = append(out.Collapsed, state.CollapsedChanges{Prefix: state.InspectedPath(Escape(pre, maxPath)), Count: collapsed[pre]})
	}

	entries := make([]state.ContainerChange, 0, len(listed))
	for _, r := range listed {
		ep := state.InspectedPath(Escape(r.path, maxPath))
		if seen[ep] || ep.Validate() != nil {
			continue
		}
		seen[ep] = true
		entries = append(entries, state.ContainerChange{Path: ep, Kind: changeKind(r.kind), Flags: pathFlags(r.path, workDir)})
	}
	sortEntries(entries)
	if len(entries) > state.MaxInspectionChanges {
		entries, out.Truncated = entries[:state.MaxInspectionChanges], true
	}

	// Stat the most interesting added or modified paths that aren't directories of other changes.
	raws := map[state.InspectedPath]string{}
	for _, r := range listed {
		raws[state.InspectedPath(Escape(r.path, maxPath))] = r.path
	}
	stats := 0
	for i := range entries {
		e := &entries[i]
		if stats >= state.MaxInspectionStats || ctx.Err() != nil {
			break
		}
		rp := raws[e.Path]
		if e.Kind == state.ChangeDeleted || parents[rp] || rp == "" {
			continue
		}
		st, err := d.StatPath(ctx, id, rp)
		if err != nil {
			continue // a path the guard's grammar refuses, or gone again
		}
		stats++
		e.Type = fileType(st.Mode)
		if e.Type == state.FileRegular || e.Type == state.FileOther {
			e.Size = max(st.Size, 0)
		}
		if !st.Mtime.IsZero() && st.Mtime.Year() > 1970 {
			t := st.Mtime.UTC()
			e.ModifiedAt = &t
		}
		if e.Type == state.FileRegular && st.Mode.Perm()&0o111 != 0 {
			e.Executable = true
			e.Flags = append([]state.ChangeFlag{state.ChangeFlagExecutable}, e.Flags...)
		}
	}
	for i := range entries {
		if len(entries[i].Flags) > 8 {
			entries[i].Flags = entries[i].Flags[:8]
		}
	}
	sortEntries(entries)
	out.Entries = entries
	return out
}

func fileType(m fs.FileMode) state.FileType {
	switch {
	case m.IsRegular():
		return state.FileRegular
	case m.IsDir():
		return state.FileDirectory
	case m&fs.ModeSymlink != 0:
		return state.FileSymlink
	}
	return state.FileOther
}

// classifyProcesses flags, masks and bounds the process list (sorted by
// PID). Without parent PIDs only the first process is the entry.
func classifyProcesses(ps []process, parentsKnown bool, added map[string]bool, m *build.Masker) state.ContainerProcesses {
	out := state.ContainerProcesses{Total: len(ps), Entries: []state.ContainerProcess{}, ParentsUnknown: !parentsKnown && len(ps) > 0}
	pids := map[int]bool{}
	commands := map[int]string{}
	for _, p := range ps {
		pids[p.pid] = true
		commands[p.pid] = p.command
	}
	for i, p := range ps {
		if len(out.Entries) == state.MaxInspectionProcesses {
			out.Truncated = true
			break
		}
		out.Entries = append(out.Entries, state.ContainerProcess{
			PID: p.pid, PPID: p.ppid, User: user(p.user),
			Command: commandText(p.command, m), Flags: processFlags(p, parentsKnown && !pids[p.ppid] || !parentsKnown && i == 0, parentCommand(parentsKnown, p, commands), added),
		})
	}
	return out
}

// parentCommand is the raw command of p's parent in the same container, ""
// when parents are unknown or the parent isn't in the list.
func parentCommand(parentsKnown bool, p process, commands map[int]string) string {
	if !parentsKnown {
		return ""
	}
	return commands[p.ppid]
}

// maskValues are the values to mask for names: each value, plus the
// password of a URL value (as run_once logs, docs/09 R16).
func maskValues(env map[string]secret.Secret, names []state.EnvName) []string {
	var out []string
	for _, n := range names {
		v, ok := env[string(n)]
		if !ok {
			continue
		}
		raw := v.Reveal()
		out = append(out, raw)
		if u, err := url.Parse(raw); err == nil && u.User != nil {
			if pw, set := u.User.Password(); set {
				out = append(out, pw)
			}
		}
	}
	slices.Sort(out)
	return slices.Compact(out)
}
