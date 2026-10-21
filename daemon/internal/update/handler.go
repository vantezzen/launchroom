package update

import (
	"context"
	"errors"
	"fmt"
	"io/fs"
	"log/slog"
	"maps"
	"net/http"
	"os"
	"path/filepath"
	"slices"
	"strings"
	"time"

	"github.com/moby/moby/client"

	"github.com/vantezzen/launchroom/daemon/internal/state"
	"github.com/vantezzen/launchroom/daemon/internal/tasks"
)

// Default timeouts (overridable for tests).
const (
	defaultDaemonInstallTimeout = 10 * time.Minute
	defaultCPInstallTimeout     = 40 * time.Minute
	defaultDaemonHealthTimeout  = 3 * time.Minute
	defaultCPHealthTimeout      = 10 * time.Minute
)

// HandlerOptions configures the self_update task handler.
type HandlerOptions struct {
	// DataDir is launchroomd's data directory as it sees it (/data).
	DataDir string
	// Version is the running launchroomd version.
	Version string
	Docker  *client.Client
	HTTP    *http.Client
	Log     *slog.Logger
	// Self locates launchroomd's container (default: Discover by PID).
	Self func(ctx context.Context) (Self, error)
	// SupervisorEntrypoint runs RunSupervisor in the supervisor container
	// (default: launchroomd update-supervisor). The plan path is appended.
	SupervisorEntrypoint []string
	// SupervisorImage overrides the image the supervisor runs (default:
	// the image of launchroomd's own container).
	SupervisorImage string
	// Timeouts (0 = defaults).
	InstallTimeout time.Duration
	HealthTimeout  time.Duration
	// Verifier options (TUF cache under DataDir by default).
	Verifier VerifierOptions
}

// NewHandler returns the self_update handler (docs/03 §7, docs/11 §4).
func NewHandler(o HandlerOptions) tasks.Handler {
	if o.Log == nil {
		o.Log = slog.New(slog.DiscardHandler)
	}
	if o.Self == nil {
		o.Self = func(ctx context.Context) (Self, error) { return Discover(ctx, o.Docker, os.Getpid(), o.DataDir) }
	}
	if len(o.SupervisorEntrypoint) == 0 {
		o.SupervisorEntrypoint = []string{"/usr/local/bin/launchroomd", SupervisorCommand}
	}
	if o.Verifier.TUFCacheDir == "" {
		o.Verifier.TUFCacheDir = filepath.Join(updateDir(o.DataDir), dirTUF)
	}
	if o.Verifier.Log == nil {
		o.Verifier.Log = o.Log
	}
	h := &handler{o: o}
	return h.handle
}

type handler struct{ o HandlerOptions }

func (h *handler) handle(ctx context.Context, t state.Task) (tasks.Result, error) {
	spec, ok := t.Spec.(*state.SelfUpdateSpec)
	if !ok {
		return tasks.Result{}, errors.New("self_update: unexpected spec")
	}
	scope, target := spec.EffectiveScope(), strings.TrimPrefix(string(spec.Version), "v")
	result := func(rolledBack bool) tasks.Result {
		return tasks.Result{SelfUpdate: &state.SelfUpdateResult{Scope: scope, FromVersion: state.Semver(h.o.Version), ToVersion: state.Semver(target), RolledBack: rolledBack}}
	}
	log := h.o.Log.With("task", t.ID, "scope", scope, "from", h.o.Version, "to", target)

	// A re-leased attempt of an update that already finished: report it.
	if st, err := readStatus(h.o.DataDir, t.ID); err == nil {
		removePending(h.o.DataDir, t.ID)
		return tasks.Result{SelfUpdate: st.Completion(t.Attempt).SelfUpdate}, st.Err()
	}
	// Still running (launchroomd restarted, or the lease moved): wait for it.
	if p, err := readPending(h.o.DataDir); err == nil && p != nil && p.TaskID == t.ID {
		return h.await(ctx, t, p, log, result)
	}

	if err := CheckNewer(h.o.Version, target); err != nil {
		return result(false), err
	}
	trust, err := LoadTrust(h.o.DataDir)
	if err != nil {
		return result(false), err
	}
	self, err := h.o.Self(ctx)
	if err != nil {
		return result(false), err
	}
	switch {
	case scope == state.UpdateControlPlane && self.Role != RoleControlPlane:
		return result(false), errors.New("a control-plane update can only run on the control-plane server")
	case scope == state.UpdateDaemon && self.Role == RoleControlPlane:
		return result(false), errors.New("launchroomd on the control-plane server is updated together with the control plane (Settings → Updates)")
	}

	tasks.SetProgress(ctx, "Verifying release v"+target)
	v, err := NewVerifier(ctx, trust, h.o.Verifier)
	if err != nil {
		return result(false), err
	}
	f := &Fetcher{Trust: trust, Client: h.o.HTTP, UserAgent: downloadUserAgentP + h.o.Version}
	b, tarball, err := f.Fetch(ctx, v, target)
	if err != nil {
		return result(false), err
	}
	if b.DaemonImage() != spec.Image {
		return result(false), fmt.Errorf("the dashboard asked for image %s, but release v%s pins %s", spec.Image, target, b.DaemonImage())
	}
	log.Info("release verified", "bundle", b.SHA256, "daemonImage", b.DaemonImage())

	dir := taskDir(h.o.DataDir, t.ID)
	_ = os.RemoveAll(dir)
	if err := os.MkdirAll(dir, 0o700); err != nil {
		return result(false), err
	}
	pruneTaskDirs(h.o.DataDir, t.ID)
	if err := b.Extract(filepath.Join(dir, dirBundle)); err != nil {
		return result(false), err
	}
	// The signed tarball stays next to the plan: on a guarded install the
	// supervisor verifies it again before running anything from it (D49).
	if err := os.WriteFile(filepath.Join(dir, fileBundleTar), tarball, 0o600); err != nil {
		return result(false), err
	}
	if err := os.WriteFile(filepath.Join(dir, fileBundleSig), b.Signature, 0o600); err != nil {
		return result(false), err
	}

	// Pull everything before changing anything: a registry problem fails
	// the update cleanly instead of half-way.
	refs := []string{string(b.ComposeImage()), string(b.DaemonImage())}
	if scope == state.UpdateControlPlane {
		for _, k := range slices.Sorted(maps.Keys(b.Images)) {
			refs = append(refs, string(b.Images[k]))
		}
	}
	slices.Sort(refs)
	for _, ref := range slices.Compact(refs) {
		tasks.SetProgress(ctx, "Pulling "+shortRef(ref))
		if err := pull(ctx, h.o.Docker, ref); err != nil {
			return result(false), err
		}
	}

	install, health := h.timeouts(scope)
	rel := func(p string) string { // container path → host path
		r, _ := filepath.Rel(h.o.DataDir, p)
		return filepath.Join(self.DataDir, r)
	}
	plan := Plan{
		TaskID: t.ID, Attempt: t.Attempt, Scope: scope, From: h.o.Version, To: target,
		ProjectDir: self.ProjectDir, Project: self.Project, Service: self.Service, DaemonContainer: self.ContainerID, DataDir: self.DataDir, SocketPath: self.SocketPath,
		BundleDir: rel(filepath.Join(dir, dirBundle)), DaemonImage: b.DaemonImage(), ComposeImage: b.ComposeImage(),
		InstallTimeout: Duration(install), HealthTimeout: Duration(health),
	}
	if err := plan.validate(); err != nil {
		return result(false), err
	}
	if err := writeJSON(filepath.Join(dir, filePlan), plan); err != nil {
		return result(false), err
	}
	pending := &Pending{TaskID: t.ID, Attempt: t.Attempt, Scope: scope, From: h.o.Version, To: target,
		Supervisor: supervisorName(t.ID), CreatedAt: time.Now().UTC()}
	if err := writeJSON(filepath.Join(updateDir(h.o.DataDir), filePending), pending); err != nil {
		return result(false), err
	}

	image := h.o.SupervisorImage
	if image == "" {
		image = self.Image
	}
	sup := helper{
		Name: pending.Supervisor, Image: image, Entrypoint: h.o.SupervisorEntrypoint,
		Cmd: []string{rel(filepath.Join(dir, filePlan))}, TaskID: string(t.ID),
		SameBinds: []string{self.ProjectDir}, SocketPath: self.SocketPath,
	}
	if _, err := sup.start(ctx, h.o.Docker); err != nil {
		removePending(h.o.DataDir, t.ID)
		return result(false), err
	}
	log.Info("update supervisor started; launchroomd will be replaced", "supervisor", sup.Name)
	return h.await(ctx, t, pending, log, result)
}

// await waits for the supervisor. If this process is stopped first (the
// supervisor replaced launchroomd's container), it hands the report over
// to the next launchroomd process (Resume).
func (h *handler) await(ctx context.Context, t state.Task, p *Pending, log *slog.Logger, result func(bool) tasks.Result) (tasks.Result, error) {
	if p.Scope == state.UpdateControlPlane {
		tasks.SetProgress(ctx, "Updating the control plane to v"+p.To+" (the dashboard restarts)")
	} else {
		tasks.SetProgress(ctx, "Installing v"+p.To+" (launchroomd restarts)")
	}
	tick := time.NewTicker(2 * time.Second)
	defer tick.Stop()
	for {
		if st, err := readStatus(h.o.DataDir, t.ID); err == nil {
			removePending(h.o.DataDir, t.ID)
			removeByName(context.WithoutCancel(ctx), h.o.Docker, p.Supervisor)
			return tasks.Result{SelfUpdate: st.Completion(t.Attempt).SelfUpdate}, st.Err()
		} else if !errors.Is(err, fs.ErrNotExist) {
			log.Warn("reading update status", "err", err)
		}
		running, err := containerRunning(ctx, h.o.Docker, p.Supervisor)
		if err == nil && !running {
			// Exited without a verdict (crashed): nothing to wait for.
			if _, serr := readStatus(h.o.DataDir, t.ID); serr != nil {
				st := Status{TaskID: t.ID, Scope: p.Scope, From: p.From, To: p.To, Outcome: OutcomeFailed,
					Error: "the update supervisor exited without a result; check `docker logs " + p.Supervisor + "`", FinishedAt: time.Now().UTC()}
				_ = writeStatus(h.o.DataDir, st)
			}
			continue
		}
		select {
		case <-ctx.Done():
			log.Info("launchroomd is stopping during the update; the next launchroomd reports the outcome")
			return result(false), tasks.ErrHandoff
		case <-tick.C:
		}
	}
}

func (h *handler) timeouts(scope state.UpdateScope) (install, health time.Duration) {
	install, health = defaultDaemonInstallTimeout, defaultDaemonHealthTimeout
	if scope == state.UpdateControlPlane {
		install, health = defaultCPInstallTimeout, defaultCPHealthTimeout
	}
	if h.o.InstallTimeout > 0 {
		install = h.o.InstallTimeout
	}
	if h.o.HealthTimeout > 0 {
		health = h.o.HealthTimeout
	}
	return install, health
}

func supervisorName(id state.TaskID) string {
	return "lr-system-update-" + strings.ToLower(strings.TrimPrefix(string(id), "tsk_"))
}

func shortRef(ref string) string {
	if i := strings.Index(ref, "@sha256:"); i > 0 && len(ref) > i+20 {
		return ref[:i+20]
	}
	return ref
}
