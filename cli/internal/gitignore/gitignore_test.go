package gitignore

import (
	"os"
	"path/filepath"
	"testing"
)

func write(t *testing.T, path, content string) {
	t.Helper()
	if err := os.MkdirAll(filepath.Dir(path), 0o755); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(path, []byte(content), 0o644); err != nil {
		t.Fatal(err)
	}
}

func TestCheck(t *testing.T) {
	root := t.TempDir()
	if err := os.Mkdir(filepath.Join(root, ".git"), 0o755); err != nil {
		t.Fatal(err)
	}
	write(t, filepath.Join(root, ".gitignore"), "# comment\n.env*\n!.env.example\n/build/\nlogs/**/*.log\n")
	write(t, filepath.Join(root, "apps", "web", ".gitignore"), "secrets.txt\n")

	cases := map[string]bool{
		".env":                       true,
		".env.local":                 true,
		".env.example":               false,
		"apps/web/.env":              true,
		"apps/web/secrets.txt":       true,
		"secrets.txt":                false,
		"build/out.env":              true,
		"apps/build/x":               false,
		"logs/a/b/c.log":             true,
		"README.md":                  false,
		"apps/web/config/production": false,
	}
	for rel, want := range cases {
		res, err := Check(filepath.Join(root, rel))
		if err != nil {
			t.Fatal(err)
		}
		if !res.InRepo || res.Ignored != want {
			t.Errorf("%s: ignored=%v inRepo=%v, want ignored=%v", rel, res.Ignored, res.InRepo, want)
		}
	}
}

func TestOutsideRepo(t *testing.T) {
	res, err := Check(filepath.Join(t.TempDir(), ".env"))
	if err != nil || res.InRepo {
		t.Fatalf("outside a repo: %+v %v", res, err)
	}
}

func TestInfoExclude(t *testing.T) {
	root := t.TempDir()
	write(t, filepath.Join(root, ".git", "info", "exclude"), ".env\n")
	res, _ := Check(filepath.Join(root, ".env"))
	if !res.Ignored {
		t.Fatal(".git/info/exclude must count")
	}
}
