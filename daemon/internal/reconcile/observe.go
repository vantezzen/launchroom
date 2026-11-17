package reconcile

import (
	"context"
	"errors"
	"fmt"
	"strings"
	"time"

	"github.com/vantezzen/launchroom/daemon/internal/docker"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// How the reconciler is doing (docs/03 §9 host.docker and host.reconcile):
// a pass that can't observe Docker must not make its stale observation look
// fresh, and the dashboard must be able to say "server-2 can't reach Docker
// since 14:02" instead of showing workloads as they were.

// passHealth is the outcome of the reconcile passes (r.mu held to access).
type passHealth struct {
	// errs are the problems of the pass in progress.
	errs []state.ReconcileError
	// status and docker are the last finished pass's (nil before the first).
	status *state.ReconcileStatus
	docker *state.DockerReach
	// lastComplete is when a pass last ended without an error;
	// lastObserved when one last read Docker's state.
	lastComplete, lastObserved time.Time
}

// beginPass starts collecting the problems of a pass.
func (r *Reconciler) beginPass() {
	r.mu.Lock()
	defer r.mu.Unlock()
	r.passHealth.errs = nil
}

// PassError is a reconcile pass's error with its typed problems
// (hostinfo.ReconcileErrorer: Report.host.reconcile takes them as they are).
type PassError struct {
	err  error
	errs []state.ReconcileError
}

func (e *PassError) Error() string { return e.err.Error() }

func (e *PassError) Unwrap() error { return e.err }

// ReconcileErrors returns the pass's typed problems.
func (e *PassError) ReconcileErrors() []state.ReconcileError {
	return append([]state.ReconcileError(nil), e.errs...)
}

// endPass records how a pass ended and returns its error typed (nil: none).
// observeFailed: it couldn't read Docker's state at all, so nothing in the
// snapshot is newer.
func (r *Reconciler) endPass(err error, observeFailed bool) error {
	r.mu.Lock()
	defer r.mu.Unlock()
	now := r.o.Now().UTC()
	h := &r.passHealth
	reach := &state.DockerReach{Reachable: true, CheckedAt: now}
	if observeFailed && err != nil {
		code := reconcileCode(err)
		if code == state.ReconcileDockerUnreachable {
			reach.Reachable = false
			reach.Error = state.Clip(err.Error(), 512)
		}
		h.errs = append(h.errs, state.ReconcileError{Code: code, Message: state.Clip(err.Error(), 512)})
	} else if err != nil && len(h.errs) == 0 {
		h.errs = append(h.errs, state.ReconcileError{Code: reconcileCode(err), Message: state.Clip(err.Error(), 512)})
	}
	h.docker = reach
	st := &state.ReconcileStatus{LastPassAt: now}
	if len(h.errs) == 0 {
		h.lastComplete = now
	} else {
		st.Errors = h.errs[:min(len(h.errs), state.MaxReconcileErrors)]
	}
	if !h.lastComplete.IsZero() {
		t := h.lastComplete
		st.LastCompleteAt = &t
	}
	h.status = st
	h.errs = nil
	if err == nil {
		return nil
	}
	return &PassError{err: err, errs: append([]state.ReconcileError(nil), st.Errors...)}
}

// passError records a failed action of the pass in progress.
func (r *Reconciler) passError(a Action, err error) {
	r.passErrorCode(actionCode(a, err), a, err)
}

func (r *Reconciler) passErrorCode(code state.ReconcileErrorCode, a Action, err error) {
	r.mu.Lock()
	defer r.mu.Unlock()
	if len(r.passHealth.errs) >= state.MaxReconcileErrors {
		return
	}
	re := state.ReconcileError{Code: code, Message: state.Clip(err.Error(), 512)}
	switch {
	case a.Workload != "":
		re.Target = state.PublicID(a.Workload)
	case a.Volume.ID != "":
		re.Target = state.PublicID(a.Volume.ID)
	case a.Network.ID != "":
		re.Target = state.PublicID(a.Network.ID)
	}
	if re.Target != "" && re.Target.Validate() != nil {
		re.Target = ""
	}
	r.passHealth.errs = append(r.passHealth.errs, re)
}

// actionCode classifies a failed action.
func actionCode(a Action, err error) state.ReconcileErrorCode {
	if code := reconcileCode(err); code != state.ReconcileOther {
		return code
	}
	switch a.Kind {
	case CreateNetwork, RemoveNetwork:
		return state.ReconcileNetwork
	case CreateVolume:
		return state.ReconcileVolume
	}
	return state.ReconcileOther
}

// reconcileCode classifies an error by what it says about the host.
func reconcileCode(err error) state.ReconcileErrorCode {
	switch {
	case err == nil:
		return state.ReconcileOther
	case errors.Is(err, docker.ErrNotIsolated):
		return state.ReconcileNotIsolated
	case errors.Is(err, docker.ErrGuardRefused):
		return state.ReconcileGuardRefused
	case docker.IsUnreachable(err), errors.Is(err, context.DeadlineExceeded):
		return state.ReconcileDockerUnreachable
	case strings.Contains(strings.ToLower(err.Error()), "no space left on device"):
		return state.ReconcileDiskFull
	}
	return state.ReconcileOther
}

// DockerReach is whether the last pass reached Docker (nil before the
// first pass; host.docker, which launchroomd's own /_ping refines).
func (r *Reconciler) DockerReach() *state.DockerReach {
	r.mu.Lock()
	defer r.mu.Unlock()
	if r.passHealth.docker == nil {
		return nil
	}
	d := *r.passHealth.docker
	return &d
}

// ReconcileStatus is how the last pass went (nil before the first pass;
// host.reconcile).
func (r *Reconciler) ReconcileStatus() *state.ReconcileStatus {
	r.mu.Lock()
	defer r.mu.Unlock()
	st := r.passHealth.status
	if st == nil {
		return nil
	}
	out := *st
	out.Errors = append([]state.ReconcileError(nil), st.Errors...)
	return &out
}

// ---- Images: facts and platform ----

// hostPlatform is this server's architecture, as Docker reports it.
type hostPlatform struct {
	arch string
	// emulates: Docker Desktop, OrbStack and Colima run other
	// architectures through emulation, so a foreign image is not refused.
	emulates bool
}

// platform returns the host platform (cached once known; nil: unknown).
func (r *Reconciler) platform(ctx context.Context) *hostPlatform {
	r.mu.Lock()
	h := r.host
	r.mu.Unlock()
	if h != nil {
		return h
	}
	info, err := r.o.Docker.Info(ctx)
	if err != nil || info.Architecture == "" {
		return nil
	}
	h = &hostPlatform{arch: docker.NormalizeArch(info.Architecture), emulates: docker.EngineKindOf(info.OperatingSystem, info.Name) != state.EngineDocker}
	r.mu.Lock()
	r.host = h
	r.mu.Unlock()
	return h
}

// imageConfig returns an image's config (cached; ok=false: unknown).
func (r *Reconciler) imageConfig(ctx context.Context, image state.ImageRef) (docker.ImageConfig, bool) {
	r.mu.Lock()
	cfg, ok := r.images[image]
	r.mu.Unlock()
	if ok {
		return cfg, true
	}
	ops, isOps := r.o.Docker.(docker.ImageOps)
	if !isOps {
		return docker.ImageConfig{}, false
	}
	cfg, err := ops.ImageConfig(ctx, string(image))
	if err != nil {
		return docker.ImageConfig{}, false
	}
	r.mu.Lock()
	r.images[image] = cfg
	r.mu.Unlock()
	return cfg, true
}

// wrongPlatform says why an image can't run on this server ("" = it can,
// or it isn't known): checked after the pull and before the create, so an
// amd64-only image on an arm64 server fails with a clear reason instead of
// crash-looping with "exec format error".
func (r *Reconciler) wrongPlatform(ctx context.Context, image state.ImageRef) string {
	cfg, ok := r.imageConfig(ctx, image)
	if !ok || cfg.Architecture == "" {
		return ""
	}
	h := r.platform(ctx)
	if h == nil || h.emulates {
		return ""
	}
	img := docker.NormalizeArch(cfg.Architecture)
	if compatibleArch(h.arch, img) {
		return ""
	}
	osName := cfg.OS
	if osName == "" {
		osName = "linux"
	}
	return fmt.Sprintf("the image is built for %s/%s; this server runs %s/%s", osName, img, osName, h.arch)
}

// compatibleArch reports whether a host runs images of arch natively
// (64-bit hosts usually run their 32-bit variant).
func compatibleArch(host, img string) bool {
	switch {
	case host == img:
		return true
	case host == "amd64" && img == "386", host == "arm64" && img == "arm":
		return true
	}
	return false
}

// learnImages reads the configs of the images the desired workloads run
// (once per image: references are digest-pinned) and forgets the others.
func (r *Reconciler) learnImages(ctx context.Context, ns *state.NodeState) {
	used := map[state.ImageRef]bool{}
	for i := range ns.Workloads {
		used[ns.Workloads[i].Image] = true
	}
	r.mu.Lock()
	var missing []state.ImageRef
	for img := range r.images {
		if !used[img] {
			delete(r.images, img)
		}
	}
	for img := range used {
		if _, ok := r.images[img]; !ok {
			missing = append(missing, img)
		}
	}
	r.mu.Unlock()
	for _, img := range missing {
		if present, err := r.o.Docker.ImagePresent(ctx, string(img)); err == nil && present {
			r.imageConfig(ctx, img)
		}
	}
}

// imageFactsLocked is WorkloadStatus.image of an image (r.mu held; nil: unknown).
func (r *Reconciler) imageFactsLocked(image state.ImageRef) *state.ImageFacts {
	cfg, ok := r.images[image]
	if !ok {
		return nil
	}
	f := &state.ImageFacts{}
	if u := state.Clip(cfg.User, 64); u != "" && !strings.ContainsFunc(u, func(c rune) bool { return c < 0x20 || c == 0x7f }) {
		f.User = u
	}
	for _, p := range cfg.ExposedPorts {
		if len(f.ExposedPorts) == 32 {
			break
		}
		f.ExposedPorts = append(f.ExposedPorts, state.Port(p)) //nolint:gosec // 1-65535 (docker.TCPPorts)
	}
	return f
}
