package presets

import (
	"bufio"
	"io/fs"
	"regexp"
	"strings"
)

// RustFacts are read from Cargo.toml.
type RustFacts struct {
	// Deps are the [dependencies] crate names.
	Deps map[string]bool
}

var reCargoDep = regexp.MustCompile(`^([A-Za-z0-9_-]+)\s*=`)

func gatherRust(fsys fs.FS, f *Facts) {
	s, ok := readSmall(fsys, "Cargo.toml")
	if !ok {
		return
	}
	f.Rust.Deps = map[string]bool{}
	in := false
	sc := bufio.NewScanner(strings.NewReader(s))
	for sc.Scan() {
		line := strings.TrimSpace(sc.Text())
		if strings.HasPrefix(line, "[") {
			in = line == "[dependencies]"
			if strings.HasPrefix(line, "[dependencies.") {
				f.Rust.Deps[strings.TrimSuffix(strings.TrimPrefix(line, "[dependencies."), "]")] = true
			}
			continue
		}
		if m := reCargoDep.FindStringSubmatch(line); in && m != nil {
			f.Rust.Deps[m[1]] = true
		}
	}
}
