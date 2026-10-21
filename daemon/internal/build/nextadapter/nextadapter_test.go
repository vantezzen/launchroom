package nextadapter

import (
	"encoding/json"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func TestVendoredFilesMatchSHA256SUMS(t *testing.T) {
	f, err := Files()
	if err != nil {
		t.Fatal(err)
	}
	for _, p := range []string{Entry, Dir + "/dist/cli.js", Dir + "/dist/finalize.js", Dir + "/package.json"} {
		if f[p] == "" {
			t.Errorf("missing %s (have %v)", p, Paths())
		}
	}
	for p := range f {
		if !strings.HasPrefix(p, Dir+"/") || strings.Contains(p, "..") || strings.ContainsRune(p, '\'') {
			t.Errorf("unsafe vendored path %q", p)
		}
	}
	if len(Digest()) != 64 {
		t.Fatal("digest")
	}
}

func TestVersionMatchesVendoredPackageJSON(t *testing.T) {
	f, err := Files()
	if err != nil {
		t.Fatal(err)
	}
	var pkg struct {
		Name    string `json:"name"`
		Version string `json:"version"`
		Type    string `json:"type"`
	}
	if err := json.Unmarshal([]byte(f[Dir+"/package.json"]), &pkg); err != nil {
		t.Fatal(err)
	}
	if pkg.Name != "@vantezzen/launchroom-next-adapter" || pkg.Version != Version || pkg.Type != "module" {
		t.Fatalf("vendored package.json = %+v, nextadapter.Version = %s", pkg, Version)
	}
	if !strings.Contains(f[Dir+"/dist/version.js"], "'"+Version+"'") {
		t.Fatal("dist/version.js does not carry Version")
	}
}

// The vendored copy must come from the package source of this repository
// (`make next-adapter` keeps both in sync; CI rebuilds and diffs).
func TestVendoredVersionMatchesPackageSource(t *testing.T) {
	raw, err := os.ReadFile(filepath.Join("..", "..", "..", "..", "packages", "next-adapter", "package.json"))
	if err != nil {
		t.Skipf("package source not available: %v", err)
	}
	var pkg struct {
		Version string `json:"version"`
	}
	if err := json.Unmarshal(raw, &pkg); err != nil {
		t.Fatal(err)
	}
	if pkg.Version != Version {
		t.Fatalf("packages/next-adapter is %s, the daemon vendors %s: run make next-adapter", pkg.Version, Version)
	}
}

func TestSummaryMarkerMatchesPackage(t *testing.T) {
	f, err := Files()
	if err != nil {
		t.Fatal(err)
	}
	if !strings.Contains(f[Dir+"/dist/manifest.js"], "'"+SummaryMarker+"'") {
		t.Fatal("SUMMARY_MARKER in dist/manifest.js differs from nextadapter.SummaryMarker")
	}
}
