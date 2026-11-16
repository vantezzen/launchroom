# OBSERVABILITY: UX refinement research (logs, metrics, analytics, activity, alerts, security)

Searches: 16 Mobbin calls (screens + flows), covering Laravel Cloud, Vercel, Railway, Sentry, Cloudflare, Supabase, Render, Stripe, Better Stack, GitLab, Vanta, Semrush, HubSpot, Toggl, PlanetScale, Seline, Amplitude, Lovable, Deel and others.
Our screenshots used: `log-explorer/logs-desktop-light` + `logs-mobile-light` + `panel-desktop-light` (Nov 16, freshest), `site-retake/raw/logs-light` + `metrics-light` (Oct 28), `ux-round/before/activity-light` + `alerts-light` (fresh), `analytics/overview-week-desktop-light` (team analytics, Oct 25), `security-v2/after-security-desktop-light` + `security/finding-desktop-light`. There were no fresh x-team-logs, x-analytics or x-security shots yet, so I used the older ones and checked the source.

---

## 0. What Laravel Cloud does in this area (primary reference)

Laravel Cloud's observability is small and calm, and that restraint is what makes it work:

- **Logs** ([d88f4fb9](https://mobbin.com/screens/d88f4fb9-f0e8-4af8-a587-53be2368153c?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp)): a centred column with one search field, a source dropdown ("Application logs"), a range dropdown ("Last 7 days") and a refresh button. There is no facet rail and no histogram. Each line is a quiet bordered row (`timestamp UTC  [INFO] message`) with a chevron. Clicking a row opens a right-hand **Log details** sheet ([8781d95a](https://mobbin.com/screens/8781d95a-9b23-44d3-aaf0-f4ad08a90442?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp)) showing the time, the request line and a "Context" JSON block, plus a **Copy ▾** menu with *Copy log message / Copy context / Copy all*.
- **Metrics** ([b1cd2fc4](https://mobbin.com/screens/b1cd2fc4-7079-4628-a8fd-8afe228908ce?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp), [ada25c28](https://mobbin.com/screens/ada25c28-2f70-489f-a49d-80f9c280c1b2?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp)): one column of chart cards with a 6h / 24h / 7d / 30d segmented range. Each card has a title, a legend at the top right and a **headline number with a qualifier** ("<0.01% avg.", "0 MiB avg."). "Edge network responses" is **stacked by status class (1/2/3XX, 4XX, 5XX) with a count per class in the legend**.
- **Resource metrics** ([1d16073c](https://mobbin.com/screens/1d16073c-0bf2-42d8-9152-0577d2ae7bb0?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp), flow [View metrics](https://mobbin.com/flows/2dbd2a60-e676-42ab-a2a8-8eabba8ad43a?tab=screens&utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp)): each chart has **a one-line plain description under its title** ("Current CPU usage of the database cluster as a percentage.") and a headline qualified as "avg." or "current". From the database page, "Metrics" and "Backups" are **navigation rows** ("View usage metrics for this database cluster. ›"), so settings and sub-pages are rows.
- I found no Laravel Cloud screens for activity, alerts or security on Mobbin ("Laravel Cloud notification settings", "… logs" and "… viewing logs" flows returned other apps). For those areas the evidence below comes from Vercel, Railway, Sentry and others.

What to take from it: the detail sheet's **copy menu**, the **headline-with-qualifier** and **one-line description** on every chart, and **status-class stacking** for request charts. We should also be more willing to keep the simple path simple. A phone user, or someone who just opened Logs, needs search, range and lines. Facets are a second step.

---

## 1. Current state

- **Logs explorer: strong core, weak edges** (`log-explorer/logs-desktop-light`, `panel-desktop-light`). The query bar with parsed filters, range, Live, histogram, facet counts and the detail panel with prev/next/copy are all at the level of Vercel and Railway. Three gaps:
  1. The detail panel has **no "show lines around this one"** and no "filter by this value" or "exclude", even though direction §5.5 promises "similar lines, show context" (`log-detail.tsx` has only prev/next/copy/close).
  2. On **phones the filter rail is simply hidden** (`log-explorer.tsx:760` `max-md:hidden`) and nothing replaces it, so you can only filter by typing `level:error` (`logs-mobile-light`).
  3. Error rows are loud: a full red row tint, red text *and* a red badge, while the Level column takes about 90 px that a phone can't spare (messages truncate after about 15 characters on mobile).
- **Older logs shot** (`site-retake/raw/logs-light`): deploy-marker labels overlapped into an unreadable blue smear. The Nov 16 shot no longer shows labels (`marker-groups.test.ts` exists), so that looks fixed. Re-check with many deploys in a 30-day range.
- **Metrics** (`metrics-light`): a good "picture → number → words" page, with clickable KPI cards driving the big chart, per-service sparklines and a service list. Charts have no one-line description, and the headline numbers mix "avg" and totals without saying which is which (Requests "1.1M" vs CPU "824m cores, avg"). The request chart is a single blue area, so 4xx/5xx are invisible until you open Analytics. Service rows use the Docker whale as the service icon, which shows Docker branding in primary UI.
- **Team analytics** (`analytics/overview-week-desktop-light`): the KPI tiles are static (they don't switch the chart, unlike the project Metrics page). The stacked bar chart is dominated by one project, so the others read as zero. "Top paths" is a numeric table while "Servers" next to it uses inline bars, so the two halves of the page disagree.
- **Activity** (`ux-round/before/activity-light`): the day grouping, type filter, project filter and "Audit log verified" are good. Bursts repeat themselves: two identical "Mara Lindqvist redeployed unplannable-site in production ✕ Failed" rows sit back to back, and project creation, service creation and deploy appear as three separate rows.
- **Alerts / notifications** (`ux-round/before/alerts-light`): channels with "Send test" and delivery state are excellent ("The address answered 404: the webhook was probably deleted"). The page opens with **10 open problems** filling the first screen of a *settings* page, each with a weak "Look" link. Rules show thresholds but never **when they last fired**.
- **Security** (`security-v2/after-security-desktop-light`, `security/finding-desktop-light`): findings are already grouped by advisory with per-service rows (good, and better than the Oct 25 shot). Dismissed and fixed findings have no visible home on the overview, so once you dismiss something you can't find it again. The finding detail page puts its actions in a "…" menu and mid-page ("Investigate again"). The header carries no action and no status line, and the breadcrumb says only "Security".

---

## 2. Patterns observed

**P1. The log detail offers "context" and pivot actions.**
Railway's detail has **View in Context** next to the line ([e83790c3](https://mobbin.com/screens/e83790c3-368e-4bf2-a244-5994bc2c135a?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp)). Sentry's expanded row ends with **+ Add to filter / − Exclude from filter / Copy as JSON** (flow [Logs](https://mobbin.com/flows/25fa3105-035b-4d8e-bd32-255b9d2b19f4?tab=screens&utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp), screen 2). Laravel Cloud has the copy menu ([8781d95a](https://mobbin.com/screens/8781d95a-9b23-44d3-aaf0-f4ad08a90442?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp)). Why it works: you find an error by searching, but you understand it by reading the 20 lines around it. Pivoting from a value ("only this replica", "not health checks") is faster than typing syntax.

**P2. Level is shown as a thin left rule, not a column.**
Railway ([7b2a7c2d](https://mobbin.com/screens/7b2a7c2d-d2af-4473-a8fd-7a4436fd316a?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp)) and Cloudflare ([ad394819](https://mobbin.com/screens/ad394819-f3c4-4409-96cc-540329602310?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp)) put a 2–3 px coloured bar on each row's left edge. Error rows get only a faint tint. Laravel Cloud writes `[INFO]` inline in the message column ([d88f4fb9](https://mobbin.com/screens/d88f4fb9-f0e8-4af8-a587-53be2368153c?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp)). Why it works: it frees width for the message, scans well in long lists and works at phone width.

**P3. Facets are collapsible, searchable and resettable.**
Vercel's filter sidebar collapses each facet, opens only Timeline and Level by default, offers a search inside a facet ("Search status…"), puts a **Reset** at the top and mirrors selections as chips in the query bar ([c1757ea2](https://mobbin.com/screens/c1757ea2-d024-4dd4-9ef2-13b6a2575d54?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp), [c6b747bc](https://mobbin.com/screens/c6b747bc-29d1-4b25-806d-2bd2ac71633f?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp)). Why it works: the rail stays short, and our Deployment facet (with "Show 1 more" and a long `dpl_01M59…` value) wouldn't push Server off-screen.

**P4. Log export and copy.**
Vercel's export dialog offers "Current screen (20)" with a pointer to log drains for continuous export ([c27fd44e](https://mobbin.com/screens/c27fd44e-809d-4918-99f1-99e9e10dcee6?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp)). Railway puts a download icon next to the range ([7b2a7c2d](https://mobbin.com/screens/7b2a7c2d-d2af-4473-a8fd-7a4436fd316a?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp)). Sentry has Export above the chart ([b058eb95](https://mobbin.com/screens/b058eb95-555c-49ac-986d-430776a4e53c?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp)).

**P5. Each chart has a headline number with a qualifier, a one-line description and status-class stacking.**
Laravel Cloud ([b1cd2fc4](https://mobbin.com/screens/b1cd2fc4-7079-4628-a8fd-8afe228908ce?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp), [1d16073c](https://mobbin.com/screens/1d16073c-0bf2-42d8-9152-0577d2ae7bb0?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp)) and Railway ([ac8201ab](https://mobbin.com/screens/ac8201ab-3829-4905-911b-7ad049c61b1d?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp): Requests stacked 2xx/3xx/4xx/5xx with a per-class tooltip and dashed deploy markers). Why it works: "avg" vs "current" vs "total" removes the most common misreading, and status stacking shows errors without a separate chart.

**P6. KPI tiles double as chart tabs, and top lists use inline bars.**
Vercel Analytics (Visitors / Page Views / Bounce Rate tiles switch the chart; Pages and Referrers lists have bar backgrounds: [f58e584d](https://mobbin.com/screens/f58e584d-9481-4125-961f-b08bde53529b?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp)), Amplitude ([7704491b](https://mobbin.com/screens/7704491b-05e1-4fd9-b2bd-ff5b0643053a?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp)), Seline ([87d36cf2](https://mobbin.com/screens/87d36cf2-d7fa-4335-8284-3c9d2fa2fd03?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp)), Lovable ([cd428f5f](https://mobbin.com/screens/cd428f5f-6760-4e0d-803a-bc4fd150ffc2?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp)). Why it works: one chart and many lenses. Inline bars show the share at a glance (picture → number).

**P7. Activity collapses bursts and shows what changed.**
Vercel groups consecutive events by the same actor in the same project under one header ("You in odyssey" followed by indented rows: [a111dca7](https://mobbin.com/screens/a111dca7-48a2-4456-bba7-acec68ff70f0?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp)). Toggl shows **From → To** chips for setting changes ([2deef352](https://mobbin.com/screens/2deef352-1f6b-4757-a4c9-dcce106b0e6a?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp)). PlanetScale puts the actor first with a compact event key ([350460dd](https://mobbin.com/screens/350460dd-4eba-4197-9f8e-f18d0e96cfef?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp)).

**P8. Alert rules are separate from alert history, and each rule says when it last fired.**
Sentry splits Alerts into **Alert Rules | History**, and each rule row reads "Triggered 3 minutes ago" ([333d32c4](https://mobbin.com/screens/333d32c4-59a9-4306-84d0-3eaba51b96de?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp)). Vercel's Alert Rules page holds only rules and their destinations ([a1852dc4](https://mobbin.com/screens/a1852dc4-aa3e-4cc1-83ff-4d18d3cfd687?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp)). Render's Notifications settings is just delivery plus defaults ([f3e5ef09](https://mobbin.com/flows/b87c6772-51cf-457d-8848-84c7e9fafaab?tab=screens&utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp)). Why it works: a settings page answers "what will I be told, and where?" Open problems belong somewhere you act on them.

**P9. The issue detail puts its actions and lifecycle in the header.**
Sentry puts **Resolve / Archive** directly under the title, with a status line (Unhandled, Ongoing), first/last seen and an Activity timeline at the side ([fcc6e08a](https://mobbin.com/screens/fcc6e08a-e647-449c-9a11-6ceef8332a89?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp), [f68fb4ad](https://mobbin.com/screens/f68fb4ad-cebc-495f-b911-e7189a2a9ca2?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp)).

**P10. Resolved and dismissed issues stay findable, and the trend is visible.**
HubSpot lists "Resolved issues" below open ones ([e9b536b6](https://mobbin.com/screens/e9b536b6-aed8-4e62-b615-bde7c0428fe0?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp)). Semrush marks "2 new issues" per row ([f4c9575a](https://mobbin.com/screens/f4c9575a-50af-462d-b267-bbe758587c33?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp)). GitLab shows vulnerabilities over time per severity with sparklines ([83a4d831](https://mobbin.com/screens/83a4d831-a8b2-4240-b322-02aec38ec666?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp)). Vanta has "Deactivated" and "History" tabs ([631da5b7](https://mobbin.com/screens/631da5b7-7729-472a-8169-5a80dce56bd1?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp)).

---

## 3. Recommendations (ranked by impact ÷ effort)

### OBS-1: "Show surrounding lines" and filter pivots in the log detail (S–M)
- **Problem:** after a search you see one matching line, but the lines just before it explain the error. The detail panel can't show them or pivot on a value. Direction §5.5 already promises "similar lines, show context".
- **Change:** in `LogDetail`, add a header action **"Show surrounding lines"**. It clears the text and level filters, keeps service and deployment, centres the range on the line's timestamp (±2 min) and scrolls to and highlights that line (the same left rule as OBS-2, in signal blue). A "Back to results" chip restores the previous query. Next to each fact value (service, deployment, server, any structured key), add a hover pair: **Only this** and **Hide this**, which add `key:value` or `-key:value` to the query. Replace the single copy icon with a **Copy ▾** menu: *Message / Line as JSON / Link to this line*.
- **Files:** `dashboard/resources/js/features/log-explorer/log-detail.tsx`, `log-explorer.tsx` (scope push/pop), `query.ts` (negation, if not supported), maybe `app/Http/Controllers/…Logs…` for an `around=<ts>` cursor.
- **Evidence:** [Railway "View in Context"](https://mobbin.com/screens/e83790c3-368e-4bf2-a244-5994bc2c135a?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp), [Sentry Add to filter / Exclude](https://mobbin.com/flows/25fa3105-035b-4d8e-bd32-255b9d2b19f4?tab=screens&utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp), [Laravel Cloud Copy menu](https://mobbin.com/screens/8781d95a-9b23-44d3-aaf0-f4ad08a90442?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp).
- **Doc:** none needed (direction §5.5 already describes it). Note the copy menu in §5.5.

### OBS-2: Phone filters and a two-line mobile log row (S)
- **Problem:** at phone width the filter rail is `max-md:hidden` with no replacement. The table keeps a Level column, so messages truncate after about 15 characters (`logs-mobile-light`).
- **Change:** below `md`, show a **Filters (n)** button next to Live that opens the existing `FilterRail` in a bottom `Sheet`. Render rows in two lines: time, service and the level word (only for warning and error) on line 1, and the wrapped message (up to 3 lines) on line 2.
- **Files:** `features/log-explorer/log-explorer.tsx`, `log-table.tsx`, `filter-rail.tsx`.
- **Evidence:** [Vercel filters](https://mobbin.com/screens/c1757ea2-d024-4dd4-9ef2-13b6a2575d54?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp), [Laravel Cloud inline level](https://mobbin.com/screens/d88f4fb9-f0e8-4af8-a587-53be2368153c?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp).
- **Doc:** direction §9.8: add "phones: filters in a sheet, two-line rows".

### OBS-3: Level as a left rule, quieter error rows (S)
- **Problem:** error rows stack three red signals (tint, red text, badge), and the Level column costs width on every row.
- **Change:** drop the Level column on desktop too. Give each row a 2 px left rule (error red, warning amber, info and debug none) and keep the **word** "Error" or "Warning" as a small muted-red/amber label before the message: the colour gets a non-colour cue, and info/debug stay silent. Keep the faint row tint for errors but render message text in the normal foreground. The `level:` facet stays in the rail.
- **Files:** `features/log-explorer/log-table.tsx`, `level-badge.tsx`; also `features/logs/log-viewer.tsx` for parity with build logs.
- **Evidence:** [Railway](https://mobbin.com/screens/7b2a7c2d-d2af-4473-a8fd-7a4436fd316a?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp), [Cloudflare](https://mobbin.com/screens/ad394819-f3c4-4409-96cc-540329602310?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp), [Laravel Cloud](https://mobbin.com/screens/d88f4fb9-f0e8-4af8-a587-53be2368153c?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp).
- **Doc:** direction §5.5: change "level chip" to "level rule and word for warning/error". Check the design-system rule on colour plus a non-colour cue (the word covers it).

### OBS-4: Move open problems off the Notifications settings page and show "last fired" per rule (S–M)
- **Problem:** `settings/notifications` opens with 10 open problems (a full screen) before you reach "Where we tell you" and "What we tell you about". The "Look" links are vague, and rules don't say whether they ever fire.
- **Change:** (a) Replace the "Open now" list with one row: "**10 problems are open** · View in Activity ›", linking to `/activity?type=alerts&state=open`; show at most the 2 most severe inline. (b) Rename "Look" to **"Open"** wherever it remains. (c) Under each rule's description add a muted line: "Last sent 3 days ago (orders-db)" or "Hasn't fired yet". (d) Add a "Open only" toggle to the Alerts filter in the Activity feed if it's missing.
- **Files:** `dashboard/resources/js/pages/settings/notifications.tsx`, `features/alerts/rules-card.tsx`, `features/activity/activity-feed.tsx`; backend: add `lastFiredAt` and `lastFiredSubject` to the rules Data object.
- **Evidence:** [Sentry Rules | History, "Triggered 3 minutes ago"](https://mobbin.com/screens/333d32c4-59a9-4306-84d0-3eaba51b96de?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp), [Vercel Alert Rules](https://mobbin.com/screens/a1852dc4-aa3e-4cc1-83ff-4d18d3cfd687?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp), [Render Notifications](https://mobbin.com/flows/b87c6772-51cf-457d-8848-84c7e9fafaab?tab=screens&utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp).
- **Doc:** docs/08-dashboard-ux.md §12 (and the notifications section of 15-observability.md): say where open alerts live.

### OBS-5: Collapse activity bursts (S–M)
- **Problem:** identical consecutive events repeat (two "redeployed unplannable-site ✕ Failed" rows), and a project's first minutes produce 3–4 rows.
- **Change:** in `activity-feed.tsx`, merge consecutive events with the same actor, verb and target within 10 minutes into one row with a "×2" count and the latest outcome; clicking it expands the children. Group consecutive different events by the same actor in the same project under a single actor line with indented sub-rows (Vercel style). For setting changes, show **from → to** values when the event carries them.
- **Files:** `features/activity/activity-feed.tsx` (pure grouping, with a unit test like `shape.test.ts`), and possibly the Activity Data object for `before/after` on setting events.
- **Evidence:** [Vercel activity grouping](https://mobbin.com/screens/a111dca7-48a2-4456-bba7-acec68ff70f0?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp), [Toggl from → to](https://mobbin.com/screens/2deef352-1f6b-4757-a4c9-dcce106b0e6a?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp), [PlanetScale audit log](https://mobbin.com/screens/350460dd-4eba-4197-9f8e-f18d0e96cfef?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp).
- **Doc:** direction §5.4 `ActivityItem`: add "bursts collapse".

### OBS-6: Finding detail actions and lifecycle in the header (S)
- **Problem:** what you can do (Redeploy, Investigate again, Dismiss) is split between a "…" menu and the middle of the page. The header shows no state, and the breadcrumb is just "Security".
- **Change:** under the title, add a status line: `Open since 21 Oct · first seen 3 hours ago · last checked 3 hours ago` (no "·" filler: use spaced segments as our other meta lines do). Add an action row: primary **Redeploy** when a fixed image exists (otherwise **Copy fix command**), secondary **Investigate again**, then **Dismiss…**. Make the breadcrumb `Security / GHSA-f82v…`. Add prev/next arrows across the open findings list. At the bottom, add a short **History** list (found, investigated, dismissed, fixed) from existing audit events.
- **Files:** `features/security/finding-detail.tsx`, `pages/security/finding.tsx`.
- **Evidence:** [Sentry issue header](https://mobbin.com/screens/fcc6e08a-e647-449c-9a11-6ceef8332a89?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp), [Sentry activity rail](https://mobbin.com/screens/f68fb4ad-cebc-495f-b911-e7189a2a9ca2?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp).
- **Doc:** docs/22-security-center.md §9 UI (finding page layout).

### OBS-7: Dismissed and recently fixed findings stay findable (S)
- **Problem:** after "Dismiss" a finding disappears from the overview with no path back. There's also no sense of progress.
- **Change:** at the end of the Action needed section, add two muted rows in the Worth-knowing style: "**3** dismissed ›" and "**5** fixed in the last 30 days ›", each opening a sheet list. In the hero, add a small 30-day sparkline of open "Action needed" findings next to "4 vulnerabilities need you".
- **Files:** `features/security/security-overview.tsx`, `worth-knowing.tsx`, `shape.ts`; backend counts in the security overview Data object.
- **Evidence:** [HubSpot resolved issues](https://mobbin.com/screens/e9b536b6-aed8-4e62-b615-bde7c0428fe0?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp), [GitLab vulnerabilities over time](https://mobbin.com/screens/83a4d831-a8b2-4240-b322-02aec38ec666?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp), [Vanta Deactivated / History](https://mobbin.com/screens/631da5b7-7729-472a-8169-5a80dce56bd1?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp).
- **Doc:** docs/22-security-center.md §9.

### OBS-8: Metrics charts get a qualifier, a description and status stacking (S–M)
- **Problem:** headline numbers mix totals and averages without saying so. The big Requests chart is one area, so 4xx/5xx only show up on Analytics.
- **Change:** every StatTile and chart headline gets a qualifier word (`total`, `avg`, `peak`, `now`). Each chart under "Requests" / "CPU" / "Memory" gets one plain description line ("Requests your services answered, by response type."). When Requests is selected, stack the main chart by **2xx-3xx / 4xx / 5xx** (neutral grey / amber / red, with counts in the legend so the colour has a number beside it). Swap the Docker whale on service rows for our generic service glyph (or the runtime logo when known).
- **Files:** `features/analytics/project-resources.tsx`, `features/metrics/metric-card.tsx`, `components/charts/time-series-chart.tsx`; the 4xx/5xx series already exist in `project-analytics.tsx`.
- **Evidence:** [Laravel Cloud edge responses](https://mobbin.com/screens/b1cd2fc4-7079-4628-a8fd-8afe228908ce?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp), [Laravel Cloud resource metrics](https://mobbin.com/screens/1d16073c-0bf2-42d8-9152-0577d2ae7bb0?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp), [Railway stacked requests](https://mobbin.com/screens/ac8201ab-3829-4905-911b-7ad049c61b1d?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp).
- **Doc:** direction §5.3 StatTile ("value with qualifier").

### OBS-9: Team analytics KPI tiles switch the chart, and top paths get inline bars (S)
- **Problem:** the team page tiles are static (unlike project Metrics), and one big project flattens everyone else in the stacked chart. "Top paths" is a bare table while "Servers" uses bars.
- **Change:** make the Requests / Server errors / Client errors tiles selectable, using the same `MetricCard` selected state as the project page, and chart the selected measure by project. Add a "Share / Absolute" toggle on the chart so small projects become visible. Render Top paths rows with a proportional background bar behind the path and the numbers right-aligned; keep the 5xx column.
- **Files:** `features/analytics/team-analytics.tsx`, `analytics-header.tsx`, `features/metrics/metric-card.tsx`.
- **Evidence:** [Vercel Analytics](https://mobbin.com/screens/f58e584d-9481-4125-961f-b08bde53529b?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp), [Seline](https://mobbin.com/screens/87d36cf2-d7fa-4335-8284-3c9d2fa2fd03?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp), [Amplitude](https://mobbin.com/screens/7704491b-05e1-4fd9-b2bd-ff5b0643053a?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp), [Lovable](https://mobbin.com/screens/cd428f5f-6760-4e0d-803a-bc4fd150ffc2?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp).
- **Doc:** none.

### OBS-10: Collapsible, searchable facets with Reset (S)
- **Problem:** every facet group is always open, so Deployment and Server get pushed below the fold (`site-retake/raw/logs-light`: Server is cut off). Long deployment IDs wrap.
- **Change:** Level and Service open by default, the others collapsed with a "(1)" selected count in the header. Show a search field inside a group once it has more than 8 values. Add a **Reset** link at the rail top when any facet is set. Truncate deployment values to 7 characters with a tooltip.
- **Files:** `features/log-explorer/filter-rail.tsx`, `facets.ts`.
- **Evidence:** [Vercel facets](https://mobbin.com/screens/c6b747bc-29d1-4b25-806d-2bd2ac71633f?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp), [Vercel status facet](https://mobbin.com/screens/c1757ea2-d024-4dd4-9ef2-13b6a2575d54?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp).
- **Doc:** none.

### OBS-11: Download the current log query (M)
- **Problem:** there is no way to hand someone the lines you found except copying one at a time.
- **Change:** add a download icon button after Live with a small menu: *Lines on screen* / *All matching (up to 10,000)*, as NDJSON or plain text. It is a server-side streamed response, authorised like the logs index and limited to the same scope.
- **Files:** `features/log-explorer/log-explorer.tsx`; new controller action plus route under the project logs controller; a Pest test for scoping.
- **Evidence:** [Vercel Export Logs](https://mobbin.com/screens/c27fd44e-809d-4918-99f1-99e9e10dcee6?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp), [Railway download](https://mobbin.com/screens/7b2a7c2d-d2af-4473-a8fd-7a4436fd316a?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp), [Sentry Export](https://mobbin.com/screens/b058eb95-555c-49ac-986d-430776a4e53c?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp).
- **Doc:** docs/15-observability.md (log export) and docs/16-api-and-cli.md if the CLI should match.

### OBS-12: Drag on the histogram to zoom the range (M, nice to have)
- **Change:** a pointer drag across `components/charts/histogram.tsx` sets a custom range, shown as a "16:10–16:40 ×" chip that replaces the segmented range until cleared. The hover tooltip already exists.
- **Evidence:** [Railway histogram tooltip and range](https://mobbin.com/screens/e4b50f41-977b-4733-881f-eb5e95612ef3?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp), [Braintrust range brush](https://mobbin.com/screens/3f6f5937-8ae6-4ab1-b6de-c18034e68a20?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp), [Cloudflare custom range chip](https://mobbin.com/screens/ad394819-f3c4-4409-96cc-540329602310?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp).
- **Doc:** direction §5.5 (histogram).
