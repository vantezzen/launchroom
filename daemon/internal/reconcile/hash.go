package reconcile

import (
	"crypto/hmac"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"strings"

	"github.com/vantezzen/launchroom/daemon/internal/docker"
)

// EnvHashKey derives the key for env digests in spec hashes from local key
// material, so the hash label (readable by anyone with Docker access) cannot
// be used to brute-force low-entropy secret values.
func EnvHashKey(secretMaterial []byte) []byte {
	m := hmac.New(sha256.New, []byte("launchroom/spec-hash/env/v1"))
	m.Write(secretMaterial)
	return m.Sum(nil)
}

// SpecHash returns a digest of everything a container is created from. Env
// values are replaced by a keyed HMAC so the hash changes when a value
// changes but does not reveal it. The spec-hash label itself is excluded.
// Config files count by path and content digest.
func SpecHash(cc *docker.ContainerCreate, envKey []byte) (string, error) {
	cfg := *cc.Config
	cfg.Labels = make(map[string]string, len(cc.Config.Labels))
	for k, v := range cc.Config.Labels {
		if k != docker.LabelSpecHash {
			cfg.Labels[k] = v
		}
	}
	cfg.Env = make([]string, len(cc.Config.Env))
	for i, kv := range cc.Config.Env {
		name, value, _ := strings.Cut(kv, "=")
		m := hmac.New(sha256.New, envKey)
		m.Write([]byte(value))
		cfg.Env[i] = name + "=" + hex.EncodeToString(m.Sum(nil))
	}
	// Config files by path and content digest (docs/03 §8): a changed file
	// recreates the container. Omitted when there are none, so hashes of
	// containers without files stay as they were.
	var files []string
	for _, f := range cc.Files {
		sum := sha256.Sum256([]byte(f.Content))
		files = append(files, string(f.Path)+"="+hex.EncodeToString(sum[:]))
	}
	data, err := json.Marshal(struct {
		Name       string
		Config     any
		HostConfig any
		Networking any
		Files      []string `json:",omitempty"`
	}{cc.Name, cfg, cc.HostConfig, cc.Networking, files})
	if err != nil {
		return "", fmt.Errorf("reconcile: hash container spec: %w", err)
	}
	sum := sha256.Sum256(data)
	return hex.EncodeToString(sum[:16]), nil
}
