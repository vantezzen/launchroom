package inventory

import (
	"bytes"
	"encoding/json"
	"path"
	"regexp"
	"strings"

	"go.yaml.in/yaml/v3"

	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// npm scopes (docs/22 §3.3): the lockfile's package graph walked from the
// app's manifest (or its workspace importer): packages reachable from
// dependencies, optionalDependencies and peerDependencies are production
// packages, those reachable only from devDependencies are development ones.

// npmFacts are the npm scopes of the app.
type npmFacts struct {
	// prod and dev hold "name@version" of reachable packages.
	prod, dev map[string]bool
	// prodList is prod in walk order (bundled candidates).
	prodList []nameVersion
	// direct are names the app's manifest lists.
	direct map[string]bool
	// lockfile is false when only a manifest was found.
	lockfile bool
}

type nameVersion struct{ name, version string }

func key(name, version string) string { return name + "@" + version }

// pkgJSON is a package.json manifest.
type pkgJSON struct {
	Dependencies         map[string]string `json:"dependencies"`
	DevDependencies      map[string]string `json:"devDependencies"`
	OptionalDependencies map[string]string `json:"optionalDependencies"`
	PeerDependencies     map[string]string `json:"peerDependencies"`
}

func (p *pkgJSON) prod() map[string]string {
	out := map[string]string{}
	for _, m := range []map[string]string{p.PeerDependencies, p.OptionalDependencies, p.Dependencies} {
		for k, v := range m {
			out[k] = v
		}
	}
	return out
}

// npmGraph is a lockfile's resolved package graph.
type npmGraph struct {
	nodes     map[string]*npmNode
	prodRoots []string
	devRoots  []string
}

type npmNode struct {
	name, version string
	deps          []string // node ids
	workspace     bool
}

func newGraph() *npmGraph { return &npmGraph{nodes: map[string]*npmNode{}} }

func (g *npmGraph) packages() int {
	n := 0
	for _, x := range g.nodes {
		if !x.workspace {
			n++
		}
	}
	return n
}

// facts walks the graph from its roots.
func (g *npmGraph) facts(direct map[string]bool) *npmFacts {
	f := &npmFacts{prod: map[string]bool{}, dev: map[string]bool{}, direct: direct, lockfile: true}
	walk := func(roots []string, into map[string]bool, list *[]nameVersion) {
		seen := map[string]bool{}
		stack := append([]string(nil), roots...)
		for len(stack) > 0 {
			id := stack[0]
			stack = stack[1:]
			if seen[id] {
				continue
			}
			seen[id] = true
			n := g.nodes[id]
			if n == nil {
				continue
			}
			if !n.workspace && n.version != "" {
				k := key(n.name, n.version)
				if !into[k] {
					into[k] = true
					if list != nil {
						*list = append(*list, nameVersion{n.name, n.version})
					}
				}
			}
			stack = append(stack, n.deps...)
		}
	}
	walk(g.prodRoots, f.prod, &f.prodList)
	walk(g.devRoots, f.dev, nil)
	return f
}

func analyzeNpm(t tree, s *sourceFacts) {
	manifestRel := join(t.app, "package.json")
	b, ok, err := t.read(manifestRel)
	if !ok || err != nil {
		return
	}
	var manifest pkgJSON
	if json.Unmarshal(b, &manifest) != nil {
		s.note("package.json could not be read, so npm packages have an unknown scope")
		return
	}
	direct := map[string]bool{}
	for _, m := range []map[string]string{manifest.Dependencies, manifest.DevDependencies, manifest.OptionalDependencies, manifest.PeerDependencies} {
		for n := range m {
			direct[n] = true
		}
	}
	s.addLockfile(manifestRel, state.LockfileNpm, len(direct))

	rel, data, err := t.find("package-lock.json", "npm-shrinkwrap.json", "pnpm-lock.yaml", "yarn.lock", "bun.lock")
	if rel == "" || err != nil {
		s.note("No lockfile found, so npm packages have an unknown scope")
		s.npm = &npmFacts{prod: map[string]bool{}, dev: map[string]bool{}, direct: direct}
		return
	}
	importer := relTo(path.Dir("/" + rel)[1:], t.app)
	var g *npmGraph
	var kind state.LockfileKind
	switch path.Base(rel) {
	case "package-lock.json", "npm-shrinkwrap.json":
		g, kind = parsePackageLock(data, importer), state.LockfileNpm
	case "pnpm-lock.yaml":
		g, kind = parsePnpm(data, importer), state.LockfilePnpm
	case "yarn.lock":
		kind = state.LockfileYarn
		if bytes.Contains(data, []byte("__metadata:")) {
			g = parseYarnBerry(data, &manifest)
		} else {
			g = parseYarnV1(data, &manifest)
		}
	case "bun.lock":
		g, kind = parseBun(data, importer), state.LockfileBun
	}
	if g == nil {
		s.note("%s could not be read, so npm packages have an unknown scope", path.Base(rel))
		s.npm = &npmFacts{prod: map[string]bool{}, dev: map[string]bool{}, direct: direct}
		return
	}
	s.addLockfile(rel, kind, g.packages())
	s.npm = g.facts(direct)
}

// ---- package-lock.json (v2/v3 "packages"; v1 "dependencies") ----

func parsePackageLock(data []byte, importer string) *npmGraph {
	type entry struct {
		Name                 string            `json:"name"`
		Version              string            `json:"version"`
		Resolved             string            `json:"resolved"`
		Link                 bool              `json:"link"`
		Dev                  bool              `json:"dev"`
		Dependencies         map[string]string `json:"dependencies"`
		DevDependencies      map[string]string `json:"devDependencies"`
		OptionalDependencies map[string]string `json:"optionalDependencies"`
		PeerDependencies     map[string]string `json:"peerDependencies"`
	}
	var l struct {
		Packages     map[string]entry `json:"packages"`
		Dependencies map[string]json.RawMessage
	}
	if json.Unmarshal(data, &l) != nil {
		return nil
	}
	g := newGraph()
	if l.Packages == nil {
		return parsePackageLockV1(data)
	}
	// Node's resolution: from the package at p, a dependency d is
	// p/node_modules/d, else the same in each ancestor, else node_modules/d.
	resolve := func(from, dep string) string {
		p := from
		for {
			k := join(p, "node_modules/"+dep)
			if e, ok := l.Packages[k]; ok {
				if e.Link && e.Resolved != "" {
					return e.Resolved
				}
				return k
			}
			if p == "" {
				return ""
			}
			i := strings.LastIndex(p, "/node_modules/")
			switch {
			case i >= 0:
				p = p[:i]
			case strings.HasPrefix(p, "node_modules/"):
				p = ""
			default:
				p = "" // a workspace folder: its parent is the root
			}
		}
	}
	nameOf := func(k string, e entry) string {
		if i := strings.LastIndex(k, "node_modules/"); i >= 0 {
			return k[i+len("node_modules/"):]
		}
		return e.Name
	}
	for k, e := range l.Packages {
		if e.Link {
			continue
		}
		n := &npmNode{name: nameOf(k, e), version: e.Version, workspace: k == "" || !strings.Contains(k, "node_modules/")}
		for _, m := range []map[string]string{e.Dependencies, e.OptionalDependencies, e.PeerDependencies} {
			for d := range m {
				if id := resolve(k, d); id != "" {
					n.deps = append(n.deps, id)
				}
			}
		}
		g.nodes[k] = n
	}
	root, ok := l.Packages[importer]
	if !ok {
		return nil
	}
	for _, m := range []map[string]string{root.Dependencies, root.OptionalDependencies, root.PeerDependencies} {
		for d := range m {
			if id := resolve(importer, d); id != "" {
				g.prodRoots = append(g.prodRoots, id)
			}
		}
	}
	for d := range root.DevDependencies {
		if id := resolve(importer, d); id != "" {
			g.devRoots = append(g.devRoots, id)
		}
	}
	return g
}

// parsePackageLockV1 reads lockfileVersion 1 (nested "dependencies" with
// npm's own dev flags; no graph needed).
func parsePackageLockV1(data []byte) *npmGraph {
	type dep struct {
		Version      string          `json:"version"`
		Dev          bool            `json:"dev"`
		Dependencies json.RawMessage `json:"dependencies"`
	}
	var root struct {
		Dependencies map[string]dep `json:"dependencies"`
	}
	if json.Unmarshal(data, &root) != nil || root.Dependencies == nil {
		return nil
	}
	g := newGraph()
	var add func(prefix string, deps map[string]dep, depth int)
	add = func(prefix string, deps map[string]dep, depth int) {
		if depth > 64 {
			return
		}
		for name, d := range deps {
			id := prefix + "/" + name
			g.nodes[id] = &npmNode{name: name, version: d.Version}
			if d.Dev {
				g.devRoots = append(g.devRoots, id)
			} else {
				g.prodRoots = append(g.prodRoots, id)
			}
			var nested map[string]dep
			if len(d.Dependencies) > 0 && json.Unmarshal(d.Dependencies, &nested) == nil {
				add(id, nested, depth+1)
			}
		}
	}
	add("", root.Dependencies, 0)
	return g
}

// ---- pnpm-lock.yaml (lockfileVersion 6–9) ----

func parsePnpm(data []byte, importer string) *npmGraph {
	type importerDeps map[string]yaml.Node
	var l struct {
		LockfileVersion any `yaml:"lockfileVersion"`
		Importers       map[string]struct {
			Dependencies         importerDeps `yaml:"dependencies"`
			DevDependencies      importerDeps `yaml:"devDependencies"`
			OptionalDependencies importerDeps `yaml:"optionalDependencies"`
		} `yaml:"importers"`
		// Root-level dependencies (single-project lockfiles without importers).
		Dependencies         importerDeps `yaml:"dependencies"`
		DevDependencies      importerDeps `yaml:"devDependencies"`
		OptionalDependencies importerDeps `yaml:"optionalDependencies"`
		Packages             map[string]struct {
			Dependencies         map[string]string `yaml:"dependencies"`
			OptionalDependencies map[string]string `yaml:"optionalDependencies"`
		} `yaml:"packages"`
		Snapshots map[string]struct {
			Dependencies         map[string]string `yaml:"dependencies"`
			OptionalDependencies map[string]string `yaml:"optionalDependencies"`
		} `yaml:"snapshots"`
	}
	if yaml.Unmarshal(data, &l) != nil {
		return nil
	}
	v9 := l.Snapshots != nil
	// id builds a node id from a dependency name and its version reference.
	id := func(name, ref string) string {
		if strings.HasPrefix(ref, "link:") || strings.HasPrefix(ref, "file:") || strings.HasPrefix(ref, "workspace:") {
			return ""
		}
		full := name + "@" + ref
		if !startsVersion(ref) {
			full = strings.TrimPrefix(ref, "/") // an alias: the reference names the package
		}
		if v9 {
			return full
		}
		return "/" + full
	}
	g := newGraph()
	add := func(k string, deps ...map[string]string) {
		name, version := splitNameVersion(strings.TrimPrefix(k, "/"))
		n := &npmNode{name: name, version: stripPeers(version)}
		for _, m := range deps {
			for d, ref := range m {
				if x := id(d, ref); x != "" {
					n.deps = append(n.deps, x)
				}
			}
		}
		g.nodes[k] = n
	}
	if v9 {
		for k, p := range l.Snapshots {
			add(k, p.Dependencies, p.OptionalDependencies)
		}
	} else {
		for k, p := range l.Packages {
			add(k, p.Dependencies, p.OptionalDependencies)
		}
	}
	imp, ok := l.Importers[orDot(importer)]
	if !ok {
		if l.Importers != nil {
			return nil
		}
		imp.Dependencies, imp.DevDependencies, imp.OptionalDependencies = l.Dependencies, l.DevDependencies, l.OptionalDependencies
	}
	ref := func(n yaml.Node) string {
		// v6+: {specifier, version}; older: a plain version.
		if n.Kind == yaml.MappingNode {
			var m struct {
				Version string `yaml:"version"`
			}
			_ = n.Decode(&m)
			return m.Version
		}
		return n.Value
	}
	for _, m := range []importerDeps{imp.Dependencies, imp.OptionalDependencies} {
		for d, n := range m {
			if x := id(d, ref(n)); x != "" {
				g.prodRoots = append(g.prodRoots, x)
			}
		}
	}
	for d, n := range imp.DevDependencies {
		if x := id(d, ref(n)); x != "" {
			g.devRoots = append(g.devRoots, x)
		}
	}
	return g
}

func orDot(s string) string {
	if s == "" {
		return "."
	}
	return s
}

func startsVersion(s string) bool { return s != "" && s[0] >= '0' && s[0] <= '9' }

// splitNameVersion splits "name@1.2.3" and "@scope/name@1.2.3(peer@1)".
func splitNameVersion(s string) (string, string) {
	base, _, _ := strings.Cut(s, "(")
	i := strings.LastIndexByte(base, '@')
	if i <= 0 {
		return base, ""
	}
	return s[:i], s[i+1:]
}

func stripPeers(v string) string {
	v, _, _ = strings.Cut(v, "(")
	return v
}

// ---- yarn.lock v1 ----

var reYarnDep = regexp.MustCompile(`^\s+("?)(@?[^"\s]+)("?)\s+"?([^"]*)"?\s*$`)

func parseYarnV1(data []byte, manifest *pkgJSON) *npmGraph {
	type entry struct {
		version string
		deps    []string // descriptors
	}
	desc := map[string]*entry{}
	var cur *entry
	inDeps := false
	var all []*entry
	for _, line := range strings.Split(string(data), "\n") {
		if strings.TrimSpace(line) == "" || strings.HasPrefix(line, "#") {
			continue
		}
		if !strings.HasPrefix(line, " ") {
			cur = &entry{}
			all = append(all, cur)
			inDeps = false
			for _, d := range strings.Split(strings.TrimSuffix(strings.TrimSpace(line), ":"), ",") {
				desc[strings.Trim(strings.TrimSpace(d), `"`)] = cur
			}
			continue
		}
		if cur == nil {
			continue
		}
		trimmed := strings.TrimSpace(line)
		indent := len(line) - len(strings.TrimLeft(line, " "))
		switch {
		case indent == 2 && strings.HasPrefix(trimmed, "version "):
			cur.version = strings.Trim(strings.TrimPrefix(trimmed, "version "), `"`)
			inDeps = false
		case indent == 2 && (trimmed == "dependencies:" || trimmed == "optionalDependencies:"):
			inDeps = true
		case indent == 2:
			inDeps = false
		case indent >= 4 && inDeps:
			if m := reYarnDep.FindStringSubmatch(line); m != nil {
				cur.deps = append(cur.deps, m[2]+"@"+m[4])
			}
		}
	}
	if len(all) == 0 {
		return nil
	}
	g := newGraph()
	ids := map[*entry]string{}
	for d, e := range desc {
		name, _ := splitDescriptor(d)
		if _, ok := ids[e]; !ok {
			ids[e] = key(name, e.version)
			g.nodes[ids[e]] = &npmNode{name: name, version: e.version}
		}
	}
	for _, e := range all {
		n := g.nodes[ids[e]]
		if n == nil {
			continue
		}
		for _, d := range e.deps {
			if t, ok := desc[d]; ok {
				n.deps = append(n.deps, ids[t])
			}
		}
	}
	for name, rng := range manifest.prod() {
		if e, ok := desc[name+"@"+rng]; ok {
			g.prodRoots = append(g.prodRoots, ids[e])
		}
	}
	for name, rng := range manifest.DevDependencies {
		if e, ok := desc[name+"@"+rng]; ok {
			g.devRoots = append(g.devRoots, ids[e])
		}
	}
	return g
}

// splitDescriptor splits "name@range" (scoped names start with "@").
func splitDescriptor(d string) (string, string) {
	i := strings.IndexByte(d[min(1, len(d)):], '@')
	if i < 0 {
		return d, ""
	}
	i++
	return d[:i], d[i+1:]
}

// ---- yarn.lock (Berry, v2+) ----

func parseYarnBerry(data []byte, manifest *pkgJSON) *npmGraph {
	var l map[string]struct {
		Version      string            `yaml:"version"`
		Resolution   string            `yaml:"resolution"`
		Dependencies map[string]string `yaml:"dependencies"`
		Optional     map[string]string `yaml:"optionalDependencies"`
	}
	if yaml.Unmarshal(data, &l) != nil {
		return nil
	}
	g := newGraph()
	desc := map[string]string{} // descriptor → resolution
	for k, e := range l {
		if k == "__metadata" || e.Resolution == "" {
			continue
		}
		name, _ := splitDescriptor(e.Resolution)
		g.nodes[e.Resolution] = &npmNode{name: name, version: e.Version, workspace: strings.Contains(e.Resolution, "@workspace:")}
		for _, d := range strings.Split(k, ",") {
			desc[strings.TrimSpace(d)] = e.Resolution
		}
	}
	berry := func(name, rng string) string {
		if !strings.Contains(rng, ":") {
			rng = "npm:" + rng
		}
		return desc[name+"@"+rng]
	}
	for k, e := range l {
		n := g.nodes[e.Resolution]
		if k == "__metadata" || n == nil {
			continue
		}
		for _, m := range []map[string]string{e.Dependencies, e.Optional} {
			for d, r := range m {
				if id := berry(d, r); id != "" {
					n.deps = append(n.deps, id)
				}
			}
		}
	}
	for name, rng := range manifest.prod() {
		if id := berry(name, rng); id != "" {
			g.prodRoots = append(g.prodRoots, id)
		}
	}
	for name, rng := range manifest.DevDependencies {
		if id := berry(name, rng); id != "" {
			g.devRoots = append(g.devRoots, id)
		}
	}
	return g
}

// ---- bun.lock (text, JSON with trailing commas) ----

func parseBun(data []byte, importer string) *npmGraph {
	var l struct {
		Workspaces map[string]pkgJSON           `json:"workspaces"`
		Packages   map[string][]json.RawMessage `json:"packages"`
	}
	if json.Unmarshal(stripTrailingCommas(data), &l) != nil {
		return nil
	}
	g := newGraph()
	deps := map[string]map[string]string{}
	for k, arr := range l.Packages {
		if len(arr) == 0 {
			continue
		}
		var ident string
		if json.Unmarshal(arr[0], &ident) != nil {
			continue
		}
		name, version := splitNameVersion(ident)
		n := &npmNode{name: name, version: version, workspace: strings.HasPrefix(version, "workspace:")}
		g.nodes[k] = n
		for _, raw := range arr[1:] {
			var meta struct {
				Dependencies         map[string]string `json:"dependencies"`
				OptionalDependencies map[string]string `json:"optionalDependencies"`
				PeerDependencies     map[string]string `json:"peerDependencies"`
			}
			if json.Unmarshal(raw, &meta) == nil && (meta.Dependencies != nil || meta.OptionalDependencies != nil || meta.PeerDependencies != nil) {
				m := map[string]string{}
				for _, x := range []map[string]string{meta.Dependencies, meta.OptionalDependencies, meta.PeerDependencies} {
					for d, r := range x {
						m[d] = r
					}
				}
				deps[k] = m
			}
		}
	}
	// bun's keys nest like node_modules: "parent/dep", scoped names keep their "/".
	resolve := func(from, dep string) string {
		segs := bunSegments(from)
		for i := len(segs); i >= 0; i-- {
			k := strings.Join(append(segs[:i:i], dep), "/")
			if _, ok := g.nodes[k]; ok {
				return k
			}
		}
		return ""
	}
	for k, m := range deps {
		for d := range m {
			if id := resolve(k, d); id != "" {
				g.nodes[k].deps = append(g.nodes[k].deps, id)
			}
		}
	}
	ws, ok := l.Workspaces[importer]
	if !ok {
		return nil
	}
	for d := range ws.prod() {
		if id := resolve("", d); id != "" {
			g.prodRoots = append(g.prodRoots, id)
		}
	}
	for d := range ws.DevDependencies {
		if id := resolve("", d); id != "" {
			g.devRoots = append(g.devRoots, id)
		}
	}
	return g
}

func stripTrailingCommas(b []byte) []byte {
	// Only outside strings: walk and copy, dropping commas before } or ].
	var out bytes.Buffer
	inStr, esc := false, false
	for i := 0; i < len(b); i++ {
		c := b[i]
		switch {
		case inStr:
			if esc {
				esc = false
			} else if c == '\\' {
				esc = true
			} else if c == '"' {
				inStr = false
			}
		case c == '"':
			inStr = true
		case c == ',':
			j := i + 1
			for j < len(b) && (b[j] == ' ' || b[j] == '\n' || b[j] == '\r' || b[j] == '\t') {
				j++
			}
			if j < len(b) && (b[j] == '}' || b[j] == ']') {
				continue
			}
		}
		out.WriteByte(c)
	}
	return out.Bytes()
}

// bunSegments splits a bun package key into package names.
func bunSegments(k string) []string {
	if k == "" {
		return nil
	}
	parts := strings.Split(k, "/")
	var out []string
	for i := 0; i < len(parts); i++ {
		if strings.HasPrefix(parts[i], "@") && i+1 < len(parts) {
			out = append(out, parts[i]+"/"+parts[i+1])
			i++
			continue
		}
		out = append(out, parts[i])
	}
	return out
}
