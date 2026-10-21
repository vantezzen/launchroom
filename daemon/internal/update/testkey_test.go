package update

import (
	"testing"

	"github.com/vantezzen/launchroom/daemon/internal/update/updatetest"
)

type testKey struct{ signer *updatetest.Signer }

func newTestKey(t *testing.T) testKey {
	t.Helper()
	s, err := updatetest.NewSigner()
	if err != nil {
		t.Fatal(err)
	}
	return testKey{s}
}

func (k testKey) trust(base string) Trust {
	return Trust{ReleaseBase: base, PublicKey: k.signer.PublicKeyPEM, AllowInsecureReleaseBase: true}
}

func (k testKey) sign(t *testing.T, artifact []byte) []byte {
	t.Helper()
	b, err := k.signer.Sign(artifact)
	if err != nil {
		t.Fatal(err)
	}
	return b
}
