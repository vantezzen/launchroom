// Command daemon is launchroomd: one per server, the only component
// that touches Docker, the firewall and ingress (docs/03-daemon-protocol.md).
//
// Wiring: load config → load/generate identity → enroll if needed → fetch,
// verify and persist the signed NodeState every ~30 s and on SIGHUP, and run
// the reconciler, firewall, ingress, health and task loops (runtime.go).
// `daemon firewall remove` deletes the daemon's nftables table (uninstall).
// `launchroomd system-images` prints the built-in system image pins
// (component=digest per line; the release workflow and e2e tests check them).
package main

import (
	"context"
	"errors"
	"fmt"
	"io"
	"log/slog"
	"maps"
	"os"
	"os/signal"
	"slices"
	"syscall"

	"github.com/vantezzen/launchroom/daemon/internal/config"
	"github.com/vantezzen/launchroom/daemon/internal/firewall"
	"github.com/vantezzen/launchroom/daemon/internal/policy"
	"github.com/vantezzen/launchroom/daemon/internal/update"
)

// version is set at build time: -ldflags "-X main.version=1.2.3".
var version = "0.0.0-dev"

func main() {
	// `daemon firewall remove` deletes the nftables table (uninstall, docs/11).
	if len(os.Args) == 3 && os.Args[1] == "firewall" && os.Args[2] == "remove" {
		if err := firewall.NewBackend().Remove(); err != nil {
			fmt.Fprintln(os.Stderr, "launchroomd: remove firewall:", err)
			os.Exit(1)
		}
		fmt.Println("removed nftables table inet " + firewall.TableName)
		return
	}
	if len(os.Args) == 2 && os.Args[1] == "system-images" {
		printSystemImages(os.Stdout)
		return
	}
	// `launchroomd update-supervisor <plan>` performs a self-update swap in a
	// helper container (internal/update, docs/11 §4).
	if len(os.Args) >= 2 && os.Args[1] == update.SupervisorCommand {
		os.Exit(runSupervisor(os.Args[2:]))
	}
	cfg, err := config.FromProcess()
	if err != nil {
		fmt.Fprintln(os.Stderr, "launchroomd:", err)
		os.Exit(2)
	}
	logger := slog.New(slog.NewJSONHandler(os.Stderr, &slog.HandlerOptions{Level: cfg.LogLevel})).
		With("component", "launchroomd", "version", version)

	ctx, stop := signal.NotifyContext(context.Background(), os.Interrupt, syscall.SIGTERM)
	defer stop()
	refresh := make(chan os.Signal, 1)
	signal.Notify(refresh, syscall.SIGHUP)
	defer signal.Stop(refresh)

	if err := run(ctx, cfg, logger, refresh, defaultDeps()); err != nil && !errors.Is(err, context.Canceled) {
		logger.Error("launchroomd stopped", "err", err)
		os.Exit(1)
	}
	logger.Info("launchroomd stopped")
}

// printSystemImages writes the system image digests compiled into this
// binary, one "component=sha256:…" line each, sorted (docs/09 R31).
func printSystemImages(w io.Writer) {
	for _, comp := range slices.Sorted(maps.Keys(policy.DefaultSystemImageDigests)) {
		for _, d := range policy.DefaultSystemImageDigests[comp] {
			_, _ = fmt.Fprintf(w, "%s=%s\n", comp, d)
		}
	}
}
