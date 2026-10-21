package certstore

import (
	"archive/tar"
	"bytes"
	"errors"
	"io"
	"os"
	"path/filepath"
	"slices"
	"strings"

	"github.com/vantezzen/launchroom/daemon/internal/atomicfile"
)

// FileStoragePath is Caddy's certificate directory in its data volume.
const FileStoragePath = "/data/caddy/certificates"

// MaxArchiveBytes bounds the tar of Caddy's certificate directory.
const MaxArchiveBytes = 64 << 20

// FromTar reads certificates (without keys unless withKeys) from a tar of
// Caddy's file storage as returned by the Docker archive API for
// FileStoragePath (entries "certificates/<issuer>/<name>/<name>.crt").
func FromTar(archive []byte, withKeys bool) ([]Cert, error) {
	tr := tar.NewReader(bytes.NewReader(archive))
	files := map[[3]string][]byte{}
	for {
		h, err := tr.Next()
		if errors.Is(err, io.EOF) {
			break
		}
		if err != nil {
			return nil, err
		}
		if h.Typeflag != tar.TypeReg || h.Size > maxBulk {
			continue
		}
		issuer, name, ext, ok := parseKey(strings.TrimPrefix(h.Name, "./"))
		if !ok || (ext == ".key" && !withKeys) {
			continue
		}
		data, err := io.ReadAll(io.LimitReader(tr, maxBulk))
		if err != nil {
			return nil, err
		}
		files[[3]string{issuer, name, ext}] = data
	}
	return sorted(assemble(files)), nil
}

func sorted(certs []Cert) []Cert {
	slices.SortFunc(certs, func(a, b Cert) int {
		if c := strings.Compare(a.Name, b.Name); c != 0 {
			return c
		}
		return strings.Compare(a.IssuerKey, b.IssuerKey)
	})
	return certs
}

// Mirror is launchroomd's local copy of the certificates it routes
// (data/caddy/certs, docs/05 §5): "<name>.crt" + "<name>.key" (0600). It is
// what Caddy loads as files when the shared storage is unreachable at start.
type Mirror struct {
	Dir string
}

// Replace makes the mirror hold exactly certs (with keys); others are removed.
func (m Mirror) Replace(certs []Cert) error {
	if err := os.MkdirAll(m.Dir, 0o700); err != nil {
		return err
	}
	keep := map[string]bool{}
	for _, c := range certs {
		if len(c.KeyPEM) == 0 || !safeName(c.Name) {
			continue
		}
		keep[c.Name+".crt"], keep[c.Name+".key"] = true, true
		for file, data := range map[string][]byte{c.Name + ".crt": c.CertPEM, c.Name + ".key": c.KeyPEM} {
			p := filepath.Join(m.Dir, file)
			if old, err := os.ReadFile(p); err == nil && bytes.Equal(old, data) { //nolint:gosec // fixed dir, safe name
				continue
			}
			if err := atomicfile.Write(p, data, 0o600); err != nil {
				return err
			}
		}
	}
	entries, err := os.ReadDir(m.Dir)
	if err != nil {
		return err
	}
	for _, e := range entries {
		if !keep[e.Name()] && e.Type().IsRegular() {
			_ = os.Remove(filepath.Join(m.Dir, e.Name()))
		}
	}
	return nil
}

// Load returns the mirrored certificates (with keys).
func (m Mirror) Load() ([]Cert, error) {
	entries, err := os.ReadDir(m.Dir)
	if errors.Is(err, os.ErrNotExist) {
		return nil, nil
	}
	if err != nil {
		return nil, err
	}
	var out []Cert
	for _, e := range entries {
		name, ok := strings.CutSuffix(e.Name(), ".crt")
		if !ok || !safeName(name) || !e.Type().IsRegular() {
			continue
		}
		crt, err1 := os.ReadFile(filepath.Join(m.Dir, name+".crt")) //nolint:gosec // fixed dir, safe name
		key, err2 := os.ReadFile(filepath.Join(m.Dir, name+".key")) //nolint:gosec // fixed dir, safe name
		if err1 != nil || err2 != nil {
			continue
		}
		l, err := leaf(crt)
		if err != nil {
			continue
		}
		out = append(out, Cert{Name: name, IssuerKey: "mirror", CertPEM: crt, KeyPEM: key, Leaf: l})
	}
	return sorted(out), nil
}
