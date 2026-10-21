package buildkitd

import (
	"errors"
	"strings"
	"testing"

	"github.com/vantezzen/launchroom/daemon/internal/docker"
	"github.com/vantezzen/launchroom/daemon/internal/policy"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

const image = state.ImageRef("127.0.0.1:5000/mirror/docker.io/moby/buildkit@" + policy.BuildkitImageDigest)

func TestConfig(t *testing.T) {
	m := New(Options{ServerID: "srv_01J9Z8X7W6V5T4S3R2Q1P0N9MK", MaxParallelism: 3, GC: GC{MaxUsedSpace: "60GB", ReservedSpace: "10GB"}})
	cfg := string(m.Config())
	for _, want := range []string{
		"max-parallelism = 3",
		`maxUsedSpace = "60GB"`,
		`reservedSpace = "10GB"`,
		`minFreeSpace = "15%"`,
		`keepDuration = "168h0m0s"`,
		`[registry."registry.launchroom.internal:5000"]` + "\n  http = true",
		`[registry."docker.io"]` + "\n  mirrors = [\"registry.launchroom.internal:5000/mirror/docker.io\"]",
		`[registry."ghcr.io"]` + "\n  mirrors = [\"registry.launchroom.internal:5000/mirror/ghcr.io\"]",
		`allowedRepositories = ["ghcr.io/railwayapp/railpack-frontend", "docker.io/docker/dockerfile"]`,
		"[worker.containerd]\n  enabled = false",
	} {
		if !strings.Contains(cfg, want) {
			t.Errorf("config misses %q:\n%s", want, cfg)
		}
	}
}

func TestContainerSpec(t *testing.T) {
	m := New(Options{ServerID: "srv_01J9Z8X7W6V5T4S3R2Q1P0N9MK", MemoryMB: 2048, CPUs: 2})
	cc := m.ContainerSpec(image)
	hc := cc.HostConfig
	if hc.Privileged || hc.NetworkMode != NetworkName || hc.PidMode != "" || len(hc.CapAdd) != 0 || len(hc.Binds) != 0 {
		t.Fatalf("buildkitd must not be privileged or share host namespaces: %+v", hc)
	}
	if strings.Join(hc.SecurityOpt, ",") != "seccomp=unconfined,apparmor=unconfined" || hc.MaskedPaths == nil || len(hc.MaskedPaths) != 0 || hc.ReadonlyPaths == nil {
		t.Fatalf("security options %v masked %v", hc.SecurityOpt, hc.MaskedPaths)
	}
	if hc.ExtraHosts[0] != "registry.launchroom.internal:10.232.0.1" || hc.Memory != 2048<<20 || hc.NanoCPUs != 2e9 {
		t.Fatalf("extra hosts %v mem %d cpus %d", hc.ExtraHosts, hc.Memory, hc.NanoCPUs)
	}
	if !docker.IsManaged(cc.Config.Labels, "srv_01J9Z8X7W6V5T4S3R2Q1P0N9MK") || cc.Config.Labels[docker.LabelSystem] != policy.SystemBuildkit {
		t.Fatalf("labels %v", cc.Config.Labels)
	}
	for _, a := range cc.Config.Cmd {
		if strings.Contains(a, "no-process-sandbox") || strings.Contains(a, "addr") {
			t.Fatalf("unsafe flag %q", a)
		}
	}
	if m.SpecHash(image) == New(Options{ServerID: "srv_01J9Z8X7W6V5T4S3R2Q1P0N9MK"}).SpecHash(image) {
		t.Fatal("spec hash ignores resources")
	}
	if err := policy.CheckSystemImage(policy.SystemBuildkit, image, policy.DefaultConfig()); err != nil {
		t.Fatalf("pinned digest rejected: %v", err)
	}
	if err := policy.CheckSystemImage(policy.SystemBuildkit, state.ImageRef("docker.io/moby/buildkit@sha256:"+strings.Repeat("0", 64)), policy.DefaultConfig()); err == nil {
		t.Fatal("unpinned buildkit digest accepted")
	}
}

func TestClientWithoutDaemon(t *testing.T) {
	if _, err := New(Options{}).Client(t.Context()); !errors.Is(err, ErrNotRunning) {
		t.Fatalf("err = %v", err)
	}
}

func TestBuildPlatforms(t *testing.T) {
	// BuildKit lists the native platform first and emulated ones after it (D53).
	got := BuildPlatforms([]string{"linux/arm64", "linux/amd64", "linux/amd64", "linux/riscv64", "linux/arm", "LINUX/ARM64", "darwin/arm64"})
	if len(got) != 2 || got[0] != state.PlatformAMD64 || got[1] != state.PlatformARM64 {
		t.Fatalf("%v", got)
	}
	if got := BuildPlatforms(nil); got != nil {
		t.Fatalf("%v", got)
	}
}

func TestPlatformsNotRunning(t *testing.T) {
	if p := New(Options{}).Platforms(t.Context()); p != nil {
		t.Fatalf("%v", p)
	}
}
