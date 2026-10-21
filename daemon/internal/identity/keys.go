// Package identity owns the daemon's key material and the cryptographic
// checks on both directions of the daemon ↔ dashboard channel
// (docs/03-daemon-protocol.md §3, §4.1; docs/09-security.md R21, R22):
//
//   - an Ed25519 signing key, used for RFC 9421 HTTP Message Signatures on every request;
//   - an X25519 sealing key, to which the dashboard seals workload secrets (crypto_box_seal);
//   - verification of dashboard response signatures (X-LR-Signature) against a trusted key set.
//
// The exact wire formats are specified in protocol/SIGNING.md.
package identity

import (
	"crypto/ecdh"
	"crypto/ed25519"
	"crypto/rand"
	"crypto/sha256"
	"crypto/x509"
	"encoding/base64"
	"encoding/pem"
	"errors"
	"fmt"
	"io/fs"
	"os"
	"path/filepath"
	"strings"

	"github.com/vantezzen/launchroom/daemon/internal/atomicfile"
)

const (
	signingKeyFile = "signing.key"
	sealingKeyFile = "sealing.key"

	// Ed25519Prefix prefixes textual Ed25519 public keys: "ed25519:<base64 std>".
	Ed25519Prefix = "ed25519:"
	// X25519Prefix prefixes textual X25519 public keys: "x25519:<base64 std>".
	X25519Prefix = "x25519:"

	pemType = "PRIVATE KEY"
)

// Identity is the daemon's long-lived key material. It is generated on the host
// on first start and never leaves it.
type Identity struct {
	signing ed25519.PrivateKey
	sealing *ecdh.PrivateKey
}

// SigningPublicKey returns the Ed25519 public key the dashboard uses to verify requests.
func (id *Identity) SigningPublicKey() ed25519.PublicKey {
	pub, _ := id.signing.Public().(ed25519.PublicKey)
	return pub
}

// SignMessage signs msg with the daemon's Ed25519 key. Used for the link
// probe handshake between daemons (docs/05 §2.1), whose messages carry a
// domain-separation prefix so they can never be confused with requests.
func (id *Identity) SignMessage(msg []byte) []byte { return ed25519.Sign(id.signing, msg) }

// SealingPublicKey returns the X25519 public key the dashboard seals secrets to.
func (id *Identity) SealingPublicKey() *ecdh.PublicKey { return id.sealing.PublicKey() }

// SealingPrivateKey returns the X25519 private key, for opening sealed payloads.
func (id *Identity) SealingPrivateKey() *ecdh.PrivateKey { return id.sealing }

// NewSigner returns a request signer for this identity using the given keyid
// ("enroll" before enrollment, the server id afterwards).
func (id *Identity) NewSigner(keyID string) (*Signer, error) {
	return NewSigner(keyID, id.signing)
}

// LoadOrCreate loads the identity from dir, generating and persisting a new one
// if dir contains no keys yet. created reports whether keys were generated.
//
// dir is created with mode 0700 and key files with 0600. Existing key files or
// a key dir with group/other permission bits are rejected rather than silently
// fixed, since that indicates someone else may have read the keys.
func LoadOrCreate(dir string) (id *Identity, created bool, err error) {
	if err := os.MkdirAll(dir, 0o700); err != nil {
		return nil, false, fmt.Errorf("identity: create key dir: %w", err)
	}
	if err := checkPerm(dir, 0o700); err != nil {
		return nil, false, err
	}

	signPath := filepath.Join(dir, signingKeyFile)
	sealPath := filepath.Join(dir, sealingKeyFile)
	signExists, err := exists(signPath)
	if err != nil {
		return nil, false, err
	}
	sealExists, err := exists(sealPath)
	if err != nil {
		return nil, false, err
	}

	switch {
	case signExists && sealExists:
		id, err := load(signPath, sealPath)
		return id, false, err
	case signExists || sealExists:
		return nil, false, fmt.Errorf("identity: only one of %s and %s exists in %s; refusing to regenerate keys (restore the missing file or remove both to re-enroll)", signingKeyFile, sealingKeyFile, dir)
	}

	id, err = Generate()
	if err != nil {
		return nil, false, err
	}
	if err := id.save(signPath, sealPath); err != nil {
		return nil, false, err
	}
	return id, true, nil
}

// Generate creates fresh key material without persisting it.
func Generate() (*Identity, error) {
	_, signing, err := ed25519.GenerateKey(rand.Reader)
	if err != nil {
		return nil, fmt.Errorf("identity: generate ed25519 key: %w", err)
	}
	sealing, err := ecdh.X25519().GenerateKey(rand.Reader)
	if err != nil {
		return nil, fmt.Errorf("identity: generate x25519 key: %w", err)
	}
	return &Identity{signing: signing, sealing: sealing}, nil
}

func (id *Identity) save(signPath, sealPath string) error {
	for _, k := range []struct {
		path string
		key  any
	}{{signPath, id.signing}, {sealPath, id.sealing}} {
		der, err := x509.MarshalPKCS8PrivateKey(k.key)
		if err != nil {
			return fmt.Errorf("identity: encode key: %w", err)
		}
		data := pem.EncodeToMemory(&pem.Block{Type: pemType, Bytes: der})
		if err := atomicfile.Write(k.path, data, 0o600); err != nil {
			return fmt.Errorf("identity: write %s: %w", filepath.Base(k.path), err)
		}
	}
	return nil
}

func load(signPath, sealPath string) (*Identity, error) {
	signAny, err := readKey(signPath)
	if err != nil {
		return nil, err
	}
	sealAny, err := readKey(sealPath)
	if err != nil {
		return nil, err
	}
	signing, ok := signAny.(ed25519.PrivateKey)
	if !ok {
		return nil, fmt.Errorf("identity: %s is not an Ed25519 key", signingKeyFile)
	}
	sealing, ok := sealAny.(*ecdh.PrivateKey)
	if !ok || sealing.Curve() != ecdh.X25519() {
		return nil, fmt.Errorf("identity: %s is not an X25519 key", sealingKeyFile)
	}
	return &Identity{signing: signing, sealing: sealing}, nil
}

func readKey(path string) (any, error) {
	if err := checkPerm(path, 0o600); err != nil {
		return nil, err
	}
	data, err := os.ReadFile(path) //nolint:gosec // path is inside the operator-configured data dir
	if err != nil {
		return nil, fmt.Errorf("identity: read %s: %w", filepath.Base(path), err)
	}
	block, rest := pem.Decode(data)
	if block == nil || block.Type != pemType || len(strings.TrimSpace(string(rest))) != 0 {
		return nil, fmt.Errorf("identity: %s is not a single PEM %q block", filepath.Base(path), pemType)
	}
	key, err := x509.ParsePKCS8PrivateKey(block.Bytes)
	if err != nil {
		return nil, fmt.Errorf("identity: parse %s: %w", filepath.Base(path), err)
	}
	return key, nil
}

// checkPerm rejects paths whose permission bits grant more than want
// (in particular any group/other access).
func checkPerm(path string, want fs.FileMode) error {
	fi, err := os.Stat(path)
	if err != nil {
		return fmt.Errorf("identity: stat %s: %w", path, err)
	}
	if extra := fi.Mode().Perm() &^ want; extra != 0 {
		return fmt.Errorf("identity: %s has permissions %v, want at most %v (fix with chmod %o)", path, fi.Mode().Perm(), want, want)
	}
	return nil
}

func exists(path string) (bool, error) {
	_, err := os.Stat(path)
	switch {
	case err == nil:
		return true, nil
	case errors.Is(err, fs.ErrNotExist):
		return false, nil
	default:
		return false, fmt.Errorf("identity: stat %s: %w", path, err)
	}
}

// FormatPublicKey renders an Ed25519 public key as "ed25519:<base64 std>".
func FormatPublicKey(pub ed25519.PublicKey) string {
	return Ed25519Prefix + base64.StdEncoding.EncodeToString(pub)
}

// FormatSealingKey renders an X25519 public key as "x25519:<base64 std>".
func FormatSealingKey(pub *ecdh.PublicKey) string {
	return X25519Prefix + base64.StdEncoding.EncodeToString(pub.Bytes())
}

// ParsePublicKey parses "ed25519:<base64 std of the 32-byte raw key>".
func ParsePublicKey(s string) (ed25519.PublicKey, error) {
	raw, err := parsePrefixed(s, Ed25519Prefix, ed25519.PublicKeySize)
	if err != nil {
		return nil, err
	}
	return ed25519.PublicKey(raw), nil
}

// ParseSealingKey parses "x25519:<base64 std of the 32-byte raw key>".
func ParseSealingKey(s string) (*ecdh.PublicKey, error) {
	raw, err := parsePrefixed(s, X25519Prefix, 32)
	if err != nil {
		return nil, err
	}
	pub, err := ecdh.X25519().NewPublicKey(raw)
	if err != nil {
		return nil, fmt.Errorf("identity: invalid x25519 public key: %w", err)
	}
	return pub, nil
}

func parsePrefixed(s, prefix string, size int) ([]byte, error) {
	b64, ok := strings.CutPrefix(s, prefix)
	if !ok {
		return nil, fmt.Errorf("identity: public key must start with %q", prefix)
	}
	raw, err := base64.StdEncoding.Strict().DecodeString(b64)
	if err != nil {
		return nil, fmt.Errorf("identity: public key is not valid standard base64: %w", err)
	}
	if len(raw) != size {
		return nil, fmt.Errorf("identity: public key has %d bytes, want %d", len(raw), size)
	}
	return raw, nil
}

// Fingerprint returns a short human-comparable fingerprint of an Ed25519
// public key, "SHA256:<unpadded base64 of sha256(raw key)>" (the OpenSSH style).
// The dashboard shows it next to a new server so an admin can compare it with
// the daemon's startup log before approving.
func Fingerprint(pub ed25519.PublicKey) string {
	sum := sha256.Sum256(pub)
	return "SHA256:" + base64.RawStdEncoding.EncodeToString(sum[:])
}
