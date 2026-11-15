package session

import "github.com/vantezzen/launchroom/daemon/internal/state"

// Features are the session kinds this server offers (allowed: the local
// LR_INTERACTIVE_SESSIONS policy).
func Features(allowed func(state.SessionKind) bool) []state.DaemonFeature {
	var out []state.DaemonFeature
	if allowed(state.SessionConsole) {
		out = append(out, state.FeatureConsoleSessions)
	}
	if allowed(state.SessionData) {
		out = append(out, state.FeatureDataSessions)
	}
	return out
}
