// Package format is the X-Launchroom-Ray header, version 1 (docs/23 §2):
// a tag-length-value payload sealed with AES-256-GCM under the edge's key,
// written as "lr1." and the base64url envelope.
// It has no Caddy dependency, so the vectors in protocol/fixtures/v1/trace
// test it alone.
package format

import (
	"crypto/cipher"
	"crypto/rand"
	"encoding/base64"
	"encoding/binary"
	"errors"
	"io"
	"strings"
	"unicode/utf8"
)

// Header is the response header that carries the trace (the "ray").
const Header = "X-Launchroom-Ray"

// Version is the only format version.
const Version = 1

// Prefix starts every value: "lr" and the version, so a reader can tell a
// newer format from a broken one before decoding. Edges before it sent the
// envelope alone (header Launchroom-Trace); Open still reads those.
const Prefix = "lr1."

// Sizes of the sealed form.
const (
	prefixSize = 5 // version + key id, also the additional data
	NonceSize  = 12
	tagSize    = 16
	minSealed  = prefixSize + NonceSize + tagSize
)

// Field limits.
const (
	MaxMethodBytes = 16
	MaxPathBytes   = 128
	MaxRules       = 8
)

// Payload tags.
const (
	tagReceivedAt     = 0x01
	tagRequestID      = 0x02
	tagEdge           = 0x03
	tagRoute          = 0x04
	tagService        = 0x05
	tagUpstreamServer = 0x06
	tagMethod         = 0x07
	tagPath           = 0x08
	tagProtocol       = 0x09
	tagStatus         = 0x0A
	tagOrigin         = 0x0B
	tagEdgeTime       = 0x0C
	tagUpstreamTime   = 0x0D
	tagUpstreamStatus = 0x0E
	tagAttempts       = 0x0F
	tagFailure        = 0x10
	tagFirewall       = 0x11
)

// Protocols (tag 0x09).
const (
	ProtocolHTTP10 = 1
	ProtocolHTTP11 = 2
	ProtocolHTTP2  = 3
	ProtocolHTTP3  = 4
)

// Origins (tag 0x0B, docs/23 §2.2).
const (
	OriginApp             = 1
	OriginFirewall        = 2
	OriginUpstreamFailure = 3
	OriginStopped         = 4
	OriginRedirect        = 5
	OriginNoRoute         = 6
	OriginAccessRefused   = 7
	OriginEdge            = 8
)

// Failures (tag 0x10, docs/23 §2.3).
const (
	FailureRefused     = 1
	FailureTimeout     = 2
	FailureNoUpstream  = 3
	FailureOther       = 4
	FailureClientClose = 5
)

// Firewall modes and outcomes (tag 0x11).
const (
	FirewallModeLog   = 1
	FirewallModeBlock = 2

	FirewallClean    = 0
	FirewallDetected = 1
	FirewallBlocked  = 2
)

// Firewall is what the web firewall decided.
type Firewall struct {
	Mode    uint8    `json:"mode"`
	Outcome uint8    `json:"outcome"`
	Score   uint8    `json:"score"`
	Rules   []uint32 `json:"rules"`
}

// Fields is one trace. Ids are public ids ("srv_…", "rt_…", "sin_…"); an
// empty string or a nil pointer is an absent field. JSON names are those of
// the fixtures.
type Fields struct {
	ReceivedAt     int64     `json:"receivedAt"` // Unix milliseconds
	RequestID      string    `json:"requestId"`  // UUID, lowercase
	Edge           string    `json:"edge"`
	Route          string    `json:"route,omitempty"`
	Service        string    `json:"service,omitempty"`
	UpstreamServer string    `json:"upstreamServer,omitempty"`
	Method         string    `json:"method,omitempty"`
	Path           string    `json:"path,omitempty"`
	Protocol       uint8     `json:"protocol,omitempty"`
	Status         uint16    `json:"status"`
	Origin         uint8     `json:"origin"`
	EdgeTime       *uint32   `json:"edgeTime,omitempty"`     // µs
	UpstreamTime   *uint32   `json:"upstreamTime,omitempty"` // µs
	UpstreamStatus uint16    `json:"upstreamStatus,omitempty"`
	Attempts       *uint8    `json:"attempts,omitempty"`
	Failure        uint8     `json:"failure,omitempty"`
	Firewall       *Firewall `json:"firewall,omitempty"`
}

// ErrUnreadable is the one answer for anything that isn't a readable trace:
// decoders don't say why (docs/23 §2).
var ErrUnreadable = errors.New("not a trace we can read")

var errField = errors.New("trace: field out of range")

// Encode returns the payload. Fields the format can't carry are refused
// (an invalid id), cut (a long path, on a UTF-8 boundary; more than eight
// rules) or left out (a method outside 1–16 printable bytes).
func Encode(f Fields) ([]byte, error) {
	b := make([]byte, 0, 256)
	put := func(tag byte, v []byte) { b = append(append(b, tag, byte(len(v))), v...) } //nolint:gosec // every value is at most 128 bytes
	putID := func(tag byte, prefix, id string, required bool) error {
		if id == "" && !required {
			return nil
		}
		raw, err := ParseID(prefix, id)
		if err != nil {
			return err
		}
		put(tag, raw[:])
		return nil
	}
	put(tagReceivedAt, binary.BigEndian.AppendUint64(nil, uint64(f.ReceivedAt))) //nolint:gosec // milliseconds since 1970 are positive
	u, err := ParseUUID(f.RequestID)
	if err != nil {
		return nil, err
	}
	put(tagRequestID, u[:])
	if err := putID(tagEdge, "srv", f.Edge, true); err != nil {
		return nil, err
	}
	if err := putID(tagRoute, "rt", f.Route, false); err != nil {
		return nil, err
	}
	if err := putID(tagService, "sin", f.Service, false); err != nil {
		return nil, err
	}
	if err := putID(tagUpstreamServer, "srv", f.UpstreamServer, false); err != nil {
		return nil, err
	}
	if validMethod(f.Method) {
		put(tagMethod, []byte(f.Method))
	}
	if p := CutPath(f.Path); p != "" {
		put(tagPath, []byte(p))
	}
	if f.Protocol != 0 {
		if f.Protocol > ProtocolHTTP3 {
			return nil, errField
		}
		put(tagProtocol, []byte{f.Protocol})
	}
	put(tagStatus, binary.BigEndian.AppendUint16(nil, f.Status))
	if f.Origin < OriginApp || f.Origin > OriginEdge {
		return nil, errField
	}
	put(tagOrigin, []byte{f.Origin})
	if f.EdgeTime != nil {
		put(tagEdgeTime, binary.BigEndian.AppendUint32(nil, *f.EdgeTime))
	}
	if f.UpstreamTime != nil {
		put(tagUpstreamTime, binary.BigEndian.AppendUint32(nil, *f.UpstreamTime))
	}
	if f.UpstreamStatus != 0 {
		put(tagUpstreamStatus, binary.BigEndian.AppendUint16(nil, f.UpstreamStatus))
	}
	if f.Attempts != nil {
		put(tagAttempts, []byte{*f.Attempts})
	}
	if f.Failure != 0 {
		if f.Failure > FailureClientClose {
			return nil, errField
		}
		put(tagFailure, []byte{f.Failure})
	}
	if fw := f.Firewall; fw != nil {
		if fw.Mode < FirewallModeLog || fw.Mode > FirewallModeBlock || fw.Outcome > FirewallBlocked {
			return nil, errField
		}
		v := []byte{fw.Mode, fw.Outcome, fw.Score}
		for i, r := range fw.Rules {
			if i == MaxRules {
				break
			}
			v = binary.BigEndian.AppendUint32(v, r)
		}
		put(tagFirewall, v)
	}
	return b, nil
}

// Decode parses a payload (docs/23 §2): unknown tags are skipped; a length
// past the end, a known tag twice or with a wrong length, a value out of
// range or a missing required field is refused.
func Decode(p []byte) (Fields, error) {
	var f Fields
	seen := map[byte]bool{}
	for len(p) > 0 {
		if len(p) < 2 || int(p[1]) > len(p)-2 {
			return Fields{}, ErrUnreadable
		}
		tag, v := p[0], p[2:2+int(p[1])]
		p = p[2+len(v):]
		if tag > tagFirewall || tag == 0 {
			continue
		}
		if seen[tag] {
			return Fields{}, ErrUnreadable
		}
		seen[tag] = true
		if err := f.set(tag, v); err != nil {
			return Fields{}, ErrUnreadable
		}
	}
	for _, t := range []byte{tagReceivedAt, tagRequestID, tagEdge, tagStatus, tagOrigin} {
		if !seen[t] {
			return Fields{}, ErrUnreadable
		}
	}
	return f, nil
}

func (f *Fields) set(tag byte, v []byte) error {
	fixed := func(n int) error {
		if len(v) != n {
			return errField
		}
		return nil
	}
	id := func(prefix string, dst *string) error {
		if err := fixed(16); err != nil {
			return err
		}
		*dst = IDString(prefix, [16]byte(v))
		return nil
	}
	switch tag {
	case tagReceivedAt:
		if err := fixed(8); err != nil {
			return err
		}
		f.ReceivedAt = int64(binary.BigEndian.Uint64(v)) //nolint:gosec // any value decodes
	case tagRequestID:
		if err := fixed(16); err != nil {
			return err
		}
		f.RequestID = UUIDString([16]byte(v))
	case tagEdge:
		return id("srv", &f.Edge)
	case tagRoute:
		return id("rt", &f.Route)
	case tagService:
		return id("sin", &f.Service)
	case tagUpstreamServer:
		return id("srv", &f.UpstreamServer)
	case tagMethod:
		if !validMethod(string(v)) {
			return errField
		}
		f.Method = string(v)
	case tagPath:
		if len(v) == 0 || len(v) > MaxPathBytes || v[0] != '/' || !utf8.Valid(v) {
			return errField
		}
		f.Path = string(v)
	case tagProtocol:
		if err := fixed(1); err != nil || v[0] < ProtocolHTTP10 || v[0] > ProtocolHTTP3 {
			return errField
		}
		f.Protocol = v[0]
	case tagStatus:
		if err := fixed(2); err != nil {
			return err
		}
		f.Status = binary.BigEndian.Uint16(v)
	case tagOrigin:
		if err := fixed(1); err != nil || v[0] < OriginApp || v[0] > OriginEdge {
			return errField
		}
		f.Origin = v[0]
	case tagEdgeTime, tagUpstreamTime:
		if err := fixed(4); err != nil {
			return err
		}
		n := binary.BigEndian.Uint32(v)
		if tag == tagEdgeTime {
			f.EdgeTime = &n
		} else {
			f.UpstreamTime = &n
		}
	case tagUpstreamStatus:
		if err := fixed(2); err != nil {
			return err
		}
		f.UpstreamStatus = binary.BigEndian.Uint16(v)
	case tagAttempts:
		if err := fixed(1); err != nil {
			return err
		}
		n := v[0]
		f.Attempts = &n
	case tagFailure:
		if err := fixed(1); err != nil || v[0] < FailureRefused || v[0] > FailureClientClose {
			return errField
		}
		f.Failure = v[0]
	case tagFirewall:
		if len(v) < 3 || (len(v)-3)%4 != 0 || (len(v)-3)/4 > MaxRules {
			return errField
		}
		fw := Firewall{Mode: v[0], Outcome: v[1], Score: v[2], Rules: []uint32{}}
		if fw.Mode < FirewallModeLog || fw.Mode > FirewallModeBlock || fw.Outcome > FirewallBlocked {
			return errField
		}
		for r := v[3:]; len(r) > 0; r = r[4:] {
			fw.Rules = append(fw.Rules, binary.BigEndian.Uint32(r))
		}
		f.Firewall = &fw
	}
	return nil
}

func validMethod(m string) bool {
	if m == "" || len(m) > MaxMethodBytes {
		return false
	}
	for i := 0; i < len(m); i++ {
		if c := m[i]; c < 0x21 || c > 0x7e {
			return false
		}
	}
	return true
}

// CutPath cuts a path to MaxPathBytes on a UTF-8 boundary.
func CutPath(p string) string {
	if len(p) <= MaxPathBytes {
		return p
	}
	p = p[:MaxPathBytes]
	for len(p) > 0 && !utf8.ValidString(p) {
		p = p[:len(p)-1]
	}
	return p
}

// Seal encrypts a payload into the header value (Prefix and the base64url
// envelope). nonce is nil in
// production (a random one is drawn); tests pass a fixed one.
func Seal(aead cipher.AEAD, keyID uint32, nonce, payload []byte) (string, error) {
	out := make([]byte, prefixSize, minSealed+len(payload))
	out[0] = Version
	binary.BigEndian.PutUint32(out[1:prefixSize], keyID)
	if nonce == nil {
		nonce = make([]byte, NonceSize)
		if _, err := io.ReadFull(rand.Reader, nonce); err != nil {
			return "", err
		}
	}
	if len(nonce) != NonceSize || aead.NonceSize() != NonceSize || aead.Overhead() != tagSize {
		return "", errors.New("trace: AES-256-GCM with a 12-byte nonce expected")
	}
	out = append(out, nonce...)
	out = aead.Seal(out, nonce, payload, out[:prefixSize])
	return Prefix + base64.RawURLEncoding.EncodeToString(out), nil
}

// Open decrypts a header value with the key the key id names (keys returns
// nil for an unknown id) and returns the key id and the payload. The value
// has Prefix, or none (edges before the prefix); any other prefix ("lr2.")
// is unreadable here.
func Open(header string, keys func(keyID uint32) cipher.AEAD) (uint32, []byte, error) {
	header = strings.TrimPrefix(header, Prefix)
	if !base64URL(header) {
		return 0, nil, ErrUnreadable
	}
	raw, err := base64.RawURLEncoding.Strict().DecodeString(header)
	if err != nil || len(raw) < minSealed || raw[0] != Version {
		return 0, nil, ErrUnreadable
	}
	keyID := binary.BigEndian.Uint32(raw[1:prefixSize])
	aead := keys(keyID)
	if aead == nil || aead.NonceSize() != NonceSize {
		return 0, nil, ErrUnreadable
	}
	nonce := raw[prefixSize : prefixSize+NonceSize]
	p, err := aead.Open(nil, nonce, raw[prefixSize+NonceSize:], raw[:prefixSize])
	if err != nil {
		return 0, nil, ErrUnreadable
	}
	return keyID, p, nil
}

// base64URL reports whether s uses only the base64url alphabet (the decoder
// alone would skip line breaks).
func base64URL(s string) bool {
	for i := 0; i < len(s); i++ {
		c := s[i]
		if (c < 'A' || c > 'Z') && (c < 'a' || c > 'z') && (c < '0' || c > '9') && c != '-' && c != '_' {
			return false
		}
	}
	return true
}
