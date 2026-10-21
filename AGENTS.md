# AGENTS.md — rules for working on launchroom

launchroom is a self-hosted PaaS (Vercel/Railway UX on your own servers). **The design lives in `docs/` — read `docs/README.md` (decision log) and the doc for the area you touch before writing code.** If your change contradicts a doc, update the doc in the same change (or stop and ask).

## Layout
- `dashboard/` — Laravel 13 + Inertia 3 + React control plane (PHP 8.4+, Postgres, Valkey, Horizon, Reverb). See `docs/10-codebase-conventions.md` §2–3.
- `daemon/` — `launchroomd`, the Go daemon (one per server; called "agent" before D27). User-facing UI never says "daemon" or "agent": it talks about servers (docs/README.md D27). See `docs/03-daemon-protocol.md`, `docs/10-codebase-conventions.md` §4.
- `cli/` — the `launchroom` CLI, a separate Go module over the public API (D36). See `docs/16-api-and-cli.md`.
- `packages/` — published JS packages (`@vantezzen/launchroom-next-adapter`).
- `protocol/` — JSON Schemas generated from Go types + shared fixtures (contract between dashboard and daemon).
- `deploy/` — what users install (compose bundles, install scripts, image Dockerfiles).
- `site/` — the public website and user docs at launchroom.vantezzen.io (a plain Next.js + fumadocs app). User-facing docs live here; `docs/` stays the repo/contributor docs. See `docs/21-website.md`.
- `dev/` — isolated local dev environment (everything in Docker; deployed test apps run inside dind "fake servers").
- `references/` — other projects' code for reading only. Never import or copy wholesale; it is git-ignored.

## Non-negotiable rules (from docs/09-security.md)
1. **No shell strings.** PHP: no `exec/shell_exec/system/passthru/popen/proc_open`/backticks/`Process::fromShellCommandline`/string `Process::run`. Go: no `sh -c`. Use argv arrays and API clients.
2. **No SSH, no "run command on host".** launchroomd accepts signed, typed desired state and typed tasks only.
3. **Authorization by construction.** Policies for every model; scoped route bindings; never `Model::find($request->...)` — go through team relations; authorize in every controller action, channel and job.
4. **Secrets never serialized or logged.** `$hidden`, Data objects whitelist fields, `#[\SensitiveParameter]`, envelope encryption for stored secrets.
5. **Host-side names are generated** from IDs; user input is display-only. Validate every identifier by grammar on both dashboard and daemon.
6. **Fail closed** on signatures/HMACs; `hash_equals` only.

## Dashboard conventions (summary of docs/10 §2)
- Thin controllers: authorize → validated Data/FormRequest → one Action → Inertia response.
- `app/Actions/{Domain}`: `final` classes, single public `handle()`, typed Data in/out, mutations in `DB::transaction`, events `afterCommit`.
- `app/Data/{Domain}`: spatie/laravel-data for inputs, props, JSON casts, protocol docs; TS types are generated — never hand-edit `resources/js/types/generated.d.ts`.
- Status enums own their transition tables; status changes only via the transition method.
- Jobs: pass IDs, re-scope, idempotent, explicit tries/backoff/timeout.
- No global helper files, no `env()` outside `config/`, no behaviour flags stored in data.
- Tests: Pest (feature + policy matrix + arch tests). PHPStan max must stay green.

## launchroomd conventions (summary of docs/10 §4)
- `context.Context` + timeouts everywhere; `log/slog` JSON; secrets typed with redacting `LogValue`.
- Reconcile = pure diff + apply. Only touch Docker objects labelled `launchroom.managed=true`.
- Enforce the local policy floor (docs/03 §8) regardless of what the dashboard sends.

## UI
- Follow `docs/design-system.md` (Vercel-inspired: Geist, monochrome, colour only with meaning + a non-colour cue, no decorative effects). Never use Vercel logos or brand marks.

## Containment (development)
- **All execution happens in Docker.** Go, PHP tooling, Node, databases and dind fake servers run in containers through `make` targets. No host installs, no global `composer`/`npm -g`/`brew`.
- Every container, volume, network and image we create is named `launchroom-dev*` and labelled `launchroom.dev=true`. Host ports bind to `127.0.0.1` only.
- Never touch Docker objects you didn't create. No global `docker system prune`; cleanup goes by label or compose project.
- Never mount the host Docker socket into anything. Tests that need Docker run against a dind fake server.

## Tooling
- `make help` lists tasks. Go is run via Docker (`golang` image) — no Go toolchain required on the host.
- **Test what you touched, not everything.** The full suites take many minutes of all CPU cores, so they run once per integration (by whoever merges the workstreams), not per change or per agent:
  - dashboard: `make check-dashboard FILES="app/Foo/Bar.php tests/Feature/Foo/BarTest.php" TEST_DB=launchroom_test_<name>` (Pint, Rector, PHPStan on those files, tsc, Pest on the listed tests; add `ARGS="--filter=..."` for more). Without `FILES` it checks everything changed since the last staged checkpoint. `make test-dashboard-pest ARGS="--filter=..."` runs Pest alone.
  - launchroomd: `make test-daemon PKG=./internal/foo/...`, `make lint-daemon PKG=./internal/foo/...`, integration with `ARGS='-test.run TestFoo'`.
  - Full gates (`make test-dashboard`, `make test-daemon` without `PKG`, `make lint-daemon` without `PKG`, the full integration suite, `test-install`, `test-update`) share one machine-wide lock (`dev/bin/heavy-lock.sh`): parallel runs queue instead of competing. Run them only when asked to, or once at the very end of a workstream.
  - Tool containers are capped at `LR_TOOL_CPUS` (default 4) CPUs.
- When several workstreams run in parallel, each uses its own test DB (`TEST_DB=launchroom_test_<name>`) and integration project (`ITEST=<name>`). Never run `migrate:fresh` against the dev DB.
- Never restart OrbStack/Docker yourself (`orbctl` included); if Docker hangs, stop and report. Don't start MongoDB 8.x/9.x in dev (it froze the engine on the dev VM's kernel); use 7.0.
- Naming: always lowercase `launchroom`. Brand namespace `vantezzen` (`ghcr.io/vantezzen/launchroom-*`, `@vantezzen/launchroom-*`, `launchroom.vantezzen.io`).
