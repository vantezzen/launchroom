package inspect

import (
	"cmp"
	"path"
	"slices"
	"strconv"
	"strings"
	"unicode/utf8"

	"github.com/vantezzen/launchroom/daemon/internal/build"
	"github.com/vantezzen/launchroom/daemon/internal/docker"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// The fixed rules of docs/03 §13.2. They are constants of launchroomd: the
// dashboard sends none of them.

// collapsedPrefixes are cache locations whose changes are counted, not
// listed. "*" matches one path segment; a pattern without a leading "/"
// matches at any depth.
var collapsedPrefixes = []string{
	"/root/.npm", "/root/.cache", "/root/.yarn", "/root/.local/share/pnpm",
	"/home/*/.npm", "/home/*/.cache", "/home/*/.yarn",
	".next/cache", "__pycache__",
	"/var/cache", "/var/log", "/var/lib/apt/lists", "/run", "/var/run",
}

// dockerInitPaths are where Docker mounts its init binary into a container
// started with HostConfig.Init (/sbin, or /usr/sbin where /sbin links there).
var dockerInitPaths = []string{"/sbin/docker-init", "/usr/sbin/docker-init"}

var (
	systemDirs = []string{"/bin", "/sbin", "/usr/bin", "/usr/sbin", "/usr/local/bin", "/usr/local/sbin", "/lib", "/lib64", "/usr/lib", "/usr/lib64"}
	tempDirs   = []string{"/tmp", "/var/tmp", "/dev/shm"}
	// sensitiveFiles are exact paths; sensitiveDirs match everything below.
	sensitiveFiles = []string{"/etc/passwd", "/etc/shadow", "/etc/group", "/etc/sudoers", "/etc/crontab", "/etc/ld.so.preload",
		"/etc/profile", "/etc/bash.bashrc", "/root/.bashrc", "/root/.profile"}
	sensitiveDirs = []string{"/etc/sudoers.d", "/var/spool/cron", "/etc/profile.d"}

	shells = []string{"sh", "bash", "ash", "dash", "zsh", "ksh"}
	// scriptRunners run package.json scripts through a shell with -c and the script (npm
	// start, yarn dev, …): such a shell is how the app starts, not a sign.
	scriptRunners = []string{"npm", "npx", "yarn", "pnpm", "bun", "npm-cli.js", "yarn.js", "yarn.cjs", "pnpm.cjs", "pnpm.js"}
	networkTools  = []string{"nc", "ncat", "netcat", "socat", "curl", "wget", "telnet", "ssh", "scp", "nmap", "masscan"}
	minerNames    = []string{"xmrig", "minerd", "cpuminer", "kinsing", "kdevtmpfsi"}
)

// under reports whether p is dir or below it.
func under(p, dir string) bool {
	return p == dir || strings.HasPrefix(p, dir+"/")
}

func underAny(p string, dirs []string) bool {
	return slices.ContainsFunc(dirs, func(d string) bool { return under(p, d) })
}

// collapsePrefix returns the concrete cache location p is in ("" when none).
func collapsePrefix(p string) string {
	segs := strings.Split(strings.TrimPrefix(p, "/"), "/")
	for _, pat := range collapsedPrefixes {
		if !strings.HasPrefix(pat, "/") {
			// At any depth: the first occurrence of the segment(s).
			ps := strings.Split(pat, "/")
			for i := 0; i+len(ps) <= len(segs); i++ {
				if slices.Equal(segs[i:i+len(ps)], ps) {
					return "/" + strings.Join(segs[:i+len(ps)], "/")
				}
			}
			continue
		}
		ps := strings.Split(strings.TrimPrefix(pat, "/"), "/")
		if len(segs) < len(ps) {
			continue
		}
		match := true
		for i, s := range ps {
			if s != "*" && s != segs[i] {
				match = false
				break
			}
		}
		if match {
			return "/" + strings.Join(segs[:len(ps)], "/")
		}
	}
	return ""
}

func sensitive(p string) bool {
	if slices.Contains(sensitiveFiles, p) || underAny(p, sensitiveDirs) || strings.HasPrefix(p, "/etc/cron.") {
		return true
	}
	return strings.Contains(p, "/.ssh/") || strings.HasSuffix(p, "/.ssh")
}

// inHome reports whether p is in a home directory (/root, /home/<user>).
func inHome(p string) bool {
	if under(p, "/root") {
		return true
	}
	segs := strings.Split(strings.TrimPrefix(p, "/"), "/")
	return len(segs) >= 2 && segs[0] == "home"
}

// hidden: a segment starting with "." outside home directories and the working directory.
func hidden(p, workDir string) bool {
	if inHome(p) || workDir != "" && workDir != "/" && under(p, workDir) {
		return false
	}
	for _, s := range strings.Split(p, "/") {
		if strings.HasPrefix(s, ".") && s != "." && s != ".." {
			return true
		}
	}
	return false
}

// pathFlags are the flags of a path known without a stat.
func pathFlags(p, workDir string) []state.ChangeFlag {
	var f []state.ChangeFlag
	if underAny(p, systemDirs) {
		f = append(f, state.ChangeFlagSystemPath)
	}
	if sensitive(p) {
		f = append(f, state.ChangeFlagSensitiveFile)
	}
	if workDir != "" && workDir != "/" && under(p, workDir) {
		f = append(f, state.ChangeFlagAppCode)
	}
	if underAny(p, tempDirs) {
		f = append(f, state.ChangeFlagTempDir)
	}
	if hidden(p, workDir) {
		f = append(f, state.ChangeFlagHidden)
	}
	return f
}

// rank orders entries: concerning flags, other flags, app code, the rest.
func rank(flags []state.ChangeFlag) int {
	switch {
	case slices.ContainsFunc(flags, func(f state.ChangeFlag) bool {
		return f == state.ChangeFlagExecutable || f == state.ChangeFlagSystemPath || f == state.ChangeFlagSensitiveFile
	}):
		return 0
	case slices.Contains(flags, state.ChangeFlagTempDir) || slices.Contains(flags, state.ChangeFlagHidden):
		return 1
	case slices.Contains(flags, state.ChangeFlagAppCode):
		return 2
	}
	return 3
}

// Escape makes a container-chosen name printable: control bytes, DEL and
// invalid UTF-8 become \xNN, a backslash \\; the result is cut to max bytes
// at a character boundary.
func Escape(s string, maxBytes int) string {
	var b strings.Builder
	for i := 0; i < len(s); {
		r, size := utf8.DecodeRuneInString(s[i:])
		var piece string
		switch {
		case r == utf8.RuneError && size <= 1:
			piece = `\x` + hex2(s[i])
		case r < 0x20 || r == 0x7f:
			piece = `\x` + hex2(s[i])
		case r == '\\':
			piece = `\\`
		case r >= 0x80 && r < 0xa0:
			// C1 controls: escape every byte.
			for j := range size {
				piece += `\x` + hex2(s[i+j])
			}
		default:
			piece = s[i : i+size]
		}
		if b.Len()+len(piece) > maxBytes {
			break
		}
		b.WriteString(piece)
		i += size
	}
	return b.String()
}

func hex2(c byte) string {
	const digits = "0123456789abcdef"
	return string([]byte{digits[c>>4], digits[c&0xf]})
}

// changeKind maps Docker's kind.
func changeKind(k docker.ChangeKind) state.ChangeKind {
	switch k {
	case docker.ChangeAdded:
		return state.ChangeAdded
	case docker.ChangeDeleted:
		return state.ChangeDeleted
	case docker.ChangeModified:
	}
	return state.ChangeModified
}

func sortEntries(entries []state.ContainerChange) {
	slices.SortStableFunc(entries, func(a, b state.ContainerChange) int {
		return cmp.Or(cmp.Compare(rank(a.Flags), rank(b.Flags)), strings.Compare(string(a.Path), string(b.Path)))
	})
}

// ---- processes ----

// process is one row of Docker's top.
type process struct {
	pid, ppid int
	user      string
	command   string // raw, unmasked
}

// parseTop reads Docker's top output (ps -ef columns: UID PID PPID … CMD).
// parentsKnown is false when there is no PPID column (busybox ps).
func parseTop(top docker.Processes) (procs []process, parentsKnown bool) {
	col := func(name string) int { return slices.Index(top.Titles, name) }
	iUID, iPID, iPPID, iCmd := col("UID"), col("PID"), col("PPID"), col("CMD")
	if iCmd < 0 {
		iCmd = col("COMMAND")
	}
	if iUID < 0 {
		iUID = col("USER")
	}
	if iPID < 0 || iCmd < 0 {
		return nil, iPPID >= 0
	}
	var out []process
	seen := map[int]bool{}
	for _, row := range top.Processes {
		if iPID >= len(row) || iCmd >= len(row) {
			continue
		}
		pid, err := strconv.Atoi(strings.TrimSpace(row[iPID]))
		if err != nil || pid < 1 || seen[pid] {
			continue
		}
		seen[pid] = true
		p := process{pid: pid, command: row[iCmd]}
		if iPPID >= 0 && iPPID < len(row) {
			if n, err := strconv.Atoi(strings.TrimSpace(row[iPPID])); err == nil && n >= 0 {
				p.ppid = n
			}
		}
		if iUID >= 0 && iUID < len(row) {
			p.user = row[iUID]
		}
		out = append(out, p)
	}
	slices.SortFunc(out, func(a, b process) int { return cmp.Compare(a.pid, b.pid) })
	return out, iPPID >= 0
}

// user makes a ps user column fit InspectedUser.
func user(s string) state.InspectedUser {
	var b strings.Builder
	for _, r := range s {
		if b.Len() >= 32 {
			break
		}
		if r < 0x80 && (r >= 'a' && r <= 'z' || r >= 'A' && r <= 'Z' || r >= '0' && r <= '9' || strings.ContainsRune("._+-", r)) {
			b.WriteRune(r)
		} else {
			b.WriteByte('_')
		}
	}
	if b.Len() == 0 {
		return "unknown"
	}
	return state.InspectedUser(b.String())
}

// program returns the first token of a command line and its base name.
func program(cmd string) (string, string) {
	fields := strings.Fields(cmd)
	if len(fields) == 0 {
		return "", ""
	}
	return fields[0], path.Base(fields[0])
}

// interpreterFlags are the interpreters that run code given on the command line.
func inlineCode(base string, args []string) bool {
	has := func(flags ...string) bool {
		return slices.ContainsFunc(args, func(a string) bool { return slices.Contains(flags, a) })
	}
	switch {
	case strings.HasPrefix(base, "python"):
		return has("-c")
	case strings.HasPrefix(base, "perl"):
		return has("-e", "-E")
	case base == "node" || base == "nodejs" || base == "bun" || base == "deno":
		return has("-e", "--eval", "-p", "--print")
	case strings.HasPrefix(base, "php"):
		return has("-r")
	case strings.HasPrefix(base, "ruby"):
		return has("-e")
	case slices.Contains(shells, base):
		return has("-c")
	}
	return false
}

// scriptRunner reports whether a command is a package manager running a
// script: "npm run start", or "node /usr/local/bin/pnpm start".
func scriptRunner(cmd string) bool {
	fields := strings.Fields(cmd)
	if len(fields) == 0 {
		return false
	}
	base := path.Base(fields[0])
	if slices.Contains(scriptRunners, base) {
		return true
	}
	return (base == "node" || base == "nodejs" || base == "bun") && len(fields) > 1 && slices.Contains(scriptRunners, path.Base(fields[1]))
}

// processFlags classifies one process. entry is whether it is the
// container's first process; parent is its parent's raw command ("" when
// unknown).
func processFlags(p process, entry bool, parent string, added map[string]bool) []state.ProcessFlag {
	prog, base := program(p.command)
	var f []state.ProcessFlag
	if entry {
		f = append(f, state.ProcessFlagEntry)
	}
	runsScript := slices.Contains(shells, base) && scriptRunner(parent) && slices.Contains(strings.Fields(p.command), "-c")
	if !entry && slices.Contains(shells, base) && !runsScript {
		f = append(f, state.ProcessFlagShell)
	}
	if slices.Contains(networkTools, base) {
		f = append(f, state.ProcessFlagNetworkTool)
	}
	if slices.ContainsFunc(minerNames, func(m string) bool { return strings.Contains(strings.ToLower(base), m) }) {
		f = append(f, state.ProcessFlagMiner)
	}
	if underAny(prog, tempDirs) && prog != "/tmp" && prog != "/var/tmp" && prog != "/dev/shm" {
		f = append(f, state.ProcessFlagTempExecutable)
	}
	if !entry && !runsScript {
		if fields := strings.Fields(p.command); len(fields) > 1 && inlineCode(base, fields[1:]) {
			f = append(f, state.ProcessFlagInlineCode)
		}
	}
	if strings.HasPrefix(prog, "/") && added[path.Clean(prog)] {
		f = append(f, state.ProcessFlagAddedBinary)
	}
	return f
}

// commandText is a process command as reported: masked against the
// workload's secret values, escaped, cut to 512 bytes.
func commandText(cmd string, m *build.Masker) string {
	return Escape(m.Mask(cmd), maxCommand)
}
