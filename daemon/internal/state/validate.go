package state

import (
	"errors"
	"fmt"
)

// APIVersion is the protocol version of every v1 document.
const APIVersion = "launchroom.vantezzen.io/v1"

// Limits that keep documents bounded (defence against resource exhaustion).
const (
	maxArgv        = 256
	maxArgLen      = 8192
	maxListItems   = 1024
	maxErrors      = 25
	maxMessageLen  = 4096
	maxLogLines    = 5000
	maxLogLineLen  = 16384
	maxHeaderValue = 1024
)

// TypeMeta is embedded in every top-level protocol document (docs/03 §10).
type TypeMeta struct {
	APIVersion string `json:"apiVersion"`
	Kind       string `json:"kind"`
}

func (m TypeMeta) check(e *errs, kind string) {
	if m.APIVersion != APIVersion {
		e.addf("apiVersion", "must be %q, got %q", APIVersion, truncate(m.APIVersion))
	}
	if m.Kind != kind {
		e.addf("kind", "must be %q, got %q", kind, truncate(m.Kind))
	}
}

// NewTypeMeta returns the TypeMeta for a v1 document of the given kind.
func NewTypeMeta(kind string) TypeMeta { return TypeMeta{APIVersion: APIVersion, Kind: kind} }

type validator interface{ Validate() error }

// errs accumulates validation errors with a JSON-ish path.
type errs struct{ list []error }

func (e *errs) add(path string, err error) {
	if err == nil || len(e.list) >= maxErrors {
		return
	}
	e.list = append(e.list, fmt.Errorf("%s: %w", path, err))
}

func (e *errs) addf(path, format string, args ...any) {
	e.add(path, fmt.Errorf(format, args...))
}

func (e *errs) check(path string, v validator) { e.add(path, v.Validate()) }

func (e *errs) err() error {
	if len(e.list) == 0 {
		return nil
	}
	return &ValidationError{errs: errors.Join(e.list...)}
}

// ValidationError reports every problem found in a document.
type ValidationError struct{ errs error }

func (v *ValidationError) Error() string { return "invalid document: " + v.errs.Error() }

// Unwrap exposes the individual errors.
func (v *ValidationError) Unwrap() error { return v.errs }

func idx(path string, i int) string { return fmt.Sprintf("%s[%d]", path, i) }

func checkCount(e *errs, path string, n, minN, maxN int) {
	if n < minN {
		e.addf(path, "needs at least %d item(s)", minN)
	}
	if n > maxN {
		e.addf(path, "has %d items, at most %d allowed", n, maxN)
	}
}

// checkArgv validates an argv array. nil means "not set"; a non-nil empty slice is invalid.
func checkArgv(e *errs, path string, argv []string) {
	if argv == nil {
		return
	}
	checkCount(e, path, len(argv), 1, maxArgv)
	for i, a := range argv {
		if len(a) > maxArgLen {
			e.addf(idx(path, i), "longer than %d bytes", maxArgLen)
		}
		for _, r := range a {
			if r == 0 {
				e.addf(idx(path, i), "contains a NUL byte")
				break
			}
		}
	}
	if len(argv) > 0 && argv[0] == "" {
		e.addf(idx(path, 0), "must not be empty")
	}
}

func checkText(e *errs, path, s string, maxLen int) {
	if len(s) > maxLen {
		e.addf(path, "longer than %d bytes", maxLen)
	}
}

func unique[T comparable](e *errs, path string, items []T) {
	seen := make(map[T]struct{}, len(items))
	for i, it := range items {
		if _, dup := seen[it]; dup {
			e.addf(idx(path, i), "duplicate value %v", it)
		}
		seen[it] = struct{}{}
	}
}
