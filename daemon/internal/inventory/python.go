package inventory

import (
	"encoding/json"
	"io/fs"
	"path"
	"regexp"
	"strings"

	"github.com/BurntSushi/toml"

	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// jsonUnmarshal decodes data (manifests are untrusted input; errors are notes).
func jsonUnmarshal(data []byte, v any) error { return json.Unmarshal(data, v) }

var (
	rePEP503   = regexp.MustCompile(`[-_.]+`)
	rePEP508   = regexp.MustCompile(`^\s*([A-Za-z0-9][A-Za-z0-9._-]*)`)
	reDevGroup = regexp.MustCompile(`(?i)^(dev|develop|development|test|tests|testing|lint|typing|docs)$`)
)

// pyName normalises a Python package name (PEP 503).
func pyName(n string) string {
	return strings.ToLower(rePEP503.ReplaceAllString(strings.TrimSpace(n), "-"))
}

// pep508Name is the distribution name of a requirement string ("django>=5; python_version>'3'").
func pep508Name(req string) string {
	if m := rePEP508.FindStringSubmatch(req); m != nil {
		return pyName(m[1])
	}
	return ""
}

func analyzePython(t tree, s *sourceFacts) {
	f := newNameFacts()
	found := false

	if rel, b, err := t.find("pyproject.toml"); rel != "" && err == nil {
		var p struct {
			Project struct {
				Dependencies []string `toml:"dependencies"`
			} `toml:"project"`
			DependencyGroups map[string][]any `toml:"dependency-groups"`
			Tool             struct {
				Poetry struct {
					Dependencies    map[string]any `toml:"dependencies"`
					DevDependencies map[string]any `toml:"dev-dependencies"`
					Group           map[string]struct {
						Dependencies map[string]any `toml:"dependencies"`
					} `toml:"group"`
				} `toml:"poetry"`
			} `toml:"tool"`
		}
		if _, err := toml.Decode(string(b), &p); err == nil {
			found = true
			n := 0
			for _, r := range p.Project.Dependencies {
				if name := pep508Name(r); name != "" {
					f.main[name], f.direct[name] = true, true
					n++
				}
			}
			for name := range p.Tool.Poetry.Dependencies {
				if name = pyName(name); name != "python" {
					f.main[name], f.direct[name] = true, true
					n++
				}
			}
			for name := range p.Tool.Poetry.DevDependencies {
				f.dev[pyName(name)], f.direct[pyName(name)] = true, true
				n++
			}
			for g, grp := range p.Tool.Poetry.Group {
				for name := range grp.Dependencies {
					name = pyName(name)
					f.direct[name] = true
					if reDevGroup.MatchString(g) {
						f.dev[name] = true
					} else {
						f.main[name] = true
					}
					n++
				}
			}
			for g, reqs := range p.DependencyGroups {
				for _, r := range reqs {
					str, ok := r.(string) // {include-group = "…"} tables are skipped
					if !ok {
						continue
					}
					if name := pep508Name(str); name != "" {
						f.direct[name] = true
						if reDevGroup.MatchString(g) {
							f.dev[name] = true
						} else {
							f.main[name] = true
						}
						n++
					}
				}
			}
			s.addLockfile(rel, state.LockfilePyproject, n)
		} else {
			s.note("pyproject.toml could not be read")
		}
	}

	if rel, b, err := t.find("poetry.lock"); rel != "" && err == nil {
		var l struct {
			Package []struct {
				Name     string   `toml:"name"`
				Category string   `toml:"category"`
				Groups   []string `toml:"groups"`
			} `toml:"package"`
		}
		if _, err := toml.Decode(string(b), &l); err == nil {
			found = true
			for _, p := range l.Package {
				name := pyName(p.Name)
				dev := p.Category == "dev"
				if len(p.Groups) > 0 {
					dev = true
					for _, g := range p.Groups {
						if !reDevGroup.MatchString(g) {
							dev = false
						}
					}
				}
				if dev {
					f.dev[name] = true
				} else if p.Category != "" || len(p.Groups) > 0 {
					f.main[name] = true
				}
			}
			s.addLockfile(rel, state.LockfilePoetry, len(l.Package))
		} else {
			s.note("poetry.lock could not be read")
		}
	}

	if rel, b, err := t.find("uv.lock"); rel != "" && err == nil {
		if n, ok := analyzeUv(b, f); ok {
			found = true
			s.addLockfile(rel, state.LockfileUv, n)
		} else {
			s.note("uv.lock could not be read")
		}
	}

	if rel, b, err := t.find("Pipfile.lock"); rel != "" && err == nil {
		var l struct {
			Default map[string]any `json:"default"`
			Develop map[string]any `json:"develop"`
		}
		if jsonUnmarshal(b, &l) == nil {
			found = true
			for n := range l.Default {
				f.main[pyName(n)] = true
			}
			for n := range l.Develop {
				f.dev[pyName(n)] = true
			}
			s.addLockfile(rel, state.LockfilePipfile, len(l.Default)+len(l.Develop))
		} else {
			s.note("Pipfile.lock could not be read")
		}
	}

	// requirements*.txt in the app directory (and dev/test variants).
	if entries, err := fs.ReadDir(t.root.FS(), dirOrDot(t.app)); err == nil {
		for _, e := range entries {
			name := e.Name()
			if !e.Type().IsRegular() || !strings.HasPrefix(name, "requirements") || !strings.HasSuffix(name, ".txt") {
				continue
			}
			rel := join(t.app, name)
			b, ok, err := t.read(rel)
			if !ok || err != nil {
				continue
			}
			dev := strings.Contains(name, "dev") || strings.Contains(name, "test")
			n := 0
			for _, line := range strings.Split(string(b), "\n") {
				line, _, _ = strings.Cut(line, "#")
				line = strings.TrimSpace(line)
				if line == "" || strings.HasPrefix(line, "-") {
					continue
				}
				pn := pep508Name(line)
				if pn == "" {
					continue
				}
				n++
				f.direct[pn] = true
				if dev {
					f.dev[pn] = true
				} else {
					f.main[pn] = true
				}
			}
			found = true
			s.addLockfile(rel, state.LockfileRequirements, n)
		}
	}
	if found {
		s.pypi = f
	}
}

func dirOrDot(d string) string {
	if d == "" {
		return "."
	}
	return path.Clean(d)
}

// analyzeUv walks uv.lock's graph from the project's own package: packages
// reachable from its dependencies are main, those reachable only from its
// dev-dependencies groups are dev.
func analyzeUv(b []byte, f *nameFacts) (int, bool) {
	type dep struct {
		Name string `toml:"name"`
	}
	var l struct {
		Package []struct {
			Name            string           `toml:"name"`
			Source          map[string]any   `toml:"source"`
			Dependencies    []dep            `toml:"dependencies"`
			DevDependencies map[string][]dep `toml:"dev-dependencies"`
			Optional        map[string][]dep `toml:"optional-dependencies"`
		} `toml:"package"`
	}
	if _, err := toml.Decode(string(b), &l); err != nil {
		return 0, false
	}
	deps := map[string][]string{}
	var prodRoots, devRoots []string
	for _, p := range l.Package {
		name := pyName(p.Name)
		for _, d := range p.Dependencies {
			deps[name] = append(deps[name], pyName(d.Name))
		}
		for _, ds := range p.Optional {
			for _, d := range ds {
				deps[name] = append(deps[name], pyName(d.Name))
			}
		}
		_, editable := p.Source["editable"]
		_, virtual := p.Source["virtual"]
		if !editable && !virtual {
			continue
		}
		for _, d := range p.Dependencies {
			prodRoots = append(prodRoots, pyName(d.Name))
			f.direct[pyName(d.Name)] = true
		}
		for _, ds := range p.DevDependencies {
			for _, d := range ds {
				devRoots = append(devRoots, pyName(d.Name))
				f.direct[pyName(d.Name)] = true
			}
		}
	}
	walk := func(roots []string, into map[string]bool) {
		seen := map[string]bool{}
		stack := append([]string(nil), roots...)
		for len(stack) > 0 {
			n := stack[len(stack)-1]
			stack = stack[:len(stack)-1]
			if seen[n] {
				continue
			}
			seen[n], into[n] = true, true
			stack = append(stack, deps[n]...)
		}
	}
	walk(prodRoots, f.main)
	walk(devRoots, f.dev)
	return len(l.Package), true
}
