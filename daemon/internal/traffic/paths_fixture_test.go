package traffic

import (
	"encoding/json"
	"os"
	"path/filepath"
	"testing"
)

// TestNormalizePathSharedVectors runs protocol/fixtures/v1/trace/paths.json:
// the request trace's copy of the function in the edge's Caddy module runs
// the same vectors, so both normalise paths alike (docs/23 §2.1).
func TestNormalizePathSharedVectors(t *testing.T) {
	data, err := os.ReadFile(filepath.Join("..", "..", "..", "protocol", "fixtures", "v1", "trace", "paths.json"))
	if err != nil {
		t.Fatal(err)
	}
	var fx struct {
		Vectors []struct {
			In   string  `json:"in"`
			Want *string `json:"want"`
		} `json:"vectors"`
	}
	if err := json.Unmarshal(data, &fx); err != nil {
		t.Fatal(err)
	}
	if len(fx.Vectors) == 0 {
		t.Fatal("no vectors")
	}
	for _, v := range fx.Vectors {
		got, ok := NormalizePath(v.In)
		switch {
		case v.Want == nil && ok:
			t.Errorf("NormalizePath(%q) = %q, want no path", v.In, got)
		case v.Want != nil && (!ok || string(got) != *v.Want):
			t.Errorf("NormalizePath(%q) = %q, %v, want %q", v.In, got, ok, *v.Want)
		}
	}
}
