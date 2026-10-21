# 02: Architecture

## 1. Components

```
                          ┌─────────────────────────── Control plane (one server, one compose stack) ───────────────────────────┐
  Browser ──HTTPS──▶ Caddy│──▶ dashboard (Laravel 13 / FrankenPHP)  ── Inertia/React UI, REST API, GitHub webhooks, daemon API   │
                          │      horizon (queue workers)            ── deployment state machine, scheduler, GitHub sync         │
                          │      reverb (websockets)                ── UI realtime + daemon "poll now" nudges + log streams       │
                          │      scheduler (artisan schedule:work)                                                               │
                          │      postgres · valkey                                                                               │
                          │      zot (registry)                     ── images, build cache, mirrored base images                 │
                          │      headscale (+DERP)                  ── off until a 2nd server joins                              │
                          │      launchroomd ────────────────────────── same launchroomd as every other server                   │
                          └─────────────────────────────────────────────────────────────────────────────────────────────────────┘
                                         ▲ HTTPS (signed), outbound from daemons only
          ┌──────────────────────────────┼───────────────────────────────┐
   ┌──────┴───────┐               ┌──────┴───────┐                ┌──────┴───────┐
   │ server: edge │               │ server: run  │                │ server: build│   (roles combine freely)
   │ launchroomd  │               │ launchroomd  │                │ launchroomd  │
   │ caddy        │               │ app containers│               │ buildkitd    │
   │ tailscale    │               │ ambassador   │                │ tailscale    │
   └──────────────┘               └──────────────┘                └──────────────┘
```

| Component | Tech | Runs where | Responsibility |
|---|---|---|---|
| **dashboard** | Laravel 13, Inertia 3, React 19, FrankenPHP | control-plane host | All user intent and state: teams, projects, environments, services, variables, domains, deployments, servers. Computes desired state per server. Scheduler (placement). GitHub integration. Registry token issuer. |
| **horizon** | Laravel Horizon | control-plane host | Queue workers: webhook processing, deployment orchestration steps, desired-state recomputation, GitHub status updates, cleanup jobs. |
| **reverb** | Laravel Reverb | control-plane host | Browser realtime (status, logs, staged-change counts, presence). Daemon nudges ("your desired state changed", "a task is waiting"). |
| **postgres** | PostgreSQL 17/18 | control-plane host | Source of truth. Backed up by launchroom itself. |
| **valkey** | Valkey (Redis-compatible) | control-plane host | Queues, cache, locks, Reverb scaling, log fan-out buffers. |
| **zot** | Zot registry | control-plane host | App images, BuildKit registry cache, mirrors of base images (Railpack builder/runtime, Postgres, Caddy…). |
| **headscale** | Headscale + embedded DERP | control-plane host | Mesh coordination. Disabled with one server and enabled automatically when the second server is added. |
| **launchroomd** | Go static binary in a container, plus `dockerd-guard` owning the Docker socket (D49) | **every** server, including the control-plane host | Reconciles desired state (containers, networks, volumes, Caddy routes, ambassadors, firewall). Executes leased tasks (build, release command, backup, log fetch). Reports status, metrics and logs. |
| **caddy** | Caddy (custom build with storage module) | servers with the `edge` role | Public ingress, TLS (ACME), load balancing to replicas, on-demand TLS for custom domains. Configured only by the local daemon through the admin API on localhost. |
| **buildkitd** | moby/buildkit (rootless) | servers with the `build` role | Builds images (Railpack frontend or Dockerfile). Started on demand by the daemon. |
| **tailscale** | stock `tailscale/tailscale` container, kernel mode | every server once the mesh is enabled | WireGuard mesh connectivity (NAT traversal, DERP fallback). |

The control-plane host is **another server with a daemon** (the "the main server is also a client" requirement). There is exactly one code path for deploying to the local box and to remote boxes.

## 2. Core model: intent → desired state → reconciliation

```
 user edits (staged)        Deploy            scheduler/placement          per-server desired state          daemon
 ──────────────────▶ tables ───────▶ revision ─────────────────▶ placements ──────────────────────▶ NodeState vN ──▶ converge
                            (immutable                      (which replica on                   (signed JSON,       (Docker API,
                             snapshot)                       which server)                       versioned)          Caddy API, nft)
                                                                                                        ▲                 │
                                                                                                        └── observed state ┘
```

1. **Intent** lives in normalized tables (services, variables, domains, settings). Edits are *staged* until Deploy ([06](06-variables-environments.md)).
2. **Deploy** freezes intent into an immutable **EnvironmentRevision** and creates **Deployments** (one per affected service). Each one either needs a build or reuses an image.
3. The **scheduler** decides placements (which servers run which replicas), respecting roles, labels, resources, anti-affinity, and pinning stateful services to the server that holds their volume. Details in §2.1.
4. For each affected server the dashboard renders a **NodeState**: the complete list of workloads, networks, volumes, routes (if edge), ambassadors and firewall allowances that server should have. It is versioned (monotonic `version`), signed, and stored.
5. The **daemon** fetches its NodeState (nudged over Reverb, with periodic polling as a fallback), persists it to `data/daemon/state.json`, and **reconciles continuously**: it creates what's missing, updates what changed (rolling, health-gated), and removes what's no longer desired (only objects carrying `launchroom.managed=true` labels).
6. launchroomd reports **observed state** (per-workload status, health, container IDs, resource usage) with `observed_at` ordering. The dashboard advances deployment state machines from these reports.

Imperative work is modelled as **tasks**, which are leased, idempotent and time-limited ([03](03-daemon-protocol.md) §7):
- `build` (clone, plan, build, push) and `plan` (dry run)
- `run_once` (release/migration command in a one-off container from the deployment's image)
- `backup` / `restore`, `backup_snapshots`, `backup_maintenance`, `verify_restore`
- `logs` (runtime logs on demand)
- `session` (console and database data sessions, separately authorised, [03](03-daemon-protocol.md) §11)
- `self_update`

Why desired state instead of RPC primitives: retries are idempotent; a server that was offline catches up by fetching the latest version, with nothing to replay; reboots and crashed containers heal without the control plane; and there is no compensation logic for half-applied command sequences (this was Coolify v5's failure mode).

### 2.1 Scheduler

Code: `App\Scheduling\Scheduler` (pure: no IO, property-tested in `tests/Unit/Scheduling`), `App\Services\Deployments\Placer` (database view + placement rows), `PlacementConstraints` (validation on save).

- **Constraints** (per service instance, `ScalingSettings`): replicas, CPU and memory limits, required server labels, `spread` (default) or `pack`, a server allow-list and deny-list (public ids). Per server: labels and two flags, **Allow production** and **Allow previews** (both on by default; Servers → server → Placement). Previews only land on servers allowing previews, production environments only on servers allowing production; persistent environments (staging) anywhere.
- **Filters**: online, `runtime` role, every required label, allow/deny lists, the environment flags, the image's platform (built images, [04](04-build-and-deploy.md) §5.1). Stateful services run one replica on the server holding their volume, once pinned (first placement pins it).
- **Capacity**: memory limits fit `total × memory_overcommit (1.5) − reserved − allocated`, CPU limits fit `cores × cpu_overcommit (4.0) − allocated`; reserved = 256 MB per server (+768 MB on the control plane). Cores and memory come from the latest report (`servers.host_status`), else the enrollment facts; unknown ones skip the check. `allocated` sums the serving replicas of other services (`placements.memory_mb`, `placements.cpus`).
- **Choice** per replica: spread → fewest replicas of the set on the server, then fewest in its failure domain (`zone` label, else `provider`, else the server), then servers of the current deployment (image already pulled), then most free memory, then lowest id; pack → servers already running the set, then the current deployment's, then the least free memory that fits (best fit). Replicas that stay (rescheduling, rolling) count for anti-affinity and capacity. Deterministic.
- **Errors** are user-facing and name each server's reason: "No server satisfies this service's placement constraints (hel-1: missing label gpu=yes; fra-2: doesn't allow previews)". Saving constraints no server satisfies is refused; at deploy time the deployment fails with `placement_error`.
- Changing server settings or constraints moves nothing by itself; the next deploy places replicas by the new rules.

### 2.2 Changing server roles

Roles are chosen at enrollment (join token) and can be changed later on Servers → server → Roles (admins, password confirmation; `launchroom:servers:update <server> --roles=edge,runtime --labels=k=v,…` for scripts; both run `UpdateServerRoles`, audited as `server.roles_updated`). Only `edge`, `runtime` and `build` are editable; `registry` stays as it is. Typical use: the control-plane VPS drops `build` so builds run on a home build box.

- **Refused** (the message names the reason): no role left; removing `runtime` while the server still has placements or volumes ("move them first": placement constraints and a deploy, or Replace server); removing `build` from the team's last online build server; removing `edge` from the team's last edge (offline edges count, they come back).
- **Effects**: every mesh server is re-rendered (the server's own `system.caddy`/`system.buildkit` follow its roles; routes, edge upstreams, mesh allow-lists and the shared certificate storage follow the set of edges), build/plan tasks pinned to it that nobody leased yet go back to the pool (running builds finish there), and the Headscale node tags follow the roles (MaintainMesh). Nothing running is moved.

## 3. Deployment flow (single server, happy path)

1. GitHub push → webhook → `HandlePushEvent` job → finds service instances tracking that repo + branch (respecting watch paths) → creates a Deployment (status `queued`) using the current environment revision.
2. Orchestrator creates a `build` task. The scheduler picks a build-capable server (sticky per service for cache locality).
3. The build daemon leases the task. It clones (installation token, shallow, exact SHA), detects and plans (Dockerfile / preset + Railpack), builds with BuildKit, and pushes `registry/apps/<service-uuid>:<deployment-ulid>`. Logs stream in batches. It reports the image digest.
4. If the service has a release command (e.g. `php artisan migrate --force`), a `run_once` task runs it in a one-off container from the new image on a runtime server, with the resolved env. Failure stops the deployment and the old version keeps serving.
5. The dashboard renders new NodeStates: new workloads (digest-pinned) next to the old ones, routes still pointing at the old ones.
6. The runtime daemon pulls by digest, creates containers on the project network, and **health-probes from outside** (HTTP path or TCP connect against the container IP:PORT).
7. When healthy, launchroomd reports. The dashboard bumps the NodeState: routes now point at the new replicas, and the old replicas are marked draining. The edge daemon applies the Caddy config atomically through the admin API.
8. After the drain period the old containers stop. The deployment becomes `active` and the previous one `superseded`. Its image is kept for rollback according to retention.
9. GitHub Deployment status, check run and PR comment are updated by listeners on deployment state events.

## 4. Topologies

### 4.1 One server (default install)

```
 DNS: *.apps.example.com → VPS   (or <svc>-<env>.<ip>.sslip.io until a domain is set)
 ┌──────────────────────────── VPS, ~/launchroom ────────────────────────────┐
 │ caddy (edge) ──▶ app containers on per-project networks                    │
 │ dashboard, horizon, reverb, scheduler, postgres, valkey, zot               │
 │ daemon (roles: edge, runtime, build)  ·  buildkitd started on demand        │
 │ headscale: disabled                                                       │
 └───────────────────────────────────────────────────────────────────────────┘
```
No mesh and no extra moving parts. Builds run locally. On servers with less than 2 GB of memory the server page suggests a separate build server, and a build that runs out of memory says so in its failure hints.

### 4.2 VPS plus home build machine behind NAT

```
 Internet ─▶ VPS (public)                                   Home (CGNAT, no inbound)
            caddy, dashboard, zot, headscale+DERP  ◀═══WG═══ build box: daemon (role build), buildkitd, tailscale
            daemon (edge, runtime)                  direct or DERP-relayed over 443
```
The build box dials out to the dashboard's public URL, enrolls, gets a Headscale pre-auth key, joins the mesh, leases build tasks and pushes to Zot over the mesh. It needs zero inbound ports.

### 4.3 Many servers with HA ingress

```
                     DNS: app.example.com → E1, E2 (health-checked DNS or floating IP)
               ┌─────────────┐   ┌─────────────┐
               │ E1: edge+CP │   │ E2: edge    │   certs shared via storage (+ local mirror)
               └──────┬──────┘   └──────┬──────┘
          ════════════╪══════ WireGuard mesh, ACL default-deny ══════╪════════════
           ┌──────────┴─┐  ┌───────────┐  ┌──────────────┐   ┌───────────────┐
           │ D1 run     │  │ D2 run    │  │ D3 run (pg)  │   │ B1..Bn build  │
           │ web#1 api#1│  │ web#2 api#2│ │ postgres     │   │ (some @home)  │
           │ ambassador │  │ ambassador│  │ :port on mesh│   └───────────────┘
           └────────────┘  └───────────┘  └──────────────┘
```

## 5. Uptime vs. ease-of-use trade-offs

| Topic | Default (ease) | What it costs | Upgrade path (uptime) |
|---|---|---|---|
| **Single edge** = the first server | One DNS record, no LB to manage | If that server dies, all public traffic is down | Tick "edge" on 2–3 public servers and point DNS at all of them (health-checked DNS or a provider floating IP). The UI shows a "single point of failure" badge while only one edge exists and other servers could take traffic too; a single-server install (the default) gets a gentle "add a second server" hint instead. |
| **Control plane on one server** | Simple install and backup | No deploys, builds, dashboard or new enrollments while it's down | **Apps keep running and serving** (daemons reconcile cached state, Caddy routes and certs are cached locally, on-demand TLS `ask` is answered by the local daemon). There is no HA control plane; restore it from backup instead ([14](14-backup-and-restore.md)). |
| **Control plane = edge = registry** | Everything on one box | That box is the hot spot | The edge role can move to other servers. The control plane can run on a non-edge server. |
| **Headscale embedded** | No Tailscale account needed | Headscale and DERP must be publicly reachable on 443 + UDP 3478. If every server is behind NAT, users need one public box (a $3 VPS) or their own Tailscale. | "Bring your own Tailscale tailnet" option. |
| **Stateful services pinned** | No split-brain, simple volumes | A server with a DB dies, so the DB is down until restored | Backups to S3 with one-click "restore to another server". |
| **sslip.io auto-domains** | Works with no DNS setup | Shared Let's Encrypt rate limits and third-party DNS | Onboarding pushes for a wildcard domain early; DNS-01 wildcard certs with a DNS provider token. |
| **Builds on the same box** (1 server) | Zero setup | A build can starve running apps of CPU/RAM | BuildKit runs with CPU and memory limits and a build-slot queue. Adding a build server is one command. |

Rule of thumb for the UI: **never hide a single point of failure**. Show it calmly, explain the fix, and make the fix one click plus one command. One server on its own is not presented as a risk (it is how most installs start and it works well): it gets an optional, positive hint ("Use a second server to improve reliability"), not a warning.

## 6. Failure modes

| Failure | Effect | Response |
|---|---|---|
| Control plane down | No changes; running apps unaffected | Daemons keep the last NodeState; Docker `restart: unless-stopped`; Caddy config and certs persisted in `data/`; existing WireGuard peers keep working. Certificate renewal pauses (about 2 weeks of headroom with 45-day certs). |
| Daemon crash or restart | No reconciliation on that server; containers keep running | `restart: always`; on start launchroomd re-reads `state.json`, re-applies firewall rules and the Caddy config, and resumes. |
| Server down | Its replicas are gone; edges drop them through health checks within seconds | After `node_dead_after` (90 s) the scheduler re-places **stateless** replicas elsewhere. Stateful services are never auto-moved; alert and offer restore (§6.1). |
| Network partition (server alive, CP unreachable) | CP thinks the server is dead | Temporary over-provisioning is fine for stateless services. On rejoin, launchroomd sees a newer NodeState version and stops orphans. Stateful services are never rescheduled automatically (no fencing, so no split-brain). |
| Registry down | No new pulls or pushes | Already-pulled images keep running and restarting from the local Docker image store. |
| Build server offline mid-build | Lease expires | The task is re-queued on another build server (builds are idempotent: same SHA, same plan, same tag). |
| Deployment health check fails | New version never receives traffic | New containers are removed and the old version keeps serving. The deployment is `failed` with a classified reason and the last 100 log lines. |

Stateful services never move on their own. Moving them is a request: **Move to another server** (and Settings → Move launchroom for the dashboard's server) copies each database and volume live with a short stop per service, while the source keeps running (D56, [14](14-backup-and-restore.md) §13). A server that is gone is replaced from backups instead (§6.1, [14](14-backup-and-restore.md) §5.3).

### 6.1 Rescheduling

Code: `App\Actions\Servers\RescheduleDeadServers`, `App\Services\Deployments\Rescheduler`; runs with `launchroom:servers:check-liveness` every 15 s.

- **Thresholds**: the server *status* keeps its hysteresis (`unreachable` after 75 s, `offline` after 300 s without a request, [03](03-daemon-protocol.md) §9.6); "dead for scheduling" is a separate threshold, `launchroom.scheduling.node_dead_after_seconds` = **90 s** since `last_seen_at`. With the 15 s tick, replicas move 90–105 s after the last contact, while the server is still `unreachable`. Measured in dev (server-2 stopped): unreachable after 80 s, replicas re-placed after 95 s, healthy on server-1 after 104 s, 0 of 120 requests failed through server-1's edge.
- **Guard**: replicas only move once the liveness check has run continuously for the threshold (a gap of more than 2 minutes between runs restarts the watch), so a control plane coming back from an outage first lets every server check in instead of evacuating them all.
- **Stateless replicas** of a dead server get a new placement (same deployment and slot, new workload id) on a healthy server chosen by the scheduler with the set's remaining replicas as existing ones; the dead one is deleted, the dead server's NodeState is rendered without it and the edges re-render. When the server returns, launchroomd fetches that NodeState and removes the orphans ("no longer desired"; measured: 1.5–2 s after its daemon started). Nothing moves back automatically; the next deploy spreads again. Draining workloads on a dead server are just dropped. Audited as `server.replicas_rescheduled`.
- **Stateful services** are never moved automatically (a planned move on request is D56, [14](14-backup-and-restore.md) §13): the instance gets `placement_issue = {kind: stateful_server_down}` (audited once as `service.degraded`), its canvas node says "Server down", and a banner on the canvas and in Settings → Scaling links to the server, to the Replace-server wizard (`servers/{server}/replace`, [14](14-backup-and-restore.md)) and to restoring the latest backup elsewhere (the service's Backups tab). The mark clears when the server is back.
- **No room elsewhere**: the replica stays where it is (it may come back), the instance is marked `unplaceable` with the scheduler's reason ("Degraded"), and the next tick retries.

## 7. Why these technology choices

- **Laravel for the control plane.** Queues, jobs, events, broadcasting, policies, encryption, Fortify (2FA/passkeys) and Sanctum come for free, and the maintainer knows it well. The control plane is a CRUD-plus-orchestration app, which is Laravel's sweet spot. It never runs shell commands against servers.
- **Go for the daemon.** A static binary, and first-class libraries for everything launchroomd touches: Docker Engine API client, BuildKit client, Railpack core (importable Go module), Caddy (Go), nftables. Low memory, easy cross-compilation for arm64 home servers.
- **Plain Docker plus our scheduler, not Swarm/Kubernetes/Nomad.** Swarm brings quorum, overlay/MTU pain and half-supported features. Kubernetes violates the single-folder rule and is heavy. Nomad is BSL-licensed. Kamal, Fly and Railway all prove "own scheduler + per-host daemon" works.
- **Caddy over Traefik.** Atomic config pushes through the admin API (no label provider, no file watching), on-demand TLS with an `ask` hook for custom domains, and certificate coordination across instances via shared storage (Traefik OSS has no distributed ACME).
- **Railpack over Nixpacks/buildpacks/`vercel build`.** Railpack is actively developed by Railway, produces BuildKit LLB with good caching and build secrets, and emits a plan we can show and patch. Nixpacks is in maintenance mode, CNB builders are heavy and framework-blind, and Vercel's output format would mean re-implementing Vercel's runtime.
- **Zot over Distribution/Harbor.** A single binary with online GC, retention policies ("keep last N"), pull-through mirroring and token auth. Distribution's GC needs downtime and Harbor is too heavy.
- **Headscale over Tailscale SaaS / NetBird / Nebula / raw WireGuard.** Stock Tailscale clients (best NAT traversal, DERP over 443), no external account, BSD license, and API-driven. Nebula is the runner-up but UDP-only. Running our own WireGuard would mean rebuilding Headscale badly.
