package sealed

import (
	"crypto/ecdh"
	"crypto/rand"
	"strings"
	"testing"

	"github.com/vantezzen/launchroom/daemon/internal/state"
)

func TestOpenMeshAuthKey(t *testing.T) {
	priv, err := ecdh.X25519().GenerateKey(rand.Reader)
	if err != nil {
		t.Fatal(err)
	}
	const srv = state.ServerID("srv_01J9Z8X7W6V5T4S3R2Q1P0N9MK")
	box, err := SealJSON(state.MeshAuthKey{ServerID: srv, AuthKey: "hskey-auth-abc"}, priv.PublicKey(), rand.Reader)
	if err != nil {
		t.Fatal(err)
	}
	key, err := OpenMeshAuthKey(box, priv, srv)
	if err != nil || key.Reveal() != "hskey-auth-abc" {
		t.Fatalf("open: %v %q", err, key.Reveal())
	}
	if _, err := OpenMeshAuthKey(box, priv, "srv_01J9Z8X7W6V5T4S3R2Q1P0N9MZ"); err == nil || strings.Contains(err.Error(), "hskey") {
		t.Fatalf("other server: %v", err)
	}
	other, _ := ecdh.X25519().GenerateKey(rand.Reader)
	if _, err := OpenMeshAuthKey(box, other, srv); err == nil {
		t.Fatal("wrong key must not open")
	}
	bad, _ := SealJSON(state.MeshAuthKey{ServerID: srv, AuthKey: "has space"}, priv.PublicKey(), rand.Reader)
	if _, err := OpenMeshAuthKey(bad, priv, srv); err == nil || strings.Contains(err.Error(), "has space") {
		t.Fatalf("invalid key: %v", err)
	}
}
