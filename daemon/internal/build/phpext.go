package build

import (
	"slices"
	"strings"

	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// railpackPhpExtensions is Railpack's PHP provider setting for extra
// extensions (RAILPACK_PHP_EXTENSIONS, comma or space separated). The PHP
// provider installs them with install-php-extensions next to the ext-*
// entries of composer.json; other providers ignore it.
const railpackPhpExtensions = "RAILPACK_PHP_EXTENSIONS"

// withPhpExtensions adds the spec's PHP extensions to the Railpack
// environment, after any the user set in railpackEnv (docs/04 §3.1). The
// names come from a validated allow-list, so they never carry separators.
func withPhpExtensions(env map[string]string, xs []state.PhpExtension) {
	if len(xs) == 0 {
		return
	}
	names := strings.FieldsFunc(env[railpackPhpExtensions], func(r rune) bool { return r == ',' || r == ' ' })
	for _, x := range xs {
		if !slices.Contains(names, string(x.Name)) {
			names = append(names, string(x.Name))
		}
	}
	env[railpackPhpExtensions] = strings.Join(names, ",")
}

// describePhpExtensions renders "pdo_pgsql (for postgres), redis (for redis)".
func describePhpExtensions(xs []state.PhpExtension) string {
	parts := make([]string, 0, len(xs))
	for _, x := range xs {
		parts = append(parts, string(x.Name)+" (for "+string(x.For)+")")
	}
	return strings.Join(parts, ", ")
}

// dockerfilePhpWarning is the hint for Dockerfile builds of a PHP app whose
// connected databases need extensions: launchroom never edits a Dockerfile.
func dockerfilePhpWarning(xs []state.PhpExtension, phpApp bool) (state.BuildWarning, bool) {
	if len(xs) == 0 || !phpApp {
		return state.BuildWarning{}, false
	}
	return state.BuildWarning{
		Code:    "dockerfile-php-extensions",
		Message: clipText("This Dockerfile may need the PHP extensions "+describePhpExtensions(xs)+" for the connected databases. launchroom adds them only to Railpack builds; install them in the Dockerfile (for example with install-php-extensions).", 1024),
	}, true
}
