package build

import (
	"slices"

	"github.com/vantezzen/launchroom/daemon/internal/build/presets"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// dockerfileRuntime applies the runtime part of the framework preset to a
// Dockerfile build's summary (scope runtime, D6: launchroom never changes
// the user's Dockerfile, but the container still gets the framework's
// variables, health path and secrets) and warns PHP apps that their server
// must trust launchroom's edge itself.
func dockerfileRuntime(f presets.Facts, bs state.BuilderSpec, disabled []string, s *state.BuildSummary, sink LogSink) {
	res, ok := presets.ResolveRuntime(f, string(bs.Preset), disabled)
	if ok && res != nil {
		s.Warnings = append(s.Warnings, res.Warnings...)
	}
	if ok && res != nil && len(res.Applied.Decisions) > 0 {
		s.Presets = []state.AppliedPreset{res.Applied}
		s.RuntimeEnv = res.RuntimeEnv
		s.Processes = res.Processes
		s.GeneratedSecrets = res.GeneratedSecrets
		s.EnvAliases = res.EnvAliases
		s.RequiredSecrets = res.RequiredSecrets
		s.VolumeSuggestions = res.VolumeSuggestions
		if res.HealthPath != "" {
			s.HealthPath = state.HTTPPath(res.HealthPath)
		}
		if s.Port == nil && res.Port > 0 {
			p := state.Port(res.Port)
			s.Port = &p
		}
		if res.Framework != "" {
			s.Framework = ident(res.Framework)
		}
		sink.Line("plan", state.StreamStdout, "Applied the runtime settings of preset "+string(res.Applied.Name)+" to your Dockerfile's container")
	}
	if f.Files["composer.json"] && !slices.Contains(disabled, "dockerfile-php-proxy") {
		s.Warnings = append(s.Warnings, dockerfilePhpProxyWarning(f))
	}
}

// dockerfilePhpProxyWarning tells a Dockerfile-built PHP app how to trust
// launchroom's edge, since launchroom adjusts only Railpack's PHP server: the
// exact lines for Laravel, or for the web server in front of PHP.
func dockerfilePhpProxyWarning(f presets.Facts) state.BuildWarning {
	msg := "launchroom adjusts only Railpack's PHP server, so this Dockerfile's app sees http:// and the edge's address unless it trusts the proxy. "
	if f.ComposerRequire["laravel/framework"] {
		msg += "In bootstrap/app.php, inside ->withMiddleware(function (Middleware $middleware) { … }), add: $middleware->trustProxies(at: ['10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16', '100.64.0.0/10']);"
	} else {
		msg += "With FrankenPHP or Caddy, add to the global options: servers { trusted_proxies static private_ranges 100.64.0.0/10 }. With nginx and PHP-FPM, add: map $http_x_forwarded_proto $fastcgi_https { https on; default ''; } and fastcgi_param HTTPS $fastcgi_https;"
	}
	return state.BuildWarning{Code: "dockerfile-php-proxy", Message: state.Clip(msg, 1024)}
}
