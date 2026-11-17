package hostinfo

import (
	"bufio"
	"bytes"
	"encoding/json"
	"os"
	"path/filepath"
	"runtime/debug"
	"strconv"
	"strings"
	"sync"
	"time"
	"unicode/utf8"

	"github.com/vantezzen/launchroom/daemon/internal/atomicfile"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// Files of launchroomd's own run in its data dir (Report.host.daemon,
// docs/03 §9).
const (
	// RunMarkerFile exists while launchroomd runs; a clean shutdown removes
	// it, so finding it at start means the previous run crashed, was killed
	// or the host lost power.
	RunMarkerFile = "run.marker"
	// CrashFile receives the Go runtime's crash output of the running
	// process (debug.SetCrashOutput) and the self-watchdog's stall note.
	CrashFile = "crash.log"
	// PreviousExitFile keeps an unclean previous exit until a report
	// carrying it was accepted (it survives another restart).
	PreviousExitFile = "previous-exit.json"
	// HeartbeatFile is touched while launchroomd's loops make progress
	// (`launchroomd healthcheck`, the previous run's last sign of life).
	HeartbeatFile = "heartbeat"
)

// DaemonRun tracks the running launchroomd: when it and the host started,
// and how its previous run ended.
type DaemonRun struct {
	dir     string
	started time.Time
	booted  *time.Time

	mu    sync.Mutex
	prev  *state.DaemonExit
	crash *os.File
}

// StartRun records this run's start in dataDir: it reads how the previous
// run ended (the marker, its crash output, its last heartbeat), writes the
// marker and sends this process's crash output to CrashFile. procRoot is
// /proc (the host's boot time is /proc/stat btime).
func StartRun(dataDir, procRoot string, now time.Time) (*DaemonRun, error) {
	r := &DaemonRun{dir: dataDir, started: now.UTC(), booted: BootTime(procRoot)}
	if err := os.MkdirAll(dataDir, 0o700); err != nil {
		return nil, err
	}
	r.prev = r.loadPrevious()
	if exit := r.previousExit(); exit != nil {
		r.prev = exit // the newest unclean exit wins
		if raw, err := json.Marshal(exit); err == nil {
			if err := atomicfile.Write(r.path(PreviousExitFile), raw, 0o600); err != nil {
				return nil, err
			}
		}
	}
	if err := atomicfile.Write(r.path(RunMarkerFile), []byte(r.started.Format(time.RFC3339Nano)+"\n"), 0o600); err != nil {
		return nil, err
	}
	f, err := os.OpenFile(r.path(CrashFile), os.O_CREATE|os.O_TRUNC|os.O_WRONLY, 0o600)
	if err != nil {
		return nil, err
	}
	if err := debug.SetCrashOutput(f, debug.CrashOptions{}); err != nil {
		_ = f.Close()
		return nil, err
	}
	r.crash = f
	return r, nil
}

func (r *DaemonRun) path(name string) string { return filepath.Join(r.dir, name) }

// previousExit is how the run before this one ended: nil after a clean
// shutdown.
func (r *DaemonRun) previousExit() *state.DaemonExit {
	if _, err := os.Stat(r.path(RunMarkerFile)); err != nil {
		return nil
	}
	exit := &state.DaemonExit{Unclean: true}
	if fi, err := os.Stat(r.path(HeartbeatFile)); err == nil {
		at := fi.ModTime().UTC()
		exit.At = &at
	}
	if data, err := readBounded(r.path(CrashFile), 1<<20); err == nil {
		exit.Panic = crashText(data)
	}
	return exit
}

func (r *DaemonRun) loadPrevious() *state.DaemonExit {
	data, err := readBounded(r.path(PreviousExitFile), 64<<10)
	if err != nil {
		return nil
	}
	var exit state.DaemonExit
	if json.Unmarshal(data, &exit) != nil || !exit.Unclean {
		return nil
	}
	exit.Panic = crashText([]byte(exit.Panic))
	return &exit
}

func readBounded(path string, limit int64) ([]byte, error) {
	f, err := os.Open(path) //nolint:gosec // fixed file names in the data dir
	if err != nil {
		return nil, err
	}
	defer func() { _ = f.Close() }()
	buf := make([]byte, limit)
	n, err := f.Read(buf)
	if err != nil && n == 0 {
		return nil, err
	}
	return buf[:n], nil
}

// crashText makes crash output report-safe: valid UTF-8 text without
// control characters other than newlines and tabs, at most
// state.MaxPanicTrace bytes from its start (the panic message and the
// crashing goroutine come first).
func crashText(data []byte) string {
	data = bytes.TrimSpace(data)
	var b strings.Builder
	for len(data) > 0 {
		c, size := utf8.DecodeRune(data)
		data = data[size:]
		switch {
		case c == utf8.RuneError && size <= 1:
			c = '?'
		case c == '\n' || c == '\t':
		case c < 0x20 || c == 0x7f:
			c = ' '
		}
		if b.Len()+utf8.RuneLen(c) > state.MaxPanicTrace {
			break
		}
		b.WriteRune(c)
	}
	return b.String()
}

// Status is Report.host.daemon.
func (r *DaemonRun) Status() *state.DaemonRun {
	r.mu.Lock()
	defer r.mu.Unlock()
	d := &state.DaemonRun{StartedAt: r.started, HostBootedAt: r.booted}
	if r.prev != nil {
		p := *r.prev
		d.PreviousExit = &p
	}
	return d
}

// Ack forgets the previous exit once a report carrying d was accepted.
func (r *DaemonRun) Ack(d *state.DaemonRun) {
	if d == nil || d.PreviousExit == nil {
		return
	}
	r.mu.Lock()
	defer r.mu.Unlock()
	if r.prev == nil {
		return
	}
	r.prev = nil
	_ = os.Remove(r.path(PreviousExitFile))
}

// WriteCrash adds text to this run's crash output (the self-watchdog's
// stall note before it exits), so the next run reports it as the panic.
func (r *DaemonRun) WriteCrash(text []byte) {
	r.mu.Lock()
	defer r.mu.Unlock()
	if r.crash != nil {
		_, _ = r.crash.Write(text)
		_ = r.crash.Sync()
	}
}

// Stop marks a clean shutdown: the marker and the (empty) crash output go.
func (r *DaemonRun) Stop() {
	r.mu.Lock()
	defer r.mu.Unlock()
	_ = debug.SetCrashOutput(nil, debug.CrashOptions{})
	if r.crash != nil {
		_ = r.crash.Close()
		r.crash = nil
	}
	_ = os.Remove(r.path(CrashFile))
	_ = os.Remove(r.path(RunMarkerFile))
}

// BootTime is the host's boot time from <procRoot>/stat (btime); nil when
// unreadable. Inside launchroomd's container it is the host's.
func BootTime(procRoot string) *time.Time {
	data, err := readBounded(filepath.Join(procRoot, "stat"), 1<<20)
	if err != nil {
		return nil
	}
	sc := bufio.NewScanner(bytes.NewReader(data))
	sc.Buffer(make([]byte, 64<<10), 1<<20)
	for sc.Scan() {
		f := strings.Fields(sc.Text())
		if len(f) == 2 && f[0] == "btime" {
			s, err := strconv.ParseInt(f[1], 10, 64)
			if err != nil || s <= 0 {
				return nil
			}
			t := time.Unix(s, 0).UTC()
			return &t
		}
	}
	return nil
}
