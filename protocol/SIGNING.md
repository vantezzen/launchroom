# Daemon ↔ dashboard signatures (v1)

This is the normative description of how launchroomd signs its requests and how the dashboard signs its responses ([docs/03](../docs/03-daemon-protocol.md) §4.1, [docs/09](../docs/09-security.md) R21–R22). The reference implementation is `daemon/internal/identity` (`httpsig.go`, `response.go`). Test vectors are in [`fixtures/signing/`](fixtures/signing/).

Both directions use **Ed25519** (RFC 8032, pure Ed25519, no prehash). In PHP, use `sodium_crypto_sign_detached()` / `sodium_crypto_sign_verify_detached()`.

Everything is **fail closed**. A missing, malformed, unknown-key or non-verifying signature is a rejection, never a warning. Compare secrets and digests with `hash_equals`.

## 1. Key formats

| Thing | Format | Example |
|---|---|---|
| Ed25519 public key | `ed25519:` + standard base64 (with padding) of the 32 raw bytes | `ed25519:A6EHv/POEL4dcN0Y50vAmWfk1jCbpQ1fHdyGZBJVMbg=` |
| X25519 public key (secret sealing) | `x25519:` + standard base64 of the 32 raw bytes | `x25519:…=` |
| Fingerprint (for humans) | `SHA256:` + **unpadded** standard base64 of `sha256(raw 32-byte key)` | `SHA256:ZGBzPU0JEvRzIko+bpTGIdZGTgQVgkcZL3Sd6MrqSfI` |
| Control-plane key id (`kid`) | `^[A-Za-z0-9._-]{1,64}$`, chosen by the dashboard | `cp-2026-10` |
| Daemon key id (`keyid`) | `enroll` before enrollment, afterwards the server id `^srv_[0-9A-HJKMNP-TV-Z]{26}$` | `srv_01J9Z8X7W6V5T4S3R2Q1P0N9MK` |

The install command passes the control-plane signing **public key** in the same `ed25519:` format (`LR_CP_KEY`, `--cp-key`). It is 44 characters of base64, short enough to pin the key itself rather than a hash of it. launchroomd prints its own key and fingerprint at startup (`identity ready` log line), so an admin can compare them before approving a server.

## 2. Daemon → dashboard: request signatures (RFC 9421 profile)

Every request to `/daemon/v1/*`, including enrollment, carries these headers:

| Header | Value |
|---|---|
| `Content-Digest` | RFC 9530: `sha-256=:<base64 std of SHA-256(body)>:`. Always present. An empty body (GET) is digested as zero bytes: `sha-256=:47DEQpj8HBSa+/TImW+5JCeuQeRkm5NMpJWZG3hSuFU=:` |
| `Date` | IMF-fixdate (RFC 9110), whole seconds, GMT: `Sat, 17 Oct 2026 12:00:00 GMT` |
| `X-LR-Nonce` | 16 random bytes, base64url **without** padding (22 chars, `^[A-Za-z0-9_-]{22}$`) |
| `Signature-Input` | `sig1=` + the signature parameters below |
| `Signature` | `sig1=:<base64 std of the 64-byte Ed25519 signature>:` |
| `X-LR-Protocol-Versions` | `v1` (not signed; informational, [docs/03](../docs/03-daemon-protocol.md) §10) |

### 2.1 Covered components and parameters (fixed)

The profile has no options. The covered components are always these, in this order:

```
("@method" "@path" "@query" "content-digest" "date" "x-lr-nonce")
```

The parameters are always, in this order: `created` (Unix seconds, equal to the `Date` header), `keyid`, `alg="ed25519"`, `tag="launchroomd-v1"`. The full `Signature-Input` value is therefore:

```
sig1=("@method" "@path" "@query" "content-digest" "date" "x-lr-nonce");created=1792238400;keyid="srv_01J9Z8X7W6V5T4S3R2Q1P0N9MK";alg="ed25519";tag="launchroomd-v1"
```

A verifier **must** reject any `Signature-Input` that doesn't match this exact shape:

```
^sig1=(\("@method" "@path" "@query" "content-digest" "date" "x-lr-nonce"\);created=([0-9]{1,12});keyid="([A-Za-z0-9_]{1,64})";alg="ed25519";tag="launchroomd-v1")$
```

Capture group 1 is the signature-params value used in the base below.

### 2.2 Component values

| Component | Value |
|---|---|
| `@method` | Request method, uppercase (`GET`, `POST`) |
| `@path` | The path of the request target **exactly as sent on the wire** (still percent-encoded), `/` if empty. Behind a path prefix it includes the prefix: base URL `https://h/launchroom` gives `/launchroom/daemon/v1/state`. |
| `@query` | `?` followed by the raw query string as sent, or just `?` when there is none (RFC 9421 §2.2.7) |
| `content-digest` | The `Content-Digest` header value, byte for byte |
| `date` | The `Date` header value, byte for byte |
| `x-lr-nonce` | The `X-LR-Nonce` header value, byte for byte |

PHP note: take `@path` and `@query` from the raw request target (`$_SERVER['REQUEST_URI']`, split at the first `?`), **not** from `$request->getPathInfo()`, which is decoded and strips the base URL. Reverse proxies (Caddy) must not rewrite the path for `/daemon/v1`.

### 2.3 Signature base

One line per covered component, `"<name>": <value>`, then the `@signature-params` line. Lines are joined with a single LF (`\n`), and there is **no trailing newline**:

```
"@method": GET
"@path": /daemon/v1/state
"@query": ?have=1842
"content-digest": sha-256=:47DEQpj8HBSa+/TImW+5JCeuQeRkm5NMpJWZG3hSuFU=:
"date": Sat, 17 Oct 2026 12:00:00 GMT
"x-lr-nonce": bGF1bmNocm9vbS1ub25jZQ
"@signature-params": ("@method" "@path" "@query" "content-digest" "date" "x-lr-nonce");created=1792238400;keyid="srv_01J9Z8X7W6V5T4S3R2Q1P0N9MK";alg="ed25519";tag="launchroomd-v1"
```

The signature is `Ed25519(daemon signing key, UTF-8 bytes of the base)`.

### 2.4 Verification (dashboard `AuthenticateDaemon` middleware)

Reject with `401` at the first failing step:

1. Exactly one of each of `Signature-Input`, `Signature`, `Content-Digest`, `Date` and `X-LR-Nonce` is present. If a header is repeated, reject.
2. `Signature-Input` matches the regex in §2.1. `Signature` matches `^sig1=:([A-Za-z0-9+/]{86}==):$` and decodes to 64 bytes.
3. `Content-Digest` matches `^sha-256=:([A-Za-z0-9+/]{43}=):$` and `hash_equals()` the digest you compute over the **raw** body (`$request->getContent()`).
4. `Date` parses as IMF-fixdate, `created` equals its Unix time, and it is within **±60 s** of the server clock.
5. `X-LR-Nonce` matches `^[A-Za-z0-9_-]{22}$` and hasn't been seen before. Use Valkey `SET lr:nonce:<keyid>:<nonce> 1 NX EX 300`; if the key already exists, reject.
6. Resolve the key from `keyid`:
   - `srv_…`: the signing key of a **non-revoked** server with that public id.
   - `enroll`: only valid on `POST /daemon/v1/enroll`. Use the `signingKey` from the (strictly validated) request body, which proves possession of the key being enrolled. The join token is checked separately.
7. Rebuild the base (§2.3) from the received values and verify the signature.
8. Bind the server to the request. Every endpoint is scoped to it.

`identity.VerifyRequest` in Go implements steps 1–4 and 6–7 and is the reference.

## 3. Dashboard → daemon: response signatures

Every response whose **body launchroomd acts on** carries:

```
X-LR-Signature: kid=<kid>, sig=<base64 std of the 64-byte Ed25519 signature>
```

- The signature covers the **exact bytes of the response body**, as launchroomd receives them after any `Content-Encoding` is removed. Sign the final JSON string you send, not a re-encoded copy. If a compression middleware is added later, the signature is still over the uncompressed bytes.
- The format is strict: `kid=`, then the kid, then exactly `, sig=` (comma, one space), then 88 base64 characters. Regex: `^kid=([A-Za-z0-9._-]{1,64}), sig=([A-Za-z0-9+/]{86}==)$`.
- launchroomd verifies the raw bytes **before** parsing, then decodes strictly. No JSON canonicalisation is involved.
- launchroomd trusts a **key set** (`kid → public key`), received at enrollment, so the dashboard can rotate keys. An unknown `kid` is a rejection.
- Which responses must be signed: `200 GET /state`, `200/201 POST /enroll`, `200 POST /tasks/lease`, `200 POST /tasks/{id}/heartbeat`, `200 POST /client-certificate`. Bodiless responses (`304`, `204`) and error responses aren't signed. launchroomd never acts on an error body; it only logs a sanitized 256-byte snippet. launchroomd never follows redirects.
- The signing key is `LAUNCHROOM_SIGNING_KEY` (a file, not the DB, not `APP_KEY`), and it is used only for this purpose (R22).

### 3.1 Enrollment response

launchroomd verifies the enrollment response against the **pinned** key from the install command (`LR_CP_KEY`), whatever `kid` the header names. It then requires `controlPlaneKeys[kid]` in the response body to be that same pinned key. If it isn't, enrollment fails, because otherwise a man in the middle could hand launchroomd its own key set. launchroomd persists `controlPlaneKeys` as its trust set.

## 4. Test vectors

The vectors are generated deterministically by `daemon/internal/identity/vectors_test.go`. Regenerate them only on an intentional profile change: `make gen-protocol`, or `go test ./internal/identity -run TestSigningVectors -update`. The keys are public test keys. **Never use them anywhere else.**

| File | What |
|---|---|
| `fixtures/signing/request-get-state.json` | GET with query, empty body |
| `fixtures/signing/request-post-lease.json` | POST with JSON body, enrolled keyid |
| `fixtures/signing/request-enroll.json` | POST with keyid `enroll` |
| `fixtures/signing/response.json` | one valid response signature + negative cases (`valid: false`) |

Request vector fields:
- `privateKeySeed`: the 32-byte Ed25519 seed (base64). In PHP: `sodium_crypto_sign_seed_keypair()`.
- `publicKey`
- `keyId`
- `created`
- `nonceBytes`: the raw nonce before base64url.
- `request`: `{method, url, body}`
- `expected`: `{contentDigest, date, nonce, signatureInput, signature, signatureBase}`
- `verifyAt`: the clock to use when verifying.
- `maxSkewSeconds`

A conforming implementation must:
1. Reproduce `expected.signatureBase` and `expected.signature` from the inputs. Ed25519 is deterministic, so the signature matches byte for byte.
2. Accept the request when verifying at `verifyAt`.
3. Reject it after changing the body, method, query or nonce, or when verifying 61 s later.

For `response.json`, every case in `cases` must verify to `valid` against the key set `{kid: publicKey}`.

## 5. Known limitations

- A response signature doesn't bind to a particular request. Replaying an old signed NodeState is harmless because launchroomd rejects versions `<=` its current one. Replaying an old lease response is bounded by `leaseExpiresAt`/`deadline` and completion idempotency `(taskId, attempt)`. Binding responses to the request nonce is a possible v2 hardening.
- How the key set is rotated after enrollment isn't specified yet. The proposal is to deliver new keys inside a document signed by a currently trusted key.
- Client certificates (mTLS) are an additional, opt-in layer below these signatures, never a replacement ([docs/03](../docs/03-daemon-protocol.md) §4.3, §6 below).

## 6. Client certificates (mTLS, D50)

Normative details are in [docs/03](../docs/03-daemon-protocol.md) §4.3; the wire contract:

- `POST /daemon/v1/client-certificate` is signed like every request (§2). Body `ClientCertificateRequest {apiVersion, kind, publicKey}`, `publicKey` = standard base64 of the DER `SubjectPublicKeyInfo` of an ECDSA P-256 key. The `200` response `ClientCertificate {apiVersion, kind, serverId, certificate, caCertificates[1..4], notBefore, notAfter, renewAfter}` is signed (§3; add it to the list of signed responses). `certificate`/`caCertificates` are standard base64 of DER.
- Leaf profile: X.509 v3, ECDSA with SHA-256, serial 16 random bytes (positive), subject `CN=<server id>, O=launchroom`, SAN URI `urn:launchroom:server:<server id>`, EKU `clientAuth`, key usage `digitalSignature` (critical), `CA:false` (critical), validity ≤ 7 days.
- The fingerprint everywhere (Caddy's `{http.request.tls.client.fingerprint}`, `X-Lr-Client-Cert`, `server_client_certificates.fingerprint`) is the **lowercase hex** SHA-256 of the leaf's DER, 64 characters, compared with `hash_equals`.
- `X-Lr-Edge` carries the edge secret the dashboard generated (`lr_edge_` + 43 base64url characters), sealed to the control-plane server as an `IngressSecret` (purpose `client_auth`, value `secret`). The dashboard compares it with `hash_equals`; a wrong value is a 401, never "no certificate".

## 7. Audit checkpoints (D50)

A checkpoint signs the position of the audit chain ([docs/09](../docs/09-security.md) R34) with the control-plane signing key (the key of §3, under its `kid`). The signed bytes are

```
launchroom-audit-checkpoint-v1\n<canonical JSON>
```

where the canonical JSON is `{"count":<entries up to and including the entry>,"createdAt":"<RFC 3339 UTC, microseconds, Z>","eventId":<id>,"hash":"<64 hex>","instance":"<instance id or null>"}` with keys sorted, no whitespace, unescaped slashes and unicode (the audit chain's `CanonicalJson`). The signature is pure Ed25519, standard base64. The prefix keeps the message from ever being a valid daemon payload: launchroomd verifies response bodies and then decodes them strictly as JSON documents, and this message is not JSON. Forwarded checkpoints carry `kid` and `signature`, so a copy can be checked with the public key alone (`launchroom:audit:verify --public-key` prints it, the same key daemons pin as `LR_CP_KEY`).
