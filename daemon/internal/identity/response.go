package identity

import (
	"crypto/ed25519"
	"encoding/base64"
	"errors"
	"fmt"
	"regexp"
	"strings"
)

// ResponseSignatureHeader carries the dashboard's signature over the exact
// response body bytes: "kid=<kid>, sig=<base64 std of the 64-byte Ed25519 signature>".
const ResponseSignatureHeader = "X-Lr-Signature"

// ErrBadResponseSignature is returned (wrapped) for every response signature failure.
var ErrBadResponseSignature = errors.New("identity: response signature invalid")

var (
	kidPattern               = regexp.MustCompile(`^[A-Za-z0-9._-]{1,64}$`)
	responseSignaturePattern = regexp.MustCompile(`^kid=([A-Za-z0-9._-]{1,64}), sig=([A-Za-z0-9+/]{86}==)$`)
)

// ValidKID reports whether kid is a valid control-plane signing key id.
func ValidKID(kid string) bool { return kidPattern.MatchString(kid) }

// KeySet maps control-plane key ids to Ed25519 public keys. Daemons trust a set
// so the dashboard can rotate its signing key.
type KeySet map[string]ed25519.PublicKey

// ParseKeySet parses kid → "ed25519:<base64>" as delivered at enrollment.
func ParseKeySet(m map[string]string) (KeySet, error) {
	if len(m) == 0 {
		return nil, errors.New("identity: empty control-plane key set")
	}
	ks := make(KeySet, len(m))
	for kid, s := range m {
		if !ValidKID(kid) {
			return nil, fmt.Errorf("identity: invalid kid %q", kid)
		}
		pub, err := ParsePublicKey(s)
		if err != nil {
			return nil, fmt.Errorf("identity: key %q: %w", kid, err)
		}
		ks[kid] = pub
	}
	return ks, nil
}

// Strings renders the key set as kid → "ed25519:<base64>".
func (ks KeySet) Strings() map[string]string {
	out := make(map[string]string, len(ks))
	for kid, pub := range ks {
		out[kid] = FormatPublicKey(pub)
	}
	return out
}

// Verify checks header against body using the key named by the header's kid.
// It fails closed: an empty key set, missing/malformed header or unknown kid
// are all errors. It returns the kid that verified.
func (ks KeySet) Verify(header string, body []byte) (string, error) {
	kid, sig, err := ParseResponseSignature(header)
	if err != nil {
		return "", err
	}
	pub, ok := ks[kid]
	if !ok || len(pub) != ed25519.PublicKeySize {
		return "", fmt.Errorf("%w: unknown kid %q", ErrBadResponseSignature, kid)
	}
	if !ed25519.Verify(pub, body, sig) {
		return "", fmt.Errorf("%w: signature does not verify", ErrBadResponseSignature)
	}
	return kid, nil
}

// VerifyWithKey checks header against body using exactly pub, regardless of
// the kid in the header (used for the enrollment response, which must be
// signed by the key pinned in the install command). It returns the header's kid.
func VerifyWithKey(pub ed25519.PublicKey, header string, body []byte) (string, error) {
	if len(pub) != ed25519.PublicKeySize {
		return "", fmt.Errorf("%w: no pinned key", ErrBadResponseSignature)
	}
	kid, sig, err := ParseResponseSignature(header)
	if err != nil {
		return "", err
	}
	if !ed25519.Verify(pub, body, sig) {
		return "", fmt.Errorf("%w: signature does not verify against the pinned key", ErrBadResponseSignature)
	}
	return kid, nil
}

// ParseResponseSignature strictly parses an X-LR-Signature header value.
func ParseResponseSignature(header string) (kid string, sig []byte, err error) {
	if header == "" {
		return "", nil, fmt.Errorf("%w: missing %s header", ErrBadResponseSignature, ResponseSignatureHeader)
	}
	m := responseSignaturePattern.FindStringSubmatch(header)
	if m == nil {
		return "", nil, fmt.Errorf("%w: malformed %s header", ErrBadResponseSignature, ResponseSignatureHeader)
	}
	sig, err = base64.StdEncoding.Strict().DecodeString(m[2])
	if err != nil || len(sig) != ed25519.SignatureSize {
		return "", nil, fmt.Errorf("%w: malformed signature encoding", ErrBadResponseSignature)
	}
	return m[1], sig, nil
}

// SignResponse produces an X-LR-Signature header value. The dashboard is the
// production signer; this exists for tests, fixtures and dev tooling.
func SignResponse(kid string, key ed25519.PrivateKey, body []byte) (string, error) {
	if !ValidKID(kid) {
		return "", fmt.Errorf("identity: invalid kid %q", kid)
	}
	if len(key) != ed25519.PrivateKeySize {
		return "", errors.New("identity: invalid ed25519 private key")
	}
	var b strings.Builder
	b.WriteString("kid=")
	b.WriteString(kid)
	b.WriteString(", sig=")
	b.WriteString(base64.StdEncoding.EncodeToString(ed25519.Sign(key, body)))
	return b.String(), nil
}
