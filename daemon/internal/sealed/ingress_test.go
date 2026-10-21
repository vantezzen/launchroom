package sealed

import (
	"crypto/ecdh"
	"crypto/rand"
	"strings"
	"testing"

	"github.com/vantezzen/launchroom/daemon/internal/secret"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

func TestOpenIngressSecret(t *testing.T) {
	priv, err := ecdh.X25519().GenerateKey(rand.Reader)
	if err != nil {
		t.Fatal(err)
	}
	seal := func(doc state.IngressSecret) state.Sealed {
		t.Helper()
		s, err := SealJSON(doc, priv.PublicKey(), rand.Reader)
		if err != nil {
			t.Fatal(err)
		}
		return s
	}
	dns := seal(state.IngressSecret{ServerID: vecServerID, Purpose: state.IngressSecretDNS, Values: map[string]secret.Secret{"token": "cf-SECRET"}})
	vals, err := OpenIngressSecret(dns, priv, vecServerID, state.IngressSecretDNS)
	if err != nil || vals["token"].Reveal() != "cf-SECRET" {
		t.Fatalf("open: %v", err)
	}
	// Bound to server and purpose.
	if _, err := OpenIngressSecret(dns, priv, vecOtherSrv, state.IngressSecretDNS); err == nil {
		t.Fatal("opened for another server")
	}
	if _, err := OpenIngressSecret(dns, priv, vecServerID, state.IngressSecretCertStorage); err == nil {
		t.Fatal("opened for another purpose")
	}
	other, _ := ecdh.X25519().GenerateKey(rand.Reader)
	if _, err := OpenIngressSecret(dns, other, vecServerID, state.IngressSecretDNS); err == nil {
		t.Fatal("opened with another key")
	}

	bad := []state.IngressSecret{
		{ServerID: vecServerID, Purpose: state.IngressSecretDNS, Values: map[string]secret.Secret{}},
		{ServerID: vecServerID, Purpose: state.IngressSecretDNS, Values: map[string]secret.Secret{"token": "x", "extra": "y"}},
		{ServerID: vecServerID, Purpose: state.IngressSecretDNS, Values: map[string]secret.Secret{"token": "a\nb"}},
		{ServerID: vecServerID, Purpose: state.IngressSecretCertStorage, Values: map[string]secret.Secret{"password": "p", "encryptionKey": "short"}},
		{ServerID: vecServerID, Purpose: "other", Values: map[string]secret.Secret{"token": "x"}},
	}
	for i, doc := range bad {
		_, err := OpenIngressSecret(seal(doc), priv, vecServerID, doc.Purpose)
		if err == nil {
			t.Errorf("case %d opened", i)
			continue
		}
		for _, v := range doc.Values {
			if v.Reveal() != "" && strings.Contains(err.Error(), v.Reveal()) && len(v.Reveal()) > 1 {
				t.Errorf("case %d: error leaks a value: %v", i, err)
			}
		}
	}
	storage := seal(state.IngressSecret{ServerID: vecServerID, Purpose: state.IngressSecretCertStorage, Values: map[string]secret.Secret{
		"username": "lr-caddy", "password": "pw", "encryptionKey": secret.Secret(strings.Repeat("k", 32)),
	}})
	if vals, err := OpenIngressSecret(storage, priv, vecServerID, state.IngressSecretCertStorage); err != nil || vals["username"].Reveal() != "lr-caddy" {
		t.Fatalf("storage: %v", err)
	}
}
