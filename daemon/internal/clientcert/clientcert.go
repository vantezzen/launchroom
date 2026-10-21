// Package clientcert keeps launchroomd's client certificate for the
// dashboard (mTLS, D50, docs/03 §4.3): an ECDSA P-256 key the daemon
// generates itself, a short-lived certificate from the control plane's
// internal CA issued over the signed channel, renewed with a new key at
// the certificate's renewAfter. The TLS client picks the current pair per
// handshake (GetClientCertificate), so a rotation needs no restart and
// drops no connection. It is an additional layer: every request stays
// RFC 9421-signed.
package clientcert

import (
	"context"
	"crypto/ecdsa"
	"crypto/elliptic"
	"crypto/rand"
	"crypto/sha256"
	"crypto/tls"
	"crypto/x509"
	"encoding/base64"
	"encoding/hex"
	"encoding/pem"
	"errors"
	"fmt"
	"log/slog"
	"math/big"
	"net/http"
	"os"
	"path/filepath"
	"slices"
	"sync"
	"sync/atomic"
	"time"

	"github.com/vantezzen/launchroom/daemon/internal/atomicfile"
	"github.com/vantezzen/launchroom/daemon/internal/controlplane"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// File names in the keys directory (data/keys).
const (
	KeyFile  = "client-tls.key"
	CertFile = "client-tls.crt"
)

// Retry timings.
const (
	retryMin      = time.Minute
	retryMax      = time.Hour
	retryNotFound = 6 * time.Hour // the dashboard has no endpoint (older version)
	idleCheck     = time.Minute   // how often an inactive manager looks again
)

// Issuer asks the control plane for a certificate (controlplane.Client).
type Issuer interface {
	IssueClientCertificate(ctx context.Context, req *state.ClientCertificateRequest) (*state.ClientCertificate, error)
}

// Options configures a Manager.
type Options struct {
	// Dir is the keys directory (data/keys).
	Dir      string
	ServerID state.ServerID
	Issuer   Issuer
	// Active reports whether the daemon talks to the control plane over
	// https (configured or adopted URL); only then a certificate is needed.
	// Nil: always active.
	Active func() bool
	Log    *slog.Logger
	Now    func() time.Time
	// OnRotate is called after a new certificate is in use and again
	// halfway to the old one's expiry: kept-alive TLS connections still
	// carry the old certificate, so the client drops its idle ones.
	OnRotate func()
}

// pair is one usable key + certificate.
type pair struct {
	cert        tls.Certificate
	leaf        *x509.Certificate
	fingerprint string
	renewAt     time.Time
}

// Manager keeps the current client certificate.
type Manager struct {
	o    Options
	cur  atomic.Pointer[pair]
	kick chan struct{}

	mu      sync.Mutex
	retryAt time.Time
	backoff time.Duration
}

// New returns a Manager and loads a stored certificate when it is still
// valid for this server and matches the stored key (anything else is
// discarded and re-issued).
func New(o Options) (*Manager, error) {
	if o.Dir == "" || o.Issuer == nil {
		return nil, errors.New("clientcert: dir and issuer are required")
	}
	if err := o.ServerID.Validate(); err != nil {
		return nil, fmt.Errorf("clientcert: %w", err)
	}
	if o.Log == nil {
		o.Log = slog.New(slog.DiscardHandler)
	}
	if o.Now == nil {
		o.Now = time.Now
	}
	m := &Manager{o: o, kick: make(chan struct{}, 1), backoff: retryMin}
	if p, err := m.load(); err == nil {
		m.cur.Store(p)
		o.Log.Info("client certificate loaded", "fingerprint", p.fingerprint, "notAfter", p.leaf.NotAfter.UTC())
	} else if !errors.Is(err, os.ErrNotExist) {
		o.Log.Warn("stored client certificate discarded; asking for a new one", "err", err)
	}
	return m, nil
}

// GetClientCertificate is tls.Config.GetClientCertificate: the current
// certificate while it is valid, else none (the server decides).
func (m *Manager) GetClientCertificate(*tls.CertificateRequestInfo) (*tls.Certificate, error) {
	p := m.cur.Load()
	if p == nil || !m.o.Now().Before(p.leaf.NotAfter) {
		return &tls.Certificate{}, nil
	}
	c := p.cert
	return &c, nil
}

// Current returns the fingerprint (lowercase hex SHA-256 of the DER) and
// expiry of the current certificate.
func (m *Manager) Current() (fingerprint string, notAfter time.Time, ok bool) {
	p := m.cur.Load()
	if p == nil {
		return "", time.Time{}, false
	}
	return p.fingerprint, p.leaf.NotAfter, true
}

// Kick makes Run look again now (e.g. a new control-plane origin was adopted).
func (m *Manager) Kick() {
	select {
	case m.kick <- struct{}{}:
	default:
	}
}

// Run keeps the certificate fresh until ctx ends.
func (m *Manager) Run(ctx context.Context) {
	for ctx.Err() == nil {
		wait := m.nextWait()
		if wait > 0 {
			t := time.NewTimer(wait)
			select {
			case <-ctx.Done():
				t.Stop()
				return
			case <-t.C:
			case <-m.kick:
				t.Stop()
				m.mu.Lock()
				m.retryAt = time.Time{} // a kick retries at once
				m.mu.Unlock()
			}
			continue
		}
		if err := m.Renew(ctx); err != nil && ctx.Err() == nil {
			m.failed(err)
		}
	}
}

// nextWait is how long Run sleeps before the next attempt (0: now).
func (m *Manager) nextWait() time.Duration {
	now := m.o.Now()
	if m.o.Active != nil && !m.o.Active() {
		return idleCheck
	}
	m.mu.Lock()
	retryAt := m.retryAt
	m.mu.Unlock()
	due := now
	if p := m.cur.Load(); p != nil && now.Before(p.leaf.NotAfter) {
		due = p.renewAt
	}
	if retryAt.After(due) {
		due = retryAt
	}
	return due.Sub(now)
}

// failed schedules the retry after a failed issuance.
func (m *Manager) failed(err error) {
	m.mu.Lock()
	defer m.mu.Unlock()
	var api *controlplane.APIError
	wait := m.backoff
	switch {
	case errors.As(err, &api) && api.Status == http.StatusNotFound:
		wait = retryNotFound
		m.o.Log.Info("the dashboard does not issue client certificates (older version); asking again later", "in", wait.String())
	case errors.As(err, &api) && api.Status == http.StatusForbidden:
		m.o.Log.Warn("the dashboard refused a client certificate: this server's certificate expired while certificates are required; an owner can allow a new one in Settings → Security",
			"err", err, "retryIn", wait.String())
	default:
		m.o.Log.Warn("could not renew the client certificate; the current one stays in use", "err", err, "retryIn", wait.String())
	}
	m.retryAt = m.o.Now().Add(jitter(wait))
	m.backoff = min(m.backoff*2, retryMax)
}

// Renew asks for a new certificate for a new key and stores it.
func (m *Manager) Renew(ctx context.Context) error {
	key, err := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	if err != nil {
		return fmt.Errorf("clientcert: generate key: %w", err)
	}
	spki, err := x509.MarshalPKIXPublicKey(&key.PublicKey)
	if err != nil {
		return fmt.Errorf("clientcert: encode public key: %w", err)
	}
	req := &state.ClientCertificateRequest{
		TypeMeta:  state.NewTypeMeta(state.KindClientCertificateRequest),
		PublicKey: state.DERBase64(base64.StdEncoding.EncodeToString(spki)),
	}
	res, err := m.o.Issuer.IssueClientCertificate(ctx, req)
	if err != nil {
		return err
	}
	p, err := m.verify(res, key)
	if err != nil {
		return fmt.Errorf("clientcert: refused the issued certificate: %w", err)
	}
	if err := m.store(key, p.leaf.Raw); err != nil {
		return err
	}
	old := m.cur.Swap(p)
	m.mu.Lock()
	m.retryAt, m.backoff = time.Time{}, retryMin
	m.mu.Unlock()
	if m.o.OnRotate != nil {
		m.o.OnRotate()
		if old != nil {
			if d := old.leaf.NotAfter.Sub(m.o.Now()) / 2; d > 0 {
				time.AfterFunc(d, m.o.OnRotate)
			}
		}
	}
	m.o.Log.Info("client certificate renewed", "fingerprint", p.fingerprint, "notAfter", p.leaf.NotAfter.UTC(), "renewAt", p.renewAt.UTC())
	return nil
}

// verify checks an issued certificate: this server, this key, client auth,
// valid now and chaining to one of the returned CAs.
func (m *Manager) verify(res *state.ClientCertificate, key *ecdsa.PrivateKey) (*pair, error) {
	if res.ServerID != m.o.ServerID {
		return nil, fmt.Errorf("issued for %s, not this server", res.ServerID)
	}
	leaf, err := res.Certificate.Certificate()
	if err != nil {
		return nil, fmt.Errorf("certificate: %w", err)
	}
	roots := x509.NewCertPool()
	for i, c := range res.CACertificates {
		ca, err := c.Certificate()
		if err != nil {
			return nil, fmt.Errorf("caCertificates[%d]: %w", i, err)
		}
		roots.AddCert(ca)
	}
	if err := m.check(leaf, key, roots); err != nil {
		return nil, err
	}
	return m.pair(leaf, key, res.RenewAfter), nil
}

func (m *Manager) check(leaf *x509.Certificate, key *ecdsa.PrivateKey, roots *x509.CertPool) error {
	pub, ok := leaf.PublicKey.(*ecdsa.PublicKey)
	if !ok || !pub.Equal(&key.PublicKey) {
		return errors.New("the certificate is not for this server's key")
	}
	if !slices.Contains(leaf.ExtKeyUsage, x509.ExtKeyUsageClientAuth) {
		return errors.New("the certificate is not for client authentication")
	}
	if !hasServerURI(leaf, m.o.ServerID) {
		return errors.New("the certificate does not name this server")
	}
	if roots != nil {
		if _, err := leaf.Verify(x509.VerifyOptions{Roots: roots, CurrentTime: m.o.Now(), KeyUsages: []x509.ExtKeyUsage{x509.ExtKeyUsageClientAuth}}); err != nil {
			return fmt.Errorf("chain: %w", err)
		}
	} else if now := m.o.Now(); now.Before(leaf.NotBefore) || !now.Before(leaf.NotAfter) {
		return errors.New("the certificate is not valid now")
	}
	return nil
}

func hasServerURI(leaf *x509.Certificate, id state.ServerID) bool {
	want := "urn:launchroom:server:" + string(id)
	for _, u := range leaf.URIs {
		if u.String() == want {
			return true
		}
	}
	return false
}

func (m *Manager) pair(leaf *x509.Certificate, key *ecdsa.PrivateKey, renewAfter time.Time) *pair {
	if renewAfter.IsZero() || renewAfter.Before(leaf.NotBefore) || renewAfter.After(leaf.NotAfter) {
		// Two thirds of the lifetime (docs/03 §4.3).
		renewAfter = leaf.NotBefore.Add(leaf.NotAfter.Sub(leaf.NotBefore) * 2 / 3)
	}
	// ±10 % of the time until renewAfter, never past expiry.
	span := renewAfter.Sub(leaf.NotBefore)
	renewAt := renewAfter.Add(jitterDelta(span / 10))
	if !renewAt.Before(leaf.NotAfter) {
		renewAt = renewAfter
	}
	// A renewAfter already in the past (a backdated notBefore, clock skew)
	// would renew in a tight loop: wait half the remaining lifetime instead.
	if now := m.o.Now(); renewAt.Before(now) {
		renewAt = now.Add(leaf.NotAfter.Sub(now) / 2)
	}
	sum := sha256.Sum256(leaf.Raw)
	return &pair{
		cert:        tls.Certificate{Certificate: [][]byte{leaf.Raw}, PrivateKey: key, Leaf: leaf},
		leaf:        leaf,
		fingerprint: hex.EncodeToString(sum[:]),
		renewAt:     renewAt,
	}
}

// store writes the key (PKCS#8 PEM, 0600) and the certificate (PEM), each
// replaced atomically. A crash in between leaves a mismatch that load
// discards (a new certificate is asked for).
func (m *Manager) store(key *ecdsa.PrivateKey, der []byte) error {
	if err := os.MkdirAll(m.o.Dir, 0o700); err != nil {
		return fmt.Errorf("clientcert: %w", err)
	}
	k, err := x509.MarshalPKCS8PrivateKey(key)
	if err != nil {
		return fmt.Errorf("clientcert: encode key: %w", err)
	}
	keyPEM := pem.EncodeToMemory(&pem.Block{Type: "PRIVATE KEY", Bytes: k})
	clear(k)
	defer clear(keyPEM)
	if err := atomicfile.Write(filepath.Join(m.o.Dir, KeyFile), keyPEM, 0o600); err != nil {
		return fmt.Errorf("clientcert: write key: %w", err)
	}
	if err := atomicfile.Write(filepath.Join(m.o.Dir, CertFile), pem.EncodeToMemory(&pem.Block{Type: "CERTIFICATE", Bytes: der}), 0o600); err != nil {
		return fmt.Errorf("clientcert: write certificate: %w", err)
	}
	return nil
}

// load reads the stored pair and checks it (this server, the key, client
// auth, valid now). The chain is checked by the dashboard's edge.
func (m *Manager) load() (*pair, error) {
	keyPEM, err := os.ReadFile(filepath.Join(m.o.Dir, KeyFile)) //nolint:gosec // fixed name in the daemon's own keys dir
	if err != nil {
		return nil, err
	}
	defer clear(keyPEM)
	certPEM, err := os.ReadFile(filepath.Join(m.o.Dir, CertFile)) //nolint:gosec // fixed name in the daemon's own keys dir
	if err != nil {
		return nil, err
	}
	kb, _ := pem.Decode(keyPEM)
	cb, _ := pem.Decode(certPEM)
	if kb == nil || cb == nil || kb.Type != "PRIVATE KEY" || cb.Type != "CERTIFICATE" {
		return nil, errors.New("not PEM")
	}
	k, err := x509.ParsePKCS8PrivateKey(kb.Bytes)
	if err != nil {
		return nil, fmt.Errorf("key: %w", err)
	}
	key, ok := k.(*ecdsa.PrivateKey)
	if !ok || key.Curve != elliptic.P256() {
		return nil, errors.New("key is not ECDSA P-256")
	}
	leaf, err := x509.ParseCertificate(cb.Bytes)
	if err != nil {
		return nil, fmt.Errorf("certificate: %w", err)
	}
	if err := m.check(leaf, key, nil); err != nil {
		return nil, err
	}
	return m.pair(leaf, key, time.Time{}), nil
}

// jitter spreads d by ±10 %.
func jitter(d time.Duration) time.Duration { return d + jitterDelta(d/10) }

// jitterDelta is a uniformly random duration in [-max, max].
func jitterDelta(maxDelta time.Duration) time.Duration {
	if maxDelta <= 0 {
		return 0
	}
	n, err := rand.Int(rand.Reader, big.NewInt(int64(2*maxDelta)+1))
	if err != nil {
		return 0
	}
	return time.Duration(n.Int64()) - maxDelta
}
