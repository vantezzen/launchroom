package waf

import (
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"io"
	"net"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"slices"
	"strings"
	"sync"
	"testing"
	"time"

	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// Records in testdata/ were captured from launchroom-caddy (Coraza 3.8.1,
// OWASP CRS 4.25.0) with the directives launchroomd renders (paranoia 1
// Block on block.localhost, Log only paranoia 2 on log.localhost).
const (
	routeBlock = state.RouteID("rt_01J9Z8X7W6V5T4S3R2Q1P0N9N9")
	routeLog   = state.RouteID("rt_01J9Z8X7W6V5T4S3R2Q1P0N9NA")
)

func readRecord(t *testing.T, name string) []byte {
	t.Helper()
	b, err := os.ReadFile(filepath.Join("testdata", name+".json"))
	if err != nil {
		t.Fatal(err)
	}
	return b
}

// withClient replaces the record's client address (the captures came from loopback).
func withClient(t *testing.T, rec []byte, ip string) []byte {
	t.Helper()
	var m map[string]any
	if err := json.Unmarshal(rec, &m); err != nil {
		t.Fatal(err)
	}
	m["transaction"].(map[string]any)["client_ip"] = ip
	out, err := json.Marshal(m)
	if err != nil {
		t.Fatal(err)
	}
	return out
}

// recordTime is the capture time of a record.
func recordTime(t *testing.T, rec []byte) time.Time {
	t.Helper()
	var r record
	if err := json.Unmarshal(rec, &r); err != nil {
		t.Fatal(err)
	}
	return time.Unix(0, r.Transaction.UnixTimestamp).UTC()
}

type clock struct {
	mu sync.Mutex
	t  time.Time
}

func (c *clock) now() time.Time {
	c.mu.Lock()
	defer c.mu.Unlock()
	return c.t
}

func (c *clock) set(t time.Time) {
	c.mu.Lock()
	c.t = t
	c.mu.Unlock()
}

// do sends one request and returns its status.
func do(t *testing.T, method, url string, body io.Reader) int {
	t.Helper()
	req, err := http.NewRequestWithContext(t.Context(), method, url, body)
	if err != nil {
		t.Fatal(err)
	}
	resp, err := http.DefaultClient.Do(req)
	if err != nil {
		t.Fatal(err)
	}
	_, _ = io.Copy(io.Discard, resp.Body)
	_ = resp.Body.Close()
	return resp.StatusCode
}

func newCollector(t *testing.T, at time.Time) (*Collector, *clock) {
	t.Helper()
	clk := &clock{t: at}
	c := New(Options{Now: clk.now})
	c.SetRoutes([]state.RouteSpec{
		{ID: routeBlock, Hosts: []state.Hostname{"block.localhost"}},
		{ID: routeLog, Hosts: []state.Hostname{"log.localhost"}},
	})
	return c, clk
}

func TestDecodeCapturedRecords(t *testing.T) {
	cases := []struct {
		name    string
		action  state.WafAction
		method  state.WafMethod
		path    state.RequestPath
		rules   []state.WafRuleID
		targets []state.WafMatchTarget
		score   int
		network state.ClientNetwork
	}{
		{"blocked-sqli", state.WafBlocked, "GET", "/api/products/:id", []state.WafRuleID{942100}, []state.WafMatchTarget{"ARGS:id"}, 5, "127.0.0.0/24"},
		{"blocked-xss", state.WafBlocked, "GET", "/", []state.WafRuleID{941100, 941110, 941160, 941390}, []state.WafMatchTarget{"ARGS:q"}, 20, "127.0.0.0/24"},
		{"detected-xss", state.WafDetected, "POST", "/search", []state.WafRuleID{941100, 941110, 941160, 941390, 941320, 942131}, []state.WafMatchTarget{"ARGS:q"}, 30, "127.0.0.0/24"},
		{"blocked-json-sqli", state.WafBlocked, "POST", "/api/x", []state.WafRuleID{942100, 942190, 942270, 942360}, []state.WafMatchTarget{"ARGS:json.name"}, 20, "127.0.0.0/24"},
		{"blocked-scanner", state.WafBlocked, "GET", "/wp-login.php", []state.WafRuleID{913100}, []state.WafMatchTarget{"REQUEST_HEADERS:User-Agent"}, 5, "127.0.0.0/24"},
		{"blocked-lfi-ipv6", state.WafBlocked, "GET", "/etc", []state.WafRuleID{930100, 930110, 930120, 932160}, []state.WafMatchTarget{"REQUEST_URI_RAW", "ARGS:f"}, 30, "::/48"},
	}
	for _, tc := range cases {
		rec := readRecord(t, tc.name)
		ev, ok := decode(rec, recordTime(t, rec))
		if !ok {
			t.Fatalf("%s: not counted", tc.name)
		}
		if ev.action != tc.action || ev.method != tc.method || ev.path != tc.path || !ev.pathOK || !slices.Equal(ev.rules, tc.rules) ||
			!slices.Equal(ev.targets, tc.targets) || ev.score != tc.score || ev.network != tc.network || ev.reference == "" {
			t.Errorf("%s: %+v", tc.name, ev)
		}
	}
}

func TestDecodeDropsBelowThreshold(t *testing.T) {
	now := time.Now()
	// An app's own 403 (relevant status, nothing matched).
	if _, ok := decode(readRecord(t, "app-403"), now); ok {
		t.Error("an app's own 403 was counted")
	}
	// A notice-level match without the evaluation rule and no interruption.
	low := []byte(`{"transaction":{"id":"abc","client_ip":"203.0.113.9","server_id":"block.localhost","request":{"method":"GET","uri":"/"},"is_interrupted":false},
		"messages":[{"data":{"id":920300,"msg":"Request Missing an Accept Header","data":""}}]}`)
	if _, ok := decode(low, now); ok {
		t.Error("a below-threshold match was counted")
	}
	for _, bad := range []string{"", "{", "null", `{"transaction":5}`, `[]`} {
		if _, ok := decode([]byte(bad), now); ok {
			t.Errorf("%q counted", bad)
		}
	}
}

func TestClientNetworkCut(t *testing.T) {
	for in, want := range map[string]state.ClientNetwork{
		"203.0.113.77":              "203.0.113.0/24",
		"2001:db8:17:4:5::9":        "2001:db8:17::/48",
		"::ffff:198.51.100.200":     "198.51.100.0/24",
		"fe80::1%eth0":              "fe80::/48",
		"not an address":            "",
		"":                          "",
		"203.0.113.77:443":          "",
		"2001:0db8:0017:0000::0001": "2001:db8:17::/48",
		" 192.0.2.1 ":               "192.0.2.0/24",
		"[2001:db8::1]":             "",
		"0.0.0.0":                   "0.0.0.0/24",
		"ffff:ffff:ffff:ffff::ffff": "ffff:ffff:ffff::/48",
	} {
		if got := clientNetwork(in); got != want {
			t.Errorf("clientNetwork(%q) = %q, want %q", in, got, want)
		}
	}
}

func TestMatchTarget(t *testing.T) {
	for in, want := range map[string]state.WafMatchTarget{
		"Matched Data: s&sos found within ARGS:id: 1' OR '1'='1":               "ARGS:id",
		"Matched Data: Nikto found within REQUEST_HEADERS:User-Agent: Nikto":   "REQUEST_HEADERS:User-Agent",
		"Matched Data: x found within ARGS:a b c: x":                           "ARGS",
		"Matched Data: x found within REQUEST_URI_RAW: /etc":                   "REQUEST_URI_RAW",
		"Matched Data: x found within ARGS:" + strings.Repeat("k", 70) + ": x": "ARGS",
		"Matched Data: x found within args:id: x":                              "",
		"no marker":                            "",
		"Matched Data: x found within ARGS:id": "",
		"Matched Data: x found within ARGS:json.items[0].id: 1": "ARGS:json.items[0].id",
	} {
		got, _ := matchTarget(in)
		if got != want {
			t.Errorf("matchTarget(%q) = %q, want %q", in, got, want)
		}
	}
}

func TestCategoryTable(t *testing.T) {
	for rule, want := range map[state.WafRuleID]state.WafCategory{
		911100: state.WafCategoryMethod, 913100: state.WafCategoryScanner, 920350: state.WafCategoryProtocol,
		921110: state.WafCategoryProtocol, 922110: state.WafCategoryProtocol, 930120: state.WafCategoryLFI,
		931100: state.WafCategoryRFI, 932160: state.WafCategoryRCE, 933100: state.WafCategoryPHP,
		934100: state.WafCategoryGeneric, 941100: state.WafCategoryXSS, 942100: state.WafCategorySQLi,
		943100: state.WafCategorySessionFixation, 944100: state.WafCategoryJava, 955100: state.WafCategoryOther,
	} {
		if got := categories([]state.WafRuleID{rule}); !slices.Equal(got, []state.WafCategory{want}) {
			t.Errorf("%d: %v", rule, got)
		}
	}
	for _, r := range []state.WafRuleID{901100, 949110, 959100, 980170, 10002, 1000000} {
		if detectionRule(r) {
			t.Errorf("%d counted as a detection rule", r)
		}
	}
}

// The aggregation of the captured records has the shape of
// protocol/fixtures/v1/report.waf.json and passes the report's validation;
// no client address, query string, argument or matched value is in it.
func TestAggregateCapturedRecords(t *testing.T) {
	names := []string{"blocked-sqli", "blocked-xss", "detected-xss", "blocked-json-sqli", "blocked-scanner", "blocked-lfi-ipv6", "app-403"}
	first := recordTime(t, readRecord(t, names[0]))
	c, clk := newCollector(t, first)
	clients := []string{"203.0.113.77", "203.0.113.78", "198.51.100.23", "2001:db8:17:4::9", "203.0.113.77", "::ffff:192.0.2.44", "192.0.2.1"}
	for i, n := range names {
		rec := withClient(t, readRecord(t, n), clients[i])
		clk.set(recordTime(t, rec))
		c.Observe(rec)
	}
	if c.Pending() != nil {
		t.Fatal("window completed early")
	}
	clk.set(first.Truncate(state.WafWindow).Add(state.WafWindow + grace))
	c.Roll()
	got := c.Pending()
	if len(got) != 2 {
		t.Fatalf("samples = %+v", got)
	}
	block, logOnly := got[0], got[1]
	if block.Route != routeBlock {
		block, logOnly = logOnly, block
	}
	if block.Blocked != 5 || block.Detected != 0 || logOnly.Detected != 1 || logOnly.Blocked != 0 {
		t.Errorf("totals: block %d/%d, log %d/%d", block.Blocked, block.Detected, logOnly.Blocked, logOnly.Detected)
	}
	if len(block.Events) != 5 || len(logOnly.Events) != 1 || logOnly.Events[0].Action != state.WafDetected {
		t.Errorf("events: %d / %d", len(block.Events), len(logOnly.Events))
	}
	cats := map[state.WafCategory]int64{}
	for _, cc := range block.Categories {
		cats[cc.Category] = cc.Blocked
	}
	if cats[state.WafCategorySQLi] != 2 || cats[state.WafCategoryXSS] != 1 || cats[state.WafCategoryScanner] != 1 || cats[state.WafCategoryLFI] != 1 || cats[state.WafCategoryRCE] != 1 {
		t.Errorf("categories = %v", cats)
	}

	rep := state.Report{
		TypeMeta: state.NewTypeMeta(state.KindReport), ServerID: "srv_01J9Z8X7W6V5T4S3R2Q1P0N9MK", ObservedAt: clk.now(), Seq: 1, Full: true, AppliedVersion: 1,
		Workloads: []state.WorkloadStatus{},
		Metrics:   &state.ReportMetrics{Host: []state.HostMetricSample{}, Workloads: []state.WorkloadMetricSample{}, Waf: got},
	}
	if err := rep.Validate(); err != nil {
		t.Fatalf("report invalid: %v", err)
	}
	enc, err := json.Marshal(rep)
	if err != nil {
		t.Fatal(err)
	}
	for _, leak := range append(clients[:6:6], "203.0.113.77", "198.51.100.23", "192.0.2.44", "2001:db8:17:4", "token", "abc", "hunter2", "OR '1'", "<script", "passwd", "UNION", "Nikto", "1%27") {
		if bytes.Contains(enc, []byte(leak)) {
			t.Errorf("report contains %q:\n%s", leak, enc)
		}
	}
	for _, want := range []string{`"clientNetwork":"203.0.113.0/24"`, `"clientNetwork":"2001:db8:17::/48"`, `"clientNetwork":"192.0.2.0/24"`, `"targets":["ARGS:id"]`} {
		if !bytes.Contains(enc, []byte(want)) {
			t.Errorf("report lacks %s", want)
		}
	}

	c.Ack(got[:1])
	if p := c.Pending(); len(p) != 1 || p[0].Route != got[1].Route {
		t.Errorf("after ack: %+v", p)
	}
	c.Ack(got)
	if c.Pending() != nil {
		t.Error("pending after full ack")
	}
}

// synthetic builds a blocked record for host at t.
func synthetic(host string, t time.Time, client, uri string, rules ...int) []byte {
	var msgs []string
	for _, r := range rules {
		msgs = append(msgs, fmt.Sprintf(`{"data":{"id":%d,"msg":"m","data":"Matched Data: x found within ARGS:p%d: secretvalue"}}`, r, r%7))
	}
	msgs = append(msgs, `{"data":{"id":949110,"msg":"Inbound Anomaly Score Exceeded (Total Score: 5)","data":""}}`)
	return fmt.Appendf(nil, `{"transaction":{"unix_timestamp":%d,"id":"ref%d","client_ip":%q,"server_id":%q,"request":{"method":"GET","uri":%q,"args":{"p":["secretvalue"]}},"is_interrupted":true},"messages":[%s]}`,
		t.UnixNano(), t.UnixNano()%100000, client, host, uri, strings.Join(msgs, ","))
}

func TestAggregationCaps(t *testing.T) {
	start := time.Date(2026, 10, 17, 12, 0, 0, 0, time.UTC)
	c, clk := newCollector(t, start)
	n := 0
	// 60 paths, 30 rules, 50 client networks, repeated requests.
	for i := range 600 {
		at := start.Add(time.Duration(i) * 500 * time.Millisecond)
		clk.set(at)
		c.Observe(synthetic("block.localhost", at, fmt.Sprintf("203.0.%d.9", i%50), fmt.Sprintf("/p%d?x=secretvalue", i%60), 941000+i%30, 942100, 913100+i%3, 930100, 932100, 933100, 934100, 920100, 944100, 943100))
		n++
	}
	// Unknown hosts are dropped.
	c.Observe(synthetic("other.example.com", start.Add(time.Second), "203.0.113.1", "/", 942100))
	clk.set(start.Add(state.WafWindow + grace))
	c.Roll()
	got := c.Pending()
	if len(got) != 1 {
		t.Fatalf("samples = %d", len(got))
	}
	s := got[0]
	if s.Blocked != int64(n) || len(s.Rules) != state.MaxWafRuleCounts || len(s.Paths) != state.MaxWafPathCounts || len(s.Events) != state.MaxWafEvents {
		t.Errorf("caps: blocked %d rules %d paths %d events %d", s.Blocked, len(s.Rules), len(s.Paths), len(s.Events))
	}
	var sum int64
	for _, p := range s.Paths {
		sum += p.Blocked
	}
	if sum+s.OtherPaths.Blocked != s.Blocked {
		t.Errorf("paths add up to %d, want %d", sum+s.OtherPaths.Blocked, s.Blocked)
	}
	for _, ev := range s.Events {
		if len(ev.Rules) > maxEventRules {
			t.Errorf("event with %d rules", len(ev.Rules))
		}
	}
	rep := state.ReportMetrics{Host: []state.HostMetricSample{}, Workloads: []state.WorkloadMetricSample{}, Waf: got}
	r := state.Report{TypeMeta: state.NewTypeMeta(state.KindReport), ServerID: "srv_01J9Z8X7W6V5T4S3R2Q1P0N9MK", ObservedAt: start, Seq: 1, Full: true, AppliedVersion: 1, Workloads: []state.WorkloadStatus{}, Metrics: &rep}
	if err := r.Validate(); err != nil {
		t.Fatal(err)
	}
	if enc, _ := json.Marshal(r); bytes.Contains(enc, []byte("secretvalue")) || bytes.Contains(enc, []byte("203.0.1.9")) {
		t.Error("values or addresses leaked into the report")
	}
	// Distinct events first: 20 distinct (network, rules, path) combinations.
	seen := map[string]bool{}
	for _, ev := range s.Events {
		seen[string(ev.ClientNetwork)+string(ev.Path)] = true
	}
	if len(seen) != state.MaxWafEvents {
		t.Errorf("distinct events = %d", len(seen))
	}
}

func TestLateRecordAndPendingCap(t *testing.T) {
	start := time.Date(2026, 10, 17, 12, 0, 0, 0, time.UTC)
	c, clk := newCollector(t, start)
	c.Observe(synthetic("block.localhost", start.Add(time.Minute), "203.0.113.1", "/a", 942100))
	clk.set(start.Add(state.WafWindow + 2*grace))
	c.Roll()
	// A record of the completed window arrives late: it counts in the current one.
	c.Observe(synthetic("block.localhost", start.Add(9*time.Minute), "203.0.113.1", "/a", 942100))
	clk.set(start.Add(2*state.WafWindow + grace))
	c.Roll()
	got := c.Pending()
	if len(got) != 2 || !got[1].At.Equal(start.Add(state.WafWindow)) {
		t.Fatalf("samples = %+v", got)
	}
	for i := range state.MaxWafSamples + 10 {
		at := start.Add(time.Duration(i+3) * state.WafWindow)
		clk.set(at)
		c.Observe(synthetic("block.localhost", at, "203.0.113.1", "/a", 942100))
	}
	clk.set(start.Add(time.Duration(state.MaxWafSamples+20) * state.WafWindow))
	c.Roll()
	if p := c.Pending(); len(p) != state.MaxWafSamples {
		t.Errorf("pending = %d", len(p))
	}
}

func TestReceiver(t *testing.T) {
	c, _ := newCollector(t, time.Now())
	srv := httptest.NewServer(c)
	defer srv.Close()
	post := func(path string, body []byte) int { return do(t, http.MethodPost, srv.URL+path, bytes.NewReader(body)) }
	if s := post(Path, readRecord(t, "blocked-sqli")); s != http.StatusNoContent {
		t.Errorf("status %d", s)
	}
	if s := post("/other", nil); s != http.StatusNotFound {
		t.Errorf("other path: %d", s)
	}
	big := httptest.NewRecorder()
	bigReq := httptest.NewRequestWithContext(t.Context(), http.MethodPost, Path, bytes.NewReader(bytes.Repeat([]byte("x"), MaxBody+1)))
	bigReq.RemoteAddr = "127.0.0.1:5555"
	c.ServeHTTP(big, bigReq)
	if big.Code != http.StatusRequestEntityTooLarge || c.Dropped() != 1 {
		t.Errorf("oversized: %d, dropped %d", big.Code, c.Dropped())
	}
	if s := do(t, http.MethodGet, srv.URL+Path, nil); s != http.StatusNotFound {
		t.Errorf("GET: %d", s)
	}
	if len(c.queue) != 1 {
		t.Errorf("queued = %d", len(c.queue))
	}
	// Overflow is counted, never blocks Coraza.
	for range QueueSize + 5 {
		post(Path, []byte("{}"))
	}
	if c.Dropped() != 1+6 { // one queued before: 1,023 more fit
		t.Errorf("dropped = %d", c.Dropped())
	}

	// Non-loopback peers are refused.
	rec := httptest.NewRecorder()
	req := httptest.NewRequestWithContext(t.Context(), http.MethodPost, Path, strings.NewReader("{}"))
	req.RemoteAddr = "10.0.0.5:4444"
	c.ServeHTTP(rec, req)
	if rec.Code != http.StatusForbidden {
		t.Errorf("remote peer: %d", rec.Code)
	}
}

func TestServeLoopbackOnly(t *testing.T) {
	c := New(Options{})
	for _, addr := range []string{"0.0.0.0:2023", "10.1.2.3:2023", "127.0.0.1:0", "localhost:2023"} {
		if err := c.Serve(context.Background(), addr); err == nil {
			t.Errorf("%s accepted", addr)
		}
	}
	var lc net.ListenConfig
	ln, err := lc.Listen(t.Context(), "tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	ctx, cancel := context.WithCancel(context.Background())
	done := make(chan error, 1)
	go func() { done <- c.ServeListener(ctx, ln) }()
	if s := do(t, http.MethodPost, "http://"+ln.Addr().String()+Path, strings.NewReader("{}")); s != http.StatusNoContent {
		t.Errorf("status %d", s)
	}
	cancel()
	if err := <-done; err != nil {
		t.Error(err)
	}
}

// Run decodes queued records.
func TestRunDecodesQueue(t *testing.T) {
	c, clk := newCollector(t, time.Now())
	kicked := make(chan struct{}, 1)
	c.o.OnWindow = func() {
		select {
		case kicked <- struct{}{}:
		default:
		}
	}
	c.o.Tick = 10 * time.Millisecond
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	go c.Run(ctx)
	rec := readRecord(t, "blocked-sqli")
	clk.set(recordTime(t, rec))
	c.queue <- rec
	time.Sleep(50 * time.Millisecond)
	clk.set(recordTime(t, rec).Add(state.WafWindow + grace))
	select {
	case <-kicked:
	case <-time.After(5 * time.Second):
		t.Fatal("no window completed")
	}
	if p := c.Pending(); len(p) != 1 || p[0].Blocked != 1 {
		t.Errorf("pending = %+v", p)
	}
}
