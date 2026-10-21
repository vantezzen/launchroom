package update

import (
	"fmt"

	"github.com/Masterminds/semver/v3"
)

// CheckNewer enforces monotonic updates: target must be a strictly newer
// semantic version than current (pre-releases order before their release,
// e.g. 1.2.0-rc.1 < 1.2.0). Equal or older targets are refused.
func CheckNewer(current, target string) error {
	cur, err := semver.StrictNewVersion(trimV(current))
	if err != nil {
		return fmt.Errorf("running version %q is not a semantic version: %w", current, err)
	}
	tgt, err := semver.StrictNewVersion(trimV(target))
	if err != nil {
		return fmt.Errorf("target version %q is not a semantic version: %w", target, err)
	}
	if !tgt.GreaterThan(cur) {
		return fmt.Errorf("refusing to update from %s to %s: launchroom never downgrades or reinstalls the same version", cur, tgt)
	}
	return nil
}

func trimV(v string) string {
	if len(v) > 0 && v[0] == 'v' {
		return v[1:]
	}
	return v
}
