package transfer

import (
	"bytes"
	"context"
	"errors"
	"io"
	"os"
	"path/filepath"
	"strings"
	"sync"
	"testing"
	"time"

	"github.com/vantezzen/launchroom/daemon/internal/controlplane"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

const (
	tid  = state.TransferID("dtr_01J9Z8X7W6V5T4S3R2Q1P0N9R0")
	task = state.TaskID("tsk_01J9Z8X7W6V5T4S3R2Q1P0N9R1")
	mib  = 1 << 20
)

// fakeDashboard serves an upload and records export chunks.
type fakeDashboard struct {
	mu      sync.Mutex
	data    []byte
	chunk   int
	fetched []int
	failAt  map[int]int // chunk → remaining transient failures
	refuse  bool
	put     map[int][]byte
}

func (f *fakeDashboard) FetchTransferChunk(_ context.Context, _ state.TransferID, n int, limit int64) ([]byte, error) {
	f.mu.Lock()
	defer f.mu.Unlock()
	if f.refuse {
		return nil, &controlplane.APIError{Status: 404}
	}
	if f.failAt[n] > 0 {
		f.failAt[n]--
		return nil, &controlplane.APIError{Status: 503}
	}
	f.fetched = append(f.fetched, n)
	lo := n * f.chunk
	hi := min(lo+f.chunk, len(f.data))
	if int64(hi-lo) > limit {
		return nil, errors.New("too big")
	}
	return bytes.Clone(f.data[lo:hi]), nil
}

func (f *fakeDashboard) PutTransferChunk(_ context.Context, _ state.TransferID, n int, data []byte) error {
	f.mu.Lock()
	defer f.mu.Unlock()
	if f.failAt[n] > 0 {
		f.failAt[n]--
		return &controlplane.APIError{Status: 502}
	}
	if f.put == nil {
		f.put = map[int][]byte{}
	}
	f.put[n] = bytes.Clone(data)
	return nil
}

func payload(n int) []byte {
	b := make([]byte, n)
	for i := range b {
		b[i] = byte(i * 7 % 251)
	}
	return b
}

func ref(data []byte, chunk int) state.TransferRef {
	return state.TransferRef{ID: tid, SizeBytes: int64(len(data)), ChunkBytes: int64(chunk), Chunks: (len(data) + chunk - 1) / chunk,
		SHA256: state.SHA256Hex(FileDigest(data, chunk))}
}

func opts(dir string) Options {
	return Options{Dir: dir, Free: func(string) (int64, bool) { return 1 << 40, true }, Backoff: time.Millisecond}
}

func TestFetchVerifiesAndResumes(t *testing.T) {
	data := payload(5*mib/2 + 17)
	f := &fakeDashboard{data: data, chunk: mib, failAt: map[int]int{1: 2}}
	dir := t.TempDir()
	r := ref(data, mib)
	var last int64
	path, err := Fetch(context.Background(), f, r, task, opts(dir), func(done, _ int64) { last = done })
	if err != nil {
		t.Fatal(err)
	}
	got, _ := os.ReadFile(path) //nolint:gosec // test path
	if !bytes.Equal(got, data) || last != int64(len(data)) {
		t.Fatalf("fetched %d bytes, progress %d", len(got), last)
	}

	// A re-leased attempt keeps whole chunks and refetches only the rest (a torn chunk is cut).
	if err := os.Truncate(path, mib+100); err != nil {
		t.Fatal(err)
	}
	f.fetched = nil
	if _, err := Fetch(context.Background(), f, r, task, opts(dir), nil); err != nil {
		t.Fatal(err)
	}
	if len(f.fetched) != 2 || f.fetched[0] != 1 {
		t.Fatalf("resume fetched %v", f.fetched)
	}
}

func TestFetchRefusesWrongDigestAndFullDisks(t *testing.T) {
	data := payload(3 * mib)
	dir := t.TempDir()
	r := ref(data, mib)
	r.SHA256 = state.SHA256Hex(strings.Repeat("0", 64))
	if _, err := Fetch(context.Background(), &fakeDashboard{data: data, chunk: mib}, r, task, opts(dir), nil); !errors.Is(err, ErrDigest) {
		t.Fatalf("wrong digest: %v", err)
	}
	if _, err := os.Stat(PartPath(dir, task)); !os.IsNotExist(err) {
		t.Fatal("a file that failed its digest was kept")
	}
	o := opts(dir)
	o.Free = func(string) (int64, bool) { return 2 * mib, true }
	if _, err := Fetch(context.Background(), &fakeDashboard{data: data, chunk: mib}, ref(data, mib), task, o, nil); err == nil || !strings.Contains(err.Error(), "disk space") {
		t.Fatalf("full disk: %v", err)
	}
	// A refusal (404) is final, not retried.
	f := &fakeDashboard{data: data, chunk: mib, refuse: true}
	if _, err := Fetch(context.Background(), f, ref(data, mib), task, opts(dir), nil); err == nil {
		t.Fatal("refused fetch succeeded")
	}
}

func TestUploader(t *testing.T) {
	data := payload(2*mib + 5)
	f := &fakeDashboard{failAt: map[int]int{0: 1}}
	u := NewUploader(context.Background(), f, state.TransferTarget{ID: tid, ChunkBytes: mib}, nil)
	u.backoff = time.Millisecond
	if _, err := io.Copy(u, bytes.NewReader(data)); err != nil {
		t.Fatal(err)
	}
	if err := u.Close(); err != nil {
		t.Fatal(err)
	}
	res := u.Result()
	if res.SizeBytes != int64(len(data)) || res.Chunks != 3 || res.SHA256 != FileDigest(data, mib) {
		t.Fatalf("result %+v", res)
	}
	var joined []byte
	for i := range 3 {
		if i < 2 && len(f.put[i]) != mib {
			t.Fatalf("chunk %d has %d bytes", i, len(f.put[i]))
		}
		joined = append(joined, f.put[i]...)
	}
	if !bytes.Equal(joined, data) {
		t.Fatal("uploaded bytes differ")
	}
}

func TestSweep(t *testing.T) {
	dir := t.TempDir()
	old, fresh := filepath.Join(dir, "a.part"), filepath.Join(dir, "b.part")
	for _, p := range []string{old, fresh} {
		if err := os.WriteFile(p, []byte("x"), 0o600); err != nil {
			t.Fatal(err)
		}
	}
	past := time.Now().Add(-48 * time.Hour)
	_ = os.Chtimes(old, past, past)
	Sweep(dir, time.Now())
	if _, err := os.Stat(old); !os.IsNotExist(err) {
		t.Fatal("old part kept")
	}
	if _, err := os.Stat(fresh); err != nil {
		t.Fatal("fresh part removed")
	}
}

func TestHumanBytes(t *testing.T) {
	for n, want := range map[int64]string{999: "999 B", 1200: "1.2 kB", 4_100_000_000: "4.1 GB", 412_000_000: "412 MB"} {
		if got := HumanBytes(n); got != want {
			t.Errorf("%d: %q", n, got)
		}
	}
}
