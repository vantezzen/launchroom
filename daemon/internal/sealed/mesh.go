package sealed

import (
	"crypto/ecdh"
	"errors"
	"fmt"

	"github.com/vantezzen/launchroom/daemon/internal/secret"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// OpenMeshAuthKey opens the sealed mesh pre-auth key of
// NodeState.system.tailscale and checks that it was sealed for exactly this
// server. The key is a secret; errors never contain it.
func OpenMeshAuthKey(s state.Sealed, priv *ecdh.PrivateKey, server state.ServerID) (secret.Secret, error) {
	plain, err := Open(s.Sealed, priv)
	if err != nil {
		return "", err
	}
	defer clear(plain)
	var doc state.MeshAuthKey
	if err := state.DecodeStrict(plain, &doc); err != nil {
		return "", errors.New("sealed: plaintext is not a valid MeshAuthKey document")
	}
	if err := doc.Validate(); err != nil {
		return "", fmt.Errorf("sealed: %w", err)
	}
	if doc.ServerID != server {
		return "", fmt.Errorf("sealed: mesh auth key was sealed for %s, not %s", doc.ServerID, server)
	}
	return doc.AuthKey, nil
}
