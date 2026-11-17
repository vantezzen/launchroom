# Dev fixtures

Small sample apps for exercising builds and deployments through the dev stack
([docs/11](../../docs/11-installation-and-dev.md), [docs/04](../../docs/04-build-and-deploy.md)). They are
deployed into the dind fake servers, never onto the laptop's own Docker.

Fixtures intentionally have **no Dockerfile** unless the fixture is about the
Dockerfile path: they exist to test Railpack detection and launchroom presets.
Each one must honour `$PORT` and answer quickly on a health path.

| Fixture | Exercises |
|---|---|
| `node-hello` | Plain Node, no dependencies, `npm start`, `$PORT`, `/health`, graceful SIGTERM (node-server preset); `/db` does one Postgres round trip over `DATABASE_URL` (`db.js`, a dependency-free client) and reports the replica (container hostname) and `release` (branch `failover`: two commits, release 1 and 2, for the failover test, `make test-failover`) |
| `node-vulnerable` | Node with a known-vulnerable runtime dependency (`lodash` 4.17.20) and dev dependency (`minimist` 1.2.5) in `package-lock.json`: the security center's inventory, findings and alert levels (docs/22 §3, §5) |
| `vite-spa` | Static build output served by Railpack's Caddy, SPA fallback, `VITE_*` build-time variable (static-spa preset) |
| `next-standalone` | Next.js with `output: 'standalone'`: the nextjs preset copies static assets and starts `node .next/standalone/server.js` |
| `next-adapter` | Next.js 16.4 (≥ 16.2) without `output` in next.config: the nextjs preset injects `@vantezzen/launchroom-next-adapter` (standalone, `deploymentId`, static assets, manifest). App Router, ISR page `/isr` (60 s), `proxy.js` (adds `x-launchroom-proxy: 1`), `public/launchroom.txt`, health `/api/health`. Integration copy: `daemon/test/integration/testdata/build/next-adapter` |
| `dockerfile-hello` | Repository Dockerfile wins over Railpack; declared `ARG` as build arg, `BUILD_SECRET` as a BuildKit secret, `EXPOSE` as port |
| `uvicorn-dockerfile` | FastAPI behind its own Dockerfile: exec-form `CMD` uvicorn on `0.0.0.0:8000`, no `EXPOSE` (the port comes from `--port`). `FIXTURE_MODE=crash` raises while importing, `slow` waits 90 s before it listens; a start command with `--host 127.0.0.1` binds loopback only. The rollout failure cases of D68 (docs/04 §8.1) |
| `laravel-sqlite` | A minimal Laravel 12 app written for SQLite (no `ext-pdo_pgsql`, `.env.example` says `sqlite`). Connected to Postgres, launchroom adds `pdo_pgsql` to its Railpack build (docs/04 §3.1); `/driver` reports the connection Laravel uses and PHP's PDO drivers, `/up` is the health path. Integration copy: `daemon/test/integration/testdata/build/laravel-sqlite` |
| `unplannable-site` | A static page in `www/` with no manifest: Railpack can't plan it (`plan_error`), the "Generate a Dockerfile" end to end (docs/20 §8) with the stub answer `dashboard/tests/Fixtures/Dockerfiles/unplannable-site.json` (busybox httpd on 8080) |

## Sample apps (generated, zero edits)

These fixtures are what a framework's own generator writes, committed as a
developer would commit it right after generating: the files git tracks under
the app's own `.gitignore` (lockfiles yes; `node_modules`, `vendor` and build
output no), nothing edited. `make fixture-gen NAME=<name>` (or `NAME=all`)
regenerates one from its recipe `dev/apps-e2e/gen/<name>.sh` in a pinned,
labelled `--rm` container; images and generator versions are pinned in
`deploy/pins.env` (`LR_FIXTURE_*`), npm resolves as of `LR_FIXTURE_NPM_BEFORE`.
A regeneration replaces the fixture but keeps its `.branches/`.

Diagnostics never go into the generated tree: a `probe` branch overlay
(`.branches/probe/1/`) adds a route only where an outside check can't see a
fact (the app's idea of its URL, scheme or client address). `make test-apps`
(`dev/apps-e2e.sh`, cases in `dev/apps-e2e/cases/<name>.sh`) imports each
fixture with no settings, deploys it, asserts from outside through the dev
edge (web firewall in Block) and opens a `pr-1` preview; every assertion
names the gaps it covers.

| Fixture | Generator (recipe) | Branches |
|---|---|---|
| `next16-stock` | `create-next-app@16` defaults (App Router, TS, Tailwind, `cacheComponents`) | `probe`: form action, client-called action, `use cache` page, `proxy.ts` |
| `next15-stock` | `create-next-app@15.5` (App Router, TS, Turbopack) | |
| `next-authjs` | next16 + `next-auth@5` with the Credentials provider from Auth.js's guide (hand-added `auth.ts` and route), no `AUTH_SECRET` | |
| `turbo-pnpm` | `create-turbo` with pnpm (`apps/web`, `apps/docs`, `packages/ui`) | |
| `laravel13-react` | `laravel new --react` today: the React starter kit (Laravel 13, Inertia, Wayfinder) at a pinned commit, install and post-create scripts | `probe`: `/probe` (app URL, storage URL, scheme, client IP, route cache); `duplicate-route`: two routes with one name |
| `laravel12-filament` | `laravel/laravel` 12 + Filament v4 panel (`filament:install --panels`) + Horizon | |
| `laravel10-stock` | `laravel/laravel` 10 (no `/up`) | |
| `symfony-webapp` | what `symfony new --webapp` runs (skeleton + `webapp` pack) | |
| `django-startproject` | `django-admin startproject mysite .` + `pip freeze` | `probe`: `/probe/` (`is_secure`, host) |
| `django-env-settings` | startproject + a hand-written django-environ settings block (allowed hosts, secret, `DATABASE_URL`, proxy header), gunicorn, psycopg | `probe`: `/probe/` |
| `fastapi-noroot` | FastAPI's first example without `/` (hand-written, frozen requirements) | `probe`: `/probe` (`request.url`) |
| `rails8-new` | `rails new` (SQLite, Dockerfile, Kamal; `master.key` not committed) | |
| `rails8-railpack-pg` | `rails new --database=postgresql --skip-docker` | |
| `phoenix-liveview` | `mix phx.new` (LiveView, Postgres) + `mix deps.get` | `probe`: what `mix phx.gen.release` writes (generated) |
| `sveltekit-node` | `sv create` (minimal, TS) + `sv add sveltekit-adapter=adapter:node` | `probe`: `/probe` (form action, `getClientAddress`, URL) |
| `sveltekit-auto` | `sv create` (minimal, TS, adapter-auto) | |
| `astro-node` | `create-astro` (minimal) + `astro add @astrojs/node` | `probe`: on-demand `/probe` accepting a form POST |
| `nuxt-stock` | `create-nuxt` (template `minimal`; template from GitHub, not versioned) | |
| `rr7-ssr` | `create-react-router@7` default template (from GitHub, not versioned) | `probe`: `/probe` (`request.url`) |
| `express-api` | `express-generator --no-view` + `npm install` | `probe`: `/probe` (`req.secure`, `req.ip`) |
| `deno-serve` | `deno init --serve` + `deno install` | |
| `rust-rocket` | Rocket's hello world (hand-written) + `cargo generate-lockfile` | |
| `go-gin` | Gin's quick start (hand-written) + `go mod tidy` | |
| `spring-boot-actuator` | Spring Initializr (Maven, Java 21, Web, Actuator) | |

Hand-written (no generator, edited in place):

| Fixture | Exercises |
|---|---|
| `nginx-dockerfile` | `FROM nginx:alpine` + `COPY index.html`: an image that switches user at start |
| `nonroot-volume` | Dockerfile with `USER 1000` writing to `/data`, a path only a volume provides (`GET /write`) |
| `stream-upload` | Node, no dependencies: `GET /events` (server-sent events, `?count=&interval=`), `POST /upload` (streams the body, answers its size and SHA-256) |
| `failure-lab` | Go, no dependencies, fails on purpose by mode (`FIXTURE_MODE` or `mode.txt`): branches `crash-late` (60-line trace, exit 3), `oom`, `hang`, `exit127` (Dockerfile whose `CMD` doesn't exist), `v2-crash` (step 1 healthy, step 2 crashes after 60 s) |

The `git-fixtures` service of the dev stack publishes every directory here as a
git repository over smart HTTP (one deterministic commit per fixture). It
rescans this directory before every `GET /` and before every clone or fetch
(`/<name>.git/info/refs`): new, edited and deleted fixtures are picked up
without restarting anything, and a fixture whose files did not change keeps
its SHA. `make fixtures` additionally rebuilds the service (`up -d --build`)
when the server's own code (`daemon/test/gitserve`) changed:

```bash
make fixtures            # names, clone URLs and commit SHAs
# clone URL inside the dev network: http://git-fixtures:8080/<name>.git
```

server-1's daemon may clone it over plain http because the dev stack sets
`LR_BUILD_INSECURE_GIT_HOSTS=git-fixtures`. A build task's spec uses
`"repo": {"provider": "git", "cloneUrl": "http://git-fixtures:8080/node-hello.git", "sha": "<sha from make fixtures>"}`.

Try a fixture by hand (throwaway container, nothing installed on the host):

```bash
docker run --rm -p 127.0.0.1:3000:3000 -e PORT=3000 -v "$PWD/dev/fixtures/node-hello:/app:ro" -w /app node:24-alpine npm start
```

## Branches

A fixture may carry extra branches as overlays in `.branches/<branch>/<step>/`:
each step's files are copied over the tree and committed on `<branch>`
(created from `main`), one commit per step. The overlay directory is never
part of a commit. `GET /` on `git-fixtures` lists the commits per branch
(oldest first). `node-hello` has a `preview` branch with two commits (its
server also reports `preview`, `commit`, `environment` and the `DATABASE_URL`
host) for the preview environment simulation:

```bash
make artisan ARGS="launchroom:dev:pr open --project=prj_… --number=1 --branch=preview"
```
