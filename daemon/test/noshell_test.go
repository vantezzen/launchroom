// Package test holds repository-wide checks for the daemon module.
package test

import (
	"io/fs"
	"os"
	"path/filepath"
	"regexp"
	"strings"
	"testing"
)

// shellPattern catches process spawning through a shell (docs/09-security.md R1).
// forbidigo bans exec.Command entirely in non-test code; this test additionally
// catches shell argv anywhere in the module, including in string constants.
var shellPattern = regexp.MustCompile(`(?:"(?:/bin/|/usr/bin/)?(?:sh|bash|ash|dash|zsh)"\s*,\s*"-c")|(?:\bbash -c\b)|(?:\bsh -c\b)`)

func TestNoShellInvocations(t *testing.T) {
	self, err := filepath.Abs("noshell_test.go")
	if err != nil {
		t.Fatal(err)
	}
	err = filepath.WalkDir("..", func(path string, d fs.DirEntry, err error) error {
		if err != nil {
			return err
		}
		if d.IsDir() || !strings.HasSuffix(path, ".go") {
			return nil
		}
		if abs, _ := filepath.Abs(path); abs == self {
			return nil
		}
		data, err := os.ReadFile(path) //nolint:gosec // walking our own source tree
		if err != nil {
			return err
		}
		if loc := shellPattern.FindIndex(data); loc != nil {
			line := 1 + strings.Count(string(data[:loc[0]]), "\n")
			t.Errorf("%s:%d: shell invocation found (docs/09 R1: use argv arrays and API clients)", path, line)
		}
		return nil
	})
	if err != nil {
		t.Fatal(err)
	}
}

func TestShellPatternMatches(t *testing.T) {
	for _, bad := range []string{`exec.Command("sh", "-c", x)`, `exec.CommandContext(ctx, "/bin/bash","-c", x)`, `"run with bash -c please"`} {
		if !shellPattern.MatchString(bad) {
			t.Errorf("pattern misses %q", bad)
		}
	}
	for _, ok := range []string{`exec.Command("git", "clone", "--", url)`, `"shell"`} {
		if shellPattern.MatchString(ok) {
			t.Errorf("pattern flags %q", ok)
		}
	}
}
