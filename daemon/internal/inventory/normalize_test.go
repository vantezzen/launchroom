package inventory

import (
	"encoding/json"
	"fmt"
	"os"
	"path/filepath"
	"slices"
	"strings"
	"testing"

	"github.com/vantezzen/launchroom/daemon/internal/build"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// spdx builds a minimal SPDX document from purls.
func spdx(purls ...string) []byte {
	type ref struct {
		ReferenceCategory string `json:"referenceCategory"`
		ReferenceType     string `json:"referenceType"`
		ReferenceLocator  string `json:"referenceLocator"`
	}
	type pkg struct {
		Name         string `json:"name"`
		VersionInfo  string `json:"versionInfo"`
		ExternalRefs []ref  `json:"externalRefs,omitempty"`
	}
	doc := struct {
		SPDXVersion string `json:"spdxVersion"`
		Packages    []pkg  `json:"packages"`
	}{SPDXVersion: "SPDX-2.3", Packages: []pkg{{Name: "sha256:abc", VersionInfo: "sha256:abc"}}}
	for _, p := range purls {
		doc.Packages = append(doc.Packages, pkg{Name: "x", VersionInfo: "", ExternalRefs: []ref{{"PACKAGE-MANAGER", "purl", p}}})
	}
	b, _ := json.Marshal(doc)
	// As the scanner writes it: an in-toto statement around the document.
	b, _ = json.Marshal(map[string]any{"_type": "https://in-toto.io/Statement/v0.1", "predicateType": "https://spdx.dev/Document", "predicate": json.RawMessage(b)})
	return b
}

func comp(t *testing.T, cs []state.InventoryComponent, eco state.PackageEcosystem, name string) state.InventoryComponent {
	t.Helper()
	for _, c := range cs {
		if c.Ecosystem == eco && string(c.Name) == name {
			return c
		}
	}
	t.Fatalf("%s %s missing", eco, name)
	return state.InventoryComponent{}
}

func validResult(t *testing.T, cs []state.InventoryComponent, truncated bool) {
	t.Helper()
	res := &state.InventoryResult{
		Image:    "127.0.0.1:5000/apps/svc_01j9z8x7w6v5t4s3r2q1p0n9n2@sha256:4b5e57f6eb2f42b9039b3d1e13929295f231749c510cbe341cd68036d9af97e2",
		Platform: "linux/amd64", Generator: build.SBOMGenerator, Components: cs, Truncated: truncated,
	}
	done := state.TaskCompletion{TypeMeta: state.NewTypeMeta(state.KindTaskCompletion), Attempt: 1, Outcome: state.OutcomeSucceeded, Inventory: res}
	if err := done.Validate(); err != nil {
		t.Fatalf("completion invalid: %v", err)
	}
}

func TestParseSPDXEcosystems(t *testing.T) {
	doc := spdx(
		"pkg:deb/debian/libssl3@3.0.15-1~deb12u1?arch=amd64&distro=debian-12&upstream=openssl",
		"pkg:deb/debian/nodejs@18.19.0%2Bdfsg-6~deb12u2?arch=amd64&distro=debian-12",
		"pkg:apk/alpine/musl@1.2.5-r9?arch=x86_64&distro=alpine-3.21.3",
		"pkg:apk/alpine/php83-fpm@8.3.19-r0?arch=x86_64&distro=alpine-3.21.3",
		"pkg:generic/node@22.11.0",
		"pkg:golang/stdlib@go1.24.3",
		"pkg:golang/github.com/gorilla/mux@v1.8.1",
		"pkg:npm/%40next/env@16.4.2",
		"pkg:npm/lodash@4.17.20",
		"pkg:maven/org.apache.logging.log4j/log4j-core@2.24.3",
		"pkg:pypi/Django@5.2.1",
		"pkg:composer/laravel/framework@v12.1.0",
		"pkg:gem/rails@8.0.2",
		"pkg:cargo/serde@1.0.219",
		"pkg:github/actions/checkout@v4",               // unsupported type: skipped
		"pkg:npm/bad%20name@1.0.0",                     // grammar: dropped
		"pkg:golang/example.com/devel@(devel)",         // grammar: dropped
		"pkg:npm/x@1.0.0?q="+strings.Repeat("y", 1100), // purl too long: dropped
	)
	cs, dropped, err := parseSPDX(doc)
	if err != nil {
		t.Fatal(err)
	}
	if dropped != 3 || len(cs) != 14 {
		t.Fatalf("%d components, %d dropped: %+v", len(cs), dropped, cs)
	}
	names := map[string]string{}
	for _, c := range cs {
		names[string(c.eco)+" "+c.name] = c.version
	}
	for k, v := range map[string]string{
		"deb libssl3": "3.0.15-1~deb12u1", "apk php83-fpm": "8.3.19-r0", "binary node": "22.11.0", "go stdlib": "1.24.3",
		"go github.com/gorilla/mux": "v1.8.1", "npm @next/env": "16.4.2", "maven org.apache.logging.log4j:log4j-core": "2.24.3",
		"pypi Django": "5.2.1", "packagist laravel/framework": "v12.1.0", "rubygems rails": "8.0.2", "cargo serde": "1.0.219",
	} {
		if names[k] != v {
			t.Errorf("%s = %q, want %q", k, names[k], v)
		}
	}
}

func TestNormalizeWithSource(t *testing.T) {
	cs, _, err := parseSPDX(spdx(
		"pkg:deb/debian/libssl3@3.0.15-1~deb12u1?arch=amd64&distro=debian-12&upstream=openssl",
		"pkg:deb/debian/python3.11@3.11.2-6?arch=amd64&distro=debian-12",
		"pkg:generic/node@22.11.0",
		"pkg:golang/stdlib@go1.24.3",
		"pkg:npm/next@16.4.2", "pkg:npm/next@16.4.2", // duplicate: merged
		"pkg:npm/vitest@3.2.4",
		"pkg:npm/npm@10.9.0", // the runtime's own npm: no evidence
		"pkg:pypi/pytest@8.4.0",
		"pkg:pypi/django@5.2.1",
	))
	if err != nil {
		t.Fatal(err)
	}
	src := &sourceFacts{
		npm: &npmFacts{
			prod:     map[string]bool{"next@16.4.2": true, "lodash@4.17.20": true, "react@19.1.0": true, "zod@3.25.0": true},
			dev:      map[string]bool{"vitest@3.2.4": true},
			prodList: []nameVersion{{"next", "16.4.2"}, {"lodash", "4.17.20"}, {"react", "19.1.0"}, {"zod", "3.25.0"}},
			direct:   map[string]bool{"next": true, "lodash": true, "vitest": true}, lockfile: true,
		},
		pypi: &nameFacts{main: map[string]bool{"django": true}, dev: map[string]bool{"pytest": true}, direct: map[string]bool{"django": true}},
	}
	next := nextProbe{found: true, server: map[string]bool{"lodash": true}, static: map[string]bool{"react": true}}
	var notes []string
	out, truncated := normalize(cs, src, next, &notes)
	validResult(t, out, truncated)
	check := func(eco state.PackageEcosystem, name string, kind state.ComponentKind, scope state.DependencyScope, presence state.ComponentPresence, direct bool) {
		t.Helper()
		c := comp(t, out, eco, name)
		if c.Kind != kind || c.Scope != scope || c.Presence != presence || c.Direct != direct {
			t.Errorf("%s %s: %+v", eco, name, c)
		}
	}
	check(state.EcosystemBinary, "node", state.ComponentRuntime, state.ScopeRuntime, state.PresenceInstalled, false)
	check(state.EcosystemDeb, "python3.11", state.ComponentRuntime, state.ScopeRuntime, state.PresenceInstalled, false)
	check(state.EcosystemDeb, "libssl3", state.ComponentOS, state.ScopeRuntime, state.PresenceInstalled, false)
	check(state.EcosystemGo, "stdlib", state.ComponentRuntime, state.ScopeRuntime, state.PresenceInstalled, false)
	check(state.EcosystemNpm, "next", state.ComponentLibrary, state.ScopeRuntime, state.PresenceInstalled, true)
	check(state.EcosystemNpm, "vitest", state.ComponentLibrary, state.ScopeDev, state.PresenceInstalled, true)
	check(state.EcosystemNpm, "npm", state.ComponentLibrary, state.ScopeUnknown, state.PresenceInstalled, false)
	check(state.EcosystemNpm, "lodash", state.ComponentLibrary, state.ScopeRuntime, state.PresenceBundled, true)
	check(state.EcosystemNpm, "react", state.ComponentLibrary, state.ScopeClient, state.PresenceBundled, false)
	check(state.EcosystemNpm, "zod", state.ComponentLibrary, state.ScopeUnknown, state.PresenceBundled, false)
	check(state.EcosystemPyPI, "pytest", state.ComponentLibrary, state.ScopeDev, state.PresenceInstalled, false)
	check(state.EcosystemPyPI, "django", state.ComponentLibrary, state.ScopeRuntime, state.PresenceInstalled, true)
	if c := comp(t, out, state.EcosystemNpm, "lodash"); c.PURL != "pkg:npm/lodash@4.17.20" {
		t.Errorf("bundled purl = %q", c.PURL)
	}
	// Display order: runtimes, direct libraries, other runtime libraries, unknown, client, dev, OS packages.
	var order []string
	for _, c := range out {
		order = append(order, string(c.Name))
	}
	want := []string{"node", "python3.11", "stdlib", "lodash", "next", "django", "npm", "zod", "react", "vitest", "pytest", "libssl3"}
	if !slices.Equal(order, want) {
		t.Errorf("order = %v\nwant    %v", order, want)
	}

	// A static site: no JavaScript runtime, so bundled packages run in browsers.
	static, _, _ := parseSPDX(spdx("pkg:apk/alpine/nginx@1.27.4-r0?distro=alpine-3.21.3", "pkg:apk/alpine/musl@1.2.5-r9?distro=alpine-3.21.3"))
	out, _ = normalize(static, src, nextProbe{}, &notes)
	if c := comp(t, out, state.EcosystemNpm, "zod"); c.Scope != state.ScopeClient {
		t.Errorf("static site bundle scope = %s", c.Scope)
	}
	if c := comp(t, out, state.EcosystemApk, "nginx"); c.Kind != state.ComponentRuntime {
		t.Errorf("nginx kind = %s", c.Kind)
	}
}

// Images launchroom didn't build: language packages unknown, except Go,
// runtimes and OS packages.
func TestNormalizeWithoutSource(t *testing.T) {
	cs, _, _ := parseSPDX(spdx("pkg:npm/lodash@4.17.20", "pkg:pypi/django@5.2.1", "pkg:maven/a/b@1.0", "pkg:golang/github.com/a/b@v1.0.0",
		"pkg:deb/debian/postgresql-17@17.5-1?distro=debian-12", "pkg:generic/postgresql@17.5"))
	var notes []string
	out, _ := normalize(cs, nil, nextProbe{}, &notes)
	validResult(t, out, false)
	for _, c := range out {
		want := state.ScopeUnknown
		if c.Ecosystem == state.EcosystemGo || c.Kind != state.ComponentLibrary {
			want = state.ScopeRuntime
		}
		if c.Scope != want || c.Direct {
			t.Errorf("%s %s: scope %s direct %v", c.Ecosystem, c.Name, c.Scope, c.Direct)
		}
	}
}

func TestTruncationOrder(t *testing.T) {
	var purls []string
	for i := range state.MaxInventoryComponents + 50 {
		purls = append(purls, fmt.Sprintf("pkg:deb/debian/lib%05d@1.0?distro=debian-12", i))
	}
	purls = append(purls, "pkg:generic/node@22.11.0", "pkg:npm/next@16.4.2")
	cs, _, _ := parseSPDX(spdx(purls...))
	var notes []string
	out, truncated := normalize(cs, nil, nextProbe{}, &notes)
	if !truncated || len(out) != state.MaxInventoryComponents || out[0].Name != "node" || out[1].Name != "next" {
		t.Fatalf("truncated %v, %d, first %s %s", truncated, len(out), out[0].Name, out[1].Name)
	}
	validResult(t, out, truncated)
	res := &state.InventoryResult{Components: out, Truncated: true}
	fitCompletion(res)
	if len(res.Components) != state.MaxInventoryComponents {
		t.Error("a small result was cut")
	}
	// A result over the encoded limit is cut further, without "truncated".
	big := make([]state.InventoryComponent, state.MaxInventoryComponents)
	for i := range big {
		big[i] = state.InventoryComponent{Ecosystem: state.EcosystemDeb, Name: state.PackageName(fmt.Sprintf("p%d", i)), Version: "1",
			PURL: state.PackageURL("pkg:deb/debian/" + strings.Repeat("x", 900)), Kind: state.ComponentOS, Scope: state.ScopeRuntime, Presence: state.PresenceInstalled}
	}
	res = &state.InventoryResult{Components: big, Truncated: true}
	fitCompletion(res)
	b, _ := json.Marshal(res)
	if len(b) > maxCompletionBytes || res.Truncated || len(res.Components) == 0 || !slices.Contains(res.Notes, "The list was cut to fit the report") {
		t.Errorf("fit: %d bytes, %d components, truncated %v", len(b), len(res.Components), res.Truncated)
	}
}

func TestProbeNext(t *testing.T) {
	dir := t.TempDir()
	write := func(p, s string) {
		full := filepath.Join(dir, filepath.FromSlash(p))
		_ = os.MkdirAll(filepath.Dir(full), 0o750)
		if err := os.WriteFile(full, []byte(s), 0o644); err != nil {
			t.Fatal(err)
		}
	}
	write("app/.next/server/app/page.js", `require("../node_modules/lodash/lodash.js"); "[project]/node_modules/@tanstack/query-core/build/x.js"`)
	write("app/.next/server/chunks/node_modules_zod_lib_index_abc.js", `x`)
	write("app/.next/static/chunks/main.js", `"node_modules\\/react\\/index.js" node_modules/lodash/x.js`)
	write("app/.next/static/chunks/style.css", `node_modules/ignored/x`)
	write("etc/os-release", "ID=debian\nVERSION_ID=\"12\"\n")
	root, err := os.OpenRoot(dir)
	if err != nil {
		t.Fatal(err)
	}
	defer func() { _ = root.Close() }()
	p := probeNext(root)
	if !p.found || !p.server["lodash"] || !p.server["@tanstack/query-core"] || !p.static["react"] || p.static["ignored"] || p.stopped {
		t.Errorf("probe = %+v", p)
	}
	b, _ := readSmall(root, "etc/os-release", 64<<10)
	if d := parseOSRelease(b); d == nil || d.ID != "debian" || d.VersionID != "12" {
		t.Errorf("distro = %+v", d)
	}
	if d := parseOSRelease([]byte("ID=\"Bad Distro\"\n")); d != nil {
		t.Errorf("invalid distro accepted: %+v", d)
	}
}

func TestParsePURL(t *testing.T) {
	p, ok := parsePURL("pkg:npm/%40scope/name@1.0.0?arch=x#sub/path")
	if !ok || p.typ != "npm" || p.namespace != "@scope" || p.name != "name" || p.version != "1.0.0" || p.qualifiers.Get("arch") != "x" {
		t.Errorf("%+v", p)
	}
	for _, bad := range []string{"", "npm/x@1", "pkg:npm", "pkg:npm/%zz@1", "pkg:/x@1"} {
		if _, ok := parsePURL(bad); ok {
			t.Errorf("%q parsed", bad)
		}
	}
}

// Real output of the pinned SBOM scanner (build.SBOMGenerator), captured
// by TestInventory and reduced to the package list (testdata/*.spdx.json).
func TestScannerFixtures(t *testing.T) {
	load := func(name string) []scanned {
		t.Helper()
		b, err := os.ReadFile(filepath.Join("testdata", name+".spdx.json"))
		if err != nil {
			t.Fatal(err)
		}
		cs, dropped, err := parseSPDX(b)
		if err != nil || dropped != 0 {
			t.Fatalf("%s: %v (%d dropped)", name, err, dropped)
		}
		return cs
	}
	var notes []string

	// Debian (Railpack's Node runtime image) without a lockfile.
	out, _ := normalize(load("debian-node"), &sourceFacts{npm: &npmFacts{prod: map[string]bool{}, dev: map[string]bool{}, direct: map[string]bool{}}}, nextProbe{}, &notes)
	validResult(t, out, false)
	if c := comp(t, out, state.EcosystemBinary, "node"); c.Kind != state.ComponentRuntime || c.Version != "22.23.3" || out[0].Name != "node" {
		t.Errorf("node: %+v (first %s)", c, out[0].Name)
	}
	if c := comp(t, out, state.EcosystemDeb, "base-files"); c.Kind != state.ComponentOS || !strings.Contains(string(c.PURL), "distro=debian-13") {
		t.Errorf("deb: %+v", c)
	}
	for _, c := range out {
		if c.Ecosystem == state.EcosystemNpm && (c.Scope != state.ScopeUnknown || c.Presence != state.PresenceInstalled) {
			t.Errorf("npm without lockfile evidence: %+v", c)
		}
	}

	// A Go binary (traefik/whoami): stdlib is a runtime, modules are runtime libraries.
	out, _ = normalize(load("go-binary"), nil, nextProbe{}, &notes)
	validResult(t, out, false)
	if c := comp(t, out, state.EcosystemGo, "stdlib"); c.Kind != state.ComponentRuntime || strings.HasPrefix(string(c.Version), "go") {
		t.Errorf("stdlib: %+v", c)
	}
	if c := comp(t, out, state.EcosystemGo, "golang.org/x/net"); c.Kind != state.ComponentLibrary || c.Scope != state.ScopeRuntime {
		t.Errorf("x/net: %+v", c)
	}

	// Alpine (postgres): apk packages, the postgresql binary, gosu's Go modules.
	out, _ = normalize(load("alpine-postgres"), nil, nextProbe{}, &notes)
	validResult(t, out, false)
	if c := comp(t, out, state.EcosystemBinary, "postgresql"); c.Kind != state.ComponentRuntime || c.Version != "18.6" {
		t.Errorf("postgresql: %+v", c)
	}
	if c := comp(t, out, state.EcosystemApk, "alpine-baselayout"); c.Kind != state.ComponentOS || !strings.Contains(string(c.PURL), "distro=alpine-") {
		t.Errorf("apk: %+v", c)
	}

	// The integration fixture's node_modules: installed packages with the lockfile's scopes.
	src := &sourceFacts{npm: &npmFacts{
		prod: map[string]bool{"ms@2.1.3": true}, dev: map[string]bool{"is-number@7.0.0": true},
		direct: map[string]bool{"ms": true, "is-number": true}, lockfile: true,
	}}
	out, _ = normalize(load("app-node-modules"), src, nextProbe{}, &notes)
	validResult(t, out, false)
	if c := comp(t, out, state.EcosystemNpm, "ms"); c.Scope != state.ScopeRuntime || !c.Direct {
		t.Errorf("ms: %+v", c)
	}
	if c := comp(t, out, state.EcosystemNpm, "is-number"); c.Scope != state.ScopeDev {
		t.Errorf("is-number: %+v", c)
	}
	if c := comp(t, out, state.EcosystemBinary, "busybox"); c.Kind != state.ComponentRuntime {
		t.Errorf("busybox: %+v", c)
	}
}
