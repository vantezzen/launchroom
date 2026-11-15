package nextadapter

import (
	"encoding/json"
	"os"
	"path/filepath"
	"reflect"
	"regexp"
	"slices"
	"strings"
	"testing"

	"github.com/vantezzen/launchroom/daemon/internal/state"
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

// The summary line the package prints is decoded into state.NextBuildInfo
// (internal/build/next.go): same fields, same ISR route cap.
func TestSummaryMatchesNextBuildInfo(t *testing.T) {
	f, err := Files()
	if err != nil {
		t.Fatal(err)
	}
	m := regexp.MustCompile(`MAX_SUMMARY_ISR = (\d+)`).FindStringSubmatch(f[Dir+"/dist/manifest.js"])
	field, _ := reflect.TypeFor[state.NextBuildInfo]().FieldByName("ISRRoutes")
	if m == nil || !strings.Contains(field.Tag.Get("jsonschema"), "maxItems="+m[1]) {
		t.Fatalf("MAX_SUMMARY_ISR %v, NextBuildInfo.isrRoutes %q", m, field.Tag.Get("jsonschema"))
	}
	src, err := os.ReadFile(filepath.Join("..", "..", "..", "..", "packages", "next-adapter", "src", "manifest.ts"))
	if err != nil {
		t.Skipf("package source not available: %v", err)
	}
	body := regexp.MustCompile(`(?s)export interface LaunchroomNextSummary \{(.*?)\n\}`).FindSubmatch(src)
	if body == nil {
		t.Fatal("LaunchroomNextSummary not found in manifest.ts")
	}
	var ts []string
	for _, k := range regexp.MustCompile(`(?m)^\s+(\w+):`).FindAllSubmatch(body[1], -1) {
		ts = append(ts, string(k[1]))
	}
	routes := regexp.MustCompile(`routes: \{([^}]*)\}`).FindSubmatch(body[1])
	var tsRoutes []string
	for _, k := range regexp.MustCompile(`(\w+):`).FindAllSubmatch(routes[1], -1) {
		tsRoutes = append(tsRoutes, string(k[1]))
	}
	jsonNames := func(t reflect.Type) []string {
		var out []string
		for i := range t.NumField() {
			n, _, _ := strings.Cut(t.Field(i).Tag.Get("json"), ",")
			out = append(out, n)
		}
		slices.Sort(out)
		return out
	}
	ts = slices.DeleteFunc(ts, func(k string) bool { return k == "v" }) // the line format version
	slices.Sort(ts)
	slices.Sort(tsRoutes)
	if want := jsonNames(reflect.TypeFor[state.NextBuildInfo]()); !slices.Equal(ts, want) {
		t.Errorf("LaunchroomNextSummary has %v, NextBuildInfo %v", ts, want)
	}
	if want := jsonNames(reflect.TypeFor[state.NextRouteCounts]()); !slices.Equal(tsRoutes, want) {
		t.Errorf("LaunchroomNextSummary.routes has %v, NextRouteCounts %v", tsRoutes, want)
	}
}
