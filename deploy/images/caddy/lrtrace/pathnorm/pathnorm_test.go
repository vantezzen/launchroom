package pathnorm

import (
	"encoding/json"
	"os"
	"path/filepath"
	"testing"
)

// TestVectors runs protocol/fixtures/v1/trace/paths.json, shared with
// launchroomd's traffic.NormalizePath.
func TestVectors(t *testing.T) {
	data, err := os.ReadFile(filepath.Join("..", "..", "..", "..", "..", "protocol", "fixtures", "v1", "trace", "paths.json"))
	if err != nil {
		t.Fatal(err)
	}
	var doc struct {
		Vectors []struct {
			In   string  `json:"in"`
			Want *string `json:"want"`
		} `json:"vectors"`
	}
	if err := json.Unmarshal(data, &doc); err != nil {
		t.Fatal(err)
	}
	if len(doc.Vectors) == 0 {
		t.Fatal("no vectors")
	}
	for _, v := range doc.Vectors {
		got, ok := Normalize(v.In)
		switch {
		case v.Want == nil && ok:
			t.Errorf("Normalize(%q) = %q, want not ok", v.In, got)
		case v.Want != nil && (!ok || got != *v.Want):
			t.Errorf("Normalize(%q) = %q, %v; want %q", v.In, got, ok, *v.Want)
		case ok && len(got) > MaxBytes:
			t.Errorf("Normalize(%q) is %d bytes", v.In, len(got))
		}
	}
}
