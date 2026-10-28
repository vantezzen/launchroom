# 03: launchroomd & protocol

launchroomd ("the launchroom daemon", called "agent" before D27; Go module `daemon/`) is the only launchroom component that acts on a server's Docker daemon, firewall and ingress. It reaches Docker only through `dockerd-guard` (D49, §2). It never runs shell strings and never listens on a public port, and it does what a valid, signed desired state asks of it, only within its local policy.

## 1. Responsibilities

| Area | What launchroomd does |
|---|---|
| Identity | Generates its own keys at enrollment, signs every request, verifies every response (§3, §4.1). |
| Reconcile | Converges Docker networks, volumes and containers to the NodeState. Only touches objects labelled `launchroom.managed=true` (§5.2). |
| System workloads | Runs launchroom's own sibling containers as needed by the server's roles: `caddy` (edge), `buildkitd` (build), `tailscale` (mesh), registry forwarder. These are part of the NodeState under `system`. |
| Health | External HTTP/TCP probes against container IP:port; port discovery (reads `/proc/<pid>/net/tcp*` in the container netns to tell the user "your app listens on 5173, not 3000") (§5.4). |
| Ingress | Renders the route table to Caddy JSON and applies it through the admin API (localhost only). Answers Caddy's on-demand TLS `ask` from the cached route table. |
| Ambassadors | Per-project TCP forwarders that let workloads reach remote dependencies by their usual names ([05](05-networking-ingress.md)). |
| Firewall | nftables: launchroomd's own table `inet launchroom` (not `DOCKER-USER`, see [05](05-networking-ingress.md) §1) blocks containers on launchroom networks from metadata, the mesh, other environments, other Docker networks, private ranges and host ports, and allows mesh-published ports only from authorized peers (§5.5). Applied on start and every reconcile, checked for drift, fail closed. |
| Tasks | Leases and runs builds, one-off commands, backups, log fetches, sessions and self-updates (§7). |
| Telemetry | Status reports, host health and hardware facts, resource metrics (CPU/mem/disk/net per container and host), request metrics, log shipping (deployment logs always; runtime logs on demand, and continuously for workloads with `shipLogs` when their environment keeps runtime logs). All of it goes to the dashboard only (§9, §5.6). |
| Self-update | Downloads the release bundle of the requested version itself, installs it only if its signature verifies against the release identity pinned at install (`data/trust.json`), never downgrades, and rolls back if the new version does not come up ([11](11-installation-and-dev.md) §4). |

## 2. Packaging

- The `ghcr.io/vantezzen/launchroomd:<version>` image contains the launchroomd binary, plus `railpack` assets for build roles. The Railpack core is linked in as a Go module, with the version pinned per daemon release.
- The container runs with `network_mode: host` (required for nftables, binding the mesh IP and the 127.0.0.1 registry forwarder, reaching container IPs for health probes and answering Caddy's on-demand TLS `ask` on 127.0.0.1), `pid: host` (port discovery reads `/proc/<pid>/net/tcp*` of container processes; without it ports are reported as unknown), `cap_add: [NET_ADMIN]` and `./data/daemon` mounted.
- **launchroomd does not mount the Docker socket** (D49). `dockerd-guard`, a second container of the same image (`/usr/local/bin/dockerd-guard`, no network, no capabilities, read-only root), owns the socket and forwards only an allow-listed, policy-checked subset of the Engine API to launchroomd on `unix:///run/launchroom/docker.sock` ([09](09-security.md) §6).
- launchroomd is the **trusted computing base** for its server's workloads (its keys, its secrets, nftables), but it is not root on the host: the guard refuses privileged or host-namespace containers, host bind mounts, unpinned images, unmanaged containers and any other shape of the update supervisor, and enforces the policy floor (§8) a second time. Nothing else on the host gets the socket: not the dashboard, not BuildKit, not user apps, not launchroomd.
- Rootless Docker hosts are supported (`server.sh --rootless`, D49, [11](11-installation-and-dev.md) §3.2): launchroomd and its system containers then run in RootlessKit's namespaces, the guard exposes 80/443 through RootlessKit's port API, and the server reports a capability matrix (`Report.host.runtime`). userns-remap is not supported (it needs daemon config outside the install folder).

## 3. Enrollment

```
Dashboard UI                       Server (new)                           Dashboard API
────────────                       ────────────                           ─────────────
"Add server" (authorized)
 → join token (256-bit, shown once,
   hashed in DB, 30 min TTL, single use,
   bound to team + requested roles)
 → copy command ──────────────▶  curl -fsSL <cp>/install/server.sh | sh
                                 (script fetched from the pinned release; it
                                  verifies checksum + cosign signature of the bundle)
                                 writes ./launchroomd/{compose.yml,.env}
                                 token via env file, never argv
                                 docker compose up -d
                                 launchroomd: generate Ed25519 (signing) + X25519
                                        (sealing) keys into data/keys (0600)
                                 POST /daemon/v1/enroll ────────────────▶  verify token (hash_equals), mark used (atomic)
                                   {token, pubkeys, host facts,           register server (status: pending_approval
                                    daemon version}                           or active per team setting)
                                                         ◀───────────────  {server_id, cp_signing_pubkeys[kid],
                                                                            registry info, poll intervals, nudge}
                                                                           (mesh key: after approval, sealed in the NodeState)
UI shows new server with key
fingerprint; admin approves
(optional "require approval")
```

**Join token and install command**
- "Add server" requires `servers.manage` (owner/admin). The UI shows `curl -fsSL <APP_URL>/install/server.sh | LR_JOIN_TOKEN=… LR_CP_KEY=ed25519:… LR_CONTROL_PLANE_URL=<APP_URL> sh` once and polls until the server appears.
- Join tokens are `lr_jt_` + 43 base64url chars (256 bit), stored as SHA-256, 30-minute TTL, single use, bound to team + roles.
- `GET /install/server.sh` serves the launchroomd installer templated with `APP_URL`, the CP public key and the launchroomd image (`LAUNCHROOM_DAEMON_IMAGE`). It never contains a token.
- launchroomd reads the join token from `LR_JOIN_TOKEN_FILE` (a 0600 file in its data dir). The installers set it only until `data/enrollment.json` exists and then remove it together with the file ([11](11-installation-and-dev.md) §2–3).

**Pinned control-plane key**
- The install command embeds the **control-plane URL** and the **CP signing public key itself** (`LR_CP_KEY=ed25519:<base64>`). The key is 44 characters, so it is pinned directly; a `SHA256:` fingerprint is shown for humans.
- launchroomd refuses an enrollment response whose signature doesn't verify against it. This protects against a MITM during enrollment, also over plain-HTTP setups like local dev.
- The key can also come from a file (`LR_CP_KEY_FILE`, e.g. `/data/cp-key` on the control-plane host). A missing file counts as "not set"; if both are set they must be the same key. Daemon-only servers use `LR_CP_KEY` in `.env`.

**Approval and retries**
- Enrollment answers `201` with `status: active` unless the team setting `requireServerApproval` is on (default off). Then the server is `pending_approval` until an admin approves it after comparing the key fingerprint. The control plane's own server is never held for approval.
- Pending servers authenticate but get `403` from `state` and `tasks/lease`.
- A retried enrollment with the same token **and the same daemon key** returns the same server (the first response may have been lost). Any other reuse is `403`.

**Control-plane host**
- The control-plane host's daemon is enrolled by `php artisan launchroom:servers:bootstrap-local` (JSON `{serverId, token, cpKey, kid}`, idempotent); `make dev-enroll` wires it into the dev stack.
- The installer runs it **before** /setup, because the dashboard is only reachable through this daemon's Caddy. With no user yet, the server goes into an unclaimed instance team that the owner claims at /setup ([11](11-installation-and-dev.md) §2.1).
- This daemon talks to `http://127.0.0.1:8000` (the dashboard's loopback port); other daemons use `APP_URL`.

**Mesh key**
- A Headscale pre-auth key is *separate* from daemon identity. Mesh membership grants no authority ([09](09-security.md)).
- The key is not part of the enroll response. Once the server is approved (`ServerStatusChanged` → `ProvisionMeshMemberJob`), the dashboard issues a single-use tagged key and delivers it sealed in the server's NodeState (`system.tailscale.authKey`, [05](05-networking-ingress.md) §2). The second approved server switches the mesh on.
- Daemons of non-control-plane servers keep talking to the dashboard via its public URL; only the registry goes over the mesh.

## 4. Transport

All connections are **daemon → dashboard**. Daemons expose no ports.

| Channel | Mechanism | Purpose |
|---|---|---|
| State | `GET /daemon/v1/state?have=<version>` → `304` or the signed NodeState | Desired state for this server |
| Config | `GET /daemon/v1/config` → signed `DaemonConfig` | Nudge settings (§4.4), origin verification (§4.2) |
| Tasks | `POST /daemon/v1/tasks/lease` (non-blocking, returns 0..n tasks with lease expiry); `POST /daemon/v1/tasks/{id}/heartbeat`; `POST /daemon/v1/tasks/{id}/complete` (idempotency key) | Builds, one-off commands, backups… (§7) |
| Reports | `POST /daemon/v1/reports` (observed state with `observed_at`, health, metrics, batched) | Advances deployment state machines and fills the UI (§9) |
| Logs | `POST /daemon/v1/logs` (batched chunks: task/deployment/workload id, seq, lines) | Build, deploy and runtime logs. Approved servers only (403 otherwise, like reports); task chunks only for tasks this server holds (403), deployment chunks only with a task or placement of that deployment on this server, workload chunks only for its own placements (others are dropped). Lines of a deployment are masked against its secret values before they are stored |
| Registry token | `POST /daemon/v1/registry-token` (scope requested; CP decides) | Short-lived pull or push credentials (§4.5) |
| Client certificate | `POST /daemon/v1/client-certificate` | mTLS certificate issuance (§4.3) |
| Transfers | `GET /daemon/v1/transfers/{dtr}/chunks/{n}` (signed binary response), `PUT /daemon/v1/transfers/{dtr}/chunks/{n}` (body covered by `content-digest`) | 8 MiB chunks of an upload to import or an export, only for a transfer whose run's task this server holds; own limiter (D55, [14](14-backup-and-restore.md) §12.2) |
| Nudges | Reverb WebSocket, private channel `daemon.{server_id}`, auth via signed `POST /daemon/v1/broadcasting/auth` | "state changed" / "task available" / "start streaming logs for X". Content-free: launchroomd always fetches through the signed HTTP channel (§4.4) |
| Sessions | Reverb WebSocket, private channel `session.{ses_id}` per console/data session, joined by the browser (single-use ticket) and launchroomd (signed broadcasting auth) | End-to-end MACed frames + Ed25519-signed dashboard commands (§11) |

- **Polling fallback**: state every 30 s and tasks every 10 s (jittered). Polling runs whether or not Reverb is up; nudges make launchroomd act sooner.
- Daemon API routes live under `/daemon/v1`. Remote daemons reach them at the dashboard's public HTTPS URL, the control-plane host's daemon at its loopback port. The enroll endpoint must be public. Security does not depend on which network a request arrives over.
- Why not gRPC streams or long-polling: PHP is poor at long-lived streams (they tie up workers), and a separate broker process is one more thing to build, secure and operate. Short requests plus a websocket nudge give the same latency with plain Laravel.

### 4.1 Authentication: both directions, both layers

1. **Daemon → dashboard requests** carry an HTTP Message Signature (RFC 9421) over method, path, `content-digest`, `date` and `x-lr-nonce`, made with launchroomd's Ed25519 key. The dashboard middleware `AuthenticateDaemon` checks:
   - the key belongs to a non-revoked server (one exception: a server revoked by **Replace server**, [14](14-backup-and-restore.md) §5.3, may still `GET /daemon/v1/state`, which only ever returns its last, empty "decommission" NodeState, so a dead server that comes back stops its workloads; every other endpoint answers 401);
   - the date is within ±60 s;
   - the nonce hasn't been seen (Valkey, 5 min);
   - the digest matches the body.

   The nonce is recorded (cache `add`, 300 s, store `LAUNCHROOM_NONCE_CACHE_STORE` or the default cache, i.e. Valkey) only **after** the signature verifies, so unauthenticated requests can't write to the nonce store; the outcome for every request is the same as with the order in `protocol/SIGNING.md` §2.4. Failures are a generic `401 {"error":"unauthorized"}`; the failing step is only logged.

   Then it binds `$request->server()`. Every daemon endpoint is scoped to that server: a daemon can fetch only its own state and tasks and report only on its own workloads.
2. **Dashboard → daemon payloads** (NodeState, task specs, every 2xx response with a body) carry `X-LR-Signature: kid=…, sig=<Ed25519 over the exact response bytes>`. launchroomd verifies the raw bytes before parsing, so no JSON canonicalisation is needed. The signing key is a dedicated key (`LAUNCHROOM_SIGNING_KEY`, stored in a file, not the DB, not `APP_KEY`). Daemons trust a **key set** (`kid`) to allow rotation.
3. TLS: the public dashboard uses normal WebPKI (Caddy/ACME). On the mesh or localhost, signatures provide integrity and authenticity. Secrets inside payloads are sealed separately (§6). **Client certificates (mTLS, D50)** are an additional, opt-in layer on top of the signatures: §4.3.

### 4.2 The dashboard's canonical origin (D43)

launchroomd always has one **configured** control-plane URL: `LR_CONTROL_PLANE_URL` from its `.env`, the URL it was installed and enrolled with. It never changes it, and it stays the **permanent fallback**. When the dashboard moves to a custom domain ([05](05-networking-ingress.md) §4.1), it tells every other server its new **canonical origin** in the signed NodeState, `system.controlPlane {url}` (only to daemons that announce the feature `controlPlaneUrl`; never to the control-plane host, whose daemon talks to `http://127.0.0.1:8000`).

- **Verify, then adopt** (`daemon/internal/controlplane.Follower`): the pushed origin is used only after a normal signed `GET <origin>/daemon/v1/config` there returns a `DaemonConfig` signed by a trusted control-plane key, i.e. it is the same control plane behind that name. Then requests prefer it, and it is persisted in `data/daemon/control-plane.json` (`{url, verifiedAt}`, 0600) so a restart uses it right away. A failing verification is retried with backoff (1 min doubling to 10 min; a new URL in a newer NodeState starts over at once) while launchroomd stays on its current URL. A NodeState without `system.controlPlane`, or with the configured URL's own origin, clears it (file deleted).
- **Failover** (`controlplane.Client`): with an adopted origin every request goes there first. A transport error (DNS, connect, TLS), a `404`/`421`/`502`/`503`/`504`, or a 2xx with a body whose `X-LR-Signature` doesn't verify (every 2xx daemon response with a body is signed; bodiless ones aren't, protocol/SIGNING.md §3) marks it down for **2 minutes**: the request is rebuilt and re-signed (fresh nonce) and sent to the configured URL, and so are all requests until the cooldown ends. Other answers (401, 403, 409, …) are the dashboard's and are returned as they are. Enrollment always uses the configured URL. One warning per outage, one info line when it works again.
- **Realtime** nudges and session relays derive `wss://<host>/app/<key>` and their `Origin` from the client's currently active base (the adopted origin unless it is cooling down), so they follow the switch and the failover; an explicit `nudge.url` from `DaemonConfig` still wins.
- **Report**: `Report.host.controlPlane {configured, adopted?, active, state, message?}`, every report: `state` = `configured` (nothing pushed, or the configured origin), `adopted` (requests go to `adopted`), `fallback` (adopted, but failing over right now; `message` says why), `pending` (pushed origin not verified yet or verification failing; `message` has the error, `adopted` may still name a previous origin) or `refused` (local policy, below). `active` is the base URL of the last successful request.
- **Self-updates** keep passing `LR_CONTROL_PLANE_URL` from `.env` unchanged; to change a server's fallback, re-run its `server.sh` with the new URL.

### 4.3 Client certificates (mTLS, D50)

An **additional** layer: every request stays RFC 9421-signed and every response `X-LR-Signature`d (§4.1); mTLS never replaces either. What it adds: the control-plane edge refuses daemon API requests from anyone without a certificate of this instance at the TLS layer (before PHP parses anything), and a stolen signing key alone is no longer enough once the instance requires certificates. Browser, API, CLI, webhook and installer paths are unaffected.

**Internal CA.** The dashboard keeps one launchroom-internal CA per instance (`daemon_client_cas`): ECDSA P-256, self-signed, 10 years, `CN=launchroom server CA`, `OU=<instance id>`, basic constraints `CA:true, pathlen:0`, key usage `keyCertSign, cRLSign`. Its private key never leaves the control plane and is stored envelope-encrypted (KEK-wrapped data key, AD `daemon_client_ca|<id>|key`, docs/09 R13; rewrapped by `launchroom:keys:rotate-kek`). It is created on first use. The trust list sent to Caddy is every CA not retired (1–4), so old and new CAs can overlap. CA rotation itself is not implemented; see the [roadmap backlog](12-roadmap.md#backlog).

**Server certificates.** launchroomd generates its own ECDSA P-256 key (`data/keys/client-tls.key`, PKCS#8 PEM, 0600; it never leaves the server) and asks for a certificate over the existing signed channel:

| | |
|---|---|
| Request | `POST /daemon/v1/client-certificate`, signed `ClientCertificateRequest {publicKey}` (base64 DER SubjectPublicKeyInfo, P-256 only) |
| Response | `200`, signed `ClientCertificate {serverId, certificate, caCertificates, notBefore, notAfter, renewAfter}` (base64 DER). launchroomd checks the signature, its own server id, that the certificate's key is its key, that it chains to one of `caCertificates` and is currently valid, then stores `data/keys/client-tls.crt` (PEM) next to the key, both replaced atomically |
| Refusals | `403 {"error":"client certificate required"}` (re-issue refused while certificates are required, below), `429` (more than 12 issuances per hour per server), `404` (older dashboard: launchroomd tries again after 6 h) |

The leaf: serial 128 random bits, `CN=<server id>, O=launchroom`, SAN URI `urn:launchroom:server:<server id>`, EKU `clientAuth` only, key usage `digitalSignature`, `CA:false`, valid from 5 minutes ago for **7 days** (`launchroom.daemon.client_certificates.lifetime_hours`), `renewAfter` = two thirds of the way through. Every issued certificate is a row in `server_client_certificates` (fingerprint = lowercase hex SHA-256 of the DER, serial, validity, `revoked_at`, `last_presented_at`) and audited (`server.client_certificate_issued`, actor the server, meta fingerprint and expiry).

launchroomd (feature `clientCertificates`, `internal/clientcert`) asks right after enrollment and whenever it has no valid certificate, but only when one of its control-plane base URLs (configured or adopted, §4.2) is `https`: the control-plane host's own launchroomd talks to `http://127.0.0.1:8000` and never needs one. It renews at `renewAfter` (± 10 % jitter) with a **new key each time**, retrying with backoff (1 min doubling to 1 h) while the old certificate keeps working; the TLS client (`GetClientCertificate`) picks the current pair per handshake, so rotation needs no restart and no connection is dropped (old and new certificates are both valid until the old one expires); after a renewal, and again halfway to the old certificate's expiry, launchroomd drops its idle keep-alive connections so no long-lived connection keeps presenting the old one. The certificate is presented on every TLS connection to the control plane that asks for one, including the adopted origin's verification request and the failover to the configured URL.

**At the edge.** The control-plane server's system routes (docs/11 §2.1, D43: every dashboard origin, old and new) get an optional `clientAuth` (only for a control-plane launchroomd that announces `clientCertificates`; §10):

```jsonc
"clientAuth": {
  "mode": "verify",                       // verify | require
  "caCertificates": ["MIIB…"],            // base64 DER, 1–4
  "edgeSecret": { "sealed": "…" }         // IngressSecret purpose "client_auth", value "secret"
}
```

launchroomd renders it into Caddy as:
- a TLS connection policy for exactly the route's hosts (SNI) with `client_authentication {ca: inline [caCertificates], mode: verify_if_given}` before the catch-all policy. A certificate that is presented must chain to the CA and be within its validity, or the handshake fails; a client without one (every browser) connects as before. Caddy enables `strict_sni_host` when client authentication is configured, so another SNI can't carry the dashboard's `Host`;
- a `vars` handler first in the route that copies `{http.request.tls.client.fingerprint}` into `lr_client_cert` (without a certificate Caddy expands that placeholder to the SHA-256 of zero bytes, `e3b0c442…b855`, which a second `vars` route maps to ""), then two request headers on every request the route proxies, overwriting anything the client sent: `X-Lr-Client-Cert` = `{http.vars.lr_client_cert}` and `X-Lr-Edge` = the edge secret (from the per-route secret file `/data/launchroom/secrets/client-auth-<route id lowercased>` in Caddy's data volume, `{file.*}` placeholder, never in the config or its cache);
- with `mode: require`, a terminal route matching `path /daemon/v1/*` and `vars lr_client_cert = ""` but `not path /daemon/v1/enroll /daemon/v1/client-certificate` that answers `401 {"error":"client certificate required"}` (the exempt paths are protocol constants in launchroomd, not configurable).

Local policy (§8): `clientAuth` is honoured only on routes whose upstreams are all address upstreams in `LR_ALLOWED_LOCAL_UPSTREAMS` (the dashboard's loopback port); on any other route it fails closed (403, never proxied) and is logged.

**At the dashboard** (`AuthenticateDaemon`, after the signature, nonce and server checks; `App\Services\Daemon\ClientCertificateIdentity`). The instance mode (`instance_settings.daemon_client_certificates`: `off` default, `verify`, `require`) decides:
- `off`: the headers are ignored (Caddy doesn't ask for certificates).
- `verify`/`require`: a request carrying `X-Lr-Edge` came through the control-plane edge, and the value must equal the edge secret (`hash_equals`; otherwise 401). Through the edge, `X-Lr-Client-Cert` is either a 64-hex fingerprint, which must be a certificate of **this** server (the RFC 9421 `keyid`'s), not revoked and not expired (otherwise 401, and a certificate of another server is logged as a warning), or empty/the unexpanded placeholder (no certificate). Requests without `X-Lr-Edge` are direct (loopback, a private path); a fingerprint on them is ignored.
- `require`: a request without a verified certificate is refused (401) unless it is `enroll`, `client-certificate` (rules below), or from the control-plane server arriving directly (its loopback). So the dashboard enforces it even when someone reaches its port without Caddy.
- `client-certificate` while `require` is on: issued when the request presents a valid certificate of the server (renewal), when the server never had one (a new enrollment), or within an owner-granted re-issue window (`servers.client_certificate_reissue_until`, 1 hour, Settings → Security, audited) for a server whose certificate expired while it was offline; otherwise 403.
- Readiness is observed, not assumed: a request with a verified certificate sets `servers.client_certificate_seen_at`, a request without one (through the edge or direct) `servers.client_certificate_missing_at`. Settings → Security lists every approved server: **Ready** (verified certificate in the last 15 minutes and nothing without one since), **This server** (the control plane, loopback), **Waiting for a certificate**, **Not presenting a certificate** (e.g. it talks to the dashboard over plain http or not through its HTTPS address), **Update launchroomd** (no `clientCertificates` feature), **Offline**. "Require client certificates" is offered once every approved server is ready; requiring anyway lists the servers that will be refused and requires instance-owner authorization. Every change is audited (`instance.client_certificates_changed`); `php artisan launchroom:client-certificates off` is the host-access escape hatch.

**Revocation.** Revoking a server revokes its certificates at once (`revoked_at`, `server.client_certificates_revoked`); the dashboard refuses them from the next request. Caddy itself only checks chain and validity (no CRL/OCSP); the dashboard is authoritative, and the 7-day lifetime bounds a certificate the dashboard can't see. A server revoked by **Replace server** keeps its certificates until they expire, so it can still fetch its empty decommission state (§4.1).

**Realtime.** Reverb's `/app/*` path is shared with browsers, so it can't require client certificates (launchroomd's websocket dialers don't present one; nothing would check it). That is acceptable: nudges are content-free and only make launchroomd poll the signed, mTLS-covered API; subscribing to `private-daemon.<id>` needs `POST /daemon/v1/broadcasting/auth`, which is under `/daemon/v1` and so mTLS-covered; console/data session frames are MACed end to end and dashboard commands Ed25519-signed (§11). Reverb is transport, not trust.

### 4.4 Nudges

Daemon `internal/nudge`, dashboard `App\Services\Daemon\DaemonNudger`.

**Where to connect**
- `GET /daemon/v1/config` (signed `DaemonConfig`) returns `nudge: {key, url?}`. The enroll response carries the same `nudge`, kept in `enrollment.json` as the fallback for dashboards without the config endpoint (404). `key` is the public Reverb/Pusher app key.
- Without `url` launchroomd derives `ws(s)://<control-plane host>[/path]/app/<key>` from its control-plane URL (`https` → `wss`). The dashboard's FrankenPHP proxies `/app/*` to Reverb, so the control-plane host's daemon uses `ws://127.0.0.1:8000/app/…` and remote daemons `wss://<APP_URL host>/app/…`. `LAUNCHROOM_DAEMON_NUDGE_URL` overrides it (dev: `ws://reverb:6001`).
- Nudges are on when the default broadcaster speaks the Pusher protocol (`reverb`/`pusher`) and `LAUNCHROOM_DAEMON_NUDGES` isn't `false`. Otherwise `nudge` is omitted and daemons only poll. Unapproved servers get no `nudge`.

**Subscribe**
- Pusher protocol 7 over WebSocket (`github.com/coder/websocket`). After `pusher:connection_established` launchroomd asks `POST /daemon/v1/broadcasting/auth` (signed `BroadcastAuthRequest {socketId, channel}`) and gets a signed `BroadcastAuth {auth: "<key>:<hex HMAC-SHA256(secret, socketId:channel)>"}`, then sends `pusher:subscribe`.
- The endpoint authorizes only `private-daemon.<its own server id>` (403 otherwise, 403 for unapproved servers, 404 when nudges are off) and session channels of session tasks this server holds (§11.2). Browser sessions can never join `daemon.*` channels (`routes/channels.php` denies them).

**Events** (content-free, data ignored)
- `state.changed` → fetch `GET /state` now; `task.available` and `logs.requested` → lease now.
- After every (re)subscription launchroomd fetches state and leases once, because nudges sent while disconnected are lost.
- Events on other channels or before the subscription is confirmed are ignored. A forged nudge costs one extra signed poll.

**Keepalive and reconnect**
- `pusher:ping` after the server's `activity_timeout` (clamped 10–120 s) of silence; reconnect when no answer within 30 s. Server pings are answered.
- Reconnects back off exponentially with jitter (1 s → 60 s, reset after a connection that lasted 2 min). The config is fetched again before each attempt. Polling keeps running at its normal intervals throughout.

**Sending**
- `RenderNodeState` nudges `state.changed` when it stores a new version.
- A task becoming pending (created, or re-queued after an expired lease) nudges the servers that may lease it: the pinned server, else the team's approved servers with the required role. `logs` tasks send `logs.requested`.
- Nudges go out after the surrounding transaction commits, synchronously (no queue latency), with the Reverb client bounded to 2 s connect / 5 s total. Failures are logged and swallowed.

**Latency** (measured in dev): Deploy → daemon fetches the new NodeState ≈ 1.4 s (nudge → fetch 25 ms; the rest is the queued `AdvanceDeploymentJob` and rendering); Deploy → build task leased ≈ 0.7 s (nudge → lease 22 ms); build finished → rollout state fetched ≈ 1.8 s. Without nudges, the poll intervals bound it (30 s / 10 s).

### 4.5 Registry tokens

`POST /daemon/v1/registry-token`, signed `RegistryToken`. The dashboard grants all requested scopes or answers 403. Tokens live ≤ 5 min.

| Scope | Granted when |
|---|---|
| pull `mirror/<registry>/…` | any approved server |
| pull `apps/<service>` | the service's images are in the server's latest NodeState |
| pull+push `apps/<service>`, `cache/<service>` | `task` = a `build` task this server currently holds (leased, lease and deadline not passed) for that service |
| pull `apps/<service>` | `task` = a `run_once` task this server holds for that service (a release command runs the new image before it is in any NodeState) |
| pull `apps/<service>` | `task` = an `inventory` task this server holds whose `spec.service` is that service (§13.1) |

## 5. NodeState (desired state document)

> **Authoritative contract:** the Go types in `daemon/internal/state` and the generated schemas in `protocol/schema/v1/` (see `protocol/README.md`, `protocol/SIGNING.md`). The sketch below is illustrative.

JSON, versioned, generated from Go structs → JSON Schema in `protocol/` ([10](10-codebase-conventions.md)). Sketch:

```jsonc
{
  "apiVersion": "launchroom.vantezzen.io/v1",
  "kind": "NodeState",
  "serverId": "srv_01J…",
  "version": 1842,                     // monotonic per server; daemon rejects <= current
  "issuedAt": "2026-10-17T12:00:00Z",  // no expiry: the state must survive a CP outage; tasks expire
  "system": {                          // launchroom's own sibling containers for this server's roles
    "caddy":    { "image": "…/caddy@sha256:…", "enabled": true },
    "buildkit": { "image": "…/buildkit@sha256:…", "enabled": false },
    "tailscale":{ "image": "…@sha256:…", "enabled": true, "loginServer": "https://…",
                  "authKey": { "sealed": "…" } },  // until joined; "tags" only with your own tailnet
    "registry": { "forwardTo": "100.64.0.1:5000" }   // control plane: { "serveOnMesh": true }
  },
  "networks": [ { "id": "net_…", "name": "lr-e_7f3c…", "subnet": "10.231.4.0/24" } ],
  "volumes":  [ { "id": "vol_…", "name": "lr-v_91ab…" } ],
  "workloads": [
    {
      "id": "wl_…",                    // stable per (service instance, replica slot, deployment)
      "service": "svc_…", "environment": "env_…", "deployment": "dpl_…",
      "image": "127.0.0.1:5000/apps/svc_…@sha256:…",
      "command": null, "args": ["node", "server.js"],      // argv, never a shell string
      "env": { "sealed": "<base64 crypto_box_seal(json env, daemon x25519)>" },
      "port": 3000,
      "health": { "type": "http", "path": "/", "expectStatus": "2xx-3xx", "startPeriod": "30s", "interval": "5s", "timeout": "3s", "failureThreshold": 3 },
      "resources": { "cpus": 1.0, "memoryMb": 512, "pids": 512 },
      "networks": [ { "network": "net_…", "aliases": ["web"] } ],
      "mounts": [ { "volume": "vol_…", "target": "/var/lib/postgresql/data" } ],
      "publishOnMesh": null,           // e.g. { "port": 31001 } if consumed cross-server
      "restart": "unless-stopped",
      "role": "serving"                // serving | draining (stop after drainUntil)
    }
  ],
  "ambassadors": [ { "network": "net_…", "alias": "postgres", "port": 5432, "upstreams": ["100.64.0.7:31001"] } ],
  "routes": [                          // only on edge servers
    { "id": "rt_…", "hosts": ["shop.com","www.shop.com"], "tls": "acme" , "redirectWwwTo": "shop.com",
      "upstreams": [ { "workload": "wl_…", "port": 3000 }, { "address": "100.64.0.9:31002" } ],
      "headers": { "X-Robots-Tag": null },
      "protection": null }             // preview protection: forward auth to the dashboard
  ],
  "firewall": { "meshAllow": [ { "port": 31001, "from": ["100.64.0.3"] } ] }
  // control plane with ≥ 2 edges: "system": { …, "certStorage": { "serveOnMesh": true, "allowFrom": ["100.64.0.2"] } }
}
```

### 5.1 Document rules

- Every document has `apiVersion` + `kind`, fields are camelCase, and decoding is strict (unknown fields rejected). Optional fields are gated by daemon features (§10).
- IDs are uppercase ULIDs. Docker repository paths are lowercase, so the dashboard lowercases IDs in image names.
- **Host-side names are generated** (`lr-…` plus hashes of UUIDs). User-chosen names are labels only.
- **Images are always digest-pinned** and must come from the allowed registry (the local forwarder).
- `WorkloadSpec` has optional `command` (entrypoint override), `args` (cmd) and `capabilities` (from the allow-list).
- `WorkloadSpec.files` (feature `configFiles`): up to 32 literal text files `{path, content}` that launchroomd writes into the container after creating it and before starting it (§8). The dashboard sends them only to daemons announcing `configFiles`.
- **Route upstreams reference workloads, not IPs.** The dashboard can't know container IPs ahead of time, so upstreams are `{ "workload": "<id>", "port": 3000 }` for workloads in the same NodeState (the edge daemon resolves the container IP on the workload's primary network; Caddy runs with the host network and reaches it directly, see [05](05-networking-ingress.md) §4) or `{ "address": "100.64.0.9:31002" }` for mesh-published replicas on other servers. launchroomd routes only to workloads that are running and pass their probe (serving ones preferred; draining ones only if no serving one is ready). A route without a ready upstream answers 503.
- Route `protection` (forward auth to the dashboard's `/auth/preview`, preview protection) is optional ([05](05-networking-ingress.md) §4).
- Route `stopped` (feature `serviceStop`, D68): the service was stopped in launchroom. Such a route has no upstreams (any other route needs 1–64); the edge answers every request with its "This app is stopped" page (503) and never proxies or asks forward auth. The dashboard sends it only to edges announcing the feature; older edges get no route (their 404 page).
- `system.controlPlane {url}` (feature `controlPlaneUrl`, D43): the dashboard's canonical origin, `ControlPlaneOrigin` = `https://<DNS name of ≥ 2 labels>[:port]` (no IP literal, no path); `Report.host.controlPlane` reports which URL is used (§4.2).
- The NodeState is persisted to `data/daemon/state.json` after signature verification, together with the signature, so launchroomd can restart offline and still trust it.

### 5.2 Reconcile

[`daemon/internal/reconcile`](../daemon/internal/reconcile). Reconciliation is **level-triggered**: launchroomd diffs desired against actual and acts.

- Runs every 30 s (jittered), on every new NodeState and on every probe state change.
- One container per workload id (`lr-<workload id lowercased>`), labelled with a `launchroom.spec-hash` of everything it was created from (env values enter the hash only as a keyed HMAC). A different hash → stop-then-recreate (same id = same name). Rolling updates are new workload ids: new before old, health-gated (see [04](04-build-and-deploy.md)).
- `draining` workloads are never created or recreated. They keep running until `drainUntil`, are then stopped with a 10 s grace period, and stay stopped until the dashboard drops them.
- Workloads that leave the desired state are stopped and removed. Before stopping or removing workloads, a pass first syncs the ingress without them, so no request goes to a stopping container. These removals come right after networks and volumes are created and before any pull or create, so a service held for an import or a restore ([14](14-backup-and-restore.md) §12.5) stops as soon as the server applies the state, not after a slow pull for another workload.
- Passes run one at a time, so a pass that waits for a long pull delays every newer NodeState. When a newer NodeState arrives with no workload that uses the image being pulled (its deployment was cancelled, or the service is held), launchroomd abandons that pull (`Reconciler.AbandonPulls`, cause "pull abandoned: no desired workload uses the image any more") and the next pass applies the new state right away.
- Unused managed networks are removed, except ones a running `run_once` task holds (§5.8).
- **Volumes are never deleted because they are unreferenced.** Only the explicit `deleteVolumes` list deletes them, with matching labels and unused ([05](05-networking-ingress.md) §1.1).
- A workload whose env can't be opened or that violates the policy floor is reported `failed` and its existing container is left untouched.
- **Image pulls run in the background** (D68, `pulls.go`): a pass waits at most 15 s (`PullWait`) for a pull it started; workloads whose image is still coming are reported `pulling` and created by the pass after the pull ends (`OnPullDone` kicks it). A slow or stuck download never holds back the rest of the desired state. A newer state that no longer needs the image cancels its pull (`AbandonPulls`).
- **Lifecycle** (D68, `lifecycle.go`): every pass compares each container with its last inspection and records typed events (§9); between passes, launchroomd looks at starting workloads every 2 s (`Watch`: not healthy for 30 s yet, at most 15 minutes after their start), so a crash, a restart or a port issue is reported within seconds. A workload waiting for the server's mesh address is reported `pending` with that reason.

### 5.3 Images

- **Pulls** use the digest-pinned reference. Images under `127.0.0.1:5000/` are pulled with `X-Registry-Auth: {"registrytoken": …}`, a pull-only token for exactly that repository (§4.5, cached until 45 s before expiry). Other registries are anonymous.
- launchroomd serves `127.0.0.1:5000` as a TCP forwarder to Zot (`system.registry.forwardTo`, else `LR_REGISTRY_UPSTREAM`; none on the host where Zot itself listens), see [05](05-networking-ingress.md) §6.
- **System images** (`system.caddy.image`, `system.buildkit.image`, `system.tailscale.image`) are accepted only if their digest is one the launchroomd release pins (any repository, e.g. a mirror in the local registry, is fine). The SFTP helper of volume access (`AccessSpec.image`, §12.4) is checked the same way against the `files` pins (busybox 1.37.0-glibc, `sha256:bdf57e52…`). See `policy.DefaultSystemImageDigests` (BuildKit: `moby/buildkit:v0.34.0-rootless`, `sha256:65eca3c0…`; Caddy: the release's own `launchroom-caddy`, compiled in at build time, or stock `caddy:2.11.7` in dev builds, [05](05-networking-ingress.md) §4). `launchroomd system-images` prints them.
- `system.buildkit.enabled` runs the rootless `lr-system-buildkit` container on its own network ([04](04-build-and-deploy.md) §12).

### 5.4 Health

- HTTP probes (GET, no redirects followed, no proxy) or TCP connects from launchroomd to container IP:port.
- Failures inside `startPeriod` don't count until the first success. `failureThreshold` consecutive failures make the probe failing.
- Workloads without a probe count as healthy after running 10 s.

### 5.5 Mesh and cross-server

See [05](05-networking-ingress.md) §1, §2, §3, §5.

- **Mesh client**: `system.tailscale` runs `lr-system-tailscale` (kernel mode, `internal/mesh`) when enabled. `loginServer` is `http(s)://host[:port]` (`MeshLoginURL`; http only for hosts in the local `LR_MESH_INSECURE_LOGIN_HOSTS`). `tags` are advertised only when present ("bring your own Tailscale"). `authKey` is a sealed `MeshAuthKey` bound to the server, used only when the client needs to log in, passed on the `tailscale up` stdin. Hosts without `/dev/net/tun` can't run the mesh client; the mesh state is then `error` with that reason.
- `Report.host.mesh` reports state (`starting`/`needs_login`/`connected`/`error`), addresses and peers (`direct`/`relay`/`idle`).
- **Registry over the mesh**: `system.registry.serveOnMesh` (only without `forwardTo`) makes launchroomd serve `<mesh IP>:5000` → the local registry.
- **Published ports**: `workloads[].publishOnMesh {port}` binds the port on the server's mesh IPv4 only (31000–32999). The workload waits (status `pending`) until the mesh address is known, and an existing container is not touched meanwhile.
- **Mesh allow-list**: `firewall.meshAllow [{port, from}]` → nftables gate `mesh-published` (prerouting, before Docker's DNAT) with one accept per (peer, port), only mesh peers.
- **Ambassadors**: `ambassadors [{network, alias, port, upstreams}]` → the `lr-system-ambassador` container (image built locally from launchroomd's own `launchroom-ambassador` binary, not dashboard-chosen). Listeners only on declared `lr-e_…` networks, upstreams only mesh addresses on 31000–32999.
- **Certificate storage**: optional `system.certStorage {serveOnMesh, allowFrom}` (control-plane host) serves `<mesh IP>:6379` → the local Valkey (`LR_CERT_STORAGE_UPSTREAM`, default `127.0.0.1:6380`) and allows tcp 6379 from the mesh only from `allowFrom`.
- **Link quality** ([05](05-networking-ingress.md) §2.1): optional `system.linkProbe {enabled, peers [{serverId, ipv4, signingKey}]}` (feature `linkProbe`, §10) → `internal/linkprobe` answers signed probes on `<mesh IPv4>:7400` from exactly those peers (nftables `mesh-host`) and measures them (latency every minute, ≤ 16 MiB transfer every 6 h). `Report.host.links` carries the latest result per peer.
- Fixtures: `node-state.cross-server.json`, `node-state.link-probe.json`, `report.links.json`.

### 5.6 Runtime log shipping

`internal/logship`, feature `shipLogs`.

- A workload with `shipLogs: true` gets one follower that streams its running container's logs through the Docker API from a per-workload cursor (`data/logship/<workload>.json`: last shipped line time and seq).
- It ships workload chunks (`{kind: workload, id}`, time-based seq in Unix µs, ≤ 500 lines, every 2 s).
- Limits: 200 lines/s per workload (burst 2,000) and 5,000 unshipped lines while the dashboard is unreachable. Dropped lines are announced by one `system` line.
- A replaced or restarted container is picked up again from the cursor. Dropping `shipLogs` stops the follower and deletes the cursor.
- `shipLogs` is not part of the spec hash: toggling it never restarts a container.
- **Startup output** (D68): a starting workload without `shipLogs` (the reconciler's `Starting`) gets a startup follower: its container's output from the first line, also from a container that already exited, as workload chunks with `step: "startup"`, at most 1,000 lines (then one `system` line), nothing persisted. It stops once the workload has been healthy for 30 s or 15 minutes after its start. The dashboard files these lines under the deployment step they arrived in and keeps them with the deployment.

### 5.7 Invalid documents

A NodeState that fails decoding or `Validate()` is refused whole, so every workload on the server would wait for the next valid one. Both sides guard against that:

- **The dashboard validates every rendered document before storing it** (`App\Services\NodeStates\NodeStateValidator`): the Go-generated JSON Schema via opis/json-schema (shipped in the dashboard image under `/var/www/protocol/schema/v1`), plus a PHP port of the `Validate()` cross-reference rules and the policy floor's name prefixes.
  - An invalid render is never published: the previous version stays, `servers.render_error` records why (server page "Desired state" health check, critical) and the error is logged.
  - The in-flight deployments it is attributed to (through the workloads, routes, networks and volumes the problem paths name; else all in-flight deployments on that server) fail with `config_error` "Desired state could not be rendered: …".
  - A server that never had a version gets an empty one instead. A valid render clears the error.
- **launchroomd keeps converging to its last good state** (`appliedVersion`) and reports the refusal in every report as `stateRejected {version, error, rejectedAt}` (version read leniently, 0 if unreadable; only for correctly signed documents) until a newer state is accepted. The dashboard stores it in `servers.state_rejection` and shows it in the same health check.

### 5.8 Network holds

A `run_once` task may create an environment network this server has no workloads in yet (a release command before the first rollout). Without coordination, a concurrent reconcile pass sees that network as undesired and unattached and removes it before the task's container exists.

- The task **holds** its network (`docker.NetworkHolds`, in-process) from before it ensures the network until its container is gone.
- The reconciler never removes a held network and removes networks only under the same lock (`RemoveUnlessHeld`), so a hold and a removal never interleave.
- Creating a network that already exists with the same id label (created concurrently) counts as success after a re-list.
- Container create and start retry "network not found" up to 3 times (250 ms, 500 ms, 1 s), re-creating missing desired networks in between (Docker 29 accepts the container and fails at start).

## 6. Secrets delivery

- Workload env is resolved by the dashboard, then **sealed to the target daemon's X25519 public key** (libsodium `crypto_box_seal`). The sealed plaintext includes the `serverId` and `workloadId`, which prevents re-targeting. The exact plaintext is the JSON document `{"serverId", "workloadId", "env": {NAME: value}}` (schema `protocol/schema/v1/workload-env.json`, test vectors in `protocol/fixtures/sealing/`).
- launchroomd decrypts in memory and passes env to Docker at container create (env vars). Values never touch disk in plaintext, except the sealed copy in `state.json`.
- A daemon only ever receives secrets for workloads scheduled on it.
- Caveat (documented): `docker inspect` on that host reveals env. The host is the trust boundary.

## 7. Tasks

```jsonc
{ "id": "tsk_…", "type": "build", "leaseExpiresAt": "…", "attempt": 1, "deadline": "…",
  "spec": { /* typed per task type, signed like NodeState */ } }
```

| Type | Spec highlights | Notes |
|---|---|---|
| `build` | builder `dockerfile` may carry the Dockerfile inline (`builder.dockerfile`, feature `generatedDockerfile`, [20](20-dockerfile-generation.md)); repo (provider `github`/`gitlab`/`gitea`/`git`, clone URL `https://`/`ssh://`, SHA, optional ref, pinned host keys for ssh), short-lived clone token or ssh deploy key (sealed), root dir, builder decision/overrides, build-scoped env (sealed), Railpack version, target platform, push repository, cache key | Logs streamed per step; result = digest, plan, info (summary), attestations, secrets hash, duration. Push credentials are requested at push time (`POST /daemon/v1/registry-token` with the task id), never part of the spec |
| `plan` | same minus the push; optional `inspect` | Dry run for "we detected…" previews and settings changes (seconds). With `inspect` (feature `generatedDockerfile`), also reports `inspection` (file listing + allow-listed manifest files, deny-listed secrets never read) on success and failure ([20](20-dockerfile-generation.md) §2, §6) |
| `run_once` | image digest, argv, env (sealed), network, timeout, optional `probe {port, path?, within}` | Release commands, seed commands, Laravel `migrate`. Runs on a runtime server in the target environment's network. With a probe (feature `generatedDockerfile`), argv may be omitted and launchroomd stops the container once it accepts connections on the port; result `runOnce.probe` ([20](20-dockerfile-generation.md) §5). |
| `backup` / `restore` | kind `database`: engine, workload; kind `volume`: volume (+ consistency `live`/`pause` for backups); destination (S3 creds sealed as a TaskEnv bound to the task) | Database: engine-specific argv generated by launchroomd from typed fields, pinned to the database's server ([14](14-backup-and-restore.md) §9.3). Volume: a never-started helper container mounts the volume (read-only for backups) and the Engine API archive endpoints stream its tar into `restic backup --stdin` / out of `restic dump`; pinned to the volume's server (§9.12) |
| `backup` / `restore` kind `base_backup` | backup: engine `postgres`, workload; restore: engine, data volume, `pointInTime?`, `walTags`, `runtime {image, mountPath, dataDirectory, archiveDirectory?, env (sealed TaskEnv: POSTGRES_USER/PASSWORD/DB), resources}` | Point-in-time recovery ([14](14-backup-and-restore.md) §10): `pg_basebackup` into restic; a restore lays the base backup + archived WAL into the emptied volume with `standby.signal` and replays it in a throwaway, network-less container of the engine image (no `restore_command`) |
| `backup_snapshots` / `backup_maintenance` | destination, tags (and retention for forget, incl. `keepWithinHours`) | `restic snapshots --json` listing; forget/prune/check |
| `verify_restore` | kind `database`/`base_backup`/`volume`, snapshot, destination, engine + image + data path (databases), WAL tags + role/database (base backups), resource caps | Restores into a throwaway container (no network, capped, own volume) and checks it; result `verify {passed, checks[], tables, rows, files, bytes, restoredTo}` ([14](14-backup-and-restore.md) §10.4) |
| `db_import` | mode `inspect`/`import`, engine (`postgres`/`mysql`/`mariadb`/`mongodb`), target workload, source (`file`: `TransferRef {id, sizeBytes, chunkBytes, chunks, sha256}`; `url`: sealed TaskEnv `SOURCE_URL`), `replace`, throwaway resources | Inspect: the target's emptiness, size and version, a URL source's reachability. Import: fetch and verify the file, read format and versions from it, drop and recreate when `replace`, restore with the target image's own client tools in a throwaway on the database's environment network; result `dataImport` ([14](14-backup-and-restore.md) §12.3). Feature `dataTransfer` |
| `volume_import` | mode, volume, file, `replace`, `stripComponents`, `owner {uid, gid}` | Inspect: empty or not, root owner. Import: the archive (`.tar`/`.tar.gz`/`.zip`) converted entry by entry into a volume helper; result `dataImport` (§12.4) |
| `db_export` / `volume_export` | engine + workload (live) or snapshot + destination; volume (+ consistency) for volumes; `transfer {id, chunkBytes}` | The backup argv's dump (or `restic dump` of a snapshot), volumes re-rooted and gzipped, uploaded in chunks; result `dataExport {format, compressed, sizeBytes, chunks, sha256}` |
| `logs` | workload, since/tail/follow | Follow mode streams through the logs endpoint plus Reverb to the browser |
| `session` | session id, kind `console`/`data`, workload, channel, sealed session key, idle timeout; shell + size (console) or engine (data) | Interactive console / database data session relayed through Reverb, pinned to the replica's server, one attempt, deadline = absolute timeout; heartbeats re-authorize; result = `SessionResult` (§11) |
| `access` | access id, kind `ssh`/`database`/`volume`, workload, engine (database), volume and helper image (volume), user, `authorizedKeys` or sealed password (TaskToken purpose `access`), `allowFrom`, `hosts`, `allowWrites`, idle timeout | Temporary remote access (§12, D61): a listener on a random port of `LR_REMOTE_ACCESS_PORTS`, pinned to the replica's server, one attempt, deadline = hard cap. Heartbeats (every 5 s) carry `access` (port, host key, certificate, connection events) and re-authorize; result `AccessResult` |
| `inventory` | digest-pinned `image` (`apps/` or `mirror/`), `platform`, `service` (apps images), optional `source {repo, cloneToken\|sshKey (sealed), rootDir}` | Lists the image's packages and runtimes with the pinned SBOM scanner inside BuildKit and, with a source, sets dependency scopes from the lockfiles; result `inventory` (§13.1, [22](22-security-center.md) §3). Feature `inventory` |
| `inspect_container` | `workload`, `maskEnv` | Reads a workload container's writable-layer changes and processes through dockerd-guard; pinned to the replica's server, one attempt; result `containerInspection` (§13.2, [22](22-security-center.md) §7). Feature `containerInspection` |
| `self_update` | target `version`, `image` (consistency check), `scope` `daemon` (default) or `control_plane` | Daemon downloads and verifies the signed bundle from the pinned release base, refuses non-newer versions, `daemon` scope on the control-plane host and `control_plane` elsewhere; progress in heartbeats; result `selfUpdate {scope, fromVersion, toVersion, rolledBack}`. The attempt is completed by the launchroomd process that runs after the swap (possibly after a re-lease; a re-leased finished update completes from its recorded status). [11](11-installation-and-dev.md) §4 |

### 7.1 Leases

- A task is leased for 60 s and extended by heartbeats. An expired lease is re-queued with `attempt+1`, up to a max per type. Completion is idempotent by `(task_id, attempt)`.
- launchroomd leases only the types it handles (`build`/`plan` only when it can run system containers). A task of an unsupported type completes as `failed` with "task type is not supported by this daemon version".
- Builds and plans are only leased by servers whose platform matches `spec.platform`.
- **Cancelling**: pending tasks are cancelled directly. Leased ones get `cancel_requested_at`, the next heartbeat answers `cancel: true`, and launchroomd completes them as `cancelled`.

### 7.2 Sealing at lease time

Unpinned tasks (builds, plans) are leased by whichever server with the required role asks first, so their secrets can't be sealed when the task is created.

- The dashboard stores task specs as templates without secrets, plus `daemon_tasks.seal`, a list of *references* (which deployment's env snapshot and which keys, which repository needs a clone token).
- When a server leases the task, `LeaseTasks` resolves the references and seals them to that server's X25519 key, bound to (server, task):
  - `spec.env` as `TaskEnv`;
  - `spec.cloneToken` as `TaskToken` (purpose `clone`: a fresh repository-scoped GitHub installation token, or the GitLab/Gitea connection's token);
  - for `ssh://` remotes, `spec.sshKey` as `TaskToken` (purpose `ssh_key`: the repository's ed25519 deploy key seed, base64), next to the public `repo.knownHosts` pins ([19](19-git-providers.md) §6).
- The sealed values are only in that lease response, never stored. A re-leased attempt is sealed again for its new server.
- A task whose secrets can't be resolved (deployment snapshot gone, installation suspended, GitHub unreachable) is failed at lease time (`result.failure.class` `config_error`/`clone_error`/`transient`) instead of being handed out, so launchroomd never receives a spec it can't run.
- Pinned tasks (release/seed commands, backups) are sealed the same way.

### 7.3 Task execution

- **`logs`**: lines ship with source `{kind: "task", id: <task id>}`, `seq` from 1, every second while following. Blank lines are lines (`text` may be empty). Follow mode ends at the task deadline or a cancel. A batch the dashboard refuses fails the task with that error instead of dropping lines silently.
- **`build` / `plan`**: step-grouped lines (`step`) ship live with `seq` from `(attempt − 1) × 10⁹ + 1`. They complete with `build` (`BuildResult` incl. `summary`) or `plan` (`PlanResult`); failed ones carry `failure` (class, step, retryable, hints, summary). Spec, results and the build pipeline: [04](04-build-and-deploy.md) §12, `protocol/CHANGELOG.md`.
- **`run_once`**: a one-off container from `spec.image`, with the policy floor and hardened defaults exactly like a workload and restart `no`.
  - Attached to `spec.network`, created with the reconciler's name, subnet and labels if this server has none (held, §5.8).
  - Gets the sealed `TaskEnv`, `command` (replaces the ENTRYPOINT) and/or `args`, and `spec.timeout`.
  - Named `lr-run-<task id>`, labelled `launchroom.task=<task id>` without a workload label: the reconciler never lists, stops or removes it.
  - Its logs ship with step `run`, masked for the values of `spec.maskEnv` (secrets and config values embedding one; raw, encoded and the password of URL values).
  - Removed afterwards, also on timeout or cancel (after SIGTERM + 10 s).
  - Any exit code is a successful task with `runOnce.exitCode`; the dashboard decides what it means.
  - Image pulls for the task request registry tokens with `task` set (§4.5).

### 7.4 Shutdown

- On SIGTERM launchroomd stops leasing and gives running tasks 20 s to finish (`tasks.Options.ShutdownGrace`; the compose files set `stop_grace_period: 30s`). A task that finishes reports its real outcome.
- Unfinished ones are stopped (one-off containers removed) and completed with outcome **`released`**. The dashboard re-queues the task at once (pinned tasks stay on their server) without using up an attempt (`max_attempts` is raised to `attempt + 1` when needed). Only a passed deadline fails it.
- So a daemon restart never cancels running builds or their deployments.

## 8. Local policy floor (enforced by launchroomd, not overridable by the dashboard)

Even a fully compromised control plane must not get trivial host root through a normal-looking spec. The floor is enforced **twice** (D49): by launchroomd before it calls Docker, and by dockerd-guard on every Engine API request, with its own policy from the install's `.env` ([09](09-security.md) §6), so a compromised launchroomd process is bounded by it too:
- Images: digest-pinned, from the configured registry only.
- No `privileged`, no host network/PID/IPC/UTS/userns namespaces, no devices, no added capabilities beyond a small allow-list (`NET_BIND_SERVICE`, `CHOWN`, `SETUID`, `SETGID`, `DAC_OVERRIDE`, `FOWNER`), no `security_opt` that disables seccomp or AppArmor or names an AppArmor profile (Docker's default only; a host profile can be wider, for example `launchroom-buildkit` grants user namespaces, D58).
- No bind mounts. Only named, launchroom-labelled volumes (and launchroom's own system containers, whose specs are built into the launchroomd binary, not taken from the dashboard).
- Volumes and networks: NodeState volume names must be workload volumes (`lr-v_…`) and network names environment networks (`lr-e_…`); `lr-system-…` objects (Caddy data, BuildKit state, the build network) can never be declared, mounted or joined. A volume is created only when a workload mounts it and is labelled with that workload's service and environment (Docker's volume create is idempotent and returns an existing volume, so launchroomd checks the labels of what it got back; after a dockerd restart Docker answers that from a cache that has lost the labels, so a create whose answer has no matching labels is decided by a `VolumeInspect` of the stored labels, never adopted on the answer alone, D51); a workload may mount an existing volume only if its `launchroom.volume` label is the declared id and (when present) its service/environment labels match the workload. So a compromised control plane cannot mount Caddy's ACME keys, BuildKit's cache or another service's data into a workload.
- Network isolation (nftables, [05](05-networking-ingress.md) §1) is applied before any workload, build or one-off container starts; if it cannot be applied, nothing starts (fail closed). Relaxations (`LR_FIREWALL=off`, `LR_FIREWALL_ALLOW_PRIVATE`) are local config only.
- Container defaults: `no-new-privileges`, `cap-drop ALL` plus the allow-list, pids/memory/CPU limits, `init: true`, log driver `json-file` with `max-size=10m,max-file=3` (log rotation without touching `daemon.json`).
- **Stopping** (D42): a workload may carry a typed `stop {signal, timeout, noInit?}`: the signal only from `SIGTERM`/`SIGINT`, the timeout 1 s–5 min (both set on the container, so Docker also uses them when the host restarts; launchroomd waits at least the timeout on every stop or removal). The init process may be left out **only** with such a stop (`noInit`, checked again on the generated container config): the image's entrypoint then execs the server as PID 1 and the kernel delivers the signal to it. That's needed for servers that drop to their own user (every database image): the init runs as root without `CAP_KILL`, so it can't forward the signal ("Operation not permitted"), exits, and the engine is killed mid-write. Without the init nothing is added (no capability, no exec); a PID 1 that doesn't reap orphans is still bounded by the pids limit, and one that ignores the signal is killed after the timeout. `KILL` stays out of the allow-list. The dashboard renders it for managed databases only (Postgres `SIGINT` = fast shutdown, MySQL, Redis/Valkey, MongoDB and ClickHouse `SIGTERM`; 60 s; `noInit`) and only for daemons announcing `stopSpec`. The Valkey image's own ENTRYPOINT is `tini -- docker-entrypoint.sh`, so its driver also renders `command: ["docker-entrypoint.sh"]`.
- Route **address upstreams** (`{"address": …}`) only inside the mesh range `100.64.0.0/10` (mesh-published replicas) or listed exactly in launchroomd's local `LR_ALLOWED_LOCAL_UPSTREAMS` (comma-separated `ip:port`; the control-plane host's install sets `127.0.0.1:8000`, the dashboard's system route). Anything else is dropped from the rendered Caddy config and logged, so a compromised control plane can't make the edge proxy the internet to loopback or host ports (Caddy's admin API, the ask endpoint, databases bound to localhost, cloud metadata). Workload upstreams (`{workload, port}`) resolve to container IPs on launchroom networks and are unaffected.
- Route **forward-auth URLs** (`protection.forwardAuth.url`) only to the origin of launchroomd's own control-plane URL (`LR_CONTROL_PLANE_URL`) or `http://<ip:port>` of an `LR_ALLOWED_LOCAL_UPSTREAMS` entry. A protected route with any other URL fails closed (answers 403, never proxied) and is logged, so a compromised control plane can neither point the auth check at an endpoint that always says yes nor use it to make the edge call arbitrary hosts.
- **Control-plane origin** (D43, §4.2): a pushed `system.controlPlane.url` is used only after it answered with a response signed by the pinned control-plane keys; `LR_CONTROL_PLANE_URL` stays the fallback. `LR_CONTROL_PLANE_FOLLOW=off` (local config) refuses following altogether, and a daemon whose configured URL is this host itself (`localhost`, `*.localhost`, `127.0.0.0/8`, `::1`, i.e. the control-plane host's own launchroomd) never follows. The forward-auth allow-list above is the configured URL's origin, the **adopted (verified) origin** and the `http://` `LR_ALLOWED_LOCAL_UPSTREAMS` entries; an unverified pushed origin is never allowed, and the Caddy config is re-rendered when the adopted origin changes.
- Route **client authentication** (`clientAuth`, D50, §4.3) only on routes whose upstreams are **all** address upstreams listed in `LR_ALLOWED_LOCAL_UPSTREAMS` (the dashboard's loopback port), and only with an edge secret that opens as this server's `client_auth` IngressSecret. Any other route with `clientAuth` answers 403 and is never proxied (logged once per change), so a compromised control plane can't make the edge demand client certificates for, or send the edge secret and certificate fingerprints to, an app. The exempt paths of `require` are compiled in.
- **Config files** (`files`, feature `configFiles`): at most 32 files, each ≤ 64 KiB and ≤ 256 KiB together, UTF-8 without NUL bytes. Paths are absolute and clean (the mount-path grammar, ≤ 255 bytes, no `.`/`..` segments), unique, never a parent directory of another file, never under `/proc`, `/sys` or `/dev`, never `/etc/hosts`, `/etc/hostname` or `/etc/resolv.conf` (Docker manages those), and never inside or equal to one of the workload's mount targets, so files never write into volumes. launchroomd writes them after `ContainerCreate` and before the first start through the archive endpoint (`PUT /containers/{id}/archive`, extracted at `/` with `noOverwriteDirNonDir`, no `copyUIDGID`): regular files only, mode `0444`, owner `0:0`, no directory entries (existing directories keep their owner and mode; missing parents are created by Docker). If the upload fails, the container is removed and never starts. Files live in the container's own layer, so every recreated container gets them again; their paths and content digests are part of the spec hash, so a changed file recreates the container. Content is config, not secrets: it is in the NodeState in plain text, and programs read secrets from the environment. dockerd-guard already forwards workload uploads unchanged (§2); nothing about it changed.
- **Scheduled workloads** (D51): a workload with `schedule {cron, timeZone}` must have restart `no` and no port, health or mesh publication (validated on both sides); launchroomd creates it unstarted and starts it at every due minute (docs/04 §1). It never adds a capability, exec or host access.
- The capability allow-list is generated into `protocol/schema/v1/capability-allow-list.json` from `policy.DefaultCapabilities`; the dashboard's `WorkloadCapability` enum, the Compose translator and `launchroom.json`'s schema are tested against it.
- **Data imports** (D55, [14](14-backup-and-restore.md) §12): the restore throwaway (`lr-import-…`, `launchroom.helper=import`) runs the target workload's own digest-pinned image from the verified NodeState (present locally, never pulled), attached only to that workload's environment network (taken from the NodeState, never from the task) after the firewall covers it, cap-drop ALL, `no-new-privileges`, no volumes, the spec's limits; credentials only in exec env or stdin. Volume imports go through a never-started volume helper, whose archive uploads dockerd-guard re-encodes to one shape (names under `data/`, files, directories, symlinks, hard links to earlier files; no devices, no setuid/setgid, no extended attributes). Uploaded files are used only after their digest from the signed spec matches. `LR_DATA_TRANSFERS=off` (local) turns the four tasks off.
- **Volume access helpers** (D67, §12.4): the pinned `files` image, `sleep` only, a numeric user, no network, cap-drop ALL, `no-new-privileges`, read-only root, one workload volume at `/data`; execs only the SFTP file tools, never a shell. A compromised control plane gets no more than SFTP into one of a workload's own volumes, which the Console already reaches.
- Relaxations (e.g. GPU, a needed capability) only through a **local** config file in `data/daemon/policy.yaml`, which requires host access to edit.

## 9. Observed state and reports

`POST /daemon/v1/reports`, Go types in `daemon/internal/state/messages.go`.

- Reports carry `observed_at` (daemon clock, NTP-synced) and a per-daemon sequence number `seq`. `seq` starts from the launchroomd clock (Unix µs), so it keeps increasing across restarts. The dashboard ignores reports older than the last applied one per workload, so status updates delayed by an outage can't overwrite newer ones.
- Reports are always full snapshots (`full: true`, not deltas), sent after each reconcile pass and on status changes (≥ 1 s apart), so the dashboard always converges.
- Contents:
  - `host`: CPUs, memory, disk of the data dir, Docker version, daemon version, `features` (§10), `health` (§9.1), `hardware` (§9.2), `mesh` and `links` (§5.5), `controlPlane` (§4.2), `runtime` (§2), `publicAddresses {ipv4?, ipv6?, detectedAt}` (below);
  - `ingress`: Caddy state;
  - per workload: phase, `health` (probe state), `detectedPorts` and, while the phase is `pulling`, `pull {stage (mirroring|downloading|extracting), downloadedBytes, totalBytes}` (compressed layer bytes; reported at most every 2 s while bytes move; optional, so no daemon feature is needed; see [04](04-build-and-deploy.md) §11 "Pulls");
  - `metrics` (§9.3, §9.4) and `stateRejected` (§5.7);
  - per workload (D68): `health.attempts` (checks since the container started), `pull.updatedAt` (when the pull last moved: a new stage or more bytes) and `issue {kind (crash_loop|loopback_only|wrong_port), since, port?, listening?, exitCode?, oomKilled?, restarts?}` while the container is clearly misconfigured (crash loop: Docker restarted it 3 times and it hasn't run a minute since; port issues: held for 15 s while the health check doesn't pass). Docker's embedded DNS listener (127.0.0.11) is not counted as a port;
  - `events` (D68): container lifecycle events `{seq, workload, deployment?, at, kind (created|create_failed|started|exited|restarted|health_passing|health_failing|stopped|removed), reason? (drained|not_desired|spec_changed|duplicate|port_not_bound), exitCode?, oomKilled?, restarts?, failures?, message?, output? (≤ 30 lines)}`, ≤ 256 per report, buffered (≤ 1,024) and repeated until a report carrying them was accepted; `seq` is unique per launchroomd ([15](15-observability.md) §6);
  - `host.registry {reachable, checkedAt, latencyMs?, error?}` (D68): whether the server reaches the image registry it pulls from, checked every 5 minutes ([05](05-networking-ingress.md) §6).
- **Public addresses** (`host.publicAddresses`, optional, no feature flag): where the internet reaches the server, i.e. what DNS records must point at ([05](05-networking-ingress.md) §5). The connection's source address can't tell: the control-plane launchroomd reaches the dashboard through a Docker port publish and appears as the bridge gateway (`172.18.0.1`), other servers may sit behind NAT. launchroomd asks IP echo services over HTTPS with the dial family forced (IPv4: api.ipify.org, ipv4.icanhazip.com, ifconfig.me; IPv6: api6.ipify.org, ipv6.icanhazip.com; first public answer wins, 5 s, 64 bytes) at start and every 6 h (10 min after a failure). Private, CGNAT, Docker, loopback, link-local, ULA, documentation and other special ranges are never reported (`state.PublicIP`, the same list as the dashboard's `IpClassifier`); both sides validate. `LR_PUBLIC_IP_DETECT=off` never contacts the services (install.sh `--no-public-ip-detect`), `LR_PUBLIC_IPS` (one IPv4 and/or one IPv6) sets the addresses instead. Informational only: an echo service could lie, so nothing security-relevant depends on it.
- **Reachability check** (domains behind a proxy, [05](05-networking-ingress.md) §5): the edge Caddy proxies `/.well-known/launchroom/check/<nonce>` (nonce `^[A-Za-z0-9_-]{32,128}$`) on every host, HTTP and HTTPS, before any route, to launchroomd's loopback ask server, which answers `GET` with `{"serverId", "signature"}` (`Cache-Control: no-store`): the std-base64 Ed25519 signature of the server's identity key over `launchroom-reachability/v1\n<host>\n<nonce>` (host lowercased, port and trailing dot removed, DNS-name grammar, else 400). The context prefix keeps it from ever matching an RFC 9421 signature base. Rate-limited to 20/s (burst 40, then 429).
- Workload phases: `pending`, `pulling`, `starting`, `healthy`, `unhealthy`, `draining`, `exited`, `failed`, `removed` (`running` is not emitted). Removed workloads are reported as `removed` for 10 minutes.

### 9.1 Host health

`internal/hostinfo`, every 5 min, first ~10 s after start. The latest snapshot rides along in every report as `host.health`. Facts that can't be read are omitted.

- Data-dir filesystem; the filesystem of Docker's root dir when launchroomd can see it (it shares launchroomd's mount namespace, e.g. dind; the production daemon container usually can't, then the data dir stands in).
- `/proc/meminfo` total/available; `/proc/loadavg`; `/proc/sys/fs/inotify/{max_user_watches,max_user_instances,max_queued_events}`.
- Docker networks (all vs. launchroom-managed); Docker's disk accounting (images, reclaimable images, containers); the BuildKit cache size (build role).
- Clock skew = dashboard `Date` header − daemon clock from the last response (±1 s).
- The last cleanup result (§9.5).

The dashboard stores the whitelisted facts in `servers.host_status` and derives checks with thresholds (`launchroom.server_health`, `App\Queries\ServerHealth`), shown on the server page:

| Check | Warning | Critical |
|---|---|---|
| Disk free | < 20 % | < 10 % |
| Memory available | < 15 % | < 5 % |
| 5-min load per CPU | ≥ 2 | ≥ 4 |
| inotify | watches < 65 536 or instances < 256 | |
| Networks outside launchroom's range (Docker's default pools run out near 30) | ≥ 20 | ≥ 28 |
| launchroom's environment range used | ≥ 80 % | |
| Clock skew (signatures fail at 60 s) | ≥ 5 s | ≥ 30 s |
| Cleanup errors | any | |

Facts older than 15 min make every check unknown.

### 9.2 Hardware facts

`internal/hostinfo/hardware.go`, at start and with every health snapshot. No network, no shell.

- `/proc/cpuinfo` (model, vendor; ARM implementer/part).
- The world-readable `/sys/class/dmi/id` fields (vendor, product, family, board, BIOS vendor, chassis type; never serials/UUIDs).
- The device-tree model (or the cpuinfo `Model` line, since Docker masks `/sys/firmware`).
- Virtualization (DMI strings, `/sys/hypervisor/type`, the "server is a container" heuristic, Docker Desktop/OrbStack, the CPU `hypervisor` flag).
- OS name and kernel from Docker's info (uname fallback).
- The host time zone (`LR_HOST_TIMEZONE` from the installer, then `/proc/1/root/etc/{timezone,localtime}`; UTC is dropped).
- A system battery (`/sys/class/power_supply/*` of type Battery, scope not Device).

Sent as `host.hardware` at enrollment and in reports when changed, unacknowledged or an hour old. The dashboard maps them to a device and provider ([design/direction.md](design/direction.md) §7.3).

### 9.3 Usage metrics

`internal/metrics`, sampled every 15 s, aggregated per minute.

- Host: CPU % (`/proc/stat` deltas), memory used/total, disk used/total (Docker's root dir, else the data dir), rx/tx bytes/s of the physical interfaces (`/proc/net/dev` without lo, Docker bridges, veths, `tailscale0`, tunnels), load1.
- Per running managed workload container: CPU cores and memory from Docker's one-shot stats API (`StatsOps`, refuses unmanaged containers). The stats API is used instead of cgroup files because launchroomd's container has a private cgroup namespace.
- Completed minutes ride in every report as `metrics` until a report carrying them was accepted (≤ 60 host minutes and ≤ 4096 workload rows buffered). A completed minute also triggers a report.

### 9.4 Request metrics

`internal/traffic`, [15](15-observability.md) §1.

- The edge Caddy writes its HTTPS access log through Caddy's `net` writer to launchroomd on `LR_INGRESS_ACCESS_LOG_ADDR` (loopback, default `127.0.0.1:2022`; `soft_start`, so Caddy serves while launchroomd is down).
- A `filter` encoder deletes headers, client addresses, the URI and TLS details inside Caddy, and the default log excludes the access logger.
- launchroomd attributes each line by host to a route and folds it into per-(route, minute) counters (requests, 2xx–5xx, bytes in/out) and a 15-bucket latency histogram.
- Completed minutes ship as `metrics.requests` with the same buffer/ack semantics as usage metrics (≤ 4096 route-minutes). Nothing per request is stored or forwarded.

### 9.5 Disk cleanup

`internal/cleanup`: daily (first run ~15 min after start, jittered) and when Docker's filesystem (else the data dir's) is ≥ 85 % full (at most every 30 min). See [04](04-build-and-deploy.md) §8.

### 9.6 Liveness

- Every signed request updates `last_seen_at` and brings `unreachable`/`offline` servers back `online`.
- `launchroom:servers:check-liveness` runs every 15 s. It marks servers `unreachable` after 75 s and `offline` after 300 s without a request (hysteresis), moves stateless replicas off servers unseen for `node_dead_after` (90 s, [02](02-architecture.md) §6.1), requeues expired task leases and releases build pins nobody leased in time ([04](04-build-and-deploy.md) §5.1).

## 10. Versioning and compatibility

- `apiVersion` on every document. launchroomd advertises `protocolVersions` at enrollment and on every request header (`X-Lr-Protocol-Versions`). The dashboard renders the newest version both understand.
- **Optional fields and daemon features**: decoding is strict, so a launchroomd refuses a whole NodeState with a field it doesn't know. A new enum value needs a feature too, since an unknown value fails the whole NodeState as well. New optional NodeState fields add a feature name instead of bumping the protocol version.
  - Every launchroomd lists the optional features it understands in every report (`Report.host.features`, `state.SupportedFeatures`).
  - The dashboard stores the known ones in `servers.daemon_features` (a change re-renders every mesh server) and renders an optional field only for servers that announced its feature (`Server::supportsDaemonFeature()`, `App\Enums\DaemonFeature`).
  - Examples: `linkProbe` (§5.5), `shipLogs` (§5.6), `stopSpec` (§8), `controlPlaneUrl` = `system.controlPlane` and `Report.host.controlPlane` (§4.2), `clientCertificates` = `routes[].clientAuth`, the `client_auth` IngressSecret purpose and `POST /daemon/v1/client-certificate` (§4.3), `gitProviders` = build/plan specs with `repo.provider` `gitlab`/`gitea`, `ssh://` clone URLs with `repo.knownHosts` and `sshKey`, and `repo.ref` ([19](19-git-providers.md) §6), `dnsProviders` = `ingress.dns` providers beyond `cloudflare`/`hetzner` with per-provider credential names ([05](05-networking-ingress.md) §5, D47), `generatedDockerfile` (§7), `consoleSessions`/`dataSessions` and `mongoClickhouse` (§11), `dataTransfer` = the `db_import`, `volume_import`, `db_export` and `volume_export` tasks and the transfer chunk endpoints, announced only while the local `LR_DATA_TRANSFERS` isn't `off` (D55), `remoteAccessSsh` / `remoteAccessDatabase` = the `access` task of that kind and `TaskHeartbeat.access` (§12, D61), announced only when the local policy allows it on a rootful Linux host with the firewall enforced, `preparedVolumes` = NodeState volumes no workload mounts are created too, without an owner label, so a service moving here has its volumes before it runs (D56, [14](14-backup-and-restore.md) §13.2), `inventory`, `containerInspection` and `waf` (§13, D63), `configFiles` = `WorkloadSpec.files` (§8).
- The dashboard supports N-1 daemon protocol versions, so fleets can upgrade gradually. The supported set is `launchroom.daemon.protocol_versions` (`['v1']`). A signed daemon request whose `X-Lr-Protocol-Versions` shares none of them gets `426`. The negotiated version is stored in `servers.protocol_version`.
- The dashboard never rolls a daemon to a version newer than its own: fleet updates target the dashboard's version, so the control plane is always updated first. The dashboard UI flags outdated daemons and offers "update all" (rolling, one server at a time, edges last).

## 11. Interactive sessions: console and database data (D35)

Two features need a low-latency, bidirectional stream between a browser and one container: the **Console** (a terminal inside a workload container) and the **Data** tab (table/key browser and SQL console of a managed database). launchroomd only dials out and exposes no port, so the stream is **relayed by Reverb**, the Pusher-protocol WebSocket server the dashboard already runs for nudges (§4). Nothing new listens anywhere: the browser and launchroomd both connect *out* to `/app/<key>` and meet on one private channel per session. Reverb is transport, not trust: every frame is authenticated end to end, every authoritative instruction from the dashboard is Ed25519-signed like every other dashboard payload, and launchroomd enforces its own policy (below) whatever the dashboard asks.

### 11.1 Lifecycle

```
Browser                         Dashboard                                  launchroomd (server of the replica)
───────                         ─────────                                  ───────────────────────────────────
Open console (signed-in
session, no prompt, D60) ────▶ authorize (containers.exec / databases.query),
                               create interactive_sessions row (ses_…),
                               32-byte session key K, ticket T (lr_st_…, 60 s,
                               single use, SHA-256 stored), daemon task
                               `session` pinned to the replica's server,
                               audit session.*_started, nudge task.available ──▶ lease (K sealed as TaskToken purpose
◀── {session, channel, T, K, limits}                                              "session", bound to server + task)
subscribe private-session.ses_…                                                   dial Reverb, POST /daemon/v1/broadcasting/auth
 → POST /sessions/{ses}/authorize                                                 (signed; allowed only for a session task this
   {socket_id, T}: T consumed,                                                     server holds), subscribe the same channel
   Pusher auth for this socket ◀──                                       ◀── `ready` (every 1 s until hello)
`hello {cols, rows}` ────────────────────── Reverb ────────────────────────────▶ console: exec the shell (TTY); data: idle
◀────────────── `out` / `result` frames ────── Reverb ──────────────────────────  …
`in` / `resize` / `ping` ────────────────── Reverb ────────────────────────────▶
data op: POST /sessions/{ses}/commands ─▶ audit (statements), history row,
                               signed `session.command` server event ──────────▶ verify signature, run, `result` frames
End / tab closed ─────────────▶ cancel task + signed `session.end` ─────────────▶ stop, complete task {session: result}
                               ◀── task completion: audit session.*_ended, ended_at, duration, exit code, bytes
```

- **Task.** A session is a daemon task of type `session` (§7): pinned to the server of the chosen replica, `max_attempts` 1, deadline = the absolute timeout, leased like any task (a nudge makes it ~25 ms). Leasing seals the session key; heartbeats (every 10 s for sessions) are the dashboard's **re-authorization point** (below); `cancel: true` ends the session; completion carries a `SessionResult` and is when the dashboard writes the end audit entry. launchroomd runs session tasks in their own runner (8 at a time) so a console never waits behind a build.
- **Spec** (`SessionSpec`, `protocol/schema/v1/task.json`): `session` (`ses_` + ULID), `kind` (`console`|`data`), `workload` (the replica's workload id), `channel` (`private-session.<session>`, must match), `key` (sealed TaskToken, purpose `session`; 32 random bytes, base64url), `idleTimeout`; console: `shell` (empty = automatic, else one of the allow-list), `cols`/`rows` (initial size), `record` (opt-in transcript, §11.5); data: `engine` (`postgres`|`mysql`|`mariadb`|`redis`|`valkey`|`mongodb`|`clickhouse`; the last two only for daemons announcing `mongoClickhouse`, D47).
- **Reconnects** (2026-10-25): opening a session ends the same user's live sessions of the same kind on the same service first (`EndSession`, reason "Replaced by a new session."); sessions already being ended don't count toward the per-user limit. A tab that disconnects and reconnects at once therefore never waits for the old session's server to notice the browser left.
- **Start failures** (2026-10-25): when launchroomd can't start a session (no shell, a refusal by its policy or dockerd-guard, the container not running, Reverb unreachable) it fails the task with the reason as `error` (also after `hello`: the `end` frame then carries `message`). The dashboard logs every session that ended before it was used (`FinishSession`, warning with the task error) and shows one sentence for it (`App\Services\Sessions\SessionFailures`, `SessionHistoryItemData.failure`, `GET /sessions/{ses}`). The browser asks for the session every 2 s while it says "Connecting…" and gives up after 25 s (it ends the session and shows why, with "Try again"); a pickup by a server usually takes under a second. It also listens for the dashboard's `session.end` event (replaced, never picked up, ended by an admin) and ends a session on "unavailable" from Reverb before it joined.
- **Result** (`TaskCompletion.session`): `startedAt` (shell started / first command), `endReason` (`closed` the user ended it or closed the tab · `exited` the shell exited · `idle` · `deadline` · `disconnected` no frame from the browser for 45 s · `ended` the dashboard ended it · `never_connected` no browser within 30 s · `refused` local policy · `error`), `exitCode` (console), `bytesIn`/`bytesOut` (frame payload bytes), `commands` (data: one `{op, status ok|error|refused|truncated, rows, durationMs}` per command, at most 1000).

### 11.2 Channel, tickets and frames

- **Channel** `private-session.ses_<ULID>`. The browser can't join it through the normal `/broadcasting/auth` (`routes/channels.php` denies `session.*`); the only way in is `POST /sessions/{session}/authorize {socket_id, ticket}`, which requires the signed-in user who opened the session, the permission still held, an unexpired (60 s) and **unused** ticket (`hash_equals` on SHA-256, consumed atomically), and answers the Pusher auth for exactly that socket and channel. launchroomd joins through the signed `POST /daemon/v1/broadcasting/auth`, which also authorizes `private-session.<ses>` for a session task this server holds (leased, not finished). Reverb accepts client events only from channel members (`accept_client_events_from: members`), so the channel has exactly two members.
- **Frames** are Pusher client events named `client-frame` with data `{"p": "<payload JSON as a string>", "m": "<base64url HMAC-SHA256>"}`. `m = HMAC-SHA256(K, "launchroom-session-v1\n" + dir + "\n" + session + "\n" + p)` with `dir` = `b2d` (browser → daemon) or `d2b`, so frames can't be reflected or moved to another session. The receiver checks the MAC (constant time) over the exact `p` bytes **before** parsing, then requires `p.s` (sequence, from 1) to be strictly greater than the last accepted one (Reverb keeps order; frames sent before the other side subscribed are simply lost, hence not "exactly +1"). Anything that fails is dropped; 20 bad frames end the session (`error`).
- **Payloads** (`p`): browser → daemon `hello {cols, rows}`, `in {d}` (base64url bytes, ≤ 4 KiB), `resize {cols, rows}` (2–500 × 2–200), `ping {}` (every 15 s), `bye {}`; daemon → browser `ready {kind}`, `started {shell}` (console), `out {d}` (≤ 4 KiB, coalesced for 15 ms), `exit {code}`, `result {op, part, parts, d}` (data: the result JSON in base64url chunks of ≤ 4 KiB, ≤ 1 MiB in total), `end {reason, message?}` (`message`: why a session couldn't start, ≤ 200 characters), `pong {}`. Every payload has `s` and `t` (type).
- **Dashboard → daemon commands** are Pusher *server* events (only the holder of the Reverb app secret, the dashboard, can send events not named `client-*`): `session.command` and `session.end`, data `{"payload": "<SessionCommand JSON>", "signature": "kid=…, sig=…"}`, the signature exactly like a signed response (§4.1, Ed25519 over the payload bytes with the control-plane signing key). `SessionCommand` (`protocol/schema/v1/session-command.json`, strict): `session`, `op` (`op_` + ULID, unique), `issuedAt` (±60 s of the daemon clock), `command` (`end`|`schema`|`rows`|`keys`|`key`|`query`) and its typed fields (`table {schema?, name}` + `page`/`pageSize` ≤ 100; `pattern` (glob ≤ 256) + `cursor`; `key` (≤ 1024 bytes); `statement` (≤ 16 KiB) + `allowWrites`). launchroomd verifies the signature against its trusted key set, the session id, the time window and that the op id is new, then runs it. Reverb's HTTP API limit is raised to 100 kB for these (`REVERB_MAX_REQUEST_SIZE`).

### 11.3 Limits and kill switches

| Limit | Value |
|---|---|
| Ticket | 60 s, single use, user + session bound |
| Idle (no `in` / no command) | 15 min (console and data) |
| Absolute | 60 min (`launchroom.sessions.max_minutes`, ≤ 4 h); the task deadline |
| Browser liveness | ping every 15 s, ended after 45 s without a frame; ≤ 30 s from `ready` to `hello` |
| Frame | `p` ≤ 8 KiB, data ≤ 4 KiB; browser input ≤ 64 KiB/s (burst 256 KiB), more ends the session |
| Data results | ≤ 500 rows, cells ≤ 8 KiB (longer ones cut and flagged), ≤ 1 MiB per result, statement timeout 15 s, Redis values ≤ 200 items / 64 KiB |
| Open sessions | ≤ 3 per user (a reconnect replaces the session of the same kind and service), ≤ 8 per server (launchroomd's session runner) |

Kill switches, from the most local:
1. **End** in the tab (or closing it: `bye` + `POST /sessions/{ses}/end` by beacon), or **End** on someone else's session by an owner/admin: `cancel_requested_at` + a signed `session.end` (ends within a second); the next heartbeat says `cancel` anyway.
2. **Every heartbeat re-checks** the user (still a member, still holding the permission), the service (not removed, the replica still placed there) and the instance switch; any "no" cancels. Removing a member or changing their role or grants ends their sessions at once.
3. **Fail closed:** launchroomd ends a session when two heartbeats in a row fail (≤ 30 s without the dashboard) or Reverb disconnects; there is no reconnect into a running shell.
4. **Instance:** `LAUNCHROOM_INTERACTIVE_SESSIONS=false` (`launchroom.sessions.enabled`) refuses new sessions and cancels open ones on their next heartbeat.
5. **Server (local, not overridable):** `LR_INTERACTIVE_SESSIONS=on|console|data|off` in the server's `.env` (default `on`). Off: launchroomd doesn't even lease session tasks; a kind that isn't allowed is refused (`endReason: refused`); launchroomd announces only the allowed kinds as `consoleSessions`/`dataSessions` in `Report.host.features`, and the dashboard offers only replicas on servers that announced the kind.
6. **Pickup timeout:** a session no server leased within 2 minutes is ended by `launchroom:sessions:sweep` (every minute), which also closes sessions whose task finished without the listener closing them.

### 11.4 launchroomd policy

- **Target:** only the container of `spec.workload`, found by labels (`launchroom.managed=true`, this server, `launchroom.workload=<id>`), running (a stopped duplicate never hides the running one), and the workload must be in the current verified NodeState. Never system containers (`lr-system-*`), one-off/run_once or build containers, never the host.
- **Console argv:** exactly one shell from the allow-list `/bin/sh`, `/bin/bash`, `/bin/ash`, `/bin/zsh`, no arguments; automatic = the first of `/bin/bash`, `/bin/ash`, `/bin/sh` that exists in the container (Engine API path stat, nothing executed to find out; a failed stat of one candidate is logged and the next one tried). An image without one fails with "This image has no shell". A shell that exits with 126 or 127 within 3 s of starting couldn't run there (not executable for the container's user, a broken link) and fails the session with that reason. Exec options are fixed: TTY, `Privileged: false`, **no `User`** (the process runs as the container's own user, so root only if the container itself runs as root), no working-dir override, env `TERM=xterm-256color` on top of the container's own (a console sees the app's runtime env, exactly like the app does; that is why exec is its own permission). Resize via the Engine API.
- **Data argv:** fixed templates per engine with grammar-checked identifiers from the workload's own env (the same rules as backups, `internal/backup/engines.go`); credentials come from the workload's sealed env and travel only in the exec env (`PGPASSWORD`, `MYSQL_PWD`, `REDISCLI_AUTH`), never in argv, a frame or a log; the database's own client inside the database container (`psql`, `mysql`/`mariadb`, `redis-cli`/`valkey-cli`), no sidecar.
  - Postgres: `psql -X -q --csv --pset=null=<random sentinel> -v ON_ERROR_STOP=1 -v FETCH_COUNT=100 --host=127.0.0.1 --username=… --dbname=… -c <statement> -c '\echo <end marker> :ROW_COUNT'`, `PGOPTIONS=-c statement_timeout=15000 [-c default_transaction_read_only=on]`, `PGAPPNAME=launchroom`. Rows are read as CSV until the cap, then the stream is closed (psql stops on EPIPE); a statement without rows reports `ROW_COUNT`. Browsing uses generated SQL with identifiers quoted by launchroomd (`"` doubled; names with control characters, `"` or `` ` `` are refused on both sides).
  - MySQL/MariaDB: `mysql --protocol=tcp --host=127.0.0.1 --user=… --database=… --xml --quick --init-command='SET SESSION max_execution_time=15000[, TRANSACTION READ ONLY]' -e <statement>` (MariaDB: `max_statement_time`), XML parsed as a stream (`xsi:nil` = NULL).
  - Redis/Valkey: `<cli> --no-auth-warning --json …`; browsing runs one read-only Lua script through `EVAL_RO` (SCAN + TYPE + PTTL, or one key's value by type); console commands are tokenized by launchroomd (redis-cli quoting) and checked against lists: always refused `SHUTDOWN`, `DEBUG`, `MONITOR`, `SYNC`, `PSYNC`, `REPLICAOF`, `SLAVEOF`, `FAILOVER`, `MODULE`, `CONFIG`, `ACL`, `SUBSCRIBE`/`PSUBSCRIBE`/`SSUBSCRIBE`, `CLIENT`, `SCRIPT`, `FUNCTION`, `BGREWRITEAOF`, `BGSAVE`, `SAVE`, `CLUSTER`, `QUIT`, `RESET`; read-only mode allows only a read-only allow-list (`GET`, `MGET`, `EXISTS`, `TYPE`, `TTL`, `PTTL`, `STRLEN`, `GETRANGE`, `SCAN`, `KEYS`, `DBSIZE`, `INFO`, `HGET`, `HGETALL`, `HMGET`, `HLEN`, `HKEYS`, `HVALS`, `HSCAN`, `LRANGE`, `LLEN`, `LINDEX`, `SMEMBERS`, `SISMEMBER`, `SCARD`, `SSCAN`, `ZRANGE`, `ZRANGEBYSCORE`, `ZSCORE`, `ZCARD`, `ZSCAN`, `XRANGE`, `XREVRANGE`, `XLEN`, `XINFO`, `OBJECT`, `MEMORY`, `PING`, `ECHO`, `TIME`).
  - ClickHouse (D47): `clickhouse-client --host=127.0.0.1 --database=… --format=JSONCompactStringsEachRowWithNames --max_execution_time=15 --max_result_rows=501 --result_overflow_mode=break [--readonly=1] --query <statement>`, credentials as `CLICKHOUSE_USER`/`CLICKHOUSE_PASSWORD` in the exec env; read-only mode is the server's own `readonly=1` (last, so the other settings still apply). Browsing reads `system.tables` of the database and `SELECT * … LIMIT/OFFSET` with backquoted names (backslashes refused too: they escape inside ClickHouse's backquotes).
  - MongoDB (D47): `mongosh --quiet --nodb --norc --eval <fixed script>`; everything variable (credentials, collection, skip/limit, the command) reaches the script only as exec env read through `process.env`, so nothing a user types is evaluated as JavaScript. The script connects to `127.0.0.1:27017` with `authSource=admin` and prints one marked JSON line. Browsing: `getCollectionInfos` (+ `estimatedDocumentCount`) and `find({}).sort({_id: 1}).skip().limit()`; documents become rows with their top-level keys as columns (`_id` first, strings as-is, everything else relaxed Extended JSON). The console takes a **database command as a JSON document** (`{"find": "users", "filter": {…}}`, relaxed Extended JSON for types) run with `db.runCommand(EJSON.parse(…))`; a cursor's first batch is shown as rows (the cursor is killed), anything else as the reply. launchroomd parses the document first (strict JSON, no duplicate keys): read-only mode allows only `find`, `aggregate` (no `$out`/`$merge` anywhere), `count`, `distinct`, `listCollections`, `listIndexes`, `collStats`, `dbStats`, `explain`, `ping`, `buildInfo`, `serverStatus`, `dataSize`, `hello`/`isMaster`; users and roles, `shutdown`, `fsync`, `setParameter`/`getParameter`, `currentOp`/`killOp`/sessions, `applyOps`, `renameCollection` (can cross databases), `getLog`, `hostInfo`, `getCmdLineOpts` and authentication commands are refused even with writes allowed, and a top-level `$db` (another database) always. The audit verb is the command name (`FIND`).
  - **Read-only mode is a seatbelt, not a boundary.** It stops accidental writes (Postgres read-only transactions, MySQL `TRANSACTION READ ONLY`, ClickHouse `readonly=1`, the Redis and MongoDB allow-lists); a member with `databases.query` who wants to write turns on "Allow writes" for the session, and that choice is audited per statement. The boundary is the permission and the audit trail.

### 11.5 Transcripts (opt-in)

Console sessions are **not recorded by default.** A team owner can turn on "Record console sessions" (Settings → Team, audited); new sessions then carry `record: true` and launchroomd ships the **output** stream only (what the terminal showed, never the keystrokes, so passwords typed at a prompt are not captured unless echoed), masked against the workload's secret values like build logs, as the session task's log lines (step `console`, at most 1 MiB, then "Recording truncated"). The session's history row links "Recording"; reading it needs `containers.exec` and is audited (`session.recording_viewed`). The tab says "This session is recorded" before connecting.

## 12. Temporary remote access (D61, D67)

"Connect via SSH", "Connect with SFTP" into a volume (§12.4) and "Temporary access" for databases ([09](09-security.md) R39) are the one place launchroomd listens on a public port. Unlike sessions (§11) they don't go through Reverb: the user's own SSH or database client connects to the server directly.

### 12.1 Lifecycle

```
Browser                Dashboard                                   launchroomd (server of the replica)
───────                ─────────                                   ───────────────────────────────────
Connect via SSH ─────▶ authorize (containers.exec / databases.query),
{allowFrom, keys|      remote_accesses row (acc_…), user name, password
 password, duration}   (encrypted, wiped at the end), daemon task `access`
                       pinned to the replica's server, audit
                       access.*_started, nudge task.available ─────────▶ lease (password sealed as TaskToken
                                                                          purpose "access", bound to server + task)
                                                                          check local policy, find the container,
                                                                          (database: ledger + create the role),
                                                                          host key / certificate in memory,
                                                                          random port, nftables `access` chain,
                       ◀──── heartbeat {access: port, hostKey, certificate, events} (at once, then every 5 s)
poll GET /access/acc_… ◀ details, countdowns          re-authorize; cancel ─────▶ close, drop the role, remove the rules
End now ─────────────▶ cancel_requested_at ──────────────── next heartbeat ─▶ complete {access: AccessResult}
                       ◀── completion: audit access.*_ended, wipe the password
```

- **Task.** `access` (§7), `max_attempts` 1, pinned, deadline = the hard cap (`launchroom.remote_access.max_minutes`, 30–240, default 120). launchroomd runs access tasks in their own runner (8 at a time, heartbeats every 5 s, fail closed after two failed heartbeats). A released task (launchroomd restarted) fails like a session: the listener, keys and roles are gone.
- **Spec** (`AccessSpec`): `access` (`acc_` + ULID), `kind` (`ssh`|`database`|`volume`), `workload`, `engine` (database: `postgres`|`redis`|`valkey`), `user` (ssh and volume `^lr-[a-z0-9]{10}$`, database `^lr_tmp_[a-z0-9]{12}$`), `authorizedKeys` (ssh and volume, 0–4 OpenSSH public keys: ed25519, ECDSA, RSA ≥ 3072), `password` (sealed TaskToken, purpose `access`; required for database, required for ssh and volume without keys, refused with keys), `volume` (volume only: the `vol_` id, which the workload must mount), `image` (volume only: the SFTP helper image, digest-pinned, a digest launchroomd pins for the `files` component), `allowFrom` (1–16 prefixes; IPv4 no wider than /8, IPv6 no wider than /32; no link-local/metadata, multicast or unspecified), `hosts` (0–4 addresses or DNS names for the certificate), `allowWrites` (database: row writes; volume: the volume is mounted writable), `idleTimeout` (5m–1h).
- **Heartbeat** `TaskHeartbeat.access` (`AccessStatus`): `port`, `hostKey {type, sha256, publicKey}` (ssh), `certificate {sha256, pem}` (database), `privateAddresses` (≤ 4 RFC 1918/ULA interface addresses), `behindNat` (the detected public IPv4 is not on any interface), `activeConnections`, `lastActivityAt`, `owner {uid, gid}` (volume: who SFTP reads and writes as), `events` (≤ 50 since the last heartbeat: `connected` with `remote`, `method` `password`|`publickey`, `channel` `shell`|`exec`|`sftp`|`database`; `auth_failed` with `remote` and `count`; `locked`) and `dropped` (events that didn't fit). launchroomd sends the first heartbeat as soon as the port listens.
- **Result** (`TaskCompletion.access`, `AccessResult`): `endReason` (`ended` the dashboard cancelled it · `idle` · `deadline` · `locked` · `refused` local policy · `error`), `startedAt`, `connections`, `authFailures`, `bytesIn`, `bytesOut`, and the `events` after the last heartbeat.

### 12.2 launchroomd

`internal/access` (`Handler`, `Manager`, `sshfront`, `sftpfs`, `dbproxy`).
- **Target**: like a session (§11.4): the running managed container of `spec.workload`, in the verified NodeState, never a system, task or helper container.
- **SSH** (`golang.org/x/crypto/ssh` server): only `spec.user`; public key auth against `authorizedKeys` or the password (constant-time compare). Channels: `session` only. Requests: `pty-req`, `window-change` (exec resize), `env` (ignored), `shell` (TTY exec of the console's automatic shell), `exec` (`<shell> -c <command>`, TTY if a PTY was requested; stdout, stderr and exit status relayed), `subsystem sftp` (`github.com/pkg/sftp` request server over `internal/access/sftpfs`). Everything else, and every other channel type (`direct-tcpip`, `forwarded-tcpip`, `x11`, `auth-agent@openssh.com`), is refused; global `tcpip-forward` requests are answered false.
- **SFTP backend** (`sftpfs`): every operation is an exec of a file tool in the container, as its user, argv only with `--` before paths: `stat -c` (`-L` for stat, without for lstat), `ls -1A` + batched `stat` for listings (≤ 200 paths per exec), `cat`/`tail -c +N` for reads (a sequential stream with a 16 MiB reorder window, restarted for a backward seek), `dd of=` / `tee -a` for writes (in order, out-of-order writes buffered up to 16 MiB), `mkdir`, `rmdir`, `rm -f`, `mv`, `ln -s`, `readlink`, `chmod`, `truncate -s`. Times and owners are accepted and ignored.
- **Database proxy** (`dbproxy`): accepts, checks the allow-list, terminates TLS (TLS 1.2+, the access certificate), checks the first message (Postgres `StartupMessage`: `user` = the role, no `replication`, `database` absent or the service's database, which is added when absent; CancelRequest is passed through without TLS: it carries no credentials; Redis: `AUTH <user> <pass>` or `HELLO <v> AUTH <user> <pass>` with the role's name), dials the container's address on its environment network and the engine's port, and splices. Roles: §12.3.
- **Manager**: the set of running accesses. The firewall renders the `access` chain from it ([05](05-networking-ingress.md) §8) and syncs on every change; idle and hard-cap timers; the per-address connection rate limit; the role ledger.

### 12.3 Temporary database roles

Run with the engine's own client inside the database container, credentials in the exec env (as §11.4), statements on **stdin** (never argv, so they're not in the container's process list):
- **Postgres** (`psql -X -q -v ON_ERROR_STOP=1 -f -`): `CREATE ROLE "<role>" LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS CONNECTION LIMIT 10 PASSWORD '<SCRAM-SHA-256 verifier>' VALID UNTIL '<cap + 5 min>'`, `GRANT CONNECT ON DATABASE`, read: `pg_read_all_data` (14+, else `USAGE` + `SELECT` on every non-system schema) and `ALTER ROLE … SET default_transaction_read_only = on`; writes: also `pg_write_all_data` (14+, else `INSERT, UPDATE, DELETE`). Drop: `pg_terminate_backend` for the role's connections, `REASSIGN OWNED BY` the role `TO` the service user, `DROP OWNED`, `DROP ROLE IF EXISTS`. Identifiers are double-quoted with `"` doubled and literals single-quoted with `'` doubled; generated names and verifiers come from fixed alphabets and are checked again before quoting.
- **Redis/Valkey** (`<cli> --no-auth-warning`, args as argv, the password only as `#<sha256 hex>`): `ACL SETUSER <role> reset on #<hash> ~* &* -@all +@read -@dangerous +ping +echo +select +hello +auth` (writes add `+@write +multi +exec +discard +watch +unwatch`, still `-@dangerous`). Drop: `CLIENT KILL USER <role>`, `ACL DELUSER <role>`. Needs Redis 6 / Valkey (ACLs).
- The ledger (`data/access/roles.json`, 0600, atomic writes) gets an entry **before** the create and loses it after a successful drop.

### 12.4 Volume access (D67)

"Connect with SFTP" into one volume. The SSH front end and the SFTP backend (§12.2) are the same as for SSH; the backend's file tools run in a helper container instead of the workload:
- **Target**: the workload must be in the verified NodeState and mount `spec.volume`; the Docker volume is the one managed `lr-v_…` volume labelled with that id (never a system volume), and its service and environment labels, when set, must be the workload's. The workload need not run (a crash-looping service is exactly when its files need fixing). The task is pinned to the volume's server.
- **Helper** (`internal/access/volume.go`): `lr-acc-<12 hex>`, labels `launchroom.helper=access` and `launchroom.access=<acc id>`, the pinned image (`system_images.files`, busybox 1.37.0-glibc; pulled by digest when missing), entrypoint `sleep <hard cap + 1 min, ≤ 5 h>`, user `<uid>:<gid>`, network `none`, cap-drop ALL, `no-new-privileges`, read-only root, an init, 128 MiB, 128 pids, one CPU, the json-file log limits, and exactly the volume at `/data` (read-only unless `allowWrites`).
- **Owner**: before the helper, a never-started probe of the same shape (user `65534:65534`, read-only) streams `GET /containers/{id}/archive?path=/data`; the first entry's `uid:gid` is the volume's top folder, and the helper runs as that user, so new files belong to the same user as the service's. Reported as `AccessStatus.owner`.
- **SFTP** serves `/data` as `/` (`NewFSAt`): every client path is cleaned as an absolute path and joined below `/data`, so `..` stops at the volume's top folder and names like `proc` are ordinary folders of the volume. Symlink targets are written as the client sent them (the service follows them, with its own mount path). Shell and exec requests are refused.
- **End**: the helper is removed when the access ends (and on a failed start); the access also ends when the workload leaves the desired state (a restore, import or move holds the service), because backups, restores and imports count an access helper as a user of the volume (they wait for it, `pause` backups pause it). Helpers whose access isn't running in this process are swept at start and on every reconcile pass.
- **dockerd-guard**: a class of its own (`launchroom.helper=access`): the create shape above exactly (image digest from the `files` pins, `sleep` with ≤ 18,000 s, a numeric user, one workload volume at `/data`, no ports, environment or restart policy), start allowed, execs only with argv[0] one of the SFTP tools (`ls`, `stat`, `cat`, `tail`, `dd`, `tee`, `mkdir`, `rmdir`, `rm`, `mv`, `ln`, `readlink`, `chmod`, `truncate`) and without TTY, user or environment, archive reads allowed, uploads refused.
- **Local policy**: `LR_REMOTE_ACCESS` takes a list of kinds (`ssh,volume`); `volume` is not gated by `LR_INTERACTIVE_SESSIONS` (no shell, no session). launchroomd announces `remoteAccessVolume` when allowed on a host that can publish the port.

## 13. Security center (D63)

The protocol side of [22](22-security-center.md): two task types and the web firewall's settings, state and results. Go types: `daemon/internal/state/inventory.go`, `inspect.go`, `waf.go`; fixtures `task-lease.inventory.json`, `task-completion.inventory.json`, `task-lease.inspect-container.json`, `task-completion.container-inspection.json`, `node-state.waf.json`, `report.waf.json`.

### 13.1 `inventory` task

- **Spec** (`InventorySpec`): `image` (digest-pinned, in the registry's `apps/` or `mirror/` repositories), `platform` (the manifest scanned in a multi-platform index; leased only by build servers of that platform), `service` (required for and only valid with `apps/` images, which must be that service's repository; the task's registry token covers exactly it, §4.5), optional `source {repo, cloneToken | sshKey, rootDir}` (the build's commit; credentials sealed at lease time like a build's, §7.2). Deadline 15 min, 3 attempts.
- **launchroomd** (`internal/inventory`): with a source, a shallow clone through the build's clone code and checkout rules, reading only manifests and lockfiles ([22](22-security-center.md) §3.3); then one BuildKit solve built from LLB: the image mounted read-only into an exec of `build.SBOMGenerator` (`BUILDKIT_SCAN_SOURCE`/`BUILDKIT_SCAN_DESTINATION`, network none), output exported with the local exporter; for Next.js images the same solve exports `.next/server` and `.next/static/chunks` file names and module references (≤ 64 MiB read). The SPDX document (≤ 64 MiB) is normalised and deleted with the work directory.
- **Result** (`TaskCompletion.inventory`, `InventoryResult`): `image`, `platform`, `generator`, `distro? {id, versionId?}`, `sourceAnalyzed`, `lockfiles? [{path, kind, packages}]` (≤ 32, only with `sourceAnalyzed`), `components [InventoryComponent]` (≤ 20,000, unique by ecosystem, name and version), `dropped?`, `truncated?` (only at 20,000), `notes?` (≤ 16 plain strings ≤ 256 bytes), `durationMs`. launchroomd keeps the encoded completion under 12 MiB (the document limit is 16 MiB) and truncates further when needed.
- **`InventoryComponent`**: `ecosystem` (`npm`, `pypi`, `go`, `maven`, `nuget`, `cargo`, `rubygems`, `packagist`, `hex`, `pub`, `deb`, `apk`, `rpm`, `binary`), `name` (`^[A-Za-z0-9@_][A-Za-z0-9@._/:+~-]{0,255}$`), `version` (`^[A-Za-z0-9][A-Za-z0-9._+~:^-]{0,127}$`), `purl?` (printable ASCII, ≤ 1024), `kind` (`os` only for deb/apk/rpm, `library` only for language packages, `runtime`), `scope` (`runtime`, `dev`, `client`, `unknown`; dev and client only for libraries), `presence` (`installed`, `bundled`; bundled only for npm), `direct?` (libraries only).

### 13.2 `inspect_container` task

- **Spec** (`InspectContainerSpec`): `workload` (in the verified NodeState), `maskEnv?` (≤ 1024 names, as `run_once`). Pinned to the replica's server, `max_attempts` 1, deadline 3 min.
- **launchroomd** (`internal/inspect`): the running managed container of the workload, found like a session's (§11.4); never system, task or helper containers. Through dockerd-guard: inspect, `GET /containers/{id}/changes`, `HEAD /containers/{id}/archive?path=` for at most 256 added or modified paths (stat only, highest priority first), `GET /containers/{id}/top` without `ps_args` ([09](09-security.md) §6.2). Nothing is executed in the container and no file content is read.
- **Fixed rules** (constants in `internal/inspect`):
  - left out of the entries (still counted in `total`): Docker's init mount `/sbin/docker-init` and `/usr/sbin/docker-init` when the container runs with Docker's init (`HostConfig.Init`, every launchroom workload unless `noInit`), and `modified` directories with changes below them (Docker lists every parent of a change; the children carry the flags);
  - collapsed (counted per prefix, not listed): `/root/.npm`, `/root/.cache`, `/home/*/.npm`, `/home/*/.cache`, `/home/*/.yarn`, `/root/.yarn`, `/root/.local/share/pnpm`, any `.next/cache`, any `__pycache__`, `/var/cache`, `/var/log`, `/var/lib/apt/lists`, `/run`, `/var/run`;
  - change flags: `executable` (regular file with an execute bit), `system_path` (under `/bin`, `/sbin`, `/usr/bin`, `/usr/sbin`, `/usr/local/bin`, `/usr/local/sbin`, `/lib`, `/lib64`, `/usr/lib`, `/usr/lib64`), `sensitive_file` (`/etc/passwd`, `/etc/shadow`, `/etc/group`, `/etc/sudoers` and `/etc/sudoers.d/*`, `/etc/crontab`, `/etc/cron.*`, `/var/spool/cron/*`, `/etc/ld.so.preload`, `/etc/profile`, `/etc/profile.d/*`, `/etc/bash.bashrc`, `/root/.bashrc`, `/root/.profile`, any `.ssh/` path), `app_code` (under the working directory, outside collapsed prefixes), `temp_dir` (under `/tmp`, `/var/tmp`, `/dev/shm`), `hidden` (a path segment starting with `.` outside home directories and the working directory);
  - order of entries: flagged (concerning flags first: `executable`, `system_path`, `sensitive_file`), then `app_code`, then the rest, each by path;
  - process flags: `entry` (its parent is not in the list), `shell` (`sh`, `bash`, `ash`, `dash`, `zsh`, `ksh` as the program, not the entry process, and not a `-c` shell whose parent is a package manager running a script: `npm`, `npx`, `yarn`, `pnpm`, `bun`, or `node`/`bun` running one of them, as `npm start` runs `sh -c node server.js`), `network_tool` (`nc`, `ncat`, `netcat`, `socat`, `curl`, `wget`, `telnet`, `ssh`, `scp`, `nmap`, `masscan`), `miner` (`xmrig`, `minerd`, `cpuminer`, `kinsing`, `kdevtmpfsi` in the program name), `temp_executable` (the program path starts with `/tmp/`, `/var/tmp/` or `/dev/shm/`), `inline_code` (an interpreter given code on its command line: `python -c`, `perl -e`, `node -e`/`--eval`, `php -r`, `ruby -e`, `sh -c`; not for the entry process or a package manager's script shell), `added_binary` (the program is an added path of the changes);
  - paths and commands: control bytes and invalid UTF-8 escaped as `\xNN`, a backslash as `\\`; commands masked against `maskEnv` values (raw, base64, URL-encoded, URL passwords) and cut to 512 bytes.
- **Result** (`TaskCompletion.containerInspection`, `ContainerInspectionResult`): `workload`, `containerId`, `imageId` (`sha256:…`), `imageMatches` (the image of the NodeState's reference), `startedAt`, `restartCount`, `user?`, `workingDir?`, `changes {total, entries [{path, kind (added|modified|deleted), type? (file|dir|symlink|other), size?, executable?, modifiedAt?, flags?}] (≤ 2000, ≤ 256 with stat fields, none for deleted), collapsed? [{prefix, count}] (≤ 64), truncated?}` (entries plus collapsed counts ≤ total), `processes {total, entries [{pid, ppid, user, command, flags?}] (≤ 256, unique pids), truncated?, parentsUnknown?}` (`parentsUnknown`: the host's `ps` printed no parent PIDs, as busybox's doesn't; every `ppid` is 0 and only the lowest PID is flagged `entry`), `inspectedAt`.
- A container that isn't running fails the task with "The container isn't running"; the dashboard marks that replica unchecked.

### 13.3 Web firewall settings (`RouteSpec.waf`)

Only for edges announcing `waf`; omitted means off.

```jsonc
"waf": {
  "mode": "block",                       // block | log
  "paranoia": 2,                         // 1 (default, omitted) or 2
  "inspectBodyKiB": 128,                 // 32 (default, omitted), 128 or 1024
  "exclusions": [                        // ≤ 50, unique
    { "rule": 942100, "path": "/api/search", "target": "ARGS:q" },
    { "rule": 920350 }
  ]
}
```

- `inspectBodyKiB`: how much of a request body the firewall inspects; the rest passes uninspected, a body is never refused for its size ([22](22-security-center.md) §6.3, §6.7).
- `rule`: 911000–944999 (detection rules; initialisation, common exceptions and evaluation rules can't be excluded). `path`: `^/[A-Za-z0-9._~/-]{0,127}$`, a prefix. `target`: `ARGS:<name>`, `REQUEST_COOKIES:<name>`, `REQUEST_HEADERS:<name>` (names `[A-Za-z0-9_.-]{1,64}`), `ARGS_NAMES`, `REQUEST_COOKIES_NAMES`, `REQUEST_BODY`, `XML`, `FILES`.
- launchroomd renders the directives from its own template ([22](22-security-center.md) §6.3); nothing in the document is directive text. A route with `clientAuth` or the dashboard's system route never gets the firewall (the dashboard doesn't send it; launchroomd ignores it on routes whose upstreams are all `LR_ALLOWED_LOCAL_UPSTREAMS` entries).

### 13.4 Firewall state and results

- `Report.ingress.waf` (`WafStatus`): `state` (`on`: at least one route runs the firewall; `off`: none asks for it; `error`: Caddy refused the config with the firewall and the routes are served without it, or Caddy hasn't been reachable for 5 minutes ([22](22-security-center.md) §6.6); `unsupported`: the pinned Caddy has no firewall module), `engine` and `ruleSet` (compiled-in labels such as `coraza 3.8.1` and `OWASP CRS 4.25.0`; required unless unsupported), `routes` (> 0 exactly when on), `droppedRecords?` (audit records lost since start), `message?`.
- `Report.metrics.waf: [WafSample]` (≤ 1024), one per (route, 10-minute window), buffered and acknowledged like analytics (§9.4):

```jsonc
{ "route": "rt_01J…", "at": "2026-10-17T12:00:00Z",       // multiple of 10 minutes, UTC
  "blocked": 14, "detected": 0,                             // at least 1 together
  "categories": [{ "category": "sqli", "blocked": 2 }],     // 1–13, unique, each within the totals
  "rules": [{ "rule": 942100, "blocked": 2 }],              // ≤ 20, unique, each within the totals
  "paths": [{ "path": "/api/products/:id", "blocked": 2 }], // ≤ 10; with otherPaths they add up to the totals
  "otherPaths": { "blocked": 12 },
  "events": [{                                              // ≤ 20, inside the window, ≤ the totals per action
    "at": "2026-10-17T12:03:12Z", "action": "blocked", "method": "GET",
    "path": "/api/products/:id", "rules": [942100], "categories": ["sqli"],
    "targets": ["ARGS:id"], "clientNetwork": "203.0.113.0/24", "score": 5, "reference": "kJ3x9QpL2mN8vR4t" }] }
```

- Categories: `method`, `scanner`, `protocol`, `lfi`, `rfi`, `rce`, `php`, `generic`, `xss`, `sqli`, `session_fixation`, `java`, `other`, from the rule id (`state.WafCategoryOf`). Event `path` follows `RequestPath` (no query string); `targets` follow `^[A-Z][A-Z_]{0,39}(:[A-Za-z0-9_.\[\]-]{1,64})?$` (variable and key name only); `method` is uppercase letters; `clientNetwork` is the client's /24 (IPv4) or /48 (IPv6) network as a canonical CIDR (`state.ClientNetworkOf`; IPv4-mapped IPv6 counts as IPv4), cut by launchroomd before the event is queued: the full address is never sent; `reference` is the transaction id on the 403 page.
- The receiver: `LR_INGRESS_WAF_LOG_ADDR` (default `127.0.0.1:2023`), loopback peers only, Coraza's JSON audit records by HTTP POST ([22](22-security-center.md) §6.5).

### 13.5 Implementation notes (launchroomd)

- **Firewall** (`internal/ingress/caddy/waf.go`, `internal/waf`): the engine's `SecRequestBodyLimit` is 1 MiB because it sizes Coraza's body buffer; rule 10002 lowers every request to 32 KiB with `ctl:requestBodyLimit` and a route with `inspectBodyKiB` raises it again. `SecArgumentsLimit 256` is engine-wide (Coraza has no per-request setting), so it applies to every `inspectBodyKiB`. The feature `waf` is announced while the running Caddy carries launchroom-caddy's image labels `io.vantezzen.launchroom.waf.engine` and `…waf.ruleset`; `IngressStatus.waf` takes `engine` and `ruleSet` from them. Without the labels and with a route asking for the firewall, the state is `unsupported`. A 403 from the firewall answers with the edge page "This request was blocked by the web firewall" and `Reference {http.error.id}` (Coraza's transaction id, the event's `reference`). Records are decoded off the request path (204 first, then a queue of 1,024); a window completes one minute after its end; a late record counts in the current window. Records that aren't interrupted and have no 949110/959100 match are dropped, as are records of hosts without a route. Events without a valid method, path, client network or reference, or without a detection rule, are counted but not sampled.
- **`inspect_container`** (`internal/inspect`): Docker's `top` runs the host's `ps -ef`; on a host whose `ps` prints no `PPID` column (busybox), only the lowest PID is flagged `entry` and the result says `parentsUnknown`. Stats are skipped for paths that are directories of other changes and for paths the guard's archive grammar refuses. The task fails with "the container isn't running" when the workload's container is stopped or stops during the inspection.
- **`inventory`** (`internal/inventory`): leased by its own runner (one at a time) and holding a build slot while it runs. The scanner writes BuildKit's in-toto statement; the SPDX document is its `predicate`. The solve also copies `/etc/os-release`, `/usr/lib/os-release` and `**/.next/{server,static/chunks}/**/*.js` (excluding `node_modules`) from the image. apk virtual packages (names starting with `.`) are skipped. Without a source, Maven, NuGet, crates.io, Hex and Pub packages are `unknown` like other language packages. A failed clone doesn't fail the task: the image is scanned with `sourceAnalyzed: false` and a note.

