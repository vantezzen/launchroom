package commands

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"io"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"sort"
	"strconv"
	"strings"
	"sync"
	"testing"
	"time"

	"github.com/vantezzen/launchroom/cli/internal/exitcode"
)

// fakeData is the slice of /api/v1 the data commands use.
type fakeData struct {
	t   *testing.T
	srv *httptest.Server

	mu          sync.Mutex
	chunkBytes  int64
	size        int64
	received    map[int][]byte
	puts        []int
	created     int
	completed   bool
	importBody  map[string]any
	importPolls int
	importFinal string
	exportBody  map[string]any
	exportPolls int
	exportFinal string
	download    []byte
	lengthLie   int64
	links       int
}

func newFakeData(t *testing.T) *fakeData {
	f := &fakeData{t: t, chunkBytes: 4, received: map[int][]byte{}, importFinal: "succeeded", exportFinal: "ready", download: []byte("PGDMP-fake-dump-bytes")}
	mux := http.NewServeMux()
	auth := func(h http.HandlerFunc) http.HandlerFunc {
		return func(w http.ResponseWriter, r *http.Request) {
			if r.Header.Get("Authorization") != "Bearer "+testToken {
				writeJSON(w, 401, map[string]any{"error": map[string]any{"code": "unauthenticated", "message": "Sign in again."}})
				return
			}
			h(w, r)
		}
	}
	env := map[string]any{"id": "env_1", "projectId": "prj_1", "name": "production", "slug": "production", "kind": "production"}
	db := map[string]any{"id": "svc_db", "environmentId": "env_1", "name": "Postgres", "slug": "postgres", "kind": "database", "status": "live", "urls": []any{},
		"volumes": []any{map[string]any{"id": "vol_1", "mountPath": "/var/lib/postgresql/data"}}}
	mux.HandleFunc("GET /api/v1/environments/env_1", auth(func(w http.ResponseWriter, _ *http.Request) { writeJSON(w, 200, map[string]any{"data": env}) }))
	mux.HandleFunc("GET /api/v1/environments/env_1/services", auth(func(w http.ResponseWriter, _ *http.Request) {
		writeJSON(w, 200, map[string]any{"data": []any{db}})
	}))
	transfer := func() map[string]any {
		chunks := int((f.size + f.chunkBytes - 1) / f.chunkBytes)
		got := []int{}
		for i := range f.received {
			got = append(got, i)
		}
		sort.Ints(got)
		status := "receiving"
		if f.completed {
			status = "ready"
		}
		return map[string]any{"id": "dtr_1", "purpose": "database_import", "direction": "upload", "status": status, "fileName": "old.dump",
			"sizeBytes": f.size, "chunkBytes": f.chunkBytes, "chunks": chunks, "receivedChunks": got, "format": "postgres_custom",
			"detail": "PostgreSQL custom dump · from PostgreSQL 15.8", "createdAt": "2026-10-21T10:00:00Z"}
	}
	mux.HandleFunc("POST /api/v1/environments/env_1/services/svc_db/transfers", auth(func(w http.ResponseWriter, r *http.Request) {
		var body map[string]any
		_ = json.NewDecoder(r.Body).Decode(&body)
		f.mu.Lock()
		defer f.mu.Unlock()
		f.created++
		f.size = int64(body["sizeBytes"].(float64))
		if body["purpose"] != "database_import" || body["fileName"] != "old.dump" {
			t.Errorf("create body = %v", body)
		}
		writeJSON(w, 201, map[string]any{"data": transfer()})
	}))
	mux.HandleFunc("GET /api/v1/transfers/dtr_1", auth(func(w http.ResponseWriter, _ *http.Request) {
		f.mu.Lock()
		defer f.mu.Unlock()
		writeJSON(w, 200, map[string]any{"data": transfer()})
	}))
	mux.HandleFunc("PUT /api/v1/transfers/dtr_1/chunks/{n}", auth(func(w http.ResponseWriter, r *http.Request) {
		n, _ := strconv.Atoi(r.PathValue("n"))
		body, _ := io.ReadAll(r.Body)
		sum := sha256.Sum256(body)
		if r.Header.Get("X-Chunk-Sha256") != hex.EncodeToString(sum[:]) || r.Header.Get("Content-Type") != "application/octet-stream" {
			t.Errorf("chunk %d headers: %v", n, r.Header)
		}
		f.mu.Lock()
		defer f.mu.Unlock()
		f.received[n] = body
		f.puts = append(f.puts, n)
		writeJSON(w, 200, map[string]any{"data": map[string]any{"index": n, "received": len(f.received), "chunks": 3}})
	}))
	mux.HandleFunc("POST /api/v1/transfers/dtr_1/complete", auth(func(w http.ResponseWriter, _ *http.Request) {
		f.mu.Lock()
		defer f.mu.Unlock()
		f.completed = true
		writeJSON(w, 200, map[string]any{"data": transfer()})
	}))
	importRun := func(status string) map[string]any {
		run := map[string]any{"id": "imp_1", "subject": "database", "status": status, "sourceKind": "file", "sourceLabel": "old.dump", "replace": f.importBody["replace"], "stopDependents": f.importBody["stopDependents"], "createdAt": "2026-10-21T10:00:00Z"}
		switch status {
		case "succeeded":
			run["result"] = map[string]any{"tables": 14, "files": 0, "sizeBytes": 48234496, "warnings": []any{"Ownership was left out"}, "durationMs": 73000}
		case "failed":
			run["error"] = "This dump comes from PostgreSQL 18.1, but postgres runs PostgreSQL 17."
		case "importing":
			run["step"] = "Fetching 1.2 MB of 4.0 MB"
		}
		return run
	}
	mux.HandleFunc("POST /api/v1/environments/env_1/services/svc_db/imports", auth(func(w http.ResponseWriter, r *http.Request) {
		f.mu.Lock()
		defer f.mu.Unlock()
		_ = json.NewDecoder(r.Body).Decode(&f.importBody)
		writeJSON(w, 201, map[string]any{"data": importRun("waiting_for_safety_backup")})
	}))
	mux.HandleFunc("GET /api/v1/imports/imp_1", auth(func(w http.ResponseWriter, _ *http.Request) {
		f.mu.Lock()
		defer f.mu.Unlock()
		f.importPolls++
		status := "importing"
		if f.importPolls >= 2 {
			status = f.importFinal
		}
		writeJSON(w, 200, map[string]any{"data": importRun(status)})
	}))
	exportRun := func(status string) map[string]any {
		run := map[string]any{"id": "exp_1", "subject": "database", "source": "live", "status": status, "fileName": "postgres-20261021-1000.dump", "sizeBytes": len(f.download), "format": "postgres_custom", "createdAt": "2026-10-21T10:00:00Z"}
		if status == "failed" {
			run["error"] = "pg_dump failed: connection refused"
		}
		return run
	}
	mux.HandleFunc("POST /api/v1/environments/env_1/services/svc_db/exports", auth(func(w http.ResponseWriter, r *http.Request) {
		f.mu.Lock()
		defer f.mu.Unlock()
		_ = json.NewDecoder(r.Body).Decode(&f.exportBody)
		writeJSON(w, 201, map[string]any{"data": exportRun("preparing")})
	}))
	mux.HandleFunc("GET /api/v1/exports/exp_1", auth(func(w http.ResponseWriter, _ *http.Request) {
		f.mu.Lock()
		defer f.mu.Unlock()
		f.exportPolls++
		writeJSON(w, 200, map[string]any{"data": exportRun(f.exportFinal)})
	}))
	mux.HandleFunc("POST /api/v1/exports/exp_1/link", auth(func(w http.ResponseWriter, _ *http.Request) {
		f.mu.Lock()
		defer f.mu.Unlock()
		f.links++
		writeJSON(w, 200, map[string]any{"data": map[string]any{"url": f.srv.URL + "/api/v1/downloads/tok123", "expiresAt": "2026-10-21T10:05:00Z"}})
	}))
	mux.HandleFunc("GET /api/v1/downloads/tok123", auth(func(w http.ResponseWriter, _ *http.Request) {
		w.Header().Set("Content-Disposition", `attachment; filename="../../etc/postgres-20261021-1000.dump"`)
		body := f.download
		if f.lengthLie > 0 {
			w.Header().Set("Content-Length", strconv.FormatInt(f.lengthLie, 10))
			w.WriteHeader(200)
			_, _ = w.Write(body)
			return
		}
		w.Header().Set("Content-Length", strconv.Itoa(len(body)))
		_, _ = w.Write(body)
	}))
	f.srv = httptest.NewServer(mux)
	t.Cleanup(f.srv.Close)
	old := dataSleep
	dataSleep = func(context.Context, time.Duration) error { return nil }
	t.Cleanup(func() { dataSleep = old })
	return f
}

func dumpFile(t *testing.T, dir string) (string, []byte) {
	t.Helper()
	content := []byte("PGDMPabcdef") // 11 bytes: chunks of 4, 4, 3
	path := filepath.Join(dir, "old.dump")
	if err := os.WriteFile(path, content, 0o600); err != nil {
		t.Fatal(err)
	}
	return path, content
}

func dataHarness(t *testing.T, f *fakeData) *harness {
	h := newHarness(t, f.srv.URL)
	h.env["LAUNCHROOM_URL"] = f.srv.URL
	h.linkTo(t, f.srv.URL)
	return h
}

func TestDbImportUploadsChunksAndFollowsTheRun(t *testing.T) {
	f := newFakeData(t)
	h := dataHarness(t, f)
	path, content := dumpFile(t, h.dir)
	if code := h.run("db", "import", "postgres", path); code != 0 {
		t.Fatalf("exit %d\nstdout %s\nstderr %s", code, h.out, h.err)
	}
	if got := string(f.received[0]) + string(f.received[1]) + string(f.received[2]); got != string(content) {
		t.Fatalf("server got %q", got)
	}
	if f.importBody["transfer"] != "dtr_1" || f.importBody["replace"] != false || f.importBody["stopDependents"] != true {
		t.Fatalf("import body = %v", f.importBody)
	}
	if !strings.Contains(h.out.String(), "Imported 14 tables") || !strings.Contains(h.out.String(), "Ownership was left out") {
		t.Fatalf("stdout = %s", h.out)
	}
	if !strings.Contains(h.err.String(), "Taking a safety snapshot") || !strings.Contains(h.err.String(), "Uploaded old.dump") {
		t.Fatalf("stderr = %s", h.err)
	}
	// A started import forgets the upload: the next run starts a new one.
	if raw, _ := os.ReadFile(filepath.Join(h.app.ConfigDir, "uploads.json")); strings.Contains(string(raw), "dtr_1") {
		t.Fatalf("uploads.json still remembers the transfer: %s", raw)
	}
}

func TestDbImportResumesAnInterruptedUpload(t *testing.T) {
	f := newFakeData(t)
	h := dataHarness(t, f)
	path, _ := dumpFile(t, h.dir)
	info, _ := os.Stat(path)
	f.size = info.Size()
	f.received[0] = []byte("PGDM")
	h.app.saveUploads(uploadState{Uploads: map[string]uploadEntry{uploadKey(path, info): {URL: f.srv.URL, Transfer: "dtr_1"}}})
	if code := h.run("db", "import", "postgres", path); code != 0 {
		t.Fatalf("exit %d\n%s", code, h.err)
	}
	if f.created != 0 {
		t.Fatal("a resumed upload must not create a new transfer")
	}
	if len(f.puts) != 2 || f.puts[0] != 1 || f.puts[1] != 2 {
		t.Fatalf("uploaded chunks %v, want [1 2]", f.puts)
	}
}

func TestDbImportReplaceNeedsATerminalOrYes(t *testing.T) {
	f := newFakeData(t)
	h := dataHarness(t, f)
	path, _ := dumpFile(t, h.dir)
	if code := h.run("db", "import", "postgres", path, "--replace"); code != exitcode.Usage {
		t.Fatalf("exit %d, want %d (%s)", code, exitcode.Usage, h.err)
	}
	if f.created != 0 {
		t.Fatal("nothing may be uploaded before the confirmation")
	}
	if code := h.run("db", "import", "postgres", path, "--replace", "--yes", "--keep-apps-running"); code != 0 {
		t.Fatalf("exit %d\n%s", code, h.err)
	}
	if f.importBody["confirmation"] != "postgres" || f.importBody["replace"] != true || f.importBody["stopDependents"] != false {
		t.Fatalf("import body = %v", f.importBody)
	}
}

func TestDbImportFailureExit11(t *testing.T) {
	f := newFakeData(t)
	f.importFinal = "failed"
	h := dataHarness(t, f)
	path, _ := dumpFile(t, h.dir)
	if code := h.run("db", "import", "postgres", path); code != exitcode.TransferFailed {
		t.Fatalf("exit %d, want %d", code, exitcode.TransferFailed)
	}
	if !strings.Contains(h.out.String(), "PostgreSQL 18.1") {
		t.Fatalf("stdout = %s", h.out)
	}
}

func TestDbExportWritesPartThenRenames(t *testing.T) {
	f := newFakeData(t)
	h := dataHarness(t, f)
	if code := h.run("db", "export", "postgres", "--json"); code != 0 {
		t.Fatalf("exit %d\n%s", code, h.err)
	}
	// The suggested name loses its directories.
	want := filepath.Join(h.dir, "postgres-20261021-1000.dump")
	got, err := os.ReadFile(want) //nolint:gosec // test file
	if err != nil || string(got) != string(f.download) {
		t.Fatalf("file = %q, %v", got, err)
	}
	if _, err := os.Stat(want + ".part"); err == nil {
		t.Fatal("the .part file is left behind")
	}
	out := jsonOnly(t, h.out.Bytes())
	if out["id"] != "exp_1" || out["path"] != want {
		t.Fatalf("json = %v", out)
	}
}

func TestDbExportSizeMismatchLeavesNothing(t *testing.T) {
	f := newFakeData(t)
	f.lengthLie = int64(len(f.download)) + 10
	h := dataHarness(t, f)
	out := filepath.Join(t.TempDir(), "x.dump")
	code := h.run("db", "export", "postgres", "-o", out)
	if code != exitcode.TransferFailed {
		t.Fatalf("exit %d, want %d (%s)", code, exitcode.TransferFailed, h.err)
	}
	for _, p := range []string{out, out + ".part"} {
		if _, err := os.Stat(p); err == nil {
			t.Fatalf("%s exists", p)
		}
	}
}

func TestVolumeExportByMountPathFromBackup(t *testing.T) {
	f := newFakeData(t)
	h := dataHarness(t, f)
	out := filepath.Join(t.TempDir(), "data.tar.gz")
	if code := h.run("volume", "export", "postgres", "/var/lib/postgresql/data", "--backup", "4a1f0c2e", "-o", out); code != 0 {
		t.Fatalf("exit %d\n%s", code, h.err)
	}
	if f.exportBody["volume"] != "vol_1" || f.exportBody["snapshot"] != "4a1f0c2e" {
		t.Fatalf("export body = %v", f.exportBody)
	}
	if code := h.run("volume", "export", "postgres", "/nope", "-o", out+"2"); code != exitcode.NotFound {
		t.Fatalf("unknown mount path: exit %d", code)
	}
}

func TestExportFailureExit11(t *testing.T) {
	f := newFakeData(t)
	f.exportFinal = "failed"
	h := dataHarness(t, f)
	if code := h.run("db", "export", "postgres", "-o", filepath.Join(t.TempDir(), "x")); code != exitcode.TransferFailed {
		t.Fatalf("exit %d", code)
	}
	if f.links != 0 {
		t.Fatal("a failed export must not ask for a link")
	}
}
