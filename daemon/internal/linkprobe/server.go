package linkprobe

import (
	"bufio"
	"context"
	"encoding/json"
	"errors"
	"io"
	"log/slog"
	"net"
	"net/netip"
	"sync"
	"sync/atomic"
	"time"
)

// Server limits.
const (
	maxConns        = 16
	helloTimeout    = 3 * time.Second
	pingConnTimeout = 5 * time.Second
	transferTimeout = 20 * time.Second
	// transferGap is the minimum time between two transfers served to the
	// same peer; probers ask every few hours, so this only bounds abuse by
	// a misbehaving peer.
	transferGap = 5 * time.Minute
	// nonceTTL covers the clock window on both sides.
	nonceTTL  = 2 * clockWindow
	maxNonces = 8192
)

// ServerOptions configure a Server.
type ServerOptions struct {
	// Self is this server's id; hellos addressed elsewhere are refused.
	Self string
	// Sign signs with this daemon's Ed25519 key.
	Sign func([]byte) []byte
	Log  *slog.Logger
	Now  func() time.Time
}

// Server answers link probes from the listed peers.
type Server struct {
	o     ServerOptions
	peers atomic.Pointer[map[string]Peer]
	slots chan struct{}

	mu        sync.Mutex
	nonces    map[string]time.Time
	lastXfer  map[string]time.Time
	xferBusy  bool
	refusedAt time.Time
}

// NewServer returns a Server without peers (it refuses everything until SetPeers).
func NewServer(o ServerOptions) *Server {
	if o.Log == nil {
		o.Log = slog.New(slog.DiscardHandler)
	}
	if o.Now == nil {
		o.Now = time.Now
	}
	s := &Server{o: o, slots: make(chan struct{}, maxConns), nonces: map[string]time.Time{}, lastXfer: map[string]time.Time{}}
	empty := map[string]Peer{}
	s.peers.Store(&empty)
	return s
}

// SetPeers replaces the peers that may probe this server.
func (s *Server) SetPeers(peers []Peer) {
	m := make(map[string]Peer, len(peers))
	for _, p := range peers {
		m[p.ID] = p
	}
	s.peers.Store(&m)
}

// Serve accepts connections on l until ctx is done.
func (s *Server) Serve(ctx context.Context, l net.Listener) error {
	go func() {
		<-ctx.Done()
		_ = l.Close()
	}()
	for {
		c, err := l.Accept()
		if err != nil {
			if ctx.Err() != nil {
				return nil
			}
			var ne net.Error
			if errors.As(err, &ne) && ne.Timeout() {
				continue
			}
			return err
		}
		select {
		case s.slots <- struct{}{}:
			go func() {
				defer func() { <-s.slots }()
				s.handle(c)
			}()
		default:
			_ = c.Close()
		}
	}
}

func (s *Server) handle(c net.Conn) {
	defer func() { _ = c.Close() }()
	start := s.o.Now()
	_ = c.SetDeadline(time.Now().Add(helloTimeout))
	r := bufio.NewReaderSize(io.LimitReader(c, maxLine+pingRounds*8), maxLine)
	line, err := r.ReadSlice('\n')
	if err != nil {
		return
	}
	var h Hello
	if json.Unmarshal(line, &h) != nil {
		return
	}
	peer, why := s.authenticate(c.RemoteAddr(), &h, start)
	if why != "" {
		s.logRefusal(c.RemoteAddr(), why)
		return
	}
	if h.Op == OpTransfer && !s.beginTransfer(peer.ID, start) {
		if b, err := encodeLine(Ack{V: wireVersion, OK: false, Error: "busy"}); err == nil {
			_, _ = c.Write(b)
		}
		return
	}
	if h.Op == OpTransfer {
		defer s.endTransfer()
	}
	ack, err := encodeLine(Ack{V: wireVersion, OK: true, Sig: sign(s.o.Sign, ackMessage(s.o.Self, h.From, h.Nonce))})
	if err != nil {
		return
	}
	if _, err := c.Write(ack); err != nil {
		return
	}
	switch h.Op {
	case OpPing:
		_ = c.SetDeadline(time.Now().Add(pingConnTimeout))
		buf := make([]byte, 8)
		for range pingRounds {
			if _, err := io.ReadFull(r, buf); err != nil {
				return
			}
			if _, err := c.Write(buf); err != nil {
				return
			}
		}
	case OpTransfer:
		_ = c.SetDeadline(time.Now().Add(transferTimeout))
		zero := make([]byte, 64<<10)
		for left := h.Bytes; left > 0; {
			n := min(left, len(zero))
			if _, err := c.Write(zero[:n]); err != nil {
				return
			}
			left -= n
		}
	}
}

// authenticate returns the peer or why the hello is refused.
func (s *Server) authenticate(remote net.Addr, h *Hello, now time.Time) (Peer, string) {
	if err := h.check(); err != nil {
		return Peer{}, err.Error()
	}
	if h.To != s.o.Self {
		return Peer{}, "addressed to another server"
	}
	peer, ok := (*s.peers.Load())[h.From]
	if !ok {
		return Peer{}, "not a listed peer"
	}
	src, ok := addrOf(remote)
	if !ok || src != peer.IP {
		return Peer{}, "source address is not the peer's mesh address"
	}
	at, _ := time.Parse(time.RFC3339, h.At)
	if d := now.Sub(at); d > clockWindow || d < -clockWindow {
		return Peer{}, "time outside the window"
	}
	if !verify(peer.Key, h.message(), h.Sig) {
		return Peer{}, "bad signature"
	}
	// Record the nonce only after the signature verified, so nobody
	// unauthenticated can fill the cache.
	if !s.useNonce(h.From+"/"+h.Nonce, now) {
		return Peer{}, "replayed nonce"
	}
	return peer, ""
}

func (s *Server) useNonce(key string, now time.Time) bool {
	s.mu.Lock()
	defer s.mu.Unlock()
	if _, seen := s.nonces[key]; seen {
		return false
	}
	if len(s.nonces) >= maxNonces {
		for k, t := range s.nonces {
			if now.Sub(t) > nonceTTL {
				delete(s.nonces, k)
			}
		}
		if len(s.nonces) >= maxNonces {
			return false
		}
	}
	s.nonces[key] = now
	return true
}

func (s *Server) beginTransfer(peer string, now time.Time) bool {
	s.mu.Lock()
	defer s.mu.Unlock()
	if s.xferBusy || now.Sub(s.lastXfer[peer]) < transferGap {
		return false
	}
	s.xferBusy = true
	s.lastXfer[peer] = now
	return true
}

func (s *Server) endTransfer() {
	s.mu.Lock()
	s.xferBusy = false
	s.mu.Unlock()
}

// logRefusal logs refused probes, at most once a minute (a peer with a
// stale peer list would otherwise log every probe).
func (s *Server) logRefusal(remote net.Addr, why string) {
	s.mu.Lock()
	now := s.o.Now()
	quiet := now.Sub(s.refusedAt) < time.Minute
	if !quiet {
		s.refusedAt = now
	}
	s.mu.Unlock()
	if !quiet {
		s.o.Log.Warn("link probe refused", "remote", remote.String(), "reason", why)
	}
}

func addrOf(a net.Addr) (netip.Addr, bool) {
	ta, ok := a.(*net.TCPAddr)
	if !ok {
		return netip.Addr{}, false
	}
	ip, ok := netip.AddrFromSlice(ta.IP)
	return ip.Unmap(), ok
}
