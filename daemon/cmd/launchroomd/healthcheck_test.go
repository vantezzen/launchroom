package main

import (
	"bytes"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"

	"github.com/vantezzen/launchroom/daemon/internal/config"
	"github.com/vantezzen/launchroom/daemon/internal/hostinfo"
	"github.com/vantezzen/launchroom/daemon/internal/watchdog"
)

// G14: `launchroomd healthcheck` follows the heartbeat file.
func TestHealthcheck(t *testing.T) {
	dir := t.TempDir()
	t.Setenv(config.EnvDataDir, dir)
	var out bytes.Buffer
	if code := runHealthcheck(nil, &out); code != 1 || !strings.Contains(out.String(), "no heartbeat yet") {
		t.Fatalf("without a heartbeat: %d %q", code, out.String())
	}
	file := filepath.Join(dir, hostinfo.HeartbeatFile)
	if err := watchdog.Touch(file, time.Now()); err != nil {
		t.Fatal(err)
	}
	out.Reset()
	if code := runHealthcheck(nil, &out); code != 0 {
		t.Fatalf("fresh heartbeat: %d %q", code, out.String())
	}
	old := time.Now().Add(-watchdog.HeartbeatMaxAge - time.Minute)
	if err := os.Chtimes(file, old, old); err != nil {
		t.Fatal(err)
	}
	out.Reset()
	if code := runHealthcheck(nil, &out); code != 1 || !strings.Contains(out.String(), "stale") {
		t.Fatalf("stale heartbeat: %d %q", code, out.String())
	}
	if code := runHealthcheck([]string{"x"}, &out); code != 2 {
		t.Fatalf("extra argument: %d", code)
	}
}
