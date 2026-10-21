package identity

import (
	"bytes"
	"crypto/ed25519"
	"testing"
)

func FuzzVerifyResponse(f *testing.F) {
	key := ed25519.NewKeyFromSeed(cpSeed)
	pub, _ := key.Public().(ed25519.PublicKey)
	body := []byte(`{"kind":"NodeState"}`)
	good, err := SignResponse("cp1", key, body)
	if err != nil {
		f.Fatal(err)
	}
	f.Add(good, body)
	f.Add("", body)
	f.Add("kid=cp1, sig=", body)
	f.Add("kid=cp1, sig=AAAA", []byte{})
	f.Add(good+" ", body)

	ks := KeySet{"cp1": pub}
	f.Fuzz(func(t *testing.T, header string, b []byte) {
		kid, err := ks.Verify(header, b)
		if err != nil {
			return
		}
		// Anything that verifies must be the genuine signature over the genuine body.
		if kid != "cp1" || header != good || !bytes.Equal(b, body) {
			t.Fatalf("forged response accepted: header=%q body=%q", header, b)
		}
	})
}
