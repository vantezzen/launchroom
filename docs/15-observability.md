# 15: Observability: request metrics, metrics dashboards, alerts

Builds on the usage metrics (per-minute host and workload samples, rollups 1/10/1440 min, retention 48 h / 30 days / 1 year; [07](07-data-model.md) "Usage metrics", [03](03-daemon-protocol.md) §5 "Usage metrics") and covers:

1. **Request metrics** per service, measured at the edge (§1).
2. **Metrics dashboards**: the service panel's Metrics tab, a project Metrics page and charts on the server page, all with deploy markers (§2).
3. **Alerts and notifications**: built-in rules plus a few thresholds, email / Slack / Discord / signed webhooks, one `RaiseAlert` / `ResolveAlert` API other parts of launchroom call (§3).
4. **Audit log forwarding** to webhooks, syslog and S3 (§4).
5. **Request analytics**: requests per hour by status class, top paths, referrer hosts and client classes per service (§5).

Principles (design-system §1, §9): a chart appears only when it has data; one signal colour per small chart; deltas are coloured only when the direction has a clear meaning; alerts are few, specific and calm ("web failed to deploy. production still serves 3b7d5e1."), and every alert that opens also closes.

## 1. Request metrics

### 1.1 Collection at the edge

Every edge server's Caddy (`lr-system-caddy`) writes **access logs** to launchroomd over loopback; launchroomd aggregates them per route and minute in memory and ships the completed minutes in its reports, like usage metrics. Nothing per request is stored or forwarded.

- **Caddy config** (rendered by `internal/ingress/caddy`): the HTTPS server `lr-https` logs through the logger `lr-access` (`servers.lr-https.logs.default_logger_name`); `logging.logs.lr-access` includes only `http.log.access.lr-access` and writes with Caddy's standard `net` writer to `tcp/127.0.0.1:2022` (`LR_INGRESS_ACCESS_LOG_ADDR`, loopback only, like the ask endpoint) with `soft_start` and a 1 s dial timeout, so Caddy starts and serves while launchroomd is down (lines are then dropped). The `default` log keeps writing Caddy's own JSON log to stderr (certificate events are read from it, [05](05-networking-ingress.md) §5) and **excludes** the access logger, so access lines never land in the container's log file.
- **Data minimisation**: the `filter` encoder deletes `request>headers`, `request>remote_ip`, `request>remote_port`, `request>client_ip`, `request>tls`, `resp_headers` and `user_id` before a line leaves Caddy, and reduces `request>uri` to its path (query string and fragment removed) and the two analytics fields to a referrer host and one user agent token (§5.1). What remains per line: `request.host`, `request.method`, `request.uri` (path only), `status`, `duration`, `size`, `bytes_read`, `ts`, `lr_referer`, `lr_agent`. Client addresses, query strings, cookies, credentials and full user agents are never seen by launchroomd.
- **Plain HTTP** (`lr-http`) only redirects to HTTPS and is not logged. Requests to hosts without a route (the 404 fallback) are dropped.
- **Listener** (`internal/traffic`): accepts only loopback peers, at most 8 concurrent connections, lines ≤ 64 KiB (longer lines are skipped), never blocks Caddy longer than a parse. A process on the host could inject lines; that only skews metrics of routed hosts, so it is accepted (the host is the trust boundary, [09](09-security.md)). If the port is taken, launchroomd logs a warning and runs without request metrics.
- **Attribution**: the line's host (lowercased, port stripped) is looked up in the edge's current route table (every host of a route, including the `www` alias that redirects) → route id. Hosts are unique across routes ([05](05-networking-ingress.md) §4).
- **Aggregation** per (route, minute of arrival, UTC): `requests` (all lines), `status2xx` … `status5xx` (1xx such as WebSocket upgrades count only in `requests`), `bytesIn` (`bytes_read`), `bytesOut` (`size`), and a **latency histogram** of every non-1xx request's `duration` over fixed buckets (ms, upper bound inclusive): `1, 2, 5, 10, 25, 50, 100, 250, 500, 1000, 2500, 5000, 10000, 30000`, plus an overflow bucket: 15 counts. Histograms add up exactly across servers and minutes, so rollups and multi-edge sums keep correct percentiles (interpolated within a bucket).
- **Buffer**: completed minutes ride in every report until one carrying them was accepted (≤ 4096 route-minutes buffered, oldest dropped), exactly like host minutes; a completed minute also kicks a report.

### 1.2 Protocol

Daemon → dashboard (`protocol/CHANGELOG.md` "Request metrics"). `ReportMetrics` carries optional `requests: [RequestMetricSample] (≤ 8192)`:

```jsonc
{ "route": "rt_01J…",            // RouteID of the edge's NodeState
  "at": "2026-10-19T12:01:00Z",  // whole minute, UTC; unique per (route, at)
  "requests": 412,               // ≥ 1
  "status2xx": 380, "status3xx": 20, "status4xx": 9, "status5xx": 3,   // omitted when 0
  "bytesIn": 52113, "bytesOut": 9934120,                              // omitted when 0
  "latencyMs": [0, 4, 61, 120, 140, 60, 15, 5, 2, 1, 0, 0, 0, 0, 0] } // 15 counts; omitted when no non-1xx request
```

Validation (both sides): `latencyMs` has exactly 15 non-negative entries whose sum ≤ `requests`; the status counts sum ≤ `requests`. A minute may arrive twice: stored idempotently per (server, route, minute).

### 1.3 Storage, rollups, retention

`request_metric_samples` ([07](07-data-model.md)): PK (`server_id`, `route_id`, `resolution`, `at`), `service_instance_id`, `project_id` (FKs, cascade), `requests`, `status_2xx`, `status_3xx`, `status_4xx`, `status_5xx`, `bytes_in`, `bytes_out` (bigint), `latency` (`bigint[15]`, nullable); indexes (`service_instance_id`, `resolution`, `at`) and (`project_id`, `resolution`, `at`).

- **Attribution** (`RecordMetrics`): `rt_<ULID>` is a domain route (`dom_<ULID>`) or an immutable deployment URL (`dpl_<ULID>`, [05](05-networking-ingress.md) §5); both resolve to a service instance of the reporting server's team. Unknown routes (the dashboard's own system route, deleted domains with no earlier samples) are dropped; a route whose domain is gone keeps the attribution of its earlier samples.
- **Rollups** (`RollupMetrics`, same schedule and windows as usage metrics): counters and histogram buckets are **summed** per (server, route, bucket). Retention: 1-minute rows 48 h, 10-minute rows 30 days, daily rows 365 days.
- **Derived values** (`App\Support\Metrics\RequestStats`, at read time, after summing all servers and stored rows of a chart bucket): requests/s = requests ÷ bucket seconds (the newest bucket only counts the minutes completed so far, so charts don't dip at the right edge; buckets without traffic are 0); error rate = 5xx ÷ requests (4xx are the client's and shown, not alerted on); p50/p95 = quantile of the summed histogram, linear inside the bucket (the overflow bucket reads as "> 30 s"); bandwidth = bytes out ÷ bucket seconds.

## 2. Metrics dashboards

All charts are the existing SVG components (`TimeSeriesChart`, `StatTile`, `Sparkline`, design-system §13.8), ranges **Hour / Day / Week** (`1h`, `24h`, `7d`; `MetricRange`), refetched on the `server.metrics` broadcast (polling every minute without Reverb). Every time chart of a service, project or server carries **deploy markers** (signal ticks, red for failed deploys; hover names the service, short SHA and outcome).

### 2.1 Endpoints and data

- `GET /p/{project}/-/metrics?environment=&service=&range=` (`projects.metrics`, `ProjectMetricsData`) returns usage series plus:
  - per service (`ServiceUsageSeriesData.requests`, `RequestSeriesData|null`, null without traffic in the range): `requestsPerSecond[]`, `errorRatePct[]`, `p50Ms[]`, `p95Ms[]`, `bytesOutPerSecond[]` aligned with `timestamps`, plus `totals` and `previous` (`RequestTotalsData {requests, status2xx…status5xx, bytesIn, bytesOut, errorRatePct, p50Ms, p95Ms}`; `previous` = the same length of time immediately before the range, for deltas);
  - for the whole environment: `totals: EnvironmentTotalsData {requests: RequestSeriesData|null, cpuCores[], memMb[], cpuAvg, memAvg, previousCpuAvg, previousMemAvg}`;
  - `deploys[]` with `sha` (short) and `serviceName`.
- `GET /servers/{server}/-/metrics` (`ServerMetricsData`) includes `deploys[]` (deployments of services that had replicas on this server in the range) and, for edges, `traffic: RequestSeriesData|null` (everything this edge served).

### 2.2 Service panel → Metrics

Current values first (stat tiles: CPU, memory; with traffic: requests/s, error rate, p95), then one chart each with deploy markers: **Requests** (req/s, signal), **Errors** (5xx share, signal; shown only when there were errors), **Latency** (p50 and p95, palette slots 1–2 with legend values), **CPU**, **Memory** (one line per replica when there are several). Workers and databases have no public traffic: they keep CPU and memory only. Without samples the tab says when metrics will arrive.

### 2.3 Project → Metrics

A project tab **Metrics** (`/p/{project}/{env}/metrics`, page `projects/metrics`, between Logs and Previews), in the Vercel CDN overview spirit:

- Header: "Metrics" + one line ("production · last 24 hours") and the range select.
- **Metric cards** (4 → 2 → 1 columns): Requests, Error rate, p95 latency, Bandwidth; then CPU and Memory. Each card: label, the value for the range in `stat`, a **delta chip** against the previous period ("+12%"; green/red only for error rate and latency, where down is good; neutral otherwise), and up to **three stacked sparklines**, one per top service (logo, name, value), the rest summed into "Other". Selecting a card shows its full chart below.
- **Selected chart**: the environment series of that metric (`TimeSeriesChart`, deploy markers of every service, table view).
- **Services** below: one row per service with its logo, name, req/s and p95 (or CPU and memory for services without traffic) and a sparkline, linking to its panel's Metrics tab.
- Empty: "Traffic appears here a minute after your first request" with the primary domain as a link; usage cards stay.

### 2.4 Server page

**What runs here → Builds** reports the managed BuildKit container's CPU independently of app services, including build subprocesses. The existing collector samples its counter every 15 seconds and reports `Report.metrics.host[].buildCpuCores` as a minute average. The dashboard stores `server_metric_samples.build_cpu_cores` (nullable, included in rollups) and exposes the latest fresh minute as `current.buildCpuCores`; the row shows a share of the server's cores and the core value on hover. Missing measurements and older servers produce no row. This is BuildKit's combined usage, not per-deployment attribution.

Below the usage tiles, a **Metrics** section with full-size charts (CPU %, memory %, disk %, network rx/tx; for edges also **Public traffic**: req/s through this server) over the page's range, with deploy markers of the services that ran here.

## 3. Alerts and notifications

### 3.1 Concepts

| Term | Meaning |
|---|---|
| **Rule** (`App\Enums\AlertRule`) | What can go wrong: built-in, with defaults; teams switch rules off and tune thresholds (`alert_rule_settings`). |
| **Alert** (`alerts`) | One open problem for one subject: (team, rule, subject key) is unique while it is `firing`. It records when it opened, how often it was seen, when it was notified and when it resolved. |
| **Channel** (`alert_channels`) | Where the team hears about alerts: email, Slack, Discord or a generic webhook. Secrets envelope-encrypted. |
| **Delivery** (`alert_deliveries`) | One attempt to tell one channel about one alert event (`fired`, `repeated`, `resolved`, `test`), with its outcome. |

`AlertStatus` (`firing` → `resolved`, `resolved` → `firing` when reopened) owns its transition table; status changes only through `Alert::transitionTo()`.

### 3.2 Raising and resolving alerts

```php
use App\Actions\Alerts\RaiseAlert;
use App\Actions\Alerts\ResolveAlert;
use App\Data\Alerts\AlertSignal;
use App\Enums\AlertRule;
use App\Enums\AlertSeverity;

// Raise (or refresh) an alert. Idempotent: calling it every minute while the
// problem lasts keeps one alert open and notifies once.
resolve(RaiseAlert::class)->handle(new AlertSignal(
    team: $team,                                   // App\Models\Team
    rule: AlertRule::BackupFailed,
    subject: 'backup:'.$instance->public_id,       // stable key of the thing that is broken
    title: 'Backup of postgres failed',            // one line, sentence case, names the object
    summary: 'restic could not reach the bucket. The last good backup is from 3 hours ago.',
    severity: AlertSeverity::Critical,             // optional, defaults to the rule's
    href: route('projects.canvas', …),             // optional: where to look (absolute URL)
    project: $project, environment: $environment,  // optional context (models), shown and used for Activity filters
    serviceInstance: $instance, server: $server,   // optional
    facts: ['Destination' => 'r2-eu', 'Error' => 'timeout'], // optional, ≤ 8 short display strings, never secrets
    occurrence: $run->public_id,                   // optional: a new occurrence of a firing alert (repeat notifications)
));

// Resolve it when the problem is gone (no-op when nothing is firing).
resolve(ResolveAlert::class)->handle($team, AlertRule::BackupFailed, 'backup:'.$instance->public_id, reason: 'The next backup succeeded.');
```

- `RaiseAlert::handle()` returns the `Alert` (or `null` when the team switched the rule off). It runs in its own transaction with a row lock on the firing alert; notifications are queued after commit (`DeliverAlertJob` per channel: tries 3, backoff 30/120/600 s, timeout 30 s, idempotent per delivery row).
- Never put secrets, variable values or log excerpts with user data into `title`, `summary` or `facts`: they go to third-party chat services.
- Callers should call `ResolveAlert` from the code path that observes success (next backup succeeded, server came back). Condition rules are also resolved by the evaluator (§3.4).
- `ResolveAlert::handle(Team|int $team, AlertRule $rule, string $subject, ?string $reason = null, bool $notify = true): ?Alert`. Subject keys in use: `service:<service instance public id>`, `server:<server public id>`, `domain:<domain public id>`; pick `<kind>:<public id>` (grammar `AlertSignal::SUBJECT_PATTERN`).
- Tests: `Tests\Support\Alerts::signal()` / `::channel()` / `::fakeDns()` build signals and channels; deliveries are queued `DeliverAlertJob`s (assert with `Queue::fake()` or the `alerts` / `alert_deliveries` tables).

### 3.3 Notification policy (dedup, cooldown, repeat, resolve)

- **Dedup**: one firing alert per (team, rule, subject). A raise while firing updates `last_seen_at`, `occurrences`, the summary and facts, and notifies nothing, unless the signal carries a new `occurrence` and the rule's **repeat window** has passed since the last notification (`deploy_failed`: 10 min; `backup_failed`: 6 h; condition rules: never repeat).
- **Cooldown** (flap guard, 10 min): a raise within 10 minutes of the same alert's resolution reopens that alert (`resolved` → `firing`) instead of opening a new one, and only notifies if its last notification is older than 10 minutes.
- **Resolve**: resolving sends one "resolved" message to the channels that were told about the alert; alerts that were never notified (rule off, no channels) resolve silently.
- **Channels** receive every alert at or above their minimum severity (`warning` default, or `critical` only). Disabled channels receive nothing. Delivery failures are recorded on the channel (`last_error`) and shown in Settings; they never affect the alert.
- Deliveries are kept 90 days, resolved alerts a year (`launchroom:alerts:prune`, daily).

### 3.4 Built-in rules

| Rule | Default | Subject | Source | Severity | Resolves |
|---|---|---|---|---|---|
| `deploy_failed` | on; persistent environments (previews never alert) | `service:<instance public id>` | `DeploymentStatusChanged` → failed | critical in production, else warning | the instance's next deployment goes live |
| `service_unhealthy` | on | `service:<instance public id>` | evaluator: a replica of the live deployment is `unhealthy`, `exited` or `failed`, or restarted ≥ 3 times in 10 min ("crash-looping"), for ≥ 3 min | critical in production, else warning | every replica healthy and no restart for 3 min |
| `server_offline` | on | `server:<public id>` | `ServerStatusChanged` → offline (no contact for 5 min, [03](03-daemon-protocol.md) §3) | critical | the server is online again |
| `disk_full` | on, 90 % | `server:<public id>` | evaluator: newest disk sample (or host health) | warning; critical ≥ 95 % | 5 points below the threshold |
| `certificate_expiring` | on, 14 days | `domain:<public id>` | evaluator: `domains.cert_expires_at` (Caddy renews at 30 days, so < 14 days means renewal keeps failing; `*.localhost` names, served by Caddy's 12-hour internal CA, never alert) | warning; critical < 3 days or expired | renewed |
| `backup_failed` | on | `backup:`, `backup_verify:`, `backup_overdue:` or `wal_archive:` + the instance's public id | backups via `RaiseAlert` (`ForwardBackupAlerts`, [14](14-backup-and-restore.md)) | critical for failures; warning for overdue backups and WAL lag | `ResolveAlert` on the next success |
| `cpu_high` | on, 90 % for 10 min | `server:<public id>` | evaluator: average `cpu_pct` of the last N 1-minute samples | warning | average below threshold − 10 |
| `memory_high` | on, 90 % for 10 min | `server:<public id>` | evaluator: average memory used ÷ total | warning | average below threshold − 10 |
| `vulnerability` | on | `security:<instance public id>` | a finding becomes "Action needed" ([22](22-security-center.md) §5, §10); a new advisory is a new occurrence (repeat after 60 min) | critical when known exploited or CVSS ≥ 9.0 in production, else warning | no open "Action needed" finding on the instance |
| `waf_unavailable` | on | `server:<public id>` | an edge reports its web firewall in `error` for 5 min while routes use it ([22](22-security-center.md) §6.6) | warning | the firewall is on again |
| `error_rate_high` | on, 5 % for 5 min, ≥ 50 requests | `service:<instance public id>` | evaluator: 5xx ÷ requests over the last N minutes (`request_metric_samples`) | warning; critical ≥ 25 % | below half the threshold |

The evaluator (`App\Actions\Alerts\EvaluateAlertConditions`, `launchroom:alerts:evaluate` every minute, without overlapping) computes the failing subjects of each condition rule per team, raises them and resolves the rule's firing alerts whose subject is no longer failing. It reads only recent rows (≤ 30 min) and is idempotent. Thresholds (CPU %, memory %, error rate %, disk %, certificate days) and durations (minutes) are validated per rule (`AlertRule::thresholdRange()`).

### 3.5 Channels

| Kind | Configuration | Secret (envelope-encrypted) | Message |
|---|---|---|---|
| Email | up to 10 addresses (default: the person setting it up) | none | Laravel mail through the instance's `MAIL_*` settings; a calm plain layout: title, summary, facts, "Open in launchroom". When the instance mailer is `log`/`array`, Settings says email isn't configured on this launchroom yet. |
| Slack | incoming webhook URL (`https://hooks.slack.com/services/…`) | the URL | `{text, blocks}`: a section with the title in bold and the summary, a context line (project · environment · server), a button to launchroom |
| Discord | webhook URL (`https://discord.com/api/webhooks/…`, `discordapp.com` accepted) | the URL | `{content: null, embeds: [{title, description, url, fields, timestamp}]}`, `allowed_mentions: {parse: []}` |
| Webhook | any `https` URL | the URL and a signing secret `lrwh_…` (generated, shown once, rotatable) | the JSON event below, HMAC-signed |

- Every request goes through `SafeHttpClient` (https only, resolved once, private/reserved addresses refused, pinned IP, no redirects, 5 s connect / 15 s total, [09](09-security.md) R5). Slack and Discord URLs must match their host and path grammar. The display hint stored in clear is only host + the last 4 characters (`hooks.slack.com · …x7Qa`).
- Channel secrets use the destination scheme ([09](09-security.md) R13): a per-channel data key wrapped by the KEK (context `alert_channel:<public id>`), values with associated data `alert_channel|<team id>|<public id>|<field>`; `launchroom:keys:rotate-kek` rewraps them. Secrets are `$hidden`, never in props, never logged; the webhook secret is revealed only once in the creation response flash.
- **Webhook event** (`Content-Type: application/json`):

```jsonc
{ "type": "alert.fired",               // alert.fired | alert.repeated | alert.resolved | alert.test
  "id": "alr_01J…", "rule": "deploy_failed", "severity": "critical", "status": "firing",
  "title": "web failed to deploy", "summary": "…", "url": "https://launchroom.example.com/p/…",
  "team": {"id": "team_…", "name": "Acme"},
  "project": {"id": "prj_…", "name": "shop"}, "environment": {"id": "env_…", "name": "production"},
  "service": {"id": "svc_…", "name": "web"}, "server": null,
  "facts": {"Commit": "3b7d5e1"}, "openedAt": "…", "resolvedAt": null, "sentAt": "…" }
```

  Headers: `X-Launchroom-Event` (the type), `X-Launchroom-Delivery` (unique per attempt series, for receiver-side dedup), `X-Launchroom-Signature: t=<unix seconds>,v1=<hex HMAC-SHA256(secret, "<t>.<raw body>")>`. Receivers recompute over the raw body, compare in constant time and reject `t` older than 5 minutes. The body is encoded once and those exact bytes are signed and sent.

### 3.6 UI

- **Settings → Notifications** (`/settings/notifications`, Team group; `alerts.manage` = owner/admin to change, everyone in the team can see):
  - Without channels: one guided card **"Where should we tell you?"** with four tiles (Email, Slack, Discord, Webhook, monochrome icons), then the one field that kind needs (pre-filled email), **Send a test**, and a quiet result line ("Sent. Check #ops" / the error in one sentence). Done in under a minute.
  - With channels: "Where we tell you" rows (icon, name, hint, last delivery "Delivered 2m ago" or the failure, Send test, ⋯ Pause / Edit / Remove behind a confirm popover) and "Add another".
  - **What we tell you about**: one row per rule with a switch and, for thresholds, an inline sentence with small number fields ("CPU above [90] % for [10] min"), saved through the floating `UnsavedBar`.
- **Activity**: alerts appear as entries ("**web** failed to deploy", open or resolved, with the time it was open), filter **Alerts**; the project activity shows the project's alerts.
- Copy: what happened, then the reassurance, then where to look; never "agent"; no exclamation marks.

### 3.7 Permissions

`Permission::ManageAlerts` (`alerts.manage`): owner and admin. `AlertChannelPolicy` (view: team members; create/update/delete/test: `alerts.manage`), `AlertPolicy` (view: team members). Every controller action authorizes; channel ids are team-scoped bindings.

## 4. Audit log forwarding (D50, [09](09-security.md) R34)

Every destination receives the same records, in chain order, from its own cursor (at least once; a receiver dedupes by `id`):

```jsonc
{"type":"event","id":812,"prevHash":"<64 hex>","hash":"<64 hex>",
 "fields":{"team_id":3,"actor_type":"user","actor_id":7,"action":"server.revoked","subject_type":"server","subject_id":"srv_…",
           "ip":"203.0.113.9","user_agent":"…","meta":{…},"created_at":"2026-10-19T12:00:00.123456Z"}}
{"type":"checkpoint","id":40,"eventId":812,"count":812,"hash":"<64 hex>","createdAt":"…Z","instance":"ins_…","kid":"cp-2026-10","signature":"<base64>"}
```

`fields` are exactly the hashed fields, so `sha256(prevHash ‖ "\n" ‖ canonical_json(fields)) = hash` can be checked anywhere, and a checkpoint with the control-plane public key (protocol/SIGNING.md §7; shown in Settings → Security and by `launchroom:audit:verify --public-key`). A checkpoint travels in the batch that contains its entry.

- **Webhook**: `POST` of `{"type":"launchroom.audit.batch","version":1,"instance","sink","batch":"<first>-<last>","records":[…]}` (≤ 200 records), headers `X-Launchroom-Event: audit.batch`, `X-Launchroom-Delivery: <sink>:<first>-<last>` (stable across retries), `X-Launchroom-Signature: t=<unix>,v1=<hex HMAC-SHA256(secret, "<t>.<raw body>")>` like alert webhooks (§3.5). Any 2xx is a delivery. "Send test" posts `launchroom.audit.test`.
- **Syslog over TLS**: RFC 5425 octet counting, one RFC 5424 message per record: `<110>1 <entry time> <dashboard host> launchroom - audit|checkpoint - <record JSON>` (facility 13 log audit, severity informational). The peer is verified against the system roots or the pasted CA for the entered host name (SNI); the TCP connection goes to the address UrlGuard vetted. No acknowledgements: written bytes count as delivered.
- **S3**: one object per batch (≤ 1000 records; a smaller batch waits until its oldest entry is 5 minutes old), `<prefix>/launchroom-audit/<instance>/<yyyy>/<mm>/<dd>/<first id>-<last id>.jsonl` (12-digit ids, JSON Lines), `PUT` with `If-None-Match: *` (never overwritten; a 412 for an earlier attempt's object counts as delivered), SigV4, path-style. With Object Lock on the bucket (detected by "Send test": `GET ?object-lock`) and a lock period chosen, each object carries `x-amz-object-lock-mode` (GOVERNANCE/COMPLIANCE), `x-amz-object-lock-retain-until-date` and `x-amz-checksum-sha256` (S3's required integrity checksum; Content-MD5 isn't used). Without Object Lock, the keys you give can delete objects: use keys without `s3:DeleteObject`.
- Delivery runs right after every audit entry commits (`DeliverAuditSinkJob`, one per sink at a time) and every minute (`launchroom:audit:deliver`) for retries and backlog: a failure keeps the cursor, records a safe message and waits 30 s × 2ⁿ (≤ 1 h); "Retry now" and resuming skip the wait.

## 5. Request analytics (D62)

A basic overview of how many requests each service serves and of what kind: requests per hour by status class, the most requested paths, referrer hosts and a coarse client class. It is not a replacement for a web analytics product: no visitors, sessions, pages per visit or locations.

### 5.1 Collection and privacy

The edge aggregates everything; nothing per request is stored or forwarded.

- **Request counts** are the request metrics of §1: launchroomd already sends per (route, minute, server) counts by status class. Analytics reads them; it adds no second count.
- **Caddy** (`internal/ingress/caddy`): the first route of `lr-https` is a non-terminal `log_append` route without a matcher that adds `lr_referer` = `{http.request.header.Referer}` and `lr_agent` = `{http.request.header.User-Agent}` to the access line. The `filter` encoder then reduces them inside Caddy with `regexp` filters (Go's regexp, tested with the same patterns in `accesslog_test.go`):
  - `request>uri`: `(?s)[?#].*$` → empty, so the query string and fragment never leave Caddy;
  - `lr_referer`: only the host of the referrer URL (scheme, credentials, port, path and query removed);
  - `lr_agent`: a bot keyword (`bot`, `crawl`, `spider`, `curl`, `python`, `headless`, …) if the user agent contains one, else `Mozilla` if it starts with it, else empty. The rest of the user agent never leaves Caddy.
- **launchroomd** (`internal/traffic`, `analytics.go`, `normalize.go`) folds each line into the open **10-minute window** of its route:
  - **Path normalisation**: the query string and fragment are cut again; segments that are numbers, UUIDs, hex hashes of ≥ 16 characters or random-looking tokens (≥ 16 characters of letters, digits, `-`, `_`, with digits and letters and no run of five lowercase letters) become `:id`; `123.json` becomes `:id.json`; segments over 64 bytes or with characters outside printable ASCII become `:id`; the path is cut at a segment boundary to ≤ 128 bytes and ends with `/...` when cut. URIs that are not a path (`*` of `OPTIONS`) count as "other".
  - **Per path**: requests, 4xx and 5xx.
  - **Referrer hosts**: valid hostnames only. A referrer that is one of the route's own hosts is navigation inside the site and is not counted; another launchroom route counts.
  - **Client class**: browsers (`Mozilla` without a bot keyword), bots and crawlers, other (no user agent or anything else).
- **Cardinality**: while a window is open, at most 1,000 distinct paths and 200 referrer hosts per route and 20,000 keys per edge are tracked; requests with a new key after that count as "other". When the window completes, the top 50 paths and 20 referrers are reported and the rest summed into `otherPaths` / `otherReferrers`. Completed windows ride in every report until one carrying them was accepted (≤ 1,024 buffered, oldest dropped). A window open while launchroomd restarts is lost; request counts are not affected.
- **Never stored**: client addresses, query strings, full URLs, full user agents, cookies, headers. Paths are what the application exposes; an application that puts a secret into a path segment (not a query string) and does not look like an id could show it in the top paths, so the docs advise against secrets in paths.

### 5.2 Protocol

`ReportMetrics.analytics: [RequestAnalyticsSample] (≤ 1024)`, daemon feature `requestAnalytics` (`protocol/CHANGELOG.md` "Request analytics"):

```jsonc
{ "route": "rt_01J…", "at": "2026-10-17T12:00:00Z",   // window start, a multiple of 10 minutes, UTC
  "requests": 412,                                      // ≥ 1
  "paths": [{ "path": "/products/:id", "requests": 130, "status4xx": 6 }],   // ≤ 50, unique
  "otherPaths": { "requests": 22, "status4xx": 3 },
  "referrers": [{ "host": "news.ycombinator.com", "requests": 51 }],       // ≤ 20, unique
  "otherReferrers": 4,
  "browsers": 370, "bots": 35, "otherAgents": 7 }
```

Validation on both sides: `RequestPath` is `/` plus printable ASCII without `?` and `#`, ≤ 128 bytes; hosts follow the `Hostname` grammar; paths plus `otherPaths` add up to `requests`; referrers and the client classes don't exceed it; 4xx + 5xx of a path don't exceed its requests. The dashboard (`MetricSamples::analytics`) drops an item that doesn't fit instead of failing the report, accepts windows up to 24 hours old and stores them idempotently per (server, route, window). The feature is only used to tell users that an older edge sends no paths (`serversWithoutPaths`).

### 5.3 Storage, rollups, retention

`request_analytics_samples` ([07](07-data-model.md)): PK (`server_id`, `route_id`, `resolution`, `at`), `service_instance_id`, `project_id`, `requests`, `paths` (jsonb `[{p, r, e4, e5}]`), `other_requests`, `other_4xx`, `other_5xx`, `referrers` (jsonb `[{h, r}]`), `other_referrers`, `browsers`, `bots`, `other_agents`; attributed like request metrics (§1.3).

- **Rollups** (`RollupRequestAnalytics`, run by `launchroom:metrics:rollup` every 10 minutes): 10-minute rows → hourly rows (recomputed for the last 3 hours) → daily rows (recomputed for the last 3 days), counts summed, the merged lists cut to the top 50 paths and 20 referrers with the rest folded into `other_*`.
- **Retention**: 10-minute rows 48 hours, hourly rows `launchroom.analytics.hourly_retention_days` (`LAUNCHROOM_ANALYTICS_HOURLY_RETENTION_DAYS`, default 7), daily rows `daily_retention_days` (`LAUNCHROOM_ANALYTICS_DAILY_RETENTION_DAYS`, default 90). Request counts keep the request metrics' retention (§1.3).
- Volume: at most one row per route, server and window, each ≤ 50 + 20 list entries.

### 5.4 Reads, UI and API

`App\Queries\RequestAnalytics` and `TeamAnalytics` read each resolution where it is complete (`App\Queries\AnalyticsTiers`): the finest rows (1-minute counts, 10-minute analytics) for the last two hours, 10-minute counts and hourly analytics before that, and for daily buckets daily rows before yesterday. Ranges (`AnalyticsRange`): **Day** (`24h`, hourly), **Week** (`7d`, hourly, default), **Month** (`30d`, daily). The result (`AnalyticsData`): per-bucket counts per status class (`status2xx` … `status5xx`, `statusOther` for 1xx), totals and the previous period's requests and error rate, the split by server and by service, the top 25 paths with 4xx/5xx counts, the top 10 referrer hosts, client classes and `serversWithoutPaths`.

- **Analytics in the sidebar** (`/analytics`, page `analytics`, `TeamAnalyticsController`; data from `GET /analytics/-/data?range=`, `TeamAnalytics` → `TeamAnalyticsData`): every project of the current team, all environments, for members who may view the team's projects (`ProjectPolicy::viewAny`). Header with the range select; stat tiles (requests with the change, 5xx share with a coloured delta, 4xx share); **Requests per hour by project** as `StackedBars`: the four busiest projects in their palette slot (a slot already used in the chart moves to the next free one), the rest summed into a grey "Other (n)", with the legend, readout and table view naming every series; **Projects**: one row per project with traffic (colour swatch matching the chart, requests, share, change, error rate, sparkline) linking to its production Analytics tab; **Top paths** across projects (project name and path) and **Servers** when more than one edge served traffic (grouped by server identity; a revoked server keeps its own row marked "(removed)", `AnalyticsSplitData.removed`, since a new server may have taken its name). Empty state as on the project page. The query is a fixed number of grouped queries (counts per project, server and bucket; the previous period per project; top paths per project and path; covered traffic), scoped by `project_id IN (team's projects)` on the (`project_id`, `resolution`, `at`) indexes, with the same tiers as below (`AnalyticsTiers`).
- **Drill-down**: Analytics → project (its Analytics tab, `/p/{project}/{env}/analytics`) → service (the same page with `?service=svc_…`; the select and the Services list keep the URL in step, the URL wins on load). The project page starts with breadcrumbs "All projects / <project> / <service>"; the project crumb clears the service filter. The project top bar's Analytics tab opens the same view.
- **Project → Analytics** (`/p/{project}/{env}/analytics`, page `projects/analytics`, tab after Metrics; data from `GET /p/{project}/-/analytics?environment=&service=&range=`): header with a service select (all services or one; `?service=` preselects) and the range select; three stat tiles (requests with the change against the previous period, server errors as a 5xx share with a coloured delta, client errors as a 4xx share); **Requests per hour** (per day for a month) as `StackedBars` by status class: 2xx signal, 3xx grey, 4xx amber, 5xx red and hatched, legend with totals, readout per class and a table view; **Top paths** (path, requests and share as a bar behind the row, 4xx and 5xx share; "Other paths" last); **Referrers** and **Clients** with shares; **Servers** when more than one edge served traffic; **Services** when all services are shown and more than one had traffic (selecting one filters). Empty: "No requests in this range yet…"; paths not there yet: "…appear here within 10 minutes", or "Update <server> to see the most requested paths" for older edges.
- **Projects list**: each project card shows production's requests per hour of the last 24 hours as a sparkline with the total ("1.2K req · 24h"), only when there was traffic.
- **API** (ability `read`): `GET /api/v1/analytics?range=` returns the team overview (`TeamAnalyticsData`) of the token's team; `GET /api/v1/environments/{environment}/analytics?range=` and `GET /api/v1/environments/{environment}/services/{service}/analytics?range=` return `AnalyticsData` (tag "Analytics" in the OpenAPI document).
