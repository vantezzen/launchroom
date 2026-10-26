# 16: Public API & CLI

A documented REST API for scripts and CI, and the `launchroom` command line tool on top of it. Decision **D36** in the [decision log](README.md). Security rules: [09](09-security.md) R7, R11, R12, R16.

The API is a second entry point to the same use cases: every endpoint calls the Actions, Queries and Policies the dashboard uses. Nothing is reachable through the API that a member couldn't do in the dashboard, and a token can never do more than its owner.

## 1. Tokens

### 1.1 What a token is

- A **personal access token** (Sanctum, `personal_access_tokens`), owned by a user and **bound to one team** (`team_id`, required). It acts as that user inside that team only: resources of the user's other teams resolve as 404, exactly like another team's resources.
- **Abilities** narrow it further. The effective permission for any check is *the user's role in the team* ∩ *the token's abilities*. A developer's token with every ability still can't reveal secrets (developers can't), and an owner's `read` token can't deploy.
- **Mandatory expiry**: 7, 30, 90 or 365 days (`expires_at` is never null; a token without one is refused, fail closed). Sanctum's global `expiration` is off; `api.max_token_days` (365, `config/api.php`) caps creation.
- **Hashed**: only the SHA-256 of the secret is stored (Sanctum). The plaintext `<id>|lr_pat_<40 random><crc32>` is shown **once**: in the create dialog's response body (a JSON fetch, never flashed into the session) or handed once to the CLI (§4).
- **Last used** time and **IP** (`last_used_at`, `last_used_ip`) are recorded on every authenticated request and shown in Settings → API tokens.
- **Audited**: `api_token.created` (name, team, abilities, expiry, client), `api_token.revoked`, `api_token.cli_login_approved` / `…_denied`. Actions taken with a token are audited like the same action in the dashboard, with the token's public id in the entry's meta (`via_token`) and the request's IP and user agent.
- A token dies with its owner's membership: removing a member (or deleting the account) deletes their tokens of that team. Even before that, a former member's token resolves nothing (the binding scope is the membership).

### 1.2 Abilities

| Ability | Grants (∩ the role) | Typical use |
|---|---|---|
| `read` | `resources.view`: projects, environments, services, deployments, logs, servers, domains | Status checks, dashboards |
| `deploy` | `deployments.create`: deploy a ref, apply staged changes, redeploy, roll back, cancel; deploy, restart, stop and start a service (D68) | CI deploys |
| `env:read` | Variable **names and config values**, `env pull` without secrets | Local development |
| `env:write` | `variables.write`: create, update and delete variables (staged until deploy) | `env set` |
| `secrets:read` | `secrets.reveal`: secret values (one variable, or `env pull`/`run` with secrets), each read audited | `env pull`, `run` |
| `domains:write` | `projects.manage` on the domain endpoints only: add and remove custom domains | Automation |
| `data:transfer` | `backups.manage` on the transfer, import, export and download endpoints only: upload files, import them, export and download data ([14](14-backup-and-restore.md) §12.7) | `launchroom db import`, `db export`, `volume export` |

`App\Enums\ApiAbility` is the single list. Each ability maps to the `Permission` it may exercise (`ApiAbility::grants()`, checked by `ApiAbility::allows()`); permissions no ability maps to (servers, members, team, exec, exposure, backups) are **never** available to a token. Enforcement is two layers:

1. Each API route declares its ability (`RequireTokenAbility:<ability>` middleware; `403 missing_ability` before the controller runs).
2. `User::hasTeamPermission()` intersects the role's permissions with the current token's abilities, and `User::teamIdsQuery()` narrows to the token's team. Every existing policy and team-scoped binding therefore respects the token without knowing about it (authorization by construction, R7).

Secrets are never part of a list response. `GET …/variables` returns secret rows with `value: null`; their values come from the single-value endpoint or `…/env` with `secrets=1`, both of which need `secrets:read` **and** the reveal permission, and both are audited. `secrets:read` is opt-in and shown as "Read secret values" on the token. `data:transfer` follows the same rule for imports and downloads (decrypted data leaves the platform): opt-in, never a default, and owner/admin only through `backups.manage`.

### 1.3 Settings → API tokens

`/settings/api-tokens` ("You" group of the settings nav: tokens are personal, each bound to one team). One `SettingsCard` per topic (design-system §13.9):

- **Use the CLI** card: one sentence, the install line (`curl … | sh`, §5.3) and `launchroom login`, both with copy; footer link to the API reference.
- **Your tokens**: one row per token (name, CLI badge, team, "Last used 3 min ago from 203.0.113.7" / "Never used", "Expires in 28 days" with an amber ⚠ under 7 days, ability chips), an outline-red Revoke behind a `ConfirmPopover`. Empty state: one line; "Create token" in the card header.
- **Create token** dialog: name, expiry as `ChoiceTiles` (7 days · 30 days · 90 days · 1 year), abilities as a checklist with one sentence each (`read` always on; the defaults `deploy`, `env:read`, `env:write` checked; abilities the role can't use disabled with "not with your role"), team = the current team (named in the dialog). After creating, the dialog turns into the **show once** view: the token in mono with copy, "You won't see it again", and an example `curl`. `POST /settings/api-tokens` answers the plaintext in its JSON body (`Cache-Control: no-store`), never through the session.

## 2. REST API v1

### 2.1 Conventions

- Base `/api/v1`, JSON only, `Authorization: Bearer <token>`. No cookies, no sessions, no CSRF (`routes/api.php`, outside the web group). Sanctum's session guard is off (`sanctum.guard = []`).
- **IDs** are public ids (`prj_…`, `env_…`, `svc_…`, `dpl_…`, `var_…`, `dom_…`, `srv_…`), constrained by grammar in the route and resolved through the team-scoped bindings. Nested ids are checked to belong together (the environment's project, the service's instance in that environment), else 404.
- **Responses**: one object `{"data": {...}}`; lists `{"data": [...], "meta": {"nextCursor": "…"|null}}` (opaque cursor, `?cursor=`, `?limit=` ≤ 100). Times are ISO 8601 UTC.
- **Errors** (every 4xx/5xx under `/api/v1`): `{"error": {"code": "…", "message": "…", "fields"?: {"key": ["…"]}, "ability"?: "deploy"}}`. Codes: `unauthenticated` 401, `forbidden` 403, `missing_ability` 403, `not_found` 404, `method_not_allowed` 405, `conflict` 409, `idempotency_key_in_progress` 409, `idempotency_key_reused` 422, `validation_failed` 422, `rule_violation` (a `DomainRuleViolation`, its own status), `rate_limited` 429 (with `Retry-After`), `server_error` 500 (never internals). `App\Http\Api\ApiExceptionRenderer` owns the mapping.
- **Rate limits** per token: 300 requests/min (`api`), mutations additionally 60/min (`api-write`); the CLI login endpoints per IP (`api-cli-start` 10/min, `api-cli-poll` 60/min). `X-RateLimit-*` headers.
- **Idempotency**: `POST` creates (deployments, deploy, variables, domains, rollback, redeploy, a service's deploy/restart/stop/start) accept `Idempotency-Key` (1–255 of `[A-Za-z0-9_.:-]`). The first request stores (token, key, SHA-256 of method + path + body) and, when it finishes, the status and body (24 h, `api_idempotency_keys`). A replay returns the stored response with `Idempotent-Replayed: true`; the same key with a different request is `422 idempotency_key_reused`; a replay while the first is still running is `409 idempotency_key_in_progress`. 5xx responses are not stored (retry is safe). Bodies of create responses never contain secrets.
- **Versioning**: additive changes stay in v1 (new fields, endpoints); a breaking change is `/api/v2`.

### 2.2 Endpoints

The endpoint reference is the [API reference](https://launchroom.vantezzen.io/docs/reference/api) on the site and the generated OpenAPI document `dashboard/resources/api/openapi.json` (§2.4).

Service lifecycle (D68): `POST /environments/{environment}/services/{service}/deploy|restart|stop|start` (`Api\V1\ServiceActionController`, ability `deploy`, `api-write`, idempotent) call the service panel header's Actions (`DeployLatest`, `RestartService`, `StopService`, `StartService`) after `Gate::authorize('deploy', $environment)`, audited like the buttons with `via_token`. Restart, stop and start take `{processes?: bool}` (default true: an app's workers and crons follow) and answer `ServiceActionResultData {service, deployments}` (`202`; Stop `200` with no deployments); deploy answers the `DeploymentResource` (`202`) like `createDeployment`. Domain refusals (stopped, staged changes, a deployment in progress) are `rule_violation`. `ServiceResource.status` is `stopped` while the service is stopped, with `stoppedAt`.

Security center (D63): read-only endpoints under `/api/v1/security/…` and `…/services/{service}/inventory` (ability `read`) and the CLI commands `launchroom security` and `launchroom security inventory`, listed in [22](22-security-center.md) §11.

### 2.3 Server-sent events

`GET …/logs/stream` answers `text/event-stream` (`Cache-Control: no-store`, `X-Accel-Buffering: no`). Events:

- `lines`: `id: <cursor>`, `data: {"lines": [...], "cursor": N}` (deployment) or `{"entries": [...], "cursor": N}` (environment);
- `status`: `{"status": "building", "finished": false}` when a deployment's status changes;
- `end`: the deployment finished (the stream closes);
- a `: ping` comment every 15 s.

The server polls the stored chunks once a second and closes the stream after 5 minutes (`event: reconnect`); clients reconnect with `Last-Event-ID` (or `?after=`) and lose nothing. Keeping one PHP worker per open stream is the price of not adding a second realtime path (Reverb needs a session); the 5-minute bound and the `api` rate limit keep it small.

### 2.4 OpenAPI

The document is **generated from the routes and Data objects** by our own small generator (`App\Http\Api\OpenApi\OpenApiGenerator` + `SchemaBuilder`, `php artisan launchroom:api:openapi [--check]`), committed as `dashboard/resources/api/openapi.json` (OpenAPI 3.1, JSON Schema 2020-12) and checked for drift by a Pest test (`make gen-openapi` regenerates it; `make gen` includes it).

- Every `/api/v1` route's controller method carries `#[ApiOperation]` (operation id, summary, tag, ability, request Data, response Data, list or single, status). A route without it fails the test.
- Schemas come from the Data classes by reflection: PHP types, `?` nullability, backed enums, `list<X>`/`array<string, string>` docblocks, and the laravel-data validation attributes (`Regex`, `Max`, `Min`, `In`) as `pattern`, `maxLength`/`maximum`, `minLength`/`minimum`, `enum`. `Optional` properties are not `required`.
- Path parameters get the public-id pattern of their route constraint; every operation documents its ability (`x-launchroom-ability`), the error schema and, for creates, `Idempotency-Key`.

Why not dedoc/scramble: it infers responses by static analysis of controller code, which in our thin controllers is mostly `ApiResponse::item(...)` around Data objects; its laravel-data support is a paid add-on, and inference changes with refactors. An explicit attribute per operation plus reflection over the Data we already write is ~400 lines, deterministic, reviewable in diffs, and adds no runtime dependency. The same Data classes generate the TypeScript types the docs page uses.

The dashboard serves the document at `GET /api/v1/openapi.json` (public: no secrets, no instance data) and renders it at **`/docs/api`** (signed in): operations grouped by tag, method + path, ability chip, parameters, request and response schemas, the error format and a copyable `curl`. Plain React from the generated JSON, monochrome, no third-party renderer (CSP, design system).

## 3. CLI (`launchroom`)

### 3.1 Where it lives

A separate Go module **`cli/`** (`github.com/vantezzen/launchroom/cli`, binary `launchroom`), not a command inside `daemon/`: launchroomd's module pulls in Docker, BuildKit, nftables and sigstore, which a laptop tool must not ship (binary size, vulnerability surface, cross-compiling to darwin/windows). The CLI depends on the standard library, `cobra`, `golang.org/x/term` and `zalando/go-keyring`. It talks to the API only (generated-JSON contract, §2.4); it never talks to launchroomd.

### 3.2 Commands

The command reference (flags, output, exit codes, CI variables) is the [CLI reference](https://launchroom.vantezzen.io/docs/reference/cli) on the site. `launchroom service deploy|restart|stop|start [SERVICE]` (D68) acts on one service: the argument (id, slug or name), else `--service`, else the link. Deploy, restart and start follow the rollout like `deploy` (exit 10 on failure, `--detach`); `stop` asks on a terminal and needs `--yes` without one (exit 2); `--without-processes` leaves an app's workers and crons alone. `db import`, `db export` and `volume export` (exit code 11 when the import or export fails) are specified in [14](14-backup-and-restore.md) §12.7.

### 3.3 Decisions

- **`deploy` deploys a git ref, it doesn't upload source.** The build pipeline ([04](04-build-and-deploy.md)) clones from the repository at a commit, which makes builds reproducible, cached by build key and reportable to GitHub.
- **`env pull`/`run` resolve server-side** with the same resolver as deploys, from the last deployed revision, so the file matches what runs. References to other services resolve to their private names (`postgres:5432`), which only work inside the environment; the CLI says so once.
- **`env pull` safety**: overwriting asks (`--yes` skips; without a TTY it refuses, exit 2); the file is written atomically with mode 0600; a warning when no `.gitignore` between the file and the repository root ignores it.
- **Requests never follow redirects**, so the token never reaches another host. GETs and idempotency-keyed POSTs are retried once after a network error with the same `Idempotency-Key`; GETs wait out one 429 (`Retry-After`, ≤ 30 s). `deploy` follows the deployment's SSE stream and falls back to polling `GET /deployments/{id}` every 2 s.
- **No shell**: `run` and `open` start processes from argv (`exec.Command(name, args...)`), never `sh -c` (R1; `forbidigo` in golangci).

### 3.4 Credentials

`login` stores the token in the OS keychain (`go-keyring`: macOS Keychain via `security` with the secret on stdin, Secret Service on Linux, Windows Credential Manager) under service `launchroom`, account = dashboard URL. Without a keychain (headless Linux, containers) it falls back to `$XDG_CONFIG_HOME/launchroom/credentials.json` (directory 0700, file 0600, refused if group/other-readable). `~/.config/launchroom/config.json` keeps the current dashboard URL and which store holds its token. On Linux the keychain is tried only when `DBUS_SESSION_BUS_ADDRESS` is set; `LAUNCHROOM_CREDENTIALS_STORE=file` forces the file store. On macOS go-keyring runs `security -i` and writes the command, secret included, to its stdin (never argv). Every keychain call has a 5 s timeout. Tokens never appear in argv, logs or `--json` output (`login --json` prints user, team, abilities, expiry and the store used).

## 4. CLI sign-in (device flow)

`launchroom login --no-browser --json` prints the pending code as the first stderr line (`{"event":"pending","userCode":…,"verificationUrlComplete":…,"expiresIn":600}`) for scripts. Approval uses the current authenticated session and checks team permissions. The CLI keeps polling on `428`, adds 2 s on `429`, exits 4 on `access_denied` and 6 on `expired_token`.

Modelled on OAuth 2.0 device authorization (RFC 8628), without OAuth:

1. `POST /api/v1/cli/sessions {clientName, hostname, version}` → `{deviceCode, userCode, verificationUrl, verificationUrlComplete, expiresIn: 600, interval: 2}`. The device code is 256 bits (only its SHA-256 is stored); the user code is 8 characters from an unambiguous alphabet (`WDJB-MJHT`).
2. The CLI prints the user code and opens `verificationUrlComplete` (`/cli/login?code=WDJB-MJHT`) in the browser.
3. The dashboard (signed in) shows **"Sign in to the launchroom CLI?"** with the code (the user compares it with the terminal), the machine name, client, IP and time, the team (current team, switchable), the abilities (default `read`, `deploy`, `env:read`, `env:write`; `secrets:read` opt-in) and the expiry (default 90 days). **Approve** creates the token (`client = cli`) and keeps its plaintext encrypted (`APP_KEY`) on the request until it is picked up; **Deny** ends it.
4. The CLI polls `POST /api/v1/cli/sessions/token {deviceCode}` every `interval` seconds: `428 authorization_pending`, `403 access_denied`, `410 expired_token`, or `200` with the token **once** (the ciphertext is wiped in the same transaction; a second poll is `410`).

Requests expire after 10 minutes; expired rows are pruned daily. Starting is limited per IP; a user code is only shown to signed-in members and only approves into a team they belong to. A stolen device code is useless without the user approving that exact code.

## 5. Release & install

### 5.1 Build

The release workflow has a `cli` job (same tag, same protected `release` environment): `go build -trimpath -ldflags "-s -w -X main.version=<VERSION>"` with `CGO_ENABLED=0` for linux/amd64, linux/arm64, darwin/amd64, darwin/arm64 and windows/amd64; archives `launchroom_<version>_<os>_<arch>.tar.gz` (`.zip` with `launchroom.exe` on Windows; each with LICENSE), built reproducibly (`-trimpath`, empty build id, `SOURCE_DATE_EPOCH`), `launchroom_<version>_SHA256SUMS`, a cosign keyless signature of the checksum file (`launchroom_<version>_SHA256SUMS.sigstore.json`, same pinned identity as the bundle, [09](09-security.md) R30, verified in the workflow) and build provenance for every archive. The release job uploads them together with `cli.sh`. CI (`ci.yml`, job `cli`) runs the tests with `-race`, cross-compiles all five targets, golangci-lint and govulncheck.

### 5.2 Development

`make test-cli` (`go test -race ./...` in the Go tools container), `make lint-cli` (golangci-lint with the daemon's rules, incl. `forbidigo` against `sh -c`), `make cli` (a binary for the host's platform into `cli/bin/`), `make test-cli-e2e` (against the running dev stack: a local-only `launchroom:dev:cli-approve` command approves the device flow, then `whoami`, `link`, `status`, `env set`, `env pull`, `run`, `deploy --redeploy` and `logs` run in the Go container on the dev network).

### 5.3 Install

`deploy/install/cli.sh` (POSIX sh, shellcheck'd): detects OS/arch, downloads the archive, checksum file and its signature from the GitHub release (`latest` or `--version`), verifies the checksum, verifies the signature with `cosign` when it is installed (exact identity; a present but invalid signature always stops), and installs `launchroom` into `~/.local/bin` (or `--dir`). Linux and macOS only (Windows takes the zip). `latest` is resolved from the `/releases/latest` redirect; `LR_CLI_RELEASE_BASE` points it at another release host (tests). Without cosign it installs on the checksum alone and says so. The settings page and the README show:

```sh
curl -fsSL https://github.com/vantezzen/launchroom/releases/latest/download/cli.sh | sh
```
