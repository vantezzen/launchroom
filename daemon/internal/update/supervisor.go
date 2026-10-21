package update

import (
	"bufio"
	"bytes"
	"context"
	"errors"
	"fmt"
	"log/slog"
	"os"
	"path/filepath"
	"regexp"
	"strconv"
	"strings"
	"syscall"
	"time"

	"github.com/moby/moby/client"

	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// SupervisorCommand is the launchroomd subcommand that runs RunSupervisor.
const SupervisorCommand = "update-supervisor"

// pgDump is where the control-plane database is dumped before an update,
// inside the postgres container (./data/postgres on the host).
const pgDump = "/var/lib/postgresql/launchroom-pre-upgrade.dump"

// managed files backed up before an update, relative to the install folder.
var (
	daemonManagedFiles = []string{"compose.yml", ".env"}
	cpManagedFiles     = []string{"compose.yml", ".env", "launchroom", "data/zot/config.json"}
	cpAppServices      = []string{"dashboard", "horizon", "reverb", "scheduler"}
)

// Supervisor performs the swap described by a plan. It runs in its own
// container (from the old, known-good launchroomd image) so it survives
// the replacement of launchroomd's container.
type Supervisor struct {
	API  *client.Client
	Log  *slog.Logger
	Now  func() time.Time
	Poll time.Duration
	// Version is the supervisor's own (= the running launchroomd's) version.
	Version string
	// TrustedRootJSON replaces the embedded Sigstore root (tests).
	TrustedRootJSON []byte
}

// RunSupervisor reads the plan at planPath and runs it to completion. The
// outcome is always written to status.json (also when it returns an error).
func (s *Supervisor) RunSupervisor(ctx context.Context, planPath string) error {
	if s.Now == nil {
		s.Now = time.Now
	}
	if s.Log == nil {
		s.Log = slog.New(slog.DiscardHandler)
	}
	if s.Poll <= 0 {
		s.Poll = 2 * time.Second
	}
	var p Plan
	if err := readJSON(planPath, &p); err != nil {
		return err
	}
	if err := p.validate(); err != nil {
		return err
	}
	st := s.run(ctx, &p)
	st.TaskID, st.Scope, st.From, st.To, st.FinishedAt = p.TaskID, p.Scope, p.From, p.To, s.Now().UTC()
	s.Log.Info("update finished", "task", p.TaskID, "outcome", st.Outcome, "error", st.Error)
	return writeStatus(p.DataDir, st)
}

func (p *Plan) validate() error {
	for name, v := range map[string]string{"projectDir": p.ProjectDir, "dataDir": p.DataDir, "bundleDir": p.BundleDir, "socketPath": p.SocketPath} {
		if !filepath.IsAbs(v) || filepath.Clean(v) != v {
			return fmt.Errorf("plan: %s %q is not a clean absolute path", name, v)
		}
	}
	if !strings.HasPrefix(p.DataDir, p.ProjectDir+"/") || !strings.HasPrefix(p.BundleDir, p.DataDir+"/") {
		return errors.New("plan: data and bundle directories must be inside the install folder")
	}
	if err := p.TaskID.Validate(); err != nil {
		return err
	}
	if err := p.Scope.Validate(); err != nil {
		return err
	}
	if err := p.ComposeImage.Validate(); err != nil {
		return err
	}
	if p.Scope == state.UpdateDaemon {
		if err := p.DaemonImage.Validate(); err != nil {
			return err
		}
	}
	return nil
}

func (s *Supervisor) run(ctx context.Context, p *Plan) Status {
	start := s.Now().Add(-time.Second)
	backup := filepath.Join(taskDir(p.DataDir, p.TaskID), dirBackup)
	if Guarded(p.ProjectDir) {
		b, err := s.prepareGuarded(ctx, p)
		if err != nil {
			return Status{Outcome: OutcomeFailed, Error: err.Error() + " (nothing was changed)"}
		}
		backup = b
	}
	files := daemonManagedFiles
	if p.Scope == state.UpdateControlPlane {
		files = cpManagedFiles
	}
	saved, err := backupFiles(p.ProjectDir, backup, files)
	if err != nil {
		return Status{Outcome: OutcomeFailed, Error: "backing up the install folder: " + err.Error()}
	}
	if p.Scope == state.UpdateControlPlane {
		s.Log.Info("dumping the control-plane database")
		if err := s.compose(ctx, p, 15*time.Minute, "exec", "-T", "postgres", "pg_dump", "-U", "launchroom", "-d", "launchroom", "-Fc", "-f", pgDump); err != nil {
			return Status{Outcome: OutcomeFailed, Error: "database dump before the update failed (nothing was changed): " + err.Error()}
		}
	}

	s.Log.Info("running the new release's installer", "to", p.To)
	failure := s.install(ctx, p)
	if failure == nil {
		s.Log.Info("waiting for the new launchroomd", "version", p.To)
		failure = s.waitRunning(ctx, p, p.To, start)
	}
	if failure == nil {
		if p.Scope == state.UpdateControlPlane {
			_ = s.compose(context.WithoutCancel(ctx), p, time.Minute, "exec", "-T", "postgres", "rm", "-f", pgDump)
		}
		return Status{Outcome: OutcomeSucceeded}
	}

	// Roll back: previous files, previous database, compose up, old version healthy.
	s.Log.Error("update failed; rolling back", "err", failure)
	rctx := context.WithoutCancel(ctx)
	rollbackStart := s.Now().Add(-time.Second)
	if err := restoreFiles(p.ProjectDir, backup, saved); err != nil {
		return Status{Outcome: OutcomeFailed, Error: fmt.Sprintf("%v; ROLLBACK FAILED restoring files: %v", failure, err)}
	}
	if p.Scope == state.UpdateControlPlane {
		if err := s.restoreDatabase(rctx, p); err != nil {
			return Status{Outcome: OutcomeFailed, Error: fmt.Sprintf("%v; ROLLBACK FAILED restoring the database (the dump is kept in data/postgres): %v", failure, err)}
		}
	}
	up := []string{"up", "-d", "--remove-orphans"}
	if p.Scope == state.UpdateControlPlane {
		up = append(up, "--wait", "--wait-timeout", "600")
	}
	if err := s.compose(rctx, p, 20*time.Minute, up...); err != nil {
		return Status{Outcome: OutcomeFailed, Error: fmt.Sprintf("%v; ROLLBACK FAILED starting the previous version: %v", failure, err)}
	}
	if err := s.waitRolledBack(rctx, p, start, rollbackStart); err != nil {
		return Status{Outcome: OutcomeFailed, Error: fmt.Sprintf("%v; rolled back, but launchroomd %s did not report back: %v", failure, p.From, err)}
	}
	if p.Scope == state.UpdateControlPlane {
		_ = s.compose(rctx, p, time.Minute, "exec", "-T", "postgres", "rm", "-f", pgDump)
	}
	return Status{Outcome: OutcomeRolledBack, Error: fmt.Sprintf("rolled back to %s: %v", p.From, failure)}
}

// install runs the bundle's installer (the documented upgrade path) in the
// compose helper container.
func (s *Supervisor) install(ctx context.Context, p *Plan) error {
	var cmd, env []string
	switch p.Scope {
	case state.UpdateControlPlane:
		cmd = []string{"sh", filepath.Join(p.BundleDir, "install", "install.sh"), "--bundle", p.BundleDir, "--dir", p.ProjectDir, "--skip-port-check", "--no-public-ip-detect"}
	default:
		dotenv, err := readDotEnv(filepath.Join(p.ProjectDir, ".env"))
		if err != nil {
			return err
		}
		cmd = []string{"sh", filepath.Join(p.BundleDir, "install", "server.sh")}
		env = []string{
			"LR_BUNDLE_DIR=" + p.BundleDir,
			"LAUNCHROOM_DAEMON_DIR=" + p.ProjectDir,
			"LR_DAEMON_IMAGE=" + string(p.DaemonImage),
			"LR_CONTROL_PLANE_URL=" + dotenv["LR_CONTROL_PLANE_URL"],
			"LR_CP_KEY=" + dotenv["LR_CP_KEY"],
		}
	}
	env = append(env, "LR_BUNDLE_VERIFIED=1")
	return s.helperRun(ctx, p, "install", cmd, env, time.Duration(p.InstallTimeout))
}

func (s *Supervisor) restoreDatabase(ctx context.Context, p *Plan) error {
	stop := append([]string{"stop"}, cpAppServices...)
	if err := s.compose(ctx, p, 5*time.Minute, stop...); err != nil {
		return err
	}
	// The previous postgres image (restored .env) may differ from the one
	// the failed update started; bring it back first.
	if err := s.compose(ctx, p, 10*time.Minute, "up", "-d", "--wait", "--wait-timeout", "300", "postgres"); err != nil {
		return err
	}
	steps := [][]string{
		{"exec", "-T", "postgres", "dropdb", "-U", "launchroom", "--if-exists", "--force", "launchroom"},
		{"exec", "-T", "postgres", "createdb", "-U", "launchroom", "-O", "launchroom", "launchroom"},
		{"exec", "-T", "postgres", "pg_restore", "-U", "launchroom", "-d", "launchroom", "--no-owner", "--role=launchroom", pgDump},
	}
	for _, args := range steps {
		if err := s.compose(ctx, p, 15*time.Minute, args...); err != nil {
			return err
		}
	}
	return nil
}

// compose runs `docker compose` for the install folder in a helper container.
func (s *Supervisor) compose(ctx context.Context, p *Plan, timeout time.Duration, args ...string) error {
	cmd := append([]string{"docker", "compose", "--project-directory", p.ProjectDir, "-p", p.Project}, args...)
	return s.helperRun(ctx, p, args[0], cmd, nil, timeout)
}

// helperRun runs argv in the digest-pinned docker CLI image, as the owner
// of the install folder (files the installer writes keep their owner),
// with the Docker socket's group, the install folder mounted at its own
// path and no network.
func (s *Supervisor) helperRun(ctx context.Context, p *Plan, step string, cmd, env []string, timeout time.Duration) error {
	if timeout <= 0 {
		timeout = 30 * time.Minute
	}
	user, groups := ownerOf(p.ProjectDir), socketGroup(dockerSocket)
	h := helper{
		Name: "lr-system-update-" + strings.ToLower(strings.TrimPrefix(string(p.TaskID), "tsk_")) + "-" + step, Image: string(p.ComposeImage),
		Entrypoint: []string{}, Cmd: cmd, WorkingDir: p.ProjectDir, User: user, GroupAdd: groups, TaskID: string(p.TaskID),
		// Plain compose output: the helper has a TTY (raw log stream), which
		// would otherwise get progress bars and colour codes into logs and
		// error messages.
		Env:       append([]string{"HOME=/tmp", "DOCKER_CONFIG=/tmp/.docker", "COMPOSE_PROGRESS=plain", "COMPOSE_ANSI=never", "NO_COLOR=1"}, env...),
		SameBinds: []string{p.ProjectDir}, SocketPath: p.SocketPath,
	}
	code, logs, err := h.run(ctx, s.API, timeout)
	if logs != "" {
		s.Log.Info("helper output", "step", step, "output", logs)
	}
	if err != nil {
		return err
	}
	if code != 0 {
		what := "docker compose " + step
		if step == "install" {
			what = "the installer"
		}
		return fmt.Errorf("%s exited with %d: %s", what, code, lastLines(logs, 4))
	}
	return nil
}

// waitRunning waits until a launchroomd of version reports itself running
// (data/update/running.json written after since).
func (s *Supervisor) waitRunning(ctx context.Context, p *Plan, version string, since time.Time) error {
	timeout := time.Duration(p.HealthTimeout)
	if timeout <= 0 {
		timeout = 3 * time.Minute
	}
	deadline := time.NewTimer(timeout)
	defer deadline.Stop()
	tick := time.NewTicker(s.Poll)
	defer tick.Stop()
	for {
		if r, err := readRunning(p.DataDir); err == nil && r.Version == version && r.StartedAt.After(since) {
			return nil
		}
		select {
		case <-ctx.Done():
			return ctx.Err()
		case <-deadline.C:
			return fmt.Errorf("launchroomd %s did not become healthy within %s", version, timeout)
		case <-tick.C:
		}
	}
}

// waitRolledBack waits for the previous launchroomd after a rollback: one
// that started since the rollback (the installer had replaced it), or the
// very process that started the update, still running in its original
// container (the installer failed before it got to launchroomd: the
// control-plane installer starts launchroomd last, so a release whose
// dashboard never becomes healthy leaves it untouched).
func (s *Supervisor) waitRolledBack(ctx context.Context, p *Plan, updateStart, rollbackStart time.Time) error {
	if r, err := readRunning(p.DataDir); err == nil && r.Version == p.From && r.StartedAt.Before(updateStart) && s.unchanged(ctx, p, updateStart) {
		return nil
	}
	return s.waitRunning(ctx, p, p.From, rollbackStart)
}

// unchanged: launchroomd's original container still runs, started before the update.
func (s *Supervisor) unchanged(ctx context.Context, p *Plan, updateStart time.Time) bool {
	if p.DaemonContainer == "" {
		return false
	}
	res, err := s.API.ContainerInspect(ctx, p.DaemonContainer, client.ContainerInspectOptions{})
	if err != nil || res.Container.State == nil || !res.Container.State.Running {
		return false
	}
	started, err := time.Parse(time.RFC3339Nano, res.Container.State.StartedAt)
	return err == nil && started.Before(updateStart)
}

// ---- files ----

type savedFile struct {
	Rel      string
	Mode     os.FileMode
	UID, GID int
	Missing  bool
}

func backupFiles(projectDir, backupDir string, rels []string) ([]savedFile, error) {
	if err := os.MkdirAll(backupDir, 0o700); err != nil {
		return nil, err
	}
	var out []savedFile
	for _, rel := range rels {
		src := filepath.Join(projectDir, rel)
		st, err := os.Lstat(src)
		if errors.Is(err, os.ErrNotExist) {
			out = append(out, savedFile{Rel: rel, Missing: true})
			continue
		}
		if err != nil {
			return nil, err
		}
		if !st.Mode().IsRegular() {
			return nil, fmt.Errorf("%s is not a regular file", rel)
		}
		b, err := os.ReadFile(src) //nolint:gosec // fixed names inside the install folder
		if err != nil {
			return nil, err
		}
		dst := filepath.Join(backupDir, strings.ReplaceAll(rel, "/", "__"))
		if err := os.WriteFile(dst, b, 0o600); err != nil { //nolint:gosec // fixed file names below our task directory
			return nil, err
		}
		f := savedFile{Rel: rel, Mode: st.Mode().Perm(), UID: -1, GID: -1}
		if sys, ok := st.Sys().(*syscall.Stat_t); ok {
			f.UID, f.GID = int(sys.Uid), int(sys.Gid)
		}
		out = append(out, f)
	}
	return out, nil
}

func restoreFiles(projectDir, backupDir string, saved []savedFile) error {
	for _, f := range saved {
		dst := filepath.Join(projectDir, f.Rel)
		if f.Missing {
			_ = os.Remove(dst)
			continue
		}
		b, err := os.ReadFile(filepath.Join(backupDir, strings.ReplaceAll(f.Rel, "/", "__"))) //nolint:gosec // our backup
		if err != nil {
			return err
		}
		tmp := dst + ".lr-restore"
		if err := os.WriteFile(tmp, b, f.Mode); err != nil { //nolint:gosec // fixed managed file names inside the install folder
			return err
		}
		if f.UID >= 0 {
			_ = os.Lchown(tmp, f.UID, f.GID)
		}
		if err := os.Chmod(tmp, f.Mode); err != nil {
			return err
		}
		if err := os.Rename(tmp, dst); err != nil {
			return err
		}
	}
	return nil
}

func ownerOf(path string) string {
	st, err := os.Stat(path)
	if err != nil {
		return ""
	}
	if sys, ok := st.Sys().(*syscall.Stat_t); ok {
		return strconv.FormatUint(uint64(sys.Uid), 10) + ":" + strconv.FormatUint(uint64(sys.Gid), 10)
	}
	return ""
}

func socketGroup(path string) []string {
	st, err := os.Stat(path)
	if err != nil {
		return nil
	}
	if sys, ok := st.Sys().(*syscall.Stat_t); ok && sys.Gid != 0 {
		return []string{strconv.FormatUint(uint64(sys.Gid), 10)}
	}
	return nil
}

// readDotEnv parses KEY=VALUE lines (no quoting, like the installers write).
func readDotEnv(path string) (map[string]string, error) {
	b, err := readLimited(path, 1<<20)
	if err != nil {
		return nil, err
	}
	out := map[string]string{}
	sc := bufio.NewScanner(bytes.NewReader(b))
	for sc.Scan() {
		if m := reEnvLine.FindStringSubmatch(strings.TrimSpace(sc.Text())); m != nil {
			out[m[1]] = m[2]
		}
	}
	return out, sc.Err()
}

// ansiEscape matches terminal control sequences (colours, cursor moves).
var ansiEscape = regexp.MustCompile(`\x1b\[[0-9;?]*[ -/]*[@-~]|\x1b[@-_]`)

func lastLines(s string, n int) string {
	s = ansiEscape.ReplaceAllString(s, "")
	var lines []string
	for l := range strings.SplitSeq(strings.TrimRight(s, "\n"), "\n") {
		if l = strings.TrimSpace(l); l != "" {
			lines = append(lines, l)
		}
	}
	if len(lines) > n {
		lines = lines[len(lines)-n:]
	}
	return strings.Join(lines, " | ")
}
