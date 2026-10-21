package update

import (
	"bytes"
	"context"
	"crypto"
	_ "embed" // trusted_root.json
	"errors"
	"fmt"
	"log/slog"
	"time"

	"github.com/sigstore/sigstore-go/pkg/bundle"
	"github.com/sigstore/sigstore-go/pkg/root"
	"github.com/sigstore/sigstore-go/pkg/tuf"
	"github.com/sigstore/sigstore-go/pkg/verify"
	"github.com/sigstore/sigstore/pkg/signature"
)

//go:generate go run gen_trusted_root.go

// embeddedTrustedRoot is the Sigstore public-good trusted root (Fulcio CAs,
// Rekor and CT log keys, timestamp authorities) at build time, fetched
// through TUF by gen_trusted_root.go. It is the offline fallback; at
// runtime a fresh root is fetched through TUF when possible.
//
//go:embed trusted_root.json
var embeddedTrustedRoot []byte

// maxSigBundleBytes bounds a .sigstore.json bundle.
const maxSigBundleBytes = 1 << 20

// VerifierOptions configures a Verifier.
type VerifierOptions struct {
	// TUFCacheDir caches TUF metadata (e.g. data/update/tuf). "" = no TUF
	// refresh, only the embedded root.
	TUFCacheDir string
	// TUFTimeout bounds the TUF refresh (default 30 s).
	TUFTimeout time.Duration
	// TrustedRootJSON replaces the embedded root (tests).
	TrustedRootJSON []byte
	Log             *slog.Logger
}

// Verifier checks cosign/sigstore bundles against the pinned Trust.
type Verifier struct {
	trust    Trust
	material root.TrustedMaterial
	verifier *verify.Verifier
}

// NewVerifier builds a verifier for t. Keyless verification requires a
// Fulcio certificate chaining to the trusted root, an SCT from a trusted
// CT log, a Rekor inclusion (promise or proof) and an observer timestamp
// (Rekor integrated time or RFC 3161) within the certificate's validity.
func NewVerifier(ctx context.Context, t Trust, o VerifierOptions) (*Verifier, error) {
	if err := t.Validate(); err != nil {
		return nil, err
	}
	if o.Log == nil {
		o.Log = slog.New(slog.DiscardHandler)
	}
	if !t.Keyless() {
		pk, err := t.publicKey()
		if err != nil {
			return nil, err
		}
		sv, err := signature.LoadVerifier(pk, crypto.SHA256)
		if err != nil {
			return nil, fmt.Errorf("publicKey: %w", err)
		}
		material := root.NewTrustedPublicKeyMaterial(func(string) (root.TimeConstrainedVerifier, error) {
			return nonExpiring{sv}, nil
		})
		v, err := verify.NewVerifier(material, verify.WithNoObserverTimestamps())
		if err != nil {
			return nil, err
		}
		return &Verifier{trust: t, material: material, verifier: v}, nil
	}

	tr, err := trustedRoot(ctx, t, o)
	if err != nil {
		return nil, err
	}
	v, err := verify.NewVerifier(tr,
		verify.WithSignedCertificateTimestamps(1),
		verify.WithTransparencyLog(1),
		verify.WithObserverTimestamps(1),
	)
	if err != nil {
		return nil, err
	}
	return &Verifier{trust: t, material: tr, verifier: v}, nil
}

// trustedRoot prefers a fresh root from Sigstore's TUF repository (rooted
// in the TUF root embedded in sigstore-go, cached in TUFCacheDir) and falls
// back to the root embedded in launchroomd when offline.
func trustedRoot(ctx context.Context, t Trust, o VerifierOptions) (*root.TrustedRoot, error) {
	if len(o.TrustedRootJSON) > 0 {
		return root.NewTrustedRootFromJSON(o.TrustedRootJSON)
	}
	if !t.OfflineOnly && o.TUFCacheDir != "" {
		timeout := o.TUFTimeout
		if timeout <= 0 {
			timeout = 30 * time.Second
		}
		tctx, cancel := context.WithTimeout(ctx, timeout)
		defer cancel()
		raw, err := fetchTUFRoot(tctx, o.TUFCacheDir)
		if err == nil {
			tr, perr := root.NewTrustedRootFromJSON(raw)
			if perr == nil {
				return tr, nil
			}
			err = perr
		}
		o.Log.Warn("could not refresh the Sigstore trusted root through TUF; using the root built into launchroomd", "err", err)
	}
	return root.NewTrustedRootFromJSON(embeddedTrustedRoot)
}

func fetchTUFRoot(ctx context.Context, cacheDir string) ([]byte, error) {
	type res struct {
		b   []byte
		err error
	}
	ch := make(chan res, 1)
	go func() {
		c, err := tuf.New(tuf.DefaultOptions().WithCachePath(cacheDir).WithContext(ctx))
		if err != nil {
			ch <- res{err: err}
			return
		}
		b, err := c.GetTarget("trusted_root.json")
		ch <- res{b, err}
	}()
	select {
	case r := <-ch:
		return r.b, r.err
	case <-ctx.Done():
		return nil, ctx.Err()
	}
}

// Verify checks that sigBundle is a valid signature over artifact by the
// pinned identity for this exact version: keyless, the certificate SAN must
// equal Identity+version (so a bundle signed for another tag is refused)
// and the issuer must match; key mode, the signature must verify with the
// pinned key.
func (v *Verifier) Verify(artifact, sigBundle []byte, version string) error {
	if version == "" {
		return errors.New("version is required")
	}
	return v.verify(verify.WithArtifact(bytes.NewReader(artifact)), sigBundle, v.trust.Identity+version)
}

// VerifyDigest is Verify for an artifact known only by its digest
// (algorithm "sha256", "sha384" or "sha512"), against an explicit SAN.
func (v *Verifier) VerifyDigest(algorithm string, digest, sigBundle []byte, san string) error {
	return v.verify(verify.WithArtifactDigest(algorithm, digest), sigBundle, san)
}

func (v *Verifier) verify(artifact verify.ArtifactPolicyOption, sigBundle []byte, san string) error {
	if len(sigBundle) == 0 || len(sigBundle) > maxSigBundleBytes {
		return errors.New("signature bundle is empty or too large")
	}
	var b bundle.Bundle
	if err := b.UnmarshalJSON(sigBundle); err != nil {
		return fmt.Errorf("signature bundle: %w", err)
	}
	var policy verify.PolicyBuilder
	if v.trust.Keyless() {
		id, err := verify.NewShortCertificateIdentity(v.trust.Issuer, "", san, "")
		if err != nil {
			return err
		}
		policy = verify.NewPolicy(artifact, verify.WithCertificateIdentity(id))
	} else {
		policy = verify.NewPolicy(artifact, verify.WithKey())
	}
	if _, err := v.verifier.Verify(&b, policy); err != nil {
		return fmt.Errorf("signature verification failed: %w", err)
	}
	return nil
}

// nonExpiring is a pinned key without a validity window.
type nonExpiring struct{ signature.Verifier }

func (nonExpiring) ValidAtTime(time.Time) bool { return true }
