# 09: Security

launchroom is exposed to the internet and holds the keys to every server and every app secret. Security is a design constraint, not a feature. This doc is the normative summary: the threat model, the rules every change follows, and the known limitations.

## 1. Core stance

1. **The mesh is transport, not trust.** Being on the WireGuard network grants packets to a few ACL-allowed ports. Every command is authenticated and authorised at the application layer.
2. **Typed intents, never shell.** launchroomd accepts signed, versioned desired state and typed task specs. There is no "run this command on the host" anywhere in the system. User-supplied commands (start, release, seed, cron) run **inside the user's own container** as argv.
3. **Authorisation by construction.** Tenancy and permissions are enforced by structure (scoped queries, policies, tests that enumerate routes), not by remembering to add a check.
4. **Small trusted computing base.** Only launchroomd talks to Docker, and it does so through **dockerd-guard** (D49), a separate small process that holds the socket and forwards only an allow-listed, policy-checked subset of the Engine API (§6). The dashboard never has the Docker socket, and builds and apps never do.

Most critical CVEs in comparable self-hosted platforms (command injection, IDOR, unauthenticated websocket handlers, a fail-open webhook HMAC, fork-PR preview secret leaks, path traversal) violate one of these.

## 2. Threat model summary

| Actor | Can | Must not be able to | Primary controls |
|---|---|---|---|
| Internet attacker | Reach the dashboard, webhook endpoint, public apps | Authenticate, forge webhooks, run code on the control plane | Minimal unauthenticated surface, 2FA/passkeys, rate limits, fail-closed HMAC, no shell, SSRF-safe client, first-run setup token |
| Malicious repo / build / fork PR | Run arbitrary code in a build | Escape the build, read other projects' secrets, poison the registry, reach launchroomd | Rootless BuildKit, no socket, no host mounts, build network denied to private, mesh and metadata ranges, per-build scoped push token, zero secrets for fork PRs |
| Compromised app container | Run code in its container | Reach other projects, launchroomd, the registry, the control plane, metadata or the mesh | Per-environment networks, nftables isolation, hardened container defaults, daemon policy floor |
| Rogue or compromised mesh node | Send packets on the mesh | Deploy, read secrets, pull images | Signed NodeState/tasks, signed daemon requests scoped per server, registry token auth, ACL default-deny, daemons listen on nothing |
| Compromised launchroomd process | Everything launchroomd may do on its server: its keys, this server's secrets, create/stop/remove launchroom's containers through the guard | Host root: privileged or host-namespace containers, host bind mounts (the Docker socket), unpinned images, other containers on the host, an installer of its choosing | dockerd-guard (§6): typed allow-list, the policy floor enforced again outside launchroomd, the update supervisor re-verifies releases against a pin launchroomd cannot write |
| Compromised daemon host | Root on that host, its keys | Affect other servers, read other servers' secrets | Per-daemon keys and secrets sealed per daemon; reports only about its own workloads; revocation |
| Malicious team member | Valid session with some role | Cross-team access, privilege escalation, host root through "legit" features | Policies, scoped bindings, role matrix, invitations ≤ own role, audit log, no host terminal |
| Compromised control plane | Everything in the DB | Trivial host root on the fleet | Signing key in a file, daemon-side policy floor, daemon updates verified against a release key pinned at install |

## 3. Rules (CI-enforced where possible)

**Process & input**
- R1 **No shell strings.**
  - PHP: `spaze/phpstan-disallowed-calls` bans `exec`, `shell_exec`, `system`, `passthru`, `popen`, `proc_open`, backticks, `Process::fromShellCommandline` and string-form `Process::run`. The control plane almost never spawns processes.
  - Go: `forbidigo`/semgrep bans `exec.Command("sh"|"bash", "-c", …)`. Prefer API clients (Docker Engine API, BuildKit client, go-git).
- R2 `--` before user-influenced positional args to any CLI. Git refs are validated (`check-ref-format` rules); `GIT_ALLOW_PROTOCOL=https:ssh`, `GIT_TERMINAL_PROMPT=0`.
- R3 Every identifier has a grammar, validated by the FormRequest/Data object **and** by launchroomd's schema. Host-side names (containers, networks, volumes, paths) are generated from IDs, never taken from user input.
- R4 Users never supply host paths. Container paths are validated (absolute, no `..`, not `/proc`, `/sys`, `/dev`, `/var/run/docker.sock`). Repo-relative paths (root dir, Dockerfile path) are containment-checked after resolving symlinks.

  Checkouts:
  - After every clone launchroomd resolves **every symlink** in the tree component by component, like the kernel: links met on the way are expanded (chains through other links included), `..` only pops components that are already resolved, at most 40 hops.
  - It refuses the build (`config_error`, "unsafe repository content") if any link would leave the checkout or is absolute. A lexical check is not enough: `a/b/s -> ../..` plus `a/b/x -> s/../..` resolves above the root.
  - In-repo links (monorepo shared configs, aliases, dangling links inside) stay allowed.
  - Second layer: launchroomd's own reads of the checkout (preset scan, Dockerfile) go through `os.Root`, which refuses any path or symlink resolving outside the app directory. Railpack's in-process planner reads with plain `os` calls (it has no `fs.FS` seam), so for Railpack the post-clone check is the guarantee.
  - The BuildKit context is sent with symlinks as symlinks (never followed on the launchroomd side).
- R5 Every outbound request to a user-influenced URL (git remotes, webhooks, S3, registries, health URLs) goes through `SafeHttpClient`:
  - scheme allow-list
  - resolve once, reject private/reserved/CGNAT/link-local
  - pin the resolved IP (`CURLOPT_RESOLVE`)
  - no redirects
  - size and time limits

  Alert channels (Slack, Discord, generic webhooks) go through it with no LAN override; Slack/Discord URLs also match their host and path grammar, and generic webhooks are HMAC-SHA256 signed over `<t>.<raw body>` ([15](15-observability.md) §3.5).
  Instance-owner-only, audited override for LAN destinations (backup endpoints, [14](14-backup-and-restore.md) §3; self-hosted git servers, [19](19-git-providers.md) §4: `SafeHttpClient::withPrivateNetwork()` / `UrlGuard::withPrivateNetwork()`, RFC 1918, CGNAT/mesh and ULA only); even then loopback, link-local/metadata and the control plane's own services stay refused. The URL is rebuilt from the normalised host (lowercase, no trailing dot) so the request and the `CURLOPT_RESOLVE` pin always name the same host.
- R6 User Compose files go through an allow-list parser and are never passed to `docker compose` (D44, [17](17-templates-and-compose.md) §2.2–2.3).
  - `App\Support\Compose` parses with symfony/yaml (no PHP objects, constants or custom tags; ≤ 256 KiB, ≤ 64 aliases, ≤ 20 000 nodes, depth ≤ 32) and translates field by field into a `ProjectBlueprint`.
  - Refused fields are never applied: privileged, host network/PID/IPC, devices, sysctls, security options, extra hosts, bind mounts of server paths, the Docker socket, capabilities outside launchroomd's allow-list.
  - String commands are split without a shell and refused when they contain shell syntax.
  - Fuzz/property tests prove every output is a valid blueprint without refused settings.
  - Generated template secrets are generator expressions evaluated when the variable is stored (never in previews, logs or audit entries).

**AuthN / AuthZ**
- R7 A Policy for every model.
  - Every controller action, Inertia action, broadcast channel and job entry point authorises.
  - Routes use scoped bindings; the team is resolved from the loaded resource, not the session.
  - No `Model::find($request->…)`: queries go through team relations. Pest arch tests forbid `::find(`/`::where('id'` in controllers.
- R8 A CI test iterates all routes and asserts `auth` + authorisation for every non-public route (with a small, reviewed allow-list). Cross-team feature tests exist for every resource type.
- R9 Roles: owner / admin / developer / viewer. `TeamRole::permissions()` is the single mapping:
  - owner: every permission;
  - admin: everything except `team.manage`;
  - developer: `projects.manage`, `deployments.create`, `variables.write`, `resources.view`, plus `containers.exec` / `databases.query` when granted per member (R33);
  - viewer: `resources.view`.

  Revealing secrets (`secrets.reveal`), container exec (`containers.exec`) and the database console (`databases.query`) are separate permissions. **New teams** (`POST /teams`, "Create team" in the team switcher, `TeamPolicy::create`): instance owners and anyone who is owner or admin of a team already; developers and viewers are guests and can't. The creator becomes the team's owner (audited `team.created`) and switches to it.
- R10 Fortify with TOTP + **passkeys**. Two-factor is **recommended, not required**, for everyone including instance owners (D33): Get started shows a dismissible "Add two-factor sign-in" card and Settings → Security marks it "Recommended"; no middleware or setup step forces it. Registration is disabled by default (invite only). First-run "create owner" requires a setup token printed by the installer.
  - Password confirmation for sensitive actions: add server, reveal secret, create token, change roles, GitHub App settings, delete project.
  - Failed password confirmations on the confirm-password page and inline (secret reveal) share one budget, 5/min per user and per IP (`App\Http\Auth\PasswordConfirmationThrottle`), and lockouts are logged. The reveal endpoint itself is limited to 10/min per user and 20/min per IP.
  - After confirming, users return only to the page itself (GET) or a same-origin referrer, never to an attacker-supplied `Referer`.
  - Changing the account e-mail needs the current password, notifies the old address and requires re-verification.
  - Deleting an account is refused for the last owner of a team and the only instance owner.
  - Secret-looking fields (`password`, `token`, `secret`, `passphrase`, S3 keys, variable values, …) are never flashed to the session as old input (`App\Support\SensitiveInput`), and the deploy bundle encrypts sessions.
- R11 API tokens ([16](16-api-and-cli.md) §1, D36): Sanctum personal access tokens, **bound to one team**.
  - Abilities `read`, `deploy`, `env:read`, `env:write`, `secrets:read`, `domains:write` are intersected with the owner's role inside `User::hasTeamPermission()` (and `teamIdsQuery()` narrows bindings to the token's team), so every policy enforces them. No ability reaches servers, members, team, exec, exposure or backups.
  - **Mandatory expiry** (7 days to 1 year); a token without `expires_at` or team is refused (fail closed).
  - Prefixed `lr_pat_`, only the SHA-256 stored, plaintext shown once, last used time and IP recorded, creation password-confirmed.
  - Created/revoked/CLI approvals are audited; actions through a token carry `via_token` in the audit meta; tokens are deleted with the membership.
  - Secrets never appear in list responses; reading one needs `secrets:read` plus the reveal permission and is audited.
  - The CLI signs in with a device-code flow approved in the dashboard.

**Secrets**
- R12 Secret attributes are `$hidden` and never serialized. API Resources and Data objects whitelist fields. Revealing is its own endpoint + policy + audit + password confirmation. A test asserts that serialized models contain no secret columns.
- R13 Envelope encryption:
  - a KEK (`LAUNCHROOM_KEK`, a file, not `APP_KEY`) wraps a per-project DEK;
  - values are encrypted with XChaCha20-Poly1305 (libsodium), with associated data `team|project|row|version` to prevent ciphertext swapping;
  - commands `launchroom:keys:rotate-kek` (rewrap) and `launchroom:keys:rotate-project` (re-encrypt), see below;
  - `APP_PREVIOUS_KEYS` for `APP_KEY` rotation.

  Infrastructure secrets (GitHub App key and secrets, DNS provider tokens, S3 creds, alert channel webhook URLs and signing secrets, [15](15-observability.md) §3.5) use the same scheme.

  Rotation:
  - Every ciphertext under a project DEK records the DEK version that made it: versions > 1 prefix the value with `k<version>.` (`k2.lr1:…`); unprefixed values are version 1. The newest version encrypts; older versions keep decrypting until pruned.
  - `launchroom:keys:rotate-project {project} [--prune]` adds a DEK version and, in one transaction under the project lock, re-encrypts variable rows, deployment env snapshots and the variable ciphertexts inside environment revisions (same content, so the immutable revision is rewritten in place). `--prune` then deletes older versions after checking nothing uses them. Build keys are MACs under the current DEK, so each service rebuilds once.
  - `launchroom:keys:rotate-kek [--new-key=PATH]` creates (or reads) the new KEK file (default `<KEK path>.next`) and re-wraps every wrapped DEK (project keys, GitHub App keys, backup destination keys), recording the new `kek_id`, in one transaction; rows already on the new KEK are skipped, rows on an unknown KEK abort. Then replace the KEK file, restart the dashboard, horizon, reverb and scheduler (until then they can't unwrap), and take a control-plane backup (older backups need the old KEK).
- R14 Secrets reach daemons only **sealed to that daemon's X25519 key**, and only for workloads scheduled there.

  Instance-wide secrets go only to servers an **instance owner** admitted, never to a server because a team admin enrolled it or gave it a role:
  - the shared certificate storage credentials and encryption key and the DNS-01 token go only to *shared edges*: the control plane, or edges an instance owner switched on under Servers → Ingress (`servers.shared_edge_approved_at`, audited, recent password). Other edges serve their team's routes with certificates of their own ([05](05-networking-ingress.md) §5);
  - the reusable tailnet key of "bring your own Tailscale" goes only to the control plane and servers an instance owner enrolled or approved.
- R15 Builds get only build-scoped variables, as BuildKit secrets. Never `--build-arg` for secrets.
- R16 Log masking: secret values and their base64/URL-encoded forms are replaced in build and deploy logs. `#[\SensitiveParameter]` on secret-handling functions. Log processors redact known keys. Exceptions never include argv or credentials. Audit entries never contain values.
  - `run_once` (release/seed) tasks carry `maskEnv`: the keys of the service's secrets and of every config variable that references one (`App\Support\Variables\SecretTaint`). launchroomd masks their values with the build masker, plus the password of URL values.
  - Independently, the dashboard masks every stored line of a deployment (its tasks, the deployment source and its workloads) against those values from the deployment's env snapshot (`App\Services\Deployments\DeploymentLogMasker`), because Viewers read deployment logs without the reveal permission.
  - Every log channel taps `App\Support\Logging\RedactSensitiveContext`, which redacts context keys such as `password`, `token`, `secret`, `authorization` at any depth (placeholders are filled after redaction).
- R16a Secret taint: a config variable that references a secret, directly or through other references, is treated as a secret. Browser-inlined keys (`NEXT_PUBLIC_*`, `VITE_*`, …, docs/06 §3.3) may not reference one: refused on save (variable create/update, raw edit, import, shared opt-in) and blocking the deploy like a broken reference. Tainted config variables are never Docker build args (they stay available as BuildKit secrets).

**Webhooks & Git providers**
- R17 HMAC verification on the **raw body** with `hash_equals`, **fail closed** if the secret is empty or the header is missing (an empty secret must never verify). Return 401 on failure (not 200). Deduplicate on `X-GitHub-Delivery`. Verification stays on in local env.
- R17a GitLab webhooks compare `X-Gitlab-Token` with the connection's secret (`hash_equals`); Gitea/Forgejo verify `X-Forgejo-Signature`/`X-Gitea-Signature` (HMAC-SHA256 hex of the raw body); the generic git webhook verifies `X-Launchroom-Signature`/`X-Hub-Signature-256`. All fail closed with 401 (unknown, pending or wrong-provider connections too), dedupe by delivery id, and match services by provider + connection + numeric repository id ([19](19-git-providers.md) §5).
- R18 Map webhooks to services by numeric repository ID and installation ID owned by the team. Verify `installation_id` server-side on install callbacks.
- R19 Minimal GitHub App permissions (contents:read, metadata:read, pull_requests:write, checks:write, deployments:write, statuses:write). Installation tokens are per build, scoped to one repository, and never in URLs or logs. Clone tokens are minted fresh for every build task at lease time and never cached, so no later build reuses one. They are not revoked when the build ends (§7).

  GitHub App details (`App\Actions\Git`, `App\Services\GitHub`):
  - `POST /webhooks/github` is the only public Git route. The app is looked up by `X-GitHub-Hook-Installation-Target-ID` and the HMAC is checked with that app's secret; a missing header, an app without a secret or a non-match is 401. Deliveries are inserted with a unique `delivery_id` (a replay answers 202 `duplicate` and is not processed), the payload goes to an encrypted job, GitHub gets 202.
  - Webhooks map through the delivering app only: app → installation (by `installation.id`, confirmed via the App JWT if unknown) → numeric repository id. Another team's installation id in a payload never resolves.
  - The setup callback's `installation_id` is checked against each of the current team's apps with `GET /app/installations/{id}` (App JWT); only a confirmed installation is stored.
  - App JWT: RS256, `iat` = now − 60 s, `exp` = now + 9 min. Clock skew is read from the `Date` header of responses received anyway, logged when > 30 s, and used to shift JWT timestamps (bounded to ±5 min).
  - Installation tokens: `repository_ids` = one repository plus the minimal permissions for the job (clone/read: contents+metadata read; reporting: checks/deployments/statuses/pull_requests write + metadata read). Listing an installation's repositories is the one installation-wide token, metadata read only. Tokens other than clone tokens are cached (encrypted with `APP_KEY`) until 5 min before expiry, refreshed under a cache lock, and are non-serializable objects that redact themselves.
  - The manifest flow's `state` is a 256-bit single-use token bound to the user and team (60 min). GitHub Enterprise URLs go through the `SafeHttpClient` URL guard before anything is sent; every GitHub call (github.com included) goes through `SafeHttpClient`. The app's `html_url` is stored and rendered only when it is https on the configured web host, otherwise `<web url>/apps/<slug>` is used.
  - App credentials use the envelope scheme with a per-app data key (see [07](07-data-model.md) "Git").
- R19a The manifest is flashed to the next page as a plain array (`ManifestRedirectData::toSession`/`fromSession`), because sessions are JSON-serialized and objects don't survive the round trip. When `APP_URL` isn't publicly reachable (`WebhookReachability`: localhost, `*.localhost`, LAN suffixes, private or reserved IPs, single labels; grammar only, no DNS), a github.com manifest leaves out `hook_attributes` and `default_events`, because GitHub rejects the whole manifest for a non-public hook URL even when inactive. The app then has no webhook secret (webhooks fail closed) and pushes arrive by polling (docs/06 §2). Settings → Git and Get started say so before connecting. GitHub Enterprise Server keeps the webhook (often on the same private network).
- R19b Other providers ([19](19-git-providers.md) §4, §6): GitLab/Gitea tokens (OAuth with PKCE, single-use user-bound state; or a pasted token) are envelope-encrypted, refreshed under a lock, and sealed only to the leasing build server; only launchroomd's in-process clone uses them. Plain git servers get a per-repository ed25519 deploy key generated by the dashboard (the private key never leaves it except as the sealed seed of one task) and **pinned host keys**: read on add (key exchange only), confirmed by the user against fingerprints, never auto-updated; launchroomd accepts only a pinned key and offers only the pinned key types. Provider APIs and SSH go through the R5 checks; a server on a private network needs the instance-owner override (audited), which also allows plain http.
- R20 Fork PRs: no previews by default; when enabled, maintainer approval is required and the preview gets no secrets, no base-environment data and no base-environment network ([06](06-variables-environments.md) §5.3).
  - Fork = head repository id ≠ base repository id (deleted forks count as forks).
  - Policy `approval`: authors with write/maintain/admin start automatically; otherwise a member with `deployments.create` approves in the UI, or a GitHub user with write access applies the `preview` label (the label sender's permission is looked up through the API; a label by anyone else does nothing). A failed author-permission lookup counts as no write access and replaces an earlier value.
  - An approval covers exactly one head commit; new commits wait again. The Approve button posts the head SHA the maintainer saw, and the approval is refused if the fork pushed since. A label approval covers the head of the `labeled` event.
  - Fork previews always have `environments.secrets_withheld`: every secret variable not targeted at the preview itself resolves to `""` (runtime and build); only the preview's own generated database credentials remain.
  - A fork preview never shares or clones a base database. Whatever the "In previews" setting, its stateful services are new empty instances with their own credentials. For a `secrets_withheld` environment no `sharedServices` are emitted, references never resolve against a base revision, no base network is attached and no clone runs, also for older revisions.
  - Promotion never copies a secret value into a fork preview, and Discard/Revert never recreate a withheld secret as a row of it.
  - The previews list and the preview canvas say all of this.
- R20a Preview protection ([05](05-networking-ingress.md) §4): previews require a team member's login or a share link by default; the dashboard session never reaches the apps domain.
  - Edge Caddy forward-auths every request. The dashboard answers from a host-only, signed, expiring cookie that is minted only from a single-use exchange token (members, after the normal dashboard login) or a share-link token (revocable, ≤ 30 days).
  - CI may bypass with a per-project secret header, which Caddy strips before the app.
  - launchroomd only calls forward-auth URLs of its own control plane (local policy); a protected route with any other URL is not served.
  - After a successful forward-auth, Caddy removes the `__Host-lr-preview` cookie from the request before it reaches the app, so preview code never sees a visitor's preview session.
  - Previews share a parent domain with production apps (`{svc}-pr-N.{project}.{apps}` and `{svc}.{project}.{apps}`), so fork code could set cookies scoped to `.{project}.{apps}`. Apps should scope their own cookies host-only (or `__Host-`).
- R20b `launchroom.json` (D45, [18](18-config-as-code.md) §5, §9): the file changes intent only through the dashboard's Actions (every grammar and rule applies again), carries no secret values (schema has no field for one; secret-shaped literals are refused and masked in stored excerpts), is size/depth/count-limited and strict (unknown keys refused, duplicate keys refused). A pull request's file never lifts preview caps: no domains, placement or secret requirements, replicas/CPU clamped; a fork's file additionally adds no services, volumes or connections. Push access to an environment's branch is configuration access to that environment, as it already is for code.

**Daemons, transport, registry**
- R21 Join tokens: 256-bit, hashed, single use, ≤ 60 min, bound to team and roles; optional admin approval. Keys are generated on the host. The install command pins the CP signing key.
- R22 All daemon requests are signed (RFC 9421, Ed25519) with nonce and date checks. All dashboard → daemon payloads are signed over raw bytes with a key-set (`kid`) for rotation. Separate keys for separate purposes: request/response signing, registry token signing, KEK, `APP_KEY`. The control-plane signing key also signs audit checkpoints, always over a domain-separated message (`launchroom-audit-checkpoint-v1\n…`, R34) that can never parse as a daemon payload (launchroomd decodes signed bodies strictly as JSON documents).
- R22a **Client certificates (mTLS, D50, [03](03-daemon-protocol.md) §4.3)**, on top of R22, opt-in per instance (Settings → Security: Off → Check → Require).
  - A launchroom-internal CA (ECDSA P-256, key envelope-encrypted, never leaves the control plane) issues 7-day server certificates over the signed channel for keys launchroomd generates itself; launchroomd renews with a new key at two thirds of the lifetime.
  - The control-plane edge verifies presented certificates for the dashboard's hosts (`verify_if_given`, so browsers are unaffected) and passes the verified fingerprint plus an edge secret (sealed to the control-plane server, read from a file in Caddy) to the dashboard. The dashboard binds the certificate to the RFC 9421 `keyid`'s server and refuses revoked, expired, foreign or forged ones (fail closed).
  - With Require, Caddy refuses `/daemon/v1/*` (except enroll and certificate issuance) without a certificate and the dashboard refuses any non-loopback daemon request without one.
  - Revoking a server revokes its certificates.
  - Residual: Caddy has no revocation list (the dashboard is authoritative, the lifetime bounds the rest); Reverb's shared `/app/*` path isn't covered (content-free nudges, mTLS-covered subscription auth, MACed session frames).
- R23 Daemon local policy floor ([03](03-daemon-protocol.md) §8):
  - digest-pinned images from the allowed registry;
  - no privileged containers, host namespaces, devices, bind mounts or socket mounts;
  - a capabilities allow-list, hardened defaults, log size limits;
  - route address upstreams only in the mesh range or launchroomd's local `LR_ALLOWED_LOCAL_UPSTREAMS`;
  - only per-service workload volumes (`lr-v_…`, owner-labelled, never `lr-system-…`) and environment networks (`lr-e_…`);
  - nftables network isolation of workloads and builds in its own `inet launchroom` table: no metadata, mesh, other environments, other Docker networks, private ranges unless allow-listed locally, or host ports except the ingress; fail closed ([05](05-networking-ingress.md) §1).
- R23a Cross-server traffic ([05](05-networking-ingress.md) §1, §3):
  - Workload ports are published only on the mesh IP (never `0.0.0.0`; policy floor), and only for the peers in `firewall.meshAllow` (servers hosting workloads of the same environment, plus edges for web services), enforced by nftables before Docker's DNAT on every provider.
  - The gate filters by destination, not only by interface: packets for the server's mesh IP that arrive on any interface other than the mesh interface (or loopback) are dropped in prerouting, so a LAN neighbour routing the mesh IP via the server's underlay address reaches neither published ports nor the registry/Valkey forwarders (`TestCrossServerMesh` covers it).
  - The source IP of a mesh packet is authenticated by WireGuard, but the mesh stays transport, not trust: the per-peer allow-list narrows the Headscale ACL (role tags) to environments, and database passwords still apply.
  - The ambassador is the only container on launchroom networks that may reach the mesh, through its own bridge and only to its listed upstreams (mesh addresses on 31000–32999, local policy); its image is built locally from launchroomd's binary.
  - The control plane's Valkey is reachable on the mesh only from edges (nftables + ACL tag rule). The edges' Valkey user is restricted to the `lr-caddy` keys and exactly the commands caddy-storage-redis, its lock library, go-redis' handshake and launchroomd's mirror send, listed one by one, never categories (a category such as `+@connection` would add `CLIENT KILL/PAUSE/LIST` on the dashboard's main Valkey; `TestMultiEdgeSharedStorage` asserts they are refused). Its password is generated by the dashboard, envelope-encrypted and sealed to each edge.
  - Link probes ([05](05-networking-ingress.md) §2.1): launchroomd's only other listener on the mesh is `<mesh IP>:7400`, nftables-allowed only from the peers in `system.linkProbe`. A probe is answered only for a listed peer whose TCP source is its mesh IP, with a fresh, unreplayed hello signed by that peer's launchroomd key (from the signed NodeState), and the answer is signed in turn. Transfers are size-capped (16 MiB), one at a time and rate-limited per peer; `LR_LINK_PROBE=off` switches it off locally. `TestLinkProbeMesh` covers unlisted peers, stolen identities and replays.
  - Residual: a compromised peer that legitimately consumes an environment can reach that environment's published ports (application credentials remain the boundary).
- R24 Registry: auth always on; short-lived tokens; pull-only per server, scoped to repositories of its workloads; push only from build tasks, scoped to one repository. Bound to localhost and the mesh, never public (`App\Actions\Registry`, `App\Services\Registry`, daemon `internal/registry`, `internal/build`).
  - ES256 tokens ≤ 5 min from a dedicated key file.
  - All-or-nothing scope grants: `mirror/**` pull; `apps/<svc>` pull only if that image is in the server's latest NodeState; `apps/<svc>` + `cache/<svc>` push only with a build task the server currently holds.
  - The public realm (`GET /registry/token`) only narrows tokens it issued and never extends their lifetime.
  - Tests cover escalation attempts: push to mirror, foreign repositories, other services, unleased/finished/expired/other-server tasks, forged and expired tokens.
  - Builds: rootless BuildKit without `--privileged`, reachable only through a Docker exec stream (no TCP API in the build network namespace), build variables only as BuildKit secrets (build args only for declared non-secret `ARG`s), masked logs (raw, base64, URL-escaped), symlinks escaping the checkout refused inside the app folder and removed outside it before anything reads the tree (R4), clone tokens never in URLs, config or disk. An integration test checks that a build secret is in no layer, config or history of the pushed image.
- R33 **Interactive sessions** (Console and Data, [03](03-daemon-protocol.md) §11, D35). There is no host terminal and no "run on server": a console is a shell **inside one workload container**, a data session runs the database's own client inside the database container, both through typed task + signed commands.
  - Permissions: `containers.exec` (Console) and `databases.query` (Data tab: browsing shows data too) are separate from deploying. Owners and admins have them, developers only when an owner/admin grants them per member (`team_user.permissions`, audited `team.member_permissions_changed`; only grantable permissions, only by someone who holds them, never to viewers). Policy `InteractiveSessionPolicy` at every entry point (open, authorize channel, command, end, recording).
  - Re-authentication: opening a session needs a password confirmed in the last 15 minutes (inline, the shared 5/min throttle).
  - Tickets: `lr_st_` + 256 bit, SHA-256 stored, 60 s, single use, bound to the user and the session's channel. The browser can join `private-session.*` only through the ticket endpoint, launchroomd only through its signed broadcasting auth for a session task it holds.
  - Integrity: frames carry an HMAC-SHA256 under a per-session key with direction and session in the MAC input and strictly increasing sequence numbers; dashboard commands are Ed25519-signed and time-boxed (±60 s), op ids single use. Reverb (and anyone on its path) can drop frames but not forge or replay them.
  - Audit: `session.console_started` / `session.data_started` (who, service, environment, replica slot, server, shell or engine) when opened, `session.console_ended` / `session.data_ended` (duration, end reason, exit code, bytes) when the task completes, and `database.statement_executed` **per statement** before it is sent (verb, length, SHA-256 of the text, read-only or writes allowed, op id): never the statement text or results (R16). The text lives only in the user's query history, encrypted under the project DEK (AD `query|<team>|<project>|<qry_ id>`).
  - Limits and kill switches: [03](03-daemon-protocol.md) §11.3 (idle 15 min, absolute 60 min, heartbeats re-authorize, fail closed without the dashboard, instance switch `LAUNCHROOM_INTERACTIVE_SESSIONS`, server-local `LR_INTERACTIVE_SESSIONS`).
  - launchroomd policy floor: [03](03-daemon-protocol.md) §11.4 (only managed workload containers in the verified NodeState, shell allow-list, never privileged, never a `User` override, credentials only in exec env, Redis command deny/allow lists). Read-only mode is a seatbelt against accidents, not a permission boundary.
  - Transcripts are off by default; opt-in per team, output only, masked, ≤ 1 MiB, reading them needs the exec permission plus a fresh password and is audited ([03](03-daemon-protocol.md) §11.5).
- R25 Revocation: removing a server denies its key immediately (so it gets no new registry tokens, leases or secrets), expires its Headscale node and revokes its client certificates.
  - The Headscale node is identified by the pre-auth keys issued to that server, never by the mesh address it reports.
  - Registry tokens it already holds stay valid until they expire (≤ 5 min). The dashboard does not yet prompt rotation of the secrets the server held (§7).
  - "Replace server" ([14](14-backup-and-restore.md) §9.14) is the one exception: the revoked server's key may still fetch its own last NodeState, which is an empty decommission state with no secrets and the mesh client off, so a dead server that comes back stops its workloads. Leases, reports, nudges and registry tokens stay denied. A decommission state that fails validation fails the replacement instead of leaving the old full state latest.

  Mesh identity: with the built-in Headscale, a server's mesh addresses and node come only from Headscale.
  - `MaintainMesh` links the node that registered with a pre-auth key issued to that server (`servers.mesh_auth_key_ids`) and copies the node's addresses. A key id two servers claim links no one. A reported address that differs only triggers maintenance.
  - With your own Tailscale (no API), a reported address is taken only inside 100.64.0.0/10 and only if no other server has it.

**Audit log**
- R34 **Tamper-evident audit log (D50).** `audit_events` is append-only (a Postgres trigger refuses UPDATE/DELETE) and hash-chained: `hash = sha256(prev_hash ‖ "\n" ‖ canonical_json(fields))`, genesis 64 zeros, one global chain in id order, writers serialized by a transaction advisory lock ([07](07-data-model.md)). Against someone who can write the database:
  - **Signed checkpoints** (`audit_checkpoints`): every 10 minutes when there are new entries (`launchroom:audit:checkpoint`), the newest entry's id, hash and position are signed with the control-plane signing key (a file, not the DB) over `launchroom-audit-checkpoint-v1\n` ‖ canonical JSON (protocol/SIGNING.md §6). Rewriting history behind a checkpoint means forging an Ed25519 signature.
  - **Verification** (`php artisan launchroom:audit:verify [--from-checkpoint]`, scheduled every 10 minutes from the last good checkpoint and daily from genesis): recomputes every hash and link and checks every checkpoint (signature against every key this control plane has signed with, retired ones included, so checkpoints survive a key rotation; its entry, hash and position). It names the first problem: an edited entry (its hash doesn't match), a deleted or reordered one (the link to the previous hash breaks), entries removed after a checkpoint (the chain is shorter than the checkpoint says), or a forged checkpoint. The result is kept in `instance_settings.audit_verification` and shown as "Audit log verified ✓ up to <time>" (Activity, Settings → Security); a failure is shown with the problem (red, with an icon and the words, never colour alone).
  - **Forwarding** to sinks the instance owner configures (Settings → Security → Audit log forwarding, recent password, audited `audit.sink_*`):
    - **syslog over TLS** (RFC 5425 octet counting, RFC 5424 messages, the peer verified against the system roots or a CA the owner pastes);
    - **HTTPS webhook** (JSON batches, `X-Launchroom-Signature: t=<unix>,v1=<hex HMAC-SHA256(secret, "<t>.<raw body>")>` like alert webhooks);
    - **S3-compatible storage** (append-only JSON Lines objects per batch, never overwritten, `If-None-Match: *`; with Object Lock on the bucket each object gets a retention period in governance or compliance mode).

    Every entry carries the exact hashed fields plus `prevHash`/`hash`, and checkpoints are forwarded too, so a copy can be verified outside launchroom with only the control plane's public key (wire format: [15](15-observability.md) §4). Credentials (webhook URL and secret, S3 keys) are envelope-encrypted per sink; every destination goes through R5 (`SafeHttpClient`/`UrlGuard`: https only, resolved once and pinned, no private ranges unless the owner allows the private network for that sink, audited; loopback, link-local and the control plane's own services never). Delivery is at least once, in order, per sink from its own cursor: right after an entry commits, and every minute for retries with backoff (30 s doubling to 1 h). A failing sink keeps its backlog in the database and catches up; the UI shows delivered up to, behind by, the last error and the next attempt.
  - Residual: someone with the signing key file and the database can rewrite everything locally; forwarded copies are the defence. Deleting the newest checkpoints together with the tail after them isn't detectable locally, but "verified up to" falls behind (checkpoints are due every 10 minutes) and the sinks have the entries.

**Ingress & exposure**
- R26 HTTPS everywhere with automatic certs. HSTS on by default for custom domains once a cert is issued (no preload unless opted in). Security headers on the dashboard: CSP with nonces, `frame-ancestors 'none'`, nosniff, strict referrer.
  - Dashboard (`App\Http\Middleware\SecurityHeaders`, every response): `Content-Security-Policy` with a per-request nonce (`Vite::useCspNonce()`; inline scripts in `app.blade.php` carry `Vite::cspNonce()`): `default-src 'self'; script-src 'self' 'nonce-…' 'strict-dynamic'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob: https://avatars.githubusercontent.com; font-src 'self' data:; connect-src 'self' <Reverb ws(s) origin>; worker-src 'self' blob:; object-src 'none'; base-uri 'self'; frame-ancestors 'none'; form-action 'self' https:` (https form posts: the GitHub App manifest flow posts to github.com or a GHE host).
  - In `APP_ENV=local` the Vite dev server (`public/hot`), its HMR socket and `ws://localhost:*` (Reverb on its own port) are added.
  - `Strict-Transport-Security: max-age=31536000` in production over https only (no `includeSubDomains`, no preload). Also `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`, `Referrer-Policy: strict-origin-when-cross-origin`, a `Permissions-Policy` that denies camera/microphone/geolocation/payment/usb, and COOP `same-origin`. The image's Caddyfile sets the static subset for files that never reach PHP.
  - Custom-domain routes: HSTS on by default per domain, sent by the edges once the certificate is issued, `max-age=31536000`, no `includeSubDomains`, no preload ([05](05-networking-ingress.md) §5).
- R26b **Dashboard domain** (D43, [05](05-networking-ingress.md) §4.1). Instance owners only; every change needs a recently confirmed password and is audited.
  - The HTTPS test request is not a user-influenced fetch (R5): it is pinned to the control-plane server's own public addresses that DNS matched, or to the operator-configured `LAUNCHROOM_DASHBOARD_PROBE_ADDRESS`, no redirects, no proxy, 5 s. It passes only on an HMAC of a fresh nonce under a key derived from `APP_KEY` (`/.well-known/launchroom/dashboard` is public and answers nothing else).
  - Over https the session cookie is `Secure` and `__Host-` (host-only: an app on a sibling domain can't toss it). Sessions don't cross origins, so everyone signs in again after a switch.
  - Earlier origins redirect browsers with `308` + `no-store` and answer only launchroomd's endpoints for good (plus API, CLI, webhooks and the installer during the transition window).
  - launchroomd follows a pushed origin only after it answered with a response signed by the pinned key, never uses an unverified one for forward auth, keeps its configured URL as fallback and can refuse following (`LR_CONTROL_PLANE_FOLLOW=off`, [03](03-daemon-protocol.md) §8). The dashboard only renders a server's forward-auth URL on an origin of its own that the server reported.
  - Passkeys keep their relying party when the new host is below it; otherwise the switch screen counts the passkeys that stop working.
  - Lock-out recovery needs host access: `./launchroom dashboard-domain-reset`.
- R27 Databases, Redis, the registry, launchroomd's API and the Headscale admin are never publicly routed. There is no toggle to expose a database publicly; the `exposure.manage` permission is reserved for it.
- R28 Preview environments are protected by default and `noindex`.
- R29 Restricting the dashboard (IP allow-list, mesh-only) with a separate public webhook receiver is not implemented; see the [roadmap backlog](12-roadmap.md#backlog).

**Supply chain**
- R30 Release artifacts (install script bundle, daemon and dashboard images) are signed with cosign plus SLSA provenance. The installer verifies before executing. launchroomd self-updates only to versions signed by the release identity **pinned at install**, and never downgrades.
  - `.github/workflows/release.yml` (tag `v*` only, `VERSION` must match the tag) builds multi-arch images with buildx SBOM + SLSA provenance (`mode=max`), cosign keyless signs every image digest and `launchroom-deploy.tar.gz` (sigstore bundle `.sigstore.json`, plus `actions/attest-build-provenance`), and verifies both in-workflow against the pinned identity.
  - **The identity** is the Fulcio certificate SAN `https://github.com/vantezzen/launchroom/.github/workflows/release.yml@refs/tags/v<version>` with OIDC issuer `https://token.actions.githubusercontent.com`. Signing never happens in a reusable workflow (that would change the SAN).
  - Who can sign: the SAN binds the tag, not the branch, so only maintainers can create `v*` tags (repository tag ruleset), the signing jobs run in the protected `release` environment (v* tags only, required reviewer), and the workflow refuses tags whose commit is not on the default branch ([11](11-installation-and-dev.md)).
  - `install.sh`/`server.sh` verify with a digest-pinned cosign container and the **exact** SAN for the bundle's `VERSION` (a signature for another tag fails). An unsigned release is accepted only with `--allow-unsigned`/`LR_ALLOW_UNSIGNED=1` (development builds), a present-but-invalid signature never. The installers write `trust.json` (issuer, identity prefix, release base) into launchroomd's data directory **once** and never overwrite it.
  - launchroomd's `self_update` (`internal/update`) takes only a version from the dashboard. It downloads the bundle itself from the pinned release base, verifies it in Go with sigstore-go (Fulcio chain, SCT, Rekor inclusion, observer timestamp, exact SAN + issuer; Sigstore trusted root refreshed via TUF, with the root embedded at build time as offline fallback), refuses versions not strictly newer than its own (semver), and takes every image digest from the verified bundle (the dashboard's `image` must match it).
  - Key-based verification (`trust.json` `publicKey`) exists for private builds and tests only.
  - Trust model for joined servers: `server.sh` comes from the control plane, which the server already trusts at enrollment; its pinned identity is a constant in the script.
  - "Never downgrades" holds for launchroomd's self-update. The installers accept `--version`, so running an installer by hand can install an older release (§7).
- R31 Infrastructure images (Caddy, Zot, BuildKit, Tailscale, Headscale, Postgres templates) are pinned by digest in release manifests. GitHub Actions are pinned by SHA. `composer audit`, `npm audit`, `govulncheck` and Dependabot run in CI.
  - `deploy/release/build-bundle.sh` writes the release's `control-plane/images.env` with the launchroom images by digest and refuses any `LR_*_IMAGE` without one (including `LR_COMPOSE_IMAGE`, the docker CLI image the update helpers run in).
  - The edge Caddy's digest (`launchroom-caddy`, host network) is compiled into the signed launchroomd of the same release, not taken from any file the dashboard or the host's `.env` writer controls; the release workflow refuses to sign a launchroomd that doesn't print it ([05](05-networking-ingress.md) §4).
  - `.github/workflows/ci.yml` runs the audits, govulncheck, actionlint and a protocol-drift check; `.github/dependabot.yml` covers actions, Go, composer, npm and Dockerfiles.
  - The release images' own bases and the Dockerfile frontend are pinned by digest as well (`daemon/Dockerfile`, `deploy/images/*/Dockerfile`: every `FROM` and `# syntax=`), checked by `deploy/release/check-pinned-images.sh` in `make lint-deploy` and CI. Dependabot's docker ecosystem bumps `FROM` lines; base images held in `ARG` defaults (dashboard, Go toolchain) are bumped by hand with tag + digest.
  - Infrastructure images are not mirrored into Zot; see the [roadmap backlog](12-roadmap.md#backlog).

**User and remote images**
- R32 Project icons (docs/08 §13) are the only images the dashboard stores from users or remote sites.
  - Fetching (`App\Services\Projects\FaviconFetcher`, queued `FetchProjectIconJob`, never in a request): only the project's primary production domain, through `SafeHttpClient` with `http`/`https` only (resolve once, reject private, loopback, link-local/metadata, CGNAT/mesh and ULA addresses, pin the IP, no proxy), redirects followed by hand (at most 3, every hop vetted again, never to another scheme), 3 s connect / 5 s per request / 20 s per lookup, the page capped at 1 MiB and every image at 256 KiB. Candidates: `<link rel=icon|apple-touch-icon>` (HTML5 parser, nothing executed), then `/favicon.ico`.
  - Content (`App\Support\Images\IconSanitizer`, also for uploads): the format comes from the bytes, never the name or `Content-Type`; only PNG, ICO, SVG and WebP. Nothing is stored as received: PNGs are re-encoded (chunks CRC-checked, the pixel stream inflated to exactly the size IHDR implies, filters checked, one new IDAT, metadata/APNG/trailing bytes dropped; at most 1024 px), ICOs become a new PNG (embedded PNG re-encoded, or the bitmap decoded with its mask), WebP containers are rebuilt with the bitstream chunks only (no EXIF/XMP/ICC, no animation), SVGs are rebuilt from an element/attribute allow-list (no DOCTYPE or entities, no scripts, event handlers, `foreignObject`, images, links, animation, `href` other than `#id`, `url()` other than `url(#id)`, `@import`). The dashboard image has no GD/Imagick, so this is launchroom's own code (pure PHP, `zlib`, DOM).
  - Storage and serving: the private disk under `project-icons/<project public id>/<content hash>.<ext>` (paths generated, never user input); `GET /p/{project}/icon` authorizes `view` on the project and answers with a fixed `Content-Type` (`image/png|webp|svg+xml`), `nosniff`, `Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; sandbox`, `Cross-Origin-Resource-Policy: same-origin` and `Content-Disposition: inline`. The UI only uses it in `<img>`.
  - Commit author avatars are never fetched or stored: the deployment keeps a URL rebuilt by `App\Support\Git\GitHubAvatar` (only `https://avatars.githubusercontent.com/u/<id>` or `/<login>`), which is the one remote host in `img-src`; the `<img>` sends no referrer.

**Docker access**
- R35 **launchroomd never holds the Docker socket** (D49, §6). It reaches Docker through dockerd-guard's socket (`DOCKER_HOST=unix:///run/launchroom/docker.sock`); only the guard container mounts `/var/run/docker.sock`. A new Engine API call, field or system container shape needs a guard change with a refusal test (`internal/guard`), not only a launchroomd change. Archive uploads into volume helpers (volume restores and imports, D55) are re-encoded by the guard to one shape: destination `/`, names under `data/` (clean, relative), files, directories, symlinks and hard links to earlier files only, setuid/setgid bits and PAX records dropped, device nodes refused.

**Model providers (Generate a Dockerfile)**
- R36 **Generated Dockerfiles are opt-in, minimal-share and verified** (D48, [20](20-dockerfile-generation.md)).
  - Opt-in twice: the instance owner turns the provider on (fresh password, audited) and each team turns it on; both off by default. Starting needs `update` on the service.
  - The provider API key is envelope-encrypted (own DEK, `ai_provider:<provider>` / `ai_provider|<provider>`, rotated by `RotateKek`), `$hidden`, never in Data objects, props, logs or audit meta (only the last four characters are shown). The official SDK gets it explicitly with a fixed base URL (`api.anthropic.com`), no `ANTHROPIC_*` environment or credential files, no proxy, no redirects; SDK exceptions are mapped to user-safe messages and never chained (their request carries the key header).
  - What is sent is minimal and filtered twice, fail closed: launchroomd selects a listing and allow-listed manifest/entry files through `os.Root` with a deny-list (`.env*`, keys, certificates, credential files, compose files); the dashboard re-applies the deny-list and limits and scrubs credentials and the project's secret values (and their encodings). Variable values, env snapshots and tokens are never part of a prompt; build-time variables only by name. The scrubbed context is stored encrypted and pruned.
  - Model output is untrusted: strict JSON-schema parsing, then `DockerfileLinter` (digest-pinnable `FROM` pinned through the registry mirror, no `--network=host`/`--security`/SSH mounts/remote `ADD`, no secrets in `ARG`/`ENV`/`LABEL`, secret mounts only for the service's build variables, no `VOLUME`/`ONBUILD`/`syntax=`). The verification build gets build variables as names only; the verification run gets `PORT` only, on the environment network, with the service's limits and launchroomd's policy floor. Only a verified Dockerfile can be used; build tasks resolve the reference within the team and service.

**Data transfers**
- R37 **Data transfers** (D55, [14](14-backup-and-restore.md) §12). Imports and exports need `backups.manage` (owner/admin); starting an export and making a download link need a password confirmed in the last 15 minutes (tokens: the opt-in, password-confirmed ability `data:transfer`), and every import and export is audited without credentials or contents. Transfer chunks are encrypted at rest under a per-transfer key wrapped by the KEK, checked against per-chunk SHA-256 digests, and used by launchroomd only after the file digest from the signed spec matches. Download links are 256-bit random tokens stored as SHA-256, one use, 5 minutes, bound to the user who made them. A source connection URL is sealed to the target server and task when the user submits it and never stored readable or logged; the display label carries no user or password. Restores run with the target image's tools in a throwaway on the database's environment network, firewalled like a workload; volume archives are converted entry by entry (no traversal, absolute names, escaping links or devices).

**Moves** (D56, [14](14-backup-and-restore.md) §13)
- R38 **Moving a server or the control plane** never weakens the transport.
  - Server moves need ManageServers; starting and retiring need a recent password and the server's name typed; every step is audited. Data moves only through D55's signed tasks and encrypted transfers (R37): no server talks to another directly, no SSH or rsync.
  - The control-plane move token (`lrmv1.…`) is shown once to an instance owner with a recent password; only SHA-256 of its 256-bit secret is stored; it expires 24 h after issue unless claimed and stops working when the move ends. The secret authenticates the new machine's requests (Bearer, `hash_equals`), and only for its own move.
  - The new machine's X25519 key binds the move: both sides derive the comparison code from SHA-256 over the move id and that key, and an owner must type it from the new machine's terminal to approve (the page never shows it), so a stolen token claimed with another key can't be approved with the code the real machine prints, and the real machine gets an error instead of a code. A second key is refused once claimed. The join token, the hand-over's chunk key and the key bundle exist only sealed to that key (`crypto_box_seal`); the old dashboard can't open them afterwards; hand-over chunks are XChaCha20-Poly1305 with associated data binding move and index.
  - Every answer to the new machine is signed with the control plane's Ed25519 key pinned from the token and carries `kind: launchroom-move/1`, which no daemon document has (strict decoding), so it can't pass as a desired state. The new machine refuses unsigned or wrongly signed answers, checks every chunk's SHA-256 and the digest over them, and refuses a key bundle whose signing key isn't the pinned one.
  - The receiver (`launchroom:move:receive`) runs on the operator's new server, not in a request, and only contacts the address in the token (or `--url`/`--move-url`), so it uses Laravel's HTTP client with TLS verification (`--ca-file` for a private CA) instead of SafeHttpClient: the old dashboard may be on a private network.
  - During the hand-over writes are frozen (web forms refused, API, webhooks and CLI endpoints 503, scheduler skipped); launchroomd's and the move endpoints keep working. Aborting is possible only until the new machine reports that its dashboard runs.
  - launchroomd's `preparedVolumes` creates NodeState volumes that no workload mounts, without an owner label. Names still pass the volume-name grammar (no system volumes), and mounts are checked as before: a volume without a service label may be mounted by the first workload that claims it, like a legacy volume.

## 4. PR review checklist (security-relevant changes)

1. Does any user-controlled string reach a process, path, URL fetch, Docker API field, Caddy config, YAML or raw SQL? What validates it, and is the sink API safe by design?
2. Is the resource loaded through a team-scoped query, with a policy check at *this* entry point (HTTP, Inertia action, channel, job, console)?
3. Could a response, log line, exception or broadcast include a secret?
4. Does this widen what launchroomd accepts? Does the local policy floor still hold?
5. Does it need the Docker socket, host mounts, capabilities or a new public port? (The default answer is no.) Does dockerd-guard need to allow something new, and is that the narrowest shape (§6)?
6. Is there a regression test with an attack payload (`;id`, `$(id)`, newline, `--upload-pack=…`, `../`, a cross-team ID)?

## 5. Trust boundary

The public security policy states the trust boundary explicitly: **user-provided commands run inside the user's own container. Anything that lets a project member reach host root, another team's data, or another server is a vulnerability.**

Within a team, `secrets.reveal` is not a boundary against Developers: they can deploy code or commands that print the environment. The permission keeps values out of the UI and the API, not out of the apps the Developer controls.

## 6. dockerd-guard: launchroomd without the Docker socket (D49)

Access to `/var/run/docker.sock` is root on the host. If launchroomd held it, any code execution inside launchroomd (a parser bug, a dependency) would be host root. The socket belongs to **dockerd-guard**, and launchroomd only reaches a narrow, typed subset of the Engine API on the guard's own socket.

### 6.1 The split

- **Own process, own container, own binary.** `dockerd-guard` (`daemon/cmd/dockerd-guard`, package `internal/guard`) ships in the launchroomd release image (`/usr/local/bin/dockerd-guard`, same signed digest, so there is nothing new to pin) and runs as its own compose service next to launchroomd in both installs (`deploy/daemon/compose.yml`, `deploy/control-plane/compose.yml`): `network_mode: none`, `cap_drop: [ALL]`, `no-new-privileges`, read-only root filesystem, the Docker socket and the named volume `guard-socket` mounted; healthcheck `dockerd-guard healthcheck`. launchroomd mounts only `guard-socket`, uses `DOCKER_HOST=unix:///run/launchroom/docker.sock` (0600), does not mount the Docker socket, and starts after the guard is healthy.
- **Configuration only from the install's `.env`**, never from launchroomd: `LR_INTERACTIVE_SESSIONS`, `LR_SYSTEM_IMAGE_DIGESTS`, `LR_GUARD_ALLOWED_REGISTRIES` (extra workload registries; default: only the local registry `127.0.0.1:5000/`), and on rootless hosts `LR_GUARD_ROOTLESSKIT_API`. launchroomd cannot write the `.env` (it only mounts its data folder).
- **launchroomd keeps all of its own checks** (defence in depth): `policy.Check`/`CheckContainer`, the managed-label scoping in `internal/docker`, the exec/session rules. The guard's refusals come back as HTTP 403 with a `dockerd-guard:` message; `internal/docker` maps "not managed by launchroom" to `ErrNotManaged` and everything else to `ErrGuardRefused`.

### 6.2 Requests: parsed strictly, denied by default

1. **Route table** (`route.go`): method + path must be one of ~35 Engine API operations launchroomd uses (ping/version/info/df, container list/create/inspect/start/stop/pause/unpause/wait/remove/logs/stats/archive, exec create/start/resize/inspect, network list/inspect/create/remove/connect/disconnect, volume list/inspect/create/remove, image inspect/pull/import/remove). Everything else is refused: build, commit, export, attach, kill, update, rename, prune, events, plugins, swarm, secrets, auth, image load/save/push/tag. The API version prefix must be `v1.40`–`v1.99`; escaped characters, `//`, `.` and `..` segments are refused; every path parameter has a grammar (names `^[a-zA-Z0-9][a-zA-Z0-9_.-]{0,127}$`, exec ids 64 hex, image references without `..`/`//`).
2. **Query parameters** are allow-listed per route with a validator each (booleans, bounded integers, enums, grammars); unknown or repeated keys are refused. Force flags that launchroomd never sets must be false (`DELETE /containers/x?v=`, `DELETE /volumes/x?force=`, `DELETE /images/x?force=`). Container stops are bounded like `WorkloadSpec.stop` (`t` ≤ 300 s, no `signal`, D42).
3. **Headers**: only `User-Agent`, `Content-Type` and, for pulls, `X-Registry-Auth` are forwarded.
4. **JSON bodies** are read up to 1 MiB, decoded into the moby API structs with unknown fields refused and trailing data refused, then checked, and the body that reaches Docker is **re-encoded from the checked struct**: a duplicate or case-folded key (`"privileged": true` after `"Privileged": false`) is decided once, by the same decoder that the check used.
5. **Objects are inspected first.** Every operation on an existing container, network or volume inspects it upstream; only objects labelled `launchroom.managed=true` with a valid `launchroom.server` id qualify (plus, inspect only, launchroomd's and the guard's own compose containers, which self-update needs to find; never the dashboard, Postgres or anything else). The request is then forwarded with the **full id from the inspection**, so a name cannot be re-pointed between the check and the call. Lists (`/containers/json`, `/volumes`) get the managed label filter added; filters are allow-listed.
6. **Execs** are tracked: `start`, `resize` and `inspect` only work on exec ids the guard created itself. Exec create: never privileged, no working dir or detach keys, argv 1–256 items, environment `NAME=value`, user by grammar; a TTY exec (Console) only into workloads, as the container's own user, and only when `LR_INTERACTIVE_SESSIONS` allows consoles (`off`/`data` refuse every TTY exec, D35); system containers allow only launchroomd's own commands (`buildctl dial-stdio` exactly in BuildKit, `tailscale …` in the mesh client, nothing in Caddy or the ambassador). Upgraded exec streams are relayed with half-close, so restores that close stdin keep their output.
7. **Archives**: reading is allowed for launchroom's containers; uploads into workloads and volume helpers pass through (restores can be large), uploads into system containers are re-written entry by entry (regular files and directories only, every entry below the class's root: `/data` for Caddy, `/home/user/.config` for BuildKit, `/config` for the ambassador; nothing for the mesh client).
8. **Images**: pulls only by digest (`fromImage` + `tag=sha256:…`, a registry host required); imports only of launchroomd's local images (`lr-…`, `launchroom-local/volume-helper`) from the request body (`fromSrc=-`, never a URL) with `CMD`/`ENTRYPOINT`/`LABEL`/`ENV`/`USER`/`WORKDIR`/`EXPOSE` changes; removal only of references under an allowed registry or `lr-`/`launchroom-local/`, never forced. Networks: bridge, IPv4, one subnet, default IPAM, no driver options, never overlapping the mesh, loopback, link-local or multicast ranges. Volumes: `local` driver without `DriverOpts` (they can bind host paths), generated names, managed labels.

### 6.3 Container classes

`POST /containers/create` is classified by name and labels. Each class has a field allow-list (`nonZeroOutside`: every field of `Config`, `HostConfig` incl. `Resources`, and the endpoint settings that is not on the list must be zero) and its own checks:

| Class | How it is recognised | What it may have (everything else refused) |
|---|---|---|
| Workload (default: apps, databases, run_once, restore/verify throwaways) | any other generated `lr-…` name (`lr-system-…` reserved) | the full policy floor `policy.CheckContainer` with the guard's own policy (digest-pinned image from an allowed registry, cap-drop ALL + allow-listed caps, no-new-privileges, init or a typed stop, log limits, CPU/memory/pids limits, ports only on the mesh range) plus: networks must be launchroom's (no `host`/`bridge`/`container:`, no `lr-system-…`), volumes must be launchroom's workload volumes (`lr-…`, not `lr-system-…`, existing ones managed and `local`) |
| Caddy | `lr-system-caddy` + `launchroom.system=caddy` | host network, image digest in `SystemImageDigests[caddy]`, cap-drop ALL + `NET_BIND_SERVICE`, no-new-privileges, only its two `lr-system-caddy-*` volumes, limits, no ports |
| Mesh client | `lr-system-tailscale` + `system=tailscale` | host network, pinned digest, cap-drop ALL + `NET_ADMIN`/`NET_RAW`, exactly `/dev/net/tun` (`rwm`), its state volume |
| BuildKit | `lr-system-buildkit` + `system=buildkit` | its own network `lr-system-build`, pinned digest, exactly `seccomp=unconfined` + `apparmor=unconfined`, emptied masked/read-only paths (systempaths), extra hosts by grammar, no added caps, pids limit, its state volume |
| Ambassador | `lr-system-ambassador` + `system=ambassador` | launchroomd's locally imported `lr-…` image, its own network, cap-drop ALL, no-new-privileges, read-only root, init, `net.ipv4.ip_unprivileged_port_start=0` (namespaced) and nothing else, its config volume |
| Volume helper | `lr-vol-<12 hex>` + `launchroom.helper=volume` | `launchroom-local/volume-helper:1`, no network, cap-drop ALL, one volume at `/data`; **never started** (the guard refuses the start) |
| Update supervisor | `lr-system-update-<task>` + `launchroom.role=updater` (§6.4) | see below |

Starting, stopping, removing, logs, stats, exec and archive calls are then also limited by class: no exec and no archive call ever reaches an update supervisor, no exec reaches Caddy or the ambassador.

### 6.4 Self-update through the guard

The update supervisor (docs/11 §4) needs the real Docker socket: it runs the new release's installer with `docker compose`. It is the **one** container with host access the guard lets launchroomd create, in exactly one shape:
- the image is the image id of the install's running launchroomd container (the guard finds its own compose container by hostname, its compose project and working dir, and the launchroomd container of that project);
- entrypoint exactly `/usr/local/bin/launchroomd update-supervisor`, one argument: a `…/update/tasks/<task>/plan.json` path inside the install folder;
- no environment, user, working dir, network, ports or capabilities;
- exactly two bind mounts: the socket path the guard itself has, and the install folder at its own path;
- labels exactly managed/role/task.

Afterwards launchroomd can start, wait for, read the logs of and remove it, but can never exec into it or upload files into it (that would replace its code before it starts).

A compromised launchroomd still writes the plan and the staged bundle. So on a guarded install (the installers write `guard/trust.json`, a copy of the release pin outside launchroomd's data folder) the supervisor **verifies the release again itself** before anything runs (`internal/update/guarded.go`):
- the signed tarball and its sigstore bundle staged next to the plan must verify against `guard/trust.json` (offline, with the Sigstore root embedded in the supervisor's binary; it has no network);
- the bundle's version must equal the plan's target and be newer than the supervisor's own version;
- the bundle is extracted into `guard/update/<task>/bundle` and the image pins are taken from it (not from the plan);
- the backup for a rollback is kept in `guard/update/<task>/backup`, so a rollback never restores files launchroomd could have planted.

launchroomd's own verification (before staging) stays.

### 6.5 What still needs elevated rights

The guard removes launchroomd's Docker socket; it does not make the server rootless. What is left, by component:

| Component | Privilege | Why | Bound |
|---|---|---|---|
| dockerd-guard | the Docker socket (= root on a rootful host) | it is the component that talks to Docker | ~2 600 lines of Go without network access, parses before it forwards, fuzzed (`FuzzParseRequest`, `FuzzCheckCreate`) |
| launchroomd | host network, `pid: host`, `NET_ADMIN`, its data folder | nftables (`inet launchroom`), binding the mesh IP and `127.0.0.1:5000`, probes to container IPs, Caddy's `ask` on loopback, port discovery via `/proc/<pid>/net` | no Docker socket, default capability set + `NET_ADMIN` only (no `SYS_ADMIN`/`SYS_PTRACE`: it cannot enter or trace host processes), no host paths outside its data folder; a compromised launchroomd can change this host's packet filtering and see process lists, not files or Docker |
| Caddy (system container) | host network, `NET_BIND_SERVICE` | binds 80/443 and reaches container IPs on every environment bridge | pinned digest only, no other caps, no host mounts |
| Mesh client (system container) | host network, `NET_ADMIN`, `NET_RAW`, `/dev/net/tun` | kernel (TUN) mode: the WireGuard interface lives in the host namespace | pinned digest only; equal to what launchroomd already has |
| BuildKit (system container) | seccomp and AppArmor unconfined, systempaths unconfined | rootless BuildKit sets up its own user namespace | own network, no added caps, never `--privileged` |

On a **rootless** Docker host all of these run inside the unprivileged user's namespaces (docs/11 §3.2): a breakout from any of them, the guard included, lands as that user, not root.

### 6.6 Trade-offs

- **Allow-list, not a generic proxy.** Path-only filters (tecnativa/docker-socket-proxy style) would pass `POST /containers/create` with `"Privileged": true`; the guard understands the bodies it forwards, at the price of following launchroomd: a new Engine API call or container field needs a guard change (rule R35). The guard shares `internal/policy` with launchroomd (one floor, two enforcement points) but deliberately imports none of the system-container packages; a test keeps their names in sync.
- **Reads are wider than writes.** `info`, `system df`, network lists and image inspections are not filtered (launchroomd's health and cleanup need host-wide counts); none of them exposes another container's configuration or environment.
- **Same release image.** The guard ships in the launchroomd image so the release has one daemon digest to pin and verify; it runs with a different entrypoint and none of launchroomd's privileges.
- **Not covered**: launchroomd's own host-level privileges (§6.5), and a compromised control plane (still bounded by the policy floor, enforced twice). The installer and `./launchroom` still run as the user who installs.

## 7. Known limitations

Accepted gaps in the current implementation. Each has a mitigation that holds today; the follow-up work is in the [roadmap backlog](12-roadmap.md#backlog).

**Ingress and mesh**
- Certificate storage is instance-wide: shared edges share one storage prefix, Valkey user and encryption key, and one DNS-01 token. Edges an instance owner did not admit get none of it (R14).
- The `lr-caddy` Valkey user can run the keyless `SCAN`, so it can list key names (not values) in the control plane's main Valkey.
- All teams share one ACME account and storage, so one team's many generated hosts can exhaust per-account and per-domain rate limits for everyone (without DNS-01). The on-demand `ask` endpoint limits issuance to routed hosts.
- "Bring your own Tailscale" uses one reusable tailnet key, sealed to every server an instance owner admitted (R14).
- Every node registers under one Headscale user, and `tagOwners` maps every tag to that user. If Headscale honours `--advertise-tags` for the tag owner, a compromised node could claim `tag:lr-cp`/`tag:lr-edge`. The receiving servers' nftables (edge-only Valkey, `meshAllow`) still apply.
- The Headscale ACL is re-pushed when its hash changes or `GET /api/v1/policy` returns a different policy or 404. A Headscale that errors instead is only caught by the hash.
- Previews share a parent domain with production apps (R20a).
- Custom domains have no DNS ownership check. Hostnames are unique install-wide (the first team to add one owns it), names at or under the apps domain and IP literals are refused.

**Secrets and credentials**
- GitHub clone tokens are not revoked when the build ends; they expire on GitHub's schedule (≤ 1 h).
- Registry tokens a revoked server already holds stay valid until they expire (≤ 5 min), and revoking a server does not prompt rotation of the secrets it held (R25).
- Backup task credentials are sealed when the task is created and stored in the task spec, not sealed at lease time. They are bound to one server and the task id, but stay in the row after that server is revoked.
- Listing a service's backup snapshots needs only the backup view permission, while the task it creates receives sealed destination credentials (throttled 10/min).

**Backups and restore**
- "Test destination" runs restic inside the dashboard container, so an instance owner's LAN override (R5) lets the control plane reach that LAN endpoint.
- Control-plane restore runs `pg_restore` as the bundle's single Postgres role, which is a superuser. The dump is authenticated by the recovery bundle's binding to the run and database snapshot ([14](14-backup-and-restore.md) §5.2).

**Updates and releases**
- launchroomd does not check the Fulcio `SourceRepositoryRef`; branch protection for releases relies on the tag ruleset, the `release` environment and the workflow's default-branch check (R30).
- Installers have no downgrade protection (R30).
- The update supervisor keeps copies of `.env` and `data/zot/config.json` (root, 0600) in the newest task directories after a successful update.

**Dashboard**
- Forgot-password has no route throttle (only the broker's 60 s per e-mail), and the default response reveals whether an account exists.
- E-mail uniqueness is case-sensitive, while invitations and setup compare `lower(email)`.
- Removing a team member and starting an in-place restore don't re-confirm the password (restore requires typed confirmation).
- `{revision}`, `{preview}` and `{shareLink}` bind by public id outside `TeamScopedBindings`. Every use checks the parent relation, so there is no IDOR, but it departs from R7.
- Permission inconsistencies without escalation under the fixed roles: "Allow deletion" for a retired volume needs `servers.manage` while "skip final backup" needs `backups.manage`; creating an environment copies secret values with `projects.manage` alone, while promotion needs `projects.manage` + `variables.write`; Revert restores variable values with `deployments.create`.
