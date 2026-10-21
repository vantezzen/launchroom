package linkprobe

import (
	"bufio"
	"context"
	"crypto/rand"
	"encoding/binary"
	"errors"
	"fmt"
	"io"
	"log/slog"
	"math/big"
	"net"
	"net/netip"
	"slices"
	"sync"
	"time"

	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// Prober cadence (docs/05 §2.1). Latency is a handful of tiny messages per
// peer and minute; throughput moves at most TransferBytes per peer every
// TransferInterval (16 MiB every 6 h ≈ 64 MiB a day per peer and direction).
const (
	PingInterval     = time.Minute
	TransferInterval = 6 * time.Hour
	TransferBytes    = MaxTransferBytes
	// firstTransfer is the window after a peer first appears in which its
	// first transfer runs (randomized so a fleet does not start at once).
	firstTransferMin = 2 * time.Minute
	firstTransferMax = 7 * time.Minute
	dialTimeout      = 3 * time.Second
	pingTimeout      = 5 * time.Second
	xferTimeout      = 15 * time.Second
	// minPartial is the least a timed-out transfer must have moved to count.
	minPartial = 1 << 20
)

// ProberOptions configure a Prober.
type ProberOptions struct {
	Self string
	Sign func([]byte) []byte
	// LocalIP is this server's mesh IPv4 (invalid = not on the mesh: no probes).
	LocalIP func() netip.Addr
	// Port is the peers' probe port (state.LinkProbeMeshPort; tests use others).
	Port uint16
	Log  *slog.Logger
	Now  func() time.Time
	// Dial opens connections (net.Dialer with LocalAddr by default; tests).
	Dial func(ctx context.Context, local netip.Addr, remote netip.AddrPort) (net.Conn, error)
}

// Prober measures every peer and keeps the latest result per peer.
type Prober struct {
	o ProberOptions

	mu       sync.Mutex
	peers    []Peer
	results  map[string]state.LinkMeasurement
	nextXfer map[string]time.Time
	kick     chan struct{}
}

// NewProber returns a Prober without peers.
func NewProber(o ProberOptions) *Prober {
	if o.Log == nil {
		o.Log = slog.New(slog.DiscardHandler)
	}
	if o.Now == nil {
		o.Now = time.Now
	}
	if o.Port == 0 {
		o.Port = state.LinkProbeMeshPort
	}
	if o.Dial == nil {
		o.Dial = dialFrom
	}
	return &Prober{o: o, results: map[string]state.LinkMeasurement{}, nextXfer: map[string]time.Time{}, kick: make(chan struct{}, 1)}
}

func dialFrom(ctx context.Context, local netip.Addr, remote netip.AddrPort) (net.Conn, error) {
	d := net.Dialer{Timeout: dialTimeout, LocalAddr: &net.TCPAddr{IP: local.AsSlice()}}
	return d.DialContext(ctx, "tcp", remote.String())
}

// SetPeers replaces the peers. Results of peers that left are dropped; a
// new peer is probed on the next round (kicked now).
func (p *Prober) SetPeers(peers []Peer) {
	p.mu.Lock()
	changed := !slices.EqualFunc(p.peers, peers, func(a, b Peer) bool { return a.ID == b.ID && a.IP == b.IP && a.Key.Equal(b.Key) })
	p.peers = slices.Clone(peers)
	keep := map[string]bool{}
	for _, peer := range peers {
		keep[peer.ID] = true
		if _, ok := p.nextXfer[peer.ID]; !ok {
			p.nextXfer[peer.ID] = p.o.Now().Add(randomBetween(firstTransferMin, firstTransferMax))
		}
	}
	for id := range p.results {
		if !keep[id] {
			delete(p.results, id)
		}
	}
	for id := range p.nextXfer {
		if !keep[id] {
			delete(p.nextXfer, id)
		}
	}
	p.mu.Unlock()
	if changed && len(peers) > 0 {
		select {
		case p.kick <- struct{}{}:
		default:
		}
	}
}

// Snapshot returns the latest measurement per peer (sorted by peer id).
func (p *Prober) Snapshot() []state.LinkMeasurement {
	p.mu.Lock()
	defer p.mu.Unlock()
	out := make([]state.LinkMeasurement, 0, len(p.results))
	for _, m := range p.results {
		out = append(out, m)
	}
	slices.SortFunc(out, func(a, b state.LinkMeasurement) int { return cmpStr(string(a.Peer), string(b.Peer)) })
	if len(out) == 0 {
		return nil
	}
	return out
}

// Run probes until ctx is done: every peer each PingInterval (jittered),
// and a transfer when a peer's turn has come (one at a time). onRound is
// called after each round that measured something (to send a report).
func (p *Prober) Run(ctx context.Context, onRound func()) {
	for {
		select {
		case <-ctx.Done():
			return
		case <-p.kick:
		case <-time.After(jitter(PingInterval)):
		}
		if p.Round(ctx) && onRound != nil {
			onRound()
		}
	}
}

// Round probes every peer once (and runs due transfers). It reports
// whether anything was measured.
func (p *Prober) Round(ctx context.Context) bool {
	local := netip.Addr{}
	if p.o.LocalIP != nil {
		local = p.o.LocalIP()
	}
	p.mu.Lock()
	peers := slices.Clone(p.peers)
	p.mu.Unlock()
	if !local.IsValid() || len(peers) == 0 {
		return false
	}
	for _, peer := range peers {
		if ctx.Err() != nil {
			return false
		}
		m := state.LinkMeasurement{Peer: state.ServerID(peer.ID), MeasuredAt: p.o.Now().UTC().Truncate(time.Millisecond)}
		rtt, err := p.Ping(ctx, local, peer)
		if err != nil {
			m.Error = errorWord(err)
		} else {
			ms := float64(rtt.Microseconds()) / 1000
			m.RTTMs = &ms
		}
		p.mu.Lock()
		if prev, ok := p.results[peer.ID]; ok {
			m.ThroughputMbps, m.ThroughputAt = prev.ThroughputMbps, prev.ThroughputAt
		}
		due := err == nil && !p.o.Now().Before(p.nextXfer[peer.ID])
		p.results[peer.ID] = m
		p.mu.Unlock()
		if !due {
			continue
		}
		mbps, xerr := p.Transfer(ctx, local, peer, TransferBytes)
		p.mu.Lock()
		p.nextXfer[peer.ID] = p.o.Now().Add(jitter(TransferInterval))
		if xerr == nil {
			at := p.o.Now().UTC().Truncate(time.Millisecond)
			cur := p.results[peer.ID]
			cur.ThroughputMbps, cur.ThroughputAt = &mbps, &at
			p.results[peer.ID] = cur
		}
		p.mu.Unlock()
		if xerr != nil {
			p.o.Log.Info("link throughput probe failed", "peer", peer.ID, "err", xerr)
		}
	}
	return true
}

// Ping measures the median round trip to peer over one connection.
func (p *Prober) Ping(ctx context.Context, local netip.Addr, peer Peer) (time.Duration, error) {
	ctx, cancel := context.WithTimeout(ctx, pingTimeout)
	defer cancel()
	c, r, err := p.open(ctx, local, peer, OpPing, 0)
	if err != nil {
		return 0, err
	}
	defer func() { _ = c.Close() }()
	rtts := make([]time.Duration, 0, pingRounds)
	out, in := make([]byte, 8), make([]byte, 8)
	for i := range pingRounds {
		binary.BigEndian.PutUint64(out, uint64(i)+1) //nolint:gosec // small counter
		t := time.Now()
		if _, err := c.Write(out); err != nil {
			return 0, fmt.Errorf("ping: %w", err)
		}
		if _, err := io.ReadFull(r, in); err != nil {
			return 0, fmt.Errorf("ping: %w", err)
		}
		if binary.BigEndian.Uint64(in) != uint64(i)+1 { //nolint:gosec // small counter
			return 0, errors.New("ping: wrong echo")
		}
		rtts = append(rtts, time.Since(t))
	}
	slices.Sort(rtts)
	return rtts[len(rtts)/2], nil
}

// Transfer downloads size bytes from peer and returns the rate in Mbit/s.
func (p *Prober) Transfer(ctx context.Context, local netip.Addr, peer Peer, size int) (float64, error) {
	ctx, cancel := context.WithTimeout(ctx, xferTimeout)
	defer cancel()
	c, r, err := p.open(ctx, local, peer, OpTransfer, size)
	if err != nil {
		return 0, err
	}
	defer func() { _ = c.Close() }()
	start := time.Now()
	n, err := io.Copy(io.Discard, io.LimitReader(r, int64(size)))
	elapsed := time.Since(start)
	if n < int64(size) {
		// A slow link may not finish in time: what arrived until the
		// deadline still tells its rate, if it is enough to mean anything.
		var ne net.Error
		timedOut := ctx.Err() != nil || errors.As(err, &ne) && ne.Timeout()
		if n < minPartial || !timedOut {
			if err == nil {
				err = io.ErrUnexpectedEOF
			}
			return 0, fmt.Errorf("transfer: %d of %d bytes: %w", n, size, err)
		}
	}
	if elapsed <= 0 {
		elapsed = time.Microsecond
	}
	return float64(n) * 8 / elapsed.Seconds() / 1e6, nil
}

// open connects, sends the signed hello and verifies the peer's signed ack.
func (p *Prober) open(ctx context.Context, local netip.Addr, peer Peer, op Op, size int) (net.Conn, *bufio.Reader, error) {
	c, err := p.o.Dial(ctx, local, netip.AddrPortFrom(peer.IP, p.o.Port))
	if err != nil {
		return nil, nil, fmt.Errorf("connect: %w", err)
	}
	if dl, ok := ctx.Deadline(); ok {
		_ = c.SetDeadline(dl)
	}
	stop := context.AfterFunc(ctx, func() { _ = c.Close() })
	fail := func(err error) (net.Conn, *bufio.Reader, error) {
		stop()
		_ = c.Close()
		return nil, nil, err
	}
	line, nonce, err := HelloLine(op, p.o.Self, peer.ID, size, p.o.Now(), p.o.Sign)
	if err != nil {
		return fail(err)
	}
	if _, err := c.Write(line); err != nil {
		return fail(fmt.Errorf("hello: %w", err))
	}
	r := bufio.NewReaderSize(c, 64<<10)
	ackLine, err := r.ReadSlice('\n')
	if err != nil {
		return fail(fmt.Errorf("no answer: %w", err))
	}
	if err := CheckAck(ackLine, peer.ID, p.o.Self, nonce, peer.Key); err != nil {
		return fail(err)
	}
	return &stoppingConn{Conn: c, stop: stop}, r, nil
}

type stoppingConn struct {
	net.Conn
	stop func() bool
}

func (c *stoppingConn) Close() error {
	c.stop()
	return c.Conn.Close()
}

// errorWord turns a probe error into a short, secret-free reason.
func errorWord(err error) string {
	var ne net.Error
	switch {
	case errors.Is(err, context.DeadlineExceeded) || errors.As(err, &ne) && ne.Timeout():
		return "timed out"
	case errors.Is(err, io.EOF) || errors.Is(err, io.ErrUnexpectedEOF):
		return "connection closed by the peer"
	}
	s := err.Error()
	if len(s) > 200 {
		s = s[:200]
	}
	return s
}

func safeWord(s string) string {
	if s == "" || len(s) > 32 {
		return "unknown"
	}
	for _, r := range s {
		if (r < 'a' || r > 'z') && r != '_' {
			return "unknown"
		}
	}
	return s
}

func cmpStr(a, b string) int {
	switch {
	case a < b:
		return -1
	case a > b:
		return 1
	}
	return 0
}

// jitter spreads d by ±10 %.
func jitter(d time.Duration) time.Duration {
	return d - d/10 + randomBetween(0, d/5)
}

func randomBetween(lo, hi time.Duration) time.Duration {
	if hi <= lo {
		return lo
	}
	n, err := rand.Int(rand.Reader, big.NewInt(int64(hi-lo)))
	if err != nil {
		return lo
	}
	return lo + time.Duration(n.Int64())
}
