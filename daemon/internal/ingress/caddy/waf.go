package caddy

import (
	"errors"
	"fmt"
	"net/netip"
	"slices"
	"strconv"
	"strings"

	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// Web firewall (docs/22 §6.3): Coraza with the OWASP Core Rule Set, compiled
// into launchroom-caddy (module http.handlers.waf). launchroomd renders every
// directive itself from the template below, filled only from grammar-checked
// fields of RouteSpec.waf: no directive text ever comes from the dashboard.
// The directive string is the same for every route of the edge, so
// coraza-caddy's pool compiles the rule set once and shares it between all
// route handlers and across reloads.

// WafOptions turns the firewall on for routes that ask for it. Set only when
// the running Caddy carries the module (WafSupport).
type WafOptions struct {
	// LogAddress is the loopback ip:port of launchroomd's audit record
	// receiver (LR_INGRESS_WAF_LOG_ADDR, internal/waf).
	LogAddress string
}

// WafRouteHeader carries the route id from the route's subroute into the
// shared rule set. It is set right before the firewall (overwriting whatever
// a client sent) and removed right after it.
const WafRouteHeader = "X-Lr-Waf-Route"

// WafLogPath is the path Coraza posts audit records to.
const WafLogPath = "/waf"

// wafLogger is the Caddy logger name of the firewall module. Its lines carry
// client addresses, URIs and matched data, so they never reach Caddy's log.
const wafLogger = "http.handlers.waf"

// Local rule ids (docs/22 §6.3): generated rules use the fixed range
// 10000–99999, outside CRS's 900000–999999.
const (
	wafLocalRuleMin = 10000
	wafLocalRuleMax = 99999
)

// wafHead are the engine settings (docs/22 §6.3), then the request body
// processors (Coraza's recommended JSON and XML rules), then CRS's setup.
// Request bodies are inspected up to 32 KiB by default; the rest passes
// uninspected (ProcessPartial). Coraza sizes a transaction's body buffer
// from the engine limit, so ctl:requestBodyLimit can only lower it: the
// engine allows the largest setting (1 MiB), rule 10002 lowers every
// request to 32 KiB and a route's own rule raises it again.
// CRS's cost grows with the inspected fields and bytes, so the limit bounds
// the CPU one request can cost the shared edge. Multipart file contents go
// to a temporary file no rule reads: only field names, file names, sizes
// and the other fields are inspected. CRS's cost also grows with the number
// of fields, so Coraza collects at most 256 per collection (engine-wide:
// Coraza has no per-transaction setting); further fields go uninspected.
const wafHead = `SecRuleEngine On
SecRequestBodyAccess On
SecRequestBodyLimit 1048576
SecRequestBodyInMemoryLimit 131072
SecRequestBodyLimitAction ProcessPartial
SecRequestBodyJsonDepthLimit 1024
SecArgumentsLimit 256
SecResponseBodyAccess Off
SecAuditEngine RelevantOnly
SecAuditLogRelevantStatus "^403$"
SecAuditLogParts AKZ
SecAuditLogFormat json
SecAuditLogType https
SecAuditLog %s
SecRule REQUEST_HEADERS:Content-Type "^(?:application(?:/soap\+|/)|text/)xml" "id:10000,phase:1,t:none,t:lowercase,pass,nolog,ctl:requestBodyProcessor=XML"
SecRule REQUEST_HEADERS:Content-Type "^application/json" "id:10001,phase:1,t:none,t:lowercase,pass,nolog,ctl:requestBodyProcessor=JSON"
SecAction "id:10002,phase:1,pass,nolog,ctl:requestBodyLimit=32768"
Include @crs-setup.conf.example
`

// wafTail loads CRS's rules after the per-route rules.
const wafTail = "Include @owasp_crs/*.conf\n"

// wafFirstRouteRule is the first id of the per-route rules (10000 and
// 10001 are the body processors, 10002 the default body limit).
const wafFirstRouteRule = 10003

// WafRoutes returns the routes that get the firewall: those asking for it,
// except routes with clientAuth, the dashboard's system route (all upstreams
// local) and routes refused by the local policy (they answer 403 anyway).
// Sorted by route id.
func WafRoutes(routes []Route, o Options) []Route {
	var out []Route
	for _, r := range routes {
		if o.wafOn(r) {
			out = append(out, r)
		}
	}
	slices.SortFunc(out, func(a, b Route) int { return strings.Compare(string(a.Spec.ID), string(b.Spec.ID)) })
	return out
}

func (o Options) wafOn(r Route) bool {
	if o.Waf == nil || r.Spec.Waf == nil || r.Spec.ClientAuth != nil || o.localOnly(r) {
		return false
	}
	return r.Spec.Protection == nil || o.forwardAuthAllowed(r.Spec.Protection)
}

// wafDirectives renders the shared directive string for the given routes
// (WafRoutes order). Every interpolated value is re-checked against its
// grammar: a value that fails stops rendering (the manager then serves the
// routes without the firewall, docs/22 §6.6).
func wafDirectives(routes []Route, logAddress string) (string, error) {
	ap, err := netip.ParseAddrPort(logAddress)
	if err != nil || !ap.Addr().IsLoopback() || ap.Port() == 0 {
		return "", errors.New("caddy: the firewall log address must be a loopback ip:port")
	}
	var b strings.Builder
	fmt.Fprintf(&b, wafHead, "http://"+ap.String()+WafLogPath)
	id := wafFirstRouteRule
	next := func() (int, error) {
		if id > wafLocalRuleMax {
			return 0, errors.New("caddy: too many firewall rules for the local rule id range")
		}
		id++
		return id - 1, nil
	}
	for _, r := range routes {
		w := r.Spec.Waf
		if err := r.Spec.ID.Validate(); err != nil {
			return "", fmt.Errorf("caddy: firewall route: %w", err)
		}
		match := fmt.Sprintf("SecRule REQUEST_HEADERS:%s \"@streq %s\"", WafRouteHeader, r.Spec.ID)
		if err := w.Mode.Validate(); err != nil {
			return "", fmt.Errorf("caddy: route %s: %w", r.Spec.ID, err)
		}
		if w.Mode == state.WafModeLog {
			n, err := next()
			if err != nil {
				return "", err
			}
			fmt.Fprintf(&b, "%s \"id:%d,phase:1,pass,nolog,ctl:ruleEngine=DetectionOnly\"\n", match, n)
		}
		switch w.InspectBodyKiB {
		case 0, state.WafInspectBodyDefaultKiB:
		case 128, 1024:
			id, err := next()
			if err != nil {
				return "", err
			}
			fmt.Fprintf(&b, "%s \"id:%d,phase:1,pass,nolog,ctl:requestBodyLimit=%d\"\n", match, id, w.InspectBodyBytes())
		default:
			return "", fmt.Errorf("caddy: route %s: inspectBodyKiB %d is not 32, 128 or 1024", r.Spec.ID, w.InspectBodyKiB)
		}
		switch w.Paranoia {
		case 0, 1:
		case 2:
			n, err := next()
			if err != nil {
				return "", err
			}
			fmt.Fprintf(&b, "%s \"id:%d,phase:1,pass,nolog,setvar:tx.blocking_paranoia_level=2\"\n", match, n)
		default:
			return "", fmt.Errorf("caddy: route %s: paranoia %d is not 1 or 2", r.Spec.ID, w.Paranoia)
		}
		for _, x := range w.Exclusions {
			if x.Rule.Validate() != nil || x.Rule < state.WafExcludableMin || x.Rule > state.WafExcludableMax {
				return "", fmt.Errorf("caddy: route %s: rule %d can't be excluded", r.Spec.ID, x.Rule)
			}
			if x.Path != "" && x.Path.Validate() != nil || x.Target != "" && x.Target.Validate() != nil {
				return "", fmt.Errorf("caddy: route %s: invalid exclusion of rule %d", r.Spec.ID, x.Rule)
			}
			ctl := "ctl:ruleRemoveById=" + strconv.Itoa(int(x.Rule))
			if x.Target != "" {
				ctl = "ctl:ruleRemoveTargetById=" + strconv.Itoa(int(x.Rule)) + ";" + string(x.Target)
			}
			n, err := next()
			if err != nil {
				return "", err
			}
			if x.Path == "" {
				fmt.Fprintf(&b, "%s \"id:%d,phase:1,pass,nolog,%s\"\n", match, n, ctl)
				continue
			}
			fmt.Fprintf(&b, "%s \"id:%d,phase:1,pass,nolog,chain\"\n", match, n)
			fmt.Fprintf(&b, "  SecRule REQUEST_FILENAME \"@beginsWith %s\" \"%s\"\n", x.Path, ctl)
		}
	}
	b.WriteString(wafTail)
	return b.String(), nil
}

// wafHandlers are the subroute entries of a firewalled route: the route
// header, the firewall with the shared directives, the header removed again.
func wafHandlers(id state.RouteID, directives string) []any {
	return []any{
		obj{"handle": []any{obj{"handler": "headers", "request": obj{"set": obj{WafRouteHeader: []string{string(id)}}}}}},
		obj{"handle": []any{obj{"handler": "waf", "load_owasp_crs": true, "directives": directives}}},
		obj{"handle": []any{obj{"handler": "headers", "request": obj{"delete": []string{WafRouteHeader}}}}},
	}
}

// pageBlocked answers a request the firewall refused (403), with Coraza's
// transaction id as the reference (docs/22 §6.3).
var pageBlocked = errorPage{
	title:     "This request was blocked by the web firewall",
	text:      "It looked like an attack on this app. If it was a normal request, send the reference below to the app's owner.",
	plain:     "launchroom: this request was blocked by the web firewall. Reference: " + wafReferencePlaceholder + "\n",
	host:      true,
	reference: true,
}

// wafReferencePlaceholder is the firewall's transaction id of a blocked
// request (coraza-caddy returns it as the handler error's id).
const wafReferencePlaceholder = "{http.error.id}"

// wafErrorRoute answers 403 errors of firewalled hosts with pageBlocked.
func wafErrorRoute(hosts []string) obj {
	return obj{
		"match":    []any{obj{"host": hosts, "vars": obj{errorStatusPlaceholder: []string{"403"}}}},
		"handle":   []any{pageBlocked.handler(403)},
		"terminal": true,
	}
}

// WafSupport is what the running Caddy image says about its firewall
// module: launchroom-caddy carries the versions as image labels (deploy/
// images/caddy), and since the image is digest-pinned (docs/09 R31) the
// labels are as trustworthy as the binary. Zero value: no module.
type WafSupport struct {
	Engine, RuleSet state.WafLabel
}

// Image labels of launchroom-caddy naming the firewall versions.
const (
	LabelWafEngine  = "io.vantezzen.launchroom.waf.engine"
	LabelWafRuleSet = "io.vantezzen.launchroom.waf.ruleset"
)

// WafSupportOf reads the labels of a Caddy container (image labels are
// merged into container labels). Labels failing their grammar count as no
// module.
func WafSupportOf(labels map[string]string) WafSupport {
	e, r := state.WafLabel(labels[LabelWafEngine]), state.WafLabel(labels[LabelWafRuleSet])
	if e.Validate() != nil || r.Validate() != nil {
		return WafSupport{}
	}
	return WafSupport{Engine: e, RuleSet: r}
}

// Available reports whether the module is there.
func (s WafSupport) Available() bool { return s.Engine != "" }
