package update

import (
	"crypto"
	"crypto/x509"
	"encoding/json"
	"encoding/pem"
	"errors"
	"fmt"
	"io"
	"io/fs"
	"net/url"
	"os"
	"path/filepath"
	"strings"
)

// TrustFile is the name of the trust pin in launchroomd's data directory
// (data/daemon/trust.json on the control-plane host, data/trust.json on
// other servers). The installer writes it once; nothing the dashboard sends
// can change it.
const TrustFile = "trust.json"

// Default release identity (docs/09 R30): keyless cosign
// signatures made by the release workflow of vantezzen/launchroom on a
// version tag, certified by Fulcio for GitHub Actions' OIDC issuer.
const (
	DefaultIssuer      = "https://token.actions.githubusercontent.com"
	DefaultIdentity    = "https://github.com/vantezzen/launchroom/.github/workflows/release.yml@refs/tags/v"
	DefaultReleaseBase = "https://github.com/vantezzen/launchroom/releases"
	maxTrustFileBytes  = 64 << 10
)

// Trust is the release identity pinned at install time.
type Trust struct {
	// Issuer is the OIDC issuer the signing certificate must name (keyless).
	Issuer string `json:"issuer,omitempty"`
	// Identity is the certificate SAN prefix; the SAN must equal Identity
	// followed by the release version exactly (keyless), e.g.
	// https://github.com/vantezzen/launchroom/.github/workflows/release.yml@refs/tags/v1.2.3.
	Identity string `json:"identity,omitempty"`
	// ReleaseBase is where release assets are downloaded from:
	// <ReleaseBase>/download/v<version>/launchroom-deploy.tar.gz (+ .sha256, .sigstore.json).
	ReleaseBase string `json:"releaseBase"`
	// PublicKey (PEM, ECDSA/Ed25519/RSA) switches to key-based verification
	// instead of keyless: for private builds and tests. Mutually exclusive
	// with Issuer/Identity.
	PublicKey string `json:"publicKey,omitempty"`
	// OfflineOnly skips the TUF refresh of the Sigstore trusted root and only
	// uses the root embedded in launchroomd (air-gapped servers).
	OfflineOnly bool `json:"offlineOnly,omitempty"`
	// AllowInsecureReleaseBase permits an http:// release base (tests only).
	AllowInsecureReleaseBase bool `json:"allowInsecureReleaseBase,omitempty"`
}

// ErrNoTrust means no release identity was pinned at install.
var ErrNoTrust = errors.New("no release identity is pinned (" + TrustFile + " is missing): re-run the installer of this server to pin it; self-update stays disabled until then")

// DefaultTrust is what the installers write.
func DefaultTrust() Trust {
	return Trust{Issuer: DefaultIssuer, Identity: DefaultIdentity, ReleaseBase: DefaultReleaseBase}
}

// LoadTrust reads and validates dataDir/trust.json.
func LoadTrust(dataDir string) (Trust, error) {
	raw, err := readLimited(filepath.Join(dataDir, TrustFile), maxTrustFileBytes)
	if errors.Is(err, fs.ErrNotExist) {
		return Trust{}, ErrNoTrust
	}
	if err != nil {
		return Trust{}, err
	}
	var t Trust
	dec := json.NewDecoder(strings.NewReader(string(raw)))
	dec.DisallowUnknownFields()
	if err := dec.Decode(&t); err != nil {
		return Trust{}, fmt.Errorf("%s: %w", TrustFile, err)
	}
	if err := t.Validate(); err != nil {
		return Trust{}, fmt.Errorf("%s: %w", TrustFile, err)
	}
	return t, nil
}

// Validate checks the trust pin. It fails closed on anything ambiguous.
func (t Trust) Validate() error {
	u, err := url.Parse(t.ReleaseBase)
	if err != nil || u.Host == "" || u.User != nil || u.RawQuery != "" || u.Fragment != "" {
		return errors.New("releaseBase must be an absolute URL without credentials, query or fragment")
	}
	switch {
	case u.Scheme == "https":
	case u.Scheme == "http" && t.AllowInsecureReleaseBase:
	default:
		return errors.New("releaseBase must use https")
	}
	if t.PublicKey != "" {
		if t.Issuer != "" || t.Identity != "" {
			return errors.New("publicKey cannot be combined with issuer/identity")
		}
		_, err := t.publicKey()
		return err
	}
	if !strings.HasPrefix(t.Issuer, "https://") || strings.ContainsAny(t.Issuer, " \t\n") {
		return errors.New("issuer must be an https URL")
	}
	if !strings.HasPrefix(t.Identity, "https://") || !strings.HasSuffix(t.Identity, "@refs/tags/v") || strings.ContainsAny(t.Identity, " \t\n") {
		return errors.New("identity must be an https URL ending in @refs/tags/v (the version is appended)")
	}
	return nil
}

// Keyless reports whether signatures are verified against Fulcio identities.
func (t Trust) Keyless() bool { return t.PublicKey == "" }

func (t Trust) publicKey() (crypto.PublicKey, error) {
	block, _ := pem.Decode([]byte(t.PublicKey))
	if block == nil {
		return nil, errors.New("publicKey is not PEM")
	}
	k, err := x509.ParsePKIXPublicKey(block.Bytes)
	if err != nil {
		return nil, fmt.Errorf("publicKey: %w", err)
	}
	return k, nil
}

// assetURL is the download URL of a release asset.
func (t Trust) assetURL(version, name string) string {
	return strings.TrimRight(t.ReleaseBase, "/") + "/download/v" + version + "/" + name
}

func readLimited(path string, limit int64) ([]byte, error) {
	f, err := os.Open(path) //nolint:gosec // fixed file names below the data directory
	if err != nil {
		return nil, err
	}
	defer func() { _ = f.Close() }()
	b, err := io.ReadAll(io.LimitReader(f, limit+1))
	if err != nil {
		return nil, err
	}
	if int64(len(b)) > limit {
		return nil, fmt.Errorf("%s is larger than %d bytes", path, limit)
	}
	return b, nil
}
