package inventory

import (
	"errors"
	"fmt"
	"io"
	"io/fs"
	"os"
	"path"
	"path/filepath"
	"regexp"
	"strings"

	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// Source analysis (docs/22 §3.3): manifests and lockfiles of the build's
// commit, read from the checkout through os.Root (symlinks can't lead out
// of it). Nothing is installed or executed; the files are parsed as data.

// maxManifestBytes bounds one manifest or lockfile.
const maxManifestBytes = 32 << 20

// nameFacts are a language ecosystem's scopes by package name (PyPI,
// Composer, RubyGems): names in the main and the dev groups, and the names
// the app's manifest lists itself.
type nameFacts struct {
	main, dev, direct map[string]bool
}

func newNameFacts() *nameFacts {
	return &nameFacts{main: map[string]bool{}, dev: map[string]bool{}, direct: map[string]bool{}}
}

// devOnly reports a name found only in development groups.
func (f *nameFacts) devOnly(name string) bool { return f.dev[name] && !f.main[name] }

// sourceFacts is everything source analysis learned.
type sourceFacts struct {
	lockfiles []state.InventoryLockfile
	notes     []string
	npm       *npmFacts
	pypi      *nameFacts
	composer  *nameFacts
	gems      *nameFacts
	goDirect  map[string]bool
}

func (s *sourceFacts) note(format string, args ...any) {
	if len(s.notes) < state.MaxInventoryNotes {
		n := fmt.Sprintf(format, args...)
		if len(n) > 256 {
			n = n[:253] + "..."
		}
		s.notes = append(s.notes, n)
	}
}

func (s *sourceFacts) addLockfile(rel string, kind state.LockfileKind, packages int) {
	if len(s.lockfiles) >= state.MaxInventoryLockfiles || state.RelPath(rel).Validate() != nil {
		return
	}
	for _, l := range s.lockfiles {
		if string(l.Path) == rel {
			return
		}
	}
	s.lockfiles = append(s.lockfiles, state.InventoryLockfile{Path: state.RelPath(rel), Kind: kind, Packages: min(packages, 100000)})
}

// tree reads files of the checkout.
type tree struct {
	root *os.Root
	// app is the app directory relative to the checkout ("" = root).
	app string
}

// dirs are the directories searched: the app directory, then each parent up
// to the repository root (workspace lockfiles).
func (t tree) dirs() []string {
	out := []string{t.app}
	for d := t.app; d != ""; {
		d = path.Dir(d)
		if d == "." {
			d = ""
		}
		out = append(out, d)
	}
	return out
}

func join(dir, name string) string {
	if dir == "" {
		return name
	}
	return dir + "/" + name
}

var errTooLarge = errors.New("file too large")

// read returns a regular file's content; ok is false when it doesn't exist.
func (t tree) read(rel string) ([]byte, bool, error) {
	f, err := t.root.Open(filepath.FromSlash(rel))
	if err != nil {
		if errors.Is(err, fs.ErrNotExist) {
			return nil, false, nil
		}
		return nil, false, err
	}
	defer func() { _ = f.Close() }()
	st, err := f.Stat()
	if err != nil || !st.Mode().IsRegular() {
		return nil, false, nil //nolint:nilerr // not a regular file: treated as missing
	}
	b, err := io.ReadAll(io.LimitReader(f, maxManifestBytes+1))
	if err != nil {
		return nil, false, err
	}
	if len(b) > maxManifestBytes {
		return nil, true, errTooLarge
	}
	return b, true, nil
}

// find returns the nearest of names (in order) from the app directory up.
func (t tree) find(names ...string) (rel string, data []byte, err error) {
	for _, d := range t.dirs() {
		for _, n := range names {
			b, ok, err := t.read(join(d, n))
			if ok || err != nil {
				return join(d, n), b, err
			}
		}
	}
	return "", nil, nil
}

// analyze reads the checkout rooted at root with the app in app.
func analyze(root *os.Root, app string) *sourceFacts {
	t := tree{root: root, app: app}
	s := &sourceFacts{}
	analyzeNpm(t, s)
	analyzePython(t, s)
	analyzeComposer(t, s)
	analyzeGems(t, s)
	analyzeGo(t, s)
	return s
}

// relTo is target relative to base (slash paths inside the checkout).
func relTo(base, target string) string {
	if base == "" {
		return target
	}
	if target == base {
		return ""
	}
	return strings.TrimPrefix(target, base+"/")
}

// ---- Go ----

var reGoRequire = regexp.MustCompile(`^\s*(?:require\s+)?([A-Za-z0-9][A-Za-z0-9._~/+-]*)\s+(v[0-9][^\s]*)(\s*//\s*indirect)?`)

func analyzeGo(t tree, s *sourceFacts) {
	rel, b, err := t.find("go.mod")
	if rel == "" || err != nil {
		return
	}
	s.goDirect = map[string]bool{}
	n := 0
	inBlock := false
	for _, line := range strings.Split(string(b), "\n") {
		trimmed := strings.TrimSpace(line)
		switch {
		case strings.HasPrefix(trimmed, "require ("):
			inBlock = true
			continue
		case inBlock && trimmed == ")":
			inBlock = false
			continue
		case !inBlock && !strings.HasPrefix(trimmed, "require "):
			continue
		}
		m := reGoRequire.FindStringSubmatch(trimmed)
		if m == nil {
			continue
		}
		n++
		if m[3] == "" {
			s.goDirect[m[1]] = true
		}
	}
	s.addLockfile(rel, state.LockfileGoMod, n)
}

// ---- Composer ----

func analyzeComposer(t tree, s *sourceFacts) {
	f := newNameFacts()
	found := false
	if rel, b, err := t.find("composer.json"); rel != "" && err == nil {
		var m struct {
			Require    map[string]string `json:"require"`
			RequireDev map[string]string `json:"require-dev"`
		}
		if jsonUnmarshal(b, &m) == nil {
			found = true
			for n := range m.Require {
				f.direct[strings.ToLower(n)] = true
			}
			for n := range m.RequireDev {
				f.direct[strings.ToLower(n)] = true
				f.dev[strings.ToLower(n)] = true
			}
		}
	}
	if rel, b, err := t.find("composer.lock"); rel != "" && err == nil {
		var m struct {
			Packages    []struct{ Name string } `json:"packages"`
			PackagesDev []struct{ Name string } `json:"packages-dev"`
		}
		if jsonUnmarshal(b, &m) == nil {
			found = true
			for _, p := range m.Packages {
				f.main[strings.ToLower(p.Name)] = true
			}
			for _, p := range m.PackagesDev {
				f.dev[strings.ToLower(p.Name)] = true
			}
			s.addLockfile(rel, state.LockfileComposer, len(m.Packages)+len(m.PackagesDev))
		} else {
			s.note("composer.lock could not be read, so PHP packages have an unknown scope")
		}
	}
	if found {
		s.composer = f
	}
}

// ---- RubyGems ----

var (
	reGem      = regexp.MustCompile(`^\s*gem\s+['"]([A-Za-z0-9._-]+)['"](.*)$`)
	reGroup    = regexp.MustCompile(`^\s*group\s+(.+?)\s+do\s*$`)
	reGemGroup = regexp.MustCompile(`groups?:\s*(\[[^\]]*\]|:[a-z_]+)`)
	reSymbols  = regexp.MustCompile(`:([a-z_]+)|['"]([a-z_]+)['"]`)
	reLockSpec = regexp.MustCompile(`^    ([A-Za-z0-9._-]+) \(`)
)

func devGroups(s string) (dev, other bool) {
	for _, m := range reSymbols.FindAllStringSubmatch(s, -1) {
		g := m[1] + m[2]
		if g == "development" || g == "test" {
			dev = true
		} else {
			other = true
		}
	}
	return dev, other
}

func analyzeGems(t tree, s *sourceFacts) {
	f := newNameFacts()
	found := false
	if rel, b, err := t.find("Gemfile"); rel != "" && err == nil {
		found = true
		depth := 0
		groupDev := false
		for _, line := range strings.Split(string(b), "\n") {
			if m := reGroup.FindStringSubmatch(line); m != nil && depth == 0 {
				dev, other := devGroups(m[1])
				groupDev = dev && !other
				depth = 1
				continue
			}
			trimmed := strings.TrimSpace(line)
			if depth > 0 && (strings.HasSuffix(trimmed, " do") || strings.HasPrefix(trimmed, "if ") || strings.HasPrefix(trimmed, "unless ")) {
				depth++
				continue
			}
			if depth > 0 && trimmed == "end" {
				depth--
				if depth == 0 {
					groupDev = false
				}
				continue
			}
			m := reGem.FindStringSubmatch(line)
			if m == nil {
				continue
			}
			name := strings.ToLower(m[1])
			f.direct[name] = true
			dev := groupDev
			if g := reGemGroup.FindStringSubmatch(m[2]); g != nil {
				d, other := devGroups(g[1])
				dev = d && !other
			}
			if dev {
				f.dev[name] = true
			} else {
				f.main[name] = true
			}
		}
		s.addLockfile(rel, state.LockfileGemfile, len(f.direct))
	}
	if rel, b, err := t.find("Gemfile.lock"); rel != "" && err == nil {
		found = true
		n := 0
		for _, line := range strings.Split(string(b), "\n") {
			if reLockSpec.MatchString(line) {
				n++
			}
		}
		s.addLockfile(rel, state.LockfileGemfile, n)
	}
	if found {
		s.gems = f
	}
}
