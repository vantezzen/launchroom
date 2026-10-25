package access

import (
	"bytes"
	"context"
	"errors"
	"fmt"
	"io"
	"io/fs"
	"os"
	"path"
	"strconv"
	"strings"
	"sync"
	"time"

	"github.com/pkg/sftp"
)

// SFTP over the container's own file tools (docs/03 §12.2, docs/09 R39):
// every operation is one exec of ls, stat, cat, tail, dd, tee, mkdir, rmdir,
// rm, mv, ln, readlink, chmod or truncate inside the container, as the
// container's own user, with argv only and "--" before every path. The
// container's mount namespace is the confinement: launchroomd never opens a
// host path.

// Runner runs one process in the target container.
type Runner interface {
	Run(ctx context.Context, argv []string, stdin io.Reader, stdout io.Writer) (exitCode int, stderr string, err error)
}

// SFTP limits.
const (
	maxSFTPPath = 4096
	// statBatch is how many paths one stat exec takes when listing.
	statBatch = 100
	// window is how far reads may come out of order, and how much
	// out-of-order write data is held, per open file.
	window = 16 << 20
	// maxListing bounds the entries of one directory listing.
	maxListing = 100000
)

// errNoTools is what images without the file tools get.
var errNoTools = errors.New("this image has no file tools (ls, stat, cat); SFTP needs them")

// deniedError is a permission error both fs and the SFTP status codes know.
type deniedError struct{}

func (deniedError) Error() string        { return "permission denied" }
func (deniedError) Is(target error) bool { return target == fs.ErrPermission }
func (deniedError) Unwrap() error        { return sftp.ErrSSHFxPermissionDenied }

var errDenied error = deniedError{}

// refusedRoots are never served: kernel interfaces, not the container's files.
var refusedRoots = []string{"/proc", "/sys", "/dev"}

// CleanPath turns an SFTP path into an absolute container path. It refuses
// NUL, newlines (the tools' output is line based), over-long paths and the
// kernel interfaces /proc, /sys and /dev. ".." can never leave "/": the path
// is cleaned as an absolute path.
func CleanPath(p string) (string, error) {
	if p == "" {
		p = "/"
	}
	if len(p) > maxSFTPPath || strings.ContainsAny(p, "\x00\n\r") {
		return "", fs.ErrInvalid
	}
	if !strings.HasPrefix(p, "/") {
		p = "/" + p
	}
	c := path.Clean(p)
	for _, r := range refusedRoots {
		if c == r || strings.HasPrefix(c, r+"/") {
			return "", errDenied
		}
	}
	return c, nil
}

// FS implements the pkg/sftp request server handlers.
type FS struct {
	run Runner
	ctx context.Context
	// root is the container folder served as "/" ("" = the whole container).
	root string
	// onActivity is called for every operation (idle tracking).
	onActivity func(n int)
}

// NewFS returns the handlers for one SFTP session over the whole container.
func NewFS(ctx context.Context, r Runner, onActivity func(n int)) *FS {
	return NewFSAt(ctx, r, "", onActivity)
}

// NewFSAt returns the handlers for one SFTP session confined to root, a
// clean absolute container folder served as "/" (volume access, D67).
// Every client path is cleaned as an absolute path first and then joined
// below root, so ".." can never leave it.
func NewFSAt(ctx context.Context, r Runner, root string, onActivity func(n int)) *FS {
	if onActivity == nil {
		onActivity = func(int) {}
	}
	if root == "/" {
		root = ""
	}
	return &FS{run: r, ctx: ctx, root: root, onActivity: onActivity}
}

// clientPath cleans an SFTP path as an absolute client path: the container
// path without a root, the path below the root with one.
func (f *FS) clientPath(p string) (string, error) {
	if f.root == "" {
		return CleanPath(p)
	}
	if p == "" {
		p = "/"
	}
	if len(p) > maxSFTPPath || strings.ContainsAny(p, "\x00\n\r") {
		return "", fs.ErrInvalid
	}
	if !strings.HasPrefix(p, "/") {
		p = "/" + p
	}
	return path.Clean(p), nil
}

// resolve turns an SFTP path into the container path the tools get.
func (f *FS) resolve(p string) (string, error) {
	c, err := f.clientPath(p)
	if err != nil || f.root == "" {
		return c, err
	}
	return path.Join(f.root, c), nil
}

// top is the container path served as "/".
func (f *FS) top() string {
	if f.root == "" {
		return "/"
	}
	return f.root
}

// Handlers returns the pkg/sftp handler set.
func (f *FS) Handlers() sftp.Handlers {
	return sftp.Handlers{FileGet: f, FilePut: f, FileCmd: f, FileList: f}
}

// exec runs argv and maps failures to fs errors.
func (f *FS) exec(argv []string, stdin io.Reader, stdout io.Writer) error {
	f.onActivity(0)
	code, stderr, err := f.run.Run(f.ctx, argv, stdin, stdout)
	if err != nil {
		return err
	}
	if code != 0 {
		return toolError(argv[0], code, stderr)
	}
	return nil
}

// toolError maps a tool's failure to an fs error the SFTP status codes know.
func toolError(tool string, code int, stderr string) error {
	l := strings.ToLower(stderr)
	switch {
	case code == 126 || code == 127 || strings.Contains(l, "executable file not found") || strings.Contains(l, "no such file or directory: unknown"):
		return fmt.Errorf("%s: %w", tool, errNoTools)
	case strings.Contains(l, "no such file"), strings.Contains(l, "not found"):
		return fs.ErrNotExist
	case strings.Contains(l, "permission denied"), strings.Contains(l, "operation not permitted"), strings.Contains(l, "read-only file system"):
		return errDenied
	case strings.Contains(l, "file exists"):
		return fs.ErrExist
	}
	msg := strings.TrimSpace(stderr)
	if i := strings.IndexByte(msg, '\n'); i >= 0 {
		msg = msg[:i]
	}
	if len(msg) > 200 {
		msg = msg[:200]
	}
	if msg == "" {
		msg = fmt.Sprintf("exit code %d", code)
	}
	return fmt.Errorf("%s: %s", tool, msg)
}

// ---- stat ----

// statFormat: raw mode (hex), size, mtime, uid, gid, then the name as given.
const statFormat = "%f %s %Y %u %g %n"

// fileInfo is one parsed stat line.
type fileInfo struct {
	name  string
	size  int64
	mode  fs.FileMode
	mtime time.Time
	uid   uint32
	gid   uint32
}

func (i *fileInfo) Name() string       { return i.name }
func (i *fileInfo) Size() int64        { return i.size }
func (i *fileInfo) Mode() fs.FileMode  { return i.mode }
func (i *fileInfo) ModTime() time.Time { return i.mtime }
func (i *fileInfo) IsDir() bool        { return i.mode.IsDir() }
func (i *fileInfo) Sys() any           { return nil }
func (i *fileInfo) Uid() uint32        { return i.uid } //nolint:revive // pkg/sftp's interface
func (i *fileInfo) Gid() uint32        { return i.gid } //nolint:revive // pkg/sftp's interface

// parseStatLine parses one statFormat line.
func parseStatLine(line string) (*fileInfo, error) {
	f := strings.SplitN(line, " ", 6)
	if len(f) != 6 {
		return nil, fmt.Errorf("stat: unexpected output")
	}
	raw, err1 := strconv.ParseUint(f[0], 16, 32)
	size, err2 := strconv.ParseInt(f[1], 10, 64)
	mt, err3 := strconv.ParseInt(f[2], 10, 64)
	uid, err4 := strconv.ParseUint(f[3], 10, 32)
	gid, err5 := strconv.ParseUint(f[4], 10, 32)
	if err := errors.Join(err1, err2, err3, err4, err5); err != nil {
		return nil, fmt.Errorf("stat: unexpected output")
	}
	return &fileInfo{name: path.Base(f[5]), size: size, mode: unixMode(uint32(raw)), mtime: time.Unix(mt, 0), uid: uint32(uid), gid: uint32(gid)}, nil
}

// unixMode converts st_mode to an fs.FileMode.
func unixMode(m uint32) fs.FileMode {
	mode := fs.FileMode(m & 0o777)
	switch m & 0o170000 {
	case 0o040000:
		mode |= fs.ModeDir
	case 0o120000:
		mode |= fs.ModeSymlink
	case 0o010000:
		mode |= fs.ModeNamedPipe
	case 0o140000:
		mode |= fs.ModeSocket
	case 0o020000:
		mode |= fs.ModeDevice | fs.ModeCharDevice
	case 0o060000:
		mode |= fs.ModeDevice
	}
	if m&0o4000 != 0 {
		mode |= fs.ModeSetuid
	}
	if m&0o2000 != 0 {
		mode |= fs.ModeSetgid
	}
	if m&0o1000 != 0 {
		mode |= fs.ModeSticky
	}
	return mode
}

func (f *FS) stat(p string, follow bool) (*fileInfo, error) {
	argv := []string{"stat"}
	if follow {
		argv = append(argv, "-L")
	}
	argv = append(argv, "-c", statFormat, "--", p)
	var out bytes.Buffer
	if err := f.exec(argv, nil, &out); err != nil {
		return nil, err
	}
	fi, err := parseStatLine(strings.TrimRight(out.String(), "\n"))
	if err != nil {
		return nil, err
	}
	if p == f.top() {
		fi.name = "/"
	}
	return fi, nil
}

// list returns the entries of dir (lstat of each).
func (f *FS) list(dir string) ([]os.FileInfo, error) {
	var out bytes.Buffer
	if err := f.exec([]string{"ls", "-1A", "--", dir}, nil, &out); err != nil {
		return nil, err
	}
	var names []string
	for _, n := range strings.Split(out.String(), "\n") {
		if n == "" || strings.Contains(n, "/") {
			continue
		}
		names = append(names, n)
		if len(names) >= maxListing {
			break
		}
	}
	infos := make([]os.FileInfo, 0, len(names))
	for len(names) > 0 {
		batch := names[:min(statBatch, len(names))]
		names = names[len(batch):]
		argv := []string{"stat", "-c", statFormat, "--"}
		for _, n := range batch {
			argv = append(argv, path.Join(dir, n))
		}
		var sb bytes.Buffer
		// A name that vanished in between makes stat exit 1 but print the others.
		code, stderr, err := f.run.Run(f.ctx, argv, nil, &sb)
		if err != nil {
			return nil, err
		}
		if code != 0 && sb.Len() == 0 {
			return nil, toolError("stat", code, stderr)
		}
		for _, line := range strings.Split(sb.String(), "\n") {
			if line == "" {
				continue
			}
			if fi, err := parseStatLine(line); err == nil {
				infos = append(infos, fi)
			}
		}
	}
	return infos, nil
}

// listerAt serves a fixed listing.
type listerAt []os.FileInfo

func (l listerAt) ListAt(out []os.FileInfo, off int64) (int, error) {
	if off >= int64(len(l)) {
		return 0, io.EOF
	}
	n := copy(out, l[off:])
	if n < len(out) {
		return n, io.EOF
	}
	return n, nil
}

// Filelist implements sftp.FileLister (List, Stat, Readlink).
func (f *FS) Filelist(r *sftp.Request) (sftp.ListerAt, error) {
	p, err := f.resolve(r.Filepath)
	if err != nil {
		return nil, err
	}
	switch r.Method {
	case "List":
		infos, err := f.list(p)
		if err != nil {
			return nil, err
		}
		return listerAt(infos), nil
	case "Stat":
		fi, err := f.stat(p, true)
		if err != nil {
			return nil, err
		}
		return listerAt{fi}, nil
	case "Readlink":
		target, err := f.readlink(p)
		if err != nil {
			return nil, err
		}
		return listerAt{&fileInfo{name: target}}, nil
	}
	return nil, sftp.ErrSSHFxOpUnsupported
}

// Lstat implements sftp.LstatFileLister.
func (f *FS) Lstat(r *sftp.Request) (sftp.ListerAt, error) {
	p, err := f.resolve(r.Filepath)
	if err != nil {
		return nil, err
	}
	fi, err := f.stat(p, false)
	if err != nil {
		return nil, err
	}
	return listerAt{fi}, nil
}

// Readlink implements sftp.ReadlinkFileLister.
func (f *FS) Readlink(p string) (string, error) {
	p, err := f.resolve(p)
	if err != nil {
		return "", err
	}
	return f.readlink(p)
}

// readlink reads a link at a container path.
func (f *FS) readlink(p string) (string, error) {
	var out bytes.Buffer
	if err := f.exec([]string{"readlink", "--", p}, nil, &out); err != nil {
		return "", err
	}
	return strings.TrimRight(out.String(), "\n"), nil
}

// RealPath implements sftp.RealPathFileLister: lexical, inside "/" (the
// client's "/", the root's when confined).
func (f *FS) RealPath(p string) (string, error) {
	return f.clientPath(p)
}

// ---- commands ----

// Filecmd implements sftp.FileCmder.
func (f *FS) Filecmd(r *sftp.Request) error {
	if r.Method == "Symlink" {
		return f.symlink(r)
	}
	p, err := f.resolve(r.Filepath)
	if err != nil {
		return err
	}
	switch r.Method {
	case "Mkdir":
		return f.exec([]string{"mkdir", "--", p}, nil, nil)
	case "Rmdir":
		return f.exec([]string{"rmdir", "--", p}, nil, nil)
	case "Remove":
		return f.exec([]string{"rm", "--", p}, nil, nil)
	case "Rename":
		to, err := f.resolve(r.Target)
		if err != nil {
			return err
		}
		// SFTP rename never replaces an existing file.
		if _, err := f.stat(to, false); err == nil {
			return fs.ErrExist
		}
		return f.exec([]string{"mv", "--", p, to}, nil, nil)
	case "Setstat":
		return f.setstat(p, r)
	}
	return sftp.ErrSSHFxOpUnsupported
}

// symlink creates a link. pkg/sftp: Filepath is the link's target, Target
// the link itself. Confined to a root, the target is kept as the client
// wrote it (not moved below the root): a link in a volume is followed by
// the service, which mounts the volume elsewhere, so relative targets stay
// relative.
func (f *FS) symlink(r *sftp.Request) error {
	link, err := f.resolve(r.Target)
	if err != nil {
		return err
	}
	target := r.Filepath
	if f.root == "" {
		if target, err = CleanPath(target); err != nil {
			return err
		}
	} else if target == "" || len(target) > maxSFTPPath || strings.ContainsAny(target, "\x00\n\r") {
		return fs.ErrInvalid
	}
	return f.exec([]string{"ln", "-s", "--", target, link}, nil, nil)
}

// PosixRename implements sftp.PosixRenameFileCmder (replaces the target).
func (f *FS) PosixRename(r *sftp.Request) error {
	p, err := f.resolve(r.Filepath)
	if err != nil {
		return err
	}
	to, err := f.resolve(r.Target)
	if err != nil {
		return err
	}
	return f.exec([]string{"mv", "-f", "--", p, to}, nil, nil)
}

// setstat applies permissions and size; owners and times are accepted and
// ignored (scp/sftp -p sends them).
func (f *FS) setstat(p string, r *sftp.Request) error {
	flags, attrs := r.AttrFlags(), r.Attributes()
	if flags.Size {
		if err := f.exec([]string{"truncate", "-s", strconv.FormatUint(attrs.Size, 10), "--", p}, nil, nil); err != nil {
			return err
		}
	}
	if flags.Permissions {
		mode := strconv.FormatUint(uint64(attrs.Mode&0o7777), 8)
		if err := f.exec([]string{"chmod", mode, "--", p}, nil, nil); err != nil {
			return err
		}
	}
	return nil
}

// ---- reading ----

// Fileread implements sftp.FileReader.
func (f *FS) Fileread(r *sftp.Request) (io.ReaderAt, error) {
	p, err := f.resolve(r.Filepath)
	if err != nil {
		return nil, err
	}
	fi, err := f.stat(p, true)
	if err != nil {
		return nil, err
	}
	if fi.IsDir() {
		return nil, fmt.Errorf("%s is a directory", p)
	}
	return &streamReader{fs: f, path: p}, nil
}

// streamReader serves ReadAt from a sequential cat/tail stream: reads may
// arrive out of order within the window; a read before it restarts the
// stream at that offset.
type streamReader struct {
	fs   *FS
	path string

	mu     sync.Mutex
	pr     *io.PipeReader
	cancel context.CancelFunc
	done   chan struct{}
	runErr error
	// buf holds bytes [base, base+len(buf)) of the file.
	base int64
	buf  []byte
	eof  bool
}

// readArgv is the reader process for a stream starting at off.
func readArgv(p string, off int64) []string {
	if off == 0 {
		return []string{"cat", "--", p}
	}
	return []string{"tail", "-c", "+" + strconv.FormatInt(off+1, 10), "--", p}
}

func (s *streamReader) start(off int64) {
	s.stop()
	ctx, cancel := context.WithCancel(s.fs.ctx)
	pr, pw := io.Pipe()
	s.pr, s.cancel, s.done = pr, cancel, make(chan struct{})
	s.base, s.buf, s.eof, s.runErr = off, nil, false, nil
	done := s.done
	go func() {
		defer close(done)
		code, stderr, err := s.fs.run.Run(ctx, readArgv(s.path, off), nil, pw)
		if err == nil && code != 0 {
			err = toolError("cat", code, stderr)
		}
		_ = pw.CloseWithError(err)
	}()
}

func (s *streamReader) stop() {
	if s.cancel != nil {
		s.cancel()
		_ = s.pr.Close()
		<-s.done
		s.cancel = nil
	}
}

// ReadAt implements io.ReaderAt.
func (s *streamReader) ReadAt(p []byte, off int64) (int, error) {
	s.mu.Lock()
	defer s.mu.Unlock()
	switch {
	case s.cancel == nil && off <= window:
		s.start(0)
	case s.cancel == nil, off < s.base, off > s.base+int64(len(s.buf))+window:
		s.start(off)
	}
	end := off + int64(len(p))
	for !s.eof && s.base+int64(len(s.buf)) < end {
		chunk := make([]byte, 64<<10)
		n, err := s.pr.Read(chunk)
		s.buf = append(s.buf, chunk[:n]...)
		s.fs.onActivity(n)
		if errors.Is(err, io.EOF) {
			s.eof = true
		} else if err != nil {
			return 0, err
		}
		// Keep at most the window behind the furthest read.
		if over := len(s.buf) - 2*window; over > 0 {
			s.buf = append([]byte(nil), s.buf[over:]...)
			s.base += int64(over)
		}
	}
	if off < s.base {
		return 0, errors.New("read too far out of order")
	}
	from := off - s.base
	if from >= int64(len(s.buf)) {
		return 0, io.EOF
	}
	n := copy(p, s.buf[from:])
	if n < len(p) {
		return n, io.EOF
	}
	return n, nil
}

// Close stops the stream.
func (s *streamReader) Close() error {
	s.mu.Lock()
	defer s.mu.Unlock()
	s.stop()
	return nil
}

// ---- writing ----

// Filewrite implements sftp.FileWriter.
func (f *FS) Filewrite(r *sftp.Request) (io.WriterAt, error) {
	p, err := f.resolve(r.Filepath)
	if err != nil {
		return nil, err
	}
	fl := r.Pflags()
	if fl.Excl {
		if _, err := f.stat(p, false); err == nil {
			return nil, fs.ErrExist
		}
	}
	return newStreamWriter(f, writeArgv(p, fl.Append)), nil
}

// writeArgv is the writer process: dd truncates and writes (its operand
// "of=<path>" is parsed by dd itself, so a leading "-" is still a file
// name); appends go through tee -a.
func writeArgv(p string, appendTo bool) []string {
	if appendTo {
		return []string{"tee", "-a", "--", p}
	}
	return []string{"dd", "of=" + p, "bs=65536"}
}

// streamWriter feeds WriteAt calls in offset order into the writer process;
// writes ahead of the next offset wait (up to the window).
type streamWriter struct {
	fs *FS

	mu      sync.Mutex
	pw      *io.PipeWriter
	done    chan struct{}
	runErr  error
	next    int64
	pending map[int64][]byte
	held    int
	closed  bool
}

func newStreamWriter(f *FS, argv []string) *streamWriter {
	pr, pw := io.Pipe()
	w := &streamWriter{fs: f, pw: pw, done: make(chan struct{}), pending: map[int64][]byte{}}
	go func() {
		defer close(w.done)
		code, stderr, err := f.run.Run(f.ctx, argv, pr, nil)
		if err == nil && code != 0 {
			err = toolError(argv[0], code, stderr)
		}
		w.runErr = err
		_ = pr.CloseWithError(errors.Join(err, io.ErrClosedPipe))
	}()
	return w
}

// WriteAt implements io.WriterAt.
func (w *streamWriter) WriteAt(p []byte, off int64) (int, error) {
	w.mu.Lock()
	defer w.mu.Unlock()
	if w.closed {
		return 0, os.ErrClosed
	}
	switch {
	case off < w.next:
		return 0, errors.New("SFTP here writes files from start to end; rewriting earlier bytes is not supported")
	case off > w.next:
		if w.held+len(p) > window {
			return 0, errors.New("writes arrived too far out of order")
		}
		w.pending[off] = append([]byte(nil), p...)
		w.held += len(p)
		return len(p), nil
	}
	if err := w.write(p); err != nil {
		return 0, err
	}
	for {
		b, ok := w.pending[w.next]
		if !ok {
			break
		}
		delete(w.pending, w.next)
		w.held -= len(b)
		if err := w.write(b); err != nil {
			return 0, err
		}
	}
	return len(p), nil
}

func (w *streamWriter) write(p []byte) error {
	if _, err := w.pw.Write(p); err != nil {
		<-w.done
		if w.runErr != nil {
			return w.runErr
		}
		return err
	}
	w.next += int64(len(p))
	w.fs.onActivity(len(p))
	return nil
}

// Close ends the input and waits for the writer process.
func (w *streamWriter) Close() error {
	w.mu.Lock()
	defer w.mu.Unlock()
	if w.closed {
		return nil
	}
	w.closed = true
	_ = w.pw.Close()
	<-w.done
	if len(w.pending) > 0 {
		return errors.New("upload ended with a gap")
	}
	return w.runErr
}
