package state

import (
	"crypto/ecdsa"
	"crypto/ed25519"
	"crypto/elliptic"
	"crypto/rand"
	"crypto/x509"
	"encoding/base64"
	"strings"
	"testing"

	"github.com/vantezzen/launchroom/daemon/internal/secret"
)

func decodeFixture[T any, PT interface {
	*T
	Document
}](t *testing.T, name string, out PT) error {
	t.Helper()
	doc, err := Decode[T, PT]([]byte(readFixture(t, name)))
	if err != nil {
		return err
	}
	*out = *doc
	return nil
}

func spki(t *testing.T, pub any) DERBase64 {
	t.Helper()
	der, err := x509.MarshalPKIXPublicKey(pub)
	if err != nil {
		t.Fatal(err)
	}
	return DERBase64(base64.StdEncoding.EncodeToString(der))
}

func TestClientCertificateRequestOnlyP256(t *testing.T) {
	p256, _ := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	p384, _ := ecdsa.GenerateKey(elliptic.P384(), rand.Reader)
	edPub, _, _ := ed25519.GenerateKey(rand.Reader)
	req := func(k DERBase64) *ClientCertificateRequest {
		return &ClientCertificateRequest{TypeMeta: NewTypeMeta(KindClientCertificateRequest), PublicKey: k}
	}
	if err := req(spki(t, &p256.PublicKey)).Validate(); err != nil {
		t.Fatal(err)
	}
	for name, k := range map[string]DERBase64{
		"p384": spki(t, &p384.PublicKey), "ed25519": spki(t, edPub), "garbage": "AAAA", "not base64": "!!!!",
		"empty": "", "huge": DERBase64(strings.Repeat("A", 8196)),
	} {
		if err := req(k).Validate(); err == nil {
			t.Errorf("%s accepted", name)
		}
	}
}

func TestRouteClientAuthNeedsCACertificates(t *testing.T) {
	var ns NodeState
	if err := decodeFixture(t, "node-state.client-auth.json", &ns); err != nil {
		t.Fatal(err)
	}
	if ns.Routes[0].ClientAuth == nil || ns.Routes[0].ClientAuth.Mode != ClientAuthRequire {
		t.Fatal("fixture has no clientAuth")
	}
	leaf := readFixture(t, "client-certificate.json")
	var cc ClientCertificate
	if err := decodeFixture(t, "client-certificate.json", &cc); err != nil || !strings.Contains(leaf, string(cc.Certificate)) {
		t.Fatal(err)
	}
	mutations := map[string]func(a *RouteClientAuth){
		"leaf as CA":     func(a *RouteClientAuth) { a.CACertificates = []DERBase64{cc.Certificate} },
		"no CAs":         func(a *RouteClientAuth) { a.CACertificates = nil },
		"five CAs":       func(a *RouteClientAuth) { a.CACertificates = []DERBase64{"QQ==", "Qg==", "Qw==", "RA==", "RQ=="} },
		"duplicate CA":   func(a *RouteClientAuth) { a.CACertificates = append(a.CACertificates, a.CACertificates[0]) },
		"bad mode":       func(a *RouteClientAuth) { a.Mode = "optional" },
		"no edge secret": func(a *RouteClientAuth) { a.EdgeSecret = Sealed{} },
	}
	for name, mutate := range mutations {
		t.Run(name, func(t *testing.T) {
			var doc NodeState
			if err := decodeFixture(t, "node-state.client-auth.json", &doc); err != nil {
				t.Fatal(err)
			}
			mutate(doc.Routes[0].ClientAuth)
			if err := doc.Validate(); err == nil {
				t.Error("accepted")
			}
		})
	}
}

func TestClientAuthIngressSecret(t *testing.T) {
	ok := &IngressSecret{ServerID: "srv_01J9Z8X7W6V5T4S3R2Q1P0N9MK", Purpose: IngressSecretClientAuth, Values: map[string]secret.Secret{"secret": "lr_edge_abc"}}
	if err := ok.Validate(); err != nil {
		t.Fatal(err)
	}
	for name, v := range map[string]map[string]secret.Secret{"missing": {}, "other value": {"secret": "x", "token": "y"}, "newline": {"secret": "a\nb"}} {
		s := &IngressSecret{ServerID: ok.ServerID, Purpose: IngressSecretClientAuth, Values: v}
		if err := s.Validate(); err == nil {
			t.Errorf("%s accepted", name)
		}
	}
}
