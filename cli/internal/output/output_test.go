package output

import (
	"testing"

	"github.com/vantezzen/launchroom/cli/internal/api"
)

// Tones come from the API (DeploymentStatus::tone()); every tone it sends
// has its own look here.
func TestStatusTonesFollowTheAPI(t *testing.T) {
	known := map[string]bool{toneActive: true, toneProgress: true, toneFailed: true, toneWarning: true, toneIdle: true, toneStaged: true}
	for _, s := range api.DeploymentStatusValues {
		tone, ok := api.DeploymentStatusTones[s]
		if !ok || !known[tone] {
			t.Errorf("deployment status %q has tone %q, which the CLI doesn't show", s, tone)
		}
		if toneOf(s) != tone {
			t.Errorf("toneOf(%q) = %q, the API says %q", s, toneOf(s), tone)
		}
	}
	if toneOf(api.ServiceStatusLive) != toneActive || toneOf(api.ServiceStatusStopped) != toneIdle {
		t.Error("service statuses: live is active, stopped is idle")
	}
}

func TestStatusLabel(t *testing.T) {
	for in, want := range map[string]string{
		api.DeploymentStatusActive:                "live",
		api.DeploymentStatusHealthChecking:        "health checking",
		api.ImportRunStatusWaitingForSafetyBackup: "waiting for safety backup",
		api.ServiceStatusNotDeployed:              "not deployed",
	} {
		if got := StatusLabel(in); got != want {
			t.Errorf("StatusLabel(%q) = %q, want %q", in, got, want)
		}
	}
}
