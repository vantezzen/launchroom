package presets

import (
	"slices"
	"testing"
	"testing/fstest"

	"github.com/vantezzen/launchroom/daemon/internal/state"
)

func commandNames(r *Result) []string {
	var out []string
	for _, c := range r.Commands {
		out = append(out, string(c.Name))
	}
	return out
}

func findCommand(r *Result, name string) *state.CommandSuggestion {
	for i := range r.Commands {
		if string(r.Commands[i].Name) == name {
			return &r.Commands[i]
		}
	}
	return nil
}

func jsApp(pkg string, extra ...string) Facts {
	fsys := fstest.MapFS{"package.json": {Data: []byte(pkg)}, "server.js": {Data: []byte("app.listen(process.env.PORT)")}}
	for _, name := range extra {
		fsys[name] = &fstest.MapFile{Data: []byte("x")}
	}
	return Gather(fsys, nil)
}

func TestCommandsTable(t *testing.T) {
	php := Info{Provider: "php", Metadata: map[string]string{"phpFramework": "laravel"}}
	node := Info{Provider: "node", Packages: map[string]string{"node": "22.11.0"}}
	noBinRails := func(f Facts) Facts { f.Ruby.BinRails = false; return f }
	noDoctrine := func(f Facts) Facts {
		delete(f.ComposerRequire, "doctrine/doctrine-migrations-bundle")
		return f
	}
	cases := []struct {
		name    string
		facts   func(t *testing.T) Facts
		info    Info
		runtime bool
		want    []string
		args    map[string][]string
		danger  []string
	}{
		{
			name: "laravel", facts: func(t *testing.T) Facts { return repo(t, "laravel") }, info: php,
			want: []string{"about", "migrate-status", "migrate", "seed", "cache-clear", "queue-restart", "queue-retry"},
			args: map[string][]string{
				"migrate": {"php", "artisan", "migrate", "--force"}, "seed": {"php", "artisan", "db:seed", "--force"},
				"queue-retry": {"php", "artisan", "queue:retry", "all"}, "about": {"php", "artisan", "about"},
			},
			danger: []string{"migrate", "seed", "queue-retry"},
		},
		{
			// php artisan runs from the Dockerfile's working directory too.
			name: "laravel dockerfile", facts: func(t *testing.T) Facts { return repo(t, "laravel") }, runtime: true,
			want: []string{"about", "migrate-status", "migrate", "seed", "cache-clear", "queue-restart", "queue-retry"},
		},
		{
			name: "symfony with doctrine migrations", facts: func(t *testing.T) Facts { return repo(t, "symfony") },
			info: Info{Provider: "php", Metadata: map[string]string{"phpFramework": "symfony"}},
			want: []string{"migrate-status", "migrate", "cache-clear"},
			args: map[string][]string{
				"migrate":     {"php", "bin/console", "doctrine:migrations:migrate", "--no-interaction", "--allow-no-migration"},
				"cache-clear": {"php", "bin/console", "cache:pool:clear", "cache.global_clearer", "--no-interaction"},
			},
			danger: []string{"migrate"},
		},
		{
			name: "symfony without migrations", facts: func(t *testing.T) Facts { return noDoctrine(repo(t, "symfony")) },
			info: Info{Provider: "php", Metadata: map[string]string{"phpFramework": "symfony"}},
			want: []string{"cache-clear"},
		},
		{
			name: "rails bin/rails", facts: func(t *testing.T) Facts { return repo(t, "rails80") }, info: Info{Provider: "ruby"},
			want:   []string{"about", "migrate-status", "migrate", "seed"},
			args:   map[string][]string{"migrate": {"bin/rails", "db:migrate"}, "migrate-status": {"bin/rails", "db:migrate:status"}},
			danger: []string{"migrate", "seed"},
		},
		{
			name: "rails without bin/rails", facts: func(t *testing.T) Facts { return noBinRails(repo(t, "rails70")) }, info: Info{Provider: "ruby"},
			want: []string{"about", "migrate-status", "migrate", "seed"},
			args: map[string][]string{"seed": {"bundle", "exec", "rails", "db:seed"}},
		},
		{
			name: "rails dockerfile", facts: func(t *testing.T) Facts { return repo(t, "rails80") }, runtime: true,
			want: []string{"about", "migrate-status", "migrate", "seed"},
		},
		{
			name: "django", facts: func(t *testing.T) Facts { return Gather(djangoFS(""), nil) }, info: Info{Provider: "python"},
			want: []string{"migrate-status", "migrate", "check-deploy", "clear-sessions"},
			args: map[string][]string{
				"migrate":        {"python", "manage.py", "migrate", "--noinput"},
				"migrate-status": {"python", "manage.py", "showmigrations"},
				"check-deploy":   {"python", "manage.py", "check", "--deploy"},
				"clear-sessions": {"python", "manage.py", "clearsessions"},
			},
			danger: []string{"migrate"},
		},
		{
			name: "phoenix release module", facts: func(t *testing.T) Facts { return repo(t, "phoenix") }, info: Info{Provider: "elixir"},
			want:   []string{"migrate"},
			args:   map[string][]string{"migrate": {"/app/_build/prod/rel/hello/bin/hello", "eval", "Hello.Release.migrate"}},
			danger: []string{"migrate"},
		},
		{name: "phoenix without release module", facts: func(t *testing.T) Facts { return repo(t, "phoenix-norelease") }, info: Info{Provider: "elixir"}},
		{
			// The release binary's path is Railpack's.
			name: "phoenix dockerfile", facts: func(t *testing.T) Facts { return repo(t, "phoenix") }, runtime: true,
		},
		{
			name: "prisma in devDependencies",
			facts: func(*testing.T) Facts {
				return jsApp(`{"dependencies":{"express":"5","@prisma/client":"6"},"devDependencies":{"prisma":"6"}}`)
			},
			info: node, want: []string{"prisma-migrate-status", "prisma-migrate"},
			args: map[string][]string{
				"prisma-migrate-status": {"npx", "--no-install", "prisma", "migrate", "status"},
				"prisma-migrate":        {"npx", "--no-install", "prisma", "migrate", "deploy"},
			},
			danger: []string{"prisma-migrate"},
		},
		{
			name: "prisma in devDependencies, pruned",
			facts: func(*testing.T) Facts {
				return jsApp(`{"dependencies":{"express":"5"},"devDependencies":{"prisma":"6"}}`)
			},
			info: Info{Provider: "node", Packages: node.Packages, PruneDeps: true},
		},
		{
			name:  "prisma in dependencies, pruned",
			facts: func(*testing.T) Facts { return jsApp(`{"dependencies":{"express":"5","prisma":"6"}}`) },
			info:  Info{Provider: "node", Packages: node.Packages, PruneDeps: true},
			want:  []string{"prisma-migrate-status", "prisma-migrate"},
		},
		{
			// @prisma/client has no prisma binary.
			name:  "prisma client only",
			facts: func(*testing.T) Facts { return jsApp(`{"dependencies":{"express":"5","@prisma/client":"6"}}`) }, info: node,
		},
		{
			name: "drizzle",
			facts: func(*testing.T) Facts {
				return jsApp(`{"dependencies":{"hono":"4"},"devDependencies":{"drizzle-kit":"0.31"}}`)
			},
			info: node, want: []string{"drizzle-migrate"},
			args:   map[string][]string{"drizzle-migrate": {"npx", "--no-install", "drizzle-kit", "migrate"}},
			danger: []string{"drizzle-migrate"},
		},
		{
			name: "next standalone keeps node_modules",
			facts: func(*testing.T) Facts {
				return Gather(fstest.MapFS{
					"package.json":   {Data: []byte(`{"dependencies":{"next":"14.2.0","prisma":"6"}}`)},
					"next.config.js": {Data: []byte(`module.exports = { output: 'standalone' }`)},
				}, nil)
			},
			info: node, want: []string{"prisma-migrate-status", "prisma-migrate"},
		},
		{
			// The adapter's image keeps only .next/standalone.
			name: "next adapter drops node_modules",
			facts: func(*testing.T) Facts {
				return Gather(fstest.MapFS{"package.json": {Data: []byte(`{"dependencies":{"next":"16.2.1","prisma":"6"}}`)}}, nil)
			},
			info: node,
		},
		{
			name:    "js dockerfile",
			facts:   func(*testing.T) Facts { return jsApp(`{"dependencies":{"express":"5","prisma":"6"}}`) },
			runtime: true,
		},
		{
			name:  "yarn plug'n'play",
			facts: func(*testing.T) Facts { return jsApp(`{"dependencies":{"express":"5","prisma":"6"}}`, ".pnp.cjs") }, info: node,
		},
		{
			name:  "bun without node",
			facts: func(*testing.T) Facts { return jsApp(`{"dependencies":{"express":"5","prisma":"6"}}`) },
			info:  Info{Provider: "node", Packages: map[string]string{"bun": "1.2.0"}},
		},
	}
	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			f := tc.facts(t)
			var r *Result
			var ok bool
			if tc.runtime {
				r, ok = ResolveRuntime(f, "", nil)
			} else {
				r, ok = Resolve(f, tc.info, "", nil)
			}
			if !ok || r == nil {
				t.Fatal("no preset matched")
			}
			validSummary(t, r)
			if got := commandNames(r); !slices.Equal(got, tc.want) {
				t.Fatalf("commands = %v, want %v", got, tc.want)
			}
			for name, args := range tc.args {
				if c := findCommand(r, name); c == nil || !slices.Equal(c.Args, args) {
					t.Fatalf("%s = %+v, want args %v", name, c, args)
				}
			}
			for _, c := range r.Commands {
				if c.Danger != slices.Contains(tc.danger, string(c.Name)) && tc.danger != nil {
					t.Fatalf("%s danger = %v", c.Name, c.Danger)
				}
				if c.Label == "" || c.Description == "" {
					t.Fatalf("%s has no copy: %+v", c.Name, c)
				}
			}
		})
	}
}

// Commands are not decisions: removing the release command keeps them.
func TestCommandsIgnoreRemovedDecisions(t *testing.T) {
	r, _ := Resolve(repo(t, "laravel"), Info{Provider: "php"}, "", []string{"release-migrations"})
	if r.ReleaseCommand != nil || findCommand(r, "migrate") == nil {
		t.Fatalf("release %v commands %v", r.ReleaseCommand, commandNames(r))
	}
}
