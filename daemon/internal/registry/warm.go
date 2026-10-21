package registry

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	"slices"
	"strings"
	"time"

	"github.com/containerd/platforms"
	ocispec "github.com/opencontainers/image-spec/specs-go/v1"

	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// maxManifest bounds a manifest or index body.
const maxManifest = 4 << 20

const manifestAccept = "application/vnd.oci.image.index.v1+json, application/vnd.docker.distribution.manifest.list.v2+json, " +
	"application/vnd.oci.image.manifest.v1+json, application/vnd.docker.distribution.manifest.v2+json"

// Warm returns the docker.MirrorWarm hook (docs/04 §11 "Pulls"): for a
// digest-pinned image in the launchroom registry at host it requests the
// manifest Docker will pull, the index first, then this host's platform
// manifest, with no response-header timeout. Zot's pull-through mirror
// answers a platform manifest it hasn't synced only after it copied every
// layer from upstream, which for large images takes longer than the Docker
// daemon's 30 s header timeout; asking first with a context-bounded wait
// lets the following docker pull read everything from the local registry.
// The result are the platform manifest's compressed layer sizes by digest.
// Images elsewhere return nil, nil. platform nil: this host's default.
func (t *Tokens) Warm(host string, httpc *http.Client, platform platforms.MatchComparer) func(ctx context.Context, ref string) (map[string]int64, error) {
	if httpc == nil {
		httpc = &http.Client{Transport: &http.Transport{
			Proxy: nil, MaxIdleConns: 4, IdleConnTimeout: 30 * time.Second,
		}}
	}
	if platform == nil {
		platform = platforms.Default()
	}
	return func(ctx context.Context, ref string) (map[string]int64, error) {
		repo, ok := RepositoryOf(ref, host)
		if !ok {
			return nil, nil
		}
		_, dgst, ok := strings.Cut(ref, "@")
		if !ok || !strings.HasPrefix(dgst, "sha256:") {
			return nil, fmt.Errorf("registry: warm %s: not digest-pinned", ref)
		}
		get := func(d string) (*manifest, error) {
			tok, err := t.Token(ctx, []state.RegistryScope{{Repository: repo, Actions: []state.RegistryAction{state.RegistryPull}}}, TaskFrom(ctx))
			if err != nil {
				return nil, err
			}
			return fetchManifest(ctx, httpc, "http://"+host+"/v2/"+string(repo)+"/manifests/"+d, d, tok.Reveal())
		}
		m, err := get(dgst)
		if err != nil {
			return nil, err
		}
		if len(m.Manifests) > 0 {
			child, ok := pickPlatform(m.Manifests, platform)
			if !ok {
				return nil, fmt.Errorf("registry: warm %s: no manifest for this platform", ref)
			}
			if m, err = get(child); err != nil {
				return nil, err
			}
		}
		sizes := make(map[string]int64, len(m.Layers))
		for _, l := range m.Layers {
			if strings.HasPrefix(l.Digest, "sha256:") && l.Size >= 0 {
				sizes[l.Digest] = l.Size
			}
		}
		return sizes, nil
	}
}

// manifest is the part of an image index or manifest the warm-up reads.
type manifest struct {
	Manifests []descriptor `json:"manifests"`
	Layers    []descriptor `json:"layers"`
}

type descriptor struct {
	Digest   string            `json:"digest"`
	Size     int64             `json:"size"`
	Platform *ocispec.Platform `json:"platform,omitempty"`
}

// pickPlatform returns the best manifest of an index for platform (Docker's
// own choice: matching platforms, most specific first).
func pickPlatform(list []descriptor, platform platforms.MatchComparer) (string, bool) {
	var cands []descriptor
	for _, d := range list {
		if d.Platform != nil && strings.HasPrefix(d.Digest, "sha256:") && platform.Match(*d.Platform) {
			cands = append(cands, d)
		}
	}
	if len(cands) == 0 {
		return "", false
	}
	slices.SortStableFunc(cands, func(a, b descriptor) int {
		switch {
		case platform.Less(*a.Platform, *b.Platform):
			return -1
		case platform.Less(*b.Platform, *a.Platform):
			return 1
		}
		return 0
	})
	return cands[0].Digest, true
}

func fetchManifest(ctx context.Context, httpc *http.Client, url, dgst, token string) (*manifest, error) {
	req, err := http.NewRequestWithContext(ctx, http.MethodGet, url, nil)
	if err != nil {
		return nil, fmt.Errorf("registry: warm: %w", err)
	}
	req.Header.Set("Accept", manifestAccept)
	req.Header.Set("Authorization", "Bearer "+token)
	res, err := httpc.Do(req)
	if err != nil {
		return nil, fmt.Errorf("registry: warm %s: %w", dgst, err)
	}
	defer func() { _ = res.Body.Close() }()
	if res.StatusCode != http.StatusOK {
		_, _ = io.Copy(io.Discard, io.LimitReader(res.Body, 64<<10))
		return nil, fmt.Errorf("registry: warm %s: HTTP %d", dgst, res.StatusCode)
	}
	body, err := io.ReadAll(io.LimitReader(res.Body, maxManifest+1))
	if err != nil {
		return nil, fmt.Errorf("registry: warm %s: %w", dgst, err)
	}
	if len(body) > maxManifest {
		return nil, fmt.Errorf("registry: warm %s: manifest too large", dgst)
	}
	sum := sha256.Sum256(body)
	if "sha256:"+hex.EncodeToString(sum[:]) != dgst {
		return nil, errors.New("registry: warm " + dgst + ": manifest digest mismatch")
	}
	var m manifest
	if err := json.Unmarshal(body, &m); err != nil {
		return nil, fmt.Errorf("registry: warm %s: %w", dgst, err)
	}
	return &m, nil
}
