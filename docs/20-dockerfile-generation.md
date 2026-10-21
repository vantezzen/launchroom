# 20: Generate a Dockerfile (opt-in, verified by an actual build)

When launchroom can't work out how to build an app, it can **suggest a Dockerfile** and prove it works before anyone uses it: a model drafts it from the repository's manifest files, launchroom lints it, builds it on a build server like any other build, starts the image once and checks the app listens on its port. Only a Dockerfile that built and started is offered. It is stored in launchroom as the service's build override (never written to the repository unless you open a pull request), so nothing changes in your code.

Decision **D48** ([README](README.md)). Builder resolution: [04](04-build-and-deploy.md) §2 step 5. Security rules: [09](09-security.md) R36.

## 1. Opt-in, two switches

| Level | Who | What |
|---|---|---|
| **Instance** (Settings → Dockerfile generation) | instance owners, recently confirmed password, audited | Provider (Anthropic's Claude API, the only one for now), API key, model (default `claude-opus-5-5`, e.g. `claude-sonnet-5` for lower cost), on/off. **Off by default.** |
| **Team** (same page, team card) | team owners (`TeamPolicy::update`, the ManageTeam permission), audited | `teams.settings.generateDockerfiles`. **Off by default.** A team only sees "Generate a Dockerfile" when both are on. |

- The API key is envelope-encrypted (own data key wrapped by the KEK, context `ai_provider:anthropic`, associated data `ai_provider|anthropic`; rotated by `RotateKek`), `$hidden`, `#[SensitiveParameter]`, never in a Data object, log line or Inertia prop. The page shows only "Key ending in …a1b2", who set it and when. Replacing or removing it is audited (`ai_provider.updated`, never the key).
- The model id has a grammar (`^claude-[a-z0-9][a-z0-9.-]{1,62}$`); unknown ids are allowed (new models) but the cost estimate then says "price unknown".
- Starting a generation needs `update` on the service instance (developers and up) on top of both switches.

## 2. What is sent (and what never is)

The page and the sheet say this in plain words before anything is sent.

**Sent to the provider**, once per attempt:

1. **A file listing** of the app directory (the service's root directory): up to 2,000 paths, depth ≤ 8, without `.git`, dependency and build output folders (`node_modules`, `vendor`, `.venv`, `venv`, `__pycache__`, `target`, `dist`, `build`, `.next`, `.nuxt`, `.svelte-kit`, `.turbo`, `.cache`, `coverage`).
2. **Up to 48 files' contents**, each cut at 32 KiB, at most 256 KiB together: manifests and toolchain pins (`package.json`, workspace files, `tsconfig.json`, framework configs such as `next.config.*`/`vite.config.*`/`astro.config.*`, `composer.json`, `requirements*.txt`, `pyproject.toml`, `Pipfile`, `setup.py`, `setup.cfg`, `manage.py`, `go.mod`, `Cargo.toml`, `Gemfile`, `config.ru`, `mix.exs`, `pom.xml`, `build.gradle*`, `deno.json*`, `*.csproj`, `Procfile`, `.nvmrc`, `.node-version`, `.python-version`, `.ruby-version`, `.tool-versions`, `runtime.txt`, an existing `Dockerfile*`), the start of `README*` (8 KiB), and **selected source excerpts**: the first 8 KiB of up to 8 likely entry points (`main.go`, `cmd/*/main.go`, `main.py`, `app.py`, `app/main.py`, `wsgi.py`, `asgi.py`, `server.*`, `index.*`, `app.*`, `src/index.*`, `src/main.*`, `src/server.*`, `src/main.rs`, `Program.cs`). Lockfiles are listed, never sent.
3. **What launchroom detected** (the plan summary: provider, versions, commands, port, and the plan error).
4. For repair attempts: the **lint problems**, or the **last 80 lines of the build or start log** (masked), and the previous Dockerfile.
5. The **names** of the service's build-time variables (so the Dockerfile may mount them as BuildKit secrets, §4), never values.

**Never sent:** `.env` and `.env.*` files, keys and certificates (`*.pem`, `*.key`, `*.p12`, `*.pfx`, `*.crt`, `id_rsa*`, `id_ed25519*`), credential files (`.npmrc`, `.yarnrc*`, `.pypirc`, `.netrc`, `.git-credentials`, `auth.json`, `credentials*`, `secrets*`, `*.tfvars`, `kubeconfig`, `docker-compose*`/`compose*` files, which often inline credentials), binary files, variable values, deployment env snapshots, git history, tokens.

Two filters, both fail closed:

- **launchroomd** selects files during a `plan` task with `inspect: true` (§5): through `os.Root` (no path leaves the checkout), regular UTF-8 files only (no NUL bytes, no symlinks), the deny-list above applied to every path component.
- **The dashboard** re-applies the same deny-list to every path it receives, drops anything over the limits, and **scrubs** contents: private key blocks, live API keys/tokens (Stripe, GitHub, Slack, AWS, Anthropic `sk-ant-…`, Google `AIza…`, JWTs), credentials in URLs (`scheme://user:pass@`), `KEY=value`/`"key": "value"` assignments whose name looks secret (`*_KEY`, `*SECRET*`, `*TOKEN*`, `*PASSWORD*`, `*_PASS`, `*_PWD`, `*CREDENTIAL*`), and the exact values (≥ 6 chars) of the environment's secret variables, each replaced by `[redacted]`. Build/start log tails were masked by launchroomd (every secret value it held) and are scrubbed again.

The generation keeps the **list of files sent** (path, bytes, truncated) and shows it ("What launchroom sent"). The scrubbed context is stored encrypted (`encrypted:array`) for repair attempts and deleted with the generation; unaccepted generations are pruned after 30 days (`launchroom:dockerfile-generations:prune`, daily).

## 3. When it's offered

- **Railpack can't plan the repository**: the service's plan preview failed with `plan_error` (Settings → Build shows the hero "launchroom couldn't detect how to build this app" with **Generate a Dockerfile**), or a deployment failed with `plan_error` (FailureCard key `cannot_plan`, fix action `generate_dockerfile`).
- **A build failed because nothing says how to start the app**: hint `no-start-command` or an unknown framework (FailureCard key `no_start_command`, same action).
- **On demand**: Settings → Build, card "Generate a Dockerfile", for any app service whose source is a git repository.

When a switch is off, those places show one calm line instead ("launchroom can suggest a Dockerfile for this app. An owner can turn this on in Settings → Dockerfile generation."), never a button that fails.

## 4. Generation and the Dockerfile rules

**Client.** The official Anthropic PHP SDK (`anthropic-ai/sdk`) with an explicit API key and base URL `https://api.anthropic.com` (never `ANTHROPIC_*` environment variables or credential files), over a Guzzle transport without proxies or redirects, 10 s connect, 300 s total, 1 retry. One `messages.create` call per attempt: the configured model, `max_tokens` 16,000, adaptive thinking (the default), `output_config.effort` `high` and **structured output** `output_config.format = {type: json_schema, schema}` returning

```json
{ "dockerfile": "…", "port": 3000, "startCommand": "node server.js", "explanation": "…", "confidence": "high|medium|low" }
```

The dashboard validates the JSON again (types, lengths: Dockerfile ≤ 64 KiB, explanation ≤ 2,000 chars, port 1–65535), and handles `stop_reason` `refusal`/`max_tokens` as a failed attempt with a clear message. The request carries `metadata.user_id` = an opaque hash of the team id (no personal data). Usage (input/output tokens) is recorded per attempt; the sheet shows the cost from `launchroom.dockerfile_generation.prices` (USD per million tokens; `claude-opus-5-5` $4/$20, `claude-sonnet-5` $2/$10).

The contract is `App\Contracts\DockerfileModel`; `AnthropicDockerfileModel` is the real one, `StubDockerfileModel` (local/testing only, refused elsewhere) answers from a JSON file so the dev stack runs end to end without a key (§8).

**Lint** (`App\Support\Dockerfiles\DockerfileLinter`, pure, every refusal has a line number and feeds the repair prompt):

- Parsed like BuildKit: logical lines (backslash continuation, comments, heredocs `<<EOF`), instructions case-insensitive. Size ≤ 64 KiB, ≤ 400 lines, ≤ 16 stages, a final stage with `CMD` or `ENTRYPOINT`.
- **Allowed instructions:** `FROM RUN CMD LABEL EXPOSE ENV ADD COPY ENTRYPOINT USER WORKDIR ARG STOPSIGNAL HEALTHCHECK SHELL`. Refused: `ONBUILD`, `VOLUME` (volumes are launchroom's), `MAINTAINER`. Parser directives: only `escape`; `syntax=` is refused (builds use the built-in frontend).
- **`FROM` is digest-pinnable**: `FROM <image>:<tag>[@sha256:…] [AS <name>]` or a previous stage; no `latest`, no untagged images, no variables in the reference, no flags (`--platform` is set by launchroom). The same for `COPY --from=<image>` that isn't a stage. After the lint every external reference is **pinned**: its tag is resolved through the registry mirror (`ImageDigestResolver`) and rewritten to `image:tag@sha256:…`; a reference that doesn't resolve is a refusal ("ghcr.io/x/y:1.2 doesn't exist") the model can fix.
- **No host features**: `RUN --network=host`, `RUN --security=insecure` (BuildKit's privileged mode), `RUN --mount=type=ssh` and `ADD <url>`/`ADD --keep-git-dir` are refused.
- **No secrets in `ARG`/`ENV`**: names that look secret (as in §2) or equal one of the service's secret variables, and values that look like credentials, are refused. Build-time variables reach the build only as BuildKit secrets, **only through launchroom's mechanism**: `RUN --mount=type=secret,id=<NAME>[,env=<NAME>|,target=/run/secrets/<NAME>]` where `NAME` is one of the service's build-time variable names; any other secret id (or `required=…` tricks) is refused. `type=cache`, `type=bind` and `type=tmpfs` mounts are allowed.
- **Port**: the final stage must `EXPOSE` the draft's port (TCP), so the build summary's port matches.

## 5. Verification by an actual build

States (`DockerfileGenerationStatus`, transition table on the enum): `reading → drafting → building → starting → verified`, any → `failed`/`cancelled`; `building`/`starting` → `drafting` for a repair. Orchestration is event-driven like deployments: `AdvanceDockerfileGeneration` (row-locked, idempotent) runs from `AdvanceDockerfileGenerationJob` on start, after each model call (`DraftDockerfileJob`, queued, never in a request) and when the generation's daemon tasks finish (`DaemonTaskFinished`), plus the minute sweep for timeouts.

1. **reading**: a `plan` task (§13 of [04](04-build-and-deploy.md)) for the branch head with `inspect: true`; launchroomd clones, collects the listing and files (§2), plans, and reports `inspection` with the result (also when planning fails, which is the usual case here). Timeout 5 min.
2. **drafting**: `DraftDockerfileJob` builds the prompt from the scrubbed inspection, the plan summary and earlier attempts, calls the model, validates, lints and pins. A lint refusal is a failed attempt (no build).
3. **building**: a normal `build` task on a build server (routing as [04](04-build-and-deploy.md) §5.1) with `builder: {kind: dockerfile, dockerfile: "<content>"}`: the Dockerfile is **not** in the repository; launchroomd writes it to a private directory outside the checkout and builds the app directory with it. Build variables are sent by **name only** (empty values, like plan previews), so no secret reaches a build whose Dockerfile nobody reviewed yet. The task carries a fresh deployment-shaped id (`spec.deployment`, never a `deployments` row) that tags the image `apps/<service>:<id>`; the image counts toward the service's registry retention like any build.
4. **starting**: one `run_once` task with the built image, pinned to an online runtime server (the build server when it has the runtime role, so no pull), on the environment's network, with the service's resource limits, env `PORT=<port>` only (sealed, no variables), and the new **`probe`**: launchroomd starts the container and checks from outside that it accepts TCP connections on the port (and, when the plan detected a health path, that `GET <path>` answers below 500) within 90 s, then stops and removes it. The result says whether it listened, after how long, the HTTP status, and on failure which ports the process did open ("listens on 5173, not 3000") and whether it exited.
5. **verified** when the probe passed. Otherwise up to **N = 2 repairs** (`launchroom.dockerfile_generation.repairs`): the failed step's masked log tail (last 80 lines, scrubbed) or the probe outcome goes back to the model with the previous Dockerfile. After the last attempt the generation is `failed` with the reason in plain words.

Limits: one running generation per service instance (partial unique index), 10 starts per team per hour, cancel any time (pending tasks cancelled, leased ones via `cancel_requested_at`).

**Use this Dockerfile** (only for `verified`): `UseGeneratedDockerfile` stages `build.builder = dockerfile`, `build.generatedDockerfile = dfg_…` (and clears `dockerfilePath`) through `UpdateServiceSettings`: an ordinary staged rebuild ("Save for next deploy"). Build tasks of that instance then send the generation's stored Dockerfile inline (team- and service-checked, verified only; the content is immutable once verified, so revisions stay reproducible). The reference promotes with the service like any build setting; `launchroom.json` export leaves it out (the file can't carry it). Switching the builder away from `dockerfile` ignores it.

**Commit to repository** (optional): opens a pull request through the git provider: branch `launchroom/dockerfile-<short id>` from the default branch, one commit adding `<root>/Dockerfile` ("Add a Dockerfile (verified by launchroom)"), the explanation and the verification result in the description. GitHub (needs the App's *Contents: read and write* permission, which the default manifest doesn't request: launchroom asks for a token with `contents: write, pull_requests: write` and explains how to grant it when GitHub refuses), GitLab (merge request via the commits API) and Gitea/Forgejo (contents API with `new_branch`). Plain git servers: "Download Dockerfile" instead. Refused when a file already exists at that path. Audited (`dockerfile_generation.pull_request_opened`).

## 6. Protocol (additive, daemon feature `generatedDockerfile`)

Specs using any of this are leased only to daemons that announce `generatedDockerfile` in `Report.host.features` ([03](03-daemon-protocol.md) §10).

- `BuilderSpec.dockerfile` (string, 1–65,536 bytes, valid UTF-8, no NUL): only with `kind: dockerfile`, never with `dockerfilePath`. launchroomd parses it with the BuildKit parser (same facts as a repository Dockerfile: ARGs, final `EXPOSE`, CMD, dev-only warning) and builds `rootDir` with it; the summary says `builder: dockerfile`, `builderReason: explicit`, no `dockerfilePath`.
- `PlanSpec.inspect` (bool) → `TaskCompletion.inspection: RepoInspection {tree: [RelPath] (≤ 2000), treeTruncated, files: [InspectedFile {path: RelPath, content (≤ 32,768 bytes), truncated}] (≤ 48, ≤ 262,144 bytes together)}`, reported for succeeded and failed plan tasks once the checkout exists.
- `RunOnceSpec.probe: RunOnceProbe {port: Port, path?: HTTPPath, within: Duration (1 s–10 min)}`; with a probe, `command` and `args` may both be omitted (the image's own CMD). `RunOnceResult.probe: ProbeOutcome {listening, afterMs, httpStatus?, exited, listeningPorts? (≤ 16)}`; `exitCode` is the container's exit code, or 0 when launchroomd stopped it after a passing probe.

## 7. UI

- **Settings → Dockerfile generation** (Team group; the provider card is the owner's, the team card the team owner's): provider card (Anthropic logo-less monochrome tile, "Claude API", model select with the two suggestions + custom, key field "Key ending in …", Remove key), on/off switch, the data-sharing notice as a recessed list, cost note; team card with its switch.
- **Guided sheet** (`DockerfileGenerationSheet`, `SidePanel` lg): *Intro*: "launchroom couldn't detect how to build this app" (or "Generate a Dockerfile" on demand), one sentence, **What launchroom sends** (three rows) with "Uses your Anthropic API key · model · about $0.05–0.50 per run", primary **Generate a Dockerfile**. *Running*: a `StepList` Reading your repository → Drafting a Dockerfile → Building it → Starting your app on port N → Ready, live (polls 2 s), "Second try: fixing the build" when repairing, Cancel. *Verified*: "Your app builds and starts" with the facts (port, start command, confidence, built in, started in, cost), the Dockerfile as a **diff-style view** (line numbers, `+` gutter against nothing, or `~`/`+`/`−` against the previous attempt), the explanation, **Use this Dockerfile** (primary), **Open a pull request** (outline, when the provider supports it), Copy/Download, "What launchroom sent". *Failed*: attention disc (amber), one sentence, the last reason, the last Dockerfile collapsed, Try again. Honest wording: "launchroom suggests" and "drafted with Claude", never "agent".

## 8. Development and trying it with a real key

- Dev runs the stub model: `dev/compose.yml` sets `LAUNCHROOM_DOCKERFILE_MODEL=${LR_DOCKERFILE_MODEL:-stub}` and `LAUNCHROOM_DOCKERFILE_STUB_RESPONSE` to `dashboard/tests/Fixtures/Dockerfiles/unplannable-site.json`. The fixture repo `dev/fixtures/unplannable-site` (plain HTML in `www/`, nothing Railpack recognizes) is served by git-fixtures. `php artisan launchroom:dev:dockerfile-generation` (`make artisan ARGS="launchroom:dev:dockerfile-generation"`) turns both switches on, creates the project, runs a generation to `verified` on the dind servers (inspecting plan → stub draft → lint/pin → build → start probe), uses the Dockerfile and deploys it.
- With a real key: set `LR_DOCKERFILE_MODEL=anthropic` in `dev/.env` (`anthropic` is the default outside the dev stack), recreate the dashboard containers (`make up`), restart the workers (`make restart-workers`), open Settings → Dockerfile generation as the instance owner, paste a key from console.anthropic.com, turn on the instance and team switches, then use **Generate a Dockerfile** on any app service. The dashboard container reaches `api.anthropic.com` directly (no proxy).
