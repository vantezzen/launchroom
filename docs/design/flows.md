# User journeys

The common journeys end to end. The bar: **what you want is exactly where it should be, working how you expect, or easier because launchroom does part of it for you.**

Screens are described in [direction.md](direction.md) §9 and [../08-dashboard-ux.md](../08-dashboard-ux.md).

Conventions used below:
- **Magic** = a moment where launchroom does something the user expected to do by hand. Each one gets a visible beat (a toast, a status change, a line on the canvas) so it's noticed, never silent.
- **Copy** = the tone and sample lines; full rules in [../design-system.md](../design-system.md#voice-and-copy). Servers, never agents. Sentence case. No "simple/easy".

## Principles that apply to every flow

1. **Default forward.** Every screen has one obvious next action, already pointing at the most likely choice. Advanced choices are one click away, never in the way.
2. **Show, then ask.** Detect first (repo, machine, DNS), show what we found, ask only for what we couldn't find.
3. **Never leave the context to fix something.** If a deploy is blocked by a broken variable, the fix opens in a sheet over the page, not a navigation away.
4. **Every wait is live.** Waiting states show what's happening right now and roughly how long it takes ("Usually 20–40 seconds"), update by themselves, and offer a "not working?" path after a sensible timeout.
5. **Undo over confirm.** Reversible things happen immediately with an undo toast. Only irreversible things ask (and then with consequences in plain words).
6. **One place for status.** The canvas node, the project card and the panel header show the same status word for the same state (see the status vocabulary in [../design-system.md](../design-system.md) §3.3).

---

## 1. First install and onboarding

1. `curl | sh` prints a setup URL with a one-time token. `/setup` is a two-pane welcome: left, "Your launchroom is installed." with the device render of the detected machine and its live facts ("fra-1 · Hetzner Cloud · 4 cores · 8 GB · connected"); right, the owner form (name, email, password).
2. Two-factor sign-in is recommended, never required (D33): a calm card on Get started. Enrolment is one full-height step with a large QR, the code input and the recovery codes.
3. **Get started**: a hero line ("Your launchroom is up.") and a progress ring; five cards, **only the current one expanded**. Done steps collapse to one line with their result (the server card shows its device). Order:
   1. Server connected (done by itself, shown as done on arrival).
   2. Connect GitHub: the GitHub App manifest flow runs **inline**: GitHub opens in the same tab and returns to this card, which then says "Connected · acme · 12 repositories".
   3. Give your apps an address: two tiles, **Use my domain** (one input, the record to create, a live "waiting for DNS" status that re-checks every 10 s) or **Use a free address for now** (sslip.io, done instantly). Let's Encrypt rate limits are a hint on the free tile, not a paragraph.
   4. Deploy your first app: framework logos, opens the import flow (§3). Done when the app is live, not when the project exists; while the first deploy runs the step shows it ("web is building, 41s", **Watch it**), and done it shows the live address.
   5. Keep a backup off this server (optional, clearly labelled): one item for the storage (the team's and launchroom's own), then the passphrase and the saved kit; **Connect storage** opens the Backups page's setup sheet (§14). Done once team storage exists and the kit is confirmed.
4. Finishing step 4 lands on the project canvas with the deploy running, not back on the checklist. The sidebar keeps "Get started 4/5" until every step is done, skipped or the checklist is hidden.
5. Complete: no subtitle; the done steps become one line ("Server, address and first app are set up."), skipped steps stay as rows with their action, and the top-bar button reads **Remove from sidebar**.

**Magic.** "This server is already connected" on arrival. GitHub returns to the exact card. DNS turns green by itself. The first deploy starts from inside onboarding.

**Copy.** "Your launchroom is up." · "Three short steps until your first app is live on fra-1. Everything else can wait." · "Waiting for DNS · checking every 10 seconds, usually done in a minute."

## 2. Connect GitHub

- One "Connect GitHub" button wherever it's needed (onboarding, the import page's empty state, Settings → Git). Organisations and GitHub Enterprise Server sit under a disclosure.
- After the app is created, launchroom forwards to the GitHub installation page; the return URL is the place you started.
- Settings → Git shows one card per connection: app, owner, "12 repositories · Manage on GitHub ↗", and one health line ("Pushes arrive right away" / "A push didn't arrive by webhook: checking every minute" after a missed push). Other providers (GitLab, Gitea / Forgejo, any git server) follow the same pattern ([../19-git-providers.md](../19-git-providers.md) §4).

**Magic.** Returning from GitHub lands where you started, with the repo picker open.

## 3. Create the first project from a repo (incl. monorepo, plan preview)

"Import a repository" is a full-page guided flow at `/projects/new` (also reachable from ⌘K):
1. **Pick a repository**: search over granted repos (recent pushes first), each row with its provider mark, framework logo, last push and visibility. "Don't see it? Grant access on GitHub ↗".
2. **We looked at your repo** (the plan preview runs while this screen appears): a hero card per detected app, like Umbrel's machine card: big framework logo, "Next.js 16.1 · pnpm 9 · Node 22", the commands as Detected + Override values, port, and the variables found in `.env.example`. For monorepos the card list *is* the workspace list: "We found 3 apps in this repository" with checkboxes (web ✓, api ✓, docs ☐) and their root directories filled in.
3. **Suggested services**: "api uses Postgres and Redis" (from `.env.example` keys and the variables the code references) as pre-checked tiles; workers suggested from the plan ("queue: php artisan queue:work"). Connections are drawn automatically.
4. **Name and deploy**: project name prefilled from the repo, environment "production", one primary button **Deploy**. A brand-new project deploys directly, without the staged review.
5. Land on the canvas with the auto-laid-out graph, every node deploying with a progress bar.

Plan preview states, each designed: Inspecting (skeleton card with the repo name and "Looking at package.json, composer.json, Dockerfile…"), Ready, Failed (what we couldn't detect + build and start command fields + "Use your Dockerfile", or Generate a Dockerfile, D48), No build server online (link to Servers).

**Magic.** Detected databases and connections appear without asking; credentials are injected with framework-aware names; the project is live in one click.

**Copy.** "We looked at acme/shop." · "We found 2 apps and 2 databases." · "api uses Postgres. We'll add one and connect it."

## 4. Add a database and connect it

- Add sheet → "Database" opens a **choose-your-database tile grid** with logos. Picking one asks one question: "Connect it to…" with the environment's other services as toggle chips (preselected when there is only one). Add → the node drops in, edges draw themselves (300 ms draw-on), and the connection injects the variables.
- On the canvas, every node has a visible "+" handle; drag from it to another node to connect.
- The edge label shows the injected variable summary (`DB_* · 6`); clicking it opens the edge popover with the full list.

**Magic.** "It just connects": one choice and the credentials are in place.

## 5. Apply and watch it go live

- While something is staged, the canvas toolbar ends in "4 changes" and **Review & apply** (dashed signal = staged). It opens a right sheet: changes grouped by service with their effect, one sentence that says which services redeploy ("Applying rebuilds web, and restarts api and worker."), an optional message, **Apply 4 changes**. No navigation. Staged changes are applied, not deployed: only services deploy, and applying redeploys the ones it affects (D75).
- After Apply you stay on the canvas: each affected node shows a compact progress bar and its current step ("Building · 41s", "Health check · 1 of 2"). The panel (if open) shows the step pills Build ✓ · Migrate ✓ · Health ◌ · Route.
- When the deployment finishes, the node's status cross-fades (180 ms); a toast says "web is live · Open"; failure toasts link to the explained error.
- The team's first app gets its own beat: a card at the top left of the canvas lists the first deploy's steps (Build, Start, Health check, Route, each waiting, running or done with its duration), then turns into "Your first app is live" with the address (**Copy**, **Open**) and three next steps (Use your own domain, Deploy on every push for public-URL sources, Keep a backup off this server). It replaces that service's toasts while it shows, is dismissed once and never comes back.
- The project card and ⌘K show "Deploying" while it runs.

**Magic.** Zero downtime is visible: during health checks the node shows the new version next to the current one, then "Live".

## 6. Debug a failed deploy

0. From anywhere in the team: **Projects** opens with "Needs you", and the failing project's card names the service ("web failed to deploy") with **See why**, one click to step 2.
1. The node says "Last deploy failed · still serving 3b7d5e1" (reassurance first: nothing went down).
2. Deployment page: the **explained failure card** first (Laravel error page style), above the overview: a human title ("Build failed: pnpm lockfile is out of date"), one or two sentences of cause and fix, the error line quoted with its neighbours, actions ("Jump to line 7", "Copy command"). The failure class is not shown as a chip; the card's copy button holds it with the raw message. Redeploy is the primary action only when a retry can help (timeouts, transient and runtime failures); a launchroom.json failure has no Redeploy and opens the file instead.
3. Steps list with the failed step open and scrolled to its first error, with error lines highlighted. The same toolbar searches every step, expands all steps, and copies or downloads the whole log.
4. Known fixes are buttons: `port_mismatch` → "Use port 5173 and redeploy"; `health_timeout` → "Allow 60s to start and redeploy"; missing variable → "Add NEXT_PUBLIC_STRIPE_KEY" (opens Settings → Variables prefilled). The fix stages the change and deploys it in the same click when it is the only staged change.

**Magic.** The fix is one click for known errors.

**Copy.** Lead with the outcome and reassurance: "Build failed: pnpm lockfile is out of date. Your site is still serving the previous version."

## 7. Roll back

- Every earlier deployment in the panel history has a visible "Roll back" on hover.
- The confirm is a small popover, not a dialog, with two rows, **Now live** above **Roll back to** (status glyph, sha, message, age): "Roll back web to 9a1b2c3 (2 days ago)? Traffic switches in seconds. New pushes won't go live until you resume auto-deploy. Database migrations are not reverted." [Roll back]
- After a rollback, a banner under the project tabs says auto-deploy is paused, with **Resume auto-deploy** right there.

## 8. Change env vars safely (staged changes)

- Variables page rows changed since the last deploy get the staged treatment (signal rule + "Changed" chip); new rows "New".
- Paste anywhere: pasting a `.env` into the tab opens the import preview directly.
- Saving a settings page that stages is one decision, not two bars: the unsaved bar names what changed ("Replicas changed", a click scrolls there) and its Save is a split button. **Save and apply** (the default) saves, then applies everything staged in the environment ("web restarts now, and the 2 other staged changes apply too"); **Save for later** keeps it staged for the next deploy or Apply. Without the right to apply, with broken references, or before the first deploy, Save for later leads and Save and apply stays listed with its reason.
- The staged bar floats at the bottom of the panel: "2 changes", "api will restart", Review (the review sheet), Discard, **Apply**. Applying from there is the same Apply as everywhere (it applies everything staged in the environment), so when other services have changes too the count says the scope ("4 changes in 2 services") and the bar keeps Review and Apply; discarding one service's changes happens in the review (D109). While it shows, the panel header drops its "Review & apply", so staged changes have one home per surface.
- The panel keeps room under its last row for the bar, so nothing stays covered once you scroll to the end.
- Broken references are listed per row with the valid references they most likely meant (one click opens the editor with the value rewritten), the alert's Fix brings the first one into view, and Deploy is disabled with the reason.

**Magic.** "Restart, not rebuild": the bar tells you the deploy will be fast because only runtime variables changed.

## 9. Add a custom domain

- "Add domain" is the primary action of the Domains page in the panel's Settings tab.
- The add dialog: one input with a live hint (root domain needs an A record, a subdomain can use a CNAME, inside the platform domain needs nothing); then the records to create with copy buttons, and a live wait that re-checks until "You're all set". Each domain card shows where a request goes: **DNS → HTTPS → Traffic**, each step turning green by itself.
- "Redirect www" is a switch on the domain card.
- Domain additions and removals apply immediately (D32, [../06-variables-environments.md](../06-variables-environments.md) §4); they don't wait for a deploy.

## 10. Add a server (VPS) / home build server

1. "What kind of machine is it?" tiles with device art: Cloud server, Dedicated server, Machine at home, Small board. This only seeds role defaults and copy (home → Build suggested, "no ports to open").
2. "What should it do?" three large role cards (Run apps / Serve traffic / Build) with one-line explanations, one muted line of what the role needs from the machine ("Needs to stay on", "Needs a public IP with ports 80 and 443 open", "Best with 4 or more cores"), and role hints for this kind of machine; suggested ones pre-selected.
3. "Run this on the server": the one-line command, single-use and expiring. The join token is masked (`lr_jt_••••••••abcd`, the dashboard key shortened) so a screenshot or screen share does not leak it; an eye button reveals it and Copy always copies the full command. The command is the same everywhere, so instead of a platform switcher a short list says what each platform needs first (Ubuntu or Debian, other Linux, Mac; Mac first for a Mac). Then then the **live wait**: the device render with its status LED off, "Waiting for your server…", which turns into the detected device ("Mac mini · 10 cores · 16 GB") and the LED lights up when it connects.
4. If approval is required, the approve button appears right in the wait card.
5. Done → the connected card proves it works: when other servers exist, a small diagram joins "Dashboard" and the new server with the private network line (solid direct, dashed relayed, dotted "Connecting…" until the first mesh report), and a checklist of live rows ("Reports to the dashboard every few seconds", "On the private network, direct", "Ready to run apps and build"). The page keeps polling until the link is up. The second server turns on the private network by itself.

**Magic.** "One command, and it's in." The device recognises itself; the home machine joins with no ports opened (said explicitly on the home tile).

## 11. Scale and place replicas

- The Scaling page shows the live picture first: one card per runtime server with its device render and the replicas standing on it ("2 on fra-1, 1 on hel-1"), CPU and memory bars with what the server really uses and has free (other services' limits only as a footnote: they are caps), and the per-server rule. Every change asks the real scheduler.
- Replicas use a **stepper** (Umbrel Machines style: − 3 +); size per replica starts with presets (Small 0.5 core and 512 MB, Medium 1 core and 1 GB, Large 2 cores and 4 GB; one that no eligible server can give says "Bigger than any server"), and **Custom** opens sliders that end at the biggest eligible server. Spread/Pack and required labels follow below.
- Changes surface in the floating bar ("api: restart · 3 replicas across 2 servers").
- On the canvas node footer: "×3 on fra-1, hel-1".

## 12. Staging → production promotion

- The page header shows the two environments as cards with an arrow between them ("staging → production", swap on click), each card with its live revision and last deploy.
- A summary: "3 changes to production", tiles + added / ~ changed / − removed, "Restarts api and web"; a missing production secret is named right under the disabled **Promote and deploy**. Also promote images shows the commit range per service (production 7c8d9e0 → staging 3b7d5e1, with messages) and Compare commits when the provider has a compare view.
- Items keep the checkbox model; adds, edits and removals get distinct gutters (+ green, ~ blue, − red), rows aren't tinted.
- The primary action is **Promote and deploy** (stages and deploys in one step); "Stage only" is secondary.

**Magic.** References resolve in the target automatically, and the UI says so ("REDIS_URL will point at production's redis").

## 13. PR previews (incl. fork approval)

- Previews page: the rule in one sentence under the title; open PRs as **cards**, closed and expired ones as one-line rows below. Each card: a URL card of its main address (none without one), title, author avatar, branch → base, status, URL with lock icon, "expires in 7 days", and the database strategy ("new empty database").
- Fork PRs needing approval sit at the top in their own group "Waiting for you" with the diff summary link ("View changes on GitHub ↗"), the commit to approve, and **Approve this commit**; the copy states the safety: "It runs without secrets, data or access to your network."
- Empty state: icon + "Open a pull request to get a preview" + "Preview settings".

## 14. Backups, restore, and replacing a dead server

- **Backups** is a Team page of Settings (`/settings/backups`, D114; a sidebar page before, D95); the old `/backups` redirects there. Top: the state as one sentence ("Everything is backed up. Last backup 2 hours ago, next today at 03:12.", "postgres needs you.", "Nothing is backed up off your servers yet.") and **Back up everything now**. Then "What's protected": one row per database, per volume and for launchroom itself, grouped by project, each with a **30-day run strip**, next/last backup ("First backup tomorrow at 06:07" with a hollow dot until the first run), size and **Back up now**. Then "Not backed up" (collapsed, the reason per item, **Turn on**), the storage cards (provider logo, status by cause with its fix, a **donut of stored size by kind** when it holds more than one kind) and the recovery kit card ("Saved, put away 21 days ago").
- **Set up** (no storage yet: "Keep a copy off your servers"): a sheet with steps. *Where*: provider tiles. *Connect*: the form with inline field errors and, for instance owners, "Also protect launchroom itself" (ticked when it is offered). The connection test runs live and names the cause (wrong keys, wrong repository password, bucket not found, unreachable) with the fix, looping back to the form. *launchroom itself*: launchroom gets a copy of the same storage in its own folder, then the recovery passphrase, the kit download and **Keep it safe** ("Print it, or save it in your password manager. Keep the passphrase somewhere else."; "I've put both away"). Done: "Backup is on" with what is protected and **Back up everything now**.
- **Running**: a row's button becomes the run ("Uploading 412 MB of about 1.2 GB", or "Waiting for hel-1" when another backup holds that server) with its steps under it; nothing needs a reload, and a second click is impossible.
- **Volumes**: backed up by default. "Back up this volume" (Settings → Volumes, or "Stop backing up…" on the row) states the consequence: "Existing backups stay until they expire". A turned-off volume moves to "Not backed up" with who turned it off and when. A volume that looks like a cache gets "Looks like a cache. Stop backing it up".
- **Remove storage**: the confirmation says new backups stop, the backups already in the bucket stay there and their history stays here. The storage becomes a "Storage removed" row with **Add again**, which asks for the repository password and picks the old backups up again.
- Schedules are edited as "Daily at 03:12 · keep 7 daily, 4 weekly" with a picker, never raw cron (cron stays under "Custom").
- Panel → Settings → Backups (restoring stays with the service): restore points grouped by day (Today, Yesterday, weekday and date), Restore as option cards: "As a new service" (the safe default) and "Into postgres" with the consequence ("A backup of postgres as it is now is taken first, so you can go back.") and a typed confirmation.
- **Replace a dead server**: from the unreachable server card ("No report for 8 min · 2 replicas were placed here" → "Replace server"), the wizard shows the dead server's device greyed, the replacement as device tiles (or "Add a new server", which runs flow 10), and the latest backup per database and volume.

**Magic.** "Lose the server, keep everything": the unreachable card already says what was placed there and what's at risk.

## 15. Update launchroom

- A status hero: "You're up to date · 0.4.2" or "0.5.0 is ready" with What's new, **What happens** (back up, install, update servers) and **Update now**. "A backup is taken first" is stated, not hidden in a dialog.
- The update dialog picks the backup as option cards: "Use the latest backup" (when it finished and its size; preselected when under a day old), "Take a new backup first" or, while one runs, "Wait for the running backup".
- Progress is one step list (back up, install and restart, check, then each server) with the same component as deployment steps; the page survives the dashboard restart and resumes.
- Servers as small cards with their version; the rolling order goes one server at a time, edges last.
- The sidebar shows a quiet dot on Settings when an update is ready (owners only).

## 16. Invite a teammate

- Inline invite on top of the members card: email, role select (Viewer → Developer → Admin → Owner, Developer by default, each with what it can do), "Send invite", a toast confirms. "Invite several people at once" opens the sheet: emails (comma or one per line), role tiles in the same order, Send.
- Members as rows with avatar, name, email, role chip; role and remove behind `⋯` (remove confirms in a popover).
- Pending invitations are rows in the same list with an "Invited" chip and `⋯` "Send again" / "Revoke" (no "Copy link": tokens are stored hashed).
- Invite acceptance page: team, inviter, role, "Join Acme".

## 17. ⌘K power-user paths

- A **visible search field in the sidebar** ("Search ⌘K") makes the palette discoverable.
- Context first: inside a project, its services come first with their status (failing ones on top), before any typing; with a service open, its commands lead; on a deployment, "Roll back to this deployment".
- Verbs per service: "Logs of web", "Analytics of web" (Traffic with a domain, else Resources), "Open web.shop.dev", "Redeploy web", "Restart web" (both confirm inline), "Variables of web", "Data of postgres"; also "Add variable to api", "Switch to staging". "api logs" and "logs api" both find it.
- Create verbs before typing: New project, Add a service (in this environment), Add domain (with a service open), Add server, Invite people, each only for people who may. Navigate mirrors the sidebar exactly; typing also finds the settings pages ("API tokens" with "Account" after it).
- Recent is where you've actually been (service panels, canvases, deployments, servers), not only palette picks.
- Rich rows: service logos, project icons, status words with their icon; deployments show commit + age. Never variable values.
- Leader shortcuts: `g p` projects, `g s` servers, `g c` canvas, `g d` deployments, `g l` / `g a` logs / analytics (of the open service when there is one), `g v` variables; `o` opens the open service's live URL; `/` focuses log search; `?` lists every shortcut. No single key changes anything.
