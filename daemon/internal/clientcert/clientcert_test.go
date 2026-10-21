package clientcert

import (
	"context"
	"crypto/ecdsa"
	"crypto/elliptic"
	"crypto/rand"
	"crypto/x509"
	"crypto/x509/pkix"
	"encoding/base64"
	"errors"
	"math/big"
	"net/url"
	"os"
	"path/filepath"
	"sync"
	"testing"
	"time"

	"github.com/vantezzen/launchroom/daemon/internal/controlplane"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

const srvID = state.ServerID("srv_01J9Z8X7W6V5T4S3R2Q1P0N9MK")

// testCA issues client certificates like the dashboard (SIGNING.md §6).
type testCA struct {
	key  *ecdsa.PrivateKey
	cert *x509.Certificate

	mu       sync.Mutex
	issued   int
	lifetime time.Duration
	// tamper changes the issued template (negative tests).
	tamper func(tpl *x509.Certificate, res *state.ClientCertificate)
	signer *testCA // another CA signs (foreign chain)
	err    error
}

func newTestCA(t *testing.T) *testCA {
	t.Helper()
	key, err := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	if err != nil {
		t.Fatal(err)
	}
	tpl := &x509.Certificate{SerialNumber: big.NewInt(1), Subject: pkix.Name{CommonName: "launchroom server CA"},
		NotBefore: time.Now().Add(-time.Hour), NotAfter: time.Now().Add(24 * time.Hour), IsCA: true, BasicConstraintsValid: true,
		KeyUsage: x509.KeyUsageCertSign}
	der, err := x509.CreateCertificate(rand.Reader, tpl, tpl, &key.PublicKey, key)
	if err != nil {
		t.Fatal(err)
	}
	cert, _ := x509.ParseCertificate(der)
	return &testCA{key: key, cert: cert, lifetime: 7 * 24 * time.Hour}
}

func (c *testCA) der() state.DERBase64 {
	return state.DERBase64(base64.StdEncoding.EncodeToString(c.cert.Raw))
}

func (c *testCA) IssueClientCertificate(_ context.Context, req *state.ClientCertificateRequest) (*state.ClientCertificate, error) {
	c.mu.Lock()
	defer c.mu.Unlock()
	if c.err != nil {
		return nil, c.err
	}
	pub, err := req.PublicKey.ParseP256PublicKey()
	if err != nil {
		return nil, err
	}
	c.issued++
	u, _ := url.Parse("urn:launchroom:server:" + string(srvID))
	now := time.Now()
	tpl := &x509.Certificate{SerialNumber: big.NewInt(int64(c.issued) + 100), Subject: pkix.Name{CommonName: string(srvID)},
		URIs: []*url.URL{u}, NotBefore: now.Add(-5 * time.Minute), NotAfter: now.Add(c.lifetime),
		KeyUsage: x509.KeyUsageDigitalSignature, ExtKeyUsage: []x509.ExtKeyUsage{x509.ExtKeyUsageClientAuth}, BasicConstraintsValid: true}
	res := &state.ClientCertificate{TypeMeta: state.NewTypeMeta(state.KindClientCertificate), ServerID: srvID,
		CACertificates: []state.DERBase64{c.der()}, NotBefore: tpl.NotBefore, NotAfter: tpl.NotAfter,
		RenewAfter: now.Add(c.lifetime * 2 / 3)}
	var key any = pub
	if c.tamper != nil {
		c.tamper(tpl, res)
		if k, ok := tpl.PublicKey.(*ecdsa.PublicKey); ok {
			key = k
		}
	}
	signer := c
	if c.signer != nil {
		signer = c.signer
	}
	der, err := x509.CreateCertificate(rand.Reader, tpl, signer.cert, key, signer.key)
	if err != nil {
		return nil, err
	}
	res.Certificate = state.DERBase64(base64.StdEncoding.EncodeToString(der))
	return res, nil
}

func newManager(t *testing.T, ca *testCA, dir string) *Manager {
	t.Helper()
	m, err := New(Options{Dir: dir, ServerID: srvID, Issuer: ca})
	if err != nil {
		t.Fatal(err)
	}
	return m
}

func TestRenewStoresAndReloads(t *testing.T) {
	ca := newTestCA(t)
	dir := t.TempDir()
	m := newManager(t, ca, dir)
	if c, _ := m.GetClientCertificate(nil); len(c.Certificate) != 0 {
		t.Fatal("certificate before issuance")
	}
	if err := m.Renew(t.Context()); err != nil {
		t.Fatal(err)
	}
	fp, notAfter, ok := m.Current()
	if !ok || len(fp) != 64 || notAfter.Before(time.Now()) {
		t.Fatalf("current = %q %v %v", fp, notAfter, ok)
	}
	c, _ := m.GetClientCertificate(nil)
	if len(c.Certificate) != 1 || c.PrivateKey == nil {
		t.Fatal("no certificate presented")
	}
	for _, f := range []string{KeyFile, CertFile} {
		st, err := os.Stat(filepath.Join(dir, f))
		if err != nil || st.Mode().Perm() != 0o600 {
			t.Errorf("%s: %v %v", f, err, st)
		}
	}
	again := newManager(t, ca, dir)
	if fp2, _, ok := again.Current(); !ok || fp2 != fp {
		t.Errorf("reloaded %q, want %q", fp2, fp)
	}

	// A new key with every renewal.
	if err := m.Renew(t.Context()); err != nil {
		t.Fatal(err)
	}
	c2, _ := m.GetClientCertificate(nil)
	if c2.PrivateKey.(*ecdsa.PrivateKey).Equal(c.PrivateKey) {
		t.Error("renewal reused the key")
	}
}

func TestRenewRefusesBadCertificates(t *testing.T) {
	other := newTestCA(t)
	cases := map[string]func(ca *testCA){
		"other server": func(ca *testCA) {
			ca.tamper = func(_ *x509.Certificate, r *state.ClientCertificate) { r.ServerID = "srv_01J9Z8X7W6V5T4S3R2Q1P0N9MZ" }
		},
		"other key": func(ca *testCA) {
			k, _ := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
			ca.tamper = func(tpl *x509.Certificate, _ *state.ClientCertificate) { tpl.PublicKey = &k.PublicKey }
		},
		"server auth only": func(ca *testCA) {
			ca.tamper = func(tpl *x509.Certificate, _ *state.ClientCertificate) {
				tpl.ExtKeyUsage = []x509.ExtKeyUsage{x509.ExtKeyUsageServerAuth}
			}
		},
		"no server uri": func(ca *testCA) {
			ca.tamper = func(tpl *x509.Certificate, _ *state.ClientCertificate) { tpl.URIs = nil }
		},
		"foreign chain": func(ca *testCA) { ca.signer = other },
		"expired": func(ca *testCA) {
			ca.tamper = func(tpl *x509.Certificate, r *state.ClientCertificate) {
				tpl.NotBefore, tpl.NotAfter = time.Now().Add(-2*time.Hour), time.Now().Add(-time.Hour)
				r.NotBefore, r.NotAfter, r.RenewAfter = tpl.NotBefore, tpl.NotAfter, tpl.NotAfter
			}
		},
	}
	for name, setup := range cases {
		t.Run(name, func(t *testing.T) {
			ca := newTestCA(t)
			setup(ca)
			dir := t.TempDir()
			m := newManager(t, ca, dir)
			if err := m.Renew(t.Context()); err == nil {
				t.Fatal("accepted")
			}
			if _, _, ok := m.Current(); ok {
				t.Error("refused certificate in use")
			}
			if _, err := os.Stat(filepath.Join(dir, CertFile)); !errors.Is(err, os.ErrNotExist) {
				t.Error("refused certificate stored")
			}
		})
	}
}

func TestFailedRenewalKeepsCurrentAndBacksOff(t *testing.T) {
	ca := newTestCA(t)
	m := newManager(t, ca, t.TempDir())
	if err := m.Renew(t.Context()); err != nil {
		t.Fatal(err)
	}
	fp, _, _ := m.Current()
	ca.err = &controlplane.APIError{Status: 503}
	err := m.Renew(t.Context())
	if err == nil {
		t.Fatal("renewal succeeded")
	}
	m.failed(err)
	if got, _, _ := m.Current(); got != fp {
		t.Error("failed renewal replaced the certificate")
	}
	m.mu.Lock()
	first := m.retryAt
	m.mu.Unlock()
	if d := time.Until(first); d < 50*time.Second || d > 70*time.Second {
		t.Errorf("first retry in %v, want ~1 min", d)
	}
	m.failed(&controlplane.APIError{Status: 404})
	m.mu.Lock()
	notFound := m.retryAt
	m.mu.Unlock()
	if d := time.Until(notFound); d < 5*time.Hour {
		t.Errorf("404 retry in %v, want ~6 h", d)
	}
}

func TestStoredCertificateDiscardedWhenMismatched(t *testing.T) {
	ca := newTestCA(t)
	dir := t.TempDir()
	m := newManager(t, ca, dir)
	if err := m.Renew(t.Context()); err != nil {
		t.Fatal(err)
	}
	// Another key next to the certificate (a crash between the two writes).
	other := newManager(t, ca, t.TempDir())
	if err := other.Renew(t.Context()); err != nil {
		t.Fatal(err)
	}
	key, err := os.ReadFile(filepath.Join(other.o.Dir, KeyFile))
	if err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(filepath.Join(dir, KeyFile), key, 0o600); err != nil {
		t.Fatal(err)
	}
	if _, _, ok := newManager(t, ca, dir).Current(); ok {
		t.Error("mismatched pair loaded")
	}
}

func TestRenewsAtRenewAfter(t *testing.T) {
	ca := newTestCA(t)
	ca.lifetime = 3 * time.Second
	m := newManager(t, ca, t.TempDir())
	ctx, cancel := context.WithTimeout(t.Context(), 20*time.Second)
	done := make(chan struct{})
	go func() { m.Run(ctx); close(done) }()
	// Stop Run (and its file writes) before the temp dir is removed.
	defer func() { cancel(); <-done }()
	seen := map[string]bool{}
	for len(seen) < 3 && ctx.Err() == nil {
		if fp, _, ok := m.Current(); ok {
			seen[fp] = true
		}
		time.Sleep(50 * time.Millisecond)
	}
	if len(seen) < 3 {
		t.Fatalf("saw %d certificates", len(seen))
	}
}

func TestInactiveManagerDoesNotAsk(t *testing.T) {
	ca := newTestCA(t)
	m, err := New(Options{Dir: t.TempDir(), ServerID: srvID, Issuer: ca, Active: func() bool { return false }})
	if err != nil {
		t.Fatal(err)
	}
	if w := m.nextWait(); w != idleCheck {
		t.Errorf("wait %v", w)
	}
}
