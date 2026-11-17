package update

import (
	"bytes"
	"context"
	"crypto/rand"
	"errors"
	"fmt"
	"io"
	"io/fs"
	"log/slog"
	"os"
	"path/filepath"
	"regexp"
	"slices"
	"sort"
	"strconv"
	"strings"
	"time"

	"github.com/moby/moby/api/pkg/stdcopy"
	"github.com/moby/moby/api/types/container"
	"github.com/moby/moby/client"
	"golang.org/x/sys/unix"

	"github.com/vantezzen/launchroom/daemon/internal/names"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// Manual updates, diagnostics and finishing a failed rollback (docs/11 §4.4).
//
// `./launchroom update` and an installer re-run on an existing install go
// through the same supervisor as an update from the dashboard (RunLocal):
// the managed files are backed up, the database is dumped, and a failure
// rolls back with the same code. `launchroomd update-rollback`
// (FinishRollback) repeats the rollback of an update whose rollback stopped
// half-way. Both run in a container the admin starts on the host with the
// Docker socket and the install folder mounted at its own path.

const (
	// cpProject and cpDaemonService are the control plane's compose project
	// and its launchroomd service.
	cpProject       = "launchroom"
	cpDaemonService = "launchroomd"
	// daemonProject is a server's compose project (deploy/daemon/compose.yml).
	daemonProject = "launchroomd"

	diagLogLines = 300
	// MigrationMarker starts the line the dashboard's entrypoint prints when
	// its migrations fail (deploy/images/dashboard/entrypoint.sh).
	MigrationMarker = "[launchroom] migration-failed"
)

// LocalOptions configure a manual update of the control plane.
type LocalOptions struct {
	API *client.Client
	Log *slog.Logger
	// ProjectDir is the install folder (host path, mounted at the same path).
	ProjectDir string
	// BundleDir is an unpacked bundle the installer checked (empty: pull the
	// pins in .env again). It is copied into the task directory.
	BundleDir string
	// InstallArgs and InstallEnv go to the bundle's installer; RunLocal
	// keeps only the allow-listed ones.
	InstallArgs []string
	InstallEnv  []string
	// SocketPath is the host's Docker socket (default /var/run/docker.sock).
	SocketPath    string
	HealthTimeout time.Duration
	Now           func() time.Time
	Poll          time.Duration
}

// installArgFlags are the installer options a manual update passes on;
// installArgValues those that take a value.
var (
	installArgFlags  = []string{"--no-public-ip-detect", "--unstable"}
	installArgValues = []string{"--domain"}
	reInstallEnvKey  = regexp.MustCompile(`^(LR_[A-Z]+_IMAGE|LAUNCHROOM_RELEASE_BASE)$`)
	reDomainArg      = regexp.MustCompile(`^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$`)
	// reImageOverride is Docker's image reference grammar: an optional
	// registry, the repository, an optional tag and an optional digest. The
	// installer takes image overrides for mirrors and tests as the admin
	// gives them (a local tag, a mirror's tag), not only digest-pinned
	// references with a registry like state.ImageRef.
	reImageOverride = regexp.MustCompile(`^(?:[a-z0-9-]+(?:\.[a-z0-9-]+)*(?::[0-9]{1,5})?/)?` +
		`[a-z0-9]+(?:(?:\.|_|__|-+)[a-z0-9]+)*(?:/[a-z0-9]+(?:(?:\.|_|__|-+)[a-z0-9]+)*)*` +
		`(?::[A-Za-z0-9_][A-Za-z0-9_.-]{0,127})?(?:@sha256:[a-f0-9]{64})?$`)
)

// FilterInstallArgs keeps the installer options a manual update may pass on.
func FilterInstallArgs(args []string) ([]string, error) {
	var out []string
	for i := 0; i < len(args); i++ {
		a := args[i]
		switch {
		case slices.Contains(installArgFlags, a):
			out = append(out, a)
		case slices.Contains(installArgValues, a) && i+1 < len(args) && reDomainArg.MatchString(args[i+1]):
			out = append(out, a, args[i+1])
			i++
		default:
			return nil, fmt.Errorf("installer option %q can't be passed to an update", a)
		}
	}
	return out, nil
}

// FilterInstallEnv keeps image pin overrides (KEY=VALUE, Docker's image
// reference grammar) and the release base URL.
func FilterInstallEnv(env []string) ([]string, error) {
	var out []string
	for _, kv := range env {
		k, v, ok := strings.Cut(kv, "=")
		if !ok || !reInstallEnvKey.MatchString(k) {
			return nil, fmt.Errorf("environment %q can't be passed to an update", k)
		}
		if strings.HasSuffix(k, "_IMAGE") {
			if len(v) > 512 || !reImageOverride.MatchString(v) {
				return nil, fmt.Errorf("%s: %q is not an image reference", k, v)
			}
		} else if !strings.HasPrefix(v, "https://") && !strings.HasPrefix(v, "http://") {
			return nil, fmt.Errorf("%s must be a URL", k)
		}
		out = append(out, k+"="+v)
	}
	return out, nil
}

// RunLocal runs a manual control-plane update and returns its status (also
// written to data/daemon/update/tasks/<task>/status.json).
func RunLocal(ctx context.Context, o LocalOptions) (Status, error) {
	if o.Log == nil {
		o.Log = slog.New(slog.DiscardHandler)
	}
	if o.Now == nil {
		o.Now = time.Now
	}
	if o.SocketPath == "" {
		o.SocketPath = dockerSocket
	}
	if o.HealthTimeout <= 0 {
		o.HealthTimeout = defaultCPHealthTimeout
	}
	dir := filepath.Clean(o.ProjectDir)
	if !filepath.IsAbs(dir) || dir == "/" {
		return Status{}, fmt.Errorf("install folder %q is not an absolute path", o.ProjectDir)
	}
	if _, err := os.Stat(filepath.Join(dir, "daemon-compose.yml")); err != nil {
		return Status{}, fmt.Errorf("%s is not a control-plane install folder", dir)
	}
	args, err := FilterInstallArgs(o.InstallArgs)
	if err != nil {
		return Status{}, err
	}
	env, err := FilterInstallEnv(o.InstallEnv)
	if err != nil {
		return Status{}, err
	}
	dataDir := filepath.Join(dir, "data", "daemon")
	if p, _ := readPending(dataDir); p != nil {
		if _, err := readStatus(dataDir, p.TaskID); err != nil {
			return Status{}, fmt.Errorf("an update started from the dashboard is still running (%s to %s); wait for it to finish", p.From, p.To)
		}
	}
	dotenv, err := readDotEnv(filepath.Join(dir, ".env"))
	if err != nil {
		return Status{}, fmt.Errorf("reading .env: %w", err)
	}
	from := ""
	if r, err := readRunning(dataDir); err == nil {
		from = r.Version
	}
	to := from
	id := newTaskID(o.Now())
	plan := Plan{
		TaskID: id, Scope: state.UpdateControlPlane, From: from, To: to, ProjectDir: dir, Project: cpProject,
		Service: cpDaemonService, DataDir: dataDir, SocketPath: o.SocketPath,
		ComposeImage: state.ImageRef(dotenv["LR_COMPOSE_IMAGE"]), Manual: true, InstallArgs: args, InstallEnv: env,
		InstallTimeout: Duration(defaultCPInstallTimeout), HealthTimeout: Duration(o.HealthTimeout),
	}
	tdir := taskDir(dataDir, id)
	if err := os.MkdirAll(tdir, 0o700); err != nil {
		return Status{}, err
	}
	if o.BundleDir != "" {
		// A development bundle (the repo's deploy/ folder) has no VERSION.
		if b, err := os.ReadFile(filepath.Join(o.BundleDir, "VERSION")); err == nil { //nolint:gosec // the bundle the admin named
			plan.To = strings.TrimSpace(string(b))
		}
		if bi, err := readDotEnv(filepath.Join(o.BundleDir, "control-plane", "images.env")); err == nil && bi["LR_COMPOSE_IMAGE"] != "" {
			plan.ComposeImage = state.ImageRef(bi["LR_COMPOSE_IMAGE"])
		}
		plan.BundleDir = filepath.Join(tdir, dirBundle)
		if err := copyTree(o.BundleDir, plan.BundleDir); err != nil {
			return Status{}, fmt.Errorf("copying the bundle: %w", err)
		}
	}
	if err := plan.validate(); err != nil {
		return Status{}, err
	}
	// Every step runs in the docker CLI image; a fresh install has never
	// pulled it (the dashboard flow pulls it with the release).
	if err := EnsureImage(ctx, o.API, plan.ComposeImage); err != nil {
		return Status{}, fmt.Errorf("the docker CLI image the update runs in: %w", err)
	}
	if err := writeJSON(filepath.Join(tdir, filePlan), plan); err != nil {
		return Status{}, err
	}
	pruneTaskDirs(dataDir, id)
	s := &Supervisor{API: o.API, Log: o.Log, Now: o.Now, Poll: o.Poll}
	if s.Poll <= 0 {
		s.Poll = 2 * time.Second
	}
	st := s.run(ctx, &plan)
	st.TaskID, st.Scope, st.From, st.To, st.FinishedAt, st.Manual = plan.TaskID, plan.Scope, plan.From, plan.To, s.Now().UTC(), true
	return st, writeStatus(dataDir, st)
}

// FinishOptions configure FinishRollback.
type FinishOptions struct {
	API        *client.Client
	Log        *slog.Logger
	ProjectDir string
	SocketPath string
	// TaskID picks the update; empty means the newest one whose rollback failed.
	TaskID        state.TaskID
	HealthTimeout time.Duration
	Now           func() time.Time
	Poll          time.Duration
}

// ErrNothingToFinish means no update has a rollback that stopped half-way.
var ErrNothingToFinish = errors.New("no update has a rollback to finish")

// FailedRollback describes an update whose rollback stopped half-way.
type FailedRollback struct {
	Status Status
	// Dir is the task directory, Backup the managed files' backup.
	Dir, Backup string
	// Dump: the pre-upgrade database dump is still there.
	Dump bool
}

// FindFailedRollback returns the newest update in the install folder dir
// whose rollback failed (or the one named by id).
func FindFailedRollback(dir string, id state.TaskID) (FailedRollback, error) {
	cp := isControlPlaneDir(dir)
	dataDir := installDataDir(dir)
	root := filepath.Join(dataDir, dirUpdate, dirTasks)
	entries, err := os.ReadDir(root)
	if err != nil && !errors.Is(err, fs.ErrNotExist) {
		return FailedRollback{}, err
	}
	var found []FailedRollback
	for _, e := range entries {
		tid := state.TaskID(e.Name())
		if !e.IsDir() || tid.Validate() != nil || (id != "" && tid != id) {
			continue
		}
		st, err := readStatus(dataDir, tid)
		if err != nil || !st.rollbackFailed() {
			continue
		}
		f := FailedRollback{Status: st, Dir: taskDir(dataDir, tid), Backup: filepath.Join(taskDir(dataDir, tid), dirBackup)}
		// On a guarded install the supervisor kept the backup outside
		// launchroomd's reach; only that copy is trusted.
		if Guarded(dir) && !st.Manual {
			f.Backup = filepath.Join(dir, dirGuard, dirUpdate, string(tid), dirBackup)
		}
		if cp {
			_, derr := os.Stat(filepath.Join(dir, "data", "postgres", filepath.Base(pgDump)))
			f.Dump = derr == nil
		}
		found = append(found, f)
	}
	if len(found) == 0 {
		return FailedRollback{}, ErrNothingToFinish
	}
	sort.Slice(found, func(i, j int) bool { return found[i].Status.FinishedAt.After(found[j].Status.FinishedAt) })
	return found[0], nil
}

func (s Status) rollbackFailed() bool {
	return s.Outcome == OutcomeFailed && (s.RollbackFailed || strings.Contains(s.Error, "ROLLBACK FAILED"))
}

// FinishRollback repeats the rollback of an update whose rollback failed:
// the previous files, the database from the pre-upgrade dump when it is
// still there, compose up, launchroomd running. The status becomes
// rolled_back when it works.
func FinishRollback(ctx context.Context, o FinishOptions) (Status, error) {
	if o.Log == nil {
		o.Log = slog.New(slog.DiscardHandler)
	}
	if o.Now == nil {
		o.Now = time.Now
	}
	if o.SocketPath == "" {
		o.SocketPath = dockerSocket
	}
	if o.HealthTimeout <= 0 {
		o.HealthTimeout = defaultCPHealthTimeout
	}
	dir := filepath.Clean(o.ProjectDir)
	f, err := FindFailedRollback(dir, o.TaskID)
	if err != nil {
		return Status{}, err
	}
	saved, err := savedList(dir, f.Backup)
	if err != nil {
		return Status{}, fmt.Errorf("the backup of the managed files: %w", err)
	}
	p := &Plan{
		TaskID: f.Status.TaskID, Scope: f.Status.Scope, From: f.Status.From, To: f.Status.To, ProjectDir: dir,
		Project: daemonProject, Service: cpDaemonService, DataDir: installDataDir(dir), SocketPath: o.SocketPath,
		Manual: true, HealthTimeout: Duration(o.HealthTimeout),
	}
	if isControlPlaneDir(dir) {
		p.Project = cpProject
	}
	// The docker CLI image of the version being restored.
	if old, err := readDotEnv(filepath.Join(f.Backup, ".env")); err == nil && old["LR_COMPOSE_IMAGE"] != "" {
		p.ComposeImage = state.ImageRef(old["LR_COMPOSE_IMAGE"])
	} else {
		var plan Plan
		if err := readJSON(filepath.Join(f.Dir, filePlan), &plan); err == nil {
			p.ComposeImage = plan.ComposeImage
		}
	}
	if err := EnsureImage(ctx, o.API, p.ComposeImage); err != nil {
		return Status{}, fmt.Errorf("no docker CLI image to run compose with: %w", err)
	}
	s := &Supervisor{API: o.API, Log: o.Log, Now: o.Now, Poll: o.Poll}
	if s.Poll <= 0 {
		s.Poll = 2 * time.Second
	}
	cause := errors.New(f.Status.Error)
	if f.Status.Reason != "" {
		cause = errors.New(f.Status.Reason)
	}
	st := s.rollback(ctx, p, f.Backup, saved, f.Dump, s.Now(), cause)
	st.TaskID, st.Scope, st.From, st.To, st.FinishedAt = f.Status.TaskID, f.Status.Scope, f.Status.From, f.Status.To, s.Now().UTC()
	st.Manual, st.MigrationFailed = f.Status.Manual, f.Status.MigrationFailed
	if st.Outcome == OutcomeRolledBack {
		st.Error = fmt.Sprintf("rolled back to %s by hand after the automatic rollback failed: %s", f.Status.From, cause)
	}
	if err := writeStatus(p.DataDir, st); err != nil {
		return st, err
	}
	return st, nil
}

// savedList is the backup's files.json, or (backups from before it
// existed) the managed files found in it with the current owners.
func savedList(dir, backup string) ([]savedFile, error) {
	var saved []savedFile
	if err := readJSON(filepath.Join(backup, fileSaved), &saved); err == nil {
		for _, f := range saved {
			if !slices.Contains(cpManagedFiles, f.Rel) && !slices.Contains(daemonManagedFiles, f.Rel) {
				return nil, fmt.Errorf("unexpected file %q in the backup list", f.Rel)
			}
		}
		return saved, nil
	} else if !errors.Is(err, fs.ErrNotExist) {
		return nil, err
	}
	files := daemonManagedFiles
	if isControlPlaneDir(dir) {
		files = cpManagedFiles
	}
	for _, rel := range files {
		if _, err := os.Stat(filepath.Join(backup, strings.ReplaceAll(rel, "/", "__"))); err != nil {
			continue
		}
		f := savedFile{Rel: rel, Mode: 0o600, UID: -1, GID: -1}
		if st, err := os.Stat(filepath.Join(dir, rel)); err == nil {
			f.Mode = st.Mode().Perm()
			if u := ownerOf(filepath.Join(dir, rel)); u != "" {
				uid, gid, _ := strings.Cut(u, ":")
				f.UID, _ = strconv.Atoi(uid)
				f.GID, _ = strconv.Atoi(gid)
			}
		}
		saved = append(saved, f)
	}
	if len(saved) == 0 {
		return nil, errors.New("it is empty")
	}
	return saved, nil
}

func isControlPlaneDir(dir string) bool {
	_, err := os.Stat(filepath.Join(dir, "daemon-compose.yml"))
	return err == nil
}

// installDataDir is launchroomd's data directory inside an install folder.
func installDataDir(dir string) string {
	if isControlPlaneDir(dir) {
		return filepath.Join(dir, "data", "daemon")
	}
	return filepath.Join(dir, "data")
}

// waitManual waits until launchroomd runs and talks to the dashboard: a
// process that started after since wrote running.json, or the process from
// before kept running (compose left its container alone).
func (s *Supervisor) waitManual(ctx context.Context, p *Plan, since time.Time) error {
	timeout := time.Duration(p.HealthTimeout)
	if timeout <= 0 {
		timeout = defaultCPHealthTimeout
	}
	deadline := time.NewTimer(timeout)
	defer deadline.Stop()
	tick := time.NewTicker(s.Poll)
	defer tick.Stop()
	for {
		if r, err := readRunning(p.DataDir); err == nil {
			if r.StartedAt.After(since) {
				return nil
			}
			if c, err := s.serviceContainer(ctx, p.Project, p.Service); err == nil && c.State != nil && c.State.Running {
				if started, err := time.Parse(time.RFC3339Nano, c.State.StartedAt); err == nil && started.Before(since) && r.StartedAt.Before(since) {
					return nil
				}
			}
		}
		select {
		case <-ctx.Done():
			return ctx.Err()
		case <-deadline.C:
			return fmt.Errorf("launchroomd did not reconnect to the dashboard within %s", timeout)
		case <-tick.C:
		}
	}
}

func (s *Supervisor) serviceContainer(ctx context.Context, project, service string) (container.InspectResponse, error) {
	list, err := s.projectContainers(ctx, project)
	if err != nil {
		return container.InspectResponse{}, err
	}
	for _, c := range list {
		if c.Config != nil && c.Config.Labels[composeService] == service {
			return c, nil
		}
	}
	return container.InspectResponse{}, fmt.Errorf("no %s container", service)
}

func (s *Supervisor) projectContainers(ctx context.Context, project string) ([]container.InspectResponse, error) {
	f := client.Filters{}
	f.Add("label", composeProject+"="+project)
	list, err := s.API.ContainerList(ctx, client.ContainerListOptions{All: true, Filters: f})
	if err != nil {
		return nil, err
	}
	var out []container.InspectResponse
	for _, c := range list.Items {
		res, err := s.API.ContainerInspect(ctx, c.ID, client.ContainerInspectOptions{})
		if err == nil {
			out = append(out, res.Container)
		}
	}
	return out, nil
}

// Diagnostics are what saveDiagnostics found.
type Diagnostics struct {
	MigrationFailed string
}

// diagState is diagnostics/<service>.json: the inspect facts, never the
// environment or the config.
type diagState struct {
	Service      string    `json:"service"`
	Image        string    `json:"image"`
	Status       string    `json:"status"`
	ExitCode     int       `json:"exitCode"`
	OOMKilled    bool      `json:"oomKilled"`
	RestartCount int       `json:"restartCount"`
	Error        string    `json:"error,omitempty"`
	StartedAt    string    `json:"startedAt,omitempty"`
	FinishedAt   string    `json:"finishedAt,omitempty"`
	Health       string    `json:"health,omitempty"`
	HealthLog    []string  `json:"healthLog,omitempty"`
	SavedAt      time.Time `json:"savedAt"`
}

// saveDiagnostics keeps the last log lines and the state of every
// container of the project that isn't running and healthy, before the
// rollback recreates them (diagnostics/ in the task directory).
func (s *Supervisor) saveDiagnostics(ctx context.Context, p *Plan) Diagnostics {
	var d Diagnostics
	ctx, cancel := context.WithTimeout(ctx, 2*time.Minute)
	defer cancel()
	list, err := s.projectContainers(ctx, p.Project)
	if err != nil {
		s.Log.Warn("could not save diagnostics", "err", err)
		return d
	}
	dir := filepath.Join(taskDir(p.DataDir, p.TaskID), dirDiag)
	if err := os.MkdirAll(dir, 0o700); err != nil {
		return d
	}
	for _, c := range list {
		if c.Config == nil || c.State == nil {
			continue
		}
		svc := c.Config.Labels[composeService]
		if !reServiceName.MatchString(svc) {
			continue
		}
		healthy := c.State.Running && (c.State.Health == nil || c.State.Health.Status == container.Healthy)
		logs := ContainerLogTail(ctx, s.API, c.ID, c.Config.Tty, diagLogLines)
		if m := MigrationFailure(logs); m != "" {
			d.MigrationFailed = m
		}
		if healthy {
			continue
		}
		ds := diagState{Service: svc, Image: c.Config.Image, Status: string(c.State.Status), ExitCode: c.State.ExitCode, OOMKilled: c.State.OOMKilled,
			RestartCount: c.RestartCount, Error: c.State.Error, StartedAt: c.State.StartedAt, FinishedAt: c.State.FinishedAt, SavedAt: s.Now().UTC()}
		if h := c.State.Health; h != nil {
			ds.Health = string(h.Status)
			for _, r := range h.Log {
				if r != nil {
					ds.HealthLog = append(ds.HealthLog, state.Clip(strings.TrimSpace(r.Output), 500))
				}
			}
		}
		_ = writeJSON(filepath.Join(dir, svc+".json"), ds)
		_ = os.WriteFile(filepath.Join(dir, svc+".log"), []byte(logs), 0o600) //nolint:gosec // service names are checked by grammar
	}
	return d
}

var reServiceName = regexp.MustCompile(`^[a-z][a-z0-9-]{0,62}$`)

// MigrationFailure returns the migration-failed marker line's text (the
// last one) from the dashboard's log output.
func MigrationFailure(logs string) string {
	out := ""
	for l := range strings.SplitSeq(logs, "\n") {
		if i := strings.Index(l, MigrationMarker); i >= 0 {
			out = strings.TrimSpace(l[i+len(MigrationMarker):])
			if out == "" {
				out = "unknown migration"
			}
		}
	}
	return state.Clip(out, 300)
}

// ContainerLogTail returns the last n log lines of a container (stdout and
// stderr), demultiplexed unless the container has a TTY.
func ContainerLogTail(ctx context.Context, api *client.Client, id string, tty bool, n int) string {
	lctx, cancel := context.WithTimeout(context.WithoutCancel(ctx), 30*time.Second)
	defer cancel()
	rc, err := api.ContainerLogs(lctx, id, client.ContainerLogsOptions{ShowStdout: true, ShowStderr: true, Tail: strconv.Itoa(n)})
	if err != nil {
		return ""
	}
	defer func() { _ = rc.Close() }()
	r := io.LimitReader(rc, 8<<20)
	var buf bytes.Buffer
	if tty {
		_, _ = io.Copy(&buf, r)
	} else {
		_, _ = stdcopy.StdCopy(&buf, &buf, r)
	}
	return string(bytes.ReplaceAll(buf.Bytes(), []byte("\r\n"), []byte("\n")))
}

// checkDumpSpace refuses an update whose database copy would not fit:
// it needs about half the database's size plus 1 GiB free next to it.
func (s *Supervisor) checkDumpSpace(ctx context.Context, p *Plan) error {
	var st unix.Statfs_t
	if err := unix.Statfs(filepath.Join(p.ProjectDir, "data", "postgres"), &st); err != nil {
		return nil // unknown: let pg_dump find out
	}
	free := uint64(st.Bavail) * uint64(st.Bsize) //nolint:gosec,unconvert // block counts are non-negative; field types differ by platform
	out, err := s.composeOutput(ctx, p, time.Minute, "exec", "-T", "postgres", "psql", "-U", "launchroom", "-d", "launchroom", "-tAc", "select pg_database_size('launchroom')")
	if err != nil {
		return nil // the dump step reports a database that doesn't answer
	}
	size, err := strconv.ParseUint(lastNumber(out), 10, 64)
	if err != nil {
		return nil
	}
	need := size/2 + 1<<30
	if free < need {
		return fmt.Errorf("not enough free disk space to copy the database first: %d MB free, %d MB needed. Free some space (./launchroom doctor --fix free-disk) and try again", free>>20, need>>20)
	}
	return nil
}

var reNumberLine = regexp.MustCompile(`^\d+$`)

func lastNumber(out string) string {
	n := ""
	for l := range strings.SplitSeq(ansiEscape.ReplaceAllString(out, ""), "\n") {
		if l = strings.TrimSpace(l); reNumberLine.MatchString(l) {
			n = l
		}
	}
	return n
}

// composeOutput is compose with its output.
func (s *Supervisor) composeOutput(ctx context.Context, p *Plan, timeout time.Duration, args ...string) (string, error) {
	cmd := append([]string{"docker", "compose", "--project-directory", p.ProjectDir, "-p", p.Project}, args...)
	h := s.helperFor(p, args[0], cmd, nil)
	code, logs, err := h.run(ctx, s.API, timeout)
	if err != nil {
		return logs, err
	}
	if code != 0 {
		return logs, fmt.Errorf("docker compose %s exited with %d", args[0], code)
	}
	return logs, nil
}

// HelperRun is one argv run in the digest-pinned docker CLI image with the
// Docker socket and the install folder (the doctor's fixes use it).
type HelperRun struct {
	// Name is the helper container's name (a launchroom system name).
	Name       string
	Image      state.ImageRef
	Cmd        []string
	ProjectDir string
	SocketPath string
	Timeout    time.Duration
}

// RunHelper runs h to completion and returns its exit code and output.
func RunHelper(ctx context.Context, api *client.Client, r HelperRun) (int, string, error) {
	if err := r.Image.Validate(); err != nil {
		return -1, "", err
	}
	if !strings.HasPrefix(r.Name, names.SystemPrefix) {
		return -1, "", fmt.Errorf("helper name %q is not a launchroom system name", r.Name)
	}
	if r.SocketPath == "" {
		r.SocketPath = dockerSocket
	}
	if r.Timeout <= 0 {
		r.Timeout = 10 * time.Minute
	}
	h := helper{
		Name: r.Name, Image: string(r.Image), Entrypoint: []string{}, Cmd: r.Cmd, WorkingDir: r.ProjectDir,
		User: ownerOf(r.ProjectDir), GroupAdd: socketGroup(dockerSocket),
		Env:       []string{"HOME=/tmp", "DOCKER_CONFIG=/tmp/.docker", "COMPOSE_PROGRESS=plain", "COMPOSE_ANSI=never", "NO_COLOR=1"},
		SameBinds: []string{r.ProjectDir}, SocketPath: r.SocketPath,
	}
	return h.run(ctx, api, r.Timeout)
}

// ReadDotEnv parses an installer-written .env (KEY=VALUE lines, no quoting).
func ReadDotEnv(path string) (map[string]string, error) { return readDotEnv(path) }

// newTaskID is a task id for a manual update (tsk_ + a ULID).
func newTaskID(now time.Time) state.TaskID {
	const crockford = "0123456789ABCDEFGHJKMNPQRSTVWXYZ"
	ms := uint64(now.UnixMilli()) //nolint:gosec // after 1970
	var b [26]byte
	for i := 9; i >= 0; i-- {
		b[i] = crockford[ms&31]
		ms >>= 5
	}
	var r [16]byte
	_, _ = rand.Read(r[:])
	for i := range 16 {
		b[10+i] = crockford[r[i]&31]
	}
	return state.TaskID("tsk_" + string(b[:]))
}

// copyTree copies a bundle directory (regular files and directories only).
func copyTree(src, dst string) error {
	return filepath.WalkDir(src, func(path string, d fs.DirEntry, err error) error {
		if err != nil {
			return err
		}
		rel, err := filepath.Rel(src, path)
		if err != nil {
			return err
		}
		target := filepath.Join(dst, rel)
		switch {
		case d.IsDir():
			return os.MkdirAll(target, 0o755) //nolint:gosec // the bundle is read by the installer's helper user
		case d.Type().IsRegular():
			info, err := d.Info()
			if err != nil {
				return err
			}
			b, err := os.ReadFile(path) //nolint:gosec // inside the bundle the admin named
			if err != nil {
				return err
			}
			return os.WriteFile(target, b, info.Mode().Perm()|0o444) //nolint:gosec // bundle files are public
		default:
			return nil // links and devices are never part of a bundle
		}
	})
}

// EnsureImage pulls a digest-pinned image unless it is already there.
func EnsureImage(ctx context.Context, api *client.Client, ref state.ImageRef) error {
	if err := ref.Validate(); err != nil {
		return err
	}
	if _, err := api.ImageInspect(ctx, string(ref)); err == nil {
		return nil
	}
	return pull(ctx, api, string(ref))
}
