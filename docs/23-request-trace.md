# 23: Request trace

Every response that passes through an edge server carries an `X-Launchroom-Ray` header. Its value, the **ray**, is an
encrypted, self-contained record of what happened to the request at the edge: when it arrived, which edge and app server
handled it, what the web firewall decided and why, and how the app answered. A team member pastes the ray into launchroom
and sees the request's path. In the UI and the user docs the value is called a ray; inside the code the feature stays the
request trace (`TraceCodec`, `trace_keys`, `lr_trace`, feature `requestTrace`, `/trace`).

Nothing per request is stored. The ray lives in the response; whoever has the response has its ray. This keeps the
rule of docs/15 §1 (request metrics are aggregates, no per-request log) and of D63 (no addresses, bodies or query strings).

Decisions: D102 (docs/README.md).

## 1. Goals and limits

- Answer "what happened to this request?" for any request that reached an edge: firewall blocks (with the rules, so an
  exception can be added for all of them), 502/503/504 (which upstream, refused or timed out, how many attempts), slow
  responses (edge time vs. app time), and "which edge and app server served this?".
- Every response gets a ray, including redirects, 404s of unknown hosts and edge error pages.
- Connect the request to the app's own logs: the edge sends the request id to the app as `X-Request-Id`; the trace view
  opens the service's logs around the request's time, searched for that id.
- Not covered: requests that never reached an edge (DNS, TLS handshake, closed port). A response without the header did
  not pass through launchroom's edge, and the trace view says so.
- The trace is written with the response headers, so it can't contain the total duration or the response size (the body
  is still streaming). It has the time to the response headers instead.

## 2. Header format (v1)

Header: `X-Launchroom-Ray: lr1.<base64url without padding>`. The text prefix `lr1.` names the format version before
anything is decoded, so a dashboard can tell a ray from a newer launchroom (`lr2.` and up: "made by a newer version of
launchroom") from a broken one. Edges up to v0.1.6-rc.3 sent the header `Launchroom-Trace` with the envelope alone, no
prefix; decoders still read such a value as v1. The envelope after the prefix is

| Bytes | Field |
|---|---|
| 1 | version, `0x01` |
| 4 | key id, uint32 big-endian |
| 12 | nonce, random |
| n + 16 | AES-256-GCM ciphertext and tag of the payload |

The first 5 bytes (version and key id) are the additional authenticated data; the text prefix is not part of it (the
version byte inside already is). The encoding is strict base64url: padding, whitespace or another alphabet is refused,
and so is anything shorter than 33 bytes. Decoders refuse another `lr<n>.` prefix with its own answer (the prefix is
public, so this says nothing about the content), and other version bytes, unknown key ids and failed authentication
without saying which (one "not a ray we can read" answer).

**Payload**: a sequence of fields `tag (1 byte) | length (1 byte) | value`, in any order. Integers are big-endian unsigned.
Decoders skip unknown tags (newer edges can add fields) and refuse a length that runs past the end, a known tag with a wrong
fixed length, a known tag twice, a value outside its range (protocol 1–4, origin 1–8, failure 1–5, firewall mode 1–2 and
outcome 0–2, method 1–16 printable ASCII bytes, path 1–128 bytes starting with `/`, at most 8 firewall rules) and a
payload without `receivedAt`, `requestId`, `edge`, `status` or `origin`. Absent fields are omitted. Ids are the 16 bytes of
the ULID behind the public id; the tag fixes the prefix. The UUID is written lowercase with hyphens.

| Tag | Field | Value |
|---|---|---|
| `0x01` | receivedAt | 8 bytes, Unix milliseconds when the edge received the request |
| `0x02` | requestId | 16 bytes, the UUID sent to the app as `X-Request-Id` |
| `0x03` | edge | 16 bytes, `srv_` of the edge server |
| `0x04` | route | 16 bytes, `rt_` of the matched route; absent when no route matched |
| `0x05` | service | 16 bytes, `sin_` service instance of the route; absent for the dashboard's own routes |
| `0x06` | upstreamServer | 16 bytes, `srv_` of the server the last attempt went to (the edge itself for a local replica) |
| `0x07` | method | 1–16 bytes, ASCII |
| `0x08` | path | 1–128 bytes, the normalised path (§2.1) |
| `0x09` | protocol | 1 byte: 1 HTTP/1.0, 2 HTTP/1.1, 3 HTTP/2, 4 HTTP/3 |
| `0x0A` | status | 2 bytes, the status the edge sent |
| `0x0B` | origin | 1 byte, who produced the response (§2.2) |
| `0x0C` | edgeTime | 4 bytes, microseconds from receipt to the response headers |
| `0x0D` | upstreamTime | 4 bytes, microseconds from the last attempt's dial to the app's response headers |
| `0x0E` | upstreamStatus | 2 bytes, the status the app answered (differs from status when the firewall blocked the response) |
| `0x0F` | attempts | 1 byte, upstream attempts (only when known) |
| `0x10` | failure | 1 byte, why the upstream failed (§2.3) |
| `0x11` | firewall | 1 byte mode (1 log only, 2 block), 1 byte outcome (0 clean, 1 detected, 2 blocked), 1 byte inbound anomaly score (capped at 255), then 0–8 rule ids of 4 bytes each |

A typical value is 200–290 characters.

### 2.1 Path normalisation

The path is taken from the raw request URI (`r.RequestURI`, as in the access log, so percent-escapes stay), without query
string, normalised the way request analytics does it
(`daemon/internal/traffic/normalize.go`: ids, UUIDs, hashes and long numbers become `:id`), then cut to 128 bytes on a
UTF-8 boundary. The Caddy module keeps a copy of the function (`lrtrace/pathnorm`); the shared vectors in
`protocol/fixtures/v1/trace/paths.json` run against both, so they stay equal.

### 2.2 Origin

| Value | Origin |
|---|---|
| 1 | app: the response came from the service |
| 2 | firewall: the web firewall blocked the request or the response |
| 3 | upstream failure: the edge answered 502/503/504 because the app couldn't be reached (also the 503 page of a route without a ready upstream, with failure 3) |
| 4 | stopped: the service is stopped, the edge answered 503 |
| 5 | redirect: HTTP → HTTPS or www ↔ apex |
| 6 | no route: no domain on this edge matched the host |
| 7 | access refused: launchroom sign-in protection or a client certificate refused the request |
| 8 | edge: anything else the edge answered itself (reachability check) |

### 2.3 Failure

1 connection refused, 2 timeout, 3 no healthy upstream (every replica marked down), 4 other transport error, 5 the
client closed the connection.

### 2.4 Fixtures

`protocol/fixtures/v1/trace/` (layout and decoder rules in its `README.md`): the test key (`key.json`), valid field sets
with their payload, nonce and encoded header (`valid/`, an unknown tag and an unprefixed value of an older edge
included), headers every decoder refuses with the reason (`invalid/`: format, newer, key, auth or payload; truncated,
padded, `lr2.` prefix, wrong key id, tampered tag, duplicate tag, values out of range, missing required fields …) and the path vectors (`paths.json`). The Go encoder, the PHP decoder and launchroomd's
`NormalizePath` test run them; the Go test fails on a stale header and rewrites them with `LR_TRACE_FIXTURES_UPDATE=1`.

## 3. Edge (launchroom-caddy)

A Go module `deploy/images/caddy/lrtrace` (own `go.mod`, pinned to the image's Caddy and Coraza versions; packages
`format` for encoding and `pathnorm`; `make test-caddy-module`, `make lint-caddy-module`) is built into launchroom-caddy
with xcaddy. The image announces it with the label `io.vantezzen.launchroom.trace=1`, and the build's `list-modules` check
requires `http.handlers.lr_trace` and `http.reverse_proxy.selection_policies.lr_round_robin`. launchroomd renders it only
while the running Caddy carries the label (value exactly `1`) and announces the feature `requestTrace` for that time, like
the WAF (docs/22 §6.6).

- **Handler `http.handlers.lr_trace`**, the first, non-terminal route without a matcher on `lr-https` and `lr-http`, so it
  wraps every other route. Config:

  | Key | Rule |
  |---|---|
  | `edge` | this server's `srv_` id |
  | `key_id` | 1–4294967295 |
  | `key_file` | `/data/launchroom/secrets/trace-key`; the module refuses a path that isn't a clean file directly in that dir |
  | `routes{rt_…: {service?, waf?, upstreams?}}` | `service` the route's `sin_` id; `waf` `block` or `log` when the route is firewalled (Coraza's engine mode can't be read from an action, so the render passes it); `upstreams` maps each address upstream's dial to its server's `srv_` id |

  A dial missing from `upstreams` counts as the edge itself, so local (workload and `LR_ALLOWED_LOCAL_UPSTREAMS`) upstreams
  need no entry and every address upstream to another server must have one. The module checks every id by grammar in
  `Provision` and refuses the config on a bad one; launchroomd skips an entry with an invalid id instead. The key is read
  from the file once in `Provision` (so a new key needs a reload, and the key file is part of the files fingerprint) and
  is never logged, quoted in errors or put in the config.
- On a request it sets `X-Request-Id` to a new v4 UUID (overwriting a client's or a proxy's value), wraps the response
  writer and sets the header in the final `WriteHeader` (also on `Write`, `Flush` and `ReadFrom`; 1xx except 101 skipped;
  `Unwrap` kept for WebSockets). When the inner handlers return an error, it sets the header on the server's header map
  before returning it, so the edge's error pages carry it. The access log never sees it (its encoder deletes all response
  headers).
- **Per-route vars** (Caddy's built-in `vars` handler): `lr_route` = the `rt_` id first in every route's subroute and on
  each HTTP redirect route; `lr_stage` = `auth` before forward auth, `app` before the app proxy; `lr_origin` on the pages
  the edge makes: `redirect` (HTTP → HTTPS, www ↔ apex), `stopped`, `unavailable` (the 503 of a route without a ready
  upstream, encoded as origin 3 with failure 3), `refused` (forward auth and client authentication refusals), `no_route`
  (the catch-all 404 on both servers), `edge` (the reachability check). Without `lr_origin`: stage `app` gives app, or an
  upstream failure when the proxy returned an error; stage `auth` gives access refused when forward auth answered, edge
  when it failed; a firewall interruption gives firewall; no stage and no route gives no route; anything else edge.
- **Coraza action `lrtrace`**, registered by the module, runs as `SecAction`s with the reserved ids 99997–99999 in phases 2,
  3 and 5 after the CRS include (generated rules stop at 99996, docs/22 §6.3). It copies the *logged* matched CRS detection
  rule ids (the filter of `daemon/internal/waf`; CRS's `nolog` paranoia-skip rules such as 911013 match on every request
  and don't count), `tx.blocking_inbound_anomaly_score` and the interruption into the request's slot, keyed by the
  transaction id (at most 131,072 slots). Every `waf` handler gets `tx_id_req_header: X-Request-Id`, so the transaction id
  is the request id and the block page reference and `WafEvent.reference` become it too.
- **Selection policy `lr_round_robin`** on the app proxy (not on forward auth or the reachability check): round robin that
  also counts attempts and records each attempt's dial, so the count is known on success too. It clears the proxy's
  latency and status placeholders before each attempt, so forward auth's never count as the app's. Without it
  `upstreamTime`, `upstreamStatus` and `attempts` are left out. A dial error is classified from its message (refused,
  timeout), since `reverseproxy.DialError` doesn't unwrap.
- **Fallbacks** (`caddy.Manager`): launchroomd keeps three configs: the full one, `base` (the trace without the firewall)
  and `fallback` (neither). A refused firewall falls back to `base` (docs/22 §6.6), a refused trace to `fallback`; each
  refused config is remembered by hash and not tried again until something changes. An unusable key is a secret-free
  note, the key file is emptied and the routes keep serving without the handler. The seeded resume config is always the
  plain fallback.
- **Limit**: a firewall block in the response phase is written by Coraza itself before phase 5 runs, so such a trace
  shows the request-phase snapshot. With `SecResponseBodyAccess Off` and CRS 4's outbound evaluation in phase 4 this
  doesn't happen today.

## 4. Keys

- Each edge has its own AES-256 key. The dashboard generates it (`trace_keys`: id = the key id, a random uint32 drawn when
  the key is created and redrawn on a collision (the sealed key is bound to it and the server), server,
  envelope-encrypted key, `created_at`, `retired_at`; rotated with the KEK), seals it to the edge as the IngressSecret
  purpose `trace` (values `key`, `keyId`) in `NodeState.ingress.trace`, and launchroomd writes `key` to
  `/data/launchroom/secrets/trace-key` (0600).
- Rotation every 30 days; retired keys decode for 90 days after their retirement and are then deleted, so a trace stays
  readable for 90 to 120 days. A key retired more than 90 days ago opens nothing even before the hourly run deletes it.
  `App\Actions\Traces\ProvisionTraceKeys` is the only writer; renders read. It runs hourly (`launchroom:traces:keys`) and
  for an edge right before its render (`PrepareNodeStateRender`), so an edge that starts announcing `requestTrace` gets its
  key with the render that follows.
- The key never leaves the dashboard except sealed to its edge.

## 5. Protocol changes

Feature `requestTrace` (launchroomd announces it while the running Caddy has the label). Details in docs/03 §10.

- `NodeState.ingress.trace`: `{sealed}`, an IngressSecret with purpose `trace` and exactly the values `key` (32 bytes,
  standard base64) and `keyId` (decimal 1–4294967295, no leading zeros). Every edge with the feature gets it; storage and
  DNS stay limited to shared edges, so `ingress` can carry only `trace`.
- `RouteSpec.service`: the `sin_` id of the route's service instance (absent for the dashboard's routes).
- `RouteSpec.upstreams[].server`: the `srv_` id of an address upstream's server, only on `{address}` upstreams (launchroomd
  and the dashboard refuse it on `{workload, port}`, which are the edge itself). The dashboard renders it on every address
  upstream of another server, so the trace never reports a remote replica as the edge.

All three are dropped for servers without the feature (`NodeStateField`, `DropField`).

## 6. Dashboard

- **Decode** (`App\Support\Traces\TraceCodec`, `App\Actions\Traces\DecodeTrace`): normalise the input (surrounding
  whitespace and quotes and a pasted `X-Launchroom-Ray:` or `Launchroom-Trace:` header name are stripped; more than 2048
  characters is not a ray; the paste field already picks the ray out of a whole `curl -i` output or a HAR entry), strip
  `lr1.` (a value without a prefix is an older edge's v1; another prefix answers "This ray was made by a newer version
  of launchroom."), parse the envelope, find
  the key by id, check that its server is in the current team and the key not retired more than 90 days ago, decrypt (AES-256-GCM, the
  5-byte AAD), check the payload's `edge` equals the key's server, decode the fields.
- **Access** (`App\Queries\Traces\RequestTraceView`): the edge must be viewable (View). With a service, the instance must
  be in the team and viewable; every such member sees the whole trace, firewall rules included (as with firewall events,
  docs/22). Traces of a route without a service (the dashboard's own routes) need View and ManageServers
  (`TeamPolicy::viewSystemTraces`). A trace without a route (no domain matched) only needs View on the edge. Everything
  else (malformed, unknown or expired key, tampered, another team, not allowed) answers "This ray isn't from one of your
  services.", the same answer as an unreadable ray.
- **Page** `GET /trace?t=<header>` (route `trace`, `throttle:30,1`, Inertia page `trace` with `trace?`, `error?`,
  `input?`): with `t` the server decodes it and passes `RequestTraceData` as props, so a link to a ray can be shared
  with a teammate. Without `t` it shows the paste field ("Paste a ray"); a decoded ray is always titled "Ray" (§7). The server computes the summary sentence, the request class and where
  the path ends (`TraceStop`: edge, firewall, app server, service); the page renders them.
- **Entry points**: a "Paste a ray" button in the log explorer's toolbar (team and project logs, not the service
  panel) that opens a paste popover and goes to the page; "Paste a ray" in ⌘K (Navigate; while searching in its own
  group first), and a pasted ray in ⌘K offers "Open this ray".
- **Actions from the trace**: "Open logs" (the service's logs from 1 minute before to 1 minute after `receivedAt`,
  searching for the request id); for detected or blocked firewall outcomes "Open firewall" and "Allow requests like this"
  (one exclusion per matched rule that can be excepted, for the static prefix of the normalised path and no target, since
  the trace carries none; `AddWafExclusions`, ManageProjects); the edge, app server and service pages; "Copy link" and
  "Paste another ray".

## 7. The trace view

The decoded ray as a picture, not a table (`features/request-trace`, docs/design-system.md §13.9). Every fact sits on
the thing it belongs to; there is no separate details table.

- **Header**: the title is always "Ray" (the page never retitles itself per ray). Under it the request's identity:
  the status pill the visitor got (icon and words, tone by request class), method, domain and path in mono, when it
  arrived (relative, the exact time with milliseconds in a tooltip) and the request id (short, mono, linking to the
  logs filtered by it, with copy). Then the server's summary sentence as a muted lead ("The firewall blocked this
  request. 2 rules matched."). Actions right: Open logs (primary unless the firewall flagged the request), Open
  firewall; Copy link and Paste another ray in the top bar.
- **Canvas**: one card with a dotted recessed canvas holding the path as cards left to right. Each card has its picture
  on a recessed stage, the name with its state, labelled rows (icon, label, value right) and, on the card that sent a
  response, an "Answered" foot with the status pill and why.
  - **Visitor**: Method, Protocol.
  - **Edge server**: device art, name (links to the server), "Edge server" or "Edge and app server", its state, Time
    here (`edgeTime − upstreamTime`).
  - **Firewall** (only when the trace carries one): its own card, "on fra-1", the decision as an explicit mark
    (check "Allowed", ✕ "Blocked", eye "Would block" in log mode), rows Mode, Score, Rules matched (links to the rules).
  - **Service** (or **Dashboard** for launchroom's own routes): logo, name (links), "project / environment", "Runs on"
    with a small device when the app runs on another server than the edge (its state under it when not online), Time
    here (`upstreamTime`). When the edge could not get an answer the card says why instead of "Not reached":
    Refused, No answer, Unhealthy. A stopped service says Stopped. When the app's status differs from what the visitor
    got, its foot shows the app's answer ("The edge sent 502").
  - **No service**: a dashed ghost when no domain matched.
  - Cards after where the request got are dashed and quieter ("Not reached").
- **Where it ended**: the edge answers redirects ("Redirected to HTTPS or the main domain"), refused sign-ins, stopped
  services, unknown addresses and upstream failures ("Couldn't reach web, tried 3 times"); the firewall answers what it
  blocked (and "Blocked web's response" when it refused the app's response).
- **Lines**: solid where the request went, dashed beyond, red dashed with ✕ and the failure where the edge couldn't
  connect (✕ alone when the row is tight). Times are not on the lines: each card says its own ("Time here") and the
  TTFB bar adds them up.
- **Motion**: everything renders in its final state at once (no entrance animation). The only motion is the flying
  dot.
- **Flying dot**: a 5 px dot in the foreground colour at low emphasis travels the lines the request took, 1.6 s per
  line, one at a time, with a rest between runs. It stops halfway into a failed connection, goes back to the card that
  answered when that came earlier (the firewall refusing a response, the edge answering after its firewall), and fades
  where the request ended; a blocking mark (the firewall's ✕, a failed line's ✕) pulses once as it arrives. Web
  Animations on one shared cycle, paused while the tab is hidden, none with reduced motion. This is the one looping
  animation in the dashboard (docs/design-system.md §8).
- **TTFB** in the canvas card's footer: "TTFB" with an info tooltip ("Time to first byte: from the edge receiving the
  request until it sent the response headers."), the total large on the right, one segmented bar (Edge grey, the
  service signal blue) with each segment named with its duration above it ("3 attempts" on the service's when the edge
  retried) and ticks below for Received, Sent to web, First byte. One segment when nothing upstream answered.
- **Rules that matched** (`#trace-rules`): the decision mark and "Score 10 in Block mode." in the header with "Allow
  requests like this" (primary) on the right; each rule a row with its id (mono, muted), the CRS message and its
  category as a chip with an icon.
- Cards side by side while they fit (widths shrink to a minimum, lines keep room for their pills); on phones and narrow
  panes one column with the picture on the left and vertical lines, the dot moving down. An sr-only ordered list
  repeats the path with what each card answered.
