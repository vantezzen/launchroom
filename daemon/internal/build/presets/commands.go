package presets

import (
	"slices"

	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// Framework commands (D105): the standard one-off commands a preset
// suggests for the dashboard's Commands tab. They run as run_once tasks in
// the live deployment's image, from its working directory, so a preset
// lists only commands whose argv works in the image it describes (Railpack's
// or the app's own Dockerfile) and that run without input. Commands that
// write files (config:cache, optimize) are left out: a one-off container is
// thrown away, so they would have no effect on the running replicas.

func command(name, label, description string, danger bool, args ...string) state.CommandSuggestion {
	return state.CommandSuggestion{Name: state.Slug(name), Label: label, Description: description, Args: args, Danger: danger}
}

func laravelCommands() []state.CommandSuggestion {
	artisan := func(args ...string) []string { return append([]string{"php", "artisan"}, args...) }
	return []state.CommandSuggestion{
		command("about", "Show app details", "Shows the Laravel and PHP versions, the environment and the drivers the app uses.", false, artisan("about")...),
		command("migrate-status", "Show migration status", "Lists every migration and whether it has run.", false, artisan("migrate:status")...),
		command("migrate", "Run migrations", "Runs the migrations that haven't run yet.", true, artisan("migrate", "--force")...),
		command("seed", "Seed the database", "Runs the database seeders, which add rows to your database.", true, artisan("db:seed", "--force")...),
		command("cache-clear", "Clear the cache", "Clears the app's cache store. A file cache lives in each container, so this only helps with a shared store such as Redis or the database.", false, artisan("cache:clear")...),
		command("queue-restart", "Restart queue workers", "Tells the queue workers to restart once they finish their current job.", false, artisan("queue:restart")...),
		command("queue-retry", "Retry failed jobs", "Puts every failed job back on the queue.", true, artisan("queue:retry", "all")...),
	}
}

func symfonyCommands(migrate []string) []state.CommandSuggestion {
	var out []state.CommandSuggestion
	if migrate != nil {
		out = append(out,
			command("migrate-status", "Show migration status", "Shows which Doctrine migrations have run and which are new.", false,
				"php", "bin/console", "doctrine:migrations:status", "--no-interaction"),
			state.CommandSuggestion{Name: "migrate", Label: "Run migrations", Description: "Runs the Doctrine migrations that haven't run yet.", Args: slices.Clone(migrate), Danger: true},
		)
	}
	return append(out, command("cache-clear", "Clear the cache", "Clears the app's cache pools. Pools kept in files live in each container, so this only helps with a shared store such as Redis.", false,
		"php", "bin/console", "cache:pool:clear", "cache.global_clearer", "--no-interaction"))
}

func railsCommands(railsCmd []string) []state.CommandSuggestion {
	rails := func(args ...string) []string { return append(slices.Clone(railsCmd), args...) }
	return []state.CommandSuggestion{
		command("about", "Show app details", "Shows the Rails, Ruby and database adapter versions and the environment.", false, rails("about")...),
		command("migrate-status", "Show migration status", "Lists every migration and whether it has run.", false, rails("db:migrate:status")...),
		command("migrate", "Run migrations", "Runs the migrations that haven't run yet.", true, rails("db:migrate")...),
		command("seed", "Seed the database", "Runs db/seeds.rb, which adds rows to your database.", true, rails("db:seed")...),
	}
}

func djangoCommands() []state.CommandSuggestion {
	manage := func(args ...string) []string { return append([]string{"python", "manage.py"}, args...) }
	return []state.CommandSuggestion{
		command("migrate-status", "Show migration status", "Lists the migrations of every app and whether they have run.", false, manage("showmigrations")...),
		command("migrate", "Run migrations", "Runs the migrations that haven't run yet.", true, manage("migrate", "--noinput")...),
		command("check-deploy", "Check production settings", "Runs Django's system checks with the checks for production settings.", false, manage("check", "--deploy")...),
		command("clear-sessions", "Remove expired sessions", "Deletes expired sessions from the session store.", false, manage("clearsessions")...),
	}
}

func phoenixCommands(migrate []string) []state.CommandSuggestion {
	return []state.CommandSuggestion{{
		Name: "migrate", Label: "Run migrations", Description: "Runs the migrations that haven't run yet, as the release command does.",
		Args: slices.Clone(migrate), Danger: true,
	}}
}

// jsCommands are the database migration commands of JavaScript apps
// (Prisma, Drizzle), run with the CLI from node_modules (npx --no-install
// never downloads a package).
//
// Railpack keeps devDependencies in the runtime image: its node provider
// copies node_modules from the install step unless RAILPACK_PRUNE_DEPS is
// set (railpack v0.42 core/providers/node: shouldPrune), so a CLI in
// devDependencies is there by default. When the plan prunes (Info.PruneDeps),
// only CLIs in dependencies are suggested.
//
// They are only for Railpack images that keep node_modules: a Dockerfile's
// image may not have them, a static site has no Node.js at all, and a preset
// that keeps only some paths (Next.js standalone output) drops the CLI.
// Yarn Plug'n'Play has no node_modules and Bun images without Node.js have
// no npx.
func jsCommands(f Facts, i Info) []state.CommandSuggestion {
	pkg := f.PackageJSON
	if pkg == nil || i.Dockerfile || i.Metadata["nodeIsSPA"] == "true" || f.Files[".pnp.cjs"] {
		return nil
	}
	if _, bun := i.Packages["bun"]; bun {
		if _, node := i.Packages["node"]; !node {
			return nil
		}
	}
	has := func(name string) bool {
		if i.PruneDeps {
			_, ok := pkg.Dependencies[name]
			return ok
		}
		_, ok := pkg.Dep(name)
		return ok
	}
	npx := func(args ...string) []string { return append([]string{"npx", "--no-install"}, args...) }
	var out []state.CommandSuggestion
	// The prisma CLI is its own package; @prisma/client alone has no binary.
	if has("prisma") {
		out = append(out,
			command("prisma-migrate-status", "Show migration status", "Shows which Prisma migrations have been applied to the database.", false, npx("prisma", "migrate", "status")...),
			command("prisma-migrate", "Run migrations", "Applies the Prisma migrations that haven't been applied yet.", true, npx("prisma", "migrate", "deploy")...),
		)
	}
	if has("drizzle-kit") {
		out = append(out, command("drizzle-migrate", "Run migrations", "Applies the Drizzle migrations that haven't been applied yet.", true, npx("drizzle-kit", "migrate")...))
	}
	return out
}

// isJavaScript reports whether p is one of the JavaScript presets, whose
// apps get jsCommands.
func isJavaScript(p Preset) bool {
	if _, ok := p.(nodeServer); ok {
		return true
	}
	return slices.ContainsFunc(javascriptPresets(), func(js Preset) bool { return js.Name() == p.Name() })
}
