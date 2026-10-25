package waf

import (
	"encoding/json"
	"net/netip"
	"regexp"
	"slices"
	"strconv"
	"strings"
	"time"

	"github.com/vantezzen/launchroom/daemon/internal/state"
	"github.com/vantezzen/launchroom/daemon/internal/traffic"
)

// record is the part of Coraza's JSON audit record launchroomd reads
// (docs/22 §6.5). Everything else in the record (arguments, the query
// string, matched data, rule text) is skipped by the decoder and never
// leaves memory.
type record struct {
	Transaction struct {
		UnixTimestamp int64  `json:"unix_timestamp"` // nanoseconds
		ID            string `json:"id"`
		ClientIP      string `json:"client_ip"`
		ServerID      string `json:"server_id"` // the request's host
		Request       *struct {
			Method string `json:"method"`
			URI    string `json:"uri"`
		} `json:"request"`
		IsInterrupted bool `json:"is_interrupted"`
	} `json:"transaction"`
	Messages []struct {
		Data *struct {
			ID   int    `json:"id"`
			Msg  string `json:"msg"`
			Data string `json:"data"`
		} `json:"data"`
	} `json:"messages"`
}

// Evaluation rules of CRS: they decide blocking from the anomaly score.
const (
	ruleInboundEvaluation  = 949110
	ruleOutboundEvaluation = 959100
)

// Limits of one event (state's own limits).
const (
	maxEventRules   = 8
	maxEventTargets = 8
)

// event is one decoded record that reached the anomaly threshold. It holds
// only the client's network, never its address.
type event struct {
	at        time.Time
	host      string
	action    state.WafAction
	method    state.WafMethod // "" when outside the grammar
	path      state.RequestPath
	pathOK    bool
	rules     []state.WafRuleID // detection rules, in record order, unique
	targets   []state.WafMatchTarget
	network   state.ClientNetwork // "" when the address didn't parse
	score     int
	reference state.WafReference // "" when outside the grammar
}

var (
	reTotalScore = regexp.MustCompile(`Total Score: ([0-9]{1,5})\)`)
	reVariable   = regexp.MustCompile(`^[A-Z][A-Z_]{0,39}$`)
)

// decode parses one audit record. ok is false for records that don't
// count: undecodable, or a transaction below the anomaly threshold (not
// interrupted and no evaluation rule matched, e.g. an app's own 403).
func decode(body []byte, now time.Time) (event, bool) {
	var r record
	if json.Unmarshal(body, &r) != nil {
		return event{}, false
	}
	// Cut the client address to its network right away (docs/22 §6.5).
	network := clientNetwork(r.Transaction.ClientIP)
	r.Transaction.ClientIP = ""

	ev := event{network: network, host: r.Transaction.ServerID}
	evaluated := false
	seenRule := map[state.WafRuleID]bool{}
	seenTarget := map[state.WafMatchTarget]bool{}
	for _, m := range r.Messages {
		d := m.Data
		if d == nil {
			continue
		}
		switch d.ID {
		case ruleInboundEvaluation, ruleOutboundEvaluation:
			evaluated = true
			if s := reTotalScore.FindStringSubmatch(d.Msg); s != nil {
				n, _ := strconv.Atoi(s[1])
				ev.score = max(ev.score, min(n, 10000))
			}
			continue
		}
		id := state.WafRuleID(d.ID)
		if !detectionRule(id) {
			continue
		}
		if !seenRule[id] {
			seenRule[id] = true
			ev.rules = append(ev.rules, id)
		}
		if t, ok := matchTarget(d.Data); ok && !seenTarget[t] && len(ev.targets) < maxEventTargets {
			seenTarget[t] = true
			ev.targets = append(ev.targets, t)
		}
	}
	switch {
	case r.Transaction.IsInterrupted:
		ev.action = state.WafBlocked
	case evaluated:
		ev.action = state.WafDetected
	default:
		return event{}, false
	}
	ev.at = now
	if ts := r.Transaction.UnixTimestamp; ts > 0 {
		// The record's own time, unless the clocks disagree (then: now).
		if t := time.Unix(0, ts).UTC(); t.After(now.Add(-time.Minute)) && t.Before(now.Add(time.Minute)) {
			ev.at = t
		}
	}
	ev.at = ev.at.UTC().Truncate(time.Second)
	if req := r.Transaction.Request; req != nil {
		if m := state.WafMethod(req.Method); m.Validate() == nil {
			ev.method = m
		}
		ev.path, ev.pathOK = traffic.NormalizePath(req.URI)
		if ev.pathOK && ev.path.Validate() != nil {
			ev.pathOK = false
		}
	}
	if ref := state.WafReference(r.Transaction.ID); ref.Validate() == nil {
		ev.reference = ref
	}
	return ev, true
}

// detectionRule reports whether a matched rule counts: a CRS rule, except
// initialisation (901), evaluation (949, 959) and correlation (980) rules.
func detectionRule(id state.WafRuleID) bool {
	if id.Validate() != nil {
		return false
	}
	switch int(id) / 1000 {
	case 901, 949, 959, 980:
		return false
	}
	return true
}

// matchTarget reads the matched variable from CRS's log data ("Matched
// Data: … found within ARGS:q: …"): the variable and key name only. A key
// outside the grammar is left out (variable only); the matched data and
// value around it are never kept.
func matchTarget(data string) (state.WafMatchTarget, bool) {
	_, rest, ok := strings.Cut(data, " found within ")
	if !ok {
		return "", false
	}
	name, _, ok := strings.Cut(rest, ": ")
	if !ok {
		return "", false
	}
	if t := state.WafMatchTarget(name); t.Validate() == nil {
		return t, true
	}
	variable, _, _ := strings.Cut(name, ":")
	if reVariable.MatchString(variable) {
		return state.WafMatchTarget(variable), true
	}
	return "", false
}

// clientNetwork cuts an address to its /24 or /48 network ("" when it
// doesn't parse).
func clientNetwork(s string) state.ClientNetwork {
	a, err := netip.ParseAddr(strings.TrimSpace(s))
	if err != nil {
		return ""
	}
	n := state.ClientNetworkOf(a)
	if n.Validate() != nil {
		return ""
	}
	return n
}

// categories are the attack categories of rules, unique, in rule order.
func categories(rules []state.WafRuleID) []state.WafCategory {
	var out []state.WafCategory
	for _, r := range rules {
		c := state.WafCategoryOf(r)
		if !slices.Contains(out, c) {
			out = append(out, c)
		}
	}
	if len(out) == 0 {
		out = []state.WafCategory{state.WafCategoryOther}
	}
	return out
}
