package state

import (
	"fmt"
	"net/netip"
	"regexp"
	"time"
)

// Web firewall (docs/22 §6, docs/03 §13.3–13.4): Coraza with the OWASP Core
// Rule Set inside launchroom's edge Caddy. The dashboard sends typed
// settings per route (RouteSpec.waf); launchroomd renders every directive
// itself from a template compiled into its binary, so no directive text
// crosses the protocol. Edges report the firewall's state
// (IngressStatus.waf) and per-route, per-10-minute aggregates of blocked and
// detected requests (ReportMetrics.waf). Daemon feature "waf".

// Limits of firewall documents.
const (
	// WafWindow is the aggregation window of one sample.
	WafWindow           = 10 * time.Minute
	MaxWafExclusions    = 50
	MaxWafSamples       = 1024
	MaxWafRuleCounts    = 20
	MaxWafPathCounts    = 10
	MaxWafEvents        = 20
	maxWafEventRules    = 8
	maxWafEventTargets  = 8
	maxWafCategories    = 13
	maxWafScore         = 10000
	maxWafStatusMessage = 1024
	// WafRuleMin and WafRuleMax bound the rule ids an event may name
	// (CRS's own range).
	WafRuleMin = 900000
	WafRuleMax = 999999
	// WafExcludableMin and WafExcludableMax bound the rules an exclusion may
	// remove: the detection rules 911xxx–944xxx, never initialisation (901),
	// common exceptions (905) or evaluation (949, 959, 980).
	WafExcludableMin = 911000
	WafExcludableMax = 944999
)

var (
	reWafPathPrefix = regexp.MustCompile(`^/[A-Za-z0-9._~/-]{0,127}$`)
	reWafTarget     = regexp.MustCompile(`^(?:(?:ARGS|REQUEST_COOKIES|REQUEST_HEADERS):[A-Za-z0-9_.-]{1,64}|ARGS_NAMES|REQUEST_COOKIES_NAMES|REQUEST_BODY|XML|FILES)$`)
	reWafMatch      = regexp.MustCompile(`^[A-Z][A-Z_]{0,39}(?::[A-Za-z0-9_.\[\]-]{1,64})?$`)
	reWafMethod     = regexp.MustCompile(`^[A-Z]{1,16}$`)
	reWafReference  = regexp.MustCompile(`^[A-Za-z0-9_.-]{1,128}$`)
	reClientNetwork = regexp.MustCompile(`^(?:[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}\.0/24|[0-9a-f:]{2,39}/48)$`)
	reWafLabel      = regexp.MustCompile(`^[A-Za-z0-9][A-Za-z0-9 ._/+-]{0,63}$`)
	wafModes        = []string{string(WafModeBlock), string(WafModeLog)}
	wafStates       = []string{string(WafStateOn), string(WafStateOff), string(WafStateError), string(WafStateUnsupported)}
	wafActions      = []string{string(WafBlocked), string(WafDetected)}
	wafCategories   = []string{
		string(WafCategoryMethod), string(WafCategoryScanner), string(WafCategoryProtocol), string(WafCategoryLFI), string(WafCategoryRFI), string(WafCategoryRCE),
		string(WafCategoryPHP), string(WafCategoryGeneric), string(WafCategoryXSS), string(WafCategorySQLi), string(WafCategorySessionFixation),
		string(WafCategoryJava), string(WafCategoryOther),
	}
)

// ---- NodeState: RouteSpec.waf ----

// RouteWaf turns the web firewall on for a route. Omitted: off. Only sent
// to edges announcing the daemon feature "waf".
type RouteWaf struct {
	// Mode block refuses requests that reach CRS's anomaly threshold (403);
	// log only reports them.
	Mode WafMode `json:"mode"`
	// Paranoia is CRS's blocking paranoia level, 1 (default when omitted) or 2.
	Paranoia int `json:"paranoia,omitzero" jsonschema:"minimum=1,maximum=2"`
	// Exclusions skip a rule for this route, optionally only below a path
	// prefix and only for one part of the request (false positives).
	Exclusions []WafExclusion `json:"exclusions,omitzero" jsonschema:"nullable,maxItems=50"`
	// InspectBodyKiB is how much of a request body the firewall inspects:
	// 32 (default when omitted), 128 or 1024 KiB. The rest of a larger body
	// passes uninspected; it is never refused for its size (docs/22 §6.3).
	InspectBodyKiB int `json:"inspectBodyKiB,omitzero" jsonschema:"enum=32,enum=128,enum=1024"`
}

// Request body inspection sizes (RouteWaf.inspectBodyKiB).
const (
	WafInspectBodyDefaultKiB = 32
	wafInspectBodyKiB128     = 128
	wafInspectBodyKiB1024    = 1024
)

// InspectBodyBytes is the route's request body inspection limit in bytes.
func (w *RouteWaf) InspectBodyBytes() int64 {
	if w.InspectBodyKiB == 0 {
		return WafInspectBodyDefaultKiB << 10
	}
	return int64(w.InspectBodyKiB) << 10
}

// WafExclusion skips one CRS rule for a route.
type WafExclusion struct {
	// Rule is a CRS detection rule id (911000–944999).
	Rule WafRuleID `json:"rule"`
	// Path limits the exclusion to request paths starting with it.
	Path WafPathPrefix `json:"path,omitzero"`
	// Target limits the exclusion to one part of the request
	// ("ARGS:q", "REQUEST_COOKIES:session", "REQUEST_BODY", …).
	Target WafTarget `json:"target,omitzero"`
}

func (w *RouteWaf) validate(e *errs, p string) {
	e.check(p+".mode", w.Mode)
	if w.Paranoia != 0 && (w.Paranoia < 1 || w.Paranoia > 2) {
		e.addf(p+".paranoia", "must be 1 or 2")
	}
	switch w.InspectBodyKiB {
	case 0, WafInspectBodyDefaultKiB, wafInspectBodyKiB128, wafInspectBodyKiB1024:
	default:
		e.addf(p+".inspectBodyKiB", "must be 32, 128 or 1024")
	}
	checkCount(e, p+".exclusions", len(w.Exclusions), 0, MaxWafExclusions)
	for i, x := range w.Exclusions {
		q := idx(p+".exclusions", i)
		e.check(q+".rule", x.Rule)
		if x.Rule.Validate() == nil && (x.Rule < WafExcludableMin || x.Rule > WafExcludableMax) {
			e.addf(q+".rule", "only detection rules %d–%d can be excluded", WafExcludableMin, WafExcludableMax)
		}
		if x.Path != "" {
			e.check(q+".path", x.Path)
		}
		if x.Target != "" {
			e.check(q+".target", x.Target)
		}
	}
	unique(e, p+".exclusions", w.Exclusions)
}

// ---- Report: IngressStatus.waf ----

// WafStatus is the firewall's state at this edge.
type WafStatus struct {
	State WafState `json:"state"`
	// Engine and RuleSet name the compiled-in versions ("coraza 3.8.1",
	// "OWASP CRS 4.25.0"); omitted with state unsupported.
	Engine  WafLabel `json:"engine,omitzero"`
	RuleSet WafLabel `json:"ruleSet,omitzero"`
	// Routes is the number of routes with the firewall in the applied config.
	Routes int `json:"routes" jsonschema:"minimum=0"`
	// DroppedRecords counts audit records launchroomd could not queue since it started.
	DroppedRecords int64 `json:"droppedRecords,omitzero" jsonschema:"minimum=0"`
	// Message explains error and unsupported (secret-free).
	Message string `json:"message,omitzero" jsonschema:"maxLength=1024"`
}

func (s *WafStatus) validate(e *errs, p string) {
	e.check(p+".state", s.State)
	if s.Engine != "" {
		e.check(p+".engine", s.Engine)
	}
	if s.RuleSet != "" {
		e.check(p+".ruleSet", s.RuleSet)
	}
	if s.State != WafStateUnsupported && (s.Engine == "" || s.RuleSet == "") {
		e.addf(p, "engine and ruleSet are required unless unsupported")
	}
	if s.Routes < 0 {
		e.addf(p+".routes", "must be >= 0")
	}
	if s.State == WafStateOn && s.Routes == 0 || s.State != WafStateOn && s.Routes != 0 {
		e.addf(p+".routes", "must be > 0 exactly when the state is on")
	}
	if s.DroppedRecords < 0 {
		e.addf(p+".droppedRecords", "must be >= 0")
	}
	checkPlainText(e, p+".message", s.Message, maxWafStatusMessage)
}

// ---- Report: ReportMetrics.waf ----

// WafSample is one 10-minute window of one route's firewall results at this
// edge. Only requests that reached CRS's anomaly threshold count.
type WafSample struct {
	// Route is the NodeState route the request's host belongs to.
	Route RouteID `json:"route"`
	// At is the start of the window (UTC, a multiple of 10 minutes).
	At time.Time `json:"at"`
	// Blocked requests were refused (mode block); Detected requests would
	// have been (mode log). Together at least 1.
	Blocked  int64 `json:"blocked,omitzero" jsonschema:"minimum=0"`
	Detected int64 `json:"detected,omitzero" jsonschema:"minimum=0"`
	// Categories count requests per attack category; a request with rules of
	// several categories counts in each.
	Categories []WafCategoryCount `json:"categories" jsonschema:"minItems=1,maxItems=13"`
	// Rules are the most matched detection rules (requests per rule), ≤ 20.
	Rules []WafRuleCount `json:"rules,omitzero" jsonschema:"nullable,maxItems=20"`
	// Paths are the most affected normalised paths, ≤ 10; with OtherPaths
	// they add up to Blocked and Detected.
	Paths      []WafPathCount `json:"paths,omitzero" jsonschema:"nullable,maxItems=10"`
	OtherPaths WafCounts      `json:"otherPaths,omitzero"`
	// Events are individual requests of the window, ≤ 20 (docs/22 §6.5).
	Events []WafEvent `json:"events,omitzero" jsonschema:"nullable,maxItems=20"`
}

// WafCounts are blocked and detected requests.
type WafCounts struct {
	Blocked  int64 `json:"blocked,omitzero" jsonschema:"minimum=0"`
	Detected int64 `json:"detected,omitzero" jsonschema:"minimum=0"`
}

// WafCategoryCount is one category's requests in a window.
type WafCategoryCount struct {
	Category WafCategory `json:"category"`
	Blocked  int64       `json:"blocked,omitzero" jsonschema:"minimum=0"`
	Detected int64       `json:"detected,omitzero" jsonschema:"minimum=0"`
}

// WafRuleCount is one rule's requests in a window.
type WafRuleCount struct {
	Rule     WafRuleID `json:"rule"`
	Blocked  int64     `json:"blocked,omitzero" jsonschema:"minimum=0"`
	Detected int64     `json:"detected,omitzero" jsonschema:"minimum=0"`
}

// WafPathCount is one normalised path's requests in a window.
type WafPathCount struct {
	Path     RequestPath `json:"path"`
	Blocked  int64       `json:"blocked,omitzero" jsonschema:"minimum=0"`
	Detected int64       `json:"detected,omitzero" jsonschema:"minimum=0"`
}

// WafEvent is one request that reached the anomaly threshold. It carries
// no query string, header, cookie or body value and no matched data.
type WafEvent struct {
	At     time.Time `json:"at"`
	Action WafAction `json:"action"`
	Method WafMethod `json:"method"`
	// Path is the normalised request path (analytics rules, docs/15 §5.1).
	Path RequestPath `json:"path"`
	// Rules are the matched detection rules, 1–8.
	Rules      []WafRuleID   `json:"rules" jsonschema:"minItems=1,maxItems=8"`
	Categories []WafCategory `json:"categories" jsonschema:"minItems=1,maxItems=13"`
	// Targets are the matched parts of the request: variable and key name
	// only ("ARGS:q", "REQUEST_HEADERS:User-Agent"), ≤ 8.
	Targets []WafMatchTarget `json:"targets,omitzero" jsonschema:"nullable,maxItems=8"`
	// ClientNetwork is the network of the TCP peer the edge saw (a proxy's
	// behind a proxy), cut on the edge to /24 (IPv4) or /48 (IPv6). The full
	// address never leaves launchroomd.
	ClientNetwork ClientNetwork `json:"clientNetwork"`
	// Score is CRS's inbound (or outbound) anomaly score.
	Score int `json:"score,omitzero" jsonschema:"minimum=0,maximum=10000"`
	// Reference is the transaction id shown on the 403 page.
	Reference WafReference `json:"reference"`
}

func (c WafCounts) validate(e *errs, p string) {
	checkNonNegative(e, p, map[string]*int64{"blocked": &c.Blocked, "detected": &c.Detected})
}

func validateWafSamples(e *errs, p string, samples []WafSample) {
	checkCount(e, p, len(samples), 0, MaxWafSamples)
	type key struct {
		r  RouteID
		at int64
	}
	keys := make([]key, 0, len(samples))
	for i, s := range samples {
		q := idx(p, i)
		e.check(q+".route", s.Route)
		switch {
		case s.At.IsZero():
			e.addf(q+".at", "is required")
		case !s.At.Equal(s.At.Truncate(WafWindow)):
			e.addf(q+".at", "must be a multiple of 10 minutes")
		}
		keys = append(keys, key{s.Route, s.At.Unix()})
		total := WafCounts{Blocked: s.Blocked, Detected: s.Detected}
		total.validate(e, q)
		if s.Blocked+s.Detected < 1 {
			e.addf(q, "blocked plus detected must be >= 1")
		}
		checkCount(e, q+".categories", len(s.Categories), 1, maxWafCategories)
		cats := make([]WafCategory, 0, len(s.Categories))
		for j, c := range s.Categories {
			cq := idx(q+".categories", j)
			e.check(cq+".category", c.Category)
			WafCounts{c.Blocked, c.Detected}.validate(e, cq)
			if c.Blocked+c.Detected < 1 || c.Blocked > s.Blocked || c.Detected > s.Detected {
				e.addf(cq, "counts must be >= 1 in total and within the sample's")
			}
			cats = append(cats, c.Category)
		}
		unique(e, q+".categories[].category", cats)
		checkCount(e, q+".rules", len(s.Rules), 0, MaxWafRuleCounts)
		rules := make([]WafRuleID, 0, len(s.Rules))
		for j, r := range s.Rules {
			rq := idx(q+".rules", j)
			e.check(rq+".rule", r.Rule)
			WafCounts{r.Blocked, r.Detected}.validate(e, rq)
			if r.Blocked+r.Detected < 1 || r.Blocked > s.Blocked || r.Detected > s.Detected {
				e.addf(rq, "counts must be >= 1 in total and within the sample's")
			}
			rules = append(rules, r.Rule)
		}
		unique(e, q+".rules[].rule", rules)
		checkCount(e, q+".paths", len(s.Paths), 0, MaxWafPathCounts)
		paths := make([]RequestPath, 0, len(s.Paths))
		sum := s.OtherPaths
		s.OtherPaths.validate(e, q+".otherPaths")
		for j, pc := range s.Paths {
			pq := idx(q+".paths", j)
			e.check(pq+".path", pc.Path)
			WafCounts{pc.Blocked, pc.Detected}.validate(e, pq)
			if pc.Blocked+pc.Detected < 1 {
				e.addf(pq, "counts must be >= 1 in total")
			}
			sum.Blocked += pc.Blocked
			sum.Detected += pc.Detected
			paths = append(paths, pc.Path)
		}
		unique(e, q+".paths[].path", paths)
		if len(s.Paths) > 0 || s.OtherPaths != (WafCounts{}) {
			if sum.Blocked != s.Blocked || sum.Detected != s.Detected {
				e.addf(q+".paths", "path counts must add up to blocked and detected")
			}
		}
		checkCount(e, q+".events", len(s.Events), 0, MaxWafEvents)
		var evBlocked, evDetected int64
		for j, ev := range s.Events {
			eq := idx(q+".events", j)
			ev.validate(e, eq)
			if !ev.At.IsZero() && (ev.At.Before(s.At) || !ev.At.Before(s.At.Add(WafWindow))) {
				e.addf(eq+".at", "must be inside the sample's window")
			}
			if ev.Action == WafBlocked {
				evBlocked++
			} else {
				evDetected++
			}
		}
		if evBlocked > s.Blocked || evDetected > s.Detected {
			e.addf(q+".events", "more events than counted requests")
		}
	}
	unique(e, p+"[].(route,at)", keys)
}

func (ev WafEvent) validate(e *errs, p string) {
	if ev.At.IsZero() {
		e.addf(p+".at", "is required")
	}
	e.check(p+".action", ev.Action)
	e.check(p+".method", ev.Method)
	e.check(p+".path", ev.Path)
	checkCount(e, p+".rules", len(ev.Rules), 1, maxWafEventRules)
	for i, r := range ev.Rules {
		e.check(idx(p+".rules", i), r)
	}
	unique(e, p+".rules", ev.Rules)
	checkCount(e, p+".categories", len(ev.Categories), 1, maxWafCategories)
	for i, c := range ev.Categories {
		e.check(idx(p+".categories", i), c)
	}
	unique(e, p+".categories", ev.Categories)
	checkCount(e, p+".targets", len(ev.Targets), 0, maxWafEventTargets)
	for i, t := range ev.Targets {
		e.check(idx(p+".targets", i), t)
	}
	unique(e, p+".targets", ev.Targets)
	e.check(p+".clientNetwork", ev.ClientNetwork)
	if ev.Score < 0 || ev.Score > maxWafScore {
		e.addf(p+".score", "must be between 0 and %d", maxWafScore)
	}
	e.check(p+".reference", ev.Reference)
}

// ---- Scalars and enums ----

// WafMode is how a route's firewall acts.
type WafMode string

// Modes (off = RouteSpec.waf omitted).
const (
	WafModeBlock WafMode = "block"
	WafModeLog   WafMode = "log"
)

// ProtocolSchema implements SchemaDescriber.
func (WafMode) ProtocolSchema() ScalarSchema { return enumSchema(wafModes) }

// Validate checks the value.
func (v WafMode) Validate() error { return checkEnum("waf mode", string(v), wafModes) }

// WafState is the firewall's state at an edge.
type WafState string

// States.
const (
	// WafStateOn: at least one route runs the firewall.
	WafStateOn WafState = "on"
	// WafStateOff: no route asks for it.
	WafStateOff WafState = "off"
	// WafStateError: Caddy refused the config with the firewall; routes are
	// served without it (docs/22 §6.6).
	WafStateError WafState = "error"
	// WafStateUnsupported: the pinned Caddy has no firewall module.
	WafStateUnsupported WafState = "unsupported"
)

// ProtocolSchema implements SchemaDescriber.
func (WafState) ProtocolSchema() ScalarSchema { return enumSchema(wafStates) }

// Validate checks the value.
func (v WafState) Validate() error { return checkEnum("waf state", string(v), wafStates) }

// WafAction is what happened to a request.
type WafAction string

// Actions.
const (
	WafBlocked  WafAction = "blocked"
	WafDetected WafAction = "detected"
)

// ProtocolSchema implements SchemaDescriber.
func (WafAction) ProtocolSchema() ScalarSchema { return enumSchema(wafActions) }

// Validate checks the value.
func (v WafAction) Validate() error { return checkEnum("waf action", string(v), wafActions) }

// WafCategory is an attack category derived from the CRS rule id.
type WafCategory string

// Categories (rule id prefix in parentheses).
const (
	WafCategoryMethod          WafCategory = "method"           // 911
	WafCategoryScanner         WafCategory = "scanner"          // 913
	WafCategoryProtocol        WafCategory = "protocol"         // 920, 921, 922
	WafCategoryLFI             WafCategory = "lfi"              // 930
	WafCategoryRFI             WafCategory = "rfi"              // 931
	WafCategoryRCE             WafCategory = "rce"              // 932
	WafCategoryPHP             WafCategory = "php"              // 933
	WafCategoryGeneric         WafCategory = "generic"          // 934
	WafCategoryXSS             WafCategory = "xss"              // 941
	WafCategorySQLi            WafCategory = "sqli"             // 942
	WafCategorySessionFixation WafCategory = "session_fixation" // 943
	WafCategoryJava            WafCategory = "java"             // 944
	WafCategoryOther           WafCategory = "other"
)

// WafCategoryOf maps a CRS rule id to its category.
func WafCategoryOf(rule WafRuleID) WafCategory {
	switch int(rule) / 1000 {
	case 911:
		return WafCategoryMethod
	case 913:
		return WafCategoryScanner
	case 920, 921, 922:
		return WafCategoryProtocol
	case 930:
		return WafCategoryLFI
	case 931:
		return WafCategoryRFI
	case 932:
		return WafCategoryRCE
	case 933:
		return WafCategoryPHP
	case 934:
		return WafCategoryGeneric
	case 941:
		return WafCategoryXSS
	case 942:
		return WafCategorySQLi
	case 943:
		return WafCategorySessionFixation
	case 944:
		return WafCategoryJava
	default:
		return WafCategoryOther
	}
}

// ProtocolSchema implements SchemaDescriber.
func (WafCategory) ProtocolSchema() ScalarSchema { return enumSchema(wafCategories) }

// Validate checks the value.
func (v WafCategory) Validate() error { return checkEnum("waf category", string(v), wafCategories) }

// WafRuleID is a CRS rule id (900000–999999).
type WafRuleID int

// ProtocolSchema implements SchemaDescriber.
func (WafRuleID) ProtocolSchema() ScalarSchema {
	return ScalarSchema{Type: "integer", Minimum: ptr[int64](WafRuleMin), Maximum: ptr[int64](WafRuleMax)}
}

// Validate checks the range.
func (v WafRuleID) Validate() error {
	if v < WafRuleMin || v > WafRuleMax {
		return fmt.Errorf("waf rule %d is outside %d-%d", v, WafRuleMin, WafRuleMax)
	}
	return nil
}

// WafPathPrefix is the path prefix of an exclusion.
type WafPathPrefix string

// ProtocolSchema implements SchemaDescriber.
func (WafPathPrefix) ProtocolSchema() ScalarSchema { return strSchema(reWafPathPrefix, 128) }

// Validate checks the grammar.
func (v WafPathPrefix) Validate() error {
	return checkPattern("waf path prefix", string(v), reWafPathPrefix, 128)
}

// WafTarget is the request part an exclusion skips.
type WafTarget string

// ProtocolSchema implements SchemaDescriber.
func (WafTarget) ProtocolSchema() ScalarSchema { return strSchema(reWafTarget, 96) }

// Validate checks the grammar.
func (v WafTarget) Validate() error { return checkPattern("waf target", string(v), reWafTarget, 96) }

// WafMatchTarget is a matched request part of an event: a Coraza variable
// and, when it has one, the key name ("ARGS:q"). Key names outside the
// grammar are left out by launchroomd (variable only).
type WafMatchTarget string

// ProtocolSchema implements SchemaDescriber.
func (WafMatchTarget) ProtocolSchema() ScalarSchema { return strSchema(reWafMatch, 105) }

// Validate checks the grammar.
func (v WafMatchTarget) Validate() error {
	return checkPattern("waf match target", string(v), reWafMatch, 105)
}

// WafMethod is an HTTP method as the request named it (uppercase letters).
type WafMethod string

// ProtocolSchema implements SchemaDescriber.
func (WafMethod) ProtocolSchema() ScalarSchema { return strSchema(reWafMethod, 16) }

// Validate checks the grammar.
func (v WafMethod) Validate() error { return checkPattern("http method", string(v), reWafMethod, 16) }

// WafReference is the firewall's transaction id of a request.
type WafReference string

// ProtocolSchema implements SchemaDescriber.
func (WafReference) ProtocolSchema() ScalarSchema { return strSchema(reWafReference, 128) }

// Validate checks the grammar.
func (v WafReference) Validate() error {
	return checkPattern("waf reference", string(v), reWafReference, 128)
}

// WafLabel names a compiled-in version ("coraza 3.8.1").
type WafLabel string

// ProtocolSchema implements SchemaDescriber.
func (WafLabel) ProtocolSchema() ScalarSchema { return strSchema(reWafLabel, 64) }

// Validate checks the grammar.
func (v WafLabel) Validate() error { return checkPattern("waf label", string(v), reWafLabel, 64) }

// ClientNetwork is a client's network as the firewall reports it: the
// canonical IPv4 /24 ("203.0.113.0/24") or IPv6 /48 ("2001:db8:17::/48")
// prefix of its address (docs/22 §6.5). launchroomd cuts the address to
// this prefix before an event leaves the edge.
type ClientNetwork string

// ProtocolSchema implements SchemaDescriber.
func (ClientNetwork) ProtocolSchema() ScalarSchema { return strSchema(reClientNetwork, 43) }

// Validate checks the grammar and that the prefix is canonical (host bits
// zero), /24 for IPv4 and /48 for IPv6.
func (v ClientNetwork) Validate() error {
	if err := checkPattern("client network", string(v), reClientNetwork, 43); err != nil {
		return err
	}
	p, err := netip.ParsePrefix(string(v))
	if err != nil || p.Masked() != p || p.Addr().Zone() != "" || p.Addr().Is4In6() || p.String() != string(v) ||
		p.Addr().Is4() && p.Bits() != 24 || p.Addr().Is6() && p.Bits() != 48 {
		return fmt.Errorf("client network %q is not a canonical /24 (IPv4) or /48 (IPv6) prefix", truncate(string(v)))
	}
	return nil
}

// ClientNetworkOf cuts an address to its ClientNetwork (/24 or /48).
func ClientNetworkOf(a netip.Addr) ClientNetwork {
	a = a.Unmap().WithZone("")
	bits := 48
	if a.Is4() {
		bits = 24
	}
	p, _ := a.Prefix(bits)
	return ClientNetwork(p.String())
}
