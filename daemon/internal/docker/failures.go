package docker

import (
	"regexp"
	"strings"

	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// Docker's errors are classified where they happen, into the typed codes of
// WorkloadStatus.failure and create_failed events (docs/03 §9), so the
// dashboard explains them without parsing Docker's wording (docs/04 §6).
// The patterns are Docker 25–29 and runc/crun messages; anything else is
// "other" and keeps Docker's text as the message.

// StartFailure classifies an error of creating or starting a container.
func StartFailure(err error) state.WorkloadFailureCode {
	if err == nil {
		return ""
	}
	return startFailureText(err.Error())
}

func startFailureText(raw string) state.WorkloadFailureCode {
	msg := strings.ToLower(raw)
	has := func(subs ...string) bool {
		for _, s := range subs {
			if strings.Contains(msg, s) {
				return true
			}
		}
		return false
	}
	switch {
	case has("no space left on device"):
		return state.FailureNoSpace
	case has("exec format error"):
		return state.FailureWrongPlatform
	case has("unable to find user", "unable to find group", "no matching entries in passwd file", "no matching entries in group file", "no users found", "no groups found"):
		return state.FailureInvalidUser
	case has("error mounting", "failed to mount", "mount denied", "invalid mount config", "error while creating mount source path", "mounting \"", "volume could not be created", "mounts unknown volume"):
		return state.FailureMountFailed
	case has("executable file not found", "no such file or directory") && has("exec", "starting container process", "unable to start container process"):
		return state.FailureCommandNotFound
	case has("permission denied") && has("exec", "starting container process", "unable to start container process"):
		return state.FailureNotExecutable
	case has("no such image", "manifest unknown"):
		return state.FailureImageNotFound
	}
	return state.FailureOther
}

// PullFailure classifies an error of pulling an image.
func PullFailure(err error) state.WorkloadFailureCode {
	if err == nil {
		return ""
	}
	return pullFailureText(err.Error())
}

func pullFailureText(raw string) state.WorkloadFailureCode {
	msg := strings.ToLower(raw)
	has := func(subs ...string) bool {
		for _, s := range subs {
			if strings.Contains(msg, s) {
				return true
			}
		}
		return false
	}
	switch {
	case has("no space left on device"):
		return state.FailureNoSpace
	case has("toomanyrequests", "too many requests", "rate limit"):
		return state.FailureRateLimited
	case has("no matching manifest for", "does not match the specified platform", "does not provide the specified platform", "not support the specified platform", "exec format error"):
		return state.FailureWrongPlatform
	// Docker Hub says "pull access denied … repository does not exist or
	// may require 'docker login'" for both: the dashboard words it as
	// "doesn't exist or this server isn't allowed to read it".
	case has("unauthorized", "authentication required", "registry credentials for", "pull access denied", "denied:", "access denied", "401 ", "403 forbidden", "status 401", "status 403", "forbidden"):
		return state.FailureUnauthorized
	case has("manifest unknown", "not found", "no such image", "name unknown", "repository does not exist"):
		return state.FailureImageNotFound
	case has("no download progress", "timed out", "timeout", "deadline exceeded", "dial tcp", "no such host", "connection refused", "connection reset",
		"network is unreachable", "tls handshake", "eof", "didn't answer within", "temporary failure in name resolution", "server misbehaving",
		"i/o timeout", "registry mirror did not finish"):
		return state.FailureNetwork
	}
	return state.FailureOther
}

// reExecFailed is the init's (tini, Docker's --init) report that it could
// not start the command: Docker starts the container fine, and it exits at
// once with 127 or 126 and this line.
var reExecFailed = regexp.MustCompile(`\[FATAL tini \(\d+\)\] exec (.{1,256}) failed: (.{1,256})$`)

// ExitFailure classifies an exit right after the start whose last lines say
// the start command couldn't run: the init's exec error, or a shell's
// "not found" with 127 ("" = an ordinary exit). code < 0: unknown.
func ExitFailure(code int, lines []string) (state.WorkloadFailureCode, string) {
	for i := len(lines) - 1; i >= 0 && i >= len(lines)-3; i-- {
		l := strings.TrimSpace(lines[i])
		if m := reExecFailed.FindStringSubmatch(l); m != nil {
			reason := strings.ToLower(m[2])
			switch {
			case strings.Contains(reason, "no such file or directory"):
				return state.FailureCommandNotFound, l
			case strings.Contains(reason, "permission denied"):
				return state.FailureNotExecutable, l
			case strings.Contains(reason, "exec format error"):
				return state.FailureWrongPlatform, l
			}
			return state.FailureOther, l
		}
		low := strings.ToLower(l)
		switch {
		case code == 127 && (strings.HasSuffix(low, ": not found") || strings.HasSuffix(low, "command not found")):
			return state.FailureCommandNotFound, l
		case code == 126 && strings.HasSuffix(low, "permission denied"):
			return state.FailureNotExecutable, l
		case strings.HasSuffix(low, "exec format error") && (code == 1 || code == 126 || code == 127 || code == 255):
			return state.FailureWrongPlatform, l
		}
	}
	return "", ""
}
