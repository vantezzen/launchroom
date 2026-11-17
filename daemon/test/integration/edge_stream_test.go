//go:build integration

package integration

import (
	"bufio"
	"bytes"
	"context"
	"crypto/rand"
	"crypto/sha256"
	"crypto/tls"
	"encoding/hex"
	"flag"
	"io"
	mrand "math/rand/v2"
	"mime/multipart"
	"net"
	"net/http"
	"strconv"
	"strings"
	"testing"
	"time"

	"github.com/moby/moby/client"

	"github.com/vantezzen/launchroom/daemon/internal/ingress/caddy"
	"github.com/vantezzen/launchroom/daemon/internal/names"
	"github.com/vantezzen/launchroom/daemon/internal/policy"
	"github.com/vantezzen/launchroom/daemon/internal/sealed"
	"github.com/vantezzen/launchroom/daemon/internal/secret"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// longStream runs the 10-minute variant of the event stream check (not part
// of the suite): ARGS='-test.run TestEdgeUploadsAndStreams -long-stream'.
var longStream = flag.Bool("long-stream", false, "stream server-sent events through the edge for 10 minutes")

const (
	sseHost       = "sse.localhost"
	streamWafAddr = "127.0.0.1:2035"
	uploadBytes   = 200 << 20
)

var sseWorkload = state.WorkloadID("wl_01J9Z8X7W6V5T4S3R2Q1P0N9NC")

// uploadScript is a CGI program answering the SHA-256 of the request body.
const uploadScript = `#!/bin/sh
printf 'Content-Type: text/plain\r\n\r\n'
head -c "$CONTENT_LENGTH" | sha256sum
`

// sseScript is a CGI program for busybox httpd: n events ("data: <i>"),
// one per second, as text/event-stream.
const sseScript = `#!/bin/sh
printf 'Content-Type: text/event-stream\r\nCache-Control: no-cache\r\n\r\n'
n="${QUERY_STRING#n=}"
i=0
while [ "$i" -lt "$n" ]; do
  printf 'data: %s\n\n' "$i"
  i=$((i+1))
  sleep 1
done
`

// TestEdgeUploadsAndStreams (G59, docs/22 §6): through an edge whose routes
// are in Block, with compression on, a 200 MB multipart upload reaches the
// app intact (its SHA-256), server-sent events arrive one by one (never buffered until
// the end), and the app sees the visitor's address in X-Real-IP whatever
// the client sent.
func TestEdgeUploadsAndStreams(t *testing.T) {
	e, ns := setupStreamEdge(t)
	block := &state.RouteWaf{Mode: state.WafModeBlock}
	ns.Routes[0].Waf = block
	ns.Routes = append(ns.Routes, state.RouteSpec{ID: "rt_01J9Z8X7W6V5T4S3R2Q1P0N9ND", Hosts: []state.Hostname{sseHost}, TLS: state.TLSInternal,
		Upstreams: []state.RouteUpstream{{Workload: sseWorkload, Port: 8080}}, Waf: block})
	if err := ns.Validate(); err != nil {
		t.Fatal(err)
	}
	converge(t, e, ns, func() bool {
		_, _, ing := e.rec.Snapshot()
		return status(e.rec, ns.Workloads[0].ID).Phase == state.PhaseHealthy && status(e.rec, sseWorkload).Phase == state.PhaseHealthy &&
			ing != nil && ing.State == state.IngressApplied && ing.Waf != nil && ing.Waf.Routes == 2
	})
	c := streamClient(30 * time.Minute)
	(&wafEdge{env: e}).waitServing(t, edgeClient(false), testHost, sseHost)

	t.Run("real ip", func(t *testing.T) {
		code, body, err := wafDo(t, edgeClient(false), http.MethodGet, "https://"+testHost+"/", nil, map[string]string{"X-Real-IP": "203.0.113.7"})
		if err != nil || code != 200 {
			t.Fatalf("%d %v", code, err)
		}
		if !strings.Contains(body, "X-Real-Ip: 127.0.0.1\r\n") || strings.Contains(body, "203.0.113.7") {
			t.Errorf("the app saw:\n%s", body)
		}
	})

	t.Run("200 MB upload", func(t *testing.T) {
		// A multipart form with one 200 MB file, with its length known (busybox
		// httpd takes no chunked bodies); the app answers the body's SHA-256.
		var head bytes.Buffer
		mw := multipart.NewWriter(&head)
		_ = mw.WriteField("title", "itest upload")
		if _, err := mw.CreateFormFile("file", "big.bin"); err != nil {
			t.Fatal(err)
		}
		tail := "\r\n--" + mw.Boundary() + "--\r\n"
		sent := sha256.New()
		body := io.TeeReader(io.MultiReader(bytes.NewReader(head.Bytes()), io.LimitReader(mrand.NewChaCha8([32]byte{7}), uploadBytes), strings.NewReader(tail)), sent)
		req, err := http.NewRequestWithContext(t.Context(), http.MethodPost, "https://"+sseHost+"/cgi-bin/upload", body)
		if err != nil {
			t.Fatal(err)
		}
		req.ContentLength = int64(head.Len()) + uploadBytes + int64(len(tail))
		req.Header.Set("Content-Type", mw.FormDataContentType())
		start := time.Now()
		resp, err := c.Do(req)
		if err != nil {
			t.Fatal(err)
		}
		defer func() { _ = resp.Body.Close() }()
		got, _ := io.ReadAll(io.LimitReader(resp.Body, 4096))
		if resp.StatusCode != 200 {
			t.Fatalf("upload: %d %q", resp.StatusCode, got)
		}
		want := hex.EncodeToString(sent.Sum(nil))
		t.Logf("%d byte upload through Block in %s: app got %q", req.ContentLength, time.Since(start).Round(time.Millisecond), strings.TrimSpace(string(got)))
		if !strings.HasPrefix(string(got), want) {
			t.Errorf("the app received other bytes: %q, want %s", got, want)
		}
	})

	t.Run("event stream", func(t *testing.T) {
		n := 20
		if *longStream {
			n = 600
		}
		req, err := http.NewRequestWithContext(t.Context(), http.MethodGet, "https://"+sseHost+"/cgi-bin/sse?n="+strconv.Itoa(n), nil)
		if err != nil {
			t.Fatal(err)
		}
		req.Header.Set("Accept", "text/event-stream")
		start := time.Now()
		resp, err := c.Do(req)
		if err != nil {
			t.Fatal(err)
		}
		defer func() { _ = resp.Body.Close() }()
		if resp.StatusCode != 200 || !strings.HasPrefix(resp.Header.Get("Content-Type"), "text/event-stream") {
			t.Fatalf("stream: %d %q", resp.StatusCode, resp.Header.Get("Content-Type"))
		}
		var arrivals []time.Duration
		sc := bufio.NewScanner(resp.Body)
		for sc.Scan() {
			if v, ok := strings.CutPrefix(sc.Text(), "data: "); ok {
				if v != strconv.Itoa(len(arrivals)) {
					t.Fatalf("event %d is %q", len(arrivals), v)
				}
				arrivals = append(arrivals, time.Since(start))
			}
		}
		if err := sc.Err(); err != nil {
			t.Fatalf("after %d events: %v", len(arrivals), err)
		}
		if len(arrivals) != n {
			t.Fatalf("%d events, want %d", len(arrivals), n)
		}
		var maxGap time.Duration
		for i := 1; i < n; i++ {
			maxGap = max(maxGap, arrivals[i]-arrivals[i-1])
		}
		t.Logf("%d events over %s: first after %s, largest gap %s", n, arrivals[n-1].Round(time.Millisecond), arrivals[0].Round(time.Millisecond), maxGap.Round(time.Millisecond))
		// Unbuffered: the first event comes right away, the events are spread
		// over the stream's lifetime and no gap is much longer than a second.
		if arrivals[0] > 5*time.Second || arrivals[n-1]-arrivals[0] < time.Duration(n-1)*time.Second/2 || maxGap > 5*time.Second {
			t.Errorf("events were buffered: %v", arrivals)
		}
	})
}

// sseStart installs the CGI programs from the env and starts busybox httpd.
const sseStart = `mkdir -p /www/cgi-bin
printf '%s' "$SSE_SCRIPT" > /www/cgi-bin/sse
printf '%s' "$UPLOAD_SCRIPT" > /www/cgi-bin/upload
chmod 755 /www/cgi-bin/sse /www/cgi-bin/upload
echo ok > /www/index.html
exec httpd -f -p 8080 -h /www
`

// setupStreamEdge is an edge running launchroom's Caddy with the firewall,
// and a NodeState with whoami (testHost) and a busybox httpd that streams
// events from a CGI program (sseHost, route added by the caller).
func setupStreamEdge(t *testing.T) (*env, *state.NodeState) {
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
	guardAllowSystemDigest(t, names.SystemCaddy, digest)
	e := setupEdge(t, caddy.Options{}, nil, nil, func(mo *caddy.ManagerOptions, pol *policy.Config) {
		pol.SystemImageDigests[names.SystemCaddy] = append(pol.SystemImageDigests[names.SystemCaddy], digest)
		pol.AllowedRegistries = append(pol.AllowedRegistries, "docker.io/library/")
		mo.WafLogAddress = streamWafAddr // nobody listens: blocking works without the receiver
	})
	ns, _ := e.nodeState(t, 1, true, true)
	ns.System.Caddy.Image = state.ImageRef("docker.io/library/launchroom-dev-caddy@" + digest)
	env, err := sealed.SealWorkloadEnv(state.WorkloadEnv{ServerID: e.serverID, WorkloadID: sseWorkload,
		Env: map[string]secret.Secret{"SSE_SCRIPT": sseScript, "UPLOAD_SCRIPT": uploadScript}}, e.key.PublicKey(), rand.Reader)
	if err != nil {
		t.Fatal(err)
	}
	port := state.Port(8080)
	app := ns.Workloads[0]
	ns.Workloads = append(ns.Workloads, state.WorkloadSpec{
		ID: sseWorkload, Service: "svc_01J9Z8X7W6V5T4S3R2Q1P0N9NE", Environment: app.Environment, Deployment: "dpl_01J9Z8X7W6V5T4S3R2Q1P0N9NF",
		Image: busyboxImage, Env: env, Port: &port,
		// The start script is a config file run by path: no shell string (docs/09 R1).
		Files:     []state.ConfigFile{{Path: "/opt/start.sh", Content: sseStart}},
		Command:   []string{"sh", "/opt/start.sh"},
		Health:    &state.HealthSpec{Type: state.HealthHTTP, Path: "/", StartPeriod: "30s", Interval: "1s", Timeout: "1s", FailureThreshold: 3},
		Resources: state.Resources{CPUs: 0.5, MemoryMB: 64, Pids: 64},
		Networks:  []state.NetworkAttachment{{Network: app.Networks[0].Network, Aliases: []state.Slug{"sse"}}},
		Restart:   state.RestartUnlessStopped, Role: state.RoleServing,
	})
	return e, ns
}

// streamClient is edgeClient with a long timeout.
func streamClient(timeout time.Duration) *http.Client {
	return &http.Client{Timeout: timeout, Transport: &http.Transport{
		Proxy:           nil,
		TLSClientConfig: &tls.Config{InsecureSkipVerify: true, MinVersion: tls.VersionTLS12}, //nolint:gosec // internal CA in tests
		DialContext: func(ctx context.Context, network, _ string) (net.Conn, error) {
			var d net.Dialer
			return d.DialContext(ctx, network, "127.0.0.1:443")
		},
	}}
}
