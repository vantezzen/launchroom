package build

import (
	"bytes"
	"context"
	"fmt"
	"maps"
	"regexp"
	"strings"
	"sync"
	"time"

	bkclient "github.com/moby/buildkit/client"
	"github.com/moby/buildkit/session"
	"github.com/moby/buildkit/session/secrets/secretsprovider"
	"github.com/opencontainers/go-digest"
	"github.com/tonistiigi/fsutil"
	"golang.org/x/sync/errgroup"

	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// SBOMGenerator is the BuildKit SBOM scanner image (Syft, stable-1), digest-pinned and
// pulled through the docker.io mirror.
const SBOMGenerator = "docker.io/docker/buildkit-syft-scanner@sha256:ae4f3b554449e7e25548e7d8ccc029d17357348e30c6e3df01b92bc93654d6a9"

// solveRequest is one BuildKit solve.
type solveRequest struct {
	Frontend      string
	FrontendAttrs map[string]string
	Locals        map[string]string // name → directory
	Secrets       map[string][]byte
	Auth          session.Attachable
	// ImageName is the full name to push ("host/apps/x:tag"); empty = no export.
	ImageName string
	CacheRef  string
	StepOf    func(vertexName string) string
	Sink      LogSink
}

type solveResult struct {
	Digest string
	// Tail are the last log lines (for error hints).
	Tail string
	// FailedStep is the step of the vertex that failed, if any.
	FailedStep string
}

func solve(ctx context.Context, c *bkclient.Client, r solveRequest) (*solveResult, error) {
	mounts := map[string]fsutil.FS{}
	for name, dir := range r.Locals {
		fs, err := fsutil.NewFS(dir)
		if err != nil {
			return nil, err
		}
		mounts[name] = fs
	}
	attach := []session.Attachable{secretsprovider.FromMap(r.Secrets)}
	if r.Auth != nil {
		attach = append(attach, r.Auth)
	}
	opt := bkclient.SolveOpt{
		Frontend:      r.Frontend,
		FrontendAttrs: maps.Clone(r.FrontendAttrs),
		LocalMounts:   mounts,
		Session:       attach,
	}
	if r.ImageName != "" {
		opt.Exports = []bkclient.ExportEntry{{
			Type:  bkclient.ExporterImage,
			Attrs: map[string]string{"name": r.ImageName, "push": "true", "oci-mediatypes": "true"},
		}}
	}
	if r.CacheRef != "" {
		opt.CacheExports = []bkclient.CacheOptionsEntry{{Type: "registry", Attrs: map[string]string{
			"ref": r.CacheRef, "mode": "max", "image-manifest": "true", "oci-mediatypes": "true", "ignore-error": "true",
		}}}
		opt.CacheImports = []bkclient.CacheOptionsEntry{{Type: "registry", Attrs: map[string]string{"ref": r.CacheRef}}}
	}

	statusCh := make(chan *bkclient.SolveStatus)
	lg := newLogMapper(r.StepOf, r.Sink)
	var resp *bkclient.SolveResponse
	eg, egctx := errgroup.WithContext(ctx)
	eg.Go(func() error {
		var err error
		resp, err = c.Solve(egctx, nil, opt, statusCh)
		return err
	})
	eg.Go(func() error {
		for st := range statusCh {
			lg.status(st)
		}
		return nil
	})
	err := eg.Wait()
	out := &solveResult{Tail: lg.tail(), FailedStep: lg.failedStep()}
	if err != nil {
		return out, err
	}
	if resp != nil {
		out.Digest = resp.ExporterResponse["containerimage.digest"]
	}
	return out, nil
}

// LogSink receives build log lines (already grouped by step).
type LogSink interface {
	Line(step string, stream state.LogStream, text string)
}

// logMapper turns BuildKit's SolveStatus stream into step-grouped lines.
type logMapper struct {
	stepOf func(string) string
	sink   LogSink

	mu       sync.Mutex
	vertices map[digest.Digest]*vertexInfo
	n        int
	last     []string
	failed   string
	partial  map[digest.Digest][]byte
}

type vertexInfo struct {
	num     int
	name    string
	step    string
	started bool
	done    bool
}

func newLogMapper(stepOf func(string) string, sink LogSink) *logMapper {
	return &logMapper{stepOf: stepOf, sink: sink, vertices: map[digest.Digest]*vertexInfo{}, partial: map[digest.Digest][]byte{}}
}

const tailLines = 50

func (m *logMapper) emit(step string, stream state.LogStream, text string) {
	m.last = append(m.last, text)
	if len(m.last) > tailLines {
		m.last = m.last[len(m.last)-tailLines:]
	}
	if m.sink != nil {
		m.sink.Line(step, stream, text)
	}
}

func (m *logMapper) status(st *bkclient.SolveStatus) {
	m.mu.Lock()
	defer m.mu.Unlock()
	for _, v := range st.Vertexes {
		vi := m.vertices[v.Digest]
		if vi == nil {
			m.n++
			vi = &vertexInfo{num: m.n, name: v.Name, step: m.stepOf(v.Name)}
			m.vertices[v.Digest] = vi
		}
		if v.Started != nil && !vi.started {
			vi.started = true
			m.emit(vi.step, state.StreamStdout, fmt.Sprintf("#%d %s", vi.num, v.Name))
		}
		if vi.done {
			continue
		}
		switch {
		case v.Error != "":
			vi.done = true
			if m.failed == "" {
				m.failed = vi.step
			}
			m.emit(vi.step, state.StreamStderr, fmt.Sprintf("#%d ERROR: %s", vi.num, v.Error))
		case v.Cached:
			vi.done = true
			m.emit(vi.step, state.StreamStdout, fmt.Sprintf("#%d CACHED", vi.num))
		case v.Completed != nil:
			vi.done = true
			d := time.Duration(0)
			if v.Started != nil {
				d = v.Completed.Sub(*v.Started)
			}
			m.emit(vi.step, state.StreamStdout, fmt.Sprintf("#%d DONE %.1fs", vi.num, d.Seconds()))
		}
	}
	for _, l := range st.Logs {
		vi := m.vertices[l.Vertex]
		step, num := "build", 0
		if vi != nil {
			step, num = vi.step, vi.num
		}
		stream := state.StreamStdout
		if l.Stream == 2 {
			stream = state.StreamStderr
		}
		data := append(m.partial[l.Vertex], l.Data...)
		for {
			i := bytes.IndexByte(data, '\n')
			if i < 0 {
				break
			}
			m.emit(step, stream, fmt.Sprintf("#%d %s", num, strings.TrimRight(string(data[:i]), "\r")))
			data = data[i+1:]
		}
		if len(data) > 8192 {
			m.emit(step, stream, fmt.Sprintf("#%d %s", num, string(data)))
			data = nil
		}
		m.partial[l.Vertex] = data
	}
	for _, w := range st.Warnings {
		step := "build"
		if vi := m.vertices[w.Vertex]; vi != nil {
			step = vi.step
		}
		m.emit(step, state.StreamStderr, "WARNING: "+string(w.Short))
	}
}

func (m *logMapper) tail() string {
	m.mu.Lock()
	defer m.mu.Unlock()
	return strings.Join(m.last, "\n")
}

func (m *logMapper) failedStep() string {
	m.mu.Lock()
	defer m.mu.Unlock()
	return m.failed
}

var reStage = regexp.MustCompile(`^\[([A-Za-z0-9_.-]+)(?: [0-9]+/[0-9]+)?\]`)

// dockerfileStep groups Dockerfile vertices by stage ("[builder 3/5] RUN …").
func dockerfileStep(name string) string {
	if s := exportStep(name); s != "" {
		return s
	}
	if m := reStage.FindStringSubmatch(name); m != nil {
		switch m[1] {
		case "internal", "auth":
			return "setup"
		}
		if strings.Contains(m[1], "/") || m[1] == "" {
			return "build"
		}
		if _, err := fmt.Sscanf(m[1], "%d", new(int)); err == nil {
			return "build"
		}
		return m[1]
	}
	return "build"
}

// exportStep recognises BuildKit's export, push and cache vertices.
func exportStep(name string) string {
	switch {
	case strings.HasPrefix(name, "importing cache"), strings.HasPrefix(name, "exporting cache"):
		return "cache"
	case strings.HasPrefix(name, "exporting"), strings.HasPrefix(name, "pushing"), strings.HasPrefix(name, "naming to"),
		strings.HasPrefix(name, "writing image"), strings.HasPrefix(name, "preparing layers"):
		return "push"
	case strings.HasPrefix(name, "resolve image config"), strings.HasPrefix(name, "[auth]"):
		return "setup"
	}
	return ""
}

// railpackStepper maps Railpack vertices to plan step names.
func railpackStepper(names map[string]string) func(string) string {
	var mu sync.Mutex
	lastStep := "setup"
	return func(name string) string {
		mu.Lock()
		defer mu.Unlock()
		if s := exportStep(name); s != "" {
			return s
		}
		if s, ok := names[name]; ok {
			lastStep = s
			return s
		}
		if strings.HasPrefix(name, "[railpack]") || strings.HasPrefix(name, "load build definition") || strings.HasPrefix(name, "[internal]") {
			return "setup"
		}
		if strings.HasPrefix(name, "mise ") || strings.Contains(name, "mise install") {
			return "packages"
		}
		return lastStep
	}
}
