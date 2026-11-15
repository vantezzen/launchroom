package update

import (
	"archive/tar"
	"bufio"
	"bytes"
	"compress/gzip"
	"context"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	"os"
	"path"
	"path/filepath"
	"regexp"
	"strings"
	"time"

	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// Release asset names (docs/11 §2, the release workflow).
const (
	BundleAsset    = "launchroom-deploy.tar.gz"
	checksumSuffix = ".sha256"
	sigSuffix      = ".sigstore.json"

	maxBundleBytes   = 32 << 20
	maxBundleFiles   = 256
	maxChecksumBytes = 4 << 10
)

// Files of the deploy bundle launchroomd relies on.
const (
	fileVersion        = "VERSION"
	fileManifest       = "manifest.json"
	fileImagesEnv      = "control-plane/images.env"
	fileInstallCP      = "install/install.sh"
	fileInstallServer  = "install/server.sh"
	fileDaemonCompose  = "daemon/compose.yml"
	fileCPCompose      = "control-plane/compose.yml"
	envDaemonImage     = "LR_DAEMON_IMAGE"
	envComposeImage    = "LR_COMPOSE_IMAGE"
	downloadTimeout    = 5 * time.Minute
	downloadUserAgentP = "launchroomd/"
)

var (
	reEnvLine    = regexp.MustCompile(`^([A-Z][A-Z0-9_]{0,63})=(.*)$`)
	reImageKey   = regexp.MustCompile(`^LR_[A-Z0-9_]{1,58}_IMAGE$`)
	reFileSHA256 = regexp.MustCompile(`^[a-f0-9]{64}$`)
)

// manifest is the bundle's manifest.json (deploy/release/build-bundle.sh):
// the image keys images.env must pin and every file with its mode and digest.
type manifest struct {
	Version        string   `json:"version"`
	RequiredImages []string `json:"requiredImages"`
	Files          []struct {
		Path   string `json:"path"`
		Mode   string `json:"mode"`
		SHA256 string `json:"sha256"`
	} `json:"files"`
}

// Bundle is a verified, parsed deploy bundle.
type Bundle struct {
	Version string
	// Images are the LR_*_IMAGE pins of control-plane/images.env.
	Images map[string]state.ImageRef
	// Files are the regular files by slash path.
	Files map[string][]byte
	// Modes are the file modes from manifest.json (nil: an older bundle
	// without one; Extract then uses the fixed rules).
	Modes map[string]os.FileMode
	// SHA256 is the hex digest of the tarball.
	SHA256 string
	// Signature is the sigstore bundle the tarball verified against (set
	// by Fetch; kept so a guarded supervisor can verify it again).
	Signature []byte
}

// DaemonImage is the release's launchroomd image.
func (b *Bundle) DaemonImage() state.ImageRef { return b.Images[envDaemonImage] }

// ComposeImage is the docker CLI image the update helpers run in.
func (b *Bundle) ComposeImage() state.ImageRef { return b.Images[envComposeImage] }

// Fetcher downloads release assets.
type Fetcher struct {
	Trust     Trust
	Client    *http.Client
	UserAgent string
}

// Fetch downloads, checks and verifies the deploy bundle of version and
// returns it parsed. Nothing is trusted before the signature verified.
func (f *Fetcher) Fetch(ctx context.Context, v *Verifier, version string) (*Bundle, []byte, error) {
	tarball, err := f.get(ctx, f.Trust.assetURL(version, BundleAsset), maxBundleBytes)
	if err != nil {
		return nil, nil, err
	}
	sig, err := f.get(ctx, f.Trust.assetURL(version, BundleAsset+sigSuffix), maxSigBundleBytes)
	if err != nil {
		return nil, nil, fmt.Errorf("release v%s has no signature: %w", version, err)
	}
	if err := v.Verify(tarball, sig, version); err != nil {
		return nil, nil, fmt.Errorf("release v%s: %w", version, err)
	}
	// The published checksum must agree too (it is what the installer and
	// humans compare); a mismatch means the release is inconsistent.
	sumFile, err := f.get(ctx, f.Trust.assetURL(version, BundleAsset+checksumSuffix), maxChecksumBytes)
	if err != nil {
		return nil, nil, fmt.Errorf("release v%s has no checksum: %w", version, err)
	}
	sum := sha256.Sum256(tarball)
	if got := strings.Fields(string(sumFile)); len(got) == 0 || !strings.EqualFold(got[0], hex.EncodeToString(sum[:])) {
		return nil, nil, fmt.Errorf("release v%s: checksum file does not match the signed bundle", version)
	}
	b, err := ParseBundle(tarball)
	if err != nil {
		return nil, nil, fmt.Errorf("release v%s: %w", version, err)
	}
	if b.Version != version {
		return nil, nil, fmt.Errorf("release v%s: the signed bundle is version %q", version, b.Version)
	}
	b.Signature = sig
	return b, tarball, nil
}

func (f *Fetcher) get(ctx context.Context, url string, limit int64) ([]byte, error) {
	c := f.Client
	if c == nil {
		c = &http.Client{Timeout: downloadTimeout}
	}
	req, err := http.NewRequestWithContext(ctx, http.MethodGet, url, nil)
	if err != nil {
		return nil, err
	}
	if f.UserAgent != "" {
		req.Header.Set("User-Agent", f.UserAgent)
	}
	res, err := c.Do(req)
	if err != nil {
		return nil, err
	}
	defer func() { _ = res.Body.Close() }()
	if res.StatusCode != http.StatusOK {
		return nil, fmt.Errorf("GET %s: HTTP %d", url, res.StatusCode)
	}
	body, err := io.ReadAll(io.LimitReader(res.Body, limit+1))
	if err != nil {
		return nil, err
	}
	if int64(len(body)) > limit {
		return nil, fmt.Errorf("GET %s: larger than %d bytes", url, limit)
	}
	return body, nil
}

// ParseBundle reads a deploy bundle tarball (gzip'd tar of regular files
// and directories with relative, clean paths).
func ParseBundle(tarball []byte) (*Bundle, error) {
	zr, err := gzip.NewReader(bytes.NewReader(tarball))
	if err != nil {
		return nil, fmt.Errorf("bundle: %w", err)
	}
	defer func() { _ = zr.Close() }()
	tr := tar.NewReader(io.LimitReader(zr, 4*maxBundleBytes))
	files := map[string][]byte{}
	for {
		h, err := tr.Next()
		if errors.Is(err, io.EOF) {
			break
		}
		if err != nil {
			return nil, fmt.Errorf("bundle: %w", err)
		}
		name := strings.TrimPrefix(h.Name, "./")
		switch h.Typeflag {
		case tar.TypeDir:
			continue
		case tar.TypeReg:
		default:
			return nil, fmt.Errorf("bundle: %s is not a regular file", h.Name)
		}
		if name == "" || path.IsAbs(name) || path.Clean(name) != name || strings.HasPrefix(name, "../") || name == ".." || strings.Contains(name, "\\") {
			return nil, fmt.Errorf("bundle: unsafe path %q", h.Name)
		}
		if len(files) >= maxBundleFiles {
			return nil, errors.New("bundle: too many files")
		}
		if _, dup := files[name]; dup {
			return nil, fmt.Errorf("bundle: duplicate file %q", name)
		}
		b, err := io.ReadAll(io.LimitReader(tr, maxBundleBytes+1))
		if err != nil {
			return nil, fmt.Errorf("bundle: %w", err)
		}
		if len(b) > maxBundleBytes {
			return nil, fmt.Errorf("bundle: %s is too large", name)
		}
		files[name] = b
	}
	for _, need := range []string{fileVersion, fileImagesEnv, fileInstallCP, fileInstallServer, fileDaemonCompose, fileCPCompose} {
		if _, ok := files[need]; !ok {
			return nil, fmt.Errorf("bundle: %s is missing", need)
		}
	}
	version := strings.TrimSpace(string(files[fileVersion]))
	if err := state.Semver(version).Validate(); err != nil || strings.HasPrefix(version, "v") {
		return nil, fmt.Errorf("bundle: VERSION %q is not a semantic version", version)
	}
	images, err := parseImagesEnv(files[fileImagesEnv])
	if err != nil {
		return nil, err
	}
	// launchroomd itself runs these two; a manifest adds what the release needs.
	required := []string{envDaemonImage, envComposeImage}
	var modes map[string]os.FileMode
	if raw, ok := files[fileManifest]; ok {
		m, err := readManifest(raw, version, files)
		if err != nil {
			return nil, err
		}
		required = append(required, m.RequiredImages...)
		modes = m.modes()
	}
	for _, need := range required {
		if images[need] == "" {
			return nil, fmt.Errorf("bundle: %s is missing from images.env", need)
		}
	}
	sum := sha256.Sum256(tarball)
	return &Bundle{Version: version, Images: images, Files: files, Modes: modes, SHA256: hex.EncodeToString(sum[:])}, nil
}

// readManifest checks manifest.json against the bundle: same version, and
// exactly the bundle's other files with their digests and a 0644 or 0755 mode.
func readManifest(raw []byte, version string, files map[string][]byte) (*manifest, error) {
	var m manifest
	dec := json.NewDecoder(bytes.NewReader(raw))
	dec.DisallowUnknownFields()
	if err := dec.Decode(&m); err != nil {
		return nil, fmt.Errorf("bundle: manifest.json: %w", err)
	}
	if m.Version != version {
		return nil, fmt.Errorf("bundle: manifest.json is for %q, VERSION says %q", m.Version, version)
	}
	for _, k := range m.RequiredImages {
		if !reImageKey.MatchString(k) {
			return nil, fmt.Errorf("bundle: manifest.json: %q is not an image key", k)
		}
	}
	listed := map[string]bool{fileManifest: true}
	for _, f := range m.Files {
		content, ok := files[f.Path]
		switch {
		case !ok:
			return nil, fmt.Errorf("bundle: manifest.json lists %q, which is missing", f.Path)
		case listed[f.Path]:
			return nil, fmt.Errorf("bundle: manifest.json lists %q twice", f.Path)
		case f.Mode != "0644" && f.Mode != "0755":
			return nil, fmt.Errorf("bundle: manifest.json: %s has mode %q", f.Path, f.Mode)
		case !reFileSHA256.MatchString(f.SHA256):
			return nil, fmt.Errorf("bundle: manifest.json: %s has no sha256", f.Path)
		}
		if sum := sha256.Sum256(content); hex.EncodeToString(sum[:]) != f.SHA256 {
			return nil, fmt.Errorf("bundle: %s does not match manifest.json", f.Path)
		}
		listed[f.Path] = true
	}
	for name := range files {
		if !listed[name] {
			return nil, fmt.Errorf("bundle: %s is not listed in manifest.json", name)
		}
	}
	return &m, nil
}

func (m *manifest) modes() map[string]os.FileMode {
	out := make(map[string]os.FileMode, len(m.Files)+1)
	for _, f := range m.Files {
		out[f.Path] = 0o644
		if f.Mode == "0755" {
			out[f.Path] = 0o755
		}
	}
	out[fileManifest] = 0o644
	return out
}

// parseImagesEnv reads the LR_*_IMAGE pins; every one must be digest-pinned.
func parseImagesEnv(raw []byte) (map[string]state.ImageRef, error) {
	out := map[string]state.ImageRef{}
	sc := bufio.NewScanner(bytes.NewReader(raw))
	for sc.Scan() {
		line := strings.TrimSpace(sc.Text())
		if line == "" || strings.HasPrefix(line, "#") {
			continue
		}
		m := reEnvLine.FindStringSubmatch(line)
		if m == nil || !strings.HasPrefix(m[1], "LR_") || !strings.HasSuffix(m[1], "_IMAGE") {
			continue
		}
		ref := state.ImageRef(m[2])
		if err := ref.Validate(); err != nil {
			return nil, fmt.Errorf("bundle: images.env %s: %w", m[1], err)
		}
		out[m[1]] = ref
	}
	if err := sc.Err(); err != nil {
		return nil, fmt.Errorf("bundle: images.env: %w", err)
	}
	return out, nil
}

// Extract writes the bundle's files below dir (0700 dirs; file modes from
// manifest.json, or for an older bundle without one: install scripts and
// the helper 0755, everything else 0644). dir must not exist yet.
func (b *Bundle) Extract(dir string) error {
	if err := os.Mkdir(dir, 0o700); err != nil {
		return err
	}
	for name, content := range b.Files {
		p := filepath.Join(dir, filepath.FromSlash(name))
		if !strings.HasPrefix(p, filepath.Clean(dir)+string(filepath.Separator)) {
			return fmt.Errorf("bundle: unsafe path %q", name)
		}
		if err := os.MkdirAll(filepath.Dir(p), 0o700); err != nil {
			return err
		}
		mode, ok := b.Modes[name]
		if !ok {
			mode = 0o644
			if strings.HasSuffix(name, ".sh") || name == "control-plane/launchroom" {
				mode = 0o755
			}
		}
		if err := os.WriteFile(p, content, mode); err != nil {
			return err
		}
	}
	return nil
}
