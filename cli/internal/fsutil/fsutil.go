// Package fsutil writes files the CLI keeps (config, credentials, the link
// file, pulled .env and launchroom.json) without leaving a half-written file
// behind.
package fsutil

import (
	"os"
	"path/filepath"
	"runtime"
)

// WriteFileAtomic writes via a temp file in the same directory and rename,
// creating the directory with 0700.
func WriteFileAtomic(path string, data []byte, perm os.FileMode) error {
	dir := filepath.Dir(path)
	if err := os.MkdirAll(dir, 0o700); err != nil {
		return err
	}
	tmp, err := os.CreateTemp(dir, "."+filepath.Base(path)+".tmp-*")
	if err != nil {
		return err
	}
	name := tmp.Name()
	defer func() { _ = os.Remove(name) }()
	// Windows has no POSIX modes; the file inherits the directory's ACL.
	if err := tmp.Chmod(perm); err != nil && runtime.GOOS != "windows" {
		_ = tmp.Close()
		return err
	}
	if _, err := tmp.Write(data); err != nil {
		_ = tmp.Close()
		return err
	}
	if err := tmp.Sync(); err != nil {
		_ = tmp.Close()
		return err
	}
	if err := tmp.Close(); err != nil {
		return err
	}
	return os.Rename(name, path)
}
