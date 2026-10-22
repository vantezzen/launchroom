package build

import (
	"strings"
	"testing"
	"time"

	bkclient "github.com/moby/buildkit/client"
	"github.com/opencontainers/go-digest"
)

// A first build has no registry cache yet: BuildKit reports the import vertex
// as failed but keeps building. That is a note, not the build's failed step.
func TestLogMapperCacheImportNotFound(t *testing.T) {
	s := &sink{}
	m := newLogMapper(dockerfileStep, s)
	now := time.Now()
	ci, run := digest.FromString("cache"), digest.FromString("run")
	ref := "registry.launchroom.internal:5000/cache/svc_01m5fje8dayqrkeg0y2gqsap2k:buildcache"
	m.status(&bkclient.SolveStatus{Vertexes: []*bkclient.Vertex{
		{Digest: ci, Name: "importing cache manifest from " + ref, Started: &now, Completed: &now,
			Error: "failed to configure registry cache importer: " + ref + ": not found"},
		{Digest: run, Name: "[2/3] RUN npm run build", Started: &now, Completed: &now, Error: "exit code: 1"},
	}})
	got := strings.Join(s.lines, "\n")
	if strings.Contains(got, "cache importer") || !strings.Contains(got, "cache|#1 "+NoCacheYet) {
		t.Fatalf("cache import line not rewritten:\n%s", got)
	}
	if m.failedStep() != "build" {
		t.Fatalf("failed step %q, want build", m.failedStep())
	}
	if strings.Contains(m.tail(), "ERROR: failed to configure") {
		t.Fatalf("tail keeps the cache error: %q", m.tail())
	}
}

func TestCacheImportNote(t *testing.T) {
	if got := cacheImportNote("failed to configure registry cache importer: unexpected status 401 Unauthorized"); got != "WARNING: Couldn't load the build cache, building without it (unexpected status 401 Unauthorized)" {
		t.Fatalf("note %q", got)
	}
	if !isCacheImport("importing cache manifest from x") || isCacheImport("exporting cache to registry") {
		t.Fatal("isCacheImport")
	}
}
