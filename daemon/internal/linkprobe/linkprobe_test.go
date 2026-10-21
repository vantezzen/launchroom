package linkprobe

import (
	"context"
	"crypto/ed25519"
	"crypto/rand"
	"net"
	"net/netip"
	"strings"
	"testing"
	"time"
)

const (
	idA = "srv_01J9Z8X7W6V5T4S3R2Q1P0N9MA"
	idB = "srv_01J9Z8X7W6V5T4S3R2Q1P0N9MB"
	idC = "srv_01J9Z8X7W6V5T4S3R2Q1P0N9MC"
)

type daemon struct {
	id   string
	pub  ed25519.PublicKey
	priv ed25519.PrivateKey
}

func newDaemon(t *testing.T, id string) daemon {
	t.Helper()
	pub, priv, err := ed25519.GenerateKey(rand.Reader)
	if err != nil {
		t.Fatal(err)
	}
	return daemon{id: id, pub: pub, priv: priv}
}

func (d daemon) sign(msg []byte) []byte { return ed25519.Sign(d.priv, msg) }

var loopback = netip.MustParseAddr("127.0.0.1")

// serve runs a Server for b on a loopback port and returns the port.
func serve(t *testing.T, b daemon, peers ...Peer) (*Server, uint16) {
	t.Helper()
	srv := NewServer(ServerOptions{Self: b.id, Sign: b.sign})
	srv.SetPeers(peers)
	var lc net.ListenConfig
	l, err := lc.Listen(context.Background(), "tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	ctx, cancel := context.WithCancel(context.Background())
	t.Cleanup(cancel)
	go func() { _ = srv.Serve(ctx, l) }()
	return srv, uint16(l.Addr().(*net.TCPAddr).Port) //nolint:gosec // a port
}

func prober(a daemon, port uint16) *Prober {
	return NewProber(ProberOptions{Self: a.id, Sign: a.sign, Port: port, LocalIP: func() netip.Addr { return loopback }})
}

func TestPingAndTransfer(t *testing.T) {
	a, b := newDaemon(t, idA), newDaemon(t, idB)
	_, port := serve(t, b, Peer{ID: a.id, IP: loopback, Key: a.pub})
	p := prober(a, port)
	peerB := Peer{ID: b.id, IP: loopback, Key: b.pub}

	rtt, err := p.Ping(context.Background(), loopback, peerB)
	if err != nil || rtt <= 0 || rtt > time.Second {
		t.Fatalf("ping = %v, %v", rtt, err)
	}
	mbps, err := p.Transfer(context.Background(), loopback, peerB, 4<<20)
	if err != nil || mbps <= 0 {
		t.Fatalf("transfer = %v, %v", mbps, err)
	}
	// A second transfer right away is refused (rate limit per peer).
	if _, err := p.Transfer(context.Background(), loopback, peerB, 1<<20); err == nil || !strings.Contains(err.Error(), "busy") {
		t.Fatalf("second transfer err = %v, want busy", err)
	}
}

func TestRoundRecordsMeasurements(t *testing.T) {
	a, b := newDaemon(t, idA), newDaemon(t, idB)
	_, port := serve(t, b, Peer{ID: a.id, IP: loopback, Key: a.pub})
	p := prober(a, port)
	p.SetPeers([]Peer{{ID: b.id, IP: loopback, Key: b.pub}})
	p.nextXfer[b.id] = time.Now().Add(-time.Second) // due now
	if !p.Round(context.Background()) {
		t.Fatal("round measured nothing")
	}
	snap := p.Snapshot()
	if len(snap) != 1 || string(snap[0].Peer) != b.id || snap[0].RTTMs == nil || snap[0].ThroughputMbps == nil || snap[0].ThroughputAt == nil || snap[0].Error != "" {
		t.Fatalf("snapshot = %+v", snap)
	}
	// Peers that leave drop their results.
	p.SetPeers(nil)
	if got := p.Snapshot(); got != nil {
		t.Fatalf("snapshot after removal = %+v", got)
	}
}

func TestProbeRefusals(t *testing.T) {
	a, b, c := newDaemon(t, idA), newDaemon(t, idB), newDaemon(t, idC)
	peerB := Peer{ID: b.id, IP: loopback, Key: b.pub}
	for name, tc := range map[string]struct {
		listed []Peer
		probe  daemon
		target Peer
	}{
		"unknown sender":         {listed: []Peer{{ID: c.id, IP: loopback, Key: c.pub}}, probe: a, target: peerB},
		"source is not the peer": {listed: []Peer{{ID: a.id, IP: netip.MustParseAddr("100.64.0.9"), Key: a.pub}}, probe: a, target: peerB},
		"key of another server":  {listed: []Peer{{ID: a.id, IP: loopback, Key: c.pub}}, probe: a, target: peerB},
		"answer from an impostor": {
			listed: []Peer{{ID: a.id, IP: loopback, Key: a.pub}}, probe: a,
			target: Peer{ID: b.id, IP: loopback, Key: c.pub}, // we expect c's key; b answers
		},
	} {
		t.Run(name, func(t *testing.T) {
			_, port := serve(t, b, tc.listed...)
			p := prober(tc.probe, port)
			if _, err := p.Ping(context.Background(), loopback, tc.target); err == nil {
				t.Fatal("ping succeeded, want refusal")
			}
		})
	}
}

func TestAuthenticate(t *testing.T) {
	a, b := newDaemon(t, idA), newDaemon(t, idB)
	now := time.Date(2026, 10, 20, 12, 0, 0, 0, time.UTC)
	srv := NewServer(ServerOptions{Self: b.id, Sign: b.sign, Now: func() time.Time { return now }})
	srv.SetPeers([]Peer{{ID: a.id, IP: loopback, Key: a.pub}})
	remote := &net.TCPAddr{IP: net.IPv4(127, 0, 0, 1), Port: 40000}
	hello := func(mut func(*Hello)) *Hello {
		h := &Hello{V: 1, Op: OpPing, From: a.id, To: b.id, At: now.Format(time.RFC3339), Nonce: "AAAAAAAAAAAAAAAAAAAAAA"}
		if mut != nil {
			mut(h)
		}
		h.Sig = sign(a.sign, h.message())
		return h
	}
	if _, why := srv.authenticate(remote, hello(nil), now); why != "" {
		t.Fatalf("valid hello refused: %s", why)
	}
	if _, why := srv.authenticate(remote, hello(nil), now); why != "replayed nonce" {
		t.Fatalf("replay: %q", why)
	}
	for name, mut := range map[string]func(*Hello){
		"other target": func(h *Hello) { h.To = idC; h.Nonce = "BBBBBBBBBBBBBBBBBBBBBB" },
		"old": func(h *Hello) {
			h.At = now.Add(-2 * time.Minute).Format(time.RFC3339)
			h.Nonce = "CCCCCCCCCCCCCCCCCCCCCC"
		},
		"future": func(h *Hello) {
			h.At = now.Add(2 * time.Minute).Format(time.RFC3339)
			h.Nonce = "DDDDDDDDDDDDDDDDDDDDDD"
		},
		"huge transfer":  func(h *Hello) { h.Op = OpTransfer; h.Bytes = MaxTransferBytes + 1; h.Nonce = "EEEEEEEEEEEEEEEEEEEEEE" },
		"ping with data": func(h *Hello) { h.Bytes = 5; h.Nonce = "FFFFFFFFFFFFFFFFFFFFFF" },
		"bad nonce":      func(h *Hello) { h.Nonce = "../../" },
		"version":        func(h *Hello) { h.V = 2; h.Nonce = "GGGGGGGGGGGGGGGGGGGGGG" },
	} {
		if _, why := srv.authenticate(remote, hello(mut), now); why == "" {
			t.Errorf("%s: accepted", name)
		}
	}
	// A tampered field breaks the signature.
	h := hello(func(h *Hello) { h.Nonce = "HHHHHHHHHHHHHHHHHHHHHH" })
	h.Op, h.Bytes = OpTransfer, 1024
	if _, why := srv.authenticate(remote, h, now); why != "bad signature" {
		t.Fatalf("tampered: %q", why)
	}
}
