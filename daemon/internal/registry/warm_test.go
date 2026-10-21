package registry

import (
	"crypto/sha256"
	"encoding/hex"
	"net/http"
	"net/http/httptest"
	"strings"
	"sync"
	"testing"
	"time"

	"github.com/containerd/platforms"
	ocispec "github.com/opencontainers/image-spec/specs-go/v1"
)

func digestOf(b []byte) string {
	s := sha256.Sum256(b)
	return "sha256:" + hex.EncodeToString(s[:])
}

func TestWarm(t *testing.T) {
	arm := []byte(`{"schemaVersion":2,"mediaType":"application/vnd.oci.image.manifest.v1+json","config":{"digest":"sha256:c0","size":10},` +
		`"layers":[{"digest":"sha256:aaaaaaaaaaaa00","size":400},{"digest":"sha256:bbbbbbbbbbbb00","size":600}]}`)
	amd := []byte(`{"schemaVersion":2,"layers":[{"digest":"sha256:dddddddddddd00","size":7}]}`)
	index := []byte(`{"schemaVersion":2,"mediaType":"application/vnd.oci.image.index.v1+json","manifests":[` +
		`{"digest":"` + digestOf(amd) + `","size":1,"platform":{"os":"linux","architecture":"amd64"}},` +
		`{"digest":"` + digestOf(arm) + `","size":1,"platform":{"os":"linux","architecture":"arm64","variant":"v8"}},` +
		`{"digest":"sha256:eeee","size":1,"platform":{"os":"unknown","architecture":"unknown"}}]}`)
	bodies := map[string][]byte{digestOf(index): index, digestOf(arm): arm, digestOf(amd): amd, "sha256:" + strings.Repeat("f", 64): []byte(`{"tampered":true}`)}

	var mu sync.Mutex
	var seen []string
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		mu.Lock()
		seen = append(seen, r.URL.Path+" "+r.Header.Get("Authorization"))
		mu.Unlock()
		d := r.URL.Path[strings.LastIndex(r.URL.Path, "/")+1:]
		if !strings.HasPrefix(r.URL.Path, "/v2/mirror/ghcr.io/x/y/manifests/") || bodies[d] == nil {
			w.WriteHeader(http.StatusNotFound)
			return
		}
		if d == digestOf(arm) {
			time.Sleep(50 * time.Millisecond) // the mirror syncs before it answers
		}
		_, _ = w.Write(bodies[d])
	}))
	defer srv.Close()
	host := strings.TrimPrefix(srv.URL, "http://")

	now := time.Date(2026, 10, 19, 12, 0, 0, 0, time.UTC)
	cp := &fakeCP{ttl: 5 * time.Minute, now: func() time.Time { return now }}
	tk := NewTokens(cp)
	tk.now = cp.now
	warm := tk.Warm(host, nil, platforms.Only(ocispec.Platform{OS: "linux", Architecture: "arm64", Variant: "v8"}))

	sizes, err := warm(t.Context(), host+"/mirror/ghcr.io/x/y@"+digestOf(index))
	if err != nil {
		t.Fatal(err)
	}
	if len(sizes) != 2 || sizes["sha256:aaaaaaaaaaaa00"] != 400 || sizes["sha256:bbbbbbbbbbbb00"] != 600 {
		t.Fatalf("sizes %v", sizes)
	}
	mu.Lock()
	if len(seen) != 2 || !strings.HasSuffix(seen[1], digestOf(arm)+" Bearer h.p.s1") {
		t.Fatalf("requests %q", seen)
	}
	mu.Unlock()
	if len(cp.reqs) != 1 || cp.reqs[0].Scopes[0].Repository != "mirror/ghcr.io/x/y" {
		t.Fatalf("token requests %+v", cp.reqs)
	}

	// Elsewhere: nothing to warm.
	if s, err := warm(t.Context(), "docker.io/library/caddy@"+digestOf(index)); s != nil || err != nil {
		t.Fatalf("foreign image: %v %v", s, err)
	}
	// A body that doesn't match its digest is refused.
	if _, err := warm(t.Context(), host+"/mirror/ghcr.io/x/y@sha256:"+strings.Repeat("f", 64)); err == nil || !strings.Contains(err.Error(), "digest mismatch") {
		t.Fatalf("tampered: %v", err)
	}
	// No manifest for this platform.
	riscv := tk.Warm(host, nil, platforms.Only(ocispec.Platform{OS: "linux", Architecture: "riscv64"}))
	if _, err := riscv(t.Context(), host+"/mirror/ghcr.io/x/y@"+digestOf(index)); err == nil {
		t.Fatal("no platform match accepted")
	}
	// Registry errors surface.
	if _, err := warm(t.Context(), host+"/mirror/ghcr.io/x/y@sha256:"+strings.Repeat("0", 64)); err == nil || !strings.Contains(err.Error(), "HTTP 404") {
		t.Fatalf("404: %v", err)
	}
}
