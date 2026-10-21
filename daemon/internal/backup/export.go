package backup

import (
	"archive/tar"
	"compress/gzip"
	"context"
	"errors"
	"fmt"
	"io"
	"strings"
	"sync"

	"github.com/vantezzen/launchroom/daemon/internal/docker"
	"github.com/vantezzen/launchroom/daemon/internal/state"
	"github.com/vantezzen/launchroom/daemon/internal/transfer"
)

// Exports (D55, docs/14 §12.2): a database's data in the engine's native
// format, or a volume's files as a .tar.gz, from the running service or a
// stored backup, uploaded to the dashboard in chunks.

// exportFormat is the native format of an engine's export.
func exportFormat(engine state.DatabaseEngine) (state.DataFormat, bool) {
	switch engine {
	case state.EnginePostgres:
		return state.FormatPostgresCustom, false
	case state.EngineMySQL, state.EngineMariaDB:
		return state.FormatMySQLSQL, true
	case state.EngineMongoDB:
		return state.FormatMongoArchive, false
	case state.EngineClickHouse:
		return state.FormatClickHouseBackup, false
	default:
		return state.FormatRedisRDB, false
	}
}

// exportSink is where an export's bytes go: optionally gzip, then the uploader.
type exportSink struct {
	up *transfer.Uploader
	gz *gzip.Writer
	w  io.Writer
}

func newExportSink(ctx context.Context, c transfer.Client, target state.TransferTarget, compress bool, progress Progress) *exportSink {
	up := transfer.NewUploader(ctx, c, target, func(done, _ int64) { progress("Uploading " + transfer.HumanBytes(done)) })
	s := &exportSink{up: up, w: up}
	if compress {
		s.gz = gzip.NewWriter(up)
		s.w = s.gz
	}
	return s
}

func (s *exportSink) Write(p []byte) (int, error) { return s.w.Write(p) }

// finish flushes everything and returns the result.
func (s *exportSink) finish(format state.DataFormat, compressed bool, durationMs int64) (*state.DataExportResult, error) {
	if s.gz != nil {
		if err := s.gz.Close(); err != nil {
			return nil, err
		}
	}
	if err := s.up.Close(); err != nil {
		return nil, err
	}
	r := s.up.Result()
	return &state.DataExportResult{Format: format, Compressed: compressed, SizeBytes: r.SizeBytes, Chunks: r.Chunks, SHA256: state.SHA256Hex(r.SHA256), DurationMs: durationMs}, nil
}

// ExportDatabase runs a db_export task. creds open spec.Destination (snapshot exports only).
func (e *Engine) ExportDatabase(ctx context.Context, spec *state.DbExportSpec, creds Credentials, logf Logf, progress Progress) (*state.DataExportResult, error) {
	if logf == nil {
		logf = nopLog
	}
	if progress == nil {
		progress = nopProgress
	}
	if e.o.Transfers == nil {
		return nil, fmt.Errorf("backup: exports: %w", ErrUnsupported)
	}
	en, err := engineFor(spec.Engine)
	if err != nil {
		return nil, err
	}
	start := e.o.Now()
	format, compress := exportFormat(spec.Engine)
	ctx, cancel := context.WithCancel(ctx)
	defer cancel()
	sink := newExportSink(ctx, e.o.Transfers, spec.Transfer, compress, progress)

	if spec.Snapshot != "" {
		r, err := e.openRepo(*spec.Destination, creds)
		if err != nil {
			return nil, err
		}
		if ok, err := r.exists(ctx); err != nil {
			return nil, err
		} else if !ok {
			return nil, errors.New("backup: the backup repository does not exist")
		}
		snap, file, err := r.snapshotFile(ctx, spec.Snapshot, en.file)
		if err != nil {
			return nil, err
		}
		logf("Decrypting backup %s", short(snap.ID))
		if err := r.streamFile(ctx, snap.ID, file, func(src io.Reader) error {
			_, err := io.Copy(sink, src)
			return err
		}); err != nil {
			return nil, err
		}
		return sink.finish(format, compress, e.o.Now().Sub(start).Milliseconds())
	}

	env, err := e.workloadEnv(spec.Workload)
	if err != nil {
		return nil, err
	}
	id, err := e.container(ctx, spec.Workload)
	if err != nil {
		return nil, err
	}
	logf("Dumping %s", spec.Engine)
	if spec.Engine == state.EngineClickHouse {
		if err := e.clickhouseDump(ctx, id, env, sink); err != nil {
			return nil, err
		}
		return sink.finish(format, compress, e.o.Now().Sub(start).Milliseconds())
	}
	cmd, err := en.dump(env)
	if err != nil {
		return nil, err
	}
	res, err := e.o.Docker.ExecStream(ctx, id, docker.ExecOptions{Argv: cmd.argv, Env: cmd.env, Stdin: cmd.input(nil), Stdout: sink})
	switch {
	case err != nil:
		return nil, fmt.Errorf("backup: running %s: %w", cmd.argv[0], err)
	case res.ExitCode != 0:
		return nil, fmt.Errorf("%s failed (exit %d): %s", cmd.argv[0], res.ExitCode, cmd.masked(res.Stderr))
	}
	return sink.finish(format, compress, e.o.Now().Sub(start).Milliseconds())
}

// ExportVolume runs a volume_export task: the volume's files as a .tar.gz
// whose names are relative to the volume ("./", "uploads/a.png"), with the
// numeric owners and modes kept.
func (e *Engine) ExportVolume(ctx context.Context, spec *state.VolumeExportSpec, creds Credentials, logf Logf, progress Progress) (*state.DataExportResult, error) {
	if logf == nil {
		logf = nopLog
	}
	if progress == nil {
		progress = nopProgress
	}
	if e.o.Transfers == nil || e.o.Volumes == nil {
		return nil, fmt.Errorf("backup: exports: %w", ErrUnsupported)
	}
	start := e.o.Now()
	ctx, cancel := context.WithCancel(ctx)
	defer cancel()
	sink := newExportSink(ctx, e.o.Transfers, spec.Transfer, true, progress)

	if spec.Snapshot != "" {
		r, err := e.openRepo(*spec.Destination, creds)
		if err != nil {
			return nil, err
		}
		if ok, err := r.exists(ctx); err != nil {
			return nil, err
		} else if !ok {
			return nil, errors.New("backup: the backup repository does not exist")
		}
		snap, file, err := r.snapshotFile(ctx, spec.Snapshot, VolumeFile)
		if err != nil {
			return nil, err
		}
		logf("Decrypting volume backup %s", short(snap.ID))
		if err := r.streamFile(ctx, snap.ID, file, func(src io.Reader) error { return rerootVolumeTar(sink, src) }); err != nil {
			return nil, err
		}
		return sink.finish(state.FormatTar, true, e.o.Now().Sub(start).Milliseconds())
	}

	vol, err := e.findVolume(ctx, spec.Volume)
	if err != nil {
		return nil, err
	}
	e.sweepHelpers(ctx)
	hid, remove, err := e.helper(ctx, vol.Name, true)
	if err != nil {
		return nil, err
	}
	defer remove()
	unpause := func() {}
	if spec.Consistency == state.ConsistencyPause {
		paused, err := e.pauseUsers(ctx, vol.Name)
		if err != nil {
			return nil, err
		}
		unpause = sync.OnceFunc(func() { e.unpauseAll(ctx, paused) })
		if len(paused) > 0 {
			logf("Paused the service while the volume is read")
		}
	}
	defer unpause()
	logf("Reading volume %s", spec.Volume)
	src, err := e.o.Volumes.StreamFromContainer(ctx, hid, docker.HelperMount)
	if err != nil {
		return nil, err
	}
	defer func() { _ = src.Close() }()
	if err := rerootVolumeTar(sink, src); err != nil {
		return nil, err
	}
	unpause()
	return sink.finish(state.FormatTar, true, e.o.Now().Sub(start).Milliseconds())
}

// rerootVolumeTar rewrites a volume tar (entries "data/…") with names
// relative to the volume: "data/" becomes "./", "data/x" becomes "x" (hard
// link targets too). Owners, modes and times are kept; extended attributes
// and owner names are left out.
func rerootVolumeTar(dst io.Writer, src io.Reader) error {
	tr := tar.NewReader(src)
	tw := tar.NewWriter(dst)
	for {
		h, err := tr.Next()
		if errors.Is(err, io.EOF) {
			break
		}
		if err != nil {
			return fmt.Errorf("backup: reading the volume: %w", err)
		}
		name, ok := volumeEntryName(h.Name)
		if !ok {
			return fmt.Errorf("backup: unexpected entry %q in the volume", clip(h.Name, 96))
		}
		out := &tar.Header{Name: reroot(name), Mode: h.Mode, Uid: h.Uid, Gid: h.Gid, ModTime: h.ModTime, Typeflag: h.Typeflag, Linkname: h.Linkname, Format: tar.FormatPAX}
		switch h.Typeflag {
		case tar.TypeDir:
			out.Name = strings.TrimSuffix(out.Name, "/") + "/"
		case tar.TypeReg, tar.TypeRegA: //nolint:staticcheck // legacy regular files
			out.Typeflag, out.Size = tar.TypeReg, h.Size
		case tar.TypeLink:
			target, ok := volumeEntryName(h.Linkname)
			if !ok {
				return fmt.Errorf("backup: unexpected hard link %q in the volume", clip(h.Name, 96))
			}
			out.Linkname = reroot(target)
		case tar.TypeSymlink, tar.TypeFifo:
		default:
			continue // device nodes can't exist in a volume a workload wrote
		}
		if err := tw.WriteHeader(out); err != nil {
			return err
		}
		if out.Typeflag == tar.TypeReg {
			if _, err := io.CopyN(tw, tr, h.Size); err != nil {
				return fmt.Errorf("backup: reading the volume: %w", err)
			}
		}
	}
	return tw.Close()
}

func reroot(clean string) string {
	if clean == "data" {
		return "./"
	}
	return strings.TrimPrefix(clean, "data/")
}
