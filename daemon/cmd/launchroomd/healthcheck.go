package main

import (
	"fmt"
	"io"
	"os"
	"path/filepath"
	"time"

	"github.com/vantezzen/launchroom/daemon/internal/config"
	"github.com/vantezzen/launchroom/daemon/internal/hostinfo"
	"github.com/vantezzen/launchroom/daemon/internal/watchdog"
)

// HealthcheckCommand is `launchroomd healthcheck`, the container's
// healthcheck (deploy/daemon/compose.yml): exit 0 while launchroomd's
// heartbeat file is fresh, 1 otherwise. It reads only the data dir
// ($LR_DATA_DIR, default /data), so it works without the rest of the
// configuration and never talks to Docker or the dashboard.
const HealthcheckCommand = "healthcheck"

// runHealthcheck implements `launchroomd healthcheck`; main.go calls it
// with os.Args[2:] and exits with its result.
func runHealthcheck(args []string, out io.Writer) int {
	if len(args) > 0 {
		_, _ = fmt.Fprintln(out, "usage: launchroomd healthcheck")
		return 2
	}
	dir := os.Getenv(config.EnvDataDir)
	if dir == "" {
		dir = config.DefaultDataDir
	}
	file := filepath.Join(filepath.Clean(dir), hostinfo.HeartbeatFile)
	if err := watchdog.Healthy(file, watchdog.HeartbeatMaxAge, time.Now()); err != nil {
		_, _ = fmt.Fprintln(out, "launchroomd is unhealthy:", err)
		return 1
	}
	_, _ = fmt.Fprintln(out, "launchroomd is healthy")
	return 0
}
