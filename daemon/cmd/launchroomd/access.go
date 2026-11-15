package main

import (
	"context"
	"errors"
	"net/netip"
	"time"

	"github.com/vantezzen/launchroom/daemon/internal/access"
	"github.com/vantezzen/launchroom/daemon/internal/docker"
	"github.com/vantezzen/launchroom/daemon/internal/hostinfo"
	"github.com/vantezzen/launchroom/daemon/internal/names"
	"github.com/vantezzen/launchroom/daemon/internal/policy"
	"github.com/vantezzen/launchroom/daemon/internal/state"
	"github.com/vantezzen/launchroom/daemon/internal/tasks"
)

// Temporary remote access (docs/03 §12, D61): its own runner, heartbeats
// every 5 s that carry the access status, fail closed after two failed ones.
const (
	accessSlots     = 8
	accessHeartbeat = 5 * time.Second
	accessHBFails   = 2
)

func (rt *daemonRuntime) accessAllowed(k state.AccessKind) bool {
	return rt.cfg.RemoteAccessAllowed(string(k))
}

func newAccessManager(rt *daemonRuntime) *access.Manager {
	return access.NewManager(access.ManagerOptions{
		Ports: rt.cfg.RemoteAccessPorts, DataDir: rt.cfg.DataDir,
		SyncFirewall: func(ctx context.Context) error {
			if rt.firewall == nil {
				return nil
			}
			return rt.firewall.Sync(ctx)
		},
		FirewallEnforced: func() bool { return rt.firewall != nil && rt.firewall.Enforced() },
		PublicIPv4: func() netip.Addr {
			if rt.publicIP == nil {
				return netip.Addr{}
			}
			if p := rt.publicIP.Snapshot(); p != nil {
				if a, err := netip.ParseAddr(string(p.IPv4)); err == nil {
					return a
				}
			}
			return netip.Addr{}
		},
		Log: rt.log.With("component", "access"),
	})
}

// accessRunner returns the runner for "access" tasks, or nil when the local
// policy turns every kind off or the host can't publish a port.
func (rt *daemonRuntime) accessRunner(taskInterval time.Duration) *tasks.Runner {
	if rt.access == nil || !rt.accessAllowed(state.AccessSSH) && !rt.accessAllowed(state.AccessDatabase) && !rt.accessAllowed(state.AccessVolume) {
		rt.log.Info("temporary remote access is off on this server", "env", "LR_REMOTE_ACCESS")
		return nil
	}
	dc, ok := rt.docker.(access.Docker)
	if !ok {
		return nil
	}
	h := access.Handler(access.Options{
		ServerID: rt.serverID, SealingKey: rt.sealingKey, Docker: dc, Manager: rt.access,
		Workload: func(id state.WorkloadID) (*state.WorkloadSpec, bool) {
			w := rt.workloadSpec(id)
			return w, w != nil
		},
		WorkloadEnv: rt.workloadEnv,
		Allowed: func(k state.AccessKind) bool {
			return rt.accessAllowed(k) && rt.accessPublishable()
		},
		CheckImage: func(ref state.ImageRef) error {
			return policy.CheckSystemImage(names.SystemFiles, ref, rt.policy)
		},
		Log: rt.log.With("component", "access"),
	})
	return tasks.New(tasks.Options{
		Client: rt.client, Interval: taskInterval, Log: rt.log.With("component", "access"),
		Handlers:       map[state.TaskType]tasks.Handler{state.TaskAccess: h},
		MaxConcurrent:  accessSlots,
		HeartbeatEvery: accessHeartbeat, MaxHeartbeatFailures: accessHBFails,
	})
}

// accessPublishable: a rootful Linux host (not a VM, not rootless), whose
// ports are the server's own (docs/05 §8).
func (rt *daemonRuntime) accessPublishable() bool {
	rt.hostRT.mu.Lock()
	f := rt.hostRT.facts
	rt.hostRT.mu.Unlock()
	if f == nil || f.Rootless {
		return false
	}
	hostOS := hostinfo.HostOS(rt.cfg.HostOS, f.Engine)
	return hostOS == state.HostLinux && f.Engine != state.EngineDockerDesktop
}

// accessFeatures are the access kinds announced in reports.
func (rt *daemonRuntime) accessFeatures() []state.DaemonFeature {
	if rt.access == nil || !rt.accessPublishable() {
		return nil
	}
	if _, ok := rt.docker.(access.Docker); !ok {
		return nil
	}
	var out []state.DaemonFeature
	if rt.accessAllowed(state.AccessSSH) {
		out = append(out, state.FeatureRemoteAccessSSH)
	}
	if rt.accessAllowed(state.AccessDatabase) {
		out = append(out, state.FeatureRemoteAccessDatabase)
	}
	if _, ok := rt.docker.(access.VolumeDocker); ok && rt.accessAllowed(state.AccessVolume) {
		out = append(out, state.FeatureRemoteAccessVolume)
	}
	return out
}

// sweepAccessHelpers removes volume access helpers no running access owns
// (docs/03 §12.4): at start every one, later those a crash left behind.
func (rt *daemonRuntime) sweepAccessHelpers(ctx context.Context) {
	if rt.access == nil || rt.docker == nil {
		return
	}
	access.SweepHelpers(ctx, rt.docker, rt.access.Running)
}

// sweepAccessRoles drops temporary roles a crash or restart left behind
// (docs/03 §12.3): through the service's current replica on this server.
// A service that is no longer here takes its roles with it (forgotten).
func (rt *daemonRuntime) sweepAccessRoles(ctx context.Context) {
	if rt.access == nil {
		return
	}
	dc, ok := rt.docker.(access.Docker)
	if !ok {
		return
	}
	cur := rt.store.Current()
	if cur == nil {
		return
	}
	rt.access.Sweep(ctx, func(ctx context.Context, e access.LedgerEntry) error {
		var wl *state.WorkloadSpec
		for i := range cur.State.Workloads {
			w := &cur.State.Workloads[i]
			if w.ID == e.Workload || e.Service != "" && w.Service == e.Service && w.Environment == e.Environment {
				wl = w
				if w.ID == e.Workload {
					break
				}
			}
		}
		if wl == nil {
			return nil
		}
		env, err := rt.workloadEnv(wl.ID)
		if err != nil {
			return err
		}
		c, err := docker.WorkloadContainer(ctx, dc, wl.ID)
		if err != nil {
			return err
		}
		if c == nil || !c.Running() {
			return errors.New("the database container is not running")
		}
		dctx, cancel := context.WithTimeout(ctx, 30*time.Second)
		defer cancel()
		return access.DropRole(dctx, access.NewContainer(dc, c.ID), e.Engine, env, e.Role)
	})
}
