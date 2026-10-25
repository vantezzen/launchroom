package inventory

import (
	"bufio"
	"bytes"
	"cmp"
	"slices"
	"strings"

	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// normalize turns the scanner's components and the source facts into the
// result's components (docs/22 §3.3, §3.4): kind, scope, presence and
// direct by evidence only, merged, ordered, capped.
func normalize(comps []scanned, src *sourceFacts, next nextProbe, notes *[]string) (out []state.InventoryComponent, truncated bool) {
	jsRuntime := false
	for _, c := range comps {
		if (c.eco == state.EcosystemBinary || c.eco == state.EcosystemDeb || c.eco == state.EcosystemApk || c.eco == state.EcosystemRpm) && jsRuntimes[c.name] {
			jsRuntime = true
		}
	}
	type k struct {
		eco           state.PackageEcosystem
		name, version string
	}
	seen := map[k]bool{}
	installedNpm := map[string]bool{}
	for _, c := range comps {
		ck := k{c.eco, c.name, c.version}
		if seen[ck] {
			continue
		}
		seen[ck] = true
		ic := state.InventoryComponent{
			Ecosystem: c.eco, Name: state.PackageName(c.name), Version: state.PackageVersion(c.version), PURL: state.PackageURL(c.purl),
			Presence: state.PresenceInstalled, Scope: state.ScopeRuntime,
		}
		switch c.eco {
		case state.EcosystemDeb, state.EcosystemApk, state.EcosystemRpm:
			ic.Kind = state.ComponentOS
			if exposed.MatchString(c.name) {
				ic.Kind = state.ComponentRuntime
			}
		case state.EcosystemBinary:
			ic.Kind = state.ComponentRuntime
		case state.EcosystemGo:
			ic.Kind = state.ComponentLibrary
			if c.name == "stdlib" {
				ic.Kind = state.ComponentRuntime
			} else if src != nil && src.goDirect[c.name] {
				ic.Direct = true
			}
		case state.EcosystemNpm:
			ic.Kind = state.ComponentLibrary
			installedNpm[key(c.name, c.version)] = true
			ic.Scope = state.ScopeUnknown
			if f := npmOf(src); f != nil {
				ic.Direct = f.direct[c.name]
				switch {
				case f.prod[key(c.name, c.version)]:
					ic.Scope = state.ScopeRuntime
				case f.dev[key(c.name, c.version)]:
					ic.Scope = state.ScopeDev
				}
			}
		case state.EcosystemPyPI:
			ic.Kind = state.ComponentLibrary
			ic.Scope, ic.Direct = nameScope(pypiOf(src), pyName(c.name))
		case state.EcosystemPackagist:
			ic.Kind = state.ComponentLibrary
			ic.Scope, ic.Direct = nameScope(composerOf(src), strings.ToLower(c.name))
		case state.EcosystemRubyGems:
			ic.Kind = state.ComponentLibrary
			ic.Scope, ic.Direct = nameScope(gemsOf(src), strings.ToLower(c.name))
		case state.EcosystemMaven, state.EcosystemNuGet, state.EcosystemCargo, state.EcosystemHex, state.EcosystemPub:
			// Installed artefacts: runtime with the build's source, else unknown (docs/22 §3.3).
			ic.Kind = state.ComponentLibrary
			if src == nil {
				ic.Scope = state.ScopeUnknown
			}
		}
		out = append(out, ic)
	}
	// Production packages of the lockfile that aren't installed: bundled.
	if f := npmOf(src); f != nil {
		for _, nv := range f.prodList {
			ck := k{state.EcosystemNpm, nv.name, nv.version}
			if seen[ck] || installedNpm[key(nv.name, nv.version)] {
				continue
			}
			if state.PackageName(nv.name).Validate() != nil || state.PackageVersion(nv.version).Validate() != nil {
				continue
			}
			seen[ck] = true
			ic := state.InventoryComponent{
				Ecosystem: state.EcosystemNpm, Name: state.PackageName(nv.name), Version: state.PackageVersion(nv.version),
				Kind: state.ComponentLibrary, Presence: state.PresenceBundled, Scope: state.ScopeUnknown, Direct: f.direct[nv.name],
			}
			if p := npmPURL(nv.name, nv.version); state.PackageURL(p).Validate() == nil {
				ic.PURL = state.PackageURL(p)
			}
			switch {
			case next.server[nv.name]:
				ic.Scope = state.ScopeRuntime
			case next.static[nv.name] || !jsRuntime:
				ic.Scope = state.ScopeClient
			}
			out = append(out, ic)
		}
		if next.found && next.stopped {
			*notes = append(*notes, "Only the first 64 MiB of the Next.js output were searched for bundled packages")
		}
	}

	slices.SortFunc(out, func(a, b state.InventoryComponent) int {
		return cmp.Or(cmp.Compare(order(a), order(b)), cmp.Compare(a.Ecosystem, b.Ecosystem), cmp.Compare(a.Name, b.Name), cmp.Compare(a.Version, b.Version))
	})
	if len(out) > state.MaxInventoryComponents {
		out, truncated = out[:state.MaxInventoryComponents], true
	}
	return out, truncated
}

// order is the display order (docs/22 §3.4): runtimes, direct libraries,
// other runtime libraries, unknown, client, dev, OS packages.
func order(c state.InventoryComponent) int {
	switch {
	case c.Kind == state.ComponentRuntime:
		return 0
	case c.Kind == state.ComponentOS:
		return 6
	case c.Direct && c.Scope == state.ScopeRuntime:
		return 1
	case c.Scope == state.ScopeRuntime:
		return 2
	case c.Scope == state.ScopeUnknown:
		return 3
	case c.Scope == state.ScopeClient:
		return 4
	}
	return 5
}

func npmOf(s *sourceFacts) *npmFacts {
	if s == nil {
		return nil
	}
	return s.npm
}

func pypiOf(s *sourceFacts) *nameFacts {
	if s == nil {
		return nil
	}
	return s.pypi
}

func composerOf(s *sourceFacts) *nameFacts {
	if s == nil {
		return nil
	}
	return s.composer
}

func gemsOf(s *sourceFacts) *nameFacts {
	if s == nil {
		return nil
	}
	return s.gems
}

// nameScope is the scope of an installed package of a name-based ecosystem:
// unknown without a manifest, dev when only in development groups, runtime
// otherwise (docs/22 §3.3).
func nameScope(f *nameFacts, name string) (state.DependencyScope, bool) {
	if f == nil {
		return state.ScopeUnknown, false
	}
	if f.devOnly(name) {
		return state.ScopeDev, f.direct[name]
	}
	return state.ScopeRuntime, f.direct[name]
}

func npmPURL(name, version string) string {
	if strings.HasPrefix(name, "@") {
		return "pkg:npm/%40" + strings.TrimPrefix(name, "@") + "@" + version
	}
	return "pkg:npm/" + name + "@" + version
}

// parseOSRelease reads ID and VERSION_ID of an os-release file.
func parseOSRelease(b []byte) *state.ImageDistro {
	var id, ver string
	sc := bufio.NewScanner(bytes.NewReader(b))
	for sc.Scan() {
		k, v, ok := strings.Cut(sc.Text(), "=")
		if !ok {
			continue
		}
		v = strings.Trim(strings.TrimSpace(v), `"'`)
		switch strings.TrimSpace(k) {
		case "ID":
			id = v
		case "VERSION_ID":
			ver = v
		}
	}
	d := &state.ImageDistro{ID: state.DistroID(id)}
	if d.ID.Validate() != nil {
		return nil
	}
	if state.DistroVersion(ver).Validate() == nil {
		d.VersionID = state.DistroVersion(ver)
	}
	return d
}
