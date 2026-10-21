package exitcode

import (
	"errors"
	"fmt"
	"testing"
)

type coded struct{}

func (coded) Error() string { return "coded" }
func (coded) ExitCode() int { return 7 }

func TestForStatus(t *testing.T) {
	cases := map[int]int{401: NotSignedIn, 403: NotAllowed, 404: NotFound, 409: Refused, 410: Refused, 422: Refused, 429: RateLimited, 500: Error, 502: Error, 0: Error}
	for status, want := range cases {
		if got := ForStatus(status); got != want {
			t.Errorf("ForStatus(%d) = %d, want %d", status, got, want)
		}
	}
}

func TestOf(t *testing.T) {
	if Of(nil) != OK {
		t.Fatal("nil must be 0")
	}
	if Of(errors.New("x")) != Error {
		t.Fatal("plain errors are 1")
	}
	if Of(fmt.Errorf("wrapped: %w", New(DeploymentFailed, errors.New("x")))) != DeploymentFailed {
		t.Fatal("wrapped *Err keeps its code")
	}
	if Of(fmt.Errorf("w: %w", coded{})) != 7 {
		t.Fatal("Coder is honoured")
	}
	if Of(Usagef("bad %s", "flag")) != Usage {
		t.Fatal("usage is 2")
	}
}
