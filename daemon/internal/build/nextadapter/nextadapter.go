// Package nextadapter vendors the built @vantezzen/launchroom-next-adapter
// (packages/next-adapter, `make next-adapter`) into the daemon. The Next.js
// preset writes these files into the Railpack build step at Dir and points
// NEXT_ADAPTER_PATH at Entry, so builds never fetch the adapter from npm and
// the user's repository is never modified (docs/04 §3). SHA256SUMS pins the
// exact bytes; Files refuses to hand out anything that does not match it.
package nextadapter

import (
	"bufio"
	"crypto/sha256"
	"embed"
	"encoding/hex"
	"fmt"
	"io/fs"
	"maps"
	"path"
	"slices"
	"strings"
	"sync"
)

// Version is the vendored package version (assets/package.json).
const Version = "0.1.0"

// Dir is where the adapter lives inside the build container. It is outside
// the app directory (/app), so it never reaches the runtime image.
const Dir = "/opt/launchroom/next-adapter"

// Entry is the value of NEXT_ADAPTER_PATH (Next resolves it with
// require.resolve and loads it with import(), so an absolute ESM file works).
const Entry = Dir + "/dist/index.js"

// FinalizeCommand copies public/ and .next/static into the standalone output
// and prints the build summary line. It runs after `next build` because Next
// calls onBuildComplete before it writes .next/standalone.
const FinalizeCommand = "node " + Dir + "/dist/cli.js"

// SummaryMarker prefixes the one-line JSON summary FinalizeCommand prints.
const SummaryMarker = "launchroom-next-summary "

//go:embed assets
var assets embed.FS

//go:embed SHA256SUMS
var sums string

var (
	once    sync.Once
	files   map[string]string
	loadErr error
)

func load() (map[string]string, error) {
	want := map[string]string{}
	sc := bufio.NewScanner(strings.NewReader(sums))
	for sc.Scan() {
		line := strings.TrimSpace(sc.Text())
		if line == "" {
			continue
		}
		sum, name, ok := strings.Cut(line, "  ")
		if !ok || len(sum) != 64 || name == "" {
			return nil, fmt.Errorf("SHA256SUMS: malformed line %q", line)
		}
		want[name] = sum
	}
	out := map[string]string{}
	err := fs.WalkDir(assets, "assets", func(p string, d fs.DirEntry, err error) error {
		if err != nil || d.IsDir() {
			return err
		}
		rel := strings.TrimPrefix(p, "assets/")
		data, err := assets.ReadFile(p)
		if err != nil {
			return err
		}
		h := sha256.Sum256(data)
		got := hex.EncodeToString(h[:])
		switch w, ok := want[rel]; {
		case !ok:
			return fmt.Errorf("%s is not listed in SHA256SUMS", rel)
		case w != got:
			return fmt.Errorf("%s: sha256 %s does not match SHA256SUMS %s", rel, got, w)
		}
		out[path.Join(Dir, rel)] = string(data)
		return nil
	})
	if err != nil {
		return nil, err
	}
	if len(out) != len(want) {
		return nil, fmt.Errorf("SHA256SUMS lists %d files, %d are vendored", len(want), len(out))
	}
	if _, ok := out[Entry]; !ok {
		return nil, fmt.Errorf("vendored adapter has no %s", Entry)
	}
	return out, nil
}

// Files returns the vendored files keyed by their absolute path in the build
// container. It fails if the embedded bytes don't match SHA256SUMS.
func Files() (map[string]string, error) {
	once.Do(func() { files, loadErr = load() })
	if loadErr != nil {
		return nil, loadErr
	}
	return maps.Clone(files), nil
}

// Digest is the SHA-256 of SHA256SUMS: one value identifying the vendored build.
func Digest() string {
	h := sha256.Sum256([]byte(sums))
	return hex.EncodeToString(h[:])
}

// Paths lists the vendored files' container paths, sorted.
func Paths() []string {
	f, err := Files()
	if err != nil {
		return nil
	}
	return slices.Sorted(maps.Keys(f))
}
