package state

import (
	"regexp"
	"time"
)

// Request analytics (docs/15 §5): which paths of a route were requested,
// from which referrer hosts and by which coarse client class, aggregated by
// the edge per route and 10-minute window. Paths are normalised before they
// are counted (no query string, ids collapsed to ":id", bounded length), so
// no full URL, client address or user agent leaves the edge.

// Bounds of Report.metrics.analytics.
const (
	// AnalyticsWindow is the aggregation window of one sample.
	AnalyticsWindow = 10 * time.Minute
	// MaxAnalyticsSamples bounds the samples of one report.
	MaxAnalyticsSamples = 1024
	// MaxAnalyticsPaths and MaxAnalyticsReferrers bound the top lists of one
	// sample; everything else is summed into the "other" counts.
	MaxAnalyticsPaths     = 50
	MaxAnalyticsReferrers = 20
	// MaxRequestPathBytes bounds a normalised path.
	MaxRequestPathBytes = 128
)

// RequestAnalyticsSample is one 10-minute window of one route at this edge.
// Paths plus OtherPaths add up to Requests; Browsers, Bots and OtherAgents
// too. Referrers count only requests whose referrer is another host.
type RequestAnalyticsSample struct {
	// Route is the NodeState route the request's host belongs to.
	Route RouteID `json:"route"`
	// At is the start of the window (UTC, a multiple of 10 minutes).
	At time.Time `json:"at"`
	// Requests is the number of requests in the window.
	Requests int64 `json:"requests" jsonschema:"minimum=1"`
	// Paths are the most requested normalised paths, most requested first.
	Paths []PathCount `json:"paths,omitempty" jsonschema:"maxItems=50"`
	// OtherPaths sums every path outside Paths.
	OtherPaths PathTotals `json:"otherPaths,omitzero"`
	// Referrers are the most frequent referrer hosts (other than the route's own hosts).
	Referrers []ReferrerCount `json:"referrers,omitempty" jsonschema:"maxItems=20"`
	// OtherReferrers sums the referrer hosts outside Referrers.
	OtherReferrers int64 `json:"otherReferrers,omitzero" jsonschema:"minimum=0"`
	// Browsers, Bots and OtherAgents classify the user agent coarsely
	// (Caddy reduces it to one token before the line leaves it).
	Browsers    int64 `json:"browsers,omitzero" jsonschema:"minimum=0"`
	Bots        int64 `json:"bots,omitzero" jsonschema:"minimum=0"`
	OtherAgents int64 `json:"otherAgents,omitzero" jsonschema:"minimum=0"`
}

// PathCount is one normalised path's requests in a window.
type PathCount struct {
	Path      RequestPath `json:"path"`
	Requests  int64       `json:"requests" jsonschema:"minimum=1"`
	Status4xx int64       `json:"status4xx,omitzero" jsonschema:"minimum=0"`
	Status5xx int64       `json:"status5xx,omitzero" jsonschema:"minimum=0"`
}

// Totals returns the counts without the path.
func (c PathCount) Totals() PathTotals {
	return PathTotals{Requests: c.Requests, Status4xx: c.Status4xx, Status5xx: c.Status5xx}
}

// PathTotals are request counts of one path (or of the "other" bucket).
type PathTotals struct {
	Requests  int64 `json:"requests" jsonschema:"minimum=0"`
	Status4xx int64 `json:"status4xx,omitzero" jsonschema:"minimum=0"`
	Status5xx int64 `json:"status5xx,omitzero" jsonschema:"minimum=0"`
}

// ReferrerCount is one referrer host's requests in a window.
type ReferrerCount struct {
	Host     Hostname `json:"host"`
	Requests int64    `json:"requests" jsonschema:"minimum=1"`
}

// RequestPath is a normalised request path: starts with "/", printable
// ASCII without "?" and "#", at most 128 bytes.
type RequestPath string

var reRequestPath = regexp.MustCompile(`^/[!"$->@-~]{0,127}$`)

// ProtocolSchema implements SchemaDescriber.
func (RequestPath) ProtocolSchema() ScalarSchema {
	return strSchema(reRequestPath, MaxRequestPathBytes)
}

// Validate checks the grammar.
func (v RequestPath) Validate() error {
	return checkPattern("request path", string(v), reRequestPath, MaxRequestPathBytes)
}

func (t PathTotals) validate(e *errs, p string) {
	checkNonNegative(e, p, map[string]*int64{"requests": &t.Requests, "status4xx": &t.Status4xx, "status5xx": &t.Status5xx})
	if t.Status4xx+t.Status5xx > t.Requests {
		e.addf(p, "status counts exceed requests")
	}
}

func validateAnalytics(e *errs, p string, samples []RequestAnalyticsSample) {
	checkCount(e, p, len(samples), 0, MaxAnalyticsSamples)
	type key struct {
		r  RouteID
		at int64
	}
	keys := make([]key, 0, len(samples))
	for i, s := range samples {
		q := idx(p, i)
		e.check(q+".route", s.Route)
		switch {
		case s.At.IsZero():
			e.addf(q+".at", "is required")
		case !s.At.Equal(s.At.Truncate(AnalyticsWindow)):
			e.addf(q+".at", "must be a multiple of 10 minutes")
		}
		keys = append(keys, key{s.Route, s.At.Unix()})
		if s.Requests < 1 {
			e.addf(q+".requests", "must be >= 1")
		}
		checkCount(e, q+".paths", len(s.Paths), 0, MaxAnalyticsPaths)
		paths := make([]RequestPath, 0, len(s.Paths))
		sum := s.OtherPaths.Requests
		for j, pc := range s.Paths {
			pq := idx(q+".paths", j)
			e.check(pq+".path", pc.Path)
			pc.Totals().validate(e, pq)
			if pc.Requests < 1 {
				e.addf(pq+".requests", "must be >= 1")
			}
			paths = append(paths, pc.Path)
			sum += pc.Requests
		}
		unique(e, q+".paths[].path", paths)
		s.OtherPaths.validate(e, q+".otherPaths")
		if sum != s.Requests {
			e.addf(q+".paths", "path counts must add up to requests")
		}
		checkCount(e, q+".referrers", len(s.Referrers), 0, MaxAnalyticsReferrers)
		hosts := make([]Hostname, 0, len(s.Referrers))
		refs := s.OtherReferrers
		for j, r := range s.Referrers {
			rq := idx(q+".referrers", j)
			e.check(rq+".host", r.Host)
			if r.Requests < 1 {
				e.addf(rq+".requests", "must be >= 1")
			}
			hosts = append(hosts, r.Host)
			refs += r.Requests
		}
		unique(e, q+".referrers[].host", hosts)
		checkNonNegative(e, q, map[string]*int64{
			"otherReferrers": &s.OtherReferrers, "browsers": &s.Browsers, "bots": &s.Bots, "otherAgents": &s.OtherAgents,
		})
		if refs > s.Requests {
			e.addf(q+".referrers", "referrer counts exceed requests")
		}
		if s.Browsers+s.Bots+s.OtherAgents > s.Requests {
			e.addf(q, "agent counts exceed requests")
		}
	}
	unique(e, p+"[].(route,at)", keys)
}
