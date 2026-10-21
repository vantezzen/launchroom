package build

import (
	"encoding/json"
	"strings"
	"sync"

	"github.com/vantezzen/launchroom/daemon/internal/build/nextadapter"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// nextCapture forwards build log lines and picks up the one-line summary the
// launchroom Next.js adapter prints after `next build`
// ("#12 launchroom-next-summary {…}"). The line is build output, i.e. app
// controlled: it only ever describes the app's own build and is validated
// like any other summary field.
type nextCapture struct {
	sink LogSink
	mu   sync.Mutex
	info *state.NextBuildInfo
}

func (c *nextCapture) Line(step string, stream state.LogStream, text string) {
	if i := strings.Index(text, nextadapter.SummaryMarker+"{"); i >= 0 && stream == state.StreamStdout {
		if info := parseNextSummary(text[i+len(nextadapter.SummaryMarker):]); info != nil {
			c.mu.Lock()
			c.info = info
			c.mu.Unlock()
		}
	}
	c.sink.Line(step, stream, text)
}

func (c *nextCapture) result() *state.NextBuildInfo {
	c.mu.Lock()
	defer c.mu.Unlock()
	return c.info
}

// nextSummary is the adapter's LaunchroomNextSummary (packages/next-adapter/src/manifest.ts).
type nextSummary struct {
	V                 int     `json:"v"`
	AdapterVersion    string  `json:"adapterVersion"`
	NextVersion       string  `json:"nextVersion"`
	DeploymentID      *string `json:"deploymentId"`
	ServerEntry       *string `json:"serverEntry"`
	Middleware        bool    `json:"middleware"`
	MiddlewareRuntime *string `json:"middlewareRuntime"`
	ImageOptimization bool    `json:"imageOptimization"`
	Sharp             bool    `json:"sharp"`
	CacheHandler      bool    `json:"cacheHandler"`
	Routes            struct {
		AppPages    int `json:"appPages"`
		AppRoutes   int `json:"appRoutes"`
		Pages       int `json:"pages"`
		PagesAPI    int `json:"pagesApi"`
		StaticFiles int `json:"staticFiles"`
		Prerendered int `json:"prerendered"`
		ISR         int `json:"isr"`
		Edge        int `json:"edge"`
	} `json:"routes"`
	ISRRoutes []string `json:"isrRoutes"`
}

// parseNextSummary turns the adapter's summary JSON into a valid
// NextBuildInfo; unusable values are dropped, an unusable summary is nil.
func parseNextSummary(raw string) *state.NextBuildInfo {
	if len(raw) > 16<<10 {
		return nil
	}
	var s nextSummary
	if err := json.Unmarshal([]byte(strings.TrimSpace(raw)), &s); err != nil || s.V != 1 {
		return nil
	}
	r := s.Routes
	info := &state.NextBuildInfo{
		AdapterVersion: state.Semver(s.AdapterVersion), NextVersion: state.Semver(s.NextVersion),
		Middleware: s.Middleware, ImageOptimization: s.ImageOptimization, Sharp: s.Sharp, CacheHandler: s.CacheHandler,
		Routes: state.NextRouteCounts{
			AppPages: r.AppPages, AppRoutes: r.AppRoutes, Pages: r.Pages, PagesAPI: r.PagesAPI,
			StaticFiles: r.StaticFiles, Prerendered: r.Prerendered, ISR: r.ISR, Edge: r.Edge,
		},
	}
	if s.DeploymentID != nil && len(*s.DeploymentID) <= 128 {
		info.DeploymentID = *s.DeploymentID
	}
	if s.ServerEntry != nil && state.RelPath(*s.ServerEntry).Validate() == nil {
		info.ServerEntry = state.RelPath(*s.ServerEntry)
	}
	if s.MiddlewareRuntime != nil {
		info.MiddlewareRuntime = ident(*s.MiddlewareRuntime)
	}
	for _, p := range s.ISRRoutes {
		if hp := state.HTTPPath(p); hp.Validate() == nil && len(info.ISRRoutes) < 32 {
			info.ISRRoutes = append(info.ISRRoutes, hp)
		}
	}
	if info.Validate() != nil {
		return nil
	}
	return info
}
