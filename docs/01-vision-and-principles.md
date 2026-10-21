# 01: Vision & principles

## What launchroom is

**One place to deploy.** Open source, on servers you choose, yours end to end. (Voice and copy rules: [design system](design-system.md#voice-and-copy).)

launchroom is not "self-hosting made easy" or a cheaper step down from managed platforms. It aims to be the best place to deploy, period, that also happens to run on your own servers. Internally we call this **"not a compromise"**. It's a design principle, not a slogan.

What that means in practice:

- **Projects are canvases.** Drop in services (a Next.js frontend, a Laravel API, Postgres, Redis, a worker or a cron job) and connect them. A connection does real work: it injects credentials, opens a private network path and orders startup.
- **Deploys just work** for the common frameworks without anyone writing a Dockerfile. When they don't, failures are explained and fixable, and there's an escape hatch.
- **Env vars are boring.** You set them in one place, they're encrypted at rest, they're targeted to environments, and launchroom works out build vs runtime.
- **Environments and previews**: production, staging, and per-PR previews with their own URLs, GitHub statuses and PR comments.
- **Servers are a pool, not a chore.** Add a VPS or a home build box with one command. No SSH keys, IP lists or registry setup. Mark servers as build, runtime and/or edge.
- **Your machines, your data**: everything lives in one folder plus Docker. Uninstalling means deleting the folder and the launchroom-labelled Docker objects.

## Who it's for

1. **The solo developer with one small VPS** who wants Vercel-like deploys for a few side projects and a database. That should be excellent out of the box, needing only `curl | sh` and a GitHub App.
2. **The small team / agency** running a handful of servers, wanting previews, staging, roles, and a beefy build machine somewhere (maybe at home).
3. **The power user** with 10 build servers and 50 deploy servers. The architecture must not stop us from getting there, but the defaults don't optimise for it.

## What makes Vercel and Railway great (and what we copy)

| Principle | Concretely in launchroom |
|---|---|
| Zero-config first deploy, progressive disclosure | Detected values are shown greyed out with an **Override** toggle (Vercel build settings). Docker internals live under "Advanced". |
| Everything has a URL | Every deployment, log line, environment, service panel tab and staged diff is deep-linkable. Panels are routes, not modals. |
| Immutable deployments | A deployment is image digest + resolved config + env snapshot, and it is never mutated. Rollback re-points traffic. |
| Staged changes, one Deploy button | Edits accumulate into a reviewable diff and are applied atomically, which removes the fear of clicking things (Railway). |
| Realtime everywhere | Canvas status, logs, deployment lists and metrics update over websockets. |
| Connections are first-class | Drawing an edge injects references with framework-aware names (`DB_HOST`… for Laravel, `DATABASE_URL` for Prisma). |
| Speak app, not container | The UI says "Service, Database, Domain, Variable, Deployment", never "Traefik router" or "compose stack". |
| ⌘K and the keyboard | Command palette to jump anywhere and run actions, plus shortcuts. |
| Safe production by construction | Healthcheck-gated cutover, instant rollback, protected previews, prod secrets distinct from preview. |
| Git is the source of truth, the UI is the fast path | PR comments, commit statuses, GitHub Deployments, branch URLs, and an optional `launchroom.json` config file ([18](18-config-as-code.md)). |
| Environments are copies of the graph | The environment switcher swaps the whole canvas. |
| Self-hosted twist: show the box | Show which server runs what and how much headroom it has. Users own the hardware. |

## What we learned from Coolify and Dokploy

The short list:

**Avoid**
1. **SSH plus shell strings as the control mechanism.** It is the root cause of dozens of command-injection CVEs in both, plus Coolify's regex-based sudo rewriter. We use a typed daemon API instead.
2. **Docker socket mounted into the dashboard or build helpers.** Then "can deploy" equals "root on the host". In launchroom only launchroomd touches Docker, and builds run in rootless BuildKit.
3. **Routing via container labels.** Domain changes then need a redeploy, and labels end up duplicated for two proxies. We render proxy config from desired state and apply it through Caddy's API, so domain changes are instant.
4. **`:latest` tags and rebuild-to-rollback** (Dokploy). We use immutable per-deployment images, keep the last N, and roll back by re-pointing.
5. **"Deployment succeeded" before the app is actually up** (Dokploy, no default healthcheck). We gate cutover on an external health probe.
6. **Healthchecks that need curl inside the image** (Coolify). launchroomd probes from outside the container.
7. **A builder menu that makes the user choose** (Nixpacks → Railpack → Dockerfile). We have one automatic path with visible, editable and ejectable decisions.
8. **Env var knob explosion and silent failures** (Coolify has 6 flags per row, preview copies, three schema migrations). We use one model, and unresolved references fail the deploy loudly.
9. **One flat Docker network for everything**, sometimes including the dashboard's own DB. We use one network per project environment, and infra is never reachable from apps.
10. **God objects**: Coolify's 6,137-line deployment job and 17k lines of global helpers; Dokploy's 60-column application table and per-DB-engine copy-paste. We use small actions, a persisted step state machine and engine drivers.
11. **Deployment logs as a JSON column rewritten per chunk.** We use append-only log chunks and websocket streaming.
12. **Disk-fill footguns**: no log rotation, and nightly `builder prune --all` that wipes the build cache. We set per-container log limits and prune by age.
13. **Plaintext secrets in odd places** (GitHub App secrets, snapshot JSON, hardcoded fallback passwords). Every credential is encrypted, and secret attributes are hidden from serialization by default.
14. **Cloud/self-hosted code forks and data-stored behaviour flags** (`compose_parsing_version`). We have one execution path, and we migrate data rather than fork behaviour.

**Copy**
- Classify config fields as **build-affecting or runtime-only**, so runtime changes redeploy the same image (Coolify).
- Cleanup thresholds that keep the last N images per service, prune build cache by age, and never auto-prune volumes (Coolify, plus Dokploy PR #3267).
- GitHub App **manifest flow**, server-side check of `installation_id`, and preview gating on PR author permission (both).
- **SSRF defence with DNS pinning** (Coolify `SafeWebhookUrl`).
- Server health checks: inotify limits, address pool exhaustion, disk, and grepping Docker's journal for known failure strings (Dokploy).
- Liveness hysteresis (2 consecutive failures before "unreachable") and staggered periodic jobs (Coolify).
- Many small operational lessons: create bind-mount files before starting containers; `HOSTNAME=0.0.0.0` for Next standalone; Postgres 18's new PGDATA path; LE refuses underscores; hostnames punycoded with 63-char labels; `docker compose` must never get `--yes`.

**The cautionary tale.** Coolify's own v5 rewrite used nearly our stack: Laravel + Inertia/React, a Rust daemon dialing out, a gRPC broker, Podman, a WireGuard mesh, custom DNS, an nftables firewall and gossip-based service discovery. It was built and then **archived**, with notes that a future v5 "must define a new architecture" (`references/coolify/docs/v5/`). Their own audit lists lost status updates during outages, no-TLS gRPC, wildcard capability profiles and unauthenticated gossip. Our takeaways:
- **Scope kills.** The single-server product comes first. The mesh, ambassadors and multi-edge build on it without changing it ([12](12-roadmap.md)).
- **Fewer moving parts.** No separate broker process (Laravel speaks to daemons directly), no gossip layer (the dashboard is the source of truth and daemons cache it), and no custom DNS server (Docker's embedded DNS plus ambassadors).
- **Declarative over RPC primitives.** v5 exposed fine-grained imperative primitives (`containers.create`, `firewall.allow`…), which needed compensation logic and lost updates on outages. We send *whole desired state per server*, versioned, and launchroomd converges. Retries are idempotent by construction.

## Principles for building launchroom

0. **Not a compromise.** Every feature is judged against the best managed platforms, not against raw Docker. If a managed platform would do it better, that's a bug in launchroom.

1. **Deployment is the product.** When trading off features, the deploy path (detect → build → release → cutover → observe) gets the polish first.
2. **Excellent at one server, possible at sixty.** Every feature must work with zero extra setup on a single server. Multi-server features appear only when a second server exists.
3. **Build on giants, wrap them well.** Railpack, BuildKit, Zot, Caddy, Headscale/Tailscale, Docker Engine API, Laravel. We add the glue and the UX, and avoid reinventing builders, proxies or VPNs.
4. **Own the abstraction where the giants leak.** Users think in services, connections and environments, not containers and labels. We are allowed to depart from "it's all just Docker". Raw Docker stays visible read-only for debugging.
5. **Declarative, idempotent, observable.** Desired state in, convergence out. Every step is persisted, every failure is classified and explained, and every action is auditable.
6. **Secure by construction, not by escaping.** Typed APIs, no shell, signed state, authorisation by construction ([09](09-security.md)).
7. **Boring code.** Laravel conventions, small classes, strong types, generated contracts, tests at boundaries. Stability beats cleverness.

## Non-goals (for now)

- Kubernetes, Nomad or Swarm backends.
- Running arbitrary third-party `docker-compose.yml` stacks with full fidelity. Compose import (D44, [17](17-templates-and-compose.md)) translates an allow-listed subset into ordinary launchroom services (image, app built from the repository, managed database or worker); there is no `compose` source or stack object. New features must not assume "one repo = one service" or that every service is built by us.
- Serverless, edge functions, or Vercel Build Output compatibility.
- Multi-region control plane HA (apps survive a control plane outage, see [02](02-architecture.md)).
- Being a general server-management tool: no host terminals, no "run command on server", no OS package management.
- Hosted/cloud edition concerns (billing etc.). If ever added, they must not fork core code paths.
