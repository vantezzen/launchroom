# 04: Build & deploy

Deployment is the product. This doc defines how a commit becomes a healthy, routed set of containers.

## 1. Service kinds

| Kind | Source | Built? | Examples |
|---|---|---|---|
| `app` | Git repo (+ root directory) | yes | Next.js, Laravel, Django, Rails, Go API, Vite SPA |
| `image` | Docker image reference | no (mirrored/pulled) | `ghcr.io/umami-software/umami:postgresql-latest` |
| `database` | Engine driver | no | Postgres, MySQL, Redis, Valkey, MongoDB, ClickHouse |
| `worker` | Same source and image as an `app` service, different command | reuses the app's image | Laravel queue, Celery, Sidekiq |
| `cron` | Same source/image as an `app`, a schedule and a command | reuses the image | `php artisan schedule:run` alternative, nightly jobs |

An image service's reference can be changed (Settings → Source → Change image, `PATCH …/s/{service}/image`, `ChangeServiceImage`, audited `service.image_changed`): same grammar as when adding (`ImageSource`), from a registry the mirror serves (docker.io, ghcr.io), a tag or a digest. The source is project-wide (docs/06 §4), so every environment shows it as a staged change and runs it after its next deploy; Discard restores it. The kind never changes: an image service can't become a repository build or the other way round (the kind decides build settings, plan previews and workers), so that is a new service.

**Cron schedules** (D51): a cron service's `deploy.schedule` is a 5-field cron expression (minute hour day month weekday; numbers, `*`, ranges, lists and steps; no names or macros; day of week 0–7; when both day fields are restricted either matches, as in Vixie cron) read in an IANA time zone (default UTC). The dashboard renders it as `WorkloadSpec.schedule {cron, timeZone}` with restart `no` and no port/health (daemon feature `cronSchedule`); launchroomd creates the container **without starting it** and its own minute loop starts it at every due wall-clock minute in that zone, also while the dashboard is unreachable. A minute repeated when clocks go back runs once, one skipped when they go forward doesn't run; a run still going when the next is due makes that one skip (logged); missed runs while the server was down aren't caught up. Between runs the container is `created`/`exited`, which counts as healthy; the last run's exit code is in the workload status. Without a schedule (or on a launchroomd without the feature) a cron runs its command continuously like a worker. The grammar is the same on both sides (`App\Support\Cron\CronGrammar`, `daemon/internal/cronexpr`, one test list).

Workers and crons **share the image build of their parent app**. One build feeds the web, queue and scheduler processes, which matters for Laravel and Django. On the canvas they are separate nodes linked to the parent ("process of api").

## 2. Builder resolution

Evaluated on the build server after cloning, for each `app` service:

1. **Explicit choice** (service settings, or the service's `build` in `launchroom.json`, [18](18-config-as-code.md)): `dockerfile | railpack | static`, plus overrides.
2. **Dockerfile present** at the configured path (default `<root>/Dockerfile`, `Containerfile`): use it, labelled "Building with your Dockerfile". If it looks dev-only (`CMD` runs `npm run dev`, `vite`, `artisan serve`, `runserver`; or the file is named `*.dev`), warn (`dockerfile-dev-only`).
3. **Preset + Railpack**: a launchroom preset matches (§3) and augments the Railpack plan.
4. **Plain Railpack**.
5. **Nothing works** (Railpack can't plan): show the Railpack output and detected facts, then offer: set build/start commands, or (opt-in) **Generate a Dockerfile**: launchroom drafts one with a model, lints it, builds it and starts the app once before offering it, and stores it in launchroom as the service's build override ([20](20-dockerfile-generation.md), D48).

**launchroom never silently switches builders.** Alternatives are offered, never auto-tried.

Railpack is driven through its **BuildKit gateway frontend** against launchroom's own `buildkitd` using the BuildKit Go client. No docker CLI or buildx. The Railpack version is part of the build inputs; launchroomd links one Railpack version and refuses specs that pin another (§12).

## 3. Presets

A preset is versioned Go code in launchroomd: `func(repoFacts, railpackInfo) PresetResult`, where `PresetResult = {railpack env overrides, railpack.json overlay, runtime env defaults, extra processes, release command, healthcheck default, warnings}`. Presets **compose with Railpack** rather than replace it. Each preset decision is shown in the UI as a removable chip. Presets exist where Railpack's defaults aren't production-grade:

| Framework | Preset behaviour |
|---|---|
| **Next.js** | Next ≥ 16.2: the deployment adapter [`@vantezzen/launchroom-next-adapter`](../packages/next-adapter/README.md) is injected through `NEXT_ADAPTER_PATH` without touching the repo (§12.1). It forces `output: 'standalone'`, sets `deploymentId` = the deployment id, pins `outputFileTracingRoot`, copies `public/` and `.next/static` next to `server.js`, and reports a manifest (routes, middleware, image optimization, ISR routes) shown with the build. The image contains only `.next/standalone` and the Node toolchain; start `node .next/standalone/server.js`. A user `adapterPath` wins (warning `own-next-adapter`, standalone handling below). Older Next with `output: 'standalone'`: an overlay copies the static assets and starts with `node .next/standalone/server.js`. Otherwise keep `next start`. Always set `HOSTNAME=0.0.0.0`, `PORT` and `NEXT_TELEMETRY_DISABLED=1`. Warn when `NEXT_SERVER_ACTIONS_ENCRYPTION_KEY` is missing (it is a build variable) and that ISR and `revalidateTag` stay per replica when replicas > 1 (no shared cache handler). |
| **Nuxt / SvelteKit / Astro / React Router 7 / TanStack Start / Remix** | Mostly Railpack defaults. Validate the adapter (warn on `adapter-static`/`-vercel` for SSR, Astro server output without `@astrojs/node`). Set `HOST=0.0.0.0` and `PORT`. |
| **Static SPA** (Vite, CRA, Angular, Astro static, Next export) | Railpack + Caddy static serving. The UI exposes "SPA fallback" and "output dir". |
| **Laravel** | `RAILPACK_SKIP_MIGRATIONS=true`. Migrations become the **release command** `php artisan migrate --force`. Suggested processes: queue (`queue:work`, or `horizon` if installed), scheduler (`schedule:work`), reverb if installed. Run `optimize` at container start, not build, so secrets don't land in `bootstrap/cache`. Offer "Generate APP_KEY". Default health path `/up`. |
| **Django** | `collectstatic --noinput` at build (needs `STATIC_ROOT`, otherwise hint whitenoise). Start: `gunicorn <wsgi> --bind 0.0.0.0:$PORT` (uvicorn for ASGI/channels). Migrations become the release command. Celery worker/beat processes suggested when present. |
| **FastAPI / Flask / Litestar** | Locate the app object (`main.py`, `app.py`, `app/main.py`, `src/*/main.py`) and set the start command. Use `fastapi run` when `fastapi[standard]` is installed. |
| **Rails** | Rails ≥ 7.1 ships a production Dockerfile, so step 2 handles it. Otherwise Railpack Ruby. Release command `bin/rails db:prepare`. Sidekiq/SolidQueue processes suggested. |
| **Node servers** (Express/Fastify/Hono/Nest), **Bun, Deno, Go** | Railpack defaults. Check the `PORT` contract (warn on hard-coded `listen(3000)`). |
| **Monorepos** (pnpm/yarn/npm workspaces, Turborepo, Nx) | The import flow lists workspace apps and the user picks one per service. The build context stays at the repo root; `turbo run build --filter=<pkg>...` / `pnpm --filter`; per-service **watch paths** default to the package and its workspace deps. |

### 3.1 PHP database drivers for connected databases (D71)

A PHP app written for SQLite has no `ext-pdo_pgsql` in `composer.json`, so Railpack's FrankenPHP image lacks the Postgres driver and the app fails at its first query (often the release command `php artisan migrate --force`). Railpack can only infer drivers from `DB_CONNECTION` at plan time, and launchroom plans with runtime variables absent. launchroom knows the connections, so it asks for the drivers itself, without touching the repository:

- **Which apps**: a built service that runs its own image (not a worker or cron, which run their app's image) and is a PHP app: its last plan detected Railpack's `php` provider, or one of its connections uses Laravel names. The connections of its processes (workers, crons) count for it, since they run the same image.
- **Mapping** (`App\Support\Builds\PhpExtensions`): Postgres → `pdo_pgsql`, MySQL → `pdo_mysql`, Redis and Valkey → `redis` (phpredis, Laravel's default client; with predis in `composer.json` Railpack installs it anyway), MongoDB → `mongodb`. ClickHouse has no PHP driver. Unique by name, sorted, each attributed to the first engine that needs it.
- **Spec**: `ServiceSpec.phpExtensions` (derived in `CurrentEnvironmentSpec`, stored with every revision) → `BuilderSpec.phpExtensions: [{name, for}]` in build and plan tasks (≤ 8, allow-list `pdo_pgsql pgsql pdo_mysql mysqli redis mongodb`, `for` = a protocol engine; checked by the dashboard and by launchroomd). Daemon feature `phpExtensions`: servers without it never lease such a task (strict decoding).
- **Railpack builds**: launchroomd appends the names to `RAILPACK_PHP_EXTENSIONS` (after any value the user set in `railpackEnv`), Railpack's own setting for its PHP provider, which installs them with `install-php-extensions` in its `extensions` step. Other providers ignore it. When the provider is `php`, the build log says "Adds PHP extensions: pdo_pgsql (for postgres)" and `summary.phpExtensions` lists them; the detected panel shows the same line.
- **Dockerfile builds** stay the user's responsibility: when the checkout has a `composer.json`, the build gets the warning `dockerfile-php-extensions` ("This Dockerfile may need the PHP extensions pdo_pgsql (for postgres) …").
- **Build key and staged changes**: the set is build-affecting (§7). Connecting a new database to a PHP app adds a staged item `services.<id>.phpExtensions` with effect `rebuild` and the label "api: adds the PostgreSQL driver (pdo_pgsql)". The build key includes the set only when it is non-empty, so keys of services without extensions are unchanged. Existing PHP apps with database connections show this rebuild once after the upgrade.
- **FrankenPHP needs NET_BIND_SERVICE**: Railpack's PHP image runs FrankenPHP, whose binary carries the file capability `cap_net_bind_service`. Workloads drop every capability, and the kernel then refuses to exec it (`exec: frankenphp: Operation not permitted`), so no Railpack PHP app could start. The dashboard adds `NET_BIND_SERVICE` (in launchroomd's default allow-list, docs/03 §8) to app workloads whose build summary says builder `railpack`, provider `php` (`NodeStateContent::appCapabilities`); other apps keep only the capabilities they asked for.
- **Laravel config**: the Laravel connection style injects `DB_CONNECTION=pgsql` (or `mysql`, `mongodb`) and `DB_HOST`/`DB_PORT`/`DB_DATABASE`/`DB_USERNAME`/`DB_PASSWORD` as runtime variables ([06](06-variables-environments.md) §3.2). Laravel's `.env` loader never overrides a real environment variable, and `.env.example` is never loaded, so an app whose `.env.example` says `sqlite` uses Postgres. The preset caches config at container start, with these values.

## 4. Plan preview ("we detected…")

A **`plan` task** (clone + detect + Railpack plan, seconds) runs when a service is created, when an instance's build settings or branch override change, and on "Detect again" (§13):

> **Next.js 16.1 (App Router) · pnpm 9.12 · Node 22.11 (from `.nvmrc`)**
> Install `pnpm install --frozen-lockfile` · Build `pnpm build` · Start `node .next/standalone/server.js` · Port **3000**
> Build-time variables: `NEXT_PUBLIC_API_URL`, `SENTRY_AUTH_TOKEN` (secret) · Runtime-only: 6 others
> ⚠ `NEXT_PUBLIC_STRIPE_KEY` is referenced in code but not defined.
> [Edit commands] [View plan JSON] [railpack.json]

Data comes from Railpack's info output (detected providers, resolved package versions *and where each came from*, metadata, start command), the preset annotations, and a static scan for env var references (`process.env.X`, `import.meta.env.X`, `env('X')`, `os.environ`).

User edits become stored overrides (Railpack env or overlay entries). They are never written to the repo. **Eject** is a download of `railpack.json` with the effective build settings (overrides, else the detected values) from Settings → Build, for the user to commit to the service's root directory. Eject to a Dockerfile is not implemented; see the [roadmap backlog](12-roadmap.md#backlog).

## 5. Build execution

Summary; details in §12 (launchroomd) and §13 (dashboard).

- **Where**: a server with the `build` role. Selection is sticky per service (BuildKit mount caches are local), then least-loaded (§5.1). On a single server, the local daemon has `build` by default.
- **BuildKit**: `moby/buildkit:rootless`, started by launchroomd on demand as a system container, using the seccomp/apparmor `unconfined` options rather than `--privileged`. There is no rootful mode. `buildkitd.toml`: `max-parallelism`, GC with `reservedSpace`/`maxUsedSpace`/`minFreeSpace`, and keepDuration filters for mount caches.
- **Concurrency**: build slots per server (default `max(1, cores/4)`), a container-level CPU/memory budget on buildkitd (§5.2), a per-build timeout (default 2 h, `LAUNCHROOM_BUILD_TIMEOUT`), and cancel by solve context (never `pkill`). Builds wait on the dashboard until a slot is free; their deadline starts when leased. Plan tasks have their own single slot, separate from builds and other tasks.
- **Clone**: a repository-scoped token (GitHub App installation token, or the connection token of other providers) used as HTTP Basic auth in memory, never in the URL or on disk. Fetch the **exact SHA** (works for fork PRs).
- **Secrets**: build-scoped variables go in as BuildKit secrets, never build args. The exception is Dockerfile builds that declare a matching `ARG` for a non-secret var. A `secrets-hash` over **build-scoped vars only** busts the cache exactly when needed, and a `cache-key` = service id namespaces mount caches.
- **Cache**: registry cache export (`mode=max`) to `cache/<service>`, plus local mount caches.
- **Output**: push `apps/<service-uuid>:<deployment-ulid>` with SBOM and provenance attestations. Record the digest. Never tag `latest`.
- **Mirrors**: the Railpack frontend, builder and runtime images, and common base images are mirrored into Zot (pull-through `mirror/*`), so GHCR or Docker Hub outages and rate limits don't break builds. For mise/GitHub rate limits, set `GITHUB_TOKEN` as a build variable.
- **Logs**: BuildKit's `SolveStatus` stream is mapped to Railpack step names (install / build / …), giving a collapsible, step-structured log UI. Secret values (and base64/URL-encoded forms) are masked before shipping.

### 5.1 Build routing

Code: `App\Scheduling\BuildRouter` (pure, unit-tested), `App\Services\Builds\BuildRouting`, `App\Actions\Daemons\ReleaseStuckBuildPins`.

- **Platform**: an image is built for one platform. It is the platform of the online runtime servers the service may run on (its placement constraints and environment kind), keeping the current deployment's platform while its servers can still run it, else the most common one (ties: the lowest server id), skipping platforms no online build server can build. A build server can build its own platform **and the ones its engine emulates** (`Report.host.runtime.buildPlatforms`: what BuildKit's workers list, e.g. an Apple Silicon Mac on OrbStack or Docker Desktop lists `linux/amd64` through Rosetta/QEMU; launchroom never installs binfmt handlers, D53); builders that build the platform natively are chosen first, emulated ones only when no native one is online. The task's `spec.platform` is recorded as `deployments.plan.platform`, and the scheduler places the image **only on servers of that platform**; image reuse (§7) only matches deployments of the same platform (older ones without a recorded platform match). No build server for any target platform → the deployment fails with `config_error`: "No online build server can build for linux/arm64 … Add a build server with that architecture, or restrict the service to servers of a platform you can build for".
- **One platform per deployment**: one deployment has one image digest. A multi-platform image would need a build task per platform plus a manifest-list merge (launchroom uses emulation only where the server's engine already offers it, D53). Mixed fleets therefore run each service on one platform at a time; constraints (labels, allow-lists) choose which. Multi-platform builds are in the [roadmap backlog](12-roadmap.md#backlog).
- **Server**: the sticky server (built the service last: BuildKit mount caches are local) when it is online, of the platform and has a free slot; else the least-loaded build server of the platform with a free slot (running build/plan tasks ÷ slots, slots = `max(1, cores / 4)`); when every one is busy, the sticky server if it can build the platform, else no pin (the pool: the first build server of the platform with a free slot leases it). Plan previews build nothing and use the team's majority platform.
- **Pins never wait forever**: a pending pinned `build`/`plan` task whose server is not `online`, or that nobody leased within `launchroom.builds.pin_release_seconds` (90 s), goes back to the pool of the platform's build servers (and they are nudged). A build whose lease expired on a dying server is re-queued pinned, then released the same way. Release, seed, backup and update tasks are never unpinned.
- **Home build servers behind NAT** push through their local registry forwarder (`127.0.0.1:5000`) to the control plane's registry on its mesh IP (`system.registry.forwardTo`, [05](05-networking-ingress.md) §2).

### 5.2 Builds on small servers (D59)

`internal/build/buildkitd.PlanResources` computes a local budget from Docker's host capacity, `/proc/meminfo`, current BuildKit usage and the declared workloads. Nothing in a task can override it; `LR_BUILDKIT_MEMORY_MB` and `LR_BUILDKIT_CPUS` remain host-owner overrides.

- The reserve floor is OS memory (256 MB + 1/32 of RAM, capped at 1 GB), plus 1 GB on a control-plane server and up to 256 MB per workload. Reserve the larger of that floor and measured non-build memory, then leave another `max(256 MB, RAM/16)` of headroom. Builds get the remainder, rounded down to 128 MB, at most 75% of RAM. A 512 MB minimum lets small builds run; on an overcommitted host the build may still fail safely. A 2 GB control-plane host typically gives builds 512 MB of RAM.
- Swap is bounded separately: at most 75% of the host's existing swap and twice the build RAM budget. No swap is created by launchroomd. Both installers offer an opt-in 2 GB swap file on hosts below 4 GB with no swap ([11](11-installation-and-dev.md) §2.1c); self-updates never make host changes.
- CPU shares 64 (Docker default 1024), block I/O weight 10, OOM score adjustment +1000. One- and two-CPU hosts also cap builds at `cores − 0.25`. BuildKit's parallelism is at most one step per GB of build RAM, at least one. These limits cover the build processes inside the rootless BuildKit container.
- Small or memory-constrained Railpack Node builds get build-only `NODE_OPTIONS=--max-old-space-size=<75% of RAM+swap>` and `RAYON_NUM_THREADS` derived from the CPU and memory budget. Explicit variable values and plan settings win. Dockerfiles keep their own environment and receive the same container limits.
- Budget changes settle across small measurement fluctuations and wait until no build is running before recreating BuildKit. The setup log names the applied limits; the server's Builds capability explains a small host without swap. Rootless Docker still needs cgroup v2 delegation for limits ([11](11-installation-and-dev.md) §3.2).

Limits protect the host; they cannot make every app fit on it. An app that needs more memory than RAM plus swap fails with the existing out-of-memory explanation. A separate build server remains the way to build larger apps.

## 6. Deployment state machine

A **Deployment** is one service instance moving to a new (image, resolved config) pair. Each step is persisted (`deployment_steps`), so the UI shows a timeline, retries can resume per step, and cancellation is clean.

```
queued ─▶ building? ─▶ releasing? ─▶ rolling_out ─▶ health_checking ─▶ ready? ─▶ routing ─▶ active
   │          │            │              │                │              │
   └──────────┴────────────┴──────────────┴────────────────┴──────────────┴──▶ failed | cancelled
queued | building ─▶ superseded (a newer deployment of the same instance collapses older ones)
active ─▶ superseded (a newer deployment went live)
```

- `ready` happens only for staged deployments (`hold_traffic`, [06](06-variables-environments.md) §6.5): healthy and running next to the live version, not routed until "Promote"; cancellable (its workloads are removed).
- `building` is skipped when the image is reusable (same commit, same build inputs; see §7). `releasing` happens only when a release command is configured.
- **Steps shown from the start** (`App\Support\Deployments\ReleaseStep`, `stepRows` in `features/deployment-detail/steps.tsx`): the UI lists every step a deployment will go through before it gets there, so none appears out of nowhere. The release command is often only known from the build summary, so "Release" is listed from the start when it is configured (`deploy.releaseCommand`), when the deployment's build summary has one, or when the service's plan preview found one or detected Laravel (an app service). When the deployment moves past it without one (the build revealed none), the step reads Skipped. Restarts, starts and rollbacks don't list it (they never run it).
- **Failed and cancelled deployments are kept.** Nothing deletes them; a failed first deployment leaves the instance without an active deployment but keeps its row, steps, failure and logs. The service's Deployments tab shows it as the expanded first row with "Try again" ([08](08-dashboard-ux.md) §13). The environment's applied revision is set when the deploy starts and never reverted by a failure, so a failed first deploy doesn't turn the layout back into staged changes.
- Orchestration is **event-driven**: daemon reports and task completions dispatch `AdvanceDeployment(deploymentId)`, which locks the row, checks the current step against reality, and moves forward (§11). Slow lookups the next step needs (image tag → digest through the registry, branch → head commit through GitHub or the git remote) run **before** the lock (`AdvanceDeployment::prefetch`, `BuildOrchestrator::lookupHead`); under the lock the deployment is re-checked and the result is used only if still needed (the in-lock lookup remains as a fallback), so no HTTP call holds the row lock or a transaction open. No long-running "god job". Every transition goes through one `DeploymentStatus` transition table that enforces valid edges and emits events (`DeploymentStatusChanged`). Listeners update GitHub, notifications and broadcasts.
- **Concurrency**: at most one in-flight deployment per service instance. A newer push **supersedes** queued ones (collapse to latest) and cancels in-progress builds of the same service instance when they're on an older commit.
- **Imports and restores win** ([14](14-backup-and-restore.md) §5.1, §12.5; `App\Services\Backups\DataHolds`): while an import or an in-place restore holds a service, its workloads are out of the desired state, so a rollout of it could never finish. A deployment of a held service that has started (`building` to `health_checking`) is **cancelled** with the reason, "The deployment was cancelled because postgres is being imported. Deploy again once the import has finished." (stored as the summary line, the running step ends `skipped` with it, cleanup as for a failure: new workloads removed, drained replicas serve again). A deployment that is still `queued`, or created while the hold lasts, **waits in the queue** and starts once the hold ends (the hold's start and end advance them right away; the sweep does too). `ready` and `routing` deployments are left alone: their workloads come back with the hold's end. Rejected: refusing to import while a deployment runs (a rollout that hangs can't be cancelled from `rolling_out`, so the import could never start), and pausing a rollout (its health deadline and pulls would have to survive an import of unknown length). Server moves (§13 of 14) redeploy the services they hold themselves and are not counted.
- **Failure classification** (shown prominently, with hints):
  - `plan_error` (Railpack could not plan)
  - `build_error` (failing step, last 50 lines highlighted, known-error hints like `ERR_PNPM_OUTDATED_LOCKFILE`, missing PHP extension, node-gyp toolchain)
  - `transient` (registry or network; auto-retried once)
  - `release_error`
  - `runtime_error` (container exits or keeps crashing; its exit code and last lines, §8.1)
  - `port_mismatch` ("listens on 5173, not 3000. Use 5173?")
  - `health_timeout`
  - `config_error` (broken image reference, missing digest, unresolvable branch, invalid desired state)
  - `placement_error` (no eligible runtime server, capacity, pinned volume's server unavailable)

## 7. Image reuse and "build once, promote everywhere"

Every config field is classified **build-affecting** or **runtime-only**:
- Build-affecting: commit SHA, root dir, builder choice and overrides, Railpack version, build-scoped variable values (via the secrets hash), target platform, the PHP extensions added for connected databases (§3.1).
- Runtime-only: runtime variables, replicas, resources, domains, healthcheck, start command (for Railpack-built images the start command is in the image, but launchroom overrides it at container create).

The build key is the hash of the build-affecting inputs. If an image with that key exists, the deployment **skips the build**. Consequences:
- Changing a runtime variable means a fast redeploy of the same image. The Deploy button summarises "web: rebuild · api: restart".
- Promoting a staging deployment to production reuses the image, unless the two environments differ in build-scoped variables (e.g. different `NEXT_PUBLIC_API_URL`). Then each environment builds its own image and the UI says why.

## 8. Rollout, health, rollback

- **Rolling update** per service: max-surge 1, max-unavailable 0. Start the new replica, wait for the health probe, add it to the upstreams, drain an old one (default 10 s, configurable, SIGTERM then SIGKILL after the grace period), repeat.
  - Applies to stateless services with more than one replica that replace serving ones and aren't staged (`hold_traffic`). Single replicas, first deploys, staged deployments and stateful services start all their replicas at once (stateful: stop-then-start).
  - One new replica is placed at a time (`AdvanceDeployment`), and the scheduler may put it on a different server than the old ones (anti-affinity against the new replicas already placed).
  - While the deployment is `health_checking`, its **healthy** replicas are routed next to the old ones (`NodeStateContent`; local workload upstreams or mesh addresses through `placements.mesh_port`), so traffic shifts replica by replica. Once every new replica so far is healthy, as many old replicas drain as new ones are healthy (old ones on the servers that now run new ones first), and the next new replica is placed. The health deadline applies per replica (`health` step meta `slotStartedAt`).
  - When the whole new set is healthy, routing switches and the remaining old replicas drain. A failure removes the new replicas and puts the drained old ones back to serving (their containers are kept until `drainUntil`; launchroomd restarts a stopped one).
  - Scaling (replicas, limits, constraints) is a `restart` change: the existing image is redeployed this way, never rebuilt.
- **Health probe** (daemon, from outside the container): HTTP GET on the configured path (default `/` for web; `/up` for Laravel preset; any 2xx/3xx), or TCP connect for non-HTTP. Start period, interval, timeout and failure threshold are all configurable. A Dockerfile `HEALTHCHECK` is respected as an extra signal. Images don't need curl.
- **Stateful services** (volumes): no overlap is possible (two Postgres can't share a data dir), so they **stop-then-start** with an explicit "brief downtime" note.
- **Workers/crons**: no health probe by default; "running for N seconds without exiting" counts as healthy.
- **If something fails after the new version became healthy**, keep the new version rather than tearing down the only healthy one.
- **Instant rollback** (`RollbackDeployment`): creates a new `rollback` deployment from a `superseded` one with its image, revision and env snapshot (re-encrypted under the new deployment id). No rebuild, and the release command doesn't run again. After a production rollback, **auto-deploy is paused** for that environment (`auto_deploy_paused`) with a banner ("Production is rolled back. New pushes won't go live. [Resume]"). Rollback warns that database migrations are not reverted.
- **Retention**:
  - *Registry* (`dev/zot/config.json`, `deploy/images/zot/config.json`, identical policies): `apps/**` keeps the 10 most recently pushed tags (each build is tagged with its deployment id), `cache/**` the last 2, `mirror/**` what was pulled within 30 days; untagged manifests and referrers (SBOM, provenance) go with them, after a 24 h delay; GC every 6 h. The count is per repository, i.e. per service, and counts every pushed build, also of failed deployments.
  - *Rollback targets* (`launchroom:registry:check-retained-images`, hourly, `App\Services\Registry\PrunedImages`): for superseded and active deployments with a built image (`apps/…@sha256:…`), `HEAD /v2/apps/<service>/manifests/<digest>` with a one-minute dashboard pull token; a `404` sets `deployments.image_pruned_at`. Such deployments are not rollback targets (`DeploymentPolicy::rollback`, `RollbackDeployment`; the UI's `canRollback` is false). Only a definite 404 counts; registry errors change nothing. Mirrored upstream images are not checked (they can be re-fetched by digest).
  - *Servers* (daemon `internal/cleanup`, daily, first run ~15 min after start, and under disk pressure ≥ 85 % at most every 30 min; result in the server's health report):
    1. Images: only images whose **every** reference is under the local registry `127.0.0.1:5000/` (built apps and mirrored upstream images launchroomd pulled). Per repository (= per service, or per upstream image), images used by any container or by a workload of the current NodeState stay, as do the newest 2 of the rest and anything created within the last hour (a `run_once` may have pulled it before it appears in a NodeState). The rest is removed by reference, never forced (Docker refuses images a container still uses). Images with any other reference (system images, anything not launchroom's) are never touched. Built images carry no launchroom label, so the registry reference decides what is launchroom's.
    2. BuildKit cache (build role): `Prune` with keep-duration 7 days and 10 GB reserved (disk pressure: 24 h and 2 GB), never `--all`. buildkitd's own GC (60 GB max, 10 GB reserved, 15 % min free) keeps running in between.
    3. One-off containers `lr-run-*` (label `launchroom.task`) that are exited/dead/created for more than 15 min (left by a daemon crash mid-task).
    4. **Volumes are never pruned**, and nothing outside launchroom's labels and registry is touched (no `system prune`).

### 8.1 Service lifecycle and rollout diagnosis (D68)

Code: `App\Actions\Services\{StopService,StartService,RestartService}`, `App\Actions\Deployments\DeployLatest`, `App\Services\Deployments\ImageRerun`, `App\Support\Deployments\{RolloutStatus,RolloutProgress,LifecycleLines}`, `App\Actions\Servers\RecordWorkloadEvents`; launchroomd `internal/reconcile/{lifecycle,pulls}.go`, `internal/logship` (startup output).

**Actions** (service panel header, environment `deploy` permission, audited):

| Action | What happens |
|---|---|
| Deploy | Repository services: the latest commit of the branch the instance tracks (`DeployRef`, trigger `manual`). Image services: the tag is resolved again (`RedeployService`). With staged changes for the service, the button is the environment's **Deploy changes** (the existing flow; `DeployLatest` refuses otherwise). Databases, workers and crons have no version of their own: Restart; until anything of them runs (a failed first deploy), Deploy runs the last applied revision again (`RedeployService`). Deploy is always offered, also while nothing runs: it is how a failed first deploy is tried again. |
| Restart | A `restart` deployment of the live one (`ImageRerun`: same image, revision, build summary and environment; no build, no release command). The normal rollout applies: several stateless replicas roll one at a time behind health checks, a single one starts next to the old before traffic moves, a database stops and starts. Refused while a deployment of the service is unfinished or while it is stopped. |
| Stop | `service_instances.stopped_at` (operational, not in a revision, never staged or promoted). Its placements are deleted, so its workloads leave the NodeStates; unfinished deployments are cancelled ("Not deployed: the service is stopped"); edges with the `serviceStop` feature route its domains to the "This app is stopped" page (`RouteSpec.stopped`, 503). Configuration, domains, volumes, variables and deployments stay. Scheduled backups are skipped, overdue-backup and unhealthy alerts don't fire, imports are refused. Refused while a deployment switches traffic. |
| Start | A `start` deployment that reruns the version that was live (as Restart), or, when the environment deployed configuration changes for it while it was stopped (or the image is no longer stored), a redeploy of the last deployed configuration at the same commit. Edges keep the stopped page until the new version is routed. |

Workers and crons of an app stop, start and restart with it when the dialog's "Include its workers and crons" stays checked. Stopping a database warns that the services connected to it lose it.

**Why the maintainer's rollout hung.** A Dockerfile-built Python app sat on Roll out ("Waiting for output…") for 15+ minutes and its worker showed Running without a container. Three things combined:

- launchroomd's reconcile pass blocked on the image pull (up to 45 minutes). While it waited, the server applied no newer desired state.
- The warm-up of an image launchroom built had no bound of its own: a registry that accepts the connection and never answers held the pull at "mirroring" for the whole 45 minutes.
- Every report re-sent the workload as `pulling` with a fresh `observedAt`, which kept extending the dashboard's 600 s pull allowance. A pull without byte progress showed no message, so the step said "Waiting for output…".
- The canvas called a workload running when its deployment was active and nothing had failed, without an observed container.

A service held by an import or a restore (left out of the NodeState, docs/14 §12.5) produced the same picture before holds cancelled rollouts; the earlier "The container removed" during a health check was such a hold starting mid-check.

**What changed.**

- Pulls run in the background (`internal/reconcile/pulls.go`): a pass waits at most 15 s for a pull it started, creates what it can and reports the rest as pulling; the pull's end kicks the next pass. A newer state that no longer needs the image cancels the pull (`AbandonPulls`).
- A built image's warm-up has 2 minutes (`docker.BuiltWarmTimeout`): "the launchroom registry didn't answer within 2m0s; check that this server reaches the main server". Mirror syncs of upstream images keep the pull's own bound.
- `PullProgress.updatedAt` moves only with the pull. The dashboard fails a download that moved no bytes for 2 minutes and names the server and path ("server-2 couldn't download the image from control plane's registry over the private network (the connection is relayed, not direct): no data for 2 minutes (100 MB of 412 MB so far)").
- **Step timeouts**: Roll out `deploy.rolloutTimeoutSeconds` (default 600 s, 60–3600, from the step's start; only an upstream image's mirror sync extends it) and Health check `deploy.healthTimeoutSeconds` (default start period + (interval + timeout) × failures + 60 s, at least 90 s; 30–3600; per replica in rolling updates, counted again after a pull that still moves). Settings → Deploy → Rollout. Their messages name the sub-step: "server-2 didn't pick up the change within 10 minutes", "server-2 was still downloading the image … after 10 minutes (412 MB of 690 MB)", "The container never became healthy within 3 minutes: GET / on :8000 kept failing (connection refused, 36 attempts). The app does not listen on any TCP port (expected 8000)." plus the last output lines.
- **Fail fast** on launchroomd's `WorkloadStatus.issue`: `crash_loop` (Docker restarted it 3 times and it hasn't run a minute since) → `runtime_error` "The container keeps crashing: it exited with code 1 and was restarted 3 times." with the last lines; `loopback_only` → `port_mismatch` "it listens on 127.0.0.1:8000 … launchroom expects it on 0.0.0.0:8000 ($PORT)"; `wrong_port` → `port_mismatch` "Nothing answers on port 8000 …: the app listens on 3000. Set the service port to 3000". Port issues settle for 15 s first and need a health check that hasn't passed; an app that doesn't listen yet is just slow. Docker's embedded DNS listener on 127.0.0.11 no longer counts as a port.
- A server that refused the rendered NodeState (`stateRejected` at or after the rollout's version) fails the rollout with its reason; a container launchroomd couldn't create (policy, create error, pull failure) fails it with "server-2 couldn't run the container: …".
- An exception that keeps coming back while advancing a deployment (3 times) fails it with the error instead of leaving it "Rolling out" while every retry throws.
- **The running step says what it waits for** (`RolloutProgress`): "Waiting for server-2 to pick up the change", "Fetching image into the local registry", "Downloading image (412 MB of 690 MB)", "server-2: waiting for this server's private network address", "Waiting for GET / on :8000 to answer (attempt 3: connection refused)", "The container keeps restarting (4 so far)".
- **Lifecycle lines** in the deployment log (`system` lines in the step they happened in) and the Logs tab: "Container created (replica 1, server-2)", "Container started (replica 1, server-2)", "Container exited with code 1 (…)", "Out of memory: the container was killed (…)", "Container restarted (attempt 4, …)", "Health check failed: GET / on :8000 → connection refused (3/3, …)", "Health check passed …", "Container stopped by launchroom (replaced by deployment dpl_…, …)", "Container removed by launchroom (the service was stopped, …)". The container's output from its first line is in the same step (startup output, [03](03-daemon-protocol.md) §5.6). A failed rollout keeps both after its placements are gone.
- **Docker's exit code** is only reported while the container is down: Docker resets it when its restart policy starts the container again, so a crash loop seen while running says "exited" without a code and the issue carries the last code seen.
- Exec-form `CMD` in a Dockerfile is stored as shell words (`uvicorn app.main:app --host 0.0.0.0 --port 8000`), so "Override" prefills a command that parses; without `EXPOSE` the port comes from `--port`, `-p`, `--bind`/`-b`/`--listen` in the command.

## 9. Build transparency

- Every deployment stores its build summary (`deployments.plan`: resolved builder and reason, preset decisions, detected port/commands, Next.js manifest, secrets hash, attestations) and its step-structured logs; the UI shows both ("View the full build plan").
- Built images carry SBOM and provenance attestations in the registry (§12).
- The Railpack version and frontend digest are pinned (§12), so a build of the same inputs uses the same toolchain.

## 10. Database services (engine drivers)

One `database` service kind with an `engine` driver class per engine (no per-engine tables or copy-paste). A driver defines:
- the image and its versions
- the default port
- the data path, version-aware (Postgres 18 moved PGDATA to `/var/lib/postgresql/18/docker`)
- generated credentials (32+ chars, URL-safe)
- the exported variables (`DATABASE_URL` + parts; Laravel-style names)
- the health probe (TCP, plus an engine-specific readiness check run by launchroomd with typed args)
- the backup and restore argv
- optional TLS

Engines: Postgres, MySQL, Redis, Valkey, MongoDB and ClickHouse (§11, backups in [14](14-backup-and-restore.md)).

Databases are **private**: reachable only from services of their environment.

## 11. Image services, pulls and deployment orchestration

Code: `App\Actions\Deployments\AdvanceDeployment` (dispatched by `AdvanceDeploymentJob` from Deploy, daemon reports and the minute sweep), `App\Drivers\DatabaseEngines`, daemon `internal/registry`, `internal/docker`.

### Image references

- **Upstream images run through the local mirror.** Image services and engine images are rendered as `<mirror_prefix>/<upstream registry>/<repository>@sha256:<digest>`, lowercase, e.g. `127.0.0.1:5000/mirror/docker.io/library/postgres@sha256:…` (`launchroom.images.mirror_prefix`, env `LAUNCHROOM_IMAGE_MIRROR_PREFIX`, default `127.0.0.1:5000/mirror`). Zot serves this path as a pull-through mirror (`mirror/<registry>/<repo>` → `<registry>/<repo>`). Built images use `launchroom.images.apps_prefix` (`127.0.0.1:5000/apps/<service id lowercased>@sha256:…`).
- **Digests are mandatory.** Engine images are pinned per major version in `config/launchroom.php` (`database_engines`, multi-arch index digests). Image services resolve a tag to a digest when the deployment starts, through `App\Contracts\ImageDigestResolver` (`RegistryImageDigestResolver`, §13 "Tag resolution"); failures are `config_error` with a hint to pin `@sha256:…`.

### Extension points

Bound in `AppServiceProvider`: `App\Contracts\BuildOrchestrator` (`TaskBuildOrchestrator`) and `App\Contracts\ReleaseRunner` (`TaskReleaseRunner`), see §13. `AdvanceDeployment` advances both idempotently with the deployment row-locked; they return `StepOutcome` (pending/succeeded/failed + class). The build orchestrator sets `deployments.image` on success.

### Steps

- `queued` waits while another deployment of the same instance is in flight (a partial unique index enforces one), and older queued ones are superseded by a newer Deploy.
- `rolling_out`: the pure scheduler places the replicas, placements (one per workload) are written and the affected NodeStates rendered next to the old workloads. Stateful services first mark the old workload `draining` with `drainUntil = now` and wait until launchroomd reports it `exited`/`removed` (timeout `launchroom.deployments.stop_timeout_seconds`, then fail rather than risk two databases on one data dir).
- `rolling_out`, waiting for the containers: a workload issue fails at once, a download without new bytes for 2 minutes fails as `transient`, and the step's timeout (`deploy.rolloutTimeoutSeconds`, §8.1) ends it with the sub-step it was in.
- `health_checking`: all placements `healthy` → routing; `failed`/`exited`/`removed` → `runtime_error` (`transient` for "pull failed: …"); a workload issue → `runtime_error`/`port_mismatch` at once. Deadline = `deploy.healthTimeoutSeconds` (default from the health check, §8.1) → `health_timeout`, counted from the step's start or, later, the last time a placement reported `pulling` within the last 3 minutes (step meta `pullSeenAt`, rolling updates).
- `routing`: the instance's `active_deployment_id` switches (routes now point at the new workloads), old serving workloads become `draining`, and the step waits until every online edge server reports the rendered NodeState version (or `routing_timeout_seconds`); then `active`, the previous deployment `superseded`, and the old workloads drain for `drainSeconds` from that moment (§13 "Draining").
- Failure removes the new workloads; a stateful predecessor stopped for the rollout is started again as new workloads of its own deployment.
- The sweep (`launchroom:deployments:sweep`, every minute) drops drained workloads once they're reported stopped (or 10 minutes after their deadline) and re-advances unfinished deployments so timeouts fire without reports.
- **Invalid desired state**: a render that `NodeStateValidator` refuses is never published ([03](03-daemon-protocol.md) §5). The deployment whose workloads caused it fails with `config_error` "Desired state could not be rendered: …" (checked at the start of every step and right after its own render, `DesiredStateInvalid`), its workloads are removed and the previous version keeps serving.

### Pulls

D51. Zot's on-demand sync answers a request for a platform manifest it has not mirrored yet only after it copied **every layer** from upstream. The Docker daemon gives a registry 30 s to send response headers, so a plain `docker pull` of a large image (Umami, Uptime Kuma) through a cold mirror fails with "timeout awaiting response headers" while Zot keeps syncing.

- launchroomd **warms the mirror first** (`registry.Tokens.Warm`, hook `docker.EngineOptions.Warm`): for an image under `127.0.0.1:5000/` it GETs the manifest itself over plain HTTP through the registry forwarder, with a pull-only token for exactly that repository and no header timeout: the index, then this host's platform manifest (containerd's platform matcher, Docker's choice), verifying each body against its digest. That request waits for Zot's sync and returns the layer sizes; then `docker pull` reads everything from the local registry. A failed warm-up changes nothing (Docker's pull reports the registry's real answer).
- The pull streams Docker's progress messages. It fails after **5 minutes without a progress message** (`docker.DefaultPullIdleTimeout`) or **45 minutes overall** including the warm-up (`DefaultPullTimeout`); the warm-up of an image launchroom built fails after **2 minutes** (`BuiltWarmTimeout`, D68). Pulls run in the background of the reconcile pass (§8.1), and `pull.updatedAt` tells the dashboard when the pull last moved.
- Layer bytes are folded into one count (manifest sizes known up front, Docker's own totals otherwise) and reported as `Report.workloads[].pull` (stage `mirroring` → `downloading` → `extracting`, at most every 2 s).
- The dashboard stores it on the placement (`placements.pull`, only while `pulling`) and nudges open deployment pages with `DeploymentUpdated` without advancing the deployment. The running Roll out step reads "Fetching image into the local registry (first deploy of this image)", then "Downloading image (412 MB of 690 MB)" (decimal units; with several servers the one furthest behind, "… on 2 servers"), then "Unpacking image". Initial placement stays `rolling_out` until every container is observed past pending/pulling; a server that stops reporting pull progress for ten minutes fails as transient. Health checking starts afterward. During replica-by-replica rolling updates, the Roll out step remains open alongside Health check until every replica is healthy, so subsequent pulls stay under Roll out too.
- Rejected alternatives: a dashboard-side pre-warm when the deployment is created (the dashboard doesn't know which platform each server pulls, would hold a worker for the length of a sync, and the daemon warms right before it needs the image anyway); raising Docker's header timeout (not configurable per pull).

### Engine drivers

`App\Drivers\DatabaseEngines`:

- Postgres 16/17/18 (18 mounts `/var/lib/postgresql`, older `/var/lib/postgresql/data`), MySQL 8.4, Redis 7, Valkey 8, MongoDB 7.0/8.0, ClickHouse 26.3/26.8.
- Credentials are 32 alphanumeric characters, passed only through the sealed env.
- Redis/Valkey images can't read a password from the env, and argv would put it in the (unsealed) NodeState. Their driver-owned args therefore re-enter the image entrypoint with `requirepass` fed through stdin (`<server> -`) from `$REDIS_PASSWORD`; no user input reaches that string.
- Capabilities stay within the launchroomd allow-list (`CHOWN SETUID SETGID DAC_OVERRIDE FOWNER`).
- Databases stop with their engine's shutdown signal, a 60 s timeout and no init process (D42).
- Dump/restore argv take credentials from env (`PG*`, `MYSQL_PWD`, `REDISCLI_AUTH`); see [14](14-backup-and-restore.md).

## 12. Build pipeline (launchroomd)

Daemon packages: `internal/build` (pipeline), `internal/build/presets` (presets), `internal/build/nextadapter` (vendored Next.js adapter), `internal/build/buildkitd` (system BuildKit), `internal/registry` (forwarder + tokens), `internal/tasks/build.go` (task handler). Contract: `BuildSpec`/`PlanSpec`, `BuildResult`/`PlanResult`/`TaskFailure` in `protocol/schema/v1/task*.json` (see `protocol/CHANGELOG.md`).

- **Builder resolution** (§2) runs on launchroomd after cloning. `builder.kind` is `auto` (Dockerfile/`Containerfile` at `dockerfilePath` or the root dir → `dockerfile`; otherwise Railpack, with a preset if one matches), `dockerfile` or `railpack`. The outcome is reported as `summary.builder` + `summary.builderReason` (`explicit`, `dockerfile_detected`, `preset`, `railpack`). Dev-only Dockerfiles (named `*.dev`, or `CMD`/`ENTRYPOINT` running `npm run dev`, `vite`, `artisan serve`, `runserver`, …) get the `dockerfile-dev-only` warning, never a silent switch.
- **Clone**: go-git in-process (no `git` binary in the launchroomd image), depth 1, exactly `repo.sha` (SHA-1; GitHub, GitLab and Gitea/Forgejo allow fetching any reachable SHA). When a server refuses an unadvertised SHA (plain git servers without `uploadpack.allowAnySHA1InWant`), launchroomd fetches `repo.ref` (or the advertised branch/tag whose tip is the SHA) with depth 50 and checks the commit out from that history.
  - HTTP(S): the token from `cloneToken` (sealed `TaskToken`, purpose `clone`) is HTTP Basic auth in memory (`x-access-token:<token>` for GitHub, `oauth2:<token>` for GitLab and Gitea, `launchroom:<token>` otherwise); it is never in the URL, a git config or on disk.
  - SSH (`ssh://[user@]host[:port]/path`, user default `git`; a path starting with `/~` is home-relative and handed to go-git in scp form so the remote gets `~/…`): go-git's SSH transport (`golang.org/x/crypto/ssh`), no `ssh` binary, no `GIT_SSH`/`core.sshCommand`, no known_hosts file, `~/.ssh/config` never read. The deploy key from `sshKey` (sealed `TaskToken`, purpose `ssh_key`, the base64 ed25519 seed) signs in. The host key callback accepts only a key byte-equal to one of `repo.knownHosts`, and only the pinned key types are offered as host key algorithms (RSA pins: `rsa-sha2-512`, `rsa-sha2-256`, `ssh-rsa`), so a server can't fall back to an unpinned key. A mismatch is `clone_error` "host key mismatch: the server presented <type> SHA256:…". 20 s connect + handshake timeout.
  - Only `https://` and `ssh://` URLs, plus `http://` for hosts in the daemon-local `LR_BUILD_INSECURE_GIT_HOSTS` (dev: `git-fixtures`).
  - After checkout, `rootDir` is resolved and containment-checked first. Outside the app folder, symlinks that are absolute or escape the checkout and device/FIFO/socket files are removed from the checkout (`pruneOutsideApp`, through `os.Root`) and listed in the build warning `unsafe-links-removed`: they aren't part of the build, and monorepos carry them as test fixtures. Then the whole tree is rejected (`config_error`) if any such entry is left, which can only be inside the app folder (or anywhere when the app is the repository root). The Dockerfile path is resolved and containment-checked. Submodules and LFS are not fetched.
- **Railpack**: `github.com/railwayapp/railpack` v0.42.0 linked in (`build.RailpackVersion`); a spec pinning another `railpackVersion` fails with `config_error`. Planning is in-process (`core.GenerateBuildPlan`); build variables enter as names only (placeholder values), `RAILPACK_*` overrides (`builder.railpackEnv`, install/build/start commands, PHP extensions) with their values. Railpack makes every planning variable a plan secret; launchroomd removes the `RAILPACK_*` names from the plan's secrets afterwards (unless a build variable has that name), since the solve has no value for them and BuildKit would fail the step. Railpack downloads and runs `mise` to resolve versions (cached in `/tmp/railpack/mise`); upstream doesn't verify mise's checksum. The build uses the Railpack gateway frontend `ghcr.io/railwayapp/railpack-frontend@sha256:a42a1239…` (v0.42.0) with `cache-key` = `cacheKey` and `secrets-hash`.
- **Presets** (§3) are pure functions `Propose(facts, railpackInfo)` (`internal/build/presets`):
  - Laravel: release `php artisan migrate --force`, `RAILPACK_SKIP_MIGRATIONS=true` in the image env, build-time `artisan *:cache`/`optimize` removed, horizon or queue worker, scheduler, reverb, health `/up`, port 8080.
  - Django: gunicorn/uvicorn/daphne start from `WSGI_APPLICATION`/`ASGI_APPLICATION`, release `migrate --noinput`, `collectstatic` when `STATIC_ROOT` is set, celery worker/beat.
  - FastAPI/Flask/Litestar: app object search, `fastapi run` with `fastapi[standard]`.
  - Next.js: `HOSTNAME=0.0.0.0`, telemetry off (decision `telemetry-off`), the deployment adapter for Next ≥ 16.2 (§12.1), else the standalone overlay + `node .next/standalone/server.js` when `output: 'standalone'`, warnings for the server-actions key and ISR.
  - Static SPAs: Caddy, port 8080.
  - Node servers: hard-coded `listen(<port>)` and missing start script warnings, port 3000.
  - PHP database drivers (§3.1): `builder.phpExtensions` are appended to `RAILPACK_PHP_EXTENSIONS` before planning, reported as `summary.phpExtensions` for the `php` provider; Dockerfile builds of an app with `composer.json` get the warning `dockerfile-php-extensions`.
  - Decisions and warnings carry stable ids; `builder.disabledDecisions` removes them (UI chips), `builder.preset` forces a preset or `none`. Without a preset, `$PORT` defaults per provider (node 3000, python 8000, else 8080); Dockerfile builds report the final stage's first `EXPOSE`.
- **BuildKit** (`buildkitd`): `moby/buildkit:v0.34.0-rootless` (digest pinned in `policy.DefaultSystemImageDigests`) when `system.buildkit.enabled`. Rootless works inside the dind fake servers; there is no rootful mode.
  - Container `lr-system-buildkit`: `seccomp=unconfined`, `apparmor=unconfined` (or `apparmor=launchroom-buildkit`, see below) and the Engine-API form of `systempaths=unconfined` (empty masked/read-only paths), never `--privileged`, no added capabilities, never `--oci-worker-no-process-sandbox`; state volume `lr-system-buildkit-state`; resource budget and low priority as in §5.2 (`LR_BUILDKIT_MEMORY_MB`/`LR_BUILDKIT_CPUS` override).
  - **User namespaces on restricted hosts** (D58): Ubuntu 23.10+ sets `kernel.apparmor_restrict_unprivileged_userns=1`, and rootlesskit under `apparmor=unconfined` then fails (`fork/exec /proc/self/exe: permission denied`). The installers load the host profile `launchroom-buildkit` (`/etc/apparmor.d/launchroom-buildkit`: `abi <abi/4.0>`, `flags=(unconfined)` plus `userns,`, the shape of Ubuntu's own per-application profiles such as `chrome`; no attachment path, so nothing uses it unless a container asks for it by name), docs/11 §2.1b. launchroomd can't see the host's loaded profiles (no securityfs in its container, no host access, R1/R2), so it decides per host: when `/proc/sys/kernel/apparmor_restrict_unprivileged_userns` is `1` and `/sys/module/apparmor/parameters/enabled` is `Y`, it creates buildkitd with `apparmor=launchroom-buildkit`; Docker/runc refuse to start a container whose profile the kernel lacks, and an error naming AppArmor makes it fall back to `apparmor=unconfined` (which still works where the owner allowed rootlesskit some other way, for example the path profile rootlesskit's own hint suggests). The fallback container is probed again with the profile only while it isn't running (crash loop), at most every 2 minutes, so loading the profile later takes effect without a restart and a working fallback is never replaced. The profile is part of the spec hash (only when it isn't `unconfined`, so existing containers on other hosts keep their hash): switching recreates the container. The path-attached `/usr/bin/rootlesskit` profile was rejected: it would match any container image's or host program at that path, and its attachment inside a container's mount namespace depends on how AppArmor resolves the path. `Report.host.runtime` capability `builds` (build servers only): full; full with a note when the profile is in use; **unavailable** with the one-line fix (`buildkitd.ProfileCommand`) when restricted, the profile is missing and BuildKit doesn't answer.
  - It sits on its own bridge `lr-system-build` (`LR_BUILD_SUBNET`, default `10.232.0.0/24`) and listens on its unix socket only: build steps share its network namespace, so there is no TCP API they could reach. launchroomd connects through `buildctl dial-stdio` over a Docker exec stream.
  - **Readiness**: a build connects through `WaitClient`: while launchroomd has not converged buildkitd yet (it just started), while Docker's restart policy restarts it (after a crash or an OOM kill) or while the reconcile replaces it (new spec), the build waits up to 2 minutes with backoff ("Waiting for BuildKit to start…" in the `setup` step, "Still waiting…" every 30 s) instead of failing on the first dial. Three restarts seen while waiting (or ≥ 5 restarts with short runs) is a **crash loop** and fails early. The failure is `internal_error` (retried once) with buildkitd's last log lines in the build log and hints: `buildkit-userns-restricted` (rootlesskit could not create its user namespace and `kernel.apparmor_restrict_unprivileged_userns=1`, the Ubuntu 23.10+ default: the fix is loading the `launchroom-buildkit` profile by re-running the installer or with the printed command, never turning the sysctl off), `buildkit-oom` (Docker's `OOMKilled`), `buildkit-crash-loop`, `buildkit-not-ready`; the dashboard's failure catalogue explains each. Sync logs a warning when the container keeps restarting.
  - `buildkitd.toml` is built into launchroomd and copied in before start: `max-parallelism`, GC `reservedSpace` 10GB/`maxUsedSpace` 60GB/`minFreeSpace` 15%, 7-day keep for mount caches and local sources, history 7 days/200 entries, `[frontend."gateway.v0"] allowedRepositories` = Railpack's frontend and `docker/dockerfile`, Docker Hub and GHCR mirrored through Zot with upstream fallback.
- **Registry from the build**: buildkitd reaches Zot as `registry.launchroom.internal:5000` (ExtraHosts → the build network gateway, where launchroomd runs a second registry forwarder; plain HTTP for that host only). The push name is `registry.launchroom.internal:5000/apps/<service lowercased>:<deployment lowercased>`; the reported image is `127.0.0.1:5000/apps/<service>@sha256:<index digest>` (same repository and digest). The build's session is BuildKit's token authority for that host: every registry challenge is answered with a dashboard-issued token for exactly the scopes this build may use (pull/push `apps/<service>` and `cache/<service>`, pull `mirror/…`); other scopes are dropped. Other registries are anonymous.
- **Build execution**: build slots per server (`LR_BUILD_SLOTS`, default max(1, cores/4)), waiting in the `queue` step; the task deadline cancels the solve. Build variables are BuildKit secrets (`id` = variable name); Dockerfile builds additionally get `--build-arg` only for names in `buildArgs` that the Dockerfile declares as `ARG`. `secretsHash` = SHA-256 over sorted `NAME=value\n` lines. Registry cache `cache/<service>:buildcache` (`mode=max`, OCI image manifest, `ignore-error`). The same ref is the cache import. On a service's first build it doesn't exist yet: BuildKit marks its "importing cache manifest" vertex as errored (`failed to configure registry cache importer: …: not found`) but the solve goes on uncached (a cache source that fails to load just has no records). launchroomd logs that vertex's error as a note, never as an `ERROR` line or the failed step: "No build cache yet for this service: this first build starts from scratch." for not found, else `WARNING: Couldn't load the build cache, building without it (<reason>)`. SBOM (`docker/buildkit-syft-scanner` stable-1, pinned, via the mirror) and `provenance mode=min` attestations are attached to the pushed index. The checkout lives in `<data>/builds/task-<id>-*` and is removed afterwards.
- **Logs**: shipped live (≤ 1 s, ≤ 500 lines per chunk) as task logs with `step` (`clone`, `plan`, `queue`, `setup`, Railpack step names such as `packages:mise`/`install`/`build`, Dockerfile stage names, `cache`, `push`). Seq starts at `(attempt − 1) × 10⁹ + 1` so retries don't collide. Every line and the completion error are masked: secret values (≥ 4 bytes), their standard/URL base64 (padded and raw) and URL-escaped forms, each line of multi-line values, and the clone token's Basic-auth header form.
- **Failures** (§6): `plan_error` (Railpack could not plan), `build_error` (a step failed; hints from the known-error table, `failure.step` = the failing step), `transient` (mise/GitHub, registry or network trouble, retryable), `clone_error`, `config_error` (unknown preset or Railpack version, missing root dir/Dockerfile, unsafe tree, bad sealed secrets), `timeout`, `internal_error` (BuildKit not running, crash-looping or not ready in time; hints `buildkit-*` above). `failure.summary` carries what was detected before the failure.
- **Dev**: `git-fixtures` serves `dev/fixtures/*` as `http://git-fixtures:8080/<name>.git` (`GET /` lists names and SHAs; `make fixtures`). Fixtures: `node-hello`, `vite-spa`, `next-standalone`, `next-adapter` (Next 16.4 through the adapter: App Router, ISR page, proxy, `public/`), `dockerfile-hello`, `unplannable-site`, `laravel-sqlite` (a Laravel 12 app written for SQLite; integration test `TestBuildLaravelWithPostgresDriver` builds it with `pdo_pgsql` and checks the running app uses `pgsql` with the driver loaded).

### 12.1 Next.js deployment adapter

`nextjs` preset v2, decision `deployment-adapter`. How the adapter itself works (hooks, why the copy runs after `next build`, config rules, manifest): [packages/next-adapter/README.md](../packages/next-adapter/README.md).

- **When**: `next` ≥ 16.2 in `package.json`, unless `next.config` sets `adapterPath` (warning `own-next-adapter`, standalone handling without the adapter) or `output: 'export'`.
- **Vendoring**: `make next-adapter` vendors the package (`packages/next-adapter`) into launchroomd (`internal/build/nextadapter`: `dist/*.js` + `package.json`, `SHA256SUMS` checked before use, CI diffs it against a fresh build). Nothing is fetched from npm at build time.
- **Injection**: the preset writes those files as Railpack plan assets to `/opt/launchroom/next-adapter` at the start of the `build` step (outside `/app`, so they never reach the image; the summary's `plan` shows them as size + sha256). It sets the build-step variables `NEXT_ADAPTER_PATH=/opt/launchroom/next-adapter/dist/index.js` (an absolute path: Next resolves `adapterPath` from its own module, so a package name would not resolve), `LAUNCHROOM_REPO_ROOT=/app`, `LAUNCHROOM_NEXT_FINALIZE=manual` and `LAUNCHROOM_DEPLOYMENT_ID=<deployment id>` (build tasks only), and appends `node /opt/launchroom/next-adapter/dist/cli.js`.
- **Finalize**: Next calls `onBuildComplete` before it (re)writes `.next/standalone`, so that CLI copies `public/` and `.next/static` after `next build` and prints one `launchroom-next-summary {…}` line. launchroomd turns that line into `summary.next` (`NextBuildInfo`: versions, deploymentId, server entry, middleware and runtime, image optimization/sharp, cache handler, route counts, first 32 ISR paths; invalid values dropped). If the build step is a cache hit, no summary line is printed and `summary.next` is absent (a warning line says so).
- **Image**: the deploy layers from install/prune/build are replaced by one layer of `.next/standalone` from the build step (the mise Node toolchain stays); start `node .next/standalone/server.js`. The standalone server uses the config embedded at build time and never loads the adapter, so the adapter is not in the image. Removing the decision gives Railpack's `next start` with the full `node_modules`.
- **Cache**: the deployment id is a build-step variable, so builds of different deployments never share the `build` step cache (identical commits reuse the image anyway, §13).
- **Size** (fixture `next-adapter`, Next 16.4.2, arm64): the image is 692 MB with the adapter and 1083 MB with `next start`. The app layer is 40.8 MB (`.next/standalone`); the remaining ~650 MB is Railpack's runtime base and the mise Node toolchain, which both variants share.
- **Multi-replica**: `deploymentId` keeps asset URLs (`?dpl=<id>`) consistent across replicas. ISR and `revalidateTag` are per replica: there is no shared cache handler (`LAUNCHROOM_NEXT_CACHE=redis` is reserved; the adapter warns and keeps a user-configured `cacheHandler`/`cacheHandlers`).
- **Test**: integration test `TestBuildNextAdapterAndRun` (dind) checks that the image serves `/` (200, proxy header, asset URLs tagged `?dpl=<deployment id>`), a hashed `/_next/static` chunk, `public/launchroom.txt`, the ISR page `/isr` and `/api/health`, and that the vendored adapter is not in the image.

## 13. Build and release orchestration (dashboard)

Dashboard side of builds, release/seed commands, plan previews, tag resolution and Git triggers. Code: `App\Services\Builds\{TaskBuildOrchestrator,BuildTasks}`, `App\Services\Deployments\TaskReleaseRunner`, `App\Services\Tasks\{TaskSecrets,TaskCancellation}`, `App\Support\Deployments\EffectiveDeploy`, `App\Actions\Services\{PlanService,RecordPlanResult}`, `App\Actions\Deployments\DeployCommit`, `App\Listeners\Git\*`, `App\Services\Images\RegistryImageDigestResolver`.

### Build tasks

When a deployment of an `app` service reaches `building`, `TaskBuildOrchestrator` (deployment row-locked, idempotent):

1. resolves the commit if the deployment has none (Deploy of staged changes; looked up before the row lock by `lookupHead()`, see §6): branch = instance branch override → environment trigger branch (unless a glob) → the source's `branch` → the GitHub repository's default branch → `main`; the head comes from `GET /repos/{repo}/commits/{branch}` (installation token, read scope) or, for plain git sources, the smart-HTTP ref advertisement (`info/refs?service=git-upload-pack`). It then recomputes `build_key` and re-stores the env snapshot with `LAUNCHROOM_GIT_COMMIT_SHA`. An unresolvable branch fails with `config_error`;
2. reuses the newest image of the same service with an equal `build_key` (no task; step message "Reused the image of dpl_…");
3. otherwise creates one `build` task: required role `build`, `timeout_seconds` 7200 (`launchroom.builds.timeout_seconds`), `max_attempts` 2, routed as in §5.1. Spec: `repo {provider github|git, cloneUrl, sha}`, `rootDir`, `builder` (`auto`/`dockerfile`/`railpack`; `static` → `railpack` + preset `static-spa`; `railpackVersion` = service setting or `launchroom.builds.railpack_version`; install/build commands), `buildArgs` = names of non-secret build-scoped variables, `platform` (§5.1), `cacheKey` = service id lowercased, `image` = `<apps_prefix>/<service lowercased>`. Every value is grammar-checked first (one undecodable task would break launchroomd's whole lease); failures are `config_error`.

Then:

- On success the deployment gets `image` (must be in its own `apps/<service>` repository, else `build_error`), `build_server_id`, and `plan = {task, durationMs, summary, secretsHash, attestations}`.
- On failure `failure.class` maps to the deployment's class: `plan_error` → `plan_error`; `transient`, `internal_error` → `transient`; `clone_error`, `config_error` → `config_error`; everything else (`build_error`, `timeout`) → `build_error`. The message is `Build failed (<step>): <error>` and a failure `summary` is kept in `plan`. A retryable/`transient` failure gets one new task (two per deployment at most).
- Workers and crons don't build: they take the image (and summary) of their parent app's deployment of the same revision, else its active deployment; they wait while it builds (`DaemonTaskFinished` wakes them).
- `cancel()` (deployment cancelled, superseded by a newer push, or failed) cancels the build task: pending tasks directly, leased ones via `cancel_requested_at` (the next heartbeat says `cancel`).
- Builds and plans are only leased by servers whose platform (`facts.arch`) matches `spec.platform`, or whose BuildKit emulates it (`runtime.buildPlatforms`, D53).

### Sealing at lease time

[03](03-daemon-protocol.md) §7. Task specs are stored without secrets; `daemon_tasks.seal` only references them (`env: {deployment, keys|null, defaults}` or `{names}`, `cloneToken: {source}`). `LeaseTasks` seals them to the leasing server: build variables (deployment env snapshot ∩ build-scoped keys) as `TaskEnv`, a fresh repository-scoped GitHub installation token as `TaskToken` (purpose `clone`). Nothing sealed is stored. A task whose secrets can't be sealed (installation suspended, repository gone, GitHub down) fails at lease time with `result.failure.class` `clone_error`/`transient` instead of being handed out.

### Summary defaults

`EffectiveDeploy`: explicit service settings win; where the service has none, the build summary of the deployment's image fills in at render time (the revision stays immutable):

| Setting | Explicit | Default from the summary |
|---|---|---|
| port | `deploy.port` | `summary.port` (web kinds); `PORT` is injected then |
| health path | `healthcheck.path` other than `/` | `summary.healthPath` (http probes) |
| release command | `deploy.releaseCommand` | `summary.releaseCommand` (app services) |
| start command | `deploy.startCommand` | workers/crons: `summary.processes[name = service slug].args` |
| runtime env | any variable | `summary.runtimeEnv` (variables win) |

- `summary.next` (Next.js adapter manifest, §12.1) is display-only: it changes no setting. The PHP mirror (`BuildSummary::$next`, `NextBuildInfo`, `NextRouteCounts`) stores it with the summary (`deployments.plan.summary.next`), and the service's Settings → Build "detected" panel shows it ("Next.js 16.4 · standalone · ISR routes: N · middleware", route counts, revalidated paths, image optimization). Plan previews never build, so the panel takes `next` from the latest deployment's build summary.
- Railpack images have the ENTRYPOINT `["/bin/bash", "-c"]`, so argv commands of Railpack-built images replace the entrypoint (`command`, no `args`); other images keep their ENTRYPOINT and get the argv as `args`.
- App services always get a generated domain (their port usually comes from the build). Rollbacks copy the summary with the image.

### Release and seed commands

- **Release commands** (`TaskReleaseRunner`): `releasing` creates one `run_once` task (purpose `release`, `max_attempts` 1, timeout `launchroom.deployments.release_timeout_seconds`, default 900 s) pinned to an online runtime server that already runs workloads of the environment (preferring the instance's current one), else any online runtime server. Spec: the deployment image, the argv, the environment network `{id, name, subnet}` (launchroomd creates it if missing), the instance's resource limits; the env (full runtime snapshot + summary defaults) is sealed at lease time. Exit code 0 → rollout; non-zero or a failed task → `release_error`, nothing is rolled out and the old version keeps serving. Rollbacks don't run the release command again. Logs are task logs with step `run`.
- **Seed commands**: after an instance's first deployment goes `active` (no previous active deployment), `deploy.seedCommand` runs once as a `run_once` task (purpose `seed`, non-blocking).
- `DeploymentStatusChanged` (after commit) drives GitHub feedback.

### Draining

When routing starts, the old workloads become `draining` with a deadline of drain period + routing timeout. Once every edge applied the new routes (or the routing timeout passed) the deadline becomes now + `drainSeconds`, so in-flight requests always get the full drain period after the switch. launchroomd takes workloads it is about to stop out of the routes before stopping them.

### Plan previews

`PlanService`, `PlanServiceJob`. On service creation (app services), when an instance's build settings or branch override change, and on "Detect again", a `plan` task (no deployment, build variables as names only, timeout 300 s) runs for the branch head. It is idempotent per (instance, commit, build inputs); a pending plan for outdated inputs is cancelled. Before a service exists, the add-service sheet previews a repository through `TaskPlanPreviewer` ([08](08-dashboard-ux.md) §9). Removed preset decisions are `build.disabledDecisions` (rebuild-affecting) and reach launchroomd as `builder.disabledDecisions`. The outcome is stored in `service_instances.plan` (`status` planning|ready|failed, `sha`, `branch`, `summary`, `error`, `failureClass`) and exposed as `App\Data\Services\ServicePlanData`.

### Tag resolution

`RegistryImageDigestResolver`: `HEAD <launchroom.registry_lookup.url>/v2/mirror/<registry>/<repository>/manifests/<tag>` with a 2-minute dashboard-issued pull token for exactly that mirror repository and `Accept` for OCI indexes, Docker manifest lists and single manifests; the `Docker-Content-Digest` is cached for 60 s. Asking the mirror also syncs the image. Only registries the Zot mirror syncs (docker.io, ghcr.io) work.

### Git sources and triggers

- **Git triggers** ([06](06-variables-environments.md) §2): see there; deployments of pushes use the environment's last applied revision (`DeployCommit`).
- **Other providers** (D46, [19](19-git-providers.md)): heads, clone URLs and clone credentials come from the source's `GitProvider`; GitLab/Gitea builds seal the connection token, plain git servers over SSH seal the deploy key seed (`sshKey`) and pin `repo.knownHosts` at lease time.
- **Plain git sources** (`GitSource.provider = git`, `cloneUrl`, `branch`; `repositoryId` 0; no `installationId`): only where `launchroom.git.plain_sources` is on (default: `APP_ENV=local`); `http://` only for `launchroom.git.insecure_hosts` (dev: `git-fixtures`, mirroring launchroomd's `LR_BUILD_INSECURE_GIT_HOSTS`). They have no webhooks: `php artisan launchroom:dev:git-push <sin_…>` deploys the current head like a push.
- **Dev end-to-end**: `php artisan launchroom:dev:deploy-app` (local only) creates a project with the `node-hello` fixture (plain git source), Postgres connected to it, and deploys: build on server-1 → push to Zot → rollout → health → route, reachable at `https://node-hello-production.<project>.localhost:8443/` (or `curl -H 'Host: …' http://127.0.0.1:8080/`, which redirects to HTTPS).
