package docker

import (
	"errors"
	"fmt"
	"net"
	"slices"
	"testing"

	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// Docker's own wording (Engine 25–29, runc and crun), as launchroomd sees it
// after wrap().
func TestStartFailureCodes(t *testing.T) {
	for msg, want := range map[string]state.WorkloadFailureCode{
		`docker: start container: Error response from daemon: failed to create task for container: failed to create shim task: OCI runtime create failed: runc create failed: unable to start container process: exec: "nope": executable file not found in $PATH: unknown`: state.FailureCommandNotFound,
		`docker: start container: OCI runtime create failed: runc create failed: unable to start container process: error during container init: exec: "/app/start.sh": stat /app/start.sh: no such file or directory: unknown`:                                             state.FailureCommandNotFound,
		`docker: start container: OCI runtime create failed: runc create failed: unable to start container process: exec: "/app/run": permission denied: unknown`:                                                                                                           state.FailureNotExecutable,
		`exec /usr/local/bin/server: exec format error`:                                                                        state.FailureWrongPlatform,
		`docker: start container: unable to find user app: no matching entries in passwd file`:                                 state.FailureInvalidUser,
		`docker: start container: error mounting "/var/lib/docker/volumes/lr-v_x/_data" to rootfs at "/data": not a directory`: state.FailureMountFailed,
		`docker: start container: OCI runtime create failed: runc create failed: unable to start container process: error during container init: error mounting "/var/lib/docker/volumes/lr-v_x/_data" to rootfs at "/etc/app": mkdirat: no such file or directory: unknown`: state.FailureMountFailed,
		`docker: create container lr-wl: write /var/lib/docker/tmp: no space left on device`: state.FailureNoSpace,
		`docker: create container lr-wl: No such image: registry.example.com/app@sha256:abc`: state.FailureImageNotFound,
		`docker: start container: something else entirely`:                                   state.FailureOther,
	} {
		if got := StartFailure(errors.New(msg)); got != want {
			t.Errorf("%q: %s, want %s", msg, got, want)
		}
	}
	if StartFailure(nil) != "" {
		t.Fatal("nil error has a code")
	}
}

func TestPullFailureCodes(t *testing.T) {
	for msg, want := range map[string]state.WorkloadFailureCode{
		`docker: pull x: toomanyrequests: You have reached your pull rate limit. You may increase the limit by authenticating and upgrading`:                                                           state.FailureRateLimited,
		`docker: pull x: Error response from daemon: pull access denied for acme/private, repository does not exist or may require 'docker login': denied: requested access to the resource is denied`: state.FailureUnauthorized,
		`docker: pull x: unauthorized: authentication required`:                                                             state.FailureUnauthorized,
		`docker: pull x: Head "https://registry/v2/a/manifests/sha256:1": unexpected status 401 Unauthorized`:               state.FailureUnauthorized,
		`docker: registry credentials for registry.lr/app@sha256:1: 403 forbidden`:                                          state.FailureUnauthorized,
		`docker: pull x: manifest unknown: manifest unknown`:                                                                state.FailureImageNotFound,
		`docker: pull x: not found: manifest unknown`:                                                                       state.FailureImageNotFound,
		`docker: pull x: no matching manifest for linux/arm64/v8 in the manifest list entries`:                              state.FailureWrongPlatform,
		`docker: pull x: write /var/lib/docker/tmp/GetImageBlob: no space left on device`:                                   state.FailureNoSpace,
		`docker: pull x: no download progress for 5m0s`:                                                                     state.FailureNetwork,
		`docker: pull x: Get "https://registry-1.docker.io/v2/": dial tcp: lookup registry-1.docker.io: no such host`:       state.FailureNetwork,
		`docker: pull x: timed out`:                                                                                         state.FailureNetwork,
		`docker: pull x: the launchroom registry didn't answer within 2m0s; check that this server reaches the main server`: state.FailureNetwork,
		`docker: pull x: something odd`:                                                                                     state.FailureOther,
	} {
		if got := PullFailure(errors.New(msg)); got != want {
			t.Errorf("%q: %s, want %s", msg, got, want)
		}
	}
}

func TestTCPPortsAndArch(t *testing.T) {
	if got := TCPPorts([]string{"8080/tcp", "53/udp", "3000", "8080", "x/tcp", "70000/tcp", "443/TCP"}); !slices.Equal(got, []int{443, 3000, 8080}) {
		t.Fatalf("ports %v", got)
	}
	for in, want := range map[string]string{"x86_64": "amd64", "aarch64": "arm64", "arm64": "arm64", "armv7l": "arm", "riscv64": "riscv64"} {
		if got := NormalizeArch(in); got != want {
			t.Errorf("%s: %s", in, got)
		}
	}
}

func TestIsUnreachable(t *testing.T) {
	for _, err := range []error{
		errors.New("Cannot connect to the Docker daemon at unix:///var/run/docker.sock. Is the docker daemon running?"),
		fmt.Errorf("docker: list networks: %w", &net.OpError{Op: "dial", Net: "unix", Err: errors.New("connect: no such file or directory")}),
	} {
		if !IsUnreachable(err) {
			t.Errorf("not unreachable: %v", err)
		}
	}
	if IsUnreachable(ErrGuardRefused) || IsUnreachable(nil) || IsUnreachable(errors.New("conflict: name in use")) {
		t.Fatal("false positive")
	}
}

func TestExitFailure(t *testing.T) {
	for _, c := range []struct {
		code  int
		lines []string
		want  state.WorkloadFailureCode
	}{
		{127, []string{"[FATAL tini (7)] exec nope-not-here failed: No such file or directory"}, state.FailureCommandNotFound},
		{126, []string{"[FATAL tini (7)] exec /app/run failed: Permission denied"}, state.FailureNotExecutable},
		{-1, []string{"[FATAL tini (6)] exec /app/server failed: Exec format error"}, state.FailureWrongPlatform},
		{127, []string{"sh: bundle: not found"}, state.FailureCommandNotFound},
		{127, []string{"/bin/sh: 1: rails: command not found"}, state.FailureCommandNotFound},
		{1, []string{"exec /usr/local/bin/node: exec format error"}, state.FailureWrongPlatform},
		{1, []string{"Error: Cannot find module '/app/server.js'"}, ""},
		{0, nil, ""},
		{1, []string{"sh: bundle: not found"}, ""}, // not the start command (exit 1)
	} {
		if got, _ := ExitFailure(c.code, c.lines); got != c.want {
			t.Errorf("%d %q: %s, want %s", c.code, c.lines, got, c.want)
		}
	}
}
