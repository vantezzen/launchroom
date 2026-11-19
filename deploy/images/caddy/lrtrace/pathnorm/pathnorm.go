// Package pathnorm is a copy of launchroomd's request-analytics path
// normalisation (daemon/internal/traffic/normalize.go, docs/15 §5.1), used for
// the trace's path field (docs/23 §2.1). The vectors in
// protocol/fixtures/v1/trace/paths.json keep both copies equal: change them
// together.
package pathnorm

import "strings"

const (
	// idToken replaces an id-like path segment.
	idToken = ":id"
	// maxSegmentBytes: longer segments are treated as ids.
	maxSegmentBytes = 64
	// truncated ends a path that was cut at MaxBytes.
	truncated = "/..."
	// MaxBytes bounds a normalised path (state.MaxRequestPathBytes).
	MaxBytes = 128
)

// Normalize returns the normalised form of a request URI (origin form
// "/a/b?c", or absolute form "https://host/a"); ok is false for anything
// else (e.g. "*" of OPTIONS).
func Normalize(uri string) (string, bool) {
	if i := strings.IndexAny(uri, "?#"); i >= 0 {
		uri = uri[:i]
	}
	if i := strings.Index(uri, "://"); i > 0 && !strings.HasPrefix(uri, "/") {
		rest := uri[i+3:]
		j := strings.IndexByte(rest, '/')
		if j < 0 {
			return "/", true
		}
		uri = rest[j:]
	}
	if !strings.HasPrefix(uri, "/") {
		return "", false
	}
	segs := strings.Split(uri[1:], "/")
	var b strings.Builder
	for _, seg := range segs {
		seg = normalizeSegment(seg)
		if b.Len()+1+len(seg) > MaxBytes-len(truncated) {
			b.WriteString(truncated)
			break
		}
		b.WriteByte('/')
		b.WriteString(seg)
	}
	return b.String(), true
}

func normalizeSegment(seg string) string {
	if seg == "" {
		return seg
	}
	if len(seg) > maxSegmentBytes || !printable(seg) {
		return idToken
	}
	if idLike(seg) {
		return idToken
	}
	// "123.json", "3f2a…9c.png": keep the extension.
	if i := strings.LastIndexByte(seg, '.'); i > 0 && len(seg)-i <= 6 && idLike(seg[:i]) {
		return idToken + seg[i:]
	}
	return seg
}

// printable reports whether s is printable ASCII without "?" and "#"
// (the grammar of a request path).
func printable(s string) bool {
	for i := 0; i < len(s); i++ {
		if c := s[i]; c < 0x21 || c > 0x7e || c == '?' || c == '#' {
			return false
		}
	}
	return true
}

// idLike reports whether a segment is a number, a UUID, a hex hash (≥ 16
// characters) or a long random token (≥ 16 characters of letters, digits,
// "-" and "_", with digits and letters but no run of five lowercase letters,
// which words have and random ids rarely do).
func idLike(s string) bool {
	if s == "" {
		return false
	}
	digits, letters, hex, lowerRun, maxLowerRun := 0, 0, 0, 0, 0
	for i := 0; i < len(s); i++ {
		c := s[i]
		switch {
		case c >= '0' && c <= '9':
			digits++
			hex++
			lowerRun = 0
		case c >= 'a' && c <= 'z':
			letters++
			if c <= 'f' {
				hex++
			}
			lowerRun++
			maxLowerRun = max(maxLowerRun, lowerRun)
		case c >= 'A' && c <= 'Z':
			letters++
			if c <= 'F' {
				hex++
			}
			lowerRun = 0
		case c == '-' || c == '_':
			lowerRun = 0
		default:
			return false
		}
	}
	switch {
	case digits == len(s):
		return true
	case isUUID(s):
		return true
	case len(s) >= 16 && hex == len(s):
		return true
	case len(s) >= 16 && digits > 0 && letters > 0 && maxLowerRun < 5:
		return true
	}
	return false
}

func isUUID(s string) bool {
	if len(s) != 36 {
		return false
	}
	for i := 0; i < len(s); i++ {
		c := s[i]
		if i == 8 || i == 13 || i == 18 || i == 23 {
			if c != '-' {
				return false
			}
			continue
		}
		if (c < '0' || c > '9') && (c < 'a' || c > 'f') && (c < 'A' || c > 'F') {
			return false
		}
	}
	return true
}
