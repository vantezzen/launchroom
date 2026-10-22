package main

import (
	"strings"
	"testing"
	"time"

	"github.com/vantezzen/launchroom/daemon/internal/build/buildkitd"
	"github.com/vantezzen/launchroom/daemon/internal/docker"
	"github.com/vantezzen/launchroom/daemon/internal/guard/guardapi"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

func capState(r *state.HostRuntime, n state.CapabilityName) state.CapabilityState {
	for _, c := range r.Capabilities {
		if c.Name == n {
			return c.State
		}
	}
	return ""
}

func TestRuntimeCapabilities(t *testing.T) {
	enforced := &state.FirewallStatus{Mode: state.FirewallEnforced}
	// Rootful behind the guard: everything full.
	r := runtimeCapabilities(docker.RuntimeFacts{Guard: true}, state.HostLinux, nil, "", enforced, &state.MeshStatus{State: state.MeshConnected})
	for _, c := range r.Capabilities {
		if c.State != state.CapabilityFull {
			t.Errorf("rootful %s = %s", c.Name, c.State)
		}
	}
	if r.Docker != state.DockerRootful || !r.Guard {
		t.Fatalf("%+v", r)
	}
	// Without the guard: Docker access degraded.
	r = runtimeCapabilities(docker.RuntimeFacts{}, state.HostLinux, nil, "", nil, nil)
	if capState(r, state.CapDockerAccess) != state.CapabilityDegraded || capState(r, state.CapFirewall) != "" || capState(r, state.CapMesh) != "" {
		t.Fatalf("%+v", r)
	}
	// Rootless with exposed edge ports.
	all := []guardapi.EdgePort{{Proto: "tcp", Port: 80, Exposed: true}, {Proto: "tcp", Port: 443, Exposed: true}, {Proto: "udp", Port: 443, Exposed: true}}
	r = runtimeCapabilities(docker.RuntimeFacts{Rootless: true, Guard: true, EdgePorts: true}, state.HostLinux, all, "", enforced, &state.MeshStatus{State: state.MeshConnected})
	if r.Docker != state.DockerRootless || capState(r, state.CapEdgePorts) != state.CapabilityFull ||
		capState(r, state.CapClientAddresses) != state.CapabilityDegraded || capState(r, state.CapFirewall) != state.CapabilityDegraded {
		t.Fatalf("%+v", r)
	}
	// Rootless, a port refused (unprivileged port range).
	some := []guardapi.EdgePort{{Proto: "tcp", Port: 80, Message: "permission denied"}, {Proto: "tcp", Port: 443, Exposed: true}}
	r = runtimeCapabilities(docker.RuntimeFacts{Rootless: true, Guard: true, EdgePorts: true}, state.HostLinux, some, "", nil, nil)
	if capState(r, state.CapEdgePorts) != state.CapabilityDegraded {
		t.Fatalf("%+v", r)
	}
	// Rootless without the RootlessKit API: no edge ports.
	r = runtimeCapabilities(docker.RuntimeFacts{Rootless: true, Guard: true}, state.HostLinux, nil, "", &state.FirewallStatus{Mode: state.FirewallError, Error: "nft: operation not permitted"}, nil)
	if capState(r, state.CapEdgePorts) != state.CapabilityUnavailable || capState(r, state.CapFirewall) != state.CapabilityUnavailable {
		t.Fatalf("%+v", r)
	}
	// Every result validates as part of a report.
	validateRuntime(t, r)
	if r.Engine != state.EngineDocker || capState(r, state.CapWorkloads) != state.CapabilityFull {
		t.Fatalf("%+v", r)
	}
}

func validateRuntime(t *testing.T, r *state.HostRuntime) {
	t.Helper()
	rep := &state.Report{TypeMeta: state.NewTypeMeta(state.KindReport), ServerID: "srv_01J9Z8X7W6V5T4S3R2Q1P0N9M8", ObservedAt: time.Now().UTC(), Seq: 1, Workloads: []state.WorkloadStatus{},
		Host: &state.HostStatus{CPUs: 1, DaemonVersion: "1.0.0", Runtime: r}}
	if err := rep.Validate(); err != nil {
		t.Fatal(err)
	}
}

func TestRuntimeCapabilitiesMac(t *testing.T) {
	enforced := &state.FirewallStatus{Mode: state.FirewallEnforced}
	connected := &state.MeshStatus{State: state.MeshConnected}
	for _, engine := range []state.EngineKind{state.EngineOrbStack, state.EngineColima, state.EngineDockerDesktop} {
		r := runtimeCapabilities(docker.RuntimeFacts{Guard: true, Engine: engine}, state.HostDarwin, nil, "", enforced, connected)
		r.BuildPlatforms = []state.Platform{state.PlatformAMD64, state.PlatformARM64}
		validateRuntime(t, r)
		if r.HostOS != state.HostDarwin || r.Engine != engine {
			t.Fatalf("%s: %+v", engine, r)
		}
		// A Mac is never an edge; isolation applies inside the VM; the mesh sits behind the VM's NAT.
		if capState(r, state.CapEdgePorts) != state.CapabilityUnavailable || capState(r, state.CapClientAddresses) != state.CapabilityDegraded ||
			capState(r, state.CapFirewall) != state.CapabilityFull || capState(r, state.CapMesh) != state.CapabilityDegraded ||
			capState(r, state.CapPortDiscovery) != state.CapabilityFull || capState(r, state.CapDockerAccess) != state.CapabilityFull {
			t.Fatalf("%s: %+v", engine, r)
		}
		// Docker Desktop publishes ports on the Mac, not on the mesh address: no apps.
		want := state.CapabilityFull
		if engine == state.EngineDockerDesktop {
			want = state.CapabilityUnavailable
		}
		if got := capState(r, state.CapWorkloads); got != want {
			t.Fatalf("%s: workloads %s, want %s", engine, got, want)
		}
	}
	// Docker Desktop on Linux is a VM too.
	r := runtimeCapabilities(docker.RuntimeFacts{Guard: true, Engine: state.EngineDockerDesktop}, state.HostLinux, nil, "", nil, nil)
	if capState(r, state.CapEdgePorts) != state.CapabilityUnavailable || capState(r, state.CapWorkloads) != state.CapabilityUnavailable {
		t.Fatalf("%+v", r)
	}
	validateRuntime(t, r)
}

func TestHostOSOf(t *testing.T) {
	cases := []struct {
		configured state.HostOS
		engine     state.EngineKind
		want       state.HostOS
	}{
		{"", state.EngineDocker, state.HostLinux},
		{"", state.EngineOrbStack, state.HostDarwin},
		{"", state.EngineColima, state.HostLinux}, // Colima also runs on Linux: the installer says darwin
		{"", state.EngineDockerDesktop, state.HostLinux},
		{state.HostDarwin, state.EngineColima, state.HostDarwin},
	}
	for _, c := range cases {
		if got := hostOSOf(c.configured, c.engine); got != c.want {
			t.Errorf("hostOSOf(%q, %q) = %q, want %q", c.configured, c.engine, got, c.want)
		}
	}
}

func TestBuildsCapability(t *testing.T) {
	if c := buildsCapability(false, buildkitd.UsernsBlocked, false); c != nil {
		t.Fatalf("not a build server: %+v", c)
	}
	if c := buildsCapability(true, buildkitd.UsernsOpen, false); c.Name != state.CapBuilds || c.State != state.CapabilityFull || c.Message != "" {
		t.Fatalf("open: %+v", c)
	}
	if c := buildsCapability(true, buildkitd.UsernsProfile, true); c.State != state.CapabilityFull || !strings.Contains(c.Message, "launchroom-buildkit") {
		t.Fatalf("profile: %+v", c)
	}
	c := buildsCapability(true, buildkitd.UsernsBlocked, false)
	if c.State != state.CapabilityUnavailable || !strings.Contains(c.Message, "apparmor_parser -r -W /etc/apparmor.d/launchroom-buildkit") || len(c.Message) > 512 {
		t.Fatalf("blocked: %+v", c)
	}
	// Restricted without the profile, but BuildKit answers (the owner
	// allowed rootlesskit another way): builds work.
	if c := buildsCapability(true, buildkitd.UsernsBlocked, true); c.State != state.CapabilityFull {
		t.Fatalf("blocked but answering: %+v", c)
	}
	if err := state.CapBuilds.Validate(); err != nil {
		t.Fatal(err)
	}
}
