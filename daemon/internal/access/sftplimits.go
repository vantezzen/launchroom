package access

import (
	"encoding/binary"
	"errors"
	"io"
	"sync"
)

// SFTP limits (limits@openssh.com). pkg/sftp's request server neither
// advertises nor answers this extension, so clients fall back to 32 KiB per
// read and write request. With the round trip of a real connection that
// caps every client that keeps few requests in flight (OpenSSH's sftp and
// scp keep 64; many graphical clients fewer) far below the link: 32 KiB
// per round trip is 0.8 MB/s at 40 ms. OpenSSH's own sftp-server
// advertises 255 KiB, and so does limitsConn: it sits between the SSH
// channel and the request server, adds the extension to the server's
// VERSION packet and answers the client's limits request itself. Every
// other packet passes through unchanged (the request server still parses
// and bounds everything, including the request this answers).
const (
	// sftpMaxPacket is the longest packet the request server accepts
	// (pkg/sftp's maxMsgLength, excluding the length field).
	sftpMaxPacket = 256 << 10
	// sftpMaxData is the largest read or write payload, like OpenSSH's
	// sftp-server: room for the packet's own fields.
	sftpMaxData = sftpMaxPacket - 1024

	fxpVersion       = 2
	fxpExtended      = 200
	fxpExtendedReply = 201
	limitsExtension  = "limits@openssh.com"
)

type limitsConn struct {
	rw io.ReadWriteCloser

	// Read side (only the request server's read loop reads).
	pending []byte // bytes to hand out before reading the channel again
	left    uint32 // bytes of the current packet still to pass through

	// Write side.
	wmu     sync.Mutex
	started bool   // the first packet (VERSION) went out
	head    []byte // the first packet while it is buffered; a partial length field after it
	wleft   uint32 // bytes of the current outgoing packet still to come
	queued  []byte // a limits reply waiting for a packet boundary
}

func newLimitsConn(rw io.ReadWriteCloser) *limitsConn { return &limitsConn{rw: rw} }

func (c *limitsConn) Close() error { return c.rw.Close() }

func (c *limitsConn) Read(p []byte) (int, error) {
	for {
		if len(c.pending) > 0 {
			n := copy(p, c.pending)
			c.pending = c.pending[n:]
			return n, nil
		}
		if c.left > 0 {
			if uint32(len(p)) > c.left { //nolint:gosec // len is never negative
				p = p[:c.left]
			}
			n, err := c.rw.Read(p)
			c.left -= uint32(n) //nolint:gosec // n ≤ len(p) ≤ c.left
			return n, err
		}
		hdr := make([]byte, 5)
		if _, err := io.ReadFull(c.rw, hdr[:4]); err != nil {
			return 0, err
		}
		length := binary.BigEndian.Uint32(hdr)
		if length == 0 || length > sftpMaxPacket {
			// The request server refuses it after reading the length.
			c.pending, c.left = hdr[:4], length
			continue
		}
		if _, err := io.ReadFull(c.rw, hdr[4:]); err != nil {
			return 0, unexpectedEOF(err)
		}
		if hdr[4] != fxpExtended {
			c.pending, c.left = hdr, length-1
			continue
		}
		body := make([]byte, length-1)
		if _, err := io.ReadFull(c.rw, body); err != nil {
			return 0, unexpectedEOF(err)
		}
		if id, ok := limitsRequest(body); ok {
			if err := c.reply(limitsReply(id)); err != nil {
				return 0, err
			}
			continue
		}
		c.pending = append(hdr, body...)
	}
}

func unexpectedEOF(err error) error {
	if errors.Is(err, io.EOF) {
		return io.ErrUnexpectedEOF
	}
	return err
}

// limitsRequest parses an SSH_FXP_EXTENDED body (after the type): request
// id and name.
func limitsRequest(b []byte) (uint32, bool) {
	if len(b) < 8 {
		return 0, false
	}
	id, n := binary.BigEndian.Uint32(b), int64(binary.BigEndian.Uint32(b[4:]))
	if n > int64(len(b)-8) || string(b[8:8+n]) != limitsExtension {
		return 0, false
	}
	return id, true
}

// limitsReply is the SSH_FXP_EXTENDED_REPLY: max packet length, max read
// and write length, max open handles (0: no limit).
func limitsReply(id uint32) []byte {
	b := make([]byte, 0, 4+1+4+4*8)
	b = binary.BigEndian.AppendUint32(b, 1+4+4*8)
	b = append(b, fxpExtendedReply)
	b = binary.BigEndian.AppendUint32(b, id)
	b = binary.BigEndian.AppendUint64(b, sftpMaxPacket)
	b = binary.BigEndian.AppendUint64(b, sftpMaxData)
	b = binary.BigEndian.AppendUint64(b, sftpMaxData)
	return binary.BigEndian.AppendUint64(b, 0)
}

// reply sends a packet between the request server's packets.
func (c *limitsConn) reply(pkt []byte) error {
	c.wmu.Lock()
	defer c.wmu.Unlock()
	if !c.started || c.wleft > 0 || len(c.head) > 0 {
		c.queued = append(c.queued, pkt...)
		return nil
	}
	_, err := c.rw.Write(pkt)
	return err
}

func (c *limitsConn) Write(p []byte) (int, error) {
	c.wmu.Lock()
	defer c.wmu.Unlock()
	if c.started {
		return c.pass(p)
	}
	// The first packet is buffered whole (VERSION is small).
	c.head = append(c.head, p...)
	if len(c.head) < 4 {
		return len(p), nil
	}
	length := binary.BigEndian.Uint32(c.head)
	if length > sftpMaxPacket {
		c.started = true
		head := c.head
		c.head = nil
		if _, err := c.pass(head); err != nil {
			return 0, err
		}
		return len(p), nil
	}
	if uint32(len(c.head)-4) < length { //nolint:gosec // len is never negative
		return len(p), nil
	}
	first, rest := c.head[:4+length], c.head[4+length:]
	c.started, c.head = true, nil
	if length > 0 && first[4] == fxpVersion {
		first = withLimits(first)
	}
	if _, err := c.rw.Write(first); err != nil {
		return 0, err
	}
	if _, err := c.pass(rest); err != nil {
		return 0, err
	}
	return len(p), nil
}

// withLimits appends the extension pair to a VERSION packet.
func withLimits(pkt []byte) []byte {
	out := append([]byte(nil), pkt...)
	out = binary.BigEndian.AppendUint32(out, uint32(len(limitsExtension)))
	out = append(out, limitsExtension...)
	out = binary.BigEndian.AppendUint32(out, 1)
	out = append(out, '1')
	binary.BigEndian.PutUint32(out, uint32(len(out)-4)) //nolint:gosec // a small packet
	return out
}

// pass writes p through, tracking packet boundaries; a queued reply goes
// out at the first boundary.
func (c *limitsConn) pass(p []byte) (int, error) {
	n, err := c.rw.Write(p)
	b := p[:n]
	for len(b) > 0 {
		if c.wleft > 0 {
			k := min(uint32(len(b)), c.wleft) //nolint:gosec // len is never negative
			c.wleft -= k
			b = b[k:]
			continue
		}
		k := min(4-len(c.head), len(b))
		c.head = append(c.head, b[:k]...)
		b = b[k:]
		if len(c.head) == 4 {
			c.wleft = binary.BigEndian.Uint32(c.head)
			c.head = c.head[:0]
		}
	}
	if err != nil {
		return n, err
	}
	if len(c.queued) > 0 && c.wleft == 0 && len(c.head) == 0 {
		q := c.queued
		c.queued = nil
		if _, err := c.rw.Write(q); err != nil {
			return n, err
		}
	}
	return n, nil
}
