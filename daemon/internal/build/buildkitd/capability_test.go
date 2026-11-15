package buildkitd

import (
	"strings"
	"testing"

	"github.com/vantezzen/launchroom/daemon/internal/state"
)

func TestCapability(t *testing.T) {
	if c := Capability(false, UsernsBlocked, false, Resources{}); c != nil {
		t.Fatalf("not a build server: %+v", c)
	}
	if c := Capability(true, UsernsOpen, false, Resources{}); c.Name != state.CapBuilds || c.State != state.CapabilityFull || c.Message != "" {
		t.Fatalf("open: %+v", c)
	}
	if c := Capability(true, UsernsProfile, true, Resources{}); c.State != state.CapabilityFull || c.Reason != state.CapReasonUsernsProfile {
		t.Fatalf("profile: %+v", c)
	}
	c := Capability(true, UsernsBlocked, false, Resources{})
	if c.State != state.CapabilityUnavailable || c.Reason != state.CapReasonUsernsBlocked || !strings.Contains(c.Message, "apparmor_parser -r -W /etc/apparmor.d/launchroom-buildkit") || len(c.Message) > 512 {
		t.Fatalf("blocked: %+v", c)
	}
	// Restricted without the profile, but BuildKit answers (the owner
	// allowed rootlesskit another way): builds work.
	if c := Capability(true, UsernsBlocked, true, Resources{}); c.State != state.CapabilityFull {
		t.Fatalf("blocked but answering: %+v", c)
	}
	resources := PlanResources(HostFacts{CPUs: 1, MemoryMB: 2048}, Overrides{})
	if c := Capability(true, UsernsOpen, true, resources); c.State != state.CapabilityDegraded || c.Reason != state.CapReasonNoSwap || !strings.Contains(c.Message, "swapon") || len(c.Message) > 512 {
		t.Fatalf("small host without swap: %+v", c)
	}
	if err := state.CapBuilds.Validate(); err != nil {
		t.Fatal(err)
	}
}
