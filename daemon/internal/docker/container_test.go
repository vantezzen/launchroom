package docker

import (
	"net/netip"
	"slices"
	"strings"
	"testing"

	"github.com/moby/moby/api/types/container"
	"github.com/moby/moby/api/types/mount"
	"github.com/moby/moby/api/types/network"

	"github.com/vantezzen/launchroom/daemon/internal/policy"
	"github.com/vantezzen/launchroom/daemon/internal/secret"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

const (
	digest = "@sha256:0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef"
	netA   = state.NetworkID("net_01J9Z8X7W6V5T4S3R2Q1P0N9MK")
	netB   = state.NetworkID("net_01J9Z8X7W6V5T4S3R2Q1P0N9MM")
	volA   = state.VolumeID("vol_01J9Z8X7W6V5T4S3R2Q1P0N9MK")
)

func webInput() WorkloadInput {
	p := state.Port(3000)
	return WorkloadInput{
		ServerID: "srv_01J9Z8X7W6V5T4S3R2Q1P0N9MK",
		Spec: state.WorkloadSpec{
			ID:          "wl_01J9Z8X7W6V5T4S3R2Q1P0N9MK",
			Service:     "svc_01J9Z8X7W6V5T4S3R2Q1P0N9MK",
			Environment: "env_01J9Z8X7W6V5T4S3R2Q1P0N9MK",
			Deployment:  "dpl_01J9Z8X7W6V5T4S3R2Q1P0N9MK",
			Image:       "127.0.0.1:5000/apps/web" + digest,
			Args:        []string{"node", "server.js"},
			Port:        &p,
			Resources:   state.Resources{CPUs: 1.5, MemoryMB: 512, Pids: 256},
			Networks: []state.NetworkAttachment{
				{Network: netA, Aliases: []state.Slug{"web"}},
				{Network: netB, Aliases: []state.Slug{"web-internal"}},
			},
			Mounts:  []state.Mount{{Volume: volA, Target: "/app/uploads", ReadOnly: true}},
			Restart: state.RestartUnlessStopped,
			Role:    state.RoleServing,
		},
		Env:      map[string]secret.Secret{"PORT": "3000", "DATABASE_URL": "postgres://u:p@postgres/db"},
		Networks: map[state.NetworkID]state.HostName{netA: "lr-e_aaa", netB: "lr-e_bbb"},
		Volumes:  map[state.VolumeID]state.HostName{volA: "lr-v_aaa"},
	}
}

func TestBuildContainerWeb(t *testing.T) {
	cc, err := BuildContainer(webInput(), policy.DefaultConfig())
	if err != nil {
		t.Fatal(err)
	}
	if cc.Name != "lr-wl_01j9z8x7w6v5t4s3r2q1p0n9mk" {
		t.Errorf("name = %q", cc.Name)
	}

	c, h := cc.Config, cc.HostConfig
	if c.Image != "127.0.0.1:5000/apps/web"+digest {
		t.Errorf("image = %q", c.Image)
	}
	if !slices.Equal(c.Cmd, []string{"node", "server.js"}) || c.Entrypoint != nil {
		t.Errorf("cmd = %v entrypoint = %v", c.Cmd, c.Entrypoint)
	}
	if !slices.Equal(c.Env, []string{"DATABASE_URL=postgres://u:p@postgres/db", "PORT=3000"}) {
		t.Errorf("env = %v", c.Env)
	}
	if c.Labels[LabelManaged] != "true" || c.Labels[LabelWorkload] != "wl_01J9Z8X7W6V5T4S3R2Q1P0N9MK" || c.Labels[LabelServer] == "" {
		t.Errorf("labels = %v", c.Labels)
	}
	if _, ok := c.ExposedPorts[network.MustParsePort("3000/tcp")]; !ok {
		t.Errorf("exposed ports = %v", c.ExposedPorts)
	}

	if h.Privileged || h.PublishAllPorts || len(h.PortBindings) != 0 {
		t.Error("unexpected privileges or published ports")
	}
	if !slices.Equal(h.CapDrop, []string{"ALL"}) || len(h.CapAdd) != 0 {
		t.Errorf("caps drop=%v add=%v", h.CapDrop, h.CapAdd)
	}
	if !slices.Equal(h.SecurityOpt, []string{"no-new-privileges:true"}) {
		t.Errorf("security opts = %v", h.SecurityOpt)
	}
	if h.Init == nil || !*h.Init {
		t.Error("init not set")
	}
	if h.LogConfig.Type != "json-file" || h.LogConfig.Config["max-size"] != "10m" || h.LogConfig.Config["max-file"] != "3" {
		t.Errorf("log config = %+v", h.LogConfig)
	}
	if h.NanoCPUs != 1_500_000_000 || h.Memory != 512<<20 || h.MemorySwap != h.Memory || h.PidsLimit == nil || *h.PidsLimit != 256 {
		t.Errorf("resources = %+v", h.Resources)
	}
	if h.RestartPolicy.Name != container.RestartPolicyUnlessStopped {
		t.Errorf("restart = %v", h.RestartPolicy)
	}
	if h.NetworkMode != "lr-e_aaa" {
		t.Errorf("network mode = %q", h.NetworkMode)
	}
	if len(h.Binds) != 0 || len(h.Mounts) != 1 {
		t.Fatalf("mounts = %+v binds = %v", h.Mounts, h.Binds)
	}
	if m := h.Mounts[0]; m.Type != mount.TypeVolume || m.Source != "lr-v_aaa" || m.Target != "/app/uploads" || !m.ReadOnly {
		t.Errorf("mount = %+v", m)
	}

	eps := cc.Networking.EndpointsConfig
	if len(eps) != 2 || !slices.Equal(eps["lr-e_aaa"].Aliases, []string{"web"}) || !slices.Equal(eps["lr-e_bbb"].Aliases, []string{"web-internal"}) {
		t.Errorf("endpoints = %+v", eps)
	}
}

func TestBuildContainerPostgresWithMeshPublish(t *testing.T) {
	in := webInput()
	in.Spec.Image = "127.0.0.1:5000/mirror/postgres" + digest
	in.Spec.Args = nil
	in.Spec.Command = []string{"docker-entrypoint.sh"}
	p := state.Port(5432)
	in.Spec.Port = &p
	in.Spec.PublishOnMesh = &state.MeshPublish{Port: 31001}
	in.Spec.Capabilities = []state.Capability{"CHOWN", "SETUID", "SETGID", "DAC_OVERRIDE", "FOWNER"}
	in.MeshIP = netip.MustParseAddr("100.64.0.7")

	cc, err := BuildContainer(in, policy.DefaultConfig())
	if err != nil {
		t.Fatal(err)
	}
	if cc.Config.Cmd != nil || !slices.Equal(cc.Config.Entrypoint, []string{"docker-entrypoint.sh"}) {
		t.Errorf("cmd=%v entrypoint=%v", cc.Config.Cmd, cc.Config.Entrypoint)
	}
	if !slices.Equal(cc.HostConfig.CapAdd, []string{"CHOWN", "SETUID", "SETGID", "DAC_OVERRIDE", "FOWNER"}) {
		t.Errorf("cap add = %v", cc.HostConfig.CapAdd)
	}
	b := cc.HostConfig.PortBindings[network.MustParsePort("5432/tcp")]
	if len(b) != 1 || b[0].HostIP != in.MeshIP || b[0].HostPort != "31001" {
		t.Errorf("port bindings = %+v", cc.HostConfig.PortBindings)
	}
}

func TestBuildContainerRejects(t *testing.T) {
	cases := map[string]func(*WorkloadInput){
		"foreign registry": func(in *WorkloadInput) { in.Spec.Image = "docker.io/library/nginx" + digest },
		"capability":       func(in *WorkloadInput) { in.Spec.Capabilities = []state.Capability{"SYS_ADMIN"} },
		"unknown network":  func(in *WorkloadInput) { delete(in.Networks, netB) },
		"unknown volume":   func(in *WorkloadInput) { in.Volumes = nil },
		"no network":       func(in *WorkloadInput) { in.Spec.Networks = nil },
		"bad env key":      func(in *WorkloadInput) { in.Env["BAD-KEY"] = "x" },
		"env with NUL":     func(in *WorkloadInput) { in.Env["X"] = "a\x00b" },
		"mesh without ip": func(in *WorkloadInput) {
			in.Spec.PublishOnMesh = &state.MeshPublish{Port: 31001}
		},
		"bad server id": func(in *WorkloadInput) { in.ServerID = "srv_x" },
	}
	for name, mutate := range cases {
		t.Run(name, func(t *testing.T) {
			in := webInput()
			mutate(&in)
			if _, err := BuildContainer(in, policy.DefaultConfig()); err == nil {
				t.Fatal("accepted")
			}
		})
	}
}

func TestEnvErrorsDoNotLeakValues(t *testing.T) {
	in := webInput()
	in.Env["X"] = "supersecret\x00"
	_, err := BuildContainer(in, policy.DefaultConfig())
	if err == nil || strings.Contains(err.Error(), "supersecret") {
		t.Fatalf("err = %v", err)
	}
}

func TestBuildContainerStop(t *testing.T) {
	in := webInput()
	in.Spec.Stop = &state.StopSpec{Signal: state.SignalInt, Timeout: "60s", NoInit: true}
	cc, err := BuildContainer(in, policy.DefaultConfig())
	if err != nil {
		t.Fatal(err)
	}
	if cc.Config.StopSignal != "SIGINT" || cc.Config.StopTimeout == nil || *cc.Config.StopTimeout != 60 {
		t.Errorf("stop signal %q timeout %v", cc.Config.StopSignal, cc.Config.StopTimeout)
	}
	if cc.HostConfig.Init == nil || *cc.HostConfig.Init {
		t.Errorf("init = %v, want false", cc.HostConfig.Init)
	}

	in.Spec.Stop = &state.StopSpec{Signal: state.SignalTerm, Timeout: "2m"}
	if cc, err = BuildContainer(in, policy.DefaultConfig()); err != nil {
		t.Fatal(err)
	}
	if cc.HostConfig.Init == nil || !*cc.HostConfig.Init || *cc.Config.StopTimeout != 120 {
		t.Errorf("init %v timeout %v", cc.HostConfig.Init, cc.Config.StopTimeout)
	}

	// Without stop the config is unchanged (spec hashes of existing workloads stay put).
	if cc, err = BuildContainer(webInput(), policy.DefaultConfig()); err != nil {
		t.Fatal(err)
	}
	if cc.Config.StopSignal != "" || cc.Config.StopTimeout != nil {
		t.Errorf("stop set without a spec: %q %v", cc.Config.StopSignal, cc.Config.StopTimeout)
	}
}

func TestBuildContainerFiles(t *testing.T) {
	in := webInput()
	in.Spec.Files = []state.ConfigFile{{Path: "/etc/app/app.conf", Content: "a=1\n"}}
	cc, err := BuildContainer(in, policy.DefaultConfig())
	if err != nil {
		t.Fatal(err)
	}
	if len(cc.Files) != 1 || cc.Files[0].Path != "/etc/app/app.conf" || cc.Files[0].Content != "a=1\n" {
		t.Fatalf("files %+v", cc.Files)
	}
	// Files never land in a volume (policy floor).
	in.Spec.Files = []state.ConfigFile{{Path: "/app/uploads/x.conf", Content: "a"}}
	if _, err := BuildContainer(in, policy.DefaultConfig()); err == nil {
		t.Fatal("file inside a mount accepted")
	}
}

// fromInspect carries HostConfig.Init, so an inspection can tell Docker's
// own init mount from a file the container wrote (docs/22 §7.2).
func TestFromInspectInit(t *testing.T) {
	on := true
	if c := fromInspect(container.InspectResponse{ID: "x", HostConfig: &container.HostConfig{Init: &on}}); !c.Init {
		t.Error("Init not read")
	}
	if c := fromInspect(container.InspectResponse{ID: "x"}); c.Init {
		t.Error("Init without HostConfig")
	}
}
