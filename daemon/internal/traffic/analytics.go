package traffic

import (
	"cmp"
	"slices"
	"time"

	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// Request analytics (docs/15 §5): per route and 10-minute window, the
// normalised paths with their 4xx/5xx counts, referrer hosts and a coarse
// client class. Cardinality is bounded twice: while a window is open, at
// most maxPathsPerRoute / maxReferrersPerRoute distinct keys per route and
// maxKeys across all routes are tracked (later new keys count as "other");
// when it completes, only the top state.MaxAnalyticsPaths paths and
// state.MaxAnalyticsReferrers referrers are reported, the rest summed.

const (
	maxPathsPerRoute     = 1000
	maxReferrersPerRoute = 200
	maxKeys              = 20000
	// MaxAnalyticsRows bounds the undelivered analytics windows (oldest dropped).
	MaxAnalyticsRows = state.MaxAnalyticsSamples
)

type analyticsAcc struct {
	requests       int64
	paths          map[state.RequestPath]*state.PathTotals
	other          state.PathTotals
	referrers      map[state.Hostname]int64
	otherReferrers int64
	agents         [3]int64
}

type analytics struct {
	window  time.Time
	keys    int
	acc     map[state.RouteID]*analyticsAcc
	pending []state.RequestAnalyticsSample
}

func newAnalytics() analytics {
	return analytics{acc: map[state.RouteID]*analyticsAcc{}}
}

// observe folds one request into the open window (caller holds the lock).
func (a *analytics) observe(now time.Time, route state.RouteID, l *accessLine, self func(string) (state.RouteID, bool)) {
	if a.window.IsZero() {
		a.window = now.Truncate(state.AnalyticsWindow)
	}
	r := a.acc[route]
	if r == nil {
		r = &analyticsAcc{paths: map[state.RequestPath]*state.PathTotals{}, referrers: map[state.Hostname]int64{}}
		a.acc[route] = r
	}
	r.requests++
	r.agents[classifyAgent(l.Agent)]++

	t := &r.other
	if p, ok := NormalizePath(l.Request.URI); ok {
		if pt := r.paths[p]; pt != nil {
			t = pt
		} else if len(r.paths) < maxPathsPerRoute && a.keys < maxKeys {
			t = &state.PathTotals{}
			r.paths[p] = t
			a.keys++
		}
	}
	t.Requests++
	switch {
	case l.Status >= 500 && l.Status < 600:
		t.Status5xx++
	case l.Status >= 400 && l.Status < 500:
		t.Status4xx++
	}

	host, ok := referrerHost(l.Referer)
	if !ok {
		return
	}
	if own, ok := self(string(host)); ok && own == route {
		return // navigation inside the site
	}
	switch _, seen := r.referrers[host]; {
	case seen:
		r.referrers[host]++
	case len(r.referrers) < maxReferrersPerRoute && a.keys < maxKeys:
		r.referrers[host] = 1
		a.keys++
	default:
		r.otherReferrers++
	}
}

// roll completes the open window if now is past it; it reports whether a
// window with traffic was completed.
func (a *analytics) roll(now time.Time) bool {
	if a.window.IsZero() || !a.window.Before(now.Truncate(state.AnalyticsWindow)) {
		return false
	}
	ids := make([]state.RouteID, 0, len(a.acc))
	for id := range a.acc {
		ids = append(ids, id)
	}
	slices.Sort(ids)
	for _, id := range ids {
		a.pending = append(a.pending, a.acc[id].sample(id, a.window))
	}
	if over := len(a.pending) - MaxAnalyticsRows; over > 0 {
		a.pending = slices.Delete(a.pending, 0, over)
	}
	a.acc = map[state.RouteID]*analyticsAcc{}
	a.window = time.Time{}
	a.keys = 0
	return len(ids) > 0
}

// sample builds the report row: the top paths and referrers, the rest summed.
func (r *analyticsAcc) sample(route state.RouteID, at time.Time) state.RequestAnalyticsSample {
	s := state.RequestAnalyticsSample{
		Route: route, At: at, Requests: r.requests, OtherPaths: r.other, OtherReferrers: r.otherReferrers,
		Browsers: r.agents[agentBrowser], Bots: r.agents[agentBot], OtherAgents: r.agents[agentOther],
	}
	paths := make([]state.PathCount, 0, len(r.paths))
	for p, t := range r.paths {
		paths = append(paths, state.PathCount{Path: p, Requests: t.Requests, Status4xx: t.Status4xx, Status5xx: t.Status5xx})
	}
	slices.SortFunc(paths, func(x, y state.PathCount) int {
		return cmp.Or(cmp.Compare(y.Requests, x.Requests), cmp.Compare(x.Path, y.Path))
	})
	for i, p := range paths {
		if i < state.MaxAnalyticsPaths {
			s.Paths = append(s.Paths, p)
			continue
		}
		s.OtherPaths.Requests += p.Requests
		s.OtherPaths.Status4xx += p.Status4xx
		s.OtherPaths.Status5xx += p.Status5xx
	}
	refs := make([]state.ReferrerCount, 0, len(r.referrers))
	for h, n := range r.referrers {
		refs = append(refs, state.ReferrerCount{Host: h, Requests: n})
	}
	slices.SortFunc(refs, func(x, y state.ReferrerCount) int {
		return cmp.Or(cmp.Compare(y.Requests, x.Requests), cmp.Compare(x.Host, y.Host))
	})
	for i, ref := range refs {
		if i < state.MaxAnalyticsReferrers {
			s.Referrers = append(s.Referrers, ref)
			continue
		}
		s.OtherReferrers += ref.Requests
	}
	return s
}

// PendingAnalytics returns the completed windows not acknowledged yet (nil when none).
func (c *Collector) PendingAnalytics() []state.RequestAnalyticsSample {
	c.mu.Lock()
	defer c.mu.Unlock()
	if len(c.an.pending) == 0 {
		return nil
	}
	out := make([]state.RequestAnalyticsSample, len(c.an.pending))
	for i, s := range c.an.pending {
		s.Paths = slices.Clone(s.Paths)
		s.Referrers = slices.Clone(s.Referrers)
		out[i] = s
	}
	return out
}

// AckAnalytics drops the windows of a report that was accepted.
func (c *Collector) AckAnalytics(rows []state.RequestAnalyticsSample) {
	if len(rows) == 0 {
		return
	}
	type key struct {
		r  state.RouteID
		at int64
	}
	sent := make(map[key]bool, len(rows))
	for _, s := range rows {
		sent[key{s.Route, s.At.Unix()}] = true
	}
	c.mu.Lock()
	defer c.mu.Unlock()
	c.an.pending = slices.DeleteFunc(c.an.pending, func(s state.RequestAnalyticsSample) bool { return sent[key{s.Route, s.At.Unix()}] })
}
