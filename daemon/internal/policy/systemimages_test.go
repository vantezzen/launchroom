package policy_test

import (
	"slices"
	"strings"
	"testing"

	"github.com/vantezzen/launchroom/daemon/internal/policy"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// A dev/test build (no launchroom-caddy digest compiled in) accepts the stock
// Caddy image, which the dev stack and the dashboard's default config run.
func TestSystemImageDigestsDevBuild(t *testing.T) {
	if policy.LaunchroomCaddyImageDigest() != "" {
		t.Skip("built with a launchroom-caddy digest")
	}
	if got := policy.DefaultSystemImageDigests[policy.SystemCaddy]; !slices.Equal(got, []string{policy.StockCaddyImageDigest}) {
		t.Fatalf("caddy pins = %v, want the stock digest only", got)
	}
}

// A release build pins exactly its own launchroom-caddy (docs/05 §4, D47):
// neither the stock image nor any other digest, whatever the dashboard sends.
func TestSystemImageDigestsReleaseBuild(t *testing.T) {
	release := "sha256:" + strings.Repeat("c", 64)
	pins := policy.SystemImageDigestsFor(release)
	if got := pins[policy.SystemCaddy]; !slices.Equal(got, []string{release}) {
		t.Fatalf("caddy pins = %v, want only %s", got, release)
	}
	if !slices.Equal(pins[policy.SystemBuildkit], []string{policy.BuildkitImageDigest}) ||
		!slices.Equal(pins[policy.SystemTailscale], []string{policy.TailscaleImageDigest}) {
		t.Fatalf("other pins changed: %v", pins)
	}
	cfg := policy.DefaultConfig()
	cfg.SystemImageDigests = pins
	if err := policy.CheckSystemImage(policy.SystemCaddy, state.ImageRef("ghcr.io/vantezzen/launchroom-caddy@"+release), cfg); err != nil {
		t.Errorf("release launchroom-caddy refused: %v", err)
	}
	if err := policy.CheckSystemImage(policy.SystemCaddy, state.ImageRef("127.0.0.1:5000/mirror/ghcr.io/vantezzen/launchroom-caddy@"+release), cfg); err != nil {
		t.Errorf("mirrored launchroom-caddy refused: %v", err)
	}
	if err := policy.CheckSystemImage(policy.SystemCaddy, state.ImageRef("docker.io/library/caddy@"+policy.StockCaddyImageDigest), cfg); err == nil {
		t.Error("a release build accepts the stock caddy digest")
	}
	if err := policy.CheckSystemImage(policy.SystemBuildkit, state.ImageRef("ghcr.io/vantezzen/launchroom-caddy@"+release), cfg); err == nil {
		t.Error("the caddy pin is accepted for buildkit")
	}
	// The pins are a copy per call: DefaultConfig callers may append host pins.
	pins[policy.SystemCaddy] = append(pins[policy.SystemCaddy], "sha256:"+strings.Repeat("d", 64))
	if got := policy.SystemImageDigestsFor(release)[policy.SystemCaddy]; len(got) != 1 {
		t.Fatalf("pins shared between calls: %v", got)
	}
}

// A malformed build-time digest is a broken release: fail closed at start.
func TestSystemImageDigestsMalformed(t *testing.T) {
	for _, bad := range []string{
		"685ae3d7c34d9b5f8caeddc5d14051db7bbd88ff1f5a2fa0c16bd8ca328fb7af",
		"sha256:" + strings.Repeat("C", 64),
		"sha256:" + strings.Repeat("c", 63),
		"sha512:" + strings.Repeat("c", 64),
		"ghcr.io/vantezzen/launchroom-caddy@sha256:" + strings.Repeat("c", 64),
	} {
		func() {
			defer func() {
				if recover() == nil {
					t.Errorf("%q accepted", bad)
				}
			}()
			policy.SystemImageDigestsFor(bad)
		}()
	}
}
