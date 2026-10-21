package link

import (
	"os"
	"path/filepath"
	"testing"
)

func TestWriteAndFindUpwards(t *testing.T) {
	root := t.TempDir()
	l := Link{URL: "https://lr.example.com", ProjectID: "prj_1", EnvironmentID: "env_1", ServiceID: "svc_1"}
	path, err := Write(root, l)
	if err != nil {
		t.Fatal(err)
	}
	if path != filepath.Join(root, ".launchroom", "project.json") {
		t.Fatalf("path = %s", path)
	}
	deep := filepath.Join(root, "a", "b", "c")
	if err := os.MkdirAll(deep, 0o755); err != nil {
		t.Fatal(err)
	}
	got, dir, err := Find(deep)
	if err != nil || got == nil || *got != l {
		t.Fatalf("Find = %+v %v", got, err)
	}
	if dir != root {
		t.Fatalf("dir = %s, want %s", dir, root)
	}
}

func TestFindNone(t *testing.T) {
	got, _, err := Find(t.TempDir())
	if err != nil || got != nil {
		t.Fatalf("expected no link, got %+v %v", got, err)
	}
}

func TestFindInvalid(t *testing.T) {
	root := t.TempDir()
	if err := os.MkdirAll(filepath.Join(root, DirName), 0o755); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(filepath.Join(root, DirName, FileName), []byte("{"), 0o644); err != nil {
		t.Fatal(err)
	}
	if _, _, err := Find(root); err == nil {
		t.Fatal("invalid JSON must be an error")
	}
}
