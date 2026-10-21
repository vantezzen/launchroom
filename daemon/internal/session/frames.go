// Package session runs interactive sessions (docs/03 §11, D35): a console in
// a managed workload container or a database data session, relayed through
// the dashboard's Reverb (Pusher protocol) on "private-session.<id>".
//
// Reverb is transport, not trust. Frames between browser and daemon carry an
// HMAC-SHA256 under the per-session key (sealed to this daemon at lease time)
// with direction, session id and a strictly increasing sequence; commands
// from the dashboard are Ed25519-signed and time-boxed. Everything else on
// the socket is dropped.
package session

import (
	"crypto/hmac"
	"crypto/sha256"
	"encoding/base64"
	"encoding/json/jsontext"
	"encoding/json/v2"
	"errors"
	"fmt"
	"time"

	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// Frame directions in the MAC input.
const (
	DirBrowserToDaemon = "b2d"
	DirDaemonToBrowser = "d2b"
	macPrefix          = "launchroom-session-v1\n"
	// FrameEvent is the Pusher client event carrying frames.
	FrameEvent = "client-frame"

	// MaxPayload bounds the payload string p of one frame.
	MaxPayload = 8 << 10
	// MaxData bounds the raw bytes in one in/out/result frame.
	MaxData = 4 << 10
	// MaxBadFrames ends a session after this many rejected frames.
	MaxBadFrames = 20
)

// Frame payload types.
const (
	THello   = "hello"
	TIn      = "in"
	TResize  = "resize"
	TPing    = "ping"
	TBye     = "bye"
	TReady   = "ready"
	TStarted = "started"
	TOut     = "out"
	TExit    = "exit"
	TResult  = "result"
	TEnd     = "end"
	TPong    = "pong"
)

// Frame is the data of a "client-frame" event.
type Frame struct {
	P string `json:"p"`
	M string `json:"m"`
}

// Payload is a decoded frame payload; only the fields of its type are set.
type Payload struct {
	S      int64      `json:"s"`
	T      string     `json:"t"`
	D      string     `json:"d,omitzero"`
	Cols   int        `json:"cols,omitzero"`
	Rows   int        `json:"rows,omitzero"`
	Kind   string     `json:"kind,omitzero"`
	Shell  string     `json:"shell,omitzero"`
	Code   *int       `json:"code,omitzero"`
	Op     state.OpID `json:"op,omitzero"`
	Part   int        `json:"part,omitzero"`
	Parts  int        `json:"parts,omitzero"`
	Reason string     `json:"reason,omitzero"`
}

var b64 = base64.RawURLEncoding

// MAC returns the frame MAC of payload p.
func MAC(key []byte, dir string, session state.SessionID, p string) string {
	h := hmac.New(sha256.New, key)
	_, _ = h.Write([]byte(macPrefix + dir + "\n" + string(session) + "\n" + p))
	return b64.EncodeToString(h.Sum(nil))
}

// Codec seals outgoing frames and opens incoming ones for one session.
type Codec struct {
	key     []byte
	session state.SessionID
	in, out string
	lastIn  int64
	nextOut int64
}

// NewCodec returns a codec; the daemon side sends d2b and accepts b2d frames.
func NewCodec(key []byte, session state.SessionID, daemonSide bool) *Codec {
	c := &Codec{key: key, session: session, in: DirBrowserToDaemon, out: DirDaemonToBrowser}
	if !daemonSide {
		c.in, c.out = c.out, c.in
	}
	return c
}

// Seal encodes payload p (its S is set here) into a frame.
func (c *Codec) Seal(p Payload) (Frame, error) {
	c.nextOut++
	p.S = c.nextOut
	raw, err := json.Marshal(p)
	if err != nil {
		return Frame{}, err
	}
	if len(raw) > MaxPayload {
		return Frame{}, fmt.Errorf("session: payload of %d bytes is too large", len(raw))
	}
	return Frame{P: string(raw), M: MAC(c.key, c.out, c.session, string(raw))}, nil
}

// ErrBadFrame is returned for frames that fail authentication or decoding.
var ErrBadFrame = errors.New("session: bad frame")

// Open authenticates and decodes an incoming frame (MAC first, then the
// payload, then the sequence).
func (c *Codec) Open(raw jsontext.Value) (Payload, error) {
	var f Frame
	if err := json.Unmarshal(raw, &f, json.RejectUnknownMembers(true)); err != nil {
		return Payload{}, fmt.Errorf("%w: %w", ErrBadFrame, err)
	}
	if len(f.P) > MaxPayload {
		return Payload{}, fmt.Errorf("%w: payload too large", ErrBadFrame)
	}
	want := MAC(c.key, c.in, c.session, f.P)
	if !hmac.Equal([]byte(want), []byte(f.M)) {
		return Payload{}, fmt.Errorf("%w: mac mismatch", ErrBadFrame)
	}
	var p Payload
	if err := json.Unmarshal([]byte(f.P), &p, json.RejectUnknownMembers(true)); err != nil {
		return Payload{}, fmt.Errorf("%w: %w", ErrBadFrame, err)
	}
	if p.S <= c.lastIn {
		return Payload{}, fmt.Errorf("%w: sequence %d not after %d", ErrBadFrame, p.S, c.lastIn)
	}
	c.lastIn = p.S
	return p, nil
}

// DecodeData decodes the base64url data of an in/out frame.
func DecodeData(d string) ([]byte, error) {
	if len(d) > b64.EncodedLen(MaxData) {
		return nil, fmt.Errorf("%w: data too large", ErrBadFrame)
	}
	b, err := b64.DecodeString(d)
	if err != nil {
		return nil, fmt.Errorf("%w: %w", ErrBadFrame, err)
	}
	return b, nil
}

// EncodeData encodes raw bytes for a frame.
func EncodeData(b []byte) string { return b64.EncodeToString(b) }

// bucket is a token bucket for browser input (bytes per second, burst).
type bucket struct {
	rate, burst, tokens float64
	last                time.Time
}

func newBucket(rate, burst float64, now time.Time) *bucket {
	return &bucket{rate: rate, burst: burst, tokens: burst, last: now}
}

// take spends n tokens; false if the bucket is empty.
func (b *bucket) take(n int, now time.Time) bool {
	b.tokens = min(b.burst, b.tokens+now.Sub(b.last).Seconds()*b.rate)
	b.last = now
	if b.tokens < float64(n) {
		return false
	}
	b.tokens -= float64(n)
	return true
}
