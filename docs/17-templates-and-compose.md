# 17: Templates & Compose import

Decision **D44**. Two ways to start a project without describing every service by hand: **import a Compose file** you already have, or **start from a template** (a curated multi-service blueprint with generated secrets). Both end in ordinary launchroom services on the canvas: there is no "compose stack" object, no `compose` service source and nothing that keeps the file around. After the import the project is indistinguishable from one built by hand, so every other feature (environments, previews, backups, promote) applies to it.

Product rules (maintainer taste): apps, not compose projects; a guided visual flow that previews the canvas before anything is created; calm notes instead of walls of warnings; the first deploy is direct, like importing a repository.

## 1. The common target: `ProjectBlueprint`

New-project flows offer **Create without deploying** alongside immediate deployment. The input's `deploy` defaults to true; false creates intent and redirects to the canvas so variables can be configured before the first Deploy.

Every source produces the same pure Data object, `App\Data\Blueprints\ProjectBlueprint`, and one Action applies it (`App\Actions\Blueprints\ApplyBlueprint`). The Compose translator and the template compiler both target it; nothing else creates services in bulk. `launchroom.json` ([18](18-config-as-code.md)) is applied to the intent tables through its own path.

```
ProjectBlueprint { version: 1, name, services: BlueprintService[], sharedVariables: BlueprintVariable[] }
BlueprintService {
  key            slug grammar ([a-z0-9-], ≤ 63); unique; references use it
  name, kind     app | image | database | worker   (no cron)
  logo?          a glyph key (monochrome, design-system §3.5)
  image?         image services: a reference (tag or digest; tags are pinned at deploy, D24)
  build?         app services: {context (root directory), dockerfile?}  (needs a repository)
  database?      {engine, version}  (managed by the engine driver: credentials, volume, backups)
  parent?        workers: key of their app (same image, different command)
  port?, public  public = gets a generated domain; private services keep only their slug
  domain?        a custom domain to add (templates' "domain" input)
  entrypoint?, startCommand?   argv only (never a shell string)
  healthcheck?   HealthcheckSettings (http path / tcp / none)
  scaling?       {replicas, cpus, memoryMb}
  capabilities   from launchroomd's allow-list only (docs/03 §8)
  volumes        mount paths (named volumes owned by this service)
  variables      {key, value, secret} in launchroom's own syntax: ${{ secret(32) }}, ${{db.DATABASE_URL}}
  connections    {to: key, style: url | laravel | parts}  (canvas edges; inject variables, docs/06 §3.2)
  notes          what the source said and what became of it (§3.4); shown, never applied
  files          config files {path, content}: written read-only into the container before it starts (D65)
  provides       {key, value} a connection to this service injects, referencing its own variables (D65)
}
```

- **Validation** (`App\Support\Blueprints\BlueprintValidator`, pure): config files pass `ConfigFileRules` (launchroomd's rules, docs/03 §8, and never inside one of the service's volumes), a `jwt()` value is signed with a secret variable of the same service, provided variables only reference the service's own variables; key grammar and uniqueness, kinds vs. sources (image xor build xor database), parents are apps of the same blueprint, connections point at existing keys (databases and web services only), references `${{key.VAR}}` point at blueprint keys, commands are argv (≤ 256 items of ≤ 4 KiB), mount paths pass the volume rules (`AddServiceVolume`: clean, outside `/proc`, `/sys`, `/dev`, never the Docker socket), capabilities ⊆ the allow-list, databases have one replica. A blueprint that fails validation is never applied (the translator only emits valid blueprints; the test suite proves it with fuzzing, §5).
- **Applying** (`ApplyBlueprint`, one transaction): services in dependency order (databases, then apps/images, then workers) through `AddService`/`CreateService` (so slugs, generated domains, database credentials and plan previews behave exactly as when added by hand), then settings (`UpdateServiceSettings`), volumes (`AddServiceVolume`), connections (`CreateConnection`), variables (`CreateVariable`, generators evaluated once per environment, generated values typed `secret`), custom domains (`AddDomain`), private image services lose their generated domain. When a key is taken in an existing environment (catalog on the canvas) the service gets the next free slug and every `${{key.…}}` reference is rewritten. Positions are laid out as Public → App → Data columns.
- **Where it lands**: a new project defaults to deploying its production environment right away (`DeployEnvironment`, trigger config, "Imported from compose.yml" / "Created from the Umami template"), like a repository import; **Create without deploying** skips that first deployment. Adding from the catalog on a canvas is **staged** like every other addition (docs/06 §4): it shows as "3 new services · Deploy".

## 2. Compose import

### 2.1 Flow (`/projects/new`, "Import a Compose file")

`/projects/new` has three ways to start, as a segmented row above the page: **Repository**, **Template**, **Compose file**.

1. **Source**: paste or upload a file (`compose.yml`, `docker-compose.yml`, ≤ 256 KiB), or pick a repository and branch, from which we read `compose.yaml`, `compose.yml`, `docker-compose.yaml` or `docker-compose.yml` at the root (GitHub contents API, read-only token, like the repository inspector). Repository imports can build services (`build:` → app services rooted at the context); pasted files can't (those services are listed as needing a repository).
2. **Preview** (`POST /projects/-/compose/preview`, pure, nothing stored): a picture of the resulting canvas (Public · App · Data columns with logos and edges), then one card per service: what it becomes ("Managed PostgreSQL 17", "Image · ghcr.io/…", "Worker of web"), and its notes, each with the line number (`line 14`) and one sentence. Choices sit on the cards: databases as option cards **Managed** (recommended: credentials, backups, clean stops) / **Keep as a container**; bind mounts with a **Convert to a volume** switch. Values the file interpolates (`${POSTGRES_PASSWORD}`) without a default appear as a short "Values" list: secret-looking names are generated for you (shown as "Generated"), the rest are optional fields. Files the services reference (`env_file: .env`) can be pasted there too (repository imports read them from the branch when present).
3. **Name and deploy** (`POST /projects/compose`): the server parses and translates the file again (the preview is never trusted), applies the blueprint and deploys; lands on the canvas.

### 2.2 The allow-list parser (`App\Support\Compose`)

Pure PHP, no framework, no I/O. Never passes anything to `docker compose` (docs/09 R6).

- **YAML** (`ComposeYaml`): symfony/yaml without object, constant or custom-tag support; input ≤ 256 KiB; ≤ 64 aliases (`*name`) per file and ≤ 20 000 nodes after alias expansion (billion-laughs guard), depth ≤ 32; duplicate keys are an error; the top level must be a mapping with `services`. A line index maps every block-style key path to its line (`services.web.privileged` → 14) so notes can point at lines; flow-style values get their parent's line.
- **Interpolation** (`ComposeInterpolation`): the Compose rules (`$VAR`, `${VAR}`, `${VAR:-default}`, `${VAR-default}`, `${VAR:?message}`, `${VAR?message}`, `${VAR:+alt}`, `${VAR+alt}`, nested defaults, `$$` → `$`). Values come from the pasted `.env` (parsed with `EnvFileParser`), else the default. In `environment` values a variable becomes a **shared variable** of the project (`${{shared.NAME}}`) so every service that uses it sees the same value; unresolved secret-looking names (`*_PASSWORD`, `*_SECRET`, `*_KEY`, `*_TOKEN`, …) are generated (`${{ secret(32) }}`), others are created empty with a note. Elsewhere (image, ports, commands, volumes) interpolation substitutes the known value; an unknown or generated value there is refused for that field.
- **Commands** (`CommandLine`): lists are argv as they are. Strings are split like a POSIX shell's word splitting with quotes and backslashes **only**: no expansion, globbing, substitution or operators. A string containing shell syntax (`&&`, `||`, `|`, `;`, `>`, `<`, `` ` ``, `$(`, `$VAR` after `$$` unescaping) is refused with "Write it as a list" (a list like `["sh", "-c", "…"]` is the user's own process inside their container and is accepted as argv).
- **Durations / sizes**: Compose durations (`1m30s`, `500ms`) → whole seconds; byte sizes (`512m`, `1.5g`, `2GiB`) → MB.

### 2.3 Translation table

Top level: `services` (required), `volumes` (named volumes; `external`, `driver`, `driver_opts` refused), `configs` (inline `content` only, see the table; `file`, `environment` and `external` configs refused), `name` (project name suggestion), `x-*` (anchors; ignored silently), `version` (ignored, obsolete), `networks` (ignored: an environment is one private network where services reach each other by name), `secrets`, `include` (refused). Services with `profiles` are skipped (listed, "only started with a profile").

| Compose | launchroom | Notes |
|---|---|---|
| `image` | image service (digest-pinned through the registry mirror at deploy, D24) | Docker Hub and GitHub Container Registry only (the mirror's upstreams); others refused |
| `build` (string or `{context, dockerfile}`) | app service from the repository, root = context, builder = Dockerfile | `..`, URLs, `dockerfile_inline`, `args`, `target` refused/ignored with a note; pasted files: needs a repository |
| same build + different `command` | worker of the first such service (one build feeds both, docs/04 §1) | the service with ports is the parent |
| well-known DB images `postgres`, `mysql`, `mariadb`, `redis`, `valkey`, `mongo` (also `mongodb/mongodb-community-server`), `clickhouse/clickhouse-server` (also `yandex/…`) (also `bitnami/*`, `*-alpine` tags) | **managed database** (version from the tag, else the nearest supported; D47 added MongoDB and ClickHouse: `MONGO_INITDB_*` / `CLICKHOUSE_USER/PASSWORD` become references, `CLICKHOUSE_DB` maps to `CLICKHOUSE_DATABASE` (the managed engine uses `default`), `mongodb://` URLs gain `authSource=admin`, `clickhouse://`/`tcp://` URLs use `CLICKHOUSE_NATIVE_PORT`) | option: keep as a plain container (image service, private, volume, TCP health). MariaDB defaults to a container (managed MySQL is offered) |
| `ports` (short and long syntax) | the container port → service port, **public** with a generated domain | host ports are never published (traffic enters through the edge); only one port per service is routed, others are noted; UDP and port ranges refused; published non-HTTP ports refused |
| `expose` | service port, private | non-HTTP ports (5432, 6379, …) too: the port gives a TCP health check and cross-server reachability, never a domain |
| `configs` (short `- name` or long `{source, target}`) | **config files** (D65): the top-level config's inline `content` (interpolated like Compose does), written read-only (0444, root) at `target` (default `/<name>`) before the service starts | `uid`, `gid`, `mode` ignored; a target inside one of the service's volumes, under `/proc`, `/sys`, `/dev` or Docker's `/etc/hosts`-like files refused; at most 32 files, 64 KiB each, 256 KiB per service; secret-shaped content refused (keep secrets in variables, the program reads them from its environment) |
| `environment` (list or map), `env_file` | variables of the service | secret-looking keys or values → `secret` (`EnvFileParser::suggestType`); values pointing at another service's host are rewritten to its slug (`db_1:5432` → `db-1:5432`) |
| `depends_on` (list or map) | connections (canvas edges) to databases and web services | conditions ignored: databases deploy first |
| credentials of a converted database | references | `POSTGRES_PASSWORD`/`MYSQL_*`/`--requirepass` values the consumers repeat become `${{db.PGPASSWORD}}` etc.; URLs to the database (`postgres://u:p@db:5432/x?…`) become a URL built from the managed database's parts (scheme and query kept) |
| named volume `name:/path`, anonymous `/path`, long `type: volume` | launchroom volume on that service | one owner per volume (a volume shared between services is refused for the second); `:ro` noted |
| bind mount `./data:/path`, `/host:/path`, long `type: bind` | **refused** | folders of the project (`./data`): switch "Convert to volumes" (empty volume, default on); folders of the server (`/srv/media`, `/`) and files (`./nginx.conf`, `/etc/localtime`) are refused (add a volume afterwards, or build the file into the image); the Docker socket is refused without an offer |
| `healthcheck.test` | HTTP probe when the test is `curl`/`wget` against `localhost` (path and port taken), else a TCP probe on the port; `disable`/`NONE` → none | launchroom probes from outside, never runs the test (argv only, no `CMD-SHELL` shell); `interval`, `timeout`, `retries`, `start_period` mapped |
| `command` | start command (argv; image CMD) | §2.2 tokenizer |
| `entrypoint` | entrypoint override (argv) | |
| `deploy.replicas`, `scale` | replicas (≤ 64; databases 1) | |
| `deploy.resources.limits.{cpus,memory}`, `cpus`, `mem_limit` | CPU and memory | reservations, placement, restart/update policies ignored |
| `cap_add` | capabilities within launchroomd's allow-list (`CHOWN`, `SETUID`, `SETGID`, `DAC_OVERRIDE`, `FOWNER`, `NET_BIND_SERVICE`) | anything else refused; `cap_drop` ignored (launchroom drops all) |
| `container_name`, `hostname`, `networks.*.aliases`, `links` | (none) | ignored; still recognised as host names for the rewrite above |
| `labels` | (none) | ignored; Traefik-style labels that route a service make it public, and their Host rules are listed as domains to add. Reverse proxies in the file (traefik, caddy, nginx-proxy, haproxy) get a note that the edge already does their job |
| `restart`, `logging`, `stop_signal`, `stop_grace_period`, `tty`, `stdin_open`, `init`, `pull_policy`, `platform`, `working_dir`, `user`, `read_only`, `shm_size`, `ulimits`, `tmpfs`, `domainname`, `dns*`, `cpu_*`, `mem_reservation`, `memswap_limit`, `blkio_config`, `oom_score_adj`, `develop`, `annotations` | (none) | ignored with a note (launchroom always restarts, routes with its own domains, rotates logs, runs the image's user and working directory) |
| `privileged`, `network_mode: host`, `network_mode: service:/container:`, `pid`, `ipc`, `uts`, `userns_mode`, `cgroup`, `cgroup_parent`, `devices`, `device_cgroup_rules`, `gpus`, `sysctls`, `security_opt`, `extra_hosts`, `external_links`, `volumes_from`, `isolation`, `runtime`, `storage_opt`, `group_add`, `mac_address`, `oom_kill_disable`, `secrets`, `extends`, `post_start`, `pre_stop` | (none) | **refused** with a reason (host access, or not something launchroom runs); the service is still imported without it |

Anything not in the table is ignored with "launchroom doesn't use `<key>`". A refusal never makes launchroom run something less safe: refused fields are simply not applied, and launchroomd's policy floor (docs/03 §8) would refuse them anyway.

### 2.4 Notes

`BlueprintNote {kind, field, line, message}` with kind `translated` (✓, quiet), `ignored` (–, muted), `refused` (✕, the only red), `warning` (⚠, amber: imported but check it, e.g. an empty variable). Messages are one sentence in the product voice: "Bind mount ./data → becomes a volume at /var/lib/app." / "privileged is refused: services never get host access." The preview groups them per service, refusals first.

## 3. Templates

### 3.1 Format

One YAML file per template in `dashboard/resources/templates/<id>.yaml`, schema `launchroom.template/v1`:

```yaml
schema: launchroom.template/v1
id: umami                       # [a-z0-9-], the file name
name: Umami
tagline: Simple, privacy-friendly website analytics.
description: …                  # two sentences for the detail sheet
category: analytics             # stacks | analytics | automation | content | developer | storage | search | monitoring | data
logo: umami                     # glyph key
website: https://umami.is
license: MIT                    # the app's licence (shown)
notes:                          # optional, ≤ 6 lines on the detail sheet: requirements and limits
  - Plan for 1 GB of memory.
inputs:                         # only what matters; everything else is generated
  - {id: domain, label: Domain, type: domain, optional: true}
services:                       # the Compose subset of §2.3, translated by the same parser
  umami:
    image: ghcr.io/umami-software/umami:3.4.0@sha256:…   # digest-pinned: required
    ports: ["3000"]
    environment:
      DATABASE_URL: ${service.db.url}
      APP_SECRET: ${generate:base64:32}
    depends_on: [db]
    healthcheck: {test: ["CMD", "curl", "-f", "http://localhost:3000/api/heartbeat"]}
    x-launchroom: {name: Umami, logo: umami, domain: "${input.domain}"}
  db:
    x-launchroom: {database: {engine: postgres, version: "17"}}
configs:                        # optional: Compose configs with inline content become config files (literal, no expressions)
  init:
    content: |
      CREATE EXTENSION IF NOT EXISTS pg_trgm;
```

- `services` is a Compose `services` map; the template compiler (`App\Support\Templates\TemplateCompiler`) turns it into a blueprint **through the Compose translator** in strict mode, so a template is valid only if the allow-list parser accepts every line (no refusals, no ignored keys).
- `x-launchroom` per service: `name`, `logo`, `database {engine, version}` (a managed database; no image), `source: repository` (an app built from the repository input; `build.context` is its root directory), `parent` (worker of another service), `public` (override), `domain`, `connect {to: style}` (connection style per target; default `laravel` when the service's variables look like Laravel's `DB_*`, else `url`), `backup: pause` (volume backups freeze the service while reading its volumes, for data written in place such as a database kept as an image; kept on environment copies), `provides {KEY: value}` (what a connection to this service injects instead of `<SLUG>_URL`, values from `${self.public_url}` and `${self.env.VAR}`, ≤ 16; D65, docs/06 §3.2). Connection-injected variables that equal a template variable are stored once.
- Expressions (resolved before Compose interpolation, so they never collide with `${VAR}`):
  - `${generate:password:N}` → `${{ secret(N) }}` (alphanumeric), `${generate:hex:N}`, `${generate:base64:N}`, `${generate:base64url:N}` (N random bytes), `${generate:uuid}` → `${{ uuid() }}`, `${generate:jwt:<role>:<KEY>}` → `${{ jwt("<role>", KEY) }}` (an HS256 JWT signed with the variable KEY of the same service, D65). Generated per environment when the variable is created (docs/06 §3.1), `secret`, never shown in the preview, never logged or audited. Exception: a JWT for the role `anon` is config, because it is public by design (Supabase's anon key goes into browser bundles) and has to feed `NEXT_PUBLIC_*` variables, which can't carry secrets (docs/06 §3.3).
  - `${service.<key>.url}` (databases: `DATABASE_URL`/`REDIS_URL`/`MONGO_URL`/`CLICKHOUSE_URL`; web: `PRIVATE_URL`), `.public_url`, `.host`, `.port`, `.user`, `.password`, `.database`, `.env.<VAR>` → the matching `${{<key>.…}}` reference; `${self.public_url}`, `${self.domain}`, `${self.env.VAR}` → `${{PUBLIC_URL}}`, `${{DOMAIN}}`, `${{VAR}}`.
  - `${input.<id>}` → the guided input's value (validated by its type: `text`, `email`, `domain`; `repository` is the picker for stacks). Text inputs start with their placeholder (Celery's `config`).
- **Resources** shown on the card are summed from the services' scaling (database defaults 1 CPU / 512 MB) plus volume count.

### 3.2 Catalog

Backends: **Supabase** (§3.5). Stacks (repository + databases, built from your code): **Next.js + Postgres**, **Laravel + MySQL + Redis** (web, queue worker, scheduler), **Django + Postgres + Celery** (web, Celery worker, Redis broker), **Rails + Postgres + Sidekiq** (web, Sidekiq, Redis). One-click apps: **Plausible** (+ managed ClickHouse + Postgres; D47, `CLICKHOUSE_DATABASE_URL: ${service.events.url}`), **Umami**, **Uptime Kuma**, **n8n**, **Ghost** (+ MySQL), **Gitea** (rootless), **versitygw** (S3-compatible storage), **Meilisearch**, **PocketBase**, **Metabase**. Forgejo is left out while its images live only on Codeberg's registry, which the mirror doesn't serve.

Logos are simple-icons glyphs (CC0 data; the marks are their owners' trademarks, used nominatively to identify the app), monochrome on the `Logo` tile. Apps without a simple-icon get a lucide glyph (versitygw: `HardDrive`). Parts of a stack without a mark of their own use the generic glyphs `auth`, `api`, `realtime`, `storage` (Supabase).

### 3.3 Validation in CI

`make lint-templates` (`php artisan launchroom:templates:lint`) and a Pest test over the same code: schema and field grammar, id = file name, known category and logo, every `image:` digest-pinned (`@sha256:` + 64 hex) and from a mirrored registry, inputs used and declared, expressions well-formed and pointing at existing services, the Compose translation without a refusal or ignored key, and the resulting blueprint valid. Digests are bumped deliberately (like the database engine pins).

### 3.5 Supabase (D65)

`resources/templates/supabase.yaml` follows Supabase's official `docker/docker-compose.yml` with its Kong gateway (`docker-compose.kong.yml`) at self-hosted release 0.8.3 (supabase/supabase@bc27d26, 2026-10-16), every image digest-pinned and multi-arch (amd64, arm64).

| Service (slug) | Image | Role |
|---|---|---|
| Supabase (`supabase`) | `kong/kong:3.9.3` | the only public service: API gateway, routes from the official `volumes/api/kong.yml` (legacy API keys) passed as `KONG_DECLARATIVE_CONFIG_STRING` with references to the keys; Studio is its basic-auth catch-all route |
| Studio | `supabase/studio` | private, reached only through the gateway's basic auth (`DASHBOARD_USERNAME`/`DASHBOARD_PASSWORD`) |
| Auth | `supabase/gotrue` | private; sign-ups confirmed right away until SMTP is configured |
| REST API | `postgrest/postgrest` | private |
| Realtime | `supabase/realtime` | private; `SELF_HOST_TENANT_NAME` is its own slug, the host name the gateway calls it by |
| Storage | `supabase/storage-api` | private, file backend on its own volume |
| Postgres Meta | `supabase/postgres-meta` | private |
| Postgres | `supabase/postgres` | an **image service** with volumes (`/var/lib/postgresql/data`, `/etc/postgresql-custom`) and the official init SQL (`roles.sql`, `jwt.sql`, `realtime.sql`, `webhooks.sql`) as config files; not a managed database (Supabase's own image, roles and extensions) |

- **Keys:** `JWT_SECRET` (48 characters), `ANON_KEY` and `SERVICE_ROLE_KEY` are variables of the gateway; the keys are `jwt()` generators signed with `JWT_SECRET` (docs/06 §3.1), so every environment has its own secret and keys. The other services reference them (`${{supabase.JWT_SECRET}}`).
- **Connecting an app:** the gateway provides `NEXT_PUBLIC_SUPABASE_URL` (`${{PUBLIC_URL}}`), `NEXT_PUBLIC_SUPABASE_ANON_KEY` and `SUPABASE_SERVICE_ROLE_KEY` (docs/06 §3.2).
- **Left out:** analytics (Logflare) and Vector, because Vector reads the Docker socket, which launchroom never gives a service; Studio's logs views are off (`ENABLED_FEATURES_LOGS_ALL=false`). imgproxy, because it reads originals from storage's volume and a volume belongs to one service; image transformations are off. Edge Functions (code is deployed into a volume, no launchroom path for that yet) and Supavisor (apps on the environment's network reach Postgres directly).
- **Backups:** the database is backed up as volumes (docs/14 §9.12); point-in-time recovery needs a managed Postgres and isn't available. Template notes say both.
- **Resources:** limits add up to about 3.3 GB of memory; the template's notes say 3 to 4 GB.

## 4. UI

- **Template gallery** (`/projects/new/templates`, `Page size="wide"`): search, category pills, a 3/2/1 grid of app cards (44 px logo tile, name, one-line tagline, footer "2 services · 1 GB"). Opening one shows a `SidePanel` (lg): logo, name, tagline; **What you get** (the blueprint picture: Public · App · Data columns of logo tiles joined by quiet curves, one line per service); **Resources** (CPU, memory, volumes as a facts strip); **Set it up** (project name prefilled, the template's few inputs, repository picker for stacks); footer **Deploy**.
- **Add from catalog** on the canvas: a "From the catalog" tile in the add sheet opens the same grid inside the sheet; picking one shows its detail with the inputs and **Add to canvas** (staged).
- **Compose import** (`/projects/new/compose`): §2.1. The service cards follow "objects are cards": logo, name, what it becomes, then the notes as icon rows with the line number in `mono-small`.

## 5. Tests

- Parser corpus: `tests/Fixtures/compose/*.yml` (our own realistic files: WordPress + MariaDB, a Node API with Postgres and Redis, Django + Celery, a Traefik-labelled stack, a media server with devices and host networking, a monitoring stack with bind mounts and the Docker socket, anchors and extension fields, interpolation with `.env`) with expected blueprints/notes.
- Translation units per row of §2.3, refusal reasons with line numbers, the tokenizer and interpolation edge cases.
- Fuzz/property tests: random mutations of the corpus and random YAML trees never crash (only `ComposeRejected`), every translation passes `BlueprintValidator`, and refused fields never reach a blueprint (no host paths, no extra capabilities, no `privileged`).
- Feature tests: preview and import endpoints (authorization, team scoping, throttles), template creation and catalog additions, generated secrets never in responses, logs or audit entries.
- `make lint-templates` over the shipped catalog.

## 6. Decisions

- **D65** (2026-10-25): Supabase as a template. Config files on services (`DeploySettings.files`, Compose `configs` with inline content, `WorkloadSpec.files`), `jwt()` generators signed with another variable, and provided connection variables. See the decision log.
- **D44** (2026-10-19): Compose files and templates both translate into one `ProjectBlueprint` applied by one Action; there is no compose source or stack object. The parser is an allow-list (§2.3): everything else is refused or ignored with a line-numbered note, never passed through. Templates are versioned YAML files in-repo whose services are a strict Compose subset validated by the same parser; generated secrets are launchroom generator expressions evaluated per environment. `DeploySettings` has `entrypoint` and `capabilities` (allow-list only) so translated services keep their entrypoint and the few capabilities common images need. Rejected: passing a validated file to `docker compose` on the server (a second orchestrator and a shell-adjacent surface), storing templates in the database (no review, no CI validation), a compose service source (would split every feature into two paths).
