package lrtrace

import (
	"errors"
	"slices"
	"strconv"
	"sync"
	"sync/atomic"

	"github.com/corazawaf/coraza/v3/experimental/plugins/plugintypes"
	"github.com/corazawaf/coraza/v3/types"

	"github.com/vantezzen/launchroom/deploy/images/caddy/lrtrace/format"
)

// ActionName is the Coraza action. launchroomd renders it after the CRS
// include as SecActions 99997–99999 in phases 2, 3 and 5 (docs/23 §3).
const ActionName = "lrtrace"

// CRS evaluation rules: they decide blocking from the anomaly score.
const (
	ruleInboundEvaluation  = 949110
	ruleOutboundEvaluation = 959100
)

// slots finds a request's state by Coraza's transaction id, which is the
// request id (tx_id_req_header). Bounded, so a flood can't grow it: past
// the bound requests go without firewall details.
var slots slotMap

const maxSlots = 1 << 17

type slotMap struct {
	m sync.Map
	n atomic.Int64
}

// add registers st and returns its removal.
func (s *slotMap) add(id string, st *reqState) func() {
	if s.n.Add(1) > maxSlots {
		s.n.Add(-1)
		return func() {}
	}
	s.m.Store(id, st)
	return func() {
		s.m.Delete(id)
		s.n.Add(-1)
	}
}

func (s *slotMap) get(id string) *reqState {
	v, ok := s.m.Load(id)
	if !ok {
		return nil
	}
	st, _ := v.(*reqState)
	return st
}

// action copies what the firewall decided so far into the request's state.
// The transaction is pooled once the waf handler returns, so it copies
// values and never keeps the transaction.
type action struct{}

func newAction() plugintypes.Action { return action{} }

func (action) Init(_ plugintypes.RuleMetadata, data string) error {
	if data != "" {
		return errActionArgs
	}
	return nil
}

func (action) Type() plugintypes.ActionType { return plugintypes.ActionTypeNondisruptive }

// txView is the part of Coraza's transaction the action reads (the
// concrete transaction has these methods; TransactionState doesn't list them).
type txView interface {
	MatchedRules() []types.MatchedRule
	Interruption() *types.Interruption
	DetectionOnlyInterruption() *types.Interruption
}

func (action) Evaluate(_ plugintypes.RuleMetadata, tx plugintypes.TransactionState) {
	st := slots.get(tx.ID())
	if st == nil {
		return
	}
	var rules []uint32
	detected, interrupted, detectionOnly := false, false, false
	if v, ok := tx.(txView); ok {
		for _, m := range v.MatchedRules() {
			// Only logged matches, like the audit record launchroomd reads:
			// CRS's nolog flow rules (paranoia skips, setup) match on every
			// request.
			if l, ok := m.(interface{ Log() bool }); ok && !l.Log() {
				continue
			}
			id := m.Rule().ID()
			switch {
			case id == ruleInboundEvaluation || id == ruleOutboundEvaluation:
				detected = true
			case detectionRule(id) && len(rules) < format.MaxRules && !slices.Contains(rules, uint32(id)): //nolint:gosec // range checked
				rules = append(rules, uint32(id)) //nolint:gosec // range checked
			}
		}
		interrupted = v.Interruption() != nil
		detectionOnly = v.DetectionOnlyInterruption() != nil
	}
	score := 0
	if vals := tx.Variables().TX().Get("blocking_inbound_anomaly_score"); len(vals) > 0 {
		score, _ = strconv.Atoi(vals[0])
	}
	st.mu.Lock()
	defer st.mu.Unlock()
	st.fwSeen = true
	st.fwRules = rules
	st.fwScore = score
	st.fwInterrupted = st.fwInterrupted || interrupted
	st.fwDetectionOnly = st.fwDetectionOnly || detectionOnly
	st.fwDetected = st.fwDetected || detected || detectionOnly
}

// detectionRule reports whether a matched rule counts: a CRS rule, except
// initialisation (901), evaluation (949, 959) and correlation (980) rules
// (the filter of launchroomd's firewall events, daemon/internal/waf).
func detectionRule(id int) bool {
	if id < 900000 || id > 999999 {
		return false
	}
	switch id / 1000 {
	case 901, 949, 959, 980:
		return false
	}
	return true
}

var errActionArgs = errors.New("lrtrace: the action takes no argument")
