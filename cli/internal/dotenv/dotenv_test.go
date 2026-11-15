package dotenv

import (
	"encoding/json"
	"os"
	"testing"
)

func TestLine(t *testing.T) {
	cases := map[string]string{
		"plain":                    "K=plain",
		"postgres://u:p@db:5432/x": "K=postgres://u:p@db:5432/x",
		"has space":                `K="has space"`,
		"quote\"back\\slash":       `K="quote\"back\\slash"`,
		"multi\nline\ttab\r":       `K="multi\nline\ttab\r"`,
		"${{ secret(3) }}":         `K="${{ secret(3) }}"`,
		"":                         "K=",
		"#hash":                    `K="#hash"`,
	}
	for in, want := range cases {
		if got := Line("K", in); got != want {
			t.Errorf("Line(%q) = %s, want %s", in, got, want)
		}
	}
}

func TestRoundTrip(t *testing.T) {
	vars := map[string]string{
		"A": "simple", "B": "with \"quotes\" and \\", "C": "line1\nline2", "D": "", "E": "tab\there", "F": "é unicode ✓", "G": "a=b=c",
	}
	got, err := Parse(Render(vars, "header line\nsecond"))
	if err != nil {
		t.Fatal(err)
	}
	if len(got) != len(vars) {
		t.Fatalf("got %d keys, want %d: %v", len(got), len(vars), got)
	}
	for k, v := range vars {
		if got[k] != v {
			t.Errorf("%s = %q, want %q", k, got[k], v)
		}
	}
}

func TestValidKey(t *testing.T) {
	for _, k := range []string{"A", "_X", "DATABASE_URL", "a1"} {
		if !ValidKey(k) {
			t.Errorf("%q should be valid", k)
		}
	}
	for _, k := range []string{"", "1A", "A-B", "A B", "A=B", "$(id)"} {
		if ValidKey(k) {
			t.Errorf("%q should be invalid", k)
		}
	}
}

// TestSharedVectors reads protocol/fixtures/cli/dotenv.json, which the
// dashboard's EnvFileParserTest reads too.
func TestSharedVectors(t *testing.T) {
	data, err := os.ReadFile("../../../protocol/fixtures/cli/dotenv.json")
	if err != nil {
		t.Fatal(err)
	}
	var v struct {
		Lines []struct {
			Key   string `json:"key"`
			Value string `json:"value"`
			Line  string `json:"line"`
		} `json:"lines"`
		ValidKeys   []string `json:"validKeys"`
		InvalidKeys []string `json:"invalidKeys"`
	}
	if err := json.Unmarshal(data, &v); err != nil {
		t.Fatal(err)
	}
	for _, l := range v.Lines {
		if got := Line(l.Key, l.Value); got != l.Line {
			t.Errorf("Line(%s) = %s, want %s", l.Key, got, l.Line)
		}
		parsed, err := Parse(l.Line + "\n")
		if err != nil || parsed[l.Key] != l.Value {
			t.Errorf("Parse(%s) = %q, %v; want %q", l.Line, parsed[l.Key], err, l.Value)
		}
	}
	for _, k := range v.ValidKeys {
		if !ValidKey(k) {
			t.Errorf("%q should be valid", k)
		}
	}
	for _, k := range v.InvalidKeys {
		if ValidKey(k) {
			t.Errorf("%q should be invalid", k)
		}
	}
}
