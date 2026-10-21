// Package sealed opens secrets the dashboard seals to this daemon
// (docs/03-daemon-protocol.md §6, docs/09-security.md R14).
//
// The format is libsodium's crypto_box_seal (PHP: sodium_crypto_box_seal):
// an ephemeral X25519 public key (32 bytes) followed by an XSalsa20-Poly1305
// box (16-byte tag + ciphertext), with the nonce derived from
// blake2b(ephemeral pk || recipient pk). golang.org/x/crypto/nacl/box
// implements exactly this as SealAnonymous/OpenAnonymous.
package sealed

import (
	"crypto/ecdh"
	"encoding/base64"
	"errors"
	"fmt"
	"io"

	"golang.org/x/crypto/nacl/box"

	"github.com/vantezzen/launchroom/daemon/internal/secret"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// Overhead is the number of bytes crypto_box_seal adds to the plaintext.
const Overhead = box.AnonymousOverhead

// ErrOpen is returned for any box that does not open; it deliberately says nothing more.
var ErrOpen = errors.New("sealed: cannot open box (wrong key or tampered ciphertext)")

func keys(priv *ecdh.PrivateKey) (pub, sk *[32]byte, err error) {
	if priv == nil || priv.Curve() != ecdh.X25519() {
		return nil, nil, errors.New("sealed: an X25519 private key is required")
	}
	pub, sk = new([32]byte), new([32]byte)
	copy(sk[:], priv.Bytes())
	copy(pub[:], priv.PublicKey().Bytes())
	return pub, sk, nil
}

// Open decodes and opens a sealed box addressed to priv. The caller owns the
// returned plaintext and should clear it after use.
func Open(b state.SealedBox, priv *ecdh.PrivateKey) ([]byte, error) {
	if err := b.Validate(); err != nil {
		return nil, fmt.Errorf("sealed: %w", err)
	}
	raw, err := base64.StdEncoding.Strict().DecodeString(string(b))
	if err != nil {
		return nil, fmt.Errorf("sealed: invalid base64: %w", err)
	}
	if len(raw) < Overhead {
		return nil, ErrOpen
	}
	pub, sk, err := keys(priv)
	if err != nil {
		return nil, err
	}
	defer clear(sk[:])
	out, ok := box.OpenAnonymous(nil, raw, pub, sk)
	if !ok {
		return nil, ErrOpen
	}
	return out, nil
}

// Seal seals plaintext to pub (crypto_box_seal). rand supplies the 32 bytes
// of the ephemeral key; pass crypto/rand.Reader except in deterministic tests.
func Seal(plaintext []byte, pub *ecdh.PublicKey, rand io.Reader) (state.SealedBox, error) {
	if pub == nil || pub.Curve() != ecdh.X25519() {
		return "", errors.New("sealed: an X25519 public key is required")
	}
	var pk [32]byte
	copy(pk[:], pub.Bytes())
	out, err := box.SealAnonymous(nil, plaintext, &pk, rand)
	if err != nil {
		return "", fmt.Errorf("sealed: %w", err)
	}
	return state.SealedBox(base64.StdEncoding.EncodeToString(out)), nil
}

// OpenWorkloadEnv opens a workload's sealed env and checks that it was sealed
// for exactly this server and workload (docs/03 §6: no re-targeting). The
// returned values are secrets; errors never contain them.
func OpenWorkloadEnv(s state.Sealed, priv *ecdh.PrivateKey, server state.ServerID, workload state.WorkloadID) (map[string]secret.Secret, error) {
	plain, err := Open(s.Sealed, priv)
	if err != nil {
		return nil, err
	}
	defer clear(plain)
	var env state.WorkloadEnv
	if err := state.DecodeStrict(plain, &env); err != nil {
		// The decode error may quote parts of the plaintext; do not wrap it.
		return nil, errors.New("sealed: env plaintext is not a valid WorkloadEnv document")
	}
	if err := env.Validate(); err != nil {
		return nil, fmt.Errorf("sealed: %w", err)
	}
	if env.ServerID != server || env.WorkloadID != workload {
		return nil, fmt.Errorf("sealed: env was sealed for %s/%s, not %s/%s", env.ServerID, env.WorkloadID, server, workload)
	}
	return env.Env, nil
}

// SealWorkloadEnv seals env to pub. It is used by tests and fixtures; the
// dashboard does the same with sodium_crypto_box_seal.
func SealWorkloadEnv(env state.WorkloadEnv, pub *ecdh.PublicKey, rand io.Reader) (state.Sealed, error) {
	if err := env.Validate(); err != nil {
		return state.Sealed{}, fmt.Errorf("sealed: %w", err)
	}
	plain, err := env.MarshalJSON()
	if err != nil {
		return state.Sealed{}, fmt.Errorf("sealed: encode: %w", err)
	}
	defer clear(plain)
	b, err := Seal(plain, pub, rand)
	if err != nil {
		return state.Sealed{}, err
	}
	return state.Sealed{Sealed: b}, nil
}

// OpenTaskToken opens a sealed task credential and checks that it was sealed
// for exactly this server, task and purpose. The token is a secret; errors
// never contain it.
func OpenTaskToken(s state.Sealed, priv *ecdh.PrivateKey, server state.ServerID, task state.TaskID, purpose state.TokenPurpose) (secret.Secret, error) {
	plain, err := Open(s.Sealed, priv)
	if err != nil {
		return "", err
	}
	defer clear(plain)
	var tok state.TaskToken
	if err := state.DecodeStrict(plain, &tok); err != nil {
		return "", errors.New("sealed: token plaintext is not a valid TaskToken document")
	}
	if err := tok.Validate(); err != nil {
		return "", fmt.Errorf("sealed: %w", err)
	}
	if tok.ServerID != server || tok.TaskID != task || tok.Purpose != purpose {
		return "", fmt.Errorf("sealed: token was sealed for %s/%s/%s, not %s/%s/%s", tok.ServerID, tok.TaskID, tok.Purpose, server, task, purpose)
	}
	return tok.Token, nil
}

// OpenTaskEnv opens a task's sealed build env, bound to this server and task.
func OpenTaskEnv(s state.Sealed, priv *ecdh.PrivateKey, server state.ServerID, task state.TaskID) (map[string]secret.Secret, error) {
	plain, err := Open(s.Sealed, priv)
	if err != nil {
		return nil, err
	}
	defer clear(plain)
	var env state.TaskEnv
	if err := state.DecodeStrict(plain, &env); err != nil {
		return nil, errors.New("sealed: env plaintext is not a valid TaskEnv document")
	}
	if err := env.Validate(); err != nil {
		return nil, fmt.Errorf("sealed: %w", err)
	}
	if env.ServerID != server || env.TaskID != task {
		return nil, fmt.Errorf("sealed: env was sealed for %s/%s, not %s/%s", env.ServerID, env.TaskID, server, task)
	}
	return env.Env, nil
}

// OpenIngressSecret opens sealed ingress credentials (NodeState.ingress) and
// checks that they were sealed for exactly this server and purpose. The
// values are secrets; errors never contain them.
func OpenIngressSecret(s state.Sealed, priv *ecdh.PrivateKey, server state.ServerID, purpose state.IngressSecretPurpose) (map[string]secret.Secret, error) {
	plain, err := Open(s.Sealed, priv)
	if err != nil {
		return nil, err
	}
	defer clear(plain)
	var doc state.IngressSecret
	if err := state.DecodeStrict(plain, &doc); err != nil {
		return nil, errors.New("sealed: plaintext is not a valid IngressSecret document")
	}
	if err := doc.Validate(); err != nil {
		return nil, fmt.Errorf("sealed: %w", err)
	}
	if doc.ServerID != server || doc.Purpose != purpose {
		return nil, fmt.Errorf("sealed: ingress secret was sealed for %s/%s, not %s/%s", doc.ServerID, doc.Purpose, server, purpose)
	}
	return doc.Values, nil
}

// SealJSON seals any marshalled plaintext document (tests, fixtures).
func SealJSON(doc interface{ MarshalJSON() ([]byte, error) }, pub *ecdh.PublicKey, rand io.Reader) (state.Sealed, error) {
	plain, err := doc.MarshalJSON()
	if err != nil {
		return state.Sealed{}, fmt.Errorf("sealed: encode: %w", err)
	}
	defer clear(plain)
	b, err := Seal(plain, pub, rand)
	if err != nil {
		return state.Sealed{}, err
	}
	return state.Sealed{Sealed: b}, nil
}
