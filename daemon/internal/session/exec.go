package session

import (
	"bytes"
	"context"
	"errors"
	"fmt"
	"regexp"
	"strings"

	"github.com/vantezzen/launchroom/daemon/internal/build"
	"github.com/vantezzen/launchroom/daemon/internal/docker"
	"github.com/vantezzen/launchroom/daemon/internal/secret"
)

type (
	envMap = map[string]secret.Secret
	execer = docker.Execer
)

// reDBIdent is the grammar for database and user names taken from a
// workload's env before they go into argv (the same as backups).
var reDBIdent = regexp.MustCompile(`^[A-Za-z0-9_]{1,63}$`)

func ident(env envMap, def string, names ...string) (string, error) {
	v := def
	for _, n := range names {
		if s := env[n]; !s.IsZero() {
			v = s.Reveal()
			break
		}
	}
	if !reDBIdent.MatchString(v) {
		return "", fmt.Errorf("%s in the database env is not a valid identifier", names[0])
	}
	return v, nil
}

func password(env envMap, names ...string) (string, error) {
	for _, n := range names {
		if s := env[n]; !s.IsZero() {
			return s.Reveal(), nil
		}
	}
	return "", fmt.Errorf("the database env has no %s", names[0])
}

// errStop ends reading a client's output at the limit.
var errStop = errors.New("session: output limit reached")

// capWriter keeps at most max bytes / maxLines newlines, then refuses more.
type capWriter struct {
	buf      bytes.Buffer
	max      int
	maxLines int
	lines    int
	hit      bool
}

func (w *capWriter) Write(p []byte) (int, error) {
	if w.hit {
		return 0, errStop
	}
	room := w.max - w.buf.Len()
	if room <= 0 {
		w.hit = true
		return 0, errStop
	}
	q := p[:min(len(p), room)]
	w.buf.Write(q)
	w.lines += bytes.Count(q, []byte{'\n'})
	if len(q) < len(p) || (w.maxLines > 0 && w.lines > w.maxLines) {
		w.hit = true
		return len(q), errStop
	}
	return len(p), nil
}

// proc runs one database client process in the database container with
// credentials only in its env.
type proc struct {
	ex        execer
	container string
	env       []string
	mask      *build.Masker
}

func newProc(ex execer, container string, env ...string) proc {
	vals := make([]string, 0, len(env))
	for _, kv := range env {
		if _, v, ok := strings.Cut(kv, "="); ok {
			vals = append(vals, v)
		}
	}
	return proc{ex: ex, container: container, env: env, mask: build.NewMasker(vals...)}
}

type procResult struct {
	out       []byte
	truncated bool
	code      int
	stderr    string
}

// run executes argv; extra env is added (e.g. PGOPTIONS).
func (p proc) run(ctx context.Context, argv []string, maxLines int, extra ...string) (procResult, error) {
	w := &capWriter{max: 2 * MaxResult, maxLines: maxLines}
	env := append(append([]string(nil), p.env...), extra...)
	res, err := p.ex.ExecStream(ctx, p.container, docker.ExecOptions{Argv: argv, Env: env, Stdout: w})
	out := procResult{out: w.buf.Bytes(), truncated: w.hit, code: res.ExitCode, stderr: p.message(res.Stderr)}
	if err != nil && w.hit {
		return out, nil
	}
	if err != nil {
		if ctx.Err() != nil {
			return out, errors.New("the statement took longer than 15 seconds")
		}
		return out, err
	}
	return out, nil
}

// message is the first meaningful stderr line, masked.
func (p proc) message(stderr string) string {
	for _, l := range strings.Split(stderr, "\n") {
		l = strings.TrimSpace(l)
		// clickhouse-client prints a header before the exception line.
		if l == "" || strings.HasPrefix(l, "mysql: [Warning]") || strings.HasPrefix(l, "mariadb: [Warning]") ||
			strings.HasPrefix(l, "Received exception from server") {
			continue
		}
		if len(l) > 500 {
			l = l[:500]
		}
		return p.mask.Mask(l)
	}
	return ""
}
