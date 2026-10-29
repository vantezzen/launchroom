# Protocol changelog

All changes to launchroomd ↔ dashboard contract. Format: one section per protocol version, newest first. Mark breaking changes **BREAKING**.

## v1 (unreleased)

### PHP database drivers for connected databases (2026-10-29, D71)

Additive. New daemon feature `phpExtensions`; the dashboard leases build and plan tasks carrying the field only to servers that announce it.

- `BuilderSpec.phpExtensions?: [PhpExtension]` (≤ 8, unique by name, valid for every builder kind): `{name (PhpExtensionName: pdo_pgsql|pgsql|pdo_mysql|mysqli|redis|mongodb), for (DatabaseEngine)}`. Railpack builds append the names to `RAILPACK_PHP_EXTENSIONS`; Dockerfile builds of an app with `composer.json` get the warning `dockerfile-php-extensions`.
- `BuildSummary.phpExtensions?: [PhpExtension]`: the extensions added to the Railpack plan (provider `php` only).
- Fixtures: `task-lease.plan-php.json`, `task-completion.plan.json` (`summary.phpExtensions`). Design: docs/04 §3.1.
- No schema change, behaviour: Railpack plans no longer list `RAILPACK_*` settings as plan secrets (the solve had no value for them, so any `railpackEnv` or command override failed the build); the dashboard renders `capabilities: ["NET_BIND_SERVICE"]` for workloads of Railpack PHP images.

### Service lifecycle and rollout diagnosis (2026-10-25, D68)

Additive. One new daemon feature, `serviceStop`, for the one NodeState field; every report field is optional and needs none.

- `RouteSpec.stopped?: bool` (feature `serviceStop`): the edge answers the route's hosts with its "This app is stopped" page (503) and proxies nothing. A stopped route has no upstreams; `upstreams` lost its schema `minItems` (Go still requires 1–64 on every other route, and the dashboard's validator checks the pairing). Fixture `node-state.stopped.json`.
- `Report.events?: [WorkloadEvent]` (≤ 256): `{seq (≥ 1, unique per launchroomd), workload, deployment?, at, kind (created|create_failed|started|exited|restarted|health_passing|health_failing|stopped|removed), reason? (drained|not_desired|spec_changed|duplicate|port_not_bound), exitCode?, oomKilled?, restarts?, failures?, message? (≤ 1024), output? [LogLine] (≤ 30, text ≤ 2 KiB)}`. Repeated until a report carrying them was accepted.
- `WorkloadStatus.issue?: WorkloadIssue {kind (crash_loop|loopback_only|wrong_port), since, port?, listening? [Port] (≤ 32), exitCode?, oomKilled?, restarts?}`.
- `ProbeStatus.attempts?: int` (checks since the container started).
- `PullProgress.updatedAt?: time` (when the pull last moved).
- `HostStatus.registry?: RegistryReach {reachable, checkedAt, latencyMs?, error? (≤ 512)}`.
- Fixture `report.lifecycle.json`. Design: docs/04 §8.1, docs/15 §6, docs/03 §5.2, §5.6, §9.

### SFTP into a volume (2026-10-25, D67)

Additive. New daemon feature `remoteAccessVolume`, announced when the local policy allows the kind (`LR_REMOTE_ACCESS` now also takes a list such as `ssh,volume`).

- `AccessSpec.kind` gained `volume`. A volume access takes the SSH user (`lr-…`), `authorizedKeys` or a sealed password like `ssh`, `allowWrites` (mount the volume writable), and two new fields that only it may carry: `volume` (the `vol_` id; the workload must mount it in the verified NodeState) and `image` (the SFTP helper, digest-pinned, a digest launchroomd pins for the new system component `files`: busybox 1.37.0-glibc).
- `AccessStatus.owner?: FileOwner {uid, gid}`: who the helper reads and writes as (the volume's top folder).
- Fixtures: `task-lease.access.json` (a third task, kind `volume`), `task-heartbeat.access.json` (`owner`). Design: docs/03 §12.4.

### Web firewall body limit, process parents (2026-10-25)

Additive, within the unreleased daemon features `waf` and `containerInspection`.

- `RouteWaf.inspectBodyKiB?`: 32 (default when omitted), 128 or 1024. How much of a request body the firewall inspects; the rest passes uninspected, never refused (docs/22 §6.3, §6.7). Fixture `node-state.waf.json` (the Log only route: 128).
- `ContainerProcesses.parentsUnknown?`: true when the host's `ps` printed no parent PIDs (busybox). Every `ppid` is then 0 and only the lowest PID is flagged `entry`.

### Config files (2026-10-25)

Additive. Daemon feature `configFiles`.

- `WorkloadSpec.files?: [ConfigFile]` (≤ 32): `{path, content}`. `path` is an absolute, clean container path (mount-path grammar, ≤ 255 bytes), not under `/proc`, `/sys`, `/dev`, not `/etc/hosts`, `/etc/hostname`, `/etc/resolv.conf`, unique, never a parent directory of another file and never inside a mount target; `content` is UTF-8 text without NUL, ≤ 64 KiB each and ≤ 256 KiB together. launchroomd writes them into the created container before it starts (regular files, mode 0444, owner 0:0); the spec hash covers them. Fixture `node-state.config-files.json`.

### Security center (2026-10-25, D63)

Additive. Three daemon features, each gating its part: `inventory`, `containerInspection`, `waf`. Defined with the contract; launchroomd announces them once it implements them (`waf` only when its pinned Caddy has the firewall module). Design: docs/22, protocol: docs/03 §13.

- New task type `inventory` with `InventorySpec {image (digest-pinned, apps/ or mirror/), platform, service? (required for and only with apps/ images, must be that repository's service), source? {repo, cloneToken? | sshKey? (sealed TaskToken as for builds), rootDir?}}`; result `TaskCompletion.inventory: InventoryResult {image, platform, generator, distro? {id, versionId?}, sourceAnalyzed, lockfiles? [{path, kind (npm|pnpm|yarn|bun|composer|poetry|uv|pipfile|requirements|pyproject|gemfile|gomod), packages}] (≤ 32), components [InventoryComponent {ecosystem (npm|pypi|go|maven|nuget|cargo|rubygems|packagist|hex|pub|deb|apk|rpm|binary), name, version, purl?, kind (os|library|runtime), scope (runtime|dev|client|unknown), presence (installed|bundled), direct?}] (≤ 20,000, unique by ecosystem, name, version), dropped?, truncated?, notes? (≤ 16), durationMs}`. Registry tokens: pull `apps/<service>` for a held inventory task of that service.
- New task type `inspect_container` with `InspectContainerSpec {workload, maskEnv?}`; result `TaskCompletion.containerInspection: ContainerInspectionResult {workload, containerId, imageId, imageMatches, startedAt, restartCount, user?, workingDir?, changes {total, entries [{path (InspectedPath), kind (added|modified|deleted), type? (file|dir|symlink|other), size?, executable?, modifiedAt?, flags? (executable|system_path|sensitive_file|app_code|temp_dir|hidden)}] (≤ 2000, ≤ 256 with stat fields), collapsed? [{prefix, count}] (≤ 64), truncated?}, processes {total, entries [{pid, ppid, user, command (≤ 512, masked), flags? (entry|shell|network_tool|miner|temp_executable|inline_code|added_binary)}] (≤ 256), truncated?}, inspectedAt}`.
- `RouteSpec.waf?: RouteWaf {mode (block|log), paranoia? (1|2), exclusions? [{rule (911000–944999), path? (prefix), target?}] (≤ 50, unique)}` (only to edges with `waf`).
- `IngressStatus.waf?: WafStatus {state (on|off|error|unsupported), engine?, ruleSet?, routes, droppedRecords?, message?}`.
- `ReportMetrics.waf?: [WafSample]` (≤ 1024): `{route, at (multiple of 10 min), blocked?, detected? (≥ 1 together), categories [{category, blocked?, detected?}] (1–13), rules? [{rule, blocked?, detected?}] (≤ 20), paths? [{path, blocked?, detected?}] (≤ 10), otherPaths?, events? [WafEvent {at, action (blocked|detected), method, path, rules (1–8), categories, targets? (≤ 8), clientNetwork (canonical CIDR: IPv4 /24 or IPv6 /48; the full client address is never sent), score?, reference}] (≤ 20)}`.
- `BuildInputs` validation moved into the shared `validateRepoAccess` (same rules and messages), now also used for `InventorySpec.source`.

### Temporary remote access (2026-10-25, D61)

Additive. New task type `access` with `AccessSpec {access (acc_ULID), kind ssh|database, workload, engine? (postgres|redis|valkey), user (lr-[a-z0-9]{10} or lr_tmp_[a-z0-9]{12}), authorizedKeys? (≤ 4 OpenSSH keys, RSA ≥ 3072 bit), password? (sealed TaskToken, new purpose `access`), allowFrom (1–16 canonical prefixes; IPv4 ≥ /8, IPv6 ≥ /32; no unspecified, link-local or multicast), hosts? (≤ 4 IPs or hostnames), allowWrites?, idleTimeout (5m–1h)}`; ssh takes keys or a password, never both. `TaskHeartbeat.access: AccessStatus {port, hostKey?, certificate?, privateAddresses?, behindNat?, activeConnections, lastActivityAt?, events? (≤ 50: connected / auth_failed / locked), dropped?}` and `TaskCompletion.access: AccessResult {endReason ended|idle|deadline|locked|refused|error, startedAt?, connections, authFailures, bytesIn, bytesOut, events?}`. New daemon features `remoteAccessSsh` and `remoteAccessDatabase`, announced only when the local policy allows the kind on a rootful Linux host. Fixtures: `task-lease.access.json`, `task-heartbeat.access.json`, `task-completion.access.json`. Design: docs/03 §12.

### Request analytics (2026-10-25)

Additive. New daemon feature `requestAnalytics`: the edge sends `ReportMetrics.analytics: [RequestAnalyticsSample] (≤ 1024)`, one per (route, 10-minute window): `{route, at (multiple of 10 min, UTC), requests ≥ 1, paths?: [PathCount {path, requests ≥ 1, status4xx?, status5xx?}] (≤ 50, unique), otherPaths?: PathTotals {requests, status4xx?, status5xx?}, referrers?: [ReferrerCount {host, requests ≥ 1}] (≤ 20, unique), otherReferrers?, browsers?, bots?, otherAgents?}`. Paths plus `otherPaths` add up to `requests`; referrer and agent counts don't exceed it. New scalar `RequestPath` (`/` + printable ASCII without `?` and `#`, ≤ 128 bytes): a normalised path with ids as `:id`. Older dashboards ignore the field; the dashboard reads it from any server and uses the feature only to explain missing paths. The edge Caddy now keeps the request URI in its access line, without the query string, plus `lr_referer` (host only) and `lr_agent` (one token) added by `log_append` and reduced by filter regexps inside Caddy. Fixture: `report.analytics.json`. Design: docs/15 §5.

### Builds capability on small hosts (2026-10-22, D59)

Additive, no daemon feature. The `builds` capability is `degraded` on a host under 4 GB of memory without swap, with a message that names the fix (the installers' swap step or the commands). Otherwise a `full` row may carry the applied build limits as its message. Design: docs/04 §5.2.

### Build CPU usage (2026-10-22)

Additive, no daemon feature. `ReportMetrics.host[].buildCpuCores` is the managed BuildKit container's average CPU in cores (optional, 0–4096), sampled and aggregated with the existing host metrics. It includes build processes in that container and is not attributed to a workload. Older servers omit it and the dashboard leaves the Builds row out. Design: docs/15 §2.4.

### Builds capability (2026-10-21, D58)

Additive, no daemon feature. `HostCapability.name` gained `builds` (sent only by build servers): `full`, or `unavailable` when the host restricts unprivileged user namespaces (`kernel.apparmor_restrict_unprivileged_userns=1`), launchroom's AppArmor profile `launchroom-buildkit` is not loaded and BuildKit doesn't answer; the message names the fix. Older dashboards drop unknown capability names. Design: docs/04 §5.

### Public addresses and the reachability check (2026-10-21)

Additive, no daemon feature. `Report.host` gained optional `publicAddresses: PublicAddresses {ipv4?, ipv6?, detectedAt}`: the server's public addresses from IP echo services or `LR_PUBLIC_IPS`; at least one address, each canonical and public (no private, CGNAT, Docker, loopback, link-local, ULA, documentation or other special range). Not a schema change of any document the dashboard sends: the edge Caddy serves `GET /.well-known/launchroom/check/<nonce>` on every host, answered by launchroomd with `{"serverId", "signature"}` (Ed25519 over `launchroom-reachability/v1\n<host>\n<nonce>`). Fixture: `report.public-addresses.json`. Design: docs/03 §9.

### Prepared volumes for server moves (2026-10-21, D56)

Additive, no schema change. New daemon feature `preparedVolumes`: launchroomd creates NodeState `volumes` that no workload mounts, without the `launchroom.service`/`launchroom.environment` labels; the first workload that mounts it claims it under the existing mount checks. The dashboard declares such volumes only for servers announcing the feature (a service moving there is held while its data is imported). Design: docs/14 §13.2.

### Data import and export (2026-10-21, D55)

Additive; the new task types go only to daemons that announce the new feature `dataTransfer` (only while their local `LR_DATA_TRANSFERS` is not `off`). Design: docs/14 §12.

- New task types `db_import` (`DbImportSpec {mode (inspect|import), engine (postgres|mysql|mariadb|mongodb), workload, source?: DbImportSource {kind (file|url), file?: TransferRef, url?: Sealed}, replace?, resources}`), `volume_import` (`VolumeImportSpec {mode, volume, file?: TransferRef, replace?, stripComponents? (0–8), owner?: FileOwner {uid, gid}}`), `db_export` (`DbExportSpec {engine, workload | snapshot + destination, transfer: TransferTarget}`), `volume_export` (`VolumeExportSpec {volume (+ consistency) | snapshot + destination, transfer}`).
- `TransferRef {id (dtr_…), sizeBytes (1 B–1 TiB), chunkBytes (1–32 MiB), chunks (= ⌈size/chunk⌉), sha256}`; the digest is SHA-256 over the concatenated raw SHA-256 digests of the chunks. `TransferTarget {id, chunkBytes}`. A url source's `Sealed` is a `TaskEnv` with exactly `SOURCE_URL`.
- `TaskCompletion` gained optional `dataImport: DataImportResult {mode, empty, tables?, files?, sizeBytes?, serverVersion?, rootOwner?, format?, compressed?, sourceVersion?, readBytes?, warnings? (≤ 8 single lines ≤ 256 bytes), durationMs}` and `dataExport: DataExportResult {format, compressed?, sizeBytes, chunks, sha256, durationMs}`. New enums `DataImportMode`, `ImportSourceKind`, `DataFormat` (`postgres_custom`, `postgres_sql`, `mysql_sql`, `mongodb_archive`, `clickhouse_backup`, `redis_rdb`, `tar`, `zip`), scalar `TransferID`.
- New endpoints `GET /daemon/v1/transfers/{dtr}/chunks/{n}` (signed raw chunk) and `PUT /daemon/v1/transfers/{dtr}/chunks/{n}` (raw chunk, signed request), see README.
- Fixtures: `task-lease.data-transfer.json`, `task-completion.data-import.json`, `task-completion.data-export.json`.

### macOS servers (2026-10-20, D53)

Additive. Design: docs/11 §3.3, docs/05 §2.

- `HostRuntime` (`Report.host.runtime`) gained optional `hostOs` (`linux` | `darwin`), `engine` (`docker-engine` | `docker-desktop` | `orbstack` | `colima`) and `buildPlatforms` (the `Platform`s the server's BuildKit can build, emulated ones included; omitted while buildkitd is not running).
- `CapabilityName` gained `workloads`: apps on the server can be reached from other servers (unavailable on Docker Desktop, which publishes ports on the machine instead of on the mesh address in its VM).
- `HardwareFacts` gained optional `hostModel` (the model the installer read on the host, e.g. `Mac mini`; display only).
- `EnrollRequest.host.os` is `darwin` when the installer says the machine is a Mac (`LR_HOST_OS`); it was always launchroomd's own `linux` before.

### Scheduled cron services (2026-10-20, D51)

Additive; `workloads[].schedule` goes only to daemons that announce the new feature `cronSchedule`. Design: docs/04 §1, docs/03 §8.

- `WorkloadSpec` gained optional `schedule: ScheduleSpec {cron: CronExpr, timeZone: Timezone}`; requires `restart: "no"` and no `port`, `health`, `publishOnMesh`. launchroomd creates the container unstarted and starts it at every due minute in the zone.
- New scalar `CronExpr` (5 fields separated by single spaces; numbers, `*`, ranges, lists, steps; ranges checked per field; ≤ 100 bytes).
- New generated schema `capability-allow-list.json`: the policy floor's default capability allow-list.
- Fixture: `node-state.cron.json`.

### Generated Dockerfiles (2026-10-19, D48)

Additive; specs using any of this go only to daemons that announce the new feature `generatedDockerfile`. Design: docs/20 §6.

- `BuilderSpec` gained optional `dockerfile` (1–65,536 bytes, valid UTF-8, no NUL): a Dockerfile stored in launchroom. Only with `kind: dockerfile`, never with `dockerfilePath`. launchroomd writes it to a private directory next to the checkout (never into it), reads it with the same rules as a repository Dockerfile and builds the app directory with it; the summary reports `builder: dockerfile`, `builderReason: explicit`, no `dockerfilePath`.
- `PlanSpec` gained optional `inspect` (bool). `TaskCompletion` gained optional `inspection: RepoInspection {tree: [RelPath] (≤ 2000), treeTruncated, files: [InspectedFile {path, content (≤ 32,768 bytes, UTF-8 text), truncated}] (≤ 48, ≤ 262,144 bytes together)}`, reported for succeeded and failed plan tasks with `inspect` once the checkout exists. The listing skips dependency/build folders, `.env*`, keys, certificates, credential and compose files; contents are manifests, configs, the README start and entry excerpts.
- `RunOnceSpec` gained optional `probe: RunOnceProbe {port, path?, within (1s–10m)}`; with a probe, `command` and `args` may both be omitted (the image's own CMD). launchroomd checks from the host that the container accepts TCP on the port (and answers `GET <path>` below 500) within `within`, then stops it. `RunOnceResult` gained optional `probe: ProbeOutcome {listening, afterMs, httpStatus?, exited, listeningPorts? (≤ 16)}`; `exitCode` is 0 when launchroomd stopped the container after a passing check.
- New daemon feature `generatedDockerfile` in `Report.host.features`.

### Client certificates for the daemon transport (2026-10-20, D50)

Additive, on top of the request/response signatures; `routes[].clientAuth` goes only to daemons that announce the new feature `clientCertificates`. Design: docs/03 §4.3, protocol/SIGNING.md §6.

- New documents `ClientCertificateRequest {publicKey}` (`POST /daemon/v1/client-certificate` body; `DERBase64` of an ECDSA P-256 SubjectPublicKeyInfo) and `ClientCertificate {serverId, certificate, caCertificates (1–4), notBefore, notAfter, renewAfter}` (signed response; `renewAfter` between `notBefore` and `notAfter`). New scalar `DERBase64` (standard padded base64, ≤ 8192 characters).
- `RouteSpec` gained optional `clientAuth: RouteClientAuth {mode (verify|require), caCertificates (1–4 `DERBase64`, unique, each a CA certificate), edgeSecret (Sealed)}`.
- `IngressSecret` purpose `client_auth` with exactly the value `secret` (the edge secret sent as `X-Lr-Edge`).
- Fixtures: `client-certificate-request.json`, `client-certificate.json`, `node-state.client-auth.json`.

### More DNS-01 providers (2026-10-20, D47)

Additive; the new providers go only to daemons that announce the new feature `dnsProviders` (older ones keep per-host certificates). Design: docs/05 §5.

- `DNSProvider` gained `route53`, `digitalocean`, `porkbun`, `desec`, `gandi`, `ovh`, `netcup` (launchroom-caddy compiles in the matching `caddy-dns` modules).
- `IngressSecret` purpose `dns` holds the provider's own value names instead of always `token`: `token` (cloudflare, hetzner, digitalocean, desec, gandi), `accessKeyId` + `secretAccessKey` (route53), `apiKey` + `secretKey` (porkbun), `endpoint` (`ovh-eu`|`ovh-ca`|`ovh-us`) + `applicationKey` + `applicationSecret` + `consumerKey` (ovh), `customerNumber` (digits) + `apiKey` + `apiPassword` (netcup). launchroomd requires exactly the provider's names; anything else makes the DNS spec unusable (per-host certificates, a note), never a refused NodeState.

### Dashboard canonical origin (2026-10-19, D43)

Additive; `system.controlPlane` goes only to daemons that announce the new feature `controlPlaneUrl`. Design: docs/03 §4.2, docs/05 §4.1.

- `SystemSpec` gained optional `controlPlane: ControlPlaneSpec {url: ControlPlaneOrigin}`: the dashboard's canonical public origin (`https://<DNS name, ≥ 2 labels>[:port]`, no IP literal, no path). launchroomd verifies it (signed `GET /daemon/v1/config` there) before using it and keeps `LR_CONTROL_PLANE_URL` as the fallback.
- `HostStatus` gained optional `controlPlane: ControlPlaneStatus {configured?, adopted?, active? (ControlPlaneBaseURL / ControlPlaneOrigin), state (configured|adopted|fallback|pending|refused), message? (≤ 512)}`, sent in every report by daemons with the feature.
- New scalars `ControlPlaneOrigin`, `ControlPlaneBaseURL` (`http(s)://<DNS name|IPv4|[IPv6]>[:port][/path]`, ≤ 512), enum `ControlPlaneURLState`.

### More git providers and ssh clones (2026-10-19, D46)

Additive; build/plan specs using any of this go only to daemons that announce the new feature `gitProviders`. Design: docs/19 §6.

- `GitProvider` gained `gitlab` and `gitea` (Gitea/Forgejo). The Basic-auth user for `cloneToken` is `x-access-token` (github), `oauth2` (gitlab, gitea) or `launchroom` (git).
- `GitURL` also accepts `ssh://[user@]host[:port]/path` (user `^[a-z_][a-z0-9_.-]{0,31}$`, no password, path `[A-Za-z0-9._~/-]+`; a path starting with `/~` is home-relative).
- `RepoSpec` gained optional `ref` (`GitRef`: `refs/heads/…` or `refs/tags/…`; fetched with depth 50 when the server won't send the SHA directly) and `knownHosts: [HostKey {type (ssh-ed25519|ecdsa-sha2-nistp256|ecdsa-sha2-nistp384|ecdsa-sha2-nistp521|ssh-rsa), key (base64 wire-format public key)}]` (1–8, unique; required for ssh URLs, refused otherwise; RSA ≥ 2048 bits; must parse and match `type`).
- `BuildInputs` gained optional `sshKey` (`Sealed`, plaintext `TaskToken` with the new purpose `ssh_key`, token = base64 of the 32-byte ed25519 seed). `sshKey` only with ssh URLs, `cloneToken` only with http(s) URLs.
- Fixture: `task-lease.build-ssh.json`.

### Clean database stops (2026-10-19, D42)

Additive; rendered only for daemons that announce the new feature `stopSpec`. Design: docs/03 §8.

- `WorkloadSpec` gained optional `stop: StopSpec {signal (SIGTERM|SIGINT), timeout (1s–5m, single-unit Duration), noInit?}`. launchroomd sets them as the container's `StopSignal`/`StopTimeout` (part of the spec hash), runs the container without an init process when `noInit` is set, and waits at least `timeout` whenever it stops or removes the container. The dashboard sets it for managed databases (Postgres `SIGINT`, MySQL and Redis/Valkey `SIGTERM`; 60 s; `noInit`; Valkey also gets `command: ["docker-entrypoint.sh"]`, as its image's ENTRYPOINT starts tini).
- Fixture: `node-state.web-postgres.json` (the Postgres workload).

### Point-in-time recovery and restore verification (2026-10-19, Phase 4, D34)

Additive; every new NodeState field is rendered only for daemons that announce the feature. Design: docs/14 §10.

- New daemon features in `Report.host.features`: `pointInTime`, `verifyRestore`.
- `BackupKind` gained `base_backup`. `backup` of kind `base_backup`: target `{engine: postgres, workload}`; launchroomd streams `pg_basebackup --format=tar --wal-method=fetch` into `restic backup --stdin --stdin-filename postgres-base.tar`.
- `RestoreSpec` gained optional `pointInTime`, `walTags` and `runtime` (all only for kind `base_backup`, `runtime` required there): target `{engine: postgres, volume}`; `RestoreRuntime {image, mountPath, dataDirectory (inside mountPath), archiveDirectory? (inside mountPath), env (sealed TaskEnv bound to server + task with exactly POSTGRES_USER, POSTGRES_PASSWORD, POSTGRES_DB), resources}`. `RestoreResult` gained optional `restoredTo`.
- `Retention` gained `keepWithinHours` (0–87600, restic `--keep-within <n>h`).
- `WorkloadSpec` gained optional `walArchive: WalArchiveSpec {directory (inside one of the workload's writable mounts), destination (BackupDestination whose credentials are a sealed **WorkloadEnv** bound to the server and workload with exactly AWS_ACCESS_KEY_ID, AWS_SECRET_ACCESS_KEY, RESTIC_PASSWORD), tags (1–32), interval (10s–1h)}`. It never changes the container (not part of the spec hash).
- `Report` gained optional `walArchives: [WalArchiveStatus {workload, state (active|waiting|failing), checkedAt, lastArchivedAt?, lastSegment? (24 upper-case hex), pendingSegments, message?}]` (≤ 256, unique per workload).
- New task type `verify_restore` with `VerifyRestoreSpec {kind (database|base_backup|volume), snapshot, destination, engine?, image?, dataPath?, dataDirectory? (base_backup), walTags? (base_backup), databaseUser?/databaseName? (base_backup, `DatabaseIdent` ^[A-Za-z0-9_]{1,63}$), resources}`; `TaskCompletion` gained optional `verify: VerifyResult {passed, checks: [{name (restore|replay|start|query|amcheck|mysqlcheck|rdb|archive), passed, detail?}] (≤ 16), tables?, rows?, files?, bytes?, restoredTo?, durationMs}`. A check that fails is `passed: false` in a succeeded task; the task fails only when the verification could not run.
- `docker exec` from launchroomd may run as an image user (`postgres`) for the WAL archive housekeeping (internal, not a protocol field).

### Interactive sessions (2026-10-19, Phase 4, D35)

Additive. Design: docs/03 §11. Daemons that don't know the task type never lease it (they lease only types they handle).

- New task type `session` with `SessionSpec {session (ses_<ULID>), kind (console|data), workload, channel (= "private-session." + session), key (Sealed TaskToken, purpose `session`, token = 32 random bytes base64url without padding), idleTimeout (1m–1h), shell? (console: /bin/sh|/bin/bash|/bin/ash|/bin/zsh; empty = automatic bash → ash → sh), cols?/rows? (console, 2–500 × 2–200), record? (console), engine? (data only, required there)}`. New token purpose `session`.
- `TaskCompletion` gained optional `session: SessionResult {startedAt?, endReason (closed|exited|idle|deadline|disconnected|ended|never_connected|refused|error), exitCode?, bytesIn, bytesOut, commands? (≤ 1000: {op, status ok|error|refused, truncated?, rows, durationMs})}`, sent for succeeded and failed session tasks. A kind the local policy (`LR_INTERACTIVE_SESSIONS`) doesn't allow completes **failed** with `endReason: refused`.
- `BroadcastAuthRequest.channel` (`DaemonChannel`) also accepts `private-session.ses_<ULID>`: the dashboard authorizes it only for a session task the requesting server holds.
- New document `SessionCommand` (`session-command.json`): the payload of the Pusher server events `session.command` / `session.end` on the session channel, whose `data` is the JSON string of `{"payload": "<SessionCommand JSON>", "signature": "kid=…, sig=…"}` (Ed25519 over the payload bytes, like response signatures). Fields: `session`, `op` (op_<ULID>, single use), `issuedAt` (±60 s), `command` (end|schema|rows|keys|key|query) and per command `table {schema?, name}` + `page?`/`pageSize?` (rows), `pattern?`/`cursor?` (keys), `key` (key), `statement` (≤ 16 KiB) + `allowWrites?` (query), `reason?` (end). `session.end` must carry `command: end`.
- New daemon features in `Report.host.features`: `consoleSessions`, `dataSessions` (only for kinds the local policy allows).
- Browser ↔ daemon frames (not a dashboard document): docs/03 §11.2.
- Fixtures: `task-lease.session-console.json`, `task-lease.session-data.json`, `task-completion.session-console.json`, `task-completion.session-data.json`, `session-command.{query,rows,end}.json`, `broadcast-auth-request.session.json`.

### Request metrics (2026-10-19)

Additive, daemon → dashboard only (dashboards that don't know the field ignore it). No NodeState change. Design: docs/15 §1.

- `ReportMetrics` gained optional `requests: [RequestMetricSample] (≤ 8192)`: per-minute traffic of each route this edge serves that no accepted report carried yet (launchroomd buffers ≤ 4096 route-minutes, dropping the oldest; a completed minute kicks a report). `RequestMetricSample {route (RouteID), at (whole minute, UTC), requests (≥ 1), status2xx?, status3xx?, status4xx?, status5xx?, bytesIn?, bytesOut? (omitted when 0), latencyMs? (exactly 15 counts)}`. `(route, at)` is unique per list; the status counts and the histogram each sum to ≤ `requests` (1xx responses, e.g. WebSocket upgrades, count only in `requests`). A minute may arrive twice: store idempotently per (server, route, minute).
- `latencyMs` counts non-1xx requests per duration bucket with the inclusive upper bounds (ms) `1, 2, 5, 10, 25, 50, 100, 250, 500, 1000, 2500, 5000, 10000, 30000` plus an overflow bucket (Go: `state.LatencyBoundsMs`, `state.LatencyBucket`). Histograms add up exactly across servers and minutes.
- Source: the edge Caddy's access log. The rendered Caddy config now has `logging.logs`: `default` (stderr, JSON, excluding `http.log.access.lr-access`, so access lines never reach the container log; certificate events are still read from it) and `lr-access` (Caddy's `net` writer to `tcp/127.0.0.1:2022` with `soft_start` and a 1 s dial timeout; `filter` encoder deleting `request>headers`, `request>remote_ip`, `request>remote_port`, `request>client_ip`, `request>uri`, `request>tls`, `resp_headers`, `user_id`), and `servers.lr-https.logs.default_logger_name: "lr-access"`. Plain-HTTP redirects are not logged. Lines are attributed by host (lowercased, port stripped) through the route table; hosts without a route are dropped.
- New launchroomd config `LR_INGRESS_ACCESS_LOG_ADDR` (loopback `ip:port`, default `127.0.0.1:2022`): the access log listener (loopback peers only, ≤ 8 connections, lines ≤ 64 KiB). If it can't bind, launchroomd logs a warning and runs without request metrics (Caddy drops the lines).
- Fixture: `report.requests.json`.

### Private-network link quality (2026-10-19, Phase 4)

Additive. Because decoding is strict, only send `system.linkProbe` to daemons that understand it: the new `host.features` below says which.

- `SystemSpec` gained optional `linkProbe` (`LinkProbeSpec {enabled?: bool, peers?: [LinkProbePeer] (≤ 256, unique serverId and ipv4, never this server, only when enabled)}`, `LinkProbePeer {serverId, ipv4 (mesh address), signingKey (the peer launchroomd's Ed25519 key)}`): launchroomd answers link probes from exactly these peers on `<mesh IPv4>:7400` (nftables `mesh-host`: tcp 7400 only from the listed peers; local policy: peers outside `100.64.0.0/10` are ignored; `LR_LINK_PROBE=off` switches it off locally) and measures each of them.
- `HostStatus` (`Report.host`) gained optional `links: [LinkMeasurement] (≤ 256, unique peer)`: `{peer, measuredAt, rttMs? (0–60000, median of 5 round trips over one TCP connection, every minute), error? (≤ 256, when the latest probe failed), throughputMbps? (0–400000) + throughputAt (together): the latest download from the peer, 16 MiB at most, about every 6 h}`. Reports repeat the latest measurement per peer; store one sample per `(server, peer, measuredAt)`.
- `HostStatus` gained optional `features: [DaemonFeature] (≤ 64, unique, grammar `^[a-z][A-Za-z0-9]{0,31}$`)`: the optional protocol features this launchroomd understands, sent in every report (this version: `linkProbe`, `shipLogs`). Decoding is strict, so a daemon refuses a whole NodeState with a field it doesn't know: the dashboard sends `system.linkProbe` and `WorkloadSpec.shipLogs` only to servers whose latest report listed the feature (unknown names are ignored, so newer daemons can announce more).
- The probe itself is daemon ↔ daemon over the mesh, not part of the dashboard contract: a signed JSON hello (Ed25519 over a domain-separated message with both server ids, time ±60 s, a 128-bit nonce, the operation and size) and a signed ack from the peer, then echo rounds or the transfer (`daemon/internal/linkprobe`).
- Fixtures: `node-state.link-probe.json`, `report.links.json`.

### Runtime log shipping (2026-10-19, Phase 4)

Additive. Because decoding is strict, only send `shipLogs` to daemons that understand it.

- `WorkloadSpec` gained optional `shipLogs` (boolean, omitted when false): launchroomd follows the workload's container logs and ships them continuously with `LogChunk.source {kind: "workload", id: <workload id>}` (runtime log retention, opt-in per environment). It never changes the container (not part of the spec hash). Rendered only for servers announcing the `shipLogs` feature.
- Semantics now implemented (the kind existed): workload chunks use time-based `seq` (Unix µs, strictly increasing across restarts; a per-workload cursor in `data/logship` avoids repeats), at most 500 lines per chunk, flushed every 2 s. launchroomd rate-limits to 200 lines/s per workload (burst 2,000) and buffers at most 5,000 unshipped lines; dropped lines are announced by a `system` line ("launchroom: N log lines were dropped …"). The dashboard keeps dropping workload chunks of placements that aren't on the sending server.

### Battery fact (2026-10-19)

Additive, daemon → dashboard only. `HardwareFacts` gained optional `battery` (boolean, omitted when false): a `/sys/class/power_supply/*` entry of `type` Battery whose `scope` is not Device (a wireless mouse's battery doesn't count). The dashboard's device detection treats a system battery on bare metal as a laptop.

### Hardware facts and usage metrics (2026-10-19)

Additive, daemon → dashboard only (dashboards that don't know the fields ignore them: Data objects whitelist fields). No NodeState change.

- `HostFacts` (`EnrollRequest.host`) now fills `memoryMb` and gained optional `hardware`; `HostStatus` (`Report.host`) gained optional `hardware`. `HardwareFacts {cpuModel?, cpuVendor?, dmi?: DMIFacts {sysVendor?, productName?, productFamily?, boardVendor?, boardName?, biosVendor?, chassisType? (0–255)}, deviceTreeModel?, virtualization? (kvm|qemu|vmware|virtualbox|xen|hyperv|parallels|apple|bhyve|container|other), osName?, kernel?, timezone? (IANA, never UTC/Etc/*)}`. Strings are `FactText` (printable, ≤ 128 bytes, display only). Read from local files only (`/proc/cpuinfo`, the world-readable `/sys/class/dmi/id` fields, the device tree, `/sys/hypervisor`, Docker's `OperatingSystem`/`KernelVersion`, uname, `LR_HOST_TIMEZONE`); never serials or UUIDs, no cloud metadata services. Reports carry `host.hardware` only when it changed since the last accepted report, was never accepted, or the last acceptance is ≥ 1 h old.
- `virtualization: container` means launchroomd's own PID 1 is neither a host init nor launchroomd while it runs in a container (`/.dockerenv`), i.e. the "server" is itself a container (the dind dev servers). Docker Desktop / OrbStack report `apple` (WSL2 kernels `hyperv`).
- `Report` gained optional `metrics: ReportMetrics {host: [HostMetricSample] (≤ 120), workloads: [WorkloadMetricSample] (≤ 8192)}`: per-minute aggregates of 15 s samples that no accepted report carried yet (launchroomd buffers ≤ 60 host minutes and ≤ 4096 workload rows, dropping the oldest). `HostMetricSample {at (whole minute, UTC), samples (1–60), cpuPct? (0–100), memUsedMb?, memTotalMb?, diskUsedMb?, diskTotalMb?, netRxBps?, netTxBps?, load1?}`; `WorkloadMetricSample {workload, at, cpuCores?, memMb?}`. `at` is unique per list (per workload for workloads). A minute may arrive twice (a report that was accepted but whose response was lost): store idempotently per (server, minute) / (workload, minute).
- Sources: CPU % from `/proc/stat` deltas (busy = total − idle − iowait), memory = MemTotal − MemAvailable, disk of Docker's root dir (else the data dir), rx/tx bytes/s from `/proc/net/dev` without `lo`, Docker bridges, veths, the mesh interface and tunnels; workloads from Docker's one-shot stats of running managed workload containers (CPU-time deltas in cores; memory = usage − inactive file cache, like `docker stats`).
- New launchroomd config `LR_HOST_TIMEZONE` (written by the installers from the host's `/etc/timezone` or `/etc/localtime` link; launchroomd's container can't see them).
- Fixtures: `report.metrics.json`, `enroll-request.hardware.json`.

### Cross-server networking: mesh-published ports, per-peer allow-lists, ambassadors, certificate storage on the mesh (2026-10-18)

Additive. Because decoding is strict, only send `system.certStorage` to daemons that understand it.

- `SystemSpec` gained optional `certStorage` (`CertStorageMeshSpec {serveOnMesh?: bool, allowFrom?: [IPAddr] (≤ 256, unique, only with serveOnMesh)}`; control-plane host): launchroomd serves `<mesh IPv4>:6379` → its local Valkey (`LR_CERT_STORAGE_UPSTREAM`, default `127.0.0.1:6380`) and allows tcp 6379 from the mesh only from `allowFrom` (non-mesh addresses are ignored by the local policy).
- Semantics now implemented (fields existed): `WorkloadSpec.publishOnMesh` publishes the workload port on the server's mesh IPv4 only (never 0.0.0.0; the policy floor refuses any other host IP or a port outside 31000–32999); the workload waits (status `pending`, existing container untouched) until the mesh address is known. `firewall.meshAllow` becomes per-peer accept rules (`ct original` destination = mesh IP:port) in nftables `mesh-forward`; non-mesh peers are ignored. `ambassadors` run in `lr-system-ambassador`; the local policy keeps only listeners on declared `lr-e_…` networks and upstreams inside `100.64.0.0/10` on ports 31000–32999.
- Fixture: `node-state.cross-server.json`.

### Mesh: Tailscale client, sealed mesh keys, registry over the mesh, mesh status (2026-10-18)

Additive (one relaxation). Because decoding is strict, only send the new fields to daemons that understand them.

- `TailscaleSpec.loginServer` is now `MeshLoginURL` (`^https?://host[:port]$`, no path) instead of `HTTPSURL`: http passes the grammar but launchroomd's local policy refuses it unless the host is in `LR_MESH_INSECURE_LOGIN_HOSTS` (dev only).
- `TailscaleSpec` gained optional `authKey: Sealed` (plaintext: new schema `mesh-auth-key.json`, `MeshAuthKey {serverId, authKey}`, bound to the server); only allowed when `enabled`. `tags` must be unique; they are advertised only when present (own tailnet; Headscale 0.29 refuses `--advertise-tags` with tagged pre-auth keys, so the built-in mesh omits them).
- `RegistrySpec` gained optional `serveOnMesh: bool` (control-plane host; not allowed with `forwardTo`): launchroomd serves `<mesh IPv4>:5000` → the local registry.
- `Report.host` gained optional `mesh: MeshStatus {state: starting|needs_login|connected|error, ipv4?, ipv6?, message?, peers?: [MeshPeer {ipv4, online, connection: direct|relay|idle, relay?, lastHandshake?}] (≤ 256, unique ipv4), checkedAt}`.
- launchroomd's system-image allow-list gained `tailscale` (`tailscale/tailscale` v1.104.1, `sha256:87c55d6b…`).
- Fixtures: `node-state.mesh.json`, `node-state.mesh-control-plane.json`, `report.mesh.json`.

### Multi-edge ingress: shared certificate storage, DNS-01, certificate reports (2026-10-18)

Additive. Because decoding is strict, only send the new NodeState fields to daemons that understand them.

- `NodeState` gained optional `ingress` (`IngressSpec {storage?, dns?}`; edge servers only): `CertStorageSpec {address (mesh range or the daemon's LR_CERT_STORAGE_ALLOW), keyPrefix (StorageKeyPrefix ^[a-z0-9][a-z0-9_-]{0,62}$), credentials}` and `DNSChallengeSpec {provider (DNSProvider: cloudflare|hetzner), domains (1–16 Hostname), credentials}`. Credentials are sealed `IngressSecret` documents (new schema `ingress-secret.json`: `{serverId, purpose: dns|cert_storage, values}`; values `token` for dns; `password`, `encryptionKey` (≥ 32 bytes), optional `username` for cert_storage), bound to server and purpose.
- `TLSMode` gained `wildcard`: the host is served by the DNS-01 certificate `*.<host minus its first label>` when `ingress.dns` covers that parent, else launchroomd falls back to `acme`.
- `RouteSpec` gained optional `loadBalancing` (`round_robin` default | `prefer_local`).
- `IngressStatus` (Report.ingress) gained optional `certificates[]` (`CertificateStatus {host, state: issued|pending|failed, wildcard?, issuer?, notBefore?, notAfter?, lastError?, lastErrorAt?}`, ≤ 4096, unique hosts) and `storage` (`CertStorageStatus {state: local|shared|degraded, mirrored, message?}`). New fixtures: `node-state.multi-edge.json`, `report.certificates.json`.
- Data contract for mesh-published ports (Phase 3B, dashboard side): `placements.mesh_port`; edges route to `<server mesh ip>:<mesh_port>` address upstreams once it is set.

### Integration hardening: rejected states, volume deletion, released tasks (2026-10-18)

Additive; all new fields are optional. Because decoding is strict, a launchroomd without this change refuses a NodeState that carries `deleteVolumes` (the dashboard only sends it when non-empty, and shows the refusal), and the dashboard must accept the new report fields and task outcome (it does).

- `NodeState` gained optional `deleteVolumes: [VolumeDeletion {id, name, service, environment, requestedAt}]`: an explicit request to delete volumes of deleted environments, projects and previews (docs/05 §1.1). `Validate()`: ids and names unique, none may be declared in `volumes`. launchroomd deletes a volume only if it is managed by this server, its `launchroom.volume`/`service`/`environment` labels match exactly, it is not declared and no container uses it (never forced). Volumes are still never deleted because they left `volumes`.
- `Report` gained optional `volumeDeletions: [VolumeDeletionResult {id, name, outcome, message?, at}]` (`outcome` ∈ `deleted` | `absent` | `refused` | `failed`; refused/failed are retried while requested) and optional `stateRejected: Rejection {version, error, rejectedAt}`: the newest NodeState was refused (decode/validation/server id; only for correctly signed documents) and launchroomd keeps converging to `appliedVersion`; cleared once a newer state is accepted. Reports are now also sent while no state was ever accepted if a rejection is pending.
- `TaskOutcome` gained `released`: launchroomd stopped (SIGTERM) before the task finished within its 20 s shutdown grace. The dashboard re-queues the task immediately without using up an attempt; a passed deadline fails it. Before, such tasks completed as `cancelled`.
- Behaviour (no schema change): a `run_once` task holds its environment network so a concurrent reconcile pass cannot remove it before the container exists; network creation that loses a race counts as success when the network exists with the same id label; container create/start retry "network not found" (3×, bounded backoff), re-creating desired networks in between.
- The dashboard validates every rendered NodeState against `node-state.json` and a port of `Validate()` before storing it, and never publishes an invalid one (docs/03 §5).
- Fixtures: `node-state.delete-volumes.json`, `report.state-rejected.json`, `task-completion.released.json`.

### Signed self-update and control-plane update (2026-10-18)

Additive. Because decoding is strict, only send `scope` to daemons that understand it (older daemons fail such a task with a decode error instead of running it).

- `SelfUpdateSpec` gained optional `scope` (`UpdateScope` ∈ `daemon` (default) | `control_plane`). `daemon` updates launchroomd on a server that is not the control-plane host; `control_plane` (control-plane host only) updates the whole control-plane stack from the release bundle (docs/11 §4). `version` is the target release; `image` must equal the `LR_DAEMON_IMAGE` pinned in that release's signed bundle.
- `TaskCompletion` gained optional `selfUpdate` (`SelfUpdateResult {scope, fromVersion, toVersion, rolledBack}`), sent for succeeded and failed self_update tasks. New fixtures: `task-lease.self-update.json`, `task-completion.self-update.json`.
- Daemon behaviour now relevant to the dashboard: launchroomd downloads `launchroom-deploy.tar.gz` + `.sigstore.json` of `version` from the release base pinned in `data/trust.json` and verifies it against the release identity pinned there; the dashboard cannot change either. It refuses versions that are not newer than its own (`daemon`) / than the installed bundle (`control_plane`), `daemon` scope on the control-plane host and `control_plane` scope elsewhere. While it runs it sends `progress` lines in heartbeats. The swap restarts launchroomd: the attempt is completed by the new (or, after a rollback, the old) process, possibly only after the lease expired and the task was leased again; a re-leased attempt of an update that already finished completes immediately with the recorded outcome.

### Rename agent → launchroomd (D27, 2026-10-18)

**BREAKING** (lockstep: dashboard and launchroomd must be upgraded together; v1 is unreleased). Earlier entries below were rewritten to the new names.

- API prefix `/agent/v1/*` → `/daemon/v1/*` (part of the signed `@path`).
- Request signature tag `launchroom-agent-v1` → `launchroomd-v1` (protocol/SIGNING.md; vectors in `fixtures/signing/` regenerated).
- Broadcast channel `private-agent.{serverId}` → `private-daemon.{serverId}`.
- Kind `AgentConfig` → `DaemonConfig` (`schema/v1/daemon-config.json`, `GET /daemon/v1/config`).
- Field `agentVersion` → `daemonVersion` in `EnrollRequest` and `HostStatus` (`host.daemonVersion` in reports).
- Client identifiers: HTTP `User-Agent` `launchroomd/<version>`, Pusher `client=launchroomd`.

### Daemon security fixes: firewall status, volume ownership (2026-10-18)

Additive report field; NodeState decoding is unchanged, but launchroomd's local policy now refuses more (docs/03 §8).

- `HostHealth` gained optional `firewall` (`FirewallStatus {mode, checkedAt, networks, repairs, error?}`; `mode` ∈ `enforced` | `disabled` | `error`). It reports launchroomd's nftables isolation of workloads and builds (docs/05 §1): `enforced` = the `inet launchroom` table matches what launchroomd expects, `disabled` = turned off locally (`LR_FIREWALL=off`), `error` = it could not be applied (launchroomd then creates and starts no workloads and runs no builds). `repairs` counts drift repairs since launchroomd started. Dashboards that don't know the field ignore it.
- Daemon behaviour now relevant to the dashboard (local policy floor, 2026-10 security review M5):
  - `networks[].name` must start with `lr-e_` and `volumes[].name` with `lr-v_` (never `lr-system-…`). Workloads that reference another network or volume are not created (status `failed` with a `policy:` message); run_once tasks with such a network fail.
  - A volume is created only when a workload in the NodeState mounts it, labelled with that workload's `launchroom.service` and `launchroom.environment`. Workloads of different services or environments mounting one volume are refused. Before mounting an existing volume launchroomd requires its `launchroom.volume` label to equal the declared volume id and, when present, its service/environment labels to match the workload (volumes created by older daemons carry only the id label).

### Next.js deployment adapter (2026-10-18)

Additive: one optional summary field; dashboards that don't know it ignore it.

- `BuildSummary` gained optional `next` (`NextBuildInfo {adapterVersion, nextVersion, deploymentId?, serverEntry?, middleware, middlewareRuntime?, imageOptimization, sharp, cacheHandler, routes, isrRoutes?}`; `NextRouteCounts {appPages, appRoutes, pages, pagesApi, staticFiles, prerendered, isr, edge}`, each 0–1 000 000; `isrRoutes` ≤ 32 `HTTPPath`s; `deploymentId` ≤ 128 bytes). It is what `@vantezzen/launchroom-next-adapter` reported for a build task (docs/04 §3); plan tasks never carry it. New fixture: `task-completion.build-next.json`.
- Daemon behaviour now relevant to the dashboard: the `nextjs` preset is version 2. For Next ≥ 16.2 (without a user `adapterPath` and without `output: 'export'`) it applies decision `deployment-adapter`: the vendored adapter is written to `/opt/launchroom/next-adapter` in the build step, `NEXT_ADAPTER_PATH` points at it, `deploymentId` = the build's deployment id, the start command is `node .next/standalone/server.js` and the image contains only `.next/standalone` (plus the Node toolchain). Removing the decision (`disabledDecisions: ["deployment-adapter"]`) falls back to Railpack's `next start`. New warning code `own-next-adapter`.

### Phase 2 preview protection (2026-10-18)

Additive: a new optional route field. Because decoding is strict, only send it to daemons that understand it.

- `RouteSpec` gained optional `protection` (`RouteProtection {forwardAuth}`; `ForwardAuth {url, stripHeaders?}`). `url` is a `ForwardAuthURL`: `^https?://<host>(:<port>)?/auth/preview$`, host a lowercase DNS name (single labels such as `dashboard` allowed) or an IPv4 literal, port 1–65535 without leading zeros, no credentials, query or fragment, at most 512 bytes. `stripHeaders`: at most 8 unique `HeaderName`s. New fixture: `node-state.preview.json`.
- Daemon behaviour now relevant to the dashboard:
  - A protected route asks `url` about **every** HTTPS request before anything else (after the www redirect), like Caddy's `forward_auth`: a `GET` to `/auth/preview` (no request body; the client's query string is kept) with `Host: <url host:port>`, `X-Forwarded-Method`, `X-Forwarded-Uri` (original path + query), `X-Forwarded-Host`, `X-Forwarded-Proto` and `X-Forwarded-For` (set by Caddy; client-supplied values are overwritten) and all other client request headers (cookies, `X-Launchroom-Bypass`). A **2xx** answer lets the request continue to the app (after deleting `stripHeaders` from it); **any other** answer (e.g. `302` with `Location` and `Set-Cookie`, `401`, `403`) is returned to the client unchanged. If the auth endpoint is unreachable, the client gets a `502`.
  - **Local policy floor**: launchroomd only calls forward-auth URLs whose origin (`scheme://host:port`) is its own control-plane URL (`LR_CONTROL_PLANE_URL`) or `http://<ip:port>` of an `LR_ALLOWED_LOCAL_UPSTREAMS` entry (the control-plane host: `http://127.0.0.1:8000/auth/preview`). A protected route with any other URL **fails closed**: it answers `403` ("launchroom: this preview's protection is misconfigured, so it is not served.") and is never proxied; launchroomd logs it. Dev: the dashboard sends `http://dashboard:8000/auth/preview`.
- Dashboard behaviour now relevant to daemons (no schema change): routes of preview environments carry `protection` (`stripHeaders: ["X-Launchroom-Bypass"]`) unless the project turned protection off; a preview whose forward-auth URL can't be built is not routed at all. Every edge NodeState also has **immutable per-deployment routes** (`id` = `rt_` + the deployment's ULID, one host `{service}-{8 chars}.…`, `tls: on_demand` or `internal` for `*.localhost`, `X-Robots-Tag: noindex`) for web deployments whose workloads run there. Workloads of previews that share a base database list a second network (the base environment's, no aliases); every network is declared once.

### Phase 1 realtime nudges, host health, cleanup and upstream policy (2026-10-18)

All additions are optional fields or new endpoints; older daemons and dashboards keep working (daemons fall back to polling, dashboards ignore unknown report fields).

- New documents: `daemon-config.json` (signed `DaemonConfig {nudge?}`, `GET /daemon/v1/config`), `broadcast-auth-request.json` (`BroadcastAuthRequest {socketId, channel}`, `POST /daemon/v1/broadcasting/auth`) and `broadcast-auth.json` (signed `BroadcastAuth {auth}`). `NudgeConfig {key, url?}`: `key` is the public Pusher/Reverb app key, `url` an optional `ws(s)://host[:port][/path]` base URL (omitted: derived from launchroomd's control-plane URL); grammars: `SocketID` `^[0-9]{1,20}\.[0-9]{1,20}$`, `DaemonChannel` `^private-daemon\.srv_<ULID>$`, `PusherAuth` `<key>:<64 hex>`.
- `EnrollResponse` gained optional `nudge` (`NudgeConfig`).
- Nudge events on `private-daemon.<server id>` (Pusher protocol 7, content-free): `state.changed`, `task.available`, `logs.requested`. The dashboard authorizes a socket only for the requesting server's own channel (403 otherwise and for unapproved servers; 404 when nudges are off).
- `Report.host` gained optional `health` (`HostHealth {collectedAt, dataDisk?, dockerDisk?, memory?, load?, inotify?, networks?, docker?, buildCacheMb?, clockSkewMs?, cleanup?}`; `FilesystemUsage {totalMb, freeMb}`, `MemoryUsage {totalMb, availableMb}`, `LoadAverage {load1, load5, load15}`, `InotifyLimits {maxUserWatches, maxUserInstances, maxQueuedEvents}`, `NetworkUsage {total, managed}`, `DockerUsage {imagesMb, imagesReclaimableMb, containersMb}`, `CleanupResult {at, trigger: schedule|disk_pressure, removedImages, removedContainers, reclaimedMb, error?}`). New fixtures: `report.health.json`, `enroll-response.nudge.json`, `daemon-config.json`, `broadcast-auth-request.json`, `broadcast-auth.json`.
- Daemon behaviour now relevant to the dashboard:
  - **Route address upstreams are restricted by local policy**: only `100.64.0.0/10` (mesh) or exact `ip:port` entries of launchroomd's `LR_ALLOWED_LOCAL_UPSTREAMS`. Others are dropped from the rendered routes (a route left without upstreams answers 503). The dashboard's system route `{"address": "127.0.0.1:8000"}` needs the control-plane host's daemon to allow it (the install compose file does).
  - Reports carry the latest host health snapshot (collected every 5 min); launchroomd fetches `GET /daemon/v1/config` before every nudge connection attempt, and `POST /daemon/v1/broadcasting/auth` once per connection.
  - Disk cleanup removes unused images under `127.0.0.1:5000/` beyond the newest 2 per repository (never volumes, never images with other references), prunes the BuildKit cache by age and leftover `lr-run-*` containers.

### Phase 1 security fixes (2026-10-18)

- `RunOnceSpec` gained optional `maskEnv` (≤ 1024 env names, unique): the variables of the sealed `env` whose values launchroomd masks (`***`) in shipped `run_once` logs, with the same forms as build logs (raw, base64 std/url padded/raw, URL query/path escaped, PEM lines ≥ 16 chars) plus the password of URL-shaped values (DSNs). Names missing from `env` are ignored. The dashboard lists the secrets of the service and every config variable that references one (docs/09 R16, review M2).
- Dashboard behaviour: `POST /daemon/v1/logs` and `POST /daemon/v1/reports` answer 403 for servers that are not approved (as `state` and `tasks/lease` do). Log chunks for `deployment` sources are stored only when this server holds a task or placement of that deployment, and `workload` sources only for a placement on this server; other chunks of a batch are dropped (logged), never stored. Stored lines are additionally masked against the deployment's secret values (review L1, M2).

### Phase 1 build/release integration (2026-10-18)

- **BREAKING** `RunOnceSpec.network` is a `NetworkSpec` (`{id, name, subnet}`) instead of a network id: launchroomd creates the environment network (reconciler name, subnet and labels) when the server has none yet. `RunOnceSpec.env` plaintext is a `TaskEnv` bound to the task. `args` is now optional when `command` is set (at least one is required): images with a shell-wrapper ENTRYPOINT (Railpack's `["/bin/bash", "-c"]`) get the whole argv as `command`.
- `BuildInputs.deployment` is optional for `plan` tasks (plans run outside deployments); `build` tasks still require it.
- Daemon behaviour: `run_once` is implemented (one-off container `lr-run-<task>`, label `launchroom.task`, no workload label, removed afterwards; result `runOnce.exitCode` for any exit code; logs with step `run`). Pulls made for a task send `task` in `RegistryTokenRequest`; the dashboard grants pull on `apps/<service>` to a leased `run_once` task of that service. Before stopping/removing workloads a reconcile pass syncs the ingress without them.
- Dashboard behaviour: task secrets (`env`, `cloneToken`) are sealed at lease time for the leasing server; tasks whose secrets can't be sealed fail at lease time with `result.failure` instead of being leased. Builds/plans are only leased by servers of the spec's platform. New fixtures: `task-lease.plan.json`, `task-lease.run-once.json`, `task-completion.run-once.json`.

### Phase 1 backups (2026-10-18)

- `DatabaseEngine` gained `valkey`.
- New task type `backup_snapshots` with `BackupSnapshotsSpec {destination, tags}`: lists the snapshots carrying **all** `tags` (`restic snapshots --json --tag a,b`), newest first. Result: `TaskCompletion.snapshots` (`BackupSnapshotsResult {snapshots[] {id, time, tags, sizeBytes?}, truncated?}`, at most 1000; tags not matching the `BackupTag` grammar are dropped). A repository that does not exist yet lists `[]`.
- **BREAKING** `BackupDestination.endpoint` is an `S3Endpoint` (`http(s)://host[:port]`, no path, credentials, query or fragment) instead of an `HTTPSURL`. Daemons accept plain `http` only for hosts in their local `LR_BACKUP_INSECURE_HOSTS` (dev S3 servers). `region` is optional (default `us-east-1`; Cloudflare R2: `auto`).
- **BREAKING** `BackupDestination.credentials` plaintext is specified: a `TaskEnv` (`task-env.json`) bound to the server and task, whose `env` holds **exactly** `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY` and `RESTIC_PASSWORD` (`s3`) or only `RESTIC_PASSWORD` (`local`). Any other or missing name fails the task. Re-seal per task.
- `TaskCompletion` gained `backup` (`BackupResult {snapshotId (full 64-hex id), sizeBytes (dump bytes streamed), addedBytes? (after dedup), durationMs}`), `restore` (`RestoreResult {sizeBytes, durationMs}`) and `maintenance` (`MaintenanceResult {removedSnapshots?, durationMs}`).
- Daemon behaviour now relevant to the dashboard:
  - `backup`, `restore`, `backup_snapshots` and `backup_maintenance` are implemented (restic 0.19.1, bundled in the launchroomd image at `/usr/local/bin/restic`; `LR_RESTIC_BINARY` overrides; dev: next to the launchroomd binary). Without restic, tasks fail with "restic is not installed on this server".
  - Repository: `s3:<endpoint>/<bucket>[/<prefix>]`; `local`: `<daemon data dir>/backups[/<prefix>]` (does not survive the server). Missing repositories (and buckets) are initialised by `backup`; the other operations never initialise. restic runs with an empty environment except the credentials, `AWS_DEFAULT_REGION` and a cache under the launchroomd data dir.
  - Only `kind: database` is supported (`volume`: Phase 3; `control_plane` runs in the dashboard container). Engines and argv are fixed per engine (never from the spec); credentials only via exec env read from the workload's sealed env in the verified NodeState: postgres `pg_dump --format=custom` / `pg_restore --clean --if-exists --single-transaction --exit-on-error` (user/db/password from `POSTGRES_USER`/`POSTGRES_DB`/`POSTGRES_PASSWORD`), mysql/mariadb `mysqldump`/`mariadb-dump --single-transaction --routines --triggers --no-tablespaces` → `mysql`/`mariadb` (`MYSQL_USER`/`MYSQL_DATABASE`/`MYSQL_PASSWORD`), redis/valkey `redis-cli`/`valkey-cli --rdb -` (`REDIS_PASSWORD`). **Restoring redis/valkey snapshots is not supported yet**; `mongodb` is not supported. The target workload must have a running container on the server that leases the task (pin it).
  - Snapshot file per engine (the `--stdin-filename`; a restore requires a snapshot of exactly `/<file>`): `postgres.dump`, `mysql.sql`, `mariadb.sql`, `redis.rdb`, `valkey.rdb`. Every snapshot gets host `launchroom` plus the spec's `tags`.
  - Both exit codes are checked: a failed dump fails the task even when restic stored the (truncated) stream, and that snapshot is forgotten.
  - `forget` requires at least one tag and applies the retention to all snapshots carrying all tags as **one group** (`--group-by ''`), then prunes if anything was removed. Scope it per service (`service:<id>`) or per control plane (`kind:control_plane`).
  - One backup/restore/maintenance task at a time per server (others wait, logging "Waiting for another backup or restore on this server to finish"); `backup_snapshots` is not queued. Task logs (source `task`, steps `backup`/`restore`/`maintenance`) carry short progress lines, never secrets.
  - Errors are secret-free (credentials masked). restic exit codes map to: 10 "the repository does not exist", 11 "the repository is locked by another operation; try again later", 12 "wrong repository password"; other failures "restic <op> failed (exit N): <last stderr lines>". Dump/restore tool failures: "backup: <tool> exited with code N: <stderr excerpt>".

### Phase 1 builds and registry (2026-10-18)

- **BREAKING** `BuildSpec.pushToken` is removed. Push credentials are requested by launchroomd at push time from the new `POST /daemon/v1/registry-token` with `task` = the build task (tokens live ≤ 5 min; builds can take 30). `BuildSpec.image` must be exactly `<registry>/apps/<service id lowercased>` (Go validation); launchroomd tags `<deployment id lowercased>` and reports the digest.
- **BREAKING** `BuildInputs.cloneToken` is optional (public repositories, dev fixtures) and its sealed plaintext is now specified: new schema `task-token.json` (`TaskToken {serverId, taskId, purpose: "clone", token}`), bound to server, task and purpose like `WorkloadEnv`. `BuildInputs.env` plaintext: new schema `task-env.json` (`TaskEnv {serverId, taskId, env}`). Seal both per task and server.
- `BuildInputs.repo.cloneUrl` is a `GitURL` (`https://…`, or `http://…` which daemons accept only for hosts in their local `LR_BUILD_INSECURE_GIT_HOSTS`). `repo.provider` gained `git` (generic smart-HTTP remote; dev `git-fixtures`). The SHA must be a 40-hex SHA-1.
- `BuilderSpec`: `kind` gained `auto` (daemon resolves Dockerfile → preset + Railpack → Railpack after cloning; `railpackVersion` required for `auto` and `railpack`, and must equal launchroomd's linked Railpack, currently `0.42.0`); `dockerfilePath` is valid for `auto` too; new optional `disabledDecisions` (preset decision/warning ids), `installCommand`, `buildCommand`, `startCommand` (≤ 4096 bytes) and `railpackEnv` (`RAILPACK_*` → value, non-secret). `preset` may be `none`.
- `BuildInputs.buildArgs` (optional): names of non-secret build variables launchroomd may also pass as Dockerfile build args when the Dockerfile declares a matching `ARG`.
- `BuildResult` gained optional `summary` (`BuildSummary`), `secretsHash` (SHA-256 hex over sorted `NAME=value\n` of the build variables) and `attestations` (`sbom`, `provenance`). New `PlanResult {durationMs, summary}` in `TaskCompletion.plan` for plan tasks, and `TaskCompletion.failure` (`TaskFailure {class, step, retryable, hints, summary}`) for failed build/plan tasks. `FailureClass`: `plan_error`, `build_error`, `transient`, `clone_error`, `config_error`, `timeout`, `internal_error`.
- `BuildSummary` ("we detected…"): `builder`, `builderReason` (`explicit`, `dockerfile_detected`, `preset`, `railpack`), `dockerfilePath`, `railpackVersion`, `provider`, `framework`, `packageManager`, `packages[] {name, version, source}`, `installCommand`/`buildCommand`/`startCommand` (display), `port`, `presets[] {name, version, decisions[] {id, title, detail}}`, `runtimeEnv` (non-secret defaults), `processes[] {name, args}`, `releaseCommand` (argv), `healthPath`, `buildEnv` (names), `envReferences` (names from a static source scan), `warnings[] {code, message}`, `plan` (the resolved Railpack plan object; names of secrets only).
- `LogLine` gained optional `step` (≤ 128 bytes) for step-grouped build logs. Build/plan log chunks use `seq` starting at `(attempt − 1) × 10⁹ + 1`, so retried attempts never collide with earlier chunks.
- New documents: `registry-token-request.json` (`RegistryTokenRequest {scopes[] {repository, actions[pull|push]}, task?}`, POST `/daemon/v1/registry-token`) and `registry-token.json` (signed `RegistryToken {token, expiresAt, scopes}`; launchroomd rejects a token that doesn't cover the requested scopes). Repository grammar: lowercase path without host (`apps/svc_…`, `cache/svc_…`, `mirror/docker.io/library/postgres`).
- Daemon behaviour now relevant to the dashboard: `build` and `plan` tasks are implemented; `system.buildkit.image` must have the pinned digest `sha256:65eca3c01cd1180c8f64565c4e3c5983af5145818b0e6fa863385d099eb73855` (`moby/buildkit:v0.34.0-rootless`); images under `127.0.0.1:5000/` are pulled with registry tokens from `/daemon/v1/registry-token`.

### Phase 1 changes (2026-10-18)

v1 is unreleased, so these change v1 in place. Changes marked **BREAKING** need the dashboard to change what it renders or accepts.

- **BREAKING** `NodeState.routes[].upstreams` items are now objects instead of `"ip:port"` strings: `{"workload": "wl_…", "port": N}` (a workload in the same NodeState; launchroomd resolves the container IP) or `{"address": "ip:port"}` (mesh-published replica, Phase 3). Exactly one form per item; upstreams must be unique per route; `workload` must reference a workload of the NodeState.
- **BREAKING** `NodeState.routes[].headers`: values must not contain `{` or `}` (schema: `pattern ^[^{}]*$`), header names now also have a schema `propertyNames` pattern. An empty value deletes the header.
- `Report` gained optional `host` (`cpus`, `memoryMb`, `diskTotalMb`, `diskFreeMb`, `dockerVersion`, `daemonVersion`) and `ingress` (`state`: `pending|applied|failed`, `routes`, `appliedAt`, `message`).
- `Report.workloads[]` gained optional `health` (`state`: `starting|passing|failing`, `consecutiveFailures`, `lastCheckedAt`, `lastError`).
- New schema `workload-env.json` (`WorkloadEnv`): the exact plaintext inside `workloads[].env.sealed`, `{"serverId", "workloadId", "env"}`, decoded strictly. New test vectors in `fixtures/sealing/workload-env.json`.
- Daemon behaviour now relevant to the dashboard: reports are always full (`full: true`); `seq` starts at launchroomd's clock in Unix microseconds; workloads removed from the desired state are reported as `removed` for 10 minutes; `system.caddy.image` must have a digest pinned by launchroomd release (currently `sha256:685ae3d7c34d9b5f8caeddc5d14051db7bbd88ff1f5a2fa0c16bd8ca328fb7af`, `caddy:2` v2.11.7); only `logs` tasks are supported, other leased types complete as `failed`.

### Phase 0

Initial version.

- Documents: `NodeState`, `EnrollRequest`/`EnrollResponse`, `LeaseRequest`/`TaskLease`/`Task`, `TaskHeartbeat`/`TaskHeartbeatResult`, `TaskCompletion`, `Report`, `LogBatch`.
- Task types: `build`, `plan`, `run_once`, `backup`, `restore`, `backup_maintenance`, `logs`, `self_update`. (`verify_restore` from docs/14 is deferred to Phase 4.)
- Request signing: RFC 9421 profile over `@method @path @query content-digest date x-lr-nonce`, Ed25519, `tag="launchroomd-v1"`.
- Response signing: `X-LR-Signature: kid=…, sig=…` over the exact body bytes.
- Compared with the sketch in docs/03 §5, `NodeState` omits `expiresAt` (a NodeState never expires) and `routes[].protection` (preview protection is a later phase). Workloads gained `capabilities` (allow-listed by local policy).
