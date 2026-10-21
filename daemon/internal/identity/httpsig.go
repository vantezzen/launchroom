package identity

import (
	"bytes"
	"crypto/ed25519"
	"crypto/rand"
	"crypto/sha256"
	"crypto/subtle"
	"encoding/base64"
	"errors"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"regexp"
	"strconv"
	"strings"
	"time"
)

// Request signing: a fixed RFC 9421 profile. See protocol/SIGNING.md for the
// normative description; this file is the reference implementation.

const (
	// EnrollKeyID is the keyid used before the daemon has a server id.
	EnrollKeyID = "enroll"
	// SignatureLabel is the RFC 9421 signature label.
	SignatureLabel = "sig1"
	// SignatureTag is the RFC 9421 "tag" parameter, binding signatures to this protocol.
	SignatureTag = "launchroomd-v1"
	// NonceHeader carries a 128-bit random nonce, base64url without padding.
	NonceHeader = "X-Lr-Nonce"

	// MaxSignedBodyBytes bounds request bodies the signer buffers.
	MaxSignedBodyBytes = 32 << 20

	nonceBytes = 16
)

// coveredComponents is the ordered list of covered components; it never varies.
var coveredComponents = []string{"@method", "@path", "@query", "content-digest", "date", "x-lr-nonce"}

var (
	keyIDPattern = regexp.MustCompile(`^(enroll|srv_[0-9A-HJKMNP-TV-Z]{26})$`)
	// signatureInputPattern accepts exactly the profile produced by Signer.
	signatureInputPattern = regexp.MustCompile(`^sig1=(\("@method" "@path" "@query" "content-digest" "date" "x-lr-nonce"\);created=([0-9]{1,12});keyid="([A-Za-z0-9_]{1,64})";alg="ed25519";tag="launchroomd-v1")$`)
	signaturePattern      = regexp.MustCompile(`^sig1=:([A-Za-z0-9+/]{86}==):$`)
	contentDigestPattern  = regexp.MustCompile(`^sha-256=:([A-Za-z0-9+/]{43}=):$`)
	noncePattern          = regexp.MustCompile(`^[A-Za-z0-9_-]{22}$`)
)

// ValidKeyID reports whether keyID is a valid request-signing keyid.
func ValidKeyID(keyID string) bool { return keyIDPattern.MatchString(keyID) }

// Signer produces RFC 9421 signatures for daemon requests.
type Signer struct {
	keyID string
	key   ed25519.PrivateKey
	// now and rand are replaceable for deterministic test vectors.
	now  func() time.Time
	rand io.Reader
}

// NewSigner returns a Signer for keyID ("enroll" or a server id).
func NewSigner(keyID string, key ed25519.PrivateKey) (*Signer, error) {
	if !ValidKeyID(keyID) {
		return nil, fmt.Errorf("identity: invalid keyid %q", keyID)
	}
	if len(key) != ed25519.PrivateKeySize {
		return nil, errors.New("identity: invalid ed25519 private key")
	}
	return &Signer{keyID: keyID, key: key, now: time.Now, rand: rand.Reader}, nil
}

// KeyID returns the keyid placed in Signature-Input.
func (s *Signer) KeyID() string { return s.keyID }

// SignedHeaders are the headers a signed request carries, plus the signature base for debugging/test vectors.
type SignedHeaders struct {
	ContentDigest  string
	Date           string
	Nonce          string
	SignatureInput string
	Signature      string
	// SignatureBase is the exact byte string that was signed (not sent).
	SignatureBase string
}

// Apply sets the signature headers on h.
func (sh SignedHeaders) Apply(h http.Header) {
	h.Set("Content-Digest", sh.ContentDigest)
	h.Set("Date", sh.Date)
	h.Set(NonceHeader, sh.Nonce)
	h.Set("Signature-Input", sh.SignatureInput)
	h.Set("Signature", sh.Signature)
}

// Sign computes the signature headers for a request with the given method,
// target URL and body.
func (s *Signer) Sign(method string, u *url.URL, body []byte) (SignedHeaders, error) {
	nonce := make([]byte, nonceBytes)
	if _, err := io.ReadFull(s.rand, nonce); err != nil {
		return SignedHeaders{}, fmt.Errorf("identity: read nonce: %w", err)
	}
	now := s.now().UTC().Truncate(time.Second)

	sh := SignedHeaders{
		ContentDigest: ContentDigest(body),
		Date:          now.Format(http.TimeFormat),
		Nonce:         base64.RawURLEncoding.EncodeToString(nonce),
	}
	params := signatureParams(now.Unix(), s.keyID)
	sh.SignatureBase = SignatureBase(SignatureBaseInput{
		Method:          method,
		Path:            ComponentPath(u),
		Query:           ComponentQuery(u),
		ContentDigest:   sh.ContentDigest,
		Date:            sh.Date,
		Nonce:           sh.Nonce,
		SignatureParams: params,
	})
	sig := ed25519.Sign(s.key, []byte(sh.SignatureBase))
	sh.SignatureInput = SignatureLabel + "=" + params
	sh.Signature = SignatureLabel + "=:" + base64.StdEncoding.EncodeToString(sig) + ":"
	return sh, nil
}

func signatureParams(created int64, keyID string) string {
	quoted := make([]string, len(coveredComponents))
	for i, c := range coveredComponents {
		quoted[i] = `"` + c + `"`
	}
	return "(" + strings.Join(quoted, " ") + ");created=" + strconv.FormatInt(created, 10) +
		`;keyid="` + keyID + `";alg="ed25519";tag="` + SignatureTag + `"`
}

// SignatureBaseInput holds the component values of the signature base.
type SignatureBaseInput struct {
	Method          string
	Path            string
	Query           string
	ContentDigest   string
	Date            string
	Nonce           string
	SignatureParams string // the Signature-Input member value, without the "sig1=" label
}

// SignatureBase builds the RFC 9421 §2.5 signature base for this profile:
// one line per covered component, `"<name>": <value>`, followed by the
// "@signature-params" line, joined by LF with no trailing newline.
func SignatureBase(in SignatureBaseInput) string {
	var b strings.Builder
	line := func(name, value string) {
		b.WriteString(`"`)
		b.WriteString(name)
		b.WriteString(`": `)
		b.WriteString(value)
		b.WriteString("\n")
	}
	line("@method", strings.ToUpper(in.Method))
	line("@path", in.Path)
	line("@query", in.Query)
	line("content-digest", in.ContentDigest)
	line("date", in.Date)
	line("x-lr-nonce", in.Nonce)
	b.WriteString(`"@signature-params": `)
	b.WriteString(in.SignatureParams)
	return b.String()
}

// ComponentPath returns the RFC 9421 "@path" value: the escaped absolute path
// as sent on the wire, "/" if empty.
func ComponentPath(u *url.URL) string {
	p := u.EscapedPath()
	if p == "" {
		return "/"
	}
	return p
}

// ComponentQuery returns the RFC 9421 "@query" value: "?" followed by the raw
// query string as sent on the wire, or just "?" when there is none.
func ComponentQuery(u *url.URL) string { return "?" + u.RawQuery }

// ContentDigest returns the RFC 9530 Content-Digest header value for body
// using sha-256: "sha-256=:<base64 std>:". An empty body is digested too.
func ContentDigest(body []byte) string {
	sum := sha256.Sum256(body)
	return "sha-256=:" + base64.StdEncoding.EncodeToString(sum[:]) + ":"
}

// Transport is an http.RoundTripper that signs every request.
type Transport struct {
	Base   http.RoundTripper
	Signer *Signer
}

// RoundTrip implements http.RoundTripper. It buffers the body (bounded by
// MaxSignedBodyBytes) to compute the digest and does not mutate req.
func (t *Transport) RoundTrip(req *http.Request) (*http.Response, error) {
	if t.Signer == nil {
		return nil, errors.New("identity: transport has no signer")
	}
	var body []byte
	if req.Body != nil && req.Body != http.NoBody {
		var err error
		body, err = io.ReadAll(io.LimitReader(req.Body, MaxSignedBodyBytes+1))
		_ = req.Body.Close()
		if err != nil {
			return nil, fmt.Errorf("identity: read request body: %w", err)
		}
		if len(body) > MaxSignedBodyBytes {
			return nil, fmt.Errorf("identity: request body exceeds %d bytes", MaxSignedBodyBytes)
		}
	}

	sh, err := t.Signer.Sign(req.Method, req.URL, body)
	if err != nil {
		return nil, err
	}

	out := req.Clone(req.Context())
	out.Header = req.Header.Clone()
	if out.Header == nil {
		out.Header = make(http.Header)
	}
	sh.Apply(out.Header)
	out.ContentLength = int64(len(body))
	if len(body) == 0 {
		out.Body = http.NoBody
		out.GetBody = func() (io.ReadCloser, error) { return http.NoBody, nil }
	} else {
		out.Body = io.NopCloser(bytes.NewReader(body))
		out.GetBody = func() (io.ReadCloser, error) { return io.NopCloser(bytes.NewReader(body)), nil }
	}

	base := t.Base
	if base == nil {
		base = http.DefaultTransport
	}
	return base.RoundTrip(out)
}

// CloseIdleConnections closes the base transport's idle connections (a new
// client certificate is presented on the next connection, docs/03 §4.3).
func (t *Transport) CloseIdleConnections() {
	if c, ok := t.Base.(interface{ CloseIdleConnections() }); ok {
		c.CloseIdleConnections()
	}
}

// VerifiedRequest is the outcome of a successful request verification.
type VerifiedRequest struct {
	KeyID   string
	Created time.Time
	Nonce   string
}

// RequestVerifyOptions configures VerifyRequest.
type RequestVerifyOptions struct {
	// LookupKey returns the public key for a keyid. For "enroll" the dashboard
	// uses the signing key from the enroll body (proof of possession).
	LookupKey func(keyID string) (ed25519.PublicKey, error)
	// Now and MaxSkew bound the Date header and created parameter (default ±60 s).
	Now     time.Time
	MaxSkew time.Duration
}

// VerifyRequest checks a daemon request against this profile. It is the
// reference for the dashboard's AuthenticateDaemon middleware; nonce replay
// tracking is the caller's job. Any deviation from the profile fails.
func VerifyRequest(r *http.Request, body []byte, opts RequestVerifyOptions) (VerifiedRequest, error) {
	fail := func(msg string) (VerifiedRequest, error) {
		return VerifiedRequest{}, errors.New("identity: request signature: " + msg)
	}
	if opts.LookupKey == nil {
		return fail("no key lookup configured")
	}
	skew := opts.MaxSkew
	if skew <= 0 {
		skew = 60 * time.Second
	}

	sigInput := singleHeader(r.Header, "Signature-Input")
	m := signatureInputPattern.FindStringSubmatch(sigInput)
	if m == nil {
		return fail("Signature-Input does not match the launchroom profile")
	}
	params, createdStr, keyID := m[1], m[2], m[3]
	sm := signaturePattern.FindStringSubmatch(singleHeader(r.Header, "Signature"))
	if sm == nil {
		return fail("malformed Signature header")
	}
	digest := singleHeader(r.Header, "Content-Digest")
	if !contentDigestPattern.MatchString(digest) {
		return fail("malformed Content-Digest header")
	}
	if subtle.ConstantTimeCompare([]byte(digest), []byte(ContentDigest(body))) != 1 {
		return fail("Content-Digest does not match body")
	}
	nonce := singleHeader(r.Header, NonceHeader)
	if !noncePattern.MatchString(nonce) {
		return fail("malformed nonce")
	}
	dateStr := singleHeader(r.Header, "Date")
	date, err := time.Parse(http.TimeFormat, dateStr)
	if err != nil {
		return fail("malformed Date header")
	}
	created, err := strconv.ParseInt(createdStr, 10, 64)
	if err != nil || created != date.Unix() {
		return fail("created parameter does not match Date")
	}
	now := opts.Now
	if now.IsZero() {
		now = time.Now()
	}
	if d := now.Sub(date); d > skew || d < -skew {
		return fail("Date outside the allowed clock skew")
	}

	pub, err := opts.LookupKey(keyID)
	if err != nil || len(pub) != ed25519.PublicKeySize {
		return fail("unknown keyid")
	}
	sig, err := base64.StdEncoding.Strict().DecodeString(sm[1])
	if err != nil || len(sig) != ed25519.SignatureSize {
		return fail("malformed signature")
	}
	base := SignatureBase(SignatureBaseInput{
		Method:          r.Method,
		Path:            ComponentPath(r.URL),
		Query:           ComponentQuery(r.URL),
		ContentDigest:   digest,
		Date:            dateStr,
		Nonce:           nonce,
		SignatureParams: params,
	})
	if !ed25519.Verify(pub, []byte(base), sig) {
		return fail("signature does not verify")
	}
	return VerifiedRequest{KeyID: keyID, Created: date, Nonce: nonce}, nil
}

// singleHeader returns the header value only if exactly one field line is present.
func singleHeader(h http.Header, name string) string {
	v := h.Values(name)
	if len(v) != 1 {
		return ""
	}
	return v[0]
}
