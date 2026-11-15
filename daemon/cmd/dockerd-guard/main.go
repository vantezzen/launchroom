// Command dockerd-guard owns the Docker socket on a launchroom server and
// serves launchroomd a narrow, allow-listed Engine API on a unix socket of
// its own (internal/guard, docs/09 §6, D49).
//
//	dockerd-guard               run (environment below)
//	dockerd-guard healthcheck   exit 0 when the guard answers on its socket
//
// Environment (from the install's .env through compose, never from launchroomd):
//
//	LR_GUARD_UPSTREAM            Docker socket (default /var/run/docker.sock)
//	LR_GUARD_LISTEN              socket for launchroomd (default /run/launchroom/docker.sock)
//	LR_GUARD_ALLOWED_REGISTRIES  extra workload registries ("host:port/,…"; default: the local registry only)
//	LR_SYSTEM_IMAGE_DIGESTS      extra system image digests (as for launchroomd)
//	LR_INTERACTIVE_SESSIONS      on | console | data | off (consoles refused for data/off)
//	LR_GUARD_ROOTLESSKIT_API     RootlessKit API socket on rootless hosts (edge ports)
//	LR_LOG_LEVEL                 debug | info | warn | error
package main

import (
	"context"
	"fmt"
	"log/slog"
	"net"
	"net/http"
	"os"
	"os/signal"
	"syscall"
	"time"

	"github.com/vantezzen/launchroom/daemon/internal/config"
	"github.com/vantezzen/launchroom/daemon/internal/guard"
	"github.com/vantezzen/launchroom/daemon/internal/policy"
)

var version = "0.0.0-dev"

func main() {
	if len(os.Args) > 1 && os.Args[1] == "healthcheck" {
		os.Exit(healthcheck())
	}
	if len(os.Args) > 1 {
		fmt.Fprintln(os.Stderr, "usage: dockerd-guard [healthcheck]")
		os.Exit(2)
	}
	os.Exit(run())
}

func run() int {
	cfg, cfgErr := config.LoadGuard(os.LookupEnv)
	log := slog.New(slog.NewJSONHandler(os.Stderr, &slog.HandlerOptions{Level: cfg.LogLevel})).With("component", "dockerd-guard", "version", version)
	if cfgErr != nil {
		log.Error("configuration", "err", cfgErr)
		return 2
	}
	guard.Version = version
	pol, err := guard.PolicyFromEnv(policy.DefaultConfig(), cfg.AllowedRegistries, cfg.SystemImageDigests)
	if err != nil {
		log.Error("configuration", "err", err)
		return 2
	}
	srv, err := guard.New(guard.Options{Upstream: cfg.Upstream, Policy: pol, Log: log, InteractiveSessions: cfg.InteractiveSessions})
	if err != nil {
		log.Error("configuration", "err", err)
		return 2
	}

	ctx, stop := signal.NotifyContext(context.Background(), syscall.SIGTERM, syscall.SIGINT)
	defer stop()
	if err := srv.Discover(ctx, cfg.RootlessKitAPI); err != nil {
		return 0 // stopped while waiting for Docker
	}

	l, err := guard.Listen(cfg.Listen)
	if err != nil {
		log.Error("listen", "socket", cfg.Listen, "err", err)
		return 1
	}
	log.Info("dockerd-guard listening", "socket", cfg.Listen, "upstream", cfg.Upstream)
	if err := srv.Serve(ctx, l); err != nil {
		log.Error("serve", "err", err)
		return 1
	}
	return 0
}

func healthcheck() int {
	path := config.GuardListen()
	c := &http.Client{Timeout: 5 * time.Second, Transport: &http.Transport{DialContext: func(ctx context.Context, _, _ string) (net.Conn, error) {
		var d net.Dialer
		return d.DialContext(ctx, "unix", path)
	}}}
	req, err := http.NewRequestWithContext(context.Background(), http.MethodGet, "http://guard"+guard.StatusPath, nil)
	if err != nil {
		return 1
	}
	res, err := c.Do(req)
	if err != nil {
		fmt.Fprintln(os.Stderr, "dockerd-guard:", err)
		return 1
	}
	_ = res.Body.Close()
	if res.StatusCode != http.StatusOK {
		fmt.Fprintln(os.Stderr, "dockerd-guard: status", res.StatusCode)
		return 1
	}
	return 0
}
