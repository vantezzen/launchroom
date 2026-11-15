package state

import (
	"strings"
	"unicode/utf8"
)

// Limits of free-text fields that launchroomd fills from error messages and
// log output. Producers clip to them so a long message never makes the
// dashboard refuse a document; TestTextLimitsMatchSchemaTags keeps them
// equal to the fields' jsonschema maxLength.
const (
	// MaxMessageLen bounds TaskCompletion.error, WorkloadStatus.message and IngressStatus.message.
	MaxMessageLen = 4096
	// MaxStatusLen bounds MeshStatus.message, WalArchiveStatus.message and ProbeStatus.lastError.
	MaxStatusLen = 1024
	// MaxDetailLen bounds VerifyCheck.detail.
	MaxDetailLen = 512
	// MaxProgressLen bounds TaskHeartbeat.progress.
	MaxProgressLen = 256
	// MaxLogLineLen bounds LogLine.text.
	MaxLogLineLen = 16384
	// MaxLogStepLen bounds LogLine.step.
	MaxLogStepLen = 128
)

// Clip returns s as valid UTF-8 of at most n bytes, cut on a rune boundary.
func Clip(s string, n int) string {
	s = strings.ToValidUTF8(s, "�")
	if len(s) <= n {
		return s
	}
	cut := n
	for cut > 0 && !utf8.RuneStart(s[cut]) {
		cut--
	}
	return s[:cut]
}

// Shorten is Clip with "…" appended when s was cut (the result can be up
// to len("…") bytes longer than n).
func Shorten(s string, n int) string {
	if len(s) <= n {
		return strings.ToValidUTF8(s, "�")
	}
	return Clip(s, n) + "…"
}

// ClipLine is Clip for one-line text: control characters are dropped first.
func ClipLine(s string, n int) string {
	return Clip(strings.Map(func(r rune) rune {
		if r < 0x20 || r == 0x7f {
			return -1
		}
		return r
	}, s), n)
}
