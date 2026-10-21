// Package transfer moves files between launchroomd and the dashboard in
// chunks over the signed channel (D55, docs/14 §12.1–12.2): Fetch downloads
// an uploaded file chunk by chunk into the daemon's data directory, resuming
// a re-leased attempt and verifying the file digest before anyone reads it;
// Uploader cuts an export stream into chunks and uploads them.
//
// A file's digest is SHA-256 over the concatenated raw SHA-256 digests of
// its chunks in order, so browsers (whose Web Crypto has no streaming hash)
// can compute it chunk by chunk.
package transfer

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"errors"
	"fmt"
	"hash"
	"io"
	"os"
	"path/filepath"
	"strings"
	"time"

	"github.com/vantezzen/launchroom/daemon/internal/controlplane"
	"github.com/vantezzen/launchroom/daemon/internal/hostinfo"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// Client is the dashboard side of transfers (controlplane.Client).
type Client interface {
	FetchTransferChunk(ctx context.Context, id state.TransferID, n int, limit int64) ([]byte, error)
	PutTransferChunk(ctx context.Context, id state.TransferID, n int, data []byte) error
}

var _ Client = (*controlplane.Client)(nil)

// Retries is how often one chunk request is tried before the transfer fails.
const Retries = 5

// DiskMargin is the free space a fetch leaves on the data directory's filesystem.
const DiskMargin = 1 << 30

// sweepAge is how old a leftover partial file must be before Sweep removes it.
const sweepAge = 24 * time.Hour

// Progress receives the bytes done so far and the total (0: unknown).
type Progress func(done, total int64)

// Digest computes a file digest chunk by chunk.
type Digest struct {
	outer hash.Hash
}

// NewDigest returns an empty digest.
func NewDigest() *Digest { return &Digest{outer: sha256.New()} }

// AddChunk adds the next chunk.
func (d *Digest) AddChunk(chunk []byte) {
	sum := sha256.Sum256(chunk)
	d.outer.Write(sum[:])
}

// Hex returns the digest (lowercase hex).
func (d *Digest) Hex() string { return hex.EncodeToString(d.outer.Sum(nil)) }

// FileDigest computes the digest of data cut into chunks of size chunk.
func FileDigest(data []byte, chunk int) string {
	d := NewDigest()
	for len(data) > 0 {
		n := min(chunk, len(data))
		d.AddChunk(data[:n])
		data = data[n:]
	}
	return d.Hex()
}

// Options configures Fetch.
type Options struct {
	// Dir holds the partial files (<data>/transfers).
	Dir string
	// Free returns the free bytes of the filesystem holding a path (default: statfs).
	Free func(path string) (int64, bool)
	// Backoff is the first retry delay (default 1 s, doubled each try).
	Backoff time.Duration
}

func (o *Options) defaults() {
	if o.Free == nil {
		o.Free = func(p string) (int64, bool) {
			fs, ok := hostinfo.Filesystem(p)
			return fs.FreeMB << 20, ok
		}
	}
	if o.Backoff <= 0 {
		o.Backoff = time.Second
	}
}

// ErrDigest means the fetched file does not match its digest.
var ErrDigest = errors.New("transfer: the file does not match its digest; upload it again")

// PartPath is where a task's fetched file lives.
func PartPath(dir string, task state.TaskID) string {
	return filepath.Join(dir, strings.ToLower(string(task))+".part")
}

// Fetch downloads ref into Dir/<task>.part and returns its path once every
// chunk verified (signed responses) and the whole file matches ref.SHA256.
// A partial file of an earlier attempt of the same task is resumed at its
// last complete chunk. The caller removes the file (Remove) when done.
func Fetch(ctx context.Context, c Client, ref state.TransferRef, task state.TaskID, o Options, progress Progress) (string, error) {
	o.defaults()
	if err := os.MkdirAll(o.Dir, 0o700); err != nil {
		return "", fmt.Errorf("transfer: %w", err)
	}
	path := PartPath(o.Dir, task)
	f, err := os.OpenFile(path, os.O_RDWR|os.O_CREATE, 0o600) //nolint:gosec // generated path inside the data dir
	if err != nil {
		return "", fmt.Errorf("transfer: %w", err)
	}
	defer func() { _ = f.Close() }()
	fi, err := f.Stat()
	if err != nil {
		return "", fmt.Errorf("transfer: %w", err)
	}
	// Whole chunks of an earlier attempt are kept; a torn last one is cut off.
	have := min(fi.Size()/ref.ChunkBytes, int64(ref.Chunks))
	if err := f.Truncate(have * ref.ChunkBytes); err != nil {
		return "", fmt.Errorf("transfer: %w", err)
	}
	need := ref.SizeBytes - have*ref.ChunkBytes
	if need < 0 {
		need = 0
	}
	if free, ok := o.Free(o.Dir); ok && free < need+DiskMargin {
		return "", fmt.Errorf("transfer: not enough disk space on this server: the file needs %s and %s must stay free, %s are free",
			HumanBytes(need), HumanBytes(DiskMargin), HumanBytes(free))
	}

	digest := NewDigest()
	buf := make([]byte, ref.ChunkBytes)
	for i := range have {
		n, err := f.ReadAt(buf, i*ref.ChunkBytes)
		if err != nil && !errors.Is(err, io.EOF) || int64(n) != ref.ChunkBytes {
			return "", fmt.Errorf("transfer: rereading chunk %d: %w", i, err)
		}
		digest.AddChunk(buf[:n])
	}
	if _, err := f.Seek(have*ref.ChunkBytes, io.SeekStart); err != nil {
		return "", fmt.Errorf("transfer: %w", err)
	}
	done := have * ref.ChunkBytes
	if progress != nil {
		progress(done, ref.SizeBytes)
	}
	for i := int(have); i < ref.Chunks; i++ {
		want := min(ref.ChunkBytes, ref.SizeBytes-int64(i)*ref.ChunkBytes)
		var chunk []byte
		err := retry(ctx, o.Backoff, func() error {
			var err error
			chunk, err = c.FetchTransferChunk(ctx, ref.ID, i, ref.ChunkBytes)
			if err == nil && int64(len(chunk)) != want {
				return fmt.Errorf("transfer: chunk %d has %d bytes, expected %d", i, len(chunk), want)
			}
			return err
		})
		if err != nil {
			return "", fmt.Errorf("transfer: fetching chunk %d of %d: %w", i+1, ref.Chunks, err)
		}
		if _, err := f.Write(chunk); err != nil {
			return "", fmt.Errorf("transfer: writing: %w", err)
		}
		digest.AddChunk(chunk)
		done += int64(len(chunk))
		if progress != nil {
			progress(done, ref.SizeBytes)
		}
	}
	if err := f.Sync(); err != nil {
		return "", fmt.Errorf("transfer: %w", err)
	}
	if digest.Hex() != string(ref.SHA256) {
		_ = os.Remove(path)
		return "", ErrDigest
	}
	return path, nil
}

// Remove deletes a task's partial file (if any).
func Remove(dir string, task state.TaskID) { _ = os.Remove(PartPath(dir, task)) }

// Sweep removes partial files older than 24 h (tasks that never finished).
func Sweep(dir string, now time.Time) {
	entries, err := os.ReadDir(dir)
	if err != nil {
		return
	}
	for _, e := range entries {
		if !strings.HasSuffix(e.Name(), ".part") {
			continue
		}
		if fi, err := e.Info(); err == nil && now.Sub(fi.ModTime()) > sweepAge {
			_ = os.Remove(filepath.Join(dir, e.Name()))
		}
	}
}

// retry runs fn up to Retries times with exponential backoff. Answers the
// dashboard gave on purpose (4xx other than 408/429) and signature failures
// are not retried.
func retry(ctx context.Context, backoff time.Duration, fn func() error) error {
	var err error
	for attempt := 1; ; attempt++ {
		if err = fn(); err == nil {
			return nil
		}
		if ctx.Err() != nil {
			return ctx.Err()
		}
		var api *controlplane.APIError
		var sig *controlplane.SignatureError
		if errors.As(err, &sig) || errors.As(err, &api) && !api.Retryable() || attempt >= Retries {
			return err
		}
		select {
		case <-ctx.Done():
			return ctx.Err()
		case <-time.After(backoff):
		}
		backoff *= 2
	}
}

// Result describes an uploaded export.
type Result struct {
	SizeBytes int64
	Chunks    int
	SHA256    string
}

// Uploader is an io.WriteCloser that uploads what is written in chunks of
// ChunkBytes (the last one shorter). Close uploads the rest and returns the
// result through Result. Errors are sticky.
type Uploader struct {
	ctx      context.Context //nolint:containedctx // the writer outlives no call; it is the upload's context
	c        Client
	id       state.TransferID
	buf      []byte
	n        int
	size     int64
	digest   *Digest
	backoff  time.Duration
	err      error
	progress Progress
	closed   bool
}

// NewUploader returns an uploader for target.
func NewUploader(ctx context.Context, c Client, target state.TransferTarget, progress Progress) *Uploader {
	return &Uploader{ctx: ctx, c: c, id: target.ID, buf: make([]byte, 0, target.ChunkBytes), digest: NewDigest(), backoff: time.Second, progress: progress}
}

// Write implements io.Writer.
func (u *Uploader) Write(p []byte) (int, error) {
	if u.err != nil {
		return 0, u.err
	}
	written := 0
	for len(p) > 0 {
		k := min(cap(u.buf)-len(u.buf), len(p))
		u.buf = append(u.buf, p[:k]...)
		p = p[k:]
		written += k
		if len(u.buf) == cap(u.buf) {
			if err := u.flush(); err != nil {
				return written, err
			}
		}
	}
	return written, nil
}

func (u *Uploader) flush() error {
	if len(u.buf) == 0 {
		return nil
	}
	chunk := u.buf
	err := retry(u.ctx, u.backoff, func() error { return u.c.PutTransferChunk(u.ctx, u.id, u.n, chunk) })
	if err != nil {
		u.err = fmt.Errorf("transfer: uploading chunk %d: %w", u.n+1, err)
		return u.err
	}
	u.digest.AddChunk(chunk)
	u.size += int64(len(chunk))
	u.n++
	u.buf = u.buf[:0]
	if u.progress != nil {
		u.progress(u.size, 0)
	}
	return nil
}

// Close uploads the last chunk.
func (u *Uploader) Close() error {
	if u.closed {
		return u.err
	}
	u.closed = true
	if u.err != nil {
		return u.err
	}
	return u.flush()
}

// Result returns what was uploaded (valid after a successful Close).
func (u *Uploader) Result() Result {
	return Result{SizeBytes: u.size, Chunks: u.n, SHA256: u.digest.Hex()}
}

// HumanBytes formats a size like "4.1 GB" (decimal, one digit under 10 like the UI).
func HumanBytes(n int64) string {
	const unit = 1000
	if n < unit {
		return fmt.Sprintf("%d B", n)
	}
	div, exp := int64(unit), 0
	for m := n / unit; m >= unit; m /= unit {
		div *= unit
		exp++
	}
	v := float64(n) / float64(div)
	if v < 10 {
		return fmt.Sprintf("%.1f %cB", v, "kMGTPE"[exp])
	}
	return fmt.Sprintf("%.0f %cB", v, "kMGTPE"[exp])
}
