package traffic

import (
	"fmt"
	"strings"
	"testing"
	"time"

	"github.com/vantezzen/launchroom/daemon/internal/state"
)

func TestNormalizePath(t *testing.T) {
	long := "/" + strings.Repeat("section/", 30)
	for in, want := range map[string]string{
		"/":                              "/",
		"/pricing":                       "/pricing",
		"/pricing?plan=pro&utm_source=x": "/pricing",
		"/docs#install":                  "/docs",
		"/users/123":                     "/users/:id",
		"/users/123/posts/456/":          "/users/:id/posts/:id/",
		"/orders/550e8400-e29b-41d4-a716-446655440000": "/orders/:id",
		"/c/5f2b9c1d8e7a6b5c4d3e2f1a":                  "/c/:id",
		"/r/01J9Z8X7W6V5T4S3R2Q1P0N9AA":                "/r/:id",
		"/s/aB3dE5fG7hJ9kL1mN":                         "/s/:id",
		"/blog/my-first-post-about-2024":               "/blog/my-first-post-about-2024",
		"/blog/2024":                                   "/blog/:id",
		"/img/123.png":                                 "/img/:id.png",
		"/assets/9f86d081884c7d659a2feaa0c55ad015.js":  "/assets/:id.js",
		"/caf%C3%A9":                                   "/caf%C3%A9",
		"/x/" + strings.Repeat("a", 70):                "/x/:id",
		"https://shop.com/cart?id=1":                   "/cart",
		"https://shop.com":                             "/",
		"/a b":                                         "/:id",
	} {
		got, ok := NormalizePath(in)
		if !ok || string(got) != want {
			t.Errorf("NormalizePath(%q) = %q, %v; want %q", in, got, ok, want)
		}
		if ok && got.Validate() != nil {
			t.Errorf("NormalizePath(%q) = %q does not validate: %v", in, got, got.Validate())
		}
	}
	got, ok := NormalizePath(long)
	if !ok || len(got) > state.MaxRequestPathBytes || !strings.HasSuffix(string(got), "/...") || got.Validate() != nil {
		t.Errorf("long path = %q (%d bytes)", got, len(got))
	}
	for _, in := range []string{"*", "", "shop.com/x"} {
		if got, ok := NormalizePath(in); ok {
			t.Errorf("NormalizePath(%q) = %q, want not ok", in, got)
		}
	}
}

func TestClassifyAgentAndReferrer(t *testing.T) {
	for token, want := range map[string]int{"": agentOther, "Mozilla": agentBrowser, "bot": agentBot, "curl": agentBot} {
		if got := classifyAgent(token); got != want {
			t.Errorf("classifyAgent(%q) = %d, want %d", token, got, want)
		}
	}
	for token, want := range map[string]string{"news.ycombinator.com": "news.ycombinator.com", "Google.COM.": "google.com", "": "", "not a host": "", "localhost": ""} {
		got, ok := referrerHost(token)
		if string(got) != want || ok != (want != "") {
			t.Errorf("referrerHost(%q) = %q, %v; want %q", token, got, ok, want)
		}
	}
}

func analyticsLine(host, uri string, status int, referer, agent string) []byte {
	return fmt.Appendf(nil, `{"request":{"host":%q,"method":"GET","uri":%q},"status":%d,"duration":0.01,"size":10,"bytes_read":0,"lr_referer":%q,"lr_agent":%q}`+"\n",
		host, uri, status, referer, agent)
}

func TestAnalyticsWindow(t *testing.T) {
	c, clk, kicks := newCollector(t)
	for range 3 {
		c.Observe(analyticsLine("shop.com", "/products/12?ref=a", 200, "news.ycombinator.com", "Mozilla"))
	}
	c.Observe(analyticsLine("shop.com", "/products/13", 404, "shop.com", "bot"))
	c.Observe(analyticsLine("www.shop.com", "/checkout", 502, "", ""))
	c.Observe(analyticsLine("api.shop.com", "/v1/items", 200, "shop.com", "python"))

	clk.set(time.Date(2026, 10, 19, 12, 9, 30, 0, time.UTC))
	c.Roll()
	if got := c.PendingAnalytics(); got != nil {
		t.Fatalf("window still open, pending = %+v", got)
	}
	clk.set(time.Date(2026, 10, 19, 12, 10, 1, 0, time.UTC))
	c.Roll()
	if kicks.Load() == 0 {
		t.Error("completed window did not kick a report")
	}
	rows := c.PendingAnalytics()
	if len(rows) != 2 {
		t.Fatalf("rows = %+v", rows)
	}
	web, api := rows[1], rows[0]
	if web.Route != routeWeb {
		web, api = api, web
	}
	at := time.Date(2026, 10, 19, 12, 0, 0, 0, time.UTC)
	if !web.At.Equal(at) || web.Requests != 5 {
		t.Fatalf("web = %+v", web)
	}
	if len(web.Paths) != 2 || web.Paths[0] != (state.PathCount{Path: "/products/:id", Requests: 4, Status4xx: 1}) ||
		web.Paths[1] != (state.PathCount{Path: "/checkout", Requests: 1, Status5xx: 1}) {
		t.Errorf("web paths = %+v", web.Paths)
	}
	// shop.com is the route's own host: navigation, not a referrer.
	if len(web.Referrers) != 1 || web.Referrers[0] != (state.ReferrerCount{Host: "news.ycombinator.com", Requests: 3}) {
		t.Errorf("web referrers = %+v", web.Referrers)
	}
	if web.Browsers != 3 || web.Bots != 1 || web.OtherAgents != 1 {
		t.Errorf("web agents = %d/%d/%d", web.Browsers, web.Bots, web.OtherAgents)
	}
	// shop.com refers to the API: another route, so it counts.
	if len(api.Referrers) != 1 || api.Referrers[0].Host != "shop.com" || api.Bots != 1 {
		t.Errorf("api = %+v", api)
	}
	rep := &state.Report{TypeMeta: state.NewTypeMeta(state.KindReport), ServerID: "srv_01J9Z8X7W6V5T4S3R2Q1P0N9MK", ObservedAt: at, Seq: 1, Full: true,
		Metrics: &state.ReportMetrics{Host: []state.HostMetricSample{}, Workloads: []state.WorkloadMetricSample{}, Analytics: rows}}
	if err := rep.Validate(); err != nil {
		t.Fatalf("report refused: %v", err)
	}
	c.AckAnalytics(rows[:1])
	if left := c.PendingAnalytics(); len(left) != 1 || left[0].Route != rows[1].Route {
		t.Errorf("after ack = %+v", left)
	}
}

func TestAnalyticsCardinalityCap(t *testing.T) {
	c, clk, _ := newCollector(t)
	// Distinct paths beyond the per-route cap fall into "other" while the
	// window is open; only the top paths are reported.
	for range 10 {
		c.Observe(analyticsLine("shop.com", "/popular", 500, "", ""))
	}
	for i := range maxPathsPerRoute + 200 {
		c.Observe(analyticsLine("shop.com", fmt.Sprintf("/page-%s", word(i)), 200, "", ""))
	}
	for i := range maxReferrersPerRoute + 5 {
		c.Observe(analyticsLine("shop.com", "/popular", 200, fmt.Sprintf("site-%s.example", word(i)), ""))
	}
	clk.set(time.Date(2026, 10, 19, 12, 10, 0, 0, time.UTC))
	c.Roll()
	rows := c.PendingAnalytics()
	if len(rows) != 1 {
		t.Fatalf("rows = %d", len(rows))
	}
	s := rows[0]
	if len(s.Paths) != state.MaxAnalyticsPaths || s.Paths[0].Path != "/popular" || s.Paths[0].Status5xx != 10 {
		t.Fatalf("paths = %d, first %+v", len(s.Paths), s.Paths[0])
	}
	var sum int64
	for _, p := range s.Paths {
		sum += p.Requests
	}
	if sum+s.OtherPaths.Requests != s.Requests {
		t.Errorf("paths %d + other %d != requests %d", sum, s.OtherPaths.Requests, s.Requests)
	}
	if len(s.Referrers) != state.MaxAnalyticsReferrers || s.OtherReferrers != int64(maxReferrersPerRoute+5-state.MaxAnalyticsReferrers) {
		t.Errorf("referrers = %d, other = %d", len(s.Referrers), s.OtherReferrers)
	}
	rep := &state.Report{TypeMeta: state.NewTypeMeta(state.KindReport), ServerID: "srv_01J9Z8X7W6V5T4S3R2Q1P0N9MK", ObservedAt: s.At, Seq: 1, Full: true,
		Metrics: &state.ReportMetrics{Host: []state.HostMetricSample{}, Workloads: []state.WorkloadMetricSample{}, Analytics: rows}}
	if err := rep.Validate(); err != nil {
		t.Fatalf("report refused: %v", err)
	}
}

func TestAnalyticsGlobalKeyCap(t *testing.T) {
	a := newAnalytics()
	now := time.Date(2026, 10, 19, 12, 0, 0, 0, time.UTC)
	none := func(string) (state.RouteID, bool) { return "", false }
	routes := maxKeys/maxPathsPerRoute + 2
	for r := range routes {
		route := state.RouteID(fmt.Sprintf("rt_01J9Z8X7W6V5T4S3R2Q1P0N%03d", r))
		for i := range maxPathsPerRoute {
			l := &accessLine{Status: 200}
			l.Request.URI = "/p-" + word(i)
			a.observe(now, route, l, none)
		}
	}
	if a.keys != maxKeys {
		t.Errorf("tracked keys = %d, want %d", a.keys, maxKeys)
	}
}

// word returns a distinct lowercase word for i (letters only, so paths are
// never collapsed to :id).
func word(i int) string {
	b := []byte{}
	for {
		b = append(b, byte('a'+i%26))
		i /= 26
		if i == 0 {
			return string(b) + "page"
		}
	}
}
