# protocol/: launchroomd ↔ dashboard contract

This folder is the shared contract between the dashboard (Laravel) and launchroomd (Go) ([docs/03](../docs/03-daemon-protocol.md), [docs/10](../docs/10-codebase-conventions.md) §5).

- **Source of truth:** the Go types in [`daemon/internal/state`](../daemon/internal/state). Their doc comments become the schema descriptions.
- **Generated:** [`schema/v1/*.json`](schema/v1/) (JSON Schema 2020-12). **Never edit these by hand.**
- **Shared fixtures:** [`fixtures/v1/`](fixtures/v1/) holds example documents. [`fixtures/signing/`](fixtures/signing/) holds signature test vectors and [`fixtures/sealing/`](fixtures/sealing/) sealed-env test vectors, both generated.
- **Signatures:** [`SIGNING.md`](SIGNING.md) specifies request and response signing.
- **History:** [`CHANGELOG.md`](CHANGELOG.md).

PHP Data objects mirror the Go types by hand. CI validates both sides: Go decodes every fixture strictly and validates it against the schemas, and the dashboard validates its rendered documents and the same fixtures against the schemas.

## Documents (v1)

| Schema | Direction | Endpoint | Signed |
|---|---|---|---|
| `enroll-request.json` | daemon → CP | `POST /daemon/v1/enroll` | request (keyid `enroll`) |
| `enroll-response.json` | CP → daemon | response to enroll | **yes**, by the pinned key |
| `node-state.json` | CP → daemon | `GET /daemon/v1/state?have=<version>` (`200` or `304`) | **yes** |
| `lease-request.json` | daemon → CP | `POST /daemon/v1/tasks/lease` | request |
| `task-lease.json` | CP → daemon | response to lease (0..n `Task`) | **yes** |
| `task.json` | inside `task-lease` | a single task. `spec` is a union selected by `type` | (via lease) |
| `task-heartbeat.json` | daemon → CP | `POST /daemon/v1/tasks/{id}/heartbeat` | request |
| `task-heartbeat-result.json` | CP → daemon | response to heartbeat | **yes** |
| `task-completion.json` | daemon → CP | `POST /daemon/v1/tasks/{id}/complete`, header `Idempotency-Key: <taskId>:<attempt>` | request |
| `report.json` | daemon → CP | `POST /daemon/v1/reports` (`204`) | request |
| `log-batch.json` | daemon → CP | `POST /daemon/v1/logs` (`204`) | request |
| `workload-env.json` | CP → daemon, sealed | plaintext inside `workloads[].env.sealed` (see below) | sealed to the launchroomd key |
| `registry-token-request.json` | daemon → CP | `POST /daemon/v1/registry-token` | request |
| `registry-token.json` | CP → daemon | response to registry-token (`200`; `403` when any scope is denied) | **yes** |
| `client-certificate-request.json` | daemon → CP | `POST /daemon/v1/client-certificate` (mTLS, D50) | request |
| `client-certificate.json` | CP → daemon | response to client-certificate (`200`; `403` re-issue refused while certificates are required, `429`) | **yes** |
| `task-token.json` | CP → daemon, sealed | plaintext inside a task credential (`BuildInputs.cloneToken`, `InventorySource.cloneToken`) | sealed to the launchroomd key |
| `task-env.json` | CP → daemon, sealed | plaintext inside `BuildInputs.env`, `RunOnceSpec.env`, `BackupDestination.credentials` (exactly `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `RESTIC_PASSWORD`; local: `RESTIC_PASSWORD`) and `DbImportSource.url` (exactly `SOURCE_URL`) | sealed to the launchroomd key |
| (raw bytes) | CP → daemon | `GET /daemon/v1/transfers/{dtr}/chunks/{n}`: chunk *n* (0-based) of an upload, `application/octet-stream` (`200`; `404` not this server's leased import, `410` transfer gone, `422` bad index) | **yes**, over the exact bytes |
| (raw bytes) | daemon → CP | `PUT /daemon/v1/transfers/{dtr}/chunks/{n}`: chunk *n* of an export, `application/octet-stream`, exactly `chunkBytes` except the last (`204`) | request (content-digest) |

Task types: `build`, `plan`, `run_once`, `backup`, `restore`, `backup_maintenance`, `backup_snapshots`, `verify_restore`, `session`, `logs`, `self_update`, `db_import`, `volume_import`, `db_export`, `volume_export`, `access`, `inventory`, `inspect_container` (spec types `BuildSpec`, `PlanSpec`, `BackupSnapshotsSpec`, … in `task.json` `$defs`).

## Conventions

- **Envelope:** every top-level document has `"apiVersion": "launchroom.vantezzen.io/v1"` and a `kind` (`NodeState`, `EnrollRequest`, …). `Task` objects inside a lease have no envelope.
- **Field names** are camelCase and match exactly. launchroomd decodes **case-sensitively**, rejects **unknown fields**, **duplicate keys** and **invalid UTF-8**, and accepts exactly one JSON value per body.
- **Optional fields** are omitted. `null` is also accepted for fields marked nullable in the schema. **Required arrays are always present**, and empty means `[]`.
- **Maps** (`headers`, `controlPlaneKeys`) must be JSON objects. PHP pitfall: `json_encode([])` produces `[]`. Cast to `(object)` or omit the field.
- **IDs** are public prefixed ULIDs: `srv_`, `net_`, `vol_`, `wl_`, `svc_`, `env_`, `dpl_`, `rt_`, `tsk_` + 26 uppercase Crockford base32 characters ([docs/07](../docs/07-data-model.md)).
- **Host-side names** (`networks[].name`, `volumes[].name`) are generated by the dashboard from IDs (`^lr-[a-z0-9][a-z0-9_-]{0,59}$`), never from user input ([docs/09](../docs/09-security.md) R3). Aliases are service slugs (`^[a-z0-9][a-z0-9-]{0,62}$`).
- **Images** are digest-pinned with an explicit registry and no tag: `127.0.0.1:5000/apps/svc_01j…@sha256:<64 hex>`. Docker repository paths must be lowercase, so lowercase the id in repository names. launchroomd's local policy only accepts its allowed registry (default `127.0.0.1:5000/`).
- **argv** (`command`, `args`) is always an array, never a shell string. If present it is non-empty. Omit it to use the image default.
- **Durations** are a single unit: `500ms`, `30s`, `5m`, `1h`.
- **Times** are RFC 3339 (`2026-10-17T12:00:00Z`).
- **Secrets** are never in plaintext. Workload env, tokens and S3 credentials are `{"sealed": "<base64 of crypto_box_seal(…, daemon X25519 key)>"}` ([docs/03](../docs/03-daemon-protocol.md) §6). See "Sealed workload env" below.
- **Route upstreams** are objects, exactly one form each: `{"workload": "wl_…", "port": 3000}` for a workload in the same NodeState (launchroomd resolves its container IP) or `{"address": "100.64.0.9:31002"}` for a mesh-published replica on another server. Never container IPs.
- **Route headers**: an empty value removes the header; values must not contain `{` or `}` (Caddy placeholders).
- **Route protection** (`protection.forwardAuth`): `url` is the dashboard's `http(s)://host[:port]/auth/preview` (lowercase DNS name or IPv4 literal, no credentials/query/fragment); `stripHeaders` (≤ 8, unique) are removed from the request after a successful check. Every request is checked first; 2xx continues to the app, anything else is returned as is. launchroomd only calls origins of its control-plane URL or `LR_ALLOWED_LOCAL_UPSTREAMS` (`http://ip:port`); other URLs make the route answer 403 (fail closed).
- **Volume deletion** is explicit only: `deleteVolumes` (sent only when non-empty), outcomes in `Report.volumeDeletions` (docs/05 §1.1).
- **Task outcome `released`**: launchroomd shut down before the task finished; the dashboard re-queues it without using up an attempt (docs/03 §7).
- **NodeState.version** is monotonic per server. launchroomd rejects anything `<=` its current version, and persists the exact signed bytes and signature in `data/daemon/state.json`.

The schema can't express every rule. The Go `Validate()` methods also check cross-references, for example:
- workload networks and mounts must reference declared networks and volumes;
- ids, hosts and mesh ports must be unique;
- `drainUntil` is required exactly when `role` is `draining`;
- `health` and `publishOnMesh` require `port`;
- `redirectWwwTo` must be one of the route's `hosts`;
- route `protection.forwardAuth.stripHeaders` must be unique;
- route upstream `workload` must reference a workload in the same NodeState, and upstreams of a route must be unique;
- `BuildSpec.image` must be `<registry>/apps/<service id lowercased>`; `builder.railpackVersion` is required for `auto` and `railpack` builds, and preset/commands/`railpackEnv`/`disabledDecisions` are only valid for them;
- container paths must not contain `.` or `..` segments;
- `deleteVolumes` ids and names are unique and never declared in `volumes`.

The dashboard's renderer must enforce the same rules. It does so before storing a document (`App\Services\NodeStates\NodeStateValidator`: this schema plus a port of the rules above); an invalid render is never published (docs/03 §5). If launchroomd still refuses a document, it keeps its last good state and reports `stateRejected` until a newer one arrives. launchroomd re-checks everything, plus its local **policy floor** ([docs/03](../docs/03-daemon-protocol.md) §8), and the policy floor is not visible in the schema.

## Sealed workload env

`workloads[].env.sealed` is standard base64 (padded) of libsodium `crypto_box_seal(plaintext, daemonSealingPublicKey)`, where the key is the 32 raw bytes of launchroomd's `sealingKey` (`x25519:<base64>` from enrollment). The plaintext is the UTF-8 JSON document of [`workload-env.json`](schema/v1/workload-env.json):

```json
{"serverId":"srv_01J9…","workloadId":"wl_01J9…","env":{"DATABASE_URL":"postgres://…","EMPTY":""}}
```

- Exactly these three members (decoded strictly: unknown/duplicate members are rejected). Member order and whitespace don't matter.
- `serverId` and `workloadId` must be the server the NodeState is for and the workload the env belongs to; launchroomd rejects anything else (no re-targeting). Re-seal per workload and server; never reuse a sealed blob.
- `env` is an object (`{}` when empty; PHP: cast to `(object)`), names match `^[A-Za-z_][A-Za-z0-9_]{0,255}$`, values are strings without NUL bytes, at most 1024 variables and 128 KiB per value.
- PHP: `base64_encode(sodium_crypto_box_seal(json_encode($doc, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), $daemonSealingKey))`. The ciphertext is randomised, so sealing the same env twice gives different strings; launchroomd does not recreate containers just because the ciphertext changed.
- [`fixtures/sealing/workload-env.json`](fixtures/sealing/workload-env.json) holds a public test key pair, the exact plaintext and sealed cases (valid, re-targeted, wrong server, unknown member, bad name, tampered). They were sealed with fixed ephemeral keys (`ephemeralSeed`) so they are deterministic. A PHP test should open each case with `sodium_crypto_box_seal_open(base64_decode($sealed), sodium_crypto_box_keypair_from_secretkey_and_publickey($sk, $pk))` and apply the same binding checks, and round-trip its own sealed output through the same checks.

## Sealed task secrets

Build and plan specs carry two sealed values, same format as the workload env (`crypto_box_seal` to launchroomd's X25519 key, standard padded base64), bound to the task so they can't be re-targeted (re-seal for every task; a retry keeps the task id):

- `cloneToken`: [`task-token.json`](schema/v1/task-token.json) `{"serverId", "taskId", "purpose": "clone", "token"}`. GitHub: the installation token (launchroomd sends it as Basic auth `x-access-token:<token>`, in memory only). Omit the field for public repositories.
- `env`: [`task-env.json`](schema/v1/task-env.json) `{"serverId", "taskId", "env": {NAME: value}}`: the build-scoped variables only. They become BuildKit secrets; names listed in `buildArgs` are additionally passed as build args to Dockerfiles that declare them (never list secret-flagged variables).

PHP: `base64_encode(sodium_crypto_box_seal(json_encode(['serverId' => …, 'taskId' => …, 'purpose' => 'clone', 'token' => $token], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), $daemonSealingKey))`.

## Registry tokens

`POST /daemon/v1/registry-token` (`RegistryTokenRequest`) returns a signed `RegistryToken` with a Docker token-spec bearer JWT (ES256, `access` claim, ≤ 5 min) or `403`. Scopes are granted all-or-nothing: pull on `mirror/<registry>/<path>`; pull on `apps/<service>` present in the server's latest NodeState; pull and push on `apps/<service>`/`cache/<service>` only with `task` naming a build task the server currently holds for that service. See [docs/05](../docs/05-networking-ingress.md) §6.

## Regenerating

From the repository root, run `make gen-protocol`. It runs `go generate ./internal/state` in the `golang` container, which writes `protocol/schema/v1/*.json`, and regenerates the signing and sealing vectors. `make test-daemon` fails if the committed schemas or vectors are stale (`TestSchemasUpToDate`, `TestSigningVectors`, `TestSealingVectors`).

## Fixtures

Name fixtures `<schema-name>.<variant>.json`, for example `node-state.web-postgres.json` or `task-lease.build.json`. `daemon/internal/state/fixtures_test.go` picks up every file in `fixtures/v1/`, strictly decodes it as the type matching its schema name, and validates it against that schema. Add a fixture whenever the dashboard starts emitting a new shape.

## Versioning

Additive, backwards-compatible changes (new optional fields, new enum values launchroomd already handles) stay in `v1` and are noted in the changelog. Note that because decoding is strict, a **new field is breaking for older daemons**. The dashboard must only send fields that launchroomd's advertised version understands. Breaking changes create `schema/v2/`, and the dashboard renders for each daemon's highest supported version and supports N-1 ([docs/03](../docs/03-daemon-protocol.md) §10).
