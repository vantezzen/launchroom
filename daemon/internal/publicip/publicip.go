// Package publicip finds the server's public IPv4 and IPv6 addresses, the
// ones DNS records must point at (docs/05 §5). The dashboard can't tell them
// from the connection: the control-plane daemon reaches it through a Docker
// port publish and appears as the bridge gateway (e.g. 172.18.0.1), and other
// servers may sit behind NAT. launchroomd asks IP echo services instead
// (the same ones install.sh uses), with the dial family forced, and reports
// only public answers (state.PublicIP).
package publicip

import (
	"context"
	"errors"
	"fmt"
	"io"
	"log/slog"
	"net"
	"net/http"
	"net/netip"
	"strings"
	"sync"
	"time"

	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// Default echo services per family.
var (
	DefaultIPv4URLs = []string{"https://api.ipify.org", "https://ipv4.icanhazip.com", "https://ifconfig.me/ip"}
	DefaultIPv6URLs = []string{"https://api6.ipify.org", "https://ipv6.icanhazip.com"}
)

// Cadence and limits.
const (
	DefaultInterval  = 6 * time.Hour
	DefaultRetry     = 10 * time.Minute
	requestTimeout   = 5 * time.Second
	maxBodyBytes     = 64
	maxConfiguredIPs = 2
	familyV4         = "tcp4"
	familyV6         = "tcp6"
)

// Public reports whether a is a globally routable unicast address.
func Public(a netip.Addr) bool { return state.PublicIP(a) }

// ParseConfigured parses LR_PUBLIC_IPS: comma-separated, at most one IPv4
// and one IPv6, each public. Empty input returns nil.
func ParseConfigured(s string) (*state.PublicAddresses, error) {
	s = strings.TrimSpace(s)
	if s == "" {
		return nil, nil //nolint:nilnil // nothing configured
	}
	parts := strings.Split(s, ",")
	if len(parts) > maxConfiguredIPs {
		return nil, errors.New("at most one IPv4 and one IPv6 address")
	}
	out := &state.PublicAddresses{}
	for _, p := range parts {
		a, err := netip.ParseAddr(strings.TrimSpace(p))
		if err != nil || a.Zone() != "" {
			return nil, fmt.Errorf("%q is not an IP address", strings.TrimSpace(p))
		}
		if a.Is4In6() || !Public(a) {
			return nil, fmt.Errorf("%s is not a public address", a)
		}
		switch {
		case a.Is4() && out.IPv4 == "":
			out.IPv4 = state.IPAddr(a.String())
		case a.Is6() && out.IPv6 == "":
			out.IPv6 = state.IPAddr(a.String())
		default:
			return nil, errors.New("at most one IPv4 and one IPv6 address")
		}
	}
	return out, nil
}

// Options configure a Detector.
type Options struct {
	// Off never contacts echo services (LR_PUBLIC_IP_DETECT=off).
	Off bool
	// Configured are the LR_PUBLIC_IPS addresses; set, nothing is detected.
	Configured *state.PublicAddresses
	// IPv4URLs and IPv6URLs override the echo services (tests).
	IPv4URLs, IPv6URLs []string
	// Dial overrides the dialer; network is "tcp4" or "tcp6" (tests).
	Dial func(ctx context.Context, network, addr string) (net.Conn, error)
	// Transport overrides the base transport (tests: TLS roots); its
	// DialContext is replaced by the family-forcing one.
	Transport *http.Transport
	Interval  time.Duration
	Retry     time.Duration
	OnChange  func()
	Log       *slog.Logger
	Now       func() time.Time
}

// Detector keeps the latest public addresses.
type Detector struct {
	o       Options
	clients map[string]*http.Client
	mu      sync.Mutex
	cur     *state.PublicAddresses
}

// New returns a Detector; with Configured set, Snapshot returns it at once.
func New(o Options) *Detector {
	if o.IPv4URLs == nil {
		o.IPv4URLs = DefaultIPv4URLs
	}
	if o.IPv6URLs == nil {
		o.IPv6URLs = DefaultIPv6URLs
	}
	if o.Interval <= 0 {
		o.Interval = DefaultInterval
	}
	if o.Retry <= 0 {
		o.Retry = DefaultRetry
	}
	if o.Log == nil {
		o.Log = slog.New(slog.DiscardHandler)
	}
	if o.Now == nil {
		o.Now = time.Now
	}
	d := &Detector{o: o, clients: map[string]*http.Client{}}
	for _, fam := range []string{familyV4, familyV6} {
		d.clients[fam] = d.client(fam)
	}
	if o.Configured != nil {
		c := *o.Configured
		c.DetectedAt = o.Now().UTC()
		d.cur = &c
	}
	return d
}

func (d *Detector) client(family string) *http.Client {
	var t *http.Transport
	if d.o.Transport != nil {
		t = d.o.Transport.Clone()
	} else {
		t = http.DefaultTransport.(*http.Transport).Clone() //nolint:forcetypeassert // stdlib default
	}
	t.Proxy = nil
	dial := d.o.Dial
	if dial == nil {
		var dl net.Dialer
		dial = dl.DialContext
	}
	t.DialContext = func(ctx context.Context, _, addr string) (net.Conn, error) { return dial(ctx, family, addr) }
	return &http.Client{
		Transport: t, Timeout: requestTimeout,
		CheckRedirect: func(*http.Request, []*http.Request) error { return http.ErrUseLastResponse },
	}
}

// Snapshot returns the latest addresses (nil while none are known).
func (d *Detector) Snapshot() *state.PublicAddresses {
	d.mu.Lock()
	defer d.mu.Unlock()
	if d.cur == nil {
		return nil
	}
	c := *d.cur
	return &c
}

// Detect asks the echo services once and stores the result. It reports
// whether any address was found. Off or configured detectors do nothing.
func (d *Detector) Detect(ctx context.Context) bool {
	if d.o.Off || d.o.Configured != nil {
		return d.Snapshot() != nil
	}
	v4 := d.ask(ctx, familyV4, d.o.IPv4URLs)
	v6 := d.ask(ctx, familyV6, d.o.IPv6URLs)
	if !v4.IsValid() && !v6.IsValid() {
		d.o.Log.Warn("could not detect a public address", "hint", "set LR_PUBLIC_IPS if this server has one")
		return false
	}
	next := &state.PublicAddresses{DetectedAt: d.o.Now().UTC()}
	if v4.IsValid() {
		next.IPv4 = state.IPAddr(v4.String())
	}
	if v6.IsValid() {
		next.IPv6 = state.IPAddr(v6.String())
	}
	d.mu.Lock()
	changed := d.cur == nil || d.cur.IPv4 != next.IPv4 || d.cur.IPv6 != next.IPv6
	d.cur = next
	d.mu.Unlock()
	if changed {
		d.o.Log.Info("public address detected", "ipv4", string(next.IPv4), "ipv6", string(next.IPv6))
		if d.o.OnChange != nil {
			d.o.OnChange()
		}
	}
	return true
}

// ask returns the first valid public answer of the family's services.
func (d *Detector) ask(ctx context.Context, family string, urls []string) netip.Addr {
	for _, u := range urls {
		a, err := d.fetch(ctx, family, u)
		if err != nil {
			d.o.Log.Debug("echo service failed", "url", u, "family", family, "err", err)
			continue
		}
		if (family == familyV4 && !a.Is4()) || (family == familyV6 && (!a.Is6() || a.Is4In6())) || !Public(a) {
			d.o.Log.Debug("echo service answer refused", "url", u, "family", family, "address", a.String())
			continue
		}
		return a
	}
	return netip.Addr{}
}

func (d *Detector) fetch(ctx context.Context, family, u string) (netip.Addr, error) {
	ctx, cancel := context.WithTimeout(ctx, requestTimeout)
	defer cancel()
	req, err := http.NewRequestWithContext(ctx, http.MethodGet, u, nil)
	if err != nil {
		return netip.Addr{}, err
	}
	req.Header.Set("User-Agent", "launchroomd")
	resp, err := d.clients[family].Do(req)
	if err != nil {
		return netip.Addr{}, err
	}
	defer func() { _ = resp.Body.Close() }()
	if resp.StatusCode != http.StatusOK {
		return netip.Addr{}, fmt.Errorf("HTTP %d", resp.StatusCode)
	}
	body, err := io.ReadAll(io.LimitReader(resp.Body, maxBodyBytes+1))
	if err != nil {
		return netip.Addr{}, err
	}
	if len(body) > maxBodyBytes {
		return netip.Addr{}, errors.New("answer too long")
	}
	a, err := netip.ParseAddr(strings.TrimSpace(string(body)))
	if err != nil || a.Zone() != "" {
		return netip.Addr{}, errors.New("answer is not an IP address")
	}
	return a, nil
}

// Run detects at start and every Interval (Retry after a failure) until ctx ends.
func (d *Detector) Run(ctx context.Context) {
	if d.o.Off || d.o.Configured != nil {
		return
	}
	for {
		wait := d.o.Interval
		if !d.Detect(ctx) {
			wait = d.o.Retry
		}
		t := time.NewTimer(wait)
		select {
		case <-ctx.Done():
			t.Stop()
			return
		case <-t.C:
		}
	}
}
