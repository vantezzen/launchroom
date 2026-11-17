package build

import (
	"fmt"
	"io/fs"
	"regexp"
	"slices"
	"strings"

	rpplan "github.com/railwayapp/railpack/core/plan"

	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// staticCaddyStep is the step of Railpack's Node static-site deploy that
// writes its Caddyfile.
const staticCaddyStep = "caddy"

// hashedAssetDirs hold content-hashed build output (Vite, Astro, Next.js
// export, Create React App): a changed file gets a new name, so a year of
// caching is safe.
var hashedAssetDirs = []string{"/assets/*", "/_astro/*", "/_next/static/*", "/static/js/*", "/static/css/*", "/static/media/*"}

var (
	caddyHealthRespond = regexp.MustCompile(`(?m)^([ \t]*)respond /health 200[ \t]*$`)
	caddyTryFiles      = regexp.MustCompile(`(?m)^([ \t]*)try_files \{path\} \{path\}\.html \{path\}/index\.html( /index\.html)?[ \t]*$`)
	caddyRoot          = regexp.MustCompile(`(?m)^[ \t]*root \* (\S+)[ \t]*$`)
	reViteBase         = regexp.MustCompile(`\bbase\s*:\s*['"\x60](/[^'"\x60]*)['"\x60]`)
	reBasePath         = regexp.MustCompile(`^(/[A-Za-z0-9._~-]+)+$`)
)

// Static-site adjustments are on by default. Each has a decision id, so a
// preset listing it as a chip (or a service's disabledDecisions) can turn
// it off.
const (
	staticCacheDecision = "static-cache-headers"
	static404Decision   = "static-404"
	staticBaseDecision  = "static-base-path"
)

// staticShims says which static-site shims to apply.
type staticShims struct {
	Cache bool
	// MultiPage: the site has a page per route (Astro, Next.js export,
	// Gatsby), so an unknown path is a 404, not the home page.
	MultiPage bool
	// Base is the path the site is built for ("/app"), "" for the root.
	Base string
}

// adjustStaticServer edits the Caddyfile of Railpack's static-site deploy.
// Each edit must match exactly once; otherwise nothing changes.
func adjustStaticServer(p *rpplan.BuildPlan, on staticShims) error {
	idx := -1
	for i := range p.Steps {
		if p.Steps[i].Name == staticCaddyStep {
			idx = i
		}
	}
	if idx < 0 {
		return fmt.Errorf("the plan has no %s step", staticCaddyStep)
	}
	assets := p.Steps[idx].Assets
	cf, ok := assets[phpCaddyfileAsset]
	if !ok {
		return fmt.Errorf("the %s step has no Caddyfile", staticCaddyStep)
	}
	for _, e := range []struct {
		re   *regexp.Regexp
		what string
	}{{caddyHealthRespond, "respond /health 200"}, {caddyTryFiles, "try_files"}, {caddyRoot, "root *"}} {
		if n := len(e.re.FindAllStringIndex(cf, -1)); n != 1 {
			return fmt.Errorf("expected one %q line in Railpack's static Caddyfile, found %d", e.what, n)
		}
	}
	var add strings.Builder
	if on.Base != "" {
		add.WriteString("\n${1}uri strip_prefix " + on.Base)
	}
	if on.Cache {
		paths := make([]string, 0, 2*len(hashedAssetDirs))
		for _, d := range hashedAssetDirs {
			paths = append(paths, d)
			if on.Base != "" {
				paths = append(paths, on.Base+d)
			}
		}
		list := strings.Join(paths, " ")
		add.WriteString("\n${1}@launchroom_hashed path " + list +
			"\n${1}header @launchroom_hashed Cache-Control \"public, max-age=31536000, immutable\"" +
			"\n${1}@launchroom_revalidate not path " + list +
			"\n${1}header @launchroom_revalidate Cache-Control \"no-cache\"")
	}
	if on.MultiPage {
		root := caddyRoot.FindStringSubmatch(cf)[1]
		cf = caddyTryFiles.ReplaceAllString(cf, "${1}try_files {path} {path}.html {path}/index.html")
		add.WriteString("\n\n${1}handle_errors 404 {\n${1}\troot * " + root + "\n${1}\trewrite * /404.html\n${1}\tfile_server\n${1}}")
	}
	if add.Len() > 0 {
		cf = caddyHealthRespond.ReplaceAllString(cf, "${1}respond /health 200"+add.String())
	}
	assets[phpCaddyfileAsset] = cf
	return nil
}

// viteBase reads a non-root `base` from the app's vite.config.
func viteBase(fsys fs.FS) string {
	for _, name := range []string{"vite.config.ts", "vite.config.js", "vite.config.mjs", "vite.config.mts"} {
		raw, err := readCapped(fsys, name, maxManifestBytes)
		if err != nil {
			continue
		}
		if m := reViteBase.FindSubmatch(raw); m != nil {
			base := strings.TrimRight(string(m[1]), "/")
			if reBasePath.MatchString(base) {
				return base
			}
		}
		return ""
	}
	return ""
}

// adjustStaticPlan applies the static-site shims to a Railpack Node
// static-site plan unless the repository brings its own Caddyfile.
func adjustStaticPlan(p *rpplan.BuildPlan, ownCaddyfile, multiPage bool, base string, bs state.BuilderSpec, s *state.BuildSummary, sink LogSink) {
	if ownCaddyfile {
		sink.Line("plan", state.StreamStdout, "Using the app's Caddyfile as is, so caching and 404 pages are up to it")
		return
	}
	off := func(id string) bool { return slices.Contains(bs.DisabledDecisions, state.Slug(id)) }
	on := staticShims{Cache: !off(staticCacheDecision), MultiPage: multiPage && !off(static404Decision)}
	if base != "" && !off(staticBaseDecision) {
		on.Base = base
	}
	if !on.Cache && !on.MultiPage && on.Base == "" {
		return
	}
	if err := adjustStaticServer(p, on); err != nil {
		s.Warnings = append(s.Warnings, state.BuildWarning{Code: "static-server-not-adjusted", Message: state.Clip("launchroom could not adjust the static site server, so browsers may keep old pages after a deploy: "+err.Error(), 1024)})
		return
	}
	var done []string
	if on.Cache {
		done = append(done, "hashed assets cached for a year, HTML revalidated")
	}
	if on.MultiPage {
		done = append(done, "unknown paths return 404")
	}
	if on.Base != "" {
		done = append(done, "served under "+on.Base)
	}
	sink.Line("plan", state.StreamStdout, "Adjusted the static site server: "+strings.Join(done, ", "))
}
