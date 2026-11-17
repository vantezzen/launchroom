package gitserve

import (
	"os"
	"path/filepath"
	"testing"
	"testing/fstest"
)

func TestOverlayKeepsTheExecBit(t *testing.T) {
	dir := t.TempDir()
	if err := os.WriteFile(filepath.Join(dir, "server"), []byte("old"), 0o600); err != nil {
		t.Fatal(err)
	}
	src := fstest.MapFS{
		"server":         {Data: []byte("#!/bin/sh\n"), Mode: 0o755},
		"bin/migrate":    {Data: []byte("#!/bin/sh\n"), Mode: 0o755},
		"config/app.exs": {Data: []byte("config"), Mode: 0o644},
	}
	if err := overlayFS(dir, src); err != nil {
		t.Fatal(err)
	}
	for path, want := range map[string]os.FileMode{"server": 0o700, "bin/migrate": 0o700, "config/app.exs": 0o600} {
		info, err := os.Stat(filepath.Join(dir, path))
		if err != nil {
			t.Fatal(err)
		}
		if got := info.Mode().Perm(); got != want {
			t.Errorf("%s: mode %v, want %v", path, got, want)
		}
	}
}
