# Request trace fixtures (docs/23 §2)

Shared vectors for the `Launchroom-Trace` header. The Go encoder in `deploy/images/caddy/lrtrace` and the dashboard's
PHP decoder both run them.

| File | Contents |
|---|---|
| `key.json` | The test key: `keyId` (uint32), `key` (32 bytes, standard base64, the form of the IngressSecret value `key`), `edge` (the key's server). |
| `valid/*.json` | `fields` (the decoded trace), `payload` (hex of the plaintext payload), `nonce` (hex, 12 bytes), `keyId`, `header` (the encoded header). |
| `invalid/*.json` | A header every decoder refuses, with `reason` (`format`, `key`, `auth` or `payload`) for the tests only: the user sees one answer for all of them. |
| `paths.json` | Path normalisation vectors (docs/23 §2.1): `in` (request URI) and `want` (normalised path, `null` for no path field). |

## Field names in `fields`

The JSON names of docs/23 §2: `receivedAt` (Unix ms), `requestId` (UUID string), `edge`, `route`, `service`,
`upstreamServer` (public ids: `srv_`, `rt_`, `sin_`, `srv_`), `method`, `path`, `protocol` (1–4), `status`, `origin`
(1–8), `edgeTime` and `upstreamTime` (µs), `upstreamStatus`, `attempts`, `failure` (1–5), `firewall`
(`{mode, outcome, score, rules}`). Absent fields are omitted. Enumerations stay numbers, as on the wire.

## Rules every decoder follows

- `header` is base64url without padding. Anything else (padding, other alphabets, whitespace) is refused.
- Bytes: version `0x01` | key id (uint32 BE) | nonce (12) | AES-256-GCM ciphertext and 16-byte tag. The additional data
  is the first 5 bytes. Shorter than 33 bytes: refused.
- Payload: `tag | length | value` records, any order. Unknown tags are skipped. Refused: a length running past the end,
  a known tag with a wrong length, a known tag twice, a value outside its range (protocol 1–4, origin 1–8, failure 1–5,
  firewall mode 1–2 and outcome 0–2, method 1–16 printable ASCII bytes, path 1–128 bytes starting with `/`, at most 8
  firewall rules), and a payload without `receivedAt`, `requestId`, `edge`, `status` or `origin`.
- Ids: the 16 bytes are the ULID behind the public id (Crockford base32, 26 characters, uppercase); the tag fixes the
  prefix. The UUID is written in lowercase with hyphens.

## How headers are built

`header` = base64url(`0x01` ‖ keyId ‖ nonce ‖ AES-256-GCM(key, nonce, payload, aad = `0x01` ‖ keyId)). Valid
fixtures use the `keyId` and `nonce` of the file and the key of `key.json`. Invalid fixtures with a `payload` start from
that header with their `version` and `keyId`, then apply `mutate`:

- `{"truncate": n}`: keep the first n bytes before encoding.
- `{"flipByte": i}`: XOR byte i with `0x01` (negative i counts from the end).
- `{"aadKeyId": k}`: encrypt with key id k in the additional data, keep `keyId` in the header.
- `{"append": "s"}`: append s to the encoded header.

A `header` of `null` has not been generated yet. The Go test regenerates every `header` from the recipe and checks
the rest (`LR_TRACE_FIXTURES_UPDATE=1`, see `deploy/images/caddy/lrtrace/format`); without the variable it fails on a
stale or missing header. A decoder test can always rebuild the header from the recipe as well.
