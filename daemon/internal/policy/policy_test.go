package policy_test

import (
	"errors"
	"net/netip"
	"slices"
	"strings"
	"testing"

	"github.com/moby/moby/api/types/container"
	"github.com/moby/moby/api/types/mount"
	"github.com/moby/moby/api/types/network"

	"github.com/vantezzen/launchroom/daemon/internal/docker"
	"github.com/vantezzen/launchroom/daemon/internal/policy"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

const digest = "@sha256:0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef"

func port(p int) *state.Port { v := state.Port(p); return &v }

func validSpec() state.WorkloadSpec {
	return state.WorkloadSpec{
		ID:          "wl_01J9Z8X7W6V5T4S3R2Q1P0N9MK",
		Service:     "svc_01J9Z8X7W6V5T4S3R2Q1P0N9MK",
		Environment: "env_01J9Z8X7W6V5T4S3R2Q1P0N9MK",
		Deployment:  "dpl_01J9Z8X7W6V5T4S3R2Q1P0N9MK",
		Image:       "127.0.0.1:5000/mirror/postgres" + digest,
		Port:        port(5432),
		Resources:   state.Resources{CPUs: 1, MemoryMB: 512, Pids: 256},
		Capabilities: []state.Capability{
			"CHOWN", "SETUID", "SETGID", "DAC_OVERRIDE", "FOWNER",
		},
		Networks: []state.NetworkAttachment{{Network: "net_01J9Z8X7W6V5T4S3R2Q1P0N9MK", Aliases: []state.Slug{"postgres"}}},
		Mounts:   []state.Mount{{Volume: "vol_01J9Z8X7W6V5T4S3R2Q1P0N9MK", Target: "/var/lib/postgresql/data"}},
		Restart:  state.RestartUnlessStopped,
		Role:     state.RoleServing,
	}
}

func TestCheck(t *testing.T) {
	cases := []struct {
		name   string
		mutate func(*state.WorkloadSpec, *policy.Config)
		rule   string // "" = allowed
	}{
		{"valid", func(*state.WorkloadSpec, *policy.Config) {}, ""},
		{"tag instead of digest", func(s *state.WorkloadSpec, _ *policy.Config) { s.Image = "127.0.0.1:5000/mirror/postgres:16" }, "image"},
		{"foreign registry", func(s *state.WorkloadSpec, _ *policy.Config) { s.Image = "docker.io/library/postgres" + digest }, "image"},
		{"registry prefix confusion", func(s *state.WorkloadSpec, _ *policy.Config) { s.Image = "127.0.0.1:50000/x" + digest }, "image"},
		{"extra registry allowed locally", func(s *state.WorkloadSpec, c *policy.Config) {
			s.Image = "ghcr.io/acme/app" + digest
			c.AllowedRegistries = append(c.AllowedRegistries, "ghcr.io/acme/")
		}, ""},
		{"capability outside allow-list", func(s *state.WorkloadSpec, _ *policy.Config) {
			s.Capabilities = append(s.Capabilities, "SYS_ADMIN")
		}, "capabilities"},
		{"NET_ADMIN outside allow-list", func(s *state.WorkloadSpec, _ *policy.Config) { s.Capabilities = []state.Capability{"NET_ADMIN"} }, "capabilities"},
		{"capability relaxed locally", func(s *state.WorkloadSpec, c *policy.Config) {
			s.Capabilities = []state.Capability{"SYS_NICE"}
			c.AllowedCapabilities = append(c.AllowedCapabilities, "SYS_NICE")
		}, ""},
		{"mount on docker socket", func(s *state.WorkloadSpec, _ *policy.Config) { s.Mounts[0].Target = "/var/run/docker.sock" }, "mounts"},
		{"mount on /proc", func(s *state.WorkloadSpec, _ *policy.Config) { s.Mounts[0].Target = "/proc/sys" }, "mounts"},
		{"mount of non-launchroom volume", func(s *state.WorkloadSpec, _ *policy.Config) { s.Mounts[0].Volume = "/etc" }, "mounts"},
		{"no memory limit", func(s *state.WorkloadSpec, _ *policy.Config) { s.Resources.MemoryMB = 0 }, "resources"},
		{"no pids limit", func(s *state.WorkloadSpec, _ *policy.Config) { s.Resources.Pids = 0 }, "resources"},
		{"no cpu limit", func(s *state.WorkloadSpec, _ *policy.Config) { s.Resources.CPUs = 0 }, "resources"},
		{"memory above local max", func(s *state.WorkloadSpec, c *policy.Config) { c.MaxMemoryMB = 256 }, "resources"},
		{"database stop without init", func(s *state.WorkloadSpec, _ *policy.Config) {
			s.Stop = &state.StopSpec{Signal: state.SignalInt, Timeout: "60s", NoInit: true}
		}, ""},
		{"config file", func(s *state.WorkloadSpec, _ *policy.Config) {
			s.Files = []state.ConfigFile{{Path: "/docker-entrypoint-initdb.d/99-roles.sql", Content: "select 1;"}}
		}, ""},
		{"config file inside a volume", func(s *state.WorkloadSpec, _ *policy.Config) {
			s.Files = []state.ConfigFile{{Path: "/var/lib/postgresql/data/postgresql.auto.conf", Content: "x"}}
		}, "files"},
		{"config file under /proc", func(s *state.WorkloadSpec, _ *policy.Config) {
			s.Files = []state.ConfigFile{{Path: "/proc/1/environ", Content: "x"}}
		}, "files"},
		{"stop with SIGKILL", func(s *state.WorkloadSpec, _ *policy.Config) {
			s.Stop = &state.StopSpec{Signal: "SIGKILL", Timeout: "60s"}
		}, "stop"},
		{"stop timeout above the maximum", func(s *state.WorkloadSpec, _ *policy.Config) {
			s.Stop = &state.StopSpec{Signal: state.SignalTerm, Timeout: "1h"}
		}, "stop"},
	}
	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			spec, cfg := validSpec(), policy.DefaultConfig()
			tc.mutate(&spec, &cfg)
			err := policy.Check(spec, cfg)
			assertRule(t, err, tc.rule)
		})
	}
}

func TestConfigValidate(t *testing.T) {
	bad := []policy.Config{
		{},
		func() policy.Config {
			c := policy.DefaultConfig()
			c.AllowedRegistries = []string{"127.0.0.1:5000"}
			return c
		}(),
		func() policy.Config {
			c := policy.DefaultConfig()
			c.AllowedCapabilities = []state.Capability{"CAP_X"}
			return c
		}(),
		func() policy.Config { c := policy.DefaultConfig(); c.MaxPids = 0; return c }(),
	}
	for i, c := range bad {
		if err := c.Validate(); err == nil {
			t.Errorf("case %d accepted", i)
		}
	}
	if err := policy.DefaultConfig().Validate(); err != nil {
		t.Fatal(err)
	}
}

func buildValid(t *testing.T) *docker.ContainerCreate {
	t.Helper()
	spec := validSpec()
	cc, err := docker.BuildContainer(docker.WorkloadInput{
		ServerID: "srv_01J9Z8X7W6V5T4S3R2Q1P0N9MK",
		Spec:     spec,
		Networks: map[state.NetworkID]state.HostName{spec.Networks[0].Network: "lr-e_abc"},
		Volumes:  map[state.VolumeID]state.HostName{spec.Mounts[0].Volume: "lr-v_abc"},
	}, policy.DefaultConfig())
	if err != nil {
		t.Fatal(err)
	}
	return cc
}

func TestCheckContainer(t *testing.T) {
	cases := []struct {
		name   string
		mutate func(*container.Config, *container.HostConfig)
		rule   string
	}{
		{"generated config passes", func(*container.Config, *container.HostConfig) {}, ""},
		{"privileged", func(_ *container.Config, h *container.HostConfig) { h.Privileged = true }, "privileged"},
		{"host network", func(_ *container.Config, h *container.HostConfig) { h.NetworkMode = "host" }, "namespaces"},
		{"join other container netns", func(_ *container.Config, h *container.HostConfig) { h.NetworkMode = "container:abc" }, "namespaces"},
		{"host pid", func(_ *container.Config, h *container.HostConfig) { h.PidMode = "host" }, "namespaces"},
		{"host ipc", func(_ *container.Config, h *container.HostConfig) { h.IpcMode = "host" }, "namespaces"},
		{"host uts", func(_ *container.Config, h *container.HostConfig) { h.UTSMode = "host" }, "namespaces"},
		{"host userns", func(_ *container.Config, h *container.HostConfig) { h.UsernsMode = "host" }, "namespaces"},
		{"device", func(_ *container.Config, h *container.HostConfig) {
			h.Devices = []container.DeviceMapping{{PathOnHost: "/dev/sda"}}
		}, "devices"},
		{"gpu request", func(_ *container.Config, h *container.HostConfig) {
			h.DeviceRequests = []container.DeviceRequest{{Driver: "nvidia"}}
		}, "devices"},
		{"bind via Binds", func(_ *container.Config, h *container.HostConfig) { h.Binds = []string{"/:/host"} }, "mounts"},
		{"bind via Mounts", func(_ *container.Config, h *container.HostConfig) {
			h.Mounts = append(h.Mounts, mount.Mount{Type: mount.TypeBind, Source: "/var/run/docker.sock", Target: "/var/run/docker.sock"})
		}, "mounts"},
		{"volume with local driver bind options", func(_ *container.Config, h *container.HostConfig) {
			h.Mounts[0].VolumeOptions = &mount.VolumeOptions{DriverConfig: &mount.Driver{Name: "local", Options: map[string]string{"o": "bind", "device": "/"}}}
		}, "mounts"},
		{"anonymous volume", func(c *container.Config, _ *container.HostConfig) { c.Volumes = map[string]struct{}{"/data": {}} }, "mounts"},
		{"cap add outside allow-list", func(_ *container.Config, h *container.HostConfig) { h.CapAdd = append(h.CapAdd, "SYS_ADMIN") }, "capabilities"},
		{"cap add with CAP_ prefix still checked", func(_ *container.Config, h *container.HostConfig) { h.CapAdd = []string{"CAP_SYS_PTRACE"} }, "capabilities"},
		{"no cap drop", func(_ *container.Config, h *container.HostConfig) { h.CapDrop = nil }, "capabilities"},
		{"missing no-new-privileges", func(_ *container.Config, h *container.HostConfig) { h.SecurityOpt = nil }, "security"},
		{"seccomp unconfined", func(_ *container.Config, h *container.HostConfig) {
			h.SecurityOpt = append(h.SecurityOpt, "seccomp=unconfined")
		}, "security"},
		{"apparmor unconfined", func(_ *container.Config, h *container.HostConfig) {
			h.SecurityOpt = append(h.SecurityOpt, "apparmor:unconfined")
		}, "security"},
		{"apparmor build profile", func(_ *container.Config, h *container.HostConfig) {
			h.SecurityOpt = append(h.SecurityOpt, "apparmor=launchroom-buildkit")
		}, "security"},
		{"apparmor other host profile", func(_ *container.Config, h *container.HostConfig) {
			h.SecurityOpt = append(h.SecurityOpt, "AppArmor=unprivileged_userns")
		}, "security"},
		{"unmask paths", func(_ *container.Config, h *container.HostConfig) { h.MaskedPaths = []string{} }, "host"},
		{"sysctls", func(_ *container.Config, h *container.HostConfig) {
			h.Sysctls = map[string]string{"net.ipv4.ip_forward": "1"}
		}, "host"},
		{"no init", func(_ *container.Config, h *container.HostConfig) { h.Init = nil }, "defaults"},
		{"init off without a typed stop", func(_ *container.Config, h *container.HostConfig) { h.Init = new(bool) }, "defaults"},
		{"init off with only a stop signal", func(c *container.Config, h *container.HostConfig) { h.Init, c.StopSignal = new(bool), "SIGINT" }, "defaults"},
		{"init off with a typed stop", func(c *container.Config, h *container.HostConfig) {
			timeout := 60
			h.Init, c.StopSignal, c.StopTimeout = new(bool), "SIGINT", &timeout
		}, ""},
		{"stop signal outside the allow-list", func(c *container.Config, _ *container.HostConfig) { c.StopSignal = "SIGKILL" }, "defaults"},
		{"stop timeout above the maximum", func(c *container.Config, _ *container.HostConfig) {
			timeout := 3600
			c.StopTimeout = &timeout
		}, "defaults"},
		{"unbounded logs", func(_ *container.Config, h *container.HostConfig) { delete(h.LogConfig.Config, "max-size") }, "defaults"},
		{"other log driver", func(_ *container.Config, h *container.HostConfig) { h.LogConfig.Type = "syslog" }, "defaults"},
		{"no pids limit", func(_ *container.Config, h *container.HostConfig) { h.PidsLimit = nil }, "defaults"},
		{"no memory limit", func(_ *container.Config, h *container.HostConfig) { h.Memory = 0 }, "defaults"},
		{"publish all", func(_ *container.Config, h *container.HostConfig) { h.PublishAllPorts = true }, "ports"},
		{"publish on all interfaces", func(_ *container.Config, h *container.HostConfig) {
			h.PortBindings = network.PortMap{network.MustParsePort("5432/tcp"): {{HostIP: netip.IPv4Unspecified(), HostPort: "5432"}}}
		}, "ports"},
		{"publish without host ip", func(_ *container.Config, h *container.HostConfig) {
			h.PortBindings = network.PortMap{network.MustParsePort("5432/tcp"): {{HostPort: "5432"}}}
		}, "ports"},
		{"publish on a LAN address", func(_ *container.Config, h *container.HostConfig) {
			h.PortBindings = network.PortMap{network.MustParsePort("5432/tcp"): {{HostIP: netip.MustParseAddr("192.168.1.5"), HostPort: "31001"}}}
		}, "ports"},
		{"publish outside the mesh port range", func(_ *container.Config, h *container.HostConfig) {
			h.PortBindings = network.PortMap{network.MustParsePort("5432/tcp"): {{HostIP: netip.MustParseAddr("100.64.0.7"), HostPort: "5432"}}}
		}, "ports"},
		{"publish on the mesh", func(_ *container.Config, h *container.HostConfig) {
			h.PortBindings = network.PortMap{network.MustParsePort("5432/tcp"): {{HostIP: netip.MustParseAddr("100.64.0.7"), HostPort: "31001"}}}
		}, ""},
		{"image swapped to tag", func(c *container.Config, _ *container.HostConfig) { c.Image = "127.0.0.1:5000/x:latest" }, "image"},
	}
	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			cc := buildValid(t)
			tc.mutate(cc.Config, cc.HostConfig)
			assertRule(t, policy.CheckContainer(cc.Config, cc.HostConfig, policy.DefaultConfig()), tc.rule)
		})
	}
	if err := policy.CheckContainer(nil, nil, policy.DefaultConfig()); err == nil {
		t.Fatal("nil config accepted")
	}
}

func assertRule(t *testing.T, err error, rule string) {
	t.Helper()
	if rule == "" {
		if err != nil {
			t.Fatalf("unexpected rejection: %v", err)
		}
		return
	}
	if err == nil {
		t.Fatalf("expected a %q violation, got none", rule)
	}
	var v *policy.Violation
	if !errors.As(err, &v) {
		t.Fatalf("error is not a policy violation: %v", err)
	}
	if !strings.Contains(err.Error(), "policy: "+rule+":") {
		t.Fatalf("expected rule %q, got %v", rule, err)
	}
}

func TestCheckSystemImage(t *testing.T) {
	cfg := policy.DefaultConfig()
	pinned := policy.DefaultSystemImageDigests[policy.SystemCaddy][0]
	for _, ref := range []state.ImageRef{
		state.ImageRef("docker.io/library/caddy@" + pinned),
		state.ImageRef("127.0.0.1:5000/mirror/caddy@" + pinned),
	} {
		if err := policy.CheckSystemImage(policy.SystemCaddy, ref, cfg); err != nil {
			t.Errorf("%s: %v", ref, err)
		}
	}
	other := state.ImageRef("docker.io/library/caddy@sha256:" + strings.Repeat("a", 64))
	if err := policy.CheckSystemImage(policy.SystemCaddy, other, cfg); err == nil {
		t.Error("unpinned digest accepted")
	}
	if err := policy.CheckSystemImage("buildkit", state.ImageRef("docker.io/library/caddy@"+pinned), cfg); err == nil {
		t.Error("caddy digest accepted for another component")
	}
	if err := policy.CheckSystemImage(policy.SystemCaddy, "docker.io/library/caddy:2", cfg); err == nil {
		t.Error("tag accepted")
	}
}

func TestCheckUpstreamAddress(t *testing.T) {
	cfg := policy.DefaultConfig()
	cfg.LocalUpstreams = []netip.AddrPort{netip.MustParseAddrPort("127.0.0.1:8000")}
	for _, ok := range []state.AddrPort{"100.64.0.9:31002", "100.127.255.254:1", "127.0.0.1:8000", "[::ffff:127.0.0.1]:8000"} {
		if err := policy.CheckUpstreamAddress(ok, cfg); err != nil {
			t.Errorf("%s refused: %v", ok, err)
		}
	}
	for _, bad := range []state.AddrPort{
		"127.0.0.1:2019",  // Caddy admin API
		"127.0.0.1:2021",  // the daemon's ask endpoint
		"127.0.0.1:5432",  // a database bound to loopback
		"127.0.0.2:8000",  // other loopback address
		"10.231.0.5:3000", // container IPs only via workload upstreams
		"169.254.169.254:80",
		"100.128.0.1:80", // just outside 100.64.0.0/10
		"[::1]:8000",
		"0.0.0.0:8000",
		"not-an-address",
	} {
		err := policy.CheckUpstreamAddress(bad, cfg)
		if err == nil {
			t.Errorf("%s allowed", bad)
			continue
		}
		assertRule(t, err, "upstreams")
	}
	if err := policy.CheckUpstreamAddress("127.0.0.1:8000", policy.DefaultConfig()); err == nil {
		t.Error("the dashboard port is allowed without the local allow-list")
	}
}

func TestFilterRouteUpstreams(t *testing.T) {
	cfg := policy.DefaultConfig()
	cfg.LocalUpstreams = []netip.AddrPort{netip.MustParseAddrPort("127.0.0.1:8000")}
	routes := []state.RouteSpec{
		{ID: "rt_01J9Z8X7W6V5T4S3R2Q1P0N9R1", Hosts: []state.Hostname{"a.example.com"}, Upstreams: []state.RouteUpstream{
			{Workload: "wl_01J9Z8X7W6V5T4S3R2Q1P0N9N7", Port: 3000},
			{Address: "127.0.0.1:2019"},
			{Address: "100.64.0.9:31002"},
		}},
		{ID: "rt_0000000000000000000000DASH", Hosts: []state.Hostname{"dash.example.com"}, Upstreams: []state.RouteUpstream{{Address: "127.0.0.1:8000"}}},
		{ID: "rt_01J9Z8X7W6V5T4S3R2Q1P0N9R2", Hosts: []state.Hostname{"b.example.com"}, Upstreams: []state.RouteUpstream{{Address: "127.0.0.1:22"}, {Address: "127.0.0.1:2019"}}},
	}
	out, refused := policy.FilterRouteUpstreams(routes, cfg)
	if len(out) != 3 || len(out[0].Upstreams) != 2 || out[0].Upstreams[1].Address != "100.64.0.9:31002" ||
		len(out[1].Upstreams) != 1 || len(out[2].Upstreams) != 0 {
		t.Fatalf("filtered = %+v", out)
	}
	if want := []state.AddrPort{"127.0.0.1:2019", "127.0.0.1:22"}; !slices.Equal(refused, want) {
		t.Fatalf("refused = %v, want %v", refused, want)
	}
	if len(routes[0].Upstreams) != 3 {
		t.Fatal("input was modified")
	}
}
