package build

import (
	"errors"
	"fmt"
	"regexp"
	"strings"

	rpplan "github.com/railwayapp/railpack/core/plan"

	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// phpCaddyfileAsset is the asset Railpack's PHP provider writes to /Caddyfile:
// its own template, or the repository's Caddyfile when there is one.
const phpCaddyfileAsset = "Caddyfile"

// phpStartScriptAsset is the asset Railpack's PHP provider writes to
// /start-container.sh: its framework template, or the repository's own.
const phpStartScriptAsset = "start-container.sh"

// phpServeAdjusted and phpServeAdjustedHTTPS are the plan log lines of a
// Railpack PHP build whose Caddyfile launchroom adjusted, with and without
// the visitor's address.
const (
	phpServeAdjusted      = "Adjusted the PHP server for launchroom: logs at info level, and PHP sees HTTPS and the visitor's address"
	phpServeAdjustedHTTPS = "Adjusted the PHP server for launchroom: logs at info level, and PHP sees HTTPS when the request came in over HTTPS"
)

// laravelStartAdjusted is the plan log line of an adjusted Laravel start script.
const laravelStartAdjusted = "Adjusted the Laravel start script: it keeps the shared cache and starts uncached if php artisan optimize fails"

var (
	caddyDebugLevel  = regexp.MustCompile(`(?m)^([ \t]*)level DEBUG[ \t]*$`)
	caddyPhpServer   = regexp.MustCompile(`(?m)^([ \t]*)php_server[ \t]*$`)
	caddyFrankenPHP  = regexp.MustCompile(`(?m)^([ \t]*)frankenphp \{[ \t]*$`)
	startOptimizeClr = regexp.MustCompile(`(?m)^php artisan optimize:clear[ \t]*\n`)
	startOptimize    = regexp.MustCompile(`(?m)^php artisan optimize[ \t]*$`)
)

// The ingress always overwrites X-Forwarded-Proto (Caddy trusts no proxies
// there), so the header is the scheme the client used. Symfony treats any
// HTTPS value other than "" and "off" as secure, hence the map to "on"; an
// unmatched request leaves HTTPS empty, as FrankenPHP itself does without TLS.
const phpHTTPSMap = "${1}map {header.X-Forwarded-Proto} {launchroom_https} {\n" +
	"${1}\thttps on\n" +
	"${1}}\n"

// phpTrustedProxies is added to the global options. The app container's
// direct peer is launchroom's edge (a private bridge address) or another
// server over the mesh (100.64.0.0/10); the edge overwrites X-Forwarded-For
// with the address it saw, so from those peers the header is the visitor.
// Any other peer keeps its own address. A container of the same environment
// can claim any address, but only for its own requests (the trust D85
// accepted for HTTPS).
const phpTrustedProxies = "${1}servers {\n" +
	"${1}\ttrusted_proxies static private_ranges 100.64.0.0/10\n" +
	"${1}\tclient_ip_headers X-Forwarded-For\n" +
	"${1}\ttrusted_proxies_strict\n" +
	"${1}}\n" +
	"${1}frankenphp {"

// phpServerShims says which PHP server shims to apply.
type phpServerShims struct {
	HTTPS     bool
	VisitorIP bool
}

// adjustPhpServer edits Railpack's Caddyfile in place:
//   - HTTPS: the global log level becomes INFO (Railpack ships DEBUG, which
//     logs every request) and php_server sets HTTPS from the edge's
//     X-Forwarded-Proto;
//   - VisitorIP: the server trusts X-Forwarded-For from private peers and
//     php_server sets REMOTE_ADDR to the client address.
//
// Each edit must match exactly once; any other shape is an error, never a
// silent skip, and nothing is changed.
func adjustPhpServer(p *rpplan.BuildPlan, on phpServerShims) error {
	assets, err := planAsset(p, phpCaddyfileAsset)
	if err != nil {
		return err
	}
	cf := assets[phpCaddyfileAsset]
	type edit struct {
		re   *regexp.Regexp
		what string
	}
	edits := []edit{{caddyPhpServer, "a plain php_server directive"}}
	if on.HTTPS {
		edits = append(edits, edit{caddyDebugLevel, "a DEBUG log level"})
	}
	if on.VisitorIP {
		edits = append(edits, edit{caddyFrankenPHP, "a frankenphp global option block"})
	}
	for _, e := range edits {
		if n := len(e.re.FindAllStringIndex(cf, -1)); n != 1 {
			return fmt.Errorf("expected %s in Railpack's Caddyfile, found %d", e.what, n)
		}
	}
	var block, env strings.Builder
	if on.HTTPS {
		cf = caddyDebugLevel.ReplaceAllString(cf, "${1}level INFO")
		block.WriteString(phpHTTPSMap)
		env.WriteString("${1}\tenv HTTPS {launchroom_https}\n")
	}
	if on.VisitorIP {
		cf = caddyFrankenPHP.ReplaceAllString(cf, phpTrustedProxies)
		env.WriteString("${1}\tenv REMOTE_ADDR {client_ip}\n")
	}
	if env.Len() > 0 {
		block.WriteString("${1}php_server {\n" + env.String() + "${1}}")
		cf = caddyPhpServer.ReplaceAllString(cf, block.String())
	}
	assets[phpCaddyfileAsset] = cf
	return nil
}

// planAsset returns the assets of the one step that has the named asset.
func planAsset(p *rpplan.BuildPlan, name string) (map[string]string, error) {
	var assets map[string]string
	for i := range p.Steps {
		if _, ok := p.Steps[i].Assets[name]; !ok {
			continue
		}
		if assets != nil {
			return nil, fmt.Errorf("the plan has more than one %s", name)
		}
		assets = p.Steps[i].Assets
	}
	if assets == nil {
		return nil, errors.New("the plan has no " + name)
	}
	return assets, nil
}

// laravelStartFallback replaces Railpack's `php artisan optimize`. On a
// failed optimize (a duplicate route name, a config closure) the app starts
// uncached instead of crash-looping; the half-written caches are removed.
const laravelStartFallback = `if ! php artisan optimize; then
  echo "launchroom: php artisan optimize failed, so the app starts without cached config and routes (see the error above)" >&2
  php artisan config:clear || true
  php artisan route:clear || true
  php artisan event:clear || true
fi`

// adjustLaravelStart edits Railpack's Laravel start script: optimize:clear
// is dropped (it flushes the shared cache store on every start and restart;
// the image never holds stale bootstrap caches, since build-time caching is
// removed by the Laravel preset and bootstrap/cache is gitignored), and
// optimize falls back to running uncached. Each edit must match exactly once.
func adjustLaravelStart(p *rpplan.BuildPlan) error {
	assets, err := planAsset(p, phpStartScriptAsset)
	if err != nil {
		return err
	}
	sh := assets[phpStartScriptAsset]
	for _, e := range []struct {
		re   *regexp.Regexp
		what string
	}{{startOptimizeClr, "php artisan optimize:clear"}, {startOptimize, "php artisan optimize"}} {
		if n := len(e.re.FindAllStringIndex(sh, -1)); n != 1 {
			return fmt.Errorf("expected one %q line in Railpack's Laravel start script, found %d", e.what, n)
		}
	}
	sh = startOptimizeClr.ReplaceAllString(sh, "")
	sh = startOptimize.ReplaceAllLiteralString(sh, laravelStartFallback)
	assets[phpStartScriptAsset] = sh
	return nil
}

// phpPlanInput is what adjustPhpPlan needs to know about the app.
type phpPlanInput struct {
	// OwnCaddyfile, OwnStartScript: the repository brings its own file,
	// which is the app's to configure.
	OwnCaddyfile, OwnStartScript bool
	// VisitorIP, LaravelStart: the preset's decisions (OD11 chips) that ask
	// for these adjustments; a user removes them like any other decision.
	VisitorIP, LaravelStart bool
}

// adjustPhpPlan applies the PHP server adjustments to a Railpack PHP plan:
// HTTPS and info-level logs always (D85), the visitor's address and the
// Laravel start script when the preset's decisions ask for them. A shape it
// doesn't know leaves the file unchanged and adds a warning.
func adjustPhpPlan(p *rpplan.BuildPlan, in phpPlanInput, s *state.BuildSummary, sink LogSink) {
	if in.OwnCaddyfile {
		sink.Line("plan", state.StreamStdout, "Using the app's Caddyfile as is, so its log level, HTTPS detection and visitor address are up to it")
	} else if err := adjustPhpServer(p, phpServerShims{HTTPS: true, VisitorIP: in.VisitorIP}); err != nil {
		s.Warnings = append(s.Warnings, state.BuildWarning{Code: "php-server-not-adjusted", Message: state.Clip("launchroom could not adjust the PHP server, so it may log every request, generate http:// links and see the edge's address instead of the visitor's: "+err.Error(), 1024)})
	} else if in.VisitorIP {
		sink.Line("plan", state.StreamStdout, phpServeAdjusted)
	} else {
		sink.Line("plan", state.StreamStdout, phpServeAdjustedHTTPS)
	}
	if !in.LaravelStart {
		return
	}
	if in.OwnStartScript {
		sink.Line("plan", state.StreamStdout, "Using the app's start-container.sh as is")
		return
	}
	if err := adjustLaravelStart(p); err != nil {
		s.Warnings = append(s.Warnings, state.BuildWarning{Code: "laravel-start-not-adjusted", Message: state.Clip("launchroom could not adjust the Laravel start script, so every start clears the shared cache and a failing php artisan optimize stops the app: "+err.Error(), 1024)})
		return
	}
	sink.Line("plan", state.StreamStdout, laravelStartAdjusted)
}
