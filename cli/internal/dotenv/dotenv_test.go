package dotenv

import "testing"

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
