package access

import (
	"bufio"
	"bytes"
	"context"
	"crypto/subtle"
	"crypto/tls"
	"encoding/binary"
	"errors"
	"fmt"
	"io"
	"net"
	"net/netip"
	"strconv"
	"strings"
	"time"

	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// Database proxy (docs/03 §12.2): TLS is terminated here with the access'
// certificate and required; the first message must name the temporary
// role; then bytes are spliced to the database container.

// Postgres protocol codes.
const (
	pgSSLRequest    = 80877103
	pgGSSENCRequest = 80877104
	pgCancelRequest = 80877102
	pgMaxStartup    = 10000
)

// DBOptions configures the proxy of one access.
type DBOptions struct {
	Engine state.DatabaseEngine
	Role   string
	// Database is the only database a Postgres role may use.
	Database string
	TLS      tls.Certificate
	// Upstream returns the database container's address (resolved per connection).
	Upstream func(ctx context.Context) (netip.AddrPort, error)
	Dial     func(ctx context.Context, addr netip.AddrPort) (net.Conn, error)
}

type dbProxy struct {
	o  DBOptions
	ep *endpoint
}

func (p *dbProxy) handle(ctx context.Context, c net.Conn, a netip.Addr) {
	_ = c.SetDeadline(time.Now().Add(handshakeTimeout))
	var (
		client net.Conn
		first  []byte
		err    error
	)
	switch p.o.Engine {
	case state.EnginePostgres:
		client, first, err = p.postgresStartup(ctx, c)
	default:
		client, first, err = p.redisStartup(ctx, c)
	}
	if err != nil || client == nil {
		return
	}
	defer func() { _ = client.Close() }()
	_ = c.SetDeadline(time.Time{})
	up, err := p.dial(ctx)
	if err != nil {
		p.refuse(client, "the database isn't reachable right now")
		return
	}
	defer func() { _ = up.Close() }()
	if _, err := up.Write(first); err != nil {
		return
	}
	if !isPGCancel(p.o.Engine, first) {
		p.ep.connected(a, state.AccessMethodPassword, state.AccessChannelDatabase)
	}
	p.ep.activity(len(first), 0)
	stop := context.AfterFunc(ctx, func() { _ = client.Close(); _ = up.Close() })
	defer stop()
	splice(client, up, p.ep)
}

func (p *dbProxy) dial(ctx context.Context) (net.Conn, error) {
	addr, err := p.o.Upstream(ctx)
	if err != nil {
		return nil, err
	}
	dctx, cancel := context.WithTimeout(ctx, 10*time.Second)
	defer cancel()
	return p.o.Dial(dctx, addr)
}

// refuse answers in the engine's own error format, then closes.
func (p *dbProxy) refuse(c net.Conn, msg string) {
	_ = c.SetWriteDeadline(time.Now().Add(5 * time.Second))
	if p.o.Engine == state.EnginePostgres {
		_, _ = c.Write(pgError("28000", "launchroom temporary access: "+msg))
	} else {
		_, _ = c.Write([]byte("-ERR launchroom temporary access: " + msg + "\r\n"))
	}
}

// splice copies both ways until either side closes.
func splice(a, b net.Conn, ep *endpoint) {
	done := make(chan struct{}, 2)
	cp := func(dst, src net.Conn, in bool) {
		buf := make([]byte, 32<<10)
		for {
			n, err := src.Read(buf)
			if n > 0 {
				if in {
					ep.activity(n, 0)
				} else {
					ep.activity(0, n)
				}
				if _, werr := dst.Write(buf[:n]); werr != nil {
					break
				}
			}
			if err != nil {
				break
			}
		}
		done <- struct{}{}
	}
	go cp(b, a, true)
	go cp(a, b, false)
	<-done
	_ = a.Close()
	_ = b.Close()
	<-done
}

// ---- Postgres ----

// pgError is an ErrorResponse (severity FATAL).
func pgError(code, msg string) []byte {
	var body bytes.Buffer
	for _, f := range []struct {
		t byte
		v string
	}{{'S', "FATAL"}, {'V', "FATAL"}, {'C', code}, {'M', msg}} {
		body.WriteByte(f.t)
		body.WriteString(f.v)
		body.WriteByte(0)
	}
	body.WriteByte(0)
	out := []byte{'E', 0, 0, 0, 0}
	binary.BigEndian.PutUint32(out[1:], uint32(body.Len()+4)) //nolint:gosec // small
	return append(out, body.Bytes()...)
}

func isPGCancel(engine state.DatabaseEngine, pkt []byte) bool {
	return engine == state.EnginePostgres && len(pkt) == 16 && binary.BigEndian.Uint32(pkt[4:8]) == pgCancelRequest
}

// readPGPacket reads one length-prefixed startup-phase packet.
func readPGPacket(r io.Reader) ([]byte, error) {
	var hdr [4]byte
	if _, err := io.ReadFull(r, hdr[:]); err != nil {
		return nil, err
	}
	n := binary.BigEndian.Uint32(hdr[:])
	if n < 8 || n > pgMaxStartup {
		return nil, errors.New("postgres: bad startup packet length")
	}
	b := make([]byte, n)
	copy(b, hdr[:])
	if _, err := io.ReadFull(r, b[4:]); err != nil {
		return nil, err
	}
	return b, nil
}

// postgresStartup negotiates TLS and returns the TLS connection and the
// (checked, possibly rewritten) StartupMessage to send upstream.
func (p *dbProxy) postgresStartup(ctx context.Context, c net.Conn) (net.Conn, []byte, error) {
	br := bufio.NewReader(c)
	peek, err := br.Peek(1)
	if err != nil {
		return nil, nil, err
	}
	conn := &bufConn{Conn: c, r: br}
	var tconn *tls.Conn
	if peek[0] == 0x16 {
		// Direct TLS (Postgres 17 sslnegotiation=direct): ALPN "postgresql" required.
		tconn = tls.Server(conn, serverTLS(p.o.TLS, []string{"postgresql"}))
		if err := tconn.HandshakeContext(ctx); err != nil || tconn.ConnectionState().NegotiatedProtocol != "postgresql" {
			_ = tconn.Close()
			return nil, nil, errors.New("postgres: direct TLS without ALPN postgresql")
		}
	} else {
		for tconn == nil {
			pkt, err := readPGPacket(conn)
			if err != nil {
				return nil, nil, err
			}
			switch binary.BigEndian.Uint32(pkt[4:8]) {
			case pgSSLRequest:
				if _, err := c.Write([]byte{'S'}); err != nil {
					return nil, nil, err
				}
				if br.Buffered() > 0 {
					// Bytes after SSLRequest before our answer: refuse (CVE-2021-23214 class).
					return nil, nil, errors.New("postgres: data after SSLRequest")
				}
				tconn = tls.Server(c, serverTLS(p.o.TLS, []string{"postgresql"}))
				if err := tconn.HandshakeContext(ctx); err != nil {
					return nil, nil, err
				}
			case pgGSSENCRequest:
				if _, err := c.Write([]byte{'N'}); err != nil {
					return nil, nil, err
				}
			case pgCancelRequest:
				// Carries only a backend pid and secret key, no credentials:
				// pass it through (libpq sends it without TLS).
				if len(pkt) != 16 {
					return nil, nil, errors.New("postgres: bad cancel request")
				}
				return c, pkt, nil
			default:
				_, _ = c.Write(pgError("28000", "launchroom temporary access needs SSL: connect with sslmode=require"))
				return nil, nil, errors.New("postgres: startup without TLS")
			}
		}
	}
	pkt, err := readPGPacket(tconn)
	if err != nil {
		_ = tconn.Close()
		return nil, nil, err
	}
	out, err := checkPGStartup(pkt, p.o.Role, p.o.Database)
	if err != nil {
		_, _ = tconn.Write(pgError("28000", "launchroom temporary access: "+err.Error()))
		_ = tconn.Close()
		p.ep.failed(remoteAddr(c), false)
		return nil, nil, err
	}
	return tconn, out, nil
}

// checkPGStartup checks a StartupMessage: protocol 3.x, user = role, no
// replication, database absent (then set) or the service's database.
func checkPGStartup(pkt []byte, role, database string) ([]byte, error) {
	if len(pkt) < 9 {
		return nil, errors.New("bad startup message")
	}
	if v := binary.BigEndian.Uint32(pkt[4:8]); v>>16 != 3 {
		return nil, errors.New("unsupported protocol version")
	}
	if pkt[len(pkt)-1] != 0 {
		return nil, errors.New("bad startup message")
	}
	params := map[string]string{}
	var order []string
	rest := pkt[8 : len(pkt)-1]
	for len(rest) > 0 {
		k, after, ok := bytes.Cut(rest, []byte{0})
		if !ok || len(k) == 0 {
			return nil, errors.New("bad startup message")
		}
		v, after2, ok := bytes.Cut(after, []byte{0})
		if !ok {
			return nil, errors.New("bad startup message")
		}
		if _, dup := params[string(k)]; dup {
			return nil, errors.New("duplicate startup parameter")
		}
		params[string(k)] = string(v)
		order = append(order, string(k))
		rest = after2
	}
	if subtle.ConstantTimeCompare([]byte(params["user"]), []byte(role)) != 1 {
		return nil, fmt.Errorf("connect as %s", role)
	}
	if _, ok := params["replication"]; ok {
		return nil, errors.New("replication connections are not allowed")
	}
	switch db, ok := params["database"]; {
	case !ok:
		params["database"] = database
		order = append(order, "database")
	case db != database:
		return nil, fmt.Errorf("connect to the database %s", database)
	}
	var b bytes.Buffer
	b.Write([]byte{0, 0, 0, 0})
	b.Write(pkt[4:8])
	for _, k := range order {
		b.WriteString(k)
		b.WriteByte(0)
		b.WriteString(params[k])
		b.WriteByte(0)
	}
	b.WriteByte(0)
	out := b.Bytes()
	binary.BigEndian.PutUint32(out, uint32(len(out))) //nolint:gosec // ≤ pgMaxStartup + a name
	return out, nil
}

// bufConn reads through a bufio.Reader that already peeked.
type bufConn struct {
	net.Conn
	r *bufio.Reader
}

func (c *bufConn) Read(p []byte) (int, error) { return c.r.Read(p) }

// ---- Redis / Valkey ----

// maxRESPFirst bounds the first command (AUTH/HELLO).
const maxRESPFirst = 8 << 10

// redisStartup terminates TLS and checks that the first command
// authenticates as the temporary user.
func (p *dbProxy) redisStartup(ctx context.Context, c net.Conn) (net.Conn, []byte, error) {
	tconn := tls.Server(c, serverTLS(p.o.TLS, nil))
	if err := tconn.HandshakeContext(ctx); err != nil {
		return nil, nil, err
	}
	br := bufio.NewReaderSize(tconn, 4096)
	args, raw, err := readRESPCommand(br)
	if err != nil {
		_, _ = tconn.Write([]byte("-ERR launchroom temporary access: send AUTH first\r\n"))
		_ = tconn.Close()
		return nil, nil, err
	}
	if !redisAuthenticatesAs(args, p.o.Role) {
		_, _ = tconn.Write([]byte("-NOAUTH launchroom temporary access: authenticate as " + p.o.Role + " first\r\n"))
		_ = tconn.Close()
		p.ep.failed(remoteAddr(c), false)
		return nil, nil, errors.New("redis: first command is not AUTH as the temporary user")
	}
	// Anything the client pipelined after AUTH goes upstream too.
	if n := br.Buffered(); n > 0 {
		rest, _ := br.Peek(n)
		raw = append(raw, rest...)
	}
	return tconn, raw, nil
}

// redisAuthenticatesAs reports whether args is AUTH <user> <pass> or
// HELLO <v> AUTH <user> <pass> … for user.
func redisAuthenticatesAs(args []string, user string) bool {
	if len(args) == 0 {
		return false
	}
	switch strings.ToUpper(args[0]) {
	case "AUTH":
		return len(args) == 3 && subtle.ConstantTimeCompare([]byte(args[1]), []byte(user)) == 1
	case "HELLO":
		for i := 2; i+2 < len(args); i++ {
			if strings.EqualFold(args[i], "AUTH") {
				return subtle.ConstantTimeCompare([]byte(args[i+1]), []byte(user)) == 1
			}
		}
	}
	return false
}

// readRESPCommand reads one command (RESP array of bulk strings, or an
// inline command) and returns its arguments and raw bytes.
func readRESPCommand(br *bufio.Reader) ([]string, []byte, error) {
	var raw bytes.Buffer
	line, err := readLine(br, &raw)
	if err != nil {
		return nil, nil, err
	}
	if !strings.HasPrefix(line, "*") {
		return strings.Fields(line), raw.Bytes(), nil
	}
	n, err := strconv.Atoi(line[1:])
	if err != nil || n < 1 || n > 16 {
		return nil, nil, errors.New("redis: bad command")
	}
	args := make([]string, 0, n)
	for range n {
		l, err := readLine(br, &raw)
		if err != nil {
			return nil, nil, err
		}
		if !strings.HasPrefix(l, "$") {
			return nil, nil, errors.New("redis: bad command")
		}
		size, err := strconv.Atoi(l[1:])
		if err != nil || size < 0 || raw.Len()+size > maxRESPFirst {
			return nil, nil, errors.New("redis: bad command")
		}
		b := make([]byte, size+2)
		if _, err := io.ReadFull(br, b); err != nil {
			return nil, nil, err
		}
		raw.Write(b)
		args = append(args, string(b[:size]))
	}
	return args, raw.Bytes(), nil
}

func readLine(br *bufio.Reader, raw *bytes.Buffer) (string, error) {
	var line []byte
	for {
		b, err := br.ReadByte()
		if err != nil {
			return "", err
		}
		line = append(line, b)
		if raw.Len()+len(line) > maxRESPFirst {
			return "", errors.New("redis: command too long")
		}
		if b == '\n' {
			break
		}
	}
	raw.Write(line)
	return strings.TrimRight(string(line), "\r\n"), nil
}
