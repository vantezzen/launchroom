// Package caddy renders the NodeState route table into Caddy's JSON config,
// applies it through the local admin API (127.0.0.1:2019, POST /load), runs
// launchroom's system Caddy container and answers Caddy's on-demand TLS
// "ask" from the cached route table (docs/05 §4, §5).
package caddy

import (
	"encoding/json"
	"errors"
	"fmt"
	"net"
	"net/netip"
	"regexp"
	"slices"
	"strconv"
	"strings"

	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// Defaults of the rendered config.
const (
	DefaultAdminListen = "127.0.0.1:2019"
	serverHTTPS        = "lr-https"
	serverHTTP         = "lr-http"
)

// Options are server-local render settings (never from the dashboard).
type Options struct {
	// AdminListen is Caddy's admin address (loopback only).
	AdminListen string
	// AskURL is the daemon's on-demand TLS permission endpoint, e.g. http://127.0.0.1:2021/caddy/ask.
	AskURL string
	// HTTPPort and HTTPSPort are where Caddy listens (80/443).
	HTTPPort, HTTPSPort int
	// RedirectHTTPSPort is the public HTTPS port used in redirects (443, or the host-mapped port in dev).
	RedirectHTTPSPort int
	// ForwardAuthOrigins are the "scheme://host:port" origins (port always
	// explicit) that protected routes may use for forward auth: the daemon's
	// control-plane URL and http://<ip:port> for LR_ALLOWED_LOCAL_UPSTREAMS
	// (local policy floor, docs/03 §8). A protected route with any other URL
	// fails closed (403, never proxied).
	ForwardAuthOrigins []string
	// ACMEDirectory overrides the ACME CA of every acme issuer (tests: a
	// Pebble server); empty = Caddy's defaults (Let's Encrypt, ZeroSSL).
	ACMEDirectory string
	// ACMERootFiles are PEM files inside the Caddy container that the ACME
	// client trusts for ACMEDirectory (tests only).
	ACMERootFiles []string
	// AccessLogAddress is the loopback ip:port launchroomd reads the HTTPS
	// server's access log from (request metrics, docs/15 §1.1); empty = no
	// access log.
	AccessLogAddress string
	// LocalUpstreams are the "ip:port" entries of LR_ALLOWED_LOCAL_UPSTREAMS:
	// a route's clientAuth (mTLS, docs/03 §4.3) is honoured only when every
	// upstream is one of them (local policy floor, docs/03 §8).
	LocalUpstreams []string
	// ReachabilityDial is the loopback ip:port of launchroomd's ask server:
	// when set, ReachabilityPath on every host (HTTP and HTTPS, before any
	// route) is proxied there, so the dashboard can confirm a domain reaches
	// this server even behind a proxy (docs/05 §5). Empty = no such route.
	ReachabilityDial string
	// Waf turns the web firewall on for routes with RouteSpec.waf (docs/22
	// §6.3); nil when the running Caddy has no firewall module, or for the
	// fallback load without it (docs/22 §6.6).
	Waf *WafOptions
}

// AccessLogger is the name of the access logger of the HTTPS server.
const AccessLogger = "lr-access"

// accessLogDeleted are the access log fields removed inside Caddy before a
// line leaves it: launchroomd needs only host, path, status, duration and
// sizes, never client addresses, headers or credentials (docs/15 §1.1).
var accessLogDeleted = []string{
	"request>headers", "request>remote_ip", "request>remote_port", "request>client_ip",
	"request>tls", "resp_headers", "user_id",
}

// Access log fields reduced inside Caddy (docs/15 §5.1): the URI loses its
// query string, the referrer (added by accessLogAppend) keeps only its host
// and the user agent only a bot keyword or "Mozilla" (anything else becomes
// empty). Go's leftmost-first alternation picks a bot keyword before
// "Mozilla", so crawlers that claim to be Mozilla count as bots.
const (
	AccessFieldReferer = "lr_referer"
	AccessFieldAgent   = "lr_agent"
	accessURIQuery     = `(?s)[?#].*$`
	accessRefererHost  = `(?s)^(?:[A-Za-z][A-Za-z0-9+.-]*://)?(?:[^/?#@]*@)?([^/?#:]*).*$`
	accessAgentClass   = `(?is)^(?:.*?(bot|crawl|spider|slurp|archiver|externalhit|headless|lighthouse|monitor|preview|curl|wget|python|httpclient|http-client|okhttp|java/|libwww|scrapy|go-http)|(mozilla)|).*$`
)

// accessLogAppend is the first route of the HTTPS server: it adds the
// referrer and user agent to the access line, where the filters above
// reduce them before the line leaves Caddy. It matches every request and
// never terminates.
func accessLogAppend() obj {
	return obj{"handle": []any{
		obj{"handler": "log_append", "key": AccessFieldReferer, "value": "{http.request.header.Referer}"},
		obj{"handler": "log_append", "key": AccessFieldAgent, "value": "{http.request.header.User-Agent}"},
	}}
}

// logging is Caddy's logging config with the access log going to
// launchroomd. The default log keeps Caddy's own JSON log on stderr (the
// certificate events are read from it, certs.go) without the access lines,
// so they never land in the container's log file.
func (o Options) logging() obj {
	accessName := "http.log.access." + AccessLogger
	def := obj{
		"writer":  obj{"output": "stderr"},
		"encoder": obj{"format": "json"},
	}
	var exclude []string
	if o.AccessLogAddress != "" {
		exclude = append(exclude, accessName)
	}
	if o.Waf != nil {
		// The firewall module logs every match with the client address,
		// the URI and the matched data: never into Caddy's log.
		exclude = append(exclude, wafLogger)
	}
	if exclude != nil {
		def["exclude"] = exclude
	}
	logs := obj{"default": def}
	if o.AccessLogAddress == "" {
		return obj{"logs": logs}
	}
	fields := obj{}
	for _, f := range accessLogDeleted {
		fields[f] = obj{"filter": "delete"}
	}
	fields["request>uri"] = obj{"filter": "regexp", "regexp": accessURIQuery, "value": ""}
	fields[AccessFieldReferer] = obj{"filter": "regexp", "regexp": accessRefererHost, "value": "${1}"}
	fields[AccessFieldAgent] = obj{"filter": "regexp", "regexp": accessAgentClass, "value": "${1}${2}"}
	logs[AccessLogger] = obj{
		// soft_start: Caddy starts and serves while launchroomd is
		// down; lines are dropped until it listens again.
		"writer":  obj{"output": "net", "address": "tcp/" + o.AccessLogAddress, "dial_timeout": "1s", "soft_start": true},
		"encoder": obj{"format": "filter", "wrap": obj{"format": "json"}, "fields": fields},
		"include": []string{accessName},
	}
	return obj{"logs": logs}
}

// Paths inside the Caddy container (its data volume) for files launchroomd
// copies in: ingress credentials (referenced through {file.*} placeholders,
// so they never appear in the config, the admin API or the config cache)
// and the certificate mirror served while shared storage is unreachable.
const (
	SecretsDir = "/data/launchroom/secrets"
	MirrorDir  = "/data/launchroom/mirror"
	// Secret file names in SecretsDir (DNS credentials: dnsSecretFile).
	SecretStorageUser     = "storage-username"
	SecretStoragePassword = "storage-password"
	SecretStorageKey      = "storage-key"
)

// TLS is the edge-wide certificate setup: NodeState.ingress plus local state.
type TLS struct {
	// Storage is shared Redis-protocol storage; nil = Caddy's file storage.
	Storage *Storage
	// DNS is the DNS-01 provider for wildcard routes; nil = per-host HTTP-01.
	DNS *state.DNSChallengeSpec
	// Mirror are certificates loaded from files while the shared storage is
	// unreachable (degraded mode; Storage is nil then).
	Mirror []MirrorFile
}

// Storage is the non-secret part of the shared storage config.
type Storage struct {
	Address     string
	KeyPrefix   string
	HasUsername bool
}

// MirrorFile is one mirrored certificate inside the Caddy container.
type MirrorFile struct {
	Certificate, Key string
	// Names are the certificate's DNS names: wildcards among them are not
	// automated while degraded (no second issuance into local storage).
	Names []string
}

// forwardAuthAllowed reports whether a protected route's auth URL is allowed.
func (o Options) forwardAuthAllowed(p *state.RouteProtection) bool {
	if p.ForwardAuth.URL.Validate() != nil {
		return false
	}
	return slices.Contains(o.ForwardAuthOrigins, p.ForwardAuth.URL.Origin())
}

// RefusedForwardAuth returns the protected routes whose forward-auth URL the
// local policy refuses; Render makes them answer 403 instead of proxying.
func RefusedForwardAuth(routes []Route, o Options) []state.RouteID {
	var out []state.RouteID
	for _, r := range routes {
		if r.Spec.Protection != nil && !o.forwardAuthAllowed(r.Spec.Protection) {
			out = append(out, r.Spec.ID)
		}
	}
	return out
}

// Route is a route with its upstreams resolved to dial addresses.
type Route struct {
	Spec state.RouteSpec
	// Dial are "ip:port" addresses; empty means no healthy upstream (503).
	Dial []string
	// EdgeSecretFile is the container path of the opened clientAuth edge
	// secret (set by the Manager); empty with a clientAuth means the secret
	// could not be opened and the route fails closed.
	EdgeSecretFile string
}

// emptyFingerprint is what {http.request.tls.client.fingerprint} expands to
// when no client certificate was presented (SHA-256 of nothing).
const emptyFingerprint = "e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855"

// clientAuthVar holds the verified client certificate's fingerprint ("" without one).
const clientAuthVar = "lr_client_cert"

// clientAuthAllowed reports whether a route's clientAuth may be honoured:
// its edge secret is open and every upstream is a local upstream of
// LR_ALLOWED_LOCAL_UPSTREAMS (the dashboard's loopback port).
func (o Options) clientAuthAllowed(r Route) bool {
	return r.Spec.ClientAuth != nil && r.EdgeSecretFile != "" && o.localOnly(r)
}

// localOnly reports whether every upstream of a route is a local upstream
// of LR_ALLOWED_LOCAL_UPSTREAMS: the dashboard's system route (app routes
// never reach them, docs/03 §8).
func (o Options) localOnly(r Route) bool {
	if len(r.Spec.Upstreams) == 0 {
		return false
	}
	for _, u := range r.Spec.Upstreams {
		if u.Address == "" || !slices.Contains(o.LocalUpstreams, string(u.Address)) {
			return false
		}
	}
	return true
}

// RefusedClientAuth returns the routes whose clientAuth the local policy
// refuses (or whose edge secret is unusable); Render makes them answer 403.
func RefusedClientAuth(routes []Route, o Options) []state.RouteID {
	var out []state.RouteID
	for _, r := range routes {
		if r.Spec.ClientAuth != nil && !o.clientAuthAllowed(r) {
			out = append(out, r.Spec.ID)
		}
	}
	return out
}

// clientAuthRoutes are the first subroute entries of a route with an
// allowed clientAuth: the verified fingerprint into a variable ("" when no
// certificate was presented), and with mode require a 401 for the daemon API
// without one (enrollment and certificate issuance exempt).
func clientAuthRoutes(a *state.RouteClientAuth) []any {
	sub := []any{
		obj{"handle": []any{obj{"handler": "vars", clientAuthVar: "{http.request.tls.client.fingerprint}"}}},
		// Without a client certificate Caddy's fingerprint placeholder is the
		// SHA-256 of zero bytes: no certificate is "" for the dashboard.
		obj{
			"match":  []any{obj{"vars": obj{clientAuthVar: []string{emptyFingerprint}}}},
			"handle": []any{obj{"handler": "vars", clientAuthVar: ""}},
		},
	}
	if a.Mode == state.ClientAuthRequire {
		sub = append(sub, obj{
			"match": []any{obj{
				"path": []string{state.DaemonAPIPrefix + "*"},
				"vars": obj{clientAuthVar: []string{""}},
				"not":  []any{obj{"path": []string{state.EnrollPath, state.ClientCertificatePath}}},
			}},
			"handle":   []any{pageClientCertRequired.handler(401)},
			"terminal": true,
		})
	}
	return sub
}

// clientAuthPolicies are the TLS connection policies: one per allowed
// clientAuth route (its hosts by SNI) that verifies a presented certificate
// against the route's CAs, then a catch-all without client auth. nil when no
// route asks for client certificates (Caddy's defaults).
func clientAuthPolicies(routes []Route, o Options) []any {
	var out []any
	for _, r := range routes {
		if !o.clientAuthAllowed(r) {
			continue
		}
		hosts := make([]string, 0, len(r.Spec.Hosts))
		for _, h := range r.Spec.Hosts {
			hosts = append(hosts, string(h))
		}
		cas := make([]string, 0, len(r.Spec.ClientAuth.CACertificates))
		for _, c := range r.Spec.ClientAuth.CACertificates {
			cas = append(cas, string(c))
		}
		out = append(out, obj{
			"match": obj{"sni": hosts},
			"client_authentication": obj{
				"ca":   obj{"provider": "inline", "trusted_ca_certs": cas},
				"mode": "verify_if_given",
			},
		})
	}
	if out == nil {
		return nil
	}
	return append(out, obj{})
}

// Endpoint is what the resolver knows about a same-server workload upstream.
type Endpoint struct {
	Addr    netip.Addr // container IP on its primary network
	Serving bool       // role serving (not draining)
	Ready   bool       // running and healthy (or running long enough without a probe)
}

// Resolver looks up a workload's endpoint (ok=false: no container).
type Resolver func(id state.WorkloadID) (Endpoint, bool)

// ResolveRoutes turns workload references into dial addresses. Only ready
// workloads receive traffic; serving ones are preferred and draining ones are
// used only when no serving workload of the route is ready (better than a
// 503 during a botched rollout). Address (mesh) upstreams are always used.
func ResolveRoutes(routes []state.RouteSpec, resolve Resolver) []Route {
	out := make([]Route, 0, len(routes))
	for _, r := range routes {
		var serving, draining, remote []string
		for _, u := range r.Upstreams {
			if u.Address != "" {
				remote = append(remote, string(u.Address))
				continue
			}
			ep, ok := resolve(u.Workload)
			if !ok || !ep.Ready || !ep.Addr.IsValid() {
				continue
			}
			dial := netip.AddrPortFrom(ep.Addr, uint16(u.Port)).String() //nolint:gosec // validated 1-65535
			if ep.Serving {
				serving = append(serving, dial)
			} else {
				draining = append(draining, dial)
			}
		}
		local := serving
		if len(local) == 0 {
			local = draining
		}
		dial := append(local, remote...)
		if r.LoadBalancing == state.LBPreferLocal && len(local) > 0 {
			// Mesh upstreams only while no replica on this edge is ready.
			dial = local
		}
		out = append(out, Route{Spec: r, Dial: dial})
	}
	return out
}

type obj = map[string]any

func redirect(location string) obj {
	return obj{"handler": "static_response", "status_code": 308, "headers": obj{"Location": []string{location}}}
}

func (o Options) httpsOrigin(host string) string {
	if o.RedirectHTTPSPort == 0 || o.RedirectHTTPSPort == 443 {
		return "https://" + host
	}
	return "https://" + host + ":" + strconv.Itoa(o.RedirectHTTPSPort)
}

// Hosts returns all hosts of the routes (for the ask endpoint).
func Hosts(routes []Route) []string {
	var out []string
	for _, r := range routes {
		for _, h := range r.Spec.Hosts {
			out = append(out, string(h))
		}
	}
	slices.Sort(out)
	return out
}

// Render produces the Caddy JSON config with Caddy's default certificate
// handling (file storage, per-host certificates).
func Render(routes []Route, o Options) ([]byte, error) {
	return RenderTLS(routes, TLS{}, o)
}

// RenderTLS produces the complete Caddy JSON config. It is pure and deterministic.
func RenderTLS(routes []Route, t TLS, o Options) ([]byte, error) {
	if o.AdminListen == "" {
		o.AdminListen = DefaultAdminListen
	}
	if ap, err := netip.ParseAddrPort(o.AdminListen); err != nil || !ap.Addr().IsLoopback() {
		return nil, errors.New("caddy: admin must listen on a loopback address")
	}
	if o.HTTPPort == 0 {
		o.HTTPPort = 80
	}
	if o.HTTPSPort == 0 {
		o.HTTPSPort = 443
	}
	if o.AskURL == "" {
		return nil, errors.New("caddy: ask URL is required")
	}
	if o.AccessLogAddress != "" {
		if ap, err := netip.ParseAddrPort(o.AccessLogAddress); err != nil || !ap.Addr().IsLoopback() {
			return nil, errors.New("caddy: the access log address must be a loopback ip:port")
		}
	}

	httpsRoutes := []any{}
	httpRoutes := []any{}
	if o.AccessLogAddress != "" {
		httpsRoutes = append(httpsRoutes, accessLogAppend())
	}
	if o.ReachabilityDial != "" {
		if ap, err := netip.ParseAddrPort(o.ReachabilityDial); err != nil || !ap.Addr().IsLoopback() || ap.Port() == 0 {
			return nil, errors.New("caddy: the reachability dial must be a loopback ip:port")
		}
		check := obj{
			"match":    []any{obj{"path": []string{ReachabilityPath + "*"}}},
			"handle":   []any{obj{"handler": "reverse_proxy", "upstreams": []any{obj{"dial": o.ReachabilityDial}}}},
			"terminal": true,
		}
		httpsRoutes = append(httpsRoutes, check)
		httpRoutes = append(httpRoutes, check)
	}
	var wafDirs string
	if o.Waf != nil {
		var err error
		if wafDirs, err = wafDirectives(WafRoutes(routes, o), o.Waf.LogAddress); err != nil {
			return nil, err
		}
	}
	var acmeHosts, internalHosts, onDemandHosts []string
	wildcards := map[string]bool{}
	seen := map[string]bool{}

	for _, r := range routes {
		hosts := make([]string, 0, len(r.Spec.Hosts))
		for _, h := range r.Spec.Hosts {
			if seen[string(h)] {
				return nil, fmt.Errorf("caddy: host %s is in more than one route", h)
			}
			seen[string(h)] = true
			hosts = append(hosts, string(h))
		}
		switch r.Spec.TLS {
		case state.TLSACME:
			acmeHosts = append(acmeHosts, hosts...)
		case state.TLSInternal:
			internalHosts = append(internalHosts, hosts...)
		case state.TLSOnDemand:
			onDemandHosts = append(onDemandHosts, hosts...)
		case state.TLSWildcard:
			// Covered by "*.<parent>" (Caddy prefers a managed wildcard over
			// per-host certificates); per-host HTTP-01 without a provider.
			for _, h := range r.Spec.Hosts {
				if w := t.DNS.WildcardFor(h); w != "" {
					wildcards[w] = true
				} else {
					acmeHosts = append(acmeHosts, string(h))
				}
			}
		default:
			return nil, fmt.Errorf("caddy: route %s: unknown tls mode %q", r.Spec.ID, r.Spec.TLS)
		}

		canonical := string(r.Spec.RedirectWWWTo)
		var others []string
		if canonical != "" {
			for _, h := range hosts {
				if h != canonical {
					others = append(others, h)
				}
			}
		}

		// HTTPS: www ↔ apex redirect, web firewall, forward auth, headers,
		// compression, proxy.
		sub := []any{}
		if len(others) > 0 {
			sub = append(sub, obj{
				"match":    []any{obj{"host": others}},
				"handle":   []any{redirect(o.httpsOrigin(canonical) + "{http.request.uri}")},
				"terminal": true,
			})
		}
		if r.Spec.Protection != nil && !o.forwardAuthAllowed(r.Spec.Protection) {
			// Fail closed: a protected route is never served without its check.
			sub = append(sub, obj{"handle": []any{pageRefusedAuth.handler(403)}})
		} else if r.Spec.ClientAuth != nil && !o.clientAuthAllowed(r) {
			// Fail closed: client certificates are only checked for the
			// dashboard's loopback upstream, never another route.
			sub = append(sub, obj{"handle": []any{pageRefusedClientAuth.handler(403)}})
		} else {
			if r.Spec.ClientAuth != nil {
				sub = append(sub, clientAuthRoutes(r.Spec.ClientAuth)...)
			}
			if o.wafOn(r) {
				sub = append(sub, wafHandlers(r.Spec.ID, wafDirs)...)
			}
			sub = append(sub, appRoutes(r)...)
		}
		httpsRoutes = append(httpsRoutes, obj{
			"@id":      "lr-route-" + string(r.Spec.ID),
			"match":    []any{obj{"host": hosts}},
			"handle":   []any{obj{"handler": "subroute", "routes": sub}},
			"terminal": true,
		})

		// HTTP: redirect to HTTPS (straight to the canonical host when set).
		target := "{http.request.host}"
		if canonical != "" {
			target = canonical
		}
		httpRoutes = append(httpRoutes, obj{
			"match":    []any{obj{"host": hosts}},
			"handle":   []any{redirect(o.httpsOrigin(target) + "{http.request.uri}")},
			"terminal": true,
		})
	}
	notFound := obj{"handle": []any{pageNotFound.handler(404)}, "terminal": true}
	httpsRoutes = append(httpsRoutes, notFound)
	httpRoutes = append(httpRoutes, notFound)

	policies := []any{}
	if len(internalHosts) > 0 {
		policies = append(policies, obj{"subjects": internalHosts, "issuers": []any{obj{"module": "internal"}}})
	}
	for _, m := range t.Mirror {
		for _, n := range m.Names {
			delete(wildcards, n)
		}
	}
	var automate []string
	if len(wildcards) > 0 {
		for w := range wildcards {
			automate = append(automate, w)
		}
		slices.Sort(automate)
		policies = append(policies, obj{"subjects": automate, "issuers": []any{o.acmeIssuer(dnsChallenge(t.DNS))}})
	}
	if len(onDemandHosts) > 0 {
		p := obj{"subjects": onDemandHosts, "on_demand": true}
		if o.ACMEDirectory != "" {
			p["issuers"] = []any{o.acmeIssuer(nil)}
		}
		policies = append(policies, p)
	}
	if len(acmeHosts) > 0 {
		p := obj{"subjects": acmeHosts}
		if o.ACMEDirectory != "" {
			p["issuers"] = []any{o.acmeIssuer(nil)}
		}
		policies = append(policies, p)
	}
	tlsApp := obj{
		"automation": obj{
			"policies": policies,
			"on_demand": obj{
				"permission": obj{"module": "http", "endpoint": o.AskURL},
			},
		},
	}
	certs := obj{}
	if len(automate) > 0 {
		certs["automate"] = automate
	}
	if len(t.Mirror) > 0 {
		files := make([]any, 0, len(t.Mirror))
		for _, m := range t.Mirror {
			files = append(files, obj{"certificate": m.Certificate, "key": m.Key, "tags": []string{"launchroom-mirror"}})
		}
		certs["load_files"] = files
	}
	if len(certs) > 0 {
		tlsApp["certificates"] = certs
	}

	https := obj{
		"listen":          []string{":" + strconv.Itoa(o.HTTPSPort)},
		"routes":          httpsRoutes,
		"errors":          obj{"routes": o.errorRoutes(routes)},
		"automatic_https": obj{"disable_redirects": true},
	}
	if pol := clientAuthPolicies(routes, o); pol != nil {
		https["tls_connection_policies"] = pol
	}
	cfg := obj{
		"admin": obj{"listen": o.AdminListen},
		"apps": obj{
			"http": obj{
				"http_port":  o.HTTPPort,
				"https_port": o.HTTPSPort,
				"servers": obj{
					serverHTTPS: https,
					serverHTTP: obj{
						"listen": []string{":" + strconv.Itoa(o.HTTPPort)},
						"routes": httpRoutes,
					},
				},
			},
			"tls": tlsApp,
		},
	}
	if t.Storage != nil {
		cfg["storage"] = redisStorage(t.Storage)
	}
	if o.AccessLogAddress != "" {
		https["logs"] = obj{"default_logger_name": AccessLogger}
	}
	if o.AccessLogAddress != "" || o.Waf != nil {
		cfg["logging"] = o.logging()
	}
	data, err := json.MarshalIndent(cfg, "", "  ")
	if err != nil {
		return nil, fmt.Errorf("caddy: encode config: %w", err)
	}
	return append(data, '\n'), nil
}

// appRoutes are the subroute entries after the www redirect: forward auth
// (protected routes), response headers, compression and the proxy (or 503).
func appRoutes(r Route) []any {
	var sub []any
	if r.Spec.Protection != nil {
		sub = append(sub, obj{"handle": []any{forwardAuthHandler(r.Spec.Protection.ForwardAuth)}})
	}
	if hdr := headersHandler(r.Spec.Headers); hdr != nil {
		sub = append(sub, obj{"handle": []any{hdr}})
	}
	if len(r.Dial) == 0 {
		return append(sub, obj{"handle": []any{pageUnavailable.handler(503)}})
	}
	ups := make([]any, 0, len(r.Dial))
	for _, d := range r.Dial {
		ups = append(ups, obj{"dial": d})
	}
	proxy := obj{
		"handler":        "reverse_proxy",
		"upstreams":      ups,
		"flush_interval": -1, // streaming / SSE / WebSocket pass-through
		"load_balancing": obj{
			"selection_policy": obj{"policy": "round_robin"},
			"try_duration":     "5s",
			"try_interval":     "250ms",
		},
		"health_checks": obj{"passive": obj{"fail_duration": "10s", "max_fails": 1, "unhealthy_status": []int{502, 503, 504}}},
	}
	if r.Spec.ClientAuth != nil {
		// The verified identity and the edge secret for the dashboard
		// (docs/03 §4.3); set, so whatever a client sent is overwritten.
		proxy["headers"] = obj{"request": obj{"set": obj{
			state.ClientCertHeader: []string{"{http.vars." + clientAuthVar + "}"},
			state.EdgeSecretHeader: []string{"{file." + r.EdgeSecretFile + "}"},
		}}}
	}
	return append(sub,
		obj{"handle": []any{obj{"handler": "encode", "encodings": obj{"zstd": obj{}, "gzip": obj{}}, "prefer": []string{"zstd", "gzip"}}}},
		obj{"handle": []any{proxy}},
	)
}

// EdgeSecretFile is the container path of a route's clientAuth edge secret.
func EdgeSecretFile(id state.RouteID) string {
	return SecretsDir + "/client-auth-" + strings.ToLower(string(id))
}

func headersHandler(h map[state.HeaderName]string) obj {
	if len(h) == 0 {
		return nil
	}
	set := obj{}
	var del []string
	for name, v := range h {
		if v == "" {
			del = append(del, string(name))
			continue
		}
		set[string(name)] = []string{v}
	}
	resp := obj{}
	if len(set) > 0 {
		resp["set"] = set
	}
	if len(del) > 0 {
		slices.Sort(del)
		resp["delete"] = del
	}
	return obj{"handler": "headers", "response": resp}
}

// PreviewAuthCookie is the host-only cookie the dashboard's preview auth
// endpoint sets (dashboard config launchroom.previews.cookie, docs/05 §4).
const PreviewAuthCookie = "__Host-lr-preview"

// forwardAuthHandler is the expansion of Caddy's forward_auth directive
// (modules/caddyhttp/reverseproxy/forwardauth): a reverse_proxy to the auth
// endpoint with method GET (no request body is sent), the original method and
// URI in X-Forwarded-Method/X-Forwarded-Uri, and a handle_response route for
// 2xx answers that falls through to the next routes (the app). Any other
// answer (e.g. 302 with Location and Set-Cookie) is copied to the client as
// is. X-Forwarded-For/-Proto/-Host are set by reverse_proxy itself and
// overwrite client-supplied values (no trusted proxies are configured).
func forwardAuthHandler(fa state.ForwardAuth) obj {
	scheme, host, port, _ := fa.URL.Parts() // validated by forwardAuthAllowed
	dial := net.JoinHostPort(host, strconv.Itoa(int(port)))

	// At least one route is needed, or reverse_proxy skips the handler; a
	// no-op vars handler, like Caddy's own forward_auth.
	good := []any{obj{"handle": []any{obj{"handler": "vars"}}}}
	// The preview cookie authenticates a member or share link to launchroom,
	// not to the app: it never reaches the (possibly fork) app's code
	// (2026-10 review L8). Removed from the Cookie header; other cookies stay.
	strip := obj{"replace": obj{"Cookie": []any{
		obj{"search_regexp": `(^|;)\s*` + regexp.QuoteMeta(PreviewAuthCookie) + `=[^;]*`, "replace": ""},
		obj{"search_regexp": `^;\s*`, "replace": ""},
	}}}
	if len(fa.StripHeaders) > 0 {
		del := make([]string, 0, len(fa.StripHeaders))
		for _, h := range fa.StripHeaders {
			del = append(del, string(h))
		}
		strip["delete"] = del
	}
	good = append(good, obj{"handle": []any{obj{"handler": "headers", "request": strip}}})
	h := obj{
		"handler":   "reverse_proxy",
		"upstreams": []any{obj{"dial": dial}},
		"rewrite":   obj{"method": "GET", "uri": state.ForwardAuthPath},
		"headers": obj{"request": obj{"set": obj{
			"Host":               []string{"{http.reverse_proxy.upstream.hostport}"},
			"X-Forwarded-Method": []string{"{http.request.method}"},
			"X-Forwarded-Uri":    []string{"{http.request.uri}"},
		}}},
		"handle_response": []any{obj{
			"match":  obj{"status_code": []int{2}},
			"routes": good,
		}},
	}
	if scheme == "https" {
		tls := obj{}
		if _, err := netip.ParseAddr(host); err != nil {
			tls["server_name"] = host
		}
		h["transport"] = obj{"protocol": "http", "tls": tls}
	}
	return h
}

// acmeIssuer is an ACME issuer (Let's Encrypt unless overridden for tests),
// optionally with a DNS-01 challenge.
func (o Options) acmeIssuer(dns obj) obj {
	iss := obj{"module": "acme"}
	if o.ACMEDirectory != "" {
		iss["ca"] = o.ACMEDirectory
		if len(o.ACMERootFiles) > 0 {
			iss["trusted_roots_pem_files"] = o.ACMERootFiles
		}
	}
	if dns != nil {
		iss["challenges"] = obj{"dns": dns}
	}
	return iss
}

func secretFile(name string) string { return "{file." + SecretsDir + "/" + name + "}" }

// redisStorage is the caddy.storage.redis module (github.com/pberkel/caddy-storage-redis).
// skip_connection_check lets Caddy start (and resume) while the storage is
// unreachable; values are AES-256-GCM encrypted with the shared key.
func redisStorage(s *Storage) obj {
	st := obj{
		"module":                "redis",
		"address":               []string{s.Address},
		"key_prefix":            s.KeyPrefix,
		"password":              secretFile(SecretStoragePassword),
		"encryption_key":        secretFile(SecretStorageKey),
		"timeout":               "5",
		"skip_connection_check": true,
	}
	if s.HasUsername {
		st["username"] = secretFile(SecretStorageUser)
	}
	return st
}
