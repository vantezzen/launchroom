package caddy

import (
	"bufio"
	"bytes"
	"context"
	"os"
	"path/filepath"
	"regexp"
	"slices"
	"strconv"
	"strings"
	"time"

	"github.com/vantezzen/launchroom/daemon/internal/docker"
	"github.com/vantezzen/launchroom/daemon/internal/names"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// Why the edge's Caddy isn't serving (IngressStatus.reason/port/process/
// output, docs/03 §9): launchroomd looks at the Caddy container and its last
// log lines once Caddy has been unreachable for a while, and names the
// program that holds the port when Caddy can't bind it. It never stops that
// program (no host commands): the host owner does, and the next pass loads
// the config by itself.

// diagnoseAfter is how long Caddy must be unreachable before launchroomd
// reads its container and logs (a restart takes a few seconds).
const diagnoseAfter = time.Minute

// failure is the classified reason of a state other than applied.
type failure struct {
	reason  state.IngressFailureReason
	port    int
	process string
	output  []state.LogLine
}

var reBindInUse = regexp.MustCompile(`listen (?:tcp|udp)[46]? [^ ]*:([0-9]{1,5}): bind: address already in use`)

// classifyCaddyOutput reads Caddy's last log lines: a port it can't bind
// (port_in_use with the port), else zero.
func classifyCaddyOutput(lines []string) (state.IngressFailureReason, int) {
	for i := len(lines) - 1; i >= 0; i-- {
		if m := reBindInUse.FindStringSubmatch(lines[i]); m != nil {
			if p, err := strconv.Atoi(m[1]); err == nil && p > 0 && p < 65536 {
				return state.IngressPortInUse, p
			}
		}
	}
	return "", 0
}

// diagnose looks at the Caddy container and its last log lines.
func (m *Manager) diagnose(ctx context.Context) *failure {
	ctx, cancel := context.WithTimeout(ctx, 10*time.Second)
	defer cancel()
	f := &failure{reason: state.IngressOtherFailure}
	cs, err := m.list(ctx)
	if err != nil {
		return f
	}
	var c *docker.Container
	for i := range cs {
		if cs[i].Name == names.CaddyContainer {
			c = &cs[i]
		}
	}
	if c == nil {
		f.reason = state.IngressNotRunning
		return f
	}
	var texts []string
	_ = m.o.Docker.StreamLogs(ctx, c.ID, docker.LogsOptions{Tail: state.MaxIngressOutputLines}, func(l docker.LogLine) error {
		texts = append(texts, l.Text)
		f.output = append(f.output, outputLine(l))
		return nil
	})
	if len(f.output) > state.MaxIngressOutputLines {
		f.output = f.output[len(f.output)-state.MaxIngressOutputLines:]
	}
	if reason, port := classifyCaddyOutput(texts); reason != "" {
		f.reason, f.port = reason, port
		f.process = PortHolder(m.procRoot(), port)
		return f
	}
	if c.State != docker.StateRunning {
		f.reason = state.IngressNotRunning // exited, or restarting after crashes
	}
	return f
}

// outputLine is one of Caddy's log lines for the report: printable text of
// at most 2 KiB.
func outputLine(l docker.LogLine) state.LogLine {
	stream := state.StreamStdout
	if l.Stream == "stderr" {
		stream = state.StreamStderr
	}
	at := l.Time.UTC()
	if at.IsZero() {
		at = time.Now().UTC()
	}
	return state.LogLine{Time: at, Stream: stream, Text: plainText(l.Text, 2048)}
}

// configRefused is the failure of a config Caddy refused: its last log
// lines say why, next to the admin API's answer. A running Caddy refuses a
// config whose port it can't bind (port_in_use, like a Caddy that can't
// start at all).
func (m *Manager) configRefused(ctx context.Context, containerID string, loadErr error) *failure {
	f := &failure{reason: state.IngressConfigRefused}
	ctx, cancel := context.WithTimeout(ctx, 10*time.Second)
	defer cancel()
	_ = m.o.Docker.StreamLogs(ctx, containerID, docker.LogsOptions{Tail: state.MaxIngressOutputLines}, func(l docker.LogLine) error {
		f.output = append(f.output, outputLine(l))
		return nil
	})
	if len(f.output) > state.MaxIngressOutputLines {
		f.output = f.output[len(f.output)-state.MaxIngressOutputLines:]
	}
	if reason, port := classifyCaddyOutput([]string{loadErr.Error()}); reason != "" {
		f.reason, f.port = reason, port
		f.process = PortHolder(m.procRoot(), port)
	}
	return f
}

func (m *Manager) procRoot() string {
	if m.o.ProcRoot != "" {
		return m.o.ProcRoot
	}
	return "/proc"
}

// PortHolder names the process listening on TCP port in the host's
// network namespace (launchroomd runs with the host's network and PID
// namespaces): /proc/net/tcp{,6} → socket inode → /proc/<pid>/fd →
// /proc/<pid>/comm. "" when not found.
func PortHolder(procRoot string, port int) string {
	var inodes []string
	for _, f := range []string{"net/tcp", "net/tcp6"} {
		inodes = append(inodes, listenInodes(filepath.Join(procRoot, f), port)...)
	}
	if len(inodes) == 0 {
		return ""
	}
	want := map[string]bool{}
	for _, ino := range inodes {
		want["socket:["+ino+"]"] = true
	}
	pids, err := os.ReadDir(procRoot)
	if err != nil {
		return ""
	}
	for _, p := range pids {
		if _, err := strconv.Atoi(p.Name()); err != nil {
			continue
		}
		fdDir := filepath.Join(procRoot, p.Name(), "fd")
		fds, err := os.ReadDir(fdDir)
		if err != nil {
			continue
		}
		for _, fd := range fds {
			if link, err := os.Readlink(filepath.Join(fdDir, fd.Name())); err == nil && want[link] {
				comm, err := os.ReadFile(filepath.Join(procRoot, p.Name(), "comm")) //nolint:gosec // /proc paths
				if err != nil {
					return ""
				}
				return state.ClipLine(strings.TrimSpace(string(comm)), 64)
			}
		}
	}
	return ""
}

// listenInodes are the inodes of sockets listening on port in a
// /proc/net/tcp{,6} table.
func listenInodes(file string, port int) []string {
	data, err := os.ReadFile(file) //nolint:gosec // /proc paths
	if err != nil {
		return nil
	}
	suffix := ":" + strings.ToUpper(strconv.FormatInt(int64(port), 16))
	for len(suffix) < 5 {
		suffix = ":0" + suffix[1:]
	}
	var out []string
	sc := bufio.NewScanner(bytes.NewReader(data))
	for sc.Scan() {
		f := strings.Fields(sc.Text())
		// sl local_address rem_address st tx:rx tr:tm retrnsmt uid timeout inode
		if len(f) < 10 || f[3] != "0A" || !strings.HasSuffix(f[1], suffix) {
			continue
		}
		if f[9] != "0" && !slices.Contains(out, f[9]) {
			out = append(out, f[9])
		}
	}
	return out
}
