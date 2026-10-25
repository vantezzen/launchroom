package guard

import (
	"archive/tar"
	"bytes"
	"context"
	"encoding/json"
	"io"
	"net"
	"net/http"
	"net/http/httptest"
	"net/netip"
	"os"
	"path/filepath"
	"strings"
	"sync"
	"testing"

	"github.com/moby/moby/api/types/blkiodev"
	"github.com/moby/moby/api/types/container"
	"github.com/moby/moby/api/types/mount"
	"github.com/moby/moby/api/types/network"

	"github.com/vantezzen/launchroom/daemon/internal/ambassadord"
	"github.com/vantezzen/launchroom/daemon/internal/build/buildkitd"
	"github.com/vantezzen/launchroom/daemon/internal/docker"
	"github.com/vantezzen/launchroom/daemon/internal/ingress/caddy"
	"github.com/vantezzen/launchroom/daemon/internal/mesh"
	"github.com/vantezzen/launchroom/daemon/internal/policy"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

const (
	testServer = "srv_01J9Z8X7W6V5T4S3R2Q1P0N9M8"
	testDigest = "sha256:0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef"
	wlID       = "wl_01J9Z8X7W6V5T4S3R2Q1P0N9M8"
)

// fakeDocker is an upstream Docker: canned inspections, a log of what reached it.
type fakeDocker struct {
	mu         sync.Mutex
	containers map[string]map[string]any
	networks   map[string]map[string]any
	volumes    map[string]map[string]any
	got        []*http.Request
	bodies     [][]byte
}

func newFake() *fakeDocker {
	return &fakeDocker{containers: map[string]map[string]any{}, networks: map[string]map[string]any{}, volumes: map[string]map[string]any{}}
}

func managed() map[string]string {
	return map[string]string{labelManaged: "true", labelServer: testServer}
}

func (f *fakeDocker) addContainer(id, name string, labels map[string]string, binds ...string) {
	mounts := []map[string]any{}
	for _, b := range binds {
		mounts = append(mounts, map[string]any{"Type": "bind", "Source": b})
	}
	c := map[string]any{"Id": id, "Name": "/" + name, "Image": "sha256:" + strings.Repeat("a", 64),
		"Config": map[string]any{"Labels": labels}, "State": map[string]any{"Running": true}, "Mounts": mounts}
	f.containers[id] = c
	f.containers[name] = c
}

func (f *fakeDocker) addNetwork(name string, labels map[string]string) {
	f.networks[name] = map[string]any{"Id": strings.Repeat("b", 63) + string(rune('0'+len(f.networks)%10)), "Name": name, "Driver": "bridge", "Labels": labels}
}

func (f *fakeDocker) addVolume(name string, labels map[string]string) {
	f.volumes[name] = map[string]any{"Name": name, "Driver": "local", "Labels": labels}
}

func (f *fakeDocker) ServeHTTP(w http.ResponseWriter, r *http.Request) {
	body, _ := io.ReadAll(r.Body)
	f.mu.Lock()
	f.got = append(f.got, r)
	f.bodies = append(f.bodies, body)
	f.mu.Unlock()
	p := r.URL.Path
	if i := strings.Index(p, "/v1."); i == 0 {
		p = p[strings.Index(p[1:], "/")+1:]
	}
	parts := strings.Split(strings.Trim(p, "/"), "/")
	lookup := func(m map[string]map[string]any, k string) {
		if v, ok := m[k]; ok {
			_ = json.NewEncoder(w).Encode(v)
			return
		}
		http.Error(w, `{"message":"no such object"}`, http.StatusNotFound)
	}
	switch {
	case r.Method == http.MethodGet && len(parts) == 3 && parts[0] == "containers" && parts[2] == "json":
		lookup(f.containers, parts[1])
	case r.Method == http.MethodGet && len(parts) == 2 && parts[0] == "networks":
		lookup(f.networks, parts[1])
	case r.Method == http.MethodGet && len(parts) == 2 && parts[0] == "volumes":
		lookup(f.volumes, parts[1])
	case r.Method == http.MethodPost && len(parts) == 3 && parts[2] == "exec":
		w.WriteHeader(http.StatusCreated)
		_, _ = w.Write([]byte(`{"Id":"` + strings.Repeat("e", 64) + `"}`))
	default:
		w.WriteHeader(http.StatusOK)
		_, _ = w.Write([]byte(`{}`))
	}
}

// last returns the last forwarded request that was not one of the guard's
// own inspections.
func (f *fakeDocker) last() (*http.Request, []byte) {
	f.mu.Lock()
	defer f.mu.Unlock()
	for i := len(f.got) - 1; i >= 0; i-- {
		r := f.got[i]
		if r.Method == http.MethodGet && !strings.HasSuffix(r.URL.Path, "/containers/json") && (strings.HasSuffix(r.URL.Path, "/json") || strings.Contains(r.URL.Path, "/networks/") || strings.Contains(r.URL.Path, "/volumes/")) {
			continue
		}
		return r, f.bodies[i]
	}
	return nil, nil
}

func (f *fakeDocker) count() int {
	f.mu.Lock()
	defer f.mu.Unlock()
	return len(f.got)
}

type harness struct {
	t    testing.TB
	fake *fakeDocker
	srv  *Server
	h    http.Handler
}

func newHarness(t testing.TB, mut ...func(*Options)) *harness {
	t.Helper()
	dir, err := os.MkdirTemp("", "guard")
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { _ = os.RemoveAll(dir) })
	sock := filepath.Join(dir, "d.sock")
	var lc net.ListenConfig
	l, err := lc.Listen(context.Background(), "unix", sock)
	if err != nil {
		t.Fatal(err)
	}
	fake := newFake()
	hs := &http.Server{Handler: fake} //nolint:gosec // test server
	go func() { _ = hs.Serve(l) }()
	t.Cleanup(func() { _ = hs.Close() })
	pol := policy.DefaultConfig()
	pol.SystemImageDigests[policy.SystemCaddy] = append(pol.SystemImageDigests[policy.SystemCaddy], testDigest)
	o := Options{Upstream: sock, Policy: pol}
	for _, m := range mut {
		m(&o)
	}
	srv, err := New(o)
	if err != nil {
		t.Fatal(err)
	}
	fake.addNetwork("lr-e_abc", managed())
	fake.addNetwork("lr-system-build", managed())
	fake.addNetwork("lr-system-ambassador", managed())
	fake.addNetwork("foreign-net", nil)
	fake.addVolume("lr-v_data", managed())
	fake.addVolume("lr-v_foreign", map[string]string{"x": "y"})
	return &harness{t: t, fake: fake, srv: srv, h: srv}
}

func (h *harness) do(method, target string, body any) *httptest.ResponseRecorder {
	h.t.Helper()
	var rd io.Reader
	switch b := body.(type) {
	case nil:
	case []byte:
		rd = bytes.NewReader(b)
	case string:
		rd = strings.NewReader(b)
	default:
		raw, err := json.Marshal(b)
		if err != nil {
			h.t.Fatal(err)
		}
		rd = bytes.NewReader(raw)
	}
	r := httptest.NewRequestWithContext(context.Background(), method, "http://docker"+target, rd)
	if rd != nil {
		r.Header.Set("Content-Type", "application/json")
	}
	w := httptest.NewRecorder()
	h.h.ServeHTTP(w, r)
	return w
}

func refused(t *testing.T, w *httptest.ResponseRecorder, want string) {
	t.Helper()
	if w.Code != http.StatusForbidden && w.Code != http.StatusNotFound {
		t.Fatalf("not refused (HTTP %d): %s", w.Code, w.Body.String())
	}
	if !strings.Contains(w.Body.String(), want) {
		t.Fatalf("refusal %q does not mention %q", w.Body.String(), want)
	}
}

func ok(t *testing.T, w *httptest.ResponseRecorder) {
	t.Helper()
	if w.Code/100 != 2 {
		t.Fatalf("HTTP %d: %s", w.Code, w.Body.String())
	}
}

func i64(v int64) *int64 { return &v }
func bptr(v bool) *bool  { return &v }

// workload is a create request like docker.BuildContainer's.
func workload() container.CreateRequest {
	labels := managed()
	labels["launchroom.workload"] = wlID
	return container.CreateRequest{
		Config: &container.Config{Image: "127.0.0.1:5000/apps/svc@" + testDigest, Labels: labels, Cmd: []string{"serve"}, Env: []string{"A=b"}},
		HostConfig: &container.HostConfig{
			NetworkMode: "lr-e_abc", CapDrop: []string{"ALL"}, SecurityOpt: []string{policy.NoNewPrivileges}, Init: bptr(true),
			LogConfig:     container.LogConfig{Type: "json-file", Config: map[string]string{"max-size": "10m", "max-file": "3"}},
			RestartPolicy: container.RestartPolicy{Name: container.RestartPolicyUnlessStopped},
			Resources:     container.Resources{NanoCPUs: 1e9, Memory: 256 << 20, MemorySwap: 256 << 20, PidsLimit: i64(512)},
			Mounts:        []mount.Mount{{Type: mount.TypeVolume, Source: "lr-v_data", Target: "/data"}},
		},
		NetworkingConfig: &network.NetworkingConfig{EndpointsConfig: map[string]*network.EndpointSettings{"lr-e_abc": {Aliases: []string{"web"}}}},
	}
}

const createPath = "/v1.52/containers/create?name=lr-wl_01j9z8x7w6v5t4s3r2q1p0n9m8"

func TestConstantsMatchPackages(t *testing.T) {
	pairs := [][2]string{
		{labelManaged, docker.LabelManaged}, {labelServer, docker.LabelServer}, {labelSystem, docker.LabelSystem}, {labelHelper, docker.LabelHelper},
		{caddyName, caddy.ContainerName}, {caddyDataVolume, caddy.DataVolume}, {caddyConfigVolume, caddy.ConfigVolume},
		{tailscaleName, mesh.ContainerName}, {tailscaleStateVolume, mesh.StateVolume},
		{buildkitName, buildkitd.ContainerName}, {buildkitNetwork, buildkitd.NetworkName}, {buildkitStateVolume, buildkitd.StateVolume},
		{buildkitAppArmor, buildkitd.AppArmorProfile},
		{ambassadorName, ambassadord.ContainerName}, {ambassadorNetwork, ambassadord.NetworkName}, {ambassadorVolume, ambassadord.ConfigVolume},
		{systemAmbassador, policy.SystemAmbassador}, {volumeHelperImage, docker.HelperImage}, {volumeHelperMount, docker.HelperMount},
	}
	for _, p := range pairs {
		if p[0] != p[1] {
			t.Errorf("guard constant %q != package constant %q", p[0], p[1])
		}
	}
}

func TestBuiltContainersPass(t *testing.T) {
	h := newHarness(t)
	ctx := t.Context()
	// The real system container specs of launchroomd.
	raw := func(cc *docker.ContainerCreate) []byte {
		b, err := json.Marshal(container.CreateRequest{Config: cc.Config, HostConfig: cc.HostConfig, NetworkingConfig: cc.Networking})
		if err != nil {
			t.Fatal(err)
		}
		return b
	}
	cc := caddy.ContainerSpec(testServer, state.ImageRef("docker.io/library/caddy@"+testDigest), "")
	if err := h.srv.CheckCreate(ctx, cc.Name, raw(cc)); err != nil {
		t.Errorf("caddy: %v", err)
	}
	tsImg := state.ImageRef("docker.io/tailscale/tailscale@" + policy.TailscaleImageDigest)
	ts := mesh.ContainerSpec(testServer, tsImg)
	if err := h.srv.CheckCreate(ctx, ts.Name, raw(ts)); err != nil {
		t.Errorf("tailscale: %v", err)
	}
	// Built workloads: through docker.BuildContainer.
	in := docker.WorkloadInput{ServerID: testServer, Spec: state.WorkloadSpec{
		ID: wlID, Service: "svc_01J9Z8X7W6V5T4S3R2Q1P0N9M8", Environment: "env_01J9Z8X7W6V5T4S3R2Q1P0N9M8", Deployment: "dpl_01J9Z8X7W6V5T4S3R2Q1P0N9M8",
		Image: state.ImageRef("127.0.0.1:5000/apps/svc@" + testDigest), Restart: "unless-stopped",
		Resources: state.Resources{CPUs: 1, MemoryMB: 256, Pids: 512},
		Networks:  []state.NetworkAttachment{{Network: "net_01J9Z8X7W6V5T4S3R2Q1P0N9M8"}},
	}, Networks: map[state.NetworkID]state.HostName{"net_01J9Z8X7W6V5T4S3R2Q1P0N9M8": "lr-e_abc"}}
	wl, err := docker.BuildContainer(in, policy.DefaultConfig())
	if err != nil {
		t.Fatal(err)
	}
	if err := h.srv.CheckCreate(ctx, wl.Name, raw(wl)); err != nil {
		t.Errorf("workload: %v", err)
	}
	w := h.do(http.MethodPost, "/v1.52/containers/create?name="+wl.Name, raw(wl))
	ok(t, w)
	if r, body := h.fake.last(); r == nil || r.URL.Path != "/v1.52/containers/create" || !bytes.Contains(body, []byte(`"lr-e_abc"`)) {
		t.Fatalf("not forwarded: %v %s", r, body)
	}
}

func TestContainerCreateRefusals(t *testing.T) {
	h := newHarness(t)
	cases := map[string]struct {
		mut  func(*container.CreateRequest)
		want string
	}{
		"privileged":   {func(r *container.CreateRequest) { r.HostConfig.Privileged = true }, "Privileged"},
		"host network": {func(r *container.CreateRequest) { r.HostConfig.NetworkMode = "host" }, "network mode"},
		"pid host":     {func(r *container.CreateRequest) { r.HostConfig.PidMode = "host" }, "PidMode"},
		"bind mount":   {func(r *container.CreateRequest) { r.HostConfig.Binds = []string{"/:/host"} }, "Binds"},
		"bind mount (Mount)": {func(r *container.CreateRequest) {
			r.HostConfig.Mounts[0] = mount.Mount{Type: mount.TypeBind, Source: "/", Target: "/host"}
		}, "named volume"},
		"docker socket": {func(r *container.CreateRequest) {
			r.HostConfig.Mounts[0] = mount.Mount{Type: mount.TypeBind, Source: "/var/run/docker.sock", Target: "/var/run/docker.sock"}
		}, "named volume"},
		"volume driver opts": {func(r *container.CreateRequest) {
			r.HostConfig.Mounts[0].VolumeOptions = &mount.VolumeOptions{DriverConfig: &mount.Driver{Name: "local", Options: map[string]string{"o": "bind", "device": "/"}}}
		}, "volume driver options"},
		"foreign volume": {func(r *container.CreateRequest) { r.HostConfig.Mounts[0].Source = "lr-v_foreign" }, NotManagedMarker},
		"system volume":  {func(r *container.CreateRequest) { r.HostConfig.Mounts[0].Source = "lr-system-caddy-data" }, "not a launchroom workload volume"},
		"foreign network": {func(r *container.CreateRequest) {
			r.HostConfig.NetworkMode = "foreign-net"
			r.NetworkingConfig = nil
		}, NotManagedMarker},
		"system network": {func(r *container.CreateRequest) {
			r.HostConfig.NetworkMode = "lr-system-build"
			r.NetworkingConfig = nil
		}, "system network"},
		"capability":  {func(r *container.CreateRequest) { r.HostConfig.CapAdd = []string{"SYS_ADMIN"} }, "allow-list"},
		"no cap drop": {func(r *container.CreateRequest) { r.HostConfig.CapDrop = nil }, "cap-drop ALL"},
		"seccomp": {func(r *container.CreateRequest) {
			r.HostConfig.SecurityOpt = append(r.HostConfig.SecurityOpt, "seccomp=unconfined")
		}, "security option"},
		"device": {func(r *container.CreateRequest) {
			r.HostConfig.Devices = []container.DeviceMapping{{PathOnHost: "/dev/sda"}}
		}, "Devices"},
		"sysctl":     {func(r *container.CreateRequest) { r.HostConfig.Sysctls = map[string]string{"kernel.x": "1"} }, "Sysctls"},
		"tag image":  {func(r *container.CreateRequest) { r.Image = "127.0.0.1:5000/apps/svc:latest" }, "digest"},
		"registry":   {func(r *container.CreateRequest) { r.Image = "docker.io/evil/x@" + testDigest }, "allowed registry"},
		"unlabelled": {func(r *container.CreateRequest) { r.Labels = map[string]string{} }, "launchroom.managed"},
		"bad server": {func(r *container.CreateRequest) { r.Labels[labelServer] = "x" }, "launchroom.managed"},
		"no limits":  {func(r *container.CreateRequest) { r.HostConfig.Memory = 0 }, "limits"},
		"all ports":  {func(r *container.CreateRequest) { r.HostConfig.PublishAllPorts = true }, "PublishAllPorts"},
		"public port": {func(r *container.CreateRequest) {
			r.HostConfig.PortBindings = network.PortMap{mustPort(t): {{HostIP: netip.MustParseAddr("0.0.0.0"), HostPort: "80"}}}
		}, "port"},
		"userns":       {func(r *container.CreateRequest) { r.HostConfig.UsernsMode = "host" }, "UsernsMode"},
		"cgroup":       {func(r *container.CreateRequest) { r.HostConfig.CgroupParent = "/" }, "CgroupParent"},
		"masked paths": {func(r *container.CreateRequest) { r.HostConfig.MaskedPaths = []string{} }, "MaskedPaths"},
		"long stop": {func(r *container.CreateRequest) {
			secs := 3600
			r.StopSignal, r.StopTimeout = "SIGTERM", &secs
		}, "stop timeout"},
		"healthcheck": {func(r *container.CreateRequest) {
			r.Healthcheck = &container.HealthConfig{Test: []string{"CMD", "x"}}
		}, "Healthcheck"},
		"endpoint ip": {func(r *container.CreateRequest) {
			r.NetworkingConfig.EndpointsConfig["lr-e_abc"].IPAddress = netip.MustParseAddr("10.0.0.5")
		}, "IPAddress"},
		"system name": {func(r *container.CreateRequest) {}, "reserved"},
	}
	for name, tc := range cases {
		t.Run(name, func(t *testing.T) {
			req := workload()
			tc.mut(&req)
			target := createPath
			if name == "system name" {
				target = "/v1.52/containers/create?name=lr-system-evil"
			}
			before := h.fake.count()
			w := h.do(http.MethodPost, target, req)
			refused(t, w, tc.want)
			if r, _ := h.fake.last(); h.fake.count() > before && r != nil && strings.HasSuffix(r.URL.Path, "/containers/create") && h.fake.count()-before > 0 {
				// Only inspections may have reached Docker.
				for _, g := range h.fake.got[before:] {
					if g.Method != http.MethodGet {
						t.Fatalf("%s reached Docker", g.URL.Path)
					}
				}
			}
		})
	}
	ok(t, h.do(http.MethodPost, createPath, workload()))
}

func mustPort(t *testing.T) network.Port {
	t.Helper()
	p, okp := network.PortFrom(80, network.TCP)
	if !okp {
		t.Fatal("port")
	}
	return p
}

func TestCreateBodyStrict(t *testing.T) {
	h := newHarness(t)
	refused(t, h.do(http.MethodPost, createPath, `{"Image":"x","HostConfig":{},"Bogus":1}`), "unknown field")
	refused(t, h.do(http.MethodPost, createPath, `{"Image":"x","HostConfig":{}} {}`), "trailing")
	refused(t, h.do(http.MethodPost, "/v1.52/containers/create", workload()), "generated name")
	// Case-folded duplicate keys: the re-encoded body reaches Docker, never the original.
	raw, _ := json.Marshal(workload())
	evil := bytes.Replace(raw, []byte(`"Privileged":false`), []byte(`"privileged":true`), 1)
	if bytes.Equal(evil, raw) {
		t.Fatal("fixture has no Privileged field")
	}
	refused(t, h.do(http.MethodPost, createPath, evil), "Privileged")
}

func TestSystemShapes(t *testing.T) {
	h := newHarness(t)
	ctx := t.Context()
	marshal := func(cc *docker.ContainerCreate) []byte {
		b, _ := json.Marshal(container.CreateRequest{Config: cc.Config, HostConfig: cc.HostConfig, NetworkingConfig: cc.Networking})
		return b
	}
	cc := caddy.ContainerSpec(testServer, state.ImageRef("docker.io/library/caddy@sha256:"+strings.Repeat("9", 64)), "")
	if err := h.srv.CheckCreate(ctx, cc.Name, marshal(cc)); err == nil || !strings.Contains(err.Error(), "pinned") {
		t.Fatalf("caddy with an unpinned digest: %v", err)
	}
	cc = caddy.ContainerSpec(testServer, state.ImageRef("docker.io/library/caddy@"+testDigest), "")
	cc.HostConfig.CapAdd = append(cc.HostConfig.CapAdd, "NET_ADMIN")
	if err := h.srv.CheckCreate(ctx, cc.Name, marshal(cc)); err == nil {
		t.Fatal("caddy with NET_ADMIN passed")
	}
	cc = caddy.ContainerSpec(testServer, state.ImageRef("docker.io/library/caddy@"+testDigest), "")
	cc.Name = "lr-wl_x"
	if err := h.srv.CheckCreate(ctx, cc.Name, marshal(cc)); err == nil {
		t.Fatal("host network under another name passed")
	}
	ts := mesh.ContainerSpec(testServer, state.ImageRef("docker.io/tailscale/tailscale@"+policy.TailscaleImageDigest))
	ts.HostConfig.Devices = append(ts.HostConfig.Devices, container.DeviceMapping{PathOnHost: "/dev/mem", PathInContainer: "/dev/mem", CgroupPermissions: "rwm"})
	if err := h.srv.CheckCreate(ctx, ts.Name, marshal(ts)); err == nil {
		t.Fatal("tailscale with /dev/mem passed")
	}
	ts = mesh.ContainerSpec(testServer, state.ImageRef("docker.io/tailscale/tailscale@"+policy.TailscaleImageDigest))
	ts.HostConfig.Mounts = append(ts.HostConfig.Mounts, mount.Mount{Type: mount.TypeVolume, Source: "lr-v_data", Target: "/x"})
	if err := h.srv.CheckCreate(ctx, ts.Name, marshal(ts)); err == nil {
		t.Fatal("tailscale with a workload volume passed")
	}
}

// The BuildKit container passes with AppArmor unconfined or the
// launchroom-buildkit profile (D58), and with nothing else.
func TestBuildkitSecurityOptions(t *testing.T) {
	h := newHarness(t)
	ctx := t.Context()
	img := state.ImageRef("127.0.0.1:5000/mirror/docker.io/moby/buildkit@" + policy.BuildkitImageDigest)
	m := buildkitd.New(buildkitd.Options{ServerID: testServer, Resources: buildkitd.PlanResources(buildkitd.HostFacts{CPUs: 1, MemoryMB: 2048, SwapMB: 2048}, buildkitd.Overrides{})})
	check := func(opts []string) error {
		cc := m.ContainerSpec(img, "unconfined")
		cc.HostConfig.SecurityOpt = opts
		b, err := json.Marshal(container.CreateRequest{Config: cc.Config, HostConfig: cc.HostConfig, NetworkingConfig: cc.Networking})
		if err != nil {
			t.Fatal(err)
		}
		return h.srv.CheckCreate(ctx, cc.Name, b)
	}
	for _, profile := range []string{"unconfined", buildkitd.AppArmorProfile} {
		cc := m.ContainerSpec(img, profile)
		b, _ := json.Marshal(container.CreateRequest{Config: cc.Config, HostConfig: cc.HostConfig, NetworkingConfig: cc.Networking})
		if err := h.srv.CheckCreate(ctx, cc.Name, b); err != nil {
			t.Errorf("buildkit with apparmor=%s: %v", profile, err)
		}
	}
	for _, opts := range [][]string{
		{"seccomp=unconfined", "apparmor=docker-default"},
		{"seccomp=unconfined", "apparmor=unprivileged_userns"},
		{"seccomp=unconfined", "apparmor=launchroom-buildkit", "apparmor=unconfined"},
		{"seccomp=unconfined", "apparmor=launchroom-buildkit", "label=disable"},
		{"apparmor=launchroom-buildkit"},
		{"seccomp=unconfined", "apparmor:launchroom-buildkit"},
		{"seccomp=unconfined", "apparmor=Launchroom-buildkit"},
		{"seccomp=unconfined", "apparmor=launchroom-buildkit "},
		{"seccomp=unconfined"},
	} {
		if err := check(opts); err == nil || !strings.Contains(err.Error(), "security options") {
			t.Errorf("buildkit with %v: %v", opts, err)
		}
	}
	// The profile is the build container's only: a workload asking for it
	// (or for any other AppArmor profile) is refused by the policy floor.
	for _, o := range []string{"apparmor=" + buildkitd.AppArmorProfile, "apparmor:unprivileged_userns", "apparmor=docker-default"} {
		req := workload()
		req.HostConfig.SecurityOpt = append(req.HostConfig.SecurityOpt, o)
		b, _ := json.Marshal(req)
		if err := h.srv.CheckCreate(ctx, "lr-wl_01j9z8x7w6v5t4s3r2q1p0n9m8", b); err == nil || !strings.Contains(err.Error(), "security option") {
			t.Errorf("workload with %s: %v", o, err)
		}
	}
}

// The BuildKit container gets a low CPU and I/O weight and a raised OOM
// score (D59); the guard lets it lower nothing else and raise nothing.
func TestBuildkitResourceShape(t *testing.T) {
	h := newHarness(t)
	ctx := t.Context()
	img := state.ImageRef("127.0.0.1:5000/mirror/docker.io/moby/buildkit@" + policy.BuildkitImageDigest)
	m := buildkitd.New(buildkitd.Options{ServerID: testServer, Resources: buildkitd.PlanResources(buildkitd.HostFacts{CPUs: 1, MemoryMB: 2048, SwapMB: 2048, ControlPlane: true}, buildkitd.Overrides{})})
	check := func(mut func(hc *container.HostConfig)) error {
		cc := m.ContainerSpec(img, "unconfined")
		mut(cc.HostConfig)
		b, err := json.Marshal(container.CreateRequest{Config: cc.Config, HostConfig: cc.HostConfig, NetworkingConfig: cc.Networking})
		if err != nil {
			t.Fatal(err)
		}
		return h.srv.CheckCreate(ctx, cc.Name, b)
	}
	if err := check(func(*container.HostConfig) {}); err != nil {
		t.Fatalf("planned buildkit shape refused: %v", err)
	}
	spec := m.ContainerSpec(img, "unconfined").HostConfig
	if spec.OomScoreAdj != 1000 || spec.CPUShares != 64 || spec.BlkioWeight != 10 || spec.NanoCPUs != 75e7 || spec.MemorySwap <= spec.Memory {
		t.Fatalf("planned shape %+v", spec.Resources)
	}
	for name, mut := range map[string]func(*container.HostConfig){
		"protected from the OOM killer": func(hc *container.HostConfig) { hc.OomScoreAdj = -500 },
		"OOM score out of range":        func(hc *container.HostConfig) { hc.OomScoreAdj = 1001 },
		"higher CPU weight":             func(hc *container.HostConfig) { hc.CPUShares = 4096 },
		"invalid I/O weight":            func(hc *container.HostConfig) { hc.BlkioWeight = 5 },
		"memory reservation":            func(hc *container.HostConfig) { hc.MemoryReservation = 1 << 30 },
		"unlimited swap":                func(hc *container.HostConfig) { hc.MemorySwap = -1 },
		"I/O device weight": func(hc *container.HostConfig) {
			hc.BlkioWeightDevice = []*blkiodev.WeightDevice{{Path: "/dev/sda", Weight: 1000}}
		},
	} {
		if err := check(mut); err == nil {
			t.Errorf("%s passed", name)
		}
	}
	// Other system containers and workloads can't set an OOM score or I/O weight.
	ts := mesh.ContainerSpec(testServer, state.ImageRef("docker.io/tailscale/tailscale@"+policy.TailscaleImageDigest))
	ts.HostConfig.OomScoreAdj = -1000
	b, _ := json.Marshal(container.CreateRequest{Config: ts.Config, HostConfig: ts.HostConfig, NetworkingConfig: ts.Networking})
	if err := h.srv.CheckCreate(ctx, ts.Name, b); err == nil {
		t.Fatal("tailscale with an OOM score passed")
	}
	req := workload()
	req.HostConfig.OomScoreAdj = -1000
	b, _ = json.Marshal(req)
	if err := h.srv.CheckCreate(ctx, "lr-wl_01j9z8x7w6v5t4s3r2q1p0n9m8", b); err == nil {
		t.Fatal("workload with an OOM score passed")
	}
}

func TestUpdaterShape(t *testing.T) {
	img := "sha256:" + strings.Repeat("c", 64)
	h := newHarness(t, func(o *Options) {
		o.Updater = &UpdaterRule{ProjectDir: "/opt/lr", SocketHostPath: "/var/run/docker.sock", Images: func(context.Context) ([]string, error) { return []string{img}, nil }}
	})
	good := func() container.CreateRequest {
		return container.CreateRequest{
			Config: &container.Config{Image: img, Entrypoint: []string{supervisorBinary, supervisorCommand}, Cmd: []string{"/opt/lr/data/update/tasks/tsk_01J9Z8X7W6V5T4S3R2Q1P0N9M8/plan.json"}, Tty: true,
				Labels: map[string]string{labelManaged: "true", labelRole: roleUpdater, "launchroom.update-task": "tsk_01J9Z8X7W6V5T4S3R2Q1P0N9M8"}},
			HostConfig: &container.HostConfig{NetworkMode: "none", GroupAdd: []string{"999"},
				LogConfig: container.LogConfig{Type: "json-file", Config: map[string]string{"max-size": "10m", "max-file": "3"}},
				Mounts:    []mount.Mount{{Type: mount.TypeBind, Source: "/var/run/docker.sock", Target: dockerSocket}, {Type: mount.TypeBind, Source: "/opt/lr", Target: "/opt/lr"}}},
		}
	}
	path := "/containers/create?name=lr-system-update-01j9z8x7w6v5t4s3r2q1p0n9m8"
	ok(t, h.do(http.MethodPost, path, good()))
	for name, mut := range map[string]func(*container.CreateRequest){
		"other image":      func(r *container.CreateRequest) { r.Image = "sha256:" + strings.Repeat("d", 64) },
		"other entrypoint": func(r *container.CreateRequest) { r.Entrypoint = []string{"/bin/busybox", "sh"} },
		"root mount": func(r *container.CreateRequest) {
			r.HostConfig.Mounts[1] = mount.Mount{Type: mount.TypeBind, Source: "/", Target: "/opt/lr"}
		},
		"plan outside": func(r *container.CreateRequest) { r.Cmd = []string{"/etc/plan.json"} },
		"privileged":   func(r *container.CreateRequest) { r.HostConfig.Privileged = true },
		"env":          func(r *container.CreateRequest) { r.Env = []string{"LD_PRELOAD=/x"} },
		"network":      func(r *container.CreateRequest) { r.HostConfig.NetworkMode = "host" },
	} {
		t.Run(name, func(t *testing.T) {
			r := good()
			mut(&r)
			if w := h.do(http.MethodPost, path, r); w.Code != http.StatusForbidden {
				t.Fatalf("passed: %d %s", w.Code, w.Body.String())
			}
		})
	}
	// Without a rule: refused.
	h2 := newHarness(t)
	refused(t, h2.do(http.MethodPost, path, good()), "self-update supervisors are not allowed")
	// No exec, no archive into a supervisor.
	h.fake.addContainer(strings.Repeat("f", 64), "lr-system-update-01j9z8x7w6v5t4s3r2q1p0n9m8", good().Labels, "/var/run/docker.sock", "/opt/lr")
	refused(t, h.do(http.MethodPost, "/containers/lr-system-update-01j9z8x7w6v5t4s3r2q1p0n9m8/exec", map[string]any{"Cmd": []string{"ls"}}), "no exec")
	refused(t, h.do(http.MethodPut, "/containers/lr-system-update-01j9z8x7w6v5t4s3r2q1p0n9m8/archive?path=/", "x"), "no archive")
	ok(t, h.do(http.MethodPost, "/containers/lr-system-update-01j9z8x7w6v5t4s3r2q1p0n9m8/start", nil))
}

func TestObjectAccess(t *testing.T) {
	h := newHarness(t)
	id := strings.Repeat("1", 64)
	fid := strings.Repeat("2", 64)
	h.fake.addContainer(id, "lr-wl_x", managed())
	h.fake.addContainer(fid, "postgres", map[string]string{"com.docker.compose.service": "postgres"})
	h.fake.addContainer(strings.Repeat("3", 64), "launchroom-dashboard-1", map[string]string{labelManaged: "true", labelRole: "control-plane", "com.docker.compose.service": "dashboard"})

	ok(t, h.do(http.MethodGet, "/v1.52/containers/lr-wl_x/json", nil))
	refused(t, h.do(http.MethodGet, "/v1.52/containers/postgres/json", nil), NotManagedMarker)
	refused(t, h.do(http.MethodGet, "/v1.52/containers/launchroom-dashboard-1/json", nil), NotManagedMarker)
	refused(t, h.do(http.MethodPost, "/v1.52/containers/postgres/stop?t=10", nil), NotManagedMarker)
	refused(t, h.do(http.MethodDelete, "/v1.52/containers/postgres?force=1", nil), NotManagedMarker)
	refused(t, h.do(http.MethodGet, "/v1.52/containers/nothere/json", nil), "no such container")

	// Forwarded by id, not by name.
	ok(t, h.do(http.MethodPost, "/v1.52/containers/lr-wl_x/stop?t=10", nil))
	if r, _ := h.fake.last(); r.URL.Path != "/v1.52/containers/"+id+"/stop" || r.URL.Query().Get("t") != "10" {
		t.Fatalf("forwarded %s", r.URL)
	}
	refused(t, h.do(http.MethodPost, "/v1.52/containers/lr-wl_x/stop?t=10&signal=KILL", nil), "signal")
	refused(t, h.do(http.MethodPost, "/v1.52/containers/lr-wl_x/stop?t=86400", nil), "integer")
	refused(t, h.do(http.MethodDelete, "/v1.52/containers/lr-wl_x?v=1", nil), "must be false")

	// Lists only ever show launchroom's objects.
	ok(t, h.do(http.MethodGet, `/v1.52/containers/json?all=1&filters=%7B%22label%22%3A%7B%22a%3Db%22%3Atrue%7D%7D`, nil))
	r, _ := h.fake.last()
	if f := r.URL.Query().Get("filters"); !strings.Contains(f, `"launchroom.managed=true":true`) || !strings.Contains(f, `"a=b":true`) {
		t.Fatalf("filters %s", f)
	}
	refused(t, h.do(http.MethodGet, `/v1.52/containers/json?filters=%7B%22before%22%3A%7B%22x%22%3Atrue%7D%7D`, nil), "filter")

	// Volumes and networks.
	refused(t, h.do(http.MethodDelete, "/v1.52/volumes/lr-v_foreign", nil), NotManagedMarker)
	ok(t, h.do(http.MethodDelete, "/v1.52/volumes/lr-v_data", nil))
	refused(t, h.do(http.MethodDelete, "/v1.52/volumes/lr-v_data?force=1", nil), "must be false")
	refused(t, h.do(http.MethodPost, "/v1.52/volumes/create", map[string]any{"Name": "lr-v_x", "Driver": "local", "DriverOpts": map[string]string{"device": "/"}, "Labels": managed()}), "DriverOpts")
	ok(t, h.do(http.MethodPost, "/v1.52/volumes/create", map[string]any{"Name": "lr-v_x", "Driver": "local", "Labels": managed()}))
	refused(t, h.do(http.MethodPost, "/v1.52/volumes/create", map[string]any{"Name": "data", "Labels": managed()}), "generated")
	refused(t, h.do(http.MethodDelete, "/v1.52/networks/foreign-net", nil), NotManagedMarker)
	ok(t, h.do(http.MethodPost, "/v1.52/networks/create", map[string]any{"Name": "lr-e_new", "Driver": "bridge", "EnableIPv6": false, "Labels": managed(), "IPAM": map[string]any{"Driver": "default", "Config": []map[string]any{{"Subnet": "10.201.0.0/24"}}}}))
	refused(t, h.do(http.MethodPost, "/v1.52/networks/create", map[string]any{"Name": "lr-e_new", "Driver": "macvlan", "Labels": managed(), "IPAM": map[string]any{"Config": []map[string]any{{"Subnet": "10.201.0.0/24"}}}}), "bridge")
	refused(t, h.do(http.MethodPost, "/v1.52/networks/create", map[string]any{"Name": "lr-e_new", "Driver": "bridge", "Labels": managed(), "IPAM": map[string]any{"Config": []map[string]any{{"Subnet": "100.64.0.0/24"}}}}), "reserved")
	refused(t, h.do(http.MethodPost, "/v1.52/networks/create", map[string]any{"Name": "lr-e_new", "Driver": "bridge", "Options": map[string]string{"com.docker.network.bridge.name": "eth0"}, "Labels": managed(), "IPAM": map[string]any{"Config": []map[string]any{{"Subnet": "10.1.0.0/24"}}}}), "Options")
	refused(t, h.do(http.MethodPost, "/v1.52/networks/lr-e_abc/connect", map[string]any{"Container": "postgres"}), NotManagedMarker)
}

func TestImages(t *testing.T) {
	h := newHarness(t)
	ok(t, h.do(http.MethodPost, "/v1.52/images/create?fromImage=127.0.0.1%3A5000%2Fapps%2Fsvc&tag="+testDigest, nil))
	refused(t, h.do(http.MethodPost, "/v1.52/images/create?fromImage=docker.io%2Flibrary%2Fubuntu&tag=latest", nil), "digest")
	refused(t, h.do(http.MethodPost, "/v1.52/images/create?fromSrc=http%3A%2F%2Fevil%2Fx.tar&repo=lr-x", nil), "fromSrc")
	ok(t, h.do(http.MethodPost, "/v1.52/images/create?fromSrc=-&repo=lr-ambassador%3Aabc&changes=USER+65534", "tar"))
	refused(t, h.do(http.MethodPost, "/v1.52/images/create?fromSrc=-&repo=lr-ambassador%3Aabc&changes=RUN+x", "tar"), "change")
	refused(t, h.do(http.MethodPost, "/v1.52/images/create?fromSrc=-&repo=docker.io%2Flibrary%2Fubuntu", "tar"), "local images")
	ok(t, h.do(http.MethodGet, "/v1.52/images/127.0.0.1:5000/apps/svc@"+testDigest+"/json", nil))
	ok(t, h.do(http.MethodDelete, "/v1.52/images/127.0.0.1:5000/apps/svc@"+testDigest, nil))
	refused(t, h.do(http.MethodDelete, "/v1.52/images/docker.io/library/postgres:17", nil), "not one of launchroom's images")
	refused(t, h.do(http.MethodDelete, "/v1.52/images/127.0.0.1:5000/apps/svc@"+testDigest+"?force=1", nil), "must be false")
}

func TestExecRules(t *testing.T) {
	h := newHarness(t, func(o *Options) { o.InteractiveSessions = "data" })
	h.fake.addContainer(strings.Repeat("1", 64), "lr-wl_x", managed())
	sys := managed()
	sys[labelSystem] = policy.SystemBuildkit
	h.fake.addContainer(strings.Repeat("4", 64), buildkitName, sys)
	ok(t, h.do(http.MethodPost, "/v1.52/containers/lr-wl_x/exec", map[string]any{"Cmd": []string{"pg_dump"}, "User": "postgres", "AttachStdout": true}))
	refused(t, h.do(http.MethodPost, "/v1.52/containers/lr-wl_x/exec", map[string]any{"Cmd": []string{"sh"}, "Tty": true}), "console sessions are off")
	refused(t, h.do(http.MethodPost, "/v1.52/containers/lr-wl_x/exec", map[string]any{"Cmd": []string{"sh"}, "Privileged": true}), "Privileged")
	refused(t, h.do(http.MethodPost, "/v1.52/containers/lr-wl_x/exec", map[string]any{"Cmd": []string{"sh"}, "WorkingDir": "/"}), "WorkingDir")
	refused(t, h.do(http.MethodPost, "/v1.52/containers/lr-wl_x/exec", map[string]any{"Cmd": []string{}}), "argv")
	ok(t, h.do(http.MethodPost, "/v1.52/containers/"+buildkitName+"/exec", map[string]any{"Cmd": []string{"buildctl", "dial-stdio"}, "AttachStdin": true}))
	refused(t, h.do(http.MethodPost, "/v1.52/containers/"+buildkitName+"/exec", map[string]any{"Cmd": []string{"sh"}}), "not allowed in")

	h2 := newHarness(t)
	h2.fake.addContainer(strings.Repeat("1", 64), "lr-wl_x", managed())
	ok(t, h2.do(http.MethodPost, "/v1.52/containers/lr-wl_x/exec", map[string]any{"Cmd": []string{"sh"}, "Tty": true}))
	refused(t, h2.do(http.MethodPost, "/v1.52/containers/lr-wl_x/exec", map[string]any{"Cmd": []string{"sh"}, "Tty": true, "User": "root"}), "own user")
	// Temporary SSH access (D61, R39) uses exactly these shapes: a TTY shell
	// as the container's user, "<shell> -c <command>" and the SFTP file
	// tools with stdin; no new route or field.
	// The shell runs inside the workload container (R39), never on the host
	// (R1); built like sshfront.go builds it, from the detected shell.
	containerShell := "/bin/sh"
	ok(t, h2.do(http.MethodPost, "/v1.52/containers/lr-wl_x/exec", map[string]any{"Cmd": []string{containerShell, "-c", "ls -la"}, "AttachStdin": true, "AttachStdout": true, "AttachStderr": true}))
	ok(t, h2.do(http.MethodPost, "/v1.52/containers/lr-wl_x/exec", map[string]any{"Cmd": []string{"dd", "of=/srv/x", "bs=65536"}, "AttachStdin": true, "AttachStdout": true}))
	ok(t, h2.do(http.MethodPost, "/v1.52/containers/lr-wl_x/exec", map[string]any{"Cmd": []string{"/bin/bash"}, "Tty": true, "Env": []string{"TERM=xterm-256color"}, "ConsoleSize": []uint{24, 80}}))
	refused(t, h2.do(http.MethodPost, "/v1.52/containers/"+strings.Repeat("4", 64)+"/exec", map[string]any{"Cmd": []string{"/bin/sh"}, "Tty": true}), "")
	// Execs are known only when the guard created them.
	refused(t, h2.do(http.MethodGet, "/v1.52/exec/"+strings.Repeat("9", 64)+"/json", nil), "no such exec")
	ok(t, h2.do(http.MethodGet, "/v1.52/exec/"+strings.Repeat("e", 64)+"/json", nil))
	refused(t, h2.do(http.MethodPost, "/v1.52/exec/"+strings.Repeat("e", 64)+"/start", map[string]any{"Detach": true, "Tty": true}), "detached")
}

func TestRouteParser(t *testing.T) {
	good := []string{
		"GET /_ping", "HEAD /_ping", "GET /v1.52/version", "GET /v1.52/info",
		"GET /v1.52/containers/json?all=1", "GET /v1.52/containers/abc/logs?stdout=1&stderr=1&timestamps=1&tail=all",
		"POST /v1.52/exec/" + strings.Repeat("a", 64) + "/resize?h=24&w=80",
	}
	for _, g := range good {
		m, u, _ := strings.Cut(g, " ")
		if _, err := parseRequest(httptest.NewRequestWithContext(context.Background(), m, "http://docker"+u, nil)); err != nil {
			t.Errorf("%s: %v", g, err)
		}
	}
	bad := []string{
		"POST /v1.52/containers/abc/kill", "POST /v1.52/containers/abc/attach", "POST /v1.52/build", "POST /v1.52/images/load",
		"GET /v1.52/containers/abc/export", "POST /v1.52/containers/abc/update", "POST /v1.52/swarm/init", "POST /v1.52/plugins/pull",
		"POST /v1.52/containers/prune", "POST /v1.52/commit", "GET /v1.52/events", "POST /v1.52/auth", "PUT /v1.52/volumes/x",
		"GET /v1.52/containers/../../etc/json", "GET /v1.52/containers/a%2Fb/json", "GET /v1.12/info", "GET /v1.52/info?x=1",
		"GET /v1.52/containers/json?all=1&all=0", "POST /v1.52/exec/abc/start", "GET /v1.52/containers/-x/json",
	}
	for _, b := range bad {
		m, u, _ := strings.Cut(b, " ")
		if _, err := parseRequest(httptest.NewRequestWithContext(context.Background(), m, "http://docker"+u, nil)); err == nil {
			t.Errorf("%s was accepted", b)
		}
	}
}

func TestCheckTar(t *testing.T) {
	var buf bytes.Buffer
	if _, err := checkTar(&buf, "/", []string{"/data"}); err != nil {
		t.Fatalf("empty archive: %v", err)
	}
	for _, name := range []string{"usr/bin/caddy", "data/../usr/bin/x", "etc/passwd"} {
		if _, err := checkTar(tarOf(t, name), "/", []string{"/data"}); err == nil {
			t.Errorf("%s passed", name)
		}
	}
	if _, err := checkTar(tarOf(t, "data/caddy/x.crt"), "/", []string{"/data"}); err != nil {
		t.Error(err)
	}
}

func TestStatusEndpoint(t *testing.T) {
	h := newHarness(t)
	w := h.do(http.MethodGet, StatusPath, nil)
	ok(t, w)
	var st Status
	if err := json.Unmarshal(w.Body.Bytes(), &st); err != nil || !st.Guard || st.Rootless {
		t.Fatalf("%s %v", w.Body.String(), err)
	}
	refused(t, h.do(http.MethodPost, EdgePortsPath, nil), "rootless")
}

func tarOf(t *testing.T, name string) *bytes.Buffer {
	t.Helper()
	var buf bytes.Buffer
	tw := tar.NewWriter(&buf)
	if err := tw.WriteHeader(&tar.Header{Typeflag: tar.TypeReg, Name: name, Mode: 0o600, Size: 1}); err != nil {
		t.Fatal(err)
	}
	_, _ = tw.Write([]byte("x"))
	_ = tw.Close()
	return &buf
}

// docs/22 §7.3: container changes and processes, workload containers only,
// by full id, no query (ps_args refused).
func TestInspectionRoutes(t *testing.T) {
	h := newHarness(t)
	wl := strings.Repeat("1", 64)
	h.fake.addContainer(wl, "lr-wl_x", managed())
	sys := managed()
	sys[labelSystem] = policy.SystemCaddy
	caddy := strings.Repeat("5", 64)
	h.fake.addContainer(caddy, caddyName, sys)
	helper := managed()
	helper[labelHelper] = "volume"
	h.fake.addContainer(strings.Repeat("6", 64), "lr-vol-0123456789ab", helper)
	upd := map[string]string{labelManaged: "true", labelRole: roleUpdater, "launchroom.update-task": "tsk_01J9Z8X7W6V5T4S3R2Q1P0N9MK"}
	h.fake.addContainer(strings.Repeat("7", 64), "lr-system-update-01j9z8x7w6v5t4s3r2q1p0n9mk", upd)
	h.fake.addContainer(strings.Repeat("2", 64), "postgres", map[string]string{"com.docker.compose.service": "postgres"})
	other := map[string]string{labelManaged: "true", labelServer: "srv_OTHER"}
	h.fake.addContainer(strings.Repeat("8", 64), "lr-wl_other", other)

	for _, suffix := range []string{"/changes", "/top"} {
		ok(t, h.do(http.MethodGet, "/v1.52/containers/"+wl+suffix, nil))
		if r, _ := h.fake.last(); r.URL.Path != "/v1.52/containers/"+wl+suffix || r.URL.RawQuery != "" {
			t.Fatalf("forwarded %s", r.URL)
		}
		// A name instead of the full id.
		refused(t, h.do(http.MethodGet, "/v1.52/containers/lr-wl_x"+suffix, nil), "full id")
		refused(t, h.do(http.MethodGet, "/v1.52/containers/"+wl[:12]+suffix, nil), "full id")
		// Other classes and foreign containers.
		refused(t, h.do(http.MethodGet, "/v1.52/containers/"+caddy+suffix, nil), "workload")
		refused(t, h.do(http.MethodGet, "/v1.52/containers/"+strings.Repeat("6", 64)+suffix, nil), "workload")
		refused(t, h.do(http.MethodGet, "/v1.52/containers/"+strings.Repeat("7", 64)+suffix, nil), "workload")
		refused(t, h.do(http.MethodGet, "/v1.52/containers/"+strings.Repeat("2", 64)+suffix, nil), NotManagedMarker)
		refused(t, h.do(http.MethodGet, "/v1.52/containers/"+strings.Repeat("8", 64)+suffix, nil), "")
		refused(t, h.do(http.MethodGet, "/v1.52/containers/"+strings.Repeat("9", 64)+suffix, nil), "no such container")
		// Extra query keys, writes.
		refused(t, h.do(http.MethodGet, "/v1.52/containers/"+wl+suffix+"?x=1", nil), "not allowed")
		refused(t, h.do(http.MethodPost, "/v1.52/containers/"+wl+suffix, nil), "not an allowed")
	}
	for _, args := range []string{"aux", "-ef", "", "-o%20pid", "--pid%201", "-e%20-o%20args"} {
		refused(t, h.do(http.MethodGet, "/v1.52/containers/"+wl+"/top?ps_args="+args, nil), "ps_args")
	}
}
