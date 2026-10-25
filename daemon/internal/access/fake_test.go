package access

import (
	"bytes"
	"context"
	"fmt"
	"io"
	"io/fs"
	"os"
	"path/filepath"
	"strconv"
	"strings"
	"sync"
	"syscall"
	"testing"
)

// toolBox emulates the container's file tools on a temp dir, the way
// busybox/coreutils behave for the argv sftpfs builds. Every argv is
// recorded, so tests can check what would run in the container.
type toolBox struct {
	t    *testing.T
	root string

	mu    sync.Mutex
	argvs [][]string
}

func newToolBox(t *testing.T) *toolBox {
	return &toolBox{t: t, root: t.TempDir()}
}

func (b *toolBox) calls() [][]string {
	b.mu.Lock()
	defer b.mu.Unlock()
	return append([][]string(nil), b.argvs...)
}

// host maps a container path into the temp dir. A path that would leave
// the root fails the test: sftpfs must never produce one.
func (b *toolBox) host(p string) string {
	if !strings.HasPrefix(p, "/") || strings.Contains(p, "/../") || strings.HasSuffix(p, "/..") {
		b.t.Errorf("tool got a path that isn't clean and absolute: %q", p)
	}
	return filepath.Join(b.root, filepath.FromSlash(p))
}

// operands returns the args after "--" (failing when there is none).
func (b *toolBox) operands(argv []string) []string {
	for i, a := range argv {
		if a == "--" {
			return argv[i+1:]
		}
	}
	b.t.Errorf("argv without --: %q", argv)
	return nil
}

func fail(err error) (int, string, error) {
	return 1, "tool: " + strings.ToLower(errText(err)) + "\n", nil
}

func errText(err error) string {
	switch {
	case os.IsNotExist(err):
		return "No such file or directory"
	case os.IsPermission(err):
		return "Permission denied"
	case os.IsExist(err):
		return "File exists"
	}
	return err.Error()
}

func (b *toolBox) statLine(name string, follow bool) (string, error) {
	h := b.host(name)
	var fi os.FileInfo
	var err error
	if follow {
		fi, err = os.Stat(h)
	} else {
		fi, err = os.Lstat(h)
	}
	if err != nil {
		return "", err
	}
	st := fi.Sys().(*syscall.Stat_t) //nolint:forcetypeassert // linux test
	return fmt.Sprintf("%x %d %d %d %d %s", st.Mode, fi.Size(), fi.ModTime().Unix(), st.Uid, st.Gid, name), nil
}

func (b *toolBox) Run(_ context.Context, argv []string, stdin io.Reader, stdout io.Writer) (int, string, error) {
	b.mu.Lock()
	b.argvs = append(b.argvs, append([]string(nil), argv...))
	b.mu.Unlock()
	if stdout == nil {
		stdout = io.Discard
	}
	switch argv[0] {
	case "stat":
		follow := argv[1] == "-L"
		code := 0
		for _, p := range b.operands(argv) {
			l, err := b.statLine(p, follow)
			if err != nil {
				code = 1
				continue
			}
			_, _ = fmt.Fprintln(stdout, l)
		}
		if code != 0 {
			return 1, "stat: can't stat: No such file or directory\n", nil
		}
		return 0, "", nil
	case "ls":
		es, err := os.ReadDir(b.host(b.operands(argv)[0]))
		if err != nil {
			return fail(err)
		}
		for _, e := range es {
			_, _ = fmt.Fprintln(stdout, e.Name())
		}
		return 0, "", nil
	case "cat":
		f, err := os.Open(b.host(b.operands(argv)[0]))
		if err != nil {
			return fail(err)
		}
		defer func() { _ = f.Close() }()
		_, _ = io.Copy(stdout, f)
		return 0, "", nil
	case "tail":
		off, _ := strconv.ParseInt(strings.TrimPrefix(argv[2], "+"), 10, 64)
		f, err := os.Open(b.host(b.operands(argv)[0]))
		if err != nil {
			return fail(err)
		}
		defer func() { _ = f.Close() }()
		_, _ = f.Seek(off-1, io.SeekStart)
		_, _ = io.Copy(stdout, f)
		return 0, "", nil
	case "dd":
		p := strings.TrimPrefix(argv[1], "of=")
		f, err := os.Create(b.host(p))
		if err != nil {
			return fail(err)
		}
		defer func() { _ = f.Close() }()
		_, _ = io.Copy(f, stdin)
		return 0, "", nil
	case "tee":
		f, err := os.OpenFile(b.host(b.operands(argv)[0]), os.O_CREATE|os.O_APPEND|os.O_WRONLY, 0o600)
		if err != nil {
			return fail(err)
		}
		defer func() { _ = f.Close() }()
		_, _ = io.Copy(io.MultiWriter(f, stdout), stdin)
		return 0, "", nil
	case "mkdir":
		if err := os.Mkdir(b.host(b.operands(argv)[0]), 0o750); err != nil {
			return fail(err)
		}
	case "rmdir":
		if err := syscall.Rmdir(b.host(b.operands(argv)[0])); err != nil {
			return fail(&fs.PathError{Op: "rmdir", Err: err})
		}
	case "rm":
		h := b.host(b.operands(argv)[0])
		if fi, err := os.Lstat(h); err == nil && fi.IsDir() {
			return 1, "rm: is a directory\n", nil
		}
		if err := os.Remove(h); err != nil {
			return fail(err)
		}
	case "mv":
		ops := b.operands(argv)
		if err := os.Rename(b.host(ops[0]), b.host(ops[1])); err != nil {
			return fail(err)
		}
	case "ln":
		ops := b.operands(argv)
		if err := os.Symlink(ops[0], b.host(ops[1])); err != nil {
			return fail(err)
		}
	case "readlink":
		t, err := os.Readlink(b.host(b.operands(argv)[0]))
		if err != nil {
			return fail(err)
		}
		_, _ = fmt.Fprintln(stdout, t)
	case "chmod":
		m, _ := strconv.ParseUint(argv[1], 8, 32)
		if err := os.Chmod(b.host(b.operands(argv)[0]), fs.FileMode(m)); err != nil {
			return fail(err)
		}
	case "truncate":
		n, _ := strconv.ParseInt(argv[2], 10, 64)
		if err := os.Truncate(b.host(b.operands(argv)[0]), n); err != nil {
			return fail(err)
		}
	default:
		return 127, "exec: \"" + argv[0] + "\": executable file not found in $PATH", nil
	}
	return 0, "", nil
}

// RunEnv implements EnvRunner for role tests: records argv, env and stdin.
type envRecorder struct {
	mu    sync.Mutex
	calls []envCall
	reply func(argv []string) (int, string, string)
}

type envCall struct {
	argv  []string
	env   []string
	stdin string
}

func (r *envRecorder) RunEnv(_ context.Context, argv, env []string, stdin []byte, stdout *bytes.Buffer) (int, string, error) {
	r.mu.Lock()
	r.calls = append(r.calls, envCall{argv: argv, env: env, stdin: string(stdin)})
	r.mu.Unlock()
	if r.reply != nil {
		code, out, errOut := r.reply(argv)
		stdout.WriteString(out)
		return code, errOut, nil
	}
	if strings.Contains(argv[0], "cli") {
		stdout.WriteString("OK\n")
	}
	return 0, "", nil
}
