package build

import (
	"errors"
	"io/fs"
	"os"
	"path/filepath"
	"slices"
	"strings"
	"testing"

	"github.com/vantezzen/launchroom/daemon/internal/build/presets"
)

// repo builds a checkout below a parent directory that holds a "secret"
// file, so escapes are observable: <parent>/secret.key and <parent>/repo.
func repo(t *testing.T, links map[string]string, files map[string]string) (root, parent string) {
	t.Helper()
	parent = t.TempDir()
	if err := os.WriteFile(filepath.Join(parent, "secret.key"), []byte(`{"name":"stolen","scripts":{"start":"x"}}`), 0o600); err != nil {
		t.Fatal(err)
	}
	root = filepath.Join(parent, "repo")
	for name, body := range files {
		p := filepath.Join(root, filepath.FromSlash(name))
		if err := os.MkdirAll(filepath.Dir(p), 0o750); err != nil {
			t.Fatal(err)
		}
		if err := os.WriteFile(p, []byte(body), 0o644); err != nil {
			t.Fatal(err)
		}
	}
	for name, target := range links {
		p := filepath.Join(root, filepath.FromSlash(name))
		if err := os.MkdirAll(filepath.Dir(p), 0o750); err != nil {
			t.Fatal(err)
		}
		if err := os.Symlink(target, p); err != nil {
			t.Fatal(err)
		}
	}
	if err := os.MkdirAll(root, 0o750); err != nil {
		t.Fatal(err)
	}
	return root, parent
}

// TestCheckTreeRejectsEscapingSymlinks covers H1 of the 2026-10 security
// review: chained symlinks that a lexical check accepts.
func TestCheckTreeRejectsEscapingSymlinks(t *testing.T) {
	cases := map[string]map[string]string{
		// The exact chain from the review: s is the root, x = s/../.. is one
		// level above the root (lexically "a"), package.json reads through x.
		"review chain":          {"a/b/s": "../..", "a/b/x": "s/../..", "package.json": "a/b/x/secret.key"},
		"chain link only":       {"a/b/s": "../..", "a/b/x": "s/../.."},
		"deeper chain":          {"a/b/c/s": "../../..", "a/b/c/x": "s/../../../.."},
		"absolute":              {"package.json": "/data/keys/daemon.key"},
		"proc root":             {"host": "/proc/1/root"},
		"absolute dir":          {"etc": "/etc"},
		"plain dotdot":          {"up": ".."},
		"dir link then dotdot":  {"d": "sub", "sub/up": "../../secret.key"},
		"through dir link":      {"a/link": "../b", "b/x": "../../secret.key"},
		"nested chains":         {"l1": "l2", "l2": "l3/..", "l3/x": "y", "l3/..x": "z", "l3/y": "../..", "k": "l1/.."},
		"link to escaping link": {"ok": "bad", "bad": "../secret.key"},
		"loop":                  {"loop1": "loop2", "loop2": "loop1"},
		"dangling outside":      {"gone": "../nope/missing"},
	}
	for name, links := range cases {
		t.Run(name, func(t *testing.T) {
			root, _ := repo(t, links, map[string]string{"sub/.keep": "", "l3/.keep": "", "b/.keep": "", "a/b/c/.keep": ""})
			err := checkTree(root)
			if !errors.Is(err, errUnsafeRepo) {
				t.Fatalf("checkTree accepted %v: %v", links, err)
			}
		})
	}
}

func TestCheckTreeAcceptsInRepoSymlinks(t *testing.T) {
	root, _ := repo(t, map[string]string{
		// Monorepo shared configs and aliases.
		"apps/web/tsconfig.base.json": "../../packages/config/tsconfig.json",
		"apps/web/.eslintrc.json":     "../../packages/config/eslint.json",
		"apps/alias":                  "web",
		"apps/api/shared":             "../../packages",
		"current":                     "./apps/web/../web",
		// Chain that climbs and comes back, staying inside.
		"a/b/s":      "../..",
		"a/b/inside": "s/packages/config",
		"dangling":   "does/not/exist",
		"selfdir":    ".",
	}, map[string]string{
		"packages/config/tsconfig.json": "{}",
		"packages/config/eslint.json":   "{}",
		"apps/web/package.json":         `{"name":"web"}`,
		"apps/api/package.json":         `{"name":"api"}`,
	})
	if err := checkTree(root); err != nil {
		t.Fatalf("in-repo symlinks rejected: %v", err)
	}
	if d, err := appDir(root, "apps/alias"); err != nil || !strings.HasSuffix(d, filepath.Join("apps", "web")) {
		t.Fatalf("appDir via symlink: %q %v", d, err)
	}
	if p, err := fileIn(filepath.Join(root, "apps", "web"), "tsconfig.base.json"); err == nil {
		t.Fatalf("fileIn must refuse files outside the app directory, got %q", p)
	}
}

// TestRootConfinedReads checks the second layer: even if checkTree were
// skipped, the daemon's own reads go through os.Root and cannot escape.
func TestRootConfinedReads(t *testing.T) {
	root, _ := repo(t, map[string]string{"a/b/s": "../..", "a/b/x": "s/../..", "package.json": "a/b/x/secret.key", "Dockerfile": "../secret.key"}, nil)
	r, err := os.OpenRoot(root)
	if err != nil {
		t.Fatal(err)
	}
	defer func() { _ = r.Close() }()
	if _, err := fs.ReadFile(r.FS(), "package.json"); err == nil {
		t.Fatal("os.Root followed an escaping symlink chain")
	}
	if _, err := readDockerfile(r, "Dockerfile"); err == nil {
		t.Fatal("Dockerfile read through an escaping symlink")
	}
	facts := presets.Gather(r.FS(), nil)
	if facts.PackageJSON != nil && facts.PackageJSON.Name == "stolen" {
		t.Fatal("preset scan read a file outside the checkout")
	}
}

// TestPruneOutsideApp: unsafe links elsewhere in a monorepo (fixtures that
// mimic /proc) are removed so the app still builds; unsafe links inside the
// app folder are left for checkTree to refuse.
func TestPruneOutsideApp(t *testing.T) {
	root, _ := repo(t, map[string]string{
		"daemon/testdata/proc/1/root/etc/localtime": "/usr/share/zoneinfo/UTC",
		"daemon/testdata/up":                        "../../../secret.key",
		"daemon/testdata/ok":                        "../go.mod",
		"site/via-outside":                          "../daemon/testdata/up",
	}, map[string]string{
		"daemon/go.mod":     "module x",
		"site/package.json": `{"name":"site"}`,
	})
	dir, err := appDir(root, "site")
	if err != nil {
		t.Fatal(err)
	}
	removed, err := pruneOutsideApp(root, dir)
	if err != nil {
		t.Fatal(err)
	}
	slices.Sort(removed)
	want := []string{"daemon/testdata/proc/1/root/etc/localtime", "daemon/testdata/up"}
	if !slices.Equal(removed, want) {
		t.Fatalf("removed %v, want %v", removed, want)
	}
	if _, err := os.Lstat(filepath.Join(root, "daemon", "testdata", "ok")); err != nil {
		t.Fatalf("a safe link outside the app was removed: %v", err)
	}
	// The app's link to a removed escaping link now dangles inside the repo.
	if err := checkTree(root); err != nil {
		t.Fatalf("checkTree after pruning: %v", err)
	}
	if msg := prunedMessage(removed); !strings.Contains(msg, "Removed 2 ") {
		t.Fatalf("message: %q", msg)
	}

	// Inside the app folder nothing is pruned and checkTree still refuses.
	root2, _ := repo(t, map[string]string{"site/bad": "/etc/passwd"}, map[string]string{"site/package.json": "{}"})
	dir2, err := appDir(root2, "site")
	if err != nil {
		t.Fatal(err)
	}
	if removed, err := pruneOutsideApp(root2, dir2); err != nil || len(removed) != 0 {
		t.Fatalf("pruned inside the app: %v %v", removed, err)
	}
	if err := checkTree(root2); !errors.Is(err, errUnsafeRepo) {
		t.Fatalf("checkTree accepted an absolute link inside the app: %v", err)
	}

	// The app is the whole checkout: nothing is pruned.
	root3, _ := repo(t, map[string]string{"x/abs": "/etc/passwd"}, map[string]string{"package.json": "{}"})
	dir3, _ := appDir(root3, "")
	if removed, err := pruneOutsideApp(root3, dir3); err != nil || len(removed) != 0 {
		t.Fatalf("pruned with the app at the root: %v %v", removed, err)
	}
}
