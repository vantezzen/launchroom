# launchroom design system

The dashboard should feel like a **product, not a dashboard**: calm and precise in its chrome, visual and alive in its content (D32). The reasoning, references and page targets are in [design/direction.md](design/direction.md), the user journeys in [design/flows.md](design/flows.md).

We remain **inspired by Vercel's design language** (Geist typography, monochrome chrome, hierarchy through type and spacing), as described in Vercel's public guide <https://vercel.com/design.md> (local copy `references/vercel-design.md`, git-ignored), and by Umbrel's guided, visual setup flows. **We are not Vercel or Umbrel.** We never use their wordmarks, logos or anything that could read as made by them. launchroom has its own mark (the "Room" tile, `components/app-logo-icon.tsx`). We borrow principles, not brand assets.

This doc complements [08-dashboard-ux.md](08-dashboard-ux.md) (what screens exist) with **how they look and behave**. It's binding for UI work; when a page needs something this doc doesn't cover, extend this doc in the same change.

## 1. Principles

1. **Picture → number → words.** Each object leads with something you can see (a device, a mini canvas, a preview, a logo) or a number (34%, 1m 12s), then its name, then supporting text. Explanations are one sentence; anything longer becomes a hint or a "Learn more" link.
2. **Objects are cards, settings are rows.** Things the user owns (servers, projects, deployments, environments, previews, destinations, infrastructure groups) are cards with a status and a place for their numbers. Forms, long lists and logs stay flat.
3. **Monochrome chrome, honest colour in content.** Navigation, buttons and text are neutral. Colour appears for status, data and identity (logos), always with a non-colour cue, never to decorate or to make a number look good.
4. **One focal object per view.** The canvas on a project, the card grid on a list, the step list on a deployment, the open step on a wizard.
5. **Default forward.** Every view has one obvious next action, pointing at the most likely choice. Detect first, ask only for what we couldn't detect. Detected values show with an **Override** toggle.
6. **Every wait is live.** Waiting states show what's happening, roughly how long it takes, update by themselves, and offer a "not working?" path after a timeout.
7. **Continuity over stillness, never decoration.** Motion explains state changes and keeps your place between views (cross-fades, shared elements, panels). Nothing loops, pulses or celebrates.
8. **Every gap has one owner.** Spacing comes from the layout container (stack/grid gap), not from ad-hoc margins on children.
9. **Hard no:** gradients on chrome, gradient text, glows, glassmorphism, decorative patterns (the canvas dot grid is the one functional pattern), coloured side rails as decoration, stock illustrations or mascots, emoji in UI chrome, ALL-CAPS eyebrows.

## 2. Typography

- **Geist Sans** for all UI text, including numbers. **Geist Mono** for code, commands, paths, hostnames, env keys, SHAs, image digests, log lines and IDs.
- Fonts are **self-hosted** (`@fontsource-variable/geist`, `@fontsource-variable/geist-mono`). Self-hosted installs don't call Google Fonts.
- Type roles (defined once as utilities in `resources/css/app.css`; don't invent sizes per page):

| Role | Size / line height | Weight | Tracking | Use |
|---|---|---|---|---|
| `display` | 32/38 | 600 | −0.03em | Heroes only: Get started, server detail name, setup |
| `title` | 28/34 | 600 | −0.025em | Page title (one per page) |
| `stat` | 24/30 | 600 | −0.02em | Metric values in stat tiles (tabular) |
| `heading` | 16/24 | 600 | 0 | Section and card titles |
| `body` | 14/20 | 400 | 0 | Default UI text |
| `label` | 13/18 | 500 | 0 | Form labels, tabs, table headers, chips |
| `small` | 12/16 | 400 | 0 | Meta text, legends, helper text. Never to cram content. |
| `mono` | 13/20 | 400 | 0 | Technical values |
| `mono-small` | 12/16 | 400 | 0 | Log lines, inline IDs |

- Weights 400/500/600 only. Sentence case everywhere. Numbers in tables, metrics and durations use tabular figures.
- Descriptions under titles: one line, ≤ 80 characters, may carry live counts ("6 servers in 4 places · 5 online").
- Body prose is limited to ~65 characters per line.

## 3. Colour & tokens

Tokens are CSS variables in `resources/css/app.css` with the shadcn names (`--background`, `--foreground`, `--card`, `--muted`, `--border`, `--primary`, `--ring`…) plus the launchroom tokens below. Values follow a Geist-like neutral scale.

### 3.1 Neutrals and surfaces

| Token | Light | Dark | Use |
|---|---|---|---|
| `--frame` | `#fafafa` | `#000000` | App frame behind the inset (sidebar background) |
| `--background` | `#ffffff` | `#0a0a0a` | Page surface (elevation 0) |
| `--card` | `#ffffff` | `#111111` | Cards, tiles, nodes, sheets (elevation 1–2) |
| `--recessed` | `#fafafa` | `#0d0d0d` | Recessed areas inside cards: device stage, log excerpts, code |
| `--foreground` | `#171717` | `#ededed` | Primary text |
| `--muted-foreground` | `#4d4d4d` | `#a1a1a1` | Secondary text (AA on every surface) |
| `--faint` | `#8f8f8f` | `#707070` | Tertiary meta (timestamps beside other text only; never alone for meaning) |
| `--border` | `#ebebeb` | `#1f1f1f` | Card borders, hairlines |
| `--border-strong` | `#e0e0e0` | `#2e2e2e` | Inputs, hover borders, dark float ring |
| `--hover` | `#f5f5f5` | `#1a1a1a` | Hover and selected backgrounds, segmented control track |
| `--primary` | `#171717` | `#ededed` | Primary buttons (inverted fill) |

### 3.2 Functional colours

| Token | Light | Dark | Use |
|---|---|---|---|
| `--signal` | `#0062d1` | `#47a8ff` | Links, focus ring, selection, the staged state (always dashed), single-series charts, map bubbles |
| `--signal-soft` | `#e9f2fe` | `#0d2440` | Selected and staged backgrounds |
| `--status-ok` / `-soft` | `#107d32` / `#e8f6ec` | `#62c073` / `#0f2a17` | Healthy, live, online, succeeded |
| `--status-progress` / `-soft` | `#a35200` / `#fff4e0` | `#ffb224` / `#2e1f05` | Building, deploying, waiting, warnings |
| `--status-error` / `-soft` | `#c4211b` / `#fdecec` | `#ff6166` / `#331314` | Failed, unreachable, destructive |
| `--status-idle` | `#666666` | `#8f8f8f` | Stopped, superseded, skipped, off |
| `--device-led-*` | ok `#22c55e`, busy `#f59e0b`, error `#ef4444`, off `#9ca3af` | same | Only inside device illustrations |

Diff views keep low-intensity red/green backgrounds with `+`/`−` gutters; edits use `~` with a signal-soft background.

### 3.3 Status vocabulary

One word per state, the same on canvas nodes, cards, panel headers, lists and ⌘K:

| State | Word | Cue |
|---|---|---|
| Serving the current version | **Live** | filled green dot |
| Running, no public traffic (workers, databases) | **Running** | filled green dot |
| Server connected | **Online** | filled green dot / green device LED |
| Queued, building, releasing, rolling out, health checking | **Deploying** (detail: "Building · 41s", "Health check · 1 of 2") | amber spinner + progress |
| Staged deployment waiting for promotion | **Ready to promote** | hollow signal dot |
| Failed with the previous version still serving | **Last deploy failed** + "still serving 3b7d5e1" | red ✕ |
| Failed with nothing serving | **Failed** | red ✕ |
| Server not reporting | **Unreachable** + "for 8 min" | red dot / red LED |
| Stopped, superseded, skipped | **Stopped** / **Superseded** / **Skipped** | hollow grey dot |
| Has staged changes | **Changed** / **New** / **Removed** chip | dashed signal outline |
| Single point of failure, stale backup, degraded | **Warning** text specific to the case | amber ⚠ |

Never a bare coloured dot without the word, except where the word sits in the same row (e.g. a legend) or the dot's meaning is labelled once nearby (a card header with "Online" in the tooltip and status text below).

### 3.4 Data palette

Categorical, fixed order, never cycled. Validated with the dataviz validator (CVD separation, normal-vision floor, contrast) on `#ffffff` and `#111111`; all checks pass. Slots 3–5 are below 3:1 on white, so those series always carry a visible label or legend value.

| Slot | Light | Dark |
|---|---|---|
| 1 blue | `#2a78d6` | `#3987e5` |
| 2 orange | `#eb6834` | `#d95926` |
| 3 aqua | `#1baf7a` | `#199e70` |
| 4 yellow | `#eda100` | `#c98500` |
| 5 magenta | `#e87ba4` | `#d55181` |
| 6 green | `#008300` | `#008300` |
| 7 violet | `#4a3aa7` | `#9085e9` |
| 8 red | `#e34948` | `#e66767` |

- A project gets a slot at creation (stored, `projects.color_slot`) and keeps it everywhere. More than 8 projects reuse slots in order, and charts fold anything past 4 series into "other" (grey).
- Single-series charts use `--signal`. Status colours are never used as series colours.
- Sequential (heat) scales use one hue (blue) light → dark; diverging scales are blue ↔ red with a grey midpoint.

### 3.5 Identity (logos)

Framework, database, provider and git-host logos (simple-icons) are **monochrome** (D32): the mark takes the foreground colour inside a neutral `Logo` tile (card background, 1 px border). No brand colours on tiles. Unknown kinds fall back to a monogram on the same tile. Logos identify the user's own stack (nominative use); they're never used as decoration.

### 3.6 Restraint

Pages must not be stuffed (D32): fewer elements per card, more whitespace, colour sparingly. At most one data colour per small chart (the signal blue); the multi-colour palette only where comparing series is the point, limited to the top few items with the rest in grey (`--chart-other`). When in doubt, remove an element. The primitives below default to the calm version (neutral chips, outline-red destructive buttons, soft status pills only in headers).

## 4. Layout

### 4.1 Shell
- Left sidebar: team switcher, a visible **Search ⌘K** field, Get started (until complete), Projects, Servers, Activity, Logs (owners and admins), Settings, user menu. The main area is the inset surface (`md:m-2 rounded-xl`, 1 px border).
- **One top bar on every page**: 52 px, `px-6` (`px-4` on phones), hairline bottom border. Team pages: breadcrumb left, page-level secondary actions right. Project pages: `project ▾` / `environment ▾`, the project tabs as an inline segmented row (Canvas · Deployments · Logs · Previews · Settings), and the staged-changes button right.

### 4.2 Page container
All pages render in `<Page size>`:

| Size | Content width | Padding (desktop / tablet / phone) | Use |
|---|---|---|---|
| `wide` | max 1280 px, centred | 32·32·64 / 24·24·48 / 20·16·48 (top·sides·bottom) | Lists, overviews, detail pages (projects, servers, server detail, deployments, deployment detail, previews, activity) |
| `narrow` | max 768 px, centred | same | Guided flows and wizards (get started, import repository, add server, replace server, recovery, setup) |
| `settings` | 200 px nav + 40 px gap + max 800 px content, centred as one block | same | Team and project settings (one shared layout) |
| `full` | edge to edge under the top bar | none | **Only** the project canvas and the logs explorer |

- Every block on a page shares the page's container. Never a full-width header above a capped body.
- Page header: title (`title`), one-line description, actions right-aligned (bottom-aligned with the title), at most one primary action.
- Spacing scale: 4, 8, 12, 16, 20, 24, 32, 40, 48, 64. Section gap 40, section header → content 14, grid gap 16, card padding 18–20, card header row 14×18.
- Grids: object cards in 3 columns ≥ 1100 px content width, 2 ≥ 720 px, 1 below. Stat tiles 4 → 2 → 1.

### 4.3 Responsiveness
Phone (390 px) is a supported layout: 16 px gutters, no horizontal page scroll, grids stack, sheets go full screen, project tabs collapse into a select, wide tables become stacked rows, the canvas becomes a grouped list of node cards, the server map is hidden (its list stays). Touch targets ≥ 44 px on phones, ≥ 32 px in desktop chrome.

## 5. Surfaces, elevation, radius

| Level | Light | Dark | For |
|---|---|---|---|
| 0 page | `--background` | `--background` | Page surface |
| 1 card | `--card`, 1 px `--border`, `0 1px 2px rgb(0 0 0 / .04)` | `--card`, 1 px `--border`, no shadow | Cards, tiles, canvas nodes and groups |
| 2 float | `--card`, `0 8px 30px rgb(0 0 0 / .10), 0 1px 3px rgb(0 0 0 / .06)` | `--card`, `0 8px 30px rgb(0 0 0 / .6)` + 1 px `--border-strong` ring | Sheets, panels, popovers, menus, toolbars, toasts, floating bars, dragged nodes |

- No nested cards; inside a card use `--recessed` areas or hairline-separated rows.
- **Radius**: 8 px controls, inputs, chips (pills `full`); 14 px cards, tiles, canvas nodes; 16 px sheets, panels, dialogs; 18 px canvas group frames; `full` for status dots and avatars. One radius per element class.
- Card hover: border to `--border-strong` (no shadow change). Selected: 1.5 px foreground ring (tiles) or 2 px (canvas nodes). Staged: dashed `--signal` outline offset 3 px.
- Tables: borderless with hairline row separators, only for genuinely tabular data with many rows (variables, log lines, snapshots). Never a table for 1–5 objects; use cards or rows.

## 6. Components

Primitives in `resources/js/components/`, composites in `components/patterns/`. Full anatomy in [design/direction.md](design/direction.md) §5.

| Component | Rules |
|---|---|
| `Page`, `PageHeader`, `Section`, `TopBar` | The only way to lay out a page (§4). |
| Buttons | One primary (inverted fill) per region. Secondary outline, tertiary ghost. Destructive actions are outline-red inside a confirm popover or the Danger zone, never a solid red button in a list. Labels are verbs. |
| Inputs | Visible labels, one-line helper only when needed, inline validation preserving input, mono for technical values. Numeric quantities with a small range use a `Stepper` (− value +). |
| Detected + Override | Detected value in muted mono with an "Override" switch; on, the field is editable with "Reset". Used everywhere we auto-detect. |
| `Status`, `StatusPill` | Icon/dot + word from §3.3. Pill variant (soft background) for headers and cards. |
| `Logo` | Monochrome logo tile (§3.5), 20/32/44 px. |
| `DeviceIllustration` | Server art by device key and status (§7). |
| `ObjectCard` + `ServerCard`, `ProjectCard`, `PreviewCard`, `EnvironmentCard`, `DeploymentCard` | Media stage on top (150 px, `--recessed`), header (name, status, meta top-right), chips, body, facts footer. Whole card is a link with one tab stop. |
| `InfraGroup` | Titled group frame with status rows (icon, label, value right). |
| `StatTile`, `Sparkline`, `MiniBars`, `UsageMeter`, `Donut`, `TimeRangeSelect` | Data viz rules in §9. |
| `StepList`, `StepPills`, `ErrorExcerpt`, `RunStrip` | Steps with status icons and right-aligned durations; failing step auto-expands to ±5 lines with the error line highlighted (red left rule + soft background). |
| `LogExplorer` | Query bar, histogram with deploy markers, filter rail with counts, virtualized mono table, detail drawer. Logs are text, never rendered as HTML; ANSI colours toned to the palette. |
| `ChoiceTiles`, `OptionCards`, `Wizard`, `LiveWait` | Guided setup: "choose your X" tiles, mode cards with a check, numbered steps with collapsed summaries, live waiting states. |
| `Sheet`, `ServicePanel` | Right sheets at elevation 2, 480/560/720 px, 12 px inset on desktop, full screen on phones; sections with headings; toggles right-aligned. |
| `UnsavedBar`, `StagedBar` | Floating, centred at the bottom of the sheet/page, inverse colours. Replace per-section Save buttons: "Unsaved changes · Reset · Save" (instant settings), "2 changes · api will restart · Discard · Deploy" (staged). |
| `EmptyState` | Icon (48 px muted) or a ghost of the object that will appear, one line (what will be here + how it gets here), one action. |
| `ServerMap` | Dotted land map (static SVG from public-domain Natural Earth data), location bubbles, edge markers, mesh arcs (solid direct, dashed relayed), region list. |
| Toasts | sonner, bottom-right, icon + one line + optional action (Undo, Open). Only for async outcomes or undo. |
| Callouts | Info / warning / error with icon, bold first sentence, one more sentence, actions. Never a red border around a whole section. |
| Confirmation | Reversible-but-consequential: confirm popover with the consequence in one sentence. Irreversible data loss: dialog with typed confirmation. |
| Command palette | cmdk; sections Actions (context first), Navigate, Projects, Services, Deployments, Servers; rich rows (logo, status, environment); shortcut hints in `mono-small`. |
| Canvas | Node: 264 px, logo tile, name, subtitle, status line (+ progress while deploying), one key fact, footer strip (sparkline, replicas, servers). Groups: Public / App / Data frames. Edges: 1.5 px, label pill `DB_* · 6`, staged dashed signal, shared-with-production red dashed + chip. Floating toolbar bottom-centre, minimap bottom-right. |

## 7. Illustration & iconography

- **Icons**: lucide-react, 16 px at 1.75 stroke in UI, 20 px in tiles, 48 px muted in empty states.
- No stock illustrations, characters or decorative art anywhere. Devices are the only illustrations.

### 7.1 Device art

- **Source**: true 3D renders in `docs/design/devices/` (`server`, `mac`, `nuc`, `raspberry-pi`, `laptop`, `tower`, `nas`). Don't add device art in a different style; a new device type needs a new render of the same kind.
- **Kinds**: ten device kinds (`cloud`, `dedicated`, `tower`, `minipc`, `compact`, `sbc`, `nas`, `laptop`, `vm`, `unknown`). Kinds without their own render use the closest one (`DEVICE_RENDER`, §13.8). Detection mapping: [design/direction.md](design/direction.md) §7.3.
- **Processing** (`make device-art`, details in §13.8): one transparent 16:10 canvas, consistent crop, scale and baseline, the same contact shadow under every device, vendor marks and logos painted out, exported as 2× WebP with a 1× WebP and a PNG fallback. The same art serves both themes on the recessed stage.
- **Status LED**: an overlay driven by `--device-led-*`, positioned per render.
- **States** (applied by the component): online (green LED), busy (amber), unreachable (red LED, desaturated), offline/revoked (greyscale, 55 % opacity, LED off), pending (`unknown`).
- No vendor logos or text on any device.

## 8. Motion

| Token | Duration | Use |
|---|---|---|
| `fast` | 120 ms | Hover, press, toggles, tab indicator |
| `base` | 180 ms | Cross-fades, popovers, menus, status changes, card enter |
| `slow` | 240 ms | Sheets, panels, page-level shared-element morphs |

- Easing: enter `cubic-bezier(.2,.8,.2,1)`, exit `cubic-bezier(.4,0,1,1)`. Nothing over 300 ms except edge draw-on (300 ms) and number tickers (400 ms).
- **Page changes**: content cross-fade with a 4 px settle via the View Transitions API around Inertia visits (CSS fade fallback). Sidebar and top bar never animate. Shared elements: canvas node logo → panel header, deployment row → deployment header.
- **Allowed**: panels and sheets, status cross-fades, progress bars, the building spinner, edge draw-on when connecting, new card/node scale-in (8 px), toasts, the staged count change, skeleton fade-in after 300 ms.
- **Not allowed**: pulsing or breathing dots, shimmer skeletons, parallax, marquees, typing effects, confetti.
- `prefers-reduced-motion`: everything becomes instant; spinners become a static icon.

## 9. Data visualisation

- A chart appears only when it has data; otherwise show the current value or hide the tile. No empty chart frames.
- Single series in `--signal`; multi-series in the data palette (§3.4) in fixed order, max 4 series + "other". Colour follows the entity (a project's slot), never its rank.
- Lines 1.75 px, area fill 22 % → 0 % of the series colour, segmented meters with 2 px gaps, rounded bar ends at the baseline, one y-axis only, recessive axes (11 px muted), no gridlines in sparklines.
- Every chart: hover crosshair or per-mark tooltip (time + value), the current value as text beside it, and a table view for full-size charts. Legends always present for ≥ 2 series, with values.
- Deltas: green/red only when the direction has a clear good/bad meaning (errors down = green); otherwise neutral ("avg 29%").
- Deploy markers (signal-blue ticks with the short SHA) on every time chart of a service or project.
- All charts are small dedicated SVG components (`components/charts`), full-size `TimeSeriesChart` included: one look, keyboard crosshair and table view built in (§13.8).

<a id="voice-and-copy"></a>

## 10. Voice and copy

**Positioning.** launchroom is one place to deploy: an open-source platform you run on your own servers, where every app, database, preview and environment lives together and goes live together. Every server joins with one command, and launchroom connects servers, services, credentials and networks itself.

**Brand line:** *One place to deploy.* Never use it alone in a headline; a subhead with the specifics (servers joining, connections, previews) goes under it.

**Voice: confident host.** Proud of the experience, matter-of-fact about the engineering, never apologetic about being self-hosted.

- Put the magic first, then one sentence of how: "It connects out, so there's nothing to open." Name the magic once per page, not in every line.
- Talk about choosing servers: "the servers you choose", "any Linux server". Never imply spare capacity ("put idle machines to work", "already own").
- Never frame launchroom as a compromise: no "simple", "easy", "made easy", "a lighter version of", "cheaper".
- Never define launchroom by another product: no competitor names, no "alternative to", "self-hosted version of", "X on your server".
- No "cloud" for launchroom itself; where a category word is needed, say "deploy platform".
- Avoid "breeze", "superpowers", "Docker dashboard", "panel", "cluster".
- Always lowercase `launchroom`, including at the start of a sentence.
- Words we use: one place, deploy, ship, go live, connect, join, servers you choose, your servers, preview, promote, roll back, yours, end to end, open source.

**UI copy**

- Sentence case everywhere. Plain, specific language: "Build failed: pnpm lockfile is out of date", not "Something went wrong".
- Lead with the outcome, then the reassurance, then the detail: "Build failed: pnpm lockfile is out of date. Your site is still serving the previous version."
- App vocabulary (service, deployment, variable, domain, server, environment, preview), never Docker internals in primary UI ([01](01-vision-and-principles.md)). Raw Docker details only under Advanced.
- The per-server program is invisible: talk about **servers** ("Run this on your server", "This server is connected", "Server version"), never "agent" or "daemon" (D27). Say `launchroomd` only where a technical name is unavoidable (troubleshooting, log lines).
- One sentence per description. Explanations longer than that go into a hint, "Learn more" or the docs.
- Times relative in lists ("2m ago") with absolute on hover; durations compact ("1m 42s"); sizes with one decimal under 10 ("4.1 GB").
- No em dashes in UI copy; use a colon or a new sentence.

## 11. Themes & accessibility

- Light and dark from day one; system preference with a manual override. Every screen is checked in both. Dark mode is designed (elevation through `--card` vs `--background`), not inverted.
- WCAG AA text contrast (including muted text on `--recessed`), visible 2 px focus rings with 2 px offset, full keyboard support (canvas included: arrow-key focus, Enter opens the panel), ARIA live regions for deployment status and wait states.
- Colour never alone: status words, legends with values, icons.
- Charts have text alternatives; the canvas has the phone list view as an accessible alternative.

## 12. Review checklist (before merging UI)

1. Squint test: is the focal object obvious, does each object lead with a picture or number?
2. Does the page use `Page` with the right size, sharing one container from top to bottom? Same top bar?
3. Any prose longer than one sentence that should be a hint? Any table with fewer than 6 rows that should be cards or rows?
4. Colour: status, data or identity only, each with a non-colour cue? Data palette in fixed order?
5. Empty, loading (skeleton after 300 ms), waiting, error and phone states designed?
6. Light, dark, reduced motion, keyboard and 390 px checked with screenshots?
7. Copy in sentence case, specific, app vocabulary, unified status words, no "agent", voice rules from §10?

## 13. Component reference

The components in `dashboard/resources/js` and their APIs. Every primitive renders on **`/dev/design`** (local environment only). To add a section there without touching the page, add a `components/**/*.design.tsx` whose default export is a component (title from `export const title`).

### 13.1 Tokens (`resources/css/app.css`)

- Colours as utilities: `bg-frame`, `bg-background`, `bg-card`, `bg-recessed`, `bg-hover`, `text-foreground`, `text-muted-foreground`, `text-faint`, `border-border`, `border-border-strong`, `text-signal`/`bg-signal`/`bg-signal-soft`, `text-status-{ok,progress,error,idle}` + `bg-status-{ok,progress,error}-soft`, data palette `chart-1…8` + `chart-other` (e.g. `bg-chart-3`, `var(--chart-3)`), `--device-led-{ok,busy,error,off}` (device art only), `--map-land` / `text-map-land` (the server map's land dots: `#b3b3b3` light, `#4d4d4d` dark, clearly visible and still quieter than text; never opacity on a border token). `status-success` / `status-staged` are aliases of `status-ok` / `signal`.
- Type roles: `type-display`, `type-title`, `type-stat` (tabular), `type-heading`, `type-body`, `type-label`, `type-small`, `type-mono`, `type-mono-small`.
- Radius: `rounded-md` 8 (controls, chips), `rounded-xl` 14 (cards, tiles, nodes), `rounded-2xl` 16 (sheets, dialogs), `rounded-3xl` 18 (canvas groups); named aliases `rounded-control|card|sheet|group`.
- Elevation: `shadow-card` (level 1; none in dark), `shadow-float` (level 2; includes the dark ring).
- Motion: `duration-fast|base|slow` (120/180/240 ms, also drive tw-animate), `ease-enter`, `ease-exit`, `animate-enter` (fade + scale-in for new cards/nodes), `animate-skeleton-in` (fade in after 300 ms). `lib/motion.ts` exports the same values for JS (`motion.fast` …) and `prefersReducedMotion()`. Reduced motion makes every animation and transition instant globally. Lucide icons render at 1.75 stroke.

### 13.2 Layout

| Component | File | API |
|---|---|---|
| `Page` | `components/patterns/page.tsx` | `<Page size="wide|narrow|settings|full" nav? className?>`. Children stack with a 40 px gap (`className` merges onto the stack, e.g. `gap-6`). `settings` takes `nav` (left 200 px column, sticky). `full` is a flex column filling the viewport under the top bar (canvas, logs explorer only). Carries the `lr-page` view-transition name; render exactly one per page. `pageGutters` exports the padding classes. |
| `PageHeader` | same | `title`, `description?` (one line), `actions?` (right, ≤ 1 primary), `media?` (logo/device before the title), `variant="title|display"`, `children?` (chips under the description). Renders the page's `h1`. |
| `Section` | `components/patterns/section.tsx` | `title?`, `description?` (inline after the title on desktop), `actions?`, `level=2|3`, children 14 px below. |
| `TopBar` | `components/patterns/top-bar.tsx` | Rendered by the layouts, not pages: `<TopBar actions? below?>{left}</TopBar>`, 52 px, sidebar trigger first, `@container` so children can use `@4xl:` etc. |
| `TopBarActions` | same | Pages put secondary actions into the right of the top bar: `<TopBarActions><Button variant="ghost" size="sm">…</Button></TopBarActions>` (portal; works in team and project layouts). |
| `TopBarBreadcrumbs` | same | Team pages: driven by `Page.layout = { breadcrumbs: [...] }` as before; phones keep the last two crumbs. |
| `SettingsNav` | `components/patterns/settings-nav.tsx` | `groups: { label?, items: { title, href, active, dot? }[] }[]`, `label`. Grouped list on desktop, a row of pills on phones. Team settings get it from `layouts/settings/layout.tsx` (groups Team · This launchroom · You, page title from the active item); project settings use it via `Page size="settings" nav={…}`. |
| `ProjectTopBar` | `features/projects/project-top-bar.tsx` | One row: `project ▾ / environment ▾`, tabs Canvas · Deployments · Logs · Previews (count) · Settings as a segmented row with a sliding pill (re-measured on resize and font load; a menu below ~900 px bar width), right: single point of failure, the staged-changes pill (§13.9; not on a canvas with services, whose toolbar carries it), then `TopBarActions`. No Add button here: adding lives in the canvas' floating toolbar (and the phone list's "Add a service"). Phones get one switcher with the project (icon + name) over the environment, opening one menu with both lists, and the page menu names the page (Canvas, Activity, Promote, Changes); the staged pill shows the count only. The project switcher shows `ProjectIcon`s. Project activity is in the project menu. |
| Floating bars | `components/patterns/unsaved-bar.tsx` | Layouts provide `FloatingBarProvider` + `FloatingBarOutlet`; see 13.4. |

Layouts: `layouts/app/app-sidebar-layout.tsx` (team pages), `layouts/project-layout.tsx` (project pages; `projects/canvas` and `projects/logs` are full-bleed, every other project page scrolls the window like team pages; it also mounts the one `ProjectDeployToasts`, §13.9), `layouts/settings/layout.tsx` (team settings, nested in the app layout). Signed-out pages (`auth/*`, `setup`, `invitations/*`) get **no** layout: each draws the shared `AuthFrame` (§13.9). `GET /dev/design` is local only.

Sidebar (`components/app-sidebar.tsx`, `nav-main.tsx`; header and footer in §13.9): Search field opening ⌘K, Get started with `n/5`, Projects, Servers, Activity, Settings (quiet signal dot when `updateAvailable`, a shared prop for instance owners from the cached release check). `NavMainItem` supports `meta`, `dot`, `activePrefix`.

### 13.3 Status, logos, empty states

- `Status` (`components/status.tsx`): `<Status state label detail? />` with `state: active | progress | failed | warning | idle | staged` (filled green dot, amber spinner, red ✕, amber ⚠, hollow grey dot, hollow signal dot). `StatusPill`: same props, soft background, for page headers and card headers only. `StatusIcon` alone for legends.
- Enum mappings (use these instead of local tables): `deploymentStatus`, `serverStatus`, `stepStatus`, `backupRunStatus`, `previewStatus`, `healthCheckState` → `{ state, label }`. Backend words: canvas nodes say **Live** (web kinds) or **Running** (workers, databases); environment summaries **Live** / "n of m running"; `DeploymentStatus::Active` is **Live**.
- `Logo` (`components/logo.tsx`): `<Logo kind size={20|32|44} name? decorative? />`. Covers the glyph keys (`nextjs`, `laravel`, `node`, `python`, `django`, `fastapi`, `flask`, `vite`, `postgres`, `mysql`, `redis`, `valkey`, `mongodb`, `nginx`, `docker`, `github`, `static`, `worker`, `cron`, `app`) plus providers/tools (`hetzner`, `digitalocean`, `googlecloud`, `vultr`, `ovh`, `scaleway`, `akamai`, `cloudflare`, `backblaze`, `raspberrypi`, `ubuntu`, `debian`, `tailscale`, `gitlab`, `astro`, `nuxt`, `remix`, `svelte`, `bun`, `deno`, `php`, `ruby`, `rails`, `go`, `rust`, `caddy`, `mariadb`, `clickhouse`, `rabbitmq`, `meilisearch`, `minio`, `server`, `cloud`, `s3`, and for templates `umami`, `plausible`, `uptimekuma`, `n8n`, `ghost`, `gitea`, `forgejo`, `pocketbase`, `metabase`, `wordpress`, `grafana`, `nextcloud`, `celery`, `sidekiq`). Unknown → monogram from `name`. `logoLabel(kind)`, `hasLogo(kind)`. Monochrome always.
- `ProjectIcon` (`components/project-icon.tsx`): `<ProjectIcon name iconUrl? size={20|32|44} fallback="monogram|none" />`: the project's favicon/upload on the `Logo` tile (border, card background, small inset); no icon or a failed load → `Logo` monogram, or nothing with `fallback="none"` (project cards). Decorative: the name is always next to it.
- `CommitAvatar` (`components/commit-avatar.tsx`): `<CommitAvatar name avatarUrl? size={16|20|24} />`: round GitHub avatar beside a commit message (URLs only from `avatars.githubusercontent.com`, built server-side), initials on the hover-grey disc otherwise (one letter below 24 px). Decorative.
- `EmptyState` (`components/patterns/empty-state.tsx`): `icon` (lucide, 48 px faint) or `ghost` (node), `title?`, children = the one line, `action?`, `secondary?`, `variant="framed|plain"`.

### 13.4 Sheets, floating bars, confirmation, toasts

- `SidePanel` (`components/patterns/sheet.tsx`; "Sheet" in §6): `open`, `onOpenChange`, `title`, `description?`, `media?`, `status?`, `size="sm|md|lg"` (480/560/720), `footer?`. Float level, 12 px inset, 16 px radius, full screen on phones. Body sections: `<SheetSection title description? control?>` separated by hairlines. `ui/sheet` stays for the mobile sidebar and existing sheets.
- `UnsavedBar`: `open`, `onReset`, `onSave`, `saving?`, `message?`, `placement="page|inline"`. `StagedBar`: `count`, `summary?` ("api will restart"), `onDiscard`, `onDeploy`, `deploying?`, `deployLabel?`, `disabledReason?`, `placement`. `page` placement portals into the layout outlet (sticky to the bottom of the content area; floats over full-bleed pages); `inline` floats at the bottom of the nearest positioned parent (inside a `SidePanel` that is the sheet). `FloatingBar` + `FloatingBarButton` build other inverse bars.
- `ConfirmPopover` (`components/patterns/confirm-popover.tsx`): wraps a trigger; `title?`, `consequence` (one sentence), `confirmLabel`, `onConfirm` (may return a promise), `tone="default|destructive"`.
- Toasts: `toast.success|error|warning|info|message(text, { action: { label: 'Open', onClick } })` from `sonner`; styled and iconed by `components/ui/sonner.tsx`. Async outcomes and undo only.

### 13.5 Guided setup

- `ChoiceTiles` (`choice-tiles.tsx`): `label`, `options: { value, label, description?, media?, disabled?, badge? }[]`, `value`, `onChange`, `columns=2|3|4`, `other?: { label, description?, media?, onSelect }` (full-width row). Native radio group.
- `OptionCards` (`option-cards.tsx`): `label`, `options: { value, title, description, media?, disabled? }[]`, `value`, `onChange`.
- `Stepper` (`stepper.tsx`): `label`, `value`, `onChange`, `min`, `max`, `step`, `unit?`, `helper?`, `consequence?` (live, announced).
- `Wizard` (`wizard.tsx`): `steps: { id, title, summary? }[]`, `current`, `onStepSelect?` (Edit on done steps), children = open step, `footer?` (actions). The page keeps the step in the URL.
- `LiveWait` (`live-wait.tsx`): `media?`, `status`, `hint?`, `timeoutSeconds=90`, `fallback?` (shown after the timeout).

### 13.6 Controls (`components/ui`)

- `Button` variants: `default` (inverted fill, one per region), `outline`, `secondary`, `ghost`, `link` (signal), `destructive` (**outline red**), `destructive-solid` (typed-confirmation dialogs only); sizes `default`, `sm`, `lg`, `icon`, `icon-sm`. Focus: 2 px signal ring, 2 px offset.
- `Badge` (chips): `default` (neutral), `secondary`, `outline`, `ok`, `progress`, `destructive`, `signal`, `staged` (dashed signal), `solid`.
- `Card`: 14 px radius, level-1 shadow, 20 px padding (`CardHeader/Title/Description/Content/Footer`).
- Added: `Tabs` (segmented track: also the Hour/Day/Week look), `Popover`, `Switch`, `ScrollArea`, `Progress` (`tone="default|progress|signal|ok|error"`), `Textarea`. Dialogs 16 px radius with float shadow; menus and popovers float shadow; overlays lighter (30 % / 60 %).

### 13.7 Motion

- Page changes: `app.tsx` sets `defaults.visitOptions = pageVisitOptions` (`lib/motion.ts`): plain GET navigations run in `document.startViewTransition`; partial reloads, `preserveState` visits (panels), forms, prefetches and reduced motion swap instantly. CSS in `app.css`: the sidebar (`lr-sidebar`) and top bar (`lr-top-bar`) never animate, the page (`lr-page`) fades out 120 ms and settles in 180 ms with 4 px; browsers without View Transitions fade the page in.
- Shared elements: `style={sharedElement('svc', id)}` on both ends (e.g. node logo and panel header logo). Names must be unique on the page.

### 13.8 Visual primitives: charts, steps, logs, devices, map, cards

All render with sample data in the "Visual primitives" section of `/dev/design` (`components/charts/visuals.design.tsx`) and alone on `/dev/design/visuals` (local only; `?theme=dark|light` forces a theme). Every chart renders **nothing** when it has no data (fewer than two points, no buckets, all-zero bars), so pages show the current value or an `EmptyState` instead of an empty frame. Colours: one series is `var(--signal)`; comparisons use `colorForSlot(slot)` (`var(--chart-N)`), at most 4 coloured items, the rest folded into grey `var(--chart-other)`.

**Charts** (`components/charts`, barrel `@/components/charts`)

| Component | API | Notes |
|---|---|---|
| `colorForSlot`, `foldToOther` | `colorForSlot(slot: number) → 'var(--chart-N)'` (wraps past 8); `foldToOther(items: Breakdown[], max = 4)` → sorted, top `max` in their slot colour (or position), rest plus every item marked `other` as one grey `{ key: '__other', other: true }` item. `Breakdown = { key, label, value, slot?, other? }` (`other: true` = always grey, e.g. the backend's "Other" usage row). Constants `SIGNAL`, `OTHER`, `TRACK`, `MAX_SERIES`. | Colour follows the entity's stored slot. |
| `Sparkline` | `values: (number\|null)[]`, `label` (aria), `height=24`, `color=SIGNAL`, `area?`, `min?`/`max?` (fixed scale, e.g. 0–100), `onActiveChange?(index\|null)` (enables hover + ←/→ crosshair). | `null` = gap. Put the current value beside it as text. |
| `StatTile` | `label`, `value: string\|null`, `unit?`, `delta?: { label, tone?: 'good'\|'bad'\|'neutral' }`, `hint?`, `series?: { values, times?, min?, max?, format? }`, `action?`. | Hovering the 40 px sparkline swaps the hint for "13:47 · 32%". No series → just the number. |
| `MiniBars` | `bars: { key, label, value, failed?, current? }[]`, `label`, `height=28`, `unit='deploys'`. | Neutral bars, failures red at the base, current period signal, 2 px stubs for zero. |
| `UsageMeter` | `items: Breakdown[]`, `total`, `label`, `format?`, `max=4`, `legend=true`, `height=8`. | Segmented, 2 px gaps, free space = track; one item without slot → signal, no legend. |
| `Meter` | `value`, `max=100`, `label`, `warnAt?`, `criticalAt?` (percent), `height=6`. | Signal unless past a threshold (amber/red only then). `role="meter"`. |
| `Donut` | `items`, `total?` (capacity), `label`, `centerValue?`, `centerLabel?`, `format?`, `size=132`, `thickness=12`, `max=4`, `legend=true`. | Legend with values beside it for ≥ 2 items. |
| `TimeRangeSelect` | `value`, `onChange`, `options = DEFAULT_RANGES` (Hour `1h` / Day `24h` / Week `7d`), `label`. | Radio group, one tab stop, arrow keys. |
| `TimeSeriesChart` | `series: { key, label, points: { t, v\|null }[], slot? }[]`, `title`, `height=200`, `yMax?`, `formatValue?`, `markers?: { t, label, tone?: 'signal'\|'error' }[]`, `table=true`, `empty?`. | One series = signal area; 2–4 = palette lines + legend with values at the crosshair; > 4 fold into "Other (n)". Gridlines at 0/half/max. Focus the chart and use ←/→/Home/End; Esc clears. "Table" toggles a data table (text alternative). Series share timestamps. |
| `Histogram` | `buckets: { start, end, total, errors? }[]`, `label`, `markers?`, `height=72`, `onSelect?(bucket)`, `highlight?: number` (epoch ms), `unit='lines'`. | Errors stacked red at the base; deploy markers signal; hover/arrow readout, Enter selects. |
| `formatBytes`, `formatCompact`, `formatPercent`, `formatTick`, `formatTooltipTime`, `niceMax` | `components/charts/format.ts` | "4.1 GB", "1.6K". |

```tsx
<StatTile label="CPU" value="34" unit="%" hint="avg 29%"
    series={{ values: cpu, times, min: 0, max: 100, format: (v) => `${v}%` }} />
<UsageMeter label="Memory by project" total={server.memoryBytes} format={formatBytes}
    items={projects.map((p) => ({ key: p.id, label: p.name, value: p.memoryBytes, slot: p.colorSlot }))} />
<TimeSeriesChart title="CPU, last hour" yMax={100} formatValue={(v) => `${Math.round(v)}%`}
    series={[{ key: 'cpu', label: 'CPU', points }]} markers={deploys.map((d) => ({ t: d.at, label: d.sha }))}
    empty={<p className="type-body text-muted-foreground">No metrics yet.</p>} />
```

**Steps** (`components/steps`)

| Component | API |
|---|---|
| `StepIcon` | `status: 'succeeded'\|'failed'\|'running'\|'pending'\|'skipped'\|'canceled'`, `size=18`. Pair with text (`STEP_STATUS_LABEL`). Spinner is static under reduced motion. |
| `StepList` | `steps: { id, name, status, summary?, duration?, chips?, content? }[]`, `defaultOpen?: string[]` (default: failed steps with content). Rows with content are buttons (`aria-expanded`); others are static rows. `StepChip` (`tone='neutral'\|'error'\|'warning'`) for "1 error". |
| `ErrorExcerpt` | `lines: { number, text }[]`, `errorLine`, `context=5`, `totalLines?`, `onShowAll?`. Red left rule + soft background on the error line; ANSI as text. |
| `StepPills` | `steps: { id, name, status }[]`: compact strip for cards and nodes. |
| `RunStrip` | `runs: { key, status: 'ok'\|'failed'\|'none', label }[]`, `label`, `height=20`. |
| `ActivityItem`, `ActivityGroup` | `actor`, `avatarUrl?`, `time`, `icon?`, children = sentence (wrap names in `<b>`); group with `title` ("Today"). |

**Log explorer core** (`features/logs/explorer`; the page is in §13.9)

| Piece | API |
|---|---|
| `LogEntry` | `{ id, time (ISO), level: 'error'\|'warning'\|'info'\|'debug', service, replica?, server?, deployment?, stream?, message (raw, ANSI ok) }`. |
| `parseLogQuery`, `serializeLogQuery`, `toggleFilter`, `matchesQuery` | Query grammar: `service:api -level:debug server:"fra 1" "connection refused" timeout`. Keys `service`, `level` (aliases warn/err/fatal), `deployment`, `server`, `stream`; `-` negates; same key OR'd, different keys AND'd, text terms must all appear (case-insensitive). Unknown keys stay text. The server-side search (`LogSearch`) applies the same semantics. |
| `QueryBar` | `value`, `onChange`, `placeholder?`. Parsed filters as removable chips; `/` focuses, Esc clears. |
| `Histogram` | from charts (above); `bucketEntries(entries, from, to, count)` buckets loaded lines. |
| `FilterRail` | `groups: { key, label, options: { value, label, count, mono? }[] }[]`, `selected: Record<key, string[]>`, `onToggle(group, value)`, `collapseAfter=6`. `countFacets(entries)` builds groups from loaded lines. |
| `LogTable` | `entries`, `selectedId?`, `onSelect?`, `highlight?` (lowercase terms), `follow?` (keep the last entry in view), `hideService?`, `empty?`. Virtualized fixed 28 px rows (100k lines scroll at ~60 fps), one tab stop, ↑/↓/PgUp/PgDn/Home/End move the selection. |
| `LogDetail` | `entry`, `facts?: { label, value, mono? }[]` (default: time, deployment, stream, server), `onClose?`, `onPrevious?`, `onNext?`, `footer?`. Content only: place it in a right column (desktop) or `SidePanel` (phones). |
| `LevelBadge`, `AnsiText` | `AnsiText` (`features/logs/ansi-text.tsx`): `text`, `highlight?`. Text nodes only, ANSI toned to the palette; also used by the deployment `LogViewer` and `ErrorExcerpt`. |

**Devices** (`components/devices`)

- `DeviceImage` (alias `DeviceIllustration`): `device: DeviceKind`, `status: 'online'\|'busy'\|'unreachable'\|'offline'\|'pending' = 'online'`, `size` (rendered width: 64 lists, 120 tiles, 200–232 card stage, 300 hero; height = width × 10/16), `alt=''` (decorative by default; the name and status are next to it). LED overlay green/amber/red/grey, unreachable desaturated, offline greyscale 55 %, pending 55 % without LED; `unknown` is the server as a flat silhouette.
- Kind → render (`DEVICE_RENDER`): `cloud`, `dedicated`, `vm`, `unknown` → server; `compact` → mac; `minipc` → nuc; `sbc` → raspberry-pi; `laptop` → laptop; `tower` → tower; `nas` → nas. `DEVICE_LABEL` gives the words ("Mini PC"). LEDs: the laptop's is the camera light in the notch, the tower's sits on the top band above the grille, the NAS uses its top status light.
- Assets: `public/images/devices/{server,mac,nuc,raspberry-pi,laptop,tower,nas}@{1x,2x}.webp` + `@2x.png` fallback, 320 × 200 canvas at 1×, baseline y = 162. Regenerate with `make device-art` (Docker tool image `launchroom-dev-device-art`, offline; `dashboard/scripts/device-art/process.py`): keeps the render's alpha (flood-fills an opaque background if a render arrives on white), drops the render's own floor shadow and specks, paints out vendor marks (apple, raspberry), repaints a display (`screen=`, the laptop's wallpaper) as a calm switched-off screen, scales to equal visual weight, stands every device on the baseline with the same two-ellipse contact shadow, exports WebP/PNG and `device-art.generated.ts` (LED positions). `scripts/device-art/sheet.py` renders a contact sheet. Add a render: drop the PNG into `docs/design/devices/`, add a `Device(...)` row (marks, LED, optional `screen`/`screen_keep`, `max_h` for tall devices) and map kinds in `DEVICE_RENDER`. `sheet.py <out> <dest>` draws the contact sheet with the LED positions.

**Map** (`components/map`)

- `ServerMap`: `locations: { id, label, sublabel?, lat, lon, servers, status?: 'ok'\|'unreachable', edge? }[]`, `links?: { from, to, relayed? }[]`, `height=360`, `legend=true`. Region list (sorted by servers) left, dotted map right (hidden below `md`), bubbles sized by servers, square ring = edge, solid/dashed signal arcs, hover links list ↔ map, greedy label placement. Land dots use the `--map-land` token (§13.1). `variant="split"` (default: list left, map right) or `"stacked"` (map on top, list below; one-column slots such as the Servers overview, which shows it from one place on).
- Land dots: `land-dots.generated.ts` (Natural Earth 110 m land, public domain, 2° grid, 3751 dots as one path). `project(lat, lon)` maps onto the grid. Regenerate with `make world-map` (downloads the GeoJSON once).

**Cards** (`components/cards`): presentational, page streams wire data.

| Component | API |
|---|---|
| `ObjectCard` | `title`, `href?` (whole card clickable via a stretched title link; inner actions need `relative z-10`), `media?` (150 px recessed stage), `mediaLabel?` (chip top-left), `mediaMeta?` (top-right), `titleMedia?` (a 20 px mark before the title, e.g. `ProjectIcon`), `status?`, `meta?`, `chips?`, children, `footer?` (facts row), `staged?` (dashed signal outline), `tone?`. `CardChip` (`outline?`), `ObjectCardSkeleton` (fades in after 300 ms, no shimmer). |
| `ServerCard` | `name`, `href?`, `device`, `deviceStatus`, `status` (a `Status`), `place?`, `lastSeen?`, `note?`, `roles?`, `cpu?`/`memory?: { value, series?, max? }`, `usedBy?: { items, total, caption }` (top 3 + grey), `specs?`, `disk?`, `alert?` (replaces the metrics, e.g. the unreachable explanation). |
| `ProjectCard` | `name`, `href?`, `status?`, `media?` (mini canvas), `domain?`, `lastDeploy?: { message, author, age, avatar? }`, `environments?`, `deploys?: MiniBar[]`. |
| `PreviewCard` | `title`, `href?`, `status?`, `url?` (renders a `UrlCard`; preview screenshots are not implemented), `branch`, `base`, `author?`, `protectedPreview?`, `expiry?`, `media?`. |
| `EnvironmentCard` | `name`, `href?`, `status?`, `trigger?`, `revision?`, `ahead?`, `behind?`, `services?`, `action?`. |
| `DeploymentCard` | `status`, `title`, `meta`, `steps?` (StepPills), `actions?`. Not a link card. |

### 13.9 Page patterns

Composites for whole pages. Reuse them instead of re-deriving a page's look.

**Sidebar header and footer** (`components/app-sidebar.tsx`, `team-switcher.tsx`, `features/teams/create-team-dialog.tsx`): the header is **one row**, the Room mark alone (28 px in a 40 px hit area, no wordmark, links home) then the team selector (team initial in a bordered neutral 32 px tile so it doesn't compete with the black mark, name, role, chevron). On the icon rail the two stack: mark (24 px) above the team tile; the team menu opens to the right there. The team menu ends with a separator and **+ Create team** (owners/admins, `teams.canCreateTeam`), which opens a small dialog: title "Create a team", one sentence, a "Team name" field, Cancel / Create team. The footer is the user menu, then one muted `type-small` line "launchroom v0.1.0" (the shared `version` prop), hidden on the icon rail. Phones get the same layout in the sidebar sheet.

**Recommendations** (`features/onboarding/two-factor-recommendation.tsx`, `features/git/webhook-reachability-note.tsx`): a calm, non-blocking card or callout: neutral icon tile, one bold line, one sentence, a quiet "Not now" and an outline action. Never a modal, never a redirect. Used for "Add two-factor sign-in" on Get started (D33) and "GitHub can't reach this dashboard" before connecting GitHub.

**Settings** (`features/settings/settings-card.tsx`): every settings page (team, instance, personal, project, the settings part of a server) is a stack of `SettingsCard`s under the page title, 40 px apart.

| Piece | API / rule |
|---|---|
| `SettingsCard` | `title?`, `description?` (one sentence), `actions?`, `footer?` (quiet recessed strip: a hint, `[skip preview]`), children = rows. One card per topic; no card titled like the page. |
| `SettingsRow` | `label`, `description?` (one line), `media?`, `htmlFor?`, `inline?` (keep the control right on phones: switches, chips, ⋯), `align`. Label left, control right (inputs `sm:w-72`, numbers `w-24`). |
| `SettingsBody` | A block inside a card that isn't a row (a password form, recovery codes, option cards). |
| Saving | Instant settings (name, switches in a form) save through the floating `UnsavedBar`, never a Save button per section. Switches that are their own action (secrets policy) apply on toggle. Endpoints that save one object (a preview database strategy) keep a small outline Save in the card footer. |
| Modes | A choice between 2–3 behaviours is `OptionCards` under a `Section`, not a select (fork previews, preview databases, restore mode). |
| Danger | A `SettingsCard` row with an outline-red button; the typed confirmation lives in the dialog. Never a red-bordered box. |

**Guided checklist** (`features/onboarding/step-card.tsx`; get started, recovery): `StepCard` (`status: done|pending|skipped`, collapsed = one row with `StepMarker`, title and result; the open step is the one card with a ring) and `ProgressRing` (done/total, monochrome) in the `display` header. One step open at a time; finishing a step opens the next.

**Auth frame** (`features/auth/auth-frame.tsx`): `AuthFrame` (`title`, `description?`, `media?`, `footer?`) is the whole page for login, 2FA challenge, password reset, verify email, confirm password and invitations: plain background, the mark at 40 px, one title, one sentence, the form (max 360 px), the instance host in `mono-small` muted at the bottom. `PasskeySignIn` first, then `OrDivider` ("or", sentence case), then email. `AuthNotice` for "We sent a link". These pages render without a layout (§13.2); setup uses its own wider frame with the connected server.

**Two-factor enrolment** (`components/two-factor-setup-modal.tsx`): one calm full-height step, not a dialog stack: title, one sentence, a 240 px QR on a white tile (both themes), the setup key in mono with copy, the 6-digit field and one primary "Turn on two-factor".

**Canvas** (`features/canvas`): groups are derived (`groups.ts`: Public = has an address, App = workers/crons/internal, Data = databases) and drawn as `GroupFrame`s (18 px radius, 3 % wash, label + icon + count); frames are skipped when the user's arrangement makes them overlap. "Tidy" (`autoLayout` with `partition`) lays groups out as columns Public → App → Data. Nodes and lists use `ServiceGlyph`, which is the `Logo` tile (monochrome) behind the canvas' glyph keys. Adding: the floating toolbar's Add (desktop) and the list view's "Add a service" (phones). Phones get `CanvasList` (grouped cards).

**Staged changes** (`features/staged-changes`): on the canvas the primary affordance is `StagedToolbarButton` at the end of the floating toolbar, bottom centre where changes are made: a dashed signal dot, "4 changes" in signal, then the toolbar's only filled button "Review & deploy →" (signal fill); it renders nothing when nothing is staged, so the toolbar is unchanged. Phones show it as a one-button floating bar under the service list. It is the toolbar's last tab stop, and Shift+D opens the review (`aria-keyshortcuts`). The top-bar pill is hidden on a canvas with services (no duplicate) and stays on every other project page, where there is no toolbar. The top-bar pill (dashed signal) reads "4 changes · Review & deploy" (or "3 new services · Deploy" before the first deploy), count only on phones, and is hidden when nothing is staged, including an environment without services. It opens `StagedChangesSheet` (grouped by service, `+`/`~`/`−` gutters, one effect per service, per-item discard, blockers as a callout with in-place Fix). Inside the service panel `ServiceStagedBar` floats at the bottom of the sheet ("2 changes · api will restart · Discard · Deploy", `placement="inline"`); page-level forms use `UnsavedBar` (`placement="page"`). One bar at a time per surface.

**Deploy outcome toasts** (`features/deployments/deploy-toasts.tsx`): `ProjectDeployToasts`, mounted once by the project layout, watches the project's persistent environments (and the open preview) and toasts "web is live · Open" / "web failed to deploy · See why". Pages don't mount their own.

**Deployments list** (`features/deployments-list/commit-group.tsx`): days as headings, one `CommitGroupCard` per commit (message, sha, who, environment, age), one row per service (status word, logo, name, failure line, neutral duration bar against the service median, duration, ⋯).

**Activity rows** (`features/activity/activity-feed.tsx`, also `ActivityItem`): the kind icon, the sentence block (one or two lines) and the time column are centred on one axis (`items-center`, rows at least 64 px), so one-line and two-line rows read the same. Commit cards and history rows put `CommitAvatar` before the commit message.

**Deployment detail** (`features/deployment-detail`), one `wide` column: header (logo, commit message as title, service · environment · who; Logs + Redeploy/Promote right) → `OverviewCard` (stage left: URL card or logo; facts right: status, duration, environment, built on, source, domains; footer: created, revision, raw id with copy) → `FailureCard` when failed (outcome title, the reassurance "still serving …", cause, fix, actions: the one-click fix, jump to line, copy command; no red frame) → Steps (`LogViewer`, one virtualized list of collapsible step headers and log lines; running step followed, failed step open at its first error with error lines highlighted, successful deploys collapsed with durations; search, expand all, wrap, timestamps, copy and download in its toolbar) → `RelatedCards` (runtime logs, configuration, previous deployment, build history). Steps is the complete log; there is no separate Full log section.

**Log explorer** (`features/project-logs/log-explorer.tsx`, page `projects/logs`, full-bleed): one toolbar row (query bar, source segmented All · Runtime · Build, range 15m · 1h · 24h · 7d, Live), the histogram with deploy markers and errors stacked red, the filter rail (counts) left, the virtualised table, the detail drawer right (a sheet on phones). The service panel's Logs tab is the same explorer scoped to one service (no rail, no service column). Empty state: one sentence plus "Widen the range" / "Follow live". Search is server-side: the count line reads "59 matching lines · all environments" ("newest 5,000 shown" when capped); on the project Logs tab a segmented "production · All environments" sits before Source; the team **Logs** page (owners/admins) is the same explorer, full-bleed in `Page size="full"` with a fixed height so only the table scrolls. Wide scopes put the origin in the service cell, muted ("staging / web", header "Where"), never an extra column, so the message keeps its width next to the drawer; no Live outside one environment. Settings → Logs (`features/logs/log-retention-settings.tsx`): one `SettingsCard` per environment (switch row; Keep for / Up to selects only while on), stored size in the footer with the card's outline Save.

**Backups tab** (`features/backups/backups-tab.tsx`, inspired by Umbrel's storage manager): a protection card (backup status word as the heading, the schedule in one sentence, "Back up now" outline right; recessed facts strip: next backup, keeps, stored in), the snapshot timeline (dots on a hairline rail, newest first, six shown, each a radio: when, why, short id, size), "Restore" as `OptionCards` ("As a new service", the default and safe; "Into postgres", replaces data after a safety snapshot) with one button that opens the confirmation (typed for in place), and one History list of restores and runs. No tables. Restore verification and point-in-time recovery (docs/14 §10): the facts strip has **Last verified** (✓/✕ + time, a quiet "Verify now" link); Postgres gets a **Point-in-time recovery** card under it (heading + one sentence + switch right; facts: status word, "Since <start>", how far back as a small select); the timeline's first entry is **Any point in time** (filled ring, "From … to about a minute ago", "Continuous" right) which opens an inline seconds date-time picker with a "Newest" chip; a **Trend** section (bar strip of one backup kind, height = size, failed = short red bar with ✕, then three facts) sits above History; History leads with the newest verification ("Verified restorable · snapshot …", what was restored and counted).

**Move data** (`features/data-transfer/`, docs/14 §12.6), a section of the Backups tab under Restore: databases get **Import data…** (outline) and **Download now** (ghost) in one row; services with volumes list each volume (mount path in mono) with **Import files…** and **Download**; engines without imports say so in one line; a server that can't do it yet shows one ⚠ status line instead of enabling the buttons. A selected snapshot gets a ghost **Download this backup** next to the restore button. Runs (imports and downloads) are one list of rows: `Status` word + "what into where", relative time, the live step line from the server while it runs ("Fetching 120 MB of 400 MB"), the outcome in one line ("14 tables · 48 MB · in 1m 13s · from PostgreSQL 15.8"), warnings as quiet lines, errors in `type-mono-small`, **Try again** on failures; a ready download is one primary **Download <file> (412 MB)** button (a fresh one-use link each click) with "Kept until 14:30". Actions use the signed-in session and check permissions without another password prompt. The **import sheet** (`SidePanel`, md) has three numbered `SheetSection`s on one scroll: *1 · Source* (`OptionCards` "From a dump file" / "From a connection URL"; a dashed drop zone that uploads at once and resumes after a reload, a progress card with percent, size of size, speed and time left, then the file with what was detected; the URL is a password-type mono input with **Check connection** and a ✓/✕ line), *2 · Target* (one sentence from the inspection: "postgres is empty" / "postgres has 14 tables (48 MB)"; then "Replace everything" or Merge / Replace contents as `OptionCards`, "Stop umami while importing" and "Leave out the top folder uploads/" checkboxes, owner "Keep from the archive" / "Set an owner" with UID:GID defaulting to the volume's owner), *3 · What will happen* (a numbered list, the missing safety snapshot as an amber ⚠ line, typed confirmation when data is replaced). The footer's primary button says **Import** or, red outline, **Replace and import**.

**Project settings** (`pages/projects/settings.tsx`): `Page size="settings"` with the project `SettingsNav` (General · Environments · Previews · Danger zone). General: a Project card (name, slug) + Secrets card. Environments: environment cards (name, kind chip for production/preview, what deploys it, revision · age, services; ⋯ for rename/triggers, compare, resume, delete) and "New environment" as a dialog. Previews: on/off card, "Which pull requests" rows, fork policy as option cards, Access switches, one option-card group per database, Automated tests (bypass secret).

**Service settings** (`features/service-settings`, docs/08 §16): the panel's Settings tab is a settings area with its own sidebar.

| Piece | API / rule |
|---|---|
| Layout | `SettingsTab {context, section}`: an `@container/settings` grid, 208 px sidebar + page (max 760 px, `@container/page`) from 768 px of tab width; below, `SettingsDrillDown`. The tab opens the panel full width (width transition `slow`). Pages stay mounted once visited (`hidden` when not open). |
| `SettingsSidebar` | `areas` (from `settingsAreas(context)`: `{ id, label, icon, pages }[]`; one-page areas render as the page), `current`, `hrefFor`, `onNavigate`. Rows 36 px: 16 px icon, label, chevron for areas; active = `bg-hover` + medium; a sub-menu has a centred muted title with a back chevron left; signal dot = unsaved edits. Sub-menus slide in 8 px (`base`). ↑/↓/Home/End, → opens, ← back. |
| `SettingsSearch` | `index` (`settingsIndex(context, areas)` from `settings-index.ts`), `query`/`onQuery`, `onSelect`, `inputRef`. 36 px field (search icon, `/` key hint, clear ×) on top of the sidebar or the drill-down; with a query the results replace the list: page name in muted `type-small`, then rows of title + one muted hint, the active row `bg-hover` with a faint ↵ (on phones: one card per page, 56 px rows). Combobox + listbox semantics with `aria-activedescendant`. Rows and cards carry `setting="…"` (`SettingsRow`, `SettingsCard`, `OverrideRow`) or `data-setting`; the result's row is scrolled to the centre, gets `data-setting-flash` for 1.6 s (app.css: signal-soft tint + 2 px signal edge on rows, a 10 px signal-soft halo on cards and sections, 700 ms fade) and its first control is focused. |
| `SettingsDrillDown` | Phones: one card of 64 px rows (36 px recessed icon tile, label, one quiet summary, chevron); an area opens its pages with "‹ All settings". The page shows a back row naming its area. |
| `SettingsPage` | `title` (20/28 semibold), `description` (one sentence), `actions` (≤ 1 primary, e.g. Add domain); cards 32 px apart. `SaveRow` (the UnsavedBar) only shows for the open page; `label` "Save for next deploy" when saving stages. |
| `OverrideRow` | Detected + Override as a row: label, "Detected `value`" in mono under it (or a fallback sentence), Override switch right; on, an input replaces the line and off resets. `source` changes the word ("From the environment:", "Pinned by launchroom:"). |
| Object heroes | Source/Build start with the thing itself in a card: 44 px logo tile, mono name with copy, 24 px outline chips (branch, root, tag, toolchain), a recessed footer for state ("Up to date · main at 3b7d5e1 · 2 h ago"). |
| Domain card | Header (icon tile: lock when the certificate is issued, globe otherwise; mono hostname 15 px; status pill), then `DomainPath`: three columns DNS → HTTPS → Traffic (label, status icon + word, one line) with a faint arrow between; rows on phones. DNS setup in a recessed block: copyable record rows (type chip, name, value) and the live re-check line. Switch rows and Remove at the bottom. |
| Server placement card | `ServerPlacementCard` on `DeviceStage` (150 px recessed stage, device 168 px; things stand on the device's top edge using the art's body box). Replicas are `ReplicaUnits`: service-logo tiles (32 px up to four, 20 px beyond), dashed signal = added by the draft, hollow dashed = going away. `CapacityBar` per resource: grey others, signal this service, track free, amber only past the total, `role="meter"`. Ineligible servers: art at 45 % greyscale and the scheduler's reason instead of the place. Rule menu in the footer. |
| `ResourceSlider` | Native range over sensible steps (CPU 0.1 … 64, memory 128 MB … 64 GB) ending at the biggest eligible server; value in `type-stat`, total for all replicas right, signal fill, amber when larger than any server. |
| Strategy pictures | Spread/Pack option cards carry a 3-server mini picture (signal replica blocks on outline servers). |
| Volume card | Left: `DeviceStage` with the volume as a 40 px drive tile standing on the server (dashed signal tile before it is placed); right: mount path, status pill, a two-fact strip (Size, Backups with a shield icon) and a footer with "Backups →". |
| Edge servers | Under the domain cards: `type-label` title, one muted sentence, then a grid of tiles (1 column, 2 from `@md/page`, 3 from `@2xl/page`): 80 px recessed well with the `DeviceImage` (size 80, never scaled on one axis, LED from the art), name in `type-body` medium, `ServerStatus` below. Never inline device art in running text. |
| Guided dialogs | Add domain and Add volume: one field (large mono input, live hint below), suggestions as chips, consequences as numbered rows on `--recessed`, one primary. Add domain continues in place with the records and a `LiveWait` until DNS points here. |

**Servers and fleet** (`features/servers`)

| Piece | API / rule |
|---|---|
| Role icons | `roleIcons` (`server-status.tsx`): runtime `AppWindow`, edge `Globe`, build `Hammer`, registry `Package`; the dashboard is `LayoutDashboard`. Monochrome. `RoleCard` shows the icon in a 36 px recessed tile (foreground when on, muted when off); `RoleChipLabel` puts a 12 px muted icon before the label in role chips (fleet cards, server hero). |
| `FleetLoad` | `capacity` (summed specs, shown before metrics arrive), `serverCount`. Card with "System load", Hour/Day/Week, three tabs (CPU / Memory / Disk: share now, "6.6 of 36 cores" / "16 GB of 35 GB") and one signal `TimeSeriesChart` (0–100 %) of the selected tab. Data: `FleetMetricsData.totals`. |
| `FleetTopology` | `servers`, `links` (`MeshLinkData[]`). Tiers Internet → edges → the rest as small nodes (device render with LED at 60 px, name, status word only when not online, duty icons); public traffic in foreground, private network in signal (solid direct, dashed relayed, dotted idle); hover highlights a node's links; one stacked column when the tiers don't fit; legend explains the icons and line styles; a screen-reader list names every link. Measured links (`latencyMs`, `speeds`) get a `type-mono-small` pill on the line, placed along the curve where it overlaps no node or other pill (full label, else the round trip alone, else none), and a two-column "Measured links" list above the legend with the full numbers. Never shows numbers we don't measure. |
| `ServerLinks` | `serverName`, `peers` (`MeshPeerData[]` with `quality`), `measuring`. Server page section "Private network": one row per peer in a card (name links to the server, path · mesh IP, a muted footnote with the speed test age and failed checks), a 28 px signal sparkline of the 24 h round trip (hidden on phones), the round trip in `type-heading` with "round trip", ↓/↑ rates (side by side on phones). A failed latest probe is a warning icon + "No answer"; nothing measured yet is muted text. Helpers `link-format.ts` (`formatLatency`, `formatRate`, `linkLabel`). |
| At a glance | Four rows with a 28 px recessed icon tile, label and one sentence (running replicas, where traffic enters, where builds run, the private network). Amber ⚠ only for a real gap (no edge, no build server), never for the server count. |
| Pending servers | `PendingCardMenu` (⋯ in the card's title row: "Remove server"), `RemovePendingButton`, `RemovePendingDialog` (`remove-pending.tsx`): `target: { kind: 'enrollment' \| 'server', id, name }`; one sentence of consequence, outline-red confirm. |
| One server | Never a warning. The ghost card and the topology carry the positive hint ("Use a second server to improve reliability"). |
| Spacing | Servers and a server's page breathe more than the 40 px default: `Page className="gap-12"` (48 px between sections), 24 px between fleet cards and overview tiles, 24 px card padding (`FleetLoad`, `FleetTopology`, At a glance, health, connection), 16 px between role cards, 16 px rows in At a glance. Widths stay the default `Page` size. |
| Server tabs | The server page is hero → `PillTabs` (`components/patterns/pill-tabs.tsx`, the same segmented row and sliding pill as the project top-bar tabs) on a hairline → the tab's sections. Hero, callout and tab bar are 32 px apart; tab content keeps the 48 px rhythm, Settings cards 40 px. `PillTabs`: `tabs` (`label`, `href`, `active`, `count?`), `label` (landmark name), `scroll` (sideways scroll on narrow screens, edge fade only while tabs are hidden, the selected tab scrolled into view), `preserve` (keep scroll + state). Links with `aria-current="page"`, focus ring, ←/→/Home/End move focus. The project top bar keeps its menu below `@4xl`. |
| `ServerGlance` | Overview "At a glance": one card of four facts (`sm` 2, `xl` 4 columns), each a link with a 28 px recessed icon tile, muted label, one value line and a muted detail, chevron on hover; then attention rows (retired volumes needing an OK, amber ⚠) and `RoleHints`. |
| Role hints | `RoleHints` (`role-hints.tsx`): `hints: RoleHintData[]` (`id`, `tone: info\|warning`, `title`, `body`, `docs?`), rendered under the role cards in Add server ("What should it do?", picked from the `roleHints` map by `roleHintKey(machine, roles)`, e.g. `home:build,edge`) and on the server page ("What this server does", `roleHints` for its current roles). Each is a quiet bordered row: 28 px recessed icon tile (lightbulb, muted; ⚠ in amber only for a real risk), one bold line, one sentence and an optional "Learn more ↗" to the docs. At most two, risks first; the region is `aria-live="polite"` and stays mounted. Rules live in one pure PHP function, `App\Support\Servers\RoleHints` (Pest unit tests), fed by `App\Queries\ServerRoleHints`; the page never derives hints itself. |


**Metrics** ([15](15-observability.md) §2, `features/metrics`)

| Piece | API / rule |
|---|---|
| `deployMarkers(deploys, serviceId?)` | `format.ts`: `DeployMarkerData[]` → `ChartMarker[]` labelled with the short SHA, red for failed deploys, `detail` "web · 3b7d5e1 · live" as the hover title. Every time chart of a service, project or server uses it. `ChartMarker.detail` (optional) renders as the marker's SVG `<title>`. |
| Formatters | `formatRate` ("12.4", "0.03", "1.2K"), `formatMs` ("38 ms", "1.2 s", "> 30 s"), `formatErrorRate`, `formatBandwidth` ("1.2 MB/s"), `formatCores` ("120m"), `formatMemory`; `delta(current, previous, 'neutral' \| 'lowerIsBetter')` → a `StatDelta` (coloured only for lower-is-better metrics and changes ≥ 5 %, hidden without a previous value). |
| `MetricCard` | `label`, `value`, `unit?`, `delta?`, `rows: { key, label, logo?, values, value }[]` (≤ 3 + "Other"), `min?`/`max?`, `selected`, `onSelect`, `hint?` (shown without rows). The whole card is a toggle button (`aria-pressed`) that picks the big chart; selected = 1.5 px foreground ring. Rows: 20 px logo tile, name, a 20 px signal sparkline, the value; all signal, never a colour per service. |
| `ProjectMetrics` | The project Metrics page body: four traffic cards (4 → 2 → 1), two usage cards (2 → 1), the selected card's `TimeSeriesChart` (240 px, deploy markers, table toggle) in a card, then service rows linking to the panel's Metrics tab. No traffic: one framed `EmptyState` instead of the traffic cards. |
| `ServerCharts` | Server page Metrics tab: one card per chart (CPU %, memory %, disk %, network rx/tx, public traffic for edges), 2 → 1 columns, title + current value right, charts without data left out; `actions` puts the range select in the header; skeleton cards while loading, an `EmptyState` before the first samples. |

**Templates and Compose import** ([17](17-templates-and-compose.md) §4, `features/blueprints`, `features/templates`)

| Piece | API / rule |
|---|---|
| `NewProjectTabs` | `current: repository \| template \| compose`: the three ways to start as `PillTabs` under the `/projects/new*` page titles. |
| `BlueprintCanvas` | `preview` (`BlueprintPreviewData`), `onSelect?`, `selected?`: the canvas a blueprint becomes, before it exists: the real canvas' Public · App · Data frames (18 px radius, 3 % wash, label + count) side by side (stacked on phones), a 32 px logo tile + name + subtitle per service, quiet `--border-strong` curves (dashed for a worker's link to its app), a red `✕ n` only when lines were refused. Picture only; `role="img"` names the services. `resourcesLine()` → "2 CPUs · 1 GB · 1 volume". |
| `NoteList` | `notes` (`BlueprintNote[]`): one row per line of the source: ✓ translated and – ignored muted, ✕ refused the only red, ⚠ amber "check this"; the line number right in `mono-small`. |
| `TemplateGallery` | `templates`, `categories`, `onOpen`, `compact?`: search + category pills (filled = selected) over a 3/2/1 grid of app cards (44 px logo tile, name, category, two-line tagline, a hairline footer "2 services · 2 CPUs · 1 GB"); `compact` is the two-column version in the canvas add sheet (no logo stack). |
| `TemplateSetup` | `template`, `url`, `askName`, `submitLabel`, `onDone?`: the detail sheet's sections: description, **What you get** (`BlueprintCanvas` + which secrets are generated), **Resources** (recessed three-fact strip), **Set it up** (project name, `RepositoryField` for stacks, the template's few inputs), then licence/website and the one primary (Deploy / Add to canvas). |
| `RepositoryField` | Compact repository search → picked row with a branch select and Change. |
| Compose import page | `Page size="narrow"`: Your file (option cards Paste or upload / From a repository, mono textarea capped at 480 px with Upload and drop, a collapsed "Values for ${…}"), Here's your project (`BlueprintCanvas`, clicking a tile scrolls to its card), Line by line (one card per service: 44 px logo, what it becomes, `n refused` chip, database option cards Managed / Keep as a container, "Convert to volumes" switch, `NoteList`), Values (generated names as chips, missing ones as fields, pasted env files), Name and deploy. |

**Updates** (`features/updates`): a guided flow is a hero card (`HeroCard`: 44 px soft icon disc, `type-stat` title, one meta line, actions right, a body indented to the title, a recessed footer) whose body says **What happens** as numbered rows (20 px hollow numerals, title + one quiet sentence) before the one primary action; while it runs the same steps are a `StepList` under a hero with a spinner disc. After something didn't go through, an attention hero (amber disc, never a red frame) gives one summary sentence, **What you can do** as bullets and **What happened** as the step list; the dashboard being up is itself the reassurance. A Stable/Beta choice is the segmented `TimeRangeSelect` with custom options; a switch that is its own action applies on change.

**Generate a Dockerfile** (D48, [20](20-dockerfile-generation.md) §7, `features/dockerfile-generation`)

| Piece | API / rule |
|---|---|
| `DockerfileGenerationSheet` | `open`, `onOpenChange`, `route`, `panel` (`DockerfileGenerationPanelData`), `trigger`. One `SidePanel` lg through intro → live `StepList` → verified / failed; polls the generation every 2 s while it runs (`useGeneration`). One primary per state (Generate a Dockerfile, Use this Dockerfile, Try again). |
| `SharingNotice` | `providerLabel`, `model`: three recessed rows (file names, manifests and entry files, never secrets) + one sentence naming the provider, model and cost. Also on the settings page. |
| `DockerfileDiff` | `previous`, `next`: mono table with line numbers and a `+`/`−` gutter (sr-only "Added:"/"Removed:"), tints only together with the gutter sign; caption "12 lines" or "3 added · 1 removed"; max 480 px, scrolls. |
| States | Verified: green check disc + "Your app builds and starts" + a recessed facts strip. Failed: amber ⚠ disc (never red), the reason in one sentence, Try again. Cancelled returns to the intro. |

### 13.10 Console & Data (`features/sessions`)

Service-panel tabs for interactive sessions (docs/08 §15, transport docs/03 §11). Both open only when the user asks, end with the tab/panel/page, and show the same calm states: an "Open …" card (one primary), a `LiveWait` while connecting, a one-line status row (green dot "Connected", what/where, elapsed, ghost "End session"), and an ended row (hollow dot "Session ended", the reason, outline "Open a new console").

| Piece | API / rule |
|---|---|
| `ConsoleTab` / `DataTab` | `{project, environment, service}`; data from `GET sessions/p/…/{console\|data}` via `useTabData`. |
| `Terminal` | xterm.js on the recessed surface, Geist Mono 13 px, colours from tokens (`--foreground`, `--recessed`, `--ansi-*`, selection `--signal-soft`), re-themed on scheme change; fit addon, resize reported only on real size changes. `ref`: `write`, `focus`, `size`. |
| Replica picker | Bordered radio chips "Replica 1 · server"; one replica = a plain line; unhealthy replicas say so in amber text. |
| `ResultGrid` | `columns`, `rows`, `offset`: sticky header on recessed, sticky row numbers, mono cells, `NullChip`, cells over 80 chars cut with the full value in a popover. Pages of 50 with Previous/Next. |
| Data workspace | `@container`: schema/key sidebar (220 px) beside the Browse · Query tabs from `@3xl`, stacked (sidebar capped at 224 px) below. Query: mono textarea (⌘↵), `Read-only` chip ↔ amber "Writes allowed" after a `ConfirmPopover`, History popover, Run. Result line "25 rows · 67 ms" / "3 rows affected" / the error in one red line. |
| `RecentSessions` | Rows: you/name on replica · server · shell, duration · end reason, age; End (canEnd) and Recording (opens a `SidePanel` with `AnsiText` lines). |
| Team access | Settings → Members: the member `⋯` menu gets an "Access" group (Console, Data checkboxes) for developers; granted access shows as "+ Console & Data" before the role chip. "Console recording" `SettingsCard` with one switch (owners). |

Frames are signed with WebCrypto, or a small pure HMAC-SHA256 (`hmac.ts`) where `crypto.subtle` is missing (plain-HTTP dashboards on a LAN address).
