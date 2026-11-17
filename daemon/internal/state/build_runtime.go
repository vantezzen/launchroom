package state

import (
	"regexp"
)

// ---- Runtime contract of a build summary: what launchroom sets up around the app ----
//
// Every type here is a BuildSummary field (launchroomd → dashboard), so no
// daemon feature is needed: the dashboard updates before its servers.

// Limits of the runtime contract.
const (
	maxEnvAliases             = 16
	maxRequiredSecrets        = 8
	maxVolumeSuggestions      = 8
	maxWorkspaceDeps          = 64
	maxPhpDependencyExtension = 32
	maxReasonText             = 256
)

// EnvAliasSource is a system value a framework variable is set to.
type EnvAliasSource string

// Alias sources (docs/06 §3.1). Each resolves per environment at deploy
// time; workers and crons resolve the domain values of their parent app.
const (
	AliasDomain     EnvAliasSource = "DOMAIN"      // the primary domain
	AliasDomains    EnvAliasSource = "DOMAINS"     // every domain, primary first, comma-separated
	AliasPublicURL  EnvAliasSource = "PUBLIC_URL"  // https://<primary domain>
	AliasPublicURLs EnvAliasSource = "PUBLIC_URLS" // https:// of every domain, comma-separated
	AliasPort       EnvAliasSource = "PORT"
	// AliasAllowedHostsList: DOMAINS plus the service's network alias and localhost.
	AliasAllowedHostsList EnvAliasSource = "ALLOWED_HOSTS_LIST"
	// AliasTrustedProxyCIDRs: the environment network's gateway and the mesh range.
	AliasTrustedProxyCIDRs EnvAliasSource = "TRUSTED_PROXY_CIDRS"
)

var envAliasSources = []string{
	string(AliasDomain), string(AliasDomains), string(AliasPublicURL), string(AliasPublicURLs), string(AliasPort),
	string(AliasAllowedHostsList), string(AliasTrustedProxyCIDRs),
}

// ProtocolSchema implements SchemaDescriber.
func (EnvAliasSource) ProtocolSchema() ScalarSchema { return enumSchema(envAliasSources) }

// Validate checks the value.
func (v EnvAliasSource) Validate() error {
	return checkEnum("alias source", string(v), envAliasSources)
}

// EnvAlias sets a framework's own variable (APP_URL, PHX_HOST,
// DJANGO_ALLOWED_HOSTS …) to a system value. The dashboard resolves it at
// the system layer: a variable the user set always wins.
type EnvAlias struct {
	Name EnvName        `json:"name"`
	From EnvAliasSource `json:"from"`
}

// RequiredSecret is a secret the app needs that launchroom can't generate
// (Rails' RAILS_MASTER_KEY): the plan preview asks for it before the first
// deploy.
type RequiredSecret struct {
	Name EnvName `json:"name"`
	// Reason says what it is for ("decrypts config/credentials.yml.enc").
	Reason string `json:"reason,omitzero" jsonschema:"maxLength=256"`
}

// VolumeSuggestion is a folder whose files should outlive a deploy
// (uploads, a SQLite database): the dashboard offers a volume there.
type VolumeSuggestion struct {
	Path   ContainerPath `json:"path"`
	Reason string        `json:"reason" jsonschema:"minLength=1,maxLength=256"`
}

// WorkspaceInfo describes the monorepo workspace an app belongs to: the
// build context is the workspace root and the build is filtered to the app
// and its workspace dependencies (docs/04 §3).
type WorkspaceInfo struct {
	// Root is the workspace root relative to the repository root (omitted:
	// the repository root).
	Root RelPath `json:"root,omitzero"`
	// PackageName is the app's package name ("@acme/web").
	PackageName string `json:"packageName" jsonschema:"minLength=1,maxLength=214"`
	// Manager is the workspace's package manager ("pnpm", "npm", "yarn", "bun").
	Manager Ident `json:"manager"`
	// Tool is the monorepo tool, if any ("turbo", "nx").
	Tool Ident `json:"tool,omitzero"`
	// DependsOn are the workspace folders the app depends on, relative to
	// the repository root (the dashboard's default watch paths).
	DependsOn []RelPath `json:"dependsOn,omitzero" jsonschema:"nullable,maxItems=64"`
}

// PhpExtensionIdent is the name of a PHP extension a dependency requires
// (composer's "ext-<name>", lowercased, without the prefix).
type PhpExtensionIdent string

var rePhpExtensionIdent = regexp.MustCompile(`^[a-z][a-z0-9_]{0,31}$`)

// ProtocolSchema implements SchemaDescriber.
func (PhpExtensionIdent) ProtocolSchema() ScalarSchema { return strSchema(rePhpExtensionIdent, 0) }

// Validate checks the grammar.
func (v PhpExtensionIdent) Validate() error {
	return checkPattern("php extension", string(v), rePhpExtensionIdent, 0)
}

// ComposerPackage is a composer package name ("vendor/name").
type ComposerPackage string

var reComposerPackage = regexp.MustCompile(`^[a-z0-9](?:[_.-]?[a-z0-9]+)*/[a-z0-9](?:(?:[_.]|-{1,2})?[a-z0-9]+)*$`)

// ProtocolSchema implements SchemaDescriber.
func (ComposerPackage) ProtocolSchema() ScalarSchema { return strSchema(reComposerPackage, 128) }

// Validate checks the grammar.
func (v ComposerPackage) Validate() error {
	return checkPattern("composer package", string(v), reComposerPackage, 128)
}

// PhpDependencyExtension is a PHP extension added to a Railpack build
// because a dependency requires it (composer.lock "ext-*").
type PhpDependencyExtension struct {
	Name PhpExtensionIdent `json:"name"`
	// For is the first package that requires it.
	For ComposerPackage `json:"for"`
}

// PresetScope is how much of a preset applied.
type PresetScope string

// Preset scopes.
const (
	// PresetScopeFull: the preset shaped the image and the runtime (Railpack builds).
	PresetScopeFull PresetScope = "full"
	// PresetScopeRuntime: only its runtime decisions applied (env, health,
	// secrets, aliases, processes), to a Dockerfile build's container.
	PresetScopeRuntime PresetScope = "runtime"
)

var presetScopes = []string{string(PresetScopeFull), string(PresetScopeRuntime)}

// ProtocolSchema implements SchemaDescriber.
func (PresetScope) ProtocolSchema() ScalarSchema { return enumSchema(presetScopes) }

// Validate checks the value.
func (v PresetScope) Validate() error { return checkEnum("preset scope", string(v), presetScopes) }

// SecretScope is where a generated secret is used.
type SecretScope string

// Secret scopes.
const (
	SecretRuntime SecretScope = "runtime"
	// SecretBuild: the build reads it too (it is part of the build key).
	SecretBuild SecretScope = "build"
)

var secretScopes = []string{string(SecretRuntime), string(SecretBuild)}

// ProtocolSchema implements SchemaDescriber.
func (SecretScope) ProtocolSchema() ScalarSchema { return enumSchema(secretScopes) }

// Validate checks the value.
func (v SecretScope) Validate() error { return checkEnum("secret scope", string(v), secretScopes) }

// SecretSharing is which environments share one generated value.
type SecretSharing string

// Secret sharing.
const (
	// ShareEnvironment: one value per service and environment (D86).
	ShareEnvironment SecretSharing = "environment"
	// ShareService: one value per service, shared by its environments except
	// fork previews, so an image built once can be promoted (build scope only).
	ShareService SecretSharing = "service"
)

var secretSharings = []string{string(ShareEnvironment), string(ShareService)}

// ProtocolSchema implements SchemaDescriber.
func (SecretSharing) ProtocolSchema() ScalarSchema { return enumSchema(secretSharings) }

// Validate checks the value.
func (v SecretSharing) Validate() error {
	return checkEnum("secret sharing", string(v), secretSharings)
}

func (s *BuildSummary) validateRuntimeContract(e *errs, p string) {
	checkCount(e, p+".envAliases", len(s.EnvAliases), 0, maxEnvAliases)
	aliasNames := make([]EnvName, 0, len(s.EnvAliases))
	for i, a := range s.EnvAliases {
		ap := idx(p+".envAliases", i)
		e.check(ap+".name", a.Name)
		e.check(ap+".from", a.From)
		aliasNames = append(aliasNames, a.Name)
	}
	unique(e, p+".envAliases.name", aliasNames)

	checkCount(e, p+".requiredSecrets", len(s.RequiredSecrets), 0, maxRequiredSecrets)
	required := make([]EnvName, 0, len(s.RequiredSecrets))
	for i, r := range s.RequiredSecrets {
		rp := idx(p+".requiredSecrets", i)
		e.check(rp+".name", r.Name)
		checkText(e, rp+".reason", r.Reason, maxReasonText)
		required = append(required, r.Name)
	}
	unique(e, p+".requiredSecrets.name", required)

	checkCount(e, p+".volumeSuggestions", len(s.VolumeSuggestions), 0, maxVolumeSuggestions)
	paths := make([]ContainerPath, 0, len(s.VolumeSuggestions))
	for i, v := range s.VolumeSuggestions {
		vp := idx(p+".volumeSuggestions", i)
		e.check(vp+".path", v.Path)
		if v.Reason == "" {
			e.addf(vp+".reason", "is required")
		}
		checkText(e, vp+".reason", v.Reason, maxReasonText)
		paths = append(paths, v.Path)
	}
	unique(e, p+".volumeSuggestions.path", paths)

	if w := s.Workspace; w != nil {
		wp := p + ".workspace"
		if w.Root != "" {
			e.check(wp+".root", w.Root)
		}
		if w.PackageName == "" || len(w.PackageName) > 214 || hasControl(w.PackageName) {
			e.addf(wp+".packageName", "must be 1-214 bytes of text")
		}
		e.check(wp+".manager", w.Manager)
		if w.Tool != "" {
			e.check(wp+".tool", w.Tool)
		}
		checkCount(e, wp+".dependsOn", len(w.DependsOn), 0, maxWorkspaceDeps)
		for i, d := range w.DependsOn {
			e.check(idx(wp+".dependsOn", i), d)
		}
		unique(e, wp+".dependsOn", w.DependsOn)
	}

	checkCount(e, p+".phpDependencyExtensions", len(s.PhpDependencyExtensions), 0, maxPhpDependencyExtension)
	exts := make([]PhpExtensionIdent, 0, len(s.PhpDependencyExtensions))
	for i, x := range s.PhpDependencyExtensions {
		xp := idx(p+".phpDependencyExtensions", i)
		e.check(xp+".name", x.Name)
		e.check(xp+".for", x.For)
		exts = append(exts, x.Name)
	}
	unique(e, p+".phpDependencyExtensions.name", exts)

	if s.Image != nil {
		s.Image.validate(e, p+".image")
	}
}
