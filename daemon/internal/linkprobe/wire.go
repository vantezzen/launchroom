// Package linkprobe measures the private-network links between launchroom
// servers (docs/05 §2.1): latency every minute and, rarely, a short,
// size-capped download. Each daemon answers probes on <mesh IP>:7400 for
// the peers its signed NodeState lists, and only for them.
//
// Wire format (one TCP connection per probe, over the mesh):
//
//  1. client → server: one JSON line (Hello, ≤ 1 KiB) signed with the
//     client daemon's Ed25519 key over helloMessage();
//  2. server → client: one JSON line (Ack) signed with the server daemon's
//     key over ackMessage() (the client's nonce), or an unsigned refusal
//     after the client authenticated ("busy");
//  3. ping: the client sends pingRounds 8-byte counters, the server echoes
//     each; transfer: the server writes Hello.Bytes zero bytes and closes.
//
// The server checks, before answering anything: the sender is a listed
// peer, the TCP source address is that peer's mesh IP, the hello is
// addressed to this server, its time is within ±60 s, its nonce is new and
// the signature verifies. Anything else closes the connection silently
// (fail closed). The mesh stays transport, not trust: the keys come from
// the dashboard-signed NodeState, the source address from WireGuard.
package linkprobe

import (
	"crypto/ed25519"
	"crypto/rand"
	"encoding/base64"
	"encoding/json"
	"errors"
	"fmt"
	"net/netip"
	"regexp"
	"strconv"
	"strings"
	"time"
)

// Op is what the client asks for.
type Op string

// Operations.
const (
	OpPing     Op = "ping"
	OpTransfer Op = "transfer"
)

const (
	wireVersion = 1
	// domain separates link-probe signatures from any other use of the key.
	domain = "launchroom-link-probe-v1"
	// maxLine bounds the hello and ack lines.
	maxLine = 1024
	// pingRounds is the number of echo round trips per latency probe.
	pingRounds = 5
	// MaxTransferBytes caps one throughput transfer (16 MiB).
	MaxTransferBytes = 16 << 20
	// clockWindow is how far a hello's time may be from the server's clock.
	clockWindow = 60 * time.Second
)

var (
	serverIDPattern = regexp.MustCompile(`^srv_[0-9A-HJKMNP-TV-Z]{26}$`)
	noncePattern    = regexp.MustCompile(`^[A-Za-z0-9_-]{22}$`)
)

// Hello opens a probe.
type Hello struct {
	V     int    `json:"v"`
	Op    Op     `json:"op"`
	From  string `json:"from"`
	To    string `json:"to"`
	At    string `json:"at"`
	Nonce string `json:"nonce"`
	Bytes int    `json:"bytes"`
	Sig   string `json:"sig"`
}

// Ack answers an authenticated hello.
type Ack struct {
	V     int    `json:"v"`
	OK    bool   `json:"ok"`
	Error string `json:"error,omitempty"`
	Sig   string `json:"sig,omitempty"`
}

func (h *Hello) message() []byte {
	return []byte(strings.Join([]string{domain, "hello", string(h.Op), h.From, h.To, h.At, h.Nonce, strconv.Itoa(h.Bytes)}, "\n"))
}

func ackMessage(server, client, nonce string) []byte {
	return []byte(strings.Join([]string{domain, "ack", server, client, nonce}, "\n"))
}

// check validates the hello's grammar (not its signature or freshness).
func (h *Hello) check() error {
	switch {
	case h.V != wireVersion:
		return errors.New("unsupported version")
	case h.Op != OpPing && h.Op != OpTransfer:
		return errors.New("unknown op")
	case !serverIDPattern.MatchString(h.From) || !serverIDPattern.MatchString(h.To):
		return errors.New("bad server id")
	case !noncePattern.MatchString(h.Nonce):
		return errors.New("bad nonce")
	case h.Op == OpPing && h.Bytes != 0:
		return errors.New("ping carries no bytes")
	case h.Op == OpTransfer && (h.Bytes < 1 || h.Bytes > MaxTransferBytes):
		return fmt.Errorf("transfer must be 1..%d bytes", MaxTransferBytes)
	}
	if _, err := time.Parse(time.RFC3339, h.At); err != nil {
		return errors.New("bad time")
	}
	return nil
}

func sign(signer func([]byte) []byte, msg []byte) string {
	return base64.StdEncoding.EncodeToString(signer(msg))
}

func verify(key ed25519.PublicKey, msg []byte, sig string) bool {
	raw, err := base64.StdEncoding.DecodeString(sig)
	if err != nil || len(raw) != ed25519.SignatureSize || len(key) != ed25519.PublicKeySize {
		return false
	}
	return ed25519.Verify(key, msg, raw)
}

func encodeLine(v any) ([]byte, error) {
	b, err := json.Marshal(v)
	if err != nil {
		return nil, err
	}
	if len(b) >= maxLine {
		return nil, errors.New("line too long")
	}
	return append(b, '\n'), nil
}

// Peer is a server this daemon measures and accepts probes from.
type Peer struct {
	ID  string
	IP  netip.Addr
	Key ed25519.PublicKey
}

// HelloLine builds a signed hello line from `from` to `to` and returns it
// with its nonce (the prober, and tests that probe from another netns).
func HelloLine(op Op, from, to string, size int, at time.Time, signer func([]byte) []byte) ([]byte, string, error) {
	raw := make([]byte, 16)
	if _, err := rand.Read(raw); err != nil {
		return nil, "", err
	}
	h := Hello{V: wireVersion, Op: op, From: from, To: to, At: at.UTC().Format(time.RFC3339),
		Nonce: base64.RawURLEncoding.EncodeToString(raw), Bytes: size}
	h.Sig = sign(signer, h.message())
	line, err := encodeLine(h)
	return line, h.Nonce, err
}

// CheckAck verifies an ack line: accepted, and signed by the server's key
// over this hello's nonce.
func CheckAck(line []byte, server, client, nonce string, key ed25519.PublicKey) error {
	var ack Ack
	if json.Unmarshal(line, &ack) != nil || ack.V != wireVersion {
		return errors.New("bad answer")
	}
	if !ack.OK {
		return fmt.Errorf("refused: %s", safeWord(ack.Error))
	}
	if !verify(key, ackMessage(server, client, nonce), ack.Sig) {
		return errors.New("answer not signed by the peer")
	}
	return nil
}
