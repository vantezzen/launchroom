# UX round: Deployments, previews and promotion (DPL-)

Screens reviewed: `deployment`, `deployment-failed`, `config-problems`, `previews`, `promote`, `service-panel` (Oct 28 set in `dev/screenshots/site-retake/raw/`, because no fresh `ux-round/before` shots existed yet for this area). There is no `x-deployments` screenshot anywhere, so I judged the project deployments list from source (`pages/projects/deployments/index.tsx`, `features/deployments/commit-group.tsx`). Where a screenshot and the current code disagree (for example the header button is now "Runtime logs", not "Logs"), I went by the code.

## 0. What Laravel Cloud does here (primary reference)

Laravel Cloud's deployment UI is deliberately small. Five points stand out:

1. **The deployment page is a step list and nothing else.** "Deployment details · 2d300ac" sits on one header line with a status icon and word, the commit, the age and the total duration. Below it is one card with two phases, **Build logs** and **Deployment logs**. Each phase has a status icon and a total duration, and each sub-step row ("Preparing build environment", "Running build commands", "Routing traffic to new deployment") shows Pending/Finished/Skipped, its duration and a chevron that opens its log. There is no separate overview panel that repeats the steps.
   [Deploying an application flow](https://mobbin.com/flows/7a7a20dc-bcc4-4013-a9d9-f06531e61beb?tab=screens), [building](https://mobbin.com/screens/f9a999ee-f498-4a02-b17f-be383d866950), [deployed](https://mobbin.com/screens/dd29535f-34aa-4279-82fc-76e11059f596)
2. **One action set that follows the state.** The header has **Cancel** (outline) while the build runs and **Redeploy** (primary) once it's done, with **Visit** next to it in both states. An up/down control steps to the neighbouring deployment.
3. **A live toast with an action.** "Deployment 2d300ac is building on laravel · main" stays at the bottom with a **Cancel** button while you move around the app (same screens as above).
4. **The deployments list is one row per deployment.** Each row has a status glyph, the sha, the commit message and "Deployed from `main` 8 hours ago", with **Deploy** and **Visit** at the top right. The environment overview ends with the same "Latest deployments" rows.
   [Application detail flow](https://mobbin.com/flows/208f0f71-fb17-49ed-9ee0-bf49640641e2?tab=screens), [deployments tab](https://mobbin.com/screens/18ef5450-d098-4785-9c12-cb014e1845cc)
5. **Preview environments are written as a rule sentence.** "When a new pull request is opened replicate `staging`", followed by two checks, "Auto deploy on trigger" and "Auto destroy on merge". You see the policy at a glance before you get to any settings.
   [Settings (application) flow](https://mobbin.com/flows/fdfb1805-264b-4ed4-83eb-b1a5b13fa1ca?tab=screens)

Vercel, Railway, PlanetScale and others back these up below. Where Laravel Cloud has no counterpart (promote, explained failures), I lead with the pattern most products share.

## 1. Current state

- **Works: the explained-failure card is our strongest screen** (`deployment-failed`). "Build failed: pnpm-lock.yaml is out of date" leads, then "Your site is still serving 3b7d5e1", the cause, the fix, **Jump to line 6** and a copyable `pnpm install`. The error line is also marked in the log. No reference product goes this far. Railway's "Diagnosis" block is the closest. The config-as-code card (`config-problems`) with code frames, carets and "Did you mean "replicas"?" is also better than anything I found.
- **Doesn't work: the steps appear twice.** On `deployment` and `deployment-failed` the overview card's left panel lists Plan, Build, Roll out, Health check and Switch traffic with bars and durations. The "Steps" section right below lists the same five rows again. On a failed deploy, the server illustration and the red bar push the explanation below the fold on a 1440×900 screen. Laravel Cloud and Vercel each show the step list once.
- **Doesn't work: config failures show empty scaffolding.** On `config-problems`, nothing ran, but the page still shows "Failed before it started", four "Skipped" bars, "Duration 0s", "Built on No build", and then a "Steps" card with the same four "Skipped" rows. The useful content (the three problems) sits between two blocks of nothing.
- **Doesn't work: the primary action ignores the failure.** On `deployment-failed` and `config-problems` the filled button is **Redeploy** (`primaryIsRedeploy` in `show.tsx`). A redeploy of the same commit fails the same way when the cause is a lockfile or `launchroom.json` error. The failure cards also show a raw `build_error` / `config_error` chip in the corner, which is internal vocabulary.
- **Mixed: previews** (`previews`). The card grid is calm, but a preview without a URL gets a 200px empty media area with a pull-request glyph. Expired and closed previews take the same space as live ones. The card links to neither the pull request nor its latest deployment. The header says "1 pull request | 0 live" but not what the preview rule is.
- **Mixed: promote** (`promote`). From→To cards, per-service change rows with checkboxes, and a secret that must be filled before **Promote and deploy** are all good. But the summary column is prose ("Deploying restarts api and web in production. Enter the production value for…"). There's no count summary and no view of which commits differ between the two environments. The top bar's staged pill ("3 changes · Review & deploy", which belongs to staging) also sits next to "3 changes to production", so two different "3 changes" are on screen at once.
- **Inconsistency: the service panel and the deployment page disagree** (`service-panel`). The live deployment's step chips include "Release 3s", but the deployment page leaves Release out. The panel also shows "Live" on a deployment while the node and the header say "Not running".

## 2. Patterns observed

**P1. The step list is the deployment page, and it appears once.** Laravel Cloud has two phases with sub-steps ([deployed](https://mobbin.com/screens/dd29535f-34aa-4279-82fc-76e11059f596)). Vercel has collapsible sections, "Build Logs / Deployment Summary / Deployment Checks / Assigning Custom Domains", each with a status glyph and duration on the right ([detail](https://mobbin.com/screens/de80242a-9d1f-4f88-af9c-a2e4cad3e585)). Railway puts "Initialization → Deploy › Create container → Post-deploy" right inside the details tab ([Railway failed](https://mobbin.com/screens/a772bb45-e352-4362-bcb6-48ba146bd3e9)). All three show the steps once. The summary on top holds facts (status, duration, source, domains), not a second copy of the steps.

**P2. The failure is shown in place, with the failing line quoted.** Vercel's "Build Failed" box quotes the last error (`Command "astro build" exited with 127`), and the log below opens on the red lines ([Vercel failed](https://mobbin.com/screens/ff81f1e9-25b1-46f9-8448-31fa40a77e4b), [Vercel log](https://mobbin.com/screens/50a79ff4-37c0-4a9b-8df3-29599652a9fb)). Railway marks the failed step red, prints the error under it and adds "Diagnosis › Config Error" with a **Fix start command** button ([Railway](https://mobbin.com/screens/a772bb45-e352-4362-bcb6-48ba146bd3e9)). Hex prints the error with **Fix with Magic / Hide error details / Copy error** ([Hex](https://mobbin.com/screens/0d6f2ae3-b59e-4c2c-ab7a-db6af5e5e2da)). The common thread is that the user reads the actual error text next to the explanation without jumping anywhere.

**P3. Log filters by severity, with counts.** Vercel's build log has "All Logs (72) / Errors (19) / Warnings (12)" and collapses unrelated lines into "Expand 52 Lines" ([Vercel error detail flow](https://mobbin.com/flows/e6e0c0e4-76b4-4236-9417-8edf17b41fe6?tab=screens)). The header of the earlier screen also shows ⊗ 1 and ⚠ 1 counts ([Vercel](https://mobbin.com/screens/ff81f1e9-25b1-46f9-8448-31fa40a77e4b)).

**P4. The current deployment is pinned and the history is secondary.** Railway shows the ACTIVE deployment as its own highlighted card, then a collapsible "History" with a **Hide skipped** toggle ([Railway view deploy logs](https://mobbin.com/flows/6caa5617-cb4a-44b2-9af6-bf9a6db34444?tab=screens)). Vercel marks rows "Current" or "Rolled Back" ([list](https://mobbin.com/screens/e9576405-bcef-419a-922a-8fb84b044a54)). After a rollback, Vercel adds a banner to the list and shows "Redeploy of qdwezpe70" as the source of later rows ([rolled-back list](https://mobbin.com/flows/b7c3778f-ba2a-473f-8a2f-8a3ee46989c7?tab=screens)).

**P5. Rollback shows current → target.** Vercel's Instant Rollback dialog shows "Rolling back [Current deployment]" above "To the following deployment" (radio list, "Previous" badge) and asks for an optional reason ([dialog](https://mobbin.com/screens/f10497e6-9a53-4d09-b1fe-e72cebede2a6)). The reason then appears on the production card next to **Undo Rollback** ([after](https://mobbin.com/screens/d8e63884-5d43-4337-bf31-5667bc4ba5db)).

**P6. Change review leads with counts and an "Open / Closed" split.** PlanetScale's deploy request shows three tiles, "2 Created / 0 Altered / 0 Dropped tables" (a picture, then a number, then the word), plus a deployability check before the action ([deploy request flow](https://mobbin.com/flows/fecee68e-38fe-4aa6-aa09-e45e882fff9f?tab=screens)). Its list splits into "Open 1 / Closed 2" ([list](https://mobbin.com/screens/be0ec4c5-4a49-4f4a-8a29-179143a514c5)). Devin's PR view lists the commits in the change with "Current" on the head and "4 files +395 −96" in the header ([Devin](https://mobbin.com/screens/1d654c5f-68dd-42f4-abcd-b53f8bac3214)).

**P7. Previews are a list of PRs, live ones first, with links out.** Mintlify's previews table opens a failed row in place with the reason, commit details, files changed, and **Redeploy / Delete** ([Mintlify](https://mobbin.com/screens/9511b9c8-f3ab-4556-a398-6eb0bef0a2e4)). Graphite groups PRs into sections with counts and puts empty sections last ([Graphite](https://mobbin.com/screens/3e2205a5-5840-410b-abb3-38ea45add32a)). Laravel Cloud and Railway describe the rule in one sentence ("When a new pull request is opened replicate staging"; Railway: "created when a pull request is opened and cleaned up when the PR is closed") ([LC](https://mobbin.com/flows/fdfb1805-264b-4ed4-83eb-b1a5b13fa1ca?tab=screens), [Railway PR envs](https://mobbin.com/flows/31d2d067-bb52-4a8a-8bf2-ecb27615ab46?tab=screens)).

**P8. The running deployment follows you.** Laravel Cloud's bottom toast reads "Deployment 2d300ac is building on laravel · main" with **Cancel** ([building](https://mobbin.com/screens/f9a999ee-f498-4a02-b17f-be383d866950)). We only toast the outcome.

## 3. Recommendations (ranked by impact ÷ effort)

### DPL-1. Show the steps once: drop the step bars from the overview card
- **Problem:** The overview's left panel and the "Steps" section list the same steps (`deployment`, `deployment-failed`). On failures, the explanation ends up below the fold.
- **Change:** In `OverviewCard`, replace the stage panel's per-step list with one thin segmented timeline bar. Segment widths are proportional to step durations, and the failed segment is red and labelled with its name and an ✕ glyph, so the colour has a non-colour cue. Keep the closing line ("Took 1m 51s" / "Failed after 42s, still serving 3b7d5e1"). The server illustration stays only for in-progress deployments, where it shows motion. Finished deployments get the facts grid at full width. The Steps section stays the only per-step list, as on Laravel Cloud and Vercel.
- **Files:** `features/deployments/overview-card.tsx`, `features/deployments/deployment-stage.tsx`, `pages/projects/deployments/show.tsx`.
- **Evidence:** [LC deployed](https://mobbin.com/screens/dd29535f-34aa-4279-82fc-76e11059f596), [Vercel detail](https://mobbin.com/screens/de80242a-9d1f-4f88-af9c-a2e4cad3e585), [Railway failed](https://mobbin.com/screens/a772bb45-e352-4362-bcb6-48ba146bd3e9)
- **Effort:** M. **Doc:** `docs/design/direction.md` (deployment detail section, §9.x) and `docs/08-dashboard-ux.md` deployment page: "the overview carries a single timeline bar; steps are listed once."

### DPL-2. On a failed deployment, put the explanation first and quote the error line in it
- **Problem:** The failure card comes after the big overview, and it says "Jump to line 6" without showing line 6. Users have to scroll or jump to read the actual error.
- **Change:** When `deployment.status === 'failed'`, render `FailureCard` / `ConfigFailureCard` directly under the header, above the overview. Inside `FailureCard`, show the matched error line (`errorLine`) plus up to two lines of context as a recessed mono block with line numbers, styled like the config code frame. Keep **Jump to line 6** as the link into the full log. Remove the duplicate "Your site is still serving…" sentence from either the overview footer or the card, and keep it in the card.
- **Files:** `pages/projects/deployments/show.tsx`, `features/deployments/failure-card.tsx` (it needs the error line text, which comes from the same stream `errorLine` is computed from), `features/deployments/overview-card.tsx`.
- **Evidence:** [Vercel failed](https://mobbin.com/screens/ff81f1e9-25b1-46f9-8448-31fa40a77e4b), [Railway diagnosis](https://mobbin.com/screens/a772bb45-e352-4362-bcb6-48ba146bd3e9), [Hex error](https://mobbin.com/screens/0d6f2ae3-b59e-4c2c-ab7a-db6af5e5e2da)
- **Effort:** S–M. **Doc:** `docs/design/flows.md` §6 (explained failure): card first on failed deployments, with the error line quoted.

### DPL-3. Choose the primary action by failure class; drop Redeploy where it can't help
- **Problem:** On `deployment-failed` (lockfile) and `config-problems`, the filled button is **Redeploy**. Rebuilding the same commit fails identically. The card's `build_error` / `config_error` chip is internal vocabulary.
- **Change:** `primaryIsRedeploy` becomes true only for classes that can be transient: `build_timeout`, `health_timeout`, `runtime_error`, infra/unknown. For `plan_error`, `build_error` without a fix action, and config errors, Redeploy becomes an outline button. Config errors hide it entirely, since only a push re-reads `launchroom.json`, and we add **Open launchroom.json on GitHub** as the primary instead. Remove the class chip from `FailureCard` and `ConfigFailureCard`, and move the class into a `title` tooltip or a copy-details action for support.
- **Files:** `pages/projects/deployments/show.tsx`, `features/deployments/failure-card.tsx`, `features/config-as-code/config-failure-card.tsx`.
- **Evidence:** [LC Cancel→Redeploy by state](https://mobbin.com/flows/7a7a20dc-bcc4-4013-a9d9-f06531e61beb?tab=screens), [Railway "Fix start command" as primary](https://mobbin.com/screens/a772bb45-e352-4362-bcb6-48ba146bd3e9)
- **Effort:** S. **Doc:** `docs/08-dashboard-ux.md` (deployment actions): "one primary action, chosen by what will actually fix it."

### DPL-4. Config-error deployments: hide the empty scaffolding
- **Problem:** `config-problems` shows four "Skipped" bars, "Duration 0s", "Built on No build", and a Steps card full of "Skipped" rows around the actual content.
- **Change:** When `detail.configErrors` is set, render a compact overview: status "Not deployed", source, "Would have served" and no stage panel. Also skip the `Section id="steps"` entirely, or collapse it to a single line, "Nothing ran. launchroom stops at the config check." Hide the "Build history" related card for this case too ("Durations show here after a few builds" is noise here).
- **Files:** `pages/projects/deployments/show.tsx`, `features/deployments/overview-card.tsx`, `features/deployments/related-cards.tsx`.
- **Evidence:** [Railway: failed step plus diagnosis, nothing else](https://mobbin.com/screens/a772bb45-e352-4362-bcb6-48ba146bd3e9), [LC "Skipped" only where something was planned](https://mobbin.com/screens/dd29535f-34aa-4279-82fc-76e11059f596)
- **Effort:** S. **Doc:** `docs/design/flows.md` (config-as-code failure).

### DPL-5. Severity filter chips with counts in the build log
- **Problem:** The log toolbar has search, Expand all, Wrap, Timestamps and Follow, but no way to see only errors. The red count ("1 error") sits only on the step header.
- **Change:** Add a three-way segmented control to the `LogViewer` toolbar: **All 71 / Errors 1 / Warnings 0**. The counts come from the existing `errors.lines` set plus a warning matcher. "Errors" collapses non-matching lines into "Show 52 lines" separators. Show the chip only when count > 0. Errors use the red token plus ✕, warnings the amber token plus ⚠.
- **Files:** `features/logs/log-viewer.tsx` (toolbar and row filtering), possibly `features/logs/use-*` helpers.
- **Evidence:** [Vercel errors filter flow](https://mobbin.com/flows/e6e0c0e4-76b4-4236-9417-8edf17b41fe6?tab=screens), [Vercel counts in header](https://mobbin.com/screens/ff81f1e9-25b1-46f9-8448-31fa40a77e4b)
- **Effort:** M. **Doc:** none, or a line in `docs/08-dashboard-ux.md` log viewer.

### DPL-6. Previews: live PRs as cards, closed and expired as a compact list, and a rule sentence in the header
- **Problem:** `previews`: an expired PR gets a full card with an empty 200px media area. The card links to neither the PR nor its deployment. The header shows counts but not the policy.
- **Change:**
  1. Split into **Open** (pending/running cards) and **Closed** (expired/closed as one-line rows: `#1 E2E preview`, author, branch → base, the status word with its glyph, "No activity for 7 days", and a **Push to restart** hint). The rows go in a section below, collapsed after 5.
  2. Drop the media placeholder when there's no URL. The card starts with the title row.
  3. Add a footer action row: **Open preview** (if a URL exists), **Pull request ↗** (`preview.pullRequestUrl`, which is already in the data), and, on `health === 'failed'`, **See why**, linking to the failing deployment.
  4. Under the page title, replace "1 pull request | 0 live" with the rule in words, e.g. "Pull requests into `main` get a copy of production. Removed when merged or after 7 days idle." Link it to Preview settings. Counts move to the Open/Closed section headings.
- **Files:** `pages/projects/previews.tsx`, `features/previews/status.tsx`, `features/previews/url-card.tsx`, and the props from the previews controller for the rule summary (`app/Data/Previews/*`).
- **Evidence:** [LC rule sentence](https://mobbin.com/flows/fdfb1805-264b-4ed4-83eb-b1a5b13fa1ca?tab=screens), [PlanetScale Open/Closed](https://mobbin.com/screens/be0ec4c5-4a49-4f4a-8a29-179143a514c5), [Mintlify expanded failed preview](https://mobbin.com/screens/9511b9c8-f3ab-4556-a398-6eb0bef0a2e4)
- **Effort:** M. **Doc:** `docs/design/direction.md` §9.11 and `docs/design/flows.md` §13 (previews page layout).

### DPL-7. Promote: count tiles and a commit range per service
- **Problem:** The `promote` summary is prose, and the page never says which code differs. "Also promote images" shows only the source sha (`PromotionImageData` has no target sha or message).
- **Change:**
  1. Top of the summary column: three small tiles, **3 added / 0 changed / 0 removed** (glyphs + / ~ / −, the same signs as the rows), then "Restarts api and web". This follows PlanetScale's picture → number → words.
  2. Per service in "Also promote images", show `production 7c8d9e0 fix: totals → staging 3b7d5e1 feat: gift cards`, plus "Compare on GitHub ↗" when the git provider gives a compare URL. Add `targetCommitSha`, `sourceCommitMessage`, `targetCommitMessage` and `compareUrl` to `PromotionImageData`. All of these are on the deployments we already load.
  3. When the button is disabled, put the reason right under it ("Fill in GIFT_CARD_PROVIDER_KEY first") instead of above it in the prose.
- **Files:** `pages/projects/promote.tsx`, `app/Data/Promotions/PromotionImageData.php`, the promotion comparison Action/Query that builds it, then `make gen-types`.
- **Evidence:** [PlanetScale tiles + check](https://mobbin.com/flows/fecee68e-38fe-4aa6-aa09-e45e882fff9f?tab=screens), [Devin commits in change](https://mobbin.com/screens/1d654c5f-68dd-42f4-abcd-b53f8bac3214)
- **Effort:** M. **Doc:** `docs/06-*` §6.4 (promotion data) and `docs/design/flows.md` §12.

### DPL-8. Rollback dialog: show current → target, not just prose
- **Problem:** `DeploymentActionDialog` for rollback is a title plus a paragraph. Nothing shows what is live now versus what you're going back to. (The post-rollback "auto-deploy paused" banner already exists and works.)
- **Change:** In the rollback case, render two compact deployment rows in the dialog: **Now live**, then an arrow, then **Roll back to**, each with status glyph, sha, commit message, author avatar and age. Keep the consequence sentence below. Use the same rows for **Promote** (staged → live). Optionally add a one-line "Reason (shown in activity)" field, if the activity log can store it.
- **Files:** `features/deployments/deployment-actions.tsx` (it needs the active deployment, which `DeploymentDetailData.serving` or the list row already gives), `features/deployments/deployments-tab.tsx`.
- **Evidence:** [Vercel Instant Rollback](https://mobbin.com/screens/f10497e6-9a53-4d09-b1fe-e72cebede2a6), [Vercel post-rollback card with reason](https://mobbin.com/screens/d8e63884-5d43-4337-bf31-5667bc4ba5db)
- **Effort:** S (rows), M (with reason). **Doc:** `docs/design/flows.md` line ~93 says the rollback confirm is "a small popover, not a dialog". Reconcile it: the popover keeps the two rows, or the doc changes to a dialog.

### DPL-9. Service panel: pin the live deployment, then show the history
- **Problem:** On `service-panel`, the live deployment is just the first, expanded row of an equal list. The step chips there include "Release", which the deployment page leaves out, and the panel says "Live" while the node says "Not running".
- **Change:** Render the active deployment as its own card at the top with **View logs** and **Redeploy**. Below it, a "History" list of compact rows that collapses past 5, plus a **Hide superseded** toggle. Feed the panel's step chips from the same `withLoggedSteps` / steps model the page uses, so both show the same step set. When the service runtime is stopped, `deploymentWord` should yield "Stopped" (`deployment.stopped` exists; check that the panel's data fills it).
- **Files:** `features/deployments/deployments-tab.tsx`, `features/deployments/steps.tsx`, `features/deployments/format.ts`.
- **Evidence:** [Railway ACTIVE + History](https://mobbin.com/flows/6caa5617-cb4a-44b2-9af6-bf9a6db34444?tab=screens), [Vercel "Current" badge](https://mobbin.com/screens/e9576405-bcef-419a-922a-8fb84b044a54)
- **Effort:** M. **Doc:** `docs/design/direction.md` (service panel, deployments tab).

### DPL-10. An in-progress toast with Cancel, and prev/next on the deployment page
- **Problem:** We only toast outcomes ("web is live · Open"). Once you leave the deployment page, there is no handle on a running build. The detail page also has no way to step to the next or previous deployment of the same service besides the "Previous deployment" related card at the bottom.
- **Change:** (a) In `deploy-toasts.tsx`, show a persistent "Building web (3b7d5e1)…" toast with **Cancel** and **Open** while one of this user's own triggered deployments runs. It gets replaced by the outcome toast. (b) Add ↑/↓ icon buttons (and `j`/`k`) in the deployment header that go to the newer and older deployment of the same service.
- **Files:** `features/deployments/deploy-toasts.tsx`, `pages/projects/deployments/show.tsx`, and the props for neighbour ids (`DeploymentDetailData`).
- **Evidence:** [LC building toast + prev/next control](https://mobbin.com/screens/f9a999ee-f498-4a02-b17f-be383d866950), [LC deployed](https://mobbin.com/screens/dd29535f-34aa-4279-82fc-76e11059f596)
- **Effort:** S–M. **Doc:** `docs/design-system.md` §6 (async outcomes): add "in-progress toast for actions you started".

### DPL-11. Small copy and consistency fixes
- The step detail on the "Plan" row uses "Next.js 16.1 · pnpm 9.12 · Node 22", which conflicts with the no-"·" rule. Render the parts as separate muted spans with spacing, or as "Next.js 16.1 with pnpm 9.12 on Node 22". The source is the plan summary string in `features/deployments/steps.tsx` and the producer in the dashboard's plan summary.
- "Built on: control plane" (overview fact) reads as jargon to 1–2 server users. Use the server's name ("Built on hetzner-1"), and "this server" when the dashboard host does the build.
- On `promote`, hide the top bar's staged-changes pill, or scope it ("Staging has 3 staged changes"), so there's only one "3 changes" on screen. Files: `features/projects/project-top-bar.tsx`, `pages/projects/promote.tsx`.
- The deployment header shows the service logo, which for image services is a container-tool mark. Use the framework logo, or the commit avatar for git sources. File: `pages/projects/deployments/show.tsx`.
- **Effort:** S. **Doc:** none.

## Not recommended
- **The deployments list layout.** The commit-grouped card per push with one row per service (`commit-group.tsx`), the "Live" word, the duration bar against the median and the filter selects all already beat Laravel Cloud's flat rows and match Vercel's density without its table feel. Keep it. A branch filter or date range (Vercel) isn't worth it at our 1–2 server scale.
- **The auto-deploy-paused banner after a rollback.** It already exists (`features/projects/auto-deploy-paused-banner.tsx`) and matches Vercel's pattern.
