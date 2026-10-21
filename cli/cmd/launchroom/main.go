// Command launchroom is the launchroom CLI (docs/16-api-and-cli.md).
package main

import (
	"context"
	"os"
	"os/signal"
	"syscall"

	"github.com/vantezzen/launchroom/cli/internal/browser"
	"github.com/vantezzen/launchroom/cli/internal/commands"
	"github.com/vantezzen/launchroom/cli/internal/config"
)

// version is set at build time (-ldflags "-X main.version=…").
var version = "dev"

func main() {
	ctx, stop := signal.NotifyContext(context.Background(), os.Interrupt, syscall.SIGTERM)
	code := commands.Main(ctx, version, os.Args[1:], config.SystemKeyring{}, browser.Open)
	stop()
	os.Exit(code)
}
