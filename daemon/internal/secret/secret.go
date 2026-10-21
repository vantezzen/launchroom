// Package secret provides a string type for sensitive values that never
// leaks through logging, formatting or JSON serialization by accident.
//
// The only way to read the value is Reveal, which makes every use greppable
// (docs/09-security.md R12, R16).
package secret

import (
	"fmt"
	"log/slog"
)

// Redacted is what a Secret renders as everywhere except Reveal.
const Redacted = "[redacted]"

// Secret holds a sensitive string such as a join token or a decrypted env value.
type Secret string

// Reveal returns the plaintext value. Call it only at the boundary where the
// value is handed to its consumer (HTTP body, Docker API).
func (s Secret) Reveal() string { return string(s) }

// IsZero reports whether the secret is empty.
func (s Secret) IsZero() bool { return s == "" }

// String implements fmt.Stringer and always redacts.
func (s Secret) String() string { return Redacted }

// GoString implements fmt.GoStringer (used by %#v) and always redacts.
func (s Secret) GoString() string { return `secret.Secret("` + Redacted + `")` }

// Format implements fmt.Formatter so that no verb (%s, %v, %q, %x, ...) can print the value.
func (s Secret) Format(f fmt.State, _ rune) {
	_, _ = f.Write([]byte(Redacted))
}

// LogValue implements slog.LogValuer and always redacts.
func (s Secret) LogValue() slog.Value { return slog.StringValue(Redacted) }

// MarshalJSON always redacts. Protocol documents that must transmit a secret
// marshal it explicitly via Reveal.
func (s Secret) MarshalJSON() ([]byte, error) { return []byte(`"` + Redacted + `"`), nil }

// MarshalText always redacts.
func (s Secret) MarshalText() ([]byte, error) { return []byte(Redacted), nil }
