# App shell & navigation: research report (NAV-)

Screens studied: fresh `ux-round/before/{canvas,activity,env-switcher,command-palette}-light.png`; older `site-retake/raw/{projects,servers,env-switcher,command-palette,settings-search}-light.png`; phone: `analytics/projects-mobile-light.png`, `shell/reconnect-mobile-light.png`, `ui1/mobile-canvas-light.png` (the fresh `x-mobile-*` shots did not exist yet when I looked). Code: `features/app-shell/*`, `features/projects/project-top-bar.tsx`, `components/patterns/top-bar.tsx`, `features/command-palette/command-palette.tsx`, `components/ui/sonner.tsx`, `features/deployments/deploy-toasts.tsx`, `features/canvas/canvas-toolbar.tsx`.

## 1. Current state

- **Project top bar is already the right shape.** `project ▾ / environment ▾ | Canvas Deployments Logs Analytics Previews Settings` in one 52 px row (canvas, env-switcher) is the same order Laravel Cloud, Supabase, Neon and Railway use. The shape doesn't need to change. What's missing is what the switchers tell you.
- **The switchers are plain lists.** The project menu has no filter even though the demo team has 26 projects (projects screenshot), and you can only scroll it. The environment menu (env-switcher) shows names and branches, but no health, no production marker and no "New environment". Nothing in the bar tells you the environment you're in is failing or stopped. You only find out from the canvas.
- **Team pages say their name twice and nothing else.** On projects, servers and activity the top bar holds only the sidebar toggle and a one-item breadcrumb ("Projects"), with the H1 "Projects" right under it. On phones (projects-mobile, reconnect-mobile) the bar is `[toggle] Projects` above a huge "Projects" H1. Search and the user are one tap into the sheet away, and nothing in the bar signals that anything needs you.
- **Nothing in the shell says "something needs you".** Activity sits in the sidebar footer with no count (activity screenshot shows open alerts, "Failed", "Open"). Deploy outcome toasts are mounted only by `ProjectLayout` (`deploy-toasts.tsx`), so a deploy that fails while you're on Servers or Settings says nothing. The only shell signal is Security's count, which reads **355** in the fresh shots: a number too big to act on.
- **⌘K is good but inconsistent.** It's context-first (services in this project with status, then Actions, then the project's pages with `g` shortcuts), which matches what mature palettes do. But the same screen labels the shortcut `Ctrl K` in the sidebar and `⌘K` in the canvas toolbar (canvas, command-palette). `canvas-toolbar.tsx:113-117` hardcodes `⌘K` while `app-sidebar.tsx` detects the platform. The Navigate group is missing team pages that are in the sidebar (Analytics, Security, Logs) and every create verb except "New project".
- **Team switcher spends its subtitle on the role** ("Owner"). That rarely changes and is rarely useful. Meanwhile the one fact a 1–2 server user wants at a glance, whether their servers are fine, isn't anywhere in the chrome.

## 2. Patterns observed

### Laravel Cloud first (primary reference)
Flows: [Home](https://mobbin.com/flows/a95840a2-b400-4294-9109-343676796c10), [View an application](https://mobbin.com/flows/7d557b82-d753-406a-bc11-bca160b1d99b), [Searching Laravel Cloud](https://mobbin.com/flows/f1e29748-abaa-4289-81e7-89974776c715), [Application detail](https://mobbin.com/flows/208f0f71-fb17-49ed-9ee0-bf49640641e2), [Updating environment settings](https://mobbin.com/flows/eca009e6-ea0b-4020-916f-bf87cbbc8217), [Creating an environment](https://mobbin.com/flows/c435540c-7c02-4608-b608-6ec370e74e73), [Logging out](https://mobbin.com/flows/f5fefa36-b35d-41a5-81eb-f0b16fcbdf0b).

- **No sidebar. The breadcrumb is the navigation.** Top row: logo, `Org ▾ / app ▾ / ■ env ▾`, then a **live status chip for the environment** ("Hibernating 15h", "Stopped 6h") right after the env name. Search ⌘K and the avatar sit on the right. Tabs (Environment, Deployments, Commands, Logs, Metrics, Settings) are on the row below. See [environment page](https://mobbin.com/screens/63204509-6daa-4fb9-ac50-c4f4bb571442) and [stopped chip](https://mobbin.com/screens/f54892bf-16e5-4a69-98e6-4f50ae970d1b). The point: you never lose track of *which* environment you're in or *what state* it's in, whatever the tab.
- **Environments have an identity.** Each environment gets a small coloured square, chosen in env settings ("Environment color", [settings](https://mobbin.com/screens/ac786e44-e697-4c82-ba1e-d4e3e77e2c61)). The square appears in the breadcrumb, on overview cards and in usage pickers. We'd keep this monochrome (see NAV-2), but the idea of a persistent env marker carries over.
- **Org level is tabs, not a sidebar** (Overview, Applications, Resources, Usage, Settings, [overview](https://mobbin.com/screens/4f63d98f-e49b-4d01-b220-8325bf7333b9)). Overview leads with "Recently deployed" cards and "Latest deployments": a feed of what just happened, not a menu.
- **⌘K opens grouped by scope** ("Organization": Overview, Applications, Databases, Caches, Buckets, Websocket clusters…). Typing narrows to typed groups ("Applications"). See [open](https://mobbin.com/screens/e9d5f1b5-4760-4ef6-971f-08f4fea7a9f4) and [typed](https://mobbin.com/screens/719ec5a9-5eca-461b-a9f2-707927fd39b6).
- **Feedback is bottom-centre and dark.** The "Unsaved changes · Reset · Save" bar ([unsaved](https://mobbin.com/screens/bce31f98-3283-4c47-971c-8a0dda21ca4b)) and the toast "Changes saved" ([toast](https://mobbin.com/screens/f2592e78-06f4-4054-825d-0502bc96ee36)) share one spot, so your eye stays in one place. We already do the bar this way. Our toasts are bottom-right.
- **The user menu is small**: Account, Docs, Help, Sign out ([avatar menu](https://mobbin.com/screens/a7e6e8fb-309d-4fdd-b3c3-8658be5a7f52)). The footer holds Status, Docs, Help and Legal.

### Corroborating patterns

| Pattern | Who | Why it works |
|---|---|---|
| **Switcher menus have a filter field on top and "Create" at the bottom** | Vercel project switcher "Find Project…" + "Create Project" ([1](https://mobbin.com/screens/63496168-dbae-470d-9039-6883d8d1e479)), Vercel team switcher "Find Team…" + "Create Team" ([2](https://mobbin.com/screens/7be05ea5-e875-439d-bd1f-7aef97281d3b)), Vercel "Find…" mixing projects and pages ([3](https://mobbin.com/screens/018ade73-2149-4941-8b2e-1086c81857f3)), Linear "Move to" menu with "Filter…" ([4](https://mobbin.com/screens/f1e8745b-81b7-4a78-85e1-a325f4843f42)) | Past about 8 items, scrolling a menu is slower than typing two letters. The create item turns the switcher into the entry point. |
| **Environment/branch marked in the breadcrumb** | Laravel Cloud status chip (above), Supabase `main [PRODUCTION]` tag next to the branch ([5](https://mobbin.com/screens/f89f0db8-f9be-41fa-b36b-617bc966067c)), Neon branch selector in the sidebar plus a "Default" tag on the production branch ([6](https://mobbin.com/screens/3bd5034c-7783-48e1-bbb9-2e87d0ac8402)), Modal env dropdown with "Create environment" ([7](https://mobbin.com/screens/2076b69d-345d-4731-8486-e5f9b4d367d6)) | The biggest navigation risk in a PaaS is acting on the wrong environment. Every product puts a non-name cue (tag, chip, square) on production. |
| **Shell-level inbox with a count** | Vercel bell by the user with Inbox (5)/Archive tabs, items like "x failed to deploy in the Production environment" with unread dots and "Archive All" ([8](https://mobbin.com/screens/aa03d3e2-37e1-42e7-96d7-b847be86a427)). Vercel "Action Required" card in the sidebar footer ([9](https://mobbin.com/screens/374a0ea8-bbef-475b-9a6a-d02f99a64747)). Railway bell in the project top bar with Project/All tabs and "Deployment failed to build" ([10](https://mobbin.com/screens/5d97ddda-d7ed-4834-8b22-e51d737ffb8a)). Linear "Inbox 1" at the top of the sidebar ([11](https://mobbin.com/screens/ecc68a89-accd-4663-b0f8-0fc451cd4897)). Stripe bell in the top bar ([12](https://mobbin.com/screens/548c9ebe-743d-4d11-a4a4-998080ac7965)) | Deploy failures happen while you're elsewhere. The shell is the only place that's always on screen. Each one ties a count to a short list of failures. |
| **Small "all OK" health pill in the chrome** | Neon "● All OK" in the top bar ([13](https://mobbin.com/screens/d440b7cd-f79c-492d-9966-b52823033ae6)), Railway trial/credit pill in the same spot ([14](https://mobbin.com/screens/0b7595bd-9179-40ef-8d3d-cae33382942a)), Laravel Cloud footer "Status" | One glanceable signal for "is my infrastructure fine". For a self-hosted user that's "are my servers up". |
| **Tabs carry counts or state** | GitHub repo tabs "Pull requests 1", "Projects 1" ([15](https://mobbin.com/screens/f24ad9b8-f897-43b6-8e63-e6429fae5c01)), Supabase Security Advisor tabs "Errors 2 / Warnings 7" ([16](https://mobbin.com/screens/e3ac7f2c-bdd0-42a4-ac13-76fb893fd06d)) | You know whether a tab is worth opening before you click it. |
| **Palette: create verbs first, then pages, with section path** | v0 "Quick Actions" (New Chat, Import from GitHub, New Project, New Team) before Navigation ([17](https://mobbin.com/screens/5f353fc3-d9ce-4d1c-be9b-cc1b4ad07578)), incident.io Actions before Navigation ([18](https://mobbin.com/screens/907900ee-1dca-4726-ae50-9e043eecfbe4)), Vapi Recent + "All Pages" with a section breadcrumb per row ("Metrics — Observe") ([19](https://mobbin.com/screens/593d7acd-2e16-4365-bcd6-02ce52f48f3b)), Linear team menu showing `G then S` shortcuts inline ([20](https://mobbin.com/screens/e8fe60b4-6db4-4485-99b8-3395d69ea7db)) | People open ⌘K to *do* something more often than to go somewhere. Every page in the sidebar should also be in the palette. |
| **Phone: persistent bottom bar with Inbox + Search, toast above it** | Linear Mobile floating bottom bar (Inbox, My issues, Favorites, Projects) plus a separate Search button ([21](https://mobbin.com/screens/f97b18e7-5e86-42f2-85df-9e7a000fae0e)), with the toast sitting just above the bar ([22](https://mobbin.com/screens/f8e3aa03-00f2-4985-a6ae-1055ae0f4144)). GitHub iOS bottom bar Home/Inbox/Explore ([23](https://mobbin.com/screens/c0ae331f-c3a7-4cf3-9f99-99fba4ffb2ee)) | On a phone you check status and jump between 3–4 places. A hidden sheet costs two taps per hop, and search shouldn't need opening a menu. |

## 3. Recommendations (ranked by impact ÷ effort)

### NAV-1 Fix the ⌘K / Ctrl K label mismatch (S)
- **Problem:** On non-Mac platforms the same screen shows "Ctrl K" in the sidebar and "⌘K" in the canvas toolbar (canvas, command-palette fresh shots).
- **Change:** Add one `modKey()` / `MOD_LABEL` helper in `lib/` (e.g. `lib/platform.ts`) and use it in the sidebar search field, the canvas toolbar's `<kbd>` and `aria-label`, `shortcuts.tsx`, and the palette footer (where you could also add `⌘K close`, like LC and Juicebox). Remove the local `isMac` constants in `app-sidebar.tsx` and `shortcuts.tsx`.
- **Files:** `features/canvas/canvas-toolbar.tsx:113-117`, `features/app-shell/app-sidebar.tsx:44-66`, `features/command-palette/shortcuts.tsx`, new `lib/platform.ts`.
- **Evidence:** Laravel Cloud search field `⌘K` ([overview](https://mobbin.com/screens/4f63d98f-e49b-4d01-b220-8325bf7333b9)), Vapi footer key hints ([19](https://mobbin.com/screens/593d7acd-2e16-4365-bcd6-02ce52f48f3b)).
- **Doc:** none.

### NAV-2 Put environment identity and state into the switcher (M)
- **Problem:** "production ▾" looks the same whether everything is live, a deploy just failed or nothing is running. Production isn't marked in the menu, so a user who names environments "main"/"live" gets no cue. You can't create an environment from the menu.
- **Change:**
  1. **Trigger:** after the env name, show the environment's rolled-up `Status` as icon + short word, only when it isn't healthy ("⚠ 2 need attention", "✕ Failed", "○ Not deployed"). Healthy shows nothing, which keeps the bar calm. This is LC's chip done in our status vocabulary. The data already exists: `projectStatuses` per project, and `ProjectSummaries` environments carry `problems`.
  2. **Menu rows:** status icon + the existing branch mono, plus a quiet outlined "Production" tag on the env whose `kind === 'production'` (`EnvironmentKind::Production`). It's text, not colour, so it fits our monochrome rule better than LC's squares.
  3. Add a "New environment" item, matching "Manage environments". It opens settings → environments with the add form open.
  4. In the phone combined switcher, show the same status icon under the project name.
- **Files:** `features/projects/project-top-bar.tsx` (`EnvironmentItem`, the env trigger, the phone trigger), `features/projects/shell-props.ts` / `App\Data\Canvas\ProjectShellPropsData` (per-environment status, lazy like `projectStatuses`), `App\Data\Canvas\EnvironmentData` (expose `kind` for production, already present).
- **Evidence:** LC env chip ([63204509](https://mobbin.com/screens/63204509-6daa-4fb9-ac50-c4f4bb571442), [f54892bf](https://mobbin.com/screens/f54892bf-16e5-4a69-98e6-4f50ae970d1b)), Supabase PRODUCTION tag ([5](https://mobbin.com/screens/f89f0db8-f9be-41fa-b36b-617bc966067c)), Neon "Default" tag ([6](https://mobbin.com/screens/3bd5034c-7783-48e1-bbb9-2e87d0ac8402)), Modal "Create environment" ([7](https://mobbin.com/screens/2076b69d-345d-4731-8486-e5f9b4d367d6)).
- **Doc:** `docs/08-dashboard-ux.md` §1 "Project level" (env switcher contents), `docs/design-system.md` §4.1.

### NAV-3 Searchable project switcher with "New project" (S)
- **Problem:** The project menu is a flat `DropdownMenu` of every project. With 26 projects (projects screenshot) it's a long scroll, and "All projects" and "Project activity" sit at the bottom.
- **Change:** Swap the project menu content for a cmdk `Command` inside a `Popover`: a "Find project…" input on top (autofocus, type to filter, ↑↓/↵), recent projects first (reuse `features/command-palette/recent.ts`), then the rest with status as today. The footer gets "+ New project", "All projects" and "Project activity". Keep the 75 ms hover prefetch. Above about 8 environments, apply the same treatment to the env menu (previews can push it there).
- **Files:** `features/projects/project-top-bar.tsx` (`projectItems`, desktop and phone menus), `components/ui/command.tsx` (already exists), `features/command-palette/recent.ts`.
- **Evidence:** Vercel "Find Project…" + "Create Project" ([1](https://mobbin.com/screens/63496168-dbae-470d-9039-6883d8d1e479)), Vercel team "Find Team…" ([2](https://mobbin.com/screens/7be05ea5-e875-439d-bd1f-7aef97281d3b)), Linear "Filter…" ([4](https://mobbin.com/screens/f1e8745b-81b7-4a78-85e1-a325f4843f42)).
- **Doc:** `docs/08-dashboard-ux.md` §1 "Project level" (one sentence).

### NAV-4 "Needs you" in the shell: Activity with a count, team-wide outcome toasts (M)
- **Problem:** Failures and open alerts are only visible if you're on the right page. Activity is in the footer with no count. Deploy toasts only fire on project pages.
- **Change:**
  1. Move **Activity** from the footer into the main nav, right under Projects. Give it the open-alerts count as `meta` (same non-colour cue as Security). Add a quiet dot (`dot`) when a deploy failed since the user last opened Activity (needs a per-user `activity_seen_at`). Shared prop next to `securityActionNeeded`, e.g. `openAlerts: int`.
  2. Mount the deploy outcome toasts in `AppShell` for **deployments the current user started** (any page), and keep the project-scoped listener for everything in the open project. Use the user's private channel if one exists. Otherwise subscribe to the team channel and filter by `triggeredBy`.
  3. Optional follow-up (L): a small inbox popover from that nav item (Vercel/Railway style) listing the `TeamAttention` items with "Open". The projects page "Needs you" list already computes them.
- **Files:** `features/app-shell/app-sidebar.tsx`, `features/app-shell/app-shell.tsx`, `features/deployments/deploy-toasts.tsx`, `app/Http/Middleware/HandleInertiaRequests.php`, `app/Data/Inertia/SharedPropsData.php`, `app/Queries/TeamAttention.php` (count only).
- **Evidence:** Linear "Inbox 1" at the top of the sidebar ([11](https://mobbin.com/screens/ecc68a89-accd-4663-b0f8-0fc451cd4897)), Vercel inbox "failed to deploy in the Production environment" ([8](https://mobbin.com/screens/aa03d3e2-37e1-42e7-96d7-b847be86a427)), Railway project/all notifications ([10](https://mobbin.com/screens/5d97ddda-d7ed-4834-8b22-e51d737ffb8a)).
- **Doc:** `docs/08-dashboard-ux.md` §1 "Sidebar" (Activity moves up, gets a count) and "Project level" (toasts mounted by the shell), `docs/design-system.md` §4.1.

### NAV-5 Calm the Security badge (S)
- **Problem:** "Security 355" (fresh canvas and activity shots) is a number nobody acts on, and it competes with real signals.
- **Change:** Show at most `99+`. Better: count *groups* (affected services, or distinct findings) instead of raw rows, so the badge means "things to fix". Keep the `ShieldAlert` icon swap as the non-colour cue.
- **Files:** `features/app-shell/app-sidebar.tsx:120-129`, `app/Http/Middleware/HandleInertiaRequests.php::securityActionNeeded`.
- **Evidence:** Vercel "Action Required: Take action to secure your projects" (one card, no raw count, [9](https://mobbin.com/screens/374a0ea8-bbef-475b-9a6a-d02f99a64747)), Supabase advisor tabs "Errors 2 / Warnings 7" ([16](https://mobbin.com/screens/e3ac7f2c-bdd0-42a4-ac13-76fb893fd06d)).
- **Doc:** `docs/22-security-center.md` §9 (badge counts what).

### NAV-6 Complete the palette: create verbs and every sidebar page (S–M)
- **Problem:** Navigate lists Projects, Servers, Activity and Team settings, but not Analytics, Security, Logs or Get started. The only create verb is "New project". Settings pages are only searchable inside settings (`settings-search`).
- **Change:**
  1. Add a **Create** group shown before typing, under the context groups: New project, Add a service (in this environment, when in a project; it opens the canvas Add), Add server, Invite member, Add domain (with a service open).
  2. Navigate mirrors the sidebar exactly: build both from one list in `features/app-shell/nav-items.ts` so they can't drift.
  3. When searching, include settings pages from `features/settings/settings-index.ts` with their group as a muted path ("API tokens, Account"). That's the Vapi "Metrics — Observe" pattern, done without a dash.
- **Files:** `features/command-palette/command-palette.tsx` (`Navigate` group around l.885), `features/app-shell/app-sidebar.tsx`, new `features/app-shell/nav-items.ts`, `features/settings/settings-index.ts`.
- **Evidence:** LC palette grouped by org resources ([e9d5f1b5](https://mobbin.com/screens/e9d5f1b5-4760-4ef6-971f-08f4fea7a9f4)), v0 Quick Actions ([17](https://mobbin.com/screens/5f353fc3-d9ce-4d1c-be9b-cc1b4ad07578)), incident.io Actions then Navigation ([18](https://mobbin.com/screens/907900ee-1dca-4726-ae50-9e043eecfbe4)), Vapi pages with section ([19](https://mobbin.com/screens/593d7acd-2e16-4365-bcd6-02ce52f48f3b)).
- **Doc:** `docs/design/flows.md` §17, `docs/design-system.md` §6 "Command palette" (add the Create section).

### NAV-7 Phone shell: search and status in the top bar, then a bottom bar (M → L)
- **Problem:** At 390 px the top bar is `[toggle] Projects`, repeating the H1 below it. Search, Activity and the user are behind the sheet. Every hop costs two taps.
- **Change, step 1 (M):** On `< md`, the team top bar shows the launchroom mark (opens the sidebar sheet, replacing the bare toggle icon) and a search icon button on the right (opens ⌘K). On one-level team pages, drop the breadcrumb text entirely because the H1 already names the page. On the project top bar, keep the combined switcher and add the search button.
- **Change, step 2 (L, decide after step 1):** A fixed bottom bar on phones with Projects, Servers, Activity (with the NAV-4 count) and Search, 56 px plus the safe-area inset. `UnsavedBar`, `StagedBar`, the canvas phone list "Add" and toasts sit above it (sonner `offset`). Hidden when a sheet is full screen.
- **Files:** `components/patterns/top-bar.tsx` (`TopBar`, `TopBarBreadcrumbs`), `features/projects/project-top-bar.tsx`, `features/app-shell/app-layout.tsx`, new `features/app-shell/phone-nav.tsx`, `components/ui/sonner.tsx` (mobile offset), `components/patterns/unsaved-bar.tsx`.
- **Evidence:** Linear Mobile bottom bar + Search, toast above it ([21](https://mobbin.com/screens/f97b18e7-5e86-42f2-85df-9e7a000fae0e), [22](https://mobbin.com/screens/f8e3aa03-00f2-4985-a6ae-1055ae0f4144)), GitHub iOS Home/Inbox/Explore ([23](https://mobbin.com/screens/c0ae331f-c3a7-4cf3-9f99-99fba4ffb2ee)), LC search as a top-bar field next to the avatar ([4f63d98f](https://mobbin.com/screens/4f63d98f-e49b-4d01-b220-8325bf7333b9)).
- **Doc:** `docs/design-system.md` §4.1 and §4.3 (phone shell), `docs/08-dashboard-ux.md` §1.

### NAV-8 Team switcher subtitle shows server health, not the role (S–M)
- **Problem:** "Owner" under the team name is static. The single most reassuring fact for a 1–2 server user, "my server is up", isn't in the chrome.
- **Change:** The subtitle becomes a status line with icon + words: "● 2 servers online", "✕ 1 server offline", "⚠ Disk almost full". Clicking the line goes to Servers. The role moves into the dropdown header ("Dev's team, Owner"). Shared prop `fleet: {online, total, worst}`, cheap from `ServerHealth`.
- **Files:** `features/app-shell/team-switcher.tsx`, `app/Http/Middleware/HandleInertiaRequests.php`, `app/Data/Inertia/SharedPropsData.php`.
- **Evidence:** Neon "● All OK" in the top bar ([13](https://mobbin.com/screens/d440b7cd-f79c-492d-9966-b52823033ae6)), Railway status/credit pill in the same spot ([14](https://mobbin.com/screens/0b7595bd-9179-40ef-8d3d-cae33382942a)), LC footer "Status" ([4f63d98f](https://mobbin.com/screens/4f63d98f-e49b-4d01-b220-8325bf7333b9)).
- **Doc:** `docs/design-system.md` §13.2 (team selector anatomy), `docs/08-dashboard-ux.md` §1 "Sidebar".

### NAV-9 Project tabs carry live state (S)
- **Problem:** Tabs are labels only (Previews has a count). A running deploy or a failure doesn't show until you open Deployments.
- **Change:** Deployments shows a `ProgressRing` while a deploy runs in this environment, or an error icon if the last one failed (icon + `aria-label`, no colour-only dot). Previews keeps its count. Logs/Analytics stay plain. Extend `PillTab` with `indicator?: ReactNode`, and the phone tab menu shows the same indicators.
- **Files:** `components/patterns/pill-tabs.tsx`, `features/projects/project-top-bar.tsx` (`tabs`), the environment live-updates channel (`features/projects/environment-live-updates.tsx`).
- **Evidence:** GitHub tab counts ([15](https://mobbin.com/screens/f24ad9b8-f897-43b6-8e63-e6429fae5c01)), Supabase tabs with error/warning counts ([16](https://mobbin.com/screens/e3ac7f2c-bdd0-42a4-ac13-76fb893fd06d)).
- **Doc:** `docs/08-dashboard-ux.md` §1 "Project level" (one sentence).

### NAV-10 Toasts share the floating bar's position (S, optional)
- **Problem:** Floating bars are bottom-centre and toasts are bottom-right, so feedback appears in two places. On the canvas the toolbar is bottom-centre and the minimap bottom-right, so toasts land on top of the minimap.
- **Change:** Toasts go `bottom-center`, stacked above the floating bar or canvas toolbar (offset tied to `FloatingBarOutlet` height). Keep inverse-free styling (popover surface) for parity with dark mode.
- **Files:** `components/ui/sonner.tsx`, `components/patterns/unsaved-bar.tsx`.
- **Evidence:** LC "Changes saved" in the unsaved bar's spot ([f2592e78](https://mobbin.com/screens/f2592e78-06f4-4054-825d-0502bc96ee36), [bce31f98](https://mobbin.com/screens/bce31f98-3283-4c47-971c-8a0dda21ca4b)), Linear Mobile toast above the bottom bar ([22](https://mobbin.com/screens/f8e3aa03-00f2-4985-a6ae-1055ae0f4144)).
- **Doc:** `docs/design-system.md` §6 "Toasts" and §13.4.

## Not recommended
- Dropping the sidebar for LC's tabs-only top bar. LC has two levels (org, app). We have team pages (Projects, Servers, Analytics, Security, Logs, Settings) plus project tabs, and the sidebar is what holds Security, Logs and the Get started checklist. Keep the sidebar and borrow LC's breadcrumb *state* (NAV-2) instead.
- Coloured environment squares. They conflict with "colour only with meaning". Use the Production tag plus status icons (NAV-2).
