package inventory

import (
	"encoding/json"
	"net/url"
	"regexp"
	"strings"

	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// spdxDoc is the part of an SPDX 2.3 JSON document the normaliser reads:
// every package with a package URL. Files, relationships and checksums are
// skipped by the decoder.
type spdxDoc struct {
	Packages []struct {
		Name         string `json:"name"`
		VersionInfo  string `json:"versionInfo"`
		ExternalRefs []struct {
			ReferenceCategory string `json:"referenceCategory"`
			ReferenceType     string `json:"referenceType"`
			ReferenceLocator  string `json:"referenceLocator"`
		} `json:"externalRefs"`
	} `json:"packages"`
}

// purl is a parsed package URL (https://github.com/package-url/purl-spec).
type purl struct {
	typ, namespace, name, version string
	qualifiers                    url.Values
}

func parsePURL(s string) (purl, bool) {
	rest, ok := strings.CutPrefix(s, "pkg:")
	if !ok {
		return purl{}, false
	}
	rest, _, _ = strings.Cut(rest, "#")
	rest, q, _ := strings.Cut(rest, "?")
	var p purl
	if q != "" {
		v, err := url.ParseQuery(q)
		if err != nil {
			return purl{}, false
		}
		p.qualifiers = v
	}
	if i := strings.LastIndexByte(rest, '@'); i > 0 && !strings.Contains(rest[i:], "/") {
		v, err := url.PathUnescape(rest[i+1:])
		if err != nil {
			return purl{}, false
		}
		p.version, rest = v, rest[:i]
	}
	segs := strings.Split(strings.Trim(rest, "/"), "/")
	if len(segs) < 2 {
		return purl{}, false
	}
	for i, s := range segs {
		u, err := url.PathUnescape(s)
		if err != nil || u == "" {
			return purl{}, false
		}
		segs[i] = u
	}
	p.typ = strings.ToLower(segs[0])
	p.name = segs[len(segs)-1]
	p.namespace = strings.Join(segs[1:len(segs)-1], "/")
	return p, true
}

// ecosystemOf maps a purl type; ok is false for types launchroom doesn't list.
func ecosystemOf(typ string) (state.PackageEcosystem, bool) {
	switch typ {
	case "npm":
		return state.EcosystemNpm, true
	case "pypi":
		return state.EcosystemPyPI, true
	case "golang":
		return state.EcosystemGo, true
	case "maven":
		return state.EcosystemMaven, true
	case "nuget":
		return state.EcosystemNuGet, true
	case "cargo":
		return state.EcosystemCargo, true
	case "gem":
		return state.EcosystemRubyGems, true
	case "composer":
		return state.EcosystemPackagist, true
	case "hex":
		return state.EcosystemHex, true
	case "pub":
		return state.EcosystemPub, true
	case "deb":
		return state.EcosystemDeb, true
	case "apk":
		return state.EcosystemApk, true
	case "rpm":
		return state.EcosystemRpm, true
	case "generic", "bitnami":
		// The scanner's binary classifiers (node, python, nginx, …) and
		// Bitnami's packaged runtimes.
		return state.EcosystemBinary, true
	}
	return "", false
}

// scanned is one component as the scanner reported it.
type scanned struct {
	eco     state.PackageEcosystem
	name    string
	version string
	purl    string
}

// parseSPDX reads the scanner's components. Packages without a package URL
// (the image itself, files) and of unsupported types are skipped; those
// whose name, version or purl fail their grammar are counted as dropped.
func parseSPDX(data []byte) ([]scanned, int, error) {
	// BuildKit's scanner protocol wraps the document in an in-toto
	// statement ({"predicateType": "https://spdx.dev/Document", "predicate": …}).
	var stmt struct {
		Predicate json.RawMessage `json:"predicate"`
	}
	if err := json.Unmarshal(data, &stmt); err != nil {
		return nil, 0, err
	}
	if len(stmt.Predicate) > 0 {
		data = stmt.Predicate
	}
	var doc spdxDoc
	if err := json.Unmarshal(data, &doc); err != nil {
		return nil, 0, err
	}
	var out []scanned
	dropped := 0
	for _, p := range doc.Packages {
		loc := ""
		for _, r := range p.ExternalRefs {
			if r.ReferenceType == "purl" {
				loc = r.ReferenceLocator
				break
			}
		}
		if loc == "" {
			continue
		}
		u, ok := parsePURL(loc)
		if !ok {
			dropped++
			continue
		}
		eco, ok := ecosystemOf(u.typ)
		if !ok {
			continue
		}
		if eco == state.EcosystemApk && strings.HasPrefix(u.name, ".") {
			continue // a virtual package (apk add --virtual): a name for a group, not a package
		}
		c := scanned{eco: eco, name: componentName(eco, u, p.Name), version: u.version, purl: loc}
		if c.version == "" {
			c.version = p.VersionInfo
		}
		if eco == state.EcosystemGo && c.name == "stdlib" {
			c.version = strings.TrimPrefix(c.version, "go")
		}
		if state.PackageName(c.name).Validate() != nil || state.PackageVersion(c.version).Validate() != nil {
			dropped++
			continue
		}
		if state.PackageURL(c.purl).Validate() != nil {
			dropped++
			continue
		}
		out = append(out, c)
	}
	return out, dropped, nil
}

// componentName is the name as the ecosystem writes it.
func componentName(eco state.PackageEcosystem, u purl, spdxName string) string {
	switch eco {
	case state.EcosystemNpm, state.EcosystemGo, state.EcosystemPackagist:
		if u.namespace != "" {
			return u.namespace + "/" + u.name
		}
	case state.EcosystemMaven:
		if u.namespace != "" {
			return u.namespace + ":" + u.name
		}
	case state.EcosystemPyPI, state.EcosystemNuGet, state.EcosystemCargo, state.EcosystemRubyGems, state.EcosystemHex,
		state.EcosystemPub, state.EcosystemDeb, state.EcosystemApk, state.EcosystemRpm, state.EcosystemBinary:
	}
	if u.name != "" {
		return u.name
	}
	return spdxName
}

// exposed is the list of OS packages and binaries that process requests or
// run the app's code (docs/22 §5.3): they are runtimes, not OS libraries.
var exposed = regexp.MustCompile(`^(?:nodejs(?:-current)?|node|bun|deno|` +
	`python3?(?:\.[0-9]+)?|libpython3\.[0-9]+|` +
	`php(?:8\.?[0-9]+)?(?:-(?:cli|fpm|common))?|php-fpm|frankenphp|` +
	`ruby(?:3\.[0-9]+)?|libruby3\.[0-9]+|` +
	`openjdk-[0-9]+-(?:jre|jdk)[a-z-]*|java|aspnetcore-runtime-[0-9.]+|dotnet-runtime-[0-9.]+|dotnet|` +
	`nginx|apache2|httpd|caddy)$`)

// jsRuntimes are the names that mean the image can run JavaScript on the server.
var jsRuntimes = map[string]bool{"node": true, "nodejs": true, "nodejs-current": true, "bun": true, "deno": true}
