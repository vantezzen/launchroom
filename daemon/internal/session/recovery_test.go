package session

import (
	"bytes"
	"context"
	"debug/elf"
	"errors"
	"log/slog"
	"net/netip"
	"slices"
	"strings"
	"testing"

	"github.com/moby/moby/api/types/container"
	"github.com/moby/moby/api/types/mount"

	"github.com/vantezzen/launchroom/daemon/internal/docker"
	"github.com/vantezzen/launchroom/daemon/internal/docker/dockerfake"
	"github.com/vantezzen/launchroom/daemon/internal/names"
	"github.com/vantezzen/launchroom/daemon/internal/policy"
	"github.com/vantezzen/launchroom/daemon/internal/secret"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

func TestIdleELF(t *testing.T) {
	for arch, machine := range map[string]elf.Machine{"amd64": elf.EM_X86_64, "arm64": elf.EM_AARCH64} {
		b, err := IdleELF(arch)
		if err != nil {
			t.Fatal(err)
		}
		f, err := elf.NewFile(bytes.NewReader(b))
		if err != nil {
			t.Fatalf("%s: %v", arch, err)
		}
		if f.Machine != machine || f.Type != elf.ET_EXEC || f.Class != elf.ELFCLASS64 || len(f.Progs) != 1 {
			t.Fatalf("%s: %+v", arch, f.FileHeader)
		}
		p := f.Progs[0]
		if p.Type != elf.PT_LOAD || p.Flags != elf.PF_R|elf.PF_X || f.Entry < p.Vaddr || f.Entry >= p.Vaddr+p.Filesz || p.Filesz != uint64(len(b)) {
			t.Fatalf("%s: %+v entry %#x", arch, p.ProgHeader, f.Entry)
		}
	}
	if _, err := IdleELF("riscv64"); err == nil {
		t.Fatal("riscv64 accepted")
	}
}

type dockerConfig = container.Config

func netipPrefix(t *testing.T, s string) netip.Prefix {
	t.Helper()
	p, err := netip.ParsePrefix(s)
	if err != nil {
		t.Fatal(err)
	}
	return p
}

// recoveryDocker is the fake engine plus the console operations.
type recoveryDocker struct{ *dockerfake.Fake }

func (recoveryDocker) PathExists(_ context.Context, _, p string) (bool, error) {
	return p == "/bin/sh", nil
}
func (recoveryDocker) ExecTTY(context.Context, string, docker.TTYOptions) (*docker.TTYExec, error) {
	return nil, errors.New("not in tests")
}

func (recoveryDocker) ExecStream(context.Context, string, docker.ExecOptions) (docker.ExecResult, error) {
	return docker.ExecResult{}, errors.New("not in tests")
}

const (
	recSrv = state.ServerID("srv_01J9Z8X7W6V5T4S3R2Q1P0N9MK")
	recNet = state.NetworkID("net_01J9Z8X7W6V5T4S3R2Q1P0N9N0")
	recVol = state.VolumeID("vol_01J9Z8X7W6V5T4S3R2Q1P0N9V1")
	recImg = state.ImageRef("127.0.0.1:5000/apps/a@sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa")
)

func recoveryState() *state.NodeState {
	port := state.Port(3000)
	return &state.NodeState{
		ServerID: recSrv,
		Networks: []state.NetworkSpec{{ID: recNet, Name: "lr-e_a", Subnet: "10.231.4.0/24"}},
		Volumes:  []state.VolumeSpec{{ID: recVol, Name: "lr-v_data"}},
		Workloads: []state.WorkloadSpec{{
			ID: wl, Service: "svc_01J9Z8X7W6V5T4S3R2Q1P0N9N2", Environment: "env_01J9Z8X7W6V5T4S3R2Q1P0N9N4", Deployment: "dpl_01J9Z8X7W6V5T4S3R2Q1P0N9N5",
			Image: recImg, Command: []string{"/app/server"}, Port: &port,
			Health:    &state.HealthSpec{Type: state.HealthHTTP, Path: "/", Interval: "5s", Timeout: "2s", FailureThreshold: 3, StartPeriod: "10s", RestartAfter: "5m"},
			Resources: state.Resources{CPUs: 1, MemoryMB: 256, Pids: 256},
			Networks:  []state.NetworkAttachment{{Network: recNet, Aliases: []state.Slug{"web"}}},
			Mounts:    []state.Mount{{Volume: recVol, Target: "/data"}},
			Restart:   state.RestartUnlessStopped, Role: state.RoleServing,
		}},
	}
}

func recoveryRun(t *testing.T, d recoveryDocker, allowChanges bool) *run {
	t.Helper()
	ns := recoveryState()
	return &run{
		o: Options{
			ServerID: recSrv, Docker: d,
			WorkloadEnv: func(state.WorkloadID) (map[string]secret.Secret, error) {
				return map[string]secret.Secret{"DATABASE_URL": secret.Secret("postgres://x")}, nil
			},
			Recovery: &Recovery{NodeState: func() *state.NodeState { return ns }, Policy: policy.DefaultConfig()},
		},
		t:    state.Task{ID: "tsk_01J9Z8X7W6V5T4S3R2Q1P0N9S1"},
		spec: &state.SessionSpec{Session: sid, Kind: state.SessionRecovery, Workload: wl, AllowChanges: allowChanges},
		log:  slog.New(slog.DiscardHandler),
		res:  &state.SessionResult{},
	}
}

func newRecoveryDocker(t *testing.T) recoveryDocker {
	t.Helper()
	f := dockerfake.New(string(recSrv))
	f.AddImage(string(recImg))
	f.Arch = "arm64"
	labels := docker.ManagedLabels(string(recSrv))
	labels[names.LabelNetwork] = string(recNet)
	if _, err := f.CreateNetwork(t.Context(), docker.NetworkCreate{Name: "lr-e_a", Subnet: netipPrefix(t, "10.231.4.0/24"), Labels: labels}); err != nil {
		t.Fatal(err)
	}
	return recoveryDocker{f}
}

// G2 (OD9): the recovery container is the workload's spec with the idle
// program instead of the app: no alias, no restart, volumes read-only, the
// idle program copied in; it is removed when the session ends, and
// leftovers of another run are swept.
func TestRecoveryContainer(t *testing.T) {
	ctx := t.Context()
	d := newRecoveryDocker(t)
	leftover, err := d.CreateContainer(ctx, &docker.ContainerCreate{Name: RecoveryPrefix + "ses_old", Config: &dockerConfig{Image: string(recImg), Labels: docker.ManagedLabels(string(recSrv))}, HostConfig: policy.BaseHostConfig()})
	if err != nil {
		t.Fatal(err)
	}
	r := recoveryRun(t, d, false)
	if err := r.prepare(ctx); err != nil {
		t.Fatal(err)
	}
	if _, err := d.InspectContainer(ctx, leftover); !errors.Is(err, docker.ErrNotFound) {
		t.Fatalf("leftover kept: %v", err)
	}
	name := RecoveryContainer(sid)
	cc, ok := d.Create(name)
	if !ok {
		t.Fatal("no recovery container")
	}
	if !slices.Equal(cc.Config.Entrypoint, []string{idlePath}) || len(cc.Config.Cmd) != 0 || cc.HostConfig.RestartPolicy.Name != "no" ||
		len(cc.Config.ExposedPorts) != 0 || len(cc.HostConfig.PortBindings) != 0 || cc.Config.Labels[names.LabelWorkload] != "" ||
		cc.Config.Labels[names.LabelTask] != "tsk_01J9Z8X7W6V5T4S3R2Q1P0N9S1" || !slices.Contains(cc.Config.Env, "DATABASE_URL=postgres://x") {
		t.Fatalf("config %+v host %+v", cc.Config, cc.HostConfig)
	}
	if len(cc.HostConfig.Mounts) != 1 || !cc.HostConfig.Mounts[0].ReadOnly || cc.HostConfig.Mounts[0].Type != mount.TypeVolume {
		t.Fatalf("mounts %+v", cc.HostConfig.Mounts)
	}
	for _, ep := range cc.Networking.EndpointsConfig {
		if len(ep.Aliases) != 0 {
			t.Fatalf("aliases %v", ep.Aliases)
		}
	}
	calls := strings.Join(d.Calls(), "\n")
	if !strings.Contains(calls, "put "+name+" /.launchroom-idle") || !strings.Contains(calls, "start "+name) || r.shell != "/bin/sh" {
		t.Fatalf("calls %s shell %q", calls, r.shell)
	}
	r.cleanup()
	if _, ok := d.Create(name); ok {
		t.Fatal("recovery container not removed")
	}
}

// Writable volumes only while the service isn't serving.
func TestRecoveryRefusesWritableNextToARunningService(t *testing.T) {
	ctx := t.Context()
	d := newRecoveryDocker(t)
	labels := docker.ManagedLabels(string(recSrv))
	labels[names.LabelWorkload] = string(wl)
	id, err := d.CreateContainer(ctx, &docker.ContainerCreate{Name: "lr-wl", Config: &dockerConfig{Image: string(recImg), Labels: labels}, HostConfig: policy.BaseHostConfig()})
	if err != nil {
		t.Fatal(err)
	}
	if err := d.StartContainer(ctx, id); err != nil {
		t.Fatal(err)
	}
	if err := recoveryRun(t, d, true).prepare(ctx); !errors.Is(err, ErrRefused) {
		t.Fatalf("err = %v", err)
	}
	if _, ok := d.Create(RecoveryContainer(sid)); ok {
		t.Fatal("created anyway")
	}
	// Crash-looping (restarting): allowed, writable.
	d.Exit(id, 1, false, true)
	r := recoveryRun(t, d, true)
	if err := r.prepare(ctx); err != nil {
		t.Fatal(err)
	}
	cc, _ := d.Create(RecoveryContainer(sid))
	if cc.HostConfig.Mounts[0].ReadOnly {
		t.Fatal("read-only although changes are allowed")
	}
	r.cleanup()
	// Not configured: refused as unavailable.
	r = recoveryRun(t, d, false)
	r.o.Recovery = nil
	var se *state.StartError
	if err := r.prepare(ctx); !errors.As(err, &se) || se.Code != state.FailKindDisabled {
		t.Fatalf("err = %v", err)
	}
	if fs := FeaturesFor(Options{Docker: d}); slices.Contains(fs, state.FeatureRecoverySessions) {
		t.Fatalf("announced without configuration: %v", fs)
	}
	if fs := FeaturesFor(Options{Docker: d, Recovery: &Recovery{NodeState: recoveryState}}); !slices.Contains(fs, state.FeatureRecoverySessions) {
		t.Fatalf("features %v", fs)
	}
}
