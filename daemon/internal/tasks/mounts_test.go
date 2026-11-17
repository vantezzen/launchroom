package tasks

import (
	"context"
	"crypto/ecdh"
	"crypto/rand"
	"strings"
	"sync"
	"sync/atomic"
	"testing"
	"time"

	"github.com/moby/moby/api/types/mount"

	"github.com/vantezzen/launchroom/daemon/internal/docker"
	"github.com/vantezzen/launchroom/daemon/internal/docker/dockerfake"
	"github.com/vantezzen/launchroom/daemon/internal/names"
	"github.com/vantezzen/launchroom/daemon/internal/policy"
	"github.com/vantezzen/launchroom/daemon/internal/state"
	"github.com/vantezzen/launchroom/daemon/internal/tasks/taskstest"
)

const (
	mVol    = state.VolumeID("vol_01J9Z8X7W6V5T4S3R2Q1P0N9V1")
	mVolLR  = state.HostName("lr-v_0123456789abcdef")
	mSvc    = state.ServiceID("svc_01J9Z8X7W6V5T4S3R2Q1P0N9N2")
	mEnv    = state.EnvironmentID("env_01J9Z8X7W6V5T4S3R2Q1P0N9N4")
	mOther  = state.ServiceID("svc_01J9Z8X7W6V5T4S3R2Q1P0N9N7")
	mOthEnv = state.EnvironmentID("env_01J9Z8X7W6V5T4S3R2Q1P0N9N8")
)

func mountLabels(svc state.ServiceID, env state.EnvironmentID) map[string]string {
	l := docker.ManagedLabels(string(bServer))
	l[names.LabelVolume] = string(mVol)
	if svc != "" {
		l[names.LabelService] = string(svc)
		l[names.LabelEnvironment] = string(env)
	}
	return l
}

type mountHarness struct {
	d      *dockerfake.Fake
	h      Handler
	priv   *ecdh.PrivateKey
	ns     atomic.Pointer[state.NodeState]
	mu     sync.Mutex
	mounts []mount.Mount
}

func newMountHarness(t *testing.T) *mountHarness {
	t.Helper()
	priv, err := ecdh.X25519().GenerateKey(rand.Reader)
	if err != nil {
		t.Fatal(err)
	}
	m := &mountHarness{d: dockerfake.New(string(bServer)), priv: priv}
	m.d.ExitOnStart[names.RunOnceContainer(bTask)] = 0
	m.d.OnCreate = func(cc docker.ContainerCreate) {
		m.mu.Lock()
		defer m.mu.Unlock()
		m.mounts = append(m.mounts, cc.HostConfig.Mounts...)
	}
	m.h = RunOnceHandler(RunOnceOptions{
		ServerID: bServer, SealingKey: priv, Docker: m.d, Client: &taskstest.ControlPlane{}, Policy: policy.DefaultConfig(),
		PollEvery: 10 * time.Millisecond, VolumeWait: 300 * time.Millisecond, NodeState: m.ns.Load,
	})
	return m
}

func (m *mountHarness) task(t *testing.T) state.Task {
	t.Helper()
	task := runOnceTask(t, m.priv.PublicKey(), "1m")
	task.Spec.(*state.RunOnceSpec).Mounts = []state.RunOnceMount{{Volume: mVol, Target: "/app/storage"}}
	return task
}

func declared(vs state.VolumeSpec, wls ...state.WorkloadSpec) *state.NodeState {
	return &state.NodeState{Volumes: []state.VolumeSpec{vs}, Workloads: wls}
}

func TestRunOnceMountsDeclaredVolume(t *testing.T) {
	m := newMountHarness(t)
	m.d.AddVolume(string(mVolLR), mountLabels(mSvc, mEnv))
	m.ns.Store(declared(state.VolumeSpec{ID: mVol, Name: mVolLR},
		state.WorkloadSpec{Service: mSvc, Environment: mEnv, Mounts: []state.Mount{{Volume: mVol, Target: "/app/storage"}}}))

	if _, err := m.h(context.Background(), m.task(t)); err != nil {
		t.Fatal(err)
	}
	if len(m.mounts) != 1 || m.mounts[0].Source != string(mVolLR) || m.mounts[0].Target != "/app/storage" || m.mounts[0].Type != mount.TypeVolume {
		t.Fatalf("mounts = %+v", m.mounts)
	}
}

func TestRunOnceMountsPreparedVolumeAfterWait(t *testing.T) {
	m := newMountHarness(t)
	// The task arrives before the NodeState that declares the volume and
	// before the reconciler created it (an owner-less prepared volume).
	go func() {
		time.Sleep(50 * time.Millisecond)
		m.ns.Store(declared(state.VolumeSpec{ID: mVol, Name: mVolLR}))
		time.Sleep(50 * time.Millisecond)
		m.d.AddVolume(string(mVolLR), mountLabels("", ""))
	}()
	if _, err := m.h(context.Background(), m.task(t)); err != nil {
		t.Fatal(err)
	}
	if len(m.mounts) != 1 || m.mounts[0].Source != string(mVolLR) {
		t.Fatalf("mounts = %+v", m.mounts)
	}
}

func TestRunOnceMountsRefused(t *testing.T) {
	cases := []struct {
		name   string
		ns     *state.NodeState
		labels map[string]string
		want   string
	}{
		{"not declared", &state.NodeState{}, mountLabels(mSvc, mEnv), "isn't declared"},
		{"not created", declared(state.VolumeSpec{ID: mVol, Name: mVolLR}), nil, "isn't created"},
		{"system volume", declared(state.VolumeSpec{ID: mVol, Name: "lr-caddy-data"}), mountLabels(mSvc, mEnv), "not a workload volume"},
		{"shared volume of another service", declared(state.VolumeSpec{ID: mVol, Name: mVolLR, Service: mOther}), mountLabels(mOther, mEnv), "belongs to another service"},
		{"mounted by another service", declared(state.VolumeSpec{ID: mVol, Name: mVolLR},
			state.WorkloadSpec{Service: mOther, Environment: mEnv, Mounts: []state.Mount{{Volume: mVol, Target: "/data"}}}), mountLabels(mOther, mEnv), "another service or environment"},
		{"mounted in another environment", declared(state.VolumeSpec{ID: mVol, Name: mVolLR},
			state.WorkloadSpec{Service: mSvc, Environment: mOthEnv, Mounts: []state.Mount{{Volume: mVol, Target: "/data"}}}), mountLabels(mSvc, mOthEnv), "another service or environment"},
		{"labels name another service", declared(state.VolumeSpec{ID: mVol, Name: mVolLR}), mountLabels(mOther, mEnv), "belongs to another service"},
	}
	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			m := newMountHarness(t)
			if tc.labels != nil {
				m.d.AddVolume(string(mVolLR), tc.labels)
				m.d.AddVolume("lr-caddy-data", tc.labels)
			}
			m.ns.Store(tc.ns)
			_, err := m.h(context.Background(), m.task(t))
			if err == nil || !strings.Contains(err.Error(), tc.want) {
				t.Fatalf("err = %v, want %q", err, tc.want)
			}
			if len(m.mounts) != 0 || strings.Contains(strings.Join(m.d.Calls(), "\n"), "create lr-run-") {
				t.Fatalf("a container was created: %v", m.d.Calls())
			}
		})
	}
}

func TestRunOnceMountsNeedNodeState(t *testing.T) {
	m := newMountHarness(t)
	h := RunOnceHandler(RunOnceOptions{ServerID: bServer, SealingKey: m.priv, Docker: m.d, Client: &taskstest.ControlPlane{}, Policy: policy.DefaultConfig(), PollEvery: 10 * time.Millisecond})
	if _, err := h(context.Background(), m.task(t)); err == nil || !strings.Contains(err.Error(), "can't mount volumes") {
		t.Fatalf("err = %v", err)
	}
}

// A release on a fresh volume runs before any workload started on it: the
// volume is given to the owner the service's workloads declare (G76), once,
// and only while it is empty.
func TestRunOnceMountsInitOwner(t *testing.T) {
	owner := &state.FileOwner{UID: 1000, GID: 1000}
	wl := state.WorkloadSpec{Service: mSvc, Environment: mEnv, Mounts: []state.Mount{{Volume: mVol, Target: "/app/storage", InitOwner: owner}}}

	t.Run("empty volume", func(t *testing.T) {
		m := newMountHarness(t)
		m.d.AddVolume(string(mVolLR), mountLabels(mSvc, mEnv))
		m.ns.Store(declared(state.VolumeSpec{ID: mVol, Name: mVolLR}, wl))
		if _, err := m.h(context.Background(), m.task(t)); err != nil {
			t.Fatal(err)
		}
		if root := m.d.VolumeRootOf(string(mVolLR)); root.UID != 1000 || root.GID != 1000 {
			t.Fatalf("root = %+v", root)
		}
		cs, _ := m.d.ListContainers(context.Background(), nil)
		for _, c := range cs {
			if strings.HasPrefix(c.Name, "lr-vol-") {
				t.Fatalf("helper left: %s", c.Name)
			}
		}
	})

	t.Run("volume with files", func(t *testing.T) {
		m := newMountHarness(t)
		m.d.AddVolume(string(mVolLR), mountLabels(mSvc, mEnv))
		m.d.SetVolumeRoot(string(mVolLR), dockerfake.VolumeRoot{Mode: 0o755, Files: []string{"database.sqlite"}})
		m.ns.Store(declared(state.VolumeSpec{ID: mVol, Name: mVolLR}, wl))
		if _, err := m.h(context.Background(), m.task(t)); err != nil {
			t.Fatal(err)
		}
		if root := m.d.VolumeRootOf(string(mVolLR)); root.UID != 0 {
			t.Fatalf("root = %+v", root)
		}
	})

	t.Run("read-only mount or no owner declared", func(t *testing.T) {
		for _, tc := range []struct {
			name     string
			wl       state.WorkloadSpec
			readOnly bool
		}{
			{"read-only task mount", wl, true},
			{"no owner", state.WorkloadSpec{Service: mSvc, Environment: mEnv, Mounts: []state.Mount{{Volume: mVol, Target: "/app/storage"}}}, false},
		} {
			m := newMountHarness(t)
			m.d.AddVolume(string(mVolLR), mountLabels(mSvc, mEnv))
			m.ns.Store(declared(state.VolumeSpec{ID: mVol, Name: mVolLR}, tc.wl))
			task := m.task(t)
			task.Spec.(*state.RunOnceSpec).Mounts[0].ReadOnly = tc.readOnly
			if _, err := m.h(context.Background(), task); err != nil {
				t.Fatal(err)
			}
			if root := m.d.VolumeRootOf(string(mVolLR)); root.UID != 0 {
				t.Fatalf("%s: root = %+v", tc.name, root)
			}
		}
	})
}
