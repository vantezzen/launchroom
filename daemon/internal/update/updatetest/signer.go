// Package updatetest signs release artifacts with a throwaway key in the
// sigstore bundle format, for tests of the key-based verification mode
// (internal/update) and the self-update integration test.
package updatetest

import (
	"github.com/sigstore/sigstore-go/pkg/sign"
	"google.golang.org/protobuf/encoding/protojson"
)

// Signer holds an ephemeral ECDSA P-256 key.
type Signer struct {
	key *sign.EphemeralKeypair
	// PublicKeyPEM goes into trust.json's publicKey.
	PublicKeyPEM string
}

// NewSigner generates a key.
func NewSigner() (*Signer, error) {
	k, err := sign.NewEphemeralKeypair(nil)
	if err != nil {
		return nil, err
	}
	pemKey, err := k.GetPublicKeyPem()
	if err != nil {
		return nil, err
	}
	return &Signer{key: k, PublicKeyPEM: pemKey}, nil
}

// Sign returns a sigstore bundle (JSON, v0.3, message signature with a
// public-key hint, no transparency log) over artifact, like
// `cosign sign-blob --key … --bundle …` without Rekor upload.
func (s *Signer) Sign(artifact []byte) ([]byte, error) {
	b, err := sign.Bundle(&sign.PlainData{Data: artifact}, s.key, sign.BundleOptions{})
	if err != nil {
		return nil, err
	}
	return protojson.Marshal(b)
}
