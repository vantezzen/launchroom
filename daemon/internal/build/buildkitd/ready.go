package buildkitd

import (
	"context"
	"fmt"
	"os"
	"regexp"
	"slices"
	"strings"
	"time"

	bkclient "github.com/moby/buildkit/client"

	"github.com/vantezzen/launchroom/daemon/internal/docker"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// Readiness: a build that finds buildkitd starting, restarting (Docker's
// restart policy after a crash or an OOM kill) or being replaced by the
// reconcile (a new NodeState, launchroomd just started) waits for it, bounded
// by ReadyTimeout, instead of failing on the first dial. A container that
// keeps crashing fails the build early with what Docker and its logs say.

// Hint codes of BuildKit readiness failures (TaskFailure.hints; the
// dashboard's FailureCatalogue explains them).
const (
	HintNotReady  = "buildkit-not-ready"
	HintCrashLoop = "buildkit-crash-loop"
	HintOOM       = "buildkit-oom"
	HintUserns    = "buildkit-userns-restricted"
	crashRestarts = 3                // restarts seen during one wait that make a crash loop
	shortRun      = 10 * time.Second // Docker resets its restart backoff after 10 s of running
	logTailLines  = 60
	keepLogLines  = 20
	// attemptTimeout bounds one dial while waiting: a container that crashes
	// right after starting must not hold the wait in one long dial.
	attemptTimeout = 10 * time.Second
	stillWaitEvery = 30 * time.Second
)

// ProfileCommand loads AppArmorProfile on a host by hand (the same rules
// the installers write; D58).
const ProfileCommand = `printf 'abi <abi/4.0>,\ninclude <tunables/global>\nprofile launchroom-buildkit flags=(unconfined) {\n  userns,\n}\n' | sudo tee /etc/apparmor.d/launchroom-buildkit && sudo apparmor_parser -r -W /etc/apparmor.d/launchroom-buildkit`

// WaitingMessage is the build log line shown while waiting.
const WaitingMessage = "Waiting for BuildKit to start…"

// NotReadyError says why buildkitd could not be reached within ReadyTimeout
// (or kept crashing).
type NotReadyError struct {
	// CrashLoop: the container kept exiting and Docker kept restarting it.
	CrashLoop bool
	// Restarts is Docker's restart count of the container.
	Restarts int
	// ExitCode and OOMKilled describe the container's last exit.
	ExitCode  int
	OOMKilled bool
	// UsernsRestricted: the kernel restricts unprivileged user namespaces
	// (Ubuntu 24.04+ AppArmor), which rootless BuildKit needs.
	UsernsRestricted bool
	// LogTail are the container's last log lines (oldest first).
	LogTail []string
	// State is Docker's container state ("restarting", "exited", …; "" if unknown).
	State  string
	Waited time.Duration
	Err    error
}

func (e *NotReadyError) Error() string {
	var b strings.Builder
	switch {
	case e.CrashLoop || e.Restarts > 0 && e.State != "running":
		fmt.Fprintf(&b, "buildkitd keeps restarting (restarted %d times, last exit code %d)", e.Restarts, e.ExitCode)
	case e.State != "":
		fmt.Fprintf(&b, "buildkitd did not become ready within %s (container %s)", e.Waited.Round(time.Second), e.State)
	default:
		fmt.Fprintf(&b, "buildkitd did not become ready within %s", e.Waited.Round(time.Second))
	}
	switch {
	case e.UsernsRestricted:
		b.WriteString(": the kernel blocks the user namespaces rootless BuildKit needs (kernel.apparmor_restrict_unprivileged_userns=1, Ubuntu 23.10 and later) and the AppArmor profile " + AppArmorProfile + " is not loaded")
	case e.OOMKilled:
		b.WriteString(": it was killed for running out of memory")
	}
	if e.Err != nil {
		fmt.Fprintf(&b, ": %v", e.Err)
	}
	if n := len(e.LogTail); n > 0 {
		b.WriteString("\nLast BuildKit log lines:")
		for _, l := range e.LogTail[max(0, n-5):] {
			b.WriteString("\n  " + l)
		}
	}
	return b.String()
}

func (e *NotReadyError) Unwrap() error { return e.Err }

// Logs are buildkitd's last log lines (for the build log).
func (e *NotReadyError) Logs() []string { return e.LogTail }

// Hints are the known-error hints of this failure (most specific first).
func (e *NotReadyError) Hints() []state.FailureHint {
	var out []state.FailureHint
	if e.UsernsRestricted {
		out = append(out, state.FailureHint{Code: HintUserns, Message: "This server's kernel blocks the user namespaces that rootless BuildKit needs (Ubuntu 23.10 and later restrict them through AppArmor). Allow them for launchroom's build container only: re-run the launchroom installer on the server and answer yes, or run `" + ProfileCommand + "`. launchroom switches to the profile within a few minutes; then redeploy."})
	}
	if e.OOMKilled {
		out = append(out, state.FailureHint{Code: HintOOM, Message: "BuildKit was killed for running out of memory. Builds need more memory than this server has free: add swap or memory, or add a bigger build server."})
	}
	if e.CrashLoop || e.Restarts > 0 && e.State != "running" {
		out = append(out, state.FailureHint{Code: HintCrashLoop, Message: "BuildKit keeps crashing on this server. Its last log lines are in the build log; the build is retried once."})
	} else {
		out = append(out, state.FailureHint{Code: HintNotReady, Message: "BuildKit did not start in time on this server. The build is retried once; if it keeps happening, check the server's free memory and disk."})
	}
	return out
}

// WaitClient connects to buildkitd like Client, but first waits (up to
// ReadyTimeout) while launchroomd has not converged it yet, while Docker
// restarts it, or while the reconcile replaces it. waiting (optional) is
// called with a progress line the first time it has to wait and every 30 s.
// The caller closes the client.
func (m *Manager) WaitClient(ctx context.Context, waiting func(string)) (*bkclient.Client, error) {
	start := time.Now()
	wctx, cancel := context.WithTimeout(ctx, m.o.ReadyTimeout)
	defer cancel()
	tell := func(text string) {
		if waiting != nil {
			waiting(text)
		}
	}
	var (
		told    time.Time
		first   = -1
		lastID  string
		last    docker.Container
		seen    bool
		lastErr error
		backoff = 250 * time.Millisecond
	)
	for {
		m.mu.Lock()
		id, synced, enabled, syncErr := m.running, m.synced, m.enabled, m.syncErr
		m.mu.Unlock()
		switch {
		case id == "" && synced && !enabled:
			return nil, ErrNotRunning
		case id == "":
			lastErr = ErrNotRunning
			if syncErr != nil {
				lastErr = syncErr
			}
		default:
			if id != lastID {
				lastID, first, seen = id, -1, false // replaced by the reconcile: a new container
			}
			c, err := m.o.Docker.InspectContainer(wctx, id)
			if err != nil {
				lastErr = err // being replaced (or removed): the next Sync names the new one
				break
			}
			last, seen = c, true
			if first < 0 {
				first = c.RestartCount
			}
			if c.Running && !c.Restarting {
				actx, acancel := context.WithTimeout(wctx, attemptTimeout)
				cl, err := m.connect(actx, id)
				acancel()
				if err == nil {
					return cl, nil
				}
				lastErr = err
			} else {
				lastErr = fmt.Errorf("container is %s", c.State)
			}
			if crashLooping(c, c.RestartCount-first) {
				return nil, m.notReady(ctx, id, &c, true, time.Since(start), lastErr)
			}
		}
		if wctx.Err() != nil {
			break
		}
		if told.IsZero() {
			tell(WaitingMessage)
			told = time.Now()
		} else if time.Since(told) >= stillWaitEvery {
			tell(fmt.Sprintf("Still waiting for BuildKit (%s)…", waitState(seen, &last)))
			told = time.Now()
		}
		select {
		case <-wctx.Done():
		case <-time.After(backoff):
		}
		backoff = min(backoff*2, 5*time.Second)
	}
	if ctx.Err() != nil {
		return nil, ctx.Err()
	}
	if !seen {
		return nil, &NotReadyError{Waited: time.Since(start), Err: lastErr}
	}
	// Diagnose from its state now, not from the last poll.
	ictx, icancel := context.WithTimeout(context.WithoutCancel(ctx), 5*time.Second)
	if c, err := m.o.Docker.InspectContainer(ictx, lastID); err == nil {
		last = c
	}
	icancel()
	crash := crashLooping(last, last.RestartCount-first) || last.Restarting && last.RestartCount > first
	return nil, m.notReady(ctx, lastID, &last, crash, time.Since(start), lastErr)
}

// crashLooping: Docker restarted it crashRestarts times during this wait,
// or it had restarted many times already and its last runs were short.
func crashLooping(c docker.Container, restartsSeen int) bool {
	if c.Running && !c.Restarting {
		return false
	}
	short := !c.StartedAt.IsZero() && !c.FinishedAt.IsZero() && c.FinishedAt.Sub(c.StartedAt) < shortRun
	return restartsSeen >= crashRestarts || c.RestartCount >= 5 && restartsSeen >= 1 && short
}

func waitState(seen bool, c *docker.Container) string {
	switch {
	case !seen:
		return "not started yet"
	case c.Restarting:
		return fmt.Sprintf("restarting, %d restarts so far", c.RestartCount)
	case c.Running:
		return "starting"
	default:
		return c.State
	}
}

// notReady collects the diagnostics of a buildkitd that never answered.
func (m *Manager) notReady(ctx context.Context, id string, c *docker.Container, crash bool, waited time.Duration, cause error) *NotReadyError {
	e := &NotReadyError{
		CrashLoop: crash, Restarts: c.RestartCount, ExitCode: c.ExitCode, OOMKilled: c.OOMKilled,
		State: c.State, Waited: waited,
	}
	if c.Running && !c.Restarting {
		e.State = "running"
		e.Err = cause // up, but never answered
	}
	lctx, cancel := context.WithTimeout(context.WithoutCancel(ctx), 5*time.Second)
	defer cancel()
	_ = m.o.Docker.StreamLogs(lctx, id, docker.LogsOptions{Tail: logTailLines}, func(l docker.LogLine) error {
		if t := strings.TrimSpace(l.Text); t != "" && !stackFrame(l.Text) {
			e.LogTail = append(e.LogTail, clipLine(t, 300))
		}
		return nil
	})
	e.LogTail = lastDistinct(e.LogTail, keepLogLines)
	e.UsernsRestricted = usernsRestricted(m.o.UsernsSysctl, e.LogTail)
	return e
}

// reFrame is a Go stack frame's function line ("main.main",
// "github.com/moby/buildkit/cmd/buildkitd/config.Load").
var reFrame = regexp.MustCompile(`^[\w.\-/]+\.[\w().*\-]+$`)

// stackFrame: a line of a Go panic/error stack trace (file lines are
// tab-indented), dropped so the diagnosis keeps the actual messages.
func stackFrame(raw string) bool {
	return strings.HasPrefix(raw, "\t") || reFrame.MatchString(strings.TrimSpace(raw))
}

var reUserns = regexp.MustCompile(`(?i)apparmor_restrict_unprivileged_userns|(rootlesskit|newuidmap|newgidmap|user namespace|/proc/self/exe).*(permission denied|operation not permitted)`)

// usernsRestricted: the logs name the AppArmor restriction, or they show
// rootlesskit failing to set up its user namespace while the sysctl says
// unprivileged user namespaces are restricted. A restricted kernel without
// matching logs (another cause) is not blamed.
func usernsRestricted(sysctl string, logs []string) bool {
	text := strings.Join(logs, "\n")
	if strings.Contains(strings.ToLower(text), "apparmor_restrict_unprivileged_userns") {
		return true
	}
	if !reUserns.MatchString(text) {
		return false
	}
	b, err := os.ReadFile(sysctl) //nolint:gosec // fixed procfs path (tests: a temp file)
	return err == nil && strings.TrimSpace(string(b)) == "1"
}

// lastDistinct keeps the last n distinct lines in the order of their last
// occurrence: a crash loop repeats the same few lines on every start.
func lastDistinct(lines []string, n int) []string {
	seen := map[string]bool{}
	var out []string
	for i := len(lines) - 1; i >= 0 && len(out) < n; i-- {
		if !seen[lines[i]] {
			seen[lines[i]] = true
			out = append(out, lines[i])
		}
	}
	slices.Reverse(out)
	return out
}

func clipLine(s string, n int) string {
	if len(s) <= n {
		return s
	}
	return s[:n] + "…"
}
