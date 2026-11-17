package doctor

import (
	"bufio"
	"context"
	"encoding/json"
	"fmt"
	"os"
	"path/filepath"
	"slices"
	"strconv"
	"strings"
	"time"

	"golang.org/x/sys/unix"

	"github.com/vantezzen/launchroom/daemon/internal/names"
	"github.com/vantezzen/launchroom/daemon/internal/update"
)

// Groups, in the order they print.
const (
	groupHost      = "Server"
	groupServices  = "Services"
	groupDatabase  = "Database"
	groupCache     = "Cache and queues"
	groupRegistry  = "Image registry"
	groupDashboard = "Dashboard"
	groupAddress   = "Address"
	groupDaemon    = "launchroomd"
	groupUpdates   = "Updates"
)

// cpServices are the control plane's compose services and how the doctor
// names them.
var cpServices = []struct{ name, title, pin string }{
	{"postgres", "Database (Postgres)", "LR_POSTGRES_IMAGE"},
	{"valkey", "Cache and queues (Valkey)", "LR_VALKEY_IMAGE"},
	{"dashboard", "Dashboard", "LR_DASHBOARD_IMAGE"},
	{"horizon", "Background jobs", "LR_DASHBOARD_IMAGE"},
	{"scheduler", "Schedule", "LR_DASHBOARD_IMAGE"},
	{"reverb", "Live updates", "LR_DASHBOARD_IMAGE"},
	{"zot", "Image registry", "LR_ZOT_IMAGE"},
	{"headscale", "Private network", "LR_HEADSCALE_IMAGE"},
	{"dockerd-guard", "Docker access guard", "LR_DAEMON_IMAGE"},
	{"launchroomd", "launchroomd", "LR_DAEMON_IMAGE"},
}

// restartable are the services the restart fix may restart. Postgres and
// Valkey are only ever started, never restarted from here.
var restartable = []string{"dashboard", "horizon", "reverb", "scheduler", "zot", "headscale"}

const cpProject = "launchroom"

func (r *run) load(ctx context.Context) {
	if r.o.Mode == ModeControlPlane {
		if env, err := update.ReadDotEnv(filepath.Join(r.o.Dir, ".env")); err == nil {
			r.env = env
		}
	}
	r.within(ctx, 10*time.Second, func(ctx context.Context) {
		r.info, r.dockerErr = r.o.Engine.Info(ctx)
		if r.dockerErr != nil && r.o.Mode == ModeServer {
			// dockerd-guard may not pass /info through: listing still works.
			r.dockerErr = nil
		}
		if r.dockerErr != nil {
			return
		}
		var err error
		if r.o.Mode == ModeControlPlane {
			r.containers, err = r.o.Engine.Containers(ctx, labelProject+"="+cpProject)
			if err == nil {
				var managed []Container
				managed, err = r.o.Engine.Containers(ctx, names.LabelManaged+"=true")
				for _, c := range managed {
					if c.Project != cpProject {
						r.containers = append(r.containers, c)
					}
				}
			}
		} else {
			r.containers, err = r.o.Engine.Containers(ctx, names.LabelManaged+"=true")
		}
		r.dockerErr = err
	})
}

func (r *run) controlPlaneChecks(ctx context.Context) {
	r.checkDocker()
	r.checkDisk()
	r.checkMemory()
	if r.dockerErr != nil {
		r.skipAll("Docker isn't answering, so nothing else could be checked.", groupServices, groupDatabase, groupCache, groupRegistry, groupDashboard, groupAddress, groupDaemon)
		r.checkUpdates()
		return
	}
	r.checkOOM()
	r.checkMemoryProtection()
	for _, s := range cpServices {
		r.checkService(ctx, s.name, s.title, s.pin)
	}
	r.checkPostgres(ctx)
	r.checkValkey(ctx)
	r.checkRegistry(ctx)
	r.checkDashboard(ctx)
	r.checkArtisan(ctx)
	r.checkAddress(ctx)
	r.checkDaemonFiles()
	r.checkDaemonLogs(ctx)
	r.checkUpdates()
}

func (r *run) serverChecks(ctx context.Context) {
	r.checkDisk()
	r.checkMemory()
	if r.dockerErr != nil {
		r.add(Result{ID: "host.docker", Group: groupHost, Title: "Docker", Status: StatusFail,
			Summary: "launchroomd can't reach Docker through its guard: " + clip(r.dockerErr.Error(), 200),
			Detail:  []string{"Check the guard with: docker compose ps dockerd-guard"}})
	} else {
		r.checkOOM()
		r.checkRestarting()
	}
	r.checkControlPlaneConnection(ctx)
	r.checkDaemonFiles()
	if r.dockerErr == nil {
		if _, ok := r.byName(names.SystemPrefix + "caddy"); ok {
			r.checkPorts()
		}
	}
	r.checkUpdates()
}

func (r *run) skipAll(why string, groups ...string) {
	for _, g := range groups {
		r.add(Result{ID: strings.ToLower(strings.ReplaceAll(g, " ", "-")), Group: g, Title: g, Status: StatusSkip, Summary: why})
	}
}

func (r *run) byName(name string) (Container, bool) {
	for _, c := range r.containers {
		if c.Name == name {
			return c, true
		}
	}
	return Container{}, false
}

// --- Server ---------------------------------------------------------------

func (r *run) checkDocker() {
	res := Result{ID: "host.docker", Group: groupHost, Title: "Docker"}
	switch {
	case r.dockerErr != nil:
		res.Status, res.Summary = StatusFail, "Docker isn't answering: "+clip(r.dockerErr.Error(), 200)
		res.Detail = []string{"Start it with: sudo systemctl start docker"}
	case r.info.CgroupVersion == "1":
		res.Status, res.Summary = StatusWarn, "Docker "+r.info.ServerVersion+" on cgroup v1: memory limits and priorities work only partly."
	default:
		res.Status, res.Summary = StatusOK, "Docker "+r.info.ServerVersion+" is running."
		if r.info.Rootless {
			res.Summary = "Docker " + r.info.ServerVersion + " is running rootless."
		}
	}
	r.add(res)
}

// Disk thresholds: fail below 2 % or 1 GiB free, warn below 10 % or 5 GiB.
const (
	gib = 1 << 30
)

type fsUsage struct {
	Total, Free         uint64
	Inodes, InodesFree  uint64
	Path, Name          string
	FreePct, InodesFPct float64
}

func statFS(path string) (fsUsage, error) {
	var st unix.Statfs_t
	if err := unix.Statfs(path, &st); err != nil {
		return fsUsage{}, err
	}
	// Field types differ by platform; block counts are never negative.
	bs := uint64(st.Bsize)                                                                                                        //nolint:gosec,unconvert,nolintlint // see above
	u := fsUsage{Path: path, Total: uint64(st.Blocks) * bs, Free: uint64(st.Bavail) * bs, Inodes: st.Files, InodesFree: st.Ffree} //nolint:unconvert,nolintlint // see above
	if u.Total > 0 {
		u.FreePct = float64(u.Free) * 100 / float64(u.Total)
	}
	if u.Inodes > 0 {
		u.InodesFPct = float64(u.InodesFree) * 100 / float64(u.Inodes)
	}
	return u, nil
}

func (r *run) checkDisk() {
	type target struct{ id, name, path string }
	var targets []target
	if r.o.Mode == ModeControlPlane {
		targets = append(targets, target{"host.disk.install", "the install folder (" + r.o.Dir + ")", r.o.Dir})
		if r.o.HostRoot != "" && r.info.DockerRootDir != "" {
			targets = append(targets, target{"host.disk.docker", "Docker's storage (" + r.info.DockerRootDir + ")", filepath.Join(r.o.HostRoot, r.info.DockerRootDir)})
		}
	} else {
		targets = append(targets, target{"host.disk.install", "launchroomd's folder", r.o.DataDir})
		if r.o.DockerFS != "" {
			targets = append(targets, target{"host.disk.docker", "Docker's storage", r.o.DockerFS})
		}
	}
	seen := map[uint64]bool{}
	for _, t := range targets {
		u, err := statFS(t.path)
		if err != nil {
			r.add(Result{ID: t.id, Group: groupHost, Title: "Disk", Status: StatusSkip, Summary: "Couldn't measure " + t.name + "."})
			continue
		}
		// Same filesystem twice (the common case): report it once.
		key := u.Total ^ u.Inodes<<1
		if seen[key] {
			continue
		}
		seen[key] = true
		r.add(diskResult(t.id, t.name, u))
	}
}

func diskResult(id, name string, u fsUsage) Result {
	res := Result{ID: id, Group: groupHost, Title: "Disk", Status: StatusOK,
		Summary: fmt.Sprintf("%s free of %s on %s.", bytesWord(u.Free), bytesWord(u.Total), name)}
	switch {
	case u.Free < gib || u.FreePct < 2:
		res.Status = StatusFail
		res.Summary = fmt.Sprintf("Only %s free on %s. Databases and builds stop working when it is full.", bytesWord(u.Free), name)
	case u.Free < 5*gib || u.FreePct < 10:
		res.Status = StatusWarn
		res.Summary = fmt.Sprintf("%s free on %s (%.0f %%). It's getting full.", bytesWord(u.Free), name, u.FreePct)
	}
	if u.Inodes > 0 && (u.InodesFPct < 1 || u.InodesFree < 10000) {
		res.Status = StatusFail
		res.Detail = append(res.Detail, fmt.Sprintf("Only %d file entries (inodes) left: new files can't be created even with space free.", u.InodesFree))
	} else if u.Inodes > 0 && u.InodesFPct < 5 && res.Status == StatusOK {
		res.Status = StatusWarn
		res.Detail = append(res.Detail, fmt.Sprintf("%.1f %% of file entries (inodes) left.", u.InodesFPct))
	}
	if res.Status != StatusOK {
		res.Fix = &FixRef{ID: FixFreeDisk, Does: "Removes launchroom's leftovers: an old pre-update database copy, finished helper containers and old update folders."}
	}
	return res
}

func (r *run) checkMemory() {
	f, err := os.Open(filepath.Join(r.o.ProcDir, "meminfo"))
	if err != nil {
		r.add(Result{ID: "host.memory", Group: groupHost, Title: "Memory", Status: StatusSkip, Summary: "Couldn't read the memory figures."})
		return
	}
	defer func() { _ = f.Close() }()
	vals := map[string]uint64{}
	sc := bufio.NewScanner(f)
	for sc.Scan() {
		fields := strings.Fields(sc.Text())
		if len(fields) >= 2 {
			v, _ := strconv.ParseUint(fields[1], 10, 64)
			vals[strings.TrimSuffix(fields[0], ":")] = v * 1024
		}
	}
	total, avail := vals["MemTotal"], vals["MemAvailable"]
	if total == 0 {
		r.add(Result{ID: "host.memory", Group: groupHost, Title: "Memory", Status: StatusSkip, Summary: "Couldn't read the memory figures."})
		return
	}
	pct := float64(avail) * 100 / float64(total)
	res := Result{ID: "host.memory", Group: groupHost, Title: "Memory", Status: StatusOK,
		Summary: fmt.Sprintf("%s available of %s.", bytesWord(avail), bytesWord(total))}
	switch {
	case pct < 5:
		res.Status = StatusFail
		res.Summary = fmt.Sprintf("Only %s of %s memory available. The system may stop processes to free memory.", bytesWord(avail), bytesWord(total))
	case pct < 10:
		res.Status = StatusWarn
		res.Summary = fmt.Sprintf("%s of %s memory available (%.0f %%).", bytesWord(avail), bytesWord(total), pct)
	}
	if vals["SwapTotal"] == 0 && total < 4*gib {
		res.Detail = append(res.Detail, "No swap: builds on this server can run out of memory.")
	}
	r.add(res)
}

func (r *run) checkOOM() {
	var killed []string
	for _, c := range r.containers {
		if c.OOMKilled {
			killed = append(killed, displayName(c))
		}
	}
	if len(killed) == 0 {
		r.add(Result{ID: "host.oom", Group: groupHost, Title: "Out of memory", Status: StatusOK, Summary: "No container was stopped for lack of memory."})
		return
	}
	r.add(Result{ID: "host.oom", Group: groupHost, Title: "Out of memory", Status: StatusWarn,
		Summary: "The system stopped these for lack of memory: " + strings.Join(killed, ", ") + "."})
}

func (r *run) checkRestarting() {
	var bad []string
	for _, c := range r.containers {
		if c.Restarting || (!c.Running && c.Status == "exited" && c.ExitCode != 0 && c.Role != roleDoctor && c.Role != names.RoleUpdater) {
			bad = append(bad, fmt.Sprintf("%s (exit code %d)", displayName(c), c.ExitCode))
		}
	}
	if len(bad) == 0 {
		r.add(Result{ID: "host.containers", Group: groupHost, Title: "Containers", Status: StatusOK, Summary: fmt.Sprintf("%d containers, none crashing.", len(r.containers))})
		return
	}
	r.add(Result{ID: "host.containers", Group: groupHost, Title: "Containers", Status: StatusWarn,
		Summary: "Stopped with an error or restarting: " + strings.Join(bad, ", ") + ". The dashboard shows why."})
}

// checkMemoryProtection: control-plane services should be the last the
// kernel stops when memory runs out (OD19).
func (r *run) checkMemoryProtection() {
	pg, ok := r.service("postgres")
	if !ok {
		return
	}
	res := Result{ID: "host.memory-priority", Group: groupHost, Title: "Memory priority", Status: StatusOK,
		Summary: "The database and the dashboard are the last the system stops when memory runs out."}
	if pg.OomScoreAdj >= 0 {
		res.Status = StatusWarn
		res.Summary = "The database has no memory priority: when memory runs out, the system may stop it before an app."
		if r.info.Rootless {
			res.Detail = []string{"Rootless Docker can't give containers a higher priority."}
		} else {
			res.Fix = &FixRef{ID: FixStart, Target: "postgres", Does: "Recreates the database container with the priority from compose.yml (a few seconds of downtime)."}
		}
	}
	r.add(res)
}

// --- Services -------------------------------------------------------------

func (r *run) checkService(ctx context.Context, name, title, pinKey string) {
	id := "service." + name
	c, ok := r.service(name)
	res := Result{ID: id, Group: groupServices, Title: title}
	start := &FixRef{ID: FixStart, Target: name, Does: "Starts " + title + " with docker compose."}
	switch {
	case !ok:
		res.Status, res.Summary, res.Fix = StatusFail, "Not created.", start
		if name == "launchroomd" {
			res.Detail = []string{"Run the installer again to enroll it."}
			res.Fix = nil
		}
		r.add(res)
		return
	case c.Restarting:
		res.Status = StatusFail
		res.Summary = fmt.Sprintf("Keeps restarting (exit code %d, %d restarts).", c.ExitCode, c.RestartCount)
	case !c.Running:
		res.Status, res.Fix = StatusFail, start
		res.Summary = "Not running."
		if c.Status == "exited" {
			res.Summary = fmt.Sprintf("Not running: it stopped %s with exit code %d.", ago(r.o.Now(), c.FinishedAt), c.ExitCode)
		}
	case c.Health == "unhealthy":
		res.Status = StatusFail
		res.Summary = "Running, but its health check fails."
		if c.HealthLog != "" {
			res.Detail = append(res.Detail, "Last check: "+clip(c.HealthLog, 200))
		}
		if slices.Contains(restartable, name) {
			res.Fix = &FixRef{ID: FixRestart, Target: name, Does: "Restarts " + title + "."}
		}
	case c.Health == "starting":
		res.Status, res.Summary = StatusWarn, "Starting."
	default:
		res.Status = StatusOK
		res.Summary = "Running since " + ago(r.o.Now(), c.StartedAt) + "."
		if c.Health == "healthy" {
			res.Summary = "Running and healthy since " + ago(r.o.Now(), c.StartedAt) + "."
		}
	}
	if c.OOMKilled {
		res.Detail = append(res.Detail, "It was stopped for lack of memory.")
	}
	if res.Status == StatusFail && !c.Healthy() {
		res.Detail = append(res.Detail, "Its log: ./launchroom logs "+name)
	}
	if want := r.env[pinKey]; want != "" && c.Image != "" && c.Image != want && res.Status == StatusOK {
		res.Status = StatusWarn
		res.Summary = "Runs an image other than the one .env pins."
		res.Detail = append(res.Detail, "Runs "+shortImage(c.Image)+", .env pins "+shortImage(want)+".")
		res.Fix = &FixRef{ID: FixStart, Target: name, Does: "Recreates " + title + " from the pinned image."}
	}
	_ = ctx
	r.add(res)
}

// --- Database and cache ---------------------------------------------------

func (r *run) checkPostgres(ctx context.Context) {
	c, ok := r.service("postgres")
	logs := ""
	if ok {
		r.within(ctx, 0, func(ctx context.Context) { logs, _ = r.o.Engine.Logs(ctx, c, 50) })
	}
	if m := matchLog(logs, postgresPatterns); m != "" {
		// A healthy database may have logged it before it recovered.
		st := StatusWarn
		if !c.Healthy() {
			st = StatusFail
		}
		r.add(Result{ID: "db.log", Group: groupDatabase, Title: "Database log", Status: st, Summary: m,
			Detail: []string{"If the database can't recover, restore the last control-plane backup with ./launchroom restore."}})
	}
	if ok && c.Running {
		r.within(ctx, 10*time.Second, func(ctx context.Context) {
			code, out, err := r.o.Engine.Exec(ctx, c, []string{"psql", "-U", "launchroom", "-d", "launchroom", "-tAF", "|", "-c",
				"select pg_database_size('launchroom'), (select count(*) from pg_stat_activity), current_setting('max_connections')"})
			res := Result{ID: "db.ready", Group: groupDatabase, Title: "Database", Status: StatusOK}
			if err != nil || code != 0 {
				res.Status, res.Summary = StatusFail, "The database doesn't answer queries."
				if line := lastLine(out); line != "" {
					res.Detail = []string{clip(line, 200)}
				}
				r.add(res)
				return
			}
			parts := strings.Split(lastLine(out), "|")
			if len(parts) == 3 {
				size, _ := strconv.ParseUint(parts[0], 10, 64)
				conns, _ := strconv.Atoi(parts[1])
				maxc, _ := strconv.Atoi(parts[2])
				res.Summary = fmt.Sprintf("Answering, %s, %d of %d connections in use.", bytesWord(size), conns, maxc)
				if maxc > 0 && conns*100/maxc >= 90 {
					res.Status = StatusWarn
				}
			} else {
				res.Summary = "Answering."
			}
			r.add(res)
		})
	}
	dump := filepath.Join(r.o.Dir, "data", "postgres", "launchroom-pre-upgrade.dump")
	if st, err := os.Stat(dump); err == nil && r.updateIdle() {
		r.add(Result{ID: "db.dump", Group: groupDatabase, Title: "Pre-update copy", Status: StatusWarn,
			Summary: fmt.Sprintf("A database copy from an update %s is still there (%s).", ago(r.o.Now(), st.ModTime()), bytesWord(uint64(st.Size()))), //nolint:gosec // file sizes are non-negative
			Fix:     &FixRef{ID: FixFreeDisk, Does: "Removes the copy once no update needs it any more."}})
	}
}

func (r *run) checkValkey(ctx context.Context) {
	c, ok := r.service("valkey")
	if !ok {
		return
	}
	logs := ""
	r.within(ctx, 0, func(ctx context.Context) { logs, _ = r.o.Engine.Logs(ctx, c, 50) })
	if m := matchLog(logs, valkeyPatterns); m != "" && !c.Healthy() {
		r.add(Result{ID: "cache.aof", Group: groupCache, Title: "Cache file", Status: StatusFail, Summary: m,
			Fix: &FixRef{ID: FixRepairValkeyAOF, Target: "valkey", Does: "Copies the cache's append-only file aside, repairs it with valkey-check-aof (the damaged tail is cut off) and starts Valkey."}})
		return
	}
	if !c.Running {
		return
	}
	r.within(ctx, 0, func(ctx context.Context) {
		code, out, err := r.o.Engine.Exec(ctx, c, []string{"valkey-cli", "INFO"})
		res := Result{ID: "cache.ready", Group: groupCache, Title: "Cache and queues", Status: StatusOK}
		if err != nil || code != 0 || !strings.Contains(out, "redis_version") && !strings.Contains(out, "valkey_version") {
			res.Status, res.Summary = StatusFail, "Valkey doesn't answer."
			r.add(res)
			return
		}
		info := parseInfo(out)
		used, _ := strconv.ParseUint(info["used_memory"], 10, 64)
		maxm, _ := strconv.ParseUint(info["maxmemory"], 10, 64)
		res.Summary = "Answering, " + bytesWord(used) + " in use."
		if maxm > 0 {
			res.Summary = fmt.Sprintf("Answering, %s of %s in use.", bytesWord(used), bytesWord(maxm))
			if used*100/maxm >= 80 {
				res.Status = StatusWarn
				res.Detail = append(res.Detail, "Above 80 %: when it is full, queued jobs are refused (nothing is evicted).")
			}
		}
		if s := info["aof_last_write_status"]; s != "" && s != "ok" {
			res.Status = StatusFail
			res.Detail = append(res.Detail, "Its last write to disk failed ("+s+"). Check the disk space.")
		}
		r.add(res)
	})
}

func parseInfo(out string) map[string]string {
	m := map[string]string{}
	for l := range strings.SplitSeq(out, "\n") {
		if k, v, ok := strings.Cut(strings.TrimSpace(l), ":"); ok {
			m[k] = v
		}
	}
	return m
}

// --- launchroomd and updates ----------------------------------------------

func (r *run) checkDaemonFiles() {
	res := Result{ID: "daemon.enrollment", Group: groupDaemon, Title: "Enrollment", Status: StatusOK, Summary: "This server is enrolled."}
	if _, err := os.Stat(filepath.Join(r.o.DataDir, "enrollment.json")); err != nil {
		res.Status, res.Summary = StatusFail, "This server isn't enrolled."
		res.Detail = []string{"Run the installer again."}
		r.add(res)
		return
	}
	r.add(res)
	st, err := os.Stat(filepath.Join(r.o.DataDir, "state.json"))
	res = Result{ID: "daemon.state", Group: groupDaemon, Title: "Configuration", Status: StatusOK}
	switch {
	case err != nil:
		res.Status, res.Summary = StatusWarn, "launchroomd hasn't received its configuration from the dashboard yet."
	case r.o.Now().Sub(st.ModTime()) > 15*time.Minute:
		// The file only changes when the configuration does; old is normal.
		res.Summary = "Last configuration change " + ago(r.o.Now(), st.ModTime()) + "."
	default:
		res.Summary = "Configuration received " + ago(r.o.Now(), st.ModTime()) + "."
	}
	r.add(res)
	var running struct {
		Version   string    `json:"version"`
		StartedAt time.Time `json:"startedAt"`
	}
	if b, err := os.ReadFile(filepath.Join(r.o.DataDir, "update", "running.json")); err == nil && json.Unmarshal(b, &running) == nil {
		r.add(Result{ID: "daemon.version", Group: groupDaemon, Title: "Version", Status: StatusOK,
			Summary: fmt.Sprintf("launchroomd %s, connected since %s.", running.Version, ago(r.o.Now(), running.StartedAt))})
	}
}

func (r *run) checkDaemonLogs(ctx context.Context) {
	c, ok := r.service("launchroomd")
	if !ok || !c.Running {
		return
	}
	logs := ""
	r.within(ctx, 0, func(ctx context.Context) { logs, _ = r.o.Engine.Logs(ctx, c, 200) })
	errs := errorLines(logs, 3)
	if len(errs) == 0 {
		r.add(Result{ID: "daemon.log", Group: groupDaemon, Title: "Recent errors", Status: StatusOK, Summary: "No errors in launchroomd's recent log."})
		return
	}
	r.add(Result{ID: "daemon.log", Group: groupDaemon, Title: "Recent errors", Status: StatusWarn,
		Summary: "launchroomd logged errors recently.", Detail: errs})
}

// updateIdle: no update is running (pending without a verdict).
func (r *run) updateIdle() bool {
	var p struct {
		TaskID string `json:"taskId"`
	}
	b, err := os.ReadFile(filepath.Join(r.o.DataDir, "update", "pending.json"))
	if err != nil || json.Unmarshal(b, &p) != nil {
		return true
	}
	_, err = os.Stat(filepath.Join(r.o.DataDir, "update", "tasks", filepath.Base(p.TaskID), "status.json"))
	return err == nil
}

func (r *run) checkUpdates() {
	dir := r.o.Dir
	if r.o.Mode == ModeServer {
		// Server mode sees only launchroomd's data folder.
		dir = filepath.Dir(r.o.DataDir)
	}
	f, err := update.FindFailedRollback(dir, "")
	if err == nil {
		res := Result{ID: "update.rollback", Group: groupUpdates, Title: "Update", Status: StatusFail,
			Summary: fmt.Sprintf("The update from %s to %s failed, and so did going back to %s.", f.Status.From, f.Status.To, f.Status.From),
			Detail:  []string{clip(f.Status.Error, 300)},
			Fix:     &FixRef{ID: FixUpdateRollback, Target: string(f.Status.TaskID), Does: "Finishes going back to " + f.Status.From + ": restores the files from before the update" + dumpWords(f.Dump) + " and starts everything."}}
		if f.Status.MigrationFailed != "" {
			res.Detail = append(res.Detail, "The new version's database migration failed: "+f.Status.MigrationFailed)
		}
		if r.o.Mode == ModeServer {
			res.Fix = nil
			res.Detail = append(res.Detail, "Run the server install command from the dashboard again to repair it.")
		}
		r.add(res)
		return
	}
	if !r.updateIdle() {
		r.add(Result{ID: "update.running", Group: groupUpdates, Title: "Update", Status: StatusWarn, Summary: "An update is running. Check again in a few minutes."})
		return
	}
	r.add(Result{ID: "update.state", Group: groupUpdates, Title: "Update", Status: StatusOK, Summary: "No update is running or left unfinished."})
}

func dumpWords(dump bool) string {
	if dump {
		return ", the database from its copy (changes made during the update are lost)"
	}
	return ""
}

// --- helpers ----------------------------------------------------------------

func displayName(c Container) string {
	if c.Service != "" {
		return c.Service
	}
	return c.Name
}

func bytesWord(b uint64) string {
	switch {
	case b >= 10*gib:
		return fmt.Sprintf("%d GB", b/gib)
	case b >= gib:
		return fmt.Sprintf("%.1f GB", float64(b)/gib)
	case b >= 1<<20:
		return fmt.Sprintf("%d MB", b>>20)
	default:
		return fmt.Sprintf("%d KB", b>>10)
	}
}

func ago(now, t time.Time) string {
	if t.IsZero() {
		return "a while ago"
	}
	d := now.Sub(t)
	switch {
	case d < time.Minute:
		return "less than a minute ago"
	case d < 2*time.Minute:
		return "a minute ago"
	case d < time.Hour:
		return itoa(int(d.Minutes())) + " minutes ago"
	case d < 2*time.Hour:
		return "an hour ago"
	case d < 48*time.Hour:
		return itoa(int(d.Hours())) + " hours ago"
	default:
		return itoa(int(d.Hours()/24)) + " days ago"
	}
}

func shortImage(ref string) string {
	if i := strings.Index(ref, "@sha256:"); i >= 0 && len(ref) > i+20 {
		return ref[:i+20]
	}
	return ref
}

func clip(s string, n int) string {
	s = strings.TrimSpace(s)
	if len(s) <= n {
		return s
	}
	return s[:n] + "…"
}

func lastLine(s string) string {
	lines := strings.Split(strings.TrimSpace(s), "\n")
	return strings.TrimSpace(lines[len(lines)-1])
}
