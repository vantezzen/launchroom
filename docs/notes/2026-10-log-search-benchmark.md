# Log search: storage layout and benchmark (2026-10-19)

Question: how should log lines be stored so the log explorer can search across every service and environment of a project (and a whole team) with full text, filters and a time histogram, on the control plane's own Postgres, without a separate search engine?

Answer: keep `log_chunks` (one row per shipped batch, lines as a jsonb array), add the chunk's scope and counts as columns, store each line's guessed level, and put a GIN trigram index on the chunk's joined text. A separate one-row-per-line table was twice the size and not faster.

## Setup

- Postgres 18.6 (the dev `launchroom-dev-postgres-1`, 12 cores, 12 GB shared with the dev stack), scratch database `launchroom_bench_p4e` (dropped afterwards). Warm cache, `EXPLAIN (ANALYZE)` execution times.
- **5,000,024 lines** in **200,000 chunks** (1–49 lines each, 25 on average, like launchroomd's 2 s batches), spread evenly over **7 days**: 10 projects × 3 environments × 4 services on 3 servers, 95 % runtime chunks.
- Line mix per line (random): 40 % access lines with ids and timings, 20 % job lines with an md5 job id, 20 % sign-ins with a hashed user, 20 % cache lines, 3.5 % slow-query warnings, 0.8 % `ECONNREFUSED` errors, 0.7 % upstream-timeout errors (≈ 1.5 % errors). High-cardinality tokens make trigrams realistic.
- Generator and queries: [below](#reproduce).

## Layouts

| Layout | Heap | Indexes | Total |
|---|---|---|---|
| **Chunks** (`log_chunks` + `project_id`, `environment_id`, `service_id`, `runtime`, `line_count`, `error_count`, `bytes`; GIN `gin_trgm_ops` on `lr_log_text(lines)`; `(team_id \| project_id \| environment_id, created_at) INCLUDE (line_count, error_count, service_id, runtime)`) | 524 MB (jsonb, TOAST-compressed) | trigram 218 MB, 3 × 11 MB btree | **791 MB** |
| Lines (one row per line: scope, time, level, stream, text; GIN trigram on `text`; `(team_id \| project_id, at)`) | 846 MB | 764 MB | 1,610 MB |

`lr_log_text(jsonb)` is an `IMMUTABLE` SQL function (`string_agg(e->>'text', E'\n')`), so the index costs no extra stored column; the recheck recomputes it from the row.

## Results

| Query | Chunks, no indexes | **Chunks, indexed** | Lines table |
|---|---|---|---|
| Project, 24 h, `econnrefused`, newest 500 lines | 141 ms | **42 ms** | 94 ms |
| Team, 7 d, a token with no hit | 1,262 ms | **1.6 ms** | — |
| Team, 7 d, frequent term (`timeout`, in ⅙ of the chunks), newest 500 | — | **114 ms** | 142 ms |
| Team, 7 d, histogram of that term (60 buckets, line-level count) | 1,637 ms | **737 ms** | 892 ms |
| Project, 24 h, histogram without text (chunk counts) | 104 ms | **1 ms** (index-only) | — |
| Team, 7 d, histogram without text (all 5 M lines) | 1,223 ms | **57 ms** (index-only) | 1,872 ms |
| Team, 7 d, newest 500 lines, no text | — | **0.4 ms** | — |
| Project, 7 d, `service:… level:error`, newest 500 | 57 ms | 57–92 ms | — |

Ingest cost: building the trigram index over the 5 M lines took 24 s (≈ 5 µs per line); the per-row cost at insert is the trigram extraction of ~25 lines, negligible next to the HTTP request that carries them.

## Decisions

- **Chunks, not lines.** Same or better latency at half the size; no second write path; the deployment log viewer keeps reading chunks unchanged.
- **Histograms from chunk counts** whenever the query has no line-level condition (text, stream, a level other than "errors only"): index-only scans over the covering indexes. Otherwise the matching lines are expanded and counted (worst case measured: 0.74 s for a common word over a whole team's week).
- **Levels are stored** with each line at ingest (`App\Support\Logs\LogLevel`); the migration back-fills older lines with the same rules as Postgres regexes.
- **Guard rails:** every search runs with `statement_timeout = 15 s`, terms are matched as literal substrings (`%`, `_` escaped), at most 16 terms, query ≤ 512 characters, results capped at the newest 5,000 lines (`matches` still counts them all).
- Not chosen: `tsvector` full-text search (word stemming is wrong for log tokens such as `ECONNREFUSED`, `10.0.4.12:5432` or ids; users expect substring matches), partitioning by time (pruning deletes in 5,000-row batches through `(created_at) WHERE runtime`; revisit when installs keep hundreds of millions of lines), an external engine (Loki/Elasticsearch: one more stateful service to run and back up).

## Reproduce

```sql
CREATE EXTENSION IF NOT EXISTS pg_trgm;
-- 200k chunks, 1-49 lines each, 7 days; see the dashboard migration
-- 2026_10_31_100000_add_log_search_and_runtime_retention for the columns.
INSERT INTO log_chunks (team_id, server_id, project_id, environment_id, service_id, deployment_id,
  source_kind, source_id, seq, runtime, lines, created_at)
SELECT 1, 1 + (g % 3), p, p * 3 + (g % 3), p * 4 + (g / 10 % 4), (p * 4 + (g / 10 % 4)) * 10 + (g % 7),
  CASE WHEN g % 20 = 0 THEN 'task' ELSE 'workload' END, 'wl_' || g, g, g % 20 <> 0,
  (SELECT jsonb_agg(jsonb_build_object('time', …, 'stream', …, 'level', …, 'text', CASE WHEN r < 0.008 THEN 'Error: connect ECONNREFUSED …' … END) ORDER BY i)
     FROM generate_series(1, 1 + (g * 7919 % 49)) i, LATERAL (SELECT random() AS r WHERE i IS NOT NULL) rr),
  t
FROM generate_series(1, 200000) g,
  LATERAL (SELECT 1 + (g % 10) AS p, now() - ((200000 - g) * interval '3.024 seconds') AS t) d;
```

The search queries are the ones `App\Queries\LogSearch` builds (`… FROM log_chunks c CROSS JOIN LATERAL jsonb_array_elements(c.lines) WITH ORDINALITY l(e, ord) WHERE c.project_id = ? AND c.created_at >= ? AND lr_log_text(c.lines) ILIKE ? AND l.e->>'text' ILIKE ? ORDER BY c.created_at DESC, c.id DESC, l.ord DESC LIMIT 5000`).
