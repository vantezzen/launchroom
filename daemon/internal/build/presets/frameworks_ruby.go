package presets

import (
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

type rails struct{}

func (rails) Name() string { return "rails" }
func (rails) Version() int { return 1 }

func (rails) Match(f Facts, _ Info) bool { return f.Ruby.Rails }

// railsWorkdir is where the app lives in the image: /app for Railpack, the
// Dockerfile's WORKDIR otherwise (/rails in the Dockerfile `rails new` writes).
func railsWorkdir(f Facts, i Info) string {
	switch {
	case !i.Dockerfile:
		return "/app"
	case f.DockerfileWorkdir != "":
		return f.DockerfileWorkdir
	default:
		return "/rails"
	}
}

func (rails) Propose(f Facts, i Info) Proposal {
	r := f.Ruby
	p := Proposal{Framework: "rails", HealthPath: "/"}
	if i.Dockerfile {
		// The Dockerfile from `rails new` 8 runs Thruster on port 80 in front
		// of Puma; earlier ones run Puma on 3000.
		p.Port = 3000
		if r.Gems["thruster"] {
			p.Port = 80
		}
	}
	railsCmd := []string{"bundle", "exec", "rails"}
	if r.BinRails {
		railsCmd = []string{"bin/rails"}
	}
	p.Decisions = append(p.Decisions,
		Decision{
			ID: "rails-env", Title: "Run in production mode",
			Detail: "RAILS_ENV and RACK_ENV are production, and logs and static files work as Rails expects behind launchroom.",
			Effect: Effect{RuntimeEnv: map[string]string{
				"RAILS_ENV": "production", "RACK_ENV": "production",
				"RAILS_LOG_TO_STDOUT": "1", "RAILS_SERVE_STATIC_FILES": "1",
			}},
		},
		Decision{
			ID: "secret-key-base", Title: "Generate SECRET_KEY_BASE",
			Detail: "Rails signs and encrypts cookies with SECRET_KEY_BASE. When the service has none, launchroom creates one as a secret variable per environment, kept across deploys.",
			Effect: Effect{GeneratedSecrets: []state.GeneratedSecret{{Name: "SECRET_KEY_BASE", Length: 64, Encoding: state.SecretHex}}},
		},
		Decision{
			ID: "release-migrations", Title: "Prepare the database once per deploy",
			Detail: "rails db:prepare runs as the release command: it creates the database the first time and runs pending migrations after that.",
			Effect: Effect{ReleaseCommand: append(append([]string{}, railsCmd...), "db:prepare")},
		},
	)
	if r.BinRails {
		start := "rm -f tmp/pids/server.pid && exec bundle exec bin/rails server -b 0.0.0.0 -p ${PORT:-3000}"
		detail := "Starts Puma on $PORT and removes a server.pid left over from an earlier run, which would stop Rails after a restart."
		if r.ProductionSQLite {
			start = "rm -f tmp/pids/server.pid && bin/rails db:prepare && exec bundle exec bin/rails server -b 0.0.0.0 -p ${PORT:-3000}"
			detail = "Starts Puma on $PORT. Like the Dockerfile Rails generates, it prepares the SQLite database at start, and it removes a server.pid left over from an earlier run."
		}
		p.Decisions = append(p.Decisions, Decision{
			ID: "start-command", Title: "Start the Rails server", Detail: detail,
			Effect: Effect{Plan: PlanPatch{StartCommand: start}},
		})
	}
	if r.HealthPath != "" {
		p.HealthPath = r.HealthPath
		p.Decisions = append(p.Decisions, Decision{ID: "health-up", Title: "Health check on " + r.HealthPath, Detail: "Rails' own health route (rails/health#show).", Effect: Effect{HealthPath: r.HealthPath}})
	}
	switch {
	case r.Gems["sidekiq"]:
		p.Decisions = append(p.Decisions, Decision{
			ID: "sidekiq", Title: "Sidekiq worker process", Detail: "bundle exec sidekiq, with 30 seconds to finish running jobs when stopped",
			Effect: Effect{Processes: []state.ProcessSuggestion{{Name: "sidekiq", Args: []string{"bundle", "exec", "sidekiq"}, StopTimeout: "30s"}}},
		})
	case r.Gems["good_job"]:
		p.Decisions = append(p.Decisions, Decision{
			ID: "good-job", Title: "GoodJob worker process", Detail: "bundle exec good_job start, with 30 seconds to finish running jobs when stopped",
			Effect: Effect{Processes: []state.ProcessSuggestion{{Name: "jobs", Args: []string{"bundle", "exec", "good_job", "start"}, StopTimeout: "30s"}}},
		})
	case r.Gems["solid_queue"] && r.ProductionSQLite:
		// A separate process would need its own copy of the SQLite queue database.
		p.Decisions = append(p.Decisions, Decision{
			ID: "solid-queue", Title: "Run Solid Queue inside Puma",
			Detail: "The queue lives in SQLite next to the app, so jobs run in the web process (SOLID_QUEUE_IN_PUMA).",
			Effect: Effect{RuntimeEnv: map[string]string{"SOLID_QUEUE_IN_PUMA": "true"}},
		})
	case r.Gems["solid_queue"] && r.BinJobs:
		p.Decisions = append(p.Decisions, Decision{
			ID: "solid-queue", Title: "Solid Queue worker process", Detail: "bin/jobs, with 30 seconds to finish running jobs when stopped",
			Effect: Effect{Processes: []state.ProcessSuggestion{{Name: "jobs", Args: []string{"bin/jobs"}, StopTimeout: "30s"}}},
		})
	}
	if r.Credentials != "" && !r.MasterKey {
		key := "config/master.key"
		if r.Credentials == "config/credentials/production.yml.enc" {
			key = "config/credentials/production.key"
		}
		p.Decisions = append(p.Decisions, Decision{
			ID: "master-key", Title: "Ask for RAILS_MASTER_KEY",
			Detail: "Your app has encrypted credentials. Their key isn't in the repository, so add it as a secret variable before the first deploy.",
			Effect: Effect{RequiredSecrets: []state.RequiredSecret{{Name: "RAILS_MASTER_KEY", Reason: "Decrypts " + r.Credentials + ". Paste the contents of " + key + "."}}},
		})
	}
	if r.ProductionSQLite || r.LocalStorage {
		reason := "Uploaded files in storage/ are kept across deploys. The app then runs on one server."
		if r.ProductionSQLite {
			reason = "The SQLite databases and uploaded files in storage/ are kept across deploys. The app then runs on one server."
		}
		p.Decisions = append(p.Decisions, Decision{
			ID: "storage-volume", Title: "Suggest a volume for storage/",
			Effect: Effect{VolumeSuggestions: []state.VolumeSuggestion{{Path: state.ContainerPath(railsWorkdir(f, i) + "/storage"), Reason: reason}}},
		})
	}
	if r.ProductionSQLite {
		p.Warnings = append(p.Warnings, Warning{Code: "sqlite-database", Runtime: true,
			Message: "Your production database is SQLite in storage/. Without a volume, it is reset on every deploy. Keep storage/ on a volume or connect a database."})
	}
	return p
}
