package update

import (
	"archive/tar"
	"bufio"
	"bytes"
	"compress/gzip"
	"context"
	"crypto/sha256"
	"encoding/hex"
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

var reEnvLine = regexp.MustCompile(`^([A-Z][A-Z0-9_]{0,63})=(.*)$`)

// Bundle is a verified, parsed deploy bundle.
type Bundle struct {
	Version string
	// Images are the LR_*_IMAGE pins of control-plane/images.env.
	Images map[string]state.ImageRef
	// Files are the regular files by slash path.
	Files map[string][]byte
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
	for _, need := range []string{envDaemonImage, envComposeImage} {
		if images[need] == "" {
			return nil, fmt.Errorf("bundle: %s is missing from images.env", need)
		}
	}
	sum := sha256.Sum256(tarball)
	return &Bundle{Version: version, Images: images, Files: files, SHA256: hex.EncodeToString(sum[:])}, nil
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

// Extract writes the bundle's files below dir (0700 dirs; install scripts
// 0755, everything else 0644). dir must not exist yet.
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
		mode := os.FileMode(0o644)
		if strings.HasSuffix(name, ".sh") || name == "control-plane/launchroom" {
			mode = 0o755
		}
		if err := os.WriteFile(p, content, mode); err != nil {
			return err
		}
	}
	return nil
}
