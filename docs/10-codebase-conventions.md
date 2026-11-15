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
│   ├── install/               install.sh (control plane), server.sh (joining servers), cli.sh (CLI); lib/ (their shared code, spliced in by splice.sh)
│   ├── release/               build-bundle.sh, check-pinned-images.sh, check-pins.sh
│   ├── test/                  install, move and update end-to-end scripts (lib.sh), script unit tests
│   └── pins.env               build/test/dev tool pins (runtime pins: control-plane/images.env)
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
| `app/Http/Controllers/{Area}` | Thin controllers | `authorize` → validated Data/FormRequest → **one Action call** → Inertia response/redirect. No queries beyond route-model binding and scoped lookups; page props come from `App\Queries\*Page` read models. No business logic, and no static helpers or constants other code reads (they belong to the Action, Query, Data or enum that owns them). |
| `app/Http/Requests` | FormRequests where Data validation isn't enough | `authorize()` delegates to policies |
| `app/Actions/{Domain}` | Use cases: `CreateProject`, `StageVariableChange`, `DeployEnvironment`, `EnrollServer`, `AdvanceDeployment`, `RenderNodeState` | `final` classes, a single public `handle(...)` method, constructor DI, typed Data in and out, wrap mutations in `DB::transaction`, dispatch events with `afterCommit`. Actions may call other Actions. No HTTP objects (`Request`, `session()`). |
| `app/Data/{Domain}` | spatie/laravel-data objects | Input DTOs, Inertia props, JSON column casts, daemon protocol documents. Generated into TypeScript. |
| `app/Models` | Eloquent models | Relationships, casts, scopes, tiny accessors. No orchestration, no HTTP/IO. `$hidden` for secrets. `final` unless extended. |
| `app/Enums` | Backed enums | Statuses with transition tables (`DeploymentStatus::canTransitionTo()`), roles, kinds |
| `app/Policies` | One per model | Deny by default; team derived from the resource |
| `app/Jobs` | Queue entry points | Pass IDs; re-load through scoped queries; call Actions; `ShouldBeUnique` for per-entity work; `ShouldBeEncrypted` if the payload contains anything sensitive; explicit `tries`, `backoff`, `timeout`; idempotent |
| `app/Events`, `app/Listeners` | Domain events (`DeploymentStatusChanged`, `ServerWentOffline`) | Listeners for side effects only. A failing listener never changes the outcome of the action that emitted it. |
| Side effects of a change | `CanvasChanged`, `NodeStateInvalidated`, follow-up jobs (`VerifyDomainJob`, `EvaluateSecurityFindingsJob`) | Emitted by the **Action** that made the change, never by its caller, so web, API, blueprints, config-as-code and console callers all get them. Both events are `ShouldDispatchAfterCommit` (inside a transaction they go out on commit, or not at all), jobs use `->afterCommit()`. `CanvasChanged` goes to every viewer, the requester included (no `toOthers()`): the staged-changes pill and prefetched panels are client queries the requester's own response doesn't refresh. Actions name what changed (`CanvasChanged::forInstance/forService/forProject/forEnvironment`, `NodeStateInvalidated::servers/edges/mesh`) instead of assembling channel names or server id lists; an action called by another may broadcast again (viewers coalesce reloads). Deployment status changes reach the canvas through one listener (`BroadcastDeploymentStatus`). |
| `app/Services/{Integration}` | Integration clients: `GitHub\AppClient`, `Headscale\Client`, `Registry\TokenIssuer`, `Crypto\Envelope`, `Crypto\Signer`, `Http\SafeHttpClient`; shared domain building blocks Actions use inside their transaction (`Holds`, `Placer`, `FinalBackups`) | Behind interfaces (`app/Contracts`) when there is more than one implementation or we need a fake in tests. Never call Actions: a use case that needs another Action is an Action, and a read model is a Query (arch test). |
| `app/Drivers/{Kind}` | Pluggable strategies: `DatabaseEngines\Postgres`, `…\MySql`, `…\Redis`, `…\Valkey`, `…\MongoDb`, `…\ClickHouse` | Registered in a typed registry; one interface per kind; no per-engine `if` chains elsewhere |
| `app/Scheduling` | The placement scheduler (pure functions over Data: servers + requirements → placements) | No IO, exhaustively unit-tested |
| `app/Support` | Small, typed, framework-agnostic utilities (e.g. `Hostname`, `Ulid`, `EnvFile` parser) | No global helper functions, no grab-bag classes, no facades. Support, Enums, Data and Models never use Services, Queries, Actions or Jobs (arch test): a Data factory takes what it shows as arguments, the Query that builds it does the lookups. |
| `routes/` | `web.php` (Inertia) plus one file per area (`projects.php`, `backups.php`, …), `api.php` (public API, Sanctum), `daemon.php` (daemon API, signature middleware), `git.php` (including the webhook receivers), `channels.php` | Every group has explicit auth and authorisation middleware |

Domain names, used for sub-namespaces: `Teams`, `Servers`, `Projects`, `Environments`, `Services`, `Variables`, `Deployments`, `Builds`, `Domains`, `Git`, `Registry`, `Mesh`, `Daemons`, `Backups`, `Audit`.

### 2.2 Rules

1. **One way to do each thing.** Mutations go through Actions, whether from a controller, job, console command or API. Never mutate models directly from controllers or listeners.
2. **Typed boundaries.** Arrays never cross layer boundaries: use Data objects. JSON columns are cast to Data objects: a column holding an object shape (fixed keys, or a list or map of them) has one Data class (or a `DataCollection` of one), while a plain list or map of scalars (ids, features, labels, permissions, free-form audit meta) stays `array` with a precise `@property` type; the exceptions are values another owner re-validates on every read (pinned host keys, KDF parameters) and rows written in bulk through the query builder (log lines, advisories, inventories). PHPStan (Larastan) at **max level**, with no baseline growth.
3. **State machines are explicit.** Statuses are enums with a transition table, and transitions go through one method that validates, persists and emits an event: `HasStatusTransitions::transitionTo($to, $note)` (models add only `recordTransition()` for timestamps and notes and `transitioned()` for events; notes are capped at one `NOTE_LIMIT`; re-entering a status is an explicit self-edge in the table). No `->update(['status' => …])` outside it and no second `transitionTo` (enforced by arch tests). Named status sets live on the enum (`nonTerminal()`, `reachable()`) and as model scopes (`Server::notRevoked()`, `DaemonTask::open()`); queries use the scope instead of listing statuses. The control-plane server is `Server::controlPlane()` (flagged `is_control_plane` and not revoked; revoking, retiring or recovering away from a server clears the flag) and `Server::currentControlPlane()` (the newest such server).
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
- **Team scoping**: team-owned models use `BelongsToTeam`. Route parameters (`{server}`, `{enrollment}`, `{membership}`, `{invitation}`, `{team}`, `{project}`, `{environment}`, `{service}`, `{serviceInstance}`, `{variable}`, `{connection}`, `{domain}`, `{deployment}`, `{revision}`, `{volume}`, …) are bound in `App\Http\Routing\TeamScopedBindings`, the only binder: domain providers register their parameters there (`teamOwned()`, `teamOwnedOrInstance()` for rows instance owners also reach with `team_id` null, `define()` for other scopes), `routes/channels.php` resolves raw ids through `find()`, and every registered parameter gets its route pattern from the model's `publicIdPrefix()` (route files don't restate prefixes). The public id must be well-formed and the row must belong to one of the user's teams, otherwise 404. Nested parameters (and `?environment=` / `?service=` ids) are scoped to each other by `App\Http\Routing\RouteScope` for web and API controllers; a staged-removed service resolves, and changes to it are refused with 409 (`RouteScope::editable`). Policies then check the role in the *resource's* team; sub-namespaced models find their policy by Laravel's `App\Models\X\Y` → `App\Policies\X\YPolicy` convention, without `Gate::policy()` calls. List pages and team-wide settings use the session's current team (`users.current_team_id`), injected as `#[CurrentTeam] Team $team` (`App\Http\Routing\CurrentTeam`).
- **Role matrix**: `TeamRole::permissions()` is the single mapping from roles to `Permission` cases; policies only call `$user->hasTeamPermission($team, Permission::…)` / `TeamRole::canGrant()`.
- **Route coverage**: every route is public (reviewed list), a daemon route (`AuthenticateDaemon` + `SignResponse`, controller uses `AuthenticateDaemon::server()`), or `auth` + an authorization call in the controller action (`Gate::authorize`) / `can:` middleware / the reviewed self-scoped list.
- **Sensitive mutations** (add server, approve/revoke, invite, change roles) authorize against the signed-in session without a password-confirmation redirect (D60). Policies, scoped bindings, CSRF, throttles, audit logging and typed destructive confirmations still apply.
- **Domain refusals**: actions throw `App\Exceptions\DomainRuleViolation` (user-facing message + field + status). `bootstrap/app.php` renders it as a validation error (web), JSON (`expectsJson`) or `{"error": …}` with its status (daemon API).
- `Route::inertia()` is not used; routes use `Route::get(..., Inertia\Controller::class)->defaults(...)`, because the macro is untyped and PHPStan max rejects it.
- Fortify's `CreateNewUser`/`ResetUserPassword` are exempt from the single-`handle()` arch rule (Fortify dictates their names).

### 2.3b Domain code, protocol and secrets

- **Where pure logic lives**: `App\Scheduling` (placement), `App\Support\Changes` (`SpecDiff`, `FieldMeta`), `App\Support\Variables` (references, generators, resolver, `.env` parser, targeting, system variables) and `App\Support\Images`. Read models that assemble Data from several tables live in `App\Queries` (`CurrentEnvironmentSpec`). Integration boundaries with more than one implementation or a fake in tests are contracts in `App\Contracts` (`ImageDigestResolver`), bound in `AppServiceProvider`; a step with one implementation is a plain final class (`Services\Builds\BuildOrchestrator`, `Services\Deployments\ReleaseRunner`, `Services\Canvas\PlanPreviewer`).
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

  Some arch rules are source scans (`SourceRulesTest`: no `::find(`/`where('id'` in controllers, status changes only through transitions, no restated status sets, flash messages only through `Toast`) because Pest arch can't see method calls. Each rule was checked by injecting a violation.
- **Route coverage test** (`RouteCoverageTest`): every route is authenticated and authorised.
- **Fake daemon**: an in-process `FakeDaemon` (`tests/Support`) that consumes NodeStates and tasks and emits reports. Used to test the deployment state machine end to end without Docker.
- **Contract tests**: PHP-rendered NodeStates and task specs validate against `protocol/` JSON Schemas; Go fixtures in `protocol/fixtures` round-trip in both languages.
- **Scheduler**: property-style tests (anti-affinity holds, stateful services never move, capacity respected).
- `phpunit.xml` forces the `launchroom_test` DB and array/sync drivers via both `<env>` and `<server>`, so tests can never touch dev data, whichever shell runs them. Parallel workstreams override the database with `TEST_DB` (AGENTS.md). It sets `memory_limit=2048M` for the full suite in one process.

### 2.5 Page props and query budgets

Pages should stay fast without caches that make data stale:

- **Props a partial reload doesn't ask for cost nothing.** Anything beyond a few fields is a closure (`'nodes' => fn () => …`), so Inertia only evaluates it when the visit includes it. The canvas is the model: opening a service or switching tabs reloads `only: ['panel']` (`panelVisitOptions`), so the canvas graph and the top bar's lists (`ProjectShell::props`) are closures; when two props share one computation, memoise it inside the request (`$built ??= …`). The shell's keys and types are `ProjectShellPropsData` (a test checks they match), like `SharedPropsData` for the props in `HandleInertiaRequests`, which are closures for the same reason, and compute only what the sidebar shows (`OnboardingChecklist::progressFor` skips the checklist page's extras).
- **Build expensive read models once per request, explicitly.** `CurrentEnvironmentSpec` is not memoised globally (actions read it before and after a change); a read path that needs it in several places builds it once and passes it on (`CanvasGraph::for`, `StagedForService::has`, `ServiceSettings::for` take an optional `EnvironmentSpec`).
- **No N+1**: eager loads, `whereIn` batches and aggregate subqueries. Hot pages have **query budget tests**: the count is measured with `DB::enableQueryLog()` after a warm-up request, must not grow when services are added, and stays under a fixed upper bound (`tests/Feature/Canvas/CanvasQueryBudgetTest.php`, `ProjectCardDataTest`). Raise a budget only with a reason.
- **Index foreign keys you filter or eager-load by.** Postgres doesn't index them by itself; add the index in the migration that adds the column.
- `Inertia::defer()` suits a prop that is slow and below the fold; the pages measured so far didn't need one (each JSON prop is small; streams and history go through TanStack Query endpoints).

## 3. Frontend (React) conventions

```
dashboard/resources/js/
├── app.tsx                Inertia entry; the default layout (team pages)
├── pages/                 One file per Inertia page (route), thin: feature components + `Page.layout`
├── features/              Domain UI: canvas/, deployments/, variables/, servers/, domains/, log-explorer/, command-palette/
│   ├── app-shell/         The app chrome and layouts (team, project): they compose features (⌘K, banners, project top bar)
│   ├── settings/          The /settings layout and its page list (titles, routes, breadcrumbs, search index)
│   └── service-lifecycle/ Deploy, redeploy, restart, stop, start with their optimistic state and toasts
├── components/ui/         shadcn primitives (generated, lightly customised)
├── components/            Shared UI no feature owns (Status, CopyButton, CopyValue, TimeAgo, SettingsCard, ServiceGlyph, charts)
├── hooks/                 Cross-feature hooks (useLiveProps, useNow, useClipboard)
├── lib/                   Plain modules: http.ts (the JSON client), format.ts (sizes, durations, ages, plurals), live-reload.ts, pages.ts
├── types/generated.d.ts   Generated from PHP. Never hand-edit.
├── types/vocabulary.generated.ts  Enum words, tones, `isTerminal` (every transition table) and #[SharedFacts] per case (`php artisan launchroom:vocabulary`, drift-tested). Status sets (cancellable, unfinished, open) are read from here, never listed in TS. Never hand-edit.
├── types/grammar.generated.ts     Protocol grammars and limits from vocabulary.json (`php artisan launchroom:protocol-vocabulary`, drift-tested). Never hand-edit.
└── wayfinder/             Generated routes
```

Rules:
- **Dependency direction**: `lib/` ← `hooks/`, `components/` ← `features/` ← `pages/`. `components/`, `hooks/` and `lib/` never import `features/` or `pages/`, features never import pages, and features don't import each other in a cycle (lint rules in `vite.config.ts` enforce all three: `no-restricted-imports` and oxlint's `import/no-cycle`). Something two features share moves down into `components/` or `lib/` instead of one feature importing the other's internals. A page declares its layout (`Page.layout`); page names in code are typed (`PageName`, `lib/pages.ts`).
- **One of each**: JSON requests outside Inertia visits go through `requestJson` (`lib/http.ts`, errors are `RequestFailed` with the server's sentence); display formats come from `lib/format.ts`; query keys come from a key factory next to their query hook, used for the query and its invalidation alike.
- Pages receive **typed props** (generated types) and contain no data fetching beyond Inertia partial reloads and TanStack Query for streams and history.
- No business rules in the frontend (e.g. the build/runtime classification of a variable comes from the server).
- Optimistic updates through Inertia `router` options or TanStack mutations, always with rollback.
- Accessibility: keyboard-reachable canvas actions, focus management in panels, `prefers-reduced-motion`.
- **Heavy code loads with its first use**: libraries or tabs that only some visits need (elkjs, the console's xterm, the settings pages of the service panel) are `lazy()` / dynamic `import()`, so the page chunk stays small. Warm a chunk the user is likely to need next when the browser is idle (`whenIdle`, `warmPages` in `lib/idle.ts`): the panel warms Settings and the deployment page, `ProjectLayout` the project pages one click away, team pages the canvas. Inertia prefetches props, not page code, so without this the first visit of each page type waits for one more download. Check the build's chunk sizes when adding a large dependency.
- **Prefetch** links that are likely next (`<Link prefetch>`, `router.prefetch` with ~75 ms hover intent); a prefetched page is served until `cacheFor` expires (no revalidation on use), so links into live views use a short `cacheFor` and **cache tags flushed by realtime events** (deployment rows: `['5s', '1m']`, tagged per deployment and per service, flushed on `.deployment.updated`; hovered service panels: `3s`, tag `service-panel`, flushed on `.canvas.changed`). Start the data of the likely next view with the click, not after it: the canvas starts the Deployments query with the panel visit (`prefetchServiceDeployments`), never after the panel mounted.
- **No loading state the client can avoid** (docs/08 §2 "Speed"): a click shows its target at once from data already on the page, and the server fills in the rest.
  - *Draw from what you have*: the service panel renders from the canvas node while its request runs (`pending`, `features/service-panel/panel-target.ts`); the deployment page opens as an Inertia **instant visit** (`component` + `pageProps`, `DeploymentLink`) from the row when no prefetched copy exists. The drawn state reserves the space of what is still coming (no layout shift) and shows nothing it can't know (no "No output" before the lines arrive). The page remounts when the answer replaces it (`pending` in its key).
  - *Client-side visits where the server adds nothing*: closing the panel and switching to a tab that loads its own data are `router.push` with new props.
  - *Seed instead of refetch*: a full visit carries what the first view needs (`deployments` on the canvas page, built in a closure so partial reloads skip it), and the client seeds the query cache from it. One Data class for the prop and the JSON endpoint.
  - *Shared query cache*: data outside page props goes through TanStack Query, including the log explorer's first page, so coming back shows the last answer at once and refreshes behind it.
- **Realtime reloads are background visits**: `liveReload()` (`lib/live-reload.ts`) is `async`, waits while a user visit loads, is cancelled by a user visit that starts and runs again afterwards. A sync `router.reload` would cancel the user's click (or be cancelled by it and leave stale props). Props that change without a realtime event (a server joining, a move in progress) poll through `useLiveProps(only, { while, everyMs })` (`hooks/use-live-props.ts`), which uses `liveReload` and pauses while the tab is hidden. Every other interval that only runs while the tab is visible (socket-down polling in `ChannelEvents`, DNS and generation checks, log tails, the updates page) uses the same `schedulePoll`, which also ticks once when the tab becomes visible again. Never invalidate queries on every Inertia `success`: the staged-changes summary refetches after non-GET visits and on `.canvas.changed` only.
- **Optimistic lifecycle actions** (stop, start, restart, deploy, redeploy, roll back): `optimistic` visit option with `optimisticService` / `optimisticQueued` and `rollbackToast` (`features/service-lifecycle/optimistic.ts`), sent through the actions in `features/service-lifecycle/actions.ts`; confirmations close on the click. Inertia restores the props when the request fails; the toast says so calmly, without the error dialog.
- **Motion starts on the click**: a client-side change that should animate runs in `withViewTransition()` (`lib/motion.ts`); visits whose target is already drawn pass `viewTransition: false`.
- **Lazy server props**: page props that cost queries are closures (`detail`/`logs` on the deployment page, `panel` on the canvas), so partial reloads build only what they ask for. `InstanceSettings::current()` is read once per request (scoped container instance, forgotten on save and per queued job).
- `vp check` / ESLint / `tsc --noEmit` in CI (already in the starter kit's scripts).

## 4. Daemon (Go) conventions

```
daemon/
├── cmd/
│   ├── launchroomd/        main: wiring only (config, subsystems, signal handling, subcommands: update-supervisor, firewall remove, …); each subsystem contributes its own features to reports
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
│   ├── config/             configuration of launchroomd and dockerd-guard from env and flags; secrets only from env or file; every variable listed in daemon/README.md
│   ├── controlplane/       signed HTTP client for /daemon/v1, response signature verification, Retry/IsLeaseLost, the desired-state syncer
│   ├── cronexpr/           5-field cron parser and matcher for cron services
│   ├── dbengine/           per-engine facts shared by backups, imports, data sessions and remote access (tools, port, URL schemes, the service login from its env, identifier grammar)
│   ├── docker/             thin layer over the Engine API (through the guard): spec conversion, managed-label scoping,
│   │                       container kinds, volume ownership, the network owner, isolation before containers join a network, system-container lifecycle
│   │   └── dockerfake/     in-memory Engine client for unit tests
│   ├── enrollment/         persists the server id and trusted control-plane key set
│   ├── firewall/           nftables `inet launchroom` table (google/nftables), drift detection and repair
│   ├── guard/              dockerd-guard: route table, request parsing, container classes
│   │   └── guardapi/       endpoints and refusals shared by launchroomd and the guard
│   ├── health/             HTTP/TCP probes against container IPs, port discovery
│   ├── hostinfo/           host health facts for reports (disk, memory, load, limits, hardware) and the capability matrix as codes
│   ├── identity/           key material, RFC 9421 request signing, payload verification
│   ├── ingress/            what the reconciler tells the edge about same-server upstreams (Endpoint, Resolver)
│   │   ├── caddy/          route table → Caddy JSON, admin API client, Caddy system container, on-demand TLS ask handler
│   │   └── certstore/      reads Caddy's certificate storage, mirrors certificates, derives per-host status
│   ├── linkprobe/          signed latency/throughput probes between servers on <mesh IP>:7400
│   ├── logship/            follows workload container logs and ships them when the NodeState asks
│   ├── mesh/               the Tailscale client system container and its status
│   ├── names/              leaf: label keys and values, generated host-side names and their grammars (shared with dockerd-guard)
│   ├── metrics/            15 s host and workload samples, per-minute aggregation, buffered until reported
│   ├── nudge/              "poll now" nudges over Reverb
│   ├── pusher/             Pusher-protocol client (dial, framing, signed subscription, pings) for nudges and session relays
│   ├── policy/             local policy floor, shared with dockerd-guard ([03](03-daemon-protocol.md) §8)
│   ├── reconcile/          level-triggered reconciler: networks, volumes, workloads, system containers
│   ├── registry/           127.0.0.1:5000 forwarder to the launchroom registry, token handling, the reachability check (D68)
│   ├── sealed/             opens secrets sealed to this daemon's X25519 key
│   ├── secret/             string type that never leaks through logging, formatting or JSON; the log masker
│   ├── session/            interactive Console and Data sessions over Reverb ([03](03-daemon-protocol.md) §11)
│   ├── state/              v1 protocol types (NodeState, tasks, reports), the source of the JSON Schemas and vocabulary.json
│   ├── tasks/              lease loop, dispatch to typed handlers, heartbeats, task log shipping, run_once
│   │   └── taskstest/      in-memory control plane for handler tests
│   ├── traffic/            per-route request metrics from the edge Caddy's access log; owns the access-line schema Caddy renders
│   └── update/             signed self-update and the update supervisor ([11](11-installation-and-dev.md) §4)
│       └── updatetest/     throwaway-key release signing for tests
└── test/                   integration tests (dind), git test server, release signing helpers
```

Rules:
- No shell (`forbidigo` bans `sh -c`). Use API clients where possible.
- `context.Context` everywhere, with timeouts on every external call. No goroutine without an owner and a shutdown path.
- Structured logging (`log/slog`, JSON). Secrets are typed (`internal/secret`, with a redacting `LogValue`).
- Host capabilities are probed up front into host facts (`hostinfo`, buildkitd's AppArmor sysctls, Caddy's image labels); an error text decides only where nothing can be probed, and the code says why (BuildKit's I/O weight).
- Reconciler functions are pure where possible (`plan := Diff(desired, actual)` then `Apply(plan)`), unit-tested on the diff, integration-tested on apply.
- Fuzz the NodeState/task parsers, the signature verification path and the guard's request parsing (`internal/state`, `internal/identity`, `internal/guard`). Run `golangci-lint` and `govulncheck` in CI.
- Every version is reproducibly built, signed (cosign) and multi-arch (amd64, arm64).

## 5. Protocol (`protocol/`)

- **Source of truth: Go types** in `daemon/internal/state` (and task specs). `go generate` runs `cmd/schemagen`, which emits JSON Schema (invopop/jsonschema) into `protocol/schema/v1/*.json` for every entry of `state.Documents()`, plus `vocabulary.json` (`state.ProtocolVocabulary()`: named grammars, forbidden mount targets, limits, constants, and which field, task or enum value needs which daemon feature, from `lr:"feature=…"` struct tags; D79).
- **Limits and enum lists are stated once per side.** A Validate limit equals the field's `jsonschema` tag (`TestLimitsMatchSchema` reads both from the source) and every enum constant is in its value list (`TestEnumListsComplete`), so the published schema says what launchroomd enforces.
- **Values are generated, shapes are mirrored and checked.** The dashboard never re-types a grammar, limit or protocol constant: `make gen-types` runs `launchroom:protocol-vocabulary`, which turns `vocabulary.json` into `App\Support\Protocol\Grammar` (anchored PCRE with `/D` plus an unanchored `_BODY` per grammar, usable in `#[Regex]`), `App\Support\Protocol\Limits` and `resources/js/types/grammar.generated.ts`; a Pest test fails when they are stale. Dashboard owners with their own rules on top (`PublicId`, `VariableKey`, `VolumeMountRules`, `Slug`) build on those constants, and everything else uses the owner. The PHP Data objects in `App\Data\Protocol\V1` are written by hand (about 80 classes, too many to call small, but their validation attributes and types say what the dashboard accepts, which generation would hide): they use the PHP enums, every mirror enum is compared with the schema by `EnumContractTest`, CI validates PHP output against the schemas, and every Go fixture in `protocol/fixtures/` round-trips through them.
- **Rules ported to PHP run the same vectors as Go.** `NodeStateValidator` (the semantic port of `NodeState.Validate()`, [03](03-daemon-protocol.md) §5) is tested with every valid `node-state.*.json` and every `protocol/fixtures/v1/invalid/*.json` with its expected problem paths, which the Go tests assert too. Cron, session frames, dump sniffing, dotenv parsing and the config file size share fixtures in the same way (`protocol/README.md`).
- launchroomd reports codes, not sentences, for everything the dashboard explains (build hint codes, port hints, session/access start failures, self-update rollback, capability reasons); the dashboard maps codes to its own words.
- Breaking changes create `v2` schemas. The dashboard renders for each daemon's highest supported version ([03](03-daemon-protocol.md) §10).

## 6. CI gates

`.github/workflows/ci.yml` runs on every push and pull request. A `changes` job decides which areas changed; jobs of untouched areas are skipped, and `ci-ok` (the check to require) passes when every job passed or was skipped. A newer push cancels the running check of the same branch or pull request.

| Job | Runs when | Checks |
|---|---|---|
| dashboard-static | `dashboard/`, `protocol/`, the daemon installer files change | `composer ci:static`: Pint, Rector (dry-run), PHPStan max + disallowed calls, `vp check`, `tsc`; generated TS types up to date; asset build. Pint/Rector/PHPStan result caches are kept between runs |
| dashboard-tests | as dashboard-static | `composer test`: Pest (unit, feature, arch, route coverage) in parallel, one Postgres database per worker |
| daemon | `daemon/`, `protocol/`, `packages/next-adapter/` change | `go test -race` |
| daemon-lint | as daemon | golangci-lint (incl. forbidigo), govulncheck of the shipped binaries (`./cmd/...`) |
| cli | `cli/`, the OpenAPI document (`dashboard/resources/api/openapi.json`) or `protocol/fixtures/cli/` change | `go test`, cross-compiled release targets, golangci-lint, govulncheck |
| protocol | as daemon | schema generation is up to date (no drift in `protocol/`) |
| deploy | always | `make lint-deploy` (shellcheck, installer regions match `deploy/install/lib`, pin copies match `deploy/pins.env` and `images.env`, release Dockerfiles pinned by digest), the installer unit tests (`make test-server-sh test-install-sh test-cli-sh`), bundle builds with digest pins and its `manifest.json` |
| next-adapter | the package or its vendored copy changes | build, typecheck and test; vendored copy in the daemon matches the build; package contents |
| site | `site/` or the files it copies change | typecheck, lint, format, build; committed installer, schema and device art copies are current |
| workflows | always | actionlint |
| audit | always | `composer audit` (advisories fail; abandoned packages are reported), `npm audit` |

Every workflow change runs everything.

`.github/workflows/release.yml` (tags `v*`) builds signed images and bundles (cosign), SBOM, provenance and the CLI binaries ([09](09-security.md) R30). The integration suite (dind) and the install/update end-to-end tests run locally through `make` ([11](11-installation-and-dev.md)).

## 7. Docs discipline

- Architectural changes update `docs/` in the same PR. The decision log in `docs/README.md` gets a new row, or a status change.
- `AGENTS.md` (and `CLAUDE.md`, which includes it) at the repo root summarises these rules for AI assistants and contributors. It points here rather than duplicating.
