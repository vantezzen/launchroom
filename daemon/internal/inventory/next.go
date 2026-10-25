package inventory

import (
	"errors"
	"io"
	"io/fs"
	"os"
	"regexp"
	"strings"
)

// Next.js probe (docs/22 §3.1 step 3): the compiled server and browser
// output of a Next.js image names the packages it bundled in module
// references ("node_modules/<name>/…"). Packages referenced from
// .next/server run on the server; packages referenced only from
// .next/static/chunks run in browsers.

// maxProbeBytes bounds what the probe reads.
const maxProbeBytes = 64 << 20

var reModuleRef = regexp.MustCompile(`node_modules[/\\]+(@[a-z0-9][a-z0-9._~-]*[/\\]+[a-z0-9._~-]+|[a-z0-9][a-z0-9._~-]*)[/\\]`)

// nextProbe is what the probe found.
type nextProbe struct {
	// found: the image has a .next/server directory.
	found          bool
	server, static map[string]bool
	// stopped: the read limit was reached before every file was read.
	stopped bool
}

// probeNext reads the exported .next files under root (the image's files,
// exported by the scan solve).
func probeNext(root *os.Root) nextProbe {
	p := nextProbe{server: map[string]bool{}, static: map[string]bool{}}
	budget := int64(maxProbeBytes)
	_ = fs.WalkDir(root.FS(), ".", func(rel string, d fs.DirEntry, err error) error {
		if err != nil || p.stopped {
			return nil //nolint:nilerr // unreadable entries are skipped
		}
		server := strings.Contains(rel, ".next/server")
		static := strings.Contains(rel, ".next/static/chunks")
		if d.IsDir() {
			if strings.HasSuffix(rel, ".next/server") {
				p.found = true
			}
			return nil
		}
		if !d.Type().IsRegular() || !strings.HasSuffix(rel, ".js") || !server && !static {
			return nil
		}
		into := p.static
		if server {
			p.found = true
			into = p.server
		}
		// File names name modules too (turbopack chunks: node_modules_lodash_…).
		for _, m := range reModuleRef.FindAllStringSubmatch(rel, -1) {
			into[strings.ReplaceAll(m[1], `\`, "/")] = true
		}
		f, err := root.Open(rel)
		if err != nil {
			return nil //nolint:nilerr // skipped
		}
		defer func() { _ = f.Close() }()
		b, err := io.ReadAll(io.LimitReader(f, budget+1))
		if err != nil && !errors.Is(err, io.EOF) {
			return nil //nolint:nilerr // skipped
		}
		if int64(len(b)) > budget {
			b = b[:budget]
			p.stopped = true
		}
		budget -= int64(len(b))
		for _, m := range reModuleRef.FindAllSubmatch(b, -1) {
			into[strings.ReplaceAll(string(m[1]), `\`, "/")] = true
		}
		if budget <= 0 {
			p.stopped = true
		}
		return nil
	})
	return p
}
