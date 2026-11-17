package doctor

import (
	"context"
	"crypto/ecdsa"
	"crypto/elliptic"
	"crypto/rand"
	"crypto/tls"
	"crypto/x509"
	"crypto/x509/pkix"
	"math/big"
	"net"
	"strings"
	"testing"
	"time"
)

// tlsListener serves one certificate for dash.localhost, valid from
// notBefore to notAfter, on a loopback port.
func tlsListener(t *testing.T, notBefore, notAfter time.Time) string {
	t.Helper()
	key, err := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	if err != nil {
		t.Fatal(err)
	}
	tmpl := &x509.Certificate{
		SerialNumber: big.NewInt(1), Subject: pkix.Name{CommonName: "dash.localhost"},
		Issuer: pkix.Name{Organization: []string{"Caddy Local Authority"}}, DNSNames: []string{"dash.localhost"},
		NotBefore: notBefore, NotAfter: notAfter, KeyUsage: x509.KeyUsageDigitalSignature,
		ExtKeyUsage: []x509.ExtKeyUsage{x509.ExtKeyUsageServerAuth},
	}
	der, err := x509.CreateCertificate(rand.Reader, tmpl, tmpl, &key.PublicKey, key)
	if err != nil {
		t.Fatal(err)
	}
	ln, err := tls.Listen("tcp", "127.0.0.1:0", &tls.Config{
		Certificates: []tls.Certificate{{Certificate: [][]byte{der}, PrivateKey: key}}, MinVersion: tls.VersionTLS12,
	})
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { _ = ln.Close() })
	go func() {
		for {
			c, err := ln.Accept()
			if err != nil {
				return
			}
			go func(c net.Conn) {
				_ = c.(*tls.Conn).HandshakeContext(context.Background())
				_ = c.Close()
			}(c)
		}
	}()
	return ln.Addr().String()
}

func TestCertificateJudgedByLifetime(t *testing.T) {
	now := time.Date(2026, 11, 17, 12, 0, 0, 0, time.UTC)
	cases := []struct {
		name          string
		before, after time.Duration
		want          Status
		summary       string
	}{
		{"local authority, 12 h cert with 11 h left", -time.Hour, 11 * time.Hour, StatusOK, "11 hours more"},
		{"90-day cert, 40 days left", -50 * 24 * time.Hour, 40 * 24 * time.Hour, StatusOK, "40 days more"},
		{"90-day cert, 15 days left: renewal overdue", -75 * 24 * time.Hour, 15 * 24 * time.Hour, StatusWarn, "15 days more"},
		{"90-day cert, 5 days left", -85 * 24 * time.Hour, 5 * 24 * time.Hour, StatusFail, "5 days more"},
		{"expired", -90 * 24 * time.Hour, -2 * 24 * time.Hour, StatusFail, "expired 2 days ago"},
	}
	for _, c := range cases {
		t.Run(c.name, func(t *testing.T) {
			addr := tlsListener(t, now.Add(c.before), now.Add(c.after))
			r := &run{o: Options{TLSAddr: addr, Now: func() time.Time { return now }}}
			r.checkTLS(t.Context(), "dash.localhost")
			got := r.results[0]
			if got.Status != c.want || !strings.Contains(got.Summary, c.summary) {
				t.Fatalf("got %s %q, want %s with %q", got.Status, got.Summary, c.want, c.summary)
			}
		})
	}
}
