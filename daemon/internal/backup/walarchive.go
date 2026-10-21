package backup

import (
	"archive/tar"
	"bytes"
	"context"
	"errors"
	"fmt"
	"io"
	"log/slog"
	"path"
	"regexp"
	"slices"
	"strings"
	"sync"
	"time"

	"github.com/vantezzen/launchroom/daemon/internal/docker"
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// WalFile is the restic --stdin-filename of WAL snapshots: a tar whose
// entries are wal/<file name> (segments, timeline history files, backup
// history files), as Postgres' basic_archive wrote them.
const WalFile = "wal.tar"

// walUser runs the archive directory housekeeping inside the database
// container: the files belong to Postgres, never to root.
const walUser = "postgres"

// maxWalBatch bounds one shipping cycle (64 segments of 16 MiB = 1 GiB);
// a longer backlog is shipped in consecutive cycles without waiting.
const maxWalBatch = 64

// Names basic_archive leaves in the archive directory. Its temporary files
// ("archtemp…") are never shipped: they are renamed when complete.
var reWalFile = regexp.MustCompile(`^(?:[0-9A-F]{24}(?:\.partial|\.[0-9A-F]{8}\.backup)?|[0-9A-F]{8}\.history)$`)

// WalDocker is what WAL shipping needs from the Docker layer.
type WalDocker interface {
	ListContainers(ctx context.Context, labels map[string]string) ([]docker.Container, error)
	docker.Execer
	StreamFromContainer(ctx context.Context, id, src string) (io.ReadCloser, error)
}

// WalArchiverOptions configures a WalArchiver.
type WalArchiverOptions struct {
	// Engine opens restic repositories (binary, cache, insecure hosts).
	Engine *Engine
	Docker WalDocker
	// Credentials opens the sealed destination credentials of a workload's
	// walArchive (a WorkloadEnv bound to this server and the workload).
	Credentials func(wl state.WorkloadID, spec *state.WalArchiveSpec) (Credentials, error)
	// OnChange is called when a status changed (kick a report).
	OnChange func()
	Log      *slog.Logger
	Now      func() time.Time
}

// WalArchiver ships the WAL segments Postgres archives into a directory of
// its volume (basic_archive, no shell) into the backup repository, for every
// workload whose NodeState entry has a walArchive (docs/14 §10.2). One
// shipper goroutine per workload; it keeps running while the dashboard is
// unreachable (the archive is local), and a container replacement loses
// nothing (the directory is in the volume; Postgres keeps a segment in
// pg_wal until basic_archive copied it).
type WalArchiver struct {
	o      WalArchiverOptions
	mu     sync.Mutex
	ship   map[state.WorkloadID]*walShipper
	status map[state.WorkloadID]state.WalArchiveStatus
}

type walShipper struct {
	spec   *state.WalArchiveSpec
	stop   context.CancelFunc
	done   chan struct{}
	update chan struct{}
}

// NewWalArchiver returns an archiver; Sync starts and stops shippers.
func NewWalArchiver(o WalArchiverOptions) *WalArchiver {
	if o.Now == nil {
		o.Now = time.Now
	}
	if o.Log == nil {
		o.Log = slog.New(slog.DiscardHandler)
	}
	return &WalArchiver{o: o, ship: map[state.WorkloadID]*walShipper{}, status: map[state.WorkloadID]state.WalArchiveStatus{}}
}

// Sync makes the running shippers match the NodeState: new walArchive
// entries start one, changed ones take the new spec at their next cycle,
// removed ones stop.
func (a *WalArchiver) Sync(ctx context.Context, ns *state.NodeState) {
	want := map[state.WorkloadID]*state.WalArchiveSpec{}
	if ns != nil {
		for i := range ns.Workloads {
			if w := &ns.Workloads[i]; w.WalArchive != nil {
				spec := *w.WalArchive
				want[w.ID] = &spec
			}
		}
	}
	a.mu.Lock()
	var stopped []*walShipper
	for id, s := range a.ship {
		if _, ok := want[id]; !ok {
			s.stop()
			stopped = append(stopped, s)
			delete(a.ship, id)
			delete(a.status, id)
		}
	}
	for id, spec := range want {
		if s, ok := a.ship[id]; ok {
			s.spec = spec
			continue
		}
		sctx, cancel := context.WithCancel(context.WithoutCancel(ctx))
		s := &walShipper{spec: spec, stop: cancel, done: make(chan struct{}), update: make(chan struct{}, 1)}
		a.ship[id] = s
		go a.run(sctx, id, s)
	}
	a.mu.Unlock()
	for _, s := range stopped {
		<-s.done
	}
}

// Stop stops every shipper and waits for them.
func (a *WalArchiver) Stop(ctx context.Context) {
	a.Sync(ctx, nil)
}

// Status returns the state of every shipper, sorted by workload.
func (a *WalArchiver) Status() []state.WalArchiveStatus {
	a.mu.Lock()
	defer a.mu.Unlock()
	out := make([]state.WalArchiveStatus, 0, len(a.status))
	for _, s := range a.status {
		out = append(out, s)
	}
	slices.SortFunc(out, func(x, y state.WalArchiveStatus) int { return strings.Compare(string(x.Workload), string(y.Workload)) })
	return out
}

func (a *WalArchiver) currentSpec(id state.WorkloadID) *state.WalArchiveSpec {
	a.mu.Lock()
	defer a.mu.Unlock()
	if s, ok := a.ship[id]; ok {
		return s.spec
	}
	return nil
}

func (a *WalArchiver) run(ctx context.Context, id state.WorkloadID, s *walShipper) {
	defer close(s.done)
	for {
		spec := a.currentSpec(id)
		if spec == nil {
			return
		}
		more := a.cycle(ctx, id, spec)
		wait := spec.Interval.Std()
		if more {
			wait = 0
		}
		select {
		case <-ctx.Done():
			return
		case <-time.After(wait):
		}
	}
}

// cycle ships once and records the status; it reports whether more
// segments are waiting than one batch holds.
func (a *WalArchiver) cycle(ctx context.Context, id state.WorkloadID, spec *state.WalArchiveSpec) bool {
	res, err := a.Ship(ctx, id, spec)
	if ctx.Err() != nil {
		return false
	}
	a.mu.Lock()
	prev, had := a.status[id]
	st := state.WalArchiveStatus{
		Workload: id, CheckedAt: a.o.Now().UTC(), LastArchivedAt: prev.LastArchivedAt, LastSegment: prev.LastSegment,
		PendingSegments: res.Pending,
	}
	switch {
	case errors.Is(err, errNoContainer):
		st.State = state.WalArchiveWaiting
	case err != nil:
		st.State = state.WalArchiveFailing
		st.Message = truncateMessage(err.Error(), 1024)
	default:
		st.State = state.WalArchiveActive
	}
	if res.Shipped > 0 {
		at := st.CheckedAt
		st.LastArchivedAt = &at
		if res.LastSegment > st.LastSegment {
			st.LastSegment = res.LastSegment
		}
	}
	if _, stillShipping := a.ship[id]; stillShipping {
		a.status[id] = st
	}
	a.mu.Unlock()
	changed := !had || prev.State != st.State || prev.Message != st.Message || res.Shipped > 0 || prev.PendingSegments != st.PendingSegments
	if err != nil && !errors.Is(err, errNoContainer) {
		a.o.Log.Warn("shipping WAL failed", "workload", id, "err", err)
	}
	if changed && a.o.OnChange != nil {
		a.o.OnChange()
	}
	return err == nil && res.Pending > 0 && res.Shipped > 0
}

// WalShipResult is the outcome of one shipping cycle.
type WalShipResult struct {
	// Shipped files are stored in the repository and removed from the directory.
	Shipped int
	// Pending completed files are still in the directory after the cycle.
	Pending int
	// LastSegment is the newest segment shipped in this cycle.
	LastSegment state.WalSegment
	// Snapshot is the restic snapshot of this cycle ("" when nothing was shipped).
	Snapshot state.SnapshotID
	Bytes    int64
}

var errNoContainer = errors.New("the database has no running container on this server")

// Ship runs one cycle for a workload: list the archive directory inside its
// container, stream the completed files (at most one batch) as one restic
// snapshot, then delete exactly those files. A failed upload deletes
// nothing, so the next cycle sends them again.
func (a *WalArchiver) Ship(ctx context.Context, id state.WorkloadID, spec *state.WalArchiveSpec) (WalShipResult, error) {
	cid, err := a.containerOf(ctx, id)
	if err != nil {
		return WalShipResult{}, err
	}
	dir := string(spec.Directory)
	// The directory lives in the volume; it is created on first use (as
	// Postgres' user, which owns the volume).
	if err := a.exec(ctx, cid, nil, "mkdir", "-p", "--", dir); err != nil {
		return WalShipResult{}, fmt.Errorf("wal archive: creating %s: %w", dir, err)
	}
	var listing bytes.Buffer
	if err := a.exec(ctx, cid, &listing, "ls", "-1", "--", dir); err != nil {
		return WalShipResult{}, fmt.Errorf("wal archive: listing %s: %w", dir, err)
	}
	var names []string
	for line := range strings.SplitSeq(listing.String(), "\n") {
		if name := strings.TrimSpace(line); reWalFile.MatchString(name) {
			names = append(names, name)
		}
	}
	slices.Sort(names)
	if len(names) == 0 {
		return WalShipResult{}, nil
	}
	batch := names[:min(len(names), maxWalBatch)]
	res := WalShipResult{Pending: len(names)}

	creds, err := a.o.Credentials(id, spec)
	if err != nil {
		return res, err
	}
	r, err := a.o.Engine.openRepo(spec.Destination, creds)
	if err != nil {
		return res, err
	}
	if _, err := r.ensure(ctx); err != nil {
		return res, err
	}

	ctx, cancel := context.WithCancel(ctx)
	defer cancel()
	src, err := a.o.Docker.StreamFromContainer(ctx, cid, dir)
	if err != nil {
		return res, fmt.Errorf("wal archive: reading %s: %w", dir, err)
	}
	defer func() { _ = src.Close() }()

	want := make(map[string]bool, len(batch))
	for _, n := range batch {
		want[n] = true
	}
	pr, pw := io.Pipe()
	type packed struct {
		files []string
		bytes int64
		err   error
	}
	packDone := make(chan packed, 1)
	go func() {
		files, n, err := packWal(pw, src, want)
		if err != nil {
			_ = pw.CloseWithError(errDumpFailed)
		} else {
			_ = pw.Close()
		}
		packDone <- packed{files, n, err}
	}()

	args := []string{"backup", "--stdin", "--stdin-filename", WalFile, "--host", resticHost, "--json"}
	for _, t := range spec.Tags {
		args = append(args, "--tag", string(t))
	}
	var out bytes.Buffer
	resticErr := r.run(ctx, "backup", pr, &out, args...)
	if resticErr != nil {
		_ = pr.CloseWithError(errors.New("restic stopped"))
		cancel()
	}
	p := <-packDone
	summary := parseBackupSummary(out.Bytes())
	switch {
	case p.err != nil:
		if summary.SnapshotID != "" {
			fctx, fcancel := context.WithTimeout(context.WithoutCancel(ctx), 2*time.Minute)
			_ = r.run(fctx, "forget", nil, nil, "forget", summary.SnapshotID)
			fcancel()
		}
		return res, p.err
	case resticErr != nil:
		return res, resticErr
	case summary.SnapshotID == "":
		return res, errors.New("wal archive: restic did not report a snapshot id")
	case len(p.files) == 0:
		// Listed but gone before the read (nothing to delete): the empty
		// snapshot holds nothing worth keeping.
		fctx, fcancel := context.WithTimeout(context.WithoutCancel(ctx), 2*time.Minute)
		_ = r.run(fctx, "forget", nil, nil, "forget", summary.SnapshotID)
		fcancel()
		return res, nil
	}

	// Stored: only now the files leave the directory.
	rm := append([]string{"rm", "-f", "--"}, prefixAll(dir, p.files)...)
	if err := a.exec(context.WithoutCancel(ctx), cid, nil, rm...); err != nil {
		return res, fmt.Errorf("wal archive: removing shipped files: %w", err)
	}
	res.Shipped, res.Bytes, res.Pending = len(p.files), p.bytes, len(names)-len(p.files)
	res.Snapshot = state.SnapshotID(summary.SnapshotID)
	for _, f := range p.files {
		if seg := state.WalSegment(f); seg.Validate() == nil && seg > res.LastSegment {
			res.LastSegment = seg
		}
	}
	return res, nil
}

// packWal copies the wanted regular files of a Docker archive stream of the
// archive directory into a new tar with entries wal/<name>.
func packWal(dst io.Writer, src io.Reader, want map[string]bool) ([]string, int64, error) {
	tr := tar.NewReader(src)
	tw := tar.NewWriter(dst)
	var files []string
	var total int64
	for {
		hdr, err := tr.Next()
		if errors.Is(err, io.EOF) {
			break
		}
		if err != nil {
			return nil, 0, fmt.Errorf("wal archive: reading the directory: %w", err)
		}
		name := path.Base(hdr.Name)
		if (hdr.Typeflag != tar.TypeReg && hdr.Typeflag != '\x00') || !want[name] || slices.Contains(files, name) {
			continue
		}
		out := &tar.Header{Typeflag: tar.TypeReg, Name: "wal/" + name, Mode: 0o600, Size: hdr.Size, Uid: hdr.Uid, Gid: hdr.Gid, ModTime: hdr.ModTime}
		if err := tw.WriteHeader(out); err != nil {
			return nil, 0, fmt.Errorf("wal archive: %w", err)
		}
		if _, err := io.CopyN(tw, tr, hdr.Size); err != nil {
			return nil, 0, fmt.Errorf("wal archive: copying %s: %w", name, err)
		}
		files = append(files, name)
		total += hdr.Size
	}
	if err := tw.Close(); err != nil {
		return nil, 0, fmt.Errorf("wal archive: %w", err)
	}
	_, _ = io.Copy(io.Discard, src)
	return files, total, nil
}

func prefixAll(dir string, names []string) []string {
	out := make([]string, len(names))
	for i, n := range names {
		out[i] = path.Join(dir, n)
	}
	return out
}

func (a *WalArchiver) containerOf(ctx context.Context, id state.WorkloadID) (string, error) {
	cs, err := a.o.Docker.ListContainers(ctx, map[string]string{docker.LabelWorkload: string(id)})
	if err != nil {
		return "", err
	}
	for _, c := range cs {
		if c.Running && c.State != "paused" && c.Labels[docker.LabelSystem] == "" {
			return c.ID, nil
		}
	}
	return "", errNoContainer
}

// exec runs argv as the Postgres user in the container; a non-zero exit is an error.
func (a *WalArchiver) exec(ctx context.Context, cid string, stdout io.Writer, argv ...string) error {
	res, err := a.o.Docker.ExecStream(ctx, cid, docker.ExecOptions{Argv: argv, Stdout: stdout, User: walUser})
	if err != nil {
		return err
	}
	if res.ExitCode != 0 {
		return fmt.Errorf("%s exited with code %d: %s", argv[0], res.ExitCode, firstLines(res.Stderr))
	}
	return nil
}

func truncateMessage(s string, n int) string {
	if len(s) <= n {
		return s
	}
	return s[:n]
}
