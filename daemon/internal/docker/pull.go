package docker

import (
	"context"
	"errors"
	"fmt"
	"strings"
	"time"

	"github.com/moby/moby/client"
)

// Pull stages reported through WithPullProgress (docs/04 §11 "Pulls").
const (
	// PullMirroring: the registry mirror is fetching the image from upstream
	// (Zot syncs a platform manifest with all its layers before answering).
	PullMirroring = "mirroring"
	// PullDownloading: Docker is downloading layers from the local registry.
	PullDownloading = "downloading"
	// PullExtracting: every layer is downloaded, Docker is unpacking them.
	PullExtracting = "extracting"
)

// PullProgress is a pull's progress: compressed layer bytes downloaded of the
// total (0 when not known yet).
type PullProgress struct {
	Stage      string
	Downloaded int64
	Total      int64
}

// MirrorWarm asks the launchroom registry for the manifest Docker is about to
// pull (the platform manifest of this host), waiting as long as ctx allows,
// so a pull-through mirror finishes syncing before Docker asks with its own,
// short header timeout. It returns the compressed sizes of the manifest's
// layers by digest ("sha256:…"), or nil without error when ref is not served
// by the launchroom registry.
type MirrorWarm func(ctx context.Context, ref string) (map[string]int64, error)

type pullProgressKey struct{}

// WithPullProgress makes pulls started with ctx report their progress to fn
// (throttled; called from the pulling goroutine).
func WithPullProgress(ctx context.Context, fn func(PullProgress)) context.Context {
	return context.WithValue(ctx, pullProgressKey{}, fn)
}

// ReportPullProgress reports p to the WithPullProgress hook of ctx, if any
// (Client implementations other than Engine, e.g. fakes).
func ReportPullProgress(ctx context.Context, p PullProgress) { pullProgressFrom(ctx)(p) }

func pullProgressFrom(ctx context.Context) func(PullProgress) {
	fn, _ := ctx.Value(pullProgressKey{}).(func(PullProgress))
	if fn == nil {
		return func(PullProgress) {}
	}
	return fn
}

// Pull defaults (EngineOptions).
const (
	DefaultPullTimeout     = 45 * time.Minute
	DefaultPullIdleTimeout = 5 * time.Minute
	// BuiltWarmTimeout bounds the warm-up of an image launchroom built
	// (apps/…): the registry has it already and answers at once, so a
	// warm-up that hangs means this server doesn't reach the registry (its
	// mesh connection to the main server, D68). Mirrored images may take
	// the whole pull timeout while the mirror syncs from upstream.
	BuiltWarmTimeout = 2 * time.Minute
	// pullReportEvery throttles progress callbacks while bytes move.
	pullReportEvery = 2 * time.Second
)

// pullLayer is one layer as Docker's progress stream describes it.
type pullLayer struct {
	size, current int64
	downloaded    bool // download finished (verifying, extracting or complete)
	complete      bool // extracted or already present
}

// pullTracker folds Docker's pull progress messages into one byte count. It
// is pure (tests feed it messages). Layers are keyed by Docker's short id, the
// first 12 hex digits of the layer digest, so sizes from the manifest (warm)
// are known before Docker starts a layer.
type pullTracker struct {
	layers map[string]*pullLayer
}

func newPullTracker(sizes map[string]int64) *pullTracker {
	t := &pullTracker{layers: map[string]*pullLayer{}}
	for d, size := range sizes {
		hex := strings.TrimPrefix(d, "sha256:")
		if len(hex) < 12 || size < 0 {
			continue
		}
		t.layers[hex[:12]] = &pullLayer{size: size}
	}
	return t
}

// observe applies one message; it reports whether the progress changed.
func (t *pullTracker) observe(id, status string, current, total int64) bool {
	if len(id) != 12 {
		return false // "Pulling from …", "Digest: …", "Status: …"
	}
	l := t.layers[id]
	known := l != nil
	if !known {
		l = &pullLayer{}
	}
	before := *l
	switch status {
	case "Pulling fs layer", "Waiting":
	case "Downloading":
		if total > 0 && l.size == 0 {
			l.size = total
		}
		l.current = max(l.current, current)
	case "Verifying Checksum", "Download complete", "Extracting":
		l.downloaded = true
	case "Pull complete", "Already exists":
		l.downloaded, l.complete = true, true
	default:
		return false
	}
	if !known {
		t.layers[id] = l
		return true
	}
	return *l != before
}

func (t *pullTracker) progress() PullProgress {
	p := PullProgress{Stage: PullDownloading}
	downloaded, complete := len(t.layers) > 0, true
	for _, l := range t.layers {
		p.Total += l.size
		if l.downloaded {
			p.Downloaded += l.size
		} else {
			p.Downloaded += min(l.current, l.size)
			downloaded = false
		}
		complete = complete && l.complete
	}
	if downloaded && !complete {
		p.Stage = PullExtracting
	}
	return p
}

// PullImage implements Client. For images in the launchroom registry it first
// warms the mirror (MirrorWarm), then streams Docker's pull: it fails when no
// progress arrives for the idle timeout or the overall timeout passes, and
// reports byte progress through WithPullProgress.
func (e *Engine) PullImage(ctx context.Context, ref string) error {
	if !strings.Contains(ref, "@sha256:") {
		return fmt.Errorf("docker: refusing to pull %q: images must be digest-pinned", ref)
	}
	ctx, cancel := context.WithTimeout(ctx, e.pullTimeout)
	defer cancel()
	report := pullProgressFrom(ctx)

	var sizes map[string]int64
	if e.warm != nil {
		report(PullProgress{Stage: PullMirroring})
		built := strings.Contains(ref, "/apps/")
		wctx, wcancel := ctx, context.CancelFunc(func() {})
		if built {
			wctx, wcancel = context.WithTimeout(ctx, e.builtWarm)
		}
		s, err := e.warm(wctx, ref)
		timedOut := wctx.Err() != nil
		wcancel()
		if ctx.Err() != nil {
			return fmt.Errorf("docker: pull %s: the registry mirror did not finish fetching the image within %s", ref, e.pullTimeout)
		}
		if built && timedOut {
			return fmt.Errorf("docker: pull %s: the launchroom registry didn't answer within %s; check that this server reaches the main server (its private network connection)", ref, e.builtWarm)
		}
		// Otherwise a failed warm-up changes nothing: Docker's pull reports
		// the registry's actual answer.
		if err == nil {
			sizes = s
		}
	}

	auth, err := e.auth(ctx, ref)
	if err != nil {
		return fmt.Errorf("docker: registry credentials for %s: %w", ref, err)
	}
	pctx, pcancel := context.WithCancelCause(ctx)
	defer pcancel(nil)
	errIdle := fmt.Errorf("no download progress for %s", e.pullIdle)
	idle := time.AfterFunc(e.pullIdle, func() { pcancel(errIdle) })
	defer idle.Stop()

	res, err := e.api.ImagePull(pctx, ref, client.ImagePullOptions{RegistryAuth: auth})
	if err != nil {
		return pullErr(pctx, ref, err)
	}
	t := newPullTracker(sizes)
	if len(t.layers) > 0 {
		report(t.progress())
	}
	var last time.Time
	lastStage := ""
	for msg, err := range res.JSONMessages(pctx) {
		if err != nil {
			return pullErr(pctx, ref, err)
		}
		idle.Reset(e.pullIdle)
		if msg.Error != nil {
			return wrap("pull "+ref, msg.Error)
		}
		var cur, total int64
		if msg.Progress != nil {
			cur, total = msg.Progress.Current, msg.Progress.Total
		}
		if !t.observe(msg.ID, msg.Status, cur, total) {
			continue
		}
		p := t.progress()
		if now := time.Now(); p.Stage != lastStage || now.Sub(last) >= pullReportEvery {
			report(p)
			last, lastStage = now, p.Stage
		}
	}
	if pctx.Err() != nil {
		return pullErr(pctx, ref, pctx.Err())
	}
	return nil
}

func pullErr(ctx context.Context, ref string, err error) error {
	if cause := context.Cause(ctx); cause != nil && !errors.Is(cause, context.Canceled) {
		if errors.Is(cause, context.DeadlineExceeded) {
			return fmt.Errorf("docker: pull %s: timed out", ref)
		}
		return fmt.Errorf("docker: pull %s: %w", ref, cause)
	}
	return wrap("pull "+ref, err)
}
