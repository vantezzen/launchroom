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
	opts := session.Options{
		ServerID: rt.serverID, SealingKey: rt.sealingKey, Auth: rt.client,
		Nudge:           rt.sessionNudge,
		ControlPlaneURL: rt.cfg.ControlPlaneURL,
		// Follows an adopted canonical origin and its failover (docs/03 §4.2).
		ControlPlaneBase: rt.client.ActiveBase,
		Trusted:          func() session.Verifier { return rt.client.Trusted() },
		Docker:           dc,
		WorkloadEnv:      rt.workloadEnv,
		InDesiredState:   func(id state.WorkloadID) bool { return rt.workloadSpec(id) != nil },
		Allowed:          rt.sessionAllowed,
		Logs:             rt.client,
		DaemonVersion:    version,
		HTTPClient:       &http.Client{Transport: controlplane.DefaultTransport()},
		Log:              rt.log.With("component", "session"),
		// Recovery shells start from the verified NodeState under the same
		// policy floor as the reconciler (docs/03 §11).
		Recovery: &session.Recovery{NodeState: rt.verifiedState, Policy: rt.policy},
	}
	rt.addFeatures(func() []state.DaemonFeature { return session.FeaturesFor(opts) })
	h := session.Handler(opts)
	return tasks.New(tasks.Options{
		Client: rt.client, Interval: taskInterval, Log: rt.log.With("component", "sessions"),
		Handlers:       map[state.TaskType]tasks.Handler{state.TaskSession: h},
		MaxConcurrent:  sessionSlots,
		HeartbeatEvery: sessionHeartbeat, MaxHeartbeatFailures: sessionHBFails,
	})
}

// sessionAllowed applies LR_INTERACTIVE_SESSIONS to a session kind. A
// recovery shell is a console in a copy of the service's container, so it
// follows the console setting: "on" and "console" allow it, "data" and
// "off" don't.
func (rt *daemonRuntime) sessionAllowed(k state.SessionKind) bool {
	if k == state.SessionRecovery {
		k = state.SessionConsole
	}
	return rt.cfg.SessionAllowed(string(k))
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
