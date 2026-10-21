package update

import (
	"context"
	"crypto/sha256"
	"os"
	"strings"
	"testing"
)

// Recorded real keyless bundle (format compatibility): goreleaser v2.18.3
// signs its checksums.txt with cosign sign-blob in GitHub Actions, the same
// way launchroom's release workflow signs launchroom-deploy.tar.gz. Fetched
// from https://github.com/goreleaser/goreleaser/releases/tag/v2.18.3.
const (
	grIdentity = "https://github.com/goreleaser/goreleaser/.github/workflows/release.yml@refs/tags/v"
	grVersion  = "2.18.3"
)

func grTrust() Trust {
	return Trust{Issuer: DefaultIssuer, Identity: grIdentity, ReleaseBase: DefaultReleaseBase, OfflineOnly: true}
}

func readTestdata(t *testing.T, name string) []byte {
	t.Helper()
	b, err := os.ReadFile("testdata/" + name)
	if err != nil {
		t.Fatal(err)
	}
	return b
}

func TestVerifyRealKeylessBundle(t *testing.T) {
	artifact := readTestdata(t, "goreleaser-v2.18.3-checksums.txt")
	sig := readTestdata(t, "goreleaser-v2.18.3-checksums.txt.sigstore.json")

	v, err := NewVerifier(context.Background(), grTrust(), VerifierOptions{})
	if err != nil {
		t.Fatal(err)
	}
	if err := v.Verify(artifact, sig, grVersion); err != nil {
		t.Fatalf("real bundle did not verify: %v", err)
	}
	sum := sha256.Sum256(artifact)
	if err := v.VerifyDigest("sha256", sum[:], sig, grIdentity+grVersion); err != nil {
		t.Fatalf("digest verification: %v", err)
	}

	cases := map[string]func() error{
		"tampered artifact": func() error {
			return v.Verify(append([]byte("x"), artifact...), sig, grVersion)
		},
		// A genuine signature for another tag must not pass as this version.
		"other version": func() error { return v.Verify(artifact, sig, "2.18.4") },
		"other identity": func() error {
			w, err := NewVerifier(context.Background(), DefaultTrust().offline(), VerifierOptions{})
			if err != nil {
				return nil //nolint:nilerr // reported as accepted below
			}
			return w.Verify(artifact, sig, grVersion)
		},
		"other issuer": func() error {
			tr := grTrust()
			tr.Issuer = "https://accounts.google.com"
			w, err := NewVerifier(context.Background(), tr, VerifierOptions{})
			if err != nil {
				return nil //nolint:nilerr
			}
			return w.Verify(artifact, sig, grVersion)
		},
		"not a bundle":  func() error { return v.Verify(artifact, []byte(`{"mediaType":"x"}`), grVersion) },
		"empty bundle":  func() error { return v.Verify(artifact, nil, grVersion) },
		"empty version": func() error { return v.Verify(artifact, sig, "") },
		"stripped tlog": func() error {
			s := string(sig)
			i := strings.Index(s, `"tlogEntries"`)
			j := strings.Index(s, `"timestampVerificationData"`)
			if i < 0 || j < i {
				t.Fatal("fixture layout changed")
			}
			return v.Verify(artifact, []byte(s[:i]+`"tlogEntries":[],`+s[j:]), grVersion)
		},
		"key mode against keyless bundle": func() error {
			k := newTestKey(t)
			w, err := NewVerifier(context.Background(), k.trust("https://example.com/releases"), VerifierOptions{})
			if err != nil {
				t.Fatal(err)
			}
			return w.Verify(artifact, sig, grVersion)
		},
	}
	for name, f := range cases {
		if err := f(); err == nil {
			t.Errorf("%s: accepted", name)
		}
	}
}

func TestVerifyKeyMode(t *testing.T) {
	k := newTestKey(t)
	artifact := []byte("launchroom deploy bundle")
	sig := k.sign(t, artifact)

	v, err := NewVerifier(context.Background(), k.trust("https://example.com/releases"), VerifierOptions{})
	if err != nil {
		t.Fatal(err)
	}
	if err := v.Verify(artifact, sig, "1.0.0"); err != nil {
		t.Fatalf("valid key signature refused: %v", err)
	}
	if err := v.Verify([]byte("other"), sig, "1.0.0"); err == nil {
		t.Error("tampered artifact accepted")
	}
	other := newTestKey(t)
	w, err := NewVerifier(context.Background(), other.trust("https://example.com/releases"), VerifierOptions{})
	if err != nil {
		t.Fatal(err)
	}
	if err := w.Verify(artifact, sig, "1.0.0"); err == nil {
		t.Error("signature by another key accepted")
	}
	// Keyless policy never accepts a bare key signature.
	kl, err := NewVerifier(context.Background(), DefaultTrust().offline(), VerifierOptions{})
	if err != nil {
		t.Fatal(err)
	}
	if err := kl.Verify(artifact, sig, "1.0.0"); err == nil {
		t.Error("keyless verifier accepted a key signature")
	}
}

func TestEmbeddedTrustedRootParses(t *testing.T) {
	if _, err := trustedRoot(context.Background(), DefaultTrust().offline(), VerifierOptions{}); err != nil {
		t.Fatal(err)
	}
}

func (t Trust) offline() Trust { t.OfflineOnly = true; return t }
