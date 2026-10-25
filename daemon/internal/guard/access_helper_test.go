package guard

import (
	"encoding/json"
	"net/http"
	"strings"
	"testing"
	"time"

	"github.com/moby/moby/api/types/container"
	"github.com/moby/moby/api/types/mount"

	"github.com/vantezzen/launchroom/daemon/internal/access"
	"github.com/vantezzen/launchroom/daemon/internal/policy"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// refusedShell is a shell the guard must refuse to exec in an access helper
// (a constant, so TestNoShellInvocations sees no shell call here).
const refusedShell = "sh"

const accessTestVolume = "lr-v_0123456789abcdef"

func accessHelper(t *testing.T) (string, container.CreateRequest) {
	t.Helper()
	cc := access.HelperSpec(testServer, "acc_01J9Z8X7W6V5T4S3R2Q1P0N9A1", state.ImageRef("docker.io/library/busybox@"+policy.FilesImageDigest), accessTestVolume, "999:999", false, 2*time.Hour)
	return cc.Name, container.CreateRequest{Config: cc.Config, HostConfig: cc.HostConfig}
}

func TestAccessHelperConstantsMatch(t *testing.T) {
	if accessHelperMount != access.HelperMount || int(access.HelperMaxLifetime/time.Second) != accessHelperMaxSleep || access.HelperKind != "access" || access.LabelAccess != "launchroom.access" {
		t.Fatal("guard constants differ from internal/access")
	}
}

// The volume SFTP helper (D67) passes exactly in launchroomd's shape.
func TestAccessHelperShape(t *testing.T) {
	h := newHarness(t)
	h.fake.addVolume(accessTestVolume, managed())
	check := func(name string, r container.CreateRequest) error {
		b, err := json.Marshal(r)
		if err != nil {
			t.Fatal(err)
		}
		return h.srv.CheckCreate(t.Context(), name, b)
	}
	name, base := accessHelper(t)
	if err := check(name, base); err != nil {
		t.Fatalf("built helper refused: %v", err)
	}
	for what, c := range map[string]struct {
		mut  func(n *string, r *container.CreateRequest)
		want string
	}{
		"not generated": {func(n *string, _ *container.CreateRequest) { *n = "lr-acc-mine" }, "not generated"},
		"no access id":  {func(_ *string, r *container.CreateRequest) { delete(r.Labels, "launchroom.access") }, "access id"},
		"another image": {func(_ *string, r *container.CreateRequest) { r.Image = "docker.io/library/busybox@" + testDigest }, "pinned files image"},
		"a shell": {func(_ *string, r *container.CreateRequest) {
			r.Entrypoint = []string{"sh"}
			r.Cmd = []string{"-c", "x"}
		}, "sleep"},
		"sleeps too long": {func(_ *string, r *container.CreateRequest) { r.Cmd = []string{"99999"} }, "sleep"},
		"a user name":     {func(_ *string, r *container.CreateRequest) { r.User = "root" }, "numeric"},
		"network": {func(_ *string, r *container.CreateRequest) {
			r.HostConfig.NetworkMode = "bridge"
			r.NetworkDisabled = false
		}, "no network"},
		"a capability":  {func(_ *string, r *container.CreateRequest) { r.HostConfig.CapAdd = []string{"DAC_OVERRIDE"} }, "drop every capability"},
		"writable root": {func(_ *string, r *container.CreateRequest) { r.HostConfig.ReadonlyRootfs = false }, "read-only root"},
		"no init":       {func(_ *string, r *container.CreateRequest) { r.HostConfig.Init = nil }, "read-only root"},
		"privileges":    {func(_ *string, r *container.CreateRequest) { r.HostConfig.SecurityOpt = nil }, "no-new-privileges"},
		"no limits":     {func(_ *string, r *container.CreateRequest) { r.HostConfig.PidsLimit = nil }, "limits"},
		"restarts":      {func(_ *string, r *container.CreateRequest) { r.HostConfig.RestartPolicy.Name = "always" }, "never restarted"},
		"environment":   {func(_ *string, r *container.CreateRequest) { r.Env = []string{"A=b"} }, "environment"},
		"a second volume": {func(_ *string, r *container.CreateRequest) {
			r.HostConfig.Mounts = append(r.HostConfig.Mounts, r.HostConfig.Mounts[0])
		}, "exactly one volume"},
		"elsewhere":       {func(_ *string, r *container.CreateRequest) { r.HostConfig.Mounts[0].Target = "/etc" }, "exactly one volume"},
		"a system volume": {func(_ *string, r *container.CreateRequest) { r.HostConfig.Mounts[0].Source = "lr-system-caddy-data" }, "not a launchroom workload volume"},
		"a bind mount": {func(_ *string, r *container.CreateRequest) {
			r.HostConfig.Mounts[0] = mount.Mount{Type: mount.TypeBind, Source: "/", Target: "/data"}
		}, "named volume"},
		"privileged": {func(_ *string, r *container.CreateRequest) { r.HostConfig.Privileged = true }, "Privileged"},
	} {
		n, r := accessHelper(t)
		c.mut(&n, &r)
		err := check(n, r)
		if err == nil || !strings.Contains(err.Error(), c.want) {
			t.Errorf("%s: %v (want %q)", what, err, c.want)
		}
	}
}

// Execs into an access helper run only the SFTP file tools: no shell, no
// TTY, no other user, no environment. The helper may be started.
func TestAccessHelperExecAndStart(t *testing.T) {
	h := newHarness(t)
	l := managed()
	l[labelHelper] = "access"
	l["launchroom.access"] = "acc_01J9Z8X7W6V5T4S3R2Q1P0N9A1"
	id := strings.Repeat("5", 64)
	h.fake.addContainer(id, "lr-acc-0123456789ab", l)
	for _, tool := range accessHelperTools {
		ok(t, h.do(http.MethodPost, "/v1.52/containers/"+id+"/exec", map[string]any{"Cmd": []string{tool, "--", "/data/x"}, "AttachStdout": true}))
	}
	ok(t, h.do(http.MethodPost, "/v1.52/containers/"+id+"/exec", map[string]any{"Cmd": []string{"dd", "of=/data/x", "bs=65536"}, "AttachStdin": true}))
	for name, body := range map[string]map[string]any{
		"sh":      {"Cmd": []string{refusedShell, "-c", "id"}},
		"busybox": {"Cmd": []string{"busybox", "sh"}},
		"path":    {"Cmd": []string{"/bin/sh"}},
		"tty":     {"Cmd": []string{"ls"}, "Tty": true},
		"user":    {"Cmd": []string{"ls"}, "User": "0:0"},
		"env":     {"Cmd": []string{"ls"}, "Env": []string{"PATH=/tmp"}},
	} {
		w := h.do(http.MethodPost, "/v1.52/containers/"+id+"/exec", body)
		if w.Code < 400 {
			t.Errorf("%s: allowed", name)
		}
	}
	ok(t, h.do(http.MethodPost, "/v1.52/containers/"+id+"/start", nil))
	// No uploads into it either (files go through SFTP, as the volume's owner).
	w := h.do(http.MethodPut, "/v1.52/containers/"+id+"/archive?path=/data", tarOf(t, "x"))
	if w.Code < 400 {
		t.Error("archive upload into an access helper allowed")
	}
}
