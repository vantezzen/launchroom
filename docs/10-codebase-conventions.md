# 10: Codebase & conventions

The fastest way to lose users is to break on random things. Comparable projects show where that comes from:
- a 6,137-line god job;
- 17k lines of global helpers;
- behaviour forks stored in data;
- per-feature hand-written authorisation;
- string-built shell.

This doc defines what goes where, so launchroom stays boring and stable.

## 1. Repository layout

```
launchroom/
├── dashboard/                 Laravel 13 app (control plane)
├── daemon/                    Go module: launchroomd, dockerd-guard, ambassador, schemagen (§4)
├── cli/                       Go module: the `launchroom` CLI ([16](16-api-and-cli.md))
├── protocol/                  Shared contract: JSON Schemas generated from Go types, fixtures, SIGNING.md, CHANGELOG
├── packages/
│   └── next-adapter/          @vantezzen/launchroom-next-adapter (npm package, vendored into the daemon's Next.js preset)
├── site/                      Next.js site: launchroom.vantezzen.io and the user docs (content/docs/*.mdx)
├── deploy/                    What users install
│   ├── control-plane/         compose.yml, images.env, launchroom (helper CLI script)
│   ├── daemon/                compose.yml for servers that join (launchroomd + dockerd-guard)
│   ├── images/                Dockerfiles: dashboard (FrankenPHP), caddy (xcaddy), headscale, zot. The launchroomd image lives at daemon/Dockerfile.
│   ├── install/               install.sh (control plane), server.sh (joining servers), cli.sh (CLI)
│   ├── release/               build-bundle.sh, check-pinned-images.sh
│   └── test/                  install and update end-to-end scripts
├── dev/                       Local development environment (compose, dind "fake servers", git fixture server, failover test)
│   └── fixtures/              Sample apps deployed into the fake servers
├── docs/                      This folder
├── .github/workflows/         ci.yml (dashboard, daemon, cli, protocol, deploy, next-adapter, site, workflows, audit; `ci-ok` sums them up), release.yml (signed)
└── Makefile                   Task runner entry points (`make help`)
```

`references/` is local reading material only. It is git-ignored and never imported.

## 2. Dashboard (Laravel) architecture

### 2.1 Layers and where code goes

```
HTTP / Inertia / API / Webhook / Daemon API / Console / Scheduled
            │  (authorize + validate → Data)
            ▼
        Actions  ───────────────▶ Events ──▶ Listeners (side effects: GitHub status, notifications, broadcasts)
   (one use case each,             ▲
    transactional)                 │
            │                      │
            ▼                      │
  Models (Eloquent) ◀── Queries   Jobs (async wrappers that call Actions; small, idempotent)
            │
            ▼
     Services / Drivers (integration boundaries: GitHub, Headscale, Registry tokens, DB engines, Signer)
```

| Directory | Contains | Rules |
|---|---|---|
| `app/Http/Controllers/{Area}` | Thin controllers | `authorize` → validated Data/FormRequest → **one Action call** → Inertia response/redirect. No queries beyond route-model binding and scoped lookups. No business logic. |
| `app/Http/Requests` | FormRequests where Data validation isn't enough | `authorize()` delegates to policies |
| `app/Actions/{Domain}` | Use cases: `CreateProject`, `StageVariableChange`, `DeployEnvironment`, `EnrollServer`, `AdvanceDeployment`, `RenderNodeState` | `final` classes, a single public `handle(...)` method, constructor DI, typed Data in and out, wrap mutations in `DB::transaction`, dispatch events with `afterCommit`. Actions may call other Actions. No HTTP objects (`Request`, `session()`). |
| `app/Data/{Domain}` | spatie/laravel-data objects | Input DTOs, Inertia props, JSON column casts, daemon protocol documents. Generated into TypeScript. |
| `app/Models` | Eloquent models | Relationships, casts, scopes, tiny accessors. No orchestration, no HTTP/IO. `$hidden` for secrets. `final` unless extended. |
| `app/Enums` | Backed enums | Statuses with transition tables (`DeploymentStatus::canTransitionTo()`), roles, kinds |
| `app/Policies` | One per model | Deny by default; team derived from the resource |
| `app/Jobs` | Queue entry points | Pass IDs; re-load through scoped queries; call Actions; `ShouldBeUnique` for per-entity work; `ShouldBeEncrypted` if the payload contains anything sensitive; explicit `tries`, `backoff`, `timeout`; idempotent |
| `app/Events`, `app/Listeners` | Domain events (`DeploymentStatusChanged`, `ServerWentOffline`) | Listeners for side effects only. A failing listener never changes the outcome of the action that emitted it. |
| `app/Services/{Integration}` | Integration clients: `GitHub\AppClient`, `Headscale\Client`, `Registry\TokenIssuer`, `Crypto\Envelope`, `Crypto\Signer`, `Http\SafeHttpClient` | Behind interfaces (`app/Contracts`) when there is more than one implementation or we need a fake in tests |
| `app/Drivers/{Kind}` | Pluggable strategies: `DatabaseEngines\Postgres`, `…\MySql`, `…\Redis`, `…\Valkey`, `…\MongoDb`, `…\ClickHouse` | Registered in a typed registry; one interface per kind; no per-engine `if` chains elsewhere |
| `app/Scheduling` | The placement scheduler (pure functions over Data: servers + requirements → placements) | No IO, exhaustively unit-tested |
| `app/Support` | Small, typed, framework-agnostic utilities (e.g. `Hostname`, `Ulid`, `EnvFile` parser) | No global helper functions, no grab-bag classes |
| `routes/` | `web.php` (Inertia) plus one file per area (`projects.php`, `backups.php`, …), `api.php` (public API, Sanctum), `daemon.php` (daemon API, signature middleware), `git.php` (including the webhook receivers), `channels.php` | Every group has explicit auth and authorisation middleware |

Domain names, used for sub-namespaces: `Teams`, `Servers`, `Projects`, `Environments`, `Services`, `Variables`, `Deployments`, `Builds`, `Domains`, `Git`, `Registry`, `Mesh`, `Daemons`, `Backups`, `Audit`.

### 2.2 Rules

1. **One way to do each thing.** Mutations go through Actions, whether from a controller, job, console command or API. Never mutate models directly from controllers or listeners.
2. **Typed boundaries.** Arrays never cross layer boundaries: use Data objects. JSON columns are cast to Data objects. PHPStan (Larastan) at **max level**, with no baseline growth.
3. **State machines are explicit.** Statuses are enums with a transition table, and transitions go through one method that validates, persists and emits an event. No `->update(['status' => …])` outside it (enforced by an arch test).
4. **Idempotency.** Jobs and daemon-facing endpoints must tolerate being run twice: idempotency keys, `SKIP LOCKED` leasing, `ShouldBeUnique`.
5. **Locks where it matters**: per-service-instance deployment admission (`lockForUpdate`), per-server NodeState rendering (an atomic version increment), and cache locks for GitHub token refresh.
6. **No behaviour flags in data.** When a format changes, write a migration that converts data. Don't add `parsing_version = 3` branches.
7. **No `env()` outside `config/`.** No facades inside `app/Scheduling` (pure). Tests control `now()` with `Carbon::setTestNow`.
8. **Process execution**: none in request paths. Where unavoidable, `Process::run([...argv])` only (R1 in [09](09-security.md)). The one exception is `App\Services\Backups\Process` (restic, pg_dump, pg_restore for control-plane backups and `launchroom:restore`, [14](14-backup-and-restore.md) §9.1): argv arrays, credentials via env, masked output, used only from queued jobs and console commands (arch tests).
9. **Time and IDs**: ULIDs (`public_id`) everywhere external; UTC timestamps; durations as `CarbonInterval` in Data.
10. **Errors**: domain exceptions (`DeploymentCannotBeCancelled`) carry user-facing messages. Unexpected exceptions are reported (Sentry/Flare optional) and never leak internals to the UI.
11. **Comments explain *why***, especially for operational workarounds. Link the issue or the doc section.

### 2.3 Packages

| Package | Why |
|---|---|
| `inertiajs/inertia-laravel` (v3) | Server side of the React UI |
| `spatie/laravel-data` | DTOs, casts, validation, Inertia props |
| `spatie/laravel-typescript-transformer` | Generate TS types for Data and enums → `resources/js/types/generated.d.ts` |
| `laravel/wayfinder` | Typed routes in TS |
| `laravel/reverb`, `laravel/echo` + `@laravel/echo-react` | Realtime |
| `laravel/horizon` | Redis queues, supervision, metrics |
| `laravel/sanctum` | API tokens with abilities |
| `laravel/fortify` | Auth, 2FA, passkeys |
| `lcobucci/jwt` | Registry token-auth JWTs, GitHub App JWTs |
| `ext-sodium` | Envelope encryption, sealing, Ed25519 |
| `phpseclib/phpseclib` (v3) | SSH client for plain git servers: host keys and `ls-remote` over SSH, pure PHP, no `ssh` binary ([19](19-git-providers.md) §4.2) |
| `symfony/yaml` | Compose import parser (R6 in [09](09-security.md)) |
| `opis/json-schema` | Validates protocol documents against `protocol/schema/v1` (contract tests) |
| `anthropic-ai/sdk` | Dockerfile generation (D48, R36) |
| Dev: `pestphp/pest` + arch plugin, `larastan/larastan`, `spaze/phpstan-disallowed-calls`, `rector/rector`, `laravel/pint` | Quality gates |

Deliberately **not** used: `lorisleiva/laravel-actions` (plain final classes are enough), `spatie/laravel-model-states` (enums with transition tables are simpler), Livewire (Inertia/React is the UI stack), `laravel/octane` (the daemon API doesn't need it), the starter kit's `laravel/chisel` (it defined global functions and spawned processes).

### 2.3a Models, routing and authorization

- **Public ids**: models use `HasPublicId` with a static `publicIdPrefix()`. Malformed or wrong-prefix IDs 404 before any query runs.
- **Team scoping**: team-owned models use `BelongsToTeam`. Route parameters (`{server}`, `{enrollment}`, `{membership}`, `{invitation}`, `{team}`, `{project}`, `{environment}`, `{service}`, `{serviceInstance}`, `{variable}`, `{connection}`, `{domain}`, `{deployment}`, …) are bound in `App\Http\Routing\TeamScopedBindings`: the public id must be well-formed and the row must belong to one of the user's teams, otherwise 404. Policies then check the role in the *resource's* team. List pages use the session's current team (`users.current_team_id`).
- **Role matrix**: `TeamRole::permissions()` is the single mapping from roles to `Permission` cases; policies only call `$user->hasTeamPermission($team, Permission::…)` / `TeamRole::canGrant()`.
- **Route coverage**: every route is public (reviewed list), a daemon route (`AuthenticateDaemon` + `SignResponse`, controller uses `AuthenticateDaemon::server()`), or `auth` + an authorization call in the controller action (`Gate::authorize`) / `can:` middleware / the reviewed self-scoped list.
- **Sensitive mutations** (add server, approve/revoke, invite, change roles) authorize against the signed-in session without a password-confirmation redirect (D60). Policies, scoped bindings, CSRF, throttles, audit logging and typed destructive confirmations still apply.
- **Domain refusals**: actions throw `App\Exceptions\DomainRuleViolation` (user-facing message + field + status). `bootstrap/app.php` renders it as a validation error (web), JSON (`expectsJson`) or `{"error": …}` with its status (daemon API).
- `Route::inertia()` is not used; routes use `Route::get(..., Inertia\Controller::class)->defaults(...)`, because the macro is untyped and PHPStan max rejects it.
- Fortify's `CreateNewUser`/`ResetUserPassword` are exempt from the single-`handle()` arch rule (Fortify dictates their names).

### 2.3b Domain code, protocol and secrets

- **Where pure logic lives**: `App\Scheduling` (placement), `App\Support\Changes` (`SpecDiff`, `FieldMeta`), `App\Support\Variables` (references, generators, resolver, `.env` parser, targeting, system variables) and `App\Support\Images`. Read models that assemble Data from several tables live in `App\Queries` (`CurrentEnvironmentSpec`). Integration boundaries with more than one implementation are contracts in `App\Contracts` (`BuildOrchestrator`, `ReleaseRunner`, `ImageDigestResolver`), bound in `AppServiceProvider`.
- **Daemon protocol Data** live in `App\Data\Protocol\V1`. Responses implement `ProtocolDocument::toProtocol()` (exact wire shape, maps as objects) and are encoded once by `ProtocolJson`; those bytes are what `SignResponse` signs. Request bodies are decoded with `ProtocolPayload` and validated by the Data rules (mirroring the schema patterns).
- **Contract tests** validate with `opis/json-schema` against `protocol/schema/v1`. The dev compose mounts `protocol/` read-only at `/var/www/protocol` for the dashboard and test containers.
- **Secrets in memory**: resolved environments are `ResolvedEnvironment` / `EnvFileEntry` value objects that refuse serialization and redact `__debugInfo`; Data objects never hold plaintext values (revisions hold ciphertexts, diffs hold none). Project DEKs are unwrapped by the `scoped` `ProjectKeyring` (one request/job).
- **NodeState versions** are decided on the *fingerprint* content: sealed envs are randomized, so workloads hash with a fingerprint of their deployment's env snapshot ciphertext instead.

### 2.4 Testing

- **Pest** (D21). Feature tests per use case, policy matrix tests (role × own/other team) for every resource, and **arch tests** (`tests/Arch`):
  - controllers don't use `::find`/`::where('id'`;
  - Actions are final and have a single public method;
  - models don't depend on `Http`;
  - statuses change only through transitions;
  - no `env()` outside config.

  Some arch rules are source scans (`SourceRulesTest`: no `::find(`/`where('id'` in controllers, status changes only through transitions) because Pest arch can't see method calls. Each rule was checked by injecting a violation.
- **Route coverage test** (`RouteCoverageTest`): every route is authenticated and authorised.
- **Fake daemon**: an in-process `FakeDaemon` (`tests/Support`) that consumes NodeStates and tasks and emits reports. Used to test the deployment state machine end to end without Docker.
- **Contract tests**: PHP-rendered NodeStates and task specs validate against `protocol/` JSON Schemas; Go fixtures in `protocol/fixtures` round-trip in both languages.
- **Scheduler**: property-style tests (anti-affinity holds, stateful services never move, capacity respected).
- `phpunit.xml` forces the `launchroom_test` DB and array/sync drivers via both `<env>` and `<server>`, so tests can never touch dev data, whichever shell runs them. Parallel workstreams override the database with `TEST_DB` (AGENTS.md). It sets `memory_limit=2048M` for the full suite in one process.

### 2.5 Page props and query budgets

Pages should stay fast without caches that make data stale:

- **Props a partial reload doesn't ask for cost nothing.** Anything beyond a few fields is a closure (`'nodes' => fn () => …`), so Inertia only evaluates it when the visit includes it. The canvas is the model: opening a service or switching tabs reloads `only: ['panel']` (`panelVisitOptions`), so the canvas graph and the top bar's lists (`ProjectScope::shellProps`) are closures; when two props share one computation, memoise it inside the request (`$built ??= …`). Shared props in `HandleInertiaRequests` are closures for the same reason, and compute only what the sidebar shows (`OnboardingChecklist::progressFor` skips the checklist page's extras).
- **Build expensive read models once per request, explicitly.** `CurrentEnvironmentSpec` is not memoised globally (actions read it before and after a change); a read path that needs it in several places builds it once and passes it on (`CanvasGraph::for`, `StagedForService::has`, `ServiceSettings::for` take an optional `EnvironmentSpec`).
- **No N+1**: eager loads, `whereIn` batches and aggregate subqueries. Hot pages have **query budget tests**: the count is measured with `DB::enableQueryLog()` after a warm-up request, must not grow when services are added, and stays under a fixed upper bound (`tests/Feature/Canvas/CanvasQueryBudgetTest.php`, `ProjectCardDataTest`). Raise a budget only with a reason.
- **Index foreign keys you filter or eager-load by.** Postgres doesn't index them by itself; add the index in the migration that adds the column.
- `Inertia::defer()` suits a prop that is slow and below the fold; the pages measured so far didn't need one (each JSON prop is small; streams and history go through TanStack Query endpoints).

## 3. Frontend (React) conventions

```
dashboard/resources/js/
├── pages/                 One file per Inertia page (route), thin: layout + feature components
├── features/              Domain UI: canvas/, deployments/, variables/, servers/, domains/, logs/, command-palette/
│   └── <feature>/{components,hooks,lib}.ts(x)
├── components/ui/         shadcn primitives (generated, lightly customised)
├── components/            Shared app components (StatusDot, CopyButton, TimeAgo, CodeValue, EmptyState)
├── layouts/               App shell, project shell, settings shell
├── hooks/                 Cross-feature hooks (useRealtime, useHotkeys, useStagedChanges)
├── types/generated.d.ts   Generated from PHP. Never hand-edit.
└── wayfinder/             Generated routes
```

Rules:
- Pages receive **typed props** (generated types) and contain no data fetching beyond Inertia partial reloads and TanStack Query for streams and history.
- No business rules in the frontend (e.g. the build/runtime classification of a variable comes from the server).
- Optimistic updates through Inertia `router` options or TanStack mutations, always with rollback.
- Accessibility: keyboard-reachable canvas actions, focus management in panels, `prefers-reduced-motion`.
- **Heavy code loads with its first use**: libraries or tabs that only some visits need (elkjs, the console's xterm, the settings pages of the service panel) are `lazy()` / dynamic `import()`, so the page chunk stays small. Warm a chunk the user is likely to need next (the panel warms Settings). Check the build's chunk sizes when adding a large dependency.
- **Prefetch** links that are likely next (`<Link prefetch>`); a prefetched page is served until `cacheFor` expires (no revalidation on use), so links into live views (project cards → canvas) use a short `cacheFor`.
- `vp check` / ESLint / `tsc --noEmit` in CI (already in the starter kit's scripts).

## 4. Daemon (Go) conventions

```
daemon/
├── cmd/
│   ├── launchroomd/        main: config, wiring, signal handling, subcommands (update-supervisor, firewall remove, …)
│   ├── dockerd-guard/      owns the Docker socket and serves launchroomd an allow-listed Engine API (docs/09 §6, D49)
│   ├── ambassador/         the per-server TCP forwarder; runs as the system container lr-system-ambassador ([05](05-networking-ingress.md) §3)
│   └── schemagen/          emits protocol/schema/v1 from the Go types in internal/state (§5)
├── internal/
│   ├── ambassador/         forwarder logic: listeners from the NodeState's ambassadors to mesh upstreams
│   ├── ambassadord/        runs the ambassador system container and keeps its listeners in step
│   ├── atomicfile/         crash-safe file writes (readers see old or new, never partial)
│   ├── backup/             restic backups and restores, restore stream filtering ([14](14-backup-and-restore.md))
│   ├── build/              clone, builder resolution, Railpack planning, BuildKit client, log masking ([04](04-build-and-deploy.md))
│   │   ├── buildkitd/      the rootless BuildKit system container on build servers
│   │   ├── nextadapter/    vendored @vantezzen/launchroom-next-adapter for the Next.js preset
│   │   └── presets/        framework presets that patch the Railpack plan
│   ├── cleanup/            disk retention and pressure cleanup
│   ├── clientcert/         launchroomd's own key and short-lived client certificate for mTLS (D50)
│   ├── config/             configuration from env and flags; secrets only from env or file
│   ├── controlplane/       signed HTTP client for /daemon/v1, response signature verification
│   ├── cronexpr/           5-field cron parser and matcher for cron services
│   ├── docker/             thin layer over the Engine API (through the guard): spec conversion, managed-label scoping
│   │   └── dockerfake/     in-memory Engine client for unit tests
│   ├── enrollment/         persists the server id and trusted control-plane key set
│   ├── firewall/           nftables `inet launchroom` table (google/nftables), drift detection and repair
│   ├── guard/              dockerd-guard: route table, request parsing, container classes
│   │   └── guardapi/       endpoints and refusals shared by launchroomd and the guard
│   ├── health/             HTTP/TCP probes against container IPs, port discovery
│   ├── hostinfo/           host health facts for reports (disk, memory, load, limits, hardware)
│   ├── identity/           key material, RFC 9421 request signing, payload verification
│   ├── ingress/
│   │   ├── caddy/          route table → Caddy JSON, admin API client, Caddy system container, on-demand TLS ask handler
│   │   └── certstore/      reads Caddy's certificate storage, mirrors certificates, derives per-host status
│   ├── linkprobe/          signed latency/throughput probes between servers on <mesh IP>:7400
│   ├── logship/            follows workload container logs and ships them when the NodeState asks
│   ├── mesh/               the Tailscale client system container and its status
│   ├── metrics/            15 s host and workload samples, per-minute aggregation, buffered until reported
│   ├── nudge/              Reverb (Pusher protocol) client for "poll now" nudges
│   ├── policy/             local policy floor, shared with dockerd-guard ([03](03-daemon-protocol.md) §8)
│   ├── reconcile/          level-triggered reconciler: networks, volumes, workloads, system containers
│   ├── registry/           127.0.0.1:5000 forwarder to the launchroom registry, token handling
│   ├── sealed/             opens secrets sealed to this daemon's X25519 key
│   ├── secret/             string type that never leaks through logging, formatting or JSON
│   ├── session/            interactive Console and Data sessions over Reverb ([03](03-daemon-protocol.md) §11)
│   ├── state/              v1 protocol types (NodeState, tasks, reports), the source of the JSON Schemas
│   ├── tasks/              lease loop, dispatch to typed handlers, heartbeats
│   ├── traffic/            per-route request metrics from the edge Caddy's access log
│   └── update/             signed self-update and the update supervisor ([11](11-installation-and-dev.md) §4)
│       └── updatetest/     throwaway-key release signing for tests
└── test/                   integration tests (dind), git test server, release signing helpers
```

Rules:
- No shell (`forbidigo` bans `sh -c`). Use API clients where possible.
- `context.Context` everywhere, with timeouts on every external call. No goroutine without an owner and a shutdown path.
- Structured logging (`log/slog`, JSON). Secrets are typed (`internal/secret`, with a redacting `LogValue`).
- Reconciler functions are pure where possible (`plan := Diff(desired, actual)` then `Apply(plan)`), unit-tested on the diff, integration-tested on apply.
- Fuzz the NodeState/task parsers, the signature verification path and the guard's request parsing (`internal/state`, `internal/identity`, `internal/guard`). Run `golangci-lint` and `govulncheck` in CI.
- Every version is reproducibly built, signed (cosign) and multi-arch (amd64, arm64).

## 5. Protocol (`protocol/`)

- **Source of truth: Go types** in `daemon/internal/state` (and task specs). `go generate` runs `cmd/schemagen`, which emits JSON Schema (invopop/jsonschema) into `protocol/schema/v1/*.json`.
- PHP Data objects mirror them by hand (they're small). CI validates PHP output against the schemas, and shared fixtures in `protocol/fixtures/` are decoded by both sides.
- Breaking changes create `v2` schemas. The dashboard renders for each daemon's highest supported version ([03](03-daemon-protocol.md) §10).

## 6. CI gates

`.github/workflows/ci.yml` runs on every push and pull request. A `changes` job decides which areas changed; jobs of untouched areas are skipped, and `ci-ok` (the check to require) passes when every job passed or was skipped. A newer push cancels the running check of the same branch or pull request.

| Job | Runs when | Checks |
|---|---|---|
| dashboard-static | `dashboard/`, `protocol/`, the daemon installer files change | `composer ci:static`: Pint, Rector (dry-run), PHPStan max + disallowed calls, `vp check`, `tsc`; generated TS types up to date; asset build. Pint/Rector/PHPStan result caches are kept between runs |
| dashboard-tests | as dashboard-static | `composer test`: Pest (unit, feature, arch, route coverage) in parallel, one Postgres database per worker |
| daemon | `daemon/`, `protocol/` change | `go test -race` |
| daemon-lint | as daemon | golangci-lint (incl. forbidigo), govulncheck of the shipped binaries (`./cmd/...`) |
| cli | `cli/` changes | `go test`, cross-compiled release targets, golangci-lint, govulncheck |
| protocol | as daemon | schema generation is up to date (no drift in `protocol/`) |
| deploy | always | shellcheck, `sh -n`, base images pinned by digest, bundle builds with digest pins |
| next-adapter | the package or its vendored copy changes | build, typecheck and test; vendored copy in the daemon matches the build; package contents |
| site | `site/` or the files it copies change | typecheck, lint, format, build; committed installer and schema copies are current |
| workflows | always | actionlint |
| audit | always | `composer audit` (advisories fail; abandoned packages are reported), `npm audit` |

Every workflow change runs everything.

`.github/workflows/release.yml` (tags `v*`) builds signed images and bundles (cosign), SBOM, provenance and the CLI binaries ([09](09-security.md) R30). The integration suite (dind) and the install/update end-to-end tests run locally through `make` ([11](11-installation-and-dev.md)).

## 7. Docs discipline

- Architectural changes update `docs/` in the same PR. The decision log in `docs/README.md` gets a new row, or a status change.
- `AGENTS.md` (and `CLAUDE.md`, which includes it) at the repo root summarises these rules for AI assistants and contributors. It points here rather than duplicating.
