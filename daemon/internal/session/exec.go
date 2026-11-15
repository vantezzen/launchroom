package session

import (
	"bytes"
	"context"
	"errors"
	"strings"

	"github.com/vantezzen/launchroom/daemon/internal/docker"
	"github.com/vantezzen/launchroom/daemon/internal/secret"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

type (
	envMap = map[string]secret.Secret
	execer = docker.Execer
)

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
	mask      *secret.Masker
}

func newProc(ex execer, container string, env ...string) proc {
	vals := make([]string, 0, len(env))
	for _, kv := range env {
		if _, v, ok := strings.Cut(kv, "="); ok {
			vals = append(vals, v)
		}
	}
	return proc{ex: ex, container: container, env: env, mask: secret.NewMasker(vals...)}
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
		return p.mask.Mask(state.Clip(l, 500))
	}
	return ""
}
