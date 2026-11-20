//go:build integration

package integration

import (
	"context"
	"crypto/aes"
	"crypto/cipher"
	"crypto/rand"
	"encoding/base64"
	"encoding/binary"
	"encoding/hex"
	"fmt"
	"io"
	"math/big"
	"net"
	"net/http"
	"regexp"
	"slices"
	"strings"
	"testing"
	"time"

	"github.com/moby/moby/client"

	"github.com/vantezzen/launchroom/daemon/internal/ingress/caddy"
	"github.com/vantezzen/launchroom/daemon/internal/sealed"
	"github.com/vantezzen/launchroom/daemon/internal/secret"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// traceService is the service instance of the itest's app route.
const traceService = state.ServiceInstanceID("sin_01J9Z8X7W6V5T4S3R2Q1P0N9NC")

// Trace fields (docs/23 §2).
const (
	tagReceivedAt     = 0x01
	tagRequestID      = 0x02
	tagEdge           = 0x03
	tagRoute          = 0x04
	tagService        = 0x05
	tagUpstreamServer = 0x06
	tagMethod         = 0x07
	tagPath           = 0x08
	tagProtocol       = 0x09
	tagStatus         = 0x0A
	tagOrigin         = 0x0B
	tagEdgeTime       = 0x0C
	tagUpstreamTime   = 0x0D
	tagAttempts       = 0x0F
	tagFirewall       = 0x11
)

// Origins (docs/23 §2.2).
const (
	originApp      = 1
	originFirewall = 2
	originRedirect = 5
	originNoRoute  = 6
)

// decodeTrace opens an X-Launchroom-Ray header (docs/23 §2) into its fields.
func decodeTrace(t *testing.T, header string, key []byte, keyID uint32) map[byte][]byte {
	t.Helper()
	envelope, ok := strings.CutPrefix(header, "lr1.")
	if !ok {
		t.Fatalf("ray %q without the lr1. prefix", header)
	}
	raw, err := base64.RawURLEncoding.DecodeString(envelope)
	if err != nil || len(raw) < 1+4+12+16 {
		t.Fatalf("trace header %q: %v", header, err)
	}
	if raw[0] != 1 || binary.BigEndian.Uint32(raw[1:5]) != keyID {
		t.Fatalf("trace version %d key id %d", raw[0], binary.BigEndian.Uint32(raw[1:5]))
	}
	block, err := aes.NewCipher(key)
	if err != nil {
		t.Fatal(err)
	}
	gcm, err := cipher.NewGCM(block)
	if err != nil {
		t.Fatal(err)
	}
	payload, err := gcm.Open(nil, raw[5:17], raw[17:], raw[:5])
	if err != nil {
		t.Fatalf("trace does not open: %v", err)
	}
	fields := map[byte][]byte{}
	for len(payload) > 0 {
		if len(payload) < 2 || int(payload[1]) > len(payload)-2 {
			t.Fatalf("trace payload truncated")
		}
		fields[payload[0]] = payload[2 : 2+int(payload[1])]
		payload = payload[2+int(payload[1]):]
	}
	return fields
}

// ulidBytes is the 16-byte value of a public id's ULID.
func ulidBytes(t *testing.T, id string) []byte {
	t.Helper()
	_, u, _ := strings.Cut(id, "_")
	n := new(big.Int)
	for _, c := range u {
		i := strings.IndexRune("0123456789ABCDEFGHJKMNPQRSTVWXYZ", c)
		if i < 0 {
			t.Fatalf("ulid %s", id)
		}
		n.Lsh(n, 5).Or(n, big.NewInt(int64(i)))
	}
	return n.FillBytes(make([]byte, 16))
}

func uuidOf(b []byte) string {
	h := hex.EncodeToString(b)
	if len(h) != 32 {
		return ""
	}
	return h[:8] + "-" + h[8:12] + "-" + h[12:16] + "-" + h[16:20] + "-" + h[20:]
}

// TestRequestTrace (docs/23): with launchroom's Caddy carrying the trace
// module, every response carries an X-Launchroom-Ray header encrypted with
// the edge's key: app answers, firewall blocks, redirects and unknown hosts,
// and the app gets the trace's request id as X-Request-Id.
func TestRequestTrace(t *testing.T) {
	api, err := client.New(client.FromEnv)
	if err != nil {
		t.Fatal(err)
	}
	insp, err := api.ImageInspect(t.Context(), localCaddyImage)
	_ = api.Close()
	if err != nil {
		t.Skipf("%s is not loaded (run `make caddy-image`): %v", localCaddyImage, err)
	}
	if insp.Config == nil || !caddy.TraceSupported(insp.Config.Labels) {
		t.Skipf("%s has no trace module (label %s)", localCaddyImage, caddy.LabelTrace)
	}

	w := setupWafEdgeOpen(t, func(e *env) func(state.Sealed, state.IngressSecretPurpose) (map[string]secret.Secret, error) {
		return func(s state.Sealed, p state.IngressSecretPurpose) (map[string]secret.Secret, error) {
			return sealed.OpenIngressSecret(s, e.key, e.serverID, p)
		}
	})
	key := make([]byte, 32)
	if _, err := io.ReadFull(rand.Reader, key); err != nil {
		t.Fatal(err)
	}
	const keyID = 4242
	sealedKey, err := sealed.SealJSON(state.IngressSecret{ServerID: w.serverID, Purpose: state.IngressSecretTrace, Values: map[string]secret.Secret{
		"key": secret.Secret(base64.StdEncoding.EncodeToString(key)), "keyId": secret.Secret(fmt.Sprint(keyID)),
	}}, w.key.PublicKey(), rand.Reader)
	if err != nil {
		t.Fatal(err)
	}
	ns, wl := w.wafState(t, 1, &state.RouteWaf{Mode: state.WafModeBlock}, nil)
	ns.Ingress = &state.IngressSpec{Trace: &sealedKey}
	w.spec.Store(ns.Ingress)
	ns.Routes[0].Service = traceService
	if err := ns.Validate(); err != nil {
		t.Fatal(err)
	}
	converge(t, w.env, ns, func() bool {
		_, _, ing := w.rec.Snapshot()
		return status(w.rec, wl).Phase == state.PhaseHealthy && ing != nil && ing.State == state.IngressApplied
	})
	if !slices.Contains(w.ingress.Features(), state.FeatureRequestTrace) {
		t.Fatal("requestTrace is not announced for launchroom's Caddy with the module")
	}
	c := edgeClient(false)
	w.waitServing(t, c, testHost)

	// res is a response with its body read and closed.
	type res struct {
		code   int
		header http.Header
		body   string
	}
	do := func(c *http.Client, url string, hdr map[string]string) res {
		t.Helper()
		ctx, cancel := context.WithTimeout(t.Context(), 15*time.Second)
		defer cancel()
		req, err := http.NewRequestWithContext(ctx, http.MethodGet, url, nil) //nolint:gosec // G704: tests call their own local endpoints
		if err != nil {
			t.Fatal(err)
		}
		for k, v := range hdr {
			req.Header.Set(k, v)
		}
		resp, err := c.Do(req) //nolint:gosec // G704: tests call their own local endpoints
		if err != nil {
			t.Fatalf("GET %s: %v", url, err)
		}
		defer func() { _ = resp.Body.Close() }()
		b, _ := io.ReadAll(io.LimitReader(resp.Body, 1<<16))
		return res{resp.StatusCode, resp.Header, string(b)}
	}
	fieldsOf := func(resp res) map[byte][]byte {
		t.Helper()
		if resp.header.Get("Launchroom-Trace") != "" {
			t.Error("the old Launchroom-Trace header is still sent")
		}
		h := resp.header.Get("X-Launchroom-Ray")
		if h == "" {
			t.Fatalf("%d response without a trace header:\n%s", resp.code, resp.body)
		}
		f := decodeTrace(t, h, key, keyID)
		if string(f[tagEdge]) != string(ulidBytes(t, string(w.serverID))) {
			t.Error("edge field is not this server")
		}
		if len(f[tagReceivedAt]) != 8 || len(f[tagRequestID]) != 16 || len(f[tagStatus]) != 2 || len(f[tagOrigin]) != 1 {
			t.Errorf("fixed fields missing: %v", f)
		}
		if got := binary.BigEndian.Uint16(f[tagStatus]); int(got) != resp.code {
			t.Errorf("status field %d, response %d", got, resp.code)
		}
		return f
	}

	// The app: route, service, upstream server (this edge), method, the
	// normalised path, timings; the app saw the trace's request id, not the
	// client's.
	resp := do(c, "https://"+testHost+"/api/products/42?token=itest-query-secret", map[string]string{"X-Request-Id": "client-chosen"})
	body := resp.body
	f := fieldsOf(resp)
	if resp.code != 200 || f[tagOrigin][0] != originApp {
		t.Fatalf("app: %d origin %v", resp.code, f[tagOrigin])
	}
	if string(f[tagRoute]) != string(ulidBytes(t, string(ns.Routes[0].ID))) || string(f[tagService]) != string(ulidBytes(t, string(traceService))) ||
		string(f[tagUpstreamServer]) != string(ulidBytes(t, string(w.serverID))) {
		t.Error("route, service or upstream server field wrong")
	}
	if string(f[tagMethod]) != "GET" || string(f[tagPath]) != "/api/products/:id" || len(f[tagProtocol]) != 1 || len(f[tagEdgeTime]) != 4 || len(f[tagUpstreamTime]) != 4 {
		t.Errorf("method %q path %q protocol %v", f[tagMethod], f[tagPath], f[tagProtocol])
	}
	if a := f[tagAttempts]; len(a) != 1 || a[0] != 1 {
		t.Errorf("attempts %v", a)
	}
	reqID := uuidOf(f[tagRequestID])
	if !strings.Contains(strings.ToLower(body), "x-request-id: "+reqID) || strings.Contains(body, "client-chosen") {
		t.Errorf("the app did not get the trace's request id %s:\n%s", reqID, body)
	}

	// The firewall: blocked, with its rules; the block page's reference is the request id.
	resp = do(c, "https://"+testHost+"/api/products/42?id=1%27%20OR%20%271%27=%271", nil)
	body = resp.body
	f = fieldsOf(resp)
	if resp.code != 403 || f[tagOrigin][0] != originFirewall {
		t.Fatalf("blocked: %d origin %v", resp.code, f[tagOrigin])
	}
	fw := f[tagFirewall]
	if len(fw) < 3+4 || (len(fw)-3)%4 != 0 || fw[0] != 2 || fw[1] != 2 || fw[2] == 0 {
		t.Errorf("firewall field %v (want mode block, outcome blocked, a score and rules)", fw)
	}
	for i := 3; i+4 <= len(fw); i += 4 {
		if id := binary.BigEndian.Uint32(fw[i:]); id < 900000 || id > 999999 {
			t.Errorf("rule id %d is not a CRS rule", id)
		}
	}
	ref := uuidOf(f[tagRequestID])
	if !strings.HasSuffix(strings.TrimSpace(body), "Reference: "+ref) {
		t.Errorf("block page reference is not the request id %s: %q", ref, body)
	}
	if !regexp.MustCompile(`^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$`).MatchString(ref) {
		t.Errorf("request id %q is not a UUID", ref)
	}

	// HTTP → HTTPS redirect.
	plain := &http.Client{Timeout: 15 * time.Second, CheckRedirect: func(*http.Request, []*http.Request) error { return http.ErrUseLastResponse },
		Transport: &http.Transport{Proxy: nil, DisableKeepAlives: true, DialContext: func(ctx context.Context, network, _ string) (net.Conn, error) {
			var d net.Dialer
			return d.DialContext(ctx, network, "127.0.0.1:80")
		}}}
	resp = do(plain, "http://"+testHost+"/x", nil)
	if f = fieldsOf(resp); resp.code != http.StatusPermanentRedirect || f[tagOrigin][0] != originRedirect ||
		string(f[tagRoute]) != string(ulidBytes(t, string(ns.Routes[0].ID))) {
		t.Errorf("redirect: %d origin %v", resp.code, f[tagOrigin])
	}

	// No route on this edge: the 404 (plain HTTP: no certificate for an unknown host) carries a trace without route.
	resp = do(plain, "http://unknown.localhost/", nil)
	if f = fieldsOf(resp); resp.code != 404 || f[tagOrigin][0] != originNoRoute || f[tagRoute] != nil {
		t.Errorf("no route: %d origin %v route %v", resp.code, f[tagOrigin], f[tagRoute])
	}

	// The firewall's audit record carries the same reference (WafEvent.reference).
	var found bool
	for deadline := time.Now().Add(30 * time.Second); !found && time.Now().Before(deadline); {
		time.Sleep(time.Second)
		w.offset.Add(int64(state.WafWindow + 2*time.Minute))
		w.col.Roll()
		for _, s := range w.col.Pending() {
			for _, ev := range s.Events {
				found = found || string(ev.Reference) == ref
			}
		}
		w.col.Ack(w.col.Pending())
	}
	if !found {
		t.Error("no firewall event with the trace's request id as reference")
	}
}
