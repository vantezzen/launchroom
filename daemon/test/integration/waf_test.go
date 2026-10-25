//go:build integration

package integration

import (
	"bytes"
	"context"
	"crypto/tls"
	"encoding/json"
	"flag"
	"fmt"
	"io"
	"net"
	"net/http"
	"slices"
	"strings"
	"sync"
	"sync/atomic"
	"testing"
	"time"

	"github.com/moby/moby/client"

	"github.com/vantezzen/launchroom/daemon/internal/docker"
	"github.com/vantezzen/launchroom/daemon/internal/ingress/caddy"
	"github.com/vantezzen/launchroom/daemon/internal/policy"
	"github.com/vantezzen/launchroom/daemon/internal/state"
	"github.com/vantezzen/launchroom/daemon/internal/waf"
)

// wafPerf runs TestWafPerformance (minutes of load; not part of the suite):
// ARGS='-test.run TestWafPerformance -waf-perf'.
var wafPerf = flag.Bool("waf-perf", false, "run the web firewall performance measurements (docs/22 §6.7)")

const (
	wafLogAddr = "127.0.0.1:2033"
	logHost    = "log.localhost"
	plainHost  = "plain.localhost"
)

// wafEdge is an edge running launchroom's Caddy (with the firewall module)
// and launchroomd's record receiver.
type wafEdge struct {
	*env
	col    *waf.Collector
	offset *atomic.Int64
	image  state.ImageRef
}

func setupWafEdge(t *testing.T) *wafEdge {
	t.Helper()
	api, err := client.New(client.FromEnv)
	if err != nil {
		t.Fatal(err)
	}
	defer func() { _ = api.Close() }()
	insp, err := api.ImageInspect(t.Context(), localCaddyImage)
	if err != nil || len(insp.RepoDigests) == 0 {
		t.Skipf("%s is not loaded into this Docker daemon (run `make caddy-image` before `make test-daemon-integration`): %v", localCaddyImage, err)
	}
	_, digest, _ := strings.Cut(insp.RepoDigests[0], "@")
	guardAllowSystemDigest(t, policy.SystemCaddy, digest)

	offset := &atomic.Int64{}
	col := waf.New(waf.Options{Now: func() time.Time { return time.Now().Add(time.Duration(offset.Load())) }})
	ctx, cancel := context.WithCancel(context.Background())
	t.Cleanup(cancel)
	go col.Run(ctx)
	go func() {
		if err := col.Serve(ctx, wafLogAddr); err != nil {
			t.Errorf("waf receiver: %v", err)
		}
	}()
	e := setupEdge(t, caddy.Options{}, nil, nil, func(mo *caddy.ManagerOptions, pol *policy.Config) {
		pol.SystemImageDigests[policy.SystemCaddy] = append(pol.SystemImageDigests[policy.SystemCaddy], digest)
		mo.WafLogAddress = wafLogAddr
		mo.WafDropped = col.Dropped
	})
	return &wafEdge{env: e, col: col, offset: offset, image: state.ImageRef("docker.io/library/launchroom-dev-caddy@" + digest)}
}

// wafState is the env's NodeState with launchroom's Caddy and three routes
// to the app: testHost (Block), logHost (Log only) and plainHost (off).
func (w *wafEdge) wafState(t *testing.T, version int64, block, logOnly *state.RouteWaf) (*state.NodeState, state.WorkloadID) {
	t.Helper()
	ns, wl := w.nodeState(t, version, true, true)
	ns.System.Caddy.Image = w.image
	up := []state.RouteUpstream{{Workload: wl, Port: 8080}}
	ns.Routes[0].Waf = block
	ns.Routes = append(ns.Routes,
		state.RouteSpec{ID: "rt_01J9Z8X7W6V5T4S3R2Q1P0N9NA", Hosts: []state.Hostname{logHost}, TLS: state.TLSInternal, Upstreams: up, Waf: logOnly},
		state.RouteSpec{ID: "rt_01J9Z8X7W6V5T4S3R2Q1P0N9NB", Hosts: []state.Hostname{plainHost}, TLS: state.TLSInternal, Upstreams: up},
	)
	if err := ns.Validate(); err != nil {
		t.Fatal(err)
	}
	w.col.SetRoutes(ns.Routes)
	return ns, wl
}

func edgeClient(keepAlive bool) *http.Client {
	return &http.Client{Timeout: 15 * time.Second, Transport: &http.Transport{
		Proxy: nil, DisableKeepAlives: !keepAlive, MaxIdleConnsPerHost: 64,
		TLSClientConfig: &tls.Config{InsecureSkipVerify: true, MinVersion: tls.VersionTLS12}, //nolint:gosec // internal CA in tests
		DialContext: func(ctx context.Context, network, _ string) (net.Conn, error) {
			var d net.Dialer
			return d.DialContext(ctx, network, "127.0.0.1:443")
		},
	}}
}

// wafDo sends one request (the host is the URL's) and returns status and body.
func wafDo(t testing.TB, c *http.Client, method, url string, body []byte, hdr map[string]string) (int, string, error) {
	t.Helper()
	return wafDoCtx(t.Context(), t, c, method, url, body, hdr)
}

func wafDoCtx(ctx context.Context, t testing.TB, c *http.Client, method, url string, body []byte, hdr map[string]string) (int, string, error) {
	t.Helper()
	var r io.Reader
	if body != nil {
		r = bytes.NewReader(body)
	}
	req, err := http.NewRequestWithContext(ctx, method, url, r) //nolint:gosec // G704: tests call their own local endpoints
	if err != nil {
		t.Fatal(err)
	}
	for k, v := range hdr {
		req.Header.Set(k, v)
	}
	resp, err := c.Do(req) //nolint:gosec // G704: tests call their own local endpoints
	if err != nil {
		return 0, "", err
	}
	defer func() { _ = resp.Body.Close() }()
	b, _ := io.ReadAll(io.LimitReader(resp.Body, 1<<16))
	return resp.StatusCode, string(b), nil
}

func (w *wafEdge) waitServing(t *testing.T, c *http.Client, hosts ...string) {
	t.Helper()
	for _, h := range hosts {
		deadline := time.Now().Add(2 * time.Minute)
		for {
			if code, _, err := wafDo(t, c, http.MethodGet, "https://"+h+"/", nil, nil); err == nil && code == 200 {
				break
			}
			if time.Now().After(deadline) {
				t.Fatalf("%s never served", h)
			}
			time.Sleep(time.Second)
		}
	}
}

// TestWafBlocksAndReports (docs/22 §6, §14): a route in Block answers an
// SQL injection with 403 and the firewall page with its reference id, a
// route in Log only passes it, and both reach launchroomd's report as
// blocked and detected events with the client's network only.
func TestWafBlocksAndReports(t *testing.T) {
	w := setupWafEdge(t)
	ns, wl := w.wafState(t, 1, &state.RouteWaf{Mode: state.WafModeBlock}, &state.RouteWaf{Mode: state.WafModeLog, Paranoia: 2})
	converge(t, w.env, ns, func() bool {
		_, _, ing := w.rec.Snapshot()
		return status(w.rec, wl).Phase == state.PhaseHealthy && ing != nil && ing.State == state.IngressApplied
	})
	_, _, ing := w.rec.Snapshot()
	if ing.Waf == nil || ing.Waf.State != state.WafStateOn || ing.Waf.Routes != 2 || ing.Waf.Engine != "coraza 3.8.1" || ing.Waf.RuleSet != "OWASP CRS 4.25.0" {
		t.Fatalf("waf status = %+v", ing.Waf)
	}
	if !w.ingress.WafSupport().Available() {
		t.Error("the feature is not announced for launchroom's Caddy")
	}
	c := edgeClient(false)
	w.waitServing(t, c, testHost, logHost, plainHost)

	attack := "/api/products/42?id=1%27%20OR%20%271%27=%271&token=itest-query-secret"
	code, body, err := wafDo(t, c, http.MethodGet, "https://"+testHost+attack, nil, nil)
	if err != nil || code != http.StatusForbidden || !strings.HasPrefix(body, "launchroom: this request was blocked by the web firewall. Reference: ") {
		t.Fatalf("block: %d %q %v", code, body, err)
	}
	ref := strings.TrimSpace(strings.TrimPrefix(body, "launchroom: this request was blocked by the web firewall. Reference: "))
	if code, page, _ := wafDo(t, c, http.MethodGet, "https://"+testHost+attack, nil, map[string]string{"Accept": "text/html"}); code != 403 || !strings.Contains(page, "<h1>This request was blocked by the web firewall</h1>") || !strings.Contains(page, "Reference ") {
		t.Errorf("html page: %d %q", code, page)
	}
	// A spoofed route header doesn't switch the route to Log only.
	if code, _, _ := wafDo(t, c, http.MethodGet, "https://"+testHost+attack, nil, map[string]string{caddy.WafRouteHeader: "rt_01J9Z8X7W6V5T4S3R2Q1P0N9NA"}); code != 403 {
		t.Errorf("spoofed header: %d", code)
	}
	if code, _, err := wafDo(t, c, http.MethodGet, "https://"+logHost+attack, nil, nil); err != nil || code != 200 {
		t.Errorf("log only: %d %v", code, err)
	}
	if code, _, err := wafDo(t, c, http.MethodGet, "https://"+plainHost+attack, nil, nil); err != nil || code != 200 {
		t.Errorf("off: %d %v", code, err)
	}
	if code, _, err := wafDo(t, c, http.MethodGet, "https://"+testHost+"/?q=hello", nil, nil); err != nil || code != 200 {
		t.Errorf("benign request: %d %v", code, err)
	}

	// The report: blocked on the Block route with the page's reference, detected on Log only.
	var samples []state.WafSample
	deadline := time.Now().Add(30 * time.Second)
	for {
		time.Sleep(time.Second)
		w.offset.Add(int64(state.WafWindow + 2*time.Minute))
		w.col.Roll()
		samples = append(samples, w.col.Pending()...)
		w.col.Ack(w.col.Pending())
		var blocked, detected int64
		for _, s := range samples {
			blocked += s.Blocked
			detected += s.Detected
		}
		if blocked >= 3 && detected >= 1 || time.Now().After(deadline) {
			break
		}
	}
	rep := state.Report{TypeMeta: state.NewTypeMeta(state.KindReport), ServerID: w.serverID, ObservedAt: time.Now().UTC(), Seq: 1, Full: true, AppliedVersion: 1,
		Workloads: []state.WorkloadStatus{}, Ingress: ing, Metrics: &state.ReportMetrics{Host: []state.HostMetricSample{}, Workloads: []state.WorkloadMetricSample{}, Waf: samples}}
	if err := rep.Validate(); err != nil {
		t.Fatalf("report invalid: %v", err)
	}
	enc, _ := json.Marshal(rep.Metrics)
	t.Logf("waf samples: %s", enc)
	var gotRef, gotDetected bool
	for _, s := range samples {
		for _, ev := range s.Events {
			if ev.ClientNetwork != "127.0.0.0/24" || ev.Path != "/api/products/:id" || !slices.Contains(ev.Categories, state.WafCategorySQLi) {
				t.Errorf("event %+v", ev)
			}
			if s.Route == ns.Routes[0].ID && ev.Action == state.WafBlocked && string(ev.Reference) == ref {
				gotRef = true
			}
			if s.Route == ns.Routes[1].ID && ev.Action == state.WafDetected {
				gotDetected = true
			}
		}
	}
	if !gotRef || !gotDetected {
		t.Errorf("events: blocked with reference %v, detected %v", gotRef, gotDetected)
	}
	for _, leak := range []string{"itest-query-secret", "OR '1'", "127.0.0.1", "token"} {
		if bytes.Contains(enc, []byte(leak)) {
			t.Errorf("report contains %q", leak)
		}
	}
	// Caddy's own log never carries the request (the firewall module's logger is excluded).
	if logs := caddyLogs(t, w.env); strings.Contains(logs, "itest-query-secret") || strings.Contains(logs, "OR '1'") || strings.Contains(logs, "%27") {
		t.Errorf("caddy log carries request data:\n%s", logs)
	}

	// Off for every route: the firewall leaves the config, the state says off.
	ns2, _ := w.wafState(t, 2, nil, nil)
	converge(t, w.env, ns2, func() bool {
		_, _, ing := w.rec.Snapshot()
		return ing != nil && ing.Waf != nil && ing.Waf.State == state.WafStateOff
	})
	if code, _, err := wafDo(t, c, http.MethodGet, "https://"+testHost+attack, nil, nil); err != nil || code != 200 {
		t.Errorf("after turning it off: %d %v", code, err)
	}
}

func caddyLogs(t *testing.T, e *env) string {
	t.Helper()
	cs, err := e.engine.ListContainers(t.Context(), map[string]string{docker.LabelSystem: policy.SystemCaddy})
	if err != nil || len(cs) == 0 {
		t.Fatalf("caddy container: %v", err)
	}
	var b strings.Builder
	_ = e.engine.StreamLogs(t.Context(), cs[0].ID, docker.LogsOptions{Tail: 2000}, func(l docker.LogLine) error {
		b.WriteString(l.Text)
		b.WriteByte('\n')
		return nil
	})
	return b.String()
}

// TestWafPerformance measures the budgets of docs/22 §6.7 on this machine:
// the same app behind a route with the firewall in Block (paranoia 1) and a
// route without it, through the same Caddy. Run explicitly with -waf-perf.
func TestWafPerformance(t *testing.T) {
	if !*wafPerf {
		t.Skip("run with -waf-perf")
	}
	w := setupWafEdge(t)
	ctx := t.Context()
	// 1. Without any firewall: baseline memory.
	ns, wl := w.wafState(t, 1, nil, nil)
	converge(t, w.env, ns, func() bool {
		_, _, ing := w.rec.Snapshot()
		return status(w.rec, wl).Phase == state.PhaseHealthy && ing != nil && ing.State == state.IngressApplied
	})
	c := edgeClient(true)
	w.waitServing(t, c, testHost, plainHost)
	caddyID := caddyContainer(t, w.env)
	time.Sleep(5 * time.Second)
	memBase := memory(ctx, t, w.env, caddyID)

	// 2. The firewall on testHost: compile time (CPU of Caddy during the reload).
	cpu0 := cpuNs(ctx, t, w.env, caddyID)
	start := time.Now()
	ns, _ = w.wafState(t, 2, &state.RouteWaf{Mode: state.WafModeBlock}, nil)
	converge(t, w.env, ns, func() bool {
		_, _, ing := w.rec.Snapshot()
		return ing != nil && ing.Waf != nil && ing.Waf.State == state.WafStateOn
	})
	firstLoad := time.Since(start)
	firstCPU := time.Duration(cpuNs(ctx, t, w.env, caddyID) - cpu0) //nolint:gosec // a delta of a CPU counter
	w.waitServing(t, c, testHost)
	time.Sleep(5 * time.Second)
	memWaf := memory(ctx, t, w.env, caddyID)

	// 3. Latency, sequential, keep-alive.
	get := func(host string, n int) []time.Duration {
		var out []time.Duration
		for range n {
			s := time.Now()
			code, _, err := wafDo(t, c, http.MethodGet, "https://"+host+"/", nil, nil)
			if err != nil || code != 200 {
				t.Fatalf("%s: %d %v", host, code, err)
			}
			out = append(out, time.Since(s))
		}
		return out
	}
	type item struct {
		Name  string   `json:"name"`
		Tags  []string `json:"tags"`
		Count int      `json:"count"`
		Note  string   `json:"note"`
	}
	var items []item
	var body []byte
	for len(body) < 64<<10-100 {
		items = append(items, item{Name: fmt.Sprintf("widget-%d", len(items)), Tags: []string{"a", "b", "c"}, Count: len(items) * 7, Note: "lorem ipsum dolor sit amet"})
		body, _ = json.Marshal(map[string]any{"items": items})
	}
	t.Logf("POST body: %d bytes", len(body))
	post := func(host string, n int) []time.Duration {
		var out []time.Duration
		for range n {
			s := time.Now()
			code, _, err := wafDo(t, c, http.MethodPost, "https://"+host+"/api", body, map[string]string{"Content-Type": "application/json"})
			if err != nil || code != 200 {
				t.Fatalf("post %s: %d %v", host, code, err)
			}
			out = append(out, time.Since(s))
		}
		return out
	}
	big, _ := json.Marshal(map[string]string{"blob": strings.Repeat("x", 1<<20-20)})
	postBig := func(host string, n int) []time.Duration {
		var out []time.Duration
		for range n {
			st := time.Now()
			code, _, err := wafDo(t, c, http.MethodPost, "https://"+host+"/api", big, map[string]string{"Content-Type": "application/json"})
			if err != nil || code != 200 {
				t.Fatalf("post 1 MiB %s: %d %v", host, code, err)
			}
			out = append(out, time.Since(st))
		}
		return out
	}
	get(testHost, 200) // warm up
	get(plainHost, 200)
	var gPlain, gWaf, pPlain, pWaf []time.Duration
	for range 5 { // interleaved rounds against drift
		gPlain = append(gPlain, get(plainHost, 300)...)
		gWaf = append(gWaf, get(testHost, 300)...)
		pPlain = append(pPlain, post(plainHost, 60)...)
		pWaf = append(pWaf, post(testHost, 60)...)
	}

	bPlain, bWaf := postBig(plainHost, 20), postBig(testHost, 20)

	// 4. Throughput at saturation (small GETs, 32 connections, 15 s each).
	rps := func(host string) float64 {
		var n atomic.Int64
		cctx, cancel := context.WithTimeout(ctx, 15*time.Second)
		defer cancel()
		var wg sync.WaitGroup
		for range 32 {
			wg.Add(1)
			go func() {
				defer wg.Done()
				for cctx.Err() == nil {
					if code, _, err := wafDoCtx(cctx, t, c, http.MethodGet, "https://"+host+"/", nil, nil); err == nil && code == 200 {
						n.Add(1)
					}
				}
			}()
		}
		wg.Wait()
		return float64(n.Load()) / 15
	}
	rpsPlain := rps(plainHost)
	rpsWaf := rps(testHost)

	// 5. A firewall change (an exclusion) under load: CPU of the recompile, failed requests.
	var failed, served atomic.Int64
	lctx, stopLoad := context.WithCancel(ctx)
	var wg sync.WaitGroup
	for _, h := range []string{testHost, plainHost} {
		wg.Add(1)
		go func() {
			defer wg.Done()
			for lctx.Err() == nil {
				if code, _, err := wafDoCtx(lctx, t, c, http.MethodGet, "https://"+h+"/", nil, nil); err != nil || code != 200 {
					if lctx.Err() != nil {
						break // cancelled by the end of the measurement, not a failure
					}
					t.Logf("request during reload: %s %d %v", h, code, err)
					failed.Add(1)
				} else {
					served.Add(1)
				}
				time.Sleep(10 * time.Millisecond)
			}
		}()
	}
	time.Sleep(2 * time.Second)
	cpu1 := cpuNs(ctx, t, w.env, caddyID)
	start = time.Now()
	ns, _ = w.wafState(t, 3, &state.RouteWaf{Mode: state.WafModeBlock, Exclusions: []state.WafExclusion{{Rule: 942100, Path: "/api/search", Target: "ARGS:q"}}}, nil)
	ctxR, cancelR := context.WithTimeout(ctx, 2*time.Minute)
	if err := w.rec.Reconcile(ctxR, ns); err != nil {
		t.Logf("reconcile: %v", err)
	}
	cancelR()
	reload := time.Since(start)
	reloadCPU := time.Duration(cpuNs(ctx, t, w.env, caddyID) - cpu1) //nolint:gosec // a delta of a CPU counter
	time.Sleep(2 * time.Second)
	stopLoad()
	wg.Wait()

	p := func(d []time.Duration, q float64) time.Duration {
		s := slices.Clone(d)
		slices.Sort(s)
		return s[min(len(s)-1, int(float64(len(s))*q))]
	}
	report := map[string]string{
		"get_p50_plain": p(gPlain, 0.5).String(), "get_p50_waf": p(gWaf, 0.5).String(), "get_p50_added": (p(gWaf, 0.5) - p(gPlain, 0.5)).String(),
		"get_p99_plain": p(gPlain, 0.99).String(), "get_p99_waf": p(gWaf, 0.99).String(), "get_p99_added": (p(gWaf, 0.99) - p(gPlain, 0.99)).String(),
		"post64k_p50_plain": p(pPlain, 0.5).String(), "post64k_p50_waf": p(pWaf, 0.5).String(), "post64k_p50_added": (p(pWaf, 0.5) - p(pPlain, 0.5)).String(),
		"post1m_field_p50_plain": p(bPlain, 0.5).String(), "post1m_field_p50_waf": p(bWaf, 0.5).String(), "post1m_field_p50_added": (p(bWaf, 0.5) - p(bPlain, 0.5)).String(),
		"rps_plain": fmt.Sprintf("%.0f", rpsPlain), "rps_waf": fmt.Sprintf("%.0f", rpsWaf), "rps_drop": fmt.Sprintf("%.1f%%", 100*(1-rpsWaf/rpsPlain)),
		"mem_base": fmt.Sprintf("%d MiB", memBase>>20), "mem_waf": fmt.Sprintf("%d MiB", memWaf>>20), "mem_added": fmt.Sprintf("%d MiB", (int64(memWaf)-int64(memBase))>>20), //nolint:gosec // memory readings
		"first_load_wall": firstLoad.String(), "first_load_cpu": firstCPU.String(),
		"reload_wall": reload.String(), "reload_cpu": reloadCPU.String(),
		"reload_requests_failed": fmt.Sprint(failed.Load()), "reload_requests_served": fmt.Sprint(served.Load()),
	}
	out, _ := json.MarshalIndent(report, "", "  ")
	t.Logf("waf performance (docs/22 §6.7):\n%s", out)
	if failed.Load() > 0 {
		t.Errorf("%d requests failed during the firewall reload", failed.Load())
	}
}

func caddyContainer(t *testing.T, e *env) string {
	t.Helper()
	cs, err := e.engine.ListContainers(t.Context(), map[string]string{docker.LabelSystem: policy.SystemCaddy})
	if err != nil || len(cs) == 0 {
		t.Fatalf("caddy container: %v", err)
	}
	return cs[0].ID
}

func memory(ctx context.Context, t *testing.T, e *env, id string) uint64 {
	t.Helper()
	st, err := e.engine.ContainerStats(ctx, id)
	if err != nil {
		t.Fatal(err)
	}
	return st.MemUsedBytes
}

func cpuNs(ctx context.Context, t *testing.T, e *env, id string) uint64 {
	t.Helper()
	st, err := e.engine.ContainerStats(ctx, id)
	if err != nil {
		t.Fatal(err)
	}
	return st.CPUTotalNs
}
