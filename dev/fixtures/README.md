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
| `unplannable-site` | A static page in `www/` with no manifest: Railpack can't plan it (`plan_error`), the "Generate a Dockerfile" end to end (docs/20 §8) with the stub answer `dashboard/tests/Fixtures/Dockerfiles/unplannable-site.json` (busybox httpd on 8080) |

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
