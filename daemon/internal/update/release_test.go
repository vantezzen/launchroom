package update

import (
	"archive/tar"
	"bytes"
	"compress/gzip"
	"context"
	"crypto/sha256"
	"encoding/hex"
	"errors"
	"maps"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"slices"
	"strings"
	"testing"
)

const (
	testDaemonImage  = "ghcr.io/vantezzen/launchroomd@sha256:1111111111111111111111111111111111111111111111111111111111111111"
	testComposeImage = "docker.io/library/docker@sha256:2222222222222222222222222222222222222222222222222222222222222222"
)

func bundleFiles(version string) map[string][]byte {
	return map[string][]byte{
		"VERSION":                   []byte(version + "\n"),
		"control-plane/images.env":  []byte("# pins\nLR_DASHBOARD_IMAGE=ghcr.io/vantezzen/launchroom-dashboard@sha256:" + strings.Repeat("3", 64) + "\nLR_DAEMON_IMAGE=" + testDaemonImage + "\nLR_COMPOSE_IMAGE=" + testComposeImage + "\nOTHER=x\n"),
		"control-plane/compose.yml": []byte("name: launchroom\n"),
		"control-plane/launchroom":  []byte("#!/bin/sh\n"),
		"daemon/compose.yml":        []byte("name: launchroomd\n"),
		"install/install.sh":        []byte("#!/bin/sh\n"),
		"install/server.sh":         []byte("#!/bin/sh\n"),
	}
}

func makeTarball(t *testing.T, files map[string][]byte, extra ...*tar.Header) []byte {
	t.Helper()
	var buf bytes.Buffer
	zw := gzip.NewWriter(&buf)
	tw := tar.NewWriter(zw)
	for _, name := range sortedKeys(files) {
		if err := tw.WriteHeader(&tar.Header{Name: name, Mode: 0o644, Size: int64(len(files[name])), Typeflag: tar.TypeReg}); err != nil {
			t.Fatal(err)
		}
		if _, err := tw.Write(files[name]); err != nil {
			t.Fatal(err)
		}
	}
	for _, h := range extra {
		if err := tw.WriteHeader(h); err != nil {
			t.Fatal(err)
		}
	}
	if err := tw.Close(); err != nil {
		t.Fatal(err)
	}
	if err := zw.Close(); err != nil {
		t.Fatal(err)
	}
	return buf.Bytes()
}

func sortedKeys(m map[string][]byte) []string { return slices.Sorted(maps.Keys(m)) }

func TestParseBundle(t *testing.T) {
	b, err := ParseBundle(makeTarball(t, bundleFiles("1.2.3")))
	if err != nil {
		t.Fatal(err)
	}
	if b.Version != "1.2.3" || b.DaemonImage() != testDaemonImage || b.ComposeImage() != testComposeImage {
		t.Fatalf("parsed %+v", b)
	}
	dir := filepath.Join(t.TempDir(), "bundle")
	if err := b.Extract(dir); err != nil {
		t.Fatal(err)
	}
	if st, err := os.Stat(filepath.Join(dir, "install", "server.sh")); err != nil || st.Mode().Perm() != 0o755 {
		t.Fatalf("server.sh: %v %v", st, err)
	}

	bad := map[string][]byte{}
	withFiles := func(mut func(map[string][]byte)) []byte {
		f := bundleFiles("1.2.3")
		mut(f)
		return makeTarball(t, f)
	}
	bad["missing VERSION"] = withFiles(func(f map[string][]byte) { delete(f, "VERSION") })
	bad["v-prefixed VERSION"] = withFiles(func(f map[string][]byte) { f["VERSION"] = []byte("v1.2.3") })
	bad["tag pin"] = withFiles(func(f map[string][]byte) {
		f["control-plane/images.env"] = []byte("LR_DAEMON_IMAGE=ghcr.io/vantezzen/launchroomd:1.2.3\nLR_COMPOSE_IMAGE=" + testComposeImage + "\n")
	})
	bad["no compose image"] = withFiles(func(f map[string][]byte) {
		f["control-plane/images.env"] = []byte("LR_DAEMON_IMAGE=" + testDaemonImage + "\n")
	})
	bad["traversal"] = withFiles(func(f map[string][]byte) { f["../evil"] = []byte("x") })
	bad["absolute"] = withFiles(func(f map[string][]byte) { f["/etc/passwd"] = []byte("x") })
	bad["symlink"] = makeTarball(t, bundleFiles("1.2.3"), &tar.Header{Name: "link", Typeflag: tar.TypeSymlink, Linkname: "/etc"})
	bad["not gzip"] = []byte("plain")
	for name, tarball := range bad {
		if _, err := ParseBundle(tarball); err == nil {
			t.Errorf("%s: accepted", name)
		}
	}
}

// releaseServer serves signed releases like GitHub's download URLs.
type releaseServer struct {
	*httptest.Server
	assets map[string][]byte
}

func newReleaseServer(t *testing.T) *releaseServer {
	t.Helper()
	rs := &releaseServer{assets: map[string][]byte{}}
	rs.Server = httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		b, ok := rs.assets[r.URL.Path]
		if !ok {
			http.NotFound(w, r)
			return
		}
		_, _ = w.Write(b)
	}))
	t.Cleanup(rs.Close)
	return rs
}

func (rs *releaseServer) publish(version string, tarball, sig []byte) {
	sum := sha256.Sum256(tarball)
	p := "/releases/download/v" + version + "/"
	rs.assets[p+BundleAsset] = tarball
	rs.assets[p+BundleAsset+".sha256"] = []byte(hex.EncodeToString(sum[:]) + "  " + BundleAsset + "\n")
	if sig != nil {
		rs.assets[p+BundleAsset+".sigstore.json"] = sig
	}
}

func TestFetchVerifiesBeforeTrusting(t *testing.T) {
	k := newTestKey(t)
	rs := newReleaseServer(t)
	trust := k.trust(rs.URL + "/releases")
	v, err := NewVerifier(context.Background(), trust, VerifierOptions{})
	if err != nil {
		t.Fatal(err)
	}
	f := &Fetcher{Trust: trust}

	good := makeTarball(t, bundleFiles("1.2.3"))
	rs.publish("1.2.3", good, k.sign(t, good))
	b, _, err := f.Fetch(context.Background(), v, "1.2.3")
	if err != nil {
		t.Fatal(err)
	}
	if b.DaemonImage() != testDaemonImage {
		t.Fatal(b.Images)
	}

	// Unsigned release.
	rs.publish("1.2.4", good, nil)
	if _, _, err := f.Fetch(context.Background(), v, "1.2.4"); err == nil {
		t.Error("unsigned release accepted")
	}
	// Signed by another key.
	other := newTestKey(t)
	rs.publish("1.2.5", good, other.sign(t, good))
	if _, _, err := f.Fetch(context.Background(), v, "1.2.5"); err == nil {
		t.Error("foreign signature accepted")
	}
	// Validly signed bundle of 1.2.3 republished as 1.3.0.
	rs.publish("1.3.0", good, k.sign(t, good))
	if _, _, err := f.Fetch(context.Background(), v, "1.3.0"); err == nil || !strings.Contains(err.Error(), "is version") {
		t.Errorf("renamed release accepted: %v", err)
	}
	// Checksum file disagreeing with the signed tarball.
	rs.publish("1.2.6", good, k.sign(t, good))
	rs.assets["/releases/download/v1.2.6/"+BundleAsset+".sha256"] = []byte(strings.Repeat("0", 64) + "  x\n")
	if _, _, err := f.Fetch(context.Background(), v, "1.2.6"); err == nil {
		t.Error("checksum mismatch accepted")
	}
}

func TestCheckNewer(t *testing.T) {
	ok := [][2]string{{"0.1.0", "0.2.0"}, {"0.1.0-dev", "0.1.0"}, {"1.2.0-rc.1", "1.2.0"}, {"v1.0.0", "1.0.1"}, {"0.0.0-dev", "0.1.0"}}
	for _, c := range ok {
		if err := CheckNewer(c[0], c[1]); err != nil {
			t.Errorf("%s → %s: %v", c[0], c[1], err)
		}
	}
	bad := [][2]string{{"0.2.0", "0.1.0"}, {"0.2.0", "0.2.0"}, {"1.2.0", "1.2.0-rc.1"}, {"1.0.0", "latest"}, {"dev", "1.0.0"}, {"1.0.0", "1.0"}}
	for _, c := range bad {
		if err := CheckNewer(c[0], c[1]); err == nil {
			t.Errorf("%s → %s accepted", c[0], c[1])
		}
	}
}

func TestLoadTrust(t *testing.T) {
	dir := t.TempDir()
	if _, err := LoadTrust(dir); !errors.Is(err, ErrNoTrust) {
		t.Fatalf("missing trust: %v", err)
	}
	write := func(s string) {
		if err := os.WriteFile(filepath.Join(dir, TrustFile), []byte(s), 0o600); err != nil {
			t.Fatal(err)
		}
	}
	write(`{"issuer":"https://token.actions.githubusercontent.com","identity":"https://github.com/vantezzen/launchroom/.github/workflows/release.yml@refs/tags/v","releaseBase":"https://github.com/vantezzen/launchroom/releases"}`)
	tr, err := LoadTrust(dir)
	if err != nil || tr != DefaultTrust() {
		t.Fatalf("default trust: %+v %v", tr, err)
	}
	for name, s := range map[string]string{
		"http base":        `{"issuer":"https://token.actions.githubusercontent.com","identity":"https://github.com/x/y/.github/workflows/release.yml@refs/tags/v","releaseBase":"http://example.com"}`,
		"identity no tag":  `{"issuer":"https://token.actions.githubusercontent.com","identity":"https://github.com/x/y/.github/workflows/release.yml","releaseBase":"https://example.com"}`,
		"no issuer":        `{"identity":"https://github.com/x/y/.github/workflows/release.yml@refs/tags/v","releaseBase":"https://example.com"}`,
		"unknown field":    `{"issuer":"https://token.actions.githubusercontent.com","identity":"https://github.com/x/y/.github/workflows/release.yml@refs/tags/v","releaseBase":"https://example.com","identityRegexp":".*"}`,
		"key and identity": `{"issuer":"https://token.actions.githubusercontent.com","identity":"https://github.com/x/y/.github/workflows/release.yml@refs/tags/v","releaseBase":"https://example.com","publicKey":"x"}`,
		"bad key":          `{"releaseBase":"https://example.com","publicKey":"not pem"}`,
		"credentials":      `{"issuer":"https://token.actions.githubusercontent.com","identity":"https://github.com/x/y/.github/workflows/release.yml@refs/tags/v","releaseBase":"https://u:p@example.com"}`,
	} {
		write(s)
		if _, err := LoadTrust(dir); err == nil {
			t.Errorf("%s: accepted", name)
		}
	}
}
