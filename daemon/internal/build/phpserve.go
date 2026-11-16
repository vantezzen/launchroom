package build

import (
	"errors"
	"fmt"
	"regexp"

	rpplan "github.com/railwayapp/railpack/core/plan"

	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// phpCaddyfileAsset is the asset Railpack's PHP provider writes to /Caddyfile:
// its own template, or the repository's Caddyfile when there is one.
const phpCaddyfileAsset = "Caddyfile"

// phpServeAdjusted is the plan log line of a Railpack PHP build whose
// Caddyfile launchroom adjusted.
const phpServeAdjusted = "Adjusted the PHP server for launchroom: logs at info level, and PHP sees HTTPS when the request came in over HTTPS"

var (
	caddyDebugLevel = regexp.MustCompile(`(?m)^([ \t]*)level DEBUG[ \t]*$`)
	caddyPhpServer  = regexp.MustCompile(`(?m)^([ \t]*)php_server[ \t]*$`)
)

// The ingress always overwrites X-Forwarded-Proto (Caddy trusts no proxies
// there), so the header is the scheme the client used. Symfony treats any
// HTTPS value other than "" and "off" as secure, hence the map to "on"; an
// unmatched request leaves HTTPS empty, as FrankenPHP itself does without TLS.
const phpServerBlock = "${1}map {header.X-Forwarded-Proto} {launchroom_https} {\n" +
	"${1}\thttps on\n" +
	"${1}}\n" +
	"${1}php_server {\n" +
	"${1}\tenv HTTPS {launchroom_https}\n" +
	"${1}}"

// adjustPhpServer edits Railpack's Caddyfile in place: the global log level
// becomes INFO (Railpack ships DEBUG, which logs every request) and
// php_server sets HTTPS from the ingress's X-Forwarded-Proto. Each edit must
// match exactly once; any other shape is an error, never a silent skip.
func adjustPhpServer(p *rpplan.BuildPlan) error {
	var assets map[string]string
	for i := range p.Steps {
		if _, ok := p.Steps[i].Assets[phpCaddyfileAsset]; !ok {
			continue
		}
		if assets != nil {
			return errors.New("the plan has more than one Caddyfile")
		}
		assets = p.Steps[i].Assets
	}
	if assets == nil {
		return errors.New("the plan has no Caddyfile")
	}
	cf := assets[phpCaddyfileAsset]
	for _, e := range []struct {
		re   *regexp.Regexp
		what string
	}{{caddyDebugLevel, "a DEBUG log level"}, {caddyPhpServer, "a plain php_server directive"}} {
		if n := len(e.re.FindAllStringIndex(cf, -1)); n != 1 {
			return fmt.Errorf("expected %s in Railpack's Caddyfile, found %d", e.what, n)
		}
	}
	cf = caddyDebugLevel.ReplaceAllString(cf, "${1}level INFO")
	cf = caddyPhpServer.ReplaceAllString(cf, phpServerBlock)
	assets[phpCaddyfileAsset] = cf
	return nil
}

// adjustPhpPlan applies adjustPhpServer to a Railpack PHP plan unless the
// repository brings its own Caddyfile, which is the app's to configure.
func adjustPhpPlan(p *rpplan.BuildPlan, ownCaddyfile bool, s *state.BuildSummary, sink LogSink) {
	if ownCaddyfile {
		sink.Line("plan", state.StreamStdout, "Using the app's Caddyfile as is, so its log level and HTTPS detection are up to it")
		return
	}
	if err := adjustPhpServer(p); err != nil {
		s.Warnings = append(s.Warnings, state.BuildWarning{Code: "php-server-not-adjusted", Message: state.Clip("launchroom could not adjust the PHP server, so it may log every request and generate http:// links: "+err.Error(), 1024)})
		return
	}
	sink.Line("plan", state.StreamStdout, phpServeAdjusted)
}
