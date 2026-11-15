package state

import (
	stdjson "encoding/json"
	"errors"
	"os"
	"path/filepath"
	"slices"
	"strings"
	"testing"
)

// invalidFixture is one file of protocol/fixtures/v1/invalid: a document
// and the problem paths its validator must report. The dashboard's port of
// these rules (NodeStateValidator) replays the same files.
type invalidFixture struct {
	Description string `json:"description"`
	// EnforcedBy is "state" (Validate), "policy" (the local policy floor,
	// internal/policy) or "dashboard" (a renderer invariant launchroomd accepts).
	EnforcedBy string             `json:"enforcedBy"`
	Problems   []string           `json:"problems"`
	Document   stdjson.RawMessage `json:"document"`
}

func loadInvalidFixtures(t *testing.T, dir string) map[string]invalidFixture {
	t.Helper()
	files, err := filepath.Glob(filepath.Join(dir, "node-state.*.json"))
	if err != nil || len(files) == 0 {
		t.Fatalf("no invalid fixtures in %s: %v", dir, err)
	}
	out := make(map[string]invalidFixture, len(files))
	for _, f := range files {
		data, err := os.ReadFile(f) //nolint:gosec // test fixture path
		if err != nil {
			t.Fatal(err)
		}
		var fx invalidFixture
		if err := stdjson.Unmarshal(data, &fx); err != nil {
			t.Fatalf("%s: %v", f, err)
		}
		if len(fx.Problems) == 0 || (fx.EnforcedBy != "state" && fx.EnforcedBy != "policy" && fx.EnforcedBy != "dashboard") {
			t.Fatalf("%s: needs problems and enforcedBy state|policy|dashboard", f)
		}
		out[filepath.Base(f)] = fx
	}
	return out
}

func problemPaths(err error) []string {
	var paths []string
	for line := range strings.SplitSeq(strings.TrimPrefix(err.Error(), "invalid document: "), "\n") {
		if path, _, ok := strings.Cut(line, ": "); ok {
			paths = append(paths, path)
		}
	}
	return paths
}

func TestInvalidNodeStateFixtures(t *testing.T) {
	for name, fx := range loadInvalidFixtures(t, filepath.Join(fixtureDir, "invalid")) {
		t.Run(name, func(t *testing.T) {
			var s NodeState
			if err := stdjson.Unmarshal(fx.Document, &s); err != nil {
				t.Fatalf("decode: %v", err)
			}
			err := s.Validate()
			if fx.EnforcedBy != "state" {
				if err != nil {
					t.Fatalf("enforced by %s, but Validate refuses it: %v", fx.EnforcedBy, err)
				}
				return
			}
			var verr *ValidationError
			if !errors.As(err, &verr) {
				t.Fatalf("Validate = %v, want a ValidationError", err)
			}
			got := problemPaths(err)
			for _, want := range fx.Problems {
				if !slices.Contains(got, want) {
					t.Errorf("missing problem %s; got %v", want, got)
				}
			}
		})
	}
}
