// Command launchroom-ambassador is the per-server TCP forwarder that lets
// workloads reach dependencies on other servers by their usual names
// (docs/05 §3). launchroomd runs it as the system container
// lr-system-ambassador and writes its config file (internal/ambassador.Config);
// the forwarder re-reads the file every second and applies changes without
// dropping unaffected connections.
package main

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"flag"
	"fmt"
	"io"
	"io/fs"
	"log/slog"
	"os"
	"os/signal"
	"syscall"
	"time"

	"github.com/vantezzen/launchroom/daemon/internal/ambassador"
)

var version = "0.0.0-dev"

const maxConfigBytes = 4 << 20

func main() {
	path := flag.String("config", "/config/ambassador.json", "config file written by launchroomd")
	every := flag.Duration("poll", time.Second, "how often the config file is re-read")
	flag.Parse()
	log := slog.New(slog.NewJSONHandler(os.Stderr, nil)).With("component", "ambassador", "version", version)
	ctx, stop := signal.NotifyContext(context.Background(), syscall.SIGTERM, syscall.SIGINT)
	defer stop()
	run(ctx, log, *path, *every)
	log.Info("stopped")
}

func run(ctx context.Context, log *slog.Logger, path string, every time.Duration) {
	srv := ambassador.NewServer(ctx, ambassador.Options{Log: log})
	var applied []byte
	pending := false // the last apply left listeners to retry
	t := time.NewTicker(every)
	defer t.Stop()
	for {
		data, err := readConfig(path)
		switch {
		case errors.Is(err, fs.ErrNotExist):
			data = []byte(`{"listeners":[]}`)
		case err != nil:
			log.Warn("cannot read the config", "path", path, "err", err)
		}
		if err == nil || errors.Is(err, fs.ErrNotExist) {
			if !bytes.Equal(data, applied) || pending {
				cfg, perr := parse(data)
				if perr != nil {
					// Possibly a partially written file: keep the current config.
					log.Warn("config not applied", "err", perr)
				} else {
					aerr := srv.Apply(cfg) //nolint:contextcheck // listeners and health checks live on the server's own context
					pending = aerr != nil
					if aerr != nil {
						log.Warn("config applied partly; retrying", "err", aerr)
					} else if !bytes.Equal(data, applied) {
						log.Info("config applied", "listeners", len(cfg.Listeners))
					}
					applied = data
				}
			}
		}
		select {
		case <-ctx.Done():
			return
		case <-t.C:
		}
	}
}

func readConfig(path string) ([]byte, error) {
	f, err := os.Open(path) //nolint:gosec // fixed path from the command line, written by launchroomd
	if err != nil {
		return nil, err
	}
	defer func() { _ = f.Close() }()
	var buf bytes.Buffer
	if _, err := buf.ReadFrom(io.LimitReader(f, maxConfigBytes+1)); err != nil {
		return nil, err
	}
	if buf.Len() > maxConfigBytes {
		return nil, fmt.Errorf("config is larger than %d bytes", maxConfigBytes)
	}
	return buf.Bytes(), nil
}

func parse(data []byte) (ambassador.Config, error) {
	var cfg ambassador.Config
	dec := json.NewDecoder(bytes.NewReader(data))
	dec.DisallowUnknownFields()
	if err := dec.Decode(&cfg); err != nil {
		return cfg, err
	}
	return cfg, cfg.Validate()
}
