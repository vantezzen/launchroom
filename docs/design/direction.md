# Design direction: "a product, not a dashboard"

The reasoning, references and page targets behind the binding rules in [../design-system.md](../design-system.md) (D32). The user journeys are in [flows.md](flows.md).

## 1. The direction in one page

Restraint in the **chrome**, life in the **content**.

| Rule | Why |
|---|---|
| **Objects are cards, settings are rows.** Servers, projects, deployments, environments, previews, destinations and infrastructure groups are cards. Forms and long lists stay flat. | Things you own should feel like things. Cards give each object a picture, a status and a place for its numbers. |
| **One illustration family: devices.** 3D server renders (§7) plus monochrome logos for frameworks, databases and providers. No stock art, mascots or decorative blobs. | A server is easier to recognise as a picture; logos are how developers recognise their stack at a glance. |
| **Colour for status, data and identity.** Monochrome chrome; one signal blue for interaction and the primary data series; a validated categorical palette for breakdowns. | Charts and stacks are unreadable in grey; a few honest colours make pages scannable. |
| **Picture → number → words.** Each object leads with a visual (device, mini canvas, preview) or a number (34%, 1m 12s), then the name, then supporting text. Prose moves to hints. | People glance before they read. |
| **Motion for continuity.** Page cross-fades, shared-element morphs (node → panel, row → detail), edges drawing on connect. Nothing decorative; off with reduced motion. | Keeps your place between canvas, deployments and logs. |
| **One container, three sizes, one rule for full-bleed** (§3). | Pages line up with each other. |

Constant across the product: the sidebar shell, Geist, sentence case, app vocabulary (never Docker words in primary UI, never "agent"), status always with a non-colour cue, Detected + Override, everything has a URL, light and dark parity.

## 2. What we take from each reference

| Reference | We take | We leave |
|---|---|---|
| **Umbrel storage manager** | Mode choice as two large selectable cards with a check; device imagery next to the thing it describes; donut with the total in the middle and "total / free" stacked beside it. → Backups destination card, server role cards, restore mode choice. | The glassy floating window and blurred wallpaper. |
| **Umbrel cloud connection** | "Choose your X" grid of large logo tiles, one row of text each, "Coming soon" as a muted tile with a chip, and the odd-one-out option as a full-width row ("Connect a WebDAV server…"). → Add service, choose database, backup provider, git provider, add-server machine type. | |
| **Umbrel Machines** | Hero image of the thing being created with a live summary chip under it ("1 core · 0.5 GB · 2 GB"); stepper inputs (− value +) with one helper line; a warning callout bottom-left next to the primary action. → Server detail hero, scaling section, add-server, import repository. | Skeuomorphic retro icon style. |
| **Umbrel share folder** | Toggle-first settings (the main switch at the top, details below), and "how to access" with a platform switcher and an illustrated mini-screenshot. → Add server (Ubuntu / other Linux / Docker installed), connect a domain (provider-specific hints). | |
| **Vercel deployment details** | Overview card first (preview, created, status, duration, environment, domains, source), then collapsible step rows with status icons and durations on the right, then related-feature cards. → Deployment detail. | The secondary left nav per section (we have the sidebar plus project tabs). |
| **Vercel CDN overview** (maintainer favourite) | Region list next to a dotted world map with bubbles; metric cards each holding 2–3 stacked sparklines with value and delta chips; a time-range select top right. → Servers map, server detail tiles, project metrics. | Dense metric walls on pages where we have no data yet. |
| **Vercel logs** | Histogram timeline on top, filter rail with counts on the left, dense monospace table, detail drawer on the right, "Live" toggle. → Project Logs tab (log explorer), also the shape of the deployment build log search. | Request-centric columns (request metrics are aggregates, not per-request logs, §8). |
| **Laravel error page** | A technical page made beautiful: big human title, a mono path under it, chips for context, framed sections, code with line numbers and one highlighted line. → Explained failure card and the error excerpt on deployments; server health detail. | Framework-specific trace UI. |
| **Laravel Cloud worker cluster** | Right side sheet with clearly separated sections, toggles aligned right, process rows, and the **floating "Unsaved changes · Reset · Save" bar** at the bottom. → Service panel Settings and Variables tabs, settings pages (§5.7). | Price badges. |
| **Railway service deployments** | Service panel over the visible canvas; the active deployment as a highlighted card with status pill and "View logs"; history as a quieter list below. → Service panel. | The heavy dark-purple theme and repeated full-width history cards. |
| **Mintlify previews** | Overview with a preview beside key facts; empty state with a single icon and one line. → Deployment overview card, preview cards, every empty state. | |
| **Laravel Cloud environment** | Grouped infrastructure cards (Network / Edge / Domains / App cluster) each with status rows, an "Add resource" slot, latest deployments under the diagram, and a floating toolbar on the canvas. → Canvas groups, node anatomy, canvas toolbar, project overview. | The fixed column layout (our canvas stays freeform). |

## 3. Page layout system

### 3.1 Shell (kept)

Sidebar (team switcher, **search field "Search ⌘K"**, Get started while incomplete, Projects, Servers, Activity, Settings, user) plus the inset main surface (`md:m-2 rounded-xl`).
- The visible search field opens the palette.
- **Activity** is a team-wide feed; project activity is a filter of it.
- Settings shows a quiet dot when an update is ready (owners).

### 3.2 One top bar for every page

Every page starts with the same **52 px top bar**, `px-6` (16 px on phones), hairline bottom border:

| Page type | Left | Right |
|---|---|---|
| Team pages | Breadcrumb: `Servers / fra-1` | Page-level secondary actions (`Replace`, `⋯`) |
| Project pages | `project ▾` / `environment ▾` (env pill with its status dot), then the project tabs inline as a segmented row: **Canvas · Deployments · Logs · Metrics · Previews · Settings** | The staged-changes button ("4 changes · Review & deploy") or nothing |

The project top bar is one row: tabs as a segmented control in the bar leave more height for the canvas and align project pages with team pages. Page titles live in the content, not in the bar.

### 3.3 Content container: three sizes

All non-full-bleed pages render inside one `<Page>` container: centred, `padding: 32px 32px 64px` (desktop), `24px 24px 48px` (tablet), `20px 16px 48px` (phone).

| Size | Max content width | Use |
|---|---|---|
| `wide` | 1280 px | Overviews and lists: projects, servers, deployments, previews, activity, project overview, **deployment detail**, server detail |
| `narrow` | 768 px | Guided flows and wizards: get started, import repository, add server, replace server, recovery, invite acceptance |
| `settings` | 200 px nav + 40 px gap + 800 px content, centred as one block (= 1040 px) | Team settings and project settings (one shared layout, §5.12) |

Rules:
- **Every block on a page uses the same container.** No full-width header above a capped body. Logs inside a deployment are a card in the container.
- Page header anatomy (always the first child): title (28/34, 600, `-0.025em`), one-line description (muted, ≤ 80 characters, may contain live counts: "6 servers in 4 places · 5 online"), actions right-aligned and bottom-aligned with the title. At most one primary action.
- Section spacing: 40 px between sections, 14 px between a section header and its content, 16 px grid gaps.
- No breadcrumb inside content; the top bar carries it.

### 3.4 Full-bleed pages, and only these

| Page | Why |
|---|---|
| Project canvas | An infinite pannable surface is the focal object. |
| Project logs explorer (and its per-service variant in an expanded panel) | Dense data, wide tables, three columns (rail, table, drawer). |

Everything else is contained. The service panel over the canvas is a floating sheet (12 px inset from the canvas edges, 16 px radius, float shadow) so the canvas visibly continues behind it (Railway); expanded it becomes a `wide` page at the same URL.

### 3.5 Transitions and motion

Duration tokens: `fast` 120 ms (hover, press, toggles), `base` 180 ms (cross-fades, popovers), `slow` 240 ms (sheets, panels, page-level morphs). Easing: `cubic-bezier(.2,.8,.2,1)` for enter, `cubic-bezier(.4,0,1,1)` for exit. Nothing over 300 ms except the edge draw-on (300 ms) and number tickers (400 ms).

| Moment | Motion |
|---|---|
| Page change within the same shell | Content cross-fade 180 ms with a 4 px upward settle; the top bar and sidebar never move. Implemented with the View Transitions API (`document.startViewTransition` around Inertia visits; fallback to a CSS fade). |
| Canvas ↔ Deployments ↔ Logs (project tabs) | The top bar stays; the tab indicator slides (120 ms); content cross-fades. Canvas state (zoom, selection) is kept when you come back. |
| Canvas node → service panel | The panel slides in from the right (240 ms); the node's logo morphs into the panel header logo (shared element `view-transition-name: svc-<id>`). The canvas pans so the selected node stays visible left of the panel. |
| Deployment row/card → deployment page | Status pill and title morph into the page header. |
| Status change on a node or card | 180 ms cross-fade of the status line; progress bars animate width; numbers tick. |
| New node / server card / connection | Fade + 8 px scale-in (180 ms); edges draw on (stroke-dashoffset, 300 ms). |
| Toasts, popovers, menus | 120–180 ms fade + 4 px slide from their anchor. |

Not allowed: pulsing dots (a "Live" indicator is a static filled dot plus the word), parallax, confetti, typing effects, looping shimmer. Skeletons fade in after 300 ms of waiting (no shimmer).

`prefers-reduced-motion`: all transitions become instant; spinners become a static icon; progress bars jump.

## 4. Visual language

### 4.1 Colour

**Chrome stays monochrome**: Geist neutral scale, black/white primary buttons, grey borders.

Colour roles (tokens in [../design-system.md](../design-system.md) §3):
- **Signal blue** (`--signal`, light `#0062d1`, dark `#47a8ff`): links, focus rings, selection, the primary series of single-series charts, map bubbles, the staged state (always with a dashed outline: dashed = staged). One blue across the product.
- **Status** green/amber/red/grey, each with an icon or label. Status soft backgrounds (`--status-ok-soft` etc.) for pills and callouts.
- **Categorical data palette**, 8 slots in fixed order, validated for colour-vision deficiency on our surfaces (white and `#111`) with the dataviz validator: blue, orange, aqua, yellow, magenta, green, violet, red (§8.4). Used for "which project uses what" breakdowns and multi-series charts only. A project keeps its slot everywhere (assigned by creation order, stored), so "shop" is always blue.
- **Identity**: framework, database and provider logos are monochrome on a neutral tile (D32); no brand colours anywhere.

Never: gradients on chrome, gradient text, glows, colour to make a number look good.

### 4.2 Elevation

Three levels, same in both themes:

| Level | Light | Dark | Used for |
|---|---|---|---|
| 0 page | `#ffffff` on the `#fafafa` frame | `#0a0a0a` on `#000` | Page surface inside the inset |
| 1 card | `#ffffff`, 1 px `#ebebeb` border, `0 1px 2px rgba(0,0,0,.04)` | `#111111`, 1 px `#1f1f1f` border, no shadow | Cards, tiles, canvas nodes, groups |
| 2 float | card colour + `0 8px 30px rgba(0,0,0,.10)` | `#111` + `0 8px 30px rgba(0,0,0,.6)` + 1 px `#2e2e2e` ring | Sheets, panels, popovers, menus, toolbar, toasts, floating bars |

Recessed areas inside cards (device stage, log excerpts, code) use `--recessed` (`#fafafa` / `#0d0d0d`). Dark mode gets its separation from the card step (`#0a0a0a` → `#111`).

### 4.3 Radius

Controls and chips 8 px (pills full), cards and tiles 14 px, sheets, panels and dialogs 16 px, canvas nodes 14 px, canvas groups 18 px. One radius per element class.

### 4.4 Typography

Geist Sans / Geist Mono, self-hosted. Roles in [../design-system.md](../design-system.md) §2: `display` 32/38 600 `-0.03em` (heroes: get started, server detail name), `title` 28/34 600 `-0.025em` (page titles), `stat` 24/30 600 tabular (metric values). Mono shrinks in prose contexts: hostnames and SHAs inline use `mono-small` at muted colour unless they're the subject.

### 4.5 Iconography

`lucide-react`, 16 px in UI and 1.75 stroke (matches Geist's weight); 20 px in tiles. Status icons are part of `<Status>`. Framework/database/provider logos come from **simple-icons** (CC0 data; the marks remain their owners' trademarks and are used nominatively to identify the user's own stack), rendered monochrome in a `<Logo kind="nextjs" />` tile. Unknown kinds fall back to a monogram on the same tile.

### 4.6 Illustration

- **Devices** (§7) are the only illustrations in product UI.
- **Empty states** use a composition of existing parts instead of art: a 48 px muted lucide icon or a ghosted version of the object that will appear (a dashed server card with the "unknown" device silhouette; a dashed project card with framework logos).
- No stock illustrations, no characters, no 3D blobs, no decorative patterns. The canvas dot grid remains the only pattern.

## 5. Component vocabulary

All live in `resources/js/components/` (primitives) or `components/patterns/` (composites). APIs: [../design-system.md](../design-system.md) §13.

### 5.1 Page primitives
- `Page` (`size="wide|narrow|settings|full"`), `PageHeader` (title, description, actions, optional `media` slot for heroes), `Section` (title, description, actions, children), `TopBar` (team or project variant).
- `EmptyState` (icon or ghost slot, one line, one action, optional secondary link). Copy formula: what will be here + how it gets here. "No previews yet. Open a pull request to get one."

### 5.2 Cards
- `ObjectCard`: media slot on top (150 px recessed stage), header (name, status, top-right meta), chips row, body slots, footer facts row. Variants: **ServerCard** (device + provider/location chip + last seen; CPU/memory sparklines; "used by" meter and legend; specs footer; unreachable variant with explanation callout), **ProjectCard** (mini canvas thumbnail rendered from the stored layout; name, status, primary domain; last deploy line with avatar, message and age; environment chips; 14-day deploy bars), **PreviewCard** (URL card, PR title, author, branch → base, status, protection lock, expiry, database chip), **EnvironmentCard** (name, trigger, live revision, changes ahead/behind, services count).
- `DeploymentCard` (active deployment in the service panel): status pill, title (commit message), meta line (sha · branch · author · age), the step pills strip (Build ✓ · Migrate ✓ · Health ◌ · Route), actions.
- `InfraGroup` (Laravel Cloud): a titled group frame with status rows (icon, label, value/status right). Used for canvas groups (Public / App / Data) and server Health.
- Card behaviour: whole card is the link (with an inner focusable title), hover lifts the border to `--border-strong` (no shadow change), focus ring 2 px signal.

### 5.3 Metrics
- `StatTile`: label, value (`stat` role) with unit in muted small, optional delta chip (green up/red down only where direction has a meaning; otherwise neutral "avg 29%"), sparkline (40 px area, 1.75 px line, 22 % → 0 area fill), hover crosshair + tooltip with time and value.
- `Sparkline` (inline, 16–30 px, no axes), `MiniBars` (deploy frequency: 14 bars, failures in red, today in signal blue).
- `UsageMeter`: segmented bar with 2 px gaps, categorical colours, legend with values; "free" as the empty remainder.
- `Donut`: total in the centre, legend beside with values; max 4 segments + "other".
- `TimeRangeSelect`: Hour / Day / Week segmented (consistent everywhere).

### 5.4 Steps and timelines
- `StepList` (Vercel deployment): collapsible rows with chevron, status icon (✓ filled green, ✕ filled red, spinner amber, dashed hollow for skipped/pending), name, summary text, chips (error count), duration right-aligned tabular. The failing step auto-expands to an **ErrorExcerpt** (±5 lines, line numbers, highlighted line with a red left rule) and "Show all N lines".
- `StepPills`: the compact horizontal version for cards and canvas nodes.
- `RunStrip`: one bar per day/run (backups, health history), green/red/grey with tooltips.
- `ActivityItem`: avatar, sentence ("**mara** deployed **api** to production"), age; grouped by day.

### 5.5 Log explorer
`LogExplorer` with: query bar (`service:api level:error "timeout"` with chips for parsed filters), time range, Live toggle; **histogram** (one bar per bucket, errors stacked in red, deploy markers as signal-blue ticks with labels); **filter rail** with counts (level, service, deployment, server, stream); virtualized monospace table (time with the seconds emphasised, level chip, service + replica, message, ANSI toned down); **detail drawer** (message wrapped, facts box, "similar lines", show context). Build logs on the deployment page reuse the table and search in a contained card.

### 5.6 Choice and guided setup
- `ChoiceTiles` ("choose your X"): 2–4 columns of logo/device tiles, selected = 1.5 px foreground ring, disabled tiles with a chip, optional full-width "other" row.
- `OptionCards` (mode choice, Umbrel storage): two to three large cards with title, one sentence, a check in the corner.
- `Stepper` (− value +) with helper text and the live consequence ("fits on 2 servers").
- `Wizard`: numbered step header ("1 What is it · 2 What it does · 3 Connect"), one card per step, earlier steps collapse to a summary line; the URL carries the step.
- `LiveWait`: illustration (device with LED off / logo), status line with spinner, "usually N seconds", the "not working?" link after a timeout.

### 5.7 Sheets, panels and the floating bar
- `Sheet` (right, 480/560/720 px, float level, 16 px radius, 12 px inset on desktop, full screen on phones): header (logo, title, status, close), sections with headings and dividers, toggles right-aligned (Laravel Cloud).
- `ServicePanel`: a `Sheet` over the canvas with tabs **Deployments · Variables · Metrics · Logs · Settings** (+ Console, Data and Backups where they apply, [../08-dashboard-ux.md](../08-dashboard-ux.md) §1).
- `UnsavedBar` / `StagedBar`: floating, centred at the bottom of the sheet or page, inverse colours (black in light, white in dark): "⚠ Unsaved changes · Reset · Save" for instant settings, "2 changes · api will restart · Discard · Deploy" for staged ones. It replaces per-section Save buttons.

### 5.8 Canvas
See §9.4 for the full spec: `ServiceNode`, `GroupFrame`, `ConnectionEdge` with a label pill, `CanvasToolbar` (floating bottom-centre: Add, Fit, Tidy, zoom, ⌘K), `Minimap` (floating bottom-right, real node rectangles, viewport in signal blue).

### 5.9 Feedback
- **Toasts** (sonner, bottom-right): icon + one line + optional action (Undo, Open). Success toasts for async outcomes only ("web is live · 1m 12s · Open"), never for synchronous saves that are visible anyway.
- **Callouts**: neutral (info), warning, error; icon, bold first sentence, one more sentence, actions. Never a red border around a whole section.
- **Confirm popover** for reversible-but-consequential actions (rollback, remove member); **dialog with typed confirmation** only for irreversible data loss.
- **Command palette**: rich rows (logo, status, environment), context ranking and verbs (flows §17).

### 5.10 Servers on a map
`ServerMap` (§9.2): dotted world map (static SVG of land dots generated at build time from Natural Earth 110 m data, public domain), location bubbles sized by server count, edges marked with a square ring, mesh links as arcs (solid = direct, dashed = relayed), a region list with counts. Hidden on phones (the list stays).

### 5.11 Status
`<Status>` keeps the dot/icon + label rule. A `StatusPill` variant (soft background) for headers and cards. Unified vocabulary in [../design-system.md](../design-system.md) §3.3.

### 5.12 Settings layout
One layout for team and project settings: grouped left nav (Team: Members, Git, Backups, Notifications, Dockerfile generation · This launchroom: Updates, Registry, Dashboard domain, Network, Security · You: Profile, Security, API tokens, Appearance), content 800 px, each section a titled block with its controls; descriptions one line; long explanations behind "Learn more". Instant settings show the `UnsavedBar`; destructive actions live in a final "Danger zone" card with outline-red buttons.

## 6. Writing

Full rules in [../design-system.md](../design-system.md#voice-and-copy).


- Lead with the outcome, then the reassurance, then the detail: "Build failed: pnpm lockfile is out of date. Your site is still serving the previous version."
- One sentence per description; anything longer becomes a hint, a "Learn more" link or docs.
- Counts in page descriptions: "6 servers in 4 places · 5 online · 1 unreachable".
- Use the unified status words (design system §3.3).
- Name the magic once, plainly: "It connects out, so there's nothing to open." Not on every page.

## 7. Server imagery

### 7.1 The set

Ten device kinds, drawn from seven renders:

| Key | Render | Typical machines |
|---|---|---|
| `cloud` | server | Any VPS: Hetzner Cloud, DigitalOcean, AWS, Vultr… |
| `dedicated` | server | Hetzner dedicated, OVH, Supermicro, PowerEdge, ProLiant |
| `tower` | tower | Workstations, gaming PCs used as build boxes |
| `minipc` | nuc | Intel NUC, Beelink, Minisforum, ThinkCentre Tiny, OptiPlex Micro |
| `compact` | mac | Mac mini, Mac Studio (never the Apple logo) |
| `sbc` | raspberry-pi | Raspberry Pi, Orange Pi, Rock, ODROID, Jetson |
| `nas` | nas | Synology, QNAP, TerraMaster, Asustor |
| `laptop` | laptop | Laptops used as servers |
| `vm` | server | Local VMs (Proxmox guests, VirtualBox, Parallels, OrbStack) |
| `unknown` | server, as a flat silhouette | Before facts arrive; pending enrolments |

### 7.2 Art direction

The art spec (renders, processing, LED overlay, states) is in [../design-system.md](../design-system.md) §7.1, the component and pipeline in §13.8. Rules for using it:

- **Sizes**: 64 px (lists, palette, wizard summary), 120 px (choice tiles), 200–232 px (card stage), 300 px (detail hero). Same asset at every size, never scaled on one axis.
- **Theme**: identical art in both themes, always on the recessed stage, which keeps contrast in dark.
- **Consistency check**: the contact sheet (`dashboard/scripts/device-art/sheet.py`) shows every render side by side; shadows on one baseline, LEDs at similar positions, no device visually heavier than the server.

### 7.3 Detecting the device

launchroomd reports a `hardware` block in the host facts, read from the filesystem only (no network calls, no shell):

| Fact | Source (Linux) |
|---|---|
| `cpuModel`, `cpuVendor` | `/proc/cpuinfo` (`model name`, `vendor_id`; ARM: `Hardware`, `CPU implementer`) |
| `dmi.sysVendor`, `dmi.productName`, `dmi.productFamily`, `dmi.boardVendor`, `dmi.boardName`, `dmi.chassisType` | `/sys/class/dmi/id/*` (world-readable fields only; never serials) |
| `deviceTreeModel` | `/proc/device-tree/model` (SBCs, Apple Silicon under Asahi) |
| `virtualization` | `/sys/hypervisor/type`, CPU `hypervisor` flag, DMI product/vendor ("KVM", "VMware", "VirtualBox", "Parallels", "Apple Virtualization"), `/.dockerenv` / cgroup hints for "container" |
| `osName` | `/etc/os-release` `PRETTY_NAME` |
| `kernel` | `uname` via syscall |
| `timezone` | `/etc/timezone` or the `/etc/localtime` link target |
| `battery` | `/sys/class/power_supply/*`: any `type` Battery whose `scope` isn't Device (laptops; peripherals excluded) |
| `memoryMb` | `/proc/meminfo` |

The dashboard maps facts to a device and a provider (pure function, unit-tested, table-driven, first match wins):

| Order | Condition | Device |
|---|---|---|
| 1 | `deviceTreeModel` matches Raspberry Pi / Orange Pi / Rock / ODROID / Jetson / Pine | `sbc` |
| 2 | `sysVendor` is Synology, QNAP, TerraMaster, ASUSTOR, or the OS / kernel is a NAS system (Synology DSM, QNAP QTS/QuTS, TrueNAS `+truenas`, Unraid `-Unraid`), virtualized or not | `nas` ("TrueNAS", "Unraid", "Synology NAS", "QNAP NAS" when the maker's model is unknown) |
| 3 | `sysVendor` "Apple Inc." and product `Macmini*`/`Mac1[34],*`, or device tree "Apple Mac mini/Mac Studio" | `compact` |
| 4 | Virtualized and the provider is known (below) | `cloud` |
| 5 | Virtualized, provider unknown | `vm` |
| 6 | A system battery (`battery`), `chassisType` 8, 9, 10, 11, 14, 30, 31, 32 (portable, laptop, notebook, hand held, sub notebook, tablet, convertible, detachable) or a MacBook | `laptop` |
| 7 | `chassisType` 15, 16, 24, 34, 35, 36 (space-saving, lunch box, sealed-case, embedded, mini PC, stick PC) or product matches NUC / Mini PC / Tiny / Micro / Beelink / Minisforum / GMKtec | `minipc` |
| 8 | `chassisType` 17, 23, 25, 28, 29 (main server chassis, rack mount, multi-system, blade, blade enclosure) or vendor/product Supermicro, PowerEdge, ProLiant, ThinkSystem | `dedicated` |
| 9 | `chassisType` 3, 4, 6, 7, 13 (desktop, low-profile, mini tower, tower, all-in-one) | `tower` |
| 10 | Not virtualized, nothing else matched | `dedicated` |
| (none) | No hardware facts yet | `unknown` |

Provider from DMI: Hetzner (`sysVendor` Hetzner), DigitalOcean, Amazon EC2, Google Compute Engine, Microsoft (product "Virtual Machine") = Azure, Linode/Akamai, Vultr, OVHcloud, Scaleway, Oracle Cloud, UpCloud, Exoscale; unknown → "Cloud server". Not virtualized + Hetzner/OVH → dedicated at that provider.

**Docker Desktop / OrbStack on a Mac**: launchroomd runs inside a Linux VM, so DMI shows the hypervisor, not the Mac. It maps to `vm` ("Virtual machine"). Users can correct it (below).

**Always overridable**: server settings get "Looks like: [Mac mini ▾]" (device picker with the ten tiles) and "Location: [Berlin, at home ▾]". A user choice is stored and wins over detection. This is the "magic with an escape hatch" rule.

**Location** for the map and the card chip: suggested from the provider (when DMI or the hostname reveals a region) and the server's time zone (`Europe/Berlin` → Berlin, `App\Support\Servers\ServerLocations`), confirmed or edited by the user once ("Is fra-1 in Berlin? Yes · Change" on the server page). No IP geolocation: self-hosted installs don't phone home.

## 8. Data visualisation

### 8.1 What we collect

| Data | Where it comes from | Spec |
|---|---|---|
| Server CPU %, memory, disk, network rx/tx, load | launchroomd samples every 15 s, ships per-minute aggregates in its reports | [../07-data-model.md](../07-data-model.md) "Usage metrics", [../03-daemon-protocol.md](../03-daemon-protocol.md) §5 |
| Per-workload CPU and memory | cgroup v2 files of launchroom's labelled containers, same tick | same |
| Requests, status classes, latency histogram, bandwidth per service | Caddy access logs on each edge, aggregated per route and minute by launchroomd | [../15-observability.md](../15-observability.md) §1 |
| Host hardware facts | §7.3 | `servers.facts`, plus the user's device and location overrides |
| Deployments and steps | queue/start/finish times, status, failure class, build server, per-step durations | [../04-build-and-deploy.md](../04-build-and-deploy.md) |
| Backups | run sizes and durations, snapshot listings | [../14-backup-and-restore.md](../14-backup-and-restore.md) |
| Logs | build and step logs, live tails, opt-in runtime retention | [../08-dashboard-ux.md](../08-dashboard-ux.md) §12 |

Metric samples are stored per minute for 48 h, as 10-minute rollups for 30 days and as daily rollups for a year. Preview screenshots are not collected; previews show a URL card.

### 8.2 Endpoints

JSON, authorized through team relations: `GET /servers/-/metrics?range=1h|24h|7d` (all servers, downsampled), `GET /servers/{server}/-/metrics?range=…` (host + per-workload), `GET /p/{project}/-/metrics?environment=&service=&range=…` (per service, with requests). The report broadcast (`server.metrics`) nudges clients to refetch; without Reverb they poll every minute. Details in [../15-observability.md](../15-observability.md) §2.1.

### 8.3 Charts per page

| Page | Charts | Data |
|---|---|---|
| Servers | Per card: CPU and memory sparklines (last hour), the top projects by memory, disk in the footer when ≥ 80 %. System load chart, map with bubbles and mesh arcs, topology. | Usage metrics, mesh peers |
| Server detail | Usage stat tiles with sparklines; "what runs here" usage meter + per-workload rows; health; the Metrics tab's charts | Usage and request metrics, health |
| Projects | 14-day deploy bars per card (failures red, today blue); mini canvas thumbnail | Deployments |
| Canvas nodes | Node footer: CPU sparkline and memory; replicas and servers; database nodes: last backup | Workload metrics, backups |
| Service panel → Metrics tab | Requests, error rate, latency when there is traffic; CPU and memory (per replica when there are several); deploy markers on every chart | Usage and request metrics |
| Project → Metrics | Six metric cards and the selected card's chart with deploy markers | Usage and request metrics |
| Deployments list | Duration bar per row relative to the median of the service; a header strip "12 deploys this week · 1 failed · median 1m 20s" | Deployments |
| Deployment detail | Build history sparkline in the related cards; step durations as text | Deployments |
| Logs explorer | Histogram of lines per bucket, errors stacked, deploy markers | Log chunks; runtime lines need opt-in retention |
| Backups | Donut of stored size by kind; per-item 30-day run strip; size trend per database | Backup runs and snapshots |
| Updates | None; status hero and step list | |

### 8.4 Chart rules

- Single-series charts use signal blue; multi-series uses the categorical palette in fixed order (blue `#2a78d6`/`#3987e5`, orange `#eb6834`/`#d95926`, aqua `#1baf7a`/`#199e70`, yellow `#eda100`/`#c98500`, magenta `#e87ba4`/`#d55181`, green `#008300`, violet `#4a3aa7`/`#9085e9`, red `#e34948`/`#e66767`; light/dark). Validated with the dataviz validator against `#ffffff` and `#111111`: all checks pass; three light slots (aqua, yellow, magenta) are below 3:1 on white, so those series always carry a visible label or legend value.
- More than 4 series fold into "other". A project keeps its colour slot everywhere.
- 1.75 px lines, area fill 22 % → 0, no gridlines in sparklines, recessive axes (muted, 11 px) in full charts, one y-axis only, tabular numbers.
- Every chart has a hover tooltip (time + value) and a text alternative (the stat value above it, or a table view for full charts).
- All charts, full-size ones included, are small hand-written SVG components (`components/charts`): one look, keyboard crosshair and table view built in.

## 9. Page-by-page targets

ASCII sketches are wireframes. What each page shows in detail is in [../08-dashboard-ux.md](../08-dashboard-ux.md).

### 9.1 Servers
```
Servers                                             [+ Add server]
6 servers in 4 places · 5 online · 1 unreachable
┌──────────────┐ ┌──────────────┐ ┌──────────────┐   3 columns ≥ 1100 px,
│ chip    12s  │ │  [device]    │ │  [device]    │   2 ≥ 720, 1 on phones
│   [device]   │ │              │ │              │
├──────────────┤ ├──────────────┤ ├──────────────┤
│ fra-1 ●  …   │ │ hel-1 ●      │ │ studio ◐ Building
│ Edge Runtime │ │ Runtime      │ │ Build        │
│ CPU 34% ~~~  Memory 5.1/8 ~~  │ │ [shop/web building 64% ▬▬▬▬▬]
│ Used by ███▆▃  shop 38% blog… │ │              │
│ 4 cores · 8 GB · 160 GB  Disk │ │ 2 build slots│
└──────────────┘ └──────────────┘ └──────────────┘
Overview: System load │ map · How it fits together │ At a glance
```
- Card order: problems first (unreachable, updating), then the control-plane server, then by name.
- Unreachable card: greyed device, "No report for 8 min", what was placed there, "What to check" and "Replace server".
- Pending enrolment: a card with the `unknown` silhouette and "Waiting to connect".
- Revoked servers leave the grid; a "Revoked (1)" link at the bottom shows them.
- Private network, public traffic and certificates live on Settings → Network.

### 9.2 Server detail
Hero (device 300 px on a recessed stage, name in `display`, status pill, one line "Hetzner Cloud CX32 in Frankfurt · runs this dashboard · joined 21 days ago", role and spec chips) → tabs **Overview · Metrics · Network · Settings** ([../08-dashboard-ux.md](../08-dashboard-ux.md) §14). Overview: usage stat tiles → "What runs here" (usage meter by project, rows per workload) beside Health (problems first, all checks in a sheet) → At a glance. Settings: "What this server does" role cards (instant, with a confirm popover for removing a role that has workloads), appearance, placement, retired volumes as one row opening a sheet, Danger zone.

### 9.3 Projects
3-column `ProjectCard` grid with search; sorted by last activity. The "New project" card is always last (dashed, framework logos). Empty state: the dashed card alone, centred, with "Import a repository" primary.

### 9.4 Project canvas
- **Groups**: derived frames Public (services with an address), App (other app/worker/cron services), Data (databases). Group frame: 18 px radius, 3 % foreground fill, hairline, label top-left with icon and count. Tidy (elk auto-layout) places groups as columns left → right.
- **Node** (264 px, 14 px radius, level-1 card):
  - header: `Logo` tile, name (600), one subtitle line (framework + version · repo, or engine + version);
  - status line (`Status` with age) and, while deploying, a progress bar with the current step;
  - one key fact line: primary domain (web), private host:port (internal), "Backed up 2h ago" (databases);
  - footer strip (hairline above): CPU sparkline and memory, volume, replicas and servers ("×2 · fra-1, hel-1").
  - Selected: 2 px foreground ring. Staged: dashed signal outline 3 px offset + a "New/Changed/Removed" chip. Degraded: amber ring + chip. Failed: red status line, node stays otherwise normal ("still serving").
- **Edges**: 1.5 px, muted foreground; database connections solid, app → app solid with arrow, "shared with production" in red dashed with a chip; label pill in mono-small (`DB_* · 6`); staged edges dashed signal.
- **Toolbar**: floating bottom-centre: + Add, Fit, Tidy, zoom − 100 % +, ⌘K, and "4 changes · Review & deploy" while something is staged. **Minimap**: floating bottom-right with real node shapes, the viewport in signal blue, the staged nodes in signal.
- **Empty canvas**: the three group frames drawn empty with ghost tiles inside ("Add a repository", "Add a database"), not a modal.
- **Phone**: the canvas becomes a list of group sections with node cards (tap opens the panel full screen).

### 9.5 Service panel tabs
- **Deployments**: `DeploymentCard` for the active or in-progress deployment (status pill, message, meta, step pills, Open deployment / Cancel / View logs), then "Earlier" history rows (status dot, message, sha · age · duration, failure reason in red, Roll back on hover).
- **Variables**: table stays (it's a list of key/values) with calmer chips (icon-only lucide icons with tooltips: lock for secret, link for reference, hammer for build, plug for connection), changed rows marked staged, the `StagedBar` at the bottom, "Raw .env" and "Import" as secondary, paste-anywhere.
- **Metrics**: requests, error rate and p95 when there is traffic, then CPU and memory, with charts and deploy markers.
- **Logs**: the log explorer scoped to this service.
- **Settings**: full width with a sidebar of pages, a drill-down list on phones ([../08-dashboard-ux.md](../08-dashboard-ux.md) §16); controls aligned right; Detected + Override rows show the detected value muted with "Override"; the floating bars replace per-section Save.
- **Console** and **Data**: [../08-dashboard-ux.md](../08-dashboard-ux.md) §15.
- **Backups**: a protection card (status, schedule as a sentence, Back up now), the snapshot timeline, Restore as option cards ("As a new service" default, "Into postgres" with the consequence stated), history.

### 9.6 Deployments list
```
Deployments                        [All environments ▾] [All services ▾] [Status ▾]
12 deploys this week · 1 failed · median 1m 20s
── Today ─────────────────────────────────────────────────────────────────
┌ fix: order totals with discounts · 3b7d5e1 · pushed by bennett · production · 1h ┐
│ ● Live    [N] web                                  ▬▬▬ 1m 36s   ⋯               │
│ ✕ Failed  [N] api  Build failed: pnpm lockfile…    ▬   48s      ⋯               │
└──────────────────────────────────────────────────────────────────────────────────┘
```
Grouped by day, then one card per commit with one row per service. Filters as compact selects in the header. The serving deployment per service is marked "Live". Duration bars are relative to the service median.

### 9.7 Deployment detail
`wide` container, everything aligned: header (logo, commit message as title, "web · production · pushed by jonas 3 hours ago", actions) → overview card (URL card left; facts grid: status, duration, environment, built on; source; domains) → explained failure card (when failed) → `StepList` → related cards (Runtime logs, Configuration, Compare, Build history). The raw deployment id sits in the overview card footer with a copy button.

### 9.8 Logs explorer
Full-bleed under the project top bar. Default: last hour, all services, live off. Deploy markers on the histogram. Search runs on the server; runtime lines are searchable when the environment keeps runtime logs (opt-in, [../08-dashboard-ux.md](../08-dashboard-ux.md) §12), otherwise they are shown live.

### 9.9 Variables, staged changes and deploy
- Staged changes are a **sheet** opened from the canvas toolbar or the top-bar button (`/changes` stays as a page with the same list). Items grouped by service with `+` (green), `~` (blue), `−` (red) gutters, effect as one chip per service ("restart", "rebuild", "new"), per-item Discard on hover, message, Deploy. Blockers appear as a callout at the top with "Fix" buttons that open the variable in place.
- Revisions are on the `/changes` page (they're an audit tool, not part of the deploy act).

### 9.10 Promote
Header with the two environment cards and an arrow (swap on click), a summary strip, the item list with distinct gutters, secrets inline, the images aside as a card; primary **Promote and deploy**, secondary "Stage only".

### 9.11 Previews
`PreviewCard` grid (2 columns), "Waiting for you" group for fork approvals at the top. Settings stay in project Settings → Previews, laid out as option cards for the database strategy ("New empty database" / "Clone from staging" / "Share staging's database") with the consequence on each card.

### 9.12 Get started
`narrow`. Hero + ring; five step cards, one open; done steps one line each with their result; the deploy step embeds the import flow. Dismiss as "Hide for now" in the top bar.

### 9.13 Add server
`narrow` wizard; flows §10.

### 9.14 Settings
Shared settings layout (§5.12). Specific pages:
- **Members**: avatar rows, role chips, `⋯` menu; "Invite people" sheet with role tiles; pending invitations.
- **Git**: a provider picker (GitHub, GitLab, Gitea / Forgejo, any git server), then one card per connection with one health line.
- **Backups**: destination card with donut, "What's protected" with run strips, recovery kit card, schedules as sentences.
- **Updates**: status hero ("You're up to date · 0.4.2" / "0.5.0 is ready" + highlights + Update now), step list during updates, server version cards with "Update 3 servers".
- **Registry, Network** (instance): cards with the current mode as `OptionCards` (built-in / your own) and the details below.
- **Profile, Security, Appearance**: settings rows; Appearance as three theme tiles with previews.

### 9.15 Auth and setup
- Login: logo mark at 40 px, "Sign in to launchroom" (or the instance name), passkey first, then email and password, an "or" divider in muted sentence case (no ALL-CAPS eyebrow), and the instance URL under the form in mono-small. Plain background, no illustration.
- Setup (first owner): two panes; left the "Your launchroom is installed" moment with the detected server illustration and live facts, right the form (flows §1).
- 2FA and recovery codes: full-height steps with large QR and code inputs.

### 9.16 Empty states (catalogue)

| Where | Ghost / icon | Line | Action |
|---|---|---|---|
| Projects | dashed project card with logos | "Your projects will live here." | Import a repository |
| Canvas | empty group frames with ghost tiles | "Add a repository or a database to get started." | Add |
| Deployments | lucide `rocket` | "No deployments yet. Push to main or press Deploy." | Deploy |
| Previews | lucide `git-pull-request` | "Open a pull request to get a preview." | Preview settings |
| Logs | lucide `scroll-text` | "Nothing logged in this time range." | Widen the range |
| Servers (only the control plane) | dashed server card with the `unknown` silhouette as the second card | "Add a server for builds, more capacity or a second edge." | Add server |
| Backups (no destination) | `OptionCards` of providers | "Keep a copy of your databases off this server." | Add destination |
| Variables | lucide `key-round` | "No variables yet. Paste a .env anywhere on this tab." | Add variable |
| Activity | lucide `history` | "Deploys, promotions and changes will show up here." | |

## 10. Accessibility and responsiveness (applies to everything above)

- WCAG AA text contrast in both themes, including muted text on recessed stages.
- Cards are links with a single tab stop; inner actions are separate tab stops; focus ring 2 px signal with 2 px offset.
- Charts: value in text next to every sparkline; full charts have a data table toggle; colour never alone (legends with values, status icons).
- Canvas: keyboard focus and arrow navigation stay; the phone list view doubles as the accessible alternative.
- Live regions for deployment status changes and wait states.
- Phones (390 px): 16 px gutters, no horizontal page scroll, grids stack, sheets full screen, the top bar collapses tabs into a select, the map hides.
- Reduced motion as in §3.5.

## 11. What we deliberately don't do

- No theme accent colour for the brand (the brand is monochrome; signal blue is functional).
- No marketing-style hero sections inside the app except Get started and setup.
- No dashboards of empty charts: a chart appears only when it has data; otherwise the tile shows the current value or is hidden.
- No per-provider device art or vendor logos on devices.
- No request/traffic visuals without data: a service without traffic shows no traffic charts.
