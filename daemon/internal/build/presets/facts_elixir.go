package presets

import (
	"io/fs"
	"path"
	"regexp"
	"strings"
)

// ElixirFacts are read from mix.exs and lib/.
type ElixirFacts struct {
	// App is the OTP app name (mix.exs `app: :my_app`), also the release name.
	App string
	// Phoenix and Ecto are set when mix.exs depends on them.
	Phoenix bool
	Ecto    bool
	// ReleaseModule is the module with a migrate/0 function for releases
	// ("MyApp.Release", from `mix phx.gen.release`), if any.
	ReleaseModule string
}

var (
	reMixApp      = regexp.MustCompile(`\bapp:\s*:([a-z_][a-z0-9_]*)`)
	reMixDep      = regexp.MustCompile(`\{\s*:([a-z_][a-z0-9_]*)\s*,`)
	reReleaseMod  = regexp.MustCompile(`defmodule\s+([A-Z][A-Za-z0-9_]*(?:\.[A-Z][A-Za-z0-9_]*)*\.Release)\s+do`)
	reMigrateFunc = regexp.MustCompile(`(?m)^\s*def\s+migrate\b`)
)

func gatherElixir(fsys fs.FS, f *Facts) {
	s, ok := readSmall(fsys, "mix.exs")
	if !ok {
		return
	}
	e := ElixirFacts{}
	if m := reMixApp.FindStringSubmatch(s); m != nil {
		e.App = m[1]
	}
	for _, m := range reMixDep.FindAllStringSubmatch(s, -1) {
		switch m[1] {
		case "phoenix":
			e.Phoenix = true
		case "ecto_sql":
			e.Ecto = true
		}
	}
	files := 0
	_ = fs.WalkDir(fsys, "lib", func(p string, d fs.DirEntry, err error) error {
		if err != nil {
			return nil //nolint:nilerr // best effort
		}
		if d.IsDir() {
			if strings.Count(p, "/") >= 3 {
				return fs.SkipDir
			}
			return nil
		}
		if path.Base(p) != "release.ex" || !d.Type().IsRegular() {
			return nil
		}
		if files++; files > 20 {
			return fs.SkipAll
		}
		if src, ok := readSmall(fsys, p); ok && reMigrateFunc.MatchString(src) {
			if m := reReleaseMod.FindStringSubmatch(src); m != nil {
				e.ReleaseModule = m[1]
				return fs.SkipAll
			}
		}
		return nil
	})
	f.Elixir = e
}
