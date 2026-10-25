//go:build integration

package integration

import (
	"bufio"
	"context"
	"crypto/tls"
	"io"
	"net"
	"net/http"
	"strings"
	"sync"
	"sync/atomic"
	"testing"
	"time"

	"github.com/vantezzen/launchroom/daemon/internal/ingress/caddy"
	"github.com/vantezzen/launchroom/daemon/internal/state"
	"github.com/vantezzen/launchroom/daemon/internal/traffic"
)

// TestRequestAnalytics sends HTTPS requests with query strings, referrers
// and user agents through the real edge Caddy and checks what reaches
// launchroomd (docs/15 §5.1): the URI without its query string, only the
// referrer's host, only one user agent token, never an address or header;
// and that the collector reports normalised paths, referrer hosts and
// client classes per 10-minute window in a valid report.
func TestRequestAnalytics(t *testing.T) {
	const addr = "127.0.0.1:2033"
	var offset atomic.Int64
	col := traffic.New(traffic.Options{Now: func() time.Time { return time.Now().Add(time.Duration(offset.Load())) }})

	// A listener that keeps the raw lines Caddy sends, then feeds the collector.
	var mu sync.Mutex
	var raw []string
	var lc net.ListenConfig
	ln, err := lc.Listen(t.Context(), "tcp", addr)
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { _ = ln.Close() })
	go func() {
		for {
			cn, err := ln.Accept()
			if err != nil {
				return
			}
			go func() {
				defer func() { _ = cn.Close() }()
				br := bufio.NewReaderSize(cn, traffic.MaxLineBytes)
				for {
					line, err := br.ReadBytes('\n')
					if err != nil {
						return
					}
					mu.Lock()
					raw = append(raw, string(line))
					mu.Unlock()
					col.Observe(line)
				}
			}()
		}
	}()

	e := setupWith(t, caddy.Options{AccessLogAddress: addr})
	ns, wl := e.nodeState(t, 1, true, true)
	col.SetRoutes(ns.Routes)
	converge(t, e, ns, func() bool {
		_, _, ing := e.rec.Snapshot()
		return status(e.rec, wl).Phase == state.PhaseHealthy && ing != nil && ing.State == state.IngressApplied
	})

	client := &http.Client{Timeout: 10 * time.Second, Transport: &http.Transport{
		Proxy: nil, DisableKeepAlives: true,
		TLSClientConfig: &tls.Config{ServerName: testHost, InsecureSkipVerify: true, MinVersion: tls.VersionTLS12}, //nolint:gosec // internal CA in tests
		DialContext: func(ctx context.Context, network, _ string) (net.Conn, error) {
			var d net.Dialer
			return d.DialContext(ctx, network, "127.0.0.1:443")
		},
	}}
	send := func(path, referer, agent string) int {
		t.Helper()
		req, err := http.NewRequestWithContext(t.Context(), http.MethodGet, "https://"+testHost+path, nil) //nolint:gosec // G704: tests call their own local endpoints
		if err != nil {
			t.Fatal(err)
		}
		req.Header.Set("User-Agent", agent)
		if referer != "" {
			req.Header.Set("Referer", referer)
		}
		req.Header.Set("Cookie", "session=cookie-secret")
		resp, err := client.Do(req) //nolint:gosec // G704: tests call their own local endpoints
		if err != nil {
			return 0
		}
		_, _ = io.Copy(io.Discard, resp.Body)
		_ = resp.Body.Close()
		return resp.StatusCode
	}

	deadline := time.Now().Add(90 * time.Second)
	for send("/", "", "probe") != 200 {
		if time.Now().After(deadline) {
			t.Fatal("route never served")
		}
		time.Sleep(time.Second)
	}
	// Drain the probe's lines: complete windows until nothing new arrives.
	for quiet := 0; quiet < 2; {
		time.Sleep(500 * time.Millisecond)
		offset.Add(int64(state.AnalyticsWindow))
		col.Roll()
		if p := col.PendingAnalytics(); len(p) > 0 {
			col.AckAnalytics(p)
			quiet = 0
		} else {
			quiet++
		}
	}
	mu.Lock()
	raw = nil
	mu.Unlock()

	browser := "Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36"
	crawler := "Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)"
	send("/users/123?token=query-secret", "https://news.ycombinator.com/item?id=987654", browser)
	send("/users/456", "https://news.ycombinator.com/", browser)
	send("/about", "https://"+testHost+"/users/123", crawler)
	send("/about", "", "curl/8.5.0")

	route := ns.Routes[0].ID
	var rows []state.RequestAnalyticsSample
	deadline = time.Now().Add(30 * time.Second)
	for {
		offset.Add(int64(state.AnalyticsWindow))
		col.Roll()
		rows = col.PendingAnalytics()
		var n int64
		for _, r := range rows {
			n += r.Requests
		}
		if n >= 4 || time.Now().After(deadline) {
			break
		}
		time.Sleep(500 * time.Millisecond)
	}

	mu.Lock()
	lines := strings.Join(raw, "")
	mu.Unlock()
	t.Logf("access lines:\n%s", lines)
	for _, secret := range []string{"query-secret", "token=", "987654", "cookie-secret", "Chrome", "Googlebot", "remote_ip", "client_ip", "headers"} {
		if strings.Contains(lines, secret) {
			t.Errorf("access line carries %q", secret)
		}
	}
	if !strings.Contains(lines, `"uri":"/users/123"`) || !strings.Contains(lines, `"lr_referer":"news.ycombinator.com"`) || !strings.Contains(lines, `"lr_agent":"bot"`) {
		t.Errorf("access lines lack the reduced uri, referrer host or agent token")
	}

	paths := map[state.RequestPath]int64{}
	refs := map[state.Hostname]int64{}
	var browsers, bots, other, total int64
	for _, r := range rows {
		if r.Route != route {
			t.Fatalf("unexpected route %s", r.Route)
		}
		for _, p := range r.Paths {
			paths[p.Path] += p.Requests
		}
		for _, h := range r.Referrers {
			refs[h.Host] += h.Requests
		}
		browsers += r.Browsers
		bots += r.Bots
		other += r.OtherAgents
		total += r.Requests
	}
	t.Logf("analytics: paths %v referrers %v agents %d/%d/%d", paths, refs, browsers, bots, other)
	if total != 4 || paths["/users/:id"] != 2 || paths["/about"] != 2 {
		t.Errorf("paths = %v (total %d)", paths, total)
	}
	// The route's own host is navigation, not a referrer.
	if len(refs) != 1 || refs["news.ycombinator.com"] != 2 {
		t.Errorf("referrers = %v", refs)
	}
	if browsers != 2 || bots != 2 || other != 0 {
		t.Errorf("agents = %d browsers, %d bots, %d other", browsers, bots, other)
	}
	rep := &state.Report{TypeMeta: state.NewTypeMeta(state.KindReport), ServerID: "srv_01J9Z8X7W6V5T4S3R2Q1P0N9MK", ObservedAt: time.Now().UTC(), Seq: 1, Full: true,
		Metrics: &state.ReportMetrics{Host: []state.HostMetricSample{}, Workloads: []state.WorkloadMetricSample{}, Analytics: rows}}
	if err := rep.Validate(); err != nil {
		t.Fatalf("report refused: %v", err)
	}
}
