package presets

import (
	"bufio"
	"io/fs"
	"path"
	"strings"
)

// gatherServerFacts reads the facts of the server-side ecosystems (PHP is
// read with composer.json in gatherComposer).
func gatherServerFacts(fsys fs.FS, f *Facts) {
	gatherRuby(fsys, f)
	gatherElixir(fsys, f)
	gatherJVM(fsys, f)
	gatherDotNet(fsys, f)
	gatherRust(fsys, f)
	f.DockerfileWorkdir = dockerfileWorkdir(fsys)
}

// dockerfileWorkdir returns the last absolute WORKDIR of the app's
// Dockerfile (the final stage's working directory in the usual layouts).
func dockerfileWorkdir(fsys fs.FS) string {
	s, ok := readSmall(fsys, "Dockerfile")
	if !ok {
		return ""
	}
	dir := ""
	sc := bufio.NewScanner(strings.NewReader(s))
	for sc.Scan() {
		fields := strings.Fields(sc.Text())
		if len(fields) == 2 && strings.EqualFold(fields[0], "WORKDIR") && strings.HasPrefix(fields[1], "/") && !strings.ContainsAny(fields[1], "$\"'") {
			dir = path.Clean(fields[1])
		}
	}
	return dir
}
