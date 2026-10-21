# 11: Installation, upgrades & local development

This doc covers the internals of the install bundle, releases, updates and the dev environment. The user-facing flows are on the site:

- [Install](https://launchroom.vantezzen.io/docs/getting-started/install): requirements, installer, owner account, folder, helper, options, uninstall.
- [Add a server](https://launchroom.vantezzen.io/docs/servers/add-a-server): join command, firewall expectations, removing a server.
- [Rootless Docker](https://launchroom.vantezzen.io/docs/servers/rootless-docker): preparing a rootless host, capabilities and limits.
- [Updates](https://launchroom.vantezzen.io/docs/operations/updates): guided flow, rollback, channel, automatic updates, signed releases, manual path.

## 1. Principles

- **Prerequisite: Docker Engine ≥ 24 with the compose plugin, on Linux.** Nothing else. The installer never installs packages, writes to `/etc`, creates systemd units or edits `daemon.json`. If Docker is missing, it prints the official install instructions and exits.
- **Everything lives in one folder** (default `./launchroom` relative to where you run the installer, overridable with `LAUNCHROOM_DIR`). Outside that folder, launchroom creates only **Docker-managed objects** (containers, images, named volumes, networks), all labelled `launchroom.managed=true`, plus kernel state launchroomd and Tailscale create at runtime: launchroomd's nftables table `inet launchroom` (network isolation, every server) and, on hosts that join the mesh, a `tailscale0` interface. That state is not persisted to disk and is gone after uninstall and reboot.
- **Uninstall is one command**: `./launchroom uninstall` (§2.3).

## 2. Control-plane install

### 2.1 Install bundle and installer (`deploy/`)

| File | What |
|---|---|
| `deploy/install/install.sh` | The installer (POSIX `sh`, shellcheck-clean, `set -eu`). |
| `deploy/control-plane/compose.yml` | The stack: `dashboard`, `horizon`, `reverb`, `scheduler` (one image, different commands), `postgres`, `valkey`, `zot`, `headscale`, `dockerd-guard`, `launchroomd`. **Managed**: replaced on every install and upgrade; local changes go to `compose.override.yml`. |
| `deploy/control-plane/images.env` | Image pins of the release. Third-party images by digest; the launchroom images (`LR_DASHBOARD_IMAGE`, `LR_DAEMON_IMAGE`, `LR_CADDY_IMAGE`) get digests from the release pipeline (§2.5). Copied into `.env` on every run; `LR_CADDY_IMAGE` reaches the dashboard as `LAUNCHROOM_CADDY_IMAGE`. |
| `deploy/control-plane/.env.example` | Template of `.env` (settings, generated secrets, image pins). |
| `deploy/control-plane/launchroom` | Helper CLI copied into the folder (§2.3). |
| `deploy/daemon/compose.yml`, `deploy/install/server.sh` | Server install (§3). |
| `deploy/test/e2e-install.sh` | `make test-install` (§2.4). |
| `deploy/release/build-bundle.sh`, `deploy/release/check-pinned-images.sh` | Release bundle and pin check (§2.5). |

**Installer options**: `--dir DIR` (or `LAUNCHROOM_DIR`, default `./launchroom`), `--domain HOST`, `--version V`, `--unstable` (the newest release including pre-releases, read from the GitHub releases API, and `LR_UPDATES_CHANNEL=beta` so Settings → Updates offers pre-releases), `--bundle DIR` (an unpacked bundle such as the repo's `deploy/`; *not* signature-verified, for tests, air-gapped installs and development), `--no-public-ip-detect`, `--skip-port-check`, `--allow-unsigned`, `--restore`, `--recovery-kit FILE`, `--restore-allow-http`. Image pins can be overridden from the environment (`LR_DASHBOARD_IMAGE`, `LR_DAEMON_IMAGE`, `LR_CADDY_IMAGE`, `LR_POSTGRES_IMAGE`, `LR_VALKEY_IMAGE`, `LR_ZOT_IMAGE`) for mirrors and tests. `LR_CADDY_IMAGE` must be an image whose digest the installed launchroomd pins ([05](05-networking-ingress.md) §4).

**Steps**, in order:
1. Checks: Linux; `docker` present and not from snap; Engine ≥ 24; the compose plugin; RAM < 2 GB → warning; 80/443 free (read from `/proc/net/tcp*`, skipped when our own `lr-system-caddy` holds them on a re-run). Missing Docker prints the official install link and exits; nothing is installed.
2. Bundle: `--bundle DIR`, or download `launchroom-deploy.tar.gz` + `.sha256` from the GitHub release (`latest` or `v<version>`) into `launchroom/.tmp`. The checksum must match. Then `launchroom-deploy.tar.gz.sigstore.json` is verified with a digest-pinned `cosign` container (`verify-blob`, keyless): issuer `https://token.actions.githubusercontent.com`, certificate identity **exactly** `https://github.com/vantezzen/launchroom/.github/workflows/release.yml@refs/tags/v<VERSION>`, where `VERSION` is read from the bundle (semver-checked; with `--version V` it must equal V). A signature that does not verify always stops the install. A release without one is accepted only with `--allow-unsigned` (development builds; the installer says it was only checksum-verified). It never reports a verification it did not do. `--bundle` is not verified (it warns), except when launchroomd's update supervisor passes the bundle it verified itself (`LR_BUNDLE_VERIFIED=1`, §4.2).
3. Folder: `compose.yml`, `launchroom`, `data/{keys,postgres,valkey,zot,daemon,backups}`, `data/daemon/trust.json` (the pinned release identity for self-updates: `issuer`, `identity` prefix, `releaseBase` = `LAUNCHROOM_RELEASE_BASE` or GitHub; written only if missing, never replaced by a re-run or an update), `data/zot/config.json` (from `deploy/images/zot/config.json`, realm = `APP_URL`, only for a new install; after the stack is up the dashboard re-renders it with `launchroom:registry:config --write`, local disk or S3 per Settings → Registry, and zot is restarted if it changed, [14](14-backup-and-restore.md) §9.11), `data/headscale/config.yaml` (from `deploy/images/headscale/config.yaml`, `server_url` = `APP_URL`) and `data/headscale/lib` (Headscale's state). `.env` is created only if missing (umask 077, mode 0600): `APP_KEY`, `DB_PASSWORD`, `VALKEY_PASSWORD`, `REVERB_APP_ID/KEY/SECRET` from `/dev/urandom`. `APP_URL` = `https://<--domain>` or `https://<ip-dashed>.sslip.io` (IPv4 from api.ipify.org / ipv4.icanhazip.com / ifconfig.me, IPv6 fallback; IPv6 `:` become `-`). Image pins are (re)written from `images.env`.
4. `docker compose up --wait postgres valkey`. `data/keys`, `data/backups` and `data/transfers` (uploaded and exported files while an import or download uses them, D55) are chowned to the dashboard image's user (1000) through a one-off container, so the installer needs no root, only Docker access.
5. `--restore` only: §2.2.
6. `launchroom:keys:generate` (KEK, signing key, registry token key; never overwrites), then `up --wait` of the whole stack except launchroomd. The dashboard's entrypoint migrates (`migrate --force --isolated`).
7. Local launchroomd: `launchroom:servers:bootstrap-local` (JSON). The CP public key goes to `data/daemon/cp-key`; the join token to `data/daemon/join-token` (0600) and `LR_JOIN_TOKEN_FILE=/data/join-token` into `.env`; `up -d launchroomd`; wait for `data/daemon/enrollment.json`; then the token file and the setting are removed and launchroomd restarted. The token is never in argv or output. The server gets the roles `edge`, `runtime` and `build`. **This runs before /setup**: with no user yet, the server is enrolled into an *unclaimed instance team* (`EnsureUnclaimedInstanceTeam`) that the owner claims at /setup (`CreateInstanceOwner`; audited `instance.team_claimed`), because the dashboard is only reachable through this launchroomd's Caddy.
8. Print the dashboard URL (the canonical one, `launchroom:dashboard-url`) and `<APP_URL>/setup?token=…` (`launchroom:setup-token --plain`; after setup: "sign in"), plus the reminder to back up `.env` and `data/keys/` (they decrypt every secret). The setup token is the only secret printed.

**Re-running** is the upgrade and repair path:
- `.env` (secrets) and `data/` are kept; `compose.yml`, the helper, the Zot config and the image pins are replaced.
- `--domain` changes `APP_URL`. When Settings → Dashboard domain moved the dashboard elsewhere, it makes `APP_URL` canonical again through `launchroom:dashboard-domain:reset`; the other domain keeps forwarding ([05](05-networking-ingress.md) §4.1).
- The stack is brought up to date. launchroomd is enrolled only if it is not; if the DB knows an enrolled control-plane server but `data/daemon` lost its identity, it is re-enrolled with `--force`.
- A new setup link is printed while setup is incomplete.

**Ingress for the dashboard ("system route")**:
- The dashboard publishes `127.0.0.1:${LR_DASHBOARD_PORT:-8000}` only; nothing in the compose file publishes 80/443.
- The control-plane server's NodeState contains one extra route, `rt_0000000000000000000000DASH`: host = `APP_URL`'s host, upstream `{"address": "127.0.0.1:8000"}` (`LAUNCHROOM_DASHBOARD_UPSTREAM`, config `launchroom.control_plane.dashboard_upstream`), TLS `acme` (`internal` for `*.localhost`). launchroomd's host-network Caddy proxies to it with the address-upstream support ([05](05-networking-ingress.md) §4). The compose file allows exactly that upstream in launchroomd's local policy with `LR_ALLOWED_LOCAL_UPSTREAMS=127.0.0.1:${LR_DASHBOARD_PORT:-8000}`; any other non-mesh address upstream is refused ([03](03-daemon-protocol.md) §8).
- Realtime nudges use the same path: the control-plane host's daemon connects to `ws://127.0.0.1:8000/app/<key>`, which FrankenPHP proxies to Reverb; remote daemons use `wss://<APP_URL host>/app/<key>` through the edge ([03](03-daemon-protocol.md) §4). Reverb's browser WebSocket path `/app/*` takes the same proxy (`CADDY_SERVER_EXTRA_DIRECTIVES`).
- Laravel trusts `X-Forwarded-*` from private ranges (Caddy reaches the container through the Docker gateway).
- If 80/443 are taken (`--skip-port-check`), the dashboard is only reachable on `127.0.0.1:8000` (for example through an SSH tunnel). There is no public `:8000` fallback.

**Compose details**:
- Project `launchroom`, network `lr-system`. Every container and network is labelled `launchroom.managed=true` + `launchroom.role=control-plane`. json-file log rotation (10 MB × 3) per service. `restart: unless-stopped` (launchroomd: `always`).
- Healthchecks: dashboard `GET /up`, horizon `horizon:status`, reverb TCP 6001, postgres `pg_isready`, valkey `PING` (the password reaches valkey through a config file written inside the container, never argv). zot, launchroomd (distroless) and the scheduler have none.
- Zot publishes `127.0.0.1:5000` and mounts only `data/keys/registry-public` (plus its config and storage). The dashboard container mounts `data/zot/config.json` writable (`LAUNCHROOM_ZOT_CONFIG_PATH`) to render it.
- Headscale (`headscale serve`, healthcheck `headscale health`) listens on lr-system `:8080` only and publishes udp `3478` (STUN). The dashboard's FrankenPHP forwards its control and DERP paths ([05](05-networking-ingress.md) §2). Headscale stays idle until a second server joins. The dashboard containers mount `data/headscale/lib` read-only at `/data/headscale` (`LAUNCHROOM_HEADSCALE_STATE_PATH`) so control-plane backups include Headscale's state; the installer gives the dashboard's group read access to Headscale's two key files (0640). After the stack is up the installer hands the dashboard the first Headscale API key once (`headscale apikeys create` piped into `launchroom:mesh:bootstrap-key`, never printed).
- The dashboard containers get `extra_hosts: host.docker.internal:host-gateway` and `LAUNCHROOM_DASHBOARD_PROBE_ADDRESS=host.docker.internal`, the fallback target of the dashboard domain's HTTPS test (Caddy listens on the host; [05](05-networking-ingress.md) §4.1).
- dockerd-guard (D49, [09](09-security.md) §6): the launchroomd image with entrypoint `/usr/local/bin/dockerd-guard`, `network_mode: none`, `cap_drop: [ALL]`, `no-new-privileges`, read-only root, the Docker socket (`LR_DOCKER_SOCKET`) and the labelled volume `guard-socket`, healthcheck `dockerd-guard healthcheck`. The installer writes `guard/trust.json` once (a copy of `data/daemon/trust.json`).
- launchroomd: `network_mode: host`, `pid: host`, `NET_ADMIN`, `guard-socket` (`DOCKER_HOST=unix:///run/launchroom/docker.sock`, **no Docker socket**), `./data/daemon:/data`. It starts once the guard is healthy and talks to the control plane at `http://127.0.0.1:8000` (signed payloads, [03](03-daemon-protocol.md) §4.1).

**Folder layout**:
```
launchroom/
├── compose.yml              managed: replaced on upgrade (do not edit)
├── compose.override.yml     yours: optional overrides (ports, resource limits)
├── .env                     generated secrets, settings and image pins (0600)
├── launchroom               helper CLI (§2.3)
├── guard/                   trust.json (release pin for the update supervisor, D49), update/<task>/ (its verified bundle and backup)
└── data/
    ├── keys/                kek, signing key, registry token key (0400, owned by the dashboard user)
    ├── postgres/  valkey/   control-plane state
    ├── zot/                 config.json (rendered by the dashboard, 0600) + storage/ (images; with S3 storage only Zot's local metadata)
    ├── headscale/           mesh coordination
    ├── daemon/              launchroomd keys, enrollment.json, state.json, cp-key, policy.yaml, caddy config cache, tailscale state
    ├── backups/             control-plane backups (local destination), restore scratch
    └── transfers/           uploads to import and prepared downloads, encrypted chunks, short-lived (D55)
```

Minimum: 2 vCPU / 2 GB RAM for control plane, small apps and local builds. 1 GB works with a separate build server. The UI warns when resources are tight.

### 2.2 `--restore` ([14](14-backup-and-restore.md) §5.2)

`install.sh --restore [--recovery-kit FILE] [--domain HOST]` runs, after step 4, the dashboard's `php artisan launchroom:restore --keys-out=/data/backups/restore [--kit=…]` in a one-off dashboard container (attached to `/dev/tty` when there is one, else `--no-interaction`).

- **Inputs.** Destination, credentials, repository password and recovery passphrase come from the kit, from `LR_RESTORE_ENDPOINT/BUCKET/PREFIX/REGION/ACCESS_KEY_ID/SECRET_ACCESS_KEY/REPOSITORY_PASSWORD` and `LR_RECOVERY_PASSPHRASE` (passed into the container by name, never as values in argv) or from prompts.
- **Restore.** The command restores the database into the new Postgres and writes `keys/<files>` + `restored.env`. The installer:
  - copies the key files into `data/keys`;
  - merges `APP_KEY`, `APP_PREVIOUS_KEYS` and `REVERB_*` into `.env` (DB and Valkey passwords stay those of the new stack);
  - puts the restored Headscale state (`<keys-out>/headscale/*`, when the backup has it) into `data/headscale/lib` (Headscale stopped, old database files removed, owner root, 0600), so the mesh keeps its identity and nodes ([14](14-backup-and-restore.md) §9.6);
  - takes the restored `APP_URL` unless `--domain` is given;
  - deletes the scratch files and the kit copy. An `EXIT` trap also removes `data/backups/restore`, `data/backups/recovery-kit` and `.restored.env` when anything fails or the installer is interrupted, because they hold the plaintext KEK, signing keys, `APP_KEY` and the kit's S3 credentials.
- **Continue.** It continues with step 6 (migrations run on top of the restored schema) and enrolls the local launchroomd with `bootstrap-local --force --recovery`. The dead control-plane server's record is kept (no longer marked control plane) and the dashboard opens in **recovery mode**. The installer prints the `/recovery` link, where an instance owner replaces the dead server with this one (roles, labels, restores of its databases and volumes, re-placed workloads, old keys revoked), checks DNS, rebuilds images the registry lost and finishes recovery ([14](14-backup-and-restore.md) §9.15).
- Without `launchroom:restore` in the image it stops with a clear message. `--restore-allow-http` (implies `--restore`) passes `--allow-http` for a plain-http endpoint (LAN or test S3 only).
- The dashboard image ships `restic` 0.19.1 (checksum-verified) and the Postgres 18 client tools (PGDG, key pinned by fingerprint) for this and for control-plane backups. The CLI interface is specified in [14](14-backup-and-restore.md) §9.7.
- Covered by the restore scenario of `make test-install` (§2.4).

### 2.2a `--move` ([14](14-backup-and-restore.md) §13.4)

`install.sh --move <token> [--move-url URL] [--domain HOST] [--version V]` on a new server moves launchroom there from the old one. Settings → Move launchroom shows the command with the token (and `--version` of the running release).

- **`check_move_target`**: needs the token, refuses `--restore`, and refuses a folder that already holds an install unless it is a move in progress (`.move` marker or `data/backups/move/token`). A re-run continues where the move stopped.
- After the bundle, `.env`, Postgres and Valkey (steps 1 to 4), nothing else of the stack starts until the hand-over.
- **`move_join`**: `launchroom:move:receive --join` in a one-off dashboard container (the token as `LR_MOVE_TOKEN`, never argv; `LR_MOVE_HOSTNAME` = the host's name) claims the move and prints the code to compare in the old dashboard, then waits for the approval. It writes the old dashboard's signing key to `data/daemon/cp-key` and the join token (only once) to `data/daemon/join-token`, sets `LR_CONTROL_PLANE_URL` in `.env` to the old address (compose: `${LR_CONTROL_PLANE_URL:-http://127.0.0.1:${LR_DASHBOARD_PORT}}`) and starts only dockerd-guard and launchroomd, which enroll with the old dashboard as a regular server.
- **`move_handover`**: `launchroom:move:receive --handover --force` waits (it prints the old dashboard's progress) until the hand-over is ready, restores the final dump into the new Postgres and writes the key bundle to `data/backups/move/restore`. On failure it calls `--abort` (the old dashboard thaws) and stops.
- **`move_take_over`**: stops launchroomd, removes `data/daemon/control-plane.json` (an origin it may have adopted from the old dashboard, D43) and clears `LR_CONTROL_PLANE_URL`; `install_restored_files` (shared with `--restore`) puts keys, secrets and Headscale's state in place; the stack starts (migrations run on top of the restored schema); `launchroom:move:takeover` makes this server the control plane; `enroll_local_daemon` finds it enrolled and starts launchroomd against the local dashboard with the same identity; `--complete=<address>` tells the old dashboard (5 tries; on failure it stays frozen, which is harmless); `data/backups/move` (token, key pair, restored files) is deleted.
- **Address** (`restored_app_url`): `--domain` wins; else a restored domain of your own is kept, and an IP or `<ip>.sslip.io` address becomes this server's (`dashboard_url`). `--restore` keeps the restored address as before.
- **`./launchroom move-takeover`** runs the takeover by hand when the installer stopped after the restore.
- An `EXIT` trap removes `data/backups/move/restore` (plaintext keys) when the installer fails or is interrupted.

### 2.3 Helper CLI (`launchroom/launchroom`)

| Command | What |
|---|---|
| `status` | Services and the local launchroomd from the DB; prints the canonical dashboard address. |
| `logs [service]` | Compose logs. |
| `update` | Pull the pins in `.env`, `up --wait`, `migrate`. A new release means re-running the installer. |
| `backup`, `restore` | Delegate to `launchroom:backups:control-plane` and `launchroom:restore`. |
| `registry show\|apply` | Zot's config rendered from Settings → Registry with the credentials redacted, or written and zot restarted when it changed ([14](14-backup-and-restore.md) §9.11). |
| `setup-token` | Print the setup link. |
| `dashboard-domain-reset [--forget-old]` | Typed confirmation, then `launchroom:dashboard-domain:reset --force`: `APP_URL` becomes the dashboard's address again when its own domain locks you out ([05](05-networking-ingress.md) §4.1). |
| `reset-owner-2fa EMAIL` | Typed confirmation, then `launchroom:owner:reset-2fa`: clears the owner's TOTP secret and recovery codes, invalidates remember-me, audited `instance.owner_two_factor_reset`. The owner enrolls again at the next sign-in. |
| `move-takeover` | After `install.sh --move` restored the old dashboard but stopped before taking over: `launchroom:move:takeover`, drops the adopted origin and `LR_CONTROL_PLANE_URL`, restarts launchroomd (§2.2a). |
| `uninstall` | See below. |

`uninstall`:
1. Typed `uninstall` → `compose down`, a one-off `launchroomd firewall remove` (deletes launchroomd's nftables table `inet launchroom`, [05](05-networking-ingress.md) §1), then every container and network labelled `launchroom.managed=true` (launchroomd-created Caddy, BuildKit, apps) is removed.
2. Typed `delete` → volumes labelled `launchroom.managed=true`, the images those containers used, and the folder (files owned by container users are removed through a short-lived container). Without it, data stays.

### 2.4 End-to-end test (`make test-install`)

`make lint-deploy` (shellcheck, POSIX `sh`) runs first. Then `deploy/test/e2e-install.sh`:

- **Setup.** Builds the production dashboard image and launchroom-caddy from `deploy/images/caddy` (labelled `launchroom.dev=true`), starts `launchroom-dev-e2e-vps` (privileged `docker:dind` pinned by digest, its `/var/lib/docker` an anonymous labelled volume, no host ports), loads launchroom-caddy with `docker save | docker load` and builds launchroomd like a release with the digest the VPS reports (`CADDY_DIGEST`), streams the dashboard and launchroomd in, copies `deploy/` in as the bundle and runs `install.sh --bundle /opt/bundle --domain test.localhost --no-public-ip-detect` with `LR_CADDY_IMAGE` set to that image. Postgres, Valkey and Zot are pulled from the internet inside the VPS.
- **Fresh install** asserts:
  - setup link printed, no join token or `APP_KEY` in the output;
  - all services running and healthy; `.env` 0600 and the join token gone;
  - the control-plane server `online` in the DB; launchroomd applied its nftables isolation ([05](05-networking-ingress.md) §1);
  - `launchroomd system-images` pins exactly the loaded launchroom-caddy for `caddy`; the running `lr-system-caddy` is that image and lists `caddy.storage.redis` and the DNS modules; the dashboard's `system_images.caddy` is that image and `ingress.caddy_modules` is on;
  - **dockerd-guard** (D49): launchroomd's container has no Docker socket mount, the guard's has, `guard/trust.json` exists. On the guard's socket (as launchroomd calls it) a privileged container with a `/` bind mount and a Docker-socket mount are refused (403 `dockerd-guard: …`), the postgres container is "not managed by launchroom" and a container list answers 200;
  - `https://test.localhost/up` and `/setup?token=…` answer 200 **through launchroomd's Caddy** (curl with `--resolve` on the VPS's host network) and `http://` redirects with 308;
  - `/install/server.sh` is templated and passes `sh -n`;
  - a second install run keeps `APP_KEY` and the single server.
- **Dashboard domain scenario** ([05](05-networking-ingress.md) §4.1, after the owner exists):
  - the owner's actions start `dash.test-new.localhost`; horizon's checks pass (local DNS, the name in Caddy's system route with the internal CA, the HTTPS test through `host.docker.internal`);
  - the switch moves `launchroom:dashboard-url` and `./launchroom status`; `https://dash.test-new.localhost/login` is served;
  - `https://test.localhost/login` answers `308` to it, while `/daemon/v1/state` answers `401` (launchroomd's fallback, not redirected) and `/install/server.sh` still `200` (transition window); the new server.sh names the new origin; the control-plane server stays online;
  - `install.sh --domain test.localhost` makes `APP_URL` canonical again (the left domain forwards with `308`), `./launchroom dashboard-domain-reset` runs, and the switch and resets are audited.
- **Client certificates scenario** (D50, [03](03-daemon-protocol.md) §4.3):
  - `launchroom:client-certificates verify`, then `require` (allowed without `--force`: the only server is the control plane, which talks over loopback);
  - the edge Caddy then answers `GET /daemon/v1/state` without a certificate with `client certificate required`, while `enroll` and `client-certificate` still reach the dashboard (its own 401 for the unsigned request) and `/up`, `/login` answer 200;
  - a certificate of this instance's CA (issued in tinker for a key made there) passes the edge; a self-signed one from elsewhere fails the TLS handshake;
  - after 35 s the control-plane server is still online and seen; `off` restores the old answer; the three changes are audited.
- **Restore scenario** ([14](14-backup-and-restore.md) §5.2):
  - a versitygw S3 target runs as a labelled sibling container `launchroom-dev-e2e-s3` on the labelled network `launchroom-dev-e2e-net`, which the VPS joins (no host ports; endpoint `http://<its IP>:7070`);
  - the script creates the owner (with the printed setup token), a project `restore-e2e`, the recovery passphrase and the control-plane destination (private network allowed) through the same actions as the UI (`php artisan tinker` in the dashboard container), downloads the recovery kit and runs `launchroom:backups:control-plane --wait`;
  - it wipes the control plane with `launchroom uninstall` (same checks as the final uninstall) and runs `install.sh --recovery-kit FILE --restore-allow-http` with only `LR_RECOVERY_PASSPHRASE` in the environment;
  - asserts: the `/recovery` link is printed, setup is not offered again, neither the passphrase, the S3 secret nor an `APP_KEY` appears in the output; all services healthy; `APP_KEY`, the control-plane signing key (launchroomd's pinned `cp-key`), the owner and the project are back; the local launchroomd enrolled as a new server and is `online`; the previous control-plane server is kept (not control plane, not revoked) and `recovery_state` points at it; `/up` and `/login` answer 200 through the new launchroomd's Caddy; a control-plane backup after the restore succeeds (the restored KEK opens the destination credentials).
- **Uninstall**: `launchroom uninstall` leaves no labelled containers, networks or volumes, no folder and no containers at all. Then the S3 container and network are removed.

The VPS is removed afterwards (`KEEP=1` keeps it; `SKIP_BUILD=1` reuses the built dashboard and caddy images; launchroomd is always rebuilt, from the cache, for the digest the VPS reports).

### 2.4a End-to-end move test (`make test-move`)

`deploy/test/e2e-move.sh` runs both moves of [14](14-backup-and-restore.md) §13 against fresh dind VPSes (labelled `launchroom.dev=true`, named `launchroom-dev-e2e-move-*`), under the heavy lock: the control-plane move with an app, a Postgres database and a volume (data written right before the move must arrive), and a server move between two servers that don't run the dashboard.

- **Setup.** One labelled network with static addresses. Each control-plane VPS gets a `compose.override.yml` before `install.sh` runs (the installer never touches it): the dashboard also on `:8001` (plain http for the other VPSes; every payload is signed), Headscale on `:8081` with `server_url: http://hs.e2e-move`, `LAUNCHROOM_MESH_LOGIN_SERVER=http://hs.e2e-move`, `LR_MESH_INSECURE_LOGIN_HOSTS=hs.e2e-move` and `LAUNCHROOM_LOCALHOST_DOMAINS=true` (generated app domains on `*.localhost`, served with Caddy's internal CA). Tailscale only talks plain http to a login server on port 80, so a labelled socat container with the alias `hs.e2e-move` forwards to the control plane's Headscale: switching it after Move 1 is the DNS change of a real move. The app is `nginx-unprivileged` with a volume at `/usr/share/nginx/html/data` (nginx's own page answers the health check).
- **Move 1** (control plane): install on A (`dash.move.localhost`), owner, project with Postgres + the app (connected), 3 rows and a file; `StartControlPlaneMove` (tinker, the same action as the UI); `install.sh --move <token> --move-url http://<A>:8001 --domain dash.move.localhost` on B in the background; the code B prints must equal A's; `ApproveControlPlaneMove`; B joins, online on the private network; a 4th row and a new file; `MoveControlPlaneWorkloads` until `ready_to_hand_over`; B's edge serves the new file and B's Postgres has 4 rows; a dashboard change (a project); `StartHandover`; B's installer exits 0 without secrets in its output. Asserted on B: all services healthy, `https://dash.move.localhost/login` through B's Caddy, both projects in B's database, the move `handed_over`, B's server the control plane and online over loopback (`LR_CONTROL_PLANE_URL` empty), A's server retired, no freeze, a checklist; the app serves the newest file and Postgres has every row in order. On A: `moved_to` set, no `lr-system-caddy`, no workloads.
- **Move 2** (regular server): hs.e2e-move → B, A deleted; C (runtime) and D join B with the dashboard's `server.sh` (`LR_BUNDLE_DIR`, a daemon `compose.override.yml` for the insecure login host); a project allow-listed to C with Postgres + the app, rows and a file on C; a row right before the move; `StartServerMove` C → D until `ready`; D has every row, B's edge serves the file (the allow-list naming C stands for D); `RetireMovedServer`: C revoked and running no workloads.
- **Result (2026-10-21):** PASS. Move 1: apps and data moved in 49 s with the longest stop of a stateful service 31 s; the hand-over (freeze, final dump, restore, start, takeover) took 34 s. Move 2: 42 s. The runs found and fixed: the registry forwarder started after the first reconcile on a server that just got its first workloads (pulls raced it), and launchroomd's volume lookup refused owner-less prepared volumes.
- `KEEP=1` keeps the VPSes, `SKIP_BUILD=1` reuses the dashboard and caddy images; launchroomd is always rebuilt.

### 2.5 Releases and signing

- **`VERSION`** is the single source of truth for the release version: tag `v$(cat VERSION)`, the images' `VERSION` build arg (launchroomd `-X main.version`, dashboard `LAUNCHROOM_VERSION` → `config('launchroom.version')`), the bundle's `VERSION` file. `make dev-daemon` builds `<VERSION>-dev`.
- **Images.** `.github/workflows/release.yml` builds and signs `launchroom-caddy` first (job `caddy`), then `launchroom-dashboard` and `launchroomd` (job `images`) with the build arg `CADDY_DIGEST` = launchroom-caddy's multi-arch index digest. launchroomd compiles it into its system-image allow-list ([05](05-networking-ingress.md) §4); before signing, the workflow runs `launchroomd system-images` for both platforms and fails unless it prints `caddy=<that digest>`.
- **`deploy/release/build-bundle.sh`** builds the release bundle (deterministic tarball, `images.env` with digests; `--dashboard-image`, `--daemon-image` and `--caddy-image` are all required and digest-pinned). `.github/workflows/release.yml` runs it, signs the bundle keylessly with cosign and publishes `launchroom-deploy.tar.gz`, `.sha256` and `.sigstore.json`. The installers and launchroomd verify it as in §2.1 step 2 and §4.2.
- **`deploy/release/check-pinned-images.sh`** fails if a release Dockerfile (`daemon/Dockerfile`, `deploy/images/*/Dockerfile`) has a `# syntax=` frontend or `FROM` base image not pinned by `@sha256` (a stage name or an `ARG` with a digest-pinned default is fine). Run by `make lint-deploy` and CI ([09](09-security.md) R31). Bumping a base image means a new tag and digest in the same line.
- **Who can sign a release.** The pinned signing identity names the tag (`release.yml@refs/tags/v<version>`), not the branch, so the repository must make sure only maintainers can produce such a tag from reviewed code:
  1. a **tag ruleset** on `refs/tags/v*` (Settings → Rules → Rulesets: restrict creation, update and deletion to the maintainers or release team, no bypass for others);
  2. a protected **environment `release`** (deployment rule "tags matching `v*`", required reviewer), which the signing jobs (`images`, `bundle`) run in, so no OIDC token for signing is issued without a reviewer's approval;
  3. the `verify` job refuses a tag whose commit is not on the default branch (`git merge-base --is-ancestor`).

  Layer 3 lives in the workflow file of the tagged commit, so on its own it doesn't stop someone who can push tags *and* edit `release.yml` on a branch. Layers 1 and 2 are repository settings and do.

## 3. Server install

`GET /install/server.sh` (`routes/install.php`, `ServerInstallScriptController` → `RenderServerInstallScript`; public, no session, `throttle:30,1`, `Cache-Control: no-store`) serves `deploy/install/server.sh` with `deploy/daemon/compose.yml` embedded in a quoted heredoc and these values filled in:

- `__LAUNCHROOM_URL__` (`APP_URL`), `__LAUNCHROOM_CP_KEY__` (the public signing key), `__LAUNCHROOM_DAEMON_IMAGE__` (`LAUNCHROOM_DAEMON_IMAGE`), `__LAUNCHROOM_VERSION__` (`config('launchroom.version')`).
- Every inserted value is grammar-checked (URL without path or quotes, `ed25519:<base64>`, image reference, semver). Otherwise, or before the signing key exists, the route answers 503 with a script that only exits 1. It never contains a join token.
- The production image ships both files in `/var/www/install` (`launchroom.install.scripts_path`; the dev stack mounts them there).

The script:
1. Runs the same checks as the installer (minus ports).
2. Reads `LR_JOIN_TOKEN` from the environment or a hidden prompt on `/dev/tty` (never argv). `LR_CONTROL_PLANE_URL`, `LR_CP_KEY` and `LR_DAEMON_IMAGE` from the environment override the templated values.
3. Unless `LR_BUNDLE_DIR` names an unpacked bundle, downloads the signed bundle of the dashboard's version (`LR_RELEASE_VERSION`), verifies it exactly like `install.sh`, uses its `daemon/compose.yml`, and requires the dashboard's daemon image to equal the bundle's `LR_DAEMON_IMAGE`. Without a signed release (development builds) it stops unless `LR_ALLOW_UNSIGNED=1`, which falls back to the embedded compose file unverified.
4. Writes `compose.yml`, `.env` (control-plane URL, pinned CP signing key, daemon image), `data/join-token` (0600, `LR_JOIN_TOKEN_FILE`), `data/trust.json` once (same identity as `install.sh`) and `guard/trust.json` (the release pin the update supervisor re-verifies against, outside launchroomd's data).
5. `docker compose up -d`, waits for `data/enrollment.json`, then deletes the token and restarts launchroomd without it. Re-running on an enrolled server only updates and restarts.

The server folder (`launchroomd/`) runs two services: `dockerd-guard` with the Docker socket, no network and no capabilities, and launchroomd with `network_mode: host`, `pid: host` (port discovery), `cap_add: [NET_ADMIN]`, the guard's socket volume and `./data` ([03](03-daemon-protocol.md) §2, [09](09-security.md) §6). launchroomd enrolls, joins the mesh if needed (it starts the Tailscale container itself), and starts the system containers its roles need (Caddy for edge, BuildKit for build).

Uninstall on a server is manual (documented on the site under [launchroomd](https://launchroom.vantezzen.io/docs/how-it-works/launchroomd#uninstalling-a-server)): `docker compose down`, `docker compose run --rm launchroomd firewall remove`, remove every container labelled `launchroom.managed=true`, delete the folder.

### 3.1 Changing roles

Changing roles never requires touching the server. System containers are part of the NodeState, so launchroomd adds or removes them ([02](02-architecture.md) §2.2).

### 3.2 Rootless Docker hosts (D49)

**Detection and checks.** `server.sh` detects rootless Docker (`docker info` → `name=rootless`) and refuses to install without `--rootless` (and refuses `--rootless` on a rootful daemon). With the flag it checks and records, without changing anything outside the install folder:

- the user's Docker socket (`$DOCKER_HOST` or `$XDG_RUNTIME_DIR/docker.sock`) → `LR_DOCKER_SOCKET` (mounted into dockerd-guard only);
- RootlessKit's API socket (`$XDG_RUNTIME_DIR/dockerd-rootless/api.sock`, override `LR_ROOTLESSKIT_API`) → handed to dockerd-guard through the managed `compose.rootless.yml` (`COMPOSE_FILE=compose.yml:compose.rootless.yml` in `.env`);
- `net.ipv4.ip_unprivileged_port_start` ≤ 80, else it stops and prints the one-time root command;
- `/dev/net/tun` (warns: without it the server cannot join the private network);
- cgroups (warns when `docker info` reports the cgroup driver `none`: rootless Docker then discards CPU, memory and pids limits, measured in `docker:29-dind-rootless`; cgroup v2 with systemd delegation is required for the policy floor's limits to take effect).

**How it runs.** With rootless Docker, `network_mode: host` is RootlessKit's network namespace, not the server's. launchroomd, Caddy and the mesh client all run host-network there, so everything that relies on loopback and container IPs (Caddy's admin API and `ask`, the access log, `127.0.0.1:5000`, health probes, the mesh IP) works unchanged. What does not reach the namespace by itself is the outside world: dockerd-guard exposes **exactly** 80/tcp, 443/tcp and 443/udp from the host into the namespace through RootlessKit's port API (the mechanism `docker run -p` uses on rootless hosts; guard endpoint `POST /launchroom/guard/v1/edge-ports`, idempotent). launchroomd asks for them at start and every 30 s, because RootlessKit forgets them when the rootless daemon restarts.

**Capability matrix.** launchroomd reports `Report.host.runtime` (`docker: rootful|rootless`, `guard`, `capabilities[]`), shown on the server page (Settings → Network → Capabilities). Measured in `docker:29-dind-rootless`:

| Capability | Rootful + guard | Rootless |
|---|---|---|
| Docker access | full (through dockerd-guard) | full (through dockerd-guard; a breakout lands as the unprivileged user) |
| Public ports 80/443 | full | full when RootlessKit exposed them; degraded or unavailable with the reason otherwise (unprivileged port range, no API socket) |
| Visitor addresses | full | **degraded**: RootlessKit's builtin port driver forwards connections, apps and access logs see an internal address |
| Network isolation | full (nftables `inet launchroom`) | **degraded**: launchroomd's nftables table applies inside the namespace (enforced, environment bridges covered), which is where container traffic flows; the server's own interfaces are outside it (RootlessKit's slirp4netns with host loopback disabled separates them) |
| Private network | full (kernel/TUN mode) | **degraded**: the mesh client runs in kernel/TUN mode inside the namespace (`tailscaled --tun=tailscale0` starts with launchroomd's spec through the guard), so the mesh IP and mesh-published ports work; its UDP is NATed by slirp4netns, so peers connect through relays unless they can reach it directly |
| Port detection | full | full (`/proc/<pid>/net` readable, the app's port detected) |

**Alternatives considered.**
- Caddy on a bridge network with published ports (`-p 80:80`) would get the forwarding from Docker itself, but Caddy could no longer reach container IPs on other environment bridges (Docker isolates bridges), so every route would need the ambassador.
- A userspace-networking mesh (`tailscale --tun=userspace-networking`) would remove the mesh IP launchroomd binds and the ambassador publishes on.

Both are rejected because the namespace keeps the rootful design working.

**Podman's Docker API** is not supported: launchroom is not tested against it (networks with gateway priorities, hijacked exec streams, archive uploads and RootlessKit's port API all differ or are missing). `server.sh` does not detect it as rootless Docker.

Covered by `daemon/test/integration/rootless_test.go`.

### 3.3 macOS servers (D53)

The core case: a Mac at home (a Mac mini, a MacBook) joins next to a VPS as a **build server**, and with OrbStack or Colima can run apps too. On macOS Docker runs inside a Linux VM, so launchroomd (a container) runs inside that VM's Docker: the VM is launchroomd's "host".

**What `server.sh` does on Darwin** (`uname -s` = `Darwin`; still POSIX sh, unit-tested by `make test-server-sh` with stubbed `uname`, `docker`, `system_profiler` and `sysctl` in busybox):

- **Engine.** `docker version` must answer (else: "Start OrbStack, Docker Desktop or Colima"); `docker info` names the engine: `OperatingSystem` `Docker Desktop` or `OrbStack`, or the VM name `colima`/`colima-<profile>`. Anything else (Rancher Desktop, Podman, plain lima) is refused with the list of supported engines. The docker CLI's endpoint (`$DOCKER_HOST`, else `docker context inspect`) must be a local `unix://` socket (`~/.orbstack/run/docker.sock`, `~/.docker/run/docker.sock`, `~/.colima/<profile>/docker.sock`); a `tcp://`/`ssh://` endpoint would install onto another machine and is refused. The VM must be `x86_64` or `aarch64`; rootless engines and `--rootless` are refused.
- **Folder: `~/launchroom`** (`LAUNCHROOM_DAEMON_DIR` overrides). Chosen over `/usr/local/launchroom`: every engine shares the home folder with its VM by default (Docker Desktop: `/Users`, `/Volumes`, `/private`, `/tmp`, `/var/folders`; OrbStack: everything; **Colima: only `~`**), so the bind mounts (`./data`, the guard's trust pin, the update supervisor's install folder) resolve inside the VM at the same path; it needs no sudo; `/usr/local` belongs to Homebrew on Intel Macs. Outside the home folder Colima is refused and the others get a warning to check file sharing.
- **Settings** in `.env`: `LR_HOST_OS=darwin`, `LR_HOST_MODEL` (`system_profiler -detailLevel mini SPHardwareDataType` "Model Name", which leaves out serials and UUIDs; fallback `sysctl -n hw.model`; grammar-checked, else empty). `LR_DOCKER_SOCKET` stays unset: dockerd-guard mounts `/var/run/docker.sock` **as the VM sees it** (Docker Desktop and OrbStack map that path to the engine, inside Colima's VM it is the engine's socket), never the Mac-side CLI socket, which isn't a socket inside the VM. The rootless override is never written.
- **Nothing else on the host**: no launchd jobs. The containers come back through Docker's `restart: always` once the engine runs; the script tells the user to keep the engine's start-at-login on (OrbStack → Settings → Start at login; Docker Desktop → "Start Docker Desktop when you sign in"; Colima: `brew services start colima`) and to stop the Mac from sleeping (a sleeping Mac is unreachable; builds waiting for it go to another builder).
- **Self-update** runs `server.sh` in the update supervisor's helper container, i.e. on Linux inside the VM: the Linux branch keeps the Darwin settings (it only sets its own keys) and no longer clears `LR_HOST_TIMEZONE` when it can't see the host's zone.

**What launchroomd does.** `LR_HOST_OS` (else: darwin for OrbStack, which only exists on macOS) and the engine from `docker info` (`docker.EngineKindOf`: OS name, then Colima's VM name; never the kernel, because a dind container on OrbStack runs OrbStack's kernel) go into `Report.host.runtime` as `hostOs` and `engine`; `EnrollRequest.host.os` is `darwin`, so the dashboard knows at enrollment. `LR_HOST_MODEL` becomes `hardware.hostModel` (device art: Mac mini/Studio/iMac compact, MacBook laptop, Mac Pro tower). The hardware probe names Docker Desktop/OrbStack VMs before its container heuristic (with `pid: host`, OrbStack's PID 1 is its `simplevisor`, which read as "a container"). `buildPlatforms` is what the running buildkitd reports for its workers (asked once per container).

**Capability matrix** (Settings → Network → Capabilities; reasoning below, not yet measured on a real Mac):

| Capability | Docker Engine (Linux) | OrbStack / Colima on a Mac | Docker Desktop on a Mac |
|---|---|---|---|
| Docker access | full (guard) | full: the guard owns the VM's socket | full (Enhanced Container Isolation, a Business setting, must allow the socket mount) |
| Public ports 80/443 | full | **unavailable**: 80/443 open inside the VM, not on the network (OrbStack/Colima forward to the Mac's localhost at most); a home Mac has no public address anyway | **unavailable**, same |
| Visitor addresses | full | **degraded**: the engine's port forwarding | **degraded** |
| Network isolation | full | full, inside the VM where every container is; the Mac's own firewall is untouched. The VM's ways to the Mac (`host.docker.internal`: Docker Desktop `192.168.65.254`, OrbStack `0.250.250.254`, lima's `192.168.5.2`) are private/`0.0.0.0/8` addresses the `egress` chain already drops, so apps can't reach services on the Mac | same; kernel nftables present in all three VMs (otherwise `error` with the reason, and builds stop: fail closed) |
| Private network | full | **degraded**: kernel/TUN mode works inside the VM (`/dev/net/tun` exists in all three), but its UDP leaves through the VM's NAT, so peers usually connect through the DERP relay | **degraded**, same |
| Port detection | full | full (`pid: host` is the VM's PID namespace, where the containers are) | full |
| Apps (`workloads`) | full | full: mesh-published ports bind to the VM's `tailscale0` address | **unavailable**: Docker Desktop opens every published port on the Mac too, and binding the mesh address (which only exists inside the VM) fails there, so a provider replica never starts |

Also inside the VM: cgroup v2 limits (enforced, against the VM's CPUs and memory, which is the capacity launchroomd reports: Docker Desktop's default is half the Mac's memory, set it in the engine), Docker's disk (the VM's disk image), BuildKit (unchanged; its memory cap is ¾ of the VM's). `./data` (with build checkouts) lives on the Mac's disk through the engine's file sharing (virtiofs): correct, somewhat slower than the VM's disk.

**Roles** (`App\Support\Servers\HostRoleLimits`): build on every Mac; runtime on OrbStack and Colima, not on Docker Desktop (`workloads` unavailable); **never edge**: the VM's 80/443 are not the Mac's, the Mac is usually behind NAT, and an edge that sleeps takes the domains with it. Enforced when the server enrolls (roles it can't take are dropped, audited as `roles_dropped`; build stays), when roles change (refused with the reason, shown on the role card), and by placement (a role the machine can't take doesn't place). The add-server wizard has a **Mac** machine (build, edge disabled) and a **macOS** command tab.

**Images and platforms.** The launchroomd, Caddy, BuildKit and Tailscale images are multi-arch (release builds `linux/amd64,linux/arm64`; system images are pinned by index digest), so an Apple Silicon Mac runs arm64 everywhere. Builds target the platform of the runtime servers ([04](04-build-and-deploy.md) §5.1); a builder counts for its own platform **and the ones its engine emulates**: Docker Desktop registers QEMU binfmt handlers, OrbStack Rosetta (both on by default), Colima depending on its VM settings (`--vz-rosetta`, or the binfmt package of its image). BuildKit detects them by running a test binary per architecture at start and lists them in its worker platforms; launchroomd reports them as `buildPlatforms`. launchroom never installs binfmt handlers itself (that needs a privileged container). So an arm64 Mac builds `linux/amd64` images for an x86 VPS (the frontend and BuildKit run natively, `RUN` steps emulated: correct but several times slower, Rosetta much less so). A native builder of the platform is preferred; to make the Mac the builder, remove the build role from the VPS.

**Not yet verified on a real Mac.** The matrix is reasoned from how each engine works; the tests stub the engine (`make test-server-sh`) or run against Linux dind. A manual run per engine should check: install, the capability matrix and device art, an amd64 build for an x86 VPS, an app consumed from the VPS (OrbStack, Colima), launchroomd coming back after a reboot, uninstall.

## 4. Updates

The user flow is on the site ([Updates](https://launchroom.vantezzen.io/docs/operations/updates)). Summary:

- **Guided flow.** "Update now" runs pre-update backup → control plane → (opt-out checkbox, on by default) every server.
- **Servers**: rolling `self_update` tasks, one server at a time, edges last. Signatures are verified against the identity pinned at install, with no downgrades. The dashboard supports daemon protocol N-1.
- **System images** (Caddy, BuildKit, Tailscale, Zot, Headscale) are pinned by digest per release and rolled out through NodeState. A server that reports a new launchroomd version gets its NodeState re-rendered with the dashboard's images. The edge Caddy changes digest with every release; an edge whose launchroomd is not updated yet keeps its pinned Caddy until it is ([05](05-networking-ingress.md) §4).
- **Manual path**: re-running the installer with `--dir` (new `compose.yml` and pins, `.env` and `data` kept); `./launchroom update` re-pulls the pins already in `.env`. Neither takes a pre-upgrade backup nor rolls back.

### 4.1 Dashboard side

- **Release check.** Settings → Updates (`/settings/updates`, instance owners, recently confirmed password for mutations) checks the GitHub Releases API of `launchroom.updates.repository` through `SafeHttpClient` (cached 1 h) and reads the release's `images.env` asset (one redirect, GitHub storage hosts only; only digest-pinned `repo@sha256:` refs count). The changelog is the release body, shown as plain text.
- **Channel**: `stable` (default, skips prereleases) or `beta` (includes them), chosen in Settings → Updates (`instance_settings.update_channel`; `LAUNCHROOM_UPDATES_CHANNEL` is only the default).
- **Control-plane update.** "Update" creates a `control_plane_updates` row (at most one active) and a queued job:
  - with a control-plane backup destination it first runs the pre-upgrade backup synchronously (as `launchroom:backups:control-plane --reason=pre_upgrade --wait`) and stops on failure before anything changes;
  - without a destination the owner must explicitly accept updating without an off-site backup (`skip_backup`; the local `pg_dump` of §4.2 still happens);
  - then a `self_update` task with `scope: control_plane` is pinned to the control-plane server (3 attempts, 45 min).
- **Outcome.** Recorded when the task finishes (listener on `DaemonTaskFinished`) and by `launchroom:updates:advance` every minute, because the dashboard restarts mid-update: succeeded → `succeeded`, failed with `selfUpdate.rolledBack` → `rolled_back`, otherwise `failed`.
- **Server rollout.** "Update N servers" rolls launchroomd to the dashboard's own version (`LAUNCHROOM_VERSION`, image `LAUNCHROOM_DAEMON_IMAGE`) with `fleet_updates` / `fleet_update_steps`: one `self_update` task (scope `daemon`, 20 min, 3 attempts) at a time, servers without the edge role first and edges last, the control-plane server excluded, servers at or above the version left out (no downgrades), stopping at the first failure (remaining steps `skipped`). In the guided flow the **new** dashboard starts it once it runs the target version (`AdvanceUpdates`, `control_plane_updates.update_servers` / `fleet_update_id`, at most once, within a day; nothing to roll out drops the flag).
- **Automatic updates** (`instance_settings.auto_update`, opt-in, off by default; remembered with the instance owner who turned them on as the actor; audited `instance.update_preferences_changed`). `launchroom:updates:auto` (`RunAutomaticUpdate`) checks hourly and starts the same flow with `automatic = true` only:
  - inside the night window (`launchroom.updates.auto_window`, 02–05 in the control-plane server's time zone from its hardware facts, else UTC);
  - with a control-plane backup destination (never without an off-site backup; turning them on is refused without one);
  - for a release never tried before (a failed or rolled-back version waits for a person);
  - when nothing else runs, outside recovery mode, and while that owner is still an instance owner.
- **Failure display.** A failed update shows "What happened" (the same step list) and what to do: rolled back → nothing to fix, dashboard changes made during the update window were undone; backup failed → nothing changed, check Settings → Backups; other failures → `./launchroom status` and the pre-update backup.
- Starts and ends are audited. Daemon requests must share a protocol version with `launchroom.daemon.protocol_versions` (current and N-1), otherwise 426 ([03](03-daemon-protocol.md) §10).

### 4.2 launchroomd side (`daemon/internal/update`)

The `self_update` task carries only `version`, `image` and `scope`. The handler:
1. completes at once from `data/update/tasks/<task>/status.json` if this task already finished (re-leased attempt);
2. refuses a `version` not strictly newer than its own (semver; `-dev` builds sort before the release);
3. loads `trust.json` (missing → refused: re-run the installer once, which pins the identity);
4. finds its own container (the compose container whose PID is launchroomd's; `pid: host`) and refuses `daemon` scope on the control-plane host (role label `control-plane`; its launchroomd is updated with the control plane) and `control_plane` scope elsewhere;
5. downloads `launchroom-deploy.tar.gz` + `.sigstore.json` + `.sha256` of that version from the pinned release base, verifies with sigstore-go (§2.1 identity; key mode for tests), checks the checksum, that the bundle's `VERSION` is the version and that its `LR_DAEMON_IMAGE` equals `image`;
6. extracts it below `data/update/tasks/<task>/bundle` and pulls every pinned image (by digest) **before changing anything**;
7. writes `plan.json` (host paths) and `data/update/pending.json`, then starts the **supervisor**: a short-lived container `lr-system-update-<task>` (labels `launchroom.managed=true`, `launchroom.role=updater`, no network) from launchroomd's **current** image (`launchroomd update-supervisor <plan>`), with the Docker socket and the install folder mounted at its own host path.

The handler keeps heartbeating (`progress` lines) until the supervisor exits or launchroomd is stopped (then the runner does not complete the attempt: hand-off).

**Supervisor**:
- Backs up `compose.yml` and `.env` (control plane also `launchroom` and `data/zot/config.json`) with owner and mode. Control plane only: `pg_dump -Fc` into `data/postgres/launchroom-pre-upgrade.dump`.
- Runs the verified bundle's own installer (re-running the installer is the upgrade path, §2.1): `install.sh --bundle … --dir … --skip-port-check --no-public-ip-detect`, or `server.sh` with `LR_BUNDLE_DIR`. It runs in a helper container of the bundle's digest-pinned docker CLI image (`LR_COMPOSE_IMAGE`, argv only, as the install folder's owner with the socket's group, no network). Helper containers run compose with plain progress (`COMPOSE_PROGRESS=plain`, `COMPOSE_ANSI=never`), and error tails are stripped of terminal codes, so task errors in the UI are readable.
- Health: the new launchroomd writes `data/update/running.json` (version, start) after its first verified state fetch. The supervisor waits for that version (3 min daemon, 10 min control plane; the control-plane installer has already waited for the compose healthchecks, including migrations in the dashboard entrypoint).
- On failure it **rolls back**: restores the files; for the control plane stops the app services and restores the database from the dump (`dropdb` / `createdb` / `pg_restore`, losing writes made during the update window); `docker compose up -d` (`--wait` for the control plane); waits for the old version's `running.json`. When the installer failed before replacing launchroomd (the control-plane installer starts launchroomd last), the old process never rewrites `running.json`; the supervisor accepts it when its container still runs since before the update (`Plan.daemonContainer`).
- The verdict (`succeeded`, `rolled_back`, `failed`) goes to `status.json`. The launchroomd process that runs afterwards (new, or old after a rollback) reports it (`Resume`, after its first verified exchange) and removes the supervisor. If the lease was lost meanwhile, the dashboard re-leases the task and the handler answers from `status.json`.

### 4.3 Update tests

- **`make test-update`** (`deploy/test/e2e-update.sh`). A dind VPS gets three releases built from the local images (1.0.0, 1.1.0, a broken 1.2.0 whose dashboard never becomes healthy), pushed to a registry inside the VPS so `build-bundle.sh` pins them by digest, signed with a throwaway key (`daemon/test/releasesign`, key-based trust) and served from a release base inside the VPS. Each release has its own edge Caddy image (stock `caddy:2.11.7` plus a version label, so its own digest), compiled into its launchroomd (`CADDY_DIGEST`) and put into its bundle (`--caddy-image`). After installing 1.0.0, the edge runs the 1.0.0 Caddy and `trust.json` is pointed at that key and base. The update is started like "Update now" (GitHub's API faked only in the starting tinker process); then horizon, the scheduler, launchroomd, its supervisor and the real `install.sh` run for real:
  - 1.0.0 → 1.1.0 with a pre-upgrade backup to S3 (versitygw) succeeds in about 75 s and is recorded by the new dashboard (data kept, dump and supervisor removed, audited, served through Caddy, the edge Caddy moved to the 1.1.0 pin);
  - 1.1.0 → 1.2.0 is rolled back (files and database restored, 1.1.0 healthy with its edge Caddy, the earlier update still recorded).
- **`make test-daemon-integration ITEST=release`** (dind, locally built fake launchroomd images in a local registry, releases signed with a throwaway key, real `server.sh`, real supervisor and handler):
  - daemon 1.0.0 → 1.1.0 swap; downgrade, reinstall and foreign-signature refusals; a never-healthy 1.2.0 rolled back to 1.1.0 with `.env` restored; re-leased attempt answered from the status;
  - control-plane scope with real Postgres: a failed update's migration is undone by the dump restore, a good one keeps it and removes the dump.
- Keyless verification is unit-tested with a recorded real GitHub Actions bundle (goreleaser v2.18.3) and the embedded trusted root. The real release identity can only be exercised by a real tagged release.

## 5. Backup & restore of the control plane

Full design: [14-backup-and-restore.md](14-backup-and-restore.md). In short:
- Control-plane snapshots (DB, `.env`, keys encrypted with the recovery passphrase, Headscale state) are taken every 6 h and before every upgrade, and stored in an S3-compatible destination through restic.
- `install.sh --restore` on a fresh server restores from S3 using the recovery kit (§2.2), then opens the dashboard in recovery mode (`/recovery`, instance owners). Recovery mode replaces the dead server (the same "Replace server" flow as for any other dead server: roles and labels move, old keys revoked), restores its databases and volumes from their latest backups, re-places its workloads, rebuilds images that are no longer in the registry and checks DNS ([14](14-backup-and-restore.md) §9.14–9.15).
- Daemons on other servers keep running throughout and reconnect by themselves when the dashboard URL is unchanged.

## 6. Local development environment

Goal: develop launchroom on a laptop **without launchroom taking over the laptop's Docker**, with realistic deployments, and optionally a multi-server setup. `make help` lists every task.

```
dev/
├── compose.yml              core dev stack (project `launchroom-dev`)
├── compose.multi.yml        adds Headscale (dev/headscale/config.yaml), server-2, server-3, nat-router and loadgen
├── .env.example             ports etc.; copied to the git-ignored dev/.env on first `make up`
├── dind/                    "fake server" image: docker:dind + daemon runner (restarts launchroomd when its binary changes)
├── go/                      Go toolchain image for make targets (no Go on the host)
├── dashboard/               helper scripts mounted into the dashboard dev containers
├── postgres/init/           creates the `launchroom_test` database
├── zot/config.json          dev registry config
├── nat-router/              CGNAT simulation for server-3
├── failover-test/           the failover test (§8)
└── fixtures/                small sample apps (see dev/fixtures/README.md), served by `git-fixtures`
```

**Core stack** (`make up`):

| Service | What | Host port |
|---|---|---|
| `dashboard` | PHP 8.4 FrankenPHP **dev image** (`deploy/images/dashboard/Dockerfile`, target `dev`; classic mode, no Octane/worker mode), `dashboard/` mounted, serves `public/` on :8000 | 8000 |
| `vite` | `npm run dev` (Vite + HMR) in the same image (the wayfinder plugin needs PHP). Uses a Linux-only `node_modules` volume that shadows the host's | 5173 |
| `horizon`, `reverb`, `scheduler` | Same image, different commands. No file watching: `make restart-workers` after PHP changes. horizon and reverb wait instead of crash-looping until their package is installed | Reverb 6001 |
| `postgres`, `valkey` | Control-plane state. Postgres also holds `launchroom_test` for the dashboard tests | Postgres 54320 (for a DB GUI); Valkey internal |
| `zot` | Registry at `http://zot:5000` with the production config shape (`dev/zot/config.json`): bearer auth with dashboard-issued tokens (realm `http://dashboard:8000/registry/token`, service `launchroom-registry`, public key from the `keys` volume), on-demand mirroring of Docker Hub and ghcr.io under `mirror/`, retention for `apps/**`, `cache/**` and `mirror/**` ([05](05-networking-ingress.md) §6). `make up` creates the registry token key | (internal) |
| `mailpit` | Mail testing | 8025 |
| `versitygw` | Dev S3 target for backups, profile `backups` (`make up-backups`), `http://versitygw:7070`; server-1 and server-2 allow it in `LR_BACKUP_INSECURE_HOSTS` ([14](14-backup-and-restore.md) §9.10) | (internal) |
| `server-1` | **Fake server**: `docker:dind` (privileged, its own Docker daemon and image store) running launchroomd with roles edge+runtime+build. `make dev-daemon` builds launchroomd (in the `go` tools container) into a shared volume; the fake server restarts launchroomd when the binary changes. Its Caddy publishes 80/443 inside dind, mapped to host **8080/8443**. It forwards `127.0.0.1:5000` to `zot:5000` (`LR_REGISTRY_UPSTREAM`) | 8080, 8443 |

**Containment**:
- Everything is in Docker, nothing is installed on the host. All containers, networks, volumes and built images are prefixed `launchroom-dev` and labelled `launchroom.dev=true`. Host ports bind to **127.0.0.1 only**. Only the dind fake servers are privileged.
- Deployed test apps live **inside `server-1`'s nested Docker** (state in its named volume). The laptop's own `docker ps` shows only the dev stack, and `make nuke` deletes everything launchroom-dev (selected by label and project, never a global prune).
- Dashboard tooling (composer, artisan, npm, tests) runs inside the dev containers. Tests use the stack's Postgres (`launchroom_test` or `launchroom_test_<name>`), never the dev database; full runs are parallel and give each worker its own `<database>_test_<n>` copy.
- Ports are configurable via `dev/.env` to avoid clashes with the developer's own services.

**First run**: `make up`, `make dev-setup-token`, open the printed `/setup?token=…` URL, create the owner (two-factor is recommended from Get started, not required: D33), then `make dev-enroll`. `dev-enroll`:
- runs `launchroom:keys:generate` (KEK + signing key in the `keys` volume) and `launchroom:servers:bootstrap-local` (a control-plane join token for roles edge+runtime+build, JSON output);
- writes the CP public key into `dev/.env` as `LR_DEV_CP_KEY` (compose passes it as `LR_CP_KEY`);
- pipes the token into `server-1:/data/join-token` over stdin and recreates `server-1`.

It is idempotent: while the control-plane server is enrolled it only re-pins the key.

`make seed` creates a demo team, project, environments and fake deployments for UI work without real builds.

**Apps, TLS and git**:
- App URLs use **`*.localhost`** (browsers resolve these to 127.0.0.1 without DNS), for example `https://web-production.shop.localhost:8443`. Caddy uses its internal CA, and `make trust-ca` optionally installs it into the laptop trust store (an explicit opt-in step).
- Fixtures from `dev/fixtures/` are served by the dev-only `git-fixtures` service (git smart HTTP, `http://git-fixtures:8080/<name>.git`, `make fixtures` lists SHAs). It rescans `dev/fixtures/` before every index request and before every clone or fetch, so added, edited or deleted fixtures are served without a restart (commits are deterministic: unchanged content keeps its SHA). `make fixtures` also rebuilds the service when its own code changed. server-1 may clone them over plain http (`LR_BUILD_INSECURE_GIT_HOSTS=git-fixtures`) with a `git` repo provider. Neither exists in production.
- Webhooks: without a tunnel, the **polling fallback** ([06](06-variables-environments.md) §2) applies. The git providers e2e uses a dev **Forgejo** (profile `forgejo`: `make up-forgejo`, `http://127.0.0.1:3300`, `make test-git-e2e`; the servers also allow `forgejo` for plain http clones and the dashboard tells Forgejo to deliver webhooks to `http://dashboard:8000`, [19](19-git-providers.md) §8).
- **A real GitHub App in dev**: with the default `APP_URL=http://localhost:8000` the manifest flow works end to end in the browser (GitHub redirects back to localhost), but github.com refuses a non-public webhook URL, so launchroom leaves the webhook out (polling only, no pull request previews; [09](09-security.md) R19a). For webhooks, expose the dev dashboard through an HTTPS tunnel to `127.0.0.1:8000` (for example `cloudflared tunnel --url http://localhost:8000` in a labelled container) and set `APP_URL` in `dev/compose.yml`'s `x-dashboard-env` to the tunnel's `https://…` origin before clicking Connect GitHub. Then open the dashboard through that origin so the session cookie and the callback match. An app created while `APP_URL` was local stays webhook-less: create a new one ("Another GitHub App") after switching.

**Runtime details**:
- Version: the Makefile exports `LR_VERSION` (root `VERSION`), and the dev dashboard gets `LAUNCHROOM_VERSION=<VERSION>-dev`, the same string the dev daemons are built with.
- Realtime nudges: the dashboard containers set `LAUNCHROOM_DAEMON_NUDGE_URL=ws://reverb:6001` (the dev FrankenPHP doesn't proxy `/app/*`), so server-1's daemon subscribes to Reverb directly on the compose network. Its log shows `realtime nudges connected` and `nudge received` lines.
- **Edge Caddy with modules** ([05](05-networking-ingress.md) §4–5): `make caddy-image` builds `launchroom-dev-caddy:dev` from `deploy/images/caddy` (xcaddy, pinned modules, labelled) for the laptop's platform; `make caddy-image-load` loads it into the running fake servers and prints `launchroom-dev-caddy@sha256:…`. Pin that digest on the fake server (`LR_SYSTEM_IMAGE_DIGESTS=caddy=sha256:…`, host config only) and point the dashboard at it (`LAUNCHROOM_CADDY_IMAGE=docker.io/library/launchroom-dev-caddy@sha256:…`; `LAUNCHROOM_CADDY_MODULES=true`). Shared certificate storage additionally needs `LAUNCHROOM_CERT_STORAGE_ADDRESS` / `_PASSWORD` on the dashboard and, for a non-mesh address, `LR_CERT_STORAGE_ALLOW` on the servers. `make test-daemon-integration` loads the image into dind-test when it exists; `TestMultiEdgeSharedStorage` (two Caddys sharing Valkey, Pebble + pebble-challtestsrv as ACME CA and DNS) skips without it.

**Multi-server dev** (`make up-multi`, then `make dev-enroll-2` and `make dev-enroll-3`):
- `headscale` (v0.29.5, pinned) listens on plain http on port 80 of the compose network: dind's Tailscale clients can't trust a dev CA, and Tailscale's control client only speaks plain http on port 80. The fake servers allow it with `LR_MESH_INSECURE_LOGIN_HOSTS=headscale`. `up-multi` hands the dashboard the first Headscale API key (`launchroom:mesh:bootstrap-key`, stdin only). The dashboard and horizon mount the `headscale` volume read-only (`LAUNCHROOM_HEADSCALE_STATE_PATH`) so control-plane backups include the mesh state.
- `server-2` is another dind with launchroomd, roles runtime+build, and **no** `LR_REGISTRY_UPSTREAM`: it pulls from Zot through the mesh. `dev-enroll-2` issues a join token (`launchroom:servers:join-token --roles=runtime,build`), writes it and the CP key into server-2's data volume over stdin and recreates server-2.
- Its enrollment switches the mesh on: both fake servers start `lr-system-tailscale` in kernel mode (privileged dind has `/dev/net/tun`). server-1 gets 100.64.0.1 and serves its registry forwarder on `100.64.0.1:5000` (→ `zot:5000`); server-2 gets 100.64.0.2 and forwards `127.0.0.1:5000` there. Check with `docker compose exec server-2 docker exec lr-system-tailscale tailscale --socket=/var/run/tailscale/tailscaled.sock status`.
- DERP needs TLS, so the dev relay is unusable; the fake servers share a network and connect directly.
- **server-3** (`make dev-enroll-3`, role build, label `location=home`) simulates a home build box behind CGNAT. It is alone on the internal compose network `home` and reaches everything through `nat-router` (`dev/nat-router`: MASQUERADE out, conntrack-only back in, dnsmasq; NET_ADMIN, not privileged). Docker (OrbStack and Docker Desktop VMs) routes between compose networks even for `internal: true` ones and SNATs that traffic to the bridge gateway, so server-3's entrypoint also drops every new inbound connection from outside the LAN or from the bridge gateway. The mesh reaches it by hole punching.
- Re-enrolling a fake server (`make dev-enroll-N`, `ROLES=…`) wipes its nested Docker state (`/data/reset-docker`), because a new identity never adopts the old one's objects.
- Dev quirk: after Headscale was down for a while, Tailscale clients that fall back to forcing port 443 ("forcing port 443 dial due to recent noise dial") never reach the plain-http dev Headscale again. launchroomd restarts a client that stays disconnected for a minute once Headscale answers again ([05](05-networking-ingress.md) §2); by hand: `docker compose exec server-N docker restart lr-system-tailscale`. Production's https login server is not affected.
- Dev-only artisan commands drive these setups: `launchroom:servers:update --roles --labels --shared-edge`, `launchroom:dev:deploy-app --replicas --app-servers --db-servers --replace --json`, `launchroom:dev:git-push --sha`, and `launchroom:dev:status` (deployments, placements and servers as JSON, or a build log).

## 7. Supported platforms

- Servers: Linux x86_64 and arm64 (Debian and Ubuntu first-class; any distro with a recent Docker Engine should work).
- macOS (Apple Silicon and Intel) with OrbStack, Docker Desktop or Colima: build servers, and app servers with OrbStack or Colima; never edges (§3.3, D53). Docker Desktop on Windows/WSL: not supported by `server.sh`.
- Rootless Docker hosts: supported for runtime, build and edge roles (`server.sh --rootless`, §3.2, D49) with the limits in its capability matrix. Rootless Podman: not supported.
- Snap-installed Docker: unsupported (detected and refused).

## 8. Failover test (`make test-failover`)

Checks that launchroom keeps serving through server and control-plane outages on the dev multi-server stack. Script: `dev/failover-test/failover.sh` (POSIX sh, shellcheck-clean; docker compose, curl and artisan only). Load generator: `dev/failover-test/load.sh`, service `loadgen` (profile `failover-test`).

**Prerequisites**: `make up-multi`, all three fake servers enrolled (`make dev-enroll`, `make dev-enroll-2`, `make dev-enroll-3`) and launchroomd built (`make dev-daemon`). The script refuses to start when a service is not running.

**Topology** (set through the regular actions: `launchroom:servers:update` for roles, labels and shared-edge admission, `launchroom:dev:deploy-app` with scheduler allow-lists; no manual DB changes):

| Server | Roles | Placement |
|---|---|---|
| server-1 "control plane" | edge, runtime | control plane host; Postgres pinned here; app replicas |
| server-2 | edge, runtime, shared edge | the non-DB server that gets killed; app replicas |
| server-3 | build, label `location=home` | behind NAT (§6), builds only |

- Postgres sits on server-1 because server-1 is the control plane: server-2 is the only server that can be killed without also killing the control plane and still leave a DB. "Killing any non-DB server" is exercised as server-2 (edge + runtime) and server-3 (build).
- DNS failover is out of scope. Results are judged per edge: an edge whose server is down or rejoining is "not required", every other edge must answer every request.

**Scenario** (about 10 minutes):
1. A fresh project: node-hello on fixture branch `failover` (two commits, `release` 1 and 2), 3 replicas across server-1 and server-2, Postgres on server-1 and reached from both servers, the domain served by both edges. The build must run on server-3 and push through its registry forwarder (`127.0.0.1:5000` → mesh → server-1's Zot).
2. 80 `/db` requests through both edges (host ports 8443 and 8444) must all succeed, and a replica on each server must answer (`/db` runs `SELECT inet_server_addr(), pg_backend_pid()`; replicas on server-2 reach Postgres through their ambassador over the mesh).
3. Under continuous load against both edges, phases: `baseline`; `server-3-down` (killed for 40 s) and back; `server-2-down` until its replicas are rescheduled onto server-1 (after `node_dead_after`, 90 s), then back until its edge serves again; `control-plane-down` (dashboard, horizon, reverb, scheduler, vite, headscale, zot, valkey, postgres stopped for `LR_FAILOVER_CP_DOWN`, default 120 s), then back: all servers online again and v1 still active with 3 healthy replicas; `redeploy` of the second commit (built on server-3), after which both edges serve `"release":"2"`.
4. An `EXIT` trap restores the topology (starts every service again), also on failure.

It passes when every request through an edge that was up succeeded.

**Output**: `dev/failover-test/out/<timestamp>/` (git-ignored): `steps.log`, `phases.txt`, `load.log` (one line per request), `summary.txt` (per phase and edge: requests, ok, rate, max latency, required), `topology.json`, `project.json`, the build logs (`build-v1.log`, `build-v2.log`), `db-check.txt` and status snapshots (`status-*.json`; `status-at-failure.json` on failure). `LR_FAILOVER_PROJECT` overrides the project name.

**Known limits** (not failures of the test):
- A rejoining edge serves stale routes for about 15 s: when a killed server comes back, its Caddy starts with the config it had before it died until launchroomd fetches its current NodeState. Health-checked DNS takes such an edge out of rotation.
- Rescheduling waits for `node_dead_after` (90 s). Edges with a local replica keep serving (`prefer_local`); an edge without one sends part of its traffic to the dead server's mesh upstreams until Caddy's passive health check marks them down (one failed request per upstream, then 10 s backoff).
- Replicas don't move back after a server returns; the next deploy rebalances them.
- A real CGNAT that defeats hole punching needs DERP, which the dev stack can't run (no TLS).
