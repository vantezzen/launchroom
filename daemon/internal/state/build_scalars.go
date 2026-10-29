package state

import (
	"bytes"
	"encoding/json/jsontext"
	"errors"
	"fmt"
	"net/url"
	"regexp"
	"strings"
)

// Scalars of the build and registry parts of the protocol (docs/04, docs/05 §6).

var (
	reGitURL       = regexp.MustCompile(`^(?:https?://[a-z0-9.-]+(?::[0-9]{1,5})?(?:/[A-Za-z0-9._~!$&'()*+,;=:@%/-]*)?|ssh://(?:[a-z_][a-z0-9_.-]{0,31}@)?[a-z0-9.-]+(?::[0-9]{1,5})?/[A-Za-z0-9._~/-]+)$`)
	reGitRef       = regexp.MustCompile(`^refs/(?:heads|tags)/[A-Za-z0-9._/+-]{1,240}$`)
	reHostKeyBlob  = regexp.MustCompile(`^(?:[A-Za-z0-9+/]{4})*(?:[A-Za-z0-9+/]{2}==|[A-Za-z0-9+/]{3}=)?$`)
	reIdent        = regexp.MustCompile(`^[A-Za-z0-9][A-Za-z0-9._+-]{0,63}$`)
	reRegistryRepo = regexp.MustCompile(`^[a-z0-9]+(?:(?:\.|_|__|-+)[a-z0-9]+)*(?:/[a-z0-9]+(?:(?:\.|_|__|-+)[a-z0-9]+)*)*$`)
	reRailpackEnv  = regexp.MustCompile(`^RAILPACK_[A-Z0-9_]{1,64}$`)
	reSHA256Hex    = regexp.MustCompile(`^[a-f0-9]{64}$`)
	reJWT          = regexp.MustCompile(`^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$`)
)

// GitURL is the clone URL of a repository, without credentials, query or
// fragment: `https://…`, `http://…` (accepted only by daemons whose local
// config lists the host, LR_BUILD_INSECURE_GIT_HOSTS, dev fixtures) or
// `ssh://[user@]host[:port]/path` (docs/19 §6; a user but never a password,
// the path is non-empty and only [A-Za-z0-9._~/-]: no quotes, spaces, `%` or
// shell metacharacters). A path starting with `/~` is home-relative, git's own
// convention: the remote gets `~/…` (scp-style `git@host:repos/app.git`
// becomes `ssh://git@host/~/repos/app.git`).
type GitURL string

// ProtocolSchema implements SchemaDescriber.
func (GitURL) ProtocolSchema() ScalarSchema { return strSchema(reGitURL, 2048) }

// Validate checks the grammar and parses the URL.
func (v GitURL) Validate() error {
	if err := checkPattern("git url", string(v), reGitURL, 2048); err != nil {
		return err
	}
	u, err := url.Parse(string(v))
	bad := err != nil || u.Host == "" || u.Hostname() == "" || u.RawQuery != "" || u.Fragment != "" || u.Opaque != ""
	if !bad {
		switch u.Scheme {
		case "https", "http":
			bad = u.User != nil
		case "ssh":
			_, hasPassword := u.User.Password()
			bad = (u.User != nil && (hasPassword || u.User.Username() == "")) || u.Path == "" || u.Path == "/"
		default:
			bad = true
		}
	}
	if bad {
		return fmt.Errorf("git url %q is invalid", truncate(string(v)))
	}
	return nil
}

// IsSSH reports whether the URL uses the ssh scheme.
func (v GitURL) IsSSH() bool { return strings.HasPrefix(string(v), "ssh://") }

// GitRef is a full branch or tag ref ("refs/heads/main", "refs/tags/v1.2.0"),
// following git's check-ref-format rules for the characters it allows.
type GitRef string

// ProtocolSchema implements SchemaDescriber.
func (GitRef) ProtocolSchema() ScalarSchema { return strSchema(reGitRef, 255) }

// Validate checks the grammar plus git's component rules.
func (v GitRef) Validate() error {
	if err := checkPattern("git ref", string(v), reGitRef, 255); err != nil {
		return err
	}
	s := string(v)
	if strings.Contains(s, "..") || strings.Contains(s, "//") || strings.HasSuffix(s, "/") || strings.HasSuffix(s, ".") {
		return fmt.Errorf("git ref %q is invalid", truncate(s))
	}
	for c := range strings.SplitSeq(s, "/") {
		if strings.HasPrefix(c, ".") || strings.HasSuffix(c, ".lock") {
			return fmt.Errorf("git ref %q is invalid", truncate(s))
		}
	}
	return nil
}

// HostKeyBlob is the standard base64 of an SSH public key in wire format
// (the second field of a known_hosts or authorized_keys line).
type HostKeyBlob string

// ProtocolSchema implements SchemaDescriber.
func (HostKeyBlob) ProtocolSchema() ScalarSchema {
	s := strSchema(reHostKeyBlob, 4096)
	s.MinLength = 16
	return s
}

// Validate checks the grammar (the key itself is checked by HostKey.Validate).
func (v HostKeyBlob) Validate() error {
	if len(v) < 16 {
		return errors.New("host key is too short")
	}
	return checkPattern("host key", string(v), reHostKeyBlob, 4096)
}

// EnvName is an environment variable name.
type EnvName string

// ProtocolSchema implements SchemaDescriber.
func (EnvName) ProtocolSchema() ScalarSchema { return strSchema(reEnvName, 0) }

// Validate checks the grammar.
func (v EnvName) Validate() error { return checkPattern("env name", string(v), reEnvName, 0) }

// Ident is a short identifier such as a Railpack provider ("node"), a
// framework ("next") or a package name ("node", "pnpm").
type Ident string

// ProtocolSchema implements SchemaDescriber.
func (Ident) ProtocolSchema() ScalarSchema { return strSchema(reIdent, 0) }

// Validate checks the grammar.
func (v Ident) Validate() error { return checkPattern("identifier", string(v), reIdent, 0) }

// RegistryRepository is a repository path inside the launchroom registry
// (no host), e.g. "apps/svc_01j…", "cache/svc_01j…" or
// "mirror/docker.io/library/postgres".
type RegistryRepository string

// ProtocolSchema implements SchemaDescriber.
func (RegistryRepository) ProtocolSchema() ScalarSchema { return strSchema(reRegistryRepo, 255) }

// Validate checks the grammar.
func (v RegistryRepository) Validate() error {
	return checkPattern("registry repository", string(v), reRegistryRepo, 255)
}

// RailpackEnvName is a Railpack configuration variable (RAILPACK_*). These are
// non-secret build configuration, never user secrets.
type RailpackEnvName string

// ProtocolSchema implements SchemaDescriber.
func (RailpackEnvName) ProtocolSchema() ScalarSchema { return strSchema(reRailpackEnv, 0) }

// Validate checks the grammar.
func (v RailpackEnvName) Validate() error {
	return checkPattern("railpack variable", string(v), reRailpackEnv, 0)
}

// SHA256Hex is a lowercase hex SHA-256 digest without prefix.
type SHA256Hex string

// ProtocolSchema implements SchemaDescriber.
func (SHA256Hex) ProtocolSchema() ScalarSchema { return strSchema(reSHA256Hex, 0) }

// Validate checks the grammar.
func (v SHA256Hex) Validate() error { return checkPattern("sha256", string(v), reSHA256Hex, 0) }

// MaxRawObjectBytes bounds an embedded JSON object (a Railpack plan).
const MaxRawObjectBytes = 512 << 10

// RawObject is an embedded JSON object passed through verbatim (the resolved
// Railpack build plan). It carries no secrets: Railpack plans list secret
// names only.
type RawObject jsontext.Value

// ProtocolSchema implements SchemaDescriber.
func (RawObject) ProtocolSchema() ScalarSchema { return ScalarSchema{Type: "object"} }

// Validate checks that the value is a JSON object within the size limit.
func (v RawObject) Validate() error {
	if len(v) > MaxRawObjectBytes {
		return fmt.Errorf("embedded object is larger than %d bytes", MaxRawObjectBytes)
	}
	val := jsontext.Value(v)
	if !val.IsValid() || val.Kind() != '{' {
		return errors.New("embedded value must be a JSON object")
	}
	return nil
}

// MarshalJSON emits the object verbatim.
func (v RawObject) MarshalJSON() ([]byte, error) {
	if err := v.Validate(); err != nil {
		return nil, err
	}
	return bytes.Clone(v), nil
}

// UnmarshalJSON keeps a copy of the object.
func (v *RawObject) UnmarshalJSON(data []byte) error {
	o := RawObject(bytes.Clone(data))
	if err := o.Validate(); err != nil {
		return err
	}
	*v = o
	return nil
}

// checkEnvMap validates a map of non-secret variables (names and value sizes).
func checkEnvMap(e *errs, path string, m map[string]string, maxItems int) {
	if len(m) > maxItems {
		e.addf(path, "has %d entries, at most %d allowed", len(m), maxItems)
	}
	for k, v := range m {
		if !reEnvName.MatchString(k) {
			e.addf(path, "invalid variable name %q", truncate(k))
		}
		if len(v) > 4096 || strings.ContainsRune(v, 0) {
			e.addf(path+"."+k, "value must be at most 4096 bytes without NUL")
		}
	}
}

// PhpExtensionName is a PHP extension launchroom may add to a Railpack PHP
// build because a connected database needs its driver (docs/04 §3.1). An
// allow-list, not a grammar: the name reaches install-php-extensions.
type PhpExtensionName string

// PHP extensions a build may add.
const (
	PhpExtPdoPgsql PhpExtensionName = "pdo_pgsql"
	PhpExtPgsql    PhpExtensionName = "pgsql"
	PhpExtPdoMysql PhpExtensionName = "pdo_mysql"
	PhpExtMysqli   PhpExtensionName = "mysqli"
	PhpExtRedis    PhpExtensionName = "redis"
	PhpExtMongoDB  PhpExtensionName = "mongodb"
)

var phpExtensionNames = []string{string(PhpExtPdoPgsql), string(PhpExtPgsql), string(PhpExtPdoMysql), string(PhpExtMysqli), string(PhpExtRedis), string(PhpExtMongoDB)}

// ProtocolSchema implements SchemaDescriber.
func (PhpExtensionName) ProtocolSchema() ScalarSchema { return enumSchema(phpExtensionNames) }

// Validate checks the value.
func (v PhpExtensionName) Validate() error {
	return checkEnum("php extension", string(v), phpExtensionNames)
}
