// Package atomicfile writes files so that readers (and the daemon after a crash)
// only ever observe the old or the new content, never a partial write.
package atomicfile

import (
	"fmt"
	"os"
	"path/filepath"
)

// Write replaces path with data atomically: it writes a temporary file in the
// same directory with the given permissions, fsyncs it, renames it over path
// and fsyncs the directory.
func Write(path string, data []byte, perm os.FileMode) (err error) {
	dir := filepath.Dir(path)
	tmp, err := os.CreateTemp(dir, "."+filepath.Base(path)+".tmp-*")
	if err != nil {
		return fmt.Errorf("atomicfile: create temp file: %w", err)
	}
	tmpName := tmp.Name()
	defer func() {
		if err != nil {
			_ = tmp.Close()
			_ = os.Remove(tmpName)
		}
	}()

	if err = tmp.Chmod(perm); err != nil {
		return fmt.Errorf("atomicfile: chmod temp file: %w", err)
	}
	if _, err = tmp.Write(data); err != nil {
		return fmt.Errorf("atomicfile: write temp file: %w", err)
	}
	if err = tmp.Sync(); err != nil {
		return fmt.Errorf("atomicfile: fsync temp file: %w", err)
	}
	if err = tmp.Close(); err != nil {
		return fmt.Errorf("atomicfile: close temp file: %w", err)
	}
	if err = os.Rename(tmpName, path); err != nil {
		return fmt.Errorf("atomicfile: rename into place: %w", err)
	}
	return syncDir(dir)
}

func syncDir(dir string) error {
	d, err := os.Open(dir) //nolint:gosec // dir is the parent of a path chosen by the daemon, not user input
	if err != nil {
		return fmt.Errorf("atomicfile: open dir for fsync: %w", err)
	}
	syncErr := d.Sync()
	closeErr := d.Close()
	if syncErr != nil {
		return fmt.Errorf("atomicfile: fsync dir: %w", syncErr)
	}
	if closeErr != nil {
		return fmt.Errorf("atomicfile: close dir: %w", closeErr)
	}
	return nil
}
