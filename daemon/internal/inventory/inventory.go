// Package inventory runs "inventory" tasks (docs/22 §3, docs/03 §13.1): it
// lists the packages and runtimes of one image with the SBOM scanner
// already pinned for build attestations, run by BuildKit with the image
// mounted read-only and no network, and sets each component's scope from
// the build's manifests and lockfiles. Only the normalised components leave
// the server; the SPDX document and the exported files are deleted with the
// task's work directory.
package inventory

import (
	"context"
	"crypto/ecdh"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"io/fs"
	"log/slog"
	"os"
	"path/filepath"
	"slices"
	"strings"
	"time"

	"github.com/containerd/platforms"
	bkclient "github.com/moby/buildkit/client"
	"github.com/moby/buildkit/client/llb"
	"github.com/moby/buildkit/session"
	"golang.org/x/sync/errgroup"

	"github.com/vantezzen/launchroom/daemon/internal/build"
	"github.com/vantezzen/launchroom/daemon/internal/sealed"
	"github.com/vantezzen/launchroom/daemon/internal/secret"
	"github.com/vantezzen/launchroom/daemon/internal/state"
	"github.com/vantezzen/launchroom/daemon/internal/tasks"
)

// Limits (docs/22 §3.2).
const (
	maxSPDXBytes       = 64 << 20
	maxCompletionBytes = 12 << 20
	scanDir            = "/run/out/"
	scanSource         = "/run/src/core/sbom"
)

// ErrTooLarge fails the task when the SBOM exceeds its limit.
var ErrTooLarge = errors.New("image is too large to inventory")

// Options configures the handler.
type Options struct {
	ServerID   state.ServerID
	SealingKey *ecdh.PrivateKey
	// BuildKit connects to the local buildkitd (buildkitd.Manager.WaitClient).
	BuildKit func(ctx context.Context, waiting func(line string)) (*bkclient.Client, error)
	// Tokens issues registry pull tokens for the task.
	Tokens build.TokenSource
	// RegistryHost is how buildkitd reaches the launchroom registry;
	// PublicRegistry the prefix of image references ("127.0.0.1:5000").
	RegistryHost, PublicRegistry string
	// WorkDir holds per-task work directories (removed after each task).
	WorkDir          string
	InsecureGitHosts []string
	// Slots are the build slots (shared with builds, D59); nil = none.
	Slots chan struct{}
	// Isolate makes sure the build network's restrictions are in place.
	Isolate func(ctx context.Context) error
	// Forget drops cached registry tokens of a finished task.
	Forget func(state.TaskID)
	Log    *slog.Logger
	Now    func() time.Time
}

// Handler returns the handler for "inventory" tasks.
func Handler(o Options) tasks.Handler {
	return func(ctx context.Context, t state.Task) (tasks.Result, error) {
		spec, ok := t.Spec.(*state.InventorySpec)
		if !ok {
			return tasks.Result{}, errors.New("inventory handler got another spec")
		}
		if o.Forget != nil {
			defer o.Forget(t.ID)
		}
		var token, key secret.Secret
		if src := spec.Source; src != nil {
			var err error
			if src.CloneToken != nil {
				if token, err = sealed.OpenTaskToken(*src.CloneToken, o.SealingKey, o.ServerID, t.ID, state.PurposeClone); err != nil {
					return tasks.Result{}, fmt.Errorf("clone token: %w", err)
				}
			}
			if src.SSHKey != nil {
				if key, err = sealed.OpenTaskToken(*src.SSHKey, o.SealingKey, o.ServerID, t.ID, state.PurposeSSHKey); err != nil {
					return tasks.Result{}, fmt.Errorf("deploy key: %w", err)
				}
			}
		}
		if o.Slots != nil {
			select {
			case o.Slots <- struct{}{}:
				defer func() { <-o.Slots }()
			case <-ctx.Done():
				return tasks.Result{}, ctx.Err()
			}
		}
		res, err := Run(ctx, o, t.ID, spec, token, key)
		if err != nil {
			// Clone credentials never reach an error message.
			return tasks.Result{}, errors.New(build.NewMasker(token.Reveal(), key.Reveal()).Mask(err.Error()))
		}
		return tasks.Result{Inventory: res}, nil
	}
}

// Run inventories one image.
func Run(ctx context.Context, o Options, task state.TaskID, spec *state.InventorySpec, token, sshKey secret.Secret) (*state.InventoryResult, error) {
	if o.Now == nil {
		o.Now = time.Now
	}
	if o.Log == nil {
		o.Log = slog.New(slog.DiscardHandler)
	}
	if o.PublicRegistry == "" {
		o.PublicRegistry = "127.0.0.1:5000"
	}
	start := o.Now()
	if err := os.MkdirAll(o.WorkDir, 0o700); err != nil {
		return nil, err
	}
	work, err := os.MkdirTemp(o.WorkDir, "inventory-"+state.LowerID(task)+"-")
	if err != nil {
		return nil, err
	}
	defer func() { _ = os.RemoveAll(work) }()

	res := &state.InventoryResult{Image: spec.Image, Platform: spec.Platform, Generator: build.SBOMGenerator, Components: []state.InventoryComponent{}}
	var notes []string
	var src *sourceFacts
	if spec.Source != nil {
		src = analyzeSource(ctx, o, spec.Source, filepath.Join(work, "src"), token, sshKey, &notes)
		res.SourceAnalyzed = src != nil
		if src != nil {
			res.Lockfiles = src.lockfiles
			notes = append(notes, src.notes...)
		}
	}

	out := filepath.Join(work, "out")
	if err := scan(ctx, o, task, spec, out); err != nil {
		return nil, err
	}
	root, err := os.OpenRoot(out)
	if err != nil {
		return nil, err
	}
	defer func() { _ = root.Close() }()
	comps, dropped, err := readSBOM(root)
	if err != nil {
		return nil, err
	}
	res.Dropped = dropped
	if img, err := root.OpenRoot("image"); err == nil {
		for _, p := range []string{"etc/os-release", "usr/lib/os-release"} {
			if b, err := readSmall(img, p, 64<<10); err == nil {
				res.Distro = parseOSRelease(b)
				break
			}
		}
		next := probeNext(img)
		res.Components, res.Truncated = normalize(comps, src, next, &notes)
		_ = img.Close()
	} else {
		res.Components, res.Truncated = normalize(comps, src, nextProbe{}, &notes)
	}
	if spec.Source == nil {
		notes = append(notes, "This image wasn't built by launchroom, so language packages have an unknown scope")
	}
	res.Notes = clipNotes(notes)
	res.DurationMs = max(o.Now().Sub(start).Milliseconds(), 0)
	fitCompletion(res)
	return res, nil
}

// analyzeSource clones the commit and reads its manifests; nil when the
// clone failed (the image is still scanned, with unknown scopes).
func analyzeSource(ctx context.Context, o Options, src *state.InventorySource, dir string, token, sshKey secret.Secret, notes *[]string) *sourceFacts {
	app, err := build.Checkout(ctx, dir, src.Repo, src.RootDir, token, sshKey, o.InsecureGitHosts)
	if err != nil {
		o.Log.Warn("inventory: source not analysed", "err", build.NewMasker(token.Reveal(), sshKey.Reveal()).Mask(err.Error()))
		*notes = append(*notes, "The repository couldn't be cloned, so language packages have an unknown scope")
		return nil
	}
	base, err := filepath.EvalSymlinks(dir)
	if err != nil {
		return nil
	}
	rel, err := filepath.Rel(base, app)
	if err != nil {
		return nil
	}
	root, err := os.OpenRoot(base)
	if err != nil {
		return nil
	}
	defer func() { _ = root.Close() }()
	appRel := filepath.ToSlash(rel)
	if appRel == "." {
		appRel = ""
	}
	return analyze(root, appRel)
}

// scan runs one BuildKit solve built from LLB (no Dockerfile, no frontend):
// the SBOM scanner with the image mounted read-only and no network, plus a
// copy of the image's os-release and Next.js output; everything exported
// to out with the local exporter.
func scan(ctx context.Context, o Options, task state.TaskID, spec *state.InventorySpec, out string) error {
	ref, err := buildkitRef(o, spec.Image)
	if err != nil {
		return err
	}
	p, err := platforms.Parse(string(spec.Platform))
	if err != nil {
		return fmt.Errorf("platform %s: %w", spec.Platform, err)
	}
	if o.Isolate != nil {
		if err := o.Isolate(ctx); err != nil {
			return err
		}
	}
	img := llb.Image(ref, llb.Platform(p), llb.WithCustomName("Pulling the image"))
	scanner := llb.Image(build.SBOMGenerator, llb.Platform(platforms.Normalize(platforms.DefaultSpec())), llb.WithCustomName("Pulling the SBOM scanner"))
	run := scanner.Run(
		llb.Args([]string{"/bin/syft-scanner"}),
		llb.AddEnv("PATH", "/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin"),
		llb.AddEnv("LOG_LEVEL", "warn"),
		llb.AddEnv("BUILDKIT_SCAN_DESTINATION", scanDir),
		llb.AddEnv("BUILDKIT_SCAN_SOURCE", scanSource),
		llb.Network(llb.NetModeNone),
		llb.WithCustomName("Reading the packages of the image"),
	)
	run.AddMount("/tmp", llb.Scratch(), llb.Tmpfs())
	run.AddMount(scanSource, img, llb.Readonly)
	sbom := run.AddMount(scanDir, llb.Scratch())
	files := llb.Scratch().
		File(llb.Copy(sbom, "/", "/sbom/", &llb.CopyInfo{CopyDirContentsOnly: true, CreateDestPath: true})).
		File(llb.Copy(img, "/", "/image/", &llb.CopyInfo{
			CopyDirContentsOnly: true, CreateDestPath: true, AllowEmptyWildcard: true,
			IncludePatterns: []string{"etc/os-release", "usr/lib/os-release", "**/.next/server/**/*.js", "**/.next/static/chunks/**/*.js"},
			ExcludePatterns: []string{"proc", "sys", "dev", "**/node_modules"},
		}), llb.WithCustomName("Reading the image's release and Next.js output"))
	def, err := files.Marshal(ctx, llb.Platform(platforms.Normalize(platforms.DefaultSpec())))
	if err != nil {
		return err
	}
	c, err := o.BuildKit(ctx, func(string) {})
	if err != nil {
		return fmt.Errorf("buildkit: %w", err)
	}
	defer func() { _ = c.Close() }()
	allowed := func(repo state.RegistryRepository, act state.RegistryAction) bool {
		if act != state.RegistryPull {
			return false
		}
		return spec.Service != "" && repo == state.AppRepository(spec.Service) || strings.HasPrefix(string(repo), "mirror/")
	}
	opt := bkclient.SolveOpt{
		Exports: []bkclient.ExportEntry{{Type: bkclient.ExporterLocal, OutputDir: out}},
		Session: []session.Attachable{build.RegistryAuth(o.RegistryHost, task, o.Tokens, allowed)},
	}
	status := make(chan *bkclient.SolveStatus)
	eg, egctx := errgroup.WithContext(ctx)
	eg.Go(func() error {
		_, err := c.Solve(egctx, def, opt, status)
		return err
	})
	eg.Go(func() error {
		for st := range status {
			_ = st // drained: the scan ships no logs of its own
		}
		return nil
	})
	if err := eg.Wait(); err != nil {
		return fmt.Errorf("scan: %w", err)
	}
	return nil
}

// buildkitRef is the image reference as buildkitd reaches the registry.
func buildkitRef(o Options, img state.ImageRef) (string, error) {
	rest, ok := strings.CutPrefix(string(img), o.PublicRegistry+"/")
	if !ok || o.RegistryHost == "" {
		return "", fmt.Errorf("image %s is not in this server's registry", img)
	}
	return o.RegistryHost + "/" + rest, nil
}

// readSBOM decodes the scanner's SPDX documents (*.spdx.json).
func readSBOM(root *os.Root) ([]scanned, int, error) {
	entries, err := fs.ReadDir(root.FS(), "sbom")
	if err != nil {
		return nil, 0, fmt.Errorf("the scanner wrote no SBOM: %w", err)
	}
	var comps []scanned
	dropped, found := 0, false
	for _, e := range entries {
		if !e.Type().IsRegular() || !strings.HasSuffix(e.Name(), ".spdx.json") {
			continue
		}
		b, err := readSmall(root, "sbom/"+e.Name(), maxSPDXBytes)
		if err != nil {
			return nil, 0, err
		}
		cs, d, err := parseSPDX(b)
		if err != nil {
			return nil, 0, fmt.Errorf("the scanner's SBOM could not be read: %w", err)
		}
		found = true
		comps = append(comps, cs...)
		dropped += d
	}
	if !found {
		return nil, 0, errors.New("the scanner wrote no SPDX document")
	}
	return comps, dropped, nil
}

func readSmall(root *os.Root, name string, limit int64) ([]byte, error) {
	f, err := root.Open(name)
	if err != nil {
		return nil, err
	}
	defer func() { _ = f.Close() }()
	st, err := f.Stat()
	if err != nil || !st.Mode().IsRegular() {
		return nil, fs.ErrNotExist
	}
	b, err := io.ReadAll(io.LimitReader(f, limit+1))
	if err != nil {
		return nil, err
	}
	if int64(len(b)) > limit {
		if limit == maxSPDXBytes {
			return nil, ErrTooLarge
		}
		return nil, errTooLarge
	}
	return b, nil
}

func clipNotes(notes []string) []string {
	var out []string
	for _, n := range notes {
		if len(out) == state.MaxInventoryNotes {
			break
		}
		if len(n) > 256 {
			n = n[:253] + "..."
		}
		out = append(out, n)
	}
	return out
}

// fitCompletion keeps the encoded completion under 12 MiB (the document
// limit is 16 MiB) by cutting components from the end of the display order.
func fitCompletion(res *state.InventoryResult) {
	for {
		b, err := json.Marshal(state.TaskCompletion{TypeMeta: state.NewTypeMeta(state.KindTaskCompletion), Attempt: 1, Outcome: state.OutcomeSucceeded, Inventory: res})
		if err != nil || len(b) <= maxCompletionBytes || len(res.Components) == 0 {
			return
		}
		keep := len(res.Components) * maxCompletionBytes / len(b) * 9 / 10
		res.Components = res.Components[:keep]
		res.Truncated = false // only valid at exactly the component limit
		if cut := "The list was cut to fit the report"; len(res.Notes) < state.MaxInventoryNotes && !slices.Contains(res.Notes, cut) {
			res.Notes = append(res.Notes, cut)
		}
	}
}
