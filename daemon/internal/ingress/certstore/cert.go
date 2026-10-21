// Package certstore reads the certificates Caddy keeps in its storage
// (shared Redis-protocol storage or its local file storage), mirrors them to
// launchroomd's data dir, and derives the per-host certificate status that
// edges report (docs/05 §5).
package certstore

import (
	"crypto/x509"
	"encoding/pem"
	"errors"
	"path"
	"strings"
	"time"
)

// Cert is one certificate from Caddy's storage (certmagic key layout
// "certificates/<issuer key>/<name>/<name>.crt|.key").
type Cert struct {
	// Name is certmagic's storage name ("example.com", "wildcard_.apps.example.com").
	Name string
	// IssuerKey is the storage directory of the issuer (e.g. "acme-v02.api.letsencrypt.org-directory").
	IssuerKey string
	CertPEM   []byte
	// KeyPEM is the private key; only read when mirroring.
	KeyPEM []byte
	Leaf   *x509.Certificate
}

// parseKey splits "certificates/<issuer>/<name>/<name>.<ext>" (after the
// storage prefix); ok=false for anything else.
func parseKey(key string) (issuer, name, ext string, ok bool) {
	parts := strings.Split(key, "/")
	if len(parts) != 4 || parts[0] != "certificates" {
		return "", "", "", false
	}
	file := parts[3]
	ext = path.Ext(file)
	if strings.TrimSuffix(file, ext) != parts[2] || (ext != ".crt" && ext != ".key") {
		return "", "", "", false
	}
	if !safeName(parts[1]) || !safeName(parts[2]) {
		return "", "", "", false
	}
	return parts[1], parts[2], ext, true
}

// safeName accepts certmagic storage names (lowercase DNS names, "wildcard_",
// issuer keys such as "acme-v02.api.letsencrypt.org-directory"); it keeps
// path tricks out of the mirror dir.
func safeName(s string) bool {
	if s == "" || len(s) > 255 || s == "." || s == ".." {
		return false
	}
	for _, r := range s {
		if (r < 'a' || r > 'z') && (r < '0' || r > '9') && r != '.' && r != '-' && r != '_' {
			return false
		}
	}
	return true
}

// leaf parses the first certificate of a PEM chain.
func leaf(chain []byte) (*x509.Certificate, error) {
	for {
		var b *pem.Block
		b, chain = pem.Decode(chain)
		if b == nil {
			return nil, errors.New("certstore: no certificate in PEM")
		}
		if b.Type == "CERTIFICATE" {
			return x509.ParseCertificate(b.Bytes)
		}
	}
}

// assemble pairs .crt and .key files into Certs (keys are optional).
func assemble(files map[[3]string][]byte) []Cert {
	var out []Cert
	for k, crt := range files {
		if k[2] != ".crt" {
			continue
		}
		l, err := leaf(crt)
		if err != nil {
			continue
		}
		out = append(out, Cert{Name: k[1], IssuerKey: k[0], CertPEM: crt, KeyPEM: files[[3]string{k[0], k[1], ".key"}], Leaf: l})
	}
	return out
}

// Index finds the certificate serving a host: the unexpired one with the
// latest expiry among those whose names cover it (exact or wildcard).
type Index struct {
	byName map[string][]*Cert
}

// NewIndex indexes certificates by every DNS name they carry.
func NewIndex(certs []Cert) *Index {
	ix := &Index{byName: map[string][]*Cert{}}
	for i := range certs {
		c := &certs[i]
		for _, n := range c.Leaf.DNSNames {
			ix.byName[strings.ToLower(n)] = append(ix.byName[strings.ToLower(n)], c)
		}
	}
	return ix
}

// Lookup returns the best certificate for host at now (nil: none) and
// whether it is a wildcard. An expired certificate is returned only when no
// valid one exists.
func (ix *Index) Lookup(host string, now time.Time) (*Cert, bool) {
	var cands []*Cert
	exact := ix.byName[host]
	cands = append(cands, exact...)
	wildcardFrom := len(cands)
	if dot := strings.IndexByte(host, '.'); dot > 0 {
		cands = append(cands, ix.byName["*"+host[dot:]]...)
	}
	better := func(a, b *Cert) bool { // a is better than b
		av, bv := now.Before(a.Leaf.NotAfter), now.Before(b.Leaf.NotAfter)
		if av != bv {
			return av
		}
		return a.Leaf.NotAfter.After(b.Leaf.NotAfter)
	}
	var best *Cert
	bestWildcard := false
	for i, c := range cands {
		if best == nil || better(c, best) {
			best, bestWildcard = c, i >= wildcardFrom
		}
	}
	return best, bestWildcard
}

// Issuer describes a certificate's issuer for humans ("Let's Encrypt R12").
func Issuer(c *x509.Certificate) string {
	var parts []string
	if len(c.Issuer.Organization) > 0 {
		parts = append(parts, c.Issuer.Organization[0])
	}
	if c.Issuer.CommonName != "" && (len(parts) == 0 || !strings.Contains(c.Issuer.CommonName, parts[0])) {
		parts = append(parts, c.Issuer.CommonName)
	}
	s := strings.Join(parts, " ")
	if len(s) > 256 {
		s = s[:256]
	}
	return s
}
