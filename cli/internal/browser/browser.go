// Package browser opens a URL in the user's browser. The URL is one
// argv element of a fixed opener program, never part of a shell string
// (docs/09 R1).
package browser

import (
	"fmt"
	"net/url"
	"os/exec"
	"runtime"
)

// Command returns the opener and its arguments for the current OS.
func Command(goos, rawURL string) (string, []string) {
	switch goos {
	case "darwin":
		return "open", []string{rawURL}
	case "windows":
		return "rundll32", []string{"url.dll,FileProtocolHandler", rawURL}
	default:
		return "xdg-open", []string{rawURL}
	}
}

// Open starts the browser on an http(s) URL without waiting for it.
func Open(rawURL string) error {
	u, err := url.Parse(rawURL)
	if err != nil || (u.Scheme != "https" && u.Scheme != "http") {
		return fmt.Errorf("refusing to open %q: not an http(s) URL", rawURL)
	}
	name, args := Command(runtime.GOOS, u.String())
	// The opener exits right after handing the URL to the browser; it
	// outlives the command on purpose, so it gets no context.
	cmd := exec.Command(name, args...) //nolint:forbidigo,gosec,noctx // fixed opener program, the URL is one argv element (no shell)
	if err := cmd.Start(); err != nil {
		return err
	}
	go func() { _ = cmd.Wait() }()
	return nil
}
