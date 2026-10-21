package backup

import (
	"archive/tar"
	"errors"
	"fmt"
	"io"
	"path"
	"strings"
)

// errUnsafeArchive marks a volume snapshot that holds entries a workload
// could never have created (2026-10 review L4).
var errUnsafeArchive = errors.New("backup: the snapshot contains entries that are not allowed in a volume")

// sanitizeVolumeTar copies a volume snapshot's tar stream from src to dst,
// keeping only what a workload (all capabilities dropped) can create itself,
// before Docker extracts it into the volume (docs/14 §2):
//   - names must stay under data/ (clean, relative, no "..");
//   - regular files, directories and symlinks are kept; hard links only to
//     an earlier regular file of the archive; FIFOs are skipped; device
//     nodes and every other type refuse the restore;
//   - setuid/setgid bits are cleared, extended attributes (file
//     capabilities, ACLs, SELinux labels) and owner names are dropped.
//
// Numeric owners are kept: database engines refuse a data directory that is
// not owned by their own UID (Postgres, MySQL), and a file owned by another
// UID grants nothing without CAP_CHOWN/CAP_FOWNER. Whatever follows the
// archive's end marker is read and discarded so the producer never blocks.
func sanitizeVolumeTar(dst io.Writer, src io.Reader) error {
	tr := tar.NewReader(src)
	tw := tar.NewWriter(dst)
	files := map[string]bool{}
	for {
		hdr, err := tr.Next()
		if errors.Is(err, io.EOF) {
			break
		}
		if err != nil {
			return fmt.Errorf("backup: reading the snapshot: %w", err)
		}
		name, ok := volumeEntryName(hdr.Name)
		if !ok {
			return fmt.Errorf("%w: %q is outside the volume", errUnsafeArchive, hdr.Name)
		}
		out := &tar.Header{
			Name: name, Mode: hdr.Mode &^ (0o4000 | 0o2000), Uid: hdr.Uid, Gid: hdr.Gid,
			ModTime: hdr.ModTime, Typeflag: hdr.Typeflag,
		}
		switch hdr.Typeflag {
		case tar.TypeReg, '\x00': // '\x00' is the legacy regular-file flag (TypeRegA)
			out.Typeflag, out.Size = tar.TypeReg, hdr.Size
			files[name] = true
		case tar.TypeDir:
			out.Name = strings.TrimSuffix(name, "/") + "/"
		case tar.TypeSymlink:
			if hdr.Linkname == "" {
				return fmt.Errorf("%w: empty symlink %q", errUnsafeArchive, hdr.Name)
			}
			out.Linkname = hdr.Linkname
		case tar.TypeLink:
			target, ok := volumeEntryName(hdr.Linkname)
			if !ok || !files[target] {
				return fmt.Errorf("%w: hard link %q points outside the volume's files", errUnsafeArchive, hdr.Name)
			}
			out.Linkname = target
		case tar.TypeFifo, tar.TypeXGlobalHeader:
			continue
		default:
			return fmt.Errorf("%w: %q has type %q (device nodes and special files are refused)", errUnsafeArchive, hdr.Name, string(hdr.Typeflag))
		}
		if err := tw.WriteHeader(out); err != nil {
			return fmt.Errorf("backup: writing the volume: %w", err)
		}
		if out.Typeflag == tar.TypeReg {
			// Bounded by the header's size (tar.Writer refuses more); the
			// archive itself is the snapshot of a volume, not a compressed stream.
			if _, err := io.CopyN(tw, tr, hdr.Size); err != nil {
				return fmt.Errorf("backup: copying %q: %w", name, err)
			}
		}
	}
	if err := tw.Close(); err != nil {
		return fmt.Errorf("backup: writing the volume: %w", err)
	}
	_, _ = io.Copy(io.Discard, src)
	return nil
}

// volumeEntryName returns the clean archive name if it is "data" or below it.
func volumeEntryName(name string) (string, bool) {
	if name == "" || strings.HasPrefix(name, "/") || strings.ContainsRune(name, 0) {
		return "", false
	}
	for _, part := range strings.Split(strings.TrimSuffix(name, "/"), "/") {
		if part == ".." {
			return "", false
		}
	}
	clean := path.Clean(strings.TrimPrefix(name, "./"))
	if clean != "data" && !strings.HasPrefix(clean, "data/") {
		return "", false
	}
	return clean, true
}
