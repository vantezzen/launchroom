package build

import (
	"encoding/json"
	"fmt"
	"io"
	"io/fs"
	"maps"
	"regexp"
	"slices"
	"strconv"
	"strings"

	rpplan "github.com/railwayapp/railpack/core/plan"

	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// maxComposerLockBytes bounds the composer.lock launchroomd reads: real lock
// files are a few MB, far above the 256 KiB of the preset facts.
const maxComposerLockBytes = 16 << 20

// phpBuiltinExtensions are compiled into the FrankenPHP images Railpack
// builds PHP apps on (php -m of dunglas/frankenphp:php8.x-trixie), so a
// dependency asking for one needs nothing installed.
var phpBuiltinExtensions = []string{
	"core", "ctype", "curl", "date", "dom", "fileinfo", "filter", "hash", "iconv", "json", "libxml", "mbstring",
	"mysqlnd", "openssl", "pcre", "pdo", "pdo_sqlite", "phar", "posix", "random", "readline", "reflection",
	"session", "simplexml", "sodium", "spl", "sqlite3", "standard", "tokenizer", "xml", "xmlreader", "xmlwriter", "zlib",
}

// phpInstallableExtensions are the extensions install-php-extensions (which
// Railpack's PHP provider runs) can add. A name outside this list is left to
// composer, which then fails with "requires ext-…" and the php-extension hint.
var phpInstallableExtensions = []string{
	"amqp", "apcu", "ast", "bcmath", "bz2", "calendar", "dba", "decimal", "ds", "enchant", "event", "excimer", "exif",
	"ffi", "gd", "gettext", "gmagick", "gmp", "gnupg", "grpc", "igbinary", "imagick", "imap", "inotify", "intl", "ldap",
	"lz4", "mailparse", "maxminddb", "memcache", "memcached", "mongodb", "msgpack", "mysqli", "oauth", "odbc", "opcache",
	"opentelemetry", "parallel", "pcntl", "pcov", "pdo_dblib", "pdo_firebird", "pdo_mysql", "pdo_odbc", "pdo_pgsql",
	"pdo_sqlsrv", "pgsql", "protobuf", "pspell", "rdkafka", "redis", "relay", "shmop", "snmp", "soap", "sockets",
	"sqlsrv", "ssh2", "swoole", "sysvmsg", "sysvsem", "sysvshm", "tidy", "timezonedb", "uuid", "vips", "xdebug", "xsl",
	"yaml", "zip", "zstd",
}

type composerLock struct {
	Packages    []composerLockPackage `json:"packages"`
	PackagesDev []composerLockPackage `json:"packages-dev"`
}

type composerLockPackage struct {
	Name    string            `json:"name"`
	Require map[string]string `json:"require"`
}

type composerManifest struct {
	Name       string            `json:"name"`
	Require    map[string]string `json:"require"`
	RequireDev map[string]string `json:"require-dev"`
	Config     struct {
		Platform map[string]any `json:"platform"`
	} `json:"config"`
}

// phpDependencyExtensions reads composer.lock (packages and packages-dev:
// Railpack installs dev dependencies too) and returns the extensions the
// dependencies require that the image lacks, each with the first package
// that needs it (the app's own require-dev counts under its name), plus the
// names launchroom can't install. Extensions the app's own composer.json
// requires are left to Railpack, which installs them itself; names
// composer.json fakes through config.platform are skipped.
func phpDependencyExtensions(fsys fs.FS) (add []state.PhpDependencyExtension, unknown []string) {
	add, unknown, _ = readComposerLock(fsys)
	return add, unknown
}

// phpFloor is the lowest PHP version every locked package accepts.
type phpFloor struct {
	Major, Minor int
	// For is the package with the highest minimum.
	For string
}

func (f phpFloor) String() string { return strconv.Itoa(f.Major) + "." + strconv.Itoa(f.Minor) }

func (f phpFloor) above(major, minor int) bool {
	return f.Major > major || f.Major == major && f.Minor > minor
}

var reVersionBound = regexp.MustCompile(`^(?:\^|~|>=|>|==|=|v)?\s*(\d+)\.(\d+)`)

// constraintFloor is the lowest major.minor a composer constraint allows
// ("^8.2", "~8.3.0 || ~8.4.0", ">=8.4.1"); ok=false when it has no lower bound.
func constraintFloor(c string) (major, minor int, ok bool) {
	for _, alt := range strings.Split(c, "||") {
		alt = strings.TrimSpace(strings.ReplaceAll(alt, "|", ""))
		// A range ("8.2 - 8.4", ">=8.2 <9") starts with its lower bound.
		m := reVersionBound.FindStringSubmatch(alt)
		if m == nil {
			return 0, 0, false // "*", "<9": no lower bound for this alternative
		}
		ma, _ := strconv.Atoi(m[1])
		mi, _ := strconv.Atoi(m[2])
		if !ok || ma < major || ma == major && mi < minor {
			major, minor, ok = ma, mi, true
		}
	}
	return major, minor, ok
}

// readComposerLock reads composer.json and composer.lock once: the
// dependency extensions (see phpDependencyExtensions) and the PHP version
// floor of the locked packages (zero when composer.json pins
// config.platform.php, which is the app's own decision).
func readComposerLock(fsys fs.FS) (add []state.PhpDependencyExtension, unknown []string, floor phpFloor) {
	var app composerManifest
	if raw, err := readCapped(fsys, "composer.json", maxManifestBytes); err == nil {
		_ = json.Unmarshal(raw, &app)
	}
	skip := map[string]bool{}
	for _, n := range phpBuiltinExtensions {
		skip[n] = true
	}
	for name := range app.Require {
		if ext, ok := strings.CutPrefix(strings.ToLower(name), "ext-"); ok {
			skip[ext] = true
		}
	}
	for name := range app.Config.Platform {
		if ext, ok := strings.CutPrefix(strings.ToLower(name), "ext-"); ok {
			skip[ext] = true
		}
	}

	var lock composerLock
	f, err := fsys.Open("composer.lock")
	if err == nil {
		defer func() { _ = f.Close() }()
		if st, err := f.Stat(); err == nil && st.Mode().IsRegular() {
			_ = json.NewDecoder(io.LimitReader(f, maxComposerLockBytes)).Decode(&lock)
		}
	}
	if _, pinned := app.Config.Platform["php"]; !pinned {
		for _, p := range lock.Packages {
			if ma, mi, ok := constraintFloor(p.Require["php"]); ok && (phpFloor{ma, mi, ""}).above(floor.Major, floor.Minor) {
				floor = phpFloor{Major: ma, Minor: mi, For: p.Name}
			}
		}
	}
	type need struct{ ext, pkg string }
	var needs []need
	for _, p := range slices.Concat(lock.Packages, lock.PackagesDev) {
		for _, name := range slices.Sorted(maps.Keys(p.Require)) {
			if ext, ok := strings.CutPrefix(strings.ToLower(name), "ext-"); ok {
				needs = append(needs, need{ext, p.Name})
			}
		}
	}
	for _, name := range slices.Sorted(maps.Keys(app.RequireDev)) {
		if ext, ok := strings.CutPrefix(strings.ToLower(name), "ext-"); ok {
			needs = append(needs, need{ext, app.Name})
		}
	}

	seen := map[string]bool{}
	for _, n := range needs {
		if seen[n.ext] || skip[n.ext] {
			continue
		}
		seen[n.ext] = true
		if state.PhpExtensionIdent(n.ext).Validate() != nil {
			continue
		}
		if !slices.Contains(phpInstallableExtensions, n.ext) {
			unknown = append(unknown, n.ext+" ("+state.Clip(n.pkg, 128)+")")
			continue
		}
		pkg := state.ComposerPackage(strings.ToLower(n.pkg))
		if pkg.Validate() != nil {
			continue
		}
		if len(add) < 32 {
			add = append(add, state.PhpDependencyExtension{Name: state.PhpExtensionIdent(n.ext), For: pkg})
		}
	}
	return add, unknown, floor
}

// withDependencyExtensions adds the extensions to the Railpack environment
// after any already there (the user's RAILPACK_PHP_EXTENSIONS, connected
// databases).
func withDependencyExtensions(env map[string]string, xs []state.PhpDependencyExtension) {
	names := strings.FieldsFunc(env[railpackPhpExtensions], func(r rune) bool { return r == ',' || r == ' ' })
	for _, x := range xs {
		if !slices.Contains(names, string(x.Name)) {
			names = append(names, string(x.Name))
		}
	}
	if len(names) > 0 {
		env[railpackPhpExtensions] = strings.Join(names, ",")
	}
}

// describeDependencyExtensions renders "intl (filament/support), pcntl (laravel/framework)".
func describeDependencyExtensions(xs []state.PhpDependencyExtension) string {
	parts := make([]string, 0, len(xs))
	for _, x := range xs {
		parts = append(parts, string(x.Name)+" ("+string(x.For)+")")
	}
	return strings.Join(parts, ", ")
}

// mergeDependencyExtensions appends the extensions of b that a doesn't name.
func mergeDependencyExtensions(a, b []state.PhpDependencyExtension) []state.PhpDependencyExtension {
	out := slices.Clone(a)
	for _, x := range b {
		if !slices.ContainsFunc(out, func(have state.PhpDependencyExtension) bool { return have.Name == x.Name }) && len(out) < 32 {
			out = append(out, x)
		}
	}
	return out
}

// frankenPHPImage is the image prefix of Railpack's PHP provider
// (dunglas/frankenphp:php<version>-trixie).
const frankenPHPImage = "dunglas/frankenphp:php"

// raisePhpVersion moves a Railpack PHP plan to the PHP version the lock
// file needs. Railpack takes the version from composer.json's require.php
// ("^8.2" means 8.2), while the lock was resolved on a newer PHP (Laravel 12
// with Symfony 8 needs 8.4), so composer install would fail.
func raisePhpVersion(p *rpplan.BuildPlan, floor phpFloor) error {
	n := 0
	for i := range p.Steps {
		for j, l := range p.Steps[i].Inputs {
			if strings.HasPrefix(l.Image, frankenPHPImage) {
				p.Steps[i].Inputs[j].Image = fmt.Sprintf("%s%s-trixie", frankenPHPImage, floor)
				n++
			}
		}
	}
	if n == 0 {
		return fmt.Errorf("the plan has no %s… image", frankenPHPImage)
	}
	return nil
}
