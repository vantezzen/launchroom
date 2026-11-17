//go:build integration

package integration

import (
	"archive/tar"
	"context"
	"log/slog"
	"os"
	"path/filepath"
	"slices"
	"strings"
	"testing"
	"time"

	"github.com/vantezzen/launchroom/daemon/internal/docker"
	"github.com/vantezzen/launchroom/daemon/internal/health"
	"github.com/vantezzen/launchroom/daemon/internal/policy"
	"github.com/vantezzen/launchroom/daemon/internal/reconcile"
	"github.com/vantezzen/launchroom/daemon/internal/sealed"
	"github.com/vantezzen/launchroom/daemon/internal/secret"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// Runtime visibility against a real Docker (docs/03 §9, docs/15 §6): exits
// keep their facts and the dead run's lines, start failures are typed, the
// probe asks as visitors do, a hung app is restarted, a fresh volume belongs
// to the image's user.

// runtimeRec is a reconciler like lifecycleEnv's with the persisted marks
// in marks ("" = none).
func runtimeRec(t *testing.T, e *env, marks string) *reconcile.Reconciler {
	t.Helper()
	ctx, cancel := context.WithCancel(context.Background())
	t.Cleanup(cancel)
	pol := policy.DefaultConfig()
	pol.AllowedRegistries = []string{policy.DefaultRegistry, "docker.io/library/"}
	mon := health.NewMonitor(ctx, health.MonitorOptions{})
	t.Cleanup(mon.Close)
	rec := reconcile.New(reconcile.Options{
		ServerID: e.serverID, Docker: e.engine, Policy: pol, Health: mon, Ingress: e.ingress,
		EnvHashKey: docker.EnvHashKey(e.key.Bytes()),
		OpenEnv: func(s *state.WorkloadSpec) (map[string]secret.Secret, error) {
			return sealed.OpenWorkloadEnv(s.Env, e.key, e.serverID, s.ID)
		},
		StopGrace: 2 * time.Second, PullWait: 2 * time.Second, MarksFile: marks,
		Log: slog.New(slog.NewTextHandler(os.Stderr, &slog.HandlerOptions{Level: slog.LevelInfo})),
	})
	t.Cleanup(rec.StopWatchers)
	return rec
}

func lineTexts(lines []state.LogLine) []string {
	out := make([]string, len(lines))
	for i, l := range lines {
		out[i] = l.Text
	}
	return out
}

// An app that runs out of memory once it is up: Docker's OOM killer ends it
// with 137 and restarts it; the exit carries the code, the OOM flag, SIGKILL
// and the dead run's last line, not the next run's start-up output.
func TestExitWatchOOM(t *testing.T) {
	e := lifecycleEnv(t, nil)
	wl := state.WorkloadID(randomID("wl"))
	argv := []string{crashShell, "-c", `echo "run started"; sleep 6; echo "allocating"; x=$(head -c 200000000 /dev/zero | tr '\0' a); echo "survived"`}
	ns := e.lifecycleState(t, 1, "", e.busyboxWorkload(t, wl, busyboxImage, argv, false))
	watchUntil(t, e, ns, 2*time.Minute, func() bool {
		for _, x := range events(e, wl, state.EventExited) {
			if x.OOMKilled {
				return true
			}
		}
		return false
	})
	var oom state.WorkloadEvent
	for _, x := range events(e, wl, state.EventExited) {
		if x.OOMKilled {
			oom = x
			break
		}
	}
	if oom.ExitCode == nil || *oom.ExitCode != 137 || oom.Signal != 9 || oom.ByLaunchroom {
		t.Fatalf("exit %+v", oom)
	}
	got := lineTexts(oom.Output)
	if len(got) < 2 || got[0] != "run started" || !slices.Contains(got, "allocating") || slices.Contains(got, "survived") || slices.Index(got[1:], "run started") >= 0 {
		t.Fatalf("output %q", got)
	}
}

// A crash long after the start with a 60-line trace: the exit has code 3
// and the whole trace, ending with its last frame.
func TestExitWatchCrashLateTrace(t *testing.T) {
	e := lifecycleEnv(t, nil)
	wl := state.WorkloadID(randomID("wl"))
	argv := []string{crashShell, "-c", `echo "run started"; sleep 8; i=0; while [ $i -lt 60 ]; do echo "  at frame $i" >&2; i=$((i+1)); done; echo "Error: boom" >&2; exit 3`}
	ns := e.lifecycleState(t, 1, "", e.busyboxWorkload(t, wl, busyboxImage, argv, false))
	watchUntil(t, e, ns, 2*time.Minute, func() bool { return len(events(e, wl, state.EventExited)) > 0 })
	x := events(e, wl, state.EventExited)[0]
	if x.ExitCode == nil || *x.ExitCode != 3 || x.OOMKilled || x.Signal != 0 {
		t.Fatalf("exit %+v", x)
	}
	got := lineTexts(x.Output)
	if len(got) != 62 || got[0] != "run started" || got[1] != "  at frame 0" || got[61] != "Error: boom" {
		t.Fatalf("output (%d lines) %q", len(got), got)
	}
	if x.Output[61].Stream != state.StreamStderr {
		t.Fatalf("stream %+v", x.Output[61])
	}
}

// A start command that doesn't exist fails within seconds with
// command_not_found, on the status and on the exit (Docker's init starts,
// its exec of the command fails with 127).
func TestExitWatchBadCommand(t *testing.T) {
	e := lifecycleEnv(t, nil)
	wl := state.WorkloadID(randomID("wl"))
	ns := e.lifecycleState(t, 1, "", e.busyboxWorkload(t, wl, busyboxImage, []string{"nope-not-here"}, false))
	start := time.Now()
	watchUntil(t, e, ns, 30*time.Second, func() bool {
		f := status(e.rec, wl).Failure
		return f != nil && f.Code == state.FailureCommandNotFound
	})
	// Includes the busybox pull of a fresh test engine.
	if d := time.Since(start); d > 20*time.Second {
		t.Fatalf("took %s", d)
	}
	if f := status(e.rec, wl).Failure; !strings.Contains(f.Message, "exec nope-not-here failed: No such file or directory") {
		t.Fatalf("failure %+v", f)
	}
	// Every exit is typed; the ones the watcher saw before Docker's restart
	// carry 127 (the pass may see the first one only after the restart).
	exits := events(e, wl, state.EventExited)
	with127 := false
	for _, x := range exits {
		if x.Code != state.FailureCommandNotFound {
			t.Fatalf("exit %+v", x)
		}
		with127 = with127 || (x.ExitCode != nil && *x.ExitCode == 127)
	}
	if !with127 {
		t.Fatalf("exits %+v", exits)
	}
}

// hostCheck is a busybox CGI that answers like a framework with an
// allowed-hosts list: 400 with a DisallowedHost page for other hosts.
const hostCheck = `mkdir -p /tmp/www/cgi-bin
cat > /tmp/www/cgi-bin/check <<'EOF'
#!/bin/sh
if [ "$HTTP_HOST" = "app.example.com" ]; then
  printf 'Content-Type: text/plain\r\n\r\nok\n'
else
  printf 'Status: 400 Bad Request\r\nContent-Type: text/html\r\n\r\n<h1>DisallowedHost at /</h1><p>Invalid HTTP_HOST header: %s</p>\n' "$HTTP_HOST"
fi
EOF
chmod +x /tmp/www/cgi-bin/check
exec httpd -f -p 8080 -h /tmp/www`

// The probe asks for the primary domain: an app with an allowed-hosts list
// passes with it, and the refusal of another domain fails with the
// host_not_allowed hint and the page's text.
func TestExitWatchProbeHost(t *testing.T) {
	e := lifecycleEnv(t, nil)
	allowed := e.busyboxWorkload(t, state.WorkloadID(randomID("wl")), busyboxImage, []string{crashShell, "-c", hostCheck}, true)
	allowed.Health.Path, allowed.Health.Host, allowed.Health.ExpectStatus = "/cgi-bin/check", "app.example.com", "2xx-4xx"
	refused := e.busyboxWorkload(t, state.WorkloadID(randomID("wl")), busyboxImage, []string{crashShell, "-c", hostCheck}, true)
	refused.Health.Path, refused.Health.Host, refused.Health.ExpectStatus = "/cgi-bin/check", "other.example.com", "2xx-4xx"
	refused.Health.StartPeriod = "1s"
	ns := e.lifecycleState(t, 1, "", allowed, refused)
	watchUntil(t, e, ns, 2*time.Minute, func() bool {
		h := status(e.rec, refused.ID).Health
		return status(e.rec, allowed.ID).Phase == state.PhaseHealthy && h != nil && h.Hint != ""
	})
	h := status(e.rec, refused.ID).Health
	if h.Hint != state.ProbeHostNotAllowed || !strings.Contains(h.Body, "DisallowedHost at / Invalid HTTP_HOST header: other.example.com") ||
		!strings.Contains(h.LastError, "HTTP 400") {
		t.Fatalf("health %+v", h)
	}
}

// A container that exits while launchroomd is down is reported once by the
// next launchroomd run (persisted marks), with its last lines.
func TestExitWatchWhileDown(t *testing.T) {
	e := lifecycleEnv(t, nil)
	marks := filepath.Join(t.TempDir(), "lifecycle.json")
	e.rec = runtimeRec(t, e, marks)
	wl := state.WorkloadID(randomID("wl"))
	argv := []string{crashShell, "-c", `echo "up"; sleep 5; echo "lost the database" >&2; exit 4`}
	ns := e.lifecycleState(t, 1, "", e.busyboxWorkload(t, wl, busyboxImage, argv, false))
	watchUntil(t, e, ns, time.Minute, func() bool { return len(events(e, wl, state.EventStarted)) > 0 })
	e.rec.StopWatchers()

	// "Down" for long enough to miss an exit (and Docker's restart).
	time.Sleep(8 * time.Second)
	e.rec = runtimeRec(t, e, marks)
	if err := e.rec.Reconcile(t.Context(), ns); err != nil {
		t.Fatal(err)
	}
	exits := events(e, wl, state.EventExited)
	if len(exits) == 0 {
		t.Fatalf("no exit reported after the restart: %+v", e.rec.PendingEvents())
	}
	if got := lineTexts(exits[0].Output); !slices.Contains(got, "lost the database") || slices.Index(got, "up") != 0 {
		t.Fatalf("output %q", got)
	}
}

// OD2: a serving app whose check passed and then keeps failing is restarted
// after restartAfter, with reason health.
func TestExitWatchLivenessRestart(t *testing.T) {
	e := lifecycleEnv(t, nil)
	wl := state.WorkloadID(randomID("wl"))
	// Serves index.html, which disappears after 20 s: the check then fails
	// (404 against 2xx-3xx) while the process stays alive.
	argv := []string{crashShell, "-c", `mkdir -p /tmp/w; echo ok > /tmp/w/index.html; (sleep 20; rm /tmp/w/index.html) & exec httpd -f -p 8080 -h /tmp/w`}
	w := e.busyboxWorkload(t, wl, busyboxImage, argv, true)
	w.Health.ExpectStatus, w.Health.StartPeriod, w.Health.FailureThreshold, w.Health.RestartAfter = "", "5s", 2, "1m"
	ns := e.lifecycleState(t, 1, "", w)
	watchUntil(t, e, ns, 3*time.Minute, func() bool {
		for _, ev := range events(e, wl, state.EventRestarted) {
			if ev.Reason == state.ReasonHealth {
				return true
			}
		}
		return false
	})
	var ev state.WorkloadEvent
	for _, x := range events(e, wl, state.EventRestarted) {
		if x.Reason == state.ReasonHealth {
			ev = x
		}
	}
	if !strings.HasPrefix(ev.Message, "health check failing for 1m") {
		t.Fatalf("event %+v", ev)
	}
	// It serves again after the restart.
	watchUntil(t, e, ns, time.Minute, func() bool { return status(e.rec, wl).Phase == state.PhaseHealthy })
}

// A fresh volume belongs to the image's user from its first start: an
// absent folder and an empty one of the image (Docker copies the image's
// folder into the volume at create); a folder with files is left alone.
func TestExitWatchInitOwner(t *testing.T) {
	e := lifecycleEnv(t, nil)
	wl := state.WorkloadID(randomID("wl"))
	w := e.busyboxWorkload(t, wl, busyboxImage, []string{"sleep", "3600"}, false)
	owner := &state.FileOwner{UID: 1000, GID: 1000}
	w.Mounts = []state.Mount{
		{Volume: "vol_01J9Z8X7W6V5T4S3R2Q1P0KC10", Target: "/data", InitOwner: owner},
		{Volume: "vol_01J9Z8X7W6V5T4S3R2Q1P0KC11", Target: "/home", InitOwner: owner},
		{Volume: "vol_01J9Z8X7W6V5T4S3R2Q1P0KC12", Target: "/etc", InitOwner: owner},
	}
	ns := e.lifecycleState(t, 1, "")
	ns.Workloads = []state.WorkloadSpec{w}
	suffix := strings.ToLower(randomID("x"))[2:10]
	ns.Volumes = []state.VolumeSpec{
		{ID: "vol_01J9Z8X7W6V5T4S3R2Q1P0KC10", Name: state.HostName("lr-v_own-a-" + suffix)},
		{ID: "vol_01J9Z8X7W6V5T4S3R2Q1P0KC11", Name: state.HostName("lr-v_own-b-" + suffix)},
		{ID: "vol_01J9Z8X7W6V5T4S3R2Q1P0KC12", Name: state.HostName("lr-v_own-c-" + suffix)},
	}
	if err := ns.Validate(); err != nil {
		t.Fatal(err)
	}
	watchUntil(t, e, ns, time.Minute, func() bool { return status(e.rec, wl).Phase == state.PhaseHealthy })
	c := status(e.rec, wl).ContainerID
	for target, want := range map[string]int{"/data": 1000, "/home": 1000, "/etc": 0} {
		rc, err := e.engine.StreamFromContainer(t.Context(), c, target)
		if err != nil {
			t.Fatal(err)
		}
		h, err := tar.NewReader(rc).Next()
		_ = rc.Close()
		if err != nil || h.Uid != want {
			t.Fatalf("%s: %+v %v, want uid %d", target, h, err, want)
		}
	}
}
