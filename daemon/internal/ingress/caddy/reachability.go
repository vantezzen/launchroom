package caddy

import (
	"encoding/base64"
	"encoding/json"
	"net"
	"net/http"
	"regexp"
	"strings"
	"sync"
	"time"
)

// ReachabilityPath is the path prefix of the reachability check: the
// dashboard fetches http://<name>/.well-known/launchroom/check/<nonce>
// through public DNS and verifies the answer against the server's signing
// key, so a domain behind a proxy (Cloudflare) counts as reaching this
// server (docs/05 §5). The edge Caddy proxies the path to the loopback ask
// server on every host, before any route.
const ReachabilityPath = "/.well-known/launchroom/check/"

// ReachabilityContext prefixes every signed message. It is domain-separated:
// RFC 9421 signature bases start with a quoted component name, so a
// reachability signature can never be replayed as a request signature.
const ReachabilityContext = "launchroom-reachability/v1\n"

// Rate limit of the signing endpoint (all hosts together).
const (
	reachabilityRate  = 20 // tokens per second
	reachabilityBurst = 40
)

var (
	reNonce    = regexp.MustCompile(`^[A-Za-z0-9_-]{32,128}$`)
	reDNSLabel = regexp.MustCompile(`^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$`)
)

// ReachabilityMessage is the exact byte string signed for host and nonce.
func ReachabilityMessage(host, nonce string) []byte {
	return []byte(ReachabilityContext + host + "\n" + nonce)
}

// Reachability answers the check with an Ed25519 signature over
// ReachabilityMessage(host, nonce).
type Reachability struct {
	serverID string
	sign     func([]byte) []byte
	now      func() time.Time

	mu     sync.Mutex
	tokens float64
	last   time.Time
}

// NewReachability returns the handler for serverID signing with sign.
func NewReachability(serverID string, sign func([]byte) []byte, now func() time.Time) *Reachability {
	if now == nil {
		now = time.Now
	}
	return &Reachability{serverID: serverID, sign: sign, now: now, tokens: reachabilityBurst}
}

func (r *Reachability) allow() bool {
	r.mu.Lock()
	defer r.mu.Unlock()
	now := r.now()
	if !r.last.IsZero() {
		r.tokens = min(reachabilityBurst, r.tokens+now.Sub(r.last).Seconds()*reachabilityRate)
	}
	r.last = now
	if r.tokens < 1 {
		return false
	}
	r.tokens--
	return true
}

// normalizeHost lowercases, strips the port and a trailing dot, and checks
// the DNS hostname grammar (at least two labels).
func normalizeHost(h string) (string, bool) {
	if host, _, err := net.SplitHostPort(h); err == nil {
		h = host
	}
	h = strings.TrimSuffix(strings.ToLower(h), ".")
	if h == "" || len(h) > 253 {
		return "", false
	}
	labels := strings.Split(h, ".")
	if len(labels) < 2 {
		return "", false
	}
	for _, l := range labels {
		if !reDNSLabel.MatchString(l) {
			return "", false
		}
	}
	return h, true
}

type reachabilityAnswer struct {
	ServerID  string `json:"serverId"`
	Signature string `json:"signature"`
}

// ServeHTTP implements http.Handler.
func (r *Reachability) ServeHTTP(w http.ResponseWriter, req *http.Request) {
	nonce, ok := strings.CutPrefix(req.URL.Path, ReachabilityPath)
	if !ok || !reNonce.MatchString(nonce) {
		http.NotFound(w, req)
		return
	}
	if req.Method != http.MethodGet {
		w.Header().Set("Allow", http.MethodGet)
		http.Error(w, "method not allowed", http.StatusMethodNotAllowed)
		return
	}
	host, ok := normalizeHost(req.Host)
	if !ok {
		http.Error(w, "invalid host", http.StatusBadRequest)
		return
	}
	if !r.allow() {
		http.Error(w, "too many requests", http.StatusTooManyRequests)
		return
	}
	body, err := json.Marshal(reachabilityAnswer{
		ServerID:  r.serverID,
		Signature: base64.StdEncoding.EncodeToString(r.sign(ReachabilityMessage(host, nonce))),
	})
	if err != nil {
		http.Error(w, "internal error", http.StatusInternalServerError)
		return
	}
	w.Header().Set("Content-Type", "application/json")
	w.Header().Set("Cache-Control", "no-store")
	_, _ = w.Write(body)
}
