package doctor

import (
	"context"
	"errors"
	"fmt"
	"io"
	"io/fs"
	"os"
	"path/filepath"
	"regexp"
	"slices"
	"strings"
	"time"

	"github.com/vantezzen/launchroom/daemon/internal/names"
	"github.com/vantezzen/launchroom/daemon/internal/update"
)

// FixID names a safe fix.
type FixID string

// Fixes. None deletes user data; each can run again without harm.
const (
	// FixStart brings a control-plane service up with docker compose
	// (creates, starts or recreates it from compose.yml and .env).
	FixStart FixID = "start"
	// FixRestart restarts an unhealthy dashboard, horizon, reverb,
	// scheduler, zot or headscale container. Never postgres or valkey.
	FixRestart FixID = "restart"
	// FixClearScheduleLocks clears the scheduler's overlap locks.
	FixClearScheduleLocks FixID = "clear-schedule-locks"
	// FixRestartWorkers lets Horizon's workers finish and start again.
	FixRestartWorkers FixID = "restart-workers"
	// FixUpdateRollback finishes a rollback that failed half-way.
	FixUpdateRollback FixID = "update-rollback"
	// FixRepairValkeyAOF repairs Valkey's damaged append-only file (after
	// copying it aside).
	FixRepairValkeyAOF FixID = "repair-valkey-aof"
	// FixFreeDisk removes launchroom's own leftovers.
	FixFreeDisk FixID = "free-disk"
)

// AllFixes lists every fix id.
var AllFixes = []FixID{FixStart, FixRestart, FixClearScheduleLocks, FixRestartWorkers, FixUpdateRollback, FixRepairValkeyAOF, FixFreeDisk}

// Hooks are the fixes that need more than the Engine interface (compose
// and the update supervisor's rollback); the command wires them.
type Hooks struct {
	// ComposeUp runs `docker compose up -d <service>` in the install folder.
	ComposeUp func(ctx context.Context, service string) error
	// FinishRollback finishes the failed rollback of update task.
	FinishRollback func(ctx context.Context, task string) (string, error)
}

var reCheckID = regexp.MustCompile(`^[a-z0-9][a-z0-9.-]{0,63}$`)

// ParseFix reads "--fix ID" or "--fix ID:target".
func ParseFix(s string) (FixID, string, error) {
	id, target, _ := strings.Cut(s, ":")
	if !slices.Contains(AllFixes, FixID(id)) {
		return "", "", fmt.Errorf("unknown fix %q (known: %s)", id, joinFixes())
	}
	if target != "" && !reCheckID.MatchString(strings.ToLower(target)) {
		return "", "", fmt.Errorf("fix target %q is not a service or task name", target)
	}
	return FixID(id), target, nil
}

func joinFixes() string {
	s := make([]string, len(AllFixes))
	for i, f := range AllFixes {
		s[i] = string(f)
	}
	return strings.Join(s, ", ")
}

// Confirm asks for a typed "yes" before a fix runs.
type Confirm func(f FixRef) bool

// ErrDeclined means the fix was not confirmed.
var ErrDeclined = errors.New("not confirmed; nothing was changed")

// Apply runs the fixes of report that match id (and target, when given),
// each after confirm. It writes what it does to out.
func Apply(ctx context.Context, o Options, h Hooks, rep Report, id FixID, target string, confirm Confirm, out io.Writer) error {
	o.defaults()
	var todo []FixRef
	for _, f := range rep.Fixes() {
		if f.ID == id && (target == "" || strings.EqualFold(f.Target, target)) {
			todo = append(todo, f)
		}
	}
	if len(todo) == 0 {
		_, _ = fmt.Fprintf(out, "Nothing to fix with %q: no check needs it right now.\n", id)
		return nil
	}
	r := &run{o: o, env: map[string]string{}}
	r.load(ctx)
	var errs []error
	for _, f := range todo {
		what := f.Does
		if f.Target != "" {
			what = fmt.Sprintf("%s (%s)", f.Does, f.Target)
		}
		_, _ = fmt.Fprintf(out, "\n%s\n", what)
		if !confirm(f) {
			errs = append(errs, ErrDeclined)
			continue
		}
		msg, err := r.apply(ctx, h, f)
		if err != nil {
			_, _ = fmt.Fprintf(out, "That didn't work: %s\n", err)
			errs = append(errs, err)
			continue
		}
		_, _ = fmt.Fprintln(out, msg)
	}
	return errors.Join(errs...)
}

func (r *run) apply(ctx context.Context, h Hooks, f FixRef) (string, error) {
	if r.o.Mode == ModeServer && f.ID != FixFreeDisk {
		return "", errors.New("this fix runs on the control-plane server only")
	}
	switch f.ID {
	case FixStart:
		if !slices.ContainsFunc(cpServices, func(s struct{ name, title, pin string }) bool { return s.name == f.Target }) {
			return "", fmt.Errorf("%q is not a control-plane service", f.Target)
		}
		if h.ComposeUp == nil {
			return "", errors.New("compose isn't available here")
		}
		if err := h.ComposeUp(ctx, f.Target); err != nil {
			return "", err
		}
		return "Started " + f.Target + ".", nil
	case FixRestart:
		if !slices.Contains(restartable, f.Target) {
			return "", fmt.Errorf("%q is never restarted from here", f.Target)
		}
		c, ok := r.service(f.Target)
		if !ok {
			return "", fmt.Errorf("no %s container", f.Target)
		}
		cctx, cancel := context.WithTimeout(ctx, 2*time.Minute)
		defer cancel()
		if err := r.o.Engine.Restart(cctx, c); err != nil {
			return "", err
		}
		return "Restarted " + f.Target + ".", nil
	case FixClearScheduleLocks:
		return r.artisan(ctx, "scheduler", "Cleared the schedule's locks.", "schedule:clear-cache")
	case FixRestartWorkers:
		return r.artisan(ctx, "horizon", "The workers restart after their current job.", "horizon:terminate")
	case FixUpdateRollback:
		if h.FinishRollback == nil {
			return "", errors.New("the update rollback isn't available here")
		}
		return h.FinishRollback(ctx, f.Target)
	case FixRepairValkeyAOF:
		return r.repairValkeyAOF(ctx)
	case FixFreeDisk:
		return r.freeDisk(ctx)
	}
	return "", fmt.Errorf("unknown fix %q", f.ID)
}

func (r *run) artisan(ctx context.Context, service, done string, args ...string) (string, error) {
	c, ok := r.service(service)
	if !ok || !c.Running {
		if c, ok = r.service("dashboard"); !ok || !c.Running {
			return "", errors.New("neither " + service + " nor the dashboard is running")
		}
	}
	cctx, cancel := context.WithTimeout(ctx, time.Minute)
	defer cancel()
	code, out, err := r.o.Engine.Exec(cctx, c, append([]string{"php", "artisan"}, args...))
	if err != nil {
		return "", err
	}
	if code != 0 {
		return "", fmt.Errorf("php artisan %s exited with %d: %s", args[0], code, clip(redactLine(lastLine(out)), 200))
	}
	return done, nil
}

// repairValkeyAOF: stop Valkey, copy its append-only files aside, let
// valkey-check-aof cut off the damaged tail, start Valkey.
func (r *run) repairValkeyAOF(ctx context.Context) (string, error) {
	c, ok := r.service("valkey")
	if !ok {
		return "", errors.New("no valkey container")
	}
	dataDir := filepath.Join(r.o.Dir, "data", "valkey")
	target, err := aofTarget(dataDir)
	if err != nil {
		return "", err
	}
	if c.Running || c.Restarting {
		if err := r.o.Engine.Stop(ctx, c); err != nil {
			return "", fmt.Errorf("stopping valkey: %w", err)
		}
	}
	backup := filepath.Join(dataDir, "aof-backup-"+r.o.Now().UTC().Format("20060102-150405"))
	src := filepath.Join(dataDir, "appendonlydir")
	if !strings.HasPrefix(target, src+"/") {
		src = target
	}
	if err := copyPath(src, filepath.Join(backup, filepath.Base(src))); err != nil {
		return "", fmt.Errorf("copying the append-only file aside (nothing was changed): %w", err)
	}
	rel, _ := filepath.Rel(dataDir, target)
	code, out, err := r.o.Engine.RunOnce(ctx, OneOff{
		Name: names.SystemPrefix + "doctor-valkey-aof", Image: c.Image,
		Cmd:   []string{"valkey-check-aof", "--fix", "/data/" + rel},
		Binds: []string{dataDir + ":/data"}, Stdin: "y\n",
	})
	if err != nil {
		return "", err
	}
	if code != 0 {
		return "", fmt.Errorf("valkey-check-aof exited with %d: %s (the copy is in %s)", code, clip(lastLine(out), 200), backup)
	}
	if err := r.o.Engine.Start(ctx, c); err != nil {
		return "", fmt.Errorf("repaired, but starting valkey failed: %w", err)
	}
	return "Repaired the append-only file and started Valkey. The copy from before is in " + backup + ".", nil
}

// aofTarget is what valkey-check-aof gets: the multi-part manifest
// (appendonlydir/*.manifest) or a single appendonly.aof.
func aofTarget(dataDir string) (string, error) {
	if m, _ := filepath.Glob(filepath.Join(dataDir, "appendonlydir", "*.manifest")); len(m) == 1 {
		return m[0], nil
	}
	single := filepath.Join(dataDir, "appendonly.aof")
	if _, err := os.Stat(single); err == nil {
		return single, nil
	}
	return "", errors.New("no append-only file found in data/valkey")
}

// freeDisk removes launchroom's own leftovers: the pre-update database copy
// once no update can need it, finished helper containers, and the
// installer's download folder.
func (r *run) freeDisk(ctx context.Context) (string, error) {
	var freed uint64
	var did []string
	if r.o.Mode == ModeControlPlane {
		dump := filepath.Join(r.o.Dir, "data", "postgres", "launchroom-pre-upgrade.dump")
		_, ferr := update.FindFailedRollback(r.o.Dir, "")
		if st, err := os.Stat(dump); err == nil && r.updateIdle() && errors.Is(ferr, update.ErrNothingToFinish) {
			if err := os.Remove(dump); err != nil {
				return "", err
			}
			freed += uint64(st.Size()) //nolint:gosec // non-negative
			did = append(did, "removed the pre-update database copy")
		}
		tmp := filepath.Join(r.o.Dir, ".tmp")
		if n := treeSize(tmp); n > 0 {
			if err := os.RemoveAll(tmp); err == nil {
				freed += n
				did = append(did, "removed the installer's downloads")
			}
		}
	}
	n := 0
	for _, c := range r.containers {
		if c.Running || (c.Role != names.RoleUpdater && c.Role != roleDoctor) {
			continue
		}
		if err := r.o.Engine.Remove(ctx, c); err == nil {
			n++
		}
	}
	if n > 0 {
		did = append(did, fmt.Sprintf("removed %d finished helper containers", n))
	}
	if len(did) == 0 {
		return "launchroom has no leftovers to remove. To free more space, delete old images or files you don't need, or grow the disk.", nil
	}
	return strings.ToUpper(did[0][:1]) + strings.Join(did, ", ")[1:] + fmt.Sprintf(" (%s).", bytesWord(freed)), nil
}

func treeSize(dir string) uint64 {
	var n uint64
	_ = filepath.WalkDir(dir, func(_ string, d fs.DirEntry, err error) error {
		if err != nil {
			return nil //nolint:nilerr // best effort
		}
		if info, ierr := d.Info(); ierr == nil && d.Type().IsRegular() {
			n += uint64(info.Size()) //nolint:gosec // non-negative
		}
		return nil
	})
	return n
}

// copyPath copies a file or directory tree, keeping modes and owners.
func copyPath(src, dst string) error {
	return filepath.WalkDir(src, func(p string, d fs.DirEntry, err error) error {
		if err != nil {
			return err
		}
		rel, err := filepath.Rel(src, p)
		if err != nil {
			return err
		}
		t := filepath.Join(dst, rel)
		info, err := d.Info()
		if err != nil {
			return err
		}
		switch {
		case d.IsDir():
			if err := os.MkdirAll(t, 0o700); err != nil {
				return err
			}
		case d.Type().IsRegular():
			b, err := os.ReadFile(p) //nolint:gosec // inside data/valkey
			if err != nil {
				return err
			}
			if err := os.WriteFile(t, b, info.Mode().Perm()); err != nil { //nolint:gosec // same mode as the original
				return err
			}
		default:
			return nil
		}
		chownLike(t, info)
		return nil
	})
}
