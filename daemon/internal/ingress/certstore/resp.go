package certstore

import (
	"bufio"
	"context"
	"errors"
	"fmt"
	"io"
	"net"
	"strconv"
	"time"
)

// Limits of the minimal RESP2 client: a certificate storage value is a few
// KiB; nothing larger is ever read.
const (
	maxBulk      = 1 << 20
	maxArray     = 100_000
	maxLineBytes = 64 << 10
)

// conn is a minimal RESP2 client for the few commands launchroomd needs
// against the shared certificate storage (AUTH, PING, SCAN, GET). It speaks
// plain TCP over the mesh (WireGuard-encrypted) or loopback.
type conn struct {
	c       net.Conn
	r       *bufio.Reader
	timeout time.Duration
}

func dial(ctx context.Context, addr string, timeout time.Duration) (*conn, error) {
	d := net.Dialer{Timeout: timeout}
	c, err := d.DialContext(ctx, "tcp", addr)
	if err != nil {
		return nil, err
	}
	return &conn{c: c, r: bufio.NewReaderSize(c, 32<<10), timeout: timeout}, nil
}

func (c *conn) Close() error { return c.c.Close() }

// errReply is an error reply from the server ("-ERR …").
type errReply string

func (e errReply) Error() string { return "redis: " + string(e) }

// do sends one command and reads its reply: string, int64, nil, []any or errReply.
func (c *conn) do(ctx context.Context, args ...string) (any, error) {
	deadline := time.Now().Add(c.timeout)
	if d, ok := ctx.Deadline(); ok && d.Before(deadline) {
		deadline = d
	}
	if err := c.c.SetDeadline(deadline); err != nil {
		return nil, err
	}
	buf := make([]byte, 0, 64)
	buf = append(buf, '*')
	buf = strconv.AppendInt(buf, int64(len(args)), 10)
	buf = append(buf, '\r', '\n')
	for _, a := range args {
		buf = append(buf, '$')
		buf = strconv.AppendInt(buf, int64(len(a)), 10)
		buf = append(buf, '\r', '\n')
		buf = append(buf, a...)
		buf = append(buf, '\r', '\n')
	}
	if _, err := c.c.Write(buf); err != nil {
		return nil, err
	}
	v, err := c.read(0)
	if err != nil {
		return nil, err
	}
	if e, ok := v.(errReply); ok {
		return nil, e
	}
	return v, nil
}

func (c *conn) line() (string, error) {
	b, err := c.r.ReadSlice('\n')
	if err != nil {
		if errors.Is(err, bufio.ErrBufferFull) {
			return "", errors.New("redis: reply line too long")
		}
		return "", err
	}
	if len(b) < 2 || b[len(b)-2] != '\r' || len(b) > maxLineBytes {
		return "", errors.New("redis: malformed reply")
	}
	return string(b[:len(b)-2]), nil
}

func (c *conn) read(depth int) (any, error) {
	if depth > 4 {
		return nil, errors.New("redis: reply nested too deeply")
	}
	l, err := c.line()
	if err != nil {
		return nil, err
	}
	if l == "" {
		return nil, errors.New("redis: empty reply")
	}
	body := l[1:]
	switch l[0] {
	case '+':
		return body, nil
	case '-':
		return errReply(body), nil
	case ':':
		return strconv.ParseInt(body, 10, 64)
	case '$':
		n, err := strconv.Atoi(body)
		if err != nil || n < -1 || n > maxBulk {
			return nil, fmt.Errorf("redis: bulk length %q out of range", body)
		}
		if n == -1 {
			return nil, nil
		}
		b := make([]byte, n+2)
		if _, err := io.ReadFull(c.r, b); err != nil {
			return nil, err
		}
		return string(b[:n]), nil
	case '*':
		n, err := strconv.Atoi(body)
		if err != nil || n < -1 || n > maxArray {
			return nil, fmt.Errorf("redis: array length %q out of range", body)
		}
		if n == -1 {
			return nil, nil
		}
		out := make([]any, 0, n)
		for range n {
			v, err := c.read(depth + 1)
			if err != nil {
				return nil, err
			}
			out = append(out, v)
		}
		return out, nil
	default:
		return nil, errors.New("redis: unsupported reply type")
	}
}
