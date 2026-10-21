package caddy

import (
	"bytes"
	"context"
	"crypto/ecdsa"
	"crypto/elliptic"
	"crypto/rand"
	"crypto/x509"
	"crypto/x509/pkix"
	"encoding/json"
	"encoding/pem"
	"errors"
	"math/big"
	"net/netip"
	"os"
	"path/filepath"
	"slices"
	"strings"
	"testing"
	"time"

	"github.com/vantezzen/launchroom/daemon/internal/docker/dockerfake"
	"github.com/vantezzen/launchroom/daemon/internal/ingress/certstore"
	"github.com/vantezzen/launchroom/daemon/internal/policy"
	"github.com/vantezzen/launchroom/daemon/internal/secret"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

func multiEdgeRoutes() []state.RouteSpec {
	return []state.RouteSpec{
		{
			ID: "rt_01J9Z8X7W6V5T4S3R2Q1P0N9R1", Hosts: []state.Hostname{"web-production.shop.apps.example.com", "web-pr-4.shop.apps.example.com"}, TLS: state.TLSWildcard,
			Upstreams: []state.RouteUpstream{{Workload: wlA, Port: 3000}, {Address: "100.64.0.7:31001"}}, LoadBalancing: state.LBPreferLocal,
		},
		{
			ID: "rt_01J9Z8X7W6V5T4S3R2Q1P0N9R2", Hosts: []state.Hostname{"api-production.blog.apps.example.com"}, TLS: state.TLSWildcard,
			Upstreams: []state.RouteUpstream{{Address: "100.64.0.8:31002"}},
		},
		{
			// Outside the provider's domain: per-host HTTP-01.
			ID: "rt_01J9Z8X7W6V5T4S3R2Q1P0N9R3", Hosts: []state.Hostname{"web.other.example.net"}, TLS: state.TLSWildcard,
			Upstreams: []state.RouteUpstream{{Workload: wlA, Port: 3000}},
		},
		{
			ID: "rt_01J9Z8X7W6V5T4S3R2Q1P0N9R4", Hosts: []state.Hostname{"shop.example.org"}, TLS: state.TLSOnDemand,
			Upstreams: []state.RouteUpstream{{Workload: wlA, Port: 3000}},
			Headers:   map[state.HeaderName]string{"Strict-Transport-Security": "max-age=31536000"},
		},
	}
}

var dnsSpec = &state.DNSChallengeSpec{Provider: state.DNSCloudflare, Domains: []state.Hostname{"apps.example.com"}}

func TestRenderTLSGolden(t *testing.T) {
	routes := ResolveRoutes(multiEdgeRoutes(), resolver(map[state.WorkloadID]Endpoint{
		wlA: {Addr: netip.MustParseAddr("10.231.4.5"), Serving: true, Ready: true},
	}))
	shared, err := RenderTLS(routes, TLS{DNS: dnsSpec, Storage: &Storage{Address: "100.64.0.1:6379", KeyPrefix: "lr-caddy", HasUsername: true}}, renderOpts)
	if err != nil {
		t.Fatal(err)
	}
	golden(t, "multi-edge", shared)
	for _, want := range []string{
		`"automate": [`, `"*.blog.apps.example.com"`, `"*.shop.apps.example.com"`,
		`"api_token": "{file./data/launchroom/secrets/dns-token}"`, `"skip_connection_check": true`,
	} {
		if !bytes.Contains(shared, []byte(want)) {
			t.Errorf("config lacks %s", want)
		}
	}

	degraded, err := RenderTLS(routes, TLS{DNS: dnsSpec, Mirror: []MirrorFile{
		{Certificate: MirrorDir + "/wildcard_.shop.apps.example.com.crt", Key: MirrorDir + "/wildcard_.shop.apps.example.com.key", Names: []string{"*.shop.apps.example.com"}},
	}}, renderOpts)
	if err != nil {
		t.Fatal(err)
	}
	golden(t, "degraded", degraded)
	if bytes.Contains(degraded, []byte(`"storage"`)) || bytes.Contains(degraded, []byte(`"*.shop.apps.example.com"`)) {
		t.Error("degraded config must use the local file storage and not re-issue mirrored wildcards")
	}

	// No DNS provider: wildcard routes fall back to per-host certificates.
	noDNS, err := RenderTLS(routes, TLS{}, renderOpts)
	if err != nil {
		t.Fatal(err)
	}
	if bytes.Contains(noDNS, []byte(`"automate"`)) || !bytes.Contains(noDNS, []byte(`"web-production.shop.apps.example.com"`)) {
		t.Errorf("fallback config wrong:\n%s", noDNS)
	}

	pebble := renderOpts
	pebble.ACMEDirectory = "https://pebble:14000/dir"
	pebble.ACMERootFiles = []string{"/data/launchroom/pebble.pem"}
	acme, err := RenderTLS(routes, TLS{DNS: dnsSpec}, pebble)
	if err != nil {
		t.Fatal(err)
	}
	golden(t, "acme-ca", acme)
}

func TestResolvePreferLocal(t *testing.T) {
	ip := netip.MustParseAddr
	specs := multiEdgeRoutes()[:1]
	routes := ResolveRoutes(specs, resolver(map[state.WorkloadID]Endpoint{wlA: {Addr: ip("10.231.4.5"), Serving: true, Ready: true}}))
	if !slices.Equal(routes[0].Dial, []string{"10.231.4.5:3000"}) {
		t.Fatalf("prefer local with a ready local replica: %v", routes[0].Dial)
	}
	routes = ResolveRoutes(specs, resolver(map[state.WorkloadID]Endpoint{wlA: {Addr: ip("10.231.4.5"), Serving: true, Ready: false}}))
	if !slices.Equal(routes[0].Dial, []string{"100.64.0.7:31001"}) {
		t.Fatalf("prefer local without a ready local replica: %v", routes[0].Dial)
	}
	specs[0].LoadBalancing = ""
	routes = ResolveRoutes(specs, resolver(map[state.WorkloadID]Endpoint{wlA: {Addr: ip("10.231.4.5"), Serving: true, Ready: true}}))
	if !slices.Equal(routes[0].Dial, []string{"10.231.4.5:3000", "100.64.0.7:31001"}) {
		t.Fatalf("round robin: %v", routes[0].Dial)
	}
}

func TestParseCertLog(t *testing.T) {
	cases := []struct {
		line        string
		id, err     string
		ok, matched bool
	}{
		{`{"level":"info","ts":1,"logger":"tls.obtain","msg":"certificate obtained successfully","identifier":"Shop.Example.org","issuer":"acme"}`, "shop.example.org", "", true, true},
		{`{"level":"info","logger":"tls.renew","msg":"certificate renewed successfully","identifier":"*.shop.apps.example.com"}`, "*.shop.apps.example.com", "", true, true},
		{`{"level":"error","logger":"tls.obtain","msg":"could not get certificate from issuer","identifier":"a.example.com","error":"HTTP 400 urn:ietf:params:acme:error:dns - no valid A records\u0007"}`, "a.example.com", "HTTP 400 urn:ietf:params:acme:error:dns - no valid A records", false, true},
		{`{"level":"info","logger":"tls.obtain","msg":"acquiring lock","identifier":"a.example.com"}`, "", "", false, false},
		{`{"level":"info","logger":"http.log","msg":"could not get certificate from issuer","identifier":"a.example.com"}`, "", "", false, false},
		{`not json "identifier"`, "", "", false, false},
	}
	for _, c := range cases {
		id, ok, msg, matched := parseCertLog(c.line)
		if id != c.id || ok != c.ok || strings.TrimSpace(msg) != c.err || matched != c.matched {
			t.Errorf("%s: got %q %v %q %v", c.line, id, ok, msg, matched)
		}
	}
}

func testLeafCert(t *testing.T, nb, na time.Time, names ...string) ([]byte, []byte) {
	t.Helper()
	key, err := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	if err != nil {
		t.Fatal(err)
	}
	tmpl := &x509.Certificate{SerialNumber: big.NewInt(1), Subject: pkix.Name{CommonName: names[0]}, DNSNames: names, NotBefore: nb, NotAfter: na}
	parent := &x509.Certificate{SerialNumber: big.NewInt(2), Subject: pkix.Name{Organization: []string{"Let's Encrypt"}, CommonName: "R12"}}
	der, err := x509.CreateCertificate(rand.Reader, tmpl, parent, &key.PublicKey, key)
	if err != nil {
		t.Fatal(err)
	}
	kb, _ := x509.MarshalECPrivateKey(key)
	return pem.EncodeToMemory(&pem.Block{Type: "CERTIFICATE", Bytes: der}), pem.EncodeToMemory(&pem.Block{Type: "EC PRIVATE KEY", Bytes: kb})
}

func certsOf(t *testing.T, pems ...[]byte) []certstore.Cert {
	t.Helper()
	var out []certstore.Cert
	for i, p := range pems {
		b, _ := pem.Decode(p)
		l, err := x509.ParseCertificate(b.Bytes)
		if err != nil {
			t.Fatal(err)
		}
		out = append(out, certstore.Cert{Name: string(rune('a' + i)), CertPEM: p, Leaf: l})
	}
	return out
}

func TestCertificateStatuses(t *testing.T) {
	now := time.Date(2026, 10, 18, 12, 0, 0, 0, time.UTC)
	wild, _ := testLeafCert(t, now.Add(-24*time.Hour), now.Add(44*24*time.Hour), "*.shop.apps.example.com")
	custom, _ := testLeafCert(t, now.Add(-90*24*time.Hour), now.Add(-time.Hour), "shop.example.org")
	routes := ResolveRoutes(multiEdgeRoutes(), resolver(nil))
	events := map[string]certEvent{
		"*.blog.apps.example.com": {lastErr: "Invalid access token (HTTP 403)", lastErrAt: now.Add(-time.Minute)},
		"*.shop.apps.example.com": {lastErr: "old error", lastErrAt: now.Add(-48 * time.Hour)},
		"shop.example.org":        {lastErr: "will be renewed", lastErrAt: now.Add(-10 * time.Minute), lastOKAt: now.Add(-20 * time.Minute)},
	}
	got := CertificateStatuses(routes, TLS{DNS: dnsSpec}, certstore.NewIndex(certsOf(t, wild, custom)), events, now)
	by := map[state.Hostname]state.CertificateStatus{}
	for _, s := range got {
		by[s.Host] = s
	}
	if len(got) != 5 {
		t.Fatalf("statuses = %+v", got)
	}
	w := by["web-pr-4.shop.apps.example.com"]
	if w.State != state.CertIssued || !w.Wildcard || w.Issuer != "Let's Encrypt R12" || w.NotAfter == nil || w.LastError != "" {
		t.Errorf("wildcard host: %+v", w)
	}
	b := by["api-production.blog.apps.example.com"]
	if b.State != state.CertFailed || b.LastError != "Invalid access token (HTTP 403)" || b.LastErrorAt == nil {
		t.Errorf("failing wildcard: %+v", b)
	}
	if p := by["web.other.example.net"]; p.State != state.CertPending || p.Wildcard {
		t.Errorf("pending host: %+v", p)
	}
	c := by["shop.example.org"]
	if c.State != state.CertFailed || c.LastError != "will be renewed" {
		t.Errorf("expired custom domain: %+v", c)
	}
	for _, s := range got {
		rep := state.IngressStatus{State: state.IngressApplied, Certificates: []state.CertificateStatus{s}}
		r := state.Report{TypeMeta: state.TypeMeta{APIVersion: state.APIVersion, Kind: state.KindReport}, ServerID: srvID, ObservedAt: now, Seq: 1, Workloads: []state.WorkloadStatus{}, Ingress: &rep}
		if err := r.Validate(); err != nil {
			t.Errorf("%s: %v", s.Host, err)
		}
	}
}

// managerWithSpec builds a Manager whose NodeState asks for spec; secrets
// "open" from a fake.
func managerWithSpec(t *testing.T, spec *state.IngressSpec, pol policy.Config) (*Manager, *fakeAdmin, string) {
	t.Helper()
	dir := t.TempDir()
	admin := &fakeAdmin{}
	m := NewManager(ManagerOptions{
		ServerID: srvID, Docker: dockerfake.New(srvID), Admin: admin, Policy: pol, Render: renderOpts, CacheDir: dir,
		Spec: func() *state.IngressSpec { return spec },
		OpenSecret: func(_ state.Sealed, p state.IngressSecretPurpose) (map[string]secret.Secret, error) {
			switch p {
			case state.IngressSecretDNS:
				return map[string]secret.Secret{"token": "cf-token-SECRET"}, nil
			case state.IngressSecretCertStorage:
				return map[string]secret.Secret{"password": "pw-SECRET", "encryptionKey": secret.Secret(strings.Repeat("k", 32))}, nil
			}
			return nil, errors.New("unknown purpose")
		},
	})
	return m, admin, dir
}

func TestManagerDegradedStorageServesMirror(t *testing.T) {
	now := time.Now()
	crt, key := testLeafCert(t, now.Add(-time.Hour), now.Add(30*24*time.Hour), "*.shop.apps.example.com")
	spec := &state.IngressSpec{
		DNS: &state.DNSChallengeSpec{Provider: state.DNSHetzner, Domains: []state.Hostname{"apps.example.com"}},
		// Nothing listens there: the storage is unreachable.
		Storage: &state.CertStorageSpec{Address: "127.0.0.1:1", KeyPrefix: "lr-caddy"},
	}
	pol := policy.DefaultConfig()
	pol.CertStorageAddrs = []netip.AddrPort{netip.MustParseAddrPort("127.0.0.1:1")}
	m, admin, dir := managerWithSpec(t, spec, pol)
	if err := (certstore.Mirror{Dir: filepath.Join(dir, "certs")}).Replace([]certstore.Cert{{Name: "wildcard_.shop.apps.example.com", CertPEM: crt, KeyPEM: key}}); err != nil {
		t.Fatal(err)
	}
	routes := ResolveRoutes(multiEdgeRoutes()[:2], resolver(nil)) // *.shop is mirrored, *.blog is not
	st := m.Sync(context.Background(), state.SystemContainer{Enabled: true, Image: caddyImage(t)}, routes)
	if st == nil || st.State != state.IngressApplied || st.Storage == nil || st.Storage.State != state.StorageDegraded || st.Storage.Mirrored != 1 {
		t.Fatalf("status = %+v / %+v", st, st.Storage)
	}
	cfg := admin.last()
	if !bytes.Contains(cfg, []byte(`"load_files"`)) || bytes.Contains(cfg, []byte(`"storage"`)) || !bytes.Contains(cfg, []byte(`"name": "hetzner"`)) ||
		bytes.Contains(cfg, []byte(`"*.shop.apps.example.com"`)) {
		t.Fatalf("degraded config:\n%s", cfg)
	}
	for _, s := range []string{"cf-token-SECRET", "pw-SECRET", "BEGIN EC PRIVATE KEY"} {
		if bytes.Contains(cfg, []byte(s)) {
			t.Fatalf("config contains secret %q", s)
		}
	}
	cached, err := os.ReadFile(filepath.Join(dir, CacheFile))
	if err != nil || bytes.Contains(cached, []byte("SECRET")) {
		t.Fatalf("cached config: %v", err)
	}
	var parsed map[string]any
	if err := json.Unmarshal(cfg, &parsed); err != nil {
		t.Fatal(err)
	}
}

func TestManagerRefusesStorageOutsidePolicy(t *testing.T) {
	spec := &state.IngressSpec{Storage: &state.CertStorageSpec{Address: "10.0.0.5:6379", KeyPrefix: "lr-caddy"}}
	m, admin, _ := managerWithSpec(t, spec, policy.DefaultConfig())
	st := m.Sync(context.Background(), state.SystemContainer{Enabled: true, Image: caddyImage(t)}, nil)
	if st.Storage == nil || st.Storage.State != state.StorageLocal || !strings.Contains(st.Storage.Message, "cert-storage") {
		t.Fatalf("storage = %+v", st.Storage)
	}
	if bytes.Contains(admin.last(), []byte(`"storage"`)) {
		t.Fatal("refused storage rendered")
	}
}

func caddyImage(t *testing.T) state.ImageRef {
	t.Helper()
	return state.ImageRef("docker.io/library/caddy@" + policy.DefaultSystemImageDigests[policy.SystemCaddy][0])
}

func (f *fakeAdmin) last() []byte {
	f.mu.Lock()
	defer f.mu.Unlock()
	if len(f.loads) == 0 {
		return nil
	}
	return f.loads[len(f.loads)-1]
}
