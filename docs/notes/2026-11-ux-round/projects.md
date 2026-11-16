# UX round: projects home, onboarding and creating things (PRJ)

Screens reviewed: `projects`, `onboarding`, `setup`, `templates`, `template-detail`, `compose-import`, `git-source` (site-retake/raw, Oct 28), `add-database`, `ai-dockerfile` (ux-round/before). `x-new-project` and `x-mobile-projects` did not exist yet, so the new project page was judged from `dev/screenshots/new-project/after/new-1440-light.png`, `after-first-time/new-1440-light.png`, `after/new-390-light.png` and `after-2/import-dbs-light.png`, plus the source.

Mobbin: 15 searches (search_flows for Laravel Cloud, search_screens for the rest). Mobbin's image links need auth, so I couldn't open the full-size versions of Laravel Cloud flow screens. Everything below is based on the inline previews returned by the searches.

---

## 1. Current state

- **Projects (`projects`)** works as a picture-first grid. Each card shows a mini service graph, a status word with an icon, a domain, a sparkline and the last commit, so it already follows "picture → number → words". It has three problems. It sorts by name (`ProjectSummaries::for()` → `orderBy('name')`), so with 26 projects the ones you touched today sit next to dead test projects. Each card is 280 px tall, so you see 6 per screen and there is no denser view. And a card has no quick actions, only "open".
- **Get started (`onboarding`)** is calm and clear. Only the current step is open, there's a progress ring, and done steps take one line. The weak spot is step 4. It counts as done when a project *exists* (`OnboardingChecklist.php:136`, `$projectCount > 0`), not when an app is *live*. In the complete state it says "Your first app is set up / 26 projects" with no URL. The first success moment, "my app is on the internet", never gets its own beat. Once the list is complete, the page is a 5/5 list with nothing to do next.
- **Setup (`setup`)** is strong. The two-pane layout, the device render and "This server is already connected" match flows §1 and need no change.
- **New project** has a good first-run state (provider logos, "Connect GitHub to deploy on every push", three benefits). The problem is order. The two big picture cards for *Start with a blank canvas* and *Import a Compose file* sit between the URL field and "Your repositories", so the least-used paths get the most visual weight. On the "We looked at next-learn" step, the name, databases and deploy are all on one page, which works.
- **Templates (`templates`, `template-detail`)** has a good gallery, and the detail sheet's three-column "What you get" diagram plus the resource tiles are better than anything I found on Mobbin. But stack templates (Laravel, Django, Rails, Next.js + Postgres) need "your repository" and the Deploy button stays disabled until you pick one. Someone who only wants to try the stack is stuck there. The primary button is also at the very bottom of a long sheet.
- **Compose import (`compose-import`)**: "Line by line" explains each part of the file well. The project name defaults to the first service's name ("web"), which is a poor name for a project.
- **Add database (`add-database`)** shows engine tiles plus version and name. "Connect it to…" exists in code (`features/add-service/simple-forms.tsx:235`) but only appears when there are services to connect, so the screenshot doesn't show it. This is fine and needs no change.

---

## 2. Patterns observed

### Laravel Cloud (primary reference)

1. **First run is one sentence and one button.** "Let's ship your first application" with three provider marks fanned out (GitHub in front, GitLab and Bitbucket behind), one "Connect to GitHub" button and a quiet "Use another option" link. No checklist, no tiles. The whole page serves one first action. [Connecting to GitHub flow](https://mobbin.com/flows/d52335b5-25cb-4573-a86a-55f316bb0d70?tab=screens), [screen](https://mobbin.com/screens/b54903e7-e288-4eb2-8e47-12c2e1423976)
2. **Create = two tabs, "Import a repository" and "Use a template". Nothing else.** The repo list is sorted by last push ("5 days ago", "12 days ago") and ends with a "10 repositories fetched" confirmation. There's no blank or empty option on this screen. [Import application from a repository](https://mobbin.com/flows/13a01db0-de6f-40cb-b022-fcf1a2b103a9?tab=screens), [repo list](https://mobbin.com/screens/bfc67461-97de-4b91-91cf-642f75c86282)
3. **Confirm step: the source sits in a strip at the top ("Importing samleemobbin-dot/landingpage · 13 days ago"), then the name, then one primary button, with "Select another repository" underneath.** Problems show up inline *before* creating ("Unsupported framework", with the button disabled), not after a failed deploy. [confirm](https://mobbin.com/screens/27455234-17ff-45f7-8d5e-85a6d6aece03), [unsupported](https://mobbin.com/screens/53e13035-15eb-4706-91fe-c63057b4b27b)
4. **A template creates a repository for you.** The "Use a template" tab shows "Laravel, Cloning from laravel/laravel", then Git provider account / Repository name, Private/Public, and "Create application". You never need your own code to start. [Onboarding flow (template tab inside)](https://mobbin.com/flows/93a93e48-c570-4843-8780-d78b2da20a42?tab=screens)
5. **Org home = "Recently deployed" card + "Latest deployments" list.** The card shows app · branch, repo, URL and "main Deployed 10 hours ago", so the most recent thing comes first. The Applications tab groups environments under each app, with a `⋯` menu per app. [Home](https://mobbin.com/flows/a95840a2-b400-4294-9109-343676796c10?tab=screens), [Applications](https://mobbin.com/flows/9b043956-0b36-4099-a80d-c0252b82be3b?tab=screens)
6. **The first deploy is a step list, not a log.** Build logs: Preparing build environment / Running build commands / Pushing application. Deployment logs: Preparing deploy environment / Running deploy commands / Updating environment stack / Routing traffic. Each step shows Pending → Finished with a duration. A dark toast says "Deployment 2d300ac is building on laravel · main [Cancel]". [Deploying an application](https://mobbin.com/flows/7a7a20dc-bcc4-4013-a9d9-f06531e61beb?tab=screens), [building](https://mobbin.com/screens/f9a999ee-f498-4a02-b17f-be383d866950), [deployed](https://mobbin.com/screens/dd29535f-34aa-4279-82fc-76e11059f596)
7. **Adding a database uses presets, not raw knobs.** "New database cluster" offers radio cards: Dev (512 MiB · 5 GB · 1 day of backups), Prod (2 GiB · 20 GB · 7 days), Scale, plus "Custom configuration ›". The node then shows up on the environment canvas. [Creating a database](https://mobbin.com/flows/5b3d9862-e6ec-4817-aff8-c9f93a4b5916?tab=screens)
8. **Empty states are centered: a quiet illustration, one line, one button** ("No applications yet / Get started and create your first application / + New application"). [Deleting an application (ends on the empty state)](https://mobbin.com/flows/399dd16e-4e7d-4af6-a422-d4ab9d0d1b7b?tab=screens)

### Corroboration from other products

**P1. Project lists sort by recent activity and offer a denser view and a per-card menu.**
- Vercel: search, filter, grid/list toggle, and a `⋯` per card (Add Favorite, View Logs, Manage Domains, Settings, View Git Repository) with a "Your Favorites" section. [1](https://mobbin.com/screens/ee9c9ca1-d199-4d29-8532-eec6ed7b5ca0), [2](https://mobbin.com/screens/e2a9b3a7-e0e6-485f-925a-2de04392aeb0)
- Railway: "Sort By: Recent Activity", grid/list toggle, and a card with a mini service-icon graph plus "production · 2/2 services online", the same idea as our cards but shorter. [screen](https://mobbin.com/screens/07669dc9-b62b-4bbe-a27e-c4a1063c9cd0)
- Supabase: Status filter, "Sorted by name" control, grid/list toggle, `⋮` per card. [screen](https://mobbin.com/screens/f36deffe-a1ad-4d9b-b6a3-4917666dc67c)
- Why it works: the project you care about is almost always the one you touched last. Laravel Cloud's "Recently deployed" says the same thing.

**P2. Blank or empty creation is a footnote, not a peer of "import".**
- Vercel: "Import Git Repository" and "Clone Template" are the two halves of the page, and "Create Empty Project" is a thin footer row. [screen](https://mobbin.com/screens/96989b3e-1d55-4eb6-948f-6f700ff9393f)
- Railway: in the New project menu, "Empty Project" comes last after GitHub Repository, Database, Template and Docker Image. [screen](https://mobbin.com/screens/c88dd047-3761-4b2e-903b-ac261429127e)
- Laravel Cloud: no blank option at all (pattern 2).

**P3. The confirm step shows "Importing from <repo>" in a strip at the top, then a short form.** Vercel [screen](https://mobbin.com/screens/e80f252c-32c8-41f2-acb4-8c620c56f23f), v0 [screen](https://mobbin.com/screens/79a55502-3f31-43fd-98f9-888dcec4f702), Laravel Cloud (pattern 3). Our "We looked at next-learn" header already does this.

**P4. A template can start without your own code.**
- Vercel: the template creates a private repo in your Git scope ("Git Scope / Private Repository Name / Create"), with "Import a different Git Repository →" underneath. [screen](https://mobbin.com/screens/8e644dce-1d43-4df6-a9b8-7c9680a7295d)
- Laravel Cloud: pattern 4.
- Railway "Deploy High-Cool": one row per service with "Ready to be deployed ✓" or a "Configure" button, then one Deploy. You can see which service still needs input. [flow](https://mobbin.com/flows/5973e16c-f6df-4e4a-bf98-839da06485e4?tab=screens)

**P5. A "first success" screen with the live URL, a preview and next steps.**
- Vercel: "Congratulations! You just deployed a new project", a large screenshot of the live site, and "Next Steps" (Instant Previews…). [screen](https://mobbin.com/screens/b4c92980-6b3a-47bd-88ef-6c4b39bc5ca9)
- Replit: "Congratulations! Your project is live." with the URL and Copy link. [screen](https://mobbin.com/screens/b9011f24-7710-4c2e-83c8-5a36b631e366)
- Stripe: "Your climate page is live", the URL with copy, three tips, and a preview of the page. [screen](https://mobbin.com/screens/f2c70a69-f41d-4e57-a918-541e7fe6aadb)
- Mintlify: the home page shows a site thumbnail in a "Generating" state next to the domain and "Add custom domain" while the first build runs. [screen](https://mobbin.com/screens/47d9cf50-1cf8-4326-bf39-a02977ee7d19)
- Why it works: the user came to put something online. Naming that moment and handing over the URL marks the end of setup.

**P6. Setup checklists finish on a *result*, detect progress by themselves, and get out of the way.**
- Graphite: "As you complete them, we'll detect and move you to the next step automatically", with "Complete setup later". [screen](https://mobbin.com/screens/d5e25ee0-a30a-4bbe-bc78-e2fb213e811d)
- Outseta: "8/8 completed", then "Remove 'Setup' from the sidebar". [screen](https://mobbin.com/screens/b1bcf27d-e787-4a4d-81f7-a1ec5515f5fe)
- Stripe: a "Setup guide" that floats over any page and can be minimised. [screen](https://mobbin.com/screens/f32c5b1d-abd7-4dcd-ae74-85acc29c2250)
- Vercel: a "Production Checklist 4/6" inside the *project* overview, where each item has its own action and a Skip. [screen](https://mobbin.com/screens/dc17d998-fce6-46e8-9149-acb19053d7ed)

**P7. Connecting a database to an app is part of creating it.** Vercel "Connect Project": pick a project, tick environments, set an env-var prefix. [flow](https://mobbin.com/flows/12181bd4-3113-410e-a88d-60e8faaa68c3?tab=screens). We already do this ("Connect it to…"), so no change is needed.

---

## 3. Recommendations (ranked by impact / effort)

### PRJ-1: "Your first app is live" as a real moment (onboarding step 4 + first-deploy card)
- **Problem:** Step 4 completes when a project row exists. The user is told "Your first app is set up" while the build may still be running or may have failed, and the done state shows "26 projects" instead of the URL. After "Create and deploy" the user lands on the canvas, gets a toast when it's live, and that's all. Laravel Cloud, Vercel, Replit and Stripe all give "it's live" its own screen with the URL.
- **Change:**
  1. `OnboardingChecklist`: step `project` is done when any production service in the team has a successful deployment (a `Running` instance with an active deployment). While the first deploy runs, the step shows a third state: `line` = "web is building · 41s" (the current step label + elapsed time, live via `useLiveProps`), with a link "Watch it" to the deployment.
  2. In the done state, `projectView` shows the live URL as the line (`web-production.acme.localhost ↗`) and the title "Your first app is live" (not "set up").
  3. New `features/projects/first-live-card.tsx`, shown once per team on the project canvas when that project's first deployment succeeds (dismissible, remembered server-side on the onboarding progress). It shows the favicon or framework logo, the URL with Copy and Open, and three next-step rows that link to existing places: "Use your own domain" (Domains), "Deploy on every push" (Connect GitHub, only if the source isn't connected), "Keep a backup off this server" (backups). No confetti, no gradient, monochrome with the ok status icon.
  4. While the first deploy runs, the same card slot shows Laravel Cloud-style step rows (Build → Start → Health check → Route, each Pending or Done with a duration) using the existing step pills from the deployment panel.
- **Files:** `dashboard/app/Queries/OnboardingChecklist.php`, `dashboard/resources/js/pages/onboarding.tsx` (`projectView`), `dashboard/resources/js/pages/projects/canvas.tsx`, new `dashboard/resources/js/features/projects/first-live-card.tsx`, `dashboard/resources/js/features/deployments/deploy-toasts.tsx` (no duplicate toast while the card is visible), plus a Pest test for the checklist rule.
- **Evidence:** [Laravel Cloud deploying](https://mobbin.com/flows/7a7a20dc-bcc4-4013-a9d9-f06531e61beb?tab=screens), [Vercel congratulations](https://mobbin.com/screens/b4c92980-6b3a-47bd-88ef-6c4b39bc5ca9), [Replit live](https://mobbin.com/screens/b9011f24-7710-4c2e-83c8-5a36b631e366)
- **Effort:** M
- **Docs:** `docs/design/flows.md` §1 step 4 ("done when the first app is live; the step shows the build while it runs") and §5 (first-live card); `docs/08-dashboard-ux.md` onboarding section.

### PRJ-2: Projects sorted by recent activity, with a sort control and a compact list view
- **Problem:** `ProjectSummaries::for()` orders by name. With 26 projects the active ones get lost among test projects, and the 280 px cards fit about 6 per screen. Vercel, Railway and Supabase all offer sort plus grid/list. Laravel Cloud leads with "Recently deployed".
- **Change:** Default to "Recent activity" (the latest deployment or service change across the project's environments, then name). Add a small `Select` next to the search field: Recent activity / Name. Add a grid/list toggle (two icon buttons with `aria-pressed`). The list view is one row per project: icon, name, primary domain (mono), status icon + word, last deploy age, 14-day deploy bars. It reuses the card's data and stays rows-with-meaning, not a data-dump table. Remember both choices per viewer in `localStorage` (wrapped in try/catch). On a phone, list is the default below `sm`.
- **Files:** `dashboard/app/Queries/ProjectSummaries.php` (expose `lastActivityAt`, sort), `dashboard/app/Data/Projects/ProjectSummaryData.php`, `dashboard/resources/js/pages/projects/index.tsx` (header controls, `ProjectRow` component), `make gen-types`.
- **Evidence:** [Railway Sort By: Recent Activity](https://mobbin.com/screens/07669dc9-b62b-4bbe-a27e-c4a1063c9cd0), [Vercel grid/list + ⋯](https://mobbin.com/screens/ee9c9ca1-d199-4d29-8532-eec6ed7b5ca0), [Supabase sort + view](https://mobbin.com/screens/f36deffe-a1ad-4d9b-b6a3-4917666dc67c), [Laravel Cloud home](https://mobbin.com/flows/a95840a2-b400-4294-9109-343676796c10?tab=screens)
- **Effort:** M
- **Docs:** `docs/08-dashboard-ux.md` §13 (projects home: default order and list view).

### PRJ-3: Stack templates can deploy without your own repository ("Start from the starter")
- **Problem:** Laravel, Django, Rails and Next.js stacks require "your repository" and keep Deploy disabled until one is picked. A user trying launchroom with a stack template has no repo to point at and stops there. Vercel and Laravel Cloud both create the repo for you, and Railway shows "Configure" exactly where input is missing.
- **Change, in two steps:**
  - (a, S/M) Each stack template in the catalog gets an optional `starter` (a public repo URL, e.g. the framework's official skeleton). In the sheet's Repository field, add a first choice, a tile labelled "Start from the Laravel starter", which deploys from that public URL (public-URL deploys already exist). Then a "Move to your own repository" row in the service's Source settings. Copy: "Deploys the official starter. Point it at your own repository any time."
  - (b, L, later) With a connected GitHub/Gitea app that has the permission, offer "Create a repository from the starter" (account, name, Private/Public), as Laravel Cloud and Vercel do. This needs repo-creation scope on the app, so it needs a security review against docs/09 and docs/19.
  - Also in the sheet: give each node in "What you get" that still needs input a dashed outline and the line "Needs a repository" (Railway's pattern). Make the action row (`Create without deploying` / `Deploy`) sticky at the bottom of the sheet so it's visible before scrolling.
- **Files:** `deploy/`/catalog template definitions (the `starter` field, CI validation in docs/17 §3.3), `dashboard/app/Data/Templates/*`, `dashboard/resources/js/features/templates/template-setup.tsx`, `dashboard/resources/js/features/templates/repository-field.tsx`, the sheet layout in `dashboard/resources/js/features/templates/template-gallery.tsx`.
- **Evidence:** [Vercel clone template → new private repo](https://mobbin.com/screens/8e644dce-1d43-4df6-a9b8-7c9680a7295d), [Laravel Cloud onboarding (Use a template tab)](https://mobbin.com/flows/93a93e48-c570-4843-8780-d78b2da20a42?tab=screens), [Railway Deploy template (Ready / Configure per service)](https://mobbin.com/flows/5973e16c-f6df-4e4a-bf98-839da06485e4?tab=screens)
- **Effort:** M for (a) plus the sheet changes. L for (b).
- **Docs:** `docs/17-templates-and-compose.md` §3.1 (the `starter` field) and §4 (UI). For (b), `docs/19-git-providers.md` (permissions) and `docs/README.md` (a new decision).

### PRJ-4: New project page puts repositories and templates first, blank and compose last
- **Problem:** On the new project page, "Start with a blank canvas" and "Import a Compose file" are two large picture cards placed right under the URL field, above the repository list and the templates. The rarer paths get the visual weight, and on a phone (`new-390`) the repo list starts below the fold.
- **Change:** Order the page as URL field → Your repositories | Start from a template (unchanged two-column block) → one thin footer row: "Other ways to start: Blank canvas · Compose file" as two text buttons with small icons, matching Vercel's "Create Empty Project" row. Keep the picture cards only in the first-run state (no repositories and no git provider), where they actually help. The tabs on `templates`/`new-compose` stay as they are.
- **Files:** `dashboard/resources/js/features/new-project/start-cards.tsx`, `dashboard/resources/js/features/new-project/new-project-start.tsx`.
- **Evidence:** [Vercel new project](https://mobbin.com/screens/96989b3e-1d55-4eb6-948f-6f700ff9393f), [Railway new project menu](https://mobbin.com/screens/c88dd047-3761-4b2e-903b-ac261429127e), [Laravel Cloud import / template tabs](https://mobbin.com/screens/bfc67461-97de-4b91-91cf-642f75c86282)
- **Effort:** S
- **Docs:** `docs/design/flows.md` §3 (page order).

### PRJ-5: Per-card `⋯` menu on project cards
- **Problem:** Opening a site, checking logs or going to settings always means opening the project first. Vercel, Supabase and Laravel Cloud all put a `⋯` on each project.
- **Change:** Add a `DropdownMenu` button in the card header, `relative z-10` like the existing "Start empty instead" button so the stretched link still works. Items: Open site ↗ (when there's a primary domain), Deployments, Logs, Settings, plus a separator and Copy domain. Use only links to existing routes and no destructive items. The button needs an `aria-label` of "Actions for {project}" and stays visible on touch, revealed on hover/focus on desktop.
- **Files:** `dashboard/resources/js/pages/projects/index.tsx` (`Card`).
- **Evidence:** [Vercel ⋯ menu](https://mobbin.com/screens/e2a9b3a7-e0e6-485f-925a-2de04392aeb0), [Supabase ⋮](https://mobbin.com/screens/f36deffe-a1ad-4d9b-b6a3-4917666dc67c), [Laravel Cloud Applications ⋯](https://mobbin.com/flows/9b043956-0b36-4099-a80d-c0252b82be3b?tab=screens)
- **Effort:** S
- **Docs:** none (or one line in docs/08 §13).

### PRJ-6: Compose import suggests a real project name
- **Problem:** An unnamed compose file uses the first service as the project name, so the Umami example becomes a project called "web" (`ComposeImportController.php:73`).
- **Change:** If the compose file has no top-level `name`, use (1) the repository name in "From a repository" mode, otherwise (2) the last path segment of the first public service's image without its tag (`ghcr.io/umami-software/umami:3.4.0` → `umami`), and only then (3) the first service name. Run the result through the existing name grammar.
- **Files:** `dashboard/app/Http/Controllers/Blueprints/ComposeImportController.php` (or better, a small `ComposeTranslator::suggestedName()` in Support), plus a Pest test.
- **Evidence:** [Laravel Cloud confirm step pre-fills the name from the source](https://mobbin.com/screens/27455234-17ff-45f7-8d5e-85a6d6aece03), [Vercel project name from repo](https://mobbin.com/screens/e80f252c-32c8-41f2-acb4-8c620c56f23f)
- **Effort:** S
- **Docs:** `docs/17-templates-and-compose.md` §2.1 (one sentence).

### PRJ-7: Get started finishes cleanly, with a "Remove from sidebar" option and an "all set" page that points somewhere
- **Problem:** The complete state is a 5/5 ring and five rows, and "Everything is in place. Deploy away." comes close to the filler the copy rules warn about. Skipped steps (GitHub, own domain) look exactly like finished ones apart from the dash.
- **Change:** When `progress.complete`, collapse the done rows into one line ("Server, first app and backups are set up") and keep the skipped steps as normal rows with their action ("Connect GitHub to deploy on every push", "Use your own domain"), because those are what's left to do. Drop the subtitle. Rename the top-bar button "Hide for now" to "Remove from sidebar" in this state, as Outseta does. Keep the sidebar `Get started 4/5` behaviour as specified.
- **Files:** `dashboard/resources/js/pages/onboarding.tsx`.
- **Evidence:** [Outseta setup guide complete](https://mobbin.com/screens/b1bcf27d-e787-4a4d-81f7-a1ec5515f5fe), [Graphite auto-detect + later](https://mobbin.com/screens/d5e25ee0-a30a-4bbe-bc78-e2fb213e811d), [Vercel production checklist with Skip](https://mobbin.com/screens/dc17d998-fce6-46e8-9149-acb19053d7ed)
- **Effort:** S
- **Docs:** `docs/design/flows.md` §1 (complete state).

### PRJ-8: Empty project cards invite the next step
- **Problem:** Projects with no services (`empty-demo`, `p5-clickhouse`) spend 150 px of picture area on grey "No services yet" text, and the card's status reads "No services" twice.
- **Change:** For an empty project, the picture area shows a dashed "+" node, like Laravel Cloud's inline "Add resource" node on its canvas. The status line says "Empty". The meta line becomes a link, "Add a service", that opens the canvas with the Add sheet open (`?add=1`, which the canvas already handles if supported; otherwise add it).
- **Files:** `dashboard/resources/js/pages/projects/index.tsx` (`Card`), `dashboard/resources/js/pages/projects/canvas.tsx` (query param to open the Add sheet).
- **Evidence:** [Laravel Cloud environment with "Add resource" node](https://mobbin.com/screens/63204509-6daa-4fb9-ac50-c4f4bb571442), [Laravel Cloud empty state](https://mobbin.com/flows/399dd16e-4e7d-4af6-a422-d4ab9d0d1b7b?tab=screens), [Railway project card graph](https://mobbin.com/screens/07669dc9-b62b-4bbe-a27e-c4a1063c9cd0)
- **Effort:** S
- **Docs:** none.

### PRJ-9 (smaller, optional): Database presets in Add database
- **Problem:** Add database asks for engine, version and name, with nothing about how much memory or backup it gets. Laravel Cloud's Dev / Prod / Scale presets, each with backup days, show those trade-offs right on the add screen.
- **Change:** Under the version field, add a compact two-option radio row, "Small (256 MB)" / "Standard (1 GB)", each with a line about backups ("Daily backup, kept 7 days" once backups are configured, otherwise "No backups yet · Set up"). Default to Standard if the server has more than 4 GB free. These map to the existing scaling limits. Leave out "Custom" because Settings already covers it.
- **Files:** `dashboard/resources/js/features/add-service/simple-forms.tsx`, `dashboard/app/Data/Services/*` (add input field), the Action that creates database services.
- **Evidence:** [Laravel Cloud new database cluster](https://mobbin.com/flows/5b3d9862-e6ec-4817-aff8-c9f93a4b5916?tab=screens)
- **Effort:** M
- **Docs:** `docs/design/flows.md` §4.

### Keep as is (already matches or beats the references)
- Setup two-pane welcome (`setup`).
- First-run new project with provider marks and "Connect GitHub to deploy on every push". It's the same move as Laravel Cloud's "Let's ship your first application".
- "Needs you" + "Recent deployments" above the project grid, which matches Laravel Cloud's "Recently deployed / Latest deployments" home.
- Template "What you get" diagram and the compose "Line by line" explainer. None of the products I reviewed show a template or compose file this transparently.
- Add database "Connect it to…" chips (P7).
