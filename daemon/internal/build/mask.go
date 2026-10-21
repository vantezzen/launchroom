package build

import (
	"encoding/base64"
	"net/url"
	"slices"
	"strings"
)

// MaskText replaces secret values in log text (docs/09 R16).
const MaskText = "***"

// minMaskLen: shorter values (e.g. "1", "true") would mangle every log line.
const minMaskLen = 4

// Masker replaces secret values and their common encodings in text.
type Masker struct {
	r *strings.Replacer
}

// NewMasker builds a masker for values: the raw value, its standard/URL
// base64 forms (padded and raw), and its URL query/path-escaped forms.
func NewMasker(values ...string) *Masker {
	set := map[string]bool{}
	add := func(v string) {
		if len(v) >= minMaskLen {
			set[v] = true
		}
	}
	for _, v := range values {
		if len(v) < minMaskLen {
			continue
		}
		add(v)
		for _, enc := range []*base64.Encoding{base64.StdEncoding, base64.URLEncoding, base64.RawStdEncoding, base64.RawURLEncoding} {
			add(enc.EncodeToString([]byte(v)))
		}
		add(url.QueryEscape(v))
		add(url.PathEscape(v))
		// Multi-line secrets (PEM keys) appear line by line in logs.
		for _, line := range strings.Split(v, "\n") {
			if len(line) >= 16 {
				add(strings.TrimSpace(line))
			}
		}
	}
	forms := make([]string, 0, len(set))
	for v := range set {
		forms = append(forms, v)
	}
	// Longest first so a value inside a longer encoded form does not win.
	slices.SortFunc(forms, func(a, b string) int { return len(b) - len(a) })
	if len(forms) == 0 {
		return &Masker{}
	}
	pairs := make([]string, 0, 2*len(forms))
	for _, f := range forms {
		pairs = append(pairs, f, MaskText)
	}
	return &Masker{r: strings.NewReplacer(pairs...)}
}

// Mask returns s with every known secret form replaced.
func (m *Masker) Mask(s string) string {
	if m == nil || m.r == nil {
		return s
	}
	return m.r.Replace(s)
}
