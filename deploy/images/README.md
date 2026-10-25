# Image Dockerfiles

Images that users run, published as `ghcr.io/vantezzen/launchroom-*`
(docs/10-codebase-conventions.md §1, docs/11-installation-and-dev.md).

| Directory | Image | Notes |
|---|---|---|
| `dashboard/` | `launchroom-dashboard` | Laravel on FrankenPHP (classic mode), PHP 8.4 |
| `daemon` | `launchroomd` | Built from `daemon/Dockerfile` (static Go binary) |
| `caddy/` | `launchroom-caddy` | Edge Caddy: xcaddy build of Caddy v2.11.7 with `caddy.storage.redis` (pberkel/caddy-storage-redis v1.9.0) and the DNS-01 providers `cloudflare` (v0.2.4), `hetzner` (v2.0.1), `route53` (v1.6.2), `digitalocean` (commit `04bde2867106`, no upstream tags), `porkbun` (v0.3.1), `desec` (v1.1.0), `gandi` (v1.2.1), `ovh` (v1.1.0), `netcup` (v1.0.0) (D47, docs/05 §4–5), and the web firewall `http.handlers.waf` (coraza-caddy v2.6.2, Coraza v3.8.1, OWASP CRS 4.25.0 embedded; all Apache-2.0; versions also in the image labels `io.vantezzen.launchroom.waf.*`, D63, docs/22 §6.1); multi-arch (cross-compiled, no emulation). See below. |
| `zot/` | stock `ghcr.io/project-zot/zot` v2.1.22, pinned by digest (`sha256:96cda114…`) | `config.json`: production registry config (docs/05 §6). The installer writes it to `data/zot/config.json` with `__LAUNCHROOM_URL__` replaced by the dashboard URL; compose mounts `data/keys/registry-public/` read-only at `/etc/zot/keys` and publishes 5000 on `127.0.0.1` only (launchroomd serves it on the control plane's mesh IP, `system.registry.serveOnMesh`). Same settings as `dev/zot/config.json` (bearer auth with dashboard-issued ES256 tokens, `docker2s2` compat, on-demand `mirror/docker.io` + `mirror/ghcr.io`, retention). |

## dashboard

The build context is the **repo root**:

```bash
docker build -f deploy/images/dashboard/Dockerfile --target production -t ghcr.io/vantezzen/launchroom-dashboard:dev .
```

Targets:

- `production`: code, `vendor/` without dev dependencies (optimized autoloader)
  and the Vite build baked in. Runs as the non-root user `launchroom` (1000:1000),
  with code read-only and only `storage/` and `bootstrap/cache/` writable. One
  image runs every PHP process; only the command differs:

  | Role | Command |
  |---|---|
  | web (default) | `frankenphp run --config /etc/frankenphp/Caddyfile` |
  | horizon | `php artisan horizon` |
  | reverb | `php artisan reverb:start` |
  | scheduler | `php artisan schedule:work` |

  `entrypoint.sh` waits for the database (`LR_DB_WAIT_TIMEOUT`, default 60 s),
  runs `migrate --force --isolated` (web only by default; `LR_RUN_MIGRATIONS=auto|true|false`),
  warms the config/route/view/event caches (`artisan optimize`), then execs the command.
  The image healthcheck probes `GET /up` on :8000; worker services should
  disable or replace it in their compose definition.

- Multi-arch: `docker buildx build --platform linux/amd64,linux/arm64 …`. The
  stages `build-php` (the same FrankenPHP base, extensions and `php.ini`
  overrides as `base`), `vendor` (composer, `--no-dev`) and `assets` (Vite, with
  the wayfinder plugin calling `php artisan`) run on the **build** platform, once,
  because their output is platform-independent. Only `base`/`production` run
  per target platform (OS packages, PHP extensions, users, copies), so an
  emulated platform only pays for those. (Releases build each platform on a
  native runner anyway, because compiling the PHP extensions is the slow part
  under QEMU: `.github/workflows/release.yml`, jobs `dashboard-platform` and
  `dashboard`.) Composer runs with the same PHP and
  extensions as the image, so platform requirements are checked as on the
  target (no `--ignore-platform-reqs`).

- `dev`: no app code (dev/compose.yml bind-mounts `dashboard/`), development
  `php.ini`, composer and Node 24 included (the Vite wayfinder plugin calls
  `php artisan`). The user matches the host UID/GID (`--build-arg UID=… GID=…`).

The production image also ships the launchroomd installer (`deploy/install/server.sh`,
`deploy/daemon/compose.yml`) in `/var/www/install`, which the dashboard serves
templated at `/install/server.sh` (docs/11 §3).

The web server speaks **plain HTTP on :8000**. TLS terminates at the edge
Caddy managed by launchroomd, which also sets HSTS. `Caddyfile` adds the
security headers that don't depend on the transport. CSP is left to the app.

Extensions: pdo_pgsql, pgsql, redis (phpredis), sodium, intl, pcntl, zip,
opcache, bcmath (on top of the FrankenPHP defaults).

## caddy

The build context is `deploy/images/caddy`:

```bash
docker buildx build --platform linux/amd64,linux/arm64 -t ghcr.io/vantezzen/launchroom-caddy:<version> deploy/images/caddy
```

Base images (`caddy:2.11.7-builder`, `caddy:2.11.7`) and module versions are pinned in the Dockerfile; the build fails if a module is missing. launchroomd only runs a Caddy image whose digest it knows (docs/09 R31), so **every release pins the multi-arch index digest twice, from the same build** (`.github/workflows/release.yml`, nothing to edit by hand):

1. In launchroomd: the `caddy` job builds and signs launchroom-caddy first; the `daemon` job then builds launchroomd with `--build-arg CADDY_DIGEST=<that digest>`, which `daemon/Dockerfile` compiles into `policy.DefaultSystemImageDigests[caddy]` (`-X …/internal/policy.launchroomCaddyDigest`). A release build accepts only that digest; a dev build (no `CADDY_DIGEST`) only the stock `caddy:2.11.7` digest. `launchroomd system-images` prints the pins; the workflow refuses to sign a launchroomd that doesn't pin the caddy digest. Servers get the pin with launchroomd itself: the control plane from `images.env`, joined servers through `server.sh` (same release's launchroomd), every server again with each self-update. An edge on the previous release keeps its running pinned Caddy until it is updated (docs/05 §4).
2. In the bundle: `deploy/release/build-bundle.sh --caddy-image` (required) writes `LR_CADDY_IMAGE=ghcr.io/vantezzen/launchroom-caddy@sha256:…` into `control-plane/images.env`; `deploy/control-plane/compose.yml` passes it to the dashboard as `LAUNCHROOM_CADDY_IMAGE` (with `LAUNCHROOM_CADDY_MODULES` derived from the name, so shared storage and DNS-01 are on; `LAUNCHROOM_CADDY_DNS_PROVIDERS` empty = all of this release's providers), which puts it into edge NodeStates.

Local builds (`make caddy-image`, `make caddy-image-load`; `make caddy-image-pin` writes both dev pins into `dev/.env`) are pinned on the server with `LR_SYSTEM_IMAGE_DIGESTS=caddy=sha256:…` (host config; the dashboard can never widen the allow-list), docs/11 §6.
