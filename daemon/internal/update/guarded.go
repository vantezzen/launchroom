package update

import (
	"context"
	"errors"
	"fmt"
	"io/fs"
	"os"
	"path/filepath"
)

// guardTrustPath is where the installer pins the release identity on a
// guarded install, outside launchroomd's data directory.
func guardTrustPath(projectDir string) string {
	return filepath.Join(projectDir, dirGuard, TrustFile)
}

// Guarded reports whether the install in projectDir runs launchroomd behind
// dockerd-guard (the installer wrote guard/trust.json).
func Guarded(projectDir string) bool {
	_, err := os.Stat(guardTrustPath(projectDir))
	return err == nil
}

// prepareGuarded is the supervisor's own verification on a guarded install
// (D49). launchroomd cannot reach the Docker socket there, but it writes the
// plan and the staged bundle: a compromised launchroomd must not be able to
// hand the supervisor (which has the socket) an installer of its own. So the
// supervisor, running launchroomd's previous (known-good) image:
//
//   - verifies the signed tarball again against guard/trust.json, which
//     launchroomd cannot write, with the embedded Sigstore root (it has no
//     network),
//   - requires the plan's target version to be the bundle's and newer than
//     its own version,
//   - extracts the bundle into guard/update/<task>/bundle and takes the
//     image pins from it (not from the plan),
//   - keeps the backup of the managed files there too, so a rollback never
//     restores files launchroomd could have changed.
//
// It returns the backup directory to use.
func (s *Supervisor) prepareGuarded(ctx context.Context, p *Plan) (string, error) {
	trust, err := loadTrustFile(guardTrustPath(p.ProjectDir))
	if err != nil {
		return "", fmt.Errorf("guarded install: %w", err)
	}
	trust.OfflineOnly = true
	v, err := NewVerifier(ctx, trust, VerifierOptions{TrustedRootJSON: s.TrustedRootJSON, Log: s.Log})
	if err != nil {
		return "", fmt.Errorf("guarded install: %w", err)
	}
	dir := taskDir(p.DataDir, p.TaskID)
	tarball, err := readLimited(filepath.Join(dir, fileBundleTar), maxBundleBytes)
	if err != nil {
		return "", fmt.Errorf("guarded install: the staged bundle: %w", err)
	}
	sig, err := readLimited(filepath.Join(dir, fileBundleSig), maxSigBundleBytes)
	if err != nil {
		return "", fmt.Errorf("guarded install: the staged signature: %w", err)
	}
	if err := v.Verify(tarball, sig, p.To); err != nil {
		return "", fmt.Errorf("guarded install: the staged bundle does not verify: %w", err)
	}
	b, err := ParseBundle(tarball)
	if err != nil {
		return "", fmt.Errorf("guarded install: %w", err)
	}
	if b.Version != p.To {
		return "", fmt.Errorf("guarded install: the signed bundle is version %q, the plan says %q", b.Version, p.To)
	}
	if s.Version != "" {
		if err := CheckNewer(s.Version, b.Version); err != nil {
			return "", fmt.Errorf("guarded install: %w", err)
		}
	}
	own := filepath.Join(p.ProjectDir, dirGuard, dirUpdate, string(p.TaskID))
	if err := os.RemoveAll(own); err != nil {
		return "", err
	}
	if err := os.MkdirAll(own, 0o700); err != nil {
		return "", err
	}
	if err := b.Extract(filepath.Join(own, dirBundle)); err != nil {
		return "", err
	}
	p.BundleDir = filepath.Join(own, dirBundle)
	p.DaemonImage, p.ComposeImage = b.DaemonImage(), b.ComposeImage()
	if err := p.ComposeImage.Validate(); err != nil {
		return "", fmt.Errorf("guarded install: %w", err)
	}
	return filepath.Join(own, dirBackup), nil
}

// loadTrustFile reads a trust pin from an explicit path.
func loadTrustFile(path string) (Trust, error) {
	t, err := LoadTrust(filepath.Dir(path))
	if errors.Is(err, fs.ErrNotExist) || errors.Is(err, ErrNoTrust) {
		return Trust{}, fmt.Errorf("%s is missing", path)
	}
	return t, err
}
