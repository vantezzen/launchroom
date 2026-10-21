// Package backup runs restic for database backups and restores
// (docs/14-backup-and-restore.md §2, §7).
//
// restic is executed as a child process with an argv built here (never a
// shell string). Repository credentials reach it only through its
// environment; database credentials reach the engine's dump/restore tool only
// through the exec environment of the database container. Dumps are streamed
// from the database container straight into `restic backup --stdin` (and
// `restic dump` straight into the restore tool): nothing is staged on disk.
package backup

import (
	"bufio"
	"bytes"
	"context"
	"errors"
	"fmt"
	"io"
	"net/url"
	"os"
	"os/exec"
	"path/filepath"
	"slices"
	"strings"
	"sync"
	"syscall"
	"time"

	"github.com/vantezzen/launchroom/daemon/internal/build"
	"github.com/vantezzen/launchroom/daemon/internal/secret"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// Environment variable names allowed in sealed destination credentials.
const (
	EnvAccessKeyID     = "AWS_ACCESS_KEY_ID"
	EnvSecretAccessKey = "AWS_SECRET_ACCESS_KEY" //nolint:gosec // env var name, not a credential
	EnvResticPassword  = "RESTIC_PASSWORD"       //nolint:gosec // env var name, not a credential
)

// Restic exit codes (restic ≥ 0.17).
const (
	exitRepoMissing   = 10
	exitLockFailed    = 11
	exitWrongPassword = 12
)

// Restic host name recorded on every snapshot. Snapshots are told apart by
// tags (docs/14 §2), never by host.
const resticHost = "launchroom"

// maxStderr bounds the restic stderr kept for error messages.
const maxStderr = 8 << 10

// ErrUnsupported marks operations this daemon version cannot run.
var ErrUnsupported = errors.New("not supported by this launchroomd version")

// Credentials open a repository. They are secrets: never log them.
type Credentials struct {
	AccessKeyID     secret.Secret
	SecretAccessKey secret.Secret
	ResticPassword  secret.Secret
}

// CredentialsFromEnv checks that a sealed TaskEnv holds exactly the names the
// destination kind needs (s3: AWS_ACCESS_KEY_ID, AWS_SECRET_ACCESS_KEY,
// RESTIC_PASSWORD; local: RESTIC_PASSWORD) and returns them. Errors name
// variables, never values.
func CredentialsFromEnv(kind state.DestinationKind, env map[string]secret.Secret) (Credentials, error) {
	want := []string{EnvResticPassword}
	if kind == state.DestinationS3 {
		want = []string{EnvAccessKeyID, EnvSecretAccessKey, EnvResticPassword}
	}
	got := make([]string, 0, len(env))
	for k := range env {
		got = append(got, k)
	}
	slices.Sort(got)
	slices.Sort(want)
	if !slices.Equal(got, want) {
		return Credentials{}, fmt.Errorf("backup: destination credentials must contain exactly %s, got %s", strings.Join(want, ", "), strings.Join(got, ", "))
	}
	for _, k := range want {
		if env[k].IsZero() {
			return Credentials{}, fmt.Errorf("backup: destination credential %s is empty", k)
		}
	}
	return Credentials{AccessKeyID: env[EnvAccessKeyID], SecretAccessKey: env[EnvSecretAccessKey], ResticPassword: env[EnvResticPassword]}, nil
}

// LookupBinary returns the restic binary: explicit (LR_RESTIC_BINARY), else
// /usr/local/bin/restic, else "restic" next to the running executable (dev:
// the daemon-bin volume). It returns "" when none exists.
func LookupBinary(explicit string) string {
	if explicit != "" {
		return explicit
	}
	candidates := []string{"/usr/local/bin/restic"}
	if exe, err := os.Executable(); err == nil {
		candidates = append(candidates, filepath.Join(filepath.Dir(exe), "restic"))
	}
	for _, c := range candidates {
		if fi, err := os.Stat(c); err == nil && fi.Mode().IsRegular() && fi.Mode()&0o111 != 0 {
			return c
		}
	}
	return ""
}

// repo is one restic repository opened with credentials.
type repo struct {
	binary   string
	location string
	env      []string
	mask     *build.Masker
	extraEnv []string // tests only
}

// openRepo resolves a destination into a repository location and environment.
func (e *Engine) openRepo(d state.BackupDestination, c Credentials) (*repo, error) {
	if e.o.ResticBinary == "" {
		return nil, errors.New("backup: restic is not installed on this server (set LR_RESTIC_BINARY)")
	}
	var location string
	switch d.Kind {
	case state.DestinationS3:
		u, err := url.Parse(string(d.Endpoint))
		if err != nil || d.Endpoint.Validate() != nil {
			return nil, errors.New("backup: invalid s3 endpoint")
		}
		if u.Scheme == "http" && !slices.Contains(e.o.InsecureHosts, strings.ToLower(u.Hostname())) {
			return nil, fmt.Errorf("backup: plain http endpoint %s is not allowed on this server (add the host to LR_BACKUP_INSECURE_HOSTS)", u.Host)
		}
		location = "s3:" + strings.TrimSuffix(string(d.Endpoint), "/") + "/" + string(d.Bucket)
		if p := strings.Trim(string(d.Prefix), "/"); p != "" {
			location += "/" + p
		}
	case state.DestinationLocal:
		dir := filepath.Join(e.o.DataDir, "backups")
		if p := strings.Trim(string(d.Prefix), "/"); p != "" {
			dir = filepath.Join(dir, filepath.FromSlash(p))
		}
		location = dir
	default:
		return nil, fmt.Errorf("backup: destination kind %q: %w", d.Kind, ErrUnsupported)
	}
	cache := filepath.Join(e.o.DataDir, "restic-cache")
	if err := os.MkdirAll(cache, 0o700); err != nil {
		return nil, fmt.Errorf("backup: restic cache dir: %w", err)
	}
	region := string(d.Region)
	if region == "" {
		region = "us-east-1"
	}
	env := []string{
		"HOME=" + cache, "XDG_CACHE_HOME=" + cache, "TMPDIR=" + cache,
		"RESTIC_PASSWORD=" + c.ResticPassword.Reveal(),
		"RESTIC_PROGRESS_FPS=0.1",
	}
	if d.Kind == state.DestinationS3 {
		env = append(env,
			"AWS_ACCESS_KEY_ID="+c.AccessKeyID.Reveal(),
			"AWS_SECRET_ACCESS_KEY="+c.SecretAccessKey.Reveal(),
			"AWS_DEFAULT_REGION="+region,
		)
	}
	return &repo{
		binary: e.o.ResticBinary, location: location, env: env, extraEnv: e.testEnv,
		mask: build.NewMasker(c.AccessKeyID.Reveal(), c.SecretAccessKey.Reveal(), c.ResticPassword.Reveal()),
	}, nil
}

// resticError is a failed restic invocation with a secret-free message.
type resticError struct {
	op   string
	code int
	msg  string
}

func (e *resticError) Error() string {
	switch e.code {
	case exitRepoMissing:
		return "restic " + e.op + ": the repository does not exist"
	case exitLockFailed:
		return "restic " + e.op + ": the repository is locked by another operation; try again later"
	case exitWrongPassword:
		return "restic " + e.op + ": wrong repository password"
	}
	if e.msg == "" {
		return fmt.Sprintf("restic %s failed (exit %d)", e.op, e.code)
	}
	return fmt.Sprintf("restic %s failed (exit %d): %s", e.op, e.code, e.msg)
}

// run executes restic with args. stdin/stdout may be nil.
func (r *repo) run(ctx context.Context, op string, stdin io.Reader, stdout io.Writer, args ...string) error {
	argv := append([]string{"--repo", r.location, "--cache-dir", filepath.Join(envValue(r.env, "XDG_CACHE_HOME"), "restic")}, args...)
	cmd := exec.CommandContext(ctx, r.binary, argv...) //nolint:gosec,forbidigo // reviewed: the pinned restic binary, argv built here from validated fields, never a shell (docs/09 R1)
	cmd.Env = append(slices.Clone(r.env), r.extraEnv...)
	cmd.Stdin = stdin
	if stdout == nil {
		stdout = io.Discard
	}
	cmd.Stdout = stdout
	stderr := &tail{max: maxStderr}
	cmd.Stderr = stderr
	cmd.Cancel = func() error { return cmd.Process.Signal(syscall.SIGTERM) }
	cmd.WaitDelay = 30 * time.Second
	err := cmd.Run()
	if err == nil {
		return nil
	}
	if ctx.Err() != nil {
		return ctx.Err()
	}
	var ee *exec.ExitError
	if errors.As(err, &ee) {
		return &resticError{op: op, code: ee.ExitCode(), msg: r.clean(stderr.String())}
	}
	return fmt.Errorf("restic %s: %w", op, err)
}

// clean masks secrets in restic's stderr and keeps the last few lines.
func (r *repo) clean(s string) string {
	s = r.mask.Mask(strings.TrimSpace(s))
	lines := strings.Split(s, "\n")
	if len(lines) > 5 {
		lines = lines[len(lines)-5:]
	}
	s = strings.Join(lines, " / ")
	if len(s) > 1024 {
		s = s[len(s)-1024:]
	}
	return s
}

// exitCode returns the restic exit code of err, or -1.
func exitCode(err error) int {
	var re *resticError
	if errors.As(err, &re) {
		return re.code
	}
	return -1
}

// probeTimeout bounds `restic cat config`. restic retries a missing S3
// bucket for many minutes instead of exiting 10; `init` creates the bucket.
var probeTimeout = 45 * time.Second

// exists probes the repository (missing config: exit 10; missing bucket:
// the bounded probe times out).
func (r *repo) exists(ctx context.Context) (bool, error) {
	pctx, cancel := context.WithTimeout(ctx, probeTimeout)
	err := r.run(pctx, "cat config", nil, nil, "cat", "config")
	cancel()
	switch {
	case err == nil:
		return true, nil
	case ctx.Err() != nil:
		return false, ctx.Err()
	case exitCode(err) == exitRepoMissing || errors.Is(err, context.DeadlineExceeded):
		return false, nil
	default:
		return false, err
	}
}

// ensure initialises the repository if it does not exist yet (init also
// creates a missing bucket).
func (r *repo) ensure(ctx context.Context) (created bool, err error) {
	ok, err := r.exists(ctx)
	if err != nil || ok {
		return false, err
	}
	if err := r.run(ctx, "init", nil, nil, "init"); err != nil {
		return false, err
	}
	return true, nil
}

func envValue(env []string, name string) string {
	for _, kv := range env {
		if v, ok := strings.CutPrefix(kv, name+"="); ok {
			return v
		}
	}
	return ""
}

// jsonLines calls fn for every line of a restic --json stream.
func jsonLines(data []byte, fn func([]byte)) {
	sc := bufio.NewScanner(bytes.NewReader(data))
	sc.Buffer(make([]byte, 64<<10), 4<<20)
	for sc.Scan() {
		if line := bytes.TrimSpace(sc.Bytes()); len(line) > 0 && line[0] == '{' {
			fn(line)
		}
	}
}

// tail keeps the last max bytes written to it.
type tail struct {
	mu  sync.Mutex
	max int
	b   []byte
}

func (t *tail) Write(p []byte) (int, error) {
	t.mu.Lock()
	defer t.mu.Unlock()
	t.b = append(t.b, p...)
	if len(t.b) > t.max {
		t.b = append(t.b[:0], t.b[len(t.b)-t.max:]...)
	}
	return len(p), nil
}

func (t *tail) String() string {
	t.mu.Lock()
	defer t.mu.Unlock()
	return string(t.b)
}
