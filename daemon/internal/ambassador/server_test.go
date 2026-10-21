package ambassador

import (
	"bufio"
	"context"
	"fmt"
	"io"
	"net"
	"strings"
	"sync"
	"testing"
	"time"
)

// echo is an upstream that answers every line with "<name>:<line>".
type echo struct {
	name string
	ln   net.Listener
	mu   sync.Mutex
	open int
}

func startEcho(t *testing.T, name string) *echo {
	t.Helper()
	var lc net.ListenConfig
	ln, err := lc.Listen(t.Context(), "tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	e := &echo{name: name, ln: ln}
	go func() {
		for {
			c, err := ln.Accept()
			if err != nil {
				return
			}
			go func() {
				e.mu.Lock()
				e.open++
				e.mu.Unlock()
				defer func() {
					e.mu.Lock()
					e.open--
					e.mu.Unlock()
					_ = c.Close()
				}()
				r := bufio.NewReader(c)
				for {
					line, err := r.ReadString('\n')
					if err != nil {
						return
					}
					if _, err := fmt.Fprintf(c, "%s:%s", e.name, line); err != nil {
						return
					}
				}
			}()
		}
	}()
	t.Cleanup(func() { _ = ln.Close() })
	return e
}

func (e *echo) addr() string { return e.ln.Addr().String() }

func freeAddr(t *testing.T) string {
	t.Helper()
	var lc net.ListenConfig
	ln, err := lc.Listen(t.Context(), "tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	a := ln.Addr().String()
	_ = ln.Close()
	return a
}

type client struct {
	c net.Conn
	r *bufio.Reader
}

func dial(t *testing.T, addr string) *client {
	t.Helper()
	var d net.Dialer
	c, err := d.DialContext(t.Context(), "tcp", addr)
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { _ = c.Close() })
	_ = c.SetDeadline(time.Now().Add(5 * time.Second))
	return &client{c: c, r: bufio.NewReader(c)}
}

func (c *client) ask(msg string) (string, error) {
	if _, err := io.WriteString(c.c, msg+"\n"); err != nil {
		return "", err
	}
	s, err := c.r.ReadString('\n')
	return strings.TrimSpace(s), err
}

func newServer(t *testing.T) *Server {
	t.Helper()
	ctx, cancel := context.WithCancel(t.Context())
	t.Cleanup(cancel)
	return NewServer(ctx, Options{CheckInterval: 50 * time.Millisecond, CheckTimeout: 50 * time.Millisecond, FailThreshold: 1, DialTimeout: time.Second})
}

func TestValidate(t *testing.T) {
	ok := Config{Listeners: []Listener{{Listen: "10.231.4.7:5432", Clients: "10.231.4.0/24", Upstreams: []string{"100.64.0.7:31001"}}}}
	if err := ok.Validate(); err != nil {
		t.Fatal(err)
	}
	for name, c := range map[string]Config{
		"bad listen":        {Listeners: []Listener{{Listen: "nope", Clients: "10.0.0.0/8", Upstreams: []string{"100.64.0.7:31001"}}}},
		"unspecified":       {Listeners: []Listener{{Listen: "0.0.0.0:5432", Clients: "0.0.0.0/0", Upstreams: []string{"100.64.0.7:31001"}}}},
		"clients elsewhere": {Listeners: []Listener{{Listen: "10.231.4.7:5432", Clients: "10.231.5.0/24", Upstreams: []string{"100.64.0.7:31001"}}}},
		"no upstreams":      {Listeners: []Listener{{Listen: "10.231.4.7:5432", Clients: "10.231.4.0/24"}}},
		"dup upstreams":     {Listeners: []Listener{{Listen: "10.231.4.7:5432", Clients: "10.231.4.0/24", Upstreams: []string{"100.64.0.7:31001", "100.64.0.7:31001"}}}},
		"balance":           {Listeners: []Listener{{Listen: "10.231.4.7:5432", Clients: "10.231.4.0/24", Upstreams: []string{"100.64.0.7:31001"}, Balance: "random"}}},
		"dup listen": {Listeners: []Listener{
			{Listen: "10.231.4.7:5432", Clients: "10.231.4.0/24", Upstreams: []string{"100.64.0.7:31001"}},
			{Listen: "10.231.4.7:5432", Clients: "10.231.4.0/24", Upstreams: []string{"100.64.0.8:31001"}},
		}},
		"negative drain": {DrainSeconds: -1},
	} {
		if c.Validate() == nil {
			t.Errorf("%s: accepted", name)
		}
	}
}

func TestForwardsBalancesAndFailsOver(t *testing.T) {
	a, b := startEcho(t, "a"), startEcho(t, "b")
	s := newServer(t)
	listen := freeAddr(t)
	cfg := Config{Listeners: []Listener{{Listen: listen, Clients: "127.0.0.0/8", Upstreams: []string{a.addr(), b.addr()}, Balance: RoundRobin}}}
	if err := s.Apply(cfg); err != nil {
		t.Fatal(err)
	}
	seen := map[string]int{}
	for range 4 {
		got, err := dial(t, listen).ask("hi")
		if err != nil {
			t.Fatal(err)
		}
		seen[strings.SplitN(got, ":", 2)[0]]++
	}
	if seen["a"] != 2 || seen["b"] != 2 {
		t.Fatalf("round robin: %v", seen)
	}

	// Least connections: with one connection held open on one upstream, the
	// next one goes to the other.
	cfg.Listeners[0].Balance = LeastConn
	if err := s.Apply(cfg); err != nil {
		t.Fatal(err)
	}
	held := dial(t, listen)
	first, err := held.ask("hold")
	if err != nil {
		t.Fatal(err)
	}
	// Whatever the round-robin rotation says, the idle upstream wins.
	for range 3 {
		c := dial(t, listen)
		got, err := c.ask("x")
		if err != nil {
			t.Fatal(err)
		}
		if got[:1] == first[:1] {
			t.Fatalf("least_conn picked the busy upstream: held %q, got %q", first, got)
		}
		_ = c.c.Close()
		time.Sleep(50 * time.Millisecond) // the proxied connection ends
	}

	// A dead upstream is skipped (health check / failed dial).
	_ = a.ln.Close()
	deadline := time.Now().Add(3 * time.Second)
	for s.Healthy(a.addr()) && time.Now().Before(deadline) {
		time.Sleep(20 * time.Millisecond)
	}
	for range 4 {
		got, err := dial(t, listen).ask("y")
		if err != nil || !strings.HasPrefix(got, "b:") {
			t.Fatalf("after a failed: %q %v", got, err)
		}
	}
}

func TestRefusesClientsOutsideTheNetwork(t *testing.T) {
	a := startEcho(t, "a")
	s := newServer(t)
	_, port, _ := net.SplitHostPort(freeAddr(t))
	// The listener binds 127.0.0.2 and accepts only that /32; a client on
	// 127.0.0.1 (another network, so to speak) is refused.
	listen := "127.0.0.2:" + port
	if err := s.Apply(Config{Listeners: []Listener{{Listen: listen, Clients: "127.0.0.2/32", Upstreams: []string{a.addr()}}}}); err != nil {
		t.Skipf("cannot listen on 127.0.0.2: %v", err)
	}
	if got, err := dial(t, listen).ask("hi"); err == nil {
		t.Fatalf("client outside the subnet was served: %q", got)
	}
	if err := s.Apply(Config{Listeners: []Listener{{Listen: listen, Clients: "127.0.0.0/8", Upstreams: []string{a.addr()}}}}); err != nil {
		t.Fatal(err)
	}
	if got, err := dial(t, listen).ask("hi"); err != nil || got != "a:hi" {
		t.Fatalf("client inside the subnet: %q %v", got, err)
	}
}

func TestDrainsRemovedUpstreamsAndListeners(t *testing.T) {
	a, b := startEcho(t, "a"), startEcho(t, "b")
	s := newServer(t)
	listen := freeAddr(t)
	cfg := Config{DrainSeconds: 1, Listeners: []Listener{{Listen: listen, Clients: "127.0.0.0/8", Upstreams: []string{a.addr()}}}}
	if err := s.Apply(cfg); err != nil {
		t.Fatal(err)
	}
	old := dial(t, listen)
	if got, err := old.ask("1"); err != nil || got != "a:1" {
		t.Fatalf("%q %v", got, err)
	}
	// Switch to b: the open connection to a keeps working during the drain…
	cfg.Listeners[0].Upstreams = []string{b.addr()}
	if err := s.Apply(cfg); err != nil {
		t.Fatal(err)
	}
	if got, err := old.ask("2"); err != nil || got != "a:2" {
		t.Fatalf("drained connection broke early: %q %v", got, err)
	}
	if got, err := dial(t, listen).ask("3"); err != nil || got != "b:3" {
		t.Fatalf("new connection: %q %v", got, err)
	}
	// …and is closed after it.
	time.Sleep(1500 * time.Millisecond)
	if _, err := old.ask("4"); err == nil {
		t.Fatal("connection to the removed upstream still open after the drain")
	}

	// Removing the listener stops accepting at once.
	if err := s.Apply(Config{Listeners: []Listener{}}); err != nil {
		t.Fatal(err)
	}
	if len(s.Listening()) != 0 {
		t.Fatalf("still listening: %v", s.Listening())
	}
	var d net.Dialer
	if c, err := d.DialContext(t.Context(), "tcp", listen); err == nil {
		_ = c.Close()
		t.Fatal("removed listener still accepts")
	}
}

func TestListenRetriedOnNextApply(t *testing.T) {
	a := startEcho(t, "a")
	s := newServer(t)
	// 127.0.0.1 port taken by another listener: Apply reports it, keeps the rest.
	busy := a.addr()
	free := freeAddr(t)
	err := s.Apply(Config{Listeners: []Listener{
		{Listen: busy, Clients: "127.0.0.0/8", Upstreams: []string{a.addr()}},
		{Listen: free, Clients: "127.0.0.0/8", Upstreams: []string{a.addr()}},
	}})
	if err == nil || !strings.Contains(err.Error(), busy) {
		t.Fatalf("err = %v", err)
	}
	if got := s.Listening(); len(got) != 1 || got[0] != free {
		t.Fatalf("listening %v", got)
	}
}
