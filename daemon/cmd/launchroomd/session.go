package main

import (
	"context"
	"errors"
	"net/http"
	"time"

	"github.com/vantezzen/launchroom/daemon/internal/controlplane"
	"github.com/vantezzen/launchroom/daemon/internal/session"
	"github.com/vantezzen/launchroom/daemon/internal/state"
	"github.com/vantezzen/launchroom/daemon/internal/tasks"
)

// Interactive sessions (docs/03 §11): their own runner, so a console never
// waits behind a build; heartbeats every 10 s and fail closed after two
// failed ones.
const (
	sessionSlots     = 8
	sessionHeartbeat = 10 * time.Second
	sessionHBFails   = 2
)

// sessionRunner returns the runner for "session" tasks, or nil when the
// local policy turns them off or the Docker client cannot exec.
func (rt *daemonRuntime) sessionRunner(taskInterval time.Duration) *tasks.Runner {
	if rt.cfg.InteractiveSessions == "off" {
		rt.log.Info("interactive sessions are off on this server", "env", "LR_INTERACTIVE_SESSIONS")
		return nil
	}
	dc, ok := rt.docker.(session.Docker)
	if !ok {
		return nil
	}
	h := session.Handler(session.Options{
		ServerID: rt.serverID, SealingKey: rt.sealingKey, Auth: rt.client,
		Nudge:           rt.sessionNudge,
		ControlPlaneURL: rt.cfg.ControlPlaneURL,
		// Follows an adopted canonical origin and its failover (docs/03 §4.2).
		ControlPlaneBase: rt.client.ActiveBase,
		Trusted:          func() session.Verifier { return rt.client.Trusted() },
		Docker:           dc,
		WorkloadEnv:      rt.workloadEnv,
		InDesiredState:   rt.inDesiredState,
		Allowed:          func(k state.SessionKind) bool { return rt.cfg.SessionAllowed(string(k)) },
		Logs:             rt.client,
		DaemonVersion:    version,
		HTTPClient:       &http.Client{Transport: controlplane.DefaultTransport()},
		Log:              rt.log.With("component", "session"),
	})
	return tasks.New(tasks.Options{
		Client: rt.client, Interval: taskInterval, Log: rt.log.With("component", "sessions"),
		Handlers:       map[state.TaskType]tasks.Handler{state.TaskSession: h},
		MaxConcurrent:  sessionSlots,
		HeartbeatEvery: sessionHeartbeat, MaxHeartbeatFailures: sessionHBFails,
	})
}

// sessionNudge returns the dashboard's current Pusher settings, falling back
// to the enrollment value when the config endpoint is missing.
func (rt *daemonRuntime) sessionNudge(ctx context.Context) (*state.NudgeConfig, error) {
	cfg, err := rt.client.FetchConfig(ctx)
	if err == nil {
		return cfg.Nudge, nil
	}
	var api *controlplane.APIError
	if errors.As(err, &api) && api.Status == http.StatusNotFound {
		return rt.nudgeFallback, nil
	}
	return nil, err
}

// inDesiredState reports whether a workload is in the verified NodeState.
func (rt *daemonRuntime) inDesiredState(id state.WorkloadID) bool {
	cur := rt.store.Current()
	if cur == nil {
		return false
	}
	for i := range cur.State.Workloads {
		if cur.State.Workloads[i].ID == id {
			return true
		}
	}
	return false
}

// features is SupportedFeatures plus the session kinds the local policy allows.
func (rt *daemonRuntime) features() []state.DaemonFeature {
	out := append([]state.DaemonFeature(nil), state.SupportedFeatures...)
	if rt.dataTransfers() {
		out = append(out, state.FeatureDataTransfer)
	}
	out = append(out, rt.accessFeatures()...)
	if rt.ingress != nil && rt.ingress.WafSupport().Available() {
		// Only while the running, pinned Caddy carries the firewall module (docs/22 §6.1).
		out = append(out, state.FeatureWaf)
	}
	if _, ok := rt.docker.(session.Docker); !ok {
		return out
	}
	if rt.cfg.SessionAllowed(string(state.SessionConsole)) {
		out = append(out, state.FeatureConsoleSessions)
	}
	if rt.cfg.SessionAllowed(string(state.SessionData)) {
		out = append(out, state.FeatureDataSessions)
	}
	return out
}
