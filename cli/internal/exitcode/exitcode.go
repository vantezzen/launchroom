// Package exitcode defines the launchroom CLI's process exit codes
// (docs/16-api-and-cli.md §3.2) and an error type that carries one.
package exitcode

import (
	"errors"
	"fmt"
)

// Exit codes. Scripts depend on these values: never renumber them.
const (
	OK               = 0
	Error            = 1
	Usage            = 2
	NotSignedIn      = 3
	NotAllowed       = 4
	NotFound         = 5
	Refused          = 6
	RateLimited      = 7
	DeploymentFailed = 10
	// TransferFailed: an import or export ran and failed (docs/14 §12).
	TransferFailed = 11
	// SecurityActionNeeded: `launchroom security --fail-on action-needed`
	// found open "Action needed" findings (docs/22 §11).
	SecurityActionNeeded = 12
	Interrupted          = 130
)

// Err is an error with the exit code the process should end with.
type Err struct {
	Code int
	Err  error
}

func (e *Err) Error() string {
	if e.Err == nil {
		return fmt.Sprintf("exit status %d", e.Code)
	}
	return e.Err.Error()
}

func (e *Err) Unwrap() error { return e.Err }

// New wraps err with an exit code.
func New(code int, err error) error {
	return &Err{Code: code, Err: err}
}

// Usagef is a usage error (exit 2) with a formatted message.
func Usagef(format string, args ...any) error {
	return &Err{Code: Usage, Err: fmt.Errorf(format, args...)}
}

// Coder is implemented by errors that know their exit code (api.Error).
type Coder interface {
	ExitCode() int
}

// Of returns the exit code for err: 0 for nil, the code of an *Err or
// Coder in the chain, else 1.
func Of(err error) int {
	if err == nil {
		return OK
	}
	var e *Err
	if errors.As(err, &e) {
		return e.Code
	}
	var c Coder
	if errors.As(err, &c) {
		return c.ExitCode()
	}
	return Error
}

// ForStatus maps an HTTP status of the launchroom API to an exit code.
func ForStatus(status int) int {
	switch status {
	case 401:
		return NotSignedIn
	case 403:
		return NotAllowed
	case 404:
		return NotFound
	case 409, 410, 422:
		return Refused
	case 429:
		return RateLimited
	default:
		return Error
	}
}
