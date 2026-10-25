package state

import (
	"regexp"
	"strings"
	"unicode/utf8"
)

// Image inventory (docs/22 §3, docs/03 §13.1): an "inventory" task lists the
// packages and runtimes of one image. launchroomd scans the image with the
// pinned SBOM scanner inside BuildKit and, with a source, reads the build's
// lockfiles to tell runtime from development dependencies. The dashboard
// matches the components against public advisory data.

// Limits of inventory documents.
const (
	MaxInventoryComponents = 20000
	MaxInventoryLockfiles  = 32
	MaxInventoryNotes      = 16
	maxInventoryNoteLen    = 256
	maxLockfilePackages    = 100000
)

var (
	rePackageName    = regexp.MustCompile(`^[A-Za-z0-9@_][A-Za-z0-9@._/:+~-]{0,255}$`)
	rePackageVersion = regexp.MustCompile(`^[A-Za-z0-9][A-Za-z0-9._+~:^-]{0,127}$`)
	rePackageURL     = regexp.MustCompile(`^pkg:[a-z][a-z0-9.+-]{0,31}/[!-~]{1,1000}$`)
	reDistroID       = regexp.MustCompile(`^[a-z0-9][a-z0-9._-]{0,31}$`)
	reDistroVersion  = regexp.MustCompile(`^[A-Za-z0-9][A-Za-z0-9._-]{0,31}$`)

	packageEcosystems = []string{
		string(EcosystemNpm), string(EcosystemPyPI), string(EcosystemGo), string(EcosystemMaven),
		string(EcosystemNuGet), string(EcosystemCargo), string(EcosystemRubyGems), string(EcosystemPackagist),
		string(EcosystemHex), string(EcosystemPub), string(EcosystemDeb), string(EcosystemApk),
		string(EcosystemRpm), string(EcosystemBinary),
	}
	componentKinds     = []string{string(ComponentOS), string(ComponentLibrary), string(ComponentRuntime)}
	dependencyScopes   = []string{string(ScopeRuntime), string(ScopeDev), string(ScopeClient), string(ScopeUnknown)}
	componentPresences = []string{string(PresenceInstalled), string(PresenceBundled)}
	lockfileKinds      = []string{
		string(LockfileNpm), string(LockfilePnpm), string(LockfileYarn), string(LockfileBun),
		string(LockfileComposer), string(LockfilePoetry), string(LockfileUv), string(LockfilePipfile),
		string(LockfileRequirements), string(LockfilePyproject), string(LockfileGemfile), string(LockfileGoMod),
	}
)

// InventorySpec asks for the inventory of one image (task "inventory",
// daemon feature "inventory"). Leased by build servers whose platform
// matches, like builds.
type InventorySpec struct {
	// Image is the digest-pinned image to scan: a built image
	// ("<registry>/apps/<service>@sha256:…") or a mirrored one
	// ("<registry>/mirror/…@sha256:…"). For a multi-platform index the
	// manifest of Platform is scanned.
	Image    ImageRef `json:"image"`
	Platform Platform `json:"platform"`
	// Service is required for images in an apps/ repository and must be
	// that repository's service: the task's registry pull token covers
	// exactly it (docs/03 §4.5). Omitted for mirrored images.
	Service ServiceID `json:"service,omitzero"`
	// Source is the commit the image was built from. With it launchroomd
	// clones the repository and reads manifests and lockfiles to set
	// dependency scopes (docs/22 §3.3); without it language packages have
	// scope "unknown".
	Source *InventorySource `json:"source,omitzero" jsonschema:"nullable"`
}

// InventorySource is the repository and commit an image was built from.
type InventorySource struct {
	Repo RepoSpec `json:"repo"`
	// CloneToken / SSHKey: sealed clone credential, exactly as for builds
	// (TaskToken purpose "clone" / "ssh_key", sealed at lease time).
	CloneToken *Sealed `json:"cloneToken,omitzero" jsonschema:"nullable"`
	SSHKey     *Sealed `json:"sshKey,omitzero" jsonschema:"nullable"`
	// RootDir is the repo-relative app directory. Omitted: repo root.
	RootDir RelPath `json:"rootDir,omitzero"`
}

// TaskType implements TaskSpec.
func (*InventorySpec) TaskType() TaskType { return TaskInventory }

// Validate implements TaskSpec.
func (s *InventorySpec) Validate() error {
	var e errs
	e.check("image", s.Image)
	e.check("platform", s.Platform)
	if s.Image.Validate() == nil {
		_, repo, _ := strings.Cut(s.Image.Repository(), "/")
		switch {
		case strings.HasPrefix(repo, "apps/"):
			switch {
			case s.Service == "":
				e.addf("service", "is required for images in an apps/ repository")
			case s.Service.Validate() != nil:
				e.check("service", s.Service)
			case RegistryRepository(repo) != AppRepository(s.Service):
				e.addf("image", "must be in the service's repository %s", AppRepository(s.Service))
			}
		case strings.HasPrefix(repo, "mirror/"):
			if s.Service != "" {
				e.addf("service", "is only valid for images in an apps/ repository")
			}
		default:
			e.addf("image", "must be in the apps/ or mirror/ repositories of the registry")
		}
	}
	if s.Source != nil {
		validateRepoAccess(&e, "source.", s.Source.Repo, s.Source.CloneToken, s.Source.SSHKey)
		if s.Source.RootDir != "" {
			e.check("source.rootDir", s.Source.RootDir)
		}
	}
	return e.err()
}

// InventoryResult is TaskCompletion.inventory of a succeeded inventory task.
type InventoryResult struct {
	// Image and Platform repeat the spec's.
	Image    ImageRef `json:"image"`
	Platform Platform `json:"platform"`
	// Generator is the SBOM scanner image that read the image.
	Generator ImageRef `json:"generator"`
	// Distro is the image's /etc/os-release (omitted without one, e.g. distroless or scratch).
	Distro *ImageDistro `json:"distro,omitzero" jsonschema:"nullable"`
	// SourceAnalyzed is true when the spec's source was cloned and its
	// manifests and lockfiles were read.
	SourceAnalyzed bool `json:"sourceAnalyzed"`
	// Lockfiles are the manifests and lockfiles that set scopes.
	Lockfiles []InventoryLockfile `json:"lockfiles,omitzero" jsonschema:"nullable,maxItems=32"`
	// Components are the normalised components in display order (docs/22
	// §3.4): runtimes, direct libraries, other runtime libraries, unknown,
	// client, dev, OS packages. Unique by (ecosystem, name, version).
	Components []InventoryComponent `json:"components" jsonschema:"maxItems=20000"`
	// Dropped counts components whose name, version or purl failed the grammar.
	Dropped int `json:"dropped,omitzero" jsonschema:"minimum=0"`
	// Truncated is true when there were more than 20,000 components.
	Truncated bool `json:"truncated,omitzero"`
	// Notes are short, secret-free remarks for the UI ("No lockfile found, …").
	Notes      []string `json:"notes,omitzero" jsonschema:"nullable,maxItems=16"`
	DurationMs int64    `json:"durationMs" jsonschema:"minimum=0"`
}

// ImageDistro is an image's distribution from /etc/os-release.
type ImageDistro struct {
	// ID is os-release ID ("debian", "alpine", "ubuntu").
	ID DistroID `json:"id"`
	// VersionID is os-release VERSION_ID ("12", "3.20.3"); omitted when absent.
	VersionID DistroVersion `json:"versionId,omitzero"`
}

// InventoryLockfile is one manifest or lockfile read from the source.
type InventoryLockfile struct {
	Path RelPath      `json:"path"`
	Kind LockfileKind `json:"kind"`
	// Packages is the number of packages it lists.
	Packages int `json:"packages" jsonschema:"minimum=0,maximum=100000"`
}

// InventoryComponent is one package, library or runtime of an image.
type InventoryComponent struct {
	Ecosystem PackageEcosystem `json:"ecosystem"`
	// Name as the ecosystem writes it (npm "@scope/name", Maven
	// "group:artifact", Go module path or "stdlib", Debian binary package).
	Name    PackageName    `json:"name"`
	Version PackageVersion `json:"version"`
	// PURL is the scanner's package URL with its qualifiers (distro,
	// upstream source package, arch); omitted when the scanner had none.
	PURL     PackageURL        `json:"purl,omitzero"`
	Kind     ComponentKind     `json:"kind"`
	Scope    DependencyScope   `json:"scope"`
	Presence ComponentPresence `json:"presence"`
	// Direct: named in the app's own manifest.
	Direct bool `json:"direct,omitzero"`
}

func (r *InventoryResult) validate(e *errs) {
	const p = "inventory"
	e.check(p+".image", r.Image)
	e.check(p+".platform", r.Platform)
	e.check(p+".generator", r.Generator)
	if d := r.Distro; d != nil {
		e.check(p+".distro.id", d.ID)
		if d.VersionID != "" {
			e.check(p+".distro.versionId", d.VersionID)
		}
	}
	checkCount(e, p+".lockfiles", len(r.Lockfiles), 0, MaxInventoryLockfiles)
	paths := make([]RelPath, 0, len(r.Lockfiles))
	for i, l := range r.Lockfiles {
		q := idx(p+".lockfiles", i)
		e.check(q+".path", l.Path)
		e.check(q+".kind", l.Kind)
		if l.Packages < 0 || l.Packages > maxLockfilePackages {
			e.addf(q+".packages", "must be between 0 and %d", maxLockfilePackages)
		}
		paths = append(paths, l.Path)
	}
	unique(e, p+".lockfiles[].path", paths)
	if len(r.Lockfiles) > 0 && !r.SourceAnalyzed {
		e.addf(p+".lockfiles", "are only valid when the source was analysed")
	}
	checkCount(e, p+".components", len(r.Components), 0, MaxInventoryComponents)
	type key struct {
		eco           PackageEcosystem
		name, version string
	}
	keys := make([]key, 0, len(r.Components))
	for i, c := range r.Components {
		c.validate(e, idx(p+".components", i))
		keys = append(keys, key{c.Ecosystem, string(c.Name), string(c.Version)})
	}
	unique(e, p+".components[].(ecosystem,name,version)", keys)
	if r.Dropped < 0 {
		e.addf(p+".dropped", "must be >= 0")
	}
	if r.Truncated && len(r.Components) < MaxInventoryComponents {
		e.addf(p+".truncated", "is only valid with %d components", MaxInventoryComponents)
	}
	checkCount(e, p+".notes", len(r.Notes), 0, MaxInventoryNotes)
	for i, n := range r.Notes {
		checkPlainText(e, idx(p+".notes", i), n, maxInventoryNoteLen)
	}
	if r.DurationMs < 0 {
		e.addf(p+".durationMs", "must be >= 0")
	}
}

func (c InventoryComponent) validate(e *errs, p string) {
	e.check(p+".ecosystem", c.Ecosystem)
	e.check(p+".name", c.Name)
	e.check(p+".version", c.Version)
	if c.PURL != "" {
		e.check(p+".purl", c.PURL)
	}
	e.check(p+".kind", c.Kind)
	e.check(p+".scope", c.Scope)
	e.check(p+".presence", c.Presence)
	osPkg := c.Ecosystem == EcosystemDeb || c.Ecosystem == EcosystemApk || c.Ecosystem == EcosystemRpm
	switch c.Kind {
	case ComponentOS:
		if !osPkg {
			e.addf(p+".kind", "os is only valid for deb, apk and rpm packages")
		}
	case ComponentLibrary:
		if osPkg || c.Ecosystem == EcosystemBinary {
			e.addf(p+".kind", "library is only valid for language packages")
		}
	case ComponentRuntime:
		// Runtimes are binaries, OS packages of the exposed list or Go's stdlib.
	}
	if c.Presence == PresenceBundled && c.Ecosystem != EcosystemNpm {
		e.addf(p+".presence", "bundled is only valid for npm packages")
	}
	if (c.Scope == ScopeDev || c.Scope == ScopeClient) && c.Kind != ComponentLibrary {
		e.addf(p+".scope", "dev and client are only valid for libraries")
	}
	if c.Direct && c.Kind != ComponentLibrary {
		e.addf(p+".direct", "is only valid for libraries")
	}
}

// checkPlainText bounds a display string and refuses control characters and invalid UTF-8.
func checkPlainText(e *errs, p, s string, maxLen int) {
	checkText(e, p, s, maxLen)
	if !utf8.ValidString(s) {
		e.addf(p, "must be valid UTF-8")
		return
	}
	for _, r := range s {
		if r < 0x20 || r == 0x7f {
			e.addf(p, "contains control characters")
			return
		}
	}
}

// PackageEcosystem is where a component comes from.
type PackageEcosystem string

// Ecosystems (docs/22 §3.4).
const (
	EcosystemNpm       PackageEcosystem = "npm"
	EcosystemPyPI      PackageEcosystem = "pypi"
	EcosystemGo        PackageEcosystem = "go"
	EcosystemMaven     PackageEcosystem = "maven"
	EcosystemNuGet     PackageEcosystem = "nuget"
	EcosystemCargo     PackageEcosystem = "cargo"
	EcosystemRubyGems  PackageEcosystem = "rubygems"
	EcosystemPackagist PackageEcosystem = "packagist"
	EcosystemHex       PackageEcosystem = "hex"
	EcosystemPub       PackageEcosystem = "pub"
	EcosystemDeb       PackageEcosystem = "deb"
	EcosystemApk       PackageEcosystem = "apk"
	EcosystemRpm       PackageEcosystem = "rpm"
	// EcosystemBinary: a runtime or server program found by the scanner's
	// binary classifiers (node, python, php, nginx, …).
	EcosystemBinary PackageEcosystem = "binary"
)

// ProtocolSchema implements SchemaDescriber.
func (PackageEcosystem) ProtocolSchema() ScalarSchema { return enumSchema(packageEcosystems) }

// Validate checks the value.
func (v PackageEcosystem) Validate() error {
	return checkEnum("package ecosystem", string(v), packageEcosystems)
}

// ComponentKind classifies a component.
type ComponentKind string

// Component kinds.
const (
	// ComponentOS is a distribution package (deb, apk, rpm).
	ComponentOS ComponentKind = "os"
	// ComponentLibrary is a language package.
	ComponentLibrary ComponentKind = "library"
	// ComponentRuntime is a language runtime or server program (a binary,
	// an OS package of the exposed list, or Go's stdlib).
	ComponentRuntime ComponentKind = "runtime"
)

// ProtocolSchema implements SchemaDescriber.
func (ComponentKind) ProtocolSchema() ScalarSchema { return enumSchema(componentKinds) }

// Validate checks the value.
func (v ComponentKind) Validate() error {
	return checkEnum("component kind", string(v), componentKinds)
}

// DependencyScope says whether a component's code can run on the server
// when the app serves requests (docs/22 §3.3). Only evidence sets runtime,
// dev or client.
type DependencyScope string

// Scopes.
const (
	ScopeRuntime DependencyScope = "runtime"
	ScopeDev     DependencyScope = "dev"
	ScopeClient  DependencyScope = "client"
	ScopeUnknown DependencyScope = "unknown"
)

// ProtocolSchema implements SchemaDescriber.
func (DependencyScope) ProtocolSchema() ScalarSchema { return enumSchema(dependencyScopes) }

// Validate checks the value.
func (v DependencyScope) Validate() error {
	return checkEnum("dependency scope", string(v), dependencyScopes)
}

// ComponentPresence is how a component is in the image.
type ComponentPresence string

// Presences.
const (
	// PresenceInstalled: the scanner found it in the image.
	PresenceInstalled ComponentPresence = "installed"
	// PresenceBundled: a production dependency of the lockfile that is not
	// installed; its code may be compiled into the app's bundles (npm only).
	PresenceBundled ComponentPresence = "bundled"
)

// ProtocolSchema implements SchemaDescriber.
func (ComponentPresence) ProtocolSchema() ScalarSchema { return enumSchema(componentPresences) }

// Validate checks the value.
func (v ComponentPresence) Validate() error {
	return checkEnum("component presence", string(v), componentPresences)
}

// LockfileKind is the format of a manifest or lockfile.
type LockfileKind string

// Lockfile kinds.
const (
	LockfileNpm          LockfileKind = "npm"
	LockfilePnpm         LockfileKind = "pnpm"
	LockfileYarn         LockfileKind = "yarn"
	LockfileBun          LockfileKind = "bun"
	LockfileComposer     LockfileKind = "composer"
	LockfilePoetry       LockfileKind = "poetry"
	LockfileUv           LockfileKind = "uv"
	LockfilePipfile      LockfileKind = "pipfile"
	LockfileRequirements LockfileKind = "requirements"
	LockfilePyproject    LockfileKind = "pyproject"
	LockfileGemfile      LockfileKind = "gemfile"
	LockfileGoMod        LockfileKind = "gomod"
)

// ProtocolSchema implements SchemaDescriber.
func (LockfileKind) ProtocolSchema() ScalarSchema { return enumSchema(lockfileKinds) }

// Validate checks the value.
func (v LockfileKind) Validate() error { return checkEnum("lockfile kind", string(v), lockfileKinds) }

// PackageName is a package name as its ecosystem writes it.
type PackageName string

// ProtocolSchema implements SchemaDescriber.
func (PackageName) ProtocolSchema() ScalarSchema { return strSchema(rePackageName, 256) }

// Validate checks the grammar.
func (v PackageName) Validate() error {
	return checkPattern("package name", string(v), rePackageName, 256)
}

// PackageVersion is a package version as its ecosystem writes it (Debian
// epochs and tildes included).
type PackageVersion string

// ProtocolSchema implements SchemaDescriber.
func (PackageVersion) ProtocolSchema() ScalarSchema { return strSchema(rePackageVersion, 128) }

// Validate checks the grammar.
func (v PackageVersion) Validate() error {
	return checkPattern("package version", string(v), rePackageVersion, 128)
}

// PackageURL is a package URL ("pkg:npm/lodash@4.17.21"), printable ASCII.
type PackageURL string

// ProtocolSchema implements SchemaDescriber.
func (PackageURL) ProtocolSchema() ScalarSchema { return strSchema(rePackageURL, 1024) }

// Validate checks the grammar.
func (v PackageURL) Validate() error {
	return checkPattern("package url", string(v), rePackageURL, 1024)
}

// DistroID is os-release ID.
type DistroID string

// ProtocolSchema implements SchemaDescriber.
func (DistroID) ProtocolSchema() ScalarSchema { return strSchema(reDistroID, 32) }

// Validate checks the grammar.
func (v DistroID) Validate() error { return checkPattern("distro id", string(v), reDistroID, 32) }

// DistroVersion is os-release VERSION_ID.
type DistroVersion string

// ProtocolSchema implements SchemaDescriber.
func (DistroVersion) ProtocolSchema() ScalarSchema { return strSchema(reDistroVersion, 32) }

// Validate checks the grammar.
func (v DistroVersion) Validate() error {
	return checkPattern("distro version", string(v), reDistroVersion, 32)
}
