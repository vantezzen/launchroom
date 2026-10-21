package main

import (
	"context"
	"fmt"
	"log/slog"
	"net/http"
	"os"
	"time"

	"github.com/moby/moby/client"

	"github.com/vantezzen/launchroom/daemon/internal/state"
	"github.com/vantezzen/launchroom/daemon/internal/tasks"
	"github.com/vantezzen/launchroom/daemon/internal/update"
)

// processStart is when this launchroomd process started (data/update/running.json).
var processStart = time.Now()

// addUpdateHandler registers the self_update handler (docs/11 §4). It
// talks to Docker directly: launchroomd's own container is compose-managed,
// not one of the per-server objects the Engine may touch.
func (rt *daemonRuntime) addUpdateHandler(h map[state.TaskType]tasks.Handler) {
	api, err := client.New(client.FromEnv, client.WithUserAgent("launchroomd/"+version))
	if err != nil {
		rt.log.Warn("self-update disabled: no Docker client", "err", err)
		return
	}
	h[state.TaskSelfUpdate] = update.NewHandler(update.HandlerOptions{
		DataDir: rt.cfg.DataDir, Version: version, Docker: api,
		HTTP: &http.Client{Timeout: 5 * time.Minute}, Log: rt.log.With("component", "update"),
	})
}

// controlPlaneContact runs after every verified state fetch. The first one
// records this process as running (the health signal an update supervisor
// waits for) and reports an update a previous process started.
func (rt *daemonRuntime) controlPlaneContact() {
	rt.contactOnce.Do(func() {
		if err := update.MarkRunning(rt.cfg.DataDir, version, processStart); err != nil {
			rt.log.Warn("could not record the running version", "err", err)
		}
		api, err := client.New(client.FromEnv, client.WithUserAgent("launchroomd/"+version))
		if err != nil {
			api = nil
		}
		go update.Resume(context.Background(), update.ResumeOptions{
			DataDir: rt.cfg.DataDir, Docker: api, Complete: rt.client.Complete, Log: rt.log.With("component", "update"),
		})
	})
}

// runSupervisor is `launchroomd update-supervisor <plan>`: it runs in a
// short-lived helper container and performs the swap (internal/update).
func runSupervisor(args []string) int {
	log := slog.New(slog.NewJSONHandler(os.Stderr, nil)).With("component", "update-supervisor", "version", version)
	if len(args) != 1 {
		fmt.Fprintln(os.Stderr, "usage: launchroomd "+update.SupervisorCommand+" <plan.json>")
		return 2
	}
	api, err := client.New(client.FromEnv, client.WithUserAgent("launchroomd/"+version))
	if err != nil {
		log.Error("docker client", "err", err)
		return 1
	}
	s := &update.Supervisor{API: api, Log: log, Version: version}
	if err := s.RunSupervisor(context.Background(), args[0]); err != nil {
		log.Error("update supervisor failed", "err", err)
		return 1
	}
	return 0
}
