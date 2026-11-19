package format

import (
	"encoding/hex"
	"errors"
	"strings"
)

// crockford is the ULID alphabet (Crockford base32, uppercase).
const crockford = "0123456789ABCDEFGHJKMNPQRSTVWXYZ"

var errID = errors.New("trace: invalid id")

// ParseID returns the 16 bytes of the ULID behind a public id
// "<prefix>_<ULID>" (canonical uppercase, first character 0-7).
func ParseID(prefix, id string) ([16]byte, error) {
	var out [16]byte
	u, ok := strings.CutPrefix(id, prefix+"_")
	if !ok || len(u) != 26 || u[0] > '7' {
		return out, errID
	}
	// 26 characters × 5 bits = 130 bits; the top two are zero.
	var hi, lo uint64 // hi holds bits 64..129, lo bits 0..63
	for i := 0; i < len(u); i++ {
		v := strings.IndexByte(crockford, u[i])
		if v < 0 {
			return out, errID
		}
		hi = hi<<5 | lo>>59
		lo = lo<<5 | uint64(v)
	}
	for i := range 8 {
		out[i] = byte(hi >> (56 - 8*i))   //nolint:gosec // byte extraction
		out[8+i] = byte(lo >> (56 - 8*i)) //nolint:gosec // byte extraction
	}
	return out, nil
}

// IDString is ParseID's inverse.
func IDString(prefix string, b [16]byte) string {
	var hi, lo uint64
	for i := range 8 {
		hi = hi<<8 | uint64(b[i])
		lo = lo<<8 | uint64(b[8+i])
	}
	var s [26]byte
	for i := 25; i >= 0; i-- {
		s[i] = crockford[lo&31]
		lo = lo>>5 | hi<<59
		hi >>= 5
	}
	return prefix + "_" + string(s[:])
}

// ParseUUID parses a UUID in its hyphenated form (either case).
func ParseUUID(s string) ([16]byte, error) {
	var out [16]byte
	if len(s) != 36 || s[8] != '-' || s[13] != '-' || s[18] != '-' || s[23] != '-' {
		return out, errID
	}
	h := s[0:8] + s[9:13] + s[14:18] + s[19:23] + s[24:]
	if _, err := hex.Decode(out[:], []byte(h)); err != nil {
		return out, errID
	}
	return out, nil
}

// UUIDString writes a UUID lowercase and hyphenated.
func UUIDString(b [16]byte) string {
	h := hex.EncodeToString(b[:])
	return h[0:8] + "-" + h[8:12] + "-" + h[12:16] + "-" + h[16:20] + "-" + h[20:]
}
