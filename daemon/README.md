# launchroomd

The Go daemon that runs on every server ([docs/03](../docs/03-daemon-protocol.md), conventions in [docs/10](../docs/10-codebase-conventions.md) §4). Module: `github.com/vantezzen/launchroom/daemon`. Image: `ghcr.io/vantezzen/launchroomd` (it also carries `dockerd-guard` and `launchroom-ambassador`).

launchroomd dials out to the dashboard, verifies and persists the signed NodeState, and reconciles Docker networks, volumes, workloads, Caddy routes, the mesh client, the ambassador and nftables rules to match. It leases typed tasks (`plan`, `build`, `run_once`, `logs`, backups and restores, sessions, self-update), reports host, workload, certificate and link state, and keeps converging while the dashboard is unreachable. It reaches Docker only through dockerd-guard (D49).

## Layout

| Path | Responsibility |
|---|---|
| `cmd/launchroomd` | `main`: config → identity → enroll if needed → poll state (30 s jittered, SIGHUP or a nudge = now) → verify → persist → reconcile loop → reports; task lease loop; ask endpoint; system containers. Graceful shutdown on SIGINT/SIGTERM (workloads keep running). JSON `slog`. |
| `cmd/dockerd-guard` | Owns the Docker socket in its own compose service and serves launchroomd an allow-listed Engine API on a unix socket (`internal/guard`, D49) |
| `cmd/ambassador` | `launchroom-ambassador`: the per-server TCP forwarder that lets workloads reach dependencies on other servers by their usual names ([docs/05](../docs/05-networking-ingress.md) §3); re-reads its config file every second |
| `cmd/schemagen` | Generates `protocol/schema/v1/*.json` from `internal/state` (invopop/jsonschema) |
| `internal/config` | Env + flags (flags win). The join token comes only from `LR_JOIN_TOKEN` / `LR_JOIN_TOKEN_FILE`, never argv. |
| `internal/identity` | Ed25519 + X25519 keys (`<data>/keys`, 0700/0600), RFC 9421 request signing, reference request verifier, `X-LR-Signature` response verification against a key set |
| `internal/clientcert` | mTLS client certificate for the dashboard (D50): own ECDSA P-256 key, certificate from the control plane's CA, renewal with a new key at two thirds of the lifetime |
| `internal/enrollment` | Persisted enrollment record (server id, trusted CP key set, poll intervals) |
| `internal/controlplane` | `/daemon/v1` client: enroll, state, tasks, heartbeats, completion, reports, log shipping, registry tokens, client certificates |
| `internal/nudge` | "Poll now" nudges over Reverb (Pusher protocol) on `private-daemon.<server id>` |
| `internal/state` | v1 protocol documents, strict decoding (`encoding/json/v2`), grammar validation, `Store` (atomic `state.json` + signature, monotonic versions) |
| `internal/policy` | Local policy floor ([docs/03](../docs/03-daemon-protocol.md) §8): workload specs, container configs, mesh login servers |
| `internal/docker` | Pure `WorkloadSpec` → container-create config (policy-checked before and after); `Client` over the Engine API that only lists and changes `launchroom.managed=true` objects, with per-call timeouts and pull progress; `dockerfake` in-memory fake for tests |
| `internal/guard` | dockerd-guard: route table, query and header allow-lists, strict body decoding and re-encoding, container classes, the policy floor applied a second time, rootless edge ports; `guard/guardapi` holds what launchroomd and the guard share |
| `internal/sealed` | Opens `crypto_box_seal` values (env, task secrets, mesh pre-auth keys) and checks their bindings |
| `internal/secret` | `Secret` type that redacts in logs, `fmt` and JSON |
| `internal/reconcile` | Pure `Diff(desired, actual)` → plan; `Reconciler` applies it, feeds health and ingress, builds report snapshots |
| `internal/health` | HTTP/TCP probes, probe state machine, one monitor goroutine per probed workload, `/proc/<pid>/net/tcp*` port discovery and hints |
| `internal/cronexpr` | 5-field cron grammar of scheduled cron services (`WorkloadSpec.schedule`), shared with the dashboard's `CronExpression` |
| `internal/ingress/caddy` | Pure route → Caddy JSON renderer (golden tests), admin client (`POST /load`), the `lr-system-caddy` container, on-demand TLS `ask` handler |
| `internal/ingress/certstore` | Reads certificates from Caddy's storage (shared Valkey or local files), mirrors them to the data dir, derives per-host certificate status |
| `internal/traffic` | Request metrics from the edge Caddy's access log (loopback `net` writer, per route and minute, 15-bucket latency histogram, [docs/15](../docs/15-observability.md) §1) |
| `internal/metrics` | Host and workload usage sampled every 15 s, aggregated per minute, buffered until a report is accepted |
| `internal/hostinfo` | Host health facts: disk, memory, load, inotify limits, Docker networks and disk usage, BuildKit cache, clock skew |
| `internal/logship` | Continuous runtime log shipping for workloads with `shipLogs` |
| `internal/firewall` | nftables isolation of workload and build networks, mesh allow-lists, link probe port ([docs/05](../docs/05-networking-ingress.md) §1) |
| `internal/mesh` | The `lr-system-tailscale` client container (kernel mode, key on stdin) and its reported state |
| `internal/linkprobe` | Daemon ↔ daemon latency and throughput probes on `<mesh IP>:7400` for the peers the NodeState lists |
| `internal/ambassador` | The forwarder library behind `cmd/ambassador` |
| `internal/ambassadord` | Runs the `lr-system-ambassador` container and keeps its listeners in step with the NodeState |
| `internal/registry` | `127.0.0.1:5000` (and build gateway) TCP forwarders to Zot, registry token cache, mirror warm-up before large pulls, `X-Registry-Auth` |
| `internal/tasks` | Lease loop, heartbeats, idempotent completion keyed by (task id, attempt), dispatch to the task handlers |
| `internal/build` | Build pipeline ([docs/04](../docs/04-build-and-deploy.md) §12): go-git clone of the exact SHA (HTTPS or SSH with pinned host keys), tree safety checks, builder resolution, Railpack planning, BuildKit solve, registry push, log mapping, secret masking, failure classification, repository inspection for generated Dockerfiles |
| `internal/build/presets` | Framework presets: pure `Propose(facts, railpackInfo)` + `Gather` of static repo facts; table tests over `internal/build/testdata/repos` |
| `internal/build/nextadapter` | Vendored `@vantezzen/launchroom-next-adapter` build (`make next-adapter` from `packages/next-adapter`), pinned by `SHA256SUMS`; the `nextjs` preset injects it for Next ≥ 16.2 |
| `internal/build/buildkitd` | The rootless `lr-system-buildkit` container, client over a Docker exec stream (`buildctl dial-stdio`) |
| `internal/backup` | restic (argv only) for database and volume backups, restores, WAL shipping and point-in-time recovery, restore verification ([docs/14](../docs/14-backup-and-restore.md)) |
| `internal/session` | Interactive console and data sessions relayed through Reverb with per-session MACed frames (D35, [docs/03](../docs/03-daemon-protocol.md) §11) |
| `internal/cleanup` | Disk cleanup on a schedule and under pressure: images beyond retention, build cache by age |
| `internal/update` | Signed self-update and control-plane update through the update supervisor; release verification against the pinned identity |
| `internal/atomicfile` | write temp → fsync → rename → fsync dir |
| `test/` | Repo-wide checks (`noshell_test.go`: no shell invocations anywhere); `test/integration` (dind: reconcile, builds, registry, mesh, backups, sessions, guard); `test/gitserve` (fixture git server for tests and the dev `git-fixtures` service); `test/releasesign` |

## Configuration

| Env | Flag | Default | Notes |
|---|---|---|---|
| `LR_CONTROL_PLANE_URL` | `--control-plane-url` | (required) | http(s), no credentials, query or fragment; always the fallback when a canonical origin was adopted ([docs/03](../docs/03-daemon-protocol.md) §4.2) |
| `LR_CONTROL_PLANE_FOLLOW` | | `on` | `off`: never move to the dashboard's canonical origin from `system.controlPlane` |
| `LR_DATA_DIR` | `--data-dir` | `/data` | absolute path; holds `keys/`, `enrollment.json`, `state.json` |
| `LR_CP_KEY` | `--cp-key` | (required to enroll) | pinned CP signing key `ed25519:<base64>` |
| `LR_CP_KEY_FILE` | `--cp-key-file` | | absolute path of a file containing the key (a missing file counts as unset; if both are set they must match) |
| `LR_JOIN_TOKEN` / `LR_JOIN_TOKEN_FILE` | none | | only one of the two; needed for the first start only |
| `LR_LOG_LEVEL` | `--log-level` | `info` | |
| `DOCKER_HOST` | | Docker client default | Engine endpoint; installs set dockerd-guard's socket `unix:///run/launchroom/docker.sock` |
| `LR_INGRESS_ASK_ADDR` | `--ingress-ask-addr` | `127.0.0.1:2021` | loopback address of the on-demand TLS ask endpoint |
| `LR_INGRESS_ACCESS_LOG_ADDR` | | `127.0.0.1:2022` | loopback listener for the edge Caddy's access log (request metrics) |
| `LR_INGRESS_HTTPS_PORT` | `--ingress-https-port` | `443` | public HTTPS port used in HTTP→HTTPS redirects (dev: `8443`) |
| `LR_ALLOWED_LOCAL_UPSTREAMS` | | | `ip:port` route upstreams allowed besides the mesh range, e.g. the dashboard's `127.0.0.1:8000` on the control-plane host |
| `LR_CERT_STORAGE_ALLOW` | | | `ip:port` shared certificate storage allowed besides the mesh range |
| `LR_CERT_STORAGE_UPSTREAM` | | `127.0.0.1:6380` | local Valkey the control-plane host serves on its mesh IP for the edges' certificate storage |
| `LR_SYSTEM_IMAGE_DIGESTS` | | | extra accepted system image digests, `component=sha256:…` comma-separated (host config only) |
| `LR_REGISTRY_UPSTREAM` | | | `host:port` the `127.0.0.1:5000` forwarder connects to when the NodeState sets no `system.registry.forwardTo` (dev: `zot:5000`) |
| `LR_BUILD_SLOTS` | | max(1, cores/4) | concurrent builds and plans |
| `LR_BUILD_SUBNET` | | `10.232.0.0/24` | build network `lr-system-build` (gateway hosts the build registry forwarder) |
| `LR_BUILD_INSECURE_GIT_HOSTS` | | | hosts that may be cloned over plain http (dev: `git-fixtures`) |
| `LR_BUILDKIT_MEMORY_MB` / `LR_BUILDKIT_CPUS` | | 75% of host memory / unlimited | buildkitd container limits |
| `LR_RESTIC_BINARY` | | `/usr/local/bin/restic` | else `restic` next to the daemon executable |
| `LR_BACKUP_INSECURE_HOSTS` | | | S3 endpoint hosts reachable over plain http (dev MinIO only) |
| `LR_FIREWALL` | | `enforce` | `off` disables nftables isolation |
| `LR_FIREWALL_ALLOW_PRIVATE` | | | private CIDRs (LAN) workloads and builds may reach; metadata, the mesh, other environments and the host stay blocked |
| `LR_MESH_INSECURE_LOGIN_HOSTS` | | | mesh login servers reachable over plain http (dev Headscale only) |
| `LR_AMBASSADOR_BIN` | | next to launchroomd | `launchroom-ambassador` executable |
| `LR_AMBASSADOR_SUBNET` | | `10.232.1.0/24` | the ambassador's own network |
| `LR_LINK_PROBE` | | `on` | `off`: no link probe listener and no measurements, whatever the NodeState says |
| `LR_INTERACTIVE_SESSIONS` | | `on` | `on`, `console`, `data` or `off` (local policy for D35 sessions) |
| `LR_HOST_TIMEZONE` | | | host time zone recorded by the installer; informational |

## Building and testing

Everything runs in Docker through the root `Makefile`; no Go toolchain is needed on the host. Run targets from the repository root (`make help` lists them all).

| Target | What it does |
|---|---|
| `make test-daemon PKG=./internal/foo/...` | `go test -race` on the given packages (without `PKG`: everything, under the heavy lock) |
| `make lint-daemon PKG=./internal/foo/...` | golangci-lint |
| `make gen-protocol` | Regenerates `protocol/schema/v1` and the signing/sealing test vectors |
| `make test-daemon-integration ARGS='-test.run TestFoo'` | `integration`-tagged tests in `test/integration` against an ephemeral dind daemon (`ITEST=<name>` isolates parallel runs) |
| `make test-daemon-integration-rootless` | `TestRootless*` against `docker:dind-rootless` |

Unit tests never touch a Docker daemon (they use `dockerfake`). The integration test binary is built in the `go` container and executed inside the dind container, so it shares launchroomd's network and PID namespaces like launchroomd on a server. It pulls small digest-pinned public images (needs internet) and removes everything afterwards. The host Docker socket is never mounted.

## Rules (summary of docs/09 and docs/10)

- No shell: `forbidigo` bans `exec.Command` and raw exec, and `test/noshell_test.go` scans for `sh -c` patterns.
- Every external call has a context and a timeout. Goroutines have an owner and a shutdown path.
- Secrets are `secret.Secret` and are revealed only at the boundary that consumes them.
- Anything launchroomd acts on is verified before it is parsed, and launchroomd fails closed.
