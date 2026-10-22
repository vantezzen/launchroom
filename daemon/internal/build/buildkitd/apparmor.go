package buildkitd

import (
	"context"
	"os"
	"strings"
	"time"

	"github.com/vantezzen/launchroom/daemon/internal/docker"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// User namespaces on restricted hosts (D58, docs/04 §5). Ubuntu 23.10 and
// later set kernel.apparmor_restrict_unprivileged_userns=1: a process that
// AppArmor leaves unconfined may not create a user namespace, so rootless
// BuildKit under apparmor=unconfined fails ("fork/exec /proc/self/exe:
// permission denied"). The installers load the host profile
// launchroom-buildkit (flags=(unconfined) plus `userns,`, the way Ubuntu's
// own per-application profiles grant it) and launchroomd starts buildkitd
// with apparmor=launchroom-buildkit there.
//
// launchroomd can't see the host's loaded profiles (securityfs is not in
// its container, and it has no host access by design, R1/R2). It finds out
// by starting: Docker refuses a profile the kernel doesn't have. Then it
// falls back to apparmor=unconfined (which still works where the owner
// allowed rootlesskit some other way) and tries the profile again when that
// container can't run either, at most every reprobeAfter, so loading the
// profile later takes effect without restarting launchroomd.

// reprobeAfter bounds how often a missing profile is tried again.
const reprobeAfter = 2 * time.Minute

// Userns is how buildkitd gets its user namespace on this host.
type Userns string

// User namespace states (Report.host.runtime "builds" capability).
const (
	// UsernsOpen: the host does not restrict user namespaces (or has no AppArmor).
	UsernsOpen Userns = "open"
	// UsernsProfile: restricted, and buildkitd runs with AppArmorProfile.
	UsernsProfile Userns = "profile"
	// UsernsBlocked: restricted, and the host has not loaded AppArmorProfile.
	UsernsBlocked Userns = "restricted"
)

// restricted: AppArmor is on and restricts unprivileged user namespaces.
func (m *Manager) restricted() bool {
	return readTrim(m.o.UsernsSysctl) == "1" && strings.EqualFold(readTrim(m.o.AppArmorEnabled), "Y")
}

func readTrim(path string) string {
	b, err := os.ReadFile(path) //nolint:gosec // fixed procfs/sysfs paths (tests: temp files)
	if err != nil {
		return ""
	}
	return strings.TrimSpace(string(b))
}

// Userns reports how buildkitd gets its user namespace.
func (m *Manager) Userns() Userns {
	m.mu.Lock()
	defer m.mu.Unlock()
	switch {
	case m.profile == AppArmorProfile:
		return UsernsProfile
	case !m.profileMissing.IsZero() && m.restricted():
		return UsernsBlocked
	}
	return UsernsOpen
}

// ensureProfiled converges the container with the AppArmor profile this
// host needs, falling back to unconfined when the host lacks the profile.
func (m *Manager) ensureProfiled(ctx context.Context, image state.ImageRef) (string, error) {
	cs, err := m.list(ctx)
	if err != nil {
		return "", err
	}
	profile := m.wantProfile(cs, image)
	id, err := m.ensureContainer(ctx, image, profile)
	if err != nil && profile == AppArmorProfile && appArmorRefused(err) {
		m.o.Log.Warn("AppArmor profile "+AppArmorProfile+" is not loaded on this host; builds need it while kernel.apparmor_restrict_unprivileged_userns=1 (re-run the installer)", "err", err)
		m.mu.Lock()
		m.profileMissing = time.Now()
		m.mu.Unlock()
		profile = appArmorUnconfined
		id, err = m.ensureContainer(ctx, image, profile)
	} else if err == nil && profile == AppArmorProfile {
		m.mu.Lock()
		m.profileMissing = time.Time{}
		m.mu.Unlock()
	}
	m.mu.Lock()
	m.profile = ""
	if err == nil {
		m.profile = profile
	}
	m.mu.Unlock()
	return id, err
}

// wantProfile picks the profile for this pass: unconfined on hosts without
// the restriction; on restricted hosts AppArmorProfile, unless it was
// missing recently and the unconfined container is running fine.
func (m *Manager) wantProfile(cs []docker.Container, image state.ImageRef) string {
	if !m.restricted() {
		return appArmorUnconfined
	}
	m.mu.Lock()
	missing := m.profileMissing
	m.mu.Unlock()
	if missing.IsZero() {
		return AppArmorProfile
	}
	hash := m.SpecHash(image, appArmorUnconfined)
	for _, c := range cs {
		if c.Name == ContainerName && c.Labels[docker.LabelSpecHash] == hash {
			if (c.Restarting || !c.Running) && time.Since(missing) >= reprobeAfter {
				return AppArmorProfile // it can't run unconfined either: try again
			}
			return appArmorUnconfined
		}
	}
	return appArmorUnconfined
}

// appArmorRefused: Docker or runc could not apply the profile ("apparmor
// failed to apply profile: write /proc/thread-self/attr/apparmor/exec: no
// such file or directory", or the daemon's "profile could not be loaded").
func appArmorRefused(err error) bool {
	return strings.Contains(strings.ToLower(err.Error()), "apparmor")
}
