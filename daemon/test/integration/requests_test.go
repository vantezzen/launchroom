//go:build integration

package integration

import (
	"context"
	"crypto/tls"
	"net"
	"net/http"
	"sync/atomic"
	"testing"
	"time"

	"github.com/vantezzen/launchroom/daemon/internal/ingress/caddy"
	"github.com/vantezzen/launchroom/daemon/internal/state"
	"github.com/vantezzen/launchroom/daemon/internal/traffic"
)

// TestRequestMetrics sends HTTPS requests through the real edge Caddy and
// checks that its access log reaches launchroomd's listener and is counted
// per route (docs/15 §1.1): status classes, sizes, the latency histogram,
// and nothing for hosts without a route.
func TestRequestMetrics(t *testing.T) {
	const addr = "127.0.0.1:2032"
	var offset atomic.Int64 // lets the test complete minutes without waiting
	col := traffic.New(traffic.Options{Now: func() time.Time { return time.Now().Add(time.Duration(offset.Load())) }})
	ctx, cancel := context.WithCancel(t.Context())
	defer cancel()
	go func() {
		if err := col.Serve(ctx, addr); err != nil {
			t.Errorf("serve: %v", err)
		}
	}()

	e := setupWith(t, caddy.Options{AccessLogAddress: addr})
	ns, wl := e.nodeState(t, 1, true, true)
	col.SetRoutes(ns.Routes)
	converge(t, e, ns, func() bool {
		_, _, ing := e.rec.Snapshot()
		return status(e.rec, wl).Phase == state.PhaseHealthy && ing != nil && ing.State == state.IngressApplied
	})

	tlsClient := &http.Client{Timeout: 10 * time.Second, Transport: &http.Transport{
		Proxy: nil, DisableKeepAlives: true,
		TLSClientConfig: &tls.Config{ServerName: testHost, InsecureSkipVerify: true, MinVersion: tls.VersionTLS12}, //nolint:gosec // internal CA in tests
		DialContext: func(ctx context.Context, network, _ string) (net.Conn, error) {
			var d net.Dialer
			return d.DialContext(ctx, network, "127.0.0.1:443")
		},
	}}
	// Wait until the route serves (the certificate is issued on first use).
	deadline := time.Now().Add(90 * time.Second)
	for {
		if code, _, _, err := get(t, tlsClient, "https://"+testHost+"/", testHost); err == nil && code == 200 {
			break
		}
		if time.Now().After(deadline) {
			t.Fatal("route never served")
		}
		time.Sleep(time.Second)
	}
	// Start counting from a clean slate: drain the probe requests' lines
	// until a whole second passes without a new one.
	for quiet := 0; quiet < 2; {
		time.Sleep(500 * time.Millisecond)
		offset.Add(int64(time.Minute))
		col.Roll()
		if p := col.Pending(); len(p) > 0 {
			col.Ack(p)
			quiet = 0
		} else {
			quiet++
		}
	}

	const n = 5
	for range n {
		if code, _, _, err := get(t, tlsClient, "https://"+testHost+"/", testHost); err != nil || code != 200 {
			t.Fatalf("request: %d %v", code, err)
		}
	}
	// A plain-HTTP redirect is not logged (only lr-https is).
	if _, _, _, err := get(t, noRedirect, "http://127.0.0.1:80/", testHost); err != nil {
		t.Fatal(err)
	}

	route := ns.Routes[0].ID
	var got state.RequestMetricSample
	deadline = time.Now().Add(30 * time.Second)
	for {
		offset.Add(int64(time.Minute))
		col.Roll()
		got = state.RequestMetricSample{}
		for _, r := range col.Pending() {
			if r.Route != route {
				t.Fatalf("unexpected route %s", r.Route)
			}
			got.Requests += r.Requests
			got.Status2xx += r.Status2xx
			got.BytesOut += r.BytesOut
			if r.LatencyMs != nil {
				if got.LatencyMs == nil {
					got.LatencyMs = make([]int64, state.LatencyBuckets)
				}
				for i, c := range r.LatencyMs {
					got.LatencyMs[i] += c
				}
			}
		}
		if got.Requests >= n || time.Now().After(deadline) {
			break
		}
		time.Sleep(500 * time.Millisecond)
	}
	t.Logf("request metrics: %+v", got)
	var hist int64
	for _, c := range got.LatencyMs {
		hist += c
	}
	if got.Requests != n || got.Status2xx != n || got.BytesOut == 0 || hist != n {
		t.Fatalf("counted %+v, want %d requests (2xx, with sizes and latencies)", got, n)
	}
}
