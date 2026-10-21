// Package gitserve turns directories into git repositories served over
// smart HTTP (git http-backend via CGI). It is test and dev tooling only:
// the daemon's integration tests and the dev `git-fixtures` service use it so
// builds can clone fixtures without GitHub. It is never part of the daemon.
//
// Commits are deterministic (fixed author and dates), so a fixture's SHA only
// changes when its files change.
package gitserve

import (
	"bytes"
	"context"
	"errors"
	"fmt"
	"io/fs"
	"net/http"
	"net/http/cgi" //nolint:gosec // G504 (httpoxy) only affects Go < 1.6.3; dev/test tooling serving local fixtures
	"os"
	"os/exec"
	"path/filepath"
	"strings"
)

// Repo is a served repository.
type Repo struct {
	Name string // "node-hello"
	SHA  string // HEAD commit
	// Branches maps extra branches to their commits, oldest first
	// (from the fixture's .branches/<branch>/<step>/ overlays).
	Branches map[string][]string
}

// branchesDir holds per-branch overlays: .branches/<branch>/<step>/ is
// copied over the tree and committed on <branch> (created from main), one
// commit per step in lexical order. Preview environments in dev use these as
// pull request heads (`launchroom:dev:pr`). The directory itself is never
// part of any commit.
const branchesDir = ".branches"

// withoutBranches hides the overlay directory from the main commit.
type withoutBranches struct{ fs.FS }

func (f withoutBranches) Open(name string) (fs.File, error) {
	if name == branchesDir || strings.HasPrefix(name, branchesDir+"/") {
		return nil, fs.ErrNotExist
	}
	return f.FS.Open(name)
}

func (f withoutBranches) ReadDir(name string) ([]fs.DirEntry, error) {
	entries, err := fs.ReadDir(f.FS, name)
	if err != nil || name != "." {
		return entries, err
	}
	out := entries[:0]
	for _, e := range entries {
		if e.Name() != branchesDir {
			out = append(out, e)
		}
	}
	return out, nil
}

func gitEnv() []string {
	return []string{
		"GIT_AUTHOR_NAME=launchroom fixtures", "GIT_AUTHOR_EMAIL=fixtures@launchroom.invalid",
		"GIT_COMMITTER_NAME=launchroom fixtures", "GIT_COMMITTER_EMAIL=fixtures@launchroom.invalid",
		"GIT_AUTHOR_DATE=2026-01-01T00:00:00Z", "GIT_COMMITTER_DATE=2026-01-01T00:00:00Z",
		"GIT_CONFIG_NOSYSTEM=1", "GIT_TERMINAL_PROMPT=0", "HOME=/nonexistent", "PATH=" + os.Getenv("PATH"),
	}
}

// git runs git with argv in dir (no shell).
func git(ctx context.Context, dir string, args ...string) (string, error) {
	cmd := exec.CommandContext(ctx, "git", args...) //nolint:forbidigo,gosec // test/dev tooling: fixed argv, no shell
	cmd.Dir = dir
	cmd.Env = gitEnv()
	var out, errb bytes.Buffer
	cmd.Stdout, cmd.Stderr = &out, &errb
	if err := cmd.Run(); err != nil {
		return "", fmt.Errorf("git %s: %w: %s", strings.Join(args, " "), err, strings.TrimSpace(errb.String()))
	}
	return strings.TrimSpace(out.String()), nil
}

// Publish copies src into a fresh commit and publishes it as root/<name>.git.
func Publish(ctx context.Context, root, name string, src fs.FS) (Repo, error) {
	work, err := os.MkdirTemp("", "gitserve-"+name+"-")
	if err != nil {
		return Repo{}, err
	}
	defer func() { _ = os.RemoveAll(work) }()
	if err := os.CopyFS(work, withoutBranches{src}); err != nil {
		return Repo{}, err
	}
	steps := [][]string{
		{"init", "-q", "-b", "main"},
		{"add", "-A"},
		{"commit", "-q", "--no-gpg-sign", "-m", "fixture " + name},
	}
	for _, s := range steps {
		if _, err := git(ctx, work, s...); err != nil {
			return Repo{}, err
		}
	}
	sha, err := git(ctx, work, "rev-parse", "HEAD")
	if err != nil {
		return Repo{}, err
	}
	branches, err := publishBranches(ctx, work, src)
	if err != nil {
		return Repo{}, err
	}
	bare := filepath.Join(root, name+".git")
	if err := os.RemoveAll(bare); err != nil {
		return Repo{}, err
	}
	if _, err := git(ctx, root, "clone", "-q", "--bare", "--", work, bare); err != nil {
		return Repo{}, err
	}
	for _, kv := range [][2]string{{"uploadpack.allowReachableSHA1InWant", "true"}, {"uploadpack.allowAnySHA1InWant", "true"}, {"http.receivepack", "false"}} {
		if _, err := git(ctx, bare, "config", kv[0], kv[1]); err != nil {
			return Repo{}, err
		}
	}
	return Repo{Name: name, SHA: sha, Branches: branches}, nil
}

// publishBranches commits the fixture's branch overlays (see branchesDir)
// in work and leaves main checked out.
func publishBranches(ctx context.Context, work string, src fs.FS) (map[string][]string, error) {
	branchDirs, err := fs.ReadDir(src, branchesDir)
	if errors.Is(err, fs.ErrNotExist) {
		return nil, nil
	}
	if err != nil {
		return nil, err
	}
	out := map[string][]string{}
	for _, b := range branchDirs {
		if !b.IsDir() {
			continue
		}
		if _, err := git(ctx, work, "checkout", "-q", "-b", b.Name(), "main"); err != nil {
			return nil, err
		}
		stepDirs, err := fs.ReadDir(src, branchesDir+"/"+b.Name())
		if err != nil {
			return nil, err
		}
		for _, step := range stepDirs {
			if !step.IsDir() {
				continue
			}
			overlay, err := fs.Sub(src, branchesDir+"/"+b.Name()+"/"+step.Name())
			if err != nil {
				return nil, err
			}
			if err := overlayFS(work, overlay); err != nil {
				return nil, err
			}
			for _, s := range [][]string{{"add", "-A"}, {"commit", "-q", "--no-gpg-sign", "--allow-empty", "-m", b.Name() + " " + step.Name()}} {
				if _, err := git(ctx, work, s...); err != nil {
					return nil, err
				}
			}
			sha, err := git(ctx, work, "rev-parse", "HEAD")
			if err != nil {
				return nil, err
			}
			out[b.Name()] = append(out[b.Name()], sha)
		}
	}
	if _, err := git(ctx, work, "checkout", "-q", "main"); err != nil {
		return nil, err
	}
	return out, nil
}

// overlayFS copies every file of src into dir, replacing existing files.
func overlayFS(dir string, src fs.FS) error {
	return fs.WalkDir(src, ".", func(path string, d fs.DirEntry, err error) error {
		if err != nil || path == "." {
			return err
		}
		target := filepath.Join(dir, filepath.FromSlash(path))
		if d.IsDir() {
			return os.MkdirAll(target, 0o750)
		}
		data, err := fs.ReadFile(src, path)
		if err != nil {
			return err
		}
		return os.WriteFile(target, data, 0o600)
	})
}

// PublishDirs publishes every subdirectory of dir (skipping README files).
func PublishDirs(ctx context.Context, root, dir string) ([]Repo, error) {
	entries, err := os.ReadDir(dir)
	if err != nil {
		return nil, err
	}
	var repos []Repo
	for _, e := range entries {
		if !e.IsDir() || strings.HasPrefix(e.Name(), ".") {
			continue
		}
		r, err := Publish(ctx, root, e.Name(), os.DirFS(filepath.Join(dir, e.Name())))
		if err != nil {
			return nil, err
		}
		repos = append(repos, r)
	}
	if len(repos) == 0 {
		return nil, errors.New("no fixture directories found")
	}
	return repos, nil
}

// Handler serves root over git smart HTTP (read-only: receive-pack is off).
func Handler(root string) (http.Handler, error) {
	gitPath, err := exec.LookPath("git")
	if err != nil {
		return nil, err
	}
	return &cgi.Handler{
		Path: gitPath,
		Args: []string{"http-backend"},
		Env:  append(gitEnv(), "GIT_PROJECT_ROOT="+root, "GIT_HTTP_EXPORT_ALL=1"),
	}, nil
}
