package configfile

import (
	"encoding/json"
	"errors"
	"os"
	"strings"
	"testing"
)

func TestCheck(t *testing.T) {
	cases := []struct {
		name, content, message string
		line, column           int
	}{
		{"valid", `{"version": 1, "services": {}}`, "", 0, 0},
		{"trailing comma", "{\n  \"version\": 1,\n}", "trailing comma before }", 3, 1},
		{"comment", "{\n  // hi\n  \"version\": 1\n}", "comments aren't allowed", 2, 3},
		{"cut off", "{\n  \"version\": 1", "ends too early", 2, 15},
		{"not an object", `[1]`, "must be a JSON object", 1, 1},
		{"two documents", `{} {}`, "after the end", 1, 4},
		{"empty", "  \n", "empty", 1, 1},
		{"bom", "\xef\xbb\xbf{}", "", 0, 0},
	}
	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			err := Check([]byte(tc.content))
			if tc.message == "" {
				if err != nil {
					t.Fatalf("unexpected error %v", err)
				}
				return
			}
			var se *SyntaxError
			if !errors.As(err, &se) {
				t.Fatalf("want a syntax error, got %v", err)
			}
			if !strings.Contains(se.Message, tc.message) || se.Line != tc.line || se.Column != tc.column {
				t.Fatalf("got %d:%d %q", se.Line, se.Column, se.Message)
			}
		})
	}
	if err := Check([]byte(`{"a": "` + strings.Repeat("x", MaxBytes) + `"}`)); err == nil {
		t.Fatal("a file over the limit must be refused")
	}
}

func TestPositionCountsCharacters(t *testing.T) {
	line, col := Position([]byte("{\"name\": \"Café\", \"x\": 1}"), 18)
	if line != 1 || col != 18 {
		t.Fatalf("got %d:%d", line, col)
	}
}

func TestExcerpt(t *testing.T) {
	lines, first := Around([]byte("a\nb\nc\nd\ne\nf"), 4)
	got := Excerpt(lines, first, 4, 3)
	want := "     2 │ b\n     3 │ c\n>    4 │ d\n       │   ^\n     5 │ e\n     6 │ f\n"
	if got != want {
		t.Fatalf("got\n%s\nwant\n%s", got, want)
	}
}

// TestMaxBytesMatchesDashboard pins MaxBytes to the dashboard's limit
// (protocol/fixtures/cli/config-file.json, read by its JsonDocumentTest too).
func TestMaxBytesMatchesDashboard(t *testing.T) {
	data, err := os.ReadFile("../../../protocol/fixtures/cli/config-file.json")
	if err != nil {
		t.Fatal(err)
	}
	var fx struct {
		MaxBytes int `json:"maxBytes"`
	}
	if err := json.Unmarshal(data, &fx); err != nil {
		t.Fatal(err)
	}
	if fx.MaxBytes != MaxBytes {
		t.Fatalf("MaxBytes = %d, dashboard %d", MaxBytes, fx.MaxBytes)
	}
}
