# 22: Security center

The **Security** page shows what runs in every project, which published vulnerabilities affect it, what the web firewall stops, and helps investigate a service when a vulnerability matters. Decisions **D63** (security center) and **D64** (no central patch channel) in the [decision log](README.md). Security rules: [09](09-security.md) R40, R41.

Parts:

1. **Inventory** (§3): the packages and runtimes of every running image, read from an SBOM of the image and the build's lockfiles.
2. **Advisories** (§4): public vulnerability data from OSV, CISA's Known Exploited Vulnerabilities catalogue and endoflife.date, pulled and matched by each dashboard.
3. **Alert levels** (§5): "Action needed" (notifies) and "Worth knowing" (listed quietly), with exact rules.
4. **Web firewall** (§6): Coraza with the OWASP Core Rule Set in launchroom's Caddy, per service Off, Log only or Block.
5. **Investigation** (§7): container changes and processes from the server, firewall and request history, and what the service can reach, combined into a report with next steps.

## 1. Goals and non-goals

Goals:

- Answer "do we run X version Y?" for every project in one search.
- Alert only when a user has to act. An alert that turns out to be irrelevant costs more trust than a missed "Worth knowing" entry.
- Block common attacks at the edge without a rule list launchroom maintains.
- After an alert, help answer "was it used against us?" with the data launchroom already has, and say plainly what that data can't prove.
- Show the protections through their results (counts, charts, timestamps), not explanations.

Non-goals (this round):

- A launchroom-run advisory, rule or patch feed (D64). Each instance pulls public data and decides locally.
- Automatic remediation. Every action is a button a person presses.
- Per-service outbound network rules and detecting a shell spawned in a container at runtime (§15).
- Scanning launchroom's own images. They are covered by signed releases and Settings → Updates ([09](09-security.md) R30, R31).
- License compliance, secret scanning of images, malware signatures.
- Raw SBOM downloads (§15).

## 2. Overview

```
build server (BuildKit)                    dashboard                                   edge server
──────────────────────                     ─────────                                   ───────────
inventory task: clone (lockfiles),         components per image  ──▶ OSV querybatch      Caddy + Coraza + CRS
scan the image with the pinned  ─────────▶ advisories (cached)   ◀── KEV, endoflife.date  │ audit records (loopback)
SBOM scanner, normalise                    findings + levels ──▶ alerts, Security page   ▼
                                           investigation report ◀── inspect_container    launchroomd aggregates
                                           firewall samples      ◀───────────────────────  Report.metrics.waf
```

Everything that leaves a server is typed protocol data ([03](03-daemon-protocol.md) §13). Advisory feeds are data only: no feed can make launchroom run code or change a setting.

## 3. Inventory

### 3.1 What is inventoried, and when

- Every image of a **live deployment** in a non-preview environment: built apps (`127.0.0.1:5000/apps/…`), image services and database engines (`127.0.0.1:5000/mirror/…`). Preview environments are not inventoried (they are short-lived and would multiply scan work).
- An inventory belongs to a **team and an image digest and platform** (`image_inventories`). Two services of the same team running the same image share one inventory; teams never share one (§13).
- Triggers:
  - a build succeeds: the dashboard creates an `inventory` task with the build's source (repository, commit, root dir) and the pushed image, preferring the server that built it (its BuildKit has the layers);
  - a deployment goes live with an image that has no inventory in its team (image services, engines, promoted images): an `inventory` task without source;
  - backfill after the update that brings this feature: one task per live image without an inventory, created by `launchroom:security:backfill` (runs once from the update flow and hourly until nothing is missing), at most 2 pending per build server.
- Inventory never blocks a deployment. A task that failed all its attempts is created again twice (after 10 min and 1 h), then the image shows "Couldn't read the packages of this image" with the reason.

### 3.2 The inventory task

Daemon task `inventory` (feature `inventory`; leased by build-role servers whose platform matches `spec.platform`, like builds; deadline 15 min; 3 attempts). Spec and result: [03](03-daemon-protocol.md) §13.1.

launchroomd:

1. **Source analysis** (only with `spec.source`): a shallow clone at the commit with the build's clone code and checkout rules ([09](09-security.md) R4: symlink containment, `os.Root` reads). It reads only manifests and lockfiles (table in §3.3) in `rootDir`, then in each parent directory up to the repository root (workspace lockfiles). No install, no script, no network besides the clone.
2. **Image scan**: one BuildKit solve built from LLB by launchroomd (no Dockerfile, no frontend): the image (`spec.image`, pulled through the registry with a pull token for this task, [03](03-daemon-protocol.md) §4.5) mounted read-only into an exec of the SBOM scanner image already pinned for build attestations (`build.SBOMGenerator`, `docker/buildkit-syft-scanner`, Syft, Apache-2.0), network disabled, output directory exported to launchroomd with the local exporter. The scanner follows BuildKit's SBOM scanner protocol (`BUILDKIT_SCAN_SOURCE`, `BUILDKIT_SCAN_DESTINATION`) and writes SPDX 2.3 JSON.
3. **Framework probe** (Next.js only, when the image contains `.next/server`): the same solve also exports the file names and module references of `.next/server/**/*.js` and `.next/static/chunks/**/*.js` (at most 64 MiB read, then stopped and noted). launchroomd searches them for `node_modules/<name>/` and `node_modules/@<scope>/<name>/` references (§3.3).
4. **Normalise** (§3.4) and complete the task with `TaskCompletion.inventory`. The SPDX document and the exported files are deleted with the task's work directory. Nothing else is kept on the server.

Why this way:

- The scanner is the one every build already pulls and runs in BuildKit's sandbox, so there is no new image to pin, no new container shape in dockerd-guard and no database to download onto servers.
- Running Syft inside launchroomd would put an image parser with a large dependency tree into the server's trusted process. A scanner container with host access (Trivy, Grype with the Docker socket) is ruled out by [09](09-security.md) §6.
- Grype and Trivy would also match vulnerabilities on the server from a database they download from their own feeds; matching belongs on the dashboard, from public data it chose (§4).

Limits: SPDX output ≤ 64 MiB (larger: the task fails with "Image is too large to inventory"), ≤ 20,000 components per result (more: the first 20,000 in the order of §3.4 and `truncated: true`), and the encoded completion under 12 MiB (launchroomd truncates further when needed; the protocol's document limit is 16 MiB).

### 3.3 Dependency scope

Scope says whether the component's code can run on the server when the app serves requests. Values: `runtime`, `dev`, `client`, `unknown`. **Only positive evidence sets `runtime`, `dev` or `client`**; without it the scope is `unknown`, and `unknown` never raises "Action needed" except for known exploited vulnerabilities (§5.2).

Presence says how the component is in the image: `installed` (the scanner found it as a package, binary or library in the image) or `bundled` (a production dependency in the lockfile that the scanner didn't find installed; its code may be compiled into the app's bundles, or used only at build time).

| Ecosystem | Evidence | Scope |
|---|---|---|
| npm (`package-lock.json` v2/v3, `npm-shrinkwrap.json`, `pnpm-lock.yaml` v6–v9, `yarn.lock` v1 and Berry, `bun.lock`) | Lockfile graph walked from the root manifest's (or the workspace importer's) `dependencies`, `optionalDependencies` and `peerDependencies`; npm's `dev: true` flags where present | Reachable only from `devDependencies`: `dev`. Installed and reachable from a production root: `runtime`. Bundled: `runtime` when the Next.js probe found it in `.next/server`, `client` when found only in `.next/static`, or when the image has no JavaScript runtime at all (no node, bun or deno binary and no `nodejs` OS package: a static site); otherwise `unknown` |
| PyPI (`poetry.lock`, `uv.lock`, `Pipfile.lock`, `requirements*.txt`, `pyproject.toml`) | Installed packages from the image; dev groups (`[tool.poetry.group.<dev\|test>]`, `[dependency-groups] dev`, `uv.lock` dev groups, `Pipfile.lock` `develop`, a requirements file named `*dev*`/`*test*`) | Installed and only in a dev group: `dev`. Installed otherwise: `runtime` |
| Packagist (`composer.lock`) | `packages` vs `packages-dev` | In `packages-dev` only: `dev`. Installed otherwise: `runtime` |
| Go | Module list compiled into each binary (Go build info, read by the scanner); Go `stdlib` as the toolchain version | `runtime` (only linked code is listed) |
| RubyGems (`Gemfile.lock`, `Gemfile`) | Installed gems; groups from `Gemfile` (`:development`, `:test`) for direct gems | Direct and only in dev/test groups: `dev`. Installed otherwise: `runtime` |
| Maven, NuGet, crates.io, Hex, Pub | Installed artefacts found by the scanner | `runtime` |
| OS packages (deb, apk, rpm) | The image's package database | `runtime` (OS packages are judged by the exposed list, §5.3) |
| Runtimes found as binaries (node, python, php, ruby, java, nginx, httpd, caddy, …) | The scanner's binary classifiers | `runtime` |

- `direct`: the package is named in the app's manifest (npm, PyPI, Composer, Gemfile; Go modules not marked `// indirect`).
- Without `spec.source` (images launchroom didn't build, and built images whose clone failed) language packages are `unknown`, except Go modules, runtimes and OS packages, which keep their row in the table above.
- Workspaces and monorepos: the importer whose path equals `rootDir` (pnpm, yarn, bun, npm workspaces); with no match the root manifest's graph.
- What can't be determined, said in the UI where it applies: server code that Next.js (or another bundler) compiled into its own files is found only through module references in the output; when the build doesn't keep them, bundled packages stay `unknown`. A package used both in the browser and on the server counts as `runtime`. Dynamic `require` of a package by a computed name isn't seen.

### 3.4 Normalised component

`InventoryComponent` ([03](03-daemon-protocol.md) §13.1):

| Field | Meaning |
|---|---|
| `ecosystem` | `npm`, `pypi`, `go`, `maven`, `nuget`, `cargo`, `rubygems`, `packagist`, `hex`, `pub`, `deb`, `apk`, `rpm`, `binary` |
| `name`, `version` | As the ecosystem writes them (npm `@scope/name`, Maven `group:artifact`, Debian binary package name) |
| `purl` | Package URL from the scanner, with distro and upstream qualifiers (`pkg:deb/debian/libssl3@3.0.15-1~deb12u1?arch=amd64&distro=debian-12&upstream=openssl`) |
| `kind` | `os` (distro package), `library` (language package), `runtime` (language runtime or server program found as a binary or OS package of the exposed list, §5.3) |
| `scope`, `presence`, `direct` | §3.3 |

Order (used for truncation and display): runtimes, direct libraries, other `runtime` libraries, `unknown`, `client`, `dev`, OS packages. Components whose name, version or purl fail their grammar are dropped and counted in `dropped`. Duplicates (same ecosystem, name and version in several locations) are merged.

The result also carries the image's `distro` (`/etc/os-release` `ID` and `VERSION_ID`), the analysed `lockfiles` (path, kind, package count) and up to 16 `notes` ("No lockfile found, so npm packages have an unknown scope").

### 3.5 Storage and size

- The dashboard stores components only (`inventory_components`, §8). A Node app is typically 800–2,000 components, a Debian-based image adds 100–250 OS packages; a row is about 150 bytes plus indexes.
- The SPDX document is not stored by the dashboard. Built images keep their SBOM attestation in the registry for as long as the image is retained ([04](04-build-and-deploy.md) §8).
- Inventories no deployment references any more are deleted with the registry's retention: an inventory is kept while a retained deployment (the last 10 per service, rollback targets included) uses its image, then pruned daily (`launchroom:security:prune`).

### 3.6 Images launchroom didn't build

Image services and database engines get the same task without `spec.source`: OS packages, binaries and the language packages installed in the image, with scope `unknown` for language packages. Their maintainers ship fixes as new image versions, so for these images only known exploited vulnerabilities raise "Action needed" (§5.2). Engines and runtimes also feed version tracking (§4.6).

## 4. Advisory data

### 4.1 Sources

| Source | What | Endpoint | Licence |
|---|---|---|---|
| **OSV** | Vulnerabilities per package and version, for every ecosystem in §3.4, including GitHub's advisory database (GHSA), PyPA, Go, RustSec, Debian, Ubuntu, Alpine, Red Hat and Bitnami's database of upstream runtimes and servers | `POST https://api.osv.dev/v1/querybatch`, `GET https://api.osv.dev/v1/vulns/{id}` | CC-BY 4.0 / per source, attribution shown on the page footer and modal |
| **CISA KEV** | CVEs known to be exploited, with the date added | `GET https://www.cisa.gov/sites/default/files/feeds/known_exploited_vulnerabilities.json` | Public domain (US government work) |
| **endoflife.date** | Release cycles, latest patch and end-of-life dates of runtimes, engines and distributions | `GET https://endoflife.date/api/v1/products/{product}` | MIT |

- Every request goes through `SafeHttpClient` ([09](09-security.md) R5): https only, the fixed hosts above (no URL comes from data or users), resolved once and pinned, no redirects, 10 s connect, 30 s total, response caps (querybatch 8 MiB, one vulnerability 1 MiB, KEV 16 MiB, endoflife 1 MiB).
- **GitHub's advisory API is not used.** OSV already imports every reviewed GHSA advisory with its severity label (`database_specific.severity`) and CWE ids, so the API adds no data we need, while it adds a second rate limit (60 requests per hour without a token) and a token to manage.
- **EPSS** (exploit prediction scores) was considered and left out: KEV is the clearer signal for "act now", and a probability score invites thresholds nobody can justify to a user.

### 4.2 What leaves the instance

- OSV receives the package queries: ecosystem, name and version of every inventoried component (OS packages by their source package). No project, service, team, host or user names. osv.dev sees the dashboard's public address.
- CISA and endoflife.date receive plain GETs of public files.
- The modal (§9.7) and the docs say this. An instance owner can switch each source off in Settings → Security (§9.8); with OSV off there are no vulnerability findings, only inventory and versions.

### 4.3 Sync

| What | When |
|---|---|
| OSV, components of a new inventory | when the inventory arrives (`CheckInventoryAdvisories`, queued) |
| OSV, every component of every live inventory | every 6 hours (`launchroom:security:sync`), jittered by up to 30 min |
| OSV vulnerability records | on first sight of an id and when querybatch reports a newer `modified` |
| KEV | daily, plus on start when older than 24 h; conditional GET (`If-None-Match`/`If-Modified-Since`) |
| endoflife.date | daily, one request per product in use |
| "Check now" (Security page, owners and admins) | at most once per 10 minutes per instance; runs the 6-hourly OSV sync and KEV |

- **Query cache** (`advisory_queries`): one row per (OSV ecosystem, name, version) with the matching vulnerability ids and `checked_at`. Components shared by many services and teams are asked once. A row younger than 6 hours is not asked again, except by "Check now".
- **Batching**: up to 1,000 queries per querybatch request, one request at a time; result pages (`next_page_token`) followed. Vulnerability records hydrate with 4 requests in parallel at most.
- **Rate limits and errors**: a 429 or 5xx backs off 30 s doubling to 30 min (honouring `Retry-After`), then the sync resumes where it stopped (cursor in `advisory_feeds`). A 4xx other than 429 on one query marks that query failed and moves on.
- **Records** (`advisories`) are public data and instance-wide. Fields kept: id, aliases, upstream ids, modified, published, withdrawn, summary (≤ 512 characters), details (≤ 16 KiB), severity label, CVSS vectors and computed scores, CWE ids, affected ranges of the matched packages, references (≤ 20, https only). Records not matched by any query for 30 days are deleted.

### 4.4 Offline and failure behaviour

- Without a successful sync, findings stay as they were: nothing is raised or resolved because data is missing.
- `advisory_feeds` records per source: last success, last attempt, last error, item count. The Security page header shows "Checked 3 hours ago"; after 24 hours without success it says "Can't reach osv.dev since yesterday" (amber, with the error in the tooltip); after 3 days a "Worth knowing" entry names the failing source.
- An air-gapped instance turns the sources off. Inventory and the web firewall work without them.
- A feed answer that fails parsing or its grammar is dropped whole (KEV, endoflife) or per record (OSV), logged, and the previous data stays.

### 4.5 Matching

- **Ecosystem mapping** (dashboard, `App\Support\Security\OsvPackage`): npm → `npm`, pypi → `PyPI`, go → `Go`, maven → `Maven`, nuget → `NuGet`, cargo → `crates.io`, rubygems → `RubyGems`, packagist → `Packagist`, hex → `Hex`, pub → `Pub`; deb → `Debian:<major>` or `Ubuntu:<version>[:LTS]` from the purl's `distro`, querying the source package (`upstream` qualifier, else the name); apk → `Alpine:v<major>.<minor>` with the origin package; rpm → the distro's ecosystem when OSV has one (`Red Hat`, `Rocky Linux`, `AlmaLinux`), else not queried; binaries → `Bitnami` with a fixed name table (node, python, php, ruby, nginx, apache, postgresql, mysql, mariadb, redis, mongodb) and Go's toolchain as `Go`/`stdlib`. A component without a mapping is inventoried but not queried, and the inventory says how many.
- OSV decides whether a version is affected (querybatch matches server-side with each ecosystem's version rules). The dashboard re-checks only what it needs for display: the `fixed` events of the matched package.
- **Advisory groups**: ids that share an alias are one advisory (GHSA-… and CVE-… and PYSEC-… for the same issue). The group's display id is the GHSA id, else the CVE, else the first id. Findings are per group.
- **Severity** (first that applies): GHSA label (`database_specific.severity`: LOW, MODERATE, HIGH, CRITICAL); else the highest CVSS v3.x base score in `severity` (computed from the vector by `App\Support\Security\Cvss3`); else unknown. CVSS v4 vectors are stored and shown, not scored, in this round. The attack vector (`AV`) comes from the CVSS vector when there is one.
- **Fixed**: the matched package's affected entry has at least one `fixed` event. The display picks the smallest fixed version greater than the installed one where the ecosystem's comparator is available (npm, Go, Packagist via `composer/semver`), else lists the fixed versions.
- **Known exploited**: any CVE id among the group's ids, aliases and upstream ids is in KEV.
- **Withdrawn** records match nothing.

### 4.6 Version tracking

From endoflife.date, for runtimes and engines in use (product table `App\Support\Security\LifecycleProducts`: nodejs, python, php, ruby, go, eclipse-temurin, dotnet, nginx, postgresql, mysql, mariadb, redis, valkey, mongodb, clickhouse, debian, ubuntu, alpine-linux): the cycle in use, its latest patch and its end of life. Database engines come from the service settings (engine and version); runtimes from inventories; distributions from `distro`. Runtimes that are OS packages are matched and shown by their upstream version (`LifecycleProducts::upstreamVersion`: no epoch, distribution revision or `+`/`~` suffix, so `nginx 1.31.8-1~trixie` is `1.31.8`, cycle `1.31`); the package versions stay on the components and in `RuntimeCardData.packageVersions`. Version facts are "Worth knowing" only:

- the cycle reached its end of life ("Node.js 20 reached its end of life on 30 April 2026");
- a newer patch of the same cycle has existed for more than 30 days.

## 5. Alert levels

Two levels. "Action needed" notifies and leads the page. "Worth knowing" is listed, grouped, and never notifies. When the inputs leave doubt, the finding is "Worth knowing".

### 5.1 Inputs

| Input | From |
|---|---|
| The finding's component: ecosystem, name, version, kind, scope, presence, direct | `inventory_components` of the image of the live deployment |
| Image origin: built by launchroom or not | `image_inventories.source` |
| Environment: preview or not | `environments.kind` |
| Public: the service instance has a domain route without preview protection | `domains` and the environment's protection setting |
| Severity label or CVSS score, attack vector | `advisories` (§4.5) |
| Fixed version exists | `advisories.affected` |
| Known exploited | KEV (§4.1) |
| Dismissed by a user | `security_findings.status` |

### 5.2 "Action needed"

A finding is "Action needed" when **every** rule holds:

| # | Rule | Data |
|---|---|---|
| A1 | The component is in the image of the **live deployment** of a service instance in a **non-preview** environment. | deployment status, environment kind |
| A2 | The component may run when the service handles requests: | inventory |
| | a) image built by launchroom: `scope` is `runtime`; | |
| | b) OS packages: only names of the exposed list (§5.3); | |
| | c) images launchroom didn't build, and components with `scope` `unknown`: only when known exploited. | |
| A3 | Severity is **high or critical** (label HIGH/CRITICAL, or CVSS ≥ 7.0), **or** known exploited. | advisory |
| A4 | A **fixed version exists**, **or** known exploited. | advisory |
| A5 | It can be reached over the network: the CVSS attack vector is `N` (network) or `A` (adjacent), or there is no vector; **or** known exploited. | advisory |
| A6 | The service is **public** (§5.1); **or** known exploited. A service without a public domain (workers, databases, internal APIs) gets "Worth knowing" with the reason "No public domain". | domains |
| A7 | The finding isn't dismissed. | finding |

Known exploited lifts A2c, A3, A4, A5 and A6, never A1, A2a for `dev`/`client` scope, or A2b: an actively exploited flaw in a dev dependency or an unexposed OS library still can't be triggered by a request.

### 5.3 Exposed list

OS packages and binaries that process requests or run the app's code directly, so a vulnerability in them is reachable through the app:

| Group | Names (deb/apk/rpm package or binary) |
|---|---|
| JavaScript runtimes | `nodejs`, `node`, `bun`, `deno` |
| Python | `python3.x`, `libpython3.x`, `python3`, `python` |
| PHP | `php`, `php8.x`, `php8.x-cli`, `php8.x-fpm`, `php8.x-common`, `php-fpm`, `frankenphp` |
| Ruby | `ruby`, `ruby3.x`, `libruby3.x` |
| Java and .NET | `openjdk-*-jre*`, `openjdk-*-jdk*`, `java`, `aspnetcore-runtime-*`, `dotnet-runtime-*`, `dotnet` |
| Web servers inside the app image | `nginx`, `apache2`, `httpd`, `caddy` |
| Go | the toolchain's `stdlib` compiled into Go binaries |

Not on the list:

- **openssl/libssl**: launchroom's edge terminates TLS (Go's TLS stack in Caddy), so requests reach app containers as plain HTTP. OpenSSL in the app is used for outbound connections, where an attacker must control the peer. Its findings are "Worth knowing" with the reason "TLS ends at launchroom's edge". Node.js bundles its own OpenSSL, so it is covered through `node`.
- **glibc, musl, zlib, libxml2 and other libraries**: most of their advisories are local (`AV:L`) or need a specific API reached with attacker input; launchroom can't see whether the app does that. Listing them would put a base image's routine updates in front of the user every week.
- **Database engines** (postgresql, mysql, redis, …): never publicly routed ([09](09-security.md) R27), so A6 makes them "Worth knowing" anyway; their versions are tracked (§4.6).

### 5.4 "Worth knowing"

Every other matched advisory, plus version facts (§4.6), feed problems (§4.4) and inventories that failed. Each entry carries its **reasons**, the first rule that kept it from "Action needed", shown as plain words:

| Reason code | Shown as |
|---|---|
| `dev` | Development dependency |
| `client` | Only in browser code |
| `bundled_unknown` | In the app bundle; launchroom can't tell if the server runs it |
| `unknown_scope` | Scope unknown (no lockfile) |
| `os_package` | Operating system package |
| `tls_at_edge` | TLS ends at launchroom's edge |
| `third_party_image` | Part of the image, waiting for a fixed version from its maintainers |
| `low_severity` | Low or medium severity |
| `severity_unknown` | No severity published |
| `no_fix` | No fixed version yet |
| `local_vector` | Needs local access to the server |
| `not_public` | No public domain |
| `preview` | Preview environment |
| `end_of_life`, `patch_available` | Version facts |

Groups on the page are by reason, most severe first inside each group.

### 5.5 Findings lifecycle

- A finding is one (service instance, advisory group, component name and version). `security_findings.status`: `open` → `resolved` (the live deployment no longer contains the version, the advisory was withdrawn, or the instance was removed) or `dismissed`; `dismissed` → `open` (reopened by a user, or automatically when the level rises from "Worth knowing" to "Action needed" or the advisory becomes known exploited after dismissal); `resolved` → `open` (the version comes back, e.g. a rollback). `SecurityFindingStatus` owns the transitions.
- Levels are recomputed for a team after each sync, inventory, live deployment, domain change, protection change and KEV update (`EvaluateSecurityFindings`, idempotent, one team at a time under an advisory lock).
- **Dismiss** ("Not affected"): reason (`not_used` "We don't use the affected code", `mitigated` "Mitigated another way", `accepted` "Accepted risk"), optional note (≤ 500 characters), applies to that service instance and advisory group; audited; needs `projects.manage`.

## 6. Web firewall

### 6.1 Image

- `deploy/images/caddy/Dockerfile` adds `github.com/corazawaf/coraza-caddy/v2@v2.6.2` (Caddy module `http.handlers.waf`), pins Coraza with `github.com/corazawaf/coraza/v3@v3.8.1`, and pins the rule set with `github.com/corazawaf/coraza-coreruleset/v4@v4.25.0` (OWASP CRS 4.25.0, embedded into the binary; no rule files on disk). The module check at the end of the Dockerfile adds `http.handlers.waf`. All three are Apache-2.0. Measured growth: the Caddy binary grows from 60.2 to 65.8 MB, the image by 5.6 MB.
- The versions are build arguments of launchroom-caddy (`CORAZA_CADDY_VERSION`, `CORAZA_VERSION`, `CRS_VERSION`) and are written into its image labels `io.vantezzen.launchroom.waf.engine` (`coraza 3.8.1`) and `io.vantezzen.launchroom.waf.ruleset` (`OWASP CRS 4.25.0`). launchroomd reads them from the running Caddy container (image labels are merged into container labels) and announces the daemon feature `waf` only while they are there and pass the `WafLabel` grammar. The image is digest-pinned (R31), so the labels are as trustworthy as the binary. This covers release builds, dev builds pinned to a local launchroom-caddy (`make caddy-image-pin`) and an edge that keeps the previous release's Caddy until its self-update; the stock `caddy:2.11.7` has no labels and gets no firewall. Nothing extra is compiled into launchroomd. After an update the dashboard re-renders every NodeState once when its system image pins changed (`launchroom:node-states:refresh`, [11](11-installation-and-dev.md) §4), so edges switch to the new Caddy and announce `waf` without waiting for another change.
- CRS updates arrive only with launchroom releases (D64).

### 6.2 Modes and defaults

Per service instance (environment-specific, like domains): **Off**, **Log only**, **Block**, a paranoia level **1** (default) or **2** (more rules, more false positives), and how much of a request body is inspected: **32 KiB** (default), **128 KiB** or **1 MiB** (`RouteWaf.inspectBodyKiB`, §6.7 for the cost). It applies to every route of the instance: its domains and immutable deployment URLs.

| Case | Default |
|---|---|
| A service instance created after this feature | Block, paranoia 1 |
| Existing service instances when the update arrives | Log only, paranoia 1 |
| Preview environments | Copied from the base environment's instance when the preview is created |
| Services without routes (workers, databases) | The setting is kept but has no effect |
| The dashboard's own route and routes with client certificates | Never protected by the firewall (the dashboard doesn't send it, launchroomd ignores it there) |

Why keep the suggested defaults: CRS at paranoia 1 is tuned for few false positives on typical apps, new services are tested by their owners right after the first deploy, and a blocked request shows its reference id (§6.5) that leads straight to the exclusion flow. Existing services already have real traffic that may include requests CRS would block; Log only lets the page show what would have been blocked before anyone switches. The Security page and the service's firewall settings show, per Log only service, "Would have blocked 312 requests in the last 7 days" with the categories and the most matched paths, and **Turn on blocking** (one click, audited).

Mode changes apply immediately (a route change, not a staged change), are audited (`waf.mode_changed`) and need `projects.manage`. `launchroom.json` support is a follow-up (§15).

### 6.3 Config generation

The dashboard sends typed settings per route (`RouteSpec.waf`, [03](03-daemon-protocol.md) §13.3); launchroomd renders every Coraza directive itself from a template compiled into its binary. **No directive text crosses the protocol**, so a compromised control plane can't add `Include`, `SecAuditLog` targets, `exec` actions or other directives.

One Coraza instance per edge:

- The directive string is identical for every route of the edge, so coraza-caddy's pool shares one compiled rule set between all route handlers and across reloads (`wafPool` keyed by the directives). A change of any route's firewall settings recompiles once (about 1 to 2 s of CPU, measured in the test plan).
- Per route, inside the route's subroute after the www ↔ apex redirect and **before** preview forward auth: a `headers` handler sets `X-Lr-Waf-Route: <route id>` on the request (overwriting anything a client sent), then the `waf` handler, then a `headers` handler deletes `X-Lr-Waf-Route` before anything else sees the request.
- Routes with mode Off, and routes of edges that don't announce `waf`, get none of this.

Template (launchroomd fills the marked parts from validated fields only; ids of generated rules come from the fixed local range 10000–99999):

```
SecRuleEngine On
SecRequestBodyAccess On
SecRequestBodyLimit 1048576            # the largest setting; sizes Coraza's body buffer
SecRequestBodyInMemoryLimit 131072
SecRequestBodyLimitAction ProcessPartial   # larger bodies pass uninspected beyond the limit, never refused
SecRequestBodyJsonDepthLimit 1024
SecArgumentsLimit 256                  # inspect at most 256 fields per collection (engine-wide)
SecResponseBodyAccess Off
SecAuditEngine RelevantOnly
SecAuditLogRelevantStatus "^403$"      # only requests refused, or refused in Log only (Coraza's detection-only interruption)
SecAuditLogParts AKZ                   # header, matched rules, end: no request headers or bodies
SecAuditLogFormat json
SecAuditLogType https
SecAuditLog http://127.0.0.1:2023/waf  # LR_INGRESS_WAF_LOG_ADDR, loopback only
# body processors (Coraza's recommended rules), so JSON and XML fields become ARGS:
SecRule REQUEST_HEADERS:Content-Type "^(?:application(?:/soap\+|/)|text/)xml" "id:10000,phase:1,t:none,t:lowercase,pass,nolog,ctl:requestBodyProcessor=XML"
SecRule REQUEST_HEADERS:Content-Type "^application/json" "id:10001,phase:1,t:none,t:lowercase,pass,nolog,ctl:requestBodyProcessor=JSON"
SecAction "id:10002,phase:1,pass,nolog,ctl:requestBodyLimit=32768"   # inspect the first 32 KiB by default
Include @crs-setup.conf.example
# per Log only route:
SecRule REQUEST_HEADERS:X-Lr-Waf-Route "@streq rt_…" "id:<n>,phase:1,pass,nolog,ctl:ruleEngine=DetectionOnly"
# per route with inspectBodyKiB 128 or 1024:
SecRule REQUEST_HEADERS:X-Lr-Waf-Route "@streq rt_…" "id:<n>,phase:1,pass,nolog,ctl:requestBodyLimit=131072"
# per paranoia 2 route:
SecRule REQUEST_HEADERS:X-Lr-Waf-Route "@streq rt_…" "id:<n>,phase:1,pass,nolog,setvar:tx.blocking_paranoia_level=2"
# per exclusion (§6.4):
SecRule REQUEST_HEADERS:X-Lr-Waf-Route "@streq rt_…" "id:<n>,phase:1,pass,nolog,chain"
  SecRule REQUEST_FILENAME "@beginsWith /api/search" "ctl:ruleRemoveTargetById=942100;ARGS:q"
Include @owasp_crs/*.conf
```

- Coraza sizes a transaction's body buffer from `SecRequestBodyLimit`, so `ctl:requestBodyLimit` can only lower it. The engine allows the largest setting, rule 10002 lowers every request to 32 KiB, and a route's own rule raises it again.
- Per-route rule ids are assigned in route id order from 10003; a route rule set that would leave the local range fails rendering, and the routes are served without the firewall (§6.6). Exclusions without a path render without `chain`; without a target they use `ctl:ruleRemoveById`.
- The firewall module logs every match with the client address, the URI and the matched data. Its logger `http.handlers.waf` is excluded from Caddy's log, so none of it reaches the container log.
- Responses are not inspected (`SecResponseBodyAccess Off`): it would buffer every response and break streaming; CRS's data-leakage rules are the price.
- Request bodies are inspected up to the route's limit (32 KiB by default) and then streamed to the app; the rest of a larger body is not inspected, and no body is refused for its size.
- Coraza collects at most 256 fields per collection (query, body, path). It has no per-request setting for this, so the limit is the same for every route and every body setting. Further fields pass uninspected.
- Multipart uploads: Coraza writes file parts to a temporary file that no CRS 4.25 rule reads. Field names, file names, file sizes and the other fields are inspected, so a 1 MiB upload costs about as much as a small form.
- WebSocket upgrades and server-sent events pass through (coraza-caddy unwraps hijacking; response bodies aren't buffered).
- A blocked request gets status 403 and launchroom's edge page "This request was blocked by the web firewall" with the reference id (Coraza's transaction id, `{http.error.id}`), in the error-page style of [05](05-networking-ingress.md) §4 (plain line for non-HTML clients).

### 6.4 Exclusions

For false positives, per service instance, at most 50 (`waf_exclusions`):

| Field | Grammar | Effect |
|---|---|---|
| `rule` | a CRS rule id, 911000–944999; anomaly-evaluation and initialisation rules (901xxx, 949xxx, 959xxx, 980xxx) are refused | the rule doesn't run |
| `path` (optional) | a request path prefix: `/` plus `[A-Za-z0-9._~/-]`, ≤ 128 bytes | only for request paths starting with it |
| `target` (optional) | `ARGS:<name>`, `ARGS_NAMES`, `REQUEST_COOKIES:<name>`, `REQUEST_COOKIES_NAMES`, `REQUEST_HEADERS:<name>`, `REQUEST_BODY`, `XML`, `FILES`; names `[A-Za-z0-9_.-]{1,64}` | only that part of the request is skipped for the rule (`ctl:ruleRemoveTargetById`) |

- Created from a firewall event ("Allow requests like this": rule, the event's path cut to its static prefix, the matched target prefilled and editable) or by hand in the service's firewall settings; listed there with who added them and when; audited (`waf.exclusion_added`, `waf.exclusion_removed`); `projects.manage`.
- Exclusions are typed fields rendered by launchroomd (§6.3), never rule text. launchroom ships no rules of its own.

### 6.5 Events

Path: Coraza's audit log, JSON over HTTP to launchroomd on loopback, aggregated per route and 10-minute window into `Report.metrics.waf` ([03](03-daemon-protocol.md) §13.4), stored by the dashboard.

- **What counts**: a transaction whose rules reached the anomaly threshold: Coraza interrupted it (**blocked**, mode Block) or the blocking-evaluation rule 949110 (inbound) or 959100 (outbound) matched without interruption (**detected**, mode Log only). Coraza writes an audit record only for those (`RelevantOnly` with relevant status 403, which in Log only is the status of the interruption it skipped); launchroomd checks it again from the record (interrupted, or 949110/959100 among the matched rules) and drops anything else, such as an app's own 403 on a request with a low score. Transactions with lower scores (single notice-level matches) are never counted: they are the routine noise of any web traffic and would turn the chart into a request counter.
- **Receiver** (`internal/waf`, launchroomd): listens on `LR_INGRESS_WAF_LOG_ADDR` (`127.0.0.1:2023`), accepts loopback peers only, bodies ≤ 256 KiB, answers 204 before parsing, a queue of 1,024 records (overflow counted as `dropped`). It decodes only these fields and discards the rest of the record in memory: `transaction.unix_timestamp`, `transaction.id`, `transaction.client_ip`, `transaction.server_id` (host), `transaction.request.method`, `transaction.request.uri` (cut to the path and normalised like analytics, [15](15-observability.md) §5.1), `transaction.is_interrupted`, and per message `data.id`, `data.tags`, `data.msg` (only to read the anomaly score of 949110/959100) and the variable name in `data.data` ("found within ARGS:q"). The record also carries the query string, argument values and the matched data; they never leave launchroomd's memory. The client address is cut to its network right after decoding (`state.ClientNetworkOf`: /24 for IPv4, /48 for IPv6, IPv4-mapped IPv6 as IPv4) and the full address is discarded.
- **Category** from the rule id (stable across CRS 4 releases; table compiled into launchroomd and mirrored in the dashboard): 911 `method`, 913 `scanner`, 920–922 `protocol`, 930 `lfi`, 931 `rfi`, 932 `rce`, 933 `php`, 934 `generic` (Node.js, SSRF, template injection), 941 `xss`, 942 `sqli`, 943 `session_fixation`, 944 `java`, anything else `other`. Evaluation rules (949xxx, 959xxx, 980xxx) and initialisation rules (901xxx) are not counted as matched rules.
- **Aggregation** per (route, 10-minute window): blocked and detected counts; per category; the top 20 rule ids and top 10 normalised paths with the rest summed; up to 20 **event samples** per window (the first event of each distinct (client network, rule set, path), then the first others), each with time, action, method, path, rule ids (≤ 8), categories, matched targets (variable and name only, ≤ 8), client network, anomaly score and reference id.
- **Client network**: the /24 (IPv4) or /48 (IPv6) network of the TCP peer Caddy saw (`clientNetwork`, a canonical CIDR checked by grammar on both sides). The full address never crosses the protocol and never reaches the dashboard's database. Behind a proxy (Cloudflare, a load balancer) it is the proxy's network; the event list says so when the edge's domain is reached through a proxy ([05](05-networking-ingress.md) §5).
- **Privacy** (amends [09](09-security.md) R40, now R41): request metrics and analytics still store no addresses. Firewall event samples store only the client network of requests that reached the anomaly threshold, for 7 days, never in aggregates, shown only to owners and admins. A /24 can still narrow down a small office or a home connection, so the restriction stays even though the address is cut. No request bodies, query strings, header or cookie values or matched data are stored or sent.
- **Retention**: `waf_samples` 10-minute rows 7 days, hourly rows 90 days (`launchroom.waf.*_retention_days`); `waf_events` 7 days.
- Buffering, acks and idempotency work like request analytics: windows ride in every report until accepted, ≤ 1,024 buffered, stored idempotently per (server, route, window).

### 6.6 Failure behaviour

- If Caddy refuses a config with the firewall (it shouldn't: every value is grammar-checked), launchroomd loads the same routes without the `waf` handlers and reports `ingress.waf.state: error` with Caddy's message. It doesn't try the same refused config again; the next firewall change, or another Caddy container, tries again. Routes keep serving: the firewall is a layer of defence, not an access boundary, so availability wins. The dashboard raises the alert `waf_unavailable` for that server (§10) and the Security page shows "Web firewall is off on fra-1" with the message.
- If launchroomd's receiver is down, Coraza's HTTP writer fails fast on the refused loopback connection; blocking keeps working, events of that time are lost.
- An edge without the `waf` feature serves routes unprotected; the service's firewall settings say "Update fra-1 to protect this service" for each such edge.

### 6.7 Performance cost

Budgets and numbers measured by `TestWafPerformance` (`make test-daemon-integration ARGS='-test.run TestWafPerformance -waf-perf'`): launchroom-caddy in the dind test daemon on an arm64 laptop (OrbStack), one `traefik/whoami` upstream, the load generator in the same container, a route with the firewall in Block at paranoia 1 compared to a route without it through the same Caddy. Medians are from 1,500 GETs, 300 JSON POSTs and 20 large POSTs per route over keep-alive; throughput from 32 connections for 15 s. The first run (2026-10-25) was on an idle machine; the runs after the body limit change ran next to other workloads (load average about 12), so their latency and throughput numbers are noisier and are given as ranges.

| Measure | Budget | Measured | Result |
|---|---|---|---|
| Added latency, GET without body, p50 / p99 | ≤ 1 ms / ≤ 5 ms | +0.4 to +0.7 ms / within the noise (+1.4 ms loaded, none idle) | met |
| CPU per request at the default 32 KiB limit | ≤ 0.3 s | at most about 0.1 s (one 32 KiB field); 0.02 to 0.06 s for 32 KiB of JSON or form fields | met |
| 64 KiB JSON POST (about 4,000 fields), added p50 | ≤ 0.3 s (was ≤ 5 ms) | +0.04 to +0.06 s (first 32 KiB, first 256 fields; +52 ms in the integration run) | met |
| 1 MiB JSON POST, one field, added p50 | ≤ 5 ms | +0.3 to +2.7 ms (only the first 32 KiB is read; a cut JSON document yields no fields) | met |
| 1 MiB multipart upload with a small form field | ≤ 5 ms | +2.5 ms; the file content isn't inspected | met |
| Throughput drop at saturation, small GETs | ≤ 30 % | 15 % idle; 42 % in a loaded run (noisy) | met idle |
| Caddy memory with one compiled CRS | ≤ +150 MiB | +34 to +55 MiB | met |
| Config reload with a firewall change | ≤ 3 s of CPU, no dropped requests | 0.4 to 1.3 s CPU; 0 failed requests in 3 runs (about 650 each) | met |
| Image size | ≤ +20 MB | +5.6 MB | met |

Why the 5 ms budget for a 64 KiB JSON POST was replaced: CRS's cost per request grows with the number of inspected fields and with their length, not with the body size as such. Each field costs about 0.3 ms (CRS runs every rule with its transformations on it); one long field costs about 3 s per MiB. Two limits bound it: the body limit (the route's setting) and Coraza's field limit, `SecArgumentsLimit 256`. Without the field limit (Coraza's default is 1,000), the worst 32 KiB request cost about 0.25 s. Measured with both limits (fastest of 5, firewall in Block, compared to 3 to 5 ms without it):

| Setting | Worst request found | Many small fields (32 KiB to 1 MiB of JSON or form data) |
|---|---|---|
| 32 KiB (default) | about 0.1 s (one 32 KiB field) | 0.02 to 0.06 s |
| 128 KiB | about 0.35 s (one 128 KiB field) | 0.03 to 0.06 s |
| 1 MiB | about 3 s (one 1 MiB field) | 0.03 to 0.06 s |

So the default bounds what one unauthenticated request can cost the shared edge at about a tenth of a second of one core, and large uploads keep working. 128 KiB and 1 MiB are per-service choices for apps whose attack surface is in large bodies. Coraza has no per-request setting for the field limit, so 256 applies to every tier.

**What the limits mean for protection.** Bytes past the body limit and fields past the 256th are not inspected. Someone who knows this can pad a request (32 KiB of filler, or 256 harmless fields) and put the payload after it. The web firewall filters common, automated attacks; it doesn't guarantee that an attack can't reach the app, and a vulnerable app still needs its fix. The site page (`web-firewall.mdx`, §17) and the "How it works" modal (§9.7) say this in plain words.

Help text for the setting: "Larger limits check more of each request but cost more server time: one request can take up to about 0.1 s of CPU at 32 KiB, 0.35 s at 128 KiB and 3 s at 1 MiB. Anything past the limit, and any form field past the 256th, is passed on unchecked."

Suggested site wording: "The firewall checks the first 32 KiB of each request body (more if you choose) and the first 256 fields. Anything after that reaches your app unchecked, so a determined attacker can hide a payload behind padding. Treat the firewall as a filter against common attacks, not as a replacement for fixing a vulnerable app."

No knob caps how many requests the edge inspects at once: neither Caddy nor coraza-caddy has a concurrency limit for a handler, and Caddy uses every core (`GOMAXPROCS`). The cost is bounded per request (above), not per edge. A flood of worst-case requests can keep every core busy, as a flood of TLS handshakes can. Capping the Caddy container's CPUs would bound it, but it would also slow TLS and proxying for every app on the server, so it is not done.

Request bodies are buffered up to the inspection limit before they reach the app.

## 7. Investigation

### 7.1 Trigger

- Automatic once when a finding becomes "Action needed" (so the notification and the page already carry results), and on demand: **Investigate** on any finding, and **Check this service** on a service's security view (owners, admins and developers with `projects.manage`; throttled to 6 per hour per service instance).
- An investigation covers one service instance and, when started from a finding, that finding's advisory. `security_investigations` keeps the report 90 days.

### 7.2 Container inspection task

Daemon task `inspect_container` (feature `containerInspection`), one per running replica, pinned to the replica's server, 1 attempt, deadline 3 min. Spec and result: [03](03-daemon-protocol.md) §13.2.

launchroomd finds the running managed container of `spec.workload` like a session (§11.4 of [03](03-daemon-protocol.md): labels, this server, in the verified NodeState; never system, task or helper containers) and reads, through dockerd-guard:

1. `GET /containers/{id}/json`: the image it runs, compared with the NodeState's image (`imageMatches`), start time, restart count.
2. `GET /containers/{id}/changes`: every path the container added, changed or deleted in its writable layer since it was created.
3. `HEAD /containers/{id}/archive?path=…` for up to 256 added or changed regular files, highest priority first: size, mode (executable bit) and modification time. Contents are never read.
4. `GET /containers/{id}/top`: the process list (`ps -ef` columns: user, PID, PPID, start, command).

Then it classifies with fixed rules (constants in `internal/inspect`, listed in [03](03-daemon-protocol.md) §13.2), collapses known cache locations into counts, masks process commands against the values of `spec.maskEnv` (from the workload's sealed env, like `run_once` logs, [09](09-security.md) R16) and cuts them to 512 bytes. Path names are reported as printable UTF-8 with control and invalid bytes escaped (`\x1b`), ≤ 1,024 bytes.

### 7.3 dockerd-guard additions

Two read-only routes ([09](09-security.md) §6.2, R35), both only for containers of the **workload** class (refused for every system container, the volume helper and the update supervisor), inspected first and addressed by full id like every other route:

| Route | Query | Why it is safe |
|---|---|---|
| `GET /containers/{id}/changes` | none | Lists paths and change kinds of the container's own writable layer; no file contents, nothing outside the container |
| `GET /containers/{id}/top` | none (`ps_args` refused) | Lists the container's processes. dockerd passes `ps_args` to the host's `ps` as arguments, so any value is refused and Docker's default (`-ef`) is used |

Both are reads launchroomd could approximate today (it runs with `pid: host`), but through the guard they stay limited to managed workload containers and to these two shapes. Refusal tests: another class, an unmanaged container, a name instead of an id, `ps_args` with any value, extra query keys.

### 7.4 Signals the dashboard combines

| Signal | Source | Window |
|---|---|---|
| Container changes and processes per replica | `inspect_container` results | now |
| Running image is the deployed image | `imageMatches` | now |
| Launchroom's own consoles and SSH sessions into the service | `interactive_sessions`, `remote_accesses` (D35, D61) | last 7 days |
| Firewall events for the service, and those in the advisory's categories | `waf_events`, `waf_samples` | last 7 days |
| Requests and server errors per hour, unusual 5xx bursts | `request_metric_samples` | last 7 days |
| Top paths with errors | `request_analytics_samples` | last 7 days |
| How long the vulnerable version has been live | deployments of the instance whose inventory has the component | since first live |
| What the service can reach | canvas graph: connections and the services they point to, variables referencing other services (names only), secrets it reads (names only), volumes it mounts, every service on its environment network | now |
| Public exposure | domains, protection | now |
| Recent backups of the databases it can reach | backups ([14](14-backup-and-restore.md)) | latest before the window |

- **Advisory categories**: CWE ids of the advisory map to firewall categories (`App\Support\Security\CweCategories`): CWE-89/564 → `sqli`; 79/80 → `xss`; 77/78/94/95/917/1336 → `rce` and `generic`; 22/23/36 → `lfi`; 98 → `rfi`; 918 → `generic`; 502 → `java` and `php`; 384 → `session_fixation`. Unmapped CWEs use all categories.
- **Same source**: firewall events are grouped by `client_network`, so the report says "9 requests from the same network (203.0.113.0/24) matched SQL injection rules". A shared network (an office, a mobile carrier, a proxy) can put different people into one group; the report says "from the same network", never "from the same client".
- **Explained processes and changes**: a shell process during a launchroom console or SSH session by a member is labelled with that session ("Console session by Alice, 14:02 to 14:19"), not flagged.

### 7.5 Report and next steps

The report (`InvestigationReportData`) has three parts.

**What we checked**: one row per signal with its window ("Container of api on fra-1", "Web firewall, last 7 days").

**What we found**: plain sentences, each with a level:

| Level | Examples |
|---|---|
| Concerning (red, ✕) | "A new executable file appeared in /tmp: /tmp/.x/kworker (2.1 MB, added 3 days ago)." "A process runs from /tmp: /tmp/.x/kworker." "The running container uses an image launchroom didn't deploy." "A process matches a known crypto-miner name: xmrig." |
| Notable (amber, ⚠) | "2 requests matched SQL injection rules in the last 7 days; both were blocked." "A shell runs that no launchroom console explains: sh (PID 412) started by node." "Files of the app changed after start: /app/server.js." "/etc/passwd changed after the container started." |
| Nothing found (grey) | "No new or changed files outside caches." "No unexpected processes." "No requests matched the firewall rules for this kind of attack." |

**What to do now**: steps generated from the findings, each mapped to an existing launchroom action:

| Condition | Step | Action |
|---|---|---|
| A fixed version exists (built app) | "Update lodash to 4.17.21 or later in your repository; the push deploys it." | copyable command for the detected package manager (`pnpm up lodash@^4.17.21`), link to the repository |
| A fixed version exists (image service, engine) | "Change the image to a version with the fix." | Settings → Source (image) or the engine version |
| Any concerning finding in a container | "Replace the running containers with fresh ones from the image." | Redeploy (`RedeployService`: new containers, fresh writable layers) |
| Concerning findings and the service holds database credentials | "Restore postgres from a backup taken before 12 March 14:02." | Backups tab with that time preselected (point-in-time recovery where available, else the newest snapshot before it; restore as new and swap, [14](14-backup-and-restore.md) §5, §10) |
| Concerning findings and the service reads secrets | "Change these secrets, then deploy: STRIPE_KEY, SESSION_SECRET." | Variables, each name linked |
| Concerning findings and the service reaches managed databases | "Change the password of postgres's user." | Manual step today (the docs page explains it); credential rotation for managed databases is a follow-up (§15) |
| Firewall not in Block | "Block attacks on api." | one-click mode switch |
| Always | "Read the advisory." | the advisory's references |

**Honesty**: the report always ends with: "Someone who got in can remove their traces, so a clean result doesn't prove nothing happened. The firewall only sees requests that match its rules, and launchroom keeps request details for 7 days." When a replica couldn't be inspected (server offline, timed out), the report says which and treats the result as partial.

## 8. Data model

All tenant tables carry `team_id` and are reached through team relations (R7).

| Table | Key columns | Notes |
|---|---|---|
| `image_inventories` | `public_id` (`inv_`), `team_id`, `image` (digest-pinned ref), `platform`, `source` (`built`/`registry`), `status` (`pending`/`ready`/`failed`, `InventoryStatus` transitions), `task_id`, `generator` (scanner ref), `source_analyzed`, `distro` (jsonb), `lockfiles` (jsonb), `notes` (jsonb), `component_count`, `dropped`, `truncated`, `error`, `scanned_at` | Unique (`team_id`, `image`, `platform`). `deployments.inventory_id` (nullable FK) links deployments |
| `inventory_components` | `inventory_id`, `ecosystem`, `name`, `version`, `purl`, `kind`, `scope`, `presence`, `direct`, `osv_ecosystem`, `osv_name` (null = not queryable) | PK (`inventory_id`, `ecosystem`, `name`, `version`); GIN trigram index on `lower(name)`; index (`osv_ecosystem`, `osv_name`, `version`) |
| `advisories` | `id` (OSV id, PK), `group_key`, `aliases` (text[]), `upstream` (text[]), `cve_ids` (text[]), `modified`, `published`, `withdrawn`, `summary`, `details`, `severity_label`, `cvss` (jsonb: vectors, v3 score), `attack_vector`, `cwe_ids` (text[]), `affected` (jsonb), `references` (jsonb), `fetched_at` | Instance-wide public data |
| `advisory_queries` | (`osv_ecosystem`, `osv_name`, `version`) PK, `advisory_ids` (text[]), `checked_at`, `error` | Query cache (§4.3) |
| `known_exploited` | `cve_id` PK, `date_added`, `due_date`, `ransomware`, `vendor`, `product`, `name` | KEV |
| `lifecycle_releases` | (`product`, `cycle`) PK, `latest`, `latest_date`, `eol` (date, null = no date), `is_eol`, `fetched_at` | endoflife.date |
| `advisory_feeds` | `feed` PK (`osv`/`kev`/`eol`), `enabled`, `last_success_at`, `last_attempt_at`, `last_error`, `etag`, `items`, `cursor` | Feed state (§4.4) |
| `security_findings` | `public_id` (`fnd_`), `team_id`, `project_id`, `environment_id`, `service_instance_id`, `deployment_id`, `inventory_id`, `group_key`, `advisory_ids` (text[]), `ecosystem`, `name`, `version`, `level` (`action_needed`/`worth_knowing`), `reasons` (jsonb, codes of §5.4), `known_exploited`, `status` (`SecurityFindingStatus`), `first_seen_at`, `last_seen_at`, `resolved_at`, `dismissed_at`, `dismissed_by`, `dismiss_reason`, `dismiss_note`, `notified_at`, `investigation_id` | Partial unique (`service_instance_id`, `group_key`, `ecosystem`, `name`, `version`) where status ≠ resolved. Resolved findings kept 90 days |
| `security_investigations` | `public_id` (`ivg_`), `team_id`, `project_id`, `service_instance_id`, `finding_id` (nullable), `status` (`running`/`completed`/`partial`/`failed`), `started_by`, `started_at`, `finished_at`, `inspections` (encrypted under the project DEK, AD `investigation\|<team>\|<project>\|<ivg_ id>`: the `ContainerInspectionResult`s), `report` (jsonb `InvestigationReportData`: sentences and steps, no process commands) | 90 days |
| `waf_samples` | PK (`server_id`, `route_id`, `resolution`, `at`), `service_instance_id`, `project_id`, `blocked`, `detected`, `categories` (jsonb), `rules` (jsonb ≤ 20), `paths` (jsonb ≤ 10), `other_*`, `dropped` | Resolutions 10 and 60; retention §6.5 |
| `waf_events` | `id`, `server_id`, `route_id`, `service_instance_id`, `project_id`, `at`, `action`, `method`, `path`, `rule_ids` (int[]), `categories` (text[]), `targets` (text[]), `client_network` (cidr, /24 or /48, validated again), `score`, `reference` | 7 days; indexes (`service_instance_id`, `at`), (`project_id`, `at`) |
| `waf_exclusions` | `public_id` (`wfx_`), `team_id`, `service_instance_id`, `rule_id`, `path_prefix`, `target`, `note`, `created_by`, `created_at` | ≤ 50 per instance |
| `service_instances` (columns) | `waf_mode` (`off`/`log`/`block`), `waf_paranoia` (1/2), `waf_mode_changed_at` | Defaults §6.2 |
| `servers` (column) | `waf_status` (jsonb, the edge's last `IngressStatus.waf`) | Read by the Security page and `waf_unavailable` |

## 9. UI

Follows the [design system](design-system.md): monochrome chrome, colour only for status and data with a word or icon beside it, charts only with data, one sentence per description, no "·" separators, no filler subtitles.

### 9.1 Navigation

- Sidebar: **Security** between Analytics and Logs (icon `ShieldCheck`, or `ShieldAlert` with the number of "Action needed" findings as a badge; the number is the non-colour cue). Visible to every team member.
- Routes: `/security` (page `security`), `/security/findings/{finding}` (finding detail, also opened as a sheet from the page), `/security/inventory?q=` (inventory search state in the URL).
- Service panel: a chip "Action needed" in the panel header links to `/security?service=svc_…`. Settings → Networking gets a **Web firewall** section (§9.6).

### 9.2 Page layout

`Page` size `wide`. Top bar: breadcrumb "Security"; right: range select (Day, Week, Month; drives the firewall section), **How it works** (opens the modal, §9.7), **Check now** (owners and admins; disabled with "Checked 4 min ago" in its tooltip while throttled).

**1. Status hero** (`SecurityHero`, one card). Top band: a 56 px status tile (red `ShieldAlert` on the error tone or green `ShieldCheck` on the ok tone) with the verdict in large type, "2 vulnerabilities need you" (counted per advisory group, not per finding) or "Nothing needs you"; under it a `MetaLine` with "In 3 services", "One is known to be exploited" (⚠) and "Checked 28 minutes ago" (or "Can’t reach osv.dev since …" with ⚠ after a day, or "Vulnerability checks are off"). Right (≥ 1024 px): **Protections**, one status row per public source (Vulnerability checks, Known exploited list, Release cycles: On, Off or Unreachable) and Web firewall (On, Log only, Ready). Bottom band: three metrics separated by hairlines (3 → 1 columns), the protections at work:

| Metric | Value | Hint / visual |
|---|---|---|
| Packages watched | "3,214" | "In 14 services"; ⚠ when the last check is older than 24 h; "Reading packages of 3 images" while inventories are pending |
| Attacks blocked | blocked count in the range, delta against the previous period (neutral) | the range in words; `Sparkline` of blocked per bucket |
| Protected services | "9 of 11" | `Meter`; "2 only log attacks" (⚠), "1 with the firewall off" or "Every public service blocks attacks" (green dot) |

**2. Action needed** (only when non-empty, or when `?service=` filters the page: then the description names the service and **Show every service** clears the filter): one card per advisory group (`groupActionNeeded` in `features/security/shape.ts`). Header: a `MetaLine` with the severity word and its cue (red ✕ for critical and high) and the CVSS score, "⚠ Known exploited since 3 Oct", the advisory id in mono; the advisory summary as the title; the package line in mono with the installed versions and the fix ("next 14.1.0 → 14.2.25"); primary **See the fix** opens the finding the investigation worries about most (else the first). Below a hairline, one row per affected service instance: logo, name, project and environment as a `MetaLine`, and the investigation as a verdict (`verdictOf`): "✕ 2 concerning findings in the container", "⚠ The container couldn’t be checked", "● Nothing unusual found", a spinner with "Checking the container", or "Not investigated yet", with the time and the other summary sentences ("37 requests matched …") on a second line. The row opens that finding; an icon button **Investigate again** (owners and admins, ≥ 640 px; the sheet has it everywhere). Groups are sorted by known exploited, then severity, then the number of services.

**3. Web firewall**:
- Edges that can't run the firewall or report `error`: one amber `Callout` per case above the chart ("Web firewall is off on fra-1: update the server").
- One card: left, **Requests per hour** (per day for Month) as `StackedBars` with one signal series "Blocked" (the sum of every category) and, when any service is in Log only, the hatched grey "Would have been blocked"; legend with totals; table view. Right (below on narrow screens, divided by a hairline), **Attack mix**: one segmented bar and a ranked list (colour square, name, count, share) of the four kinds with a palette slot (SQL injection, Cross-site scripting, Code execution, Scanners) and everything else folded into a grey "Other" row that names its kinds.
- **Watching, not blocking yet** (only when a service is in Log only): one row per such service, "api in production would have blocked 312 requests in the last 7 days", its top categories and top matched path as a `MetaLine`, and **Turn on blocking**.
- **Recent blocks** (2 of 3 columns) beside **Most targeted** (1 column; services with counts and a `Meter` against the top one). Recent blocks is a compact feed of the latest 20 events (8 shown, "Show 12 more"): time, method and path in mono, the kind with its colour square ("SQL injection, logged" for Log only), the service, and the client network for owners and admins (≥ 1280 px). A row opens the event sheet: rule ids with CRS's message, matched targets, score, "Allow requests like this" (exclusion flow, §6.4).
- No firewall events in the range: one calm card instead of the chart, "No attacks blocked this week", "Requests to 9 public services pass the firewall first." and the blocking meter ("9 of 11").

**4. Worth knowing**: one card of quiet rows in two columns (one on phones), one per reason (§5.4): the count, the reason ("142 in operating system packages") and a chevron. A row opens a sheet with its items: version facts as sentences ("Node.js 20 reached its end of life on 30 April 2026, used by 6 services"); otherwise package and version (mono), severity word, advisory title and the services using it, each opening the finding. Notices from the page (`notices`) sit in a recessed strip at the top of the card.

**5. Inventory**:
- Search field "Search packages and runtimes" (placeholder "node 20, lodash, openssl 3.0"). Grammar: words match name substrings; a token that looks like a version (`20`, `4.17`, `3.0.15`) filters versions by prefix; `ecosystem:npm`, `service:api`, `scope:runtime` filters as chips like the log query bar.
- The section description counts what is watched ("3,214 packages in 14 services").
- Without a query: **Runtimes and engines**, then **Base images**, each a catalogue card of rows: logo, "Node.js 20.11.1" and "6 services", a lifecycle `Meter` from release to end of life (amber once it ended, a dashed track when no dates are known), and right-aligned "Supported until 30 April 2027" or "⚠ Ended 30 April 2026" with "20.19.5 available" when a newer patch exists. On phones the bar and the dates go below the name.
- With a query: one card per package (name, ecosystem, number of versions), rows per version with the scope as a word under it (Runtime, Development, Browser, Unknown), its services (project, environment) and open findings for that version with their severity word. "No service uses lodash" when nothing matches.

Empty states:
- No live services: "Deploy a service to see what it runs and how it's protected."
- Inventories pending: the Packages watched metric reads "Reading packages of 3 images" and updates live.
- Nothing needs action: the hero's green "Nothing needs you" with the protections on; the Action needed section is left out.
- Sources off: "Vulnerability checks are off on this launchroom" with a link to Settings → Security for owners.
- No firewall events: the chart is hidden; the section shows the calm card described in part 3.

### 9.3 Finding detail

Sheet (wide) or page. Header: the advisory summary as the title, the advisory id under it. Body: a `MetaLine` with the severity word and its cue, the CVSS score and "⚠ Known exploited since …" ("Marked not affected" when dismissed) with the menu at its end; a recessed strip **Installed** (package and version, mono) | **Fixed in** | **First seen** (two columns and a row on phones); facts as label-over-value pairs in two columns: service (project and environment), public domains, scope and presence (with the lockfile), ecosystem, services with this version. **Why it needs action** or **Why it isn't urgent**: the evaluated rules as a two-column checklist (✓/✕ with the data: "Development dependency (package-lock.json)", "No public domain"). **Investigation** (§7.5) with **Investigate again**: a verdict strip first (✕ "2 concerning findings" on the error tone, ⚠ "1 thing worth a look" or "Some parts couldn’t be checked" on the warning tone, ● "Nothing unusual found" on the recessed surface, with who checked it and when), then What we found, What to do now (numbered, with the action buttons and the copyable command), What we checked, and the honesty line. **Advisory**: the details text rendered from plain text (paragraphs and links only, no HTML; links https only, `rel="noopener noreferrer"`), references, source attribution. Menu: **Not affected…** (dismiss dialog), **Reopen**.

### 9.4 Investigation in progress

A `StepList`: Container on fra-1, Container on fra-2, Web firewall, Requests, Reach; each step live, with "couldn't check: fra-2 is offline" as a failed step and the report marked partial.

### 9.5 Copy

- Sentence case, specific, no "agent" or "daemon": "Checking the container on fra-1" (not "launchroomd inspects").
- Notification and card titles name the object and the package: "api uses lodash 4.17.20, which has a critical vulnerability".
- No "·" between facts; `MetaLine` or a second line.

### 9.6 Service settings: Web firewall

In the service panel's Settings → Networking, for services with routes: mode as three `ChoiceTiles` (Off, Log only, Block) with one line each ("Requests that match attack rules are refused"), paranoia as a select under "Advanced" ("Stricter rules (may block legitimate requests)"), "Inspect request bodies up to" as a select under "Advanced" (32 KiB, 128 KiB, 1 MiB) with the help text of §6.7, the last 7 days for this service (blocked or detected count and a `Sparkline`), **Exceptions** (rows: rule id and its CRS message, path, target, added by, Remove behind a `ConfirmPopover`; "Add exception"), and edges without the feature as amber rows.

### 9.7 How it works (modal)

The only explanatory text. Sections, two or three sentences each: Vulnerability checks (sources, what is sent, how often), Action needed and Worth knowing (the rules in one sentence and a link to the docs), Web firewall (rule set and version, modes, what isn't inspected), Investigations (what is read, what it can't prove), Your data (the client's network, never the full address, for 7 days; nothing else per request).

### 9.8 Settings

Settings → This launchroom → **Security** (instance owners) gets a card **Vulnerability data**: one switch per source (OSV, CISA KEV, endoflife.date) with its last sync, and one line on what OSV receives. Settings → Team → Notifications lists the new rules (§10).

## 10. Notifications

Two new `AlertRule` cases ([15](15-observability.md) §3), on by default:

| Rule | Subject | Raised | Severity | Resolves |
|---|---|---|---|---|
| `vulnerability` | `security:<service instance public id>` | a finding of the instance becomes "Action needed"; `occurrence` = the advisory group, so a second advisory notifies again after the repeat window (60 min) | critical when known exploited or CVSS ≥ 9.0 in production, else warning | no open "Action needed" finding left on the instance |
| `waf_unavailable` | `server:<public id>` | an edge reports `waf.state` `error` for 5 minutes while it has routes with the firewall on | warning | state `on` again |

- Title and summary: "api uses lodash 4.17.20, which has a critical vulnerability" / "Fixed in 4.17.21. Checked the container: nothing unusual. 2 requests matched SQL injection rules in the last 7 days." Facts: Advisory, Package, Fixed in, Known exploited. Never process commands, paths from containers or client networks (they go to third-party chat services).
- "Worth knowing", blocked attacks and Log only detections never notify.

## 11. API and CLI

Public API v1 ([16](16-api-and-cli.md)), ability `read`, team-scoped like every endpoint:

| Endpoint | Returns |
|---|---|
| `GET /api/v1/security/findings?level=&status=&project=&service=` | findings with level, reasons, package, advisory group, known exploited, latest investigation status (cursor-paginated) |
| `GET /api/v1/security/findings/{finding}` | one finding with the advisory and the latest investigation report (no process commands) |
| `GET /api/v1/security/inventory?q=` | packages matching the query with versions and where they run |
| `GET /api/v1/environments/{environment}/services/{service}/inventory` | the live image's components |
| `GET /api/v1/security/waf?range=` | firewall totals, per category and per service |

No write endpoints in this round. CLI (`cli/`): `launchroom security` (open "Action needed" findings; exit code 12 with `--fail-on action-needed` when there are any, for CI), `launchroom security inventory <query>`.

## 12. Permissions and audit

| Action | Permission | Audit |
|---|---|---|
| View the Security page, findings, inventory, firewall charts | `resources.view` (every member) | |
| See client networks of firewall events | owner, admin | |
| Change firewall mode or paranoia, add or remove exclusions | `projects.manage` | `waf.mode_changed`, `waf.paranoia_changed`, `waf.exclusion_added`, `waf.exclusion_removed` |
| Dismiss or reopen a finding | `projects.manage` | `security.finding_dismissed`, `security.finding_reopened` |
| Start an investigation | `projects.manage` | `security.investigation_started` |
| Check now | owner, admin | |
| Switch advisory sources | instance owner | `instance.security_sources_changed` |

Policies: `SecurityFindingPolicy`, `SecurityInvestigationPolicy`, `WafExclusionPolicy`, `ImageInventoryPolicy`; every controller action, job and channel authorizes; finding and exclusion ids bind through team-scoped bindings.

## 13. Threat model

| Threat | What it can do | Controls |
|---|---|---|
| A compromised or wrong advisory feed (OSV, KEV, endoflife.date, or a network attacker despite TLS) | Raise false "Action needed" alerts; hide real ones; show misleading text | Data only: no action runs without a person; fixed https hosts through `SafeHttpClient`; size caps; strict parsing and grammars; text rendered without HTML; links https only. Hiding a real advisory removes a defence, it doesn't open a hole |
| A malicious repository or image crafting its SBOM (package names, versions, file names) | Inject odd strings; hide its own vulnerable packages; exhaust the scanner | Scanner runs in BuildKit's rootless sandbox without network, image mounted read-only; launchroomd caps the SPDX size and the component count and drops values failing grammars; the dashboard validates again. A team can only affect its own findings: inventories are per team (a team's build server can't change another team's view of a shared public image) |
| A malicious feed of exclusions or firewall settings (compromised control plane) | Turn the firewall off or exclude rules | The same as changing any route today; audited. It can't add directives: launchroomd renders every directive from typed, grammar-checked fields (§6.3) |
| A process on an edge host | Inject fake firewall events on loopback | The host is the trust boundary ([09](09-security.md) §5); only counts and samples of routed hosts are affected |
| Client networks of firewall events | Narrow a small office or home connection down to a few people | Only the /24 or /48 network leaves the edge; 7 days; owners and admins only |
| Investigation data | Process commands can contain secrets; file names are attacker-chosen | Commands masked against the workload's secret values and cut to 512 bytes; stored encrypted under the project DEK; never in notifications or the API; names escaped and rendered as text |
| The new guard routes | Read process lists or layer changes of other containers | Workload class only, inspect-first, full id, no query parameters (`ps_args` refused) |
| Denial of service through inventory or inspection | Tie up build servers or Docker | Deadlines (15 min, 3 min), component and size caps, build slots (D59), throttles on manual triggers |
| Privacy of package lists | osv.dev learns which package versions the instance uses | Documented; source switches; no project or user names sent |

## 14. Test plan

Targeted runs per AGENTS.md; the full suites run once at integration.

**Protocol** (this change): Go decoding and validation of every new type, fixtures `task-lease.inventory.json`, `task-lease.inspect-container.json`, `task-completion.inventory.json`, `task-completion.container-inspection.json`, `node-state.waf.json`, `report.waf.json` validated in Go and by the dashboard's schema test.

**launchroomd** (`make test-daemon PKG=…`, integration with `ARGS='-test.run …'`):
- `internal/state`: grammar and cross-field rules (exclusion rule range, target grammar, sample sums, event caps).
- `internal/ingress/caddy`: golden files with the firewall in Block, Log only, paranoia 2, exclusions with and without path and target, mixed with forward auth and clientAuth routes; the directive string is identical across routes; Off renders nothing; the fallback without `waf` handlers.
- `internal/waf`: decoding of real Coraza audit records captured from the pinned versions (blocked SQLi, detected XSS in Log only, below-threshold record dropped, record with query string and args: none of it in the sample), the client address cut to /24 or /48 (IPv4, IPv6, IPv4-mapped) with no full address anywhere in the encoded report, category table, aggregation caps, loopback-only listener, overflow counting.
- `internal/inventory`: lockfile analysers with fixtures per format (dev-only transitive packages, workspaces, missing lockfile), SPDX normalisation (Debian, Alpine, Node, Go binary fixtures from the pinned scanner), Next.js probe, truncation order.
- `internal/inspect`: change classification, collapsing, process flags, masking, path escaping.
- `internal/guard`: refusal tests for the two routes (system class, unmanaged, name instead of id, `ps_args`, extra keys) and a fuzz seed.
- Integration (dind): a Caddy route in Block returns 403 with the reference id for `?id=1' OR '1'='1` and the event reaches the report; Log only passes the request and reports it as detected; an inventory of the `next-adapter` and `node-hello` fixtures (dev dependency not `runtime`, traced package `runtime`); an inspection that sees a file created by exec in `/tmp` and a `sleep` process.
- Performance: the budgets of §6.7 with `hey` from a labelled tool container against the dev fake server, with and without the firewall; results written into §6.7.

**Dashboard** (`make check-dashboard FILES=… TEST_DB=launchroom_test_security`):
- The level rules as a table-driven test: one case per rule A1–A7 and per reason code, including known-exploited lifts and the cases it doesn't lift.
- OSV client with recorded responses (`Http::fake`): batching, pagination, 429 back-off, partial failure, withdrawn records, alias grouping, severity selection, fixed versions; KEV and endoflife parsing; feed failure keeps findings unchanged.
- Findings lifecycle: open, resolve on new deployment, reopen on rollback, dismiss and automatic reopen on escalation.
- Alerts: one alert per service instance, repeat on a new advisory, resolve.
- NodeState rendering: `routes[].waf` only for edges with `waf`; defaults for new and existing instances (migration test); schema-valid documents.
- Report ingestion of `metrics.waf` (idempotent, attribution, retention) and completion handling for `inventory` and `inspect_container`.
- Registry token: pull of `apps/<svc>` for a held inventory task; refused for others.
- Investigation report generation from fixture inspections (concerning, notable, clean, partial) and next steps.
- Policies matrix for every new route; arch tests unchanged; PHPStan max.

## 15. Follow-ups

- Per-service outbound network rules (egress allow-lists in nftables).
- Runtime detection of shells and unexpected processes spawned in containers (event-driven, not the on-demand `top`).
- Rotating managed database credentials as one action (generate, `ALTER USER` through the engine's client, update the reference, redeploy consumers).
- Rebuild without cache as a deploy option.
- Firewall settings and exclusions in `launchroom.json`.
- Scoring CVSS v4 vectors.
- SBOM download per deployment (from the registry attestation).
- API write endpoints for firewall settings and dismissals.
- Inventories for preview environments.

## 16. Work split

Two agents work in parallel after this design. The contract is frozen: the Go types in `daemon/internal/state` (`inventory.go`, `inspect.go`, `waf.go`), the generated schemas and the fixtures listed in §14. A change to it needs the Go types, `make gen`, the fixtures, [03](03-daemon-protocol.md) §13, `protocol/CHANGELOG.md` and this doc in one change, additive only.

### 16.1 Server side (launchroomd and the Caddy image)

Owns:

- `deploy/images/caddy/Dockerfile` (modules and module check), `daemon/Dockerfile` and `.github/workflows/release.yml` (pass `CORAZA_VERSION`/`CRS_VERSION`), `deploy/images/README.md`, the dev setup that builds and pins launchroom-caddy locally (`dev/`, Makefile target).
- `daemon/internal/policy` (WAF version pins), `daemon/internal/ingress/caddy` (rendering, 403 page, fallback, golden files), new `daemon/internal/waf` (receiver, aggregation, buffer, ack), `daemon/cmd/launchroomd` wiring (`LR_INGRESS_WAF_LOG_ADDR`, report fields, features in `state.SupportedFeatures`: `waf`, `inventory`, `containerInspection`).
- New `daemon/internal/inventory` (task handler, clone reuse, lockfile analysers, LLB scan solve, SPDX normaliser, Next.js probe), `daemon/internal/build` changes needed to share the clone and BuildKit client code.
- New `daemon/internal/inspect` (task handler, classification), `daemon/internal/docker` (`ContainerChanges`, `ContainerTop`), `daemon/internal/guard` (two routes, tests, fuzz seeds), `daemon/internal/tasks` (registration, runners).
- Docs it updates when details settle: [03](03-daemon-protocol.md) §13 (implementation notes only), [05](05-networking-ingress.md) §4 module list and §7 ports, [09](09-security.md) §6 guard table, §6.7 budgets with measured numbers.

Order: (1) Caddy image with the module and the dev pin; (2) rendering and golden files against `node-state.waf.json`; (3) receiver and aggregation against captured audit records, producing `report.waf.json`-shaped output; (4) guard routes, then `inspect_container`; (5) `inventory`; (6) integration tests and performance numbers.

### 16.2 Dashboard

Owns:

- Migrations, models, enums (`SecurityFindingStatus`, `InventoryStatus`, `WafMode`, reason codes), Data objects mirroring the protocol types (`App\Data\Daemon\…`), policies, `DaemonTaskType`/`DaemonFeature` cases.
- `App\Actions\Security\…`: inventory task creation and completion, `CheckInventoryAdvisories`, `SyncAdvisories`, `SyncKnownExploited`, `SyncLifecycles`, `EvaluateSecurityFindings`, `DismissFinding`, `ReopenFinding`, `StartInvestigation`, `CompleteContainerInspection`, `BuildInvestigationReport`, `SetWafMode`, `AddWafExclusion`, `RemoveWafExclusion`; jobs and console commands of §3–§4; `RecordReport` ingestion of `metrics.waf` and `ingress.waf`; registry token rule; NodeState rendering of `routes[].waf`; `AlertRule` cases.
- UI: `resources/js/pages/security*`, `features/security/*`, the sidebar entry, the service panel's Web firewall section, Settings → Security card; API controllers, OpenAPI, `cli/` commands.
- Docs: [07](07-data-model.md) tables, [08](08-dashboard-ux.md) navigation, [15](15-observability.md) rules table, [16](16-api-and-cli.md) endpoints; the site pages of §17 once the UI labels are final.

Order: (1) migrations, models, Data mirrors validated against the fixtures; (2) feeds and matching with recorded responses; (3) level rules and findings; (4) NodeState rendering and report ingestion against `report.waf.json`; (5) task creation and completion against the completion fixtures; (6) investigation; (7) UI, alerts, API, CLI; (8) site docs.

#### 16.2.1 Dashboard implementation notes

Where the dashboard differs from the text above:

- Array columns are `jsonb` lists instead of `text[]`. Extra columns: `inventory_components.osv_version`; `advisory_queries.modified`; `advisory_feeds.failing_since`, `last_modified`, `backoff_seconds`, `retry_at`, `manual_check_at`; `security_findings.kind`, `scope`, `presence`, `direct`, `checks`, `attack_vector`, `severity`, `cvss_score`, `fixed_version`, `known_exploited_since`, `public`, `level_changed_at`; `security_investigations.automatic`, `replicas`; `servers.waf_status`.
- The source switches are `advisory_feeds.enabled` (Settings → Security → Vulnerability data); there is no `instance_settings.security`.
- Inventories are requested when a deployment goes live (listener on the live event, plus an hourly backfill for live deployments without one), not at build success, and the task isn't pinned to the building server. The update flow doesn't trigger a backfill; the hourly one covers it.
- Version ordering is launchroom's own (`App\Support\Security\VersionOrder`), no composer/semver dependency. OSV records are fetched one after another (within the limit of 4 in parallel).
- Preview environments aren't evaluated at all, so the reason `preview` exists only in the rules.
- Investigations end as `partial` or `completed`; `failed` is unused (a failed replica makes the report partial). Console and SSH sessions are summarised per person.
- CRS rule messages are shown for the common rules only (`App\Support\Security\CrsRules`); others show the rule id and category.
- "Allow requests like this" in a blocked request's sheet adds the exception directly (with Edit first to change it in the service settings).
- The CLI exit code for `--fail-on action-needed` is 12 (3 is already "not signed in").
- The service panel's "Action needed" chip opens the finding when the instance has one open "Action needed" finding, and `/security?service=svc_…` when it has several (with the count).
- OSV records without a `summary` (Bitnami's) take the first sentence of `details`; CVSS vectors are also read from `affected[].severity`, and a CVSS v4 vector gives the attack vector when there is no v3 one. Runtime binaries (`binary`, Bitnami's SEMVER ranges) get a "Fixed in" version like npm, Go and Packagist; their next step is to build with the fixed runtime version.

### 16.3 Meeting point

Neither side waits for the other: the dashboard tests with the fixtures and faked completions, launchroomd with golden files and recorded Coraza records. The first joint run is the dev stack's integration test (a build → inventory → finding with an injected OSV fixture; a blocked request → Security page chart), done by whoever integrates.

First joint run (2026-10-25, dev stack with the pinned launchroom-caddy, fixture `node-vulnerable`): the edges announced `waf`, `inventory` and `containerInspection`; a deploy produced an inventory on the build server (lodash `runtime` and direct, minimist `dev`, npm's own packages `unknown`, node as a runtime binary, 87 Debian packages); real osv.dev and KEV data raised "Action needed" for lodash (GHSA-35jh-r3h4-6jhm) and node 22.23.3 (BIT-node-2026-111497) and kept minimist (dev), npm's packages (unknown scope) and OS packages in "Worth knowing"; SQL injection, XSS, path traversal and a scanner user agent got the 403 page with a reference id, and their events reached the chart, "By kind of attack" and Recent blocks with /24 networks only; an exception from "Allow requests like this" let the matching request through; Log only passed attacks and counted them as "Would have been blocked"; an investigation of the fresh container found nothing, and one after a file was written to `/tmp` and run reported both. Fixed on the way: Docker's init mount and parent directories reported as changed system files, npm's `sh -c` script shell reported as an unexplained shell (docs/03 §13.2), Bitnami records without summary, fixed version or attack vector (§16.2.1), and the dev fake servers' busybox `ps` (procps added, so dev sees process parents like Debian and Ubuntu hosts).

## 17. Site docs outline

A new section **Security** in `site/content/docs/security/` (sidebar group "Operate", after "Teams and security"):

| Page | Content |
|---|---|
| `index.mdx` "Security overview" | What the Security page shows and where each part is explained |
| `vulnerability-checks.mdx` | Where package data comes from, the sources, what is sent to osv.dev, how often, switching sources off |
| `alert-levels.mdx` | When something is "Action needed" and when it's "Worth knowing", with every rule and reason in words |
| `inventory.mdx` | Searching packages and runtimes, scopes, images launchroom didn't build, version tracking |
| `web-firewall.mdx` | Modes, defaults, paranoia levels, the body inspection setting, what isn't inspected (bytes past the body limit, fields past the 256th) and that the firewall is a filter, not a guarantee (§6.7), what is logged and kept, performance, proxies and client networks |
| `firewall-exceptions.mdx` | Handling a blocked legitimate request: the reference id, adding and removing exceptions |
| `investigations.mdx` | What an investigation checks, how to read the report, what it can't prove, the next steps |
| `responding-to-a-vulnerability.mdx` | A walkthrough from the notification to a fixed deployment, including restoring data and changing secrets (and the manual database password change) |
| `privacy-and-data.mdx` | What launchroom stores about requests and for how long, what leaves the instance |
