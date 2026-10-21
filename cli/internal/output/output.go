// Package output prints for humans (colour and spinners only on a TTY) or
// machines (--json: exactly one JSON document on stdout, nothing else).
package output

import (
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"os"
	"strings"
	"text/tabwriter"

	"golang.org/x/term"

	"github.com/vantezzen/launchroom/cli/internal/api"
	"github.com/vantezzen/launchroom/cli/internal/exitcode"
)

// Printer writes command output.
type Printer struct {
	Out   io.Writer // stdout: results
	Err   io.Writer // stderr: progress, hints, errors
	JSON  bool
	Color bool
	TTY   bool // stdin and stdout are terminals (prompts allowed)
}

// New builds a printer for the process' stdio.
func New(jsonMode, noColor bool) *Printer {
	tty := term.IsTerminal(int(os.Stdout.Fd())) && term.IsTerminal(int(os.Stdin.Fd())) // #nosec G115 -- file descriptors fit in int
	errTTY := term.IsTerminal(int(os.Stderr.Fd()))                                     // #nosec G115 -- file descriptors fit in int
	color := errTTY && !noColor && os.Getenv("NO_COLOR") == "" && os.Getenv("TERM") != "dumb"
	return &Printer{Out: os.Stdout, Err: os.Stderr, JSON: jsonMode, Color: color && !jsonMode, TTY: tty}
}

const (
	reset = "\x1b[0m"
	bold  = "\x1b[1m"
	dim   = "\x1b[2m"
	red   = "\x1b[31m"
	green = "\x1b[32m"
	amber = "\x1b[33m"
)

func (p *Printer) paint(code, s string) string {
	if !p.Color {
		return s
	}
	return code + s + reset
}

// Bold styles emphasis (no-op without colour).
func (p *Printer) Bold(s string) string { return p.paint(bold, s) }

// Dim styles secondary text.
func (p *Printer) Dim(s string) string { return p.paint(dim, s) }

// Good styles success.
func (p *Printer) Good(s string) string { return p.paint(green, s) }

// Bad styles failure.
func (p *Printer) Bad(s string) string { return p.paint(red, s) }

// Warn styles warnings.
func (p *Printer) Warn(s string) string { return p.paint(amber, s) }

// Result prints the command's result: v as JSON in --json mode, else the
// human text from human().
func (p *Printer) Result(v any, human func(w io.Writer)) error {
	if p.JSON {
		enc := json.NewEncoder(p.Out)
		enc.SetIndent("", "  ")
		enc.SetEscapeHTML(false)
		return enc.Encode(v)
	}
	if human != nil {
		human(p.Out)
	}
	return nil
}

// Info prints a progress line on stderr (also in --json mode, where stdout
// stays machine-readable).
func (p *Printer) Info(format string, args ...any) {
	_, _ = fmt.Fprintf(p.Err, format+"\n", args...)
}

// Warning prints a warning on stderr.
func (p *Printer) Warning(format string, args ...any) {
	_, _ = fmt.Fprintf(p.Err, p.Warn("!")+" "+format+"\n", args...)
}

// Table prints rows aligned in columns.
func (p *Printer) Table(w io.Writer, header []string, rows [][]string) {
	tw := tabwriter.NewWriter(w, 0, 2, 3, ' ', 0)
	if header != nil {
		cells := make([]string, len(header))
		for i, h := range header {
			cells[i] = p.Dim(strings.ToUpper(h))
		}
		_, _ = fmt.Fprintln(tw, strings.Join(cells, "\t"))
	}
	for _, r := range rows {
		_, _ = fmt.Fprintln(tw, strings.Join(r, "\t"))
	}
	_ = tw.Flush()
}

// Status styles a deployment/service status word.
func (p *Printer) Status(status string) string {
	switch status {
	case "active", "live", "running", "ready", "online":
		return p.Good("● " + statusLabel(status))
	case "failed", "cancelled", "offline", "unreachable":
		return p.Bad("✕ " + statusLabel(status))
	case "queued", "building", "deploying", "starting", "releasing", "routing", "planning", "in_progress":
		return p.Warn("◌ " + statusLabel(status))
	default:
		return p.Dim("○ " + statusLabel(status))
	}
}

func statusLabel(s string) string {
	if s == "active" {
		return "live"
	}
	return strings.ReplaceAll(s, "_", " ")
}

// ErrorJSON is the --json shape of a failure on stderr.
type ErrorJSON struct {
	Error errorBody `json:"error"`
}

type errorBody struct {
	Code     string              `json:"code"`
	Message  string              `json:"message"`
	Status   int                 `json:"status,omitempty"`
	Fields   map[string][]string `json:"fields,omitempty"`
	Ability  string              `json:"ability,omitempty"`
	ExitCode int                 `json:"exitCode"`
}

// Fail prints err (human or JSON) on stderr and returns the exit code.
func (p *Printer) Fail(err error) int {
	code := exitcode.Of(err)
	if p.JSON {
		body := errorBody{Code: "error", Message: err.Error(), ExitCode: code}
		var apiErr *api.Error
		if errors.As(err, &apiErr) {
			body.Code, body.Message, body.Status, body.Fields, body.Ability = apiErr.Code, apiErr.Message, apiErr.Status, apiErr.Fields, apiErr.Ability
		} else if code == exitcode.Usage {
			body.Code = "usage"
		}
		b, _ := json.Marshal(ErrorJSON{Error: body})
		_, _ = fmt.Fprintln(p.Err, string(b))
		return code
	}
	msg := err.Error()
	switch code {
	case exitcode.NotSignedIn:
		msg += "\n  Run `launchroom login` to sign in again."
	case exitcode.NotAllowed:
		msg += "\n  Create a token with the needed ability in Settings → API tokens, or ask a team owner."
	}
	_, _ = fmt.Fprintln(p.Err, p.Bad("Error:")+" "+msg)
	return code
}
