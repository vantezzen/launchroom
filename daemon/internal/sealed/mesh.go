package sealed

import (
	"crypto/ecdh"
	"fmt"

	"github.com/vantezzen/launchroom/daemon/internal/secret"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// OpenMeshAuthKey opens the sealed mesh pre-auth key of
// NodeState.system.tailscale and checks that it was sealed for exactly this
// server. The key is a secret; errors never contain it.
func OpenMeshAuthKey(s state.Sealed, priv *ecdh.PrivateKey, server state.ServerID) (secret.Secret, error) {
	doc, err := openDoc[state.MeshAuthKey](s, priv, "MeshAuthKey")
	if err != nil {
		return "", err
	}
	if doc.ServerID != server {
		return "", fmt.Errorf("sealed: mesh auth key was sealed for %s, not %s", doc.ServerID, server)
	}
	return doc.AuthKey, nil
}
