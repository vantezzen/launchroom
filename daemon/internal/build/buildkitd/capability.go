package buildkitd

import "github.com/vantezzen/launchroom/daemon/internal/state"

// Capability is the "builds" row of the capability matrix (Report.host.
// runtime, D58): nil when BuildKit is off here; unavailable when the host
// restricts user namespaces, lacks launchroom's AppArmor profile and
// buildkitd doesn't answer.
func Capability(enabled bool, userns Userns, answering bool, res Resources) *state.HostCapability {
	if !enabled {
		return nil
	}
	c := &state.HostCapability{Name: state.CapBuilds, State: state.CapabilityFull}
	switch {
	case userns == UsernsProfile:
		c.Reason = state.CapReasonUsernsProfile
	case userns == UsernsBlocked && !answering:
		c.State, c.Reason, c.Message = state.CapabilityUnavailable, state.CapReasonUsernsBlocked, ProfileCommand
	case res.NoSwap:
		c.State, c.Reason, c.Message = state.CapabilityDegraded, state.CapReasonNoSwap, SwapCommand
	case res.MemoryMB > 0:
		c.Reason, c.Message = state.CapReasonBuildLimits, res.Summary()
	}
	return c
}
