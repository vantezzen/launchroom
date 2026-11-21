package presets

import (
	"github.com/vantezzen/launchroom/daemon/internal/state"
)

// Decisions the build applies itself (outside the preset's Effect) when they
// are listed, so a user can remove them like any other chip (OD11).
const (
	// PhpVisitorIPDecision: the FrankenPHP Caddyfile trusts the ingress's
	// X-Forwarded-For and sets REMOTE_ADDR to the visitor's address.
	PhpVisitorIPDecision = "visitor-ip"
	// LaravelStartScriptDecision: Railpack's Laravel start script runs
	// without optimize:clear and starts uncached when caching fails.
	LaravelStartScriptDecision = "start-script"
	// PhpPcntlDecision: pcntl is installed before Railpack plans (ResolvePrePlan).
	PhpPcntlDecision = "pcntl"
	// PhpPublicDocrootDecision: the web root is public/ (ResolvePrePlan).
	PhpPublicDocrootDecision = "public-docroot"
)

// phpWorkdir is where the app lives in the image: /app for Railpack, the
// Dockerfile's WORKDIR otherwise.
func phpWorkdir(f Facts, i Info) string {
	if i.Dockerfile && f.DockerfileWorkdir != "" {
		return f.DockerfileWorkdir
	}
	return "/app"
}

var visitorIPDecision = Decision{
	ID: PhpVisitorIPDecision, Title: "Pass on the visitor's IP address",
	Detail: "PHP sees the visitor's address in REMOTE_ADDR instead of launchroom's, so rate limits and logs work without trusting proxies in your code.",
}

type laravel struct{}

func (laravel) Name() string { return "laravel" }

// Version 3: health path from bootstrap/app.php, APP_URL alias, pcntl, stop
// timeouts, production defaults, storage and SQLite hints, start script and
// visitor IP (OD11). Version 2: APP_KEY is generated.
func (laravel) Version() int { return 3 }

func (laravel) Match(f Facts, i Info) bool {
	return f.Files["artisan"] && (f.ComposerRequire["laravel/framework"] || i.Metadata["phpFramework"] == "laravel")
}

// PrePlan installs pcntl so queue workers, Horizon and Reverb can finish
// their work on SIGTERM.
func (l laravel) PrePlan(f Facts) map[string]PrePlan {
	if !l.Match(f, Info{}) {
		return nil
	}
	return map[string]PrePlan{PhpPcntlDecision: {PhpExtensions: []state.PhpDependencyExtension{{Name: "pcntl", For: "laravel/framework"}}}}
}

func (laravel) Propose(f Facts, i Info) Proposal {
	l := f.PHP.Laravel
	health := l.HealthPath
	p := Proposal{Framework: "laravel", Port: 8080, HealthPath: "/", Commands: laravelCommands()}
	if health != "" {
		p.HealthPath = health
	}
	p.Decisions = append(p.Decisions,
		Decision{
			ID: "release-migrations", Title: "Run migrations once per deploy",
			Detail: "php artisan migrate --force runs as the release command instead of at every container start (no races between replicas and workers).",
			Effect: Effect{
				Plan:           PlanPatch{DeployVariables: map[string]string{"RAILPACK_SKIP_MIGRATIONS": "true"}},
				ReleaseCommand: []string{"php", "artisan", "migrate", "--force"},
			},
		},
		Decision{
			ID: "optimize-at-start", Title: "Cache config at container start",
			Detail: "Build-time config/route/view/event caching is removed so build secrets never land in bootstrap/cache; the start script runs artisan optimize with the runtime environment.",
			Effect: Effect{Plan: PlanPatch{DropBuildCommandsContaining: []string{
				"artisan config:cache", "artisan route:cache", "artisan view:cache", "artisan event:cache", "artisan optimize",
			}}},
		},
		Decision{
			ID: LaravelStartScriptDecision, Title: "Keep the cache store on restarts",
			Detail: "The start script no longer runs optimize:clear, which flushed locks, rate limits and queue restart signals on every start. If caching config or routes fails, the app starts uncached and the error is logged.",
		},
		visitorIPDecision,
		Decision{
			ID: PhpPcntlDecision, Title: "Install the pcntl extension",
			Detail: "Queue workers and Horizon need pcntl to finish the current job when they are stopped.",
		},
	)
	const workerStop = state.Duration("60s")
	if f.ComposerRequire["laravel/horizon"] {
		p.Decisions = append(p.Decisions, Decision{
			ID: "horizon", Title: "Horizon worker process", Detail: "php artisan horizon, with 60 seconds to finish running jobs when stopped",
			Effect: Effect{Processes: []state.ProcessSuggestion{{Name: "horizon", Args: []string{"php", "artisan", "horizon"}, StopTimeout: workerStop}}},
		})
	} else {
		p.Decisions = append(p.Decisions, Decision{
			ID: "queue-worker", Title: "Queue worker process", Detail: "php artisan queue:work, with 60 seconds to finish the current job when stopped",
			Effect: Effect{Processes: []state.ProcessSuggestion{{Name: "queue", Args: []string{"php", "artisan", "queue:work", "--tries=3"}, StopTimeout: workerStop}}},
		})
	}
	p.Decisions = append(p.Decisions, Decision{
		ID: "scheduler", Title: "Scheduler process", Detail: "php artisan schedule:work",
		Effect: Effect{Processes: []state.ProcessSuggestion{{Name: "scheduler", Args: []string{"php", "artisan", "schedule:work"}}}},
	})
	if f.ComposerRequire["laravel/reverb"] {
		p.Decisions = append(p.Decisions, Decision{
			ID: "reverb", Title: "Reverb WebSocket process", Detail: "php artisan reverb:start",
			Effect: Effect{Processes: []state.ProcessSuggestion{{Name: "reverb", Args: []string{"php", "artisan", "reverb:start", "--host=0.0.0.0", "--port=8080"}}}},
		})
	}
	if health != "" {
		p.Decisions = append(p.Decisions, Decision{ID: "health-up", Title: "Health check on " + health, Detail: "The health route from bootstrap/app.php.", Effect: Effect{HealthPath: health}})
	}
	p.Decisions = append(p.Decisions,
		Decision{
			ID: "app-key", Title: "Generate APP_KEY",
			Detail: "Laravel can't start without APP_KEY. When the service has none, launchroom creates one as a secret variable per environment, kept across deploys.",
			// 32 alphanumeric characters are a valid AES-256-CBC key for Laravel's encrypter.
			Effect: Effect{GeneratedSecrets: []state.GeneratedSecret{{Name: "APP_KEY", Length: 32, Encoding: state.SecretAlnum}}},
		},
		Decision{
			ID: "app-url", Title: "Set APP_URL to the service's address",
			Detail: "APP_URL follows this environment's domain, so links in mail, queued jobs and Storage::url() are right. Your own APP_URL replaces it.",
			Effect: Effect{EnvAliases: []state.EnvAlias{{Name: "APP_URL", From: state.AliasPublicURL}}},
		},
		Decision{
			ID: "production-defaults", Title: "Production log level and secure cookies",
			Detail: "LOG_LEVEL=info instead of debug, and SESSION_SECURE_COOKIE=true since every address is HTTPS. Your own variables replace them.",
			Effect: Effect{RuntimeEnv: map[string]string{"LOG_LEVEL": "info", "SESSION_SECURE_COOKIE": "true"}},
		},
	)
	if l.Uploads {
		p.Decisions = append(p.Decisions, Decision{
			ID: "persist-uploads", Title: "Suggest a volume for uploaded files",
			Detail: "Your code stores uploads on a local disk, which is reset on every deploy.",
			Effect: Effect{VolumeSuggestions: []state.VolumeSuggestion{{
				Path:   state.ContainerPath(phpWorkdir(f, i) + "/storage/app"),
				Reason: "Uploaded files in storage/app are kept across deploys. The app then runs on one server.",
			}}},
		})
	}
	if l.DefaultDB == "sqlite" {
		p.Warnings = append(p.Warnings, Warning{Code: "sqlite-database", Runtime: true,
			Message: "Without DB_CONNECTION, this app uses SQLite. Its file is inside the container and is reset on every deploy. Connect a database to keep your data."})
	}
	if l.SessionDriver == "file" {
		p.Warnings = append(p.Warnings, Warning{Code: "file-sessions", Runtime: true,
			Message: "Sessions are stored in files inside the container, so visitors are signed out on every deploy and sessions don't work across replicas. Set SESSION_DRIVER to database or redis."})
	}
	return p
}

type symfony struct{}

func (symfony) Name() string { return "symfony" }
func (symfony) Version() int { return 1 }

func (symfony) Match(f Facts, i Info) bool {
	return f.PHP.Symfony || i.Metadata["phpFramework"] == "symfony"
}

func (symfony) Propose(f Facts, _ Info) Proposal {
	p := Proposal{Framework: "symfony", Port: 8080, HealthPath: "/"}
	var migrate []string
	if f.ComposerRequire["doctrine/doctrine-migrations-bundle"] {
		migrate = []string{"php", "bin/console", "doctrine:migrations:migrate", "--no-interaction", "--allow-no-migration"}
		p.Decisions = append(p.Decisions, Decision{
			ID: "release-migrations", Title: "Run migrations once per deploy",
			Detail: "doctrine:migrations:migrate runs as the release command instead of at every container start on every replica.",
			Effect: Effect{
				Plan:           PlanPatch{DeployVariables: map[string]string{"RAILPACK_SKIP_MIGRATIONS": "true"}},
				ReleaseCommand: migrate,
			},
		})
	}
	p.Commands = symfonyCommands(migrate)
	p.Decisions = append(p.Decisions,
		Decision{
			ID: "app-secret", Title: "Generate APP_SECRET",
			Detail: "Symfony signs URLs, CSRF tokens and remember-me cookies with APP_SECRET. When the service has none, launchroom creates one as a secret variable per environment, kept across deploys.",
			Effect: Effect{GeneratedSecrets: []state.GeneratedSecret{{Name: "APP_SECRET", Length: 16, Encoding: state.SecretHex}}},
		},
		Decision{
			ID: "default-uri", Title: "Set DEFAULT_URI to the service's address",
			Detail: "URLs built outside a request (commands, Messenger, mail) use this environment's domain. Your own DEFAULT_URI replaces it.",
			Effect: Effect{EnvAliases: []state.EnvAlias{{Name: "DEFAULT_URI", From: state.AliasPublicURL}}},
		},
		Decision{
			ID: "trusted-proxies", Title: "Trust launchroom's proxy",
			Detail: "SYMFONY_TRUSTED_PROXIES and TRUSTED_PROXIES name the addresses requests arrive from, so Symfony knows the request was HTTPS.",
			Effect: Effect{EnvAliases: []state.EnvAlias{
				{Name: "SYMFONY_TRUSTED_PROXIES", From: state.AliasTrustedProxyCIDRs},
				{Name: "TRUSTED_PROXIES", From: state.AliasTrustedProxyCIDRs},
			}},
		},
		visitorIPDecision,
	)
	return p
}

// phpPlain covers PHP apps without a framework preset (Slim, custom code).
type phpPlain struct{}

func (phpPlain) Name() string { return "php" }
func (phpPlain) Version() int { return 1 }

func (phpPlain) Match(f Facts, i Info) bool {
	return i.Provider == "php" && !f.Files["artisan"] && !f.PHP.Symfony && i.Metadata["phpFramework"] == ""
}

// PrePlan serves public/ when that's where index.php is, so vendor/,
// composer.json and config files are never reachable from the web.
func (phpPlain) PrePlan(f Facts) map[string]PrePlan {
	if f.Files["artisan"] || f.PHP.Symfony || !f.PHP.PublicIndex {
		return nil
	}
	return map[string]PrePlan{PhpPublicDocrootDecision: {RailpackEnv: map[string]string{"RAILPACK_PHP_ROOT_DIR": "/app/public"}}}
}

func (phpPlain) Propose(f Facts, _ Info) Proposal {
	p := Proposal{Framework: "php", Port: 8080, HealthPath: "/"}
	if f.PHP.PublicIndex {
		p.Decisions = append(p.Decisions, Decision{
			ID: PhpPublicDocrootDecision, Title: "Serve the public folder",
			Detail: "public/index.php is the entry point, so only public/ is served. vendor/, composer.json and your config stay private.",
		})
	}
	p.Decisions = append(p.Decisions, visitorIPDecision)
	return p
}
