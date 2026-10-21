// Package gitignore answers "would git ignore this file?" well enough to
// warn before writing secrets into a repository (`env pull`). It reads the
// .gitignore files between the file and the repository root plus
// .git/info/exclude; it supports negation, anchoring, directory patterns
// and `**`, not the global excludes file.
package gitignore

import (
	"bufio"
	"os"
	"path"
	"path/filepath"
	"strings"
)

// Result describes a file's relation to git.
type Result struct {
	InRepo  bool   // a .git directory exists above the file
	Ignored bool   // some rule ignores it
	Root    string // repository root, when InRepo
}

// Check looks at file (need not exist).
func Check(file string) (Result, error) {
	abs, err := filepath.Abs(file)
	if err != nil {
		return Result{}, err
	}
	dir := filepath.Dir(abs)
	var dirs []string
	root := ""
	for d := dir; ; {
		dirs = append(dirs, d)
		if st, err := os.Stat(filepath.Join(d, ".git")); err == nil && (st.IsDir() || st.Mode().IsRegular()) {
			root = d
			break
		}
		parent := filepath.Dir(d)
		if parent == d {
			break
		}
		d = parent
	}
	if root == "" {
		return Result{}, nil
	}
	res := Result{InRepo: true, Root: root}
	// Rules from the root down; later (deeper) rules win, like git.
	type ruleSet struct {
		base  string
		rules []rule
	}
	var sets []ruleSet
	if rules := readRules(filepath.Join(root, ".git", "info", "exclude")); rules != nil {
		sets = append(sets, ruleSet{root, rules})
	}
	for i := len(dirs) - 1; i >= 0; i-- {
		if rules := readRules(filepath.Join(dirs[i], ".gitignore")); rules != nil {
			sets = append(sets, ruleSet{dirs[i], rules})
		}
	}
	for _, s := range sets {
		rel, err := filepath.Rel(s.base, abs)
		if err != nil {
			continue
		}
		rel = filepath.ToSlash(rel)
		for _, r := range s.rules {
			if r.matches(rel) {
				res.Ignored = !r.negate
			}
		}
	}
	return res, nil
}

type rule struct {
	pattern  string
	negate   bool
	anchored bool // contains a slash (other than trailing): relative to the base
	dirOnly  bool
}

func readRules(file string) []rule {
	f, err := os.Open(file) // #nosec G304 -- .gitignore files of the user's own repository
	if err != nil {
		return nil
	}
	defer func() { _ = f.Close() }()
	var rules []rule
	s := bufio.NewScanner(f)
	for s.Scan() {
		if r, ok := ParseRule(s.Text()); ok {
			rules = append(rules, r)
		}
	}
	if rules == nil {
		rules = []rule{}
	}
	return rules
}

// ParseRule parses one .gitignore line.
func ParseRule(line string) (rule, bool) {
	line = strings.TrimRight(strings.TrimSuffix(line, "\r"), " ")
	if line == "" || strings.HasPrefix(line, "#") {
		return rule{}, false
	}
	var r rule
	if strings.HasPrefix(line, "!") {
		r.negate = true
		line = line[1:]
	} else if strings.HasPrefix(line, `\`) {
		line = line[1:]
	}
	if strings.HasSuffix(line, "/") {
		r.dirOnly = true
		line = strings.TrimRight(line, "/")
	}
	if strings.Contains(line, "/") {
		r.anchored = true
		line = strings.TrimPrefix(line, "/")
	}
	r.pattern = line
	return r, line != ""
}

// matches tests rel (slash-separated, relative to the rule's base). A
// directory rule matches when one of rel's parent directories matches.
func (r rule) matches(rel string) bool {
	parts := strings.Split(rel, "/")
	// Candidates: every parent directory prefix, and the file itself
	// unless the rule only matches directories.
	for i := 1; i <= len(parts); i++ {
		isFile := i == len(parts)
		if isFile && r.dirOnly {
			continue
		}
		candidate := strings.Join(parts[:i], "/")
		if r.anchored {
			if globMatch(r.pattern, candidate) {
				return true
			}
		} else if globMatch(r.pattern, parts[i-1]) {
			return true
		}
	}
	return false
}

// globMatch is path.Match with `**` matching any number of components.
func globMatch(pattern, name string) bool {
	if !strings.Contains(pattern, "**") {
		ok, _ := path.Match(pattern, name)
		return ok
	}
	pp := strings.Split(pattern, "/")
	np := strings.Split(name, "/")
	return matchParts(pp, np)
}

func matchParts(pp, np []string) bool {
	if len(pp) == 0 {
		return len(np) == 0
	}
	if pp[0] == "**" {
		for i := 0; i <= len(np); i++ {
			if matchParts(pp[1:], np[i:]) {
				return true
			}
		}
		return false
	}
	if len(np) == 0 {
		return false
	}
	if ok, _ := path.Match(pp[0], np[0]); !ok {
		return false
	}
	return matchParts(pp[1:], np[1:])
}
