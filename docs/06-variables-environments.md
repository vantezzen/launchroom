# 06: Variables, environments & previews

## 1. Concepts

- **Project**: a canvas of **services** (identity, kind, name, icon, connections).
- **Environment**: a full copy of the project's service graph with its own configuration, variables, domains, volumes and placements. Kinds:
  - `production`: exactly one, always exists.
  - `persistent`: user-created, e.g. `staging`, `qa`.
  - `preview`: ephemeral, created from a **base environment** per PR, destroyed on close/merge or expiry.
- **Service instance**: a service *in* an environment. It holds the settings: source branch, build overrides, start/release commands, replicas, resources, healthcheck, placement. Deployments belong to service instances.
- A service can be **disabled** in an environment (e.g. the `stripe-webhook-worker` only in production).
- Adding a service on a canvas (`AddService` → `CreateService` with the environment) creates it in **that environment only**; other environments get it through Compare & promote (§6). Creating a service without an environment (dev commands, API) creates it in every non-preview environment.

## 2. Triggers

Per environment:

| Trigger | Example | Notes |
|---|---|---|
| Branch push | `main` → production, `develop` → staging | glob support (`release/*`) |
| Tag push | `v*` → production | Teams who release by tag: production ignores branch pushes |
| Pull request | open/sync → preview; close/merge → destroy | Target-branch filter, label filter (`preview`), or opt-out in the PR title (`[skip preview]`); max concurrent previews (default 3) with queueing; expiry after N days without new commits (default 7). See §5 |
| Manual | "Deploy" button / API / CLI | Any commit or branch |
| Polling fallback | Every 60 s via the GitHub API for tracked refs | When webhooks can't reach the dashboard (e.g. dashboard behind NAT) or webhook delivery is unhealthy |

### 2.1 Environment triggers

- Triggers are edited per environment in Settings → Environments (`UpdateEnvironment`): a branch glob, a tag glob, both, or neither (**manual only**). Globs are git ref names with `*`/`?` (`EnvironmentTriggers::GLOB`).
- Production may track tags only (branch empty, tags `v*`): branch pushes then never reach it.
- A new environment starts manual-only unless a branch is given.
- Trigger edits apply to the next push at once and also show as staged items with effect "none".
- A push is matched against **every** environment of the project: each matching environment deploys its own instance of the service, so `develop` → staging and `main` → production work side by side, and two environments tracking the same branch both deploy (`DeployOnGitPush`; `tests/Feature/Environments/GitTriggersTest.php`).

### 2.2 Git events

- Webhooks and polling both emit `App\Events\Git\GitPushReceived` (GitHub installation or GitLab/Gitea/plain git connection, [19](19-git-providers.md) §5; numeric repository id, full ref, before/after, head commit, changed files when the payload is complete).
- Both record the ref's head in `github_ref_heads` first, so a push seen by both channels is emitted once. Deleted refs and skip-marked commits are recorded but not emitted.
- Skip markers in the head commit message, case-insensitive: `[skip ci]`, `[skip deploy]`, `[ci skip]`, `[no ci]`, `[deploy skip]`.
- `pull_request` webhooks emit `PullRequestEventReceived` (fork = head repository id ≠ base repository id; the author's permission is looked up for fork PRs). `App\Listeners\Previews\SyncPreviewsOnPullRequest` syncs every project with a service of that installation + repository (§5).
- **Polling** runs every minute for the refs returned by the `App\Contracts\Git\TrackedRefs` contract, but only for apps without a verified webhook in the last 15 minutes. It uses `GET /repos/{repo}/commits/{ref}` with `If-None-Match` (a 304 costs no rate limit). The first observation of a ref only records a baseline; it doesn't deploy.
- `TrackedRefs` (`App\Services\Git\ServiceTrackedRefs`): `refs/heads/<branch>` per (installation, repository, branch) of live GitHub-sourced instances in non-preview environments. Glob branches are left to webhooks.

### 2.3 Push deploys

`App\Listeners\Git\DeployOnGitPush` → `App\Actions\Deployments\DeployCommit` deploys every live, enabled service instance that:
- has a GitHub source with this installation (`github_installations.id`) and numeric repository id,
- is in a non-preview environment,
- matches the ref: its branch override, or else the environment's branch glob, matches `refs/heads/<branch>`, or the environment's tag glob matches `refs/tags/<tag>` (trigger `tag`).

Skipped:
- environments with `auto_deploy_paused` (production after a rollback, [04](04-build-and-deploy.md) §8),
- instances with **watch paths** (repo-relative directory prefixes or globs) that none of the changed files match. Unknown file lists always deploy.

The deployment uses the environment's **last applied revision**: pushes never apply staged changes, and never-deployed instances are not deployed. It carries `trigger`, `git_ref`, `commit_sha`, `commit_message` and `commit_author`, supersedes queued deployments of the instance, and cancels + supersedes a build still running for an older commit (collapse to latest).

### 2.4 Commit status on GitHub

`App\Listeners\Git\ReportDeploymentToGitHub` → `ReportDeploymentStatusJob`, only for deployments with a commit of GitHub-sourced services:

| Deployment | GitHub state |
|---|---|
| queued (sent when a push creates the deployment) | Queued |
| building … routing | InProgress |
| active | Success |
| failed | Failure (with the failure message) |
| cancelled / superseded | Cancelled |

Only changes of the reported state are sent. An active deployment being replaced is not reported again; its GitHub deployment goes inactive through `auto_inactive`. Previews report differently (§5.6).

## 3. Variables

Code: `App\Support\Variables`, `App\Services\Variables`.

### 3.1 Model

```
final env for (service instance S in environment E, branch B) =
     system variables                       (LAUNCHROOM_*, PORT, HOST/HOSTNAME, generated URLs)
   < project shared variables               (only those S opted into)
   < connection-injected references         (from canvas edges)
   < service variables targeted at E
   < service variables targeted at "Previews, branch B"   (branch override)
```

- One **variable row** = key + value + type + **targets**. Targets are a set of environments: specific persistent environments, `production`, and/or "All previews" with an optional branch pattern. Targeting is described in §3.6.
- **Types**:
  - `config`: readable by anyone who can see the service.
  - `secret`: write-only after saving (replace/rotate only), masked in logs; reveal only for roles with permission, password-confirmed and audited.

  Imported keys matching secret patterns (`*_KEY`, `*_SECRET`, `*_TOKEN`, `*PASSWORD*`, `*_DSN`) default to `secret`.
- **Shared variables** are project-level, with the same targets. They are **not** auto-injected everywhere: a service opts in ("Use in: web, api"), which injects the variable under its own key, or references it as `${{shared.KEY}}`. A shared variable a service didn't opt into is reachable only by reference.
- **Connection-owned variables** have no targets: they apply in every environment where both services exist.
- **Overlaps**: two non-connection rows with the same key, service and a shared target are refused (the value would be ambiguous).
- **Values are stored verbatim.** No trimming, no implicit `$VAR` interpolation (only `${{ }}` references), and multiline values are supported.

**References**:
- `${{KEY}}` (same service), `${{svc.KEY}}` (`svc` is a service slug), `${{shared.KEY}}` (`shared` is reserved and can't be a slug). Any other `${{ … }}` is rejected on save and blocks a deploy. There is no escape syntax. Substituted values are never re-scanned.
- Autocomplete appears on `${{`, the editor shows the resolved value inline (secrets masked), and **broken references block the deploy with an error naming them** (never a silent literal).
- **References are environment-relative**: `${{postgres.DATABASE_URL}}` in a preview resolves to the preview's Postgres. Previews work without per-environment edits because of this.

**Generated values** are evaluated on save and only there, then stored as secrets (not re-rolled per deploy):
- `${{ secret(n) }}`: n alphanumeric characters (URL-safe).
- `${{ secret(n, "base64"|"base64url"|"hex") }}`: n random bytes in that encoding.
- `${{ uuid() }}`: UUID v4.
- n is 8–1024. The resolver treats a leftover function as a broken expression.
- `variables.generator` keeps the generator of a value that was exactly one generator expression; copies into another environment re-evaluate it, so generated values are fresh per environment. Rows without a recorded generator are copied as they are.

**System variables** (read-only, shown collapsed in the editor):
- `LAUNCHROOM`, `LAUNCHROOM_PROJECT_ID`, `LAUNCHROOM_ENVIRONMENT(_ID|_KIND)`, `LAUNCHROOM_SERVICE(_ID)`.
- `PRIVATE_HOST` (the slug), `PORT`, `HOST`/`HOSTNAME=0.0.0.0` (not for databases), `PRIVATE_URL` (`http://<slug>:<port>`, web kinds), `DOMAIN`/`PUBLIC_URL` (first custom domain, else the generated one).
- Deployment-specific `LAUNCHROOM_DEPLOYMENT_ID` and `LAUNCHROOM_GIT_COMMIT_SHA` are added to the env snapshot but can't be referenced.

**Exported variables**: every service exports `PRIVATE_HOST`, `PRIVATE_URL`, `PUBLIC_URL`, `DOMAIN`, `PORT`. Database engines export `DATABASE_URL` plus parts (`HOST`, `PORT`, `USER`, `PASSWORD`, `DATABASE`), Redis exports `REDIS_URL`, and so on.

**Database credentials** are ordinary variables of the database service, created per environment by the engine driver (e.g. `POSTGRES_PASSWORD` = secret from `${{ secret(32) }}`, `DATABASE_URL` = a reference template). Connections inject references to them (`DB_PASSWORD=${{postgres.PGPASSWORD}}`).

### 3.2 Canvas connections

Dragging an edge from `api` to `postgres` opens a **Connect** popover that previews the variables to inject, using framework-aware naming:
- Laravel: `DB_CONNECTION=pgsql`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` (or `DB_URL`)
- Prisma/Node: `DATABASE_URL`
- Django: `DATABASE_URL` (dj-database-url)
- Redis: `REDIS_URL` or `REDIS_HOST`/`REDIS_PORT`/`REDIS_PASSWORD`

Accepting stores a **connection** row plus the injected references. These are ordinary reference variables, marked as connection-owned (deleting the edge removes them, after confirmation). An app → app edge (`web` → `api`) offers `API_URL` as private (`http://api:8000`) or public (`https://api…`). Browser-facing (`NEXT_PUBLIC_*`) variables are forced to public, with a warning explaining why.

### 3.3 Build vs runtime (automatic)

Each variable has an effective **exposure**: `runtime` (default), `build`, or `build+runtime`, computed automatically and overridable under Advanced:
- **Build+runtime automatically**:
  - framework-inlined prefixes: `NEXT_PUBLIC_*`, `VITE_*`, `PUBLIC_*` (SvelteKit/Astro), `REACT_APP_*`, `EXPO_PUBLIC_*`, `GATSBY_*`
  - build tooling: `SENTRY_AUTH_TOKEN`/`SENTRY_ORG`/`SENTRY_PROJECT`, `NODE_OPTIONS`, `NPM_TOKEN`/`NPM_CONFIG_*`, `COMPOSER_AUTH`, `PIP_INDEX_URL`/`UV_INDEX_URL`, `BUNDLE_*`
  - launchroom-generated build keys (`NEXT_SERVER_ACTIONS_ENCRYPTION_KEY`)
- `NUXT_PUBLIC_*` stays runtime (Nitro reads it at runtime).
- Inlined-prefix variables get a 🏗 badge: "baked into the browser bundle at build time, visible to anyone. Changing it requires a rebuild." They **cannot** be type `secret`.
- Everything else is runtime-only, so editing it doesn't rebuild ([04](04-build-and-deploy.md) §7).
- Build-scoped values reach builds only as BuildKit secrets. Warnings cover cases that bake values into output anyway: prerendering with DB access, Laravel `config:cache` at build (the preset moves it to container start).

### 3.4 Editor features

- Table with key, value (masked for secrets), environment chips, badges (🔒 secret, ↗ reference, 🏗 build, 🔗 connection).
- **Paste a `.env` anywhere** to bulk-import (preview table with conflicts and auto-detected secrets).
- Raw `.env` mode (secrets shown as placeholders that preserve existing values).
- Export (`.env` with references resolved; secrets only with permission, audited).
- CLI ([16](16-api-and-cli.md) §3): `launchroom env pull`, `launchroom env set`, `launchroom run -- <cmd>`, resolved server-side from the last deployed revision; secrets only with the `secrets:read` ability and the reveal permission (audited).
- System variables (read-only, collapsible) show what launchroom injects.

**`.env` import** (`EnvFileParser`):
- dotenv rules without interpolation: quotes, escapes in double quotes, multiline quoted values, `export`, inline comments only after whitespace in unquoted values, CRLF/BOM, last duplicate wins with a warning.
- A file with syntax errors is refused as a whole.
- Types are suggested from key patterns plus unmistakable value shapes (private keys, live API keys, GitHub/Slack/AWS tokens, URLs with passwords).

### 3.5 Storage

Envelope encryption ([09](09-security.md)):
- Each project has a data key wrapped by a key-encryption key (`LAUNCHROOM_KEK`, not `APP_KEY`).
- Values are encrypted with AEAD. The associated data of a value is `var|<team id>|<project id>|<variable public id>|<version>`; the leading tag domain-separates it from env snapshots (`env|<team>|<project>|<deployment public id>`). Every value change bumps `version`, so a ciphertext can't be replayed into another row or an older version.
- The deployment env snapshot is stored encrypted, and the UI shows names plus hashes.

### 3.6 Targets and per-environment rows

- Each environment created as a copy (§6.6) gets **its own rows** for the base's variables, so editing staging never changes production.
- Rows can still target several environments explicitly (chips in the editor). Editing such a row from one environment offers "**<env> only**" (default) or "every environment of this row". "Only" sends `onlyEnvironment`: the environment leaves the row and gets a row of its own with the edited key, type and value (the current value when none is entered), shared-variable opt-ins included (`UpdateVariable`). The raw `.env` editor splits the same way.
- Preview branch rows (targeted at "All previews" with a branch pattern) apply to previews of that PR branch and win over plain "All previews" rows.

### 3.7 "Production secrets must differ from preview"

`ProductionSecretPolicy`, on when the project (`projects.settings.productionSecretsMustDiffer`, Settings → General) or the team (`teams.settings.productionSecretsMustDiffer`) enables it.

- A secret row that applies to production may not have the same value (compared as keyed MACs in memory) as a row of the same key and service (or the same shared key) that applies to previews (targeted at "All previews" with any branch pattern, or at a preview environment). A row targeting both always violates it.
- Enforced:
  - on every save path through `SecretExposureGuard` (single edit, raw edit, import, shared usage),
  - on every deploy of production and previews: Deploy in `DeployEnvironment`; push and tag deploys in `DeployCommit`, which skips and logs; preview deploys in `DeployPreview`, which fails the preview with the message,
  - when copying an environment: a preview never gets a secret equal to production's, whatever environment it is copied from, so staging copied from production can't pass production's values on,
  - in Compare & promote: "copy value" between production and a preview is blocked.
- Turning the policy on is refused while secrets violate it. Every message names the key and service.

## 4. Staged changes

Staged changes work without draft tables:

- **Intent tables are the draft.** Every edit (variables, settings, domains, new services, connections, replica counts) writes immediately to the normal tables. Each environment has a `last_applied_revision`.
- **Revision** = an immutable, normalized JSON snapshot of the environment's full intent (`EnvironmentSpec`, a laravel-data object; variables only as ciphertext + version). It is created on every Deploy (D24).
- **Staged changes = diff(current intent, last_applied_revision)**, computed per environment and shown as a count pill plus a diff view grouped by service. Each change shows its effect ("api: restart", "web: rebuild", "postgres: new service").
- **Deploy** writes a new revision and creates deployments for the affected service instances. **Discard** restores the touched fields from the last revision (per item or all). An optional commit message goes to the activity feed.
- **Exceptions applied instantly** (no staging):
  - domain additions and removals, including the www redirect: `ApplyDomainRoutes` writes the service's current domains into a new revision on top of the last applied one ("Added shop.example.com"), so routes change without a deployment and other staged changes stay staged. Before the environment's first deploy (or while the service itself is new) they ride the first deploy
  - domain verification checks
  - git pushes (they deploy the last revision's config plus the new commit)
  - rollbacks
  - scaling to zero
  - canvas layout and positions
- Revisions double as the **audit trail** ("who changed what, when") and the basis of `launchroom.json` export/import ([18](18-config-as-code.md), D45): a file read at push time is applied to the intent tables like any edit, so its changes are ordinary staged changes of this diff.

### 4.1 Diff, effects and discard

Code: `App\Support\Changes\SpecDiff`, `App\Queries\CurrentEnvironmentSpec`.

- The diff is pure over two `EnvironmentSpec`s. Effects come from attributes on the settings Data; the strongest wins per service:
  - `new`/`remove`: service added, removed, enabled or disabled
  - `rebuild`: build-affecting fields, build-scoped variables
  - `restart`: runtime fields, runtime variables, volumes, connections, shared-variable usage
  - `route`: domains (applied immediately by their own revision, see the exceptions above; still listed for new services and never-deployed environments)
  - `none`: name, icon, watch paths, seed command, triggers
- Never-built kinds turn `rebuild` into `restart`. Services whose variables reference a changed service (`${{postgres.X}}`) or a changed shared variable get at least `restart` (`rebuild` if the referencing variable is build-scoped), transitively.
- Item paths: `services.<svc>`, `services.<svc>.<field>`, `services.<svc>.(build|deploy|scaling).<property>`, `services.<svc>.(variables|connectionVariables|sharedVariables).<var>`, `services.<svc>.domains.<hostname>`, `services.<svc>.volumes.<vol>`, `connections.<con>`, `sharedVariables.<var>`, `environment.(name|triggers)`. Items never carry variable values.
- **Removing a service** is staged (`service_instances.removed_at`); Deploy tears it down (workloads gone, volumes kept).
- **Discard** restores rows with their recorded ids (variables with their recorded version and ciphertext). A removed variable is restored targeted at this environment only (the revision records how it applied here, not all its targets).
- **Identity fields** (name, icon, source) are shared by all environments, so discarding them affects every environment.
- **Deploy** refuses when nothing is staged (after the first deploy) and when any enabled service has a broken reference (every offender is listed).

## 5. Previews

Code: `App\Actions\Previews`, `App\Services\Previews`, `App\Models\Previews` (tables in [07](07-data-model.md) "Previews"). Summary in D28.

### 5.1 Lifecycle

- **One `previews` row per pull request** (project + provider + repository + number) holds the PR's state and outlives its environment.
- `PreviewStatus`:
  - `skipped`: filters, fork policy off, base never deployed
  - `awaiting_approval`: forks (§5.3)
  - `queued`: no free slot
  - `preparing`: environment exists; databases start, clones restore
  - `running`: apps deployed for the head
  - `failed`: data preparation failed or broken references
  - `closed`/`expired`: torn down; a reopen or new push starts over
- **Slots**: a preview holds one of the project's `max_concurrent` slots (default 3) while it has an environment. Queued previews start oldest-first when a slot frees (teardown, settings change, the minute tick).
- **Gate** (`PreviewGate`, re-checked under the row lock when starting):
  - previews are enabled,
  - the target branch matches one of the comma-separated globs,
  - the required label is present,
  - the title has no `[skip preview]`/`[preview skip]`/`[no preview]`,
  - the fork policy allows it (§5.3),
  - the base environment was deployed.

  A filter that stops matching (label removed, title edited) tears the environment down (`skipped`).
- **Base**: the configured environment, else `staging` (persistent, slug `staging`) if it exists, else production.
- **Creation** (`CreatePreviewEnvironment`):
  - environment `pr-<n>` ("PR #n", `kind=preview`, `pr_number`, `pr_head_sha`, `pr_head_ref`, `base_environment_id`), its own /24, the base's canvas positions,
  - every current base instance provisioned from the base instance as template: settings, generated domains `{svc}-pr-{n}.…`, fresh database volume + credentials,
  - web services also get a **branch alias** `{svc}-git-{branch}.…` that redirects (308) to the PR URL,
  - the copy goes through `CopyEnvironmentIntent` (§6.6, recorded as the preview's Compare & promote base): the base's own variables become rows of the preview (fresh values for generated ones; **no secrets for fork PRs**, and never production secrets while "production secrets must differ" is on); "All previews" rows (and branch-pattern rows for the PR branch, which win) apply by targeting; connection-owned references apply wherever both services exist. Closing deletes the copies with the environment (`DeleteEnvironment`).
- **Resource caps**: 1 replica and at most `launchroom.previews.max_cpus` (0.5) CPU per replica by default. Memory and placement constraints follow the base, so an app needing 1 GB still gets it; raise them per preview in its settings. Previews are only placed on servers with **Allow previews** on (a server setting, [02](02-architecture.md) §2.1).
- **Deploy** (`DeployPreview`, idempotent):
  1. Snapshot a revision when the intent changed.
  2. Deploy never-deployed image-based services (databases first). The preview stays `preparing` until they run and clones are ready.
  3. Once per head commit (`data.deployedSha`), deploy the built services through `DeployCommit` with trigger `pr`.
- **Focused previews**: a service builds the PR head when its watch paths match the PR's changed files (GitHub compare `base...head`; more than 300 files or an error = unknown = everything builds), or when it is a worker/cron of such a service, or has a build-time variable referencing one. Every other service deploys the **commit its base instance runs**, so its build key equals the base deployment's and the build step reuses that image (`plan.reusedFrom`, [04](04-build-and-deploy.md) §7). The graph stays complete. Without watch paths every service builds the head.

### 5.2 Stateful services

Each stateful base instance has an "In previews" setting (Settings → Previews):

| Option | Behaviour | Default? |
|---|---|---|
| **New empty instance** (`new_empty`) | A fresh instance per preview. The app's release command runs migrations, then an optional **seed command** (`php artisan db:seed`, `prisma db seed`) runs once after its first deploy (`ReleaseRunner::seed`). | **Yes** |
| Clone from another database (`clone`) | Restores the source's latest backup snapshot into the preview's database. Details below and in [14](14-backup-and-restore.md) §6. | No |
| Share base instance (`share`) | Preview references resolve to the base environment's database. Migrations in PRs run against it, and the UI says so. | Available, never default |

Caches (Redis/Valkey) and non-database volumes default to new empty. **Fork PRs always get new empty instances**, whatever the setting (§5.3).

**Clone**:
- The user picks any database service of the project as the source (typically staging). The source DB takes no load.
- Once the preview's (empty) database runs, a `restore_runs` row (mode `new_service`) restores the source's **latest successful backup snapshot** into it through the backup machinery ([14](14-backup-and-restore.md) §6).
- Then the optional sanitize argv runs once as a `run_once` task (purpose `sanitize`) **in the database's image** on the preview network with the database's env. Only then do the apps deploy.
- **Size guard**: the dialog shows each candidate's size. The source's latest backup `size_bytes` must be ≤ the team limit (`teams.settings.previewCloneLimitMb`, default `LAUNCHROOM_PREVIEW_CLONE_LIMIT_MB` = 1024).
- A source without a successful backup is refused.
- Production sources need `preview_settings.allow_production_clone` ("Allow production data in previews", with a PII warning), which only `backups.manage` (owner/admin) can switch on.
- Failures fail the preview with the reason.

**Share**:
- The preview instance is disabled (no volume, no credentials). The revision records `sharedServices` (service → base environment), so references resolve to the base's last applied revision, and the preview's workloads also join the base environment's network (after their own, without aliases).
- The canvas edge shows a red "shared with <base>" badge; the settings page warns that PR migrations run against it.
- Sharing a **production** database needs `allow_production_clone`, checked when the strategy is saved and again when a preview is created. If it is off by then, the preview gets a new empty database, noted as `downgradeReason: production`.

### 5.3 Fork PRs

See also [09](09-security.md) R20.

- Fork = head repository id ≠ base repository id (deleted forks count as forks).
- Policy `off` (default) skips fork PRs.
- Policy `approval`:
  - authors with write/maintain/admin permission start automatically,
  - others wait until a member with `deployments.create` presses Approve, or a user with write access applies the `preview` label (the sender's permission is looked up on `labeled` events; a label by anyone else does nothing),
  - a failed permission lookup is stored as `unknown` (no write access) instead of keeping an earlier value.
- **An approval covers one head commit** (`approved_sha`). The Approve button posts the full head SHA the list showed, and `ApprovePreview` refuses (409, "new commits were pushed") when it is no longer the head under the row lock. A label approves the head in the `labeled` event. New fork commits wait again while the approved commit keeps running.
- **No secrets**: fork previews always run with `environments.secrets_withheld`. Every `secret` variable not targeted at the preview itself resolves to `""` (build-scoped ones too); only its own generated database credentials stay.
- **No base data, no base network**: `CreatePreviewEnvironment` sets every stateful instance whose base strategy is `share` or `clone` to `new_empty` (own volume and credentials, no clone source, no sanitize command) and records `previews.data.databases[<service>] = {strategy: new_empty, downgradedFrom, downgradeReason: fork}`. The previews list ("not shared with the base: forks never get base data") and the canvas ("new and empty (fork: no base data)") show it.
- Defence in depth for older revisions or a forged state: for a `secrets_withheld` environment, `CurrentEnvironmentSpec` emits no `sharedServices`, `SpecVariableResolver` never resolves against a base revision, `PreviewRoutes::sharedNetworks` never attaches a base network, and `PreviewDatabases` never starts a clone.
- Promotion into a fork preview never copies a secret value (a value must be entered), and Discard/Revert never recreate a withheld secret as a row of the preview.

### 5.4 Protection and URLs

- **Protection** ([05](05-networking-ingress.md) §4, [09](09-security.md) R20a): on by default per project. Previews require a launchroom login or a share link (`preview_share_links`, ≤ 30 days, revocable, revoked on teardown). CI bypass header `X-Launchroom-Bypass` with a per-project secret (stripped before the app). `noindex` on all non-production hosts.
- **URLs**: `{svc}-pr-{n}.{project}.{apps}` (localhost/sslip fallbacks as [05](05-networking-ingress.md) §5), the branch alias, and the immutable per-deployment URLs.

### 5.5 Cleanup

- `launchroom:previews:tick` (every minute): advance preparing previews, start queued ones, expire previews without new commits for `expiry_days` (default 7).
- `launchroom:previews:sweep-orphans` (daily 03:17): `GET /pulls/{n}` for open GitHub previews; closed → teardown. Covers missed webhooks.
- Teardown (`DestroyPreview` → `DeleteEnvironment` without the typed confirmation) goes through desired state: workloads leave the NodeStates, the edges drop the routes, the environment row is deleted (deployments, domains, volume rows and the variables that only applied there go with it).
- While a backup or restore of one of its databases runs (a clone), the removal waits (`data.pendingClose`) and the tick retries it.
- **Docker volumes stay on the servers** for `launchroom.volumes.preview_retention_hours` (default 7 days; `0` = next sweep) and are then deleted through an explicit NodeState request; backed-up ones only after a confirmation ([05](05-networking-ingress.md) §1.1).

### 5.6 GitHub feedback

`PreviewGitHub`:
- One GitHub Deployment per preview environment and head commit (environment `preview/pr-<n>`, transient) whose status aggregates the preview's deployments: in progress → success with the primary URL, or failure.
- Per-service check runs and a single PR comment table (all services, status, URLs), updated in place. Per-deployment Deployments API reporting (§2.4) is off for previews (`DeploymentStatusReport.deploymentApi = false`).
- Teardown posts `inactive` and marks the comment rows.

### 5.7 Local dev

`php artisan launchroom:dev:pr open|sync|reopen|close|merge|label --project=prj_… [--number --branch --sha --files --fork --title …]` simulates PR events for plain git sources (the `git-fixtures` server publishes fixture branches, e.g. node-hello's `preview` with two commits).

## 6. Promoting changes between environments

The everyday workflow: you change things in **staging** (add Redis, connect it, add `QUEUE_CONNECTION=redis`, raise the healthcheck timeout, add a worker), test them, and then want **the same changes in production without redoing them by hand**. Code is promoted by git (merge or tag) or by promoting a deployment's image. **Configuration** is promoted with this flow.

Code: `App\Support\Promotions\PromotionDiff` (pure three-way diff), `App\Services\Promotions\{PromotionSnapshots,PromotionPlanner,PromotionRecorder}`, `App\Actions\Promotions\PromoteChanges`, `App\Actions\Environments\{CopyEnvironmentIntent,CreateEnvironment,UpdateEnvironment,DeleteEnvironment,ResumeAutoDeploy}`, `App\Services\Environments\EnvironmentIntentCopier`, `App\Queries\{PromotionComparison,ProjectActivity}`.

### 6.1 User flow

1. In a persistent environment, the environment switcher shows a hint: "staging has 6 changes that aren't in production · [Compare]". It is fetched when the switcher opens (`GET /p/{project}/{env}/-/promotion-hint`, compared with production).
2. **Compare & promote** (`/p/{project}/promote?from=<env>&to=<env>`) shows a diff grouped by service, with a checkbox per item:

```
Promote staging → production
 ☑ + redis                 new database service (fresh instance in production, new credentials)
 ☑ + api → redis           connection: injects REDIS_URL = ${{redis.REDIS_URL}}
 ☑ ~ api                   variable QUEUE_CONNECTION: database → redis
 ☑ ~ api                   healthcheck timeout: 30s → 60s
 ☑ + api-queue             new worker process (php artisan queue:work)
 ☐ ~ web                   replicas: 1 → 2               (environment-specific, unchecked by default)
 ⚠ + api                   new secret STRIPE_WEBHOOK_SECRET: enter the production value  [ ••••••• ]
 Also promote images: ☑ api (staging dpl_8f3a, same build inputs) ☐ web (build inputs differ: NEXT_PUBLIC_API_URL)
                                                              [Cancel]  [Stage in production →]
```

3. "Stage in production" applies the selected items to production's intent as **staged changes**. Nothing goes live yet.
4. Review production's staged changes (with any production-only edits mixed in) and press **Deploy**. This is the same Deploy as anywhere else: one revision, health-gated rollouts, instant rollback.

The same flow works in any direction and between any two environments: production → staging to "refresh staging's config", or staging → qa. Creating a new environment ("copy of production") and creating previews use the same copy machinery (§6.6).

**Stage in target** (`POST /p/{project}/promote`, `PromoteChanges`, policy `EnvironmentPolicy::promote` = manage projects + write variables):
- Recomputes the plan and refuses selections that are no longer offered ("changed since you opened the comparison").
- Applies, in order: services (`EnvironmentIntentCopier::copyService`: settings from the source instance, fresh credentials, empty volumes, a generated domain, the canvas position), shared variables, settings, variables (in place when the target's row applies only there, else split), opt-ins, volumes, triggers, and service removals last (staged removal).
- Save-time checks (browser-inlined secrets, the secrets policy, §3.7) run on the changed keys.
- Records a `promoted` row whose `snapshot` is the source with the **not promoted** items reset to their old base (so items left out are offered again next time) and audits `environment.changes_promoted`. Nothing is deployed.

### 6.2 What gets promoted by default

Every field in the `EnvironmentSpec` Data classes is annotated with a promotion class (`#[Promotion]`). The diff engine reads the annotation, so new settings get the right behaviour by declaring it once.

| Class | Examples | Default in the promote dialog |
|---|---|---|
| **Structural** | Services added/removed, connections, worker/cron processes, build settings and overrides, start/release/seed commands, healthcheck, drain settings, volume mount paths, variable *existence*, config-type variable values that are references or identical across environments | ☑ included |
| **Environment-specific** | Domains, replicas, CPU/memory, placement and server labels, triggers/branches, preview settings, variable values explicitly targeted at one environment | ☐ shown but unchecked |
| **Never promoted** | Secret *values* (the user enters the target's value or explicitly chooses "copy value", which is blocked when the "production secrets must differ" policy is on), generated credentials (the target gets its own), volume *data*, deployment history | Not selectable; new secrets show an input |

References like `${{redis.REDIS_URL}}` are environment-relative, so they are copied verbatim and resolve to the target's own Redis. A promoted change works in the target environment without edits because of this.

**Promotable form.** `PromotionSnapshots::of()` turns an `EnvironmentSpec` into entries keyed by paths that mean the same thing in every environment:
- `services.<svc>` (kind), `services.<svc>.(enabled|branchOverride)`, `services.<svc>.(build|deploy|scaling).<property>` (every `#[ChangeAffects]` property, class from `#[Promotion]`)
- `services.<svc>.variables.<KEY>` and `sharedVariables.<KEY>`: environment-layer rows **by key**, because each environment has its own rows
- `services.<svc>.sharedVariables.<KEY>` (opt-ins), `services.<svc>.volumes.<mount path>`, `connections.<con>`, `environment.triggers`
- A variable's fingerprint is its type, exposure and a keyed MAC of its value (`ProjectKeyring::mac`, never the value). Generated values have one fingerprint whatever their value (never promoted, never noise).
- Left out: identity (name, icon, source: project-wide), domains (hostnames are unique per install), connection-owned variables (they follow the connection) and preview branch overrides.

### 6.3 How the diff works (three-way)

A naive "make production look like staging" would wipe production's intentional differences (domains, replicas, production-only variables). So promotion uses a **three-way diff**:
- **base**: what the source looked like at the last promotion between the two environments, or when the target was created from it;
- **source**: the source's current intent;
- **target**: the target's current intent.

Only changes made in the source **since the base** are offered. Items where the target changed the same field differently since the base are shown as **conflicts** ("staging: 60s, production: 45s, was 30s"), and the user picks one side. After a promotion is staged, a row in `environment_promotions` records it ([07](07-data-model.md)), so the next comparison only shows newer changes and the activity feed tells the story ("Promoted 5 changes from staging by @bennett").

**Base.** The latest `environment_promotions` row between the two environments, in either direction: its `snapshot` (the source side) when it went from → to, its `target_snapshot` (the target right after) when it went to → from.
- Creating an environment records a row of kind `created`, which stands for "the state the target was created from". The copy uses current intent, which can include staged changes, so a revision id alone would not be exact; `source_revision_id` is kept for reference.
- Without any row the target stands in for the base: every difference is offered, no conflicts.
- Stored snapshots keep settings displays (for "was …") but never variable values.
- After a project key rotation variable fingerprints change once, so their values show up as changed in the next comparison.

**Diff rules** (`PromotionDiff`, exhaustive tests in `tests/Unit/Support/Promotions/PromotionDiffTest.php`):
- An item is offered when the source changed it since the base and the target doesn't already match. It is a conflict when the target changed it too (shown with both sides and the base, unchecked; checking it takes the source's side).
- Added services carry their settings and volumes (not listed separately). Their variables and opt-ins **depend** on them: the dialog selects dependencies along, and the action refuses a selection without them.
- Items of a service the target removed on purpose aren't offered.
- Service removals are never pre-checked, with a red warning when the service holds data.
- Volumes are added (empty) but never removed.
- Connections are informational ("follows the services").
- Variables: config values are structural (checked); new secrets are "never" class and need a value for the target or an explicit **copy**; changed secret values are offered unchecked and need a value; type/exposure changes of secrets keep the target's value unless one is entered; new generated values are generated afresh.

### 6.4 Images and code

- **Also promote images** lists built services whose source deployment is active with an image the target doesn't run yet, with the build inputs that differ (build settings, branch override, build-scoped variable values compared as MACs).
- Selected services are pinned on the promotion (`images`: deployment, commit, ref). The target's next Deploy creates a `promote` deployment of exactly that commit for them (also when nothing else changed) and marks the pins applied. The staged changes page lists pending pins.
- With equal inputs the build key matches and the source's image is reused ([04](04-build-and-deploy.md) §7). Otherwise the same commit is rebuilt with the target's build-scoped variables, and the dialog says why.
- If production deploys from tags or `main`, the usual flow is: merge or tag in git (code), and promote config in launchroom. Promoting images directly is for teams that want "exactly what we tested in staging" (build once, promote everywhere).

### 6.5 Production safety

- **Staged production deployments**: Deploy with "Stage without routing traffic" (`stage`) sets `deployments.hold_traffic` on stateless services (databases deploy as usual). Healthy, they stop in status `ready` ("Ready to promote"): running next to the live version, not routed. `POST …/d/{dpl}/promote` (`PromoteStagedDeployment`) moves them to `routing` (no rebuild, no restart). Cancelling a ready deployment removes its workloads. A ready deployment counts as in flight, so later deployments of the instance queue behind it.
- Instant rollback and the auto-deploy pause (resumed with `ResumeAutoDeploy`) are described in [04](04-build-and-deploy.md) §8. Rolling back a deployment does not revert promoted *config*; "Revert to revision N" covers that.
- **Revert to revision N**: `POST /p/{project}/{env}/revisions/{rev}/revert` runs `DiscardChanges` against that revision, restoring its intent as staged changes. Restored values get new versions (a version number stays bound to one value). Listed on the staged changes page.

### 6.6 Copying environments and activity

- **Copying an environment.** `CopyEnvironmentIntent` (used by `CreateEnvironment` and by previews; options `secrets`, `variables`, `serviceIds`, `recordBase`) copies the current instances with their settings, volumes (empty) and canvas positions, and the variables targeted at the base as rows of their own (§3.6). Keys the target already has (e.g. new database credentials) are kept. It records the `created` promotion base (§6.3).
- **Deleting an environment.** `DeleteEnvironment` (never production; typed name, recent password; refused while a backup/restore of its databases runs; databases with backups need `confirmBackups`) tears down through desired state like `DeleteProject`, deletes rows that only applied there and services that only existed there, and retires its volumes (kept for the retention period, then deleted explicitly, [05](05-networking-ingress.md) §1.1).
- **Activity**: `/p/{project}/activity` lists deploys (with their messages), environment copies, promotions ("Promoted 5 changes from staging to production") and rollbacks from the domain tables. The audit log keeps the full trail.
