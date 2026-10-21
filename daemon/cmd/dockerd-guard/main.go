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

const (
	defaultUpstream = "/var/run/docker.sock"
	defaultListen   = "/run/launchroom/docker.sock"
)

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

func env(k, def string) string {
	if v := os.Getenv(k); v != "" {
		return v
	}
	return def
}

func run() int {
	level := slog.LevelInfo
	_ = level.UnmarshalText([]byte(env("LR_LOG_LEVEL", "info")))
	log := slog.New(slog.NewJSONHandler(os.Stderr, &slog.HandlerOptions{Level: level})).With("component", "dockerd-guard", "version", version)
	guard.Version = version

	digests, err := config.ParseSystemImageDigests(os.Getenv(config.EnvSystemImageDigests))
	if err != nil {
		log.Error("configuration", "err", err)
		return 2
	}
	pol, err := guard.PolicyFromEnv(policy.DefaultConfig(), os.Getenv("LR_GUARD_ALLOWED_REGISTRIES"), digests)
	if err != nil {
		log.Error("configuration", "err", err)
		return 2
	}
	upstream, listen := env("LR_GUARD_UPSTREAM", defaultUpstream), env("LR_GUARD_LISTEN", defaultListen)
	srv, err := guard.New(guard.Options{
		Upstream: upstream, Policy: pol, Log: log,
		InteractiveSessions: os.Getenv(config.EnvInteractiveSessions),
	})
	if err != nil {
		log.Error("configuration", "err", err)
		return 2
	}

	ctx, stop := signal.NotifyContext(context.Background(), syscall.SIGTERM, syscall.SIGINT)
	defer stop()

	// Wait for Docker, then learn the host: rootless or not, and the
	// install folder for self-updates.
	var rootless bool
	for i := 0; ; i++ {
		pctx, cancel := context.WithTimeout(ctx, 10*time.Second)
		rootless, err = srv.DetectRootless(pctx)
		cancel()
		if err == nil {
			break
		}
		if ctx.Err() != nil {
			return 0
		}
		if i%10 == 0 {
			log.Warn("waiting for Docker", "upstream", upstream, "err", err)
		}
		time.Sleep(2 * time.Second)
	}
	opts := srv.Options()
	if rootless {
		opts.Rootless = &guard.RootlessOptions{APISocket: os.Getenv("LR_GUARD_ROOTLESSKIT_API")}
		log.Info("rootless Docker host", "edgePorts", opts.Rootless.APISocket != "")
	}
	if u, err := srv.DiscoverUpdater(ctx); err != nil {
		log.Warn("self-updates through the guard are disabled", "reason", err)
	} else {
		opts.Updater = u
		log.Info("self-update supervisor allowed", "installFolder", u.ProjectDir)
	}
	srv, err = guard.New(opts)
	if err != nil {
		log.Error("configuration", "err", err)
		return 2
	}

	l, err := guard.Listen(listen)
	if err != nil {
		log.Error("listen", "socket", listen, "err", err)
		return 1
	}
	log.Info("dockerd-guard listening", "socket", listen, "upstream", upstream)
	if err := srv.Serve(ctx, l); err != nil {
		log.Error("serve", "err", err)
		return 1
	}
	return 0
}

// healthcheck asks the guard's status endpoint on its own socket.
func healthcheck() int {
	path := env("LR_GUARD_LISTEN", defaultListen)
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
