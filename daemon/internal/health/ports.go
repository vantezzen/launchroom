package health

import (
	"bufio"
	"errors"
	"fmt"
	"io"
	"os"
	"path/filepath"
	"slices"
	"strconv"
	"strings"
)

// Listener is a listening TCP socket inside a container's network namespace.
type Listener struct {
	Port int
	// LoopbackOnly is true when every socket on this port is bound to 127.0.0.1/::1,
	// i.e. the app is unreachable from outside the container.
	LoopbackOnly bool
}

// PortScanner discovers listening ports of a process's network namespace.
type PortScanner interface {
	// Listeners returns the listening TCP sockets of pid's netns, or an error
	// when discovery is not possible (the caller reports "unknown").
	Listeners(pid int) ([]Listener, error)
}

// ProcScanner reads <Root>/<pid>/net/tcp and tcp6. It works when the daemon
// shares the PID namespace of the Docker daemon (the daemon container runs with
// pid: host in production; in the dev fake servers the daemon runs next to dockerd).
type ProcScanner struct {
	Root string // "/proc" by default
}

// ErrUnknown means ports cannot be discovered for this container.
var ErrUnknown = errors.New("health: listening ports unknown")

const maxProcNetBytes = 4 << 20

// Listeners implements PortScanner.
func (p ProcScanner) Listeners(pid int) ([]Listener, error) {
	if pid <= 0 {
		return nil, ErrUnknown
	}
	root := p.Root
	if root == "" {
		root = "/proc"
	}
	found := map[int]bool{} // port → loopback only
	read := 0
	for _, name := range []string{"tcp", "tcp6"} {
		f, err := os.Open(filepath.Join(root, strconv.Itoa(pid), "net", name)) //nolint:gosec // fixed /proc path built from an integer pid
		if err != nil {
			continue
		}
		err = parseProcNet(io.LimitReader(f, maxProcNetBytes), found)
		_ = f.Close()
		if err != nil {
			return nil, err
		}
		read++
	}
	if read == 0 {
		return nil, ErrUnknown
	}
	out := make([]Listener, 0, len(found))
	for port, lo := range found {
		out = append(out, Listener{Port: port, LoopbackOnly: lo})
	}
	slices.SortFunc(out, func(a, b Listener) int { return a.Port - b.Port })
	return out, nil
}

// parseProcNet parses a /proc/net/tcp{,6} table and records LISTEN sockets.
func parseProcNet(r io.Reader, found map[int]bool) error {
	sc := bufio.NewScanner(r)
	first := true
	for sc.Scan() {
		if first {
			first = false
			continue // header
		}
		fields := strings.Fields(sc.Text())
		if len(fields) < 4 || fields[3] != "0A" { // 0A = TCP_LISTEN
			continue
		}
		addr, portHex, ok := strings.Cut(fields[1], ":")
		if !ok {
			continue
		}
		port, err := strconv.ParseUint(portHex, 16, 16)
		if err != nil || port == 0 {
			continue
		}
		lo := isLoopbackHex(addr)
		prev, seen := found[int(port)]
		found[int(port)] = lo && (!seen || prev)
	}
	if err := sc.Err(); err != nil {
		return fmt.Errorf("health: parse proc net: %w", err)
	}
	return nil
}

// isLoopbackHex recognises 127.x.x.x (little-endian hex "xx00007F" style:
// last byte 7F) and ::1 / ::ffff:127.x in /proc's hex notation.
func isLoopbackHex(a string) bool {
	switch len(a) {
	case 8:
		return strings.HasSuffix(a, "7F")
	case 32:
		if a == "00000000000000000000000001000000" {
			return true
		}
		return strings.HasPrefix(a, "0000000000000000FFFF0000") && strings.HasSuffix(a, "7F")
	}
	return false
}

// Ports returns just the port numbers.
func Ports(ls []Listener) []int {
	out := make([]int, len(ls))
	for i, l := range ls {
		out[i] = l.Port
	}
	return out
}

// Hint explains a common misconfiguration when the configured port is not
// reachable, or "" if nothing useful can be said.
func Hint(port int, ls []Listener) string {
	if port <= 0 || ls == nil {
		return ""
	}
	for _, l := range ls {
		if l.Port == port {
			if l.LoopbackOnly {
				return fmt.Sprintf("the app listens on 127.0.0.1:%d only; bind to 0.0.0.0 so it is reachable", port)
			}
			return ""
		}
	}
	if len(ls) == 0 {
		return fmt.Sprintf("the app does not listen on any TCP port (expected %d)", port)
	}
	ports := make([]string, len(ls))
	for i, l := range ls {
		ports[i] = strconv.Itoa(l.Port)
	}
	return fmt.Sprintf("the app listens on %s, not %d", strings.Join(ports, ", "), port)
}
