//go:build integration

package integration

import (
	"context"
	"crypto/ecdsa"
	"crypto/ed25519"
	"crypto/elliptic"
	"crypto/rand"
	"crypto/sha256"
	"crypto/tls"
	"crypto/x509"
	"crypto/x509/pkix"
	"encoding/base64"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"io"
	"math/big"
	"net"
	"net/http"
	"net/netip"
	"net/url"
	"strings"
	"sync"
	"sync/atomic"
	"testing"
	"time"

	"github.com/vantezzen/launchroom/daemon/internal/clientcert"
	"github.com/vantezzen/launchroom/daemon/internal/controlplane"
	"github.com/vantezzen/launchroom/daemon/internal/identity"
	"github.com/vantezzen/launchroom/daemon/internal/ingress/caddy"
	"github.com/vantezzen/launchroom/daemon/internal/sealed"
	"github.com/vantezzen/launchroom/daemon/internal/secret"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// itCA is an in-test version of the dashboard's internal CA.
type itCA struct {
	key  *ecdsa.PrivateKey
	cert *x509.Certificate
}

func newITCA(t *testing.T, cn string) *itCA {
	t.Helper()
	key, err := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	if err != nil {
		t.Fatal(err)
	}
	tpl := &x509.Certificate{SerialNumber: big.NewInt(1), Subject: pkix.Name{CommonName: cn},
		NotBefore: time.Now().Add(-time.Hour), NotAfter: time.Now().Add(24 * time.Hour), IsCA: true, BasicConstraintsValid: true,
		MaxPathLenZero: true, KeyUsage: x509.KeyUsageCertSign | x509.KeyUsageCRLSign}
	der, err := x509.CreateCertificate(rand.Reader, tpl, tpl, &key.PublicKey, key)
	if err != nil {
		t.Fatal(err)
	}
	cert, _ := x509.ParseCertificate(der)
	return &itCA{key: key, cert: cert}
}

func (c *itCA) issue(t *testing.T, server state.ServerID, pub *ecdsa.PublicKey, notBefore, notAfter time.Time) []byte {
	t.Helper()
	u, _ := url.Parse("urn:launchroom:server:" + string(server))
	serial, _ := rand.Int(rand.Reader, new(big.Int).Lsh(big.NewInt(1), 127))
	tpl := &x509.Certificate{SerialNumber: serial, Subject: pkix.Name{CommonName: string(server), Organization: []string{"launchroom"}},
		URIs: []*url.URL{u}, NotBefore: notBefore, NotAfter: notAfter, BasicConstraintsValid: true,
		KeyUsage: x509.KeyUsageDigitalSignature, ExtKeyUsage: []x509.ExtKeyUsage{x509.ExtKeyUsageClientAuth}}
	der, err := x509.CreateCertificate(rand.Reader, tpl, c.cert, pub, c.key)
	if err != nil {
		t.Fatal(err)
	}
	return der
}

func fingerprint(der []byte) string {
	sum := sha256.Sum256(der)
	return hex.EncodeToString(sum[:])
}

// fakeDashboard emulates the dashboard's side of docs/03 §4.3: it issues
// certificates and, for every other daemon request, requires the edge
// secret and (with require) a known, unrevoked certificate of the server.
type fakeDashboard struct {
	t        *testing.T
	ca       *itCA
	server   state.ServerID
	cpKey    ed25519.PrivateKey
	edge     string
	lifetime time.Duration

	mu      sync.Mutex
	issued  map[string]time.Time // fingerprint → notAfter
	revoked map[string]bool
	require bool
	seen    map[string]bool
	refused atomic.Int64
}

func (f *fakeDashboard) sign(w http.ResponseWriter, doc any) {
	body, err := state.Encode(doc)
	if err != nil {
		f.t.Error(err)
		return
	}
	sig, err := identity.SignResponse("cp-test", f.cpKey, body)
	if err != nil {
		f.t.Error(err)
		return
	}
	w.Header().Set(identity.ResponseSignatureHeader, sig)
	w.Header().Set("Content-Type", "application/json")
	_, _ = w.Write(body)
}

func (f *fakeDashboard) ServeHTTP(w http.ResponseWriter, r *http.Request) {
	switch r.URL.Path {
	case "/up":
		_, _ = io.WriteString(w, "up")
		return
	case "/daemon/v1/enroll":
		_, _ = io.WriteString(w, "enroll reached the dashboard")
		return
	}
	if r.Header.Get(state.EdgeSecretHeader) != f.edge {
		f.refused.Add(1)
		http.Error(w, `{"error":"unauthorized"}`, http.StatusUnauthorized)
		return
	}
	fp := r.Header.Get(state.ClientCertHeader)
	f.mu.Lock()
	notAfter, known := f.issued[fp]
	valid := fp != "" && known && !f.revoked[fp] && time.Now().Before(notAfter)
	if valid {
		f.seen[fp] = true
	}
	require := f.require
	f.mu.Unlock()
	if fp != "" && !valid || require && !valid && r.URL.Path != state.ClientCertificatePath {
		f.refused.Add(1)
		http.Error(w, `{"error":"unauthorized"}`, http.StatusUnauthorized)
		return
	}
	switch r.URL.Path {
	case state.ClientCertificatePath:
		var req state.ClientCertificateRequest
		if err := json.NewDecoder(r.Body).Decode(&req); err != nil {
			http.Error(w, "bad request", http.StatusBadRequest)
			return
		}
		pub, err := req.PublicKey.ParseP256PublicKey()
		if err != nil {
			http.Error(w, "bad key", http.StatusBadRequest)
			return
		}
		now := time.Now()
		f.mu.Lock()
		lifetime := f.lifetime
		f.mu.Unlock()
		nb, na := now.Add(-5*time.Second), now.Add(lifetime)
		der := f.ca.issue(f.t, f.server, pub, nb, na)
		f.mu.Lock()
		f.issued[fingerprint(der)] = na
		f.mu.Unlock()
		f.sign(w, &state.ClientCertificate{TypeMeta: state.NewTypeMeta(state.KindClientCertificate), ServerID: f.server,
			Certificate:    state.DERBase64(base64.StdEncoding.EncodeToString(der)),
			CACertificates: []state.DERBase64{state.DERBase64(base64.StdEncoding.EncodeToString(f.ca.cert.Raw))},
			NotBefore:      nb, NotAfter: na, RenewAfter: now.Add(lifetime * 2 / 3)})
	case "/daemon/v1/config":
		f.sign(w, &state.DaemonConfig{TypeMeta: state.NewTypeMeta(state.KindDaemonConfig)})
	default:
		_, _ = io.WriteString(w, "ok cert="+fp) //nolint:gosec // G705: plain-text test echo of a header the test checks
	}
}

// dialEdge sends every connection to the local Caddy.
func dialEdge(ctx context.Context, network, _ string) (net.Conn, error) {
	var d net.Dialer
	return d.DialContext(ctx, network, "127.0.0.1:443")
}

func mtlsClient(host string, cert *tls.Certificate) *http.Client {
	cfg := &tls.Config{ServerName: host, InsecureSkipVerify: true, MinVersion: tls.VersionTLS12} //nolint:gosec // Caddy's internal CA in tests
	if cert != nil {
		// Always presented: Go's client would leave out a certificate whose
		// issuer the server doesn't list, and the edge must see the attempt.
		cfg.GetClientCertificate = func(*tls.CertificateRequestInfo) (*tls.Certificate, error) { return cert, nil }
	}
	return &http.Client{Timeout: 10 * time.Second,
		Transport: &http.Transport{Proxy: nil, DisableKeepAlives: true, TLSClientConfig: cfg, DialContext: dialEdge}}
}

func leafPair(t *testing.T, der []byte, key *ecdsa.PrivateKey) *tls.Certificate {
	t.Helper()
	leaf, err := x509.ParseCertificate(der)
	if err != nil {
		t.Fatal(err)
	}
	return &tls.Certificate{Certificate: [][]byte{der}, PrivateKey: key, Leaf: leaf}
}

// TestClientCertificatesEdge is mTLS for the daemon transport (D50,
// docs/03 §4.3) through the real Caddy: the dashboard's system route with
// clientAuth verifies presented certificates (verify_if_given), forwards
// the fingerprint and the edge secret (overwriting what clients send), and
// with require refuses /daemon/v1/* without a certificate except
// enrollment and issuance. Foreign, expired and revoked certificates are
// refused; renewal with a new key every few seconds drops no request.
func TestClientCertificatesEdge(t *testing.T) {
	ca := newITCA(t, "launchroom server CA")
	_, cpKey, err := ed25519.GenerateKey(rand.Reader)
	if err != nil {
		t.Fatal(err)
	}
	const edgeSecret = "lr_edge_dGVzdC1lZGdlLXNlY3JldC0wMTIzNDU2Nzg5YWJjZGVm"
	dash := &fakeDashboard{t: t, ca: ca, cpKey: cpKey, edge: edgeSecret, lifetime: time.Hour,
		issued: map[string]time.Time{}, revoked: map[string]bool{}, seen: map[string]bool{}}

	ln, err := (&net.ListenConfig{}).Listen(t.Context(), "tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	srv := &http.Server{ReadHeaderTimeout: 5 * time.Second, Handler: dash}
	go func() { _ = srv.Serve(ln) }()
	t.Cleanup(func() { _ = srv.Close() })
	upstream := netip.MustParseAddrPort(ln.Addr().String())

	e := setupLocalOpen(t, caddy.Options{}, []netip.AddrPort{upstream}, func(e *env) func(state.Sealed, state.IngressSecretPurpose) (map[string]secret.Secret, error) {
		return func(s state.Sealed, p state.IngressSecretPurpose) (map[string]secret.Secret, error) {
			return sealed.OpenIngressSecret(s, e.key, e.serverID, p)
		}
	})
	dash.server = e.serverID
	edgeSealed, err := sealed.SealJSON(state.IngressSecret{ServerID: e.serverID, Purpose: state.IngressSecretClientAuth,
		Values: map[string]secret.Secret{"secret": edgeSecret}}, e.key.PublicKey(), rand.Reader)
	if err != nil {
		t.Fatal(err)
	}
	const host = "dash.localhost"
	caDER := state.DERBase64(base64.StdEncoding.EncodeToString(ca.cert.Raw))
	apply := func(version int64, mode state.ClientAuthMode) {
		t.Helper()
		ns, _ := e.nodeState(t, version, false, true)
		ns.Routes = []state.RouteSpec{{
			ID: "rt_0000000000000000000001DASH", Hosts: []state.Hostname{host}, TLS: state.TLSInternal,
			Upstreams:  []state.RouteUpstream{{Address: state.AddrPort(upstream.String())}},
			ClientAuth: &state.RouteClientAuth{Mode: mode, CACertificates: []state.DERBase64{caDER}, EdgeSecret: edgeSealed},
		}}
		if err := ns.Validate(); err != nil {
			t.Fatal(err)
		}
		converge(t, e, ns, func() bool {
			_, _, ing := e.rec.Snapshot()
			return ing != nil && ing.State == state.IngressApplied
		})
		dash.mu.Lock()
		dash.require = mode == state.ClientAuthRequire
		dash.mu.Unlock()
	}
	apply(1, state.ClientAuthVerify)

	get := func(c *http.Client, path string, hdr map[string]string) (int, string, error) {
		code, _, body, err := do(t, c, http.MethodGet, "https://"+host+path, "", hdr)
		return code, body, err
	}
	// Caddy needs a moment for its internal certificate.
	waitFor(t.Context(), t, "the dashboard route over https", func() error {
		code, body, err := get(mtlsClient(host, nil), "/up", nil)
		if err != nil || code != 200 {
			return fmt.Errorf("%d %q %w", code, body, err)
		}
		return nil
	})

	// Verify mode: no certificate passes the edge (browsers), with an empty
	// fingerprint and the real edge secret whatever the client sends.
	code, body, err := get(mtlsClient(host, nil), "/daemon/v1/state", map[string]string{
		state.ClientCertHeader: strings.Repeat("a", 64), state.EdgeSecretHeader: "forged"})
	if err != nil || code != 200 || body != "ok cert=" {
		t.Fatalf("no certificate, verify mode: %d %q %v", code, body, err)
	}

	// A certificate of this CA: its fingerprint reaches the dashboard.
	key, _ := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	der := ca.issue(t, e.serverID, &key.PublicKey, time.Now().Add(-time.Minute), time.Now().Add(time.Hour))
	dash.mu.Lock()
	dash.issued[fingerprint(der)] = time.Now().Add(time.Hour)
	dash.mu.Unlock()
	good := mtlsClient(host, leafPair(t, der, key))
	if code, body, err := get(good, "/daemon/v1/state", map[string]string{state.ClientCertHeader: "forged"}); err != nil || code != 200 || body != "ok cert="+fingerprint(der) {
		t.Fatalf("valid certificate: %d %q %v", code, body, err)
	}

	// A foreign CA's certificate and an expired one fail the handshake.
	foreign := newITCA(t, "someone else")
	fkey, _ := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	fder := foreign.issue(t, e.serverID, &fkey.PublicKey, time.Now().Add(-time.Minute), time.Now().Add(time.Hour))
	if code, _, err := get(mtlsClient(host, leafPair(t, fder, fkey)), "/daemon/v1/state", nil); err == nil {
		t.Errorf("foreign certificate accepted: %d", code)
	}
	ekey, _ := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	eder := ca.issue(t, e.serverID, &ekey.PublicKey, time.Now().Add(-2*time.Hour), time.Now().Add(-time.Hour))
	if code, _, err := get(mtlsClient(host, leafPair(t, eder, ekey)), "/daemon/v1/state", nil); err == nil {
		t.Errorf("expired certificate accepted: %d", code)
	}

	// Revoked: the edge forwards it, the dashboard refuses it.
	dash.mu.Lock()
	dash.revoked[fingerprint(der)] = true
	dash.mu.Unlock()
	if code, _, err := get(good, "/daemon/v1/state", nil); err != nil || code != http.StatusUnauthorized {
		t.Errorf("revoked certificate: %d %v", code, err)
	}

	// Require mode: Caddy refuses the daemon API without a certificate,
	// enrollment, issuance and the browser paths still reach the dashboard.
	apply(2, state.ClientAuthRequire)
	none := mtlsClient(host, nil)
	waitFor(t.Context(), t, "require mode", func() error {
		code, body, err := get(none, "/daemon/v1/state", nil)
		if err != nil || code != http.StatusUnauthorized || body != state.ClientCertRequiredBody {
			return fmt.Errorf("%d %q %w", code, body, err)
		}
		return nil
	})
	before := dash.refused.Load()
	for _, p := range []string{"/daemon/v1/STATE", "/daemon/v1/tasks/lease", "/daemon/v1//state"} {
		if code, _, _ := get(none, p, nil); code != http.StatusUnauthorized {
			t.Errorf("%s without a certificate: %d", p, code)
		}
	}
	if dash.refused.Load() != before {
		t.Error("requests without a certificate reached the dashboard in require mode")
	}
	if code, body, err := get(none, "/daemon/v1/enroll", nil); err != nil || code != 200 || body != "enroll reached the dashboard" {
		t.Errorf("enroll: %d %q %v", code, body, err)
	}
	if code, _, err := get(none, "/up", nil); err != nil || code != 200 {
		t.Errorf("browser path: %d %v", code, err)
	}

	// Rotation without downtime: launchroomd's own client and certificate
	// manager, a 6 s lifetime (renewal every ~4 s with a new key), requests
	// every 100 ms through keep-alive connections.
	dash.mu.Lock()
	dash.lifetime = 6 * time.Second
	dash.mu.Unlock()
	id, err := identity.Generate()
	if err != nil {
		t.Fatal(err)
	}
	signer, err := id.NewSigner(string(e.serverID))
	if err != nil {
		t.Fatal(err)
	}
	trusted := identity.KeySet{"cp-test": cpKey.Public().(ed25519.PublicKey)}
	var client atomic.Pointer[controlplane.Client]
	issuer := issuerFunc(func(ctx context.Context, r *state.ClientCertificateRequest) (*state.ClientCertificate, error) {
		return client.Load().IssueClientCertificate(ctx, r)
	})
	certs, err := clientcert.New(clientcert.Options{Dir: t.TempDir(), ServerID: e.serverID, Issuer: issuer,
		OnRotate: func() { client.Load().CloseIdleConnections() }})
	if err != nil {
		t.Fatal(err)
	}
	base, _ := url.Parse("https://" + host)
	tr := &http.Transport{Proxy: nil, DialContext: dialEdge, TLSClientConfig: &tls.Config{ServerName: host, InsecureSkipVerify: true, MinVersion: tls.VersionTLS12}} //nolint:gosec // internal CA
	c, err := controlplane.New(controlplane.Options{BaseURL: base, Signer: signer, Trusted: trusted, DaemonVersion: "0.0.0-test",
		Transport: tr, ClientCertificate: certs.GetClientCertificate})
	if err != nil {
		t.Fatal(err)
	}
	client.Store(c)
	// The first certificate needs no certificate (a new server).
	if err := certs.Renew(t.Context()); err != nil {
		t.Fatal(err)
	}
	ctx, cancel := context.WithCancel(t.Context())
	defer cancel()
	go certs.Run(ctx)
	fps := map[string]bool{}
	var failed, total int
	deadline := time.Now().Add(20 * time.Second)
	for time.Now().Before(deadline) {
		total++
		if _, err := c.FetchConfig(t.Context()); err != nil {
			failed++
			t.Logf("request failed: %v", err)
		}
		if fp, _, ok := certs.Current(); ok {
			fps[fp] = true
		}
		time.Sleep(100 * time.Millisecond)
	}
	if failed > 0 {
		t.Errorf("%d of %d requests failed during rotation", failed, total)
	}
	if len(fps) < 4 {
		t.Errorf("only %d certificates during 20 s (want a renewal every ~4 s)", len(fps))
	}
	dash.mu.Lock()
	seen := len(dash.seen)
	dash.mu.Unlock()
	if seen < 4 {
		t.Errorf("the dashboard saw %d client certificates", seen)
	}
}

type issuerFunc func(context.Context, *state.ClientCertificateRequest) (*state.ClientCertificate, error)

func (f issuerFunc) IssueClientCertificate(ctx context.Context, r *state.ClientCertificateRequest) (*state.ClientCertificate, error) {
	return f(ctx, r)
}
