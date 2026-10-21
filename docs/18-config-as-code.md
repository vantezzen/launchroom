# 18: Config as code (`launchroom.json`)

Decision **D45** in the [decision log](README.md). Builds on staged changes and revisions ([06](06-variables-environments.md) §4), previews ([06](06-variables-environments.md) §5), the public API and CLI ([16](16-api-and-cli.md)) and the preview trust rules ([09](09-security.md) R20).

A project can keep its service configuration in a `launchroom.json` file next to its code. The dashboard reads the file at the commit it deploys and turns it into **staged changes** of that environment, through the same machinery as an edit in the UI: one `SpecDiff`, one revision per Deploy, the same review, promotion and rollback. The file never reaches launchroomd and never carries a secret value.

## 1. What the file is (and isn't)

- An **overlay**, not a full description: the file manages exactly the settings it names. Everything it leaves out stays in the dashboard, editable as before. A field that leaves the file stops being managed and keeps its last value (nothing is reset to a default behind your back).
- **Project-wide with per-environment overrides**: one file describes the services; `environments.<slug>` (and `environments.previews` for every preview) override settings for one environment.
- **Read per environment, at that environment's commit**: production reads the file of the commit it deploys from `main`, staging the file on `develop`, a preview the file of the PR head. An environment always runs the configuration of the code it runs.
- Not a Compose file and not a template format of its own, though templates (D44) can be expressed as one (§10).

## 2. Format

### 2.1 JSON, strictly

`launchroom.json` is **JSON (RFC 8259), UTF-8**. No comments, no trailing commas, no duplicate keys. Decided against:

- **YAML**: implicit typing (`no` → false, `1.10` → 1.1, `010` → 8), anchors and aliases (the "billion laughs" expansion), and several incompatible 1.1/1.2 parsers. A config that decides which servers run your app should mean exactly one thing.
- **JSONC/JSON5**: comments would be nice, but editors treat `*.json` as plain JSON and flag comments, schema-driven autocompletion works best on plain JSON, and every other tool (`jq`, CI linters) reads it unchanged. Explanations go in the commit, a README, or a `"description"` field where the schema offers one.
- **TOML**: no nesting-friendly syntax for per-environment overrides and no first-class JSON Schema tooling.

The dashboard parses the file with its own small position-tracking parser (`App\Support\ConfigAsCode\JsonDocument`), so every error, syntax or schema, comes with a line and column (§6). Duplicate keys are an error (`json_decode` would silently keep the last one).

### 2.2 Versioned JSON Schema

- `version: 1` is required. Additive changes (new optional fields) stay in v1; anything that changes the meaning of an existing file is v2, and the dashboard keeps reading v1.
- The schema is committed at `dashboard/resources/schema/launchroom.v1.json` (JSON Schema 2020-12), published as **`https://launchroom.vantezzen.io/schema/launchroom.v1.json`** (its `$id`; the site serves the file attached to every GitHub release as `launchroom.v1.json`) and served by every dashboard at `/schema/launchroom.v1.json` (public, no instance data, CORS open, so a self-hosted team can point `$schema` at its own dashboard). `"$schema"` pointing at it gives autocompletion, hover documentation and red squiggles in VS Code, JetBrains IDEs and anything else that speaks JSON Schema. The export (§4) always writes it.
- The schema is **strict**: `additionalProperties: false` everywhere, so a typo is an error, not a silently ignored key. Every property has a description (it is the editor documentation).
- A drift test keeps the schema and the dashboard aligned: enum values, limits and patterns are compared with the settings Data classes (`BuildSettings`, `DeploySettings`, `HealthcheckSettings`, `ScalingSettings`), and every exported fixture validates.

### 2.3 Shape

```json
{
  "$schema": "https://launchroom.vantezzen.io/schema/launchroom.v1.json",
  "version": 1,
  "services": {
    "web": {
      "kind": "app",
      "rootDirectory": "apps/web",
      "build": { "buildCommand": "npm run build", "watchPaths": ["apps/web", "packages/ui"] },
      "deploy": {
        "port": 3000,
        "startCommand": ["npm", "start"],
        "healthcheck": { "path": "/api/health", "timeoutSeconds": 5 }
      },
      "scaling": { "replicas": 2, "memoryMb": 1024 },
      "domains": ["shop.example.com", { "hostname": "www.shop.example.com", "redirectTo": "shop.example.com" }],
      "variables": {
        "NEXT_PUBLIC_API_URL": "${{api.PUBLIC_URL}}",
        "LOG_LEVEL": "info",
        "STRIPE_SECRET_KEY": { "secret": true }
      },
      "connections": ["api"]
    },
    "api": {
      "kind": "app",
      "rootDirectory": "apps/api",
      "deploy": { "port": 8000, "releaseCommand": ["php", "artisan", "migrate", "--force"] },
      "variables": { "APP_KEY": "${{ secret(32) }}", "QUEUE_CONNECTION": "redis" },
      "connections": ["postgres", { "to": "redis", "style": "laravel" }]
    },
    "queue": { "kind": "worker", "of": "api", "deploy": { "startCommand": ["php", "artisan", "queue:work"] } },
    "postgres": { "kind": "database", "engine": "postgres", "version": "18" },
    "redis": { "kind": "database", "engine": "valkey", "version": "8" }
  },
  "environments": {
    "staging": { "services": { "web": { "scaling": { "replicas": 1 }, "domains": ["staging.shop.example.com"] } } },
    "previews": { "services": { "web": { "variables": { "LOG_LEVEL": "debug" } } } }
  }
}
```

**Top level**: `$schema` (optional), `version` (required, `1`), `services` (required, object keyed by service slug), `environments` (optional).

**A service** (key = slug, the DNS name and reference name, `^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$`, not `shared`/`launchroom`/`localhost`):

| Field | Kinds | Meaning (setting it makes it managed) |
|---|---|---|
| `kind` | all, required | `app` · `image` · `database` · `worker` · `cron`. Can't change for an existing service |
| `name` | all | Display name (1–63). Identity (§3.3) |
| `rootDirectory` | app | Directory of the app in this repository. Identity |
| `image` | image, required | Image reference (`ghcr.io/org/app:1.2`, `…@sha256:…`). Identity |
| `engine`, `version` | database, required | `postgres` · `mysql` · `redis` · `valkey` · `mongodb` · `clickhouse` (D47) and a supported major version. Must match an existing database (engine changes and upgrades stay in the dashboard) |
| `of` | worker, cron, required | The app whose image it runs |
| `branch` | app, worker, cron | Branch override for this service (environment-specific) |
| `enabled` | all | `false` keeps the service out of this environment |
| `build` | app | `builder` (`auto` · `dockerfile` · `railpack` · `static`), `dockerfilePath`, `installCommand`, `buildCommand`, `railpackVersion`, `watchPaths` |
| `deploy` | all but database | `port`, `startCommand`, `releaseCommand`, `seedCommand` (argv arrays: never a shell string, [09](09-security.md) R1), `drainSeconds`, `entrypoint` (argv, replaces the image's ENTRYPOINT), `capabilities` (launchroomd's allow-list: `CHOWN`, `DAC_OVERRIDE`, `FOWNER`, `NET_BIND_SERVICE`, `SETGID`, `SETUID`), `schedule` (cron only: {`cron` 5-field expression, `timeZone` IANA name, default `UTC`}; `null` runs continuously; D51), `healthcheck` {`type` `http`·`tcp`·`none`, `path`, `expectStatus`, `startPeriodSeconds`, `intervalSeconds`, `timeoutSeconds`, `failureThreshold`} |
| `scaling` | all | `replicas`, `cpus`, `memoryMb`, `placement` {`labels` (server labels the servers must carry), `strategy` `spread`·`pack`} |
| `variables` | all | `KEY: "value"` (config, stored verbatim, `${{ }}` references and `${{ secret(32) }}`-style generators allowed) or `KEY: { "secret": true }` (a **required secret**: must exist in the environment; its value is set in the dashboard or CLI) |
| `domains` | app, image | Custom domains: `"host"` or `{ "hostname", "redirectTo" }` |
| `volumes` | app, image | Mount paths (`"/data"`) |
| `connections` | all | `"postgres"` or `{ "to", "mode" (private · public), "style" (url · laravel · parts) }`, like a canvas edge ([06](06-variables-environments.md) §3.2) |

Field names and limits are those of the settings Data classes (so the file, the UI and the API say the same thing). A `field` inside `build`/`deploy`/`scaling`/`healthcheck` is managed on its own: `"healthcheck": { "path": "/health" }` manages the path and leaves the timeouts to the dashboard.

Cron services are a kind with a command and an optional `deploy.schedule` (D51; validated with launchroomd's cron grammar, refused on other kinds). Shared variables, preview database strategies, environment triggers, server allow/deny lists (instance-specific server ids) and backup schedules stay in the dashboard (§11).

**Environment overrides**: `environments.<environment slug>.services.<slug>` takes the same fields as a service **except** identity and wiring (`kind`, `name`, `rootDirectory`, `image`, `engine`, `version`, `of`, `connections`). `environments.previews` applies to every preview environment (`previews` is reserved for this; no environment can take the slug). Overrides merge into the service: objects merge key by key, everything else replaces, and `null` removes the key (that setting is not managed by the file in this environment). An override for a service that isn't in `services`, or for an environment that doesn't exist, is an error (catches typos); `previews` always exists.

**Limits** (also in the schema): file ≤ 256 KiB, nesting ≤ 16, ≤ 100 services, ≤ 200 variables per service, ≤ 20 domains and ≤ 10 volumes per service, strings as in the settings Data (commands ≤ 256 arguments of ≤ 4096 bytes).

## 3. Applying a file

### 3.1 Where the file comes from

Project **Settings → General → Config file** (`projects.settings.configFile`):

- **Path**: `launchroom.json` by default, any repository-relative path (`deploy/launchroom.json`), validated like a root directory ([09](09-security.md) R4).
- **Repository**: the repository holding the file. Defaults to the repository of the project's app services when they all use one; a project with several repositories picks one (until then no file is read, and the card says so).
- **On/off**: on by default. Off: no file is read, nothing is managed.

The dashboard reads the file through the git provider contract of D46, `GitProvider::fileAtRef()` (`App\Services\ConfigAsCode\ConfigFileReader`): GitHub uses the contents API at the exact commit SHA with a read-only installation token scoped to that one repository and the raw media type; GitLab and Gitea their file APIs. Plain git servers have no file API, so their projects read no file (the push deploys as before, logged). A missing file (404) means "nothing managed"; a provider that can't answer right now leaves the push to deploy without the file (logged), never half-applied.

### 3.2 When it is read

| Moment | What happens |
|---|---|
| **Push** to a branch/tag an environment deploys (`DeployOnGitPush`) | The file at the pushed commit is read once per push and applied to each matching environment before its deployments are created (§3.4) |
| **Pull request head** (`DeployPreview`) | Applied to the preview environment at the head commit, with the preview trust rules (§5) |
| **"Check now"** in Settings → Config file, `POST …/config/sync` | Reads the file at the head of the environment's tracked branch and stages the result (nothing deploys) |
| `launchroom config diff`, `…/config/diff` | A dry run: the changes the file would stage, nothing is written (§7) |

Manual Deploy, Redeploy and Rollback never read the file: they deploy what is in the dashboard (and a rollback is "the previous version", including its configuration).

### 3.3 What applying does

`App\Actions\ConfigAsCode\ApplyConfigFile` takes the environment, the parsed file and a trust level, and writes the effective configuration (base ⊕ override) into the intent tables through the same services the UI uses, inside one transaction under the environment's row lock:

- **Services** named in the file and missing in the environment are created there (`CreateService`; an existing service of another environment gets an instance here, `ServiceProvisioner`). Services are **never removed** by the file: one that isn't in the file stays and simply isn't managed ("not in launchroom.json" on its settings).
- **Settings** (`branch`, `enabled`, every field of `build`, `deploy`, `scaling`) are written when they differ.
- **Identity** (`name`, `rootDirectory`, `image`) is shared by all environments ([06](06-variables-environments.md) §4.1), so only the **production** environment's file changes it for existing services; in other environments a different value is ignored with a note ("image follows production's launchroom.json"). New services take it from whichever file creates them.
- **Variables**: a config value creates or updates the service's row for this environment (split off when the row targets others, like the UI's "this environment only"); a generator expression creates a generated secret once and is never re-rolled while the row exists; `{ "secret": true }` requires a secret row that applies here (missing, or a config row of that key, is an error with the key's position, and nothing is applied). Variables the file **managed before** and that are gone from it are removed. Others are untouched.
- **Domains** are added and removed through `AddDomain`/`RemoveDomain` and apply immediately, exactly like in the UI ([06](06-variables-environments.md) §4 exceptions). Only custom domains are managed; generated ones never. A custom domain the file managed before and that is gone is removed.
- **Volumes** are added (empty); never removed (data).
- **Connections** are created like canvas edges (project-wide, injecting their reference variables); never removed by the file (a connection serves every environment).

Every write is an ordinary intent edit, so the result is a set of **staged changes** computed by `SpecDiff` against the last applied revision, with effects (`rebuild`, `restart`, `new`, …) as always. The action records `environment_config_files` (§8): the commit, the status and the **managed map** (per service: managed fields, variable keys, domains, the file line of each service) that the UI reads.

The whole apply is refused (nothing written) when the file is invalid or a rule is violated (unknown parent, a required secret missing, a domain already used elsewhere, a scaling value no server can satisfy).

### 3.4 Pushes: the file deploys with its commit

A push carries its code and its configuration together:

1. The file at the pushed commit is read and validated. **Invalid → the push fails** for that environment: every service the push would deploy gets a failed deployment (class `config_error`, with the file errors, §6), GitHub gets a failed status ("launchroom.json is invalid: line 14, column 9"), nothing is staged.
2. Valid → applied (§3.3).
3. If the environment had **no other staged changes** before the push, the push deploys the file's changes with the code: one revision (message "launchroom.json · 3b7d5e1", trigger `push`), deployments for the services the changes affect, and the pushed commit for the pushed services (no double deployment: the commit is pinned on them).
4. If other changes were already staged (someone's unfinished edit), the push does **not** deploy them: the file's changes join the staged changes, the push deploys the code with the last applied configuration as before ([06](06-variables-environments.md) §2), and the review page says "launchroom.json changes from 3b7d5e1 are waiting with 2 other changes. Deploy to apply them."

This keeps the rule that a push never ships somebody's half-finished dashboard edit, while a team that keeps its config in the file gets push-to-deploy for config and code together.

### 3.5 Conflicts: the file wins on the next push

- **In the UI**, settings pages with managed fields show a "Managed by launchroom.json" callout ("These settings come from launchroom.json in acme/shop at 3b7d5e1.") with **Edit in launchroom.json ↗** (the file on GitHub at that commit, at the service's line), and their inputs are read-only (a page with any managed setting is read-only as a whole; the callout lists which settings come from the file, and pages without any stay editable). Managed variables carry a file icon; their config values can't be edited or deleted in the Variables tab (a required secret keeps its value in the dashboard, so it stays editable).
- **The API and CLI are not blocked** (scripts may need to override in an emergency), but the file wins: an edit of a managed setting is shown in the staged changes with "launchroom.json will set this back on the next push", and the next push re-applies the file. The rule is one sentence: **what the file says wins on the next push; the dashboard decides everything the file doesn't say.**

## 4. Export

The current environment can be downloaded as a `launchroom.json` that **imports back without a single change** (round trip, tested):

- **UI**: Settings → General → Config file → **Download** on each environment's row (`GET /p/{project}/{environment}/-/config`, an attachment named after the configured path).
- **API**: `GET /api/v1/environments/{environment}/config` (`env:read`), `?revision=N` exports a deployed revision instead of the current intent.
- **CLI**: `launchroom config pull [FILE] [--revision N] [--yes]` (default `./launchroom.json`; asks before overwriting).

`App\Services\ConfigAsCode\ConfigExporter` works on an `EnvironmentSpec` (current intent or any revision), so "export a revision" is the same code. It writes `$schema`, `version`, and per service: kind and identity, `branch` and `enabled: false` when set, every `build`/`deploy`/`scaling` field that differs from what a new service of that kind would get (databases' engine-driver defaults are left out), config variables verbatim, generated secrets as their generator (`${{ secret(32) }}`, fresh per environment), other secrets as `{ "secret": true }`, custom domains, extra volumes and outgoing connections. Left out: connection-owned and engine-driver variables (they come with the connection or the database), generated domains, server allow/deny lists, secret values (never). Keys are sorted; the output is stable for diffs. Export is audited (`config_file.exported`, key counts, never values).

## 5. Trust: previews and forks

The file in a pull request is written by whoever opened it. Previews already run that code with caps ([06](06-variables-environments.md) §5, [09](09-security.md) R20); the file must not lift them. `ConfigTrust` is `trusted` (pushes, Check now), `preview` (same-repository PRs) or `fork` (fork PRs), and the applier filters by it before writing anything:

| Setting in a PR's file | `preview` | `fork` |
|---|---|---|
| `domains` | ignored (previews get generated URLs) | ignored |
| `scaling.replicas`, `scaling.cpus` | clamped to the preview caps (1 replica, `launchroom.previews.max_cpus`) | clamped |
| `scaling.placement` | ignored (previews go to servers with Allow previews) | ignored |
| `{ "secret": true }` | not enforced (previews may lack a secret; fork previews have none) | not enforced |
| Identity (`name`, `image`, `rootDirectory`), `enabled`, `branch` | ignored | ignored |
| New services, `connections`, `volumes` | applied | **ignored** (a fork can't add containers or wiring to the preview) |
| `build`, `deploy`, config `variables` | applied | applied (fork previews have `secrets_withheld`: references to secrets resolve empty) |

Everything ignored or clamped is listed on the preview ("launchroom.json: domains ignored in previews") and in the dry run. The preview of a PR never writes into its base environment: overrides apply to the preview's own copy.

## 6. Validation and friendly failures

Three layers, all reporting `{line, column, path, message, hint?}` with the JSON pointer turned into the dotted path users see (`services.web.deploy.port`):

1. **Syntax** (`JsonDocument`): unexpected character, unterminated string, trailing comma ("Remove the comma before `}`"), comments ("JSON doesn't allow comments"), duplicate key ("`port` is set twice in services.web.deploy (first on line 12)"), size and depth limits.
2. **Schema** (opis/json-schema against the committed schema): unknown keys with a "did you mean `buildCommand`?" suggestion (Levenshtein over the allowed keys), wrong types ("should be a list of arguments like [\"npm\", \"start\"], not a string"), enums ("`builder` must be one of auto, dockerfile, railpack, static"), ranges.
3. **Rules** (`ConfigFileValidator`): per-kind required fields (`image` for image services), `of` must name an app, kinds/engines can't change, overrides must name known services and environments, variable keys and expressions are valid ([06](06-variables-environments.md) §3.1), **values that look like secrets are refused** (private keys, live API keys, tokens, URLs with passwords: "This looks like a secret. Store it in the dashboard and write `{ \"secret\": true }` here."), keys that usually hold secrets (`*_SECRET`, `*_TOKEN`, …) with a literal value get a warning, required secrets exist.

A failed push shows on the deployment page as a **FailureCard** ("launchroom.json has 2 problems", "web is still serving 9f2c1ab"), then one card per error: the file path and line, a 5-line excerpt in mono with the line highlighted and a caret under the column, the message and the hint, and "Open on GitHub ↗" at that line. The deployments list shows the usual failed row ("launchroom.json: line 14"). The same rendering is reused by Settings → Config file ("Last read 3b7d5e1: 2 problems") and the CLI prints the excerpt with a caret.

## 7. API & CLI

| Method & path | Ability | Notes |
|---|---|---|
| `GET /environments/{environment}/config` | env:read | `{path, content, revision}`: the exported file (§4); `?revision=N` |
| `POST /environments/{environment}/config/validate` | read | `{content}` (≤ 256 KiB) → `{valid, errors, warnings}`; layers 1–3 against this environment (layer 3 needs the project's services) |
| `POST /environments/{environment}/config/diff` | read | `{content}` → `{valid, errors, warnings, changes, ignored}`: the staged changes the file would produce here (dry run in a rolled-back transaction, no domain checks, no audit) |
| `GET /environments/{environment}/config/status` | read | The last read: commit, status, managed counts, errors |

CLI (`cli/internal/commands/config.go`):

- `launchroom config pull [FILE] [--revision N] [--yes]`: writes the export (0644, atomically), asks before overwriting.
- `launchroom config validate [FILE]`: local syntax check first (Go's decoder with offsets → line/column, works offline), then the server's validation; errors print as `launchroom.json:14:9  services.web.deploy.port should be a number` plus the excerpt and caret. Exit 6 when invalid.
- `launchroom config diff [FILE]`: the dry run: `+ redis  new database service`, `~ web  build command: npm run build → pnpm build (rebuild)`, ignored items dimmed. `--json` prints the changes.

All three default to `./launchroom.json` (or the project's configured path, when the CLI is run from the repository root) and take the usual `--project/--environment`, `--json`.

## 8. Data model

| Table / column | Notes |
|---|---|
| `environment_config_files` | `team_id`, `environment_id` (unique), `path`, `repository` (full name), `commit_sha`, `status` (`ConfigFileStatus`: `applied` · `staged` · `unchanged` · `invalid` · `missing`), `managed` (jsonb `ManagedConfigData`: services → slug, line, fields, variables, domains), `errors` / `warnings` / `ignored` (jsonb lists of `ConfigFileProblemData`), `content_hash`, `checked_at`, `applied_at`. One row per environment, overwritten on every read; history is the audit log (`config_file.applied`, `config_file.invalid`) and the revisions |
| `deployments.config_errors` | jsonb, null unless the deployment failed on the file: `{path, commitSha, errors}` for the FailureCard |
| `projects.settings.configFile` | `ConfigFileSettingsData {enabled, path, repositoryId}` |

## 9. Security

- **No secret values, by construction**: the schema has no field for one (`{ "secret": true }` admits no `value`), secret-shaped literals are refused, export writes `{ "secret": true }`. Generated secrets are evaluated in the dashboard ([06](06-variables-environments.md) §3.1), never stored in the file.
- **Strict schema**, size/depth/count limits, duplicate keys refused; every identifier validated by grammar again by the Actions it flows into (slugs, hostnames, mount paths, label keys, image references, argv), so the file can't do anything the UI can't.
- **Push access is configuration access** for the environments that deploy from that branch, as it already is for code. Production's file is the one on production's branch; protect it the way the code is protected (branch protection). Applying from a push is audited as the system with the path, commit and trust level (`config_file.applied`, `config_file.invalid`); the commit names its author.
- **Previews and forks** follow §5: no domains, no placement, capped scaling, no secrets requirement; forks additionally add no services, volumes or connections.
- **Problems never store a secret**: a value refused as secret-looking is masked (`"••••••"`) in the stored excerpt, and messages name keys, never values. Only the SHA-256 of the file is kept (`content_hash`), not the file.
- The file is fetched with a read-only installation token for that one repository, at the exact commit SHA, never following redirects to other hosts; responses over the size limit are refused before parsing.

## 10. Templates and blueprints

Templates and Compose import (D44, [17](17-templates-and-compose.md)) translate into a `ProjectBlueprint` applied by `ApplyBlueprint`, which **creates** services in bulk (next free slug when one is taken). Applying a `launchroom.json` is different: it **updates** an existing environment by slug, field by field, tracks what it manages and filters by trust, so it has its own applier and doesn't go through `ApplyBlueprint`. The two share the vocabulary on purpose: slugs as keys, kinds, `image` / `engine`+`version` / `of`, argv commands, `${{ }}` references and generators, `connections` with `style`, mount paths, the same validation rules (`AddServiceVolume`, `CreateConnection`, the variable store).

## 11. What the file doesn't manage

- Removing services (data; done in the dashboard).
- Shared variables and their opt-ins.
- Preview database strategies.
- Environment triggers.
- Server allow/deny lists (instance-specific ids).
- Backup schedules.

The export writes one environment; `environments` overrides are written by hand. Pages with managed settings are read-only as a whole; the callout lists the managed fields.

## 12. Implementation map

- `App\Support\ConfigAsCode` (pure): `JsonDocument` + `JsonDocumentParser` (strict parser with positions), `ConfigSchema` (opis/json-schema over the committed schema, friendly messages, "did you mean"), `ConfigFileValidator` (rules, secret-value refusal), `ConfigFile` + `EffectiveService` (base ⊕ override, pointers back into the file), `Problems` (line/column, excerpt, masking), `ConfigContext`, `ConfigCheck`, `Arrays`.
- `App\Services\ConfigAsCode`: `ConfigApplier` (trust filter, then the dashboard's own Actions and services; throws `ConfigApplyFailed` with positioned problems), `ConfigExporter`, `ConfigFileReader` (settings → repository → `fileAtRef`), `ManagedPaths` (staged items the file manages).
- `App\Actions\ConfigAsCode`: `ApplyConfigFile` (check + apply + record, nested transaction so a failed apply writes nothing), `ApplyConfigFileOnPush` (§3.4), `ApplyPreviewConfigFile` (§5, once per head commit, `previews.data.configSha`/`configError`), `DryRunConfigFile` (rolled back), `ExportEnvironmentConfig`, `SyncConfigFile`, `UpdateConfigFileSettings`.
- Hooks: `DeployOnGitPush` groups matched instances per environment (a change of the file's path counts as touching every service), `DeployPreview` (before its revision snapshot), `DeployEnvironment` (`$commits`: the pushed commit pinned on pushed services), `StagedChangesView` (`configFile`), `ServiceSettings` (`configFile`), `ServiceVariablesView` (`managedByFile`, config values read-only), `DeploymentDetailView` (`configErrors`).
- HTTP: `ConfigFileController` (settings, Check now, download), `Api\V1\ConfigController` (export, validate, diff, status), `ConfigSchemaController` (`/schema/launchroom.v1.json`, public).
- UI (`resources/js/features/config-as-code`): `ManagedByFile` (callout + read-only page, wraps every service settings page), `ConfigProblem` (excerpt with the line marked and a caret), `ConfigFailureCard` (deployment page), `ConfigFileSettings` (project Settings → General), `StagedConfigNote` (review sheet); the Variables tab's file icon and the staged items' `launchroom.json` chip.
- CLI: `cli/internal/commands/config.go`, `cli/internal/configfile` (local syntax check, excerpts).
- Tests: `tests/Unit/Support/ConfigAsCode` (parser positions and messages, schema/rule messages, schema ↔ Data drift), `tests/Feature/ConfigAsCode` (round trip, push flows, managed/unmanaged, conflicts, overrides, identity, trust, dry run, previews and forks through a real `DeployPreview`, UI data), `tests/Feature/Api/ConfigApiTest.php`, `cli/internal/commands/config_test.go`.
