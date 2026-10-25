package access

import (
	"bytes"
	"context"
	"crypto/rand"
	"errors"
	"io"
	"io/fs"
	"net"
	"os"
	"path/filepath"
	"slices"
	"strings"
	"testing"

	"github.com/pkg/sftp"
)

func TestCleanPathConfinesToTheContainer(t *testing.T) {
	ok := map[string]string{
		"":                          "/",
		".":                         "/",
		"/":                         "/",
		"../../../etc/passwd":       "/etc/passwd",
		"/srv/app/../../../../etc":  "/etc",
		"a/b/../c":                  "/a/c",
		"-rf":                       "/-rf",
		"/procfs/x":                 "/procfs/x",
		"/var/lib/app//data/./x.db": "/var/lib/app/data/x.db",
	}
	for in, want := range ok {
		got, err := CleanPath(in)
		if err != nil || got != want {
			t.Errorf("CleanPath(%q) = %q, %v; want %q", in, got, err, want)
		}
	}
	for _, in := range []string{"/proc", "/proc/self/environ", "/proc/../proc/1/root", "/sys/kernel", "/dev/sda", "/a/../dev/null", "/tmp/a\nb", "/tmp/a\x00b", "/tmp/\r", "/" + strings.Repeat("a", 5000)} {
		if got, err := CleanPath(in); err == nil {
			t.Errorf("CleanPath(%q) = %q, want an error", in, got)
		}
	}
}

// sftpPair serves the FS over an in-memory pipe and returns a client.
func sftpPair(t *testing.T, r Runner) *sftp.Client {
	t.Helper()
	a, b := net.Pipe()
	f := NewFS(context.Background(), r, nil)
	rs := sftp.NewRequestServer(a, f.Handlers(), sftp.WithStartDirectory("/"))
	go func() { _ = rs.Serve() }()
	c, err := sftp.NewClientPipe(b, b)
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { _ = c.Close(); _ = rs.Close() })
	return c
}

func TestSFTPOverContainerTools(t *testing.T) {
	box := newToolBox(t)
	c := sftpPair(t, box)

	if err := c.Mkdir("/srv"); err != nil {
		t.Fatal(err)
	}
	payload := make([]byte, 3<<20+123)
	_, _ = rand.Read(payload)
	w, err := c.Create("/srv/upload.bin")
	if err != nil {
		t.Fatal(err)
	}
	if _, err := w.ReadFrom(bytes.NewReader(payload)); err != nil {
		t.Fatalf("upload: %v", err)
	}
	if err := w.Close(); err != nil {
		t.Fatalf("upload close: %v", err)
	}
	onDisk, _ := os.ReadFile(filepath.Join(box.root, "srv", "upload.bin"))
	if !bytes.Equal(onDisk, payload) {
		t.Fatalf("uploaded %d bytes, file has %d", len(payload), len(onDisk))
	}

	rf, err := c.Open("/srv/upload.bin")
	if err != nil {
		t.Fatal(err)
	}
	var got bytes.Buffer
	if _, err := rf.WriteTo(&got); err != nil {
		t.Fatalf("download: %v", err)
	}
	_ = rf.Close()
	if !bytes.Equal(got.Bytes(), payload) {
		t.Fatalf("downloaded %d bytes, want %d", got.Len(), len(payload))
	}

	fi, err := c.Stat("/srv/upload.bin")
	if err != nil || fi.Size() != int64(len(payload)) || fi.IsDir() {
		t.Fatalf("stat: %v %+v", err, fi)
	}
	if err := c.Chmod("/srv/upload.bin", 0o600); err != nil {
		t.Fatal(err)
	}
	if fi, _ := c.Stat("/srv/upload.bin"); fi.Mode().Perm() != 0o600 {
		t.Fatalf("chmod: %v", fi.Mode())
	}
	if err := c.Symlink("/srv/upload.bin", "/srv/link"); err != nil {
		t.Fatal(err)
	}
	if target, err := c.ReadLink("/srv/link"); err != nil || target != "/srv/upload.bin" {
		t.Fatalf("readlink: %q %v", target, err)
	}
	if err := c.Rename("/srv/upload.bin", "/srv/renamed.bin"); err != nil {
		t.Fatal(err)
	}
	entries, err := c.ReadDir("/srv")
	if err != nil {
		t.Fatal(err)
	}
	var names []string
	for _, e := range entries {
		names = append(names, e.Name())
	}
	slices.Sort(names)
	if !slices.Equal(names, []string{"link", "renamed.bin"}) {
		t.Fatalf("listing %v", names)
	}
	// Rename never replaces; PosixRename does.
	if w, err := c.Create("/srv/other"); err == nil {
		_, _ = w.Write([]byte("x"))
		_ = w.Close()
	}
	if err := c.Rename("/srv/other", "/srv/renamed.bin"); err == nil {
		t.Fatal("rename replaced an existing file")
	}
	if err := c.PosixRename("/srv/other", "/srv/renamed.bin"); err != nil {
		t.Fatal(err)
	}
	if err := c.Remove("/srv/renamed.bin"); err != nil {
		t.Fatal(err)
	}
	if err := c.Remove("/srv/link"); err != nil {
		t.Fatal(err)
	}
	if err := c.RemoveDirectory("/srv"); err != nil {
		t.Fatal(err)
	}

	// Traversal stays inside the container's root; kernel paths are refused.
	if _, err := c.Open("../../../../etc/passwd"); !errors.Is(err, fs.ErrNotExist) {
		t.Fatalf("traversal: %v", err)
	}
	if _, err := c.Stat("/proc/self/environ"); !errors.Is(err, fs.ErrPermission) {
		t.Fatalf("/proc: %v", err)
	}
	if _, err := c.ReadDir("/../sys"); !errors.Is(err, fs.ErrPermission) {
		t.Fatalf("/sys: %v", err)
	}

	for _, argv := range box.calls() {
		if argv[0] == "dd" {
			if !strings.HasPrefix(argv[1], "of=/") {
				t.Errorf("dd target %q", argv[1])
			}
			continue
		}
		if !slices.Contains(argv, "--") {
			t.Errorf("argv without --: %q", argv)
		}
		for _, a := range box.operands(argv) {
			if !strings.HasPrefix(a, "/") {
				t.Errorf("relative operand %q in %q", a, argv)
			}
			if strings.HasPrefix(a, "/proc") || strings.HasPrefix(a, "/sys") || strings.HasPrefix(a, "/dev") {
				t.Errorf("kernel path reached a tool: %q", argv)
			}
		}
	}
}

func TestSFTPWithoutToolsSaysSo(t *testing.T) {
	r := runnerFunc(func([]string) (int, string) { return 127, `exec: "stat": executable file not found in $PATH` })
	f := NewFS(context.Background(), r, nil)
	_, err := f.stat("/", true)
	if !errors.Is(err, errNoTools) {
		t.Fatalf("got %v", err)
	}
}

type runnerFunc func(argv []string) (int, string)

func (f runnerFunc) Run(_ context.Context, argv []string, _ io.Reader, _ io.Writer) (int, string, error) {
	code, stderr := f(argv)
	return code, stderr, nil
}

func TestStreamReaderServesOutOfOrderReads(t *testing.T) {
	box := newToolBox(t)
	data := make([]byte, 1<<20)
	_, _ = rand.Read(data)
	if err := os.WriteFile(filepath.Join(box.root, "f"), data, 0o644); err != nil {
		t.Fatal(err)
	}
	r := &streamReader{fs: NewFS(context.Background(), box, nil), path: "/f"}
	defer func() { _ = r.Close() }()
	read := func(off, n int) {
		t.Helper()
		b := make([]byte, n)
		got, err := r.ReadAt(b, int64(off))
		if err != nil && !errors.Is(err, io.EOF) {
			t.Fatalf("ReadAt(%d): %v", off, err)
		}
		if !bytes.Equal(b[:got], data[off:off+got]) {
			t.Fatalf("ReadAt(%d) returned wrong bytes", off)
		}
	}
	read(65536, 32768) // ahead of the stream
	read(0, 32768)     // behind it, inside the window
	read(32768, 32768)
	read(900000, 1000)
	read(10, 10)
	starts := 0
	for _, a := range box.calls() {
		if a[0] == "cat" || a[0] == "tail" {
			starts++
		}
	}
	if starts != 1 {
		t.Fatalf("the stream restarted %d times for reads inside the window", starts-1)
	}
	// Past the end: io.EOF.
	if n, err := r.ReadAt(make([]byte, 10), int64(len(data))); n != 0 || !errors.Is(err, io.EOF) {
		t.Fatalf("past EOF: %d %v", n, err)
	}
}

func TestStreamWriterOrdersWrites(t *testing.T) {
	box := newToolBox(t)
	w := newStreamWriter(NewFS(context.Background(), box, nil), writeArgv("/out", false))
	parts := [][]byte{[]byte("hello "), []byte("out of "), []byte("order")}
	if _, err := w.WriteAt(parts[2], 13); err != nil {
		t.Fatal(err)
	}
	if _, err := w.WriteAt(parts[0], 0); err != nil {
		t.Fatal(err)
	}
	if _, err := w.WriteAt(parts[1], 6); err != nil {
		t.Fatal(err)
	}
	if _, err := w.WriteAt([]byte("x"), 2); err == nil {
		t.Fatal("rewriting earlier bytes must fail")
	}
	if err := w.Close(); err != nil {
		t.Fatal(err)
	}
	got, _ := os.ReadFile(filepath.Join(box.root, "out"))
	if string(got) != "hello out of order" {
		t.Fatalf("got %q", got)
	}
	gap := newStreamWriter(NewFS(context.Background(), box, nil), writeArgv("/gap", false))
	_, _ = gap.WriteAt([]byte("late"), 100)
	if err := gap.Close(); err == nil {
		t.Fatal("an upload with a gap must fail")
	}
}

func TestToolErrors(t *testing.T) {
	cases := map[string]error{
		"cat: /x: No such file or directory":               fs.ErrNotExist,
		"mkdir: can't create directory: Permission denied": fs.ErrPermission,
		"mkdir: cannot create directory '/x': File exists": fs.ErrExist,
		"dd: /etc/x: Read-only file system":                fs.ErrPermission,
	}
	for stderr, want := range cases {
		if err := toolError("t", 1, stderr); !errors.Is(err, want) {
			t.Errorf("%q → %v, want %v", stderr, err, want)
		}
	}
	if err := toolError("t", 2, "weird\nsecond line"); err.Error() != "t: weird" {
		t.Errorf("message %q", err)
	}
}

// A volume access serves its helper's /data as "/" (D67): every tool gets a
// path below the root, ".." stops at it, names like "proc" are just folders
// of the volume, and a link keeps the target the client wrote.
func TestSFTPConfinedToARoot(t *testing.T) {
	box := newToolBox(t)
	if err := os.MkdirAll(filepath.Join(box.root, "data", "uploads"), 0o750); err != nil {
		t.Fatal(err)
	}
	if err := os.MkdirAll(filepath.Join(box.root, "etc"), 0o750); err != nil {
		t.Fatal(err)
	}
	_ = os.WriteFile(filepath.Join(box.root, "etc", "secret"), []byte("outside"), 0o644)
	_ = os.WriteFile(filepath.Join(box.root, "data", "uploads", "a.txt"), []byte("inside"), 0o644)

	a, b := net.Pipe()
	f := NewFSAt(context.Background(), box, "/data", nil)
	rs := sftp.NewRequestServer(a, f.Handlers(), sftp.WithStartDirectory("/"))
	go func() { _ = rs.Serve() }()
	c, err := sftp.NewClientPipe(b, b)
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { _ = c.Close(); _ = rs.Close() })

	infos, err := c.ReadDir("/")
	if err != nil || len(infos) != 1 || infos[0].Name() != "uploads" {
		t.Fatalf("ReadDir(/) = %v, %v; want only the volume's uploads", infos, err)
	}
	if _, err := c.Open("/../../etc/secret"); !errors.Is(err, fs.ErrNotExist) {
		t.Fatalf("escaping with ..: %v", err)
	}
	r, err := c.Open("/uploads/a.txt")
	if err != nil {
		t.Fatal(err)
	}
	got, _ := io.ReadAll(r)
	_ = r.Close()
	if string(got) != "inside" {
		t.Fatalf("read %q", got)
	}
	w, err := c.Create("/new.txt")
	if err != nil {
		t.Fatal(err)
	}
	_, _ = w.Write([]byte("hello"))
	if err := w.Close(); err != nil {
		t.Fatal(err)
	}
	if b, _ := os.ReadFile(filepath.Join(box.root, "data", "new.txt")); string(b) != "hello" {
		t.Fatalf("upload landed elsewhere: %q", b)
	}
	if err := c.Mkdir("/proc"); err != nil {
		t.Fatalf("a folder named proc in the volume: %v", err)
	}
	if fi, err := c.Stat("/"); err != nil || fi.Name() != "/" || !fi.IsDir() {
		t.Fatalf("Stat(/) = %v, %v", fi, err)
	}
	if p, err := c.RealPath("/../uploads/../.."); err != nil || p != "/" {
		t.Fatalf("RealPath = %q, %v", p, err)
	}
	if err := c.Symlink("uploads/a.txt", "/link"); err != nil {
		t.Fatal(err)
	}
	for _, argv := range box.calls() {
		var paths []string
		for i, arg := range argv {
			switch {
			case strings.HasPrefix(arg, "of="):
				paths = append(paths, strings.TrimPrefix(arg, "of="))
			case arg == "--":
				paths = append(paths, argv[i+1:]...)
			}
		}
		if argv[0] == "ln" {
			paths = paths[1:] // the link's target, as written
		}
		for _, p := range paths {
			if p != "/data" && !strings.HasPrefix(p, "/data/") {
				t.Errorf("tool got a path outside the root: %q", argv)
			}
		}
	}
}
