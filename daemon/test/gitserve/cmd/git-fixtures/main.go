// Command git-fixtures serves dev/fixtures/* as git repositories over smart
// HTTP for the dev stack (`git-fixtures` compose service): builds clone
// http://git-fixtures:8080/<name>.git at the SHA listed on GET /.
//
// The fixtures directory is rescanned before every index request (GET /) and
// before every clone/fetch starts (GET /<name>.git/info/refs): fixtures that
// were added, changed or deleted on disk are republished, so no restart is
// needed. Commits are deterministic, so an unchanged fixture keeps its SHA.
// Dev tooling only; it is not shipped.
package main

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"flag"
	"io"
	"io/fs"
	"log/slog"
	"net/http"
	"os"
	"os/signal"
	"path/filepath"
	"slices"
	"strings"
	"sync"
	"syscall"
	"time"

	"github.com/vantezzen/launchroom/daemon/test/gitserve"
)

type entry struct {
	Name     string              `json:"name"`
	SHA      string              `json:"sha"`
	CloneURL string              `json:"cloneUrl"`
	Branches map[string][]string `json:"branches,omitempty"`
}

// server keeps root/<name>.git in sync with the fixture directories.
type server struct {
	fixtures, root, base string
	log                  *slog.Logger

	mu    sync.Mutex
	repos map[string]published // by fixture name
}

type published struct {
	hash string
	repo gitserve.Repo
}

func main() {
	fixtures := flag.String("fixtures", "/fixtures", "directory whose subdirectories are published")
	root := flag.String("root", "/srv/git", "where bare repositories are written")
	listen := flag.String("listen", ":8080", "listen address")
	base := flag.String("base-url", "http://git-fixtures:8080", "URL prefix shown in the index")
	flag.Parse()
	log := slog.New(slog.NewTextHandler(os.Stderr, nil))
	fail := func(err error) {
		log.Error("git-fixtures failed", "err", err)
		os.Exit(1)
	}

	ctx, stop := signal.NotifyContext(context.Background(), os.Interrupt, syscall.SIGTERM)
	defer stop()
	if err := os.MkdirAll(*root, 0o750); err != nil {
		fail(err)
	}
	s := &server{fixtures: *fixtures, root: *root, base: *base, log: log, repos: map[string]published{}}
	if _, err := s.sync(ctx); err != nil {
		fail(err)
	}
	git, err := gitserve.Handler(*root)
	if err != nil {
		fail(err)
	}
	mux := http.NewServeMux()
	mux.HandleFunc("GET /{$}", func(w http.ResponseWriter, r *http.Request) {
		index, err := s.sync(r.Context())
		if err != nil {
			log.Warn("rescanning fixtures failed; serving the last index", "err", err)
		}
		w.Header().Set("Content-Type", "application/json")
		_ = json.NewEncoder(w).Encode(index)
	})
	mux.Handle("/", http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		// A clone or fetch starts with the ref advertisement: rescan first so
		// it sees the fixture as it is on disk now.
		if strings.HasSuffix(r.URL.Path, "/info/refs") {
			if _, err := s.sync(r.Context()); err != nil {
				log.Warn("rescanning fixtures failed; serving the last published repositories", "err", err)
			}
		}
		git.ServeHTTP(w, r)
	}))
	srv := &http.Server{Addr: *listen, Handler: mux, ReadHeaderTimeout: 10 * time.Second}
	go func() {
		<-ctx.Done()
		sctx, cancel := context.WithTimeout(context.WithoutCancel(ctx), 5*time.Second)
		defer cancel()
		_ = srv.Shutdown(sctx)
	}()
	if err := srv.ListenAndServe(); err != nil && !errors.Is(err, http.ErrServerClosed) {
		fail(err)
	}
}

// sync republishes fixtures whose content changed, publishes new ones and
// removes deleted ones. It returns the index (also on error: the last good one
// for fixtures that could not be republished).
func (s *server) sync(ctx context.Context) ([]entry, error) {
	s.mu.Lock()
	defer s.mu.Unlock()
	dirs, err := os.ReadDir(s.fixtures)
	if err != nil {
		return s.indexLocked(), err
	}
	var errs []error
	seen := map[string]bool{}
	for _, d := range dirs {
		if !d.IsDir() || strings.HasPrefix(d.Name(), ".") {
			continue
		}
		name := d.Name()
		seen[name] = true
		hash, err := treeHash(filepath.Join(s.fixtures, name))
		if err != nil {
			errs = append(errs, err)
			continue
		}
		if p, ok := s.repos[name]; ok && p.hash == hash {
			continue
		}
		repo, err := s.publish(ctx, name)
		if err != nil {
			errs = append(errs, err)
			continue
		}
		s.repos[name] = published{hash: hash, repo: repo}
		s.log.Info("fixture published", "name", name, "cloneUrl", s.cloneURL(name), "sha", repo.SHA)
	}
	for name := range s.repos {
		if !seen[name] {
			delete(s.repos, name)
			_ = os.RemoveAll(filepath.Join(s.root, name+".git"))
			s.log.Info("fixture removed", "name", name)
		}
	}
	if len(s.repos) == 0 {
		errs = append(errs, errors.New("no fixture directories found"))
	}
	return s.indexLocked(), errors.Join(errs...)
}

// publish builds the repository in a staging directory and swaps it into
// place, so a clone never sees a half-written repository.
func (s *server) publish(ctx context.Context, name string) (gitserve.Repo, error) {
	staging, err := os.MkdirTemp(s.root, ".staging-")
	if err != nil {
		return gitserve.Repo{}, err
	}
	defer func() { _ = os.RemoveAll(staging) }()
	repo, err := gitserve.Publish(ctx, staging, name, os.DirFS(filepath.Join(s.fixtures, name)))
	if err != nil {
		return gitserve.Repo{}, err
	}
	target := filepath.Join(s.root, name+".git")
	old := filepath.Join(staging, "old.git")
	if err := os.Rename(target, old); err != nil && !errors.Is(err, fs.ErrNotExist) {
		return gitserve.Repo{}, err
	}
	if err := os.Rename(filepath.Join(staging, name+".git"), target); err != nil {
		return gitserve.Repo{}, err
	}
	return repo, nil
}

func (s *server) cloneURL(name string) string { return s.base + "/" + name + ".git" }

func (s *server) indexLocked() []entry {
	names := make([]string, 0, len(s.repos))
	for name := range s.repos {
		names = append(names, name)
	}
	slices.Sort(names)
	index := make([]entry, 0, len(names))
	for _, name := range names {
		r := s.repos[name].repo
		index = append(index, entry{name, r.SHA, s.cloneURL(name), r.Branches})
	}
	return index
}

// treeHash digests every path, mode and file content below dir (fixtures are small).
func treeHash(dir string) (string, error) {
	h := sha256.New()
	err := filepath.WalkDir(dir, func(path string, d fs.DirEntry, err error) error {
		if err != nil {
			return err
		}
		rel, _ := filepath.Rel(dir, path)
		info, err := d.Info()
		if err != nil {
			return err
		}
		_, _ = io.WriteString(h, rel+"\x00"+info.Mode().String()+"\x00")
		if !d.Type().IsRegular() {
			return nil
		}
		f, err := os.Open(path) //nolint:gosec // dev tooling reading the mounted fixtures
		if err != nil {
			return err
		}
		defer func() { _ = f.Close() }()
		_, err = io.Copy(h, f)
		_, _ = io.WriteString(h, "\x00")
		return err
	})
	return hex.EncodeToString(h.Sum(nil)), err
}
