package backup

import (
	"archive/tar"
	"archive/zip"
	"bufio"
	"bytes"
	"compress/gzip"
	"errors"
	"fmt"
	"io"
	"io/fs"
	"os"
	"path"
	"strings"
	"time"

	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// Volume archive imports (D55, docs/14 §12.4): an uploaded .tar, .tar.gz or
// .zip is converted entry by entry into the tar launchroomd uploads into a
// volume helper (names under data/). Anything a workload with all
// capabilities dropped could not have created itself, or that would land
// outside the volume, refuses the import.

// errUnsafeImport marks an archive launchroom refuses to extract.
var errUnsafeImport = errors.New("the archive can't be imported")

// archiveOptions configure the conversion.
type archiveOptions struct {
	// Strip drops leading path components (tar --strip-components).
	Strip int
	// Owner sets every entry's owner (nil: the archive's; zip: DefaultOwner).
	Owner *state.FileOwner
	// DefaultOwner is the owner of entries without one (zip, implicit folders).
	DefaultOwner state.FileOwner
	// RootMode is the mode of the volume's root folder (written first).
	RootMode int64
	// MaxBytes bounds the content written (archive bombs).
	MaxBytes int64
}

// archiveStats count what was converted.
type archiveStats struct {
	Files, Bytes, Skipped int64
	Format                state.DataFormat
	Compressed            bool
}

// archiveEntry is one archive member, whatever the format.
type archiveEntry struct {
	name     string
	typ      byte // tar type flag
	mode     int64
	uid, gid int
	owned    bool // the archive carries an owner
	link     string
	size     int64
	mtime    time.Time
	body     io.Reader
}

// converter writes the data/-rooted tar.
type converter struct {
	o        archiveOptions
	tw       *tar.Writer
	stats    archiveStats
	dirs     map[string]bool
	files    map[string]bool
	symlinks map[string]string // name → target
}

func refuse(format string, args ...any) error {
	return fmt.Errorf("%w: %s", errUnsafeImport, fmt.Sprintf(format, args...))
}

// convertArchive converts the archive at path into dst.
func convertArchive(dst io.Writer, path string, o archiveOptions) (archiveStats, error) {
	f, err := os.Open(path) //nolint:gosec // generated path inside the data dir
	if err != nil {
		return archiveStats{}, err
	}
	defer func() { _ = f.Close() }()
	head := make([]byte, 512)
	n, _ := io.ReadFull(f, head)
	head = head[:n]
	if _, err := f.Seek(0, io.SeekStart); err != nil {
		return archiveStats{}, err
	}
	c := &converter{o: o, tw: tar.NewWriter(dst), dirs: map[string]bool{}, files: map[string]bool{}, symlinks: map[string]string{}}
	if err := c.root(); err != nil {
		return c.stats, err
	}
	switch {
	case bytes.HasPrefix(head, []byte("PK\x03\x04")) || bytes.HasPrefix(head, []byte("PK\x05\x06")):
		c.stats.Format = state.FormatZip
		fi, err := f.Stat()
		if err != nil {
			return c.stats, err
		}
		err = c.zip(f, fi.Size())
		if err != nil {
			return c.stats, err
		}
	case len(head) >= 2 && head[0] == 0x1f && head[1] == 0x8b:
		c.stats.Format, c.stats.Compressed = state.FormatTar, true
		zr, err := gzip.NewReader(bufio.NewReader(f))
		if err != nil {
			return c.stats, refuse("the file looks gzipped but isn't readable")
		}
		if err := c.tar(zr); err != nil {
			return c.stats, err
		}
	case len(head) >= 262 && bytes.HasPrefix(head[257:], []byte("ustar")):
		c.stats.Format = state.FormatTar
		if err := c.tar(bufio.NewReader(f)); err != nil {
			return c.stats, err
		}
	default:
		return c.stats, errors.New("this isn't a .tar, .tar.gz or .zip archive")
	}
	// Links may point through links written later: check them all again
	// with the complete set (the dry run before any extraction does this).
	for name := range c.symlinks {
		if !c.inside(name) {
			return c.stats, refuse("the symlink %q points outside the archive", clip(name, 96))
		}
	}
	return c.stats, c.tw.Close()
}

// inside resolves the symlink name like the kernel would (through the
// archive's other symlinks, at most 40 hops) and reports whether it stays
// within the archive.
func (c *converter) inside(name string) bool {
	hops := 0
	var resolve func(dir []string, target string) ([]string, bool)
	resolve = func(dir []string, target string) ([]string, bool) {
		cur := append([]string(nil), dir...)
		for _, part := range strings.Split(target, "/") {
			switch part {
			case "", ".":
				continue
			case "..":
				if len(cur) == 0 {
					return nil, false
				}
				cur = cur[:len(cur)-1]
				continue
			}
			cur = append(cur, part)
			if t, ok := c.symlinks[strings.Join(cur, "/")]; ok {
				hops++
				if hops > 40 || strings.HasPrefix(t, "/") {
					return nil, false
				}
				var ok bool
				if cur, ok = resolve(cur[:len(cur)-1], t); !ok {
					return nil, false
				}
			}
		}
		return cur, true
	}
	parts := strings.Split(name, "/")
	_, ok := resolve(parts[:len(parts)-1], c.symlinks[name])
	return ok
}

func (c *converter) owner(e *archiveEntry) (int, int) {
	switch {
	case c.o.Owner != nil:
		return c.o.Owner.UID, c.o.Owner.GID
	case e != nil && e.owned:
		return e.uid, e.gid
	}
	return c.o.DefaultOwner.UID, c.o.DefaultOwner.GID
}

// root writes the volume's root folder first: its owner and mode survive a
// replace (images running as non-root need them).
func (c *converter) root() error {
	uid, gid := c.owner(nil)
	mode := c.o.RootMode & 0o1777
	if mode == 0 {
		mode = 0o755
	}
	c.dirs[""] = true
	return c.tw.WriteHeader(&tar.Header{Typeflag: tar.TypeDir, Name: "data/", Mode: mode, Uid: uid, Gid: gid, ModTime: time.Now(), Format: tar.FormatPAX})
}

// cleanName validates and normalises an archive name; "" means the
// archive's root (skipped) or an entry stripped away.
func (c *converter) cleanName(raw string) (string, error) {
	name := raw
	if strings.ContainsRune(name, 0) {
		return "", refuse("an entry name contains a NUL byte")
	}
	if strings.HasPrefix(name, "/") {
		return "", refuse("%q has an absolute path", clip(raw, 96))
	}
	for _, part := range strings.Split(name, "/") {
		if part == ".." {
			return "", refuse("%q leaves the archive (..)", clip(raw, 96))
		}
	}
	name = strings.TrimSuffix(path.Clean("./"+name), "/")
	if name == "." {
		return "", nil
	}
	parts := strings.Split(name, "/")
	if len(parts) <= c.o.Strip {
		return "", nil
	}
	name = strings.Join(parts[c.o.Strip:], "/")
	// Nothing may be written through a symlink of the archive.
	for p := path.Dir(name); p != "."; p = path.Dir(p) {
		if _, ok := c.symlinks[p]; ok {
			return "", refuse("%q is inside the symlink %q", clip(raw, 96), clip(p, 96))
		}
	}
	return name, nil
}

// parents writes the missing folders above name (owned like DefaultOwner).
func (c *converter) parents(name string) error {
	dir := path.Dir(name)
	if dir == "." || c.dirs[dir] {
		return nil
	}
	if err := c.parents(dir); err != nil {
		return err
	}
	uid, gid := c.owner(nil)
	c.dirs[dir] = true
	return c.tw.WriteHeader(&tar.Header{Typeflag: tar.TypeDir, Name: "data/" + dir + "/", Mode: 0o755, Uid: uid, Gid: gid, ModTime: time.Now(), Format: tar.FormatPAX})
}

func (c *converter) add(e *archiveEntry) error {
	name, err := c.cleanName(e.name)
	if err != nil || name == "" {
		if err == nil && e.typ != tar.TypeDir {
			c.stats.Skipped++
		}
		return err
	}
	if _, ok := c.symlinks[name]; ok {
		return refuse("%q replaces a symlink of the archive", clip(e.name, 96))
	}
	hdr := &tar.Header{Name: "data/" + name, Mode: e.mode & 0o1777, ModTime: e.mtime, Format: tar.FormatPAX}
	hdr.Uid, hdr.Gid = c.owner(e)
	switch e.typ {
	case tar.TypeDir:
		// Written again when it was a parent already: its own entry carries
		// the archive's mode and owner.
		hdr.Typeflag, hdr.Name = tar.TypeDir, hdr.Name+"/"
		c.dirs[name] = true
	case tar.TypeReg, tar.TypeRegA: //nolint:staticcheck // TypeRegA is how old tars mark files
		hdr.Typeflag, hdr.Size = tar.TypeReg, e.size
		c.files[name] = true
	case tar.TypeSymlink:
		t := e.link
		if t == "" || strings.HasPrefix(t, "/") || strings.ContainsRune(t, 0) {
			return refuse("the symlink %q points to %q (absolute or empty)", clip(e.name, 96), clip(t, 96))
		}
		if c.dirs[name] || c.files[name] {
			return refuse("the symlink %q replaces a folder or file of the archive", clip(e.name, 96))
		}
		c.symlinks[name] = t
		if !c.inside(name) {
			return refuse("the symlink %q points outside the archive", clip(e.name, 96))
		}
		hdr.Typeflag, hdr.Linkname = tar.TypeSymlink, t
	case tar.TypeLink:
		target, err := c.cleanName(e.link)
		if err != nil || target == "" || !c.files[target] {
			return refuse("the hard link %q doesn't point to an earlier file of the archive", clip(e.name, 96))
		}
		hdr.Typeflag, hdr.Linkname = tar.TypeLink, "data/"+target
		c.files[name] = true
	case tar.TypeFifo:
		c.stats.Skipped++
		return nil
	case tar.TypeXGlobalHeader:
		return nil
	default:
		return refuse("%q is a device node or special file (type %q)", clip(e.name, 96), string(e.typ))
	}
	if err := c.parents(name); err != nil {
		return err
	}
	if hdr.Typeflag == tar.TypeReg {
		c.stats.Bytes += e.size
		if c.o.MaxBytes > 0 && c.stats.Bytes > c.o.MaxBytes {
			return refuse("it expands to more than %s", humanBytes(c.o.MaxBytes))
		}
	}
	if err := c.tw.WriteHeader(hdr); err != nil {
		return err
	}
	if hdr.Typeflag == tar.TypeReg {
		if _, err := io.CopyN(c.tw, e.body, e.size); err != nil {
			return fmt.Errorf("reading %q from the archive: %w", clip(e.name, 96), err)
		}
	}
	c.stats.Files++
	return nil
}

func (c *converter) tar(r io.Reader) error {
	tr := tar.NewReader(r)
	for {
		h, err := tr.Next()
		if errors.Is(err, io.EOF) {
			return nil
		}
		if err != nil {
			return fmt.Errorf("reading the archive: %w", err)
		}
		if err := c.add(&archiveEntry{name: h.Name, typ: h.Typeflag, mode: h.Mode, uid: h.Uid, gid: h.Gid, owned: true, link: h.Linkname, size: h.Size, mtime: h.ModTime, body: tr}); err != nil {
			return err
		}
	}
}

func (c *converter) zip(r io.ReaderAt, size int64) error {
	zr, err := zip.NewReader(r, size)
	if err != nil {
		return fmt.Errorf("reading the zip archive: %w", err)
	}
	for _, f := range zr.File {
		name := strings.ReplaceAll(f.Name, "\\", "/")
		m := f.Mode()
		unix := f.CreatorVersion>>8 == 3
		e := &archiveEntry{name: name, mtime: f.Modified}
		switch {
		case m&fs.ModeSymlink != 0:
			e.typ = tar.TypeSymlink
			rc, err := f.Open()
			if err != nil {
				return err
			}
			b, err := io.ReadAll(io.LimitReader(rc, 4097))
			_ = rc.Close()
			if err != nil || len(b) > 4096 {
				return refuse("the symlink %q is unreadable", clip(f.Name, 96))
			}
			e.link, e.mode = string(b), 0o777
		case m.IsDir() || strings.HasSuffix(name, "/"):
			e.typ, e.mode = tar.TypeDir, 0o755
		case m&(fs.ModeDevice|fs.ModeCharDevice|fs.ModeSocket|fs.ModeNamedPipe|fs.ModeIrregular) != 0:
			e.typ = tar.TypeChar
			if m&fs.ModeNamedPipe != 0 {
				e.typ = tar.TypeFifo
			}
		default:
			e.typ, e.mode, e.size = tar.TypeReg, 0o644, int64(f.UncompressedSize64) //nolint:gosec // bounded by MaxBytes
		}
		if unix && e.typ != tar.TypeSymlink {
			e.mode = int64(m.Perm())
			if m&fs.ModeSticky != 0 {
				e.mode |= 0o1000
			}
		}
		if e.typ == tar.TypeReg {
			rc, err := f.Open()
			if err != nil {
				return fmt.Errorf("reading %q from the zip archive: %w", clip(f.Name, 96), err)
			}
			e.body = rc
			err = c.add(e)
			_ = rc.Close()
			if err != nil {
				return err
			}
			continue
		}
		if err := c.add(e); err != nil {
			return err
		}
	}
	return nil
}
