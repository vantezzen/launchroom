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
   4. Deploy your first app: framework logos, opens the import flow (§3).
   5. Keep a backup off this server (optional, clearly labelled): destination tiles (R2, Hetzner, S3, other) → credentials → "Download recovery kit" → done.
4. Finishing step 4 lands on the project canvas with the deploy running, not back on the checklist. The sidebar keeps "Get started 4/5" until every step is done, skipped or the checklist is hidden.

**Magic.** "This server is already connected" on arrival. GitHub returns to the exact card. DNS turns green by itself. The first deploy starts from inside onboarding.

**Copy.** "Your launchroom is up." · "Three short steps until your first app is live on fra-1. Everything else can wait." · "Waiting for DNS · checking every 10 seconds, usually done in a minute."

## 2. Connect GitHub

- One "Connect GitHub" button wherever it's needed (onboarding, the import page's empty state, Settings → Git). Organisations and GitHub Enterprise Server sit under a disclosure.
- After the app is created, launchroom forwards to the GitHub installation page; the return URL is the place you started.
- Settings → Git shows one card per connection: app, owner, "12 repositories · Manage on GitHub ↗", and one health line ("Receiving webhooks" / "Checking for pushes every minute" when GitHub can't reach this dashboard). Other providers (GitLab, Gitea / Forgejo, any git server) follow the same pattern ([../19-git-providers.md](../19-git-providers.md) §4).

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

## 5. Deploy and watch it go live

- While something is staged, the canvas toolbar ends in **Review & deploy** (dashed signal = staged). It opens a right sheet: changes grouped by service with their effect ("api: restart · web: rebuild"), an optional message, **Deploy**. No navigation.
- After Deploy you stay on the canvas: each affected node shows a compact progress bar and its current step ("Building · 41s", "Health check · 1 of 2"). The panel (if open) shows the step pills Build ✓ · Migrate ✓ · Health ◌ · Route.
- When the deployment finishes, the node's status cross-fades (180 ms); a toast says "web is live · Open"; failure toasts link to the explained error.
- The project card and ⌘K show "Deploying" while it runs.

**Magic.** Zero downtime is visible: during health checks the node shows the new version next to the current one, then "Live".

## 6. Debug a failed deploy

1. The node says "Last deploy failed · still serving 3b7d5e1" (reassurance first: nothing went down).
2. Deployment page: the overview card, then the **explained failure card** (Laravel error page style): a human title ("Build failed: pnpm lockfile is out of date"), one or two sentences of cause and fix, actions ("Jump to line 7", "Copy command"), the failure class as a small chip.
3. Steps list with the failed step open and scrolled to its first error, with error lines highlighted. The same toolbar searches every step, expands all steps, and copies or downloads the whole log.
4. Known fixes are buttons: `port_mismatch` → "Use port 5173 and redeploy"; `health_timeout` → "Allow 60s to start and redeploy"; missing variable → "Add NEXT_PUBLIC_STRIPE_KEY" (opens the Variables tab prefilled). The fix stages the change and deploys it in the same click when it is the only staged change.

**Magic.** The fix is one click for known errors.

**Copy.** Lead with the outcome and reassurance: "Build failed: pnpm lockfile is out of date. Your site is still serving the previous version."

## 7. Roll back

- Every earlier deployment in the panel history has a visible "Roll back" on hover.
- The confirm is a small popover, not a dialog: "Roll back web to 9a1b2c3 (2 days ago)? Traffic switches in seconds. New pushes won't go live until you resume auto-deploy. Database migrations are not reverted." [Roll back]
- After a rollback, a banner under the project tabs says auto-deploy is paused, with **Resume auto-deploy** right there.

## 8. Change env vars safely (staged changes)

- Variables tab rows changed since the last deploy get the staged treatment (signal rule + "Changed" chip); new rows "New".
- Paste anywhere: pasting a `.env` into the tab opens the import preview directly.
- The staged bar floats at the bottom of the panel: "2 changes · api will restart · Discard · Deploy". Deploying from there is the same Deploy as everywhere.
- Broken references are listed per row, and Deploy is disabled with the reason.

**Magic.** "Restart, not rebuild": the bar tells you the deploy will be fast because only runtime variables changed.

## 9. Add a custom domain

- "Add domain" is the primary action of the Domains page in the panel's Settings tab.
- The add dialog: one input with a live hint (root domain needs an A record, a subdomain can use a CNAME, inside the platform domain needs nothing); then the records to create with copy buttons, and a live wait that re-checks until "You're all set". Each domain card shows where a request goes: **DNS → HTTPS → Traffic**, each step turning green by itself.
- "Redirect www" is a switch on the domain card.
- Domain additions and removals apply immediately (D32, [../06-variables-environments.md](../06-variables-environments.md) §4); they don't wait for a deploy.

## 10. Add a server (VPS) / home build server

1. "What kind of machine is it?" tiles with device art: Cloud server, Dedicated server, Machine at home, Small board. This only seeds role defaults and copy (home → Build suggested, "no ports to open").
2. "What should it do?" three large role cards (Run apps / Serve traffic / Build) with one-line explanations and role hints for this kind of machine; suggested ones pre-selected.
3. "Run this on the server": the one-line command with a platform switcher (Ubuntu/Debian, other Linux, Docker already installed), single-use and expiring, then the **live wait**: the device render with its status LED off, "Waiting for your server…", which turns into the detected device ("Mac mini · 10 cores · 16 GB") and the LED lights up when it connects.
4. If approval is required, the approve button appears right in the wait card.
5. Done → the server card on the Servers page animates in; the second server turns on the private network by itself.

**Magic.** "One command, and it's in." The device recognises itself; the home machine joins with no ports opened (said explicitly on the home tile).

## 11. Scale and place replicas

- The Scaling page shows the live picture first: one card per runtime server with its device render and the replicas standing on it ("2 on fra-1, 1 on hel-1"), CPU and memory bars against capacity, and the per-server rule. Every change asks the real scheduler.
- Replicas use a **stepper** (Umbrel Machines style: − 3 +); size per replica uses sliders that end at the biggest eligible server. Spread/Pack and required labels follow below.
- Changes surface in the floating bar ("api: restart · 3 replicas across 2 servers").
- On the canvas node footer: "×3 · fra-1, hel-1".

## 12. Staging → production promotion

- The page header shows the two environments as cards with an arrow between them ("staging → production", swap on click), each card with its live revision and last deploy.
- A summary strip: "3 changes · 1 needs a production secret · 1 environment-specific (unchecked)".
- Items keep the checkbox model; adds, edits and removals get distinct gutters (+ green, ~ blue, − red), rows aren't tinted.
- The primary action is **Promote and deploy** (stages and deploys in one step); "Stage only" is secondary.

**Magic.** References resolve in the target automatically, and the UI says so ("REDIS_URL will point at production's redis").

## 13. PR previews (incl. fork approval)

- Previews page as **cards**: each PR with a URL card of its main address, title, author avatar, branch → base, status, URL with lock icon, "expires in 7 days", and the database strategy ("new empty database").
- Fork PRs needing approval sit at the top in their own group "Waiting for you" with the diff summary link ("View changes on GitHub ↗"), the commit to approve, and **Approve this commit**; the copy states the safety: "It runs without secrets, data or access to your network."
- Empty state: icon + "Open a pull request to get a preview" + "Preview settings".

## 14. Backups, restore, and replacing a dead server

- Settings → Backups: destination card with provider logo, connection status, last backup, and a **donut of stored size by kind** (databases / volumes / launchroom) (Umbrel storage manager). "What's protected": one row per database/volume with a **30-day run strip** and next/last backup. The recovery kit is a card with its own state ("Saved 21 days ago"), not an always-open form.
- Schedules are edited as "Daily at 03:12 · keep 7 daily, 4 weekly" with a picker, never raw cron (cron stays under "Custom").
- Panel → Backups: a snapshot timeline, Restore as option cards: "As a new service" (the safe default) and "Into postgres" with the consequence and a typed confirmation.
- **Replace a dead server**: from the unreachable server card ("No report for 8 min · 2 replicas were placed here" → "Replace server"), the wizard shows the dead server's device greyed, the replacement as device tiles (or "Add a new server", which runs flow 10), and the latest backup per database and volume.

**Magic.** "Lose the server, keep everything": the unreachable card already says what was placed there and what's at risk.

## 15. Update launchroom

- A status hero: "You're up to date · 0.4.2" or "0.5.0 is ready" with What's new, **What happens** (back up, install, update servers) and **Update now**. "A backup is taken first" is stated, not hidden in a dialog.
- Progress is one step list (back up, install and restart, check, then each server) with the same component as deployment steps; the page survives the dashboard restart and resumes.
- Servers as small cards with their version; the rolling order goes one server at a time, edges last.
- The sidebar shows a quiet dot on Settings when an update is ready (owners only).

## 16. Invite a teammate

- Members as rows with avatar, name, email, role chip; role and remove behind `⋯` (remove confirms in a popover). "Invite people" opens a sheet: emails (comma or one per line), role as three tiles (Admin / Developer / Viewer) with what each can do, Send.
- Pending invitations listed with "Send again" and "Revoke" (no "Copy link": tokens are stored hashed).
- Invite acceptance page: team, inviter, role, "Join Acme".

## 17. ⌘K power-user paths

- A **visible search field in the sidebar** ("Search ⌘K") makes the palette discoverable.
- Context first: inside a project, its services and actions come first; on a deployment, "Roll back to this deployment".
- Verbs: "Redeploy web", "Roll back to this deployment", "Open logs of web", "Add variable to api", "Switch to staging".
- Rich rows: service logos, project icons, status dots, environment chips; deployments show commit + age.
- Leader shortcuts: `g p` projects, `g s` servers, `g d` deployments, `g l` logs, `g v` variables; `/` focuses log search.
