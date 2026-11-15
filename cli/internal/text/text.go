// Package text cuts strings for terminals and file names without
// splitting a UTF-8 sequence.
package text

import "unicode/utf8"

// Clip returns s cut to at most n bytes at a rune boundary.
func Clip(s string, n int) string {
	if len(s) <= n {
		return s
	}
	for n > 0 && !utf8.RuneStart(s[n]) {
		n--
	}
	return s[:n]
}
