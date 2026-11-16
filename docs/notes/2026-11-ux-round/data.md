# UX round: Databases, backups and remote access (DAT-)

Scope: the Data tab (browse + query), Console tab, service Backups page (snapshots, PITR, restore dialog, Move data),
team Settings → Backups (destinations, what's protected, recovery kit), temporary SSH/SFTP/database access, Volumes.

Screens looked at: `site-retake/raw/{database-data,database-browse,database-backups,pitr,restore-dialog,console}-light.png`,
`ux-round/before/backup-settings-light.png`, `access-v2/after-{ssh-open,volumes}-desktop-light.png`.
Code read: `features/sessions/{data-tab,result-grid,console-tab}.tsx`, `features/backups/{backups-tab,point-in-time}.tsx`,
`features/remote-access/{remote-access.tsx,connection.ts}`, `features/service-panel/service-panel.tsx`, docs/08 §15–16.

---

## 1. Current state

- **The Data tab works, but it's cramped in the narrow panel** (`database-data`, `database-browse`). The table list sits *above* the grid
  instead of beside it (docs/08 §15 promises a 220 px sidebar), so the grid starts 700 px down and the `ts` column eats half the width.
  Wide tables get cut off with no way to see a whole row. The grid has no column types, no sort and no copy/export. The pager
  ("Rows 1–50", Previous/Next) sits under 50 rows, so you have to scroll to page, and it drops the "of ~5.1K" estimate the sidebar already knows.
- **The query console is solid.** Read-only chip, Allow writes switch behind a confirm, History popover, ⌘↵, "7 rows | 24 ms". It's close
  to Supabase's SQL editor. It has no results toolbar (copy / download CSV).
- **Restoring takes too many steps and confirms twice** (`pitr`, `restore-dialog`). Today it goes: pick a row in Snapshots (point in time is a
  pseudo-row with a bare native `datetime-local` and no time zone) → scroll to a separate **Restore** section → pick an option card →
  press "Restore as a new service…" → a dialog that repeats the same choice as a paragraph. The dialog's time ("10/19/2026, 3:00:35 PM")
  has no zone. After the restore there's no "now what" (open the copy, swap it in).
- **The PITR card shows config and status well** (Status / Restorable since / Reaches back), but the *action* (restore to a time) lives
  far below it in the snapshot list.
- **Team Settings → Backups** (`backup-settings`): the destination cards' blue full rings ("47 MB latest copy") are always 100 %, so they're
  decoration, which design-system.md rules out. "What's protected" is 19 equal rows, and 16 of them say "No backup yet" in neutral grey with nothing on
  top to sum it up. The recovery kit block is good.
- **Temporary access is a strong screen** (`after-ssh-open`): live countdowns, copyable credential rows, client tabs (ssh/sftp/scp/config),
  host key check, "Open to" chips. The one gap: for databases it gives a URL and `psql`/`redis-cli`, but not the `.env` block a
  Laravel/Node app pastes in. **Volumes** (`after-volumes`) reads well: device picture, mount path, size, backup state, SFTP. **Console**
  (`console`) is clean: status line, End session, Recent sessions.

---

## 2. Patterns observed

### Laravel Cloud (primary reference)
Indexed flows that touch this area: *Creating a database*, *Database detail*, *View metrics*, *Archiving a database*, *Running a command*.
Mobbin has no Laravel Cloud backup-list or restore screens (searched flows pages 1–2 and deep screen search: nothing), so that part
is corroborated from Neon/PlanetScale below.

- **Database detail = one object card, then drill-in rows.** The header line is name + engine + a facts line with small icons (region,
  1 vCPU · 512 MiB RAM, 5 GB storage). Then **Credentials as a ready-to-paste `.env` block** (`DB_CONNECTION`, `DB_HOST`, `DB_PORT`,
  `DB_USERNAME`, `DB_PASSWORD`), masked, with one reveal and one copy for the whole block. Under it an amber callout says why the
  credentials might not work and links the fix ("Public endpoint is disabled… **Enable public endpoint**"). After that, **Metrics** and **Backups**
  as plain chevron rows, and the databases inside with "Used in ASMobbin · staging".
  [Database detail](https://mobbin.com/flows/472b9dae-c619-47b5-b8cd-04be2e37ba1a?tab=screens&utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp),
  [View metrics](https://mobbin.com/flows/2dbd2a60-e676-42ab-a2a8-8eabba8ad43a?tab=screens&utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp)
- **Backups are part of the size choice.** Each preset in "New database cluster" lists its retention in the same facts line
  ("Flex (1 vCPU · 2 GiB RAM) · 20 GB storage · 7 days backups"), and the canvas resource card shows Type / Name / Size / Storage rows.
  [Creating a database](https://mobbin.com/flows/5b3d9862-e6ec-4817-aff8-c9f93a4b5916?tab=screens&utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp)
- **Destructive data actions state the backup consequence in bold, list what's affected, and ask you to type the name.** "This will take a
  **final backup**, delete scheduled backups, and retain manual ones. You can restore this database cluster from one of these backups at
  any time." Under that, a box with the cluster and its environments, then "To confirm, type laravel below".
  [Archiving a database](https://mobbin.com/flows/690be9ec-aa46-4d38-8566-ee813ffcd176?tab=screens&utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp)
- **Commands: a one-line runner with history and a details page.** Input prefilled with `php artisan`, "Run command", "Latest commands"
  list. The details page shows the command, status ("Finished · Just now · Alex Smith"), **Run again**, and the output in a recessed block.
  When it can't run, the reason and fix sit inline under the field ("You cannot run commands while the environment is hibernating.
  **Wake up environment**").
  [Running a command](https://mobbin.com/flows/0a8cfbfc-0a27-432e-a01f-b633487729cc?tab=screens&utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp)

### Restore points are actions on the list, and the dialog shows facts and consequences (Neon, PlanetScale, Confluence)
- **Neon Backup & Restore**: the top card *is* the point-in-time action: Source branch + Point in time picker with the **time zone written
  under it** ("Asia/Jakarta, GMT+07:00"), **Preview data**, **Restore to point in time**. A divider "Or restore from a snapshot", then
  snapshots on a dated timeline, each with its own **Restore ▾** split button and a ⋯ menu. Out-of-window times get an inline error
  ("Date is beyond the history retention").
  [page](https://mobbin.com/screens/463bbee6-ef19-48ee-ad92-7819bb1181fd?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp),
  [snapshot row](https://mobbin.com/screens/99efb176-4a62-4499-8a59-8a5e16d452fa?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp),
  [inline validation](https://mobbin.com/screens/0db73c4d-7092-4c22-910f-2d7efe2aa0a5?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp)
- **Neon restore dialog**: "here's what will happen" as a short ✓ checklist ("production will be restored to Feb 26, 2026 5:20 am (GMT+7)",
  "Your current branch will be kept as a backup and renamed to production_old_…", "You can undo this restore as long as you keep …").
  [PITR restore](https://mobbin.com/screens/89e2d05f-4596-4495-8d3d-a039a87868e2?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp),
  [snapshot restore](https://mobbin.com/screens/3ef2b98f-8c30-409a-a90f-b1c3b94d2d50?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp)
- **PlanetScale restore dialog**: fact rows (Branch, Backed up, Expires, **Size**), then an editable new name prefilled
  (`main-main-backup-2025-8`). The backup list shows Size and "Backed up 8 hours ago", next to a schedule card ("Run Every 12 hours / Keep 2 days /
  Last run 8 hours ago · Next run in 4 hours").
  [dialog](https://mobbin.com/screens/8eeaadaa-69fb-4708-b091-c659d6fb6c0f?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp),
  [Backups flow](https://mobbin.com/flows/2ad8031b-3b48-41d9-be7d-b0b0688c4156?tab=screens&utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp)
- **Confluence** restores a database "as new" with a prefilled editable name "[Restored] Untitled database Jun 3, 2025 1:24 p.m."
  [screen](https://mobbin.com/screens/b4f87b7e-0533-402f-a87c-8baf0499a876?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp)
- Why it works: the thing you restore *from* carries its own action, and the dialog answers the three questions people actually have
  (what point, what happens to my current data, how big/how long), so the dialog never just restates the button.

### After a restore, show the next step (Neon)
- Neon lands on the new branch with "Success!" and two next-step rows: "Connect to the new branch to preview restored data" (Get connection
  details) and "Migrate connections and settings to the new branch". Its "Preview historic data" dialog lets you browse and query at the
  timestamp, then **Proceed to restore**.
  [success](https://mobbin.com/flows/5e8b3a4a-5792-41cd-ae25-a5a5e0b00f17?tab=screens&utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp),
  [Restoring a branch flow](https://mobbin.com/flows/ebe13d1c-83ce-45c7-bf22-33a78c2e2d60?tab=screens&utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp)

### Data grids: types in headers, totals in the pager, a row inspector (Supabase, Replit, v0, Retool, Obvious)
- **Column type next to the name** in the header: Supabase `id int8`, `created_at timestamptz`; Replit `id serial`, `name text`; Retool
  `PRIMARY KEY` tag; v0 "public.categories, 4 columns · PK: id" with a "Column types" disclosure.
  [Supabase](https://mobbin.com/screens/c7224401-b4bf-4d45-88b9-bf7ef0df5f2a?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp),
  [v0](https://mobbin.com/screens/66131399-da95-4e2e-a5ef-683413f3b2bb?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp),
  [Replit flow](https://mobbin.com/flows/7fae3e4b-fd1a-45ce-a58b-98734467bbf7?tab=screens&utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp)
- **The pager is a pinned footer with totals**: Supabase "Page 1 of 1 · 100 rows · 20 records", v0 "Showing 1 – 10 · Previous / Next",
  Cloudflare "Showing 1-1 of 1".
  [Cloudflare D1](https://mobbin.com/screens/a71613ed-609b-4489-b2a2-0cae68fe4099?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp)
- **Row inspector side sheet**: Supabase opens a row as a vertical form (field name + type on the left, value on the right); Obvious and
  Braintrust show the selected row as label/value pairs with a "1 / 100" stepper. This is how wide rows stay readable in narrow space.
  [Supabase row](https://mobbin.com/screens/9d24497c-18be-490d-aace-3226d0214d1a?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp),
  [Obvious](https://mobbin.com/screens/3f3ebce5-2600-4a86-8d09-941892aac18a?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp),
  [Braintrust](https://mobbin.com/screens/80308e7f-102b-45b2-862a-81187d020464?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp)
- **Sort** as a header affordance or a "Sort" popover listing columns with types (Supabase).
  [Supabase sort](https://mobbin.com/screens/d3b6831e-f216-43b7-a103-09af362e2bfd?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp)
- **SQL results toolbar**: Supabase has Results / Explain / Chart + **Export**, and its role and source pickers sit next to Run. It also warns *before*
  running a risky statement ("Potential issue detected" with Run anyway / Run safely).
  [SQL editor](https://mobbin.com/screens/fe760737-5cf1-482a-ad17-b44e3e3cf39f?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp),
  [pre-run warning](https://mobbin.com/screens/ddc7775d-ddd7-4f5a-90cd-c49e66325dbb?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp)

### Database tooling gets the whole width (Vercel Storage, Supabase, PlanetScale)
- Vercel's database detail has a "Browser" group (Query, Data Editor, Schema) as full pages; Supabase's table editor and PlanetScale's
  Console take the whole content area. None of them squeeze a grid into a side panel.
  [Vercel database detail](https://mobbin.com/flows/98057e90-2702-42a4-b996-c601484d93ca?tab=screens&utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp),
  [PlanetScale console](https://mobbin.com/screens/d48698dd-a218-4cfe-b594-fa049b2464b3?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp)

### Credentials as copy-ready blocks (Laravel Cloud, Replit, Retool)
- Laravel Cloud gives a `.env` block. Replit gives `DATABASE_URL` masked with copy + reveal and "Copy it into any tool that needs to connect".
  Retool gives a "PSQL command" and a "Postgres Connection URL", both masked.
  [Replit](https://mobbin.com/screens/36ed8105-df08-4a6c-ad01-25d0a484e853?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp),
  [Retool flow](https://mobbin.com/flows/e2c25af0-6992-4cba-9e21-506af66e8c8d?tab=screens&utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp)

### Typed confirmation with the consequences listed (Laravel Cloud, Railway, Resend, Clerk, Cloudflare)
- They all converge on this: one sentence on what's lost, a count list of what's affected (Resend: "0 Members · 1 Webhooks · 1 Domains · 2 API Keys"),
  and "Type X to confirm". We already require typing for an in-place restore. What's missing is the affected list.
  [Railway](https://mobbin.com/screens/767c7c44-2612-4ba6-a112-2e7a5d6a2bf7?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp),
  [Resend](https://mobbin.com/screens/52df18d7-8f15-470f-a302-5c407d781091?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp)

### Web terminals
- AWS CloudShell, PlanetScale Console, Replit Shell, v0 Terminal: the terminal fills its pane, and its only chrome is a target picker (PlanetScale "Branch ▾")
  and a close button. Stripe Shell puts a "Read only" chip in the status bar. Ours already matches this. The thing to borrow from Laravel Cloud is
  **one-shot commands with Run again** (see DAT-8).
  [Stripe Shell](https://mobbin.com/screens/613771bc-ae7b-4c50-b41b-47e9bd9da29a?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp),
  [Replit shell flow](https://mobbin.com/flows/dde3e6d9-a667-4a6c-a83a-361b9b657f98?tab=screens&utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp)

---

## 3. Recommendations (ranked by impact ÷ effort)

### DAT-1 Restore from the row, not from a separate section — S/M
- **Problem**: restoring is select row → scroll → option cards → button → dialog that repeats the choice. Point in time is a pseudo-row.
- **Change** (Neon + PlanetScale pattern):
  1. At the top of the Backups page put a **"Restore to a point in time"** card (PITR only, shown when restorable). It holds the picker
     (see DAT-3), a **Newest** shortcut and one primary **Restore…** button. The PITR config facts (Status / Restorable since / Reaches back)
     stay in that card's footer. The on/off switch moves to the card header, as it is today.
  2. Each snapshot row gets its own trailing **Restore…** button (or a split `Restore ▾` with *As a new service*, *Into orders-db*,
     *Download this backup*). Drop the "Any point in time" pseudo-row and the row-selection state.
  3. Remove the standalone **Restore** section. The **dialog** now holds the choice: the two existing `OptionCards` (As a new service /
     Into orders-db) inside the dialog, default *As a new service*. The typed confirmation appears only when *Into* is chosen.
- **Files**: `dashboard/resources/js/features/backups/backups-tab.tsx` (`ServiceBackups` Snapshots/Restore sections, `RestoreDialog`,
  `SnapshotTimeline`), `features/backups/point-in-time.tsx`.
- **Evidence**: [Neon page](https://mobbin.com/screens/463bbee6-ef19-48ee-ad92-7819bb1181fd?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp),
  [Neon snapshot row](https://mobbin.com/screens/99efb176-4a62-4499-8a59-8a5e16d452fa?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp),
  [PlanetScale dialog](https://mobbin.com/screens/8eeaadaa-69fb-4708-b091-c659d6fb6c0f?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp)
- **Doc update**: docs/14-backup-and-restore.md (restore UX section) and design-system.md if it describes the Backups page layout.

### DAT-2 Restore dialog: fact rows + "what will happen" checklist instead of a paragraph — S
- **Problem**: the dialog restates the button in one long sentence; size, time zone and the name of the new service aren't visible.
- **Change**: in `RestoreDialog` replace the description paragraph with
  - **Fact rows** (`dl`, label/value, mono for values): *From* (snapshot time with zone, or "Point in time · 26 Oct 2026, 08:07:15 CET"),
    *Size* (snapshot size), *Server* (where it lands), *New service* (prefilled editable name `orders-db-restored-2026-10-19`, as new only,
    if the action can accept a name; otherwise show the generated name read-only).
  - A 2–3 line **✓ list** of consequences in plain words. As a new service: "orders-db keeps running unchanged", "A new database
    orders-db-restored-… is added in production", "You can swap it in later". Into: "A safety snapshot of orders-db is taken first",
    "orders-db is stopped while its data is replaced (about N min for 2.7 KB)", "Services using it reconnect when it's back". Use the
    `lucide` Check icon in muted foreground (it's information, not success), and use amber + the `TriangleAlert` icon only for the in-place
    "replaces its data" line.
- **Files**: `features/backups/backups-tab.tsx` (`RestoreDialog`, `SwapDialog` gets the same treatment), backend name field only if
  the Action already supports it (`app/Actions/Backups/*Restore*`; otherwise display-only).
- **Evidence**: [Neon checklist](https://mobbin.com/screens/89e2d05f-4596-4495-8d3d-a039a87868e2?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp),
  [PlanetScale facts](https://mobbin.com/screens/8eeaadaa-69fb-4708-b091-c659d6fb6c0f?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp),
  [Laravel Cloud archive](https://mobbin.com/flows/690be9ec-aa46-4d38-8566-ee813ffcd176?tab=screens&utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp)
- **Doc update**: docs/14 restore section (dialog contents).

### DAT-3 Point-in-time picker: show the zone and the window, validate inline — S
- **Problem**: bare `datetime-local` with no zone. The dialog shows a locale string with no zone. Out-of-window input isn't explained.
- **Change**: keep the native input (it's accessible) but add one muted line under it: "Europe/Berlin (UTC+01:00) · between 22 Oct, 08:02 and
  now". On a value outside `[windowStart, now]` show one inline line ("That's before the window starts on 22 Oct, 08:02") and disable
  Restore. Use the same formatted string with zone in the dialog's *From* row. Add a "1 hour ago" quick chip next to **Newest**,
  since that's the usual "undo my bad migration" case.
- **Files**: `features/backups/point-in-time.tsx` (`PointInTimePicker`, `pointLabel`), `features/backups/format.ts`.
- **Evidence**: [Neon zone line](https://mobbin.com/screens/463bbee6-ef19-48ee-ad92-7819bb1181fd?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp),
  [Neon inline error](https://mobbin.com/screens/0db73c4d-7092-4c22-910f-2d7efe2aa0a5?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp)
- **Doc update**: none beyond DAT-1's.

### DAT-4 Data tab opens the panel full width, like Settings — S
- **Problem**: in the default panel width the schema sidebar collapses above the grid, so the grid starts ~700 px down and wide rows are cut.
  docs/08 §15 describes a two-column layout that the narrow panel can't fit.
- **Change**: treat `data` the way `settings` is treated in `service-panel.tsx` (`expanded = settingsTab ? !settingsNarrow : pinned`):
  Data opens expanded, and the toggle narrows it for this visit. In the narrow layout (container < ~768 px) replace the stacked table list
  with a **table picker select** in the toolbar ("pageviews ▾ ~5.1K rows", grouped by schema, with filter) so the grid starts right under the
  Browse/Query switch.
- **Files**: `features/service-panel/service-panel.tsx`, `features/sessions/data-tab.tsx` (`Workspace`, `SchemaSidebar`).
- **Evidence**: [Vercel database browser](https://mobbin.com/flows/98057e90-2702-42a4-b996-c601484d93ca?tab=screens&utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp),
  [Supabase table editor](https://mobbin.com/screens/c7224401-b4bf-4d45-88b9-bf7ef0df5f2a?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp),
  [Replit](https://mobbin.com/flows/7fae3e4b-fd1a-45ce-a58b-98734467bbf7?tab=screens&utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp)
- **Doc update**: docs/08 §15 (Data opens full width; narrow = table select) and §16 "Full width" bullet.

### DAT-5 Grid: pinned pager with totals, row inspector, copy/download — M
- **Problem**: the pager sits under 50 rows and drops the "of ~N" the doc promises. You can't read a whole wide row, and you can't take results out.
- **Change**:
  1. Move Previous/Next into the header line next to the title: "pageviews  Rows 1–50 of ~5.1K  ‹ ›" (estimate from the sidebar's
     `rowsLabel`). Docs/08 §15 already specifies "Rows 51–100 of ~12 400".
  2. **Row inspector**: clicking a row number opens a side sheet (Sheet, right, full screen on phones) listing `column: value` pairs
     (mono values, NULL chip, long values wrapped, copy button per value), with ↑/↓ to step rows ("12 / 50"). Read-only. It is the
     same data that's already in memory, so no protocol change is needed.
  3. A small results toolbar on Query and Browse: **Copy as CSV**, **Download CSV** (client-side from the rows on screen, filename
     `<table>-rows-1-50.csv`), **Copy as JSON**. Results already live only in the tab, so this doesn't change the "never cached" rule.
- **Files**: `features/sessions/data-tab.tsx` (`TableBrowser`, `QueryConsole`), `features/sessions/result-grid.tsx`, new
  `features/sessions/row-sheet.tsx`.
- **Evidence**: [Supabase row sheet](https://mobbin.com/screens/9d24497c-18be-490d-aace-3226d0214d1a?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp),
  [Obvious inspector](https://mobbin.com/screens/3f3ebce5-2600-4a86-8d09-941892aac18a?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp),
  [Supabase SQL Export](https://mobbin.com/screens/fe760737-5cf1-482a-ad17-b44e3e3cf39f?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp)
- **Doc update**: docs/08 §15 (row inspector, export).

### DAT-6 `.env` tab for database credentials — S
- **Problem**: the temporary-access dialog for a database offers a Connection URL and `psql`/`redis-cli`, but most users paste
  credentials into a Laravel/Node `.env`.
- **Change**: add a client tab **.env** in `connection.ts` for Postgres (`DB_CONNECTION=pgsql`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`,
  `DB_USERNAME`, `DB_PASSWORD`, plus `DATABASE_URL=`) and Redis/Valkey (`REDIS_HOST`, `REDIS_PORT`, `REDIS_PASSWORD`, `REDIS_URL`).
  Show it masked in the recessed code block with reveal + copy-all, the way Laravel Cloud does. Put it first when the project's services
  were detected as Laravel, otherwise after URL.
- **Files**: `features/remote-access/connection.ts` (+ `connection.test.ts`), `features/remote-access/remote-access.tsx` (masking for the block).
- **Evidence**: [Laravel Cloud credentials](https://mobbin.com/flows/472b9dae-c619-47b5-b8cd-04be2e37ba1a?tab=screens&utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp),
  [Replit DATABASE_URL](https://mobbin.com/screens/36ed8105-df08-4a6c-ad01-25d0a484e853?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp)
- **Doc update**: docs/08 §15 "Temporary access" bullet (databases: Connection URL, .env, psql/redis-cli).

### DAT-7 After a restore, show what to do next — S
- **Problem**: once a "restore as new service" finishes, the user only sees a History entry. The swap and the inspection are separate trips.
- **Change**: when the newest restore run is `succeeded` and is less than ~24 h old, show a quiet success card at the top of the Backups page:
  "orders-db-restored-2026-10-19 is ready", restored from <time>, with three row actions: **Look at its data** (opens that service's Data tab),
  **Swap it in for orders-db…** (the existing `SwapDialog`), **Remove it**. Dismissible. Green dot + check icon only for the status word.
- **Files**: `features/backups/backups-tab.tsx` (History/restores data already loaded).
- **Evidence**: [Neon success next steps](https://mobbin.com/flows/5e8b3a4a-5792-41cd-ae25-a5a5e0b00f17?tab=screens&utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp),
  [Neon restore flow](https://mobbin.com/flows/ebe13d1c-83ce-45c7-bf22-33a78c2e2d60?tab=screens&utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp)
- **Doc update**: docs/14 restore section.

### DAT-8 Console: "Run a command" with presets and Run again — M
- **Problem**: the Console only offers an interactive shell. For the common one-offs (`php artisan migrate --force`, `tinker`, `npx prisma
  migrate deploy`), Laravel Cloud's command runner is faster and leaves a record you can re-run.
- **Change**: next to **Open console**, add a single-line command field with framework presets as chips (from the detected plan:
  Laravel → `php artisan migrate --force`, `php artisan about`, `php artisan queue:restart`; Node/Prisma → `npx prisma migrate deploy`).
  Running opens a normal console session and types the line into it. That's the user's own session under the Console permission,
  so it is not a new "run on host" path. In **Recent sessions**, show the first command typed and add **Run again**. If the service
  isn't running, say why inline with the fix, as Laravel Cloud does ("node-hello isn't running. **Restart it**"), instead of disabling silently.
- **Files**: `features/sessions/console-tab.tsx`, `features/sessions/recent-sessions.tsx`, `features/sessions/terminal.tsx`
  (write-on-connect). Server-side: storing the first command line needs a field on the session record (`app/Models/…Session`, Data object); keep it out of transcripts if the team disabled recording.
- **Evidence**: [Laravel Cloud Running a command](https://mobbin.com/flows/0a8cfbfc-0a27-432e-a01f-b633487729cc?tab=screens&utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp),
  [Replit Running a command](https://mobbin.com/flows/7e659dc4-ad75-412e-a17d-083c597c12db?tab=screens&utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp)
- **Doc update**: docs/08 §15 Console; check docs/09 R33 wording (it's typing into the user's session, still under the Console permission).

### DAT-9 Team Backups: drop the decorative rings, lead "What's protected" with a summary — S
- **Problem**: the destination rings are always full, so they decorate instead of measuring. "What's protected" lists 19 identical-weight rows, and
  the 16 "No backup yet" ones (the real problem) look neutral.
- **Change**:
  1. Destination card: replace the ring with a plain right-aligned stat ("47 MB", muted "latest copy"), or with a usage bar only if the
     bucket reports a quota. Keep Connected + "Last backup 21 days ago". Go amber with the clock icon when that age exceeds the slowest schedule.
  2. "What's protected" header gets one summary line ("3 backed up · 16 waiting for their first backup · 0 failing"), and the rows are sorted
     failing → overdue → never → healthy. Rows whose first run is still ahead say "First backup tonight at 06:07" (muted, hollow dot)
     instead of "No backup yet". Overdue or never-run past their first slot get amber + icon.
  3. Show the schedule like PlanetScale's card: "Daily at 06:07 · keeps 7 daily, 4 weekly, 3 monthly · next in 13 h".
- **Files**: `features/backups/destinations-section.tsx`, `features/backups/schedules-section.tsx`, `features/backups/backup-health.tsx`.
- **Evidence**: [PlanetScale schedule card](https://mobbin.com/flows/06b19726-4b94-4b2e-b5be-8c742e982c16?tab=screens&utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp),
  [Laravel Cloud resource list facts line](https://mobbin.com/flows/690be9ec-aa46-4d38-8566-ee813ffcd176?tab=screens&utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp),
  [Supabase tables list with size/rows](https://mobbin.com/screens/f8ae515b-d3a5-4342-bda2-4dde28f21b0f?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp)
- **Doc update**: design-system.md / docs/14 team Backups section if it specifies the ring.

### DAT-10 Grid column types and header sort — M/L (needs protocol)
- **Problem**: headers show bare names, and Browse can't sort (you have to write SQL for "newest first").
- **Change**: extend the `rows` data command's result with column types (`int8`, `timestamptz`, `text`; Redis: n/a) and accept an optional
  `orderBy {column, direction}` validated against the table's real columns (grammar + allow-list on both sides, docs/09). Show the type
  in muted mono after the name. Clicking a header cycles sort with an arrow icon. Default: primary key descending when there is one, since
  people mostly want the newest rows.
- **Files**: `features/sessions/result-grid.tsx`, `features/sessions/data-tab.tsx`, `features/sessions/result-types.ts`; dashboard Data
  objects for the data-session result; launchroomd data-session handler + `protocol/schema/v1` (regen with `make gen-protocol`, `make gen-types`).
- **Evidence**: [Supabase headers + sort](https://mobbin.com/screens/d3b6831e-f216-43b7-a103-09af362e2bfd?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp),
  [Replit typed headers](https://mobbin.com/flows/7fae3e4b-fd1a-45ce-a58b-98734467bbf7?tab=screens&utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp),
  [Retool PK tag](https://mobbin.com/flows/e2c25af0-6992-4cba-9e21-506af66e8c8d?tab=screens&utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp)
- **Doc update**: docs/03 §11 (data session commands), docs/08 §15.

### DAT-11 Write mode: catch the dangerous statement before it runs — S/M
- **Problem**: once "Allow writes" is on, `DELETE FROM orders` without WHERE runs the same as a SELECT.
- **Change**: when writes are allowed, check the statement client-side before sending. `UPDATE`/`DELETE` without `WHERE`, `DROP`,
  `TRUNCATE` and `ALTER … DROP` open a confirm popover that names the table ("This deletes every row in orders. The last backup is from
  2 days ago.") with **Run it** and **Cancel**, plus a "Back up now first" link when the last backup is more than a day old. This is advisory
  only. The daemon's own policy stays the real guard.
- **Files**: `features/sessions/data-tab.tsx` (`QueryConsole`), small helper `features/sessions/risky-statement.ts` (+ test).
- **Evidence**: [Supabase pre-run warning](https://mobbin.com/screens/ddc7775d-ddd7-4f5a-90cd-c49e66325dbb?utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp),
  [Laravel Cloud "final backup" wording](https://mobbin.com/flows/690be9ec-aa46-4d38-8566-ee813ffcd176?tab=screens&utm_source=claude-code&utm_medium=ai-assistant&utm_source_platform=mcp)
- **Doc update**: docs/08 §15 Query bullet.

### Not recommended
- A dark "terminal look" for the Console: most products use one, but design-system.md's theme-token terminal is fine, and changing it buys nothing.
- Neon's "preview historic data at a timestamp": that needs a throwaway restore. Our *restore as a new service* + DAT-7's "Look at its data" covers the same need.
- Inline cell editing (Supabase/Airtable): it raises write-risk in a self-hosted tool and conflicts with read-only by default. Leave it to the SQL query with writes allowed.
