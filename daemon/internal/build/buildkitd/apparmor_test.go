package buildkitd

import (
	"context"
	"errors"
	"io"
	"net"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"

	"github.com/vantezzen/launchroom/daemon/internal/docker"
	"github.com/vantezzen/launchroom/daemon/internal/docker/dockerfake"
	"github.com/vantezzen/launchroom/daemon/internal/policy"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// aaDocker is the fake Docker of a host whose kernel has (or lacks) the
// launchroom-buildkit profile: like runc, starting a container with a
// profile the kernel doesn't have fails.
type aaDocker struct {
	*dockerfake.Fake
	loaded bool
}

func (d *aaDocker) StartContainer(ctx context.Context, id string) error {
	c, err := d.InspectContainer(ctx, id)
	if err != nil {
		return err
	}
	if cc, ok := d.Create(c.Name); ok && !d.loaded && strings.Join(cc.HostConfig.SecurityOpt, ",") == "seccomp=unconfined,apparmor="+AppArmorProfile {
		return errors.New("Error response from daemon: failed to create task for container: OCI runtime create failed: unable to apply apparmor profile: apparmor failed to apply profile: write /proc/thread-self/attr/apparmor/exec: no such file or directory: unknown")
	}
	return d.Fake.StartContainer(ctx, id)
}

type nopOps struct{}

func (nopOps) CopyToContainer(context.Context, string, string, io.Reader) error { return nil }
func (nopOps) DialExec(context.Context, string, []string) (net.Conn, error) {
	return nil, errors.New("no exec")
}

func aaRig(t *testing.T, restrict, apparmor string, loaded bool) (*Manager, *aaDocker) {
	t.Helper()
	dir := t.TempDir()
	sysctl, enabled := filepath.Join(dir, "userns"), filepath.Join(dir, "enabled")
	for p, v := range map[string]string{sysctl: restrict, enabled: apparmor} {
		if v == "" {
			continue // missing file: an older kernel, or no AppArmor
		}
		if err := os.WriteFile(p, []byte(v+"\n"), 0o600); err != nil {
			t.Fatal(err)
		}
	}
	d := &aaDocker{Fake: dockerfake.New(readySrv), loaded: loaded}
	d.AddImage(string(image))
	m := New(Options{ServerID: readySrv, Docker: d, Ops: nopOps{}, Policy: policy.DefaultConfig(), UsernsSysctl: sysctl, AppArmorEnabled: enabled})
	return m, d
}

func securityOpt(t *testing.T, d *aaDocker) string {
	t.Helper()
	cc, ok := d.Create(ContainerName)
	if !ok {
		t.Fatal("no buildkitd container")
	}
	return strings.Join(cc.HostConfig.SecurityOpt, ",")
}

func syncOn(t *testing.T, m *Manager) {
	t.Helper()
	if err := m.Sync(t.Context(), state.SystemContainer{Enabled: true, Image: image}); err != nil {
		t.Fatal(err)
	}
}

func TestAppArmorUnrestrictedHostStaysUnconfined(t *testing.T) {
	for _, tc := range []struct{ name, restrict, apparmor string }{
		{"restriction off", "0", "Y"},
		{"older kernel", "", "Y"},
		{"no AppArmor", "1", "N"},
		{"AppArmor module missing", "1", ""},
	} {
		t.Run(tc.name, func(t *testing.T) {
			m, d := aaRig(t, tc.restrict, tc.apparmor, false)
			syncOn(t, m)
			if got := securityOpt(t, d); got != "seccomp=unconfined,apparmor=unconfined" {
				t.Fatalf("security options %s", got)
			}
			if m.Userns() != UsernsOpen {
				t.Fatalf("userns %s", m.Userns())
			}
		})
	}
}

func TestAppArmorRestrictedHostUsesTheProfile(t *testing.T) {
	m, d := aaRig(t, "1", "Y", true)
	syncOn(t, m)
	if got := securityOpt(t, d); got != "seccomp=unconfined,apparmor=launchroom-buildkit" {
		t.Fatalf("security options %s", got)
	}
	if !m.Enabled() || m.Userns() != UsernsProfile {
		t.Fatalf("enabled %v userns %s", m.Enabled(), m.Userns())
	}
	// Converged: the next pass keeps the container.
	d.Calls() // Calls returns and clears the record
	syncOn(t, m)
	calls := d.Calls()
	for _, c := range calls {
		if strings.HasPrefix(c, "create ") || strings.HasPrefix(c, "remove ") {
			t.Fatalf("recreated a converged container: %v", calls)
		}
	}
}

func TestAppArmorMissingProfileFallsBackAndRetries(t *testing.T) {
	m, d := aaRig(t, "1", "Y", false)
	syncOn(t, m)
	if got := securityOpt(t, d); got != "seccomp=unconfined,apparmor=unconfined" {
		t.Fatalf("fallback security options %s", got)
	}
	if m.Userns() != UsernsBlocked {
		t.Fatalf("userns %s", m.Userns())
	}

	// Running fine unconfined (the owner allowed rootlesskit another way):
	// never replaced for a probe.
	m.mu.Lock()
	m.profileMissing = time.Now().Add(-time.Hour)
	m.mu.Unlock()
	syncOn(t, m)
	if got := securityOpt(t, d); !strings.HasSuffix(got, "apparmor=unconfined") {
		t.Fatalf("working fallback replaced: %s", got)
	}

	// Crash-looping unconfined, and the owner has loaded the profile since:
	// the next pass after reprobeAfter switches to it.
	cs, _ := d.ListContainers(t.Context(), map[string]string{docker.LabelSystem: policy.SystemBuildkit})
	d.Update(cs[0].ID, func(c *docker.Container) { c.State, c.Restarting, c.RestartCount = "restarting", true, 4 })
	d.loaded = true
	m.mu.Lock()
	m.profileMissing = time.Now() // too recent: not yet
	m.mu.Unlock()
	syncOn(t, m)
	if got := securityOpt(t, d); !strings.HasSuffix(got, "apparmor=unconfined") {
		t.Fatalf("probed again too early: %s", got)
	}
	m.mu.Lock()
	m.profileMissing = time.Now().Add(-reprobeAfter)
	m.mu.Unlock()
	syncOn(t, m)
	if got := securityOpt(t, d); got != "seccomp=unconfined,apparmor=launchroom-buildkit" {
		t.Fatalf("profile not picked up: %s", got)
	}
	if m.Userns() != UsernsProfile {
		t.Fatalf("userns %s", m.Userns())
	}
	cs, _ = d.ListContainers(t.Context(), map[string]string{docker.LabelSystem: policy.SystemBuildkit})
	if len(cs) != 1 {
		t.Fatalf("%d buildkitd containers", len(cs))
	}
}

func TestAppArmorOtherStartErrorsAreNotAProfileProblem(t *testing.T) {
	if appArmorRefused(errors.New("no space left on device")) || !appArmorRefused(errors.New("AppArmor enabled on system but the launchroom-buildkit profile could not be loaded")) {
		t.Fatal("appArmorRefused")
	}
}

func TestProfileCommandMatchesTheProfile(t *testing.T) {
	for _, want := range []string{"profile " + AppArmorProfile + " flags=(unconfined) {", "userns,", "abi <abi/4.0>,", "/etc/apparmor.d/" + AppArmorProfile, "apparmor_parser -r -W"} {
		if !strings.Contains(ProfileCommand, want) {
			t.Errorf("ProfileCommand misses %q", want)
		}
	}
}
