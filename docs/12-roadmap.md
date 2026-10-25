# 12: Roadmap

What is built, where the built parts fall short, and what is next. Decisions are in the [decision log](README.md#decision-log); this doc only lists them.

## Done

| Area | What it covers | Decisions |
|---|---|---|
| Foundations | Monorepo, Laravel control plane, Go daemon, generated protocol schemas, signed transport, containerised dev stack with dind fake servers, Pest and arch tests | D1, D2, D5, D15, D16, D17, D21, D22, D27 |
| Single server | Desired-state reconcile, Railpack + presets + Dockerfile builds on BuildKit, Zot registry, Caddy ingress with on-demand TLS, variables with references, staged changes and revisions, deployments with health-gated rollouts and rollback, GitHub App, backups with restic and `install --restore` | D3, D4, D6, D7, D8, D9, D12, D13, D18, D19, D23, D24, D25 |
| Environments and previews | Persistent environments, Compare & promote, preview environments per pull request with databases, protection and GitHub feedback | D14, D20, D28 |
| Multi-server | Headscale mesh, multi-server enrollment, scheduler with placement constraints and rescheduling, build routing, ambassadors and mesh-published ports, multi-edge ingress, volume backups, Replace server, recovery mode | D10, D11, D29, D30, D31 |
| Operations and data | Optional 2FA, Postgres point-in-time recovery and restore verification, console and Data tab sessions, public API v1 and CLI, request metrics and alerts, request analytics, link quality, log search and retention, guided updates, clean database stops | D33, D34, D35, D36, D40, D41, D42, D62 |
| Breadth | Dashboard at its own domain, templates and Compose import, `launchroom.json`, GitLab / Gitea / Forgejo / any git server, more DNS-01 providers, MongoDB and ClickHouse, generated Dockerfiles, dockerd-guard and rootless Docker hosts, mTLS for daemon transport and tamper-evident audit log, cron schedules, website and user docs | D43, D44, D45, D46, D47, D48, D49, D50, D51, D52 |
| Data in and out | Import into databases (dump files, connection URLs) and volumes (archives), downloads of backups and current data, CLI `db import`/`db export`/`volume export` | D55 |
| Moves | Move an online server to another (no data loss, no S3), move the control plane with `install.sh --move`, CPU architecture changes with rebuilds before the switch | D56 |
| Design | Product-style dashboard, design system, device art, voice and copy | D26, D32 |

The failover test ([11](11-installation-and-dev.md) §8) verifies the multi-server path end to end: builds on a home server behind NAT, replicas across two servers behind two edges, servers and the control plane killed under load.

## Known gaps

Limits of features that exist. Security limitations are listed in [09](09-security.md) §7.

- **Edges and replicas**
  - An edge that rejoins after a long disconnect serves stale routes for about 15 s.
  - Replicas move back to a returning server only on the next deploy.
  - Generated hostnames get their certificate on first request, so the first rollout to a new host can show TLS errors for about 25 s.
  - DERP relaying for hard NATs is untested in dev, and there is one DERP region.
- **Measurements**
  - Link throughput above about 1 Gbit/s is underestimated (16 MiB transfer, TCP slow start).
  - Request metrics don't cover the dashboard's own route or plain-HTTP redirects.
  - Request analytics lose the open 10-minute window of paths when launchroomd restarts, merge top lists approximately (a path below the top 50 of every window lands in "other") and have no countries (no local GeoIP database).
  - Log filter facet counts cover the loaded page only.
- **Backups:** restore verification runs on the database's own server. Redis/Valkey can be backed up but not restored.
- **Rootless hosts:** visitor addresses are not preserved (RootlessKit's builtin port driver), and resource limits need cgroup v2 delegation.
- **dockerd-guard** has to follow every new Engine API call or container field launchroomd uses (R35).
- **Git providers**
  - GitLab is tested against HTTP fakes only, not a real instance.
  - Plain git servers have no file API, so `launchroom.json` is not read for them.
- **Generated Dockerfiles:** not yet run against the real Anthropic API. Pull requests on GitHub need the App's Contents: write permission. Generations are not in the public API.
- **Templates:** image digests are bumped by hand.
- **Tests:** the update e2e has no second server (the fleet rollout is covered by Pest). The dind integration suite and fuzzing run locally, not in CI.
- **Website:** not deployed yet. There is no demo video.

## Backlog

Not implemented. Grouped by area; order within a group is not a priority.

### Control plane and platform
- HA control plane (stateless dashboard on two nodes + replicated Postgres), only if there is demand. Managed HA Postgres for apps.
- Octane worker mode for the dashboard (FrankenPHP runs in classic mode; switching back to PHP-FPM is a Dockerfile change).
- Opt-in telemetry for framework, preset and failure-class statistics: off by default, asked during onboarding, no personal data.
- Prune `node_state_versions` to the last N versions per server ([07](07-data-model.md)).
- `registry` role on servers other than the control plane (Zot always runs there today).
- Mirror infrastructure images into Zot.

### Builds and deploys
- Shared Redis cache handler for Next.js ISR and `'use cache'` (`LAUNCHROOM_NEXT_CACHE=redis` is reserved; today each replica has its own cache).
- Eject to Dockerfile (a plan → Dockerfile transpiler). Commit `railpack.json` through the git provider instead of downloading it.
- Parallel image pulls: one long pull blocks the reconcile loop today.
- Multi-platform builds (one build per platform, merged into a manifest list).
- Per-service cached git mirrors for clones.
- Per-deployment artifacts (plan JSON, BuildKit invocation, SBOM) and a "Reproduce locally" command.
- "Update builder" per service when a new Railpack version ships.
- One-click "use auto-build instead" for dev-only Dockerfiles.
- Framework-aware graceful stops for workers (`horizon:terminate`, queue SIGTERM with 60 s).
- Database engine version upgrades with an automatic safety snapshot first.
- Fixture corpus of real-world repositories built nightly against the pinned and the latest Railpack.
- An e2e CI job: dev stack up, fixtures deployed into dind, a browser smoke test.

### Environments and previews
- Environment protection rules (approval for production deploys and promotions) and deployment checks. Per-project role overrides.
- Separate apps domain for previews, so preview code can't set cookies on production's parent domain.
- Previews that share a base database on another server (ambassadors and allow-list entries for them).
- Preview clones from an on-demand dump when the source has no backup; a free-disk check on the target before a clone.
- An escape syntax for `${{ }}` in variable values.

### Networking
- tsnet userspace mesh fallback for hosts without `/dev/net/tun`.
- LAN-only installs where Headscale uses an internal CA.
- Single-use per-server keys through a Tailscale OAuth client for "Bring your own Tailscale".
- mTLS between ambassadors with per-project identities.
- DNS TXT ownership verification (`_launchroom.<host>`) for custom domains on multi-team installs.
- Per-team certificate storage (key prefix, ACL user, encryption key) and DNS-01 limited to each team's routes. A dedicated Valkey for certificate storage, so `SCAN` can't list key names.
- Headscale: `tagOwners` as an unused principal; re-push the ACL when Headscale errors instead of answering 404.
- Visitor IPs on rootless hosts (slirp4netns port driver).
- Public access to a database through the edge's L4 proxy (admin only, forced strong password, warning).
- Dashboard IP allow-list or mesh-only dashboard access with a separate webhook receiver.
- A second DERP region on another edge.

### Moves (D56)
- Retry a failed service from the failed step instead of from the start.
- DNS changes through a configured DNS provider instead of by hand.
- Copy registry images to the new server instead of rebuilding them on the next deploy.
- Image services whose image exists for one platform only can't move to another architecture.
- Generated `…sslip.io` addresses that contain the old server's IP stop working once it is gone; the plan warns, but nothing re-addresses them.
- With a dashboard address tied to the old IP (an IP or `<ip>.sslip.io`), other servers need `LR_CONTROL_PLANE_URL` changed by hand and their mesh clients re-joined (same as a restore); the new control-plane server's own mesh client can't log in to the new address without a new key (harmless while it is the only server).
- A hand-over whose new machine dies after it restored (before it reports back) leaves the old dashboard frozen; the way back is Abort hand-over there (only safe while no new dashboard runs), or moving again.
- A stuck queued deployment blocks the hand-over ("Wait for these to finish first: deployments").
- Not verified on a real amd64 ↔ arm64 pair yet: the plan (rebuild flags, the missing-builder refusal) and the prebuild marker are covered by Pest, the prebuild deployment itself not end to end.

### Backups
- Restore into another environment (refresh staging from production) and clone into a preview.
- Redis/Valkey restore.
- Self-backup: another launchroom server running an S3-compatible service as a backup target; a local backup destination in the dashboard (launchroomd already supports it).
- Run "test destination" as a daemon task instead of in the dashboard container. Seal backup task credentials at lease time and don't store them.
- Weekly `restic check`, scheduled by the dashboard.
- Opt-in safety snapshot before production deploys with a release command.
- Optional backup of deployment logs. I/O weights for backups.
- Refuse unauthenticated (format 1) recovery bundles.
- A typed `registry_reload` task so the dashboard can restart Zot.

### Observability
- Alert routing per channel, quiet hours, escalation, acknowledging alerts, per-project channels.
- SMS, PagerDuty and Opsgenie channels (the signed webhook covers them meanwhile).
- Request metrics per path and status code, client geography, a Traffic view on the server map.
- Preview screenshots on preview cards.

### API, CLI and config as code
- API endpoints for metrics, backups, sessions and runtime log retention ([16](16-api-and-cli.md)).
- Team-owned robot tokens that outlive their creator; per-service deploy hooks with a single ability.
- `launchroom deploy` from a local source tarball.
- `launchroom.jsonc`; start a project from a repository's `launchroom.json`; save a project as a template; multi-environment export; shared variables, preview database strategies and triggers in the file.
- Cron services in blueprints (templates and Compose import).
- Per-build read-only deploy tokens for GitLab and Gitea/Forgejo instead of the connection token. Bitbucket.

### Security
- Rotation of the internal client-certificate CA ([03](03-daemon-protocol.md) §4.3; the schema already accepts overlapping CAs).
- Revoke GitHub clone tokens when a build ends.
- Prompt for secret rotation when a server is revoked.
- Per-team "require 2FA" setting.
- Verify Fulcio's `SourceRepositoryRef` in launchroomd; downgrade protection in the installers; delete update rollback copies after a successful update.
- Run `pg_restore` as a non-superuser.
- Authorization cleanups: snapshot listing needs `deploy` or `backups.manage`; scope the parent-service lookup in `DiscardChanges`; bind `{revision}`, `{preview}` and `{shareLink}` through `TeamScopedBindings`; align permissions for retired-volume deletion, environment copies with secrets and Revert.
- Account hygiene: throttle forgot-password without revealing whether an account exists; case-insensitive e-mail uniqueness.
- Secrets delivered as tmpfs `*_FILE` files.

- Security center follow-ups ([22](22-security-center.md) §15): per-service outbound network rules, runtime detection of shells spawned in containers, rotating managed database credentials as one action, rebuild without cache, firewall settings in `launchroom.json`, CVSS v4 scoring, SBOM downloads, API writes for the firewall and dismissals, inventories of previews.

### Dashboard
- Presence avatars on projects.
- A `?` shortcut overlay, `.` and `d` shortcuts, more ⌘K verbs (for example "Add domain to web").
- Optional offline GeoIP database for suggesting a server's location.

## Cross-cutting

- The rules in [09](09-security.md) apply to every change.
- New features extend the fixtures, the integration suite and the e2e tests that cover them.
- Docs change in the same change as the decision or behaviour they describe.
